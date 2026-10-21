<?php
/**
 * invoice_problems_lib.php — منطق صفحة مشاكل الفواتير
 * نظام الفجالة ستور المالي
 *
 * كل منطق «مشاكل الفواتير» في الملف ده:
 * - المزامنة التلقائية للمردودات (اتنقلت من الصفحة بالحرف — من غيرها
 *   القائمة هتطلع ناقصة المردودات الجديدة).
 * - استعلامات الرندر (قائمة الفواتير · هيدر الفاتورة · سطورها · مشاكلها).
 * - عمليات الحفظ والحذف الثلاث (حفظ خانة · حذف مشكلة · حذف مشاكل فاتورة).
 *
 * الشاشة (invoice_problems.php) والـAPI (invoice_problems_api.php) بينادوا
 * نفس الدوال — مفيش سطر SQL متعاد في أي واحد فيهم.
 */
declare(strict_types=1);

require_once __DIR__ . '/receiving_lib.php';   // merge_invoice_items() · dist_key_sql()

// =========================================================================
//  دوال الحساب التلقائي المجمّع للمردودات (اتنقلت من الصفحة بالحرف)
// =========================================================================

/**
 * حساب ومزامنة المردودات التلقائية لفاتورة معينة.
 * استعلام واحد مجمّع بدون أي استعلام داخل حلقة.
 *
 * مثال محمد بالحرف: قلم روتو في الفاتورة 12، اللي جه 10، المردود 2.
 * العمود short_qty = الناقص (12 - 10 = 2)، مش 10 ومش 12.
 */
