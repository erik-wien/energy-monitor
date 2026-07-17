<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../../inc/wetter.php';

/**
 * Tests for inc/wetter.php — Fakten-Engine + Template des Dashboard-„Wetterberichts"
 * (s. docs/superpowers/specs/2026-07-17-wetterbericht-design.md §1/§7).
 *
 * DB-gestützte Tests (Fixtures in daily_summary/readings/tariff_config) skippen
 * ohne erreichbare energie_test-DB, s. ENERGIE_DEV_INI/ENERGIE_TEST_DB.
 */
final class WetterTest extends TestCase {
    private const DEV_INI_ENV = 'ENERGIE_DEV_INI';
    private const DEFAULT_INI = '/opt/homebrew/etc/energie-config-dev.ini';
    private const TEST_DB_ENV = 'ENERGIE_TEST_DB';
    private const DEFAULT_DB  = 'energie_test';

    private ?PDO $pdo = null;

    private ?string $tmpCachePath = null;

    protected function setUp(): void {
        $this->pdo = $this->connectTestDb();
        $this->applySchema();
        $this->truncateAll();
    }

    protected function tearDown(): void {
        if ($this->tmpCachePath !== null) {
            $dir = dirname($this->tmpCachePath);
            @unlink($this->tmpCachePath);
            foreach (glob($dir . '/*.tmp*') ?: [] as $leftover) {
                @unlink($leftover);
            }
            @rmdir($dir);
        }
    }

    // ── en_wetter_verbrauch / en_wetter_fakten ──────────────────────────────

    public function test_verbrauch_voll_alle_felder_korrekt(): void {
        // gestern + w7-Fenster (day > 2026-07-09 und <= 2026-07-16).
        $this->seedTag('2026-07-16', 5.0, 12.0);  // gestern
        $this->seedTag('2026-07-15', 3.0, 12.0);  // w7
        $this->seedTag('2026-07-01', 4.0, 12.0);  // d30, nicht w7
        // w7-Vorjahr-Fenster (day > 2025-07-09 und <= 2025-07-16) UND zugleich
        // im üblich-Jahr-1-Block (day > 2025-06-16 und <= 2025-07-16).
        $this->seedTag('2025-07-14', 2.0, 12.0);
        // nur im üblich-Jahr-1-Block.
        $this->seedTag('2025-06-20', 6.0, 12.0);
        // üblich-Jahr-2-Block (day > 2024-06-16 und <= 2024-07-16).
        $this->seedTag('2024-07-01', 10.0, 12.0);
        // Trend-Vorblock (day > 2026-05-17 und <= 2026-06-16).
        $this->seedTag('2026-06-01', 3.0, 12.0);

        $fakten = en_wetter_verbrauch($this->pdo, '2026-07-16');

        $this->assertEqualsWithDelta(5.0, $fakten['gestern_kwh'], 0.001);
        $this->assertEqualsWithDelta(8.0, $fakten['w7_kwh'], 0.001);          // 5+3
        $this->assertEqualsWithDelta(2.0, $fakten['w7_vorjahr_kwh'], 0.001);
        $this->assertEqualsWithDelta(8.0 / 2.0 - 1.0, $fakten['w7_yoy_pct'], 0.001);
        $this->assertEqualsWithDelta(12.0, $fakten['d30_kwh'], 0.001);        // 5+3+4
        $this->assertEqualsWithDelta(9.0, $fakten['d30_ueblich_kwh'], 0.001); // avg(8,10)
        $this->assertEqualsWithDelta(12.0 / 9.0 - 1.0, $fakten['d30_delta_pct'], 0.001);
        $this->assertSame('steigend', $fakten['trend']); // 12/3-1 = +300 %
    }

    public function test_verbrauch_ohne_historie_alle_null_pfade(): void {
        $fakten = en_wetter_verbrauch($this->pdo, '2026-07-16');

        $this->assertNull($fakten['gestern_kwh']);
        $this->assertEqualsWithDelta(0.0, $fakten['w7_kwh'], 0.001);
        $this->assertNull($fakten['w7_vorjahr_kwh']);
        $this->assertNull($fakten['w7_yoy_pct']);
        $this->assertEqualsWithDelta(0.0, $fakten['d30_kwh'], 0.001);
        $this->assertNull($fakten['d30_ueblich_kwh']);
        $this->assertNull($fakten['d30_delta_pct']);
        $this->assertNull($fakten['trend']);
    }

    public function test_verbrauch_trend_steigend_und_fallend(): void {
        // Szenario steigend: d30-Tag trägt 105,1 kWh, Vorblock-Tag 100 kWh -> +5,1 %.
        $this->seedTag('2020-01-30', 105.1, 12.0);
        $this->seedTag('2019-12-30', 100.0, 12.0); // gestern - 31 Tage
        $this->assertSame('steigend', en_wetter_verbrauch($this->pdo, '2020-01-30')['trend']);

        // Szenario fallend: 94,9 vs 100 -> -5,1 %.
        $this->seedTag('2021-01-30', 94.9, 12.0);
        $this->seedTag('2020-12-30', 100.0, 12.0);
        $this->assertSame('fallend', en_wetter_verbrauch($this->pdo, '2021-01-30')['trend']);
    }

