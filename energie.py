#!/usr/bin/env python3
"""Energie pipeline: fetch spot prices + consumption, store in MariaDB."""

import argparse
import configparser
import csv
import decimal
import glob
import json
import os
import shutil
import sys
from datetime import datetime, date, timedelta

import mysql.connector
import requests

# ── Config ──────────────────────────────────────────────────────────────────

CONFIG_PATH = os.path.join(os.path.dirname(__file__), "config.ini")
ARCHIV_DIR  = os.path.join(os.path.dirname(__file__), "_Archiv")


def load_config(path=None):
    resolved = path or CONFIG_PATH
    cfg = configparser.ConfigParser()
    if not cfg.read(resolved):
        sys.exit(f"❌ Config not found at {resolved}")
    return cfg


# ── DB ───────────────────────────────────────────────────────────────────────

def get_db(cfg):
    kwargs = dict(
        user=cfg["db"]["user"],
        password=cfg["db"]["password"],
        database=cfg["db"]["database"],
        charset="utf8mb4",
    )
    if cfg["db"].get("socket"):
        kwargs["unix_socket"] = cfg["db"]["socket"]
    else:
        kwargs["host"] = cfg["db"]["host"]
    return mysql.connector.connect(**kwargs)


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
    """
    Compute gross electricity cost for one quarter-hour slot.

    All per-kWh values in ct/kWh; annual fees in €/yr.
    Returns cost in €.

    Formula (VAT does NOT apply to consumption tax / Gebrauchsabgabe):
        net_ct  = epex + provider_surcharge + electricity_tax + renewable_tax
                  + (meter_fee_eur + renewable_fee_eur) / yearly_kwh_estimate * 100
        gross_ct = net_ct * (1 + vat_rate) + net_ct * consumption_tax_rate
        cost_eur = consumed_kwh * gross_ct / 100
    """
    annual_ct = (
        (tariff["meter_fee_eur"] + tariff["renewable_fee_eur"])
        / tariff["yearly_kwh_estimate"]
        * 100
    )
    net_ct = (
        spot_ct
        + tariff["provider_surcharge_ct"]
        + tariff["electricity_tax_ct"]
        + tariff["renewable_tax_ct"]
        + annual_ct
    )
    gross_ct = net_ct * (1 + tariff["vat_rate"] + tariff["consumption_tax_rate"])
    return consumed_kwh * gross_ct / 100


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

    # Save raw JSON
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

    dest = os.path.join(ARCHIV_DIR, os.path.basename(json_path))
    shutil.move(json_path, dest)
    print(f"📦 Archived → {dest}")


# ── Consumption CSV ──────────────────────────────────────────────────────────


def parse_consumption_csv(fileobj) -> list[dict]:
    """Parse grid-operator CSV into row dicts.

    Handles two formats:
    - Old export:  Datum;von;bis;Verbrauch
    - QuarterHourValues: Datum;Zeit von;Zeit bis;<meter-id> - Verbrauch [kWh]
    """
    reader = csv.DictReader(fileobj, delimiter=";")
    raw_fields = [f.strip() for f in (reader.fieldnames or []) if f]

    time_col = "von" if "von" in raw_fields else "Zeit von"
    if "Verbrauch" in raw_fields:
        kwh_col = "Verbrauch"
    else:
        kwh_col = next((f for f in raw_fields if "Verbrauch" in f or "kWh" in f), None)

    if not kwh_col:
        return []

    rows = []
    for row in reader:
        row = {k.strip(): v.strip() for k, v in row.items() if k}
        if not row.get("Datum") or not row.get(time_col) or not row.get(kwh_col):
            continue
        try:
            consumed = float(row[kwh_col].replace(",", "."))
            parts = row["Datum"].split(".")
            # Handle DD.MM.YYYY or DD.MM.YY
            year = parts[2] if len(parts[2]) == 4 else "20" + parts[2]
            time_val = row[time_col]
            ts = f"{year}-{parts[1].zfill(2)}-{parts[0].zfill(2)}T{time_val}"
            # Normalise HH:MM → HH:MM:SS
            if time_val.count(":") == 1:
                ts += ":00"
            rows.append({"ts": ts, "consumed_kwh": consumed})
        except (IndexError, ValueError):
            continue
    return rows


