# Auth Login Gate — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Gate all Energie web pages behind a login form that authenticates against the shared `wl_accounts` table.

**Architecture:** A new `inc/initialize.php` bootstraps the session, security headers, and a MySQLi `$con` (for auth). It is included at the top of `inc/db.php`, so every existing page picks it up with no include changes. Auth logic is ported from wlmonitor's `inc/auth.php` with trimmed session fields. Three new web pages handle login/logout; five existing pages get a one-line `auth_require()` guard.

**Tech Stack:** PHP 8+, MySQLi (auth), PDO (data), MariaDB, bcrypt-13, `/opt/homebrew/etc/energie-config.ini`

---

## File Map

| Action | File | Responsibility |
|--------|------|---------------|
| Create | `inc/initialize.php` | Security headers, MySQLi `$con`, session start, CSRF include, utility functions, `auth_require()` |
| Create | `inc/csrf.php` | CSRF token generation and verification |
| Create | `inc/auth.php` | `auth_login()`, `auth_logout()`, IP rate limiting |
| Create | `data/ratelimit.json` | Rate-limit state (writable by web server) |
| Create | `data/.htaccess` | Block direct HTTP access to data/ |
| Create | `web/login.php` | Login form (Energie dark CSS, no Bootstrap) |
| Create | `web/authentication.php` | POST handler: CSRF → auth_login → redirect |
| Create | `web/logout.php` | auth_logout → redirect to login.php |
| Modify | `inc/db.php` | Prepend `require_once initialize.php` |
| Modify | `web/index.php` | Add `auth_require()`, add logout link to header |
| Modify | `web/daily.php` | Add `auth_require()` |
| Modify | `web/weekly.php` | Add `auth_require()` |
| Modify | `web/monthly.php` | Add `auth_require()` |
| Modify | `inc/_chart_page.php` | Add logout link to header nav (shared by daily/weekly/monthly) |
| Modify | `web/api.php` | Add inline 401 JSON guard |

---

## Task 1: Create `data/` directory

**Files:**
- Create: `data/ratelimit.json`
- Create: `data/.htaccess`

- [ ] **Step 1: Create the data directory and files**

```bash
mkdir -p /Users/erikr/Git/Energie/data
echo '{}' > /Users/erikr/Git/Energie/data/ratelimit.json
echo 'Deny from all' > /Users/erikr/Git/Energie/data/.htaccess
```

- [ ] **Step 2: Verify**

```bash
cat /Users/erikr/Git/Energie/data/ratelimit.json
# Expected: {}
```

- [ ] **Step 3: Commit**

```bash
cd /Users/erikr/Git/Energie
git add data/ratelimit.json data/.htaccess
git commit -m "chore: add data dir for rate-limit state"
```

---

## Task 2: Create `inc/csrf.php`

**Files:**
- Create: `inc/csrf.php`

Exact copy of wlmonitor's `include/csrf.php`. Provides `csrf_token()`, `csrf_input()`, `csrf_verify()`.

- [ ] **Step 1: Create the file**

```php
<?php
/**
 * inc/csrf.php — CSRF protection helpers.
 * Included by inc/initialize.php; do not include directly.
 */

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(): bool {
    if (empty($_SESSION['csrf_token'])) {
        return false;
    }
    $submitted = $_POST['csrf_token']
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? '';
    return hash_equals($_SESSION['csrf_token'], $submitted);
}

function csrf_input(): string {
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}
```

Save to `/Users/erikr/Git/Energie/inc/csrf.php`.

- [ ] **Step 2: Commit**

```bash
cd /Users/erikr/Git/Energie
git add inc/csrf.php
git commit -m "feat: add CSRF helpers (ported from wlmonitor)"
```

---

## Task 3: Create `inc/auth.php`

**Files:**
- Create: `inc/auth.php`

Ported from wlmonitor's `inc/auth.php`. Changes: `RATE_LIMIT_FILE` path adjusted; session fields in `auth_login()` trimmed to what Energie uses; theme cookie sync removed.

- [ ] **Step 1: Create the file**

