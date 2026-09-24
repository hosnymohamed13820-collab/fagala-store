<?php
/**
 * recon_lib.php — مكتبة مطابقة الفروع والإدارة
 * نظام الفجالة ستور المالي
 *
 * مخصصة لعمليات مطابقة البضاعة بين الإدارة المركزية وفروع الشركة
 * وقراءة وكتابة علامات التلوين اليدوية (recon_marks).
 */

require_once __DIR__ . '/config.php';

// erp = hq_invoices.supplier_id بتاع «الطرف ده كمورد» في دفاتر الباقيين
// sid = suppliers.id عندنا
const SUPP_BRANCHES = [
    'fagala'  => ['label' => 'الفجالة',  'cb' => 2, 'bid' => 3, 'erp' => 3000091, 'sid' => 7],
    'bonjour' => ['label' => 'بونجور',   'cb' => 3, 'bid' => 1, 'erp' => 4,       'sid' => 24],
    'degla'   => ['label' => 'دجلة',     'cb' => 5, 'bid' => 4, 'erp' => 1000180, 'sid' => 21],
    'abc'     => ['label' => 'اي بي سي', 'cb' => 6, 'bid' => 2, 'erp' => 1000181, 'sid' => 10],
];

// الإدارة طرف في المطابقة
const SUPP_HQ_PARTY = ['label' => 'الإدارة', 'cb' => 1, 'erp' => 1000182, 'sid' => 4];

// الأزواج العشرة بين الأطراف الخمسة (الإدارة + 4 فروع)
const RECON_PAIRS = [
    ['hq', 'fagala'],
    ['hq', 'bonjour'],
    ['hq', 'degla'],
    ['hq', 'abc'],
    ['fagala', 'bonjour'],
    ['fagala', 'degla'],
    ['fagala', 'abc'],
    ['bonjour', 'degla'],
    ['bonjour', 'abc'],
    ['degla', 'abc'],
];

/**
 * جلب جميع الأطراف (الإدارة + الفروع)
 */
function recon_all_parties(): array
{
    return array_merge(['hq' => SUPP_HQ_PARTY], SUPP_BRANCHES);
}

/**
 * الاستعلام التجميعي الواحد لبناء مصفوفة حركات المطابقة لجميع الأطراف
 *
 * @return array [cb => [supplier_id => [doc_type => ['cnt' => int, 'amt' => float]]]]
 */
function recon_get_matrix(PDO $pdo, string $from, string $to): array
{
    $allParties = recon_all_parties();
    $allErpIds = array_column($allParties, 'erp');
    $inErp = implode(',', array_fill(0, count($allErpIds), '?'));

    $sql = "SELECT company_branch, supplier_id, doc_type, COUNT(*) AS cnt, SUM(net_amount) AS total_amt
            FROM hq_invoices
            WHERE invoice_date >= ? AND invoice_date < DATE_ADD(?, INTERVAL 1 DAY)
              AND doc_type IN (2, 6)
              AND supplier_id IN ($inErp)
            GROUP BY company_branch, supplier_id, doc_type";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$from, $to], $allErpIds));
    $rows = $stmt->fetchAll();

    $matrix = [];
    foreach ($rows as $r) {
        $cb = ($r['company_branch'] === null) ? 1 : (int)$r['company_branch'];
        $cell = &$matrix[$cb][(int)$r['supplier_id']][(int)$r['doc_type']];
        $cell = [
            'cnt' => (int)($cell['cnt'] ?? 0) + (int)$r['cnt'],
            'amt' => (float)($cell['amt'] ?? 0) + (float)$r['total_amt'],
        ];
        unset($cell);
    }
    return $matrix;
}

/**
 * حساب بيانات زوج من الأطراف (تحديد من/إلى وصافي الخارج وصافي الواصل والفرق)
 */
