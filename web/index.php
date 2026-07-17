<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/insight.php';
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
            SUM(avg_spot_ct * consumed_kwh) / NULLIF(SUM(consumed_kwh), 0) AS avg_spot_ct,
            MIN(day) AS from_day, MAX(day) AS to_day
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
            SUM(avg_spot_ct * consumed_kwh) / NULLIF(SUM(consumed_kwh), 0) AS avg_spot_ct,
            MONTH(MIN(day)) AS m, YEAR(MIN(day)) AS y
     FROM daily_summary WHERE day > DATE_SUB(?, INTERVAL 30 DAY)");
$stmt->execute([$latest]);
$month = $stmt->fetch();
$prev_month = (int)$month['m'];
$prev_year  = (int)$month['y'];

// ── Vorige gleichlange Perioden, nur für die Delta-Chips der Kacheln ────────
// (Anzeige-Kontext; die Effektiv-KPI-Berechnung der Kacheln selbst bleibt unverändert.)

// SUM()-Aggregate über WHERE ohne Treffer liefern eine Zeile mit NULL-Spalten
// (kein leeres fetch()) -> hier auf 0.0 normalisieren (en_delta/kpi_eff sind
// float-typisiert, insight.php läuft mit strict_types).
$normPrev = static function (array|false $row): array {
    return ['consumed_kwh' => (float)($row['consumed_kwh'] ?? 0), 'cost_brutto' => (float)($row['cost_brutto'] ?? 0)];
};

$stmt = $pdo->prepare("SELECT consumed_kwh, cost_brutto FROM daily_summary WHERE day = DATE_SUB(?, INTERVAL 1 DAY)");
$stmt->execute([$latest]);
$todayPrev = $normPrev($stmt->fetch());

