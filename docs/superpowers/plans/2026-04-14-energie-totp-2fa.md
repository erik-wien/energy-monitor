# Energie TOTP 2FA Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add TOTP-based two-factor authentication to Energie, matching the shape of simplechat-2.1's integration with the shared `erikr/auth` library.

**Architecture:** Energie already uses `erikr/auth` via Composer path dependency. The library gained TOTP support (`src/totp.php` + the `totp_secret` column in `auth_accounts` + the `totp_required` branch inside `auth_login()` and the new `auth_totp_complete()` helper). This plan wires those hooks into Energie: runs the DB migration, pulls the new composer deps (chillerlan/php-qrcode comes transitively via `erikr/auth`), adds a mid-login `totp_verify.php` page, branches `authentication.php` on `totp_required`, and extends `preferences.php` with an enable/confirm/disable 2FA card.

**Tech Stack:** PHP 8.2+, MariaDB, `erikr/auth` (path dep), `chillerlan/php-qrcode` (transitive), vanilla CSS + shared jardyx theme tokens.

**Reference implementation:** `simplechat-2.1/web/authentication.php`, `simplechat-2.1/web/totp_verify.php`, `simplechat-2.1/web/security.php`. Don't copy them blindly — Energie's preferences are mounted into `preferences.php` (alongside avatar, theme, email, password), not a standalone `security.php`.

**Testing:** Energie has no PHP test suite — only Python tests for the pricing pipeline. Verification is `php -l` syntax checks plus a manual browser walkthrough against `http://localhost/energie.test` (dev) with a test account. Each task that changes PHP includes an explicit lint step; the final task does the end-to-end manual test.

---

## File Structure

| File | Action | Responsibility |
|---|---|---|
| `composer.json` / `composer.lock` / `vendor/` | Update | Pull `chillerlan/php-qrcode` via `composer update erikr/auth --with-all-dependencies` |
| `auth/db/06_totp.sql` (already in library) | Run | Add `totp_secret` column to `auth_accounts` |
| `web/authentication.php` | Modify | Branch on `$result['totp_required']` → redirect to `totp_verify.php` |
| `web/totp_verify.php` | Create | Mid-login page: reads `$_SESSION['auth_totp_pending']`, calls `auth_totp_complete()` |
| `web/preferences.php` | Modify | Add `totp_start` / `totp_confirm` / `totp_disable` POST actions and a "Zwei-Faktor-Authentifizierung" card in the preferences UI |
| `web/styles/energie.css` | Modify (minimal) | Add `.totp-code-input` style (centered, letter-spaced) and tweak `.pref-card` for the QR image if needed |

No changes needed in `inc/_header.php` — the existing dropdown menu already links to `preferences.php`, which is where 2FA management will live.

---

## Task 1: Database migration

**Files:**
- Run: `auth/db/06_totp.sql` against `jardyx_auth` (dev) and later against the production auth DB.

- [ ] **Step 1: Verify current auth_accounts schema**

Run:
```bash
mysql -u root -e "USE jardyx_auth; SHOW COLUMNS FROM auth_accounts LIKE 'totp_secret'"
```
Expected: empty output (column does not exist yet).

- [ ] **Step 2: Apply the migration locally**

Run:
```bash
mysql -u root jardyx_auth < /Users/erikr/Git/auth/db/06_totp.sql
```
The file contains:
```sql
USE jardyx_auth;
ALTER TABLE auth_accounts
  ADD COLUMN totp_secret VARCHAR(64) NULL DEFAULT NULL
  COMMENT 'Base32-encoded TOTP secret. NULL = 2FA disabled.';
```
Expected: no error. If the column already exists, MySQL emits `ERROR 1060 (42S21): Duplicate column name 'totp_secret'` — that's fine, treat as already-migrated.

- [ ] **Step 3: Verify the column exists**

Run:
```bash
mysql -u root -e "USE jardyx_auth; SHOW COLUMNS FROM auth_accounts LIKE 'totp_secret'"
```
Expected:
```
Field        Type         Null  Key  Default  Extra
totp_secret  varchar(64)  YES        NULL
```

