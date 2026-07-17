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
}
