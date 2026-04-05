<?php
// Expected vars from including file:
// $title        string       Page <title>
// $period_label string       e.g. "Di 01.04.2026" or "KW14 · 30.03–05.04.2026"
// $prev_url     string       URL for ← link
// $prev_label   string       Label for ← link e.g. "Mo 30.03.2026" / "KW13"
// $next_url     string|null  URL for → link (null if no future data)
// $next_label   string       Label for → link
// $api_url      string       URL passed to Chart.js fetch
// $kpi_kwh      float
// $kpi_eur      float
// $kpi_ct       float

// Header nav targets — always point to *today's* day / week / month
$_nav_today       = date('Y-m-d');
$_nav_week_year   = (int)date('o');   // ISO year (may differ from calendar year in Jan)
$_nav_week_num    = (int)date('W');   // ISO week number
$_nav_month_year  = (int)date('Y');
$_nav_month_month = (int)date('n');

function fmt_kwh($v) { return number_format($v, 1, ',', '.') . ' kWh'; }
function fmt_eur($v) { return '€ ' . number_format($v, 2, ',', '.'); }
function fmt_ct($v)  { return number_format($v, 2, ',', '.') . ' ct/kWh'; }
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title) ?> · Energie</title>
    <link rel="stylesheet" href="<?= $base ?>/styles/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
</head>
<body>
<header>
    <span style="display:flex;align-items:center;gap:0.75rem">
        <span>⚡</span>
        <h1><a href="<?= $base ?>/" style="color:inherit;text-decoration:none">Energie</a></h1>
    </span>
    <nav class="header-nav">
        <a href="<?= $base ?>/daily.php?date=<?= $_nav_today ?>"
           <?= $page_type === 'daily'   ? 'class="active"' : '' ?>>Heute</a>
        <a href="<?= $base ?>/weekly.php?year=<?= $_nav_week_year ?>&amp;week=<?= $_nav_week_num ?>"
           <?= $page_type === 'weekly'  ? 'class="active"' : '' ?>>Woche</a>
        <a href="<?= $base ?>/monthly.php?year=<?= $_nav_month_year ?>&amp;month=<?= $_nav_month_month ?>"
           <?= $page_type === 'monthly' ? 'class="active"' : '' ?>>Monat</a>
    </nav>
</header>
<main>
    <div class="nav-bar">
        <a href="<?= htmlspecialchars($prev_url) ?>">← <?= htmlspecialchars($prev_label) ?></a>
        <div class="period-nav">
            <span class="period-label"><?= htmlspecialchars($period_label) ?></span>
            <input type="date" id="date-picker" class="date-input-inline"
                   value="<?= htmlspecialchars($current_date_iso) ?>">
        </div>
        <?php if ($next_url): ?>
            <a href="<?= htmlspecialchars($next_url) ?>"><?= htmlspecialchars($next_label) ?> →</a>
        <?php else: ?>
            <span style="color:var(--border)"><?= htmlspecialchars($next_label) ?> →</span>
        <?php endif; ?>
    </div>

    <div class="tab-bar">
        <button class="tab-btn" data-tab="grafik">Grafik</button>
        <button class="tab-btn" data-tab="aufstellung">Aufstellung</button>
    </div>

    <div class="tab-panel" data-tab="grafik">
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
        <div class="chart-container">
            <canvas id="chart"></canvas>
        </div>
    </div>

    <div class="tab-panel" data-tab="aufstellung">
        <div class="invoice">
            <div class="invoice-hdr">Hochgerechnete Kosten · <?= htmlspecialchars($period_label) ?></div>
            <table class="invoice-table">
                <thead id="invoice-head"></thead>
                <tbody id="invoice-body"></tbody>
                <tfoot id="invoice-foot"></tfoot>
            </table>
        </div>
    </div>
</main>

<script>
const isDailyPage = <?= json_encode($page_type === 'daily') ?>;

