<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for `_parse_energie_csv_timestamps()` (see inc/csv_parser.php).
 *
 * This is the preview-side parser the web UI uses to count "total / existing
 * / new" before handing off to the Python pipeline. Parsing rules it has to
 * absorb: UTF-8 BOMs from Excel, 2-digit vs 4-digit years, HH:MM vs HH:MM:SS,
 * and alternate column names ("Zeit von" vs "von", "Verbrauch" vs "kWh").
 */
final class CsvParserTest extends TestCase
{
    private function writeCsv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'energie-csv-');
        file_put_contents($path, $body);
        return $path;
    }

    protected function tearDown(): void
    {
        // Each test creates at most one tempfile; rely on OS cleanup in prod,
        // but scrub deterministic leftovers during the run.
        foreach (glob(sys_get_temp_dir() . '/energie-csv-*') ?: [] as $f) {
            @unlink($f);
        }
    }

    public function test_parses_standard_grid_format(): void
    {
        $csv = $this->writeCsv(
            "Datum;Zeit von;Zeit bis;Verbrauch (kWh)\n"
          . "01.04.2026;00:00;00:15;0,125\n"
          . "01.04.2026;00:15;00:30;0,130\n"
        );
        $this->assertSame(
            ['2026-04-01T00:00:00', '2026-04-01T00:15:00'],
            _parse_energie_csv_timestamps($csv),
        );
    }

    public function test_strips_utf8_bom(): void
    {
        $csv = $this->writeCsv(
            "\xEF\xBB\xBFDatum;Zeit von;Verbrauch (kWh)\n"
          . "15.04.2026;08:00;0,250\n"
        );
        $this->assertSame(['2026-04-15T08:00:00'], _parse_energie_csv_timestamps($csv));
    }

    public function test_accepts_two_digit_year(): void
    {
        $csv = $this->writeCsv(
            "Datum;Zeit von;Verbrauch (kWh)\n"
          . "05.04.26;12:00;0,100\n"
        );
        $this->assertSame(['2026-04-05T12:00:00'], _parse_energie_csv_timestamps($csv));
    }

    public function test_accepts_von_header_without_zeit_prefix(): void
    {
        $csv = $this->writeCsv(
            "Datum;von;bis;Verbrauch\n"
          . "01.04.2026;00:00;00:15;0,125\n"
        );
        $this->assertSame(['2026-04-01T00:00:00'], _parse_energie_csv_timestamps($csv));
    }

    public function test_accepts_kwh_column_without_verbrauch(): void
    {
        $csv = $this->writeCsv(
            "Datum;Zeit von;kWh\n"
          . "01.04.2026;00:00;0,125\n"
        );
        $this->assertSame(['2026-04-01T00:00:00'], _parse_energie_csv_timestamps($csv));
    }

    public function test_normalises_hh_mm_to_hh_mm_ss(): void
    {
        $csv = $this->writeCsv(
            "Datum;Zeit von;Verbrauch (kWh)\n"
          . "01.04.2026;09:30;0,125\n"
        );
        $this->assertSame(['2026-04-01T09:30:00'], _parse_energie_csv_timestamps($csv));
    }

    public function test_preserves_hh_mm_ss_when_already_given(): void
    {
        $csv = $this->writeCsv(
            "Datum;Zeit von;Verbrauch (kWh)\n"
          . "01.04.2026;09:30:45;0,125\n"
        );
        $this->assertSame(['2026-04-01T09:30:45'], _parse_energie_csv_timestamps($csv));
    }

    public function test_zero_pads_single_digit_day_and_month(): void
    {
        $csv = $this->writeCsv(
            "Datum;Zeit von;Verbrauch (kWh)\n"
          . "3.4.2026;00:00;0,125\n"
        );
        $this->assertSame(['2026-04-03T00:00:00'], _parse_energie_csv_timestamps($csv));
    }

    public function test_skips_rows_with_missing_fields(): void
    {
        $csv = $this->writeCsv(
            "Datum;Zeit von;Verbrauch (kWh)\n"
          . "01.04.2026;00:00;0,125\n"
          . ";00:15;0,130\n"            // no date
          . "01.04.2026;;0,130\n"       // no time
          . "01.04.2026;00:30;\n"       // no kwh
          . "01.04.2026;00:45;0,130\n"
        );
        $this->assertSame(
            ['2026-04-01T00:00:00', '2026-04-01T00:45:00'],
            _parse_energie_csv_timestamps($csv),
        );
    }

    public function test_returns_empty_when_required_header_missing(): void
    {
        $csv = $this->writeCsv(
            "WrongDate;WrongTime;WrongValue\n"
          . "01.04.2026;00:00;0,125\n"
        );
        $this->assertSame([], _parse_energie_csv_timestamps($csv));
    }

    public function test_returns_empty_when_file_unreadable(): void
    {
        $this->assertSame([], _parse_energie_csv_timestamps('/nonexistent/path/to/file.csv'));
    }

    public function test_skips_malformed_date_row(): void
    {
        $csv = $this->writeCsv(
            "Datum;Zeit von;Verbrauch (kWh)\n"
          . "not-a-date;00:00;0,125\n"
          . "01.04.2026;00:15;0,130\n"
        );
        $this->assertSame(['2026-04-01T00:15:00'], _parse_energie_csv_timestamps($csv));
    }
}