    public function test_verbrauch_trend_stabil_knapp_unter_der_5_prozent_grenze(): void {
        // Knapp innerhalb von ±5 % (4,9 %) muss 'stabil' bleiben — testet die
        // strikte "> Schwelle" / "< -Schwelle"-Grenze ohne Fließkomma-Randfälle
        // bei einer exakten 5,0-%-Quote.
        $this->seedTag('2022-01-30', 104.9, 12.0);
        $this->seedTag('2021-12-30', 100.0, 12.0);
        $this->assertSame('stabil', en_wetter_verbrauch($this->pdo, '2022-01-30')['trend']);

        $this->seedTag('2023-01-30', 95.1, 12.0);
        $this->seedTag('2022-12-30', 100.0, 12.0);
        $this->assertSame('stabil', en_wetter_verbrauch($this->pdo, '2023-01-30')['trend']);
    }

    public function test_disziplin_starkverbraucher_bei_teuren_stunden_unguenstig(): void {
        // 9 Stunden; drei Starkverbraucher-Stunden (kwh > Tages-Ø·1,5) liegen
        // ausgerechnet bei den teuersten Spotpreisen des Tages (oberes Terzil).
        $kwh  = [0 => 1.0, 1 => 1.0, 2 => 1.0, 3 => 1.0, 4 => 1.0, 5 => 5.0, 6 => 8.0, 7 => 1.0, 8 => 6.0];
        $spot = [0 => 5.0, 1 => 6.0, 2 => 7.0, 3 => 10.0, 4 => 11.0, 5 => 12.0, 6 => 20.0, 7 => 21.0, 8 => 22.0];
        foreach ($kwh as $h => $v) {
            $this->seedReading(sprintf('2026-07-16 %02d:00:00', $h), $v, $spot[$h]);
        }

        $fakten = en_wetter_disziplin($this->pdo, '2026-07-16');

        $this->assertCount(3, $fakten['laeufe']);
        // Absteigend nach kwh: Stunde 6 (8,0), Stunde 8 (6,0), Stunde 5 (5,0).
        $this->assertSame(6, $fakten['laeufe'][0]['stunde']);
        $this->assertEqualsWithDelta(8.0, $fakten['laeufe'][0]['kwh'], 0.001);
        $this->assertEqualsWithDelta(20.0, $fakten['laeufe'][0]['spot_ct'], 0.001);
        $this->assertSame('teuer', $fakten['laeufe'][0]['lage']); // oberstes Terzil (20/21/22)
        $this->assertSame(8, $fakten['laeufe'][1]['stunde']);
        $this->assertSame('teuer', $fakten['laeufe'][1]['lage']);
        $this->assertSame(5, $fakten['laeufe'][2]['stunde']);
        $this->assertSame('mittel', $fakten['laeufe'][2]['lage']); // mittleres Terzil (10/11/12)
        $this->assertSame('unguenstig', $fakten['bewertung']);
    }

    public function test_disziplin_starkverbraucher_bei_guenstigen_stunden_gut(): void {
        // Gleiches kwh-Muster wie oben, aber Spotpreise gespiegelt: die drei
        // Starkverbraucher-Stunden liegen jetzt im günstigen Terzil.
        $kwh  = [0 => 1.0, 1 => 1.0, 2 => 1.0, 3 => 1.0, 4 => 1.0, 5 => 5.0, 6 => 8.0, 7 => 1.0, 8 => 6.0];
        $spot = [0 => 22.0, 1 => 21.0, 2 => 20.0, 3 => 12.0, 4 => 11.0, 5 => 10.0, 6 => 7.0, 7 => 6.0, 8 => 5.0];
        foreach ($kwh as $h => $v) {
            $this->seedReading(sprintf('2026-07-16 %02d:00:00', $h), $v, $spot[$h]);
        }

        $fakten = en_wetter_disziplin($this->pdo, '2026-07-16');

        $this->assertCount(3, $fakten['laeufe']);
        $this->assertSame(6, $fakten['laeufe'][0]['stunde']);
        $this->assertSame('guenstig', $fakten['laeufe'][0]['lage']); // Terzil (5/6/7)
        $this->assertSame(8, $fakten['laeufe'][1]['stunde']);
        $this->assertSame('guenstig', $fakten['laeufe'][1]['lage']);
        $this->assertSame(5, $fakten['laeufe'][2]['stunde']);
        $this->assertSame('mittel', $fakten['laeufe'][2]['lage']);
        $this->assertSame('gut', $fakten['bewertung']);
    }

