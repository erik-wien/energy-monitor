<?php
declare(strict_types=1);

/**
 * inc/wetter.php — deterministische Fakten-Engine für den Dashboard-„Wetterbericht"
 * (Verbrauch 30 T vs. Baseline, Lastdisziplin, heutiges Spotprofil) + Template-Fallback.
 * Reine/DB-testbare Funktionen, keine LLM-Abhängigkeit
 * (s. docs/superpowers/specs/2026-07-17-wetterbericht-design.md §1).
 * en_wetter_regenerieren() (§3, weiter unten) formuliert per Haiku und braucht
 * daher inc/ai_client.php.
 */

require_once __DIR__ . '/ai_client.php';

/**
 * en_wetter_fakten() — bündelt die drei Fakten-Blöcke für den Wetterbericht,
 * verankert auf den Tag $latest (Format 'Y-m-d'). Das „heute"-Preisprofil kann
 * per $profilTag auf einen anderen Tag verschoben werden (Morgen-Vorschau im
 * #nach-Slot, s. en_wetter_regenerieren()); $vorschau markiert das für
 * en_wetter_template() ("Vorschau auf morgen" statt "Heute").
 *
 * @return array{
 *   verbrauch: array{ist_kwh: float, basis_kwh: float, delta_pct: ?float},
 *   disziplin: array{gew: ?float, einfach: ?float, gap_pct: ?float, bewertung: string},
 *   heute: array{datum: string, avg: ?float, max: ?float, max_h: ?int, min: ?float,
 *                min_h: ?int, spitzen: int[], guenstig_von: ?int, guenstig_bis: ?int,
 *                guenstig_avg: ?float},
 *   vorschau: bool
 * }
 */
function en_wetter_fakten(PDO $pdo, string $latest, ?string $profilTag = null, bool $vorschau = false): array {
    return [
        'verbrauch' => en_wetter_verbrauch($pdo, $latest),
        'disziplin' => en_wetter_disziplin($pdo, $latest),
        'heute'     => en_wetter_heute($pdo, $profilTag ?? $latest),
        'vorschau'  => $vorschau,
    ];
}

/** Trend-Schwelle (Spec §2): 30-T-Verbrauch vs. die 30 Tage davor, >|5 %| = steigend/fallend. */
const EN_TREND_SCHWELLE = 0.05;

/**
 * Summiert `daily_summary.consumed_kwh` über ein Fenster `(day > start, day <= ende]`.
 * Liefert null, wenn das Fenster keine Zeilen enthält (SUM() über 0 Zeilen ist NULL),
 * sonst den (ggf. 0,0) Summenwert.
 */
function en_wetter_sum_fenster(PDO $pdo, string $start, string $ende): ?float {
    $stmt = $pdo->prepare(
        "SELECT SUM(consumed_kwh) FROM daily_summary WHERE day > ? AND day <= ?"
    );
    $stmt->execute([$start, $ende]);
    $sum = $stmt->fetchColumn();
    return $sum !== null ? (float) $sum : null;
}

/**
 * Verbrauchs-Kennzahlenblock, verankert auf $gestern (Spec §2): gestriger
 * Verbrauch, Woche/Monat (bis inkl. $gestern) je mit Vorjahresvergleich bzw.
 * Mehrjahr-„üblich"-Schnitt (2024+2025), sowie 30-Tage-Trend ggü. den 30 Tagen
 * davor. Alle Vergleichswerte sind null, wenn die entsprechende Historie fehlt
 * (nie 0/Fehler).
 */
