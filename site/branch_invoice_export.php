<?php
/**
 * branch_invoice_export.php — تصدير فاتورة فرع (إكسيل .xlsx) — الفجالة · دجلة · بونجور · اي بي سي
 * نسخة من ashraqa_invoice_export.php (بريف ٣٧). المدخلات: ?b=fagala&invoice=…&suffix=…
 * نظام الفجالة ستور المالي
 *
 * ملف إكسيل لأصناف فرع الفرع فقط بدون باقي الفروع وبدون اسم المورد.
 * عمود كود الصنف محدد كنص من الأصل للحفاظ على الأصفار البادئة.
 *
 * المدخلات: ?invoice=STH_ID&suffix=26_07
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/branch_invoices_lib.php';
// 🔴 الفرع جاي من الرابط (?b=) — والصلاحية على **الفرع اللي في الرابط نفسه**،
//    عشان كاشير الفجالة مايغيّرش ?b=degla ويسحب ملف دجلة (نفس ثغرة branch_export.php).
$BR_KEY = (string) ($_GET['b'] ?? '');
$BR_MAP = br_inv_map();
if (!isset($BR_MAP[$BR_KEY])) {
    http_response_code(404);
    die('فرع غير معروف.');
}
require_permission('invoices:' . $BR_KEY);
$BR_INV = $BR_MAP[$BR_KEY];
require_once __DIR__ . '/receiving_lib.php';
require_once __DIR__ . '/xlsx_lib.php';

$invoiceId = $_GET['invoice'] ?? null;
$suffix    = $_GET['suffix'] ?? null;

if ($invoiceId === null || $invoiceId === '') {
    http_response_code(400);
    die('لازم تحدد الفاتورة.');
}

$invHeader = sqlsrv_fetch_invoice_header($invoiceId, $suffix);
if (!$invHeader) {
    http_response_code(404);
    die('الفاتورة غير موجودة.');
}
// 🔴 المردودات مابتظهرش لعاشور (doc_type 6 = مردود شراء). الشاشة بتمنعها،
//    والمنع هنا كمان عشان مايبقاش فيه باب جانبي بلينك متكتب بالإيد.
if ((int) ($invHeader['doc_type'] ?? 0) === 6) {
    http_response_code(403);
    die('غير مصرح.');
}

$pdo = db();
$dist = ensure_distribution_record($invHeader, current_user()['id'] ?? null);
$distId = (int) $dist['id'];

// حارس أمان: التحقق من أن الفاتورة فيها توزيع للفرع
$stQty = $pdo->prepare(
    'SELECT notes AS item_code, SUM(amount) AS qty
       FROM invoice_distribution_lines
      WHERE distribution_id = ? AND branch_id = ?
      GROUP BY notes
      HAVING SUM(amount) > 0'
);
$stQty->execute([$distId, (int) $BR_INV['id']]);
$ashraqaQtys = [];
foreach ($stQty as $row) {
    $ashraqaQtys[(string)$row['item_code']] = (float)$row['qty'];
}

if (empty($ashraqaQtys)) {
    http_response_code(403);
    die('غير مصرح.');
}

// أصناف الفاتورة مدمجة
$items = merge_invoice_items(sqlsrv_fetch_invoice_items($invoiceId, $suffix));

// جلب item_id لكل أكواد التوزيع باستعلام واحد مجمّع (للاحتياطي)
$distCodes = array_keys($ashraqaQtys);
$distCodeToItemId = [];
$distCodesByItemId = [];
$nonHashDistCodes = [];
foreach ($distCodes as $dc) {
    if (str_starts_with($dc, '#')) {
        $iid = (int) substr($dc, 1);
        if ($iid > 0) {
            $distCodeToItemId[$dc] = $iid;
            $distCodesByItemId[$iid][] = $dc;
        }
    } else {
        $nonHashDistCodes[] = $dc;
    }
}
if ($nonHashDistCodes) {
    $phC = implode(',', array_fill(0, count($nonHashDistCodes), '?'));
    try {
        $stC = $pdo->prepare("SELECT code, item_id FROM hq_codes WHERE code IN ($phC)");
        $stC->execute($nonHashDistCodes);
        foreach ($stC as $cr) {
            $iid = (int) $cr['item_id'];
            $cVal = (string) $cr['code'];
            $distCodeToItemId[$cVal] = $iid;
            $distCodesByItemId[$iid][] = $cVal;
        }
    } catch (Throwable $ex) { /* تجاهل */ }
}

