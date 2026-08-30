<?php
/**
 * hr.php — الموارد البشرية (للإدارة)
 * نظام الفجالة ستور المالي
 *
 * تبويبات: نظرة عامة | السلف | المكافآت | الجزاءات | الإجازات | الرواتب | طلبات التصحيح
 * - الرواتب بتصرف من الخزينة وبتضيف المكافآت وتخصم السلف والجزاءات وعجز العهدة.
 * - طلبات تصحيح الحضور تتعتمد/ترفض هنا.
 */
require_once __DIR__ . '/auth.php';
require_module_access('hr');
require_once __DIR__ . '/finance.php';

$pdo = db();
$uid = (int) current_user()['id'];
$flash = ''; $flashType = 'success';

// قراءة التابات المسموحة للمستخدم بناءً على أجزاء مديول الموارد البشرية
$hrParts = module_parts()['hr'] ?? [];
// أجزاء صلاحيات مش تابات — دي مفاتيح لصفحات منفصلة بتتفتح بزرار من جوّه التاب.
// لو اتشالت من هنا هتطلع تابات فاضية بترندر سجل الحضور بالغلط.
const HR_NON_TAB_PARTS = ['attendance_import'];

$allowedTabs = [];
foreach ($hrParts as $tabKey => $tabLabel) {
    if (in_array($tabKey, HR_NON_TAB_PARTS, true)) continue;
    if (can('hr:' . $tabKey)) {
        $allowedTabs[$tabKey] = $tabLabel;
    }
}

// لو المستخدم مالوش أي تاب مسموح (حزام أمان إضافي)
if (empty($allowedTabs)) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;text-align:center;padding:60px;direction:rtl">'
        . '<h2>🚫 غير مصرّح</h2>'
        . '<p>ليس لديك صلاحية للوصول إلى هذه الصفحة.</p>'
        . '<a href="logout.php">تسجيل الخروج</a></div>');
}

// تحديد التاب النشط: لو طلب تاب مسموح يفتحه، لو مالوش صلاحيته يتحوّل لأول تاب مسموح
$requestedTab = (string)($_GET['tab'] ?? '');
if (isset($allowedTabs[$requestedTab])) {
    $tab = $requestedTab;
} else {
    $tab = (string) array_key_first($allowedTabs);
}

// القوائم المنسدلة للمودالات العامة في آخر الصفحة — يتم تحميلها دائماً
$payTreasuries = $pdo->query(
    "SELECT id, name FROM treasuries WHERE branch_id IS NULL AND is_active=1
     ORDER BY FIELD(type,'cash','main','visa','instapay','card')"
)->fetchAll();
$employees = $pdo->query('SELECT id, full_name FROM employees WHERE is_active=1 ORDER BY full_name')->fetchAll();

/**
 * تطبيق تعديلات المكافآت والسلف من شاشة الرواتب في قاعدة البيانات.
 * مفيش أي استعلام جوه حلقة:
 * - استعلام مجمّع واحد لجلب عدد وحالة المكافآت المعلّقة لكل الموظفين.
 * - استعلام مجمّع واحد لجلب عدد وحالة السلف المعلّقة (شهرية ومستديمة) لكل الموظفين.
 * - إعداد الاستعلامات (prepare) مرة واحدة خارج الحلقة والتنفيذ فقط للصفوف المتغيرة.
 * - لا يوجد أي حذف (DELETE) للسجلات المالية — التصفير يتم بتحديث المبلغ أو المتبقي لـ 0.
 * - الخانة الفاضية يتم تجاهلها تماماً، والتعديل يمس فقط المبلغ/المتبقي دون مساس بالسبب أو الملاحظات الأصلية.
 */
