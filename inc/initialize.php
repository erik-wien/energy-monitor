<?php
/**
 * inc/initialize.php
 *
 * Bootstrap: config, MySQLi $con (auth DB), auth library.
 * Included by inc/db.php for pages that need PDO. Auth-only pages (authentication.php, logout.php, avatar.php) may include this file directly to avoid opening an unnecessary PDO connection.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/config.php';

// ── Config ────────────────────────────────────────────────────────────────────

$_cfg = energie_load_config();

define('APP_BASE_URL',      rtrim($_cfg['app']['base_url']       ?? '', '/'));
define('APP_NAME',          $_cfg['app']['name']                 ?? 'Energie');
define('APP_SUPPORT_EMAIL', $_cfg['app']['support_email']        ?? 'contact@eriks.cloud');
define('APP_VERSION',       '1.1');
define('APP_BUILD',         1);
define('APP_ENV',           $_cfg['app']['env']                  ?? 'dev');

/** Energie's $con connects directly to the auth DB — no schema prefix needed. */
define('AUTH_DB_PREFIX', '');

define('APP_CODE', $_cfg['APP_CODE'] ?? 'energie');

define('RATE_LIMIT_FILE', __DIR__ . '/../data/ratelimit.json');

// ── Auth DB connection ($con — MySQLi) ────────────────────────────────────────

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$con = new mysqli(
    $_cfg['auth_db']['host'],
    $_cfg['auth_db']['user'],
    $_cfg['auth_db']['password'],
    $_cfg['auth_db']['name']
);
$con->set_charset('utf8mb4');
unset($_cfg);

// ── Bootstrap (security headers + session + CSRF) ─────────────────────────────

// img-src blob: is required by the Cropper.js avatar editor in preferences.php,
// which previews the selected file via URL.createObjectURL() before upload.
auth_bootstrap([
    'style-src' => 'https://cdn.jsdelivr.net',  // Flatpickr, Chart.js CDN assets
    'img-src'   => 'blob:',
]);

// ── Cross-DB cleanup hooks for admin_delete_user() ────────────────────────────
//
// en_preferences lives in the Energie data DB ($pdo), while auth_accounts lives
// in jardyx_auth ($con). On local/akadbrain these are different DBs; on
// world4you they happen to collide in one DB. Either way, we cannot rely on
// FK ON DELETE CASCADE across DBs, so register a cleanup hook that runs
// inside admin_delete_user()'s DELETE transaction.
admin_register_delete_cleanup(static function (mysqli $authCon, int $userId): void {
    global $pdo;
    if (!isset($pdo)) { return; }
    $stmt = $pdo->prepare('DELETE FROM en_preferences WHERE user_id = ?');
    $stmt->execute([$userId]);
});
