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
 * verankert auf den Tag $latest (Format 'Y-m-d').
 *
 * @return array{
 *   verbrauch: array{ist_kwh: float, basis_kwh: float, delta_pct: ?float},
 *   disziplin: array{gew: ?float, einfach: ?float, gap_pct: ?float, bewertung: string},
 *   heute: array{datum: string, avg: ?float, max: ?float, max_h: ?int, min: ?float,
 *                min_h: ?int, spitzen: int[], guenstig_von: ?int, guenstig_bis: ?int,
 *                guenstig_avg: ?float}
 * }
 */
function en_wetter_fakten(PDO $pdo, string $latest): array {
    return [
        'verbrauch' => en_wetter_verbrauch($pdo, $latest),
        'disziplin' => en_wetter_disziplin($pdo, $latest),
        'heute'     => en_wetter_heute($pdo, $latest),
    ];
}

/**
 * Verbrauch der letzten 30 Tage (bis inkl. $latest) vs. Baseline: Ø Tagesverbrauch
 * der 90 Tage davor (latest-120 .. latest-30] × 30. basis<=0 → delta_pct=null.
 */
function en_wetter_verbrauch(PDO $pdo, string $latest): array {
    $stmt = $pdo->prepare(
        "SELECT SUM(consumed_kwh) FROM daily_summary
         WHERE day > DATE_SUB(?, INTERVAL 30 DAY) AND day <= ?"
    );
    $stmt->execute([$latest, $latest]);
    $ist = (float) ($stmt->fetchColumn() ?: 0.0);

    $stmt = $pdo->prepare(
        "SELECT AVG(consumed_kwh) FROM daily_summary
         WHERE day > DATE_SUB(?, INTERVAL 120 DAY) AND day <= DATE_SUB(?, INTERVAL 30 DAY)"
    );
    $stmt->execute([$latest, $latest]);
    $avgTag = $stmt->fetchColumn();
    $basis  = $avgTag !== null ? (float) $avgTag * 30 : 0.0;

    $delta = $basis > 0.0 ? ($ist / $basis - 1.0) : null;

    return ['ist_kwh' => $ist, 'basis_kwh' => $basis, 'delta_pct' => $delta];
}

/**
 * Lastdisziplin: gewichteter Spotpreis (Σ spot·kwh / Σkwh) vs. einfacher
 * Durchschnitt (AVG(spot)) der letzten 30 Tage (bis inkl. $latest) aus `readings`.
 * gap_pct = gew/einfach−1; bewertung: gap<−0,02 'gut', >0,02 'unguenstig', sonst 'neutral'.
 */
function en_wetter_disziplin(PDO $pdo, string $latest): array {
    $stmt = $pdo->prepare(
        "SELECT SUM(spot_ct * consumed_kwh) / NULLIF(SUM(consumed_kwh), 0) AS gew,
                AVG(spot_ct) AS einfach
         FROM readings
         WHERE ts > DATE_SUB(?, INTERVAL 30 DAY) AND ts < DATE_ADD(?, INTERVAL 1 DAY)"
    );
    $stmt->execute([$latest, $latest]);
    $row = $stmt->fetch();

    $gew     = $row['gew']     !== null ? (float) $row['gew']     : null;
    $einfach = $row['einfach'] !== null ? (float) $row['einfach'] : null;

    $gap = ($gew !== null && $einfach !== null && $einfach != 0.0)
        ? ($gew / $einfach - 1.0)
        : null;

    $bewertung = $gap === null ? 'neutral' : en_wetter_bewertung($gap);

    return ['gew' => $gew, 'einfach' => $einfach, 'gap_pct' => $gap, 'bewertung' => $bewertung];
}

/** Bewertungs-Schwellen für die gewichtet-vs-einfach-Spot-Lücke: gap<−0,02 'gut', >0,02 'unguenstig', sonst 'neutral'. */
function en_wetter_bewertung(float $gap): string {
    if ($gap < -0.02) return 'gut';
    if ($gap > 0.02)  return 'unguenstig';
    return 'neutral';
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
        $avgTxt = number_format($avg, 1, ',', '.');
        if ($max !== null && $maxH !== null) {
            $maxTxt = number_format($max, 1, ',', '.');
            $saetze[] = "Heute liegt der Strompreis im Schnitt bei {$avgTxt} ct/kWh, mit einer Spitze um {$maxH} Uhr ({$maxTxt} ct/kWh).";
        } else {
            $saetze[] = "Heute liegt der Strompreis im Schnitt bei {$avgTxt} ct/kWh.";
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
    ];
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
 * $cachePath ist für Tests injizierbar (Default: der echte Cache-Pfad).
 *
 * @return array{datum: string, fakten: array, text: string, quelle: string, erzeugt_at: string}
 */
function en_wetter_regenerieren(PDO $pdo, array $cfg, ?string $cachePath = null): array {
    $path = $cachePath ?? en_wetter_cache_pfad();

    $latest = en_wetter_latest_tag($pdo) ?? date('Y-m-d');
    $fakten = en_wetter_fakten($pdo, $latest);

    $haikuText = en_haiku_wetter($fakten, $cfg);
    $text      = $haikuText ?? en_wetter_template($fakten);
    $quelle    = $haikuText !== null ? 'haiku' : 'template';

    $cache = [
        'datum'      => $latest,
        'fakten'     => $fakten,
        'text'       => $text,
        'quelle'     => $quelle,
        'erzeugt_at' => date('c'),
    ];

    en_wetter_cache_schreiben($path, $cache);

    return $cache;
}
