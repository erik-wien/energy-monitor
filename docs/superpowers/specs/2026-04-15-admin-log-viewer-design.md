# Admin Log Viewer + Admin UI Alignment — Design

**Date:** 2026-04-15
**Status:** Draft
**Scope:** `web/admin.php`, `web/api.php`, `inc/`, `web/styles/energie.css`, auth library

---

## 1. Goals

1. Add a **Log viewer** to Energie's admin area so an admin can inspect `auth_log` rows — especially the `auth_fail` rows introduced on 2026-04-15 — without shell access.
2. Align Energie's admin UI with **wlmonitor's admin pattern**: single-page scrolling cards, modal-based create/edit, API-driven actions with JSON responses and toast alerts.
3. Make the Log viewer filterable by the **app that produced the row**, even though `auth_log` has no dedicated `app` column today.

## 2. Non-goals

- Not touching the auth library's security behavior (rate limiting, CSRF tokens, bcrypt).
- Not migrating other apps (wlmonitor, simplechat, zeiterfassung) to the new pattern — they already use their own.
- Not adding per-row deletion, export, or log rotation from the UI. Logs are read-only.

## 3. App identification (the "filter by app" problem)

`auth_log` schema today:

```
id, idUser, context, activity, origin VARCHAR(32) DEFAULT 'web', ipAdress, logTime
```

No `app` column. All existing rows (284 on world4you) have `origin='web'` — a hardcoded default from `appendLog()`.

**Decision:** **repurpose the existing `origin` field as the app slug.** Every Energie call to `appendLog()` will write `origin='energie'`. Future apps using the shared auth library will write their own slug.

**Mechanism:**

1. Energie defines a constant in `inc/initialize.php`:

   ```php
   const APP_SLUG = 'energie';
   ```

2. The auth library's `appendLog()` is tweaked (2 lines) so the default origin reads the constant if defined:

   ```php
   function appendLog(mysqli $con, string $context, string $activity, string $origin = ''): bool {
       if ($origin === '') {
           $origin = defined('APP_SLUG') ? APP_SLUG : 'web';
       }
       // ...existing INSERT...
   }
   ```

   Backwards compatible — existing callers that pass an explicit origin (none in any Energie code) still work. Library-originated calls (`Erik logged in.`, `User #X updated.`) now inherit the app slug automatically.

3. **Legacy data cleanup:** on both world4you and akadbrain, run once before deploying:

   ```sql
   DELETE FROM auth_log WHERE origin = 'web';
   ```

   Rationale: on akadbrain the shared `jardyx_auth` database holds rows from simplechat, wlmonitor, zeiterfassung, and energie — all currently labeled `'web'`. We cannot reliably attribute them to an app after the fact, so dropping them is cleaner than keeping ambiguous history. On world4you the DB is single-app (energie), but we apply the same rule for symmetry.

4. The App filter dropdown in the Log tab is populated **dynamically** from `SELECT DISTINCT origin FROM auth_log ORDER BY origin`. On world4you this will show only `energie` (after cleanup). On akadbrain it will grow as other apps start writing their own slug.

## 4. UI structure — two tabs on `admin.php`

Tabs via GET parameter: `?tab=users` (default) or `?tab=log`. Server-side branch, not CSS toggle — only the active tab's content is rendered.

```
┌─ Administration ──────────────────────────────────────────┐
│ [ Benutzerverwaltung ]  [ Log ]                           │
├───────────────────────────────────────────────────────────┤
│ (active tab card)                                         │
└───────────────────────────────────────────────────────────┘
```

Tab links are `<a>` elements so deep-linking, back button, and copy-URL all work. No JavaScript required to switch tabs.

### 4.1 Benutzerverwaltung tab

Single card with:

- Header row: `[+ Benutzer anlegen]` button (opens create modal) + inline search form (GET `tab=users&filter=...`).
- User table: Username, Email, Rechte, Status, Aktionen.
- Action buttons per row: **Bearbeiten** (opens edit modal), **Passwort-Reset** (API call + confirm), **Löschen** (API call + confirm).
- Pagination below the table, 25 per page.

**Create modal** and **Edit modal** are standard `.modal` + `.modal-dialog` + `.modal-header` + `.modal-body` + `.modal-footer` using the shared `components.css` classes. Modal opening/closing is handled by a small inline script with `<?= $_cspNonce ?>` — the same JS pattern wlmonitor uses (`data-modal-open`, `data-modal-close`, click-outside to close).

All form submissions POST to `web/api.php?type=admin_user_*` endpoints (see §5). Responses are JSON `{ok: true}` or `{ok: false, error: '...'}`. The page shows a toast via a `showAlert()` helper appended into `#adminAlerts`, then reloads the row list on success.

### 4.2 Log tab

Single card with:

- Filter form (method=GET, action=admin.php, hidden `tab=log`):
  - `log_app` — select, populated from `DISTINCT origin`, "Alle Apps" default
  - `log_context` — select, populated from `DISTINCT context`, "Alle Kontexte" default
  - `log_user` — text, matches username (joined via `auth_accounts`)
  - `log_from`, `log_to` — Flatpickr date pickers, default last 7 days
  - `log_q` — text, substring match on `activity`
  - `log_fail` — checkbox, adds `context LIKE '%fail%'` to the WHERE
  - `[Filter]` submit, `[Zurücksetzen]` clears to defaults
- Table columns: **Zeit | App | Kontext | Benutzer | IP | Aktivität**
- Pagination below the table, 50 per page
- Empty state: "Keine Einträge gefunden."

Filter state is encoded in the URL, so the view is shareable. Pagination uses `log_page=N` to avoid collision with user-tab `page=N`.