```php
<?php
/**
 * inc/auth.php — Login, logout, IP rate limiting.
 * Requires: getUserIpAddr(), appendLog() from inc/initialize.php.
 */

define('RATE_LIMIT_FILE',   __DIR__ . '/../data/ratelimit.json');
define('RATE_LIMIT_MAX',    5);
define('RATE_LIMIT_WINDOW', 900);

// ── General-purpose rate limiter ─────────────────────────────────────────────

function rate_limit_check(string $key, int $max = 3, int $window = 900): bool {
    $fp = fopen(RATE_LIMIT_FILE, 'c+');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    $data  = json_decode(stream_get_contents($fp), true) ?? [];
    $now   = time();
    $entry = $data[$key] ?? ['count' => 0, 'since' => $now];
    if ($now - $entry['since'] > $window) {
        $entry = ['count' => 0, 'since' => $now];
    }
    $limited = $entry['count'] >= $max;
    flock($fp, LOCK_UN);
    fclose($fp);
    return $limited;
}

function rate_limit_record(string $key, int $window = 900): void {
    $fp = fopen(RATE_LIMIT_FILE, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $data  = json_decode(stream_get_contents($fp), true) ?? [];
    $now   = time();
    $entry = $data[$key] ?? ['count' => 0, 'since' => $now];
    if ($now - $entry['since'] > $window) {
        $entry = ['count' => 0, 'since' => $now];
    }
    $entry['count']++;
    $data[$key] = $entry;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);
}

function rate_limit_clear(string $key): void {
    $fp = fopen(RATE_LIMIT_FILE, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $data = json_decode(stream_get_contents($fp), true) ?? [];
    unset($data[$key]);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);
}

// ── Login rate limiter ────────────────────────────────────────────────────────

function auth_is_rate_limited(string $ip): bool {
    $fp = fopen(RATE_LIMIT_FILE, 'c+');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    $data  = json_decode(stream_get_contents($fp), true) ?? [];
    $now   = time();
    $entry = $data[$ip] ?? ['count' => 0, 'since' => $now];
    if ($now - $entry['since'] > RATE_LIMIT_WINDOW) {
        $entry = ['count' => 0, 'since' => $now];
    }
    $limited = $entry['count'] >= RATE_LIMIT_MAX;
    flock($fp, LOCK_UN);
    fclose($fp);
    return $limited;
}

function auth_record_failure(string $ip): void {
    $fp = fopen(RATE_LIMIT_FILE, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $data  = json_decode(stream_get_contents($fp), true) ?? [];
    $now   = time();
    $entry = $data[$ip] ?? ['count' => 0, 'since' => $now];
    if ($now - $entry['since'] > RATE_LIMIT_WINDOW) {
        $entry = ['count' => 0, 'since' => $now];
    }
    $entry['count']++;
    $data[$ip] = $entry;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);
}

function auth_clear_failures(string $ip): void {
    $fp = fopen(RATE_LIMIT_FILE, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $data = json_decode(stream_get_contents($fp), true) ?? [];
    unset($data[$ip]);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);
}

// ── Login / logout ────────────────────────────────────────────────────────────

function auth_login(mysqli $con, string $username, string $password): array {
    $ip = getUserIpAddr();

    if (auth_is_rate_limited($ip)) {
        return ['ok' => false, 'error' => 'Zu viele Fehlversuche. Bitte warten Sie 15 Minuten.'];
    }

    $stmt = $con->prepare(
        'SELECT id, username, password, email, activation_code, disabled, invalidLogins, rights
         FROM wl_accounts WHERE username = ?'
    );
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        auth_record_failure($ip);
        return ['ok' => false, 'error' => 'Falscher Benutzername oder Kennwort.'];
    }

    $row = $result->fetch_assoc();
    $stmt->close();

    if ($row['activation_code'] !== 'activated') {
        return ['ok' => false, 'error' => 'Benutzer ist noch nicht aktiviert.'];
    }
    if ((int) $row['disabled'] === 1) {
        return ['ok' => false, 'error' => 'Benutzer ist gesperrt.'];
    }
    if (!password_verify($password, $row['password'])) {
        auth_record_failure($ip);
        $upd = $con->prepare('UPDATE wl_accounts SET invalidLogins = invalidLogins + 1 WHERE username = ?');
        $upd->bind_param('s', $username);
        $upd->execute();
        $upd->close();
        return ['ok' => false, 'error' => 'Falscher Benutzername oder Kennwort.'];
    }

    if (password_needs_rehash($row['password'], PASSWORD_BCRYPT, ['cost' => 13])) {
        $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 13]);
        $upd = $con->prepare('UPDATE wl_accounts SET password = ? WHERE id = ?');
        $upd->bind_param('si', $newHash, $row['id']);
        $upd->execute();
        $upd->close();
    }

    auth_clear_failures($ip);
    session_regenerate_id(true);
    $sId = session_id();
    setcookie('sId', $sId, [
        'expires'  => time() + 60 * 60 * 24 * 4,
        'path'     => '/',
        'httponly' => true,
        'secure'   => true,
        'samesite' => 'Strict',
    ]);

    $_SESSION['sId']      = $sId;
    $_SESSION['loggedin'] = true;
    $_SESSION['id']       = (int) $row['id'];
    $_SESSION['username'] = $row['username'];
    $_SESSION['email']    = $row['email'];
    $_SESSION['rights']   = $row['rights'];

    $upd = $con->prepare('UPDATE wl_accounts SET lastLogin = NOW(), invalidLogins = 0 WHERE id = ?');
    $upd->bind_param('i', $row['id']);
    $upd->execute();
    $upd->close();

    appendLog($con, 'auth', $row['username'] . ' logged in (energie).', 'web');

    return ['ok' => true, 'username' => $row['username']];
}

function auth_logout(mysqli $con): void {
    if (!empty($_SESSION['username'])) {
        appendLog($con, 'log', $_SESSION['username'] . ' logged out (energie).', 'web');
    }
    session_destroy();
    setcookie('sId', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
        'secure'   => true,
        'samesite' => 'Strict',
    ]);
}
```

