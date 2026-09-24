<?php
/**
 * agent_lib.php — مراقبة وكلاء المزامنة
 * =====================================================================
 *  ٦ وكلاء بيغذّوا الموقع: الإدارة · دجلة · بونجور · ABC · الفجالة · إشراقة.
 *  الملف ده بيجاوب على سؤال واحد: كل واحد فيهم حي ولا ميت؟
 *
 *  المبدأ: **النبضة غير الداتا.**
 *  الوكيل بيقول "أنا هنا" كل دورة حتى لو مفيش بيع جديد. من غير كده،
 *  سكوت الوكيل بيبقى غامض — مش هنعرف الجهاز واقف ولا مفيش حركة.
 *
 *  ⚠️ التنبيه بيشتغل في وقت الشغل بس (10ص → 12:30ص). بالليل الفروع
 *     مقفولة والوكلاء مطفيين — تنبيه ساعتها إزعاج بلا معنى، وأول ما
 *     الناس تتعوّد تتجاهله هتتجاهله بالنهار كمان.
 * =====================================================================
 */

// سجل الأخطاء — endpoints الوكلاء (sync_beat · sync_branch) مابتعملش require لـauth.php
require_once __DIR__ . '/errlog_lib.php';
errlog_install();
require_once __DIR__ . '/config.php';

const AGENT_OK    = 'ok';
const AGENT_WARN  = 'warn';
const AGENT_ALERT = 'alert';
const AGENT_OFF   = 'off';    // برّه وقت الشغل — مش بنحكم عليه

/** إعداد برقم مع حد أدنى */
function agent_cfg_int(string $key, int $default, int $min = 1): int
{
    $v = (int) setting($key, (string) $default);
    return $v >= $min ? $v : $default;
}

/**
 * إحنا دلوقتي في وقت الشغل؟
 *
 * النافذة بتعدّي نص الليل (10:00 → 00:30) لأن الفروع بتقفل 12.
 * لو الفترة اتقلبت (from > to) يبقى دي نافذة عابرة لليوم اللي بعده.
 */
function agent_in_window(?DateTime $now = null): bool
{
    $now  = $now ?: new DateTime('now');
    $from = trim((string) setting('agent_watch_from', '10:00'));
    $to   = trim((string) setting('agent_watch_to',   '00:30'));

    $cur = (int) $now->format('H') * 60 + (int) $now->format('i');
    $f   = agent_hhmm($from, 10 * 60);
    $t   = agent_hhmm($to,   30);

    if ($f === $t) return true;              // نافذة 24 ساعة
    if ($f < $t)   return $cur >= $f && $cur < $t;
    return $cur >= $f || $cur < $t;          // عابرة نص الليل
}

function agent_hhmm(string $s, int $fallback): int
{
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $s, $m)) {
        $h = (int) $m[1]; $i = (int) $m[2];
        if ($h >= 0 && $h <= 23 && $i >= 0 && $i <= 59) return $h * 60 + $i;
    }
    return $fallback;
}

/** مقارنة رقمين bigint بأمان كنصوص أو أرقام 64-bit دون استخدام float */
function agent_id_cmp(string $a, string $b): int
{
    if (strlen($a) !== strlen($b)) {
        return strlen($a) <=> strlen($b);
    }
    return strcmp($a, $b);
}

/**
 * حالة كل الوكلاء.
 *
 * @return array صفوف فيها status و quiet_min و label
 */