function en_wetter_verbrauch(PDO $pdo, string $gestern): array {
    $stmt = $pdo->prepare("SELECT consumed_kwh FROM daily_summary WHERE day = ?");
    $stmt->execute([$gestern]);
    $gesternRow = $stmt->fetchColumn();
    $gesternKwh = $gesternRow !== false ? (float) $gesternRow : null;

    $stmt = $pdo->prepare("SELECT DATE_SUB(?, INTERVAL 7 DAY)");
    $stmt->execute([$gestern]);
    $w7Start = $stmt->fetchColumn();
    $w7Kwh   = en_wetter_sum_fenster($pdo, $w7Start, $gestern) ?? 0.0;

    $stmt = $pdo->prepare("SELECT DATE_SUB(?, INTERVAL 1 YEAR)");
    $stmt->execute([$gestern]);
    $w7VorjahrEnde  = $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT DATE_SUB(?, INTERVAL 7 DAY)");
    $stmt->execute([$w7VorjahrEnde]);
    $w7VorjahrStart = $stmt->fetchColumn();
    $w7VorjahrKwh   = en_wetter_sum_fenster($pdo, $w7VorjahrStart, $w7VorjahrEnde);

    $w7YoyPct = ($w7VorjahrKwh !== null && $w7VorjahrKwh != 0.0)
        ? ($w7Kwh / $w7VorjahrKwh - 1.0)
        : null;

    $stmt = $pdo->prepare("SELECT DATE_SUB(?, INTERVAL 30 DAY)");
    $stmt->execute([$gestern]);
    $d30Start = $stmt->fetchColumn();
    $d30Kwh   = en_wetter_sum_fenster($pdo, $d30Start, $gestern) ?? 0.0;

    $ueblichWerte = [];
    foreach ([1, 2] as $jahre) {
        $stmt = $pdo->prepare("SELECT DATE_SUB(?, INTERVAL {$jahre} YEAR)");
        $stmt->execute([$gestern]);
        $ende = $stmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT DATE_SUB(?, INTERVAL 30 DAY)");
        $stmt->execute([$ende]);
        $start = $stmt->fetchColumn();
        $sum   = en_wetter_sum_fenster($pdo, $start, $ende);
        if ($sum !== null) $ueblichWerte[] = $sum;
    }
    $d30Ueblich = $ueblichWerte ? array_sum($ueblichWerte) / count($ueblichWerte) : null;

    $d30DeltaPct = ($d30Ueblich !== null && $d30Ueblich != 0.0)
        ? ($d30Kwh / $d30Ueblich - 1.0)
        : null;

    $stmt = $pdo->prepare("SELECT DATE_SUB(?, INTERVAL 60 DAY)");
    $stmt->execute([$gestern]);
    $vorBlockStart = $stmt->fetchColumn();
    $vorBlockSum   = en_wetter_sum_fenster($pdo, $vorBlockStart, $d30Start);

    $trend = null;
    if ($vorBlockSum !== null && $vorBlockSum != 0.0) {
        $trendPct = $d30Kwh / $vorBlockSum - 1.0;
        if ($trendPct > EN_TREND_SCHWELLE) {
            $trend = 'steigend';
        } elseif ($trendPct < -EN_TREND_SCHWELLE) {
            $trend = 'fallend';
        } else {
            $trend = 'stabil';
        }
    }

    return [
        'gestern_kwh'     => $gesternKwh,
        'w7_kwh'          => $w7Kwh,
        'w7_vorjahr_kwh'  => $w7VorjahrKwh,
        'w7_yoy_pct'      => $w7YoyPct,
        'd30_kwh'         => $d30Kwh,
        'd30_ueblich_kwh' => $d30Ueblich,
        'd30_delta_pct'   => $d30DeltaPct,
        'trend'           => $trend,
    ];
}

/** Starkverbraucher-Schwellen (Spec §2): Stunde zählt als Lauf ab Tages-Ø·Faktor, max. N Läufe. */
const EN_STARKVERBRAUCH_FAKTOR      = 1.5;
const EN_STARKVERBRAUCH_MAX_LAEUFE  = 3;

/**
 * Disziplin-Kennzahlenblock (Spec §2): Starkverbraucher-Läufe von $gestern —
 * Stunden mit `consumed_kwh > Tages-Ø·EN_STARKVERBRAUCH_FAKTOR`, absteigend
 * nach kWh, max. EN_STARKVERBRAUCH_MAX_LAEUFE. Je Lauf die Spot-Lage der
 * Stunde (Terzil der Tages-Spotpreise): unteres Drittel `guenstig`, oberes
 * `teuer`, sonst `mittel`. Bewertung: mehrheitlich guenstig → `gut`,
 * mehrheitlich teuer → `unguenstig`, sonst `neutral`. Keine Läufe (keine
 * Readings oder kein Ausreißer) → `laeufe=[]`, `bewertung=null`.
 */
