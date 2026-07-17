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
    // world4you runs MySQL 5.5 with a MAX_JOIN_SIZE guard (SQL_BIG_SELECTS=0),
    // which rejects the weekly/monthly readings-aggregation joins on estimated
    // rows-examined. MariaDB (akadbrain/local) does not enforce this, so the app
    // connection opts out of the interactive-client guard for consistent behaviour.
    // Set via exec() (not PDO::MYSQL_ATTR_INIT_COMMAND) to stay version-safe: that
    // constant is deprecated on PHP 8.5 (notice would corrupt JSON API bodies) and
    // the Pdo\Mysql replacement is absent on world4you's older PHP.
    $pdo->exec('SET SESSION SQL_BIG_SELECTS=1');
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(['error' => $e->getMessage()]));
}

/**
 * Whether the chart swipe-to-page gesture is enabled for this user.
 * Defaults to enabled (no row yet); resilient if the column is missing
 * (e.g. migration not yet applied) so pages never fatal over a preference.
 */
function en_swipe_nav_enabled(PDO $pdo, int $userId): bool {
    try {
        $stmt = $pdo->prepare('SELECT swipe_nav FROM en_preferences WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row === false ? true : (bool) $row['swipe_nav'];
    } catch (PDOException $e) {
        return true;
    }
}
