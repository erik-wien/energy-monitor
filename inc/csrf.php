<?php
/**
 * inc/csrf.php — CSRF protection helpers.
 * Included by inc/initialize.php; do not include directly.
 */

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(): bool {
    if (empty($_SESSION['csrf_token'])) {
        return false;
    }
    $submitted = $_POST['csrf_token']
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? '';
    return hash_equals($_SESSION['csrf_token'], $submitted);
}

function csrf_input(): string {
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}
