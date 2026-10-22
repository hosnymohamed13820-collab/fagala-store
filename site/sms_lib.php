<?php
/**
 * sms_lib.php — مكتبة تحليل رسايل البنك الأهلي وإنستاباي
 * =====================================================================
 *  - sms_parse(): استخراج بيانات الرسالة (داخل/خارج/كارت، المبلغ، الطرف،
 *                 الرقم المرجعي، وقت العملية، الرصيد المتاح).
 *  - sms_fetch(): جلب الرسايل من sms_inbox بفترة محددة وفلترة النوع.
 * =====================================================================
 */
declare(strict_types=1);

if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'sms_lib.php' || basename($_SERVER['PHP_SELF'] ?? '') === 'sms_lib.php') {
    require_once __DIR__ . '/auth.php';
    require_login();
    if (!is_owner()) {
        http_response_code(403);
        echo 'غير مصرح لك';
        exit;
    }
}

/**
 * البطاقة مسبقة الدفع متسجّلة على نفس رقم الموبايل ومالهاش علاقة بالشغل (قرار محمد ٣/١٠).
 * رسايلها بتفضل تتعرض في الصفحة بالأحمر، بس مابتدخلش الإجماليات ومالهاش «سجّل إذن».
 * شكلها: «… لبطاقتكم مسبقة الدفع …» / «… من بطاقتكم مسبقة الدفع …» / «تم خصم … من بطاقة المدفوعة مقدماً رقم 2943 …».
 * 🔴 حساب الشغل هو «لحسابكم/من حسابكم رقم 0150» — مش ده.
 */
const SMS_FOREIGN_LABEL = 'البطاقة مسبقة الدفع';

function sms_is_foreign(string $body): bool
{
    $b = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $body);   // «مقدماً» بتنوين
    return (bool) preg_match('/مسبق[ةه]\s+الدفع|المدفوع[ةه]\s+مقدما/u', (string) $b);
}

/**
 * تحليل نص رسالة البنك واستخراج عناصرها
 *
 * @param string $body   نص الرسالة
 * @param string $smsAt  تاريخ ووقت وصول الرسالة على الجهاز
 * @return array{kind: ?string, amount: ?float, party: ?string, ref: ?string, txn_at: ?string, available: ?float}
 */
