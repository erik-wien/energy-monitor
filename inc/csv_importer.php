<?php
/**
 * inc/csv_importer.php — PHP-native CSV importer for servers without Python/proc_open.
 *
 * Replicates energie.py import-csv:
 *   1. Parse German-format quarter-hour CSV
 *   2. Look up spot_ct from existing readings row (populated by earlier price import)
 *   3. Look up applicable tariff from tariff_config
 *   4. Calculate cost_brutto
 *   5. Upsert into readings
 *   6. Rebuild daily_summary
 *   7. Archive file to _Archiv/
 *
 * All DB access is batched: one SELECT for existing spot_ct, one tariff scan,
 * chunked multi-row INSERT ... ON DUPLICATE KEY UPDATE. Per-row round-trips
 * made the import proportional to network latency (1536 rows × 20 ms RTT
 * = 30 s on world4you — past PHP-FPM's request budget).
 */

/**
 * Gross electricity cost for one quarter-hour slot.
 * Mirrors calculate_cost_brutto() in energie.py.
 * All per-kWh values in ct/kWh; annual fees in EUR/yr. Returns cost in EUR.
 * VAT does NOT apply to consumption tax -- they are additive, not compounding.
 */
function _csv_calc_cost(float $consumed_kwh, float $spot_ct, array $t): float {
    $annual_ct = ($t['meter_fee_eur'] + $t['renewable_fee_eur'])
        / max(1.0, $t['yearly_kwh_estimate']) * 100;
    $net_ct = $spot_ct
        + $t['provider_surcharge_ct']
        + $t['electricity_tax_ct']
        + $t['renewable_tax_ct']
        + $annual_ct;
    $gross_ct = $net_ct * (1 + $t['vat_rate'] + $t['consumption_tax_rate']);
    return $consumed_kwh * $gross_ct / 100;
}

/**
 * Parse German-format quarter-hour CSV into row dicts.
 * Handles both old (Datum;von;bis;Verbrauch) and QuarterHourValues formats.
 * Returns array of ['ts' => 'YYYY-MM-DDTHH:MM:SS', 'consumed_kwh' => float].
 */
function _csv_parse_rows(string $path): array {
    $handle = @fopen($path, 'r');
    if (!$handle) return [];

    $first = ltrim((string) fgets($handle), "\xEF\xBB\xBF");
    if (trim($first) === '') { fclose($handle); return []; }
    $headers = array_map('trim', str_getcsv(trim($first), ';', '"', ''));

    $dIdx = array_search('Datum', $headers, true);
    $vIdx = array_search('Zeit von', $headers, true);
    if ($vIdx === false) $vIdx = array_search('von', $headers, true);
    $kIdx = null;
    foreach ($headers as $i => $h) {
        if (strpos($h, 'Verbrauch') !== false || strpos($h, 'kWh') !== false) {
            $kIdx = $i; break;
        }
    }

    if ($dIdx === false || $vIdx === false || $kIdx === null) {
        fclose($handle);
        return [];
    }

    $rows = [];
    while (($line = fgets($handle)) !== false) {
        $cols  = str_getcsv(trim($line), ';', '"', '');
        $datum = trim($cols[$dIdx] ?? '');
        $von   = trim($cols[$vIdx] ?? '');
        $kwh   = trim($cols[$kIdx] ?? '');
        if (!$datum || !$von || !$kwh) continue;

        $parts = explode('.', $datum);
        if (count($parts) !== 3) continue;
        $year = strlen($parts[2]) === 2 ? '20' . $parts[2] : $parts[2];
        if (substr_count($von, ':') === 1) $von .= ':00';

        $rows[] = [
            'ts'           => sprintf('%s-%02d-%02dT%s', $year, (int) $parts[1], (int) $parts[0], $von),
            'consumed_kwh' => (float) str_replace(',', '.', $kwh),
        ];
    }
    fclose($handle);
    return $rows;
}

/**
 * Fetch spot_ct + has-consumption flag for every ts in one round-trip
 * (chunked under 1000 placeholders to stay well under any IN-clause limit).
 * Returns [ts => ['spot_ct' => float, 'had_consumption' => bool]].
 *
 * `had_consumption` matters for the import-dialog counters: a row created
 * by a prior EPEX fetch (consumed_kwh = 0) is NOT "existing" from the
 * user's perspective — importing the CSV fills in real consumption data.
 */
