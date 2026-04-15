# Admin Log Viewer + Admin UI Alignment — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a filterable `auth_log` viewer to Energie's admin area and refactor user administration to match wlmonitor's modal + API pattern, with two tabs (Benutzerverwaltung, Log).

**Architecture:** `admin.php` becomes a two-tab page. User CRUD moves to modal dialogs, submitted via JSON calls to new `api.php?type=admin_user_*` endpoints. The Log tab renders server-side from a new `inc/admin_log.php` data layer reading `auth_log` via the MySQLi `$con` connection. Per-app filtering is enabled by repurposing the existing `origin` column — the `erikr/auth` library gets a 2-line tweak so `appendLog()` defaults to an `APP_SLUG` constant when defined.

**Tech Stack:** PHP 8.5, MySQLi (auth tables), shared `components.css` (no Bootstrap), vanilla JS, Flatpickr (already in project), `erikr/auth` Composer path dependency.

**No PHP test harness in this project** — Energie's `tests/` dir contains only Python tests for the billing pipeline. Verification for each task is via `php -l` (syntax lint), browser smoke test (documented), and SQL inspection against `auth_log`. TDD is adapted: each task lists an explicit "verify" step with the exact command to run.

**Design spec:** `docs/superpowers/specs/2026-04-15-admin-log-viewer-design.md`

---

## Phase 1 — App identification plumbing

### Task 1: Teach `erikr/auth`'s `appendLog()` to pick up `APP_SLUG`

**Files:**
- Modify: `/Users/erikr/git/auth/src/log.php:29-44`

- [ ] **Step 1: Make the 2-line change**

Replace the body of `appendLog()` so an empty `$origin` parameter resolves to the `APP_SLUG` constant if defined, falling back to `'web'`:

```php
function appendLog(mysqli $con, string $context, string $activity, string $origin = ''): bool {
    if ($origin === '') {
        $origin = defined('APP_SLUG') ? APP_SLUG : 'web';
    }
    $table = AUTH_DB_PREFIX . 'auth_log';
    $stmt = $con->prepare(
        "INSERT INTO {$table} (idUser, context, activity, origin, ipAdress, logTime)
         VALUES (?, ?, ?, ?, INET_ATON(?), CURRENT_TIMESTAMP)"
    );
    if ($stmt === false) {
        return false;
    }
    $id = $_SESSION['id'] ?? 0;
    $ip = getUserIpAddr();
    $stmt->bind_param('issss', $id, $context, $activity, $origin, $ip);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}
```

Only two things changed vs the original: the default for `$origin` is `''` instead of `'web'`, and there's a new 3-line block at the top that resolves the default. All existing call sites in the library (which pass `'web'` explicitly) are unaffected.

- [ ] **Step 2: Lint**

Run: `php -l /Users/erikr/git/auth/src/log.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit in the auth repo**

```bash
cd /Users/erikr/git/auth
git add src/log.php
git commit -m "feat(log): resolve appendLog origin default from APP_SLUG constant

Consumers that define APP_SLUG before the library is loaded will have
every appendLog() row automatically tagged with their app slug in the
origin column. Fallback is 'web' so behavior is unchanged for any
consumer that does not define the constant.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 2: Define `APP_SLUG` in Energie's `inc/initialize.php`

**Files:**
- Modify: `/Users/erikr/Git/Energie/inc/initialize.php` — add the constant *before* the auth library is loaded via Composer autoload

- [ ] **Step 1: Find where the constant should go**

