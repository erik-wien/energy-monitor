# Energie Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace three separate Python scripts with a single `energie.py` pipeline that stores data in MariaDB and powers a PHP dashboard at `http://localhost/energie` plus daily Slack briefings.

**Architecture:** `energie.py` fetches spot prices (Hofer API) and consumption data (Playwright scrape), upserts to MariaDB, and rebuilds daily aggregates. PHP + Chart.js serves the dashboard. A Claude Code scheduled agent runs the pipeline daily at 14:15 and posts Slack summaries.

**Tech Stack:** Python 3 · mysql-connector-python · Playwright · slack_sdk · matplotlib · PHP 8.5 · MariaDB · Chart.js (CDN) · Apache (docroot `/opt/homebrew/var/www`)

---

## File Map

```
/Users/erikr/Git/Energie/
├── energie.py              # Single merged pipeline — all Python logic
├── config.ini              # Credentials (gitignored)
├── config.ini.example      # Committed template
├── .gitignore              # Add config.ini, energie.log
├── tests/
│   ├── test_billing.py     # Billing formula unit tests
│   ├── test_prices.py      # Price parsing unit tests
│   └── test_csv.py         # CSV import unit tests

/opt/homebrew/var/www/energie/
├── index.php               # Overview: 3 tiles
├── daily.php               # Drilldown: ?date=YYYY-MM-DD
├── weekly.php              # Drilldown: ?year=Y&week=W (ISO 8601)
├── monthly.php             # Drilldown: ?year=Y&month=M
├── api.php                 # JSON endpoint for Chart.js
├── db.php                  # Shared PDO connection (reads config.ini)
└── assets/
    └── style.css           # Dark theme
```

---

## Task 1: Project Setup

**Files:**
- Modify: `/Users/erikr/Git/Energie/.gitignore`
- Create: `/Users/erikr/Git/Energie/config.ini.example`

- [ ] **Step 1: Install Python dependencies**

```bash
cd /Users/erikr/Git/Energie
pip install playwright slack_sdk pytest
python -m playwright install chromium
```

Expected: all install without errors.

- [ ] **Step 2: Create config.ini.example**

```ini
[hofer]
username = you@example.com
password = secret
meter_id = AT0010000000000000001000012891962

[db]
host = localhost
user = energie
password = secret
database = energie

[slack]
bot_token = xoxb-your-token-here
channel_id = C0123456789
```

- [ ] **Step 3: Update .gitignore**

Add to `/Users/erikr/Git/Energie/.gitignore` (create if missing):
```
config.ini
energie.log
__pycache__/
.pytest_cache/
tests/__pycache__/
```

- [ ] **Step 4: Copy config.ini.example to config.ini and fill in real values**

```bash
cp config.ini.example config.ini
# Edit config.ini with your real Hofer credentials, DB password, Slack token
```

- [ ] **Step 5: Commit**

```bash
git add config.ini.example .gitignore
git commit -m "chore: add project config template and gitignore"
```

---

## Task 2: MariaDB Setup

**Files:** (SQL only, no Python file)

- [ ] **Step 1: Create database and user**

```bash
mysql -u root <<'SQL'
CREATE DATABASE IF NOT EXISTS energie CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'energie'@'localhost' IDENTIFIED BY 'secret';
GRANT ALL PRIVILEGES ON energie.* TO 'energie'@'localhost';
FLUSH PRIVILEGES;
SQL
```

Replace `'secret'` with your chosen password and update `config.ini`.

- [ ] **Step 2: Create tables**

```bash
mysql -u energie -p energie <<'SQL'
CREATE TABLE IF NOT EXISTS readings (
    ts           DATETIME      NOT NULL,
    consumed_kwh DECIMAL(8,4)  NOT NULL,
    spot_ct      DECIMAL(8,4)  NOT NULL,
    cost_brutto  DECIMAL(10,6) NOT NULL,
    PRIMARY KEY (ts)
);

CREATE TABLE IF NOT EXISTS daily_summary (
    day          DATE         NOT NULL,
    consumed_kwh DECIMAL(8,3) NOT NULL,
    cost_brutto  DECIMAL(8,4) NOT NULL,
    avg_spot_ct  DECIMAL(8,4) NOT NULL,
    PRIMARY KEY (day)
);

CREATE TABLE IF NOT EXISTS tariff_config (
    valid_from         DATE         NOT NULL,
    netz_ct            DECIMAL(8,4) NOT NULL,
    hofer_aufschlag_ct DECIMAL(8,4) NOT NULL,
    mwst               DECIMAL(5,4) NOT NULL,
    viertelstunden_ct  DECIMAL(8,7) NOT NULL,
    PRIMARY KEY (valid_from)
);
SQL
```

- [ ] **Step 3: Seed initial tariff**

```bash
mysql -u energie -p energie <<'SQL'
INSERT IGNORE INTO tariff_config
    (valid_from, netz_ct, hofer_aufschlag_ct, mwst, viertelstunden_ct)
VALUES
    ('2020-01-01', 10.396, 1.9, 0.2000, 0.0311875);
SQL
```

- [ ] **Step 4: Verify**

```bash
mysql -u energie -p energie -e "SHOW TABLES; SELECT * FROM tariff_config;"
```

Expected: 3 tables listed, 1 tariff row.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "chore: add DB schema setup instructions to plan"
```

---

## Task 3: energie.py — Skeleton + Config + DB

**Files:**
- Create: `/Users/erikr/Git/Energie/energie.py`
- Create: `/Users/erikr/Git/Energie/tests/test_billing.py`

- [ ] **Step 1: Write failing billing test**

Create `/Users/erikr/Git/Energie/tests/test_billing.py`:

```python
import sys, os
sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))

def test_billing_formula_basic():
    """Single slot: 0.1 kWh at 10 ct/kWh spot, with known tariff."""
    from energie import calculate_cost_brutto
    tariff = {
        "netz_ct": 10.396,
        "hofer_aufschlag_ct": 1.9,
        "mwst": 0.20,
        "viertelstunden_ct": 0.0311875,
    }
    # (0.1 * ((10 + 10.396) * 1.2 + 1.9) + 0.0311875) / 100
    # = (0.1 * (20.396 * 1.2 + 1.9) + 0.0311875) / 100
    # = (0.1 * (24.4752 + 1.9) + 0.0311875) / 100
    # = (0.1 * 26.3752 + 0.0311875) / 100
    # = (2.63752 + 0.0311875) / 100
    # = 2.6687075 / 100
    # = 0.026687075
    result = calculate_cost_brutto(0.1, 10.0, tariff)
    assert abs(result - 0.026687075) < 1e-8

def test_billing_zero_consumption():
    from energie import calculate_cost_brutto
    tariff = {"netz_ct": 10.396, "hofer_aufschlag_ct": 1.9, "mwst": 0.20, "viertelstunden_ct": 0.0311875}
    result = calculate_cost_brutto(0.0, 10.0, tariff)
    # (0 * ... + 0.0311875) / 100 = 0.000311875
    assert abs(result - 0.000311875) < 1e-10
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd /Users/erikr/Git/Energie
python -m pytest tests/test_billing.py -v
```

Expected: `ImportError: cannot import name 'calculate_cost_brutto'`

- [ ] **Step 3: Create energie.py with config, DB, and billing**

Create `/Users/erikr/Git/Energie/energie.py`:

```python
#!/usr/bin/env python3
"""Energie pipeline: fetch spot prices + consumption, store in MariaDB."""

import argparse
import configparser
import csv
import json
import os
import sys
from datetime import datetime, date, timedelta

import mysql.connector

# ── Config ──────────────────────────────────────────────────────────────────

CONFIG_PATH = os.path.join(os.path.dirname(__file__), "config.ini")


def load_config():
    cfg = configparser.ConfigParser()
    if not cfg.read(CONFIG_PATH):
        sys.exit(f"❌ config.ini not found at {CONFIG_PATH}")
    return cfg


# ── DB ───────────────────────────────────────────────────────────────────────

