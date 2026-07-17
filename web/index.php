<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/insight.php';
require_once __DIR__ . '/../inc/wetter.php';
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
function fmt_ct_plain($v) { return number_format($v, 1, ',', '.') . ' ct/kWh'; }
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

// ── Wetterbericht (Dashboard-Karte, Spec §4) ────────────────────────────────
// Liest NUR den Cache (nie Haiku synchron beim Seitenaufbau, §20); die eigentliche
// Regeneration läuft off-path über inc/wetter.php::en_wetter_regenerieren()
// (Import-Hook + api.php?type=wetter-refresh).
$w  = en_wetter_lesen($pdo);
$wf = $w['fakten'];

// Budget-Gate (TASK-6, max. 2 Haiku-Bestellungen/Tag): Off-Path-Regeneration
// nur anstoßen, wenn der Cache aus einem ANDEREN 14:00-Slot stammt als jetzt
// (nicht mehr "Cache-Datum != heute" — die fakten-Tagesanker der Datenlage
// ist nie "heute", das feuerte bislang bei praktisch jedem Seitenaufbau).
$wetterBrauchtRefresh = ($w['slot'] ?? '') !== en_wetter_slot(new DateTimeImmutable());

/**
 * Wetterglyph aus dem Preis-Symbol (Spec §5, v2-Fakten `preis.symbol`):
 * 'sonne' -> ui-icon-sun, 'wolke' -> ui-icon-cloud, 'gewitter' ->
 * ui-icon-cloud-lightning. Bevorzugt das top-level Cache-Feld `$w['symbol']`
 * (Task 6, ohne Neurechnung); fällt sonst auf `$wf['preis']['symbol']` zurück,
 * bei Alt-Cache (fehlt beides) auf 'wolke'.
 */
function en_wetter_glyph(?string $symbol): string {
    return match ($symbol) {
        'sonne'    => 'sun',
        'gewitter' => 'cloud-lightning',
        default    => 'cloud', // 'wolke' oder fehlend/unbekannt
    };
}
$wetterSymbol = $w['symbol'] ?? $wf['preis']['symbol'] ?? null;
$wetterGlyph  = en_wetter_glyph($wetterSymbol);

/** Fakten-Chip: Label + entweder ein Delta (reuses en_chip()) oder Klartext, klickbar. */
function en_wetter_chip(string $label, string $href, ?array $delta = null, string $klartext = ''): string {
    $body = htmlspecialchars($label);
    if ($delta !== null && $delta['pct'] !== null) {
        $body .= ' ' . en_chip($delta);
    } elseif ($klartext !== '') {
        $body .= ' · ' . htmlspecialchars($klartext);
    }
    return '<a class="wetter-chip" href="' . htmlspecialchars($href) . '">' . $body . '</a>';
}

$wetterMonthlyHref = $base . '/monthly.php?year=' . $prev_year . '&month=' . $prev_month;

$wetterChips = [];

// Optionaler Chip (Spec §5): Verbrauch ggü. Vorjahr, nur wenn die v2-Fakten
// einen Vorjahreswert liefern konnten (w7_yoy_pct).
$yoy = $wf['verbrauch']['w7_yoy_pct'] ?? null;
if ($yoy !== null) {
    $yDir = abs($yoy) < 0.005 ? 'flat' : ($yoy > 0 ? 'up' : 'down');
    $wetterChips[] = en_wetter_chip('Verbrauch ggü. Vorjahr', $wetterMonthlyHref, ['pct' => $yoy * 100, 'dir' => $yDir]);
}

// Im Vorschau-Slot (#nach, morgen) trägt das Preisprofil Morgen-Zahlen — Chip
// entsprechend beschriften UND aus dem Morgen-Profil speisen (sonst zeigten
// die Preis-Chips im Vorschau-Slot fälschlich Heute-Zahlen unter "morgen"-Label).
$wetterTagWort = ($wf['vorschau'] ?? false) ? 'morgen' : 'heute';
$profil = ($wf['vorschau'] ?? false)
    ? ($wf['preis']['morgen'] ?? $wf['preis']['heute'] ?? null)
    : ($wf['preis']['heute'] ?? null);

// Chip-Link zeigt auf den tatsächlichen Profil-Tag (heute bzw. im
// Vorschau-Fall morgen), nicht auf $w['datum'] (= gestern).
$wetterDailyHref = $base . '/daily.php?date=' . htmlspecialchars($profil['datum'] ?? $w['datum']);

$hAvg = $profil['avg'] ?? null;
if ($hAvg !== null) {
    $wetterChips[] = en_wetter_chip($wetterTagWort . ' Ø', $wetterDailyHref, null, number_format($hAvg, 1, ',', '.') . ' ct');
}

$hMax = $profil['max'] ?? null;
$hMaxH = $profil['max_h'] ?? null;
if ($hMax !== null && $hMaxH !== null) {
    $wetterChips[] = en_wetter_chip('Spitze ' . $hMaxH . ' h', $wetterDailyHref, null, number_format($hMax, 1, ',', '.') . ' ct');
}

