# Web UI

## Page Inventory

| URL | File | Data source | Template |
|---|---|---|---|
| `/energie/` | `index.php` | `daily_summary` × 3 queries | standalone |
| `/energie/daily.php?date=YYYY-MM-DD` | `daily.php` | `api.php?type=daily` | `_chart_page.php` |
| `/energie/weekly.php?year=Y&week=W` | `weekly.php` | `api.php?type=weekly` | `_chart_page.php` |
| `/energie/monthly.php?year=Y&month=M` | `monthly.php` | `api.php?type=monthly` | `_chart_page.php` |
| `/energie/yearly.php?year=Y&month=M` | `yearly.php` | `api.php?type=yearly` | standalone |
| `/energie/api.php?type=…` | `api.php` | `readings`, `daily_summary` | JSON |
| `/energie/preferences.php` | `preferences.php` | `auth_accounts` | standalone |
| `/energie/admin/` | `admin/index.php` | `tariff_config`, `readings` | standalone |

---

## Dashboard — `index.php`

Renders three clickable tiles showing KPIs for the most recent day, the last 7 days, and the last 30 days. All data is server-rendered; no JavaScript chart on this page.

The "most recent day" is the latest `daily_summary.day` where `consumed_kwh > 0`, not necessarily today — this handles gaps gracefully when the pipeline hasn't run yet.

---

## Drilldown Pages — `_chart_page.php`

`daily.php`, `weekly.php`, and `monthly.php` all follow the same pattern: the page file builds a handful of template variables and then `require`s `inc/_chart_page.php`, which owns the entire HTML structure.

Template variables the page must provide:

| Variable | Type | Example |
|---|---|---|
| `$title` | string | `"07.04.2026"` |
| `$period_label` | string | `"Di 07.04.2026"` |
| `$page_type` | string | `'daily'` \| `'weekly'` \| `'monthly'` |
| `$current_date_iso` | string | `"2026-04-07"` (for the date picker) |
| `$prev_url` / `$prev_label` | string | Previous period nav |
| `$next_url` / `$next_label` | string/null | Next period nav (null = no future data) |
| `$api_url` | string | Full URL passed to Chart.js `fetch()` |
| `$kpi_kwh` / `$kpi_eur` / `$kpi_ct` | float | KPI strip values |

`yearly.php` is standalone — it has enough unique requirements (monthly rows instead of slots/days, 13-month rolling window, no `shadowDatasets`) that a shared template would add more complexity than it removes.

---

## Chart.js System

### Datasets

Each drilldown chart has up to 10 datasets. The `_` prefix marks shadow/band datasets that are hidden from the legend and tooltip.

| Label | Axis | Color | Notes |
|---|---|---|---|
| `Kosten (€)` | y | `#e94560` red | |
| `Verbrauch (kWh)` | y2 | `#68d391` light green | |
| `_tariff_max` | y3 | transparent | fill to `_tariff_min`; weekly/monthly only |
| `_tariff_min` | y3 | transparent | shadow pair |
| `Tarif (ct/kWh)` | y3 | `#63b3ed` light blue | |
| `_htariff_max` | y3 | transparent | fill to `_htariff_min` |
| `_htariff_min` | y3 | transparent | shadow pair |
| `Ø Tarif (ct/kWh)` | y3 | `#3182ce` dark blue | dashed, historical avg |
| `_hkwh_max` | y2 | transparent | fill to `_hkwh_min` |
| `_hkwh_min` | y2 | transparent | shadow pair |
| `Ø Verbrauch (kWh)` | y2 | `#38a169` dark green | dashed, historical avg |

**Color convention:** contemporary data uses light shades; historical overlay uses the darker shade of the same hue.

**Shadow bands:** implemented via Chart.js `fill: '+1'` on the `_max` dataset. The `_max` and `_min` datasets must be adjacent in the `datasets` array.

**Daily page difference:** no tariff min/max shadow band (all quarter-hour slots within the same hour have identical `spot_ct`). The "Tarif Band" pill is hidden on the daily page.

### Three Y-axes

| Axis | Position | Color | Scale |
|---|---|---|---|
| `y` | left | red `#fc8181` | Kosten (€) |
| `y2` | right | green `#68d391` | Verbrauch (kWh) |
| `y3` | right | blue `#63b3ed` | Tarif (ct/kWh) |

### Scale Stability

Chart.js recomputes scale boundaries whenever datasets are hidden or shown. To prevent jarring axis jumps, scale boundaries are snapshotted immediately after chart creation and restored in every `applyVis()` call:

```js
const _ch = window._energieChart;
_ch._yMin  = _ch.scales.y?.min;   _ch._yMax  = _ch.scales.y?.max;
_ch._y2Min = _ch.scales.y2?.min;  _ch._y2Max = _ch.scales.y2?.max;
_ch._y3Min = _ch.scales.y3?.min;  _ch._y3Max = _ch.scales.y3?.max;
```