Save to `/Users/erikr/Git/Energie/inc/auth.php`.

- [ ] **Step 2: Commit**

```bash
cd /Users/erikr/Git/Energie
git add inc/auth.php
git commit -m "feat: add auth functions (ported from wlmonitor)"
```

---

## Task 4: Create `inc/initialize.php`

**Files:**
- Create: `inc/initialize.php`

- [ ] **Step 1: Create the file**

```php
<?php
/**
 * inc/initialize.php
 *
 * Bootstrap: security headers, MySQLi $con, session, CSRF, utility functions.
 * Included at the top of inc/db.php — do not include directly from pages.
 */

// ── Security headers ─────────────────────────────────────────────────────────

$_cspNonce = base64_encode(random_bytes(16));
$_isHttps  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
if ($_isHttps) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
header(
    "Content-Security-Policy: " .
    "default-src 'self'; " .
    "script-src 'self' 'nonce-{$_cspNonce}' https://cdn.jsdelivr.net; " .
    "style-src 'self' 'unsafe-inline'; " .
    "img-src 'self' data:; " .
    "connect-src 'self'; " .
    "frame-ancestors 'none'; " .
    "base-uri 'self'; " .
    "form-action 'self';"
);

// ── MySQLi connection (for auth / wl_accounts / wl_log) ──────────────────────

$_configPath = '/opt/homebrew/etc/energie-config.ini';
$_cfg = parse_ini_file($_configPath, true);
if (!$_cfg) {
    http_response_code(500);
    die('Config not found');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$con = new mysqli(
    $_cfg['db']['host'],
    $_cfg['db']['user'],
    $_cfg['db']['password'],
    $_cfg['db']['database']
);
$con->set_charset('utf8mb4');

// ── Session ──────────────────────────────────────────────────────────────────

$_sessionOpts = [
    'cookie_lifetime' => 60 * 60 * 24 * 4,
    'cookie_httponly' => true,
    'cookie_secure'   => $_isHttps,
    'cookie_samesite' => 'Strict',
    'use_strict_mode' => true,
];
session_start($_sessionOpts);

if (empty($_SESSION['sId'])) {
    if (isset($_COOKIE['sId']) && preg_match('/^[a-zA-Z0-9\-]{22,128}$/', $_COOKIE['sId'])) {
        session_abort();
        session_id($_COOKIE['sId']);
        session_start($_sessionOpts);
    } else {
        $_SESSION['sId'] = session_id();
        setcookie('sId', $_SESSION['sId'], [
            'expires'  => time() + 60 * 60 * 24 * 4,
            'path'     => '/',
            'httponly' => true,
            'secure'   => $_isHttps,
            'samesite' => 'Strict',
        ]);
    }
}

unset($_configPath, $_cfg, $_isHttps, $_sessionOpts);

// ── CSRF ─────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/csrf.php';

// ── Utility functions ────────────────────────────────────────────────────────

function getUserIpAddr(): string {
    return $_SERVER['HTTP_CLIENT_IP']
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR'];
}

function addAlert(string $type, string $message): void {
    $_SESSION['alerts'][] = [$type, htmlentities($message)];
}

function appendLog(mysqli $con, string $context, string $activity, string $origin = 'web'): bool {
    $stmt = $con->prepare(
        'INSERT INTO wl_log (idUser, context, activity, origin, ipAdress, logTime)
         VALUES (?, ?, ?, ?, INET_ATON(?), CURRENT_TIMESTAMP)'
    );
    $id = $_SESSION['id'] ?? 1;
    $ip = getUserIpAddr();
    $stmt->bind_param('issss', $id, $context, $activity, $origin, $ip);
    $stmt->execute();
    $stmt->close();
    return true;
}

function auth_require(): void {
    if (empty($_SESSION['loggedin'])) {
        header('Location: /energie/login.php');
        exit;
    }
}
```