def get_db(cfg):
    return mysql.connector.connect(
        host=cfg["db"]["host"],
        user=cfg["db"]["user"],
        password=cfg["db"]["password"],
        database=cfg["db"]["database"],
        charset="utf8mb4",
    )


def get_tariff(conn, ts: datetime) -> dict:
    """Return the tariff row with the largest valid_from <= ts."""
    cur = conn.cursor(dictionary=True)
    cur.execute(
        "SELECT * FROM tariff_config WHERE valid_from <= %s ORDER BY valid_from DESC LIMIT 1",
        (ts.date(),),
    )
    row = cur.fetchone()
    cur.close()
    if not row:
        sys.exit(f"❌ No tariff found for {ts}")
    return row


def upsert_readings(conn, rows: list[dict]):
    """rows: list of {ts, consumed_kwh, spot_ct, cost_brutto}"""
    cur = conn.cursor()
    cur.executemany(
        """INSERT INTO readings (ts, consumed_kwh, spot_ct, cost_brutto)
           VALUES (%(ts)s, %(consumed_kwh)s, %(spot_ct)s, %(cost_brutto)s)
           ON DUPLICATE KEY UPDATE
               consumed_kwh = VALUES(consumed_kwh),
               spot_ct      = VALUES(spot_ct),
               cost_brutto  = VALUES(cost_brutto)""",
        rows,
    )
    conn.commit()
    cur.close()


def rebuild_daily_summary(conn):
    """Recompute daily_summary from readings."""
    cur = conn.cursor()
    cur.execute("""
        INSERT INTO daily_summary (day, consumed_kwh, cost_brutto, avg_spot_ct)
        SELECT
            DATE(ts),
            SUM(consumed_kwh),
            SUM(cost_brutto),
            AVG(spot_ct)
        FROM readings
        GROUP BY DATE(ts)
        ON DUPLICATE KEY UPDATE
            consumed_kwh = VALUES(consumed_kwh),
            cost_brutto  = VALUES(cost_brutto),
            avg_spot_ct  = VALUES(avg_spot_ct)
    """)
    conn.commit()
    cur.close()
    print(f"✅ daily_summary rebuilt: {cur.rowcount} rows affected")


# ── Billing ──────────────────────────────────────────────────────────────────

def calculate_cost_brutto(consumed_kwh: float, spot_ct: float, tariff: dict) -> float:
    """All ct inputs → returns € brutto."""
    return (
        consumed_kwh * (
            (spot_ct + tariff["netz_ct"]) * (1 + tariff["mwst"])
            + tariff["hofer_aufschlag_ct"]
        )
        + tariff["viertelstunden_ct"]
    ) / 100


# ── CLI placeholder ──────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description="Energie pipeline")
    sub = parser.add_subparsers(dest="cmd", required=True)

    p = sub.add_parser("fetch-prices")
    p.add_argument("--year",  type=int, default=datetime.now().year)
    p.add_argument("--month", type=int, default=datetime.now().month)

    p = sub.add_parser("fetch-consumption")
    p.add_argument("--year",  type=int, default=datetime.now().year)
    p.add_argument("--month", type=int, default=datetime.now().month)

    p = sub.add_parser("fetch-all")
    p.add_argument("--year",  type=int, default=datetime.now().year)
    p.add_argument("--month", type=int, default=datetime.now().month)

    p = sub.add_parser("import-csv")
    p.add_argument("file")

    args = parser.parse_args()
    cfg  = load_config()

    if args.cmd == "fetch-prices":
        fetch_prices(cfg, args.year, args.month)
    elif args.cmd == "fetch-consumption":
        fetch_consumption(cfg, args.year, args.month)
    elif args.cmd == "fetch-all":
        fetch_prices(cfg, args.year, args.month)
        fetch_consumption(cfg, args.year, args.month)
    elif args.cmd == "import-csv":
        import_csv(cfg, args.file)


if __name__ == "__main__":
    main()
```

- [ ] **Step 4: Run billing tests — verify they pass**

```bash
python -m pytest tests/test_billing.py -v
```

Expected:
```
PASSED tests/test_billing.py::test_billing_formula_basic
PASSED tests/test_billing.py::test_billing_zero_consumption
```

- [ ] **Step 5: Commit**

```bash
git add energie.py tests/test_billing.py
git commit -m "feat: energie.py skeleton with config, DB helpers, billing formula"
```

---

## Task 4: Fetch Spot Prices

**Files:**
- Modify: `/Users/erikr/Git/Energie/energie.py` (add `fetch_prices` function)
- Create: `/Users/erikr/Git/Energie/tests/test_prices.py`

- [ ] **Step 1: Write failing price parsing test**

Create `/Users/erikr/Git/Energie/tests/test_prices.py`:

```python
import sys, os
sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))

def test_parse_spot_json_returns_rows():
    from energie import parse_spot_json
    data = {
        "id": "SPOT",
        "data": [
            {"price": 10.9, "unit": "ct/MWH", "from": "2025-01-01T00:00:00", "to": "2025-01-01T00:15:00"},
            {"price": 11.2, "unit": "ct/MWH", "from": "2025-01-01T00:15:00", "to": "2025-01-01T00:30:00"},
        ]
    }
    rows = parse_spot_json(data)
    assert len(rows) == 2
    assert rows[0]["ts"] == "2025-01-01T00:00:00"
    assert rows[0]["spot_ct"] == 10.9
    assert rows[1]["spot_ct"] == 11.2

def test_parse_spot_json_skips_bad_rows():
    from energie import parse_spot_json
    data = {"data": [{"price": "bad", "from": "2025-01-01T00:00:00"}]}
    rows = parse_spot_json(data)
    assert rows == []
```

- [ ] **Step 2: Run test — verify it fails**

```bash
python -m pytest tests/test_prices.py -v
```

Expected: `ImportError: cannot import name 'parse_spot_json'`

- [ ] **Step 3: Add `parse_spot_json` and `fetch_prices` to energie.py**

Add after the `# ── Billing ──` section and before `# ── CLI placeholder ──`:

```python
# ── Spot Prices ──────────────────────────────────────────────────────────────

import requests


def parse_spot_json(data: dict) -> list[dict]:
    """Extract list of {ts, spot_ct} from raw API response."""
    rows = []
    for row in data.get("data", []):
        try:
            ts  = row["from"]
            ct  = float(row["price"])
            rows.append({"ts": ts, "spot_ct": ct})
        except (KeyError, ValueError):
            continue
    return rows


def fetch_prices(cfg, year: int, month: int):
    url = (
        f"https://www.hofer-grünstrom.at/service/energy-manager/spot-prices"
        f"?year={year}&month={month}"
    )
    print(f"⬇ Fetching spot prices {year}-{month:02d} …")
    resp = requests.get(url, timeout=30)
    resp.raise_for_status()
    data = resp.json()

    # Save raw JSON (preserves existing file convention)
    json_path = os.path.join(os.path.dirname(__file__), f"spotpreise_{year}_{month:02d}.json")
    with open(json_path, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)

    spot_rows = parse_spot_json(data)
    if not spot_rows:
        print(f"⚠ No spot price rows for {year}-{month:02d}")
        return

    conn = get_db(cfg)
    # Build full readings rows: need to join with consumption later.
    # For now just store spot prices in a staging approach:
    # upsert only the spot_ct into existing readings rows, or create
    # skeleton rows that fetch-consumption will fill in.
    # Simpler: upsert spot_ct only; consumed_kwh + cost_brutto updated
    # when consumption arrives. We use a two-phase upsert.
    cur = conn.cursor()
    cur.executemany(
        """INSERT INTO readings (ts, consumed_kwh, spot_ct, cost_brutto)
           VALUES (%(ts)s, 0, %(spot_ct)s, 0)
           ON DUPLICATE KEY UPDATE spot_ct = VALUES(spot_ct)""",
        spot_rows,
    )
    conn.commit()
    cur.close()
    conn.close()
    print(f"✅ Upserted {len(spot_rows)} spot price rows for {year}-{month:02d}")
```

