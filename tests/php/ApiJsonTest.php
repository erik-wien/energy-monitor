<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../../inc/api_json.php';

final class ApiJsonTest extends TestCase {
    public function test_naechster_freier_name_haengt_suffix_an(): void {
        $dir = sys_get_temp_dir() . '/energie-namt-' . bin2hex(random_bytes(4));
        mkdir($dir);
        touch($dir . '/verbrauch.csv');
        $this->assertSame('verbrauch_1.csv', naechster_freier_name($dir, 'verbrauch.csv'));
        touch($dir . '/verbrauch_1.csv');
        $this->assertSame('verbrauch_2.csv', naechster_freier_name($dir, 'verbrauch.csv'));
        $this->assertSame('neu.csv', naechster_freier_name($dir, 'neu.csv'));
        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    }
}
