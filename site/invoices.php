<?php
/**
 * invoices.php — الاستلامات (توزيع فواتير السيستم على الفروع)
 * نظام الفجالة ستور المالي
 *
 * - قائمة فواتير SQL Server (مع تجميع 3 أيام لنفس المورد — للعرض فقط).
 * - فتح فاتورة: عرض أصنافها + توزيع كل صنف على الفروع.
 * - عمود المخزن (الفرق) = الكمية − الموزّع.
 * - أزرار التوزيع لكل فرع تنوّر عند إدخال كمية > 0.
 * - التوزيع على مرحلتين: المتاح = المتبقي فقط.
 * - بحث الصنف بالكود.
 * - الهيدر بيتزامن مع الموردين تلقائياً (ensure_distribution_record).
 */
require_once __DIR__ . '/auth.php';
require_permission('invoices');
require_once __DIR__ . '/receiving_lib.php';
require_once __DIR__ . '/group_lib.php';       // الفواتير المجمّعة
require_once __DIR__ . '/item_flags_lib.php';  // ملاحظات وألوان الأصناف

// =====================================================================
//  AJAX — تتبّع الصنف عبر الفواتير (السيرش بار اللي في الهيدر)
//  كله محلي على hq_* — صفر نداءات للجسر.
// =====================================================================
$ilkAjax = $_GET['ilk'] ?? '';
if ($ilkAjax !== '') {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/item_lookup_lib.php';
    try {
        if (!ilk_ready()) throw new RuntimeException('المرآة لسه فاضية — شغّل وكيل المزامنة.');

        if ($ilkAjax === 'search') {
            echo json_encode([
                'ok'   => true,
                'rows' => ilk_search((string) ($_GET['term'] ?? ''), 15),
            ], JSON_UNESCAPED_UNICODE);
        } elseif ($ilkAjax === 'history') {
            $res = ilk_item_history((string) ($_GET['code'] ?? ''), (int) ($_GET['item_id'] ?? 0));
            echo json_encode(['ok' => true] + $res, JSON_UNESCAPED_UNICODE);
        } else {
            throw new RuntimeException('عملية غير معروفة.');
        }
    } catch (Throwable $ex) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// =====================================================================
//  AJAX — بحث الأصناف جوه الفواتير (بوب أب «تحديد أصناف» في المجموعات)
//  بيشمل المرحّلة والغير محفوظة، وبيوضّح نوع المستند (شراء / مردود).
//  كله محلي على hq_* — صفر نداءات للجسر.
// =====================================================================
if (($_GET['gpick'] ?? '') === 'search') {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/item_lookup_lib.php';
    try {
        // لو الملف القديم لسه على السيرفر، الدالة مش هتكون موجودة —
        // نقول ده صريح بدل ما الصفحة ترمي Fatal والواجهة تقول «مفيش نتايج».
        if (!function_exists('ilk_pick_search')) {
            throw new RuntimeException('ارفع item_lookup_lib.php الجديد — النسخة اللي على السيرفر قديمة.');
        }
        if (!ilk_ready()) throw new RuntimeException('المرآة لسه فاضية — شغّل وكيل المزامنة.');

        $term = trim((string) ($_GET['term'] ?? ''));
        $rows = ilk_pick_search($term);

        // الأصناف اللي جوه المجموعة بالفعل — عشان نمنع التكرار في الواجهة
        $gid  = (int) ($_GET['g'] ?? 0);
        $have = [];
        if ($gid > 0) {
            foreach (group_selected_map($gid) as $k => $codes) {
                foreach (array_keys($codes) as $c) $have[$k . '|' . $c] = true;
            }
        }
        foreach ($rows as &$r) $r['in_group'] = isset($have[$r['key']]);
        unset($r);

        echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// =====================================================================
//  AJAX — أصناف فاتورة واحدة (تصفّح بالفاتورة جوه بوب أب تحديد الأصناف)
//  بيرجّع نفس شكل صفوف البحث بالظبط عشان الواجهة ترسمها بنفس الدالة.
// =====================================================================
if (($_GET['gpick'] ?? '') === 'items') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $sth = (int) ($_GET['sth'] ?? 0);
        $suf = trim((string) ($_GET['suffix'] ?? ''));
        if ($sth <= 0) throw new RuntimeException('رقم الفاتورة ناقص.');

        $h = sqlsrv_fetch_invoice_header($sth, $suf !== '' ? $suf : null);
        if (!$h) throw new RuntimeException('الفاتورة مش موجودة في المرآة — يمكن اتلغت أو السينك متأخر.');

        $realSuf = (string) ($h['suffix'] ?? $suf);
        $isRet   = ((int) ($h['doc_type'] ?? 0) === 6);
        $isUnp   = ($realSuf === 'YY_MM');

        // نفس الدمج المستخدم في شاشة الفاتورة: الكود المكرر سطر واحد
        $lines = merge_invoice_items(sqlsrv_fetch_invoice_items($sth, $realSuf ?: null));

        // الموجود في المجموعة بالفعل
        $gid  = (int) ($_GET['g'] ?? 0);
        $have = $gid > 0 ? (group_selected_map($gid)[$sth . '|' . $realSuf] ?? []) : [];

        $rows = [];
        foreach ($lines as $ln) {
            $code = trim((string) ($ln['item_code'] ?? ''));
            if ($code === '') continue;
            $rows[] = [
                'key'        => $sth . '|' . $realSuf . '|' . $code,
                'sth_id'     => $sth,
                'suffix'     => $realSuf,
                'invoice_no' => (string) ($h['inv_no'] ?? ''),
                'type_label' => $isRet ? 'مردود' : 'فاتورة شراء',
                'is_return'  => $isRet,
                'unposted'   => $isUnp,
                'supplier'   => (string) ($h['supplier_name'] ?? ''),
                'date'       => (string) ($h['invoice_date'] ?? ''),
                'item_code'  => $code,
                'item_name'  => (string) ($ln['item_name'] ?? ''),
                'qty'        => (float) ($ln['quantity'] ?? 0),
                'in_group'   => !empty($have[$code]),
            ];
        }

        echo json_encode([
            'ok'    => true,
            'title' => trim(($h['inv_no'] ?? '') . ' — ' . ($h['supplier_name'] ?? '')),
            'rows'  => $rows,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// =====================================================================
//  AJAX — فواتير مجموعة معيّنة (تصفّح بالمجموعة)
// =====================================================================
if (($_GET['gpick'] ?? '') === 'ginv') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $g2 = (int) ($_GET['g2'] ?? 0);
        $og = $g2 > 0 ? group_get($g2) : null;
        if (!$og) throw new RuntimeException('المجموعة مش موجودة.');

        $rows = [];
        foreach (group_invoices($g2) as $iv) {
            $rows[] = [
                'sth_id'     => (int) $iv['sth_id'],
                'suffix'     => (string) $iv['suffix'],
                'invoice_no' => (string) ($iv['invoice_no'] ?? ''),
                'supplier'   => (string) ($iv['supplier_name'] ?? ''),
                'date'       => substr((string) ($iv['invoice_date'] ?? ''), 0, 10),
                'unposted'   => ((string) $iv['suffix'] === 'YY_MM'),
            ];
        }
        echo json_encode([
            'ok'    => true,
            'title' => trim(($og['name'] ?: ('مجموعة #' . $g2)) . ' — ' . count($rows) . ' فاتورة'),
            'rows'  => $rows,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$pdo  = db();
$uid  = (int) current_user()['id'];
$flash = ''; $flashType = 'success';

// =====================================================================
//  نسبة التسعير (Markup)
//  النسبة = (سعر البيع ÷ التكلفة − 1) × 100
//    تكلفة 100 وبيع 130 → 30%   ·   المستخدم يكتب 50 → البيع = التكلفة × 1.5
//  التكلفة المستخدمة = سعر الوحدة المعروض في الجدول (STL_ITEM_PRICE)،
//  ولو صفر بنرجع لتكلفة البند (STL_ITEM_COST).
//  السعر الناتج بيتقرّب لأقرب نص جنيه.
// =====================================================================
const MRK_ROUND_STEP = 0.5;

/** تقريب السعر الناتج لأقرب نص جنيه */
function mrk_round(float $v): float
{
    return round($v / MRK_ROUND_STEP) * MRK_ROUND_STEP;
}

/** التكلفة اللي النسبة بتتحسب عليها لبند */
function mrk_cost(array $item): float
{
    $c = (float) ($item['unit_price'] ?? 0);
    if ($c <= 0) $c = (float) ($item['cost_price'] ?? 0);
    return $c > 0 ? $c : 0.0;
}

/** النسبة المئوية للعرض (null لو مفيش تكلفة أو سعر) */
function mrk_pct(float $cost, float $sale): ?float
{
    if ($cost <= 0 || $sale <= 0) return null;
    return ($sale / $cost - 1) * 100;
}

/** تنسيق النسبة: 30 مش 30.0 · 33.3 لو فيها كسر */
function mrk_fmt(?float $p): string
{
    if ($p === null) return '';
    $s = number_format($p, 1, '.', '');
    $s = rtrim(rtrim($s, '0'), '.');
    return $s === '' || $s === '-' ? '0' : $s;
}

/**
 * خانة نسبة التسعير لصف صنف.
 * الخانة **بتفضل فاضية دايمًا** — النسبة المحسوبة بتبان باهتة جواها
 * (placeholder) وماتتخزّنش في أي مكان. لو المستخدم كتب رقم، الجافاسكربت
 * بيحسب سعر البيع ويحفظه ويفضّي الخانة تاني.
 */
function mrk_cell(string $code, float $cost, ?float $sale): string
{
    $has = $cost > 0;
    $pct = $has ? mrk_pct($cost, (float) $sale) : null;
    $ph  = $pct === null ? '—' : mrk_fmt($pct) . '%';
    $cs  = $has ? rtrim(rtrim(number_format($cost, 5, '.', ''), '0'), '.') : '0';

    return '<td class="c-mrk">'
        . '<input type="number" step="0.5" class="mrk-input' . ($has ? '' : ' no-cost') . '"'
        . ' aria-label="نسبة التسعير" data-code="' . e($code) . '" data-cost="' . e($cs) . '"'
        . ' placeholder="' . e($ph) . '"' . ($has ? '' : ' disabled')
        . ' title="' . ($has ? 'اكتب نسبة واضغط Enter — سعر البيع هيتحسب لوحده' : 'مفيش تكلفة للصنف ده') . '"'
        . ' onblur="applyMrk(this)" onkeydown="mrkKey(event,this)">'
        . '</td>';
}

/** شريط النسبة العامة فوق الجدول */
function mrk_bulk_bar(): string
{
    return '<div class="mrk-bulk">'
        . '<span class="mrk-lbl"><i class="ti ti-percentage"></i> نسبة التسعير لكل الأصناف</span>'
        . '<input type="number" step="0.5" id="mrkBulk" placeholder="مثال: 50"'
        . ' aria-label="نسبة تسعير لكل الأصناف" autocomplete="off"'
        . ' onkeydown="mrkBulkKey(event)">'
        . '<button type="button" class="btn btn-sm btn-primary" onclick="applyBulkMrk()">'
        . '<i class="ti ti-check"></i> طبّق</button>'
        . '<span class="mrk-hint">بتسعّر كل الأصناف مرة واحدة (بتأكيد قبلها).</span>'
        . '<span id="mrkBulkNote" style="display:none;font-size:13px;"></span>'
        . '</div>';
}

// =====================================================================
//  علامات الصنف — دوال العرض
//  ---------------------------------------------------------------
//  بتتنده من تلات أماكن (فاتورة موزّعة بالكامل · فاتورة عادية ·
//  مجموعة) فمكتوبة مرة واحدة هنا بدل ما تتكرر في الـHTML.
//
//  أسبقية لون الصف:
//    لون يدوي موجود        → اللون اليدوي هو خلفية الصف
//    مفيش لون يدوي + تم التوزيع الفعلي → خلفية خضرا
//    الاتنين مع بعض        → اللون اليدوي للخلفية + علامة ✔ خضرا
//  كده مفيش معلومة بتختفي ورا التانية.
// =====================================================================

/** كلاسات الصف حسب العلامات */
function ifl_row_class(array $f): string
{
    $hl = (string) ($f['highlight'] ?? '');
    $cls = 'item-row';
    if ($hl !== '')                              $cls .= ' hl-' . $hl;
    elseif ((int) ($f['done_actual'] ?? 0) === 1) $cls .= ' hl-done';
    return $cls;
}

/** خصائص data- للصف (الجافاسكربت بيقرا منها الحالة الحالية) */
function ifl_row_data(array $f, bool $skip): string
{
    return ' data-hl="'   . e((string) ($f['highlight'] ?? '')) . '"'
         . ' data-done="' . (int) ($f['done_actual'] ?? 0) . '"'
         . ' data-skip="' . ($skip ? 1 : 0) . '"'
         . ' data-note="' . e((string) ($f['user_notes'] ?? '')) . '"';
}

/** خانة كود الصنف + زرار الإعدادات + علامة الممنوع من التقرير */
function ifl_code_cell(string $code, bool $skip): string
{
    return '<td class="c-code"><span class="ifl-codewrap">'
        . '<button type="button" class="ifl-gear" title="إعدادات الصنف"'
        . ' aria-label="إعدادات الصنف" onclick="iflOpen(this)">'
        . '<i class="ti ti-adjustments"></i></button>'
        . '<span class="ifl-code-txt">' . e($code) . '</span>'
        . '<i class="ti ti-download ifl-noexp" title="الصنف ده مش بينزل في ملف الفرع"'
        . ($skip ? '' : ' style="display:none;"') . '></i>'
        . '</span></td>';
}

/** خانة اسم الصنف + أيقونة الملاحظة + علامة التوزيع الفعلي */
function ifl_name_cell(string $name, array $f): string
{
    $note = trim((string) ($f['user_notes'] ?? ''));
    $done = (int) ($f['done_actual'] ?? 0) === 1;

    // ⚠️ الأيقونة دي **مابتتضغطش** — تولتيب بس بالوقوف عليها.
    //    فتح الإعدادات من زرار ⚙ اللي جنب كود الصنف. (طلب محمد 2026-08-14)
    return '<td class="c-name">'
        . '<i class="ti ti-note ifl-noteic' . ($note !== '' ? ' has' : '') . '"'
        . ' title="' . e($note !== '' ? $note : 'مفيش ملاحظة') . '"'
        . ' aria-hidden="true"></i> '
        . '<span class="ifl-name-txt">' . e($name) . '</span>'
        . '<i class="ti ti-circle-check ifl-donetick" title="تم التوزيع الفعلي"'
        . ($done ? '' : ' style="display:none;"') . '></i>'
        . '</td>';
}

/** خانة الملاحظات — آخر عمود في الجدول */
function ifl_notes_cell(string $code, array $f): string
{
    $note = (string) ($f['user_notes'] ?? '');
    return '<td class="c-inote">'
        . '<input type="text" class="inote-input" placeholder="ملاحظة…"'
        . ' aria-label="ملاحظة الصنف" autocomplete="off"'
        . ' data-code="' . e($code) . '" value="' . e($note) . '"'
        . ' oninput="iflNoteTyping(this)" onblur="iflNoteSave(this)">'
        . '<span class="inote-ind"></span>'
        . '</td>';
}

/**
 * لافتة بتقول كام صنف معلّم «لا ينزل في التقرير» في الشاشة دي.
 * بتتعرض جنب أزرار تحميل ملفات الفروع.
 *
 * الغرض مش تزويق: دي بتقرا من **الداتابيز** بعد التحديث، فلو الرقم
 * طلع صفر بعد ما علّمت صنف، يبقى الحفظ مش واصل — ولو طلع صح والملف
 * لسه فيه الصنف، يبقى ملف التصدير على السيرفر قديم.
 */
function ifl_skip_note(array $skipMap): string
{
    $n = count($skipMap);
    // بتتبنى دايمًا (حتى لو صفر) عشان الجافاسكربت يقدر يحدّثها لحظيًا
    // لما تعلّم صنف من البوب أب من غير ما تعمل تحديث للصفحة.
    return '<div class="ifl-skipnote"' . ($n < 1 ? ' style="display:none;"' : '') . '>'
        . '<i class="ti ti-alert-triangle"></i>'
        . '<span><b class="ifl-skipn">' . $n . '</b> صنف معلّم «لا ينزل في التقرير» — '
        . 'مش هيظهر في ملفات الفروع (TXT). '
        . 'الكميات والأسعار وملف الإكسيل شغالين عادي.<br>'
        . '<span class="ifl-skiplist">' . e(implode(' · ', array_slice(array_keys($skipMap), 0, 12)))
        . ($n > 12 ? ' …' : '') . '</span></span></div>';
}

/** شريط أزرار مسح التمييز — فوق الجدول */
function ifl_bar(): string
{
    return '<div class="ifl-bar">'
        . '<span class="ifl-lbl"><i class="ti ti-adjustments"></i> تمييز الصفوف</span>'
        . '<button type="button" class="btn btn-sm" onclick="iflClear(\'highlight\')"'
        . ' title="بيرجّع كل الصفوف للون الطبيعي — علامات «تم التوزيع الفعلي» مابتتمسحش">'
        . '<i class="ti ti-rotate"></i> مسح التمييز</button>'
        . '<button type="button" class="btn btn-sm" onclick="iflClear(\'done\')"'
        . ' title="بيشيل علامة «تم التوزيع الفعلي» من كل الأصناف">'
        . '<i class="ti ti-circle-check"></i> مسح علامات التوزيع الفعلي</button>'
        . '<span class="ifl-hint">اضغط على <i class="ti ti-adjustments"></i> جنب كود أي صنف للألوان والملاحظات.</span>'
        . '</div>';
}

$activeBranches = $pdo->query('SELECT id, name, sto_id, ext_balance_src FROM branches WHERE is_active=1 AND in_receiving=1 ORDER BY name')->fetchAll();

// =====================================================================
//  بوب أب اختيار الفواتير / الأصناف — 2026-08-14
//  ---------------------------------------------------------------
//  نفس البوب أب بيخدم ٤ حالات (عشان مانكررش الماركب):
//    مجموعة جديدة       → group_create        / group_create_items
//    إضافة لمجموعة قائمة → group_add_invoices  / group_add_items
//
//  وضعان جواه: [الفواتير] و[تحديد أصناف]. الافتراضي «الفواتير».
//
//  ⚠️ المجموعة نوع full مابتعرضش زرار «تحديد أصناف» خالص —
//     القاعدة: اللي بدأ فواتير كاملة يفضل كده.
// =====================================================================
/**
 * مخازن **الفروع** (مش الإدارة).
 *
 * ⚠️ المنطق معكوس عن قصد. أول محاولة كانت بتدوّر على الإدارة بـ
 *    `in_receiving = 0`، وده فشل لأن **الإدارة أصلاً مش مسجّلة في
 *    جدول branches** — هي مش فرع استلامات فمحدش عملها. النتيجة كانت
 *    خريطة فاضية → الفلتر يتوقف → كل الفواتير تظهر. (بلاغ محمد 14/08)
 *
 *    الصح: نعرّف **الفروع** (اللي متسجّلة فعلاً وليها sto_id عشان
 *    أرصدة المخزون)، وأي حاجة غيرها = الإدارة.
 *
 * @return array [sto_id => true]
 */
function grp_branch_stores(): array
{
    static $m = null;
    if ($m !== null) return $m;
    $m = [];
    try {
        $q = db()->query(
            'SELECT sto_id FROM branches
             WHERE in_receiving = 1 AND sto_id IS NOT NULL AND sto_id > 0'
        );
        foreach ($q as $b) $m[(int) $b['sto_id']] = true;
    } catch (Throwable $ex) { /* تجاهل */ }
    return $m;
}

/**
 * الفاتورة دي بتاعة الإدارة؟
 * = مخزنها مش واحد من مخازن الفروع المعروفة.
 * الفاتورة اللي مخزنها فاضي أو صفر بتتحسب إدارة (الأأمن — ما نخفيش شغل).
 */
function grp_is_admin_inv(array $iv): bool
{
    $br = grp_branch_stores();
    if (!$br) return true;   // مفيش فروع متسجّلة — مانفلترش
    return !isset($br[(int) ($iv['company_branch_id'] ?? 0)]);
}

/** شخاطة «فواتير الإدارة بس» — بتتحط تحت خانة البحث في الوضعين */
function grp_admin_toggle(int $branchCount): string
{
    // مفيش فروع متسجّلة بـ sto_id → الفلتر متوقف. نقول ده صريح بدل
    // ما تفضل تتفرج على كل الفواتير وانت فاكر إن الفلتر شغال.
    if (!grp_branch_stores()) {
        return '<div class="grp-admtip" style="margin-top:9px;color:var(--warn);">'
            . '<i class="ti ti-alert-triangle"></i> مفيش فروع متسجّلة بكود مخزن (sto_id) في «التعريفات ← الفروع» — '
            . 'فمقدرناش نفرّق بين فواتير الإدارة والفروع، وبتشوف الكل.</div>';
    }
    if ($branchCount < 1) return '';   // كل الفواتير إدارة أصلاً
    return '<label class="grp-admtog">'
        . '<input type="checkbox" onchange="grpShowBranches(this.checked)">'
        . '<span>اعرض فواتير الفروع كمان <b>(' . $branchCount . ')</b></span>'
        . '<span class="grp-admtip">افتراضيًا بتشوف فواتير الإدارة بس.</span>'
        . '</label>';
}

function grp_picker(array $unposted, array $posted, int $groupId = 0, string $groupType = 'full'): string
{
    $isAdd     = $groupId > 0;
    $allowItems = !$isAdd || $groupType === 'items';   // مجموعة full: فواتير بس
    $h = '';

    // كام فاتورة فرع هتتخفي؟ (للشرح على زرار الإظهار)
    $branchCount = 0;
    foreach (array_merge($unposted, $posted) as $iv) {
        if (!grp_is_admin_inv($iv)) $branchCount++;
    }

    $rows = function (array $list, string $label) use (&$h) {
        if (!$list) return '';
        // العدد جوه span عشان الجافاسكربت يحدّثه مع الفلتر —
        // كان رقم ثابت من السيرفر فبيقول 613 وانت شايف 20. (بلاغ 14/08)
        $o = '<div class="picker-group">' . e($label)
           . ' (<span class="pg-n">' . count($list) . '</span>)</div>';
        foreach ($list as $iv) {
            $adm = grp_is_admin_inv($iv);
            $o .= '<label class="picker-row grp-row" data-adm="' . ($adm ? 1 : 0) . '"'
                . ($adm ? '' : ' style="display:none;"')
                . ' data-s="'
                . e(mb_strtolower(($iv['inv_no'] ?? '') . ' ' . ($iv['supplier_name'] ?? ''))) . '">'
                . '<input type="checkbox" name="pick[]" onchange="grpCount()" value="'
                . e((string) $iv['inv_id']) . '|' . e((string) ($iv['suffix'] ?? '')) . '">'
                . '<span class="pk-no">' . e((string) $iv['inv_no']) . '</span>'
                . '<span class="pk-sup">' . e((string) ($iv['supplier_name'] ?? '—')) . '</span>'
                . '<span class="pk-date">' . e(date('Y-m-d', strtotime((string) $iv['invoice_date']))) . '</span>'
                . '<span class="pk-amt">' . number_format((float) $iv['net_amount'], 2) . '</span>'
                . '</label>';
        }
        return $o;
    };

    $h .= '<div class="picker-overlay" id="grpPicker" onclick="if(event.target===this)closeGrpPicker()">'
        . '<div class="picker-box">'
        . '<form method="post" id="grpForm" onsubmit="return grpSubmit(event)">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<input type="hidden" name="action" id="grpAction" value="'
        . ($isAdd ? 'group_add_invoices' : 'group_create') . '">'
        . '<input type="hidden" name="group_id" value="' . $groupId . '">'
        . '<input type="hidden" name="ipicks" id="grpIpicks" value="">';

    // ---- الهيدر + تبديل الوضع ----
    $h .= '<div class="picker-head">'
        . '<span id="grpHeadTxt">' . ($isAdd ? 'إضافة للمجموعة' : 'مجموعة جديدة') . '</span>'
        . '<button type="button" class="picker-x" onclick="closeGrpPicker()">&times;</button>'
        . '</div>';

    if ($allowItems) {
        $h .= '<div class="grp-modes">'
            . '<button type="button" class="gm-btn active" data-mode="inv" onclick="grpMode(\'inv\')">'
            . '<i class="ti ti-file-invoice"></i> فواتير كاملة</button>'
            . '<button type="button" class="gm-btn" data-mode="item" onclick="grpMode(\'item\')">'
            . '<i class="ti ti-scan"></i> تحديد أصناف</button>'
            . '</div>';
    } else {
        $h .= '<div class="grp-lockmsg"><i class="ti ti-lock"></i> '
            . 'المجموعة دي «فواتير كاملة» — ينفع تضيف فاتورة كاملة بس، '
            . 'مش صنف منفرد.</div>';
    }

    // ================= وضع الفواتير =================
    $h .= '<div id="grpPanelInv">'
        . '<div class="picker-search">';
    if (!$isAdd) {
        $h .= '<input type="text" id="grpName" name="group_name" autocomplete="off"'
            . ' placeholder="اسم المجموعة (اختياري) — مثلاً: مورد المنار أغسطس" style="margin-bottom:8px;">';
    }
    $h .= '<input type="text" id="grpSearch" autocomplete="off" aria-label="بحث في الفواتير"'
        . ' placeholder="ابحث برقم الفاتورة أو اسم المورد..." oninput="filterGrp()"'
        . ' onkeydown="grpNoEnter(event)">'
        . grp_admin_toggle($branchCount)
        . '</div>'
        . '<div class="picker-body" id="grpBody">'
        . $rows($unposted, 'غير محفوظة')
        . $rows($posted, 'مرحّلة')
        . '<div class="pk-empty" id="grpEmpty" style="display:none;">مفيش نتائج</div>'
        . '</div></div>';

    // ================= وضع تحديد الأصناف =================
    if ($allowItems) {
        $h .= '<div id="grpPanelItem" style="display:none;">'
            . '<div class="picker-search">';
        if (!$isAdd) {
            $h .= '<input type="text" id="grpNameI" autocomplete="off"'
                . ' placeholder="اسم المجموعة (اختياري)" style="margin-bottom:8px;">';
        }
        // طريقتين للوصول للصنف: بحث في كل الفواتير، أو تصفّح فاتورة بفاتورة
        $h .= '<div class="gi-ways">'
            . '<button type="button" class="giw-btn active" data-way="find" onclick="grpWay(\'find\')">'
            . '<i class="ti ti-search"></i> بحث</button>'
            . '<button type="button" class="giw-btn" data-way="browse" onclick="grpWay(\'browse\')">'
            . '<i class="ti ti-file-invoice"></i> بالفاتورة</button>'
            . '<button type="button" class="giw-btn" data-way="grp" onclick="grpWay(\'grp\')">'
            . '<i class="ti ti-stack-2"></i> بالمجموعة</button>'
            . '</div>';

        // ---- طريقة (١): البحث ----
        $h .= '<div id="grpWayFind">'
            . '<div class="gi-searchrow">'
            . '<input type="text" id="grpItemSearch" autocomplete="off" inputmode="search"'
            . ' aria-label="بحث عن صنف" placeholder="كود الصنف أو جزء من الاسم…"'
            . ' oninput="grpFindItem()" onkeydown="grpNoEnter(event)">'
            . '<button type="button" class="btn btn-sm" title="مسح بالكاميرا"'
            . ' onclick="openBarcodeScanner(\'grpItemSearch\')" style="font-size:16px;"><i class="ti ti-camera"></i></button>'
            . '</div>'
            . '<div class="gi-hint">بيدوّر في فواتير الشراء والمردود — المرحّلة والغير محفوظة.</div>'
            . '</div>';

        // ---- طريقة (٢): تصفّح بالفاتورة ----
        $h .= '<div id="grpWayBrowse" style="display:none;">'
            . '<input type="text" id="grpInvFilter" autocomplete="off"'
            . ' aria-label="بحث في الفواتير" placeholder="فلتر: رقم الفاتورة أو المورد…"'
            . ' oninput="grpFilterInvList()" onkeydown="grpNoEnter(event)">'
            . grp_admin_toggle($branchCount)
            . '<div class="gi-hint">دوس على فاتورة عشان تشوف أصنافها وتحدد منها.</div>'
            . '</div>';

        // ---- طريقة (٣): تصفّح بمجموعة موجودة ----
        $h .= '<div id="grpWayGrp" style="display:none;">'
            . '<div class="gi-hint">اختار مجموعة عشان تشوف فواتيرها بس — '
            . 'بعدين ادخل على أي فاتورة وحدد منها.</div>'
            . '</div>';

        $h .= '</div>';   // نهاية picker-search

        // شريط «رجوع» بيظهر جوه فاتورة
        $h .= '<div class="gi-crumb" id="grpCrumb" style="display:none;">'
            . '<button type="button" class="gi-back" onclick="grpBackToInvoices()">'
            . '<i class="ti ti-arrow-right"></i> رجوع للفواتير</button>'
            . '<b id="grpCrumbTitle"></b>'
            . '<button type="button" class="gi-all" onclick="grpPickAllVisible()">حدد الكل</button>'
            . '</div>';

        // قايمة الفواتير للتصفّح (نفس بيانات وضع الفواتير، بس بالضغط مش بالتشيك)
        $h .= '<div class="picker-body" id="grpInvList" style="display:none;">';
        foreach ([['غير محفوظة', $unposted], ['مرحّلة', $posted]] as [$lbl, $list]) {
            if (!$list) continue;
            $h .= '<div class="picker-group">' . e($lbl)
                . ' (<span class="pg-n">' . count($list) . '</span>)</div>';
            foreach ($list as $iv) {
                $adm = grp_is_admin_inv($iv);
                $h .= '<div class="picker-row gi-invrow" data-adm="' . ($adm ? 1 : 0) . '"'
                    . ($adm ? '' : ' style="display:none;"')
                    . ' data-s="'
                    . e(mb_strtolower(($iv['inv_no'] ?? '') . ' ' . ($iv['supplier_name'] ?? ''))) . '"'
                    // ⚠️ علامة تنصيص فردية جوه onclick="..." — json_encode
                    //    بيطلّع مزدوجة وبتقفل الخاصية فالـHTML بيتقطع
                    //    والضغطة مابتعملش حاجة خالص. (اتكشف 14/08)
                    . ' onclick="grpOpenInvoice(' . (int) $iv['inv_id']
                    . ', \'' . e((string) ($iv['suffix'] ?? '')) . '\')">'
                    . '<span class="pk-no">' . e((string) $iv['inv_no']) . '</span>'
                    . '<span class="pk-sup">' . e((string) ($iv['supplier_name'] ?? '—')) . '</span>'
                    . '<span class="pk-date">' . e(date('Y-m-d', strtotime((string) $iv['invoice_date']))) . '</span>'
                    . '<span class="pk-amt"><i class="ti ti-chevron-left"></i></span>'
                    . '</div>';
            }
        }
        $h .= '<div class="pk-empty" id="grpInvEmpty" style="display:none;">مفيش نتائج</div>'
            . '</div>';

        // قايمة المجموعات
        $h .= '<div class="picker-body" id="grpGrpList" style="display:none;">';
        $others = [];
        try {
            $q = db()->prepare(
                'SELECT g.id, g.name, g.group_type, g.created_at,
                        (SELECT COUNT(*) FROM invoice_group_items i WHERE i.group_id = g.id) AS n
                 FROM invoice_groups g
                 WHERE g.id <> ? ORDER BY g.id DESC LIMIT 60'
            );
            $q->execute([$groupId]);
            $others = $q->fetchAll();
        } catch (Throwable $ex) { /* تجاهل */ }

        if (!$others) {
            $h .= '<div class="pk-empty">مفيش مجموعات تانية.</div>';
        } else {
            foreach ($others as $og) {
                $isIt = ((string) ($og['group_type'] ?? 'full')) === 'items';
                $h .= '<div class="picker-row gi-grprow"'
                    . ' onclick="grpOpenGroup(' . (int) $og['id'] . ')">'
                    . '<span class="pk-no">#' . (int) $og['id'] . '</span>'
                    . '<span class="pk-sup">' . e((string) ($og['name'] ?: 'بدون اسم')) . '</span>'
                    . '<span class="pk-date">' . (int) $og['n'] . ' فاتورة</span>'
                    . '<span class="grp-type ' . ($isIt ? 'items' : 'full') . '" style="font-size:10.5px;">'
                    . ($isIt ? 'أصناف' : 'كاملة') . '</span>'
                    . '</div>';
            }
        }
        $h .= '</div>';

        $h .= '<div class="picker-body" id="grpItemBody">'
            . '<div class="pk-empty">اكتب كود أو اسم صنف، أو امسح باركود بالكاميرا.</div>'
            . '</div>'
            . '<div class="gi-chosen" id="grpChosen" style="display:none;"></div>'
            . '</div>';
    }

    // ---- الفوتر ----
    $h .= '<div class="grp-foot">'
        . '<span id="grpHint" style="color:var(--ink-500);font-size:13px;margin-left:auto;">'
        . ($isAdd ? 'اختار فاتورة أو أكتر' : 'اختار فاتورتين على الأقل') . '</span>'
        . '<button type="button" class="btn" style="background:var(--line);color:var(--ink-700);"'
        . ' onclick="closeGrpPicker()">إلغاء</button>'
        . '<button type="submit" class="btn btn-primary" id="grpGo" disabled>'
        . '<i class="ti ti-stack-2"></i> <span id="grpGoTxt">'
        . ($isAdd ? 'ضيف للمجموعة' : 'افتح المجموعة') . '</span></button>'
        . '</div>';

    $h .= '</form></div></div>';
    return $h;
}


// التاب الحالي: posted = فواتير مرحّلة · unposted = غير محفوظة
//               discount = حساب الخصم · group = فواتير مجمّعة
$tab = $_GET['tab'] ?? 'posted';
if (!in_array($tab, ['posted', 'unposted', 'discount', 'group'], true)) $tab = 'posted';
$invStatuses = [];
$salePrices  = [];
$itemFlags   = [];   // [code => علامات الصنف]  — ملاحظة/لون/تم التوزيع الفعلي
$skipMap     = [];   // [code => true]           — ممنوع من ملف الفرع
$iflDistIds  = [];   // سجلات التوزيع بتاعت الشاشة (للتحقق في الواجهة)
$itemBalances = [];
$allBalances  = [];
$storeNames   = [];
$discUnposted = [];
$discPosted   = [];
$discHeader  = null;
$discItems   = [];
$discCalc    = null;

// ===== حفظ سعر البيع (AJAX) — على نسختنا فقط، قاعدة الشركة مابتتكتبش =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_price') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!csrf_verify($_POST['csrf'] ?? '')) {
            throw new RuntimeException('انتهت صلاحية الجلسة، اعمل تحديث للصفحة.');
        }
        $d = (int) ($_POST['distribution_id'] ?? 0);
        $c = trim((string) ($_POST['item_code'] ?? ''));
        $p = (float) ($_POST['price'] ?? 0);

        if ($d <= 0 || $c === '') throw new RuntimeException('بيانات ناقصة.');
        if ($p < 0) throw new RuntimeException('السعر لازم يكون موجب.');

        save_invoice_sale_price($d, $c, $p, $uid);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ===== تحديث الأسعار فورًا من سيستم الشركة (AJAX) =====
// بيجيب كل أسعار الفاتورة من SQL Server ويكتب فوق المخزّن — السيستم هو المرجع.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refresh_prices') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!csrf_verify($_POST['csrf'] ?? '')) {
            throw new RuntimeException('انتهت صلاحية الجلسة، اعمل تحديث للصفحة.');
        }
        $d      = (int) ($_POST['distribution_id'] ?? 0);
        $invId  = trim((string) ($_POST['invoice_id'] ?? ''));
        $suf    = trim((string) ($_POST['suffix'] ?? ''));
        if ($d <= 0 || $invId === '') throw new RuntimeException('بيانات ناقصة.');

        // مدمّج: الأسعار متخزّنة بالكود، فالسطر المكرر مالوش لازمة
        $refItems = merge_invoice_items(sqlsrv_fetch_invoice_items($invId, $suf !== '' ? $suf : null));
        if (!$refItems) throw new RuntimeException('مقدرتش أقرا أصناف الفاتورة من السيستم.');

        $prices = refresh_invoice_sale_prices($d, $refItems, true);
        echo json_encode(['ok' => true, 'prices' => $prices], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ===== حفظ سعر بيع من شاشة المجموعة (AJAX) =====
// بيتكتب على كل فواتير المجموعة اللي فيها الصنف — عشان ملف كل فاتورة
// لوحدها يطلع بنفس السعر.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'group_save_price') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!csrf_verify($_POST['csrf'] ?? '')) {
            throw new RuntimeException('انتهت صلاحية الجلسة، اعمل تحديث للصفحة.');
        }
        $g = (int) ($_POST['group_id'] ?? 0);
        $c = trim((string) ($_POST['item_code'] ?? ''));
        $p = (float) ($_POST['price'] ?? 0);

        $n = group_save_sale_price($g, $c, $p, $uid);
        echo json_encode(['ok' => true, 'invoices' => $n], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ===== تحديث كل أسعار المجموعة من السيستم (AJAX) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'group_refresh_prices') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!csrf_verify($_POST['csrf'] ?? '')) {
            throw new RuntimeException('انتهت صلاحية الجلسة، اعمل تحديث للصفحة.');
        }
        $g = (int) ($_POST['group_id'] ?? 0);
        if ($g <= 0) throw new RuntimeException('مجموعة غير صحيحة.');

        $gd = group_load($g);
        if (empty($gd['items'])) throw new RuntimeException('مفيش أصناف في المجموعة.');

        $prices = group_resolve_prices($gd['items'], true);
        echo json_encode(['ok' => true, 'prices' => $prices], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ===== تسعير جماعي بنسبة (AJAX) — فاتورة واحدة =====
// بيوصل [كود => سعر] محسوب في المتصفح، والسيرفر بيقرّب ويحفظ في حفظة واحدة.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_prices') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!csrf_verify($_POST['csrf'] ?? '')) {
            throw new RuntimeException('انتهت صلاحية الجلسة، اعمل تحديث للصفحة.');
        }
        $d   = (int) ($_POST['distribution_id'] ?? 0);
        $map = json_decode((string) ($_POST['prices'] ?? ''), true);
        if ($d <= 0 || !is_array($map) || !$map) throw new RuntimeException('بيانات ناقصة.');
        if (count($map) > 5000) throw new RuntimeException('عدد الأصناف كبير جدًا.');

        $n = 0;
        $pdo->beginTransaction();
        try {
            foreach ($map as $code => $price) {
                $code = trim((string) $code);
                $price = (float) $price;
                if ($code === '' || $price < 0) continue;
                save_invoice_sale_price($d, $code, mrk_round($price), $uid);
                $n++;
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $ex;
        }
        echo json_encode(['ok' => true, 'count' => $n], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ===== تسعير جماعي بنسبة (AJAX) — مجموعة =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'group_bulk_prices') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!csrf_verify($_POST['csrf'] ?? '')) {
            throw new RuntimeException('انتهت صلاحية الجلسة، اعمل تحديث للصفحة.');
        }
        $g   = (int) ($_POST['group_id'] ?? 0);
        $map = json_decode((string) ($_POST['prices'] ?? ''), true);
        if ($g <= 0 || !is_array($map) || !$map) throw new RuntimeException('بيانات ناقصة.');
        if (count($map) > 5000) throw new RuntimeException('عدد الأصناف كبير جدًا.');

        $clean = [];
        foreach ($map as $code => $price) {
            $code = trim((string) $code);
            $price = (float) $price;
            if ($code === '' || $price < 0) continue;
            $clean[$code] = mrk_round($price);
        }
        $n = group_save_prices_bulk($g, $clean, $uid);
        echo json_encode(['ok' => true, 'count' => $n], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ===== حفظ ملاحظات المجموعة (AJAX) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'group_save_notes') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!csrf_verify($_POST['csrf'] ?? '')) {
            throw new RuntimeException('انتهت صلاحية الجلسة، اعمل تحديث للصفحة.');
        }
        group_save_notes((int) ($_POST['group_id'] ?? 0), (string) ($_POST['notes'] ?? ''));
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ===== حفظ ملاحظات الفاتورة (AJAX) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_notes') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!csrf_verify($_POST['csrf'] ?? '')) {
            throw new RuntimeException('انتهت صلاحية الجلسة، اعمل تحديث للصفحة.');
        }
        $distId = (int) ($_POST['distribution_id'] ?? 0);
        if ($distId <= 0) throw new RuntimeException('فاتورة غير صحيحة.');

        save_invoice_notes($distId, $_POST['notes'] ?? '', $uid);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ===== تغيير حالة الفاتورة يدوي (AJAX) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_status') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!csrf_verify($_POST['csrf'] ?? '')) {
            throw new RuntimeException('انتهت صلاحية الجلسة، اعمل تحديث للصفحة.');
        }
        $distId = (int) ($_POST['distribution_id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        if ($distId <= 0) throw new RuntimeException('فاتورة غير صحيحة.');

        // 🔴 تشغيل تجريبي: بنرجّع الأصناف الناقصة **دلوقتي** من غير ما
        //    نكتب أي حاجة. لازم يفضل قبل أي UPDATE.
        //    بيتنده وقت الضغط مش وقت تحميل الصفحة، عشان جدول التوزيع
        //    بيتحفظ لوحده من غير ريفرش — فأي قايمة محسوبة وقت التحميل
        //    بتبقى قديمة وتحذّر من أصناف اتوزّعت خلاص (أو تسكت عن
        //    أصناف اتصفّرت بعد كده).
        if (!empty($_POST['dry'])) {
            $dryItems = [];
            try {
                $dryItems = sqlsrv_fetch_invoice_items($_POST['invoice_id'] ?? null, $_POST['suffix'] ?? null);
            } catch (Throwable $ex) { $dryItems = []; }
            echo json_encode(
                ['ok' => true, 'pending' => invoice_pending_items($distId, $dryItems)],
                JSON_UNESCAPED_UNICODE
            );
            exit;
        }

        if ($status === 'auto') {
            // رجوع للحساب التلقائي
            clear_invoice_status_manual($distId);
            $invId = $_POST['invoice_id'] ?? null;
            if ($invId !== null && $invId !== '') {
                refresh_invoice_status($distId, $invId, $_POST['suffix'] ?? null, false /* تغيير الحالة اليدوي. السطر ٨٩٢ بينده receiving_open بنفسه */);
            }
            $cur = $pdo->prepare('SELECT status FROM invoice_distribution WHERE id = ?');
            $cur->execute([$distId]);
            $newStatus = (string) $cur->fetchColumn();
            if ($newStatus === 'done') {
                require_once __DIR__ . '/receiving_flow_lib.php';
                receiving_open($pdo, 0, $distId, $uid);
            }
            echo json_encode(['ok' => true, 'status' => $newStatus, 'manual' => false], JSON_UNESCAPED_UNICODE);
        } else {
            if (!set_invoice_status_manual($distId, $status, $uid)) {
                throw new RuntimeException('حالة غير صحيحة.');
            }
            if ($status === 'done') {
                require_once __DIR__ . '/receiving_flow_lib.php';
                receiving_open($pdo, 0, $distId, $uid);
            }
            echo json_encode(['ok' => true, 'status' => $status, 'manual' => true], JSON_UNESCAPED_UNICODE);
        }
    } catch (Throwable $ex) {
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// =====================================================================
//  علامات الصنف (AJAX): لون التمييز · تم التوزيع الفعلي · ملاحظة ·
//                       ينزل/ما ينزلش في ملف الفرع
//  ---------------------------------------------------------------
//  السيرفر هو اللي بيحدد سجلات التوزيع، مش الواجهة:
//    فاتورة مفتوحة  → السجل بتاعها
//    مجموعة مفتوحة → كل فواتير المجموعة اللي فيها الصنف
//                     (نفس group_code_dist_ids بتاعة الأسعار)
//  عشان الملاحظة اللي تتكتب في المجموعة تنزل على الفواتير والعكس.
// =====================================================================

/** سجلات التوزيع بتاعت السياق الحالي (فاتورة أو مجموعة). */
function ifl_ctx_ids(int $distId, int $groupId, string $itemCode = ''): array
{
    if ($groupId > 0) {
        // '__any__' كود مش موجود → الدالة بترجّع كل سجلات المجموعة
        // (نفس الحيلة المستخدمة في group_save_prices_bulk)
        return group_code_dist_ids($groupId, $itemCode !== '' ? $itemCode : '__any__');
    }
    return $distId > 0 ? [$distId] : [];
}

/**
 * تفكيك الأصناف المختارة الجاية من بوب أب «تحديد أصناف».
 * الصيغة JSON: [{"s":sth_id,"f":suffix,"c":item_code}, ...]
 *
 * الهوية = (الفاتورة + الكود) — مش اسم الصنف. المكرر بيتشال هنا،
 * والـ UNIQUE KEY في الداتابيز حزام أمان تاني.
 */
function grp_parse_item_picks($raw): array
{
    $arr = json_decode((string) $raw, true);
    if (!is_array($arr)) return [];
    if (count($arr) > 2000) $arr = array_slice($arr, 0, 2000);

    $out = [];
    foreach ($arr as $p) {
        if (!is_array($p)) continue;
        $sth  = (int) ($p['s'] ?? 0);
        $code = trim((string) ($p['c'] ?? ''));
        if ($sth <= 0 || $code === '') continue;
        $suf  = trim((string) ($p['f'] ?? ''));
        $out[$sth . '|' . $suf . '|' . $code] = [
            'sth_id' => $sth, 'suffix' => $suf, 'item_code' => $code,
        ];
    }
    return array_values($out);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'item_flags_save') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!csrf_verify($_POST['csrf'] ?? '')) {
            throw new RuntimeException('انتهت صلاحية الجلسة، اعمل تحديث للصفحة.');
        }

        // الملف بياخد وقت على الفواتير الكبيرة، والجلسة بتفضل مقفولة طول ما هو شغال
        // فأي حفظ AJAX للمستخدم نفسه بيستنى في الطابور. الجلسة اتقريت خلاص، فنقفلها.
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

        $code = trim((string) ($_POST['item_code'] ?? ''));
        $gid  = (int) ($_POST['group_id'] ?? 0);
        $did  = (int) ($_POST['distribution_id'] ?? 0);
        if ($code === '') throw new RuntimeException('كود الصنف ناقص.');

        // (أ) الفلاج العالمي: ينزل / ما ينزلش في ملف الفرع
        if (array_key_exists('skip_export', $_POST)) {
            ifl_set_skip($code, ((int) $_POST['skip_export']) === 1, $uid);
        }

        // (ب) الفلاجات المرتبطة بالفاتورة
        $fields = [];
        if (array_key_exists('highlight', $_POST))   $fields['highlight']   = (string) $_POST['highlight'];
        if (array_key_exists('done_actual', $_POST)) $fields['done_actual'] = (int) $_POST['done_actual'];
        if (array_key_exists('user_notes', $_POST))  $fields['user_notes']  = (string) $_POST['user_notes'];

        $n = 0;
        if ($fields) {
            $ids = ifl_ctx_ids($did, $gid, $code);
            if (!$ids) throw new RuntimeException('مالقيتش سجل التوزيع بتاع الصنف ده.');
            $n = ifl_save($ids, $code, $fields, $uid);
        }

        echo json_encode(['ok' => true, 'invoices' => $n], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ===== مسح التمييز / مسح علامات التوزيع الفعلي (AJAX) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'item_flags_clear') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!csrf_verify($_POST['csrf'] ?? '')) {
            throw new RuntimeException('انتهت صلاحية الجلسة، اعمل تحديث للصفحة.');
        }
        $what = (string) ($_POST['what'] ?? 'highlight');
        $ids  = ifl_ctx_ids((int) ($_POST['distribution_id'] ?? 0), (int) ($_POST['group_id'] ?? 0));
        if (!$ids) throw new RuntimeException('مفيش فاتورة أو مجموعة مفتوحة.');

        $n = $what === 'done' ? ifl_clear_done($ids) : ifl_clear_highlights($ids);
        echo json_encode(['ok' => true, 'count' => $n], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ===== إضافة صنف لمشاكل الفواتير من الإشعار (AJAX) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_to_problems') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!csrf_verify($_POST['csrf'] ?? '')) {
            throw new RuntimeException('انتهت صلاحية الجلسة، اعمل تحديث للصفحة.');
        }
        $code = trim((string) ($_POST['item_code'] ?? ''));
        $did  = (int) ($_POST['distribution_id'] ?? 0);
        $gid  = (int) ($_POST['group_id'] ?? 0);
        $note = trim((string) ($_POST['note'] ?? ''));
        if ($code === '') throw new RuntimeException('كود الصنف ناقص.');
        if ($did <= 0 && $gid > 0) {
            $ids = ifl_ctx_ids($did, $gid, $code);
            $did = $ids[0] ?? 0;
        }
        if ($did <= 0) throw new RuntimeException('معرّف التوزيع غير موجود.');

        $itemName = trim((string) ($_POST['item_name'] ?? ''));
        if ($itemName === '') {
            $stName = $pdo->prepare('SELECT name FROM hq_codes c JOIN hq_items i ON i.item_id = c.item_id WHERE c.code = ? LIMIT 1');
            $stName->execute([$code]);
            $itemName = (string) $stName->fetchColumn();
        }

        $ins = $pdo->prepare(
            "INSERT INTO invoice_problems (distribution_id, item_code, item_name, source, note, created_by)
             VALUES (?, ?, ?, 'manual', ?, ?)
             ON DUPLICATE KEY UPDATE
                 note = VALUES(note),
                 item_name = COALESCE(NULLIF(VALUES(item_name), ''), item_name),
                 updated_at = NOW()"
        );
        $ins->execute([$did, $code, $itemName ?: null, $note, $uid]);

        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ===== حفظ تلقائي (AJAX): كمية فرع واحد لصنف =====
// ===== الفواتير المجمّعة: إنشاء / مسح =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'group_create') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $_SESSION['flash'] = 'انتهت صلاحية الجلسة، حاول تاني.';
        $_SESSION['flash_type'] = 'error';
    } else {
        try {
            $picks = [];
            foreach (($_POST['pick'] ?? []) as $raw) {
                // الصيغة: sth_id|suffix
                $parts = explode('|', (string) $raw, 2);
                $picks[] = ['sth_id' => (int) $parts[0], 'suffix' => (string) ($parts[1] ?? '')];
            }
            $gid = group_create($picks, $uid, trim((string) ($_POST['group_name'] ?? '')), 'full');
            $_SESSION['flash'] = 'تم فتح مجموعة بـ ' . count(group_invoices($gid)) . ' فاتورة.';
            $_SESSION['flash_type'] = 'success';
            // ندخل على المجموعة الجديدة على طول
            header('Location: invoices.php?tab=group&g=' . $gid);
            exit;
        } catch (Throwable $ex) {
            $_SESSION['flash'] = $ex->getMessage();
            $_SESSION['flash_type'] = 'error';
        }
    }
    header('Location: invoices.php?tab=group');
    exit;
}

