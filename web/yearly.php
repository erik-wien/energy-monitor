<?php
require_once __DIR__ . '/../inc/db.php';
auth_require();

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? (int)date('n'));
if ($month < 1)  { $month = 12; $year--; }
if ($month > 12) { $month = 1;  $year++; }

$end_ymd   = sprintf('%04d-%02d-01', $year, $month);
$start_ymd = date('Y-m-01', strtotime('-12 months', strtotime($end_ymd)));

// KPIs
$stmt = $pdo->prepare(
    "SELECT SUM(consumed_kwh) AS kwh, SUM(cost_brutto) AS eur, AVG(avg_spot_ct) AS ct
     FROM daily_summary
     WHERE day >= ? AND day < DATE_ADD(?, INTERVAL 1 MONTH)"
);
$stmt->execute([$start_ymd, $end_ymd]);
$kpi = $stmt->fetch();
$kpi_kwh = (float)($kpi['kwh'] ?? 0);
$kpi_eur = (float)($kpi['eur'] ?? 0);
$kpi_ct  = (float)($kpi['ct']  ?? 0);

// Navigation
$prev_month = $month - 1; $prev_year = $year;
if ($prev_month < 1)  { $prev_month = 12; $prev_year--; }
$next_month = $month + 1; $next_year = $year;
if ($next_month > 12) { $next_month = 1;  $next_year++; }

$now_year  = (int)date('Y');
$now_month = (int)date('n');
$is_future = $next_year > $now_year || ($next_year === $now_year && $next_month > $now_month);

$month_names = ['Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'];
$start_label = $month_names[((int)date('n', strtotime($start_ymd))) - 1] . ' ' . date('Y', strtotime($start_ymd));
$end_label   = $month_names[$month - 1] . ' ' . $year;
$period_label = $start_label . ' – ' . $end_label;

$prev_url = "$base/yearly.php?year=$prev_year&month=$prev_month";
$next_url = $is_future ? null : "$base/yearly.php?year=$next_year&month=$next_month";
$next_label = $month_names[$next_month - 1] . ' ' . $next_year;
$prev_label = $month_names[$prev_month - 1] . ' ' . $prev_year;

$api_url    = "$base/api.php?type=yearly&year=$year&month=$month";
$page_type  = 'yearly';

function fmt_kwh($v) { return number_format($v, 1, ',', '.') . ' kWh'; }
function fmt_eur($v) { return '€ ' . number_format($v, 2, ',', '.'); }
function fmt_ct($v)  { return number_format($v, 2, ',', '.') . ' ct/kWh'; }
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($period_label) ?> · Energie</title>
    <link rel="stylesheet" href="<?= $base ?>/styles/shared/theme.css">
    <link rel="stylesheet" href="<?= $base ?>/styles/shared/reset.css">
    <link rel="stylesheet" href="<?= $base ?>/styles/energie-theme.css">
    <link rel="stylesheet" href="<?= $base ?>/styles/energie.css">
    <link rel="icon" type="image/x-icon" href="<?= $base ?>/img/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $base ?>/img/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= $base ?>/img/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= $base ?>/img/apple-touch-icon.png">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js" nonce="<?= $_cspNonce ?>"></script>
