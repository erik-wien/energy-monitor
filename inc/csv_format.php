<?php
declare(strict_types=1);

/**
 * Geteilte Format-/Plausibilitätsanalyse einer Energie-CSV.
 *
 * Einzige Quelle der Wahrheit für BOM-Strip, Trennzeichen und Spalten-
 * erkennung — von Vorschau (web/api.php) UND den Parsern (csv_parser.php,
 * csv_importer.php) genutzt, damit die Erkennung nie auseinanderdriftet.
 *
 * Erkannt: Trennzeichen ';' (Standard) mit Heuristik gegen ','; Pflichtspalten
 * 'Datum' + 'Zeit von' (oder 'von') + Verbrauchsspalte (Header enthält
 * 'Verbrauch' oder 'kWh'). 'ok' verlangt alle drei Spalten UND >=1 geparste
 * Datenzeile. Bei !ok trägt 'problem' eine konkrete, menschenlesbare Meldung.
 */
function energie_csv_format_pruefen(string $path): array {
    $out = [
        'ok' => false, 'bom' => false, 'trennzeichen' => ';', 'kopf' => [],
        'datum_idx' => null, 'zeit_idx' => null, 'verbrauch_idx' => null,
        'zeilen' => 0, 'problem' => null,
    ];

    $handle = @fopen($path, 'r');
    if (!$handle) { $out['problem'] = 'Datei leer oder unlesbar.'; return $out; }

    $firstRaw = (string) fgets($handle);
    $out['bom'] = str_starts_with($firstRaw, "\xEF\xBB\xBF");
    $first = ltrim($firstRaw, "\xEF\xBB\xBF");
    if (trim($first) === '') { fclose($handle); $out['problem'] = 'Datei leer oder unlesbar.'; return $out; }

    // Trennzeichen-Heuristik: das, das die meisten Spalten ergibt.
    $semi = str_getcsv(trim($first), ';', '"', '');
    $comma = str_getcsv(trim($first), ',', '"', '');
    $out['trennzeichen'] = count($comma) > count($semi) ? ',' : ';';
    $headers = array_map('trim', str_getcsv(trim($first), ';', '"', ''));
    $out['kopf'] = $headers;

    $dIdx = array_search('Datum', $headers, true);
    $vIdx = array_search('Zeit von', $headers, true);
    if ($vIdx === false) $vIdx = array_search('von', $headers, true);
    $kIdx = null;
    foreach ($headers as $i => $h) {
        if (strpos($h, 'Verbrauch') !== false || strpos($h, 'kWh') !== false) { $kIdx = $i; break; }
    }
    $out['datum_idx']     = $dIdx === false ? null : $dIdx;
    $out['zeit_idx']      = $vIdx === false ? null : $vIdx;
    $out['verbrauch_idx'] = $kIdx;

    // Datenzeilen zählen (leichtgewichtig; nur zur Plausibilität).
    if ($dIdx !== false && $vIdx !== false && $kIdx !== null) {
        while (($line = fgets($handle)) !== false) {
            $cols = str_getcsv(trim($line), ';', '"', '');
            if (trim($cols[$dIdx] ?? '') !== '' && trim($cols[$vIdx] ?? '') !== '' && trim($cols[$kIdx] ?? '') !== '') {
                $out['zeilen']++;
            }
        }
    }
    fclose($handle);

    // Problem-Diagnose (konkret).
    if ($out['trennzeichen'] === ',' && ($dIdx === false)) {
        $out['problem'] = "Spalte „Datum“ nicht gefunden. Kopfzeile: " . implode(';', $headers)
            . " → Trennzeichen scheint „,“ statt „;“.";
        return $out;
    }
    if ($dIdx === false) {
        $out['problem'] = "Spalte „Datum“ nicht gefunden. Kopfzeile: " . implode(';', $headers);
        return $out;
    }
    if ($vIdx === false) {
        $out['problem'] = "Spalte „Zeit von“ nicht gefunden. Kopfzeile: " . implode(';', $headers);
        // Datum + Verbrauch vorhanden, nur die Uhrzeit fehlt → klassischer Tageswerte-Export.
        if ($kIdx !== null) {
            $out['problem'] .= " — das sieht nach einem Tageswerte-Export aus; der Importer braucht "
                . "Viertelstundenwerte (Spalte „Zeit von“).";
        }
        return $out;
    }
    if ($kIdx === null) {
        $out['problem'] = "Verbrauchsspalte (Header mit „Verbrauch“ oder „kWh“) nicht gefunden. Kopfzeile: " . implode(';', $headers);
        return $out;
    }
    if ($out['zeilen'] === 0) {
        $out['problem'] = 'Kopfzeile erkannt, aber keine Datenzeile geparst — Datums-/Zeitformat geändert?';
        return $out;
    }
    $out['ok'] = true;
    return $out;
}
