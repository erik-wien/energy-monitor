# UI Tabs + Header Nav Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Heute/Woche/Monat header nav and Grafik/Aufstellung tab switcher to all three drilldown pages.

**Architecture:** Both changes live entirely in the shared template `inc/_chart_page.php` and `web/styles/style.css`. No PHP is needed in the individual page files. Tab state is persisted in `localStorage` per page type. All nav link targets are computed server-side.

**Tech Stack:** PHP 8, vanilla JS, CSS custom properties (no build step, no framework).

---

## File map

| File | What changes |
|------|-------------|
| `web/styles/style.css` | Add header-nav, tab-bar, inline date input styles; remove cal-btn/date-picker styles |
| `inc/_chart_page.php` | Add header nav PHP, replace date toggle with inline input, add tab bar HTML + JS |

---

## Task 1: CSS — header nav + tabs + inline date input

**Files:**
- Modify: `web/styles/style.css`

- [ ] **Step 1: Add `justify-content: space-between` to the existing `header` rule**

In `web/styles/style.css`, find the `header` block (currently lines 22–29) and add `justify-content: space-between;`:

```css
header {
    background: var(--surface);
    padding: 1rem 2rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    justify-content: space-between;
    border-bottom: 1px solid var(--border);
}
```

- [ ] **Step 2: Add header-nav styles after the `header h1` rule**

```css
.header-nav { display: flex; gap: 0.25rem; }
.header-nav a {
    color: var(--muted);
    text-decoration: none;
    font-size: 0.85rem;
    padding: 0.25rem 0.65rem;
    border-radius: 6px;
    transition: background .15s, color .15s;
}
.header-nav a:hover,
.header-nav a.active { color: var(--text); background: var(--card); }
```

- [ ] **Step 3: Add inline date input style after the header-nav rules**

```css
.date-input-inline {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 4px;
    color: var(--text);
    color-scheme: dark;
    font-size: 0.8rem;
    padding: 0.15rem 0.4rem;
}
```

- [ ] **Step 4: Remove the old `.cal-btn`, `.date-picker`, and `.date-picker.visible` rules**

Delete these blocks (currently around lines 75–90):
```css
.cal-btn { … }
.cal-btn:hover { … }
.date-picker { … }
.date-picker.visible { … }
```

- [ ] **Step 5: Add tab bar styles after the `.period-nav` rule**

```css
.tab-bar {
    display: flex;
    border-bottom: 1px solid var(--border);
    margin-bottom: 1.5rem;
}
.tab-btn {
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    color: var(--muted);
    cursor: pointer;
    font-size: 0.9rem;
    padding: 0.5rem 1.25rem;
    margin-bottom: -1px;
    transition: color .15s, border-color .15s;
}
.tab-btn:hover { color: var(--text); }
.tab-btn.active { color: var(--text); border-bottom-color: var(--accent); }
.tab-panel.hidden { display: none; }
```

- [ ] **Step 6: Commit**

```bash
git add web/styles/style.css
git commit -m "style: add header-nav, tab-bar, inline date input; remove cal-btn"
```

---

## Task 2: PHP — header nav links

**Files:**
- Modify: `inc/_chart_page.php`

The nav links target today's date, the current ISO week, and the current calendar month — all computed at render time regardless of which date the page is showing.

- [ ] **Step 1: Add the nav link PHP variables at the top of `_chart_page.php`**

Insert after the opening `<?php` comment block (before the `function fmt_kwh` line):

```php
// Header nav targets — always point to *today's* day / week / month
$_nav_today       = date('Y-m-d');
$_nav_week_year   = (int)date('o');   // ISO year (may differ from calendar year in Jan)
$_nav_week_num    = (int)date('W');   // ISO week number
$_nav_month_year  = (int)date('Y');
$_nav_month_month = (int)date('n');
```

- [ ] **Step 2: Replace the `<header>` block**

