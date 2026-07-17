---
id: TASK-5
title: >-
  Effektiv-Tarif auf NETTO umstellen (Graph-Linie + Ø-effektiv-Kachel),
  angebotsvergleichbar
status: Done
assignee: []
created_date: '2026-07-17 05:37'
updated_date: '2026-07-17 05:59'
labels: []
dependencies: []
priority: medium
ordinal: 4000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Folge zu TASK-4. User: beide ct-Linien im Graph muessen dieselbe Basis haben (netto), und netto ist mit Marktangeboten vergleichbar (die meist netto sind). Gewaehlt: 'Nur Effektiv netto' (Spot einfach netto + Effektiv netto), keine gewichtete-Spot-Linie.
Netto korrekt = cost_brutto / (1 + vat_rate + consumption_tax_rate) PRO PERIODE (Faktor variiert: 1,26 bis 2026-02, 1,27 ab 2026-03; NICHT hart durch 1,26 teilen). cost_brutto enthaelt bereits alles inkl. amortisierter Fixkosten (_csv_calc_cost), daher ist die Division sauber.
Umsetzung: (a) api.php daily/weekly/monthly: pro Punkt cost_netto = cost_brutto/(1+vat+ct) ins JSON (Raten liegen via TARIFF_COLS je Zeile vor). (b) _chart_page.php: Effektiv-Linie nutzt cost_netto statt cost; Label 'Effektiv netto'. (c) KPI 'Ø effektiv' in daily/weekly/monthly/yearly auf netto (Summary-Query um SUM(cost_brutto/(1+vat+ct)) via tariff_config-Join erweitern).
Hinweis dokumentiert: Effektiv-netto liegt weiter UEBER dem Spot (~+2,6 ct Netto-Sockel), faellt nicht darunter; Lastverschiebe-Effekt ist separat (gewichteter vs einfacher Spot).
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 Graph zeigt Effektiv-netto-Linie (cost_netto/Verbrauch*100), periodengerecht; Label 'Effektiv netto'; Spot-Linie unveraendert
- [ ] #2 Kachel 'Ø effektiv' zeigt Netto-Wert (periodengerecht via tariff_config-Join) in daily/weekly/monthly/yearly
- [ ] #3 Lokal Woche+Monat verifiziert (Juni ~12,97 ct netto); php -l sauber
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
ERLEDIGT 2026-07-17 — KORRIGIERTE Definition nach Klaerung mit User: 'effektiv' = VERBRAUCHSGEWICHTETER Spot (Σ kWh×Spot/Σ kWh = API-Feld epex_wgt), NICHT cost_brutto/1.27. Netto effektiv = gewichteter Spot (reine Energie), liegt UNTER dem einfachen Spot → zeigt Lastverschiebung.
Umgesetzt: (a) _chart_page.php: Effektiv-Linie = data.epex_wgt, Label 'Effektiv netto (ct/kWh)', nur Wochen/Monat (isDailyPage → weggelassen, da gewichteter Spot je 15-min-Slot = Spot selbst); Pill 'Effektiv netto' (id btn-eff) in Tagesansicht ausgeblendet; applyVis/defaults angepasst. (b) KPI 'Ø effektiv' in daily/weekly/monthly/yearly = SUM(spot_ct*consumed_kwh)/SUM(consumed_kwh) via Readings-Query (periodengerecht). (c) yearly.php-Chart ebenfalls angeglichen (hist-Tarif-Linie+Band raus, Effektiv-netto-Linie rein) — war in TASK-4 uebersehen.
Verifiziert lokal Tag/Woche/Monat/Jahr: Ø effektiv < Ø Spot (9,61/9,39/9,49 vs 10,63/10,54/10,23), Linie unter Spot, Tag nur Kachel. php -l sauber. NICHT deployt (wartet auf Freigabe).
<!-- SECTION:NOTES:END -->