function en_wetter_disziplin(PDO $pdo, string $gestern): array {
    $stmt = $pdo->prepare(
        "SELECT HOUR(ts) AS h, SUM(consumed_kwh) AS kwh, AVG(spot_ct) AS spot
         FROM readings WHERE DATE(ts) = ? GROUP BY HOUR(ts)"
    );
    $stmt->execute([$gestern]);
    $rows = $stmt->fetchAll();
    if (!$rows) return ['laeufe' => [], 'bewertung' => null];

    $kwhByHour  = [];
    $spotByHour = [];
    foreach ($rows as $row) {
        $h = (int) $row['h'];
        $kwhByHour[$h]  = (float) $row['kwh'];
        $spotByHour[$h] = (float) $row['spot'];
    }

    $tagesAvg = array_sum($kwhByHour) / count($kwhByHour);
    $schwelle = $tagesAvg * EN_STARKVERBRAUCH_FAKTOR;

    $kandidaten = array_filter($kwhByHour, fn(float $kwh) => $kwh > $schwelle);
    if (!$kandidaten) return ['laeufe' => [], 'bewertung' => null];

    arsort($kandidaten); // absteigend nach kwh (stabil bei Gleichstand)
    $topStunden = array_slice(array_keys($kandidaten), 0, EN_STARKVERBRAUCH_MAX_LAEUFE);

    // Terzil-Lage je Stunde aus den Tages-Spotwerten (positionale Drittel
    // nach aufsteigendem Spotpreis sortiert).
    $sortedH = array_keys($spotByHour);
    usort($sortedH, fn($a, $b) => $spotByHour[$a] <=> $spotByHour[$b]);
    $n = count($sortedH);
    $lageByHour = [];
    foreach ($sortedH as $i => $h) {
        $tier = intdiv($i * 3, $n);
        $lageByHour[$h] = $tier === 0 ? 'guenstig' : ($tier === 2 ? 'teuer' : 'mittel');
    }

    $laeufe = [];
    $guenstigCount = 0;
    $teuerCount    = 0;
    foreach ($topStunden as $h) {
        $lage = $lageByHour[$h] ?? 'mittel';
        if ($lage === 'guenstig') $guenstigCount++;
        elseif ($lage === 'teuer') $teuerCount++;
        $laeufe[] = [
            'stunde'  => $h,
            'kwh'     => $kwhByHour[$h],
            'spot_ct' => $spotByHour[$h],
            'lage'    => $lage,
        ];
    }

    $bewertung = $guenstigCount > $teuerCount
        ? 'gut'
        : ($teuerCount > $guenstigCount ? 'unguenstig' : 'neutral');

    return ['laeufe' => $laeufe, 'bewertung' => $bewertung];
}

/**
 * Heutiges Preisprofil: Stunden-Aggregat (AVG(spot_ct) je HOUR(ts)) des Tages
 * $latest aus `readings`. avg/max/min(+Stunde), Spitzen (Stunde > avg·1,25),
 * günstigstes gleitendes 3-h-Fenster (min Ø über drei aufeinanderfolgende Stunden).
 * Leerer Tag (keine Readings) → alle Werte null / [].
 */
function en_wetter_heute(PDO $pdo, string $latest): array {
    $result = [
        'datum' => $latest, 'avg' => null, 'max' => null, 'max_h' => null,
        'min' => null, 'min_h' => null, 'spitzen' => [],
        'guenstig_von' => null, 'guenstig_bis' => null, 'guenstig_avg' => null,
    ];

    $stmt = $pdo->prepare(
        "SELECT HOUR(ts) AS h, AVG(spot_ct) AS avg_spot
         FROM readings WHERE DATE(ts) = ? GROUP BY HOUR(ts) ORDER BY h"
    );
    $stmt->execute([$latest]);
    $rows = $stmt->fetchAll();
    if (!$rows) return $result;

    $stunden = [];
    foreach ($rows as $row) {
        $stunden[(int) $row['h']] = (float) $row['avg_spot'];
    }

    $avg = array_sum($stunden) / count($stunden);

    $maxH = null; $maxV = null;
    $minH = null; $minV = null;
    foreach ($stunden as $h => $v) {
        if ($maxV === null || $v > $maxV) { $maxV = $v; $maxH = $h; }
        if ($minV === null || $v < $minV) { $minV = $v; $minH = $h; }
    }

    $spitzen = [];
    foreach ($stunden as $h => $v) {
        if ($v > $avg * 1.25) $spitzen[] = $h;
    }
    sort($spitzen);

    // Günstigstes gleitendes 3-h-Fenster über drei lückenlos aufeinanderfolgende
    // Stunden (keine Wrap-around-Fenster über Mitternacht).
    $stundenSortiert = array_keys($stunden);
    sort($stundenSortiert);
    $guenstigVon = null; $guenstigBis = null; $guenstigAvg = null;
    $bestAvg = null;
    foreach ($stundenSortiert as $h) {
        if (!isset($stunden[$h + 1], $stunden[$h + 2])) continue;
        $fensterAvg = ($stunden[$h] + $stunden[$h + 1] + $stunden[$h + 2]) / 3.0;
        if ($bestAvg === null || $fensterAvg < $bestAvg) {
            $bestAvg = $fensterAvg;
            $guenstigVon = $h;
            $guenstigBis = $h + 3;
        }
    }
    $guenstigAvg = $guenstigVon !== null ? $bestAvg : null;

    $result['avg']          = $avg;
    $result['max']          = $maxV;
    $result['max_h']        = $maxH;
    $result['min']          = $minV;
    $result['min_h']        = $minH;
    $result['spitzen']      = $spitzen;
    $result['guenstig_von'] = $guenstigVon;
    $result['guenstig_bis'] = $guenstigBis;
    $result['guenstig_avg'] = $guenstigAvg;

    return $result;
}