function sync_invoice_problems_for_dist(PDO $pdo, int $distId): void
{
    if ($distId <= 0) return;

    $stDist = $pdo->prepare(
        "SELECT d.id, d.invoice_no, d.company_branch, d.doc_type, d.dist_date, d.supplier_id
         FROM invoice_distribution d
         WHERE d.id = ? LIMIT 1"
    );
    $stDist->execute([$distId]);
    $dist = $stDist->fetch();
    if (!$dist) return;

    // مشاكل الفواتير مخصصة لفواتير شراء الإدارة فقط (company_branch = 1 و doc_type = 2)
    if ($dist['company_branch'] !== null && (int) $dist['company_branch'] !== 1) return;
    if ($dist['doc_type'] !== null && (int) $dist['doc_type'] !== 2) return;

    // جلب فاتورة الشراء الأصلية من مرآة الشركة باستخدام المفتاح المركب
    $distKey = dist_key_sql('d', 'i.company_branch', 'i.doc_type');
    $stHq = $pdo->prepare(
        "SELECT i.sth_id, i.suffix, i.doc_no, i.company_branch, i.doc_type, i.supplier_id, i.invoice_date
         FROM hq_invoices i
         CROSS JOIN (SELECT ? AS company_branch, ? AS doc_type) d
         WHERE i.doc_no = ? AND i.doc_type = 2 AND (i.company_branch = 1 OR i.company_branch IS NULL) AND $distKey
         LIMIT 1"
    );
    $stHq->execute([$dist['company_branch'], $dist['doc_type'], $dist['invoice_no']]);
    $hqInv = $stHq->fetch();
    if (!$hqInv) return;

    // جلب كل سطور فاتورة الشراء
    $stLines = $pdo->prepare(
        "SELECT hl.item_id, hl.item_code, hl.item_name, hl.quantity, hl.unit_price, hl.cost_price
         FROM hq_invoice_lines hl
         WHERE hl.sth_id = ? AND hl.suffix = ?"
    );
    $stLines->execute([$hqInv['sth_id'], $hqInv['suffix']]);
    $rawLines = $stLines->fetchAll();
    if (empty($rawLines)) return;

    // دمج الأكواد المكررة بالدالة الرسمية
    $mergedList = merge_invoice_items($rawLines);
    $mergedItems = [];
    foreach ($mergedList as $it) {
        $code = trim((string) ($it['item_code'] ?? ''));
        if ($code !== '') {
            $mergedItems[$code] = $it;
        }
    }
    $itemCodes = array_keys($mergedItems);
    if (empty($itemCodes)) return;

    // استعلام مجمّع لجلب كميات المردود (doc_type = 6) لنفس المورد خلال 90 يوم
    $placeholders = implode(',', array_fill(0, count($itemCodes), '?'));
    $retSql = "
        SELECT lr.item_code, SUM(lr.quantity) AS return_qty
        FROM hq_invoices ir
        JOIN hq_invoice_lines lr ON lr.sth_id = ir.sth_id AND lr.suffix = ir.suffix
        WHERE ir.doc_type = 6
          AND ir.supplier_id = ?
          AND ir.invoice_date >= ?
          AND ir.invoice_date <= DATE_ADD(?, INTERVAL 90 DAY)
          AND lr.item_code IN ($placeholders)
        GROUP BY lr.item_code
        HAVING return_qty > 0
    ";
    $retParams = array_merge([$hqInv['supplier_id'], $hqInv['invoice_date'], $hqInv['invoice_date']], $itemCodes);
    $stRet = $pdo->prepare($retSql);
    $stRet->execute($retParams);
    $returns = $stRet->fetchAll(PDO::FETCH_KEY_PAIR);

    // جلب المشاكل الحالية المسجلة لهذه الفاتورة
    $stExist = $pdo->prepare(
        "SELECT item_code, source, short_qty, over_qty, note, dismissed_at
         FROM invoice_problems
         WHERE distribution_id = ?"
    );
    $stExist->execute([$distId]);
    $existing = [];
    foreach ($stExist->fetchAll() as $ep) {
        $existing[$ep['item_code']] = $ep;
    }

    // استعلامات الإدراج والتحديث خارج أي حلقة
    $insAuto = $pdo->prepare(
        "INSERT INTO invoice_problems (distribution_id, item_code, item_name, source, short_qty, over_qty)
         VALUES (?, ?, ?, 'auto', ?, 0)
         ON DUPLICATE KEY UPDATE
             short_qty = VALUES(short_qty),
             item_name = COALESCE(NULLIF(VALUES(item_name), ''), item_name)"
    );

    $updManual = $pdo->prepare(
        "UPDATE invoice_problems
         SET short_qty = ?, item_name = COALESCE(NULLIF(?, ''), item_name)
         WHERE distribution_id = ? AND item_code = ?"
    );

    foreach ($returns as $code => $retQty) {
        $rQty = (float) $retQty;
        if ($rQty <= 0) continue;

        // مثال محمد بالحرف: قلم روتو في الفاتورة ١٢، اللي جه ١٠، المردود ٢
        // العمود short_qty = الناقص (١٢ - ١٠ = ٢)، مش ١٠ ومش ١٢
        $shortQty = $rQty;
        $itemName = $mergedItems[$code]['item_name'] ?? '';

        if (isset($existing[$code])) {
            // لو الصنف شاله المستخدم يدوياً نتخطاه ولا نرجعه
            if ($existing[$code]['dismissed_at'] !== null) {
                continue;
            }
            // لو الصنف موجود يدوي: نحدّث الكميات فقط ونسيب source و note زي ما هما
            if ($existing[$code]['source'] === 'manual') {
                $updManual->execute([$shortQty, $itemName, $distId, $code]);
            } else {
                $insAuto->execute([$distId, $code, $itemName, $shortQty]);
            }
        } else {
            $insAuto->execute([$distId, $code, $itemName, $shortQty]);
        }
    }
}

/**
 * مزامنة تلقائية مجمّعة لكل الفواتير الموزعة التي لها مردودات.
 * استعلام واحد مجمّع بدون أي استعلام داخل حلقة.
 */
