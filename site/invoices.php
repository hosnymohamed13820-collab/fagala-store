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
require_once __DIR__ . '/invoices_lib.php';

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
    try {
        $term = trim((string) ($_GET['term'] ?? ''));
        $gid  = (int) ($_GET['g'] ?? 0);
        $rows = inv_pick_search($term, $gid);
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
        $gid = (int) ($_GET['g'] ?? 0);
        $res = inv_pick_items($sth, $suf, $gid);
        echo json_encode(['ok' => true] + $res, JSON_UNESCAPED_UNICODE);
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
        $res = inv_pick_group_invoices($g2);
        echo json_encode(['ok' => true] + $res, JSON_UNESCAPED_UNICODE);
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
// grp_branch_stores() و grp_is_admin_inv() اتنقلوا لـ receiving_lib.php (بريف ٧٩)

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
    return '<label class="grp-admtog"' . ($branchCount < 1 ? ' style="display:none;"' : '') . '>'
        . '<input type="checkbox" onchange="grpShowBranches(this.checked)">'
        . '<span>اعرض فواتير الفروع كمان <b>(' . $branchCount . ')</b></span>'
        . '<span class="grp-admtip">افتراضيًا بتشوف فواتير الإدارة بس.</span>'
        . '</label>';
}

/** صفوف الفواتير داخل بوب أب المجموعات (#grpBody) — بتتبني للـ AJAX أو للرسم المباشر */
function grp_picker_rows(array $unposted, array $posted): string
{
    $rows = function (array $list, string $label) {
        if (!$list) return '';
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

    return $rows($unposted, 'غير محفوظة')
         . $rows($posted, 'مرحّلة')
         . '<div class="pk-empty" id="grpEmpty" style="display:none;">مفيش نتائج</div>';
}

/** صفوف الفواتير داخل وضع التصفح بالفاتورة (#grpInvList) */
function grp_picker_inv_rows(array $unposted, array $posted): string
{
    $h = '';
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
                . ' onclick="grpOpenInvoice(' . (int) $iv['inv_id']
                . ', \'' . e((string) ($iv['suffix'] ?? '')) . '\')">'
                . '<span class="pk-no">' . e((string) $iv['inv_no']) . '</span>'
                . '<span class="pk-sup">' . e((string) ($iv['supplier_name'] ?? '—')) . '</span>'
                . '<span class="pk-date">' . e(date('Y-m-d', strtotime((string) $iv['invoice_date']))) . '</span>'
                . '<span class="pk-amt"><i class="ti ti-chevron-left"></i></span>'
                . '</div>';
        }
    }
    $h .= '<div class="pk-empty" id="grpInvEmpty" style="display:none;">مفيش نتائج</div>';
    return $h;
}

