"""Tests for `fetch_prices()` — the HTTP + archive + upsert wrapper.

`parse_spot_json` is covered in `test_prices.py` / `test_billing.py`; here we
mock `requests.get` and verify the surrounding flow: correct URL, archived
JSON, upsert invoked with the parsed rows.
"""
import json
import os
import sys
from unittest.mock import MagicMock, patch

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))


def _cfg_like(tmp_path):
    """Minimal configparser-shaped object pointing at a throwaway DB."""
    return {
        "db": {
            "user": "x",
            "password": "x",
            "database": "x",
            "host": "localhost",
            "get": lambda key, default=None: None,
        }
    }


def test_fetch_prices_requests_correct_url(tmp_path, monkeypatch):
    """Verifies the URL shape and that raise_for_status is honoured."""
    import energie

    monkeypatch.setattr(energie, "ARCHIV_DIR", str(tmp_path))
    monkeypatch.chdir(tmp_path)
    monkeypatch.setattr(energie, "__file__", str(tmp_path / "energie.py"))

    fake_resp = MagicMock()
    fake_resp.json.return_value = {"data": []}
    fake_resp.raise_for_status = MagicMock()

    with patch.object(energie.requests, "get", return_value=fake_resp) as mock_get, \
         patch.object(energie, "get_db") as mock_get_db:
        energie.fetch_prices({}, 2026, 4)
        mock_get.assert_called_once()
        url = mock_get.call_args[0][0]
        assert "year=2026" in url
        assert "month=4" in url
        assert fake_resp.raise_for_status.called
        # No rows → no DB call
        mock_get_db.assert_not_called()


def test_fetch_prices_archives_json_and_upserts(tmp_path, monkeypatch):
    """A non-empty response is written, archived, and upserted row-by-row."""
    import energie

    monkeypatch.setattr(energie, "ARCHIV_DIR", str(tmp_path / "archive"))
    os.makedirs(str(tmp_path / "archive"), exist_ok=True)
    monkeypatch.setattr(energie, "__file__", str(tmp_path / "energie.py"))

    api_payload = {
        "data": [
            {"from": "2026-04-01T00:00:00", "price": "12.3"},
            {"from": "2026-04-01T01:00:00", "price": "15.0"},
        ]
    }
    fake_resp = MagicMock()
    fake_resp.json.return_value = api_payload
    fake_resp.raise_for_status = MagicMock()

    fake_cur  = MagicMock()
    fake_conn = MagicMock()
    fake_conn.cursor.return_value = fake_cur

    with patch.object(energie.requests, "get", return_value=fake_resp), \
         patch.object(energie, "get_db", return_value=fake_conn):
        energie.fetch_prices({}, 2026, 4)

    # JSON was moved into archive
    archived = tmp_path / "archive" / "spotpreise_2026_04.json"
    assert archived.exists()
    assert json.loads(archived.read_text())["data"][0]["price"] == "12.3"

    # Upsert saw the two parsed rows
    assert fake_cur.executemany.called
    args, _ = fake_cur.executemany.call_args
    sql, rows = args
    assert "INSERT INTO readings" in sql
    assert rows == [
        {"ts": "2026-04-01T00:00:00", "spot_ct": 12.3},
        {"ts": "2026-04-01T01:00:00", "spot_ct": 15.0},
    ]
    assert fake_conn.commit.called


def test_fetch_prices_raises_on_http_error(tmp_path, monkeypatch):
    """HTTP errors propagate — caller must see the failure."""
    import energie

    monkeypatch.setattr(energie, "ARCHIV_DIR", str(tmp_path))
    fake_resp = MagicMock()
    fake_resp.raise_for_status.side_effect = RuntimeError("502 Bad Gateway")

    with patch.object(energie.requests, "get", return_value=fake_resp):
        import pytest
        with pytest.raises(RuntimeError):
            energie.fetch_prices({}, 2026, 4)
