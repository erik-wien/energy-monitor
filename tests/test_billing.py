import sys, os
sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))

# Tariff snapshots matching the four periods defined in tariff_config.
# yearly_kwh_estimate = 3000 throughout.

TARIFF_2022 = {
    "provider_surcharge_ct":  1.9,
    "electricity_tax_ct":     0.0,
    "renewable_tax_ct":       0.0,
    "meter_fee_eur":          4.695,
    "renewable_fee_eur":      0.0,
    "consumption_tax_rate":   0.06,
    "vat_rate":               0.20,
    "yearly_kwh_estimate":    3000,
}

TARIFF_2025 = {
    "provider_surcharge_ct":  1.9,
    "electricity_tax_ct":     0.0,
    "renewable_tax_ct":       0.796,
    "meter_fee_eur":          4.695,
    "renewable_fee_eur":      19.02,
    "consumption_tax_rate":   0.06,
    "vat_rate":               0.20,
    "yearly_kwh_estimate":    3000,
}

TARIFF_2026_JAN = {
    "provider_surcharge_ct":  1.9,
    "electricity_tax_ct":     0.1,
    "renewable_tax_ct":       0.796,
    "meter_fee_eur":          4.695,
    "renewable_fee_eur":      19.02,
    "consumption_tax_rate":   0.06,
    "vat_rate":               0.20,
    "yearly_kwh_estimate":    3000,
}

TARIFF_2026_MAR = {
    "provider_surcharge_ct":  1.9,
    "electricity_tax_ct":     0.1,
    "renewable_tax_ct":       0.796,
    "meter_fee_eur":          4.695,
    "renewable_fee_eur":      19.02,
    "consumption_tax_rate":   0.07,
    "vat_rate":               0.20,
    "yearly_kwh_estimate":    3000,
}


def _expected(consumed_kwh, spot_ct, tariff):
    """Reference implementation of the formula — keep in sync with the docstring."""
    annual_ct = (tariff["meter_fee_eur"] + tariff["renewable_fee_eur"]) / tariff["yearly_kwh_estimate"] * 100
    net_ct = spot_ct + tariff["provider_surcharge_ct"] + tariff["electricity_tax_ct"] + tariff["renewable_tax_ct"] + annual_ct
    gross_ct = net_ct * (1 + tariff["vat_rate"] + tariff["consumption_tax_rate"])
    return consumed_kwh * gross_ct / 100


def test_formula_2022():
    """2022: no renewable fees, no electricity/renewable tax."""
    from energie import calculate_cost_brutto
    # annual_ct = 4.695 / 3000 * 100 = 0.1565
    # net_ct    = 10 + 1.9 + 0 + 0 + 0.1565 = 12.0565
    # gross_ct  = 12.0565 * 1.26 = 15.191...
    # cost      = 0.1 * 15.191... / 100
    result = calculate_cost_brutto(0.1, 10.0, TARIFF_2022)
    assert abs(result - _expected(0.1, 10.0, TARIFF_2022)) < 1e-10


def test_formula_2025():
    """2025: renewable fee and renewable tax kick in."""
    from energie import calculate_cost_brutto
    result = calculate_cost_brutto(0.25, 8.5, TARIFF_2025)
    assert abs(result - _expected(0.25, 8.5, TARIFF_2025)) < 1e-10


def test_formula_2026_jan():
    """2026-Jan: electricity_tax added vs 2025."""
    from energie import calculate_cost_brutto
    # annual_ct = (4.695 + 19.02) / 3000 * 100 = 23.715 / 30 = 0.7905
    # net_ct    = 10 + 1.9 + 0.1 + 0.796 + 0.7905 = 13.5865
    # gross_ct  = 13.5865 * (1 + 0.20 + 0.06) = 13.5865 * 1.26 = 17.11899
    # cost      = 0.1 * 17.11899 / 100 = 0.01711899
    result = calculate_cost_brutto(0.1, 10.0, TARIFF_2026_JAN)
    assert abs(result - _expected(0.1, 10.0, TARIFF_2026_JAN)) < 1e-10
    assert abs(result - 0.01711899) < 1e-7


def test_formula_2026_mar():
    """2026-Mar: consumption_tax rises from 6% to 7%."""
    from energie import calculate_cost_brutto
    jan = calculate_cost_brutto(0.1, 10.0, TARIFF_2026_JAN)
    mar = calculate_cost_brutto(0.1, 10.0, TARIFF_2026_MAR)
    assert mar > jan  # higher Gebrauchsabgabe → more expensive


