<?php
require_once __DIR__ . '/../inc/initialize.php';

if (empty($_POST['login-username']) || empty($_POST['login-password'])) {
    addAlert('danger', 'Bitte sowohl Benutzername als auch Kennwort ausfüllen.');
    header('Location: login.php'); exit;
}

if (!csrf_verify()) {
    addAlert('danger', 'Ungültige Anfrage.');
    header('Location: login.php'); exit;
}

$result = auth_login($con, $_POST['login-username'], $_POST['login-password']);

if ($result['ok']) {
    addAlert('info', 'Hallo ' . htmlspecialchars($result['username'], ENT_QUOTES, 'UTF-8') . '.');
    header('Location: index.php'); exit;
} else {
    addAlert('danger', $result['error']);
    header('Location: login.php'); exit;
}
