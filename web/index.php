<?php
require_once __DIR__ . '/../inc/db.php';
auth_require();

// Most recent day with actual consumption data
$stmt = $pdo->query("SELECT MAX(day) AS latest FROM daily_summary WHERE consumed_kwh > 0");
$latest = $stmt->fetch()['latest'] ?? date('Y-m-d', strtotime('-1 day'));

// Yesterday tile
$stmt = $pdo->prepare("SELECT consumed_kwh, cost_brutto, avg_spot_ct FROM daily_summary WHERE day = ?");
$stmt->execute([$latest]);
$today = $stmt->fetch() ?: ['consumed_kwh' => 0, 'cost_brutto' => 0, 'avg_spot_ct' => 0];

// Last 7 days tile
$stmt = $pdo->prepare(
    "SELECT SUM(consumed_kwh) AS consumed_kwh, SUM(cost_brutto) AS cost_brutto,
            AVG(avg_spot_ct) AS avg_spot_ct, MIN(day) AS from_day, MAX(day) AS to_day
     FROM daily_summary WHERE day > DATE_SUB(?, INTERVAL 7 DAY)");
$stmt->execute([$latest]);
$week = $stmt->fetch();

// Current ISO week for link
$latest_dt = new DateTime($latest);
$iso_year  = $latest_dt->format('o');
$iso_week  = $latest_dt->format('W');

// Last 30 days tile
$stmt = $pdo->prepare(
    "SELECT SUM(consumed_kwh) AS consumed_kwh, SUM(cost_brutto) AS cost_brutto,
            AVG(avg_spot_ct) AS avg_spot_ct, MONTH(MIN(day)) AS m, YEAR(MIN(day)) AS y
     FROM daily_summary WHERE day > DATE_SUB(?, INTERVAL 30 DAY)");
$stmt->execute([$latest]);
$month = $stmt->fetch();
$prev_month = (int)$month['m'];
$prev_year  = (int)$month['y'];

function fmt_kwh($v) { return number_format($v, 1, ',', '.') . ' kWh'; }
function fmt_eur($v) { return '€ ' . number_format($v, 2, ',', '.'); }
function fmt_ct($v)  { return number_format($v, 1, ',', '.') . ' ct/kWh'; }
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Energie</title>
    <link rel="stylesheet" href="<?= $base ?>/styles/shared/theme.css">
    <link rel="stylesheet" href="<?= $base ?>/styles/shared/reset.css">
    <link rel="stylesheet" href="<?= $base ?>/styles/shared/layout.css">
    <link rel="stylesheet" href="<?= $base ?>/styles/shared/components.css">
    <link rel="stylesheet" href="<?= $base ?>/styles/energie-theme.css">
    <link rel="stylesheet" href="<?= $base ?>/styles/energie.css">
    <meta name="theme-color" content="<?= htmlspecialchars(APP_COLOR, ENT_QUOTES) ?>">
    <link rel="icon" type="image/svg+xml" href="<?= $base ?>/jardyx-favicon.svg">
    <link rel="icon" type="image/x-icon" href="<?= $base ?>/assets/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $base ?>/assets/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= $base ?>/assets/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= $base ?>/assets/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= $base ?>/assets/web-app-manifest-192x192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="<?= $base ?>/assets/web-app-manifest-512x512.png">
</head>
<body>
<?php $page_type = 'index'; require __DIR__ . '/../inc/_header.php'; ?>
<main id="main-content" tabindex="-1">
    <div class="tiles">

        <a class="tile" href="<?= $base ?>/daily.php?date=<?= htmlspecialchars($latest) ?>">
            <div class="icon">📅</div>
            <div class="period"><?= $latest === date('Y-m-d') ? 'Heute' : 'Letzter Tag' ?></div>
            <h2><?= date('D, d.m.Y', strtotime($latest)) ?></h2>
            <div class="kpi">
                <div class="kpi-row"><span class="kpi-label">Verbrauch</span><span class="kpi-value kwh"><?= fmt_kwh($today['consumed_kwh']) ?></span></div>
                <div class="kpi-row"><span class="kpi-label">Kosten</span><span class="kpi-value eur"><?= fmt_eur($today['cost_brutto']) ?></span></div>
                <div class="kpi-row"><span class="kpi-label">Ø Tarif</span><span class="kpi-value tariff"><?= fmt_ct($today['avg_spot_ct']) ?></span></div>
            </div>
        </a>

        <a class="tile" href="<?= $base ?>/weekly.php?year=<?= $iso_year ?>&week=<?= $iso_week ?>">
            <div class="icon">📊</div>
            <div class="period">Letzte 7 Tage</div>
            <h2>KW<?= $iso_week ?> · <?= date('d.m', strtotime($week['from_day'])) ?>–<?= date('d.m.y', strtotime($week['to_day'])) ?></h2>
            <div class="kpi">
                <div class="kpi-row"><span class="kpi-label">Verbrauch</span><span class="kpi-value kwh"><?= fmt_kwh($week['consumed_kwh']) ?></span></div>
                <div class="kpi-row"><span class="kpi-label">Kosten</span><span class="kpi-value eur"><?= fmt_eur($week['cost_brutto']) ?></span></div>
                <div class="kpi-row"><span class="kpi-label">Ø Tarif</span><span class="kpi-value tariff"><?= fmt_ct($week['avg_spot_ct']) ?></span></div>
            </div>
        </a>

        <a class="tile" href="<?= $base ?>/monthly.php?year=<?= $prev_year ?>&month=<?= $prev_month ?>">
            <div class="icon">📈</div>
            <div class="period">Letzte 30 Tage</div>
            <h2><?= date('F Y', mktime(0,0,0,$prev_month,1,$prev_year)) ?></h2>
            <div class="kpi">
                <div class="kpi-row"><span class="kpi-label">Verbrauch</span><span class="kpi-value kwh"><?= fmt_kwh($month['consumed_kwh']) ?></span></div>
                <div class="kpi-row"><span class="kpi-label">Kosten</span><span class="kpi-value eur"><?= fmt_eur($month['cost_brutto']) ?></span></div>
                <div class="kpi-row"><span class="kpi-label">Ø Tarif</span><span class="kpi-value tariff"><?= fmt_ct($month['avg_spot_ct']) ?></span></div>
            </div>
        </a>

    </div>
</main>
<?php require __DIR__ . '/../inc/_footer.php'; ?>
</body>
</html>
