<?php
/**
 * push_worker.php — وركر معالجة وإرسال طابور الإشعارات (Push Queue Worker)
 * نظام الفجالة ستور المالي
 *
 * بيشتغل عبر cron job كل دقيقة بطلب POST وتوكن في الهيدر X-Push-Token
 */
declare(strict_types=1);

// سجل الأخطاء — الملف ده مابيعملش require لـauth.php ولا agent_lib.php
require_once __DIR__ . '/errlog_lib.php';
errlog_install();
require_once __DIR__ . '/push_lib.php';   // فيه PUSH_WORKER_TOKEN

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
error_reporting(E_ALL);
ini_set('display_errors', '0');

function worker_die(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (PHP_SAPI !== 'cli') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        worker_die('POST only.', 405);
    }

    $token = (string) ($_SERVER['HTTP_X_PUSH_TOKEN'] ?? '');
    if (!hash_equals(PUSH_WORKER_TOKEN, $token)) {
        worker_die('Unauthorized.', 401);
    }
}

require_once __DIR__ . '/config.php';

try {
    $pdo = db();

    // ١) سحب ٥٠ صف من الطابور بترتيب المعرّف
    $st = $pdo->prepare(
        "SELECT id, user_id, title, body, link, tries
         FROM push_queue
         WHERE status = 'pending'
         ORDER BY id ASC
         LIMIT 50"
    );
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $picked = count($rows);
    $sent = 0;
    $failed = 0;
    $dead = 0;

    if ($picked === 0) {
        echo json_encode([
            'ok'     => true,
            'picked' => 0,
            'sent'   => 0,
            'failed' => 0,
            'dead'   => 0
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ٢) جلب كل أجهزة المستخدمين باستعلام واحد مجمّع
    $userIds = array_values(array_unique(array_column($rows, 'user_id')));
    $subsByUser = [];
    if (!empty($userIds)) {
        $in = implode(',', array_fill(0, count($userIds), '?'));
        $subSt = $pdo->prepare(
            "SELECT id, user_id, endpoint, endpoint_hash, p256dh, auth, user_agent, fail_count
             FROM push_subscriptions
             WHERE user_id IN ($in)"
        );
        $subSt->execute($userIds);
        $allSubs = $subSt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($allSubs as $s) {
            $subsByUser[(int) $s['user_id']][] = $s;
        }
    }

    $updateSent = $pdo->prepare("UPDATE push_queue SET status = 'sent', sent_at = NOW() WHERE id = ?");
    $updateTries = $pdo->prepare("UPDATE push_queue SET tries = ?, last_error = ?, status = ? WHERE id = ?");
    $delSub = $pdo->prepare("DELETE FROM push_subscriptions WHERE id = ?");
    $updateSubOk = $pdo->prepare("UPDATE push_subscriptions SET last_ok_at = NOW(), fail_count = 0 WHERE id = ?");

    // ٣) معالجة كل صف في الطابور
    foreach ($rows as $row) {
        $qid = (int) $row['id'];
        $uid = (int) $row['user_id'];
        $userSubs = $subsByUser[$uid] ?? [];

        // مستخدم ليس لديه أي أجهزة ← تم الإرسال فوراً لتفريغ الطابور
        if (empty($userSubs)) {
            $updateSent->execute([$qid]);
            $sent++;
            continue;
        }

        $payload = [
            'title' => $row['title'],
            'body'  => $row['body'],
            'link'  => $row['link']
        ];

        $allOk = true;
        $lastErr = null;

        foreach ($userSubs as $sub) {
            $res = push_send_one($sub, $payload);
            if (!empty($res['ok'])) {
                $updateSubOk->execute([(int) $sub['id']]);
            } else {
                $allOk = false;
                $code = (int) ($res['code'] ?? 0);
                $lastErr = (string) ($res['error'] ?? 'خطأ في الإرسال');

                if ($code === 404 || $code === 410) {
                    // اشتراك ميت ← حذف الجهاز
                    $delSub->execute([(int) $sub['id']]);
                    $dead++;
                }
            }
        }

        if ($allOk) {
            $updateSent->execute([$qid]);
            $sent++;
        } else {
            $newTries = (int) $row['tries'] + 1;
            $newStatus = ($newTries >= 5) ? 'failed' : 'pending';
            if ($newStatus === 'failed') {
                $failed++;
            }
            $updateTries->execute([$newTries, mb_substr((string) $lastErr, 0, 255), $newStatus, $qid]);
        }
    }

    echo json_encode([
        'ok'     => true,
        'picked' => $picked,
        'sent'   => $sent,
        'failed' => $failed,
        'dead'   => $dead
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    // 🔴 الرسالة تروح للوج مش للرد — رسالة الاستثناء بتكشف أسماء جداول
    //    ونص الاستعلام، و display_errors مقفول عشان نفس السبب بالظبط.
    error_log('push_worker error: ' . $e->getMessage());
    worker_die('Server error.', 500);
}
