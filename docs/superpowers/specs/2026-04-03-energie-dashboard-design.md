# Energie Dashboard — Design Spec

**Date:** 2026-04-03  
**Status:** Approved

## Overview

Replace the existing three-script pipeline with a single unified `energie.py` that fetches both spot prices and consumption data, stores everything in MariaDB, and powers a local PHP dashboard at `localhost/energie`. A Claude Code scheduled agent runs daily at ~14:15 and posts a briefing to Slack `#dailybriefing`.

---

## 1. Python Pipeline (`energie.py`)

Single entry point replacing `1_convert_verbrauch.py`, `2_fetch_epex.py`, and `3_merge2abrechnung.py`.

### Subcommands

| Command | Description |
|---|---|
| `fetch-prices [--year Y] [--month M]` | Fetch spot prices from Hofer API, upsert to DB |
| `fetch-consumption [--year Y] [--month M]` | Scrape consumption via Playwright, upsert to DB |
| `fetch-all [--year Y] [--month M]` | Both of the above |
| `import-csv <file>` | Manual fallback: import a consumption CSV downloaded by hand |

Default `--year` and `--month` is the current month. For past months, `fetch-consumption` skips the scrape if data already exists in DB (upsert is always safe to re-run).

### Configuration (`config.ini`, not web-accessible)

```ini
[hofer]
username = you@example.com
password = secret

[db]
host = localhost
user = energie
password = secret
database = energie

[slack]
bot_token = xoxb-...
channel_id = C0123456789
```

`config.ini` lives one directory above the Apache document root so it is never served by Apache.

### Playwright scraping

The consumption portal (`hofer-grünstrom.at/app/portal/energymanager/…`) is a JavaScript SPA requiring authentication. Playwright automates:
1. Navigate to login page
2. Log in with credentials from `config.ini`
3. Navigate to `energymanager/<meter-id>/profile?year=Y&month=M`
4. Click "Ausgewählte Daten herunterladen"
5. Save the downloaded CSV to a temp file
6. Parse and upsert to DB

The meter ID (`AT001000…`) is stored in `config.ini` under `[hofer] meter_id`.

### Billing formula

Looks up the applicable tariff from `tariff_config` using `MAX(valid_from) WHERE valid_from <= ts`:

```
cost_brutto = (consumed_kwh × ((spot_ct + netz_ct) × (1 + mwst) + hofer_aufschlag_ct) + viertelstunden_ct) / 100
```

All terms inside the parentheses are in ct; dividing by 100 converts to €.

After upserting `readings`, `energie.py` rebuilds `daily_summary` via `INSERT … ON DUPLICATE KEY UPDATE` from a `GROUP BY DATE(ts)` query, also joining `tariff_config` for the correct rates per day.

---

## 2. MariaDB Schema

```sql
-- Quarter-hourly readings (primary data)
CREATE TABLE readings (
    ts           DATETIME      NOT NULL,
    consumed_kwh DECIMAL(8,4)  NOT NULL,
    spot_ct      DECIMAL(8,4)  NOT NULL,
    cost_brutto  DECIMAL(10,6) NOT NULL,
    PRIMARY KEY (ts)
);

-- Daily aggregates (cache for fast dashboard queries)
CREATE TABLE daily_summary (
    day          DATE         NOT NULL,
    consumed_kwh DECIMAL(8,3) NOT NULL,
    cost_brutto  DECIMAL(8,4) NOT NULL,
    avg_spot_ct  DECIMAL(8,4) NOT NULL,
    PRIMARY KEY (day)
);

-- Tariff rates with validity dates
CREATE TABLE tariff_config (
    valid_from         DATE         NOT NULL,
    netz_ct            DECIMAL(8,4) NOT NULL,  -- e.g. 10.396
    hofer_aufschlag_ct DECIMAL(8,4) NOT NULL,  -- e.g. 1.9
    mwst               DECIMAL(5,4) NOT NULL,  -- e.g. 0.20
    viertelstunden_ct  DECIMAL(8,7) NOT NULL,  -- e.g. 0.0311875 ct/slot (divided by 100 → €)
    PRIMARY KEY (valid_from)
);
```

Seed `tariff_config` with one row (`valid_from = '2020-01-01'`) containing current known values. Add a new row whenever rates change — no code change needed.

