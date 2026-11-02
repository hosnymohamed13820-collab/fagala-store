<?php
require_once __DIR__ . '/includes/site_profile.php';
if (!site_profile()['true_integration']) { http_response_code(404); exit; }

/**
 * print_pull.php — نقطة سحب المهام لوكيل الطباعة الحرارية
 * نظام الفجالة ستور المالي
 *
 * الوكيل (fagala_printer_agent.ps1) بينده هنا دورياً:
 *   POST { key, session, branch, host, printer, done, failed, error }
 *   → بيسجل نبضة الحياة، يأكد اللي اتطبع أو فشل، ويسحب مهام جديدة.
 *
 * الحماية: مفتاح من جدول settings (print_agent_key).
 * فشل مقفول: لو المفتاح في settings فاضي، الباب مقفول ومفيش وكيل يعدّي.
 * مفيش جلسة دخول هنا لأن الوكيل برنامج مش متصفح.
 */
require_once __DIR__ . '/errlog_lib.php';
errlog_install();
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$key     = (string) ($_POST['key'] ?? '');
$session = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', $_POST['session'] ?? ''));
$branch  = (int) ($_POST['branch'] ?? 0);
$host    = mb_substr(trim((string) ($_POST['host'] ?? '')), 0, 64);
$printer = mb_substr(trim((string) ($_POST['printer'] ?? '')), 0, 120);
$done    = (string) ($_POST['done'] ?? '');    // "12,13"
$failed  = (string) ($_POST['failed'] ?? '');  // "14"
$err     = mb_substr(trim((string) ($_POST['error'] ?? '')), 0, 255);

$expect = (string) setting('print_agent_key', '');

// فشل مقفول: مفتاح فاضي = الباب مقفول، مش مفتوح.
if ($expect === '' || !hash_equals($expect, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'مفتاح غلط'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($session === '') {
    echo json_encode(['ok' => false, 'error' => 'session مطلوب'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = db();

    // (أ) نبضة حياة: تسجيل وجود الوكيل وحالته
    $pdo->prepare(
        'INSERT INTO print_agents (session_code, branch_id, hostname, printer, last_seen)
         VALUES (?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            branch_id = VALUES(branch_id),
            hostname  = VALUES(hostname),
            printer   = VALUES(printer),
            last_seen = NOW()'
    )->execute([
        $session,
        $branch > 0 ? $branch : null,
        $host !== '' ? $host : null,
        $printer !== '' ? $printer : null,
    ]);

    // (ب) تأكيد اللي اتطبع أو فشل
    if ($done !== '') {
        $doneIds = array_values(array_filter(array_map('intval', explode(',', $done)), static fn($id) => $id > 0));
        if (!empty($doneIds)) {
            $inDone = implode(',', $doneIds);
            $stDone = $pdo->prepare("UPDATE print_queue SET status = 'done', printed_at = NOW() WHERE id IN ($inDone) AND claimed_by = ?");
            $stDone->execute([$session]);
        }
    }

    if ($failed !== '') {
        $failIds = array_values(array_filter(array_map('intval', explode(',', $failed)), static fn($id) => $id > 0));
        if (!empty($failIds)) {
            $inFail = implode(',', $failIds);
            $failErr = $err !== '' ? $err : 'فشل غير محدد من الوكيل';
            $stFail = $pdo->prepare("UPDATE print_queue SET status = 'failed', last_error = ? WHERE id IN ($inFail) AND claimed_by = ?");
            $stFail->execute([$failErr, $session]);
        }
    }

    // (ج) إرجاع المهام المعلّقة ثم إعدام المتعثرة
    $pdo->exec("UPDATE print_queue
                   SET status = 'queued', claimed_by = NULL, attempts = attempts + 1
                 WHERE status = 'claimed' AND claimed_at < NOW() - INTERVAL 2 MINUTE");

    $pdo->exec("UPDATE print_queue
                   SET status = 'failed', last_error = 'اتجرّبت ٣ مرات وما اتطبعتش'
                 WHERE status = 'queued' AND attempts >= 3");

    // (د) السحب في معاملة
    $pdo->beginTransaction();
    $st = $pdo->prepare(
        "SELECT id, kind, ref_id, payload FROM print_queue
          WHERE status = 'queued' AND (branch_id = ? OR branch_id IS NULL)
          ORDER BY id ASC LIMIT 3 FOR UPDATE"
    );
    $st->execute([$branch > 0 ? $branch : null]);
    $rows = $st->fetchAll();
    $claimedIds = [];
    $jobs = [];

    if (!empty($rows)) {
        $badIds = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['payload'], true);
            if ($decoded === null) {
                $badIds[] = (int) $row['id'];
            } else {
                $claimedIds[] = (int) $row['id'];
                $jobs[] = [
                    'id'      => (int) $row['id'],
                    'kind'    => (string) $row['kind'],
                    'ref'     => (int) $row['ref_id'],
                    'payload' => $decoded,
                ];
            }
        }

        if (!empty($claimedIds)) {
            $inClaimed = implode(',', $claimedIds);
            $stClaim = $pdo->prepare("UPDATE print_queue SET status = 'claimed', claimed_by = ?, claimed_at = NOW() WHERE id IN ($inClaimed)");
            $stClaim->execute([$session]);
        }

        if (!empty($badIds)) {
            $inBad = implode(',', $badIds);
            $pdo->exec("UPDATE print_queue SET status = 'failed', last_error = 'payload JSON تالف' WHERE id IN ($inBad)");
        }
    }
    $pdo->commit();

    // (هـ) تنضيف خفيف
    if (random_int(1, 100) === 1) {
        $pdo->exec("DELETE FROM print_queue WHERE status IN ('done','failed') AND created_at < NOW() - INTERVAL 7 DAY");
    }

    // (و) الرد
    echo json_encode([
        'ok'   => true,
        'jobs' => $jobs,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $ex) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
}

