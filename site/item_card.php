<?php
/**
 * item_card.php — تحويل لتقرير حركة الصنف (بريف ٢٢٦)
 * نظام الفجالة ستور المالي
 *
 * كارت الصنف الحالي اتنقل للتقارير باسم «حركة الصنف»،
 * واسم «كارت الصنف» هيفضى لموديول تعريف الأصناف الجديد (بريف ٢٢٧).
 * تحويل 302 مع الاحتفاظ بنفس الـquery string للروابط القديمة.
 */
declare(strict_types=1);

$params = $_GET;
unset($params['type']);
$query = http_build_query(array_merge(['type' => 'item_movement'], $params));
header('Location: reports.php?' . $query, true, 302);
exit;
