<?php
require_once __DIR__ . '/../inc/db.php';
auth_require();
admin_require();

$selfId = (int) $_SESSION['id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        addAlert('danger', 'Ungültige Anfrage.');
        header('Location: admin.php'); exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create_user') {
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email']    ?? '');
        $rights   = $_POST['rights']        ?? 'User';

        if ($username === '' || $email === '') {
            $errors['create'] = 'Benutzername und E-Mail sind erforderlich.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['create'] = 'Ungültige E-Mail-Adresse.';
        } else {
            try {
                admin_create_user($con, $username, $email, $rights, APP_BASE_URL);
                appendLog($con, 'admin', "Created user {$username} ({$email})", 'web');
                addAlert('success', "Benutzer «{$username}» angelegt. Einladungs-E-Mail gesendet.");
                header('Location: admin.php'); exit;
            } catch (\mysqli_sql_exception $e) {
                $errors['create'] = 'Benutzername oder E-Mail bereits vergeben.';
            }
        }
    }

    if ($action === 'edit_user') {
        $targetId   = (int) ($_POST['id']       ?? 0);
        $email      = trim($_POST['email']      ?? '');
        $rights     = $_POST['rights']          ?? 'User';
        $disabled   = (int) !empty($_POST['disabled']);
        $debug      = (int) !empty($_POST['debug']);
        $totpReset  = !empty($_POST['totp_reset']);

        if ($targetId <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['edit'] = 'Ungültige Eingabe.';
        } else {
            admin_edit_user($con, $targetId, $email, $rights, $disabled, $debug, $totpReset);
            addAlert('success', 'Benutzer aktualisiert.');
            header('Location: admin.php'); exit;
        }
    }

    if ($action === 'reset_password') {
        $targetId = (int) ($_POST['id'] ?? 0);
        if ($targetId > 0 && admin_reset_password($con, $targetId, APP_BASE_URL)) {
            addAlert('success', 'Einladungs-E-Mail gesendet.');
        } else {
            addAlert('danger', 'E-Mail konnte nicht gesendet werden.');
        }
        header('Location: admin.php'); exit;
    }

    if ($action === 'delete_user') {
        $targetId = (int) ($_POST['id'] ?? 0);
        if ($targetId === $selfId) {
            addAlert('danger', 'Sie können sich nicht selbst löschen.');
        } elseif ($targetId > 0 && admin_delete_user($con, $targetId, $selfId)) {
            addAlert('success', 'Benutzer gelöscht.');
        } else {
            addAlert('danger', 'Löschen fehlgeschlagen.');
        }
        header('Location: admin.php'); exit;
    }
}

$page     = max(1, (int) ($_GET['page']   ?? 1));
$filter   = trim($_GET['filter']          ?? '');
$perPage  = 25;
$listing  = admin_list_users($con, $page, $perPage, $filter);
$users    = $listing['users'];
$total    = $listing['total'];
$lastPage = max(1, (int) ceil($total / $perPage));