function sync_all_invoice_problems_auto(PDO $pdo): void
{
    // تنظيف أي سجلات تلقائية قديمة لفروع أخرى أو لمردودات
    try {
        $pdo->exec(
            "DELETE p FROM invoice_problems p
             JOIN invoice_distribution d ON d.id = p.distribution_id
             WHERE p.source = 'auto' AND ((d.company_branch IS NOT NULL AND d.company_branch <> 1) OR d.doc_type = 6)"
        );
    } catch (Throwable $e) { /* تجاهل */ }

    $sql = "
        SELECT 
            d.id AS dist_id,
            lp.item_code,
            MAX(lp.item_name) AS item_name,
            SUM(lr.quantity) AS return_qty
        FROM invoice_distribution d
        JOIN hq_invoices inv_p 
            ON inv_p.doc_no = d.invoice_no 
           AND inv_p.doc_type = 2
           AND (d.company_branch = 1 OR (d.company_branch IS NULL AND (inv_p.company_branch = 1 OR inv_p.company_branch IS NULL)))
           AND (d.doc_type = 2 OR d.doc_type IS NULL)
           AND (inv_p.company_branch = 1 OR inv_p.company_branch IS NULL)
        JOIN hq_invoice_lines lp 
            ON lp.sth_id = inv_p.sth_id AND lp.suffix = inv_p.suffix
        JOIN hq_invoices inv_r 
            ON inv_r.doc_type = 6 
           AND inv_r.supplier_id = inv_p.supplier_id
           AND (inv_r.company_branch = 1 OR inv_r.company_branch IS NULL OR inv_r.company_branch = inv_p.company_branch)
           AND inv_r.invoice_date >= inv_p.invoice_date 
           AND inv_r.invoice_date <= DATE_ADD(inv_p.invoice_date, INTERVAL 90 DAY)
        JOIN hq_invoice_lines lr 
            ON lr.sth_id = inv_r.sth_id AND lr.suffix = inv_r.suffix 
           AND lr.item_code = lp.item_code
        WHERE (d.company_branch = 1 OR d.company_branch IS NULL)
          AND (d.doc_type = 2 OR d.doc_type IS NULL)
        GROUP BY d.id, lp.item_code
        HAVING return_qty > 0
    ";
    $st = $pdo->query($sql);
    $rows = $st ? $st->fetchAll() : [];
    if (empty($rows)) return;

    $stManual = $pdo->query("SELECT distribution_id, item_code FROM invoice_problems WHERE source = 'manual'");
    $manualKeys = [];
    if ($stManual) {
        foreach ($stManual->fetchAll() as $m) {
            $manualKeys[$m['distribution_id'] . ':' . $m['item_code']] = true;
        }
    }

    $stDismissed = $pdo->query("SELECT distribution_id, item_code FROM invoice_problems WHERE dismissed_at IS NOT NULL");
    $dismissedKeys = [];
    if ($stDismissed) {
        foreach ($stDismissed->fetchAll() as $d) {
            $dismissedKeys[$d['distribution_id'] . ':' . $d['item_code']] = true;
        }
    }

    $insAuto = $pdo->prepare(
        "INSERT INTO invoice_problems (distribution_id, item_code, item_name, source, short_qty, over_qty)
         VALUES (?, ?, ?, 'auto', ?, 0)
         ON DUPLICATE KEY UPDATE
             short_qty = VALUES(short_qty),
             item_name = COALESCE(NULLIF(VALUES(item_name), ''), item_name)"
    );

    $updManual = $pdo->prepare(
        "UPDATE invoice_problems
         SET short_qty = ?, item_name = COALESCE(NULLIF(?, ''), item_name)
         WHERE distribution_id = ? AND item_code = ?"
    );

    foreach ($rows as $r) {
        $did  = (int) $r['dist_id'];
        $code = (string) $r['item_code'];
        $name = (string) $r['item_name'];
        $sq   = (float) $r['return_qty'];
        $k    = $did . ':' . $code;

        // لو الصنف اتشال بإيد المستخدم نتخطاه تماماً ولا نرجعه ولا نحدثه
        if (isset($dismissedKeys[$k])) {
            continue;
        }

        // مثال محمد بالحرف: قلم روتو في الفاتورة ١٢، اللي جه ١٠، المردود ٢
        // العمود short_qty = الناقص (١٢ - ١٠ = ٢)، مش ١٠ ومش ١٢
        if (isset($manualKeys[$k])) {
            $updManual->execute([$sq, $name, $did, $code]);
        } else {
            $insAuto->execute([$did, $code, $name, $sq]);
        }
    }

    try {
        $stSet = $pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value, description)
             VALUES ('invoice_problems_last_recalc', NOW(), 'آخر إعادة حساب لمشاكل الفواتير')
             ON DUPLICATE KEY UPDATE setting_value = NOW()"
        );
        $stSet->execute();
    } catch (Throwable $e) { /* تجاهل */ }
}

