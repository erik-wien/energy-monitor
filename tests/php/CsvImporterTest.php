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
        foreach (glob($this->archiv . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->archiv);
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

    private function seedTariff(string $validFrom): void
    {
        $this->pdo->prepare(
            "INSERT INTO tariff_config
             (valid_from, provider_surcharge_ct, electricity_tax_ct, renewable_tax_ct,
              meter_fee_eur, renewable_fee_eur,
              consumption_tax_rate, vat_rate, yearly_kwh_estimate)
             VALUES (?, 1.5, 1.5, 1.0, 30.0, 10.0, 0.06, 0.20, 3000)"
        )->execute([$validFrom]);
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
