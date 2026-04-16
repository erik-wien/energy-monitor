<?php
// _header.php — thin adapter for \Erikr\Chrome\Header.
//
// Expected vars from including page:
//   $base       URL prefix (e.g. '/energie.test'), defined by inc/db.php
//   $page_type  'daily' | 'weekly' | 'monthly' | 'yearly' | 'index'
//               | 'preferences' | 'security' | 'help' | 'admin'
//   $pdo        PDO handle to the data DB (used for nav-target lookup)
//   $_cspNonce  set by auth_bootstrap()

// ── App-specific nav targets ──────────────────────────────────────────────────

$_stmt_latest     = $pdo->query("SELECT MAX(day) AS d FROM daily_summary WHERE consumed_kwh > 0");
$_nav_today       = ($_stmt_latest->fetchColumn()) ?: date('Y-m-d');
$_nav_week_year   = (int) date('o');
$_nav_week_num    = (int) date('W');
$_nav_month_year  = (int) date('Y');
$_nav_month_month = (int) date('n');

$_appMenu = [
    ['type' => 'daily',   'label' => 'Aktuell',
     'href' => 'daily.php?date=' . $_nav_today],
    ['type' => 'weekly',  'label' => 'Woche',
     'href' => 'weekly.php?year=' . $_nav_week_year . '&week=' . $_nav_week_num],
    ['type' => 'monthly', 'label' => 'Monat',
     'href' => 'monthly.php?year=' . $_nav_month_year . '&month=' . $_nav_month_month],
    ['type' => 'yearly',  'label' => 'Jahr',
     'href' => 'yearly.php?year=' . $_nav_month_year . '&month=' . $_nav_month_month],
];

// ── Import / CSV upload dropdown extras ───────────────────────────────────────

$_scrapes_dir  = dirname(__DIR__) . '/scrapes';
$_import_count = count(array_merge(
    glob($_scrapes_dir . '/*.csv')  ?: [],
    glob($_scrapes_dir . '/*.xlsx') ?: []
));
$_isAdmin = (($_SESSION['rights'] ?? '') === 'Admin');

$_extras = [];
if ($_import_count > 0) {
    $_extras[] = '<button class="dropdown-link-btn dropdown-link-btn--import" id="import-trigger" type="button">'
               . 'Importieren (' . $_import_count . ')'
               . '</button>';
} elseif ($_isAdmin) {
    $_extras[] = '<label class="dropdown-link-btn" style="cursor:pointer" for="csv-upload-input" id="upload-label">'
               . 'CSV hochladen'
               . '</label>'
               . '<input type="file" id="csv-upload-input" accept=".csv" style="display:none">';
}

// ── Render shared header ──────────────────────────────────────────────────────

\Erikr\Chrome\Header::render([
    'appName'       => 'Energie',
    'base'          => $base,
    'cspNonce'      => $_cspNonce ?? '',
    'csrfToken'     => function_exists('csrf_token') ? csrf_token() : '',
    'pageType'      => $page_type ?? '',
    'appMenu'       => $_appMenu,
    'extraItems'    => $_extras,
    'brandLogoSrc'  => $base . '/assets/jardyx.svg',
    'themeEndpoint' => $base . '/preferences.php',
]);
?>
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
        <button class="btn" id="imp-cancel">Abbrechen</button>
        <button class="btn btn-outline-success" id="imp-confirm">Importieren</button>
    </div>
</dialog>
<?php endif; ?>
<script nonce="<?= $_cspNonce ?>">
(function() {
    const apiBase   = <?= json_encode($base) ?>;
    const csrfToken = <?= json_encode(csrf_token()) ?>;

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
                ? `Import abgeschlossen: ${_r.total} gefunden, ${_r.existing} übersprungen, ${_r.imported} importiert.`
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
        const importLabel = importBtn.textContent;

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
})();
</script>
