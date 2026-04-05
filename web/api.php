<?php
require_once __DIR__ . '/../inc/db.php';

if (empty($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$type = $_GET['type'] ?? '';

// Global Y-axis maxima for consistent scale across all charts
$max_daily_kwh  = (float)$pdo->query("SELECT MAX(consumed_kwh) FROM daily_summary")->fetchColumn();
$max_daily_cost = (float)$pdo->query("SELECT MAX(cost_brutto)  FROM daily_summary")->fetchColumn();
$max_slot_kwh   = (float)$pdo->query("SELECT MAX(consumed_kwh) FROM readings")->fetchColumn();
$max_slot_cost  = (float)$pdo->query("SELECT MAX(cost_brutto)  FROM readings")->fetchColumn();

if ($type === 'daily') {
    $date = $_GET['date'] ?? date('Y-m-d', strtotime('-1 day'));
    $stmt = $pdo->prepare(
        "SELECT TIME(ts) AS label, consumed_kwh, cost_brutto, spot_ct
         FROM readings
         WHERE DATE(ts) = ?
         ORDER BY ts"
    );
    $stmt->execute([$date]);
    $rows = $stmt->fetchAll();
    echo json_encode([
        'labels'      => array_column($rows, 'label'),
        'cost'        => array_map('floatval', array_column($rows, 'cost_brutto')),
        'consumption' => array_map('floatval', array_column($rows, 'consumed_kwh')),
        'tariff'      => array_map('floatval', array_column($rows, 'spot_ct')),
        'maxCost'     => $max_slot_cost,
        'maxKwh'      => $max_slot_kwh,
    ]);

} elseif ($type === 'weekly') {
    $year = (int)($_GET['year'] ?? date('Y'));
    $week = (int)($_GET['week'] ?? date('W'));
    $stmt = $pdo->prepare(
        "SELECT DATE_FORMAT(ds.day, '%d.%m') AS label, DATE(ds.day) AS raw_date,
                ds.consumed_kwh, ds.cost_brutto, ds.avg_spot_ct,
                MIN(r.spot_ct) AS min_spot_ct, MAX(r.spot_ct) AS max_spot_ct
         FROM daily_summary ds
         LEFT JOIN readings r ON DATE(r.ts) = ds.day
         WHERE YEAR(ds.day) = ? AND WEEK(ds.day, 3) = ?
         GROUP BY ds.day
         ORDER BY ds.day"
    );
    $stmt->execute([$year, $week]);
    $rows = $stmt->fetchAll();
    echo json_encode([
        'labels'      => array_column($rows, 'label'),
        'cost'        => array_map('floatval', array_column($rows, 'cost_brutto')),
        'consumption' => array_map('floatval', array_column($rows, 'consumed_kwh')),
        'tariff'      => array_map('floatval', array_column($rows, 'avg_spot_ct')),
        'min_spot'    => array_map('floatval', array_column($rows, 'min_spot_ct')),
        'max_spot'    => array_map('floatval', array_column($rows, 'max_spot_ct')),
        'dates'       => array_column($rows, 'raw_date'),
        'maxCost'     => $max_daily_cost,
        'maxKwh'      => $max_daily_kwh,
    ]);

} elseif ($type === 'monthly') {
    $year  = (int)($_GET['year']  ?? date('Y'));
    $month = (int)($_GET['month'] ?? (int)date('m') - 1);
    if ($month < 1) { $month = 12; $year--; }
    $stmt = $pdo->prepare(
        "SELECT DATE_FORMAT(ds.day, '%d.%m') AS label, DATE(ds.day) AS raw_date,
                ds.consumed_kwh, ds.cost_brutto, ds.avg_spot_ct,
                MIN(r.spot_ct) AS min_spot_ct, MAX(r.spot_ct) AS max_spot_ct
         FROM daily_summary ds
         LEFT JOIN readings r ON DATE(r.ts) = ds.day
         WHERE YEAR(ds.day) = ? AND MONTH(ds.day) = ?
         GROUP BY ds.day
         ORDER BY ds.day"
    );
    $stmt->execute([$year, $month]);
    $rows = $stmt->fetchAll();
    echo json_encode([
        'labels'      => array_column($rows, 'label'),
        'cost'        => array_map('floatval', array_column($rows, 'cost_brutto')),
        'consumption' => array_map('floatval', array_column($rows, 'consumed_kwh')),
        'tariff'      => array_map('floatval', array_column($rows, 'avg_spot_ct')),
        'min_spot'    => array_map('floatval', array_column($rows, 'min_spot_ct')),
        'max_spot'    => array_map('floatval', array_column($rows, 'max_spot_ct')),
        'dates'       => array_column($rows, 'raw_date'),
        'maxCost'     => $max_daily_cost,
        'maxKwh'      => $max_daily_kwh,
    ]);

} else {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown type. Use type=daily|weekly|monthly']);
}