- [ ] **Step 4: Note production migration in the commit message later**

Production uses the database `5279249db19` on world4you (per `~/Git/CLAUDE.md`). The migration file contains a hardcoded `USE jardyx_auth;`, so when running it against prod you must strip that line or run it as:
```bash
mysql -h world4you-host -u <user> -p 5279249db19 < /tmp/06_totp_no_use.sql
```
where `/tmp/06_totp_no_use.sql` is the same file without the `USE` statement. **Do not execute production migration as part of this task** — only note the command in the plan. Production migration is a final-task confirmation step.

---

## Task 2: Composer dependency refresh

**Files:**
- Modify: `composer.lock`, `vendor/`

- [ ] **Step 1: Inspect the current composer.lock for chillerlan**

Run:
```bash
cd /Users/erikr/Git/Energie && grep -c '"chillerlan/php-qrcode"' composer.lock
```
Expected: `0` (not yet pulled in).

- [ ] **Step 2: Update the erikr/auth path dependency and its transitive deps**

Run:
```bash
cd /Users/erikr/Git/Energie && php composer.phar update erikr/auth --with-all-dependencies
```
Expected output includes:
```
Package operations: … installs …
  - Installing chillerlan/php-qrcode (5.…)
  - Installing chillerlan/php-settings-container (…)
```
If composer complains about missing `ext-*` extensions (e.g. `ext-gd`), install them via Herd / brew before retrying — chillerlan/php-qrcode in SVG mode does not require GD, but the platform check may still trigger. Do **not** pass `--ignore-platform-reqs` blindly; investigate first.

- [ ] **Step 3: Verify the autoloader resolves the QRCode class**

Run:
```bash
cd /Users/erikr/Git/Energie && php -r 'require "vendor/autoload.php"; var_dump(class_exists("chillerlan\\QRCode\\QRCode"));'
```
Expected: `bool(true)`.

- [ ] **Step 4: Verify the library TOTP functions are loaded**

Run:
```bash
cd /Users/erikr/Git/Energie && php -r 'require "vendor/autoload.php"; var_dump(function_exists("auth_totp_enable"), function_exists("auth_totp_complete"));'
```
Expected: `bool(true)` twice.

- [ ] **Step 5: Commit**

```bash
cd /Users/erikr/Git/Energie && git add composer.lock vendor
git commit -m "chore: pull chillerlan/php-qrcode via erikr/auth update"
```

---

## Task 3: Branch authentication.php on totp_required

**Files:**
- Modify: `/Users/erikr/Git/Energie/web/authentication.php`

**Context:** `auth_login()` now returns `['ok' => true, 'totp_required' => true]` when the user has a non-null `totp_secret`. The session payload (`$_SESSION['auth_totp_pending']`) is already populated by the library — the consumer app only needs to redirect instead of falling through to the normal "welcome" path.

- [ ] **Step 1: Read the current authentication.php**

Run: `cat /Users/erikr/Git/Energie/web/authentication.php`
Expected: the file currently has a single success branch:
```php
$result = auth_login($con, $_POST['login-username'], $_POST['login-password']);

if ($result['ok']) {
    if (!empty($_POST['rememberName'])) { … setcookie(…); }
    else { … setcookie('', expire); }
    addAlert('info', 'Hallo ' . htmlspecialchars($result['username'], …));
    header('Location: ./'); exit;
} else {
    addAlert('danger', $result['error']);
    header('Location: login.php'); exit;
}
```

- [ ] **Step 2: Insert the totp_required branch immediately after the auth_login call**

Use Edit to replace:
```php
$result = auth_login($con, $_POST['login-username'], $_POST['login-password']);

if ($result['ok']) {
    if (!empty($_POST['rememberName'])) {
```
with:
```php
$result = auth_login($con, $_POST['login-username'], $_POST['login-password']);

if (!empty($result['ok']) && !empty($result['totp_required'])) {
    // Persist rememberName cookie intent for the post-TOTP redirect.
    if (!empty($_POST['rememberName'])) {
        setcookie('energie_username', $_POST['login-username'], [
            'expires'  => time() + 10 * 24 * 60 * 60,
            'path'     => '/',
            'httponly' => true,
            'secure'   => true,
            'samesite' => 'Lax',
        ]);
    } else {
        setcookie('energie_username', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'secure'   => true,
            'samesite' => 'Lax',
        ]);
    }
    header('Location: totp_verify.php'); exit;
}

if ($result['ok']) {
    if (!empty($_POST['rememberName'])) {
```

