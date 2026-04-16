<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for `energie_config_path()` (see inc/config.php).
 *
 * The helper is pure routing — given the current SCRIPT_NAME and two
 * candidate ini paths, it returns whichever should be used. Dev path
 * wins only when the URL is mounted at /energie.test AND the dev ini
 * is readable; every other combination falls through to prod.
 */
final class ConfigPathTest extends TestCase
{
    private string $devIni;
    private string $prodIni;

    protected function setUp(): void
    {
        $this->devIni  = tempnam(sys_get_temp_dir(), 'energie-dev-');
        $this->prodIni = tempnam(sys_get_temp_dir(), 'energie-prod-');
    }

    protected function tearDown(): void
    {
        @unlink($this->devIni);
        @unlink($this->prodIni);
    }

    public function test_dev_path_when_mounted_on_energie_test_and_readable(): void
    {
        $this->assertSame(
            $this->devIni,
            energie_config_path('/energie.test/api.php', $this->devIni, $this->prodIni),
        );
    }

    public function test_prod_path_when_not_mounted_on_energie_test(): void
    {
        $this->assertSame(
            $this->prodIni,
            energie_config_path('/energie/api.php', $this->devIni, $this->prodIni),
        );
    }

    public function test_prod_path_when_dev_ini_is_unreadable(): void
    {
        unlink($this->devIni);
        $this->assertSame(
            $this->prodIni,
            energie_config_path('/energie.test/api.php', $this->devIni, $this->prodIni),
        );
    }

    public function test_falls_back_to_script_name_from_server_global(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/energie.test/index.php';
        $this->assertSame(
            $this->devIni,
            energie_config_path(null, $this->devIni, $this->prodIni),
        );
    }

    public function test_empty_script_name_yields_prod(): void
    {
        $this->assertSame(
            $this->prodIni,
            energie_config_path('', $this->devIni, $this->prodIni),
        );
    }
}