/**
 * جلب وقت آخر إعادة حساب لمشاكل الفواتير (من settings أو أقصى updated_at).
 */
function ip_last_recalc_time(PDO $pdo): ?string
{
    try {
        $st = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'invoice_problems_last_recalc' LIMIT 1");
        $val = $st ? $st->fetchColumn() : false;
        if ($val) return (string) $val;
    } catch (Throwable $e) {}
    try {
        $st = $pdo->query("SELECT MAX(updated_at) FROM invoice_problems");
        $val = $st ? $st->fetchColumn() : false;
        if ($val) return (string) $val;
    } catch (Throwable $e) {}
    return null;
}

/**
 * إعادة حساب دورية لمشاكل الفواتير إذا مر وقت كافٍ (للوركر أو cron).
 */
function ip_maybe_recalc_auto(PDO $pdo, int $intervalMinutes = 10): bool
{
    try {
        $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'invoice_problems_last_recalc' LIMIT 1");
        $st->execute();
        $last = $st->fetchColumn();
        if ($last) {
            $lastTs = strtotime((string) $last);
            if ($lastTs && (time() - $lastTs) < ($intervalMinutes * 60)) {
                return false;
            }
        }
        sync_all_invoice_problems_auto($pdo);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

// =========================================================================
//  استعلامات الرندر (اتنقلت من الصفحة بالحرف)
// =========================================================================

/**
 * كل فواتير مشتريات الإدارة المتاحة للفتح (مودال اختيار الفاتورة).
 *
 * @return array[] كل صف: id · invoice_no · dist_date · supplier_name
 */
function ip_all_invoices(PDO $pdo): array
{
    // جلب فواتير الإدارة فقط (company_branch = 1 ومشتريات فقط doc_type = 2) لإتاحة فتح أي فاتورة
    $stAllInv = $pdo->query(
        "SELECT d.id, d.invoice_no, d.dist_date,
                COALESCE(s.name, inv_p.supplier_name, 'غير محدد') AS supplier_name
         FROM invoice_distribution d
         LEFT JOIN suppliers s ON s.id = d.supplier_id
         LEFT JOIN hq_invoices inv_p 
             ON inv_p.doc_no = d.invoice_no 
            AND inv_p.doc_type = 2
            AND (d.company_branch IS NULL OR inv_p.company_branch IS NULL OR d.company_branch = inv_p.company_branch)
         WHERE (d.company_branch = 1 OR (d.company_branch IS NULL AND (inv_p.company_branch = 1 OR inv_p.company_branch IS NULL)))
           AND (d.doc_type = 2 OR (d.doc_type IS NULL AND (inv_p.doc_type = 2 OR inv_p.doc_type IS NULL)))
           AND (d.doc_type IS NULL OR d.doc_type <> 6)
         ORDER BY d.id DESC
         LIMIT 200"
    );
    $rows = $stAllInv ? $stAllInv->fetchAll() : [];

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'            => (int) $r['id'],
            'invoice_no'    => (string) $r['invoice_no'],
            'dist_date'     => (string) $r['dist_date'],
            'supplier_name' => (string) $r['supplier_name'],
        ];
    }
    return $out;
}

