# Data Pipeline

## Overview

`energie.py` is the sole entry point for all data ingestion. It is a self-contained Python CLI with no web dependencies — it can run from cron, a shell script, or interactively.

All operations are idempotent: every insert uses `ON DUPLICATE KEY UPDATE`, so re-running a command with the same data is safe.

---

## CLI Reference

### `fetch-prices`

```bash
python energie.py fetch-prices [--year YYYY] [--month M]
# Default: current year and month
```

1. GET `https://www.hofer-grünstrom.at/service/energy-manager/spot-prices?year=Y&month=M`
2. Saves raw response to `spotpreise_YYYY_MM.json`
3. Parses `data[].from` and `data[].price` → list of `{ts, spot_ct}`
4. Upserts into `readings` as stubs: `consumed_kwh = 0`, `cost_brutto = 0`, `spot_ct = fetched`
5. **Archives** `spotpreise_YYYY_MM.json` → `_Archiv/`

Spot prices are always hourly (one row per hour). The grid's consumption data is quarter-hourly, so four `readings` rows share the same `spot_ct` value.

### `import-csv`

```bash
python energie.py import-csv <file.csv|file.xlsx>
```

1. Detects file format by extension
2. Parses all rows into `{ts, consumed_kwh}` dicts
3. For each row: looks up existing `spot_ct` from `readings`, fetches applicable tariff, computes `cost_brutto`
4. Upserts `consumed_kwh` and `cost_brutto` into `readings`
5. Calls `rebuild_daily_summary()`
6. **Archives** the source file → `_Archiv/`

Accepts two CSV formats (auto-detected from column headers):

| Format | Columns | Date | Time |
|---|---|---|---|
| Old export | `Datum;von;bis;Verbrauch` | `DD.MM.YY` | `HH:MM` |
| QuarterHourValues | `Datum;Zeit von;Zeit bis;<meter-id> - Verbrauch [kWh]` | `DD.MM.YYYY` | `HH:MM:SS` |

### `fetch-all`

```bash
python energie.py fetch-all [--year YYYY] [--month M]
```

Combines both operations in sequence:
1. `fetch-prices --year Y --month M`
2. Globs `scrapes/QuarterHourValues-*.csv` and calls `import-csv` on each file found

Files in `scrapes/` are archived after a successful import. If no CSV is found, a warning is printed and the command continues without error (spot prices still get updated).

**Typical cron usage:**
```cron
# Daily at 06:00 — fetch today's spot prices, import any pending CSV
0 6 * * * cd /path/to/Energie && python energie.py fetch-all
```

### `notify`

```bash
python energie.py notify
```

Posts Slack briefings using `matplotlib` charts:
- **Daily** — always; uses most recent `daily_summary` row
- **Weekly** — only on Tuesdays; reports the just-completed Mon–Sun week
- **Monthly** — only on the 2nd of the month; reports the previous month

Requires `[slack]` section in `config.ini` with a valid `bot_token` and `channel_id`.

---

## Pricing Formula

The gross cost per 15-minute slot is calculated by `calculate_cost_brutto(consumed_kwh, spot_ct, tariff)`.

### Parameters (from `tariff_config`)

| Column | Description | Unit |
|---|---|---|
| `provider_surcharge_ct` | Hofer markup on top of EPEX spot | ct/kWh |
| `electricity_tax_ct` | Elektrizitätsabgabe (federal electricity levy) | ct/kWh |
| `renewable_tax_ct` | Erneuerbaren-Förderbeitrag (renewables surcharge) | ct/kWh |
| `meter_fee_eur` | Zählergebühr (annual meter rental) | €/yr |
| `renewable_fee_eur` | Erneuerbaren-Förderpauschale (annual renewables flat fee) | €/yr |
| `consumption_tax_rate` | Gebrauchsabgabe Wien (Vienna consumption tax) | fraction, e.g. `0.07` |
| `vat_rate` | Umsatzsteuer (VAT) | fraction, e.g. `0.20` |
| `yearly_kwh_estimate` | Annual consumption estimate for fee amortisation | kWh |

### Formula

```
annual_ct  = (meter_fee_eur + renewable_fee_eur) / yearly_kwh_estimate × 100

net_ct     = spot_ct
           + provider_surcharge_ct
           + electricity_tax_ct
           + renewable_tax_ct
           + annual_ct

gross_ct   = net_ct × (1 + vat_rate + consumption_tax_rate)

cost_eur   = consumed_kwh × gross_ct / 100
```

**Critical:** VAT and the Gebrauchsabgabe are additive, not compounding. The Gebrauchsabgabe is not subject to VAT under Austrian law. The formula applies both as a flat multiplier: `(1 + 0.20 + 0.07) = 1.27`, not `1.20 × 1.07 = 1.284`.

### Tariff history

The `tariff_config` table is versioned by `valid_from` date. The pipeline always looks up the tariff row with the largest `valid_from ≤ ts`, so historical data is never re-priced incorrectly when a new tariff period starts.

Notable changes in the dataset:

| Period | Change |
|---|---|
| 2020-01-01 | Baseline: all levies active, Gebrauchsabgabe 60% |
| 2022-01-01 | Electricity tax + renewables surcharge waived (government relief) |
| 2025-01-01 | Renewables surcharge reinstated |
| 2026-01-01 | Electricity tax reinstated; Gebrauchsabgabe normalised to 6% |
| 2026-03-01 | Gebrauchsabgabe raised to 7% (Vienna municipal budget) |

---

## File Lifecycle

```
scrapes/                     ← user drops files here
  QuarterHourValues-*.csv
  
  import-csv / fetch-all
        ↓  (after successful import)
  
_Archiv/                     ← permanent record
  QuarterHourValues-*.csv
  spotpreise_YYYY_MM.json
  *.xlsx                      (manual imports)
```

Files are moved (not copied) using `shutil.move`. If parsing produces zero rows, the file is not archived — it stays in place with a warning so the problem can be investigated.

---

## Database Functions

### `rebuild_daily_summary(conn)`

Recomputes the entire `daily_summary` table from `readings`:

```sql
INSERT INTO daily_summary (day, consumed_kwh, cost_brutto, avg_spot_ct)
SELECT DATE(ts), SUM(consumed_kwh), SUM(cost_brutto), AVG(spot_ct)
FROM readings
GROUP BY DATE(ts)
ON DUPLICATE KEY UPDATE
    consumed_kwh = VALUES(consumed_kwh),
    cost_brutto  = VALUES(cost_brutto),
    avg_spot_ct  = VALUES(avg_spot_ct)
```

This is a full recompute — intentionally so. Since spot prices and consumption for a given day may be imported in separate operations, any partial state is corrected on the next rebuild. The affected-rows count is printed to stdout.

### `get_tariff(conn, ts)`

```sql
SELECT * FROM tariff_config
WHERE valid_from <= :date
ORDER BY valid_from DESC LIMIT 1
```

Returns the tariff row applicable at timestamp `ts`. Exits with an error if no tariff is found (the table must have at least one row covering the start of the dataset).

### `upsert_readings(conn, rows)`

Bulk upsert used by `fetch-prices` for spot price stubs. Consumption import uses a per-row upsert (to allow per-slot tariff lookup) via `_compute_and_upsert_consumption`.
