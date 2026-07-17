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
// $kpi_eff      float

require_once __DIR__ . '/layout.php';

function fmt_kwh($v) { return number_format($v, 1, ',', '.') . ' <span class="unit">kWh</span>'; }
function fmt_eur($v) { return '<span class="unit">€</span> ' . number_format($v, 2, ',', '.'); }
function fmt_ct($v)  { return number_format($v, 2, ',', '.') . ' <span class="unit">ct/kWh</span>'; }

$_chartHead = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"'
            . ' nonce="' . htmlspecialchars($_cspNonce, ENT_QUOTES, 'UTF-8') . '"></script>'
            . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/dark.css">'
            . '<link rel="stylesheet" href="' . htmlspecialchars($base, ENT_QUOTES, 'UTF-8') . '/css/flatpickr-overrides.css">';
render_page_head($title, $_chartHead);
render_header($page_type);
?>
<main>
    <div class="nav-bar">
        <a href="<?= htmlspecialchars($prev_url) ?>">← <?= htmlspecialchars($prev_label) ?></a>
        <div class="period-nav">
            <span class="period-label"><?= htmlspecialchars($period_label) ?></span>
            <input type="text" id="date-picker" class="date-input-inline" readonly
                   value="<?= htmlspecialchars($current_date_iso) ?>">
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
            <div class="label">Ø Spotpreis</div>
            <div class="value tariff"><?= fmt_ct($kpi_ct) ?></div>
        </div>
        <div class="kpi-card">
            <div class="label">Ø effektiv</div>
            <div class="value eff"><?= fmt_ct($kpi_eff) ?></div>
        </div>
    </div>
    <div class="chart-controls">
        <button type="button" class="chart-pill chart-pill--cost"    data-key="cost">Kosten</button>
        <button type="button" class="chart-pill chart-pill--kwh"     data-key="kwh">Verbrauch</button>
        <button type="button" class="chart-pill chart-pill--hkwh"    data-key="hkwh">hist. Verbr.</button>
        <button type="button" class="chart-pill chart-pill--hkband"  data-key="hkband">hist. Verbr. Band</button>
        <button type="button" class="chart-pill chart-pill--tariff"  data-key="tariff">Tarif</button>
        <button type="button" class="chart-pill chart-pill--minmax"  data-key="minmax" id="btn-minmax">Tarif Band</button>
        <button type="button" class="chart-pill chart-pill--eff"     data-key="eff" id="btn-eff">Effektiv netto</button>
    </div>
    <div class="chart-container">
        <canvas id="chart"></canvas>
        <div class="scrub-line" id="scrub-line" aria-hidden="true"></div>
    </div>
    <div class="scrub-bar" id="scrub-bar">
        <div class="scrub-handle" id="scrub-handle"></div>
    </div>
    <div class="scrub-readout" id="scrub-readout" aria-live="polite"></div>
</main>

<script nonce="<?= $_cspNonce ?>">
const isDailyPage   = <?= json_encode($page_type === 'daily') ?>;
const isWeeklyPage  = <?= json_encode($page_type === 'weekly') ?>;
const periodLabel   = <?= json_encode($period_label) ?>;
const base          = <?= json_encode($base) ?>;
const prevUrl       = <?= json_encode($prev_url) ?>;
const nextUrl       = <?= json_encode($next_url) ?>;
const swipeNavEnabled = <?= en_swipe_nav_enabled($pdo, (int) $_SESSION['id']) ? 'true' : 'false' ?>;
let _printHTML = null;
let _blobUrl   = null;

