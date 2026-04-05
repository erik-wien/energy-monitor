<?php
/**
 * inc/auth.php — Login, logout, IP rate limiting.
 * Requires: getUserIpAddr(), appendLog() from inc/initialize.php.
 */

define('RATE_LIMIT_FILE',   __DIR__ . '/../data/ratelimit.json');
define('RATE_LIMIT_MAX',    5);
define('RATE_LIMIT_WINDOW', 900);

// ── General-purpose rate limiter ─────────────────────────────────────────────

function rate_limit_check(string $key, int $max = 3, int $window = 900): bool {
    $fp = fopen(RATE_LIMIT_FILE, 'c+');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    $data  = json_decode(stream_get_contents($fp), true) ?? [];
    $now   = time();
    $entry = $data[$key] ?? ['count' => 0, 'since' => $now];
    if ($now - $entry['since'] > $window) {
        $entry = ['count' => 0, 'since' => $now];
    }
    $limited = $entry['count'] >= $max;
    flock($fp, LOCK_UN);
    fclose($fp);
    return $limited;
}

function rate_limit_record(string $key, int $window = 900): void {
    $fp = fopen(RATE_LIMIT_FILE, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $data  = json_decode(stream_get_contents($fp), true) ?? [];
    $now   = time();
    $entry = $data[$key] ?? ['count' => 0, 'since' => $now];
    if ($now - $entry['since'] > $window) {
        $entry = ['count' => 0, 'since' => $now];
    }
    $entry['count']++;
    $data[$key] = $entry;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);
}

function rate_limit_clear(string $key): void {
    $fp = fopen(RATE_LIMIT_FILE, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $data = json_decode(stream_get_contents($fp), true) ?? [];
    unset($data[$key]);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);
}

// ── Login rate limiter ────────────────────────────────────────────────────────

function auth_is_rate_limited(string $ip): bool {
    $fp = fopen(RATE_LIMIT_FILE, 'c+');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    $data  = json_decode(stream_get_contents($fp), true) ?? [];
    $now   = time();
    $entry = $data[$ip] ?? ['count' => 0, 'since' => $now];
    if ($now - $entry['since'] > RATE_LIMIT_WINDOW) {
        $entry = ['count' => 0, 'since' => $now];
    }
    $limited = $entry['count'] >= RATE_LIMIT_MAX;
    flock($fp, LOCK_UN);
    fclose($fp);
    return $limited;
}

function auth_record_failure(string $ip): void {
    $fp = fopen(RATE_LIMIT_FILE, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $data  = json_decode(stream_get_contents($fp), true) ?? [];
    $now   = time();
    $entry = $data[$ip] ?? ['count' => 0, 'since' => $now];
    if ($now - $entry['since'] > RATE_LIMIT_WINDOW) {
        $entry = ['count' => 0, 'since' => $now];
    }
    $entry['count']++;
    $data[$ip] = $entry;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);
}

function auth_clear_failures(string $ip): void {
    $fp = fopen(RATE_LIMIT_FILE, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $data = json_decode(stream_get_contents($fp), true) ?? [];
    unset($data[$ip]);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);
}

// ── Login / logout ────────────────────────────────────────────────────────────

function auth_login(mysqli $con, string $username, string $password): array {
    $ip = getUserIpAddr();

    if (auth_is_rate_limited($ip)) {
        return ['ok' => false, 'error' => 'Zu viele Fehlversuche. Bitte warten Sie 15 Minuten.'];
    }

    $stmt = $con->prepare(
        'SELECT id, username, password, email, activation_code, disabled, invalidLogins, rights
         FROM wl_accounts WHERE username = ?'
    );
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        auth_record_failure($ip);
        return ['ok' => false, 'error' => 'Falscher Benutzername oder Kennwort.'];
    }

    $row = $result->fetch_assoc();
    $stmt->close();

    if ($row['activation_code'] !== 'activated') {
        return ['ok' => false, 'error' => 'Benutzer ist noch nicht aktiviert.'];
    }
    if ((int) $row['disabled'] === 1) {
        return ['ok' => false, 'error' => 'Benutzer ist gesperrt.'];
    }
    if (!password_verify($password, $row['password'])) {
        auth_record_failure($ip);
        $upd = $con->prepare('UPDATE wl_accounts SET invalidLogins = invalidLogins + 1 WHERE username = ?');
        $upd->bind_param('s', $username);
        $upd->execute();
        $upd->close();
        return ['ok' => false, 'error' => 'Falscher Benutzername oder Kennwort.'];
    }

    if (password_needs_rehash($row['password'], PASSWORD_BCRYPT, ['cost' => 13])) {
        $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 13]);
        $upd = $con->prepare('UPDATE wl_accounts SET password = ? WHERE id = ?');
        $upd->bind_param('si', $newHash, $row['id']);
        $upd->execute();
        $upd->close();
    }

    auth_clear_failures($ip);
    session_regenerate_id(true);
    $sId = session_id();
    setcookie('sId', $sId, [
        'expires'  => time() + 60 * 60 * 24 * 4,
        'path'     => '/',
        'httponly' => true,
        'secure'   => true,
        'samesite' => 'Strict',
    ]);

    $_SESSION['sId']      = $sId;
    $_SESSION['loggedin'] = true;
    $_SESSION['id']       = (int) $row['id'];
    $_SESSION['username'] = $row['username'];
    $_SESSION['email']    = $row['email'];
    $_SESSION['rights']   = $row['rights'];

    $upd = $con->prepare('UPDATE wl_accounts SET lastLogin = NOW(), invalidLogins = 0 WHERE id = ?');
    $upd->bind_param('i', $row['id']);
    $upd->execute();
    $upd->close();

    appendLog($con, 'auth', $row['username'] . ' logged in (energie).', 'web');

    return ['ok' => true, 'username' => $row['username']];
}

function auth_logout(mysqli $con): void {
    if (!empty($_SESSION['username'])) {
        appendLog($con, 'log', $_SESSION['username'] . ' logged out (energie).', 'web');
    }
    session_destroy();
    setcookie('sId', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
        'secure'   => true,
        'samesite' => 'Strict',
    ]);
}