function agent_list(): array
{
    $warnMin  = agent_cfg_int('agent_warn_min',  20);
    $alertMin = agent_cfg_int('agent_alert_min', 60);
    if ($alertMin < $warnMin) $alertMin = $warnMin;

    $inWin = agent_in_window();
    $now   = time();
    // طابور ربط سيستم الفروع (بريف ٥٠ و ٥٢) — دقايق من وصول أقدم أمر مستني / تأخر التصدير أو الاستلام
    $replLimit = agent_cfg_int('agent_repl_alert_min', 120, 15);

    try {
        $rows = db()->query(
            'SELECT * FROM agent_status WHERE is_active = 1 ORDER BY sort_order, agent_key'
        )->fetchAll();
    } catch (Throwable $ex) {
        return [];                            // الجدول لسه ما اتعملش
    }

    // استخراج أكبر أمر صدّرته الإدارة مرة واحدة قبل الحلقة
    $hqLastSent = null;
    foreach ($rows as $row) {
        if (($row['agent_key'] ?? '') === 'hq') {
            if (!empty($row['repl_last_sent']) && preg_match('/^\d{1,19}$/', (string) $row['repl_last_sent'])) {
                $hqLastSent = (string) $row['repl_last_sent'];
            }
            break;
        }
    }

    foreach ($rows as &$r) {
        $r['hq_last_sent'] = $hqLastSent;

        $beat  = $r['last_beat_at'] ? strtotime((string) $r['last_beat_at']) : null;
        $quiet = $beat === null ? null : (int) floor(($now - $beat) / 60);
        $r['quiet_min'] = $quiet;

        if (!$inWin) {
            $r['status'] = AGENT_OFF;
        } elseif ($quiet === null) {
            // عمره ما بعت — مش اتركّب لسه، أو التوكن غلط
            $r['status'] = AGENT_ALERT;
        } elseif ($quiet >= $alertMin) {
            $r['status'] = AGENT_ALERT;
        } elseif ($quiet >= $warnMin) {
            $r['status'] = AGENT_WARN;
        } else {
            $r['status'] = AGENT_OK;
        }

        $r['data_min'] = $r['last_data_at']
            ? (int) floor(($now - strtotime((string) $r['last_data_at'])) / 60)
            : null;

        // null = الوكيل قديم مابيبعتش بيانات ربط
        // stuck = واقف في الطابور (بريف ٥٠)
        // not_exporting = الإدارة مابتطلّعش ملفات (بريف ٥٢)
        // behind = الفرع مابيستلمش (بريف ٥٢)
        // ok = شغال سليم
        // الأولوية: stuck > behind
        $hasReplData = array_key_exists('repl_waiting', $r) && (
            $r['repl_waiting'] !== null ||
            ($r['repl_last_recv'] ?? null) !== null ||
            ($r['repl_send_pending'] ?? null) !== null
        );

        if (!$hasReplData) {
            $r['repl_status'] = null;
        } elseif ((int) ($r['repl_waiting'] ?? 0) > 0 && (int) ($r['repl_age_min'] ?? 0) >= $replLimit) {
            $r['repl_status'] = 'stuck';
        } elseif ($r['agent_key'] === 'hq') {
            if ($r['repl_send_pending'] !== null && (int) $r['repl_send_pending'] > 0 && (int) ($r['repl_send_age_min'] ?? 0) >= $replLimit) {
                $r['repl_status'] = 'not_exporting';
            } else {
                $r['repl_status'] = 'ok';
            }
        } else {
            $isBehind = false;
            if ($hqLastSent !== null && !empty($r['repl_last_recv']) && preg_match('/^\d{1,19}$/', (string) $r['repl_last_recv'])) {
                if (agent_id_cmp((string) $r['repl_last_recv'], $hqLastSent) < 0) {
                    $changedAt = $r['repl_recv_changed_at'] ?? null;
                    if ($changedAt) {
                        $staleMin = (int) floor(($now - strtotime((string) $changedAt)) / 60);
                        if ($staleMin >= $replLimit) {
                            $isBehind = true;
                        }
                    }
                }
            }
            $r['repl_status'] = $isBehind ? 'behind' : 'ok';
        }
    }
    unset($r);

    return $rows;
}

/**
 * ملخّص للتنبيه: الوكلاء اللي فيهم مشكلة دلوقتي.
 *
 * @return array ['level' => ok|warn|alert, 'warn' => [], 'alert' => [], 'repl' => []]
 *   repl = الفروع اللي طابور ربطها واقف أو متأخرة أو الإدارة مش بتصدر — في أي وقت (بريف ٥٠ و ٥٢)
 */
