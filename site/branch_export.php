<?php
/**
 * branch_export.php — تصدير ملف TEXT لتوزيع فرع معيّن
 * نظام الفجالة ستور المالي
 *
 * الفورمات (بدون هيدر، سطر لكل صنف اتوزّع للفرع بكمية > 0):
 *   كود_الصنف <TAB> الكمية_المقسمة <TAB> سعر_التكلفة
 *
 * المدخلات: ?invoice=STH_ID&branch=BRANCH_ID
 */
require_once __DIR__ . '/auth.php';

// عاشور معاه invoices:ashraqa بس — يسحب ملف إشراقة وبس.
// من غير القيد ده كان يقدر يغيّر ?branch ويسحب ملف أي فرع تاني.
if (!can('invoices') && can('invoices:ashraqa')) {
    $ashraqaOnly = true;
} else {
    require_permission('invoices');
    $ashraqaOnly = false;
}
require_once __DIR__ . '/receiving_lib.php';
require_once __DIR__ . '/item_flags_lib.php';   // الأصناف الممنوعة من ملف الفرع
require_once __DIR__ . '/group_lib.php';        // فلتر مجموعة الأصناف

$invoiceId = $_GET['invoice'] ?? null;
$branchId  = (int) ($_GET['branch'] ?? 0);
// ⚠️ الـ suffix ضروري: STH_ID بيتكرر بين جدول الفواتير غير المحفوظة
//    (YY_MM) والجداول الشهرية المرحّلة. من غيره ممكن نجيب هيدر فاتورة
//    تانية خالص → سجل توزيع غلط → ملف فرع بكميات مش بتاعتها.
$suffix    = trim((string) ($_GET['suffix'] ?? '')) ?: null;
// ?group=ID اختياري: بيتبعت من شاشة المجموعة. لو المجموعة «أصناف
// محددة»، ملف الفاتورة بيطلع بالأصناف المختارة بس — مش الفاتورة
// كلها. (قرار محمد 2026-08-14: الاختيار «أ».)
$groupCtx  = (int) ($_GET['group'] ?? 0);

if ($invoiceId === null || $branchId <= 0) {
    http_response_code(400);
    die('بيانات غير مكتملة.');
}

if ($ashraqaOnly && $branchId !== 5) { // branch 403
    http_response_code(403);
    die('غير مصرح.');
}

$pdo = db();

// سجل التوزيع (الهيدر في MySQL) لهذه الفاتورة
$invHeader = sqlsrv_fetch_invoice_header($invoiceId, $suffix);
if (!$invHeader) {
    http_response_code(404);
    die('الفاتورة غير موجودة.');
}
$dist = ensure_distribution_record($invHeader);
$distId = (int) $dist['id'];

if ($ashraqaOnly) {
    // 🔴 المردودات مابتظهرش لعاشور (doc_type 6). الشرط جوّه $ashraqaOnly
    //    بس — باقي الفروع بتصدّر المردودات عادي زي ما هي.
    if ((int) ($invHeader['doc_type'] ?? 0) === 6) {
        http_response_code(403);
        die('غير مصرح.');
    }
    $stCheck = $pdo->prepare(
        'SELECT 1 FROM invoice_distribution_lines
          WHERE distribution_id = ? AND branch_id = 5 AND amount > 0
          LIMIT 1'
    );
    $stCheck->execute([$distId]);
    if (!$stCheck->fetchColumn()) {
        http_response_code(403);
        die('غير مصرح.');
    }
}

// اسم الفرع (لاسم الملف)
$bStmt = $pdo->prepare('SELECT name FROM branches WHERE id = ?');
$bStmt->execute([$branchId]);
$branchName = $bStmt->fetchColumn() ?: ('branch' . $branchId);

// الكميات الموزّعة لهذا الفرع لكل صنف (notes = كود الصنف)
$qStmt = $pdo->prepare(
    'SELECT notes AS item_code, SUM(amount) AS qty
     FROM invoice_distribution_lines
     WHERE distribution_id = ? AND branch_id = ?
     GROUP BY notes
     HAVING SUM(amount) > 0'
);
$qStmt->execute([$distId, $branchId]);

