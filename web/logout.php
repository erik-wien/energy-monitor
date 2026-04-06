<?php
require_once __DIR__ . '/../inc/initialize.php';

auth_logout($con);
addAlert('info', 'Sie wurden abgemeldet.');
header('Location: login.php'); exit;
