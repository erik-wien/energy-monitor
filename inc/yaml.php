<?php
/**
 * inc/yaml.php
 *
 * Minimal YAML loader for the flat, 2-level config.yaml emitted by mcp/generate.py.
 * Ported from wlmonitor. Supports top-level scalars, top-level mappings,
 * blank lines, # comments, quoted strings, booleans, nulls, integers.
 * Not supported: lists, nesting > 2, multiline scalars, anchors.
 */

if (!function_exists('wl_yaml_load')) {
    function wl_yaml_load(string $path): array {
        if (!is_file($path)) {
            throw new RuntimeException("YAML file not found: $path");
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $out = [];
        $currentKey = null;

        foreach ($lines as $raw) {
            $line = preg_replace('/(^|\s)#.*$/', '', $raw);
            if (trim($line) === '') { $currentKey = null; continue; }

            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*):\s*(.*)$/', $line, $m)) {
                $key = $m[1];
                $val = trim($m[2]);
                if ($val === '') {
                    $out[$key] = [];
                    $currentKey = $key;
                } else {
                    $out[$key] = wl_yaml_scalar($val);
                    $currentKey = null;
                }
                continue;
            }

            if ($currentKey !== null && preg_match('/^\s+([A-Za-z_][A-Za-z0-9_]*):\s*(.*)$/', $line, $m)) {
                $out[$currentKey][$m[1]] = wl_yaml_scalar(trim($m[2]));
                continue;
            }
        }
        return $out;
    }

    function wl_yaml_scalar(string $v): mixed {
        if ($v === '' || $v === '~' || strcasecmp($v, 'null') === 0) return null;
        if (strcasecmp($v, 'true') === 0 || strcasecmp($v, 'yes') === 0) return true;
        if (strcasecmp($v, 'false') === 0 || strcasecmp($v, 'no') === 0) return false;
        $len = strlen($v);
        if ($len >= 2) {
            $f = $v[0]; $l = $v[$len - 1];
            if (($f === '"' && $l === '"') || ($f === "'" && $l === "'")) {
                return substr($v, 1, -1);
            }
        }
        if (preg_match('/^-?\d+$/', $v)) return (int) $v;
        return $v;
    }
}
