<?php
ob_start();
require_once __DIR__ . '/../inc/initialize.php';
ob_end_clean();
\Erikr\Chrome\Avatar::serve($con);
