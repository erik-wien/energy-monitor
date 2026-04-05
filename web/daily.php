<?php
require_once __DIR__ . '/../inc/db.php';
auth_require();

$date = $_GET['date'] ?? date('Y-m-d', strtotime('-1 day'));
$dt   = new DateTime($date);

$prev_url = $base . '/daily.php?date=' . $dt->modify('-1 day')->format('Y-m-d');
$dt->modify('+1 day'); // restore
$next_date = $dt->modify('+1 day')->format('Y-m-d');
$dt->modify('-1 day'); // restore to $date

// Check if next date has data
$stmt = $pdo->prepare("SELECT COUNT(*) FROM readings WHERE DATE(ts) = ?");
$stmt->execute([$next_date]);
$has_next = $stmt->fetchColumn() > 0;
$next_url = $has_next ? $base . '/daily.php?date=' . $next_date : null;

$stmt = $pdo->prepare("SELECT consumed_kwh, cost_brutto, avg_spot_ct FROM daily_summary WHERE day = ?");
$stmt->execute([$date]);
$summary = $stmt->fetch() ?: ['consumed_kwh' => 0, 'cost_brutto' => 0, 'avg_spot_ct' => 0];

$de_day = ['Sun'=>'So','Mon'=>'Mo','Tue'=>'Di','Wed'=>'Mi','Thu'=>'Do','Fri'=>'Fr','Sat'=>'Sa'];
$fmt_day = function($d) use ($de_day) {
    return $de_day[date('D', strtotime($d))] . ' ' . date('d.m.Y', strtotime($d));
};

$prev_date    = (clone $dt)->modify('-1 day')->format('Y-m-d');
$page_type        = 'daily';
$current_date_iso = $date;
$title            = date('d.m.Y', strtotime($date));
$period_label = $fmt_day($date);
$prev_label   = $fmt_day($prev_date);
$next_label   = $fmt_day($next_date);
$api_url      = $base . '/api.php?type=daily&date=' . $date;
$kpi_kwh      = (float)$summary['consumed_kwh'];
$kpi_eur      = (float)$summary['cost_brutto'];
$kpi_ct       = (float)$summary['avg_spot_ct'];

require __DIR__ . '/../inc/_chart_page.php';
