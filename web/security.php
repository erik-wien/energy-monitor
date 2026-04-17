<?php
require_once __DIR__ . '/../inc/db.php';
auth_require();

$userId = (int) $_SESSION['id'];
$uname  = htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8');

// ── Current 2FA state ─────────────────────────────────────────────────────
$stmt = $con->prepare('SELECT totp_secret FROM auth_accounts WHERE id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
$has2fa = ($stmt->get_result()->fetch_assoc()['totp_secret'] ?? null) !== null;
$stmt->close();

$errors = [];

// ── POST handler ──────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        addAlert('danger', 'Ungültige Anfrage.');
        header('Location: security.php'); exit;
    }

    $action = $_POST['action'] ?? '';

    // ── Change password ───────────────────────────────────────────────────────
    if ($action === 'change_password') {
        $old  = $_POST['oldPassword']  ?? '';
        $new1 = $_POST['newPassword1'] ?? '';
        $new2 = $_POST['newPassword2'] ?? '';

        if ($old === '' || $new1 === '' || $new2 === '') {
            $errors['password'] = 'Bitte alle Felder ausfüllen.';
        } elseif (strlen($new1) < 8) {
            $errors['password'] = 'Das neue Kennwort muss mindestens 8 Zeichen lang sein.';
        } elseif ($new1 !== $new2) {
            $errors['password'] = 'Die neuen Kennwörter stimmen nicht überein.';
        } else {
            $stmt = $con->prepare('SELECT password FROM auth_accounts WHERE id = ?');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row || !password_verify($old, $row['password'])) {
                $upd = $con->prepare(
                    'UPDATE auth_accounts SET invalidLogins = invalidLogins + 1 WHERE id = ?'
                );
                $upd->bind_param('i', $userId);
                $upd->execute();
                $upd->close();
                appendLog($con, 'npw', 'Failed: wrong old password for ' . ($_SESSION['username'] ?? ''));
                $errors['password'] = 'Das alte Kennwort ist falsch.';
            } else {
                auth_change_password($con, $userId, $new1);
                addAlert('success', 'Kennwort erfolgreich geändert.');
                header('Location: security.php'); exit;
            }
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
            header('Location: security.php'); exit;
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
                appendLog($con, 'auth', ($_SESSION['username'] ?? '') . ' enabled 2FA.');
                addAlert('success', '2FA ist jetzt aktiv.');
                header('Location: security.php'); exit;
            }
            $errors['totp'] = 'Code ungültig. Bitte erneut versuchen.';
        }
    }

    // ── Disable 2FA ───────────────────────────────────────────────────────────
    if ($action === 'totp_disable') {
        auth_totp_disable($con, $userId);
        unset($_SESSION['totp_setup_secret']);
        appendLog($con, 'auth', ($_SESSION['username'] ?? '') . ' disabled 2FA.');
        addAlert('success', '2FA wurde deaktiviert.');
        header('Location: security.php'); exit;
    }
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
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sicherheit · Energie</title>
    <link rel="stylesheet" href="<?= $base ?>/styles/shared/theme.css">
    <link rel="stylesheet" href="<?= $base ?>/styles/shared/reset.css">
    <link rel="stylesheet" href="<?= $base ?>/styles/shared/layout.css">
    <link rel="stylesheet" href="<?= $base ?>/styles/shared/components.css">
    <link rel="stylesheet" href="<?= $base ?>/styles/energie-theme.css">
    <link rel="stylesheet" href="<?= $base ?>/styles/energie.css">
    <link rel="icon" type="image/x-icon" href="<?= $base ?>/assets/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $base ?>/assets/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= $base ?>/assets/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= $base ?>/assets/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= $base ?>/assets/web-app-manifest-192x192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="<?= $base ?>/assets/web-app-manifest-512x512.png">
</head>
<body>
<?php $page_type = 'security'; require __DIR__ . '/../inc/_header.php'; ?>
<main>
    <div class="pref-section">

        <?php foreach ($_SESSION['alerts'] ?? [] as [$type, $msg]): ?>
            <div class="alert alert-<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"><?= $msg ?></div>
        <?php endforeach; unset($_SESSION['alerts']); ?>

        <!-- Kennwort -->
        <div class="pref-card">
            <div class="pref-card-hdr">Kennwort ändern</div>
            <div class="pref-card-body">
                <?php if (!empty($errors['password'])): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($errors['password'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <form method="post" action="security.php">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="change_password">
                    <div class="form-group">
                        <label for="oldPassword">Altes Kennwort</label>
                        <input type="password" id="oldPassword" name="oldPassword"
                               class="form-control" autocomplete="current-password" required>
                    </div>
                    <div class="form-group">
                        <label for="newPassword1">Neues Kennwort</label>
                        <input type="password" id="newPassword1" name="newPassword1"
                               class="form-control" autocomplete="new-password" minlength="8" required>
                    </div>
                    <div class="form-group">
                        <label for="newPassword2">Neues Kennwort bestätigen</label>
                        <input type="password" id="newPassword2" name="newPassword2"
                               class="form-control" autocomplete="new-password" minlength="8" required>
                    </div>
                    <button class="btn btn-outline-success" type="submit">Speichern</button>
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
                    <form method="post" action="security.php"
                          onsubmit="return confirm('2FA wirklich deaktivieren?');">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="totp_disable">
                        <button type="submit" class="btn">2FA deaktivieren</button>
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
                    <form method="post" action="security.php">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="totp_confirm">
                        <div class="form-group">
                            <label for="totp_code">6-stelliger Code zur Bestätigung</label>
                            <input type="text" id="totp_code" name="totp_code"
                                   inputmode="numeric" maxlength="6"
                                   autocomplete="one-time-code" required autofocus
                                   class="totp-code-input" style="max-width:200px;">
                        </div>
                        <button type="submit" class="btn btn-outline-success">Bestätigen</button>
                    </form>

                <?php else: ?>
                    <p class="text-muted" style="margin-bottom:.75rem">
                        2FA ist derzeit nicht aktiviert. Aktiviere es, um dein Konto mit einem
                        zweiten Faktor zu schützen.
                    </p>
                    <form method="post" action="security.php">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="totp_start">
                        <button type="submit" class="btn btn-outline-success">2FA aktivieren</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

    </div>
</main>
<?php require __DIR__ . '/../inc/_footer.php'; ?>
</body>
</html>
