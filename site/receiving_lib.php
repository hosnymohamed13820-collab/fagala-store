<?php
/**
 * receiving_lib.php — منطق موديول الاستلامات
 * نظام الفجالة ستور المالي
 *
 * بيقرا الفواتير وأصنافها من SQL Server (داتا السيستم)،
 * ويدير توزيعها على الفروع، ويربط هيدر الفاتورة بموديول الموردين.
 *
 * ============================================================
 *  إعداد أسماء جداول/أعمدة SQL Server  (عدّلها حسب نظامك)
 * ============================================================
 * لو أسماء الجداول أو الأعمدة عندك مختلفة، غيّرها هنا فقط.
 *
 * ملاحظة مهمة: لو ظهر خطأ "Invalid object name 'STO_TRANS_HEADER'"
 * يبقى الجداول في قاعدة/schema تانية. املأ SQLSRV_PREFIX بالبادئة الصح،
 * مثلاً: 'YourDB.dbo.' أو 'dbo.' (سيبها '' لو الجداول في نفس القاعدة الافتراضية).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/agent_lib.php';   // bal_table() — مصدر الأرصدة
// SQLSRV_PREFIX بتتعرّف في config.php (اسم قاعدة الفرع + .dbo.)
// الجداول ممكن تكون شهرية (مثلاً STO_TRANS_HEADER_26_06). النظام بيكتشفها كلها
// تلقائياً ويجمّعها، فمايقعش لو شهر مش موجود، وبيشمل أي شهر جديد لوحده.
const SQLSRV_HEAD_BASE   = 'STO_TRANS_HEADER';   // الاسم الأساسي للهيدر
const SQLSRV_LINES_BASE  = 'STO_TRANS_LINES';    // الاسم الأساسي للسطور

/**
 * بيكتشف كل الجداول اللي اسمها يبدأ بـ $base (مع أو بدون لاحقة شهر)،
 * ويبني تعبير FROM مجمّع (UNION ALL) جاهز للاستعلام.
 * مثال: لو فيه STO_TRANS_HEADER_26_06 و STO_TRANS_HEADER_26_07
 *       يرجّع: (SELECT * FROM db.dbo.[STO_TRANS_HEADER_26_06] UNION ALL SELECT * FROM ...)
 * لو الجدول موجود بالاسم المجرّد (بدون لاحقة) بيستخدمه عادي.
 *
 * @return string تعبير FROM (subquery) — يُستخدم بدل اسم الجدول.
 */
function sqlsrv_union_tables(string $base, string $cols = '*'): string
{
    static $cache = [];
    $ckey = $base . '|' . $cols;
    if (isset($cache[$ckey])) return $cache[$ckey];

    // ندوّر على كل الجداول اللي اسمها = base أو base_xxxx
    $like = $base . '%';
    $rows = sqlsrv_query(
        "SELECT TABLE_NAME FROM " . SQLSRV_DB . ".INFORMATION_SCHEMA.TABLES
         WHERE TABLE_TYPE='BASE TABLE' AND TABLE_NAME LIKE ?
         ORDER BY TABLE_NAME",
        [$like]
    );
    $tables = array_column($rows, 'TABLE_NAME');

    // فلترة: الاسم لازم يكون base بالظبط أو base متبوع بـ _ (عشان مايجيبش جداول تانية بالغلط)
    $valid = [];
    foreach ($tables as $t) {
        if ($t === $base || strpos($t, $base . '_') === 0) {
            $valid[] = $t;
        }
    }

    if (!$valid) {
        $cache[$ckey] = SQLSRV_PREFIX . $base;
        return $cache[$ckey];
    }

    if (count($valid) === 1) {
        $cache[$ckey] = SQLSRV_PREFIX . '[' . $valid[0] . ']';
        return $cache[$ckey];
    }

    // أكتر من جدول: UNION ALL — بأعمدة محدّدة (عشان الجداول لو مختلفة الأعمدة)
    $parts = [];
    foreach ($valid as $t) {
        $parts[] = 'SELECT ' . $cols . ' FROM ' . SQLSRV_PREFIX . '[' . $t . ']';
    }
    $cache[$ckey] = '(' . implode(' UNION ALL ', $parts) . ')';
    return $cache[$ckey];
}

/** أعمدة الهيدر المطلوبة (ثابتة عشان الـ UNION). */
function sqlsrv_head_cols(): string
{
    return COL_INV_ID . ', ' . COL_INV_NO . ', ' . COL_INV_SUPP_ID . ', '
         . COL_INV_NET . ', ' . COL_INV_TOTAL . ', ' . COL_INV_DATE . ', ' . COL_INV_BRANCH;
}
/** أعمدة السطور المطلوبة (ثابتة عشان الـ UNION). */
function sqlsrv_lines_cols(): string
{
    return COL_LN_INV_ID . ', ' . COL_LN_NO . ', ' . COL_LN_ITEM_ID . ', ' . COL_LN_IC_ID . ', '
         . COL_LN_QTY . ', ' . COL_LN_PRICE . ', ' . COL_LN_COST;
}

/** اسم/تعبير جدول الهيدر المجمّع (شهور). */
function sqlsrv_head_table(): string { return sqlsrv_union_tables(SQLSRV_HEAD_BASE, sqlsrv_head_cols()); }
/** اسم/تعبير جدول السطور المجمّع (شهور). */
function sqlsrv_lines_table(): string { return sqlsrv_union_tables(SQLSRV_LINES_BASE, sqlsrv_lines_cols()); }

// أعمدة الهيدر
const COL_INV_ID       = 'STH_ID';          // رقم الفاتورة (مفتاح)
const COL_INV_NO       = 'STH_DOC_NO';      // رقم الفاتورة الظاهر
const COL_INV_SUPP_ID  = 'SUPP_ID';         // رقم المورد
const COL_INV_NET      = 'STH_NET_VALUE';   // الصافي
const COL_INV_TOTAL    = 'STH_TOT_VALUE';   // الإجمالي
const COL_INV_DATE     = 'STH_DOC_DATE';    // تاريخ الفاتورة
const COL_INV_BRANCH   = 'BRCH_ID';         // الفرع

// أعمدة السطور
const COL_LN_INV_ID    = 'STH_ID';          // ربط بالفاتورة
const COL_LN_NO        = 'STL_LINE_NO';     // رقم السطر
const COL_LN_ITEM_ID   = 'ITEM_ID';         // رقم الصنف
const COL_LN_IC_ID     = 'IC_ID';           // الكود المحدد في الفاتورة (للربط مع ITEM_CODES)
const COL_LN_QTY       = 'STL_QTY';         // الكمية
const COL_LN_PRICE     = 'STL_ITEM_PRICE';  // سعر الوحدة (الجديد)
const COL_LN_COST      = 'STL_ITEM_COST';   // سعر التكلفة (القديم)

// جداول الأسماء (عدّلها حسب نظامك لو مختلفة)
const SUPPLIERS_TABLE  = SQLSRV_PREFIX . 'SUPPLIERS';
const SUPP_ID_COL      = 'SUPP_ID';
const SUPP_NAME_COL    = 'SUPP_NAME1';
const ITEMS_TABLE      = SQLSRV_PREFIX . 'ITEMS';
const ITEM_ID_COL      = 'ITEM_ID';
const ITEM_NAME_COL    = 'ITEM_NAME1';

require_once __DIR__ . '/config.php';

/**
 * بيكتشف كل جداول الهيدر المرحّلة (STO_TRANS_HEADER_YY_MM) ويرجّع أسماءها مع الـ suffix.
 * بيتجاهل جدول القالب STO_TRANS_HEADER_YY_MM (الحرفي).
 * @return array من [table => اسم الجدول, suffix => اللاحقة]
 */
function sqlsrv_posted_tables(): array
{
    // ملاحظة: الـ ESCAPE '\' القديم كان بيتاكل في الطريق عبر الجسر
    // (PHP → HTTP → ODBC)، فالنمط مايطابقش أي جدول والنتيجة تبقى فاضية
    // من غير أي خطأ. دلوقتي: استعلام بسيط + فلترة دقيقة في PHP.
    // مهم: لازم نأهّل INFORMATION_SCHEMA باسم القاعدة.
    // الجسر متصل بـ master (القاعدة الافتراضية للـ DSN)، فلو سبناها
    // من غير تأهيل بيدوّر في master ويرجّع فاضي من غير أي خطأ.
    $rows = sqlsrv_query(
        "SELECT TABLE_NAME FROM " . SQLSRV_DB . ".INFORMATION_SCHEMA.TABLES
         WHERE TABLE_TYPE='BASE TABLE'
           AND TABLE_NAME LIKE 'STO_TRANS_HEADER%'
         ORDER BY TABLE_NAME DESC"
    );
    $out = [];
    foreach (array_column($rows, 'TABLE_NAME') as $t) {
        // نقبل بس الشكل STO_TRANS_HEADER_YY_MM بأرقام حقيقية
        // (ده بيستبعد جدول القالب STO_TRANS_HEADER_YY_MM الحرفي والجدول الأساسي)
        if (!preg_match('/^STO_TRANS_HEADER_(\d{2})_(\d{2})$/', (string) $t)) {
            continue;
        }
        $out[] = ['table' => $t, 'suffix' => str_replace('STO_TRANS_HEADER_', '', $t)];
    }
    return $out;
}

/**
 * جلب قائمة فواتير الشراء من SQL Server.
 * بيمر على كل جدول مرحّل لوحده (زي المشروع الأصلي)، ويجمّعهم، مع suffix لكل فاتورة.
 * الفلتر: SSTT_ID = 2 (فواتير شراء بس).
 *
 * @param int $fromYear (غير مستخدم حالياً — كله بيتجمّع، الترتيب بالتاريخ)
 */
function sqlsrv_fetch_invoices(int $fromYear = 2026): array
{
    // ١) النسخة المحلية الأول (hq_invoices) — تشتغل من غير نت
    try {
        $rows = db()->query(
            "SELECT sth_id AS inv_id, doc_no AS inv_no, supplier_id,
                    supplier_code, supplier_name, net_amount, total_amount,
                    invoice_date, company_branch AS company_branch_id,
                    doc_type, suffix
             FROM hq_invoices ORDER BY invoice_date DESC"
        )->fetchAll();
        if ($rows) return $rows;
    } catch (Throwable $ex) {
        // الجدول لسه ما اتعملش — نكمّل على الجسر
    }

    // ٢) النسخة المحلية فاضية → الجسر
    $all = [];
    foreach (sqlsrv_posted_tables() as $t) {
        $head = SQLSRV_PREFIX . '[' . $t['table'] . ']';
        $sql = "SELECT h." . COL_INV_ID . " AS inv_id,
                       LTRIM(RTRIM(h." . COL_INV_NO . ")) AS inv_no,
                       h." . COL_INV_SUPP_ID . " AS supplier_id,
                       LTRIM(RTRIM(s.SUPP_CODE)) AS supplier_code,
                       LTRIM(RTRIM(s." . SUPP_NAME_COL . ")) AS supplier_name,
                       h." . COL_INV_NET . " AS net_amount, h." . COL_INV_TOTAL . " AS total_amount,
                       h." . COL_INV_DATE . " AS invoice_date,
                       h." . COL_INV_BRANCH . " AS company_branch_id,
                       h.SSTT_ID AS doc_type
                FROM " . $head . " h
                LEFT JOIN " . SUPPLIERS_TABLE . " s ON s." . SUPP_ID_COL . " = h." . COL_INV_SUPP_ID . "
                WHERE h.SSTT_ID IN (2, 6)
                ORDER BY h." . COL_INV_DATE . " DESC";
        try {
            foreach (sqlsrv_query($sql) as $row) {
                $row['suffix'] = $t['suffix'];
                if (isset($row['supplier_name'])) $row['supplier_name'] = sqlsrv_fix_encoding((string)$row['supplier_name']);
                if (isset($row['supplier_code'])) $row['supplier_code'] = sqlsrv_fix_encoding((string)$row['supplier_code']);
                $all[] = $row;
            }
        } catch (Throwable $ex) {
            error_log('receiving: فشل قراءة ' . $t['table'] . ' — ' . $ex->getMessage());
            $GLOBALS['recv_errors'][] = $t['table'] . ': ' . $ex->getMessage();
        }
    }
    usort($all, fn($a,$b) => strtotime((string)$b['invoice_date']) <=> strtotime((string)$a['invoice_date']));
    return $all;
}

/**
 * جلب أصناف فاتورة من SQL Server.
 * بيرجّع: كود الصنف، اسمه، الكمية، سعر الوحدة (الجديد)، سعر التكلفة (القديم).
 */
