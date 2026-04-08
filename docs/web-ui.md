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

`yearly.php` is standalone — it has enough unique requirements (invoice table with months, 13-month rolling window, no `shadowDatasets`) that a shared template would add more complexity than it removes.

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

**Color convention:** contemporary data uses light shades; historical overlay uses the darker shade of the same hue. This lets you compare actual vs. typical at a glance without needing a legend.

**Shadow bands:** implemented via Chart.js `fill: '+1'` on the `_max` dataset, which fills the area between `_max` and the next dataset (`_min`). The `_max` and `_min` datasets must be adjacent in the `datasets` array; the band dataset always immediately precedes its pair.

**Daily page difference:** the daily view has no tariff min/max shadow band (spot prices are hourly, so all four quarter-hour slots in the same hour have identical `spot_ct`; min = max). The "Tarif Band" pill button is hidden on this page via an `isDailyPage` JS check.

### Three Y-axes

| Axis | Position | Color | Scale |
|---|---|---|---|
| `y` | left | red `#fc8181` | Kosten (€) |
| `y2` | right | green `#68d391` | Verbrauch (kWh) |
| `y3` | right | blue `#63b3ed` | Tarif (ct/kWh) |

The right-side axes overlap visually. This works because cost and consumption are rarely toggled simultaneously, and the pill controls make it explicit which axis is active.

### Scale Stability

Chart.js recomputes scale boundaries whenever datasets are hidden or shown, which causes jarring axis jumps. To prevent this, scale boundaries are snapshotted immediately after chart creation and restored in every `applyVis()` call:

```js
const _ch = window._energieChart;
_ch._yMin  = _ch.scales.y?.min;   _ch._yMax  = _ch.scales.y?.max;
_ch._y2Min = _ch.scales.y2?.min;  _ch._y2Max = _ch.scales.y2?.max;
_ch._y3Min = _ch.scales.y3?.min;  _ch._y3Max = _ch.scales.y3?.max;
```

### Visibility Pills

Eight pill buttons (seven on the yearly page, six on the daily page) toggle dataset visibility. Each button has a `data-key` attribute that maps to an entry in the `vis` object.

State is persisted to `localStorage` under the key `energie-vis-{page_type}`. On page load, the stored state is merged over the defaults, so a user who hides "Kosten" on the daily page will find it hidden next time.

Pill appearance: **raised** (`.active`) = dataset visible; **pressed** (inset shadow, dim) = dataset hidden. This 3D button metaphor makes the state immediately obvious without labels like "show/hide".

### Legend Click Suppression

```js
legend: { onClick: () => {} }
```

Click-to-hide on legend items is disabled. Visibility is controlled exclusively through pills. This prevents accidental hiding via the legend, which would bypass localStorage persistence and confuse the pill state.

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

These are used to pin the y-axis `max` values so that navigating between periods doesn't change the scale — a spike in one month doesn't make other months look flat.

### `type=daily`

```
GET api.php?type=daily&date=YYYY-MM-DD
```

Returns 96 data points (15-min slots). Historical aggregates are computed by `GROUP BY TIME(ts)` across all dates — i.e., "what is the average consumption at 14:15 across all recorded days?"

`hist_kwh_avg` uses `AVG(NULLIF(consumed_kwh, 0))` to exclude hours where only spot prices were recorded (before consumption data was available), preventing the historical average from being dragged down by stub rows.

**Response:**
```json
{
  "labels": ["00:00:00", "00:15:00", …],
  "cost": [0.021, …],
  "consumption": [0.058, …],
  "tariff": [4.32, …],
  "hist_tariff_avg": [3.98, …],
  "hist_tariff_min": [1.20, …],
  "hist_tariff_max": [8.74, …],
  "hist_kwh_avg": [0.042, …],
  "hist_kwh_min": [0.011, …],
  "hist_kwh_max": [0.128, …],
  "maxCost": 0.842,
  "maxKwh": 1.234
}
```

### `type=weekly`

```
GET api.php?type=weekly&year=YYYY&week=W
```

Returns up to 7 data points (one per day). ISO week numbering (`WEEK(day, 3)`). Also returns `dates` (raw `YYYY-MM-DD` values) used by Chart.js to render German day abbreviations on the x-axis.

Historical aggregates are grouped by `WEEKDAY(day)` (0=Mon…6=Sun).

### `type=monthly`

```
GET api.php?type=monthly&year=YYYY&month=M
```

Returns up to 31 data points. Historical aggregates grouped by `DAY(day)` (day of month, 1–31).

Also includes `min_spot` / `max_spot` per day (from `readings`) for the tariff shadow band.

### `type=yearly`

```
GET api.php?type=yearly&year=YYYY&month=M
```

Returns 13 data points — the 13 months ending at `year-month` (inclusive). The 13-month window ensures a full calendar year is always visible plus the current month, regardless of where you are in the year.

Historical aggregates: per calendar month, averaged across all years in the dataset, using a subquery that first sums consumption per month per year before averaging across years.

### `type=set-theme`

```
POST api.php?type=set-theme
Body: theme=light|dark|auto
```

Persists the theme choice to `auth_accounts.theme` via MySQLi `$con`. Also updates `$_SESSION['theme']`. Returns `{"ok": true}`.

---

## Yearly Page — `yearly.php`

Standalone page (not using `_chart_page.php`). Differences from the drilldown pages:

- Labels are `MM/YY` formatted month keys
- Invoice table shows monthly totals with Ø Tarif column (not slot-level detail)
- No date picker (navigation is prev/next month-end)
- No "Tarif Band" pill (no min/max shadow for yearly aggregate data)
- Legend uses `pointStyle: 'rectRounded'` instead of `'line'`

---

## Preferences — `preferences.php`

Four settings sections, all using POST forms with CSRF protection:

| Section | Action | Notes |
|---|---|---|
| Profile picture | `upload_avatar` | JPEG/PNG/GIF/WebP, max 2 MB; stored as blob in `auth_accounts` |
| Theme | `change_theme` | Writes to DB + session; also settable via header dropdown |
| Email | `change_email` | Requires password confirmation; sends confirmation link via SMTP |
| Password | `change_password` | Old password required; bcrypt cost 13; min 8 chars |

Theme can be changed two ways: via the header dropdown (instant, AJAX to `api.php?type=set-theme`) or via Preferences (form POST). Both paths write to the same DB column and session key.