    public function test_disziplin_starkverbraucher_ausgeglichen_neutral(): void {
        // Wie oben, aber Spotpreise so verteilt, dass unter den drei Läufen
        // genau 1× guenstig, 1× teuer, 1× mittel steht -> Unentschieden -> neutral.
        $kwh  = [0 => 1.0, 1 => 1.0, 2 => 1.0, 3 => 1.0, 4 => 1.0, 5 => 5.0, 6 => 8.0, 7 => 1.0, 8 => 6.0];
        $spot = [0 => 6.0, 1 => 7.0, 2 => 10.0, 3 => 12.0, 4 => 20.0, 5 => 11.0, 6 => 22.0, 7 => 21.0, 8 => 5.0];
        foreach ($kwh as $h => $v) {
            $this->seedReading(sprintf('2026-07-16 %02d:00:00', $h), $v, $spot[$h]);
        }

        $fakten = en_wetter_disziplin($this->pdo, '2026-07-16');

        $this->assertCount(3, $fakten['laeufe']);
        $this->assertSame(6, $fakten['laeufe'][0]['stunde']);
        $this->assertSame('teuer', $fakten['laeufe'][0]['lage']);
        $this->assertSame(8, $fakten['laeufe'][1]['stunde']);
        $this->assertSame('guenstig', $fakten['laeufe'][1]['lage']);
        $this->assertSame(5, $fakten['laeufe'][2]['stunde']);
        $this->assertSame('mittel', $fakten['laeufe'][2]['lage']);
        $this->assertSame('neutral', $fakten['bewertung']);
    }

    public function test_disziplin_max_drei_laeufe_bei_mehr_kandidaten(): void {
        // 6 Basisstunden à 1 kWh + 4 Starkverbraucher-Spitzen (10/9/8/7 kWh);
        // alle vier liegen über Ø·1,5, aber es dürfen max. 3 Läufe zurückkommen
        // — die schwächste Spitze (7 kWh) fällt raus.
        foreach (range(0, 5) as $h) {
            $this->seedReading(sprintf('2026-07-16 %02d:00:00', $h), 1.0, 10.0);
        }
        $this->seedReading('2026-07-16 06:00:00', 10.0, 10.0);
        $this->seedReading('2026-07-16 07:00:00', 9.0, 10.0);
        $this->seedReading('2026-07-16 08:00:00', 8.0, 10.0);
        $this->seedReading('2026-07-16 09:00:00', 7.0, 10.0);

        $fakten = en_wetter_disziplin($this->pdo, '2026-07-16');

        $this->assertCount(3, $fakten['laeufe']);
        $this->assertSame([6, 7, 8], array_column($fakten['laeufe'], 'stunde'));
    }

    public function test_disziplin_ohne_starkverbraucher_leere_laeufe_bewertung_null(): void {
        // Flacher Verbrauch (alle Stunden gleich) -> keine Stunde > Ø·1,5.
        foreach (range(0, 5) as $h) {
            $this->seedReading(sprintf('2026-07-16 %02d:00:00', $h), 1.0, 10.0);
        }

        $fakten = en_wetter_disziplin($this->pdo, '2026-07-16');

        $this->assertSame([], $fakten['laeufe']);
        $this->assertNull($fakten['bewertung']);
    }

    public function test_disziplin_ohne_readings_leere_laeufe_bewertung_null(): void {
        // Readings existieren, aber nicht am $gestern-Tag.
        $this->seedReading('2026-07-15 12:00:00', 5.0, 10.0);

        $fakten = en_wetter_disziplin($this->pdo, '2026-07-16');

        $this->assertSame([], $fakten['laeufe']);
        $this->assertNull($fakten['bewertung']);
    }

    // ── en_wetter_heute ──────────────────────────────────────────────────────

    public function test_heute_guenstigstes_3h_fenster_ist_fenster_mit_min_avg(): void {
        // Stunden 10..14 mit V-förmigem Profil; das Minimum-Ø-Fenster liegt
        // NICHT am Rand, sondern bei 11-13 (avg ≈ 8,67), nicht 10-12 oder 12-14.
        $this->seedHourReading('2026-07-17', 10, 20.0);
        $this->seedHourReading('2026-07-17', 11, 15.0);
        $this->seedHourReading('2026-07-17', 12, 5.0);
        $this->seedHourReading('2026-07-17', 13, 6.0);
        $this->seedHourReading('2026-07-17', 14, 20.0);

        $heute = en_wetter_heute($this->pdo, '2026-07-17');

        $this->assertSame(11, $heute['guenstig_von']);
        $this->assertSame(14, $heute['guenstig_bis']);
        $this->assertEqualsWithDelta((15.0 + 5.0 + 6.0) / 3.0, $heute['guenstig_avg'], 0.001);

        $this->assertEqualsWithDelta((20.0 + 15.0 + 5.0 + 6.0 + 20.0) / 5.0, $heute['avg'], 0.001);
        $this->assertEqualsWithDelta(20.0, $heute['max'], 0.001);
        $this->assertSame(10, $heute['max_h']); // erster Treffer bei Gleichstand (10 und 14 je 20,0)
        $this->assertEqualsWithDelta(5.0, $heute['min'], 0.001);
        $this->assertSame(12, $heute['min_h']);
        $this->assertSame([10, 14], $heute['spitzen']); // > avg*1.25 = 16.5
    }

