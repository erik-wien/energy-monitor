<?php
/**
 * inc/layout.php — page shell for every Energie page.
 *
 * render_page_head()  emits <!DOCTYPE>…</head><body>
 * render_header()     emits the shared Chrome header (§12) + import toolbar
 * render_footer()     emits the shared Chrome footer (§13) + </body></html>
 * render_anon_header() head + Chrome header (loggedIn=false) + <main> for pre-auth pages
 *
 * Expected globals (set by inc/db.php or inc/initialize.php):
 *   $base, $_cspNonce, $pdo  (pdo only for render_header())
 */

use Erikr\Chrome\Header;
use Erikr\Chrome\Footer;

/**
 * Emits <!DOCTYPE>, <html>, <head>, and <body> open tag.
 * $extraHead is optional raw HTML injected before </head> (e.g. CDN scripts).
 */
function render_page_head(string $title, string $extraHead = ''): void
{
    global $base;
    $pageTitle = htmlspecialchars($title . ' · Energie', ENT_QUOTES, 'UTF-8');
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="<?= $base ?>/css/shared/theme.css<?= en_asset_v('css/shared/theme.css') ?>">
    <link rel="stylesheet" href="<?= $base ?>/css/shared/reset.css<?= en_asset_v('css/shared/reset.css') ?>">
    <link rel="stylesheet" href="<?= $base ?>/css/shared/layout.css<?= en_asset_v('css/shared/layout.css') ?>">
    <link rel="stylesheet" href="<?= $base ?>/css/shared/components.css<?= en_asset_v('css/shared/components.css') ?>">
    <link rel="stylesheet" href="<?= $base ?>/css/energie-theme.css<?= en_asset_v('css/energie-theme.css') ?>">
    <link rel="stylesheet" href="<?= $base ?>/css/energie.css<?= en_asset_v('css/energie.css') ?>">
    <meta name="theme-color" content="<?= htmlspecialchars(APP_COLOR, ENT_QUOTES) ?>">
    <link rel="icon" type="image/svg+xml" href="<?= $base ?>/css/shared/logos/jardyx_gelb.svg">
    <link rel="icon" type="image/x-icon" href="<?= $base ?>/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $base ?>/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= $base ?>/favicon-16x16.png">
    <link rel="apple-touch-icon" href="<?= $base ?>/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= $base ?>/web-app-manifest-192x192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="<?= $base ?>/web-app-manifest-512x512.png">
    <link rel="icon" type="image/png" sizes="1024x1024" href="<?= $base ?>/web-app-manifest-1024x1024.png">
    <?php if ($extraHead !== '') echo $extraHead; ?>
</head>
<body>
    <?php
}

/**
 * Emits the authenticated app header: nav menu, import toolbar, Chrome §12 header.
 * Requires $pdo (set by inc/db.php) for nav-target lookup.
 */
