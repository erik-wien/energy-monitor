# Architecture

## Overview

Energie is split into two independent subsystems that share a MariaDB database:

1. **The pipeline** (`energie.py`) — a CLI tool that ingests data from two external sources and stores it in normalised form.
2. **The web UI** (`web/`) — a PHP application that queries the database and renders interactive charts.

Neither subsystem depends on the other at runtime. The pipeline can run headlessly via cron; the web UI only ever reads (and users write only preferences). This clean separation means data collection is never blocked by web traffic and the web UI never needs to understand the import format.

---

## Components

### energie.py — CLI Pipeline

Responsible for all data ingestion. Has four subcommands:

| Command | What it does |
|---|---|
| `fetch-prices --year Y --month M` | Fetches hourly EPEX spot prices from the Hofer API, upserts into `readings`, archives the raw JSON to `_Archiv/` |
| `import-csv <file>` | Parses a grid-operator CSV or XLSX, computes gross cost per slot, upserts into `readings`, rebuilds `daily_summary`, archives the file |
| `fetch-all --year Y --month M` | Runs `fetch-prices` then imports all `QuarterHourValues-*.csv` found in `scrapes/` |
| `notify` | Posts Slack briefings (daily always; weekly on Tuesdays; monthly on the 2nd) |

The pipeline writes to two tables directly: `readings` (one row per 15-min slot) and triggers a full `daily_summary` rebuild after every consumption import. Spot price rows are written as stubs (`consumed_kwh = 0`) so the timestamp exists before consumption data arrives; the consumption import then fills in the missing fields via `ON DUPLICATE KEY UPDATE`.

### Web UI — PHP Application

A login-gated PHP application served under an Apache `Alias`. All pages follow the same bootstrap chain and delegate chart rendering to the browser via Chart.js. The server produces only HTML + JSON; no server-side chart generation.

Key components:

| File | Role |
|---|---|
| `inc/initialize.php` | Security headers (CSP, HSTS), MySQLi `$con` for auth DB, auth library bootstrap |
| `inc/db.php` | Includes `initialize.php`, opens PDO `$pdo` for data DB, derives `$base` URL prefix |
| `inc/_chart_page.php` | Shared drilldown template: KPI strip, visibility pills, Chart.js datasets, invoice table |
| `inc/_header.php` | Navigation bar, user menu, theme switcher |
| `web/api.php` | Single JSON endpoint for all chart data (type=daily|weekly|monthly|yearly|set-theme) |

### MariaDB

Four tables. Two belong to the data model, one to configuration, one to auth:

```
readings         one row per 15-min slot; PK = ts (datetime)
daily_summary    one row per day; computed from readings; PK = day (date)
tariff_config    one row per tariff period; PK = valid_from (date)
auth_accounts    managed by erikr/auth library; stores users, sessions, avatars
```

---

## Data Flow

### Ingest path

```
Grid operator CSV
  └─ parse_consumption_csv()          detect column format, parse Datum/Zeit von
       └─ _compute_and_upsert_consumption()
            ├─ for each row: SELECT spot_ct from readings
            ├─ get_tariff() lookup by date
            ├─ calculate_cost_brutto()
            └─ INSERT … ON DUPLICATE KEY UPDATE consumed_kwh, cost_brutto
                 └─ rebuild_daily_summary()   GROUP BY DATE(ts)

Hofer API
  └─ fetch_prices()
       ├─ GET /spot-prices?year=&month=
       ├─ parse_spot_json()
       └─ INSERT … ON DUPLICATE KEY UPDATE spot_ct   (consumed_kwh stays 0)
```

The two paths are intentionally decoupled. Spot prices are always fetched first (they arrive as hourly stubs). When consumption data arrives later, the upsert fills in `consumed_kwh` and `cost_brutto` by joining against the existing spot price in the same row.

### Read path

```
Browser GET /energy.test/daily.php?date=2026-04-07
  └─ daily.php         query daily_summary for KPIs, build $api_url, include _chart_page.php
       └─ _chart_page.php   render HTML shell + Chart.js bootstrap

Browser fetch /energie.test/api.php?type=daily&date=2026-04-07
  └─ api.php           query readings + historical aggregates
       └─ JSON response → Chart.js renders datasets in browser
```