    public function test_heute_leerer_tag_alle_felder_null(): void {
        // Readings existieren, aber nicht am latest-Tag.
        $this->seedHourReading('2026-07-16', 12, 10.0);

        $heute = en_wetter_heute($this->pdo, '2026-07-17');

        $this->assertSame('2026-07-17', $heute['datum']);
        $this->assertNull($heute['avg']);
        $this->assertNull($heute['max']);
        $this->assertNull($heute['max_h']);
        $this->assertNull($heute['min']);
        $this->assertNull($heute['min_h']);
        $this->assertSame([], $heute['spitzen']);
        $this->assertNull($heute['guenstig_von']);
        $this->assertNull($heute['guenstig_bis']);
        $this->assertNull($heute['guenstig_avg']);
    }

    public function test_wetter_fakten_buendelt_alle_drei_bloecke(): void {
        $this->seedTag('2026-07-01', 10.0, 12.0);
        $this->seedHourReading('2026-07-17', 12, 10.0);

        $fakten = en_wetter_fakten($this->pdo, '2026-07-17');

        $this->assertArrayHasKey('verbrauch', $fakten);
        $this->assertArrayHasKey('disziplin', $fakten);
        $this->assertArrayHasKey('heute', $fakten);
        $this->assertSame('2026-07-17', $fakten['heute']['datum']);
    }

    // ── en_wetter_symbol ─────────────────────────────────────────────────────

    public function test_symbol_sonne_wenn_max_bis_1_4x_avg(): void {
        $this->assertSame('sonne', en_wetter_symbol(['avg' => 10.0, 'max' => 14.0])); // genau Grenze (<=)
        $this->assertSame('sonne', en_wetter_symbol(['avg' => 10.0, 'max' => 10.0]));
    }

    public function test_symbol_wolke_im_mittleren_bereich(): void {
        $this->assertSame('wolke', en_wetter_symbol(['avg' => 10.0, 'max' => 15.0]));
        $this->assertSame('wolke', en_wetter_symbol(['avg' => 10.0, 'max' => 20.0])); // genau Grenze (nicht > , also nicht gewitter)
    }

    public function test_symbol_gewitter_wenn_max_ueber_2x_avg(): void {
        $this->assertSame('gewitter', en_wetter_symbol(['avg' => 10.0, 'max' => 20.01]));
    }

    public function test_symbol_leeres_profil_wolke(): void {
        $this->assertSame('wolke', en_wetter_symbol(['avg' => null, 'max' => null]));
    }

    // ── en_wetter_preis ──────────────────────────────────────────────────────

    public function test_preis_heute_und_morgen_profile_plus_vergleiche(): void {
        // heute-Profil (readings): Ø 10 ct/kWh, Spitze 20 (max/avg=2.0 -> nicht > 2.0 -> wolke).
        $this->seedHourReading('2026-07-17', 8, 5.0);
        $this->seedHourReading('2026-07-17', 12, 20.0);
        // morgen-Profil.
        $this->seedHourReading('2026-07-18', 12, 8.0);
        // 30-Tage-Historie bis inkl. gestern (2026-07-16): Ø 8 ct/kWh.
        $this->seedTag('2026-07-16', 1.0, 8.0);
        $this->seedTag('2026-07-15', 1.0, 8.0);
        // Vorjahres-Fenster (2026-07-17 -1 Jahr = 2025-07-17, ±3 Tage): Ø 15 ct/kWh.
        $this->seedTag('2025-07-16', 1.0, 15.0);

        $preis = en_wetter_preis($this->pdo, '2026-07-17', '2026-07-18');

        $this->assertSame('2026-07-17', $preis['heute']['datum']);
        $this->assertEqualsWithDelta((5.0 + 20.0) / 2.0, $preis['heute']['avg'], 0.001); // 12,5
        $this->assertNotNull($preis['morgen']);
        $this->assertSame('2026-07-18', $preis['morgen']['datum']);
        $this->assertEqualsWithDelta(8.0, $preis['morgen']['avg'], 0.001);

        $this->assertEqualsWithDelta(12.5 / 8.0 - 1.0, $preis['heute_vs_ueblich_pct'], 0.001);
        $this->assertEqualsWithDelta(12.5 / 15.0 - 1.0, $preis['heute_vs_vorjahr_pct'], 0.001);
        $this->assertSame(en_wetter_symbol($preis['heute']), $preis['symbol']);
    }

