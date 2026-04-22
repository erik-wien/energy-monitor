<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/layout.php';
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
        $res = \Erikr\Chrome\AvatarUpload::handle($con, $userId, $_FILES['avatar'] ?? null);
        header('Content-Type: application/json; charset=utf-8');
        if ($res['ok']) {
            appendLog($con, 'prefs', 'Avatar updated (' . $res['size'] . ' bytes).');
            echo json_encode(['ok' => true]);
            exit;
        }
        $msg = match ($res['error']) {
            'upload_failed'  => 'Upload fehlgeschlagen.',
            'too_large'      => 'Das Bild darf maximal 5 MB groß sein.',
            'not_image'      => 'Nur Bilder (JPEG, PNG, GIF, WebP) sind erlaubt.',
            'too_small'      => 'Das Bild muss mindestens 64×64 Pixel groß sein.',
            'decode_failed',
            'encode_failed'  => 'Das Bild konnte nicht verarbeitet werden.',
            default          => 'Fehler beim Hochladen.',
        };
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $msg]);
        exit;
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
                    $code = auth_email_confirmation_issue($con, $userId, $newEmail)['token'];

                    $confirmUrl = APP_BASE_URL . '/confirm_email.php?code=' . urlencode($code);
                    if (mail_send_email_change_confirmation($newEmail, $_SESSION['username'] ?? '', $confirmUrl)) {
                        appendLog($con, 'prefs', 'Email change requested for ' . ($_SESSION['username'] ?? ''));
                        addAlert('info', 'Bestätigungslink wurde an die neue E-Mail-Adresse gesendet.');
                    } else {
                        appendLog($con, 'prefs', 'Email send failed for ' . ($_SESSION['username'] ?? ''));
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
            appendLog($con, 'prefs', 'Theme set to ' . $t);
            addAlert('success', 'Design gespeichert.');
            header('Location: preferences.php'); exit;
        }
    }

    // ── Revoke all remember-me tokens ─────────────────────────────────────────
    if ($action === 'revoke_all_devices') {
        if (auth_remember_revoke_all($con)) {
            addAlert('success', 'Alle Sitzungen wurden beendet.');
        } else {
            addAlert('danger', 'Konnte Sitzungen nicht beenden.');
        }
        header('Location: preferences.php'); exit;
    }

}
?>
<?php render_page_head('Einstellungen'); render_header('preferences'); ?>
<main id="main-content" tabindex="-1">
    <div class="pref-section">

        <?php foreach ($_SESSION['alerts'] ?? [] as [$type, $msg]): ?>
            <div class="alert alert-<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"><?= $msg ?></div>
        <?php endforeach; unset($_SESSION['alerts']); ?>

        <?php
        $activeTab = 'profilbild';
        if (!empty($errors['email']))        { $activeTab = 'email'; }
        elseif (!empty($errors['theme']))    { $activeTab = 'theme'; }
        elseif (!empty($errors['avatar']))   { $activeTab = 'profilbild'; }
        ?>

        <nav class="tab-bar" role="tablist" aria-label="Einstellungen">
            <button type="button" class="tab-btn<?= $activeTab === 'profilbild' ? ' active' : '' ?>"
                    id="tab-profilbild" role="tab" aria-controls="panel-profilbild"
                    aria-selected="<?= $activeTab === 'profilbild' ? 'true' : 'false' ?>" data-tab="profilbild">Profilbild</button>
            <button type="button" class="tab-btn<?= $activeTab === 'email' ? ' active' : '' ?>"
                    id="tab-email" role="tab" aria-controls="panel-email"
                    aria-selected="<?= $activeTab === 'email' ? 'true' : 'false' ?>" data-tab="email">E-Mail</button>
            <button type="button" class="tab-btn<?= $activeTab === 'theme' ? ' active' : '' ?>"
                    id="tab-theme" role="tab" aria-controls="panel-theme"
                    aria-selected="<?= $activeTab === 'theme' ? 'true' : 'false' ?>" data-tab="theme">Design</button>
        </nav>

        <section id="panel-profilbild" class="tab-panel<?= $activeTab !== 'profilbild' ? ' hidden' : '' ?>"
                 role="tabpanel" aria-labelledby="tab-profilbild"<?= $activeTab !== 'profilbild' ? ' hidden' : '' ?>>
            <link rel="stylesheet" href="<?= $base ?>/css/shared/js/vendor/cropperjs/cropper.min.css">
            <div class="pref-card">
                <div class="pref-card-hdr">Profilbild</div>
                <div class="pref-card-body">
                    <div class="avatar-row">
                        <img src="<?= $base ?>/avatar.php" class="avatar-preview" alt="Profilbild">
                        <span class="text-muted">JPEG, PNG, GIF oder WebP &middot; max.&nbsp;5&thinsp;MB. Nach der Auswahl &ouml;ffnet sich der Zuschnitt.</span>
                    </div>
                    <input type="file" class="form-control" id="avatarFile"
                           accept="image/jpeg,image/png,image/gif,image/webp">
                </div>
            </div>

            <div class="modal" id="avatarCropModal" aria-hidden="true" role="dialog"
                 style="display:none;position:fixed;inset:0;z-index:1050;background:rgba(0,0,0,.6);
                        align-items:center;justify-content:center;padding:1rem">
                <div class="modal-dialog" style="max-width:560px;width:100%;background:var(--color-bg);
                     border:1px solid var(--color-border);border-radius:var(--radius);
                     box-shadow:var(--shadow-sm);display:flex;flex-direction:column;max-height:90vh">
                    <div class="modal-header" style="padding:.75rem 1rem;border-bottom:1px solid var(--color-border)">
                        <strong>Profilbild zuschneiden</strong>
                    </div>
                    <div class="modal-body" style="padding:1rem;overflow:auto;min-height:0">
                        <div style="max-height:60vh">
                            <img id="avatarCropImage" alt="" style="display:block;max-width:100%">
                        </div>
                    </div>
                    <div class="modal-footer" style="padding:.75rem 1rem;border-top:1px solid var(--color-border);display:flex;gap:.5rem;justify-content:flex-end">
                        <button type="button" class="btn" id="avatarCropCancel">Abbrechen</button>
                        <button type="button" class="btn btn-outline-success" id="avatarCropConfirm">Speichern</button>
                    </div>
                </div>
            </div>
            <script nonce="<?= $_cspNonce ?>" src="<?= $base ?>/css/shared/js/vendor/cropperjs/cropper.min.js"></script>
            <script nonce="<?= $_cspNonce ?>" src="<?= $base ?>/css/shared/js/avatar-cropper.js"></script>
            <script nonce="<?= $_cspNonce ?>">
            (function () {
                const modal = document.getElementById('avatarCropModal');
                new MutationObserver(function () {
                    modal.style.display = modal.classList.contains('show') ? 'flex' : 'none';
                }).observe(modal, { attributes: true, attributeFilter: ['class'] });
                initAvatarCropper({
                    fileInputId: 'avatarFile',
                    modalId:     'avatarCropModal',
                    imageId:     'avatarCropImage',
                    confirmId:   'avatarCropConfirm',
                    cancelId:    'avatarCropCancel',
                    formAction:  'preferences.php',
                    csrfToken:   <?= json_encode(csrf_token()) ?>,
                });
            })();
            </script>
        </section>

        <section id="panel-email" class="tab-panel<?= $activeTab !== 'email' ? ' hidden' : '' ?>"
                 role="tabpanel" aria-labelledby="tab-email"<?= $activeTab !== 'email' ? ' hidden' : '' ?>>
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
                        <button class="btn btn-outline-success" type="submit">Bestätigungslink senden</button>
                    </form>
                </div>
            </div>
        </section>

        <section id="panel-theme" class="tab-panel<?= $activeTab !== 'theme' ? ' hidden' : '' ?>"
                 role="tabpanel" aria-labelledby="tab-theme"<?= $activeTab !== 'theme' ? ' hidden' : '' ?>>
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
                        <button class="btn btn-outline-success" type="submit">Speichern</button>
                    </form>
                </div>
            </div>
        </section>

    </div>
</main>
<script src="<?= $base ?>/css/shared/js/admin.js" nonce="<?= $_cspNonce ?>"></script>
<?php render_footer(); ?>