- [ ] **Step 4: Run tests — verify they pass**

```bash
python -m pytest tests/test_prices.py -v
```

Expected: both PASSED.

- [ ] **Step 5: Smoke test against live API**

```bash
python energie.py fetch-prices --year 2026 --month 3
```

Expected: `✅ Upserted 2972 spot price rows for 2026-03`

- [ ] **Step 6: Commit**

```bash
git add energie.py tests/test_prices.py
git commit -m "feat: fetch spot prices from Hofer API and upsert to DB"
```

---

## Task 5: Import Consumption CSV

**Files:**
- Modify: `/Users/erikr/Git/Energie/energie.py` (add `import_csv` + `_compute_and_upsert_consumption`)
- Create: `/Users/erikr/Git/Energie/tests/test_csv.py`

- [ ] **Step 1: Write failing CSV import test**

Create `/Users/erikr/Git/Energie/tests/test_csv.py`:

```python
import sys, os, io
sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))

SAMPLE_CSV = """Datum;von;bis;Verbrauch
01.01.2025;00:00;00:15;0,078
01.01.2025;00:15;00:30;0,093
01.01.2025;00:30;00:45;0,343
"""

def test_parse_consumption_csv():
    from energie import parse_consumption_csv
    rows = parse_consumption_csv(io.StringIO(SAMPLE_CSV))
    assert len(rows) == 3
    assert rows[0]["ts"] == "2025-01-01T00:00:00"
    assert rows[0]["consumed_kwh"] == 0.078
    assert rows[2]["consumed_kwh"] == 0.343

def test_parse_consumption_csv_skips_missing_fields():
    from energie import parse_consumption_csv
    bad = "Datum;von;bis;Verbrauch\n01.01.2025;;;0,1\n"
    rows = parse_consumption_csv(io.StringIO(bad))
    assert rows == []
```

- [ ] **Step 2: Run test — verify it fails**

```bash
python -m pytest tests/test_csv.py -v
```

Expected: `ImportError: cannot import name 'parse_consumption_csv'`

- [ ] **Step 3: Add `parse_consumption_csv`, `_compute_and_upsert_consumption`, and `import_csv` to energie.py**

Add after the `# ── Spot Prices ──` section:

```python
# ── Consumption CSV ──────────────────────────────────────────────────────────


def parse_consumption_csv(fileobj) -> list[dict]:
    """Parse the grid-operator CSV (Datum;von;bis;Verbrauch) into row dicts."""
    reader = csv.DictReader(fileobj, delimiter=";")
    rows = []
    for row in reader:
        row = {k.strip(): v.strip() for k, v in row.items() if k}
        if not row.get("Datum") or not row.get("von") or not row.get("Verbrauch"):
            continue
        try:
            consumed = float(row["Verbrauch"].replace(",", "."))
            parts = row["Datum"].split(".")
            # Handle DD.MM.YYYY or DD.MM.YY
            year = parts[2] if len(parts[2]) == 4 else "20" + parts[2]
            ts = f"{year}-{parts[1].zfill(2)}-{parts[0].zfill(2)}T{row['von']}"
            # Normalise HH:MM → HH:MM:SS
            if ts.count(":") == 1:
                ts += ":00"
            rows.append({"ts": ts, "consumed_kwh": consumed})
        except (IndexError, ValueError):
            continue
    return rows


def _compute_and_upsert_consumption(conn, rows: list[dict]):
    """
    For each consumption row, look up its spot_ct from readings (already upserted
    by fetch_prices), compute cost_brutto using the applicable tariff, and upsert.
    """
    cur = conn.cursor(dictionary=True)
    updated = 0
    for row in rows:
        ts_dt = datetime.fromisoformat(row["ts"])
        cur.execute("SELECT spot_ct FROM readings WHERE ts = %s", (row["ts"],))
        existing = cur.fetchone()
        spot_ct = existing["spot_ct"] if existing else 0.0

        tariff = get_tariff(conn, ts_dt)
        cost = calculate_cost_brutto(row["consumed_kwh"], spot_ct, tariff)

        cur.execute(
            """INSERT INTO readings (ts, consumed_kwh, spot_ct, cost_brutto)
               VALUES (%s, %s, %s, %s)
               ON DUPLICATE KEY UPDATE
                   consumed_kwh = VALUES(consumed_kwh),
                   cost_brutto  = VALUES(cost_brutto)""",
            (row["ts"], row["consumed_kwh"], spot_ct, cost),
        )
        updated += 1
    conn.commit()
    cur.close()
    return updated


def import_csv(cfg, filepath: str):
    print(f"⬇ Importing consumption CSV: {filepath}")
    with open(filepath, newline="", encoding="utf-8-sig") as f:
        rows = parse_consumption_csv(f)
    if not rows:
        print("⚠ No rows parsed from CSV.")
        return
    conn = get_db(cfg)
    n = _compute_and_upsert_consumption(conn, rows)
    rebuild_daily_summary(conn)
    conn.close()
    print(f"✅ Imported {n} consumption rows")
```

- [ ] **Step 4: Run all tests**

```bash
python -m pytest tests/ -v
```

Expected: all 6 tests PASSED.

- [ ] **Step 5: Smoke test with an existing CSV**

```bash
python energie.py import-csv verbrauch_2025.csv
# Note: verbrauch_2025.csv uses the converted format from 1_convert_verbrauch.py
```

Expected: `✅ Imported N consumption rows` + `✅ daily_summary rebuilt`

- [ ] **Step 6: Commit**

```bash
git add energie.py tests/test_csv.py
git commit -m "feat: import consumption CSV and compute billing into DB"
```

---

## Task 6: Playwright Consumption Scraper

**Files:**
- Modify: `/Users/erikr/Git/Energie/energie.py` (add `fetch_consumption`)

> **Note:** The portal is a JavaScript SPA. The selectors below are based on common patterns. If the login or download button is not found, use `page.pause()` to inspect the live DOM (add it temporarily before `page.click()`).

- [ ] **Step 1: Add `fetch_consumption` to energie.py**

Add after `# ── Consumption CSV ──` section:

```python
# ── Playwright Scraper ───────────────────────────────────────────────────────


def fetch_consumption(cfg, year: int, month: int):
    """Scrape monthly consumption CSV from Hofer portal using Playwright."""
    from playwright.sync_api import sync_playwright
    import tempfile

    username  = cfg["hofer"]["username"]
    password  = cfg["hofer"]["password"]
    meter_id  = cfg["hofer"]["meter_id"]
    login_url = "https://www.hofer-grünstrom.at/app/portal/login"
    data_url  = (
        f"https://www.hofer-grünstrom.at/app/portal/energymanager"
        f"/{meter_id}/profile?year={year}&month={month}"
    )

    print(f"⬇ Scraping consumption {year}-{month:02d} via Playwright …")

    with sync_playwright() as pw:
        browser = pw.chromium.launch(headless=True)
        context = browser.new_context(accept_downloads=True)
        page    = context.new_page()

        # 1. Login
        page.goto(login_url, wait_until="networkidle")
        page.fill("input[type='email'], input[name='username'], input[id*='user']", username)
        page.fill("input[type='password']", password)
        page.click("button[type='submit']")
        page.wait_for_load_state("networkidle")

        # 2. Navigate to energy manager profile
        page.goto(data_url, wait_until="networkidle")

        # 3. Click download button — label: "Ausgewählte Daten herunterladen"
        with page.expect_download() as dl_info:
            page.get_by_text("Ausgewählte Daten herunterladen").click()
        download = dl_info.value

        # 4. Save to temp file and parse
        with tempfile.NamedTemporaryFile(suffix=".csv", delete=False) as tmp:
            tmp_path = tmp.name
        download.save_as(tmp_path)

        browser.close()

    print(f"   Downloaded to {tmp_path}")
    conn = get_db(cfg)

    # The downloaded file is the raw grid-operator format — run through converter
    with open(tmp_path, newline="", encoding="utf-8-sig") as f:
        rows = parse_consumption_csv(f)

    if not rows:
        # Try with the raw multi-column format from 1_convert_verbrauch.py
        from energie import _convert_raw_csv
        converted_path = _convert_raw_csv(tmp_path)
        with open(converted_path, newline="", encoding="utf-8") as f:
            rows = parse_consumption_csv(f)

    os.unlink(tmp_path)

    if not rows:
        print(f"⚠ No rows parsed for {year}-{month:02d}")
        return

    n = _compute_and_upsert_consumption(conn, rows)
    rebuild_daily_summary(conn)
    conn.close()
    print(f"✅ Scraped and stored {n} consumption rows for {year}-{month:02d}")


def _convert_raw_csv(input_path: str) -> str:
    """Convert raw grid-operator CSV (multi-column) to normalised 4-column format."""
    import csv as _csv
    output_path = input_path + ".converted.csv"
    with open(input_path, "r", encoding="utf-8") as infile, \
         open(output_path, "w", encoding="utf-8", newline="") as outfile:
        reader = _csv.reader(infile, delimiter=";")
        writer = _csv.writer(outfile, delimiter=";")
        next(reader, None)  # skip original header
        writer.writerow(["Datum", "von", "bis", "Verbrauch"])
        for row in reader:
            while row and row[-1] == "":
                row.pop()
            writer.writerow(row)
    return output_path
```

