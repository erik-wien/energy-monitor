<?php
require_once __DIR__ . '/../inc/initialize.php';

if (!empty($_SESSION['loggedin'])) {
    header('Location: index.php'); exit;
}

$alerts = $_SESSION['alerts'] ?? [];
unset($_SESSION['alerts']);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Energie · Anmelden</title>
    <?php $_b = '/' . explode('/', ltrim($_SERVER['SCRIPT_NAME'], '/'))[0]; ?>
    <link rel="stylesheet" href="<?= $_b ?>/styles/shared/theme.css">
    <link rel="stylesheet" href="<?= $_b ?>/styles/shared/reset.css">
    <link rel="stylesheet" href="<?= $_b ?>/styles/energie-theme.css">
    <link rel="stylesheet" href="<?= $_b ?>/styles/energie.css">
    <link rel="icon" type="image/x-icon" href="<?= $_b ?>/img/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $_b ?>/img/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= $_b ?>/img/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= $_b ?>/img/apple-touch-icon.png">
</head>
<body>
<header>
    <span style="display:flex;align-items:center;gap:0.75rem">
        <img src="<?= $_b ?>/img/energieLogo_icon.svg" alt="" style="height:32px;width:32px;object-fit:contain">
        <h1>Energie</h1>
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
                       autocomplete="username" required autofocus>
            </div>
            <div class="form-group">
                <label for="login-password">Kennwort</label>
                <input type="password" id="login-password" name="login-password"
                       autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn-login">Anmelden</button>
        </form>
    </div>
</div>
</body>
</html>