function sqlsrv_fetch_invoice_items($invoiceId, ?string $suffix = null): array
{
    // ⚠️ نفس تحذير الهيدر: الـ STH_ID بيتكرر بين جدول الفواتير غير
    //    المحفوظة (YY_MM) والجداول الشهرية المرحّلة، وكمان بين الجداول
    //    الشهرية نفسها. لازم نلتزم بالمصدر الصح: نوع الجدول + الشهر.
    $isUnposted    = ($suffix === 'YY_MM');
    $isPostedLines = ($suffix !== null && $suffix !== '' && !$isUnposted);

    try {
        if (!$isUnposted) {
            // فاتورة مرحّلة → البنود المرحّلة، مفلترة بالشهر كمان
            // (sth_id لوحده مش فريد بين جداول الشهور)
            $sql = 'SELECT item_id, item_code, item_name, quantity,
                           unit_price, cost_price
                    FROM hq_invoice_lines WHERE sth_id = ?';
            $args = [(int) $invoiceId];
            if ($isPostedLines) { $sql .= ' AND suffix = ?'; $args[] = $suffix; }
            $st = db()->prepare($sql . ' ORDER BY line_no');
            $st->execute($args);
            $rows = $st->fetchAll();
            if ($rows) return $rows;
        } else {
            // فاتورة غير محفوظة → بنود hq_unposted_lines بس
            $st2 = db()->prepare(
                'SELECT item_id, item_code, item_name, quantity,
                        unit_price, cost_price
                 FROM hq_unposted_lines WHERE sth_id = ? ORDER BY line_no'
            );
            $st2->execute([(int) $invoiceId]);
            $rows2 = $st2->fetchAll();
            if ($rows2) return $rows2;
        }

        // مفيش بنود محلي — الفاتورة نفسها موجودة محلي؟
        // لو أيوه يبقى إما الفاتورة فعلاً فاضية، وإما دفعة البنود
        // ماوصلتش من الوكيل (الهيدر والبنود بيتبعتوا في POST منفصلين).
        // بنرفع علم عشان الواجهة تفرّق بين الحالتين بدل ما تقول
        // "لا توجد أصناف" وتوهّم إن الفاتورة فاضية.
        $existsHere = $isUnposted
            ? hq_unposted_exists((int) $invoiceId)
            : hq_invoice_exists((int) $invoiceId, $suffix);
        if ($existsHere) {
            $GLOBALS['recv_lines_missing'] = true;
            return [];
        }
    } catch (Throwable $ex) {
        // الجدول لسه ما اتعملش — نكمّل على الجسر
    }

    // ٢) الجسر (لو النسخة المحلية فاضية)
    $suffix = $suffix ?: date('y_m');
    $linesTable = SQLSRV_PREFIX . '[STO_TRANS_LINES_' . $suffix . ']';
    $namesTable = SQLSRV_PREFIX . 'ITEM_CODES';
    $sql = "SELECT l.ITEM_ID AS item_id,
                   LTRIM(RTRIM(ic.IC_CODE)) AS item_code,
                   COALESCE(LTRIM(RTRIM(ic.IC_NAME1)), CAST(l.ITEM_ID AS NVARCHAR(50))) AS item_name,
                   l.STL_QTY AS quantity,
                   l." . COL_LN_PRICE . " AS unit_price,
                   l.STL_ITEM_COST AS cost_price
            FROM " . $linesTable . " l
            LEFT JOIN " . $namesTable . " ic ON ic.IC_ID = l.IC_ID
            WHERE l.STH_ID = ?
            ORDER BY l.STL_LINE_NO";
    $rows = sqlsrv_query($sql, [$invoiceId]);
    foreach ($rows as &$r) {
        if (isset($r['item_name'])) $r['item_name'] = sqlsrv_fix_encoding((string)$r['item_name']);
        if (isset($r['item_code'])) $r['item_code'] = sqlsrv_fix_encoding((string)$r['item_code']);
    }
    return $rows;
}

/**
 * الفاتورة موجودة في النسخة المحلية؟ (عشان نعرف نرجع للجسر ولا لأ)
 */
function hq_invoice_exists(int $sthId, ?string $suffix = null): bool
{
    try {
        // الشهر جزء من المفتاح — من غيره ممكن نأكّد وجود فاتورة
        // شهر تاني بنفس الرقم ونقول غلط إن البنود ناقصة.
        if ($suffix !== null && $suffix !== '' && $suffix !== 'YY_MM') {
            $st = db()->prepare('SELECT 1 FROM hq_invoices WHERE sth_id = ? AND suffix = ? LIMIT 1');
            $st->execute([$sthId, $suffix]);
            return (bool) $st->fetchColumn();
        }
        $st = db()->prepare('SELECT 1 FROM hq_invoices WHERE sth_id = ? LIMIT 1');
        $st->execute([$sthId]);
        return (bool) $st->fetchColumn();
    } catch (Throwable $ex) {
        return false;
    }
}

/**
 * النسخة المحلية للأرصدة فيها داتا؟ (عشان نعرف نرجع للجسر ولا لأ)
 */
function hq_balances_ready(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $ready = (bool) db()->query('SELECT 1 FROM ' . bal_table() . ' LIMIT 1')->fetchColumn();
    } catch (Throwable $ex) {
        $ready = false;
    }
    return $ready;
}

/**
 * جدول الفواتير غير المرحّلة موجود؟ (حتى لو فاضي — يبقى مفيش غير مرحّلة)
 */
function hq_unposted_ready(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        db()->query('SELECT 1 FROM hq_unposted LIMIT 1');
        $ready = true;
    } catch (Throwable $ex) {
        $ready = false;
    }
    return $ready;
}

/**
 * فاتورة غير مرحّلة موجودة محلي؟
 */
function hq_unposted_exists(int $sthId): bool
{
    try {
        $st = db()->prepare('SELECT 1 FROM hq_unposted WHERE sth_id = ? LIMIT 1');
        $st->execute([$sthId]);
        return (bool) $st->fetchColumn();
    } catch (Throwable $ex) {
        return false;
    }
}

/**
 * جلب هيدر فاتورة واحدة من SQL Server.
 */
function sqlsrv_fetch_invoice_header($invoiceId, ?string $suffix = null): ?array
{
    // =================================================================
    //  ⚠️ تصادم الـ STH_ID
    //  الفواتير غير المحفوظة عايشة في STO_TRANS_HEADER_YY_MM
    //  والمرحّلة في جداول شهرية (STO_TRANS_HEADER_26_07 ...).
    //  الرقم بيتكرر بين الجدولين، فلازم نلتزم بالمصدر الصح:
    //
    //    suffix = 'YY_MM'  → غير محفوظة → hq_unposted **بس**
    //    suffix = '26_07'  → مرحّلة     → hq_invoices **بس**
    //    suffix = null     → مش معروف   → نجرّب الاتنين (سلوك قديم)
    //
    //  الاتجاهين لازم يتقفلوا: لو فاتورة مرحّلة مش موجودة في المرآة
    //  (شهر قديم مثلاً) ومشيناها على hq_unposted، هتلاقي فاتورة
    //  غير محفوظة بنفس الرقم وترجّع بياناتها — نفس الغلط بالمقلوب.
    // =================================================================
    $isUnposted = ($suffix === 'YY_MM');
    $isPosted   = ($suffix !== null && $suffix !== '' && !$isUnposted);

    // ١) النسخة المحلية — الفواتير المرحّلة
    //    بنفلتر بالـ suffix كمان: الـ sth_id لوحده مش فريد بين الشهور.
    if (!$isUnposted) {
        try {
            $sql = 'SELECT sth_id AS inv_id, doc_no AS inv_no, supplier_id,
                           supplier_code, supplier_name, net_amount, total_amount,
                           invoice_date, doc_type, suffix
                    FROM hq_invoices WHERE sth_id = ?';
            $args = [(int) $invoiceId];
            if ($isPosted) { $sql .= ' AND suffix = ?'; $args[] = $suffix; }
            $st = db()->prepare($sql . ' LIMIT 1');
            $st->execute($args);
            $local = $st->fetch();
            if ($local) return $local;
        } catch (Throwable $ex) {
            // نكمّل
        }
    }

    // ٢) النسخة المحلية — الفواتير غير المحفوظة
    //    ممنوعة تماماً لو إحنا متأكدين إن الفاتورة مرحّلة.
    if (!$isPosted) {
        try {
            $st = db()->prepare(
                'SELECT sth_id AS inv_id, doc_no AS inv_no, supplier_id,
                        supplier_code, supplier_name, net_amount, total_amount,
                        invoice_date, doc_type, remark
                 FROM hq_unposted WHERE sth_id = ? LIMIT 1'
            );
            $st->execute([(int) $invoiceId]);
            $localUnp = $st->fetch();
            if ($localUnp) {
                $localUnp['suffix'] = 'YY_MM';
                return $localUnp;
            }
            // جاية من تاب "غير محفوظة" والجدول جاهز ومش لاقيينها؟
            // يبقى اتحفظت/اتلغت — نرجّع فاضي بدل ما نعلّق 8 ثواني على الجسر.
            if ($isUnposted && hq_unposted_ready()) return null;
        } catch (Throwable $ex) {
            // الجدول لسه ما اتعملش — نكمّل على الجسر
        }
    }

    // ٣) الجسر (احتياطي)
    $suffix = $suffix ?: date('y_m');
    $headTable = SQLSRV_PREFIX . '[STO_TRANS_HEADER_' . $suffix . ']';
    $sql = "SELECT h." . COL_INV_ID . " AS inv_id,
                   LTRIM(RTRIM(h." . COL_INV_NO . ")) AS inv_no,
                   h." . COL_INV_SUPP_ID . " AS supplier_id,
                   LTRIM(RTRIM(s.SUPP_CODE)) AS supplier_code,
                   COALESCE(LTRIM(RTRIM(s." . SUPP_NAME_COL . ")), CAST(h." . COL_INV_SUPP_ID . " AS NVARCHAR(50))) AS supplier_name,
                   h." . COL_INV_NET . " AS net_amount, h." . COL_INV_TOTAL . " AS total_amount,
                   h." . COL_INV_DATE . " AS invoice_date
            FROM " . $headTable . " h
            LEFT JOIN " . SUPPLIERS_TABLE . " s ON s." . SUPP_ID_COL . " = h." . COL_INV_SUPP_ID . "
            WHERE h." . COL_INV_ID . " = ?";
    $rows = sqlsrv_query($sql, [$invoiceId]);
    $row = $rows[0] ?? null;
    if ($row) {
        if (isset($row['supplier_name'])) $row['supplier_name'] = sqlsrv_fix_encoding((string)$row['supplier_name']);
        if (isset($row['supplier_code'])) $row['supplier_code'] = sqlsrv_fix_encoding((string)$row['supplier_code']);
    }
    return $row ?: null;
}

/* ============================================================
 *  مزامنة هيدر الفاتورة إلى MySQL (للربط بالموردين)
 * ============================================================
 * بنخزّن هيدر كل فاتورة اتفتحت في invoice_distribution
 * عشان تظهر في الموردين وكشوف الحسابات.
 * (الأصناف بتفضل تتقرا من SQL Server وقت الحاجة).
 */

/**
 * يضمن وجود سجل توزيع (هيدر) للفاتورة في MySQL، ويرجّع صفّه.
 * بيربط الفاتورة بمورد في جدول suppliers (بإنشائه لو مش موجود).
 */
function ensure_distribution_record(array $invHeader, ?int $userId = null): array
{
    $pdo = db();
    $invNo = (string) $invHeader['inv_no'];

    // هل موجود بالفعل؟
    $st = $pdo->prepare('SELECT * FROM invoice_distribution WHERE invoice_no = ? LIMIT 1');
    $st->execute([$invNo]);
    $existing = $st->fetch();
    if ($existing) {
        return $existing;
    }

    // إيجاد/إنشاء المورد بالاسم
    $supplierName = trim((string) $invHeader['supplier_name']) ?: 'مورد غير محدد';
    $sp = $pdo->prepare('SELECT id FROM suppliers WHERE name = ? LIMIT 1');
    $sp->execute([$supplierName]);
    $supplierId = $sp->fetchColumn();
    if (!$supplierId) {
        $pdo->prepare('INSERT INTO suppliers (name) VALUES (?)')->execute([$supplierName]);
        $supplierId = (int) $pdo->lastInsertId();
    }

    // إنشاء سجل التوزيع (الهيدر)
    $pdo->prepare(
        'INSERT INTO invoice_distribution (invoice_no, supplier_id, dist_date, total_amount, notes, created_by)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([
        $invNo, (int) $supplierId,
        date('Y-m-d', strtotime((string) $invHeader['invoice_date'])),
        (float) $invHeader['total_amount'],
        'الصافي: ' . number_format((float) $invHeader['net_amount'], 2),
        $userId,
    ]);
    $id = (int) $pdo->lastInsertId();

    $st = $pdo->prepare('SELECT * FROM invoice_distribution WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch();
}

/**
 * جلب التوزيعات المحفوظة لصنف في فاتورة (من MySQL).
 * المفتاح: distribution_id + item_code.
 * بترجّع [branch_id => total_qty_distributed].
 */
function get_item_distribution(int $distributionId, string $itemCode): array
{
    $pdo = db();
    $st = $pdo->prepare(
        'SELECT branch_id, SUM(amount) AS qty
         FROM invoice_distribution_lines
         WHERE distribution_id = ? AND notes = ?
         GROUP BY branch_id'
    );
    // نستخدم عمود notes لتخزين كود الصنف (لأن الجدول عام)
    $st->execute([$distributionId, $itemCode]);
    $out = [];
    foreach ($st as $row) {
        $out[(int) $row['branch_id']] = (float) $row['qty'];
    }
    return $out;
}

/**
 * إجمالي الموزّع لصنف (كل الفروع).
 */