function apply_payroll_financial_adjustments(PDO $pdo, string $month, string $date, array $bonusesPost, array $monthlyAdvPost, array $standingAdvPost): void
{
    // 1) جلب المكافآت المعلّقة مجمّعة لكل موظف (استعلام واحد)
    $bonStmt = $pdo->query(
        "SELECT employee_id,
                COUNT(*) AS cnt,
                MIN(id) AS single_id,
                COALESCE(SUM(amount), 0) AS total_amount
         FROM employee_bonuses
         WHERE status = 'pending'
         GROUP BY employee_id"
    );
    $bonMap = [];
    foreach ($bonStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $bonMap[(int)$r['employee_id']] = [
            'cnt'          => (int)$r['cnt'],
            'single_id'    => $r['single_id'] !== null ? (int)$r['single_id'] : null,
            'total_amount' => (float)$r['total_amount'],
        ];
    }

    // 2) جلب السلف المعلّقة مقسمة حسب النوع مجمّعة لكل موظف (استعلام واحد)
    $advStmt = $pdo->query(
        "SELECT employee_id,
                SUM(CASE WHEN adv_type = 'monthly' THEN 1 ELSE 0 END) AS monthly_cnt,
                MIN(CASE WHEN adv_type = 'monthly' THEN id ELSE NULL END) AS monthly_single_id,
                COALESCE(SUM(CASE WHEN adv_type = 'monthly' THEN COALESCE(remaining, amount) ELSE 0 END), 0) AS monthly_amount,
                SUM(CASE WHEN adv_type = 'standing' THEN 1 ELSE 0 END) AS standing_cnt,
                MIN(CASE WHEN adv_type = 'standing' THEN id ELSE NULL END) AS standing_single_id,
                COALESCE(SUM(CASE WHEN adv_type = 'standing' THEN COALESCE(remaining, amount) ELSE 0 END), 0) AS standing_amount
         FROM employee_advances
         WHERE status = 'pending'
         GROUP BY employee_id"
    );
    $advMap = [];
    foreach ($advStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $advMap[(int)$r['employee_id']] = [
            'monthly_cnt'        => (int)$r['monthly_cnt'],
            'monthly_single_id'  => $r['monthly_single_id'] !== null ? (int)$r['monthly_single_id'] : null,
            'monthly_amount'     => (float)$r['monthly_amount'],
            'standing_cnt'       => (int)$r['standing_cnt'],
            'standing_single_id' => $r['standing_single_id'] !== null ? (int)$r['standing_single_id'] : null,
            'standing_amount'    => (float)$r['standing_amount'],
        ];
    }

    // إعداد الجمل مرة واحدة خارج الحلقة (Prepared Statements)
    $insBonus = $pdo->prepare('INSERT INTO employee_bonuses (employee_id, amount, bonus_date, reason, status) VALUES (?, ?, ?, ?, "pending")');
    $updBonus = $pdo->prepare('UPDATE employee_bonuses SET amount = ? WHERE id = ? AND status = "pending"');

    $insAdv = $pdo->prepare('INSERT INTO employee_advances (employee_id, amount, advance_date, status, notes, monthly_deduction, remaining, adv_type) VALUES (?, ?, ?, "pending", ?, 0.00, ?, "monthly")');
    $updAdv = $pdo->prepare('UPDATE employee_advances SET remaining = ? WHERE id = ? AND status = "pending" AND adv_type = ?');

    $noteReason = 'من شاشة الرواتب — شهر ' . $month;

    // معالجة المكافآت
    foreach ($bonusesPost as $empIdRaw => $newValRaw) {
        $empId = (int)$empIdRaw;
        if ($empId <= 0) continue;

        // إذا كانت الخانة ممسوحة أو متروكة فاضية، تجاهلها تماماً
        $valStr = trim((string)$newValRaw);
        if ($valStr === '') {
            continue;
        }

        $newAmount = round((float)$valStr, 2);
        if ($newAmount < 0) $newAmount = 0.0;
        $info = $bonMap[$empId] ?? ['cnt' => 0, 'single_id' => null, 'total_amount' => 0.0];

        if ($info['cnt'] > 1) {
            // أكثر من صف معلّق: الحقل كان مقفول ولا يتم التعديل
            continue;
        } elseif ($info['cnt'] === 1) {
            // صف واحد معلّق: تحديث المبلغ لنفس الصف إذا تغيّرت القيمة (حتى لو أصبحت 0) مع الحفاظ على السبب الأصلي
            if (abs($newAmount - $info['total_amount']) > 0.001) {
                $updBonus->execute([$newAmount, $info['single_id']]);
            }
        } else {
            // لا يوجد صفوف معلّقة: إضافة صف جديد فقط لو القيمة أكبر من صفر
            if ($newAmount > 0) {
                $insBonus->execute([$empId, $newAmount, $date, $noteReason]);
            }
        }
    }

    // معالجة سلف الشهر (monthly)
    foreach ($monthlyAdvPost as $empIdRaw => $newValRaw) {
        $empId = (int)$empIdRaw;
        if ($empId <= 0) continue;

        $valStr = trim((string)$newValRaw);
        if ($valStr === '') {
            continue;
        }

        $newAmount = round((float)$valStr, 2);
        if ($newAmount < 0) $newAmount = 0.0;
        $info = $advMap[$empId] ?? ['monthly_cnt' => 0, 'monthly_single_id' => null, 'monthly_amount' => 0.0];

        if ($info['monthly_cnt'] > 1) {
            continue;
        } elseif ($info['monthly_cnt'] === 1) {
            // صف واحد معلّق: تحديث المتبقي remaining فقط لنفس الصف مع الحفاظ على المبلغ الأصلي amount والملاحظات
            if (abs($newAmount - $info['monthly_amount']) > 0.001) {
                $updAdv->execute([$newAmount, $info['monthly_single_id'], 'monthly']);
                emp_ledger_diff($pdo, $empId, (float) $info['monthly_amount'], $newAmount,
                                $date, 'advance', (int) $info['monthly_single_id']);
            }
        } else {
            // لا يوجد سلف شهرية معلّقة: إنشاء سلفة شهرية جديدة لو القيمة أكبر من صفر
            if ($newAmount > 0) {
                $insAdv->execute([$empId, $newAmount, $date, $noteReason, $newAmount]);
                emp_ledger_add($empId, 'debit', $newAmount, $date, 'advance',
                               'سلفة اتسجّلت من شاشة الرواتب', null, 'advance',
                               (int) $pdo->lastInsertId(), emp_ledger_uid(), $pdo);
            }
        }
    }

    // معالجة السلف المستديمة (standing) — تعديل الرصيد القائم فقط، وإنشاء الجديد من تاب السلف حصراً
    foreach ($standingAdvPost as $empIdRaw => $newValRaw) {
        $empId = (int)$empIdRaw;
        if ($empId <= 0) continue;

        $valStr = trim((string)$newValRaw);
        if ($valStr === '') {
            continue;
        }

        $newAmount = round((float)$valStr, 2);
        if ($newAmount < 0) $newAmount = 0.0;
        $info = $advMap[$empId] ?? ['standing_cnt' => 0, 'standing_single_id' => null, 'standing_amount' => 0.0];

        if ($info['standing_cnt'] > 1) {
            continue;
        } elseif ($info['standing_cnt'] === 1) {
            // صف واحد معلّق: تحديث المتبقي remaining فقط لنفس الصف مع الحفاظ على القسط والمبلغ الأصلي والملاحظات
            if (abs($newAmount - $info['standing_amount']) > 0.001) {
                $updAdv->execute([$newAmount, $info['standing_single_id'], 'standing']);
                emp_ledger_diff($pdo, $empId, (float) $info['standing_amount'], $newAmount,
                                $date, 'advance', (int) $info['standing_single_id']);
            }
        }
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $flash = 'انتهت صلاحية الجلسة، حاول مرة أخرى.';
        $flashType = 'error';
    } else {
        $action = (string)($_POST['action'] ?? '');

        // خريطة العمليات الصريحة وربط كل عملية بجزئها
        $actionMap = [
            'add_bonus'                => 'bonuses',
            'add_penalty'              => 'penalties',
            'add_leave'                => 'leaves',
            'add_advance'              => 'advances',
            'add_opening_balance'      => 'advances',
            'update_advance_deduction' => 'advances',
            'cancel_advance'           => 'advances',
            'save_payroll_adjustments' => 'salaries',
            'pay_salary'               => 'salaries',
            'pay_salary_batch'         => 'salaries',
            'approve_request'          => 'requests',
            'reject_request'           => 'requests',
            'update_request_time'      => 'requests',
            'delete_attendance'        => 'attendance',
        ];

        $targetPart = $actionMap[$action] ?? null;
        if ($targetPart === null) {
            $flash = 'عملية غير معروفة أو غير مصرّح بها.';
            $flashType = 'error';
        } elseif (!can('hr:' . $targetPart)) {
            $flash = 'غير مصرّح لك بتنفيذ هذه العملية.';
            $flashType = 'error';
        } else {
            try {
                if ($action === 'add_bonus') {
                $empId = (int)($_POST['employee_id'] ?? 0);
                $amount = (float)($_POST['amount'] ?? 0);
                $date = $_POST['date'] ?? date('Y-m-d');
                $reason = trim($_POST['reason'] ?? '');
                if ($empId<=0 || $amount<=0) throw new RuntimeException('بيانات غير مكتملة.');
                $pdo->prepare('INSERT INTO employee_bonuses (employee_id, amount, bonus_date, reason, status) VALUES (?,?,?,?,"pending")')
                    ->execute([$empId,$amount,$date,$reason ?: null]);
                $flash = 'تم تسجيل المكافأة.'; $tab='bonuses';
            }
            elseif ($action === 'add_penalty') {
                $empId = (int)($_POST['employee_id'] ?? 0);
                $amount = (float)($_POST['amount'] ?? 0);
                $date = $_POST['date'] ?? date('Y-m-d');
                $reason = trim($_POST['reason'] ?? '');
                if ($empId<=0 || $amount<=0) throw new RuntimeException('بيانات غير مكتملة.');
                $pdo->prepare('INSERT INTO employee_penalties (employee_id, amount, penalty_date, reason, status) VALUES (?,?,?,?,"pending")')
                    ->execute([$empId,$amount,$date,$reason ?: null]);
                $flash = 'تم تسجيل الجزاء.'; $tab='penalties';
            }
            elseif ($action === 'add_leave') {
                $empId = (int)($_POST['employee_id'] ?? 0);
                $start = $_POST['start_date'] ?? date('Y-m-d');
                $end   = $_POST['end_date'] ?? $start;
                $type  = $_POST['leave_type'] ?? 'annual';
                $days  = max(1, (int)((strtotime($end)-strtotime($start))/86400)+1);
                if ($empId<=0) throw new RuntimeException('اختر الموظف.');
                $pdo->prepare('INSERT INTO employee_leaves (employee_id, start_date, end_date, leave_type, days) VALUES (?,?,?,?,?)')
                    ->execute([$empId,$start,$end,$type,$days]);
                $flash = 'تم تسجيل الإجازة.'; $tab='leaves';
            }
            elseif ($action === 'update_advance_deduction') {
                $advId = (int)($_POST['advance_id'] ?? 0);
                $monthlyDeduction = (float)($_POST['monthly_deduction'] ?? 0);
                if ($advId <= 0 || $monthlyDeduction < 0) {
                    throw new RuntimeException('بيانات غير صحيحة.');
                }
                $chk = $pdo->prepare('SELECT id, status FROM employee_advances WHERE id = ?');
                $chk->execute([$advId]);
                $advRow = $chk->fetch();
                if (!$advRow) {
                    throw new RuntimeException('السلفة غير موجودة.');
                }
                if ($advRow['status'] !== 'pending') {
                    throw new RuntimeException('لا يمكن تعديل قسط سلفة غير معلّقة.');
                }
                $pdo->prepare('UPDATE employee_advances SET monthly_deduction = ? WHERE id = ?')
                    ->execute([$monthlyDeduction, $advId]);
                $flash = 'تم تحديث القسط الشهري للسلفة بنجاح.'; $tab='advances';
            }
            elseif ($action === 'cancel_advance') {
                if (!is_owner()) {
                    throw new RuntimeException('غير مصرّح لك بإلغاء السلف.');
                }
                $advId = (int)($_POST['advance_id'] ?? 0);
                if ($advId <= 0) {
                    throw new RuntimeException('بيانات غير صحيحة.');
                }
                $chk = $pdo->prepare('SELECT id, employee_id, amount, remaining, status, notes FROM employee_advances WHERE id = ?');
                $chk->execute([$advId]);
                $advRow = $chk->fetch();
                if (!$advRow) {
                    throw new RuntimeException('السلفة غير موجودة.');
                }
                if ($advRow['status'] !== 'pending') {
                    throw new RuntimeException('لا يمكن إلغاء سلفة غير معلّقة.');
                }
                $oldNotes = trim((string)($advRow['notes'] ?? ''));
                $cancelNote = 'تم إلغاء السلفة بتاريخ ' . date('Y-m-d');
                $newNotes = $oldNotes !== '' ? ($oldNotes . "\n" . $cancelNote) : $cancelNote;

                // المتبقي بيتقرا قبل التصفير — لو اتقرا بعده هيبقى صفر والحركة تضيع
                $cancelledAmount = $advRow['remaining'] !== null
                    ? (float) $advRow['remaining']
                    : (float) $advRow['amount'];

                $pdo->prepare('UPDATE employee_advances SET remaining = 0, notes = ? WHERE id = ? AND status = "pending"')
                    ->execute([$newNotes, $advId]);

                emp_ledger_add(
                    (int) $advRow['employee_id'],
                    'credit',
                    $cancelledAmount,
                    date('Y-m-d'),
                    'cancel',
                    'إلغاء سلفة',
                    null,
                    'advance',
                    $advId,
                    $uid,
                    $pdo
                );
                $flash = 'تم إلغاء السلفة بنجاح.'; $tab='advances';
            }
            elseif ($action === 'add_advance') {
                $empId = (int)($_POST['employee_id'] ?? 0);
                $amount = (float)($_POST['amount'] ?? 0);
                $date = trim((string)($_POST['advance_date'] ?? date('Y-m-d')));
                if (!strtotime($date)) {
                    $date = date('Y-m-d');
                }
                $advType = trim((string)($_POST['adv_type'] ?? 'standing'));
                if (!in_array($advType, ['monthly', 'standing'], true)) {
                    $advType = 'standing';
                }
                $monthlyDeduction = (float)($_POST['monthly_deduction'] ?? 0);
                if ($monthlyDeduction < 0) {
                    $monthlyDeduction = 0;
                }
                $notes = trim((string)($_POST['notes'] ?? ''));
                if ($empId <= 0 || $amount <= 0) {
                    throw new RuntimeException('بيانات غير مكتملة.');
                }
                $chkEmp = $pdo->prepare('SELECT full_name FROM employees WHERE id = ?');
                $chkEmp->execute([$empId]);
                if (!$chkEmp->fetchColumn()) {
                    throw new RuntimeException('الموظف غير موجود.');
                }
                $pdo->prepare(
                    'INSERT INTO employee_advances (employee_id, amount, advance_date, status, notes, monthly_deduction, remaining, adv_type)
                     VALUES (?, ?, ?, "pending", ?, ?, ?, ?)'
                )->execute([$empId, $amount, $date, $notes ?: null, $monthlyDeduction, $amount, $advType]);

                emp_ledger_add(
                    $empId,
                    'debit',
                    $amount,
                    $date,
                    'advance',
                    'سلفة قديمة اتسجّلت من تاب السلف',
                    null,
                    'advance',
                    (int) $pdo->lastInsertId(),
                    $uid,
                    $pdo
                );
                $flash = 'تم تسجيل السلفة بنجاح.'; $tab='advances';
            }
            elseif ($action === 'add_opening_balance') {
                if (!is_owner()) {
                    throw new RuntimeException('غير مصرّح لك بتسجيل رصيد افتتاحي.');
                }
                $empId = (int)($_POST['employee_id'] ?? 0);
                $direction = trim((string)($_POST['direction'] ?? ''));
                $amount = (float)($_POST['amount'] ?? 0);
                $date = trim((string)($_POST['date'] ?? date('Y-m-d')));
                $description = trim((string)($_POST['description'] ?? ''));

                if ($empId <= 0) {
                    throw new RuntimeException('اختر الموظف.');
                }
                if ($amount <= 0.00001) {
                    throw new RuntimeException('المبلغ يجب أن يكون أكبر من صفر.');
                }
                if ($direction !== 'debit' && $direction !== 'credit') {
                    throw new RuntimeException('الاتجاه يجب أن يكون «عليه» (debit) أو «له» (credit).');
                }
                if (!strtotime($date)) {
                    throw new RuntimeException('التاريخ غير صالح.');
                }
                if ($description === '') {
                    throw new RuntimeException('البيان مطلوب.');
                }

                $chkEmp = $pdo->prepare('SELECT id FROM employees WHERE id = ? AND is_active = 1');
                $chkEmp->execute([$empId]);
                if (!$chkEmp->fetch()) {
                    throw new RuntimeException('الموظف غير موجود أو غير نشط.');
                }

                emp_ledger_add(
                    $empId,
                    $direction,
                    $amount,
                    $date,
                    'opening',
                    $description,
                    null,
                    null,
                    null,
                    $uid,
                    $pdo
                );

                $flash = 'تم تسجيل الرصيد الافتتاحي بنجاح.'; $tab='advances';
            }
            elseif ($action === 'save_payroll_adjustments') {
                if (!is_owner()) {
                    throw new RuntimeException('غير مصرّح لك بتعديل بيانات الرواتب.');
                }
                $month = trim((string)($_POST['salary_month'] ?? date('Y-m')));
                $date = trim((string)($_POST['date'] ?? date('Y-m-d')));
                if (!strtotime($date)) {
                    $date = date('Y-m-d');
                }
                $bonusesPost    = (array)($_POST['bonuses'] ?? []);
                $monthlyAdvPost = (array)($_POST['monthly_adv'] ?? []);
                $standingAdvPost= (array)($_POST['standing_adv'] ?? []);

                $pdo->beginTransaction();
                try {
                    apply_payroll_financial_adjustments($pdo, $month, $date, $bonusesPost, $monthlyAdvPost, $standingAdvPost);
                    $pdo->commit();
                    $flash = 'تم حفظ تعديلات المكافآت والسلف بنجاح.';
                    $flashType = 'success';
                } catch (Throwable $ex) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $ex;
                }
                $tab = 'salaries';
            }
            elseif ($action === 'pay_salary') {
                $empId = (int)($_POST['employee_id'] ?? 0);
                $month = trim((string)($_POST['salary_month'] ?? date('Y-m')));
                $from  = trim((string)($_POST['period_from'] ?? '')) ?: null;
                $to    = trim((string)($_POST['period_to'] ?? '')) ?: null;
                $treasuryId = (int)($_POST['treasury_id'] ?? 0);
                $date = trim((string)($_POST['date'] ?? date('Y-m-d')));
                if (!strtotime($date)) {
                    $date = date('Y-m-d');
                }
                $leaveDaysOverride = null;
                if (isset($_POST['leave_days']) && $_POST['leave_days'] !== '') {
                    if (!is_owner()) {
                        throw new RuntimeException('غير مصرّح لك بتعديل أيام الإجازة.');
                    }
                    $leaveDaysOverride = (int) $_POST['leave_days'];
                }
                $dueDaysOverride = null;
                if (isset($_POST['due_days']) && $_POST['due_days'] !== '') {
                    if (!is_owner()) {
                        throw new RuntimeException('غير مصرّح لك بتعديل أيام الاستحقاق.');
                    }
                    $dueDaysOverride = (float) $_POST['due_days'];
                }
                pay_salary($empId, $month, $treasuryId, $date, $uid, $from, $to, $leaveDaysOverride, $dueDaysOverride);
                notify_salaries_paid([$empId], $month);
                $flash = 'تم صرف المرتب وخصم المستحقات.'; $tab='salaries';
            }
            elseif ($action === 'pay_salary_batch') {
                $empIds = $_POST['employee_ids'] ?? [];
                if (!is_array($empIds) || empty($empIds)) {
                    throw new RuntimeException('اختر موظفاً واحداً على الأقل.');
                }
                $month = trim((string)($_POST['salary_month'] ?? ''));
                if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
                    throw new RuntimeException('اختر شهراً صحيحاً.');
                }
                $from  = trim((string)($_POST['period_from'] ?? '')) ?: null;
                $to    = trim((string)($_POST['period_to'] ?? '')) ?: null;
                $treasuryId = (int)($_POST['treasury_id'] ?? 0);
                if ($treasuryId <= 0) {
                    throw new RuntimeException('اختر خزينة الصرف.');
                }
                $date = trim((string)($_POST['date'] ?? ''));
                if (!strtotime($date)) {
                    $date = date('Y-m-d');
                }

                $leaveDaysArr    = (array)($_POST['leave_days'] ?? []);
                $dueDaysArr      = (array)($_POST['due_days'] ?? []);
                $bonusesPost     = (array)($_POST['bonuses'] ?? []);
                $monthlyAdvPost  = (array)($_POST['monthly_adv'] ?? []);
                $standingAdvPost = (array)($_POST['standing_adv'] ?? []);

                // إذا كان المستخدم هو الأونر، نقوم بحفظ أي تعديلات مالية على المكافآت والسلف في معاملة منفصلة ومغلقة قبل بدء الصرف
                if (is_owner()) {
                    $pdo->beginTransaction();
                    try {
                        apply_payroll_financial_adjustments($pdo, $month, $date, $bonusesPost, $monthlyAdvPost, $standingAdvPost);
                        $pdo->commit();
                    } catch (Throwable $ex) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        throw $ex;
                    }
                }

                $empNamesStmt = $pdo->query('SELECT id, full_name FROM employees');
                $empNames = $empNamesStmt->fetchAll(PDO::FETCH_KEY_PAIR);

                $paidCount = 0;
                $paidEmpIds = [];
                $fails = [];
                foreach ($empIds as $rawId) {
                    $empId = (int) $rawId;
                    if ($empId <= 0) continue;
                    $name = $empNames[$empId] ?? ('موظف #' . $empId);
                    $leaveOverride = null;
                    if (isset($leaveDaysArr[$empId]) && $leaveDaysArr[$empId] !== '') {
                        if (!is_owner()) {
                            throw new RuntimeException('غير مصرّح لك بتعديل أيام الإجازة.');
                        }
                        $leaveOverride = (int) $leaveDaysArr[$empId];
                    }
                    $dueOverride = null;
                    if (isset($dueDaysArr[$empId]) && $dueDaysArr[$empId] !== '') {
                        if (!is_owner()) {
                            throw new RuntimeException('غير مصرّح لك بتعديل أيام الاستحقاق.');
                        }
                        $dueOverride = (float) $dueDaysArr[$empId];
                    }
                    try {
                        pay_salary($empId, $month, $treasuryId, $date, $uid, $from, $to, $leaveOverride, $dueOverride);
                        $paidCount++;
                        $paidEmpIds[] = $empId;
                    } catch (Throwable $ex) {
                        $fails[] = $name . ' (' . $ex->getMessage() . ')';
                    }
                }

                if (!empty($paidEmpIds)) {
                    notify_salaries_paid($paidEmpIds, $month);
                }

                if ($paidCount > 0 && empty($fails)) {
                    $flash = "تم صرف مرتبات $paidCount موظف بنجاح لشهر $month.";
                    $flashType = 'success';
                } elseif ($paidCount > 0 && !empty($fails)) {
                    $flash = "تم صرف $paidCount مرتب بنجاح. وتعذر صرف " . count($fails) . " مرتب: " . implode(' · ', $fails);
                    $flashType = 'error';
                } else {
                    $flash = "لم يتم صرف أي مرتب. تعذر الصرف لـ: " . implode(' · ', $fails);
                    $flashType = 'error';
                }
                $tab = 'salaries';
            }
            elseif ($action === 'approve_request') {
                approve_attendance_request((int)($_POST['request_id'] ?? 0), $uid);
                $flash = 'تم اعتماد الطلب وتطبيقه على الحضور.'; $tab='requests';
            }
            elseif ($action === 'update_request_time') {
                if (!can_edit_attendance_time()) {
                    throw new RuntimeException('غير مصرّح لك بتعديل أوقات طلبات التصحيح.');
                }
                $rid = (int)($_POST['request_id'] ?? 0);
                $in  = trim($_POST['requested_in'] ?? '');
                $out = trim($_POST['requested_out'] ?? '');
                if ($in === '' && $out === '') {
                    throw new RuntimeException('أدخل وقت الدخول أو الخروج.');
                }
                $chk = $pdo->prepare('SELECT id FROM attendance_requests WHERE id = ? AND status = "pending"');
                $chk->execute([$rid]);
                if (!$chk->fetch()) {
                    throw new RuntimeException('الطلب غير متاح للتعديل.');
                }
                $pdo->prepare('UPDATE attendance_requests SET requested_in = ?, requested_out = ? WHERE id = ? AND status = "pending"')
                    ->execute([$in !== '' ? $in : null, $out !== '' ? $out : null, $rid]);
                $flash = 'تم تعديل أوقات الطلب بنجاح.'; $tab='requests';
            }
            elseif ($action === 'reject_request') {
                $rid = (int)($_POST['request_id'] ?? 0);
                $pdo->prepare('UPDATE attendance_requests SET status="rejected", reviewed_by=?, reviewed_at=NOW() WHERE id=? AND status="pending"')
                    ->execute([$uid,$rid]);
                $flash = 'تم رفض الطلب.'; $tab='requests';
            }
            elseif ($action === 'delete_attendance') {
                delete_attendance((int)($_POST['attendance_id'] ?? 0));
                $flash = 'تم مسح سجل الحضور لليوم ده. يقدر الموظف يسجّل من جديد.'; $tab='attendance';
            }
        } catch (Throwable $ex) {
            $flash = 'خطأ: ' . $ex->getMessage(); $flashType='error';
        }
    }
}
    // PRG: بعد أي POST نعمل تحويل لصفحة GET عشان الـ Refresh ما يعيدش الإرسال (منع التكرار) مع الحفاظ على الفلاتر
    // بنعمل دمج للـ query string الحالية مع $tab اللي المعالج حددها عشان نحافظ على الفلاتر (from/to) ونروح للتاب الصح لو المعالج غيّره
    $_SESSION['flash'] = $flash;
    $_SESSION['flash_type'] = $flashType;
    $hrQuery = [];
    parse_str(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY) ?? '', $hrQuery);
    $hrQuery['tab'] = $tab;
    header('Location: hr.php?' . http_build_query($hrQuery));
    exit;
}

