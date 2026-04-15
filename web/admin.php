<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/admin_log.php';
auth_require();
admin_require();

$selfId = (int) $_SESSION['id'];
$tab    = $_GET['tab'] ?? 'users';
if ($tab !== 'users' && $tab !== 'log') {
    $tab = 'users';
}

// --- Users tab data -------------------------------------------------------
$users    = [];
$total    = 0;
$page     = 1;
$lastPage = 1;
$filter   = '';
$perPage  = 25;
if ($tab === 'users') {
    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $filter  = trim((string) ($_GET['filter'] ?? ''));
    $listing = admin_list_users($con, $page, $perPage, $filter);
    $users   = $listing['users'];
    $total   = $listing['total'];
    $lastPage = max(1, (int) ceil($total / $perPage));
}

// --- Log tab data ---------------------------------------------------------
$logRows       = [];
$logTotal      = 0;
$logPage       = 1;
$logLastPage   = 1;
$logPerPage    = 50;
$logApps       = [];
$logContexts   = [];
$logFilters    = [
    'app'     => '',
    'context' => '',
    'user'    => '',
    'from'    => '',
    'to'      => '',
    'q'       => '',
    'fail'    => '',
];
if ($tab === 'log') {
    $logPage = max(1, (int) ($_GET['log_page'] ?? 1));
    $logFilters['app']     = trim((string) ($_GET['log_app']     ?? ''));
    $logFilters['context'] = trim((string) ($_GET['log_context'] ?? ''));
    $logFilters['user']    = trim((string) ($_GET['log_user']    ?? ''));
    $logFilters['from']    = trim((string) ($_GET['log_from']    ?? ''));
    $logFilters['to']      = trim((string) ($_GET['log_to']      ?? ''));
    $logFilters['q']       = trim((string) ($_GET['log_q']       ?? ''));
    $logFilters['fail']    = !empty($_GET['log_fail']) ? '1' : '';

    // Default to last 7 days if neither from nor to is set.
    $logDefaulted = false;
    if ($logFilters['from'] === '' && $logFilters['to'] === '') {
        $logFilters['from'] = date('Y-m-d', strtotime('-7 days'));
        $logFilters['to']   = date('Y-m-d');
        $logDefaulted = true;
    }

    $logData     = admin_log_list($con, $logPage, $logPerPage, $logFilters);
    $logRows     = $logData['rows'];
    $logTotal    = $logData['total'];
    $logLastPage = max(1, (int) ceil($logTotal / $logPerPage));
    $logApps     = admin_log_distinct_apps($con);
    $logContexts = admin_log_distinct_contexts($con);
}

$csrfToken = csrf_token();

/** Build a query string for tab links preserving nothing. */
function tab_url(string $t): string {
    return 'admin.php?tab=' . urlencode($t);
}

/** Build a query string for log pagination preserving all filters. */
function log_page_url(int $p, array $filters): string {
    $qs = ['tab' => 'log', 'log_page' => $p];
    foreach ($filters as $k => $v) {
        if ($v !== '' && $v !== null) {
            $qs['log_' . $k] = $v;
        }
    }
    return 'admin.php?' . http_build_query($qs);
}

