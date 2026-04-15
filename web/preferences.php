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
    <link rel="icon" type="image/x-icon" href="<?= $base ?>/assets/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $base ?>/assets/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= $base ?>/assets/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= $base ?>/assets/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= $base ?>/assets/web-app-manifest-192x192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="<?= $base ?>/assets/web-app-manifest-512x512.png">
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

    </div>
</main>
<?php require __DIR__ . '/../inc/_footer.php'; ?>
</body>
</html>
