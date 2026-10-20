<?php
/**
 * remote_scan_api.php — داتا «سكانر الكمبيوتر» (بريف ١٤٦)
 * نظام الفجالة ستور المالي
 *
 *   ?part=devices          ← {devices}   (GET)
 *   ?part=wake   (POST)    ← null        (session)
 *   ?part=push   (POST)    ← null        (session, barcode)
 *
 * الشاشة (remote_scan.php) لسه على حالها — الـAPI بينادي نفس دوال
 * remote_scan_lib.php بالحرف: نفس التنظيف ونفس الرسايل ونفس الحماية.
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

// نفس حماية الصفحة بالظبط: require_permission('remote_scan') في remote_scan.php:13
api_require('remote_scan');

require_once __DIR__ . '/remote_scan_lib.php';

$uid = (int) (current_user()['id'] ?? 0);

$part = (string) ($_GET['part'] ?? '');

// ===== القراءة — GET بس =====
switch ($part) {
    case 'devices':
        api_method('GET');
        api_ok(['devices' => rs_devices()]);
}

// ===== الكتابة — POST بس، بـCSRF ومحمي من التكرار =====
$writes = ['wake', 'push'];

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
        case 'wake':
            rs_wake((string) ($in['session'] ?? ''));
            api_ok(null);

        case 'push':
            rs_push(
                (string) ($in['session'] ?? ''),
                (string) ($in['barcode'] ?? ''),
                $uid
            );
            api_ok(null);
    }
} catch (RuntimeException $e) {
    api_fail($e->getMessage(), 422, 'invalid');
}