**Why we handle `rememberName` twice:** the cookie must be written before the session finishes because the user's browser session is not yet logged in at the totp_verify step — if we wait until after `auth_totp_complete()`, the cookie is still correctly set because `setcookie()` during the `authentication.php` request applies to the browser before the redirect, but an abandoned 2FA flow would also leave the rememberName preserved, which is what we want.

- [ ] **Step 3: Lint**

Run: `php -l /Users/erikr/Git/Energie/web/authentication.php`
Expected: `No syntax errors detected in …/authentication.php`

- [ ] **Step 4: Commit**

```bash
cd /Users/erikr/Git/Energie && git add web/authentication.php
git commit -m "feat(auth): redirect to totp_verify.php when 2FA required"
```

---

## Task 4: Create web/totp_verify.php

**Files:**
- Create: `/Users/erikr/Git/Energie/web/totp_verify.php`

**Context:** This page is unauthenticated but reads `$_SESSION['auth_totp_pending']`. On GET it shows the code input; on POST it calls `auth_totp_complete($con, $code)`. On success it flows through to `index.php`. On failure it re-renders with an error (the library manages the attempt counter and session TTL internally). Its HTML shell copies Energie's `login.php` — same `<header>`, same stylesheet block, same footer one-liner — so it feels like a continuation of the login flow.

- [ ] **Step 1: Create the file**

Write to `/Users/erikr/Git/Energie/web/totp_verify.php`:

```php
<?php
/**
 * web/totp_verify.php — Mid-login TOTP code entry page.
 *
 * Public page (no auth_require). Reads $_SESSION['auth_totp_pending'].
 * GET:  Show code entry form, or redirect to login if pending state is missing/expired.
 * POST: Call auth_totp_complete(), redirect to index on success or re-render on failure.
 */
require_once __DIR__ . '/../inc/initialize.php';

if (!empty($_SESSION['loggedin'])) {
    header('Location: index.php'); exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        addAlert('danger', 'Ungültige Anfrage.');
        header('Location: totp_verify.php'); exit;
    }

    $code   = trim($_POST['totp_code'] ?? '');
    $result = auth_totp_complete($con, $code);

    if ($result['ok']) {
        addAlert('info', 'Willkommen zurück.');
        header('Location: ./'); exit;
    }

    $error = $result['error'];
    // If the library cleared the pending state (TTL, max attempts), bounce to login.
    if (empty($_SESSION['auth_totp_pending'])) {
        addAlert('danger', $error);
        header('Location: login.php'); exit;
    }
}

// GET-side guard: if nothing pending or TTL expired, redirect to login.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $pending = $_SESSION['auth_totp_pending'] ?? null;
    if ($pending === null || time() > $pending['until']) {
        unset($_SESSION['auth_totp_pending']);
        addAlert('danger', 'Sitzung abgelaufen. Bitte erneut anmelden.');
        header('Location: login.php'); exit;
    }
}

$alerts = $_SESSION['alerts'] ?? [];
unset($_SESSION['alerts']);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Energie · Zwei-Faktor-Authentifizierung</title>
    <?php $_b = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'); ?>
    <link rel="stylesheet" href="<?= $_b ?>/styles/shared/theme.css">
    <link rel="stylesheet" href="<?= $_b ?>/styles/shared/reset.css">
    <link rel="stylesheet" href="<?= $_b ?>/styles/shared/layout.css">
    <link rel="stylesheet" href="<?= $_b ?>/styles/shared/components.css">
    <link rel="stylesheet" href="<?= $_b ?>/styles/energie-theme.css">
    <link rel="stylesheet" href="<?= $_b ?>/styles/energie.css">
    <link rel="icon" type="image/x-icon" href="<?= $_b ?>/img/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $_b ?>/img/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= $_b ?>/img/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= $_b ?>/img/apple-touch-icon.png">
</head>
<body>
<header class="app-header">
    <span class="brand">
        <img src="<?= $_b ?>/img/jardyx.svg" class="header-logo" width="28" height="28" alt="">
        <span class="header-appname">Energie</span>
    </span>
</header>
<div class="login-wrap">
    <div class="login-card">
        <h2>2FA-Code eingeben</h2>
        <p class="text-muted" style="margin-bottom:1rem">
            Gib den 6-stelligen Code aus deiner Authenticator-App ein.
        </p>

        <?php foreach ($alerts as [$type, $msg]): ?>
            <div class="alert alert-<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>">
                <?= $msg ?>
            </div>
        <?php endforeach; ?>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="post" action="totp_verify.php">
            <?= csrf_input() ?>
            <div class="form-group">
                <label for="totp_code">Authenticator-Code</label>
                <input type="text" id="totp_code" name="totp_code"
                       inputmode="numeric" maxlength="6" autocomplete="one-time-code"
                       required autofocus
                       class="totp-code-input">
            </div>
            <button type="submit" class="btn-login">Bestätigen</button>
        </form>
        <div class="login-links">
            <a href="login.php">Abbrechen und neu anmelden</a>
        </div>
    </div>
</div>
<?php echo '<footer class="app-footer"><span>&copy; ' . date('Y') . ' Erik R. Accart-Huemer</span> <a href="https://www.eriks.cloud/#impressum" target="_blank" rel="noopener">Impressum</a> <span>' . APP_NAME . ' ' . APP_VERSION . '.' . APP_BUILD . ' &middot; ' . APP_ENV . '</span></footer>'; ?>
</body>
</html>
```

