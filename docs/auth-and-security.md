# Authentication & Security

## Two-Connection Model

Every page request opens two separate database connections with different purposes and credentials:

| Variable | Type | Database | Used for |
|---|---|---|---|
| `$con` | MySQLi | `jardyx_auth` | Auth operations: login, session, avatar, email, password |
| `$pdo` | PDO | `energie` or `energie_dev` | All data queries: readings, daily_summary, tariff_config |

**Why two connections?** The auth library (`erikr/auth`) was designed as a shared library across multiple projects — it always connects to its own dedicated database. Energie's data lives in a separate DB with a separate user. This means a credential leak on the app DB does not expose auth data, and the auth schema never mixes with application tables.

---

## Bootstrap Chain

Every request goes through this exact sequence:

```
require_once 'inc/db.php'
  │
  ├─ derive $base from SCRIPT_NAME
  │   '/energie.test/daily.php' → $base = '/energie.test'
  │   '/energie/daily.php'      → $base = '/energie'
  │
  ├─ select config file:
  │   $base === '/energie.test' → energie-config-dev.ini
  │   otherwise                → energie-config.ini
  │
  └─ require_once 'inc/initialize.php'
       │
       ├─ vendor/autoload.php  (loads erikr/auth)
       │
       ├─ parse energie-config.ini → define APP_BASE_URL, APP_NAME,
       │   APP_SUPPORT_EMAIL, AUTH_DB_PREFIX
       │   (initialize.php always reads the prod config for constants;
       │    SMTP credentials live in /opt/homebrew/etc/jardyx-mail.ini
       │    read by the erikr/auth mailer — not per app)
       │
       ├─ parse config again → open MySQLi $con (jardyx_auth DB)
       │
       └─ auth_bootstrap()
            ├─ session_start() + session_regenerate() on stale tokens
            ├─ generate per-request CSP nonce → $_cspNonce
            └─ emit security headers (see Security Headers section)

  └─ open PDO $pdo (energie or energie_dev, based on config selection above)
```

After bootstrap, pages call `auth_require()` which redirects to `login.php` if `$_SESSION['loggedin']` is not set. JSON endpoints (`api.php`) return HTTP 401 instead of redirecting.

---

## Session Model

Session fields set by the auth library on successful login:

| Key | Type | Example |
|---|---|---|
| `loggedin` | bool | `true` |
| `sId` | string | Session UUID |
| `id` | int | User ID (matches `auth_accounts.id`) |
| `username` | string | Display name |
| `email` | string | Verified email address |
| `rights` | string | `'Admin'` or `'User'` |
| `theme` | string | `'light'`, `'dark'`, or `'auto'` |

The `rights` field is an `ENUM('Admin','User')` in `auth_accounts`. Admin access is checked via `$_SESSION['rights'] === 'Admin'` in the admin panel guard.

---

## User Preferences

### Theme

Three options: **Light**, **Dark**, **Auto** (follows `prefers-color-scheme`).

Theme state lives in three places simultaneously:
1. `auth_accounts.theme` — persisted across devices and browsers
2. `$_SESSION['theme']` — loaded on login, updated on change
3. `document.documentElement.dataset.theme` — applied immediately in browser

The header emits an inline script before any CSS renders:
```html
<script nonce="…">document.documentElement.dataset.theme = "dark";</script>
```
This prevents a flash of the wrong theme by applying it synchronously during HTML parsing, before stylesheets are processed. CSS variables switch on `[data-theme="light"]` / `[data-theme="dark"]` / `@media (prefers-color-scheme: light)` for auto.

Theme changes can happen two ways:
- **Header dropdown** → JS `fetch()` to `api.php?type=set-theme` (instant, no page reload)
- **Preferences page** → POST form → server writes to DB + session → redirect

Both paths are equivalent; the dropdown is faster for casual switching.

### Avatar

Stored as a binary blob (`mediumblob`) in `auth_accounts.img_blob` with MIME type in `img_type`. Served by `avatar.php` which reads the blob and sets `Content-Type` from `img_type`. Falls back to an SVG placeholder if no image is set. Max upload size: 2 MB.

---

## Security Model

### Content Security Policy

`auth_bootstrap()` generates a per-request random nonce (`$_cspNonce`) and emits a strict CSP header. All inline `<script>` tags must include `nonce="<?= $_cspNonce ?>"`. External scripts (Chart.js CDN) are covered by the CSP script-src directive.

This prevents XSS by ensuring that injected scripts without the nonce are blocked by the browser.

### HTTP Strict Transport Security

HSTS header is emitted by `auth_bootstrap()`. Browsers that have seen this header will refuse plain HTTP connections for the declared max-age period.

### CSRF Protection

Every state-changing form uses `<?= csrf_input() ?>` which renders a hidden input with a session-bound token. POST handlers call `csrf_verify()` before processing. The token is refreshed on session start and on a rolling timer.

The logout form is a `<form method="post">` with CSRF — not a plain link — so that logout cannot be triggered by a third-party page loading an image or iframe pointing to the logout URL.

### Rate Limiting

Login attempts are rate-limited by `REMOTE_ADDR` using a JSON state file at `data/ratelimit.json`. The file is writable by the web server process. Proxy headers (`X-Forwarded-For`) are deliberately ignored to prevent bypass by sending a spoofed header.

The `data/` directory is protected by `.htaccess`:
```apache
Require all denied
```

### Password Hashing

bcrypt with cost factor 13. The `invalidLogins` counter in `auth_accounts` is incremented on each failed attempt and reset to 0 on success. It is used by the rate limiter alongside the IP-based limit.

### Input Handling

- All user-facing output uses `htmlspecialchars()` with `ENT_QUOTES`
- Database queries use PDO prepared statements (`$pdo->prepare()`) or MySQLi prepared statements (`$con->prepare()`) — no string interpolation in SQL
- File uploads validate MIME type via `getimagesize()` (reads magic bytes, not just extension) and enforce a 2 MB size limit
- `$_GET` parameters used in SQL (dates, year, month, week) are cast to `int` or validated against a pattern before use

### X-Frame-Options / Clickjacking

Set by `auth_bootstrap()` to prevent the app from being embedded in an iframe on another origin.
