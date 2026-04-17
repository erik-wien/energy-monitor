<?php
/**
 * inc/csv_parser.php — pure CSV-parsing helpers for the import preview.
 *
 * Separate from web/api.php so PHPUnit can exercise parsing without
 * pulling in the request/session/PDO side effects of the endpoint.
 */

/**
 * Parse timestamp strings from a semicolon-delimited Energie CSV.
 *
 * Handles UTF-8 BOM, DD.MM.YYYY or DD.MM.YY date format, HH:MM or HH:MM:SS time.
 * Header names recognised: 'Datum', 'Zeit von' (or 'von'), plus any column
 * whose name contains 'Verbrauch' or 'kWh'.
 *
 * Returns an array of 'YYYY-MM-DDTHH:MM:SS' strings — not yet DB-formatted,
 * not deduplicated. Rows with missing required fields are silently skipped
 * (the preview caller treats missing as "nothing to import" rather than an
 * error, matching the Python pipeline's behaviour).
 */
function _parse_energie_csv_timestamps(string $path): array {
    // Suppress fopen's warning on missing/unreadable path — this is a JSON
    // endpoint, so a stray PHP warning would corrupt the response body.
    $handle = @fopen($path, 'r');
    if (!$handle) return [];
    $first   = ltrim((string) fgets($handle), "\xEF\xBB\xBF");  // strip BOM
    $headers = array_map('trim', str_getcsv(trim($first), ';', '"', ''));
    $dIdx = array_search('Datum', $headers, true);
    $vIdx = array_search('Zeit von', $headers, true);
    if ($vIdx === false) $vIdx = array_search('von', $headers, true);
    $kIdx = null;
    foreach ($headers as $i => $h) {
        if (strpos($h, 'Verbrauch') !== false || strpos($h, 'kWh') !== false) { $kIdx = $i; break; }
    }
    if ($dIdx === false || $vIdx === false || $kIdx === null) { fclose($handle); return []; }
    $timestamps = [];
    while (($line = fgets($handle)) !== false) {
        $cols  = str_getcsv(trim($line), ';', '"', '');
        $datum = trim($cols[$dIdx] ?? '');
        $von   = trim($cols[$vIdx] ?? '');
        $kwh   = trim($cols[$kIdx] ?? '');
        if (!$datum || !$von || !$kwh) continue;
        $parts = explode('.', $datum);
        if (count($parts) !== 3) continue;
        $year = strlen($parts[2]) === 2 ? '20' . $parts[2] : $parts[2];
        if (substr_count($von, ':') === 1) $von .= ':00';
        $timestamps[] = sprintf('%s-%02d-%02dT%s', $year, (int)$parts[1], (int)$parts[0], $von);
    }
    fclose($handle);
    return $timestamps;
}
