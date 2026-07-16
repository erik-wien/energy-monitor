<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for `php_import_csv()` (see inc/csv_importer.php).
 *
 * This is the PHP-native importer used on shared hosts where proc_open
 * and python3 are unavailable (e.g. world4you). It batches one IN-list
 * SELECT + one multi-row UPSERT per 500 rows, so a 1536-row CSV completes
 * in ~5 round-trips where the naive per-row approach needs ~3072.
 *
 * Skips when `energie_test` is not reachable — CI sets up the DB, local
 * devs opt in via the SETUP_HINT below.
 */
final class CsvImporterTest extends TestCase
{
    private const DEV_INI_ENV = 'ENERGIE_DEV_INI';
    private const DEFAULT_INI = '/opt/homebrew/etc/energie-config-dev.ini';
    private const TEST_DB_ENV = 'ENERGIE_TEST_DB';
    private const DEFAULT_DB  = 'energie_test';

    private PDO $pdo;
    private string $archiv;

    protected function setUp(): void
    {
        $this->pdo    = $this->connectTestDb();
        $this->applySchema();
        $this->truncateAll();
        $this->archiv = $this->makeTmpDir('energie-archiv-');
    }

    protected function tearDown(): void
    {
        if (isset($this->archiv)) {
            foreach (glob($this->archiv . '/*') ?: [] as $f) @unlink($f);
            @rmdir($this->archiv);
        }
        foreach (glob(sys_get_temp_dir() . '/energie-importer-*') ?: [] as $f) {
            @unlink($f);
        }
    }

    public function test_fresh_import_inserts_rows_and_rebuilds_daily_summary(): void
    {
        $this->seedTariff('2026-01-01');
        $csv = $this->writeFixtureCsv(
            "Datum;Zeit von;Zeit bis;Verbrauch (kWh)\n"
          . "01.04.2026;00:00;00:15;0,125\n"
          . "01.04.2026;00:15;00:30;0,130\n"
          . "01.04.2026;00:30;00:45;0,140\n"
        );

        $result = php_import_csv($this->pdo, $csv, $this->archiv);

        $this->assertSame(3, $result['total']);
        $this->assertSame(3, $result['inserted']);
        $this->assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM readings')->fetchColumn());

        $sum = $this->pdo->query(
            "SELECT consumed_kwh FROM daily_summary WHERE day = '2026-04-01'"
        )->fetchColumn();
        $this->assertEqualsWithDelta(0.395, (float) $sum, 0.0001);
    }

    public function test_existing_rows_with_consumption_counted_as_existing(): void
    {
        $this->seedTariff('2026-01-01');
        // Pre-seed one row with real consumption — a prior CSV import covered this slot.
        $this->runDdl(
            "INSERT INTO readings (ts, consumed_kwh, spot_ct, cost_brutto)
             VALUES ('2026-04-01 00:00:00', 0.100, 5.0, 0.01)"
        );

        $csv = $this->writeFixtureCsv(
            "Datum;Zeit von;Zeit bis;Verbrauch (kWh)\n"
          . "01.04.2026;00:00;00:15;0,125\n"   // already has consumption → existing
          . "01.04.2026;00:15;00:30;0,130\n"   // fresh → inserted
        );

        $result = php_import_csv($this->pdo, $csv, $this->archiv);

        $this->assertSame(2, $result['total']);
        $this->assertSame(1, $result['inserted']);

        // UPSERT updates the pre-seeded row's consumed_kwh to the CSV value.
        $updated = $this->pdo->query(
            "SELECT consumed_kwh FROM readings WHERE ts = '2026-04-01 00:00:00'"
        )->fetchColumn();
        $this->assertEqualsWithDelta(0.125, (float) $updated, 0.0001);
    }

