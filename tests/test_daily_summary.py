"""Integration tests for `rebuild_daily_summary()`.

The function is a pure SQL aggregation (INSERT … SELECT … GROUP BY DATE(ts)
… ON DUPLICATE KEY UPDATE). These tests cover the three things the web UI
depends on:
  1. SUM(consumed_kwh) and SUM(cost_brutto) per day,
  2. AVG(spot_ct) per day (simple mean of all slots, no weighting),
  3. idempotent rebuild — running twice yields the same daily_summary.
"""
from decimal import Decimal


def _insert_reading(conn, ts, kwh, spot_ct, cost):
    cur = conn.cursor()
    cur.execute(
        """INSERT INTO readings (ts, consumed_kwh, spot_ct, cost_brutto)
           VALUES (%s, %s, %s, %s)""",
        (ts, kwh, spot_ct, cost),
    )
    conn.commit()
    cur.close()


def _fetch_summary(conn):
    cur = conn.cursor(dictionary=True)
    cur.execute("SELECT day, consumed_kwh, cost_brutto, avg_spot_ct "
                "FROM daily_summary ORDER BY day")
    rows = cur.fetchall()
    cur.close()
    return rows


def test_rebuild_aggregates_two_days(test_db):
    from energie import rebuild_daily_summary

    _insert_reading(test_db, "2026-04-01 00:00:00", 0.25, 10.0, 0.10)
    _insert_reading(test_db, "2026-04-01 00:15:00", 0.50, 12.0, 0.20)
    _insert_reading(test_db, "2026-04-02 00:00:00", 1.00, 14.0, 0.40)

    rebuild_daily_summary(test_db)
    rows = _fetch_summary(test_db)

    assert len(rows) == 2
    day1, day2 = rows
    assert str(day1["day"]) == "2026-04-01"
    assert float(day1["consumed_kwh"]) == 0.75
    assert float(day1["cost_brutto"]) == 0.30
    assert float(day1["avg_spot_ct"]) == 11.0        # (10 + 12) / 2

    assert str(day2["day"]) == "2026-04-02"
    assert float(day2["consumed_kwh"]) == 1.00
    assert float(day2["avg_spot_ct"]) == 14.0


def test_rebuild_is_idempotent(test_db):
    """Running twice must not double-count. Matches the admin UI flow where
    users can click 'rebuild' repeatedly without side effects."""
    from energie import rebuild_daily_summary

    _insert_reading(test_db, "2026-04-01 00:00:00", 0.25, 10.0, 0.10)

    rebuild_daily_summary(test_db)
    first = _fetch_summary(test_db)

    rebuild_daily_summary(test_db)
    second = _fetch_summary(test_db)

    assert first == second


def test_rebuild_picks_up_new_readings_on_second_run(test_db):
    """After new readings are inserted, a second rebuild updates existing day rows."""
    from energie import rebuild_daily_summary

    _insert_reading(test_db, "2026-04-01 00:00:00", 0.25, 10.0, 0.10)
    rebuild_daily_summary(test_db)

    _insert_reading(test_db, "2026-04-01 00:15:00", 0.25, 20.0, 0.10)
    rebuild_daily_summary(test_db)

    rows = _fetch_summary(test_db)
    assert len(rows) == 1
    assert float(rows[0]["consumed_kwh"]) == 0.50
    assert float(rows[0]["avg_spot_ct"]) == 15.0    # (10 + 20) / 2


def test_rebuild_handles_empty_readings(test_db):
    """With zero readings the function returns cleanly and daily_summary stays empty."""
    from energie import rebuild_daily_summary

    rebuild_daily_summary(test_db)
    assert _fetch_summary(test_db) == []
