<?php
/**
 * errors_api.php — داتا «سجل الأخطاء» (بريف ١٤٨)
 * نظام الفجالة ستور المالي
 *
 *   ?part=groups            ← {groups, count, total, php, js, size}  (GET)
 *   ?part=clear      (POST) ← null                                    (أونر بس)
 *
 * الشاشة (errors.php) لسه على حالها — الـAPI بينادي نفس دوال errlog_lib.php
 * بالحرف (errlog_snapshot · errlog_clear)، فمفيش منطق متعاد هنا.
 *
 * ⚠️ استقبال أخطاء الجافاسكربت (?report=js) سايب زي ما هو في errors.php:
 *    ده endpoint قايم بالفعل (sendBeacon من المتصفح + التطبيقات بتوكن Bearer)
 *    وردّه 204، ونقله هنا كان هيغيّر عقده ويبوّظ اللي بيبعته.
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

// نفس حماية الصفحة بالظبط: require_permission('errors') في errors.php
api_require('errors');

$part = (string) ($_GET['part'] ?? '');

// ===== القراءة — GET بس =====
switch ($part) {
    case 'groups':
        api_method('GET');
        api_ok(errlog_snapshot());
}

// ===== الكتابة — POST بس، بـCSRF ومحمي من التكرار =====
if ($part !== 'clear') {
    api_fail('جزء غير معروف', 400, 'bad_request');
}

api_method('POST');
api_csrf();
api_idempotent();

// نفس فحص الصفحة بالحرف: المسح للأونر بس — اللي معاه صلاحية القراية مايمسحش الأدلة
if (!is_owner()) {
    api_fail('المسح للأونر بس.', 403, 'forbidden');
}

try {
    errlog_clear();
    api_ok(null);
} catch (RuntimeException $e) {
    api_fail($e->getMessage(), 422, 'invalid');
}
