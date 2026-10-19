<?php
/**
 * cashier.php — تقفيل الكاشير
 * نظام الفجالة ستور المالي
 *
 * - تقفيل وردية جديدة: فئات الكاش + سطور وسائل الدفع + السيستم.
 * - اختيار الفرع (افتراضياً فرع الكاشير، لكن يقدر يغيّره لو بيغطّي مكان تاني).
 * - تقفيلات اليوم (كل الحالات) + تقفيلاتي القديمة المرفوضة (قابلة للتعديل).
 * - التقفيل مرحلة تسجيل أولي — الفلوس تدخل الخزينة وقت مراجعة المحاسب.
 */
require_once __DIR__ . '/auth.php';
require_permission('cashier');
require_once __DIR__ . '/finance.php';

$pdo  = db();
$u    = current_user();
$uid  = (int) $u['id'];
$flash = ''; $flashType = 'success';

// فئات الكاش. 0.5 و 0.25 كانوا في المراجعة بس ومش في التقفيل،
// فالكاشير ماكانش يقدر يعدّ الفكة.
$denominations = [200, 100, 50, 20, 10, 5, 1, 0.5, 0.25];
$payMethods    = ['visa' => 'فيزا', 'instapay' => 'إنستا باي'];
$rowsPerMethod = 10;

// الفروع المتاحة للاختيار (أي فرع نشط وله مبيعات — للتغطية)
try {
    $allBranches = $pdo->query('SELECT id, name FROM branches WHERE is_active=1 AND has_finance=1 AND has_sales=1 ORDER BY name')->fetchAll();
} catch (Throwable $e) {
    // استرجاع آمن قبل تطبيق ملف الترقية upgrade_has_sales.sql
    $allBranches = $pdo->query('SELECT id, name FROM branches WHERE is_active=1 AND has_finance=1 ORDER BY name')->fetchAll();
}
$defaultBranch = !empty($u['branch_id']) ? (int) $u['branch_id']
                 : ($allBranches ? (int) $allBranches[0]['id'] : 0);

try {
    shift_returns_expire($pdo);
} catch (Throwable $e) {
    // صامت لو الجدول أو الأعمدة لسه مانزلتش
}

// ===== معالجة العمليات =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $flash = 'انتهت صلاحية الجلسة، حاول مرة أخرى.';
        $flashType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'submit_shift' || $action === 'resubmit_shift' || $action === 'owner_edit_shift') {
                $branchId = (int) ($_POST['branch_id'] ?? 0);
                $bizDate  = $_POST['business_date'] ?? date('Y-m-d');
                // ★ الحقلين دول إجباريين: من غيرهم المراجعة مش هتعرف
                //   تطابق الوردية ولا تحسب الفرق.
                $rawSystem = trim((string)($_POST['system_total'] ?? ''));
                $shiftName = trim($_POST['shift_name'] ?? '');

                if ($shiftName === '') {
                    throw new RuntimeException('اكتب رقم الوردية.');
                }
                if ($rawSystem === '' || !is_numeric($rawSystem)) {
                    throw new RuntimeException('اكتب قيمة السيستم.');
                }

                $systemTotal = (float) $rawSystem;
                if ($systemTotal <= 0) {
                    throw new RuntimeException('قيمة السيستم لازم تكون أكبر من صفر.');
                }

                if ($branchId <= 0) throw new RuntimeException('اختر الفرع.');

                // حساب الكاش من الفئات
                $cashTotal = 0.0;
                $cashCounts = [];
                foreach ($denominations as $d) {
                    // العدد صحيح (كام ورقة/عملة)، لكن الفئة نفسها ممكن تكون كسر
                    $c = (int) ($_POST['denom'][(string)$d] ?? 0);
                    if ($c < 0) $c = 0;
                    $cashCounts[$d] = $c;
                    $cashTotal += $d * $c;
                }

                // حساب وسائل الدفع من السطور
                // ⚠️ بنقرا **كل** السطور اللي وصلت مش أول 10 بس.
                //    الواجهة بتسمح بإضافة صفوف بالسهم لتحت، فلو ثبّتنا
                //    الحد على 10 أي صف زيادة بيتساب من غير حساب —
                //    وده معناه فلوس بتختفي من التقفيل من غير أي تحذير.
                $methodTotals = ['visa' => 0.0, 'instapay' => 0.0];
                $lines = [];
                foreach (array_keys($payMethods) as $m) {
                    $posted = $_POST[$m] ?? [];
                    if (!is_array($posted)) $posted = [];
                    $refs = $_POST[$m . '_ref'] ?? [];
                    if (!is_array($refs)) $refs = [];

                    // ترتيب بالمفتاح عشان الترقيم يفضل زي ما الكاشير شافه
                    ksort($posted, SORT_NUMERIC);

                    foreach ($posted as $i => $rawAmt) {
                        $i   = (int) $i;
                        $amt = (float) $rawAmt;
                        if ($amt <= 0) continue;
                        // line_no في الجدول TINYINT (أقصى 255) — نحميه من أي رقم شاذ
                        if ($i < 1 || $i > 255) $i = min(255, count($lines) + 1);
                        $ref = trim((string) ($refs[$i] ?? ''));
                        $methodTotals[$m] += $amt;
                        $lines[] = [$m, $i, $ref, $amt];
                    }
                }

                // حساب المصروفات النثرية من الدرج
                $rawExpAmounts  = $_POST['exp_amount'] ?? [];
                $rawExpAccounts = $_POST['exp_account_id'] ?? [];
                $rawExpNotes    = $_POST['exp_note'] ?? [];
                $expenses = [];
                if (is_array($rawExpAmounts)) {
                    foreach ($rawExpAmounts as $k => $rawAmt) {
                        $amt = (float) $rawAmt;
                        if ($amt <= 0) continue;
                        $accId = !empty($rawExpAccounts[$k]) ? (int) $rawExpAccounts[$k] : null;
                        $note  = isset($rawExpNotes[$k]) ? trim((string)$rawExpNotes[$k]) : '';
                        if (mb_strlen($note) > 255) {
                            $note = mb_substr($note, 0, 255);
                        }
                        $expenses[] = [
                            'account_id' => $accId,
                            'amount'     => $amt,
                            'note'       => $note !== '' ? $note : null,
                        ];
                    }
                }

                // حساب إجمالي المصروفات النثرية من الدرج
                $expTotal = array_sum(array_column($expenses, 'amount'));

                // الفرق المجمع = (كاش في الدرج + مصروفات الدرج + وسائل الدفع) − السيستم
                $collected  = $cashTotal + $expTotal + $methodTotals['visa'] + $methodTotals['instapay'];
                $difference = $collected - $systemTotal;

                $pdo->beginTransaction();
                try {
                    if ($action === 'owner_edit_shift') {
                        if (!is_owner()) {
                            throw new RuntimeException('غير مصرح لك بتعديل هذه الوردية.');
                        }
                        $shiftId = (int) ($_POST['shift_id'] ?? 0);
                        $chk = $pdo->prepare('SELECT * FROM shifts WHERE id=? FOR UPDATE');
                        $chk->execute([$shiftId]);
                        $oldShift = $chk->fetch();
                        if (!$oldShift || !in_array($oldShift['status'], ['submitted', 'resubmitted'], true)) {
                            throw new RuntimeException('لا يمكن تعديل هذه الوردية (لازم تكون مستنية مراجعة).');
                        }

                        // قراءة الفئات والسطور والمصروفات القديمة قبل المسح (للسجل)
                        $oldCashSt = $pdo->prepare('SELECT denomination, count, subtotal FROM shift_cash WHERE shift_id=?');
                        $oldCashSt->execute([$shiftId]);
                        $oldCash = $oldCashSt->fetchAll();

                        $oldLinesSt = $pdo->prepare('SELECT method, line_no, reference, amount FROM shift_payment_lines WHERE shift_id=?');
                        $oldLinesSt->execute([$shiftId]);
                        $oldLines = $oldLinesSt->fetchAll();

                        $oldExpSt = $pdo->prepare('SELECT account_id, amount, note FROM shift_expenses WHERE shift_id=?');
                        $oldExpSt->execute([$shiftId]);
                        $oldExp = $oldExpSt->fetchAll();

                        $before = [
                            'shift'    => $oldShift,
                            'cash'     => $oldCash,
                            'lines'    => $oldLines,
                            'expenses' => $oldExp,
                        ];

                        $pdo->prepare(
                            'UPDATE shifts SET branch_id=?, business_date=?, shift_name=?, cash_total=?, visa_total=?,
                                wallet_total=0, instapay_total=?, system_total=?, difference=?
                             WHERE id=?'
                        )->execute([$branchId, $bizDate, $shiftName, $cashTotal, $methodTotals['visa'],
                            $methodTotals['instapay'], $systemTotal, $difference, $shiftId]);

                        // مسح الفئات والسطور والمصروفات القديمة
                        $pdo->prepare('DELETE FROM shift_cash WHERE shift_id=?')->execute([$shiftId]);
                        $pdo->prepare('DELETE FROM shift_payment_lines WHERE shift_id=?')->execute([$shiftId]);
                        $pdo->prepare('DELETE FROM shift_expenses WHERE shift_id=?')->execute([$shiftId]);
                    } elseif ($action === 'resubmit_shift') {
                        // تعديل وردية مرفوضة موجودة
                        $shiftId = (int) ($_POST['shift_id'] ?? 0);
                        if (is_shift_return_expired($shiftId)) {
                            throw new RuntimeException('الوقت المسموح لتعديل الوردية دي عدّى.');
                        }
                        // تأكد إنها بتاعته ومرفوضة
                        $chk = $pdo->prepare("SELECT user_id, status FROM shifts WHERE id=? FOR UPDATE");
                        $chk->execute([$shiftId]);
                        $row = $chk->fetch();
                        if (!$row || (int)$row['user_id'] !== $uid || $row['status'] !== 'rejected') {
                            throw new RuntimeException('لا يمكن تعديل هذه الوردية.');
                        }
                        $pdo->prepare(
                            'UPDATE shifts SET branch_id=?, business_date=?, shift_name=?, cash_total=?, visa_total=?,
                                wallet_total=0, instapay_total=?, system_total=?, difference=?,
                                status="resubmitted" WHERE id=?'
                        )->execute([$branchId, $bizDate, $shiftName, $cashTotal, $methodTotals['visa'],
                            $methodTotals['instapay'], $systemTotal, $difference, $shiftId]);
                        // امسح التفاصيل القديمة
                        $pdo->prepare('DELETE FROM shift_cash WHERE shift_id=?')->execute([$shiftId]);
                        $pdo->prepare('DELETE FROM shift_payment_lines WHERE shift_id=?')->execute([$shiftId]);
                        $pdo->prepare('DELETE FROM shift_expenses WHERE shift_id=?')->execute([$shiftId]);
                    } else {
                        // وردية جديدة
                        $pdo->prepare(
                            'INSERT INTO shifts (user_id, branch_id, business_date, shift_name, cash_total, visa_total,
                                wallet_total, instapay_total, system_total, difference, status)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "submitted")'
                        )->execute([$uid, $branchId, $bizDate, $shiftName, $cashTotal, $methodTotals['visa'],
                            0, $methodTotals['instapay'], $systemTotal, $difference]);
                        $shiftId = (int) $pdo->lastInsertId();
                    }

                    // حفظ فئات الكاش
                    $insC = $pdo->prepare('INSERT INTO shift_cash (shift_id, denomination, count, subtotal) VALUES (?, ?, ?, ?)');
                    foreach ($cashCounts as $d => $c) {
                        if ($c > 0) $insC->execute([$shiftId, $d, $c, $d * $c]);
                    }
                    // حفظ سطور الدفع
                    $insL = $pdo->prepare('INSERT INTO shift_payment_lines (shift_id, method, line_no, reference, amount) VALUES (?, ?, ?, ?, ?)');
                    foreach ($lines as $ln) {
                        $insL->execute([$shiftId, $ln[0], $ln[1], $ln[2] ?: null, $ln[3]]);
                    }
                    // حفظ بنود المصروفات
                    if (!empty($expenses)) {
                        $insE = $pdo->prepare('INSERT INTO shift_expenses (shift_id, account_id, amount, note) VALUES (?, ?, ?, ?)');
                        foreach ($expenses as $exp) {
                            $insE->execute([$shiftId, $exp['account_id'], $exp['amount'], $exp['note']]);
                        }
                    }

                    if ($action === 'owner_edit_shift') {
                        $afterShift = $oldShift;
                        $afterShift['branch_id']      = $branchId;
                        $afterShift['business_date']  = $bizDate;
                        $afterShift['shift_name']     = $shiftName;
                        $afterShift['cash_total']     = $cashTotal;
                        $afterShift['visa_total']     = $methodTotals['visa'];
                        $afterShift['wallet_total']   = 0;
                        $afterShift['instapay_total'] = $methodTotals['instapay'];
                        $afterShift['system_total']   = $systemTotal;
                        $afterShift['difference']     = $difference;

                        $newCash = [];
                        foreach ($cashCounts as $d => $c) {
                            if ($c > 0) $newCash[] = ['denomination' => $d, 'count' => $c, 'subtotal' => $d * $c];
                        }
                        $newLines = [];
                        foreach ($lines as $ln) {
                            $newLines[] = ['method' => $ln[0], 'line_no' => $ln[1], 'reference' => $ln[2] ?: null, 'amount' => $ln[3]];
                        }

                        $after = [
                            'shift' => $afterShift,
                            'cash'  => $newCash,
                            'lines' => $newLines,
                        ];

                        shift_edit_log($pdo, $shiftId, $uid, 'edit', $before, $after);
                    }

                    $pdo->commit();
                    try {
                        shift_sequence_check($pdo, $shiftId);
                    } catch (Throwable $seqEx) {
                        // صامت
                    }

                    if ($action === 'owner_edit_shift') {
                        $flash = 'تم تعديل الوردية.';
                    } elseif ($action === 'resubmit_shift') {
                        $flash = 'تم تعديل الوردية وإعادة إرسالها للمحاسب.';
                    } else {
                        $flash = 'تم تقفيل الوردية وإرسالها للمراجعة.';
                    }
                } catch (Throwable $ex) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $ex;
                }
            }
            elseif ($action === 'delete_shift') {
                delete_shift((int)($_POST['shift_id'] ?? 0), $uid, is_owner());
                $flash = 'تم مسح التقفيلة.';
            }
            elseif ($action === 'seq_reply') {
                $issueId = (int)($_POST['issue_id'] ?? 0);
                $reply   = trim((string)($_POST['reply'] ?? ''));
                if ($reply === '') {
                    throw new RuntimeException('اكتب الرد أولاً.');
                }
                $up = $pdo->prepare(
                    "UPDATE shift_sequence_issues
                     SET status = 'answered', reply = ?, resolved_at = NOW()
                     WHERE id = ? AND assigned_user_id = ? AND status = 'sent'"
                );
                $up->execute([$reply, $issueId, $uid]);
                if ($up->rowCount() > 0) {
                    _notify_seq_issue($pdo, "{$u['full_name']} رد على مشكلة التسلسل", $reply);
                    $flash = 'تم إرسال ردك بنجاح.';
                } else {
                    throw new RuntimeException('المشكلة غير متاحة للرد أو تم الرد عليها مسبقاً.');
                }
            }
        } catch (Throwable $ex) {
            $flash = 'خطأ: ' . $ex->getMessage();
            $flashType = 'error';
        }
    }
    // PRG: بعد أي POST نعمل تحويل لصفحة GET عشان الـ Refresh ما يعيدش الإرسال (منع التكرار) والحفاظ على الفلاتر
    $_SESSION['flash'] = $flash;
    $_SESSION['flash_type'] = $flashType;
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF']));
    exit;
}

