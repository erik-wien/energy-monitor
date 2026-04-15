<?php
require_once __DIR__ . '/initialize.php';
// Shared DB connection — include this file, do not access directly
// $base: URL prefix for this app (e.g. '/energie' or '/energie.test')
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

$cfg = energie_load_config();

try {
    $pdo = new PDO(
        "mysql:host={$cfg['db']['host']};dbname={$cfg['db']['name']};charset=utf8mb4",
        $cfg['db']['user'],
        $cfg['db']['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(['error' => $e->getMessage()]));
}
