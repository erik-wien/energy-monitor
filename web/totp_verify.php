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