// نقرأ رسالة الفلاش من السيشن (بعد التحويل)
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    $flashType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash'], $_SESSION['flash_type']);
}

// ===== اليوم المحاسبي (9ص لـ 9ص) =====
// لو الساعة قبل 9 صباحاً، اليوم المحاسبي = امبارح
$now = new DateTime();
$bizDateToday = (clone $now);
if ((int)$now->format('H') < 9) {
    $bizDateToday->modify('-1 day');
}
$bizDateStr = $bizDateToday->format('Y-m-d');

// ---------------------------------------------------------------------------
//  نطاق الرؤية
//
//  القواعد:
//    - الأونر: يشوف كل الفروع، ويقدر يفلتر بأي فرع.
//    - الموظف المربوط بفرع: يشوف فرعه دايمًا.
//    - الموظف غير المربوط بفرع: مايشوفش حاجة — لحد ما يعمل تقفيل
//      على فرع في يوم العمل الحالي، وساعتها يشوف الفرع ده.
//      ومع بداية يوم عمل جديد، الرؤية بتختفي تاني لحد ما يقفل.
//
//  السبب: قبل كده كان `$seeAll = is_owner() || $myBranch === null`
//  يعني اللي مش مربوط بفرع كان بيشوف كل الفروع — عكس المطلوب تمامًا.
// ---------------------------------------------------------------------------
$myBranch = !empty($u['branch_id']) ? (int)$u['branch_id'] : null;
$isOwner  = is_owner();

// ★ الفروع اللي قفل عليها في يوم العمل الحالي بس.
//   مش كل تاريخه — عشان الرؤية تنتهي مع نهاية اليوم، وميفضلش شايف
//   بيانات فرع غطّى فيه مرة من شهر.
$mine = $pdo->prepare(
    'SELECT DISTINCT branch_id FROM shifts WHERE user_id = ? AND business_date = ?'
);
$mine->execute([$uid, $bizDateStr]);
$workedBranches = array_map('intval', $mine->fetchAll(PDO::FETCH_COLUMN));

// الفروع المسموح له يشوفها
if ($isOwner) {
    $allowedBranches = array_map(fn($b) => (int)$b["id"], $allBranches);
} else {
    $allowedBranches = $workedBranches;
    if ($myBranch !== null && !in_array($myBranch, $allowedBranches, true)) {
        $allowedBranches[] = $myBranch;
    }
}

// فلتر الفرع المختار (من الزراير) — للأونر بس.
// غير الأونر: أي محاولة فلترة من الـ URL بتتجاهل.
$filterBranch = ($isOwner && isset($_GET['branch'])) ? (int)$_GET['branch'] : 0;

// حماية: مايقدرش يفلتر بفرع مش مسموح له
if ($filterBranch > 0 && !in_array($filterBranch, $allowedBranches, true)) {
    $filterBranch = 0;
}

// الفروع اللي هتظهر كزراير
$branchTabs = array_values(array_filter(
    $allBranches,
    fn($b) => in_array((int)$b['id'], $allowedBranches, true)
));

// ---------------------------------------------------------------------------
//  بناء شرط الفرع للاستعلامات
// ---------------------------------------------------------------------------
$where  = ['s.business_date = ?'];
$params = [$bizDateStr];

if (empty($allowedBranches)) {
    // مش مربوط بفرع ومعملش تقفيل قبل كده → مايشوفش حاجة
    $where[]  = '1 = 0';
} elseif ($filterBranch > 0) {
    $where[]  = 's.branch_id = ?';
    $params[] = $filterBranch;
} elseif (!$isOwner) {
    $ph       = implode(',', array_fill(0, count($allowedBranches), '?'));
    $where[]  = "s.branch_id IN ({$ph})";
    $params   = array_merge($params, $allowedBranches);
}

// تقفيلات اليوم
$todayShifts = $pdo->prepare(
    "SELECT s.*, u.full_name AS cashier_name, b.name AS branch_name
     FROM shifts s
     JOIN users u ON u.id = s.user_id
     JOIN branches b ON b.id = s.branch_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY s.id DESC"
);
$todayShifts->execute($params);
$todayShifts = $todayShifts->fetchAll();