---

## 3. PHP Dashboard (`localhost/energie`)

Deployed to Apache's document root under `energie/`.

### File structure

```
energie/
├── index.php       # Overview: 3 tiles (heute / letzte Woche / letzter Monat)
├── daily.php       # Drilldown: ?date=2026-04-02
├── weekly.php      # Drilldown: ?year=2026&week=14 (ISO 8601)
├── monthly.php     # Drilldown: ?year=2026&month=3
├── api.php         # JSON endpoint for Chart.js
├── db.php          # Shared PDO connection (include, not directly accessible)
└── assets/
    └── style.css
```

### Overview page (`index.php`)

Three clickable tiles:
- **Heute** — yesterday's totals from `daily_summary` (most recently available day)
- **Letzte Woche** — sum of last 7 days
- **Letzter Monat** — sum of last 30 days

Each tile shows: total kWh, total cost (€), avg tariff (ct/kWh). Clicking navigates to the drilldown page for that period.

### Drilldown pages

All three share the same layout:
1. **Navigation bar** — period label + ← prev / next → arrows
2. **Chart** (Chart.js combo, full width, ~60% of viewport height)
   - Bars: `cost_brutto` per slot/day
   - Line: `consumed_kwh` per slot/day
3. **KPI strip** below chart — total kWh · total € · avg ct/kWh

Daily uses 96 quarter-hourly slots from `readings`. Weekly uses 7 daily rows from `daily_summary`. Monthly uses ~28–31 daily rows from `daily_summary`.

### API endpoint (`api.php`)

Returns JSON consumed by Chart.js:

| Request | Source table | Rows |
|---|---|---|
| `?type=daily&date=2026-04-02` | `readings` | 96 |
| `?type=weekly&year=2026&week=14` | `daily_summary` | 7 (ISO 8601 week) |
| `?type=monthly&year=2026&month=3` | `daily_summary` | 28–31 |

Response shape:
```json
{
  "labels": ["00:00", "00:15", ...],
  "cost": [0.021, 0.019, ...],
  "consumption": [0.078, 0.093, ...]
}
```

---

## 4. Slack Briefing

Posted by the scheduled agent after a successful `fetch-all`. The Slack bot token and channel ID are in `config.ini`.

### Daily (every day)

Text message only:

```
⚡ Energie · Mi 02.04.2026

Verbrauch:  12,4 kWh
Kosten:     € 2,18
Ø Tarif:    8,4 ct/kWh

→ localhost/energie/daily.php?date=2026-04-02
```

### Weekly (every Tuesday)

Same KPI block for the past Mon–Sun week, plus a matplotlib combo chart (bars = daily cost, line = daily consumption) uploaded as a PNG attachment to the Slack message.

### Monthly (every 2nd of the month)

Same format as weekly but covering the full prior calendar month.

---

## 5. Scheduling

A Claude Code scheduled agent runs daily at **14:15**. Steps:

1. Run `python3 /path/to/energie.py fetch-all`
2. On success: post daily Slack briefing
3. If today is **Tuesday**: post weekly Slack briefing with chart
4. If today is the **2nd of the month**: post monthly Slack briefing with chart
5. Log output to `energie.log` beside the script

---

## 6. File Layout (repository)

```
Energie/
├── energie.py          # Merged pipeline script
├── config.ini          # Credentials (not committed to git)
├── config.ini.example  # Template committed to git
├── energie.log         # Runtime log (gitignored)
├── docs/
│   └── superpowers/specs/
│       └── 2026-04-03-energie-dashboard-design.md
└── _Archiv/            # Existing archive files (unchanged)

/Library/WebServer/Documents/energie/   (or equivalent Apache docroot)
├── index.php
├── daily.php
├── weekly.php
├── monthly.php
├── api.php
├── db.php
└── assets/style.css
```

`config.ini` and `energie.log` are added to `.gitignore`.

---

## Open Questions / Assumptions

- Apache document root path needs to be confirmed (`httpd -S` will reveal it).
- Meter ID (`AT001000…`) is already known from the existing portal URL.
- Playwright requires `pip install playwright && python -m playwright install chromium` on first setup.
- Slack bot needs `chat:write` and `files:write` scopes.