function get_item_distributed_total(int $distributionId, string $itemCode): float
{
    $pdo = db();
    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(amount),0) FROM invoice_distribution_lines
         WHERE distribution_id = ? AND notes = ?'
    );
    $st->execute([$distributionId, $itemCode]);
    return (float) $st->fetchColumn();
}

/**
 * حفظ توزيع صنف على الفروع (إضافي — يُجمَع على الموجود).
 *
 * المنطق المتفق عليه:
 *  - المتاح للتوزيع = الكمية − الموزّع سابقاً (مش الكمية كاملة).
 *  - بنرفض لو إجمالي التوزيع الجديد + القديم يتجاوز الكمية.
 *  - المخزن (الفرق) = الكمية − إجمالي الموزّع.
 *
 * @param array $perBranch [branch_id => qty] الكميات الجديدة المراد توزيعها
 * @return array ['stored'=>float, 'remaining'=>float]
 */
function distribute_item(
    int $distributionId,
    string $itemCode,
    string $itemName,
    float $itemQty,
    array $perBranch,
    ?int $userId = null
): array {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // الموزّع سابقاً
        $alreadySt = $pdo->prepare(
            'SELECT COALESCE(SUM(amount),0) FROM invoice_distribution_lines
             WHERE distribution_id = ? AND notes = ?'
        );
        $alreadySt->execute([$distributionId, $itemCode]);
        $already = (float) $alreadySt->fetchColumn();

        $newTotal = 0.0;
        foreach ($perBranch as $qty) {
            $q = (float) $qty;
            if ($q < 0) throw new InvalidArgumentException('الكمية لا يمكن أن تكون سالبة.');
            $newTotal += $q;
        }

        // المتاح = الكمية − الموزّع سابقاً
        $available = $itemQty - $already;
        if ($newTotal > $available + 0.0001) {
            throw new RuntimeException(
                'الكمية الموزّعة (' . number_format($newTotal, 2) . ') أكبر من المتاح (' .
                number_format($available, 2) . ').'
            );
        }

        // حفظ السطور الجديدة (كل فرع كمية > 0)
        $ins = $pdo->prepare(
            'INSERT INTO invoice_distribution_lines (distribution_id, branch_id, amount, notes)
             VALUES (?, ?, ?, ?)'
        );
        foreach ($perBranch as $branchId => $qty) {
            $q = (float) $qty;
            if ($q > 0) {
                $ins->execute([$distributionId, (int) $branchId, $q, $itemCode]);
            }
        }

        $pdo->commit();
        $distributedTotal = $already + $newTotal;
        return [
            'stored'    => $newTotal,
            'remaining' => $itemQty - $distributedTotal, // المخزن المتبقي
        ];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * حفظ تلقائي: تحديد كمية فرع واحد لصنف (استبدال مش إضافة).
 * الكمية المرسلة = التوزيع الكامل للفرع ده للصنف ده (idempotent) —
 * لو المستخدم عدّل الرقم، بيتحدّث نفس السطر بدل ما يتضاف سطر جديد.
 *
 * بيرجّع ['remaining' => المخزن المتبقي بعد الحفظ, 'branch_total' => إجمالي الفرع للصنف].
 */
function set_branch_distribution(
    int $distributionId,
    string $itemCode,
    float $itemQty,
    int $branchId,
    float $qty,
    ?int $userId = null
): array {
    if ($qty < 0) throw new InvalidArgumentException('الكمية لا يمكن أن تكون سالبة.');
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        // الموزّع سابقاً لباقي الفروع (غير الفرع الحالي) — بقفل للسطور
        $st = $pdo->prepare(
            'SELECT COALESCE(SUM(amount),0) FROM invoice_distribution_lines
             WHERE distribution_id = ? AND notes = ? AND branch_id <> ? FOR UPDATE'
        );
        $st->execute([$distributionId, $itemCode, $branchId]);
        $othersTotal = (float) $st->fetchColumn();

        // 🔴 السقف الحقيقي = الأقل بين كمية الفاتورة ورصيد الإدارة.
        //    الفاتورة ممكن تبقى ١٢ والواصل فعلاً ١٠ (والباقي اتعمله
        //    مردود)، فرصيد الإدارة ١٠. توزيع ١٢ بيطلّع بضاعة مش موجودة.
        //
        // 🔴 وبيتحسب **هنا في السيرفر** بقصد. `$itemQty` جاي من
        //    `$_POST['item_qty']` في المتصفح، يعني ممكن يتزوّر — كان
        //    ينفع حد يبعت item_qty=99999 ويوزّع أي كمية يعوزها.
        //    رصيد الإدارة بيتقري من القاعدة فمالوش دعوة بالعميل.
        $hqBal = hq_admin_balance_for_code($itemCode);

        // 🔴 الرصيد مش معروف: **ممنوع تزوّد، مسموح تقلّل**.
        //    التطوّر ده حصل على تلات خطوات في يوم واحد (٣١ أغسطس ٢٠٢٦):
        //      ١) «مش معروف = مفيش سقف» ← الميزة بتفضل نايمة بالظبط في
        //         الحالة اللي اتعملت عشانها.
        //      ٢) «مش معروف = امنع خالص» ← اتقفل ٥٤ صنف من ٧٥ في فاتورة
        //         واحدة، بما فيهم أصناف **متوزّعة خلاص**.
        //      ٣) الصح: امنع **الخطر الجديد** بس.
        if ($hqBal === null) {
            // 🔴 الرصيد مش واصل: **ممنوع تزوّد، مسموح تقلّل**.
            //    أول نسخة كانت بتمنع الصنف خالص، فاتقفل ٥٤ صنف من ٧٥ —
            //    بما فيهم أصناف **متوزّعة خلاص**، فمحمد مابقاش يقدر
            //    يصلّح ولا يمسح توزيع قديم. القفل كان بيعاقبه على شغل
            //    عمله قبل ما الميزة تتعمل أصلاً.
            //    طول ما الكمية مابتزيدش عن المحفوظ، مفيش بضاعة زيادة
            //    بتطلع — فمفيش سبب نمنعه.
            $ownSt = $pdo->prepare(
                'SELECT COALESCE(SUM(amount),0) FROM invoice_distribution_lines
                 WHERE distribution_id = ? AND notes = ? AND branch_id = ? FOR UPDATE'
            );
            $ownSt->execute([$distributionId, $itemCode, $branchId]);
            $ownCurrent = (float) $ownSt->fetchColumn();

            if ($qty > $ownCurrent + 0.0001) {
                throw new RuntimeException(
                    'رصيد الإدارة للصنف ده مش واصل، فمش هينفع تزوّد الكمية. '
                    . 'تقدر تقلّلها أو تصفّرها. شغّل وكيل مزامنة الإدارة عشان توزّع أكتر.'
                );
            }
            $cap = min($itemQty, $othersTotal + $ownCurrent);
        } else {
            $cap = min($itemQty, max(0.0, $hqBal));   // الرصيد السالب = صفر
        }

        // المتاح للفرع ده = السقف − توزيع باقي الفروع
        $available = $cap - $othersTotal;
        if ($qty > $available + 0.0001) {
            $msg = 'الكمية (' . number_format($qty, 2) . ') أكبر من المتاح (' .
                   number_format(max(0.0, $available), 2) . ')';
            // لو السبب رصيد الإدارة مش كمية الفاتورة، قولها صريحة —
            // من غير كده الرسالة بتبان غلط في النظام مش حماية مقصودة
            if ($cap < $itemQty - 0.0001) {
                $msg .= ' — رصيد الإدارة ' . number_format(max(0.0, $hqBal), 2)
                      . ' والفاتورة ' . number_format($itemQty, 2);
            }
            throw new RuntimeException($msg . '.');
        }

        // استبدال سطور الفرع للصنف ده بسطر واحد بالقيمة الجديدة
        $pdo->prepare(
            'DELETE FROM invoice_distribution_lines
             WHERE distribution_id = ? AND notes = ? AND branch_id = ?'
        )->execute([$distributionId, $itemCode, $branchId]);

        if ($qty > 0) {
            $pdo->prepare(
                'INSERT INTO invoice_distribution_lines (distribution_id, branch_id, amount, notes)
                 VALUES (?, ?, ?, ?)'
            )->execute([$distributionId, $branchId, $qty, $itemCode]);
        }

        if ($own) $pdo->commit();
        return [
            'remaining'    => $itemQty - $othersTotal - $qty,
            'branch_total' => $qty,
        ];
    } catch (Throwable $ex) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * تجميع الفواتير لنفس المورد خلال 3 أيام (للعرض فقط — مش بيجمّع المبالغ).
 * بياخد قائمة فواتير (من sqlsrv_fetch_invoices) ويرجّعها مجمّعة.
 *
 * @return array قائمة مجموعات؛ كل مجموعة: ['supplier_name','invoices'=>[...]]
 */
function group_invoices_3days(array $invoices): array
{
    // رتّب بالمورد ثم التاريخ
    usort($invoices, function ($a, $b) {
        $s = strcmp((string)$a['supplier_id'], (string)$b['supplier_id']);
        if ($s !== 0) return $s;
        return strtotime((string)$a['invoice_date']) <=> strtotime((string)$b['invoice_date']);
    });

    $groups = [];
    foreach ($invoices as $inv) {
        $placed = false;
        $invTime = strtotime((string) $inv['invoice_date']);
        // دوّر على مجموعة لنفس المورد وآخر فاتورة فيها خلال 3 أيام
        for ($i = count($groups) - 1; $i >= 0; $i--) {
            $g = $groups[$i];
            if ((string)$g['supplier_id'] !== (string)$inv['supplier_id']) continue;
            $lastTime = strtotime((string) $g['invoices'][count($g['invoices'])-1]['invoice_date']);
            if (abs($invTime - $lastTime) <= 3 * 86400) {
                $groups[$i]['invoices'][] = $inv;
                $placed = true;
                break;
            }
        }
        if (!$placed) {
            $groups[] = [
                'supplier_id'   => $inv['supplier_id'],
                'supplier_name' => $inv['supplier_name'],
                'invoices'      => [$inv],
            ];
        }
    }
    return $groups;
}

// =====================================================================
//  حالة الفاتورة + الفواتير غير المحفوظة
// =====================================================================

/**
 * جلب الفواتير غير المحفوظة (من جدول القالب STO_TRANS_HEADER_YY_MM).
 * دي فواتير لسه ما اترحّلتش لجدول الشهر، بتتعرف بـ STH_STATUS = 0.
 */
/**
 * تجميع الفواتير حسب الفرع (المخزن sto_id في نظام الشركة).
 * الإدارة (الفرع اللي in_receiving = 0، يعني مش فرع بيع بضاعة) بتظهر أولاً،
 * وبعدها الفروع بالترتيب. الفواتير اللي مفيش ليها فرع معروف بتتحط في
 * "غير محدّد" في الآخر.
 *
 * بيرجّع مصفوفة مرتبة: [ ['branch_id','branch_name','is_admin','invoices'=>[...]], ... ]
 */
function group_invoices_by_branch(array $invoices): array
{
    // خريطة sto_id → [الاسم, in_receiving] من الفروع المحلية
    $map = [];
    foreach (db()->query('SELECT sto_id, name, in_receiving FROM branches WHERE is_active = 1 AND sto_id IS NOT NULL') as $b) {
        $map[(int) $b['sto_id']] = ['name' => $b['name'], 'in_receiving' => (int) $b['in_receiving']];
    }

    $groups = []; // sto_id => bucket
    foreach ($invoices as $inv) {
        $sid = (int) ($inv['company_branch_id'] ?? 0);
        if (!isset($groups[$sid])) {
            $known = $map[$sid] ?? null;
            $groups[$sid] = [
                'branch_id'   => $sid,
                'branch_name' => $known['name'] ?? ($sid > 0 ? 'فرع #' . $sid : 'غير محدّد'),
                // الإدارة = فرع مش داخل في الاستلامات (in_receiving = 0)
                'is_admin'    => $known !== null && $known['in_receiving'] === 0,
                'is_known'    => $known !== null,
                'invoices'    => [],
            ];
        }
        $groups[$sid]['invoices'][] = $inv;
    }

    $buckets = array_values($groups);
    // الترتيب: الإدارة أولاً، بعدها الفروع المعروفة بالاسم، وأخيراً غير المحدّد
    usort($buckets, function ($a, $b) {
        if ($a['is_admin'] !== $b['is_admin']) return $a['is_admin'] ? -1 : 1;
        if ($a['is_known'] !== $b['is_known']) return $a['is_known'] ? -1 : 1;
        return strcmp($a['branch_name'], $b['branch_name']);
    });
    return $buckets;
}

function sqlsrv_fetch_unposted_invoices(): array
{
    // ١) النسخة المحلية الأول (hq_unposted)
    try {
        $rows = db()->query(
            "SELECT sth_id AS inv_id, doc_no AS inv_no, supplier_id, supplier_code,
                    supplier_name, net_amount, total_amount, invoice_date,
                    company_branch AS company_branch_id, doc_type, remark
             FROM hq_unposted ORDER BY invoice_date DESC"
        )->fetchAll();
        // الجدول موجود؟ (حتى لو فاضي، يبقى مفيش فواتير غير مرحّلة)
        if ($rows || hq_unposted_ready()) {
            foreach ($rows as &$r) { $r['suffix'] = 'YY_MM'; }
            return $rows;
        }
    } catch (Throwable $ex) {
        // نكمّل على الجسر
    }

    // ٢) الجسر
    $head  = SQLSRV_PREFIX . '[STO_TRANS_HEADER_YY_MM]';
    $sql = "SELECT h." . COL_INV_ID . " AS inv_id,
                   LTRIM(RTRIM(h." . COL_INV_NO . ")) AS inv_no,
                   h." . COL_INV_SUPP_ID . " AS supplier_id,
                   LTRIM(RTRIM(s.SUPP_CODE)) AS supplier_code,
                   LTRIM(RTRIM(s." . SUPP_NAME_COL . ")) AS supplier_name,
                   h." . COL_INV_NET . " AS net_amount, h." . COL_INV_TOTAL . " AS total_amount,
                   h." . COL_INV_DATE . " AS invoice_date,
                   h." . COL_INV_BRANCH . " AS company_branch_id,
                   h.SSTT_ID AS doc_type,
                   h.STH_HEAD_REMARK1 AS remark
            FROM " . $head . " h
            LEFT JOIN " . SUPPLIERS_TABLE . " s ON s." . SUPP_ID_COL . " = h." . COL_INV_SUPP_ID . "
            WHERE h.SSTT_ID IN (2, 6) AND h.STH_STATUS = 0
            ORDER BY h." . COL_INV_DATE . " DESC";

    $out = [];
    try {
        foreach (sqlsrv_query($sql) as $row) {
            $row['suffix'] = 'YY_MM';
            if (isset($row['supplier_name'])) $row['supplier_name'] = sqlsrv_fix_encoding((string)$row['supplier_name']);
            if (isset($row['supplier_code'])) $row['supplier_code'] = sqlsrv_fix_encoding((string)$row['supplier_code']);
            if (isset($row['remark']))        $row['remark']        = sqlsrv_fix_encoding((string)$row['remark']);
            $out[] = $row;
        }
    } catch (Throwable $ex) {
        error_log('receiving: فشل قراءة الفواتير غير المحفوظة — ' . $ex->getMessage());
        $GLOBALS['recv_errors'][] = 'STO_TRANS_HEADER_YY_MM: ' . $ex->getMessage();
    }
    return $out;
}

/**
 * تسجيل إن فرع اتصدّر/اتطبع تقريره في فاتورة.
 * idempotent — التكرار مابيعملش صفوف زيادة.
 */
function mark_branch_exported(int $distributionId, int $branchId, ?int $userId = null): void
{
    db()->prepare(
        'INSERT INTO invoice_branch_exports (distribution_id, branch_id, exported_by)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE exported_at = CURRENT_TIMESTAMP'
    )->execute([$distributionId, $branchId, $userId]);
}

/**
 * تحديث حالة الفاتورة بعد تغيير (توزيع أو تصدير).
 * بتجيب الأصناف من SQL Server وتعيد الحساب والتخزين.
 * الفشل مابيوقفش العملية الأصلية — الحالة تتصلّح في أول إعادة حساب.
 */
function refresh_invoice_status(int $distributionId, $invoiceId, ?string $suffix = null): void
{
    try {
        $items = sqlsrv_fetch_invoice_items($invoiceId, $suffix);
        recalc_invoice_status($distributionId, $items);
    } catch (Throwable $ex) {
        error_log('receiving: تعذّر تحديث حالة الفاتورة — ' . $ex->getMessage());
    }
}

/**
 * دمج الأصناف المكررة في فاتورة واحدة.
 *
 * ⚠️ الصنف الواحد ممكن يتكرر في أكتر من سطر جوه نفس الفاتورة
 *    (مثلاً 6000 في سطر و1200 في سطر تاني بنفس الكود).
 *
 * بس التوزيع بيتخزّن في invoice_distribution_lines بمفتاح
 * (سجل التوزيع + الفرع + كود الصنف) — يعني **سجل واحد للكود**
 * مهما اتكرر في الفاتورة.
 *
 * من غير الدمج ده بيحصل الآتي:
 *   • التوزيع اللي اتعمل على سطر بيظهر على السطر التاني كمان
 *   • مجموع الفروع يبان أكبر من كمية السطر → "أكبر من المتاح"
 *   • تصفير سطر بيصفّر التاني معاه (نفس السجل)
 *   • recalc_invoice_status بتقارن إجمالي الكود بكمية سطر واحد
 *     فتقول "اتوزّعت بالكامل" وهي لأ
 *
 * فالقاعدة: أي حاجة ليها علاقة بالتوزيع لازم تشوف **صف واحد للكود**.
 *
 * ملاحظة: شاشة الخصومات بتستخدم السطور **خام** (من غير دمج) لأن
 * السطر المكرر ممكن يكون بسعر مختلف، والخصم بيتحسب سطر سطر.
 *
 * @param array $items أصناف من sqlsrv_fetch_invoice_items
 * @return array نفس الشكل، بس صف واحد لكل كود
 */
function merge_invoice_items(array $items): array
{
    if (!$items) return [];

    $out = [];
    foreach ($items as $it) {
        $code = trim((string) ($it['item_code'] ?? ''));
        if ($code === '') $code = '#' . (int) ($it['item_id'] ?? 0);

        if (!isset($out[$code])) {
            $out[$code] = $it;
            $out[$code]['item_code'] = $code;
            $out[$code]['quantity']  = (float) ($it['quantity'] ?? 0);
            $out[$code]['line_count'] = 1;
            continue;
        }

        // الكميات بتتجمع — ده جوهر الإصلاح
        $out[$code]['quantity'] += (float) ($it['quantity'] ?? 0);
        $out[$code]['line_count']++;

        // سعر الوحدة: الأعلى (نفس منطق شاشة المجموعة)
        $up = (float) ($it['unit_price'] ?? 0);
        if ($up > (float) ($out[$code]['unit_price'] ?? 0)) {
            $out[$code]['unit_price'] = $up;
        }

        // التكلفة: أول قيمة حقيقية بنلاقيها
        if ((float) ($out[$code]['cost_price'] ?? 0) <= 0) {
            $out[$code]['cost_price'] = (float) ($it['cost_price'] ?? 0);
        }

        // رقم الصنف والاسم: أول واحد مش فاضي
        if ((int) ($out[$code]['item_id'] ?? 0) <= 0 && (int) ($it['item_id'] ?? 0) > 0) {
            $out[$code]['item_id'] = (int) $it['item_id'];
        }
        if (trim((string) ($out[$code]['item_name'] ?? '')) === '') {
            $out[$code]['item_name'] = (string) ($it['item_name'] ?? '');
        }
    }

    return array_values($out);
}

/**
 * الفروع اللي اتصدّرت في فاتورة. بترجّع [branch_id => true].
 */
function get_exported_branches(int $distributionId): array
{
    $st = db()->prepare('SELECT branch_id FROM invoice_branch_exports WHERE distribution_id = ?');
    $st->execute([$distributionId]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int) $r['branch_id']] = true;
    return $out;
}