// تقفيلاتي المرفوضة (القديمة، القابلة للتعديل) — دي بتفضل ظاهرة لصاحبها
$hoursReturn = (int) setting('shift_return_hours', 48);
try {
    $myRejectedSt = $pdo->prepare(
        "SELECT s.*, b.name AS branch_name,
                CASE WHEN s.reviewed_at >= ? THEN TIMESTAMPDIFF(HOUR, NOW(), s.reviewed_at + INTERVAL ? HOUR) END AS hours_left,
                (s.return_expired_at IS NOT NULL OR (s.reviewed_at >= ? AND s.reviewed_at + INTERVAL ? HOUR < NOW())) AS is_return_expired
         FROM shifts s JOIN branches b ON b.id = s.branch_id
         WHERE s.user_id = ? AND s.status = 'rejected'
         ORDER BY s.id DESC"
    );
    // المرجوعة قبل shift_return_since مالهاش مهلة (hours_left = NULL) — شوف shift_return_since()
    $retSince = shift_return_since();
    $myRejectedSt->execute([$retSince, $hoursReturn, $retSince, $hoursReturn, $uid]);
    $myRejected = $myRejectedSt->fetchAll();
} catch (Throwable $e) {
    $myRejectedSt = $pdo->prepare(
        "SELECT s.*, b.name AS branch_name
         FROM shifts s JOIN branches b ON b.id = s.branch_id
         WHERE s.user_id = ? AND s.status = 'rejected'
         ORDER BY s.id DESC"
    );
    $myRejectedSt->execute([$uid]);
    $myRejected = $myRejectedSt->fetchAll();
}

// فتح الوردية من اللينك (edit_shift)
$autoOpenShiftId = 0;
if (isset($_GET['edit_shift'])) {
    $reqEditId = (int)$_GET['edit_shift'];
    if ($reqEditId > 0) {
        $targetShift = null;
        foreach ($myRejected as $rej) {
            if ((int)$rej['id'] === $reqEditId) {
                $targetShift = $rej;
                break;
            }
        }
        if ($targetShift) {
            $isExp = !empty($targetShift['is_return_expired'])
                || !empty($targetShift['return_expired_at'])
                || (isset($targetShift['hours_left']) && (int)$targetShift['hours_left'] < 0)
                || is_shift_return_expired($reqEditId);
            if ($isExp) {
                $flash = "الوقت المسموح لتعديل الوردية دي عدّى ({$hoursReturn} ساعة). كلّم المحاسب.";
                $flashType = 'error';
            } else {
                $autoOpenShiftId = $reqEditId;
            }
        }
    }
}

// قراءة مشاكل التسلسل المطلوب رد المستخدم عليها
$mySeqIssues = [];
try {
    $stSeq = $pdo->prepare(
        "SELECT ssi.*, b.name AS branch_name
         FROM shift_sequence_issues ssi
         JOIN branches b ON b.id = ssi.branch_id
         WHERE ssi.status = 'sent' AND ssi.assigned_user_id = ?
         ORDER BY ssi.id DESC"
    );
    $stSeq->execute([$uid]);
    $mySeqIssues = $stSeq->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // الجدول لسه مانزلش
}

// ---------------------------------------------------------------------------
//  الأرشيف
//  المرفوض بقى يختفي زي المعتمد (كان بيفضل ظاهر ويزحم الجدول).
//  اللي بيظهر: المسوّدات واللي تحت المراجعة بس.
// ---------------------------------------------------------------------------
$aWhere  = ['s.business_date < ?', "s.status NOT IN ('approved','rejected')"];
$aParams = [$bizDateStr];

if (empty($allowedBranches)) {
    $aWhere[]  = '1 = 0';
} elseif ($filterBranch > 0) {
    $aWhere[]  = 's.branch_id = ?';
    $aParams[] = $filterBranch;
} elseif (!$isOwner) {
    $ph        = implode(',', array_fill(0, count($allowedBranches), '?'));
    $aWhere[]  = "s.branch_id IN ({$ph})";
    $aParams   = array_merge($aParams, $allowedBranches);
}

$archiveShifts = $pdo->prepare(
    "SELECT s.*, u.full_name AS cashier_name, b.name AS branch_name
     FROM shifts s
     JOIN users u ON u.id = s.user_id
     JOIN branches b ON b.id = s.branch_id
     WHERE " . implode(' AND ', $aWhere) . "
     ORDER BY s.business_date DESC, s.id DESC LIMIT 100"
);
$archiveShifts->execute($aParams);
$archiveShifts = $archiveShifts->fetchAll();

// جلب بادج «آخر تعديل» لكل الورديات المعروضة باستعلام مجمّع واحد
$allCashierShiftIds = [];
foreach ($todayShifts as $ts) $allCashierShiftIds[] = (int)$ts['id'];
foreach ($archiveShifts as $as) $allCashierShiftIds[] = (int)$as['id'];
foreach ($myRejected as $mr) $allCashierShiftIds[] = (int)$mr['id'];
$allCashierShiftIds = array_values(array_unique(array_filter($allCashierShiftIds)));

// جلب مصروفات الورديات المعروضة باستعلام مجمّع واحد
$shiftExpensesMap = shift_expenses_for_shifts($allCashierShiftIds, $pdo);

