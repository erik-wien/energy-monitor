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
}