</head>
<body>
<?php require __DIR__ . '/../inc/_header.php'; ?>
<main>
    <div class="nav-bar">
        <a href="<?= htmlspecialchars($prev_url) ?>">← <?= htmlspecialchars($prev_label) ?></a>
        <div class="period-nav">
            <span class="period-label"><?= htmlspecialchars($period_label) ?></span>
            <button type="button" id="print-btn" class="print-btn" title="Aufstellung drucken">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                    <rect x="6" y="14" width="12" height="8"/>
                </svg>
            </button>
        </div>
        <?php if ($next_url): ?>
            <a href="<?= htmlspecialchars($next_url) ?>"><?= htmlspecialchars($next_label) ?> →</a>
        <?php else: ?>
            <span style="color:var(--color-border)"><?= htmlspecialchars($next_label) ?> →</span>
        <?php endif; ?>
    </div>

    <div class="kpi-strip">
        <div class="kpi-card">
            <div class="label">Verbrauch</div>
            <div class="value kwh"><?= fmt_kwh($kpi_kwh) ?></div>
        </div>
        <div class="kpi-card">
            <div class="label">Kosten</div>
            <div class="value eur"><?= fmt_eur($kpi_eur) ?></div>
        </div>
        <div class="kpi-card">
            <div class="label">Ø Tarif</div>
            <div class="value tariff"><?= fmt_ct($kpi_ct) ?></div>
        </div>
    </div>
    <div class="chart-controls" style="margin-top:0.75rem">
        <button type="button" class="chart-pill chart-pill--cost"    data-key="cost">Kosten</button>
        <button type="button" class="chart-pill chart-pill--kwh"     data-key="kwh">Verbrauch</button>
        <button type="button" class="chart-pill chart-pill--hkwh"    data-key="hkwh">hist. Verbr.</button>
        <button type="button" class="chart-pill chart-pill--hkband"  data-key="hkband">hist. Verbr. Band</button>
        <button type="button" class="chart-pill chart-pill--tariff"  data-key="tariff">Tarif</button>
        <button type="button" class="chart-pill chart-pill--htariff" data-key="htariff">hist. Tarif</button>
        <button type="button" class="chart-pill chart-pill--htband"  data-key="htband">hist. Tarif Band</button>
    </div>
    <div class="chart-container" style="margin-top:1rem">
        <canvas id="chart"></canvas>
    </div>
</main>

<script nonce="<?= $_cspNonce ?>">
const periodLabel = <?= json_encode($period_label) ?>;

let _printHTML = null;
let _blobUrl   = null;

fetch(<?= json_encode($api_url) ?>)
  .then(r => r.json())
  .then(data => {
    const DE_MO = ['Jan','Feb','M\u00e4r','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'];
    const ctx   = document.getElementById('chart').getContext('2d');

    window._energieChart = new Chart(ctx, {
      data: {
        labels: data.labels,
        datasets: [
          {
            type: 'line', label: 'Kosten (€)', data: data.cost,
            borderColor: '#e94560', backgroundColor: 'rgba(233,69,96,0.08)',
            borderWidth: 2, pointRadius: 3, tension: 0.3, yAxisID: 'y', order: 2,
          },
          {
            type: 'line', label: 'Verbrauch (kWh)', data: data.consumption,
            borderColor: '#68d391', backgroundColor: 'rgba(104,211,145,0.1)',
            borderWidth: 2, pointRadius: 3, tension: 0.3, yAxisID: 'y2', order: 1,
          },
          {
            type: 'line', label: 'Tarif (ct/kWh)', data: data.tariff,
            borderColor: '#63b3ed', backgroundColor: 'rgba(99,179,237,0.1)',
            borderWidth: 2, pointRadius: 3, tension: 0.3, yAxisID: 'y3', order: 0,
          },
          {
            type: 'line', label: '_htariff_max', data: data.hist_tariff_max,
            borderColor: 'transparent', backgroundColor: 'rgba(49,130,206,0.13)',
            pointRadius: 0, fill: '+1', tension: 0.3, yAxisID: 'y3', order: 9,
          },
          {
            type: 'line', label: '_htariff_min', data: data.hist_tariff_min,
            borderColor: 'transparent', backgroundColor: 'transparent',
            pointRadius: 0, fill: false, tension: 0.3, yAxisID: 'y3', order: 8,
          },
          {
            type: 'line', label: 'Ø Tarif (ct/kWh)', data: data.hist_tariff_avg,
            borderColor: '#3182ce', backgroundColor: 'rgba(49,130,206,0.08)',
            borderWidth: 1.5, pointRadius: 3, tension: 0.3, yAxisID: 'y3', order: 3,
            borderDash: [5, 3],
          },
          {
            type: 'line', label: '_hkwh_max', data: data.hist_kwh_max,
            borderColor: 'transparent', backgroundColor: 'rgba(56,161,105,0.13)',
            pointRadius: 0, fill: '+1', tension: 0.3, yAxisID: 'y2', order: 11,
          },
          {
            type: 'line', label: '_hkwh_min', data: data.hist_kwh_min,
            borderColor: 'transparent', backgroundColor: 'transparent',
            pointRadius: 0, fill: false, tension: 0.3, yAxisID: 'y2', order: 10,
          },
          {
            type: 'line', label: 'Ø Verbrauch (kWh)', data: data.hist_kwh_avg,
            borderColor: '#38a169', backgroundColor: 'rgba(56,161,105,0.08)',
            borderWidth: 1.5, pointRadius: 3, tension: 0.3, yAxisID: 'y2', order: 4,
            borderDash: [5, 3],
          },
        ]
      },
      options: {
        animation: false,
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: {
            onClick: () => {},
            labels: {
              color: '#e2e8f0',
              usePointStyle: true,
              pointStyle: 'rectRounded',
              filter: item => !item.text.startsWith('_'),
            }
          },
          tooltip: {
            backgroundColor: '#16213e', borderColor: '#2d3748', borderWidth: 1,
            filter: item => !item.dataset.label.startsWith('_'),
          }
        },
        scales: {
          x:  { ticks: { color: '#718096' }, grid: { color: '#2d3748' } },
          y:  { ticks: { color: '#fc8181' }, grid: { color: '#2d3748' }, position: 'left',
                title: { display: true, text: 'Kosten (€)', color: '#fc8181' } },
          y2: { ticks: { color: '#68d391' }, grid: { display: false }, position: 'right',
                title: { display: true, text: 'Verbrauch (kWh)', color: '#68d391' } },
          y3: { ticks: { color: '#63b3ed' }, grid: { display: false }, position: 'right',
                title: { display: true, text: 'Tarif (ct/kWh)', color: '#63b3ed' } },
        }
      }
    });

    const _ch = window._energieChart;
    _ch._yMin = _ch.scales.y?.min;   _ch._yMax = _ch.scales.y?.max;
    _ch._y2Min = _ch.scales.y2?.min; _ch._y2Max = _ch.scales.y2?.max;
    _ch._y3Min = _ch.scales.y3?.min; _ch._y3Max = _ch.scales.y3?.max;
    if (window._applyChartVis) window._applyChartVis(window._energieChart);

    _printHTML = buildPrintContent(data, DE_MO);
  });