// ===== مجموعة أصناف جديدة (أصناف محددة من فواتير مختلفة) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'group_create_items') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $_SESSION['flash'] = 'انتهت صلاحية الجلسة، حاول تاني.';
        $_SESSION['flash_type'] = 'error';
    } else {
        try {
            $gid = group_create_items(
                grp_parse_item_picks($_POST['ipicks'] ?? ''),
                $uid,
                trim((string) ($_POST['group_name'] ?? ''))
            );
            $_SESSION['flash'] = 'تم فتح مجموعة أصناف.';
            $_SESSION['flash_type'] = 'success';
            header('Location: invoices.php?tab=group&g=' . $gid);
            exit;
        } catch (Throwable $ex) {
            $_SESSION['flash'] = $ex->getMessage();
            $_SESSION['flash_type'] = 'error';
        }
    }
    header('Location: invoices.php?tab=group');
    exit;
}

// ===== إضافة فواتير كاملة لمجموعة موجودة =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'group_add_invoices') {
    $gid = (int) ($_POST['group_id'] ?? 0);
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $_SESSION['flash'] = 'انتهت صلاحية الجلسة، حاول تاني.';
        $_SESSION['flash_type'] = 'error';
    } else {
        try {
            $picks = [];
            foreach (($_POST['pick'] ?? []) as $raw) {
                $parts = explode('|', (string) $raw, 2);
                $picks[] = ['sth_id' => (int) $parts[0], 'suffix' => (string) ($parts[1] ?? '')];
            }
            if (!$picks) throw new RuntimeException('ماخترتش أي فاتورة.');

            $n = group_add_invoices($gid, $picks, $uid);
            if ($n > 0) {
                $_SESSION['flash'] = 'اتضاف ' . $n . ' فاتورة للمجموعة.'
                    . (group_is_items($gid) ? ' كل أصناف الفاتورة دخلت المجموعة.' : '');
                $_SESSION['flash_type'] = 'success';
            } else {
                $_SESSION['flash'] = 'مفيش فاتورة جديدة اتضافت — يمكن موجودة أصلاً أو اتلغت من نظام الشركة.';
                $_SESSION['flash_type'] = 'error';
            }
        } catch (Throwable $ex) {
            $_SESSION['flash'] = $ex->getMessage();
            $_SESSION['flash_type'] = 'error';
        }
    }
    header('Location: invoices.php?tab=group&g=' . $gid);
    exit;
}

// ===== إضافة أصناف محددة لمجموعة موجودة =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'group_add_items') {
    $gid = (int) ($_POST['group_id'] ?? 0);
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $_SESSION['flash'] = 'انتهت صلاحية الجلسة، حاول تاني.';
        $_SESSION['flash_type'] = 'error';
    } else {
        try {
            $picks = grp_parse_item_picks($_POST['ipicks'] ?? '');
            if (!$picks) throw new RuntimeException('ماخترتش أي صنف.');

            $r = group_add_items($gid, $picks, $uid);
            $msg = 'اتضاف ' . $r['added'] . ' صنف للمجموعة.';
            if ($r['dup'] > 0) $msg .= ' (' . $r['dup'] . ' كانوا موجودين قبل كده.)';
            if ($r['bad'] > 0) $msg .= ' (' . $r['bad'] . ' مقدرناش نلاقي فاتورتهم.)';
            $_SESSION['flash'] = $msg;
            $_SESSION['flash_type'] = $r['added'] > 0 ? 'success' : 'error';
        } catch (Throwable $ex) {
            $_SESSION['flash'] = $ex->getMessage();
            $_SESSION['flash_type'] = 'error';
        }
    }
    header('Location: invoices.php?tab=group&g=' . $gid);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'group_delete') {
    if (csrf_verify($_POST['csrf'] ?? '')) {
        group_delete((int) ($_POST['group_id'] ?? 0));
        $_SESSION['flash'] = 'تم مسح المجموعة. التوزيع اللي اتعمل محفوظ في الفواتير زي ما هو.';
        $_SESSION['flash_type'] = 'success';
    }
    header('Location: invoices.php?tab=group');
    exit;
}

// ===== شيل فاتورة من المجموعة =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'group_remove_invoice') {
    if (csrf_verify($_POST['csrf'] ?? '')) {
        try {
            $ok = group_remove_invoice(
                (int) ($_POST['group_id'] ?? 0),
                (int) ($_POST['sth_id'] ?? 0),
                (string) ($_POST['suffix'] ?? '')
            );
            $_SESSION['flash'] = $ok
                ? 'اتشالت الفاتورة من المجموعة. توزيعها محفوظ زي ما هو وشغّالة من تاب الفواتير.'
                : 'الفاتورة دي مش في المجموعة أصلاً.';
            $_SESSION['flash_type'] = $ok ? 'success' : 'error';   // مفيش .flash.warn في الـCSS
        } catch (Throwable $ex) {
            $_SESSION['flash'] = $ex->getMessage();
            $_SESSION['flash_type'] = 'error';
        }
    }
    header('Location: invoices.php?tab=group');
    exit;
}

// ===== شيل صنف من مجموعة أصناف =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'group_remove_item') {
    if (csrf_verify($_POST['csrf'] ?? '')) {
        try {
            $code = trim((string) ($_POST['item_code'] ?? ''));
            $n = group_remove_item((int) ($_POST['group_id'] ?? 0), $code);
            $_SESSION['flash'] = $n > 0
                ? 'اتشال الصنف من المجموعة. توزيعه محفوظ لو رجّعته تاني.'
                : 'الصنف ده مش في المجموعة أصلاً.';
            $_SESSION['flash_type'] = $n > 0 ? 'success' : 'error';
        } catch (Throwable $ex) {
            $_SESSION['flash'] = $ex->getMessage();
            $_SESSION['flash_type'] = 'error';
        }
    }
    header('Location: invoices.php?tab=group');
    exit;
}