Save to `/Users/erikr/Git/Energie/inc/initialize.php`.

- [ ] **Step 2: Commit**

```bash
cd /Users/erikr/Git/Energie
git add inc/initialize.php
git commit -m "feat: add initialize.php (session, MySQLi, security headers)"
```

---

## Task 5: Wire `initialize.php` into `db.php`

**Files:**
- Modify: `inc/db.php:1`

- [ ] **Step 1: Prepend the require to `inc/db.php`**

The current first line of `inc/db.php` is `<?php`. Replace the file's opening with:

```php
<?php
require_once __DIR__ . '/initialize.php';
// Shared DB connection — include this file, do not access directly
// ...rest of file unchanged...
```

Full resulting file:

```php
<?php
require_once __DIR__ . '/initialize.php';
// Shared DB connection — include this file, do not access directly
// $base: URL prefix for this app (e.g. '/energie' or '/energie.test')
$base = '/' . explode('/', ltrim($_SERVER['SCRIPT_NAME'], '/'))[0];
$config_path = '/opt/homebrew/etc/energie-config.ini';
$cfg = parse_ini_file($config_path, true);
if (!$cfg) {
    http_response_code(500);
    die(json_encode(['error' => 'Config not found']));
}

try {
    $pdo = new PDO(
        "mysql:host={$cfg['db']['host']};dbname={$cfg['db']['database']};charset=utf8mb4",
        $cfg['db']['user'],
        $cfg['db']['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(['error' => $e->getMessage()]));
}
```

- [ ] **Step 2: Verify existing pages still load (no auth yet — expect no errors)**

```bash
curl -s -o /dev/null -w "%{http_code}" http://localhost/energie/
# Expected: 200 (pages still open, session now started)
```

- [ ] **Step 3: Commit**

```bash
cd /Users/erikr/Git/Energie
git add inc/db.php
git commit -m "feat: wire initialize.php into db.php"
```

---

## Task 6: Create login/authentication/logout pages

**Files:**
- Create: `web/login.php`
- Create: `web/authentication.php`
- Create: `web/logout.php`

- [ ] **Step 1: Create `web/login.php`**

