<?php
// Expected vars from including file:
// $base       string  URL prefix (e.g. '/energie.test')
// $page_type  string  'daily' | 'weekly' | 'monthly' | 'yearly' | 'index' | 'preferences'

// Count importable files in scrapes/
$_scrapes_dir  = dirname(__DIR__) . '/scrapes';
$_import_count = count(array_merge(
    glob($_scrapes_dir . '/*.csv')  ?: [],
    glob($_scrapes_dir . '/*.xlsx') ?: []
));

// Nav targets
$_stmt_latest     = $pdo->query("SELECT MAX(day) AS d FROM daily_summary WHERE consumed_kwh > 0");
$_nav_today       = ($_stmt_latest->fetchColumn()) ?: date('Y-m-d');
$_nav_week_year   = (int)date('o');
$_nav_week_num    = (int)date('W');
$_nav_month_year  = (int)date('Y');
$_nav_month_month = (int)date('n');
$_theme           = $_SESSION['theme'] ?? 'auto';
$_username        = htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES);
$_isAdmin         = (($_SESSION['rights'] ?? '') === 'Admin');
?>
<script nonce="<?= $_cspNonce ?>">document.documentElement.dataset.theme = <?= json_encode($_theme) ?>;</script>
<header class="app-header">
    <a class="brand" href="<?= $base ?>/">
        <img src="<?= $base ?>/img/jardyx.svg" class="header-logo" width="28" height="28" alt="">
        <span class="header-appname">Energie</span>
    </a>
    <nav class="header-nav">
        <a href="<?= $base ?>/daily.php?date=<?= $_nav_today ?>"
           <?= $page_type === 'daily'   ? 'class="active"' : '' ?>>Aktuell</a>
        <a href="<?= $base ?>/weekly.php?year=<?= $_nav_week_year ?>&amp;week=<?= $_nav_week_num ?>"
           <?= $page_type === 'weekly'  ? 'class="active"' : '' ?>>Woche</a>
        <a href="<?= $base ?>/monthly.php?year=<?= $_nav_month_year ?>&amp;month=<?= $_nav_month_month ?>"
           <?= $page_type === 'monthly' ? 'class="active"' : '' ?>>Monat</a>
        <a href="<?= $base ?>/yearly.php?year=<?= $_nav_month_year ?>&amp;month=<?= $_nav_month_month ?>"
           <?= $page_type === 'yearly'  ? 'class="active"' : '' ?>>Jahr</a>
    </nav>
    <div class="user-menu">
        <button class="user-btn" type="button">
            <img src="<?= $base ?>/avatar.php" class="avatar" width="26" height="26" alt="">
            <span><?= $_username ?></span>
            <svg class="chevron" width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M2 4l4 4 4-4"/>
            </svg>
            <?php if ($_import_count > 0): ?>
                <span class="notif-dot" title="<?= $_import_count ?> Datei(en) importierbar"></span>
            <?php endif; ?>
        </button>
        <div class="user-dropdown">
            <span class="dropdown-username"><?= $_username ?></span>
            <div class="dropdown-divider"></div>
            <a href="<?= $base ?>/preferences.php">Einstellungen</a>
            <?php if ($_isAdmin): ?>
                <a href="<?= $base ?>/admin.php">Admin</a>
            <?php endif; ?>
            <?php if ($_import_count > 0): ?>
                <div class="dropdown-divider"></div>
                <button class="dropdown-link-btn dropdown-link-btn--import" id="import-trigger">
                    Importieren (<?= $_import_count ?>)
                </button>
            <?php elseif ($_isAdmin): ?>
                <div class="dropdown-divider"></div>
                <label class="dropdown-link-btn" style="cursor:pointer" for="csv-upload-input" id="upload-label">
                    CSV hochladen
                </label>
                <input type="file" id="csv-upload-input" accept=".csv" style="display:none">
            <?php endif; ?>
            <div class="dropdown-divider"></div>
            <div class="theme-row">
                <button class="theme-btn <?= $_theme === 'light' ? 'active' : '' ?>" data-theme="light" title="Hell">☀</button>
                <button class="theme-btn <?= $_theme === 'auto'  ? 'active' : '' ?>" data-theme="auto"  title="Auto">⬤</button>
                <button class="theme-btn <?= $_theme === 'dark'  ? 'active' : '' ?>" data-theme="dark"  title="Dunkel">🌙</button>
            </div>
            <div class="dropdown-divider"></div>
            <form method="post" action="<?= $base ?>/logout.php" style="margin:0">
                <?= csrf_input() ?>
                <button type="submit" class="dropdown-link-btn">Abmelden</button>
            </form>
        </div>
    </div>
</header>
<?php if ($_import_count > 0): ?>
<dialog id="import-dialog">
    <h3>Import-Vorschau</h3>
    <div class="import-counts">
        <span class="label">Datensätze gefunden</span>
        <span class="value" id="imp-total">…</span>
        <span class="label">Bereits importiert</span>
        <span class="value exists" id="imp-existing">…</span>
        <span class="label">Neu</span>
        <span class="value new" id="imp-new">…</span>
    </div>
    <div class="import-dialog-btns">
        <button class="btn" id="imp-cancel"
            style="background:var(--color-surface);border:1px solid var(--color-border);color:var(--color-text)">Abbrechen</button>
        <button class="btn btn-primary" id="imp-confirm">Importieren</button>
    </div>