    public function test_epex_preseeded_rows_with_no_consumption_count_as_new(): void
    {
        $this->seedTariff('2026-01-01');
        // Simulate EPEX pre-fetch: spot_ct set, consumed_kwh = 0.
        $this->runDdl(
            "INSERT INTO readings (ts, consumed_kwh, spot_ct, cost_brutto)
             VALUES ('2026-04-01 00:00:00', 0.000, 7.5, 0.0)"
        );

        $csv = $this->writeFixtureCsv(
            "Datum;Zeit von;Zeit bis;Verbrauch (kWh)\n"
          . "01.04.2026;00:00;00:15;0,125\n"
        );

        $result = php_import_csv($this->pdo, $csv, $this->archiv);

        // Row existed in readings but had no consumption — importing the CSV
        // adds real consumption for the first time, so it's "new" to the user.
        $this->assertSame(1, $result['total']);
        $this->assertSame(1, $result['inserted']);

        // Spot price from the prior EPEX fetch must be preserved.
        $spot = $this->pdo->query(
            "SELECT spot_ct FROM readings WHERE ts = '2026-04-01 00:00:00'"
        )->fetchColumn();
        $this->assertEqualsWithDelta(7.5, (float) $spot, 0.0001);
    }

    public function test_archives_file_on_success(): void
    {
        $this->seedTariff('2026-01-01');
        $csv = $this->writeFixtureCsv(
            "Datum;Zeit von;Zeit bis;Verbrauch (kWh)\n"
          . "01.04.2026;00:00;00:15;0,125\n"
        );
        $name = basename($csv);

        php_import_csv($this->pdo, $csv, $this->archiv);

        $this->assertFileDoesNotExist($csv);
        $this->assertFileExists($this->archiv . '/' . $name);
    }

    public function test_empty_csv_returns_zero_counts(): void
    {
        $csv    = $this->writeFixtureCsv('');
        $result = php_import_csv($this->pdo, $csv, $this->archiv);
        $this->assertSame(0, $result['total']);
        $this->assertSame(0, $result['inserted']);
    }

    // ── client-loop import (§20): preview counts, candidates, per-day, finalize ──

    public function test_preview_counts_ignoriert_epex_nur_zeilen(): void
    {
        // EPEX-Preseed: consumed_kwh = 0 → gilt als NEU, nicht vorhanden.
        $this->runDdl(
            "INSERT INTO readings (ts, consumed_kwh, spot_ct, cost_brutto)
             VALUES ('2026-07-01 00:00:00', 0, 5.0, 0)"
        );
        $counts = preview_import_counts($this->pdo, ['2026-07-01T00:00:00', '2026-07-01T00:15:00']);
        $this->assertSame(2, $counts['total']);
        $this->assertSame(0, $counts['existing']);
        $this->assertSame(2, $counts['new']);
    }

    public function test_import_day_importiert_nur_den_tag(): void
    {
        $this->seedTariff('2026-01-01');
        $csv = $this->writeFixtureCsv(
            "Datum;Zeit von;Zeit bis;Verbrauch [kWh]\n"
          . "01.07.2026;00:00:00;00:15:00;0,50\n"
          . "02.07.2026;00:00:00;00:15:00;0,90\n"
        );
        $r = php_import_day($this->pdo, $csv, '2026-07-01');
        $this->assertSame(1, $r['total']);
        $this->assertSame(1, $r['inserted']);
        $cnt = (int) $this->pdo->query("SELECT COUNT(*) FROM readings WHERE consumed_kwh > 0")->fetchColumn();
        $this->assertSame(1, $cnt); // nur der 1. Tag
    }

    public function test_import_day_does_not_rebuild_summary_or_archive(): void
    {
        $this->seedTariff('2026-01-01');
        $csv = $this->writeFixtureCsv(
            "Datum;Zeit von;Zeit bis;Verbrauch [kWh]\n01.07.2026;00:00:00;00:15:00;0,50\n"
        );
        php_import_day($this->pdo, $csv, '2026-07-01');
        $this->assertFileExists($csv);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM daily_summary')->fetchColumn());
    }