// فلتر مجموعة الأصناف (لو الملف اتطلب من جوه مجموعة)
$grpAllow = [];
if ($groupCtx > 0 && group_is_items($groupCtx)) {
    $grpAllow = group_invoice_codes($groupCtx, (int) $invoiceId, (string) ($suffix ?? ''));
}

$branchQuantities = [];
foreach ($qStmt as $row) {
    $code = (string) $row['item_code'];
    if ($grpAllow && empty($grpAllow[$code])) continue;
    $branchQuantities[$code] = (float) $row['qty'];
}

if (empty($branchQuantities)) {
    http_response_code(404);
    die('لا توجد كميات موزّعة لهذا الفرع.');
}

// ⚠️ الأصناف اليتيمة: كود اتوزّع، وبعدين اتشال أو اتغيّر في فاتورة
//    الشركة. سطر التوزيع بيفضل عندنا فالكود كان بينزل في الملف
//    **بسعر تكلفة صفر**. بنشيله — الفاتورة هي المرجع مش سجل التوزيع.
//    (بلاغ محمد 2026-08-14)
$items = sqlsrv_fetch_invoice_items($invoiceId, $suffix);
$costByCode = [];
foreach ($items as $it) {
    $costByCode[(string) $it['item_code']] = (float) ($it['cost_price'] ?? 0);
}
$orphans = [];
if ($costByCode) {   // لو المرآة رجّعت بنود — من غير كده مانفلترش عشان ما نفضّيش الملف
    foreach (array_keys($branchQuantities) as $c) {
        if (!isset($costByCode[(string) $c])) {
            $orphans[] = (string) $c;
            unset($branchQuantities[$c]);
        }
    }
}
if (empty($branchQuantities)) {
    http_response_code(404);
    die('كل الأصناف الموزّعة للفرع ده مابقتش موجودة في الفاتورة على نظام الشركة'
        . ' (اتشالت أو اتغيّر كودها): ' . implode('، ', $orphans));
}

// ⚠️ الأصناف المعلّمة «لا ينزل في التقرير» بتتشال من الملف هنا.
//    التوزيع نفسه بيفضل محفوظ زي ما هو، والحالة والأسعار والإكسيل
//    ماليهمش دعوة بالفلاج ده — ملف الفرع بس.
$filtered = ifl_filter_export($branchQuantities);
$branchQuantities = $filtered['kept'];
$skippedCodes     = $filtered['skipped'];

if (empty($branchQuantities)) {
    http_response_code(404);
    die('كل الأصناف الموزّعة للفرع ده معلّمة «لا ينزل في التقرير» — الملف هيطلع فاضي.');
}

// بناء محتوى الملف: كود <TAB> كمية <TAB> سعر التكلفة (بدون هيدر)
$lines = [];
foreach ($branchQuantities as $code => $qty) {
    $cost = $costByCode[$code] ?? 0;
    // الكمية: عدد صحيح لو مفيش كسور، وإلا رقم عشري
    $qtyOut  = ($qty == (int)$qty) ? (string)(int)$qty : rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
    $costOut = rtrim(rtrim(number_format($cost, 3, '.', ''), '0'), '.');
    if ($costOut === '') $costOut = '0';
    $lines[] = $code . "\t" . $qtyOut . "\t" . $costOut;
}
$content = implode("\r\n", $lines) . "\r\n";

// اسم الملف: فاتورة + فرع
$safeBranch = preg_replace('/[^\p{Arabic}\w\-]+/u', '_', $branchName);
$filename = 'dist_' . $invHeader['inv_no'] . '_' . $safeBranch . '.txt';

// تسجيل إن الفرع ده اتصدّر (بيحدّد حالة الفاتورة: تم التقسيم / تم التوزيع)
try {
    mark_branch_exported($distId, $branchId, current_user()['id'] ?? null);
    refresh_invoice_status($distId, $invoiceId, $_GET['suffix'] ?? null);
} catch (Throwable $ex) {
    // فشل التسجيل مايمنعش التصدير نفسه
    error_log('branch_export: تعذّر تسجيل التصدير — ' . $ex->getMessage());
}

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($content));
echo $content;
exit;
