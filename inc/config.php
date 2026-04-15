<?php
/**
 * inc/config.php
 *
 * Energie config loader. Prefers legacy /opt/homebrew/etc/energie-config.ini
 * (local Mac dev) when present, otherwise falls back to config.yaml from the
 * app root (deployed environments — akadbrain, world4you). Both sources are
 * normalised to the mcp config.yaml schema: db.{host,name,user,password},
 * auth_db.{...}, smtp.{...}, app.{base_url,env}.
 *
 * Result is cached in a static so repeat calls are free.
 */

require_once __DIR__ . '/yaml.php';

function energie_load_config(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    // 1. Local Mac dev: legacy ini files managed outside the app tree.
    // Skipped on non-macOS hosts where open_basedir would block the probe
    // and emit a visible warning (world4you, akadbrain).
    if (PHP_OS_FAMILY === 'Darwin') {
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
        $iniCandidates = $base === '/energie.test'
            ? ['/opt/homebrew/etc/energie-config-dev.ini', '/opt/homebrew/etc/energie-config.ini']
            : ['/opt/homebrew/etc/energie-config.ini'];
        foreach ($iniCandidates as $iniPath) {
            if (is_readable($iniPath)) {
                $raw = parse_ini_file($iniPath, true) ?: [];
                $cache = energie_normalise_ini($raw);
                return $cache;
            }
        }
    }

    // 2. Deployed: config.yaml sits one level above inc/ (out of webroot).
    $yamlPath = dirname(__DIR__) . '/config.yaml';
    if (is_readable($yamlPath)) {
        $cache = wl_yaml_load($yamlPath);
        return $cache;
    }

    throw new RuntimeException(
        'Energie config not found: neither /opt/homebrew/etc/energie-config.ini nor '
        . $yamlPath
    );
}

function energie_normalise_ini(array $ini): array {
    return [
        'db' => [
            'host'     => $ini['db']['host']     ?? '',
            'name'     => $ini['db']['database'] ?? $ini['db']['name'] ?? '',
            'user'     => $ini['db']['user']     ?? '',
            'password' => $ini['db']['password'] ?? '',
        ],
        'auth_db' => [
            'host'     => $ini['auth']['host']     ?? '',
            'name'     => $ini['auth']['database'] ?? $ini['auth']['name'] ?? '',
            'user'     => $ini['auth']['user']     ?? '',
            'password' => $ini['auth']['password'] ?? '',
        ],
        'smtp' => [
            'host'      => $ini['smtp']['host']      ?? '',
            'port'      => (int) ($ini['smtp']['port'] ?? 587),
            'user'      => $ini['smtp']['user']      ?? '',
            'password'  => $ini['smtp']['password']  ?? '',
            'from'      => $ini['smtp']['from']      ?? '',
            'from_name' => $ini['smtp']['from_name'] ?? 'Energie',
        ],
        'app' => [
            'base_url' => $ini['app']['base_url'] ?? '',
            'env'      => $ini['app']['env']      ?? 'dev',
        ],
    ];
}