Read `/Users/erikr/Git/Energie/inc/initialize.php` and locate:
1. The `require_once __DIR__ . '/../vendor/autoload.php';` line (this is where the auth library's functions become available)
2. The `AUTH_DB_PREFIX` constant definition (already required by the library)

The `APP_SLUG` constant must be defined *before* the first `appendLog()` call and ideally before `vendor/autoload.php` is required, to guarantee visibility from library internals.

- [ ] **Step 2: Add the constant**

Insert this line immediately after the `AUTH_DB_PREFIX` definition (or adjacent to it — both constants are config-level definitions):

```php
const APP_SLUG = 'energie';
```

- [ ] **Step 3: Lint**

Run: `php -l /Users/erikr/Git/Energie/inc/initialize.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Smoke-test locally**

Open `http://localhost/energie.test/login.php` in a browser, log in, then run:

```bash
mysql -u root jardyx_auth -e "SELECT id, origin, activity FROM auth_log ORDER BY id DESC LIMIT 1"
```

Expected: the most recent row has `origin='energie'`. If it still says `'web'`, either the autoloader didn't pick up the updated library (`composer update erikr/auth` in the Energie repo) or `APP_SLUG` was defined too late.

- [ ] **Step 5: Commit**

```bash
cd /Users/erikr/Git/Energie
git add inc/initialize.php
git commit -m "feat(log): tag auth_log rows with APP_SLUG='energie'

Takes effect via the erikr/auth library's new default-origin resolution
(see auth library commit). All appendLog() calls from both the library
and the project now write origin='energie' automatically.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Phase 2 — Log data access layer

### Task 3: Create `inc/admin_log.php` with list + distinct helpers

**Files:**
- Create: `/Users/erikr/Git/Energie/inc/admin_log.php`

- [ ] **Step 1: Write the file**

```php
<?php
/**
 * inc/admin_log.php — Data access for the admin Log tab.
 *
 * Reads auth_log via MySQLi (auth DB). Do not use PDO here — auth_log
 * and auth_accounts live in the auth database, not the Energie data DB.
 */

/**
 * Return a paginated, filtered slice of auth_log rows joined with auth_accounts
 * for username lookup.
 *
 * $filters keys (all optional, all strings):
 *   app      — exact match on origin
 *   context  — exact match on context
 *   user     — substring match on auth_accounts.username
 *   from     — 'YYYY-MM-DD' inclusive lower bound on logTime
 *   to       — 'YYYY-MM-DD' inclusive upper bound on logTime
 *   q        — substring match on activity
 *   fail     — truthy → context LIKE '%fail%'
 *
 * @return array{rows: list<array>, total: int}
 */
function admin_log_list(mysqli $con, int $page, int $perPage, array $filters): array
{
    $where  = [];
    $types  = '';
    $params = [];

    if (!empty($filters['app'])) {
        $where[] = 'l.origin = ?';
        $types  .= 's';
        $params[] = $filters['app'];
    }
    if (!empty($filters['context'])) {
        $where[] = 'l.context = ?';
        $types  .= 's';
        $params[] = $filters['context'];
    }
    if (!empty($filters['user'])) {
        $where[] = 'a.username LIKE ?';
        $types  .= 's';
        $params[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['user']) . '%';
    }
    if (!empty($filters['from'])) {
        $where[] = 'l.logTime >= ?';
        $types  .= 's';
        $params[] = $filters['from'] . ' 00:00:00';
    }
    if (!empty($filters['to'])) {
        $where[] = 'l.logTime < (? + INTERVAL 1 DAY)';
        $types  .= 's';
        $params[] = $filters['to'] . ' 00:00:00';
    }
    if (!empty($filters['q'])) {
        $where[] = 'l.activity LIKE ?';
        $types  .= 's';
        $params[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']) . '%';
    }
    if (!empty($filters['fail'])) {
        $where[] = "l.context LIKE '%fail%'";
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $offset   = ($page - 1) * $perPage;

    $tableLog      = AUTH_DB_PREFIX . 'auth_log';
    $tableAccounts = AUTH_DB_PREFIX . 'auth_accounts';

    $sql = "SELECT l.id, l.logTime, l.origin, l.context, l.activity,
                   INET_NTOA(l.ipAdress) AS ip, a.username
            FROM {$tableLog} l
            LEFT JOIN {$tableAccounts} a ON a.id = l.idUser
            {$whereSql}
            ORDER BY l.logTime DESC, l.id DESC
            LIMIT ? OFFSET ?";

    $stmt = $con->prepare($sql);
    $typesWithPage = $types . 'ii';
    $paramsWithPage = array_merge($params, [$perPage, $offset]);
    $stmt->bind_param($typesWithPage, ...$paramsWithPage);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows   = [];
    while ($r = $result->fetch_assoc()) {
        $rows[] = [
            'id'       => (int) $r['id'],
            'logTime'  => $r['logTime'],
            'origin'   => $r['origin'],
            'context'  => $r['context'],
            'activity' => $r['activity'],
            'ip'       => $r['ip'],
            'username' => $r['username'],
        ];
    }
    $stmt->close();

    $countSql = "SELECT COUNT(*) FROM {$tableLog} l
                 LEFT JOIN {$tableAccounts} a ON a.id = l.idUser
                 {$whereSql}";
    $cstmt = $con->prepare($countSql);
    if ($params) {
        $cstmt->bind_param($types, ...$params);
    }
    $cstmt->execute();
    $cstmt->bind_result($total);
    $cstmt->fetch();
    $cstmt->close();

    return ['rows' => $rows, 'total' => (int) $total];
}

/**
 * Distinct non-empty `origin` values in auth_log, sorted. Drives the App filter.
 *
 * @return list<string>
 */
function admin_log_distinct_apps(mysqli $con): array
{
    $table = AUTH_DB_PREFIX . 'auth_log';
    $out   = [];
    if ($res = $con->query("SELECT DISTINCT origin FROM {$table} WHERE origin <> '' ORDER BY origin")) {
        while ($row = $res->fetch_row()) {
            $out[] = $row[0];
        }
        $res->free();
    }
    return $out;
}

/**
 * Distinct non-empty `context` values in auth_log, sorted. Drives the Kontext filter.
 *
 * @return list<string>
 */
function admin_log_distinct_contexts(mysqli $con): array
{
    $table = AUTH_DB_PREFIX . 'auth_log';
    $out   = [];
    if ($res = $con->query("SELECT DISTINCT context FROM {$table} WHERE context <> '' ORDER BY context")) {
        while ($row = $res->fetch_row()) {
            $out[] = $row[0];
        }
        $res->free();
    }
    return $out;
}
```

- [ ] **Step 2: Lint**

Run: `php -l /Users/erikr/Git/Energie/inc/admin_log.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Manual SQL smoke-test**

While logged in on local dev, paste this into the MySQL CLI to prove the JOIN and bind_param work:

```bash
mysql -u root jardyx_auth -e "SELECT l.id, l.logTime, l.origin, l.context, l.activity, INET_NTOA(l.ipAdress) AS ip, a.username FROM auth_log l LEFT JOIN auth_accounts a ON a.id = l.idUser ORDER BY l.logTime DESC LIMIT 5"
```

Expected: 5 rows with `username` populated for authenticated events and NULL for `idUser=0` rows (the `auth_fail` ones).

- [ ] **Step 4: Commit**

```bash
git add inc/admin_log.php
git commit -m "feat(admin): add admin_log data access helpers

Prepared-statement list/count with substring search, date-range filter,
and fail-only toggle. LEFT JOINs auth_accounts to surface usernames on
authenticated events without hiding anonymous auth_fail rows.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Phase 3 — API endpoints for user CRUD

### Task 4: Add `admin_user_*` endpoints to `web/api.php`

**Files:**
- Modify: `/Users/erikr/Git/Energie/web/api.php` — append new `$type ===` branches after the existing `set-theme` branch

- [ ] **Step 1: Add a shared prelude for admin endpoints**

Find the last `elseif ($type === 'set-theme')` block in `web/api.php`. After its closing `}`, add the four new branches below. **Each endpoint MUST:**

1. Call `admin_require()` (already loaded via `initialize.php`; redirects non-admins — but for JSON we need to 403 instead, so we check `$_SESSION['rights']` inline rather than calling the function which does `header('Location: …')`)
2. Verify CSRF via `csrf_verify()` (POST only)
3. Return `application/json` (already set by the header at the top of api.php)

Add this block at the end of the `elseif` chain:

```php
} elseif (
    $type === 'admin_user_create' ||
    $type === 'admin_user_edit'   ||
    $type === 'admin_user_reset'  ||
    $type === 'admin_user_delete'
) {
    if (($_SESSION['rights'] ?? '') !== 'Admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ungültige Anfrage.']);
        exit;
    }

    if ($type === 'admin_user_create') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $email    = trim((string) ($_POST['email']    ?? ''));
        $rights   = (string) ($_POST['rights'] ?? 'User');
        if ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['ok' => false, 'error' => 'Benutzername und gültige E-Mail erforderlich.']);
            exit;
        }
        try {
            admin_create_user($con, $username, $email, $rights, APP_BASE_URL);
            appendLog($con, 'admin', "Created user {$username} ({$email})");
            echo json_encode(['ok' => true]);
        } catch (\mysqli_sql_exception $e) {
            echo json_encode(['ok' => false, 'error' => 'Benutzername oder E-Mail bereits vergeben.']);
        }
        exit;
    }

    if ($type === 'admin_user_edit') {
        $targetId  = (int) ($_POST['id'] ?? 0);
        $email     = trim((string) ($_POST['email'] ?? ''));
        $rights    = (string) ($_POST['rights'] ?? 'User');
        $disabled  = (int) !empty($_POST['disabled']);
        $debug     = (int) !empty($_POST['debug']);
        $totpReset = !empty($_POST['totp_reset']);
        if ($targetId <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['ok' => false, 'error' => 'Ungültige Eingabe.']);
            exit;
        }
        admin_edit_user($con, $targetId, $email, $rights, $disabled, $debug, $totpReset);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($type === 'admin_user_reset') {
        $targetId = (int) ($_POST['id'] ?? 0);
        if ($targetId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Ungültige ID.']);
            exit;
        }
        $ok = admin_reset_password($con, $targetId, APP_BASE_URL);
        echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => 'E-Mail konnte nicht gesendet werden.']);
        exit;
    }

    if ($type === 'admin_user_delete') {
        $targetId = (int) ($_POST['id'] ?? 0);
        $selfId   = (int) ($_SESSION['id'] ?? 0);
        if ($targetId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Ungültige ID.']);
            exit;
        }
        if ($targetId === $selfId) {
            echo json_encode(['ok' => false, 'error' => 'Sie können sich nicht selbst löschen.']);
            exit;
        }
        $ok = admin_delete_user($con, $targetId, $selfId);
        echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => 'Löschen fehlgeschlagen.']);
        exit;
    }
}
```

- [ ] **Step 2: Lint**

Run: `php -l /Users/erikr/Git/Energie/web/api.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add web/api.php
git commit -m "feat(admin): add admin_user_* JSON endpoints to api.php