function agent_trouble(): array
{
    $warn = []; $alert = []; $repl = [];
    foreach (agent_list() as $a) {
        if ($a['status'] === AGENT_ALERT)     $alert[] = $a;
        elseif ($a['status'] === AGENT_WARN)  $warn[]  = $a;
        if (in_array($a['repl_status'] ?? null, ['stuck', 'behind', 'not_exporting'], true)) {
            $repl[] = $a;
        }
    }
    $level = ($alert || $repl) ? AGENT_ALERT : ($warn ? AGENT_WARN : AGENT_OK);
    return ['level' => $level, 'warn' => $warn, 'alert' => $alert, 'repl' => $repl];
}

/**
 * المستخدم ده المفروض يشوف تنبيه الوكلاء؟
 * الأونر دايماً + مستخدم واحد بيتحدد من الإعدادات.
 */
function agent_is_watcher(array $user): bool
{
    // الأونر بيتحدد بـ is_owner من جدول roles — مش بعمود role.
    if (!empty($user['is_owner'])) return true;
    $wid = (int) setting('agent_watcher_id', '0');
    return $wid > 0 && (int) ($user['id'] ?? 0) === $wid;
}

/** نص التنبيه اللي بيظهر في البانر */
function agent_nag_text(array $t): string
{
    $names = static function (array $rows): string {
        $out = [];
        foreach ($rows as $r) {
            $q = $r['quiet_min'];
            $out[] = $r['label'] . ($q === null ? ' (عمره ما بعت)' : ' (' . agent_ago($q) . ')');
        }
        return implode(' · ', $out);
    };

    // طابور وتصدير واستلام الربط (بريف ٥٠ و ٥٢) — بييجي الأول لأنه بيوقف الأصناف والأسعار كلها
    $replText = '';
    if (!empty($t['repl'])) {
        $out = [];
        foreach ($t['repl'] as $r) {
            $st = $r['repl_status'] ?? '';
            if ($st === 'not_exporting') {
                $out[] = $r['label'] . ' مابتطلّعش ملفات (' . (int) ($r['repl_send_pending'] ?? 0) . ' أمر من ' . agent_ago((int) ($r['repl_send_age_min'] ?? 0)) . ')';
            } elseif ($st === 'behind') {
                $out[] = $r['label'] . ' مابيستلمش (آخر أمر ' . ($r['repl_last_recv'] ?? '؟') . ' والإدارة وصلت ' . ($r['hq_last_sent'] ?? '؟') . ')';
            } else {
                // stuck
                $out[] = $r['label'] . ' واقف في الطابور (' . (int) $r['repl_waiting'] . ' أمر من ' . agent_ago((int) $r['repl_age_min']) . ')';
            }
        }
        $replText = 'ربط سيستم الفروع: ' . implode(' · ', $out) . '. الأصناف والأسعار الجديدة مش بتوصل.';
    }
    if (!$t['alert'] && !$t['warn']) return $replText;
    $pre = $replText !== '' ? $replText . ' — ' : '';

    if ($t['alert']) {
        $s = 'وكيل مزامنة واقف: ' . $names($t['alert']);
        if ($t['warn']) $s .= ' — وكمان متأخر: ' . $names($t['warn']);
        return $pre . $s . '. الأرصدة من الفروع دي مش بتتحدّث.';
    }
    return $pre . 'وكيل مزامنة متأخر: ' . $names($t['warn']) . '. لو استمر، الأرصدة هتبقى قديمة.';
}

/** «٣ دقايق» / «ساعتين» — للعرض */
function agent_ago(?int $min): string
{
    if ($min === null) return 'عمره ما بعت';
    if ($min < 1)   return 'دلوقتي';
    if ($min == 1)  return 'دقيقة';
    if ($min == 2)  return 'دقيقتين';
    if ($min < 11)  return $min . ' دقايق';
    if ($min < 60)  return $min . ' دقيقة';

    $h = (int) floor($min / 60);
    $r = $min % 60;
    if ($h == 1)      $s = 'ساعة';
    elseif ($h == 2)  $s = 'ساعتين';
    elseif ($h < 11)  $s = $h . ' ساعات';
    else              $s = $h . ' ساعة';
    if ($r >= 5) $s .= ' و' . $r . ' دقيقة';
    return $s;
}

