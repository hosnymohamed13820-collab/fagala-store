<?php
/**
 * scan_pull.php — نقطة سحب الأكواد لوكيل الكمبيوتر
 * نظام الفجالة ستور المالي
 *
 * الوكيل (fagala_scanner_agent.ps1) بينده هنا كل ثانية تقريبًا:
 *   POST { key, session } → بيرجع الأكواد الجديدة ويعلّمها مستهلكة.
 *
 * الحماية: مفتاح ثابت (نفس أسلوب bridge.php) — **غيّره قبل الرفع**
 * ولازم يطابق اللي في سكربت الوكيل. مفيش جلسة دخول هنا لأن الوكيل
 * برنامج مش متصفح.
 */
// سجل الأخطاء — الملف ده مابيعملش require لـauth.php ولا agent_lib.php
require_once __DIR__ . '/errlog_lib.php';
errlog_install();
require_once __DIR__ . '/config.php';

// ★ غيّر المفتاح ده لمفتاح طويل عشوائي (32 حرف على الأقل)
//   ولازم يطابق $Key في fagala_scanner_agent.ps1
const AGENT_KEY = 'zovUE8swzsSMcvUnPX5I84ymZ3FYeYSS';

header('Content-Type: application/json; charset=utf-8');

$key     = (string) ($_POST['key'] ?? '');
$session = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', $_POST['session'] ?? ''));

if (!hash_equals(AGENT_KEY, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'مفتاح غلط']);
    exit;
}
if ($session === '') {
    echo json_encode(['ok' => false, 'error' => 'session مطلوب']);
    exit;
}

try {
    $pdo = db();

    // نبضة حياة: بنسجل إن الجهاز ده شغال دلوقتي (لقايمة الأجهزة في الموبايل)
    // وبنقرا في نفس الوقت هل فيه موبايل فاتح السكانر على الجلسة دي —
    // ده اللي بيخلّي الوكيل يسرّع أو يهدّى من غير استعلام زيادة.
    $host = mb_substr(trim((string) ($_POST['host'] ?? '')), 0, 64);

    $wk = $pdo->prepare(
        'SELECT wake_until > NOW() AS awake FROM scan_agents WHERE session_code = ?'
    );
    $wk->execute([$session]);
    $awake = (bool) $wk->fetchColumn();

    $pdo->prepare(
        'INSERT INTO scan_agents (session_code, hostname, last_seen)
         VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE hostname = VALUES(hostname), last_seen = NOW()'
    )->execute([$session, $host !== '' ? $host : null]);

    // نقرا ونعلّم مستهلك في transaction عشان مفيش كود يتكتب مرتين
    $pdo->beginTransaction();
    $st = $pdo->prepare(
        'SELECT id, barcode FROM scan_queue
         WHERE session_code = ? AND consumed = 0
         ORDER BY id ASC LIMIT 20 FOR UPDATE'
    );
    $st->execute([$session]);
    $rows = $st->fetchAll();
    if ($rows) {
        $ids = array_column($rows, 'id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE scan_queue SET consumed = 1 WHERE id IN ($ph)")->execute($ids);
    }
    $pdo->commit();

    // تنضيف خفيف: أي حاجة أقدم من 24 ساعة تتمسح (مرة كل ~100 نداء)
    if (random_int(1, 100) === 1) {
        $pdo->exec("DELETE FROM scan_queue WHERE created_at < NOW() - INTERVAL 1 DAY");
    }

    // wake = فيه موبايل فاتح السكانر دلوقتي على الجلسة دي.
    // الوكيل بيسرّع لما تكون true ويهدّى لما تكون false — ده اللي
    // بينزّل الحمل من ~100 ألف نداء في اليوم لآلاف قليلة.
    echo json_encode([
        'ok'    => true,
        'codes' => array_column($rows, 'barcode'),
        'wake'  => $awake,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $ex) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
}
