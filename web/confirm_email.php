<?php
require_once __DIR__ . '/../inc/db.php';

$code = trim($_GET['code'] ?? '');

if ($code === '' || !preg_match('/^[0-9a-f]{64}$/', $code)) {
    addAlert('danger', 'Ungültiger oder fehlender Bestätigungslink.');
    header('Location: login.php'); exit;
}

$stmt = $con->prepare(
    'SELECT id, username, pending_email FROM auth_accounts
     WHERE email_change_code = ? AND pending_email IS NOT NULL'
);
$stmt->bind_param('s', $code);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    addAlert('danger', 'Der Bestätigungslink ist ungültig oder wurde bereits verwendet.');
    header('Location: login.php'); exit;
}

// Race condition guard
$chk = $con->prepare('SELECT id FROM auth_accounts WHERE email = ? AND id != ?');
$chk->bind_param('si', $row['pending_email'], $row['id']);
$chk->execute();
$chk->store_result();
$taken = $chk->num_rows > 0;
$chk->close();

if ($taken) {
    $clr = $con->prepare(
        'UPDATE auth_accounts SET pending_email = NULL, email_change_code = NULL WHERE id = ?'
    );
    $clr->bind_param('i', $row['id']);
    $clr->execute();
    $clr->close();
    addAlert('danger', 'Die E-Mail-Adresse ist inzwischen bereits vergeben.');
    header('Location: login.php'); exit;
}

$upd = $con->prepare(
    'UPDATE auth_accounts SET email = pending_email, pending_email = NULL, email_change_code = NULL
     WHERE id = ?'
);
$upd->bind_param('i', $row['id']);
$upd->execute();
$upd->close();

if (!empty($_SESSION['id']) && (int) $_SESSION['id'] === (int) $row['id']) {
    $_SESSION['email'] = $row['pending_email'];
}

appendLog($con, 'prefs', 'Email confirmed for ' . $row['username'], 'web');
addAlert('success', 'Ihre E-Mail-Adresse wurde erfolgreich aktualisiert.');
header('Location: ' . (empty($_SESSION['loggedin']) ? 'login.php' : 'preferences.php'));
exit;
