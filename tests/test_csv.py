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

def test_parse_consumption_xlsx():
    import openpyxl, warnings, tempfile, os
    from datetime import datetime
    from energie import parse_consumption_xlsx

    # Build a minimal in-memory XLSX that matches the portal format
    wb = openpyxl.Workbook()
    ws = wb.active
    # Row 1: title, Row 2: blank, Row 3: headers (all skipped)
    ws.append(["Verbrauchsdaten für Zählpunkt AT001..."])
    ws.append([])
    ws.append(["Datum/Uhrzeit", "Leistung [kW]", "Verbrauch [kWh]"])
    # Row 4+: data
    ws.append([datetime(2025, 8, 1, 0, 0), 0.308, 0.077])
    ws.append([datetime(2025, 8, 1, 0, 15), 0.324, 0.081])
    ws.append([None, None, None])   # should be skipped

    with tempfile.NamedTemporaryFile(suffix=".xlsx", delete=False) as f:
        tmp = f.name
    try:
        wb.save(tmp)
        with warnings.catch_warnings():
            warnings.simplefilter("ignore")
            rows = parse_consumption_xlsx(tmp)
    finally:
        os.unlink(tmp)

    assert len(rows) == 2
    assert rows[0]["ts"] == "2025-08-01T00:00:00"
    assert rows[0]["consumed_kwh"] == 0.077
    assert rows[1]["ts"] == "2025-08-01T00:15:00"
    assert rows[1]["consumed_kwh"] == 0.081