The page HTML is delivered first with KPI numbers (server-rendered). The chart data arrives asynchronously via a second fetch, which keeps the initial page load fast and allows the chart to fade in smoothly.

---

## Directory Structure

```
Energie/
├── energie.py              Entry point for all pipeline operations
├── config.ini              Credentials: DB, Hofer API, Slack
├── deploy.sh               Rsync + DB sync script
│
├── inc/                    PHP includes — NOT web-accessible
│   ├── initialize.php      Bootstrap (security headers, $con, auth library)
│   ├── db.php              $pdo, $base, config path selection
│   ├── _chart_page.php     Drilldown page template
│   └── _header.php         Navigation + user menu
│
├── web/                    Apache document root
│   ├── index.php           Dashboard (3 KPI tiles)
│   ├── daily.php           24-hour drilldown
│   ├── weekly.php          7-day drilldown
│   ├── monthly.php         Calendar-month drilldown
│   ├── yearly.php          13-month rolling view (standalone, not _chart_page)
│   ├── api.php             JSON data endpoint
│   ├── admin/              Admin panel (tariffs, import, status, users)
│   ├── preferences.php     User settings
│   ├── login.php           Login UI
│   ├── authentication.php  Login POST handler
│   ├── logout.php          Session destroy (CSRF-protected POST)
│   ├── avatar.php          Serves profile image from DB blob
│   ├── confirm_email.php   Email change confirmation handler
│   ├── styles/style.css    Full stylesheet
│   └── img/                Static assets (favicon, SVG logo)
│
├── vendor/                 Composer — erikr/auth
├── scrapes/                Drop zone for incoming CSV files
├── _Archiv/                Consumed and archived data files
└── data/
    ├── ratelimit.json      Login rate-limiter state
    └── .htaccess           Deny all HTTP access to data/
```

---

## Request Lifecycle

A complete round-trip for a daily drilldown page:

```
1. Browser → GET /energie.test/daily.php?date=2026-04-07
   Apache matches Alias /energie.test → /Users/erikr/Git/Energie/web/
   PHP processes web/daily.php

2. web/daily.php
   require_once '../inc/db.php'
     → inc/db.php derives $base = '/energie.test' from SCRIPT_NAME
     → selects energie-config-dev.ini (dev) or energie-config.ini (prod)
     → include inc/initialize.php
         → vendor/autoload.php  (erikr/auth)
         → parse ini → define SMTP_*, APP_BASE_URL
         → open MySQLi $con → jardyx_auth DB
         → auth_bootstrap()
             → emit Content-Security-Policy (nonce), HSTS, X-Frame-Options
             → session_start()
             → regenerate CSRF token if stale
     → open PDO $pdo → energie (or energie_dev) DB
   auth_require()  → redirects to login.php if !$_SESSION['loggedin']
   Query daily_summary for KPIs (consumed_kwh, cost_brutto, avg_spot_ct)
   Set template variables ($title, $period_label, $api_url, $kpi_*, …)
   require '../inc/_chart_page.php'
     → emit full HTML: nav, KPI strip, pill buttons, empty <canvas>
     → emit inline <script> with chart bootstrap + fetch($api_url)

3. Browser receives HTML, renders KPIs immediately

4. Browser → GET /energie.test/api.php?type=daily&date=2026-04-07
   api.php checks $_SESSION['loggedin'] → 401 if missing
   Queries readings for the date + historical aggregates (GROUP BY TIME(ts))
   Returns JSON: labels, cost, consumption, tariff, hist_tariff_*, hist_kwh_*

5. Chart.js callback
   Creates Chart with 10 datasets (cost, consumption, tariff, 3×hist tariff,
   3×hist kwh, 2×shadow bands)
   Snapshots all six scale boundaries (_yMin/_yMax, _y2Min/…, _y3Min/…)
   Applies stored visibility state from localStorage
   Renders chart with fade-in animation
```

**Why two requests?** Separating HTML from data keeps the page responsive during the DB query. The KPI numbers (server-rendered) appear instantly; the chart fades in once the data arrives. It also enables the same `api.php` endpoint to serve Slack/other consumers.