/**
 * فواتير الإدارة التي بها مشاكل حالياً مع اسم المورد وتاريخها ومين دخلها.
 *
 * @return array[] كل صف: dist_id · invoice_no · dist_date · supplier_name ·
 *                    entered_by · problems_count · short_count · over_count · note_count
 */
function ip_problem_invoices(PDO $pdo): array
{
    // استعلام فواتير الإدارة التي بها مشاكل حالياً مع اسم المورد وتاريخها ومين دخلها
    $stProblemsList = $pdo->query(
        "SELECT 
            d.id AS dist_id,
            d.invoice_no,
            d.dist_date,
            COALESCE(s.name, inv_p.supplier_name, 'غير محدد') AS supplier_name,
            u.full_name AS entered_by,
            COUNT(p.id) AS problems_count,
            SUM(CASE WHEN p.short_qty > 0 THEN 1 ELSE 0 END) AS short_count,
            SUM(CASE WHEN p.over_qty > 0 THEN 1 ELSE 0 END) AS over_count,
            SUM(CASE WHEN p.note IS NOT NULL AND p.note <> '' THEN 1 ELSE 0 END) AS note_count
         FROM invoice_problems p
         JOIN invoice_distribution d ON d.id = p.distribution_id
         LEFT JOIN users u ON u.id = d.created_by
         LEFT JOIN suppliers s ON s.id = d.supplier_id
         LEFT JOIN hq_invoices inv_p 
             ON inv_p.doc_no = d.invoice_no 
            AND inv_p.doc_type = 2
            AND (d.company_branch IS NULL OR inv_p.company_branch IS NULL OR d.company_branch = inv_p.company_branch)
         WHERE (d.company_branch = 1 OR (d.company_branch IS NULL AND (inv_p.company_branch = 1 OR inv_p.company_branch IS NULL)))
           AND (d.doc_type = 2 OR (d.doc_type IS NULL AND (inv_p.doc_type = 2 OR inv_p.doc_type IS NULL)))
           AND (d.doc_type IS NULL OR d.doc_type <> 6)
           AND p.dismissed_at IS NULL
           AND (p.short_qty > 0 OR p.over_qty > 0 OR (p.note IS NOT NULL AND p.note <> ''))
         GROUP BY d.id, d.invoice_no, d.dist_date, supplier_name, u.full_name
         ORDER BY d.id DESC"
    );
    $rows = $stProblemsList ? $stProblemsList->fetchAll() : [];

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'dist_id'        => (int) $r['dist_id'],
            'invoice_no'     => (string) $r['invoice_no'],
            'dist_date'      => (string) $r['dist_date'],
            'supplier_name'  => (string) $r['supplier_name'],
            'entered_by'     => (string) $r['entered_by'],
            'problems_count' => (int) $r['problems_count'],
            'short_count'    => (int) $r['short_count'],
            'over_count'     => (int) $r['over_count'],
            'note_count'     => (int) $r['note_count'],
        ];
    }
    return $out;
}

/**
 * هيدر فاتورة واحدة (الصفحة بتفتح بيها ?d=) — null لو مش موجودة أو مش فاتورة إدارة.
 *
 * @return array|null id · invoice_no · company_branch · doc_type · dist_date ·
 *                    created_by · supplier_name · entered_by
 */
