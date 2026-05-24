<?php
/**
 * scripts/cron_fetch_epex.php
 *
 * Standalone EPEX price fetcher for cron. Run daily or hourly.
 * Fetches spot prices for the current month and any past months
 * with missing prices, then recalculates cost_brutto and
 * rebuilds daily_summary for newly-fixed slots.
 *
 * Usage (world4you):
 *   /usr/bin/php84 /path/to/energie/scripts/cron_fetch_epex.php
 *
 * Usage (akadbrain):
 *   /opt/homebrew/bin/php /path/to/energie/scripts/cron_fetch_epex.php
 */

$root = dirname(__DIR__);
require_once $root . '/inc/yaml.php';
require_once $root . '/inc/epex_importer.php';

$cfg = wl_yaml_load($root . '/config.yaml');
$db  = $cfg['db'];
$pdo = new PDO(
    'mysql:host=' . $db['host'] . ';dbname=' . $db['name'] . ';charset=utf8mb4',
    $db['user'], $db['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// 1. Always fetch the current month proactively (no readings exist yet for
//    future hours, so php_fetch_missing_epex would skip it).
$y = (int) date('Y');
$m = (int) date('n');
$cur = php_fetch_epex($pdo, $y, $m);
echo date('c') . ' current month: ' . $cur['log'] . "\n";

// 2. Fix any past months with partial or full zero-spot gaps.
$backfill = php_fetch_missing_epex($pdo);
if ($backfill['months'] > 0) {
    echo date('c') . ' backfill: ' . $backfill['log'] . "\n";
}

// 3. Recalculate cost_brutto for any consumption slot where spot_ct is now
//    populated but cost_brutto is still zero (happens when spot prices arrive
//    after the CSV import ran).
$stmt = $pdo->query("
    SELECT DISTINCT DATE(ts) AS day
    FROM readings
    WHERE consumed_kwh > 0 AND spot_ct > 0 AND cost_brutto = 0
    ORDER BY day
");
$days = $stmt->fetchAll(PDO::FETCH_COLUMN);

if ($days) {
    $update = $pdo->prepare("
        UPDATE readings r
        JOIN (
            SELECT provider_surcharge_ct, electricity_tax_ct, renewable_tax_ct,
                   meter_fee_eur, renewable_fee_eur, consumption_tax_rate, vat_rate,
                   yearly_kwh_estimate
            FROM tariff_config
            WHERE valid_from = (SELECT MAX(valid_from) FROM tariff_config WHERE valid_from <= ?)
        ) t ON 1=1
        SET r.cost_brutto = r.consumed_kwh
            * ( (r.spot_ct + t.provider_surcharge_ct + t.electricity_tax_ct + t.renewable_tax_ct
                 + (t.meter_fee_eur + t.renewable_fee_eur) / t.yearly_kwh_estimate * 100)
                * (1 + t.vat_rate + t.consumption_tax_rate) )
            / 100
        WHERE DATE(r.ts) = ? AND r.consumed_kwh > 0 AND spot_ct > 0 AND cost_brutto = 0
    ");

    $ph   = implode(',', array_fill(0, count($days), '?'));
    $dsub = $pdo->prepare("
        INSERT INTO daily_summary (day, consumed_kwh, cost_brutto, avg_spot_ct)
        SELECT DATE(ts), SUM(consumed_kwh), SUM(cost_brutto), AVG(spot_ct)
        FROM readings WHERE DATE(ts) IN ($ph) GROUP BY DATE(ts)
        ON DUPLICATE KEY UPDATE
          consumed_kwh = VALUES(consumed_kwh),
          cost_brutto  = VALUES(cost_brutto),
          avg_spot_ct  = VALUES(avg_spot_ct)
    ");

    foreach ($days as $day) {
        $update->execute([$day, $day]);
    }
    $dsub->execute($days);
    echo date('c') . ' recalculated cost_brutto + daily_summary for: ' . implode(', ', $days) . "\n";
}

echo date('c') . " done.\n";