$shiftEditBadges = [];
if (!empty($allCashierShiftIds)) {
    try {
        $phBadge = implode(',', array_fill(0, count($allCashierShiftIds), '?'));
        $stBadge = $pdo->prepare("
            SELECT sel.shift_id, sel.created_at, u.full_name AS editor_name
            FROM shift_edit_log sel
            JOIN (
                SELECT shift_id, MAX(id) AS max_id
                FROM shift_edit_log
                WHERE shift_id IN ($phBadge)
                GROUP BY shift_id
            ) latest ON latest.max_id = sel.id
            JOIN users u ON u.id = sel.user_id
        ");
        $stBadge->execute($allCashierShiftIds);
        foreach ($stBadge as $br) {
            $shiftEditBadges[(int)$br['shift_id']] = [
                'name' => $br['editor_name'],
                'time' => date('H:i', strtotime($br['created_at'])),
            ];
        }
    } catch (Throwable $ex) {
        // لو جدول shift_edit_log مش موجود ← مفيش بادج ومفيش خطأ
    }
}

// إجماليات اليوم (لبطاقات الملخص فوق + صف الإجماليات في الجدول)
$sumCash   = array_sum(array_column($todayShifts, 'cash_total'));
$sumVisa   = array_sum(array_column($todayShifts, 'visa_total'));
$sumInsta  = array_sum(array_column($todayShifts, 'instapay_total'));
$sumSys    = array_sum(array_column($todayShifts, 'system_total'));
$sumExp    = 0.0;
foreach ($todayShifts as $ts) {
    $sid = (int)$ts['id'];
    $sExps = $shiftExpensesMap[$sid] ?? [];
    $sumExp += array_sum(array_column($sExps, 'amount'));
}
$sumColl   = $sumCash + $sumExp + $sumVisa + $sumInsta;
$sumVI     = $sumVisa + $sumInsta;   // الفيزا + الإنستا (من غير الكاش)
$sumDiff   = $sumColl - $sumSys;

$statusLabels = [
    'submitted'   => ['مستلمة', 'var(--ink-700)', 'var(--line-soft)'],
    'approved'    => ['معتمدة', 'var(--pos)', 'var(--pos-soft)'],
    'rejected'    => ['مرفوضة', 'var(--neg)', 'var(--neg-soft)'],
    'resubmitted' => ['معاد إرسال', 'var(--warn)', 'var(--warn-soft)'],
];

$page_title    = 'تقفيل الكاشير';
$active_module = 'cashier';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .flash { padding:12px 16px; border-radius:10px; margin-bottom:18px; font-size:14px; }
    .flash.success { background:var(--pos-soft); color:var(--pos); }
    .flash.error   { background:var(--neg-soft); color:var(--neg); }
    .card { background:var(--surface); border-radius:14px; padding:20px; box-shadow:0 2px 10px rgba(124,111,247,0.06); margin-bottom:18px; }
    .section-title { font-size:16px; color:var(--ink-900); font-weight:600; margin-bottom:14px; }
    .grid-top { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:18px; }
    @media (max-width:760px){ .grid-top { grid-template-columns:1fr; } }
    .fld label { display:block; font-size:13px; color:var(--ink-700); margin-bottom:6px; font-weight:500; }
    .fld input, .fld select { width:100%; padding:10px 12px; border:1.5px solid var(--line); border-radius:9px;
        font-size:14px; font-family:inherit; background:var(--line-soft); }
    .fld input:focus, .fld select:focus { outline:none; border-color:var(--accent-text); background:var(--surface); }
    .denom-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:10px; }
    .denom-cell { background:var(--line-soft); border:1.5px solid var(--line); border-radius:10px; padding:10px; }
    .denom-cell .dlabel { font-size:13px; color:var(--ink-500); margin-bottom:5px; }
    .denom-cell input { width:100%; padding:7px 9px; border:1px solid var(--line); border-radius:7px;
        font-size:14px; text-align:center; font-family:inherit; }
    .denom-cell .dsub { font-size:12px; color:var(--accent-text); margin-top:4px; font-weight:600; }
    .pay-method { margin-bottom:16px; }
    .pay-method h4 { font-size:14px; color:var(--ink-700); margin-bottom:8px; }
    .pay-rows { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:8px; }
    .pay-row { display:flex; gap:6px; }
    .pay-row input.ref { flex:1.4; padding:7px 9px; border:1.5px solid var(--line); border-radius:7px; font-size:13px; font-family:inherit; }
    .pay-row input.amt { flex:1; padding:7px 9px; border:1.5px solid var(--line); border-radius:7px; font-size:13px; text-align:center; font-family:inherit; }
    .totals-bar { display:flex; gap:18px; flex-wrap:wrap; padding:14px; background:var(--bg); border-radius:10px; margin:14px 0; }
    .total-item { font-size:14px; }
    .total-item .tl { color:var(--ink-500); font-size:12px; } .total-item .tv { color:var(--ink-900); font-weight:700; font-size:17px; }
    .diff-pos { color:var(--pos); } .diff-neg { color:var(--neg); }
    table { width:100%; border-collapse:collapse; }
    th, td { padding:10px 12px; text-align:right; font-size:14px; border-bottom:1px solid var(--line); }
    th { color:var(--ink-500); font-weight:600; font-size:13px; }
    td { color:var(--ink-800); }
    .badge { display:inline-block; padding:3px 11px; border-radius:20px; font-size:12px; font-weight:500; }
    .empty { text-align:center; color:var(--ink-400); padding:24px; font-size:14px; }
    .reject-reason { background:var(--neg-soft); color:var(--neg); padding:8px 12px; border-radius:8px; font-size:13px; margin-top:4px; }
    /* تبويبات التقفيل */
    .ct-tabs { display:flex; gap:6px; flex-wrap:wrap; margin:6px 0 16px; border-bottom:2px solid var(--line); }
    .ct-tab { padding:9px 18px; border:none; background:transparent; color:var(--ink-500); font-family:inherit;
        font-size:14px; cursor:pointer; border-bottom:3px solid transparent; margin-bottom:-2px; font-weight:600; }
    .ct-tab.active { color:var(--accent-text); border-bottom-color:var(--accent-text); }
    .ct-tab:hover:not(.active) { color:var(--accent-text); }
    .ct-pane { display:none; }
    .ct-pane.active { display:block; }
    .pay-table { width:100%; border-collapse:collapse; table-layout:fixed; }
    .pay-table td { padding:4px; border:none; }
    .pay-table td:first-child, .pay-table th:first-child { width:34px; }
    .pay-table td:last-child, .pay-table th:last-child { width:110px; }
    .pay-table th { padding:4px 6px 8px; text-align:right; font-size:12px; color:var(--ink-500); font-weight:600; border:none; }
    .pay-table input { width:100%; padding:8px 10px; border:1.5px solid var(--line); border-radius:8px; font-size:14px; font-family:inherit; box-sizing:border-box; }
    .totals-row td { font-weight:700; background:var(--accent-soft); color:var(--ink-900); }
    .grid-3 { grid-template-columns:1fr 1fr 1fr; }
    @media (max-width:760px){ .grid-3 { grid-template-columns:1fr; } }
    .grid-4 { grid-template-columns:1fr 1fr 1fr 1fr; }
    @media (max-width:760px){ .grid-4 { grid-template-columns:1fr 1fr; } }
    @media (max-width:480px){ .grid-4 { grid-template-columns:1fr; } }
    /* بوب أب التقفيل */
    .shift-modal { display:none; position:fixed; inset:0; background:rgba(30,27,75,0.5);
        z-index:var(--z-modal); align-items:flex-start; justify-content:center; padding:30px 16px; overflow-y:auto; }
    .shift-modal.open { display:flex; }
    .shift-modal-box { background:var(--surface); border-radius:16px; width:100%; max-width:780px;
        box-shadow:0 18px 50px rgba(0,0,0,0.25); margin:auto; }
    .shift-modal-head { display:flex; justify-content:space-between; align-items:center;
        padding:18px 22px; border-bottom:1.5px solid var(--line); position:sticky; top:0; background:var(--surface); border-radius:16px 16px 0 0; z-index:2; }
    .shift-modal-body { padding:20px 22px; }
    .modal-x { background:none; border:none; font-size:28px; color:var(--ink-400); cursor:pointer; line-height:1; padding:0 6px; }
    .modal-x:hover { color:var(--neg); }
    .shift-modal-box { overflow-x:hidden; }
    .shift-modal-body { max-width:100%; }
    .shift-modal-body input, .shift-modal-body select { max-width:100%; box-sizing:border-box; }
    .pay-table input { width:100%; box-sizing:border-box; min-width:0; }
    @media (max-width:600px){
        .shift-modal { padding:12px 6px; }
        .shift-modal-body { padding:14px 12px; }
        .shift-modal-head { padding:14px 12px; }
        .ct-tabs { flex-wrap:wrap; gap:4px; }
        .ct-tab { font-size:13px; padding:7px 10px; }
        .side-summary { margin-top:14px; }
        .cash-total-box, .side-summary .sum-detail { padding:12px; }
        .denom-line input { width:70px; }
        .pay-table td:last-child, .pay-table th:last-child { width:88px; }
        .pay-table input { padding:7px 8px; font-size:13px; }
    }
    /* بطاقات ملخص اليوم: ٧ كروت متساوية */
    .stat-cards { display:grid; grid-template-columns:repeat(7,1fr); gap:10px; margin-bottom:18px; }
    @media (max-width:1100px){ .stat-cards { grid-template-columns:repeat(4,1fr); } }
    @media (max-width:760px){ .stat-cards { grid-template-columns:repeat(3,1fr); } }
    @media (max-width:520px){ .stat-cards { grid-template-columns:repeat(2,1fr); } }
    .stat-card { background:var(--surface); border-radius:12px; padding:14px 12px; text-align:center; box-shadow:0 2px 10px rgba(124,111,247,0.06); }
    .stat-card .label { font-size:12px; color:var(--ink-500); margin-bottom:6px; }
    .stat-card .value { font-size:17px; font-weight:700; color:var(--accent-text); }
    /* text-align:center — قاعدة .diff-pos/.diff-neg العامة في fagala.css (بريف ٢١) بتحط
       text-align:right عشان علامة السالب، فالرقم كان بيطلع يمين الكارت مش في نصه. */
    .stat-card .value.diff-pos { color:var(--pos); text-align:center; } .stat-card .value.diff-neg { color:var(--neg); text-align:center; }
    .stat-card .value.exp-val { color:var(--neg); }
    .stat-card .sub { font-size:11px; color:var(--ink-400); margin-top:2px; }
    /* تخطيط تاب الدفع: جدول + ملخص جانبي */
    .pay-with-summary { display:grid; grid-template-columns:1fr 280px; gap:18px; align-items:start; }
    .pay-with-summary .pay-method { min-width:0; }
    @media (max-width:760px){ .pay-with-summary { grid-template-columns:1fr; } }
    .side-summary .sum-detail { background:var(--line-soft); border-radius:12px; padding:16px; }
    .side-summary .sum-detail table { width:100%; border-collapse:collapse; }
    .side-summary .sum-detail .sdt { font-weight:700; color:var(--ink-900); margin-bottom:10px; }
    .side-summary .sum-detail td { padding:6px 2px; font-size:14px; }
    .side-summary .sum-detail td.k { color:var(--ink-500); }
    .side-summary .sum-detail td.v { text-align:left; color:var(--ink-900); font-weight:600; }
    .side-summary .sum-detail tr.grand td { border-top:1.5px solid var(--line); padding-top:10px; font-weight:700; color:var(--accent-text); }
    /* تاب الكاش (شكل المشروع القديم: قائمة يسار + بوكس إجمالي يمين) */
    .cash-layout { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
    @media (max-width:640px){ .cash-layout { grid-template-columns:1fr; } }
    .denom-line { display:flex; align-items:center; gap:10px; margin-bottom:8px; }
    .denom-line .dn { font-size:13px; color:var(--ink-500); min-width:62px; }
    .denom-line input { width:90px; padding:7px 9px; border:1.5px solid var(--line); border-radius:8px;
        text-align:center; font-family:inherit; font-size:14px; background:var(--line-soft); }
    .denom-line input:focus { outline:none; border-color:var(--accent-text); background:var(--surface); }
    .denom-line .mul { font-size:13px; color:var(--ink-300); }
    .denom-line .sub { font-size:13px; font-weight:600; min-width:70px; text-align:left; color:var(--accent-text); }
    .cash-total-box { background:var(--bg); border-radius:12px; padding:18px; height:fit-content; }
    .cash-total-box .ctl { font-size:13px; color:var(--ink-500); }
    .cash-total-box .ctv { font-size:26px; font-weight:700; color:var(--accent-text); margin-top:2px; }
    .cash-total-box .cbreak { font-size:11px; color:var(--ink-500); margin-top:12px; line-height:1.9; }
    /* تاب الملخص (جدول تفاصيل يسار + بوكس الفرق يمين) */
    .sum-layout { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
    @media (max-width:640px){ .sum-layout { grid-template-columns:1fr; } }
    .sum-detail { background:var(--line-soft); border-radius:12px; padding:16px; }
    .sum-detail .sdt { font-size:13px; font-weight:600; color:var(--ink-900); margin-bottom:12px; }
    .sum-detail table { width:100%; border-collapse:collapse; }
    .sum-detail td { padding:6px 0; font-size:13px; border:none; }
    .sum-detail td.k { color:var(--ink-500); }
    .sum-detail td.v { text-align:left; font-weight:600; color:var(--ink-800); }
    .sum-detail tr.grand td { border-top:1.5px solid var(--line); padding-top:10px; font-weight:700; color:var(--ink-900); }
    .sum-diff-box { background:var(--line-soft); border-radius:12px; padding:16px; text-align:center;
        display:flex; flex-direction:column; align-items:center; justify-content:center; }
    .sum-diff-box .sdl { font-size:13px; color:var(--ink-500); }
    .sum-diff-box .sdv { font-size:32px; font-weight:700; margin:10px 0; color:var(--ink-900); }
    .sum-diff-box .sds { font-size:13px; color:var(--ink-500); }

    /* كلاسات بدل style="" المتكرر — بريف ٤٥. القيم منقولة بالحرف، متغيّرهاش. */
    .csh-shift-name { font-weight:600;color:var(--accent-text); }
    .csh-edit-badge { font-size:11px;font-weight:normal;color:var(--ink-500);margin-top:2px; }
</style>

<?php if ($flash): ?>
    <div class="flash <?= e($flashType) ?>"><?= e($flash) ?></div>
<?php endif; ?>

<!-- زر فتح التقفيل -->
<div style="margin-bottom:18px;">
    <button type="button" class="btn" onclick="openShiftModal()"><i class="ti ti-plus"></i> إضافة تقفيل</button>
</div>

<!-- ملخص اليوم -->
<?php if ($isOwner && count($branchTabs) > 1): ?>
<!-- ─────────────────────────────────────────────────────────────────────
     زراير الفروع — للأونر بس.
     الموظف مالوش فلترة: بيشوف فرعه (أو الفرع اللي قفل عليه النهارده).
     ───────────────────────────────────────────────────────────────────── -->
<div class="branch-tabs">
    <a href="?"
       class="btab <?= $filterBranch === 0 ? 'active' : '' ?>">كل الفروع</a>
    <?php foreach ($branchTabs as $bt): ?>
        <a href="?branch=<?= (int)$bt['id'] ?>"
           class="btab <?= $filterBranch === (int)$bt['id'] ? 'active' : '' ?>">
            <?= e($bt['name']) ?>
        </a>
    <?php endforeach; ?>
</div>
<style>
  .branch-tabs { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
  .branch-tabs .btab {
      padding:7px 16px; border-radius:9px; background:var(--surface); color:var(--accent-text);
      text-decoration:none; font-size:14px; border:1.5px solid var(--line);
      transition:.15s;
  }
  .branch-tabs .btab:hover  { border-color:var(--accent); }
  .branch-tabs .btab.active { background:var(--accent); color:#fff; border-color:var(--accent-text); }
</style>
<?php endif; ?>

<?php if (empty($allowedBranches)): ?>
<!-- المستخدم مش مربوط بفرع ومعملش أي تقفيل قبل كده -->
<div style="background:var(--warn-soft);color:var(--warn);border-radius:10px;padding:14px 18px;
            margin-bottom:16px;font-size:14px;">
    مش مربوط بأي فرع. اضغط «إضافة تقفيل»، اختار الفرع، وابعت للمراجعة —
    وبعدها هتشوف بيانات الفرع ده هنا.
</div>
<?php endif; ?>

<?php /* الترتيب مقصود: سيستم · كاش · فيزا · إنستا · الفيزا+الإنستا · المصروفات · الفرق.
         كارت «الفيزا + الإنستا» = الوسائل غير النقدية، الكاش مش داخل فيه. */ ?>
<div class="stat-cards">
    <div class="stat-card">
        <div class="label">سيستم اليوم</div>
        <div class="value"><?= number_format($sumSys,2) ?></div>
        <div class="sub">ج.م</div>
    </div>
    <div class="stat-card">
        <div class="label">كاش اليوم</div>
        <div class="value"><?= number_format($sumCash,2) ?></div>
        <div class="sub">ج.م</div>
    </div>
    <div class="stat-card">
        <div class="label">فيزا اليوم</div>
        <div class="value"><?= number_format($sumVisa,2) ?></div>
        <div class="sub">ج.م</div>
    </div>
    <div class="stat-card">
        <div class="label">إنستا اليوم</div>
        <div class="value"><?= number_format($sumInsta,2) ?></div>
        <div class="sub">ج.م</div>
    </div>
    <div class="stat-card">
        <div class="label">الفيزا + الإنستا</div>
        <div class="value"><?= number_format($sumVI,2) ?></div>
        <div class="sub">ج.م</div>
    </div>
    <div class="stat-card">
        <div class="label">إجمالي المصروفات</div>
        <div class="value exp-val"><?= number_format($sumExp,2) ?></div>
        <div class="sub">ج.م</div>
    </div>
    <div class="stat-card">
        <div class="label">الفرق المجمع</div>
        <div class="value <?= $sumDiff>=0?'diff-pos':'diff-neg' ?>"><?= number_format($sumDiff,2) ?></div>
        <div class="sub">ج.م</div>
    </div>
</div>

<!-- نموذج التقفيل (بوب أب) -->
<div class="shift-modal" id="shiftModal">
  <div class="shift-modal-box">
    <div class="shift-modal-head">
        <span class="section-title" id="shiftModalTitle" style="margin:0;">تقفيل وردية جديدة</span>
        <button type="button" class="modal-x" onclick="closeShiftModal()">&times;</button>
    </div>
    <div class="shift-modal-body">
    <form method="post" id="shiftForm" data-busy="جاري تسليم الوردية...">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="submit_shift" id="formAction">
        <input type="hidden" name="shift_id" value="" id="shiftId">

        <!-- بيانات الوردية -->
        <div class="grid-top grid-4">
            <div class="fld">
                <label for="cashierName">القائم بالتقفيل</label>
                <input type="text" id="cashierName" value="<?= e($u['full_name'] ?? '') ?>" data-self="<?= e($u['full_name'] ?? '') ?>" readonly aria-label="القائم بالتقفيل" style="background:var(--accent-soft);color:var(--ink-900);">
            </div>
            <div class="fld">
                <label for="shiftName">رقم الوردية</label>
                <input type="number" min="1" step="1" name="shift_name" id="shiftName" placeholder="مثال: 1" required>
            </div>
            <div class="fld">
                <label for="shiftBranch">الفرع <?= empty($u['branch_id']) ? '' : '(فرعك)' ?></label>
                <select name="branch_id" id="shiftBranch" required>
                    <?php foreach ($allBranches as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= $defaultBranch===(int)$b['id']?'selected':'' ?>>
                            <?= e($b['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fld">
                <label for="shiftDate">تاريخ اليوم</label>
                <input type="date" name="business_date" id="shiftDate" value="<?= e($bizDateStr) ?>" required>
            </div>
        </div>
        <div id="ownerEditNotice" style="display:none;font-size:12px;color:var(--ink-500);margin-top:6px;margin-bottom:12px;background:var(--line-soft);padding:6px 12px;border-radius:6px;">بتعدّل وردية <span id="ownerEditCashierName" style="font-weight:600;color:var(--ink-800);"></span> — اسم الكاشير مش هيتغيّر، والتعديل هيتسجّل باسمك.</div>

        <!-- تبويبات التقفيل -->
        <div class="ct-tabs">
            <button type="button" class="ct-tab active" data-pane="pane_cash" onclick="ctTab(this)">كاش</button>
            <button type="button" class="ct-tab" data-pane="pane_visa" onclick="ctTab(this)">فيزا</button>
            <button type="button" class="ct-tab" data-pane="pane_instapay" onclick="ctTab(this)">إنستا باي</button>
            <button type="button" class="ct-tab" data-pane="pane_expenses" onclick="ctTab(this)">مصروفات من الدرج</button>
            <button type="button" class="ct-tab" data-pane="pane_summary" onclick="calc();ctTab(this)">الملخص</button>
        </div>

        <!-- كاش -->
        <div class="ct-pane active" id="pane_cash">
            <div class="cash-layout">
                <div>
                    <p style="font-size:13px;color:var(--ink-500);margin-bottom:12px;">أدخل عدد كل فئة</p>
                    <?php foreach ($denominations as $d): ?>
                        <div class="denom-line">
                            <span class="dn"><?= $d ?> ج.م</span>
                            <input type="number" min="0" name="denom[<?= $d ?>]" value="" aria-label="عدد فئة <?= $d ?>"
                                   placeholder="0" data-denom="<?= $d ?>" class="denom-input" oninput="calc()">
                            <span class="mul">×</span>
                            <span class="sub" id="dsub_<?= $d ?>">0.00</span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="cash-total-box">
                    <div class="ctl">إجمالي الكاش</div>
                    <div class="ctv"><span id="t_cash">0.00</span> ج.م</div>
                    <div class="cbreak" id="cashBreak"></div>
                </div>
            </div>
        </div>

        <!-- وسائل الدفع (تبويب لكل واحدة) -->
        <?php foreach ($payMethods as $mk => $mLabel): ?>
        <div class="ct-pane" id="pane_<?= $mk ?>">
            <div class="pay-with-summary">
                <div class="pay-method">
                    <h4><?= e($mLabel) ?></h4>
                    <table class="repeatable pay-table">
                        <thead>
                            <tr>
                                <th style="width:34px;">#</th>
                                <th>رقم السيريال</th>
                                <th>المبلغ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php for ($i=1; $i<=$rowsPerMethod; $i++): ?>
                                <tr>
                                    <td style="color:var(--ink-500);font-size:13px;text-align:center;"><?= $i ?></td>
                                    <td><input type="text" aria-label="رقم مرجعي" class="ref" name="<?= $mk ?>_ref[<?= $i ?>]" placeholder="رقم السيريال"></td>
                                    <td><input type="number" aria-label="المبلغ" step="0.01" min="0" class="amt <?= $mk ?>-amt"
                                           name="<?= $mk ?>[<?= $i ?>]" placeholder="0" oninput="calc()"></td>
                                </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                    <p style="font-size:12px;color:var(--ink-500);margin-top:8px;">اقف على آخر صف واضغط السهم لتحت لإضافة صف جديد.</p>
                </div>
                <div class="side-summary">
                    <div class="sum-detail">
                        <div class="sdt">تفاصيل التقفيل</div>
                        <table>
                            <tr><td class="k">مبيعات السيستم</td><td class="v"><span class="side_system">0.00</span> ج.م</td></tr>
                            <tr><td class="k">كاش في الدرج</td><td class="v"><span class="side_cash">0.00</span> ج.م</td></tr>
                            <tr><td class="k">مصروفات الدرج</td><td class="v" style="color:var(--neg);"><span class="side_expenses">0.00</span> ج.م</td></tr>
                            <tr><td class="k">فيزا</td><td class="v"><span class="side_visa">0.00</span> ج.م</td></tr>
                            <tr><td class="k">إنستا باي</td><td class="v"><span class="side_instapay">0.00</span> ج.م</td></tr>
                            <tr class="grand"><td>إجمالي فعلي</td><td class="v"><span class="side_collected">0.00</span> ج.م</td></tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- مصروفات من الدرج -->
        <div class="ct-pane" id="pane_expenses">
            <div class="pay-with-summary">
                <div class="pay-method">
                    <h4>مصروفات من الدرج</h4>
                    <table class="repeatable pay-table" style="width:100%;border-collapse:collapse;">
                        <thead>
                            <tr>
                                <th style="width:34px;text-align:center;">#</th>
                                <th style="width:170px;">تصنيف المصروف</th>
                                <th style="width:110px;">المبلغ</th>
                                <th>ملاحظة / بيان</th>
                                <th style="width:36px;text-align:center;"></th>
                            </tr>
                        </thead>
                        <tbody id="expRowsTbody">
                            <?php for ($i = 1; $i <= $rowsPerMethod; $i++): ?>
                                <tr>
                                    <td class="exp-row-num" style="color:var(--ink-500);font-size:13px;text-align:center;"><?= $i ?></td>
                                    <td style="padding:4px;">
                                        <?= acctree_field('exp_account_id[]', null, '— اختر الحساب —', 'custody') ?>
                                    </td>
                                    <td style="padding:4px;">
                                        <input type="number" step="0.01" min="0" name="exp_amount[]" class="amt exp-amt"
                                               placeholder="0" oninput="calc()" style="width:100%;padding:8px 10px;border:1.5px solid var(--line);border-radius:8px;font-size:14px;font-family:inherit;box-sizing:border-box;text-align:center;">
                                    </td>
                                    <td style="padding:4px;">
                                        <input type="text" name="exp_note[]" class="exp-note" placeholder="ملاحظة (بنزين، مياه...)"
                                               style="width:100%;padding:8px 10px;border:1.5px solid var(--line);border-radius:8px;font-size:14px;font-family:inherit;box-sizing:border-box;">
                                    </td>
                                    <td style="padding:4px;text-align:center;">
                                        <button type="button" onclick="removeExpRow(this)" title="حذف المصروف"
                                                style="background:none;border:none;cursor:pointer;color:var(--neg);font-size:16px;padding:4px 6px;border-radius:6px;line-height:1;">
                                            <i class="ti ti-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                    <div style="margin-top:10px;">
                        <button type="button" class="btn btn-sm btn-ghost" onclick="addExpRow()" style="padding:5px 12px;font-size:13px;">
                            <i class="ti ti-plus"></i> إضافة سطر مصروف
                        </button>
                    </div>
                    <p style="font-size:12px;color:var(--ink-500);margin-top:8px;">
                        المصروفات النثرية التي صُرفت مباشرة من الدرج (بنزين، إكراميات، مياه، إلخ).
                    </p>
                </div>
                <div class="side-summary">
                    <div class="sum-detail">
                        <div class="sdt">تفاصيل التقفيل</div>
                        <table>
                            <tr><td class="k">مبيعات السيستم</td><td class="v"><span class="side_system">0.00</span> ج.م</td></tr>
                            <tr><td class="k">كاش في الدرج</td><td class="v"><span class="side_cash">0.00</span> ج.م</td></tr>
                            <tr><td class="k">مصروفات الدرج</td><td class="v" style="color:var(--neg);"><span class="side_expenses">0.00</span> ج.م</td></tr>
                            <tr><td class="k">فيزا</td><td class="v"><span class="side_visa">0.00</span> ج.م</td></tr>
                            <tr><td class="k">إنستا باي</td><td class="v"><span class="side_instapay">0.00</span> ج.م</td></tr>
                            <tr class="grand"><td>إجمالي فعلي</td><td class="v"><span class="side_collected">0.00</span> ج.م</td></tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- الملخص (شامل السيستم) -->
        <div class="ct-pane" id="pane_summary">
            <div class="grid-top">
                <div class="fld">
                    <label for="systemTotal">إجمالي السيستم</label>
                    <input type="number" step="0.01" min="0.01" name="system_total" id="systemTotal" value="" placeholder="0.00" oninput="calc()" required>
                </div>
            </div>
            <div class="sum-layout">
                <div class="sum-detail">
                    <div class="sdt">تفاصيل التقفيل</div>
                    <table>
                        <tr><td class="k">مبيعات السيستم</td><td class="v"><span id="s_system">0.00</span> ج.م</td></tr>
                        <tr><td class="k">كاش في الدرج</td><td class="v"><span id="s_cash">0.00</span> ج.م</td></tr>
                        <tr><td class="k">مصروفات الدرج</td><td class="v" style="color:var(--neg);"><span id="s_expenses">0.00</span> ج.م</td></tr>
                        <tr><td class="k">فيزا</td><td class="v"><span id="s_visa">0.00</span> ج.م</td></tr>
                        <tr><td class="k">إنستا باي</td><td class="v"><span id="s_instapay">0.00</span> ج.م</td></tr>
                        <tr class="grand"><td>إجمالي فعلي</td><td class="v"><span id="s_collected">0.00</span> ج.م</td></tr>
                    </table>
                </div>
                <div class="sum-diff-box">
                    <div class="sdl">الفرق</div>
                    <div class="sdv" id="s_diff">0.00 ج.م</div>
                    <div class="sds" id="s_diff_status">—</div>
                </div>
            </div>
        </div>

        <div style="margin-top:16px;display:flex;gap:8px;">
            <button type="submit" class="btn" id="shiftSubmitBtn"><i class="ti ti-lock"></i> <span>تقفيل وإرسال للمراجعة</span></button>
            <button type="button" class="btn btn-ghost" onclick="closeShiftModal()">إلغاء</button>
            <button type="button" class="btn btn-ghost" id="cancelEdit" style="display:none;" onclick="resetForm()">إلغاء التعديل</button>
        </div>
    </form>
    </div>
  </div>
</div>

<!-- قالب سطر المصروف الديناميكي -->
<template id="expRowTpl">
    <tr>
        <td class="exp-row-num" style="color:var(--ink-500);font-size:13px;text-align:center;"></td>
        <td style="padding:4px;">
            <?= acctree_field('exp_account_id[]', null, '— اختر الحساب —', 'custody') ?>
        </td>
        <td style="padding:4px;">
            <input type="number" step="0.01" min="0" name="exp_amount[]" class="amt exp-amt"
                   placeholder="0" oninput="calc()" style="width:100%;padding:8px 10px;border:1.5px solid var(--line);border-radius:8px;font-size:14px;font-family:inherit;box-sizing:border-box;text-align:center;">
        </td>
        <td style="padding:4px;">
            <input type="text" name="exp_note[]" class="exp-note" placeholder="ملاحظة (بنزين، مياه...)"
                   style="width:100%;padding:8px 10px;border:1.5px solid var(--line);border-radius:8px;font-size:14px;font-family:inherit;box-sizing:border-box;">
        </td>
        <td style="padding:4px;text-align:center;">
            <button type="button" onclick="removeExpRow(this)" title="حذف المصروف"
                    style="background:none;border:none;cursor:pointer;color:var(--neg);font-size:16px;padding:4px 6px;border-radius:6px;line-height:1;">
                <i class="ti ti-trash"></i>
            </button>
        </td>
    </tr>
</template>

<!-- كارت مطلوب ردك لمشاكل تسلسل الورديات -->
<?php if (!empty($mySeqIssues)): ?>
<div class="card" style="border-right:4px solid var(--warn);">
    <div class="section-title" style="color:var(--warn);"><i class="ti ti-alert-triangle"></i> مطلوب ردك على مشاكل تسلسل الورديات</div>
    <?php foreach ($mySeqIssues as $iss):
        $desc = $iss['kind'] === 'duplicate'
            ? "رقم الوردية {$iss['found_no']} اتكرر في فرع {$iss['branch_name']}"
            : "فجوة في أرقام الورديات بفرع {$iss['branch_name']}: المتوقع كان {$iss['expected_no']} لكن جه {$iss['found_no']}";
    ?>
        <div id="seq_card_<?= (int)$iss['id'] ?>" style="background:var(--bg);border-radius:10px;padding:14px;margin-bottom:12px;">
            <div style="font-weight:600;font-size:14px;margin-bottom:6px;"><?= e($desc) ?></div>
            <?php if (!empty($iss['owner_note'])): ?>
                <div style="font-size:13px;color:var(--ink-600);margin-bottom:8px;background:var(--surface);padding:8px 10px;border-radius:6px;">
                    <b>ملاحظة:</b> <?= e($iss['owner_note']) ?>
                </div>
            <?php endif; ?>
            <form method="post" style="margin-top:8px;">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="seq_reply">
                <input type="hidden" name="issue_id" value="<?= (int)$iss['id'] ?>">
                <div style="margin-bottom:8px;">
                    <textarea name="reply" required rows="2" placeholder="اكتب ردك وتوضيحك هنا..." style="width:100%;padding:8px;border:1.5px solid var(--line);border-radius:8px;font-family:inherit;font-size:13px;"></textarea>
                </div>
                <button type="submit" class="btn btn-sm" style="background:var(--accent);color:#fff;border:none;border-radius:7px;padding:6px 14px;cursor:pointer;">ابعت الرد</button>
            </form>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- تقفيلاتي المرفوضة -->
<?php if (!empty($myRejected)): ?>
<div class="card">
    <div class="section-title">تقفيلاتي المرفوضة (اضغط تعديل لإعادة الإرسال)</div>
    <table>
        <thead><tr><th>التاريخ</th><th>رقم الوردية</th><th>الفرع</th><th>المحصّل</th><th>الفرق</th><th>سبب الرفض</th><th>إجراء</th></tr></thead>
        <tbody>
        <?php foreach ($myRejected as $s): ?>
            <?php
                $sid = (int)$s['id'];
                $sExps = $shiftExpensesMap[$sid] ?? [];
                $sExpTotal = array_sum(array_column($sExps, 'amount'));
                $collected = (float)$s['cash_total'] + $sExpTotal + (float)$s['visa_total'] + (float)$s['instapay_total'];
                $isExpired = !empty($s['is_return_expired'])
                    || !empty($s['return_expired_at'])
                    || (isset($s['hours_left']) && (int)$s['hours_left'] < 0);
                $hrsLeft = max(0, (int)($s['hours_left'] ?? 0));
            ?>
            <tr>
                <td><?= e($s['business_date']) ?></td>
                <td class="csh-shift-name">
                    <?= $s['shift_name'] !== null && $s['shift_name'] !== '' ? e($s['shift_name']) : '—' ?>
                    <?php if (!empty($shiftEditBadges[(int)$s['id']])): ?>
                        <div class="csh-edit-badge">اتعدّل بواسطة <?= e($shiftEditBadges[(int)$s['id']]['name']) ?> · <?= e($shiftEditBadges[(int)$s['id']]['time']) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= e($s['branch_name']) ?></td>
                <td><?= number_format($collected,2) ?></td>
                <td class="<?= $s['difference']>=0?'diff-pos':'diff-neg' ?>"><?= number_format((float)$s['difference'],2) ?></td>
                <td><span class="reject-reason"><?= e($s['reject_reason'] ?? '—') ?></span></td>
                <td>
                    <?php if ($isExpired): ?>
                        <span class="badge" style="color:var(--neg);background:var(--neg-soft);">عدّت المهلة</span>
                    <?php else: ?>
                        <button class="btn btn-sm" onclick='editShift(<?= (int)$s['id'] ?>)'>تعديل</button>
                        <?php if (isset($s['hours_left']) && $s['hours_left'] !== null): ?>
                        <span style="font-size:12px;color:var(--ink-500);margin-inline-start:4px;">فاضل <?= $hrsLeft ?> ساعة</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- تقفيلات اليوم -->
<div class="card">
    <div class="section-title">تقفيلات اليوم (<?= e($bizDateStr) ?>)</div>
    <?php if (empty($todayShifts)): ?>
        <div class="empty">لا توجد تقفيلات اليوم بعد.</div>
    <?php else: ?>
    <table>
        <thead><tr>
            <th>التاريخ</th><th>الكاشير</th><th>الفرع</th><th>رقم الوردية</th><th>السيستم</th>
            <th>كاش</th><th>فيزا</th><th>إنستا</th><th>مصروفات</th>
            <th>المحصّل</th><th>الفرق</th><th>الحالة</th><th>الوقت</th><th>إجراءات</th>
        </tr></thead>
        <tbody>
        <?php
            foreach ($todayShifts as $s):
                $sid=(int)$s['id'];
                $cash=(float)$s['cash_total']; $visa=(float)$s['visa_total'];
                $insta=(float)$s['instapay_total'];
                $sExps = $shiftExpensesMap[$sid] ?? [];
                $sExpTotal = array_sum(array_column($sExps, 'amount'));
                $sys=(float)$s['system_total']; $collected=$cash+$sExpTotal+$visa+$insta; $diff=(float)$s['difference'];
                $sl = $statusLabels[$s['status']] ?? ['—','var(--ink-500)','var(--line)'];
        ?>
            <tr>
                <td><?= e($s['business_date']) ?></td>
                <td><?= e($s['cashier_name']) ?></td>
                <td><?= e($s['branch_name']) ?></td>
                <td class="csh-shift-name">
                    <?= $s['shift_name'] !== null && $s['shift_name'] !== '' ? e($s['shift_name']) : '—' ?>
                    <?php if (!empty($shiftEditBadges[(int)$s['id']])): ?>
                        <div class="csh-edit-badge">اتعدّل بواسطة <?= e($shiftEditBadges[(int)$s['id']]['name']) ?> · <?= e($shiftEditBadges[(int)$s['id']]['time']) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= number_format($sys,2) ?></td>
                <td><?= number_format($cash,2) ?></td>
                <td><?= number_format($visa,2) ?></td>
                <td><?= number_format($insta,2) ?></td>
                <td>
                    <?php if ($sExpTotal > 0): ?>
                        <span style="color:var(--neg);font-weight:600;"><?= number_format($sExpTotal,2) ?></span>
                        <div style="font-size:11px;color:var(--ink-400);">
                            <?php foreach ($sExps as $x): ?>
                                <div><?= e($x['account_name'] ?: 'مصروف') ?>: <?= number_format((float)$x['amount'],2) ?><?= !empty($x['note']) ? ' (' . e($x['note']) . ')' : '' ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <span style="color:var(--ink-300);">—</span>
                    <?php endif; ?>
                </td>
                <td style="font-weight:600;"><?= number_format($collected,2) ?></td>
                <td class="<?= $diff>=0?'diff-pos':'diff-neg' ?>"><?= number_format($diff,2) ?></td>
                <td><span class="badge" style="color:<?= $sl[1] ?>;background:<?= $sl[2] ?>;"><?= e($sl[0]) ?></span></td>
                <td style="color:var(--ink-400);font-size:12px;white-space:nowrap;"><?= e(date('H:i', strtotime($s['created_at']))) ?></td>
                <td>
                    <?php if (in_array($s['status'], ['submitted','resubmitted'], true) && (is_owner() || (int)$s['user_id'] === $uid)): ?>
                        <?php if (is_owner()): ?>
                            <button type="button" class="btn btn-sm" onclick="ownerEditShift(<?= (int)$s['id'] ?>)" title="تعديل الوردية">
                                <i class="ti ti-edit"></i> تعديل
                            </button>
                        <?php endif; ?>
                        <form method="post" style="display:inline;"
                              data-confirm="تأكيد مسح التقفيلة دي؟ هتقدر تعملها من جديد." data-confirm-danger>
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete_shift">
                            <input type="hidden" name="shift_id" value="<?= (int)$s['id'] ?>">
                            <button class="btn btn-sm" style="background:var(--neg-soft);color:var(--neg);" title="مسح التقفيلة">
                                <i class="ti ti-trash"></i> مسح
                            </button>
                        </form>
                    <?php else: ?>
                        <span style="color:var(--ink-300);">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
            <!-- صف الإجماليات بالعرض -->
            <tr class="totals-row">
                <td colspan="4">إجمالي ورديات اليوم</td>
                <td><?= number_format($sumSys,2) ?></td>
                <td><?= number_format($sumCash,2) ?></td>
                <td><?= number_format($sumVisa,2) ?></td>
                <td><?= number_format($sumInsta,2) ?></td>
                <td style="color:var(--neg);"><?= number_format($sumExp,2) ?></td>
                <td><?= number_format($sumColl,2) ?></td>
                <td class="<?= $sumDiff>=0?'diff-pos':'diff-neg' ?>"><?= number_format($sumDiff,2) ?></td>
                <td colspan="3"></td>
            </tr>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- الأرشيف: تقفيلات سابقة (المعتمدة اختفت، المرفوضة تظهر) -->
<?php if (!empty($archiveShifts)): ?>
<div class="card">
    <div class="section-title">الأرشيف (تقفيلات سابقة)</div>
    <table>
        <thead><tr>
            <th>التاريخ</th><th>الكاشير</th><th>الفرع</th><th>رقم الوردية</th><th>السيستم</th>
            <th>كاش</th><th>فيزا</th><th>إنستا</th><th>مصروفات</th>
            <th>المحصّل</th><th>الفرق</th><th>الحالة</th><th>الوقت</th>
            <?php if (is_owner()): ?><th data-xt-skip>الإجراءات</th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($archiveShifts as $s): ?>
            <?php
                $sid=(int)$s['id'];
                $cash=(float)$s['cash_total']; $visa=(float)$s['visa_total'];
                $insta=(float)$s['instapay_total'];
                $sExps = $shiftExpensesMap[$sid] ?? [];
                $sExpTotal = array_sum(array_column($sExps, 'amount'));
                $sys=(float)$s['system_total']; $collected=$cash+$sExpTotal+$visa+$insta;
                $sl = $statusLabels[$s['status']] ?? ['—','var(--ink-500)','var(--line)'];
            ?>
            <tr>
                <td><?= e($s['business_date']) ?></td>
                <td><?= e($s['cashier_name']) ?></td>
                <td><?= e($s['branch_name']) ?></td>
                <td class="csh-shift-name">
                    <?= $s['shift_name'] !== null && $s['shift_name'] !== '' ? e($s['shift_name']) : '—' ?>
                    <?php if (!empty($shiftEditBadges[(int)$s['id']])): ?>
                        <div class="csh-edit-badge">اتعدّل بواسطة <?= e($shiftEditBadges[(int)$s['id']]['name']) ?> · <?= e($shiftEditBadges[(int)$s['id']]['time']) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= number_format($sys,2) ?></td>
                <td><?= number_format($cash,2) ?></td>
                <td><?= number_format($visa,2) ?></td>
                <td><?= number_format($insta,2) ?></td>
                <td>
                    <?php if ($sExpTotal > 0): ?>
                        <span style="color:var(--neg);font-weight:600;"><?= number_format($sExpTotal,2) ?></span>
                        <div style="font-size:11px;color:var(--ink-400);">
                            <?php foreach ($sExps as $x): ?>
                                <div><?= e($x['account_name'] ?: 'مصروف') ?>: <?= number_format((float)$x['amount'],2) ?><?= !empty($x['note']) ? ' (' . e($x['note']) . ')' : '' ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <span style="color:var(--ink-300);">—</span>
                    <?php endif; ?>
                </td>
                <td style="font-weight:600;"><?= number_format($collected,2) ?></td>
                <td class="<?= $s['difference']>=0?'diff-pos':'diff-neg' ?>"><?= number_format((float)$s['difference'],2) ?></td>
                <td><span class="badge" style="color:<?= $sl[1] ?>;background:<?= $sl[2] ?>;"><?= e($sl[0]) ?></span></td>
                <td style="color:var(--ink-400);font-size:12px;white-space:nowrap;"><?= e(date('H:i', strtotime($s['created_at']))) ?></td>
                <?php if (is_owner()): ?>
                <td>
                    <?php if (in_array($s['status'], ['submitted','resubmitted'], true)): ?>
                        <button type="button" class="btn btn-sm" onclick="ownerEditShift(<?= (int)$s['id'] ?>)" title="تعديل الوردية">
                            <i class="ti ti-edit"></i> تعديل
                        </button>
                        <form method="post" style="display:inline;"
                              data-confirm="تأكيد مسح التقفيلة دي؟ هتقدر تعملها من جديد." data-confirm-danger>
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete_shift">
                            <input type="hidden" name="shift_id" value="<?= (int)$s['id'] ?>">
                            <button class="btn btn-sm" style="background:var(--neg-soft);color:var(--neg);" title="مسح التقفيلة">
                                <i class="ti ti-trash"></i> مسح
                            </button>
                        </form>
                    <?php else: ?>
                        <span style="color:var(--ink-300);">—</span>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?= acctree_data(true) ?>
<script src="assets/acctree.js?v=4"></script>
<script>
    const denoms = <?= json_encode($denominations) ?>;
    const methods = ['visa','instapay'];

    // تبديل تبويبات التقفيل
    function ctTab(btn) {
        document.querySelectorAll('.ct-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.ct-pane').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById(btn.dataset.pane).classList.add('active');
    }

    function addExpRow(accountId, amount, note) {
        const tpl = document.getElementById('expRowTpl');
        if (!tpl) return;
        const tbody = document.getElementById('expRowsTbody');
        const clone = tpl.content.cloneNode(true);
        const tr = clone.querySelector('tr');
        const num = tbody.querySelectorAll('tr').length + 1;
        const numCell = tr.querySelector('.exp-row-num');
        if (numCell) numCell.textContent = num;

        if (amount) {
            tr.querySelector('.exp-amt').value = amount;
        }
        if (note) {
            tr.querySelector('.exp-note').value = note;
        }
        tbody.appendChild(tr);

        if (window.acctreeScan) {
            window.acctreeScan();
        }
        if (accountId) {
            const actRoot = tr.querySelector('[data-acctree]');
            if (actRoot && window.acctreeSet) {
                window.acctreeSet(actRoot, accountId);
            }
        }
        calc();
    }

    function removeExpRow(btn) {
        const tr = btn.closest('tr');
        if (tr) {
            const tbody = tr.closest('tbody');
            tr.remove();
            if (tbody) {
                tbody.querySelectorAll('tr').forEach((row, idx) => {
                    const cell = row.querySelector('.exp-row-num');
                    if (cell) cell.textContent = idx + 1;
                });
            }
            calc();
        }
    }

    function calc() {
        let cash = 0;
        const breakParts = [];
        denoms.forEach(d => {
            const inp = document.querySelector('.denom-input[data-denom="'+d+'"]');
            const c = parseInt(inp.value) || 0;
            const sub = d * c;
            cash += sub;
            document.getElementById('dsub_'+d).textContent = sub.toFixed(2);
            if (c > 0) breakParts.push(c + ' × ' + d + ' = ' + sub.toFixed(2));
        });
        const mt = {};
        methods.forEach(m => {
            let t = 0;
            document.querySelectorAll('.'+m+'-amt').forEach(a => t += parseFloat(a.value)||0);
            mt[m] = t;
        });

        let expTotal = 0;
        document.querySelectorAll('.exp-amt').forEach(a => {
            expTotal += parseFloat(a.value) || 0;
        });

        // إجمالي الكاش + تفصيله
        document.getElementById('t_cash').textContent = cash.toFixed(2);
        const cb = document.getElementById('cashBreak');
        if (cb) cb.innerHTML = breakParts.join('<br>');

        const collected = cash + expTotal + mt.visa + mt.instapay;
        const sys = parseFloat(document.getElementById('systemTotal').value) || 0;
        const diff = collected - sys;

        // ملخص التفاصيل
        setTxt('s_system', sys.toFixed(2));
        setTxt('s_cash', cash.toFixed(2));
        setTxt('s_expenses', expTotal.toFixed(2));
        setTxt('s_visa', mt.visa.toFixed(2));
        setTxt('s_instapay', mt.instapay.toFixed(2));
        setTxt('s_collected', collected.toFixed(2));

        // الملخص الجانبي في تابات الدفع (نفس الأرقام)
        setTxtAll('side_system', sys.toFixed(2));
        setTxtAll('side_cash', cash.toFixed(2));
        setTxtAll('side_expenses', expTotal.toFixed(2));
        setTxtAll('side_visa', mt.visa.toFixed(2));
        setTxtAll('side_instapay', mt.instapay.toFixed(2));
        setTxtAll('side_collected', collected.toFixed(2));

        // بوكس الفرق
        const dv = document.getElementById('s_diff');
        const ds = document.getElementById('s_diff_status');
        if (dv) {
            dv.textContent = diff.toFixed(2) + ' ج.م';
            if (Math.abs(diff) < 0.01) {
                dv.style.color = 'var(--pos)'; if(ds) ds.textContent = '✓ مطابق تماماً';
            } else if (diff > 0) {
                dv.style.color = 'var(--warn)'; if(ds) ds.textContent = 'زيادة ' + diff.toFixed(2) + ' ج.م';
            } else {
                dv.style.color = 'var(--neg)'; if(ds) ds.textContent = 'عجز ' + Math.abs(diff).toFixed(2) + ' ج.م';
            }
        }
    }

    function setTxt(id, txt) {
        const el = document.getElementById(id);
        if (el) el.textContent = txt;
    }

    function setTxtAll(cls, txt) {
        document.querySelectorAll('.' + cls).forEach(el => el.textContent = txt);
    }

    // فتح وقفل بوب أب التقفيل
    function openShiftModal() {
        if (document.getElementById('formAction').value === 'submit_shift') {
            const cName = document.getElementById('cashierName');
            if (cName && cName.dataset.self) cName.value = cName.dataset.self;
            const mTitle = document.getElementById('shiftModalTitle');
            if (mTitle) mTitle.textContent = 'تقفيل وردية جديدة';
            const sBtn = document.getElementById('shiftSubmitBtn');
            if (sBtn) sBtn.innerHTML = '<i class="ti ti-lock"></i> <span>تقفيل وإرسال للمراجعة</span>';
            const oNotice = document.getElementById('ownerEditNotice');
            if (oNotice) oNotice.style.display = 'none';
        }
        document.getElementById('shiftModal').classList.add('open');
        document.body.style.overflow = 'hidden';
    }
    function closeShiftModal() {
        document.getElementById('shiftModal').classList.remove('open');
        document.body.style.overflow = '';
    }

    // تعديل وردية: نجيب تفاصيلها بـ AJAX بسيط
    function editShift(id, action = 'resubmit_shift') {
        fetch('shift_data.php?id=' + id)
            .then(r => r.json())
            .then(data => {
                if (data.error) { fgToast(data.error, 'err'); return; }
                document.getElementById('formAction').value = action;
                document.getElementById('shiftId').value = id;
                document.querySelector('[name="branch_id"]').value = data.branch_id;
                document.querySelector('[name="business_date"]').value = data.business_date;
                document.getElementById('shiftName').value = data.shift_name || '';
                document.getElementById('systemTotal').value = data.system_total;

                const cName = document.getElementById('cashierName');
                if (cName && data.cashier_name) {
                    cName.value = data.cashier_name;
                }
                const mTitle = document.getElementById('shiftModalTitle');
                const sBtn = document.getElementById('shiftSubmitBtn');
                const oNotice = document.getElementById('ownerEditNotice');
                const oCName = document.getElementById('ownerEditCashierName');
                const sName = data.shift_name ? data.shift_name : id;

                if (action === 'owner_edit_shift') {
                    if (mTitle) mTitle.textContent = 'تعديل وردية ' + sName;
                    if (sBtn) sBtn.innerHTML = '<i class="ti ti-edit"></i> <span>حفظ التعديل</span>';
                    if (oNotice) {
                        if (oCName) oCName.textContent = data.cashier_name || '';
                        oNotice.style.display = 'block';
                    }
                } else {
                    if (mTitle) mTitle.textContent = 'تعديل الوردية المرجوعة';
                    if (sBtn) sBtn.innerHTML = '<i class="ti ti-lock"></i> <span>تقفيل وإرسال للمراجعة</span>';
                    if (oNotice) oNotice.style.display = 'none';
                }

                // تصفير ثم ملء الفئات
                denoms.forEach(d => {
                    const inp = document.querySelector('.denom-input[data-denom="'+d+'"]');
                    if (inp) {
                        const cnt = data.cash[d] || 0;
                        inp.value = cnt > 0 ? cnt : '';
                    }
                });
                // تصفير ثم ملء السطور (تصفير كل خانات السطور في الفورم)
                document.querySelectorAll('.pay-table input.ref, .pay-table input.amt').forEach(inp => {
                    inp.value = '';
                });
                (data.lines||[]).forEach(ln => {
                    const amt = document.querySelector('[name="'+ln.method+'['+ln.line_no+']"]');
                    const ref = document.querySelector('[name="'+ln.method+'_ref['+ln.line_no+']"]');
                    if(amt) amt.value = ln.amount;
                    if(ref) ref.value = ln.reference || '';
                });

                // ملء المصروفات
                const tbody = document.getElementById('expRowsTbody');
                if (tbody) {
                    tbody.innerHTML = '';
                    const exps = data.expenses || [];
                    exps.forEach(ex => {
                        addExpRow(ex.account_id, ex.amount, ex.note);
                    });
                    const currentCount = tbody.querySelectorAll('tr').length;
                    for (let i = currentCount + 1; i <= 10; i++) {
                        addExpRow();
                    }
                }

                document.getElementById('cancelEdit').style.display = 'inline-flex';
                calc();
                openShiftModal();
            })
            .catch(() => fgToast('تعذّر تحميل بيانات الوردية.', 'err'));
    }

    function ownerEditShift(id) {
        editShift(id, 'owner_edit_shift');
    }

    function resetForm() {
        document.getElementById('shiftForm').reset();
        document.getElementById('formAction').value = 'submit_shift';
        document.getElementById('shiftId').value = '';
        document.getElementById('cancelEdit').style.display = 'none';
        const tbody = document.getElementById('expRowsTbody');
        if (tbody) {
            tbody.innerHTML = '';
            for (let i = 1; i <= 10; i++) {
                addExpRow();
            }
        }
        const cName = document.getElementById('cashierName');
        if (cName && cName.dataset.self) {
            cName.value = cName.dataset.self;
        }
        const mTitle = document.getElementById('shiftModalTitle');
        if (mTitle) mTitle.textContent = 'تقفيل وردية جديدة';
        const sBtn = document.getElementById('shiftSubmitBtn');
        if (sBtn) sBtn.innerHTML = '<i class="ti ti-lock"></i> <span>تقفيل وإرسال للمراجعة</span>';
        const oNotice = document.getElementById('ownerEditNotice');
        if (oNotice) oNotice.style.display = 'none';
        calc();
        closeShiftModal();
    }
    calc();

    // Enter = انتقال للحقل اللي بعده (مش تقفيل الوردية).
    //
    // قبل كده كان Enter ممنوع تمامًا عشان مايقفلش الوردية بالغلط،
    // بس ده خلّى الناس تدوس Enter وتستنى ينقلها ومايحصلش حاجة.
    // دلوقتي بينقل عادي، والتقفيل بالزرار بس.
    (function(){
        var form = document.getElementById('shiftForm');
        if (!form) return;

        form.addEventListener('keydown', function(e){
            if (e.key !== 'Enter' && e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;

            var el = e.target;
            if (el.tagName === 'TEXTAREA') return;          // سيب الـtextarea عادي
            if (el.tagName !== 'INPUT' && el.tagName !== 'SELECT') return;

            e.preventDefault();   // مهم: يمنع إرسال الفورم

            // كل الحقول القابلة للكتابة والظاهرة حاليًا (التاب المفتوح بس)
            var all = Array.prototype.filter.call(
                form.querySelectorAll('input:not([type=hidden]), select, textarea'),
                function(x){
                    return !x.disabled && !x.readOnly && x.offsetParent !== null;
                }
            );

            var i = all.indexOf(el);
            if (i === -1) return;

            var next = (e.key === 'ArrowUp') ? all[i - 1] : all[i + 1];
            if (!next) return;

            next.focus();
            if (next.select) next.select();   // يحدد المحتوى عشان الكتابة فوقه على طول
        });
    })();

    <?php if ($autoOpenShiftId > 0): ?>
    document.addEventListener('DOMContentLoaded', function() {
        editShift(<?= (int)$autoOpenShiftId ?>);
    });
    <?php endif; ?>

    <?php if (!empty($_GET['seq_issue'])): ?>
    document.addEventListener('DOMContentLoaded', function() {
        var el = document.getElementById('seq_card_<?= (int)$_GET['seq_issue'] ?>');
        if (el) el.scrollIntoView({ behavior: 'smooth' });
    });
    <?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