function render_header(string $page_type): void
{
    global $base, $pdo, $_cspNonce;

    // Nav targets — latest data day for Aktuell, current ISO week/month for others.
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
    // Cross-App-Navigation aus der zentralen Registry (Erikr\Chrome\AppsMenu) —
    // ersetzt die frühere handgepflegte Liste (TASK-19).
    $_appsMenu = \Erikr\Chrome\AppsMenu::build('energie', APP_ENV);

    // Import toolbar — shown when CSVs are waiting or when admin can upload.
    $_scrapes_dir  = dirname(__DIR__) . '/scrapes';
    $_import_count = count(array_merge(
        glob($_scrapes_dir . '/*.csv')  ?: [],
        glob($_scrapes_dir . '/*.xlsx') ?: []
    ));
    $_isAdmin = (($_SESSION['rights'] ?? '') === 'Admin');

    $_extras = [];
    if ($_import_count > 0 && $_isAdmin) {
        $_extras[] = '<button class="dropdown-link-btn dropdown-link-btn--import" id="import-trigger" type="button">'
                   . 'Importieren (' . $_import_count . ')'
                   . '</button>';
    } elseif ($_isAdmin) {
        $_extras[] = '<label class="dropdown-link-btn" style="cursor:pointer" for="csv-upload-input" id="upload-label">'
                   . 'CSV hochladen'
                   . '</label>'
                   . '<input type="file" id="csv-upload-input" accept=".csv" style="display:none">';
    }

    Header::render([
        'appName'       => 'Energie',
        'base'          => $base,
        'cspNonce'      => $_cspNonce ?? '',
        'csrfToken'     => function_exists('csrf_token') ? csrf_token() : '',
        'pageType'      => $page_type,
        'appMenu'       => $_appMenu,
        'appsMenu'      => $_appsMenu,
        'extraItems'    => $_extras,
        'themeEndpoint' => $base . '/preferences.php',
        'profileHref'   => $base . '/preferences.php#profilbild',
        'emailHref'     => $base . '/preferences.php#email',
        'securityHref'  => $base . '/security.php',
    ]);

    if ($_import_count > 0 && $_isAdmin): ?>
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
    <div id="imp-format-warn" class="alert alert-warning" role="alert" hidden style="margin-top:1rem"></div>
    <div id="imp-epex-section" style="display:none;margin-top:1rem">
        <p id="imp-epex-label" style="font-size:.85rem;color:var(--color-text-muted);margin:0 0 .4rem"></p>
        <progress id="imp-epex-bar" style="width:100%" value="0" max="1"></progress>
    </div>
    <div id="imp-csv-section" style="display:none;margin-top:1rem">
        <p id="imp-csv-label" style="font-size:.85rem;color:var(--color-text-muted);margin:0 0 .4rem"></p>
        <progress id="imp-csv-bar" style="width:100%" value="0" max="1"></progress>
    </div>
    <div class="import-dialog-btns">
        <button class="btn" id="imp-cancel">Abbrechen</button>
        <button class="btn btn-outline-danger" id="imp-confirm">Importieren</button>
    </div>
</dialog>
<?php endif; ?>
<script nonce="<?= $_cspNonce ?>">
(function() {
    const apiBase   = <?= json_encode($base) ?>;
    const csrfToken = <?= json_encode(csrf_token()) ?>;
    const isAdmin   = <?= json_encode(($_SESSION['rights'] ?? '') === 'Admin') ?>;

    // Import status toast (shown after page reload)
    const _stored = sessionStorage.getItem('importResult');
    if (_stored) {
        sessionStorage.removeItem('importResult');
        try {
            const _r = JSON.parse(_stored);
            const _el = document.createElement('div');
            if (_r.ok || _r.teilfehler) {
                _el.className = 'alert ' + (_r.teilfehler ? 'alert-warning' : 'alert-success');
                _el.style.cssText = 'margin:0.75rem 1.5rem;';
                let msg = typeof _r.imported === 'number'
                    ? `Import: ${_r.total} gefunden, ${_r.existing} übersprungen, ${_r.imported} importiert.`
                    : (_r.log || 'OK').trim().slice(0, 200);
                if (_r.failed) msg += `, ${_r.failed} Abschnitt(e) fehlgeschlagen`;
                if (_r.epexMonths) msg += ` Spot-Preise: ${_r.epexMonths} Monat(e) geladen.`;
                _el.textContent = msg;
            } else {
                _el.className = 'alert alert-danger';
                _el.style.cssText = 'margin:0.75rem 1.5rem;';
                _el.textContent = `Import fehlgeschlagen: ${(_r.error || _r.log || 'Unbekannter Fehler').trim().slice(0, 200)}`;
            }
            document.querySelector('.app-header')?.insertAdjacentElement('afterend', _el);
            setTimeout(() => _el.remove(), 8000);
        } catch (_) {}
    }

    // Import trigger — client-loop: preview → dialog → chunked import → EPEX → finalize
    const importBtn    = document.getElementById('import-trigger');
    const importDialog = document.getElementById('import-dialog');
    if (importBtn && importDialog) {
        const impTotal      = document.getElementById('imp-total');
        const impExisting   = document.getElementById('imp-existing');
        const impNew        = document.getElementById('imp-new');
        const impCancel     = document.getElementById('imp-cancel');
        const impConfirm    = document.getElementById('imp-confirm');
        const impFormatWarn = document.getElementById('imp-format-warn');
        const impEpexSect   = document.getElementById('imp-epex-section');
        const impEpexBar    = document.getElementById('imp-epex-bar');
        const impEpexLbl    = document.getElementById('imp-epex-label');
        const impCsvSect    = document.getElementById('imp-csv-section');
        const impCsvBar     = document.getElementById('imp-csv-bar');
        const impCsvLbl     = document.getElementById('imp-csv-label');
        const importLabel   = importBtn.textContent;
        let importAbbruch   = false;
        let importLaeuft    = false;
        let lastPreview     = null;

        function _post(type, extra) {
            let body = 'csrf_token=' + encodeURIComponent(csrfToken);
            if (extra) body += '&' + extra;
            return fetch(apiBase + '/api.php?type=' + type, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body,
            }).then(r => r.json());
        }

        async function _apiJson(type, extra) {
            let body = 'csrf_token=' + encodeURIComponent(csrfToken);
            if (extra) body += '&' + extra;
            const r = await fetch(apiBase + '/api.php?type=' + type, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body,
            });
            let data;
            try {
                data = await r.json();
            } catch (_) {
                const snippet = (await r.text().catch(() => '')).slice(0, 300);
                throw { httpStatus: r.status, detail: snippet };
            }
            if (!r.ok || data.ok === false) throw { httpStatus: r.status, error: data.error, detail: data.detail };
            return data;
        }

        function _httpFehler(e) {
            if (e && e.httpStatus === 504) {
                return { ok: false, error: 'Zeitüberschreitung (HTTP 504) — Server zu langsam. Bitte erneut versuchen.' };
            }
            const s = e && e.httpStatus ? ' (HTTP ' + e.httpStatus + ')' : '';
            const d = e && e.detail ? ' — ' + e.detail : '';
            return { ok: false, error: (e && e.error ? e.error : 'Serverfehler') + s + d };
        }

        function escapeHtml(s) {
            const d = document.createElement('div');
            d.textContent = String(s);
            return d.innerHTML;
        }

        async function _fetchEpexWithProgress() {
            const preview = await _post('epex-preview');
            if (!preview.ok || !preview.months || !preview.months.length) return {};
            const months = preview.months;
            if (months.length > 1) {
                impEpexSect.style.display = '';
                impEpexBar.max   = months.length;
                impEpexBar.value = 0;
                impEpexLbl.textContent = 'Spot-Preise: 0\u00a0/\u00a0' + months.length + ' Monate';
            } else {
                impEpexSect.style.display = '';
                impEpexLbl.textContent = 'Lade Spot-Preise\u2026';
            }
            let epexRows = 0;
            for (let i = 0; i < months.length; i++) {
                const { y, m } = months[i];
                try {
                    const d = await _post('fetch-prices', 'year=' + y + '&month=' + m);
                    epexRows += d.rows || 0;
                } catch (_) {}
                if (months.length > 1) {
                    impEpexBar.value = i + 1;
                    impEpexLbl.textContent = 'Spot-Preise: ' + (i + 1) + '\u00a0/\u00a0' + months.length + ' Monate';
                }
            }
            return { epexMonths: months.length, epexRows };
        }

        async function _runImport() {
            importAbbruch = false;
            importLaeuft  = true;
            impConfirm.disabled = true;
            impCancel.disabled  = false;
            impConfirm.textContent = 'Importiere\u2026';
            try {
                const cand = (await _apiJson('import-candidates')).candidates || [];
                impCsvSect.style.display = '';
                impCsvBar.max   = cand.length || 1;
                impCsvBar.value = 0;
                const doneDays    = [];
                const doneFiles   = new Set();
                const failedFiles = new Set();
                let failed = 0, imported = 0;
                for (let i = 0; i < cand.length; i++) {
                    if (importAbbruch) break;
                    const c = cand[i];
                    impCsvLbl.textContent = 'Import: ' + (i + 1) + '\u00a0/\u00a0' + cand.length + ' \u00b7 ' + c.file + ' ' + c.date;
                    try {
                        const r = await _apiJson('import-chunk', 'file=' + encodeURIComponent(c.file) + '&day=' + encodeURIComponent(c.date));
                        imported += r.inserted || 0;
                        doneDays.push(c.date);
                        doneFiles.add(c.file);
                    } catch (_) {
                        failed++;
                        failedFiles.add(c.file);
                    }
                    impCsvBar.value = i + 1;
                }
                // Nur Dateien ohne fehlgeschlagenen Abschnitt archivieren (Dateien mit
                // mindestens einem gescheiterten Tag bleiben in scrapes/ fuer einen Retry).
                const archivableFiles = [...doneFiles].filter(f => !failedFiles.has(f));
                let epex = {};
                if (!importAbbruch && isAdmin) {
                    try { epex = await _fetchEpexWithProgress(); } catch (_) {}
                }
                if (!importAbbruch && doneDays.length) {
                    await _apiJson('import-finalize',
                        doneDays.map(d => 'days[]=' + encodeURIComponent(d)).join('&') + '&' +
                        archivableFiles.map(f => 'files[]=' + encodeURIComponent(f)).join('&'));
                }
                importDialog.close();
                importLaeuft = false;
                const result = importAbbruch
                    ? { ok: false, error: 'Import abgebrochen.' }
                    : Object.assign({
                          ok: failed === 0,
                          teilfehler: failed > 0,
                          imported,
                          existing: lastPreview ? lastPreview.existing : undefined,
                          total: lastPreview ? lastPreview.total : undefined,
                          failed,
                      }, epex);
                sessionStorage.setItem('importResult', JSON.stringify(result));
                location.reload();
            } catch (e) {
                importLaeuft = false;
                importDialog.close();
                sessionStorage.setItem('importResult', JSON.stringify(_httpFehler(e)));
                location.reload();
            }
        }

        importBtn.addEventListener('click', e => {
            e.stopPropagation();
            importBtn.textContent = 'Lade Vorschau\u2026';
            importBtn.disabled    = true;
            _apiJson('preview-import')
                .then(d => {
                    importBtn.textContent = importLabel;
                    importBtn.disabled    = false;
                    lastPreview = d;
                    impTotal.textContent    = d.total;
                    impExisting.textContent = d.existing;
                    impNew.textContent      = d.new;
                    const bad = (d.dateien || []).filter(f => !f.ok);
                    if (bad.length) {
                        impFormatWarn.hidden    = false;
                        impFormatWarn.innerHTML = 'Format gepr\u00fcft \u2014 ' + bad.length +
                            ' Datei(en) sehen anders aus als erwartet:<ul>' +
                            bad.map(f => '<li>' + escapeHtml(f.name) + ': ' + escapeHtml(f.problem || 'unbekannt') + '</li>').join('') +
                            '</ul>';
                        impConfirm.disabled = true;
                    } else {
                        impFormatWarn.hidden = true;
                        impConfirm.disabled  = false;
                    }
                    impConfirm.textContent = 'Importieren';
                    importDialog.showModal();
                })
                .catch(e => {
                    importBtn.textContent = importLabel;
                    importBtn.disabled    = false;
                    sessionStorage.setItem('importResult', JSON.stringify(_httpFehler(e)));
                    location.reload();
                });
        });

        impCancel.addEventListener('click', () => {
            if (importLaeuft) { importAbbruch = true; return; }
            importDialog.close();
        });
        impConfirm.addEventListener('click', _runImport);
        importDialog.addEventListener('click', e => { if (e.target === importDialog) importDialog.close(); });

        if (sessionStorage.getItem('autoPreview') === '1') {
            sessionStorage.removeItem('autoPreview');
            importBtn.click();
        }
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
                .then(r => {
                    if (r.ok) return r.json().catch(() => ({ ok: true }));
                    return r.json().catch(() => ({ error: 'Upload fehlgeschlagen (HTTP ' + r.status + ')' }));
                })
                .then(d => {
                    if (d.ok) {
                        sessionStorage.setItem('autoPreview', '1');
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
<?php
}

/**
 * Emits the Chrome footer (§13) and closes </body></html>.
 */
function render_footer(): void
{
    global $base;
    // Stage/owner/version aus dem Library-Default (Footer::deriveStage kennt
    // akadbrain jetzt selbst; owner-Default = 'Erik R. Accart-Huemer') — TASK-19.
    Footer::render([
        'base' => $base,
    ]);
    echo '</body></html>';
}

/**
 * Full pre-auth page shell: <!DOCTYPE>…<body> + Chrome header (no user menu) + <main>.
 * Caller closes </main> before render_footer().
 */
function render_anon_header(string $title): void
{
    global $base, $_cspNonce;
    render_page_head($title);
    Header::render([
        'appName'       => 'Energie',
        'base'          => $base,
        'cspNonce'      => $_cspNonce ?? '',
        'loggedIn'      => false,
        'anonLoginHref' => null,
    ]);
    echo '<main id="main-content" tabindex="-1">';
}
