<?php
/** قراية مشتريات Redux بس. */
declare(strict_types=1);
define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();
api_require('ashraqa_purchases');
api_method('GET');
require_once __DIR__ . '/ashraqa_purchases_lib.php';
api_ok(ashraqa_purchases_read(db(), $_GET));