Admin-gated, CSRF-verified, JSON-in/JSON-out. Mirrors the existing
admin.php POST handlers but returns structured responses suitable for
modal-driven async submission.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Phase 4 — Admin page rewrite

### Task 5: Add tab CSS to `web/styles/energie.css`

**Files:**
- Modify: `/Users/erikr/Git/Energie/web/styles/energie.css` — append new block at the end

- [ ] **Step 1: Append the tab styles**

Add this block to the end of `energie.css`:

```css
/* ---- Admin tabs ------------------------------------------------------ */
.admin-tabs {
    display: flex;
    gap: 0.25rem;
    border-bottom: 1px solid var(--color-border);
    margin-bottom: 1.5rem;
}
.tab-link {
    display: inline-block;
    padding: 0.6rem 1.2rem;
    color: var(--color-text-muted);
    text-decoration: none;
    border-bottom: 2px solid transparent;
    font-weight: 500;
    transition: color 120ms, border-color 120ms;
}
.tab-link:hover {
    color: var(--color-text);
}
.tab-link.active {
    color: var(--color-primary);
    border-bottom-color: var(--color-primary);
}

/* ---- Admin alert container ------------------------------------------- */
#adminAlerts {
    position: fixed;
    top: calc(var(--header-height, 56px) + 1rem);
    right: 1rem;
    max-width: 360px;
    z-index: 1100;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    pointer-events: none;
}
#adminAlerts .alert {
    pointer-events: auto;
    box-shadow: var(--shadow-md);
}

/* ---- Log table activity wrapping ------------------------------------- */
.log-table td.log-activity {
    word-break: break-word;
    max-width: 36rem;
}
.log-table td.log-time {
    font-family: var(--font-mono);
    white-space: nowrap;
}
```

- [ ] **Step 2: Verify CSS custom properties exist**

Run: `grep -n "\-\-color-border\|\-\-color-text-muted\|\-\-color-primary\|\-\-shadow-md\|\-\-font-mono" /Users/erikr/Git/css/theme.css /Users/erikr/Git/Energie/web/styles/energie-theme.css 2>&1 | head -20`
Expected: all five tokens resolve in either the shared `theme.css` or Energie's `energie-theme.css`. If any are missing, add them to `energie-theme.css` as an override in all three dark-mode blocks.

- [ ] **Step 3: Commit**