/**
 * حساب حالة فاتورة وتخزينها.
 *
 *   new   = جديدة      — لسه فيه أصناف ماتوزّعتش بالكامل
 *   split = تم التقسيم  — كل الأصناف اتوزّعت، بس لسه فيه فرع ماتصدّرش
 *   done  = تم التوزيع  — اتقسمت + كل فرع ليه كمية اتصدّر
 *
 * بتحسب وتخزّن في invoice_distribution.status عشان القائمة تقراها جاهزة.
 * بتتنادى عند أي تغيير في التوزيع أو التصدير.
 *
 * @param array $items أصناف الفاتورة (من sqlsrv_fetch_invoice_items)
 */
function recalc_invoice_status(?int $distributionId, array $items): string
{
    if (!$distributionId || !$items) return 'new';

    // الصنف المكرر لازم يتجمع الأول — التوزيع متخزّن بالكود مش بالسطر.
    // من غير كده بنقارن إجمالي الكود (6000) بكمية سطر واحد (1200)
    // فتطلع الفاتورة "تم التوزيع" وهي لسه ناقصة.
    $items = merge_invoice_items($items);

    $pdo = db();

    // كل التوزيعات للفاتورة دي مرة واحدة (بدل استعلام لكل صنف)
    $st = $pdo->prepare(
        'SELECT notes AS item_code, branch_id, SUM(amount) AS qty
         FROM invoice_distribution_lines
         WHERE distribution_id = ?
         GROUP BY notes, branch_id'
    );
    $st->execute([$distributionId]);

    $byItem     = [];   // [code => total]
    $usedBranch = [];   // [branch_id => true] الفروع اللي أخدت كمية فعلاً
    foreach ($st->fetchAll() as $r) {
        $q = (float) $r['qty'];
        if ($q <= 0) continue;
        $byItem[(string) $r['item_code']] = ($byItem[(string) $r['item_code']] ?? 0) + $q;
        $usedBranch[(int) $r['branch_id']] = true;
    }

    $status = 'new';
    if ($byItem) {
        // كل الأصناف لازم تكون اتوزّعت بالكامل
        $allFull = true;
        foreach ($items as $it) {
            $code = (string) $it['item_code'];
            $qty  = (float) $it['quantity'];
            if (($byItem[$code] ?? 0) < $qty - 0.0001) { $allFull = false; break; }
        }

        if ($allFull) {
            // اتقسمت — نشوف التصدير. المهم الفروع اللي أخدت كمية بس.
            $exported = get_exported_branches($distributionId);
            $status = 'done';
            foreach (array_keys($usedBranch) as $bid) {
                if (empty($exported[$bid])) { $status = 'split'; break; }
            }
        }
    }

    // ⚠️ لو المستخدم غيّر الحالة بإيده، الحساب التلقائي ما يدوسش عليها.
    // الشرط status_manual = 0 في الـ WHERE هو اللي بيحمي القيمة اليدوية.
    $pdo->prepare(
        'UPDATE invoice_distribution
         SET status = ?, status_at = NOW()
         WHERE id = ? AND status_manual = 0'
    )->execute([$status, $distributionId]);

    // لو كانت مقفولة يدوي، نرجّع الحالة المخزّنة مش المحسوبة
    $st2 = $pdo->prepare('SELECT status, status_manual FROM invoice_distribution WHERE id = ?');
    $st2->execute([$distributionId]);
    $row = $st2->fetch();
    if ($row && (int) $row['status_manual'] === 1) {
        return (string) $row['status'];
    }

    return $status;
}

/**
 * الحالات المخزّنة لمجموعة فواتير — استعلام واحد على MySQL.
 * بترجّع [invoice_no => status].
 */
function get_invoice_statuses(array $invoiceNos): array
{
    $nos = array_values(array_unique(array_filter(
        array_map(fn($n) => (string) $n, $invoiceNos),
        fn($n) => $n !== ''
    )));
    if (!$nos) return [];

    $ph = implode(',', array_fill(0, count($nos), '?'));
    $st = db()->prepare("SELECT invoice_no, status FROM invoice_distribution WHERE invoice_no IN ($ph)");
    $st->execute($nos);

    $out = [];
    foreach ($st->fetchAll() as $r) $out[(string) $r['invoice_no']] = (string) $r['status'];
    return $out;
}

/**
 * ملاحظات الفواتير — عشان نعرض النص نفسه في القائمة.
 * بترجّع [invoice_no => نص الملاحظة].
 */
function get_invoices_with_notes(array $invoiceNos): array
{
    $nos = array_values(array_unique(array_filter(
        array_map(fn($n) => (string) $n, $invoiceNos),
        fn($n) => $n !== ''
    )));
    if (!$nos) return [];

    try {
        $ph = implode(',', array_fill(0, count($nos), '?'));
        $st = db()->prepare(
            "SELECT invoice_no, user_notes FROM invoice_distribution
             WHERE invoice_no IN ($ph) AND user_notes IS NOT NULL AND user_notes <> ''"
        );
        $st->execute($nos);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(string) $r['invoice_no']] = (string) $r['user_notes'];
        }
        return $out;
    } catch (Throwable $ex) {
        return [];
    }
}

/**
 * تسمية الحالة وكلاس التنسيق.
 */
function invoice_status_label(string $status): array
{
    return match ($status) {
        'done'  => ['تم التوزيع', 'st-done'],
        'split' => ['تم التقسيم', 'st-split'],
        default => ['جديدة',      'st-new'],
    };
}

// =====================================================================
//  أسعار البيع لأصناف الفاتورة
//  المصدر: COM_ITEM_PRICE.CIP_PRICE (نفس مصدر مودويل استعلام الأسعار)
//  التخزين: invoice_item_prices عندنا — قاعدة الشركة مابتتكتبش أبدًا.
// =====================================================================

/**
 * جلب أسعار البيع من SQL Server لمجموعة أكواد (استعلام واحد).
 * بترجّع [item_code => price].
 */