function ip_dist_header(PDO $pdo, int $distId): ?array
{
    $stDistHeader = $pdo->prepare(
        "SELECT d.id, d.invoice_no, d.company_branch, d.doc_type, d.dist_date, d.created_by,
                COALESCE(s.name, inv_p.supplier_name, 'غير محدد') AS supplier_name,
                u.full_name AS entered_by
         FROM invoice_distribution d
         LEFT JOIN users u ON u.id = d.created_by
         LEFT JOIN suppliers s ON s.id = d.supplier_id
         LEFT JOIN hq_invoices inv_p 
             ON inv_p.doc_no = d.invoice_no 
            AND inv_p.doc_type = 2
            AND (d.company_branch IS NULL OR inv_p.company_branch IS NULL OR d.company_branch = inv_p.company_branch)
         WHERE d.id = ?
           AND (d.company_branch = 1 OR (d.company_branch IS NULL AND (inv_p.company_branch = 1 OR inv_p.company_branch IS NULL)))
           AND (d.doc_type = 2 OR (d.doc_type IS NULL AND (inv_p.doc_type = 2 OR inv_p.doc_type IS NULL)))
           AND (d.doc_type IS NULL OR d.doc_type <> 6)
         LIMIT 1"
    );
    $stDistHeader->execute([$distId]);
    $row = $stDistHeader->fetch();
    if (!$row) {
        return null;
    }

    return [
        'id'             => (int) $row['id'],
        'invoice_no'     => (string) $row['invoice_no'],
        'company_branch' => $row['company_branch'] !== null ? (int) $row['company_branch'] : null,
        'doc_type'       => $row['doc_type'] !== null ? (int) $row['doc_type'] : null,
        'dist_date'      => (string) $row['dist_date'],
        'created_by'     => $row['created_by'] !== null ? (int) $row['created_by'] : null,
        'supplier_name'  => (string) $row['supplier_name'],
        'entered_by'     => (string) $row['entered_by'],
    ];
}

/**
 * فاتورة الشراء الأصلية في مرآة الشركة — بالمفتاح المركب (sth_id + suffix).
 */
function ip_hq_invoice(PDO $pdo, array $distHeader): ?array
{
    // جلب سطور الفاتورة من مرآة الشركة مع دمج الأكواد المكررة
    $distKey = dist_key_sql('d', 'i.company_branch', 'i.doc_type');
    $stHq = $pdo->prepare(
        "SELECT i.sth_id, i.suffix
         FROM hq_invoices i
         CROSS JOIN (SELECT ? AS company_branch, ? AS doc_type) d
         WHERE i.doc_no = ? AND i.doc_type = 2 AND $distKey
         LIMIT 1"
    );
    $stHq->execute([$distHeader['company_branch'], $distHeader['doc_type'], $distHeader['invoice_no']]);
    $row = $stHq->fetch();

    return $row ? $row : null;
}

/**
 * أصناف الفاتورة من مرآة الشركة بعد دمج الأكواد المكررة — مفهرسة بكود الصنف.
 *
 * @return array [item_code => item]
 */
function ip_merged_items(PDO $pdo, ?array $hqInv): array
{
    $mergedInvoiceItems = [];
    if ($hqInv) {
        $stLines = $pdo->prepare(
            "SELECT hl.item_id, hl.item_code, hl.item_name, hl.quantity, hl.unit_price, hl.cost_price
             FROM hq_invoice_lines hl
             WHERE hl.sth_id = ? AND hl.suffix = ?"
        );
        $stLines->execute([$hqInv['sth_id'], $hqInv['suffix']]);
        $mergedList = merge_invoice_items($stLines->fetchAll());
        foreach ($mergedList as $it) {
            $code = trim((string) ($it['item_code'] ?? ''));
            if ($code !== '') {
                $mergedInvoiceItems[$code] = $it;
            }
        }
    }
    return $mergedInvoiceItems;
}

/**
 * المشاكل المسجلة في جدول invoice_problems لهذه الفاتورة (من غير المدفون).
 *
 * @return array [item_code => row]
 */
