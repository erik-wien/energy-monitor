<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../../inc/insight.php';

final class InsightTest extends TestCase {
    public function test_delta_hoch_runter_flat(): void {
        $this->assertSame('up',   en_delta(110, 100)['dir']);
        $this->assertSame('down', en_delta(90, 100)['dir']);
        $this->assertSame('flat', en_delta(100, 100)['dir']);
        $this->assertEqualsWithDelta(10.0, en_delta(110, 100)['pct'], 0.001);
    }
    public function test_delta_ohne_basis_null(): void {
        $this->assertNull(en_delta(50, 0)['pct']);
        $this->assertSame('flat', en_delta(50, 0)['dir']);
    }
    public function test_sparkline_leer_bei_zu_wenig(): void {
        $this->assertSame('', en_sparkline_svg([]));
        $this->assertSame('', en_sparkline_svg([5.0]));
    }
    public function test_sparkline_polyline_und_normierung(): void {
        $svg = en_sparkline_svg([1.0, 2.0, 3.0]);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('<polyline', $svg);
        // erster x=0, letzter x=w; Min normiert nach unten (y≈h), Max nach oben (y≈0)
        $this->assertMatchesRegularExpression('/points="0,\d/', $svg);
        $this->assertStringContainsString('64,', $svg);
    }
    public function test_sparkline_flach_ohne_div0(): void {
        $svg = en_sparkline_svg([7.0, 7.0, 7.0]); // max==min
        $this->assertStringContainsString('<polyline', $svg); // kein Warning/Fehler
    }

    // ── en_preis_komposition / en_effektiv_serie (DB-gestützt) ──────────────────

    private const DEV_INI_ENV = 'ENERGIE_DEV_INI';
    private const DEFAULT_INI = '/opt/homebrew/etc/energie-config-dev.ini';
    private const TEST_DB_ENV = 'ENERGIE_TEST_DB';
    private const DEFAULT_DB  = 'energie_test';

    private ?PDO $pdo = null;

    protected function setUp(): void {
        $this->pdo = $this->connectTestDb();
        $this->applySchema();
        $this->truncateAll();
    }

    public function test_komposition_segmente_summieren_auf_brutto(): void {
        $this->seedTariff('2026-01-01');
        $this->seedTag('2026-06-01', 10.0, 5.0);
        $this->seedTag('2026-06-02', 12.0, 6.0);

        $komp = en_preis_komposition($this->pdo, '2026-06-01', '2026-06-02');
        $segSum = $komp['spot'] + $komp['aufschlag'] + $komp['abgaben']
                + $komp['gba'] + $komp['mwst'] + $komp['fixkosten'];
        $this->assertEqualsWithDelta($komp['brutto'], $segSum, 0.05);
    }

    public function test_komposition_netto_unter_brutto_spot_unter_netto(): void {
        $this->seedTariff('2026-01-01');
        $this->seedTag('2026-06-01', 10.0, 5.0);
        $this->seedTag('2026-06-02', 12.0, 6.0);

        $komp = en_preis_komposition($this->pdo, '2026-06-01', '2026-06-02');
        $this->assertLessThan($komp['brutto'], $komp['netto']);
        $this->assertLessThan($komp['netto'], $komp['spot']);
    }

    public function test_komposition_leere_periode_alles_null(): void {
        $komp = en_preis_komposition($this->pdo, '2026-06-01', '2026-06-02');
        foreach (['spot', 'aufschlag', 'abgaben', 'gba', 'mwst', 'fixkosten', 'brutto', 'netto', 'kwh', 'eur'] as $k) {
            $this->assertSame(0.0, $komp[$k]);
        }
    }

    public function test_effektiv_serie_tagesreihenfolge_und_ueberspringt_leer(): void {
        $this->seedTariff('2026-01-01');
        $this->seedTag('2026-06-01', 10.0, 5.0);   // effektiv = 13.02 ct/kWh (siehe seedTag)
        $this->seedTagLeer('2026-06-02');
        $this->seedTag('2026-06-03', 12.0, 6.0);   // effektiv = 14.28 ct/kWh

        $serie = en_effektiv_serie($this->pdo, '2026-06-01', '2026-06-03');
        $this->assertCount(2, $serie);
        $this->assertEqualsWithDelta(13.02, $serie[0], 0.01);
        $this->assertEqualsWithDelta(14.28, $serie[1], 0.01);
    }

    public function test_effektiv_serie_leere_periode_leeres_array(): void {
        $this->assertSame([], en_effektiv_serie($this->pdo, '2026-06-01', '2026-06-02'));
    }

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

    private function seedTariff(string $validFrom): void {
        $this->pdo->prepare(
            "INSERT INTO tariff_config
             (valid_from, provider_surcharge_ct, electricity_tax_ct, renewable_tax_ct,
              meter_fee_eur, renewable_fee_eur,
              consumption_tax_rate, vat_rate, yearly_kwh_estimate)
             VALUES (?, 1.5, 1.5, 1.0, 30.0, 10.0, 0.06, 0.20, 3000)"
        )->execute([$validFrom]);
    }

    /**
     * Seed one daily_summary row with cost_brutto berechnet nach der echten
     * _csv_calc_cost()-Formel (inc/csv_importer.php), damit die Zerlegung reale
     * Rechnungslogik spiegelt. Tariff-Werte hier fix, müssen zu seedTariff() passen.
     */
    private function seedTag(string $day, float $kwh, float $spotCt): void {
        $mfee = 30.0; $rfee = 10.0; $ykwh = 3000.0;
        $psc = 1.5; $etc = 1.5; $rtc = 1.0; $gbr = 0.06; $vatr = 0.20;

        $annualCt = ($mfee + $rfee) / $ykwh * 100;
        $netCt    = $spotCt + $psc + $etc + $rtc + $annualCt;
        $grossCt  = $netCt * (1 + $vatr + $gbr);
        $cost     = $kwh * $grossCt / 100;

        $this->pdo->prepare(
            "INSERT INTO daily_summary (day, consumed_kwh, cost_brutto, avg_spot_ct) VALUES (?, ?, ?, ?)"
        )->execute([$day, $kwh, $cost, $spotCt]);
    }

    /** Seed a zero-consumption day (e.g. no import yet) — must be skipped by en_effektiv_serie. */
    private function seedTagLeer(string $day): void {
        $this->pdo->prepare(
            "INSERT INTO daily_summary (day, consumed_kwh, cost_brutto, avg_spot_ct) VALUES (?, 0, 0, 0)"
        )->execute([$day]);
    }
}
