<?php
// PHPUnit bootstrap — loads Composer autoload and any inc/ helpers
// the tests want to exercise. Kept small on purpose: inc/initialize.php
// has side effects (session, headers, MySQLi) that tests should not
// trigger; require specific files instead.

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../inc/config.php';
require_once __DIR__ . '/../../inc/csv_parser.php';
