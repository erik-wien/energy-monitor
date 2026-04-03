import sys, os, io
sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))

SAMPLE_CSV = """Datum;von;bis;Verbrauch
01.01.2025;00:00;00:15;0,078
01.01.2025;00:15;00:30;0,093
01.01.2025;00:30;00:45;0,343
"""

def test_parse_consumption_csv():
    from energie import parse_consumption_csv
    rows = parse_consumption_csv(io.StringIO(SAMPLE_CSV))
    assert len(rows) == 3
    assert rows[0]["ts"] == "2025-01-01T00:00:00"
    assert rows[0]["consumed_kwh"] == 0.078
    assert rows[2]["consumed_kwh"] == 0.343

def test_parse_consumption_csv_skips_missing_fields():
    from energie import parse_consumption_csv
    bad = "Datum;von;bis;Verbrauch\n01.01.2025;;;0,1\n"
    rows = parse_consumption_csv(io.StringIO(bad))
    assert rows == []