    public function test_preis_ohne_morgen_param_morgen_null(): void {
        $this->seedHourReading('2026-07-17', 12, 10.0);

        $preis = en_wetter_preis($this->pdo, '2026-07-17', null);

        $this->assertNull($preis['morgen']);
    }

    public function test_preis_ohne_historie_vergleiche_null(): void {
        $this->seedHourReading('2026-07-17', 12, 10.0);

        $preis = en_wetter_preis($this->pdo, '2026-07-17', null);

        $this->assertNull($preis['heute_vs_ueblich_pct']);
        $this->assertNull($preis['heute_vs_vorjahr_pct']);
    }

    // ── en_wetter_auffaelligkeiten ───────────────────────────────────────────

    public function test_auffaelligkeiten_hoher_verbrauch_gestern(): void {
        // Baseline (30 T vor gestern, exkl. gestern): Ø 2,0 kWh. Gestern: 5,0 kWh (> Ø·1,5).
        $this->seedTag('2026-07-01', 2.0, 10.0);
        $this->seedTag('2026-07-02', 2.0, 10.0);
        $this->seedTag('2026-07-16', 5.0, 10.0);

        $auffaellig = en_wetter_auffaelligkeiten($this->pdo, '2026-07-16', ['avg' => null, 'max' => null]);

        $this->assertCount(1, $auffaellig);
        $this->assertStringContainsString('5', $auffaellig[0]);
        $this->assertStringContainsString('kWh', $auffaellig[0]);
        $this->assertStringContainsString('über', $auffaellig[0]);
    }

    public function test_auffaelligkeiten_niedriger_verbrauch_gestern(): void {
        // Baseline Ø 2,0 kWh, gestern 0,5 kWh (< Ø·0,5 = 1,0).
        $this->seedTag('2026-07-01', 2.0, 10.0);
        $this->seedTag('2026-07-02', 2.0, 10.0);
        $this->seedTag('2026-07-16', 0.5, 10.0);

        $auffaellig = en_wetter_auffaelligkeiten($this->pdo, '2026-07-16', ['avg' => null, 'max' => null]);

        $this->assertCount(1, $auffaellig);
        $this->assertStringContainsString('unter', $auffaellig[0]);
    }

    public function test_auffaelligkeiten_niedrigster_preis_seit_n_monaten(): void {
        // 3-Monats-Fenster bis inkl. gestern: Minimum 8 ct/kWh. Heute (übergeben
        // via $preisHeute) liegt mit 4 ct/kWh deutlich darunter.
        $this->seedTag('2026-06-01', 1.0, 8.0);
        $this->seedTag('2026-07-01', 1.0, 12.0);

        $auffaellig = en_wetter_auffaelligkeiten($this->pdo, '2026-07-16', ['avg' => 4.0, 'max' => 4.0]);

        $this->assertCount(1, $auffaellig);
        $this->assertStringContainsString('4', $auffaellig[0]);
        $this->assertStringContainsString('ct/kWh', $auffaellig[0]);
        $this->assertStringContainsString('niedrigster', $auffaellig[0]);
    }

    public function test_auffaelligkeiten_hoechster_preis_seit_n_monaten(): void {
        $this->seedTag('2026-06-01', 1.0, 8.0);
        $this->seedTag('2026-07-01', 1.0, 12.0);

        $auffaellig = en_wetter_auffaelligkeiten($this->pdo, '2026-07-16', ['avg' => 20.0, 'max' => 20.0]);

        $this->assertCount(1, $auffaellig);
        $this->assertStringContainsString('höchster', $auffaellig[0]);
    }

    public function test_auffaelligkeiten_nichts_bemerkenswertes_leer(): void {
        $this->seedTag('2026-07-01', 2.0, 10.0);
        $this->seedTag('2026-07-16', 2.1, 10.0); // nahe Ø, keine Starkverbrauch-Auffälligkeit

        $auffaellig = en_wetter_auffaelligkeiten($this->pdo, '2026-07-16', ['avg' => 10.0, 'max' => 10.0]);

        $this->assertSame([], $auffaellig);
    }

    public function test_auffaelligkeiten_ohne_jegliche_historie_leer(): void {
        $auffaellig = en_wetter_auffaelligkeiten($this->pdo, '2026-07-16', ['avg' => null, 'max' => null]);

        $this->assertSame([], $auffaellig);
    }

    // ── en_wetter_lesen / en_wetter_regenerieren (Cache + Off-Path, Spec §3) ──
    //
    // $pdo/$cachePath sind bei beiden Funktionen injizierbar (Default: globales
    // $pdo bzw. der echte data/wetterbericht.json-Pfad) — hier immer ein
    // frischer Temp-Pfad, damit kein echter Cache berührt wird.

    private function tmpCachePfad(): string {
        $dir = sys_get_temp_dir() . '/wetter_test_' . uniqid('', true);
        $this->tmpCachePath = $dir . '/wetterbericht.json';
        return $this->tmpCachePath;
    }

