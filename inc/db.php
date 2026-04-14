<?php
require_once __DIR__ . '/initialize.php';
// Shared DB connection — include this file, do not access directly
// $base: URL prefix for this app (e.g. '/energie' or '/energie.test')
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$config_path = $base === '/energie.test'
    ? '/opt/homebrew/etc/energie-config-dev.ini'
    : '/opt/homebrew/etc/energie-config.ini';
$cfg = parse_ini_file($config_path, true);
if (!$cfg) {
    http_response_code(500);
    die(json_encode(['error' => 'Config not found']));
}

try {
    $pdo = new PDO(
        "mysql:host={$cfg['db']['host']};dbname={$cfg['db']['database']};charset=utf8mb4",
        $cfg['db']['user'],
        $cfg['db']['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(['error' => $e->getMessage()]));
}
