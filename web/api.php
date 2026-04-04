<?php
require_once __DIR__ . '/inc/db.php';
header('Content-Type: application/json');

$type = $_GET['type'] ?? '';

if ($type === 'daily') {
    $date = $_GET['date'] ?? date('Y-m-d', strtotime('-1 day'));
    $stmt = $pdo->prepare(
        "SELECT TIME(ts) AS label, consumed_kwh, cost_brutto
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
    ]);

} elseif ($type === 'weekly') {
    $year = (int)($_GET['year'] ?? date('Y'));
    $week = (int)($_GET['week'] ?? date('W'));
    $stmt = $pdo->prepare(
        "SELECT DATE_FORMAT(day, '%d.%m') AS label, consumed_kwh, cost_brutto, avg_spot_ct
         FROM daily_summary
         WHERE YEAR(day) = ? AND WEEK(day, 3) = ?
         ORDER BY day"
    );
    $stmt->execute([$year, $week]);
    $rows = $stmt->fetchAll();
    echo json_encode([
        'labels'      => array_column($rows, 'label'),
        'cost'        => array_map('floatval', array_column($rows, 'cost_brutto')),
        'consumption' => array_map('floatval', array_column($rows, 'consumed_kwh')),
    ]);

} elseif ($type === 'monthly') {
    $year  = (int)($_GET['year']  ?? date('Y'));
    $month = (int)($_GET['month'] ?? (int)date('m') - 1);
    if ($month < 1) { $month = 12; $year--; }
    $stmt = $pdo->prepare(
        "SELECT DATE_FORMAT(day, '%d.%m') AS label, consumed_kwh, cost_brutto, avg_spot_ct
         FROM daily_summary
         WHERE YEAR(day) = ? AND MONTH(day) = ?
         ORDER BY day"
    );
    $stmt->execute([$year, $month]);
    $rows = $stmt->fetchAll();
    echo json_encode([
        'labels'      => array_column($rows, 'label'),
        'cost'        => array_map('floatval', array_column($rows, 'cost_brutto')),
        'consumption' => array_map('floatval', array_column($rows, 'consumed_kwh')),
    ]);

} else {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown type. Use type=daily|weekly|monthly']);
}
