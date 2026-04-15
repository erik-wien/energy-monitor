<?php
require_once __DIR__ . '/../inc/initialize.php';

$token = trim($_GET['token'] ?? '');
$error = '';

$resetRow = null;
if ($token !== '' && preg_match('/^[0-9a-f]{64}$/', $token)) {
    $stmt = $con->prepare(
        'SELECT pr.id, pr.user_id, a.username
           FROM password_resets pr
           JOIN auth_accounts a ON a.id = pr.user_id
          WHERE pr.token = ? AND pr.used = 0 AND pr.expires_at > UTC_TIMESTAMP()'
    );
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $resetRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($resetRow === null) {
    $error = 'Dieser Link ist ungültig oder abgelaufen.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $resetRow !== null) {
    if (!csrf_verify()) {
        $error = 'Ungültige Anfrage.';
    } else {
        $pw1 = $_POST['password1'] ?? '';
        $pw2 = $_POST['password2'] ?? '';

        if (strlen($pw1) < 8) {
            $error = 'Das Kennwort muss mindestens 8 Zeichen lang sein.';
        } elseif ($pw1 !== $pw2) {
            $error = 'Die Kennwörter stimmen nicht überein.';
        } else {
            $hash = password_hash($pw1, PASSWORD_BCRYPT, ['cost' => 13]);

            $upd = $con->prepare(
                'UPDATE auth_accounts SET password = ?, invalidLogins = 0 WHERE id = ?'
            );
            $upd->bind_param('si', $hash, $resetRow['user_id']);
            $upd->execute();
            $upd->close();

            $mark = $con->prepare('UPDATE password_resets SET used = 1 WHERE id = ?');
            $mark->bind_param('i', $resetRow['id']);
            $mark->execute();
            $mark->close();

            appendLog($con, 'pwd_reset', 'Password reset: ' . $resetRow['username'], 'web');
            addAlert('success', 'Kennwort wurde geändert. Sie können sich jetzt anmelden.');
            header('Location: login.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Energie · Neues Kennwort</title>
    <?php $_b = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'); ?>
    <link rel="stylesheet" href="<?= $_b ?>/styles/shared/theme.css">
    <link rel="stylesheet" href="<?= $_b ?>/styles/shared/reset.css">
    <link rel="stylesheet" href="<?= $_b ?>/styles/shared/layout.css">
    <link rel="stylesheet" href="<?= $_b ?>/styles/shared/components.css">
    <link rel="stylesheet" href="<?= $_b ?>/styles/energie-theme.css">
    <link rel="stylesheet" href="<?= $_b ?>/styles/energie.css">
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
        <h2>Neues Kennwort setzen</h2>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php if ($resetRow === null): ?>
                <div class="login-links"><a href="forgotPassword.php">Neuen Link anfordern</a></div>
            <?php endif; ?>
        <?php else: ?>
            <form method="post" action="executeReset.php?token=<?= urlencode($token) ?>">
                <?= csrf_input() ?>
                <div class="form-group">
                    <label for="password1">Neues Kennwort</label>
                    <input type="password" id="password1" name="password1"
                           class="form-control" autocomplete="new-password"
                           minlength="8" required autofocus>
                </div>
                <div class="form-group">
                    <label for="password2">Kennwort wiederholen</label>
                    <input type="password" id="password2" name="password2"
                           class="form-control" autocomplete="new-password"
                           minlength="8" required>
                </div>
                <button type="submit" class="btn-login">Kennwort ändern</button>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
