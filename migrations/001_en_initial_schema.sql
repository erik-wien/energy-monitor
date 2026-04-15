-- 001_initial_schema.sql
-- Initial Energie schema for shared DB environments (e.g. world4you).
-- Safe to run repeatedly: all CREATEs use IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS `readings` (
  `ts` datetime NOT NULL,
  `consumed_kwh` decimal(8,4) NOT NULL,
  `spot_ct` decimal(8,4) NOT NULL,
  `cost_brutto` decimal(10,6) NOT NULL,
  PRIMARY KEY (`ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `daily_summary` (
  `day` date NOT NULL,
  `consumed_kwh` decimal(8,3) NOT NULL,
  `cost_brutto` decimal(8,4) NOT NULL,
  `avg_spot_ct` decimal(8,4) NOT NULL,
  PRIMARY KEY (`day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tariff_config` (
  `valid_from` date NOT NULL,
  `provider_surcharge_ct` decimal(8,4) NOT NULL DEFAULT 0.0000 COMMENT 'Hofer Aufschlag ct/kWh',
  `electricity_tax_ct` decimal(8,4) NOT NULL DEFAULT 0.0000 COMMENT 'Elektrizitaetsabgabe ct/kWh',
  `renewable_tax_ct` decimal(8,4) NOT NULL DEFAULT 0.0000 COMMENT 'Erneuerbaren Foerderbeitrag ct/kWh',
  `meter_fee_eur` decimal(8,4) NOT NULL DEFAULT 0.0000 COMMENT 'Zaehlergebuehr EUR/Jahr',
  `renewable_fee_eur` decimal(8,4) NOT NULL DEFAULT 0.0000 COMMENT 'Erneuerbaren Foerderpauschale EUR/Jahr',
  `consumption_tax_rate` decimal(6,4) NOT NULL DEFAULT 0.0000 COMMENT 'Gebrauchsabgabe Wien (fraction)',
  `vat_rate` decimal(6,4) NOT NULL DEFAULT 0.0000 COMMENT 'Umsatzsteuer (fraction)',
  `yearly_kwh_estimate` decimal(10,2) NOT NULL DEFAULT 3000.00 COMMENT 'Jahresverbrauch-Schaetzung',
  PRIMARY KEY (`valid_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `en_preferences` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL COMMENT 'references auth_accounts.id',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `en_userprefs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `theme` varchar(8) NOT NULL DEFAULT 'auto',
  `updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