$gVon = $profil['guenstig_von'] ?? null;
$gBis = $profil['guenstig_bis'] ?? null;
$gAvg = $profil['guenstig_avg'] ?? null;
if ($gVon !== null && $gBis !== null && $gAvg !== null) {
    $wetterChips[] = en_wetter_chip('günstig ' . $gVon . '–' . $gBis . ' h', $wetterDailyHref, null, number_format($gAvg, 1, ',', '.') . ' ct');
}
?>
<?php render_page_head('Energie'); render_header('index'); ?>
<main id="main-content" tabindex="-1">

    <section class="preis-hero" aria-label="Preiszusammensetzung">
        <h2 class="preis-hero-title">Dein Strompreis je kWh · letzte 30 Tage</h2>
        <div class="preis-bar-track">
            <div class="preis-bar">
                <div class="seg-spot"      data-label="Börse"           data-ct="<?= htmlspecialchars(fmt_ct_plain($komp['spot'])) ?>"      style="flex-basis:<?= sprintf('%.3f', $komp_pct($komp['spot'])) ?>%"></div>
                <div class="seg-aufschlag" data-label="Aufschlag"       data-ct="<?= htmlspecialchars(fmt_ct_plain($komp['aufschlag'])) ?>" style="flex-basis:<?= sprintf('%.3f', $komp_pct($komp['aufschlag'])) ?>%"></div>
                <div class="seg-abgaben"   data-label="Abgaben"         data-ct="<?= htmlspecialchars(fmt_ct_plain($komp['abgaben'])) ?>"   style="flex-basis:<?= sprintf('%.3f', $komp_pct($komp['abgaben'])) ?>%"></div>
                <div class="seg-gba"       data-label="Gebrauchsabgabe" data-ct="<?= htmlspecialchars(fmt_ct_plain($komp['gba'])) ?>"       style="flex-basis:<?= sprintf('%.3f', $komp_pct($komp['gba'])) ?>%"></div>
                <div class="seg-mwst"      data-label="MwSt"            data-ct="<?= htmlspecialchars(fmt_ct_plain($komp['mwst'])) ?>"      style="flex-basis:<?= sprintf('%.3f', $komp_pct($komp['mwst'])) ?>%"></div>
                <div class="seg-fixkosten" data-label="Fixkosten"       data-ct="<?= htmlspecialchars(fmt_ct_plain($komp['fixkosten'])) ?>" style="flex-basis:<?= sprintf('%.3f', $komp_pct($komp['fixkosten'])) ?>%"></div>
            </div>
            <div class="seg-tooltip" id="seg-tooltip" role="tooltip" hidden></div>
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
    </section>
    <script nonce="<?= $_cspNonce ?>">
    (function() {
        var track = document.querySelector('.preis-bar-track');
        var tip   = document.getElementById('seg-tooltip');
        if (!track || !tip) return;
        var open = null;

        function close() { tip.hidden = true; open = null; }

        function show(seg) {
            tip.innerHTML = '<span class="seg-tooltip-label"></span><span class="seg-tooltip-value"></span>';
            tip.querySelector('.seg-tooltip-label').textContent = seg.getAttribute('data-label') || '';
            tip.querySelector('.seg-tooltip-value').textContent = seg.getAttribute('data-ct') || '';
            tip.hidden = false;
            var trackRect = track.getBoundingClientRect();
            var segRect   = seg.getBoundingClientRect();
            tip.style.left      = (segRect.left - trackRect.left + segRect.width / 2) + 'px';
            tip.style.top       = (segRect.bottom - trackRect.top + 8) + 'px';
            tip.style.transform = 'translateX(-50%)';
            open = seg;
        }

        track.querySelectorAll('[data-label]').forEach(function(seg) {
            seg.addEventListener('pointerdown', function(e) {
                e.stopPropagation();
                if (open === seg) { close(); return; }
                show(seg);
            });
        });

        // Rule §8: outside-close on pointerdown (capture), never click — excludes
        // interactions whose target lies within the track itself.
        document.addEventListener('pointerdown', function(e) {
            if (open && !track.contains(e.target)) close();
        }, true);
    })();
    </script>

    <section class="wetterbericht" aria-label="Wetterbericht">
        <span class="ui-icon ui-icon-<?= htmlspecialchars($wetterGlyph) ?> wetterbericht-glyph" aria-hidden="true"></span>
        <div class="wetterbericht-body">
            <p class="wetterbericht-text"><?= htmlspecialchars($w['text']) ?></p>
            <?php if ($wetterChips): ?>
            <ul class="wetter-chips">
                <?php foreach ($wetterChips as $wetterChip): ?>
                <li><?= $wetterChip ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <div class="wetterbericht-meta">
                aktualisiert <?= htmlspecialchars((new DateTime($w['erzeugt_at']))->format('H:i')) ?> · <?= htmlspecialchars($w['quelle']) ?>
            </div>
        </div>
    </section>
    <?php if ($wetterBrauchtRefresh): ?>
    <script nonce="<?= $_cspNonce ?>">
    (function() {
        // Cache stammt aus einem anderen 14:00-Slot als jetzt: einmal Off-Path-
        // Regeneration anstoßen (§20 — kein await, blockiert die UI nicht;
        // Ergebnis egal, nächster Load zeigt den frischen Bericht). Garantiert
        // max. 2 Haiku-Bestellungen/Tag (TASK-6) — dazwischen liefert PHP oben
        // bereits denselben Slot -> dieser Block wird gar nicht erst gerendert.
        fetch(<?= json_encode($base) ?> + '/api.php?type=wetter-refresh', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'csrf_token=' + encodeURIComponent(<?= json_encode(csrf_token()) ?>)
        }).catch(function() {});
    })();
    </script>
    <?php endif; ?>

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