## 5. Data access

### 5.1 New file: `inc/admin_log.php`

```php
admin_log_list(mysqli $con, int $page, int $perPage, array $filters): array
admin_log_distinct_apps(mysqli $con): array
admin_log_distinct_contexts(mysqli $con): array
```

- `admin_log_list()` returns `['rows' => [...], 'total' => int]`. Single prepared SELECT with `LEFT JOIN auth_accounts ON auth_accounts.id = auth_log.idUser`, plus matching `SELECT COUNT(*)` with identical WHERE predicates.
- Filters array supports: `app, context, user, from, to, q, fail`. Empty/null values are skipped. All `bind_param` (no interpolation).
- Time filters are `logTime >= ? AND logTime < ? + INTERVAL 1 DAY` semantics so the "bis" pick is inclusive.
- Uses the MySQLi `$con` (auth-DB connection opened in `inc/initialize.php`), not PDO — `auth_log` and `auth_accounts` live in the auth database per the project's two-connection rule (CLAUDE.md §"Auth system").

### 5.2 Revised `inc/admin_user.php` (existing wrappers around `erikr/auth`)

The existing `admin_list_users`, `admin_create_user`, `admin_edit_user`, `admin_reset_password`, `admin_delete_user` helpers stay. The only change is that `admin.php` stops calling them directly and instead routes through `api.php`.

### 5.3 New `api.php` endpoints

```
POST api.php?type=admin_user_create   (CSRF required, admin only)
POST api.php?type=admin_user_edit     (CSRF required, admin only)
POST api.php?type=admin_user_reset    (CSRF required, admin only)
POST api.php?type=admin_user_delete   (CSRF required, admin only)
```

Each endpoint:

1. Calls `auth_require()` + `admin_require()`.
2. Verifies CSRF token from POST (`csrf_token` field).
3. Calls the corresponding `admin_*` helper.
4. Returns `Content-Type: application/json` with `{ok, error?, user?}`.
5. Logs the action via `appendLog()` — library already does this for create/edit/reset/delete.

The **Log viewer is read-only**, so there is **no** `api.php?type=admin_log` endpoint. The log tab renders server-side from `admin_log_list()` on every GET — the filter form is a normal GET submit. This keeps the view shareable via URL and avoids a second fetch layer.

## 6. File changes summary

| File | Change |
|---|---|
| `inc/initialize.php` | Define `const APP_SLUG = 'energie';` before any `appendLog()` call |
| `inc/admin_log.php` | **New** — `admin_log_list()` + distinct helpers |
| `web/admin.php` | Rewritten — two tabs, modal-driven user CRUD, log viewer |
| `web/api.php` | New `admin_user_*` endpoints |
| `web/styles/energie.css` | Add `.admin-tabs`, `.tab-link`, `.tab-link.active` (app-specific) |
| `/Users/erikr/Git/auth/src/log.php` | 2-line tweak: `appendLog()` defaults `$origin` to `APP_SLUG` if defined |

**Migration / backfill** (run once per DB, not in a migration file because it's a cleanup, not a schema change):

```sql
DELETE FROM auth_log WHERE origin = 'web';
```

Ship the auth library change via `composer update erikr/auth` before deploying Energie.

## 7. Permissions

- `auth_require()` + `admin_require()` are already invoked at the top of `admin.php`. Both tabs inherit the gate — the Log tab does not need its own check.
- The new `api.php` endpoints each call both functions individually because `api.php` is a shared entry point that serves non-admin routes too (daily/weekly chart data).

## 8. Error handling

- Data-layer functions throw `\PDOException` / `\mysqli_sql_exception` on DB errors. `api.php` catches them and returns `{ok: false, error: 'Serverfehler.'}` with a generic message (never reflecting raw DB error text to the client).
- On CSRF failure, `api.php` returns HTTP 400 + `{ok: false, error: 'Ungültige Anfrage.'}`.
- On missing/invalid input, validation happens in the helper function and returns `{ok: false, error: '<human-readable>'}` for the toast.
- The Log tab's filter form is server-side parsed; bad date formats silently fall back to the 7-day default rather than erroring.

## 9. Testing

Before deploying:

- [ ] Backfill SQL runs clean on both DBs (count before/after)
- [ ] `composer update erikr/auth` pulls the new library version
- [ ] Fresh login writes an `auth_log` row with `origin='energie'` (verify via SQL)
- [ ] Admin user CRUD: create, edit, reset, delete all work via the modal+API path and show toasts
- [ ] Log tab renders with all columns populated
- [ ] Each filter works in isolation: app, context, user, date range, text search, "nur Fehler"
- [ ] Combined filters (app=energie + context=auth_fail + last 24h) produce correct subset
- [ ] Pagination works with active filters (query params carry across page links)
- [ ] Deep-linking `?tab=log&log_app=energie&log_fail=1` works
- [ ] Admin-only gate: a non-admin user hitting `api.php?type=admin_user_create` gets rejected

## 10. Open risks

- **Library compat:** the `appendLog()` tweak changes the default-parameter behavior. Any other app that depends on `erikr/auth` and *doesn't* define `APP_SLUG` will start writing `origin='web'` (same as today) — safe default. Apps that want the new behavior just define the constant. No breaking change.
- **Legacy delete:** `DELETE FROM auth_log WHERE origin='web'` on akadbrain wipes ~unknown-count historical rows across all apps using `jardyx_auth`. Ensure the user is OK with this before running on that DB. On world4you it drops 284 Energie rows.
- **CSP nonce:** the modal-handling JS is inline and must carry `<?= $_cspNonce ?>`. Verified that `_cspNonce` is set in `auth_bootstrap()` and available in `admin.php`.
