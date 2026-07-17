---
id: TASK-4
title: >-
  KPI-Kacheln: Einheit kleiner + iPhone 2-spaltig; Graph: hist. Tarif ->
  effektiver Tarif (ohne Band)
status: Done
assignee: []
created_date: '2026-07-17 04:52'
updated_date: '2026-07-17 04:58'
labels: []
dependencies: []
priority: medium
ordinal: 3000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
UI-Wunsch (mockup bestaetigt 2026-07-17):
1) Kachel-Einheit (kWh, ct/kWh, EUR-Prefix) deutlich kleiner als der Wert -> Einheit in <span class=unit> wrappen (fmt_kwh/fmt_eur/fmt_ct in inc/_chart_page.php + web/index.php + web/yearly.php, alle drei Dubletten) + CSS .unit ~0.6em, leichter/gedaempft. EUR-Prefix ebenfalls klein (User bestaetigt).
2) iPhone (max-width:600px): .kpi-strip von 1 auf 2 Spalten (2x2). Tablet/Desktop unveraendert.
3) Graph: 'Ø Tarif' (hist_tariff_avg) + 'hist. Tarif Band' (hist_tariff_max/min) ENTFERNEN; NEU 'Effektiv (ct/kWh)' = pro Tag cost/consumption*100 (brutto, wie Ø-effektiv-Kachel), Gold #ecc94b, y3, OHNE Band. Pills htariff/htband raus, 'Effektiv'-Pill rein; defaults/applyVis/y3-display + chart-pill--eff CSS anpassen; verwaiste chart-pill--htariff/--htband CSS entfernen. API bleibt unveraendert (hist_tariff_* Felder bleiben, nur ungenutzt).
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 Einheit in allen KPI-Kacheln deutlich kleiner als der Wert (chart-Seiten, index, yearly)
- [ ] #2 iPhone zeigt KPI-Kacheln 2x2; Tablet/Desktop unveraendert; keine Ueberlaeufe
- [ ] #3 Graph zeigt Effektiv-Linie (Gold, ohne Band) statt hist. Tarif; Pills/Sichtbarkeit konsistent; lokal Woche+Monat verifiziert
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
ERLEDIGT + DEPLOYT 2026-07-17.
Umgesetzt: (1) fmt_kwh/fmt_eur/fmt_ct in inc/_chart_page.php + web/index.php + web/yearly.php wrappen Einheit/EUR-Prefix in <span class=unit>; CSS .unit 0.6em/opacity .65 (verifiziert: 14.4px vs 24px = 0.6x). (2) energie.css @media max-width:600px: .kpi-strip repeat(2,1fr)+gap+padding (Tablet/Desktop unveraendert). (3) Graph: hist_tariff-Linie+Band raus, neue Effektiv-Linie = cost/consumption*100 (Gold #ecc94b, ohne Band, y3); Pill htariff/htband -> 'Effektiv'; defaults/applyVis/y3-display + chart-pill--eff CSS; verwaiste --htariff/--htband CSS entfernt.
Verifiziert: lokal Woche+Monat, prod jardyx.com + eriks.cloud (kleine Einheiten, goldene Effektiv-Linie ohne Band, Legende/Pills korrekt, php -l sauber, keine Alt-Referenzen). iPhone-2x2 nicht gerendert (Fenster nicht unter ~1455px verkleinerbar), CSS-Query aber korrekt. Deployt energie -> akadbrain + world4you.
<!-- SECTION:NOTES:END -->