function sqlsrv_fetch_sale_prices(array $codes): array
{
    $codes = array_values(array_unique(array_filter(
        array_map(fn($c) => trim((string) $c), $codes),
        fn($c) => $c !== ''
    )));
    if (!$codes) return [];

    // ١) نجرّب النسخة المحلية الأول (hq_items) — دي بتشتغل حتى لو
    //    النت عند جهاز الإدارة فاصل، وأسرع من الجسر.
    $out    = hq_local_prices($codes);
    $missing = array_values(array_filter($codes, fn($c) => !isset($out[$c])));

    // ٢) اللي مش موجود محلي بس، نسأل عنه الجسر (لو النت شغال)
    if ($missing) {
        foreach (array_chunk($missing, 200) as $chunk) {
            $ph  = implode(',', array_fill(0, count($chunk), '?'));
            $sql = "SELECT LTRIM(RTRIM(c.IC_CODE)) AS code, p.CIP_PRICE AS price
                    FROM " . SQLSRV_PREFIX . "ITEM_CODES c
                    LEFT JOIN " . SQLSRV_PREFIX . "COM_ITEM_PRICE p ON p.CIP_ID = c.CIP_ID
                    WHERE c.IC_CODE IN ($ph)";
            try {
                foreach (sqlsrv_query($sql, $chunk) as $r) {
                    $out[(string) $r['code']] = (float) ($r['price'] ?? 0);
                }
            } catch (Throwable $ex) {
                error_log('receiving: فشل جلب أسعار البيع — ' . $ex->getMessage());
                $GLOBALS['recv_errors'][] = 'أسعار البيع: ' . $ex->getMessage();
            }
        }
    }
    return $out;
}

/**
 * أسعار البيع من النسخة المحلية (hq_items) — بالباركود.
 * بترجّع [barcode => price]. فاضية لو الجدول لسه ما اتعملش.
 */
