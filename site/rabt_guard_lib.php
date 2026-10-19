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

    // اللي الأونر قفله بإيده وهو لسه موجود = «معروف ومش مشكلة» — مايتفتحش تاني
    // ولا يبعت إشعار كل ١٠ دقايق. أول ما يختفي من الفحص، بيتشال من القايمة دي،
    // فلو رجع بعد كده يبقى حادثة جديدة بجد.
    $stIgn = $pdo->prepare(
        "SELECT id, check_code, fingerprint FROM rabt_incidents
          WHERE agent_key = ? AND status = 'closed' AND close_reason = 'قفلها الأونر'"
    );
    $stIgn->execute([$agent]);
    $ignoredByFp = [];
    foreach ($stIgn->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ignoredByFp[$r['fingerprint']] = $r;
    }

    $seenFpsInPayload = [];
    $newIncidents = [];
    $inWindow = $overrideInWindow !== null ? $overrideInWindow : agent_in_window();

    // H02 («الفرع مااستوردش من ساعتين») كان بيصرخ على فرع لسه مافتحش أو مابيبيعش
    // (٢/١٠ الضهر: دجلة والفجالة صفر فواتير، والحارس فاكر الربط واقف). الفرع اللي
    // مالوش ولا مستند في آخر ١٥٠ دقيقة في قاعدته هو (br_invoices من وكيله) مفيش حاجة
    // تتبعت منه أصلاً — نعتبر الفحص سليم. ولو الربط واقف بجد والفرع بيبيع، المستندات
    // بتوصل هنا من وكيله وH02 بيصرخ عادي.
    $activeBranches = [];
    try {
        $activeBranches = array_flip($pdo->query(
            'SELECT agent_key FROM br_invoices
              WHERE invoice_date >= NOW() - INTERVAL 150 MINUTE
              GROUP BY agent_key'
        )->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        $activeBranches = null;   // مش قادرين نعرف — سيب H02 زي ما هو
    }

    // الريستارت كان بيتبعت مرة واحدة ساعة ما الحادثة تتفتح. ٢/١٠: دجلة ريستارت
    // ٠٢:٣٧ رجع ok والربط فضل ميت ١٠ ساعات والحارس ساكت. دلوقتي طول ما الحادثة
    // لسه ظاهرة بيعيد كل ساعة لحد ٣ مرات، وبعد التالتة إشعار إن الموضوع محتاج حد.
    $restartTries = [];   // incident_id => [count, minutes since last]
    try {
        foreach ($pdo->query(
            "SELECT incident_id, COUNT(*) n, TIMESTAMPDIFF(MINUTE, MAX(created_at), NOW()) age
               FROM agent_commands
              WHERE cmd = 'restart_repl' AND incident_id IS NOT NULL
              GROUP BY incident_id"
        )->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $restartTries[(int) $r['incident_id']] = [(int) $r['n'], (int) $r['age']];
        }
    } catch (Throwable $e) {
        $restartTries = null;   // مش قادرين نعرف — مانعيدش
    }
    $giveUp = [];

    $stUpdate = $pdo->prepare(
        'UPDATE rabt_incidents
            SET last_seen = NOW(), seen_count = seen_count + 1, details = ?
          WHERE id = ?'
    );

    $stInsert = $pdo->prepare(
        'INSERT INTO rabt_incidents (agent_key, check_code, fingerprint, branch_key, title, details, first_seen, last_seen, seen_count, status)
         VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), 1, \'new\')'
    );

    // ١.٥) ملفات أكتر من فرع متأخرة في Import\HQ في نفس اللفّة = الإدارة هي اللي واقفة
    //      (repl_true عندها مابيستوردش)، مش مشكلة في كل فرع. كانت بتطلع ٣ حوادث في نفس
    //      الدقيقة لنفس السبب (٥٩ حادثة H05 في ٣ أيام) ← دلوقتي حادثة واحدة «الإدارة».
    //      البصمة من أقدم ملف لكل فرع، فبتفضل ثابتة طول ما الوقفة مستمرة.
    $delayed = [];
    foreach ($findings as $i => $f) {
        if (is_array($f) && trim((string) ($f['code'] ?? '')) === 'H05'
            && str_starts_with(trim((string) ($f['title'] ?? $f['summary'] ?? '')), 'File in Import/HQ delayed')) {
            $delayed[$i] = $f;
        }
    }
    if (count($delayed) >= 2) {
        $names = [];
        $lines = [];
        foreach ($delayed as $i => $f) {
            $t = trim((string) ($f['title'] ?? $f['summary'] ?? ''));
            $lines[] = $t;
            if (preg_match('/\(([^,]+),/', $t, $m)) {
                $names[] = $m[1];
            }
            unset($findings[$i]);
        }
        sort($names);
        $findings[] = [
            'code'    => 'H05',
            'branch'  => 'hq',
            'title'   => 'الإدارة مابتستوردش: ملفات ' . count($delayed) . ' فروع متأخرة في Import\\HQ',
            'details' => implode("\n", $lines),
            'restart' => 'hq',
            'fp'      => sha1('H05|hq|delayed|' . implode('|', $names)),
        ];
    }

    // ١.٦) H10 دفعة واحدة لكل فرع لو فيه أكتر من مستند متأخر لنفس الفرع
    $h10ByBranch = [];
    foreach ($findings as $i => $f) {
        if (is_array($f) && trim((string) ($f['code'] ?? '')) === 'H10') {
            $br = trim((string) ($f['branch'] ?? ''));
            $h10ByBranch[$br][$i] = $f;
        }
    }
    foreach ($h10ByBranch as $br => $items) {
        if (count($items) >= 2) {
            $lines = [];
            $dates = [];
            foreach ($items as $i => $f) {
                $t = trim((string) ($f['title'] ?? $f['summary'] ?? ''));
                $lines[] = $t;
                if (preg_match('/date:\s*(\d{1,2})\/(\d{1,2})\/(\d{4})/i', $t, $m)) {
                    $dates[] = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[1], (int) $m[2]);
                } elseif (preg_match('/date:\s*(\d{4})-(\d{1,2})-(\d{1,2})/i', $t, $m)) {
                    $dates[] = sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
                }
                unset($findings[$i]);
            }
            $oldestDate = !empty($dates) ? min($dates) : date('Y-m-d');
            $findings[] = [
                'code'    => 'H10',
                'branch'  => $br,
                'title'   => 'مستندات واقفة أكتر من ٧ أيام في ' . $br . ': ' . count($items) . ' مستند',
                'details' => implode("\n", $lines),
                'restart' => null,
                'fp'      => sha1('H10|' . $br . '|batch|' . $oldestDate),
            ];
        }
    }

    // ٢) فحص وتحديث أو إدخال كل finding
    foreach ($findings as $f) {
        if (!is_array($f)) continue;

        $code = trim((string) ($f['code'] ?? ''));
        $fp = trim((string) ($f['fp'] ?? $f['sha'] ?? ''));
        if ($fp === '') continue;
        if ($code === 'H02' && is_array($activeBranches) && !empty($f['branch'])
            && !isset($activeBranches[(string) $f['branch']])) {
            continue;   // فرع مابيبيعش دلوقتي — مش مشكلة ربط (ومش في seen ← المفتوحة تتقفل)
        }

        // H05 من غير رقم أول ملف = الملف لسه بينزل (Resilio) والوكيل ماعرفش يقراه، مش فجوة.
        // ٢/١٠: ١٣ إنذار كاذب على بونجور واي بي سي، كلهم اتقفلوا لوحدهم وكل واحد شغّل تحليل.
        if ($code === 'H05' && preg_match('/Lowest file start:[ \t]*(\r?\n|$)/', (string) ($f['details'] ?? ''))) {
            continue;
        }

        $seenFpsInPayload[$fp] = true;
        if (isset($ignoredByFp[$fp]) && !isset($openByFp[$fp])) continue;
        $branch = !empty($f['branch']) ? trim((string) $f['branch']) : null;
        $title = mb_substr(trim((string) ($f['title'] ?? $f['summary'] ?? '')), 0, 200);
        $details = null;
        if (isset($f['details'])) {
            $details = is_string($f['details']) ? $f['details'] : json_encode($f['details'], JSON_UNESCAPED_UNICODE);
        }
        $restartKey = !empty($f['restart']) ? trim((string) $f['restart']) : null;
        // ملف فرع قاعد في Import\HQ أكتر من ساعة = repl_true على الإدارة مابيستوردش (م١).
        // ٢/١٠: الاستيراد وقف ٢١:٠٧ للأربع فروع والوكيل باعت H05 ده من غير restart، فمحدش
        // عمل حاجة. الريستارت هنا للإدارة مش للفرع (الملف وصل خلاص).
        if ($code === 'H05' && $restartKey === null && str_starts_with($title, 'File in Import/HQ delayed')) {
            $restartKey = 'hq';
        }

        if (isset($openByFp[$fp])) {
            // بصمته مفتوحة: نحدّث last_seen و seen_count و details ولا نغيّر status
            $incId = (int) $openByFp[$fp]['id'];
            $stUpdate->execute([$details, $incId]);

            // لسه موجودة بعد الريستارت ← نعيد (كل ساعة، ٣ مرات بالكتير)
            $inWin = !in_array($code, ['H02', 'H03'], true) || $inWindow;
            if ($restartKey !== null && $inWin && is_array($restartTries)) {
                [$n, $age] = $restartTries[$incId] ?? [0, 9999];
                if ($n < 3 && $age >= 60) {
                    rabt_queue_restart($restartKey, 'محاولة ' . ($n + 1) . ' — ' . $title, $incId);
                } elseif ($n >= 3 && $age >= 60 && $age < 70) {
                    $giveUp[] = $title;   // مرة واحدة: الفحص كل ١٠ دقايق
                }
            }
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

    // المقفول بإيد الأونر واختفى من الفحص ← يطلع من «المعروف»
    $goneIgnored = [];
    foreach ($ignoredByFp as $fp => $row) {
        if (in_array($row['check_code'], $checked, true) && !isset($seenFpsInPayload[$fp])) {
            $goneIgnored[] = (int) $row['id'];
        }
    }
    if (!empty($goneIgnored)) {
        $inG = implode(',', array_fill(0, count($goneIgnored), '?'));
        $pdo->prepare(
            "UPDATE rabt_incidents SET close_reason = 'قفلها الأونر — اختفت' WHERE id IN ($inG)"
        )->execute($goneIgnored);
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

    if (!empty($giveUp)) {
        try {
            $ids = $pdo->query(
                'SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.is_owner = 1 AND u.is_active = 1'
            )->fetchAll(PDO::FETCH_COLUMN);
            $wid = (int) setting('agent_watcher_id', '0');
            if ($wid > 0) $ids[] = $wid;
            require_once __DIR__ . '/push_lib.php';
            notify($pdo, $ids, 'rabt_guard', 'حارس الربط: ٣ ريستارت ماحلّوش — محتاج حد',
                $giveUp[0], 'rabt_guard.php');
        } catch (Throwable $e) {
        }
    }

    // ٤.٥) تذكير كل ٦ ساعات للحوادث المفتوحة القديمة
    try {
        $stDue = $pdo->query(
            "SELECT id, title, TIMESTAMPDIFF(HOUR, first_seen, NOW()) AS age_hours
               FROM rabt_incidents
              WHERE status <> 'closed'
                AND first_seen < NOW() - INTERVAL 6 HOUR
                AND (reminded_at IS NULL OR reminded_at < NOW() - INTERVAL 6 HOUR)
              ORDER BY first_seen ASC"
        );
        $dueRows = $stDue ? $stDue->fetchAll(PDO::FETCH_ASSOC) : [];
        if (!empty($dueRows)) {
            $ids = $pdo->query(
                'SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.is_owner = 1 AND u.is_active = 1'
            )->fetchAll(PDO::FETCH_COLUMN);
            $wid = (int) setting('agent_watcher_id', '0');
            if ($wid > 0) $ids[] = $wid;

            $countDue = count($dueRows);
            $oldest = $dueRows[0];
            $ageH = (int) ($oldest['age_hours'] ?? 0);
            $title = 'حارس الربط: ' . $countDue . ' مشكلة لسه مفتوحة';
            $body = $oldest['title'] . ' — من ' . $ageH . ' ساعة';

            require_once __DIR__ . '/push_lib.php';
            notify($pdo, $ids, 'rabt_guard', $title, $body, 'rabt_guard.php');

            $dueIds = array_column($dueRows, 'id');
            $inDue = implode(',', array_fill(0, count($dueIds), '?'));
            $pdo->prepare("UPDATE rabt_incidents SET reminded_at = NOW() WHERE id IN ($inDue)")
                ->execute($dueIds);
        }
    } catch (Throwable $e) {
        // لو العمود مش موجود أو فشل الإرسال — الحارس مايقعش
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
    // واتسحب ومارجعش نتيجة (الوكيل وقع في النص) — كان بيفضل «جاري» للأبد
    $pdo->exec(
        'UPDATE agent_commands
            SET status = \'expired\', result = \'اتسحب ومارجعش نتيجة\'
          WHERE status = \'picked\' AND picked_at < NOW() - INTERVAL 2 HOUR'
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
