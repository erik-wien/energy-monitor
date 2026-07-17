<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../../inc/ai_client.php';
require_once __DIR__ . '/../../inc/wetter.php';

/**
 * Tests for inc/ai_client.php — Haiku-Analyst-Client des Dashboard-
 * „Wetterberichts" (s. docs/superpowers/specs/2026-07-17-wetterbericht-v2-analyst.md
 * §3/§6). Rein — kein echter HTTP-Call, $http wird immer gemockt.
 */
final class AiClientTest extends TestCase {
    private const CFG = [
        'url'     => 'https://api.anthropic.com/v1/messages',
        'model'   => 'claude-haiku-4-5-20251001',
        'api_key' => 'sk-ant-test-key',
    ];

    // v2-Fakten-Fixture (Struktur wie en_wetter_fakten() liefert, Spec §2).
    private const FAKTEN = [
        'stand' => ['gestern' => '2026-07-16', 'heute' => '2026-07-17', 'morgen' => null, 'aktuell' => true],
        'verbrauch' => [
            'gestern_kwh' => 8.5, 'w7_kwh' => 62.0, 'w7_vorjahr_kwh' => 71.0, 'w7_yoy_pct' => -0.1267,
            'd30_kwh' => 250.0, 'd30_ueblich_kwh' => 260.0, 'd30_delta_pct' => -0.0385, 'trend' => 'stabil',
        ],
        'disziplin' => [
            'laeufe' => [['stunde' => 19, 'kwh' => 2.1, 'spot_ct' => 8.2, 'lage' => 'guenstig']],
            'bewertung' => 'gut',
        ],
        'preis' => [
            'heute' => ['avg' => 14.5, 'max' => 19.0, 'max_h' => 19, 'min' => 8.0, 'min_h' => 3,
                        'spitzen' => [19], 'guenstig_von' => 12, 'guenstig_bis' => 15, 'guenstig_avg' => 10.1],
            'morgen' => null,
            'heute_vs_ueblich_pct' => -0.05, 'heute_vs_vorjahr_pct' => null,
            'symbol' => 'wolke',
        ],
        'auffaellig' => ['Verbrauch letzte 7 Tage 12,7 % unter Vorjahr.'],
        'vorschau' => false,
    ];

    public function test_200_mit_gueltigem_anthropic_json_liefert_text(): void {
        $http = function (string $url, array $headers, string $bodyJson): array {
            return [
                'status' => 200,
                'body'   => json_encode(['content' => [['type' => 'text', 'text' => 'Ein ruhiger Stromtag.']]]),
            ];
        };

        $text = en_haiku_wetter(self::FAKTEN, self::CFG, $http);

        $this->assertSame('Ein ruhiger Stromtag.', $text);
    }

    public function test_hinweis_wird_deterministisch_angehaengt_wenn_nicht_aktuell(): void {
        // Haiku lässt den „gestrige Werte fehlen"-Hinweis gern weg; bei
        // stand.aktuell=false muss er trotzdem im Text landen.
        $fakten = self::FAKTEN;
        $fakten['stand']['aktuell'] = false;
        $http = fn(string $url, array $headers, string $bodyJson): array => [
            'status' => 200,
            'body'   => json_encode(['content' => [['type' => 'text', 'text' => 'Heute wird der Strom teuer.']]]),
        ];

        $text = en_haiku_wetter($fakten, self::CFG, $http);

        $this->assertStringContainsString('bitte die gestrigen Werte laden', $text);
    }

    public function test_hinweis_nicht_doppelt_wenn_modell_ihn_schon_nennt(): void {
        $fakten = self::FAKTEN;
        $fakten['stand']['aktuell'] = false;
        $http = fn(string $url, array $headers, string $bodyJson): array => [
            'status' => 200,
            'body'   => json_encode(['content' => [['type' => 'text', 'text' => 'Bitte die gestrigen Werte laden.']]]),
        ];

        $text = en_haiku_wetter($fakten, self::CFG, $http);

        $this->assertSame(1, substr_count(mb_strtolower($text), 'laden'));
    }