function hq_local_prices(array $barcodes): array
{
    if (!$barcodes) return [];
    $out = [];
    try {
        $pdo = db();
        foreach (array_chunk($barcodes, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $st = $pdo->prepare(
                "SELECT barcode, sale_price FROM hq_items WHERE barcode IN ($ph)"
            );
            $st->execute($chunk);
            foreach ($st->fetchAll() as $r) {
                $out[(string) $r['barcode']] = (float) $r['sale_price'];
            }
        }
    } catch (Throwable $ex) {
        // الجدول ممكن ما يكونش اتعمل لسه — نرجّع فاضي ونكمّل على الجسر
        return [];
    }
    return $out;
}

/**
 * أسعار البيع المحفوظة عندنا لفاتورة. بترجّع [item_code => price].
 */
function get_invoice_sale_prices(int $distributionId): array
{
    $st = db()->prepare(
        'SELECT item_code, sale_price FROM invoice_item_prices WHERE distribution_id = ?'
    );
    $st->execute([$distributionId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(string) $r['item_code']] = (float) $r['sale_price'];
    }
    return $out;
}

/**
 * أسعار البيع لعرضها: المحفوظ عندنا أولًا، والباقي يتجاب من السيستم
 * ويتخزّن (عشان المرات الجاية ما نضربش SQL Server تاني).
 *
 * @param array $items أصناف الفاتورة (من sqlsrv_fetch_invoice_items)
 * @return array [item_code => price]
 */
function resolve_invoice_sale_prices(int $distributionId, array $items): array
{
    return refresh_invoice_sale_prices($distributionId, $items, false);
}

/**
 * جلب/تحديث أسعار البيع من سيستم الشركة.
 *
 * سيستم الشركة هو المرجع دايمًا — التحديث بيكتب فوق أي تعديل يدوي
 * اتعمل على الموقع (قرار Mohamed).
 *
 * $force = false → الأصناف الناقصة + اللي عدّى على آخر جلب ليها 24 ساعة
 *                  (تحديث يومي تلقائي مع فتح الفاتورة)
 * $force = true  → كل الأصناف فورًا (زرار "تحديث الأسعار من السيستم")
 *
 * لو السيستم مرجعش سعر لصنف، المخزّن عندنا بيفضل زي ما هو.
 */
function refresh_invoice_sale_prices(int $distributionId, array $items, bool $force = false): array
{
    $pdo = db();

    // المخزّن عندنا + وقت آخر جلب من السيستم
    $st = $pdo->prepare(
        'SELECT item_code, sale_price, fetched_at
         FROM invoice_item_prices WHERE distribution_id = ?'
    );
    $st->execute([$distributionId]);

    $saved = [];   // [code => price]
    $stale = [];   // [code => true] عدّى عليه يوم أو مجابش قبل كده
    foreach ($st as $r) {
        $code = (string) $r['item_code'];
        $saved[$code] = (float) $r['sale_price'];
        $old = $r['fetched_at'] === null
            || strtotime((string) $r['fetched_at']) < time() - 86400;
        if ($force || $old) $stale[$code] = true;
    }

    // المطلوب جلبه من السيستم: الناقص + القديم
    $wanted = [];
    foreach ($items as $it) {
        $code = (string) $it['item_code'];
        if ($code === '') continue;
        if (!array_key_exists($code, $saved) || isset($stale[$code])) $wanted[] = $code;
    }

    if ($wanted) {
        $fetched = sqlsrv_fetch_sale_prices($wanted);
        if ($fetched) {
            $ins = $pdo->prepare(
                'INSERT INTO invoice_item_prices
                        (distribution_id, item_code, sale_price, source_price, fetched_at)
                 VALUES (?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                        sale_price   = VALUES(sale_price),
                        source_price = VALUES(source_price),
                        fetched_at   = NOW()'
            );
            foreach ($fetched as $code => $price) {
                try {
                    $ins->execute([$distributionId, $code, $price, $price]);
                    $saved[$code] = $price;
                } catch (Throwable $ex) {
                    error_log('receiving: فشل تخزين سعر ' . $code . ' — ' . $ex->getMessage());
                }
            }
        }
        // أي كود مالوش سعر في السيستم ولا مخزّن عندنا → صفر (للعرض بس)
        foreach ($wanted as $code) {
            if (!array_key_exists($code, $saved)) $saved[$code] = 0.0;
        }
    }

    return $saved;
}

/**
 * حفظ سعر بيع معدّل (على نسختنا فقط).
 */
function save_invoice_sale_price(int $distributionId, string $itemCode, float $price, ?int $userId = null): void
{
    db()->prepare(
        'INSERT INTO invoice_item_prices (distribution_id, item_code, sale_price, updated_by)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE sale_price = VALUES(sale_price), updated_by = VALUES(updated_by)'
    )->execute([$distributionId, $itemCode, $price, $userId]);
}

// =====================================================================
//  دقة سعر ما بعد الخصم — 2026-08-09
//
//  كان بخانتين، وده كان بيولّد فروق قروش مالهاش داعي.
//
//  الدليل (فاتورة 93 — المنار MG، خصم 3%):
//      نظام الشركة عرض سعر وحدة 27.65 وقيمة سطر 829.35 لكمية 30.
//      829.35 ÷ 30 = 27.645  ← يعني الشركة مخزّنة تلات خانات
//      وبتعرض اتنين بس. الصافي عندهم طلع 2,342.55 بالظبط.
//
//      إحنا كنا بنقرّب 28.50 × 0.97 = 27.645 لـ 27.65، فالمجموع
//      يطلع 2,342.70 (أكتر بـ 0.15)، والتسوية تدوّر تقفلها.
//
//      وماكانتش بتقدر تقفلها: كل الكميات في الفاتورة دي زوجية
//      (30 و 4)، فأي تحريك بالقرش بيغيّر الإجمالي بمضاعفات 0.02،
//      و 0.15 رقم فردي بالقروش → مستحيل رياضياً. فضل قرش فرق.
//
//  بتلات خانات: 27.645 تتكتب زي ما هي والصافي يطابق من غير تسوية.
//
//  ⚠️ تحديث 2026-08-11 — بقت **٥ خانات**:
//     تلات خانات قرّبت المسافة بس ما ضبطتش كل الفواتير. السبب إن
//     قيمة السطر لازم تفضل قابلة للقسمة على الكمية بدقة DISC_PRICE_DP —
//     ومع تلات خانات فيه كميات كتير مفيش لها سعر بيرجّع القيمة
//     المطلوبة بالظبط (مثال: كمية 7 وقيمة 100.00 → 14.28571...
//     اللي بتتقرّب لـ 14.286 وترجّع 100.002). بخمس خانات المساحة
//     بتتوسّع ١٠٠ ضعف فالتطابق بيقفل في كل الحالات تقريباً.
//
//  ⚠️ لو نظام الشركة اتضح إنه بيقرّب المستورد لعدد خانات أقل، رجّع
//     الثابت للرقم اللي بيقبله وخلاص — كل الحسابات والعرض والتصدير
//     بتمشي وراه. **ده الثابت الوحيد اللي بيتغيّر.**
// =====================================================================
const DISC_PRICE_DP   = 5;      // خانات سعر ما بعد الخصم
const DISC_ADJ_CENTS  = 25;     // أقصى تحريك لقيمة السطر وقت التسوية (بالقروش)

/** أصغر وحدة تحريك للسعر حسب الدقة (٥ خانات → 0.00001) */
function disc_step(): float
{
    return 1 / (10 ** DISC_PRICE_DP);
}

/**
 * عرض سعر ما بعد الخصم: بيوصل لأقصى دقة بس من غير أصفار زيادة،
 * وبحد أدنى خانتين عشان مايبانش "63" بدل "63.00".
 */
function disc_price_txt(float $v): string
{
    $s = number_format($v, DISC_PRICE_DP, '.', '');
    if (DISC_PRICE_DP > 2 && strpos($s, '.') !== false) {
        $s = rtrim($s, '0');
        // رجّع خانتين على الأقل
        $dec = strlen($s) - strpos($s, '.') - 1;
        if ($dec < 2) $s = number_format((float) $s, 2, '.', '');
    }
    return $s;
}

/**
 * تنسيق السعر للتصدير: يحافظ على العشري زي ما هو من غير أصفار زيادة.
 *   15      → 15
 *   33.50   → 33.5
 *   61.000  → 61
 */
function fmt_price_out(float $v): string
{
    $s = number_format($v, 5, '.', '');
    if (strpos($s, '.') !== false) {
        $s = rtrim(rtrim($s, '0'), '.');
    }
    return $s === '' ? '0' : $s;
}

// =====================================================================
//  حساب الخصم
//  الفواتير بتيجي بإجمالي وصافي، والفرق بينهم خصم على مستوى الفاتورة
//  (مش نازل على الأصناف). هنا بنوزّعه كنسبة على كل صنف.
// =====================================================================

/**
 * حساب أسعار ما بعد الخصم لأصناف فاتورة.
 *
 *   نسبة الخصم = (الصافي − الإجمالي) ÷ الإجمالي        [سالبة يعني خصم]
 *   السعر بعد الخصم = السعر × (1 + النسبة)
 *
 *  خصم لكل صنف (2026-08-10):
 *  ----------------------------------------------------------------
 *  $itemPct = [كود الصنف => نسبة خصم موجبة]. الصنف اللي ليه نسبة هنا
 *  بيتحسب بنسبته لوحده (مثلاً 5 = خصم 5%) و**يتجاهل خصم الفاتورة
 *  الكلي تماماً**، ومابيدخلش تسوية التقريب لأنه مضبوط بالنية.
 *  الصنف اللي مالوش نسبة بيمشي بخصم الفاتورة زي الأول.
 *
 *  الوضعين مش بيشتغلوا مع بعض: يا خصم فاتورة على الكل، يا نسب
 *  على أصناف مختارة. أي صنف بنسبة بيخرج من حساب خصم الفاتورة.
 *
 * @param array $itemPct  [item_code => pct]  نسبة خصم خاصة لأصناف (اختياري)
 * @return array{rate: float, items: array, totals: array}
 */
function calc_invoice_discount(
    array $invHeader,
    array $items,
    ?float $netOverride = null,
    array $itemPct = []
): array {
    $total = (float) ($invHeader['total_amount'] ?? 0);
    $net   = (float) ($invHeader['net_amount'] ?? 0);

    // تجاوز يدوي للصافي (زي ملف الإكسيل: خانة "الاجمالي بعد"):
    // لو فيه خصم إضافي أو ضريبة قيمة مضافة، Mohamed بيكتب الإجمالي النهائي
    // بنفسه والنسبة بتتحسب عليه بدل صافي الهيدر.
    if ($netOverride !== null && $netOverride > 0.00001) {
        $net = $netOverride;
    }

    // النسبة من هيدر الفاتورة. لو الإجمالي صفر مفيش نسبة.
    $rate = ($total > 0.00001) ? (($net - $total) / $total) : 0.0;

    // =================================================================
    //  ⚠️ التقريب لازم يحصل هنا مش وقت التصدير.
    //  الملف بيتصدّر بسعر مقرّب، ونظام الشركة بيحسب
    //  (الكمية × السعر المقرّب). فلو إحنا حسبنا بالسعر غير المقرّب،
    //  الرقم اللي على الموقع مش هيطابق اللي هيطلع عندهم — وده مصدر
    //  فرق الجنيهات. فبنحسب بنفس السعر اللي هيتصدّر بالظبط.
    //
    //  الدقة = DISC_PRICE_DP (٥ خانات) — شوف الشرح فوق عند الثابت.
    //  قيمة السطر بتفضل خانتين لأن ده اللي بيتخزّن عندهم.
    // =================================================================
    $out = [];
    $sumBefore = 0.0; $sumAfter = 0.0; $sumQty = 0.0;

    foreach ($items as $it) {
        $qty   = (float) $it['quantity'];
        $price = (float) ($it['unit_price'] ?? 0);
        $code  = (string) $it['item_code'];

        // خصم خاص بالصنف؟ (نسبة موجبة = خصم). لو موجود يتجاهل خصم الفاتورة.
        $ownPct  = $itemPct[$code] ?? null;
        $hasOwn  = ($ownPct !== null && $ownPct > 0.00001);
        $useRate = $hasOwn ? (-$ownPct / 100) : $rate;

        $before     = $qty * $price;
        $priceAfter = round($price * (1 + $useRate), DISC_PRICE_DP); // نفس اللي هيتصدّر
        $after      = round($qty * $priceAfter, 2);                  // نفس اللي هيحسبوه

        $out[] = [
            'item_code'   => $code,
            'item_name'   => (string) $it['item_name'],
            'quantity'    => $qty,
            'unit_price'  => $price,
            'price_after' => $priceAfter,
            'total_before'=> $before,
            'total_after' => $after,
            'own_pct'     => $hasOwn ? (float) $ownPct : null,  // للعرض
            'locked'      => $hasOwn,   // مايتحرّكش في التسوية
        ];

        $sumQty    += $qty;
        $sumBefore += $before;
        $sumAfter  += $after;
    }

    // =================================================================
    //  تسوية فرق التقريب
    //  مجموع السطور المقرّبة ممكن ما يطلعش بالظبط زي الصافي المطلوب.
    //  بنقفل الفرق بتحريك صنف أو اتنين بأقل قدر ممكن.
    //
    //  ⚠️ الأصناف اللي ليها نسبة خاصة (locked) مابتدخلش التسوية —
    //  سعرها مضبوط بالنية، ومالهاش علاقة بخصم الفاتورة. كمان
    //  الصافي المطلوب هنا بيتحسب على الجزء اللي عليه خصم الفاتورة
    //  بس: (الصافي − مجموع الأصناف المقفولة قبل الخصم × نسبتها).
    //  لكن الأبسط والأصح: التسوية بتقفل الفرق بين مجموع كل السطور
    //  والصافي، بتحريك الأصناف غير المقفولة بس.
    //
    //  ⚠️ البحث بيشتغل على **قيمة السطر بالقروش** مش على السعر.
    //  السبب: مع الدقة العالية، تحريك السعر بأصغر خطوة لصنف كميته 3
    //  بيغيّر قيمة السطر 0.003 — اللي بتتقرّب لصفر، يعني تحريك
    //  ملوش أي أثر. فبدل ما نخمّن في السعر، بنحدد الأول قيمة السطر
    //  اللي عايزينها (قرش زيادة أو نقصان) وبعدين نرجع نحسب السعر
    //  منها، ونتأكد إن (الكمية × السعر) بترجّع نفس القيمة بالظبط.
    //
    //  ملاحظة رياضية: التطابق التام مش مضمون 100% — قيمة السطر
    //  لازم تفضل قابلة للقسمة على الكمية بدقة DISC_PRICE_DP. لو
    //  مفيش تركيبة، بنقرّب لأقرب رقم ممكن ونعرض الباقي صريح.
    // =================================================================
    $hasLocked = false;
    foreach ($out as $r) { if (!empty($r['locked'])) { $hasLocked = true; break; } }

    // لو كل الأصناف عليها نسبة خاصة، مفيش خصم فاتورة أصلاً — بلاش تسوية.
    $allLocked = true;
    foreach ($out as $r) { if (empty($r['locked'])) { $allLocked = false; break; } }

    // الصافي المستهدف للتسوية:
    //   من غير نسب صنف  → صافي الفاتورة زي الأول
    //   مع نسب صنف      → مجموع كل السطور نفسه (الأصناف المقفولة
    //                      مضبوطة، والباقي بخصم الفاتورة — مفيش رقم
    //                      خارجي نطابقه، فالتسوية بتقفل فرق التقريب
    //                      الداخلي بس على السطور غير المقفولة).
    $target   = $hasLocked ? $sumAfter : $net;
    $residual = round($target - $sumAfter, 2);

    if (!$allLocked && abs($residual) > 0.004 && $out) {
        $n    = count($out);
        $need = (int) round($residual * 100);   // الفرق المطلوب بالقروش
        $MAXC = DISC_ADJ_CENTS;                 // أقصى تحريك لقيمة السطر (قروش)

        // تحريك قيمة السطر $idx بمقدار $c قرش.
        // بترجّع [السعر الجديد, القيمة الجديدة] لو ينفع، وإلا null.
        // الأصناف المقفولة بترجّع null دايمًا (ماتتحركش).
        $tryMove = static function (array $row, int $c) {
            if (!empty($row['locked'])) return null;
            $q = (float) $row['quantity'];
            if ($q <= 0 || $c === 0) return null;
            $newTotal = round((float) $row['total_after'] + $c / 100, 2);
            if ($newTotal < 0) return null;
            $newPrice = round($newTotal / $q, DISC_PRICE_DP);
            if ($newPrice < 0) return null;
            // لازم الشركة تطلّع نفس القيمة لما تضرب الكمية × السعر
            if (abs(round($q * $newPrice, 2) - $newTotal) > 0.004) return null;
            return [$newPrice, $newTotal];
        };

        $best = ['left' => abs($need), 'moves' => []];

        // (١) صنف واحد يقفل الفرق كله
        for ($i = 0; $i < $n && $best['left'] > 0; $i++) {
            if (abs($need) > $MAXC) break;
            if ($m = $tryMove($out[$i], $need)) {
                $best = ['left' => 0, 'moves' => [[$i, $m]]];
            }
        }

        // (٢) صنفين — الأول بجزء والتاني بالباقي
        if ($best['left'] > 0) {
            for ($i = 0; $i < $n && $best['left'] > 0; $i++) {
                for ($c = -$MAXC; $c <= $MAXC && $best['left'] > 0; $c++) {
                    $mi = $tryMove($out[$i], $c);
                    if (!$mi) continue;
                    $rest = $need - $c;
                    if ($rest === 0)          { $best = ['left'=>0,'moves'=>[[$i,$mi]]]; break; }
                    if (abs($rest) > $MAXC)   continue;
                    for ($j = $i + 1; $j < $n; $j++) {
                        if ($mj = $tryMove($out[$j], $rest)) {
                            $best = ['left' => 0, 'moves' => [[$i, $mi], [$j, $mj]]];
                            break;
                        }
                    }
                }
            }
        }

        // (٣) مفيش تركيبة كاملة — قرّب لأقرب حاجة ممكنة بصنف واحد
        if ($best['left'] > 0) {
            for ($i = 0; $i < $n; $i++) {
                for ($c = -$MAXC; $c <= $MAXC; $c++) {
                    if (!($m = $tryMove($out[$i], $c))) continue;
                    $left = abs($need - $c);
                    if ($left < $best['left']) $best = ['left' => $left, 'moves' => [[$i, $m]]];
                }
            }
        }

        foreach ($best['moves'] as [$idx, [$newPrice, $newTotal]]) {
            $sumAfter += $newTotal - (float) $out[$idx]['total_after'];
            $out[$idx]['price_after'] = $newPrice;
            $out[$idx]['total_after'] = $newTotal;
            $out[$idx]['adjusted']    = true;      // عشان الواجهة تعلّم عليه
        }
        $sumAfter = round($sumAfter, 2);
    }

    return [
        'rate'     => $rate,
        'items'    => $out,
        // الفرق المتبقي بيتقارن بالصافي بس لما مفيش نسب صنف. لو فيه
        // نسب، الصافي مابيوصفش الفاتورة المخلوطة فمفيش فرق نبلّغ عنه.
        'residual'  => $hasLocked ? 0.0 : round($net - $sumAfter, 2),
        'has_locked'=> $hasLocked,   // للواجهة: تخفي رسالة مطابقة الصافي
        'totals' => [
            'qty'    => $sumQty,
            'before' => $sumBefore,
            'after'  => round($sumAfter, 2),
            'diff'   => round($sumAfter - $sumBefore, 2),
            'header_total' => $total,
            'header_net'   => $net,
        ],
    ];
}

// =====================================================================
//  أرصدة الأصناف في مخزن الإدارة (قراءة فقط)
//  المصدر: COM_STO_ITEMS.CSI_CURRENT_BALANCE — العمود الوحيد اللي
//  بيتحرّك فعليًا في البيع (اتأكدنا من ده في شغل POS).
// =====================================================================

/**
 * مخزن الإدارة في COM_STO_ITEMS.
 * الجدول فيه صف لكل مخزن (STO_ID) لكل صنف، فلازم نفلتر—
 * من غير الفلتر بنجيب رصيد مخزن عشوائي.
 */
const ADMIN_STO_ID = 1;

/**
 * رصيد الإدارة لصنف واحد بالباركود — للاستعمال **كسقف للتوزيع**.
 *
 * ليه موجودة: الفاتورة ممكن تبقى ١٢ قلم والواصل فعلاً ١٠ (والباقي
 * اتعمله مردود)، فرصيد الإدارة بقى ١٠. توزيع ١٢ بيطلّع بضاعة مش
 * موجودة، والفرق بيظهر بعدين كعجز على الفرع.
 *
 * 🔴 بترجّع `null` معناها **مفيش سقف** — مش صفر. حالتين:
 *      • النسخة المحلية لسه فاضية (وكيل الإدارة ما اشتغلش)
 *      • الباركود مش موجود في `hq_codes`
 *    الفرق ده مش تفصيلة: لو رجّعنا صفر في الحالتين دول، التوزيع
 *    بيتقفل على **كل** الأصناف المش معروفة، والموقع كله يقف لـ٤٩
 *    موظف في ٥ فروع أول ما الوكيل يتأخر ساعة.
 *
 * 🔴 ودي **لصنف واحد وقت الحفظ بس**. متنادهاش في حلقة رندر —
 *    الفاتورة فيها مئات الأصناف، والأرصدة بتتجاب مجمّعة بـ
 *    `sqlsrv_fetch_item_balances_all()` قبل الحلقة.
 */
function hq_admin_balance_for_code(string $itemCode): ?float
{
    $code = trim($itemCode);
    if ($code === '' || !hq_balances_ready()) return null;

    try {
        $st = db()->prepare(
            'SELECT b.balance
               FROM hq_codes c
               JOIN ' . bal_table() . ' b
                 ON b.item_id = c.item_id AND b.sto_id = ' . ADMIN_STO_ID . '
              WHERE c.code = ? LIMIT 1'
        );
        $st->execute([$code]);
        $row = $st->fetch();
        if ($row === false) return null;      // الباركود مش معروف = مفيش سقف
        return (float) $row['balance'];
    } catch (Throwable $ex) {
        return null;                          // أي مشكلة = مفيش سقف، مش قفل
    }
}

/**
 * جلب أرصدة مجموعة أصناف من مخزن الإدارة.
 * بترجّع [item_id => balance].
 *
 * @param array $itemIds أرقام الأصناف (ITEM_ID)
 */
function sqlsrv_fetch_item_balances(array $itemIds): array
{
    $ids = array_values(array_unique(array_filter(
        array_map(fn($i) => (int) $i, $itemIds),
        fn($i) => $i > 0
    )));
    if (!$ids) return [];

    // ١) النسخة المحلية الأول (hq_balances) — مخزن الإدارة
    try {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $st = db()->prepare(
                "SELECT item_id, balance FROM " . bal_table() . "
                 WHERE sto_id = " . ADMIN_STO_ID . " AND item_id IN ($ph)"
            );
            $st->execute($chunk);
            foreach ($st->fetchAll() as $r) {
                $out[(int) $r['item_id']] = (float) $r['balance'];
            }
        }
        if (hq_balances_ready()) return $out;
    } catch (Throwable $ex) {
        // نكمّل على الجسر
    }

    // ٢) الجسر
    $out = [];
    foreach (array_chunk($ids, 300) as $chunk) {
        $ph  = implode(',', array_fill(0, count($chunk), '?'));
        $sql = "SELECT ITEM_ID AS item_id, CSI_CURRENT_BALANCE AS balance
                FROM " . SQLSRV_PREFIX . "COM_STO_ITEMS
                WHERE STO_ID = " . ADMIN_STO_ID . " AND ITEM_ID IN ($ph)";
        try {
            foreach (sqlsrv_query($sql, $chunk) as $r) {
                $out[(int) $r['item_id']] = (float) ($r['balance'] ?? 0);
            }
        } catch (Throwable $ex) {
            error_log('receiving: فشل جلب أرصدة الأصناف — ' . $ex->getMessage());
            $GLOBALS['recv_errors'][] = 'أرصدة الأصناف: ' . $ex->getMessage();
        }
    }
    return $out;
}

/**
 * أسماء المخازن من SQL Server. بترجّع [sto_id => name].
 * بتتكاش في الذاكرة عشان ما نضربش السيرفر أكتر من مرة في نفس الطلب.
 */
function sqlsrv_store_names(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache = [];

    // النسخة المحلية الأول (hq_stores) — تشتغل من غير نت
    try {
        $rows = db()->query('SELECT sto_id, name FROM hq_stores')->fetchAll();
        foreach ($rows as $r) {
            $nm = trim((string) ($r['name'] ?? ''));
            $cache[(int) $r['sto_id']] = $nm !== '' ? $nm : ('مخزن ' . $r['sto_id']);
        }
    } catch (Throwable $ex) {
        // الجدول لسه ما اتعملش — نكمّل على الجسر
    }
    if ($cache) return $cache;

    // احتياطي: الجسر (لو النسخة المحلية فاضية)
    try {
        $rows = sqlsrv_query(
            "SELECT STO_ID AS id, LTRIM(RTRIM(STO_NAME1)) AS name
             FROM " . SQLSRV_PREFIX . "STORES
             WHERE STO_ID > 0 ORDER BY STO_ID"
        );
        foreach ($rows as $r) {
            $nm = sqlsrv_fix_encoding((string) ($r['name'] ?? ''));
            $cache[(int) $r['id']] = $nm !== '' ? $nm : ('مخزن ' . $r['id']);
        }
    } catch (Throwable $ex) {
        error_log('receiving: فشل جلب أسماء المخازن — ' . $ex->getMessage());
    }
    return $cache;
}

/**
 * أرصدة أصناف في كل المخازن (للعرض التفصيلي).
 * بترجّع [item_id => [sto_id => balance]].
 */
function sqlsrv_fetch_item_balances_all(array $itemIds): array
{
    $ids = array_values(array_unique(array_filter(
        array_map(fn($i) => (int) $i, $itemIds),
        fn($i) => $i > 0
    )));
    if (!$ids) return [];

    // ١) النسخة المحلية الأول (hq_balances) — كل المخازن
    try {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $st = db()->prepare(
                "SELECT item_id, sto_id, balance FROM " . bal_table() . "
                 WHERE item_id IN ($ph) ORDER BY item_id, sto_id"
            );
            $st->execute($chunk);
            foreach ($st->fetchAll() as $r) {
                $out[(int) $r['item_id']][(int) $r['sto_id']] = (float) $r['balance'];
            }
        }
        if (hq_balances_ready()) return $out;
    } catch (Throwable $ex) {
        // نكمّل على الجسر
    }

    // ٢) الجسر
    $out = [];
    foreach (array_chunk($ids, 300) as $chunk) {
        $ph  = implode(',', array_fill(0, count($chunk), '?'));
        $sql = "SELECT ITEM_ID AS item_id, STO_ID AS sto_id, CSI_CURRENT_BALANCE AS balance
                FROM " . SQLSRV_PREFIX . "COM_STO_ITEMS
                WHERE ITEM_ID IN ($ph)
                ORDER BY ITEM_ID, STO_ID";
        try {
            foreach (sqlsrv_query($sql, $chunk) as $r) {
                $out[(int) $r['item_id']][(int) $r['sto_id']] = (float) ($r['balance'] ?? 0);
            }
        } catch (Throwable $ex) {
            error_log('receiving: فشل جلب أرصدة المخازن — ' . $ex->getMessage());
            $GLOBALS['recv_errors'][] = 'أرصدة المخازن: ' . $ex->getMessage();
        }
    }
    return $out;
}

