<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/layout.php';
auth_require();
admin_require();

$selfId = (int) $_SESSION['id'];

// The users tab is server-rendered on initial load; the log tab is AJAX-only
// (Rule §15.1 — filters and pagination go through api.php?action=admin_log_list).

$perPage  = 25;
$page     = max(1, (int) ($_GET['page'] ?? 1));
$filter   = trim((string) ($_GET['filter'] ?? ''));
$listing  = \Erikr\Chrome\Admin\Users::listExtended($con, $page, $perPage, $filter);
$users    = $listing['users'];
$total    = $listing['total'];

$csrfToken = csrf_token();

$pageUrl = static function (int $p, string $f): string {
    $qs = ['page' => $p];
    if ($f !== '') {
        $qs['filter'] = $f;
    }
    return 'admin.php?' . http_build_query($qs) . '#users';
};
?>
<?php
$_adminHead  = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/dark.css">';
$_adminHead .= '<link rel="stylesheet" href="' . htmlspecialchars($base, ENT_QUOTES, 'UTF-8') . '/css/flatpickr-overrides.css">';
render_page_head('Administration', $_adminHead);
render_header('admin');
?>
<main id="main-content" tabindex="-1">
    <div class="admin-section">

        <div id="adminAlerts"></div>

        <nav class="app-tabs" role="tablist" aria-label="Administration">
            <button type="button" class="app-tab"
                    id="tab-params" role="tab" aria-controls="panel-params"
                    aria-selected="false" data-tab="params">App-Parameter</button>
            <button type="button" class="app-tab active"
                    id="tab-users" role="tab" aria-controls="panel-users"
                    aria-selected="true" data-tab="users">Benutzerverwaltung</button>
            <button type="button" class="app-tab"
                    id="tab-log" role="tab" aria-controls="panel-log"
                    aria-selected="false" data-tab="log">Log</button>
        </nav>

        <section id="panel-params" class="app-tab-panel" hidden role="tabpanel" aria-labelledby="tab-params">
            <div class="app-card">
                <div class="app-card-header app-card-header-split">
                    <h2 class="app-card-heading">Spot-Preise laden</h2>
                </div>
                <div class="app-card-body">
                    <p class="text-muted" style="margin-bottom:1rem">
                        Lädt alle Monate, für die Verbrauchsdaten vorhanden, aber noch keine
                        Spotpreise importiert sind, von der Hofer&nbsp;Grünstrom-API.
                    </p>
                    <form id="epexForm">
                        <button type="submit" class="btn btn-outline-danger" id="epexSubmit">
                            Fehlende Spot-Preise laden
                        </button>
                    </form>
                </div>
            </div>
        </section>

        <section id="panel-users" class="app-tab-panel" role="tabpanel" aria-labelledby="tab-users">
            <?php \Erikr\Chrome\Admin\UsersTab::render([
                'users'   => $users,
                'total'   => $total,
                'page'    => $page,
                'perPage' => $perPage,
                'filter'  => $filter,
                'selfId'  => $selfId,
                'pageUrl' => $pageUrl,
            ]); ?>
        </section>

        <section id="panel-log" class="app-tab-panel" hidden role="tabpanel" aria-labelledby="tab-log">
            <?php \Erikr\Chrome\Admin\LogTab::render(); ?>
        </section>

    </div>
</main>

<?php \Erikr\Chrome\Admin\UserModals::render(['csrfToken' => $csrfToken]); ?>

<script nonce="<?= $_cspNonce ?>">
window.CSRF = <?= json_encode($csrfToken) ?>;
</script>
<script src="<?= $base ?>/css/shared/js/admin.js" nonce="<?= $_cspNonce ?>"></script>
<script type="module" src="<?= $base ?>/css/shared/js/dialog.js?v=<?= APP_VERSION . '.' . APP_BUILD ?>" nonce="<?= $_cspNonce ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr" nonce="<?= $_cspNonce ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/de.js" nonce="<?= $_cspNonce ?>"></script>

<script nonce="<?= $_cspNonce ?>">
// ── App-Parameter tab: EPEX fetch ──────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    const epexForm = document.getElementById('epexForm');
    if (!epexForm) return;
    epexForm.addEventListener('submit', async e => {
        e.preventDefault();
        const btn = document.getElementById('epexSubmit');
        const orig = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Lädt\u2026';
        const fd = new FormData(epexForm);
        const res = await adminPost('fetch-prices', Object.fromEntries(fd));
        btn.disabled = false;
        btn.textContent = orig;
        if (res.ok) {
            showAlert(res.log || 'Spotpreise geladen.', 'success');
        } else {
            showAlert('Fehler: ' + (res.error || 'Unbekannt'), 'danger');
        }
    });
});