// ===== حفظ توزيع من الشاشة المجمّعة (شلال على الفواتير) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'group_autosave') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!csrf_verify($_POST['csrf'] ?? '')) {
            throw new RuntimeException('انتهت صلاحية الجلسة، اعمل تحديث للصفحة.');
        }
        $gid      = (int) ($_POST['group_id'] ?? 0);
        $itemCode = trim($_POST['item_code'] ?? '');
        $branchId = (int) ($_POST['branch_id'] ?? 0);
        $qty      = (float) ($_POST['qty'] ?? 0);
        if ($gid <= 0 || $itemCode === '' || $branchId <= 0) {
            throw new RuntimeException('بيانات ناقصة.');
        }

        // مصادر الصنف من المجموعة (السيرفر هو المرجع، مش الواجهة)
        $g = group_load($gid);
        $sources = [];
        foreach ($g['items'] as $it) {
            if ((string) $it['item_code'] === $itemCode) { $sources = $it['sources']; break; }
        }
        if (!$sources) throw new RuntimeException('الصنف مش موجود في المجموعة.');

        $res = group_set_distribution($sources, $itemCode, $branchId, $qty, $uid);

        // إجمالي الفرع في المجموعة كلها + تفصيل لكل فاتورة
        // (بنرجّعهم كاملين عشان الواجهة تعيد بناء أزرار التحميل لحظيًا —
        //  من غير كده الفاتورة اللي اتوزّعلها دلوقتي بتفضل مكتوب عليها
        //  "لسه ماتوزّعش منها حاجة" لحد ما المستخدم يعمل ريفريش.)
        $groupTotals   = [];
        $invoiceTotals = [];
        $q = $pdo->prepare(
            'SELECT branch_id, SUM(amount) AS t FROM invoice_distribution_lines
             WHERE distribution_id = ? GROUP BY branch_id HAVING SUM(amount) > 0'
        );
        foreach ($g['dist_ids'] as $sth => $did) {
            $q->execute([(int) $did]);
            foreach ($q->fetchAll() as $r) {
                $bid = (int) $r['branch_id'];
                $t   = (float) $r['t'];
                $invoiceTotals[(string) $sth][(string) $bid] = $t;
                $groupTotals[(string) $bid] = ($groupTotals[(string) $bid] ?? 0) + $t;
            }
        }
        $branchTotal = (float) ($groupTotals[(string) $branchId] ?? 0);

        // حالة كل فاتورة تتحدّث (نفس منطق الفاتورة الواحدة)
        group_refresh_statuses($gid);

        echo json_encode([
            'ok'             => true,
            'remaining'      => $res['remaining'],
            'branch_total'   => $branchTotal,
            'split'          => $res['split'],
            'group_totals'   => (object) $groupTotals,
            'invoice_totals' => (object) $invoiceTotals,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'autosave') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!csrf_verify($_POST['csrf'] ?? '')) {
            throw new RuntimeException('انتهت صلاحية الجلسة، اعمل تحديث للصفحة.');
        }
        $distId   = (int) ($_POST['distribution_id'] ?? 0);
        $itemCode = trim($_POST['item_code'] ?? '');
        $itemQty  = (float) ($_POST['item_qty'] ?? 0);
        $branchId = (int) ($_POST['branch_id'] ?? 0);
        $qty      = (float) ($_POST['qty'] ?? 0);
        if ($distId <= 0 || $itemCode === '' || $branchId <= 0) {
            throw new RuntimeException('بيانات ناقصة.');
        }
        $res = set_branch_distribution($distId, $itemCode, $itemQty, $branchId, $qty, $uid);
        // إجمالي كميات الفرع الكلي في التوزيعة (لتحديث زرار التحميل)
        $bt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM invoice_distribution_lines WHERE distribution_id=? AND branch_id=?');
        $bt->execute([$distId, $branchId]);
        $branchTotal = (float) $bt->fetchColumn();

        // تحديث حالة الفاتورة (مخزّنة عشان القائمة تفتح بسرعة)
        $invId = $_POST['invoice_id'] ?? null;
        if ($invId !== null && $invId !== '') {
            refresh_invoice_status($distId, $invId, $_POST['suffix'] ?? null, false /* autosave = حفظ كمية، مش إرسال */);
        }

        echo json_encode(['ok' => true, 'remaining' => $res['remaining'], 'branch_total' => $branchTotal], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reservation_pull_preview') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!csrf_verify($_POST['csrf'] ?? '')) {
            throw new RuntimeException('انتهت صلاحية الجلسة، اعمل تحديث للصفحة.');
        }
        $distId = (int) ($_POST['distribution_id'] ?? 0);
        $invId  = $_POST['invoice_id'] ?? null;
        $suffix = $_POST['suffix'] ?? null;
        if ($distId <= 0 || $invId === null || $invId === '') {
            throw new RuntimeException('بيانات ناقصة.');
        }
        $rawItems = sqlsrv_fetch_invoice_items($invId, $suffix);
        $items = merge_invoice_items($rawItems);
        $preview = reservation_pull_preview($distId, $items);
        echo json_encode(['ok' => true, 'items' => $preview['items'], 'total_candidates' => $preview['total_candidates']], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reservation_pull_apply') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!csrf_verify($_POST['csrf'] ?? '')) {
            throw new RuntimeException('انتهت صلاحية الجلسة، اعمل تحديث للصفحة.');
        }
        $distId    = (int) ($_POST['distribution_id'] ?? 0);
        $invoiceNo = trim($_POST['invoice_no'] ?? '');
        $invId     = $_POST['invoice_id'] ?? null;
        $suffix    = $_POST['suffix'] ?? null;
        $allocRaw  = $_POST['alloc'] ?? '[]';
        $alloc     = is_array($allocRaw) ? $allocRaw : json_decode($allocRaw, true);
        if (!is_array($alloc)) $alloc = [];

        if ($distId <= 0 || $invId === null || $invId === '') {
            throw new RuntimeException('بيانات ناقصة.');
        }

        $rawItems = sqlsrv_fetch_invoice_items($invId, $suffix);
        $items = merge_invoice_items($rawItems);
        $itemQty      = [];
        $codeByItemId = [];
        foreach ($items as $it) {
            $itemQty[(string) $it['item_code']] = (float) ($it['quantity'] ?? 0);
            $iid = (int) ($it['item_id'] ?? 0);
            if ($iid > 0) $codeByItemId[$iid] = (string) $it['item_code'];
        }

        $res = reservation_pull_apply($distId, $invoiceNo, $itemQty, $alloc, $uid, $codeByItemId);
        echo json_encode(['ok' => true, 'allocated_count' => $res['allocated_count']], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ===== تحديد العرض: قائمة أو فاتورة مفتوحة =====
$openInvoiceId = $_GET['invoice'] ?? null;
$openSuffix    = $_GET['suffix'] ?? null;
$openCb        = isset($_GET['cb']) && $_GET['cb'] !== '' ? (int) $_GET['cb'] : null;
$openDt        = isset($_GET['dt']) && $_GET['dt'] !== '' ? (int) $_GET['dt'] : null;
$sqlsrvError = '';
$invoiceGroups = [];
$openInvoice = null;
$items = [];
$distId = 0;

try {
    if ($openInvoiceId !== null) {
        // فتح فاتورة محددة (بالـ suffix بتاع الشهر والفرع ونوع المستند)
        $openInvoice = sqlsrv_fetch_invoice_header($openInvoiceId, $openSuffix, $openCb, $openDt);
        if ($openInvoice) {
            // ضمان وجود الفرع ونوع المستند في الهيدر
            if ($openCb !== null && !isset($openInvoice['company_branch'])) {
                $openInvoice['company_branch'] = $openCb;
            }
            if ($openDt !== null && !isset($openInvoice['doc_type'])) {
                $openInvoice['doc_type'] = $openDt;
            }
            // الصنف المكرر في أكتر من سطر بيتجمع في صف واحد — التوزيع
            // متخزّن بمفتاح الكود مش السطر. (شوف merge_invoice_items)
            $items = merge_invoice_items(sqlsrv_fetch_invoice_items($openInvoiceId, $openSuffix));
            $distRecord = ensure_distribution_record($openInvoice, $uid);
            $distId = (int) $distRecord['id'];
            // إجمالي ما اتوزّع لكل فرع (لإظهار أزرار التحميل)
            $bq = $pdo->prepare(
                'SELECT branch_id, SUM(amount) AS total FROM invoice_distribution_lines
                 WHERE distribution_id = ? GROUP BY branch_id HAVING SUM(amount) > 0'
            );
            $bq->execute([$distId]);
            $branchTotals = [];
            foreach ($bq as $r) $branchTotals[(int)$r['branch_id']] = (float)$r['total'];

            // ⚠️ الرقم اللي على الزرار لازم يطابق اللي هينزل في الملف —
            //    فبنطرح الأصناف المعلّمة «لا ينزل في التقرير» والأصناف
            //    اللي اتشالت من فاتورة الشركة (سطر توزيع يتيم).
            $exclCodes = ifl_skip_map(array_map(fn($i) => (string)($i['item_code'] ?? ''), $items));
            $liveCodes = [];
            foreach ($items as $i) $liveCodes[(string)($i['item_code'] ?? '')] = true;

            $dq = $pdo->prepare(
                'SELECT branch_id, notes AS item_code, SUM(amount) AS qty
                 FROM invoice_distribution_lines WHERE distribution_id = ?
                 GROUP BY branch_id, notes HAVING SUM(amount) > 0'
            );
            $dq->execute([$distId]);
            $orphanCodes = [];
            $distByItem = [];
            foreach ($dq as $r) {
                $c = (string) $r['item_code'];
                $distByItem[$c] = ($distByItem[$c] ?? 0.0) + (float)$r['qty'];
                $isOrphan = $liveCodes && !isset($liveCodes[$c]);
                if ($isOrphan) $orphanCodes[$c] = true;
                if (isset($exclCodes[$c]) || $isOrphan) {
                    $b = (int) $r['branch_id'];
                    $branchTotals[$b] = max(0, ($branchTotals[$b] ?? 0) - (float) $r['qty']);
                    if ($branchTotals[$b] <= 0) unset($branchTotals[$b]);
                }
            }

            // حصر الأصناف التي لم تكتمل في التوزيع للفاتورة الحالية
            $pendingItems = [];
            foreach ($items as $it) {
                $code = (string) ($it['item_code'] ?? '');
                $req  = (float) ($it['quantity'] ?? 0);
                $done = (float) ($distByItem[$code] ?? 0);
                if ($done < $req - 0.0001) {
                    $pendingItems[] = [
                        'code' => $code,
                        'name' => (string) ($it['item_name'] ?? $code),
                        'done' => $done,
                        'qty'  => $req,
                    ];
                }
            }

            // أسعار البيع: المحفوظ عندنا، والناقص يتجاب من السيستم ويتخزّن
            $salePrices = resolve_invoice_sale_prices($distId, $items);

            // علامات الأصناف (ملاحظة/لون/تم التوزيع الفعلي) + الممنوع
            // من ملف الفرع — استعلامين إجمالاً مهما كان عدد الأصناف.
            $iflDistIds = [$distId];
            $itemFlags  = ifl_load($iflDistIds);
            $skipMap    = ifl_skip_map(array_map(fn($i) => (string) ($i['item_code'] ?? ''), $items));

            // أرصدة كل المخازن (قراءة فقط — استعلام واحد لكل الأصناف)
            $allBalances = sqlsrv_fetch_item_balances_all(
                array_map(fn($i) => (int)($i['item_id'] ?? 0), $items)
            );
            $storeNames = sqlsrv_store_names();
            // رصيد الإدارة للعرض في العمود
            $itemBalances = [];
            foreach ($allBalances as $iid => $byStore) {
                $itemBalances[$iid] = $byStore[ADMIN_STO_ID] ?? 0;
            }
            // أرصدة اشراقة — من MySQL بالباركود (نظامهم مختلف تماماً)
            $ashraqaBalances = ashraqa_fetch_balances(
                array_map(fn($i) => (string)($i['item_code'] ?? ''), $items)
            );
            $ashraqaSync = ashraqa_last_sync();
        }
    } elseif ($tab === 'group') {
        // ===== تاب الفواتير المجمّعة =====
        // ?g=ID → شاشة الشغل على مجموعة معيّنة · بدونه → كروت المجموعات
        $grpOpenId  = (int) ($_GET['g'] ?? 0);
        $grpCurrent = $grpOpenId > 0 ? group_get($grpOpenId) : null;
        $grpData    = null;
        $grpCards   = [];
        $grpUnposted = [];
        $grpPosted   = [];

        if ($grpCurrent) {
            $grpData = group_load((int) $grpCurrent['id']);
            // قايمة الفواتير للبوب أب (إضافة فاتورة / إضافة أصناف)
            $grpUnposted = sqlsrv_fetch_unposted_invoices();
            $grpPosted   = sqlsrv_fetch_invoices(2026);
            // الأصناف: أسعار البيع + الأرصدة (نفس الفاتورة الواحدة)
            if ($grpData['items']) {
                $grpItemIds = array_map(fn($i) => (int) $i['item_id'], $grpData['items']);
                $allBalances = sqlsrv_fetch_item_balances_all($grpItemIds);
                $itemBalances = [];
                foreach ($allBalances as $iid => $byStore) {
                    $itemBalances[$iid] = $byStore[ADMIN_STO_ID] ?? 0;
                }
                $ashraqaBalances = ashraqa_fetch_balances(
                    array_map(fn($i) => (string) $i['item_code'], $grpData['items'])
                );
                $ashraqaSync = ashraqa_last_sync();
                // أسعار البيع لكل أصناف المجموعة — القراءة من أقدم فاتورة
                // فيها الصنف، والسعر بيتنشر على باقي فواتير المجموعة.
                $salePrices = group_resolve_prices($grpData['items']);

                // علامات الأصناف — نفس قاعدة الأسعار: الأقدم هو المرجع.
                // dist_ids جاية مرتّبة بترتيب فواتير المجموعة (الأقدم أولاً).
                $iflDistIds = array_values($grpData['dist_ids']);
                $itemFlags  = ifl_load($iflDistIds);
                $skipMap    = ifl_skip_map(
                    array_map(fn($i) => (string) $i['item_code'], $grpData['items'])
                );
            }
        } else {
            // شاشة الكروت + قائمة الاختيار لمجموعة جديدة
            $grpCards    = group_list();
            $grpUnposted = sqlsrv_fetch_unposted_invoices();
            $grpPosted   = sqlsrv_fetch_invoices(2026);
        }
    } elseif ($tab === 'discount') {
        // ===== تاب حساب الخصم: بوب أب اختيار + حساب للفاتورة المختارة =====
        // الفواتير غير المحفوظة الأول (دي اللي بتتعمل عليها الشغل غالبًا)
        $discUnposted = sqlsrv_fetch_unposted_invoices();
        $discPosted   = sqlsrv_fetch_invoices(2026);
        $invoices     = array_merge($discUnposted, $discPosted);

        $discInvId  = $_GET['dinv'] ?? null;
        $discSuffix = $_GET['dsuf'] ?? null;

        // "الاجمالي بعد" اليدوي (زي خانة الإكسيل) — لو فاضي، صافي الفاتورة
        $discNetRaw      = trim((string) ($_GET['dnet'] ?? ''));
        $discNetOverride = ($discNetRaw !== '' && is_numeric($discNetRaw) && (float)$discNetRaw > 0)
            ? (float) $discNetRaw : null;

        // نسب خصم لكل صنف: بتيجي في البارامتر dpct بصيغة "كود:نسبة,كود:نسبة".
        // الصنف اللي ليه نسبة هنا بيتحسب بيها ويتجاهل خصم الفاتورة الكلي.
        $discItemPct = [];
        $dpctRaw = trim((string) ($_GET['dpct'] ?? ''));
        if ($dpctRaw !== '') {
            foreach (explode(',', $dpctRaw) as $pair) {
                $pair = trim($pair);
                if ($pair === '' || strpos($pair, ':') === false) continue;
                [$c, $p] = explode(':', $pair, 2);
                $c = trim($c);
                $p = (float) $p;
                if ($c !== '' && $p > 0 && $p <= 100) $discItemPct[$c] = $p;
            }
        }

        if ($discInvId !== null && $discInvId !== '') {
            $discHeader = sqlsrv_fetch_invoice_header($discInvId, $discSuffix);
            if ($discHeader) {
                // ⚠️ خام بدون دمج عن قصد: الخصم بيتحسب سطر سطر،
                //    والسطر المكرر ممكن يكون بسعر مختلف.
                $discItems = sqlsrv_fetch_invoice_items($discInvId, $discSuffix);
                $discCalc  = calc_invoice_discount($discHeader, $discItems, $discNetOverride, $discItemPct);
            }
        }
    } else {
        // قائمة الفواتير مجمّعة
        if ($tab === 'unposted') {
            $invoices = sqlsrv_fetch_unposted_invoices();
        } else {
            $invoices = sqlsrv_fetch_invoices(2026);
        }
        // تجميع بالفرع: الإدارة فوق، بعدها الفروع
        $invoiceGroups = group_invoices_by_branch($invoices);

        // حالة كل فاتورة — استعلام واحد على MySQL (مخزّنة مسبقًا).
        // قبل كده كنا بنجيب أصناف كل فاتورة من SQL Server عشان نحسب،
        // يعني استعلام لكل فاتورة عبر الجسر — ده اللي كان بيبطّئ الصفحة.
        $invStatuses = get_invoice_statuses(array_map(
            fn($i) => (string) ($i['inv_no'] ?? ''), $invoices
        ));
        // الفواتير اللي عليها ملاحظات — علامة في القائمة
        $invNotes = get_invoices_with_notes(array_map(
            fn($i) => (string) ($i['inv_no'] ?? ''), $invoices
        ));
    }
} catch (Throwable $ex) {
    $sqlsrvError = $ex->getMessage();
}

$page_title    = 'الاستلامات';
$active_module = 'invoices';
require_once __DIR__ . '/includes/header.php';
?>

<div style="display:flex;justify-content:flex-end;margin-bottom:10px;gap:8px;">
    <?php if (can('invoices:problems')): ?>
    <a href="invoice_problems.php" class="btn btn-sm"
       title="الأصناف التي بها مشاكل أو فروقات في الفواتير">
        <i class="ti ti-alert-circle"></i> مشاكل الفواتير
    </a>
    <?php endif; ?>
    <?php /* فواتير الفروع بقت موديول لوحده (branch_invoices.php) — مالهاش زراير هنا */ ?>
</div>

<!-- إشعار إضافة الصنف لمشاكل الفواتير -->
<div id="problemToast" class="problem-toast" style="display:none;">
    <div class="problem-toast-inner">
        <span class="problem-toast-text">
            <i class="ti ti-alert-circle"></i> تحب تضيف الصنف ده في مشاكل الفواتير؟
            <span id="problemToastItem" style="font-weight:bold;margin-right:4px;"></span>
        </span>
        <div class="problem-toast-actions">
            <button type="button" class="btn btn-sm" id="problemToastYes" style="background:var(--accent);color:#fff;">أيوه</button>
            <button type="button" class="btn btn-sm btn-ghost" id="problemToastNo" style="background:var(--surface);color:var(--text);border:1px solid var(--line);">لأ</button>
        </div>
    </div>
</div>

<style>
.problem-toast {
    position: fixed;
    top: 24px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 99999;
    background: var(--surface);
    color: var(--text);
    border: 1.5px solid var(--accent);
    box-shadow: 0 6px 24px rgba(0,0,0,0.15);
    border-radius: var(--r);
    padding: 12px 20px;
    animation: problemToastIn 0.2s ease;
}
@keyframes problemToastIn {
    from { opacity: 0; transform: translate(-50%, -10px); }
    to { opacity: 1; transform: translate(-50%, 0); }
}
.problem-toast-inner {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}
.problem-toast-text {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 14px;
}
.problem-toast-actions {
    display: flex;
    gap: 6px;
}
</style>

<!-- ================= تتبّع الصنف (سيرش بار الهيدر) ================= -->
<div class="ilk-bar">
    <i class="ti ti-search"></i>
    <div class="ilk-wrap">
        <input type="text" id="ilkInput" autocomplete="off"
               placeholder="دوّر على صنف بالكود أو الاسم — يوريك نزل في أنهي فواتير وتوزيعته…">
        <div class="ilk-sugg" id="ilkSugg"></div>
    </div>
</div>

<!-- المودال -->
<div class="ilk-modal" id="ilkModal">
    <div class="ilk-box">
        <header>
            <div style="flex:1;min-width:0;">
                <div id="ilkTitle" style="font-size:15px;font-weight:600;"></div>
                <div id="ilkSub" class="ilk-muted" style="margin-top:3px;"></div>
            </div>
            <button class="ilk-x" id="ilkClose">✕</button>
        </header>
        <div id="ilkSummary" class="ilk-summary"></div>
        <div class="ilk-body" id="ilkBody"></div>
    </div>
</div>

<style>
    .ilk-bar { background:var(--surface); border-radius:12px; padding:11px 15px; margin-bottom:16px;
        display:flex; gap:10px; align-items:center;
        box-shadow:0 2px 10px rgba(124,111,247,0.06); }
    .ilk-bar > i { color:var(--ink-500); font-size:18px; }
    .ilk-wrap { position:relative; flex:1; }
    .ilk-wrap input { width:100%; box-sizing:border-box; padding:9px 11px;
        border:1.5px solid var(--line); border-radius:9px; font-family:inherit; font-size:13.5px; }
    .ilk-wrap input:focus { outline:none; border-color:var(--accent-text); }
    .ilk-sugg { position:absolute; top:calc(100% + 5px); right:0; left:0; z-index:60;
        background:var(--surface); border:1.5px solid var(--line); border-radius:10px;
        max-height:330px; overflow-y:auto; box-shadow:0 8px 24px rgba(0,0,0,0.13); display:none; }
    .ilk-sugg div.ilk-s { padding:9px 12px; cursor:pointer; border-bottom:1px solid var(--line-soft); font-size:13px; }
    .ilk-sugg div.ilk-s:last-child { border-bottom:none; }
    .ilk-sugg div.ilk-s:hover { background:var(--bg); }
    .ilk-muted { color:var(--ink-500); font-size:12px; }

    .ilk-modal { position:fixed; inset:0; background:rgba(20,16,50,0.55); z-index:300;
        display:none; align-items:center; justify-content:center; padding:16px; }
    .ilk-modal.on { display:flex; }
    .ilk-box { background:var(--surface); border-radius:14px; width:min(900px,100%); max-height:88vh;
        display:flex; flex-direction:column; overflow:hidden; }
    .ilk-box header { padding:14px 18px; border-bottom:1px solid var(--line);
        display:flex; gap:10px; align-items:flex-start; }
    .ilk-x { background:none; border:none; font-size:20px; cursor:pointer; color:var(--ink-500); }
    .ilk-summary { padding:11px 18px; background:var(--bg); display:flex; gap:18px;
        flex-wrap:wrap; font-size:13px; border-bottom:1px solid var(--line); }
    .ilk-summary b { color:var(--ink-900); }
    .ilk-body { overflow-y:auto; padding:12px 18px 18px; }

    .ilk-inv { border:1.5px solid var(--line); border-radius:11px; padding:12px 14px; margin-bottom:10px; }
    .ilk-inv.ilk-ret { border-color:var(--neg); background:var(--surface); }
    .ilk-top { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .ilk-tag { padding:2px 10px; border-radius:20px; font-size:11px; font-weight:700; color:#fff; background:var(--accent); }
    .ilk-tag.ret { background:var(--neg-fill); }
    .ilk-chips { display:flex; gap:6px; flex-wrap:wrap; margin-top:9px; }
    .ilk-chip { background:var(--bg); border:1px solid var(--line); border-radius:8px;
        padding:4px 10px; font-size:12.5px; }
    .ilk-chip b { color:var(--accent-hover,var(--accent)); }
    .ilk-chip.store { background:var(--line-soft); border-color:var(--warn); }
    .ilk-none { color:var(--ink-500); font-size:12.5px; margin-top:8px; }
</style>

<script>
(function () {
    'use strict';
    const $ = id => document.getElementById(id);
    const inp = $('ilkInput'), sugg = $('ilkSugg'), modal = $('ilkModal');
    if (!inp) return;

    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g,
        c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const num = v => (Math.round(v * 1000) / 1000).toLocaleString('en-US');
    const money = v => Number(v || 0).toFixed(2);

    async function api(action, qs) {
        const res = await fetch('invoices.php?ilk=' + action + '&' + qs);
        const d = await res.json().catch(() => ({ ok:false, error:'رد غير مفهوم.' }));
        if (!d.ok) throw new Error(d.error || 'حصل خطأ.');
        return d;
    }

    // ---- الاقتراحات ----
    let t = null;
    inp.addEventListener('input', function () {
        clearTimeout(t);
        const term = inp.value.trim();
        if (term.length < 2) { sugg.style.display = 'none'; return; }
        t = setTimeout(async () => {
            try {
                const d = await api('search', 'term=' + encodeURIComponent(term));
                sugg.innerHTML = d.rows.length ? d.rows.map(r =>
                    '<div class="ilk-s" data-code="' + esc(r.code) + '" data-item="' + r.item_id + '">' +
                    '<b>' + esc(r.name || '—') + '</b><br><span class="ilk-muted">' +
                    esc(r.code) + ' · بيع ' + money(r.price) + ' · تكلفة ' + money(r.cost) +
                    '</span></div>').join('')
                    : '<div class="ilk-s ilk-muted" style="cursor:default;">مفيش نتائج</div>';
                sugg.style.display = 'block';
            } catch (e) {
                sugg.innerHTML = '<div class="ilk-s" style="color:var(--neg);cursor:default;">' + esc(e.message) + '</div>';
                sugg.style.display = 'block';
            }
        }, 300);
    });

    // Enter = افتح أول نتيجة
    inp.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Enter') return;
        ev.preventDefault();
        const first = sugg.querySelector('[data-code]');
        if (first) first.click();
    });

    sugg.addEventListener('click', function (ev) {
        const d = ev.target.closest('[data-code]');
        if (!d) return;
        sugg.style.display = 'none';
        open(d.dataset.code, parseInt(d.dataset.item, 10) || 0);
    });

    document.addEventListener('click', function (ev) {
        if (!ev.target.closest('.ilk-wrap')) sugg.style.display = 'none';
    });

    // ---- المودال ----
    $('ilkClose').addEventListener('click', () => modal.classList.remove('on'));
    modal.addEventListener('click', ev => { if (ev.target === modal) modal.classList.remove('on'); });
    document.addEventListener('keydown', ev => { if (ev.key === 'Escape') modal.classList.remove('on'); });

    async function open(code, itemId) {
        modal.classList.add('on');
        $('ilkTitle').textContent = 'بيحمّل…';
        $('ilkSub').textContent = '';
        $('ilkSummary').innerHTML = '';
        $('ilkBody').innerHTML = '<div class="ilk-muted" style="padding:24px;text-align:center;">بيدوّر في الفواتير…</div>';

        try {
            const d = await api('history', 'code=' + encodeURIComponent(code) + '&item_id=' + itemId);
            const it = d.item || {};
            $('ilkTitle').textContent = it.name || code;
            $('ilkSub').textContent = code + (it.item_id ? ' · ITEM_ID ' + it.item_id : '')
                + (it.price ? ' · سعر البيع ' + money(it.price) : '');

            if (!d.rows.length) {
                $('ilkSummary').innerHTML = '';
                $('ilkBody').innerHTML = '<div class="ilk-muted" style="padding:30px;text-align:center;">'
                    + 'الصنف ده مانزلش في أي فاتورة شراء أو مردود في المرآة.</div>';
                return;
            }

            const tt = d.totals;
            $('ilkSummary').innerHTML =
                '<span>فواتير: <b>' + tt.invoices + '</b></span>' +
                '<span>مشتريات: <b>' + num(tt.bought) + '</b></span>' +
                (tt.returned ? '<span>مردود: <b style="color:var(--neg);">' + num(tt.returned) + '</b></span>' : '') +
                '<span>الصافي: <b>' + num(tt.net) + '</b></span>' +
                '<span>اتوزّع: <b>' + num(tt.distributed) + '</b></span>' +
                (d.truncated ? '<span class="ilk-muted">(أحدث 50 فاتورة)</span>' : '');

            $('ilkBody').innerHTML = d.rows.map(r => {
                const chips = r.dist.length
                    ? r.dist.map(b => '<span class="ilk-chip">' + esc(b.name) + ' <b>' + num(b.qty) + '</b></span>').join('')
                      + (r.in_store > 0.0001 ? '<span class="ilk-chip store">مخزن <b>' + num(r.in_store) + '</b></span>' : '')
                    : '';
                return '<div class="ilk-inv' + (r.is_return ? ' ilk-ret' : '') + '">' +
                    '<div class="ilk-top">' +
                        '<span class="ilk-tag' + (r.is_return ? ' ret' : '') + '">' + esc(r.type_label) + '</span>' +
                        '<a class="inv-link" href="invoices.php?invoice=' + encodeURIComponent(r.sth_id) +
                            '&suffix=' + encodeURIComponent(r.suffix) +
                            (r.company_branch ? '&cb=' + encodeURIComponent(r.company_branch) : '') +
                            (r.doc_type ? '&dt=' + encodeURIComponent(r.doc_type) : '') + '">' + esc(r.doc_no) + '</a>' +
                        '<span class="ilk-muted">' + esc(r.date ? r.date.substring(0, 10) : '') + '</span>' +
                        '<span style="flex:1;min-width:60px;"></span>' +
                        '<span>كمية <b>' + num(r.qty) + '</b></span>' +
                        '<span class="ilk-muted">سعر ' + money(r.unit_price) + '</span>' +
                    '</div>' +
                    '<div class="ilk-muted" style="margin-top:5px;">' + esc(r.supplier || '—') + '</div>' +
                    (chips ? '<div class="ilk-chips">' + chips + '</div>'
                           : '<div class="ilk-none">لسه ما اتوزّعش على الفروع</div>') +
                '</div>';
            }).join('');
        } catch (e) {
            $('ilkTitle').textContent = 'خطأ';
            $('ilkBody').innerHTML = '<div style="padding:24px;text-align:center;color:var(--neg);">' + esc(e.message) + '</div>';
        }
    }
})();
</script>

<style>
    /* دوران أيقونة زرار "تحديث الأسعار" وقت الجلب */
    @keyframes ti-spin { to { transform: rotate(360deg); } }
    .ti-spin { display:inline-block; animation: ti-spin 0.9s linear infinite; }
    .flash { padding:12px 16px; border-radius:10px; margin-bottom:18px; font-size:14px; }
    .flash.success { background:var(--pos-soft); color:var(--pos); }
    .flash.error   { background:var(--neg-soft); color:var(--neg); }
    .card { background:var(--surface); border-radius:14px; padding:20px; box-shadow:0 2px 10px rgba(124,111,247,0.06); margin-bottom:18px; }
    .section-title { font-size:16px; color:var(--ink-900); font-weight:600; margin-bottom:14px; }
    .sqlsrv-warn { background:var(--warn-soft); color:var(--warn); padding:16px; border-radius:12px; font-size:14px; line-height:1.7; }
    /* تحذير: الفاتورة موجودة محلي بس بنودها ماوصلتش من المزامنة */
    .lines-missing { display:flex; gap:12px; align-items:flex-start;
        background:var(--warn-soft); color:var(--warn); padding:16px 18px;
        border-radius:12px; font-size:14px; line-height:1.8; margin:14px 0; }
    .lines-missing i { font-size:22px; flex-shrink:0; margin-top:2px; }
    .lines-missing .lm-time { font-size:12.5px; opacity:0.85; }
    table { width:100%; border-collapse:collapse; }
    th, td { padding:11px 13px; text-align:right; font-size:14px; border-bottom:1px solid var(--line); }
    th { color:var(--ink-500); font-weight:600; font-size:13px; }
    td { color:var(--ink-800); }
    .inv-link { color:var(--accent-text); font-weight:600; text-decoration:none; }
    .inv-link:hover { text-decoration:underline; }
    /* جدول الفواتير - الصف كله قابل للضغط */
    /* ===== شريط أدوات القائمة ===== */
    .list-head { display:flex; justify-content:space-between; align-items:center;
        gap:14px; flex-wrap:wrap; margin-bottom:14px; }
    .list-count { display:inline-block; margin-right:8px; padding:2px 9px; border-radius:20px;
        background:var(--line-soft); color:var(--ink-500); font-size:12px; font-weight:600; }
    .list-search { position:relative; flex:0 1 320px; min-width:200px; }
    .list-search i { position:absolute; right:12px; top:50%; transform:translateY(-50%);
        color:var(--ink-400); font-size:17px; pointer-events:none; }
    .list-search input { width:100%; padding:9px 36px 9px 30px; font-size:13.5px; }
    .ls-clear { position:absolute; left:8px; top:50%; transform:translateY(-50%);
        background:none; border:none; font-size:20px; line-height:1; color:var(--ink-400); cursor:pointer; }
    .ls-clear:hover { color:var(--ink-700); }

    .scope-filters { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
    .scope-btn { display:inline-flex; align-items:center; gap:7px; padding:7px 14px;
        border:1px solid var(--line); border-radius:20px; background:var(--surface);
        color:var(--ink-600); font-family:inherit; font-size:13px; font-weight:600;
        cursor:pointer; transition:all .15s; }
    .scope-btn:hover { border-color:var(--accent); color:var(--accent-text); }
    .scope-btn span { display:inline-block; min-width:20px; padding:1px 6px; border-radius:20px;
        background:var(--line-soft); color:var(--ink-500); font-size:11.5px; font-weight:700; }
    .scope-btn.active { background:var(--accent); border-color:var(--accent); color:#fff; }
    .scope-btn.active span { background:rgba(255,255,255,0.25); color:#fff; }

    .status-filters { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
    .sf-btn { display:inline-flex; align-items:center; gap:7px; padding:7px 14px;
        border:1px solid var(--line); border-radius:20px; background:var(--surface);
        color:var(--ink-600); font-family:inherit; font-size:13px; font-weight:500;
        cursor:pointer; transition:all .15s; }
    .sf-btn:hover { border-color:var(--ink-300); color:var(--ink-800); }
    .sf-btn span { display:inline-block; min-width:20px; padding:1px 6px; border-radius:20px;
        background:var(--line-soft); color:var(--ink-500); font-size:11.5px; font-weight:700; }
    .sf-btn.active { background:var(--fill-strong); border-color:var(--fill-strong); color:var(--fill-strong-fg); }
    .sf-btn.active span { background:var(--fill-strong-veil); color:var(--fill-strong-fg); }
    .no-match { padding:26px; text-align:center; color:var(--ink-400); }

    /* 🔴 `fixed` مش رفاهية: من غيرها المتصفح بيقيس كل خانة ظاهرة عشان
           يحسب عرض الأعمدة — ومع ٢٠٠٠ فاتورة × ٨ أعمدة الفرد كان بياخد
           ثانية كاملة. مع `fixed` الأعمدة بتتحدد من `<colgroup>` تحت،
           والمتصفح مابيقيسش ولا خانة.
       ⚠️ يعني الأعمدة مابتتوسّعش مع المحتوى — أي عمود جديد لازم
          ياخد عرض في الـ`<colgroup>` وإلا هيطلع ضيّق. */
    .inv-table { width:100%; border-collapse:collapse; table-layout:fixed; }
    .inv-table .inv-supplier { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

    /* 🔴 الصف ممكن يتخفي لـ٣ أسباب مستقلة: الفرع مطوي · المجموعة الفرعية
           مطوية · الفلتر مخفيه. كل سبب خاصية لوحده، والمتصفح بيجمّعهم.
           قبل كده الجافاسكربت كان بيقرا التلاتة ويحسب `style.display`
           لكل صف — شغل زيادة على ٢٠٠٠ صف في كل فرد. */
    .inv-table tr.inv-row[data-branch-collapsed],
    .inv-table tr.inv-row[data-sub-collapsed],
    .inv-table tr.inv-row[data-filter-hidden] { display: none; }
    .inv-table thead th { background:var(--line-soft); color:var(--accent-text); font-weight:600; padding:11px 12px; text-align:center; border-bottom:2px solid var(--line); white-space:nowrap; }
    .inv-table tbody td { padding:11px 12px; text-align:center; border-bottom:1px solid var(--line-soft); }
    .inv-table .inv-row { cursor:pointer; transition:background 0.12s; }
    .inv-table .inv-row:hover { background:var(--accent-soft); }
    .inv-table .inv-no { font-weight:700; color:var(--accent-text); }
    /* بادج نوع المستند (مردود شراء) جنب رقم الفاتورة */
    .doc-badge { display:inline-block; font-size:11px; font-weight:600; padding:2px 8px;
                 border-radius:20px; margin-right:6px; white-space:nowrap; vertical-align:middle; }
    .doc-badge.refund { background:var(--neg-soft); color:var(--neg); }
    /* رأس الفرع داخل قائمة الفواتير */
    .inv-table .branch-head td {
        background:var(--accent-soft); color:var(--accent-text); font-weight:700;
        font-size:14px; text-align:right; padding:12px 14px; border-top:2px solid var(--accent);
        border-bottom:1px solid var(--line); position:sticky; top:0;
    }
    .inv-table .branch-head.branch-admin td {
        background:var(--accent); color:#fff; border-top-color:var(--accent-text);
    }
    .inv-table .branch-head i { margin-left:6px; }
    .inv-table .branch-head { cursor:pointer; user-select:none; }
    .inv-table .branch-head .bh-caret { transition:transform 0.18s; font-size:16px; }
    .inv-table .branch-head.collapsed .bh-caret { transform:rotate(-90deg); }
    /* رأس فرعي داخل الفرع: فاتورة شراء / مردود شراء */
    .inv-table .subtype-head td {
        background:var(--bg-soft, var(--surface)); color:var(--ink-700, var(--accent-text));
        font-weight:600; font-size:13px; text-align:right; padding:8px 14px 8px 28px;
        border-bottom:1px solid var(--line);
    }
    .inv-table .subtype-head { cursor:pointer; user-select:none; }
    .inv-table .subtype-head.subtype-refund td { color:var(--neg); }
    .inv-table .subtype-head .st-caret { transition:transform 0.18s; font-size:14px; }
    .inv-table .subtype-head.collapsed .st-caret { transform:rotate(-90deg); }
    .inv-table .subtype-head .bh-count {
        float:left; font-weight:400; font-size:11px; opacity:0.8;
        background:var(--line); color:var(--ink-700,var(--accent-text)); padding:1px 9px; border-radius:20px;
    }
    .inv-table .subtype-head.subtype-refund .bh-count { background:var(--neg-soft); color:var(--neg); }
    .inv-table .branch-head .bh-sub {
        font-weight:400; font-size:12px; opacity:0.8; margin-right:8px;
    }
    .inv-table .branch-head .bh-count {
        float:left; font-weight:400; font-size:12px; opacity:0.75;
        background:rgba(255,255,255,0.18); padding:2px 10px; border-radius:20px;
    }
    .inv-table .branch-admin .bh-count { background:rgba(255,255,255,0.22); }
    .inv-table:not(.branch-admin) .branch-head:not(.branch-admin) .bh-count {
        background:var(--accent); color:#fff;
    }
    .inv-stack { display:flex; flex-direction:column; gap:3px; }
    .btn { background:var(--accent); color:#fff; border:none; padding:10px 18px; border-radius:10px;
        font-size:14px; font-family:inherit; cursor:pointer; display:inline-flex; align-items:center; gap:7px; text-decoration:none; }
    .btn:hover { background:var(--accent-hover); }
    .btn-sm { padding:6px 12px; font-size:13px; }
    .item-row { border:1.5px solid var(--line); border-radius:12px; padding:16px; margin-bottom:14px; }
    .item-head { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:12px; }
    .item-name { font-size:15px; font-weight:600; color:var(--ink-900); }
    .item-code { font-size:12px; color:var(--ink-500); background:var(--bg); padding:2px 8px; border-radius:6px; }
    .item-meta { display:flex; gap:16px; font-size:13px; align-items:center; flex-wrap:wrap; }
    .item-meta .m .l { color:var(--ink-500); } .item-meta .m .v { color:var(--ink-900); font-weight:600; }

    /* اختيار الحالة يدوي */
    .inv-status-sel { font-family:inherit; font-size:12.5px; font-weight:600; padding:3px 8px;
        border:1.5px solid var(--line); border-radius:8px; background:var(--surface); color:var(--ink-900);
        cursor:pointer; }
    .inv-status-sel:focus { outline:none; border-color:var(--accent-text); }
    .inv-lock { font-size:12px; margin-right:4px; }

    /* بار الملاحظات */
    .notes-bar { background:var(--bg); border:1.5px solid var(--line); border-radius:10px;
        padding:9px 12px; margin-bottom:14px; }
    .notes-head { display:flex; justify-content:space-between; align-items:center;
        font-size:12.5px; color:var(--ink-500); margin-bottom:6px; }
    .notes-state { font-size:11.5px; color:var(--ink-400); }
    .notes-state.saving { color:var(--accent-text); }
    .notes-state.saved  { color:var(--pos); }
    .notes-input { width:100%; border:1px solid var(--line); border-radius:8px; padding:7px 10px;
        font-family:inherit; font-size:13px; color:var(--ink-900); resize:vertical;
        background:var(--surface); line-height:1.6; }
    .notes-input:focus { outline:none; border-color:var(--accent-text); }

    /* الملاحظات في قائمة الفواتير — النص نفسه بيبان تحت رقم الفاتورة */
    .inv-note-text { display:block; font-size:11.5px; color:var(--ink-600);
        background:var(--surface); border:1px solid var(--warn); border-right:3px solid var(--warn);
        border-radius:6px; padding:3px 8px; margin-top:5px; line-height:1.55;
        font-weight:400; white-space:normal; word-break:break-word; max-width:340px; }
    .store-v.has { color:var(--warn); } .store-v.zero { color:var(--pos); }
    .branch-dist { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; margin:10px 0; }
    .branch-box { background:var(--line-soft); border:1.5px solid var(--line); border-radius:10px; padding:10px; }
    .branch-box .bname { font-size:13px; color:var(--ink-700); margin-bottom:6px; font-weight:500; display:flex; justify-content:space-between; }
    .branch-box .bdone { font-size:11px; color:var(--pos); }
    .branch-box input { width:100%; padding:8px 10px; border:1.5px solid var(--line); border-radius:8px;
        font-size:14px; text-align:center; font-family:inherit; }
    .done-badge { background:var(--pos-soft); color:var(--pos); padding:4px 12px; border-radius:20px; font-size:12px; font-weight:500; }
    .partial-badge { background:var(--warn-soft); color:var(--warn); padding:4px 12px; border-radius:20px; font-size:12px; font-weight:500; }
    /* ===== التابين + حالة الفاتورة ===== */
    .inv-tabs { display:flex; gap:8px; margin-bottom:14px; align-items:center; flex-wrap:wrap; }
    .tab-btn { display:inline-flex; align-items:center; gap:6px; padding:9px 18px; border-radius:10px;
               background:var(--surface); color:var(--ink-500); border:1.5px solid var(--line); text-decoration:none;
               font-size:14px; font-weight:600; transition:.15s; }
    .tab-btn:hover { border-color:var(--line); color:var(--ink-700); }
    .tab-btn.active { background:var(--accent-hover); color:#fff; border-color:var(--accent-text); }
    .st-new   { background:var(--line-soft); color:var(--ink-500); }
    .st-split { background:var(--warn-soft); color:var(--warn); }
    .st-done  { background:var(--pos-soft); color:var(--pos); }
    .inv-row.inv-done { opacity:.6; }

    .search-box { display:flex; gap:8px; margin-bottom:16px; }

    /* ===== بوب أب بحث الصنف ===== */
    .lookup-overlay { display:none; position:fixed; inset:0; background:rgba(30,25,70,.45);
                      z-index:900; align-items:flex-start; justify-content:center; padding:60px 16px; }
    .lookup-overlay.open { display:flex; }
    .lookup-box { background:var(--surface); border-radius:14px; width:100%; max-width:640px;
                  box-shadow:0 18px 50px rgba(40,30,90,.25); overflow:hidden; }
    .lookup-head { display:flex; align-items:center; justify-content:space-between; gap:10px;
                   padding:14px 18px; border-bottom:1px solid var(--ink-400); font-weight:700; color:var(--ink-800); }
    .lookup-x { background:none; border:none; font-size:26px; line-height:1; color:var(--ink-400); cursor:pointer; }
    .lookup-form { padding:16px 18px; }
    .lk-row { display:flex; gap:8px; align-items:stretch; width:100%; }
    .lk-row .btn { flex:0 0 auto; white-space:nowrap; }
    .lk-label { display:block; font-size:13px; color:var(--ink-500); margin-bottom:6px; font-weight:600; }
    .lk-input { flex:1 1 auto; min-width:0; width:auto; padding:10px 14px;
                border:1.5px solid var(--line); border-radius:9px;
                font-size:14px; font-family:inherit; }
    .lk-input:focus { outline:none; border-color:var(--accent-text); }
    .lk-muted { color:var(--ink-400); font-size:14px; margin:14px 0 0; }
    .lk-alert { padding:12px 14px; border-radius:9px; margin:16px 0 12px; font-size:14px; }
    .lk-alert-info { background:var(--accent-soft); color:var(--ink-800); }
    .lk-alert-warning { background:var(--warn-soft); color:var(--warn); }
    .lk-table { width:100%; border-collapse:collapse; }
    .lk-table th { background:var(--line-soft); padding:9px 14px; font-size:12px; color:var(--ink-500); text-align:right; }
    .lk-table td { padding:9px 14px; border-top:1px solid var(--ink-400); font-size:14px; }

    /* ===== بوب أب سحب أعداد الفروع من الحجوزات ===== */
    .btn-res-pull { background:var(--accent); color:#fff; }
    .btn-res-pull:hover { background:var(--accent-hover); color:#fff; }
    .res-card { background:var(--surface); border:1.5px solid var(--line); border-radius:12px; padding:14px; margin-bottom:14px; }
    .res-card-head { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:10px; padding-bottom:8px; border-bottom:1px solid var(--line-soft); }
    .res-card-title { font-size:14px; font-weight:700; color:var(--ink-900); }
    .res-card-meta { display:flex; gap:12px; font-size:12.5px; color:var(--ink-500); flex-wrap:wrap; }
    .res-card-meta b { color:var(--ink-900); }
    .res-table { width:100%; border-collapse:collapse; font-size:13px; margin-bottom:10px; }
    .res-table th { background:var(--bg); color:var(--ink-700); padding:7px 10px; text-align:right; font-weight:600; border-bottom:1.5px solid var(--line); }
    .res-table td { padding:7px 10px; border-bottom:1px solid var(--line-soft); color:var(--ink-900); }
    .res-alloc-inp { width:75px; padding:4px 8px; border:1.5px solid var(--line); border-radius:6px; font-family:inherit; font-size:13px; font-weight:600; }
    .res-alloc-inp:focus { outline:none; border-color:var(--accent-text); }
    .res-alloc-inp.invalid { border-color:var(--neg); background:var(--neg-soft); }
    .res-branch-sum { font-size:12.5px; color:var(--accent-text); font-weight:600; padding:6px 10px; background:var(--accent-soft); border-radius:8px; margin-bottom:8px; }
    .res-card-foot { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; font-size:12.5px; color:var(--ink-700); padding-top:6px; }
    .res-card-foot b { color:var(--ink-900); }
    .res-empty-msg { padding:35px 20px; text-align:center; color:var(--ink-500); font-size:14px; }

    .lines-table { width:100%; border-collapse:collapse; font-size:13px; min-width:700px; }
    .lines-table thead th { background:var(--line-soft); color:var(--accent-text); font-weight:600; padding:11px 10px;
        text-align:center; white-space:nowrap; border-bottom:2px solid var(--line); position:sticky; top:0; }
    .lines-table tbody td { padding:8px 10px; text-align:center; border-bottom:1px solid var(--line-soft); vertical-align:middle; }
    .lines-table tbody tr:hover { background:var(--surface); }
    .lines-table .c-code { font-family:monospace; color:var(--ink-500); white-space:nowrap; }
    .lines-table .c-name { text-align:right; font-weight:500; color:var(--ink-900); min-width:160px; }
    .lines-table .c-qty { font-weight:700; color:var(--accent-text); }
    .lines-table .c-cost { color:var(--ink-500); white-space:nowrap; }
    .lines-table .c-after { font-weight:700; color:var(--pos); white-space:nowrap; }

    /* ===================== علامات الأصناف ===================== */
    /* ألوان التمييز: خلفيات فاتحة عن قصد — النص لازم يفضل مقروء.
       شريطة جانبية غامقة عشان اللون يبان حتى لو الصف طويل ومسحوب. */
    .lines-table tbody tr.hl-red    { background:var(--line-soft); box-shadow:inset -4px 0 0 var(--neg); }
    .lines-table tbody tr.hl-green,
    .lines-table tbody tr.hl-done   { background:var(--line-soft); box-shadow:inset -4px 0 0 var(--pos); }
    .lines-table tbody tr.hl-yellow { background:var(--warn-soft); box-shadow:inset -4px 0 0 var(--warn); }
    .lines-table tbody tr.hl-orange { background:var(--warn-soft); box-shadow:inset -4px 0 0 var(--warn); }
    /* الهوفر مايلغيش اللون */
    .lines-table tbody tr.hl-red:hover    { background:var(--line-soft); }
    .lines-table tbody tr.hl-green:hover,
    .lines-table tbody tr.hl-done:hover   { background:var(--line); }
    .lines-table tbody tr.hl-yellow:hover { background:var(--warn-soft); }
    .lines-table tbody tr.hl-orange:hover { background:var(--warn-soft); }

    .ifl-codewrap { display:inline-flex; align-items:center; gap:5px; }
    .ifl-gear { background:none; border:none; cursor:pointer; padding:2px; line-height:1;
        color:var(--ink-400); font-size:15px; border-radius:6px; }
    .ifl-gear:hover { color:var(--accent-text); background:var(--accent-soft); }
    .ifl-noexp { color:var(--neg); font-size:14px; position:relative; }
    /* شخطة على أيقونة التنزيل = مابينزلش في ملف الفرع */
    .ifl-noexp::after { content:''; position:absolute; inset:0; margin:auto;
        width:17px; height:1.8px; background:var(--neg-fill); transform:rotate(-45deg); border-radius:2px; }
    /* أيقونة الملاحظة: تولتيب بس — مافيش ضغط عليها */
    .ifl-noteic { color:var(--ink-300); font-size:14px; cursor:help; vertical-align:-1px; }
    .ifl-noteic.has { color:var(--warn); }
    .ifl-donetick { color:var(--pos); font-size:14px; margin-right:5px; vertical-align:-1px; }

    .lines-table .th-inote { min-width:150px; }
    .lines-table .c-inote { text-align:right; position:relative; min-width:150px; }
    .inote-input { width:100%; box-sizing:border-box; padding:5px 8px; font-size:12.5px;
        font-family:inherit; border:1px solid var(--line); border-radius:8px;
        background:var(--surface); color:var(--ink-900); }
    .inote-input:focus { outline:none; border-color:var(--accent-text); box-shadow:0 0 0 3px var(--accent-ring); }
    .inote-input::placeholder { color:var(--ink-300); }
    .inote-ind { position:absolute; left:6px; top:50%; transform:translateY(-50%); font-size:11px; }

    .ifl-skipnote { display:flex; gap:9px; align-items:flex-start; margin-bottom:14px;
        background:var(--neg-soft); border:1px solid var(--neg); border-radius:var(--r);
        padding:10px 13px; font-size:12.5px; color:var(--ink-700); line-height:1.8; }
    .ifl-skipnote i { color:var(--neg); font-size:16px; margin-top:2px; }
    .ifl-skiplist { font-family:monospace; font-size:11.5px; color:var(--ink-500); }

    /* شريط أزرار التمييز */
    .ifl-bar { display:flex; align-items:center; gap:9px; flex-wrap:wrap;
        background:var(--line-soft); border:1px solid var(--line); border-radius:var(--r);
        padding:9px 13px; margin-bottom:12px; }
    .ifl-bar .ifl-lbl { font-weight:600; color:var(--ink-700); font-size:13px; white-space:nowrap; }
    .ifl-bar .btn { background:var(--surface); color:var(--ink-700); border:1px solid var(--line); }
    .ifl-bar .btn:hover { border-color:var(--accent-text); color:var(--accent-text); }
    .ifl-hint { color:var(--ink-500); font-size:12px; }

    /* ===== بوب أب إعدادات الصنف ===== */
    .ifl-overlay { display:none; position:fixed; inset:0; background:rgba(22,35,61,.5);
        z-index:960; align-items:center; justify-content:center; padding:16px; }
    .ifl-overlay.open { display:flex; }
    .ifl-box { background:var(--surface); border-radius:var(--r-lg); width:100%; max-width:430px;
        max-height:90vh; overflow-y:auto; box-shadow:var(--shadow-lg); }
    .ifl-head { display:flex; align-items:flex-start; justify-content:space-between; gap:10px;
        padding:15px 18px; border-bottom:1px solid var(--line); }
    .ifl-head b { color:var(--ink-900); font-size:14.5px; display:block; }
    .ifl-head small { color:var(--ink-500); font-size:12px; font-family:monospace; }
    .ifl-x { background:none; border:none; font-size:24px; line-height:1; color:var(--ink-400); cursor:pointer; }
    .ifl-sec { padding:14px 18px; border-bottom:1px solid var(--line-soft); }
    .ifl-sec:last-child { border-bottom:none; }
    .ifl-sec > label { display:block; font-weight:600; color:var(--ink-700); font-size:13px; margin-bottom:9px; }
    .ifl-sec .sub { color:var(--ink-500); font-size:11.5px; line-height:1.7; margin-top:7px; }
    .ifl-sw { display:flex; gap:9px; flex-wrap:wrap; }
    .ifl-sw button { width:42px; height:34px; border-radius:9px; cursor:pointer;
        border:2px solid var(--line); background:var(--surface); position:relative; }
    .ifl-sw button.on { border-color:var(--ink-900); box-shadow:0 0 0 3px var(--accent-ring); }
    .ifl-sw button[data-c="red"]    { background:var(--line-soft); border-color:var(--neg); }
    .ifl-sw button[data-c="green"]  { background:var(--line-soft); border-color:var(--pos); }
    .ifl-sw button[data-c="yellow"] { background:var(--warn-soft); border-color:var(--warn); }
    .ifl-sw button[data-c="orange"] { background:var(--warn-soft); border-color:var(--warn); }
    .ifl-sw button[data-c=""]::after { content:'✕'; color:var(--ink-400); font-size:13px; }
    .ifl-sw button.on[data-c="red"], .ifl-sw button.on[data-c="green"],
    .ifl-sw button.on[data-c="yellow"], .ifl-sw button.on[data-c="orange"] { border-color:var(--ink-900); }
    .ifl-radio { display:flex; flex-direction:column; gap:8px; }
    .ifl-radio label { display:flex; align-items:center; gap:8px; font-size:13px;
        color:var(--ink-800); cursor:pointer; }
    .ifl-donebtn { width:100%; padding:11px; border-radius:var(--r); border:2px solid var(--pos);
        background:var(--surface); color:var(--pos); font-family:inherit; font-weight:600;
        font-size:13.5px; cursor:pointer; margin-top:14px; }
    .ifl-donebtn.on { background:var(--pos-fill); color:#fff; }
    .ifl-donebtn:disabled { opacity:.6; cursor:default; }
    .ifl-donehint { color:var(--ink-500); font-size:11.5px; line-height:1.7; margin-top:7px; }
    .ifl-note-in { width:100%; box-sizing:border-box; padding:9px 11px; font-family:inherit;
        font-size:13px; border:1.5px solid var(--line); border-radius:var(--r-sm); resize:vertical; }
    .ifl-note-in:focus { outline:none; border-color:var(--accent-text); }
    .ifl-foot { padding:12px 18px; display:flex; justify-content:space-between;
        align-items:center; gap:10px; background:var(--line-soft); }
    .ifl-state { font-size:12px; color:var(--ink-500); }
    /* ===== خصم لكل صنف ===== */
    .lines-table .c-pct { white-space:nowrap; }
    .disc-pct-inp {
        width:74px; padding:5px 8px; text-align:center;
        border:1px solid var(--line); border-radius:8px; font-size:13px;
        font-family:inherit; background:var(--surface); color:var(--ink-900);
    }
    .disc-pct-inp:focus { outline:none; border-color:var(--accent-text);
        box-shadow:0 0 0 3px rgba(99,102,241,.12); }
    .disc-pct-inp::placeholder { color:var(--ink-300); }
    .lines-table tr.disc-locked { background:rgba(99,102,241,.05); }
    .lines-table tr.disc-locked .disc-pct-inp {
        border-color:var(--accent-text); font-weight:700; color:var(--accent-text); }
    /* ===== بوب أب اختيار الفاتورة ===== */
    .picker-overlay { display:none; position:fixed; inset:0; background:rgba(22,35,61,.5);
        z-index:950; align-items:flex-start; justify-content:center; padding:50px 16px; }
    .picker-overlay.open { display:flex; }
    .picker-box { background:var(--surface); border-radius:var(--r-lg); width:100%; max-width:720px;
        max-height:82vh; display:flex; flex-direction:column; overflow:hidden;
        box-shadow:0 24px 60px rgba(22,35,61,.32); }
    .picker-head { display:flex; align-items:center; justify-content:space-between;
        padding:15px 20px; border-bottom:1px solid var(--line); font-weight:700; color:var(--ink-900); }
    .picker-x { background:none; border:none; font-size:26px; line-height:1; color:var(--ink-400); cursor:pointer; }
    .picker-search { padding:12px 16px; border-bottom:1px solid var(--line-soft); }
    .picker-search input { width:100%; padding:11px 14px; font-size:14px; }
    .picker-body { overflow-y:auto; padding:8px 0; }

    /* ⚠️ الفورم بقى الابن المباشر لـ.picker-box، والقوايم اتلفّت جوه
       بانلات — لازم عمود الفلكس يكمّل لتحت وإلا القايمة مابتسكرولش
       و.picker-box بـoverflow:hidden بيقصّها. (باظت في 14/08) */
    .picker-box > form { display:flex; flex-direction:column; flex:1; min-height:0; }
    #grpPanelInv, #grpPanelItem { display:flex; flex-direction:column; flex:1; min-height:0; }
    #grpForm .picker-body { flex:1; min-height:0; }
    .picker-box > form > .picker-head,
    .picker-box > form > .grp-modes,
    .picker-box > form > .grp-lockmsg { flex:0 0 auto; }
    #grpPanelInv > .picker-search, #grpPanelItem > .picker-search { flex:0 0 auto; }
    #grpChosen { flex:0 0 auto; }
    .picker-group { padding:9px 20px; font-size:12px; font-weight:700; color:var(--ink-500);
        background:var(--line-soft); letter-spacing:.02em; position:sticky; top:0; }
    .picker-row { display:grid; grid-template-columns:110px 1fr 100px 110px; gap:10px; align-items:center;
        padding:11px 20px; text-decoration:none; color:var(--ink-800); font-size:13.5px;
        border-bottom:1px solid var(--line-soft); transition:background .12s; }
    .picker-row:hover { background:var(--accent-soft); }
    .pk-no { font-weight:700; color:var(--accent-text); }
    .pk-sup { color:var(--ink-800); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .pk-date { color:var(--ink-500); font-size:12.5px; }
    .pk-amt { font-weight:600; text-align:left; }
    .pk-empty { padding:30px; text-align:center; color:var(--ink-400); }

    /* ===== كروت المجموعات ===== */
    .grp-cards { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:14px; }
    .grp-card { display:block; background:var(--surface); border:1.5px solid var(--line); border-radius:14px;
        padding:16px 18px; text-decoration:none; color:inherit; transition:all .15s; }
    .grp-card:hover { border-color:var(--accent-text); transform:translateY(-2px);
        box-shadow:0 8px 22px rgba(124,111,247,.13); }
    .gc-top { display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:10px; }
    .gc-title { font-weight:700; color:var(--ink-900); font-size:15px; }
    .gc-state { font-size:11.5px; padding:3px 10px; border-radius:20px; white-space:nowrap; font-weight:600; }
    .gc-meta { display:flex; flex-wrap:wrap; gap:12px; color:var(--ink-500); font-size:12.5px; margin-bottom:12px; }
    .gc-meta i { font-size:14px; }
    .gc-bar { height:6px; background:var(--line-soft); border-radius:4px; overflow:hidden; }
    .gc-bar span { display:block; height:100%; border-radius:4px; transition:width .3s; }
    .gc-foot { display:flex; justify-content:space-between; align-items:center;
        font-size:12.5px; color:var(--ink-500); margin-top:8px; }

    /* ===== أنواع المجموعات + بوب أب تحديد الأصناف ===== */
    .grp-type { display:inline-flex; align-items:center; gap:5px; padding:3px 10px;
        border-radius:20px; font-size:11.5px; font-weight:700; vertical-align:middle; }
    .grp-type.full  { background:var(--accent-soft); color:var(--accent-text); }
    .grp-type.items { background:var(--warn-soft);   color:var(--warn); }
    .grp-itemsnote { display:flex; gap:9px; align-items:flex-start; margin-top:12px;
        background:var(--warn-soft); border:1px solid var(--warn); border-radius:var(--r);
        padding:10px 13px; font-size:12.5px; color:var(--ink-700); line-height:1.8; }
    .grp-itemsnote i { color:var(--warn); font-size:16px; margin-top:2px; }

    /* × شيل فاتورة من شريحة المجموعة */
    .grp-xbtn { border:0; background:none; cursor:pointer; color:var(--accent-text);
        font-size:17px; line-height:1; padding:0 1px; opacity:.5;
        border-radius:50%; width:18px; height:18px; font-family:inherit; }
    .grp-xbtn:hover { opacity:1; background:var(--neg-fill); color:#fff; }
    /* شيل صنف من صف الجدول */
    .grp-itemx { border:0; background:none; cursor:pointer; color:var(--ink-300);
        font-size:16px; line-height:1; padding:2px 5px; border-radius:5px;
        font-family:inherit; }
    .grp-itemx:hover { color:#fff; background:var(--neg-fill); }

    .grp-modes { display:flex; gap:0; border-bottom:1px solid var(--line); }
    .gm-btn { flex:1; padding:12px 10px; background:none; border:none; cursor:pointer;
        font-family:inherit; font-size:13.5px; font-weight:600; color:var(--ink-500);
        border-bottom:3px solid transparent; display:flex; align-items:center;
        justify-content:center; gap:6px; }
    .gm-btn:hover { color:var(--accent-text); background:var(--line-soft); }
    .gm-btn.active { color:var(--accent-text); border-bottom-color:var(--accent-text); }
    .grp-lockmsg { display:flex; gap:8px; align-items:flex-start; padding:11px 16px;
        background:var(--warn-soft); color:var(--ink-700); font-size:12.5px; line-height:1.8;
        border-bottom:1px solid var(--line); }
    .grp-lockmsg i { color:var(--warn); font-size:15px; margin-top:2px; }

    .gi-searchrow { display:flex; gap:8px; }
    .gi-searchrow input { flex:1; padding:11px 14px; font-size:14px; }
    .gi-hint { color:var(--ink-500); font-size:11.5px; margin-top:7px; }

    .gi-row { display:flex; align-items:center; gap:10px; padding:10px 18px;
        border-bottom:1px solid var(--line-soft); font-size:13px; }
    .gi-row:hover { background:var(--accent-soft); }
    .gi-row.on { background:var(--pos-soft); }
    .gi-row.dis { opacity:.55; }
    .gi-main { flex:1; min-width:0; }
    .gi-nm { font-weight:600; color:var(--ink-900); display:block;
        overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .gi-sub { color:var(--ink-500); font-size:11.5px; margin-top:3px;
        display:flex; gap:7px; flex-wrap:wrap; align-items:center; }
    .gi-doc { padding:1.5px 8px; border-radius:20px; font-size:10.5px; font-weight:700;
        color:#fff; background:var(--accent); }
    .gi-doc.ret { background:var(--neg-fill); }
    .gi-doc.unp { background:var(--warn-fill); }
    .gi-add { border:1.5px solid var(--accent); background:var(--surface); color:var(--accent-text);
        border-radius:8px; padding:6px 13px; font-family:inherit; font-size:12.5px;
        font-weight:600; cursor:pointer; white-space:nowrap; }
    .gi-add.on { background:var(--pos-fill); border-color:var(--pos); color:#fff; }
    .gi-add:disabled { border-color:var(--line); color:var(--ink-400); cursor:default; }

    .gi-ways { display:flex; gap:6px; margin-bottom:9px; }
    .giw-btn { flex:1; padding:8px 10px; border:1.5px solid var(--line); background:var(--surface);
        border-radius:9px; cursor:pointer; font-family:inherit; font-size:12.5px; font-weight:600;
        color:var(--ink-500); display:flex; align-items:center; justify-content:center; gap:6px; }
    .giw-btn:hover { border-color:var(--accent-text); color:var(--accent-text); }
    .giw-btn.active { background:var(--accent); border-color:var(--accent-text); color:#fff; }
    #grpInvFilter { width:100%; padding:11px 14px; font-size:14px; }

    .gi-crumb { display:flex; align-items:center; gap:10px; padding:9px 16px;
        background:var(--accent-soft); border-bottom:1px solid var(--line); flex:0 0 auto; }
    .gi-crumb b { flex:1; font-size:13px; color:var(--ink-900); overflow:hidden;
        text-overflow:ellipsis; white-space:nowrap; }
    .gi-back, .gi-all { border:1.5px solid var(--accent); background:var(--surface);
        color:var(--accent-text); border-radius:8px; padding:5px 11px; font-family:inherit;
        font-size:12px; font-weight:600; cursor:pointer; white-space:nowrap; }
    .gi-all { border-color:var(--pos); color:var(--pos); }

    .grp-admtog { display:flex; align-items:center; gap:8px; margin-top:9px;
        font-size:12.5px; color:var(--ink-700); cursor:pointer; flex-wrap:wrap; }
    .grp-admtog input { width:16px; height:16px; accent-color:var(--accent-text); cursor:pointer; }
    .grp-admtip { color:var(--ink-500); font-size:11.5px; }

    .picker-row.gi-grprow { grid-template-columns:60px 1fr 90px 70px; cursor:pointer; }
    .picker-row.gi-grprow:hover { background:var(--accent-soft); }
    #grpGrpList { flex:1; min-height:0; }
    .picker-row.gi-invrow { grid-template-columns:110px 1fr 100px 30px; cursor:pointer; }
    .picker-row.gi-invrow:hover { background:var(--accent-soft); }
    .picker-row.gi-invrow .pk-amt { text-align:center; color:var(--ink-400); }
    #grpInvList { flex:1; min-height:0; }

    .gi-chosen { padding:10px 16px; background:var(--line-soft);
        border-top:1px solid var(--line); max-height:130px; overflow-y:auto; }
    .gi-chip { display:inline-flex; align-items:center; gap:6px; background:var(--surface);
        border:1px solid var(--line); border-radius:20px; padding:4px 10px;
        font-size:11.5px; margin:0 0 5px 5px; }
    .gi-chip button { background:none; border:none; color:var(--neg); cursor:pointer;
        font-size:15px; line-height:1; padding:0; }

    /* ===== بوب أب الاختيار المتعدد (الفواتير المجمّعة) ===== */
    /* الفورم لازم يورث الفليكس من .picker-box وإلا الليستة بتطوّل
       والفوتر (زرار "افتح المجموعة") بيخرج برّا الصندوق ويتقص
       بسبب overflow:hidden. */
    .picker-box > form { display:flex; flex-direction:column; min-height:0; flex:1; }
    .picker-box > form > .picker-head,
    .picker-box > form > .picker-search,
    .picker-box > form > .grp-foot { flex:0 0 auto; }
    .picker-box > form > .picker-body { flex:1 1 auto; min-height:0; overflow-y:auto; }
    .grp-foot { padding:14px 18px; border-top:1px solid var(--line); background:var(--surface);
        display:flex; gap:10px; justify-content:flex-end; align-items:center; flex-wrap:wrap; }
    /* عمود زيادة للـ checkbox قدام أعمدة الفاتورة الأربعة */
    .picker-row.grp-row { grid-template-columns:26px 110px 1fr 100px 110px; cursor:pointer; }
    .picker-row.grp-row input[type=checkbox] { width:18px; height:18px; cursor:pointer; accent-color:var(--accent-text); }
    .picker-row.grp-row:has(input:checked) { background:var(--accent-soft); }
    @media (max-width:640px){
        .picker-row.grp-row { grid-template-columns:26px 1fr 1fr; }
    }
    @media (max-width:640px){
        .picker-row { grid-template-columns:1fr 1fr; }
        .pk-date, .pk-amt { font-size:12px; }
    }

    .disc-summary { display:flex; flex-wrap:wrap; gap:22px; }
    .disc-summary > div { min-width:120px; }
    .disc-summary span { display:block; font-size:11.5px; color:var(--ink-500); margin-bottom:3px; }
    .disc-summary b { font-size:17px; color:var(--ink-900); letter-spacing:-.02em; }
    .disc-foot td { font-weight:700; background:var(--line-soft); border-top:2px solid var(--line); padding:10px; }
    .lines-table .c-sale { white-space:nowrap; position:relative; }
    .qty-wrap { display:inline-block; }
    .unlock-btn { background:none; border:1px solid var(--line); border-radius:6px;
        padding:3px 7px; margin-right:6px; cursor:pointer; color:var(--ink-500);
        font-size:14px; line-height:1; transition:all .15s; vertical-align:middle; }
    .unlock-btn:hover { border-color:var(--accent-text); color:var(--accent-text); background:var(--accent-soft); }
    .unlock-btn.is-open { background:var(--pos-fill); border-color:var(--pos); color:#fff; }
    .item-row.editing { background:var(--warn-soft) !important; }
    .lines-table .c-bal { white-space:nowrap; font-weight:700; color:var(--ink-800);
        background:var(--line-soft); }
    .lines-table .c-bbal { white-space:nowrap; font-size:12.5px; font-weight:600;
        color:var(--ink-500); background:var(--line-soft); padding:0 6px !important; }
    .lines-table .th-bal { font-size:11px !important; font-weight:500 !important;
        color:var(--ink-400) !important; padding:11px 6px !important; }
    .bal-na { color:var(--ink-300); }
    .sale-input { width:88px; padding:6px 8px; border:1px solid var(--line); border-radius:7px;
        font-size:13px; font-family:inherit; text-align:center; background:var(--surface);
        transition:border-color .15s, box-shadow .15s; }
    .sale-input:focus { outline:none; border-color:var(--accent-text); box-shadow:0 0 0 3px var(--accent-ring); }
    .sale-input.dirty { border-color:var(--warn); background:var(--warn-soft); }
    .sale-ind { position:absolute; left:6px; top:50%; transform:translateY(-50%); font-size:12px; }
    .sale-ind.ok { color:var(--pos); }
    .sale-ind.err { color:var(--neg); }
    .sale-ind.wait { color:var(--warn); }
    /* ===== نسبة التسعير ===== */
    .lines-table .c-mrk { white-space:nowrap; position:relative; }
    /* الخط أصغر من باقي الجدول عن قصد: الخانة بتستحمل نسبة زي
       "115.5%" (٦ حروف) + سهمين الـnumber input. على 13px كان الرقم
       بيتقص من الشمال. */
    .mrk-input { width:82px; padding:6px 3px; border:1px dashed var(--line); border-radius:7px;
        font-size:11.5px; font-family:inherit; text-align:center; background:var(--surface);
        color:var(--ink-900); transition:border-color .15s, box-shadow .15s; }
    .mrk-input:focus { outline:none; border-style:solid; border-color:var(--accent-text);
        box-shadow:0 0 0 3px var(--accent-ring); }
    /* النسبة المحسوبة بتبان باهتة جوه الخانة (placeholder) — مش قيمة متخزّنة */
    .mrk-input::placeholder { color:var(--ink-400); opacity:1; }
    .mrk-input.no-cost { border-style:dotted; background:var(--line-soft); }
    .mrk-bulk { display:flex; align-items:center; gap:8px; flex-wrap:wrap;
        background:var(--line-soft); border:1px solid var(--line); border-radius:10px;
        padding:9px 12px; margin-bottom:12px; }
    .mrk-bulk input { width:96px; padding:7px 10px; border:1px solid var(--line);
        border-radius:7px; font-size:13.5px; font-family:inherit; text-align:center;
        background:var(--surface); }
    .mrk-bulk input:focus { outline:none; border-color:var(--accent-text);
        box-shadow:0 0 0 3px var(--accent-ring); }
    .mrk-bulk .mrk-lbl { font-size:13px; color:var(--ink-700); font-weight:600; }
    .mrk-bulk .mrk-hint { font-size:12px; color:var(--ink-500); }
    .lines-table .c-store .store-v.has { color:var(--warn); font-weight:600; }
    .lines-table .dist-input { width:64px; padding:6px 4px; border:1.5px solid var(--line); border-radius:7px;
        font-size:13px; text-align:center; font-family:inherit; }
    .lines-table .dist-input:focus { border-color:var(--accent-text); outline:none; }
    .lines-table .dist-input:disabled { background:var(--line-soft); color:var(--ink-400); }
    .lines-table .prev-val { color:var(--pos); font-size:12px; }
    .lines-table .dist-remain { display:block; font-size:11px; margin-top:2px; }
    .lines-table .dist-btn { padding:6px 14px; font-size:12px; white-space:nowrap; }
    .lines-table .dist-btn:disabled { opacity:0.4; cursor:not-allowed; }
</style>

<?php if ($flash): ?>
    <div class="flash <?= e($flashType) ?>"><?= e($flash) ?></div>
<?php endif; ?>

<?php if (!empty($GLOBALS['recv_errors'])): ?>
    <div class="sqlsrv-warn">
        <strong>بعض الجداول فشلت قراءتها (الباقي معروض):</strong><br>
        <?php foreach ($GLOBALS['recv_errors'] as $re): ?>
            <?= e($re) ?><br>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($sqlsrvError): ?>
    <div class="sqlsrv-warn">
        <strong>تعذّر الاتصال بقاعدة بيانات الأصناف (SQL Server):</strong><br>
        <?= e($sqlsrvError) ?><br>
        تأكد من إعدادات SQLSRV في config.php ومن تفعيل امتداد pdo_sqlsrv.
    </div>
<?php elseif ($openInvoice): ?>
    <!-- ===== فاتورة مفتوحة ===== -->
    <a href="invoices.php?tab=<?= e($tab) ?>" class="btn btn-back"><i class="ti ti-arrow-right"></i> رجوع للفواتير</a>

    <div class="card">
        <div class="section-title">
            فاتورة <?= e($openInvoice['inv_no']) ?> — <?= e($openInvoice['supplier_name']) ?>
        </div>
        <div class="item-meta" style="margin-bottom:14px;">
            <div class="m"><span class="l">الصافي:</span> <span class="v"><?= number_format((float)$openInvoice['net_amount'],2) ?></span></div>
            <div class="m"><span class="l">الإجمالي:</span> <span class="v"><?= number_format((float)$openInvoice['total_amount'],2) ?></span></div>
            <div class="m"><span class="l">التاريخ:</span> <span class="v"><?= e(date('Y-m-d', strtotime((string)$openInvoice['invoice_date']))) ?></span></div>
            <?php
                $curStatus = (string)($distRecord['status'] ?? 'new');
                $isManual  = (int)($distRecord['status_manual'] ?? 0) === 1;
                [$curLabel, $curClass] = invoice_status_label($curStatus);
            ?>
            <div class="m">
                <span class="l">الحالة:</span>
                <select id="invStatusSel" class="inv-status-sel" onchange="changeStatus(this.value)"
                        title="غيّر الحالة يدوي — التلقائي مش هيدوس عليها">
                    <option value="auto" <?= !$isManual ? 'selected' : '' ?>>تلقائي (<?= e($curLabel) ?>)</option>
                    <option value="new"   <?= $isManual && $curStatus==='new'   ? 'selected' : '' ?>>جديدة</option>
                    <option value="split" <?= $isManual && $curStatus==='split' ? 'selected' : '' ?>>تم التقسيم</option>
                    <option value="done"  <?= $isManual && $curStatus==='done'  ? 'selected' : '' ?>>تم التوزيع</option>
                </select>
                <span id="invStatusLock" class="inv-lock" style="<?= $isManual ? '' : 'display:none;' ?>"
                      title="الحالة مقفولة يدوي"><i class="ti ti-lock"></i></span>
            </div>
        </div>

        <?php /* لافتة قراءة بس، محسوبة وقت تحميل الصفحة. التحذير اللي
                 بيظهر وقت قفل الحالة يدوي بيسأل السيرفر من جديد
                 (فرع `dry`) لأن جدول التوزيع بيتحفظ من غير ريفرش. */ ?>
        <?php if (!$isManual && !empty($pendingItems)): ?>
            <div class="card" style="background:var(--warn-soft);border:1px solid var(--warn);color:var(--warn-text, var(--warn));padding:12px 16px;border-radius:10px;margin-bottom:14px;display:flex;gap:10px;align-items:flex-start;">
                <i class="ti ti-alert-triangle" style="font-size:20px;flex-shrink:0;margin-top:2px;"></i>
                <div>
                    <strong style="font-size:14px;">الفاتورة لسه ما راحتش للاستلام — فيه أصناف توزيعها ناقص:</strong>
                    <div style="margin-top:6px;font-size:13px;line-height:1.6;">
                        <?php foreach ($pendingItems as $pi): ?>
                            <div>• <?= e($pi['name']) ?> — اتوزّع <?= (float)$pi['done'] ?> من <?= (float)$pi['qty'] ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- ===== بار الملاحظات ===== -->
        <?php $uNotes = (string)($distRecord['user_notes'] ?? ''); ?>
        <div class="notes-bar">
            <div class="notes-head">
                <span><i class="ti ti-note"></i> ملاحظات الفاتورة</span>
                <span class="notes-state" id="notesState"><?= $uNotes !== '' ? 'محفوظة' : '' ?></span>
            </div>
            <textarea id="invNotes" class="notes-input" rows="2"
                      placeholder="اكتب ملاحظاتك هنا... (بتتحفظ لوحدها)"
                      oninput="queueNotesSave()"><?= e($uNotes) ?></textarea>
        </div>

        <!-- بحث الصنف -->
        <!-- بحث الصنف + سحب أعداد الفروع -->
        <div class="search-box">
            <button class="btn btn-sm btn-primary" type="button" onclick="openLookup()">
                <i class="ti ti-search"></i> بحث صنف
            </button>
            <button class="btn btn-sm btn-res-pull" type="button" onclick="openReservationPull()">
                <i class="ti ti-users"></i> سحب أعداد الفروع
            </button>
        </div>

        <!-- بوب أب بحث الصنف -->
        <div class="lookup-overlay" id="lookupOverlay" onclick="if(event.target===this)closeLookup()">
            <div class="lookup-box">
                <div class="lookup-head">
                    <span><i class="ti ti-search"></i> بحث صنف</span>
                    <button type="button" class="lookup-x" onclick="closeLookup()">&times;</button>
                </div>
                <div class="lookup-form">
                    <label class="lk-label">كود الصنف</label>
                    <div class="lk-row">
                        <input type="text" class="lk-input" id="lookupCode" placeholder="أدخل كود الصنف"
                               aria-label="كود الصنف" autocomplete="off"
                               inputmode="search" enterkeyhint="search"
                               onkeydown="handleLookupKey(event)"
                               onkeyup="handleLookupKey(event)"
                               onsearch="doLookup()">
                        <button type="button" class="btn btn-sm" onclick="openBarcodeScanner('lookupCode')"
                                title="مسح بالكاميرا" style="font-size:16px;"><i class="ti ti-camera"></i></button>
                        <button type="button" class="btn btn-sm btn-primary" onclick="doLookup()">بحث</button>
                    </div>
                    <div id="lookupResult"></div>
                </div>
            </div>
        </div>

        <!-- بوب أب سحب أعداد الفروع -->
        <div class="lookup-overlay" id="resPullOverlay" onclick="if(event.target===this)closeReservationPull()">
            <div class="lookup-box" style="max-width:850px;width:95%;max-height:85vh;display:flex;flex-direction:column;">
                <div class="lookup-head">
                    <span><i class="ti ti-users"></i> سحب أعداد الفروع من الحجوزات</span>
                    <button type="button" class="lookup-x" onclick="closeReservationPull()">&times;</button>
                </div>
                <div id="resPullBody" style="overflow-y:auto;padding:16px;flex:1;min-height:0;"></div>
                <div class="res-pull-foot" id="resPullFoot" style="padding:12px 18px;border-top:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;background:var(--bg);border-radius:0 0 14px 14px;flex-shrink:0;">
                    <div id="resPullSummaryNote" style="font-size:13px;color:var(--ink-500);"></div>
                    <button type="button" class="btn btn-sm btn-primary" id="btnSubmitResPull" onclick="submitReservationPull()" disabled>
                        <i class="ti ti-check"></i> اعتماد التوزيع
                    </button>
                </div>
            </div>
        </div>

        <?php if (empty($items)): ?>
            <?php if (!empty($GLOBALS['recv_lines_missing'])): ?>
                <?php $hqSync = hq_last_sync(); ?>
                <div class="lines-missing">
                    <i class="ti ti-alert-triangle"></i>
                    <div>
                        <strong>بنود الفاتورة لسه ماوصلتش من آخر مزامنة.</strong><br>
                        الفاتورة موجودة في النسخة المحلية بس أصنافها لأ — يعني إما
                        الفاتورة فاضية فعلاً في نظام الشركة، وإما دفعة البنود فشلت
                        وقت الإرسال. استنى المزامنة الجاية (كل نص ساعة) وافتحها تاني.
                        <?php if ($hqSync): ?>
                            <br><span class="lm-time">آخر مزامنة ناجحة من الإدارة:
                                <?= e(date('Y-m-d H:i', strtotime($hqSync))) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="empty">لا توجد أصناف في هذه الفاتورة.</div>
            <?php endif; ?>
        <?php else: ?>
            <?php
                $naCount = 0;
                foreach ($items as $it) {
                    $iid = (int) ($it['item_id'] ?? 0);
                    if ($iid <= 0 || !isset($itemBalances[$iid])) {
                        $naCount++;
                    }
                }
            ?>
            <?php if ($naCount > 0): ?>
                <div class="sqlsrv-warn" style="margin-bottom:14px;display:flex;align-items:center;gap:10px;">
                    <i class="ti ti-alert-triangle" style="font-size:20px;flex-shrink:0;"></i>
                    <div>
                        فيه <?= $naCount ?> صنف رصيد الإدارة بتاعهم مش واصل — تقدر تقلّل توزيعهم بس مش تزوّده.
                        شغّل وكيل مزامنة الإدارة.
                    </div>
                </div>
            <?php endif; ?>
            <?= mrk_bulk_bar() ?>
            <?= ifl_bar() ?>
            <div class="lines-wrap">
            <table class="lines-table" id="linesTable" data-xt-min="1">
                <thead>
                    <tr>
                        <th>كود الصنف</th>
                        <th>اسم الصنف</th>
                        <th>الكمية</th>
                        <?php foreach ($activeBranches as $b): ?>
                            <th><?= e($b['name']) ?></th>
                            <th class="th-bal" title="رصيد الصنف الحالي في <?= e($b['name']) ?>">رصيد</th>
                        <?php endforeach; ?>
                        <th>سعر الوحدة</th>
                        <th>المخزن المتبقي</th>
                        <th>سعر البيع</th>
                        <th class="th-bal" title="النسبة بين التكلفة وسعر البيع — اكتب نسبة واضغط Enter">نسبة التسعير</th>
                        <th>رصيد الإدارة</th>
                        <th class="th-inote">ملاحظات</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $idx => $it): ?>
                    <?php
                        $code = (string) $it['item_code'];
                        $qty  = (float) $it['quantity'];
                        $dist = get_item_distribution($distId, $code);
                        $distributedTotal = array_sum($dist);
                        $store = $qty - $distributedTotal;
                        $isDone = $store <= 0.0001;
                        $available = $qty - $distributedTotal;
                        $iFlags = $itemFlags[$code] ?? [];
                        $iSkip  = isset($skipMap[$code]);
                        // رصيد الإدارة — سقف التوزيع. `$itemBalances` محسوبة
                        // مجمّعة قبل الحلقة، فمفيش أي استعلام هنا.
                        // 🔴 `null` = مفيش سقف (الصنف مش معروف / الوكيل ما زامنش)،
                        //    **مش صفر**. لو بقت صفر التوزيع بيتقفل على الموقع كله.
                        $iid   = (int) ($it['item_id'] ?? 0);
                        $hqB   = ($iid > 0 && isset($itemBalances[$iid])) ? (float) $itemBalances[$iid] : null;
                        $hqLow = ($hqB !== null && $hqB < $qty - 0.0001);
                        $hqNA  = ($hqB === null);   // رصيد الإدارة مش واصل = التوزيع متقفل
                    ?>
                    <tr class="<?= ifl_row_class($iFlags) ?>" data-code="<?= e($code) ?>" data-name="<?= e($it['item_name']) ?>"<?= ifl_row_data($iFlags, $iSkip) ?>>
                        <?= ifl_code_cell($code, $iSkip) ?>
                        <?= ifl_name_cell((string)$it['item_name'], $iFlags) ?>
                        <td class="c-qty"><?= number_format($qty,2) ?></td>
                        <?php if ($isDone): ?>
                            <?php foreach ($activeBranches as $b): ?>
                                <?php $prev = $dist[(int)$b['id']] ?? 0; ?>
                                <td class="c-branch">
                                    <span class="prev-val"><?= $prev>0?number_format($prev,1):'—' ?></span>
                                    <div class="qty-wrap" style="display:none;position:relative;">
                                        <input type="number" step="1" min="0"
                                               aria-label="كمية الفرع <?= e($b['name']) ?>"
                                               value="<?= $prev>0 ? rtrim(rtrim(number_format($prev,2,'.',''), '0'), '.') : '' ?>" placeholder="0"
                                               class="dist-input"
                                               data-branch="<?= (int)$b['id'] ?>"
                                               data-code="<?= e($code) ?>"
                                               data-qty="<?= $qty ?>"
                                               data-hqbal="<?= $hqB === null ? '' : (float)$hqB ?>"
                                               data-saved="<?= $prev ?>"
                                               <?= $hqNA ? 'title="رصيد الإدارة مش واصل — تقدر تقلّل بس مش تزوّد"' : '' ?>
                                               oninput="onDistInput(this)"
                                               onblur="autoSave(this)">
                                        <span class="save-ind" style="position:absolute;left:4px;top:50%;transform:translateY(-50%);font-size:12px;"></span>
                                    </div>
                                </td>
                                <td class="c-bbal"><?php
                                    // فرع بمصدر خارجي (اشراقة) → الرصيد بالباركود من MySQL
                                    if (!empty($b['ext_balance_src'])) {
                                        $abc  = trim((string)($it['item_code'] ?? ''));
                                        $bbal = $abc !== '' && isset($ashraqaBalances[$abc])
                                                ? $ashraqaBalances[$abc]['balance'] : null;
                                    } else {
                                        $bsto = (int)($b['sto_id'] ?? 0);
                                        $bbal = $bsto > 0 ? ($allBalances[(int)($it['item_id'] ?? 0)][$bsto] ?? null) : null;
                                    }
                                    if ($bbal === null) {
                                        echo '<span class="bal-na">—</span>';
                                    } else {
                                        $neg = $bbal < 0 ? ' style="color:var(--danger,var(--neg));"' : '';
                                        echo '<span'.$neg.'>'.rtrim(rtrim(number_format((float)$bbal, 2, '.', ''), '0'), '.').'</span>';
                                    }
                                ?></td>
                            <?php endforeach; ?>
                            <td class="c-cost"><?= number_format((float)($it['unit_price'] ?? 0),2) ?></td>
                            <td class="c-store">
                                <span class="done-badge">تم التوزيع</span>
                                <span class="store-v" data-code="<?= e($code) ?>" style="display:none;">0</span>
                                <button type="button" class="unlock-btn"
                                        onclick="event.stopPropagation();toggleRowEdit(this)"
                                        title="تعديل الكميات"><i class="ti ti-pencil"></i></button>
                            </td>
                            <td class="c-sale">
                                <input type="number" step="0.01" min="0"
                                       class="sale-input"
                                       aria-label="سعر البيع"
                                       value="<?= isset($salePrices[$code]) ? rtrim(rtrim(number_format((float)$salePrices[$code],5,'.',''), '0'), '.') : '' ?>"
                                       placeholder="0"
                                       data-code="<?= e($code) ?>"
                                       data-saved="<?= e(isset($salePrices[$code]) ? (string)(float)$salePrices[$code] : '') ?>"
                                       oninput="onSaleInput(this)"
                                       onblur="saveSalePrice(this)">
                                <span class="sale-ind"></span>
                            </td>
                            <?= mrk_cell($code, mrk_cost($it), $salePrices[$code] ?? null) ?>
                            <td class="c-bal"><?php
                                $bal = $itemBalances[(int)($it['item_id'] ?? 0)] ?? null;
                                echo $bal === null ? '<span class="bal-na">—</span>'
                                    : rtrim(rtrim(number_format((float)$bal, 2, '.', ''), '0'), '.');
                            ?><?php if ($hqNA): ?><span class="badge" style="display:block;margin-top:3px;font-size:10.5px;color:var(--neg);background:var(--neg-soft);" title="رصيد الإدارة مش واصل — تقدر تقلّل بس مش تزوّد">الرصيد مش واصل</span><?php elseif ($hqLow): ?><span class="badge" style="display:block;margin-top:3px;font-size:10.5px;color:var(--warn);background:var(--warn-soft);" title="الفاتورة <?= number_format($qty, 2) ?> ورصيد الإدارة <?= number_format(max(0.0, (float)$hqB), 2) ?> — التوزيع محدود بالرصيد">الرصيد أقل</span><?php endif; ?></td>
                            <?= ifl_notes_cell($code, $iFlags) ?>
                        <?php else: ?>
                            <?php foreach ($activeBranches as $b): ?>
                                <?php $prev = $dist[(int)$b['id']] ?? 0; ?>
                                <td class="c-branch">
                                    <div style="position:relative;">
                                        <input type="number" step="1" min="0"
                                               aria-label="كمية الفرع <?= e($b['name']) ?>"
                                               value="<?= $prev>0 ? rtrim(rtrim(number_format($prev,2,'.',''), '0'), '.') : '' ?>" placeholder="0"
                                               class="dist-input"
                                               data-branch="<?= (int)$b['id'] ?>"
                                               data-code="<?= e($code) ?>"
                                               data-qty="<?= $qty ?>"
                                               data-hqbal="<?= $hqB === null ? '' : (float)$hqB ?>"
                                               data-saved="<?= $prev ?>"
                                               <?= $hqNA ? 'title="رصيد الإدارة مش واصل — تقدر تقلّل بس مش تزوّد"' : '' ?>
                                               oninput="onDistInput(this)"
                                               onblur="autoSave(this)">
                                        <span class="save-ind" style="position:absolute;left:4px;top:50%;transform:translateY(-50%);font-size:12px;"></span>
                                    </div>
                                </td>
                                <td class="c-bbal"><?php
                                    // فرع بمصدر خارجي (اشراقة) → الرصيد بالباركود من MySQL
                                    if (!empty($b['ext_balance_src'])) {
                                        $abc  = trim((string)($it['item_code'] ?? ''));
                                        $bbal = $abc !== '' && isset($ashraqaBalances[$abc])
                                                ? $ashraqaBalances[$abc]['balance'] : null;
                                    } else {
                                        $bsto = (int)($b['sto_id'] ?? 0);
                                        $bbal = $bsto > 0 ? ($allBalances[(int)($it['item_id'] ?? 0)][$bsto] ?? null) : null;
                                    }
                                    if ($bbal === null) {
                                        echo '<span class="bal-na">—</span>';
                                    } else {
                                        $neg = $bbal < 0 ? ' style="color:var(--danger,var(--neg));"' : '';
                                        echo '<span'.$neg.'>'.rtrim(rtrim(number_format((float)$bbal, 2, '.', ''), '0'), '.').'</span>';
                                    }
                                ?></td>
                            <?php endforeach; ?>
                            <td class="c-cost"><?= number_format((float)($it['unit_price'] ?? 0),2) ?></td>
                            <td class="c-store">
                                <span class="store-v has" data-code="<?= e($code) ?>"><?= number_format($store,2) ?></span>
                            </td>
                            <td class="c-sale">
                                <input type="number" step="0.01" min="0"
                                       class="sale-input"
                                       aria-label="سعر البيع"
                                       value="<?= isset($salePrices[$code]) ? rtrim(rtrim(number_format((float)$salePrices[$code],5,'.',''), '0'), '.') : '' ?>"
                                       placeholder="0"
                                       data-code="<?= e($code) ?>"
                                       data-saved="<?= e(isset($salePrices[$code]) ? (string)(float)$salePrices[$code] : '') ?>"
                                       oninput="onSaleInput(this)"
                                       onblur="saveSalePrice(this)">
                                <span class="sale-ind"></span>
                            </td>
                            <?= mrk_cell($code, mrk_cost($it), $salePrices[$code] ?? null) ?>
                            <td class="c-bal"><?php
                                $bal = $itemBalances[(int)($it['item_id'] ?? 0)] ?? null;
                                echo $bal === null ? '<span class="bal-na">—</span>'
                                    : rtrim(rtrim(number_format((float)$bal, 2, '.', ''), '0'), '.');
                            ?><?php if ($hqNA): ?><span class="badge" style="display:block;margin-top:3px;font-size:10.5px;color:var(--neg);background:var(--neg-soft);" title="رصيد الإدارة مش واصل — تقدر تقلّل بس مش تزوّد">الرصيد مش واصل</span><?php elseif ($hqLow): ?><span class="badge" style="display:block;margin-top:3px;font-size:10.5px;color:var(--warn);background:var(--warn-soft);" title="الفاتورة <?= number_format($qty, 2) ?> ورصيد الإدارة <?= number_format(max(0.0, (float)$hqB), 2) ?> — التوزيع محدود بالرصيد">الرصيد أقل</span><?php endif; ?></td>
                            <?= ifl_notes_cell($code, $iFlags) ?>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- تصدير الفاتورة كاملة -->
    <div class="card" style="margin-bottom:16px;">
        <div class="section-title">تصدير الفاتورة</div>
        <p style="color:var(--ink-500);font-size:13px;margin-bottom:14px;">
            ملف إكسيل (.xlsx) فيه كل بيانات الفاتورة: الهيدر، الأصناف، التوزيع على الفروع،
            أرصدة الفروع، أسعار البيع، والمتبقي بالمخزن.
        </p>
        <a class="btn" style="background:var(--pos-fill);"
           href="invoice_full_export.php?invoice=<?= e((string)$openInvoice['inv_id']) ?>&suffix=<?= e((string)($openInvoice['suffix'] ?? $openSuffix ?? '')) ?>">
            <i class="ti ti-file-spreadsheet"></i> تصدير الفاتورة كاملة (Excel)
        </a>
    </div>

    <!-- تصدير أسعار البيع — مستقل تمامًا عن حالة التوزيع، شغّال في أي وقت -->
    <div class="card" style="margin-bottom:16px;">
        <div class="section-title">أسعار البيع</div>
        <p style="color:var(--ink-500);font-size:13px;margin-bottom:14px;">
            الأسعار بتتحدث من السيستم تلقائي كل يوم مع فتح الفاتورة، وزرار التحديث بيجيبها فورًا.
            السيستم هو المرجع — التحديث بيكتب فوق أي تعديل يدوي (قاعدة الشركة مابتتكتبش أبدًا).
            التصدير بيطلع ملف نصي: كود الصنف + Tab + السعر.
        </p>
        <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
            <a class="btn" id="pricesExportBtn" style="background:var(--fill-strong);color:var(--fill-strong-fg);"
               href="prices_export.php?invoice=<?= e((string)$openInvoice['inv_id']) ?>&suffix=<?= e((string)($openInvoice['suffix'] ?? $openSuffix ?? '')) ?>">
                <i class="ti ti-download"></i> تصدير ملف الأسعار
            </a>
            <button type="button" class="btn" id="refreshPricesBtn" style="background:var(--accent);"
                    onclick="refreshPrices(this)">
                <i class="ti ti-refresh"></i> تحديث الأسعار من السيستم
            </button>
            <span id="priceRefreshNote" style="display:none;font-size:13px;"></span>
            <span id="priceEditNote" style="display:none;color:var(--pos);font-size:13px;">
                <i class="ti ti-check"></i> اتحفظ — تقدر تصدّر الملف
            </span>
        </div>
    </div>

    <?php
    $isReceivingOpened = false;
    if (!empty($distId)) {
        $isReceivingOpened = (int) $pdo->query('SELECT COUNT(*) FROM receipts WHERE distribution_id = ' . (int)$distId)->fetchColumn() > 0;
    }
    ?>
    <!-- أزرار تحميل ملف TEXT لكل فرع اتوزّعله حاجة -->
    <div class="card" id="branchDlCard" data-receiving-open="<?= $isReceivingOpened ? '1' : '0' ?>" <?= (empty($branchTotals) && $tab !== 'unposted') ? 'style="display:none;"' : '' ?>>
        <div class="section-title">تحميل ملفات التوزيع (لكل فرع)</div>
        <p style="color:var(--ink-500);font-size:13px;margin-bottom:14px;">
            كل زر بينزّل ملف TEXT خاص بالفرع (كود الصنف، الكمية، سعر التكلفة). يظهر فقط للفروع اللي اتوزّعلها كميات.
        </p>
        <?= ifl_skip_note($skipMap) ?>
        <div style="display:flex;flex-wrap:wrap;gap:10px;">
            <?php foreach ($activeBranches as $b): $btotal = (float)($branchTotals[(int)$b['id']] ?? 0); ?>
                <a class="btn branch-dl" style="background:var(--pos-fill);<?= $btotal>0?'':'display:none;' ?>"
                   data-branch="<?= (int)$b['id'] ?>"
                   href="branch_export.php?invoice=<?= e((string)$openInvoice['inv_id']) ?>&branch=<?= (int)$b['id'] ?>&suffix=<?= e((string)($openInvoice['suffix'] ?? $openSuffix ?? '')) ?>">
                    <i class="ti ti-download"></i> <?= e($b['name']) ?>
                    <span class="dl-count" style="opacity:.8;font-size:12px;">(<?= number_format($btotal,0) ?>)</span>
                </a>
            <?php endforeach; ?>

            <?php if ($tab === 'unposted'): ?>
                <a class="btn" style="background:var(--pos-fill);"
                   href="invoices_export.php?invoice=<?= e((string)$openInvoice['inv_id']) ?>&suffix=<?= e((string)($openInvoice['suffix'] ?? $openSuffix ?? '')) ?>">
                    <i class="ti ti-file-spreadsheet"></i> تصدير إكسيل
                </a>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>
    <!-- ===== قائمة الفواتير ===== -->
    <div class="inv-tabs">
        <a class="tab-btn <?= $tab==='posted'?'active':'' ?>" href="invoices.php?tab=posted">
            <i class="ti ti-package"></i> فواتير مرحّلة
        </a>
        <a class="tab-btn <?= $tab==='unposted'?'active':'' ?>" href="invoices.php?tab=unposted">
            <i class="ti ti-clock"></i> غير محفوظة
        </a>
        <a class="tab-btn <?= $tab==='discount'?'active':'' ?>" href="invoices.php?tab=discount">
            <i class="ti ti-discount"></i> حساب الخصم
        </a>
        <a class="tab-btn <?= $tab==='group'?'active':'' ?>" href="invoices.php?tab=group">
            <i class="ti ti-stack-2"></i> فواتير مجمّعة
            <?php $grpOpenCount = count(group_list()); if ($grpOpenCount > 0): ?>
                <span style="background:var(--pos-fill);color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;">
                    <?= $grpOpenCount ?>
                </span>
            <?php endif; ?>
        </a>
    </div>

    <?php if ($tab === 'group'): ?>
    <!-- ===================== الفواتير المجمّعة ===================== -->

    <?php if (empty($grpCurrent)): ?>
        <!-- ===== كروت المجموعات ===== -->
        <div class="card" style="margin-bottom:16px;">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;">
                <div>
                    <div class="section-title" style="margin-bottom:4px;">مجموعات الشغل</div>
                    <p style="color:var(--ink-500);font-size:13px;margin:0;">
                        كل مجموعة = مجموعة فواتير بتتوزّع مرة واحدة. تقدر تفتح أكتر من مجموعة وتشتغل عليهم بالتبادل.
                    </p>
                </div>
                <button type="button" class="btn btn-primary" onclick="openGrpPicker()">
                    <i class="ti ti-plus"></i> مجموعة جديدة
                </button>
            </div>
        </div>

        <?php if (empty($grpCards)): ?>
            <div class="card">
                <div class="empty" style="padding:34px 20px;line-height:2;">
                    مفيش مجموعات مفتوحة.<br>
                    <span style="font-size:13px;color:var(--ink-500);">
                        اضغط "مجموعة جديدة" واختار فاتورتين أو أكتر — هتتدمج أصنافهم في جدول واحد توزّعه مرة واحدة
                        بدل ما تفتح فاتورة فاتورة.
                    </span>
                </div>
            </div>
        <?php else: ?>
            <div class="grp-cards">
            <?php foreach ($grpCards as $gc): ?>
                <?php
                    $pct  = (int) $gc['pct'];
                    $col  = $pct >= 100 ? 'var(--pos)' : ($pct > 0 ? 'var(--warn)' : 'var(--ink-400)');
                    $lbl  = $pct >= 100 ? 'اتوزّعت' : ($pct > 0 ? 'شغل جاري' : 'لسه ماتبدأتش');
                ?>
                <a class="grp-card" href="invoices.php?tab=group&g=<?= (int)$gc['id'] ?>">
                    <div class="gc-top">
                        <span class="gc-title">
                            <?= e($gc['name'] ?: ('مجموعة #' . $gc['id'])) ?>
                        </span>
                        <span class="gc-state" style="background:<?= $col ?>1a;color:<?= $col ?>;"><?= e($lbl) ?></span>
                    </div>
                    <div class="gc-meta">
                        <?php if (!empty($gc['is_items'])): ?>
                            <span class="grp-type items" style="font-size:11px;">
                                <i class="ti ti-list-check"></i> <?= (int)($gc['sel_count'] ?? 0) ?> صنف مختار
                            </span>
                        <?php endif; ?>
                        <span><i class="ti ti-file-invoice"></i> <?= (int)$gc['inv_count'] ?> فاتورة</span>
                        <span><i class="ti ti-building-store"></i> <?= (int)$gc['branches'] ?> فرع</span>
                        <span><i class="ti ti-calendar"></i> <?= e(date('Y-m-d', strtotime((string)$gc['created_at']))) ?></span>
                    </div>
                    <div class="gc-bar"><span style="width:<?= $pct ?>%;background:<?= $col ?>;"></span></div>
                    <?php $gcNote = trim((string)($gc['user_notes'] ?? '')); ?>
                    <?php if ($gcNote !== ''): ?>
                        <span class="inv-note-text" title="<?= e($gcNote) ?>"><i class="ti ti-note"></i> <?= e(mb_strimwidth($gcNote, 0, 120, '…')) ?></span>
                    <?php endif; ?>
                    <div class="gc-foot">
                        <span><?= rtrim(rtrim(number_format((float)$gc['done_qty'],2,'.',''),'0'),'.') ?>
                              من <?= rtrim(rtrim(number_format((float)$gc['total_qty'],2,'.',''),'0'),'.') ?> قطعة</span>
                        <span style="color:<?= $col ?>;font-weight:700;"><?= $pct ?>%</span>
                    </div>
                </a>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- بوب أب اختيار الفواتير / الأصناف (مشترك — شوف grp_picker) -->
        <?= grp_picker($grpUnposted ?? [], $grpPosted ?? []) ?>

    <?php else: ?>
        <!-- ===== مجموعة مفتوحة: شاشة التوزيع ===== -->
        <?php
            $grpInvoices = $grpData['invoices'];
            $grpItems    = $grpData['items'];
            $grpTotalQty = 0.0;
            foreach ($grpItems as $gi) $grpTotalQty += (float) $gi['quantity'];
        ?>
        <div class="card" style="margin-bottom:16px;">
            <a href="invoices.php?tab=group" class="btn" style="background:var(--line);color:var(--ink-700);
               padding:7px 14px;font-size:13px;margin-bottom:12px;">
                <i class="ti ti-arrow-right"></i> كل المجموعات
            </a>
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;">
                <div>
                    <?php
                        $gIsItems = group_is_items((int) $grpCurrent['id']);
                        $gSelCnt  = $gIsItems ? count(group_selected_codes((int) $grpCurrent['id'])) : 0;
                    ?>
                    <div class="section-title" style="margin-bottom:6px;">
                        <?= e($grpCurrent['name'] ?: ('مجموعة #' . $grpCurrent['id'])) ?>
                        <span class="grp-type <?= $gIsItems ? 'items' : 'full' ?>">
                            <i class="ti ti-<?= $gIsItems ? 'list-check' : 'file-invoice' ?>"></i>
                            <?= $gIsItems ? 'مجموعة أصناف' : 'فواتير كاملة' ?>
                        </span>
                        <span style="font-weight:400;color:var(--ink-500);font-size:13.5px;">
                            — <?= count($grpInvoices) ?> فاتورة · <?= count($grpItems) ?> صنف
                            · إجمالي <?= number_format($grpTotalQty, 2) ?> قطعة
                        </span>
                    </div>
                    <div style="display:flex;flex-wrap:wrap;gap:7px;margin-top:8px;">
                        <?php $grpCanRemove = count($grpInvoices) > 1; ?>
                        <?php foreach ($grpInvoices as $gi): ?>
                            <span style="background:var(--accent-soft);color:var(--accent-text);border-radius:20px;
                                         padding:4px 12px;font-size:12.5px;display:inline-flex;
                                         align-items:center;gap:6px;">
                                <?= e($gi['invoice_no']) ?>
                                <span style="opacity:.75;"><?= e($gi['supplier_name'] ?: '—') ?></span>
                                <?php if ($grpCanRemove): ?>
                                    <?php /* data-* مش onclick+json_encode — ده بيكسر الـattribute
                                             لما الاسم يكون فيه علامة تنصيص (بق موثّق في RULES.md) */ ?>
                                    <button type="button" class="grp-xbtn js-grp-drop"
                                        title="شيل الفاتورة دي من المجموعة"
                                        data-sth="<?= (int) $gi['sth_id'] ?>"
                                        data-suf="<?= e((string) $gi['suffix']) ?>"
                                        data-no="<?= e((string) $gi['invoice_no']) ?>">&times;</button>
                                <?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div style="display:flex;gap:9px;flex-wrap:wrap;align-items:center;">
                    <button type="button" class="btn btn-primary" onclick="openGrpPicker()">
                        <i class="ti ti-plus"></i>
                        <?= $gIsItems ? 'ضيف فواتير أو أصناف' : 'ضيف فاتورة' ?>
                    </button>
                    <form method="post" onsubmit="return confirm('مسح المجموعة؟\n\nالتوزيع اللي اتعمل هيفضل محفوظ في الفواتير — اللي هيتمسح هو تجميعة الشغل بس.');">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="group_delete">
                        <input type="hidden" name="group_id" value="<?= (int)$grpCurrent['id'] ?>">
                        <button class="btn" style="background:var(--neg-fill);">
                            <i class="ti ti-trash"></i> امسح المجموعة
                        </button>
                    </form>

                    <?php /* فورم مخفي بيخدم شيل الفاتورة وشيل الصنف */ ?>
                    <form method="post" id="grpDropForm" style="display:none;">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" id="grpDropAction" value="">
                        <input type="hidden" name="group_id" value="<?= (int)$grpCurrent['id'] ?>">
                        <input type="hidden" name="sth_id"    id="grpDropSth"  value="">
                        <input type="hidden" name="suffix"    id="grpDropSuf"  value="">
                        <input type="hidden" name="item_code" id="grpDropCode" value="">
                    </form>
                </div>
            </div>
            <?php if ($gIsItems): ?>
                <div class="grp-itemsnote">
                    <i class="ti ti-list-check"></i>
                    <span>
                        مجموعة أصناف — فيها <b><?= (int) $gSelCnt ?></b> صنف مختار من
                        <?= count($grpInvoices) ?> فاتورة. ملفات الفروع بتطلع
                        <b>بالأصناف المختارة بس</b>، وباقي أصناف الفواتير دي شغالة
                        زي ما هي من تاب الفواتير.
                    </span>
                </div>
            <?php endif; ?>
            <?php if (!empty($grpData['missing'])): ?>
                <div class="sqlsrv-warn" style="margin-top:14px;">
                    <i class="ti ti-alert-triangle"></i> فيه <?= count($grpData['missing']) ?> فاتورة مقدرناش نجيب بياناتها
                    (يمكن اتحفظت أو اتلغت من نظام الشركة). الباقي شغّال عادي.
                </div>
            <?php endif; ?>
        </div>

        <!-- بوب أب الإضافة (نفس بوب أب الإنشاء — شوف grp_picker) -->
        <?= grp_picker($grpUnposted ?? [], $grpPosted ?? [],
                       (int) $grpCurrent['id'], $gIsItems ? 'items' : 'full') ?>

        <!-- جدول الأصناف المدموج -->
        <div class="card" style="margin-bottom:16px;">
            <div class="section-title">الأصناف المجمّعة</div>

            <!-- ===== بار الملاحظات (نفس بتاع الفاتورة) ===== -->
            <?php $gNotes = (string)($grpCurrent['user_notes'] ?? ''); ?>
            <div class="notes-bar">
                <div class="notes-head">
                    <span><i class="ti ti-note"></i> ملاحظات المجموعة</span>
                    <span class="notes-state" id="notesState"><?= $gNotes !== '' ? 'محفوظة' : '' ?></span>
                </div>
                <textarea id="invNotes" class="notes-input" rows="2"
                          placeholder="اكتب ملاحظاتك هنا... (بتتحفظ لوحدها وبتبان على كارت المجموعة)"
                          oninput="queueNotesSave()"><?= e($gNotes) ?></textarea>
            </div>

            <!-- بحث الصنف -->
            <div class="search-box">
                <button class="btn btn-sm btn-primary" type="button" onclick="openLookup()">
                    <i class="ti ti-search"></i> بحث صنف
                </button>
            </div>

            <!-- بوب أب بحث الصنف -->
            <div class="lookup-overlay" id="lookupOverlay" onclick="if(event.target===this)closeLookup()">
                <div class="lookup-box">
                    <div class="lookup-head">
                        <span><i class="ti ti-search"></i> بحث صنف</span>
                        <button type="button" class="lookup-x" onclick="closeLookup()">&times;</button>
                    </div>
                    <div class="lookup-form">
                        <label class="lk-label">كود الصنف</label>
                        <div class="lk-row">
                            <input type="text" class="lk-input" id="lookupCode" placeholder="أدخل كود الصنف"
                                   aria-label="كود الصنف" autocomplete="off"
                                   inputmode="search" enterkeyhint="search"
                                   onkeydown="handleLookupKey(event)"
                                   onkeyup="handleLookupKey(event)"
                                   onsearch="doLookup()">
                            <button type="button" class="btn btn-sm" onclick="openBarcodeScanner('lookupCode')"
                                    title="مسح بالكاميرا" style="font-size:16px;"><i class="ti ti-camera"></i></button>
                            <button type="button" class="btn btn-sm btn-primary" onclick="doLookup()">بحث</button>
                        </div>
                        <div id="lookupResult"></div>
                    </div>
                </div>
            </div>

            <?php if (empty($grpItems)): ?>
                <div class="empty">مفيش أصناف في فواتير المجموعة.</div>
            <?php else: ?>
            <?php
                $naCount = 0;
                foreach ($grpItems as $it) {
                    $iid = (int) ($it['item_id'] ?? 0);
                    if ($iid <= 0 || !isset($itemBalances[$iid])) {
                        $naCount++;
                    }
                }
            ?>
            <?php if ($naCount > 0): ?>
                <div class="sqlsrv-warn" style="margin-bottom:14px;display:flex;align-items:center;gap:10px;">
                    <i class="ti ti-alert-triangle" style="font-size:20px;flex-shrink:0;"></i>
                    <div>
                        فيه <?= $naCount ?> صنف رصيد الإدارة بتاعهم مش واصل — تقدر تقلّل توزيعهم بس مش تزوّده.
                        شغّل وكيل مزامنة الإدارة.
                    </div>
                </div>
            <?php endif; ?>
            <?= mrk_bulk_bar() ?>
            <?= ifl_bar() ?>
            <div class="lines-wrap">
            <table class="lines-table" id="grpTable" data-xt-min="1">
                <thead>
                    <tr>
                        <th>كود الصنف</th>
                        <th>اسم الصنف</th>
                        <th>الكمية</th>
                        <th class="th-bal" title="الفواتير اللي فيها الصنف ده">مصدر</th>
                        <?php foreach ($activeBranches as $b): ?>
                            <th><?= e($b['name']) ?></th>
                            <th class="th-bal" title="رصيد الصنف الحالي في <?= e($b['name']) ?>">رصيد</th>
                        <?php endforeach; ?>
                        <th>سعر الوحدة</th>
                        <th>المخزن المتبقي</th>
                        <th>سعر البيع</th>
                        <th class="th-bal" title="النسبة بين التكلفة وسعر البيع — اكتب نسبة واضغط Enter">نسبة التسعير</th>
                        <th>رصيد الإدارة</th>
                        <th class="th-inote">ملاحظات</th>
                        <?php if ($gIsItems): ?><th data-xt-skip></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($grpItems as $it): ?>
                    <?php
                        $code = (string) $it['item_code'];
                        $qty  = (float) $it['quantity'];
                        $dist = $grpData['distribution'][$code] ?? [];
                        $distributedTotal = array_sum($dist);
                        $store = $qty - $distributedTotal;
                        $srcTxt = [];
                        foreach ($it['sources'] as $s) {
                            $srcTxt[] = $s['invoice_no'] . ' (' . rtrim(rtrim(number_format((float)$s['qty'],2,'.',''), '0'), '.') . ')';
                        }
                        $iFlags = $itemFlags[$code] ?? [];
                        $iSkip  = isset($skipMap[$code]);
                        $iid   = (int) ($it['item_id'] ?? 0);
                        $hqB   = ($iid > 0 && isset($itemBalances[$iid])) ? (float) $itemBalances[$iid] : null;
                        $hqLow = ($hqB !== null && $hqB < $qty - 0.0001);
                        $hqNA  = ($hqB === null);
                    ?>
                    <tr class="<?= ifl_row_class($iFlags) ?>" data-code="<?= e($code) ?>" data-name="<?= e($it['item_name']) ?>"<?= ifl_row_data($iFlags, $iSkip) ?>>
                        <?= ifl_code_cell($code, $iSkip) ?>
                        <?= ifl_name_cell((string)$it['item_name'], $iFlags) ?>
                        <td class="c-qty"><?= number_format($qty,2) ?></td>
                        <td class="c-bbal" style="font-size:11.5px;color:var(--ink-500);white-space:normal;min-width:110px;"
                            title="<?= e(implode(' · ', $srcTxt)) ?>">
                            <?= e(implode(' · ', $srcTxt)) ?>
                        </td>
                        <?php foreach ($activeBranches as $b): ?>
                            <?php $prev = $dist[(int)$b['id']] ?? 0; ?>
                            <td class="c-branch">
                                <div style="position:relative;">
                                    <input type="number" step="1" min="0"
                                           aria-label="كمية الفرع <?= e($b['name']) ?>"
                                           value="<?= $prev>0 ? rtrim(rtrim(number_format($prev,2,'.',''), '0'), '.') : '' ?>"
                                           placeholder="0"
                                           class="dist-input grp-input"
                                           data-branch="<?= (int)$b['id'] ?>"
                                           data-code="<?= e($code) ?>"
                                           data-qty="<?= $qty ?>"
                                           data-hqbal="<?= $hqB === null ? '' : (float)$hqB ?>"
                                           data-saved="<?= $prev ?>"
                                           <?= $hqNA ? 'title="رصيد الإدارة مش واصل — تقدر تقلّل بس مش تزوّد"' : '' ?>
                                           oninput="onDistInput(this)"
                                           onblur="grpSave(this)">
                                    <span class="save-ind" style="position:absolute;left:4px;top:50%;transform:translateY(-50%);font-size:12px;"></span>
                                </div>
                            </td>
                            <td class="c-bbal"><?php
                                if (!empty($b['ext_balance_src'])) {
                                    $abc  = trim($code);
                                    $bbal = $abc !== '' && isset($ashraqaBalances[$abc])
                                            ? $ashraqaBalances[$abc]['balance'] : null;
                                } else {
                                    $bsto = (int)($b['sto_id'] ?? 0);
                                    $bbal = $bsto > 0 ? ($allBalances[(int)$it['item_id']][$bsto] ?? null) : null;
                                }
                                if ($bbal === null) {
                                    echo '<span class="bal-na">—</span>';
                                } else {
                                    $neg = $bbal < 0 ? ' style="color:var(--danger,var(--neg));"' : '';
                                    echo '<span'.$neg.'>'.rtrim(rtrim(number_format((float)$bbal, 2, '.', ''), '0'), '.').'</span>';
                                }
                            ?></td>
                        <?php endforeach; ?>
                        <td class="c-cost"><?= number_format((float)$it['unit_price'],2) ?></td>
                        <td class="c-store">
                            <span class="store-v has" data-code="<?= e($code) ?>"><?= number_format($store,2) ?></span>
                        </td>
                        <td class="c-sale">
                            <input type="number" step="0.01" min="0"
                                   class="sale-input" aria-label="سعر البيع"
                                   value="<?= isset($salePrices[$code]) ? rtrim(rtrim(number_format((float)$salePrices[$code],5,'.',''), '0'), '.') : '' ?>"
                                   placeholder="0"
                                   data-code="<?= e($code) ?>"
                                   data-saved="<?= e(isset($salePrices[$code]) ? (string)(float)$salePrices[$code] : '') ?>"
                                   oninput="onSaleInput(this)"
                                   onblur="saveSalePrice(this)">
                            <span class="sale-ind"></span>
                        </td>
                        <?= mrk_cell($code, mrk_cost($it), $salePrices[$code] ?? null) ?>
                        <td class="c-bal"><?php
                            $bal = $itemBalances[(int)($it['item_id'] ?? 0)] ?? null;
                            echo $bal === null ? '<span class="bal-na">—</span>'
                                : rtrim(rtrim(number_format((float)$bal, 2, '.', ''), '0'), '.');
                        ?><?php if ($hqNA): ?><span class="badge" style="display:block;margin-top:3px;font-size:10.5px;color:var(--neg);background:var(--neg-soft);" title="رصيد الإدارة مش واصل — تقدر تقلّل بس مش تزوّد">الرصيد مش واصل</span><?php elseif ($hqLow): ?><span class="badge" style="display:block;margin-top:3px;font-size:10.5px;color:var(--warn);background:var(--warn-soft);" title="الفاتورة <?= number_format($qty, 2) ?> ورصيد الإدارة <?= number_format(max(0.0, (float)$hqB), 2) ?> — التوزيع محدود بالرصيد">الرصيد أقل</span><?php endif; ?></td>
                        <?= ifl_notes_cell($code, $iFlags) ?>
                        <?php if ($gIsItems): ?>
                            <td style="text-align:center;">
                                <button type="button" class="grp-itemx js-grp-dropitem"
                                    title="شيل الصنف ده من المجموعة"
                                    data-code="<?= e($code) ?>"
                                    data-name="<?= e((string) ($it['item_name'] ?? $code)) ?>">&times;</button>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- تصدير المجموعة كاملة (إكسيل) — زي زرار الفاتورة المفردة -->
        <div class="card" style="margin-bottom:16px;">
            <div class="section-title">تصدير المجموعة</div>
            <p style="color:var(--ink-500);font-size:13px;margin-bottom:14px;line-height:1.8;">
                ملف إكسيل (.xlsx) فيه كل بيانات المجموعة: الفواتير اللي جواها، الأصناف المدموجة،
                المصدر (الصنف جاي من أنهي فاتورة وبكام)، التوزيع على الفروع، أرصدة الفروع،
                أسعار البيع، والمتبقي بالمخزن.
            </p>
            <a class="btn" style="background:var(--pos-fill);"
               href="group_full_export.php?group=<?= (int)$grpCurrent['id'] ?>">
                <i class="ti ti-file-spreadsheet"></i> تصدير المجموعة كاملة (Excel)
                <span style="opacity:.8;font-size:12px;">(<?= count($grpItems) ?> صنف)</span>
            </a>
        </div>

        <!-- أسعار البيع للمجموعة كلها — مستقل تمامًا عن التوزيع -->
        <div class="card" style="margin-bottom:16px;">
            <div class="section-title">أسعار البيع</div>
            <p style="color:var(--ink-500);font-size:13px;margin-bottom:14px;line-height:1.8;">
                ملف واحد لكل أصناف المجموعة (الصنف المكرر في أكتر من فاتورة بيطلع سطر واحد).
                الصيغة: كود الصنف + Tab + السعر.<br>
                السعر اللي تعدّله في الجدول فوق بيتحفظ على كل فواتير المجموعة اللي فيها الصنف،
                والتحديث من السيستم بيكتب فوق أي تعديل يدوي (قاعدة الشركة مابتتكتبش أبدًا).
            </p>
            <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
                <a class="btn" id="pricesExportBtn" style="background:var(--fill-strong);color:var(--fill-strong-fg);"
                   href="group_prices_export.php?group=<?= (int)$grpCurrent['id'] ?>">
                    <i class="ti ti-download"></i> تصدير ملف الأسعار المجمّع
                    <span style="opacity:.8;font-size:12px;">(<?= count($grpItems) ?> صنف)</span>
                </a>
                <button type="button" class="btn" id="refreshPricesBtn" style="background:var(--accent);"
                        onclick="refreshPrices(this)">
                    <i class="ti ti-refresh"></i> تحديث الأسعار من السيستم
                </button>
                <span id="priceRefreshNote" style="display:none;font-size:13px;"></span>
                <span id="priceEditNote" style="display:none;color:var(--pos);font-size:13px;">
                    <i class="ti ti-check"></i> اتحفظ — تقدر تصدّر الملف
                </span>
            </div>
        </div>

        <!-- تحميل ملفات التوزيع: مجمّع + لكل فاتورة -->
        <div class="card">
            <div class="section-title">تحميل ملفات التوزيع (لكل فرع)</div>

            <!-- (١) مجمّع: ملف واحد للفرع من كل فواتير المجموعة -->
            <?php
                // كل فواتير المجموعة اللي ليها كارت استلام مفرد — استعلام واحد قبل الحلقة
                $grpSoloOpened = [];
                $allGrpDistIds = array_values(array_filter(array_map('intval', $grpData['dist_ids'] ?? []), fn($x) => $x > 0));
                if ($allGrpDistIds) {
                    $in = implode(',', $allGrpDistIds);
                    foreach ($pdo->query("SELECT DISTINCT distribution_id FROM receipts WHERE group_id = 0 AND distribution_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN) as $d) {
                        $grpSoloOpened[(int) $d] = true;
                    }
                }

                // إجمالي كميات الفروع لكل فواتير المجموعة — استعلام واحد مجمّع بدلاً من prepare جوه الحلقة (قاعدة ١)
                $grpBtMap = [];
                if ($allGrpDistIds) {
                    $in = implode(',', $allGrpDistIds);
                    $qBt = $pdo->query(
                        "SELECT distribution_id, branch_id, SUM(amount) AS t
                         FROM invoice_distribution_lines
                         WHERE distribution_id IN ($in)
                         GROUP BY distribution_id, branch_id
                         HAVING SUM(amount) > 0"
                    );
                    foreach ($qBt->fetchAll() as $r) {
                        $grpBtMap[(int)$r['distribution_id']][(int)$r['branch_id']] = (float) $r['t'];
                    }
                }

                $grpBranchTotals = $grpData['branch_totals'] ?? [];
                $anyGrp = false;
                foreach ($activeBranches as $b) {
                    if ((float)($grpBranchTotals[(int)$b['id']] ?? 0) > 0) { $anyGrp = true; break; }
                }
                $isGrpReceivingOpened = (int) $pdo->query('SELECT COUNT(*) FROM receipts WHERE group_id = ' . (int)$grpCurrent['id'])->fetchColumn() > 0;
                $soloCount = count($grpSoloOpened);
            ?>
            <div style="border:2px solid var(--pos);border-radius:12px;padding:16px;margin-bottom:16px;
                        background:var(--pos-soft);" data-receiving-open="<?= $isGrpReceivingOpened ? '1' : '0' ?>"
                 data-solo-count="<?= $soloCount ?>">
                <div style="font-weight:700;color:var(--pos);font-size:14px;margin-bottom:6px;">
                    <i class="ti ti-stack-2"></i> مجمّع — كل فواتير المجموعة في ملف واحد
                </div>
                <p style="color:var(--ink-700);font-size:12.5px;margin-bottom:12px;line-height:1.7;">
                    الصنف اللي اتوزّع من أكتر من فاتورة بيتجمع في سطر واحد
                    (الفرع بيستلمه مرة واحدة). التصدير بيسجّل على كل الفواتير وبيحدّث حالتها.
                </p>
                <?php if ($soloCount > 0): ?>
                    <div style="background:var(--warn-soft);color:var(--warn);border:1px solid var(--warn);
                                border-radius:var(--r-sm);padding:8px 12px;font-size:12.5px;
                                font-weight:600;margin-bottom:12px;">
                        <i class="ti ti-alert-triangle"></i>
                        فيه <?= $soloCount ?> فاتورة اتبعتت لوحدها خلاص — <b>مش هتنزل في الملف ده</b>
                        عشان الفرع مايرفعهاش مرتين.
                    </div>
                <?php endif; ?>
                <?= ifl_skip_note($skipMap) ?>
                <div style="display:flex;flex-wrap:wrap;gap:8px;" id="grpComboWrap">
                    <?php foreach ($activeBranches as $b): ?>
                        <?php $gt = (float)($grpBranchTotals[(int)$b['id']] ?? 0); ?>
                        <a class="btn grp-combo" data-branch="<?= (int)$b['id'] ?>"
                           style="background:var(--pos-fill);padding:9px 16px;font-size:13.5px;<?= $gt > 0 ? '' : 'display:none;' ?>"
                           href="group_export.php?group=<?= (int)$grpCurrent['id'] ?>&branch=<?= (int)$b['id'] ?>">
                            <i class="ti ti-download"></i> <?= e($b['name']) ?>
                            <span class="dl-count" style="opacity:.8;font-size:12px;">(<?= number_format($gt,0) ?>)</span>
                        </a>
                    <?php endforeach; ?>
                    <span id="grpComboNone" style="color:var(--ink-500);font-size:13px;align-self:center;<?= $anyGrp ? 'display:none;' : '' ?>">
                        لسه ماتوزّعش حاجة — اكتب الكميات فوق والأزرار هتظهر لوحدها.
                    </span>
                </div>
            </div>

            <!-- (٢) لكل فاتورة على حدة -->
            <p style="color:var(--ink-500);font-size:13px;margin-bottom:12px;">
                أو نزّل ملف كل فاتورة لوحدها — زي ما لو فتحتها من تاب الفواتير.
            </p>
            <?php foreach ($grpInvoices as $gi): ?>
                <?php
                    $sth  = (int) $gi['sth_id'];
                    $did  = (int) ($grpData['dist_ids'][$sth] ?? 0);
                    $bt   = $grpBtMap[$did] ?? [];
                    // فيه أي فرع اتوزّعله حاجة في الفاتورة دي؟
                    $anyB = false;
                    foreach ($activeBranches as $b) {
                        if ((float)($bt[(int)$b['id']] ?? 0) > 0) { $anyB = true; break; }
                    }
                ?>
                <div style="border:1.5px solid var(--line);border-radius:12px;padding:14px 16px;margin-bottom:10px;" data-receiving-open="<?= ($isGrpReceivingOpened || !empty($grpSoloOpened[$did])) ? '1' : '0' ?>">
                    <div style="font-weight:600;color:var(--ink-900);font-size:14px;margin-bottom:10px;">
                        فاتورة <?= e($gi['invoice_no']) ?>
                        <span style="font-weight:400;color:var(--ink-500);font-size:13px;">
                            — <?= e($gi['supplier_name'] ?: '—') ?>
                        </span>
                    </div>
                    <?php if ($isGrpReceivingOpened && empty($grpSoloOpened[$did])): ?>
                        <div style="font-size:12px;color:var(--ink-500);margin-bottom:8px;">
                            المجموعة اتبعتت خلاص — الملف ده <b>تقرير بس</b>، متحمّلوش على برنامج الفرع تاني.
                        </div>
                    <?php endif; ?>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;" data-invwrap="<?= (int)$sth ?>">
                        <?php foreach ($activeBranches as $b): ?>
                            <?php $tot = (float)($bt[(int)$b['id']] ?? 0); ?>
                            <a class="btn grp-dl" style="background:var(--pos-fill);padding:8px 14px;font-size:13px;<?= $tot > 0 ? '' : 'display:none;' ?>"
                               data-sth="<?= (int)$sth ?>" data-branch="<?= (int)$b['id'] ?>"
                               href="branch_export.php?invoice=<?= e((string)$sth) ?>&branch=<?= (int)$b['id'] ?>&suffix=<?= e((string)$gi['suffix']) ?>&group=<?= (int)$grpCurrent['id'] ?>">
                                <i class="ti ti-download"></i> <?= e($b['name']) ?>
                                <span class="dl-count" style="opacity:.8;font-size:12px;">(<?= number_format($tot,0) ?>)</span>
                            </a>
                        <?php endforeach; ?>
                        <span class="grp-none" data-sth="<?= (int)$sth ?>"
                              style="color:var(--ink-400);font-size:13px;align-self:center;<?= $anyB ? 'display:none;' : '' ?>">
                            لسه ماتوزّعش منها حاجة
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php elseif ($tab === 'discount'): ?>
    <!-- ===== حساب الخصم ===== -->
    <div class="card" style="margin-bottom:16px;">
        <div class="section-title">اختر الفاتورة</div>
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
            <button type="button" class="btn btn-primary" onclick="openInvPicker()">
                <i class="ti ti-search"></i> اختر فاتورة
            </button>
            <?php if ($discHeader): ?>
                <span style="color:var(--ink-700);font-size:14px;">
                    المختارة: <b><?= e($discHeader['inv_no']) ?></b>
                    — <?= e($discHeader['supplier_name'] ?? '') ?>
                </span>
            <?php else: ?>
                <span style="color:var(--ink-400);font-size:14px;">مفيش فاتورة مختارة</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- بوب أب اختيار الفاتورة -->
    <div class="picker-overlay" id="invPicker" onclick="if(event.target===this)closeInvPicker()">
        <div class="picker-box">
            <div class="picker-head">
                <span>اختر فاتورة</span>
                <button type="button" class="picker-x" onclick="closeInvPicker()">&times;</button>
            </div>
            <div class="picker-search">
                <input type="text" id="pickerSearch" placeholder="ابحث برقم الفاتورة أو اسم المورد..."
                       aria-label="بحث في الفواتير"
                       oninput="filterPicker()" autocomplete="off">
            </div>
            <div class="picker-body" id="pickerBody">
                <?php if ($discUnposted): ?>
                    <div class="picker-group">غير محفوظة (<?= count($discUnposted) ?>)</div>
                    <?php foreach ($discUnposted as $iv): ?>
                        <a class="picker-row" data-s="<?= e(mb_strtolower($iv['inv_no'].' '.($iv['supplier_name'] ?? ''))) ?>"
                           href="invoices.php?tab=discount&dinv=<?= e((string)$iv['inv_id']) ?>&dsuf=<?= e((string)($iv['suffix'] ?? '')) ?>">
                            <span class="pk-no"><?= e($iv['inv_no']) ?></span>
                            <span class="pk-sup"><?= e($iv['supplier_name'] ?? '—') ?></span>
                            <span class="pk-date"><?= e(date('Y-m-d', strtotime((string)$iv['invoice_date']))) ?></span>
                            <span class="pk-amt"><?= number_format((float)$iv['net_amount'],2) ?></span>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if ($discPosted): ?>
                    <div class="picker-group">مرحّلة (<?= count($discPosted) ?>)</div>
                    <?php foreach ($discPosted as $iv): ?>
                        <a class="picker-row" data-s="<?= e(mb_strtolower($iv['inv_no'].' '.($iv['supplier_name'] ?? ''))) ?>"
                           href="invoices.php?tab=discount&dinv=<?= e((string)$iv['inv_id']) ?>&dsuf=<?= e((string)($iv['suffix'] ?? '')) ?>">
                            <span class="pk-no"><?= e($iv['inv_no']) ?></span>
                            <span class="pk-sup"><?= e($iv['supplier_name'] ?? '—') ?></span>
                            <span class="pk-date"><?= e(date('Y-m-d', strtotime((string)$iv['invoice_date']))) ?></span>
                            <span class="pk-amt"><?= number_format((float)$iv['net_amount'],2) ?></span>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
                <div class="pk-empty" id="pkEmpty" style="display:none;">مفيش نتائج</div>
            </div>
        </div>
    </div>

    <?php if ($discCalc): ?>
        <?php $t = $discCalc['totals']; $rate = $discCalc['rate']; ?>
        <div class="card" style="margin-bottom:16px;">
            <div class="disc-summary">
                <div><span>الفاتورة</span><b><?= e($discHeader['inv_no']) ?></b></div>
                <div><span>المورد</span><b><?= e($discHeader['supplier_name'] ?? '—') ?></b></div>
                <div><span>الإجمالي</span><b><?= number_format($t['header_total'],2) ?></b></div>
                <div><span>الصافي<?= $discNetOverride !== null ? ' (يدوي)' : '' ?></span><b><?= number_format($t['header_net'],2) ?></b></div>
                <div><span>نسبة الخصم</span>
                    <b class="<?= $rate < 0 ? 'num-neg' : ($rate > 0 ? 'num-pos' : '') ?>"><?= number_format($rate * 100, 2) ?>%</b>
                </div>
            </div>
            <!-- الإجمالي بعد يدوي: لو فيه خصم إضافي أو ضريبة قيمة مضافة -->
            <form method="get" action="invoices.php"
                  style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:14px;padding-top:14px;border-top:1px solid var(--line);">
                <input type="hidden" name="tab"  value="discount">
                <input type="hidden" name="dinv" value="<?= e((string)$discInvId) ?>">
                <input type="hidden" name="dsuf" value="<?= e((string)($discSuffix ?? '')) ?>">
                <label for="dnetInput" style="font-size:13px;color:var(--ink-500);">
                    الإجمالي بعد الخصم / الضريبة:
                </label>
                <input type="number" step="0.01" min="0" id="dnetInput" name="dnet"
                       value="<?= $discNetOverride !== null ? e((string)$discNetOverride) : '' ?>"
                       placeholder="<?= e(number_format($t['header_net'],2,'.','')) ?>"
                       style="width:150px;padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                <button type="submit" class="btn" style="background:var(--accent);">
                    <i class="ti ti-calculator"></i> احسب
                </button>
                <?php if ($discNetOverride !== null): ?>
                    <a class="btn" style="background:var(--ink-500);"
                       href="invoices.php?tab=discount&dinv=<?= e((string)$discInvId) ?>&dsuf=<?= e((string)($discSuffix ?? '')) ?>">
                        رجّع صافي الفاتورة
                    </a>
                <?php endif; ?>
                <span style="font-size:12px;color:var(--ink-500);">
                    سيبها فاضية = صافي الفاتورة من السيستم
                </span>
            </form>
        </div>

        <div class="card" style="margin-bottom:16px;">
            <div class="section-title">الأصناف بعد الخصم</div>
            <table class="lines-table" id="discTable"
                   data-rate="<?= e((string)$rate) ?>"
                   data-dp="<?= DISC_PRICE_DP ?>">
                <thead><tr>
                    <th>كود الصنف</th><th>اسم الصنف</th><th>الكمية</th>
                    <th>السعر</th><th>نسبة الصنف %</th><th>السعر بعد الخصم</th><th>الإجمالي بعد الخصم</th>
                </tr></thead>
                <tbody>
                <?php foreach ($discCalc['items'] as $ri => $r): ?>
                    <?php $isLocked = !empty($r['locked']); ?>
                    <tr class="disc-row <?= $isLocked ? 'disc-locked' : '' ?>"
                        data-qty="<?= e((string)$r['quantity']) ?>"
                        data-price="<?= e((string)$r['unit_price']) ?>"
                        data-adjusted="<?= !empty($r['adjusted']) ? '1' : '0' ?>"
                        data-server-after="<?= e(disc_price_txt((float)$r['price_after'])) ?>"
                        data-server-total="<?= e(number_format($r['total_after'],2,'.','')) ?>">
                        <td class="c-code"><?= e($r['item_code']) ?></td>
                        <td class="c-name"><?= e($r['item_name']) ?></td>
                        <td class="c-qty"><?= rtrim(rtrim(number_format($r['quantity'],2,'.',''), '0'), '.') ?></td>
                        <td class="c-cost"><?= number_format($r['unit_price'],2) ?></td>
                        <td class="c-pct">
                            <input type="number" class="disc-pct-inp" min="0" max="100" step="0.01"
                                   inputmode="decimal" placeholder="—"
                                   value="<?= $r['own_pct'] !== null ? e(rtrim(rtrim(number_format($r['own_pct'],2,'.',''),'0'),'.')) : '' ?>"
                                   data-code="<?= e($r['item_code']) ?>"
                                   aria-label="نسبة خصم الصنف"
                                   oninput="discPctLive(this)"
                                   onchange="discPctSync()"
                                   onkeydown="if(isEnterKey(event)){event.preventDefault();this.blur();}">
                        </td>
                        <td class="c-after js-after"><?= disc_price_txt((float)$r['price_after']) ?><?php
                            if ($isLocked) echo '<span class="disc-star" title="خصم خاص بالصنف — بيتجاهل خصم الفاتورة" style="color:var(--accent-text);font-size:11px;"> ★</span>';
                            elseif (!empty($r['adjusted'])) echo '<span class="disc-dot" title="اتعدّل بأقل وحدة ممكنة لضبط الإجمالي" style="color:var(--warn);font-size:11px;"> ●</span>';
                        ?></td>
                        <td class="c-cost js-total"><?= number_format($r['total_after'],2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr class="disc-foot">
                    <td colspan="2">الإجمالي</td>
                    <td><?= rtrim(rtrim(number_format($t['qty'],2,'.',''), '0'), '.') ?></td>
                    <td>—</td><td>—</td><td>—</td>
                    <td id="discGrand"><?= number_format($t['after'],2) ?></td>
                </tr></tfoot>
            </table>
        </div>

        <?php $resd = (float) ($discCalc['residual'] ?? 0); ?>
        <?php $hasLocked = !empty($discCalc['has_locked']); ?>
        <div class="card" style="margin-bottom:16px;">
            <!-- بانر خصم الصنف — بيظهر بالـJS أول ما تكتب نسبة، من غير ريفرش -->
            <div id="discLockedMsg" style="<?= $hasLocked ? '' : 'display:none;' ?>">
                <div style="display:flex;gap:12px;align-items:flex-start;background:var(--pos-soft);
                            color:var(--pos);padding:14px 16px;border-radius:12px;font-size:13.5px;line-height:1.8;">
                    <i class="ti ti-discount-2" style="font-size:20px;flex-shrink:0;"></i>
                    <div>
                        <b>فيه أصناف بخصم خاص ★.</b> الأصناف اللي كتبت لها نسبة بتتحسب
                        بنسبتها لوحدها وبتتجاهل خصم الفاتورة الكلي. باقي الأصناف ماشية
                        بخصم الفاتورة زي الأول. امسح النسبة عشان الصنف يرجع لخصم الفاتورة.
                    </div>
                </div>
            </div>
            <!-- رسايل الصافي — بتتخفي بالـJS لما يبقى فيه نسبة صنف -->
            <div id="discNetMsg" style="<?= $hasLocked ? 'display:none;' : '' ?>">
            <?php if (abs($resd) <= 0.004): ?>
                <div style="display:flex;gap:12px;align-items:flex-start;background:var(--pos-soft);
                            color:var(--pos);padding:14px 16px;border-radius:12px;font-size:13.5px;line-height:1.8;">
                    <i class="ti ti-circle-check" style="font-size:20px;flex-shrink:0;"></i>
                    <div>
                        <b>مطابق تماماً.</b> مجموع السطور = <?= number_format($t['after'],2) ?>
                        وهو نفس الصافي المطلوب. نظام الشركة هيطلّع نفس الرقم.
                    </div>
                </div>
            <?php else: ?>
                <div style="display:flex;gap:12px;align-items:flex-start;background:var(--warn-soft);
                            color:var(--warn);padding:14px 16px;border-radius:12px;font-size:13.5px;line-height:1.8;">
                    <i class="ti ti-alert-triangle" style="font-size:20px;flex-shrink:0;"></i>
                    <div>
                        <b>فرق تقريب: <?= number_format($resd,2) ?> ج</b> —
                        مجموع السطور <?= number_format($t['after'],2) ?> مقابل صافي مطلوب
                        <?= number_format($t['header_net'],2) ?>.<br>
                        الأسعار بـ<?= DISC_PRICE_DP ?> خانات عشرية والكميات صحيحة، فمش كل رقم
                        صافي ينفع يتوصّل له بالظبط. ده أقرب رقم ممكن — وهو
                        <b>نفس اللي هيطلع في نظام الشركة</b>، مفيش مفاجأة بعد الاستيراد.
                    </div>
                </div>
            <?php endif; ?>
            </div><!-- /discNetMsg -->
        </div>

        <div class="card" style="margin-bottom:16px;">
            <div class="section-title">تصدير</div>
            <p style="color:var(--ink-500);font-size:13px;margin-bottom:14px;">
                ملف نصي: كود الصنف + Tab + الكمية + Tab + السعر بعد الخصم
                (لغاية <?= DISC_PRICE_DP ?> خانات عشرية — زي ما نظام الشركة بيخزّن).
            </p>
            <a class="btn" id="discExportBtn" style="background:var(--fill-strong);color:var(--fill-strong-fg);"
               data-base="discount_export.php?invoice=<?= e(rawurlencode((string)$discInvId)) ?>&suffix=<?= e(rawurlencode((string)($discSuffix ?? ''))) ?><?= $discNetOverride !== null ? '&dnet=' . e(rawurlencode((string)$discNetOverride)) : '' ?>"
               href="discount_export.php?invoice=<?= e((string)$discInvId) ?>&suffix=<?= e((string)($discSuffix ?? '')) ?><?= $discNetOverride !== null ? '&dnet=' . e((string)$discNetOverride) : '' ?><?= $dpctRaw !== '' ? '&dpct=' . e(rawurlencode($dpctRaw)) : '' ?>">
                <i class="ti ti-download"></i> تصدير ملف الخصم
            </a>
        </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="card">
        <div class="list-head">
            <div class="section-title" style="margin:0;">
                <?= $tab==='unposted' ? 'فواتير غير محفوظة' : 'كل فواتير الشراء' ?>
                <span class="list-count" id="listCount"></span>
            </div>
            <div class="list-search">
                <i class="ti ti-search"></i>
                <input type="text" id="invSearch" placeholder="ابحث برقم الفاتورة أو المورد..."
                       aria-label="بحث في الفواتير" autocomplete="off" oninput="filterInvList()">
                <button type="button" class="ls-clear" onclick="clearInvSearch()" title="مسح">&times;</button>
            </div>
        </div>

        <?php
        $adminCount = 0;
        $branchCount = 0;
        foreach ($invoiceGroups as $g) {
            $cnt = count($g['invoices']);
            if (!empty($g['is_admin']) || (int)($g['branch_id'] ?? 0) === 1) {
                $adminCount += $cnt;
            } else {
                $branchCount += $cnt;
            }
        }
        ?>

        <script>
        /* 🔴 الدوال دي **لازم تفضل فوق الجدول**.
               الجدول تحتيها `foreach` على كل الفواتير — في تاب «المرحّلة»
               بيطلّع آلاف الصفوف. المتصفح بيرسمها ويسيب المستخدم يدوس
               قبل ما يوصل للسكربت اللي في آخر الصفحة.
               لما كانوا تحت، اللي يدوس على رأس فرع عشان يطويه كان بياخد
               «toggleSubtype is not defined» والضغطة تضيع (اتسجّلت على
               السيرفر ٢٠ سبتمبر ٢٠٢٦). متنقلهمش تحت تاني. */
        // ===== حفظ حالة الأكورديونات لحد نهاية الجلسة =====
        // sessionStorage بيتمسح لوحده مع إغلاق التاب أو تسجيل الخروج،
        // فمش محتاجين تنضيف يدوي.
        const ACC_KEY = 'fagala_acc_state';

        function accLoad() {
            try { return JSON.parse(sessionStorage.getItem(ACC_KEY) || '{}'); }
            catch (e) { return {}; }
        }

        function accSave(key, collapsed) {
            if (!key) return;
            try {
                const st = accLoad();
                if (collapsed) st[key] = 1; else delete st[key];
                sessionStorage.setItem(ACC_KEY, JSON.stringify(st));
            } catch (e) { /* الوضع الخاص ممكن يمنع الكتابة — نتجاهل */ }
        }

        // إعادة الحالة المحفوظة بعد تحميل الصفحة
        function accRestore() {
            const st = accLoad();
            if (!st || !Object.keys(st).length) return;

            // الفروع الأول، بعدين الأنواع الفرعية — عشان الترتيب يتحسب صح
            document.querySelectorAll('tr[data-branchhead]').forEach(h => {
                if (st[h.dataset.acckey]) toggleBranch(h, true);
            });
            document.querySelectorAll('tr[data-subhead]').forEach(h => {
                if (st[h.dataset.acckey]) toggleSubtype(h, true);
            });
        }

        function toggleBranch(head, silent) {
            const collapsed = head.classList.toggle('collapsed');
            if (!silent) accSave(head.dataset.acckey, collapsed);
            let sib = head.nextElementSibling;
            while (sib && !sib.dataset.branchhead) {
                if (sib.dataset.subhead) {
                    // الرأس الفرعي: نخفيه/نظهره مع الفرع، ونسيبه مطوي لو كان مطوي
                    sib.style.display = collapsed ? 'none' : '';
                } else if (sib.classList.contains('inv-row')) {
                    if (collapsed) sib.dataset.branchCollapsed = '1';
                    else           delete sib.dataset.branchCollapsed;
                }
                sib = sib.nextElementSibling;
            }
        }

        // طي/فرد مجموعة فرعية (فاتورة شراء / مردود شراء) داخل الفرع
        function toggleSubtype(head, silent) {
            const collapsed = head.classList.toggle('collapsed');
            if (!silent) accSave(head.dataset.acckey, collapsed);
            let sib = head.nextElementSibling;
            while (sib && !sib.dataset.subhead && !sib.dataset.branchhead) {
                if (sib.classList.contains('inv-row')) {
                    if (collapsed) sib.dataset.subCollapsed = '1';
                    else           delete sib.dataset.subCollapsed;
                }
                sib = sib.nextElementSibling;
            }
        }
        </script>

        <!-- أزرار عزل فواتير الإدارة عن باقي الفروع -->
        <div class="scope-filters">
            <button type="button" class="scope-btn active" data-scope="admin" onclick="setScopeFilter('admin', this)">
                <i class="ti ti-building-bank"></i> فواتير الإدارة (المركز الرئيسي) <span><?= $adminCount ?></span>
            </button>
            <button type="button" class="scope-btn" data-scope="branches" onclick="setScopeFilter('branches', this)">
                <i class="ti ti-building-store"></i> فواتير الفروع الأخرى <span><?= $branchCount ?></span>
            </button>
            <button type="button" class="scope-btn" data-scope="all" onclick="setScopeFilter('all', this)">
                <i class="ti ti-list-details"></i> كل الفواتير <span><?= $adminCount + $branchCount ?></span>
            </button>
        </div>

        <?php if ($tab==='posted'): ?>
        <div class="status-filters">
            <button type="button" class="sf-btn active" data-st="all"   onclick="setStatusFilter(this)">الكل <span></span></button>
            <button type="button" class="sf-btn"        data-st="new"   onclick="setStatusFilter(this)">جديدة <span></span></button>
            <button type="button" class="sf-btn"        data-st="split" onclick="setStatusFilter(this)">تم التقسيم <span></span></button>
            <button type="button" class="sf-btn"        data-st="done"  onclick="setStatusFilter(this)">تم التوزيع <span></span></button>
        </div>
        <?php endif; ?>

        <div class="no-match" id="noMatch" style="display:none;">مفيش فواتير مطابقة.</div>

        <?php if (empty($invoiceGroups)): ?>
            <div class="empty">لا توجد فواتير.</div>
        <?php else: ?>
        <table class="inv-table">
            <colgroup>
                <col style="width:48px;">
                <col style="width:13%;">
                <col style="width:12%;">
                <col style="width:11%;">
                <col>
                <col style="width:13%;">
                <col style="width:13%;">
                <?php if ($tab==='posted'): ?><col style="width:13%;"><?php endif; ?>
            </colgroup>
            <thead><tr>
                <th>#</th><th>رقم الفاتورة</th><th>التاريخ</th>
                <th>كود المورد</th><th>اسم المورد</th><th>الإجمالي</th><th>الصافي</th>
                <?php if ($tab==='posted'): ?><th>الحالة</th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php $rowNo = 0; foreach ($invoiceGroups as $g): ?>
                <?php
                    $colspan = $tab==='posted' ? 8 : 7;
                    $isAdm = !empty($g['is_admin']) || (int)($g['branch_id'] ?? 0) === 1;
                    $gScope = $isAdm ? 'admin' : 'branch';
                    $bIcon = $isAdm ? 'ti-building-bank' : 'ti-building-store';
                ?>
                <tr class="branch-head<?= $isAdm ?' branch-admin':'' ?>" data-branchhead="1"
                    data-scope="<?= $gScope ?>"
                    data-acckey="br:<?= e((string)($g['branch_name'] ?? '')) ?>"
                    onclick="toggleBranch(this)" role="button" tabindex="0" title="اضغط لطي/فرد فواتير الفرع">
                    <td colspan="<?= $colspan ?>">
                        <i class="ti ti-chevron-down bh-caret"></i>
                        <i class="ti <?= $bIcon ?>"></i>
                        <?= e($isAdm ? 'الإدارة (المركز الرئيسي)' : $g['branch_name']) ?>
                        <span class="bh-count"><?= count($g['invoices']) ?> فاتورة</span>
                    </td>
                </tr>
                <?php
                    // نقسّم فواتير الفرع لمجموعتين: شراء (كل حاجة مش 6) ومردود (6)
                    $subPurchase = [];
                    $subRefund   = [];
                    foreach ($g['invoices'] as $inv) {
                        if ((int)($inv['doc_type'] ?? 0) === 6) $subRefund[] = $inv;
                        else $subPurchase[] = $inv;
                    }
                    $subGroups = [];
                    if ($subPurchase) $subGroups[] = ['kind'=>'purchase','label'=>'فاتورة شراء','icon'=>'ti-file-invoice','rows'=>$subPurchase];
                    if ($subRefund)   $subGroups[] = ['kind'=>'refund','label'=>'مردود شراء','icon'=>'ti-arrow-back-up','rows'=>$subRefund];
                ?>
                <?php foreach ($subGroups as $sg): ?>
                <tr class="subtype-head subtype-<?= e($sg['kind']) ?>" data-subhead="1"
                    data-scope="<?= $gScope ?>"
                    data-acckey="sub:<?= e((string)($g['branch_name'] ?? '')) ?>:<?= e((string)$sg['kind']) ?>"
                    onclick="toggleSubtype(this)" role="button" tabindex="0" title="اضغط لطي/فرد">
                    <td colspan="<?= $colspan ?>">
                        <i class="ti ti-chevron-down st-caret"></i>
                        <i class="ti <?= e($sg['icon']) ?>"></i>
                        <?= e($sg['label']) ?>
                        <span class="bh-count"><?= count($sg['rows']) ?></span>
                    </td>
                </tr>
                <?php foreach ($sg['rows'] as $inv): $rowNo++;
                    $invNo = (string)($inv['inv_no'] ?? '');
                    $invCb = isset($inv['company_branch_id']) && $inv['company_branch_id'] !== '' ? (string)$inv['company_branch_id'] : (isset($inv['company_branch']) && $inv['company_branch'] !== '' ? (string)$inv['company_branch'] : ($isAdm ? '1' : '*'));
                    $invDt = isset($inv['doc_type']) && $inv['doc_type'] !== '' ? (string)$inv['doc_type'] : '*';
                    $fullKey = $invNo . '|' . $invCb . '|' . $invDt;
                    $fallbackKey = $invNo . '|*|*';
                    $st = $invStatuses[$fullKey] ?? ($invStatuses[$fallbackKey] ?? ($invStatuses[$invNo] ?? 'new'));
                    [$stLabel, $stClass] = invoice_status_label($st);
                    $nTxt = trim((string)($invNotes[$fullKey] ?? ($invNotes[$fallbackKey] ?? ($invNotes[$invNo] ?? ''))));
                ?>
                    <tr class="inv-row<?= $st==='done'?' inv-done':'' ?>"
                        data-st="<?= e($st) ?>"
                        data-scope="<?= $gScope ?>"
                        data-s="<?= e(mb_strtolower($invNo.' '.($inv['supplier_name'] ?? '').' '.($inv['supplier_code'] ?? '').' '.((int)($inv['doc_type'] ?? 0) === 6 ? 'مردود شراء refund' : '').' '.$nTxt)) ?>"
                        onclick="openInvoice('<?= e($inv['inv_id']) ?>','<?= e($inv['suffix'] ?? '') ?>','<?= e($invCb !== '*' ? $invCb : '') ?>','<?= e($invDt !== '*' ? $invDt : '') ?>')" title="اضغط لفتح أصناف الفاتورة">
                        <td><?= $rowNo ?></td>
                        <td class="inv-no">
                            <?= e($invNo) ?>
                            <?php if ((int)($inv['doc_type'] ?? 0) === 6): ?>
                                <span class="doc-badge refund">مردود شراء</span>
                            <?php endif; ?>
                            <?php if ($nTxt !== ''): ?>
                                <span class="inv-note-text" title="<?= e($nTxt) ?>"><i class="ti ti-note"></i> <?= e(mb_strimwidth($nTxt, 0, 120, '…')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:13px;"><?= e(date('Y-m-d', strtotime((string)$inv['invoice_date']))) ?></td>
                        <td style="font-family:monospace;color:var(--ink-500);"><?= e($inv['supplier_code'] ?? '—') ?></td>
                        <td class="inv-supplier" style="font-weight:500;text-align:right;" title="<?= e($inv['supplier_name'] ?? '') ?>"><?= e($inv['supplier_name'] ?? '—') ?></td>
                        <td><?= number_format((float)$inv['total_amount'],2) ?></td>
                        <td><?= number_format((float)$inv['net_amount'],2) ?></td>
                        <?php if ($tab==='posted'): ?>
                            <td><span class="st-badge <?= $stClass ?>"><?= e($stLabel) ?></span></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php endforeach; /* المجموعات الفرعية (شراء/مردود) */ ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>

<script>
    // ===== نسبة خصم لكل صنف — حساب فوري في المتصفح (بدون ريفرش) =====
    //
    //  بيطابق منطق PHP بالظبط:
    //    السعر بعد الخصم = round(السعر × (1 − نسبة/100), DP)
    //    قيمة السطر      = round(الكمية × السعر بعد الخصم, 2)
    //
    //  الصف اللي فيه نسبة → بيتحسب بنسبته (locked).
    //  الصف الفاضي:
    //     • مفيش أي نسبة في الفاتورة كلها → يرجع لقيمة السيرفر
    //       (اللي فيها التسوية الذكية) زي ما هي.
    //     • فيه نسبة في صنف تاني → التسوية بتتوقف على السيرفر،
    //       فبنحسب الصف بخصم الفاتورة العادي من غير تسوية.
    //
    //  ملف التصدير بيتحدّث لينكه بس؛ الحساب الفعلي بيتعمل على
    //  السيرفر وقت التنزيل بنفس المعادلة دي بالظبط.
    // =============================================================
    const _dt = document.getElementById('discTable');
    const DISC_RATE = _dt ? parseFloat(_dt.dataset.rate || '0') : 0;   // نسبة خصم الفاتورة (سالبة)
    const DISC_DP   = _dt ? parseInt(_dt.dataset.dp || '3', 10) : 3;

    function _round(v, dp) {
        const f = Math.pow(10, dp);
        return Math.round((v + Number.EPSILON) * f) / f;
    }
    // عرض السعر بعد الخصم: لغاية DP خانات، بحد أدنى خانتين، بدون أصفار زيادة
    function _fmtAfter(v) {
        let s = v.toFixed(DISC_DP);
        if (DISC_DP > 2) {
            s = s.replace(/0+$/, '');
            const dec = s.length - s.indexOf('.') - 1;
            if (s.indexOf('.') === -1) s += '.00';
            else if (dec < 2) s = v.toFixed(2);
        }
        return s;
    }
    function _fmt2(v) {
        return v.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    // في أي نسبة صنف مكتوبة دلوقتي؟
    function _anyPct() {
        let any = false;
        document.querySelectorAll('.disc-pct-inp').forEach(function (el) {
            const v = parseFloat(el.value);
            if (!isNaN(v) && v > 0 && v <= 100) any = true;
        });
        return any;
    }

    // إعادة حساب صف واحد (بيرجّع قيمة السطر عشان نجمّعها)
    function _recalcRow(tr) {
        const inp = tr.querySelector('.disc-pct-inp');
        const qty = parseFloat(tr.dataset.qty || '0');
        const prc = parseFloat(tr.dataset.price || '0');
        const afterCell = tr.querySelector('.js-after');
        const totalCell = tr.querySelector('.js-total');

        let pct = parseFloat(inp.value);
        const hasPct = (!isNaN(pct) && pct > 0 && pct <= 100);

        // لو الصف فاضي ومفيش أي نسبة في الفاتورة → رجّع قيمة السيرفر بالظبط
        if (!hasPct && !_anyPct()) {
            tr.classList.toggle('disc-locked', false);
            const sa = tr.dataset.serverAfter || '';
            const st = parseFloat(tr.dataset.serverTotal || '0');
            let badge = '';
            if (tr.dataset.adjusted === '1')
                badge = '<span class="disc-dot" title="اتعدّل بأقل وحدة ممكنة لضبط الإجمالي" style="color:var(--warn);font-size:11px;"> ●</span>';
            afterCell.innerHTML = sa + badge;
            totalCell.textContent = _fmt2(st);
            return st;
        }

        // نسبة الصف: خصم الصنف لو مكتوب، وإلا خصم الفاتورة
        const rate  = hasPct ? (-pct / 100) : DISC_RATE;
        const after = _round(prc * (1 + rate), DISC_DP);
        const total = _round(qty * after, 2);

        tr.classList.toggle('disc-locked', hasPct);
        afterCell.innerHTML = _fmtAfter(after) +
            (hasPct ? '<span class="disc-star" title="خصم خاص بالصنف — بيتجاهل خصم الفاتورة" style="color:var(--accent-text);font-size:11px;"> ★</span>' : '');
        totalCell.textContent = _fmt2(total);
        return total;
    }

    // حساب فوري وأنا بكتب (الصف ده + المجموع + لينك التصدير)
    let _pctTimer = null;
    function discPctLive() {
        let grand = 0;
        document.querySelectorAll('#discTable tbody tr.disc-row').forEach(function (tr) {
            grand += _recalcRow(tr);
        });
        const g = document.getElementById('discGrand');
        if (g) g.textContent = _fmt2(_round(grand, 2));
        _updateExportLink();

        // بدّل الرسالة: خصم صنف شغّال ولا الصافي العادي
        const any = _anyPct();
        const lk = document.getElementById('discLockedMsg');
        const nm = document.getElementById('discNetMsg');
        if (lk) lk.style.display = any ? '' : 'none';
        if (nm) nm.style.display = any ? 'none' : '';
    }

    // تحديث لينك التصدير بالنِسب الحالية (من غير ما نحمّل الصفحة)
    function _updateExportLink() {
        const btn = document.getElementById('discExportBtn');
        if (!btn) return;
        const parts = [];
        document.querySelectorAll('.disc-pct-inp').forEach(function (el) {
            const code = (el.dataset.code || '').trim();
            let v = parseFloat(el.value);
            if (code && !isNaN(v) && v > 0 && v <= 100) {
                v = Math.round(v * 100) / 100;
                parts.push(code + ':' + v);
            }
        });
        let href = btn.dataset.base;
        if (parts.length) href += '&dpct=' + encodeURIComponent(parts.join(','));
        btn.href = href;
    }

    // onchange: مفيش ريفرش خلاص — بس نتأكد إن كله متحدّث
    function discPctSync() { discPctLive(); }

    function branchRows(head) {
        const rows = [];
        let sib = head.nextElementSibling;
        while (sib && !sib.dataset.branchhead) {
            if (sib.classList.contains('inv-row')) rows.push(sib);
            sib = sib.nextElementSibling;
        }
        return rows;
    }

    let invFilter = { q: '', st: 'all', scope: 'admin' };

    function filterInvList() {
        const inp = document.getElementById('invSearch');
        invFilter.q = (inp ? inp.value : '').trim().toLowerCase();
        applyInvFilter();
    }

    function setScopeFilter(scope, btn) {
        document.querySelectorAll('.scope-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        invFilter.scope = scope;
        applyInvFilter();
    }

    function setStatusFilter(btn) {
        document.querySelectorAll('.sf-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        invFilter.st = btn.dataset.st;
        applyInvFilter();
    }

    function clearInvSearch() {
        const inp = document.getElementById('invSearch');
        if (inp) { inp.value = ''; inp.focus(); }
        invFilter.q = '';
        applyInvFilter();
    }

    function applyInvFilter() {
        const rows = document.querySelectorAll('.inv-row');
        let shown = 0, n = 0;
        rows.forEach(r => {
            const okQ  = !invFilter.q || (r.dataset.s || '').includes(invFilter.q);
            const okSt = invFilter.st === 'all' || (r.dataset.st || 'new') === invFilter.st;
            const okScope = invFilter.scope === 'all' || (r.dataset.scope === invFilter.scope) || (invFilter.scope === 'branches' && r.dataset.scope === 'branch');
            const hit  = okQ && okSt && okScope;
            if (hit) {
                delete r.dataset.filterHidden;
                shown++;
                // ترقيم متسلسل للمعروض بس
                const c = r.querySelector('td:first-child');
                if (c) c.textContent = ++n;
            } else {
                r.dataset.filterHidden = '1';
            }
        });
        // رأس الفرع يفضل ظاهر لو مطوي عمداً؛ يتخفى بس لو الفلتر شال كل فواتيره
        document.querySelectorAll('.branch-head').forEach(h => {
            const okScope = invFilter.scope === 'all' || (h.dataset.scope === invFilter.scope) || (invFilter.scope === 'branches' && h.dataset.scope === 'branch');
            const rows = branchRows(h);
            const anyMatch = rows.some(r => !r.dataset.filterHidden);
            h.style.display = (okScope && anyMatch) ? '' : 'none';
        });
        // نفس الشيء للرؤوس الفرعية (شراء/مردود)
        document.querySelectorAll('.subtype-head').forEach(h => {
            const okScope = invFilter.scope === 'all' || (h.dataset.scope === invFilter.scope) || (invFilter.scope === 'branches' && h.dataset.scope === 'branch');
            let any = false, sib = h.nextElementSibling;
            while (sib && !sib.dataset.subhead && !sib.dataset.branchhead) {
                if (sib.classList.contains('inv-row') && !sib.dataset.filterHidden) { any = true; break; }
                sib = sib.nextElementSibling;
            }
            // نخفيه بس لو فرعه مش مطوي (عشان ماينافسش طي الفرع)
            if (h.style.display !== 'none' || any) h.style.display = (okScope && any) ? '' : 'none';
        });
        const cnt = document.getElementById('listCount');
        if (cnt) cnt.textContent = shown + (shown === rows.length ? '' : ' من ' + rows.length);
        const nm = document.getElementById('noMatch');
        if (nm) nm.style.display = (shown === 0 && rows.length > 0) ? 'block' : 'none';
    }

    // عدّادات الحالة (مرة واحدة عند التحميل — مفيش أي طلب للسيرفر)
    (function initCounts() {
        const rows = document.querySelectorAll('.inv-row');
        if (!rows.length) return;
        const c = { all: rows.length, new: 0, split: 0, done: 0 };
        rows.forEach(r => { const k = r.dataset.st || 'new'; if (c[k] !== undefined) c[k]++; });
        document.querySelectorAll('.sf-btn').forEach(b => {
            const sp = b.querySelector('span');
            if (sp) sp.textContent = c[b.dataset.st] ?? 0;
        });
        // تطبيق الفلترة المبدئية لحصر العرض على فواتير الإدارة
        applyInvFilter();
    })();

    // إعادة حالة الأكورديونات المحفوظة (بعد العدّادات عشان الأرقام تتحسب على الكل)
    // 🔴 الحارس مش زيادة: `accRestore` متعرّفة في السكربت اللي **فوق جدول
    //    الفواتير**، وده جوّه فرع `else` بتاع التابات — يعني مابيترسمش
    //    في صفحة فاتورة مفتوحة ولا في تاب الخصم. أما النداء ده فبيترسم
    //    في كل الصفحات. من غير الحارس بتطلع
    //    «accRestore is not defined» (اتسجّلت ٨ مرات ٢١ سبتمبر ٢٠٢٦).
    //    والنداء أصلاً مالوش معنى من غير الجدول.
    if (typeof accRestore === 'function') accRestore();

    // فتح أصناف الفاتورة بالضغط على الصف كله (مع تمرير الفرع ونوع المستند)
    function openInvoice(id, suffix, cb, dt) {
        var url = '?invoice=' + encodeURIComponent(id) + '&suffix=' + encodeURIComponent(suffix);
        if (cb !== undefined && cb !== null && cb !== '') url += '&cb=' + encodeURIComponent(cb);
        if (dt !== undefined && dt !== null && dt !== '') url += '&dt=' + encodeURIComponent(dt);
        url += '&tab=<?= e($tab) ?>';
        window.location = url;
    }
</script>

<script>
    // أسماء الفروع (ديناميكي من التعريفات — يتحدّث لوحده لو ضفت فرع)
    const BRANCH_NAMES = <?= json_encode(array_column($activeBranches, 'name', 'id'), JSON_UNESCAPED_UNICODE) ?>;
    const BRANCH_LIST  = <?= json_encode(array_values(array_column($activeBranches, 'name')), JSON_UNESCAPED_UNICODE) ?>;

    // ===== تاب الخصم: بوب أب اختيار الفاتورة =====
    // ===================== الفواتير المجمّعة =====================
    const GROUP_ID = <?= (int)($grpCurrent['id'] ?? 0) ?>;

    function openGrpPicker() {
        document.getElementById('grpPicker').classList.add('open');
        // الأرقام في العناوين لازم تتظبط على الظاهر فعلاً من أول فتحة
        // (فواتير الفروع مخفية افتراضيًا)
        filterGrp();
        grpFilterInvList();
    }
    function closeGrpPicker() { document.getElementById('grpPicker').classList.remove('open'); }

    /* ===== شيل فاتورة / شيل صنف من المجموعة =====
       بيستخدم data-* مش onclick بمعاملات — الأسماء فيها علامات تنصيص
       وأقواس وكانت بتكسر الـattribute (بق موثّق في RULES.md).
       التوزيع مابيتمسحش — نفس مبدأ «امسح المجموعة». */
    function grpSubmitDrop(action, fields) {
        const f = document.getElementById('grpDropForm');
        if (!f) return;
        document.getElementById('grpDropAction').value = action;
        document.getElementById('grpDropSth').value  = fields.sth  || '';
        document.getElementById('grpDropSuf').value  = fields.suf  || '';
        document.getElementById('grpDropCode').value = fields.code || '';
        formSubmit(f);
    }

    document.addEventListener('click', function (ev) {
        const inv = ev.target.closest('.js-grp-drop');
        if (inv) {
            const no = inv.dataset.no || '';
            if (!confirm('تشيل فاتورة ' + no + ' من المجموعة؟\n\n'
                + 'التوزيع بتاعها هيفضل محفوظ، وهترجع تشتغل عادي من تاب الفواتير.\n'
                + 'تقدر تضيفها تاني في أي وقت وتلاقي شغلك زي ما هو.')) return;
            grpSubmitDrop('group_remove_invoice',
                { sth: inv.dataset.sth, suf: inv.dataset.suf });
            return;
        }

        const itm = ev.target.closest('.js-grp-dropitem');
        if (itm) {
            const nm = itm.dataset.name || itm.dataset.code || '';
            if (!confirm('تشيل «' + nm + '» من المجموعة؟\n\n'
                + 'هيتشال من كل فواتير المجموعة، والتوزيع اللي اتعمل عليه\n'
                + 'هيفضل محفوظ لو رجّعته تاني.')) return;
            grpSubmitDrop('group_remove_item', { code: itm.dataset.code });
        }
    });

    /* =================================================================
       بوب أب المجموعة — وضعان: [فواتير كاملة] و[تحديد أصناف]
       -----------------------------------------------------------------
       GRP_ADD_ID > 0 يعني إحنا بنضيف لمجموعة موجودة (مش بننشئ جديدة)،
       وده اللي بيحدد الـaction اللي هيتبعت للسيرفر.
       ================================================================= */
    // ⚠️ GROUP_ID (فوق) مش GRP_ID — GRP_ID متعرّف في بلوك سكربت تاني
    //    بعد ده، وقراءته هنا كانت هترمي ReferenceError. القيمة واحدة.
    const GRP_ADD_ID = GROUP_ID;            // مجموعة مفتوحة = إضافة
    let   grpModeCur = 'inv';               // الوضع الحالي
    const grpPicked  = {};                  // key -> {s,f,c,name,inv}

    function grpMode(m) {
        grpModeCur = m;
        document.querySelectorAll('.gm-btn').forEach(b =>
            b.classList.toggle('active', b.dataset.mode === m));
        const pi = document.getElementById('grpPanelInv');
        const pt = document.getElementById('grpPanelItem');
        if (pi) pi.style.display = m === 'inv'  ? '' : 'none';
        if (pt) pt.style.display = m === 'item' ? '' : 'none';

        const go = document.getElementById('grpGoTxt');
        if (go) go.textContent = GRP_ADD_ID > 0
            ? (m === 'item' ? 'ضيف الأصناف' : 'ضيف الفواتير')
            : (m === 'item' ? 'افتح مجموعة أصناف' : 'افتح المجموعة');

        if (m === 'item') { grpWay(grpWayCur); grpItemRefresh(); } else grpCount();
    }

    /**
     * Enter جوه خانات البحث كان بيبعت الفورم (إرسال ضمني) —
     * يعني مجموعة تتفتح بالغلط وانت بتكتب. ممنوع.
     */
    function grpNoEnter(ev) {
        if (ev.key === 'Enter') {
            ev.preventDefault();
            // Enter = دوّر دلوقتي من غير انتظار — كل خانة وشغلها
            if (ev.target.id === 'grpItemSearch')      grpFindItem();
            else if (ev.target.id === 'grpInvFilter')  grpFilterInvList();
            return false;
        }
        return true;
    }

    /** الـaction الصح حسب الوضع والسياق */
    function grpSubmit(ev) {
        const act = GRP_ADD_ID > 0
            ? (grpModeCur === 'item' ? 'group_add_items' : 'group_add_invoices')
            : (grpModeCur === 'item' ? 'group_create_items' : 'group_create');
        document.getElementById('grpAction').value = act;

        if (grpModeCur === 'inv') {
            const n = document.querySelectorAll('#grpBody input[name="pick[]"]:checked').length;
            if (n < (GRP_ADD_ID > 0 ? 1 : 2)) { ev.preventDefault(); return false; }
        }

        if (grpModeCur === 'item') {
            const list = Object.keys(grpPicked).map(k => {
                const p = grpPicked[k];
                return { s: p.s, f: p.f, c: p.c };
            });
            if (!list.length) { ev.preventDefault(); return false; }
            document.getElementById('grpIpicks').value = JSON.stringify(list);
            // اسم المجموعة في وضع الأصناف خانة منفصلة
            const ni = document.getElementById('grpNameI');
            const nm = document.getElementById('grpName');
            if (ni && nm) nm.value = ni.value;
        }
        const go = document.getElementById('grpGo');
        if (go) go.disabled = true;   // منع الضغط المتكرر
        return true;
    }

    function grpCount() {
        const n = document.querySelectorAll('#grpBody input[name="pick[]"]:checked').length;
        const go = document.getElementById('grpGo');
        const hint = document.getElementById('grpHint');
        // إضافة لمجموعة موجودة: فاتورة واحدة كفاية.
        // مجموعة جديدة: فاتورتين على الأقل (الواحدة تتفتح عادي من تاب الفواتير).
        const min = GRP_ADD_ID > 0 ? 1 : 2;
        go.disabled = n < min;
        hint.textContent = n < min
            ? (min === 1 ? 'اختار فاتورة أو أكتر' : 'اختار فاتورتين على الأقل')
            : (n + ' فاتورة مختارة');
        hint.style.color = n < min ? 'var(--ink-500)' : 'var(--pos)';
    }

    // ---------- طريقتين للوصول للصنف: بحث · تصفّح بالفاتورة ----------
    let grpWayCur = 'find';

    let grpFromGroup = 0;   // المجموعة اللي بنتصفّح فواتيرها (0 = كل الفواتير)

    function grpWay(w) {
        grpWayCur = w;
        grpFromGroup = 0;
        document.querySelectorAll('.giw-btn').forEach(b =>
            b.classList.toggle('active', b.dataset.way === w));
        ['find', 'browse', 'grp'].forEach(function (k) {
            const el = document.getElementById('grpWay' + k.charAt(0).toUpperCase() + k.slice(1));
            if (el) el.style.display = (k === w) ? '' : 'none';
        });

        grpHideAllBodies();
        if (w === 'browse') {
            grpBackToInvoices();
        } else if (w === 'grp') {
            document.getElementById('grpGrpList').style.display = '';
        } else {
            document.getElementById('grpItemBody').style.display = '';
            const inp = document.getElementById('grpItemSearch');
            if (inp) inp.focus();
        }
    }

    function grpHideAllBodies() {
        ['grpCrumb', 'grpInvList', 'grpItemBody', 'grpGrpList'].forEach(function (id) {
            const el = document.getElementById(id);
            if (el) el.style.display = 'none';
        });
    }

    /** إظهار/إخفاء فواتير الفروع في القايمتين */
    function grpShowBranches(on) {
        document.querySelectorAll('[data-adm="0"]').forEach(function (r) {
            r.dataset.hidebranch = on ? '' : '1';
            r.style.display = on ? '' : 'none';
        });
        // خلي الشخاطتين متطابقتين
        document.querySelectorAll('.grp-admtog input').forEach(c => { c.checked = on; });
        filterGrp();
        grpFilterInvList();
    }

    function grpBranchesShown() {
        const c = document.querySelector('.grp-admtog input');
        return c ? c.checked : true;
    }

    /** فواتير مجموعة موجودة */
    function grpOpenGroup(gid) {
        grpFromGroup = gid;
        grpHideAllBodies();
        const crumb = document.getElementById('grpCrumb');
        const body  = document.getElementById('grpItemBody');
        crumb.style.display = '';
        body.style.display  = '';
        document.getElementById('grpCrumbTitle').textContent = 'بيحمّل…';
        body.innerHTML = '<div class="pk-empty">بيجيب فواتير المجموعة…</div>';

        fetch('invoices.php?gpick=ginv&g2=' + gid)
            .then(r => r.json())
            .then(d => {
                if (!d.ok) throw new Error(d.error || 'حصل خطأ.');
                document.getElementById('grpCrumbTitle').textContent = d.title;
                body.innerHTML = d.rows.length ? d.rows.map(function (r) {
                    return '<div class="picker-row gi-invrow"'
                        + ' onclick="grpOpenInvoice(' + r.sth_id + ", '" + r.suffix + "')\">"
                        + '<span class="pk-no">' + grpEsc(r.invoice_no) + '</span>'
                        + '<span class="pk-sup">' + grpEsc(r.supplier || '—') + '</span>'
                        + '<span class="pk-date">' + grpEsc(r.date)
                        + (r.unposted ? ' · غير محفوظة' : '') + '</span>'
                        + '<span class="pk-amt"><i class="ti ti-chevron-left"></i></span>'
                        + '</div>';
                }).join('') : '<div class="pk-empty">المجموعة دي مفيهاش فواتير.</div>';
            })
            .catch(e => {
                document.getElementById('grpCrumbTitle').textContent = '';
                body.innerHTML = '<div class="pk-empty" style="color:var(--neg);">'
                    + grpEsc(e.message) + '</div>';
            });
    }

    /** رجوع: للمجموعة اللي جاي منها، أو لقايمة الفواتير/المجموعات */
    function grpBackToInvoices() {
        grpHideAllBodies();
        if (grpWayCur === 'grp') {
            if (grpFromGroup) { grpOpenGroup(grpFromGroup); return; }
            document.getElementById('grpGrpList').style.display = '';
            return;
        }
        document.getElementById('grpInvList').style.display = '';
        const f = document.getElementById('grpInvFilter');
        if (f) f.focus();
    }

    /** فلتر قايمة الفواتير في وضع التصفّح */
    /**
     * بيعد الصفوف الظاهرة تحت كل عنوان ويحدّث الرقم، ويخفي العنوان
     * لو مفيش تحته حاجة. بتتنده من كل الفلاتر.
     * @return int إجمالي الظاهر
     */
    // أول ما الصفحة تحمّل: ظبّط الأرقام على المخفي/الظاهر الحقيقي
    document.addEventListener('DOMContentLoaded', function () {
        if (document.getElementById('grpPicker')) {
            grpSyncGroupCounts('#grpBody', 'grp-row');
            grpSyncGroupCounts('#grpInvList', 'gi-invrow');
        }
    });

    function grpSyncGroupCounts(listSel, rowClass) {
        const list = document.querySelector(listSel);
        if (!list) return 0;
        let total = 0;
        list.querySelectorAll('.picker-group').forEach(function (g) {
            let n = 0, el = g.nextElementSibling;
            while (el && !el.classList.contains('picker-group')) {
                if (el.classList.contains(rowClass) && el.style.display !== 'none') n++;
                el = el.nextElementSibling;
            }
            const num = g.querySelector('.pg-n');
            if (num) num.textContent = n;
            g.style.display = n ? '' : 'none';
            total += n;
        });
        return total;
    }

    function grpFilterInvList() {
        // 🔴 المجموعة نوع «فواتير كاملة» مابتطلّعش لوحة تحديد الأصناف
        //    (`$allowItems` = false في `grp_picker`)، يعني `#grpInvFilter`
        //    و`#grpInvEmpty` **مش موجودين في الصفحة أصلاً**. و`openGrpPicker`
        //    بينده الدالة دي على طول، فكانت بترمي
        //    «Cannot read properties of null» وتوقّف باقي فتح البوب أب.
        const box = document.getElementById('grpInvFilter');
        if (!box) return;
        const q = (box.value || '').trim().toLowerCase();
        let shown = 0;
        const showBr = grpBranchesShown();
        document.querySelectorAll('#grpInvList .gi-invrow').forEach(function (r) {
            const ok = (!q || (r.dataset.s || '').includes(q))
                    && (showBr || r.dataset.adm !== '0');
            r.style.display = ok ? '' : 'none';
            if (ok) shown++;
        });
        grpSyncGroupCounts('#grpInvList', 'gi-invrow');
        const emp = document.getElementById('grpInvEmpty');
        if (emp) emp.style.display = shown ? 'none' : '';
    }

    /** افتح أصناف فاتورة معيّنة */
    function grpOpenInvoice(sth, suffix) {
        const body  = document.getElementById('grpItemBody');
        const crumb = document.getElementById('grpCrumb');

        document.getElementById('grpInvList').style.display = 'none';
        body.style.display = '';
        crumb.style.display = '';
        document.getElementById('grpCrumbTitle').textContent = 'بيحمّل…';
        body.innerHTML = '<div class="pk-empty">بيجيب أصناف الفاتورة…</div>';

        fetch('invoices.php?gpick=items&g=' + GRP_ADD_ID
              + '&sth=' + encodeURIComponent(sth)
              + '&suffix=' + encodeURIComponent(suffix || ''))
            .then(r => r.json())
            .then(d => {
                if (!d.ok) throw new Error(d.error || 'حصل خطأ.');
                document.getElementById('grpCrumbTitle').textContent =
                    d.title + ' — ' + d.rows.length + ' صنف';
                body.innerHTML = d.rows.length
                    ? d.rows.map(grpItemRow).join('')
                    : '<div class="pk-empty">الفاتورة دي مالهاش بنود في المرآة.</div>';
            })
            .catch(e => {
                document.getElementById('grpCrumbTitle').textContent = '';
                body.innerHTML = '<div class="pk-empty" style="color:var(--neg);">'
                    + grpEsc(e.message) + '</div>';
            });
    }

    /** حدد كل الأصناف الظاهرة (جوه فاتورة) */
    function grpPickAllVisible() {
        const btns = document.querySelectorAll('#grpItemBody .gi-add:not(:disabled)');
        if (!btns.length) return;
        // لو كلهم متحددين، الزرار يشيلهم
        let allOn = true;
        btns.forEach(b => { if (!b.classList.contains('on')) allOn = false; });
        btns.forEach(function (b) {
            const on = b.classList.contains('on');
            if (on === !allOn) return;
            grpItemToggle(b);
        });
    }

    // ---------- بحث الأصناف ----------
    let grpItemTimer = null;

    /**
     * ⚠️ الاسم لازم يفضل مختلف عن id الخانة (grpItemSearch).
     *    الـinline handler جوه <form> بيدوّر على الاسم في: العنصر →
     *    **الفورم** → document → window. والفورم بيرجّع أي كنترول
     *    بنفس الـid، فـ grpItemSearch() كانت بتنده على <input> مش على
     *    الدالة → "not a function" وسكوت تام. (اتكشف 14/08)
     */
    function grpFindItem() {
        const term = (document.getElementById('grpItemSearch').value || '').trim();
        const body = document.getElementById('grpItemBody');
        clearTimeout(grpItemTimer);

        if (term.length < 2) {
            body.innerHTML = '<div class="pk-empty">اكتب كود أو اسم صنف، أو امسح باركود بالكاميرا.</div>';
            return;
        }
        body.innerHTML = '<div class="pk-empty">بيدوّر…</div>';

        // debounce 300ms — نفس قاعدة باقي البحث في النظام
        grpItemTimer = setTimeout(function () {
            fetch('invoices.php?gpick=search&g=' + GRP_ADD_ID + '&term=' + encodeURIComponent(term))
                .then(r => r.json())
                .then(d => {
                    if (!d.ok) throw new Error(d.error || 'حصل خطأ.');
                    if (!d.rows.length) {
                        body.innerHTML = '<div class="pk-empty">الصنف ده مانزلش في أي فاتورة شراء أو مردود.</div>';
                        return;
                    }
                    body.innerHTML = d.rows.map(grpItemRow).join('');
                })
                .catch(e => {
                    body.innerHTML = '<div class="pk-empty" style="color:var(--neg);">' + grpEsc(e.message) + '</div>';
                });
        }, 300);
    }

    function grpEsc(t) {
        return String(t == null ? '' : t).replace(/[&<>"']/g,
            c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
    }

    function grpItemRow(r) {
        const on  = !!grpPicked[r.key];
        const dis = !!r.in_group;
        const tag = r.is_return ? 'ret' : (r.unposted ? 'unp' : '');
        const lbl = r.type_label + (r.unposted ? ' (غير محفوظة)' : '');

        return '<div class="gi-row' + (on ? ' on' : '') + (dis ? ' dis' : '') + '">'
            + '<div class="gi-main">'
            +   '<span class="gi-nm">' + grpEsc(r.item_name || r.item_code) + '</span>'
            +   '<span class="gi-sub">'
            +     '<span class="gi-doc ' + tag + '">' + grpEsc(lbl) + '</span>'
            +     '<b>' + grpEsc(r.invoice_no) + '</b>'
            +     '<span>' + grpEsc(r.item_code) + '</span>'
            +     '<span>كمية ' + (Math.round(r.qty * 100) / 100) + '</span>'
            +     '<span>' + grpEsc((r.supplier || '—')) + '</span>'
            +     '<span>' + grpEsc((r.date || '').substring(0, 10)) + '</span>'
            +   '</span>'
            + '</div>'
            + '<button type="button" class="gi-add' + (on ? ' on' : '') + '"'
            +   (dis ? ' disabled' : '')
            +   ' data-p="' + grpEsc(JSON.stringify({
                    key: r.key, s: r.sth_id, f: r.suffix, c: r.item_code,
                    name: r.item_name || r.item_code, inv: r.invoice_no
                })) + '"'
            +   ' onclick="grpItemToggle(this)">'
            + (dis ? 'في المجموعة' : (on ? '✓ متحدد' : 'تحديد')) + '</button>'
            + '</div>';
    }

    function grpItemToggle(btn) {
        const p = JSON.parse(btn.dataset.p);
        if (grpPicked[p.key]) delete grpPicked[p.key];
        else                  grpPicked[p.key] = p;

        const on = !!grpPicked[p.key];
        btn.classList.toggle('on', on);
        btn.textContent = on ? '✓ متحدد' : 'تحديد';
        btn.closest('.gi-row').classList.toggle('on', on);
        grpItemRefresh();
    }

    function grpItemDrop(key) {
        delete grpPicked[key];
        // لو الصف لسه ظاهر في النتايج، رجّعه لحالته
        document.querySelectorAll('#grpItemBody .gi-add').forEach(function (b) {
            try {
                if (JSON.parse(b.dataset.p).key === key) {
                    b.classList.remove('on'); b.textContent = 'تحديد';
                    b.closest('.gi-row').classList.remove('on');
                }
            } catch (e) {}
        });
        grpItemRefresh();
    }

    /** شريط المختار + حالة زرار الحفظ */
    function grpItemRefresh() {
        const keys = Object.keys(grpPicked);
        const box  = document.getElementById('grpChosen');
        if (box) {
            box.style.display = keys.length ? '' : 'none';
            box.innerHTML = keys.map(function (k) {
                const p = grpPicked[k];
                return '<span class="gi-chip">'
                    + '<b>' + grpEsc(p.name) + '</b>'
                    + '<span style="color:var(--ink-500);">' + grpEsc(p.inv) + '</span>'
                    + '<button type="button" title="شيل"'
                    + ' onclick="grpItemDrop(' + JSON.stringify(k).replace(/"/g, '&quot;') + ')">&times;</button>'
                    + '</span>';
            }).join('');
        }
        const go = document.getElementById('grpGo');
        const hint = document.getElementById('grpHint');
        if (grpModeCur !== 'item') return;
        if (go) go.disabled = keys.length < 1;
        if (hint) {
            hint.textContent = keys.length ? ('تم تحديد ' + keys.length + ' صنف') : 'اختار صنف واحد على الأقل';
            hint.style.color = keys.length ? 'var(--pos)' : 'var(--ink-500)';
        }
    }

    function filterGrp() {
        const q = (document.getElementById('grpSearch').value || '').trim().toLowerCase();
        let any = false;
        const showBr = grpBranchesShown();
        document.querySelectorAll('#grpBody .grp-row').forEach(r => {
            const hit = !q || (r.dataset.s || '').indexOf(q) !== -1;
            // الصف المختار يفضل ظاهر عشان ما يضيعش من المستخدم
            const checked = r.querySelector('input') && r.querySelector('input').checked;
            const admOk   = showBr || r.dataset.adm !== '0';
            r.style.display = ((hit && admOk) || checked) ? '' : 'none';
            if (hit && admOk) any = true;
        });
        grpSyncGroupCounts('#grpBody', 'grp-row');
        const emp = document.getElementById('grpEmpty');
        if (emp) emp.style.display = any ? 'none' : '';
    }

    // حفظ توزيع من الشاشة المجمّعة — السيرفر بيقسّمه بالشلال على الفواتير
    function grpSave(input) {
        const qty = parseFloat(input.value) || 0;
        const saved = parseFloat(input.dataset.saved) || 0;
        if (Math.abs(qty - saved) < 0.0001) return;

        const key = input.dataset.code + ':' + input.dataset.branch;
        clearTimeout(saveTimers[key]);

        setInd(input, '⏳', 'var(--warn)');
        const body = new URLSearchParams({
            action: 'group_autosave', csrf: CSRF,
            group_id: GROUP_ID,
            item_code: input.dataset.code,
            branch_id: input.dataset.branch,
            qty: qty
        });
        fetch('invoices.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body.toString()
        })
        .then(r => r.json())
        .then(d => {
            if (!d.ok) {
                input.value = saved > 0 ? saved : '';
                setInd(input, '✗', 'var(--neg)');
                alert('خطأ في الحفظ: ' + (d.error || 'حاول تاني'));
                return;
            }
            input.dataset.saved = qty;
            if (window.iflRecalcDownloads) iflRecalcDownloads();
            setInd(input, '✓', 'var(--pos)');
            setTimeout(() => setInd(input, '', ''), 1800);

            // المخزن المتبقي
            const row = input.closest('tr');
            const storeEl = row.querySelector('.store-v');
            if (storeEl && d.remaining !== undefined) {
                storeEl.textContent = Number(d.remaining).toFixed(2);
                storeEl.style.color = d.remaining <= 0.0001 ? 'var(--pos)' : '';
            }
            // تلميح: اتقسمت على أنهي فواتير (يظهر لما الصنف في أكتر من فاتورة)
            if (d.split && d.split.length > 1) {
                const txt = d.split.map(s => s.invoice_no + ':' + (+s.qty)).join(' · ');
                input.title = 'اتقسمت — ' + txt;
            }
            // حدّث أزرار التحميل كلها لحظيًا (المجمّع + كل فاتورة)
            grpUpdateDownloads(d.group_totals, d.invoice_totals);
        })
        .catch(() => {
            input.value = saved > 0 ? saved : '';
            setInd(input, '✗', 'var(--neg)');
            alert('مفيش اتصال بالسيرفر.');
        });
    }

    // تحديث أزرار التحميل بعد أي حفظ — كل الأزرار موجودة في الصفحة
    // من الأول (مخفية)، فإحنا بنظهر/نخفي ونحدّث العداد بس.
    // كده الفاتورة اللي اتوزّعلها دلوقتي أزرارها تبان فورًا من غير ريفريش.
    function grpUpdateDownloads(groupTotals, invoiceTotals) {
        if (!groupTotals && !invoiceTotals) return;

        // (١) المجمّع
        if (groupTotals) {
            let anyCombo = false;
            document.querySelectorAll('.grp-combo').forEach(a => {
                const t = +(groupTotals[a.dataset.branch] || 0);
                a.style.display = t > 0 ? '' : 'none';
                const c = a.querySelector('.dl-count');
                if (c) c.textContent = '(' + Math.round(t) + ')';
                if (t > 0) anyCombo = true;
            });
            const none = document.getElementById('grpComboNone');
            if (none) none.style.display = anyCombo ? 'none' : '';
        }

        // (٢) كل فاتورة على حدة
        if (invoiceTotals) {
            const perInv = {};
            document.querySelectorAll('.grp-dl').forEach(a => {
                const sth = a.dataset.sth;
                const t = +(((invoiceTotals[sth]) || {})[a.dataset.branch] || 0);
                a.style.display = t > 0 ? '' : 'none';
                const c = a.querySelector('.dl-count');
                if (c) c.textContent = '(' + Math.round(t) + ')';
                if (t > 0) perInv[sth] = true;
            });
            document.querySelectorAll('.grp-none').forEach(s => {
                s.style.display = perInv[s.dataset.sth] ? 'none' : '';
            });
        }
    }

    function openInvPicker() {
        const p = document.getElementById('invPicker');
        if (!p) return;
        p.classList.add('open');
        const s = document.getElementById('pickerSearch');
        if (s) { s.value = ''; filterPicker(); setTimeout(() => s.focus(), 60); }
    }
    function closeInvPicker() {
        const p = document.getElementById('invPicker');
        if (p) p.classList.remove('open');
    }
    function filterPicker() {
        const q = (document.getElementById('pickerSearch').value || '').trim().toLowerCase();
        let shown = 0;
        document.querySelectorAll('#pickerBody .picker-row').forEach(r => {
            const hit = !q || (r.dataset.s || '').includes(q);
            r.style.display = hit ? '' : 'none';
            if (hit) shown++;
        });
        // نخفي عنوان أي مجموعة مالهاش نتائج
        document.querySelectorAll('#pickerBody .picker-group').forEach(g => {
            let n = g.nextElementSibling, any = false;
            while (n && n.classList.contains('picker-row')) {
                if (n.style.display !== 'none') { any = true; break; }
                n = n.nextElementSibling;
            }
            g.style.display = any ? '' : 'none';
        });
        const e = document.getElementById('pkEmpty');
        if (e) e.style.display = shown ? 'none' : 'block';
    }
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeInvPicker();
    });

    // ===== التنقّل بين خانات الكميات بالأسهم =====
    // الجدول RTL: السهم الشمال بيروح للعمود اللي على شماله بصريًا.
    function gridNav(ev) {
        const k = ev.key;
        if (!['ArrowUp','ArrowDown','ArrowLeft','ArrowRight','Enter'].includes(k)) return;

        const inp = ev.target;
        if (!inp.classList.contains('dist-input')) return;

        // كل الخانات الظاهرة، مرتبة صف بصف
        const rows = [...document.querySelectorAll('.item-row')];
        const grid = rows.map(r => [...r.querySelectorAll('.dist-input')]
            .filter(i => i.offsetParent !== null));

        let r = -1, c = -1;
        grid.forEach((cells, ri) => {
            const ci = cells.indexOf(inp);
            if (ci > -1) { r = ri; c = ci; }
        });
        if (r < 0) return;

        let tr = r, tc = c;
        if (k === 'ArrowUp')    tr = r - 1;
        if (k === 'ArrowDown' || k === 'Enter') tr = r + 1;
        if (k === 'ArrowRight') tc = c - 1;   // RTL: يمين = العمود اللي قبله
        if (k === 'ArrowLeft')  tc = c + 1;

        // لو الصف الهدف مالوش خانات ظاهرة، نكمّل لحد ما نلاقي
        const step = tr > r ? 1 : -1;
        while (tr >= 0 && tr < grid.length && grid[tr].length === 0) tr += step;

        const target = grid[tr] && (grid[tr][tc] || grid[tr][Math.min(c, grid[tr].length - 1)]);
        if (target) {
            ev.preventDefault();
            target.focus();
            target.select();
        }
    }
    document.addEventListener('keydown', gridNav);

    // ===== فك قفل صف مكتمل التوزيع للتعديل =====
    function toggleRowEdit(btn) {
        const row = btn.closest('tr');
        if (!row) return;
        const open = row.classList.toggle('editing');

        row.querySelectorAll('.c-branch .prev-val').forEach(v => v.style.display = open ? 'none' : '');
        row.querySelectorAll('.c-branch .qty-wrap').forEach(w => w.style.display = open ? 'inline-block' : 'none');

        const badge = row.querySelector('.done-badge');
        const storeV = row.querySelector('.store-v');
        if (badge)  badge.style.display  = open ? 'none' : '';
        if (storeV) storeV.style.display = open ? '' : 'none';

        btn.innerHTML = open ? '<i class="ti ti-check"></i>' : '<i class="ti ti-pencil"></i>';
        btn.classList.toggle('is-open', open);
        btn.title = open ? 'إنهاء التعديل' : 'تعديل الكميات';

        if (open) {
            const first = row.querySelector('.qty-wrap .dist-input');
            if (first) { first.focus(); first.select(); }
        } else {
            // لو اتقفل والمخزن لسه فيه باقي، نحدّث الشارة
            refreshRowState(row);
        }
    }

    // تحديث شكل الصف حسب المتبقي (بعد تعديل)
    function refreshRowState(row) {
        const storeV = row.querySelector('.store-v');
        if (!storeV) return;
        const rem = parseFloat(storeV.textContent) || 0;
        const badge = row.querySelector('.done-badge');
        if (badge) {
            const done = rem <= 0.0001;
            badge.style.display = done ? '' : 'none';
            storeV.style.display = done ? 'none' : '';
            storeV.classList.toggle('has', !done);
        }
    }

    // ===== نسبة التسعير =====
    // النسبة = (سعر البيع ÷ التكلفة − 1) × 100
    // الخانة بتفضل فاضية دايمًا — الرقم الباهت جواها هو النسبة المحسوبة.
    const MRK_STEP = 0.5;   // تقريب السعر الناتج لأقرب نص جنيه

    function mrkRound(v) { return Math.round(v / MRK_STEP) * MRK_STEP; }

    function mrkFmt(p) {
        const r = Math.round(p * 10) / 10;
        return String(r);
    }

    // تحديث الرقم الباهت في خانة النسبة حسب سعر البيع الحالي
    function refreshMrk(row) {
        if (!row) return;
        const m = row.querySelector('.mrk-input');
        const s = row.querySelector('.sale-input');
        if (!m || !s) return;
        const cost = parseFloat(m.dataset.cost || '0') || 0;
        const sale = parseFloat(s.value || '0') || 0;
        m.placeholder = (cost > 0 && sale > 0) ? mrkFmt((sale / cost - 1) * 100) + '%' : '—';
    }

    // Enter في خانة النسبة = تطبيق (الموبايل بيبعت مفاتيح مختلفة)
    function mrkKey(ev, inp) {
        const k = ev.key || '';
        const isEnter = k === 'Enter' || k === 'Go' || k === 'Done' || k === 'Next'
                     || ev.keyCode === 13 || ev.which === 13;
        if (!isEnter) return;
        ev.preventDefault();
        inp.blur();   // onblur بينفّذ applyMrk
    }

    // تطبيق نسبة على صنف واحد: بتحسب سعر البيع، تحفظه، وتفضّي الخانة
    function applyMrk(inp) {
        const raw = (inp.value || '').trim();
        inp.value = '';                       // الخانة فاضية دايمًا
        if (raw === '') return;

        const p = parseFloat(raw);
        if (isNaN(p) || p <= -100) return;

        const cost = parseFloat(inp.dataset.cost || '0') || 0;
        if (cost <= 0) return;

        const row  = inp.closest('tr');
        const sale = row && row.querySelector('.sale-input');
        if (!sale) return;

        const val = mrkRound(cost * (1 + p / 100));
        sale.value = String(val);
        sale.classList.add('dirty');
        saveSalePrice(sale);
        refreshMrk(row);
    }

    // ===== النسبة العامة: تسعير كل الأصناف مرة واحدة =====
    function mrkBulkKey(ev) {
        const k = ev.key || '';
        const isEnter = k === 'Enter' || k === 'Go' || k === 'Done' || k === 'Next'
                     || ev.keyCode === 13 || ev.which === 13;
        if (!isEnter) return;
        ev.preventDefault();
        applyBulkMrk();
    }

    function applyBulkMrk() {
        const box  = document.getElementById('mrkBulk');
        const note = document.getElementById('mrkBulkNote');
        const setNote = (txt, color) => {
            if (!note) return;
            note.textContent = txt; note.style.color = color; note.style.display = 'inline';
            clearTimeout(window.__mrkNoteT);
            window.__mrkNoteT = setTimeout(() => { note.style.display = 'none'; }, 6000);
        };
        if (!box) return;

        const raw = (box.value || '').trim();
        if (raw === '') { box.focus(); return; }
        const p = parseFloat(raw);
        if (isNaN(p) || p <= -100) { setNote('✗ اكتب نسبة صحيحة', 'var(--neg)'); return; }

        // الحساب في المتصفح، والحفظ في طلب واحد
        const prices = {};
        const rows = [];
        let noCost = 0;
        document.querySelectorAll('tr.item-row').forEach(row => {
            const m = row.querySelector('.mrk-input');
            const s = row.querySelector('.sale-input');
            if (!m || !s) return;
            const cost = parseFloat(m.dataset.cost || '0') || 0;
            if (cost <= 0) { noCost++; return; }
            const val = mrkRound(cost * (1 + p / 100));
            prices[s.dataset.code] = val;
            rows.push({ input: s, row: row, val: val });
        });

        const n = rows.length;
        if (!n) { setNote('✗ مفيش أصناف ليها تكلفة', 'var(--neg)'); return; }

        const extra = noCost > 0 ? '\n(' + noCost + ' صنف من غير تكلفة هيتساب زي ما هو)' : '';
        if (!confirm('هيتسعّر ' + n + ' صنف بنسبة ' + p + '%\n\n'
                   + '⚠️ أي سعر بيع موجود دلوقتي هيتكتب فوقه.' + extra + '\n\nتمام؟')) return;

        setNote('بيحفظ ' + n + ' صنف…', 'var(--ink-500)');
        box.disabled = true;

        const body = GRP_ID > 0
            ? { action: 'group_bulk_prices', csrf: CSRF, group_id: GRP_ID, prices: JSON.stringify(prices) }
            : { action: 'bulk_prices', csrf: CSRF, distribution_id: DIST_ID, prices: JSON.stringify(prices) };

        fetch('invoices.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams(body).toString()
        })
        .then(r => r.json())
        .then(j => {
            if (!j.ok) throw new Error(j.error || 'فشل الحفظ');
            rows.forEach(r => {
                r.input.value = String(r.val);
                r.input.dataset.saved = String(r.val);
                r.input.classList.remove('dirty');
                refreshMrk(r.row);
            });
            box.value = '';
            setNote('✓ اتسعّر ' + n + ' صنف بنسبة ' + p + '%', 'var(--pos)');
        })
        .catch(err => setNote('✗ ' + err.message, 'var(--neg)'))
        .finally(() => { box.disabled = false; });
    }

    // ===== سعر البيع: تعديل وحفظ (على نسختنا فقط) =====
    function onSaleInput(inp) {
        const ind = inp.parentElement.querySelector('.sale-ind');
        if (ind) { ind.textContent = ''; ind.className = 'sale-ind'; }
        inp.classList.add('dirty');
        refreshMrk(inp.closest('tr'));   // النسبة بتتحدث مع الكتابة
    }

    // تحديث كل أسعار الفاتورة فورًا من سيستم الشركة.
    // السيستم هو المرجع — بيكتب فوق أي تعديل يدوي في الخانات.
    function refreshPrices(btn) {
        const note = document.getElementById('priceRefreshNote');
        const setNote = (txt, color) => {
            if (!note) return;
            note.textContent = txt;
            note.style.color = color;
            note.style.display = 'inline';
        };
        btn.disabled = true;
        const icon = btn.querySelector('i');
        if (icon) icon.classList.add('ti-spin');
        setNote('بيجيب الأسعار من السيستم…', 'var(--ink-500)');

        // في المجموعة: كل أصناف كل الفواتير مرة واحدة
        const body = GRP_ID > 0
            ? { action: 'group_refresh_prices', csrf: CSRF, group_id: GRP_ID }
            : { action: 'refresh_prices', csrf: CSRF,
                distribution_id: DIST_ID, invoice_id: INV_ID, suffix: INV_SUFFIX };

        fetch('invoices.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams(body).toString()
        })
        .then(r => r.json())
        .then(j => {
            if (!j.ok) throw new Error(j.error || 'فشل التحديث');
            let changed = 0;
            document.querySelectorAll('.sale-input').forEach(inp => {
                const code = inp.dataset.code;
                if (!(code in j.prices)) return;
                const v = +parseFloat(j.prices[code]).toFixed(5);
                const prev = parseFloat(inp.dataset.saved || '0') || 0;
                if (Math.abs(v - prev) >= 0.000001) changed++;
                inp.value = v || v === 0 ? String(v) : '';
                inp.dataset.saved = String(v);
                inp.classList.remove('dirty');
                refreshMrk(inp.closest('tr'));
            });
            setNote(changed > 0
                ? '✓ اتحدثت — ' + changed + ' صنف اتغير سعره'
                : '✓ اتحدثت — مفيش أسعار اتغيرت', 'var(--pos)');
        })
        .catch(err => setNote('✗ ' + err.message, 'var(--neg)'))
        .finally(() => {
            btn.disabled = false;
            if (icon) icon.classList.remove('ti-spin');
            clearTimeout(window.__refNoteT);
            window.__refNoteT = setTimeout(() => { if (note) note.style.display = 'none'; }, 5000);
        });
    }

    function saveSalePrice(inp) {
        const code = inp.dataset.code;
        const raw  = (inp.value || '').trim();
        const val  = raw === '' ? 0 : parseFloat(raw);
        if (isNaN(val) || val < 0) { inp.value = inp.dataset.saved || ''; return; }

        const prev = parseFloat(inp.dataset.saved || '0') || 0;
        if (Math.abs(val - prev) < 0.000001) { inp.classList.remove('dirty'); return; }

        const ind = inp.parentElement.querySelector('.sale-ind');
        if (ind) { ind.textContent = '⏳'; ind.className = 'sale-ind wait'; }

        // في المجموعة السعر بيتكتب على كل الفواتير اللي فيها الصنف
        const body = GRP_ID > 0
            ? { action: 'group_save_price', csrf: CSRF,
                group_id: GRP_ID, item_code: code, price: val }
            : { action: 'save_price', csrf: CSRF,
                distribution_id: DIST_ID, item_code: code, price: val };

        fetch('invoices.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams(body).toString()
        })
        .then(r => r.json())
        .then(j => {
            if (j.ok) {
                inp.dataset.saved = val;
                inp.classList.remove('dirty');
                if (ind) { ind.textContent = '✓'; ind.className = 'sale-ind ok'; }
                setTimeout(() => { if (ind) ind.textContent = ''; }, 1400);
                const note = document.getElementById('priceEditNote');
                if (note) {
                    note.style.display = 'inline';
                    clearTimeout(window.__noteT);
                    window.__noteT = setTimeout(() => note.style.display = 'none', 4000);
                }
            } else {
                if (ind) { ind.textContent = '✕'; ind.className = 'sale-ind err'; }
                alert(j.error || 'تعذّر الحفظ');
            }
        })
        .catch(() => { if (ind) { ind.textContent = '✕'; ind.className = 'sale-ind err'; } });
    }

    // ===== بحث الصنف (بوب أب — زي المشروع القديم) =====
    function openLookup() {
        document.getElementById('lookupResult').innerHTML = '';
        document.getElementById('lookupCode').value = '';
        document.getElementById('lookupOverlay').classList.add('open');
        setTimeout(() => document.getElementById('lookupCode').focus(), 50);
    }

    function closeLookup() {
        document.getElementById('lookupOverlay').classList.remove('open');
    }

    // Enter على الموبايل بيبعت key مختلف (Go / Search / Unidentified)
    // وممكن مايوصلش أصلاً، فبنغطي كل الاحتمالات + keyCode 13
    function handleLookupKey(ev) {
        const k = ev.key || '';
        const isEnter = k === 'Enter' || k === 'Go' || k === 'Search'
                     || k === 'Done' || k === 'Next' || k === 'Unidentified'
                     || ev.keyCode === 13 || ev.which === 13;
        if (!isEnter) return;
        ev.preventDefault();
        ev.stopPropagation();
        // نمنع التكرار لو keydown و keyup اتنفذوا مع بعض
        const now = Date.now();
        if (now - (window.__lkLast || 0) < 350) return false;
        window.__lkLast = now;
        doLookup();
        return false;
    }

    function doLookup() {
        const code = document.getElementById('lookupCode').value.trim();
        if (!code) return;
        const result = document.getElementById('lookupResult');
        result.innerHTML = '<p class="lk-muted">جاري البحث...</p>';

        // البحث في الجدول الحالي
        let found = false;
        document.querySelectorAll('.item-row').forEach(tr => {
            const rowCode = (tr.dataset.code || '').trim();
            if (rowCode === code || rowCode.includes(code)) {
                found = true;
                const name = (tr.dataset.name || '').trim();
                const qty  = tr.querySelector('.c-qty') ? tr.querySelector('.c-qty').textContent.trim() : '';
                let html = `<div class="lk-alert lk-alert-info"><strong>${escRes(name)}</strong> — كود: ${escRes(rowCode)} — إجمالي: ${escRes(qty)}</div>
                            <table class="lk-table"><thead><tr><th>الفرع</th><th>الكمية</th></tr></thead><tbody>`;

                // الكميات: من الخانات (لو لسه بيتوزّع) أو من النص (لو خلص التوزيع)
                const inputs = tr.querySelectorAll('.dist-input');
                if (inputs.length) {
                    inputs.forEach(inp => {
                        const q = parseFloat(inp.value) || 0;
                        if (q > 0) html += `<tr><td>${BRANCH_NAMES[inp.dataset.branch] || inp.dataset.branch}</td><td style="font-weight:700;">${q}</td></tr>`;
                    });
                } else {
                    tr.querySelectorAll('.c-branch').forEach((td, i) => {
                        const q = parseFloat(td.textContent.trim()) || 0;
                        if (q > 0) html += `<tr><td>${BRANCH_LIST[i] || ''}</td><td style="font-weight:700;">${q}</td></tr>`;
                    });
                }

                html += '</tbody></table>';
                // زرار «تم التوزيع الفعلي» تحت جدول الفرع/الكمية
                if (typeof iflLookupBtn === 'function') html += iflLookupBtn(rowCode);
                result.innerHTML = html;
            }
        });
        if (!found) result.innerHTML = '<div class="lk-alert lk-alert-warning">لم يتم العثور على الصنف</div>';
    }

    // ===== الحفظ التلقائي للتوزيع =====
    const DIST_ID = <?= (int)($distId ?? 0) ?>;
    // المجموعة المفتوحة (تاب الفواتير المجمّعة) — صفر في باقي الشاشات.
    // الأسعار في الشاشة دي ليها مسار حفظ مختلف (كل فواتير المجموعة).
    const GRP_ID  = <?= (int)($grpCurrent['id'] ?? 0) ?>;
    const INV_ID     = '<?= e((string)($openInvoice['inv_id'] ?? '')) ?>';
    const INV_NO     = '<?= e((string)($openInvoice['inv_no'] ?? '')) ?>';
    const INV_SUFFIX = '<?= e((string)($openInvoice['suffix'] ?? $openSuffix ?? '')) ?>';
    const CSRF = '<?= e(csrf_token()) ?>';
    const saveTimers = {};
    let initialStatusSel = document.getElementById('invStatusSel') ? document.getElementById('invStatusSel').value : 'auto';

    // ===== سحب أعداد الفروع من الحجوزات =====
    const resPullOverlay = document.getElementById('resPullOverlay');
    const escRes = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    function openReservationPull() {
        if (!resPullOverlay) return;
        resPullOverlay.classList.add('open');
        const body = document.getElementById('resPullBody');
        const btnSubmit = document.getElementById('btnSubmitResPull');
        const summary = document.getElementById('resPullSummaryNote');
        if (btnSubmit) btnSubmit.disabled = true;
        if (summary) summary.textContent = '';
        if (body) {
            body.innerHTML = '<div style="text-align:center;padding:40px;color:var(--ink-500);">'
                + '<i class="ti ti-loader ti-spin" style="font-size:24px;display:block;margin-bottom:8px;"></i>'
                + 'جاري فحص الحجوزات المفتوحة…</div>';
        }

        const fd = new FormData();
        fd.append('action', 'reservation_pull_preview');
        fd.append('csrf', CSRF);
        fd.append('distribution_id', DIST_ID);
        fd.append('invoice_id', INV_ID);
        fd.append('suffix', INV_SUFFIX);

        fetch('invoices.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (!d.ok) {
                    body.innerHTML = '<div class="lk-alert lk-alert-warning" style="margin:10px;">'
                        + escRes(d.error || 'تعذّر جلب بيانات الحجوزات.') + '</div>';
                    return;
                }
                renderReservationPull(d.items || []);
            })
            .catch(() => {
                body.innerHTML = '<div class="lk-alert lk-alert-warning" style="margin:10px;">تعذّر الاتصال بالسيرفر.</div>';
            });
    }

    function closeReservationPull() {
        if (resPullOverlay) resPullOverlay.classList.remove('open');
    }

    function renderReservationPull(items) {
        const body = document.getElementById('resPullBody');
        const btnSubmit = document.getElementById('btnSubmitResPull');
        const summary = document.getElementById('resPullSummaryNote');
        if (!body) return;

        if (!items || items.length === 0) {
            body.innerHTML = '<div class="res-empty-msg">'
                + '<i class="ti ti-users" style="font-size:36px;display:block;margin-bottom:10px;color:var(--ink-400);"></i>'
                + 'مفيش حجوزات على أصناف الفاتورة دي'
                + '</div>';
            if (btnSubmit) btnSubmit.disabled = true;
            if (summary) summary.textContent = '';
            return;
        }

        let html = '';
        items.forEach((it) => {
            const lines = it.lines || [];
            html += `<div class="res-card" data-item-code="${escRes(it.item_code)}" data-received="${it.received_qty}">
                <div class="res-card-head">
                    <div class="res-card-title">${escRes(it.item_name)} <span style="font-size:12px;color:var(--ink-500);font-weight:normal;">(${escRes(it.item_code)})</span></div>
                    <div class="res-card-meta">
                        <span>المستلم: <b>${it.received_qty}</b></span>
                        <span>إجمالي الحجوزات: <b>${it.total_reserved}</b></span>
                        <span>المطلوب توزيعه: <b>${it.available_qty}</b></span>
                    </div>
                </div>
                <table class="res-table">
                    <thead>
                        <tr>
                            <th>التاريخ</th>
                            <th>العميل</th>
                            <th>الفرع</th>
                            <th>المحجوز</th>
                            <th>المتبقي</th>
                            <th>المخصص</th>
                        </tr>
                    </thead>
                    <tbody>`;

            lines.forEach(l => {
                const dt = l.created_at ? l.created_at.substring(0, 10) : '—';
                const branchName = l.branch_name || 'بدون فرع';
                html += `<tr>
                    <td>${escRes(dt)}</td>
                    <td>${escRes(l.customer_name)}</td>
                    <td>${escRes(branchName)}</td>
                    <td>${l.qty}</td>
                    <td>${l.remaining_qty}</td>
                    <td>
                        <input type="number" step="1" min="0" max="${l.remaining_qty}"
                               class="res-alloc-inp"
                               data-line-id="${l.res_line_id}"
                               data-code="${escRes(it.item_code)}"
                               data-branch-id="${l.branch_id || ''}"
                               data-branch-name="${escRes(branchName)}"
                               data-max="${l.remaining_qty}"
                               value="${l.suggested_qty}"
                               oninput="onResAllocInput()">
                    </td>
                </tr>`;
            });

            html += `</tbody>
                </table>
                <div class="res-branch-sum"></div>
                <div class="res-card-foot">
                    <span>إجمالي المستلم: <b>${it.received_qty}</b></span>
                    <span>إجمالي الموزّع: <b class="res-dist-total">0</b></span>
                    <span>متبقي: <b class="res-dist-remain">${it.received_qty}</b></span>
                </div>
            </div>`;
        });

        body.innerHTML = html;
        onResAllocInput();
    }

    function onResAllocInput() {
        const cards = document.querySelectorAll('.res-card');
        const btnSubmit = document.getElementById('btnSubmitResPull');
        let allValid = true;
        let totalItemsCount = cards.length;
        let totalAllocatedSum = 0;

        cards.forEach(card => {
            const received = parseFloat(card.dataset.received) || 0;
            const inputs = card.querySelectorAll('.res-alloc-inp');
            let itemAllocSum = 0;
            const branchSums = {};

            inputs.forEach(inp => {
                const val = parseFloat(inp.value) || 0;
                const max = parseFloat(inp.dataset.max) || 0;
                const bName = inp.dataset.branchName;
                const bId = inp.dataset.branchId;

                if (val < 0 || val > max + 0.0001) {
                    inp.classList.add('invalid');
                    allValid = false;
                } else {
                    inp.classList.remove('invalid');
                }

                itemAllocSum += val;
                if (val > 0 && bId && bName && bName !== 'بدون فرع') {
                    branchSums[bName] = (branchSums[bName] || 0) + val;
                }
            });

            totalAllocatedSum += itemAllocSum;

            // تحديث الإجمالي والمتبقي للصنف
            const distTotalEl = card.querySelector('.res-dist-total');
            const distRemainEl = card.querySelector('.res-dist-remain');
            const remain = received - itemAllocSum;

            if (distTotalEl) {
                distTotalEl.textContent = itemAllocSum;
                distTotalEl.style.color = (itemAllocSum > received + 0.0001) ? 'var(--neg)' : '';
            }
            if (distRemainEl) {
                distRemainEl.textContent = remain;
                distRemainEl.style.color = (remain < -0.0001) ? 'var(--neg)' : '';
            }

            if (itemAllocSum > received + 0.0001) {
                allValid = false;
            }

            // ملخص توزيع الفروع
            const branchSumEl = card.querySelector('.res-branch-sum');
            if (branchSumEl) {
                const parts = [];
                for (const [bn, bq] of Object.entries(branchSums)) {
                    parts.push(`${bn} ${bq}`);
                }
                branchSumEl.textContent = parts.length ? ('توزيع الفروع: ' + parts.join(' · ')) : 'توزيع الفروع: —';
            }
        });

        if (totalItemsCount === 0) allValid = false;
        if (btnSubmit) {
            btnSubmit.disabled = !allValid;
        }

        const summaryNote = document.getElementById('resPullSummaryNote');
        if (summaryNote) {
            summaryNote.textContent = allValid
                ? `جاهز للاعتماد (${totalItemsCount} صنف — إجمالي التخصيص: ${totalAllocatedSum})`
                : 'يوجد خطأ في توزيع الكميات (تأكد من عدم تجاوز المستلم أو المتبقي في الحجز)';
            summaryNote.style.color = allValid ? 'var(--ink-500)' : 'var(--neg)';
        }
    }

    function submitReservationPull() {
        const btnSubmit = document.getElementById('btnSubmitResPull');
        if (btnSubmit && btnSubmit.disabled) return;

        const alloc = {};
        document.querySelectorAll('.res-alloc-inp').forEach(inp => {
            const lid = inp.dataset.lineId;
            const val = parseFloat(inp.value) || 0;
            if (lid) alloc[lid] = val;
        });

        if (btnSubmit) {
            btnSubmit.disabled = true;
            btnSubmit.innerHTML = '<i class="ti ti-loader ti-spin"></i> جاري الاعتماد…';
        }

        const fd = new FormData();
        fd.append('action', 'reservation_pull_apply');
        fd.append('csrf', CSRF);
        fd.append('distribution_id', DIST_ID);
        fd.append('invoice_no', INV_NO);
        fd.append('invoice_id', INV_ID);
        fd.append('suffix', INV_SUFFIX);
        fd.append('alloc', JSON.stringify(alloc));

        fetch('invoices.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (!d.ok) {
                    alert('خطأ: ' + (d.error || 'تعذّر اعتماد التوزيع'));
                    if (btnSubmit) {
                        btnSubmit.disabled = false;
                        btnSubmit.innerHTML = '<i class="ti ti-check"></i> اعتماد التوزيع';
                    }
                    return;
                }
                location.reload();
            })
            .catch(() => {
                alert('تعذّر الاتصال بالسيرفر.');
                if (btnSubmit) {
                    btnSubmit.disabled = false;
                    btnSubmit.innerHTML = '<i class="ti ti-check"></i> اعتماد التوزيع';
                }
            });
    }

    // ===== ملاحظات الفاتورة (حفظ تلقائي بعد وقفة كتابة) =====
    let notesTimer = null;

    function queueNotesSave() {
        const st = document.getElementById('notesState');
        if (st) { st.textContent = 'جاري الحفظ...'; st.className = 'notes-state saving'; }
        clearTimeout(notesTimer);
        notesTimer = setTimeout(saveNotes, 900);
    }

    function saveNotes() {
        const box = document.getElementById('invNotes');
        const st  = document.getElementById('notesState');
        if (!box) return;

        const fd = new FormData();
        fd.append('csrf', CSRF);
        fd.append('notes', box.value);

        // نفس الخانة بتخدم الفاتورة والمجموعة — المسار بيختلف بس
        if (GRP_ID > 0) {
            fd.append('action', 'group_save_notes');
            fd.append('group_id', GRP_ID);
        } else {
            fd.append('action', 'save_notes');
            fd.append('distribution_id', DIST_ID);
        }

        fetch('invoices.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(j => {
                if (!st) return;
                if (j.ok) { st.textContent = 'محفوظة'; st.className = 'notes-state saved'; }
                else      { st.textContent = j.error || 'فشل الحفظ'; st.className = 'notes-state'; }
            })
            .catch(() => {
                if (st) { st.textContent = 'فشل الحفظ'; st.className = 'notes-state'; }
            });
    }

    // ===== تغيير حالة الفاتورة يدوي =====
    function resetStatusSel() {
        const sel = document.getElementById('invStatusSel');
        if (sel) sel.value = initialStatusSel;
    }

    function changeStatus(val) {
        // 🔴 «تم التوزيع» اليدوي بيفتح الاستلام على طول — حتى لو فيه أصناف
        //    توزيعها ناقص. ساعتها الأصناف دي مابتوصلش لأي فرع ومحدش ياخد
        //    باله. فبنسأل السيرفر الأول ونحذّر. (قرار محمد ٢١ سبتمبر ٢٠٢٦)
        if (val !== 'done') { doChangeStatus(val); return; }

        // 🔴 بنسأل السيرفر **دلوقتي** — مش بنعتمد على قايمة محسوبة وقت
        //    تحميل الصفحة. جدول التوزيع بيتحفظ لوحده من غير ريفرش، فلو
        //    اعتمدنا على القديمة كنا هنحذّر من صنف اتوزّع خلاص، أو نسكت
        //    عن صنف اتصفّر بعد التحميل. التحذير اللي بيكدب أسوأ من مفيش.
        var fd = new FormData();
        fd.append('action', 'set_status');
        fd.append('csrf', CSRF);
        fd.append('distribution_id', DIST_ID);
        fd.append('status', val);
        fd.append('invoice_id', INV_ID);
        fd.append('suffix', INV_SUFFIX);
        fd.append('dry', '1');

        var sel = document.getElementById('invStatusSel');
        if (sel) sel.disabled = true;

        fetch('invoices.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (sel) sel.disabled = false;
                var pend = (j && j.ok && j.pending) ? j.pending : [];
                if (!pend.length) { doChangeStatus(val); return; }

                var lines = ['خلي بالك: فيه ' + pend.length + ' صنف لسه ما اتوزّعش بالكامل:', ''];
                for (var i = 0; i < pend.length; i++) {
                    lines.push('• ' + pend[i].name
                             + ' — اتوزّع ' + pend[i].done
                             + ' من ' + pend[i].qty);
                }
                lines.push('');
                lines.push('لو كمّلت، الفاتورة هتروح للفروع والأصناف دي مش هتوصل لحد.');
                lines.push('الأحسن توزّعها الأول وهي هتروح لوحدها.');
                lines.push('');
                lines.push('تكمّل برضه؟');

                if (!confirm(lines.join('\n'))) { resetStatusSel(); return; }
                doChangeStatus(val);
            })
            .catch(function () {
                // السيرفر ماردّش — التحذير مش سبب نمنع المستخدم من شغله
                if (sel) sel.disabled = false;
                doChangeStatus(val);
            });
    }

    function doChangeStatus(val) {
        const sel  = document.getElementById('invStatusSel');
        const lock = document.getElementById('invStatusLock');
        if (sel) sel.disabled = true;

        const fd = new FormData();
        fd.append('action', 'set_status');
        fd.append('csrf', CSRF);
        fd.append('distribution_id', DIST_ID);
        fd.append('status', val);
        fd.append('invoice_id', INV_ID);
        fd.append('suffix', INV_SUFFIX);

        fetch('invoices.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(j => {
                if (sel) sel.disabled = false;
                if (!j.ok) { alert(j.error || 'تعذّر تغيير الحالة'); resetStatusSel(); return; }

                initialStatusSel = val;
                if (lock) lock.style.display = j.manual ? '' : 'none';

                // تحديث اسم الحالة في خيار "تلقائي"
                if (!j.manual && sel) {
                    const labels = { new: 'جديدة', split: 'تم التقسيم', done: 'تم التوزيع' };
                    sel.options[0].textContent = 'تلقائي (' + (labels[j.status] || j.status) + ')';
                }
            })
            .catch(() => {
                if (sel) sel.disabled = false;
                alert('تعذّر الاتصال بالسيرفر');
                resetStatusSel();
            });
    }

    // التحقق اللحظي: مجموع خانات الصف ميزيدش عن كمية الصنف
    function onDistInput(input) {
        const row = input.closest('tr');
        const itemQty = parseFloat(input.dataset.qty) || 0;

        const rawBal = input.dataset.hqbal;
        const hasBal = rawBal !== undefined && rawBal !== '';

        /* السقف = الأقل بين كمية الفاتورة ورصيد الإدارة. الفاتورة ممكن
           ١٢ والواصل فعلاً ١٠ (والباقي مردود)، فتوزيع ١٢ بيطلّع بضاعة
           مش موجودة والفرق يظهر بعدين كعجز على الفرع.

           🔴 والرصيد مش واصل = التوزيع متقفل. الخانة `readonly` أصلاً،
              وده حزام أمان تاني لو حد فك الـreadonly من أدوات المطوّر.
              الحماية الحقيقية في السيرفر جوّه set_branch_distribution(). */
        if (!hasBal) {
            /* 🔴 ممنوع تزوّد، مسموح تقلّل — نفس قاعدة السيرفر بالظبط. */
            const savedNA = parseFloat(input.dataset.saved) || 0;
            if ((parseFloat(input.value) || 0) > savedNA + 0.0001) {
                input.value = savedNA > 0 ? input.dataset.saved : '';
                setInd(input, '✗ الرصيد مش واصل — تقدر تقلّل بس', 'var(--neg)');
                return;
            }
            /* تقليل أو تصفير: عدّي للحفظ زي أي تعديل عادي */
            const kNA = input.dataset.code + ':' + input.dataset.branch;
            clearTimeout(saveTimers[kNA]);
            saveTimers[kNA] = setTimeout(
                () => (input.classList.contains('grp-input') ? grpSave(input) : autoSave(input)),
                800
            );
            return;
        }

        const hqBal = Math.max(0, parseFloat(rawBal) || 0);
        const cap   = Math.min(itemQty, hqBal);

        const inputs = row.querySelectorAll('.dist-input');

        let sum = 0;
        inputs.forEach(i => sum += (parseFloat(i.value) || 0));
        if (sum > cap + 0.0001) {
            // رجّع القيمة لآخر محفوظ
            input.value = parseFloat(input.dataset.saved) > 0 ? input.dataset.saved : '';
            // قول **ليه** الحد أقل — من غير كده الرسالة بتبان غلط في
            // النظام، وهي أصلاً بتمنع توزيع بضاعة مش موجودة
            const why = (hqBal !== null && cap < itemQty - 0.0001)
                ? ('✗ رصيد الإدارة ' + cap)
                : '✗ أكبر من المتاح';
            setInd(input, why, 'var(--neg)');
            return;
        }

        // debounce: احفظ بعد وقفة قصيرة من الكتابة
        const key = input.dataset.code + ':' + input.dataset.branch;
        clearTimeout(saveTimers[key]);
        saveTimers[key] = setTimeout(
            () => (input.classList.contains('grp-input') ? grpSave(input) : autoSave(input)),
            800
        );
    }

    function autoSave(input) {
        const qty = parseFloat(input.value) || 0;
        const saved = parseFloat(input.dataset.saved) || 0;
        if (Math.abs(qty - saved) < 0.0001) return; // مفيش تغيير

        const key = input.dataset.code + ':' + input.dataset.branch;
        clearTimeout(saveTimers[key]);

        setInd(input, '⏳', 'var(--warn)');
        const body = new URLSearchParams({
            action: 'autosave', csrf: CSRF,
            distribution_id: DIST_ID,
            item_code: input.dataset.code,
            item_qty: input.dataset.qty,
            branch_id: input.dataset.branch,
            qty: qty,
            invoice_id: INV_ID,
            suffix: INV_SUFFIX
        });
        fetch('invoices.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body.toString()
        })
        .then(r => r.json())
        .then(d => {
            if (!d.ok) {
                input.value = saved > 0 ? saved : '';
                setInd(input, '✗', 'var(--neg)');
                alert('خطأ في الحفظ: ' + (d.error || 'حاول تاني'));
                return;
            }
            input.dataset.saved = qty;
            if (window.iflRecalcDownloads) iflRecalcDownloads();
            setInd(input, '✓', 'var(--pos)');
            setTimeout(() => setInd(input, '', ''), 1800);
            // حدّث "المخزن المتبقي" في نفس الصف
            const row = input.closest('tr');
            const storeEl = row.querySelector('.store-v');
            if (storeEl && d.remaining !== undefined) {
                storeEl.textContent = Number(d.remaining).toFixed(2);
                storeEl.style.color = d.remaining <= 0.0001 ? 'var(--pos)' : '';
            }
            // في صف اتفك قفله: نحدّث الرقم المعروض جنب الخانة
            const cell = input.closest('.c-branch');
            if (cell) {
                const pv = cell.querySelector('.prev-val');
                if (pv) {
                    const v = parseFloat(input.value) || 0;
                    pv.textContent = v > 0 ? v.toFixed(1) : '—';
                }
            }
            // حدّث زرار تحميل الفرع (يظهر لما ليه كميات، يختفي لما تبقى صفر)
            if (d.branch_total !== undefined) {
                const dl = document.querySelector('.branch-dl[data-branch="' + input.dataset.branch + '"]');
                if (dl) {
                    const cnt = dl.querySelector('.dl-count');
                    if (cnt) cnt.textContent = '(' + Math.round(d.branch_total) + ')';
                    dl.style.display = d.branch_total > 0 ? '' : 'none';
                }
                // أظهر كارت التحميل لو فيه أي زرار ظاهر
                const card = document.getElementById('branchDlCard');
                if (card) {
                    const anyVisible = Array.from(document.querySelectorAll('.branch-dl'))
                        .some(a => a.style.display !== 'none');
                    card.style.display = anyVisible ? '' : 'none';
                }
            }
        })
        .catch(() => {
            input.value = saved > 0 ? saved : '';
            setInd(input, '✗ اتصال', 'var(--neg)');
        });
    }

    function setInd(input, txt, color) {
        const ind = input.parentElement.querySelector('.save-ind');
        if (ind) { ind.textContent = txt; ind.style.color = color; }
    }

    // حفظ أي تعديلات معلّقة قبل مغادرة الصفحة
    // ملاحظة: خانات الشاشة المجمّعة ليها مسار حفظ مختلف (grpSave)،
    // لأن autoSave بيعتمد على DIST_ID/INV_ID بتوع الفاتورة الواحدة
    // واللي بيبقوا فاضيين في تاب المجموعة.
    window.addEventListener('beforeunload', () => {
        document.querySelectorAll('.dist-input').forEach(i => {
            const q = parseFloat(i.value) || 0;
            const s = parseFloat(i.dataset.saved) || 0;
            if (Math.abs(q - s) <= 0.0001) return;
            if (i.classList.contains('grp-input')) grpSave(i);
            else autoSave(i);
        });
    });
</script>

<?php
// بوب أب إعدادات الصنف — بيتبني مرة واحدة، ولما يبقى فيه جدول أصناف بس.
$iflHasTable = (!empty($openInvoice) && !empty($items))
            || (!empty($grpCurrent) && !empty($grpData['items']));
?>
<?php if ($iflHasTable): ?>
<div class="ifl-overlay" id="iflOverlay" onclick="if(event.target===this)iflClose()">
    <div class="ifl-box">
        <div class="ifl-head">
            <div style="min-width:0;">
                <b id="iflName">—</b>
                <small id="iflCode"></small>
            </div>
            <button type="button" class="ifl-x" onclick="iflClose()" aria-label="إغلاق">&times;</button>
        </div>

        <!-- (١) لون التمييز -->
        <div class="ifl-sec">
            <label>لون التمييز</label>
            <div class="ifl-sw" id="iflSwatches">
                <button type="button" data-c=""       title="بدون لون"></button>
                <button type="button" data-c="red"    title="أحمر"></button>
                <button type="button" data-c="green"  title="أخضر"></button>
                <button type="button" data-c="yellow" title="أصفر"></button>
                <button type="button" data-c="orange" title="برتقالي"></button>
            </div>
            <div class="sub">اللون بيتحفظ مع الصنف في الفاتورة — بيفضل موجود بعد التحديث.</div>
        </div>

        <!-- (٢) ملف الفرع -->
        <div class="ifl-sec">
            <label>ملف الفرع (TXT)</label>
            <div class="ifl-radio">
                <label><input type="radio" name="iflSkip" value="0" onchange="iflSetSkip(0)"> ينزل في التقرير</label>
                <label><input type="radio" name="iflSkip" value="1" onchange="iflSetSkip(1)"> لا ينزل في التقرير</label>
            </div>
            <div class="sub">
                <i class="ti ti-alert-triangle"></i> الإعداد ده على <b>كود الصنف في كل النظام</b> — يعني مش هينزل في ملف
                أي فرع في أي فاتورة أو مجموعة.<br>
                التوزيع والكميات والأسعار وملفات الإكسيل <b>ماتتأثرش خالص</b> — ملف الفرع بس.
            </div>
        </div>

        <!-- (٣) الملاحظة -->
        <!-- ⚠️ زرار «تم التوزيع الفعلي» مكانه بوب أب **بحث صنف** (اللي بيعرض
             جدول الفرع/الكمية) — مش هنا. طلب محمد 2026-08-14. -->
        <div class="ifl-sec">
            <label>ملاحظة</label>
            <textarea class="ifl-note-in" id="iflNote" rows="3"
                      placeholder="اكتب ملاحظتك على الصنف ده…"
                      oninput="iflNoteBoxTyping()"></textarea>
            <div class="sub">نفس ملاحظة عمود «ملاحظات» في آخر الجدول — بتتحفظ لوحدها.</div>
        </div>

        <div class="ifl-foot">
            <span class="ifl-state" id="iflState"></span>
            <button type="button" class="btn btn-sm btn-primary" onclick="iflClose()">تم</button>
        </div>
    </div>
</div>

<script>
/* =====================================================================
   علامات الأصناف: لون · ملاحظة · تم التوزيع الفعلي · ملف الفرع
   ---------------------------------------------------------------
   الحالة كلها متخزّنة على الـ<tr> نفسه في data-* ، والسيرفر هو اللي
   بيحدد الفواتير اللي الحفظ هيتكتب عليها (فاتورة واحدة أو كل فواتير
   المجموعة). الواجهة مابتبعتش أرقام سجلات توزيع.
   ===================================================================== */
(function () {
    'use strict';

    let curCode = null;          // الصنف المفتوح في البوب أب
    let noteTimer = null;        // مؤقت حفظ الملاحظة من البوب أب
    const cellTimers = {};       // مؤقتات خانات الملاحظة في الجدول
    const skipSeq = {};          // آخر ضغطة لكل كود — الرد القديم بيتتجاهل

    const ov = document.getElementById('iflOverlay');

    function rowOf(code) {
        const rows = document.querySelectorAll('tr.item-row');
        for (let i = 0; i < rows.length; i++) {
            if ((rows[i].dataset.code || '') === code) return rows[i];
        }
        return null;
    }

    function state(txt, color) {
        const el = document.getElementById('iflState');
        if (el) { el.textContent = txt || ''; el.style.color = color || 'var(--ink-500)'; }
    }

    /**
     * الحفظ — بيبعت الحقول المتغيّرة بس.
     * `busy` بيمنع الضغط المتكرر السريع في تمييز الألوان من إنه يسبق نفسه.
     * الراديو والملاحظات الاتنين مش متحرسين (`guard === false`) لأن
     * المفتاح واحد وآخر كتابة هي الصح على item_export_flags وسجلات التوزيع.
     */
    const busy = {};
    function post(code, fields, guard) {
        // الملاحظات مابتتحرسش: آخر كتابة هي الصح (نفس المفتاح)، والحراسة
        // كانت هتلغي اللي المستخدم كتبه لو الحفظة اللي قبلها لسه ماردّتش.
        if (guard !== false) {
            if (busy[code]) return Promise.reject(new Error('استنى الحفظة اللي قبلها…'));
            busy[code] = true;
        }

        const fd = new FormData();
        fd.append('action', 'item_flags_save');
        fd.append('csrf', CSRF);
        fd.append('item_code', code);
        fd.append('distribution_id', DIST_ID);
        fd.append('group_id', GRP_ID);
        Object.keys(fields).forEach(k => fd.append(k, fields[k]));

        return fetch('invoices.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(j => {
                busy[code] = false;
                if (!j.ok) throw new Error(j.error || 'فشل الحفظ');
                return j;
            })
            .catch(err => { busy[code] = false; throw err; });
    }

    // ---------- تلوين الصف ----------
    function paint(row) {
        const hl   = row.dataset.hl || '';
        const done = row.dataset.done === '1';

        row.classList.remove('hl-red', 'hl-green', 'hl-yellow', 'hl-orange', 'hl-done');
        if (hl) row.classList.add('hl-' + hl);
        else if (done) row.classList.add('hl-done');

        const tick = row.querySelector('.ifl-donetick');
        if (tick) tick.style.display = done ? '' : 'none';

        const noexp = row.querySelector('.ifl-noexp');
        if (noexp) noexp.style.display = row.dataset.skip === '1' ? '' : 'none';

        const note = row.dataset.note || '';
        const ic = row.querySelector('.ifl-noteic');
        if (ic) {
            ic.classList.toggle('has', note !== '');
            ic.title = note !== '' ? note : 'مفيش ملاحظة';
        }
    }

    // ---------- فتح/قفل البوب أب ----------
    window.iflOpen = function (el) {
        const row = el.closest('tr.item-row');
        if (!row) return;
        curCode = row.dataset.code || '';

        document.getElementById('iflName').textContent = row.dataset.name || curCode;
        document.getElementById('iflCode').textContent = curCode;

        // اللون
        document.querySelectorAll('#iflSwatches button').forEach(b => {
            b.classList.toggle('on', (b.dataset.c || '') === (row.dataset.hl || ''));
        });
        // ملف الفرع
        const want = row.dataset.skip === '1' ? '1' : '0';
        document.querySelectorAll('input[name="iflSkip"]').forEach(r => { r.checked = (r.value === want); });
        // الملاحظة
        document.getElementById('iflNote').value = row.dataset.note || '';

        state('');
        ov.classList.add('open');
    };

    window.iflClose = function () {
        // لو الملاحظة لسه مستنية حفظ، احفظها فورًا قبل القفل
        if (noteTimer) { clearTimeout(noteTimer); noteTimer = null; saveNoteBox(); }
        ov.classList.remove('open');
        curCode = null;
    };

    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && ov.classList.contains('open')) iflClose();
    });

    // ---------- اللون ----------
    document.getElementById('iflSwatches').addEventListener('click', function (ev) {
        const b = ev.target.closest('button[data-c]');
        if (!b || !curCode) return;
        const c = b.dataset.c || '';
        const row = rowOf(curCode);
        if (!row) return;

        const prev = row.dataset.hl || '';
        row.dataset.hl = c;
        paint(row);
        document.querySelectorAll('#iflSwatches button').forEach(x =>
            x.classList.toggle('on', (x.dataset.c || '') === c));
        state('جاري الحفظ…');

        post(curCode, { highlight: c })
            .then(() => state('اتحفظ ✓', 'var(--pos)'))
            .catch(e => {
                row.dataset.hl = prev; paint(row);
                document.querySelectorAll('#iflSwatches button').forEach(x =>
                    x.classList.toggle('on', (x.dataset.c || '') === prev));
                state(e.message, 'var(--neg)');
            });
    });

    // ---------- ملف الفرع ----------
    window.iflSetSkip = function (v) {
        if (!curCode) return;
        const code = curCode;

        const rows = [];
        document.querySelectorAll('tr.item-row').forEach(r => {
            if ((r.dataset.code || '') === code) rows.push(r);
        });
        if (!rows.length) return;

        const prev = rows[0].dataset.skip || '0';
        rows.forEach(r => {
            r.dataset.skip = String(v);
            paint(r);
        });
        state('جاري الحفظ…');

        const my = (skipSeq[code] = (skipSeq[code] || 0) + 1);

        post(code, { skip_export: v }, false)
            .then(() => {
                if (skipSeq[code] !== my) return;
                state(v === 1
                    ? 'اتحفظ ✓ — الصنف مش هينزل في ملف أي فرع'
                    : 'اتحفظ ✓ — الصنف هينزل عادي', 'var(--pos)');
                iflRecalcDownloads();   // الأرقام على الأزرار تتظبط فورًا
            })
            .catch(e => {
                if (skipSeq[code] !== my) return;
                rows.forEach(r => {
                    r.dataset.skip = prev;
                    paint(r);
                });
                // الراديو بتاع البوب أب يترجّع **بس** لو لسه فاتح على نفس
                // الصنف — المستخدم ممكن يكون قفله وفتح صنف تاني في الوقت ده.
                if (curCode === code) {
                    document.querySelectorAll('input[name="iflSkip"]').forEach(r => {
                        r.checked = (r.value === prev);
                    });
                    state(e.message, 'var(--neg)');
                }
            });
    };

    // ---------- تم التوزيع الفعلي — من بوب أب «بحث صنف» ----------
    // الزرار بيتبني جوه نتيجة البحث (تحت جدول الفرع/الكمية) عشان تكون
    // شايف توزيعة الصنف قدامك وانت بتعلّم إنه اتسلّم فعلاً.

    /** HTML الزرار — بيتنده من doLookup */
    window.iflLookupBtn = function (code) {
        const row = rowOf(code);
        if (!row) return '';
        const on = row.dataset.done === '1';
        return '<button type="button" class="ifl-donebtn' + (on ? ' on' : '') + '"'
             + ' data-code="' + code.replace(/"/g, '&quot;') + '"'
             + ' onclick="iflLookupDone(this)">'
             + '<i class="ti ti-circle-check"></i> <span>'
             + (on ? 'تم التوزيع الفعلي ✓ (اضغط للتراجع)' : 'تم التوزيع الفعلي')
             + '</span></button>'
             + '<div class="ifl-donehint">علامة بصرية بس (الصف بيبقى أخضر). '
             + '<b>مالهاش أي أثر</b> على الكميات ولا ملفات الفروع ولا حالة الفاتورة.</div>';
    };

    function paintLookupBtn(btn, on) {
        btn.classList.toggle('on', on);
        const sp = btn.querySelector('span');
        if (sp) sp.textContent = on ? 'تم التوزيع الفعلي ✓ (اضغط للتراجع)' : 'تم التوزيع الفعلي';
    }

    window.iflLookupDone = function (btn) {
        const code = btn.dataset.code || '';
        const row  = rowOf(code);
        if (!row) return;

        const prev = row.dataset.done === '1';
        const next = !prev;
        row.dataset.done = next ? '1' : '0';
        paint(row); paintLookupBtn(btn, next);
        btn.disabled = true;

        post(code, { done_actual: next ? 1 : 0 })
            .then(() => { btn.disabled = false; })
            .catch(e => {
                btn.disabled = false;
                row.dataset.done = prev ? '1' : '0';
                paint(row); paintLookupBtn(btn, prev);
                alert(e.message);
            });
    };

    // ---------- الملاحظة من البوب أب ----------
    function saveNoteBox() {
        if (!curCode) return;
        const code = curCode;
        const val  = document.getElementById('iflNote').value;
        const row  = rowOf(code);
        if (!row || (row.dataset.note || '') === val.trim()) return;

        row.dataset.note = val.trim();
        paint(row);
        const cell = row.querySelector('.inote-input');
        if (cell) cell.value = val.trim();
        state('جاري الحفظ…');

        post(code, { user_notes: val }, false)
            .then(() => state('اتحفظت ✓', 'var(--pos)'))
            .then(() => {
                if (!window.isPageUnloading && val.trim() !== '') {
                    showProblemPrompt(code, val.trim(), row ? (row.dataset.name || code) : code);
                }
            })
            .catch(e => state(e.message, 'var(--neg)'));
    }

    window.iflNoteBoxTyping = function () {
        state('جاري الحفظ…');
        clearTimeout(noteTimer);
        noteTimer = setTimeout(function () { noteTimer = null; saveNoteBox(); }, 800);
    };

    // ---------- الملاحظة من خانة الجدول ----------
    function cellInd(input, txt, color) {
        const ind = input.parentElement.querySelector('.inote-ind');
        if (ind) { ind.textContent = txt; ind.style.color = color || 'var(--ink-500)'; }
    }

    window.iflNoteTyping = function (input) {
        const code = input.dataset.code;
        cellInd(input, '…');
        clearTimeout(cellTimers[code]);
        cellTimers[code] = setTimeout(function () { iflNoteSave(input); }, 800);
    };

    window.iflNoteSave = function (input) {
        const code = input.dataset.code;
        clearTimeout(cellTimers[code]);
        const row = input.closest('tr.item-row');
        const val = input.value.trim();
        if (!row || (row.dataset.note || '') === val) { cellInd(input, ''); return; }

        const prev = row.dataset.note || '';
        row.dataset.note = val;
        paint(row);
        cellInd(input, '…');

        post(code, { user_notes: val }, false)
            .then(() => {
                cellInd(input, '✓', 'var(--pos)');
                setTimeout(() => cellInd(input, ''), 1400);
                if (curCode === code) document.getElementById('iflNote').value = val;
                if (!window.isPageUnloading && val !== '') {
                    showProblemPrompt(code, val, row.dataset.name || code);
                }
            })
            .catch(e => {
                row.dataset.note = prev; paint(row);
                input.value = prev;
                cellInd(input, '✗', 'var(--neg)');
            });
    };

    /**
     * ---------- تحديث أرقام أزرار التحميل لحظيًا ----------
     * الرقم على الزرار لازم يساوي اللي هينزل في الملف بالظبط.
     * PHP بيحسبه أول ما الصفحة تفتح، لكن لما تعلّم صنف «لا ينزل في
     * التقرير» من البوب أب الرقم كان بيفضل قديم لحد ما تعمل تحديث.
     * الدالة دي بتعيد الحساب من الجدول نفسه من غير أي نداء للسيرفر.
     *
     * ⚠️ الأصناف اليتيمة (اتشالت من فاتورة الشركة) مش في الجدول أصلاً،
     *    فهي مستبعدة تلقائيًا من الحساب ده — زي التصدير بالظبط.
     */
    window.iflRecalcDownloads = function () {
        const sums = {};
        document.querySelectorAll('input.dist-input').forEach(function (inp) {
            const row = inp.closest('tr.item-row');
            if (!row || row.dataset.skip === '1') return;   // مش هينزل في الملف
            const b = inp.dataset.branch;
            const q = parseFloat(inp.dataset.saved);
            if (!b || !q || q <= 0) return;
            sums[b] = (sums[b] || 0) + q;
        });

        let any = false;
        document.querySelectorAll('a.branch-dl, a.grp-combo').forEach(function (a) {
            const t = sums[a.dataset.branch] || 0;
            const c = a.querySelector('.dl-count');
            if (c) c.textContent = '(' + Math.round(t).toLocaleString('en-US') + ')';
            a.style.display = t > 0 ? '' : 'none';
            if (t > 0) any = true;
        });

        const none = document.getElementById('grpComboNone');
        if (none) none.style.display = any ? 'none' : '';

        // لافتة «X صنف معلّم لا ينزل في التقرير»
        const skipped = [];
        document.querySelectorAll('tr.item-row').forEach(function (r) {
            if (r.dataset.skip === '1') skipped.push(r.dataset.code || '');
        });
        document.querySelectorAll('.ifl-skipnote').forEach(function (box) {
            box.style.display = skipped.length ? '' : 'none';
            const n = box.querySelector('.ifl-skipn');
            if (n) n.textContent = skipped.length;
            const l = box.querySelector('.ifl-skiplist');
            if (l) l.textContent = skipped.slice(0, 12).join(' · ') + (skipped.length > 12 ? ' …' : '');
        });
    };

    // ---------- مسح التمييز / علامات التوزيع الفعلي ----------
    window.iflClear = function (what) {
        const isDone = what === 'done';
        const msg = isDone
            ? 'مسح علامة «تم التوزيع الفعلي» من كل الأصناف؟\n\nالألوان اليدوية والملاحظات هتفضل زي ما هي.'
            : 'رجّع كل الصفوف للون الطبيعي؟\n\nعلامات «تم التوزيع الفعلي» والملاحظات هتفضل زي ما هي.';
        if (!confirm(msg)) return;

        const fd = new FormData();
        fd.append('action', 'item_flags_clear');
        fd.append('csrf', CSRF);
        fd.append('what', isDone ? 'done' : 'highlight');
        fd.append('distribution_id', DIST_ID);
        fd.append('group_id', GRP_ID);

        fetch('invoices.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(j => {
                if (!j.ok) { alert(j.error || 'فشل المسح'); return; }
                document.querySelectorAll('tr.item-row').forEach(row => {
                    if (isDone) row.dataset.done = '0';
                    else        row.dataset.hl = '';
                    paint(row);
                });
                if (ov.classList.contains('open')) iflClose();
            })
            .catch(() => alert('تعذّر الاتصال بالسيرفر'));
    };

    // ---------- إشعار إضافة الصنف لمشاكل الفواتير ----------
    var problemToastTimer = null;
    var pendingProblemItem = null;

    function showProblemPrompt(code, note, name) {
        if (window.isPageUnloading) return;
        if (!note || !note.trim()) return;

        pendingProblemItem = { code: code, note: note.trim(), name: name || code };

        var toast = document.getElementById('problemToast');
        var label = document.getElementById('problemToastItem');
        if (!toast) return;

        if (label) label.textContent = name ? ('«' + name + '»') : ('[' + code + ']');
        toast.style.display = 'block';

        if (problemToastTimer) clearTimeout(problemToastTimer);
        // الإشعار يختفي لوحده بعد 15 ثانية لو محدش داس
        problemToastTimer = setTimeout(function () {
            hideProblemPrompt();
        }, 15000);
    }

    function hideProblemPrompt() {
        var toast = document.getElementById('problemToast');
        if (toast) toast.style.display = 'none';
        if (problemToastTimer) {
            clearTimeout(problemToastTimer);
            problemToastTimer = null;
        }
        pendingProblemItem = null;
    }

    var btnNo = document.getElementById('problemToastNo');
    if (btnNo) {
        btnNo.addEventListener('click', function () {
            hideProblemPrompt();
        });
    }

    var btnYes = document.getElementById('problemToastYes');
    if (btnYes) {
        btnYes.addEventListener('click', function () {
            if (!pendingProblemItem) return;
            var item = pendingProblemItem;
            hideProblemPrompt();

            var fd = new FormData();
            fd.append('action', 'add_to_problems');
            fd.append('csrf', CSRF);
            fd.append('distribution_id', DIST_ID);
            fd.append('group_id', GRP_ID);
            fd.append('item_code', item.code);
            fd.append('item_name', item.name);
            fd.append('note', item.note);

            fetch('invoices.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(j => {
                    if (!j.ok) {
                        console.error(j.error || 'فشل إضافة الصنف لمشاكل الفواتير');
                    }
                })
                .catch(e => console.error('تعذر الاتصال بالسيرفر'));
        });
    }

    // احفظ أي ملاحظة معلّقة قبل مغادرة الصفحة
    window.addEventListener('beforeunload', function () {
        window.isPageUnloading = true;
        document.querySelectorAll('.inote-input').forEach(function (i) {
            const row = i.closest('tr.item-row');
            if (row && (row.dataset.note || '') !== i.value.trim()) iflNoteSave(i);
        });
    });

    // تأكيد فتح الاستلام عند تنزيل ملف التوزيع لأول مرة
    document.addEventListener('click', function (e) {
        var link = e.target.closest('.branch-dl, .grp-combo, .grp-dl');
        if (!link) return;

        var card = link.closest('[data-receiving-open]');
        if (!card) return;

        var isOpen = card.getAttribute('data-receiving-open');
        if (isOpen === '1') return; // تم فتح الاستلام مسبقاً — تنزيل عادي بدون سؤال

        e.preventDefault();
        var targetUrl = link.href;
        var isGrp = link.classList.contains('grp-combo');
        var headMsg = isGrp
            ? 'هل تريد إرسال المجموعة كلها لموديول استلام الفروع؟'
            : 'هل تريد إرسال الفاتورة دي لوحدها لموديول استلام الفروع؟';
        if (isGrp) {
            var solo = parseInt(card.getAttribute('data-solo-count') || '0', 10);
            if (solo > 0) {
                headMsg += '\n⚠️ ' + solo + ' فاتورة اتبعتت لوحدها ومش هتنزل في الملف ده.';
            }
        }
        var msg = headMsg + '\n\n'
                + '• الضغط على "موافق": إرسال لموديول الاستلام وتنبيه الفروع وبدء المتابعة.\n'
                + '• الضغط على "إلغاء": تنزيل الملف فقط كتقرير دون إرساله للاستلام وبدون إشعارات.';

        if (confirm(msg)) {
            card.setAttribute('data-receiving-open', '1');
            window.location.href = targetUrl;
        } else {
            var sep = targetUrl.indexOf('?') !== -1 ? '&' : '?';
            window.location.href = targetUrl + sep + 'skip_receiving=1';
        }
    });
})();
</script>
<?php endif; ?>

<script src="assets/barcode_scanner.js?v=6"></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
