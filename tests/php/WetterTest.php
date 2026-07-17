<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../../inc/wetter.php';

/**
 * Tests for inc/wetter.php — Fakten-Engine + Template des Dashboard-„Wetterberichts"
 * (s. docs/superpowers/specs/2026-07-17-wetterbericht-design.md §1/§7).
 *
 * DB-gestützte Tests (Fixtures in daily_summary/readings/tariff_config) skippen
 * ohne erreichbare energie_test-DB, s. ENERGIE_DEV_INI/ENERGIE_TEST_DB.
 */
final class WetterTest extends TestCase {
    private const DEV_INI_ENV = 'ENERGIE_DEV_INI';
    private const DEFAULT_INI = '/opt/homebrew/etc/energie-config-dev.ini';
    private const TEST_DB_ENV = 'ENERGIE_TEST_DB';
    private const DEFAULT_DB  = 'energie_test';

    private ?PDO $pdo = null;

    private ?string $tmpCachePath = null;

    protected function setUp(): void {
        $this->pdo = $this->connectTestDb();
        $this->applySchema();
        $this->truncateAll();
    }

    protected function tearDown(): void {
        if ($this->tmpCachePath !== null) {
            $dir = dirname($this->tmpCachePath);
            @unlink($this->tmpCachePath);
            foreach (glob($dir . '/*.tmp*') ?: [] as $leftover) {
                @unlink($leftover);
            }
            @rmdir($dir);
        }
    }

    // ── en_wetter_verbrauch / en_wetter_fakten ──────────────────────────────

    public function test_verbrauch_30_tage_delta_korrekt(): void {
        // ist-Fenster: Tage > 2026-06-17 und <= 2026-07-17 (letzte 30 T).
        $this->seedTag('2026-07-01', 10.0, 12.0);
        $this->seedTag('2026-07-02', 10.0, 12.0);
        // basis-Fenster: Tage > 2026-03-19 und <= 2026-06-17 (90 T davor).
        $this->seedTag('2026-05-01', 5.0, 12.0);
        $this->seedTag('2026-05-02', 5.0, 12.0);

        $fakten = en_wetter_verbrauch($this->pdo, '2026-07-17');

        $this->assertEqualsWithDelta(20.0, $fakten['ist_kwh'], 0.001);
        $this->assertEqualsWithDelta(150.0, $fakten['basis_kwh'], 0.001); // AVG(5,5)*30
        $this->assertEqualsWithDelta(20.0 / 150.0 - 1.0, $fakten['delta_pct'], 0.001);
    }

    public function test_verbrauch_ohne_basis_delta_null(): void {
        $this->seedTag('2026-07-01', 10.0, 12.0);

        $fakten = en_wetter_verbrauch($this->pdo, '2026-07-17');

        $this->assertEqualsWithDelta(10.0, $fakten['ist_kwh'], 0.001);
        $this->assertSame(0.0, $fakten['basis_kwh']);
        $this->assertNull($fakten['delta_pct']);
    }

    // ── en_wetter_bewertung (Grenzwerte) ─────────────────────────────────────

    public function test_bewertung_grenzen(): void {
        $this->assertSame('neutral',    en_wetter_bewertung(-0.02));
        $this->assertSame('gut',        en_wetter_bewertung(-0.0201));
        $this->assertSame('neutral',    en_wetter_bewertung(0.02));
        $this->assertSame('unguenstig', en_wetter_bewertung(0.0201));
        $this->assertSame('neutral',    en_wetter_bewertung(0.0));
    }

    public function test_disziplin_gewichtet_vs_einfach_aus_db(): void {
        // Zwei Readings am selben Tag im 30-T-Fenster, unterschiedlich gewichtet:
        // viel Verbrauch bei niedrigem Spotpreis → gewichteter Preis < einfacher Ø.
        $this->seedReading('2026-07-10 12:00:00', 10.0, 10.0); // günstige Stunde, viel Verbrauch
        $this->seedReading('2026-07-10 19:00:00', 1.0, 20.0);  // teure Stunde, wenig Verbrauch

        $fakten = en_wetter_disziplin($this->pdo, '2026-07-17');

        $gewErwartet = (10.0 * 10.0 + 1.0 * 20.0) / (10.0 + 1.0); // ≈10.909
        $einfachErwartet = (10.0 + 20.0) / 2; // 15.0
        $this->assertEqualsWithDelta($gewErwartet, $fakten['gew'], 0.001);
        $this->assertEqualsWithDelta($einfachErwartet, $fakten['einfach'], 0.001);
        $this->assertEqualsWithDelta($gewErwartet / $einfachErwartet - 1.0, $fakten['gap_pct'], 0.001);
        $this->assertSame('gut', $fakten['bewertung']);
    }

