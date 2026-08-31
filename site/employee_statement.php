<?php
/**
 * employee_statement.php — كشف حساب الموظف (سجل الحركات) — قراءة فقط
 * نظام الفجالة ستور المالي
 *
 * - صفحة خاصة بعرض كشف حساب الموظف وحركاته في employee_ledger والرصيد التراكمي.
 * - للأونر ولمسؤول السلف (hr:advances): إمكانية عرض كشف حساب أي موظف عبر ?emp=.
 * - للموظف العادي: يتم تحديد الموظف من حسابه فقط وأي باراميتر ?emp= في الرابط يتم تجاهله تماماً.
 * - قراءة خالصة: لا توجد أي عمليات إضافة أو تعديل أو حذف في أي جدول.
 */
require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/finance.php';

$pdo = db();
$u = current_user();
$uid = (int)($u['id'] ?? 0);

$isManager = is_owner() || can('hr:advances');
$targetEmpId = null;
$employee = null;
$notLinked = false;

if ($isManager) {
    if (isset($_GET['emp']) && (int)$_GET['emp'] > 0) {
        $targetEmpId = (int)$_GET['emp'];
        $st = $pdo->prepare('SELECT id, full_name FROM employees WHERE id = ?');
        $st->execute([$targetEmpId]);
        $employee = $st->fetch(PDO::FETCH_ASSOC);
    } else {
        // لو الأونر أو صاحب الصلاحية ما حددش موظف، نعرض كشف حسابه لو حسابه مربوط بموظف
        $st = $pdo->prepare('SELECT id, full_name FROM employees WHERE user_id = ?');
        $st->execute([$uid]);
        $employee = $st->fetch(PDO::FETCH_ASSOC);
        if ($employee) {
            $targetEmpId = (int)$employee['id'];
        }
    }
} else {
    // الموظف العادي: الربط من حسابه فقط، وأي باراميتر ?emp= يتم تجاهله تماماً
    $st = $pdo->prepare('SELECT id, full_name FROM employees WHERE user_id = ?');
    $st->execute([$uid]);
    $employee = $st->fetch(PDO::FETCH_ASSOC);
    if ($employee) {
        $targetEmpId = (int)$employee['id'];
    } else {
        $notLinked = true;
    }
}

// جلب حركات كشف الحساب
$rows = [];
if ($employee && $targetEmpId > 0) {
    $st = $pdo->prepare(
        'SELECT el.*, t.name AS treasury_name
         FROM employee_ledger el
         LEFT JOIN treasuries t ON t.id = el.treasury_id
         WHERE el.employee_id = ?
         ORDER BY el.entry_date ASC, el.id ASC'
    );
    $st->execute([$targetEmpId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
}

// حساب الرصيد التراكمي في PHP باستعلام واحد مجمّع
$runningBalance = 0.0;
$ledgerEntries = [];
foreach ($rows as $r) {
    $amount = (float)$r['amount'];
    $dir = $r['direction'];
    if ($dir === 'debit') {
        $runningBalance += $amount;
    } else {
        $runningBalance -= $amount;
    }
    $ledgerEntries[] = [
        'id'            => (int)$r['id'],
        'entry_date'    => $r['entry_date'],
        'direction'     => $dir,
        'amount'        => $amount,
        'move_type'     => $r['move_type'],
        'description'   => $r['description'] ?? '',
        'treasury_id'   => $r['treasury_id'],
        'treasury_name' => $r['treasury_name'] ?? '',
        'ref_type'      => $r['ref_type'],
        'ref_id'        => $r['ref_id'],
        'running_bal'   => $runningBalance,
    ];
}
$currentBalance = $runningBalance;

// مسميات أنواع الحركات بالعامية
$moveTypeLabels = [
    'advance'          => 'سلفة',
    'salary_deduction' => 'خصم من المرتب',
    'cash_repay'       => 'سداد نقدي',
    'cancel'           => 'إلغاء سلفة',
    'opening'          => 'رصيد افتتاحي',
];

/**
 * دالة مساعدة لتنسيق الرصيد بالكلام والألوان (بدون إشارة ناقص)
 */
function format_ledger_balance(float $bal): array {
    if (abs($bal) < 0.005) {
        return [
            'label' => 'مفيش رصيد',
            'full'  => 'مفيش رصيد',
            'color' => 'var(--ink-700)',
            'bg'    => 'var(--line-soft)',
            'type'  => 'zero',
        ];
    }
    if ($bal > 0) {
        return [
            'label' => number_format($bal, 2) . ' عليه',
            'full'  => number_format($bal, 2) . ' عليه',
            'color' => 'var(--neg)',
            'bg'    => 'var(--neg-soft)',
            'type'  => 'debit',
        ];
    }
    return [
        'label' => number_format(abs($bal), 2) . ' له',
        'full'  => number_format(abs($bal), 2) . ' له',
        'color' => 'var(--pos)',
        'bg'    => 'var(--pos-soft)',
        'type'  => 'credit',
    ];
}

$curBalInfo = format_ledger_balance($currentBalance);

$page_title = 'كشف حساب الموظف';
$active_module = ''; // مش موديول في القايمة الجانبية
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .statement-wrap {
        max-width: 860px;
        margin: 0 auto;
        padding: 10px 0 30px;
    }
    .statement-card {
        background:var(--surface);
        border-radius: var(--r-lg, 16px);
        padding: 24px;
        box-shadow: var(--shadow, 0 2px 12px rgba(124,111,247,0.08));
        margin-bottom: 20px;
    }
    .statement-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 20px;
        padding-bottom: 16px;
        border-bottom: 1px solid var(--line-soft);
    }
    .statement-emp-name {
        font-size: 20px;
        font-weight: 700;
        color: var(--ink-900);
    }
    .statement-balance-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 16px;
        border-radius: 20px;
        font-size: 16px;
        font-weight: 700;
    }
    .msg-card {
        background:var(--surface);
        border-radius: var(--r-lg, 16px);
        padding: 40px 24px;
        text-align: center;
        box-shadow: var(--shadow, 0 2px 12px rgba(124,111,247,0.08));
        max-width: 480px;
        margin: 30px auto;
    }
    .msg-icon {
        font-size: 44px;
        color: var(--accent);
        margin-bottom: 14px;
    }
    .msg-title {
        font-size: 18px;
        font-weight: 700;
        color: var(--ink-900);
        margin-bottom: 8px;
    }
    .msg-desc {
        font-size: 14px;
        color: var(--ink-500);
        line-height: 1.6;
    }
    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 9px 18px;
        border-radius: var(--r, 10px);
        font-size: 13px;
        font-weight: 600;
        font-family: inherit;
        cursor: pointer;
        text-decoration: none;
        border: none;
        transition: background 0.15s ease;
    }
    .btn-secondary {
        background: var(--line-soft);
        color: var(--ink-700);
    }
    .btn-secondary:hover {
        background: var(--line);
        color: var(--ink-700);
    }
    .btn-ghost {
        background: transparent;
        color: var(--accent);
    }
    .btn-ghost:hover {
        background: var(--accent-soft);
        color: var(--accent);
    }
    .empty {
        text-align: center;
        padding: 40px 20px;
        color: var(--ink-500);
        font-size: 14px;
    }
