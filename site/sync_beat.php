<?php
/**
 * sync_beat.php — استقبال نبضات الوكلاء
 * =====================================================================
 *  كل وكيل بيبعت هنا كل دورة (كل دقيقتين) حتى لو مفيش داتا اتغيّرت.
 *  الطلب صغير جداً — بايتات — فمش بيحمّل الاستضافة.
 *
 *  ليه ده موجود: من غير النبضة، سكوت الوكيل غامض. مش هنعرف الجهاز
 *  واقف ولا الوكيل واقف ولا ببساطة مفيش بيع جديد. النبضة بتفصل
 *  "شغّال ومفيش حركة" عن "واقف".
 *
 *  الأمان: توكن في الهيدر (مش في الـURL عشان مايتسجّلش في لوجات
 *  السيرفر بالنص الصريح) + POST بس.
 *
 *  مثال:
 *    POST /sync_beat.php
 *    X-Sync-Token: <التوكن>
 *    {"agent":"degla","rows":0,"version":"1.0","host":"DEGLA-PC"}
 * =====================================================================
 */
declare(strict_types=1);

// نفس التوكن اللي في sync_hq.php — الوكلاء كلهم بيستخدموه
const BEAT_TOKEN = 'hq_7Kp9mXq2Rv5Nw8Lt3Bz6Yd4Hs1Fj0Gc';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
error_reporting(E_ALL);
ini_set('display_errors', '0');

function beat_die(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    beat_die('POST only.', 405);
}

$token = (string) ($_SERVER['HTTP_X_SYNC_TOKEN'] ?? '');
if (!hash_equals(BEAT_TOKEN, $token)) {
    beat_die('Unauthorized.', 401);
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 4096) {
    beat_die('Bad payload.');
}

$in = json_decode($raw, true);
if (!is_array($in)) beat_die('Bad JSON.');

$agent = trim((string) ($in['agent'] ?? ''));
if ($agent === '' || !preg_match('/^[a-z0-9_]{2,32}$/', $agent)) {
    beat_die('Bad agent key.');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/agent_lib.php';

try {
    if (!agent_exists($agent)) {
        // وكيل مش مسجّل — نرفض بدل ما نضيف صف مجهول بصمت
        beat_die('Unknown agent: ' . $agent, 404);
    }

    agent_beat(
        $agent,
        max(0, (int) ($in['rows'] ?? 0)),
        (string) ($in['error']   ?? ''),
        (string) ($in['version'] ?? ''),
        (string) ($in['host']    ?? '')
    );

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $ex) {
    beat_die('Server error.', 500);
}