    public function test_lesen_ohne_cache_liefert_frisches_template(): void {
        $path = $this->tmpCachePfad(); // Datei existiert bewusst nicht.
        $this->seedTag('2026-07-01', 10.0, 12.0);

        $result = en_wetter_lesen($this->pdo, $path);

        $this->assertSame('template', $result['quelle']);
        // Kein echter Cache -> Slot bewusst '' (weicht von jedem echten Slot ab,
        // damit der Aufrufer einmalig eine Off-Path-Regeneration anstößt).
        $this->assertSame('', $result['slot']);
        $this->assertArrayHasKey('verbrauch', $result['fakten']);
        $this->assertArrayHasKey('disziplin', $result['fakten']);
        $this->assertArrayHasKey('heute', $result['fakten']);
        $this->assertNotSame('', $result['text']);
        $this->assertIsString($result['datum']);
        $this->assertIsString($result['erzeugt_at']);
    }

    public function test_lesen_mit_vorhandenem_cache_liefert_ihn_unveraendert(): void {
        $path = $this->tmpCachePfad();
        mkdir(dirname($path), 0755, true);
        $vorhanden = [
            'datum'      => '2026-07-16',
            'slot'       => '2026-07-16#nach',
            'fakten'     => ['verbrauch' => ['ist_kwh' => 1.0, 'basis_kwh' => 2.0, 'delta_pct' => -0.5]],
            'text'       => 'Gestriger Bericht.',
            'quelle'     => 'haiku',
            'erzeugt_at' => '2026-07-16T08:00:00+00:00',
        ];
        file_put_contents($path, json_encode($vorhanden, JSON_UNESCAPED_UNICODE));

        // $pdo wird nicht gebraucht, wenn der Cache existiert — trotzdem
        // übergeben, um zu zeigen, dass er dann ignoriert wird.
        $result = en_wetter_lesen($this->pdo, $path);

        $this->assertEquals($vorhanden, $result);
    }

    public function test_regenerieren_schreibt_gueltigen_cache_atomar(): void {
        $path = $this->tmpCachePfad(); // Verzeichnis existiert bewusst noch nicht.
        $this->seedTag('2026-07-01', 10.0, 12.0);
        $this->seedHourReading('2026-07-01', 12, 10.0);

        // Leere ai-Config → en_haiku_wetter liefert sofort null → Template-Fallback.
        $now    = new DateTimeImmutable('2026-07-01 09:00:00');
        $result = en_wetter_regenerieren($this->pdo, [], $path, $now);

        $this->assertFileExists($path);
        $onDisk = json_decode(file_get_contents($path), true);
        $this->assertEquals($result, $onDisk);
        $this->assertSame('template', $onDisk['quelle']);
        $this->assertNotSame('', $onDisk['text']);
        $this->assertArrayHasKey('disziplin', $onDisk['fakten']);
        $this->assertSame('2026-07-01', $onDisk['datum']);
        $this->assertSame('2026-07-01#vor', $onDisk['slot']);

        // Atomarer Write (tmp-Datei + rename): keine liegen gebliebene tmp-Datei.
        $this->assertSame([], glob(dirname($path) . '/*.tmp*') ?: []);
    }

    public function test_regenerieren_robust_bei_leeren_daten(): void {
        $path = $this->tmpCachePfad(); // Keine Readings/daily_summary vorhanden.

        $result = en_wetter_regenerieren($this->pdo, [], $path);

        $this->assertFileExists($path);
        $this->assertSame('template', $result['quelle']);
        $this->assertNotSame('', $result['text']);
        $this->assertNull($result['fakten']['disziplin']['gap_pct']);
    }

    // ── en_wetter_slot / Budget (TASK-6: max. 2 Haiku-Aufrufe/Tag) ───────────

    public function test_slot_vor_14_uhr(): void {
        $this->assertSame('2026-07-17#vor', en_wetter_slot(new DateTimeImmutable('2026-07-17 00:00:00')));
        $this->assertSame('2026-07-17#vor', en_wetter_slot(new DateTimeImmutable('2026-07-17 13:59:59')));
    }

    public function test_slot_ab_14_uhr(): void {
        $this->assertSame('2026-07-17#nach', en_wetter_slot(new DateTimeImmutable('2026-07-17 14:00:00')));
        $this->assertSame('2026-07-17#nach', en_wetter_slot(new DateTimeImmutable('2026-07-17 23:59:59')));
    }

