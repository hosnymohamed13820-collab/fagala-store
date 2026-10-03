<?php
/**
 * group_full_export.php — تصدير مجموعة فواتير كاملة (إكسيل .xlsx)
 * نظام الفجالة ستور المالي
 *
 * =====================================================================
 *  نفس فكرة invoice_full_export.php بالظبط، بس على مستوى المجموعة:
 *  الأصناف مدموجة من كل فواتير المجموعة (الصنف اللي في أكتر من فاتورة
 *  بيطلع سطر واحد بالكمية المجمّعة) — نفس اللي ظاهر على الشاشة بالحرف.
 *
 *  زيادة عن ملف الفاتورة المفردة: عمود "المصدر" بيوضّح الصنف جاي من
 *  أنهي فاتورة/فواتير وبكام — عشان اللي يفتح الملف يعرف يرجّع الرقم.
 *
 *  عمود كود الصنف محدّد كنص من الأصل، فالأكواد الطويلة مابتتحولش
 *  لصيغة علمية والأصفار البادئة مابتضيعش.
 *
 *  المدخلات: ?group=GROUP_ID
 * =====================================================================
 */
require_once __DIR__ . '/auth.php';
require_permission('invoices');

// الملف بياخد وقت على الفواتير الكبيرة، والجلسة بتفضل مقفولة طول ما هو شغال
// فأي حفظ AJAX للمستخدم نفسه بيستنى في الطابور. الجلسة اتقريت خلاص، فنقفلها.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

require_once __DIR__ . '/group_lib.php';
require_once __DIR__ . '/xlsx_lib.php';

$groupId = (int) ($_GET['group'] ?? 0);

if ($groupId <= 0) {
    http_response_code(400);
    die('لازم تحدد المجموعة.');
}

$pdo = db();

$grp = group_get($groupId);
if (!$grp) {
    http_response_code(404);
    die('المجموعة غير موجودة.');
}

$data     = group_load($groupId);
$items    = $data['items'];
$invoices = $data['invoices'];

if (!$items) {
    http_response_code(404);
    die('مفيش أصناف في فواتير المجموعة.');
}

$branches = $pdo->query(
    'SELECT id, name, sto_id, ext_balance_src FROM branches
     WHERE is_active=1 AND in_receiving=1 ORDER BY name'
)->fetchAll();

// أسعار البيع — نفس مصدر الشاشة بالظبط (أقدم فاتورة فيها الصنف)
$salePrices = group_resolve_prices($items);

// الأرصدة: مخازن الشركة بالـ item_id، واشراقة بالباركود (نظامها مختلف)
$allBalances = sqlsrv_fetch_item_balances_all(
    array_map(fn($i) => (int) ($i['item_id'] ?? 0), $items)
);
$ashraqaBalances = ashraqa_fetch_balances(
    array_map(fn($i) => (string) ($i['item_code'] ?? ''), $items)
);

$x = new SimpleXlsx('مجموعة ' . ($grp['name'] ?: ('#' . $groupId)));

// ===== أنواع الأعمدة (قبل أي بيانات) =====
//   A=م رقم · B=كود الصنف نص · C=اسم الصنف نص · D=الكمية رقم
//   E=المصدر نص · الباقي أرقام
$types = ['n', 's', 's', 'n', 's', 'n', 'n'];
foreach ($branches as $b) { $types[] = 'n'; $types[] = 'n'; }
array_push($types, 'n', 'n', 'n', 'n');
$x->setColumnTypes($types);

// ===== بيانات المجموعة =====
$totalQty = 0.0;
foreach ($items as $it) $totalQty += (float) $it['quantity'];

$x->addRow(['بيانات المجموعة'], true);
$x->addRow(['اسم المجموعة', (string) ($grp['name'] ?: ('مجموعة #' . $groupId))]);
$x->addRow(['عدد الفواتير', count($invoices)]);
$x->addRow(['عدد الأصناف',  count($items)]);
$x->addRow(['إجمالي الكميات', round($totalQty, 3)]);
if (trim((string) ($grp['user_notes'] ?? '')) !== '') {
    $x->addRow(['ملاحظات', (string) $grp['user_notes']]);
}
$x->addRow(['تاريخ التصدير', date('Y-m-d H:i')]);
$x->addBlank();