fetch(<?= json_encode($api_url) ?>)
  .then(r => r.json())
  .then(data => {
    const DE_DAYS = ['So','Mo','Di','Mi','Do','Fr','Sa'];
    const ctx     = document.getElementById('chart').getContext('2d');
    const ptR     = data.labels.length > 50 ? 0 : 3;

    // Effektiv netto = verbrauchsgewichteter Spot (Σ kWh×Spot / Σ kWh; API-Feld
    // epex_wgt). Liegt unter dem einfachen Spot, wenn in billigen Stunden mehr
    // verbraucht wird → macht die Lastverschiebung sichtbar. Nur in Wochen-/
    // Monatsansicht sinnvoll — in der Tagesansicht (15-min-Slots) wäre der
    // gewichtete Spot je Slot der Spot selbst (redundant, daher weggelassen).
    const effDatasets = isDailyPage ? [] : [
      {
        type: 'line', label: 'Effektiv netto (ct/kWh)', data: data.epex_wgt,
        borderColor: '#ecc94b', backgroundColor: 'rgba(236,201,75,0.1)',
        borderWidth: 2, pointRadius: ptR, tension: 0.3, yAxisID: 'y3', order: 3,
      },
    ];

    const shadowDatasets = isDailyPage ? [] : [
      {
        type: 'line', label: '_tariff_max', data: data.max_spot,
        borderColor: 'transparent', backgroundColor: 'rgba(99,179,237,0.13)',
        pointRadius: 0, fill: '+1', tension: 0.3, yAxisID: 'y3', order: 5,
      },
      {
        type: 'line', label: '_tariff_min', data: data.min_spot,
        borderColor: 'transparent', backgroundColor: 'transparent',
        pointRadius: 0, fill: false, tension: 0.3, yAxisID: 'y3', order: 4,
      },
    ];

    window._energieChart = new Chart(ctx, {
      data: {
        labels: data.labels,
        datasets: [
          {
            type: 'line', label: 'Kosten (€)', data: data.cost,
            borderColor: '#e94560', backgroundColor: 'rgba(233,69,96,0.08)',
            borderWidth: 2, pointRadius: ptR, tension: 0.3, yAxisID: 'y', order: 2,
          },
          {
            type: 'line', label: 'Verbrauch (kWh)', data: data.consumption,
            borderColor: '#68d391', backgroundColor: 'rgba(104,211,145,0.1)',
            borderWidth: 2, pointRadius: ptR, tension: 0.3, yAxisID: 'y2', order: 1,
          },
          ...shadowDatasets,
          {
            type: 'line', label: 'Tarif (ct/kWh)', data: data.tariff,
            borderColor: '#63b3ed', backgroundColor: 'rgba(99,179,237,0.1)',
            borderWidth: 2, pointRadius: ptR, tension: 0.3, yAxisID: 'y3', order: 0,
          },
          ...effDatasets,
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
            borderWidth: 1.5, pointRadius: ptR, tension: 0.3, yAxisID: 'y2', order: 4,
            borderDash: [5, 3],
          },
        ]
      },
      options: {
        animation: false,
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        onHover: (event) => {
          event.native.target.style.cursor = 'default';
        },
        plugins: {
          legend: {
            onClick: () => {},
            labels: {
              color: '#e2e8f0',
              usePointStyle: true,
              pointStyle: 'line',
              filter: item => !item.text.startsWith('_'),
            }
          },
          tooltip: {
            backgroundColor: '#16213e', borderColor: '#2d3748', borderWidth: 1,
            filter: item => !item.dataset.label.startsWith('_'),
          }
        },
        scales: {
          x: {
            ticks: {
              color: '#718096',
              maxTicksLimit: 24,
              callback: (val, i) => {
                if (isDailyPage)  return (data.labels[i] ?? '').substring(0, 2);
                if (isWeeklyPage) {
                  const raw = data.dates?.[i];
                  return raw ? DE_DAYS[new Date(raw + 'T00:00:00').getDay()] : '';
                }
                return (data.labels[i] ?? '').substring(0, 2); // monthly: "DD"
              }
            },
            grid: { color: '#2d3748' }
          },
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

    const _ch = window._energieChart;
    _ch._yMin = _ch.scales.y?.min;   _ch._yMax = _ch.scales.y?.max;
    _ch._y2Min = _ch.scales.y2?.min; _ch._y2Max = _ch.scales.y2?.max;
    _ch._y3Min = _ch.scales.y3?.min; _ch._y3Max = _ch.scales.y3?.max;
    if (window._applyChartVis) window._applyChartVis(window._energieChart);

    _printHTML = buildPrintContent(data, DE_DAYS);

    // ── Scrub-Lineal: Crosshair + Anfasser + Werte-Readout ────────────────
    (function initScrub() {
      const chart     = window._energieChart;
      const canvas    = document.getElementById('chart');
      const container = canvas.parentElement;            // .chart-container
      const line      = document.getElementById('scrub-line');
      const bar       = document.getElementById('scrub-bar');
      const handle    = document.getElementById('scrub-handle');
      const readout   = document.getElementById('scrub-readout');
      const n         = data.labels.length;
      let scrubIndex = null;
      if (!n) return;

      const clamp  = i => Math.max(0, Math.min(n - 1, i));
      const fmtEur = v => '€ ' + (v ?? 0).toFixed(2).replace('.', ',');
      const fmtKwh = v => (v ?? 0).toFixed(2).replace('.', ',') + ' kWh';
      const fmtCt  = v => (v ?? 0).toFixed(2).replace('.', ',') + ' ct/kWh';
      const esc    = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

      function render() {
        if (scrubIndex == null) return;
        const px    = chart.scales.x.getPixelForValue(scrubIndex);
        const cRect = canvas.getBoundingClientRect();
        const pRect = container.getBoundingClientRect();
        const bRect = bar.getBoundingClientRect();
        // Crosshair über die Plot-Höhe
        line.style.left    = (cRect.left - pRect.left + px) + 'px';
        line.style.top     = (cRect.top  - pRect.top  + chart.chartArea.top) + 'px';
        line.style.height  = (chart.chartArea.bottom - chart.chartArea.top) + 'px';
        line.style.display = 'block';
        // Anfasser auf der Leiste (gleiche X-Pixel wie der Plot)
        handle.style.left  = (cRect.left - bRect.left + px) + 'px';
        // Readout
        readout.innerHTML =
            '<span class="ro-time">' + esc(isDailyPage ? String(data.labels[scrubIndex] ?? '').slice(0, 5) : (data.labels[scrubIndex] ?? '')) + '</span>'
          + '<span class="ro-sep">·</span><span class="ro-eur">'    + fmtEur(data.cost[scrubIndex])        + '</span>'
          + '<span class="ro-sep">·</span><span class="ro-kwh">'    + fmtKwh(data.consumption[scrubIndex]) + '</span>'
          + '<span class="ro-sep">·</span><span class="ro-tariff">' + fmtCt(data.tariff[scrubIndex])       + '</span>'
          + (data.epex_wgt && data.epex_wgt[scrubIndex] != null
                ? '<span class="ro-sep">·</span><span class="ro-eff">' + fmtCt(data.epex_wgt[scrubIndex]) + '</span>'
                : '');
      }

      function setFromClientX(clientX) {
        const cRect = canvas.getBoundingClientRect();
        const idx   = clamp(Math.round(chart.scales.x.getValueForPixel(clientX - cRect.left)));
        scrubIndex  = idx;
        render();
      }

      let downX = 0, moved = false, onHandle = false;
      bar.addEventListener('pointerdown', e => {
        bar.setPointerCapture(e.pointerId);
        downX = e.clientX; moved = false;
        onHandle = (e.target === handle);
        setFromClientX(e.clientX);
      });
      bar.addEventListener('pointermove', e => {
        if (!bar.hasPointerCapture(e.pointerId)) return;
        if (Math.abs(e.clientX - downX) > 6) moved = true;
        setFromClientX(e.clientX);
      });
      bar.addEventListener('pointerup', e => {
        bar.releasePointerCapture(e.pointerId);
        // Anfasser antippen (ohne Ziehen) → Tagesansicht des gewählten Zeitpunkts
        if (onHandle && !moved && !isDailyPage && data.dates && data.dates[scrubIndex]) {
          window.location = base + '/daily.php?date=' + data.dates[scrubIndex];
        }
      });

      let resizeTimer = null;
      window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(render, 150);
      });

      // Startposition: Mitte
      scrubIndex = Math.floor((n - 1) / 2);
      render();
    })();
  });

function buildPrintContent(data, DE_DAYS) {
  const pad2      = n => String(n).padStart(2, '0');
  const fmtN      = (v, dp) => (v < 0 ? '\u2212' : '') + '\u20ac\u00a0' + Math.abs(v).toFixed(dp).replace('.', ',');
  const fmt2      = v => fmtN(v, 2);
  const fmtKwh    = v => Math.abs(v).toFixed(3).replace('.', ',') + '\u00a0kWh';
  const fmtCt     = v => Math.abs(v).toFixed(2).replace('.', ',') + '\u00a0ct/kWh';
  // Cost in ct (for daily rows and zwischensumme)
  const fmtCtCost = v => (v < 0 ? '\u2212' : '') + Math.abs(v).toFixed(3).replace('.', ',') + '\u00a0ct';
  const fmtDE     = iso => iso.slice(8,10) + '.' + iso.slice(5,7) + '.' + iso.slice(0,4);
  const esc       = s => s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

  const now = new Date();
  const stand  = pad2(now.getDate()) + '.' + pad2(now.getMonth()+1) + '.' + now.getFullYear()
               + ' ' + pad2(now.getHours()) + ':' + pad2(now.getMinutes());
  const period = esc(periodLabel) + ' (' + fmtDE(data.period_start) + ' \u2013 ' + fmtDE(data.period_end) + ')';

  let sumKwh = 0, sumEpxW = 0, sumNetto = 0, sumEpx = 0, sumCount = 0, sumAuf = 0, sumAbg = 0, sumGba = 0, sumMwst = 0, sumGes = 0;
  const bodyParts = [];

  const addRow = (lbl, kwh, epx, epxW, auf, abg, gba, mwst, ges) => {
    if (isDailyPage) {
      // Per-slot row: Ø gew. omitted; all cost columns in ct
      bodyParts.push(
        '<tr>'
        + '<td>' + esc(lbl) + '</td>'
        + '<td>' + fmtKwh(kwh) + '</td>'
        + '<td>' + fmtCt(epx) + '</td>'
        + '<td>' + fmtCtCost(kwh * epx) + '</td>'
        + '<td>' + fmtCtCost(auf * 100) + '</td>'
        + '<td>' + fmtCtCost(abg * 100) + '</td>'
        + '<td>' + fmtCtCost(gba * 100) + '</td>'
        + '<td>' + fmtCtCost(mwst * 100) + '</td>'
        + '<td' + (ges < 0 ? ' class="neg"' : '') + '>' + fmtCtCost(ges * 100) + '</td>'
        + '</tr>'
      );
    } else {
      // Per-day row: Ø gew. = consumption-weighted avg; Netto Preis uses arithmetic avg epx
      // (invoice_breakdown uses avg_spot_ct = epx, so columns sum to Preis Brutto)
      bodyParts.push(
        '<tr>'
        + '<td>' + esc(lbl) + '</td>'
        + '<td>' + fmtKwh(kwh) + '</td>'
        + '<td>' + fmtCt(epx) + '</td>'
        + '<td>' + fmtCt(epxW) + '</td>'
        + '<td>' + fmt2(kwh * epx / 100) + '</td>'
        + '<td>' + fmt2(auf) + '</td>'
        + '<td>' + fmt2(abg) + '</td>'
        + '<td>' + fmt2(gba) + '</td>'
        + '<td>' + fmt2(mwst) + '</td>'
        + '<td' + (ges < 0 ? ' class="neg"' : '') + '>' + fmt2(ges) + '</td>'
        + '</tr>'
      );
    }
    sumKwh += kwh; sumEpxW += kwh * epxW; sumNetto += kwh * epx / 100; sumEpx += epx; sumCount++; sumAuf += auf; sumAbg += abg; sumGba += gba; sumMwst += mwst; sumGes += ges;
  };

  if (isDailyPage) {
    data.labels.forEach((lbl, i) => addRow(
      lbl.substring(0, 5),
      data.consumption?.[i]     ?? 0,
      data.epex?.[i]            ?? 0,
      data.epex?.[i]            ?? 0,
      data.aufschlag?.[i]       ?? 0,
      data.abgaben?.[i]         ?? 0,
      data.gebrauchsabgabe?.[i] ?? 0,
      data.mwst_tax?.[i]        ?? 0,
      data.gesamt_variable?.[i] ?? data.cost[i] ?? 0
    ));
  } else {
    data.dates.forEach((iso, i) => {
      const d = new Date(iso + 'T00:00:00');
      addRow(
        DE_DAYS[d.getDay()] + ' ' + iso.slice(8,10) + '.' + iso.slice(5,7) + '.',
        data.consumption?.[i]     ?? 0,
        data.epex?.[i]            ?? 0,
        data.epex_wgt?.[i]        ?? data.epex?.[i] ?? 0,
        data.aufschlag?.[i]       ?? 0,
        data.abgaben?.[i]         ?? 0,
        data.gebrauchsabgabe?.[i] ?? 0,
        data.mwst_tax?.[i]        ?? 0,
        data.gesamt_variable?.[i] ?? data.cost[i] ?? 0
      );
    });
  }

  const mfp   = data.meter_fee_prop    ?? 0;
  const rfp   = data.renewable_fee_prop ?? 0;
  const grand = sumGes + mfp + rfp;

  let footParts, blank, headerCols;
  if (isDailyPage) {
    // 9 columns: Zeit, Verbrauch, EPEX ø, Netto Preis, Aufschlag, Abgaben, Steuern, MwSt, Preis Brutto
    blank = '<td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>';
    headerCols = '<th>Verbrauch</th><th>EPEX</th><th>Netto Preis</th><th>Aufschlag</th><th>Abgaben</th><th>Steuern</th><th>MwSt</th><th>Preis Brutto</th>';
    footParts = [
      '<tr class="sub">'
        + '<td class="lbl-sub">Zwischensumme</td>'
        + '<td>' + fmtKwh(sumKwh) + '</td>'
        + '<td>' + fmtCt(sumCount > 0 ? sumEpx / sumCount : 0) + '</td>'
        + '<td>' + fmtCtCost(sumEpxW) + '</td>'
        + '<td>' + fmtCtCost(sumAuf * 100) + '</td>'
        + '<td>' + fmtCtCost(sumAbg * 100) + '</td>'
        + '<td>' + fmtCtCost(sumGba * 100) + '</td>'
        + '<td>' + fmtCtCost(sumMwst * 100) + '</td>'
        + '<td' + (sumGes < 0 ? ' class="neg"' : '') + '>' + fmtCtCost(sumGes * 100) + '</td>'
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
        + '<th>Gesamtsumme</th><th></th><th></th><th></th><th></th><th></th><th></th><th></th>'
        + '<th' + (grand < 0 ? ' class="neg"' : '') + '>' + fmt2(grand) + '</th>'
        + '</tr>'
    );
  } else {
    // 10 columns: Tag, Verbrauch, EPEX ø, Ø gew., Netto Preis, Aufschlag, Abgaben, Steuern, MwSt, Preis Brutto
    blank = '<td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>';
    headerCols = '<th>Verbrauch</th><th>EPEX \u00f8</th><th>\u00d8 gew.</th><th>Netto Preis (EPEX \u00f8)</th><th>Aufschlag</th><th>Abgaben</th><th>Steuern</th><th>MwSt</th><th>Preis Brutto</th>';
    footParts = [
      '<tr class="sub">'
        + '<td class="lbl-sub">Zwischensumme</td>'
        + '<td>' + fmtKwh(sumKwh) + '</td>'
        + '<td>' + fmtCt(sumCount > 0 ? sumEpx / sumCount : 0) + '</td>'
        + '<td>' + fmtCt(sumKwh  > 0 ? sumEpxW / sumKwh  : 0) + '</td>'
        + '<td>' + fmt2(sumNetto) + '</td>'
        + '<td>' + fmt2(sumAuf) + '</td>'
        + '<td>' + fmt2(sumAbg) + '</td>'
        + '<td>' + fmt2(sumGba) + '</td>'
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
        + '<th>Gesamtsumme</th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th>'
        + '<th' + (grand < 0 ? ' class="neg"' : '') + '>' + fmt2(grand) + '</th>'
        + '</tr>'
    );
  }

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
    + '<th>' + (isDailyPage ? 'Zeit' : 'Tag') + '</th>'
    + headerCols
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

// Date picker — Flatpickr
</script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr" nonce="<?= $_cspNonce ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/de.js" nonce="<?= $_cspNonce ?>"></script>
<script nonce="<?= $_cspNonce ?>">
(function() {
  const pageType = <?= json_encode($page_type) ?>;
  flatpickr('#date-picker', {
    locale: 'de',
    dateFormat: 'Y-m-d',
    defaultDate: document.getElementById('date-picker').value || null,
    onChange: ([date]) => {
      if (!date) return;
      const val = date.getFullYear() + '-'
        + String(date.getMonth() + 1).padStart(2, '0') + '-'
        + String(date.getDate()).padStart(2, '0');
      const d = date;
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
    }
  });
})();

// Dataset visibility controls
(function() {
  const storageKey = 'energie-vis-' + <?= json_encode($page_type) ?>;
  const defaults   = { cost: true, kwh: true, tariff: true, minmax: true, eff: true, hkwh: true, hkband: true };
  let vis = Object.assign({}, defaults, JSON.parse(localStorage.getItem(storageKey) || '{}'));

  if (isDailyPage) {
    // Tarif-Band + Effektiv-netto-Pill sind in der Tagesansicht gegenstandslos.
    ['btn-minmax', 'btn-eff'].forEach(id => {
      const btn = document.getElementById(id);
      if (btn) btn.style.display = 'none';
    });
  }

  document.querySelectorAll('.chart-controls .chart-pill[data-key]').forEach(btn => {
    btn.classList.toggle('active', vis[btn.dataset.key] !== false);
  });

  function applyVis(chart) {
    chart.data.datasets.forEach((ds, i) => {
      const meta = chart.getDatasetMeta(i);
      if      (ds.label === 'Kosten (€)')          meta.hidden = !vis.cost;
      else if (ds.label === 'Verbrauch (kWh)')     meta.hidden = !vis.kwh;
      else if (ds.label === 'Tarif (ct/kWh)')      meta.hidden = !vis.tariff;
      else if (ds.label.startsWith('_tariff'))      meta.hidden = !vis.minmax;
      else if (ds.label === 'Effektiv netto (ct/kWh)') meta.hidden = !vis.eff;
      else if (ds.label === 'Ø Verbrauch (kWh)')   meta.hidden = !vis.hkwh;
      else if (ds.label.startsWith('_hkwh'))        meta.hidden = !vis.hkband;
    });
    chart.options.scales.y.display  = vis.cost;
    chart.options.scales.y2.display = vis.kwh  || vis.hkwh;
    chart.options.scales.y3.display = vis.tariff || vis.eff;
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

// Swipe-Navigation + leichte Slide-Animation (nur im Graph-Band)
(function() {
  if (!swipeNavEnabled) return;
  const container = document.querySelector('.chart-container');
  if (!container) return;
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const THRESH = 60;
  let x0 = 0, y0 = 0, dragging = false, dx = 0;

  // Slide-in beim Laden (Richtung aus dem vorherigen Blättern)
  const from = sessionStorage.getItem('energieSlideFrom');
  if (from) {
    sessionStorage.removeItem('energieSlideFrom');
    if (!reduce) {
      container.style.transition = 'none';
      container.style.transform  = 'translateX(' + (from === 'next' ? '100%' : '-100%') + ')';
      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          container.style.transition = 'transform 0.18s ease-out';
          container.style.transform  = 'translateX(0)';
        });
      });
    }
  }

  container.addEventListener('touchstart', e => {
    x0 = e.touches[0].clientX; y0 = e.touches[0].clientY;
    dragging = false; dx = 0;
    container.style.transition = 'none';
  }, { passive: true });

  container.addEventListener('touchmove', e => {
    dx = e.touches[0].clientX - x0;
    const dy = e.touches[0].clientY - y0;
    if (!dragging && Math.abs(dx) > 10 && Math.abs(dx) > Math.abs(dy)) dragging = true;
    if (dragging && !reduce) container.style.transform = 'translateX(' + dx + 'px)';
  }, { passive: true });

  container.addEventListener('touchend', () => {
    if (!dragging) return;
    const goNext = dx < 0 && nextUrl;
    const goPrev = dx > 0 && prevUrl;
    if (Math.abs(dx) > THRESH && (goNext || goPrev)) {
      const url = goNext ? nextUrl : prevUrl;
      sessionStorage.setItem('energieSlideFrom', goNext ? 'next' : 'prev');
      if (reduce) { window.location = url; return; }
      container.style.transition = 'transform 0.18s ease-out';
      container.style.transform  = 'translateX(' + (goNext ? '-100%' : '100%') + ')';
      setTimeout(() => { window.location = url; }, 180);
    } else {
      container.style.transition = 'transform 0.18s ease-out';
      container.style.transform  = 'translateX(0)';
    }
  }, { passive: true });

  container.addEventListener('touchcancel', () => {
    container.style.transition = 'transform 0.18s ease-out';
    container.style.transform  = 'translateX(0)';
  }, { passive: true });
})();
</script>
<?php render_footer(); ?>
