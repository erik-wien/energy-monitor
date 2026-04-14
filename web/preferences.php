<?php
require_once __DIR__ . '/../inc/db.php';
auth_require();

$userId  = (int) $_SESSION['id'];
$uname   = htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8');
$theme   = $_SESSION['theme'] ?? 'auto';

// Reload email fresh from DB
$stmt = $con->prepare('SELECT email FROM auth_accounts WHERE id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
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

// ── POST handler ──────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        addAlert('danger', 'Ungültige Anfrage.');
        header('Location: preferences.php'); exit;
    }

    $action = $_POST['action'] ?? '';

    // ── Profile picture ───────────────────────────────────────────────────────
    if ($action === 'upload_avatar') {
        $file = $_FILES['avatar'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $errors['avatar'] = 'Upload fehlgeschlagen (Fehlercode ' . ($file['error'] ?? '?') . ').';
        } else {
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $info    = @getimagesize($file['tmp_name']);
            if (!$info || !in_array($info['mime'], $allowed, true)) {
                $errors['avatar'] = 'Nur Bilder (JPEG, PNG, GIF, WebP) sind erlaubt.';
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $errors['avatar'] = 'Das Bild darf maximal 2 MB groß sein.';
            } else {
                $data = file_get_contents($file['tmp_name']);
                $mime = $info['mime'];
                $size = strlen($data);
                $upd  = $con->prepare(
                    'UPDATE auth_accounts SET img_blob = ?, img_type = ?, img_size = ? WHERE id = ?'
                );
                $upd->bind_param('ssii', $data, $mime, $size, $userId);
                $upd->execute();
                $upd->close();
                appendLog($con, 'prefs', 'Avatar updated (' . $mime . ', ' . $size . ' bytes).', 'web');
                addAlert('success', 'Profilbild aktualisiert.');
                header('Location: preferences.php'); exit;
            }
        }
    }

    // ── Change e-mail ─────────────────────────────────────────────────────────
    if ($action === 'change_email') {
        $newEmail  = trim($_POST['email'] ?? '');
        $emailPass = $_POST['email_password'] ?? '';

        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Ungültige E-Mail-Adresse.';
        } elseif ($emailPass === '') {
            $errors['email'] = 'Bitte Kennwort zur Bestätigung eingeben.';
        } else {
            $stmt = $con->prepare('SELECT password FROM auth_accounts WHERE id = ?');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row || !password_verify($emailPass, $row['password'])) {
                $errors['email'] = 'Das Kennwort ist falsch.';
            } else {
                $chk = $con->prepare('SELECT id FROM auth_accounts WHERE email = ? AND id != ?');
                $chk->bind_param('si', $newEmail, $userId);
                $chk->execute();
                $chk->store_result();
                $taken = $chk->num_rows > 0;
                $chk->close();

                if ($taken) {
                    $errors['email'] = 'Diese E-Mail-Adresse ist bereits vergeben.';
                } else {
                    $code = bin2hex(random_bytes(32));
                    $upd  = $con->prepare(
                        'UPDATE auth_accounts SET pending_email = ?, email_change_code = ? WHERE id = ?'
                    );
                    $upd->bind_param('ssi', $newEmail, $code, $userId);
                    $upd->execute();
                    $upd->close();

                    $confirmUrl = APP_BASE_URL . '/confirm_email.php?code=' . urlencode($code);
                    $htmlBody   = '<p>Hallo ' . $uname . ',</p>'
                        . '<p>Sie haben eine neue E-Mail-Adresse für Ihr Energie-Konto beantragt. '
                        . 'Bitte bestätigen Sie sie mit diesem Link:</p>'
                        . '<p><a href="' . htmlspecialchars($confirmUrl, ENT_QUOTES, 'UTF-8') . '">'
                        . htmlspecialchars($confirmUrl, ENT_QUOTES, 'UTF-8') . '</a></p>'
                        . '<p>Dieser Link ist 24 Stunden gültig.</p>'
                        . '<p>Sollten Sie keine E-Mail-Änderung beantragt haben, ignorieren Sie diese Nachricht.</p>';
                    $textBody   = "Hallo,\n\nBitte bestätigen Sie Ihre neue E-Mail-Adresse:\n$confirmUrl\n\n"
                        . "Dieser Link ist 24 Stunden gültig.\n";

                    try {
                        send_mail($newEmail, $_SESSION['username'] ?? '', 'E-Mail-Adresse bestätigen', $htmlBody, $textBody);
                        appendLog($con, 'prefs', 'Email change requested for ' . ($_SESSION['username'] ?? ''), 'web');
                        addAlert('info', 'Bestätigungslink wurde an die neue E-Mail-Adresse gesendet.');
                    } catch (Throwable $e) {
                        appendLog($con, 'prefs', 'Email send failed: ' . $e->getMessage(), 'web');
                        $errors['email'] = 'Die Bestätigungs-E-Mail konnte nicht gesendet werden. Bitte versuchen Sie es später erneut.';
                    }

                    if (empty($errors['email'])) {
                        header('Location: preferences.php'); exit;
                    }
                }
            }
        }
    }

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
                appendLog($con, 'npw', 'Failed: wrong old password for ' . ($_SESSION['username'] ?? ''), 'web');
                $errors['password'] = 'Das alte Kennwort ist falsch.';
            } else {
                $hash = password_hash($new1, PASSWORD_BCRYPT, ['cost' => 13]);
                $upd  = $con->prepare(
                    'UPDATE auth_accounts SET password = ?, invalidLogins = 0 WHERE id = ?'
                );
                $upd->bind_param('si', $hash, $userId);
                $upd->execute();
                $upd->close();
                appendLog($con, 'npw', 'Success: password changed for ' . ($_SESSION['username'] ?? ''), 'web');
                addAlert('success', 'Kennwort erfolgreich geändert.');
                header('Location: preferences.php'); exit;
            }
        }
    }

    // ── Change theme ──────────────────────────────────────────────────────────
    if ($action === 'change_theme') {
        $t = $_POST['theme'] ?? '';
        if (!in_array($t, ['light', 'dark', 'auto'], true)) {
            $errors['theme'] = 'Ungültiges Design.';
        } else {
            $upd = $con->prepare('UPDATE auth_accounts SET theme = ? WHERE id = ?');
            $upd->bind_param('si', $t, $userId);
            $upd->execute();
            $upd->close();
            $_SESSION['theme'] = $t;
            $theme = $t;
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
    <title>Einstellungen · Energie</title>
    <link rel="stylesheet" href="<?= $base ?>/styles/shared/theme.css">
    <link rel="stylesheet" href="<?= $base ?>/styles/shared/reset.css">
    <link rel="stylesheet" href="<?= $base ?>/styles/shared/layout.css">
    <link rel="stylesheet" href="<?= $base ?>/styles/shared/components.css">
    <link rel="stylesheet" href="<?= $base ?>/styles/energie-theme.css">
    <link rel="stylesheet" href="<?= $base ?>/styles/energie.css">
    <link rel="icon" type="image/x-icon" href="<?= $base ?>/img/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $base ?>/img/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= $base ?>/img/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= $base ?>/img/apple-touch-icon.png">
</head>
<body>
<?php $page_type = 'preferences'; require __DIR__ . '/../inc/_header.php'; ?>
<main>
    <div class="pref-section">

        <?php foreach ($_SESSION['alerts'] ?? [] as [$type, $msg]): ?>
            <div class="alert alert-<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"><?= $msg ?></div>
        <?php endforeach; unset($_SESSION['alerts']); ?>

        <!-- Profilbild -->
        <div class="pref-card">
            <div class="pref-card-hdr">Profilbild</div>
            <div class="pref-card-body">
                <div class="avatar-row">
                    <img src="<?= $base ?>/avatar.php" class="avatar-preview" alt="Profilbild">
                    <span class="text-muted">JPEG, PNG, GIF oder WebP · max. 2 MB</span>
                </div>
                <?php if (!empty($errors['avatar'])): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($errors['avatar'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <form method="post" action="preferences.php" enctype="multipart/form-data">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="upload_avatar">
                    <div class="input-group">
                        <input type="file" class="form-control" name="avatar"
                               accept="image/jpeg,image/png,image/gif,image/webp" required>
                        <button class="btn btn-primary" type="submit">Hochladen</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Design -->
        <div class="pref-card">
            <div class="pref-card-hdr">Design</div>
            <div class="pref-card-body">
                <?php if (!empty($errors['theme'])): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($errors['theme'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <form method="post" action="preferences.php">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="change_theme">
                    <div class="btn-group">
                        <input type="radio" name="theme" id="th_light" value="light" <?= $theme === 'light' ? 'checked' : '' ?>>
                        <label for="th_light">☀ Hell</label>
                        <input type="radio" name="theme" id="th_auto"  value="auto"  <?= $theme === 'auto'  ? 'checked' : '' ?>>
                        <label for="th_auto">⬤ Auto</label>
                        <input type="radio" name="theme" id="th_dark"  value="dark"  <?= $theme === 'dark'  ? 'checked' : '' ?>>
                        <label for="th_dark">🌙 Dunkel</label>
                    </div>
                    <button class="btn btn-primary" type="submit">Speichern</button>
                </form>
            </div>
        </div>

        <!-- E-Mail -->
        <div class="pref-card">
            <div class="pref-card-hdr">E-Mail-Adresse</div>
            <div class="pref-card-body">
                <p class="text-muted" style="margin-bottom:.75rem">
                    Aktuelle Adresse: <strong style="color:var(--color-text)"><?= $currentEmail ?></strong><br>
                    Nach dem Speichern erhalten Sie einen Bestätigungslink an die neue Adresse.
                </p>
                <?php if (!empty($errors['email'])): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($errors['email'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <form method="post" action="preferences.php">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="change_email">
                    <div class="form-group">
                        <label for="newEmail">Neue E-Mail-Adresse</label>
                        <input type="email" id="newEmail" name="email" class="form-control"
                               value="<?= isset($errors['email']) ? htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') : '' ?>"
                               autocomplete="email" required>
                    </div>
                    <div class="form-group">
                        <label for="emailPassword">Kennwort zur Bestätigung</label>
                        <input type="password" id="emailPassword" name="email_password"
                               class="form-control" autocomplete="current-password" required>
                    </div>
                    <button class="btn btn-primary" type="submit">Bestätigungslink senden</button>
                </form>
            </div>
        </div>

        <!-- Kennwort -->
        <div class="pref-card">
            <div class="pref-card-hdr">Kennwort ändern</div>
            <div class="pref-card-body">
                <?php if (!empty($errors['password'])): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($errors['password'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <form method="post" action="preferences.php">
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
<?php echo '<footer class="app-footer"><span>&copy; ' . date('Y') . ' Erik R. Accart-Huemer</span> <a href="https://www.eriks.cloud/#impressum" target="_blank" rel="noopener">Impressum</a> <span>' . APP_NAME . ' ' . APP_VERSION . '.' . APP_BUILD . ' &middot; ' . APP_ENV . '</span></footer>'; ?>
</body>
</html>
