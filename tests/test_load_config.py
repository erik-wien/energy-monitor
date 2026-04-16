"""Regression tests for `load_config()`.

Covers the bug where `web/api.php` invoked `energie.py` without `--config`,
causing the pipeline to fall back to the on-disk `config.ini` instead of
the dev/prod ini the web layer had selected.
"""
import os
import sys
import textwrap

import pytest

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))


def _write(tmp_path, name, body):
    p = tmp_path / name
    p.write_text(textwrap.dedent(body).lstrip(), encoding="utf-8")
    return str(p)


def test_load_config_honours_explicit_path(tmp_path):
    """Passing `path=` overrides the default CONFIG_PATH."""
    from energie import load_config

    ini = _write(tmp_path, "custom.ini", """
        [db]
        host = localhost
        user = u
        password = p
        database = a_custom_database
    """)
    cfg = load_config(ini)
    assert cfg["db"]["database"] == "a_custom_database"


def test_load_config_routes_dev_vs_prod(tmp_path):
    """Two different ini files resolve to two different DBs."""
    from energie import load_config

    dev = _write(tmp_path, "dev.ini", """
        [db]
        host = localhost
        user = u
        password = p
        database = energie_dev
    """)
    prod = _write(tmp_path, "prod.ini", """
        [db]
        host = localhost
        user = u
        password = p
        database = energie
    """)
    assert load_config(dev)["db"]["database"] == "energie_dev"
    assert load_config(prod)["db"]["database"] == "energie"


def test_load_config_missing_path_exits(tmp_path):
    """An unreadable config path aborts with a clear sys.exit."""
    from energie import load_config

    with pytest.raises(SystemExit):
        load_config(str(tmp_path / "does-not-exist.ini"))
