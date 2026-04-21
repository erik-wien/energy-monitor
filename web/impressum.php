<?php
require_once __DIR__ . '/../inc/initialize.php';
require_once __DIR__ . '/../inc/layout.php';
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
?>
<?php render_anon_header('Impressum'); ?>
<div class="page-reading">
    <h1>Impressum</h1>
    <p>
        Erik R. Accart-Huemer<br>
        Böckhgasse 9/6/74<br>
        1120 Wien, Österreich
    </p>
    <p>E-Mail: <a href="mailto:contact@eriks.cloud">contact@eriks.cloud</a></p>
    <p class="text-muted">Angaben gemäß § 5 ECG</p>
    <p><a href="<?= $base ?>/">← Zurück</a></p>
</div>
</main>
<?php render_footer(); ?>
