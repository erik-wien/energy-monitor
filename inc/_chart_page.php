<?php
// Expected vars from including file:
// $title       string   Page <title>
// $period_label string  e.g. "Di 01.04.2026" or "KW14 · 30.03–05.04.2026"
// $prev_url    string   URL for ← link
// $next_url    string|null URL for → link (null if no future data)
// $api_url     string   URL passed to Chart.js fetch
// $kpi_kwh     float
// $kpi_eur     float
// $kpi_ct      float

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
    <span>⚡</span>
    <h1><a href="<?= $base ?>/" style="color:inherit;text-decoration:none">Energie</a></h1>
</header>
<main>
    <div class="nav-bar">
        <a href="<?= htmlspecialchars($prev_url) ?>">← zurück</a>
        <span class="period-label"><?= htmlspecialchars($period_label) ?></span>
        <?php if ($next_url): ?>
            <a href="<?= htmlspecialchars($next_url) ?>">vor →</a>
        <?php else: ?>
            <span style="color:var(--border)">vor →</span>
        <?php endif; ?>
    </div>

    <div class="chart-container">
        <canvas id="chart"></canvas>
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
</main>

<script>
fetch(<?= json_encode($api_url) ?>)
  .then(r => r.json())
  .then(data => {
    const ctx = document.getElementById('chart').getContext('2d');
    new Chart(ctx, {
      data: {
        labels: data.labels,
        datasets: [
          {
            type: 'bar',
            label: 'Kosten (€)',
            data: data.cost,
            backgroundColor: 'rgba(233,69,96,0.7)',
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
          x:  { ticks: { color: '#718096', maxTicksLimit: 12 }, grid: { color: '#2d3748' } },
          y:  { ticks: { color: '#fc8181' }, grid: { color: '#2d3748' }, position: 'left',
                title: { display: true, text: 'Kosten (€)', color: '#fc8181' } },
          y2: { ticks: { color: '#68d391' }, grid: { display: false }, position: 'right',
                title: { display: true, text: 'Verbrauch (kWh)', color: '#68d391' } }
        }
      }
    });
  });
</script>
</body>
</html>