```bash
git add web/styles/energie.css
git commit -m "style(admin): add tab, alert toast, and log table styles

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 6: Rewrite `web/admin.php` — tabs shell + shared bits

**Files:**
- Modify: `/Users/erikr/Git/Energie/web/admin.php` — full rewrite

This is a large rewrite. Rather than step-by-step patches, write the entire new file in one go. The file has five logical parts:

1. Top — require, `auth_require`, `admin_require`, tab resolution
2. Data fetch — users-tab data (if active) or log-tab data (if active)
3. HTML head
4. Body: header include, tab nav, active tab card, modals
5. Inline JS with CSP nonce

- [ ] **Step 1: Include the new data layer**

Add `require_once __DIR__ . '/../inc/admin_log.php';` near the top.

- [ ] **Step 2: Write the entire file**

Replace the contents of `web/admin.php` with this:

```php
<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/admin_log.php';
auth_require();
admin_require();

$selfId = (int) $_SESSION['id'];
$tab    = $_GET['tab'] ?? 'users';
if ($tab !== 'users' && $tab !== 'log') {
    $tab = 'users';
}

// --- Users tab data -------------------------------------------------------
$users    = [];
$total    = 0;
$page     = 1;
$lastPage = 1;
$filter   = '';
$perPage  = 25;
if ($tab === 'users') {
    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $filter  = trim((string) ($_GET['filter'] ?? ''));
    $listing = admin_list_users($con, $page, $perPage, $filter);
    $users   = $listing['users'];
    $total   = $listing['total'];
    $lastPage = max(1, (int) ceil($total / $perPage));
}

// --- Log tab data ---------------------------------------------------------
$logRows       = [];
$logTotal      = 0;
$logPage       = 1;
$logLastPage   = 1;
$logPerPage    = 50;
$logApps       = [];
$logContexts   = [];
$logFilters    = [
    'app'     => '',
    'context' => '',
    'user'    => '',
    'from'    => '',
    'to'      => '',
    'q'       => '',
    'fail'    => '',
];
if ($tab === 'log') {
    $logPage = max(1, (int) ($_GET['log_page'] ?? 1));
    $logFilters['app']     = trim((string) ($_GET['log_app']     ?? ''));
    $logFilters['context'] = trim((string) ($_GET['log_context'] ?? ''));
    $logFilters['user']    = trim((string) ($_GET['log_user']    ?? ''));
    $logFilters['from']    = trim((string) ($_GET['log_from']    ?? ''));
    $logFilters['to']      = trim((string) ($_GET['log_to']      ?? ''));
    $logFilters['q']       = trim((string) ($_GET['log_q']       ?? ''));
    $logFilters['fail']    = !empty($_GET['log_fail']) ? '1' : '';

    // Default to last 7 days if neither from nor to is set.
    $logDefaulted = false;
    if ($logFilters['from'] === '' && $logFilters['to'] === '') {
        $logFilters['from'] = date('Y-m-d', strtotime('-7 days'));
        $logFilters['to']   = date('Y-m-d');
        $logDefaulted = true;
    }

    $logData     = admin_log_list($con, $logPage, $logPerPage, $logFilters);
    $logRows     = $logData['rows'];
    $logTotal    = $logData['total'];
    $logLastPage = max(1, (int) ceil($logTotal / $logPerPage));
    $logApps     = admin_log_distinct_apps($con);
    $logContexts = admin_log_distinct_contexts($con);
}

$csrfToken = csrf_token();

/** Build a query string for tab links preserving nothing. */
function tab_url(string $t): string {
    return 'admin.php?tab=' . urlencode($t);
}

/** Build a query string for log pagination preserving all filters. */
function log_page_url(int $p, array $filters): string {
    $qs = ['tab' => 'log', 'log_page' => $p];
    foreach ($filters as $k => $v) {
        if ($v !== '' && $v !== null) {
            $qs['log_' . $k] = $v;
        }
    }
    return 'admin.php?' . http_build_query($qs);
}

