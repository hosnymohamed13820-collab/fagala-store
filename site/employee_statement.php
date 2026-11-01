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
require_once __DIR__ . '/salary_view_lib.php';
require_once __DIR__ . '/salary_pin_lib.php';

$pdo = db();
$u = current_user();
$uid = (int)($u['id'] ?? 0);

$isManager = is_owner() || can('hr:advances');
$requestedEmpId = isset($_GET['emp']) && (int)$_GET['emp'] > 0 ? (int)$_GET['emp'] : null;

$pinRequired = salary_pin_statement_required(db(), $uid, $isManager, $requestedEmpId);
// مسميات أنواع الحركات بالعامية
$moveTypeLabels = [
    'advance'          => 'سلفة',
    'salary_deduction' => 'خصم من المرتب',
    'cash_repay'       => 'سداد نقدي',
    'cancel'           => 'إلغاء سلفة',
    'opening'          => 'رصيد افتتاحي',
];

$page_title = 'كشف حساب الموظف';
$active_module = ''; // مش موديول في القايمة الجانبية
require_once __DIR__ . '/includes/header.php';
if ($pinRequired && !salary_pin_unlocked()) {
    salary_pin_lock_screen('كشف حساب الموظف');
    require_once __DIR__ . '/includes/footer.php';
    exit;
}
$data = employee_statement_data($uid, $isManager, $requestedEmpId, $pdo);
$targetEmpId    = $data['target_emp_id'];
$employee       = $data['employee'];
$notLinked      = $data['not_linked'];
$ledgerEntries  = $data['ledger_entries'];
$currentBalance = $data['current_balance'];

$curBalInfo = format_ledger_balance($currentBalance);
if ($pinRequired) salary_pin_lock_button();
?>

<style>
    .statement-wrap {
        max-width: 860px;
        margin: 0 auto;
        padding: 10px 0 30px;
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
    .statement-message {
        padding: 40px 24px;
        text-align: center;
        max-width: 480px;
        margin: 30px auto;
    }
    .msg-icon {
        font-size: 44px;
        color:var(--accent-text);
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
    .empty {
        text-align: center;
        padding: 40px 20px;
        color: var(--ink-500);
        font-size: 14px;
    }
</style>

<div class="statement-wrap">
<?php if ($notLinked): ?>
    <div class="statement-message fcard">
        <div class="msg-icon"><i class="ti ti-user-cog"></i></div>
        <div class="msg-title">الحساب غير مربوط بملف موظف</div>
        <div class="msg-desc">
            حسابك الحالي مش مربوط ببيانات موظف في الموارد البشرية.<br>
            تواصل مع الإدارة لربط حسابك بملفك.
        </div>
    </div>
<?php elseif ($isManager && (!$employee || $targetEmpId <= 0)): ?>
    <div class="statement-message fcard">
        <div class="msg-icon"><i class="ti ti-users"></i></div>
        <div class="msg-title">لم يتم تحديد موظف</div>
        <div class="msg-desc">
            يرجى اختيار موظف من شاشة السلف لعرض كشف حسابه.<br><br>
            <a href="hr.php?tab=advances" class="btn btn-secondary"><i class="ti ti-arrow-right"></i> الذهاب لشاشة السلف</a>
        </div>
    </div>
<?php else: ?>
    <div class="statement-card fcard">
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
            <div class="ftable-wrap">
                <table class="ftable">
                    <thead>
                        <tr>
                            <th style="width: 100px;">التاريخ</th>
                            <th>البيان</th>
                            <th class="num" style="width: 100px;">عليه</th>
                            <th class="num" style="width: 100px;">له</th>
                            <th class="num" style="width: 140px;">الرصيد</th>
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
                            <td class="num">
                                <?php if ($entry['direction'] === 'debit'): ?>
                                    <span style="color: var(--neg); font-weight: 600;"><?= number_format($entry['amount'], 2) ?></span>
                                <?php else: ?>
                                    <span style="color: var(--ink-400);">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="num">
                                <?php if ($entry['direction'] === 'credit'): ?>
                                    <span style="color: var(--pos); font-weight: 600;"><?= number_format($entry['amount'], 2) ?></span>
                                <?php else: ?>
                                    <span style="color: var(--ink-400);">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="num">
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