- [ ] **Step 2: First run — headed mode to verify login works**

Temporarily change `headless=True` to `headless=False` in `fetch_consumption`, run:

```bash
python energie.py fetch-consumption --year 2026 --month 3
```

Watch the browser: does it log in and land on the energy manager page? If login fails, inspect the form field selectors with DevTools and update the `page.fill(...)` calls.

- [ ] **Step 3: Revert to headless and verify**

Change back to `headless=True`. Run again:

```bash
python energie.py fetch-consumption --year 2026 --month 3
```

Expected: `✅ Scraped and stored N consumption rows for 2026-03`

- [ ] **Step 4: Verify data in DB**

```bash
mysql -u energie -p energie -e "SELECT COUNT(*), MIN(ts), MAX(ts) FROM readings WHERE ts >= '2026-03-01';"
```

Expected: ~2976 rows, spanning 2026-03-01 to 2026-03-31.

- [ ] **Step 5: Commit**

```bash
git add energie.py
git commit -m "feat: scrape consumption from Hofer portal via Playwright"
```

---

## Task 7: fetch-all + Historical Backfill

**Files:**
- Modify: `/Users/erikr/Git/Energie/energie.py` (update `fetch-all` to skip if data exists)

- [ ] **Step 1: Add data-existence check to skip already-fetched consumption**

Replace the existing `fetch-all` handler in `main()`:

```python
    elif args.cmd == "fetch-all":
        fetch_prices(cfg, args.year, args.month)
        # Skip consumption scrape if we already have data for this month
        conn = get_db(cfg)
        cur = conn.cursor()
        cur.execute(
            "SELECT COUNT(*) FROM readings WHERE ts >= %s AND ts < %s AND consumed_kwh > 0",
            (f"{args.year}-{args.month:02d}-01", _next_month(args.year, args.month))
        )
        (count,) = cur.fetchone()
        cur.close()
        conn.close()
        if count > 0:
            print(f"ℹ Consumption data already present for {args.year}-{args.month:02d} ({count} rows), skipping scrape.")
        else:
            fetch_consumption(cfg, args.year, args.month)
```

Add helper before `main()`:

```python
def _next_month(year: int, month: int) -> str:
    if month == 12:
        return f"{year + 1}-01-01"
    return f"{year}-{month + 1:02d}-01"
```

- [ ] **Step 2: Backfill historical data (run once)**

```bash
# Import existing JSON data into DB for all years
python -c "
import json, sys
sys.path.insert(0, '.')
from energie import load_config, get_db, _compute_and_upsert_consumption, rebuild_daily_summary
import os

cfg = load_config()
conn = get_db(cfg)

for year in [2024, 2025]:
    fname = f'verbrauch_{year}.json'
    if not os.path.exists(fname):
        continue
    with open(fname) as f:
        data = json.load(f)
    rows = [{'ts': r['from'], 'consumed_kwh': r['consumed']} for r in data['data']]
    n = _compute_and_upsert_consumption(conn, rows)
    print(f'{year}: {n} rows')

rebuild_daily_summary(conn)
conn.close()
"
```

- [ ] **Step 3: Also backfill spot prices for 2024 and 2025**

```bash
for month in $(seq 1 12); do
    python energie.py fetch-prices --year 2024 --month $month
done
for month in $(seq 1 9); do
    python energie.py fetch-prices --year 2025 --month $month
done
```

Then recompute cost_brutto for all readings (spot prices just updated):

```bash
python -c "
from energie import load_config, get_db, rebuild_daily_summary
cfg = load_config()
conn = get_db(cfg)
# Recompute cost_brutto for all readings that have both consumed_kwh and spot_ct
from energie import get_tariff, calculate_cost_brutto
from datetime import datetime
cur = conn.cursor(dictionary=True)
cur.execute('SELECT ts, consumed_kwh, spot_ct FROM readings WHERE consumed_kwh > 0')
rows = cur.fetchall()
updates = []
for r in rows:
    ts_dt = datetime.fromisoformat(str(r['ts']))
    tariff = get_tariff(conn, ts_dt)
    cost = calculate_cost_brutto(float(r['consumed_kwh']), float(r['spot_ct']), tariff)
    updates.append((cost, r['ts']))
cur2 = conn.cursor()
cur2.executemany('UPDATE readings SET cost_brutto = %s WHERE ts = %s', updates)
conn.commit()
rebuild_daily_summary(conn)
conn.close()
print(f'Recomputed {len(updates)} rows')
"
```

- [ ] **Step 4: Verify totals match previous abrechnung_2025.csv**

```bash
mysql -u energie -p energie -e "
SELECT
  YEAR(day) as year,
  SUM(consumed_kwh) as total_kwh,
  SUM(cost_brutto) as total_eur
FROM daily_summary
GROUP BY YEAR(day)
ORDER BY year;"
```

Compare `total_eur` for 2025 against the SUMME row in `abrechnung_2025.csv` (696.63 €).

- [ ] **Step 5: Commit**

```bash
git add energie.py
git commit -m "feat: skip re-scrape if consumption data exists, add next-month helper"
```

---

## Task 8: PHP Foundation — db.php + api.php

**Files:**
- Create: `/opt/homebrew/var/www/energie/` (directory)
- Create: `/opt/homebrew/var/www/energie/db.php`
- Create: `/opt/homebrew/var/www/energie/api.php`
- Create: `/opt/homebrew/var/www/energie/assets/style.css`

- [ ] **Step 1: Create directory structure**

```bash
mkdir -p /opt/homebrew/var/www/energie/assets
```

- [ ] **Step 2: Create db.php**

Create `/opt/homebrew/var/www/energie/db.php`:

```php
<?php
// Shared DB connection — not intended to be accessed directly via web
$config_path = '/Users/erikr/Git/Energie/config.ini';
$cfg = parse_ini_file($config_path, true);
if (!$cfg) {
    http_response_code(500);
    die(json_encode(['error' => 'Config not found']));
}

try {
    $pdo = new PDO(
        "mysql:host={$cfg['db']['host']};dbname={$cfg['db']['database']};charset=utf8mb4",
        $cfg['db']['user'],
        $cfg['db']['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(['error' => $e->getMessage()]));
}
```

- [ ] **Step 3: Create api.php**

Create `/opt/homebrew/var/www/energie/api.php`:

