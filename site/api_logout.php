<?php
/**
 * api_logout.php — إلغاء توكن الجهاز الحالي
 * نظام الفجالة ستور المالي (بريف ١٠٢)
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';

api_begin();
require_login();
api_method('POST');
api_csrf();

$token = auth_bearer();
if ($token !== null && $token !== '') {
    $tokenHash = hash('sha256', $token);
    try {
        db()->prepare('UPDATE api_tokens SET revoked_at = NOW() WHERE token_hash = ?')
            ->execute([$tokenHash]);
    } catch (Throwable) {
        // لو حصل خطأ في التحديث نكمّل
    }
}

api_ok();
