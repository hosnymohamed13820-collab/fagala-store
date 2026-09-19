<?php
/**
 * dashboard.php — الداشبورد (مرجع المدير المالي)
 * نظام الفجالة ستور المالي
 *
 * - أرصدة كل الخزائن (الرئيسية + الفرعية) — كشف مختصر بدون تفاصيل.
 * - عهد الفروع كـ Progress Bars: النسبة = الباقي ÷ أول المدة.
 *   أبيض (مفيش عهدة) → أخضر (فوق 50%) → برتقالي (أقل) → أحمر (20% أو أقل).
 * - ملخص اليوم: تقفيلات فيها فرق، في انتظار مراجعة، إجمالي المبيعات، دفعات موردين قادمة.
 * - حالة التقفيلات: في ورديات محتاجة مراجعة ولا لأ.
 * - سلايدر فترة: مبيعات اليوم (افتراضي) أو من تاريخ لتاريخ.
 */
require_once __DIR__ . '/auth.php';
require_permission('dashboard');
require_once __DIR__ . '/finance.php';

$pdo = db();

// ===== الفترة (افتراضي: اليوم) =====
$from = $_GET['from'] ?? date('Y-m-d');
$to   = $_GET['to']   ?? date('Y-m-d');
if (!strtotime($from)) $from = date('Y-m-d');
if (!strtotime($to))   $to   = date('Y-m-d');
$isToday = ($from === date('Y-m-d') && $to === date('Y-m-d'));

// ===== أرصدة الخزائن =====
$treasuries = $pdo->query(
    "SELECT t.*, b.name AS branch_name
     FROM treasuries t LEFT JOIN branches b ON b.id = t.branch_id
     WHERE t.is_active = 1
     ORDER BY FIELD(t.type,'cash','main','visa','instapay','card','branch_custody','employee_custody'), t.name"
)->fetchAll();

$centralTreasuries = array_filter($treasuries, fn($t) => !in_array($t['type'], ['branch_custody', 'employee_custody'], true));
// الخزينة الرئيسية = إجمالي الخزائن الفرعية (نظرة كلية، مش خزينة فعلية)
$mainTotal = 0.0;
// «خزينة الكاش الفعلي» = الخزينة الرئيسية + خزينة الكاش، وبس.
// بتتحسب من نفس الصفوف اللي اتجابت فوق — من غير أي استعلام زيادة.
$realCash  = 0.0;
foreach ($centralTreasuries as $t) {
    $mainTotal += (float) $t['balance'];
    if (in_array($t['type'], ['main', 'cash'], true)) {
        $realCash += (float) $t['balance'];
    }
}

// ===== عهد الفروع (للبارات) =====
$custodies = $pdo->query(
    "SELECT bc.*, b.name AS branch_name, t.balance AS treasury_balance
     FROM branch_custody bc
     JOIN branches b ON b.id = bc.branch_id
     JOIN treasuries t ON t.id = bc.custody_treasury_id
     WHERE bc.status = 'open' AND bc.employee_id IS NULL"
)->fetchAll();

$allBranchesWithCustody = $pdo->query(
    "SELECT b.id, b.name, t.id AS treasury_id, t.balance
     FROM branches b
     JOIN treasuries t ON t.branch_id = b.id AND t.type='branch_custody'
     WHERE b.is_active = 1 AND b.has_finance = 1
     ORDER BY b.name"
)->fetchAll();

$openByBranch = [];
foreach ($custodies as $c) $openByBranch[(int)$c['branch_id']] = $c;

$custodyBars = [];
foreach ($allBranchesWithCustody as $br) {
    $bid = (int) $br['id'];
    $open = $openByBranch[$bid] ?? null;
    if ($open) {
        $opening = (float) $open['opening_balance'];
        $balance = (float) $open['treasury_balance'];
    } else {
        $opening = 0.0; $balance = 0.0;
    }
    $pct = $opening > 0 ? max(0, min(100, ($balance / $opening) * 100)) : 0;
    if ($opening <= 0)  $color = 'empty';
    elseif ($pct <= 20) $color = 'red';
    elseif ($pct < 50)  $color = 'orange';
    else                $color = 'green';
    $custodyBars[] = [
        'name' => $br['name'], 'opening' => $opening, 'balance' => $balance,
        'pct' => $pct, 'color' => $color,
    ];
}

// ===== عهد الموظفين (المفتوحة فقط — تختفي فور الإقفال والاعتماد) =====
$empCustodies = $pdo->query(
    "SELECT bc.*, e.full_name AS employee_name, t.balance AS treasury_balance
     FROM branch_custody bc
     JOIN employees e ON e.id = bc.employee_id
     JOIN treasuries t ON t.id = bc.custody_treasury_id
     WHERE bc.status = 'open' AND bc.employee_id IS NOT NULL
     ORDER BY e.full_name"
)->fetchAll();