function recon_calc_pair(array $matrix, string $p1Key, string $p2Key): array
{
    $allParties = recon_all_parties();
    $p1 = $allParties[$p1Key];
    $p2 = $allParties[$p2Key];

    // حركات P1 بالنسبة لـ P2 في دفاتر P1:
    // dt=6 (مردود = خرج من P1 إلى P2)
    // dt=2 (شراء = دخل P1 من P2)
    $p1Out = $matrix[$p1['cb']][$p2['erp']][6] ?? ['cnt' => 0, 'amt' => 0.0];
    $p1In  = $matrix[$p1['cb']][$p2['erp']][2] ?? ['cnt' => 0, 'amt' => 0.0];
    $netOut1 = (float)$p1Out['amt'] - (float)$p1In['amt'];

    // حركات P2 بالنسبة لـ P1 في دفاتر P2:
    // dt=2 (شراء = دخل P2 من P1)
    // dt=6 (مردود = خرج من P2 إلى P1)
    $p2In  = $matrix[$p2['cb']][$p1['erp']][2] ?? ['cnt' => 0, 'amt' => 0.0];
    $p2Out = $matrix[$p2['cb']][$p1['erp']][6] ?? ['cnt' => 0, 'amt' => 0.0];
    $netIn2 = (float)$p2In['amt'] - (float)$p2Out['amt'];

    // الاتجاه: «من» = الطرف اللي بعت أكتر (لو الصافي سالب اقلب الزوج عشان الأرقام تطلع موجبة)
    if (($netOut1 + $netIn2) < 0) {
        // قلب الزوج: P2 هو «من» و P1 هو «إلى»
        $fromKey = $p2Key;
        $fromParty = $p2;
        $toKey = $p1Key;
        $toParty = $p1;

        $fromSent = $p2Out;
        $fromRet  = $p2In;
        $netSent  = (float)$p2Out['amt'] - (float)$p2In['amt'];

        $toRecv   = $p1In;
        $toRet    = $p1Out;
        $netRecv  = (float)$p1In['amt'] - (float)$p1Out['amt'];
    } else {
        $fromKey = $p1Key;
        $fromParty = $p1;
        $toKey = $p2Key;
        $toParty = $p2;

        $fromSent = $p1Out;
        $fromRet  = $p1In;
        $netSent  = $netOut1;

        $toRecv   = $p2In;
        $toRet    = $p2Out;
        $netRecv  = $netIn2;
    }

    $diff = $netSent - $netRecv;

    return [
        'from_key'   => $fromKey,
        'from_label' => $fromParty['label'],
        'to_key'     => $toKey,
        'to_label'   => $toParty['label'],
        'net_sent'   => $netSent,
        'from_sent'  => $fromSent,
        'from_ret'   => $fromRet,
        'net_recv'   => $netRecv,
        'to_recv'    => $toRecv,
        'to_ret'     => $toRet,
        'diff'       => $diff,
        'is_match'   => abs($diff) < 0.01,
    ];
}

/**
 * حساب جدول الأزواج العشرة كاملة ومرتبة بحيث تظهر الأزواج التي بها فرق أولاً
 */
function recon_get_all_pairs(array $matrix): array
{
    $pairs = [];
    foreach (RECON_PAIRS as [$p1, $p2]) {
        $pairs[] = recon_calc_pair($matrix, $p1, $p2);
    }

    // الترتيب: الأزواج التي فيها فرق أولاً، مرتبة بـ abs(الفرق) تنازلياً
    usort($pairs, function ($a, $b) {
        if ($a['is_match'] !== $b['is_match']) {
            return $a['is_match'] ? 1 : -1; // التي بها فرق أولاً
        }
        return abs($b['diff']) <=> abs($a['diff']);
    });

    return $pairs;
}

/**
 * جلب كشف تفصيلي لحركات فواتير الطرفين
 */
