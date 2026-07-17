---
id: TASK-2
title: >-
  Audit Durchschnittsberechnung + effektiver bezahlter kWh-Preis
  (Fixtarif-Vergleich)
status: Done
assignee: []
created_date: '2026-07-16 16:45'
updated_date: '2026-07-16 19:44'
labels: []
dependencies: []
priority: medium
ordinal: 1000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
ZIEL (User): herausfinden, wie viel de facto als VIRTUELL FIXER kWh-Preis bezahlt wurde, um zu vergleichen, ob ein fixer Tarif guenstiger gewesen waere.

Der gesuchte Wert = effektiver Blended-Preis = SUM(cost_brutto) / SUM(consumed_kwh) ueber die Periode (brutto, inkl. aller Steuern/Abgaben und der anteiligen Fixkosten Zaehler-/Foerderpauschale). Das ist der 'virtuelle Fixpreis' den man 1:1 gegen ein Fixtarif-Angebot (ct/kWh) stellt.

AUDIT-BEFUND (Ausgangslage, zu verifizieren): Die aktuellen KPIs in weekly.php/monthly.php zeigen 'O Tarif' = AVG(avg_spot_ct) — also den ungewichteten Mittelwert der taeglichen Durchschnitts-SPOTpreise. Das ist NICHT der gesuchte Wert: (a) nur Spot-Komponente, ohne Aufschlag/Steuern/Abgaben/MwSt/Fixkosten; (b) verbrauchs-UNgewichtet (teure Hochverbrauchsstunden zaehlen gleich wie billige Niedrigverbrauchsstunden). Fuer den Fixtarif-Vergleich fuehrt beides in die Irre.

UMFANG:
1. Auditieren, wie Durchschnitte heute berechnet/angezeigt werden: daily_summary.avg_spot_ct (Herkunft in energie.py/csv_importer), AVG(avg_spot_ct) in api.php weekly/monthly/yearly KPIs, epex_wgt (verbrauchsgewichteter Spot — existiert schon pro Tag!), invoice_breakdown().
2. Klaeren welche Metrik der User-Frage entspricht und ob sie schon irgendwo auftaucht.
3. Spec: neue Kennzahl 'effektiv bezahlter Preis (ct/kWh brutto)' = SUM(cost_brutto)/SUM(consumed_kwh) je Periode + eine Gesamt-/Jahres-Zahl; sauber definiert inkl. Behandlung der jaehrlichen Fixkosten (meter_fee/renewable_fee, amortisiert ueber yearly_kwh_estimate) — im Brutto-cost_brutto bereits enthalten, also in Σcost_brutto automatisch drin; dokumentieren.
4. Fixtarif-Vergleich: ueberlegen wie man den effektiven Preis einem hypothetischen Fixtarif gegenueberstellt (Fixtarif haette teils dieselben Netz-/Fixkomponenten — fairer Vergleich = Energiepreis-Anteil vs. Fix-Energiepreis, ODER voller Brutto-Blended vs. voller Fix-Brutto). Empfehlung als Teil der Spec.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 Dokumentierter Audit: exakt wie avg_spot_ct entsteht und was AVG(avg_spot_ct) in weekly/monthly/yearly aktuell darstellt; Feststellung ob/wo der effektiv bezahlte Brutto-Preis pro kWh heute sichtbar ist
- [ ] #2 Definition + Formel der Kennzahl 'effektiv bezahlter Preis (ct/kWh brutto)' = SUM(cost_brutto)/SUM(consumed_kwh), inkl. korrekter Fixkosten-Behandlung, als Spec festgehalten
- [ ] #3 Empfehlung fuer den Fixtarif-Vergleich (welche Preis-Ebene fair gegenuebergestellt wird) dokumentiert
- [ ] #4 Umsetzungsplan (welche Query/API/UI-Stellen) im Task, wartet auf Freigabe vor Implementierung
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
OBSOLET/ERLEDIGT 2026-07-16: Die gesuchte Kennzahl existiert bereits im Code (WIP, heute deployt). 'Ø effektiv' = kpi_eff() = SUM(cost_brutto)/SUM(consumed_kwh)*100 (ct/kWh brutto) in web/index.php:43 + daily/weekly/monthly/yearly + inc/_chart_page.php. Das IST der effektive bezahlte Preis = virtueller Fixpreis fuer den Fixtarif-Vergleich. Kein Audit noetig.
<!-- SECTION:NOTES:END -->

## Abschluss (2026-07-16)
Punkte 1–3 (Audit + Kennzahl „effektiv bezahlter Preis" = Σcost_brutto/Σconsumed_kwh) umgesetzt in **TASK-3** (Commit e063409): KPI „Ø effektiv" auf allen Ansichten, „Ø Tarif"→„Ø Spotpreis" verbrauchsgewichtet. Zahlen + 4-Karten-Layout browser-verifiziert. Punkt 4 (Fixtarif-Vergleich als Feature) auf Nutzer-Entscheidung **bewusst nicht gebaut** — die effektive Kennzahl ist der „virtuelle Fixpreis", den man direkt gegen ein Fixtarif-Angebot stellt.
