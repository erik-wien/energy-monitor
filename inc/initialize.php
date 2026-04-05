<?php
/**
 * inc/initialize.php
 *
 * Bootstrap: security headers, MySQLi $con, session, CSRF, utility functions.
 * Included at the top of inc/db.php — do not include directly from pages.
 */

// ── Security headers ─────────────────────────────────────────────────────────

$_cspNonce = base64_encode(random_bytes(16));
$_isHttps  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
if ($_isHttps) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
header(
    "Content-Security-Policy: " .
    "default-src 'self'; " .
    "script-src 'self' 'nonce-{$_cspNonce}' https://cdn.jsdelivr.net; " .
    "style-src 'self' 'unsafe-inline'; " .
    "img-src 'self' data:; " .
    "connect-src 'self'; " .
    "frame-ancestors 'none'; " .
    "base-uri 'self'; " .
    "form-action 'self';"
);

// ── MySQLi connection (for auth / wl_accounts / wl_log) ──────────────────────

$_configPath = '/opt/homebrew/etc/energie-config.ini';
$_cfg = parse_ini_file($_configPath, true);
if (!$_cfg) {
    http_response_code(500);
    die('Config not found');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$con = new mysqli(
    $_cfg['db']['host'],
    $_cfg['db']['user'],
    $_cfg['db']['password'],
    $_cfg['db']['database']
);
$con->set_charset('utf8mb4');

// ── Session ──────────────────────────────────────────────────────────────────

$_sessionOpts = [
    'cookie_lifetime' => 60 * 60 * 24 * 4,
    'cookie_httponly' => true,
    'cookie_secure'   => $_isHttps,
    'cookie_samesite' => 'Strict',
    'use_strict_mode' => true,
];
session_start($_sessionOpts);

if (empty($_SESSION['sId'])) {
    if (isset($_COOKIE['sId']) && preg_match('/^[a-zA-Z0-9\-]{22,128}$/', $_COOKIE['sId'])) {
        session_abort();
        session_id($_COOKIE['sId']);
        session_start($_sessionOpts);
    } else {
        $_SESSION['sId'] = session_id();
        setcookie('sId', $_SESSION['sId'], [
            'expires'  => time() + 60 * 60 * 24 * 4,
            'path'     => '/',
            'httponly' => true,
            'secure'   => $_isHttps,
            'samesite' => 'Strict',
        ]);
    }
}

unset($_configPath, $_cfg, $_isHttps, $_sessionOpts);

// ── CSRF ─────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/csrf.php';

// ── Utility functions ────────────────────────────────────────────────────────

function getUserIpAddr(): string {
    return $_SERVER['HTTP_CLIENT_IP']
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR'];
}

function addAlert(string $type, string $message): void {
    $_SESSION['alerts'][] = [$type, htmlentities($message)];
}

function appendLog(mysqli $con, string $context, string $activity, string $origin = 'web'): bool {
    $stmt = $con->prepare(
        'INSERT INTO wl_log (idUser, context, activity, origin, ipAdress, logTime)
         VALUES (?, ?, ?, ?, INET_ATON(?), CURRENT_TIMESTAMP)'
    );
    $id = $_SESSION['id'] ?? 1;
    $ip = getUserIpAddr();
    $stmt->bind_param('issss', $id, $context, $activity, $origin, $ip);
    $stmt->execute();
    $stmt->close();
    return true;
}

function auth_require(): void {
    if (empty($_SESSION['loggedin'])) {
        header('Location: /energie/login.php');
        exit;
    }
}