### Visibility Pills

Eight pill buttons toggle dataset visibility. Each button has a `data-key` attribute mapping to an entry in the `vis` object. State is persisted to `localStorage` under `energie-vis-{page_type}`.

Pill appearance: **raised** (`.active`) = dataset visible; **pressed** (inset shadow, dim) = dataset hidden.

### Legend Click Suppression

```js
legend: { onClick: () => {} }
```

Click-to-hide on legend items is disabled. Visibility is controlled exclusively through pills to keep localStorage persistence consistent.

---

## Print Invoice

A printer icon button sits in the `.period-nav` bar (between the period label and the date picker). Clicking it opens a self-contained cost breakdown in a new popup window.

### How it works

After the API fetch resolves, `buildPrintContent(data, DE_DAYS)` generates a complete HTML document as a string and stores it in `_printHTML`. When the print button is clicked:

```js
_blobUrl = URL.createObjectURL(new Blob([_printHTML], { type: 'text/html; charset=utf-8' }));
const win = window.open(_blobUrl, '_blank', 'width=900,height=700,…');
if (win) win.addEventListener('load', () => { win.focus(); win.print(); });
```

The popup is created as a Blob URL rather than using the legacy `write` method on `document`. This avoids XSS risk and is compatible with the app's nonce-based CSP. Chrome inherits the parent page's CSP for `blob:` URLs opened from the same origin — inline event handlers in the popup HTML would be blocked. The `window.print()` call is therefore made from the opener's nonce-protected `<script>` via the `load` event, not from an `onclick` attribute inside the blob.

Blob URLs are revoked on every subsequent click (`URL.revokeObjectURL(_blobUrl)`) to avoid memory leaks.

### Invoice columns

| Column | Content | Format |
|---|---|---|
| Zeit / Tag | Time slot (daily) or weekday + date (weekly/monthly) | string |
| Verbrauch | Consumption | `kWh` to 4 dp (daily) / 3 dp (weekly/monthly) |
| EPEX | Spot price rate | `ct/kWh` to 2 dp |
| Aufschlag | Provider surcharge component | `€` |
| Abgaben | Tax component (electricity + renewable) | `€` |
| Steuern | Consumption tax (Gebrauchsabgabe Wien) component | `€` |
| MwSt | VAT component | `€` |
| Gesamt | Total variable cost for this slot | `€` |

The "€" columns use `invoice_breakdown()` decomposition — each column is the portion of the total cost attributable to that cost driver, computed so they sum exactly to Gesamt.

### Zwischensumme (subtotal row)

- **Verbrauch**: simple sum of slot consumption
- **EPEX**: consumption-weighted average rate — `Σ(kWh_i × spot_i) / Σ(kWh_i)` in ct/kWh
- **Aufschlag / Abgaben / Steuern / MwSt / Gesamt**: simple sums

The weighted EPEX average means that multiplying it by the total kWh gives the total EPEX cost in ct (÷ 100 = €).

### Invoice footer

Two fixed-cost rows appear below the subtotal if non-zero:

- **Zählergebühr (ant.)** — meter fee proportional to the period's consumption share of `yearly_kwh_estimate`
- **Erneuerbaren-Abgabe (ant.)** — renewable flat fee, same proration

These are supplied by `api.php` as `meter_fee_prop` and `renewable_fee_prop`.

**Gesamtsumme** = variable subtotal + both fixed-cost proportions.

### Invoice header

Hardcoded address and provider info (Böckhgasse 9/6/74, 1120 Wien; Hofer Grünstrom; Wien Strom), plus a "Stand vom DD.MM.YYYY HH:MM" timestamp computed at print time.

### Decimal precision

- `invDp = 4` on the daily page (slot-level data is small; 4 dp avoids displaying `€ 0,00` for sub-cent slots)
- `invDp = 3` on weekly/monthly pages (daily totals are large enough that 3 dp is sufficient)
- The EPEX and kWh columns always use 2 dp and `invDp` dp respectively regardless of page type

---

## API Endpoint — `api.php`

Single file, dispatches on `type` query parameter. All endpoints require a valid session (401 if not authenticated).

### Global maxima

At the top of the file, four global maxima are computed across the entire dataset:

```sql
SELECT MAX(consumed_kwh) FROM daily_summary   -- max_daily_kwh
SELECT MAX(cost_brutto)  FROM daily_summary   -- max_daily_cost
SELECT MAX(consumed_kwh) FROM readings        -- max_slot_kwh
SELECT MAX(cost_brutto)  FROM readings        -- max_slot_cost
```

These pin the y-axis `max` values so that navigating between periods doesn't change the scale.

### `invoice_breakdown()` helper

```php
function invoice_breakdown(float $kwh, float $epex, array &$epex_out,
    float $prov, float $elec_tax, float $ren_tax,
    float $con_tax_rate, float $vat_rate,
    float $mfp, float $rfp): array
```

