<?php
declare(strict_types=1);

/**
 * inc/ai_client.php — Haiku-Formulierungs-Client für den Dashboard-„Wetterbericht"
 * (s. docs/superpowers/specs/2026-07-17-wetterbericht-design.md §2/§6).
 * Formuliert NUR aus den von inc/wetter.php gelieferten Fakten; Fehler/Timeout/
 * fehlender Key → null (Aufrufer fällt dann auf en_wetter_template() zurück).
 */

const EN_AI_SYSTEM_PROMPT = 'Du schreibst einen kurzen, freundlichen deutschen '
    . 'Strom-Wetterbericht (2–4 Sätze) NUR aus den gegebenen Fakten — nichts '
    . 'erfinden, nicht rechnen, keine Zahlen ändern.';

/**
 * en_haiku_wetter() — lässt Haiku den übergebenen Fakten-Block in einen kurzen
 * deutschen Wetterbericht-Text formulieren.
 *
 * $cfg = ['url' => string, 'model' => string, 'api_key' => string] — aus
 * energie_load_config()['ai'], analog imagos `cloud`-Block
 * (~/TUEV/imago/data/config/config.json).
 *
 * $http (injectable, für Tests): fn(string $url, array $headers, string $bodyJson): array{status:int, body:string}
 * Default: curl gegen die Anthropic Messages-API (connect 4 s, total 12 s),
 * kein curl_close() vor dem Lesen der Antwort — vermeidet den PHP-8.5-
 * Deprecation-JSON-Leak (s. ~/TUEV/imago/web/inc/ai_http.php).
 *
 * Leerer/fehlender api_key → sofort null ($http wird dann gar nicht erst
 * aufgerufen). Jeder weitere Fehler (Transport, Nicht-200, kaputtes JSON,
 * fehlendes content[0].text) → ebenfalls null, ohne PHP-Warning-Leak.
 */
function en_haiku_wetter(array $fakten, array $cfg, ?callable $http = null): ?string {
    $apiKey = trim((string) ($cfg['api_key'] ?? ''));
    if ($apiKey === '') return null;

    $url   = (string) ($cfg['url']   ?? '');
    $model = (string) ($cfg['model'] ?? '');

    $factsJson = json_encode($fakten, JSON_UNESCAPED_UNICODE);
    if ($factsJson === false) return null;

    $body = json_encode([
        'model'      => $model,
        'max_tokens' => 200,
        'system'     => EN_AI_SYSTEM_PROMPT,
        'messages'   => [
            ['role' => 'user', 'content' => $factsJson],
        ],
    ], JSON_UNESCAPED_UNICODE);
    if ($body === false) return null;

    $headers = [
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
        'content-type: application/json',
    ];

    $http ??= en_ai_http_curl_post(...);

    try {
        $res = $http($url, $headers, $body);
    } catch (\Throwable) {
        return null;
    }

    if (!is_array($res) || (int) ($res['status'] ?? 0) !== 200) return null;

    $data = json_decode((string) ($res['body'] ?? ''), true);
    if (!is_array($data)) return null;

    $text = $data['content'][0]['text'] ?? null;
    return is_string($text) && $text !== '' ? $text : null;
}

/** Default-HTTP: curl-POST gegen die Anthropic Messages-API. */
function en_ai_http_curl_post(string $url, array $headers, string $bodyJson): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $bodyJson,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT        => 12,
    ]);
    $raw    = curl_exec($ch);
    $errno  = curl_errno($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // Kein curl_close(): seit PHP 8.0 No-Op, ab 8.5 deprecated — die Warnung
    // würde sonst in den HTTP-Body geschrieben und das JSON zerstören.
    if ($errno !== 0 || $raw === false) {
        return ['status' => 0, 'body' => ''];
    }
    return ['status' => $status, 'body' => (string) $raw];
}
