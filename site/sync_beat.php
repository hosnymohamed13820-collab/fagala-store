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
 *  الأمان: توكن سري لكل وكيل في الهيدر (مش في الـURL عشان مايتسجّلش في لوجات
 *  السيرفر بالنص الصريح) + POST بس.
 *
 *  مثال:
 *    POST /sync_beat.php
 *    X-Sync-Token: <التوكن>
 *    {"agent":"degla","rows":0,"version":"1.0","host":"DEGLA-PC"}
 * =====================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/site_profile.php';
if (!site_profile()['true_integration']) { http_response_code(404); exit; }


require_once __DIR__ . '/sync_tokens.php';
require_once __DIR__ . '/agent_dist_lib.php';

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

if (!synctok_ok($agent, $token, array_diff(array_keys(SYNC_AGENT_TOKENS), ['sms_phone', 'guard_watch']))) beat_die('Unauthorized.', 401);

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

    // طابور الربط (بريف ٥٠) — الوكلاء الجداد بس بيبعتوه. null/مش موجود = تجاهل.
    if (isset($in['repl']) && is_array($in['repl'])) {
        try { agent_repl_beat($agent, $in['repl']); }
        catch (Throwable $ex) { /* الأعمدة لسه مااتضافتش — النبضة نفسها نجحت */ }
    }

    // تصدير الربط في الإدارة (بريف ٥٢) — وكيل الإدارة بس بيبعته.
    if (isset($in['repl_send']) && is_array($in['repl_send'])) {
        try { agent_repl_send_beat($agent, $in['repl_send']); }
        catch (Throwable $ex) { /* الأعمدة لسه مااتضافتش — النبضة نفسها نجحت */ }
    }

    // أوامر حارس الربط (بريف ٩٤) — استلام نتائج وتنفيذ أوامر إعادة التشغيل
    $cmdToReturn = null;
    try {
        require_once __DIR__ . '/rabt_guard_lib.php';
        $cmdToReturn = rabt_handle_beat_commands($agent, $in);
    } catch (Throwable $ex) {
        // الجداول ممكن تبقى لسه ما اتعملتش — النبضة نفسها لازم تنجح زي النهارده
    }

    $out = ['ok' => true];
    if ($cmdToReturn !== null) {
        $out['cmd'] = $cmdToReturn;
    }
    $update = agent_dist_update($agent, trim((string) ($in['version'] ?? '')));
    if ($update !== null) {
        $out['update'] = $update;
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (Throwable $ex) {
    beat_die('Server error.', 500);
}
