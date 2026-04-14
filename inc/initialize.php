<?php
/**
 * inc/initialize.php
 *
 * Bootstrap: config, MySQLi $con (auth DB), auth library.
 * Included by inc/db.php for pages that need PDO. Auth-only pages (authentication.php, logout.php, avatar.php) may include this file directly to avoid opening an unnecessary PDO connection.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// ── Config ────────────────────────────────────────────────────────────────────

$_ini = parse_ini_file('/opt/homebrew/etc/energie-config.ini', true) ?: [];

define('APP_BASE_URL',  rtrim($_ini['app']['base_url']   ?? '', '/'));
define('APP_NAME',      'Energie');
define('APP_VERSION',   '1.0');
define('APP_BUILD',     '2026-04-12');
define('APP_ENV',       file_exists(__DIR__ . '/../app.prod') ? 'prod' : 'dev');
define('SMTP_HOST',     $_ini['smtp']['host']             ?? '');
define('SMTP_PORT',     (int) ($_ini['smtp']['port']      ?? 587));
define('SMTP_USER',     $_ini['smtp']['user']             ?? '');
define('SMTP_PASS',     $_ini['smtp']['password']         ?? '');
define('SMTP_FROM',     $_ini['smtp']['from']             ?? '');
define('SMTP_FROM_NAME',$_ini['smtp']['from_name']        ?? 'Energie');

/** Energie's $con connects directly to the auth DB — no schema prefix needed. */
define('AUTH_DB_PREFIX', '');

define('RATE_LIMIT_FILE', __DIR__ . '/../data/ratelimit.json');

unset($_ini);

// ── Auth DB connection ($con — MySQLi) ────────────────────────────────────────

$_cfg = parse_ini_file('/opt/homebrew/etc/energie-config.ini', true);
if (!$_cfg) {
    http_response_code(500);
    die('Config not found');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$con = new mysqli(
    $_cfg['auth']['host'],
    $_cfg['auth']['user'],
    $_cfg['auth']['password'],
    $_cfg['auth']['database']
);
$con->set_charset('utf8mb4');
unset($_cfg);

// ── Bootstrap (security headers + session + CSRF) ─────────────────────────────

auth_bootstrap([
    'style-src' => 'https://cdn.jsdelivr.net',  // Flatpickr, Chart.js CDN assets
]);
