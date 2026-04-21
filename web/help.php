<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/layout.php';
auth_require();

$uname = htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8');
?>
<?php render_page_head('Hilfe'); render_header('help'); ?>
<main id="main-content" tabindex="-1">
    <div class="pref-section">

        <div class="pref-card">
            <div class="pref-card-hdr">Was Energie macht</div>
            <div class="pref-card-body">
                <p>
                    Energie berechnet deine Stromkosten auf Basis der
                    <strong>viertelstündlichen Verbrauchswerte</strong> deines Netzbetreibers und der
                    <strong>Stundenspotpreise</strong> an der Börse (EPEX). Der angezeigte Preis enthält
                    alle Bestandteile der Endabrechnung: Energiepreis, Hofer&nbsp;Aufschlag,
                    Elektrizitäts- und Erneuerbaren-Abgaben, Zähler- und Netzgebühren,
                    Gebrauchsabgabe Wien und USt.
                </p>
                <p class="text-muted">
                    Grundlage ist der Hofer-Grünstrom-Spotpreistarif (Österreich). Die Tarifparameter
                    (Aufschläge, Abgaben, Steuersätze) liegen in der Tabelle <code>tariff_config</code>
                    und gelten jeweils ab dem eingetragenen Stichtag.
                </p>
            </div>
        </div>

        <div class="pref-card">
            <div class="pref-card-hdr">Die vier Ansichten</div>
            <div class="pref-card-body">
                <dl class="help-dl">
                    <dt>Aktuell</dt>
                    <dd>Ein einzelner Tag in Viertelstunden-Auflösung. Balken zeigen den Verbrauch,
                        die Linie den Spotpreis. Pfeile links/rechts oder Wischen wechseln den Tag.</dd>

                    <dt>Woche</dt>
                    <dd>Eine ISO-Kalenderwoche in Tagessummen. Praktisch für den Wochenvergleich.</dd>

                    <dt>Monat</dt>
                    <dd>Ein Kalendermonat in Tagessummen mit Monatssumme und Durchschnittspreis.</dd>

                    <dt>Jahr</dt>
                    <dd>Zwölf rollende Monate als Monatssummen. Gut für den Jahresüberblick und die
                        Rechnungskontrolle.</dd>
                </dl>
                <p class="text-muted">
                    In jeder Ansicht steht unten eine <strong>Rechnungs-Aufschlüsselung</strong>, die
                    zeigt, wie viel auf Energie, Aufschläge, Abgaben und USt entfällt.
                </p>
            </div>
        </div>

        <div class="pref-card">
            <div class="pref-card-hdr">Navigation und Bedienung</div>
            <div class="pref-card-body">
                <ul>
                    <li><strong>Datum wählen:</strong> Klick auf den Titel öffnet einen Kalender (Flatpickr).</li>
                    <li><strong>Klick auf einen Balken</strong> in der Wochen-, Monats- oder Jahresansicht
                        springt in die jeweils feinere Auflösung (Monat&nbsp;→&nbsp;Tag, usw.).</li>
                    <li><strong>Wischen</strong> auf Touchgeräten wechselt Tag, Woche, Monat oder Jahr.</li>
                    <li><strong>Light/Dark/Auto:</strong> Das Farbdesign stellst du unter
                        <em>Einstellungen</em> ein; Auto folgt deinem Betriebssystem.</li>
                </ul>
            </div>
        </div>

        <div class="pref-card">
            <div class="pref-card-hdr">Dein Konto</div>
            <div class="pref-card-body">
                <ul>
                    <li><a href="<?= $base ?>/preferences.php">Einstellungen</a> —
                        Profilbild, Design und E-Mail-Adresse.</li>
                    <li><a href="<?= $base ?>/security.php">Passwort &amp; 2FA</a> —
                        Passwort ändern und Zwei-Faktor-Authentifizierung (TOTP) aktivieren oder zurücksetzen.</li>
                    <li>Abmelden erfolgt über das Benutzer-Menü oben rechts.</li>
                </ul>
            </div>
        </div>

        <div class="pref-card">
            <div class="pref-card-hdr">Datenimport (nur Administratoren)</div>
            <div class="pref-card-body">
                <p>
                    Neue Verbrauchsdaten kommen als CSV-Datei vom Netzbetreiber (Viertelstundenwerte,
                    Semikolon-getrennt, deutsche Datums- und Dezimalformate). Der Importpfad ist:
                </p>
                <ol>
                    <li><strong>Datei ablegen</strong> — per „CSV hochladen" im Benutzer-Menü oder
                        manuell in den Ordner <code>scrapes/</code>.</li>
                    <li><strong>Vorschau</strong> — „Importieren" im Benutzer-Menü zeigt, wie viele
                        Datensätze neu sind und wie viele schon in der Datenbank stehen.</li>
                    <li><strong>Importieren</strong> — bestätigt den Lauf. Die Pipeline ruft
                        <code>energie.py&nbsp;import-csv</code> auf, verknüpft jede Viertelstunde mit
                        dem passenden Spotpreis und schreibt Verbrauch und Bruttokosten in die Tabelle
                        <code>readings</code>.</li>
                </ol>
            </div>
        </div>

        <div class="pref-card">
            <div class="pref-card-hdr">Kontakt</div>
            <div class="pref-card-body">
                <p>
                    Bei Fragen oder Fehlern:
                    <a href="mailto:contact@eriks.cloud">contact@eriks.cloud</a>
                </p>
            </div>
        </div>

    </div>
</main>
<?php render_footer(); ?>