// نقرأ رسالة الفلاش من السيشن (بعد التحويل)
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    $flashType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash'], $_SESSION['flash_type']);
}

// بيانات التبويبات المشروطة بالتاب النشط
$empList = [];
if ($tab === 'employees' || $tab === 'salaries') {
    $empList = $pdo->query(
        "SELECT e.*, b.name AS branch_name,
                COALESCE((SELECT SUM(COALESCE(remaining, amount)) FROM employee_advances WHERE employee_id=e.id AND status='pending'),0) AS pending_advance
         FROM employees e
         LEFT JOIN branches b ON b.id=e.branch_id WHERE e.is_active=1 ORDER BY e.full_name"
    )->fetchAll();
}

$advances = [];
if ($tab === 'advances') {
    $advances = $pdo->query(
        "SELECT a.*, e.full_name FROM employee_advances a JOIN employees e ON e.id=a.employee_id ORDER BY a.id DESC LIMIT 100"
    )->fetchAll();
}

$bonuses = [];
if ($tab === 'bonuses') {
    $bonuses = $pdo->query(
        "SELECT b.*, e.full_name FROM employee_bonuses b JOIN employees e ON e.id=b.employee_id ORDER BY b.id DESC LIMIT 100"
    )->fetchAll();
}

$penalties = [];
if ($tab === 'penalties') {
    // قراءة عتبة التسامح لاستخدامها في فلترة SQL المسبقة وفي نداء shift_variance_status()
    $tolerance = custody_tolerance();

    // فلترة صفوف الورديات في الـ SQL قبل LIMIT 100 بنفس شرط العتبة بالضبط:
    // ABS(s.difference) - ? > 0.0001
    // ⚠️ الشرط ده مطابق رياضياً لـ shift_variance_status() — والاتنين بياخدوا العتبة من custody_tolerance().
    // أي تعديل في معادلة الدالة لازم يتعمل في السطر ده كمان.
    $penStmt = $pdo->prepare(
        "SELECT p.penalty_date AS deduction_date,
                e.full_name,
                'penalty' AS source_type,
                b.name AS branch_name,
                p.amount,
                0.0 AS difference,
                p.reason,
                p.status,
                NULL AS shift_name,
                NULL AS shift_id,
                p.id AS sort_id
           FROM employee_penalties p
           JOIN employees e ON e.id = p.employee_id
           LEFT JOIN branches b ON b.id = e.branch_id

          UNION ALL

         SELECT DATE(cd.created_at) AS deduction_date,
                e.full_name,
                'custody_deficit' AS source_type,
                b.name AS branch_name,
                cd.amount,
                0.0 AS difference,
                COALESCE(cd.notes, 'عجز تسوية عهدة') AS reason,
                cd.status,
                NULL AS shift_name,
                NULL AS shift_id,
                cd.id AS sort_id
           FROM custody_deficits cd
           LEFT JOIN employees e ON e.id = cd.employee_id
           LEFT JOIN branches b ON b.id = COALESCE(cd.branch_id, e.branch_id)

          UNION ALL

         SELECT s.business_date AS deduction_date,
                COALESCE(e.full_name, u.full_name, '—') AS full_name,
                'shift' AS source_type,
                b.name AS branch_name,
                ABS(s.difference) AS amount,
                s.difference AS difference,
                NULL AS reason,
                s.status,
                s.shift_name,
                s.id AS shift_id,
                s.id AS sort_id
           FROM shifts s
           LEFT JOIN branches b ON b.id = s.branch_id
           LEFT JOIN users u ON u.id = s.user_id
           LEFT JOIN employees e ON e.user_id = u.id
          WHERE s.difference <> 0
            AND ABS(s.difference) - ? > 0.0001

          ORDER BY deduction_date DESC, sort_id DESC
          LIMIT 100"
    );
    $penStmt->execute([$tolerance]);
    $penaltiesRaw = $penStmt->fetchAll();

    foreach ($penaltiesRaw as $r) {
        if ($r['source_type'] === 'penalty') {
            $penalties[] = [
                'deduction_date' => (string) $r['deduction_date'],
                'full_name'      => (string) $r['full_name'],
                'deduction_type' => 'جزاء',
                'branch_name'    => $r['branch_name'] ?? '—',
                'amount'         => (float) $r['amount'],
                'tolerance'      => '—',
                'exceeded'       => '—',
                'reason'         => $r['reason'] ?? '—',
                'status'         => (string) $r['status'],
            ];
        } elseif ($r['source_type'] === 'custody_deficit') {
            // عجز العهدة اتسجل وقت إقفال العهدة بالعتبة اللي كانت موجودة ساعتها
            // فبنعرض العتبة '—' عشان منعرضش عتبة النهاردة مع سجل قديم، والمتجاوز = المبلغ نفسه
            $amount = (float) $r['amount'];
            $penalties[] = [
                'deduction_date' => (string) $r['deduction_date'],
                'full_name'      => (string) $r['full_name'],
                'deduction_type' => 'عجز عهدة',
                'branch_name'    => $r['branch_name'] ?? '—',
                'amount'         => $amount,
                'tolerance'      => '—',
                'exceeded'       => number_format($amount, 2),
                'reason'         => $r['reason'] ?? 'عجز تسوية عهدة',
                'status'         => (string) $r['status'],
            ];
        } elseif ($r['source_type'] === 'shift') {
            // فحص فرق الوردية بالعتبة الموحدة
            $diff = (float) $r['difference'];
            $vs = shift_variance_status($diff, $tolerance);

            // اللي داخل العتبة مايظهرش في تاب الجزاءات خالص
            if (!$vs['over_limit']) {
                continue;
            }

            $dedType = $vs['kind'] === 'shortage' ? 'عجز وردية' : 'زيادة وردية';
            $shiftDisplay = ($r['shift_name'] !== null && $r['shift_name'] !== '') ? 'وردية ' . $r['shift_name'] : 'وردية #' . $r['shift_id'];
            $branchDisplay = $r['branch_name'] ?: 'بدون فرع';
            $reason = "{$branchDisplay} · {$r['deduction_date']} · {$shiftDisplay} (إجمالي " . ($vs['kind'] === 'shortage' ? 'عجز' : 'زيادة') . ' ' . number_format($vs['amount'], 2) . ' ج)';

            $penalties[] = [
                'deduction_date' => (string) $r['deduction_date'],
                'full_name'      => (string) $r['full_name'],
                'deduction_type' => $dedType,
                'branch_name'    => $r['branch_name'] ?? '—',
                'amount'         => $vs['exceeded'], // المبلغ المعروض لصفوف الورديات = المتجاوز
                'tolerance'      => number_format($vs['tolerance'], 2),
                'exceeded'       => number_format($vs['exceeded'], 2),
                'reason'         => $reason,
                'status'         => (string) $r['status'],
            ];
        }
    }
}
$leaves = [];
if ($tab === 'leaves') {
    $leaves = $pdo->query(
        "SELECT l.*, e.full_name FROM employee_leaves l JOIN employees e ON e.id=l.employee_id ORDER BY l.id DESC LIMIT 100"
    )->fetchAll();
}
$salaries = [];
if ($tab === 'salaries') {
    $salaries = $pdo->query(
        "SELECT s.*, e.full_name FROM salaries s JOIN employees e ON e.id=s.employee_id ORDER BY s.id DESC LIMIT 100"
    )->fetchAll();
}
$requests = [];
$pendingReqCount = 0;
if ($tab === 'requests') {
    $requests = $pdo->query(
        "SELECT r.*, e.full_name FROM attendance_requests r JOIN employees e ON e.id=r.employee_id ORDER BY r.status='pending' DESC, r.id DESC LIMIT 100"
    )->fetchAll();
    foreach ($requests as $r) {
        if ($r['status'] === 'pending') {
            $pendingReqCount++;
        }
    }
} elseif (isset($allowedTabs['requests'])) {
    // لو المستخدم معاه صلاحية طلبات التصحيح وهو في تاب تاني، نحسب العدّاد للشريط
    $pendingReqCount = (int) $pdo->query("SELECT COUNT(*) FROM attendance_requests WHERE status='pending'")->fetchColumn();
}

// بيانات الحضور للشهر المختار (تبويب الحضور)
$attMonth = $_GET['month'] ?? date('Y-m');
$attRows = [];
if ($tab === 'attendance') {
    $st = $pdo->prepare(
        "SELECT a.*, e.full_name, e.expected_in, e.expected_out
         FROM attendance a JOIN employees e ON e.id=a.employee_id
         WHERE DATE_FORMAT(a.att_date,'%Y-%m') = ?
         ORDER BY a.att_date DESC, e.full_name"
    );
    $st->execute([$attMonth]);
    $attRows = $st->fetchAll();
}

// بيانات الرواتب وحساب المستحقات للفترة (تبويب الرواتب)
// الفترة الافتراضية: من ٢٦ الشهر السابق إلى ٢٥ الشهر الحالي
$defaultSalFrom = date('Y-m-26', strtotime('first day of last month'));
$defaultSalTo   = date('Y-m-25');
$salFrom = $_GET['from'] ?? $defaultSalFrom;
$salTo   = $_GET['to']   ?? $defaultSalTo;
if (!strtotime($salFrom)) $salFrom = $defaultSalFrom;
if (!strtotime($salTo))   $salTo   = $defaultSalTo;
$salMonth = $_GET['month'] ?? date('Y-m', strtotime($salTo));
if (!preg_match('/^\d{4}-\d{2}$/', $salMonth)) $salMonth = date('Y-m');

