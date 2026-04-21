<?php
require_once __DIR__ . '/../inc/db.php';
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/dark.css">
    <link rel="stylesheet" href="<?= $base ?>/styles/flatpickr-overrides.css">
    <meta name="theme-color" content="<?= htmlspecialchars(APP_COLOR, ENT_QUOTES) ?>">
    <link rel="icon" type="image/svg+xml" href="<?= $base ?>/jardyx-favicon.svg">
    <link rel="icon" type="image/x-icon" href="<?= $base ?>/assets/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $base ?>/assets/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= $base ?>/assets/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= $base ?>/assets/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= $base ?>/assets/web-app-manifest-192x192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="<?= $base ?>/assets/web-app-manifest-512x512.png">
</head>
<body>
<?php $page_type = 'admin'; require __DIR__ . '/../inc/_header.php'; ?>
<main id="main-content">
    <div class="admin-section">

        <div id="adminAlerts"></div>

        <nav class="tab-bar" role="tablist" aria-label="Administration">
            <button type="button" class="tab-btn"
                    id="tab-params" role="tab" aria-controls="panel-params"
                    aria-selected="false" data-tab="params">App-Parameter</button>
            <button type="button" class="tab-btn active"
                    id="tab-users" role="tab" aria-controls="panel-users"
                    aria-selected="true" data-tab="users">Benutzerverwaltung</button>
            <button type="button" class="tab-btn"
                    id="tab-log" role="tab" aria-controls="panel-log"
                    aria-selected="false" data-tab="log">Log</button>
        </nav>

        <section id="panel-params" class="tab-panel hidden" role="tabpanel" aria-labelledby="tab-params">
            <div class="card">
                <div class="card-header card-header-split">
                    <h2 class="card-title">Spot-Preise laden</h2>
                </div>
                <div class="card-body">
                    <p class="text-muted" style="margin-bottom:1rem">
                        Lädt alle Monate, für die Verbrauchsdaten vorhanden, aber noch keine
                        Spotpreise importiert sind, von der Hofer&nbsp;Grünstrom-API.
                    </p>
                    <form id="epexForm">
                        <button type="submit" class="btn btn-outline-success" id="epexSubmit">
                            Fehlende Spot-Preise laden
                        </button>
                    </form>
                </div>
            </div>
        </section>

        <section id="panel-users" class="tab-panel" role="tabpanel" aria-labelledby="tab-users">
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

        <section id="panel-log" class="tab-panel hidden" role="tabpanel" aria-labelledby="tab-log">
            <?php \Erikr\Chrome\Admin\LogTab::render(); ?>
        </section>

    </div>
</main>

<?php \Erikr\Chrome\Admin\UserModals::render(['csrfToken' => $csrfToken]); ?>

<?php require __DIR__ . '/../inc/_footer.php'; ?>

<script nonce="<?= $_cspNonce ?>">
window.CSRF = <?= json_encode($csrfToken) ?>;
</script>
<script src="<?= $base ?>/styles/shared/js/admin.js" nonce="<?= $_cspNonce ?>"></script>
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
            if (!confirm('Benutzer «' + btn.dataset.username + '» ' + nextLabel + '?')) return;
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
            if (!confirm('Einladungs-/Reset-E-Mail an «' + btn.dataset.username + '» senden?')) return;
            const res = await adminPost('admin_user_reset', { id: btn.dataset.id });
            showAlert(res.ok ? 'E-Mail versandt.' : (res.error || 'Fehler.'), res.ok ? 'success' : 'danger');
        });
    });

    document.querySelectorAll('.btn-revoke-totp').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!confirm('2FA von «' + btn.dataset.username + '» widerrufen? Der Benutzer muss sich neu registrieren.')) return;
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
            if (!confirm('Fehlversuche (' + btn.dataset.count + ') für «' + btn.dataset.username + '» zurücksetzen?')) return;
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
});