- [ ] **Step 2: Lint**

Run: `php -l /Users/erikr/Git/Energie/web/totp_verify.php`
Expected: `No syntax errors detected in …/totp_verify.php`

- [ ] **Step 3: Commit**

```bash
cd /Users/erikr/Git/Energie && git add web/totp_verify.php
git commit -m "feat(auth): add mid-login TOTP verification page"
```

---

## Task 5: Add the .totp-code-input style

**Files:**
- Modify: `/Users/erikr/Git/Energie/web/styles/energie.css`

- [ ] **Step 1: Locate the existing login / preferences section**

Run: `grep -n "login-card\|\.login-wrap" /Users/erikr/Git/Energie/web/styles/energie.css`
Expected: a block of login-related rules somewhere in the file. Append the new rule right after that block so related styles stay together.

- [ ] **Step 2: Append the rule**

Add at the end of the login styles block (or at the end of the file if no obvious section marker):

```css
/* TOTP code input — centered, letter-spaced, monospace feel */
.totp-code-input {
    width: 100%;
    padding: .75rem 1rem;
    font-size: 1.4rem;
    text-align: center;
    letter-spacing: .25em;
    border: 1px solid var(--color-border);
    border-radius: var(--radius);
    background: var(--color-surface);
    color: var(--color-text);
}
.totp-code-input:focus {
    outline: 2px solid var(--color-accent);
    outline-offset: 2px;
}

/* Preferences → 2FA card helpers */
.totp-qr-wrap { margin: .75rem 0; }
.totp-qr-wrap img { display: block; width: 200px; height: 200px; }
.totp-secret {
    font-family: ui-monospace, SFMono-Regular, monospace;
    font-size: .95rem;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius);
    padding: .25rem .5rem;
    display: inline-block;
    word-break: break-all;
}
```

- [ ] **Step 3: Sanity check with a static CSS parser**

Run: `php -r 'echo file_get_contents("/Users/erikr/Git/Energie/web/styles/energie.css") ? "ok\n" : "missing\n";'`
Expected: `ok`. (We have no CSS linter; this just verifies the file is still readable.)

- [ ] **Step 4: Commit**