Current:
```html
<header>
    <span>⚡</span>
    <h1><a href="<?= $base ?>/" style="color:inherit;text-decoration:none">Energie</a></h1>
</header>
```

Replace with:
```html
<header>
    <span style="display:flex;align-items:center;gap:0.75rem">
        <span>⚡</span>
        <h1><a href="<?= $base ?>/" style="color:inherit;text-decoration:none">Energie</a></h1>
    </span>
    <nav class="header-nav">
        <a href="<?= $base ?>/daily.php?date=<?= $_nav_today ?>"
           <?= $page_type === 'daily'   ? 'class="active"' : '' ?>>Heute</a>
        <a href="<?= $base ?>/weekly.php?year=<?= $_nav_week_year ?>&week=<?= $_nav_week_num ?>"
           <?= $page_type === 'weekly'  ? 'class="active"' : '' ?>>Woche</a>
        <a href="<?= $base ?>/monthly.php?year=<?= $_nav_month_year ?>&month=<?= $_nav_month_month ?>"
           <?= $page_type === 'monthly' ? 'class="active"' : '' ?>>Monat</a>
    </nav>
</header>
```

- [ ] **Step 3: Verify in browser**

Open any of `http://localhost/energie/daily.php`, `weekly.php`, `monthly.php`.
- Header shows ⚡ Energie on the left, "Heute Woche Monat" on the right
- The link matching the current page type is visually highlighted
- All three links navigate correctly

- [ ] **Step 4: Commit**

```bash
git add inc/_chart_page.php
git commit -m "feat: add Heute/Woche/Monat header nav"
```

---

## Task 3: PHP — inline date input

**Files:**
- Modify: `inc/_chart_page.php`

- [ ] **Step 1: Replace the `.period-nav` span in the nav bar**

Current:
```html
<div class="period-nav">
    <span class="period-label"><?= htmlspecialchars($period_label) ?></span>
    <button class="cal-btn" id="cal-btn" title="Datum wählen">🗓</button>
    <input type="date" id="date-picker" class="date-picker"
           value="<?= htmlspecialchars($current_date_iso) ?>">
</div>
```

Replace with:
```html
<div class="period-nav">
    <span class="period-label"><?= htmlspecialchars($period_label) ?></span>
    <input type="date" id="date-picker" class="date-input-inline"
           value="<?= htmlspecialchars($current_date_iso) ?>">
</div>
```

- [ ] **Step 2: Replace the date-picker JS block**

Remove the entire IIFE at the bottom of the `<script>` tag that references `cal-btn`:

```javascript
// DELETE this entire block:
(function() {
  const pageType = <?= json_encode($page_type) ?>;
  const base     = <?= json_encode($base) ?>;
  const btn      = document.getElementById('cal-btn');
  const picker   = document.getElementById('date-picker');

  btn.addEventListener('click', () => {
    picker.classList.toggle('visible');
    if (picker.classList.contains('visible')) picker.showPicker?.() || picker.focus();
  });

  picker.addEventListener('change', () => {
    const val = picker.value;
    if (!val) return;
    const d = new Date(val + 'T00:00:00');
    if (pageType === 'daily') {
      window.location = base + '/daily.php?date=' + val;
    } else if (pageType === 'weekly') {
      const tmp = new Date(d);
      tmp.setDate(tmp.getDate() + 4 - (tmp.getDay() || 7));
      const yearStart = new Date(tmp.getFullYear(), 0, 1);
      const week = Math.ceil(((tmp - yearStart) / 86400000 + 1) / 7);
      window.location = base + '/weekly.php?year=' + tmp.getFullYear() + '&week=' + week;
    } else {
      window.location = base + '/monthly.php?year=' + d.getFullYear() + '&month=' + (d.getMonth() + 1);
    }
  });

  document.addEventListener('click', e => {
    if (!e.target.closest('.period-nav')) picker.classList.remove('visible');
  });
})();
```

