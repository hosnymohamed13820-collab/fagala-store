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
        "SELECT id, sender, body, sms_at, source, device, received_at
         FROM sms_inbox
         WHERE sms_at >= ? AND sms_at < DATE_ADD(?, INTERVAL 1 DAY)
         ORDER BY sms_at DESC"
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