    public function test_regenerieren_inflight_guard_gleicher_slot_ueberspringt(): void {
        // Simuliert zwei fast gleichzeitige Loads: der Cache trägt bereits den
        // aktuellen Slot -> en_wetter_regenerieren MUSS ihn unverändert
        // zurückgeben (kein zweiter Haiku-Call), obwohl frische DB-Daten da
        // sind, die eine andere Berechnung liefern würden.
        $path = $this->tmpCachePfad();
        mkdir(dirname($path), 0755, true);
        $now  = new DateTimeImmutable('2026-07-17 10:00:00');
        $slot = en_wetter_slot($now);
        $bereitsFrisch = [
            'datum'      => '2026-07-17',
            'slot'       => $slot,
            'fakten'     => ['irrelevant' => true],
            'text'       => 'Bereits frisch von einem parallelen Request.',
            'quelle'     => 'template',
            'erzeugt_at' => '2026-07-17T09:30:00+00:00',
        ];
        file_put_contents($path, json_encode($bereitsFrisch, JSON_UNESCAPED_UNICODE));

        $this->seedTag('2026-07-01', 10.0, 12.0); // würde bei echter Neuberechnung andere Fakten liefern

        $result = en_wetter_regenerieren($this->pdo, [], $path, $now);

        $this->assertEquals($bereitsFrisch, $result);
    }

    public function test_regenerieren_vor_14_kein_vorschau_auch_wenn_morgen_preise_da(): void {
        $path = $this->tmpCachePfad();
        $this->seedTag('2026-07-17', 10.0, 12.0);
        $this->seedHourReading('2026-07-18', 12, 15.0); // Morgen-Preise liegen vor
        $now = new DateTimeImmutable('2026-07-17 09:00:00'); // #vor

        $result = en_wetter_regenerieren($this->pdo, [], $path, $now);

        $this->assertSame('2026-07-17#vor', $result['slot']);
        $this->assertFalse($result['fakten']['vorschau']);
        $this->assertSame('2026-07-17', $result['fakten']['heute']['datum']);
    }

    public function test_regenerieren_nach_14_mit_morgen_preisen_vorschau(): void {
        $path = $this->tmpCachePfad();
        $this->seedTag('2026-07-17', 10.0, 12.0);
        $this->seedHourReading('2026-07-18', 12, 15.0); // Morgen-Preise liegen vor
        $now = new DateTimeImmutable('2026-07-17 15:00:00'); // #nach

        $result = en_wetter_regenerieren($this->pdo, [], $path, $now);

        $this->assertSame('2026-07-17#nach', $result['slot']);
        $this->assertTrue($result['fakten']['vorschau']);
        $this->assertSame('2026-07-18', $result['fakten']['heute']['datum']);
        $this->assertStringContainsString('Vorschau auf morgen', $result['text']);
    }

    public function test_regenerieren_nach_14_ohne_morgen_preise_fallback_heute(): void {
        $path = $this->tmpCachePfad();
        $this->seedTag('2026-07-17', 10.0, 12.0);
        $this->seedHourReading('2026-07-17', 12, 10.0); // nur heutige Preise, keine für morgen
        $now = new DateTimeImmutable('2026-07-17 15:00:00'); // #nach

        $result = en_wetter_regenerieren($this->pdo, [], $path, $now);

        $this->assertFalse($result['fakten']['vorschau']);
        $this->assertSame('2026-07-17', $result['fakten']['heute']['datum']);
        $this->assertStringNotContainsString('Vorschau auf morgen', $result['text']);
    }

    // ── en_wetter_template (reine Funktion, keine DB) ────────────────────────

    public function test_template_enthaelt_kernzahlen(): void {
        $fakten = [
            'verbrauch' => ['ist_kwh' => 190.0, 'basis_kwh' => 276.0, 'delta_pct' => -0.311],
            'disziplin' => ['gew' => 9.7, 'einfach' => 11.8, 'gap_pct' => -0.1805, 'bewertung' => 'gut'],
            'heute'     => [
                'datum' => '2026-07-17', 'avg' => 14.5, 'max' => 18.0, 'max_h' => 19,
                'min' => 9.8, 'min_h' => 13, 'spitzen' => [],
                'guenstig_von' => 12, 'guenstig_bis' => 15, 'guenstig_avg' => 10.1,
            ],
        ];

        $text = en_wetter_template($fakten);

        $this->assertNotSame('', $text);
        $this->assertStringContainsString('31 %', $text);              // Verbrauchs-Delta
        $this->assertStringContainsString('zwischen 12 und 15', $text); // günstig-Fenster
    }

    public function test_template_ueberspringt_null_felder(): void {
        $fakten = [
            'verbrauch' => ['ist_kwh' => 0.0, 'basis_kwh' => 0.0, 'delta_pct' => null],
            'disziplin' => ['gew' => null, 'einfach' => null, 'gap_pct' => null, 'bewertung' => 'neutral'],
            'heute'     => [
                'datum' => '2026-07-17', 'avg' => null, 'max' => null, 'max_h' => null,
                'min' => null, 'min_h' => null, 'spitzen' => [],
                'guenstig_von' => null, 'guenstig_bis' => null, 'guenstig_avg' => null,
            ],
        ];

        $text = en_wetter_template($fakten);

        $this->assertNotSame('', $text); // Neutraltext, kein leerer String
        $this->assertStringNotContainsString('%', $text);
    }

