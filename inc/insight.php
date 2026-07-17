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
