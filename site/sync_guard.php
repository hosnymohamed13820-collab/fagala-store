<?php
/**
 * sync_guard.php — نقطة استقبال فحوصات حارس الربط من وكيل الإدارة
 * =====================================================================
 *  نظام الفجالة ستور المالي
 *  بريف ٩٤ — أكتوبر ٢٠٢٦
 * =====================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/site_profile.php';
if (!site_profile()['true_integration']) { http_response_code(404); exit; }


require_once __DIR__ . '/sync_tokens.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
error_reporting(E_ALL);
ini_set('display_errors', '0');

function guard_die(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    guard_die('POST only.', 405);
}

$token = (string) ($_SERVER['HTTP_X_SYNC_TOKEN'] ?? '');

$raw = file_get_contents('php://input');
// حد الحجم 256 KB
if ($raw === false || strlen($raw) > 256 * 1024) {
    guard_die('Bad payload size.');
}

$in = json_decode($raw, true);
if (!is_array($in)) {
    guard_die('Bad JSON.');
}

$agent = trim((string) ($in['agent'] ?? ''));
if ($agent !== 'hq') {
    guard_die('Bad agent key.');
}

if (!synctok_ok($agent, $token, ['hq'])) {
    guard_die('Unauthorized.', 401);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rabt_guard_lib.php';

try {
    $res = rabt_process_findings($in);
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    guard_die('Server error: ' . $e->getMessage(), 500);
}
