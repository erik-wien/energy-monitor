<?php
declare(strict_types=1);

/**
 * inc/insight.php — reine Helfer für die Dashboard-Einsichts-Schicht
 * (Delta-Chips, Sparklines) + periodenweise Preis-Komposition (DB, s. u.).
 */

/** Prozentuale Veränderung + Richtung. $vorher<=0 → kein Vergleich (pct=null). */
function en_delta(float $aktuell, float $vorher): array {
    if ($vorher <= 0.0) return ['pct' => null, 'dir' => 'flat'];
    $pct = ($aktuell - $vorher) / $vorher * 100.0;
    $dir = abs($pct) < 0.5 ? 'flat' : ($pct > 0 ? 'up' : 'down');
    return ['pct' => $pct, 'dir' => $dir];
}

/** Winzige inline-SVG-Sparkline (currentColor). ≤1 Wert → leerer String. */
function en_sparkline_svg(array $werte, int $w = 64, int $h = 16): string {
    $werte = array_values(array_map('floatval', $werte));
    $n = count($werte);
    if ($n < 2) return '';
    $min = min($werte); $max = max($werte);
    $span = ($max - $min) ?: 1.0;               // flach → keine Division durch 0
    $pad = 1.5;                                  // Randabstand, damit die Linie nicht klippt
    $pts = [];
    foreach ($werte as $i => $v) {
        $x = $n === 1 ? 0 : round($i / ($n - 1) * $w, 2);
        $y = round($pad + (1 - ($v - $min) / $span) * ($h - 2 * $pad), 2);
        $pts[] = $x . ',' . $y;
    }
    return '<svg class="sparkline" viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h
        . '" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"'
        . ' stroke-linecap="round" aria-hidden="true"><polyline points="' . implode(' ', $pts) . '"/></svg>';
}

/**
 * en_preis_komposition() — zerlegt den Bruttopreis je kWh einer Periode in
 * Preisschichten (Börse → Aufschlag → Abgaben → Gebrauchsabgabe → MwSt →
 * Fixkosten). Spiegelt invoice_breakdown() in web/api.php; bewusst
 * dupliziert, um api.php nicht anzufassen.
 *
 * Lädt je Tag der Periode daily_summary × tariff_config (valid_from<=Tag, MAX).
 * Alle Preis-Komponenten in ct/kWh (Division durch Σkwh), 'kwh'/'eur' als
 * Perioden-Summen. Leere Periode (Σkwh==0) → alle Preis-Felder 0.
 *
 * @return array{spot:float,aufschlag:float,abgaben:float,gba:float,mwst:float,
 *               fixkosten:float,brutto:float,netto:float,kwh:float,eur:float}
 */
