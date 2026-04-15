<?php
require_once __DIR__ . '/../inc/initialize.php';

$error   = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Ungültige Anfrage.';
    } elseif (trim($_POST['email'] ?? '') === '') {
        $error = 'Bitte E-Mail-Adresse eingeben.';
    } else {
        $ip  = getUserIpAddr();
        $key = 'reset:' . $ip;

        if (rate_limit_check($key, 3, 900)) {
            $error = 'Zu viele Versuche. Bitte warten Sie 15 Minuten.';
        } else {
            rate_limit_record($key);

            $email = trim($_POST['email']);
            $stmt  = $con->prepare(
                "SELECT id, username FROM auth_accounts WHERE email = ? AND disabled = '0'"
            );
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                $del = $con->prepare('DELETE FROM password_resets WHERE user_id = ?');
                $del->bind_param('i', $row['id']);
                $del->execute();
                $del->close();

                $token     = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', time() + 3600);

                $ins = $con->prepare(
                    'INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)'
                );
                $ins->bind_param('iss', $row['id'], $token, $expiresAt);
                $ins->execute();
                $ins->close();

                $resetUrl = APP_BASE_URL . '/executeReset.php?token=' . urlencode($token);
                $uname    = htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8');
                $htmlBody = "<p>Hallo {$uname},</p>"
                    . '<p>Wir haben eine Anfrage für ein neues Kennwort für Ihr Energie-Konto erhalten.</p>'
                    . '<p><a href="' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '">Kennwort zurücksetzen</a></p>'
                    . '<p>Dieser Link ist eine Stunde gültig. Wenn Sie keine Zurücksetzung beantragt haben, können Sie diese E-Mail ignorieren.</p>';
                $textBody = "Hallo {$row['username']},\n\n"
                    . "Kennwort zurücksetzen: {$resetUrl}\n\n"
                    . "Dieser Link ist eine Stunde gültig.\n";

                try {
                    send_mail($email, $row['username'], 'Kennwort zurücksetzen – Energie', $htmlBody, $textBody);
                    appendLog($con, 'pwd_reset', 'Reset mail sent: ' . $row['username'], 'web');
                } catch (Throwable $e) {
                    appendLog($con, 'pwd_reset', 'Reset mail failed: ' . $e->getMessage(), 'web');
                }
            }

            // Always show the same message to avoid user enumeration.
            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Energie · Kennwort vergessen</title>
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
        <h2>Kennwort vergessen</h2>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                Sofern die angegebene E-Mail-Adresse registriert ist, haben Sie einen Link zum Zurücksetzen erhalten.
            </div>
            <div class="login-links"><a href="login.php">Zurück zur Anmeldung</a></div>
        <?php else: ?>
            <form method="post" action="forgotPassword.php">
                <?= csrf_input() ?>
                <div class="form-group">
                    <label for="email">E-Mail-Adresse</label>
                    <input type="email" id="email" name="email" class="form-control"
                           autocomplete="email" required autofocus
                           value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <button type="submit" class="btn-login">Link anfordern</button>
            </form>
            <div class="login-links"><a href="login.php">Abbrechen</a></div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
