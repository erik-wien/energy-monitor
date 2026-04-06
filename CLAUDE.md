# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Project Does

A 3-step Python pipeline for calculating electricity costs using spot prices (Hofer Grünstrom tariff, Austria).

**Step 1 — `1_convert_verbrauch.py`**  
Converts the raw quarter-hourly consumption CSV from the grid operator (semicolon-delimited, German date format `DD.MM.YY`, header in German) into:
- `verbrauch_YYYY.csv` — normalized CSV with header `Datum;von;bis;Verbrauch`
- `verbrauch_YYYY.json` — JSON with `{"id": "CONSUMPTION", "data": [{consumed, unit, from, to}]}`, ISO 8601 timestamps

The year is auto-detected from the first data row. Launched via tkinter file picker.

**Step 2 — `2_fetch_epex.py`**  
Fetches hourly spot prices from the Hofer Grünstrom API for all months of a given year. Saves:
- `spotpreise_YYYY_MM.json` per month — raw API response
- `spotpreise_YYYY.csv` — aggregated year CSV with header `Zeitpunkt;Preis (ct/kWh)`, only full hours (minute == 0), German decimal format

**Step 3 — `3_merge2abrechnung.py`**  
Joins consumption (`verbrauch_YYYY.json`) with spot prices (`spotpreise_YYYY_MM.json` files) by ISO timestamp, applies pricing formula, outputs `abrechnung_YYYY.csv`.

## Pricing Formula (`calculate_cost_brutto`)

Tariff parameters come from the `tariff_config` DB table (looked up by `valid_from <= ts`):

| Column | Meaning | Unit |
|--------|---------|------|
| `provider_surcharge_ct` | Hofer Aufschlag | ct/kWh |
| `electricity_tax_ct` | Elektrizitätsabgabe | ct/kWh |
| `renewable_tax_ct` | Erneuerbaren Förderbeitrag | ct/kWh |
| `meter_fee_eur` | Zählergebühr | €/yr |
| `renewable_fee_eur` | Erneuerbaren Förderpauschale | €/yr |
| `consumption_tax_rate` | Gebrauchsabgabe Wien | fraction (e.g. 0.06) |
| `vat_rate` | Umsatzsteuer | fraction (e.g. 0.20) |
| `yearly_kwh_estimate` | Annual kWh for fee amortisation | kWh |

```
annual_ct  = (meter_fee_eur + renewable_fee_eur) / yearly_kwh_estimate * 100
net_ct     = epex + provider_surcharge_ct + electricity_tax_ct + renewable_tax_ct + annual_ct
gross_ct   = net_ct * (1 + vat_rate) + net_ct * consumption_tax_rate
           = net_ct * (1 + vat_rate + consumption_tax_rate)
cost_eur   = consumed_kwh * gross_ct / 100
```

**Key rule:** VAT does **not** apply to the consumption tax (Gebrauchsabgabe). They are additive, not compounding.

## Running the Scripts

```bash
python 1_convert_verbrauch.py   # opens file picker for raw grid CSV
python 2_fetch_epex.py          # prompts for year via tkinter dialog
python 3_merge2abrechnung.py    # prompts for year via tkinter dialog
```

Only external dependency: `requests` (used in script 2). All other imports are stdlib.

## Data Conventions

- CSV delimiter: `;` (semicolon)
- German decimal format: `,` as decimal separator in output CSVs
- Timestamps in JSON: ISO 8601 (`YYYY-MM-DDTHH:MM:SS`)
- Input consumption CSV uses German date format `DD.MM.YY` and times like `00:00`
- `DEBUG = True/False` flag controls verbose logging in scripts 1 and 3

## File Naming

| File | Description |
|------|-------------|
| `VIERTELSTUNDENWERTE-*.csv` | Raw input from grid operator (do not modify) |
| `verbrauch_YYYY.csv/json` | Normalized consumption data |
| `spotpreise_YYYY_MM.json` | Raw monthly spot price API responses |
| `spotpreise_YYYY.csv` | Aggregated annual spot prices |
| `abrechnung_YYYY.csv` | Final billing output |

## Web UI (`web/`)

PHP app served at `/energie/`. All pages are login-gated.

### Auth system

Ported from the sibling project `wlmonitor`. Authenticates against the shared `wl_accounts` MariaDB table (same DB as the pipeline data).

**Two DB connections per request:**
- `$con` — MySQLi, opened in `inc/initialize.php`, used for auth (`wl_accounts`, `wl_log`)
- `$pdo` — PDO, opened in `inc/db.php`, used for all data queries (`readings`, `daily_summary`, `tariff_config`)

**Bootstrap chain:** every page `require_once '../inc/db.php'` → which `require_once 'initialize.php'` → session + MySQLi + security headers + CSRF.

**Key files:**

| File | Role |
|------|------|
| `inc/initialize.php` | Security headers (CSP nonce, HSTS, etc.), MySQLi `$con`, session start, CSRF include, `getUserIpAddr()`, `addAlert()`, `appendLog()`, `auth_require()` |
| `inc/csrf.php` | `csrf_token()`, `csrf_verify()`, `csrf_input()` |
| `inc/auth.php` | `auth_login()`, `auth_logout()`, IP rate limiting (5 attempts / 15 min, state in `data/ratelimit.json`) |
| `inc/db.php` | Includes `initialize.php`, opens PDO `$pdo`, sets `$base` URL prefix |
| `web/login.php` | Login form (Energie dark CSS, no Bootstrap) |
| `web/authentication.php` | POST handler: CSRF check → `auth_login()` → redirect |
| `web/logout.php` | `auth_logout()` → redirect to `login.php` |
| `data/ratelimit.json` | Rate-limit state file (writable by web server) |
| `data/.htaccess` | Blocks direct HTTP access to `data/` |

**Protecting a page:** add `auth_require();` after `require_once db.php`. For JSON endpoints return 401 inline instead of redirecting.

**Rate limiter** uses only `REMOTE_ADDR` (no proxy headers) to prevent bypass via spoofed `X-Forwarded-For`.

**Session fields set on login:** `loggedin`, `sId`, `id`, `username`, `email`, `rights`.

**Config:** `/opt/homebrew/etc/energie-config.ini` — both `initialize.php` (MySQLi) and `db.php` (PDO) read the `[db]` section.