/**
 * تسجيل نبضة من وكيل.
 *
 * @param string $key    مفتاح الوكيل
 * @param int    $rows   عدد صفوف الداتا اللي اتبعتت (0 = نبضة بس)
 * @param string $error  خطأ الوكيل عن نفسه، لو فيه
 */
function agent_beat(string $key, int $rows = 0, string $error = '',
                    string $version = '', string $host = ''): bool
{
    $key = substr(trim($key), 0, 32);
    if ($key === '') return false;

    $pdo = db();
    $st  = $pdo->prepare(
        'UPDATE agent_status
            SET last_beat_at  = NOW(),
                last_rows     = ?,
                agent_version = COALESCE(NULLIF(?, \'\'), agent_version),
                host_name     = COALESCE(NULLIF(?, \'\'), host_name),
                last_data_at  = CASE WHEN ? > 0 THEN NOW() ELSE last_data_at END,
                last_error    = CASE WHEN ? <> \'\' THEN ? ELSE last_error END,
                last_error_at = CASE WHEN ? <> \'\' THEN NOW() ELSE last_error_at END
          WHERE agent_key = ?'
    );
    $err = substr($error, 0, 255);
    $st->execute([$rows, $version, $host, $rows, $err, $err, $err, $key]);

    return $st->rowCount() > 0 || agent_exists($key);
}

/**
 * طابور ربط سيستم الفروع (db_repl_receive) — بريف ٥٠ و ٥٢.
 * الربط بيقف عند أول أمر يفشل ومابيقولش، فالوكيل بيعدّ المستني مع كل نبضة.
 * الإشعار بيتبعت **مرة واحدة** لكل وقفة: أول ما الوقفة تعدّي الحد، و`repl_alerted_at`
 * بيتصفّر لما الطابور يفضى والفرع يلحق — فالوقفة الجاية تبعت تاني.
 */
function agent_repl_beat(string $key, array $repl): void
{
    $waiting = max(0, (int) ($repl['waiting'] ?? 0));
    $age     = max(0, (int) ($repl['age_min'] ?? 0));
    $firstId = preg_match('/^\d{1,19}$/', (string) ($repl['first_id'] ?? '')) ? (string) $repl['first_id'] : null;
    $text    = mb_substr((string) ($repl['first_text'] ?? ''), 0, 255);
    if ($waiting === 0) { $age = 0; $firstId = null; $text = ''; }

    $hasRecv = isset($repl['last_recv']) && preg_match('/^\d{1,19}$/', (string) $repl['last_recv']);
    $recv    = $hasRecv ? (string) $repl['last_recv'] : null;

    $pdo = db();
    $pdo->prepare(
        'UPDATE agent_status
            SET repl_waiting = ?, repl_age_min = ?, repl_first_id = ?, repl_first_text = ?,
                repl_checked_at = NOW(),
                repl_recv_changed_at = CASE
                    WHEN ? IS NOT NULL AND NOT (repl_last_recv <=> ?) THEN NOW()
                    ELSE repl_recv_changed_at
                END,
                repl_last_recv = CASE WHEN ? IS NOT NULL THEN ? ELSE repl_last_recv END
          WHERE agent_key = ?'
    )->execute([$waiting, $age, $firstId, $text !== '' ? $text : null, $recv, $recv, $recv, $recv, $key]);

    // قراءة أكبر أمر صدّرته الإدارة (لو الوكيل فرع)
    $hqLastSent = null;
    if ($key !== 'hq') {
        $stHq = $pdo->query("SELECT repl_last_sent FROM agent_status WHERE agent_key = 'hq'");
        $val = $stHq ? $stHq->fetchColumn() : null;
        if ($val !== false && $val !== null && preg_match('/^\d{1,19}$/', (string) $val)) {
            $hqLastSent = (string) $val;
        }
    }

    $limit = agent_cfg_int('agent_repl_alert_min', 120, 15);
    $isStuck = ($waiting > 0 && $age >= $limit);

    // الحالة الحالية للفرع بعد التحديث
    $stCur = $pdo->prepare('SELECT repl_last_recv, repl_recv_changed_at FROM agent_status WHERE agent_key = ?');
    $stCur->execute([$key]);
    $curRow = $stCur->fetch();
    $curRecv = $curRow['repl_last_recv'] ?? null;
    $curChangedAt = $curRow['repl_recv_changed_at'] ?? null;

    $isBehind = false;
    $staleMin = 0;
    if ($key !== 'hq' && $hqLastSent !== null && $curRecv !== null) {
        if (agent_id_cmp((string) $curRecv, $hqLastSent) < 0) {
            if ($curChangedAt) {
                $staleMin = (int) floor((time() - strtotime((string) $curChangedAt)) / 60);
                if ($staleMin >= $limit) {
                    $isBehind = true;
                }
            }
        }
    }

    // تصفير تنبيه الوقفة لما الفرع يلحق والطابور يفضى
    $isCaughtUp = ($hqLastSent === null || $curRecv === null || agent_id_cmp((string) $curRecv, $hqLastSent) >= 0);
    if ($waiting === 0 && $isCaughtUp) {
        // الإدارة: متصفّرش لو التصدير لسه واقف — وإلا agent_repl_send_beat يبعت إشعار كل نبضة
        $pdo->prepare(
            'UPDATE agent_status SET repl_alerted_at = NULL
              WHERE agent_key = ? AND (agent_key <> \'hq\' OR COALESCE(repl_send_pending, 0) = 0)'
        )->execute([$key]);
        return;
    }

    // تنبيه ١: طابور واقف (الأولوية الأولى)
    if ($isStuck) {
        $claim = $pdo->prepare('UPDATE agent_status SET repl_alerted_at = NOW() WHERE agent_key = ? AND repl_alerted_at IS NULL');
        $claim->execute([$key]);
        if ($claim->rowCount() !== 1) return;

        $lb = $pdo->prepare('SELECT label FROM agent_status WHERE agent_key = ?');
        $lb->execute([$key]);
        $label = (string) ($lb->fetchColumn() ?: $key);

        $ids = $pdo->query(
            'SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.is_owner = 1 AND u.is_active = 1'
        )->fetchAll(PDO::FETCH_COLUMN);
        $wid = (int) setting('agent_watcher_id', '0');
        if ($wid > 0) $ids[] = $wid;

        require_once __DIR__ . '/push_lib.php';
        notify($pdo, $ids, 'agent_repl',
            'ربط ' . $label . ' واقف',
            $waiting . ' أمر مستني من ' . agent_ago($age) . ' — أول أمر واقف رقم ' . ($firstId ?? '؟'),
            'settings.php#agents');
        return;
    }

    // تنبيه ٢: الفرع مابيستلمش (behind)
    if ($isBehind) {
        $claim = $pdo->prepare('UPDATE agent_status SET repl_alerted_at = NOW() WHERE agent_key = ? AND repl_alerted_at IS NULL');
        $claim->execute([$key]);
        if ($claim->rowCount() !== 1) return;

        $lb = $pdo->prepare('SELECT label FROM agent_status WHERE agent_key = ?');
        $lb->execute([$key]);
        $label = (string) ($lb->fetchColumn() ?: $key);

        $ids = $pdo->query(
            'SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.is_owner = 1 AND u.is_active = 1'
        )->fetchAll(PDO::FETCH_COLUMN);
        $wid = (int) setting('agent_watcher_id', '0');
        if ($wid > 0) $ids[] = $wid;

        require_once __DIR__ . '/push_lib.php';
        notify($pdo, $ids, 'agent_repl',
            'ربط ' . $label . ' مابيستلمش',
            'آخر أمر ' . $curRecv . ' والإدارة وصلت ' . $hqLastSent . ' (ثابت من ' . agent_ago($staleMin) . ')',
            'settings.php#agents');
        return;
    }
}

/**
 * تصدير ربط سيستم الإدارة (db_repl_send) — بريف ٥٢.
 * الإدارة بتطلّع طابور واحد لكل الفروع. لو التصدير وقف، الفروع كلها مابيوصلهاش جديد.
 */
function agent_repl_send_beat(string $key, array $s): void
{
    $pending = max(0, (int) ($s['pending'] ?? 0));
    $pendAge = max(0, (int) ($s['pend_age'] ?? 0));
    $lastExp = preg_match('/^\d{1,19}$/', (string) ($s['last_exported'] ?? '')) ? (string) $s['last_exported'] : null;
    if ($pending === 0) { $pendAge = 0; }

    $pdo = db();
    $pdo->prepare(
        'UPDATE agent_status
            SET repl_send_pending = ?,
                repl_send_age_min = ?,
                repl_last_sent    = COALESCE(?, repl_last_sent),
                repl_checked_at   = NOW()
          WHERE agent_key = ?'
    )->execute([$pending, $pendAge, $lastExp, $key]);

    // تصفير تنبيه وقفة التصدير لما الطابور يفضى
    if ($pending === 0) {
        $pdo->prepare(
            'UPDATE agent_status
                SET repl_alerted_at = CASE WHEN repl_waiting IS NULL OR repl_waiting = 0 THEN NULL ELSE repl_alerted_at END
              WHERE agent_key = ?'
        )->execute([$key]);
        return;
    }

    $limit = agent_cfg_int('agent_repl_alert_min', 120, 15);
    if ($pendAge < $limit) return;

    // UPDATE شرطي = قفل: إشعار واحد لكل وقفة
    $claim = $pdo->prepare('UPDATE agent_status SET repl_alerted_at = NOW() WHERE agent_key = ? AND repl_alerted_at IS NULL');
    $claim->execute([$key]);
    if ($claim->rowCount() !== 1) return;

    $ids = $pdo->query(
        'SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.is_owner = 1 AND u.is_active = 1'
    )->fetchAll(PDO::FETCH_COLUMN);
    $wid = (int) setting('agent_watcher_id', '0');
    if ($wid > 0) $ids[] = $wid;

    require_once __DIR__ . '/push_lib.php';
    notify($pdo, $ids, 'agent_repl',
        'الإدارة مابتطلّعش ملفات ربط',
        $pending . ' أمر مستني تصدير من ' . agent_ago($pendAge),
        'settings.php#agents');
}

function agent_exists(string $key): bool
{
    $st = db()->prepare('SELECT 1 FROM agent_status WHERE agent_key = ?');
    $st->execute([$key]);
    return (bool) $st->fetchColumn();
}

/**
 * جدول الأرصدة اللي الموقع بيقرا منه.
 *
 *   hq     = الإدارة (بيستنى الترحيل) — السلوك القديم
 *   branch = الفروع مباشرة (لحظي)
 *
 * الـview فيه نفس أعمدة hq_balances وبيفلتر الأصفار زيها بالظبط،
 * فالتبديل مايحتاجش أي تعديل في منطق الاستعلامات.
 *
 * ⚠️ لو الجدول الجديد فاضي (الوكلاء لسه ما اشتغلوش)، بنرجع للإدارة
 *    تلقائياً. أهون بكتير من إننا نوري الناس أصفار على إنها حقيقة.
 */
function bal_table(): string
{
    static $cached = null;
    if ($cached !== null) return $cached;

    if (setting('balance_source', 'hq') !== 'branch') {
        return $cached = 'hq_balances';
    }
    try {
        $n = (int) db()->query('SELECT 1 FROM hq_branch_balances LIMIT 1')->fetchColumn();
        return $cached = ($n ? 'hq_branch_balances_nz' : 'hq_balances');
    } catch (Throwable $ex) {
        return $cached = 'hq_balances';     // الجدول لسه ما اتعملش
    }
}

/** بنقرا من الفروع دلوقتي؟ (للعرض في الشاشات) */
function bal_from_branches(): bool
{
    return bal_table() !== 'hq_balances';
}
