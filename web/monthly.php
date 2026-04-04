<?php
require_once __DIR__ . '/db.php';

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? ((int)date('m') - 1 ?: 12));

$prev_month = $month - 1; $prev_year = $year;
if ($prev_month < 1) { $prev_month = 12; $prev_year--; }
$next_month = $month + 1; $next_year = $year;
if ($next_month > 12) { $next_month = 1; $next_year++; }

$prev_url = "/energie/monthly.php?year=$prev_year&month=$prev_month";
$stmt = $pdo->prepare("SELECT COUNT(*) FROM daily_summary WHERE YEAR(day)=? AND MONTH(day)=?");
$stmt->execute([$next_year, $next_month]);
$next_url = $stmt->fetchColumn() > 0 ? "/energie/monthly.php?year=$next_year&month=$next_month" : null;

$stmt = $pdo->prepare(
    "SELECT SUM(consumed_kwh) AS kwh, SUM(cost_brutto) AS eur, AVG(avg_spot_ct) AS ct
     FROM daily_summary WHERE YEAR(day)=? AND MONTH(day)=?");
$stmt->execute([$year, $month]);
$summary = $stmt->fetch() ?: ['kwh' => 0, 'eur' => 0, 'ct' => 0];

$month_name   = date('F Y', mktime(0, 0, 0, $month, 1, $year));
$title        = $month_name;
$period_label = $month_name;
$api_url      = "/energie/api.php?type=monthly&year=$year&month=$month";
$kpi_kwh      = (float)$summary['kwh'];
$kpi_eur      = (float)$summary['eur'];
$kpi_ct       = (float)$summary['ct'];

require __DIR__ . '/_chart_page.php';
