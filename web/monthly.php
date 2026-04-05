<?php
require_once __DIR__ . '/../inc/db.php';
auth_require();

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? ((int)date('m') - 1 ?: 12));

$prev_month = $month - 1; $prev_year = $year;
if ($prev_month < 1) { $prev_month = 12; $prev_year--; }
$next_month = $month + 1; $next_year = $year;
if ($next_month > 12) { $next_month = 1; $next_year++; }

$prev_url = "$base/monthly.php?year=$prev_year&month=$prev_month";
$stmt = $pdo->prepare("SELECT COUNT(*) FROM daily_summary WHERE YEAR(day)=? AND MONTH(day)=?");
$stmt->execute([$next_year, $next_month]);
$next_url = $stmt->fetchColumn() > 0 ? "$base/monthly.php?year=$next_year&month=$next_month" : null;

$stmt = $pdo->prepare(
    "SELECT SUM(consumed_kwh) AS kwh, SUM(cost_brutto) AS eur, AVG(avg_spot_ct) AS ct
     FROM daily_summary WHERE YEAR(day)=? AND MONTH(day)=?");
$stmt->execute([$year, $month]);
$summary = $stmt->fetch() ?: ['kwh' => 0, 'eur' => 0, 'ct' => 0];

$de_months = ['Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'];
$page_type        = 'monthly';
$current_date_iso = sprintf('%04d-%02d-01', $year, $month);
$month_name       = $de_months[$month - 1] . ' ' . $year;
$title        = $month_name;
$period_label = $month_name;
$prev_label   = $de_months[$prev_month - 1] . ' ' . $prev_year;
$next_label   = $de_months[$next_month - 1] . ' ' . $next_year;
$api_url      = "$base/api.php?type=monthly&year=$year&month=$month";
$kpi_kwh      = (float)$summary['kwh'];
$kpi_eur      = (float)$summary['eur'];
$kpi_ct       = (float)$summary['ct'];

require __DIR__ . '/../inc/_chart_page.php';