function ip_invoice_problems(PDO $pdo, int $distId): array
{
    // جلب كل المشاكل المسجلة في جدول invoice_problems لهذه الفاتورة
    $stProblems = $pdo->prepare(
        "SELECT p.*, u.full_name AS recorder_name
         FROM invoice_problems p
         LEFT JOIN users u ON u.id = p.created_by
         WHERE p.distribution_id = ? AND p.dismissed_at IS NULL"
    );
    $stProblems->execute([$distId]);
    $problemsMap = [];
    foreach ($stProblems->fetchAll() as $pRow) {
        $problemsMap[$pRow['item_code']] = $pRow;
    }
    return $problemsMap;
}

/**
 * قائمة أصناف الفاتورة الموحّدة (أصناف الفاتورة + المشاكل) بالترتيب اللي
 * الصفحة بتعرضه: أصناف المشاكل أولاً ثم الباقي بالاسم.
 *
 * @return array[] كل صف: code · name · inv_qty · short_qty · over_qty · note ·
 *                    source · recorder · time · has_problem
 */
function ip_invoice_items(PDO $pdo, array $distHeader): array
{
    $hqInv = ip_hq_invoice($pdo, $distHeader);
    $mergedInvoiceItems = ip_merged_items($pdo, $hqInv);
    $problemsMap = ip_invoice_problems($pdo, (int) $distHeader['id']);

    // تجميع كل الأصناف في قائمة موحدة
    $allItemCodes = array_unique(array_merge(array_keys($mergedInvoiceItems), array_keys($problemsMap)));
    $itemsList = [];
    foreach ($allItemCodes as $c) {
        $m = $mergedInvoiceItems[$c] ?? null;
        $p = $problemsMap[$c] ?? null;

        $itemName = $p['item_name'] ?? ($m['item_name'] ?? $c);
        $invQty   = $m ? (float)$m['quantity'] : 0.0;
        $shortQty = $p ? (float)$p['short_qty'] : 0.0;
        $overQty  = $p ? (float)$p['over_qty'] : 0.0;
        $note     = $p['note'] ?? '';
        $src      = $p['source'] ?? '';
        $recName  = $p['recorder_name'] ?? '';
        $recAt    = $p['updated_at'] ?? ($p['created_at'] ?? '');

        $hasProblem = ($shortQty > 0 || $overQty > 0 || $note !== '');

        $itemsList[] = [
            'code'        => (string) $c,
            'name'        => $itemName,
            'inv_qty'     => $invQty,
            'short_qty'   => $shortQty,
            'over_qty'    => $overQty,
            'note'        => $note,
            'source'      => $src,
            'recorder'    => $recName,
            'time'        => $recAt,
            'has_problem' => $hasProblem,
        ];
    }

    // ترتيب: أصناف المشاكل أولاً ثم الباقي
    usort($itemsList, function ($a, $b) {
        if ($a['has_problem'] && !$b['has_problem']) return -1;
        if (!$a['has_problem'] && $b['has_problem']) return 1;
        return strcmp($a['name'], $b['name']);
    });

    return $itemsList;
}

// =========================================================================
//  عمليات الكتابة (اتنقلت من الصفحة بالحرف)
// =========================================================================

/**
 * حفظ خانة مشكلة صنف: الناقص أو الزيادة أو الملاحظة.
 * السجل القائم بيتحدّث، ولو مش موجود بيتعمل واحد جديد source = 'manual'.
 *
 * @throws RuntimeException لو البيانات ناقصة أو الحقل مش معروف
 * @return array{ok: bool, source: string} نفس اللي الصفحة بترجّعه
 */
