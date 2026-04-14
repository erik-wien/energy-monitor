<?php
require_once __DIR__ . '/../inc/initialize.php';

if (!empty($_SESSION['loggedin'])) {
    header('Location: index.php'); exit;
}

$alerts    = $_SESSION['alerts'] ?? [];
unset($_SESSION['alerts']);
$remembered = htmlspecialchars($_COOKIE['energie_username'] ?? '', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Energie · Anmelden</title>
    <?php $_b = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'); ?>
    <link rel="stylesheet" href="<?= $_b ?>/styles/shared/theme.css">
    <link rel="stylesheet" href="<?= $_b ?>/styles/shared/reset.css">
    <link rel="stylesheet" href="<?= $_b ?>/styles/shared/layout.css">
    <link rel="stylesheet" href="<?= $_b ?>/styles/shared/components.css">
    <link rel="stylesheet" href="<?= $_b ?>/styles/energie-theme.css">
    <link rel="stylesheet" href="<?= $_b ?>/styles/energie.css">
    <link rel="icon" type="image/x-icon" href="<?= $_b ?>/img/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $_b ?>/img/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= $_b ?>/img/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= $_b ?>/img/apple-touch-icon.png">
</head>
<body>
<header class="app-header">
    <span class="brand">
        <img src="<?= $_b ?>/img/jardyx.svg" class="header-logo" width="28" height="28" alt="">
        <span class="header-appname">Energie</span>
    </span>
</header>
<div class="login-wrap">
    <div class="login-card">
        <h2>Anmelden</h2>
        <?php foreach ($alerts as [$type, $msg]): ?>
            <div class="alert alert-<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>">
                <?= $msg ?>
            </div>
        <?php endforeach; ?>
        <form method="post" action="authentication.php">
            <?= csrf_input() ?>
            <div class="form-group">
                <label for="login-username">Benutzername</label>
                <input type="text" id="login-username" name="login-username"
                       autocomplete="username" required autofocus
                       value="<?= $remembered ?>">
            </div>
            <div class="form-group">
                <label for="login-password">Kennwort</label>
                <input type="password" id="login-password" name="login-password"
                       autocomplete="current-password" required>
            </div>
            <div class="form-check">
                <input type="checkbox" id="rememberName" name="rememberName" value="1"
                       <?= $remembered !== '' ? 'checked' : '' ?>>
                <label for="rememberName">Benutzername merken</label>
            </div>
            <button type="submit" class="btn-login">Anmelden</button>
        </form>
        <div class="login-links">
            <a href="forgotPassword.php">Kennwort vergessen?</a>
        </div>
    </div>
</div><?php echo '<footer class="app-footer"><span>&copy; ' . date('Y') . ' Erik R. Accart-Huemer</span> <a href="https://www.eriks.cloud/#impressum" target="_blank" rel="noopener">Impressum</a> <span>' . APP_NAME . ' ' . APP_VERSION . '.' . APP_BUILD . ' &middot; ' . APP_ENV . '</span></footer>'; ?>

</body>
</html>