/** Gibt es mind. eine `readings`-Zeile am Tag $tag? (Prüft, ob Spotpreise für morgen schon da sind.) */
function en_wetter_hat_readings(PDO $pdo, string $tag): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM readings WHERE DATE(ts) = ? LIMIT 1");
    $stmt->execute([$tag]);
    return $stmt->fetchColumn() !== false;
}

// ── Preisprofil (heute+morgen), Symbol, Vergleiche, Auffälligkeiten (Spec §2, Task 3) ──

/** Symbol-Schwellen fürs heutige Preisprofil: max<=avg·SONNE -> 'sonne', max>avg·GEWITTER -> 'gewitter', sonst 'wolke'. */
const EN_SYMBOL_SONNE    = 1.4;
const EN_SYMBOL_GEWITTER = 2.0;

/** Zurückliegende Monate für den Preis-Rekord-Vergleich in en_wetter_auffaelligkeiten(). */
const EN_AUFFAELLIG_MONATE = 3;

/** Schwelle für „Verbrauch gestern deutlich unter dem Üblichen" (oberes Pendant ist EN_STARKVERBRAUCH_FAKTOR). */
const EN_AUFFAELLIG_VERBRAUCH_NIEDRIG = 0.5;

/**
 * Wetterglyph aus einem Preisprofil (`en_wetter_heute()`-Format): breit-günstig
 * (Spitze höchstens EN_SYMBOL_SONNE·Ø) → 'sonne'; extreme Spitze
 * (> EN_SYMBOL_GEWITTER·Ø) → 'gewitter'; sonst 'wolke'. Leeres Profil
 * (`avg` fehlt) → 'wolke' (neutral).
 */
function en_wetter_symbol(array $profil): string {
    $avg = $profil['avg'] ?? null;
    $max = $profil['max'] ?? null;
    if ($avg === null || $max === null) return 'wolke';
    if ($max <= $avg * EN_SYMBOL_SONNE)    return 'sonne';
    if ($max >  $avg * EN_SYMBOL_GEWITTER) return 'gewitter';
    return 'wolke';
}

/** Ø `daily_summary.avg_spot_ct` im Fenster `(start, ende]`; null wenn keine Zeilen. */
function en_wetter_avg_spot_fenster(PDO $pdo, string $start, string $ende): ?float {
    $stmt = $pdo->prepare(
        "SELECT AVG(avg_spot_ct) FROM daily_summary WHERE day > ? AND day <= ?"
    );
    $stmt->execute([$start, $ende]);
    $avg = $stmt->fetchColumn();
    return $avg !== null ? (float) $avg : null;
}

/**
 * Preisprofil-Block (Spec §2): heutiges (+ optional morgiges) Preisprofil aus
 * `en_wetter_heute()`, verglichen mit der 30-Tage-Historie bis inkl. gestern
 * (=`$heute`−1) und mit dem gleichen Kalendertag ±3 Tage im Vorjahr (jeweils
 * Ø `daily_summary.avg_spot_ct`, da `$heute` selbst i. d. R. noch nicht in
 * `daily_summary` steht). Vergleichswerte sind null, wenn heute kein Profil
 * (`avg`) hat oder die jeweilige Historie fehlt.
 */