```php
<?php
require_once __DIR__ . '/db.php';
header('Content-Type: application/json');

$type = $_GET['type'] ?? '';

if ($type === 'daily') {
    $date = $_GET['date'] ?? date('Y-m-d', strtotime('-1 day'));
    $stmt = $pdo->prepare(
        "SELECT TIME(ts) AS label, consumed_kwh, cost_brutto
         FROM readings
         WHERE DATE(ts) = ?
         ORDER BY ts"
    );
    $stmt->execute([$date]);
    $rows = $stmt->fetchAll();
    echo json_encode([
        'labels'      => array_column($rows, 'label'),
        'cost'        => array_map('floatval', array_column($rows, 'cost_brutto')),
        'consumption' => array_map('floatval', array_column($rows, 'consumed_kwh')),
    ]);

} elseif ($type === 'weekly') {
    $year = (int)($_GET['year'] ?? date('Y'));
    $week = (int)($_GET['week'] ?? date('W'));
    $stmt = $pdo->prepare(
        "SELECT day AS label, consumed_kwh, cost_brutto, avg_spot_ct
         FROM daily_summary
         WHERE YEAR(day) = ? AND WEEK(day, 3) = ?
         ORDER BY day"
    );
    $stmt->execute([$year, $week]);
    $rows = $stmt->fetchAll();
    echo json_encode([
        'labels'      => array_column($rows, 'label'),
        'cost'        => array_map('floatval', array_column($rows, 'cost_brutto')),
        'consumption' => array_map('floatval', array_column($rows, 'consumed_kwh')),
    ]);

} elseif ($type === 'monthly') {
    $year  = (int)($_GET['year']  ?? date('Y'));
    $month = (int)($_GET['month'] ?? date('m') - 1);
    if ($month < 1) { $month = 12; $year--; }
    $stmt = $pdo->prepare(
        "SELECT day AS label, consumed_kwh, cost_brutto, avg_spot_ct
         FROM daily_summary
         WHERE YEAR(day) = ? AND MONTH(day) = ?
         ORDER BY day"
    );
    $stmt->execute([$year, $month]);
    $rows = $stmt->fetchAll();
    echo json_encode([
        'labels'      => array_column($rows, 'label'),
        'cost'        => array_map('floatval', array_column($rows, 'cost_brutto')),
        'consumption' => array_map('floatval', array_column($rows, 'consumed_kwh')),
    ]);

} else {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown type']);
}
```

- [ ] **Step 4: Create style.css**

Create `/opt/homebrew/var/www/energie/assets/style.css`:

```css
:root {
    --bg: #0f0f1a;
    --surface: #1a1a2e;
    --card: #16213e;
    --accent: #e94560;
    --green: #68d391;
    --blue: #63b3ed;
    --text: #e2e8f0;
    --muted: #718096;
    --border: #2d3748;
}

* { box-sizing: border-box; margin: 0; padding: 0; }

body {
    background: var(--bg);
    color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    min-height: 100vh;
}

header {
    background: var(--surface);
    padding: 1rem 2rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    border-bottom: 1px solid var(--border);
}
header h1 { font-size: 1.25rem; color: var(--text); }
header span { font-size: 1.5rem; }

main { padding: 2rem; max-width: 1100px; margin: 0 auto; }

/* Tiles on overview page */
.tiles { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem; }
.tile {
    background: var(--card);
    border-radius: 12px;
    padding: 1.5rem;
    cursor: pointer;
    text-decoration: none;
    color: var(--text);
    border: 1px solid var(--border);
    transition: border-color .2s;
}
.tile:hover { border-color: var(--accent); }
.tile .icon { font-size: 2rem; margin-bottom: 0.5rem; }
.tile .period { color: var(--muted); font-size: 0.8rem; text-transform: uppercase; letter-spacing: .05em; }
.tile h2 { font-size: 1rem; margin: 0.25rem 0 1rem; }
.tile .kpi { display: flex; flex-direction: column; gap: 0.5rem; }
.tile .kpi-row { display: flex; justify-content: space-between; }
.tile .kpi-label { color: var(--muted); font-size: 0.85rem; }
.tile .kpi-value { font-weight: 600; }
.tile .kpi-value.kwh  { color: var(--green); }
.tile .kpi-value.eur  { color: var(--accent); }
.tile .kpi-value.tariff { color: var(--blue); }

/* Drilldown page */
.nav-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: var(--card);
    border-radius: 8px;
    padding: 0.75rem 1.25rem;
    margin-bottom: 1.5rem;
    border: 1px solid var(--border);
}
.nav-bar a { color: var(--muted); text-decoration: none; font-size: 0.9rem; }
.nav-bar a:hover { color: var(--text); }
.nav-bar .period-label { font-weight: 600; }

.chart-container {
    background: var(--card);
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    border: 1px solid var(--border);
    height: 60vh;
    min-height: 300px;
}

.kpi-strip {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
}
.kpi-card {
    background: var(--card);
    border-radius: 8px;
    padding: 1rem 1.25rem;
    border: 1px solid var(--border);
}
.kpi-card .label { color: var(--muted); font-size: 0.75rem; text-transform: uppercase; letter-spacing: .05em; }
.kpi-card .value { font-size: 1.5rem; font-weight: 700; margin-top: 0.25rem; }
.kpi-card .value.kwh   { color: var(--green); }
.kpi-card .value.eur   { color: var(--accent); }
.kpi-card .value.tariff { color: var(--blue); }

@media (max-width: 600px) {
    .tiles, .kpi-strip { grid-template-columns: 1fr; }
}
```

- [ ] **Step 5: Smoke test api.php**

```bash
curl "http://localhost/energie/api.php?type=daily&date=2026-03-01" | python3 -m json.tool | head -20
```

Expected: JSON with `labels`, `cost`, `consumption` arrays of ~96 items.

- [ ] **Step 6: Commit files to repo as symlinks or copy**

The PHP files live in Apache's docroot, not the git repo. Create symlinks from the repo to the docroot so they're version-controlled:

```bash
# Alternative: keep PHP files in repo and symlink
mkdir -p /Users/erikr/Git/Energie/web
# Copy the files into /Users/erikr/Git/Energie/web/
# and symlink the web dir into Apache docroot
ln -sfn /Users/erikr/Git/Energie/web /opt/homebrew/var/www/energie
```

Or simply keep the PHP files directly in `/opt/homebrew/var/www/energie/` and commit them by adding the path to git:

```bash
cd /Users/erikr/Git/Energie
mkdir -p web
cp /opt/homebrew/var/www/energie/*.php web/
cp /opt/homebrew/var/www/energie/assets/style.css web/assets/
git add web/
git commit -m "feat: PHP dashboard foundation — db.php, api.php, style.css"
```

---

## Task 9: PHP Overview Page

**Files:**
- Create: `/opt/homebrew/var/www/energie/index.php`

- [ ] **Step 1: Create index.php**

