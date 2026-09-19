<?php
/**
 * group_export.php — تصدير ملف TEXT مجمّع لفرع من كل فواتير المجموعة
 * نظام الفجالة ستور المالي
 *
 * =====================================================================
 *  نفس صيغة branch_export.php بالحرف:
 *      كود الصنف <TAB> الكمية <TAB> سعر التكلفة
 *
 *  الفرق: بيجمع كميات الفرع من **كل فواتير المجموعة** في ملف واحد.
 *  الصنف اللي اتوزّع من أكتر من فاتورة بيتجمع في سطر واحد — لأن
 *  الفرع بيستلم الصنف مرة واحدة مش مرة لكل فاتورة.
 *
 *  بيسجّل التصدير على كل فاتورة (mark_branch_exported) وبيحدّث
 *  حالتها — بالظبط زي ما لو نزّلت ملف كل فاتورة لوحدها.
 *
 *  المدخلات: ?group=GROUP_ID&branch=BRANCH_ID
 * =====================================================================
 */
require_once __DIR__ . '/auth.php';
require_permission('invoices');

// الملف بياخد وقت على الفواتير الكبيرة، والجلسة بتفضل مقفولة طول ما هو شغال
// فأي حفظ AJAX للمستخدم نفسه بيستنى في الطابور. الجلسة اتقريت خلاص، فنقفلها.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

require_once __DIR__ . '/group_lib.php';
require_once __DIR__ . '/item_flags_lib.php';   // الأصناف الممنوعة من ملف الفرع

$groupId  = (int) ($_GET['group'] ?? 0);
$branchId = (int) ($_GET['branch'] ?? 0);

if ($groupId <= 0 || $branchId <= 0) {
    http_response_code(400);
    die('بيانات غير مكتملة.');
}

$pdo = db();

$invoices = group_invoices($groupId);
if (!$invoices) {
    http_response_code(404);
    die('المجموعة غير موجودة أو فاضية.');
}

// اسم الفرع (لاسم الملف)
$bStmt = $pdo->prepare('SELECT name FROM branches WHERE id = ?');
$bStmt->execute([$branchId]);
$branchName = $bStmt->fetchColumn() ?: ('branch' . $branchId);

// تجميع الكميات والتكاليف عبر كل فواتير المجموعة
$qtyByCode  = [];
$costByCode = [];
$distIds    = [];

// ⚠️ مجموعة الأصناف: سجل التوزيع بتاع الفاتورة فيه كمان أصناف
//    اتوزّعت من شاشة الفاتورة المفردة ومش جوه المجموعة. لو ماعملناش
//    الفلتر ده الملف هيطلع بأصناف الفاتورة كلها بدل أصناف المجموعة.
$grpIsItems = group_is_items($groupId);

$q = $pdo->prepare(
    'SELECT notes AS item_code, SUM(amount) AS qty
     FROM invoice_distribution_lines
     WHERE distribution_id = ? AND branch_id = ?
     GROUP BY notes HAVING SUM(amount) > 0'
);

foreach ($invoices as $inv) {
    $sth    = (int) $inv['sth_id'];
    $suffix = (string) $inv['suffix'];

    $header = sqlsrv_fetch_invoice_header($sth, $suffix ?: null);
    if (!$header) continue;                 // فاتورة اتحفظت/اتلغت — نكمّل بالباقي

    $dist   = ensure_distribution_record($header);
    $distId = (int) $dist['id'];
    $distIds[$sth] = $distId;

    // الأصناف المسموحة من الفاتورة دي (فاضي = كلها)
    $allow = $grpIsItems ? group_invoice_codes($groupId, $sth, $suffix) : [];

    // كميات الفرع في الفاتورة دي
    $q->execute([$distId, $branchId]);
    foreach ($q as $row) {
        $code = (string) $row['item_code'];
        if ($allow && empty($allow[$code])) continue;
        $qtyByCode[$code] = ($qtyByCode[$code] ?? 0) + (float) $row['qty'];
    }

    // أسعار التكلفة (أول سعر بنلاقيه للصنف)
    foreach (sqlsrv_fetch_invoice_items($sth, $suffix ?: null) as $it) {
        $code = (string) $it['item_code'];
        if ($allow && empty($allow[$code])) continue;
        if (!isset($costByCode[$code])) {
            $costByCode[$code] = (float) ($it['cost_price'] ?? 0);
        }
    }
}

if (empty($qtyByCode)) {
    http_response_code(404);
    die('لا توجد كميات موزّعة لهذا الفرع في المجموعة.');
}

// ⚠️ (١) الأصناف اليتيمة: كود اتوزّع وبعدين اتشال من فاتورة الشركة.
//    كان بينزل في الملف بسعر تكلفة صفر. الفاتورة هي المرجع.
if ($costByCode) {
    foreach (array_keys($qtyByCode) as $c) {
        if (!isset($costByCode[(string) $c])) unset($qtyByCode[$c]);
    }
}

// ⚠️ (٢) الأصناف المعلّمة «لا ينزل في التقرير» —
//    نفس فلترة branch_export.php بالحرف.
$filtered   = ifl_filter_export($qtyByCode);
$qtyByCode  = $filtered['kept'];

if (empty($qtyByCode)) {
    http_response_code(404);
    die('كل الأصناف الموزّعة للفرع ده معلّمة «لا ينزل في التقرير» — الملف هيطلع فاضي.');
}

// بناء الملف — نفس صيغة branch_export.php بالحرف
$lines = [];
foreach ($qtyByCode as $code => $qty) {
    $cost    = $costByCode[$code] ?? 0;
    $qtyOut  = ($qty == (int) $qty) ? (string) (int) $qty
             : rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
    $costOut = rtrim(rtrim(number_format($cost, 3, '.', ''), '0'), '.');
    if ($costOut === '') $costOut = '0';
    $lines[] = $code . "\t" . $qtyOut . "\t" . $costOut;
}
$content = implode("\r\n", $lines) . "\r\n";

$safeBranch = preg_replace('/[^\p{Arabic}\w\-]+/u', '_', $branchName);
$filename   = 'dist_group' . $groupId . '_' . $safeBranch . '.txt';

$skipReceiving = !empty($_GET['skip_receiving']);

// تسجيل التصدير على كل فاتورة + تحديث حالتها
foreach ($invoices as $inv) {
    $sth = (int) $inv['sth_id'];
    if (!isset($distIds[$sth])) continue;
    try {
        mark_branch_exported($distIds[$sth], $branchId, current_user()['id'] ?? null);
        refresh_invoice_status($distIds[$sth], $sth, ((string) $inv['suffix']) ?: null, false /* الملف المجمّع = كارت مجموعة واحد. السطر ١٥٦ هو اللي بيفتحها */);
    } catch (Throwable $ex) {
        error_log('group_export: تعذّر تسجيل التصدير للفاتورة ' . $sth . ' — ' . $ex->getMessage());
    }
}

// فتح استلام المجموعة ككل (وليس فواتير مفردة)
if (!$skipReceiving) {
    try {
        require_once __DIR__ . '/receiving_flow_lib.php';
        receiving_open($pdo, $groupId, 0, (int) ($_SESSION['user']['id'] ?? 0));
    } catch (Throwable $ex) {
        error_log('group_export: تعذّر فتح استلام المجموعة ' . $groupId . ' — ' . $ex->getMessage());
    }
}

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($content));
echo $content;
exit;