```bash
cd /Users/erikr/Git/Energie && git add web/styles/energie.css
git commit -m "style: add TOTP code input and 2FA card styles"
```

---

## Task 6: Add 2FA management to preferences.php

**Files:**
- Modify: `/Users/erikr/Git/Energie/web/preferences.php`

**Context:** The page already handles `upload_avatar`, `change_email`, `change_password`, `change_theme`. We add three new actions — `totp_start`, `totp_confirm`, `totp_disable` — and a new UI card. All three call into the auth library: `auth_totp_enable()`, `auth_totp_confirm()`, `auth_totp_disable()`, and `auth_totp_uri()`. QR rendering uses `chillerlan\QRCode\QRCode` in SVG mode.

- [ ] **Step 1: Read current $has2fa state before the POST block**

In preferences.php, immediately after the existing `$currentEmail = …` block (around line 16, after `$stmt->close();`), insert:

```php
// ── Current 2FA state ─────────────────────────────────────────────────────
$stmt = $con->prepare('SELECT totp_secret FROM auth_accounts WHERE id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
$has2fa = ($stmt->get_result()->fetch_assoc()['totp_secret'] ?? null) !== null;
$stmt->close();
```

Use Edit to replace:
```php
$currentEmail = htmlspecialchars(
    $stmt->get_result()->fetch_assoc()['email'] ?? '', ENT_QUOTES, 'UTF-8'
);
$stmt->close();

$errors = [];
```
with:
```php
$currentEmail = htmlspecialchars(
    $stmt->get_result()->fetch_assoc()['email'] ?? '', ENT_QUOTES, 'UTF-8'
);
$stmt->close();

// ── Current 2FA state ─────────────────────────────────────────────────────
$stmt = $con->prepare('SELECT totp_secret FROM auth_accounts WHERE id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
$has2fa = ($stmt->get_result()->fetch_assoc()['totp_secret'] ?? null) !== null;
$stmt->close();

$errors = [];
```

- [ ] **Step 2: Add the three POST handlers inside the existing POST block**

The existing POST block ends with the `change_theme` handler's closing braces and then the outer `}` that closes the `if ($_SERVER['REQUEST_METHOD'] === 'POST')` wrapper. Insert the three new handlers AFTER `change_theme`'s closing brace but BEFORE the outer POST block's closing brace. Use Edit to replace:

```php
            appendLog($con, 'prefs', 'Theme set to ' . $t, 'web');
            addAlert('success', 'Design gespeichert.');
            header('Location: preferences.php'); exit;
        }
    }
}
?>
```
with:
```php
            appendLog($con, 'prefs', 'Theme set to ' . $t, 'web');
            addAlert('success', 'Design gespeichert.');
            header('Location: preferences.php'); exit;
        }
    }

    // ── Start 2FA setup ───────────────────────────────────────────────────────
    if ($action === 'totp_start') {
        $secret = auth_totp_enable($con, $userId);
        if ($secret !== null) {
            $_SESSION['totp_setup_secret'] = [
                'secret' => $secret,
                'until'  => time() + 300,
            ];
        } else {
            $errors['totp'] = 'Konto nicht gefunden.';
        }
        if (empty($errors['totp'])) {
            header('Location: preferences.php'); exit;
        }
    }

    // ── Confirm 2FA setup ─────────────────────────────────────────────────────
    if ($action === 'totp_confirm') {
        $setupData = $_SESSION['totp_setup_secret'] ?? null;
        if ($setupData === null || time() > $setupData['until']) {
            unset($_SESSION['totp_setup_secret']);
            $errors['totp'] = 'Sitzung abgelaufen. Bitte erneut starten.';
        } else {
            $code = trim($_POST['totp_code'] ?? '');
            if (auth_totp_confirm($con, $userId, $setupData['secret'], $code)) {
                unset($_SESSION['totp_setup_secret']);
                appendLog($con, 'auth', ($_SESSION['username'] ?? '') . ' enabled 2FA.', 'web');
                addAlert('success', '2FA ist jetzt aktiv.');
                header('Location: preferences.php'); exit;
            }
            $errors['totp'] = 'Code ungültig. Bitte erneut versuchen.';
        }
    }

    // ── Disable 2FA ───────────────────────────────────────────────────────────
    if ($action === 'totp_disable') {
        auth_totp_disable($con, $userId);
        unset($_SESSION['totp_setup_secret']);
        appendLog($con, 'auth', ($_SESSION['username'] ?? '') . ' disabled 2FA.', 'web');
        addAlert('success', '2FA wurde deaktiviert.');
        header('Location: preferences.php'); exit;
    }
}
?>
```

