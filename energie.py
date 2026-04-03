#!/usr/bin/env python3
"""Energie pipeline: fetch spot prices + consumption, store in MariaDB."""

import argparse
import configparser
import csv
import decimal
import json
import os
import sys
from datetime import datetime, date, timedelta

import mysql.connector
import requests

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
    affected = cur.rowcount
    cur.close()
    print(f"✅ daily_summary rebuilt: {affected} rows affected")


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


# ── Spot Prices ──────────────────────────────────────────────────────────────


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
    try:
        cur = conn.cursor()
        cur.executemany(
            """INSERT INTO readings (ts, consumed_kwh, spot_ct, cost_brutto)
               VALUES (%(ts)s, 0, %(spot_ct)s, 0)
               ON DUPLICATE KEY UPDATE spot_ct = VALUES(spot_ct)""",
            spot_rows,
        )
        conn.commit()
        cur.close()
    finally:
        conn.close()
    print(f"✅ Upserted {len(spot_rows)} spot price rows for {year}-{month:02d}")


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
            if row["von"].count(":") == 1:
                ts += ":00"
            rows.append({"ts": ts, "consumed_kwh": consumed})
        except (IndexError, ValueError):
            continue
    return rows


def _compute_and_upsert_consumption(conn, rows: list[dict]) -> int:
    """
    For each consumption row, look up its spot_ct from readings,
    compute cost_brutto using the applicable tariff, and upsert.
    Returns the number of rows processed.
    """
    cur = conn.cursor(dictionary=True)
    updated = 0
    for row in rows:
        ts_dt = datetime.fromisoformat(row["ts"])
        cur.execute("SELECT spot_ct FROM readings WHERE ts = %s", (row["ts"],))
        existing = cur.fetchone()
        spot_ct = float(existing["spot_ct"]) if existing else 0.0

        raw_tariff = get_tariff(conn, ts_dt)
        tariff = {k: float(v) if isinstance(v, decimal.Decimal) else v
                  for k, v in raw_tariff.items()}
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


def parse_consumption_xlsx(filepath: str) -> list[dict]:
    """Parse the Hofer portal XLSX download into row dicts."""
    import openpyxl
    import warnings
    rows = []
    with warnings.catch_warnings():
        warnings.simplefilter("ignore")   # suppress openpyxl stylesheet warnings
        wb = openpyxl.load_workbook(filepath, data_only=True)
    ws = wb.active
    for row in ws.iter_rows(min_row=4, values_only=True):   # skip 3 header rows
        dt = row[0]
        consumed = row[2]
        if dt is None or consumed is None:
            continue
        try:
            ts = dt.strftime("%Y-%m-%dT%H:%M:%S")
            rows.append({"ts": ts, "consumed_kwh": float(consumed)})
        except (AttributeError, ValueError):
            continue
    return rows


def import_csv(cfg, filepath: str):
    print(f"⬇ Importing consumption CSV: {filepath}")
    with open(filepath, newline="", encoding="utf-8-sig") as f:
        rows = parse_consumption_csv(f)
    if not rows:
        print("⚠ No rows parsed from CSV.")
        return
    conn = get_db(cfg)
    try:
        n = _compute_and_upsert_consumption(conn, rows)
        rebuild_daily_summary(conn)
    finally:
        conn.close()
    print(f"✅ Imported {n} consumption rows")


# ── Playwright Scraper ───────────────────────────────────────────────────────


def fetch_consumption(cfg, year: int, month: int):
    """Scrape monthly consumption XLSX from Hofer portal using Playwright."""
    from playwright.sync_api import sync_playwright
    import tempfile

    username  = cfg["hofer"]["username"]
    password  = cfg["hofer"]["password"]
    meter_id  = cfg["hofer"]["meter_id"]
    login_url = "https://www.hofer-grünstrom.at/steward/signin.html"
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
        # Try common selectors for email/password — adjust if login fails
        page.locator("input[type='email'], input[name='email'], input[name='username'], input[id*='email'], input[id*='user']").first.fill(username)
        page.locator("input[type='password']").fill(password)
        page.locator("button[type='submit'], button:has-text('Anmelden'), button:has-text('Login'), input[type='submit']").first.click()
        page.wait_for_load_state("networkidle")

        # 2. Navigate to energy manager profile for the requested month
        page.goto(data_url, wait_until="networkidle")

        # 3. Dismiss cookie consent popup if present (blocks click in headless)
        try:
            page.locator("#ppms_cm_agree-to-all").click(timeout=5000)
            page.wait_for_selector("#ppms_cm_popup_overlay", state="hidden", timeout=10000)
        except Exception:
            pass  # popup not present or already dismissed

        # 4. Click download button
        with page.expect_download() as dl_info:
            page.get_by_text("Ausgewählte Daten herunterladen").click()
        download = dl_info.value

        # 4. Save to temp file
        suffix = ".xlsx"
        with tempfile.NamedTemporaryFile(suffix=suffix, delete=False) as tmp:
            tmp_path = tmp.name
        download.save_as(tmp_path)
        browser.close()

    print(f"   Downloaded: {download.suggested_filename} → {tmp_path}")

    rows = parse_consumption_xlsx(tmp_path)
    os.unlink(tmp_path)

    if not rows:
        print(f"⚠ No rows parsed for {year}-{month:02d}")
        return

    conn = get_db(cfg)
    try:
        n = _compute_and_upsert_consumption(conn, rows)
        rebuild_daily_summary(conn)
    finally:
        conn.close()
    print(f"✅ Scraped and stored {n} consumption rows for {year}-{month:02d}")


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

    sub.add_parser("notify")

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
    elif args.cmd == "notify":
        pass  # implemented in Task 11


if __name__ == "__main__":
    main()
