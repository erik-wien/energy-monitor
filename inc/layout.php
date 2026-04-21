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
    <link rel="stylesheet" href="<?= $base ?>/css/shared/theme.css">
    <link rel="stylesheet" href="<?= $base ?>/css/shared/reset.css">
    <link rel="stylesheet" href="<?= $base ?>/css/shared/layout.css">
    <link rel="stylesheet" href="<?= $base ?>/css/shared/components.css">
    <link rel="stylesheet" href="<?= $base ?>/css/energie-theme.css">
    <link rel="stylesheet" href="<?= $base ?>/css/energie.css">
    <meta name="theme-color" content="<?= htmlspecialchars(APP_COLOR, ENT_QUOTES) ?>">
    <link rel="icon" type="image/svg+xml" href="<?= $base ?>/jardyx-favicon.svg">
    <link rel="icon" type="image/x-icon" href="<?= $base ?>/assets/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $base ?>/assets/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= $base ?>/assets/favicon-16x16.png">
    <link rel="apple-touch-icon" href="<?= $base ?>/assets/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= $base ?>/assets/web-app-manifest-192x192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="<?= $base ?>/assets/web-app-manifest-512x512.png">
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
        ['label' => 'Apps', 'children' => [
            ['href' => 'https://wlmonitor.jardyx.com', 'label' => 'WL Monitor'],
            ['href' => 'https://chat.jardyx.com',      'label' => 'Chat'],
            ['href' => 'https://zeit.jardyx.com',      'label' => 'Zeit'],
            ['href' => 'https://lastfm.jardyx.com',    'label' => 'Last.fm'],
            ['href' => 'https://suche.eriks.cloud',    'label' => 'Suche'],
        ]],
    ];
    if (defined('APP_ENV') && APP_ENV === 'local') {
        $_appMenu[] = ['label' => 'Test', 'children' => [
            ['href' => 'http://energie.test',   'label' => 'Energie'],
            ['href' => 'http://wlmonitor.test', 'label' => 'WL Monitor'],
            ['href' => 'http://chat.test',      'label' => 'Chat'],
            ['href' => 'http://zeit.test',      'label' => 'Zeit'],
            ['href' => 'http://lastfm.test',    'label' => 'Last.fm'],
            ['href' => 'http://suche.test',     'label' => 'Suche'],
        ]];
    }

    // Import toolbar — shown when CSVs are waiting or when admin can upload.
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

    Header::render([
        'appName'       => 'Energie',
        'base'          => $base,
        'cspNonce'      => $_cspNonce ?? '',
        'csrfToken'     => function_exists('csrf_token') ? csrf_token() : '',
        'pageType'      => $page_type,
        'appMenu'       => $_appMenu,
        'extraItems'    => $_extras,
        'brandLogoSrc'  => $base . '/jardyx-logo.svg',
        'themeEndpoint' => $base . '/preferences.php',
        'securityHref'  => $base . '/security.php',
    ]);

    if ($_import_count > 0): ?>
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
    <div id="imp-epex-section" style="display:none;margin-top:1rem">
        <p id="imp-epex-label" style="font-size:.85rem;color:var(--color-text-muted);margin:0 0 .4rem"></p>
        <progress id="imp-epex-bar" style="width:100%" value="0" max="1"></progress>
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
    const isAdmin   = <?= json_encode(($_SESSION['rights'] ?? '') === 'Admin') ?>;

    // Import status toast (shown after page reload)
    const _stored = sessionStorage.getItem('importResult');
    if (_stored) {
        sessionStorage.removeItem('importResult');
        try {
            const _r = JSON.parse(_stored);
            const _el = document.createElement('div');
            _el.className = 'alert ' + (_r.ok ? 'alert-success' : 'alert-danger');
            _el.style.cssText = 'margin:0.75rem 1.5rem;';
            if (_r.ok) {
                let msg = typeof _r.imported === 'number'
                    ? `Import: ${_r.total} gefunden, ${_r.existing} übersprungen, ${_r.imported} importiert.`
                    : (_r.log || 'OK').trim().slice(0, 200);
                if (_r.epexMonths) msg += ` Spot-Preise: ${_r.epexMonths} Monat(e) geladen.`;
                _el.textContent = msg;
            } else {
                _el.textContent = `Import fehlgeschlagen: ${(_r.log || _r.error || 'Unbekannter Fehler').trim().slice(0, 200)}`;
            }
            document.querySelector('.app-header')?.insertAdjacentElement('afterend', _el);
            setTimeout(() => _el.remove(), 8000);
        } catch (_) {}
    }

    // Import trigger — 2-step: preview → dialog → import → auto EPEX fetch
    const importBtn    = document.getElementById('import-trigger');
    const importDialog = document.getElementById('import-dialog');
    if (importBtn && importDialog) {
        const impTotal    = document.getElementById('imp-total');
        const impExisting = document.getElementById('imp-existing');
        const impNew      = document.getElementById('imp-new');
        const impCancel   = document.getElementById('imp-cancel');
        const impConfirm  = document.getElementById('imp-confirm');
        const impEpexSect = document.getElementById('imp-epex-section');
        const impEpexBar  = document.getElementById('imp-epex-bar');
        const impEpexLbl  = document.getElementById('imp-epex-label');
        const importLabel = importBtn.textContent;

        function _post(type, extra) {
            let body = 'csrf_token=' + encodeURIComponent(csrfToken);
            if (extra) body += '&' + extra;
            return fetch(apiBase + '/api.php?type=' + type, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body,
            }).then(r => r.json());
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
            impConfirm.disabled = true;
            impCancel.disabled  = true;
            impConfirm.textContent = 'Importiere\u2026';
            let result;
            try {
                result = await _post('trigger-import');
            } catch (_) {
                result = { ok: false, error: 'Netzwerkfehler' };
            }
            if (result.ok && isAdmin) {
                try {
                    const epex = await _fetchEpexWithProgress();
                    result = Object.assign({}, result, epex);
                } catch (_) {}
            }
            importDialog.close();
            sessionStorage.setItem('importResult', JSON.stringify(result));
            location.reload();
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
    $stage = in_array(strtolower(APP_ENV), ['local', 'localhost', 'dev', 'development', 'staging', 'akadbrain'], true) ? 'DEV' : 'PROD';
    Footer::render([
        'base'    => $base,
        'owner'   => 'Erik R. Accart-Huemer',
        'version' => APP_VERSION . '.' . APP_BUILD . ' ' . $stage,
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
        'brandLogoSrc'  => $base . '/jardyx-logo.svg',
        'loggedIn'      => false,
        'anonLoginHref' => null,
    ]);
    echo '<main id="main-content" tabindex="-1">';
}