Splits the total variable cost into the six invoice columns (EPEX, Aufschlag, Abgaben, Steuern, MwSt, Gesamt) and appends `$epex` (the raw ct/kWh spot rate) to `$epex_out`. The caller accumulates `$epex_out[]` across all rows.

The decomposition uses the same pricing formula as `calculate_cost_brutto()` but computes each additive component separately so the columns sum to the total. The fixed-cost proportions `$mfp` / `$rfp` are passed through to the response but handled separately (they appear only in the invoice footer, not as per-slot columns).

### Common response fields

All data-fetching endpoints (`daily`, `weekly`, `monthly`, `yearly`) return these invoice-related arrays alongside the chart data:

| Field | Content |
|---|---|
| `epex` | Spot rate per slot/day/month in ct/kWh |
| `aufschlag` | Provider surcharge component in € |
| `abgaben` | Electricity + renewable tax component in € |
| `gebrauchsabgabe` | Consumption tax component in € |
| `mwst_tax` | VAT component in € |
| `gesamt_variable` | Total variable cost per slot/day/month in € |
| `meter_fee_prop` | Meter fee proportion for the period in € |
| `renewable_fee_prop` | Renewable flat fee proportion for the period in € |
| `period_start` / `period_end` | ISO date strings for the invoice header |

### `type=daily`

```
GET api.php?type=daily&date=YYYY-MM-DD
```

Returns 96 data points (15-min slots). Historical aggregates are computed by `GROUP BY TIME(ts)` across all dates.

`hist_kwh_avg` uses `AVG(NULLIF(consumed_kwh, 0))` to exclude hours where only spot prices were recorded.

### `type=weekly`

```
GET api.php?type=weekly&year=YYYY&week=W
```

Returns up to 7 data points (one per day). ISO week numbering (`WEEK(day, 3)`). Also returns `dates` (raw `YYYY-MM-DD` values) used by Chart.js to render German day abbreviations. Historical aggregates grouped by `WEEKDAY(day)`.

### `type=monthly`

```
GET api.php?type=monthly&year=YYYY&month=M
```

Returns up to 31 data points. Historical aggregates grouped by `DAY(day)`. Includes `min_spot` / `max_spot` per day for the tariff shadow band.

### `type=yearly`

```
GET api.php?type=yearly&year=YYYY&month=M
```

Returns 13 data points — the 13 months ending at `year-month` inclusive. Historical aggregates: per calendar month, averaged across all years, using a subquery that first sums per month per year before averaging.

### `type=set-theme`

```
POST api.php?type=set-theme
Body: theme=light|dark|auto
```

Persists to `auth_accounts.theme` and `$_SESSION['theme']`. Returns `{"ok": true}`.

### `type=trigger-import`

```
POST api.php?type=trigger-import
```

Runs the Python import pipeline on all `.csv` and `.xlsx` files currently in `scrapes/`. The subprocess is spawned via `proc_open()` with an array argument list (no shell string, no injection risk). Captures stdout/stderr and returns:

```json
{ "ok": true,  "log": "Imported 384 rows…" }
{ "ok": false, "error": "…", "log": "…" }
```

The import button in the header dropdown calls this endpoint, disables itself while waiting, and reloads the page on success.

---

## Header — `inc/_header.php`

### Import notification

At render time, `_header.php` globs `scrapes/` for `*.csv` and `*.xlsx` files. If any are found, a small orange dot (`.notif-dot`) appears on the avatar button. The count is shown in the "Importieren (N)" dropdown item.

### Theme switcher

Three buttons in the dropdown (☀ light / ⬤ auto / 🌙 dark) post to `api.php?type=set-theme` via fetch and immediately update `document.documentElement.dataset.theme`. The active button gets `.active`. Theme can also be changed via the Preferences page form — both paths write to the same DB column and session key.

---

## Yearly Page — `yearly.php`

Standalone page (not using `_chart_page.php`). Differences from the drilldown pages:

- Labels are `MM/YY` formatted month keys; rows iterate `data.months` instead of `data.dates`
- Invoice table shows monthly totals with weighted-average EPEX column
- No date picker (navigation is prev/next month-end)
- No "Tarif Band" pill (no min/max shadow for yearly aggregate data)
- `buildPrintContent` uses `DE_MO` (German month abbreviation array) instead of `DE_DAYS`
- Print popup, Blob URL approach, and `load`-event auto-print are identical to `_chart_page.php`

---

## Preferences — `preferences.php`

Four settings sections, all using POST forms with CSRF protection:

| Section | Action | Notes |
|---|---|---|
| Profile picture | `upload_avatar` | JPEG/PNG/GIF/WebP, max 2 MB; stored as blob in `auth_accounts` |
| Theme | `change_theme` | Writes to DB + session; also settable via header dropdown |
| Email | `change_email` | Requires password confirmation; sends confirmation link via SMTP |
| Password | `change_password` | Old password required; bcrypt cost 13; min 8 chars |