Replace with this simplified version (same navigation logic, no toggle):
```javascript
(function() {
  const pageType = <?= json_encode($page_type) ?>;
  const base     = <?= json_encode($base) ?>;
  const picker   = document.getElementById('date-picker');

  picker.addEventListener('change', () => {
    const val = picker.value;
    if (!val) return;
    const d = new Date(val + 'T00:00:00');
    if (pageType === 'daily') {
      window.location = base + '/daily.php?date=' + val;
    } else if (pageType === 'weekly') {
      const tmp = new Date(d);
      tmp.setDate(tmp.getDate() + 4 - (tmp.getDay() || 7));
      const yearStart = new Date(tmp.getFullYear(), 0, 1);
      const week = Math.ceil(((tmp - yearStart) / 86400000 + 1) / 7);
      window.location = base + '/weekly.php?year=' + tmp.getFullYear() + '&week=' + week;
    } else {
      window.location = base + '/monthly.php?year=' + d.getFullYear() + '&month=' + (d.getMonth() + 1);
    }
  });
})();
```

- [ ] **Step 3: Verify in browser**

- Date input is always visible in the nav bar (no icon click needed)
- Changing the date navigates correctly on all three page types

- [ ] **Step 4: Commit**

```bash
git add inc/_chart_page.php
git commit -m "feat: replace date toggle with always-visible inline input"
```

---

## Task 4: PHP + JS — tab bar

**Files:**
- Modify: `inc/_chart_page.php`

- [ ] **Step 1: Add the tab bar HTML after the `.nav-bar` div**

Insert after the closing `</div>` of the `.nav-bar` block:

```html
<div class="tab-bar">
    <button class="tab-btn" data-tab="grafik">Grafik</button>
    <button class="tab-btn" data-tab="aufstellung">Aufstellung</button>
</div>
```

- [ ] **Step 2: Wrap the KPI strip and chart container in a Grafik tab panel**

Current order in `<main>`:
```html
<div class="chart-container"> … </div>
<div class="kpi-strip"> … </div>
```

Replace with (KPIs first, then chart — both inside the Grafik panel):
```html
<div class="tab-panel" data-tab="grafik">
    <div class="kpi-strip">
        … (existing kpi-strip contents unchanged) …
    </div>
    <div class="chart-container">
        <canvas id="chart"></canvas>
    </div>
</div>
```

- [ ] **Step 3: Wrap the invoice in an Aufstellung tab panel**

Current:
```html
<div class="invoice">
    …
</div>
```

Replace with:
```html
<div class="tab-panel" data-tab="aufstellung">
    <div class="invoice">
        …
    </div>
</div>
```

- [ ] **Step 4: Add tab switching JS at the end of the `<script>` block (before `</script>`)**

```javascript
// Tab switching
(function() {
  const storageKey = 'energie-tab-' + <?= json_encode($page_type) ?>;
  const savedTab   = localStorage.getItem(storageKey) || 'grafik';

  function activateTab(name) {
    document.querySelectorAll('.tab-btn').forEach(btn => {
      btn.classList.toggle('active', btn.dataset.tab === name);
    });
    document.querySelectorAll('.tab-panel').forEach(panel => {
      panel.classList.toggle('hidden', panel.dataset.tab !== name);
    });
    localStorage.setItem(storageKey, name);
  }

  document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => activateTab(btn.dataset.tab));
  });

  activateTab(savedTab);
})();
```

- [ ] **Step 5: Verify in browser**

- Grafik tab shows KPI strip + chart; Aufstellung tab shows only the invoice table
- Switching tabs works without a page reload
- Navigating away and back restores the last active tab (localStorage persistence)
- Works on daily, weekly, and monthly pages

- [ ] **Step 6: Commit**

```bash
git add inc/_chart_page.php
git commit -m "feat: add Grafik/Aufstellung tab switcher with localStorage persistence"
```