    public function test_disziplin_ohne_readings_null_neutral(): void {
        $fakten = en_wetter_disziplin($this->pdo, '2026-07-17');

        $this->assertNull($fakten['gew']);
        $this->assertNull($fakten['einfach']);
        $this->assertNull($fakten['gap_pct']);
        $this->assertSame('neutral', $fakten['bewertung']);
    }

    // ── en_wetter_heute ──────────────────────────────────────────────────────

    public function test_heute_guenstigstes_3h_fenster_ist_fenster_mit_min_avg(): void {
        // Stunden 10..14 mit V-förmigem Profil; das Minimum-Ø-Fenster liegt
        // NICHT am Rand, sondern bei 11-13 (avg ≈ 8,67), nicht 10-12 oder 12-14.
        $this->seedHourReading('2026-07-17', 10, 20.0);
        $this->seedHourReading('2026-07-17', 11, 15.0);
        $this->seedHourReading('2026-07-17', 12, 5.0);
        $this->seedHourReading('2026-07-17', 13, 6.0);
        $this->seedHourReading('2026-07-17', 14, 20.0);

        $heute = en_wetter_heute($this->pdo, '2026-07-17');

        $this->assertSame(11, $heute['guenstig_von']);
        $this->assertSame(14, $heute['guenstig_bis']);
        $this->assertEqualsWithDelta((15.0 + 5.0 + 6.0) / 3.0, $heute['guenstig_avg'], 0.001);

        $this->assertEqualsWithDelta((20.0 + 15.0 + 5.0 + 6.0 + 20.0) / 5.0, $heute['avg'], 0.001);
        $this->assertEqualsWithDelta(20.0, $heute['max'], 0.001);
        $this->assertSame(10, $heute['max_h']); // erster Treffer bei Gleichstand (10 und 14 je 20,0)
        $this->assertEqualsWithDelta(5.0, $heute['min'], 0.001);
        $this->assertSame(12, $heute['min_h']);
        $this->assertSame([10, 14], $heute['spitzen']); // > avg*1.25 = 16.5
    }

    public function test_heute_leerer_tag_alle_felder_null(): void {
        // Readings existieren, aber nicht am latest-Tag.
        $this->seedHourReading('2026-07-16', 12, 10.0);

        $heute = en_wetter_heute($this->pdo, '2026-07-17');

        $this->assertSame('2026-07-17', $heute['datum']);
        $this->assertNull($heute['avg']);
        $this->assertNull($heute['max']);
        $this->assertNull($heute['max_h']);
        $this->assertNull($heute['min']);
        $this->assertNull($heute['min_h']);
        $this->assertSame([], $heute['spitzen']);
        $this->assertNull($heute['guenstig_von']);
        $this->assertNull($heute['guenstig_bis']);
        $this->assertNull($heute['guenstig_avg']);
    }

    public function test_wetter_fakten_buendelt_alle_drei_bloecke(): void {
        $this->seedTag('2026-07-01', 10.0, 12.0);
        $this->seedHourReading('2026-07-17', 12, 10.0);

        $fakten = en_wetter_fakten($this->pdo, '2026-07-17');

        $this->assertArrayHasKey('verbrauch', $fakten);
        $this->assertArrayHasKey('disziplin', $fakten);
        $this->assertArrayHasKey('heute', $fakten);
        $this->assertSame('2026-07-17', $fakten['heute']['datum']);
    }

    // ── en_wetter_lesen / en_wetter_regenerieren (Cache + Off-Path, Spec §3) ──
    //
    // $pdo/$cachePath sind bei beiden Funktionen injizierbar (Default: globales
    // $pdo bzw. der echte data/wetterbericht.json-Pfad) — hier immer ein
    // frischer Temp-Pfad, damit kein echter Cache berührt wird.

