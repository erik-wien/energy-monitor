# Spec: UI Tabs + Header Nav

**Date:** 2026-04-05  
**Scope:** `inc/_chart_page.php`, `web/styles/style.css`  
**Pages affected:** daily.php, weekly.php, monthly.php (all use `_chart_page.php`)

---

## What's changing

Two independent additions to every drilldown page:

1. **Header navigation** — quick links to the current day / week / month
2. **Content tabs** — Grafik (chart + KPIs) and Aufstellung (table)

---

## 1. Header navigation

The `<header>` currently contains only the ⚡ Energie logo. A `<nav>` is added on the right side with three links:

| Label | Target | Active when |
|-------|--------|-------------|
| Heute | `daily.php?date=<today>` | `$page_type === 'daily'` |
| Woche | `weekly.php?year=<Y>&week=<W>` | `$page_type === 'weekly'` |
| Monat | `monthly.php?year=<Y>&month=<M>` | `$page_type === 'monthly'` |

The current ISO week and month are computed server-side in `_chart_page.php` using `date()`. The active link gets a distinct style (light background, full-brightness text); inactive links are muted.

---

## 2. Inline date input

The existing calendar icon button (`.cal-btn`) and hidden `.date-picker` toggle are replaced by a date `<input>` that is always visible inline in the nav bar, between the period label and the `→` arrow. No icon, no toggle — just the input. Behavior on change is unchanged (navigates to the selected date/week/month).

---

## 3. Content tabs

A tab bar with two tabs is inserted between the nav bar and the page content:

- **Grafik** — contains the KPI strip and the chart container
- **Aufstellung** — contains the invoice table

The KPI strip moves inside the Grafik tab (currently it sits below the chart). The Aufstellung tab shows only the table — no KPIs.

Tab switching is pure CSS/JS (no server round-trip). The active tab is stored in `localStorage` keyed by page type (`energie-tab-daily`, `energie-tab-weekly`, `energie-tab-monthly`) so the last selected tab persists across navigation.

---

## 4. CSS changes

- Add `.header-nav` styles (flex row, link states)
- Remove `.cal-btn` and `.date-picker` / `.date-picker.visible` rules
- Add `.tab-bar`, `.tab-btn`, `.tab-btn.active` rules
- Add `.tab-panel` and `.tab-panel.hidden` rules

No existing styles are renamed; unused rules (`.cal-btn`, `.date-picker`) are removed.

---

## Affected files

| File | Change |
|------|--------|
| `inc/_chart_page.php` | Add header nav, replace date toggle with inline input, add tab bar + JS |
| `web/styles/style.css` | Add tab + header-nav styles, remove cal-btn/date-picker styles |

`web/daily.php`, `web/weekly.php`, `web/monthly.php` — no changes needed; they already pass `$page_type` and `$base` to `_chart_page.php`.
