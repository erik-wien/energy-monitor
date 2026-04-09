# Energie

A self-hosted electricity cost tracker for Austrian [Hofer Grünstrom](https://www.hofer-grünstrom.at) spot-price customers. It pulls quarter-hourly consumption data from the grid operator, fetches hourly EPEX spot prices from the Hofer API, calculates the exact gross cost per slot using the Austrian tariff structure, and presents everything in an interactive web dashboard.

> **Context:** Hofer Grünstrom is an Austrian electricity provider whose tariff passes EPEX spot prices directly to the customer, plus a fixed surcharge. The gross cost per slot therefore varies every 15 minutes, which makes tracking non-trivial and motivates this project.

---

## Architecture

```
┌─────────────────────────────────────────────────┐
│                  Data Sources                   │
│  Grid Operator CSV          Hofer API           │
│  QuarterHourValues-*.csv    /spot-prices        │
└───────────────┬─────────────────┬───────────────┘
                │                 │
                ▼                 ▼
┌────────────────────────────────────────────────────────┐
│               energie.py  (CLI pipeline)               │
│  import-csv      fetch-prices      fetch-all           │
│  parse → upsert  fetch → upsert    prices + import     │
│  rebuild daily_summary             archive → _Archiv/  │
└──────────────────────────┬─────────────────────────────┘
                           │
                           ▼
              ┌────────────────────────┐
              │        MariaDB         │
              │  readings              │
              │  daily_summary         │
              │  tariff_config         │
              │  auth_accounts         │
              └────────────┬───────────┘
                           │
                           ▼
┌────────────────────────────────────────────────────────┐
│               Web UI  (PHP / Apache)                   │
│  /energie.test (dev)       /energie (prod)             │
│                                                        │
│  index      Dashboard — three KPI summary tiles        │
│  daily      24-hour view  (15-min slots)               │
│  weekly     7-day view                                 │
│  monthly    Calendar-month view                        │
│  yearly     13-month rolling view                      │
│  api        JSON endpoint (Chart.js data + set-theme)  │
│  admin      Tariff editor, CSV import, DB status       │
│  preferences  Avatar, email, password, theme           │
└────────────────────────────────────────────────────────┘
```

---

## Repository Layout

```
Energie/
├── energie.py              CLI data pipeline
├── config.ini              Pipeline config (DB, Hofer API credentials)
├── deploy.sh               File sync + prod→dev DB refresh
├── inc/                    PHP shared includes (not web-accessible)
│   ├── initialize.php      Bootstrap: security headers, MySQLi $con, auth
│   ├── db.php              PDO $pdo, $base prefix, config selection
│   ├── _chart_page.php     Drilldown page template (Chart.js + invoice table)
│   └── _header.php         Navigation header component
├── web/                    Apache document root (/energie or /energie.test)
│   ├── index.php           Dashboard
│   ├── daily.php           Daily drilldown
│   ├── weekly.php          Weekly drilldown
│   ├── monthly.php         Monthly drilldown
│   ├── yearly.php          Yearly overview
│   ├── api.php             JSON data endpoint
│   ├── admin/              Admin panel (tariffs, import, status, users)
│   ├── preferences.php     User preferences
│   ├── login.php           Login form
│   ├── authentication.php  Login POST handler
│   ├── logout.php          Logout + CSRF-protected form submit
│   ├── avatar.php          Serves profile picture from DB blob
│   ├── confirm_email.php   Email change confirmation
│   ├── styles/
│   │   ├── shared/         Symlink → ~/Git/css (shared CSS library)
│   │   ├── energie-theme.css  Energie color palette (--color-* overrides)
│   │   └── energie.css     App-specific styles (header, tiles, charts, etc.)
│   └── img/                Favicons, logo
├── vendor/                 Composer — erikr/auth shared auth library
├── scrapes/                Drop incoming CSVs here; processed → _Archiv/
├── _Archiv/                Processed spot price JSONs and consumption files
├── data/
│   ├── ratelimit.json      Rate-limiter state (must be writable by httpd)
│   └── .htaccess           Blocks direct HTTP access
└── docs/                   Technical documentation (you are here)
```

---

## Quick Start

### Prerequisites

- macOS with Homebrew (Apache + PHP + MariaDB)
- Python 3.11+ with `mysql-connector-python`, `requests`, `openpyxl`
- Composer (for the auth library)

### 1. Database

```sql
CREATE DATABASE energie  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE energie_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'energie'@'localhost' IDENTIFIED BY 'your-password';
GRANT SELECT, INSERT, UPDATE ON energie.* TO 'energie'@'localhost';
GRANT ALL ON energie_dev.* TO 'energie'@'localhost';
FLUSH PRIVILEGES;
```

Import the schema (tables: `readings`, `daily_summary`, `tariff_config`) and seed at least one `tariff_config` row.

### 2. Config

```ini
# /opt/homebrew/etc/energie-config.ini  (production)
[db]
host     = localhost
user     = energie
password = …
database = energie

[auth]
host     = localhost
user     = …
password = …
database = jardyx_auth     ; shared auth DB

[smtp]
host = …   port = 587   user = …   password = …
from = energie@example.com   from_name = Energie

[app]
base_url = http://localhost/energie
```

Copy to `energie-config-dev.ini` and set `database = energie_dev`.

### 3. Apache

```apache
Alias /energie      /Library/WebServer/Documents/Energie/web
Alias /energie.test /path/to/git/Energie/web
```

Each directory needs `AllowOverride All`.

### 4. First import

```bash
# Fetch current month's spot prices
python energie.py fetch-prices

# Import consumption CSV from grid operator
python energie.py import-csv scrapes/QuarterHourValues-…csv

# Or both at once (also globs scrapes/ for CSVs)
python energie.py fetch-all
```

### 5. Deploy

```bash
./deploy.sh   # syncs web/ inc/ vendor/ to prod, refreshes energie_dev from prod
```

---

## Documentation

| Document | Contents |
|---|---|
| [Architecture](docs/architecture.md) | System design, component relationships, request lifecycle |
| [Data Pipeline](docs/data-pipeline.md) | CLI commands, pricing formula, file lifecycle |
| [Database](docs/database.md) | Schema, column reference, daily_summary rebuild |
| [Web UI](docs/web-ui.md) | Pages, Chart.js system, API endpoint reference |
| [Auth & Security](docs/auth-and-security.md) | Auth model, session, preferences, security headers |
| [Admin Panel](docs/admin.md) | Tariff editor, CSV import, DB status, user management |
| [Deployment](docs/deployment.md) | Dev/prod split, deploy.sh, Slack notifications |

---

## Tech Stack

| Layer | Technology |
|---|---|
| Data pipeline | Python 3, `mysql-connector-python`, `requests` |
| Database | MariaDB 10.x |
| Web backend | PHP 8.x |
| Web frontend | Vanilla JS, Chart.js 4 |
| Auth library | `erikr/auth` (Composer, shared across projects) |
| HTTP server | Apache httpd (Homebrew) |
| Notifications | Slack Web API + `matplotlib` charts |
