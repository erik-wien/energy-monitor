# Database

## Overview

Two databases are used:

| Database | Owner | Purpose |
|---|---|---|
| `energie` | production | All application data |
| `energie_dev` | development | Mirror of production, refreshed on deploy |
| `jardyx_auth` | shared | User accounts, managed by `erikr/auth` library |

The `energie` user has `SELECT, INSERT, UPDATE` on `energie.*` and `ALL` on `energie_dev.*`. The auth database is accessed by a separate user defined in `[auth]` config.

---

## Tables

### `readings`

The core fact table. One row per 15-minute consumption slot.

| Column | Type | Description |
|---|---|---|
| `ts` | `datetime` PK | Slot start timestamp (ISO 8601, second precision) |
| `consumed_kwh` | `decimal(8,4)` | Energy consumed in this slot (kWh) |
| `spot_ct` | `decimal(8,4)` | EPEX spot price for this hour (ct/kWh) |
| `cost_brutto` | `decimal(10,6)` | Gross cost for this slot (€) |

**Notes:**
- Rows are written in two passes: `fetch-prices` inserts stubs with `consumed_kwh = 0` and `cost_brutto = 0`; `import-csv` fills in the consumption and cost fields via `ON DUPLICATE KEY UPDATE`.
- Spot prices are hourly; consumption is quarter-hourly. Four `readings` rows within the same hour share the same `spot_ct`.
- `cost_brutto` can be negative when the EPEX spot price is negative (common during high solar/wind periods), meaning the customer earns a rebate.

### `daily_summary`

Aggregated view per calendar day. Rebuilt from `readings` after every import.

| Column | Type | Description |
|---|---|---|
| `day` | `date` PK | Calendar date |
| `consumed_kwh` | `decimal(8,3)` | Total consumption for the day (kWh) |
| `cost_brutto` | `decimal(8,4)` | Total gross cost for the day (€) |
| `avg_spot_ct` | `decimal(8,4)` | Average spot price across all slots (ct/kWh) |

**Notes:**
- This table is the primary source for all web UI queries. Direct queries against `readings` are only made for the daily drilldown chart and for historical aggregations.
- `avg_spot_ct` is the average of all 96 slot prices (including zero-consumption hours), giving the true average market price for the day rather than a consumption-weighted average.
- Rebuilt by `rebuild_daily_summary()` using a full `INSERT … ON DUPLICATE KEY UPDATE`. This is safe to run repeatedly.

### `tariff_config`

Versioned tariff parameters. One row per tariff period.

| Column | Type | Default | Description |
|---|---|---|---|
| `valid_from` | `date` PK | — | Start date of this tariff period |
| `provider_surcharge_ct` | `decimal(8,4)` | 0 | Hofer markup above EPEX (ct/kWh) |
| `electricity_tax_ct` | `decimal(8,4)` | 0 | Elektrizitätsabgabe (ct/kWh) |
| `renewable_tax_ct` | `decimal(8,4)` | 0 | Erneuerbaren-Förderbeitrag (ct/kWh) |
| `meter_fee_eur` | `decimal(8,4)` | 0 | Annual meter rental (€/yr) |
| `renewable_fee_eur` | `decimal(8,4)` | 0 | Annual renewables flat fee (€/yr) |
| `consumption_tax_rate` | `decimal(6,4)` | 0 | Vienna Gebrauchsabgabe (fraction, e.g. 0.07) |
| `vat_rate` | `decimal(6,4)` | 0 | VAT (fraction, e.g. 0.20) |
| `yearly_kwh_estimate` | `decimal(10,2)` | 3000 | Annual kWh estimate for fee amortisation |

Tariff lookup: `SELECT * FROM tariff_config WHERE valid_from <= :date ORDER BY valid_from DESC LIMIT 1`. This means a new row takes effect for all data from its `valid_from` date forward. Historical records are never re-priced unless the tariff row itself is edited.

**Current tariff history:**

| valid_from | surcharge | elec. tax | renew. tax | meter | renew. fee | cons. tax | VAT |
|---|---|---|---|---|---|---|---|
| 2020-01-01 | 1.90 | 0.10 | 0.796 | 4.695 | 19.02 | 60% | 20% |
| 2022-01-01 | 1.90 | 0.00 | 0.000 | 4.695 | 0.00 | 6% | 20% |
| 2025-01-01 | 1.90 | 0.00 | 0.796 | 4.695 | 19.02 | 6% | 20% |
| 2026-01-01 | 1.90 | 0.10 | 0.796 | 4.695 | 19.02 | 6% | 20% |
| 2026-03-01 | 1.90 | 0.10 | 0.796 | 4.695 | 19.02 | 7% | 20% |

### `auth_accounts` (jardyx_auth)

Managed by the `erikr/auth` library. Energie does not write to this table except via the auth library's own functions and the `preferences.php` avatar/email/password handlers.

| Column | Type | Description |
|---|---|---|
| `id` | `int` PK | Auto-increment user ID |
| `uuid` | `char(36)` | UUID (used by auth library internals) |
| `username` | `varchar(50)` | Display name |
| `password` | `varchar(255)` | bcrypt hash (cost 13) |
| `email` | `varchar(100)` | Current verified email |
| `pending_email` | `varchar(255)` | Unconfirmed email change target |
| `email_change_code` | `varchar(255)` | One-time confirmation token |
| `img_blob` | `mediumblob` | Profile picture binary data |
| `img_type` | `varchar(50)` | MIME type of profile picture |
| `img_size` | `int` | Size of profile picture in bytes |
| `lastLogin` | `datetime` | Timestamp of most recent login |
| `invalidLogins` | `int` | Failed login counter (reset on success) |
| `disabled` | `enum('0','1')` | Account lock flag |
| `departures` | `tinyint unsigned` | Rate-limit departure counter |
| `theme` | `set('light','dark','auto','')` | UI theme preference |
| `debug` | `enum('1','0')` | Debug mode flag |
| `rights` | `enum('Admin','User')` | Role — `Admin` grants access to `/admin` |
| `updated` | `timestamp` | Auto-updated on any row change |
| `created` | `timestamp` | Account creation timestamp |

---

## Entity Relationships

```
tariff_config (1) ──< readings (N)
    valid_from             ts (PK)
    [tariff params]        consumed_kwh
                           spot_ct
                           cost_brutto
                               │
                               │ GROUP BY DATE(ts)
                               ▼
                         daily_summary (1 per day)
                           day (PK)
                           consumed_kwh (SUM)
                           cost_brutto  (SUM)
                           avg_spot_ct  (AVG)
```

There is no foreign key constraint between `tariff_config` and `readings` — the relationship is enforced by application logic in `get_tariff()`. This is intentional: it avoids locking issues during bulk import and allows tariff rows to be updated independently.

---

## Indexes

The schema relies on the primary keys for all performance-critical queries:
- `readings.ts` (PK) — point lookups by date and range scans for monthly/yearly aggregation
- `daily_summary.day` (PK) — all web UI summary queries
- `tariff_config.valid_from` (PK) — descending scan for tariff lookup

No additional indexes are needed at the current data volume (~100k readings rows, ~1900 daily_summary rows).
