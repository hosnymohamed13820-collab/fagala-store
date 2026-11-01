<?php
/**
 * my_salary.php — تفاصيل المرتب (واجهة الموظف)
 * نظام الفجالة ستور المالي
 *
 * - صفحة خاصة بالموظف لعرض تفاصيل آخر مرتب تم صرفه له خلال نافذة أسبوع (٧ أيام من تاريخ الصرف).
 * - للأونر ولمسؤول الرواتب (hr:salaries): إمكانية عرض مرتب أي موظف في أي وقت عبر ?emp=.
 * - للموظف العادي: يتم تحديد الموظف من حسابه فقط وأي باراميتر في الرابط يتم تجاهله تماماً.
 * - بعد مرور ٧ أيام على الصرف: لا تظهر أي أرقام وتظهر رسالة لطيفة بالعامية.
 */
require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/salary_view_lib.php';

$pdo = db();
$u = current_user();
$uid = (int)($u['id'] ?? 0);

$isManager = is_owner() || can('hr:salaries');
$requestedEmpId = isset($_GET['emp']) && (int)$_GET['emp'] > 0 ? (int)$_GET['emp'] : null;

$page_title = 'تفاصيل المرتب';
$active_module = ''; // مش موديول في القايمة الجانبية
require_once __DIR__ . '/salary_pin_lib.php';
require_once __DIR__ . '/includes/header.php';

if (!salary_pin_unlocked()) {
    salary_pin_lock_screen('تفاصيل المرتب');
    require_once __DIR__ . '/includes/footer.php';
    exit;
}
$data = my_salary_data($uid, $isManager, $requestedEmpId, $pdo);
$targetEmpId       = $data['target_emp_id'];
$employee          = $data['employee'];
$notLinked         = $data['not_linked'];
$salary            = $data['salary'];
$showDetails       = $data['show_details'];
$expiredOrNoSalary = $data['expired_or_no_salary'];

salary_pin_lock_button();
?>

