<?php
require_once __DIR__ . '/../inc/initialize.php';
require_once __DIR__ . '/../inc/layout.php';
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

if (!empty($_SESSION['loggedin'])) {
    header('Location: index.php'); exit;
}

$alerts    = $_SESSION['alerts'] ?? [];
unset($_SESSION['alerts']);
$remembered = htmlspecialchars($_COOKIE['energie_username'] ?? '', ENT_QUOTES, 'UTF-8');
?>
<?php render_anon_header('Anmelden'); ?>
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
            <div class="form-check">
                <input type="checkbox" id="remember_me" name="remember_me" value="1">
                <label for="remember_me">Angemeldet bleiben (<?= (int) (AUTH_REMEMBER_LIFETIME / 86400) ?>&nbsp;Tage)</label>
            </div>
            <p class="form-text">Meldet Sie auch auf den anderen Apps auf eriks.cloud an.</p>
            <button type="submit" class="btn-login">Anmelden</button>
        </form>
        <div class="login-links">
            <a href="forgotPassword.php">Kennwort vergessen?</a>
        </div>
    </div>
</div>
</main>
<?php render_footer(); ?>
