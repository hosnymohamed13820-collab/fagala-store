<?php
/**
 * site/treasury_ops_lib.php
 * منطق عمليات الخزينة (صرف واستلام) والتحقق منها
 * مستخرج من site/treasury.php (بريف ٨٦)
 */
declare(strict_types=1);

require_once __DIR__ . '/finance.php';

/**
 * تنظيف المبلغ من فواصل الآلاف والأرقام العربية (نفس منطق money_post في finance.php:25)
 */
function treasury_clean_amount(mixed $val, float $default = 0.0): float
{
    if ($val === null || $val === '') {
        return $default;
    }
    $raw = str_replace([',', '٬', ' ', "\xC2\xA0"], '', (string) $val);
    $raw = strtr($raw, [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
    return is_numeric($raw) ? (float) $raw : $default;
}

/**
 * التحقق من خانات العملية قبل الحفظ (بدون تحريك فلوس)
 * يحتوي على نفس رسايل الخطأ الموجودة في treasury_apply_op
 */
function treasury_validate_op(string $action, array $in): void
{
    if ($action !== 'receipt' && $action !== 'disbursement') {
        throw new RuntimeException('نوع العملية غير صحيح.');
    }
    $tid = (int) ($in['treasury_id'] ?? 0);
    if ($tid <= 0) {
        throw new RuntimeException('اختار الخزينة.');
    }
    $amount = treasury_clean_amount($in['amount'] ?? 0.0);
    if ($amount <= 0) {
        throw new RuntimeException('المبلغ لازم يكون أكبر من صفر.');
    }
    fin_valid_date((string) ($in['date'] ?? date('Y-m-d')));
    $opType = $in['op_type'] ?? 'normal';
    $entryCodeId = ($in['entry_code_id'] ?? '') !== '' ? (int) $in['entry_code_id'] : null;

    if ($action === 'disbursement' && $opType === 'advance') {
        $empId = (int) ($in['employee_id'] ?? 0);
        if ($empId <= 0) throw new RuntimeException('اختر الموظف للسلفة.');
    }
    elseif ($action === 'disbursement' && $opType === 'custody') {
        $bId = (int) ($in['branch_id'] ?? 0);
        if ($bId <= 0) throw new RuntimeException('اختر الفرع للعهدة.');
        $ct = db()->prepare("SELECT id FROM treasuries WHERE branch_id=? AND type='branch_custody' LIMIT 1");
        $ct->execute([$bId]);
        $ctId = (int) $ct->fetchColumn();
        if (!$ctId) throw new RuntimeException('لا توجد خزينة عهدة لهذا الفرع.');
    }
    elseif ($action === 'disbursement' && $opType === 'employee_custody') {
        $empId = (int) ($in['employee_id'] ?? 0);
        if ($empId <= 0) throw new RuntimeException('اختر الموظف للعهدة.');
    }
    elseif ($action === 'receipt' && $opType === 'advance') {
        $empId = (int) ($in['employee_id'] ?? 0);
        if ($empId <= 0) throw new RuntimeException('اختر الموظف لرد السلفة.');
    }
    elseif ($action === 'receipt' && $opType === 'custody') {
        $bId = (int) ($in['branch_id'] ?? 0);
        if ($bId <= 0) throw new RuntimeException('اختر الفرع لاستلام العهدة.');
    }
    elseif ($opType === 'supplier') {
        $suppId = (int) ($in['supplier_id'] ?? 0);
        if ($suppId <= 0) {
            throw new RuntimeException('اختر المورد.');
        }
        $stSupp = db()->prepare('SELECT id, name FROM suppliers WHERE id = ? AND is_active = 1 LIMIT 1');
        $stSupp->execute([$suppId]);
        $supp = $stSupp->fetch();
        if (!$supp) {
            throw new RuntimeException('المورد غير موجود أو غير نشط.');
        }
        sync_dynamic_accounts();
        $stAcc = db()->prepare("SELECT id FROM accounts WHERE src_table = 'suppliers' AND src_id = ? AND is_active = 1 LIMIT 1");
        $stAcc->execute([$suppId]);
        $accId = (int) $stAcc->fetchColumn();
        if (!$accId) {
            throw new RuntimeException('المورد ده مالوش حساب في شجرة الحسابات — اتأكد إن فيه أب «موردين» في التعريفات.');
        }
        $costBranchId = ($in['cost_branch_id'] ?? '') !== ''
            ? (int) $in['cost_branch_id'] : null;
        if (empty($costBranchId)) {
            throw new RuntimeException('اختار الفرع.');
        }
    }
    else {
        // القيد والفرع إجباريين في الاستلام والصرف العادي
        if (empty($entryCodeId)) {
            throw new RuntimeException('اختار القيد.');
        }
        $costBranchId = ($in['cost_branch_id'] ?? '') !== ''
            ? (int) $in['cost_branch_id'] : null;
        if (empty($costBranchId)) {
            throw new RuntimeException('اختار الفرع.');
        }
    }
}

/**
 * تطبيق عملية الصرف أو الاستلام على الخزينة
 * (نقل حرفي للكود الأصلي من site/treasury.php)
 *
 * @param string $action 'receipt' أو 'disbursement'
 * @param array $in مصفوفة البيانات (سواء $_POST أو payload الإذن)
 * @param int $uid معرّف المستخدم المنفذ للعملية
 * @param int|null $outMovementId معرّف الحركة المرتجع من treasury_movement إن وُجد
 * @return string رسالة الفلاش الناتجة عن العملية
 */
function treasury_apply_op(string $action, array $in, int $uid, ?int &$outMovementId = null, bool $custodyFromChosen = false): string
{
    $pdo = db();
    $tid    = (int) ($in['treasury_id'] ?? 0);
    $amount = treasury_clean_amount($in['amount'] ?? 0.0);
    if ($amount <= 0) throw new RuntimeException('المبلغ لازم يكون أكبر من صفر.');
    $date   = fin_valid_date((string) ($in['date'] ?? date('Y-m-d')));
    $desc   = trim((string) ($in['description'] ?? ''));
    $opType = $in['op_type'] ?? 'normal';  // normal / advance / custody
    $entryCodeId = ($in['entry_code_id'] ?? '') !== '' ? (int) $in['entry_code_id'] : null;
    $notes  = trim((string) ($in['notes'] ?? ''));

    if ($action === 'disbursement' && $opType === 'advance') {
        $empId = (int) ($in['employee_id'] ?? 0);
        if ($empId <= 0) throw new RuntimeException('اختر الموظف للسلفة.');
        disburse_advance($empId, $amount, $tid, $date, $desc ?: null, $uid);
        notify_advance_disbursed([
            [
                'employee_id' => $empId,
                'amount'      => $amount,
                'date'        => $date,
            ],
        ]);
        $flash = 'تم صرف السلفة وتسجيلها على الموظف.';
    }
    elseif ($action === 'disbursement' && $opType === 'custody') {
        $bId = (int) ($in['branch_id'] ?? 0);
        if ($bId <= 0) throw new RuntimeException('اختر الفرع للعهدة.');
        $ct = $pdo->prepare("SELECT id FROM treasuries WHERE branch_id=? AND type='branch_custody' LIMIT 1");
        $ct->execute([$bId]);
        $ctId = (int) $ct->fetchColumn();
        if (!$ctId) throw new RuntimeException('لا توجد خزينة عهدة لهذا الفرع.');
        if ($custodyFromChosen) {
            issue_disbursement_order($bId, $ctId, $amount, $date, $desc ?: null, $uid, $tid);
        } else {
            issue_disbursement_order($bId, $ctId, $amount, $date, $desc ?: null, $uid);
        }
        $flash = 'تم صرف العهدة للفرع (الخزينة نقصت وعهدة الفرع زادت).';
    }
    elseif ($action === 'disbursement' && $opType === 'employee_custody') {
        $empId = (int) ($in['employee_id'] ?? 0);
        if ($empId <= 0) throw new RuntimeException('اختر الموظف للعهدة.');
        if ($custodyFromChosen) {
            issue_employee_disbursement_order($empId, $amount, $date, $desc ?: null, $uid, $tid);
        } else {
            issue_employee_disbursement_order($empId, $amount, $date, $desc ?: null, $uid);
        }
        $flash = 'تم صرف العهدة للموظف (الخزينة نقصت وعهدة الموظف زادت).';
    }
    elseif ($action === 'receipt' && $opType === 'advance') {
        $empId = (int) ($in['employee_id'] ?? 0);
        if ($empId <= 0) throw new RuntimeException('اختر الموظف لرد السلفة.');
        recover_advance($empId, $amount, $tid, $date, $desc ?: null, $uid);
        $flash = 'تم رد السلفة وخصمها من رصيد الموظف.';
    }
    elseif ($action === 'receipt' && $opType === 'custody') {
        $bId = (int) ($in['branch_id'] ?? 0);
        if ($bId <= 0) throw new RuntimeException('اختر الفرع لاستلام العهدة.');
        receive_custody($bId, $amount, $tid, $date, $desc ?: null, $uid);
        $flash = 'تم استلام العهدة من الفرع (الخزينة زادت وعهدة الفرع نقصت).';
    }
    elseif ($opType === 'supplier') {
        $suppId = (int) ($in['supplier_id'] ?? 0);
        if ($suppId <= 0) {
            throw new RuntimeException('اختر المورد.');
        }
        $stSupp = $pdo->prepare('SELECT id, name FROM suppliers WHERE id = ? AND is_active = 1 LIMIT 1');
        $stSupp->execute([$suppId]);
        $supp = $stSupp->fetch();
        if (!$supp) {
            throw new RuntimeException('المورد غير موجود أو غير نشط.');
        }
        sync_dynamic_accounts();
        $stAcc = $pdo->prepare("SELECT id FROM accounts WHERE src_table = 'suppliers' AND src_id = ? AND is_active = 1 LIMIT 1");
        $stAcc->execute([$suppId]);
        $accId = (int) $stAcc->fetchColumn();
        if (!$accId) {
            throw new RuntimeException('المورد ده مالوش حساب في شجرة الحسابات — اتأكد إن فيه أب «موردين» في التعريفات.');
        }
        $costBranchId = ($in['cost_branch_id'] ?? '') !== ''
            ? (int) $in['cost_branch_id'] : null;
        if (empty($costBranchId)) {
            throw new RuntimeException('اختار الفرع.');
        }
        $opDesc = $desc !== ''
            ? $desc
            : (($action === 'disbursement' ? 'صرف للمورد ' : 'استلام من المورد ') . $supp['name']);
        $movId = treasury_movement(
            $tid, $action, $amount, $date, $opDesc, $uid,
            $accId, $costBranchId, $notes !== '' ? $notes : null
        );
        $outMovementId = $movId;
        $flash = ($action === 'disbursement')
            ? 'تم تسجيل الصرف للمورد.'
            : 'تم تسجيل الاستلام من المورد.';
    }
    else {
        // القيد والفرع إجباريين في الاستلام والصرف العادي
        if (empty($entryCodeId)) {
            throw new RuntimeException('اختار القيد.');
        }
        // فرع التكلفة (تحليلي) — اسمه cost_branch_id في الفورم عشان
        // ما يتعارضش مع branch_id بتاع صرف/استلام العهدة.
        $costBranchId = ($in['cost_branch_id'] ?? '') !== ''
            ? (int) $in['cost_branch_id'] : null;
        if (empty($costBranchId)) {
            throw new RuntimeException('اختار الفرع.');
        }
        $movId = treasury_movement($tid, $action, $amount, $date, $desc ?: null, $uid,
                          $entryCodeId, $costBranchId, $notes ?: null);
        $outMovementId = $movId;
        $flash = ($action === 'receipt') ? 'تم تسجيل الاستلام.' : 'تم تسجيل الصرف.';
    }

    return $flash;
}
