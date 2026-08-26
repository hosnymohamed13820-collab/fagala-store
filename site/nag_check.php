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
require_once __DIR__ . '/agent_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$me  = current_user() ?: [];
$uid = (int) ($me['id'] ?? 0);

$out = [
    'ok'       => true,
    'shortage' => shortage_pending_count($uid),
];

// حالة الوكلاء — للأونر والمراقب المحدد بس.
// بيتحسب من صفوف قليلة (٦ صفوف) فمش بيضيف حمل على الفحص الدوري.
try {
    if ($me && agent_is_watcher($me)) {
        $t = agent_trouble();
        $out['agent_level'] = $t['level'];
        $out['agent_text']  = ($t['level'] === AGENT_OK) ? '' : agent_nag_text($t);
    }
} catch (Throwable $ex) { /* الجداول لسه مش موجودة */ }

echo json_encode($out, JSON_UNESCAPED_UNICODE);
