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
