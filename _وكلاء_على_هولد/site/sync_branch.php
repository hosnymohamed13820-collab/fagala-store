<?php
/**
 * sync_branch.php — استقبال أرصدة الفروع
 * =====================================================================
 *  كل فرع بيبعت هنا **الفروق بس** (delta) — الأصناف اللي رصيدها اتحرّك
 *  من آخر دفعة. الفرع اللي مفيهوش بيع بيبعت صفر صفوف.
 *
 *  ليه delta مش لقطة كاملة: لقطة كاملة كل ٥ دقايق × ٥ فروع =
 *  ملايين الكتابات في اليوم. الاستضافة مش هتستحمل.
 *
 *  رصيد صفر بيتخزّن زي ما هو (مابيتشالش): لازم نفرّق بين
 *  "الصنف رصيده صفر" و "الصنف مالوش خبر من الفرع ده".
 *
 *  الأمان: توكن في الهيدر + POST بس. الملف مش محتاج تسجيل دخول
 *  (الجهاز مش بني آدم) فالتوكن لازم يفضل سري.
 * =====================================================================
 */
declare(strict_types=1);

const BRANCH_SYNC_TOKEN = 'hq_7Kp9mXq2Rv5Nw8Lt3Bz6Yd4Hs1Fj0Gc';
const BRANCH_MAX_ROWS   = 60000;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
error_reporting(E_ALL);
ini_set('display_errors', '0');

function br_die(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') br_die('POST only.', 405);

$token = (string) ($_SERVER['HTTP_X_SYNC_TOKEN'] ?? '');
if (!hash_equals(BRANCH_SYNC_TOKEN, $token)) br_die('Unauthorized.', 401);

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') br_die('Empty payload.');

$in = json_decode($raw, true);
if (!is_array($in)) br_die('Bad JSON.');

$agent = trim((string) ($in['agent'] ?? ''));
if (!preg_match('/^[a-z0-9_]{2,32}$/', $agent)) br_die('Bad agent key.');

$rows = $in['rows'] ?? null;
if (!is_array($rows)) br_die('Missing rows.');
if (count($rows) > BRANCH_MAX_ROWS) br_die('Too many rows.', 413);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/agent_lib.php';

try {
    if (!agent_exists($agent)) br_die('Unknown agent: ' . $agent, 404);

    $pdo = db();

    // upsert: الصنف الموجود يتحدّث والجديد يتحط.
    // مابنمسحش أي حاجة — الوكيل بيبعت الصفر صراحةً لما الرصيد يخلص.
    $st = $pdo->prepare(
        'INSERT INTO hq_branch_balances (agent_key, item_id, sto_id, balance)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            agent_key  = VALUES(agent_key),
            balance    = VALUES(balance),
            updated_at = NOW()'
    );

    $stored  = 0;
    $skipped = 0;

    $pdo->beginTransaction();
    try {
        foreach ($rows as $r) {
            if (!is_array($r)) { $skipped++; continue; }
            $item = (int) ($r['item_id'] ?? 0);
            $sto  = (int) ($r['sto_id']  ?? 0);
            if ($item <= 0 || $sto <= 0) { $skipped++; continue; }

            $bal = $r['balance'] ?? 0;
            if (!is_numeric($bal)) { $skipped++; continue; }

            $st->execute([$agent, $item, $sto, round((float) $bal, 3)]);
            $stored++;
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }

    // النبضة جوّه نفس الطلب — الوكيل مش محتاج يعمل نداء تاني للداتا
    try { agent_beat($agent, $stored); } catch (Throwable $ex) { }

    echo json_encode(
        ['ok' => true, 'stored' => $stored, 'skipped' => $skipped],
        JSON_UNESCAPED_UNICODE
    );
} catch (Throwable $ex) {
    br_die('Server error.', 500);
}
