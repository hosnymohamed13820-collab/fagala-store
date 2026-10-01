<?php
/**
 * rabt_guard_lib.php — مكتبة دوال حارس الربط وإدارة الحوادث والأوامر
 * =====================================================================
 *  نظام الفجالة ستور المالي
 *  بريف ٩٤ — أكتوبر ٢٠٢٦
 * =====================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/agent_lib.php';

/**
 * وضع أمر إعادة تشغيل repl_true للوكيل في طابور الأوامر بشرط عدم التكرار خلال ٣٠ دقيقة.
 */
function rabt_queue_restart(string $agentKey, ?string $reason = null, ?int $incidentId = null): bool
{
    $allowed = ['hq', 'zahraa', 'degla', 'bonjour', 'abc'];
    if (!in_array($agentKey, $allowed, true)) {
        return false;
    }

    try {
        $pdo = db();
        // فحص وجود أمر pending أو picked لنفس الوكيل خلال آخر ٣٠ دقيقة
        $st = $pdo->prepare(
            'SELECT 1 FROM agent_commands
              WHERE agent_key = ?
                AND status IN (\'pending\', \'picked\')
                AND created_at >= NOW() - INTERVAL 30 MINUTE
              LIMIT 1'
        );
        $st->execute([$agentKey]);
        if ($st->fetchColumn()) {
            return false;
        }

        $ins = $pdo->prepare(
            'INSERT INTO agent_commands (agent_key, cmd, reason, incident_id, status)
             VALUES (?, \'restart_repl\', ?, ?, \'pending\')'
        );
        $ins->execute([
            $agentKey,
            $reason !== null ? mb_substr($reason, 0, 255) : null,
            $incidentId
        ]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * معالجة نتائج فحص الحارس الواردة من وكيل الإدارة (sync_guard.php).
 */
function rabt_process_findings(array $payload, ?bool $overrideInWindow = null): array
{
    $agent = trim((string) ($payload['agent'] ?? ''));
    if ($agent !== 'hq') {
        throw new InvalidArgumentException('Only hq agent is allowed to report findings.');
    }

    $checked = is_array($payload['checked'] ?? null) ? $payload['checked'] : [];
    $findings = is_array($payload['findings'] ?? null) ? $payload['findings'] : [];

    $pdo = db();

    // ١) استعلام واحد يجيب كل الحوادث المفتوحة للوكيل ده
    $stOpen = $pdo->prepare(
        'SELECT id, check_code, fingerprint, status
           FROM rabt_incidents
          WHERE agent_key = ? AND status <> \'closed\''
    );
    $stOpen->execute([$agent]);
    $openRows = $stOpen->fetchAll(PDO::FETCH_ASSOC);

    $openByFp = [];
    foreach ($openRows as $r) {
        $openByFp[$r['fingerprint']] = $r;
    }

    $seenFpsInPayload = [];
    $newIncidents = [];
    $inWindow = $overrideInWindow !== null ? $overrideInWindow : agent_in_window();

    $stUpdate = $pdo->prepare(
        'UPDATE rabt_incidents
            SET last_seen = NOW(), seen_count = seen_count + 1, details = ?
          WHERE id = ?'
    );

    $stInsert = $pdo->prepare(
        'INSERT INTO rabt_incidents (agent_key, check_code, fingerprint, branch_key, title, details, first_seen, last_seen, seen_count, status)
         VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), 1, \'new\')'
    );

    // ٢) فحص وتحديث أو إدخال كل finding
    foreach ($findings as $f) {
        if (!is_array($f)) continue;

        $code = trim((string) ($f['code'] ?? ''));
        $fp = trim((string) ($f['fp'] ?? $f['sha'] ?? ''));
        if ($fp === '') continue;

        $seenFpsInPayload[$fp] = true;
        $branch = !empty($f['branch']) ? trim((string) $f['branch']) : null;
        $title = mb_substr(trim((string) ($f['title'] ?? $f['summary'] ?? '')), 0, 200);
        $details = null;
        if (isset($f['details'])) {
            $details = is_string($f['details']) ? $f['details'] : json_encode($f['details'], JSON_UNESCAPED_UNICODE);
        }
        $restartKey = !empty($f['restart']) ? trim((string) $f['restart']) : null;

        if (isset($openByFp[$fp])) {
            // بصمته مفتوحة: نحدّث last_seen و seen_count و details ولا نغيّر status
            $stUpdate->execute([$details, (int) $openByFp[$fp]['id']]);
        } else {
            // حادثة جديدة
            $stInsert->execute([$agent, $code, $fp, $branch, $title, $details]);
            $newId = (int) $pdo->lastInsertId();

            // فحص نافذة العمل لـ H02 و H03
            $skipAlertAndRestart = in_array($code, ['H02', 'H03'], true) && !$inWindow;

            if (!$skipAlertAndRestart) {
                $newIncidents[] = [
                    'id'    => $newId,
                    'title' => $title,
                    'code'  => $code,
                ];

                if ($restartKey !== null) {
                    rabt_queue_restart($restartKey, $title, $newId);
                }
            }
        }
    }

    // ٣) كل حادثة مفتوحة كودها في checked وبصمتها مش في findings تقفل كـ "اتحلت لوحدها"
    $toCloseIds = [];
    foreach ($openByFp as $fp => $row) {
        if (in_array($row['check_code'], $checked, true) && !isset($seenFpsInPayload[$fp])) {
            $toCloseIds[] = (int) $row['id'];
        }
    }

    if (!empty($toCloseIds)) {
        $inClause = implode(',', array_fill(0, count($toCloseIds), '?'));
        $stClose = $pdo->prepare(
            "UPDATE rabt_incidents
                SET status = 'closed', closed_at = NOW(), close_reason = 'اتحلت لوحدها'
              WHERE id IN ($inClause)"
        );
        $stClose->execute($toCloseIds);
    }

    // ٤) إشعار واحد لو فيه جديد
    if (!empty($newIncidents)) {
        try {
            $ids = $pdo->query(
                'SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.is_owner = 1 AND u.is_active = 1'
            )->fetchAll(PDO::FETCH_COLUMN);
            $wid = (int) setting('agent_watcher_id', '0');
            if ($wid > 0) $ids[] = $wid;

            $countNew = count($newIncidents);
            $firstTitle = $newIncidents[0]['title'];
            $body = $countNew === 1 ? $firstTitle : ($firstTitle . '…');

            require_once __DIR__ . '/push_lib.php';
            notify($pdo, $ids, 'rabt_guard', 'حارس الربط: ' . $countNew . ' مشكلة جديدة', $body, 'rabt_guard.php');
        } catch (Throwable $e) {
            // خطأ إرسال الإشعار لا يفشل استلام الفحوصات
        }
    }

    // ٥) حساب عدد الحوادث المفتوحة حالياً
    $stCount = $pdo->prepare('SELECT COUNT(*) FROM rabt_incidents WHERE agent_key = ? AND status <> \'closed\'');
    $stCount->execute([$agent]);
    $openCount = (int) $stCount->fetchColumn();

    return [
        'ok'       => true,
        'new'      => count($newIncidents),
        'open'     => $openCount,
        'stored'   => count($findings),
        'resolved' => count($toCloseIds),
    ];
}

/**
 * معالجة نتائج الأوامر وأفعال الوكلاء والتقاط أوامر جديدة أثناء النبضة.
 */
function rabt_handle_beat_commands(string $agent, array $in): ?array
{
    $pdo = db();

    // أ) معالجة نتيجة أمر منفّذ (cmd_result)
    if (isset($in['cmd_result']) && is_array($in['cmd_result'])) {
        $cmdId  = (int) ($in['cmd_result']['id'] ?? 0);
        $ok     = !empty($in['cmd_result']['ok']);
        $result = mb_substr(trim((string) ($in['cmd_result']['text'] ?? '')), 0, 255);

        if ($cmdId > 0) {
            $updCmd = $pdo->prepare(
                'UPDATE agent_commands
                    SET status = ?, done_at = NOW(), result = ?
                  WHERE id = ? AND agent_key = ?'
            );
            $updCmd->execute([$ok ? 'done' : 'failed', $result, $cmdId, $agent]);

            if (!$ok) {
                // إشعار بفشل إعادة التشغيل
                $lb = $pdo->prepare('SELECT label FROM agent_status WHERE agent_key = ?');
                $lb->execute([$agent]);
                $label = (string) ($lb->fetchColumn() ?: $agent);

                $ids = $pdo->query(
                    'SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.is_owner = 1 AND u.is_active = 1'
                )->fetchAll(PDO::FETCH_COLUMN);
                $wid = (int) setting('agent_watcher_id', '0');
                if ($wid > 0) $ids[] = $wid;

                require_once __DIR__ . '/push_lib.php';
                notify($pdo, $ids, 'rabt_guard', 'إعادة تشغيل الربط فشلت — ' . $label, $result, 'rabt_guard.php');
            }
        }
    }

    // ب) معالجة repl_action لو الوكيل اتصرف لوحده
    if (isset($in['repl_action']) && is_array($in['repl_action'])) {
        $actText = mb_substr(trim((string) ($in['repl_action']['text'] ?? '')), 0, 255);
        if ($actText !== '') {
            $lb = $pdo->prepare('SELECT label FROM agent_status WHERE agent_key = ?');
            $lb->execute([$agent]);
            $label = (string) ($lb->fetchColumn() ?: $agent);

            $ids = $pdo->query(
                'SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.is_owner = 1 AND u.is_active = 1'
            )->fetchAll(PDO::FETCH_COLUMN);
            $wid = (int) setting('agent_watcher_id', '0');
            if ($wid > 0) $ids[] = $wid;

            require_once __DIR__ . '/push_lib.php';
            notify($pdo, $ids, 'rabt_guard', 'حارس الربط — ' . $label, $actText, 'rabt_guard.php');
        }
    }

    // ج) تحويل الأوامر القديمة إلى expired
    $pdo->exec(
        'UPDATE agent_commands
            SET status = \'expired\'
          WHERE status = \'pending\' AND created_at < NOW() - INTERVAL 2 HOUR'
    );

    // د) التقاط أقدم أمر pending لهذا الوكيل
    $stOldest = $pdo->prepare(
        'SELECT id, cmd
           FROM agent_commands
          WHERE agent_key = ? AND status = \'pending\'
          ORDER BY id ASC
          LIMIT 1'
    );
    $stOldest->execute([$agent]);
    $cmdRow = $stOldest->fetch(PDO::FETCH_ASSOC);

    if ($cmdRow) {
        $cId = (int) $cmdRow['id'];
        $updPick = $pdo->prepare(
            'UPDATE agent_commands
                SET status = \'picked\', picked_at = NOW()
              WHERE id = ? AND status = \'pending\''
        );
        $updPick->execute([$cId]);
        if ($updPick->rowCount() === 1) {
            return [
                'id'   => $cId,
                'name' => (string) $cmdRow['cmd'],
            ];
        }
    }

    return null;
}
