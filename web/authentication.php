<?php
require_once __DIR__ . '/../inc/initialize.php';

$attemptUser = substr((string)($_POST['login-username'] ?? ''), 0, 64);
$logUser     = $attemptUser !== '' ? $attemptUser : '(empty)';

if (empty($_POST['login-username']) || empty($_POST['login-password'])) {
    appendLog($con, 'auth_fail', 'Missing credentials (user="' . $logUser . '")');
    addAlert('danger', 'Bitte sowohl Benutzername als auch Kennwort ausfüllen.');
    header('Location: login.php'); exit;
}

if (!csrf_verify()) {
    appendLog($con, 'auth_fail', 'CSRF failed on login (user="' . $logUser . '")');
    addAlert('danger', 'Ungültige Anfrage.');
    header('Location: login.php'); exit;
}

$remember = !empty($_POST['remember_me']);
$result   = auth_login($con, $_POST['login-username'], $_POST['login-password'], $remember);

if (!empty($result['ok']) && !empty($result['totp_required'])) {
    // Persist rememberName cookie intent for the post-TOTP redirect.
    if (!empty($_POST['rememberName'])) {
        setcookie('energie_username', $_POST['login-username'], [
            'expires'  => time() + 10 * 24 * 60 * 60,
            'path'     => '/',
            'httponly' => true,
            'secure'   => true,
            'samesite' => 'Lax',
        ]);
    } else {
        setcookie('energie_username', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'secure'   => true,
            'samesite' => 'Lax',
        ]);
    }
    header('Location: totp_verify.php'); exit;
}

if ($result['ok']) {
    if (!empty($_POST['rememberName'])) {
        setcookie('energie_username', $_POST['login-username'], [
            'expires'  => time() + 10 * 24 * 60 * 60,
            'path'     => '/',
            'httponly' => true,
            'secure'   => true,
            'samesite' => 'Lax',
        ]);
    } else {
        setcookie('energie_username', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'secure'   => true,
            'samesite' => 'Lax',
        ]);
    }
    addAlert('info', 'Hallo ' . htmlspecialchars($result['username'], ENT_QUOTES, 'UTF-8') . '.');
    header('Location: ./'); exit;
} else {
    addAlert('danger', $result['error']);
    header('Location: login.php'); exit;
}
