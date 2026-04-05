<?php
require_once __DIR__ . '/../inc/initialize.php';
require_once __DIR__ . '/../inc/auth.php';

auth_logout($con);
addAlert('info', 'Sie wurden abgemeldet.');
header('Location: login.php'); exit;