// ── Log tab: AJAX load, filter, paginate ───────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    const form      = document.getElementById('logFilterForm');
    const tbody     = document.getElementById('logTbody');
    const paginate  = document.getElementById('logPagination');
    const totalEl   = document.getElementById('logTotal');
    const appSel    = document.getElementById('log_app');
    const ctxSel    = document.getElementById('log_context');
    const fromInput = document.getElementById('log_from');
    const toInput   = document.getElementById('log_to');
    const resetBtn  = document.getElementById('logReset');

    let filtersInitialised = false;
    let loaded             = false;

    const today   = new Date();
    const weekAgo = new Date(today.getTime() - 7 * 24 * 60 * 60 * 1000);
    const ymd = d => d.toISOString().slice(0, 10);

    fromInput.value = ymd(weekAgo);
    toInput.value   = ymd(today);

    if (window.flatpickr) {
        flatpickr(fromInput, { dateFormat: 'Y-m-d' });
        flatpickr(toInput,   { dateFormat: 'Y-m-d' });
    }

    function addOption(sel, value) {
        const opt = document.createElement('option');
        opt.value = value;
        opt.textContent = value;
        sel.appendChild(opt);
    }

    function populateFilters(apps, contexts) {
        if (filtersInitialised) return;
        filtersInitialised = true;
        (apps     || []).forEach(a => addOption(appSel, a));
        (contexts || []).forEach(c => addOption(ctxSel, c));
    }

    function currentFilters() {
        return {
            app:     appSel.value,
            context: ctxSel.value,
            user:    document.getElementById('log_user').value.trim(),
            from:    fromInput.value.trim(),
            to:      toInput.value.trim(),
            q:       document.getElementById('log_q').value.trim(),
            fail:    document.getElementById('log_fail').checked ? '1' : '',
        };
    }

    function setPlaceholderRow(text) {
        tbody.replaceChildren();
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = 6;
        td.className = 'text-muted';
        td.textContent = text;
        tr.appendChild(td);
        tbody.appendChild(tr);
    }

    function renderRows(rows) {
        tbody.replaceChildren();
        if (!rows.length) {
            setPlaceholderRow('Keine Einträge gefunden.');
            return;
        }
        for (const r of rows) {
            const tr = document.createElement('tr');

            const tdTime = document.createElement('td');
            tdTime.className = 'log-time';
            tdTime.textContent = r.logTime ?? '';
            tr.appendChild(tdTime);

            const tdOrigin = document.createElement('td');
            tdOrigin.textContent = r.origin ?? '';
            tr.appendChild(tdOrigin);

            const tdCtx = document.createElement('td');
            tdCtx.textContent = r.context ?? '';
            tr.appendChild(tdCtx);

            const tdUser = document.createElement('td');
            if (r.username !== null && r.username !== undefined) {
                tdUser.textContent = r.username;
            } else {
                const sp = document.createElement('span');
                sp.className = 'text-muted';
                sp.textContent = '—';
                tdUser.appendChild(sp);
            }
            tr.appendChild(tdUser);

            const tdIp = document.createElement('td');
            if (r.ip !== null && r.ip !== undefined) {
                tdIp.textContent = r.ip;
            } else {
                const sp = document.createElement('span');
                sp.className = 'text-muted';
                sp.textContent = '—';
                tdIp.appendChild(sp);
            }
            tr.appendChild(tdIp);

            const tdAct = document.createElement('td');
            tdAct.className = 'log-activity';
            tdAct.textContent = r.activity ?? '';
            tr.appendChild(tdAct);

            tbody.appendChild(tr);
        }
    }

    function renderPagination(page, total, perPage, onClick) {
        paginate.replaceChildren();
        const lastPage = Math.max(1, Math.ceil(total / perPage));
        if (lastPage <= 1) return;
        for (let p = 1; p <= lastPage; p++) {
            const a = document.createElement('a');
            a.className = 'page-link' + (p === page ? ' active' : '');
            a.href = '#log';
            a.textContent = String(p);
            a.addEventListener('click', e => { e.preventDefault(); onClick(p); });
            paginate.appendChild(a);
        }
    }

    async function loadPage(page) {
        setPlaceholderRow('Lade…');
        const res = await adminPost('admin_log_list', { page, ...currentFilters() });
        if (!res.ok) {
            setPlaceholderRow('Fehler beim Laden.');
            showAlert(res.error || 'Log konnte nicht geladen werden.', 'danger');
            return;
        }
        populateFilters(res.apps, res.contexts);
        totalEl.textContent = String(res.total);
        renderRows(res.rows || []);
        renderPagination(res.page, res.total, res.per_page, loadPage);
    }

    form.addEventListener('submit', e => { e.preventDefault(); loadPage(1); });

    resetBtn.addEventListener('click', e => {
        e.preventDefault();
        appSel.value = '';
        ctxSel.value = '';
        document.getElementById('log_user').value   = '';
        document.getElementById('log_q').value      = '';
        document.getElementById('log_fail').checked = false;
        fromInput.value = ymd(weekAgo);
        toInput.value   = ymd(today);
        loadPage(1);
    });

    function maybeLoad() {
        if (loaded) return;
        if (location.hash === '#log') {
            loaded = true;
            loadPage(1);
        }
    }
    document.querySelectorAll('.tab-btn[data-tab="log"]').forEach(btn =>
        btn.addEventListener('click', () => { if (!loaded) { loaded = true; loadPage(1); } })
    );
    window.addEventListener('hashchange', maybeLoad);
    maybeLoad();
});
</script>

</body>
</html>
