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

// 🔴 الفواتير اللي اتبعتت لوحدها خلاص: الفرع رفع ملفها على برنامجه، فلو
//    نزلت تاني في الملف المجمّع الكميات هتتحسب **مرتين في المخزن**.
//    نفس القاعدة بالظبط اللي بتشيلها من كارت المجموعة.
require_once __DIR__ . '/receiving_flow_lib.php';
$soloDistIds = receiving_solo_dist_ids(group_code_dist_ids($groupId, '__any__'));

$pairs = [];
foreach ($invoices as $inv) {
    $pairs[] = ['sth_id' => (int) $inv['sth_id'], 'suffix' => (string) $inv['suffix']];
}
$headersMap = group_fetch_headers($pairs);
$distPre    = group_dist_existing(array_filter($headersMap));

$linePairs = [];
foreach ($invoices as $inv) {
    $k = (int) $inv['sth_id'] . '|' . (string) $inv['suffix'];
    if (!empty($headersMap[$k])) {
        $linePairs[] = ['sth_id' => (int) $inv['sth_id'], 'suffix' => (string) $inv['suffix']];
    }
}
$linesMap = group_fetch_lines($linePairs);

// استخراج سجلات التوزيع لفواتير المجموعة
$distMap = [];
foreach ($invoices as $inv) {
    $sth    = (int) $inv['sth_id'];
    $suffix = (string) $inv['suffix'];
    $key    = $sth . '|' . $suffix;
    $header = $headersMap[$key] ?? null;
    if (!$header) continue;
    $k    = group_dist_key($header);
    $dist = ($k !== null && isset($distPre[$k])) ? $distPre[$k] : ensure_distribution_record($header);
    $distId = (int) $dist['id'];
    if (isset($soloDistIds[$distId])) continue;
    $distMap[$key] = $distId;
}

// استعلام كميات واحد مجمّع لكل الفواتير
$qtyLinesByDist = [];
$allDistIds = array_values(array_unique($distMap));
if (!empty($allDistIds)) {
    $ph = implode(',', array_fill(0, count($allDistIds), '?'));
    $params = array_merge($allDistIds, [$branchId]);
    $stQty = $pdo->prepare(
        "SELECT distribution_id, notes AS item_code, SUM(amount) AS qty
         FROM invoice_distribution_lines
         WHERE distribution_id IN ($ph) AND branch_id = ?
         GROUP BY distribution_id, notes HAVING SUM(amount) > 0"
    );
    $stQty->execute($params);
    while ($r = $stQty->fetch()) {
        $qtyLinesByDist[(int) $r['distribution_id']][] = $r;
    }
}

foreach ($invoices as $inv) {
    $sth    = (int) $inv['sth_id'];
    $suffix = (string) $inv['suffix'];
    $key    = $sth . '|' . $suffix;

    $header = $headersMap[$key] ?? null;
    if (!$header) continue;                 // فاتورة اتحفظت/اتلغت — نكمّل بالباقي

    if (!isset($distMap[$key])) continue;
    $distId = $distMap[$key];

    $distIds[$sth] = $distId;

    // الأصناف المسموحة من الفاتورة دي (فاضي = كلها)
    $allow = $grpIsItems ? group_invoice_codes($groupId, $sth, $suffix) : [];

    // كميات الفرع في الفاتورة دي
    $branchRows = $qtyLinesByDist[$distId] ?? [];
    foreach ($branchRows as $row) {
        $code = (string) $row['item_code'];
        if ($allow && empty($allow[$code])) continue;
        $qtyByCode[$code] = ($qtyByCode[$code] ?? 0) + (float) $row['qty'];
    }

    // أسعار التكلفة (أول سعر بنلاقيه للصنف)
    $items = $linesMap[$key] ?? [];
    foreach ($items as $it) {
        $code = (string) $it['item_code'];
        if ($allow && empty($allow[$code])) continue;
        if (!isset($costByCode[$code])) {
            $costByCode[$code] = (float) ($it['cost_price'] ?? 0);
        }
    }
}

if (empty($qtyByCode)) {
    http_response_code(404);
    die($soloDistIds
        ? 'كل فواتير المجموعة اللي فيها كميات للفرع ده اتبعتت لوحدها خلاص — مفيش حاجة جديدة تنزل.'
        : 'لا توجد كميات موزّعة لهذا الفرع في المجموعة.');
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
    $sth    = (int) $inv['sth_id'];
    $suffix = (string) $inv['suffix'];
    $key    = $sth . '|' . $suffix;
    if (!isset($distIds[$sth])) continue;
    try {
        mark_branch_exported($distIds[$sth], $branchId, current_user()['id'] ?? null);
        try {
            recalc_invoice_status($distIds[$sth], $linesMap[$key] ?? [], false /* الملف المجمّع = كارت مجموعة واحد. السطر ٢١٥ هو اللي بيفتحها */);
        } catch (Throwable $ex) {
            error_log('receiving: تعذّر تحديث حالة الفاتورة — ' . $ex->getMessage());
        }
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
