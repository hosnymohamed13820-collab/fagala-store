<?php
/**
 * nag_check.php — فحص دوري خفيف من الهيدر (كل دقيقتين)
 * نظام الفجالة ستور المالي
 *
 * بيرجّع عدد مهام النواقص المفتوحة المستنية المستخدم الحالي —
 * الهيدر بيستخدمه عشان "يزنّ" على الموظف اللي مقافل الطلب.
 * require_login بس (من غير صلاحية موديول) عشان يشتغل لأي مستخدم.
 */
require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/shortage_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$uid = (int) current_user()['id'];
echo json_encode([
    'ok'       => true,
    'shortage' => shortage_pending_count($uid),
], JSON_UNESCAPED_UNICODE);