function en_wetter_preis(PDO $pdo, string $heute, ?string $morgen): array {
    $profilHeute  = en_wetter_heute($pdo, $heute);
    $profilMorgen = $morgen !== null ? en_wetter_heute($pdo, $morgen) : null;

    $heuteAvg = $profilHeute['avg'] ?? null;

    $stmt = $pdo->prepare("SELECT DATE_SUB(?, INTERVAL 1 DAY)");
    $stmt->execute([$heute]);
    $ueblichEnde = $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT DATE_SUB(?, INTERVAL 30 DAY)");
    $stmt->execute([$ueblichEnde]);
    $ueblichStart = $stmt->fetchColumn();
    $ueblichAvg   = $heuteAvg !== null ? en_wetter_avg_spot_fenster($pdo, $ueblichStart, $ueblichEnde) : null;

    $stmt = $pdo->prepare("SELECT DATE_SUB(?, INTERVAL 1 YEAR)");
    $stmt->execute([$heute]);
    $vorjahrTag = $stmt->fetchColumn();
    // Fenster [vorjahrTag-3, vorjahrTag+3] (inklusive); en_wetter_avg_spot_fenster()
    // erwartet ein exklusives Start-Datum, daher -4 Tage als Start-Schranke.
    $stmt = $pdo->prepare("SELECT DATE_SUB(?, INTERVAL 4 DAY)");
    $stmt->execute([$vorjahrTag]);
    $vorjahrStart = $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT DATE_ADD(?, INTERVAL 3 DAY)");
    $stmt->execute([$vorjahrTag]);
    $vorjahrEnde = $stmt->fetchColumn();
    $vorjahrAvg  = $heuteAvg !== null
        ? en_wetter_avg_spot_fenster($pdo, $vorjahrStart, $vorjahrEnde)
        : null;

    $ueblichPct = ($heuteAvg !== null && $ueblichAvg !== null && $ueblichAvg != 0.0)
        ? ($heuteAvg / $ueblichAvg - 1.0)
        : null;
    $vorjahrPct = ($heuteAvg !== null && $vorjahrAvg !== null && $vorjahrAvg != 0.0)
        ? ($heuteAvg / $vorjahrAvg - 1.0)
        : null;

    return [
        'heute'                 => $profilHeute,
        'morgen'                => $profilMorgen,
        'heute_vs_ueblich_pct'  => $ueblichPct,
        'heute_vs_vorjahr_pct'  => $vorjahrPct,
        'symbol'                => en_wetter_symbol($profilHeute),
    ];
}

/**
 * Auffälligkeiten (Spec §2): 0..3 mit PHP erkannte, konkrete Beobachtungen
 * (Zahl+Einheit), die Haiku hervorheben kann — nie erfunden, nie ohne Beleg.
 * Aktuell zwei Prüfungen (je maximal eine Auffälligkeit):
 *  - Preisrekord: heutiger Ø-Spotpreis ($preisHeute['avg']) niedriger/höher als
 *    das Minimum/Maximum der letzten EN_AUFFAELLIG_MONATE Monate (Ø `daily_summary
 *    .avg_spot_ct`, bis inkl. $gestern).
 *  - Verbrauchsausreißer: $gestern-Verbrauch über EN_STARKVERBRAUCH_FAKTOR bzw.
 *    unter EN_AUFFAELLIG_VERBRAUCH_NIEDRIG des Ø-Tagesverbrauchs der 30 Tage davor.
 * Fehlt die nötige Historie, wird die jeweilige Prüfung übersprungen (kein Fehler).
 */