def test_zero_consumption():
    """Zero consumption → zero cost (no per-slot fixed fee in new formula)."""
    from energie import calculate_cost_brutto
    result = calculate_cost_brutto(0.0, 10.0, TARIFF_2026_JAN)
    assert result == 0.0


def test_parse_spot_json_basic():
    """parse_spot_json extracts ts and spot_ct from API response."""
    from energie import parse_spot_json
    data = {"data": [
        {"from": "2025-01-01T00:00:00", "price": "12.5"},
        {"from": "2025-01-01T01:00:00", "price": "9.0"},
    ]}
    rows = parse_spot_json(data)
    assert rows == [
        {"ts": "2025-01-01T00:00:00", "spot_ct": 12.5},
        {"ts": "2025-01-01T01:00:00", "spot_ct": 9.0},
    ]


def test_parse_spot_json_skips_invalid_rows():
    """parse_spot_json silently skips rows missing keys or with bad price values."""
    from energie import parse_spot_json
    data = {"data": [
        {"from": "2025-01-01T00:00:00", "price": "10.0"},   # good
        {"price": "11.0"},                                    # missing 'from' key
        {"from": "2025-01-01T02:00:00", "price": "bad"},     # invalid price
        {},                                                   # empty
    ]}
    rows = parse_spot_json(data)
    assert len(rows) == 1
    assert rows[0]["spot_ct"] == 10.0


def test_parse_spot_json_empty():
    """parse_spot_json returns empty list for missing or empty data key."""
    from energie import parse_spot_json
    assert parse_spot_json({}) == []
    assert parse_spot_json({"data": []}) == []


def test_parse_consumption_csv_basic():
    """parse_consumption_csv parses DD.MM.YY dates and German decimal format."""
    from energie import parse_consumption_csv
    import io
    csv_text = "Datum;von;bis;Verbrauch\n01.01.25;00:00;00:15;0,250\n01.01.25;00:15;00:30;0,125\n"
    rows = parse_consumption_csv(io.StringIO(csv_text))
    assert rows == [
        {"ts": "2025-01-01T00:00:00", "consumed_kwh": 0.25},
        {"ts": "2025-01-01T00:15:00", "consumed_kwh": 0.125},
    ]


def test_parse_consumption_csv_four_digit_year():
    """parse_consumption_csv accepts DD.MM.YYYY dates."""
    from energie import parse_consumption_csv
    import io
    csv_text = "Datum;von;bis;Verbrauch\n15.03.2026;08:00;08:15;0,500\n"
    rows = parse_consumption_csv(io.StringIO(csv_text))
    assert rows == [{"ts": "2026-03-15T08:00:00", "consumed_kwh": 0.5}]


def test_parse_consumption_csv_skips_incomplete_rows():
    """parse_consumption_csv skips rows missing required fields."""
    from energie import parse_consumption_csv
    import io
    csv_text = "Datum;von;bis;Verbrauch\n;00:00;00:15;0,1\n01.01.25;;00:15;0,1\n01.01.25;00:00;00:15;\n01.01.25;00:15;00:30;0,2\n"
    rows = parse_consumption_csv(io.StringIO(csv_text))
    assert len(rows) == 1
    assert rows[0]["consumed_kwh"] == 0.2


def test_vat_not_applied_to_consumption_tax():
    """VAT must NOT compound on top of the consumption tax."""
    from energie import calculate_cost_brutto
    # Build a tariff with only consumption_tax (no VAT) and compare
    t_no_vat = {**TARIFF_2026_JAN, "vat_rate": 0.0, "consumption_tax_rate": 0.0}
    t_vat    = {**TARIFF_2026_JAN, "vat_rate": 0.20, "consumption_tax_rate": 0.0}
    t_ct     = {**TARIFF_2026_JAN, "vat_rate": 0.0, "consumption_tax_rate": 0.06}
    t_both   = TARIFF_2026_JAN

    base   = calculate_cost_brutto(1.0, 10.0, t_no_vat)
    w_vat  = calculate_cost_brutto(1.0, 10.0, t_vat)
    w_ct   = calculate_cost_brutto(1.0, 10.0, t_ct)
    w_both = calculate_cost_brutto(1.0, 10.0, t_both)

    # If they were compounding: w_both == base * 1.20 * 1.06
    # Correct (non-compounding): w_both == base * 1.20 + base * 0.06
    non_compounding = w_vat + w_ct - base   # base * 1.20 + base * 0.06 - base = base * 0.26
    compounding     = base * 1.20 * 1.06
    assert abs(w_both - non_compounding) < 1e-10
    assert abs(w_both - compounding) > 1e-6