</dialog>
<?php endif; ?>
<script nonce="<?= $_cspNonce ?>">
(function() {
    const menu      = document.querySelector('.user-menu');
    const apiBase   = <?= json_encode($base) ?>;
    const csrfToken = <?= json_encode(csrf_token()) ?>;
    if (!menu) return;

    // Dropdown toggle
    menu.querySelector('.user-btn').addEventListener('click', e => {
        e.stopPropagation();
        menu.classList.toggle('open');
    });
    document.addEventListener('click', () => menu.classList.remove('open'));

    // Import status toast (shown after page reload)
    const _stored = sessionStorage.getItem('importResult');
    if (_stored) {
        sessionStorage.removeItem('importResult');
        try {
            const _r = JSON.parse(_stored);
            const _el = document.createElement('div');
            _el.className = 'alert ' + (_r.ok ? 'alert-success' : 'alert-danger');
            _el.style.cssText = 'margin:0.75rem 1.5rem;';
            _el.textContent = _r.ok
                ? `Import abgeschlossen: ${_r.rows} Datensätze aus ${_r.count} Datei(en) importiert.`
                : `Import fehlgeschlagen: ${(_r.log || _r.error || 'Unbekannter Fehler').trim().slice(0, 200)}`;
            document.querySelector('.app-header')?.insertAdjacentElement('afterend', _el);
            setTimeout(() => _el.remove(), 8000);
        } catch (_) {}
    }

    // Import trigger — 2-step: preview → dialog → import
    const importBtn    = document.getElementById('import-trigger');
    const importDialog = document.getElementById('import-dialog');
    if (importBtn && importDialog) {
        const impTotal    = document.getElementById('imp-total');
        const impExisting = document.getElementById('imp-existing');
        const impNew      = document.getElementById('imp-new');
        const impCancel   = document.getElementById('imp-cancel');
        const impConfirm  = document.getElementById('imp-confirm');
        const importLabel = 'Importieren (<?= $_import_count ?>)';

        function _runImport() {
            impConfirm.disabled    = true;
            impConfirm.textContent = 'Importiere\u2026';
            fetch(apiBase + '/api.php?type=trigger-import', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'csrf_token=' + encodeURIComponent(csrfToken),
            })
                .then(r => r.json())
                .then(d => { importDialog.close(); sessionStorage.setItem('importResult', JSON.stringify(d)); location.reload(); })
                .catch(() => { importDialog.close(); sessionStorage.setItem('importResult', JSON.stringify({ ok: false, error: 'Netzwerkfehler' })); location.reload(); });
        }

        importBtn.addEventListener('click', e => {
            e.stopPropagation();
            importBtn.textContent = 'Lade Vorschau\u2026';
            importBtn.disabled    = true;
            fetch(apiBase + '/api.php?type=preview-import', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'csrf_token=' + encodeURIComponent(csrfToken),
            })
                .then(r => r.json())
                .then(d => {
                    importBtn.textContent = importLabel;
                    importBtn.disabled    = false;
                    if (!d.ok) {
                        sessionStorage.setItem('importResult', JSON.stringify({ ok: false, error: d.error || 'Vorschau fehlgeschlagen' }));
                        location.reload();
                        return;
                    }
                    impTotal.textContent    = d.total;
                    impExisting.textContent = d.existing;
                    impNew.textContent      = d.new;
                    impConfirm.disabled     = false;
                    impConfirm.textContent  = 'Importieren';
                    importDialog.showModal();
                })
                .catch(() => {
                    importBtn.textContent = importLabel;
                    importBtn.disabled    = false;
                    sessionStorage.setItem('importResult', JSON.stringify({ ok: false, error: 'Netzwerkfehler bei Vorschau' }));
                    location.reload();
                });
        });

        impCancel.addEventListener('click', () => importDialog.close());
        impConfirm.addEventListener('click', _runImport);
        importDialog.addEventListener('click', e => { if (e.target === importDialog) importDialog.close(); });
    }

    // Admin CSV upload
    const csvInput = document.getElementById('csv-upload-input');
    if (csvInput) {
        csvInput.addEventListener('change', () => {
            const file = csvInput.files[0];
            if (!file) return;
            const label = document.getElementById('upload-label');
            if (label) { label.textContent = 'Wird hochgeladen\u2026'; }
            const fd = new FormData();
            fd.append('file', file);
            fd.append('csrf_token', csrfToken);
            fetch(apiBase + '/api.php?type=upload-csv', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if (d.ok) {
                        sessionStorage.setItem('importResult', JSON.stringify({
                            ok: true, rows: 0, count: 0,
                            log: `Datei '${d.filename}' hochgeladen. Klicke auf Importieren.`,
                        }));
                        location.reload();
                    } else {
                        alert(d.error || 'Upload fehlgeschlagen');
                        if (label) { label.textContent = 'CSV hochladen'; }
                        csvInput.value = '';
                    }
                })
                .catch(() => { alert('Netzwerkfehler beim Upload'); csvInput.value = ''; });
        });
    }

    // Theme switcher
    menu.querySelectorAll('.theme-btn').forEach(btn => {
        btn.addEventListener('click', e => {
            e.stopPropagation();
            const theme = btn.dataset.theme;
            document.documentElement.dataset.theme = theme;
            menu.querySelectorAll('.theme-btn').forEach(b => b.classList.toggle('active', b.dataset.theme === theme));
            const fd = new FormData();
            fd.append('action', 'change_theme');
            fd.append('theme', theme);
            fd.append('csrf_token', csrfToken);
            fetch(apiBase + '/preferences.php', { method: 'POST', body: fd }).catch(() => {});
        });
    });
})();
</script>