function sms_parse(string $body, string $smsAt): array
{
    $nullResult = [
        'kind'      => null,
        'amount'    => null,
        'party'     => null,
        'ref'       => null,
        'txn_at'    => null,
        'available' => null,
    ];

    // ١. إزالة التشكيل والتطويل
    $clean = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $body);

    // ٢. تحويل الأرقام الهندية وفواصل الأرقام العربية
    $hindiMap = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '٫' => '.', '٬' => '',
    ];
    $clean = strtr($clean, $hindiMap);
    $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));

    if ($clean === '') {
        return $nullResult;
    }

    $kind      = null;
    $amount    = null;
    $party     = null;
    $ref       = null;
    $txnAt     = null;
    $available = null;

    // استخراج السنة والشهر المرجعي من وقت وصول الرسالة sms_at
    $baseTs = strtotime($smsAt);
    $baseYear  = $baseTs !== false ? (int) date('Y', $baseTs) : null;
    $baseMonth = $baseTs !== false ? (int) date('n', $baseTs) : null;

    // دالة مساعدة لحساب وقت العملية بدقة مع معالجة فرق السنة
    $resolveTxnAt = function (int $m, int $d, int $h, int $min) use ($baseYear, $baseMonth): ?string {
        if ($baseYear === null || $baseMonth === null) {
            return null;
        }
        // لو شهر العملية في الرسالة أكبر من شهر وصولها (مثال: رسالة ديسمبر وصلت يناير) -> سنة سابقة
        $year = ($m > $baseMonth) ? ($baseYear - 1) : $baseYear;
        if (!checkdate($m, $d, $year) || $h < 0 || $h > 23 || $min < 0 || $min > 59) {
            return null;
        }
        return sprintf('%04d-%02d-%02d %02d:%02d:00', $year, $m, $d, $h, $min);
    };

    // [الشكل الأول: خارج] تم تنفيذ تحويل لحظي
    if (preg_match('/^تم تنفيذ تحويل لحظي/u', $clean)) {
        $kind = 'out';

        // المبلغ بعد «بمبلغ»
        if (preg_match('/بمبلغ\s*([\d,]+(?:\.\d+)?)/u', $clean, $mAmount)) {
            $amount = (float) str_replace(',', '', $mAmount[1]);
        }

        // الطرف بعد «إلى» لحد «رقم مرجعي»
        if (preg_match('/إلى\s+(.*?)\s+رقم مرجعي/su', $clean, $mParty)) {
            $p = trim($mParty[1]);
            $party = $p !== '' ? $p : null;
        }

        // الرقم المرجعي بعد «رقم مرجعي» (كنص)
        if (preg_match('/رقم مرجعي\s*(\d+)/u', $clean, $mRef)) {
            $ref = (string) $mRef[1];
        }

        // وقت العملية: شهر-يوم
        if (preg_match('/يوم\s+(\d{1,2})-(\d{1,2})\s+الساع[ةه]\s+(\d{1,2}):(\d{1,2})/u', $clean, $mDate)) {
            $txnAt = $resolveTxnAt((int) $mDate[1], (int) $mDate[2], (int) $mDate[3], (int) $mDate[4]);
        }
    }
    // [الشكل الثاني: كارت] تم خصم
    elseif (preg_match('/^تم خصم/u', $clean)) {
        $kind = 'card';

        // المبلغ بعد «تم خصم»
        if (preg_match('/تم خصم\s*([\d,]+(?:\.\d+)?)/u', $clean, $mAmount)) {
            $amount = (float) str_replace(',', '', $mAmount[1]);
        }

        // الطرف بعد «عند» لحد «يوم»
        if (preg_match('/عند\s+(.*?)\s+يوم/su', $clean, $mParty)) {
            $p = trim($mParty[1]);
            $party = $p !== '' ? $p : null;
        }

        // وقت العملية: يوم/شهر
        if (preg_match('/يوم\s+(\d{1,2})\/(\d{1,2})\s+الساع[ةه]\s+(\d{1,2}):(\d{1,2})/u', $clean, $mDate)) {
            $txnAt = $resolveTxnAt((int) $mDate[2], (int) $mDate[1], (int) $mDate[3], (int) $mDate[4]);
        }

        // الرصيد المتاح بعد «المتاح»
        if (preg_match('/المتاح\s*([\d,]+(?:\.\d+)?)/u', $clean, $mAvail)) {
            $available = (float) str_replace(',', '', $mAvail[1]);
        }
    }
    // [الشكل الثالث: داخل] تم إضافة تحويل لحظي
    elseif (preg_match('/^تم [إا]ضاف[ةه] تحويل لحظي/u', $clean)) {
        $kind = 'in';

        // المبلغ بعد «بمبلغ»
        if (preg_match('/بمبلغ\s*([\d,]+(?:\.\d+)?)/u', $clean, $mAmount)) {
            $amount = (float) str_replace(',', '', $mAmount[1]);
        }

        // الطرف بعد «من» لحد «رقم مرجعي»
        if (preg_match('/بمبلغ.*?من\s+(.*?)\s+رقم مرجعي/su', $clean, $mParty)) {
            $p = trim($mParty[1]);
            $party = $p !== '' ? $p : null;
        }

        // الرقم المرجعي بعد «رقم مرجعي» (كنص)
        if (preg_match('/رقم مرجعي\s*(\d+)/u', $clean, $mRef)) {
            $ref = (string) $mRef[1];
        }

        // وقت العملية: شهر-يوم
        if (preg_match('/يوم\s+(\d{1,2})-(\d{1,2})\s+الساع[ةه]\s+(\d{1,2}):(\d{1,2})/u', $clean, $mDate)) {
            $txnAt = $resolveTxnAt((int) $mDate[1], (int) $mDate[2], (int) $mDate[3], (int) $mDate[4]);
        }
    }

    // شرط السلامة: لا توجد حركة صحيحة بدون مبلغ ومحدد النوع
    if ($kind === null || $amount === null || $amount <= 0) {
        return $nullResult;
    }

    return [
        'kind'      => $kind,
        'amount'    => $amount,
        'party'     => $party,
        'ref'       => $ref,
        'txn_at'    => $txnAt,
        'available' => $available,
    ];
}

/**
 * جلب الرسايل من قاعدة البيانات مع التحليل والفلترة
 *
 * @param PDO    $pdo
 * @param string $from  تاريخ البداية (YYYY-MM-DD)
 * @param string $to    تاريخ النهاية (YYYY-MM-DD)
 * @param string $kind  نوع الفلتر (all, in, out, card, unknown)
 * @return array
 */
function sms_fetch(PDO $pdo, string $from, string $to, string $kind = 'all'): array
{
    $from = trim($from);
    $to   = trim($to);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $from = date('Y-m-d', strtotime('-30 days'));
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $to = date('Y-m-d');
    }

    $st = $pdo->prepare(
        "SELECT s.id, s.sender, s.body, s.sms_at, s.source, s.device, s.received_at,
                COALESCE(m.note, '') AS note, COALESCE(m.color, '') AS color
         FROM sms_inbox s
         LEFT JOIN sms_marks m ON m.sms_id = s.id
         WHERE s.sms_at >= ? AND s.sms_at < DATE_ADD(?, INTERVAL 1 DAY)
         ORDER BY s.sms_at DESC"
    );
    $st->execute([$from, $to]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $res = [];
    foreach ($rows as $r) {
        $parsed = sms_parse((string) ($r['body'] ?? ''), (string) ($r['sms_at'] ?? ''));
        $r['kind']      = $parsed['kind'];
        $r['amount']    = $parsed['amount'];
        $r['party']     = $parsed['party'];
        $r['ref']       = $parsed['ref'];
        $r['txn_at']    = $parsed['txn_at'];
        $r['available'] = $parsed['available'];
        $r['parsed']    = $parsed;
        $r['foreign']   = sms_is_foreign((string) ($r['body'] ?? ''));

        if ($kind === 'in' && $parsed['kind'] !== 'in') {
            continue;
        }
        if ($kind === 'out' && $parsed['kind'] !== 'out') {
            continue;
        }
        if ($kind === 'card' && $parsed['kind'] !== 'card') {
            continue;
        }
        if ($kind === 'unknown' && $parsed['kind'] !== null) {
            continue;
        }

        $res[] = $r;
    }

    return $res;
}