function _csv_fetch_spot_map(PDO $pdo, array $timestamps): array {
    if (empty($timestamps)) return [];
    $map = [];
    foreach (array_chunk($timestamps, 1000) as $chunk) {
        $ph   = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $pdo->prepare(
            "SELECT ts, spot_ct, consumed_kwh > 0 AS had_consumption
             FROM readings WHERE ts IN ($ph)"
        );
        $stmt->execute($chunk);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $key = (new DateTime($r['ts']))->format('Y-m-d\TH:i:s');
            $map[$key] = [
                'spot_ct'         => (float) $r['spot_ct'],
                'had_consumption' => (bool) $r['had_consumption'],
            ];
        }
    }
    return $map;
}

/**
 * Load all tariff_config rows once; resolve per-date by picking the latest
 * valid_from <= date. Keeps per-row tariff lookup entirely in PHP.
 */
function _csv_load_tariffs(PDO $pdo): array {
    $rows = $pdo->query(
        "SELECT valid_from, provider_surcharge_ct, electricity_tax_ct, renewable_tax_ct,
                consumption_tax_rate, vat_rate,
                meter_fee_eur, renewable_fee_eur, yearly_kwh_estimate
         FROM tariff_config
         ORDER BY valid_from DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        foreach ($r as $k => $v) {
            if ($k !== 'valid_from') $r[$k] = (float) $v;
        }
    }
    return $rows;
}

/** First tariff with valid_from <= $date, given $tariffs sorted DESC, or null. */
function _csv_tariff_for(array $tariffs, string $date): ?array {
    foreach ($tariffs as $t) {
        if ($t['valid_from'] <= $date) return $t;
    }
    return null;
}

/**
 * Import one CSV file into readings + daily_summary, then archive it.
 *
 * @param  PDO    $pdo      Data DB connection
 * @param  string $filepath Absolute path to CSV file
 * @param  string $archiv   Absolute path to _Archiv/ directory
 * @return array  ['inserted' => int, 'total' => int, 'log' => string]
 * @throws PDOException on DB error
 */
function php_import_csv(PDO $pdo, string $filepath, string $archiv): array {
    $rows = _csv_parse_rows($filepath);
    if (empty($rows)) {
        return [
            'inserted' => 0,
            'total'    => 0,
            'log'      => 'Keine Zeilen gefunden: ' . basename($filepath),
        ];
    }

    $timestamps = array_column($rows, 'ts');
    $spotMap    = _csv_fetch_spot_map($pdo, $timestamps);
    $tariffs    = _csv_load_tariffs($pdo);

    $total    = count($rows);
    $existing = 0;
    foreach ($spotMap as $entry) {
        if ($entry['had_consumption']) $existing++;
    }
    $inserted = $total - $existing;

    $pdo->beginTransaction();
    try {
        foreach (array_chunk($rows, 500) as $chunk) {
            $placeholders = [];
            $params       = [];
            foreach ($chunk as $row) {
                $ts      = $row['ts'];
                $kwh     = $row['consumed_kwh'];
                $spot_ct = $spotMap[$ts]['spot_ct'] ?? 0.0;
                $tariff  = _csv_tariff_for($tariffs, substr($ts, 0, 10));
                $cost    = $tariff !== null ? _csv_calc_cost($kwh, $spot_ct, $tariff) : 0.0;

                $placeholders[] = '(?, ?, ?, ?)';
                $params[]       = $ts;
                $params[]       = $kwh;
                $params[]       = $spot_ct;
                $params[]       = $cost;
            }
            $stmt = $pdo->prepare(
                "INSERT INTO readings (ts, consumed_kwh, spot_ct, cost_brutto)
                 VALUES " . implode(',', $placeholders) . "
                 ON DUPLICATE KEY UPDATE
                     consumed_kwh = VALUES(consumed_kwh),
                     cost_brutto  = VALUES(cost_brutto)"
            );
            $stmt->execute($params);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $pdo->exec(
        "INSERT INTO daily_summary (day, consumed_kwh, cost_brutto, avg_spot_ct)
         SELECT DATE(ts), SUM(consumed_kwh), SUM(cost_brutto), AVG(spot_ct)
         FROM readings
         GROUP BY DATE(ts)
         ON DUPLICATE KEY UPDATE
             consumed_kwh = VALUES(consumed_kwh),
             cost_brutto  = VALUES(cost_brutto),
             avg_spot_ct  = VALUES(avg_spot_ct)"
    );

    $log = sprintf(
        "%s: %d neu, %d vorhanden, %d gesamt\n",
        basename($filepath), $inserted, $existing, $total
    );

    if (is_dir($archiv)) {
        $dest = $archiv . '/' . basename($filepath);
        if (@rename($filepath, $dest)) {
            $log .= 'Archiviert: ' . basename($dest) . "\n";
        }
    }

    return ['inserted' => $inserted, 'total' => $total, 'log' => $log];
}