```php
<?php
require_once __DIR__ . '/db.php';

// Most recent day with data
$stmt = $pdo->query("SELECT MAX(day) AS latest FROM daily_summary");
$latest = $stmt->fetch()['latest'] ?? date('Y-m-d', strtotime('-1 day'));

// Yesterday tile
$stmt = $pdo->prepare("SELECT consumed_kwh, cost_brutto, avg_spot_ct FROM daily_summary WHERE day = ?");
$stmt->execute([$latest]);
$today = $stmt->fetch() ?: ['consumed_kwh' => 0, 'cost_brutto' => 0, 'avg_spot_ct' => 0];

// Last 7 days tile
$stmt = $pdo->prepare(
    "SELECT SUM(consumed_kwh) AS consumed_kwh, SUM(cost_brutto) AS cost_brutto,
            AVG(avg_spot_ct) AS avg_spot_ct, MIN(day) AS from_day, MAX(day) AS to_day
     FROM daily_summary WHERE day > DATE_SUB(?, INTERVAL 7 DAY)");
$stmt->execute([$latest]);
$week = $stmt->fetch();

// Current ISO week for link
$latest_dt = new DateTime($latest);
$iso_year  = $latest_dt->format('o');
$iso_week  = $latest_dt->format('W');

// Last 30 days tile
$stmt = $pdo->prepare(
    "SELECT SUM(consumed_kwh) AS consumed_kwh, SUM(cost_brutto) AS cost_brutto,
            AVG(avg_spot_ct) AS avg_spot_ct, MONTH(MIN(day)) AS m, YEAR(MIN(day)) AS y
     FROM daily_summary WHERE day > DATE_SUB(?, INTERVAL 30 DAY)");
$stmt->execute([$latest]);
$month = $stmt->fetch();
$prev_month = (int)$month['m'];
$prev_year  = (int)$month['y'];

function fmt_kwh($v) { return number_format($v, 1, ',', '.') . ' kWh'; }
function fmt_eur($v) { return '€ ' . number_format($v, 2, ',', '.'); }
function fmt_ct($v)  { return number_format($v, 1, ',', '.') . ' ct/kWh'; }
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Energie</title>
    <link rel="stylesheet" href="/energie/assets/style.css">
</head>
<body>
<header>
    <span>⚡</span>
    <h1>Energie</h1>
</header>
<main>
    <div class="tiles">

        <a class="tile" href="/energie/daily.php?date=<?= htmlspecialchars($latest) ?>">
            <div class="icon">📅</div>
            <div class="period">Letzter Tag</div>
            <h2><?= date('D, d.m.Y', strtotime($latest)) ?></h2>
            <div class="kpi">
                <div class="kpi-row"><span class="kpi-label">Verbrauch</span><span class="kpi-value kwh"><?= fmt_kwh($today['consumed_kwh']) ?></span></div>
                <div class="kpi-row"><span class="kpi-label">Kosten</span><span class="kpi-value eur"><?= fmt_eur($today['cost_brutto']) ?></span></div>
                <div class="kpi-row"><span class="kpi-label">Ø Tarif</span><span class="kpi-value tariff"><?= fmt_ct($today['avg_spot_ct']) ?></span></div>
            </div>
        </a>

        <a class="tile" href="/energie/weekly.php?year=<?= $iso_year ?>&week=<?= $iso_week ?>">
            <div class="icon">📊</div>
            <div class="period">Letzte 7 Tage</div>
            <h2>KW<?= $iso_week ?> · <?= date('d.m', strtotime($week['from_day'])) ?>–<?= date('d.m.y', strtotime($week['to_day'])) ?></h2>
            <div class="kpi">
                <div class="kpi-row"><span class="kpi-label">Verbrauch</span><span class="kpi-value kwh"><?= fmt_kwh($week['consumed_kwh']) ?></span></div>
                <div class="kpi-row"><span class="kpi-label">Kosten</span><span class="kpi-value eur"><?= fmt_eur($week['cost_brutto']) ?></span></div>
                <div class="kpi-row"><span class="kpi-label">Ø Tarif</span><span class="kpi-value tariff"><?= fmt_ct($week['avg_spot_ct']) ?></span></div>
            </div>
        </a>

        <a class="tile" href="/energie/monthly.php?year=<?= $prev_year ?>&month=<?= $prev_month ?>">
            <div class="icon">📈</div>
            <div class="period">Letzte 30 Tage</div>
            <h2><?= date('F Y', mktime(0,0,0,$prev_month,1,$prev_year)) ?></h2>
            <div class="kpi">
                <div class="kpi-row"><span class="kpi-label">Verbrauch</span><span class="kpi-value kwh"><?= fmt_kwh($month['consumed_kwh']) ?></span></div>
                <div class="kpi-row"><span class="kpi-label">Kosten</span><span class="kpi-value eur"><?= fmt_eur($month['cost_brutto']) ?></span></div>
                <div class="kpi-row"><span class="kpi-label">Ø Tarif</span><span class="kpi-value tariff"><?= fmt_ct($month['avg_spot_ct']) ?></span></div>
            </div>
        </a>

    </div>
</main>
</body>
</html>
```

- [ ] **Step 2: Test in browser**

Open `http://localhost/energie/` — verify three tiles appear with real numbers.

- [ ] **Step 3: Commit**

```bash
cp /opt/homebrew/var/www/energie/index.php /Users/erikr/Git/Energie/web/
git add web/index.php
git commit -m "feat: overview page with daily/weekly/monthly tiles"
```

---

## Task 10: PHP Drilldown Pages

**Files:**
- Create: `/opt/homebrew/var/www/energie/daily.php`
- Create: `/opt/homebrew/var/www/energie/weekly.php`
- Create: `/opt/homebrew/var/www/energie/monthly.php`

All three pages share the same chart layout (big Chart.js combo → KPI strip). A shared `_chart_page.php` include avoids repetition.

- [ ] **Step 1: Create the shared chart page include**

Create `/opt/homebrew/var/www/energie/_chart_page.php`:

```php
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
    <link rel="stylesheet" href="/energie/assets/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
</head>
<body>
<header>
    <span>⚡</span>
    <h1><a href="/energie/" style="color:inherit;text-decoration:none">Energie</a></h1>
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
```

- [ ] **Step 2: Create daily.php**

Create `/opt/homebrew/var/www/energie/daily.php`:

```php
<?php
require_once __DIR__ . '/db.php';

$date = $_GET['date'] ?? date('Y-m-d', strtotime('-1 day'));
$dt   = new DateTime($date);

$prev_url = '/energie/daily.php?date=' . $dt->modify('-1 day')->format('Y-m-d');
$dt->modify('+1 day'); // restore
$next_date = $dt->modify('+1 day')->format('Y-m-d');
$dt->modify('-1 day'); // restore to $date

// Check if next date has data
$stmt = $pdo->prepare("SELECT COUNT(*) FROM readings WHERE DATE(ts) = ?");
$stmt->execute([$next_date]);
$has_next = $stmt->fetchColumn() > 0;
$next_url = $has_next ? '/energie/daily.php?date=' . $next_date : null;

$stmt = $pdo->prepare("SELECT consumed_kwh, cost_brutto, avg_spot_ct FROM daily_summary WHERE day = ?");
$stmt->execute([$date]);
$summary = $stmt->fetch() ?: ['consumed_kwh' => 0, 'cost_brutto' => 0, 'avg_spot_ct' => 0];

$title        = date('d.m.Y', strtotime($date));
$period_label = date('D d.m.Y', strtotime($date));
$api_url      = '/energie/api.php?type=daily&date=' . $date;
$kpi_kwh      = (float)$summary['consumed_kwh'];
$kpi_eur      = (float)$summary['cost_brutto'];
$kpi_ct       = (float)$summary['avg_spot_ct'];

require __DIR__ . '/_chart_page.php';
```

- [ ] **Step 3: Create weekly.php**

Create `/opt/homebrew/var/www/energie/weekly.php`:

```php
<?php
require_once __DIR__ . '/db.php';

$year = (int)($_GET['year'] ?? date('Y'));
$week = (int)($_GET['week'] ?? (int)date('W'));

// Prev/next week
$prev_week = $week - 1; $prev_year = $year;
if ($prev_week < 1) { $prev_week = 52; $prev_year--; }
$next_week = $week + 1; $next_year = $year;
$max_week  = (int)(new DateTime("$year-12-28"))->format('W');
if ($next_week > $max_week) { $next_week = 1; $next_year++; }

// Week date range (ISO Monday–Sunday)
$mon = new DateTime(); $mon->setISODate($year, $week, 1);
$sun = new DateTime(); $sun->setISODate($year, $week, 7);

$prev_url = "/energie/weekly.php?year=$prev_year&week=$prev_week";
$stmt = $pdo->prepare("SELECT COUNT(*) FROM daily_summary WHERE YEAR(day)=? AND WEEK(day,3)=?");
$stmt->execute([$next_year, $next_week]);
$next_url = $stmt->fetchColumn() > 0 ? "/energie/weekly.php?year=$next_year&week=$next_week" : null;

$stmt = $pdo->prepare(
    "SELECT SUM(consumed_kwh) AS kwh, SUM(cost_brutto) AS eur, AVG(avg_spot_ct) AS ct
     FROM daily_summary WHERE YEAR(day)=? AND WEEK(day,3)=?");
$stmt->execute([$year, $week]);
$summary = $stmt->fetch() ?: ['kwh' => 0, 'eur' => 0, 'ct' => 0];

$title        = "KW$week $year";
$period_label = "KW$week · {$mon->format('d.m')}–{$sun->format('d.m.y')}";
$api_url      = "/energie/api.php?type=weekly&year=$year&week=$week";
$kpi_kwh      = (float)$summary['kwh'];
$kpi_eur      = (float)$summary['eur'];
$kpi_ct       = (float)$summary['ct'];

require __DIR__ . '/_chart_page.php';
```