$empCustodyBars = [];
foreach ($empCustodies as $ec) {
    $opening = (float) $ec['opening_balance'];
    $balance = (float) $ec['treasury_balance'];
    $pct = $opening > 0 ? max(0, min(100, ($balance / $opening) * 100)) : 0;
    if ($opening <= 0)  $color = 'empty';
    elseif ($pct <= 20) $color = 'red';
    elseif ($pct < 50)  $color = 'orange';
    else                $color = 'green';
    $empCustodyBars[] = [
        'name' => $ec['employee_name'], 'opening' => $opening, 'balance' => $balance,
        'pct' => $pct, 'color' => $color,
    ];
}

// ===== ملخص الفترة =====
$salesStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(cash_total+visa_total+instapay_total),0)
     FROM shifts WHERE status='approved' AND business_date BETWEEN ? AND ?"
);
$salesStmt->execute([$from, $to]);
$totalSales = (float) $salesStmt->fetchColumn();

$diffStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM shifts WHERE ABS(difference) > 0.001 AND business_date BETWEEN ? AND ?"
);
$diffStmt->execute([$from, $to]);
$shiftsWithDiff = (int) $diffStmt->fetchColumn();

$pendingReview = (int) $pdo->query(
    "SELECT COUNT(*) FROM shifts WHERE status IN ('submitted','resubmitted')"
)->fetchColumn();

$upcomingPayments = (int) $pdo->query(
    "SELECT COUNT(*) FROM supplier_payment_schedules
     WHERE status='pending' AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)"
)->fetchColumn();

// ===== حالة تقفيلات الفترة =====
$periodShifts = $pdo->prepare(
    "SELECT s.*, u.full_name AS cashier_name, b.name AS branch_name
     FROM shifts s JOIN users u ON u.id=s.user_id JOIN branches b ON b.id=s.branch_id
     WHERE s.business_date BETWEEN ? AND ?
     ORDER BY s.id DESC LIMIT 10"
);
$periodShifts->execute([$from, $to]);
$periodShifts = $periodShifts->fetchAll();

$typeIcons = [
    'cash'=>'ti-cash','visa'=>'ti-credit-card','instapay'=>'ti-device-mobile',
    'main'=>'ti-building-bank','card'=>'ti-credit-card',
];
$statusLabels = [
    'submitted'=>['مستلمة','var(--ink-700)','var(--line-soft)'],'approved'=>['معتمدة','var(--pos)','var(--pos-soft)'],
    'rejected'=>['مرفوضة','var(--neg)','var(--neg-soft)'],'resubmitted'=>['معاد إرسال','var(--warn)','var(--warn-soft)'],
];