    private function tmpCachePfad(): string {
        $dir = sys_get_temp_dir() . '/wetter_test_' . uniqid('', true);
        $this->tmpCachePath = $dir . '/wetterbericht.json';
        return $this->tmpCachePath;
    }

    public function test_lesen_ohne_cache_liefert_frisches_template(): void {
        $path = $this->tmpCachePfad(); // Datei existiert bewusst nicht.
        $this->seedTag('2026-07-01', 10.0, 12.0);

        $result = en_wetter_lesen($this->pdo, $path);

        $this->assertSame('template', $result['quelle']);
        $this->assertArrayHasKey('verbrauch', $result['fakten']);
        $this->assertArrayHasKey('disziplin', $result['fakten']);
        $this->assertArrayHasKey('heute', $result['fakten']);
        $this->assertNotSame('', $result['text']);
        $this->assertIsString($result['datum']);
        $this->assertIsString($result['erzeugt_at']);
    }

    public function test_lesen_mit_vorhandenem_cache_liefert_ihn_unveraendert(): void {
        $path = $this->tmpCachePfad();
        mkdir(dirname($path), 0755, true);
        $vorhanden = [
            'datum'      => '2026-07-16',
            'fakten'     => ['verbrauch' => ['ist_kwh' => 1.0, 'basis_kwh' => 2.0, 'delta_pct' => -0.5]],
            'text'       => 'Gestriger Bericht.',
            'quelle'     => 'haiku',
            'erzeugt_at' => '2026-07-16T08:00:00+00:00',
        ];
        file_put_contents($path, json_encode($vorhanden, JSON_UNESCAPED_UNICODE));

        // $pdo wird nicht gebraucht, wenn der Cache existiert — trotzdem
        // übergeben, um zu zeigen, dass er dann ignoriert wird.
        $result = en_wetter_lesen($this->pdo, $path);

        $this->assertEquals($vorhanden, $result);
    }

    public function test_regenerieren_schreibt_gueltigen_cache_atomar(): void {
        $path = $this->tmpCachePfad(); // Verzeichnis existiert bewusst noch nicht.
        $this->seedTag('2026-07-01', 10.0, 12.0);
        $this->seedHourReading('2026-07-01', 12, 10.0);

        // Leere ai-Config → en_haiku_wetter liefert sofort null → Template-Fallback.
        $result = en_wetter_regenerieren($this->pdo, [], $path);

        $this->assertFileExists($path);
        $onDisk = json_decode(file_get_contents($path), true);
        $this->assertEquals($result, $onDisk);
        $this->assertSame('template', $onDisk['quelle']);
        $this->assertNotSame('', $onDisk['text']);
        $this->assertArrayHasKey('disziplin', $onDisk['fakten']);
        $this->assertSame('2026-07-01', $onDisk['datum']);

        // Atomarer Write (tmp-Datei + rename): keine liegen gebliebene tmp-Datei.
        $this->assertSame([], glob(dirname($path) . '/*.tmp*') ?: []);
    }

    public function test_regenerieren_robust_bei_leeren_daten(): void {
        $path = $this->tmpCachePfad(); // Keine Readings/daily_summary vorhanden.

        $result = en_wetter_regenerieren($this->pdo, [], $path);

        $this->assertFileExists($path);
        $this->assertSame('template', $result['quelle']);
        $this->assertNotSame('', $result['text']);
        $this->assertNull($result['fakten']['disziplin']['gap_pct']);
    }

    // ── en_wetter_template (reine Funktion, keine DB) ────────────────────────

    public function test_template_enthaelt_kernzahlen(): void {
        $fakten = [
            'verbrauch' => ['ist_kwh' => 190.0, 'basis_kwh' => 276.0, 'delta_pct' => -0.311],
            'disziplin' => ['gew' => 9.7, 'einfach' => 11.8, 'gap_pct' => -0.1805, 'bewertung' => 'gut'],
            'heute'     => [
                'datum' => '2026-07-17', 'avg' => 14.5, 'max' => 18.0, 'max_h' => 19,
                'min' => 9.8, 'min_h' => 13, 'spitzen' => [],
                'guenstig_von' => 12, 'guenstig_bis' => 15, 'guenstig_avg' => 10.1,
            ],
        ];

        $text = en_wetter_template($fakten);

        $this->assertNotSame('', $text);
        $this->assertStringContainsString('31 %', $text);              // Verbrauchs-Delta
        $this->assertStringContainsString('zwischen 12 und 15', $text); // günstig-Fenster
    }