- [ ] **Step 3: Prepare the QR code data before the HTML body**

Use Edit to replace:
```php
}
?>
<!DOCTYPE html>
```
with:
```php
}

// ── Prepare QR code for in-progress 2FA enrollment ────────────────────────────
$setupSecret = null;
$setupQrHtml = '';
$setupData   = $_SESSION['totp_setup_secret'] ?? null;
if (!$has2fa && $setupData !== null && time() <= $setupData['until']) {
    $setupSecret = $setupData['secret'];
    $uri         = auth_totp_uri(
        $setupSecret,
        ($_SESSION['username'] ?? 'user') . '@' . APP_NAME,
        APP_NAME
    );
    $options     = new \chillerlan\QRCode\QROptions([
        'outputType'  => 'svg',
        'imageBase64' => false,
    ]);
    $svg         = (new \chillerlan\QRCode\QRCode($options))->render($uri);
    $setupQrHtml = '<img src="data:image/svg+xml;base64,' . base64_encode($svg)
                 . '" width="200" height="200" alt="QR Code">';
}
?>
<!DOCTYPE html>
```

- [ ] **Step 4: Add the 2FA card to the preferences UI**

The existing last card in the grid is "Kennwort ändern". Use Edit to replace:

```php
                    <button class="btn btn-primary" type="submit">Speichern</button>
                </form>
            </div>
        </div>

    </div>
</main>
```
with:
```php
                    <button class="btn btn-primary" type="submit">Speichern</button>
                </form>
            </div>
        </div>

        <!-- Zwei-Faktor-Authentifizierung -->
        <div class="pref-card">
            <div class="pref-card-hdr">Zwei-Faktor-Authentifizierung</div>
            <div class="pref-card-body">
                <?php if (!empty($errors['totp'])): ?>
                    <div class="alert alert-danger">
                        <?= htmlspecialchars($errors['totp'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <?php if ($has2fa): ?>
                    <p class="text-muted" style="margin-bottom:.75rem">
                        Dein Konto ist mit einem TOTP-Authenticator gesichert.
                    </p>
                    <form method="post" action="preferences.php"
                          onsubmit="return confirm('2FA wirklich deaktivieren?');">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="totp_disable">
                        <button type="submit" class="btn btn-primary">2FA deaktivieren</button>
                    </form>

                <?php elseif ($setupSecret !== null): ?>
                    <p class="text-muted" style="margin-bottom:.5rem">
                        Scanne den QR-Code mit deiner Authenticator-App:
                    </p>
                    <div class="totp-qr-wrap"><?= $setupQrHtml ?></div>
                    <p class="text-muted" style="margin-bottom:.75rem">
                        Oder gib den Code manuell ein:
                        <span class="totp-secret"><?= htmlspecialchars($setupSecret, ENT_QUOTES, 'UTF-8') ?></span>
                    </p>
                    <form method="post" action="preferences.php">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="totp_confirm">
                        <div class="form-group">
                            <label for="totp_code">6-stelliger Code zur Bestätigung</label>
                            <input type="text" id="totp_code" name="totp_code"
                                   inputmode="numeric" maxlength="6"
                                   autocomplete="one-time-code" required autofocus
                                   class="totp-code-input" style="max-width:200px;">
                        </div>
                        <button type="submit" class="btn btn-primary">Bestätigen</button>
                    </form>

                <?php else: ?>
                    <p class="text-muted" style="margin-bottom:.75rem">
                        2FA ist derzeit nicht aktiviert. Aktiviere es, um dein Konto mit einem
                        zweiten Faktor zu schützen.
                    </p>
                    <form method="post" action="preferences.php">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="totp_start">
                        <button type="submit" class="btn btn-primary">2FA aktivieren</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

    </div>
</main>
```