$page_title    = 'الرئيسية';
$active_module = 'dashboard';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .period-bar { display:flex; gap:10px; align-items:center; margin-bottom:14px; flex-wrap:wrap; }
    .period-btn { background:var(--surface); border:1.5px solid var(--line); border-radius:10px; padding:10px 16px;
        font-size:14px; color:var(--accent-text); cursor:pointer; font-family:inherit; display:flex; align-items:center; gap:7px; text-decoration:none; }
    .period-btn.active { background:var(--accent); color:#fff; border-color:var(--accent-text); }
    .period-custom { display:flex; gap:8px; align-items:center; }
    .period-custom input { padding:9px 11px; border:1.5px solid var(--line); border-radius:9px; font-family:inherit; font-size:13px; }
    .period-custom button { background:var(--accent); color:#fff; border:none; border-radius:9px; padding:9px 14px; cursor:pointer; font-family:inherit; }
    .sec-label { font-size:13px; color:var(--ink-500); margin:0 0 8px; font-weight:600; }
    /* الكارت المجمّع سطر كامل، والخزائن تحته تتوزّع بالتساوي على العرض. */
    .treasury-grid { display:flex; flex-wrap:wrap; gap:14px; margin-bottom:22px; }
    .treasury-grid > .tre-box { flex:1 1 0; min-width:150px; }
    .tre-box { background:var(--surface); border-radius:14px; padding:18px; box-shadow:0 2px 10px rgba(124,111,247,0.06); }

    .tre-box.main {
        flex:1 1 58%;   /* كان 100% — ضاق عشان كارت الكاش الفعلي يقف جنبه */
        border:2px solid var(--accent);
        background:linear-gradient(135deg,var(--line-soft) 0%,#fff 60%);
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:16px;
        padding:16px 22px;
    }
    .tre-box.main .tre-top { margin-bottom:0; font-size:14px; }
    .tre-box.main .tre-val { font-size:30px; }
    /* خزينة الكاش الفعلي — بيقف يمين الكارت الأزرق */
    .tre-box.realcash {
        flex:1 1 34%; border:2px solid var(--pos);
        background:linear-gradient(135deg,var(--pos-soft) 0%,#fff 70%);
        display:flex; align-items:center; justify-content:space-between;
        gap:16px; padding:16px 22px;
    }
    .tre-box.realcash .tre-top { margin-bottom:0; font-size:14px; color:var(--pos); }
    .tre-box.realcash .tre-val { font-size:30px; }
    @media (max-width:820px){
        .tre-box.main, .tre-box.realcash { flex:1 1 100%; }
    }

    .tre-box .tre-top { display:flex; align-items:center; gap:8px; color:var(--ink-500); font-size:13px; margin-bottom:10px; }
    .tre-box .tre-val { font-size:26px; font-weight:800; color:var(--ink-900); }
    .tre-box.main .tre-val { color:var(--accent-text); }
    .tre-box .tre-cur { font-size:13px; color:var(--ink-400); font-weight:500; }
    .summary-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:14px; margin-bottom:22px; }
    .sum-box { background:var(--surface); border-radius:14px; padding:18px; box-shadow:0 2px 10px rgba(124,111,247,0.06); }
    .sum-box .l { font-size:13px; color:var(--ink-500); margin-bottom:6px; display:flex; align-items:center; gap:6px; }
    .sum-box .v { font-size:28px; font-weight:800; }
    .sum-box .u { font-size:12px; color:var(--ink-400); margin-top:2px; }
    .v-green { color:var(--pos); } .v-orange { color:var(--warn); } .v-purple { color:var(--accent-text); }
    .bars-card, .shifts-card { background:var(--surface); border-radius:14px; padding:20px; box-shadow:0 2px 10px rgba(124,111,247,0.06); }
    .bottom-grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
    @media (max-width:880px){ .bottom-grid { grid-template-columns:1fr; } }
    .bar-row { margin-bottom:16px; }
    .bar-head { display:flex; justify-content:space-between; font-size:14px; margin-bottom:6px; }
    .bar-head .bn { color:var(--ink-900); font-weight:600; }
    .bar-head .bv { color:var(--ink-500); font-size:13px; }
    .bar-track { height:12px; background:var(--line); border-radius:8px; overflow:hidden; border:1px solid var(--line); }
    .bar-fill { height:100%; border-radius:8px; transition:width .3s; }
    .bar-fill.green { background:var(--pos-fill); }
    .bar-fill.orange { background:var(--warn-fill); }
    .bar-fill.red { background:var(--neg-fill); }
    .bar-fill.empty { background:transparent; }
    .section-title { font-size:16px; color:var(--ink-900); font-weight:600; margin-bottom:14px; }
    .shift-item { display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-bottom:1px solid var(--line); font-size:14px; }
    .badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:500; }
    .empty { text-align:center; color:var(--ink-400); padding:24px; font-size:14px; }
    .quick-actions { display:flex; gap:8px; margin-top:14px; }
    .qa-btn { padding:9px 16px; border-radius:9px; font-size:14px; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
    .qa-primary { background:var(--accent); color:#fff; } .qa-ghost { background:var(--bg); color:var(--accent-text); }
    /* موبايل: الكروت تبقى أعمدة مرنة من غير عرض ثابت يطلّع الصفحة عريضة */
    @media (max-width:760px){
        .summary-grid { grid-template-columns:repeat(2,1fr); }
        .tre-box .tre-val { font-size:22px; }
        .sum-box .v { font-size:24px; }
        .period-custom { flex-wrap:wrap; }
        .period-custom input { flex:1; min-width:120px; }
        .quick-actions { flex-wrap:wrap; }
    }
    @media (max-width:430px){
        .summary-grid { grid-template-columns:1fr; }
    }
</style>

<div class="period-bar">
    <a href="dashboard.php" class="period-btn <?= $isToday?'active':'' ?>"><i class="ti ti-calendar"></i> مبيعات اليوم</a>
    <form method="get" class="period-custom">
        <span style="color:var(--ink-500);font-size:13px;">من</span>
        <input type="date" name="from" value="<?= e($from) ?>">
        <span style="color:var(--ink-500);font-size:13px;">إلى</span>
        <input type="date" name="to" value="<?= e($to) ?>">
        <button type="submit">عرض</button>
    </form>
</div>

<p class="sec-label">أرصدة الخزائن</p>
<div class="treasury-grid">
    <div class="tre-box main">
        <div class="tre-top"><i class="ti ti-building-bank"></i> الخزائن المركزية (بدون عهد الفروع)</div>
        <div class="tre-val"><?= ltr_out(money_fmt($mainTotal)) ?> <span class="tre-cur">ج.م</span></div>
    </div>
    <div class="tre-box realcash" title="الخزينة الرئيسية + خزينة الكاش">
        <div class="tre-top"><i class="ti ti-cash"></i> خزينة الكاش الفعلي</div>
        <div class="tre-val"><?= ltr_out(money_fmt($realCash)) ?> <span class="tre-cur">ج.م</span></div>
    </div>
    <?php foreach ($centralTreasuries as $t): ?>
        <div class="tre-box">
            <div class="tre-top"><i class="ti <?= e($typeIcons[$t['type']]??'ti-cash') ?>"></i> <?= e($t['name']) ?></div>
            <div class="tre-val"><?= ltr_out(money_fmt((float)$t['balance'])) ?> <span class="tre-cur">ج.م</span></div>
        </div>
    <?php endforeach; ?>
</div>

<p class="sec-label"><?= $isToday ? 'ملخص اليوم' : 'ملخص الفترة' ?></p>
<div class="summary-grid">
    <div class="sum-box">
        <div class="l"><i class="ti ti-cash-banknote"></i> إجمالي المبيعات</div>
        <div class="v v-green"><?= money_fmt($totalSales) ?></div>
        <div class="u">ج.م — معتمدة</div>
    </div>
    <div class="sum-box">
        <div class="l"><i class="ti ti-clock"></i> في انتظار مراجعة</div>
        <div class="v v-orange"><?= $pendingReview ?></div>
        <div class="u">تقفيلات</div>
    </div>
    <div class="sum-box">
        <div class="l"><i class="ti ti-alert-triangle"></i> تقفيلات فيها فرق</div>
        <div class="v <?= $shiftsWithDiff?'v-orange':'v-green' ?>"><?= $shiftsWithDiff ?></div>
        <div class="u"><?= $shiftsWithDiff?'تحتاج مراجعة':'كل شيء تمام' ?></div>
    </div>
    <div class="sum-box">
        <div class="l"><i class="ti ti-truck"></i> دفعات موردين قادمة</div>
        <div class="v v-purple"><?= $upcomingPayments ?></div>
        <div class="u">خلال 3 أيام</div>
    </div>
</div>

<div class="bottom-grid">
    <div class="bars-card">
        <div class="section-title">خزنة العهدة — حالة الفروع</div>
        <?php if (empty($custodyBars)): ?>
            <div class="empty">لا توجد فروع.</div>
        <?php else: ?>
            <?php foreach ($custodyBars as $bar): ?>
                <div class="bar-row">
                    <div class="bar-head">
                        <span class="bn">عهدة <?= e($bar['name']) ?></span>
                        <span class="bv"><?= number_format($bar['balance'],0) ?> / <?= number_format($bar['opening'],0) ?> ج.م</span>
                    </div>
                    <div class="bar-track">
                        <div class="bar-fill <?= e($bar['color']) ?>" style="width:<?= $bar['opening']>0 ? round($bar['pct']) : 0 ?>%;"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($empCustodyBars)): ?>
            <div class="section-title" style="margin-top:20px;border-top:1px solid var(--line-soft);padding-top:14px;">عُهد الموظفين (المفتوحة)</div>
            <?php foreach ($empCustodyBars as $bar): ?>
                <div class="bar-row">
                    <div class="bar-head">
                        <span class="bn">عهدة <?= e($bar['name']) ?></span>
                        <span class="bv"><?= number_format($bar['balance'],0) ?> / <?= number_format($bar['opening'],0) ?> ج.م</span>
                    </div>
                    <div class="bar-track">
                        <div class="bar-fill <?= e($bar['color']) ?>" style="width:<?= $bar['opening']>0 ? round($bar['pct']) : 0 ?>%;"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="shifts-card">
        <div class="section-title">حالة التقفيلات <?= $isToday?'اليوم':'' ?></div>
        <?php if (empty($periodShifts)): ?>
            <div class="empty">لا توجد تقفيلات في هذه الفترة.</div>
        <?php else: ?>
            <?php foreach ($periodShifts as $s): ?>
                <?php $sl = $statusLabels[$s['status']] ?? ['—','var(--ink-500)','var(--line)']; ?>
                <div class="shift-item">
                    <span><?= e($s['cashier_name']) ?> — <?= e($s['branch_name']) ?></span>
                    <span class="badge" style="color:<?=$sl[1]?>;background:<?=$sl[2]?>;"><?= e($sl[0]) ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <div class="quick-actions">
            <?php if (can('review')): ?><a href="review.php" class="qa-btn qa-primary"><i class="ti ti-checklist"></i> مراجعة</a><?php endif; ?>
            <?php if (can('cashier')): ?><a href="cashier.php" class="qa-btn qa-ghost"><i class="ti ti-plus"></i> إضافة تقفيل</a><?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
