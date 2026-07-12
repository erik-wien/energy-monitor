<?php
require_once __DIR__ . '/../inc/initialize.php';
require_once __DIR__ . '/../inc/layout.php';
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

// Prod/.test: kein eigenes Login-Formular — zum zentralen Login-Host umleiten.
auth_login_redirect_if_central();

if (!empty($_SESSION['loggedin'])) {
    header('Location: index.php'); exit;
}

$alerts     = $_SESSION['alerts'] ?? [];
unset($_SESSION['alerts']);
$remembered = htmlspecialchars($_COOKIE['energie_username'] ?? '', ENT_QUOTES, 'UTF-8');
$theme      = $_COOKIE['theme'] ?? 'auto';
$theme      = in_array($theme, ['light', 'dark', 'auto'], true) ? $theme : 'auto';
$nonce      = htmlspecialchars($_cspNonce ?? '', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="de" data-theme="<?= htmlspecialchars($theme, ENT_QUOTES, 'UTF-8') ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Anmelden &mdash; <?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="theme-color" content="<?= htmlspecialchars(APP_COLOR, ENT_QUOTES) ?>">
  <link rel="icon" type="image/svg+xml" href="<?= $base ?>/css/shared/icons/jardyx_gelb.svg">
  <link rel="apple-touch-icon" href="<?= $base ?>/apple-touch-icon.png">
  <link rel="stylesheet" href="<?= $base ?>/css/shared/theme.css<?= en_asset_v('css/shared/theme.css') ?>">
  <link rel="stylesheet" href="<?= $base ?>/css/shared/reset.css<?= en_asset_v('css/shared/reset.css') ?>">
  <link rel="stylesheet" href="<?= $base ?>/css/shared/layout.css<?= en_asset_v('css/shared/layout.css') ?>">
  <link rel="stylesheet" href="<?= $base ?>/css/shared/components.css<?= en_asset_v('css/shared/components.css') ?>">
  <link rel="stylesheet" href="<?= $base ?>/css/energie-theme.css<?= en_asset_v('css/energie-theme.css') ?>">
  <link rel="stylesheet" href="<?= $base ?>/css/energie.css<?= en_asset_v('css/energie.css') ?>">
</head>
<body class="login-page">
<main class="login-main" id="main-content">
  <form class="login-card" method="post" action="authentication.php" autocomplete="on">
    <?= csrf_input() ?>
    <span class="login-logo" aria-hidden="true"></span>
    <h1><?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></h1>
    <?php foreach ($alerts as [$type, $msg]): ?>
      <p class="app-alert app-alert-<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>" role="alert"><?= $msg ?></p>
    <?php endforeach; ?>
    <label class="login-field">
      <span>Benutzername</span>
      <input type="text" name="login-username" autocomplete="username" required autofocus
             value="<?= $remembered ?>" data-clearable>
    </label>
    <label class="login-field">
      <span>Kennwort</span>
      <input type="password" name="login-password" autocomplete="current-password" required>
    </label>
    <label class="login-check">
      <input type="checkbox" name="rememberName" value="1"<?= $remembered !== '' ? ' checked' : '' ?>>
      <span>Benutzername merken</span>
    </label>
    <label class="login-check">
      <input type="checkbox" name="remember_me" value="1">
      <span>Angemeldet bleiben (<?= (int) (AUTH_REMEMBER_LIFETIME / 86400) ?>&nbsp;Tage)</span>
    </label>
    <p class="login-note">Meldet Sie auch auf den anderen Apps auf eriks.cloud an.</p>
    <button type="submit" class="btn-login">Anmelden</button>
    <p class="login-forgot"><a href="forgotPassword.php">Kennwort vergessen?</a></p>
  </form>
</main>
<script src="<?= $base ?>/css/shared/js/field-enhance.js" nonce="<?= $nonce ?>"></script>
</body>
</html>