$payrollData = [];
if ($tab === 'salaries') {
    // 1) ساعات العمل المجمّعة لكل موظف في الفترة المحددة (استعلام واحد)
    $stAtt = $pdo->prepare(
        "SELECT employee_id, COALESCE(SUM(work_hours), 0) AS total_hours
         FROM attendance
         WHERE att_date BETWEEN ? AND ?
         GROUP BY employee_id"
    );
    $stAtt->execute([$salFrom, $salTo]);
    $empHours = $stAtt->fetchAll(PDO::FETCH_KEY_PAIR);

    // 2) المكافآت المعلّقة مجمّعة لكل موظف مع العدد والـ id الفردي (استعلام واحد)
    $bonPendingStmt = $pdo->query(
        "SELECT employee_id,
                COUNT(*) AS cnt,
                MIN(id) AS single_id,
                COALESCE(SUM(amount), 0) AS total_amount
         FROM employee_bonuses
         WHERE status = 'pending'
         GROUP BY employee_id"
    );
    $bonPendingMap = [];
    foreach ($bonPendingStmt->fetchAll(PDO::FETCH_ASSOC) as $bRow) {
        $bonPendingMap[(int)$bRow['employee_id']] = [
            'cnt'          => (int)$bRow['cnt'],
            'single_id'    => $bRow['single_id'] !== null ? (int)$bRow['single_id'] : null,
            'total_amount' => (float)$bRow['total_amount'],
        ];
    }

    // 3) السلف المعلّقة مجمّعة لكل موظف بالأقساط الشهرية مع سقف رصيد السجل الموجب (استعلامان مجمّعان بدون أي استعلام في حلقة)
    // ⚠️ المعادلة مكررة بين هنا و finance.php (compute_salary و pay_salary) — لو غيّرت هنا، غيّر في التلاتة.
    $advRawMap = $pdo->query(
        "SELECT employee_id, COALESCE(SUM(
            CASE
                WHEN monthly_deduction > 0 THEN LEAST(monthly_deduction, COALESCE(remaining, amount))
                ELSE COALESCE(remaining, amount)
            END
        ), 0)
         FROM employee_advances WHERE status='pending'
         GROUP BY employee_id"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    $allEmpIds = !empty($empList) ? array_column($empList, 'id') : [];
    $ledgerBalancesMap = emp_ledger_balances($allEmpIds, $pdo);

    $advMap = [];
    foreach ($advRawMap as $eId => $rawAdv) {
        $lBal = $ledgerBalancesMap[(int)$eId] ?? 0.0;
        if ($lBal <= 0.0) {
            $advMap[(int)$eId] = 0.0;
        } else {
            $advMap[(int)$eId] = min((float)$rawAdv, $lBal);
        }
    }

    // 4) الجزاءات المعلّقة مجمّعة لكل موظف (استعلام واحد)
    $penMap = $pdo->query(
        "SELECT employee_id, COALESCE(SUM(amount), 0)
         FROM employee_penalties WHERE status='pending'
         GROUP BY employee_id"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    // 5) عجز العهدة المعلّق مجمّع لكل موظف (استعلام واحد)
    $defMap = $pdo->query(
        "SELECT employee_id, COALESCE(SUM(amount), 0)
         FROM custody_deficits WHERE status='pending'
         GROUP BY employee_id"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    // 6) المرتبات المصروفة للشهر المختار (استعلام واحد لمعرفة حالة الصرف)
    $stPaid = $pdo->prepare("SELECT employee_id, id FROM salaries WHERE salary_month = ?");
    $stPaid->execute([$salMonth]);
    $paidMonthMap = $stPaid->fetchAll(PDO::FETCH_KEY_PAIR);

    // 7) أرصدة السلف المعلّقة مقسمة حسب نوع السلفة مع العدد والـ id (استعلام واحد مجمّع)
    $advBucketsStmt = $pdo->query(
        "SELECT employee_id,
                SUM(CASE WHEN adv_type = 'monthly' THEN 1 ELSE 0 END) AS monthly_cnt,
                MIN(CASE WHEN adv_type = 'monthly' THEN id ELSE NULL END) AS monthly_single_id,
                COALESCE(SUM(CASE WHEN adv_type = 'monthly' THEN COALESCE(remaining, amount) ELSE 0 END), 0) AS monthly_adv,
                SUM(CASE WHEN adv_type = 'standing' THEN 1 ELSE 0 END) AS standing_cnt,
                MIN(CASE WHEN adv_type = 'standing' THEN id ELSE NULL END) AS standing_single_id,
                COALESCE(SUM(CASE WHEN adv_type = 'standing' THEN COALESCE(remaining, amount) ELSE 0 END), 0) AS standing_adv
         FROM employee_advances
         WHERE status = 'pending'
         GROUP BY employee_id"
    );
    $advBucketsMap = [];
    foreach ($advBucketsStmt->fetchAll(PDO::FETCH_ASSOC) as $bRow) {
        $advBucketsMap[(int)$bRow['employee_id']] = [
            'monthly_cnt'        => (int)$bRow['monthly_cnt'],
            'monthly_single_id'  => $bRow['monthly_single_id'] !== null ? (int)$bRow['monthly_single_id'] : null,
            'monthly'            => (float)$bRow['monthly_adv'],
            'standing_cnt'       => (int)$bRow['standing_cnt'],
            'standing_single_id' => $bRow['standing_single_id'] !== null ? (int)$bRow['standing_single_id'] : null,
            'standing'           => (float)$bRow['standing_adv'],
        ];
    }

    // الحسابات في PHP بدون أي استعلام داخل الحلقة
    foreach ($empList as $e) {
        $empId           = (int) $e['id'];
        $salaryMode      = $e['salary_mode'] ?? 'hours';
        $isFixed         = ($salaryMode === 'fixed');
        $base            = (float) $e['base_salary'];
        $expectedHours   = isset($e['expected_monthly_hours']) && $e['expected_monthly_hours'] !== null && $e['expected_monthly_hours'] !== '' ? (float) $e['expected_monthly_hours'] : null;
        $empLedgerBal    = $ledgerBalancesMap[$empId] ?? 0.0;

        if ($isFixed) {
            $hours       = 0.0;
            $noExpected  = false;
            $workDays    = 0.0;
            $leaveDays   = 0;
            $dueDaysCalc = 0.0;
            $dayRate     = $base > 0 ? ($base / 30.0) : 0.0;
            $gross       = $base;
        } else {
            $hours       = (float) ($empHours[$empId] ?? 0);
            $salDays     = salary_days_from_hours($hours, $expectedHours);
            $noExpected  = !empty($salDays['no_expected']);
            $workDays    = $salDays['work_days'];
            $leaveDays   = $salDays['leave_days'];
            $dueDaysCalc = $salDays['due_days'];
            $dayRate     = $base > 0 ? ($base / 30.0) : 0.0;
            $gross       = $noExpected ? 0.0 : ($dayRate * $dueDaysCalc);
        }

        $bonInfo         = $bonPendingMap[$empId] ?? ['cnt' => 0, 'single_id' => null, 'total_amount' => 0.0];
        $bon             = (float) $bonInfo['total_amount'];
        $bonCnt          = (int) $bonInfo['cnt'];
        $adv             = (float) ($advMap[$empId] ?? 0);
        $advBucket       = $advBucketsMap[$empId] ?? ['monthly_cnt' => 0, 'monthly_single_id' => null, 'monthly' => 0.0, 'standing_cnt' => 0, 'standing_single_id' => null, 'standing' => 0.0];
        $monthlyAdvOwed  = (float) $advBucket['monthly'];
        $monthlyAdvCnt   = (int) $advBucket['monthly_cnt'];
        $standingAdvOwed = (float) $advBucket['standing'];
        $standingAdvCnt  = (int) $advBucket['standing_cnt'];
        $pen             = (float) ($penMap[$empId] ?? 0);
        $def             = (float) ($defMap[$empId] ?? 0);
        $totalDeductions = $adv + $pen + $def;
        $net             = $noExpected ? 0.0 : ($gross + $bon - $totalDeductions);
        $isPaid          = isset($paidMonthMap[$empId]);

        $payrollData[] = [
            'id'                     => $empId,
            'full_name'              => $e['full_name'],
            'salary_mode'            => $salaryMode,
            'is_fixed'               => $isFixed,
            'branch_name'            => $e['branch_name'] ?? '',
            'base_salary'            => $base,
            'expected_monthly_hours' => $expectedHours,
            'no_expected'            => $noExpected,
            'day_hours'              => $salDays['day_hours'] ?? 0.0,
            'work_hours'             => $hours,
            'work_days'              => $workDays,
            'leave_days'             => $leaveDays,
            'due_days_calc'          => $dueDaysCalc,
            'day_rate'               => $dayRate,
            'gross_salary'           => $gross,
            'bonuses'                => $bon,
            'bonus_cnt'              => $bonCnt,
            'total_bonuses'          => $bon,
            'advances'               => $adv,
            'ledger_balance'         => $empLedgerBal,
            'monthly_adv_owed'       => $monthlyAdvOwed,
            'monthly_adv_cnt'        => $monthlyAdvCnt,
            'standing_adv_owed'      => $standingAdvOwed,
            'standing_adv_cnt'       => $standingAdvCnt,
            'penalties'              => $pen,
            'deficits'               => $def,
            'total_deductions'       => $totalDeductions,
            'net_salary'             => $net,
            'is_paid'                => $isPaid,
        ];
    }
}

$page_title    = 'الموارد البشرية';
$active_module = 'hr';
require_once __DIR__ . '/includes/header.php';

$leaveLabels = ['annual'=>'سنوية','sick'=>'مرضية','unpaid'=>'بدون راتب','other'=>'أخرى'];
$statusBadge = function($st) {
    $m = ['pending'=>['معلّق','var(--warn)','var(--warn-soft)'],'deducted'=>['مخصوم','var(--pos)','var(--pos-soft)'],
          'approved'=>['معتمد','var(--pos)','var(--pos-soft)'],'rejected'=>['مرفوض','var(--neg)','var(--neg-soft)'],
          'paid'=>['مدفوع','var(--pos)','var(--pos-soft)'],'draft'=>['مسودة','#666','#eee'],
          'recovered'=>['مسترد','var(--pos)','var(--pos-soft)'],
          'submitted'=>['مستلم','var(--accent)','var(--accent-soft)'],
          'resubmitted'=>['معاد إرسال','var(--warn)','var(--warn-soft)']];
    return $m[$st] ?? [$st ?: '—','#666','#eee'];
};
?>

<style>
    .flash { padding:12px 16px; border-radius:10px; margin-bottom:18px; font-size:14px; }
    .flash.success { background:var(--pos-soft); color:var(--pos); }
    .flash.error   { background:var(--neg-soft); color:var(--neg); }
    .flash.warning { background:var(--warn-soft); color:var(--warn); }
    .card { background:#fff; border-radius:14px; padding:20px; box-shadow:0 2px 10px rgba(124,111,247,0.06); margin-bottom:18px; }
    .section-title { font-size:16px; color:var(--ink-900); font-weight:600; margin-bottom:14px; }
    .tabs { display:flex; gap:6px; margin-bottom:20px; flex-wrap:wrap; }
    .tab { padding:9px 16px; border-radius:10px; text-decoration:none; color:var(--accent-hover); background:#fff;
        font-size:14px; font-weight:500; position:relative; }
    .tab.active { background:var(--accent); color:#fff; }
    .tab:not(.active):hover { background:var(--bg); }
    .tab .count { background:var(--neg); color:#fff; border-radius:20px; padding:1px 7px; font-size:11px; margin-right:4px; }
    .toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; }
    .btn { background:var(--accent); color:#fff; border:none; padding:11px 20px; border-radius:10px;
        font-size:14px; font-family:inherit; cursor:pointer; display:inline-flex; align-items:center; gap:7px; }
    .btn:hover { background:var(--accent-hover); }
    .btn-sm { padding:6px 12px; font-size:13px; }
    .btn-approve { background:var(--pos); } .btn-reject { background:var(--neg); }
    .btn-ghost { background:var(--bg); color:var(--accent-hover); }
    .btn-ghost:hover { background:var(--line); }
    .btn-cancel { background:var(--line-soft); color:#666; }
    .btn-cancel:hover { background:var(--line); }
    table { width:100%; border-collapse:collapse; }
    th, td { padding:11px 13px; text-align:right; font-size:14px; border-bottom:1px solid var(--line); }
    th { color:var(--ink-500); font-weight:600; font-size:13px; }
    td { color:var(--ink-800); }
    .badge { display:inline-block; padding:3px 11px; border-radius:20px; font-size:12px; font-weight:500; }
    .empty { text-align:center; color:#bbb; padding:30px; font-size:14px; }
    .modal-bg { display:none; position:fixed; inset:0; background:rgba(30,27,75,0.45);
        z-index:200; align-items:flex-start; justify-content:center; padding:30px 16px; overflow-y:auto; }
    .modal-bg.open { display:flex; }
    .modal { background:#fff; border-radius:16px; width:100%; max-width:440px; padding:26px; }
    .modal h3 { color:var(--ink-900); margin-bottom:16px; font-size:18px; }
    .form-row { margin-bottom:14px; }
    .form-row label { display:block; font-size:13px; color:var(--ink-700); margin-bottom:6px; font-weight:500; }
    .form-row input, .form-row select { width:100%; padding:10px 12px; border:1.5px solid var(--line);
        border-radius:9px; font-size:14px; font-family:inherit; background:var(--line-soft); }
    .modal-actions { display:flex; gap:10px; margin-top:20px; }
    .btn-cancel { background:var(--line-soft); color:#666; }
    .payroll-num { width:95px; text-align:center; padding:4px 6px; border:1.5px solid var(--line);
        border-radius:6px; font-family:inherit; font-size:13px; -moz-appearance:textfield; appearance:textfield; }
    .payroll-num::-webkit-outer-spin-button,
    .payroll-num::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
    .payroll-num.is-days { width:55px; }
</style>

<?php if ($flash): ?>
    <div class="flash <?= e($flashType) ?>"><?= e($flash) ?></div>
<?php endif; ?>

<div class="tabs">
    <?php foreach ($allowedTabs as $tabKey => $tabLabel): ?>
        <a href="?tab=<?= e($tabKey) ?>" class="tab <?= $tab === $tabKey ? 'active' : '' ?>">
            <?= e($tabLabel) ?><?php if ($tabKey === 'requests' && !empty($pendingReqCount)): ?><span class="count"><?= (int)$pendingReqCount ?></span><?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($tab === 'employees'): ?>
    <div class="card">
        <div class="section-title">الموظفون (<?= count($empList) ?>) — التعديل من التعريفات</div>
        <?php if (empty($empList)): ?>
            <div class="empty">لا يوجد موظفون. أضفهم من التعريفات.</div>
        <?php else: ?>
        <table>
            <thead><tr><th>الاسم</th><th>الفرع</th><th>الوظيفة</th><th>الراتب</th><th>السلفة المعلّقة</th><th>الحضور المتوقع</th><th>الانصراف</th></tr></thead>
            <tbody>
            <?php foreach ($empList as $e2): ?>
                <tr>
                    <td style="font-weight:500;"><?= e($e2['full_name']) ?></td>
                    <td><?= e($e2['branch_name'] ?? '—') ?></td>
                    <td><?= e($e2['job_title'] ?? '—') ?></td>
                    <td><?= number_format((float)$e2['base_salary'],2) ?></td>
                    <td><?= (float)$e2['pending_advance'] > 0
                            ? '<span style="color:var(--neg);font-weight:600;">'.number_format((float)$e2['pending_advance'],2).'</span>'
                            : '<span style="color:var(--ink-500);">0.00</span>' ?></td>
                    <td><?= e($e2['expected_in'] ? substr($e2['expected_in'],0,5) : '—') ?></td>
                    <td><?= e($e2['expected_out'] ? substr($e2['expected_out'],0,5) : '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'advances'): ?>
    <?php
    $monthlyAdvances = [];
    $standingAdvances = [];
    foreach ($advances as $a) {
        if (($a['adv_type'] ?? 'monthly') === 'standing') {
            $standingAdvances[] = $a;
        } else {
            $monthlyAdvances[] = $a;
        }
    }
    ?>
    <div class="toolbar">
        <span style="color:var(--ink-500);font-size:14px;"><?= count($advances) ?> سلفة (<?= count($monthlyAdvances) ?> سلف الشهر · <?= count($standingAdvances) ?> مستديمة)</span>
        <div style="display:flex;gap:8px;align-items:center;">
            <?php if (is_owner()): ?>
                <a href="advances_import.php" class="btn btn-ghost"><i class="ti ti-file-spreadsheet"></i> استيراد شيت السلف</a>
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('openingBalanceModal').classList.add('open')"><i class="ti ti-wallet"></i> تسجيل رصيد افتتاحي</button>
            <?php endif; ?>
            <button class="btn" onclick="document.getElementById('advanceModal').classList.add('open')"><i class="ti ti-plus"></i> تسجيل سلفة قديمة</button>
        </div>
    </div>

    <!-- جدول سلف الشهر -->
    <div class="card">
        <div class="section-title">سلف الشهر (<?= count($monthlyAdvances) ?>)</div>
        <p style="color:var(--ink-500);font-size:13px;margin-bottom:14px;">سلف الشهر الحالي تُصرف من موديول الخزينة وتُخصم تلقائياً مع أول راتب.</p>
        <?php if (empty($monthlyAdvances)): ?>
            <div class="empty">لا توجد سلف شهرية.</div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table>
            <thead><tr><th>التاريخ</th><th>الموظف</th><th>أصل السلفة</th><th>المتبقي</th><th>القسط الشهري</th><th>الحالة</th><th>ملاحظات</th><th>إجراء</th></tr></thead>
            <tbody>
            <?php foreach ($monthlyAdvances as $a):
                $b = $statusBadge($a['status']);
                $origAmount = (float)$a['amount'];
                $remAmount  = $a['remaining'] !== null ? (float)$a['remaining'] : $origAmount;
                $monthlyDed = (float)($a['monthly_deduction'] ?? 0);
                $isPending  = ($a['status'] === 'pending');
            ?>
                <tr>
                    <td><?= e($a['advance_date']) ?></td>
                    <td style="font-weight:500;"><?= e($a['full_name']) ?></td>
                    <td><?= number_format($origAmount, 2) ?></td>
                    <td style="font-weight:600;"><?= number_format($remAmount, 2) ?></td>
                    <td>
                        <?php if ($monthlyDed > 0): ?>
                            <span style="font-weight:600;color:var(--accent-hover);"><?= number_format($monthlyDed, 2) ?></span>
                        <?php else: ?>
                            <span style="color:var(--ink-500);">بالكامل</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge" style="color:<?=$b[1]?>;background:<?=$b[2]?>;"><?=e($b[0])?></span></td>
                    <td style="font-size:13px;color:var(--ink-500);white-space:pre-line;"><?= e($a['notes'] ?? '—') ?></td>
                    <td>
                        <a href="employee_statement.php?emp=<?= (int)$a['employee_id'] ?>" class="btn btn-sm btn-ghost" title="كشف حساب الموظف"><i class="ti ti-file-invoice"></i> كشف الحساب</a>
                        <?php if ($isPending && $remAmount > 0): ?>
                            <button type="button" class="btn btn-sm btn-ghost btn-edit-adv"
                                    data-id="<?= (int)$a['id'] ?>"
                                    data-name="<?= e($a['full_name']) ?>"
                                    data-amount="<?= number_format($origAmount, 2) ?>"
                                    data-remaining="<?= number_format($remAmount, 2) ?>"
                                    data-deduction="<?= $monthlyDed > 0 ? $monthlyDed : 0 ?>"
                                    title="تعديل القسط الشهري"><i class="ti ti-edit"></i> تعديل القسط</button>
                            <?php if (is_owner()): ?>
                                <form method="post" style="display:inline;margin:0;"
                                      onsubmit="return confirm('تأكيد إلغاء سلفة <?= e(addslashes($a['full_name'])) ?>؟\nالمبلغ المتبقي: <?= number_format($remAmount, 2) ?> ج.م');">
                                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="cancel_advance">
                                    <input type="hidden" name="advance_id" value="<?= (int)$a['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-reject" title="إلغاء السلفة"><i class="ti ti-trash"></i> إلغاء</button>
                                </form>
                            <?php endif; ?>
                        <?php elseif ($isPending && $remAmount <= 0): ?>
                            <span class="badge" style="color:var(--neg);background:var(--neg-soft);">ملغاة</span>
                        <?php else: ?>
                            <span style="color:#ccc;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- جدول السلف المستديمة -->
    <div class="card">
        <div class="section-title">السلف المستديمة (<?= count($standingAdvances) ?>)</div>
        <p style="color:var(--ink-500);font-size:13px;margin-bottom:14px;">السلف المستديمة والمديونيات المرحلة من شهور سابقة، ويمكن تحديد قسط شهري لخصمها تدريجياً أو استيرادها من شيت الإكسيل.</p>
        <?php if (empty($standingAdvances)): ?>
            <div class="empty">لا توجد سلف مستديمة.</div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table>
            <thead><tr><th>التاريخ</th><th>الموظف</th><th>أصل السلفة</th><th>المتبقي</th><th>القسط الشهري</th><th>الحالة</th><th>ملاحظات</th><th>إجراء</th></tr></thead>
            <tbody>
            <?php foreach ($standingAdvances as $a):
                $b = $statusBadge($a['status']);
                $origAmount = (float)$a['amount'];
                $remAmount  = $a['remaining'] !== null ? (float)$a['remaining'] : $origAmount;
                $monthlyDed = (float)($a['monthly_deduction'] ?? 0);
                $isPending  = ($a['status'] === 'pending');
            ?>
                <tr>
                    <td><?= e($a['advance_date']) ?></td>
                    <td style="font-weight:500;"><?= e($a['full_name']) ?></td>
                    <td><?= number_format($origAmount, 2) ?></td>
                    <td style="font-weight:600;"><?= number_format($remAmount, 2) ?></td>
                    <td>
                        <?php if ($monthlyDed > 0): ?>
                            <span style="font-weight:600;color:var(--accent-hover);"><?= number_format($monthlyDed, 2) ?></span>
                        <?php else: ?>
                            <span style="color:var(--ink-500);">بالكامل</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge" style="color:<?=$b[1]?>;background:<?=$b[2]?>;"><?=e($b[0])?></span></td>
                    <td style="font-size:13px;color:var(--ink-500);white-space:pre-line;"><?= e($a['notes'] ?? '—') ?></td>
                    <td>
                        <a href="employee_statement.php?emp=<?= (int)$a['employee_id'] ?>" class="btn btn-sm btn-ghost" title="كشف حساب الموظف"><i class="ti ti-file-invoice"></i> كشف الحساب</a>
                        <?php if ($isPending && $remAmount > 0): ?>
                            <button type="button" class="btn btn-sm btn-ghost btn-edit-adv"
                                    data-id="<?= (int)$a['id'] ?>"
                                    data-name="<?= e($a['full_name']) ?>"
                                    data-amount="<?= number_format($origAmount, 2) ?>"
                                    data-remaining="<?= number_format($remAmount, 2) ?>"
                                    data-deduction="<?= $monthlyDed > 0 ? $monthlyDed : 0 ?>"
                                    title="تعديل القسط الشهري"><i class="ti ti-edit"></i> تعديل القسط</button>
                            <?php if (is_owner()): ?>
                                <form method="post" style="display:inline;margin:0;"
                                      onsubmit="return confirm('تأكيد إلغاء سلفة <?= e(addslashes($a['full_name'])) ?>؟\nالمبلغ المتبقي: <?= number_format($remAmount, 2) ?> ج.م');">
                                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="cancel_advance">
                                    <input type="hidden" name="advance_id" value="<?= (int)$a['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-reject" title="إلغاء السلفة"><i class="ti ti-trash"></i> إلغاء</button>
                                </form>
                            <?php endif; ?>
                        <?php elseif ($isPending && $remAmount <= 0): ?>
                            <span class="badge" style="color:var(--neg);background:var(--neg-soft);">ملغاة</span>
                        <?php else: ?>
                            <span style="color:#ccc;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <p style="color:var(--ink-500);font-size:13px;text-align:center;margin:14px 0 20px;">
        الأرصدة الافتتاحية بتظهر في <a href="employee_statement.php" style="color:var(--accent);text-decoration:none;font-weight:600;"><i class="ti ti-file-invoice"></i> كشف حساب الموظف</a> مش هنا.
    </p>

<?php elseif ($tab === 'bonuses'): ?>
    <div class="toolbar">
        <span style="color:var(--ink-500);font-size:14px;"><?= count($bonuses) ?> مكافأة</span>
        <button class="btn" onclick="document.getElementById('bonusModal').classList.add('open')"><i class="ti ti-plus"></i> إضافة مكافأة</button>
    </div>
    <div class="card">
        <?php if (empty($bonuses)): ?>
            <div class="empty">لا توجد مكافآت.</div>
        <?php else: ?>
        <table>
            <thead><tr><th>التاريخ</th><th>الموظف</th><th>المبلغ</th><th>السبب</th><th>الحالة</th></tr></thead>
            <tbody>
            <?php foreach ($bonuses as $bn): $b=$statusBadge($bn['status']); ?>
                <tr>
                    <td><?= e($bn['bonus_date']) ?></td>
                    <td style="font-weight:500;"><?= e($bn['full_name']) ?></td>
                    <td><?= number_format((float)$bn['amount'],2) ?></td>
                    <td><?= e($bn['reason'] ?? '—') ?></td>
                    <td><span class="badge" style="color:<?=$b[1]?>;background:<?=$b[2]?>;"><?=e($b[0])?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'penalties'): ?>
    <div class="toolbar">
        <span style="color:var(--ink-500);font-size:14px;"><?= count($penalties) ?> استقطاع/جزاء</span>
        <button class="btn" onclick="document.getElementById('penaltyModal').classList.add('open')"><i class="ti ti-plus"></i> إضافة جزاء</button>
    </div>
    <div style="color:var(--ink-500);font-size:13px;margin-bottom:12px;">
        العتبة الحالية <strong><?= number_format($tolerance, 2) ?> ج</strong> — أي عجز أو زيادة أقل من كده مش بيظهر هنا.
        <a href="settings.php" style="color:var(--accent);">غيّرها من الإعدادات</a>
    </div>
    <div class="card">
        <?php if (empty($penalties)): ?>
            <div class="empty">لا توجد استقطاعات أو جزاءات.</div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table>
            <thead><tr><th>التاريخ</th><th>الموظف</th><th>النوع</th><th>الفرع</th><th>المبلغ</th><th>العتبة</th><th>المتجاوز</th><th>السبب</th><th>الحالة</th></tr></thead>
            <tbody>
            <?php foreach ($penalties as $p): $b=$statusBadge($p['status']); ?>
                <?php
                $typeColor = 'var(--ink-800)';
                $typeBg    = 'var(--line-soft)';
                if ($p['deduction_type'] === 'عجز وردية' || $p['deduction_type'] === 'عجز عهدة') {
                    $typeColor = 'var(--neg)';
                    $typeBg    = 'var(--neg-soft)';
                } elseif ($p['deduction_type'] === 'زيادة وردية') {
                    $typeColor = 'var(--pos)';
                    $typeBg    = 'var(--pos-soft)';
                }
                ?>
                <tr>
                    <td><?= e($p['deduction_date']) ?></td>
                    <td style="font-weight:500;"><?= e($p['full_name']) ?></td>
                    <td><span class="badge" style="background:<?= $typeBg ?>;color:<?= $typeColor ?>;"><?= e($p['deduction_type']) ?></span></td>
                    <td><?= e($p['branch_name'] ?? '—') ?></td>
                    <td style="font-weight:600;<?= ($p['deduction_type']==='زيادة وردية') ? 'color:var(--pos);' : 'color:var(--neg);' ?>"><?= number_format((float)$p['amount'],2) ?></td>
                    <td><?= is_numeric($p['tolerance']) ? number_format((float)$p['tolerance'],2) : e((string)$p['tolerance']) ?></td>
                    <td><?= is_numeric($p['exceeded']) ? number_format((float)$p['exceeded'],2) : e((string)$p['exceeded']) ?></td>
                    <td><?= e($p['reason'] ?? '—') ?></td>
                    <td><span class="badge" style="color:<?=$b[1]?>;background:<?=$b[2]?>;"><?=e($b[0])?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'leaves'): ?>
    <div class="toolbar">
        <span style="color:var(--ink-500);font-size:14px;"><?= count($leaves) ?> إجازة</span>
        <button class="btn" onclick="document.getElementById('leaveModal').classList.add('open')"><i class="ti ti-plus"></i> إضافة إجازة</button>
    </div>
    <div class="card">
        <?php if (empty($leaves)): ?>
            <div class="empty">لا توجد إجازات.</div>
        <?php else: ?>
        <table>
            <thead><tr><th>الموظف</th><th>من</th><th>إلى</th><th>النوع</th><th>الأيام</th></tr></thead>
            <tbody>
            <?php foreach ($leaves as $l): ?>
                <tr>
                    <td style="font-weight:500;"><?= e($l['full_name']) ?></td>
                    <td><?= e($l['start_date']) ?></td>
                    <td><?= e($l['end_date']) ?></td>
                    <td><?= e($leaveLabels[$l['leave_type']] ?? $l['leave_type']) ?></td>
                    <td><?= (int)$l['days'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'salaries'): ?>
    <!-- فلترة فترة الحضور -->
    <div class="card" style="margin-bottom:18px;">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:14px;">
            <div class="section-title" style="margin-bottom:0;">حساب واستحقاق الرواتب للفترة</div>
            <div style="display:flex;gap:8px;">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('salaryModal').classList.add('open')"><i class="ti ti-cash"></i> صرف فردي</button>
            </div>
        </div>

        <form method="get" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
            <input type="hidden" name="tab" value="salaries">
            <div style="display:flex;align-items:center;gap:6px;">
                <label style="font-size:13px;color:var(--ink-700);font-weight:500;">من تاريخ:</label>
                <input type="date" name="from" value="<?= e($salFrom) ?>" onchange="this.form.submit()"
                       style="padding:7px 10px;border:1.5px solid var(--line);border-radius:8px;font-family:inherit;">
            </div>
            <div style="display:flex;align-items:center;gap:6px;">
                <label style="font-size:13px;color:var(--ink-700);font-weight:500;">إلى تاريخ:</label>
                <input type="date" name="to" value="<?= e($salTo) ?>" onchange="this.form.submit()"
                       style="padding:7px 10px;border:1.5px solid var(--line);border-radius:8px;font-family:inherit;">
            </div>
            <div style="display:flex;align-items:center;gap:6px;">
                <label style="font-size:13px;color:var(--ink-700);font-weight:500;">شهر المرتب:</label>
                <input type="month" name="month" value="<?= e($salMonth) ?>" onchange="this.form.submit()"
                       style="padding:7px 10px;border:1.5px solid var(--line);border-radius:8px;font-family:inherit;">
            </div>
            <button type="submit" class="btn btn-sm btn-ghost"><i class="ti ti-refresh"></i> تحديث</button>
        </form>
    </div>

    <!-- جدول حساب المستحقات للموظفين للفترة المحددة -->
    <div class="card">
        <?php if (date('d', strtotime($salFrom)) !== '26' || date('d', strtotime($salTo)) !== '25'): ?>
            <div class="flash warning" style="margin-bottom:14px;display:flex;align-items:center;gap:8px;">
                <i class="ti ti-alert-triangle"></i>
                <span>تنبيه: الفترة المحددة تختلف عن الفترة المعتادة (من ٢٦ الشهر اللي فات لـ٢٥ الشهر ده).</span>
            </div>
        <?php endif; ?>
        <form method="post" id="batchPayrollForm">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="period_from" value="<?= e($salFrom) ?>">
            <input type="hidden" name="period_to" value="<?= e($salTo) ?>">
            <input type="hidden" name="salary_month" value="<?= e($salMonth) ?>">

            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:14px;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <span style="font-weight:600;color:var(--ink-900);">المستحق للموظفين (<?= count($payrollData) ?>)</span>
                    <button type="button" class="btn btn-sm btn-ghost" id="btnSalSelectAll" style="padding:2px 8px;font-size:12px;">تحديد الكل</button>
                    <button type="button" class="btn btn-sm btn-ghost" id="btnSalDeselectAll" style="padding:2px 8px;font-size:12px;">إلغاء التحديد</button>
                </div>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <?php if (is_owner()): ?>
                        <button type="submit" name="action" value="save_payroll_adjustments" formnovalidate class="btn btn-sm btn-ghost" style="border:1.5px solid var(--line);"><i class="ti ti-device-floppy"></i> حفظ التعديلات</button>
                    <?php endif; ?>
                    <select name="treasury_id" required style="padding:7px 10px;border:1.5px solid var(--line);border-radius:8px;font-family:inherit;font-size:13px;">
                        <option value="">— اختر خزينة الصرف —</option>
                        <?php foreach ($payTreasuries as $t): ?>
                            <option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="date" name="date" value="<?= date('Y-m-d') ?>" required
                           style="padding:7px 10px;border:1.5px solid var(--line);border-radius:8px;font-family:inherit;font-size:13px;">
                    <button type="submit" name="action" value="pay_salary_batch" class="btn btn-sm" onclick="return confirm('تأكيد صرف الرواتب للموظفين المحددين؟')">
                        <i class="ti ti-check"></i> صرف المحددين
                    </button>
                </div>
            </div>

            <?php if (empty($payrollData)): ?>
                <div class="empty">لا يوجد موظفون نشطون.</div>
            <?php else: ?>
            <div style="overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th style="width:30px;"><input type="checkbox" id="salMasterCb" title="تحديد/إلغاء الكل"></th>
                        <th>الموظف</th>
                        <th>ساعات العمل</th>
                        <th>أيام العمل</th>
                        <th>أيام الإجازة</th>
                        <th>أيام الاستحقاق</th>
                        <th>الأساسي</th>
                        <th>قيمة اليوم</th>
                        <th>الاستحقاق قبل الخصم</th>
                        <th>المكافآت</th>
                        <th>سلف الشهر</th>
                        <th>سلف مستديمة</th>
                        <th>الخصومات</th>
                        <th>الصافي</th>
                        <th>إجراء</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($payrollData as $p): ?>
                    <tr data-emp-id="<?= $p['id'] ?>"
                        data-salary-mode="<?= e($p['salary_mode']) ?>"
                        data-base-salary="<?= $p['base_salary'] ?>"
                        data-work-days="<?= $p['work_days'] ?>"
                        data-leave-days="<?= $p['leave_days'] ?>"
                        data-day-rate="<?= $p['day_rate'] ?>"
                        data-bonuses="<?= $p['total_bonuses'] ?>"
                        data-monthly-adv="<?= $p['monthly_adv_owed'] ?>"
                        data-standing-adv="<?= $p['standing_adv_owed'] ?>"
                        data-penalties="<?= $p['penalties'] ?>"
                        data-deficits="<?= $p['deficits'] ?>"
                        data-deductions="<?= $p['total_deductions'] ?>">
                        <td>
                            <input type="checkbox" name="employee_ids[]" value="<?= $p['id'] ?>" class="sal-emp-cb" <?= ($p['is_paid'] || (!$p['is_fixed'] && $p['no_expected'])) ? 'disabled' : '' ?>>
                        </td>
                        <td style="font-weight:500;">
                            <?= e($p['full_name']) ?>
                            <?php if ($p['branch_name']): ?>
                                <span style="font-size:12px;color:var(--ink-500);">(<?= e($p['branch_name']) ?>)</span>
                            <?php endif; ?>
                            <?php if ($p['ledger_balance'] < -0.005): ?>
                                <span class="badge" style="font-size:11px;color:var(--pos);background:var(--pos-soft);margin-right:4px;" title="رصيد دائن في سجل حساب الموظف">الشركة مدينة له <?= number_format(abs($p['ledger_balance']), 2) ?></span>
                            <?php endif; ?>
                            <?php if ($p['is_fixed']): ?>
                                <span class="badge" style="font-size:11px;color:var(--accent);background:var(--accent-soft);margin-right:4px;">مرتب ثابت</span>
                            <?php elseif ($p['no_expected']): ?>
                                <div style="font-size:11px;color:var(--warn);font-weight:600;margin-top:2px;">
                                    <i class="ti ti-alert-triangle"></i> الساعات المنتظرة مش محددة — المرتب مش هيتحسب
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?= $p['is_fixed'] ? '<span style="color:var(--ink-400);">—</span>' : number_format($p['work_hours'], 2) ?></td>
                        <td><?= ($p['is_fixed'] || $p['no_expected']) ? '<span style="color:var(--ink-400);">—</span>' : ('<span title="أيام العمل الفعلية: ' . number_format($p['work_days'], 3) . '">' . number_format($p['work_days'], 2) . '</span>') ?></td>
                        <td>
                            <?php if ($p['is_fixed'] || $p['no_expected']): ?>
                                <span style="color:var(--ink-400);">—</span>
                            <?php elseif (is_owner() && !$p['is_paid']): ?>
                                <input type="number" min="0" max="60" step="1" name="leave_days[<?= $p['id'] ?>]"
                                       value="<?= (int)$p['leave_days'] ?>"
                                       class="leave-days-input payroll-num is-days"
                                       data-calc="<?= (int)$p['leave_days'] ?>"
                                       style="font-weight:600;">
                            <?php else: ?>
                                <span style="font-weight:600;"><?= (int)$p['leave_days'] ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($p['is_fixed'] || $p['no_expected']): ?>
                                <span style="color:var(--ink-400);">—</span>
                            <?php elseif (is_owner() && !$p['is_paid']): ?>
                                <div style="display:inline-flex;align-items:center;gap:4px;">
                                    <input type="number" min="0" max="60" step="0.01" name="due_days[<?= $p['id'] ?>]"
                                           value="<?= number_format($p['due_days_calc'], 2, '.', '') ?>"
                                           class="due-days-input payroll-num"
                                           data-calc="<?= number_format($p['due_days_calc'], 2, '.', '') ?>"
                                           style="font-weight:600;">
                                    <span class="due-days-edited-badge" style="display:none;font-size:11px;color:var(--warn);font-weight:600;" title="القيمة تختلف عن المحسوب (أيام العمل + الإجازة)">معدّلة</span>
                                </div>
                            <?php else: ?>
                                <span class="cell-due-days" style="font-weight:600;" title="أيام الاستحقاق الفعلية: <?= number_format($p['due_days_calc'], 3) ?>"><?= number_format($p['due_days_calc'], 2) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= number_format($p['base_salary'], 2) ?></td>
                        <td><?= ($p['is_fixed'] || $p['no_expected']) ? '<span style="color:var(--ink-400);">—</span>' : number_format($p['day_rate'], 2) ?></td>
                        <td class="cell-gross"><?= ($p['is_fixed']) ? number_format($p['gross_salary'], 2) : ($p['no_expected'] ? '—' : number_format($p['gross_salary'], 2)) ?></td>
                        <td>
                            <?php if ($p['is_paid'] || !is_owner()): ?>
                                <span style="color:<?= $p['total_bonuses'] > 0 ? 'var(--pos)' : 'var(--ink-500)' ?>;font-weight:600;">
                                    <?= $p['total_bonuses'] > 0 ? ('+' . number_format($p['total_bonuses'], 2)) : '0.00' ?>
                                </span>
                            <?php elseif ($p['bonus_cnt'] > 1): ?>
                                <span style="color:var(--pos);font-weight:600;">+<?= number_format($p['total_bonuses'], 2) ?></span>
                                <a href="hr.php?tab=bonuses" style="display:block;font-size:11px;color:var(--accent);text-decoration:none;" title="يوجد أكثر من مكافأة معلّقة — عدلها من تاب المكافآت">تفاصيل (<?= $p['bonus_cnt'] ?>)</a>
                            <?php elseif ($p['bonus_cnt'] === 1): ?>
                                <input type="number" step="0.01" min="0" name="bonuses[<?= $p['id'] ?>]"
                                       value="<?= number_format($p['total_bonuses'], 2, '.', '') ?>"
                                       class="bonus-input payroll-num"
                                       data-orig="<?= $p['total_bonuses'] ?>"
                                       style="color:var(--pos);font-weight:600;">
                            <?php else: ?>
                                <input type="number" step="0.01" min="0" name="bonuses[<?= $p['id'] ?>]"
                                       value=""
                                       placeholder="0.00"
                                       class="bonus-input payroll-num"
                                       data-orig="0.00"
                                       style="color:var(--pos);font-weight:600;">
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($p['is_paid'] || !is_owner()): ?>
                                <span style="color:<?= $p['monthly_adv_owed'] > 0 ? 'var(--neg)' : 'var(--ink-500)' ?>;font-weight:500;">
                                    <?= number_format($p['monthly_adv_owed'], 2) ?>
                                </span>
                            <?php elseif ($p['monthly_adv_cnt'] > 1): ?>
                                <span style="color:var(--neg);font-weight:500;"><?= number_format($p['monthly_adv_owed'], 2) ?></span>
                                <a href="hr.php?tab=advances" style="display:block;font-size:11px;color:var(--accent);text-decoration:none;" title="يوجد أكثر من سلفة شهرية معلّقة — عدلها من تاب السلف">تفاصيل (<?= $p['monthly_adv_cnt'] ?>)</a>
                            <?php elseif ($p['monthly_adv_cnt'] === 1): ?>
                                <input type="number" step="0.01" min="0" name="monthly_adv[<?= $p['id'] ?>]"
                                       value="<?= number_format($p['monthly_adv_owed'], 2, '.', '') ?>"
                                       class="monthly-adv-input payroll-num"
                                       data-orig="<?= $p['monthly_adv_owed'] ?>"
                                       style="color:var(--neg);font-weight:500;">
                            <?php else: ?>
                                <input type="number" step="0.01" min="0" name="monthly_adv[<?= $p['id'] ?>]"
                                       value=""
                                       placeholder="0.00"
                                       class="monthly-adv-input payroll-num"
                                       data-orig="0.00"
                                       style="color:var(--neg);font-weight:500;">
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($p['is_paid'] || !is_owner()): ?>
                                <span style="color:<?= $p['standing_adv_owed'] > 0 ? 'var(--neg)' : 'var(--ink-500)' ?>;font-weight:500;">
                                    <?= number_format($p['standing_adv_owed'], 2) ?>
                                </span>
                            <?php elseif ($p['standing_adv_cnt'] > 1): ?>
                                <span style="color:var(--neg);font-weight:500;"><?= number_format($p['standing_adv_owed'], 2) ?></span>
                                <a href="hr.php?tab=advances" style="display:block;font-size:11px;color:var(--accent);text-decoration:none;" title="يوجد أكثر من سلفة مستديمة معلّقة — عدلها من تاب السلف">تفاصيل (<?= $p['standing_adv_cnt'] ?>)</a>
                            <?php elseif ($p['standing_adv_cnt'] === 1): ?>
                                <input type="number" step="0.01" min="0" name="standing_adv[<?= $p['id'] ?>]"
                                       value="<?= number_format($p['standing_adv_owed'], 2, '.', '') ?>"
                                       class="standing-adv-input payroll-num"
                                       data-orig="<?= $p['standing_adv_owed'] ?>"
                                       title="تعديل رصيد السلفة المستديمة القائمة"
                                       style="color:var(--neg);font-weight:500;">
                            <?php else: ?>
                                <span style="color:var(--ink-400);font-size:12px;" title="لإضافة سلفة مستديمة جديدة حدد القسط من تاب السلف">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="cell-deductions">
                            <span style="color:<?= $p['total_deductions'] > 0 ? 'var(--neg)' : 'var(--ink-500)' ?>;font-weight:600;" title="سلف: <?= number_format($p['advances'],2) ?> | جزاءات: <?= number_format($p['penalties'],2) ?> | عجز: <?= number_format($p['deficits'],2) ?>">
                                <?= number_format($p['total_deductions'], 2) ?>
                            </span>
                        </td>
                        <td class="cell-net" style="font-weight:700;<?= ($p['no_expected'] && !$p['is_fixed']) ? 'color:var(--warn);' : ($p['net_salary'] >= 0 ? 'color:var(--pos);' : 'color:var(--neg);') ?>">
                            <?= ($p['no_expected'] && !$p['is_fixed']) ? 'غير محدد' : number_format($p['net_salary'], 2) ?>
                        </td>
                        <td>
                            <?php if ($p['is_paid']): ?>
                                <span class="badge" style="color:var(--pos);background:var(--pos-soft);">تم الصرف</span>
                            <?php elseif (!$p['is_fixed'] && $p['no_expected']): ?>
                                <span class="badge" style="color:var(--warn);background:var(--warn-soft);" title="حدد الساعات المنتظرة في التعريفات أولاً">غير محدد</span>
                            <?php else: ?>
                                <button type="button" class="btn btn-sm btn-ghost btn-single-pay"
                                        data-emp-id="<?= $p['id'] ?>"
                                        data-emp-name="<?= e($p['full_name']) ?>"
                                        title="صرف لهذا الموظف">صرف</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </form>
    </div>

    <!-- سجل الرواتب المصروفة سابقاً -->
    <div class="card">
        <div class="section-title">سجل الرواتب المصروفة (<?= count($salaries) ?>)</div>
        <?php if (empty($salaries)): ?>
            <div class="empty">لا توجد رواتب مصروفة.</div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>الشهر</th>
                    <th>الموظف</th>
                    <th>فترة الحضور</th>
                    <th>ساعات/أيام العمل</th>
                    <th>أيام الاستحقاق</th>
                    <th>الأساسي</th>
                    <th>مكافآت</th>
                    <th>سلف</th>
                    <th>جزاءات</th>
                    <th>عجز عهدة</th>
                    <th>الصافي</th>
                    <th>الحالة</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($salaries as $s): $b=$statusBadge($s['status']); ?>
                <tr>
                    <td><?= e($s['salary_month']) ?></td>
                    <td style="font-weight:500;"><?= e($s['full_name']) ?></td>
                    <td style="font-size:12px;color:var(--ink-500);">
                        <?= !empty($s['period_from']) ? (e($s['period_from']) . ' ← ' . e($s['period_to'])) : '—' ?>
                    </td>
                    <td style="font-size:13px;">
                        <?= $s['work_hours'] !== null ? (number_format((float)$s['work_hours'], 1) . ' س (' . number_format((float)$s['work_days'], 2) . ' ي)') : '—' ?>
                    </td>
                    <td>
                        <?php if ($s['due_days'] !== null): ?>
                            <b><?= number_format((float)$s['due_days'], 2) ?></b>
                            <?php if ($s['due_days_calc'] !== null && abs((float)$s['due_days'] - (float)$s['due_days_calc']) > 0.0001): ?>
                                <span style="font-size:11px;color:var(--warn);" title="المحسوب: <?= number_format((float)$s['due_days_calc'], 2) ?>">(معدّل)</span>
                            <?php endif; ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td><?= number_format((float)$s['base_salary'],2) ?></td>
                    <td style="color:var(--pos);"><?= isset($s['total_bonuses']) ? number_format((float)$s['total_bonuses'],2) : '0.00' ?></td>
                    <td style="color:var(--neg);"><?= number_format((float)$s['total_advances'],2) ?></td>
                    <td style="color:var(--neg);"><?= number_format((float)$s['total_penalties'],2) ?></td>
                    <td style="color:var(--neg);"><?= number_format((float)$s['total_deficits'],2) ?></td>
                    <td style="font-weight:700;color:var(--pos);"><?= number_format((float)$s['net_salary'],2) ?></td>
                    <td><span class="badge" style="color:<?=$b[1]?>;background:<?=$b[2]?>;"><?=e($b[0])?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'requests'): ?>
    <div class="card">
        <div class="section-title">طلبات تصحيح الحضور</div>
        <?php if (empty($requests)): ?>
            <div class="empty">لا توجد طلبات.</div>
        <?php else: ?>
        <table>
            <thead><tr><th>الموظف</th><th>اليوم</th><th>دخول مطلوب</th><th>خروج مطلوب</th><th>السبب</th><th>الحالة</th><th>إجراء</th></tr></thead>
            <tbody>
            <?php foreach ($requests as $r): $b=$statusBadge($r['status']); ?>
                <tr>
                    <td style="font-weight:500;"><?= e($r['full_name']) ?></td>
                    <td><?= e($r['req_date']) ?></td>
                    <td><?= e($r['requested_in'] ? substr($r['requested_in'],0,5) : '—') ?></td>
                    <td><?= e($r['requested_out'] ? substr($r['requested_out'],0,5) : '—') ?></td>
                    <td style="font-size:13px;"><?= e($r['reason'] ?? '—') ?></td>
                    <td><span class="badge" style="color:<?=$b[1]?>;background:<?=$b[2]?>;"><?=e($b[0])?></span></td>
                    <td>
                        <?php if ($r['status']==='pending'): ?>
                            <?php if (can_edit_attendance_time()): ?>
                                <button type="button" class="btn btn-sm btn-ghost btn-edit-req"
                                        data-id="<?= (int)$r['id'] ?>"
                                        data-in="<?= e($r['requested_in'] ? substr($r['requested_in'],0,5) : '') ?>"
                                        data-out="<?= e($r['requested_out'] ? substr($r['requested_out'],0,5) : '') ?>"
                                        data-name="<?= e($r['full_name']) ?>"
                                        data-date="<?= e($r['req_date']) ?>"
                                        title="تعديل الأوقات"><i class="ti ti-edit"></i> تعديل</button>
                            <?php endif; ?>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="approve_request">
                                <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-sm btn-approve">اعتماد</button>
                            </form>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="reject_request">
                                <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-sm btn-reject">رفض</button>
                            </form>
                        <?php else: ?>
                            <span style="color:#ccc;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

<?php else: // attendance ?>
    <div class="card">
        <div class="section-title">سجل الحضور والانصراف</div>
        <?php if (can('hr:attendance_import')): ?>
            <div class="toolbar" style="margin-bottom:14px;">
                <span style="color:var(--ink-500);font-size:14px;">الحضور بيتسجّل بالبصمة، وتقدر ترفع شيت كروينجو لشهر كامل.</span>
                <a href="attendance_import.php" class="btn btn-ghost"><i class="ti ti-file-spreadsheet"></i> رفع شيت الحضور</a>
            </div>
        <?php endif; ?>
        <form method="get" style="margin-bottom:14px;display:flex;gap:8px;align-items:center;">
            <input type="hidden" name="tab" value="attendance">
            <label style="font-size:13px;color:var(--ink-500);">الشهر:</label>
            <input type="month" name="month" value="<?= e($attMonth) ?>" onchange="this.form.submit()"
                   style="padding:8px 11px;border:1.5px solid var(--line);border-radius:8px;font-family:inherit;">
        </form>
        <?php if (empty($attRows)): ?>
            <div class="empty">لا يوجد سجل حضور لهذا الشهر.</div>
        <?php else: ?>
        <table>
            <thead><tr><th>التاريخ</th><th>الموظف</th><th>الحضور</th><th>الانصراف</th><th>ساعات العمل</th><th>مكان البصمة</th><th>الحالة</th><th>إجراءات</th></tr></thead>
            <tbody>
            <?php
                $statusMap = ['present'=>['حاضر','var(--pos)','var(--pos-soft)'],'absent'=>['غائب','var(--neg)','var(--neg-soft)'],
                              'leave'=>['إجازة','var(--warn)','var(--warn-soft)'],'holiday'=>['عطلة','#666','#eee']];
                // خلية مكان البصمة: رابط خريطة + تنبيه لو برّة النطاق
                $locCell = function($lat, $lng, $dist, $outside, $label, $acc) {
                    if ($lat === null || $lng === null || $lat === '' || $lng === '') return '';
                    $url = 'https://www.google.com/maps?q=' . $lat . ',' . $lng;
                    // uncertain مش عمود في الجدول فبنستنتجه من الدقة
                    $uncertain = ($outside == 0 && $acc !== null && $acc > 100);
                    if ($outside) {
                        $color = 'var(--neg)';
                        $txt = '⚠ برّة (' . (int)$dist . 'م)';
                    } elseif ($uncertain) {
                        $color = 'var(--warn)';
                        $txt = '؟ الموقع مش دقيق (' . (int)$dist . 'م ±' . (int)$acc . 'م)';
                    } else {
                        $color = 'var(--pos)';
                        $txt = '✓ داخل النطاق';
                    }
                    return '<a href="' . $url . '" target="_blank" style="color:' . $color . ';font-size:12px;text-decoration:none;white-space:nowrap;" title="افتح على الخريطة">'
                         . $label . ' ' . $txt . '</a>';
                };
                foreach ($attRows as $a):
                    $sb = $statusMap[$a['status']] ?? ['—','#666','#eee'];
                    $inLoc  = $locCell($a['check_in_lat'] ?? null,  $a['check_in_lng'] ?? null,  $a['check_in_dist'] ?? null,  !empty($a['check_in_outside']),  'دخول:', $a['check_in_acc'] ?? null);
                    $outLoc = $locCell($a['check_out_lat'] ?? null, $a['check_out_lng'] ?? null, $a['check_out_dist'] ?? null, !empty($a['check_out_outside']), 'خروج:', $a['check_out_acc'] ?? null);
                    $rowFlagged = !empty($a['check_in_outside']) || !empty($a['check_out_outside']);
            ?>
                <tr<?= $rowFlagged ? ' style="background:#fff6f6;"' : '' ?>>
                    <td><?= e($a['att_date']) ?></td>
                    <td style="font-weight:500;"><?= e($a['full_name']) ?></td>
                    <td><?= e($a['check_in'] ? substr($a['check_in'],0,5) : '—') ?></td>
                    <td><?= e($a['check_out'] ? substr($a['check_out'],0,5) : '—') ?></td>
                    <td><?= number_format((float)$a['work_hours'],2) ?></td>
                    <td>
                        <?php if ($inLoc || $outLoc): ?>
                            <div style="display:flex;flex-direction:column;gap:2px;"><?= $inLoc ?><?= $outLoc ?></div>
                        <?php else: ?>
                            <span style="color:var(--ink-300);font-size:12px;">—</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge" style="color:<?=$sb[1]?>;background:<?=$sb[2]?>;"><?=e($sb[0])?></span></td>
                    <td>
                        <form method="post" style="display:inline;"
                              onsubmit="return confirm('تأكيد مسح سجل حضور <?= e($a['full_name']) ?> يوم <?= e($a['att_date']) ?>؟ هيقدر يسجّل من جديد.')">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete_attendance">
                            <input type="hidden" name="attendance_id" value="<?= (int)$a['id'] ?>">
                            <button class="btn btn-sm btn-reject" title="مسح سجل اليوم"><i class="ti ti-trash"></i> مسح</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Modals -->
<div class="modal-bg" id="advanceModal">
    <div class="modal">
        <h3>تسجيل سلفة قديمة / مستديمة</h3>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_advance">
            <div class="form-row"><label>الموظف</label><select name="employee_id" required>
                <option value="">— اختر —</option>
                <?php foreach ($employees as $emp): ?><option value="<?= (int)$emp['id'] ?>"><?= e($emp['full_name']) ?></option><?php endforeach; ?>
            </select></div>
            <div class="form-row"><label>المبلغ</label><input type="number" step="0.01" min="0.01" name="amount" required aria-label="المبلغ"></div>
            <div class="form-row"><label>نوع السلفة</label><select name="adv_type">
                <option value="standing" selected>مستديمة (رصيد قديم)</option>
                <option value="monthly">سلفة الشهر</option>
            </select></div>
            <div class="form-row"><label>القسط الشهري (0 = خصم بالكامل مع الراتب)</label><input type="number" step="0.01" min="0" name="monthly_deduction" value="0" aria-label="القسط الشهري"></div>
            <div class="form-row"><label>التاريخ</label><input type="date" name="advance_date" value="<?= date('Y-m-d') ?>"></div>
            <div class="form-row"><label>ملاحظات</label><input type="text" name="notes" placeholder="مثال: رصيد سلفة قديم قبل النظام" aria-label="ملاحظات"></div>
            <div class="modal-actions">
                <button type="submit" class="btn">حفظ</button>
                <button type="button" class="btn btn-cancel" onclick="document.getElementById('advanceModal').classList.remove('open')">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<?php if (is_owner()): ?>
<div class="modal-bg" id="openingBalanceModal">
    <div class="modal">
        <h3>تسجيل رصيد افتتاحي</h3>
        <p style="font-size:12.5px;color:var(--ink-500);margin-bottom:14px;line-height:1.7;">
            الحركة دي <b>مابتلمسش الخزينة خالص</b> — هي بتسجّل رصيد قديم بين الشركة
            والموظف مش متسجّل في النظام. لو الفلوس اتحركت فعلاً، سجّلها من الخزينة
            مش من هنا.
        </p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_opening_balance">
            <div class="form-row"><label>الموظف</label><select name="employee_id" required>
                <option value="">— اختر —</option>
                <?php foreach ($employees as $emp): ?><option value="<?= (int)$emp['id'] ?>"><?= e($emp['full_name']) ?></option><?php endforeach; ?>
            </select></div>
            <div class="form-row"><label>الاتجاه</label><select name="direction" required>
                <option value="debit">عليه — الموظف مدين للشركة</option>
                <option value="credit">له — الشركة مدينة للموظف</option>
            </select></div>
            <div class="form-row"><label>المبلغ</label><input type="number" step="0.01" min="0.01" name="amount" required aria-label="المبلغ"></div>
            <div class="form-row"><label>التاريخ</label><input type="date" name="date" value="<?= date('Y-m-d') ?>" required></div>
            <div class="form-row"><label>البيان (مطلوب)</label><input type="text" name="description" required aria-label="البيان"></div>
            <div class="modal-actions">
                <button type="submit" class="btn">حفظ</button>
                <button type="button" class="btn btn-cancel" onclick="document.getElementById('openingBalanceModal').classList.remove('open')">إلغاء</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="modal-bg" id="bonusModal">
    <div class="modal">
        <h3>إضافة مكافأة</h3>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_bonus">
            <div class="form-row"><label>الموظف</label><select name="employee_id" required>
                <option value="">— اختر —</option>
                <?php foreach ($employees as $emp): ?><option value="<?= (int)$emp['id'] ?>"><?= e($emp['full_name']) ?></option><?php endforeach; ?>
            </select></div>
            <div class="form-row"><label>المبلغ</label><input type="number" step="0.01" min="0.01" name="amount" required aria-label="المبلغ"></div>
            <div class="form-row"><label>التاريخ</label><input type="date" name="date" value="<?= date('Y-m-d') ?>"></div>
            <div class="form-row"><label>السبب</label><input type="text" name="reason" aria-label="السبب"></div>
            <div class="modal-actions">
                <button type="submit" class="btn">حفظ</button>
                <button type="button" class="btn btn-cancel" onclick="document.getElementById('bonusModal').classList.remove('open')">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-bg" id="penaltyModal">
    <div class="modal">
        <h3>إضافة جزاء</h3>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_penalty">
            <div class="form-row"><label>الموظف</label><select name="employee_id" required>
                <option value="">— اختر —</option>
                <?php foreach ($employees as $emp): ?><option value="<?= (int)$emp['id'] ?>"><?= e($emp['full_name']) ?></option><?php endforeach; ?>
            </select></div>
            <div class="form-row"><label>المبلغ</label><input type="number" step="0.01" min="0.01" name="amount" required aria-label="المبلغ"></div>
            <div class="form-row"><label>التاريخ</label><input type="date" name="date" value="<?= date('Y-m-d') ?>"></div>
            <div class="form-row"><label>السبب</label><input type="text" name="reason" aria-label="السبب"></div>
            <div class="modal-actions">
                <button type="submit" class="btn">حفظ</button>
                <button type="button" class="btn btn-cancel" onclick="document.getElementById('penaltyModal').classList.remove('open')">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-bg" id="leaveModal">
    <div class="modal">
        <h3>إضافة إجازة</h3>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_leave">
            <div class="form-row"><label>الموظف</label><select name="employee_id" required>
                <option value="">— اختر —</option>
                <?php foreach ($employees as $emp): ?><option value="<?= (int)$emp['id'] ?>"><?= e($emp['full_name']) ?></option><?php endforeach; ?>
            </select></div>
            <div class="form-row"><label>من</label><input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required></div>
            <div class="form-row"><label>إلى</label><input type="date" name="end_date" value="<?= date('Y-m-d') ?>" required></div>
            <div class="form-row"><label>النوع</label><select name="leave_type">
                <option value="annual">سنوية</option><option value="sick">مرضية</option>
                <option value="unpaid">بدون راتب</option><option value="other">أخرى</option>
            </select></div>
            <div class="modal-actions">
                <button type="submit" class="btn">حفظ</button>
                <button type="button" class="btn btn-cancel" onclick="document.getElementById('leaveModal').classList.remove('open')">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-bg" id="salaryModal">
    <div class="modal">
        <h3>صرف مرتب فردي</h3>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="pay_salary">
            <input type="hidden" name="period_from" value="<?= e($salFrom) ?>">
            <input type="hidden" name="period_to" value="<?= e($salTo) ?>">
            <div class="form-row"><label>الموظف</label><select name="employee_id" id="singleSalaryEmpId" required>
                <option value="">— اختر —</option>
                <?php foreach ($employees as $emp): ?><option value="<?= (int)$emp['id'] ?>"><?= e($emp['full_name']) ?></option><?php endforeach; ?>
            </select></div>
            <div class="form-row"><label>شهر المرتب</label><input type="month" name="salary_month" value="<?= e($salMonth) ?>" required></div>
            <div class="form-row"><label>خزينة الصرف</label><select name="treasury_id" required>
                <option value="">— اختر الخزينة —</option>
                <?php foreach ($payTreasuries as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?>
            </select></div>
            <div class="form-row"><label>التاريخ</label><input type="date" name="date" value="<?= date('Y-m-d') ?>"></div>
            <?php if (is_owner()): ?>
                <div class="form-row">
                    <label>أيام الإجازة (تعديل الأونر — اختياري)</label>
                    <input type="number" min="0" max="60" step="1" name="leave_days" id="singleSalaryLeaveDays" placeholder="اتركه فارغاً للاعتماد التلقائي">
                </div>
                <div class="form-row">
                    <label>أيام الاستحقاق (تعديل الأونر — اختياري)</label>
                    <input type="number" min="0" max="60" step="0.01" name="due_days" id="singleSalaryDueDays" placeholder="اتركه فارغاً للاعتماد التلقائي">
                </div>
            <?php endif; ?>
            <p style="font-size:12px;color:var(--ink-500);">سيتم إضافة المكافآت وخصم السلف والجزاءات وعجز العهدة المعلّقة تلقائياً.</p>
            <div class="modal-actions">
                <button type="submit" class="btn">صرف المرتب</button>
                <button type="button" class="btn btn-cancel" onclick="document.getElementById('salaryModal').classList.remove('open')">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-bg" id="batchSalaryModal">
    <div class="modal" style="max-width:500px;">
        <h3>صرف رواتب مجموعة</h3>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="pay_salary_batch">
            <input type="hidden" name="period_from" value="<?= e($salFrom) ?>">
            <input type="hidden" name="period_to" value="<?= e($salTo) ?>">
            <div class="form-row">
                <label>الشهر</label>
                <input type="month" name="salary_month" value="<?= e($salMonth) ?>" required>
            </div>
            <div class="form-row">
                <label>خزينة الصرف</label>
                <select name="treasury_id" required>
                    <option value="">— اختر الخزينة —</option>
                    <?php foreach ($payTreasuries as $t): ?>
                        <option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label>تاريخ الصرف</label>
                <input type="date" name="date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-row">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                    <label style="margin:0;font-weight:600;">الموظفون (<?= count($empList) ?>)</label>
                    <div style="display:flex;gap:6px;">
                        <button type="button" class="btn btn-sm btn-ghost" id="btnBatchSelectAll" style="padding:2px 8px;font-size:12px;">تحديد الكل</button>
                        <button type="button" class="btn btn-sm btn-ghost" id="btnBatchDeselectAll" style="padding:2px 8px;font-size:12px;">إلغاء تحديد الكل</button>
                    </div>
                </div>
                <div style="max-height:200px;overflow-y:auto;border:1.5px solid var(--line);border-radius:9px;padding:8px 12px;background:var(--line-soft);">
                    <?php foreach ($empList as $emp): ?>
                        <label style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid var(--line);cursor:pointer;font-size:13px;">
                            <input type="checkbox" name="employee_ids[]" class="batch-emp-cb" value="<?= (int)$emp['id'] ?>" style="width:auto;margin:0;">
                            <span>
                                <b><?= e($emp['full_name']) ?></b>
                                <?php if (!empty($emp['branch_name'])): ?>
                                    <span style="color:var(--ink-500);font-size:12px;"> (<?= e($emp['branch_name']) ?>)</span>
                                <?php endif; ?>
                                <span style="color:var(--ink-500);font-size:12px;"> — راتب <?= number_format((float)$emp['base_salary'], 2) ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <p style="font-size:12px;color:var(--ink-500);">سيتم صرف المرتبات للموظفين المحددين وإضافة المكافآت وخصم السلف والجزاءات وعجز العهدة تلقائياً لكل موظف.</p>
            <div class="modal-actions">
                <button type="submit" class="btn"><i class="ti ti-check"></i> صرف الرواتب المحددة</button>
                <button type="button" class="btn btn-cancel" onclick="document.getElementById('batchSalaryModal').classList.remove('open')">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<?php if (can_edit_attendance_time()): ?>
<div class="modal-bg" id="editRequestModal">
    <div class="modal">
        <h3>تعديل وقت طلب التصحيح</h3>
        <p style="font-size:13px;color:var(--ink-500);margin-bottom:14px;">
            الموظف: <strong id="editReqEmpName" style="color:var(--ink-900);"></strong> · التاريخ: <strong id="editReqDate" style="color:var(--ink-900);"></strong>
        </p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_request_time">
            <input type="hidden" name="request_id" id="editReqId" value="">
            <div class="form-row">
                <label>وقت الحضور المطلوب</label>
                <input type="time" name="requested_in" id="editReqIn">
            </div>
            <div class="form-row">
                <label>وقت الانصراف المطلوب</label>
                <input type="time" name="requested_out" id="editReqOut">
            </div>
            <div class="modal-actions">
                <button type="submit" class="btn">حفظ التعديل</button>
                <button type="button" class="btn btn-cancel" onclick="document.getElementById('editRequestModal').classList.remove('open')">إلغاء</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="modal-bg" id="editAdvanceModal">
    <div class="modal">
        <h3>تعديل القسط الشهري للسلفة</h3>
        <p style="font-size:13px;color:var(--ink-500);margin-bottom:14px;">
            الموظف: <strong id="editAdvEmpName" style="color:var(--ink-900);"></strong> · أصل السلفة: <strong id="editAdvAmount" style="color:var(--ink-900);"></strong> · المتبقي: <strong id="editAdvRemaining" style="color:var(--ink-900);"></strong>
        </p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_advance_deduction">
            <input type="hidden" name="advance_id" id="editAdvId" value="">
            <div class="form-row">
                <label>القسط الشهري (0 = خصم المتبقي بالكامل مع أول راتب)</label>
                <input type="number" step="0.01" min="0" name="monthly_deduction" id="editAdvDeduction" required>
            </div>
            <p style="font-size:12px;color:var(--ink-500);">إذا حُدد القسط بـ 0 فسيتم خصم كامل المبلغ المتبقي مع الراتب القادم.</p>
            <div class="modal-actions">
                <button type="submit" class="btn">حفظ التعديل</button>
                <button type="button" class="btn btn-cancel" onclick="document.getElementById('editAdvanceModal').classList.remove('open')">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.querySelectorAll('.modal-bg').forEach(m => {
        m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
    });
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-edit-req');
        if (!btn) return;
        var modal = document.getElementById('editRequestModal');
        if (!modal) return;
        document.getElementById('editReqId').value = btn.dataset.id;
        document.getElementById('editReqIn').value = btn.dataset.in || '';
        document.getElementById('editReqOut').value = btn.dataset.out || '';
        document.getElementById('editReqEmpName').textContent = btn.dataset.name;
        document.getElementById('editReqDate').textContent = btn.dataset.date;
        modal.classList.add('open');
    });

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-edit-adv');
        if (!btn) return;
        var modal = document.getElementById('editAdvanceModal');
        if (!modal) return;
        document.getElementById('editAdvId').value = btn.dataset.id;
        document.getElementById('editAdvEmpName').textContent = btn.dataset.name;
        document.getElementById('editAdvAmount').textContent = btn.dataset.amount;
        document.getElementById('editAdvRemaining').textContent = btn.dataset.remaining;
        document.getElementById('editAdvDeduction').value = btn.dataset.deduction || '0';
        modal.classList.add('open');
    });

    // تفويض زر الصرف الفردي من صف الجدول
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-single-pay');
        if (!btn) return;
        var tr = btn.closest('tr');
        var empId = btn.dataset.empId;
        var modal = document.getElementById('salaryModal');
        if (!modal) return;
        var empSelect = document.getElementById('singleSalaryEmpId');
        if (empSelect) empSelect.value = empId;
        var leaveInput = tr ? tr.querySelector('.leave-days-input') : null;
        var modalLeaveInput = document.getElementById('singleSalaryLeaveDays');
        if (modalLeaveInput && leaveInput) {
            modalLeaveInput.value = leaveInput.value;
        } else if (modalLeaveInput) {
            modalLeaveInput.value = '';
        }

        var dueInput = tr ? tr.querySelector('.due-days-input') : null;
        var modalDueInput = document.getElementById('singleSalaryDueDays');
        if (modalDueInput && dueInput) {
            modalDueInput.value = dueInput.value;
        } else if (modalDueInput) {
            modalDueInput.value = '';
        }
        modal.classList.add('open');
    });

    // تحديث أرقام أيام الاستحقاق والاستحقاق والخصومات والصافي لحظياً عند تعديل أي خانة (إجازة، استحقاق، مكافأة، سلف)
    document.addEventListener('input', function(e) {
        var inp = e.target;
        if (!inp.matches('.leave-days-input, .due-days-input, .bonus-input, .monthly-adv-input, .standing-adv-input')) return;
        var tr = inp.closest('tr');
        if (!tr) return;

        var salaryMode = tr.dataset.salaryMode || 'hours';
        var isFixed = (salaryMode === 'fixed');
        var baseSalary = parseFloat(tr.dataset.baseSalary) || 0;
        var dayRate = parseFloat(tr.dataset.dayRate) || 0;
        var workDays = parseFloat(tr.dataset.workDays) || 0;
        var penalties = parseFloat(tr.dataset.penalties) || 0;
        var deficits = parseFloat(tr.dataset.deficits) || 0;

        var leaveInp = tr.querySelector('.leave-days-input');
        var leaveDays = leaveInp ? (leaveInp.value.trim() === '' ? (parseFloat(leaveInp.dataset.calc) || 0) : (parseFloat(leaveInp.value) || 0)) : (parseFloat(tr.dataset.leaveDays) || 0);

        var dueInp = tr.querySelector('.due-days-input');
        var dueBadge = tr.querySelector('.due-days-edited-badge');

        // لو التعديل جه من خانة الإجازة، حدّث خانة الاستحقاق تلقائياً للمحسوب الجديد (أيام العمل + الإجازة الجديدة)
        if (inp === leaveInp && dueInp) {
            var autoDue = workDays + leaveDays;
            dueInp.value = autoDue.toFixed(2);
        }

        var bonusInp = tr.querySelector('.bonus-input');
        var bonuses = bonusInp ? (bonusInp.value.trim() === '' ? (parseFloat(bonusInp.dataset.orig) || 0) : (parseFloat(bonusInp.value) || 0)) : (parseFloat(tr.dataset.bonuses) || 0);

        var monthlyInp = tr.querySelector('.monthly-adv-input');
        var monthlyAdv = monthlyInp ? (monthlyInp.value.trim() === '' ? (parseFloat(monthlyInp.dataset.orig) || 0) : (parseFloat(monthlyInp.value) || 0)) : (parseFloat(tr.dataset.monthlyAdv) || 0);

        var standingInp = tr.querySelector('.standing-adv-input');
        var standingAdv = standingInp ? (standingInp.value.trim() === '' ? (parseFloat(standingInp.dataset.orig) || 0) : (parseFloat(standingInp.value) || 0)) : (parseFloat(tr.dataset.standingAdv) || 0);

        var gross = 0;
        if (isFixed) {
            gross = baseSalary;
        } else {
            var calcDueDays = workDays + leaveDays;
            var effectiveDueDays = calcDueDays;
            if (dueInp && dueInp.value.trim() !== '') {
                effectiveDueDays = parseFloat(dueInp.value) || 0;
            }

            // إظهار أو إخفاء علامة "معدّلة" لو القيمة تختلف عن المحسوب (أيام العمل + الإجازة)
            if (dueBadge) {
                if (Math.abs(effectiveDueDays - parseFloat(calcDueDays.toFixed(2))) > 0.0001) {
                    dueBadge.style.display = 'inline-block';
                } else {
                    dueBadge.style.display = 'none';
                }
            }

            gross = dayRate * effectiveDueDays;
            var dueDaysCell = tr.querySelector('.cell-due-days');
            if (dueDaysCell) {
                dueDaysCell.textContent = effectiveDueDays.toFixed(2);
                dueDaysCell.title = 'أيام الاستحقاق الفعلية: ' + effectiveDueDays.toFixed(3);
            }
        }

        var totalDeductions = monthlyAdv + standingAdv + penalties + deficits;
        var net = gross + bonuses - totalDeductions;

        var grossCell = tr.querySelector('.cell-gross');
        if (grossCell) {
            grossCell.textContent = gross.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
        }

        var dedCell = tr.querySelector('.cell-deductions');
        if (dedCell) {
            dedCell.innerHTML = '<span style="color:' + (totalDeductions > 0 ? 'var(--neg)' : 'var(--ink-500)') + ';font-weight:600;" title="سلف: ' + (monthlyAdv + standingAdv).toFixed(2) + ' | جزاءات: ' + penalties.toFixed(2) + ' | عجز: ' + deficits.toFixed(2) + '">' +
                totalDeductions.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}) + '</span>';
        }

        var netCell = tr.querySelector('.cell-net');
        if (netCell) {
            netCell.textContent = net.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
            netCell.style.color = net >= 0 ? 'var(--pos)' : 'var(--neg)';
        }
    });

    var salMasterCb = document.getElementById('salMasterCb');
    if (salMasterCb) {
        salMasterCb.addEventListener('change', function() {
            var checked = this.checked;
            document.querySelectorAll('.sal-emp-cb:not(:disabled)').forEach(function(cb) {
                cb.checked = checked;
            });
        });
    }
    var btnSalAll = document.getElementById('btnSalSelectAll');
    if (btnSalAll) {
        btnSalAll.addEventListener('click', function() {
            document.querySelectorAll('.sal-emp-cb:not(:disabled)').forEach(function(cb) {
                cb.checked = true;
            });
            if (salMasterCb) salMasterCb.checked = true;
        });
    }
    var btnSalNone = document.getElementById('btnSalDeselectAll');
    if (btnSalNone) {
        btnSalNone.addEventListener('click', function() {
            document.querySelectorAll('.sal-emp-cb').forEach(function(cb) {
                cb.checked = false;
            });
            if (salMasterCb) salMasterCb.checked = false;
        });
    }

    var btnBatchAll = document.getElementById('btnBatchSelectAll');
    var btnBatchNone = document.getElementById('btnBatchDeselectAll');
    if (btnBatchAll) {
        btnBatchAll.addEventListener('click', function() {
            document.querySelectorAll('.batch-emp-cb').forEach(function(cb) { cb.checked = true; });
        });
    }
    if (btnBatchNone) {
        btnBatchNone.addEventListener('click', function() {
            document.querySelectorAll('.batch-emp-cb').forEach(function(cb) { cb.checked = false; });
        });
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