/** Build a query string for user pagination preserving the filter. */
function user_page_url(int $p, string $filter): string {
    $qs = ['tab' => 'users', 'page' => $p];
    if ($filter !== '') {
        $qs['filter'] = $filter;
    }
    return 'admin.php?' . http_build_query($qs);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administration · Energie</title>
    <link rel="stylesheet" href="<?= $base ?>/styles/shared/theme.css">
    <link rel="stylesheet" href="<?= $base ?>/styles/shared/reset.css">
    <link rel="stylesheet" href="<?= $base ?>/styles/shared/layout.css">
    <link rel="stylesheet" href="<?= $base ?>/styles/shared/components.css">
    <link rel="stylesheet" href="<?= $base ?>/styles/energie-theme.css">
    <link rel="stylesheet" href="<?= $base ?>/styles/energie.css">
    <?php if ($tab === 'log'): ?>
    <link rel="stylesheet" href="<?= $base ?>/assets/flatpickr.min.css">
    <?php endif; ?>
    <link rel="icon" type="image/x-icon" href="<?= $base ?>/assets/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $base ?>/assets/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= $base ?>/assets/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= $base ?>/assets/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= $base ?>/assets/web-app-manifest-192x192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="<?= $base ?>/assets/web-app-manifest-512x512.png">
</head>
<body>
<?php $page_type = 'admin'; require __DIR__ . '/../inc/_header.php'; ?>
<main>
    <div class="pref-section">

        <div id="adminAlerts"></div>

        <nav class="admin-tabs">
            <a class="tab-link <?= $tab === 'users' ? 'active' : '' ?>" href="<?= tab_url('users') ?>">Benutzerverwaltung</a>
            <a class="tab-link <?= $tab === 'log'   ? 'active' : '' ?>" href="<?= tab_url('log')   ?>">Log</a>
        </nav>

        <?php if ($tab === 'users'): ?>
            <?php require __DIR__ . '/../inc/_admin_users_tab.php'; ?>
        <?php else: ?>
            <?php require __DIR__ . '/../inc/_admin_log_tab.php'; ?>
        <?php endif; ?>

    </div>
</main>

<?php if ($tab === 'users'): ?>
<?php require __DIR__ . '/../inc/_admin_user_modals.php'; ?>
<?php endif; ?>

<?php require __DIR__ . '/../inc/_footer.php'; ?>

<script nonce="<?= $_cspNonce ?>">
const CSRF = <?= json_encode($csrfToken) ?>;

function showAlert(msg, type) {
    const box = document.getElementById('adminAlerts');
    if (!box) return;
    const div = document.createElement('div');
    div.className = 'alert alert-' + (type || 'info');
    div.textContent = msg;
    box.appendChild(div);
    setTimeout(() => div.remove(), 5000);
}

async function adminPost(type, params) {
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    for (const [k, v] of Object.entries(params)) fd.append(k, v);
    const r = await fetch('api.php?type=' + encodeURIComponent(type), { method: 'POST', body: fd });
    return r.json();
}

function openModal(id)  { document.getElementById(id)?.classList.add('show'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('show'); }

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-modal-open]').forEach(btn =>
        btn.addEventListener('click', () => openModal(btn.dataset.modalOpen))
    );
    document.querySelectorAll('[data-modal-close]').forEach(btn =>
        btn.addEventListener('click', () => {
            const m = btn.closest('.modal');
            if (m) m.classList.remove('show');
        })
    );
    document.querySelectorAll('.modal').forEach(m =>
        m.addEventListener('click', e => { if (e.target === m) m.classList.remove('show'); })
    );

    <?php if ($tab === 'users'): ?>
    const editModal   = document.getElementById('editModal');
    const editForm    = document.getElementById('editForm');
    const createForm  = document.getElementById('createForm');

    document.querySelectorAll('.btn-edit').forEach(btn => {
        btn.addEventListener('click', () => {
            document.getElementById('editId').value          = btn.dataset.id;
            document.getElementById('editUsername').textContent = btn.dataset.username;
            document.getElementById('editEmail').value       = btn.dataset.email;
            document.getElementById('editRights').value      = btn.dataset.rights;
            document.getElementById('editDisabled').checked  = btn.dataset.disabled === '1';
            document.getElementById('editDebug').checked     = btn.dataset.debug === '1';
            document.getElementById('editTotpReset').checked = false;
        });
    });

    editForm?.addEventListener('submit', async e => {
        e.preventDefault();
        const fd = new FormData(e.target);
        fd.delete('csrf_token');
        const res = await adminPost('admin_user_edit', Object.fromEntries(fd));
        if (res.ok) {
            showAlert('Gespeichert.', 'success');
            closeModal('editModal');
            setTimeout(() => location.reload(), 700);
        } else {
            showAlert(res.error || 'Fehler beim Speichern.', 'danger');
        }
    });

    createForm?.addEventListener('submit', async e => {
        e.preventDefault();
        const fd = new FormData(e.target);
        fd.delete('csrf_token');
        const res = await adminPost('admin_user_create', Object.fromEntries(fd));
        if (res.ok) {
            showAlert('Einladung versandt an ' + fd.get('email') + '.', 'success');
            closeModal('createModal');
            e.target.reset();
            setTimeout(() => location.reload(), 700);
        } else {
            showAlert(res.error || 'Unbekannter Fehler.', 'danger');
        }
    });

    document.querySelectorAll('.btn-reset').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!confirm('Einladungs-E-Mail erneut senden?')) return;
            const res = await adminPost('admin_user_reset', { id: btn.dataset.id });
            showAlert(res.ok ? 'E-Mail versandt.' : (res.error || 'Fehler.'), res.ok ? 'success' : 'danger');
        });
    });

    document.querySelectorAll('.btn-delete').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!confirm('Benutzer «' + btn.dataset.username + '» wirklich löschen?')) return;
            const res = await adminPost('admin_user_delete', { id: btn.dataset.id });
            if (res.ok) {
                showAlert('Gelöscht.', 'success');
                setTimeout(() => location.reload(), 700);
            } else {
                showAlert(res.error || 'Löschen fehlgeschlagen.', 'danger');
            }
        });
    });
    <?php endif; ?>

    <?php if ($tab === 'log'): ?>
    if (window.flatpickr) {
        flatpickr('#log_from', { dateFormat: 'Y-m-d' });
        flatpickr('#log_to',   { dateFormat: 'Y-m-d' });
    }
    <?php endif; ?>
});
</script>

<?php if ($tab === 'log'): ?>
<script nonce="<?= $_cspNonce ?>" src="<?= $base ?>/assets/flatpickr.min.js"></script>
<?php endif; ?>

</body>
</html>
```

- [ ] **Step 3: Lint**

Run: `php -l /Users/erikr/Git/Energie/web/admin.php`
Expected: `No syntax errors detected`

Note: the `_admin_users_tab.php`, `_admin_log_tab.php`, and `_admin_user_modals.php` includes don't exist yet. The lint will pass (require at runtime, not parse time) but the page will fatal-error when opened. Tasks 7–9 create them.

- [ ] **Step 4: Do NOT commit yet** — the includes are missing and the page is non-functional. Commit at the end of Task 9.

---

### Task 7: Create `inc/_admin_users_tab.php`

**Files:**
- Create: `/Users/erikr/Git/Energie/inc/_admin_users_tab.php`

- [ ] **Step 1: Write the partial**

```php
<?php
/**
 * inc/_admin_users_tab.php — Benutzerverwaltung tab body.
 * Required from admin.php. Expects:
 *   $users, $total, $page, $lastPage, $filter, $perPage, $selfId
 */