</style>

<div class="statement-wrap">
<?php if ($notLinked): ?>
    <div class="msg-card">
        <div class="msg-icon"><i class="ti ti-user-cog"></i></div>
        <div class="msg-title">الحساب غير مربوط بملف موظف</div>
        <div class="msg-desc">
            حسابك الحالي مش مربوط ببيانات موظف في الموارد البشرية.<br>
            تواصل مع الإدارة لربط حسابك بملفك.
        </div>
    </div>
<?php elseif ($isManager && (!$employee || $targetEmpId <= 0)): ?>
    <div class="msg-card">
        <div class="msg-icon"><i class="ti ti-users"></i></div>
        <div class="msg-title">لم يتم تحديد موظف</div>
        <div class="msg-desc">
            يرجى اختيار موظف من شاشة السلف لعرض كشف حسابه.<br><br>
            <a href="hr.php?tab=advances" class="btn btn-secondary"><i class="ti ti-arrow-right"></i> الذهاب لشاشة السلف</a>
        </div>
    </div>
<?php else: ?>
    <div class="statement-card">
        <div class="statement-head">
            <div>
                <div class="statement-emp-name"><?= e($employee['full_name']) ?></div>
                <div style="font-size: 13px; color: var(--ink-500); margin-top: 4px;">كشف الحركات المالية والأرصدة</div>
            </div>
            <div class="statement-balance-badge" style="color: <?= $curBalInfo['color'] ?>; background: <?= $curBalInfo['bg'] ?>;">
                <i class="ti ti-wallet"></i>
                <span>الرصيد الحالي: <?= e($curBalInfo['label']) ?></span>
            </div>
        </div>

        <?php if (empty($ledgerEntries)): ?>
            <div class="empty">لا توجد حركات مسجلة في كشف الحساب.</div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 100px;">التاريخ</th>
                            <th>البيان</th>
                            <th style="width: 100px; text-align: left;">عليه</th>
                            <th style="width: 100px; text-align: left;">له</th>
                            <th style="width: 140px; text-align: left;">الرصيد</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($ledgerEntries as $entry):
                        $typeLabel = $moveTypeLabels[$entry['move_type']] ?? $entry['move_type'];
                        $descText = trim($entry['description']);
                        $rowBal = format_ledger_balance($entry['running_bal']);
                    ?>
                        <tr>
                            <td style="font-size: 13px; white-space: nowrap;"><?= e($entry['entry_date']) ?></td>
                            <td>
                                <div style="font-weight: 500; color: var(--ink-900);">
                                    <?= e($descText !== '' ? $descText : $typeLabel) ?>
                                    <?php if ($descText !== '' && $descText !== $typeLabel): ?>
                                        <span style="font-size: 11px; color: var(--ink-500); font-weight: normal; margin-right: 4px;">(<?= e($typeLabel) ?>)</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($entry['treasury_name'])): ?>
                                    <div style="font-size: 11px; color: var(--ink-500); margin-top: 2px;">
                                        <i class="ti ti-building-bank" style="font-size: 11px;"></i> خزينة <?= e($entry['treasury_name']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: left;">
                                <?php if ($entry['direction'] === 'debit'): ?>
                                    <span style="color: var(--neg); font-weight: 600;"><?= number_format($entry['amount'], 2) ?></span>
                                <?php else: ?>
                                    <span style="color: var(--ink-400);">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: left;">
                                <?php if ($entry['direction'] === 'credit'): ?>
                                    <span style="color: var(--pos); font-weight: 600;"><?= number_format($entry['amount'], 2) ?></span>
                                <?php else: ?>
                                    <span style="color: var(--ink-400);">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: left;">
                                <span style="color: <?= $rowBal['color'] ?>; font-weight: 600;"><?= e($rowBal['full']) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div style="text-align: center; margin-top: 24px; display: flex; justify-content: center; gap: 10px;">
            <?php if ($isManager): ?>
                <a href="hr.php?tab=advances" class="btn btn-secondary">
                    <i class="ti ti-arrow-right"></i> الرجوع لشاشة السلف
                </a>
            <?php else: ?>
                <a href="my_salary.php" class="btn btn-secondary">
                    <i class="ti ti-arrow-right"></i> الرجوع لتفاصيل المرتب
                </a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
