<?php
// Expected vars from including file:
// $base       string  URL prefix (e.g. '/energie.test')
// $page_type  string  'daily' | 'weekly' | 'monthly' | 'index'

// Count importable files in scrapes/
$_scrapes_dir  = dirname(__DIR__) . '/scrapes';
$_import_count = count(array_merge(
    glob($_scrapes_dir . '/*.csv')  ?: [],
    glob($_scrapes_dir . '/*.xlsx') ?: []
));

// Nav targets — always point to *today's* day / week / month
$_nav_today       = date('Y-m-d');
$_nav_week_year   = (int)date('o');
$_nav_week_num    = (int)date('W');
$_nav_month_year  = (int)date('Y');
$_nav_month_month = (int)date('n');
$_theme           = $_SESSION['theme'] ?? 'auto';
?>
<script nonce="<?= $_cspNonce ?>">document.documentElement.dataset.theme = <?= json_encode($_theme) ?>;</script>
<header>
    <a href="<?= $base ?>/" style="display:flex;align-items:center;gap:0.75rem;text-decoration:none">
        <img src="<?= $base ?>/img/energieLogo_icon.svg" alt="Energie" style="height:32px;width:32px;object-fit:contain">
        <h1 style="color:var(--color-text)">Energie</h1>
    </a>
    <nav class="header-nav">
        <a href="<?= $base ?>/daily.php?date=<?= $_nav_today ?>"
           <?= $page_type === 'daily'   ? 'class="active"' : '' ?>>Heute</a>
        <a href="<?= $base ?>/weekly.php?year=<?= $_nav_week_year ?>&amp;week=<?= $_nav_week_num ?>"
           <?= $page_type === 'weekly'  ? 'class="active"' : '' ?>>Woche</a>
        <a href="<?= $base ?>/monthly.php?year=<?= $_nav_month_year ?>&amp;month=<?= $_nav_month_month ?>"
           <?= $page_type === 'monthly' ? 'class="active"' : '' ?>>Monat</a>
        <a href="<?= $base ?>/yearly.php?year=<?= $_nav_month_year ?>&amp;month=<?= $_nav_month_month ?>"
           <?= $page_type === 'yearly'  ? 'class="active"' : '' ?>>Jahr</a>
        <div class="user-menu">
            <button class="user-btn" type="button">
                <img src="<?= $base ?>/avatar.php" class="avatar" width="24" height="24" alt="">
                <span><?= htmlspecialchars($_SESSION['username'] ?? '') ?></span>
                <svg class="chevron" width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <path d="M2 4l4 4 4-4"/>
                </svg>
                <?php if ($_import_count > 0): ?>
                    <span class="notif-dot" title="<?= $_import_count ?> Datei(en) importierbar"></span>
                <?php endif; ?>
            </button>
            <div class="user-dropdown">
                <div class="theme-row">
                    <button class="theme-btn <?= $_theme === 'light' ? 'active' : '' ?>" data-theme="light" title="Hell">☀</button>
                    <button class="theme-btn <?= $_theme === 'auto'  ? 'active' : '' ?>" data-theme="auto"  title="Auto">⬤</button>
                    <button class="theme-btn <?= $_theme === 'dark'  ? 'active' : '' ?>" data-theme="dark"  title="Dunkel">🌙</button>
                </div>
                <div class="dropdown-divider"></div>
                <?php if ($_import_count > 0): ?>
                    <button class="dropdown-link-btn dropdown-link-btn--import" id="import-trigger">
                        Importieren (<?= $_import_count ?>)
                    </button>
                    <div class="dropdown-divider"></div>
                <?php endif; ?>
                <a href="<?= $base ?>/preferences.php">Einstellungen</a>
                <form method="post" action="<?= $base ?>/logout.php" style="margin:0">
                    <?= csrf_input() ?>
                    <button type="submit" class="dropdown-link-btn">Abmelden</button>
                </form>
            </div>
        </div>
    </nav>
</header>
<script nonce="<?= $_cspNonce ?>">
(function() {
    const menu   = document.querySelector('.user-menu');
    const apiBase = <?= json_encode($base) ?>;
    if (!menu) return;

    // Dropdown toggle
    menu.querySelector('.user-btn').addEventListener('click', e => {
        e.stopPropagation();
        menu.classList.toggle('open');
    });
    document.addEventListener('click', () => menu.classList.remove('open'));

    // Import trigger
    const importBtn = document.getElementById('import-trigger');
    if (importBtn) {
        importBtn.addEventListener('click', e => {
            e.stopPropagation();
            importBtn.textContent = 'Importiere\u2026';
            importBtn.disabled = true;
            fetch(apiBase + '/api.php?type=trigger-import', { method: 'POST' })
                .then(r => r.json())
                .then(d => {
                    if (d.ok) { location.reload(); }
                    else { alert(d.log || d.error || 'Import fehlgeschlagen'); importBtn.disabled = false; }
                });
        });
    }

    // Theme switcher
    menu.querySelectorAll('.theme-btn').forEach(btn => {
        btn.addEventListener('click', e => {
            e.stopPropagation();
            const theme = btn.dataset.theme;
            document.documentElement.dataset.theme = theme;
            menu.querySelectorAll('.theme-btn').forEach(b => b.classList.toggle('active', b.dataset.theme === theme));
            fetch(apiBase + '/api.php?type=set-theme', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'theme=' + encodeURIComponent(theme)
            });
        });
    });
})();
</script>
