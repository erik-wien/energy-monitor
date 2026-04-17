<?php
/**
 * inc/epex_importer.php — PHP-native Hofer Grünstrom spot price fetcher.
 *
 * Replicates energie.py fetch-prices:
 *   1. GET spot prices from Hofer Grünstrom API for a given year/month
 *   2. Parse JSON response: data[].from (ISO timestamp), data[].price (ct/kWh)
 *   3. Upsert into readings.spot_ct (leaves consumed_kwh/cost_brutto at 0 for new rows)
 *
 * @param  PDO $pdo   Data DB connection
 * @param  int $year  e.g. 2025
 * @param  int $month 1–12
 * @return array ['rows' => int, 'log' => string]
 * @throws RuntimeException on HTTP or parse error
 */
function php_fetch_epex(PDO $pdo, int $year, int $month): array {
    // Punycode (IDNA) form of www.hofer-grünstrom.at — avoids any idn_to_ascii()
    // call and the PHP notices it can emit when the intl constant is not defined.
    $url = sprintf(
        'https://www.xn--hofer-grnstrom-nsb.at/service/energy-manager/spot-prices?year=%d&month=%02d',
        $year, $month
    );

    if (function_exists('curl_init')) {
        $ch = @curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init fehlgeschlagen');
        }
        @curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'Energie-PHP/1.0',
            CURLOPT_ENCODING       => '',
        ]);
        $body = @curl_exec($ch);
        $code = (int) @curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = (string) @curl_error($ch);
        @curl_close($ch);
        if ($body === false || $err !== '') {
            throw new RuntimeException('curl: ' . ($err ?: 'unbekannter Fehler'));
        }
        if ($code !== 200) {
            throw new RuntimeException('HTTP ' . $code . ' von API erhalten');
        }
    } else {
        $ctx = stream_context_create(['http' => [
            'method'  => 'GET',
            'timeout' => 30,
            'header'  => "User-Agent: Energie-PHP/1.0\r\n",
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            throw new RuntimeException('API nicht erreichbar (file_get_contents)');
        }
    }

    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
        throw new RuntimeException('Unerwartetes API-Format');
    }

    $upsert = $pdo->prepare(
        "INSERT INTO readings (ts, consumed_kwh, spot_ct, cost_brutto)
         VALUES (?, 0, ?, 0)
         ON DUPLICATE KEY UPDATE spot_ct = VALUES(spot_ct)"
    );

    $count = 0;
    $pdo->beginTransaction();
    try {
        foreach ($data['data'] as $row) {
            if (!isset($row['from'], $row['price'])) continue;
            $upsert->execute([$row['from'], (float)$row['price']]);
            $count++;
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    return [
        'rows' => $count,
        'log'  => sprintf('%d-%02d: %d Spotpreis-Zeilen importiert', $year, $month, $count),
    ];
}

/**
 * Find all months that have consumption data but no spot prices (avg spot_ct = 0),
 * fetch them all from the API, and return combined results.
 *
 * @return array ['rows' => int, 'months' => int, 'log' => string]
 */
function php_fetch_missing_epex(PDO $pdo): array {
    // Months with consumption where we have no spot prices at all yet.
    $missing = $pdo->query(
        "SELECT YEAR(ts) AS y, MONTH(ts) AS m
         FROM readings
         WHERE consumed_kwh > 0
         GROUP BY y, m
         HAVING AVG(spot_ct) = 0
         ORDER BY y, m"
    )->fetchAll(PDO::FETCH_ASSOC);

    if (empty($missing)) {
        return ['rows' => 0, 'months' => 0, 'log' => 'Keine fehlenden Spot-Preise gefunden.'];
    }

    $totalRows = 0;
    $log       = '';
    $errors    = [];

    foreach ($missing as $row) {
        $y = (int)$row['y'];
        $m = (int)$row['m'];
        try {
            $result     = php_fetch_epex($pdo, $y, $m);
            $totalRows += $result['rows'];
            $log       .= $result['log'] . "\n";
        } catch (Exception $e) {
            $errors[] = sprintf('%d-%02d: %s', $y, $m, $e->getMessage());
        }
    }

    if ($errors) {
        $log .= 'Fehler: ' . implode('; ', $errors);
    }

    return ['rows' => $totalRows, 'months' => count($missing), 'log' => trim($log)];
}