function recon_get_party_moves(PDO $pdo, string $from, string $to, string $pAKey, string $pBKey): array
{
    $allParties = recon_all_parties();
    if (!isset($allParties[$pAKey], $allParties[$pBKey])) {
        return [];
    }

    $pA = $allParties[$pAKey];
    $pB = $allParties[$pBKey];

    // جدول دفاتر A: فواتير A حيث supplier_id = erp الطرف B
    $whereCbA = ($pAKey === 'hq') ? "(company_branch = 1 OR company_branch IS NULL)" : "company_branch = ?";
    $sqlA = "SELECT sth_id, suffix, DATE(invoice_date) AS tx_date, doc_type, doc_no, net_amount
             FROM hq_invoices
             WHERE invoice_date >= ? AND invoice_date < DATE_ADD(?, INTERVAL 1 DAY)
               AND doc_type IN (2, 6)
               AND $whereCbA
               AND supplier_id = ?
             ORDER BY invoice_date ASC, sth_id ASC";
    $paramsA = [$from, $to];
    if ($pAKey !== 'hq') {
        $paramsA[] = $pA['cb'];
    }
    $paramsA[] = $pB['erp'];
    $stmtA = $pdo->prepare($sqlA);
    $stmtA->execute($paramsA);
    $movesA = $stmtA->fetchAll();

    // جدول دفاتر B: فواتير B حيث supplier_id = erp الطرف A
    $whereCbB = ($pBKey === 'hq') ? "(company_branch = 1 OR company_branch IS NULL)" : "company_branch = ?";
    $sqlB = "SELECT sth_id, suffix, DATE(invoice_date) AS tx_date, doc_type, doc_no, net_amount
             FROM hq_invoices
             WHERE invoice_date >= ? AND invoice_date < DATE_ADD(?, INTERVAL 1 DAY)
               AND doc_type IN (2, 6)
               AND $whereCbB
               AND supplier_id = ?
             ORDER BY invoice_date ASC, sth_id ASC";
    $paramsB = [$from, $to];
    if ($pBKey !== 'hq') {
        $paramsB[] = $pB['cb'];
    }
    $paramsB[] = $pA['erp'];
    $stmtB = $pdo->prepare($sqlB);
    $stmtB->execute($paramsB);
    $movesB = $stmtB->fetchAll();

    $totA_buy = 0.0;
    $totA_ret = 0.0;
    foreach ($movesA as $m) {
        if ((int)$m['doc_type'] === 2) {
            $totA_buy += (float)$m['net_amount'];
        } else {
            $totA_ret += (float)$m['net_amount'];
        }
    }

    $totB_buy = 0.0;
    $totB_ret = 0.0;
    foreach ($movesB as $m) {
        if ((int)$m['doc_type'] === 2) {
            $totB_buy += (float)$m['net_amount'];
        } else {
            $totB_ret += (float)$m['net_amount'];
        }
    }

    return [
        'partyA'    => $pA,
        'partyB'    => $pB,
        'movesA'    => $movesA,
        'movesB'    => $movesB,
        'totA_buy'  => $totA_buy,
        'totA_ret'  => $totA_ret,
        'totB_buy'  => $totB_buy,
        'totB_ret'  => $totB_ret,
    ];
}

/**
 * قراءة علامات التلوين لقائمة فواتير باستعلام واحد
 *
 * @param array $sthIds قائمة أرقام sth_id
 * @return array ['sth_id_suffix' => 'color']
 */
function recon_get_marks(PDO $pdo, array $sthIds): array
{
    $sthIds = array_values(array_unique(array_filter(array_map('intval', $sthIds))));
    if (empty($sthIds)) {
        return [];
    }

    $marks = [];
    try {
        $ph = implode(',', array_fill(0, count($sthIds), '?'));
        $stmt = $pdo->prepare("SELECT sth_id, suffix, color FROM recon_marks WHERE sth_id IN ($ph)");
        $stmt->execute($sthIds);
        while ($row = $stmt->fetch()) {
            $marks[$row['sth_id'] . '_' . $row['suffix']] = $row['color'];
        }
    } catch (PDOException) {
        // الجدول قد لا يكون موجوداً بعد
    }
    return $marks;
}