?>
<div class="pref-card">
    <div class="pref-card-hdr">Benutzerverwaltung</div>
    <div class="pref-card-body">

        <div class="form-inline" style="margin-bottom:1rem; display:flex; justify-content:space-between; gap:1rem; align-items:center">
            <button type="button" class="btn btn-primary" data-modal-open="createModal">
                + Benutzer anlegen
            </button>
            <form method="get" action="admin.php" class="form-inline" style="display:flex; gap:.5rem">
                <input type="hidden" name="tab" value="users">
                <input type="text" name="filter" class="form-control"
                       placeholder="Benutzername suchen"
                       value="<?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="btn">Suchen</button>
                <?php if ($filter !== ''): ?>
                    <a href="admin.php?tab=users" class="btn">Zurücksetzen</a>
                <?php endif; ?>
            </form>
        </div>

        <table class="table">
            <thead>
                <tr>
                    <th>Benutzername</th>
                    <th>E-Mail</th>
                    <th>Rechte</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($u['email'],    ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($u['rights'],   ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <?= $u['disabled']
                            ? '<span class="badge badge-danger">deaktiviert</span>'
                            : '<span class="badge badge-success">aktiv</span>' ?>
                        <?php if ($u['debug']): ?><span class="badge">debug</span><?php endif; ?>
                    </td>
                    <td style="white-space:nowrap">
                        <button type="button" class="btn btn-sm btn-edit"
                                data-modal-open="editModal"
                                data-id="<?= $u['id'] ?>"
                                data-username="<?= htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8') ?>"
                                data-email="<?= htmlspecialchars($u['email'],    ENT_QUOTES, 'UTF-8') ?>"
                                data-rights="<?= htmlspecialchars($u['rights'],   ENT_QUOTES, 'UTF-8') ?>"
                                data-disabled="<?= $u['disabled'] ?>"
                                data-debug="<?= $u['debug'] ?>">
                            Bearbeiten
                        </button>
                        <button type="button" class="btn btn-sm btn-reset"
                                data-id="<?= $u['id'] ?>">
                            Passwort-Reset
                        </button>
                        <?php if ($u['id'] !== $selfId): ?>
                            <button type="button" class="btn btn-sm btn-danger btn-delete"
                                    data-id="<?= $u['id'] ?>"
                                    data-username="<?= htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8') ?>">
                                Löschen
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($users)): ?>
                <tr><td colspan="5" class="text-muted">Keine Benutzer gefunden.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>

        <?php if ($lastPage > 1): ?>
            <nav class="pagination">
                <?php for ($p = 1; $p <= $lastPage; $p++): ?>
                    <a class="page-link<?= $p === $page ? ' active' : '' ?>"
                       href="<?= htmlspecialchars(user_page_url($p, $filter), ENT_QUOTES, 'UTF-8') ?>"><?= $p ?></a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>

    </div>
</div>
```

- [ ] **Step 2: Lint**

Run: `php -l /Users/erikr/Git/Energie/inc/_admin_users_tab.php`
Expected: `No syntax errors detected`

---

### Task 8: Create `inc/_admin_user_modals.php`

**Files:**
- Create: `/Users/erikr/Git/Energie/inc/_admin_user_modals.php`

- [ ] **Step 1: Write the partial**

```php
<?php
/**
 * inc/_admin_user_modals.php — Create and edit modals for the users tab.
 * Required from admin.php when $tab === 'users'.
 */
?>
<!-- Create Modal -->
<div class="modal" id="createModal" aria-labelledby="createModalTitle">
    <div class="modal-dialog">
        <div class="modal-header">
            <h5 class="modal-title" id="createModalTitle">Benutzer anlegen</h5>
            <button type="button" class="btn-close" data-modal-close aria-label="Schließen">&times;</button>
        </div>
        <form id="createForm">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div class="form-group">
                    <label for="createUsername">Benutzername</label>
                    <input type="text" id="createUsername" name="username" class="form-control" required autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="createEmail">E-Mail</label>
                    <input type="email" id="createEmail" name="email" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="createRights">Rechte</label>
                    <select id="createRights" name="rights" class="form-control">
                        <option value="User">User</option>
                        <option value="Admin">Admin</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-modal-close>Abbrechen</button>
                <button type="submit" class="btn btn-primary">Anlegen &amp; einladen</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal" id="editModal" aria-labelledby="editModalTitle">
    <div class="modal-dialog">
        <div class="modal-header">
            <h5 class="modal-title" id="editModalTitle">Benutzer bearbeiten: <span id="editUsername"></span></h5>
            <button type="button" class="btn-close" data-modal-close aria-label="Schließen">&times;</button>
        </div>
        <form id="editForm">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="id" id="editId">
                <div class="form-group">
                    <label for="editEmail">E-Mail</label>
                    <input type="email" id="editEmail" name="email" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="editRights">Rechte</label>
                    <select id="editRights" name="rights" class="form-control">
                        <option value="User">User</option>
                        <option value="Admin">Admin</option>
                    </select>
                </div>
                <div class="form-check">
                    <input type="checkbox" id="editDisabled" name="disabled" value="1">
                    <label for="editDisabled">Deaktiviert</label>
                </div>
                <div class="form-check">
                    <input type="checkbox" id="editDebug" name="debug" value="1">
                    <label for="editDebug">Debug-Modus</label>
                </div>
                <div class="form-check">
                    <input type="checkbox" id="editTotpReset" name="totp_reset" value="1">
                    <label for="editTotpReset">2FA zurücksetzen</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-modal-close>Abbrechen</button>
                <button type="submit" class="btn btn-primary">Speichern</button>
            </div>
        </form>
    </div>
</div>
```

- [ ] **Step 2: Lint**

Run: `php -l /Users/erikr/Git/Energie/inc/_admin_user_modals.php`
Expected: `No syntax errors detected`

---

### Task 9: Create `inc/_admin_log_tab.php`

**Files:**
- Create: `/Users/erikr/Git/Energie/inc/_admin_log_tab.php`

- [ ] **Step 1: Write the partial**

```php
<?php
/**
 * inc/_admin_log_tab.php — Log tab body.
 * Required from admin.php when $tab === 'log'. Expects:
 *   $logRows, $logTotal, $logPage, $logLastPage, $logPerPage,
 *   $logApps, $logContexts, $logFilters
 */
?>
<div class="pref-card">
    <div class="pref-card-hdr">Log (<?= (int) $logTotal ?> Einträge)</div>
    <div class="pref-card-body">

        <form method="get" action="admin.php" class="form-inline" style="display:flex; flex-wrap:wrap; gap:.5rem; margin-bottom:1rem; align-items:end">
            <input type="hidden" name="tab" value="log">

            <div class="form-group">
                <label for="log_app">App</label>
                <select id="log_app" name="log_app" class="form-control">
                    <option value="">Alle</option>
                    <?php foreach ($logApps as $a): ?>
                        <option value="<?= htmlspecialchars($a, ENT_QUOTES, 'UTF-8') ?>" <?= $logFilters['app'] === $a ? 'selected' : '' ?>>
                            <?= htmlspecialchars($a, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="log_context">Kontext</label>
                <select id="log_context" name="log_context" class="form-control">
                    <option value="">Alle</option>
                    <?php foreach ($logContexts as $c): ?>
                        <option value="<?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?>" <?= $logFilters['context'] === $c ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="log_user">Benutzer</label>
                <input type="text" id="log_user" name="log_user" class="form-control"
                       value="<?= htmlspecialchars($logFilters['user'], ENT_QUOTES, 'UTF-8') ?>" placeholder="username">
            </div>

            <div class="form-group">
                <label for="log_from">Von</label>
                <input type="text" id="log_from" name="log_from" class="form-control"
                       value="<?= htmlspecialchars($logFilters['from'], ENT_QUOTES, 'UTF-8') ?>" placeholder="YYYY-MM-DD">
            </div>

            <div class="form-group">
                <label for="log_to">Bis</label>
                <input type="text" id="log_to" name="log_to" class="form-control"
                       value="<?= htmlspecialchars($logFilters['to'], ENT_QUOTES, 'UTF-8') ?>" placeholder="YYYY-MM-DD">
            </div>

            <div class="form-group" style="flex:1; min-width:14rem">
                <label for="log_q">Suche in Aktivität</label>
                <input type="text" id="log_q" name="log_q" class="form-control"
                       value="<?= htmlspecialchars($logFilters['q'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Text">
            </div>

            <div class="form-check" style="align-self:center">
                <input type="checkbox" id="log_fail" name="log_fail" value="1" <?= $logFilters['fail'] ? 'checked' : '' ?>>
                <label for="log_fail">nur Fehler</label>
            </div>

            <div class="form-group" style="display:flex; gap:.5rem">
                <button type="submit" class="btn btn-primary">Filter</button>
                <a class="btn" href="admin.php?tab=log">Zurücksetzen</a>
            </div>
        </form>

        <table class="table log-table">
            <thead>
                <tr>
                    <th>Zeit</th>
                    <th>App</th>
                    <th>Kontext</th>
                    <th>Benutzer</th>
                    <th>IP</th>
                    <th>Aktivität</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($logRows as $r): ?>
                <tr>
                    <td class="log-time"><?= htmlspecialchars($r['logTime'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['origin'],  ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['context'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= $r['username'] !== null ? htmlspecialchars($r['username'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>' ?></td>
                    <td><?= $r['ip'] !== null ? htmlspecialchars($r['ip'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>' ?></td>
                    <td class="log-activity"><?= htmlspecialchars($r['activity'], ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($logRows)): ?>
                <tr><td colspan="6" class="text-muted">Keine Einträge gefunden.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>

        <?php if ($logLastPage > 1): ?>
            <nav class="pagination">
                <?php for ($p = 1; $p <= $logLastPage; $p++): ?>
                    <a class="page-link<?= $p === $logPage ? ' active' : '' ?>"
                       href="<?= htmlspecialchars(log_page_url($p, $logFilters), ENT_QUOTES, 'UTF-8') ?>"><?= $p ?></a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>

    </div>
</div>
```

- [ ] **Step 2: Lint**

Run: `php -l /Users/erikr/Git/Energie/inc/_admin_log_tab.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Check Flatpickr assets are present**

Run: `ls /Users/erikr/Git/Energie/web/assets/flatpickr.min.* 2>&1`
Expected: both `.css` and `.js` exist. If missing, they need to be added (search the rest of Energie — other chart pages already use Flatpickr, so the files must exist; check `web/styles/` or `web/js/` instead and adjust the `<link>` and `<script>` paths in `admin.php` accordingly).

- [ ] **Step 4: Commit all of Phase 4**

```bash
git add web/admin.php inc/_admin_users_tab.php inc/_admin_user_modals.php inc/_admin_log_tab.php web/styles/energie.css
git commit -m "feat(admin): two-tab admin page with modal CRUD and log viewer

- Benutzerverwaltung tab with modal-based create/edit, API-driven
  actions via api.php?type=admin_user_*, inline toast feedback
- Log tab with App/Kontext/User/date/search/fail filters, joined to
  auth_accounts for username display, 50 rows per page
- Shared .modal / .pref-card / .table styling from components.css
- Tab chrome and log-specific tweaks in energie.css

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Phase 5 — Legacy cleanup + deploy

### Task 10: Delete legacy `origin='web'` rows on both databases

**Rationale:** the `origin='web'` rows predate app slugs and cannot be attributed to a specific app on the shared `jardyx_auth` database (akadbrain). On world4you the DB is single-app, but we apply the same rule for consistency.

**This is destructive. Require explicit user go-ahead before running.**

- [ ] **Step 1: Count what will be deleted (world4you)**

```bash
ssh -i ~/.ssh/id_rsa_world4you ftp5279249@www11.world4you.com \
  "mysql -h mysqlsvr78.world4you.com -u sql6675098 -p'hipsuc-vychAw-7qonko' 5279249db19 \
   -e \"SELECT COUNT(*) FROM auth_log WHERE origin='web'\""
```

Expected: a single number. Before running the delete, report it to the user and wait for confirmation.

- [ ] **Step 2: Count what will be deleted (akadbrain)**

If Energie is deployed to akadbrain (check `mcp/config.yaml` for an `akadbrain` target under `apps.energie`), run the equivalent query against the akadbrain MariaDB. If not, skip this step.

- [ ] **Step 3: Run the delete (world4you) after user confirms**

```bash
ssh -i ~/.ssh/id_rsa_world4you ftp5279249@www11.world4you.com \
  "mysql -h mysqlsvr78.world4you.com -u sql6675098 -p'hipsuc-vychAw-7qonko' 5279249db19 \
   -e \"DELETE FROM auth_log WHERE origin='web'\""
```

Verify the row count dropped by the expected number.

- [ ] **Step 4: Run the delete (akadbrain) after user confirms**

Only if akadbrain hosts Energie. Same SQL, different credentials.

---

### Task 11: Deploy to world4you

- [ ] **Step 1: Push the auth library update**

Since `erikr/auth` is a path dependency (`composer.json` `repositories: [{type: path, url: ../auth}]`), changes in `/Users/erikr/git/auth/src/log.php` must be picked up by Composer before `deploy.py` runs. From the Energie repo:

```bash
cd /Users/erikr/Git/Energie
composer update erikr/auth 2>&1 | tail -10
```

Expected: `Updating erikr/auth (...)` with no errors. `vendor/erikr/auth/src/log.php` should now contain the new `appendLog()` body.

- [ ] **Step 2: Deploy**

```bash
cd /Users/erikr/Git/mcp && python deploy.py energie world4you 2>&1 | tail -20
```

Expected: `✓ Done: energie → world4you` and the rsync file list includes `vendor/erikr/auth/src/log.php`, `web/admin.php`, `web/api.php`, `web/styles/energie.css`, `inc/admin_log.php`, `inc/_admin_users_tab.php`, `inc/_admin_user_modals.php`, `inc/_admin_log_tab.php`, `inc/initialize.php`.

---

### Task 12: Smoke test in production

- [ ] **Step 1: Log in and confirm log tagging**

Open `https://energie.jardyx.com/admin.php` and log in as Erik. Then:

```bash
ssh -i ~/.ssh/id_rsa_world4you ftp5279249@www11.world4you.com \
  "mysql -h mysqlsvr78.world4you.com -u sql6675098 -p'hipsuc-vychAw-7qonko' 5279249db19 \
   -e \"SELECT id, origin, context, activity FROM auth_log ORDER BY id DESC LIMIT 3\""
```

Expected: the top row is the "Erik logged in." event with `origin='energie'`.

- [ ] **Step 2: Open the Log tab**

Click the Log tab. Verify:
  - Table renders
  - App dropdown shows only `energie`
  - Kontext dropdown includes `auth`, `auth_fail`, `admin`, etc.
  - Defaults to last 7 days
  - "nur Fehler" checkbox filters to `context LIKE '%fail%'`
  - Date pickers work
  - Pagination page links carry filters

- [ ] **Step 3: Test user CRUD flow**

  - Open the create modal, create a dummy user (use a throwaway email), verify toast and row appears
  - Open edit modal on the dummy user, toggle a flag, save, verify toast
  - Passwort-Reset → verify toast
  - Löschen → verify toast + row disappears
  - Attempt to delete yourself → verify the Löschen button is not rendered on your own row

- [ ] **Step 4: Test failed-login logging**

Log out. Attempt to log in with a wrong password. Go back in as admin, open Log tab with "nur Fehler" on. The failed attempt should appear with `context='auth_fail'` and activity string including the attempted username and the error message.

- [ ] **Step 5: Negative permissions check**

Create a second dummy user with `rights='User'`, log in as them, try to hit `https://energie.jardyx.com/admin.php` and `https://energie.jardyx.com/api.php?type=admin_user_delete` (POST via curl with their session cookie). Both should redirect (admin.php) or return `{ok: false, error: 'Forbidden'}` with HTTP 403 (api.php).

---

## Self-Review

**Spec coverage:**
- §1 Goals → Phases 1–4 all map
- §3 App identification + library change → Tasks 1, 2
- §3 Legacy cleanup → Task 10
- §4 Tabs → Task 6
- §4.1 Benutzerverwaltung modal CRUD → Tasks 6, 7, 8, plus the api endpoints in Task 4
- §4.2 Log tab → Tasks 3, 9
- §5.1 Data access → Task 3
- §5.3 API endpoints → Task 4
- §6 File changes summary → all files accounted for
- §7 Permissions → Task 4 (inline 403), Task 12 negative check
- §8 Error handling → Task 4 JSON error shapes
- §9 Testing checklist → Task 12

All spec sections have a corresponding task.

**Placeholder scan:** No TBD / TODO / "similar to" / "add appropriate validation". All code blocks are complete.

**Type consistency:** `admin_log_list()` signature matches between spec §5.1 and Task 3. `$filters` keys (`app, context, user, from, to, q, fail`) are used consistently in Task 3 data layer and Task 6 controller. Modal IDs (`createModal`, `editModal`) match between Task 6 JS, Task 7 buttons, and Task 8 modal markup. `data-*` attributes on edit buttons in Task 7 exactly cover what Task 6 JS reads.

**One risk I want to flag explicitly:** Task 6 assumes `APP_BASE_URL` is already defined (it's used by the existing admin.php create-user flow). It is — `inc/initialize.php` sets it. No new dependency.