function grp_picker(array $unposted = [], array $posted = [], int $groupId = 0, string $groupType = 'full'): string
{
    $isAdd     = $groupId > 0;
    $allowItems = !$isAdd || $groupType === 'items';   // مجموعة full: فواتير بس
    $h = '';

    // كام فاتورة فرع هتتخفي؟ (لو متمررة بيانات مسبقاً، وإلا 0 ويتحدث بالـ AJAX)
    $branchCount = 0;
    if ($unposted || $posted) {
        foreach (array_merge($unposted, $posted) as $iv) {
            if (!grp_is_admin_inv($iv)) $branchCount++;
        }
    }

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
        . '<div class="picker-body" id="grpBody">';
    if ($unposted || $posted) {
        $h .= grp_picker_rows($unposted, $posted);
    } else {
        $h .= '<div class="pk-loading" style="padding:28px;text-align:center;color:var(--ink-500);"><i class="ti ti-loader ti-spin"></i> جاري تحميل الفواتير…</div>';
    }
    $h .= '</div></div>';

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
        if ($unposted || $posted) {
            $h .= grp_picker_inv_rows($unposted, $posted);
        } else {
            $h .= '<div class="pk-loading" style="padding:28px;text-align:center;color:var(--ink-500);"><i class="ti ti-loader ti-spin"></i> جاري تحميل الفواتير…</div>';
        }
        $h .= '</div>';

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

// =====================================================================
//  AJAX — صفوف بوب أب المجموعات (بريف ٥٧)
//  بيجيب الفواتير ويرجّع JSON {html, branch_count, inv_list_html}
// =====================================================================
if (($_GET['ajax'] ?? '') === 'grp_rows') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $data = inv_group_picker_data();
        $html = grp_picker_rows($data['unposted'], $data['posted']);
        $invListHtml = grp_picker_inv_rows($data['unposted'], $data['posted']);

        echo json_encode([
            'ok'            => true,
            'html'          => $html,
            'inv_list_html' => $invListHtml,
            'branch_count'  => $data['branch_count'],
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
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

function dist_is_return(PDO $pdo, int $distId): bool
{
    $stDt = $pdo->prepare('SELECT doc_type FROM invoice_distribution WHERE id = ?');
    $stDt->execute([$distId]);
    return (int) $stDt->fetchColumn() === 6;
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
        if (dist_is_return($pdo, $distId)) {
            throw new RuntimeException('المردود مابيتوزعش ومالوش حالة — نزّل ملف المردود.');
        }

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
        $distTotalsMap = [];
        if (!empty($g['dist_ids'])) {
            $dids = array_values(array_unique(array_map('intval', $g['dist_ids'])));
            $ph   = implode(',', array_fill(0, count($dids), '?'));
            $q    = $pdo->prepare(
                "SELECT distribution_id, branch_id, SUM(amount) AS t
                 FROM invoice_distribution_lines
                 WHERE distribution_id IN ($ph)
                 GROUP BY distribution_id, branch_id
                 HAVING SUM(amount) > 0"
            );
            $q->execute($dids);
            while ($r = $q->fetch()) {
                $distTotalsMap[(int) $r['distribution_id']][(int) $r['branch_id']] = (float) $r['t'];
            }
        }
        foreach ($g['dist_ids'] as $sth => $did) {
            $rows = $distTotalsMap[(int) $did] ?? [];
            foreach ($rows as $bid => $t) {
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
        if (dist_is_return($pdo, $distId)) {
            throw new RuntimeException('المردود مابيتوزعش ومالوش حالة — نزّل ملف المردود.');
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
        if (dist_is_return($pdo, $distId)) {
            throw new RuntimeException('المردود مابيتوزعش ومالوش حالة — نزّل ملف المردود.');
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
$invScope = $_GET['scope'] ?? 'admin';
if (!in_array($invScope, ['admin', 'branches', 'all'], true)) {
    $invScope = 'admin';
}
$adminCount = $branchCount = 0;
$openInvoice = null;
$items = [];
$distId = 0;
$isReturnDoc = false;
$isBranchInv = false;
$noDistDoc   = false;
$branchName  = '';

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
            $isReturnDoc = $openInvoice && (int)($openInvoice['doc_type'] ?? 0) === 6;
            // فاتورة فرع (شراء أو مردود) = نفس تعريف قايمة «فواتير الفروع الأخرى» — مابتتوزعش (بريف ٧٩)
            $isBranchInv = $openInvoice && !grp_is_admin_inv(['company_branch_id' => $openInvoice['company_branch'] ?? 0]);
            // من غير توزيع: مردود الإدارة (بريف ٧١) + فواتير الفروع (بريف ٧٩)
            $noDistDoc = $isReturnDoc || $isBranchInv;

            if ($isBranchInv) {
                $cbId = (int) ($openInvoice['company_branch'] ?? 0);
                $bq = $pdo->prepare('SELECT name FROM branches WHERE sto_id = ? LIMIT 1');
                $bq->execute([$cbId]);
                $branchName = (string) ($bq->fetchColumn() ?: ('فرع #' . $cbId));
            }
            // الصنف المكرر في أكتر من سطر بيتجمع في صف واحد — التوزيع
            // متخزّن بمفتاح الكود مش السطر. (شوف merge_invoice_items)
            $items = merge_invoice_items(sqlsrv_fetch_invoice_items($openInvoiceId, $openSuffix));
            // بريف ٨٠: إجمالي الكميات — من نفس $items المدموج اللي بيترسم في الجدول
            $totalQty = array_sum(array_map(fn($it) => (float) ($it['quantity'] ?? 0), $items));
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

        if ($grpCurrent) {
            $grpData = group_load((int) $grpCurrent['id']);
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
            // شاشة الكروت (بوب أب مجموعة جديدة بيتحمل بالـ AJAX وقت الفتح — بريف ٥٧)
            $grpCards = group_list();
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

        // بريف ٨٠: عدد الأصناف لكل فاتورة — استعلام واحد مجمّع لكل الفواتير.
        // «الصنف» = صف بعد merge_invoice_items(): نفس الدمج بـtrim(item_code)،
        // ولو الكود فاضي '#'.item_id — عشان القايمة والشاشة يطلّعوا نفس الرقم.
        // لو الجدول مش موجود (القايمة جاية من الجسر) ← العمود يطلع «—» والصفحة ماتقعش.
        $itemCountMap = [];
        try {
            $nExpr = "COUNT(DISTINCT CASE WHEN TRIM(COALESCE(item_code,'')) <> '' THEN TRIM(item_code)
                                          ELSE CONCAT('#', COALESCE(item_id, 0)) END)";
            if ($tab === 'unposted') {
                // hq_unposted_lines مفيهاش suffix — المفتاح sth_id|YY_MM زي sqlsrv_fetch_unposted_invoices()
                foreach (db()->query("SELECT sth_id, {$nExpr} AS n_items FROM hq_unposted_lines GROUP BY sth_id") as $r) {
                    $itemCountMap[$r['sth_id'] . '|YY_MM'] = (int) $r['n_items'];
                }
            } else {
                foreach (db()->query("SELECT sth_id, suffix, {$nExpr} AS n_items FROM hq_invoice_lines GROUP BY sth_id, suffix") as $r) {
                    $itemCountMap[$r['sth_id'] . '|' . $r['suffix']] = (int) $r['n_items'];
                }
            }
        } catch (Throwable $ex) {
            $itemCountMap = [];
        }

        // تجميع بالفرع: الإدارة فوق، بعدها الفروع
        $invoiceGroups = group_invoices_by_branch($invoices);

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

        if ($invScope !== 'all') {
            $filteredGroups = [];
            foreach ($invoiceGroups as $g) {
                $isAdm = !empty($g['is_admin']) || (int)($g['branch_id'] ?? 0) === 1;
                if ($invScope === 'admin' && $isAdm) {
                    $filteredGroups[] = $g;
                } elseif ($invScope === 'branches' && !$isAdm) {
                    $filteredGroups[] = $g;
                }
            }
            $invoiceGroups = $filteredGroups;
        }

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
    <?php if (can('invoices:undistributed')): ?>
    <a href="undistributed.php" class="btn btn-sm" title="اللي اتشرى في فترة ولسه ماتوزّعش على الفروع">
        <i class="ti ti-package-export"></i> الأصناف غير الموزّعة
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
    z-index: var(--z-toast);
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
    .inv-table .inv-nitems { text-align:center; }   /* بريف ٨٠ — عدد الأصناف في القايمة */
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
                        (r.buyer ? '<span class="ilk-chip" title="الفرع اللي عامل الفاتورة"><i class="ti ti-building-store"></i> ' + esc(r.buyer) + '</span>' : '') +
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

<link rel="stylesheet" href="assets/invoices.css?v=2">

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
            <div class="m"><span class="l">عدد الأصناف:</span> <span class="v"><?= count($items) ?></span></div>
            <div class="m"><span class="l">إجمالي الكميات:</span> <span class="v"><?= e(qty_fmt((float) ($totalQty ?? 0))) ?></span></div>
            <?php if ($isBranchInv): ?>
                <?php if ($isReturnDoc): ?>
                    <div class="m"><span class="doc-badge refund">مردود <?= e($branchName) ?></span></div>
                <?php else: ?>
                    <div class="m"><span class="doc-badge">فاتورة <?= e($branchName) ?></span></div>
                <?php endif; ?>
            <?php elseif ($isReturnDoc): ?>
                <div class="m"><span class="doc-badge refund">مردود شراء</span></div>
            <?php else: ?>
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
            <?php endif; ?>
        </div>

        <?php /* لافتة قراءة بس، محسوبة وقت تحميل الصفحة. التحذير اللي
                 بيظهر وقت قفل الحالة يدوي بيسأل السيرفر من جديد
                 (فرع `dry`) لأن جدول التوزيع بيتحفظ من غير ريفرش. */ ?>
        <?php if (!$noDistDoc && !$isManual && !empty($pendingItems)): ?>
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
            <?php if (!$noDistDoc): ?>
            <button class="btn btn-sm btn-res-pull" type="button" onclick="openReservationPull()">
                <i class="ti ti-users"></i> سحب أعداد الفروع
            </button>
            <?php endif; ?>
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

        <?php if (!$noDistDoc): ?>
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
        <?php endif; ?>

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
                $isGuardOff = dist_guard_off([$distId]);
                $naCount = 0;
                if (!$isGuardOff) {
                    foreach ($items as $it) {
                        $iid = (int) ($it['item_id'] ?? 0);
                        if ($iid <= 0 || !isset($itemBalances[$iid])) {
                            $naCount++;
                        }
                    }
                }
            ?>
            <?php if (!$noDistDoc): ?>
                <?php if ($isGuardOff): ?>
                    <div class="status-bar warn-soft" style="margin-bottom:14px;display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:10px;font-size:13.5px;font-weight:600;background:var(--warn-soft);color:var(--warn);border:1px solid var(--warn);">
                        <i class="ti ti-lock"></i> حارس رصيد الإدارة متشال عن الفاتورة دي — التوزيع حده كمية الفاتورة بس.
                    </div>
                <?php elseif ($naCount > 0): ?>
                    <div class="sqlsrv-warn" style="margin-bottom:14px;display:flex;align-items:center;gap:10px;">
                        <i class="ti ti-alert-triangle" style="font-size:20px;flex-shrink:0;"></i>
                        <div>
                            فيه <?= $naCount ?> صنف رصيد الإدارة بتاعهم مش واصل — تقدر تقلّل توزيعهم بس مش تزوّده.
                            شغّل وكيل مزامنة الإدارة.
                        </div>
                    </div>
                <?php endif; ?>
                <?= mrk_bulk_bar() ?>
            <?php endif; ?>
            <?= ifl_bar() ?>
            <?php if ($noDistDoc): ?>
            <div class="lines-wrap">
            <table class="lines-table" id="linesTable" data-xt-min="1">
                <thead>
                    <tr>
                        <th>كود الصنف</th>
                        <th>اسم الصنف</th>
                        <th>الكمية</th>
                        <th>سعر التكلفة</th>
                        <th class="th-inote">ملاحظات</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $it): ?>
                    <?php
                        $code = (string) ($it['item_code'] ?? '');
                        $qty  = (float) ($it['quantity'] ?? 0);
                        $iFlags = $itemFlags[$code] ?? [];
                        $iSkip  = isset($skipMap[$code]);
                    ?>
                    <tr class="<?= ifl_row_class($iFlags) ?>" data-code="<?= e($code) ?>" data-name="<?= e((string)($it['item_name'] ?? '')) ?>"<?= ifl_row_data($iFlags, $iSkip) ?>>
                        <?= ifl_code_cell($code, $iSkip) ?>
                        <?= ifl_name_cell((string)($it['item_name'] ?? ''), $iFlags) ?>
                        <td class="c-qty"><?= number_format($qty, 2) ?></td>
                        <td class="c-cost"><?= number_format((float)($it['cost_price'] ?? 0), 3) ?></td>
                        <?= ifl_notes_cell($code, $iFlags) ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php else: ?>
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
                        // 🔴 لو الحارس متشال: السقف = كمية الصنف، ومفيش تنبيهات
                        if ($isGuardOff) {
                            $hqB   = $qty;
                            $hqLow = false;
                            $hqNA  = false;
                        } else {
                            $iid   = (int) ($it['item_id'] ?? 0);
                            $hqB   = ($iid > 0 && isset($itemBalances[$iid])) ? (float) $itemBalances[$iid] : null;
                            $hqLow = ($hqB !== null && $hqB < $qty - 0.0001);
                            $hqNA  = ($hqB === null);   // رصيد الإدارة مش واصل = التوزيع متقفل
                        }
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

    <?php if (!$noDistDoc): ?>
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
    <?php endif; ?>

    <?php if ($noDistDoc): ?>
    <!-- كارت ملف المردود / الفاتورة -->
    <div class="card" id="returnDlCard" style="margin-bottom:16px;">
        <div class="section-title"><?= $isReturnDoc ? 'ملف المردود' : 'ملف الفاتورة' ?></div>
        <p style="color:var(--ink-500);font-size:13px;margin-bottom:14px;">
            <?= $isReturnDoc
                ? 'ملف TEXT واحد فيه كل أصناف المردود بكميتها كاملة (كود الصنف، الكمية، سعر التكلفة). المردود مابيتوزعش على الفروع.'
                : 'ملف TEXT واحد فيه كل أصناف الفاتورة بكميتها كاملة (كود الصنف، الكمية، سعر التكلفة). فاتورة الفرع مابتتوزعش.' ?>
        </p>
        <?= ifl_skip_note($skipMap) ?>
        <div style="display:flex;flex-wrap:wrap;gap:10px;">
            <?php
                $exportUrl = 'return_export.php?invoice=' . urlencode((string)$openInvoice['inv_id'])
                    . '&suffix=' . urlencode((string)($openInvoice['suffix'] ?? $openSuffix ?? ''));
                if ($isBranchInv) {
                    $exportUrl .= '&cb=' . (int)($openInvoice['company_branch'] ?? 0)
                               . '&dt=' . (int)($openInvoice['doc_type'] ?? 0);
                }
                $btnLabel = $isBranchInv ? 'ملف الفاتورة (TXT)' : 'تنزيل ملف المردود';
            ?>
            <a class="btn" style="background:var(--pos-fill);"
               href="<?= e($exportUrl) ?>">
                <i class="ti ti-download"></i> <?= e($btnLabel) ?>
            </a>
        </div>
    </div>
    <?php else: ?>
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
    <?php endif; ?>

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
            <?php $grpOpenCount = group_open_count(); if ($grpOpenCount > 0): ?>
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
        <?= grp_picker() ?>

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
                    <form method="post" data-confirm="مسح المجموعة؟&#10;&#10;التوزيع اللي اتعمل هيفضل محفوظ في الفواتير — اللي هيتمسح هو تجميعة الشغل بس." data-confirm-danger>
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
        <?= grp_picker([], [], (int) $grpCurrent['id'], $gIsItems ? 'items' : 'full') ?>

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
                $grpDistIds = $grpData['dist_ids'] ?? [];
                $guardOffDistMap = [];
                if (!empty($grpDistIds)) {
                    try {
                        $pdo = db();
                        $ph = implode(',', array_fill(0, count($grpDistIds), '?'));
                        $st = $pdo->prepare("SELECT id FROM invoice_distribution WHERE id IN ($ph) AND hq_guard_off = 1");
                        $st->execute($grpDistIds);
                        $guardOffDistMap = array_fill_keys($st->fetchAll(PDO::FETCH_COLUMN), true);
                    } catch (Throwable) {}
                }
                $grpHasGuardOff = !empty($guardOffDistMap);

                $naCount = 0;
                foreach ($grpItems as $it) {
                    $itGuardOff = false;
                    if ($grpHasGuardOff && !empty($it['sources'])) {
                        foreach ($it['sources'] as $src) {
                            if (!empty($guardOffDistMap[(int)($src['dist_id'] ?? 0)])) {
                                $itGuardOff = true;
                                break;
                            }
                        }
                    }
                    if (!$itGuardOff) {
                        $iid = (int) ($it['item_id'] ?? 0);
                        if ($iid <= 0 || !isset($itemBalances[$iid])) {
                            $naCount++;
                        }
                    }
                }
            ?>
            <?php if ($grpHasGuardOff): ?>
                <div class="status-bar warn-soft" style="margin-bottom:14px;display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:10px;font-size:13.5px;font-weight:600;background:var(--warn-soft);color:var(--warn);border:1px solid var(--warn);">
                    <i class="ti ti-lock"></i> حارس رصيد الإدارة متشال عن الفاتورة دي — التوزيع حده كمية الفاتورة بس.
                </div>
            <?php elseif ($naCount > 0): ?>
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

                        $itGuardOff = false;
                        if ($grpHasGuardOff && !empty($it['sources'])) {
                            foreach ($it['sources'] as $src) {
                                if (!empty($guardOffDistMap[(int)($src['dist_id'] ?? 0)])) {
                                    $itGuardOff = true;
                                    break;
                                }
                            }
                        }
                        if ($itGuardOff) {
                            $hqB   = $qty;
                            $hqLow = false;
                            $hqNA  = false;
                        } else {
                            $iid   = (int) ($it['item_id'] ?? 0);
                            $hqB   = ($iid > 0 && isset($itemBalances[$iid])) ? (float) $itemBalances[$iid] : null;
                            $hqLow = ($hqB !== null && $hqB < $qty - 0.0001);
                            $hqNA  = ($hqB === null);
                        }
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
                            if ($isLocked) echo '<span class="disc-star" title="خصم خاص بالصنف — بيتجاهل خصم الفاتورة" style="color:var(--accent-text);font-size:11px;"> <i class="ti ti-star"></i></span>';
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
                        <b>فيه أصناف بخصم خاص <i class="ti ti-star"></i>.</b> الأصناف اللي كتبت لها نسبة بتتحسب
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

        <script>
        /* 🔴 الدوال دي **لازم تفضل فوق الجدول**.
               الجدول تحتيها `foreach` على كل الفواتير — في تاب «المرحّلة»
               بيطلّع آلاف الصفوف. المتصفح بيرسمها ويسيب المستخدم يدوس
               قبل ما يوصل للسكربت اللي في آخر الصفحة.
               لما كانوا تحت، اللي يدوس على رأس فرع عشان يطويه كان بياخد
               «toggleSubtype is not defined» والضغطة تضيع (اتسجّلت على
               السيرفر ٢٠ سبتمبر ٢٠٢٦). متنقلهمش تحت تاني. */
        const INV_TAB = <?= json_encode($tab) ?>;

        function setScopeFilter(scope, btn) {
            const inp = document.getElementById('invSearch');
            const q = inp ? inp.value.trim() : '';
            location.href = 'invoices.php?tab=' + encodeURIComponent(INV_TAB) + '&scope=' + encodeURIComponent(scope)
                          + (q ? '&q=' + encodeURIComponent(q) : '');
        }

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
            <button type="button" class="scope-btn<?= $invScope === 'admin' ? ' active' : '' ?>" data-scope="admin" onclick="setScopeFilter('admin', this)">
                <i class="ti ti-building-bank"></i> فواتير الإدارة (المركز الرئيسي) <span><?= $adminCount ?></span>
            </button>
            <button type="button" class="scope-btn<?= $invScope === 'branches' ? ' active' : '' ?>" data-scope="branches" onclick="setScopeFilter('branches', this)">
                <i class="ti ti-building-store"></i> فواتير الفروع الأخرى <span><?= $branchCount ?></span>
            </button>
            <button type="button" class="scope-btn<?= $invScope === 'all' ? ' active' : '' ?>" data-scope="all" onclick="setScopeFilter('all', this)">
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
                <col style="width:7%;">
                <col style="width:11%;">
                <col>
                <col style="width:13%;">
                <col style="width:13%;">
                <?php if ($tab==='posted'): ?><col style="width:13%;"><?php endif; ?>
            </colgroup>
            <thead><tr>
                <th>#</th><th>رقم الفاتورة</th><th>التاريخ</th><th>عدد الأصناف</th>
                <th>كود المورد</th><th>اسم المورد</th><th>الإجمالي</th><th>الصافي</th>
                <?php if ($tab==='posted'): ?><th>الحالة</th><?php endif; ?>
            </tr></thead>
            <tbody title="اضغط لفتح أصناف الفاتورة">
            <?php $rowNo = 0; foreach ($invoiceGroups as $g): ?>
                <?php
                    $colspan = $tab==='posted' ? 9 : 8;   // بريف ٨٠: +عمود «عدد الأصناف»
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
                    $isRet = (int)($inv['doc_type'] ?? 0) === 6;
                    $noStatus = $isRet || !$isAdm;
                    if ($noStatus) {
                        $st = 'ret';
                        $stLabel = '—';
                        $stClass = '';
                    } else {
                        $st = $invStatuses[$fullKey] ?? ($invStatuses[$fallbackKey] ?? (inv_status_match($invStatuses, $invNo, $invCb, $invDt) ?? 'new'));
                        [$stLabel, $stClass] = invoice_status_label($st);
                    }
                    $nTxt = trim((string)($invNotes[$fullKey] ?? ($invNotes[$fallbackKey] ?? (inv_status_match($invNotes, $invNo, $invCb, $invDt) ?? ''))));
                ?>
                    <tr class="inv-row<?= ($st==='done' && !$noStatus)?' inv-done':'' ?>" data-st="<?= e($st) ?>" data-scope="<?= $gScope ?>" data-s="<?= e(mb_strtolower($invNo.' '.($inv['supplier_name'] ?? '').' '.($inv['supplier_code'] ?? '').' '.($isRet ? 'مردود شراء refund' : '').' '.$nTxt)) ?>" onclick="openInvoice('<?= e($inv['inv_id']) ?>','<?= e($inv['suffix'] ?? '') ?>','<?= e($invCb !== '*' ? $invCb : '') ?>','<?= e($invDt !== '*' ? $invDt : '') ?>')"><td><?= $rowNo ?></td><td class="inv-no"><?= e($invNo) ?><?php if ($isRet): ?> <span class="doc-badge refund">مردود شراء</span><?php endif; ?><?php if ($nTxt !== ''): ?> <span class="inv-note-text" title="<?= e($nTxt) ?>"><i class="ti ti-note"></i> <?= e(mb_strimwidth($nTxt, 0, 120, '…')) ?></span><?php endif; ?></td><td class="inv-date"><?= e(date('Y-m-d', strtotime((string)$inv['invoice_date']))) ?></td><td class="inv-nitems"><?= isset($itemCountMap[$inv['inv_id'] . '|' . ($inv['suffix'] ?? '')]) ? (int) $itemCountMap[$inv['inv_id'] . '|' . ($inv['suffix'] ?? '')] : '—' ?></td><td class="inv-scode"><?= e($inv['supplier_code'] ?? '—') ?></td><td class="inv-supplier" title="<?= e($inv['supplier_name'] ?? '') ?>"><?= e($inv['supplier_name'] ?? '—') ?></td><td><?= number_format((float)$inv['total_amount'],2) ?></td><td><?= number_format((float)$inv['net_amount'],2) ?></td><?php if ($tab==='posted'): ?><td><?php if ($noStatus): ?><span style="color:var(--ink-400);">—</span><?php else: ?><span class="st-badge <?= $stClass ?>"><?= e($stLabel) ?></span><?php endif; ?></td><?php endif; ?></tr>
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
window.INV = <?= json_encode([
    'tab'         => (string) $tab,
    'invScope'    => (string) $invScope,
    'branchNames' => array_column($activeBranches, 'name', 'id'),
    'branchList'  => array_values(array_column($activeBranches, 'name')),
    'groupId'     => (int) ($grpCurrent['id'] ?? 0),
    'distId'      => (int) ($distId ?? 0),
    'invId'       => (string) ($openInvoice['inv_id'] ?? ''),
    'invNo'       => (string) ($openInvoice['inv_no'] ?? ''),
    'invSuffix'   => (string) ($openInvoice['suffix'] ?? $openSuffix ?? ''),
    'csrf'        => (string) csrf_token(),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
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

<?php endif; ?>

<?php /* بعد بوب أب الأصناف: الـJS بيدوّر على #iflOverlay وقت ما يتحمّل (بريف ٥٧ كان حاطه قبله فعلامات الأصناف كلها وقفت) */ ?>
<script src="assets/invoices.js?v=5"></script>
<script src="assets/barcode_scanner.js?v=9"></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