    public function test_template_ueberspringt_null_felder(): void {
        $fakten = [
            'verbrauch' => ['ist_kwh' => 0.0, 'basis_kwh' => 0.0, 'delta_pct' => null],
            'disziplin' => ['gew' => null, 'einfach' => null, 'gap_pct' => null, 'bewertung' => 'neutral'],
            'heute'     => [
                'datum' => '2026-07-17', 'avg' => null, 'max' => null, 'max_h' => null,
                'min' => null, 'min_h' => null, 'spitzen' => [],
                'guenstig_von' => null, 'guenstig_bis' => null, 'guenstig_avg' => null,
            ],
        ];

        $text = en_wetter_template($fakten);

        $this->assertNotSame('', $text); // Neutraltext, kein leerer String
        $this->assertStringNotContainsString('%', $text);
    }

    public function test_template_leere_fakten_neutraltext(): void {
        $text = en_wetter_template([]);

        $this->assertIsString($text);
        $this->assertNotSame('', $text);
    }

    // ── DB-Scaffolding (Muster wie InsightTest/CsvImporterTest) ─────────────

    private function connectTestDb(): PDO {
        $iniPath = getenv(self::DEV_INI_ENV) ?: self::DEFAULT_INI;
        if (!is_readable($iniPath)) {
            $this->markTestSkipped("dev ini not readable: {$iniPath}");
        }
        $cfg = parse_ini_file($iniPath, true);
        if (!is_array($cfg) || empty($cfg['db'])) {
            $this->markTestSkipped("dev ini has no [db] section: {$iniPath}");
        }
        $db   = $cfg['db'];
        $name = getenv(self::TEST_DB_ENV) ?: self::DEFAULT_DB;
        $dsn  = isset($db['socket'])
            ? "mysql:unix_socket={$db['socket']};dbname={$name};charset=utf8mb4"
            : "mysql:host=" . ($db['host'] ?? '127.0.0.1')
                . (isset($db['port']) ? ";port={$db['port']}" : '')
                . ";dbname={$name};charset=utf8mb4";
        try {
            return new PDO($dsn, $db['user'], $db['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (PDOException $e) {
            $this->markTestSkipped("energie_test not reachable ({$e->getMessage()}).");
        }
    }

    private function applySchema(): void {
        $schema = file_get_contents(__DIR__ . '/../../migrations/001_en_initial_schema.sql');
        foreach (array_filter(array_map('trim', explode(';', $schema))) as $stmt) {
            $this->pdo->exec($stmt);
        }
    }

    private function truncateAll(): void {
        foreach (['readings', 'daily_summary', 'tariff_config'] as $t) {
            $this->pdo->exec("TRUNCATE TABLE {$t}");
        }
    }

    /** Seed one daily_summary row (avg_spot_ct/cost_brutto are irrelevant to wetter.php, kept plausible). */
    private function seedTag(string $day, float $kwh, float $spotCt): void {
        $this->pdo->prepare(
            "INSERT INTO daily_summary (day, consumed_kwh, cost_brutto, avg_spot_ct) VALUES (?, ?, ?, ?)"
        )->execute([$day, $kwh, $kwh * $spotCt / 100, $spotCt]);
    }

    /** Seed one readings row at an exact timestamp (cost_brutto is irrelevant to wetter.php). */
    private function seedReading(string $ts, float $kwh, float $spotCt): void {
        $this->pdo->prepare(
            "INSERT INTO readings (ts, consumed_kwh, spot_ct, cost_brutto) VALUES (?, ?, ?, ?)"
        )->execute([$ts, $kwh, $spotCt, $kwh * $spotCt / 100]);
    }

    /** Seed one readings row for a given day+hour (fixed :00 minute, 1 kWh). */
    private function seedHourReading(string $day, int $hour, float $spotCt): void {
        $this->seedReading(sprintf('%s %02d:00:00', $day, $hour), 1.0, $spotCt);
    }
}
