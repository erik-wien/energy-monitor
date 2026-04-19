<?php
require_once __DIR__ . '/../inc/initialize.php';
$_b = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Impressum · Energie</title>
    <link rel="stylesheet" href="<?= $_b ?>/styles/shared/theme.css">
    <link rel="stylesheet" href="<?= $_b ?>/styles/shared/reset.css">
    <link rel="stylesheet" href="<?= $_b ?>/styles/shared/layout.css">
    <link rel="stylesheet" href="<?= $_b ?>/styles/shared/components.css">
    <link rel="stylesheet" href="<?= $_b ?>/styles/energie-theme.css">
    <link rel="stylesheet" href="<?= $_b ?>/styles/energie.css">
    <link rel="icon" type="image/x-icon" href="<?= $_b ?>/assets/favicon.ico">
</head>
<body>
<header class="app-header">
    <div class="header-left">
        <a class="brand" href="<?= $_b ?>/">
            <img src="<?= $_b ?>/assets/jardyx.svg" class="header-logo" width="28" height="28" alt="">
            <span class="header-appname">Energie</span>
        </a>
    </div>
    <div class="header-right"></div>
</header>
<main id="main-content">
<div class="page-reading">
    <h1>Impressum</h1>
    <p>
        Erik R. Accart-Huemer<br>
        Böckhgasse 9/6/74<br>
        1120 Wien, Österreich
    </p>
    <p>E-Mail: <a href="mailto:contact@eriks.cloud">contact@eriks.cloud</a></p>
    <p class="text-muted">Angaben gemäß § 5 ECG</p>
    <p><a href="<?= $_b ?>/">← Zurück</a></p>
</div>
</main>
<?php \Erikr\Chrome\Footer::render(['base' => $_b]); ?>
</body>
</html>