/**
 * إحصائيات صندوق الرسائل (إجمالي، وصول آخر رسالة، سحب، مباشر)
 *
 * @return array{total_count: int, last_received: string, backfill_count: int, live_count: int}
 */
function sms_get_stats(PDO $pdo): array
{
    $statsSt = $pdo->query(
        "SELECT
            COUNT(*) AS total_count,
            MAX(received_at) AS last_received,
            SUM(CASE WHEN source = 'backfill' THEN 1 ELSE 0 END) AS backfill_count,
            SUM(CASE WHEN source = 'live' THEN 1 ELSE 0 END) AS live_count
         FROM sms_inbox"
    );
    $stats = $statsSt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'total_count'    => (int) ($stats['total_count'] ?? 0),
        'last_received'  => $stats['last_received'] ? (string) $stats['last_received'] : 'لا يوجد بعد',
        'backfill_count' => (int) ($stats['backfill_count'] ?? 0),
        'live_count'     => (int) ($stats['live_count'] ?? 0),
    ];
}

/**
 * جلب كلمات الفلتر من الإعدادات
 *
 * @return array{include_keywords: string, exclude_keywords: string}
 */
function sms_get_keywords(PDO $pdo): array
{
    $setSt = $pdo->query(
        "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('sms_include_keywords', 'sms_exclude_keywords')"
    );
    $settingsMap = [];
    while ($row = $setSt->fetch(PDO::FETCH_ASSOC)) {
        $settingsMap[(string) $row['setting_key']] = (string) $row['setting_value'];
    }
    return [
        'include_keywords' => $settingsMap['sms_include_keywords'] ?? '',
        'exclude_keywords' => $settingsMap['sms_exclude_keywords'] ?? '',
    ];
}

/**
 * تدقيق وضبط معاملات التاريخ والنوع
 *
 * @return array{from: string, to: string, kind: string}
 */
function sms_parse_period(?string $from, ?string $to, ?string $kind): array
{
    $from = trim((string) ($from ?? ''));
    $to   = trim((string) ($to ?? ''));
    $kind = trim((string) ($kind ?? 'all'));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $from = date('Y-m-d', strtotime('-30 days'));
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $to = date('Y-m-d');
    }

    $validKinds = ['all', 'in', 'out', 'card', 'unknown'];
    if (!in_array($kind, $validKinds, true)) {
        $kind = 'all';
    }

    return [
        'from' => $from,
        'to'   => $to,
        'kind' => $kind,
    ];
}

/**
 * حساب إجماليات الرسائل في الفترة
 *
 * @param array $allMessages
 * @return array{
 *     in_count: int,
 *     in_amount: float,
 *     out_count: int,
 *     out_amount: float,
 *     unknown_count: int,
 *     foreign_count: int,
 *     net_amount: float
 * }
 */
function sms_calc_totals(array $allMessages): array
{
    $totInCount   = 0;
    $totInAmount  = 0.0;
    $totOutCount  = 0;
    $totOutAmount = 0.0;
    $unknownCount = 0;
    $foreignCount = 0;

    foreach ($allMessages as $m) {
        $k = $m['kind'];
        if (!empty($m['foreign'])) {
            $foreignCount++;
            continue;
        }
        if ($k === 'in') {
            $totInCount++;
            $totInAmount += (float) $m['amount'];
        } elseif ($k === 'out' || $k === 'card') {
            $totOutCount++;
            $totOutAmount += (float) $m['amount'];
        } else {
            $unknownCount++;
        }
    }
    $netAmount = $totInAmount - $totOutAmount;

    return [
        'in_count'      => $totInCount,
        'in_amount'     => $totInAmount,
        'out_count'     => $totOutCount,
        'out_amount'    => $totOutAmount,
        'unknown_count' => $unknownCount,
        'foreign_count' => $foreignCount,
        'net_amount'    => $netAmount,
    ];
}

/**
 * بيانات ربط تطبيق الموبايل (التوكن للأونر فقط)
 *
 * @return array{hook_url: string, hook_token: string}
 */
function sms_get_webhook_info(bool $isOwner): array
{
    return [
        'hook_url'   => 'https://hosnimanagement.com/sms_hook.php',
        'hook_token' => $isOwner ? (string) (SYNC_AGENT_TOKENS['sms_phone'] ?? '') : '',
    ];
}