// فلترة الأصناف: بالكود أولاً ثم باحتياطي item_id (الأصناف اليتيمة ماتنزلش في الإكسيل)
$claimedDistCodes = [];
$ashraqaItems = [];
$ashraqaCodes = [];
foreach ($items as $it) {
    $c = (string)($it['item_code'] ?? '');
    $q = 0.0;
    if ($c !== '' && isset($ashraqaQtys[$c]) && !isset($claimedDistCodes[$c])) {
        $q = (float) $ashraqaQtys[$c];
        $claimedDistCodes[$c] = true;
    } elseif (!empty($it['item_id'])) {
        $iid = (int) $it['item_id'];
        $candCodes = $distCodesByItemId[$iid] ?? [];
        foreach ($candCodes as $dc) {
            if (!isset($claimedDistCodes[$dc]) && isset($ashraqaQtys[$dc]) && (str_starts_with($c, '#') || str_starts_with($dc, '#'))) {
                $q += (float) $ashraqaQtys[$dc];
                $claimedDistCodes[$dc] = true;
            }
        }
    }
    if ($q <= 0) continue;
    $ashraqaQtys[$c] = $q;
    $ashraqaItems[] = $it;
    $ashraqaCodes[] = $c;
}

// الأرصدة والأسعار لفرع الفرع
// الرصيد من hq_branch_balances بالـitem_id (نفس branch_invoices_core.php)
$itemIdByCode = [];
foreach ($ashraqaItems as $it) {
    $c   = (string) ($it['item_code'] ?? '');
    $iid = (int) ($it['item_id'] ?? 0);
    if ($iid <= 0 && str_starts_with($c, '#')) $iid = (int) substr($c, 1);
    if ($iid <= 0) $iid = (int) ($distCodeToItemId[$c] ?? 0);
    if ($iid > 0) $itemIdByCode[$c] = $iid;
}
$rawBal   = br_inv_balances((string) $BR_INV['agent'], array_values($itemIdByCode));
$balances = [];
foreach ($itemIdByCode as $c => $iid) {
    if (isset($rawBal[$iid])) $balances[$c] = ['balance' => $rawBal[$iid]];
}
$salePrices = br_inv_get_prices((int) $BR_INV['id'], $distId);

// دالة حساب التكلفة والنسبة (نفس شاشة الفاتورة)
function ash_exp_cost(array $item): float
{
    $c = (float) ($item['unit_price'] ?? 0);
    if ($c <= 0) $c = (float) ($item['cost_price'] ?? 0);
    return $c > 0 ? $c : 0.0;
}

function ash_exp_pct(float $cost, float $sale): ?float
{
    if ($cost <= 0 || $sale <= 0) return null;
    return ($sale / $cost - 1) * 100;
}

$x = new SimpleXlsx('فاتورة ' . $invHeader['inv_no']);

// ===== أنواع الأعمدة (قبل أي بيانات) =====
//   A=م رقم · B=كود الصنف نص · C=اسم الصنف نص · الباقي أرقام
$x->setColumnTypes(['n', 's', 's', 'n', 'n', 'n', 'n', 'n']);

// ===== بيانات الفاتورة =====
$x->addRow(['بيانات الفاتورة'], true);
$x->addRow(['رقم الفاتورة', (string) $invHeader['inv_no']]);
$x->addRow(['التاريخ', $invHeader['invoice_date']
    ? date('Y-m-d', strtotime((string) $invHeader['invoice_date'])) : '']);
$x->addRow(['عدد الأصناف', count($ashraqaItems)]);
$x->addRow(['تاريخ التصدير', date('Y-m-d H:i')]);
$x->addBlank();

// ===== رأس جدول الأصناف =====
$head = ['م', 'كود الصنف', 'اسم الصنف', 'كمية ' . $BR_INV['name'], 'رصيد ' . $BR_INV['name'], 'سعر الوحدة', 'سعر البيع', 'نسبة التسعير'];
$x->addRow($head, true);

// ===== الأصناف =====
$i = 1;
$totQty = 0.0;

foreach ($ashraqaItems as $it) {
    $code = (string) ($it['item_code'] ?? '');
    $qty  = (float) ($ashraqaQtys[$code] ?? 0);
    $unit = (float) ($it['unit_price'] ?? 0);

    // رصيد الفرع: لو غير موجود يكون فاضي
    $bbal = isset($balances[$code]) ? round((float)$balances[$code]['balance'], 3) : '';

    // سعر البيع الخاص بالفرع: لو غير موجود يكون فاضي
    $hasSale = isset($salePrices[$code]) && (float)$salePrices[$code] > 0;
    $sPrice  = $hasSale ? round((float)$salePrices[$code], 2) : '';

    // نسبة التسعير: لو مفيش تكلفة أو سعر بيع تكون فاضية
    $cost = ash_exp_cost($it);
    $pct  = ($cost > 0 && $hasSale) ? ash_exp_pct($cost, (float)$salePrices[$code]) : null;
    $pctOut = ($pct !== null) ? round($pct, 1) : '';

    $row = [
        $i++,
        $code,
        (string) ($it['item_name'] ?? '—'),
        round($qty, 3),
        $bbal,
        round($unit, 2),
        $sPrice,
        $pctOut,
    ];

    $x->addRow($row);
    $totQty += $qty;
}

// ===== الإجماليات =====
$tot = ['', '', 'الإجمالي', round($totQty, 3), '', '', '', ''];
$x->addRow($tot, true);

$safeDocNo = preg_replace('/[^\w\-]+/', '_', (string) $invHeader['inv_no']);
$x->download(strtoupper($BR_KEY) . '_' . $safeDocNo . '.xlsx');