- [ ] **Step 4: Create monthly.php**

Create `/opt/homebrew/var/www/energie/monthly.php`:

```php
<?php
require_once __DIR__ . '/db.php';

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? ((int)date('m') - 1 ?: 12));

$prev_month = $month - 1; $prev_year = $year;
if ($prev_month < 1) { $prev_month = 12; $prev_year--; }
$next_month = $month + 1; $next_year = $year;
if ($next_month > 12) { $next_month = 1; $next_year++; }

$prev_url = "/energie/monthly.php?year=$prev_year&month=$prev_month";
$stmt = $pdo->prepare("SELECT COUNT(*) FROM daily_summary WHERE YEAR(day)=? AND MONTH(day)=?");
$stmt->execute([$next_year, $next_month]);
$next_url = $stmt->fetchColumn() > 0 ? "/energie/monthly.php?year=$next_year&month=$next_month" : null;

$stmt = $pdo->prepare(
    "SELECT SUM(consumed_kwh) AS kwh, SUM(cost_brutto) AS eur, AVG(avg_spot_ct) AS ct
     FROM daily_summary WHERE YEAR(day)=? AND MONTH(day)=?");
$stmt->execute([$year, $month]);
$summary = $stmt->fetch() ?: ['kwh' => 0, 'eur' => 0, 'ct' => 0];

$month_name   = date('F Y', mktime(0, 0, 0, $month, 1, $year));
$title        = $month_name;
$period_label = $month_name;
$api_url      = "/energie/api.php?type=monthly&year=$year&month=$month";
$kpi_kwh      = (float)$summary['kwh'];
$kpi_eur      = (float)$summary['eur'];
$kpi_ct       = (float)$summary['ct'];

require __DIR__ . '/_chart_page.php';
```

- [ ] **Step 5: Test all drilldown pages in browser**

- `http://localhost/energie/daily.php?date=2026-03-01` — 96 bars visible, line overlaid
- `http://localhost/energie/weekly.php?year=2026&week=13` — 7 bars
- `http://localhost/energie/monthly.php?year=2025&month=9` — 30 bars
- Verify prev/next navigation works on all three

- [ ] **Step 6: Commit**

```bash
for f in _chart_page.php daily.php weekly.php monthly.php; do
    cp /opt/homebrew/var/www/energie/$f /Users/erikr/Git/Energie/web/
done
git add web/
git commit -m "feat: daily/weekly/monthly drilldown pages with Chart.js combo charts"
```

---

## Task 11: Slack Briefing

**Files:**
- Modify: `/Users/erikr/Git/Energie/energie.py` (add `SlackNotifier` class and `notify_slack` subcommand)

- [ ] **Step 1: Add SlackNotifier to energie.py**

Add after `# ── Playwright Scraper ──` section:

```python
# ── Slack Notifications ──────────────────────────────────────────────────────

import matplotlib
matplotlib.use("Agg")   # headless backend
import matplotlib.pyplot as plt
import matplotlib.dates as mdates
import tempfile


class SlackNotifier:
    def __init__(self, cfg):
        from slack_sdk import WebClient
        self.client   = WebClient(token=cfg["slack"]["bot_token"])
        self.channel  = cfg["slack"]["channel_id"]

    def _get_daily_summary(self, conn, day: date) -> dict | None:
        cur = conn.cursor(dictionary=True)
        cur.execute("SELECT * FROM daily_summary WHERE day = %s", (day,))
        row = cur.fetchone()
        cur.close()
        return row

    def _get_period_summary(self, conn, from_day: date, to_day: date) -> dict:
        cur = conn.cursor(dictionary=True)
        cur.execute(
            """SELECT SUM(consumed_kwh) AS kwh, SUM(cost_brutto) AS eur,
                      AVG(avg_spot_ct) AS ct
               FROM daily_summary WHERE day BETWEEN %s AND %s""",
            (from_day, to_day),
        )
        row = cur.fetchone()
        cur.close()
        return row or {"kwh": 0, "eur": 0, "ct": 0}

    def _get_daily_rows(self, conn, from_day: date, to_day: date) -> list[dict]:
        cur = conn.cursor(dictionary=True)
        cur.execute(
            "SELECT day, consumed_kwh, cost_brutto FROM daily_summary "
            "WHERE day BETWEEN %s AND %s ORDER BY day",
            (from_day, to_day),
        )
        rows = cur.fetchall()
        cur.close()
        return rows

    def _make_chart(self, rows: list[dict], title: str) -> str:
        """Generate combo chart PNG, return temp file path."""
        days  = [r["day"] for r in rows]
        cost  = [float(r["cost_brutto"]) for r in rows]
        kwh   = [float(r["consumed_kwh"]) for r in rows]

        fig, ax1 = plt.subplots(figsize=(10, 4))
        fig.patch.set_facecolor("#1a1a2e")
        ax1.set_facecolor("#16213e")

        ax1.bar(days, cost, color="#e94560", alpha=0.8, label="Kosten (€)", width=0.6)
        ax1.set_ylabel("Kosten (€)", color="#fc8181")
        ax1.tick_params(axis="y", labelcolor="#fc8181")
        ax1.tick_params(axis="x", colors="#718096")
        ax1.xaxis.set_major_formatter(mdates.DateFormatter("%d.%m"))
        ax1.set_xlabel("")
        for spine in ax1.spines.values():
            spine.set_edgecolor("#2d3748")

        ax2 = ax1.twinx()
        ax2.plot(days, kwh, color="#68d391", linewidth=2, marker="o",
                 markersize=4, label="Verbrauch (kWh)")
        ax2.set_ylabel("Verbrauch (kWh)", color="#68d391")
        ax2.tick_params(axis="y", labelcolor="#68d391")
        ax2.set_facecolor("#16213e")

        # Combined legend
        lines1, labels1 = ax1.get_legend_handles_labels()
        lines2, labels2 = ax2.get_legend_handles_labels()
        ax1.legend(lines1 + lines2, labels1 + labels2,
                   facecolor="#1a1a2e", labelcolor="#e2e8f0", loc="upper left")

        plt.title(title, color="#e2e8f0", pad=10)
        fig.tight_layout()

        tmp = tempfile.NamedTemporaryFile(suffix=".png", delete=False)
        plt.savefig(tmp.name, dpi=150, bbox_inches="tight", facecolor=fig.get_facecolor())
        plt.close(fig)
        return tmp.name

    def post_daily(self, conn, day: date):
        row = self._get_daily_summary(conn, day)
        if not row:
            print(f"⚠ No daily summary for {day}")
            return
        text = (
            f"⚡ *Energie · {day.strftime('%a %d.%m.%Y')}*\n\n"
            f"Verbrauch:  {float(row['consumed_kwh']):.1f} kWh\n"
            f"Kosten:     € {float(row['cost_brutto']):.2f}\n"
            f"Ø Tarif:    {float(row['avg_spot_ct']):.1f} ct/kWh\n\n"
            f"→ http://localhost/energie/daily.php?date={day}"
        )
        self.client.chat_postMessage(channel=self.channel, text=text)
        print(f"✅ Daily Slack briefing posted for {day}")

    def post_weekly(self, conn, iso_year: int, iso_week: int):
        mon = date.fromisocalendar(iso_year, iso_week, 1)
        sun = date.fromisocalendar(iso_year, iso_week, 7)
        summary = self._get_period_summary(conn, mon, sun)
        rows    = self._get_daily_rows(conn, mon, sun)
        if not rows:
            print(f"⚠ No weekly data for {iso_year}-W{iso_week:02d}")
            return

        chart_path = self._make_chart(
            rows, f"KW{iso_week:02d} · {mon.strftime('%d.%m')}–{sun.strftime('%d.%m.%y')}"
        )
        text = (
            f"⚡ *Energie · KW{iso_week:02d} {iso_year}* "
            f"({mon.strftime('%d.%m')}–{sun.strftime('%d.%m.%y')})\n\n"
            f"Verbrauch:  {float(summary['kwh']):.1f} kWh\n"
            f"Kosten:     € {float(summary['eur']):.2f}\n"
            f"Ø Tarif:    {float(summary['ct']):.1f} ct/kWh\n\n"
            f"→ http://localhost/energie/weekly.php?year={iso_year}&week={iso_week}"
        )
        self.client.files_upload_v2(
            channel=self.channel, file=chart_path,
            initial_comment=text, filename=f"energie-kw{iso_week:02d}.png"
        )
        os.unlink(chart_path)
        print(f"✅ Weekly Slack briefing posted for {iso_year}-W{iso_week:02d}")

    def post_monthly(self, conn, year: int, month: int):
        from_day = date(year, month, 1)
        # Last day of month
        if month == 12:
            to_day = date(year, 12, 31)
        else:
            to_day = date(year, month + 1, 1) - timedelta(days=1)
        summary = self._get_period_summary(conn, from_day, to_day)
        rows    = self._get_daily_rows(conn, from_day, to_day)
        if not rows:
            print(f"⚠ No monthly data for {year}-{month:02d}")
            return

        month_name = from_day.strftime("%B %Y")
        chart_path = self._make_chart(rows, month_name)
        text = (
            f"⚡ *Energie · {month_name}*\n\n"
            f"Verbrauch:  {float(summary['kwh']):.1f} kWh\n"
            f"Kosten:     € {float(summary['eur']):.2f}\n"
            f"Ø Tarif:    {float(summary['ct']):.1f} ct/kWh\n\n"
            f"→ http://localhost/energie/monthly.php?year={year}&month={month}"
        )
        self.client.files_upload_v2(
            channel=self.channel, file=chart_path,
            initial_comment=text, filename=f"energie-{year}-{month:02d}.png"
        )
        os.unlink(chart_path)
        print(f"✅ Monthly Slack briefing posted for {year}-{month:02d}")
```

