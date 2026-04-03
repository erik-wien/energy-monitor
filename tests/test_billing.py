import sys, os
sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))

def test_billing_formula_basic():
    """Single slot: 0.1 kWh at 10 ct/kWh spot, with known tariff."""
    from energie import calculate_cost_brutto
    tariff = {
        "netz_ct": 10.396,
        "hofer_aufschlag_ct": 1.9,
        "mwst": 0.20,
        "viertelstunden_ct": 0.0311875,
    }
    # (0.1 * ((10 + 10.396) * 1.2 + 1.9) + 0.0311875) / 100
    # = (0.1 * (20.396 * 1.2 + 1.9) + 0.0311875) / 100
    # = (0.1 * (24.4752 + 1.9) + 0.0311875) / 100
    # = (0.1 * 26.3752 + 0.0311875) / 100
    # = (2.63752 + 0.0311875) / 100
    # = 2.6687075 / 100
    # = 0.026687075
    result = calculate_cost_brutto(0.1, 10.0, tariff)
    assert abs(result - 0.026687075) < 1e-8

def test_billing_zero_consumption():
    from energie import calculate_cost_brutto
    tariff = {"netz_ct": 10.396, "hofer_aufschlag_ct": 1.9, "mwst": 0.20, "viertelstunden_ct": 0.0311875}
    result = calculate_cost_brutto(0.0, 10.0, tariff)
    # (0 * ... + 0.0311875) / 100 = 0.000311875
    assert abs(result - 0.000311875) < 1e-10