    public function test_http_500_liefert_null(): void {
        $http = fn(string $url, array $headers, string $bodyJson): array
            => ['status' => 500, 'body' => 'Internal Server Error'];

        $this->assertNull(en_haiku_wetter(self::FAKTEN, self::CFG, $http));
    }

    public function test_http_wirft_exception_liefert_null(): void {
        $http = function (string $url, array $headers, string $bodyJson): array {
            throw new RuntimeException('timeout');
        };

        $this->assertNull(en_haiku_wetter(self::FAKTEN, self::CFG, $http));
    }

    public function test_leerer_api_key_liefert_null_ohne_http_aufruf(): void {
        $calls = 0;
        $http  = function (string $url, array $headers, string $bodyJson) use (&$calls): array {
            $calls++;
            return ['status' => 200, 'body' => '{}'];
        };

        $cfg = self::CFG;
        $cfg['api_key'] = '';

        $this->assertNull(en_haiku_wetter(self::FAKTEN, $cfg, $http));
        $this->assertSame(0, $calls, '$http darf bei leerem api_key nicht aufgerufen werden');
    }

    public function test_fehlender_api_key_liefert_null_ohne_http_aufruf(): void {
        $calls = 0;
        $http  = function (string $url, array $headers, string $bodyJson) use (&$calls): array {
            $calls++;
            return ['status' => 200, 'body' => '{}'];
        };

        $cfg = self::CFG;
        unset($cfg['api_key']);

        $this->assertNull(en_haiku_wetter(self::FAKTEN, $cfg, $http));
        $this->assertSame(0, $calls);
    }

    public function test_unparsable_body_liefert_null(): void {
        $http = fn(string $url, array $headers, string $bodyJson): array
            => ['status' => 200, 'body' => 'not json at all {'];

        $this->assertNull(en_haiku_wetter(self::FAKTEN, self::CFG, $http));
    }

    public function test_request_form_enthaelt_headers_model_fakten_und_system_prompt(): void {
        $captured = null;
        $http = function (string $url, array $headers, string $bodyJson) use (&$captured): array {
            $captured = ['url' => $url, 'headers' => $headers, 'body' => $bodyJson];
            return ['status' => 200, 'body' => json_encode(['content' => [['type' => 'text', 'text' => 'x']]])];
        };

        en_haiku_wetter(self::FAKTEN, self::CFG, $http);

        $this->assertNotNull($captured);
        $this->assertSame(self::CFG['url'], $captured['url']);

        $headerBlob = implode("\n", $captured['headers']);
        $this->assertStringContainsString('x-api-key: ' . self::CFG['api_key'], $headerBlob);
        $this->assertStringContainsString('anthropic-version: 2023-06-01', $headerBlob);

        $decoded = json_decode($captured['body'], true);
        $this->assertIsArray($decoded);
        $this->assertSame('claude-haiku-4-5-20251001', $decoded['model']);
        $this->assertSame(320, $decoded['max_tokens']);
        $this->assertStringContainsString('nüchterner Strom-Analyst', $decoded['system']);

        // User-Content = das beschriftete Kennzahlen-Blatt aus
        // en_wetter_faktenblatt($fakten) — NIE rohes JSON.
        $userContent = $decoded['messages'][0]['content'];
        $this->assertIsString($userContent);
        $this->assertSame(en_wetter_faktenblatt(self::FAKTEN), $userContent);
    }

    public function test_en_ai_saeubern_entfernt_markdown_ueberschrift_und_emoji(): void {
        $roh = "# Wetterbericht\nHeute wird's günstig ☀️ und sonnig!";

        $bereinigt = en_ai_saeubern($roh);

        $this->assertStringNotContainsString('#', $bereinigt);
        $this->assertStringNotContainsString('☀️', $bereinigt);
        $this->assertStringNotContainsString('☀', $bereinigt);
        $this->assertStringContainsString("Heute wird's günstig", $bereinigt);
        $this->assertStringContainsString('und sonnig!', $bereinigt);
    }
}