// ===== فواتير المجموعة =====
$x->addRow(['فواتير المجموعة'], true);
$x->addRow(['', 'رقم الفاتورة', 'المورد', 'التاريخ'], true);
foreach ($invoices as $inv) {
    $x->addRow([
        '',
        (string) $inv['invoice_no'],
        (string) ($inv['supplier_name'] ?: '—'),
        !empty($inv['invoice_date']) ? date('Y-m-d', strtotime((string) $inv['invoice_date'])) : '',
    ]);
}
if (!empty($data['missing'])) {
    $x->addRow(['', 'فيه ' . count($data['missing']) . ' فاتورة مقدرناش نجيب بياناتها']);
}
$x->addBlank();

// ===== رأس جدول الأصناف =====
$head = ['م', 'كود الصنف', 'اسم الصنف', 'الكمية', 'المصدر', 'سعر الوحدة', 'إجمالي الصنف'];
foreach ($branches as $b) {
    $head[] = (string) $b['name'];
    $head[] = 'رصيد ' . (string) $b['name'];
}
array_push($head, 'الموزّع', 'المتبقي بالمخزن', 'سعر البيع', 'رصيد الإدارة');
$x->addRow($head, true);

// ===== الأصناف =====
$i = 1;
$totQty = 0.0; $totVal = 0.0; $totDist = 0.0; $totRem = 0.0;
$branchSums = [];

foreach ($items as $it) {
    $code = (string) $it['item_code'];
    $iid  = (int) ($it['item_id'] ?? 0);
    $qty  = (float) $it['quantity'];
    $unit = (float) ($it['unit_price'] ?? 0);
    $d    = $data['distribution'][$code] ?? [];

    // المصدر: رقم الفاتورة (الكمية) — نفس نص العمود اللي على الشاشة
    $src = [];
    foreach (($it['sources'] ?? []) as $s) {
        $src[] = $s['invoice_no'] . ' ('
               . rtrim(rtrim(number_format((float) $s['qty'], 2, '.', ''), '0'), '.') . ')';
    }

    $row = [$i++, $code, (string) $it['item_name'],
            round($qty, 3), implode(' · ', $src),
            round($unit, 2), round($qty * $unit, 2)];

    $sum = 0.0;
    foreach ($branches as $b) {
        $bid  = (int) $b['id'];
        $q    = (float) ($d[$bid] ?? 0);
        $sum += $q;
        $branchSums[$bid] = ($branchSums[$bid] ?? 0) + $q;
        $row[] = $q > 0 ? round($q, 3) : '';

        // رصيد الفرع: اشراقة بالباركود (نظام منفصل)، والباقي بالـ sto_id
        if (!empty($b['ext_balance_src'])) {
            $bbal = isset($ashraqaBalances[$code])
                  ? (float) $ashraqaBalances[$code]['balance'] : null;
        } else {
            $bsto = (int) ($b['sto_id'] ?? 0);
            $bbal = $bsto > 0 ? ($allBalances[$iid][$bsto] ?? null) : null;
        }
        $row[] = $bbal === null ? '' : round((float) $bbal, 3);
    }

    $rem = $qty - $sum;
    $row[] = round($sum, 3);
    $row[] = round($rem, 3);
    $row[] = isset($salePrices[$code]) ? round((float) $salePrices[$code], 3) : '';
    $adminBal = $allBalances[$iid][ADMIN_STO_ID] ?? null;
    $row[] = $adminBal === null ? '' : round((float) $adminBal, 3);

    $x->addRow($row);

    $totQty  += $qty;
    $totVal  += $qty * $unit;
    $totDist += $sum;
    $totRem  += $rem;
}

// ===== الإجماليات =====
$tot = ['', '', 'الإجمالي', round($totQty, 3), '', '', round($totVal, 2)];
foreach ($branches as $b) {
    $v = (float) ($branchSums[(int) $b['id']] ?? 0);
    $tot[] = $v > 0 ? round($v, 3) : '';
    $tot[] = '';
}
array_push($tot, round($totDist, 3), round($totRem, 3), '', '');
$x->addRow($tot, true);

$x->download('GROUP_' . $groupId . '_' . date('Y-m-d') . '.xlsx');