function en_wetter_auffaelligkeiten(PDO $pdo, string $gestern, array $preisHeute): array {
    $auffaellig = [];

    $heuteAvg = $preisHeute['avg'] ?? null;
    if ($heuteAvg !== null) {
        $stmt = $pdo->prepare("SELECT DATE_SUB(?, INTERVAL " . EN_AUFFAELLIG_MONATE . " MONTH)");
        $stmt->execute([$gestern]);
        $start = $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT MIN(avg_spot_ct), MAX(avg_spot_ct) FROM daily_summary WHERE day > ? AND day <= ?"
        );
        $stmt->execute([$start, $gestern]);
        [$minSpot, $maxSpot] = $stmt->fetch(PDO::FETCH_NUM);

        $heuteTxt = number_format($heuteAvg, 1, ',', '.');
        if ($minSpot !== null && $heuteAvg < (float) $minSpot) {
            $auffaellig[] = "Heutiger Durchschnittspreis {$heuteTxt} ct/kWh — niedrigster seit " . EN_AUFFAELLIG_MONATE . " Monaten.";
        } elseif ($maxSpot !== null && $heuteAvg > (float) $maxSpot) {
            $auffaellig[] = "Heutiger Durchschnittspreis {$heuteTxt} ct/kWh — höchster seit " . EN_AUFFAELLIG_MONATE . " Monaten.";
        }
    }

    $stmt = $pdo->prepare("SELECT consumed_kwh FROM daily_summary WHERE day = ?");
    $stmt->execute([$gestern]);
    $gesternRow = $stmt->fetchColumn();
    $gesternKwh = $gesternRow !== false ? (float) $gesternRow : null;

    if ($gesternKwh !== null) {
        $stmt = $pdo->prepare("SELECT DATE_SUB(?, INTERVAL 31 DAY)");
        $stmt->execute([$gestern]);
        $baseStart = $stmt->fetchColumn();
        $stmt = $pdo->prepare(
            "SELECT AVG(consumed_kwh) FROM daily_summary WHERE day > ? AND day < ?"
        );
        $stmt->execute([$baseStart, $gestern]);
        $baseAvgRow = $stmt->fetchColumn();
        $baseAvg    = $baseAvgRow !== null ? (float) $baseAvgRow : null;

        if ($baseAvg !== null && $baseAvg > 0.0) {
            $kwhTxt = number_format($gesternKwh, 1, ',', '.');
            $avgTxt = number_format($baseAvg, 1, ',', '.');
            if ($gesternKwh > $baseAvg * EN_STARKVERBRAUCH_FAKTOR) {
                $auffaellig[] = "Verbrauch gestern {$kwhTxt} kWh, deutlich über dem Üblichen (Ø {$avgTxt} kWh).";
            } elseif ($gesternKwh < $baseAvg * EN_AUFFAELLIG_VERBRAUCH_NIEDRIG) {
                $auffaellig[] = "Verbrauch gestern {$kwhTxt} kWh, deutlich unter dem Üblichen (Ø {$avgTxt} kWh).";
            }
        }
    }

    return $auffaellig;
}

/**
 * en_wetter_template() — deterministischer 2–4-Satz-Bericht aus Satzbausteinen
 * (Fallback ohne KI, s. Spec §1). Null-Felder werden übersprungen; völlig leere
 * Fakten liefern einen neutralen Hinweistext (nie leerer String).
 */
function en_wetter_template(array $fakten): string {
    $saetze = [];

    $delta = $fakten['verbrauch']['delta_pct'] ?? null;
    if ($delta !== null) {
        $pct = abs((int) round($delta * 100));
        $saetze[] = $delta < 0
            ? "Dein Verbrauch lag in den letzten 30 Tagen {$pct} % unter deinem üblichen Niveau."
            : "Dein Verbrauch lag in den letzten 30 Tagen {$pct} % über deinem üblichen Niveau.";
    }

    $gap = $fakten['disziplin']['gap_pct'] ?? null;
    if ($gap !== null) {
        $pct = abs((int) round($gap * 100));
        $bewertung = $fakten['disziplin']['bewertung'] ?? 'neutral';
        if ($bewertung === 'gut') {
            $saetze[] = "Deine Lastverschiebung zahlt sich aus: dein gewichteter Preis liegt {$pct} % unter dem einfachen Durchschnitt.";
        } elseif ($bewertung === 'unguenstig') {
            $saetze[] = "Deine Lastverschiebung könnte besser laufen: dein gewichteter Preis liegt {$pct} % über dem einfachen Durchschnitt.";
        } else {
            $saetze[] = "Deine Lastverschiebung liegt im neutralen Bereich.";
        }
    }

    $avg  = $fakten['heute']['avg']   ?? null;
    $maxH = $fakten['heute']['max_h'] ?? null;
    $max  = $fakten['heute']['max']   ?? null;
    if ($avg !== null) {
        $vorschau = $fakten['vorschau'] ?? false;
        $lead     = $vorschau ? 'Vorschau auf morgen: der Strompreis liegt' : 'Heute liegt der Strompreis';
        $avgTxt   = number_format($avg, 1, ',', '.');
        if ($max !== null && $maxH !== null) {
            $maxTxt = number_format($max, 1, ',', '.');
            $saetze[] = "{$lead} im Schnitt bei {$avgTxt} ct/kWh, mit einer Spitze um {$maxH} Uhr ({$maxTxt} ct/kWh).";
        } else {
            $saetze[] = "{$lead} im Schnitt bei {$avgTxt} ct/kWh.";
        }
    }

    $von  = $fakten['heute']['guenstig_von']  ?? null;
    $bis  = $fakten['heute']['guenstig_bis']  ?? null;
    $gAvg = $fakten['heute']['guenstig_avg']  ?? null;
    if ($von !== null && $bis !== null && $gAvg !== null) {
        $gTxt = number_format($gAvg, 1, ',', '.');
        $saetze[] = "Am günstigsten ist es zwischen {$von} und {$bis} Uhr (Ø {$gTxt} ct/kWh).";
    }

    if (!$saetze) {
        return "Für die aktuelle Periode liegen noch nicht genug Daten für einen Wetterbericht vor.";
    }

    return implode(' ', $saetze);
}

