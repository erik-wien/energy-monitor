<?php
require_once __DIR__ . '/db.php';

$date = $_GET['date'] ?? date('Y-m-d', strtotime('-1 day'));
$dt   = new DateTime($date);

$prev_url = '/energie/daily.php?date=' . $dt->modify('-1 day')->format('Y-m-d');
$dt->modify('+1 day'); // restore
$next_date = $dt->modify('+1 day')->format('Y-m-d');
$dt->modify('-1 day'); // restore to $date

// Check if next date has data
$stmt = $pdo->prepare("SELECT COUNT(*) FROM readings WHERE DATE(ts) = ?");
$stmt->execute([$next_date]);
$has_next = $stmt->fetchColumn() > 0;
$next_url = $has_next ? '/energie/daily.php?date=' . $next_date : null;

$stmt = $pdo->prepare("SELECT consumed_kwh, cost_brutto, avg_spot_ct FROM daily_summary WHERE day = ?");
$stmt->execute([$date]);
$summary = $stmt->fetch() ?: ['consumed_kwh' => 0, 'cost_brutto' => 0, 'avg_spot_ct' => 0];

$title        = date('d.m.Y', strtotime($date));
$period_label = date('D d.m.Y', strtotime($date));
$api_url      = '/energie/api.php?type=daily&date=' . $date;
$kpi_kwh      = (float)$summary['consumed_kwh'];
$kpi_eur      = (float)$summary['cost_brutto'];
$kpi_ct       = (float)$summary['avg_spot_ct'];

require __DIR__ . '/_chart_page.php';