```php
<?php
require_once __DIR__ . '/../inc/initialize.php';

if (!empty($_SESSION['loggedin'])) {
    header('Location: index.php'); exit;
}

$alerts = $_SESSION['alerts'] ?? [];
unset($_SESSION['alerts']);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Energie · Anmelden</title>
    <link rel="stylesheet" href="/energie/styles/style.css">
    <style>
        .login-wrap {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: calc(100vh - 60px);
            padding: 2rem;
        }
        .login-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 2rem;
            width: 100%;
            max-width: 360px;
        }
        .login-card h2 {
            margin-bottom: 1.5rem;
            text-align: center;
            font-size: 1.1rem;
        }
        .form-group { margin-bottom: 1rem; }
        .form-group label {
            display: block;
            font-size: 0.85rem;
            color: var(--muted);
            margin-bottom: 0.35rem;
        }
        .form-group input {
            width: 100%;
            padding: 0.6rem 0.75rem;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text);
            font-size: 0.95rem;
        }
        .form-group input:focus {
            outline: none;
            border-color: var(--accent);
        }
        .btn-login {
            width: 100%;
            padding: 0.65rem;
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 0.95rem;
            cursor: pointer;
            margin-top: 0.5rem;
        }
        .btn-login:hover { opacity: 0.9; }
        .alert {
            padding: 0.65rem 0.9rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }
        .alert-danger { background: #4a1a1a; color: #fc8181; border: 1px solid #742a2a; }
        .alert-info   { background: #1a2a4a; color: #63b3ed; border: 1px solid #2a4a6a; }
    </style>
</head>
<body>
<header>
    <span>⚡</span>
    <h1>Energie</h1>
</header>
<div class="login-wrap">
    <div class="login-card">
        <h2>Anmelden</h2>
        <?php foreach ($alerts as [$type, $msg]): ?>
            <div class="alert alert-<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>">
                <?= $msg ?>
            </div>
        <?php endforeach; ?>
        <form method="post" action="authentication.php">
            <?= csrf_input() ?>
            <div class="form-group">
                <label for="login-username">Benutzername</label>
                <input type="text" id="login-username" name="login-username"
                       autocomplete="username" required autofocus>
            </div>
            <div class="form-group">
                <label for="login-password">Kennwort</label>
                <input type="password" id="login-password" name="login-password"
                       autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn-login">Anmelden</button>
        </form>
    </div>
</div>
</body>
</html>
```

- [ ] **Step 2: Create `web/authentication.php`**

```php
<?php
require_once __DIR__ . '/../inc/initialize.php';
require_once __DIR__ . '/../inc/auth.php';

if (empty($_POST['login-username']) || empty($_POST['login-password'])) {
    addAlert('danger', 'Bitte sowohl Benutzername als auch Kennwort ausfüllen.');
    header('Location: login.php'); exit;
}

if (!csrf_verify()) {
    addAlert('danger', 'Ungültige Anfrage.');
    header('Location: login.php'); exit;
}

$result = auth_login($con, $_POST['login-username'], $_POST['login-password']);

if ($result['ok']) {
    addAlert('info', 'Hallo ' . htmlspecialchars($result['username'], ENT_QUOTES, 'UTF-8') . '.');
    header('Location: index.php'); exit;
} else {
    addAlert('danger', $result['error']);
    header('Location: login.php'); exit;
}
```

- [ ] **Step 3: Create `web/logout.php`**

```php
<?php
require_once __DIR__ . '/../inc/initialize.php';
require_once __DIR__ . '/../inc/auth.php';

auth_logout($con);
addAlert('info', 'Sie wurden abgemeldet.');
header('Location: login.php'); exit;
```

- [ ] **Step 4: Verify login page renders**

Open `http://localhost/energie/login.php` in a browser.
Expected: Dark-themed login card with username/password fields and "Anmelden" button. No PHP errors.

- [ ] **Step 5: Verify login with valid credentials redirects to index**

Use a `wl_accounts` username/password. After submitting, expect redirect to `index.php` and a "Hallo …" alert (once index.php is gated in Task 7, this will show there).

- [ ] **Step 6: Verify wrong credentials shows error**

Submit bad credentials. Expected: redirected back to `login.php` with red "Falscher Benutzername oder Kennwort." alert.

- [ ] **Step 7: Commit**

```bash
cd /Users/erikr/Git/Energie
git add web/login.php web/authentication.php web/logout.php
git commit -m "feat: add login/authentication/logout pages"
```

---

## Task 7: Gate HTML pages + add logout links

