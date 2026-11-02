<?php
/** الروابط القديمة لمرآة Redux. */
require_once __DIR__ . '/auth.php';
require_permission('ashraqa_purchases');
header('Location: ashraqa_purchases.php' . ($_GET ? '?' . http_build_query($_GET) : ''), true, 302);
exit;