/**
 * أرصدة فرع اشراقة — من MySQL المحلي مش من SQL Server.
 * =====================================================================
 *  اشراقة نظامها مختلف (Redux / ReduxSalesPUB2) على جهاز في مكان تاني.
 *  جهازهم بيبعت الأرصدة لـ sync_ashraqa.php كل نص ساعة، وإحنا بنقرا
 *  من جدول ashraqa_balances.
 *
 *  ⚠️ الربط بالباركود مش بالـ item_id — أرقام الأصناف عندهم مختلفة
 *     تماماً عن أرقامنا.
 *
 *  ⚠️ الصنف الواحد عندهم له أكتر من باركود بنفس الرصيد. إحنا بنربط
 *     بباركود الصنف اللي في فاتورتنا، فبناخد صف واحد بس — مفيش
 *     خطر تضاعف.
 *
 * @param  array $barcodes باركودات أصناف الفاتورة (item_code)
 * @return array [barcode => ['balance' => float, 'updated_at' => string]]
 */
function ashraqa_fetch_balances(array $barcodes): array
{
    $codes = array_values(array_unique(array_filter(
        array_map(fn($b) => trim((string) $b), $barcodes),
        fn($b) => $b !== ''
    )));
    if (!$codes) return [];

    $out = [];
    try {
        $pdo = db();
        foreach (array_chunk($codes, 500) as $chunk) {
            $ph  = implode(',', array_fill(0, count($chunk), '?'));
            $st  = $pdo->prepare(
                "SELECT barcode, balance, updated_at
                 FROM ashraqa_balances WHERE barcode IN ($ph)"
            );
            $st->execute($chunk);
            foreach ($st->fetchAll() as $r) {
                $out[(string) $r['barcode']] = [
                    'balance'    => (float) $r['balance'],
                    'updated_at' => (string) $r['updated_at'],
                ];
            }
        }
    } catch (Throwable $ex) {
        // الجدول ممكن ما يكونش اتعمل لسه — منرميش خطأ، بنرجّع فاضي
        error_log('ashraqa: فشل جلب الأرصدة — ' . $ex->getMessage());
    }
    return $out;
}

/**
 * آخر مرة وصلت فيها أرصدة اشراقة (للعرض في الواجهة).
 * بترجّع null لو مفيش مزامنة لسه.
 */
function ashraqa_last_sync(): ?string
{
    try {
        $v = db()->query('SELECT MAX(updated_at) FROM ashraqa_balances')->fetchColumn();
        return $v ?: null;
    } catch (Throwable $ex) {
        return null;
    }
}

/**
 * آخر مزامنة ناجحة من جهاز الإدارة (للعرض في الواجهة).
 * $kind فاضية = أي نوع. بترجّع null لو مفيش مزامنة لسه.
 */
function hq_last_sync(?string $kind = null): ?string
{
    try {
        if ($kind !== null && $kind !== '') {
            $st = db()->prepare(
                "SELECT MAX(synced_at) FROM hq_sync_log WHERE kind = ? AND status = 'ok'"
            );
            $st->execute([$kind]);
            $v = $st->fetchColumn();
        } else {
            $v = db()->query(
                "SELECT MAX(synced_at) FROM hq_sync_log WHERE status = 'ok'"
            )->fetchColumn();
        }
        return $v ?: null;
    } catch (Throwable $ex) {
        return null;
    }
}

/**
 * حفظ ملاحظات المستخدم على الفاتورة.
 * بتتخزن في user_notes — منفصلة عن notes اللي النظام بيكتب فيه
 * "الصافي: ..." تلقائياً وقت إنشاء سجل التوزيع.
 */
function save_invoice_notes(int $distributionId, ?string $notes, ?int $userId = null): void
{
    $txt = trim((string) $notes);
    if ($txt === '') $txt = null;
    if ($txt !== null && mb_strlen($txt) > 2000) {
        $txt = mb_substr($txt, 0, 2000);
    }

    db()->prepare(
        'UPDATE invoice_distribution
         SET user_notes = ?, notes_at = NOW(), notes_by = ?
         WHERE id = ?'
    )->execute([$txt, $userId, $distributionId]);
}

/**
 * تغيير حالة الفاتورة يدوي.
 *
 * بتقفل الحالة (status_manual = 1) فالحساب التلقائي ما يدوسش عليها.
 * لو $auto = true بترجّعها للوضع التلقائي وتعيد الحساب.
 *
 * @param string $status new | split | done
 */
function set_invoice_status_manual(int $distributionId, string $status, ?int $userId = null): bool
{
    $allowed = ['new', 'split', 'done'];
    if (!in_array($status, $allowed, true)) return false;

    db()->prepare(
        'UPDATE invoice_distribution
         SET status = ?, status_manual = 1, status_at = NOW(), status_by = ?
         WHERE id = ?'
    )->execute([$status, $userId, $distributionId]);

    return true;
}

/**
 * إرجاع الفاتورة للحالة التلقائية.
 * بتفك القفل بس — الحساب نفسه بيحصل في refresh_invoice_status بعدها.
 */
function clear_invoice_status_manual(int $distributionId): void
{
    db()->prepare(
        'UPDATE invoice_distribution SET status_manual = 0 WHERE id = ?'
    )->execute([$distributionId]);
}

// =====================================================================
//  سحب أعداد الفروع من الحجوزات المفتوحة
// =====================================================================

/**
 * معاينة سحب أعداد الفروع من الحجوزات المفتوحة لأصناف الفاتورة.
 * التوزيع الشره بالأقدم (الأقدم الأول — والفرع مالوش أي دور في الترتيب).
 *
 * @param int   $distributionId رقم سجل التوزيع (invoice_distribution.id)
 * @param array $items          أصناف الفاتورة (بعد merge_invoice_items)
 * @return array ['items' => array, 'total_candidates' => int]
 */
function reservation_pull_preview(int $distributionId, array $items): array
{
    $items = merge_invoice_items($items);
    if (!$items || $distributionId <= 0) {
        return ['items' => [], 'total_candidates' => 0];
    }

    $pdo = db();

    // استخراج أرقام الأصناف الصالحة (> 0) وربطها ببنود الفاتورة
    $itemIds = [];
    $itemsByItemId = [];
    foreach ($items as $it) {
        $iid = (int) ($it['item_id'] ?? 0);
        if ($iid > 0) {
            $itemIds[$iid] = $iid;
            $itemsByItemId[$iid] = $it;
        }
    }

    if (!$itemIds) {
        return ['items' => [], 'total_candidates' => 0];
    }

    // تخصيصات الفاتورة دي السابقة (لو الزرار اتشغّل قبل كده)
    $prevAllocByLine = [];
    $prevAllocByCode = [];
    try {
        $stPrev = $pdo->prepare(
            'SELECT res_line_id, item_code, qty
             FROM reservation_allocations
             WHERE distribution_id = ?'
        );
        $stPrev->execute([$distributionId]);
        foreach ($stPrev->fetchAll() as $r) {
            $lid = (int) $r['res_line_id'];
            $q   = (float) $r['qty'];
            $c   = (string) $r['item_code'];
            $prevAllocByLine[$lid] = $q;
            $prevAllocByCode[$c]   = ($prevAllocByCode[$c] ?? 0.0) + $q;
        }
    } catch (Throwable $ex) {
        // الجدول ممكن يكون لسه ما اتعملش
    }

    // إجمالي الموزّع حالياً لكل كود صنف في الفاتورة
    $currentDistByCode = [];
    try {
        $stDist = $pdo->prepare(
            'SELECT notes AS item_code, COALESCE(SUM(amount), 0) AS total
             FROM invoice_distribution_lines
             WHERE distribution_id = ?
             GROUP BY notes'
        );
        $stDist->execute([$distributionId]);
        foreach ($stDist->fetchAll() as $r) {
            $currentDistByCode[(string) $r['item_code']] = (float) $r['total'];
        }
    } catch (Throwable $ex) {
        // تجاهل
    }

    // ١) الحجوزات المرشحة — استعلام واحد لكل أصناف الفاتورة
    // الترتيب بالأقدم أولاً (created_at ASC, l.id ASC) والفرع مالوش أي دور
    $idList = array_values($itemIds);
    $ph = implode(',', array_fill(0, count($idList), '?'));
    $sql = "SELECT l.id AS res_line_id, l.reservation_id, l.item_id, l.code,
                   l.item_name, l.qty, l.allocated_qty,
                   r.branch_id, r.customer_name, r.created_at,
                   b.name AS branch_name
            FROM book_reservation_lines l
            JOIN book_reservations r ON r.id = l.reservation_id
            LEFT JOIN branches b ON b.id = r.branch_id
            WHERE r.status = 'open'
              AND (l.qty > l.allocated_qty OR l.id IN (
                  SELECT res_line_id FROM reservation_allocations WHERE distribution_id = ?
              ))
              AND l.item_id IN ($ph)
            ORDER BY r.created_at ASC, l.id ASC";

    $args = array_merge([$distributionId], $idList);
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $candidates = $st->fetchAll();

    if (!$candidates) {
        return ['items' => [], 'total_candidates' => 0];
    }

    // تجميع المرشحين حسب item_id
    $candidatesByItem = [];
    foreach ($candidates as $c) {
        $candidatesByItem[(int) $c['item_id']][] = $c;
    }

    $resultItems = [];
    $totalCandidatesCount = 0;

    foreach ($itemIds as $iid) {
        if (empty($candidatesByItem[$iid])) {
            continue; // صنف مالوش أي حجز ← مايظهرش خالص
        }

        $it = $itemsByItemId[$iid];
        $code = (string) ($it['item_code'] ?? '');
        $received = (float) ($it['quantity'] ?? 0);
        $currDist = $currentDistByCode[$code] ?? 0.0;
        $prevAlloc = $prevAllocByCode[$code] ?? 0.0;

        // المتاح = المستلم − الموزّع حالياً + تخصيصنا السابق
        $available = $received - $currDist + $prevAlloc;
        if ($available < 0) $available = 0.0;

        $availableForItem = $available;
        $lines = [];
        $totalReservedForItem = 0.0;

        foreach ($candidatesByItem[$iid] as $c) {
            $totalCandidatesCount++;
            $lid = (int) $c['res_line_id'];
            $lQty = (float) $c['qty'];
            $lAlloc = (float) $c['allocated_qty'];
            $pAlloc = $prevAllocByLine[$lid] ?? 0.0;

            // المتبقي في السطر (مع إعادة تخصيصنا السابق له)
            $remainLine = $lQty - ($lAlloc - $pAlloc);
            if ($remainLine < 0) $remainLine = 0.0;

            $totalReservedForItem += $remainLine;

            // التوزيع الشره بالأقدم
            $give = min($remainLine, $availableForItem);
            if ($give <= 0) $give = 0.0;
            $availableForItem -= $give;

            $lines[] = [
                'res_line_id'    => $lid,
                'reservation_id' => (int) $c['reservation_id'],
                'item_id'        => (int) $c['item_id'],
                'code'           => (string) $c['code'],
                'item_name'      => (string) $c['item_name'],
                'customer_name'  => (string) ($c['customer_name'] ?? '—'),
                'created_at'     => (string) ($c['created_at'] ?? ''),
                'branch_id'      => $c['branch_id'] !== null ? (int) $c['branch_id'] : null,
                'branch_name'    => (string) ($c['branch_name'] ?? 'بدون فرع'),
                'qty'            => $lQty,
                'allocated_qty'  => $lAlloc,
                'remaining_qty'  => $remainLine,
                'suggested_qty'  => $give,
            ];
        }

        $resultItems[] = [
            'item_id'        => $iid,
            'item_code'      => $code,
            'item_name'      => (string) ($it['item_name'] ?? ''),
            'received_qty'   => $received,
            'total_reserved' => $totalReservedForItem,
            'available_qty'  => $available,
            'lines'          => $lines,
        ];
    }

    return [
        'items'            => $resultItems,
        'total_candidates' => $totalCandidatesCount,
    ];
}