$stmt = $pdo->prepare(
    "SELECT SUM(consumed_kwh) AS consumed_kwh, SUM(cost_brutto) AS cost_brutto
     FROM daily_summary
     WHERE day > DATE_SUB(?, INTERVAL 14 DAY) AND day <= DATE_SUB(?, INTERVAL 7 DAY)");
$stmt->execute([$latest, $latest]);
$weekPrev = $normPrev($stmt->fetch());

$stmt = $pdo->prepare(
    "SELECT SUM(consumed_kwh) AS consumed_kwh, SUM(cost_brutto) AS cost_brutto
     FROM daily_summary
     WHERE day > DATE_SUB(?, INTERVAL 60 DAY) AND day <= DATE_SUB(?, INTERVAL 30 DAY)");
$stmt->execute([$latest, $latest]);
$monthPrev = $normPrev($stmt->fetch());

function fmt_kwh($v) { return number_format($v, 1, ',', '.') . ' <span class="unit">kWh</span>'; }
function fmt_eur($v) { return '<span class="unit">€</span> ' . number_format($v, 2, ',', '.'); }
function fmt_ct($v)  { return number_format($v, 1, ',', '.') . ' <span class="unit">ct/kWh</span>'; }
function kpi_eff($eur, $kwh) { return $kwh != 0.0 ? $eur / $kwh * 100 : 0.0; }

/** Delta-Chip-Markup (▲/▼/·). $sem=false → immer neutral eingefärbt (nur Richtung/Wert). */
function en_chip(array $d, bool $sem = true): string {
    if ($d['pct'] === null) return '';
    $a = $d['dir']==='up'?'▲':($d['dir']==='down'?'▼':'·');
    return '<span class="delta-chip '.($sem?$d['dir']:'flat').'">'.$a.' '.number_format(abs($d['pct']),0,',','.').' %</span>';
}

// Kachel-Sparklines: Tagesreihe des effektiven Preises im jeweiligen Betrachtungsfenster.
// Tag-Kachel hat selbst nur einen Tag -> 14 Tage Trend-Kontext bis $latest als Sparkline.
$daySparkFrom = date('Y-m-d', strtotime($latest . ' -13 days'));
$daySpark   = en_sparkline_svg(en_effektiv_serie($pdo, $daySparkFrom, $latest));
$weekSpark  = en_sparkline_svg(en_effektiv_serie($pdo, $week['from_day'], $week['to_day']));
$monthSparkFrom = date('Y-m-d', strtotime($latest . ' -29 days'));
$monthSpark = en_sparkline_svg(en_effektiv_serie($pdo, $monthSparkFrom, $latest));

// Preis-Hero: Zusammensetzung ct/kWh der letzten 30 Tage (Börse -> Rechnung).
$komp = en_preis_komposition($pdo, date('Y-m-d', strtotime('-29 days')), date('Y-m-d'));
$komp_pct = static fn(float $v): float => $komp['brutto'] > 0 ? $v / $komp['brutto'] * 100 : 0.0;
?>
<?php render_page_head('Energie'); render_header('index'); ?>
<main id="main-content" tabindex="-1">

    <section class="preis-hero" aria-label="Preiszusammensetzung">
        <h2 class="preis-hero-title">Dein Strompreis je kWh — von der Börse bis zur Rechnung</h2>
        <div class="preis-bar-track">
            <div class="preis-bar">
                <div class="seg-spot"      style="flex-basis:<?= sprintf('%.3f', $komp_pct($komp['spot'])) ?>%"></div>
                <div class="seg-aufschlag" style="flex-basis:<?= sprintf('%.3f', $komp_pct($komp['aufschlag'])) ?>%"></div>
                <div class="seg-abgaben"   style="flex-basis:<?= sprintf('%.3f', $komp_pct($komp['abgaben'])) ?>%"></div>
                <div class="seg-gba"       style="flex-basis:<?= sprintf('%.3f', $komp_pct($komp['gba'])) ?>%"></div>
                <div class="seg-mwst"      style="flex-basis:<?= sprintf('%.3f', $komp_pct($komp['mwst'])) ?>%"></div>
                <div class="seg-fixkosten" style="flex-basis:<?= sprintf('%.3f', $komp_pct($komp['fixkosten'])) ?>%"></div>
            </div>
            <div class="preis-ticks">
                <div class="preis-tick" style="left:<?= sprintf('%.3f', $komp_pct($komp['spot'])) ?>%">
                    <span class="tick-mark">▲</span>
                    <span class="tick-value"><?= fmt_ct($komp['spot']) ?></span>
                    <span>Börse</span>
                </div>
                <div class="preis-tick" style="left:<?= sprintf('%.3f', $komp_pct($komp['netto'])) ?>%">
                    <span class="tick-mark">▲</span>
                    <span class="tick-value"><?= fmt_ct($komp['netto']) ?></span>
                    <span>vergleichbar mit Pauschalangeboten</span>
                </div>
                <div class="preis-tick" style="left:100%">
                    <span class="tick-mark">▲</span>
                    <span class="tick-value"><?= fmt_ct($komp['brutto']) ?></span>
                    <span>real gezahlt</span>
                </div>
            </div>
        </div>
        <ul class="preis-legende">
            <li><span class="legende-dot seg-spot"></span> Börse <?= fmt_ct($komp['spot']) ?></li>
            <li><span class="legende-dot seg-aufschlag"></span> Aufschlag <?= fmt_ct($komp['aufschlag']) ?></li>
            <li><span class="legende-dot seg-abgaben"></span> Abgaben <?= fmt_ct($komp['abgaben']) ?></li>
            <li><span class="legende-dot seg-gba"></span> Gebrauchsabgabe <?= fmt_ct($komp['gba']) ?></li>
            <li><span class="legende-dot seg-mwst"></span> MwSt <?= fmt_ct($komp['mwst']) ?></li>
            <li><span class="legende-dot seg-fixkosten"></span> Fixkosten <?= fmt_ct($komp['fixkosten']) ?></li>
        </ul>
    </section>

    <div class="tiles">

        <a class="tile" href="<?= $base ?>/daily.php?date=<?= htmlspecialchars($latest) ?>">
            <div class="tile-icon"><span class="ui-icon ui-icon-calendar" aria-hidden="true"></span></div>
            <div class="period"><?= $latest === date('Y-m-d') ? 'Heute' : 'Letzter Tag' ?></div>
            <h2><?= date('D, d.m.Y', strtotime($latest)) ?></h2>
            <div class="kpi">
                <div class="kpi-row"><span class="kpi-label">Verbrauch</span><span class="kpi-value kwh"><?= fmt_kwh($today['consumed_kwh']) ?><?= en_chip(en_delta((float)$today['consumed_kwh'], (float)$todayPrev['consumed_kwh']), false) ?></span></div>
                <div class="kpi-row"><span class="kpi-label">Kosten</span><span class="kpi-value eur"><?= fmt_eur($today['cost_brutto']) ?></span></div>
                <div class="kpi-row"><span class="kpi-label">Ø Spotpreis</span><span class="kpi-value tariff"><?= fmt_ct($today['avg_spot_ct']) ?></span></div>
                <div class="kpi-row"><span class="kpi-label">Ø effektiv</span><span class="kpi-value eff"><?= fmt_ct(kpi_eff($today['cost_brutto'], $today['consumed_kwh'])) ?><?= en_chip(en_delta(kpi_eff($today['cost_brutto'], $today['consumed_kwh']), kpi_eff($todayPrev['cost_brutto'], $todayPrev['consumed_kwh']))) ?><?= $daySpark ?></span></div>
            </div>
        </a>

        <a class="tile" href="<?= $base ?>/weekly.php?year=<?= $iso_year ?>&week=<?= $iso_week ?>">
            <div class="tile-icon"><span class="ui-icon ui-icon-bar-chart-3" aria-hidden="true"></span></div>
            <div class="period">Letzte 7 Tage</div>
            <h2>KW<?= $iso_week ?> · <?= date('d.m', strtotime($week['from_day'])) ?>–<?= date('d.m.y', strtotime($week['to_day'])) ?></h2>
            <div class="kpi">
                <div class="kpi-row"><span class="kpi-label">Verbrauch</span><span class="kpi-value kwh"><?= fmt_kwh($week['consumed_kwh']) ?><?= en_chip(en_delta((float)$week['consumed_kwh'], (float)$weekPrev['consumed_kwh']), false) ?></span></div>
                <div class="kpi-row"><span class="kpi-label">Kosten</span><span class="kpi-value eur"><?= fmt_eur($week['cost_brutto']) ?></span></div>
                <div class="kpi-row"><span class="kpi-label">Ø Spotpreis</span><span class="kpi-value tariff"><?= fmt_ct($week['avg_spot_ct']) ?></span></div>
                <div class="kpi-row"><span class="kpi-label">Ø effektiv</span><span class="kpi-value eff"><?= fmt_ct(kpi_eff($week['cost_brutto'], $week['consumed_kwh'])) ?><?= en_chip(en_delta(kpi_eff($week['cost_brutto'], $week['consumed_kwh']), kpi_eff($weekPrev['cost_brutto'], $weekPrev['consumed_kwh']))) ?><?= $weekSpark ?></span></div>
            </div>
        </a>

        <a class="tile" href="<?= $base ?>/monthly.php?year=<?= $prev_year ?>&month=<?= $prev_month ?>">
            <div class="tile-icon"><span class="ui-icon ui-icon-trending-up" aria-hidden="true"></span></div>
            <div class="period">Letzte 30 Tage</div>
            <h2><?= date('F Y', mktime(0,0,0,$prev_month,1,$prev_year)) ?></h2>
            <div class="kpi">
                <div class="kpi-row"><span class="kpi-label">Verbrauch</span><span class="kpi-value kwh"><?= fmt_kwh($month['consumed_kwh']) ?><?= en_chip(en_delta((float)$month['consumed_kwh'], (float)$monthPrev['consumed_kwh']), false) ?></span></div>
                <div class="kpi-row"><span class="kpi-label">Kosten</span><span class="kpi-value eur"><?= fmt_eur($month['cost_brutto']) ?></span></div>
                <div class="kpi-row"><span class="kpi-label">Ø Spotpreis</span><span class="kpi-value tariff"><?= fmt_ct($month['avg_spot_ct']) ?></span></div>
                <div class="kpi-row"><span class="kpi-label">Ø effektiv</span><span class="kpi-value eff"><?= fmt_ct(kpi_eff($month['cost_brutto'], $month['consumed_kwh'])) ?><?= en_chip(en_delta(kpi_eff($month['cost_brutto'], $month['consumed_kwh']), kpi_eff($monthPrev['cost_brutto'], $monthPrev['consumed_kwh']))) ?><?= $monthSpark ?></span></div>
            </div>
        </a>

    </div>
</main>
<?php render_footer(); ?>
