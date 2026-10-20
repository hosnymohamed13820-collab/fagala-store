<?php
/**
 * stocktake_places_api.php — أقسام وأرفف الجرد (بريف ١٤٩)
 * نظام الفجالة ستور المالي
 *
 *   ?part=places                 ← {places:[…]}      (GET)
 *   ?part=add|delete             (POST) ← {added} · null
 *        |delete_all|toggle
 *
 * الشاشة (stocktake_places.php) لسه على حالها — الـAPI ده بينادي نفس دوال
 * stocktake_places_lib.php بالحرف: نفس تحويلات الأنواع ونفس نصوص الرسايل.
 * المنطق كله في المكتبة، مفيش سطر منه متعاد هنا.
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

// نفس حماية الصفحة بالحرف: require_login() وبعدها is_owner() (للأونر فقط).
// ملاحظة: is_owner() بترجّع true لحساب «مشاهدة بس» على GET لوحده، فأي POST
// من الحساب ده بيقع على نفس الفحص هنا — يعني مستحيل يكتب من الـAPI.
if (!is_owner()) {
    api_fail('للأونر فقط.', 403, 'forbidden');
}

require_once __DIR__ . '/stocktake_places_lib.php';

// نفس اللي الصفحة بتعمله في أولها: الجدول يتعمل لو مش موجود
stp_ready();

$part = (string) ($_GET['part'] ?? '');

// ===== القراءة — GET بس =====
switch ($part) {
    case 'places':
        api_method('GET');
        api_ok(['places' => stp_list()]);
}

// ===== الكتابة — POST بس، بـCSRF ومحمي من التكرار =====
$writes = ['add', 'delete', 'delete_all', 'toggle'];

if (!in_array($part, $writes, true)) {
    api_fail('جزء غير معروف', 400, 'bad_request');
}

api_method('POST');
api_csrf();
api_idempotent();

$in = api_input();

// الدوال بترمي RuntimeException برسايل للمستخدم — بنحوّلها 422.
// أي Throwable تاني مايتلقطش هنا ويروح لمعالج api_begin().
try {
    switch ($part) {
        case 'add':
            // نفس مدخل فورم الشاشة: سطر لكل مكان
            $n = stp_add((string) ($in['places'] ?? ''));
            api_ok(['added' => $n]);

        case 'delete':
            stp_delete((int) ($in['id'] ?? 0));
            api_ok(null);

        case 'delete_all':
            stp_delete_all();
            api_ok(null);

        case 'toggle':
            stp_toggle((int) ($in['id'] ?? 0));
            api_ok(null);
    }
} catch (RuntimeException $e) {
    api_fail($e->getMessage(), 422, 'invalid');
}