- [ ] **Step 5: Lint**

Run: `php -l /Users/erikr/Git/Energie/web/preferences.php`
Expected: `No syntax errors detected in …/preferences.php`

- [ ] **Step 6: Commit**

```bash
cd /Users/erikr/Git/Energie && git add web/preferences.php
git commit -m "feat(prefs): add 2FA enrollment card to preferences page"
```

---

## Task 7: End-to-end manual verification

No automated tests exist for the PHP side — this task runs the full flow in a browser and documents the expected behavior. Use a dev account on `jardyx_auth` (e.g. create a throwaway user or use your own).

**URL base:** `http://localhost/energie.test` (Apache alias serves directly from `/Users/erikr/Git/Energie/web`).

- [ ] **Step 1: Log in normally (no 2FA yet)**

Open: `http://localhost/energie.test/login.php`
Do: log in with a user whose `totp_secret` is NULL.
Expected: redirected to `index.php`, banner "Hallo <user>." visible. No TOTP prompt.

- [ ] **Step 2: Navigate to preferences and enable 2FA**

Open: dropdown menu → Einstellungen.
Do: scroll to the new "Zwei-Faktor-Authentifizierung" card → click "2FA aktivieren".
Expected: page reloads with a QR code visible, Base32 secret below it, a 6-digit code input, and a Bestätigen button.

- [ ] **Step 3: Verify the secret is NOT yet persisted**

Run:
```bash
mysql -u root -e "USE jardyx_auth; SELECT totp_secret FROM auth_accounts WHERE username = '<your-test-user>'"
```
Expected: `NULL` (the secret is only in `$_SESSION['totp_setup_secret']` until confirmed).

- [ ] **Step 4: Scan the QR code with an authenticator app**

Use Google Authenticator / 1Password / Authy.
Expected: a new entry appears labelled `<user>@Energie` (issuer: Energie).

- [ ] **Step 5: Enter a WRONG code first**

Do: type `000000` → Bestätigen.
Expected: page reloads with "Code ungültig. Bitte erneut versuchen." and the QR still visible.

- [ ] **Step 6: Enter the correct code**

Do: type the current 6-digit code from the authenticator → Bestätigen.
Expected: redirect to preferences.php, alert "2FA ist jetzt aktiv.", the card now shows "Dein Konto ist mit einem TOTP-Authenticator gesichert." and a "2FA deaktivieren" button.

- [ ] **Step 7: Verify the secret is now persisted**

Run:
```bash
mysql -u root -e "USE jardyx_auth; SELECT totp_secret FROM auth_accounts WHERE username = '<your-test-user>'"
```
Expected: a 32-character Base32 string.

- [ ] **Step 8: Log out and log back in — TOTP gate**

Do: dropdown → Abmelden.
Open: `http://localhost/energie.test/login.php`
Do: enter username + password.
Expected: redirected to `totp_verify.php`, not `index.php`. The page shows the Energie brand header, "2FA-Code eingeben" card with a centered numeric input.

- [ ] **Step 9: Enter a wrong code twice**

Do: type `111111` → Bestätigen → type `222222` → Bestätigen.
Expected: each time the page reloads with "Ungültiger Code." visible. The library tracks attempt count; after 5 failures it destroys the pending state and redirects to login.

- [ ] **Step 10: Enter the correct code**

Do: type the current 6-digit code → Bestätigen.
Expected: redirect to `index.php`, alert "Willkommen zurück.". User is fully logged in (check `$_SESSION['loggedin']` by navigating to any protected page).

- [ ] **Step 11: Disable 2FA**

Do: dropdown → Einstellungen → "2FA deaktivieren" → confirm the JS dialog.
Expected: alert "2FA wurde deaktiviert.", the card reverts to the "2FA aktivieren" state.

- [ ] **Step 12: Verify the column is cleared**