    public function test_template_leere_fakten_neutraltext(): void {
        $text = en_wetter_template([]);

        $this->assertIsString($text);
        $this->assertNotSame('', $text);
    }

    public function test_template_vorschau_auf_morgen(): void {
        $fakten = [
            'verbrauch' => ['ist_kwh' => 0.0, 'basis_kwh' => 0.0, 'delta_pct' => null],
            'disziplin' => ['gew' => null, 'einfach' => null, 'gap_pct' => null, 'bewertung' => 'neutral'],
            'heute'     => [
                'datum' => '2026-07-18', 'avg' => 14.5, 'max' => 18.0, 'max_h' => 19,
                'min' => 9.8, 'min_h' => 13, 'spitzen' => [],
                'guenstig_von' => null, 'guenstig_bis' => null, 'guenstig_avg' => null,
            ],
            'vorschau'  => true,
        ];

        $text = en_wetter_template($fakten);

        $this->assertStringContainsString('Vorschau auf morgen', $text);
    }

    public function test_template_ohne_vorschau_flag_heute_wortlaut(): void {
        $fakten = [
            'verbrauch' => ['ist_kwh' => 0.0, 'basis_kwh' => 0.0, 'delta_pct' => null],
            'disziplin' => ['gew' => null, 'einfach' => null, 'gap_pct' => null, 'bewertung' => 'neutral'],
            'heute'     => [
                'datum' => '2026-07-17', 'avg' => 14.5, 'max' => null, 'max_h' => null,
                'min' => null, 'min_h' => null, 'spitzen' => [],
                'guenstig_von' => null, 'guenstig_bis' => null, 'guenstig_avg' => null,
            ],
        ];

        $text = en_wetter_template($fakten);

        $this->assertStringContainsString('Heute liegt der Strompreis', $text);
        $this->assertStringNotContainsString('Vorschau auf morgen', $text);
    }

    // ── DB-Scaffolding (Muster wie InsightTest/CsvImporterTest) ─────────────

    private function connectTestDb(): PDO {
        $iniPath = getenv(self::DEV_INI_ENV) ?: self::DEFAULT_INI;
        if (!is_readable($iniPath)) {
            $this->markTestSkipped("dev ini not readable: {$iniPath}");
        }
        $cfg = parse_ini_file($iniPath, true);
        if (!is_array($cfg) || empty($cfg['db'])) {
            $this->markTestSkipped("dev ini has no [db] section: {$iniPath}");
        }
        $db   = $cfg['db'];
        $name = getenv(self::TEST_DB_ENV) ?: self::DEFAULT_DB;
        $dsn  = isset($db['socket'])
            ? "mysql:unix_socket={$db['socket']};dbname={$name};charset=utf8mb4"
            : "mysql:host=" . ($db['host'] ?? '127.0.0.1')
                . (isset($db['port']) ? ";port={$db['port']}" : '')
                . ";dbname={$name};charset=utf8mb4";
        try {
            return new PDO($dsn, $db['user'], $db['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (PDOException $e) {
            $this->markTestSkipped("energie_test not reachable ({$e->getMessage()}).");
        }
    }

    private function applySchema(): void {
        $schema = file_get_contents(__DIR__ . '/../../migrations/001_en_initial_schema.sql');
        foreach (array_filter(array_map('trim', explode(';', $schema))) as $stmt) {
            $this->pdo->exec($stmt);
        }
    }

    private function truncateAll(): void {
        foreach (['readings', 'daily_summary', 'tariff_config'] as $t) {
            $this->pdo->exec("TRUNCATE TABLE {$t}");
        }
    }

    /** Seed one daily_summary row (avg_spot_ct/cost_brutto are irrelevant to wetter.php, kept plausible). */
    private function seedTag(string $day, float $kwh, float $spotCt): void {
        $this->pdo->prepare(
            "INSERT INTO daily_summary (day, consumed_kwh, cost_brutto, avg_spot_ct) VALUES (?, ?, ?, ?)"
        )->execute([$day, $kwh, $kwh * $spotCt / 100, $spotCt]);
    }

    /** Seed one readings row at an exact timestamp (cost_brutto is irrelevant to wetter.php). */
    private function seedReading(string $ts, float $kwh, float $spotCt): void {
        $this->pdo->prepare(
            "INSERT INTO readings (ts, consumed_kwh, spot_ct, cost_brutto) VALUES (?, ?, ?, ?)"
        )->execute([$ts, $kwh, $spotCt, $kwh * $spotCt / 100]);
    }

    /** Seed one readings row for a given day+hour (fixed :00 minute, 1 kWh). */
    private function seedHourReading(string $day, int $hour, float $spotCt): void {
        $this->seedReading(sprintf('%s %02d:00:00', $day, $hour), 1.0, $spotCt);
    }
}
