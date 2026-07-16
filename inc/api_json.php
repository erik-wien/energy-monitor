<?php
declare(strict_types=1);

/** Sendet valides JSON + HTTP-Status und beendet. Verhindert Warning-Leaks im Body. */
function api_json_send(array $payload, int $status = 200): void {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Freier Dateiname in $dir: haengt _1, _2, … vor der Endung an, wenn $name existiert. */
function naechster_freier_name(string $dir, string $name): string {
    if (!file_exists($dir . '/' . $name)) return $name;
    $ext  = pathinfo($name, PATHINFO_EXTENSION);
    $base = pathinfo($name, PATHINFO_FILENAME);
    $suf  = $ext !== '' ? '.' . $ext : '';
    for ($i = 1; $i < 10000; $i++) {
        $cand = $base . '_' . $i . $suf;
        if (!file_exists($dir . '/' . $cand)) return $cand;
    }
    return $base . '_' . uniqid() . $suf;
}