/**
 * تطبيق توزيع الحجوزات على الفاتورة وتحديث الأرصدة وتوزيع الفروع.
 *
 * @param int    $distributionId رقم سجل التوزيع (invoice_distribution.id)
 * @param string $invoiceNo      رقم الفاتورة الظاهر
 * @param array  $itemQty        كميات الأصناف المستلمة [item_code => received_qty]
 * @param array  $alloc          التخصيصات المعتمدة [res_line_id => qty]
 * @param int    $userId         رقم المستخدم الحالي
 * @return array ['ok' => bool, 'allocated_count' => int]
 */
function reservation_pull_apply(
    int $distributionId,
    string $invoiceNo,
    array $itemQty,
    array $alloc,
    int $userId,
    array $codeByItemId = []
): array {
    if ($distributionId <= 0) {
        throw new InvalidArgumentException('رقم سجل التوزيع غير صالح.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // تنظيف المدخلات والتحقق من الكميات غير السالبة
        $cleanAlloc = [];
        foreach ($alloc as $lid => $q) {
            $lineId = (int) $lid;
            $qty    = (float) $q;
            if ($qty < 0) {
                throw new InvalidArgumentException('الكمية لا يمكن أن تكون سالبة.');
            }
            if ($lineId > 0) {
                $cleanAlloc[$lineId] = $qty;
            }
        }

        // جلب التخصيصات السابقة لهذه الفاتورة
        $prevAllocByLine = [];
        $prevAllocByCode = [];
        $prevAllocByCodeBranch = [];
        $stPrev = $pdo->prepare(
            'SELECT res_line_id, item_code, branch_id, qty
             FROM reservation_allocations
             WHERE distribution_id = ?'
        );
        $stPrev->execute([$distributionId]);
        foreach ($stPrev->fetchAll() as $r) {
            $lid = (int) $r['res_line_id'];
            $q   = (float) $r['qty'];
            $c   = (string) $r['item_code'];
            $bid = $r['branch_id'] !== null ? (int) $r['branch_id'] : null;
            $prevAllocByLine[$lid] = $r;
            $prevAllocByCode[$c]   = ($prevAllocByCode[$c] ?? 0.0) + $q;
            if ($bid !== null && $bid > 0) {
                $prevAllocByCodeBranch[$c][$bid] = ($prevAllocByCodeBranch[$c][$bid] ?? 0.0) + $q;
            }
        }

        // كل أرقام السطور المتأثرة (الجديدة أو السابقة)
        $allLineIds = array_values(array_unique(array_merge(
            array_keys($cleanAlloc),
            array_keys($prevAllocByLine)
        )));

        if (!$allLineIds) {
            $pdo->commit();
            return ['ok' => true, 'allocated_count' => 0];
        }

        // قفل سطور الحجز للتحقق من الداتابيز مباشرة (FOR UPDATE)
        $ph = implode(',', array_fill(0, count($allLineIds), '?'));
        $sqlLock = "SELECT l.id AS res_line_id, l.reservation_id, l.item_id, l.code,
                           l.item_name, l.qty, l.allocated_qty,
                           r.branch_id, r.status AS res_status
                    FROM book_reservation_lines l
                    JOIN book_reservations r ON r.id = l.reservation_id
                    WHERE l.id IN ($ph)
                    FOR UPDATE";
        $stLock = $pdo->prepare($sqlLock);
        $stLock->execute($allLineIds);
        $dbLines = [];
        foreach ($stLock->fetchAll() as $row) {
            $dbLines[(int) $row['res_line_id']] = $row;
        }

        // إجمالي الموزّع حالياً في invoice_distribution_lines لكل كود
        $currentDistByCode = [];
        $currentDistByCodeBranch = [];
        $stDist = $pdo->prepare(
            'SELECT notes AS item_code, branch_id, SUM(amount) AS total
             FROM invoice_distribution_lines
             WHERE distribution_id = ?
             GROUP BY notes, branch_id'
        );
        $stDist->execute([$distributionId]);
        foreach ($stDist->fetchAll() as $r) {
            $c   = (string) $r['item_code'];
            $bid = (int) $r['branch_id'];
            $tot = (float) $r['total'];
            $currentDistByCode[$c] = ($currentDistByCode[$c] ?? 0.0) + $tot;
            $currentDistByCodeBranch[$c][$bid] = $tot;
        }

        // التحقق لكل سطر ومجموع كل صنف
        $reqByCode = [];
        $newAllocByCodeBranch = [];
        $touchedCodes = [];

        foreach ($cleanAlloc as $lineId => $requestedQty) {
            if (!isset($dbLines[$lineId])) {
                throw new RuntimeException('سطر الحجز غير موجود.');
            }
            $row = $dbLines[$lineId];
            // 🔴 الكود بتاع الفاتورة مش بتاع الحجز. الصنف ليه أكتر من كود
            // (أساسي + فرعي) بنفس الـitem_id، والمطابقة بتتم بالـitem_id.
            // لو أخدنا كود الحجز، $itemQty[$code] بترجع صفر والاعتماد بيترفض.
            $code = (string) ($codeByItemId[(int) $row['item_id']] ?? $row['code']);
            $bid  = $row['branch_id'] !== null ? (int) $row['branch_id'] : null;

            // التحقق ١: سطر حجزه مقفول/ملغي
            if ($requestedQty > 0.0001 && ($row['res_status'] ?? '') !== 'open') {
                throw new RuntimeException('الحجز اتقفل — اعمل ريفريش.');
            }

            // التحقق ٢: أكبر من المتبقي في الحجز
            $prevHere = (float) ($prevAllocByLine[$lineId]['qty'] ?? 0.0);
            $maxAllowedForLine = (float) $row['qty'] - (float) $row['allocated_qty'] + $prevHere;
            if ($requestedQty > $maxAllowedForLine + 0.0001) {
                throw new RuntimeException('أكبر من المتبقي في الحجز.');
            }

            $reqByCode[$code] = ($reqByCode[$code] ?? 0.0) + $requestedQty;
            $touchedCodes[$code] = true;
            if ($bid !== null && $bid > 0 && $requestedQty > 0) {
                $newAllocByCodeBranch[$code][$bid] = ($newAllocByCodeBranch[$code][$bid] ?? 0.0) + $requestedQty;
            }
        }

        // إضافة الأكواد التي كانت مخصصة سابقاً حتى لو تم تصفيرها
        foreach (array_keys($prevAllocByCode) as $c) {
            $touchedCodes[$c] = true;
        }

        // التحقق ٣: إجمالي الموزّع لكل صنف أكبر من الكمية المستلمة
        foreach ($touchedCodes as $code => $_) {
            $receivedQty = (float) ($itemQty[$code] ?? 0.0);
            $currDist    = (float) ($currentDistByCode[$code] ?? 0.0);
            $prevAlloc   = (float) ($prevAllocByCode[$code] ?? 0.0);
            $available   = $receivedQty - $currDist + $prevAlloc;

            $totalRequestedForCode = (float) ($reqByCode[$code] ?? 0.0);
            if ($totalRequestedForCode > $available + 0.0001) {
                throw new RuntimeException('إجمالي الموزّع أكبر من الكمية المستلمة.');
            }
        }

        // كتابة / تحديث reservation_allocations
        $insAlloc = $pdo->prepare(
            'INSERT INTO reservation_allocations
                (distribution_id, invoice_no, item_id, item_code, reservation_id, res_line_id, branch_id, qty, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                invoice_no = VALUES(invoice_no),
                item_id    = VALUES(item_id),
                branch_id  = VALUES(branch_id),
                qty        = VALUES(qty),
                created_by = VALUES(created_by),
                updated_at = CURRENT_TIMESTAMP'
        );

        $delAlloc = $pdo->prepare(
            'DELETE FROM reservation_allocations
             WHERE distribution_id = ? AND res_line_id = ?'
        );

        foreach ($allLineIds as $lineId) {
            $q = (float) ($cleanAlloc[$lineId] ?? 0.0);
            if ($q > 0.0001) {
                $row = $dbLines[$lineId];
                // نفس القاعدة: بنخزّن كود الفاتورة عشان prevAllocByCode
                // يطابق invoice_distribution_lines في التشغيلة الجاية
                $insAlloc->execute([
                    $distributionId,
                    $invoiceNo,
                    (int) $row['item_id'],
                    (string) ($codeByItemId[(int) $row['item_id']] ?? $row['code']),
                    (int) $row['reservation_id'],
                    $lineId,
                    $row['branch_id'] !== null ? (int) $row['branch_id'] : null,
                    $q,
                    $userId,
                ]);
            } else {
                $delAlloc->execute([$distributionId, $lineId]);
            }
        }

        // إعادة حساب allocated_qty لكل سطر من reservation_allocations (SUM مش +)
        $sqlUpdAlloc = "UPDATE book_reservation_lines l
                        SET l.allocated_qty = (
                            SELECT COALESCE(SUM(a.qty), 0)
                            FROM reservation_allocations a
                            WHERE a.res_line_id = l.id
                        )
                        WHERE l.id IN ($ph)";
        $stUpdAlloc = $pdo->prepare($sqlUpdAlloc);
        $stUpdAlloc->execute($allLineIds);

        // كتابة توزيع الفروع لكل صنف وفرع عبر set_branch_distribution
        // الإجمالي الجديد للفرع = الموجود حالياً − تخصيصنا السابق لنفس الفرع + تخصيصنا الجديد
        foreach (array_keys($touchedCodes) as $code) {
            $receivedQty = (float) ($itemQty[$code] ?? 0.0);

            // جمع كل الفروع التي لها توزيع حالي أو تخصيص سابق أو جديد
            $branchesForCode = [];
            if (!empty($currentDistByCodeBranch[$code])) {
                foreach (array_keys($currentDistByCodeBranch[$code]) as $bid) $branchesForCode[$bid] = true;
            }
            if (!empty($prevAllocByCodeBranch[$code])) {
                foreach (array_keys($prevAllocByCodeBranch[$code]) as $bid) $branchesForCode[$bid] = true;
            }
            if (!empty($newAllocByCodeBranch[$code])) {
                foreach (array_keys($newAllocByCodeBranch[$code]) as $bid) $branchesForCode[$bid] = true;
            }

            foreach (array_keys($branchesForCode) as $branchId) {
                $branchId = (int) $branchId;
                if ($branchId <= 0) continue; // حجز branch_id = NULL يتخطّى في التوزيع

                $existingQty = (float) ($currentDistByCodeBranch[$code][$branchId] ?? 0.0);
                $prevAllocQ  = (float) ($prevAllocByCodeBranch[$code][$branchId] ?? 0.0);
                $newAllocQ   = (float) ($newAllocByCodeBranch[$code][$branchId] ?? 0.0);

                $newTotal = $existingQty - $prevAllocQ + $newAllocQ;
                if ($newTotal < 0) $newTotal = 0.0;

                set_branch_distribution(
                    $distributionId,
                    $code,
                    $receivedQty,
                    $branchId,
                    $newTotal,
                    $userId
                );
            }
        }

        // تحديث حالة الفاتورة مرة واحدة في الآخر
        $recalcItems = [];
        foreach ($itemQty as $c => $q) {
            $recalcItems[] = ['item_code' => $c, 'quantity' => $q];
        }
        recalc_invoice_status($distributionId, $recalcItems);

        $pdo->commit();
        return ['ok' => true, 'allocated_count' => count($cleanAlloc)];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

