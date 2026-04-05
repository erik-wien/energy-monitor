# Auth Login Gate — Design Spec
_2026-04-05_

## Overview

Add a login gate to the Energie web UI by repurposing the wlmonitor authentication system. All five PHP pages require a valid session; unauthenticated requests redirect to a login form. User accounts are stored in the shared `wl_accounts` table (same MariaDB instance). No new DB schema, no register/reset flows in Energie itself.

---

## Architecture

Two database connections coexist in the same request:

| Connection | Variable | Driver | Purpose |
|------------|----------|--------|---------|
| Auth | `$con` | MySQLi | Read `wl_accounts`, write `wl_log`, rate-limit state |
| Data | `$pdo` | PDO | All `readings` / `daily_summary` / `tariff_config` queries |

Both use the same credentials from `/opt/homebrew/etc/energie-config.ini` `[db]` section.

`inc/db.php` becomes the single bootstrap include for all pages: it first includes `initialize.php` (session + MySQLi), then opens the PDO connection. Pages need no additional includes.

---

## New Files

### `inc/initialize.php`
Bootstrap file included by `inc/db.php` at the top.

Responsibilities:
- Emit security headers: `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy`, `Strict-Transport-Security` (HTTPS only), `Content-Security-Policy` with per-request nonce in `$_cspNonce`.
- Open MySQLi `$con` using `[db]` credentials from `energie-config.ini`. Strict error reporting enabled.
- Start session with hardened cookie settings: `httponly`, `secure` (HTTPS-detected), `samesite=Strict`, 4-day lifetime, `use_strict_mode`.
- Session recovery from `sId` cookie (validated with `preg_match`).
- Include `inc/csrf.php`.
- Define `getUserIpAddr()`, `addAlert()`, `appendLog()`, `auth_require()`.

`auth_require()` behaviour: redirect to `/energie/login.php` and exit if session not active. Used only by HTML pages. `api.php` performs its own inline 401 JSON check (see Modified Files).

### `inc/csrf.php`
Exact copy of `wlmonitor/include/csrf.php`. Provides `csrf_token()`, `csrf_input()`, `csrf_verify()`.

### `inc/auth.php`
Ported from `wlmonitor/inc/auth.php` with one change: session fields written on successful login are trimmed to what Energie uses:

```
$_SESSION['loggedin']  = true
$_SESSION['sId']       = $sId
$_SESSION['id']        = (int) $row['id']
$_SESSION['username']  = $row['username']
$_SESSION['email']     = $row['email']
$_SESSION['rights']    = $row['rights']
```

Everything else (rate limiting, bcrypt upgrade, session fixation protection, `wl_log` write) is kept identical.

Constants defined: `RATE_LIMIT_FILE`, `RATE_LIMIT_MAX` (5), `RATE_LIMIT_WINDOW` (900 s).

### `data/ratelimit.json`
Empty JSON object `{}`. Must be writable by the web server.

### `data/.htaccess`
```
Deny from all
```
Prevents direct HTTP access to the rate-limit state file.

### `web/login.php`
Login form page. Uses Energie's existing `styles/style.css` (dark theme, no Bootstrap).

- If session already active (`$_SESSION['loggedin']`): redirect to `index.php`.
- Displays any queued `$_SESSION['alerts']` (Bootstrap-style type/message pairs rendered as styled divs).
- Single `<form method="post" action="authentication.php">` with CSRF hidden input, username field, password field, submit button.
- No remember-me, no register link, no forgot-password link.
- Logout link visible in the header of protected pages (added to the existing `<header>` in each page).

### `web/authentication.php`
POST handler:
1. Reject empty username or password → redirect to `login.php` with alert.
2. `csrf_verify()` → reject invalid token.
3. `auth_login($con, username, password)` → on success redirect to `index.php`; on failure redirect to `login.php` with error alert.

### `web/logout.php`
Calls `auth_logout($con)`, adds alert, redirects to `login.php`.

---

## Modified Files

### `inc/db.php`
Prepend:
```php
require_once __DIR__ . '/initialize.php';
```
No other changes. All existing PDO code stays as-is.

### `web/index.php`, `web/daily.php`, `web/weekly.php`, `web/monthly.php`
Add one line immediately after the existing `require_once`:
```php
auth_require();
```

Add a logout link to the `<header>` element (already present in each page):
```html
<a href="/energie/logout.php" class="header-nav-logout">Abmelden</a>
```

### `web/api.php`
Add at top (after `require_once`):
```php
if (empty($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
```

---

## Data Flow

```
Browser → any page
  → inc/db.php
    → inc/initialize.php
        open MySQLi $con
        start/restore session
        include csrf.php
    → open PDO $pdo
  → auth_require()          ← redirects to login.php if not logged in
  → page logic (PDO queries)

Browser → login.php         ← served without auth check (public)
Browser POST → authentication.php
  → csrf_verify()
  → auth_login($con, ...)   ← reads wl_accounts via MySQLi
      rate-limit check (ratelimit.json)
      bcrypt verify
      session fixation protection
      write wl_log
  → redirect index.php

Browser → logout.php
  → auth_logout($con)       ← write wl_log, destroy session
  → redirect login.php
```

---

## Security Properties Preserved from wlmonitor

- IP-based rate limiting: 5 failures / 15 min per IP (JSON file, flock).
- bcrypt cost-13 with transparent upgrade on login.
- Session fixation protection: `session_regenerate_id(true)` on login.
- `sId` cookie: `httponly`, `secure`, `samesite=Strict`, 4-day lifetime.
- CSRF tokens on all POST forms.
- Generic error message (no username enumeration).

---

## Out of Scope

- Register, forgot password, email confirmation, admin panel — these remain in wlmonitor only.
- Avatar/theme/preferences — Energie has no such UI.
- Any changes to the Python pipeline (`energie.py`) or test suite.
