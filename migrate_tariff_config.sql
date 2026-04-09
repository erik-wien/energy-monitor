-- Migration: replace old tariff_config schema with new per-component structure
-- Run once against the energie database.

-- 1. Drop old columns (netz_ct bundles what is now broken out individually)
ALTER TABLE tariff_config
    DROP COLUMN IF EXISTS netz_ct,
    DROP COLUMN IF EXISTS hofer_aufschlag_ct,
    DROP COLUMN IF EXISTS mwst,
    DROP COLUMN IF EXISTS viertelstunden_ct;

-- 2. Add new columns
ALTER TABLE tariff_config
    ADD COLUMN IF NOT EXISTS provider_surcharge_ct  DECIMAL(8,4) NOT NULL DEFAULT 0 COMMENT 'Hofer Aufschlag ct/kWh',
    ADD COLUMN IF NOT EXISTS electricity_tax_ct     DECIMAL(8,4) NOT NULL DEFAULT 0 COMMENT 'Elektrizitätsabgabe ct/kWh',
    ADD COLUMN IF NOT EXISTS renewable_tax_ct       DECIMAL(8,4) NOT NULL DEFAULT 0 COMMENT 'Erneuerbaren Förderbeitrag ct/kWh',
    ADD COLUMN IF NOT EXISTS meter_fee_eur          DECIMAL(8,4) NOT NULL DEFAULT 0 COMMENT 'Zählergebühr €/Jahr',
    ADD COLUMN IF NOT EXISTS renewable_fee_eur      DECIMAL(8,4) NOT NULL DEFAULT 0 COMMENT 'Erneuerbaren Förderpauschale €/Jahr',
    ADD COLUMN IF NOT EXISTS consumption_tax_rate   DECIMAL(6,4) NOT NULL DEFAULT 0 COMMENT 'Gebrauchsabgabe Wien (fraction, e.g. 0.06)',
    ADD COLUMN IF NOT EXISTS vat_rate               DECIMAL(6,4) NOT NULL DEFAULT 0 COMMENT 'Umsatzsteuer (fraction, e.g. 0.20)',
    ADD COLUMN IF NOT EXISTS yearly_kwh_estimate    DECIMAL(10,2) NOT NULL DEFAULT 3000 COMMENT 'Jahresverbrauch-Schätzung für Jahreskosten-Umlage';

-- 3. Insert / update all tariff periods
--    ON DUPLICATE KEY UPDATE handles re-runs safely.

-- ab 1.1.2022
INSERT INTO tariff_config (valid_from, provider_surcharge_ct, electricity_tax_ct, renewable_tax_ct,
                            meter_fee_eur, renewable_fee_eur, consumption_tax_rate, vat_rate, yearly_kwh_estimate)
VALUES ('2022-01-01', 1.9, 0.0, 0.0, 4.695, 0.0, 0.06, 0.20, 3000)
ON DUPLICATE KEY UPDATE
    provider_surcharge_ct = VALUES(provider_surcharge_ct),
    electricity_tax_ct    = VALUES(electricity_tax_ct),
    renewable_tax_ct      = VALUES(renewable_tax_ct),
    meter_fee_eur         = VALUES(meter_fee_eur),
    renewable_fee_eur     = VALUES(renewable_fee_eur),
    consumption_tax_rate  = VALUES(consumption_tax_rate),
    vat_rate              = VALUES(vat_rate),
    yearly_kwh_estimate   = VALUES(yearly_kwh_estimate);

-- ab 1.1.2025
INSERT INTO tariff_config (valid_from, provider_surcharge_ct, electricity_tax_ct, renewable_tax_ct,
                            meter_fee_eur, renewable_fee_eur, consumption_tax_rate, vat_rate, yearly_kwh_estimate)
VALUES ('2025-01-01', 1.9, 0.0, 0.796, 4.695, 19.02, 0.06, 0.20, 3000)
ON DUPLICATE KEY UPDATE
    provider_surcharge_ct = VALUES(provider_surcharge_ct),
    electricity_tax_ct    = VALUES(electricity_tax_ct),
    renewable_tax_ct      = VALUES(renewable_tax_ct),
    meter_fee_eur         = VALUES(meter_fee_eur),
    renewable_fee_eur     = VALUES(renewable_fee_eur),
    consumption_tax_rate  = VALUES(consumption_tax_rate),
    vat_rate              = VALUES(vat_rate),
    yearly_kwh_estimate   = VALUES(yearly_kwh_estimate);

-- ab 1.1.2026
INSERT INTO tariff_config (valid_from, provider_surcharge_ct, electricity_tax_ct, renewable_tax_ct,
                            meter_fee_eur, renewable_fee_eur, consumption_tax_rate, vat_rate, yearly_kwh_estimate)
VALUES ('2026-01-01', 1.9, 0.1, 0.796, 4.695, 19.02, 0.06, 0.20, 3000)
ON DUPLICATE KEY UPDATE
    provider_surcharge_ct = VALUES(provider_surcharge_ct),
    electricity_tax_ct    = VALUES(electricity_tax_ct),
    renewable_tax_ct      = VALUES(renewable_tax_ct),
    meter_fee_eur         = VALUES(meter_fee_eur),
    renewable_fee_eur     = VALUES(renewable_fee_eur),
    consumption_tax_rate  = VALUES(consumption_tax_rate),
    vat_rate              = VALUES(vat_rate),
    yearly_kwh_estimate   = VALUES(yearly_kwh_estimate);

-- ab 1.3.2026 (Gebrauchsabgabe 6% → 7%)
INSERT INTO tariff_config (valid_from, provider_surcharge_ct, electricity_tax_ct, renewable_tax_ct,
                            meter_fee_eur, renewable_fee_eur, consumption_tax_rate, vat_rate, yearly_kwh_estimate)
VALUES ('2026-03-01', 1.9, 0.1, 0.796, 4.695, 19.02, 0.07, 0.20, 3000)
ON DUPLICATE KEY UPDATE
    provider_surcharge_ct = VALUES(provider_surcharge_ct),
    electricity_tax_ct    = VALUES(electricity_tax_ct),
    renewable_tax_ct      = VALUES(renewable_tax_ct),
    meter_fee_eur         = VALUES(meter_fee_eur),
    renewable_fee_eur     = VALUES(renewable_fee_eur),
    consumption_tax_rate  = VALUES(consumption_tax_rate),
    vat_rate              = VALUES(vat_rate),
    yearly_kwh_estimate   = VALUES(yearly_kwh_estimate);
