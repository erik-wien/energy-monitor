<?php
require_once __DIR__ . '/../inc/initialize.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    header('Location: ./'); exit;
}

auth_logout($con);
auth_logout_redirect_if_central();  // Prod/.test: zentrale Session mitbeenden; sonst weiter lokal
addAlert('info', 'Sie wurden abgemeldet.');
header('Location: login.php'); exit;
