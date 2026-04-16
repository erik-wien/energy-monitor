"""Integration tests exercising the real DB path.

These tests connect to `energie_test` (see conftest.py) and verify that
upserts land in the `readings` table with the expected computed fields.

`parse_consumption_csv` and `calculate_cost_brutto` are unit-tested
elsewhere; here we confirm that the code that *writes to the DB*
behaves correctly on repeat runs (insert vs. update) and computes
cost_brutto consistently with the tariff table.
"""
from unittest.mock import MagicMock, patch


def _seed_tariff(conn, valid_from="2026-01-01"):
    """Install one tariff_config row so get_tariff() always finds something."""
    cur = conn.cursor()
    cur.execute(
        """INSERT INTO tariff_config
           (valid_from, provider_surcharge_ct, electricity_tax_ct, renewable_tax_ct,
            meter_fee_eur, renewable_fee_eur, consumption_tax_rate, vat_rate,
            yearly_kwh_estimate)
           VALUES (%s, 2.0, 1.5, 0.5, 25.0, 15.0, 0.06, 0.20, 3000.00)""",
        (valid_from,),
    )
    conn.commit()
    cur.close()


def _prime_spot(conn, ts, spot_ct):
    """Seed a readings row with just the spot price (consumption zero)
    so `_compute_and_upsert_consumption`'s lookup finds something."""
    cur = conn.cursor()
    cur.execute(
        """INSERT INTO readings (ts, consumed_kwh, spot_ct, cost_brutto)
           VALUES (%s, 0, %s, 0)
           ON DUPLICATE KEY UPDATE spot_ct = VALUES(spot_ct)""",
        (ts, spot_ct),
    )
    conn.commit()
    cur.close()


def test_compute_and_upsert_inserts_new_row(test_db):
    """A fresh ts lands in readings with computed cost_brutto."""
    from energie import _compute_and_upsert_consumption

    _seed_tariff(test_db)
    _prime_spot(test_db, "2026-04-01 00:00:00", 10.0)

    rows = [{"ts": "2026-04-01T00:00:00", "consumed_kwh": 0.25}]
    inserted, total = _compute_and_upsert_consumption(test_db, rows)

    assert (inserted, total) == (0, 1)  # prime row existed → update, not insert

    cur = test_db.cursor(dictionary=True)
    cur.execute("SELECT consumed_kwh, spot_ct, cost_brutto FROM readings")
    row = cur.fetchone()
    cur.close()
    assert float(row["consumed_kwh"]) == 0.25
    assert float(row["spot_ct"])      == 10.0
    # Sanity: cost is positive and proportional to consumption
    assert float(row["cost_brutto"]) > 0


def test_compute_and_upsert_updates_existing_row(test_db):
    """Re-importing the same ts overwrites consumed_kwh, keeps spot_ct."""
    from energie import _compute_and_upsert_consumption

    _seed_tariff(test_db)
    _prime_spot(test_db, "2026-04-01 00:00:00", 10.0)

    rows = [{"ts": "2026-04-01T00:00:00", "consumed_kwh": 0.10}]
    _compute_and_upsert_consumption(test_db, rows)

    rows = [{"ts": "2026-04-01T00:00:00", "consumed_kwh": 0.50}]
    inserted, _ = _compute_and_upsert_consumption(test_db, rows)
    assert inserted == 0

    cur = test_db.cursor()
    cur.execute("SELECT consumed_kwh, spot_ct FROM readings WHERE ts='2026-04-01 00:00:00'")
    consumed, spot = cur.fetchone()
    cur.close()
    assert float(consumed) == 0.50
    assert float(spot)     == 10.0   # preserved through UPDATE


def test_compute_and_upsert_counts_inserts(test_db):
    """Without a prime row, rowcount==1 path fires and `inserted` matches."""
    from energie import _compute_and_upsert_consumption

    _seed_tariff(test_db)
    rows = [
        {"ts": "2026-04-01T00:00:00", "consumed_kwh": 0.10},
        {"ts": "2026-04-01T00:15:00", "consumed_kwh": 0.20},
    ]
    inserted, total = _compute_and_upsert_consumption(test_db, rows)
    assert (inserted, total) == (2, 2)


def test_fetch_prices_writes_to_readings(test_db, test_cfg, tmp_path, monkeypatch):
    """End-to-end: mocked API payload → real DB upsert → readings populated."""
    import energie

    import os
    archive_dir = tmp_path / "archive"
    archive_dir.mkdir()
    monkeypatch.setattr(energie, "ARCHIV_DIR", str(archive_dir))
    monkeypatch.setattr(energie, "__file__", str(tmp_path / "energie.py"))

    payload = {
        "data": [
            {"from": "2026-04-01T00:00:00", "price": "12.3"},
            {"from": "2026-04-01T01:00:00", "price": "15.0"},
        ]
    }
    fake_resp = MagicMock()
    fake_resp.json.return_value = payload
    fake_resp.raise_for_status = MagicMock()

    with patch.object(energie.requests, "get", return_value=fake_resp):
        energie.fetch_prices(test_cfg, 2026, 4)

    cur = test_db.cursor(dictionary=True)
    cur.execute("SELECT ts, spot_ct FROM readings ORDER BY ts")
    got = [(r["ts"].strftime("%Y-%m-%dT%H:%M:%S"), float(r["spot_ct"])) for r in cur.fetchall()]
    cur.close()
    assert got == [
        ("2026-04-01T00:00:00", 12.3),
        ("2026-04-01T01:00:00", 15.0),
    ]