function buildPrintContent(data, DE_MO) {
  const pad2  = n => String(n).padStart(2, '0');
  const fmtN   = (v, dp) => (v < 0 ? '\u2212' : '') + '\u20ac\u00a0' + Math.abs(v).toFixed(dp).replace('.', ',');
  const fmt2   = v => fmtN(v, 2);
  const fmtKwh = v => Math.abs(v).toFixed(2).replace('.', ',') + '\u00a0kWh';
  const fmtCt  = v => Math.abs(v).toFixed(2).replace('.', ',') + '\u00a0ct/kWh';
  const fmtDE = iso => iso.slice(8,10) + '.' + iso.slice(5,7) + '.' + iso.slice(0,4);
  const esc   = s => s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

  const now    = new Date();
  const stand  = pad2(now.getDate()) + '.' + pad2(now.getMonth()+1) + '.' + now.getFullYear()
               + ' ' + pad2(now.getHours()) + ':' + pad2(now.getMinutes());
  const period = esc(periodLabel) + ' (' + fmtDE(data.period_start) + ' \u2013 ' + fmtDE(data.period_end) + ')';

  let sumKwh = 0, sumEpxW = 0, sumAuf = 0, sumAbg = 0, sumGba = 0, sumMwst = 0, sumGes = 0;
  const bodyParts = [];

  data.months.forEach((mo, i) => {
    const [y, m] = mo.split('-');
    const lbl  = DE_MO[parseInt(m, 10) - 1] + ' ' + y;
    const kwh  = data.consumption?.[i]     ?? 0;
    const epx  = data.epex?.[i]            ?? 0;
    const auf  = data.aufschlag?.[i]       ?? 0;
    const abg  = data.abgaben?.[i]         ?? 0;
    const gba  = data.gebrauchsabgabe?.[i] ?? 0;
    const mwst = data.mwst_tax?.[i]        ?? 0;
    const ges  = data.gesamt_variable?.[i] ?? data.cost[i] ?? 0;
    sumKwh += kwh; sumEpxW += kwh * epx; sumAuf += auf; sumAbg += abg; sumGba += gba; sumMwst += mwst; sumGes += ges;
    bodyParts.push(
      '<tr>'
      + '<td>' + esc(lbl) + '</td>'
      + '<td>' + fmtKwh(kwh) + '</td>'
      + '<td>' + fmtCt(epx)  + '</td>'
      + '<td>' + fmt2(auf)  + '</td>'
      + '<td>' + fmt2(abg)  + '</td>'
      + '<td>' + fmt2(gba)  + '</td>'
      + '<td>' + fmt2(mwst) + '</td>'
      + '<td' + (ges < 0 ? ' class="neg"' : '') + '>' + fmt2(ges) + '</td>'
      + '</tr>'
    );
  });

  const mfp   = data.meter_fee_prop    ?? 0;
  const rfp   = data.renewable_fee_prop ?? 0;
  const grand = sumGes + mfp + rfp;
  const blank  = '<td></td><td></td><td></td><td></td><td></td><td></td><td></td>';

  const footParts = [
    '<tr class="sub">'
      + '<td class="lbl-sub">Zwischensumme</td>'
      + '<td>' + fmtKwh(sumKwh) + '</td>'
      + '<td>' + fmtCt(sumKwh > 0 ? sumEpxW / sumKwh : 0) + '</td>'
      + '<td>' + fmt2(sumAuf)  + '</td>'
      + '<td>' + fmt2(sumAbg)  + '</td>'
      + '<td>' + fmt2(sumGba)  + '</td>'
      + '<td>' + fmt2(sumMwst) + '</td>'
      + '<td' + (sumGes < 0 ? ' class="neg"' : '') + '>' + fmt2(sumGes) + '</td>'
      + '</tr>',
  ];
  if (mfp > 0.00005) footParts.push(
    '<tr><td class="lbl-fee">+ Z\u00e4hlergebühr (ant.)</td>'
    + blank.replace(/(<td><\/td>)$/, '<td>' + fmt2(mfp) + '</td>') + '</tr>'
  );
  if (rfp > 0.00005) footParts.push(
    '<tr><td class="lbl-fee">+ Erneuerbaren-Abgabe (ant.)</td>'
    + blank.replace(/(<td><\/td>)$/, '<td>' + fmt2(rfp) + '</td>') + '</tr>'
  );
  footParts.push(
    '<tr class="grand">'
      + '<th>Gesamtsumme</th><th></th><th></th><th></th><th></th><th></th><th></th>'
      + '<th' + (grand < 0 ? ' class="neg"' : '') + '>' + fmt2(grand) + '</th>'
      + '</tr>'
  );

  return '<!DOCTYPE html>'
    + '<html lang="de"><head><meta charset="UTF-8">'
    + '<title>Aufstellung \u2013 ' + esc(periodLabel) + '</title>'
    + '<style>'
    + '* { box-sizing: border-box; margin: 0; padding: 0; }'
    + 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; font-size: 0.8rem; color: #1a202c; background: #fff; padding: 1.5rem; }'
    + '.meta { margin-bottom: 1rem; line-height: 1.8; }'
    + '.meta .period { font-weight: 600; font-size: 0.85rem; }'
    + '.print-bar { margin-bottom: 1rem; }'
    + '.print-bar button { font-size: 0.8rem; padding: 0.3rem 0.8rem; cursor: pointer; }'
    + 'table { width: 100%; border-collapse: collapse; font-size: 0.78rem; }'
    + 'th, td { padding: 0.3rem 0.6rem; text-align: right; border-bottom: 1px solid #e2e8f0; }'
    + 'th:first-child, td:first-child { text-align: left; }'
    + 'thead th { background: #f7fafc; color: #4a5568; font-weight: 600; border-bottom: 2px solid #cbd5e0; }'
    + 'tfoot tr.sub td { border-top: 2px solid #cbd5e0; color: #4a5568; }'
    + 'tfoot tr.grand th { border-top: 2px solid #1a202c; font-weight: 700; }'
    + '.lbl-fee { color: #718096; padding-left: 1.5rem; font-size: 0.75rem; }'
    + '.lbl-sub { color: #718096; font-style: italic; }'
    + '.neg { color: #276749; }'
    + '@media print { .print-bar { display: none; } }'
    + '</style></head><body>'
    + '<div class="print-bar"><button id="prt">\uD83D\uDDA8\uFE0F Drucken</button></div>'
    + '<div class="meta">'
    + '<div>B\u00f6ckhgasse 9/6/74 &middot; 1120 Wien</div>'
    + '<div>Stromanbieter: Hofer Gr\u00FCnstrom &middot; Netzbetreiber: Wien Strom</div>'
    + '<div>Stand vom ' + stand + '</div>'
    + '<div class="period">Abrechnung f\u00FCr ' + period + '</div>'
    + '</div>'
    + '<table>'
    + '<thead><tr>'
    + '<th>Monat</th>'
    + '<th>Verbrauch</th><th>EPEX</th><th>Aufschlag</th><th>Abgaben</th><th>Steuern</th><th>MwSt</th><th>Gesamt</th>'
    + '</tr></thead>'
    + '<tbody>' + bodyParts.join('') + '</tbody>'
    + '<tfoot>' + footParts.join('') + '</tfoot>'
    + '</table>'
    + '</body></html>';
}

