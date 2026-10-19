<?php
/**
 * supplier_purchases_lib.php — مكتبة حسابات تقرير «مشتريات مورد» (بريف ١٢٥)
 * نظام الفجالة ستور المالي
 *
 * الحسبة مأخوذة بالحرف ومطابقة لبرنامج True في الإدارة:
 * - مشتريات: SUM(net_amount) لـ doc_type = 2
 * - مردودات: SUM(net_amount) لـ doc_type = 6 (بالسالب)
 * - خصومات: SUM(total_amount - net_amount) لـ doc_type = 2 (للعلم بس ولا تُطرح من الصافي)
 * - الصافي: المشتريات − المردودات
 */
declare(strict_types=1);

/**
 * جلب صفوف المصدر الأساسية للتقرير.
 *
 * // النهارده من hq_invoices (True). لما فواتير الشراء تتسجّل على الموقع، الدالة دي بس اللي تتغيّر.
 *
 * @param string   $from   تاريخ البداية (YYYY-MM-DD)
 * @param string   $to     تاريخ النهاية (YYYY-MM-DD)
 * @param int|null $branch معرّف الفرع (company_branch)، أو null لكل الفروع
 * @return array<int, array{
 *     supplier_code: string,
 *     supplier_name: string,
 *     payment_type: ?string,
 *     purchases: float,
 *     returns: float,
 *     discounts: float,
 *     net: float
 * }>
 */
function sp_source_rows(string $from, string $to, ?int $branch = null): array
{
    $pdo = db();

    // الفترة: invoice_date >= من و < (إلى + يوم)
    $fromTs = $from . ' 00:00:00';
    $toTs   = date('Y-m-d 00:00:00', strtotime($to . ' +1 day'));

    $where = [
        'h.invoice_date >= ?',
        'h.invoice_date < ?',
        'h.doc_type IN (2, 6)',
    ];
    $params = [$fromTs, $toTs];

    if ($branch !== null && $branch > 0) {
        $where[] = 'h.company_branch = ?';
        $params[] = $branch;
    }

    $whereSql = implode(' AND ', $where);

    // استعلام واحد مجمّع يربط مع جدول suppliers لجلب payment_type
    // والترتيب بالكود كرقم ثم كنص.
    //
    // التجميع بالكود بس — لأن الاسم بيتخزّن مع كل فاتورة في hq_invoices،
    // فلو المورد اتغيّر اسمه في True في نص الفترة كان بيطلع سطرين (تقرير True
    // بيجمّع بالمورد). والاسم المعروض = اسم **أحدث فاتورة** في الفترة:
    // GROUP_CONCAT مرتب بالتاريخ تنازلي وبعدين أول عنصر بالفاصل \x1F.
    // (مفيش قلق من قص GROUP_CONCAT الافتراضي ١٠٢٤ بايت: القص بيحصل في الآخر
    //  والاسم الأحدث محفوظ في الأول.)
    $sql = "SELECT
                COALESCE(h.supplier_code, '') AS supplier_code,
                SUBSTRING_INDEX(GROUP_CONCAT(h.supplier_name ORDER BY h.invoice_date DESC SEPARATOR '\x1F'), '\x1F', 1) AS supplier_name,
                MAX(s.payment_type) AS payment_type,
                SUM(CASE WHEN h.doc_type = 2 THEN h.net_amount ELSE 0 END) AS purchases,
                SUM(CASE WHEN h.doc_type = 6 THEN h.net_amount ELSE 0 END) AS returns_amount,
                SUM(CASE WHEN h.doc_type = 2 THEN (h.total_amount - h.net_amount) ELSE 0 END) AS discounts
            FROM hq_invoices h
            LEFT JOIN suppliers s ON s.erp_supplier_id = h.supplier_id
            WHERE $whereSql
            GROUP BY h.supplier_code
            ORDER BY CAST(h.supplier_code AS UNSIGNED) ASC, h.supplier_code ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $purchases = (float) $r['purchases'];
        $returns   = (float) $r['returns_amount'];
        $discounts = (float) $r['discounts'];
        $net       = $purchases - $returns;

        $rows[] = [
            'code'         => (string) $r['supplier_code'],
            'name'         => (string) $r['supplier_name'],
            'payment_type' => $r['payment_type'] !== null && $r['payment_type'] !== '' ? (string) $r['payment_type'] : null,
            'purchases'    => round($purchases, 2),
            'returns'      => round($returns, 2),
            'discounts'    => round($discounts, 2),
            'net'          => round($net, 2),
        ];
    }

    return $rows;
}

/**
 * تجهيز بيانات تقرير «مشتريات مورد» بالصفوف والإجماليات
 *
 * @param string   $from   تاريخ البداية (YYYY-MM-DD)
 * @param string   $to     تاريخ النهاية (YYYY-MM-DD)
 * @param int|null $branch الفرع
 * @return array{
 *     rows: array<int, array{code: string, name: string, payment_type: ?string, purchases: float, returns: float, discounts: float, net: float}>,
 *     totals: array{purchases: float, returns: float, discounts: float, net: float, count: int}
 * }
 */
function supplier_purchases_report(string $from, string $to, ?int $branch = null): array
{
    $rows = sp_source_rows($from, $to, $branch);

    $totPurchases = 0.0;
    $totReturns   = 0.0;
    $totDiscounts = 0.0;
    $totNet       = 0.0;

    foreach ($rows as $row) {
        $totPurchases += $row['purchases'];
        $totReturns   += $row['returns'];
        $totDiscounts += $row['discounts'];
        $totNet       += $row['net'];
    }

    $totals = [
        'purchases' => round($totPurchases, 2),
        'returns'   => round($totReturns, 2),
        'discounts' => round($totDiscounts, 2),
        'net'       => round($totNet, 2),
        'count'     => count($rows),
    ];

    return [
        'rows'   => $rows,
        'totals' => $totals,
    ];
}

