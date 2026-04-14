<?php
require_once __DIR__ . '/../inc/initialize.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    header('Location: ./'); exit;
}

auth_logout($con);
addAlert('info', 'Sie wurden abgemeldet.');
header('Location: login.php'); exit;