function ip_save_problem_field(PDO $pdo, int $uid, int $did, string $code, string $name, string $field, string $val): array
{
    if ($did <= 0 || $code === '') {
        throw new RuntimeException('بيانات الصنف أو الفاتورة غير مكتملة.');
    }

    if (!in_array($field, ['short_qty', 'over_qty', 'note'], true)) {
        throw new RuntimeException('حقل غير صالح.');
    }

    // فحص السجل الحالي
    $stCur = $pdo->prepare('SELECT id, source, short_qty, over_qty, note FROM invoice_problems WHERE distribution_id = ? AND item_code = ? LIMIT 1');
    $stCur->execute([$did, $code]);
    $cur = $stCur->fetch();

    if ($cur) {
        // تحديث السجل القائم وإلغاء حالة الحذف لو كانت موجودة
        if ($field === 'short_qty') {
            $num = max(0, (float) $val);
            $upd = $pdo->prepare('UPDATE invoice_problems SET short_qty = ?, item_name = COALESCE(NULLIF(?, ""), item_name), dismissed_at = NULL WHERE id = ?');
            $upd->execute([$num, $name, $cur['id']]);
        } elseif ($field === 'over_qty') {
            $num = max(0, (float) $val);
            $upd = $pdo->prepare('UPDATE invoice_problems SET over_qty = ?, item_name = COALESCE(NULLIF(?, ""), item_name), dismissed_at = NULL WHERE id = ?');
            $upd->execute([$num, $name, $cur['id']]);
        } else { // note
            $upd = $pdo->prepare('UPDATE invoice_problems SET note = ?, item_name = COALESCE(NULLIF(?, ""), item_name), created_by = COALESCE(created_by, ?), dismissed_at = NULL WHERE id = ?');
            $upd->execute([$val !== '' ? $val : null, $name, $uid, $cur['id']]);
        }
        return ['ok' => true, 'source' => (string) $cur['source']];
    }

    // إدراج سجل يدوي جديد
    $short = ($field === 'short_qty') ? max(0, (float) $val) : 0.0;
    $over  = ($field === 'over_qty') ? max(0, (float) $val) : 0.0;
    $note  = ($field === 'note' && $val !== '') ? $val : null;

    $ins = $pdo->prepare(
        "INSERT INTO invoice_problems (distribution_id, item_code, item_name, source, short_qty, over_qty, note, created_by)
         VALUES (?, ?, ?, 'manual', ?, ?, ?, ?)"
    );
    $ins->execute([$did, $code, $name ?: null, $short, $over, $note, $uid]);

    return ['ok' => true, 'source' => 'manual'];
}

/**
 * حذف مشكلة صنف واحد: الـauto بيتعلّم (dismissed_at) والـmanual بيتمسح.
 *
 * @return array{ok: bool} نفس اللي الصفحة بترجّعه
 */
function ip_delete_problem(PDO $pdo, int $did, string $code): array
{
    if ($did > 0 && $code !== '') {
        $st = $pdo->prepare('SELECT source FROM invoice_problems WHERE distribution_id = ? AND item_code = ? LIMIT 1');
        $st->execute([$did, $code]);
        $src = $st->fetchColumn();
        if ($src === 'auto') {
            $del = $pdo->prepare('UPDATE invoice_problems SET dismissed_at = NOW() WHERE distribution_id = ? AND item_code = ?');
            $del->execute([$did, $code]);
        } else {
            $del = $pdo->prepare('DELETE FROM invoice_problems WHERE distribution_id = ? AND item_code = ?');
            $del->execute([$did, $code]);
        }
    }
    return ['ok' => true];
}

/**
 * حذف كل مشاكل فاتورة واحدة: الـauto بيتعلّم والـmanual بيتمسح.
 *
 * @return array{ok: bool} نفس اللي الصفحة بترجّعه
 */
function ip_delete_invoice_problems(PDO $pdo, int $did): array
{
    if ($did > 0) {
        $pdo->prepare("UPDATE invoice_problems SET dismissed_at = NOW() WHERE distribution_id = ? AND source = 'auto'")->execute([$did]);
        $pdo->prepare("DELETE FROM invoice_problems WHERE distribution_id = ? AND source = 'manual'")->execute([$did]);
    }
    return ['ok' => true];
}