function en_preis_komposition(PDO $pdo, string $from, string $to): array {
    $stmt = $pdo->prepare(
        "SELECT ds.consumed_kwh, ds.cost_brutto, ds.avg_spot_ct,
                t.provider_surcharge_ct AS psc,
                t.electricity_tax_ct    AS etc,
                t.renewable_tax_ct      AS rtc,
                t.consumption_tax_rate  AS gbr,
                t.vat_rate              AS vatr,
                t.meter_fee_eur         AS mfee,
                t.renewable_fee_eur     AS rfee,
                t.yearly_kwh_estimate   AS ykwh
         FROM daily_summary ds
         LEFT JOIN tariff_config t ON t.valid_from = (
             SELECT MAX(valid_from) FROM tariff_config WHERE valid_from <= ds.day
         )
         WHERE ds.day BETWEEN ? AND ?
         ORDER BY ds.day"
    );
    $stmt->execute([$from, $to]);

    $spotE = $aufschlagE = $abgabenE = $gbaE = $mwstE = $fixkostenE = 0.0;
    $kwhSum = $eurSum = $nettoEur = 0.0;

    foreach ($stmt->fetchAll() as $row) {
        $kwh  = (float)$row['consumed_kwh'];
        $spot = (float)$row['avg_spot_ct'];
        $psc  = (float)($row['psc']  ?? 0);
        $etc  = (float)($row['etc']  ?? 0);
        $rtc  = (float)($row['rtc']  ?? 0);
        $gbr  = (float)($row['gbr']  ?? 0);
        $vatr = (float)($row['vatr'] ?? 0);
        $mfee = (float)($row['mfee'] ?? 0);
        $rfee = (float)($row['rfee'] ?? 0);
        $ykwh = max(1.0, (float)($row['ykwh'] ?? 0));
        $cost = (float)$row['cost_brutto'];

        $netVar = $spot + $psc + $etc + $rtc;
        $gross  = 1 + $gbr + $vatr;

        $spotE      += $kwh * $spot / 100;
        $aufschlagE += $kwh * $psc  / 100;
        $abgabenE   += $kwh * ($etc + $rtc) / 100;
        $gbaE       += $kwh * $netVar * $gbr  / 100;
        $mwstE      += $kwh * $netVar * $vatr / 100;
        $fixkostenE += $kwh * ($mfee + $rfee) / $ykwh * $gross;

        $kwhSum   += $kwh;
        $eurSum   += $cost;
        $nettoEur += $cost / $gross;
    }

    if ($kwhSum <= 0.0) {
        return ['spot' => 0.0, 'aufschlag' => 0.0, 'abgaben' => 0.0, 'gba' => 0.0,
                'mwst' => 0.0, 'fixkosten' => 0.0, 'brutto' => 0.0, 'netto' => 0.0,
                'kwh' => 0.0, 'eur' => 0.0];
    }

    $spotCt      = $spotE      * 100 / $kwhSum;
    $aufschlagCt = $aufschlagE * 100 / $kwhSum;
    $abgabenCt   = $abgabenE   * 100 / $kwhSum;
    $gbaCt       = $gbaE       * 100 / $kwhSum;
    $mwstCt      = $mwstE      * 100 / $kwhSum;
    $fixkostenCt = $fixkostenE * 100 / $kwhSum;
    $bruttoCt    = $eurSum     * 100 / $kwhSum;
    $nettoCt     = $nettoEur   * 100 / $kwhSum;

    // Rundungs-Sicherheitsnetz: die 6 Segmente sollen exakt auf brutto summieren
    // (Toleranz 0,05 ct), damit die gestapelte Leiste optisch bei 100 % endet.
    // Rest geht ins größte Nicht-Spot-Segment (Spot bleibt die "reine" Börsenzahl).
    $segSum = $spotCt + $aufschlagCt + $abgabenCt + $gbaCt + $mwstCt + $fixkostenCt;
    $diff   = $bruttoCt - $segSum;
    if (abs($diff) > 0.05) {
        $nonSpot = ['aufschlag' => &$aufschlagCt, 'abgaben' => &$abgabenCt,
                    'gba' => &$gbaCt, 'mwst' => &$mwstCt, 'fixkosten' => &$fixkostenCt];
        $largestKey = null; $largestVal = -INF;
        foreach ($nonSpot as $key => $val) {
            if ($val > $largestVal) { $largestVal = $val; $largestKey = $key; }
        }
        $nonSpot[$largestKey] += $diff;
    }

    return [
        'spot' => $spotCt, 'aufschlag' => $aufschlagCt, 'abgaben' => $abgabenCt,
        'gba' => $gbaCt, 'mwst' => $mwstCt, 'fixkosten' => $fixkostenCt,
        'brutto' => $bruttoCt, 'netto' => $nettoCt, 'kwh' => $kwhSum, 'eur' => $eurSum,
    ];
}

/**
 * en_effektiv_serie() — Tagesreihe des effektiven Preises (cost_brutto/kwh,
 * ct/kWh) einer Periode, für Sparklines. Tage mit consumed_kwh<=0 werden
 * übersprungen (Division durch 0).
 *
 * @return float[] in Tagesreihenfolge
 */
function en_effektiv_serie(PDO $pdo, string $from, string $to): array {
    $stmt = $pdo->prepare(
        "SELECT consumed_kwh, cost_brutto FROM daily_summary
         WHERE day BETWEEN ? AND ? ORDER BY day"
    );
    $stmt->execute([$from, $to]);

    $serie = [];
    foreach ($stmt->fetchAll() as $row) {
        $kwh = (float)$row['consumed_kwh'];
        if ($kwh <= 0.0) continue;
        $serie[] = (float)$row['cost_brutto'] / $kwh * 100;
    }
    return $serie;
}
