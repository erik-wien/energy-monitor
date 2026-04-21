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

$pendingUser = $_SESSION['auth_totp_pending']['user_data']['username'] ?? '(none)';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        appendLog($con, 'auth_fail', 'CSRF failed on TOTP verify (user="' . $pendingUser . '")');
        addAlert('danger', 'Ungültige Anfrage.');
        header('Location: totp_verify.php'); exit;
    }

    $code   = trim($_POST['totp_code'] ?? '');
    $result = auth_totp_complete($con, $code);

    if ($result['ok']) {
        addAlert('info', 'Willkommen zurück.');
        header('Location: ./'); exit;
    }

    appendLog($con, 'auth_fail', 'TOTP failed (user="' . $pendingUser . '"): ' . $result['error']);

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
        if ($pending !== null) {
            appendLog($con, 'auth_fail', 'TOTP pending expired (user="' . $pendingUser . '")');
        }
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
    <meta name="theme-color" content="<?= htmlspecialchars(APP_COLOR, ENT_QUOTES) ?>">
    <link rel="icon" type="image/svg+xml" href="<?= $_b ?>/jardyx-favicon.svg">
    <link rel="icon" type="image/x-icon" href="<?= $_b ?>/assets/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $_b ?>/assets/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= $_b ?>/assets/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= $_b ?>/assets/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= $_b ?>/assets/web-app-manifest-192x192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="<?= $_b ?>/assets/web-app-manifest-512x512.png">
</head>
<body>
<header class="app-header">
    <div class="header-left">
        <span class="brand">
            <img src="<?= $_b ?>/assets/jardyx.svg" class="header-logo" width="28" height="28" alt="">
            <span class="header-appname">Energie</span>
        </span>
    </div>
    <div class="header-right"></div>
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
<?php $base = $_b; require __DIR__ . '/../inc/_footer.php'; ?>
</body>
</html>
