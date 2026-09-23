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
    // طابور ربط سيستم الفروع (بريف ٥٠) — دقايق من وصول أقدم أمر مستني
    $replLimit = agent_cfg_int('agent_repl_alert_min', 120, 15);

    try {
        $rows = db()->query(
            'SELECT * FROM agent_status WHERE is_active = 1 ORDER BY sort_order, agent_key'
        )->fetchAll();
    } catch (Throwable $ex) {
        return [];                            // الجدول لسه ما اتعملش
    }

    foreach ($rows as &$r) {
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

        // null = الوكيل قديم أو مابيقراش الطابور (أو الأعمدة لسه مااتضافتش) · stuck = عدّى الحد
        $r['repl_status'] = !array_key_exists('repl_waiting', $r) || $r['repl_waiting'] === null ? null
            : (((int) $r['repl_waiting'] > 0 && (int) $r['repl_age_min'] >= $replLimit) ? 'stuck' : 'ok');
    }
    unset($r);

    return $rows;
}

/**
 * ملخّص للتنبيه: الوكلاء اللي فيهم مشكلة دلوقتي.
 *
 * @return array ['level' => ok|warn|alert, 'warn' => [], 'alert' => [], 'repl' => []]
 *   repl = الفروع اللي طابور ربطها واقف — في أي وقت، مش وقت الشغل بس (بريف ٥٠)
 */
function agent_trouble(): array
{
    $warn = []; $alert = []; $repl = [];
    foreach (agent_list() as $a) {
        if ($a['status'] === AGENT_ALERT)     $alert[] = $a;
        elseif ($a['status'] === AGENT_WARN)  $warn[]  = $a;
        if (($a['repl_status'] ?? null) === 'stuck') $repl[] = $a;
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

    // طابور الربط الواقف (بريف ٥٠) — بييجي الأول لأنه بيوقف الأصناف والأسعار كلها
    $replText = '';
    if (!empty($t['repl'])) {
        $out = [];
        foreach ($t['repl'] as $r) {
            $out[] = $r['label'] . ' (' . (int) $r['repl_waiting'] . ' أمر من ' . agent_ago((int) $r['repl_age_min']) . ')';
        }
        $replText = 'ربط سيستم الفروع واقف: ' . implode(' · ', $out) . '. الأصناف والأسعار الجديدة مش بتوصل.';
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
 * طابور ربط سيستم الفروع (db_repl_receive) — بريف ٥٠.
 * الربط بيقف عند أول أمر يفشل ومابيقولش، فالوكيل بيعدّ المستني مع كل نبضة.
 * الإشعار بيتبعت **مرة واحدة** لكل وقفة: أول ما الوقفة تعدّي الحد، و`repl_alerted_at`
 * بيتصفّر لما الطابور يفضى — فالوقفة الجاية تبعت تاني.
 */
function agent_repl_beat(string $key, array $repl): void
{
    $waiting = max(0, (int) ($repl['waiting'] ?? 0));
    $age     = max(0, (int) ($repl['age_min'] ?? 0));
    $firstId = preg_match('/^\d{1,19}$/', (string) ($repl['first_id'] ?? '')) ? (string) $repl['first_id'] : null;
    $text    = mb_substr((string) ($repl['first_text'] ?? ''), 0, 255);
    if ($waiting === 0) { $age = 0; $firstId = null; $text = ''; }

    $pdo = db();
    $pdo->prepare(
        'UPDATE agent_status
            SET repl_waiting = ?, repl_age_min = ?, repl_first_id = ?, repl_first_text = ?,
                repl_checked_at = NOW(),
                repl_alerted_at = CASE WHEN ? = 0 THEN NULL ELSE repl_alerted_at END
          WHERE agent_key = ?'
    )->execute([$waiting, $age, $firstId, $text !== '' ? $text : null, $waiting, $key]);

    $limit = agent_cfg_int('agent_repl_alert_min', 120, 15);
    if ($waiting === 0 || $age < $limit) return;

    // UPDATE شرطي = قفل: نبضتين في نفس اللحظة مايبعتوش إشعارين
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
