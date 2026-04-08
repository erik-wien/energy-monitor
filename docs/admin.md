# Admin Panel

> **Status: Planned.** This document is the design specification for the admin panel. It does not yet exist in the codebase.

---

## Purpose

The admin panel provides a web UI for operations that currently require direct database access or CLI commands. It is gated to users with `rights = 'Admin'` in `auth_accounts`.

---

## Access Control

A new helper `auth_require_admin()` will be added to `inc/initialize.php`:

```php
function auth_require_admin(): void {
    auth_require();   // redirects to login if not logged in
    if (($_SESSION['rights'] ?? '') !== 'Admin') {
        http_response_code(403);
        // render a simple 403 page and exit
    }
}
```

All files under `web/admin/` call `auth_require_admin()` instead of `auth_require()`. Regular users who navigate to `/admin/` will see a 403 page, not the login form (they are already logged in).

---

## Sections

### 1. Tariff Editor

**URL:** `/admin/tariffs.php`

Displays all rows from `tariff_config` ordered by `valid_from` descending (newest first). Each row shows all eight parameters.

**Add new tariff period:**
An "Add row" form at the top allows entering a new `valid_from` date and all parameter values. On submit, the row is inserted. If the `valid_from` date already exists, the form redisplays with an error.

**Edit existing row:**
Each row has an Edit button that opens an inline edit form pre-populated with current values. On save, the row is updated via `ON DUPLICATE KEY UPDATE`. No rows are deleted — historical data must remain repricing-consistent.

**Validation:**
- `valid_from` must be a valid date
- All rate fields must be non-negative
- `vat_rate` and `consumption_tax_rate` must be between 0 and 1 (fractions, not percentages)
- `yearly_kwh_estimate` must be positive

**Re-price warning:**
Editing an existing tariff row changes the calculated cost for all historical data that referenced that row. A confirmation prompt will warn the user and offer to trigger `rebuild_daily_summary()` after the update.

### 2. Consumption Import

**URL:** `/admin/import.php`

A file upload form accepting `.csv` and `.xlsx` files. On submit:

1. The uploaded file is saved to a temp location
2. `parse_consumption_csv()` or `parse_consumption_xlsx()` is called (PHP port of the Python logic)
3. Rows are upserted into `readings` with cost computation
4. `daily_summary` is rebuilt
5. The source file is moved to `_Archiv/`
6. A result card shows: rows imported, date range covered, rows skipped (zero consumption)

This mirrors exactly what `python energie.py import-csv` does, allowing imports without shell access.

**Accepted formats:**
Same two formats as the CLI parser — the column detection logic (`von` vs `Zeit von`) is replicated in PHP.

### 3. DB Status

**URL:** `/admin/status.php` (or rendered inline on the admin index)

Three stat cards:

| Card | Query |
|---|---|
| Latest reading | `SELECT MAX(ts) FROM readings` |
| Total readings | `SELECT COUNT(*) FROM readings` |
| Latest daily summary | `SELECT MAX(day) FROM daily_summary WHERE consumed_kwh > 0` |

A fourth card shows the number of files currently waiting in `scrapes/` (by globbing the directory). This gives a quick health check without needing to open a terminal.

### 4. User Management

**URL:** `/admin/users.php`

A table of all rows in `auth_accounts` showing: username, email, rights, lastLogin, disabled status, created date.

**Actions per user:**
- **Toggle active/inactive** — sets `auth_accounts.disabled` to `'0'`/`'1'`
- **Set role** — changes `rights` between `'Admin'` and `'User'`

Password resets are not handled here — they are handled by the auth library's own flow (forgotten password via email). The admin panel cannot read or set plaintext passwords.

An admin cannot demote their own account to `'User'` (prevents accidental lockout).

---

## Navigation

The admin panel is linked from the header nav for Admin users only. The `_header.php` will conditionally render an "Admin" link:

```php
<?php if (($_SESSION['rights'] ?? '') === 'Admin'): ?>
    <a href="<?= $base ?>/admin/" …>Admin</a>
<?php endif; ?>
```

---

## File Layout

```
web/admin/
├── index.php       Admin dashboard (status cards + quick links)
├── tariffs.php     Tariff config editor
├── import.php      Browser-based CSV/XLSX import
├── users.php       User management
└── _layout.php     Shared admin page shell (header + sidebar)
```

---

## Implementation Notes

- All admin pages share a common layout (`_layout.php`) separate from the main app layout — the admin UI uses a narrower max-width and a sidebar nav
- All POST actions require CSRF verification (`csrf_verify()`)
- The PHP `parse_consumption_csv()` function must match the Python implementation exactly — same two-format detection, same timestamp construction, same decimal handling
- `rebuild_daily_summary()` is called via a direct PDO query (same SQL as the Python version) — no shell exec
