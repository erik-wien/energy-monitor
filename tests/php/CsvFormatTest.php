<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../../inc/csv_format.php';

final class CsvFormatTest extends TestCase
{
    private function tmp(string $content): string {
        $p = tempnam(sys_get_temp_dir(), 'csvfmt-');
        file_put_contents($p, $content);
        return $p;
    }

    public function test_reale_datei_ist_ok(): void {
        $r = energie_csv_format_pruefen(__DIR__ . '/fixtures/viertelstunden_bom.csv');
        $this->assertTrue($r['ok'], $r['problem'] ?? '');
        $this->assertTrue($r['bom']);
        $this->assertSame(';', $r['trennzeichen']);
        $this->assertGreaterThan(0, $r['zeilen']);
        $this->assertSame(0, $r['datum_idx']);
        $this->assertSame(1, $r['zeit_idx']);
        $this->assertSame(3, $r['verbrauch_idx']);
    }

    public function test_fehlende_datumsspalte_meldet_problem(): void {
        $p = $this->tmp("Foo;Zeit von;Verbrauch\n01:00;x;1,0\n");
        $r = energie_csv_format_pruefen($p);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Datum', (string) $r['problem']);
        unlink($p);
    }

    public function test_komma_trennzeichen_wird_erkannt(): void {
        $p = $this->tmp("Datum,Zeit von,Verbrauch\n01.07.2026,00:00,0,5\n");
        $r = energie_csv_format_pruefen($p);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString(',', (string) $r['problem']);
        unlink($p);
    }

    public function test_kopf_ok_aber_keine_datenzeile(): void {
        $p = $this->tmp("Datum;Zeit von;Zeit bis;Verbrauch [kWh]\n");
        $r = energie_csv_format_pruefen($p);
        $this->assertFalse($r['ok']);
        $this->assertSame(0, $r['zeilen']);
        $this->assertStringContainsString('keine Datenzeile', (string) $r['problem']);
        unlink($p);
    }

    public function test_leere_datei(): void {
        $p = $this->tmp('');
        $r = energie_csv_format_pruefen($p);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('leer', (string) $r['problem']);
        unlink($p);
    }

    public function test_tageswerte_export_wird_als_solcher_benannt(): void
    {
        $csv = tempnam(sys_get_temp_dir(), 'tagw-') . '.csv';
        file_put_contents($csv,
            "\xEF\xBB\xBFDatum;AT00... - Verbrauch [kWh];;\n" .
            "01.06.2026;6,699;;\n02.06.2026;9,63;;\n");
        $f = energie_csv_format_pruefen($csv);
        @unlink($csv);
        $this->assertFalse($f['ok']);
        $this->assertNull($f['zeit_idx']);
        $this->assertStringContainsString('Tageswerte', $f['problem']);
        $this->assertStringContainsString('Viertelstundenwerte', $f['problem']);
    }

    public function test_zeit_von_fehlt_ohne_verbrauchsspalte_kein_tageswerte_hinweis(): void {
        // „Zeit von" fehlt UND keine Verbrauchsspalte → das ist KEIN Tageswerte-
        // Export; der Zusatz darf hier nicht anhängen (nur Datum+Verbrauch-da-Fall).
        $p = $this->tmp("Datum;Foo;Bar\n01.06.2026;a;b\n");
        $r = energie_csv_format_pruefen($p);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Zeit von', (string) $r['problem']);
        $this->assertStringNotContainsString('Tageswerte', (string) $r['problem']);
        unlink($p);
    }
}