<style>
    .salary-wrap {
        max-width: 520px;
        margin: 0 auto;
        padding: 10px 0 30px;
    }
    .salary-head {
        text-align: center;
        margin-bottom: 20px;
        padding-bottom: 16px;
        border-bottom: 1px solid var(--line-soft);
    }
    .salary-emp-name {
        font-size: 20px;
        font-weight: 700;
        color: var(--ink-900);
        margin-bottom: 6px;
    }
    .salary-month-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: var(--line-soft);
        color: var(--ink-700);
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 600;
    }
    .salary-section-title {
        font-size: 13px;
        font-weight: 700;
        color: var(--ink-500);
        margin: 16px 0 8px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .salary-meta-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: var(--bg);
        border-radius: var(--r, 10px);
        padding: 10px 14px;
        margin-bottom: 12px;
        font-size: 13px;
    }
    .salary-meta-row .lbl {
        color: var(--ink-500);
    }
    .salary-meta-row .val {
        color: var(--ink-800);
        font-weight: 600;
    }
    .salary-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        margin-bottom: 14px;
    }
    .salary-item {
        background: var(--bg);
        border-radius: var(--r, 10px);
        padding: 12px 14px;
    }
    .salary-item .lbl {
        font-size: 12px;
        color: var(--ink-500);
        margin-bottom: 4px;
    }
    .salary-item .val {
        font-size: 15px;
        font-weight: 600;
        color: var(--ink-900);
    }
    .salary-item.bonus .val {
        color: var(--pos);
    }
    .salary-item.deduct .val {
        color: var(--neg);
    }
    .salary-net-box {
        background: var(--pos-soft);
        border: 1.5px solid var(--pos);
        border-radius: var(--r-lg, 14px);
        padding: 18px;
        text-align: center;
        margin-top: 18px;
    }
    .salary-net-box .lbl {
        font-size: 13px;
        font-weight: 700;
        color: var(--pos);
        margin-bottom: 4px;
    }
    .salary-net-box .val {
        font-size: 28px;
        font-weight: 800;
        color: var(--pos);
        letter-spacing: 0.5px;
    }
    .salary-message {
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
</style>

<div class="salary-wrap">
<?php if ($notLinked): ?>
    <div class="salary-message fcard">
        <div class="msg-icon"><i class="ti ti-user-cog"></i></div>
        <div class="msg-title">الحساب غير مربوط بملف موظف</div>
        <div class="msg-desc">
            حسابك الحالي مش مربوط ببيانات موظف في الموارد البشرية.<br>
            تواصل مع الإدارة لربط حسابك بملفك.
        </div>
    </div>
<?php elseif ($isManager && (!$employee || $targetEmpId <= 0)): ?>
    <div class="salary-message fcard">
        <div class="msg-icon"><i class="ti ti-users"></i></div>
        <div class="msg-title">لم يتم تحديد موظف</div>
        <div class="msg-desc">
            يرجى اختيار موظف من شاشة الرواتب لعرض تفاصيل مرتبه.<br><br>
            <a href="hr.php?tab=salaries" class="btn btn-secondary"><i class="ti ti-arrow-right"></i> الذهاب لشاشة الرواتب</a>
        </div>
    </div>
<?php elseif ($expiredOrNoSalary || !$showDetails): ?>
    <div class="salary-message fcard">
        <div class="msg-icon"><i class="ti ti-wallet"></i></div>
        <div class="msg-title">شكراً لك</div>
        <div class="msg-desc">
            مفيش بيانات مرتب دلوقتي.<br>
            هتظهرلك تاني أول ما المرتب الجاي ينزل.
        </div>
    </div>
<?php else:
    // تجهيز البيانات للعرض
    $isFixed = ($salary['work_hours'] === null && $salary['due_days'] === null);

    $periodStr = '—';
    if (!empty($salary['period_from']) && !empty($salary['period_to'])) {
        $periodStr = 'من ' . e($salary['period_from']) . ' إلى ' . e($salary['period_to']);
    }

    $workHoursStr = $salary['work_hours'] !== null ? (number_format((float)$salary['work_hours'], 2) . ' ساعة') : '—';
    $workDaysStr  = $salary['work_days'] !== null ? (rtrim(rtrim(number_format((float)$salary['work_days'], 3), '0'), '.') . ' يوم') : '—';
    $leaveDaysStr = (!$isFixed && isset($salary['leave_days'])) ? ((int)$salary['leave_days'] . ' يوم') : '—';
    $dueDaysStr   = $salary['due_days'] !== null ? (rtrim(rtrim(number_format((float)$salary['due_days'], 3), '0'), '.') . ' يوم') : '—';

    $baseSalary = (float)$salary['base_salary'];
    $bonuses    = (float)$salary['total_bonuses'];
    $advances   = (float)$salary['total_advances'];
    $penalties  = (float)$salary['total_penalties'];
    $deficits   = (float)$salary['total_deficits'];
    $cashierDef = (float)($salary['total_cashier_deficits'] ?? 0);
    $netSalary  = (float)$salary['net_salary'];

    // الاستحقاق قبل الخصم
    $grossSalary = $netSalary - $bonuses + $advances + $penalties + $deficits + $cashierDef;
?>
    <div class="salary-card fcard">
        <div class="salary-head">
            <div class="salary-emp-name"><?= e($employee['full_name']) ?></div>
            <div class="salary-month-badge">
                <i class="ti ti-calendar"></i>
                <span>مرتب شهر <?= e($salary['salary_month']) ?></span>
            </div>
        </div>

        <div class="salary-section-title">فترة الحضور والعمل</div>
        <div class="salary-meta-row">
            <span class="lbl">فترة الحضور</span>
            <span class="val"><?= $periodStr ?></span>
        </div>

        <div class="salary-grid">
            <div class="salary-item">
                <div class="lbl">ساعات العمل</div>
                <div class="val"><?= $workHoursStr ?></div>
            </div>
            <div class="salary-item">
                <div class="lbl">أيام العمل</div>
                <div class="val"><?= $workDaysStr ?></div>
            </div>
            <div class="salary-item">
                <div class="lbl">أيام الإجازة</div>
                <div class="val"><?= $leaveDaysStr ?></div>
            </div>
            <div class="salary-item">
                <div class="lbl">أيام الاستحقاق</div>
                <div class="val"><?= $dueDaysStr ?></div>
            </div>
        </div>

        <div class="salary-section-title">المستحقات والخصومات</div>
        <div class="salary-grid">
            <div class="salary-item">
                <div class="lbl">الأساسي</div>
                <div class="val"><?= number_format($baseSalary, 2) ?> ج.م</div>
            </div>
            <div class="salary-item">
                <div class="lbl">الاستحقاق قبل الخصم</div>
                <div class="val"><?= number_format($grossSalary, 2) ?> ج.م</div>
            </div>
            <div class="salary-item bonus">
                <div class="lbl">المكافآت (+)</div>
                <div class="val"><?= number_format($bonuses, 2) ?> ج.م</div>
            </div>
            <div class="salary-item deduct">
                <div class="lbl">السلف (-)</div>
                <div class="val"><?= number_format($advances, 2) ?> ج.م</div>
            </div>
            <div class="salary-item deduct">
                <div class="lbl">الجزاءات (-)</div>
                <div class="val"><?= number_format($penalties, 2) ?> ج.م</div>
            </div>
            <div class="salary-item deduct">
                <div class="lbl">عجز العهدة (-)</div>
                <div class="val"><?= number_format($deficits, 2) ?> ج.م</div>
            </div>
            <?php if ($cashierDef > 0): ?>
            <div class="salary-item deduct">
                <div class="lbl">عجز كاشير (-)</div>
                <div class="val"><?= number_format($cashierDef, 2) ?> ج.م</div>
            </div>
            <?php endif; ?>
        </div>

        <div class="salary-net-box">
            <div class="lbl">صافي المرتب المصروف</div>
            <div class="val"><?= number_format($netSalary, 2) ?> ج.م</div>
            <?php if ($netSalary <= 0.005 && ($advances + $penalties + $deficits + $cashierDef) > 0): ?>
                <div style="font-size: 12px; color: var(--neg); margin-top: 6px; font-weight: 600;">
                    المرتب مشالش كل الخصومات — الباقي اتحوّل سلفة عليك.
                </div>
            <?php endif; ?>
        </div>

        <div style="text-align: center; margin-top: 16px;">
            <a href="employee_statement.php" style="font-size: 13px; color:var(--accent-text); text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                <i class="ti ti-file-invoice"></i> كشف حسابك
            </a>
        </div>

        <?php if ($isManager): ?>
            <div style="text-align: center; margin-top: 20px;">
                <a href="hr.php?tab=salaries" class="btn btn-secondary">
                    <i class="ti ti-arrow-right"></i> الرجوع لشاشة الرواتب
                </a>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
