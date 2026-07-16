---
id: TASK-3
title: 'Effektiver Durchschnittspreis: Ø-effektiv-KPI + gewichtete Mittelung (Audit)'
status: Done
assignee: []
created_date: '2026-07-16 18:08'
updated_date: '2026-07-16 18:27'
labels: []
dependencies: []
priority: high
ordinal: 2000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
**Audit-Anlass (2026-07-16):** Das KPI „Ø Tarif" zeigt auf allen Ansichten (Start, Tag, Woche, Monat, Jahr) nur den EPEX-Spot-Anteil (`AVG(avg_spot_ct)`), NICHT den effektiven Brutto-Preis, den der Nutzer real pro kWh zahlt. An echten Daten (jardyx): Ø Tarif 9,34 ct/kWh vs. effektiv gezahlt 14,93 ct/kWh — Unterschätzung um ~60 %. Der effektive Preis (Σ cost_brutto / Σ consumed_kwh × 100) wird nirgends angezeigt, obwohl kWh und € bereits als KPIs vorliegen. Zweitbefund: die Summen mitteln ungewichtet („Mittelwert von Tages-Mittelwerten"), während das Chart längst verbrauchsgewichtet rechnet (`epex_wgt`, api.php:155/235/318).

**Umsetzung (rein additiv, keine DB-Änderung):**

1. Neues KPI „Ø effektiv" = `kpi_eur / kpi_kwh * 100` (Brutto ct/kWh; Division durch 0 abfangen → 0/„–"). In `inc/_chart_page.php` KPI-Strip (Zeilen 48-62): 4. Karte ergänzen; bestehendes „Ø Tarif" zu **„Ø Spotpreis"** umbenennen (klar: nur EPEX-Anteil). Neue Variable `$kpi_eff` an das Template durchreichen, in ALLEN Konsumenten setzen: daily.php, weekly.php, monthly.php, yearly.php. `fmt_ct` (2 Nachkommastellen) wiederverwenden. CSS `.kpi-strip` prüfen, ob 4 Karten sauber umbrechen (sonst kleiner Grid-Fix in der zugehörigen CSS; keine neuen Tokens).

2. Ungewichtete Mittelung ersetzen: in daily.php (Tages-avg_spot_ct bleibt — ein Tag, kein Periodenmittel), weekly.php:25, monthly.php:19, yearly.php:16 und index.php (Dashboard heute/Woche/Monat, Zeilen ~11-31) die `AVG(avg_spot_ct)`-Spalte auf verbrauchsgewichtet umstellen: `SUM(avg_spot_ct*consumed_kwh)/NULLIF(SUM(consumed_kwh),0)`. Damit stimmt das „Ø Spotpreis"-KPI mit dem Chart-`epex_wgt` überein. (daily.php ist ein Einzeltag → dessen avg_spot_ct ist bereits das Tagesmittel, unverändert lassen; nur die Perioden-Views weekly/monthly/yearly/index betrifft es.)

3. index.php: die drei Dashboard-Karten (heute/Woche/Monat) zeigen ebenfalls „Ø Tarif" (Zeilen 53/64/75). Analog „Ø Spotpreis" + zusätzlich „Ø effektiv" (eur/kwh*100) pro Karte, sofern Platz; mindestens die Beschriftung und die gewichtete Mittelung nachziehen.

4. Detailtabelle `_chart_page.php:344`: Spalte „Netto Preis" nutzt ungewichtetes `epx`, daneben steht „Ø gew." (`epxW`). Beschriftung eindeutig machen (z. B. „Netto Preis" ist die Herleitung aus avg_spot; als solche kennzeichnen) — kein Rechenumbau, nur Klarheit.

**Acceptance Criteria:**
- (a) Auf Tag/Woche/Monat/Jahr + Startseite erscheint ein KPI „Ø effektiv" mit Brutto ct/kWh = Kosten/Verbrauch; an echten Daten ~14–17 ct/kWh (nicht ~9).
- (b) „Ø Tarif" heißt überall „Ø Spotpreis"; sein Wert ist verbrauchsgewichtet und stimmt mit dem Chart-`epex_wgt` derselben Periode überein (Toleranz < 0,05 ct auf Monatssicht).
- (c) Division durch 0 (leere Periode) ergibt sauber 0/„–", kein Warning.
- (d) `php -l` sauber; PHPUnit-Baseline unverändert grün (nur die 5 vorbestehenden ConfigPathTest-Failures); KPI-Layout im Browser verifiziert (4 Karten, kein Umbruch-Bruch, Dark+Light).
- (e) Kein neuer DB-Zugriffspfad, keine Migration.
<!-- SECTION:DESCRIPTION:END -->
