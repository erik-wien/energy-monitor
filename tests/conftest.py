"""Shared pytest fixtures.

The `test_db` fixture opens a mysql.connector connection against the local
`energie_test` database (separate from `energie_dev` / `energie` so tests
can truncate tables freely). Credentials come from the same dev ini the
Mac uses for local development; only the database name is overridden.

If the DB is unreachable, the fixture calls `pytest.skip` with the exact
setup command needed. No silent skips — an operator should know what to
run to make the test pass.
"""
import configparser
import os
import sys

import pytest

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))

# CI and non-Homebrew hosts can override the ini location via env.
DEV_INI   = os.environ.get("ENERGIE_DEV_INI", "/opt/homebrew/etc/energie-config-dev.ini")
TEST_DB   = os.environ.get("ENERGIE_TEST_DB", "energie_test")
SCHEMA_SQL = os.path.join(
    os.path.dirname(os.path.dirname(__file__)),
    "migrations", "001_en_initial_schema.sql",
)

SETUP_HINT = f"""\
energie_test DB is not reachable. One-time setup:

    mysql -u root --socket=/tmp/mysql.sock <<'SQL'
    CREATE DATABASE IF NOT EXISTS {TEST_DB} CHARACTER SET utf8mb4;
    GRANT ALL PRIVILEGES ON {TEST_DB}.* TO 'energie'@'localhost';
    FLUSH PRIVILEGES;
    SQL
"""


def _read_dev_config():
    if not os.path.isfile(DEV_INI):
        pytest.skip(f"{DEV_INI} not found — test needs local dev config")
    cfg = configparser.ConfigParser()
    cfg.read(DEV_INI)
    return cfg


def _apply_schema(conn):
    """Create the core tables if they're not already there."""
    with open(SCHEMA_SQL, encoding="utf-8") as f:
        sql = f.read()
    cur = conn.cursor()
    for stmt in [s.strip() for s in sql.split(";") if s.strip()]:
        cur.execute(stmt)
    conn.commit()
    cur.close()


@pytest.fixture
def test_db():
    """Fresh connection to energie_test with tables truncated before each use."""
    import mysql.connector

    cfg = _read_dev_config()
    kwargs = dict(
        user=cfg["db"]["user"],
        password=cfg["db"]["password"],
        database=TEST_DB,
        charset="utf8mb4",
    )
    # Prefer socket when the ini declares one (local macOS); otherwise TCP
    # via [db].host + optional [db].port — the shape CI service containers use.
    if cfg["db"].get("socket"):
        kwargs["unix_socket"] = cfg["db"]["socket"]
    else:
        kwargs["host"] = cfg["db"].get("host", "127.0.0.1")
        if cfg["db"].get("port"):
            kwargs["port"] = int(cfg["db"]["port"])
    try:
        conn = mysql.connector.connect(**kwargs)
    except mysql.connector.Error as e:
        pytest.skip(f"{e}\n\n{SETUP_HINT}")

    _apply_schema(conn)

    cur = conn.cursor()
    for table in ("readings", "daily_summary", "tariff_config"):
        cur.execute(f"TRUNCATE TABLE {table}")
    conn.commit()
    cur.close()

    yield conn

    conn.close()


@pytest.fixture
def test_cfg():
    """A configparser pointing at energie_test — for functions that take `cfg`."""
    cfg = _read_dev_config()
    cfg["db"]["database"] = TEST_DB
    return cfg