// Print button — opens popup via Blob URL, auto-prints from opener to avoid CSP inline-handler issues
document.getElementById('print-btn').addEventListener('click', () => {
  if (!_printHTML) { alert('Daten werden noch geladen\u2026'); return; }
  if (_blobUrl) URL.revokeObjectURL(_blobUrl);
  _blobUrl = URL.createObjectURL(new Blob([_printHTML], { type: 'text/html; charset=utf-8' }));
  const win = window.open(_blobUrl, '_blank', 'width=900,height=700,menubar=no,toolbar=no,location=no,status=no');
  if (win) win.addEventListener('load', () => { win.focus(); win.print(); });
});

// Dataset visibility controls
(function() {
  const storageKey = 'energie-vis-yearly';
  const defaults   = { cost: true, kwh: true, tariff: true, htariff: true, htband: true, hkwh: true, hkband: true };
  let vis = Object.assign({}, defaults, JSON.parse(localStorage.getItem(storageKey) || '{}'));

  document.querySelectorAll('.chart-controls .chart-pill[data-key]').forEach(btn => {
    btn.classList.toggle('active', vis[btn.dataset.key] !== false);
  });

  function applyVis(chart) {
    chart.data.datasets.forEach((ds, i) => {
      const meta = chart.getDatasetMeta(i);
      if      (ds.label === 'Kosten (€)')         meta.hidden = !vis.cost;
      else if (ds.label === 'Verbrauch (kWh)')    meta.hidden = !vis.kwh;
      else if (ds.label === 'Tarif (ct/kWh)')     meta.hidden = !vis.tariff;
      else if (ds.label === 'Ø Tarif (ct/kWh)')   meta.hidden = !vis.htariff;
      else if (ds.label.startsWith('_htariff'))    meta.hidden = !vis.htband;
      else if (ds.label === 'Ø Verbrauch (kWh)')  meta.hidden = !vis.hkwh;
      else if (ds.label.startsWith('_hkwh'))       meta.hidden = !vis.hkband;
    });
    chart.options.scales.y.display  = vis.cost;
    chart.options.scales.y2.display = vis.kwh  || vis.hkwh;
    chart.options.scales.y3.display = vis.tariff || vis.htariff;
    if (chart._yMin  != null) chart.options.scales.y.min   = chart._yMin;
    if (chart._yMax  != null) chart.options.scales.y.max   = chart._yMax;
    if (chart._y2Min != null) chart.options.scales.y2.min  = chart._y2Min;
    if (chart._y2Max != null) chart.options.scales.y2.max  = chart._y2Max;
    if (chart._y3Min != null) chart.options.scales.y3.min  = chart._y3Min;
    if (chart._y3Max != null) chart.options.scales.y3.max  = chart._y3Max;
    chart.update('none');
  }
  window._applyChartVis = applyVis;

  document.querySelectorAll('.chart-controls .chart-pill[data-key]').forEach(btn => {
    btn.addEventListener('click', () => {
      const key = btn.dataset.key;
      vis[key] = !vis[key];
      btn.classList.toggle('active', vis[key]);
      localStorage.setItem(storageKey, JSON.stringify(vis));
      if (window._energieChart) applyVis(window._energieChart);
    });
  });
})();
</script>
</body>
</html>