fetch(<?= json_encode($api_url) ?>)
  .then(r => r.json())
  .then(data => {
    const ctx = document.getElementById('chart').getContext('2d');
    new Chart(ctx, {
      data: {
        labels: data.labels,
        datasets: [
          {
            type: 'line',
            label: 'Kosten (€)',
            data: data.cost,
            borderColor: '#e94560',
            backgroundColor: 'rgba(233,69,96,0.08)',
            borderWidth: 2,
            pointRadius: data.labels.length > 50 ? 0 : 3,
            tension: 0.3,
            yAxisID: 'y',
            order: 2,
          },
          {
            type: 'line',
            label: 'Verbrauch (kWh)',
            data: data.consumption,
            borderColor: '#68d391',
            backgroundColor: 'rgba(104,211,145,0.1)',
            borderWidth: 2,
            pointRadius: data.labels.length > 50 ? 0 : 3,
            tension: 0.3,
            yAxisID: 'y2',
            order: 1,
          },
          {
            type: 'line',
            label: 'Tarif (ct/kWh)',
            data: data.tariff,
            borderColor: '#63b3ed',
            backgroundColor: 'rgba(99,179,237,0.1)',
            borderWidth: 2,
            pointRadius: data.labels.length > 50 ? 0 : 3,
            tension: 0.3,
            yAxisID: 'y3',
            order: 0,
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { labels: { color: '#e2e8f0' } },
          tooltip: { backgroundColor: '#16213e', borderColor: '#2d3748', borderWidth: 1 }
        },
        scales: {
          x:  { ticks: { color: '#718096', maxTicksLimit: 24,
                  callback: (val, i, ticks) => {
                    const lbl = ticks[i]?.label ?? '';
                    return isDailyPage ? lbl.substring(0, 2) : lbl;
                  }
                }, grid: { color: '#2d3748' } },
          y:  { ticks: { color: '#fc8181' }, grid: { color: '#2d3748' }, position: 'left',
                title: { display: true, text: 'Kosten (€)', color: '#fc8181' },
                min: isDailyPage ? -0.20 : undefined,
                max: isDailyPage ?  0.50 : data.maxCost },
          y2: { ticks: { color: '#68d391' }, grid: { display: false }, position: 'right',
                title: { display: true, text: 'Verbrauch (kWh)', color: '#68d391' },
                max: data.maxKwh },
          y3: { ticks: { color: '#63b3ed' }, grid: { display: false }, position: 'right',
                title: { display: true, text: 'Tarif (ct/kWh)', color: '#63b3ed' },
                min: isDailyPage ? -25 : undefined,
                max: isDailyPage ?  35  : undefined }
        }
      }
    });

    // Invoice table
    const DE_DAYS = ['So','Mo','Di','Mi','Do','Fr','Sa'];
    const fmtKwh = v => v.toFixed(2).replace('.', ',') + ' kWh';
    const fmtEur = v => (v < 0 ? '\u2212\u20ac\u00a0' : '\u20ac\u00a0') + Math.abs(v).toFixed(3).replace('.', ',');
    const fmtCt  = v => v.toFixed(2).replace('.', ',') + ' ct';

    function makeRow(tag, cells) {
      const tr = document.createElement('tr');
      cells.forEach(({text, cls}) => {
        const td = document.createElement(tag);
        td.textContent = text;
        if (cls) td.className = cls;
        tr.appendChild(td);
      });
      return tr;
    }

    const invoiceHead = document.getElementById('invoice-head');
    const invoiceBody = document.getElementById('invoice-body');
    const invoiceFoot = document.getElementById('invoice-foot');

    if (isDailyPage) {
      invoiceHead.appendChild(makeRow('th', [
        {text:'Zeit'}, {text:'Verbrauch'}, {text:'Tarif'}, {text:'Kosten'}
      ]));
      let sumKwh = 0, sumEur = 0;
      data.labels.forEach((lbl, i) => {
        sumKwh += data.consumption[i];
        sumEur += data.cost[i];
        invoiceBody.appendChild(makeRow('td', [
          {text: lbl.substring(0, 5)},
          {text: fmtKwh(data.consumption[i])},
          {text: fmtCt(data.tariff[i])},
          {text: fmtEur(data.cost[i]), cls: data.cost[i] < 0 ? 'neg' : ''},
        ]));
      });
      invoiceFoot.appendChild(makeRow('th', [
        {text:'Gesamt'}, {text: fmtKwh(sumKwh)}, {text:''},
        {text: fmtEur(sumEur), cls: sumEur < 0 ? 'neg' : ''},
      ]));
    } else {
      invoiceHead.appendChild(makeRow('th', [
        {text:'Tag'}, {text:'Verbrauch'}, {text:'Kosten'}
      ]));
      let sumKwh = 0, sumEur = 0;
      data.dates.forEach((iso, i) => {
        sumKwh += data.consumption[i];
        sumEur += data.cost[i];
        const d = new Date(iso + 'T00:00:00');
        const dayLabel = DE_DAYS[d.getDay()] + ' ' + iso.slice(8,10) + '.' + iso.slice(5,7) + '.';
        invoiceBody.appendChild(makeRow('td', [
          {text: dayLabel},
          {text: fmtKwh(data.consumption[i])},
          {text: fmtEur(data.cost[i]), cls: data.cost[i] < 0 ? 'neg' : ''},
        ]));
      });
      invoiceFoot.appendChild(makeRow('th', [
        {text:'Gesamt'}, {text: fmtKwh(sumKwh)},
        {text: fmtEur(sumEur), cls: sumEur < 0 ? 'neg' : ''},
      ]));
    }
  });

// Date picker
(function() {
  const pageType = <?= json_encode($page_type) ?>;
  const base     = <?= json_encode($base) ?>;
  const picker   = document.getElementById('date-picker');

  picker.addEventListener('change', () => {
    const val = picker.value;
    if (!val) return;
    const d = new Date(val + 'T00:00:00');
    if (pageType === 'daily') {
      window.location = base + '/daily.php?date=' + val;
    } else if (pageType === 'weekly') {
      const tmp = new Date(d);
      tmp.setDate(tmp.getDate() + 4 - (tmp.getDay() || 7));
      const yearStart = new Date(tmp.getFullYear(), 0, 1);
      const week = Math.ceil(((tmp - yearStart) / 86400000 + 1) / 7);
      window.location = base + '/weekly.php?year=' + tmp.getFullYear() + '&week=' + week;
    } else {
      window.location = base + '/monthly.php?year=' + d.getFullYear() + '&month=' + (d.getMonth() + 1);
    }
  });
})();

// Tab switching
(function() {
  const storageKey = 'energie-tab-' + <?= json_encode($page_type) ?>;
  const savedTab   = localStorage.getItem(storageKey) || 'grafik';

  function activateTab(name) {
    document.querySelectorAll('.tab-btn').forEach(btn => {
      btn.classList.toggle('active', btn.dataset.tab === name);
    });
    document.querySelectorAll('.tab-panel').forEach(panel => {
      panel.classList.toggle('hidden', panel.dataset.tab !== name);
    });
    localStorage.setItem(storageKey, name);
  }

  document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => activateTab(btn.dataset.tab));
  });

  activateTab(savedTab);
})();
</script>
</body>
</html>