**Files:**
- Modify: `web/index.php:2` (add auth_require)
- Modify: `web/index.php` header (add logout link)
- Modify: `web/daily.php:2` (add auth_require)
- Modify: `web/weekly.php:2` (add auth_require)
- Modify: `web/monthly.php:2` (add auth_require)
- Modify: `inc/_chart_page.php` header nav (add logout link)

- [ ] **Step 1: Add `auth_require()` to `web/index.php`**

After line 2 (`require_once __DIR__ . '/../inc/db.php';`), add:
```php
auth_require();
```

- [ ] **Step 2: Add logout link to `web/index.php` header**

The current header in `index.php` is:
```html
<header>
    <span>⚡</span>
    <h1>Energie</h1>
</header>
```

Replace with:
```html
<header>
    <span style="display:flex;align-items:center;gap:0.75rem">
        <span>⚡</span>
        <h1>Energie</h1>
    </span>
    <nav class="header-nav">
        <a href="<?= $base ?>/logout.php">Abmelden</a>
    </nav>
</header>
```

- [ ] **Step 3: Add `auth_require()` to `web/daily.php`**

After line 2 (`require_once __DIR__ . '/../inc/db.php';`), add:
```php
auth_require();
```

- [ ] **Step 4: Add `auth_require()` to `web/weekly.php`**

After line 2 (`require_once __DIR__ . '/../inc/db.php';`), add:
```php
auth_require();
```

- [ ] **Step 5: Add `auth_require()` to `web/monthly.php`**

After line 2 (`require_once __DIR__ . '/../inc/db.php';`), add:
```php
auth_require();
```

- [ ] **Step 6: Add logout link to `inc/_chart_page.php` header nav**

The current closing `</nav>` in `_chart_page.php` is at line 47:
```html
        <a href="<?= $base ?>/monthly.php?year=<?= $_nav_month_year ?>&amp;month=<?= $_nav_month_month ?>"
           <?= $page_type === 'monthly' ? 'class="active"' : '' ?>>Monat</a>
    </nav>
```

Add the logout link before `</nav>`:
```html
        <a href="<?= $base ?>/monthly.php?year=<?= $_nav_month_year ?>&amp;month=<?= $_nav_month_month ?>"
           <?= $page_type === 'monthly' ? 'class="active"' : '' ?>>Monat</a>
        <a href="<?= $base ?>/logout.php" style="margin-left:0.75rem;color:var(--muted)">Abmelden</a>
    </nav>
```

- [ ] **Step 7: Verify unauthenticated access redirects to login**

Clear cookies, then:
```bash
curl -s -o /dev/null -w "%{http_code}" http://localhost/energie/
# Expected: 302
curl -s -I http://localhost/energie/ | grep -i location
# Expected: Location: /energie/login.php
```

- [ ] **Step 8: Verify authenticated access works**

Log in via browser. Expect:
- `index.php` loads with tiles
- `daily.php`, `weekly.php`, `monthly.php` load with chart
- "Abmelden" link visible in header on all pages
- Clicking "Abmelden" destroys session and returns to login form

- [ ] **Step 9: Commit**

```bash
cd /Users/erikr/Git/Energie
git add web/index.php web/daily.php web/weekly.php web/monthly.php inc/_chart_page.php
git commit -m "feat: gate HTML pages behind auth_require()"
```

---

## Task 8: Gate `api.php`

**Files:**
- Modify: `web/api.php:3` (add 401 guard)

- [ ] **Step 1: Add the auth check to `web/api.php`**

After `require_once __DIR__ . '/../inc/db.php';` (line 2), add:
```php
if (empty($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
```

- [ ] **Step 2: Verify unauthenticated API request returns 401**

```bash
curl -s -w "\n%{http_code}" http://localhost/energie/api.php?type=daily
# Expected body:  {"error":"Unauthorized"}
# Expected status: 401
```

- [ ] **Step 3: Verify authenticated API request still works**

Log in via browser, then use the browser's dev tools or a session cookie with curl to confirm `api.php?type=daily` returns chart data.

- [ ] **Step 4: Commit**

```bash
cd /Users/erikr/Git/Energie
git add web/api.php
git commit -m "feat: return 401 JSON for unauthenticated API requests"
```
