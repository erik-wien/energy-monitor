<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../../inc/ai_client.php';

/**
 * Tests for inc/ai_client.php — Haiku-Formulierungs-Client des Dashboard-
 * „Wetterberichts" (s. docs/superpowers/specs/2026-07-17-wetterbericht-design.md
 * §2/§6). Rein — kein echter HTTP-Call, $http wird immer gemockt.
 */
final class AiClientTest extends TestCase {
    private const CFG = [
        'url'     => 'https://api.anthropic.com/v1/messages',
        'model'   => 'claude-haiku-4-5-20251001',
        'api_key' => 'sk-ant-test-key',
    ];

    private const FAKTEN = [
        'verbrauch' => ['ist_kwh' => 100.0, 'basis_kwh' => 120.0, 'delta_pct' => -0.1667],
        'disziplin' => ['gew' => 10.0, 'einfach' => 12.0, 'gap_pct' => -0.1667, 'bewertung' => 'gut'],
        'heute'     => ['datum' => '2026-07-17', 'avg' => 14.5, 'max' => 19.0, 'max_h' => 19,
                         'min' => 8.0, 'min_h' => 3, 'spitzen' => [19], 'guenstig_von' => 12,
                         'guenstig_bis' => 15, 'guenstig_avg' => 10.1],
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
        $this->assertSame(200, $decoded['max_tokens']);
        $this->assertStringContainsString('nichts erfinden', $decoded['system']);

        // User-Content = json_encode($fakten) als String
        $userContent = $decoded['messages'][0]['content'];
        $this->assertIsString($userContent);
        // assertEquals (nicht assertSame): der JSON-Roundtrip macht aus
        // ganzzahligen Floats (100.0) wieder int (100) — Werte bleiben gleich.
        $faktenDecoded = json_decode($userContent, true);
        $this->assertEquals(self::FAKTEN, $faktenDecoded);
    }
}