// ── Users tab: row actions ──────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    const editForm   = document.getElementById('editForm');
    const createForm = document.getElementById('createForm');

    document.querySelectorAll('.btn-edit').forEach(btn => {
        btn.addEventListener('click', () => {
            document.getElementById('editId').value             = btn.dataset.id;
            document.getElementById('editUsername').textContent = btn.dataset.username;
            document.getElementById('editEmail').value          = btn.dataset.email;
            document.getElementById('editRights').value         = btn.dataset.rights;
            document.getElementById('editDisabled').checked     = btn.dataset.disabled === '1';
        });
    });

    const errorMessages = {
        duplicate_or_invalid: 'Benutzername oder E-Mail bereits vergeben.',
        missing_fields:       'Bitte alle Pflichtfelder ausfüllen.',
        missing_id:           'Ungültige ID.',
        cannot_delete_self:   'Sie können sich nicht selbst löschen.',
        csrf:                 'Sitzung abgelaufen — Seite neu laden.',
        forbidden:            'Keine Berechtigung.',
        server_error:         'Serverfehler — bitte Log prüfen.',
    };
    const errMsg = res => errorMessages[res.error] || res.error || 'Unbekannter Fehler.';

    editForm?.addEventListener('submit', async e => {
        e.preventDefault();
        clearAlerts('editAlerts');
        const fd = new FormData(e.target);
        fd.delete('csrf_token');
        const res = await adminPost('admin_user_edit', Object.fromEntries(fd));
        if (res.ok) {
            showAlert('Gespeichert.', 'success');
            closeModal('editModal');
            setTimeout(() => location.reload(), 700);
        } else {
            showAlert(errMsg(res), 'danger', 'editAlerts');
        }
    });

    createForm?.addEventListener('submit', async e => {
        e.preventDefault();
        clearAlerts('createAlerts');
        const fd = new FormData(e.target);
        fd.delete('csrf_token');
        const res = await adminPost('admin_user_create', Object.fromEntries(fd));
        if (res.ok) {
            showAlert('Einladung versandt an ' + fd.get('email') + '.', 'success');
            closeModal('createModal');
            e.target.reset();
            setTimeout(() => location.reload(), 700);
        } else {
            showAlert(errMsg(res), 'danger', 'createAlerts');
        }
    });

    document.querySelectorAll('.btn-toggle-disabled').forEach(btn => {
        btn.addEventListener('click', async () => {
            const isDisabled  = btn.dataset.disabled === '1';
            const nextLabel   = isDisabled ? 'aktivieren' : 'deaktivieren';
            if (!await window.confirmDialog('Benutzer «' + btn.dataset.username + '» ' + nextLabel + '?', { titel: 'Benutzer ' + nextLabel, okLabel: nextLabel.charAt(0).toUpperCase() + nextLabel.slice(1), gefahr: 'neutral' })) return;
            const res = await adminPost('admin_user_toggle_disabled', {
                id: btn.dataset.id,
                disabled: isDisabled ? '' : '1',
            });
            if (res.ok) {
                showAlert(isDisabled ? 'Aktiviert.' : 'Deaktiviert.', 'success');
                setTimeout(() => location.reload(), 700);
            } else {
                showAlert(res.error || 'Fehler.', 'danger');
            }
        });
    });

    document.querySelectorAll('.btn-reset').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!await window.confirmDialog('Einladungs-/Reset-E-Mail an «' + btn.dataset.username + '» senden?', { titel: 'E-Mail senden', okLabel: 'Senden', gefahr: 'secondary' })) return;
            const res = await adminPost('admin_user_reset', { id: btn.dataset.id });
            showAlert(res.ok ? 'E-Mail versandt.' : (res.error || 'Fehler.'), res.ok ? 'success' : 'danger');
        });
    });

    document.querySelectorAll('.btn-revoke-totp').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!await window.confirmDialog('2FA von «' + btn.dataset.username + '» widerrufen? Der Benutzer muss sich neu registrieren.', { titel: '2FA widerrufen', okLabel: 'Widerrufen', gefahr: 'secondary' })) return;
            const res = await adminPost('admin_user_revoke_totp', { id: btn.dataset.id });
            if (res.ok) {
                showAlert('2FA widerrufen.', 'success');
                setTimeout(() => location.reload(), 700);
            } else {
                showAlert(res.error || 'Fehler.', 'danger');
            }
        });
    });

    document.querySelectorAll('.btn-invalid-reset').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!await window.confirmDialog('Fehlversuche (' + btn.dataset.count + ') für «' + btn.dataset.username + '» zurücksetzen?', { titel: 'Fehlversuche zurücksetzen', okLabel: 'Zurücksetzen', gefahr: 'secondary' })) return;
            const res = await adminPost('admin_user_reset_invalid', { id: btn.dataset.id });
            if (res.ok) {
                showAlert('Fehlversuche zurückgesetzt.', 'success');
                setTimeout(() => location.reload(), 700);
            } else {
                showAlert(res.error || 'Fehler.', 'danger');
            }
        });
    });

    document.querySelectorAll('.btn-delete').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!await window.confirmDialog('Benutzer «' + btn.dataset.username + '» wirklich löschen?', { titel: 'Benutzer löschen', okLabel: 'Löschen', gefahr: 'commit' })) return;
            const res = await adminPost('admin_user_delete', { id: btn.dataset.id });
            if (res.ok) {
                showAlert('Gelöscht.', 'success');
                setTimeout(() => location.reload(), 700);
            } else {
                showAlert(res.error || 'Löschen fehlgeschlagen.', 'danger');
            }
        });
    });
});

// ── Log tab: shared initLogTab (css/shared/js/admin.js) ────────────────────
initLogTab({
    endpoint:  'api.php',
    csrfToken: window.CSRF,
    perPage:   50,
});
</script>

<?php render_footer(); ?>
