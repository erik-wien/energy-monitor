<?php
require_once __DIR__ . '/../inc/db.php';

if (empty($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

// Accept `?action=` (Rule §15.1) as an alias for the legacy `?type=` dispatch.
$type = $_GET['action'] ?? $_GET['type'] ?? '';

/**
 * Parse timestamp strings from a semicolon-delimited Energie CSV.
 * Handles UTF-8 BOM, DD.MM.YYYY or DD.MM.YY date format, HH:MM or HH:MM:SS time.
 * Returns array of 'YYYY-MM-DDTHH:MM:SS' strings (not yet DB-formatted).
 */
function _parse_energie_csv_timestamps(string $path): array {
    $handle = fopen($path, 'r');
    if (!$handle) return [];
    $first   = ltrim((string) fgets($handle), "\xEF\xBB\xBF");  // strip BOM
    $headers = array_map('trim', str_getcsv(trim($first), ';', '"', ''));
    $dIdx = array_search('Datum', $headers, true);
    $vIdx = array_search('Zeit von', $headers, true);
    if ($vIdx === false) $vIdx = array_search('von', $headers, true);
    $kIdx = null;
    foreach ($headers as $i => $h) {
        if (str_contains($h, 'Verbrauch') || str_contains($h, 'kWh')) { $kIdx = $i; break; }
    }
    if ($dIdx === false || $vIdx === false || $kIdx === null) { fclose($handle); return []; }
    $timestamps = [];
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
        $timestamps[] = sprintf('%s-%02d-%02dT%s', $year, (int)$parts[1], (int)$parts[0], $von);
    }
    fclose($handle);
    return $timestamps;
}

// Global Y-axis maxima for consistent scale across all charts
$max_daily_kwh  = (float)$pdo->query("SELECT MAX(consumed_kwh) FROM daily_summary")->fetchColumn();
$max_daily_cost = (float)$pdo->query("SELECT MAX(cost_brutto)  FROM daily_summary")->fetchColumn();
$max_slot_kwh   = (float)$pdo->query("SELECT MAX(consumed_kwh) FROM readings")->fetchColumn();
$max_slot_cost  = (float)$pdo->query("SELECT MAX(cost_brutto)  FROM readings")->fetchColumn();

// Compute per-row invoice breakdown (variable portion only; annual fees go to footer).
// net_variable = spot + provider_surcharge + electricity_tax + renewable_tax
// gesamt_variable = kwh * net_variable * (1 + vat + gba) / 100
// meter_fee_prop  = kwh * meter_fee / yearly_kwh * (1 + vat + gba)   [accumulated]
function invoice_breakdown(array &$rows, bool $is_daily,
    array &$epex_out, array &$aufschlag, array &$abgaben, array &$gba_out, array &$mwst_out,
    array &$gesamt_var, float &$meter_fee_prop, float &$renew_fee_prop): void
{
    foreach ($rows as $row) {
        $kwh  = (float)$row['consumed_kwh'];
        $spot = (float)($is_daily ? $row['spot_ct'] : $row['avg_spot_ct']);
        $psc  = (float)($row['provider_surcharge_ct'] ?? 0);
        $etc  = (float)($row['electricity_tax_ct']    ?? 0);
        $rtc  = (float)($row['renewable_tax_ct']      ?? 0);
        $mfee = (float)($row['meter_fee_eur']          ?? 0);
        $rfee = (float)($row['renewable_fee_eur']      ?? 0);
        $ykwh = max(1.0, (float)($row['yearly_kwh_estimate'] ?? 3000));
        $gbr  = (float)($row['consumption_tax_rate']  ?? 0);
        $vatr = (float)($row['vat_rate']              ?? 0);

        $net_var      = $spot + $psc + $etc + $rtc;
        $gross_factor = 1 + $gbr + $vatr;

        $epex_out[]    = $spot;   // ct/kWh rate; multiply by kwh/100 to get €
        $aufschlag[]   = $kwh * $psc  / 100;
        $abgaben[]     = $kwh * ($etc  + $rtc) / 100;
        $gba_out[]     = $kwh * $net_var * $gbr  / 100;
        $mwst_out[]    = $kwh * $net_var * $vatr / 100;
        $gesamt_var[]  = $kwh * $net_var * $gross_factor / 100;
        $meter_fee_prop += $kwh * $mfee / $ykwh * $gross_factor;
        $renew_fee_prop += $kwh * $rfee / $ykwh * $gross_factor;
    }
}

// Tariff JOIN fragment (correlated subquery on a date column alias).
// Usage: wrap data query as subquery "agg", then LEFT JOIN tariff on agg.<date_col>
define('TARIFF_COLS', "t.provider_surcharge_ct, t.electricity_tax_ct, t.renewable_tax_ct,
                t.consumption_tax_rate, t.vat_rate,
                t.meter_fee_eur, t.renewable_fee_eur, t.yearly_kwh_estimate");

if ($type === 'daily') {
    $date = $_GET['date'] ?? date('Y-m-d', strtotime('-1 day'));
    $stmt = $pdo->prepare(
        "SELECT TIME(r.ts) AS label, r.consumed_kwh, r.cost_brutto, r.spot_ct, " . TARIFF_COLS . "
         FROM readings r
         LEFT JOIN tariff_config t ON t.valid_from = (
             SELECT MAX(valid_from) FROM tariff_config WHERE valid_from <= DATE(r.ts)
         )
         WHERE DATE(r.ts) = ?
         ORDER BY r.ts"
    );
    $stmt->execute([$date]);
    $rows = $stmt->fetchAll();

    $hRows = $pdo->query(
        "SELECT TIME(ts) AS slot,
                AVG(spot_ct) AS ta, MIN(spot_ct) AS tn, MAX(spot_ct) AS tx,
                AVG(NULLIF(consumed_kwh,0)) AS ka,
                MIN(NULLIF(consumed_kwh,0)) AS kn,
                MAX(NULLIF(consumed_kwh,0)) AS kx
         FROM readings GROUP BY slot"
    )->fetchAll();
    $hMap = array_column($hRows, null, 'slot');
    $htariff_avg = array_map(fn($r) => isset($hMap[$r['label']]) ? (float)$hMap[$r['label']]['ta'] : null, $rows);
    $htariff_min = array_map(fn($r) => isset($hMap[$r['label']]) ? (float)$hMap[$r['label']]['tn'] : null, $rows);
    $htariff_max = array_map(fn($r) => isset($hMap[$r['label']]) ? (float)$hMap[$r['label']]['tx'] : null, $rows);
    $hkwh_avg    = array_map(fn($r) => isset($hMap[$r['label']]) ? (float)$hMap[$r['label']]['ka'] : null, $rows);
    $hkwh_min    = array_map(fn($r) => isset($hMap[$r['label']]) ? (float)$hMap[$r['label']]['kn'] : null, $rows);
    $hkwh_max    = array_map(fn($r) => isset($hMap[$r['label']]) ? (float)$hMap[$r['label']]['kx'] : null, $rows);

    $epex = []; $aufschlag = []; $abgaben = []; $gba = []; $mwst = []; $gesamt_var = [];
    $mfp = 0.0; $rfp = 0.0;
    invoice_breakdown($rows, true, $epex, $aufschlag, $abgaben, $gba, $mwst, $gesamt_var, $mfp, $rfp);

    echo json_encode([
        'labels'            => array_column($rows, 'label'),
        'cost'              => array_map('floatval', array_column($rows, 'cost_brutto')),
        'consumption'       => array_map('floatval', array_column($rows, 'consumed_kwh')),
        'tariff'            => array_map('floatval', array_column($rows, 'spot_ct')),
        'hist_tariff_avg'   => $htariff_avg,
        'hist_tariff_min'   => $htariff_min,
        'hist_tariff_max'   => $htariff_max,
        'hist_kwh_avg'      => $hkwh_avg,
        'hist_kwh_min'      => $hkwh_min,
        'hist_kwh_max'      => $hkwh_max,
        'maxCost'           => $max_slot_cost,
        'maxKwh'            => $max_slot_kwh,
        'epex'              => $epex,
        'aufschlag'         => $aufschlag,
        'abgaben'           => $abgaben,
        'gebrauchsabgabe'   => $gba,
        'mwst_tax'          => $mwst,
        'gesamt_variable'   => $gesamt_var,
        'meter_fee_prop'    => $mfp,
        'renewable_fee_prop'=> $rfp,
        'period_start'      => $date,
        'period_end'        => $date,
    ]);

} elseif ($type === 'weekly') {
    $year = (int)($_GET['year'] ?? date('Y'));
    $week = (int)($_GET['week'] ?? date('W'));
    $stmt = $pdo->prepare(
        "SELECT agg.label, agg.raw_date, agg.consumed_kwh, agg.cost_brutto, agg.avg_spot_ct,
                agg.min_spot_ct, agg.max_spot_ct, agg.epex_wgt, " . TARIFF_COLS . "
         FROM (
             SELECT DATE_FORMAT(ds.day, '%d.%m') AS label, DATE(ds.day) AS raw_date,
                    ds.consumed_kwh, ds.cost_brutto, ds.avg_spot_ct,
                    MIN(r.spot_ct) AS min_spot_ct, MAX(r.spot_ct) AS max_spot_ct,
                    SUM(r.spot_ct * r.consumed_kwh) / NULLIF(SUM(r.consumed_kwh), 0) AS epex_wgt
             FROM daily_summary ds
             LEFT JOIN readings r ON DATE(r.ts) = ds.day
             WHERE YEAR(ds.day) = ? AND WEEK(ds.day, 3) = ?
             GROUP BY ds.day
         ) agg
         LEFT JOIN tariff_config t ON t.valid_from = (
             SELECT MAX(valid_from) FROM tariff_config WHERE valid_from <= agg.raw_date
         )
         ORDER BY agg.raw_date"
    );
    $stmt->execute([$year, $week]);
    $rows = $stmt->fetchAll();

    $hRows = $pdo->query(
        "SELECT WEEKDAY(day) AS k,
                AVG(avg_spot_ct) AS ta, MIN(avg_spot_ct) AS tn, MAX(avg_spot_ct) AS tx,
                AVG(consumed_kwh) AS ka, MIN(consumed_kwh) AS kn, MAX(consumed_kwh) AS kx
         FROM daily_summary GROUP BY k"
    )->fetchAll();
    $hMap = []; foreach ($hRows as $h) $hMap[(int)$h['k']] = $h;
    $htariff_avg = []; $htariff_min = []; $htariff_max = [];
    $hkwh_avg    = []; $hkwh_min    = []; $hkwh_max    = [];
    foreach ($rows as $r) {
        $k = (int)date('N', strtotime($r['raw_date'])) - 1;
        $h = $hMap[$k] ?? null;
        $htariff_avg[] = $h ? (float)$h['ta'] : null;
        $htariff_min[] = $h ? (float)$h['tn'] : null;
        $htariff_max[] = $h ? (float)$h['tx'] : null;
        $hkwh_avg[]    = $h ? (float)$h['ka'] : null;
        $hkwh_min[]    = $h ? (float)$h['kn'] : null;
        $hkwh_max[]    = $h ? (float)$h['kx'] : null;
    }

    $epex = []; $aufschlag = []; $abgaben = []; $gba = []; $mwst = []; $gesamt_var = [];
    $mfp = 0.0; $rfp = 0.0;
    invoice_breakdown($rows, false, $epex, $aufschlag, $abgaben, $gba, $mwst, $gesamt_var, $mfp, $rfp);

    $mon = (new DateTime())->setISODate($year, $week, 1);
    $sun = (new DateTime())->setISODate($year, $week, 7);
    echo json_encode([
        'labels'            => array_column($rows, 'label'),
        'cost'              => array_map('floatval', array_column($rows, 'cost_brutto')),
        'consumption'       => array_map('floatval', array_column($rows, 'consumed_kwh')),
        'tariff'            => array_map('floatval', array_column($rows, 'avg_spot_ct')),
        'min_spot'          => array_map(fn($r) => $r['min_spot_ct'] !== null ? (float)$r['min_spot_ct'] : null, $rows),
        'max_spot'          => array_map(fn($r) => $r['max_spot_ct'] !== null ? (float)$r['max_spot_ct'] : null, $rows),
        'dates'             => array_column($rows, 'raw_date'),
        'hist_tariff_avg'   => $htariff_avg,
        'hist_tariff_min'   => $htariff_min,
        'hist_tariff_max'   => $htariff_max,
        'hist_kwh_avg'      => $hkwh_avg,
        'hist_kwh_min'      => $hkwh_min,
        'hist_kwh_max'      => $hkwh_max,
        'maxCost'           => $max_daily_cost,
        'maxKwh'            => $max_daily_kwh,
        'epex'              => $epex,
        'epex_wgt'          => array_map(fn($r) => $r['epex_wgt'] !== null ? (float)$r['epex_wgt'] : null, $rows),
        'aufschlag'         => $aufschlag,
        'abgaben'           => $abgaben,
        'gebrauchsabgabe'   => $gba,
        'mwst_tax'          => $mwst,
        'gesamt_variable'   => $gesamt_var,
        'meter_fee_prop'    => $mfp,
        'renewable_fee_prop'=> $rfp,
        'period_start'      => $mon->format('Y-m-d'),
        'period_end'        => $sun->format('Y-m-d'),
    ]);

} elseif ($type === 'monthly') {
    $year  = (int)($_GET['year']  ?? date('Y'));
    $month = (int)($_GET['month'] ?? (int)date('m') - 1);
    if ($month < 1) { $month = 12; $year--; }
    $stmt = $pdo->prepare(
        "SELECT agg.label, agg.raw_date, agg.consumed_kwh, agg.cost_brutto, agg.avg_spot_ct,
                agg.min_spot_ct, agg.max_spot_ct, agg.epex_wgt, " . TARIFF_COLS . "
         FROM (
             SELECT DATE_FORMAT(ds.day, '%d.%m') AS label, DATE(ds.day) AS raw_date,
                    ds.consumed_kwh, ds.cost_brutto, ds.avg_spot_ct,
                    MIN(r.spot_ct) AS min_spot_ct, MAX(r.spot_ct) AS max_spot_ct,
                    SUM(r.spot_ct * r.consumed_kwh) / NULLIF(SUM(r.consumed_kwh), 0) AS epex_wgt
             FROM daily_summary ds
             LEFT JOIN readings r ON DATE(r.ts) = ds.day
             WHERE YEAR(ds.day) = ? AND MONTH(ds.day) = ?
             GROUP BY ds.day
         ) agg
         LEFT JOIN tariff_config t ON t.valid_from = (
             SELECT MAX(valid_from) FROM tariff_config WHERE valid_from <= agg.raw_date
         )
         ORDER BY agg.raw_date"
    );
    $stmt->execute([$year, $month]);
    $rows = $stmt->fetchAll();

    $hRows = $pdo->query(
        "SELECT DAY(day) AS k,
                AVG(avg_spot_ct) AS ta, MIN(avg_spot_ct) AS tn, MAX(avg_spot_ct) AS tx,
                AVG(consumed_kwh) AS ka, MIN(consumed_kwh) AS kn, MAX(consumed_kwh) AS kx
         FROM daily_summary GROUP BY k"
    )->fetchAll();
    $hMap = []; foreach ($hRows as $h) $hMap[(int)$h['k']] = $h;
    $htariff_avg = []; $htariff_min = []; $htariff_max = [];
    $hkwh_avg    = []; $hkwh_min    = []; $hkwh_max    = [];
    foreach ($rows as $r) {
        $k = (int)substr($r['raw_date'], 8, 2);
        $h = $hMap[$k] ?? null;
        $htariff_avg[] = $h ? (float)$h['ta'] : null;
        $htariff_min[] = $h ? (float)$h['tn'] : null;
        $htariff_max[] = $h ? (float)$h['tx'] : null;
        $hkwh_avg[]    = $h ? (float)$h['ka'] : null;
        $hkwh_min[]    = $h ? (float)$h['kn'] : null;
        $hkwh_max[]    = $h ? (float)$h['kx'] : null;
    }

    $epex = []; $aufschlag = []; $abgaben = []; $gba = []; $mwst = []; $gesamt_var = [];
    $mfp = 0.0; $rfp = 0.0;
    invoice_breakdown($rows, false, $epex, $aufschlag, $abgaben, $gba, $mwst, $gesamt_var, $mfp, $rfp);

    $last_day = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
    echo json_encode([
        'labels'            => array_column($rows, 'label'),
        'cost'              => array_map('floatval', array_column($rows, 'cost_brutto')),
        'consumption'       => array_map('floatval', array_column($rows, 'consumed_kwh')),
        'tariff'            => array_map('floatval', array_column($rows, 'avg_spot_ct')),
        'min_spot'          => array_map(fn($r) => $r['min_spot_ct'] !== null ? (float)$r['min_spot_ct'] : null, $rows),
        'max_spot'          => array_map(fn($r) => $r['max_spot_ct'] !== null ? (float)$r['max_spot_ct'] : null, $rows),
        'dates'             => array_column($rows, 'raw_date'),
        'hist_tariff_avg'   => $htariff_avg,
        'hist_tariff_min'   => $htariff_min,
        'hist_tariff_max'   => $htariff_max,
        'hist_kwh_avg'      => $hkwh_avg,
        'hist_kwh_min'      => $hkwh_min,
        'hist_kwh_max'      => $hkwh_max,
        'maxCost'           => $max_daily_cost,
        'maxKwh'            => $max_daily_kwh,
        'epex'              => $epex,
        'epex_wgt'          => array_map(fn($r) => $r['epex_wgt'] !== null ? (float)$r['epex_wgt'] : null, $rows),
        'aufschlag'         => $aufschlag,
        'abgaben'           => $abgaben,
        'gebrauchsabgabe'   => $gba,
        'mwst_tax'          => $mwst,
        'gesamt_variable'   => $gesamt_var,
        'meter_fee_prop'    => $mfp,
        'renewable_fee_prop'=> $rfp,
        'period_start'      => sprintf('%04d-%02d-01', $year, $month),
        'period_end'        => sprintf('%04d-%02d-%02d', $year, $month, $last_day),
    ]);

} elseif ($type === 'yearly') {
    $year  = (int)($_GET['year']  ?? date('Y'));
    $month = (int)($_GET['month'] ?? (int)date('n'));
    $end   = sprintf('%04d-%02d-01', $year, $month);
    $start = date('Y-m-01', strtotime('-12 months', strtotime($end)));
    $stmt  = $pdo->prepare(
        "SELECT agg.month_key, agg.label, agg.consumed_kwh, agg.cost_brutto, agg.avg_spot_ct,
                agg.epex_wgt, " . TARIFF_COLS . "
         FROM (
             SELECT DATE_FORMAT(day, '%Y-%m')    AS month_key,
                    MIN(day)                     AS first_day,
                    DATE_FORMAT(MIN(day), '%m/%y') AS label,
                    SUM(consumed_kwh)            AS consumed_kwh,
                    SUM(cost_brutto)             AS cost_brutto,
                    AVG(avg_spot_ct)             AS avg_spot_ct,
                    SUM(avg_spot_ct * consumed_kwh) / NULLIF(SUM(consumed_kwh), 0) AS epex_wgt
             FROM daily_summary
             WHERE day >= ? AND day < DATE_ADD(?, INTERVAL 1 MONTH)
             GROUP BY month_key
         ) agg
         LEFT JOIN tariff_config t ON t.valid_from = (
             SELECT MAX(valid_from) FROM tariff_config WHERE valid_from <= agg.first_day
         )
         ORDER BY agg.month_key"
    );
    $stmt->execute([$start, $end]);
    $rows = $stmt->fetchAll();

    $hRows = $pdo->query(
        "SELECT k, AVG(ta) AS ta, MIN(ta) AS tn, MAX(ta) AS tx,
                   AVG(ks) AS ka, MIN(ks) AS kn, MAX(ks) AS kx
         FROM (SELECT MONTH(day) AS k, YEAR(day) AS yr,
                      AVG(avg_spot_ct) AS ta, SUM(consumed_kwh) AS ks
               FROM daily_summary GROUP BY yr, k) sub
         GROUP BY k"
    )->fetchAll();
    $hMap = []; foreach ($hRows as $h) $hMap[(int)$h['k']] = $h;
    $htariff_avg = []; $htariff_min = []; $htariff_max = [];
    $hkwh_avg    = []; $hkwh_min    = []; $hkwh_max    = [];
    foreach ($rows as $r) {
        $k = (int)substr($r['month_key'], 5, 2);
        $h = $hMap[$k] ?? null;
        $htariff_avg[] = $h ? (float)$h['ta'] : null;
        $htariff_min[] = $h ? (float)$h['tn'] : null;
        $htariff_max[] = $h ? (float)$h['tx'] : null;
        $hkwh_avg[]    = $h ? (float)$h['ka'] : null;
        $hkwh_min[]    = $h ? (float)$h['kn'] : null;
        $hkwh_max[]    = $h ? (float)$h['kx'] : null;
    }

    $epex = []; $aufschlag = []; $abgaben = []; $gba = []; $mwst = []; $gesamt_var = [];
    $mfp = 0.0; $rfp = 0.0;
    invoice_breakdown($rows, false, $epex, $aufschlag, $abgaben, $gba, $mwst, $gesamt_var, $mfp, $rfp);

    echo json_encode([
        'labels'            => array_column($rows, 'label'),
        'months'            => array_column($rows, 'month_key'),
        'cost'              => array_map('floatval', array_column($rows, 'cost_brutto')),
        'consumption'       => array_map('floatval', array_column($rows, 'consumed_kwh')),
        'tariff'            => array_map('floatval', array_column($rows, 'avg_spot_ct')),
        'hist_tariff_avg'   => $htariff_avg,
        'hist_tariff_min'   => $htariff_min,
        'hist_tariff_max'   => $htariff_max,
        'hist_kwh_avg'      => $hkwh_avg,
        'hist_kwh_min'      => $hkwh_min,
        'hist_kwh_max'      => $hkwh_max,
        'epex'              => $epex,
        'epex_wgt'          => array_map(fn($r) => $r['epex_wgt'] !== null ? (float)$r['epex_wgt'] : null, $rows),
        'aufschlag'         => $aufschlag,
        'abgaben'           => $abgaben,
        'gebrauchsabgabe'   => $gba,
        'mwst_tax'          => $mwst,
        'gesamt_variable'   => $gesamt_var,
        'meter_fee_prop'    => $mfp,
        'renewable_fee_prop'=> $rfp,
        'period_start'      => $start,
        'period_end'        => date('Y-m-t', strtotime($end)),
    ]);

} elseif ($type === 'preview-import') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit;
    }
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        http_response_code(403); echo json_encode(['error' => 'Invalid CSRF token']); exit;
    }
    $root    = realpath(dirname(__DIR__));
    $scrapes = $root . '/scrapes';
    $files   = array_merge(
        glob($scrapes . '/*.csv')  ?: [],
        glob($scrapes . '/*.xlsx') ?: []
    );
    if (empty($files)) {
        echo json_encode(['ok' => true, 'total' => 0, 'existing' => 0, 'new' => 0, 'files' => 0]);
        exit;
    }
    $timestamps = [];
    foreach ($files as $file) {
        $ts = strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'csv'
            ? _parse_energie_csv_timestamps($file)
            : [];                          // XLSX: preview not implemented; import will validate
        $timestamps = array_merge($timestamps, $ts);
    }
    $timestamps = array_values(array_unique($timestamps));
    $total = count($timestamps);
    $existing = 0;
    if ($total > 0) {
        $ph   = implode(',', array_fill(0, $total, '?'));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM readings WHERE ts IN ($ph)");
        $stmt->execute($timestamps);
        $existing = (int) $stmt->fetchColumn();
    }
    echo json_encode([
        'ok'       => true,
        'total'    => $total,
        'existing' => $existing,
        'new'      => $total - $existing,
        'files'    => count($files),
    ]);

} elseif ($type === 'trigger-import') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
    $root    = realpath(dirname(__DIR__));
    $scrapes = $root . '/scrapes';
    $files   = array_merge(
        glob($scrapes . '/*.csv')  ?: [],
        glob($scrapes . '/*.xlsx') ?: []
    );
    if (empty($files)) {
        echo json_encode(['ok' => true, 'count' => 0, 'rows' => 0]);
        exit;
    }
    $script   = $root . '/energie.py';
    $log      = '';
    $ok       = true;
    $imported = 0;
    $existing = 0;
    $total    = 0;
    foreach ($files as $file) {
        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(['/usr/local/bin/python3', $script, '--config', $config_path, 'import-csv', $file], $desc, $pipes, $root);
        $out  = $proc ? stream_get_contents($pipes[1]) : '';
        $err  = $proc ? stream_get_contents($pipes[2]) : '';
        if ($proc) { fclose($pipes[1]); fclose($pipes[2]); }
        $code = $proc ? proc_close($proc) : 1;
        $log .= $out . ($err ? "\nSTDERR: $err" : '');
        if ($code !== 0) { $ok = false; }
        // "✅ Imported N new, M existing, T total consumption rows"
        if (preg_match('/Imported (\d+) new, (\d+) existing, (\d+) total/', $out, $m)) {
            $imported += (int) $m[1];
            $existing += (int) $m[2];
            $total    += (int) $m[3];
        }
    }
    $count = count($files);
    if ($ok) {
        appendLog($con, 'import', "Import OK: {$count} file(s), {$imported} new, {$existing} existing, {$total} total.");
    } else {
        appendLog($con, 'import', "Import FAILED: {$count} file(s). " . mb_substr(trim($log), 0, 400));
    }
    echo json_encode([
        'ok'       => $ok,
        'count'    => $count,
        'imported' => $imported,
        'existing' => $existing,
        'total'    => $total,
        'log'      => $log,
    ]);

} elseif ($type === 'upload-csv') {
    // Admin-only CSV upload into scrapes/ for manual import
    if (($_SESSION['rights'] ?? '') !== 'Admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }

    $f = $_FILES['file'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
        $codes = [1=>'too large (ini)',2=>'too large (form)',3=>'partial',4=>'no file',6=>'no tmp',7=>'write fail',8=>'extension blocked'];
        $msg = $codes[$f['error'] ?? 4] ?? 'unknown error ' . ($f['error'] ?? '?');
        http_response_code(400);
        echo json_encode(['error' => "Upload error: {$msg}"]);
        exit;
    }

    // Size: max 10 MB
    if ($f['size'] > 10 * 1024 * 1024) {
        http_response_code(413);
        echo json_encode(['error' => 'File too large (max 10 MB)']);
        exit;
    }

    // Extension: only .csv
    $origName = $f['name'];
    if (strtolower(pathinfo($origName, PATHINFO_EXTENSION)) !== 'csv') {
        http_response_code(400);
        echo json_encode(['error' => 'Only .csv files are accepted']);
        exit;
    }

    // Content check: no null bytes, no PHP open tags
    $head = file_get_contents($f['tmp_name'], false, null, 0, 512);
    if ($head === false || str_contains($head, "\x00")) {
        http_response_code(400);
        echo json_encode(['error' => 'File appears to be binary, not CSV']);
        exit;
    }
    if (str_contains(strtolower($head), '<?')) {
        http_response_code(400);
        echo json_encode(['error' => 'File content rejected (forbidden characters)']);
        exit;
    }

    // Sanitize filename: keep only safe characters, prevent path traversal
    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($origName));
    if ($safeName === '' || $safeName[0] === '.') {
        $safeName = 'upload_' . $safeName;
    }

    $root    = realpath(dirname(__DIR__));
    $scrapes = $root . '/scrapes';
    $dest    = $scrapes . '/' . $safeName;

    if (file_exists($dest)) {
        http_response_code(409);
        echo json_encode(['error' => "A file named '{$safeName}' already exists in scrapes/"]);
        exit;
    }

    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save file']);
        exit;
    }

    appendLog($con, 'import', "CSV uploaded: {$safeName} (" . number_format($f['size']) . " bytes)");
    echo json_encode(['ok' => true, 'filename' => $safeName]);

} elseif ($type === 'set-theme') {
    $theme = $_POST['theme'] ?? '';
    if (!in_array($theme, ['light', 'dark', 'auto'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid theme']);
        exit;
    }
    $stmt = $con->prepare('UPDATE auth_accounts SET theme = ? WHERE id = ?');
    $stmt->bind_param('si', $theme, $_SESSION['id']);
    $stmt->execute();
    $stmt->close();
    $_SESSION['theme'] = $theme;
    echo json_encode(['ok' => true]);

} elseif (str_starts_with($type, 'admin_')) {
    \Erikr\Chrome\Admin\Dispatch::handle($con, $type, [
        'baseUrl' => APP_BASE_URL,
        'selfId'  => (int) ($_SESSION['id'] ?? 0),
    ]);
    exit;

} else {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown type. Use type=daily|weekly|monthly|yearly']);
}
