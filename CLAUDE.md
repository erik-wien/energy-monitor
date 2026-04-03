# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Project Does

A 3-step Python pipeline for calculating electricity costs using spot prices (Hofer Grünstrom tariff, Austria).

**Step 1 — `1_convert_verbrauch.py`**  
Converts the raw quarter-hourly consumption CSV from the grid operator (semicolon-delimited, German date format `DD.MM.YY`, header in German) into:
- `verbrauch_YYYY.csv` — normalized CSV with header `Datum;von;bis;Verbrauch`
- `verbrauch_YYYY.json` — JSON with `{"id": "CONSUMPTION", "data": [{consumed, unit, from, to}]}`, ISO 8601 timestamps

The year is auto-detected from the first data row. Launched via tkinter file picker.

**Step 2 — `2_fetch_epex.py`**  
Fetches hourly spot prices from the Hofer Grünstrom API for all months of a given year. Saves:
- `spotpreise_YYYY_MM.json` per month — raw API response
- `spotpreise_YYYY.csv` — aggregated year CSV with header `Zeitpunkt;Preis (ct/kWh)`, only full hours (minute == 0), German decimal format

**Step 3 — `3_merge2abrechnung.py`**  
Joins consumption (`verbrauch_YYYY.json`) with spot prices (`spotpreise_YYYY_MM.json` files) by ISO timestamp, applies pricing formula, outputs `abrechnung_YYYY.csv`.

## Pricing Formula (Step 3)

Fixed constants in `AbrechnungExporter`:
- `NETZ_PREIS = 10.396` ct/kWh
- `HOFER_AUFSCHLAG = 1.9` ct/kWh (fixed markup, not multiplied by MwSt)
- `MWST = 0.20` (20%)
- `VIERTELSTUNDENZUSCHLAG = 0.0311875` € per quarter-hour slot

```
kosten_brutto = (consumed * ((preis_ct + NETZ_PREIS) * (1 + MWST) + HOFER_AUFSCHLAG) + VIERTELSTUNDENZUSCHLAG) / 100
```

The `/ 100` converts from ct to €. Timestamps are matched exactly — missing timestamps are collected in `missing_timestamps`.

## Running the Scripts

```bash
python 1_convert_verbrauch.py   # opens file picker for raw grid CSV
python 2_fetch_epex.py          # prompts for year via tkinter dialog
python 3_merge2abrechnung.py    # prompts for year via tkinter dialog
```

Only external dependency: `requests` (used in script 2). All other imports are stdlib.

## Data Conventions

- CSV delimiter: `;` (semicolon)
- German decimal format: `,` as decimal separator in output CSVs
- Timestamps in JSON: ISO 8601 (`YYYY-MM-DDTHH:MM:SS`)
- Input consumption CSV uses German date format `DD.MM.YY` and times like `00:00`
- `DEBUG = True/False` flag controls verbose logging in scripts 1 and 3

## File Naming

| File | Description |
|------|-------------|
| `VIERTELSTUNDENWERTE-*.csv` | Raw input from grid operator (do not modify) |
| `verbrauch_YYYY.csv/json` | Normalized consumption data |
| `spotpreise_YYYY_MM.json` | Raw monthly spot price API responses |
| `spotpreise_YYYY.csv` | Aggregated annual spot prices |
| `abrechnung_YYYY.csv` | Final billing output |
