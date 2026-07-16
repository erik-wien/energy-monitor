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

require_once __DIR__ . '/csv_format.php';

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
    $fmt = energie_csv_format_pruefen($path);
    if (!$fmt['ok']) return [];
    $handle = @fopen($path, 'r');
    if (!$handle) return [];
    fgets($handle); // Kopfzeile überspringen
    $dIdx = $fmt['datum_idx']; $vIdx = $fmt['zeit_idx']; $kIdx = $fmt['verbrauch_idx'];

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
 * Import-preview counts for a set of parsed timestamps.
 *
 * Returns ['total', 'existing', 'new']. "existing" mirrors the importer's
 * definition (see _csv_fetch_spot_map): only rows that ALREADY have
 * consumption count — EPEX-preseeded spot-only rows (consumed_kwh = 0) are
 * "new", because importing the CSV fills in their real consumption.
 */
function preview_import_counts(PDO $pdo, array $timestamps): array {
    $timestamps = array_values(array_unique($timestamps));
    $total      = count($timestamps);
    $existing   = 0;
    foreach (array_chunk($timestamps, 1000) as $chunk) {
        $ph   = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM readings WHERE consumed_kwh > 0 AND ts IN ($ph)");
        $stmt->execute($chunk);
        $existing += (int) $stmt->fetchColumn();
    }
    return ['total' => $total, 'existing' => $existing, 'new' => $total - $existing];
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

    _csv_upsert_rows($pdo, $rows, $spotMap, $tariffs);
    _csv_rebuild_daily_summary($pdo);

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

/**
 * Transactional chunked UPSERT of parsed rows into readings.
 * Shared by the one-shot importer (php_import_csv) and the per-day
 * client-loop importer (php_import_day). Does NOT touch daily_summary.
 */
function _csv_upsert_rows(PDO $pdo, array $rows, array $spotMap, array $tariffs): void {
    if (empty($rows)) return;
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
}

/**
 * Rebuild daily_summary from readings.
 *
 * @param ?array $days  When null, rebuilds every day (one-shot import / CLI).
 *                      When given a list of 'YYYY-MM-DD', rebuilds only those —
 *                      the client-loop finalize scopes to the imported days so
 *                      it never GROUP-BYs the whole readings table.
 */
function _csv_rebuild_daily_summary(PDO $pdo, ?array $days = null): void {
    $where  = '';
    $params = [];
    if ($days !== null) {
        if (empty($days)) return;
        $ph     = implode(',', array_fill(0, count($days), '?'));
        $where  = "WHERE DATE(ts) IN ($ph)";
        $params = $days;
    }
    $stmt = $pdo->prepare(
        "INSERT INTO daily_summary (day, consumed_kwh, cost_brutto, avg_spot_ct)
         SELECT DATE(ts), SUM(consumed_kwh), SUM(cost_brutto), AVG(spot_ct)
         FROM readings
         $where
         GROUP BY DATE(ts)
         ON DUPLICATE KEY UPDATE
             consumed_kwh = VALUES(consumed_kwh),
             cost_brutto  = VALUES(cost_brutto),
             avg_spot_ct  = VALUES(avg_spot_ct)"
    );
    $stmt->execute($params);
}

/**
 * Build the per-day work list for the client-loop import (§20).
 *
 * Parses each CSV once and groups its rows by calendar day, in file order.
 * Returns [['file' => path, 'date' => 'YYYY-MM-DD', 'rows' => int], …].
 * Cheap: parsing only, no DB access. (XLSX files yield no candidates — the
 * PHP-native path handles CSV only; XLSX import stays Python-only.)
 */
function import_candidates(array $files): array {
    $out = [];
    foreach ($files as $file) {
        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'csv') continue;
        $byDay = [];
        foreach (_csv_parse_rows($file) as $row) {
            $day = substr($row['ts'], 0, 10);
            $byDay[$day] = ($byDay[$day] ?? 0) + 1;
        }
        foreach ($byDay as $day => $count) {
            $out[] = ['file' => $file, 'date' => $day, 'rows' => $count];
        }
    }
    return $out;
}

/**
 * Import exactly one calendar day's rows from one CSV file.
 *
 * One client-loop chunk: small enough (~96 quarter-hours) to finish well
 * under any proxy/PHP timeout. Does NOT rebuild daily_summary and does NOT
 * archive the file — both happen once in php_import_finalize().
 *
 * @return array ['inserted' => int, 'existing' => int, 'total' => int]
 */
function php_import_day(PDO $pdo, string $filepath, string $date): array {
    $rows = array_values(array_filter(
        _csv_parse_rows($filepath),
        static fn(array $r): bool => substr($r['ts'], 0, 10) === $date
    ));
    if (empty($rows)) {
        return ['inserted' => 0, 'existing' => 0, 'total' => 0];
    }

    $spotMap = _csv_fetch_spot_map($pdo, array_column($rows, 'ts'));
    $tariffs = _csv_load_tariffs($pdo);

    $total    = count($rows);
    $existing = 0;
    foreach ($spotMap as $entry) {
        if ($entry['had_consumption']) $existing++;
    }

    _csv_upsert_rows($pdo, $rows, $spotMap, $tariffs);

    return ['inserted' => $total - $existing, 'existing' => $existing, 'total' => $total];
}

/**
 * Finish a client-loop import: rebuild daily_summary for the imported days
 * only, recompute cost_brutto for those days (EPEX can have run AFTER the
 * import, so spot_ct may have been 0 during php_import_day), then archive
 * the processed files. Called once after all per-day chunks succeeded.
 *
 * @return array ['days' => int, 'archived' => string[], 'recomputed' => int]
 */
function php_import_finalize(PDO $pdo, array $days, array $files, string $archiv): array {
    $days = array_values(array_unique(array_filter($days)));
    _csv_rebuild_daily_summary($pdo, $days);

    // Kosten für die berührten Tage neu rechnen: spot_ct kann durch einen
    // späteren EPEX-Abruf gesetzt worden sein, cost_brutto sonst = 0.
    $recomputed = 0;
    if (!empty($days)) {
        $tariffs = _csv_load_tariffs($pdo);
        $ph = implode(',', array_fill(0, count($days), '?'));
        $sel = $pdo->prepare("SELECT ts, consumed_kwh, spot_ct FROM readings WHERE consumed_kwh > 0 AND DATE(ts) IN ($ph)");
        $sel->execute($days);
        $upd = $pdo->prepare("UPDATE readings SET cost_brutto = ? WHERE ts = ?");
        $pdo->beginTransaction();
        try {
            foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $t = _csv_tariff_for($tariffs, substr((string) $row['ts'], 0, 10));
                if ($t === null) continue;
                $cost = _csv_calc_cost((float) $row['consumed_kwh'], (float) $row['spot_ct'], $t);
                $upd->execute([$cost, $row['ts']]);
                $recomputed++;
            }
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        _csv_rebuild_daily_summary($pdo, $days); // Summary mit neuen Kosten
    }

    $archived = [];
    foreach ($files as $file) {
        if (is_dir($archiv) && is_file($file)) {
            $dest = $archiv . '/' . basename($file);
            if (@rename($file, $dest)) $archived[] = basename($dest);
        }
    }
    return ['days' => count($days), 'archived' => $archived, 'recomputed' => $recomputed];
}