// ── Cache + Off-Path-Regeneration (Spec §3) ─────────────────────────────────

/** Default-Pfad des Wetterbericht-Caches (`data/wetterbericht.json`). */
function en_wetter_cache_pfad(): string {
    return dirname(__DIR__) . '/data/wetterbericht.json';
}

/** Neutrale, DB-lose Fakten (aller Werte null/leer) — Notanker wenn keine DB verfügbar ist. */
function en_wetter_fakten_leer(): array {
    return [
        'verbrauch' => ['ist_kwh' => 0.0, 'basis_kwh' => 0.0, 'delta_pct' => null],
        'disziplin' => ['gew' => null, 'einfach' => null, 'gap_pct' => null, 'bewertung' => 'neutral'],
        'heute'     => [
            'datum' => date('Y-m-d'), 'avg' => null, 'max' => null, 'max_h' => null,
            'min' => null, 'min_h' => null, 'spitzen' => [],
            'guenstig_von' => null, 'guenstig_bis' => null, 'guenstig_avg' => null,
        ],
        'vorschau'  => false,
    ];
}

/**
 * en_wetter_slot() — Budget-Slot-Kennung für die Zweimal-täglich-Grenze (14:00
 * lokal, s. docs/superpowers/specs/2026-07-17-wetterbericht-design.md /
 * TASK-6): 'Y-m-d#vor' vor 14:00, 'Y-m-d#nach' ab 14:00. $now ist injizierbar
 * (Tests); Produktiv-Aufrufer übergeben `new DateTimeImmutable()`.
 */
function en_wetter_slot(DateTimeImmutable $now): string {
    return $now->format('Y-m-d') . ((int) $now->format('H') < 14 ? '#vor' : '#nach');
}

/** Letzter Tag mit tatsächlichem Verbrauch (Muster wie web/index.php). */
function en_wetter_latest_tag(PDO $pdo): ?string {
    $latest = $pdo->query("SELECT MAX(day) FROM daily_summary WHERE consumed_kwh > 0")->fetchColumn();
    return ($latest !== false && $latest !== null) ? (string) $latest : null;
}

/** Liest+validiert den Cache; null wenn Datei fehlt oder Inhalt unbrauchbar ist. */
function en_wetter_cache_lesen(string $path): ?array {
    $raw = @file_get_contents($path);
    if ($raw === false) return null;

    $data = json_decode($raw, true);
    if (!is_array($data)) return null;
    if (!isset($data['datum'], $data['fakten'], $data['text'], $data['quelle'], $data['erzeugt_at'])) return null;

    return [
        'datum'      => (string) $data['datum'],
        // 'slot' fehlt in Alt-Caches (vor TASK-6) — '' weicht garantiert von
        // jedem echten Slot ab, löst also einmalig eine Regeneration aus.
        'slot'       => isset($data['slot']) ? (string) $data['slot'] : '',
        'fakten'     => (array) $data['fakten'],
        'text'       => (string) $data['text'],
        'quelle'     => (string) $data['quelle'],
        'erzeugt_at' => (string) $data['erzeugt_at'],
    ];
}

/** Schreibt den Cache atomar (tmp-Datei im selben Verzeichnis + rename). */
function en_wetter_cache_schreiben(string $path, array $cache): void {
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return;

    $json = json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) return;

    $tmp = $path . '.tmp' . getmypid() . '_' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $json) === false) return;
    if (!@rename($tmp, $path)) @unlink($tmp);
}

/**
 * en_wetter_lesen() — liest den Cache; existiert er (noch), wird er unverändert
 * zurückgegeben. Fehlt/ist er kaputt: sofort Fakten + Template berechnen (schnell,
 * deterministisch, KEIN Haiku-Call) — nie leer, nie blockierend (Spec §3).
 *
 * $pdo/$cachePath sind für Tests injizierbar; im Produktivpfad wird
 * `en_wetter_lesen()` ohne Argumente aufgerufen (Fallback auf das globale $pdo
 * aus inc/db.php, Fallback-Pfad auf den echten Cache).
 *
 * @return array{datum: string, fakten: array, text: string, quelle: string, erzeugt_at: string}
 */