- [ ] **Step 2: Add notify subcommand to main()**

Add to the `sub = parser.add_subparsers(...)` block in `main()`:

```python
    sub.add_parser("notify")   # posts appropriate briefings for today
```

Add handler in the `if args.cmd == ...` chain:

```python
    elif args.cmd == "notify":
        today = date.today()
        conn  = get_db(cfg)
        notifier = SlackNotifier(cfg)

        # Daily: most recent available day
        cur = conn.cursor()
        cur.execute("SELECT MAX(day) FROM daily_summary")
        (latest,) = cur.fetchone()
        cur.close()
        if latest:
            notifier.post_daily(conn, latest)

        # Weekly: every Tuesday (weekday 1)
        if today.weekday() == 1:
            # Report on the just-completed week (Mon–Sun ending last Sunday)
            last_sun = today - timedelta(days=today.weekday() + 1)
            iso_year, iso_week, _ = last_sun.isocalendar()
            notifier.post_weekly(conn, iso_year, iso_week)

        # Monthly: every 2nd of the month
        if today.day == 2:
            prev_month = today.month - 1 or 12
            prev_year  = today.year if today.month > 1 else today.year - 1
            notifier.post_monthly(conn, prev_year, prev_month)

        conn.close()
```

- [ ] **Step 3: Smoke test notify (daily only)**

```bash
python energie.py notify
```

Expected: `✅ Daily Slack briefing posted for YYYY-MM-DD` appears in `#dailybriefing`.

- [ ] **Step 4: Test weekly chart generation (without Slack)**

```bash
python -c "
from energie import load_config, get_db, SlackNotifier
from datetime import date
cfg  = load_config()
conn = get_db(cfg)
n    = SlackNotifier(cfg)
path = n._make_chart(n._get_daily_rows(conn, date(2026,3,1), date(2026,3,7)), 'Test KW10')
print('Chart saved to:', path)
conn.close()
"
```

Open the resulting PNG file and verify the chart looks correct.

- [ ] **Step 5: Commit**

```bash
git add energie.py
git commit -m "feat: Slack daily/weekly/monthly briefings with matplotlib charts"
```

---

## Task 12: Scheduled Agent

**Files:** (Claude Code schedule configuration only — no Python changes)

- [ ] **Step 1: Confirm the full path to energie.py**

```bash
echo /Users/erikr/Git/Energie/energie.py
python3 /Users/erikr/Git/Energie/energie.py --help
```

Expected: subcommand list printed without errors.

- [ ] **Step 2: Create the scheduled agent**

Use the `schedule` skill to create a daily trigger. In Claude Code, run:

```
/schedule
```

When prompted, configure:
- **Schedule**: `15 14 * * *` (14:15 every day)
- **Task**: `Run python3 /Users/erikr/Git/Energie/energie.py fetch-all and then python3 /Users/erikr/Git/Energie/energie.py notify. Log output to /Users/erikr/Git/Energie/energie.log with timestamp.`

- [ ] **Step 3: Verify the schedule was created**

```
/schedule list
```

Expected: one entry for the energie pipeline at 14:15 daily.

- [ ] **Step 4: Do a manual dry-run**

```bash
python3 /Users/erikr/Git/Energie/energie.py fetch-all 2>&1 | tee -a /Users/erikr/Git/Energie/energie.log
python3 /Users/erikr/Git/Energie/energie.py notify    2>&1 | tee -a /Users/erikr/Git/Energie/energie.log
```

Expected: prices fetched, Slack message in `#dailybriefing`, no errors in log.

- [ ] **Step 5: Commit**

```bash
git add energie.log  # may be empty — just to confirm it's gitignored
git status           # should NOT show energie.log as untracked (gitignore check)
git commit -m "feat: complete energie pipeline with scheduling and Slack notifications"
```

---

## Self-Review

### Spec coverage

| Spec requirement | Task |
|---|---|
| Merged single Python script | Tasks 3–7, 11 |
| MariaDB with readings / daily_summary / tariff_config | Task 2 |
| Playwright consumption scraper | Task 6 |
| Spot price fetch from Hofer API | Task 4 |
| `import-csv` manual fallback | Task 5 |
| Billing formula with tariff lookup | Task 3 |
| PHP overview page with 3 tiles | Task 9 |
| Daily / weekly / monthly drilldown with Chart.js | Task 10 |
| Prev/next navigation on drilldown pages | Task 10 |
| api.php JSON endpoint | Task 8 |
| Slack daily text briefing | Task 11 |
| Slack weekly/monthly with matplotlib chart | Task 11 |
| Claude Code scheduled agent at 14:15 | Task 12 |
| config.ini.example committed, config.ini gitignored | Task 1 |
| Tariff rows with valid_from for rate changes | Task 2 |

All spec requirements covered. ✅

### Type consistency check

- `calculate_cost_brutto(consumed_kwh, spot_ct, tariff)` — defined Task 3, used Task 5 ✅
- `parse_consumption_csv(fileobj)` — defined Task 5, used Task 6 ✅
- `_compute_and_upsert_consumption(conn, rows)` — defined Task 5, used Tasks 5, 6 ✅
- `rebuild_daily_summary(conn)` — defined Task 3, used Tasks 5, 6, 7 ✅
- `get_tariff(conn, ts_dt)` — defined Task 3, used Task 5 ✅
- `SlackNotifier(cfg)` methods — all defined and called within Task 11 ✅
- PHP `fmt_kwh / fmt_eur / fmt_ct` — defined in `_chart_page.php`, also defined locally in `index.php` (acceptable since index.php doesn't include `_chart_page.php`) ✅