def _compute_and_upsert_consumption(conn, rows: list[dict]) -> tuple[int, int]:
    """
    For each consumption row, look up its spot_ct from readings,
    compute cost_brutto using the applicable tariff, and upsert.
    Returns (inserted, total) where inserted counts only new rows
    (rowcount == 1 means INSERT; rowcount 0 or 2 means existing row updated/unchanged).
    """
    cur = conn.cursor(dictionary=True)
    inserted = 0
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
        if cur.rowcount == 1:
            inserted += 1
    conn.commit()
    cur.close()
    return inserted, len(rows)


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
    filepath = os.path.abspath(filepath)
    print(f"⬇ Importing consumption file: {filepath}")
    if filepath.lower().endswith(".xlsx"):
        rows = parse_consumption_xlsx(filepath)
    else:
        with open(filepath, newline="", encoding="utf-8-sig") as f:
            rows = parse_consumption_csv(f)
    if not rows:
        print("⚠ No rows parsed — check encoding, date format, or column layout.", file=sys.stderr)
        sys.exit(1)
    conn = get_db(cfg)
    try:
        inserted, total = _compute_and_upsert_consumption(conn, rows)
        rebuild_daily_summary(conn)
    finally:
        conn.close()
    print(f"✅ Imported {inserted} new, {total - inserted} existing, {total} total consumption rows")

    dest = os.path.join(ARCHIV_DIR, os.path.basename(filepath))
    shutil.move(filepath, dest)
    print(f"📦 Archived → {dest}")


# ── Slack Notifications ──────────────────────────────────────────────────────

import matplotlib
matplotlib.use("Agg")   # headless backend
import matplotlib.pyplot as plt
import matplotlib.dates as mdates
import tempfile


class SlackNotifier:
    def __init__(self, cfg):
        import ssl, certifi
        from slack_sdk import WebClient
        ssl_ctx = ssl.create_default_context(cafile=certifi.where())
        self.client   = WebClient(token=cfg["slack"]["bot_token"], ssl=ssl_ctx)
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
        try:
            self.client.files_upload_v2(
                channel=self.channel, file=chart_path,
                initial_comment=text, filename=f"energie-kw{iso_week:02d}.png"
            )
        finally:
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
        try:
            self.client.files_upload_v2(
                channel=self.channel, file=chart_path,
                initial_comment=text, filename=f"energie-{year}-{month:02d}.png"
            )
        finally:
            os.unlink(chart_path)
        print(f"✅ Monthly Slack briefing posted for {year}-{month:02d}")


# ── Helpers ──────────────────────────────────────────────────────────────────

# ── CLI ─────────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description="Energie pipeline")
    parser.add_argument("--config", metavar="PATH", default=None,
                        help="Config file path (default: config.ini next to this script)")
    sub = parser.add_subparsers(dest="cmd", required=True)

    p = sub.add_parser("fetch-prices")
    p.add_argument("--year",  type=int, default=datetime.now().year)
    p.add_argument("--month", type=int, default=datetime.now().month)

    p = sub.add_parser("fetch-all")
    p.add_argument("--year",  type=int, default=datetime.now().year)
    p.add_argument("--month", type=int, default=datetime.now().month)

    p = sub.add_parser("import-csv")
    p.add_argument("file")

    sub.add_parser("notify")

    args = parser.parse_args()
    cfg  = load_config(args.config)

    if args.cmd == "fetch-prices":
        fetch_prices(cfg, args.year, args.month)
    elif args.cmd == "fetch-all":
        fetch_prices(cfg, args.year, args.month)
        scrapes_dir = os.path.join(os.path.dirname(__file__), "scrapes")
        csv_files = sorted(glob.glob(os.path.join(scrapes_dir, "QuarterHourValues-*.csv")))
        if not csv_files:
            print("⚠ No QuarterHourValues-*.csv found in scrapes/. Skipping consumption import.")
        else:
            for path in csv_files:
                import_csv(cfg, path)
    elif args.cmd == "import-csv":
        import_csv(cfg, args.file)
    elif args.cmd == "notify":
        today = date.today()
        conn  = get_db(cfg)
        notifier = SlackNotifier(cfg)
        try:
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
        finally:
            conn.close()


if __name__ == "__main__":
    main()
