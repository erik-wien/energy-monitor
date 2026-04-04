<?php
require_once __DIR__ . '/db.php';

$year = (int)($_GET['year'] ?? date('Y'));
$week = (int)($_GET['week'] ?? (int)date('W'));

// Prev/next week
$prev_week = $week - 1; $prev_year = $year;
if ($prev_week < 1) { $prev_week = 52; $prev_year--; }
$next_week = $week + 1; $next_year = $year;
$max_week  = (int)(new DateTime("$year-12-28"))->format('W');
if ($next_week > $max_week) { $next_week = 1; $next_year++; }

// Week date range (ISO Monday–Sunday)
$mon = new DateTime(); $mon->setISODate($year, $week, 1);
$sun = new DateTime(); $sun->setISODate($year, $week, 7);

$prev_url = "/energie/weekly.php?year=$prev_year&week=$prev_week";
$stmt = $pdo->prepare("SELECT COUNT(*) FROM daily_summary WHERE YEAR(day)=? AND WEEK(day,3)=?");
$stmt->execute([$next_year, $next_week]);
$next_url = $stmt->fetchColumn() > 0 ? "/energie/weekly.php?year=$next_year&week=$next_week" : null;

$stmt = $pdo->prepare(
    "SELECT SUM(consumed_kwh) AS kwh, SUM(cost_brutto) AS eur, AVG(avg_spot_ct) AS ct
     FROM daily_summary WHERE YEAR(day)=? AND WEEK(day,3)=?");
$stmt->execute([$year, $week]);
$summary = $stmt->fetch() ?: ['kwh' => 0, 'eur' => 0, 'ct' => 0];

$title        = "KW$week $year";
$period_label = "KW$week · {$mon->format('d.m')}–{$sun->format('d.m.y')}";
$api_url      = "/energie/api.php?type=weekly&year=$year&week=$week";
$kpi_kwh      = (float)$summary['kwh'];
$kpi_eur      = (float)$summary['eur'];
$kpi_ct       = (float)$summary['ct'];

require __DIR__ . '/_chart_page.php';