$editId   = (int) ($_GET['edit'] ?? 0);
$editRow  = null;
foreach ($users as $u) {
    if ($u['id'] === $editId) { $editRow = $u; break; }
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

        <?php foreach ($_SESSION['alerts'] ?? [] as [$type, $msg]): ?>
            <div class="alert alert-<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"><?= $msg ?></div>
        <?php endforeach; unset($_SESSION['alerts']); ?>

        <div class="pref-card">
            <div class="pref-card-hdr">Benutzer</div>
            <div class="pref-card-body">
                <form method="get" action="admin.php" class="form-inline" style="margin-bottom:1rem">
                    <div class="form-group">
                        <input type="text" name="filter" class="form-control"
                               placeholder="Benutzername suchen"
                               value="<?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <button type="submit" class="btn btn-primary">Suchen</button>
                    <?php if ($filter !== ''): ?>
                        <a href="admin.php" class="btn">Zurücksetzen</a>
                    <?php endif; ?>
                </form>

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
                            <td><?= htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($u['rights'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?= $u['disabled'] ? '<span class="badge badge-danger">deaktiviert</span>' : '<span class="badge badge-success">aktiv</span>' ?>
                                <?php if ($u['debug']): ?><span class="badge">debug</span><?php endif; ?>
                            </td>
                            <td style="white-space:nowrap">
                                <a class="btn btn-sm" href="admin.php?edit=<?= $u['id'] ?><?= $filter !== '' ? '&amp;filter=' . urlencode($filter) : '' ?>">Bearbeiten</a>
                                <form method="post" action="admin.php" style="display:inline"
                                      onsubmit="return confirm('Einladungs-E-Mail erneut senden?');">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="reset_password">
                                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="btn btn-sm">Passwort-Reset</button>
                                </form>
                                <?php if ($u['id'] !== $selfId): ?>
                                    <form method="post" action="admin.php" style="display:inline"
                                          onsubmit="return confirm('Benutzer «<?= htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8') ?>» wirklich löschen?');">
                                        <?= csrf_input() ?>
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">Löschen</button>
                                    </form>
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
                               href="admin.php?page=<?= $p ?><?= $filter !== '' ? '&amp;filter=' . urlencode($filter) : '' ?>"><?= $p ?></a>
                        <?php endfor; ?>
                    </nav>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($editRow !== null): ?>
        <div class="pref-card">
            <div class="pref-card-hdr">Benutzer bearbeiten: <?= htmlspecialchars($editRow['username'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="pref-card-body">
                <?php if (!empty($errors['edit'])): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($errors['edit'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <form method="post" action="admin.php">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="edit_user">
                    <input type="hidden" name="id" value="<?= $editRow['id'] ?>">
                    <div class="form-group">
                        <label for="edit-email">E-Mail</label>
                        <input type="email" id="edit-email" name="email" class="form-control"
                               value="<?= htmlspecialchars($editRow['email'], ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="edit-rights">Rechte</label>
                        <select id="edit-rights" name="rights" class="form-control">
                            <option value="User"  <?= $editRow['rights'] === 'User'  ? 'selected' : '' ?>>User</option>
                            <option value="Admin" <?= $editRow['rights'] === 'Admin' ? 'selected' : '' ?>>Admin</option>
                        </select>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" id="edit-disabled" name="disabled" value="1" <?= $editRow['disabled'] ? 'checked' : '' ?>>
                        <label for="edit-disabled">Deaktiviert</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" id="edit-debug" name="debug" value="1" <?= $editRow['debug'] ? 'checked' : '' ?>>
                        <label for="edit-debug">Debug-Modus</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" id="edit-totp-reset" name="totp_reset" value="1">
                        <label for="edit-totp-reset">2FA zurücksetzen</label>
                    </div>
                    <button type="submit" class="btn btn-primary">Speichern</button>
                    <a href="admin.php" class="btn">Abbrechen</a>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div class="pref-card">
            <div class="pref-card-hdr">Neuen Benutzer anlegen</div>
            <div class="pref-card-body">
                <?php if (!empty($errors['create'])): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($errors['create'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <p class="text-muted" style="margin-bottom:.75rem">
                    Der neue Benutzer erhält eine Einladungs-E-Mail mit einem Link zum Setzen des Kennworts.
                </p>
                <form method="post" action="admin.php">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="create_user">
                    <div class="form-group">
                        <label for="new-username">Benutzername</label>
                        <input type="text" id="new-username" name="username" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="new-email">E-Mail</label>
                        <input type="email" id="new-email" name="email" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="new-rights">Rechte</label>
                        <select id="new-rights" name="rights" class="form-control">
                            <option value="User">User</option>
                            <option value="Admin">Admin</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Anlegen &amp; einladen</button>
                </form>
            </div>
        </div>

    </div>
</main>
<?php require __DIR__ . '/../inc/_footer.php'; ?>
</body>
</html>