function en_wetter_lesen(?PDO $pdo = null, ?string $cachePath = null): array {
    $path  = $cachePath ?? en_wetter_cache_pfad();
    $cache = en_wetter_cache_lesen($path);
    if ($cache !== null) return $cache;

    $pdo ??= $GLOBALS['pdo'] ?? null;
    $fakten = en_wetter_fakten_leer();
    $datum  = date('Y-m-d');

    if ($pdo instanceof PDO) {
        try {
            $latest = en_wetter_latest_tag($pdo) ?? $datum;
            $fakten = en_wetter_fakten($pdo, $latest);
            $datum  = $latest;
        } catch (Throwable) {
            // DB-Fehler: bei den neutralen Fakten bleiben, Template greift trotzdem.
        }
    }

    return [
        'datum'      => $datum,
        // Kein echter Cache da -> Slot bewusst '' (nie ein gültiger Slot),
        // damit der Aufrufer (web/index.php) einmalig eine Off-Path-Regeneration
        // anstößt statt den Template-Notanker dauerhaft zu servieren.
        'slot'       => '',
        'fakten'     => $fakten,
        'text'       => en_wetter_template($fakten),
        'quelle'     => 'template',
        'erzeugt_at' => date('c'),
    ];
}

/**
 * en_wetter_regenerieren() — berechnet frische Fakten (verankert auf den
 * letzten Tag mit Verbrauch), lässt Haiku formulieren (Fallback: Template),
 * und schreibt das Ergebnis atomar in den Cache. Läuft OFF-PATH (Import-Hook
 * oder `wetter-refresh`-Route), nie synchron beim Dashboard-Seitenaufbau.
 *
 * Budget (TASK-6): höchstens 2 Haiku-Aufrufe/Tag, an der 14:00-Grenze (s.
 * en_wetter_slot()). Vor 14:00 kein Vorschau-Profil (Morgen-Preise fehlen
 * meist); ab 14:00 Vorschau auf morgen, WENN für morgen bereits Readings
 * (Spotpreise) vorliegen — sonst Fallback auf das heutige Profil (Dev-Daten
 * ohne Zukunftspreise). In-Flight-Guard: liegt bereits ein Cache-Eintrag für
 * denselben Slot vor (z. B. zwei fast gleichzeitige Loads), wird der
 * bestehende Cache unverändert zurückgegeben — kein zweiter Haiku-Call.
 *
 * $cachePath/$now sind für Tests injizierbar (Default: echter Cache-Pfad
 * bzw. echte Uhrzeit).
 *
 * @return array{datum: string, slot: string, fakten: array, text: string, quelle: string, erzeugt_at: string}
 */
function en_wetter_regenerieren(PDO $pdo, array $cfg, ?string $cachePath = null, ?DateTimeImmutable $now = null): array {
    $path = $cachePath ?? en_wetter_cache_pfad();
    $now  ??= new DateTimeImmutable();
    $slot = en_wetter_slot($now);

    // In-Flight-Guard: jemand hat für diesen Slot gerade erst regeneriert
    // (billiger optimistischer Check; das atomare Rename schützt ohnehin vor
    // Korruption, hier geht es nur um doppelte Haiku-Bestellungen).
    $vorhanden = en_wetter_cache_lesen($path);
    if ($vorhanden !== null && $vorhanden['slot'] === $slot) {
        return $vorhanden;
    }

    $latest = en_wetter_latest_tag($pdo) ?? date('Y-m-d');

    $profilTag = $latest;
    $vorschau  = false;
    if (str_ends_with($slot, '#nach')) {
        $morgen = date('Y-m-d', strtotime($latest . ' +1 day'));
        if (en_wetter_hat_readings($pdo, $morgen)) {
            $profilTag = $morgen;
            $vorschau  = true;
        }
    }

    $fakten = en_wetter_fakten($pdo, $latest, $profilTag, $vorschau);

    $haikuText = en_haiku_wetter($fakten, $cfg);
    $text      = $haikuText ?? en_wetter_template($fakten);
    $quelle    = $haikuText !== null ? 'haiku' : 'template';

    $cache = [
        'datum'      => $latest,
        'slot'       => $slot,
        'fakten'     => $fakten,
        'text'       => $text,
        'quelle'     => $quelle,
        'erzeugt_at' => date('c'),
    ];

    en_wetter_cache_schreiben($path, $cache);

    return $cache;
}