    public function test_candidates_gruppiert_nach_tag(): void
    {
        $csv = $this->writeFixtureCsv(
            "Datum;Zeit von;Zeit bis;Verbrauch [kWh]\n"
          . "01.07.2026;00:00:00;00:15:00;0,50\n"
          . "01.07.2026;00:15:00;00:30:00;0,40\n"
          . "02.07.2026;00:00:00;00:15:00;0,90\n"
        );
        $cand = import_candidates([$csv]);
        $days = array_column($cand, 'date');
        $this->assertSame(['2026-07-01', '2026-07-02'], $days);
        $this->assertSame(2, $cand[0]['rows']);
    }

    public function test_finalize_rebuilds_summary_and_archives_only_given_days(): void
    {
        $this->seedTariff('2026-01-01');
        $csv = $this->writeFixtureCsv(
            "Datum;Zeit von;Zeit bis;Verbrauch [kWh]\n"
          . "01.07.2026;00:00:00;00:15:00;0,50\n"
          . "02.07.2026;00:00:00;00:15:00;0,90\n"
        );
        $name = basename($csv);
        php_import_day($this->pdo, $csv, '2026-07-01');
        php_import_day($this->pdo, $csv, '2026-07-02');

        $res = php_import_finalize($this->pdo, ['2026-07-01'], [$csv], $this->archiv);
        $this->assertSame(1, $res['days']);
        $this->assertSame([$name], $res['archived']);
        $this->assertFileDoesNotExist($csv);
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM daily_summary')->fetchColumn()
        );
        $this->assertSame(
            '2026-07-01',
            $this->pdo->query('SELECT day FROM daily_summary')->fetchColumn()
        );
    }

    public function test_finalize_berechnet_kosten_nach_spaetem_epex(): void
    {
        $tariff = $this->seedTariff('2026-01-01');
        $csv = $this->writeFixtureCsv("Datum;Zeit von;Zeit bis;Verbrauch [kWh]\n01.07.2026;00:00:00;00:15:00;1,0\n");
        php_import_day($this->pdo, $csv, '2026-07-01'); // spot_ct evtl. 0 → cost 0
        $this->runDdl("UPDATE readings SET spot_ct = 10.0 WHERE DATE(ts) = '2026-07-01'"); // später EPEX
        $res = php_import_finalize($this->pdo, ['2026-07-01'], [$csv], $this->archiv);
        $this->assertSame(1, $res['recomputed']);
        $expected = _csv_calc_cost(1.0, 10.0, $tariff);
        $cost = (float) $this->pdo->query("SELECT cost_brutto FROM readings WHERE DATE(ts)='2026-07-01' LIMIT 1")->fetchColumn();
        $this->assertEqualsWithDelta($expected, $cost, 0.000001);
    }

    public function test_finalize_recompute_ueberspringt_epex_nur_zeilen(): void
    {
        $tariff = $this->seedTariff('2026-01-01');
        $csv = $this->writeFixtureCsv("Datum;Zeit von;Zeit bis;Verbrauch [kWh]\n01.07.2026;00:00:00;00:15:00;1,0\n");
        php_import_day($this->pdo, $csv, '2026-07-01');
        // EPEX-Preseed am selben Tag: consumed_kwh = 0, aber spot_ct gesetzt.
        $this->runDdl(
            "INSERT INTO readings (ts, consumed_kwh, spot_ct, cost_brutto)
             VALUES ('2026-07-01 00:15:00', 0, 10.0, 0)"
        );
        $this->runDdl("UPDATE readings SET spot_ct = 10.0 WHERE ts = '2026-07-01 00:00:00'");

        $res = php_import_finalize($this->pdo, ['2026-07-01'], [$csv], $this->archiv);

        // Nur die Zeile mit echtem Verbrauch wird neu berechnet.
        $this->assertSame(1, $res['recomputed']);

        $expected = _csv_calc_cost(1.0, 10.0, $tariff);
        $realCost = (float) $this->pdo->query(
            "SELECT cost_brutto FROM readings WHERE ts = '2026-07-01 00:00:00'"
        )->fetchColumn();
        $this->assertEqualsWithDelta($expected, $realCost, 0.000001);

        $epexCost = (float) $this->pdo->query(
            "SELECT cost_brutto FROM readings WHERE ts = '2026-07-01 00:15:00'"
        )->fetchColumn();
        $this->assertSame(0.0, $epexCost);
    }

    // ── helpers ───────────────────────────────────────────────────────


    private function connectTestDb(): PDO
    {
        $iniPath = getenv(self::DEV_INI_ENV) ?: self::DEFAULT_INI;
        if (!is_readable($iniPath)) {
            $this->markTestSkipped("dev ini not readable: {$iniPath}");
        }
        $cfg = parse_ini_file($iniPath, true);
        if (!is_array($cfg) || empty($cfg['db'])) {
            $this->markTestSkipped("dev ini has no [db] section: {$iniPath}");
        }
        $db    = $cfg['db'];
        $name  = getenv(self::TEST_DB_ENV) ?: self::DEFAULT_DB;
        $dsn   = isset($db['socket'])
            ? "mysql:unix_socket={$db['socket']};dbname={$name};charset=utf8mb4"
            : "mysql:host=" . ($db['host'] ?? '127.0.0.1')
                . (isset($db['port']) ? ";port={$db['port']}" : '')
                . ";dbname={$name};charset=utf8mb4";
        try {
            return new PDO($dsn, $db['user'], $db['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (PDOException $e) {
            $this->markTestSkipped(
                "energie_test not reachable ({$e->getMessage()}). Setup:\n"
              . "  mysql -u root --socket=/tmp/mysql.sock <<'SQL'\n"
              . "  CREATE DATABASE IF NOT EXISTS {$name} CHARACTER SET utf8mb4;\n"
              . "  GRANT ALL PRIVILEGES ON {$name}.* TO '{$db['user']}'@'localhost';\n"
              . "  FLUSH PRIVILEGES;\n  SQL"
            );
        }
    }

    private function applySchema(): void
    {
        $schema = file_get_contents(__DIR__ . '/../../migrations/001_en_initial_schema.sql');
        foreach (array_filter(array_map('trim', explode(';', $schema))) as $stmt) {
            $this->runDdl($stmt);
        }
    }

    private function truncateAll(): void
    {
        foreach (['readings', 'daily_summary', 'tariff_config'] as $t) {
            $this->runDdl("TRUNCATE TABLE {$t}");
        }
    }

    /** PDO::exec wrapper — one line to keep the call site grep-able. */
    private function runDdl(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    /** @return array Tariff row shape matching _csv_load_tariffs(), for expected-cost assertions. */
    private function seedTariff(string $validFrom): array
    {
        $this->pdo->prepare(
            "INSERT INTO tariff_config
             (valid_from, provider_surcharge_ct, electricity_tax_ct, renewable_tax_ct,
              meter_fee_eur, renewable_fee_eur,
              consumption_tax_rate, vat_rate, yearly_kwh_estimate)
             VALUES (?, 1.5, 1.5, 1.0, 30.0, 10.0, 0.06, 0.20, 3000)"
        )->execute([$validFrom]);

        return [
            'valid_from'            => $validFrom,
            'provider_surcharge_ct' => 1.5,
            'electricity_tax_ct'    => 1.5,
            'renewable_tax_ct'      => 1.0,
            'consumption_tax_rate'  => 0.06,
            'vat_rate'              => 0.20,
            'meter_fee_eur'         => 30.0,
            'renewable_fee_eur'     => 10.0,
            'yearly_kwh_estimate'   => 3000.0,
        ];
    }

    private function writeFixtureCsv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'energie-importer-') . '.csv';
        file_put_contents($path, $body);
        return $path;
    }

    private function makeTmpDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(4));
        mkdir($dir, 0755);
        return $dir;
    }
}
