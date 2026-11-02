<?php
/**
 * salary_view_lib.php — مكتبة دوال جلب بيانات المرتب وكشف الحساب (قراءة فقط)
 * نظام الفجالة ستور المالي (بريف ١٣٦)
 *
 * بتوفّر دوال استعلام مشتركة للصفحات والـ APIs:
 *  - my_salary_data()          ← جلب تفاصيل آخر مرتب مصروف
 *  - employee_statement_data()  ← جلب حركات كشف حساب الموظف والرصيد التراكمي
 *  - format_ledger_balance()    ← تنسيق الرصيد التراكمي بالألوان والمسميات
 */
declare(strict_types=1);

require_once __DIR__ . '/finance.php';

/**
 * جلب تفاصيل آخر مرتب مصروف للموظف.
 *
 * @param int      $uid             معرف المستخدم الحالي
 * @param bool     $isManager       هل المستخدم مدير (أونر أو معه hr:salaries)
 * @param int|null $requestedEmpId  الموظف المطلوب في الباراميتر (لو مدير)
 * @param PDO|null $pdo             اتصال قاعدة البيانات
 * @return array
 */
function my_salary_data(int $uid, bool $isManager, ?int $requestedEmpId = null, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $targetEmpId = null;
    $employee = null;
    $notLinked = false;

    if ($isManager) {
        if ($requestedEmpId !== null && $requestedEmpId > 0) {
            $targetEmpId = $requestedEmpId;
            $st = $pdo->prepare('SELECT id, full_name, salary_mode FROM employees WHERE id = ?');
            $st->execute([$targetEmpId]);
            $employee = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } else {
            // لو الأونر أو مسؤول الرواتب ما حددش موظف، نعرض مرتب حسابه لو مربوط بموظف
            $st = $pdo->prepare('SELECT id, full_name, salary_mode FROM employees WHERE user_id = ?');
            $st->execute([$uid]);
            $employee = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($employee) {
                $targetEmpId = (int) $employee['id'];
            }
        }
    } else {
        // الموظف العادي: الربط من حسابه فقط، وأي باراميتر ?emp= يتم تجاهله تماماً
        $st = $pdo->prepare('SELECT id, full_name, salary_mode FROM employees WHERE user_id = ?');
        $st->execute([$uid]);
        $employee = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($employee) {
            $targetEmpId = (int) $employee['id'];
        } else {
            $notLinked = true;
        }
    }

    // جلب آخر مرتب مصروف
    $salary = null;
    $showDetails = false;
    $expiredOrNoSalary = false;

    if ($employee && $targetEmpId !== null && $targetEmpId > 0) {
        $salStmt = $pdo->prepare(
            'SELECT * FROM salaries
             WHERE employee_id = ? AND status = "paid" AND reversed_at IS NULL
             ORDER BY paid_at DESC, id DESC
             LIMIT 1'
        );
        $salStmt->execute([$targetEmpId]);
        $salary = $salStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($salary) {
            if ($isManager) {
                // صاحب الصلاحية يشوف في أي وقت بدون التقيد بنافذة الأسبوع
                $showDetails = true;
            } else {
                // الموظف العادي: نافذة ٧ أيام من paid_at
                $paidTime = !empty($salary['paid_at']) ? strtotime((string) $salary['paid_at']) : 0;
                if ($paidTime > 0 && (time() - $paidTime) <= (7 * 86400)) {
                    $showDetails = true;
                } else {
                    $expiredOrNoSalary = true;
                }
            }
        } else {
            $expiredOrNoSalary = true;
        }
    }

    return [
        'target_emp_id'        => $targetEmpId,
        'employee'             => $employee,
        'not_linked'           => $notLinked,
        'salary'               => $salary,
        'show_details'         => $showDetails,
        'expired_or_no_salary' => $expiredOrNoSalary,
    ];
}

/**
 * دالة مساعدة لتنسيق الرصيد بالكلام والألوان (بدون إشارة ناقص)
 */
function format_ledger_balance(float $bal): array
{
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

/**
 * جلب حركات كشف حساب الموظف وحساب الرصيد التراكمي.
 *
 * @param int      $uid             معرف المستخدم الحالي
 * @param bool     $isManager       هل المستخدم مدير (أونر أو معه hr:advances)
 * @param int|null $requestedEmpId  الموظف المطلوب في الباراميتر (لو مدير)
 * @param PDO|null $pdo             اتصال قاعدة البيانات
 * @return array
 */
function employee_statement_data(int $uid, bool $isManager, ?int $requestedEmpId = null, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $targetEmpId = null;
    $employee = null;
    $notLinked = false;

    if ($isManager) {
        if ($requestedEmpId !== null && $requestedEmpId > 0) {
            $targetEmpId = $requestedEmpId;
            $st = $pdo->prepare('SELECT id, full_name FROM employees WHERE id = ?');
            $st->execute([$targetEmpId]);
            $employee = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } else {
            // لو الأونر أو صاحب الصلاحية ما حددش موظف، نعرض كشف حسابه لو حسابه مربوط بموظف
            $st = $pdo->prepare('SELECT id, full_name FROM employees WHERE user_id = ?');
            $st->execute([$uid]);
            $employee = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($employee) {
                $targetEmpId = (int) $employee['id'];
            }
        }
    } else {
        // الموظف العادي: الربط من حسابه فقط، وأي باراميتر ?emp= يتم تجاهله تماماً
        $st = $pdo->prepare('SELECT id, full_name FROM employees WHERE user_id = ?');
        $st->execute([$uid]);
        $employee = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($employee) {
            $targetEmpId = (int) $employee['id'];
        } else {
            $notLinked = true;
        }
    }

    $rows = [];
    if ($employee && $targetEmpId !== null && $targetEmpId > 0) {
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
        $amount = (float) $r['amount'];
        $dir = (string) $r['direction'];
        if ($dir === 'debit') {
            $runningBalance += $amount;
        } else {
            $runningBalance -= $amount;
        }
        $ledgerEntries[] = [
            'id'            => (int) $r['id'],
            'entry_date'    => (string) $r['entry_date'],
            'direction'     => $dir,
            'amount'        => $amount,
            'move_type'     => (string) $r['move_type'],
            'description'   => (string) ($r['description'] ?? ''),
            'treasury_id'   => $r['treasury_id'] !== null ? (int) $r['treasury_id'] : null,
            'treasury_name' => (string) ($r['treasury_name'] ?? ''),
            'ref_type'      => $r['ref_type'] !== null ? (string) $r['ref_type'] : null,
            'ref_id'        => $r['ref_id'] !== null ? (int) $r['ref_id'] : null,
            'running_bal'   => $runningBalance,
        ];
    }

    return [
        'target_emp_id'   => $targetEmpId,
        'employee'        => $employee,
        'not_linked'      => $notLinked,
        'ledger_entries'  => $ledgerEntries,
        'current_balance' => $runningBalance,
    ];
}

// تعليقات النسخة القديمة للأجزاء اللي اتنقلت للمكتبة؛ محفوظة للمراجعة.

// جلب حركات كشف الحساب