/** Build a query string for user pagination preserving the filter. */
function user_page_url(int $p, string $filter): string {
    $qs = ['tab' => 'users', 'page' => $p];
    if ($filter !== '') {
        $qs['filter'] = $filter;
    }
    return 'admin.php?' . http_build_query($qs);
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
    <?php if ($tab === 'log'): ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/dark.css">
    <link rel="stylesheet" href="<?= $base ?>/styles/flatpickr-overrides.css">
    <?php endif; ?>
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

        <div id="adminAlerts"></div>

        <nav class="admin-tabs">
            <a class="tab-link <?= $tab === 'users' ? 'active' : '' ?>" href="<?= tab_url('users') ?>">Benutzerverwaltung</a>
            <a class="tab-link <?= $tab === 'log'   ? 'active' : '' ?>" href="<?= tab_url('log')   ?>">Log</a>
        </nav>

        <?php if ($tab === 'users'): ?>
            <?php require __DIR__ . '/../inc/_admin_users_tab.php'; ?>
        <?php else: ?>
            <?php require __DIR__ . '/../inc/_admin_log_tab.php'; ?>
        <?php endif; ?>

    </div>
</main>

<?php if ($tab === 'users'): ?>
<?php require __DIR__ . '/../inc/_admin_user_modals.php'; ?>
<?php endif; ?>

<?php require __DIR__ . '/../inc/_footer.php'; ?>

<script nonce="<?= $_cspNonce ?>">
const CSRF = <?= json_encode($csrfToken) ?>;

function showAlert(msg, type) {
    const box = document.getElementById('adminAlerts');
    if (!box) return;
    const div = document.createElement('div');
    div.className = 'alert alert-' + (type || 'info');
    div.textContent = msg;
    box.appendChild(div);
    setTimeout(() => div.remove(), 5000);
}

async function adminPost(type, params) {
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    for (const [k, v] of Object.entries(params)) fd.append(k, v);
    const r = await fetch('api.php?type=' + encodeURIComponent(type), { method: 'POST', body: fd });
    return r.json();
}

function openModal(id)  { document.getElementById(id)?.classList.add('show'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('show'); }

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-modal-open]').forEach(btn =>
        btn.addEventListener('click', () => openModal(btn.dataset.modalOpen))
    );
    document.querySelectorAll('[data-modal-close]').forEach(btn =>
        btn.addEventListener('click', () => {
            const m = btn.closest('.modal');
            if (m) m.classList.remove('show');
        })
    );
    document.querySelectorAll('.modal').forEach(m =>
        m.addEventListener('click', e => { if (e.target === m) m.classList.remove('show'); })
    );

    <?php if ($tab === 'users'): ?>
    const editModal   = document.getElementById('editModal');
    const editForm    = document.getElementById('editForm');
    const createForm  = document.getElementById('createForm');

    document.querySelectorAll('.btn-edit').forEach(btn => {
        btn.addEventListener('click', () => {
            document.getElementById('editId').value          = btn.dataset.id;
            document.getElementById('editUsername').textContent = btn.dataset.username;
            document.getElementById('editEmail').value       = btn.dataset.email;
            document.getElementById('editRights').value      = btn.dataset.rights;
            document.getElementById('editDisabled').checked  = btn.dataset.disabled === '1';
            document.getElementById('editDebug').checked     = btn.dataset.debug === '1';
            document.getElementById('editTotpReset').checked = false;
        });
    });

    editForm?.addEventListener('submit', async e => {
        e.preventDefault();
        const fd = new FormData(e.target);
        fd.delete('csrf_token');
        const res = await adminPost('admin_user_edit', Object.fromEntries(fd));
        if (res.ok) {
            showAlert('Gespeichert.', 'success');
            closeModal('editModal');
            setTimeout(() => location.reload(), 700);
        } else {
            showAlert(res.error || 'Fehler beim Speichern.', 'danger');
        }
    });

    createForm?.addEventListener('submit', async e => {
        e.preventDefault();
        const fd = new FormData(e.target);
        fd.delete('csrf_token');
        const res = await adminPost('admin_user_create', Object.fromEntries(fd));
        if (res.ok) {
            showAlert('Einladung versandt an ' + fd.get('email') + '.', 'success');
            closeModal('createModal');
            e.target.reset();
            setTimeout(() => location.reload(), 700);
        } else {
            showAlert(res.error || 'Unbekannter Fehler.', 'danger');
        }
    });

    document.querySelectorAll('.btn-reset').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!confirm('Einladungs-E-Mail erneut senden?')) return;
            const res = await adminPost('admin_user_reset', { id: btn.dataset.id });
            showAlert(res.ok ? 'E-Mail versandt.' : (res.error || 'Fehler.'), res.ok ? 'success' : 'danger');
        });
    });

    document.querySelectorAll('.btn-delete').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!confirm('Benutzer «' + btn.dataset.username + '» wirklich löschen?')) return;
            const res = await adminPost('admin_user_delete', { id: btn.dataset.id });
            if (res.ok) {
                showAlert('Gelöscht.', 'success');
                setTimeout(() => location.reload(), 700);
            } else {
                showAlert(res.error || 'Löschen fehlgeschlagen.', 'danger');
            }
        });
    });
    <?php endif; ?>

    <?php if ($tab === 'log'): ?>
    if (window.flatpickr) {
        flatpickr('#log_from', { dateFormat: 'Y-m-d' });
        flatpickr('#log_to',   { dateFormat: 'Y-m-d' });
    }
    <?php endif; ?>
});
</script>

<?php if ($tab === 'log'): ?>
<script src="https://cdn.jsdelivr.net/npm/flatpickr" nonce="<?= $_cspNonce ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/de.js" nonce="<?= $_cspNonce ?>"></script>
<?php endif; ?>

</body>
</html>