Run:
```bash
mysql -u root -e "USE jardyx_auth; SELECT totp_secret FROM auth_accounts WHERE username = '<your-test-user>'"
```
Expected: `NULL`.

- [ ] **Step 13: Log out and log back in — no TOTP gate**

Do: Abmelden → login again with the same user.
Expected: direct redirect to `index.php`, no TOTP page. The end-to-end flow is reversible.

- [ ] **Step 14: Edge case — TTL expiry during enrollment**

Do: start 2FA enrollment (button → QR appears). Wait 6 minutes. Submit any 6-digit code.
Expected: "Sitzung abgelaufen. Bitte erneut starten." The setup secret was discarded by the handler. Re-start enrollment works.

- [ ] **Step 15: Edge case — TTL expiry during login**

Do: enable 2FA for the test account again (fast — don't wait past 5 min). Log out. Log in with username + password. On the `totp_verify.php` page, wait 6 minutes. Submit a code.
Expected: redirect to `login.php` with alert "Sitzung abgelaufen. Bitte erneut anmelden.".

- [ ] **Step 16: Record the verification in the log**

After all manual steps pass, add a one-line note to the final commit message in Task 8 confirming the full flow worked. If any step fails, STOP and debug before continuing.

---

## Task 8: Deploy to production

**Context:** `~/Git/CLAUDE.md` says "all deploys go through `mcp/deploy.py`". Use the deploy-all coordinator or the Energie-specific target. Production uses DB `5279249db19` on world4you, so the migration must be run there first.

- [ ] **Step 1: Apply the DB migration to production**

Create a modified migration file without the `USE` statement:
```bash
sed '/^USE /d' /Users/erikr/Git/auth/db/06_totp.sql > /tmp/06_totp_prod.sql
cat /tmp/06_totp_prod.sql
```
Expected: an `ALTER TABLE` statement with no `USE` line.

Then apply it to prod — check `mcp/CLAUDE.md` for the exact credentials and host (the plan does not hardcode them). Run:
```bash
mysql -h <world4you-host> -u <prod-user> -p 5279249db19 < /tmp/06_totp_prod.sql
```
Expected: no error (or `Duplicate column name 'totp_secret'` if already migrated).

Verify:
```bash
mysql -h <world4you-host> -u <prod-user> -p 5279249db19 -e "SHOW COLUMNS FROM auth_accounts LIKE 'totp_secret'"
```
Expected: one row showing `totp_secret varchar(64) YES NULL`.

- [ ] **Step 2: Deploy Energie via mcp/deploy.py**

Run:
```bash
cd /Users/erikr/Git && python mcp/deploy.py energie
```
(Substitute the exact target name / flags per `mcp/CLAUDE.md`.)
Expected: rsync output, no errors.

- [ ] **Step 3: Smoke test production**

Open: `https://energie.eriks.cloud/login.php`
Do: log in with a known account. If that account does not have 2FA enabled, login should work unchanged. Then enable 2FA, log out, log back in — same flow as Task 7 but against prod.
Expected: full flow works. If any step fails, revert the deploy and debug.

- [ ] **Step 4: Final commit**

Only after every check above is green:
```bash
cd /Users/erikr/Git/Energie && git log --oneline -n 10
```
Confirm the commit chain (composer, authentication, totp_verify, styles, preferences). If the history is clean and the prod smoke test passed, the plan is complete.

---

## Notes on what this plan deliberately does NOT do

- **No "remember this device" cookie.** TOTP is enforced on every login until the user disables 2FA.
- **No backup codes.** Simplechat doesn't have them either; if the user loses their authenticator, an admin resets via `auth_totp_disable()` in a manual DB query. A future plan can add self-service recovery.
- **No admin-panel 2FA reset UI.** Energie's admin.php may grow this later; it's out of scope here.
- **No changes to `forgotPassword.php` or `executeReset.php`.** Password reset bypasses TOTP by design (the library's invite flow does not check `totp_secret`). Re-enabling 2FA after a password reset is the user's responsibility — document this in the user-facing Hilfe page if/when Energie grows one.
- **No enforcement that 2FA is enabled.** Users choose.
