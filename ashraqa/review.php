<?php
/**
 * review.php — مراجعة المحاسب (شكل جديد: كروت + بوب أب بتابات + مقارنة)
 * نظام الفجالة ستور المالي
 *
 * - أرقام الكاشير ظاهرة للمحاسب في كل التابات (شريط أعلى البوب أب).
 * - تابات: كاش (فئات) + فيزا/إنستا (سطور) + مقارنة.
 * - الاعتماد فقط → الأرقام تدخل الخزائن (عبر save_shift_review). أما الرفض فلا يحرّك أي فلوس.
 */
require_once __DIR__ . '/auth.php';
require_permission('review');
require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/push_lib.php';

$pdo  = db();
$uid  = (int) current_user()['id'];
$flash = ''; $flashType = 'success';

// فحص الورديات المرجوعة اللي عدّت مهلتها (٤٨ ساعة) آلياً
try {
    shift_returns_expire($pdo);
} catch (Throwable $e) {
    error_log("shift_returns_expire in review: " . $e->getMessage());
}

$denominations = [200, 100, 50, 20, 10, 5, 1, 0.5, 0.25];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $flash = 'انتهت صلاحية الجلسة، حاول مرة أخرى.';
        $flashType = 'error';
    } else {
        try {
            // 🔴 الصفحة فيها فورمين دلوقتي: مراجعة وردية ومراجعة عهدة.
            //    من غير التفريع ده، مراجعة العهدة كانت هتروح لـsave_shift_review()
            //    وتتعامل مع custody_id على إنه shift_id — يعني تعتمد وردية
            //    مالهاش دعوة وتحرّك فلوسها.
            if (($_POST['kind'] ?? 'shift') === 'custody') {
                $custodyId  = (int) ($_POST['custody_id'] ?? 0);
                $cDecision  = $_POST['decision'] ?? '';
                $cReason    = trim($_POST['reason'] ?? '');
                $rawCounted = trim((string) ($_POST['acc_counted'] ?? ''));

                if ($cDecision === 'approved') {
                    // ★ الصفر **مسموح** هنا — يعني العهدة رجعت فاضية، ودي حالة
                    //   حقيقية. الوردية بتمنع الصفر لأن مبيعات بصفر معناها
                    //   الفرق = المحصّل كله، وده منطق تاني خالص.
                    if ($rawCounted === '' || !is_numeric($rawCounted) || (float) $rawCounted < 0) {
                        throw new RuntimeException('اكتب المبلغ اللي استلمته قبل الاعتماد.');
                    }
                } elseif ($cDecision === 'rejected') {
                    if ($cReason === '') {
                        throw new RuntimeException('اكتب سبب الرفض.');
                    }
                } else {
                    throw new RuntimeException('قرار المراجعة غير صحيح.');
                }

                save_custody_review(
                    $custodyId,
                    $cDecision === 'approved' ? (float) $rawCounted : 0.0,
                    $cDecision,
                    $cReason ?: null,
                    $uid
                );
                // 🔴 مش زي الوردية: رفض العهدة **مفيش فيه أي حركة مالية**.
                $flash = $cDecision === 'approved'
                    ? 'تم اعتماد العهدة والفلوس دخلت الخزينة.'
                    : 'تم رفض العهدة ورجعت للكاشير. مفيش فلوس اتحرّكت.';
            } elseif (($_POST['action'] ?? '') === 'shift_date_branch') {
                $shiftId = (int) ($_POST['shift_id'] ?? 0);
                $bDate   = trim((string)($_POST['business_date'] ?? ''));
                $bBranch = (int) ($_POST['branch_id'] ?? 0);
                update_shift_date_branch($shiftId, $bDate, $bBranch, $uid);
                $flash = 'اتغيّر تاريخ/فرع الوردية.';
            } elseif (($_POST['action'] ?? '') === 'seq_ignore') {
                if (!can_edit_shift_date_branch()) {
                    throw new RuntimeException('غير مصرح لك بتنفيذ هذه العملية.');
                }
                $issueId = (int) ($_POST['issue_id'] ?? 0);
                $ownerNote = trim((string)($_POST['owner_note'] ?? ''));
                if ($ownerNote === '') {
                    throw new RuntimeException('سبب التغاضي إجباري.');
                }
                $stmt = $pdo->prepare(
                    "UPDATE shift_sequence_issues 
                     SET status = 'ignored', owner_note = ?, resolved_by = ?, resolved_at = NOW() 
                     WHERE id = ? AND status = 'open'"
                );
                $stmt->execute([$ownerNote, $uid, $issueId]);
                $flash = 'تم التغاضي عن مشكلة التسلسل.';
            } elseif (($_POST['action'] ?? '') === 'seq_send') {
                if (!can_edit_shift_date_branch()) {
                    throw new RuntimeException('غير مصرح لك بتنفيذ هذه العملية.');
                }
                $issueId = (int) ($_POST['issue_id'] ?? 0);
                $assignedUserId = (int) ($_POST['assigned_user_id'] ?? 0);
                $ownerNote = trim((string)($_POST['owner_note'] ?? ''));
                if ($assignedUserId <= 0) {
                    throw new RuntimeException('اختر الموظف لإرسال المشكلة.');
                }
                $stmtChk = $pdo->prepare("SELECT id, full_name FROM users WHERE id = ? AND is_active = 1");
                $stmtChk->execute([$assignedUserId]);
                $assignee = $stmtChk->fetch(PDO::FETCH_ASSOC);
                if (!$assignee) {
                    throw new RuntimeException('الموظف المحدد غير موجود أو غير نشط.');
                }
                $stmt = $pdo->prepare(
                    "UPDATE shift_sequence_issues 
                     SET status = 'sent', assigned_user_id = ?, owner_note = ? 
                     WHERE id = ? AND status = 'open'"
                );
                $stmt->execute([$assignedUserId, $ownerNote ?: null, $issueId]);

                try {
                    $stmtIss = $pdo->prepare("SELECT * FROM shift_sequence_issues WHERE id = ?");
                    $stmtIss->execute([$issueId]);
                    $iss = $stmtIss->fetch(PDO::FETCH_ASSOC);
                    if ($iss) {
                        $desc = ($iss['kind'] === 'duplicate')
                            ? "رقم {$iss['found_no']} اتكرر"
                            : "بعد " . ($iss['expected_no'] - 1) . " جه {$iss['found_no']}";
                        $noteMsg = $ownerNote !== '' ? " — {$ownerNote}" : '';
                        notify(
                            $pdo,
                            [$assignedUserId],
                            'shift_seq',
                            'مطلوب ردك على مشكلة في تسلسل الورديات',
                            "{$desc}{$noteMsg}",
                            "cashier.php?seq_issue={$issueId}"
                        );
                    }
                } catch (Throwable $e) {
                    error_log("notify shift_seq error: " . $e->getMessage());
                }
                $flash = 'تم إرسال مشكلة التسلسل للموظف.';
            } else {

            $shiftId  = (int) ($_POST['shift_id'] ?? 0);
            $decision = $_POST['decision'] ?? '';
            $reason   = trim($_POST['reason'] ?? '');

            // ★ قيمة السيستم إجباري عند الاعتماد.
            //   من غيرها الفرق بيتحسب على أساس صفر، والقيود تدخل الخزائن غلط.
            //   (عند الرفض مش إجباري — ممكن يرفض عشان الأرقام ناقصة أصلًا.)
            $rawSys = trim((string)($_POST['acc_system'] ?? ''));
            if ($decision === 'approved') {
                if ($rawSys === '' || !is_numeric($rawSys)) {
                    throw new RuntimeException('اكتب قيمة السيستم قبل الاعتماد.');
                }
                // 🔴 الصفر ممنوع: `is_numeric('0')` بترجّع true، والصفر معناه
                //    الفرق = المحصّل كله = عجز وهمي بحجم الوردية.
                //    نفس شرط التقفيل بالظبط (cashier.php:54).
                if ((float) $rawSys <= 0) {
                    throw new RuntimeException('قيمة السيستم لازم تكون أكبر من صفر.');
                }
            }

            $amounts = [
                'cash'     => (float) ($_POST['acc_cash'] ?? 0),
                'visa'     => (float) ($_POST['acc_visa'] ?? 0),
                'instapay' => (float) ($_POST['acc_instapay'] ?? 0),
                'system'   => (float) $rawSys,
            ];
            save_shift_review($shiftId, $amounts, $decision, $reason ?: null, $uid);

            if ($decision === 'rejected') {
                try {
                    $stmtShift = $pdo->prepare("SELECT user_id, shift_name FROM shifts WHERE id = ? LIMIT 1");
                    $stmtShift->execute([$shiftId]);
                    $rejShift = $stmtShift->fetch(PDO::FETCH_ASSOC);
                    if ($rejShift && !empty($rejShift['user_id'])) {
                        $hours = (int) setting('shift_return_hours', 48);
                        $sName = ($rejShift['shift_name'] !== null && $rejShift['shift_name'] !== '') ? $rejShift['shift_name'] : (string)$shiftId;
                        notify(
                            $pdo,
                            [(int)$rejShift['user_id']],
                            'shift_return',
                            "ورديتك رقم {$sName} رجعت للتعديل",
                            "السبب: " . ($reason ?: 'بدون سبب') . " — قدامك {$hours} ساعة تعدّلها وتبعتها تاني",
                            "cashier.php?edit_shift={$shiftId}"
                        );
                    }
                } catch (Throwable $e) {
                    error_log("notify rejection error: " . $e->getMessage());
                }
            }

            $flash = $decision === 'approved'
                ? 'تم اعتماد الوردية ودخلت الأرقام الخزائن.'
                : 'تم رفض الوردية ورجعت للكاشير. مفيش فلوس اتحرّكت.';

            } // نهاية مسار الوردية
        } catch (Throwable $ex) {
            $flash = 'خطأ: ' . $ex->getMessage();
            $flashType = 'error';
        }
    }
    // PRG: بعد أي POST نعمل تحويل لصفحة GET عشان الـ Refresh ما يعيدش الإرسال (منع التكرار)
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

// الورديات المنتظرة للمراجعة
$pending = $pdo->query(
    "SELECT s.*, u.full_name AS cashier_name, b.name AS branch_name
     FROM shifts s
     JOIN users u ON u.id = s.user_id
     JOIN branches b ON b.id = s.branch_id
     WHERE s.status IN ('submitted','resubmitted')
     ORDER BY s.id DESC"
)->fetchAll();

// ===== تفاصيل الورديات المنتظرة — للمقارنة التفصيلية في البوب أب =====
// 🔴 استعلامين مجمّعين بس. أي prepare جوّه foreach هنا = غلط (RULES §٠/١).
$empCash  = [];   // [shift_id][denomination] => count
$empLines = [];   // [shift_id][method][] => ['no'=>..,'ref'=>..,'amt'=>..]
if ($pending) {
    $ids = array_column($pending, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));

    $q = $pdo->prepare(
        "SELECT shift_id, denomination, `count`
           FROM shift_cash
          WHERE shift_id IN ($in)"
    );
    $q->execute($ids);
    foreach ($q as $r) {
        $empCash[(int)$r['shift_id']][(string)(float)$r['denomination']] = (int)$r['count'];
    }

    $q = $pdo->prepare(
        "SELECT shift_id, method, line_no, reference, amount
           FROM shift_payment_lines
          WHERE shift_id IN ($in)
          ORDER BY shift_id, method, line_no"
    );
    $q->execute($ids);
    foreach ($q as $r) {
        $empLines[(int)$r['shift_id']][$r['method']][] = [
            'no'  => (int)$r['line_no'],
            'ref' => (string)($r['reference'] ?? ''),
            'amt' => (float)$r['amount'],
        ];
    }
}

// السجل (آخر 50 وردية تمت مراجعتها)
$history = $pdo->query(
    "SELECT s.*, u.full_name AS cashier_name, b.name AS branch_name
     FROM shifts s
     JOIN users u ON u.id = s.user_id
     JOIN branches b ON b.id = s.branch_id
     WHERE s.status IN ('approved','rejected')
     ORDER BY s.id DESC LIMIT 50"
)->fetchAll();

// جلب بادج «آخر تعديل» للورديات المعروضة باستعلام مجمّع واحد
$allReviewShiftIds = [];
foreach ($pending as $ps) $allReviewShiftIds[] = (int)$ps['id'];
foreach ($history as $hs) $allReviewShiftIds[] = (int)$hs['id'];
$allReviewShiftIds = array_values(array_unique(array_filter($allReviewShiftIds)));
$shiftExpensesMap = shift_expenses_for_shifts($allReviewShiftIds, $pdo);

$shiftEditBadges = [];
if (!empty($allReviewShiftIds)) {
    try {
        $phBadge = implode(',', array_fill(0, count($allReviewShiftIds), '?'));
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
        $stBadge->execute($allReviewShiftIds);
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

// ===== عهد الفروع والموظفين المقدّمة للمراجعة =====
// استعلام واحد جايب اسم الفرع واسم الموظف واسم اللي قدّم — مفيش prepare جوّه أي حلقة.
$pendingCustody = $pdo->query(
    "SELECT bc.*, b.name AS branch_name, u.full_name AS submitted_by_name,
            e.full_name AS employee_name
       FROM branch_custody bc
       LEFT JOIN branches b ON b.id = bc.branch_id
       LEFT JOIN users u ON u.id = bc.submitted_by
       LEFT JOIN employees e ON e.id = bc.employee_id
      WHERE bc.status = 'pending_review'
      ORDER BY bc.id DESC"
)->fetchAll();

// 🔴 `reviewed_at IS NOT NULL` مش زيادة — هي أهم شرط في الاستعلام ده.
//    العهد اللي اتقفلت **قبل** الترقية حالتها `closed` و`reviewed_at` فيها
//    NULL. من غير الشرط ده هتظهر في السجل كأن حد راجعها، وهي أصلاً اتقفلت
//    بالنظام القديم اللي كان بيقفل ويعمل القيد بضغطة واحدة.
$custodyHistory = $pdo->query(
    "SELECT bc.*, b.name AS branch_name, u.full_name AS reviewer_name,
            e.full_name AS employee_name
       FROM branch_custody bc
       LEFT JOIN branches b ON b.id = bc.branch_id
       LEFT JOIN users u ON u.id = bc.reviewed_by
       LEFT JOIN employees e ON e.id = bc.employee_id
      WHERE bc.status IN ('closed','rejected') AND bc.reviewed_at IS NOT NULL
      ORDER BY bc.reviewed_at DESC LIMIT 50"
)->fetchAll();

// الفروع المتاحة للاختيار (أي فرع نشط وله مبيعات — للتغطية)
try {
    $reviewBranches = $pdo->query('SELECT id, name FROM branches WHERE is_active=1 AND has_finance=1 AND has_sales=1 ORDER BY name')->fetchAll();
} catch (Throwable $e) {
    // استرجاع آمن قبل تطبيق ملف الترقية upgrade_has_sales.sql
    $reviewBranches = $pdo->query('SELECT id, name FROM branches WHERE is_active=1 AND has_finance=1 ORDER BY name')->fetchAll();
}

// حساب أعداد المعلق لكل فرع من المصفوفات المتحملة أصلاً (مفيش استعلام زيادة)
$reviewBranchCounts = [];
foreach ($pending as $s) {
    $bid = (int)$s['branch_id'];
    $reviewBranchCounts[$bid] = ($reviewBranchCounts[$bid] ?? 0) + 1;
}
foreach ($pendingCustody as $c) {
    $bid = (int)$c['branch_id'];
    $reviewBranchCounts[$bid] = ($reviewBranchCounts[$bid] ?? 0) + 1;
}
$totalPendingReviewCount = count($pending) + count($pendingCustody);

// استعلام مشاكل تسلسل الورديات لو مسموح
$seqIssues = [];
$openSeqCount = 0;
$seqUsersByBranch = [];
$seqIssuesTableExists = false;
if (can_edit_shift_date_branch()) {
    try {
        $seqIssues = $pdo->query(
            "SELECT ssi.*, b.name AS branch_name, u_as.full_name AS assigned_name, u_res.full_name AS resolved_name
             FROM shift_sequence_issues ssi
             JOIN branches b ON b.id = ssi.branch_id
             LEFT JOIN users u_as ON u_as.id = ssi.assigned_user_id
             LEFT JOIN users u_res ON u_res.id = ssi.resolved_by
             ORDER BY ssi.id DESC LIMIT 100"
        )->fetchAll(PDO::FETCH_ASSOC);
        $seqIssuesTableExists = true;
        foreach ($seqIssues as $iss) {
            if ($iss['status'] === 'open') {
                $openSeqCount++;
            }
        }
        $branchUsersRaw = $pdo->query(
            "SELECT u.id, u.full_name, u.branch_id AS u_branch, e.branch_id AS e_branch
             FROM users u
             LEFT JOIN employees e ON e.user_id = u.id AND e.is_active = 1
             WHERE u.is_active = 1
             ORDER BY u.full_name ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($branchUsersRaw as $uRow) {
            $uId = (int)$uRow['id'];
            $uName = $uRow['full_name'];
            $uB = (int)($uRow['u_branch'] ?? 0);
            $eB = (int)($uRow['e_branch'] ?? 0);
            if ($uB > 0) {
                $seqUsersByBranch[$uB][$uId] = ['id' => $uId, 'name' => $uName];
            }
            if ($eB > 0) {
                $seqUsersByBranch[$eB][$uId] = ['id' => $uId, 'name' => $uName];
            }
        }
        foreach ($seqUsersByBranch as $bid => $uList) {
            $seqUsersByBranch[$bid] = array_values($uList);
        }
    } catch (Throwable $e) {
        $seqIssues = [];
        $openSeqCount = 0;
        $seqIssuesTableExists = false;
    }
}

$page_title    = 'مراجعة المحاسب';
$active_module = 'review';
require_once __DIR__ . '/includes/header.php';

// دالة مساعدة: أرقام الكاشير لوردية
function shift_cashier_amounts(array $s): array {
    return [
        'cash'     => (float)($s['cash_total'] ?? 0),
        'visa'     => (float)($s['visa_total'] ?? 0),
        'instapay' => (float)($s['instapay_total'] ?? 0),
        'system'   => (float)($s['system_total'] ?? 0),
    ];
}
?>

<style>
  .branch-tabs { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
  .branch-tabs .btab {
      padding:7px 16px; border-radius:9px; background:var(--surface); color:var(--accent-text);
      text-decoration:none; font-size:14px; border:1.5px solid var(--line);
      transition:.15s; font-family:inherit; cursor:pointer;
  }
  .branch-tabs .btab:hover  { border-color:var(--accent); }
  .branch-tabs .btab.active { background:var(--accent); color:#fff; border-color:var(--accent-text); }
    .flash { padding:12px 16px; border-radius:10px; margin-bottom:18px; font-size:14px; }
    .flash.success { background:var(--pos-soft); color:var(--pos); }
    .flash.error   { background:var(--neg-soft); color:var(--neg); }
    .rv-tabs { display:flex; gap:18px; border-bottom:2px solid var(--line); margin-bottom:20px; }
    .rv-tab { padding:10px 4px; cursor:pointer; color:var(--ink-500); font-weight:600; font-size:15px;
        border-bottom:3px solid transparent; margin-bottom:-2px; background:none; border-top:none; border-left:none; border-right:none; font-family:inherit; }
    .rv-tab.active { color:var(--accent-text); border-bottom-color:var(--accent-text); }
    .rv-pane { display:none; } .rv-pane.active { display:block; }
    /* كروت الورديات */
    .shift-card { background:var(--surface); border-radius:14px; padding:18px 20px; margin-bottom:16px;
        box-shadow:0 2px 10px rgba(124,111,247,0.06); border-right:4px solid var(--warn); }
    .shift-card.done { border-right-color:var(--pos); }
    .shift-card.rej { border-right-color:var(--neg); }
    .sc-head { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:14px; }
    .sc-title { font-size:16px; color:var(--ink-900); }
    .sc-title b { color:var(--accent-text); }
    .sc-sub { color:var(--ink-500); font-size:13px; }
    .sc-stats { display:grid; grid-template-columns:repeat(5,1fr); gap:10px; }
    .sc-stats.cst-stats { grid-template-columns:repeat(4,1fr); }
    .sc-stat { background:var(--line-soft); border-radius:10px; padding:12px; text-align:center; }
    .sc-stat .l { font-size:12px; color:var(--ink-500); margin-bottom:4px; }
    .sc-stat .v { font-size:16px; font-weight:700; color:var(--ink-900); }
    .sc-stat .v.red { color:var(--neg); }
    .btn-review { background:var(--accent); color:#fff; border:none; border-radius:9px; padding:9px 18px; cursor:pointer; font-family:inherit; font-size:14px; display:inline-flex; align-items:center; gap:6px; }
    /* بوب أب */
    .rv-modal { display:none; position:fixed; inset:0; background:rgba(30,27,75,0.5); z-index:var(--z-modal); align-items:flex-start; justify-content:center; padding:24px 14px; overflow-y:auto; }
    .rv-modal.open { display:flex; }
    .rv-box { background:var(--surface); border-radius:16px; width:100%; max-width:620px; margin:auto; box-shadow:0 18px 50px rgba(0,0,0,0.25); }
    .rv-mhead { display:flex; justify-content:space-between; align-items:center; padding:16px 22px; border-bottom:1.5px solid var(--line); position:sticky; top:0; background:var(--surface); border-radius:16px 16px 0 0; }
    .rv-mhead .t { font-size:16px; color:var(--ink-900); font-weight:700; }
    .rv-x { background:none; border:none; font-size:26px; color:var(--ink-400); cursor:pointer; }
    .rv-mbody { padding:18px 22px; }
    .rv-note { background:var(--surface); color:var(--accent-text); padding:10px 14px; border-radius:9px; font-size:13px; margin-bottom:14px; }
    .rv-sys { margin-bottom:14px; }
    .rv-sys label { font-size:13px; color:var(--ink-500); display:block; margin-bottom:5px; }
    .rv-sys input { width:100%; padding:9px 12px; border:1.5px solid var(--line); border-radius:9px; font-family:inherit; font-size:15px; }
    .rv-ptabs { display:flex; gap:4px; flex-wrap:wrap; border-bottom:2px solid var(--line); margin-bottom:16px; }
    .rv-ptab { padding:8px 12px; cursor:pointer; color:var(--ink-500); font-size:13px; font-weight:500; border:none; background:none; border-bottom:2px solid transparent; margin-bottom:-2px; font-family:inherit; }
    .rv-ptab.active { color:var(--accent-text); border-bottom-color:var(--accent-text); }
    .rv-ppane { display:none; } .rv-ppane.active { display:block; }
    /* تاب الكاش (شكل قديم: قائمة + بوكس إجمالي) */
    .rv-cash-layout { display:grid; grid-template-columns:1fr 0.9fr; gap:14px; align-items:start; }
    @media (max-width:560px){ .rv-cash-layout { grid-template-columns:1fr; } }
    .denom-row { display:flex; align-items:center; gap:10px; margin-bottom:8px; justify-content:flex-end; }
    .denom-row .dn { width:70px; color:var(--ink-700); font-size:13px; text-align:left; }
    .denom-row input { width:80px; padding:6px 8px; border:1.5px solid var(--line); border-radius:7px; text-align:center; font-family:inherit; }
    .denom-row .sub { width:90px; color:var(--ink-500); font-size:13px; }
    .rv-cash-box { background:var(--bg); border-radius:12px; padding:16px; }
    .rv-cash-box .l { font-size:13px; color:var(--ink-500); }
    .rv-cash-box .v { font-size:24px; font-weight:700; color:var(--accent-text); margin-top:2px; }
    .pay-row { display:grid; grid-template-columns:1fr 100px 1fr 36px; gap:8px; margin-bottom:6px; align-items:center; }
    .pay-row input { padding:6px 8px; border:1.5px solid var(--line); border-radius:7px; font-family:inherit; font-size:13px; }
    .rv-total { background:var(--line-soft); border-radius:9px; padding:10px 14px; font-weight:600; color:var(--accent-text); text-align:center; margin-top:8px; }
    .cmp-table { width:100%; border-collapse:collapse; }
    .cmp-table th, .cmp-table td { padding:9px 12px; text-align:center; border-bottom:1px solid var(--line-soft); font-size:14px; }
    .cmp-table th { background:var(--line-soft); color:var(--ink-500); }
    .cmp-ok { color:var(--pos); } .cmp-bad { color:var(--neg); }
    .cmp-expandable { cursor:pointer; user-select:none; }
    .cmp-arrow { display:inline-block; transition:transform 0.2s ease; margin-inline-start:6px; font-size:13px; vertical-align:middle; }
    .cmp-expandable.open .cmp-arrow { transform:rotate(180deg); }
    .cmp-detail td { padding:0 !important; border-bottom:1px solid var(--line-soft); background:var(--bg); }
    .cmp-sub-table { width:100%; border-collapse:collapse; font-size:12px; }
    .cmp-sub-table th, .cmp-sub-table td { padding:6px 10px; text-align:center; border-bottom:1px solid var(--line-soft); }
    .cmp-sub-table th { background:var(--line-soft); color:var(--ink-500); font-weight:600; }
    .cmp-sub-table td { color:var(--ink-700); }
    .rv-actions { display:flex; gap:8px; margin-top:14px; }
    .btn-approve { background:var(--pos-fill); color:#fff; flex:1; }
    .btn-approve:hover { background:var(--pos-fill); color:#fff; }
    .btn-reject  { background:var(--neg-fill); color:#fff; flex:1; }
    .btn-reject:hover { background:var(--neg-fill); color:#fff; }
    /* قسم عهد الفروع */
    .cst-row { display:grid; grid-template-columns:1fr 1.4fr; gap:12px; margin-bottom:4px; }
    .section-head { font-size:15px; font-weight:600; color:var(--ink-700); margin:26px 0 12px; }
    .btn-shift-db { background:var(--line-soft); color:var(--ink-700); border:1px solid var(--line); border-radius:9px; padding:7px 12px; cursor:pointer; font-family:inherit; font-size:13px; display:inline-flex; align-items:center; gap:5px; }
    .btn-shift-db:hover { background:var(--line); color:var(--ink-900); }
    @media (max-width:560px){ .sc-stats { grid-template-columns:repeat(2,1fr); } .pay-row { grid-template-columns:1fr 80px 36px; } .cst-row { grid-template-columns:1fr; } }
</style>

<?php if ($flash): ?>
    <div class="flash <?= $flashType ?>"><?= e($flash) ?></div>
<?php endif; ?>

<div class="branch-tabs" id="reviewBranchTabs">
    <button type="button" class="btab active" data-b="all" onclick="filterReviewBranch('all')">
        الكل (<?= $totalPendingReviewCount ?>)
    </button>
    <?php foreach ($reviewBranches as $rb):
        $cnt = $reviewBranchCounts[(int)$rb['id']] ?? 0;
    ?>
    <button type="button" class="btab" data-b="<?= (int)$rb['id'] ?>" onclick="filterReviewBranch('<?= (int)$rb['id'] ?>')">
        <?= e($rb['name']) ?> (<?= $cnt ?>)
    </button>
    <?php endforeach; ?>
</div>

<div class="rv-tabs">
    <button class="rv-tab active" onclick="rvTab(this,'pane_pending')">في انتظار المراجعة (<span id="cnt_pending"><?= count($pending) ?></span>)</button>
    <button class="rv-tab" onclick="rvTab(this,'pane_custody')">العهد (<span id="cnt_custody"><?= count($pendingCustody) ?></span>)</button>
    <button class="rv-tab" onclick="rvTab(this,'pane_history')">السجل</button>
    <?php if (can_edit_shift_date_branch() && $seqIssuesTableExists): ?>
        <button class="rv-tab" id="tab_seq_btn" onclick="rvTab(this,'pane_seq')">تسلسل الورديات (<?= $openSeqCount ?> مفتوحة)</button>
    <?php endif; ?>
</div>

<!-- في انتظار المراجعة -->
<div class="rv-pane active" id="pane_pending">
    <?php if (empty($pending)): ?>
        <div class="card" style="text-align:center;color:var(--ink-500);padding:30px;">مفيش ورديات في انتظار المراجعة.</div>
    <?php else: ?>
        <?php foreach ($pending as $s): 
            $sid = (int)$s['id'];
            $amt = shift_cashier_amounts($s); 
            $sExps = $shiftExpensesMap[$sid] ?? [];
            $expTotal = array_sum(array_column($sExps, 'amount'));
            $collected = $amt['cash'] + $expTotal + $amt['visa'] + $amt['instapay'];
            $diff = $collected - $amt['system'];
        ?>
            <div class="shift-card rv-pending-shift" data-branch="<?= (int)$s['branch_id'] ?>">
                <div class="sc-head">
                    <div>
                        <div class="sc-title"><b><?= e($s['branch_name']) ?></b> — وردية<?= !empty($s['shift_name']) ? ' #' . e($s['shift_name']) : '' ?> <span class="sc-sub"><?= e($s['business_date']) ?> · كاشير: <?= e($s['cashier_name']) ?></span></div>
                        <?php if (!empty($shiftEditBadges[(int)$s['id']])): ?>
                            <div style="font-size:12px;color:var(--ink-500);margin-top:2px;">اتعدّل بواسطة <?= e($shiftEditBadges[(int)$s['id']]['name']) ?> · <?= e($shiftEditBadges[(int)$s['id']]['time']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex;gap:6px;align-items:center;">
                        <?php if (can_edit_shift_date_branch()): ?>
                            <button type="button" class="btn-shift-db" onclick="openShiftDateBranch(<?= (int)$s['id'] ?>, '<?= e($s['business_date']) ?>', <?= (int)$s['branch_id'] ?>)" title="تغيير التاريخ والفرع">
                                <i class="ti ti-calendar"></i> تغيير التاريخ/الفرع
                            </button>
                        <?php endif; ?>
                        <button class="btn-review" onclick='openReview(<?= json_encode([
                            "id"=>(int)$s["id"], "branch"=>$s["branch_name"], "cashier"=>$s["cashier_name"],
                            "no"=>(string)($s["shift_name"] ?? ''),
                            "date"=>$s["business_date"],
                            "cash"=>$amt["cash"], "visa"=>$amt["visa"], "instapay"=>$amt["instapay"],
                            "system"=>$amt["system"],
                            "expenses"=>$sExps,
                            "expenses_total"=>$expTotal,
                            "empCash"  => $empCash[(int)$s["id"]]  ?? (object)[],
                            "empLines" => $empLines[(int)$s["id"]] ?? (object)[],
                        ], JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="ti ti-eye"></i> مراجعة</button>
                    </div>
                </div>
                <div class="sc-stats">
                    <div class="sc-stat"><div class="l">مبيعات السيستم</div><div class="v"><?= number_format($amt['system'],2) ?></div></div>
                    <div class="sc-stat"><div class="l">كاش الدرج</div><div class="v"><?= number_format($amt['cash'],2) ?></div></div>
                    <div class="sc-stat"><div class="l">مصروفات الدرج</div><div class="v" style="<?= $expTotal > 0 ? 'color:var(--neg);' : '' ?>"><?= number_format($expTotal,2) ?></div></div>
                    <div class="sc-stat"><div class="l">فيزا + إنستا باي</div><div class="v"><?= number_format($amt['visa']+$amt['instapay'],2) ?></div></div>
                    <div class="sc-stat"><div class="l">الفرق</div><div class="v <?= abs($diff)>=0.01?'red':'' ?>"><?= number_format($diff,2) ?></div></div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- عهد الفروع -->
<div class="rv-pane" id="pane_custody">
    <?php if (empty($pendingCustody)): ?>
        <div class="card" style="text-align:center;color:var(--ink-500);padding:30px;">مفيش عهد في انتظار المراجعة.</div>
    <?php else: ?>
        <?php foreach ($pendingCustody as $c):
            $cOpening  = (float) $c['opening_balance'];
            $cExpenses = (float) $c['total_expenses'];
            $cExpected = (float) $c['closing_balance'];
            $cCounted  = (float) ($c['counted_cash'] ?? 0);
            $cDiff     = $cCounted - $cExpected;
        ?>
            <div class="shift-card rv-pending-custody" data-branch="<?= (int)$c['branch_id'] ?>">
                <div class="sc-head">
                    <div class="sc-title">
                        <b><?= !empty($c['employee_id']) ? ('عهدة موظف: ' . e($c['employee_name'] ?: ('#' . $c['employee_id']))) : (e($c['branch_name'] ?? '') . ' — عهدة') ?></b>
                        <span class="sc-sub">
                            قدّمها <?= e($c['submitted_by_name'] ?? '—') ?>
                            <?= !empty($c['submitted_at']) ? ' · ' . e($c['submitted_at']) : '' ?>
                        </span>
                    </div>
                </div>

                <div class="sc-stats cst-stats">
                    <div class="sc-stat"><div class="l">أول المدة</div><div class="v"><?= number_format($cOpening,2) ?></div></div>
                    <div class="sc-stat"><div class="l">المصروفات</div><div class="v"><?= number_format($cExpenses,2) ?></div></div>
                    <div class="sc-stat"><div class="l">المفروض</div><div class="v"><?= number_format($cExpected,2) ?></div></div>
                    <div class="sc-stat">
                        <div class="l">الكاشير عدّ</div>
                        <div class="v <?= abs($cDiff)>=0.01?'red':'' ?>"><?= number_format($cCounted,2) ?></div>
                    </div>
                </div>

                <form method="post" style="margin-top:14px;"
                      onsubmit="return cstConfirm(this);">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="kind" value="custody">
                    <input type="hidden" name="custody_id" value="<?= (int)$c['id'] ?>">
                    <input type="hidden" name="decision" value="">
                    <input type="hidden" name="branch_label" value="<?= e(!empty($c['employee_id']) ? ('موظف: ' . ($c['employee_name'] ?: ('#' . $c['employee_id']))) : ($c['branch_name'] ?? '')) ?>">

                    <div class="cst-row">
                        <div class="rv-sys" style="margin-bottom:0;">
                            <label>اللي استلمته فعلاً</label>
                            <input type="number" step="0.01" min="0" name="acc_counted"
                                   value="<?= number_format($cCounted,2,'.','') ?>"
                                   aria-label="المبلغ اللي استلمته فعلاً">
                        </div>
                        <div class="rv-sys" style="margin-bottom:0;">
                            <label>السبب (إجباري عند الرفض)</label>
                            <input type="text" name="reason" aria-label="سبب الرفض">
                        </div>
                    </div>

                    <div class="rv-actions">
                        <button type="submit" class="btn-review btn-approve"
                                onclick="this.form.decision.value='approved'">
                            <i class="ti ti-check"></i> اعتماد
                        </button>
                        <button type="submit" class="btn-review btn-reject"
                                onclick="this.form.decision.value='rejected'">
                            <i class="ti ti-x"></i> رفض
                        </button>
                    </div>
                </form>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($custodyHistory): ?>
        <div class="section-head">آخر العهد اللي اتراجعت</div>
        <?php foreach ($custodyHistory as $c):
            $isDone = $c['status'] === 'closed';
        ?>
            <div class="shift-card rv-custody-history <?= $isDone?'done':'rej' ?>" data-branch="<?= (int)$c['branch_id'] ?>">
                <div class="sc-head">
                    <div class="sc-title">
                        <b><?= !empty($c['employee_id']) ? ('عهدة موظف: ' . e($c['employee_name'] ?: ('#' . $c['employee_id']))) : (e($c['branch_name'] ?? '') . ' — عهدة') ?></b>
                        <span class="sc-sub">
                            راجعها <?= e($c['reviewer_name'] ?? '—') ?> · <?= e($c['reviewed_at']) ?>
                        </span>
                    </div>
                    <span style="font-size:13px;font-weight:600;color:<?= $isDone?'var(--pos)':'var(--neg)' ?>;">
                        <?= $isDone?'معتمدة':'مرفوضة' ?>
                    </span>
                </div>
                <div class="sc-stats cst-stats">
                    <div class="sc-stat"><div class="l">المفروض</div><div class="v"><?= number_format((float)$c['closing_balance'],2) ?></div></div>
                    <div class="sc-stat"><div class="l">الكاشير عدّ</div><div class="v"><?= number_format((float)($c['counted_cash'] ?? 0),2) ?></div></div>
                    <div class="sc-stat">
                        <div class="l">المحاسب استلم</div>
                        <div class="v"><?= $isDone ? number_format((float)($c['accountant_counted'] ?? 0),2) : '—' ?></div>
                    </div>
                    <div class="sc-stat">
                        <div class="l"><?= $isDone ? 'الفرق' : 'سبب الرفض' ?></div>
                        <div class="v <?= $isDone && abs((float)$c['difference'])>=0.01 ? 'red' : '' ?>"
                             style="<?= $isDone ? '' : 'font-size:13px;font-weight:500;' ?>">
                            <?= $isDone ? number_format((float)$c['difference'],2) : e($c['reject_reason'] ?? '—') ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- السجل -->
<div class="rv-pane" id="pane_history">
    <?php if (empty($history)): ?>
        <div class="card" style="text-align:center;color:var(--ink-500);padding:30px;">مفيش سجل بعد.</div>
    <?php else: ?>
        <?php foreach ($history as $s): 
            $sid = (int)$s['id'];
            $amt = shift_cashier_amounts($s); 
            $sExps = $shiftExpensesMap[$sid] ?? [];
            $expTotal = array_sum(array_column($sExps, 'amount'));
            $diff = ($amt['cash'] + $expTotal + $amt['visa'] + $amt['instapay']) - $amt['system'];
            $isDone = $s['status']==='approved'; 
        ?>
            <div class="shift-card rv-shift-history <?= $isDone?'done':'rej' ?>" data-branch="<?= (int)$s['branch_id'] ?>">
                <div class="sc-head">
                    <div>
                        <div class="sc-title"><b><?= e($s['branch_name']) ?></b> — وردية<?= !empty($s['shift_name']) ? ' #' . e($s['shift_name']) : '' ?> <span class="sc-sub"><?= e($s['business_date']) ?> · <?= e($s['cashier_name']) ?></span></div>
                        <?php if (!empty($shiftEditBadges[(int)$s['id']])): ?>
                            <div style="font-size:12px;color:var(--ink-500);margin-top:2px;">اتعدّل بواسطة <?= e($shiftEditBadges[(int)$s['id']]['name']) ?> · <?= e($shiftEditBadges[(int)$s['id']]['time']) ?></div>
                        <?php endif; ?>
                    </div>
                    <span style="font-size:13px;font-weight:600;color:<?= $isDone?'var(--pos)':'var(--neg)' ?>;"><?= $isDone?'معتمدة':'مرفوضة' ?></span>
                </div>
                <div class="sc-stats">
                    <div class="sc-stat"><div class="l">السيستم</div><div class="v"><?= number_format($amt['system'],2) ?></div></div>
                    <div class="sc-stat"><div class="l">كاش الدرج</div><div class="v"><?= number_format($amt['cash'],2) ?></div></div>
                    <div class="sc-stat"><div class="l">مصروفات الدرج</div><div class="v" style="<?= $expTotal > 0 ? 'color:var(--neg);' : '' ?>"><?= number_format($expTotal,2) ?></div></div>
                    <div class="sc-stat"><div class="l">فيزا+إنستا</div><div class="v"><?= number_format($amt['visa']+$amt['instapay'],2) ?></div></div>
                    <div class="sc-stat"><div class="l">الفرق</div><div class="v <?= abs($diff)>=0.01?'red':'' ?>"><?= number_format($diff,2) ?></div></div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if (can_edit_shift_date_branch() && $seqIssuesTableExists): ?>
<!-- تسلسل الورديات -->
<div class="rv-pane" id="pane_seq">
    <?php if (empty($seqIssues)): ?>
        <div class="card" style="text-align:center;color:var(--ink-500);padding:30px;">لا توجد مشاكل في تسلسل الورديات.</div>
    <?php else: ?>
        <div class="card" style="overflow-x:auto;padding:0;">
            <table class="data-table" style="width:100%;border-collapse:collapse;font-size:14px;text-align:right;">
                <thead>
                    <tr style="background:var(--line-soft);color:var(--ink-500);border-bottom:1.5px solid var(--line);">
                        <th style="padding:10px 14px;">الفرع</th>
                        <th style="padding:10px 14px;">النوع</th>
                        <th style="padding:10px 14px;">الوصف</th>
                        <th style="padding:10px 14px;">الحالة</th>
                        <th style="padding:10px 14px;">الموظف</th>
                        <th style="padding:10px 14px;">الرد / الملاحظة</th>
                        <th style="padding:10px 14px;">التاريخ</th>
                        <th style="padding:10px 14px;">إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($seqIssues as $iss):
                        $isDup = ($iss['kind'] === 'duplicate');
                        $descText = $isDup ? ('رقم ' . e($iss['found_no']) . ' اتكرر') : ('بعد ' . e($iss['expected_no'] - 1) . ' جه ' . e($iss['found_no']));
                        $st = $iss['status'];
                        $stBadge = match($st) {
                            'open'     => '<span style="display:inline-block;padding:3px 8px;border-radius:6px;background:var(--warn-soft,#fff3cd);color:var(--warn,#856404);font-size:12px;font-weight:600;">مفتوحة</span>',
                            'ignored'  => '<span style="display:inline-block;padding:3px 8px;border-radius:6px;background:var(--line-soft,#e9ecef);color:var(--ink-500,#6c757d);font-size:12px;font-weight:600;">تم التغاضي</span>',
                            'sent'     => '<span style="display:inline-block;padding:3px 8px;border-radius:6px;background:var(--surface,#e0e7ff);color:var(--accent-text,#4338ca);font-size:12px;font-weight:600;">مُرسلة للموظف</span>',
                            'answered' => '<span style="display:inline-block;padding:3px 8px;border-radius:6px;background:var(--pos-soft,#d4edda);color:var(--pos,#155724);font-size:12px;font-weight:600;">تم الرد</span>',
                            default    => e($st),
                        };
                    ?>
                    <tr class="seq-issue-row" data-branch="<?= (int)$iss['branch_id'] ?>" style="border-bottom:1px solid var(--line-soft);">
                        <td style="padding:10px 14px;font-weight:600;"><?= e($iss['branch_name']) ?></td>
                        <td style="padding:10px 14px;"><?= $isDup ? 'مكرر' : 'فجوة' ?></td>
                        <td style="padding:10px 14px;font-weight:600;color:var(--ink-900);"><?= $descText ?></td>
                        <td style="padding:10px 14px;"><?= $stBadge ?></td>
                        <td style="padding:10px 14px;"><?= !empty($iss['assigned_name']) ? e($iss['assigned_name']) : '—' ?></td>
                        <td style="padding:10px 14px;font-size:13px;color:var(--ink-700);">
                            <?php if (!empty($iss['owner_note'])): ?>
                                <div><span style="color:var(--ink-500);">ملاحظة:</span> <?= e($iss['owner_note']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($iss['reply'])): ?>
                                <div style="color:var(--pos);"><span style="color:var(--ink-500);">الرد:</span> <?= e($iss['reply']) ?></div>
                            <?php endif; ?>
                            <?php if (empty($iss['owner_note']) && empty($iss['reply'])): ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td style="padding:10px 14px;font-size:12px;color:var(--ink-500);"><?= e($iss['created_at']) ?></td>
                        <td style="padding:10px 14px;">
                            <?php if ($st === 'open'): ?>
                                <div style="display:flex;gap:6px;">
                                    <button type="button" class="btn-shift-db" onclick="openSeqIgnore(<?= (int)$iss['id'] ?>)">تغاضي</button>
                                    <button type="button" class="btn-shift-db" onclick="openSeqSend(<?= (int)$iss['id'] ?>, <?= (int)$iss['branch_id'] ?>, '<?= e(addslashes($descText)) ?>')">ابعت لموظف</button>
                                </div>
                            <?php else: ?>
                                <span style="color:var(--ink-400);font-size:12px;">منتهية</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- بوب أب المراجعة -->
<div class="rv-modal" id="rvModal">
  <div class="rv-box">
    <div class="rv-mhead">
        <span class="t" id="rvTitle">مراجعة وردية</span>
        <button type="button" class="rv-x" onclick="closeReview()">&times;</button>
    </div>
    <div class="rv-mbody">
        <form method="post" id="rvForm">
            <input type="hidden" name="kind" value="shift">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="shift_id" id="rv_shift_id" value="">
            <input type="hidden" name="decision" id="rv_decision" value="">
            <input type="hidden" name="acc_cash" id="rv_acc_cash" value="0">
            <input type="hidden" name="acc_visa" id="rv_acc_visa" value="0">
            <input type="hidden" name="acc_instapay" id="rv_acc_instapay" value="0">

            <div class="rv-sys">
                <label for="rv_acc_system">مبيعات السيستم</label>
                <input type="number" step="0.01" min="0" name="acc_system" id="rv_acc_system" value="" placeholder="0.00" oninput="rvCalc()">
            </div>

            <div class="rv-ptabs">
                <button type="button" class="rv-ptab active" onclick="rvPtab(this,'rp_cash')">كاش</button>
                <button type="button" class="rv-ptab" onclick="rvPtab(this,'rp_visa')">فيزا</button>
                <button type="button" class="rv-ptab" onclick="rvPtab(this,'rp_instapay')">إنستا باي</button>
                <button type="button" class="rv-ptab" onclick="rvPtab(this,'rp_expenses')">مصروفات الدرج (<span id="rv_tab_exp_t">0.00</span>)</button>
                <button type="button" class="rv-ptab" id="rv_cmp_tab" onclick="rvCompare(this)">قارن</button>
            </div>

            <!-- كاش -->
            <div class="rv-ppane active" id="rp_cash">
                <div class="rv-cash-layout">
                    <div>
                        <?php foreach ($denominations as $d): ?>
                            <div class="denom-row">
                                <span class="sub" id="rvd_<?= str_replace('.','_',(string)$d) ?>">0.00</span>
                                <span>×</span>
                                <input type="number" aria-label="عدد فئة <?= $d ?> جنيه" min="0" class="rv-denom" data-d="<?= $d ?>" value="" placeholder="0" oninput="rvCalc()">
                                <span class="dn"><?= $d ?> ج.م</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="rv-cash-box">
                        <div class="l">إجمالي الكاش</div>
                        <div class="v"><span id="rv_cash_t">0.00</span> ج.م</div>
                    </div>
                </div>
            </div>

            <!-- فيزا / إنستا (سطور) -->
            <?php foreach (['visa'=>'فيزا','instapay'=>'إنستا باي'] as $mk=>$ml): ?>
            <div class="rv-ppane" id="rp_<?= $mk ?>">
                <table class="pay-table" style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th style="width:28px;padding:4px 6px 8px;text-align:right;font-size:12px;color:var(--ink-500);font-weight:600;">#</th>
                            <th style="padding:4px 6px 8px;text-align:right;font-size:12px;color:var(--ink-500);font-weight:600;">رقم السيريال</th>
                            <th style="padding:4px 6px 8px;text-align:right;font-size:12px;color:var(--ink-500);font-weight:600;">المبلغ</th>
                        </tr>
                    </thead>
                    <tbody id="tb_<?= $mk ?>">
                    <?php for ($i=1;$i<=10;$i++): ?>
                        <tr>
                            <td style="padding:4px;color:var(--ink-500);font-size:13px;text-align:center;"><?= $i ?></td>
                            <td style="padding:4px;"><input type="text" class="rv-<?= $mk ?>-ref" placeholder="رقم السيريال" style="width:100%;padding:6px 8px;border:1.5px solid var(--line);border-radius:7px;font-family:inherit;font-size:13px;" aria-label="رقم السيريال"></td><!-- rv-visa-ref / rv-instapay-ref -->
                            <td style="padding:4px;"><input type="number" aria-label="المبلغ" step="0.01" min="0" class="rv-<?= $mk ?>" value="" placeholder="0.00" oninput="rvCalc()" style="width:100%;padding:6px 8px;border:1.5px solid var(--line);border-radius:7px;font-family:inherit;font-size:13px;text-align:center;"></td>
                        </tr>
                    <?php endfor; ?>
                    </tbody>
                </table>
                <button type="button" onclick="rvAddRow('<?= $mk ?>')" style="margin-top:8px;background:var(--line-soft);color:var(--accent-text);border:1.5px dashed var(--accent);border-radius:8px;padding:7px 14px;font-family:inherit;font-size:13px;cursor:pointer;">+ إضافة صف</button>
                <div class="rv-total">إجمالي <?= $ml ?>: <span id="rv_<?= $mk ?>_t">0.00</span> ج.م</div>
            </div>
            <?php endforeach; ?>

            <!-- مصروفات الدرج -->
            <div class="rv-ppane" id="rp_expenses">
                <div style="margin-bottom:10px;font-size:13px;color:var(--ink-700);">
                    المصروفات النثرية المسجلة من قِبل الكاشير (صُرفت من الدرج):
                </div>
                <table class="pay-table" style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th style="padding:6px 8px;text-align:right;font-size:12px;color:var(--ink-500);font-weight:600;">تصنيف المصروف</th>
                            <th style="padding:6px 8px;text-align:center;font-size:12px;color:var(--ink-500);font-weight:600;width:100px;">المبلغ</th>
                            <th style="padding:6px 8px;text-align:right;font-size:12px;color:var(--ink-500);font-weight:600;">ملاحظة / بيان</th>
                        </tr>
                    </thead>
                    <tbody id="rv_exp_body">
                        <!-- Dynamic rows from openReview -->
                    </tbody>
                </table>
                <div class="rv-total" style="color:var(--neg);">إجمالي المصروفات: <span id="rv_exp_t">0.00</span> ج.م</div>
                <div style="margin-top:12px;background:var(--line-soft);padding:10px 14px;border-radius:9px;font-size:12px;color:var(--ink-600);line-height:1.7;">
                    <i class="ti ti-alert-circle"></i> <b>آلية الترحيل المالي:</b> عند الاعتماد، يدخل إجمالي الكاش المبيوع (كاش الدرج + المصروفات) كاستلام مبيعات في الخزينة، ثم يُصرف كل بند مصروف كقيد صرف مستقل من نفس الخزينة النقدية بحسابه الخاص.
                </div>
            </div>

            <!-- مقارنة -->
            <div class="rv-ppane" id="rp_compare">
                <!-- ─────────────────────────────────────────────────────
                     مرحلتين:
                       1. المحاسب يدخل أرقامه — أرقام الموظف مخفية تمامًا
                       2. يدوس «قارن» → الأرقام تظهر + المقارنة + القرار

                     السبب: لو شاف أرقام الموظف وهو بيعدّ، هينسخها بدل
                     ما يعدّ بنفسه، والمراجعة تبقى بلا قيمة.
                     ───────────────────────────────────────────────────── -->
                <div id="cmp_locked" style="background:var(--surface);border-radius:10px;
                     padding:22px;text-align:center;color:var(--ink-500);font-size:14px;">
                    <i class="ti ti-lock"></i> أدخل أرقامك في التابات الأولى، وبعدها اضغط «قارن».
                </div>
                <table class="cmp-table" id="cmp_table" data-xt-off style="display:none;">
                    <thead><tr><th>البند</th><th>الموظف</th><th>المحاسب</th><th>الفرق</th></tr></thead>
                    <tbody id="cmp_body"></tbody>
                </table>
                <div id="cmp_msg" style="margin:14px 0;padding:10px 14px;border-radius:9px;text-align:center;font-size:14px;display:none;"></div>
                <div id="cmp_decide" style="display:none;">
                    <label style="font-size:13px;color:var(--ink-500);display:block;margin-bottom:5px;">ملاحظات المحاسب (مطلوب للرفض)</label>
                    <textarea name="reason" id="rv_reason" rows="2" placeholder="اكتب سبب الرجوع هنا..." style="width:100%;padding:9px 12px;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;"></textarea>
                    <div class="rv-actions">
                        <button type="submit" class="btn-approve" onclick="return rvDecide('approved')" style="border:none;border-radius:9px;padding:11px;cursor:pointer;font-family:inherit;font-size:14px;">✓ اعتماد وإنشاء القيود</button>
                        <button type="submit" class="btn-reject" onclick="return rvDecide('rejected')" style="border:none;border-radius:9px;padding:11px;cursor:pointer;font-family:inherit;font-size:14px;">↩ إرجاع للموظف</button>
                    </div>
                </div>
            </div>
        </form>
    </div>
  </div>
</div>

<!-- بوب أب تغيير تاريخ/فرع الوردية -->
<div class="rv-modal" id="shiftDateBranchModal">
  <div class="rv-box" style="max-width:440px;">
    <div class="rv-mhead">
        <span class="t">تغيير تاريخ / فرع الوردية</span>
        <button type="button" class="rv-x" onclick="closeShiftDateBranch()">&times;</button>
    </div>
    <div class="rv-mbody">
        <form method="post" id="shiftDateBranchForm">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="shift_date_branch">
            <input type="hidden" name="shift_id" id="sdb_shift_id" value="">

            <div style="margin-bottom:14px;">
                <label for="sdb_date" style="font-size:13px;color:var(--ink-500);display:block;margin-bottom:5px;">تاريخ الوردية</label>
                <input type="date" name="business_date" id="sdb_date" required style="width:100%;padding:9px 12px;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;font-size:14px;">
            </div>

            <div style="margin-bottom:18px;">
                <label for="sdb_branch" style="font-size:13px;color:var(--ink-500);display:block;margin-bottom:5px;">الفرع</label>
                <select name="branch_id" id="sdb_branch" required style="width:100%;padding:9px 12px;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;font-size:14px;">
                    <?php foreach ($reviewBranches as $rb): ?>
                        <option value="<?= (int)$rb['id'] ?>"><?= e($rb['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px;">
                <button type="button" onclick="closeShiftDateBranch()" style="background:var(--line-soft);color:var(--ink-700);border:1px solid var(--line);border-radius:9px;padding:9px 16px;cursor:pointer;font-family:inherit;font-size:14px;">إلغاء</button>
                <button type="submit" style="background:var(--accent);color:#fff;border:none;border-radius:9px;padding:9px 20px;cursor:pointer;font-family:inherit;font-size:14px;font-weight:600;">حفظ</button>
            </div>
        </form>
    </div>
  </div>
</div>

<?php if (can_edit_shift_date_branch() && $seqIssuesTableExists): ?>
<!-- بوب أب تغاضي عن مشكلة التسلسل -->
<div class="rv-modal" id="seqIgnoreModal">
  <div class="rv-box" style="max-width:440px;">
    <div class="rv-mhead">
        <span class="t">تغاضي عن مشكلة التسلسل</span>
        <button type="button" class="rv-x" onclick="closeSeqIgnore()">&times;</button>
    </div>
    <div class="rv-mbody">
        <form method="post" id="seqIgnoreForm">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="seq_ignore">
            <input type="hidden" name="issue_id" id="ignore_issue_id" value="">

            <div style="margin-bottom:14px;">
                <label for="ignore_owner_note" style="font-size:13px;color:var(--ink-500);display:block;margin-bottom:5px;">سبب التغاضي (إجباري)</label>
                <textarea name="owner_note" id="ignore_owner_note" required rows="3" placeholder="اكتب سبب التغاضي..." style="width:100%;padding:9px 12px;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;font-size:14px;box-sizing:border-box;"></textarea>
            </div>

            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px;">
                <button type="button" onclick="closeSeqIgnore()" style="background:var(--line-soft);color:var(--ink-700);border:1px solid var(--line);border-radius:9px;padding:9px 16px;cursor:pointer;font-family:inherit;font-size:14px;">إلغاء</button>
                <button type="submit" style="background:var(--accent);color:#fff;border:none;border-radius:9px;padding:9px 20px;cursor:pointer;font-family:inherit;font-size:14px;font-weight:600;">تأكيد التغاضي</button>
            </div>
        </form>
    </div>
  </div>
</div>

<!-- بوب أب إرسال مشكلة التسلسل لموظف -->
<div class="rv-modal" id="seqSendModal">
  <div class="rv-box" style="max-width:480px;">
    <div class="rv-mhead">
        <span class="t">إرسال مشكلة التسلسل لموظف</span>
        <button type="button" class="rv-x" onclick="closeSeqSend()">&times;</button>
    </div>
    <div class="rv-mbody">
        <form method="post" id="seqSendForm">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="seq_send">
            <input type="hidden" name="issue_id" id="send_issue_id" value="">

            <div id="send_desc_box" class="rv-note" style="margin-bottom:14px;font-weight:600;"></div>

            <div style="margin-bottom:14px;">
                <label for="send_user_id" style="font-size:13px;color:var(--ink-500);display:block;margin-bottom:5px;">الموظف (من فرع المشكلة)</label>
                <select name="assigned_user_id" id="send_user_id" required style="width:100%;padding:9px 12px;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;font-size:14px;">
                    <option value="">اختر الموظف...</option>
                </select>
            </div>

            <div style="margin-bottom:14px;">
                <label for="send_owner_note" style="font-size:13px;color:var(--ink-500);display:block;margin-bottom:5px;">ملاحظة للموظف (اختياري)</label>
                <textarea name="owner_note" id="send_owner_note" rows="2" placeholder="اكتب ملاحظة أو توجيه للموظف..." style="width:100%;padding:9px 12px;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;font-size:14px;box-sizing:border-box;"></textarea>
            </div>

            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px;">
                <button type="button" onclick="closeSeqSend()" style="background:var(--line-soft);color:var(--ink-700);border:1px solid var(--line);border-radius:9px;padding:9px 16px;cursor:pointer;font-family:inherit;font-size:14px;">إلغاء</button>
                <button type="submit" style="background:var(--accent);color:#fff;border:none;border-radius:9px;padding:9px 20px;cursor:pointer;font-family:inherit;font-size:14px;font-weight:600;">إرسال للموظف</button>
            </div>
        </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
    var seqUsersByBranch = <?= json_encode($seqUsersByBranch ?? [], JSON_UNESCAPED_UNICODE) ?>;

    function openSeqIgnore(id) {
        var el = document.getElementById('ignore_issue_id');
        if (el) el.value = id;
        var note = document.getElementById('ignore_owner_note');
        if (note) note.value = '';
        var m = document.getElementById('seqIgnoreModal');
        if (m) {
            m.classList.add('open');
            document.body.style.overflow = 'hidden';
        }
    }
    function closeSeqIgnore() {
        var m = document.getElementById('seqIgnoreModal');
        if (m) {
            m.classList.remove('open');
            document.body.style.overflow = '';
        }
    }
    function openSeqSend(id, branchId, desc) {
        var el = document.getElementById('send_issue_id');
        if (el) el.value = id;
        var dBox = document.getElementById('send_desc_box');
        if (dBox) dBox.textContent = desc || '';
        var note = document.getElementById('send_owner_note');
        if (note) note.value = '';
        var sel = document.getElementById('send_user_id');
        if (sel) {
            sel.innerHTML = '<option value="">اختر الموظف...</option>';
            var users = seqUsersByBranch[branchId] || [];
            if (users.length === 0) {
                var opt = document.createElement('option');
                opt.value = '';
                opt.textContent = 'لا يوجد موظفين مسجلين بهذا الفرع';
                sel.appendChild(opt);
            } else {
                users.forEach(function(u) {
                    var opt = document.createElement('option');
                    opt.value = u.id;
                    opt.textContent = u.name;
                    sel.appendChild(opt);
                });
            }
        }
        var m = document.getElementById('seqSendModal');
        if (m) {
            m.classList.add('open');
            document.body.style.overflow = 'hidden';
        }
    }
    function closeSeqSend() {
        var m = document.getElementById('seqSendModal');
        if (m) {
            m.classList.remove('open');
            document.body.style.overflow = '';
        }
    }

    function openShiftDateBranch(id, date, branchId) {
        document.getElementById('sdb_shift_id').value = id;
        document.getElementById('sdb_date').value = date;
        document.getElementById('sdb_branch').value = branchId;
        document.getElementById('shiftDateBranchModal').classList.add('open');
        document.body.style.overflow = 'hidden';
    }
    function closeShiftDateBranch() {
        document.getElementById('shiftDateBranchModal').classList.remove('open');
        document.body.style.overflow = '';
    }
    var rvEmp = {};       // أرقام الموظف (الكاشير)
    var rvRevealed = false;  // اتكشفت أرقام الموظف؟ (بعد ما يدوس «قارن»)
    var rvOpen = { 'كاش': false, 'فيزا': false, 'إنستا باي': false };

    // ─────────────────────────────────────────────────────────────────
    //  «قارن» — المرحلة التانية
    //  بيكشف أرقام الموظف، يبني المقارنة، ويظهر أزرار القرار.
    //  قبل كده المحاسب بيدخل أرقامه وهو مش شايف حاجة.
    // ─────────────────────────────────────────────────────────────────
    function rvCompare(btn) {
        var sysEl = document.getElementById('rv_acc_system');
        if (sysEl.value.trim() === '') {
            fgToast('اكتب قيمة السيستم الأول.', 'warn');
            sysEl.focus();
            return;
        }

        rvRevealed = true;
        document.getElementById('cmp_locked').style.display  = 'none';
        document.getElementById('cmp_table').style.display   = '';
        document.getElementById('cmp_msg').style.display     = '';
        document.getElementById('cmp_decide').style.display  = '';

        rvCalc();
        rvPtab(btn, 'rp_compare');
    }
    function rvTab(btn, pane) {
        document.querySelectorAll('.rv-tab').forEach(t=>t.classList.remove('active'));
        btn.classList.add('active');
        document.querySelectorAll('.rv-pane').forEach(p=>p.classList.remove('active'));
        document.getElementById(pane).classList.add('active');
    }
    function rvPtab(btn, pane) {
        document.querySelectorAll('.rv-ptab').forEach(t=>t.classList.remove('active'));
        btn.classList.add('active');
        document.querySelectorAll('.rv-ppane').forEach(p=>p.classList.remove('active'));
        document.getElementById(pane).classList.add('active');
    }
    function escHtml(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;')
            .replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function openReview(data) {
        rvEmp = data;
        document.getElementById('rvForm').reset();
        document.getElementById('rv_shift_id').value = data.id;
        document.getElementById('rvTitle').textContent = 'مراجعة — ' + data.branch + ' — وردية' + (data.no ? ' #' + data.no : '');

        // ملء بيانات مصروفات الدرج
        var expBody = document.getElementById('rv_exp_body');
        var expTotal = parseFloat(data.expenses_total) || 0;
        var tabExpT = document.getElementById('rv_tab_exp_t');
        if (tabExpT) tabExpT.textContent = expTotal.toFixed(2);
        var expT = document.getElementById('rv_exp_t');
        if (expT) expT.textContent = expTotal.toFixed(2);

        if (expBody) {
            expBody.innerHTML = '';
            var exps = data.expenses || [];
            if (exps.length === 0) {
                expBody.innerHTML = '<tr><td colspan="3" style="text-align:center;color:var(--ink-400);padding:16px;">لا توجد مصروفات مسجلة لهذه الوردية.</td></tr>';
            } else {
                exps.forEach(function(x) {
                    var tr = document.createElement('tr');
                    tr.innerHTML = '<td style="padding:7px 8px;font-weight:500;">' + (x.account_name ? escHtml(x.account_name) : '<span style="color:var(--ink-400);">— بدون تصنيف —</span>') + '</td>'
                                 + '<td style="padding:7px 8px;text-align:center;font-weight:700;color:var(--neg);">' + (parseFloat(x.amount)||0).toFixed(2) + ' ج.م</td>'
                                 + '<td style="padding:7px 8px;color:var(--ink-600);">' + (x.note ? escHtml(x.note) : '—') + '</td>';
                    expBody.appendChild(tr);
                });
            }
        }

        // تصفير الحالة — كل وردية بتبدأ مقفولة
        rvRevealed = false;
        rvOpen = { 'كاش في الدرج': false, 'فيزا': false, 'إنستا باي': false };
        document.getElementById('cmp_locked').style.display  = '';
        document.getElementById('cmp_table').style.display   = 'none';
        document.getElementById('cmp_msg').style.display     = 'none';
        document.getElementById('cmp_decide').style.display  = 'none';

        // تصفير الكاش والسطور
        document.querySelectorAll('.rv-denom').forEach(i=>i.value='');
        rvCalc();
        rvPtab(document.querySelector('.rv-ptab'), 'rp_cash');
        document.getElementById('rvModal').classList.add('open');
        document.body.style.overflow = 'hidden';
    }
    function closeReview() {
        document.getElementById('rvModal').classList.remove('open');
        document.body.style.overflow = '';
    }
    function sumClass(cls) {
        var t = 0;
        document.querySelectorAll('.' + cls).forEach(function(i){ t += parseFloat(i.value)||0; });
        return t;
    }
    function rvCalc() {
        // كاش
        var cash = 0;
        document.querySelectorAll('.rv-denom').forEach(function(i){
            var d = parseFloat(i.dataset.d)||0, c = parseFloat(i.value)||0, sub = d*c;
            cash += sub;
            var el = document.getElementById('rvd_' + String(d).replace('.','_'));
            if (el) el.textContent = sub.toFixed(2);
        });
        var visa = sumClass('rv-visa'), insta = sumClass('rv-instapay');
        document.getElementById('rv_cash_t').textContent = cash.toFixed(2);
        document.getElementById('rv_visa_t').textContent = visa.toFixed(2);
        document.getElementById('rv_instapay_t').textContent = insta.toFixed(2);
        // خزّن في الحقول المخفية
        document.getElementById('rv_acc_cash').value = cash;
        document.getElementById('rv_acc_visa').value = visa;
        document.getElementById('rv_acc_instapay').value = insta;
        // جدول المقارنة
        var sys = parseFloat(document.getElementById('rv_acc_system').value)||0;
        var expTotal = parseFloat(rvEmp.expenses_total)||0;
        var rows = [
            ['كاش في الدرج', rvEmp.cash, cash],
            ['مصروفات من الدرج', expTotal, expTotal],
            ['إجمالي الكاش المبيوع', (rvEmp.cash||0) + expTotal, cash + expTotal],
            ['فيزا', rvEmp.visa, visa],
            ['إنستا باي', rvEmp.instapay, insta],
            ['السيستم', rvEmp.system, sys],
        ];
        var empTotal = (rvEmp.cash||0) + expTotal + (rvEmp.visa||0) + (rvEmp.instapay||0);
        var accTotal = cash + expTotal + visa + insta;
        rows.push(['الإجمالي المحصّل', empTotal, accTotal]);

        // ★ بند الفرق: (الإجمالي − السيستم) لكل طرف.
        //   ده الرقم اللي بيظهر في التقفيل، وكان ناقص من المقارنة —
        //   فالمحاسب ماكانش شايف فرق الموظف قصاد فرقه.
        var empDiff = empTotal - (rvEmp.system||0);
        var accDiff = accTotal - sys;
        rows.push(['__DIFF__', empDiff, accDiff]);

        var html = '', allMatch = true;
        rows.forEach(function(r){
            var emp = parseFloat(r[1])||0, acc = parseFloat(r[2])||0, df = acc - emp;
            var ok = Math.abs(df) < 0.01;

            // صف الفرق: شكل مختلف — بنوري العجز/الزيادة مش المطابقة
            if (r[0] === '__DIFF__') {
                var cls = function(v){
                    if (Math.abs(v) < 0.01) return 'cmp-ok';
                    return 'cmp-bad';
                };
                html += '<tr style="border-top:2px solid var(--line);font-weight:bold;">'
                      + '<td>الفرق</td>'
                      + '<td class="'+cls(emp)+'">'+emp.toFixed(2)+'</td>'
                      + '<td class="'+cls(acc)+'">'+acc.toFixed(2)+'</td>'
                      + '<td class="'+(ok?'cmp-ok':'cmp-bad')+'">'+(ok?'✓ مطابق':df.toFixed(2))+'</td>'
                      + '</tr>';
                return;
            }

            if (r[0] !== 'السيستم' && !ok) allMatch = false;

            var isExp = rvOpen.hasOwnProperty(r[0]);
            var isOpen = isExp && rvOpen[r[0]];

            if (isExp) {
                html += '<tr class="cmp-expandable' + (isOpen ? ' open' : '') + '" style="cursor:pointer;" onclick="rvToggle(\'' + r[0] + '\')">'
                      + '<td>' + r[0] + ' <i class="ti ti-chevron-down cmp-arrow"></i></td>'
                      + '<td>' + emp.toFixed(2) + '</td>'
                      + '<td>' + acc.toFixed(2) + '</td>'
                      + '<td class="' + (ok ? 'cmp-ok' : 'cmp-bad') + '">' + (ok ? '✓ مطابق' : df.toFixed(2)) + '</td>'
                      + '</tr>';
                if (isOpen) {
                    if (r[0] === 'كاش في الدرج' || r[0] === 'كاش') html += rvRenderCashDetail();
                    else if (r[0] === 'فيزا') html += rvRenderLinesDetail('visa');
                    else if (r[0] === 'إنستا باي') html += rvRenderLinesDetail('instapay');
                }
            } else {
                html += '<tr><td>' + r[0] + '</td><td>' + emp.toFixed(2) + '</td><td>' + acc.toFixed(2) + '</td>'
                      + '<td class="' + (ok ? 'cmp-ok' : 'cmp-bad') + '">' + (ok ? '✓ مطابق' : df.toFixed(2)) + '</td></tr>';
            }
        });
        // مايكشفش أرقام الموظف قبل ما يدوس «قارن»
        if (!rvRevealed) return;
        document.getElementById('cmp_body').innerHTML = html;
        var msg = document.getElementById('cmp_msg');
        if (allMatch) { msg.textContent = '✓ أرقامك مطابقة تماماً — يمكنك الاعتماد'; msg.style.background='var(--pos-soft)'; msg.style.color='var(--pos)'; }
        else { msg.textContent = 'فيه فروقات بين أرقامك وأرقام الموظف — راجع قبل القرار'; msg.style.background='var(--warn-soft)'; msg.style.color='var(--warn)'; }
    }

    function rvToggle(item) {
        if (rvOpen.hasOwnProperty(item)) {
            rvOpen[item] = !rvOpen[item];
            rvCalc();
        }
    }

    function rvRenderCashDetail() {
        var denoms = [200, 100, 50, 20, 10, 5, 1, 0.5, 0.25];
        var rowsHtml = '';
        var empCash = rvEmp.empCash || {};

        denoms.forEach(function(d){
            var empCount = 0;
            var k1 = d, k2 = String(d), k3 = d.toFixed(2);
            if (empCash[k1] !== undefined) empCount = parseInt(empCash[k1], 10) || 0;
            else if (empCash[k2] !== undefined) empCount = parseInt(empCash[k2], 10) || 0;
            else if (empCash[k3] !== undefined) empCount = parseInt(empCash[k3], 10) || 0;

            var inp = document.querySelector('.rv-denom[data-d="' + d + '"]');
            var accCount = inp ? (parseInt(inp.value, 10) || 0) : 0;

            // وري الفئات اللي فيها رقم عند أي طرف بس. لو التنين صفر، الصف يتشال.
            if (empCount === 0 && accCount === 0) return;

            var diffCount = accCount - empCount;
            var diffVal   = diffCount * d;

            var diffCountStr = (diffCount === 0) ? '0' : ((diffCount > 0 ? '+' : '') + diffCount);
            var diffValStr   = (Math.abs(diffVal) < 0.001) ? '0.00' : ((diffVal > 0 ? '+' : '') + diffVal.toFixed(2));

            var countOk = (diffCount === 0);
            var valOk   = (Math.abs(diffVal) < 0.001);

            rowsHtml += '<tr>'
                      + '<td>' + d + ' ج.م</td>'
                      + '<td>' + empCount + '</td>'
                      + '<td>' + accCount + '</td>'
                      + '<td class="' + (countOk ? 'cmp-ok' : 'cmp-bad') + '">' + diffCountStr + '</td>'
                      + '<td class="' + (valOk ? 'cmp-ok' : 'cmp-bad') + '">' + diffValStr + '</td>'
                      + '</tr>';
        });

        var body = rowsHtml
            ? '<div style="overflow-x:auto;"><table class="cmp-sub-table"><thead><tr>'
              + '<th>الفئة</th><th>عدد الموظف</th><th>عدد المحاسب</th><th>فرق العدد</th><th>فرق القيمة</th>'
              + '</tr></thead><tbody>' + rowsHtml + '</tbody></table></div>'
            : '<div style="padding:10px;text-align:center;color:var(--ink-500);font-size:13px;">مفيش تفاصيل فئات متسجّلة.</div>';

        return '<tr class="cmp-detail"><td colspan="4">' + body + '</td></tr>';
    }

    // السيريال نص حر بيكتبه الكاشير — لازم يتهرّب قبل ما يدخل innerHTML.
    function rvEsc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function rvRenderLinesDetail(method) {
        var empLines = (rvEmp.empLines && rvEmp.empLines[method]) ? rvEmp.empLines[method] : [];
        var amtInputs = document.querySelectorAll('.rv-' + method);
        var refInputs = document.querySelectorAll('.rv-' + method + '-ref'); // rv-visa-ref / rv-instapay-ref

        var accLines = [];
        for (var i = 0; i < amtInputs.length; i++) {
            var refVal = (refInputs[i] ? refInputs[i].value : '').trim();
            var amtRaw = amtInputs[i] ? amtInputs[i].value.trim() : '';
            if (refVal !== '' || amtRaw !== '') {
                var amtVal = (amtRaw !== '' && !isNaN(parseFloat(amtRaw))) ? parseFloat(amtRaw) : 0;
                accLines.push({ ref: refVal, amt: amtVal });
            }
        }

        if (empLines.length === 0 && accLines.length === 0) {
            return '<tr class="cmp-detail"><td colspan="4"><div style="padding:10px;text-align:center;color:var(--ink-500);font-size:13px;">مفيش سطور متسجّلة.</div></td></tr>';
        }

        // السيريال بيتقارن كنص بعد .trim() وغير حساس لحالة الأحرف.
        // 🔴 التطبيع ده للمقارنة بس — اللي بيتعرض هو النص الخام بعد rvEsc().
        var normRef = function(ref) { return String(ref || '').trim().toLowerCase(); };
        var empUsed = [], accUsed = [], matches = [];

        // مرحلة ١ — بالسيريال (الطرفين سيريالهم مش فاضي ومتطابق)
        for (var e = 0; e < empLines.length; e++) {
            var eRefNorm = normRef(empLines[e].ref);
            if (eRefNorm === '') continue;
            for (var a = 0; a < accLines.length; a++) {
                if (accUsed[a]) continue;
                var aRefNorm = normRef(accLines[a].ref);
                if (aRefNorm === '' || eRefNorm !== aRefNorm) continue;

                empUsed[e] = true; accUsed[a] = true;
                var eAmt = parseFloat(empLines[e].amt || 0), aAmt = parseFloat(accLines[a].amt || 0), df = aAmt - eAmt;
                matches.push({
                    status: Math.abs(df) < 0.01 ? '✓ مطابق' : 'المبلغ مختلف',
                    empRef: empLines[e].ref, empAmt: eAmt,
                    accRef: accLines[a].ref, accAmt: aAmt, diff: df
                });
                break;
            }
        }

        // مرحلة ٢ — بالمبلغ (الباقي من الطرفين اللي مبلغهم واحد)
        for (var e = 0; e < empLines.length; e++) {
            if (empUsed[e]) continue;
            var eAmt = parseFloat(empLines[e].amt || 0), eKey = eAmt.toFixed(2);
            for (var a = 0; a < accLines.length; a++) {
                if (accUsed[a]) continue;
                var aAmt = parseFloat(accLines[a].amt || 0);
                if (aAmt.toFixed(2) === eKey) {
                    empUsed[e] = true; accUsed[a] = true;
                    matches.push({
                        status: '✓ مطابق',
                        empRef: empLines[e].ref, empAmt: eAmt,
                        accRef: accLines[a].ref, accAmt: aAmt, diff: aAmt - eAmt
                    });
                    break;
                }
            }
        }

        // مرحلة ٣ — الباقي (اللي مالقاش شريك)
        for (var e = 0; e < empLines.length; e++) {
            if (empUsed[e]) continue;
            var eAmt = parseFloat(empLines[e].amt || 0);
            matches.push({ status: 'عند الموظف بس', empRef: empLines[e].ref, empAmt: eAmt, accRef: null, accAmt: null, diff: -eAmt });
        }
        for (var a = 0; a < accLines.length; a++) {
            if (accUsed[a]) continue;
            var aAmt = parseFloat(accLines[a].amt || 0);
            matches.push({ status: 'عند المحاسب بس', empRef: null, empAmt: null, accRef: accLines[a].ref, accAmt: aAmt, diff: aAmt });
        }

        // الترتيب في العرض: المشاكل الأول، المطابق تحت
        var badMatches = [], okMatches = [];
        for (var m = 0; m < matches.length; m++) {
            if (matches[m].status === '✓ مطابق') okMatches.push(matches[m]);
            else badMatches.push(matches[m]);
        }
        var displayMatches = badMatches.concat(okMatches);

        // سطر الملخص فوق الجدول
        var countOk = okMatches.length, countDiff = 0, countEmpOnly = 0, countAccOnly = 0;
        for (var b = 0; b < badMatches.length; b++) {
            if (badMatches[b].status === 'المبلغ مختلف') countDiff++;
            else if (badMatches[b].status === 'عند الموظف بس') countEmpOnly++;
            else if (badMatches[b].status === 'عند المحاسب بس') countAccOnly++;
        }
        var summaryParts = [];
        if (countOk > 0) summaryParts.push(countOk + ' مطابق');
        if (countDiff > 0) summaryParts.push(countDiff + ' المبلغ مختلف');
        if (countEmpOnly > 0) summaryParts.push(countEmpOnly + ' عند الموظف بس');
        if (countAccOnly > 0) summaryParts.push(countAccOnly + ' عند المحاسب بس');
        var summaryText = summaryParts.join(' · ');
        var summaryHtml = summaryText ? '<div style="font-size:12px;color:var(--ink-500);margin-bottom:6px;">' + summaryText + '</div>' : '';

        // الكاشير مش بيسجّل سيريالات، فالعمودين بيفضلوا «—» وبياخدوا نص
        // عرض الجدول على الفاضي. لو مفيش ولا سيريال عند أي طرف نخفيهم.
        // 🔴 ده إخفاء **عرض** بس — المطابقة فوق لسه بتستعمل السيريال
        //    لما يكون موجود، والأولوية بتاعته زي ما هي.
        var hasAnyRef = false;
        for (var r = 0; r < matches.length; r++) {
            if (String(matches[r].empRef || '').trim() !== '' ||
                String(matches[r].accRef || '').trim() !== '') { hasAnyRef = true; break; }
        }

        var rowsHtml = '';
        for (var k = 0; k < displayMatches.length; k++) {
            var item = displayMatches[k], isOk = (item.status === '✓ مطابق'), stCls = isOk ? 'cmp-ok' : 'cmp-bad';
            var empRefDisplay = (item.empRef !== null && String(item.empRef).trim() !== '') ? String(item.empRef) : '—';
            var empAmtDisplay = (item.empAmt !== null) ? item.empAmt.toFixed(2) : '—';
            var accRefDisplay = (item.accRef !== null && String(item.accRef).trim() !== '') ? String(item.accRef) : '—';
            var accAmtDisplay = (item.accAmt !== null) ? item.accAmt.toFixed(2) : '—';
            var diffStr = isOk ? '0.00' : (Math.abs(item.diff) < 0.01 ? '0.00' : ((item.diff > 0 ? '+' : '') + item.diff.toFixed(2)));

            rowsHtml += '<tr>'
                      + '<td class="' + stCls + '">' + item.status + '</td>'
                      + (hasAnyRef ? '<td>' + rvEsc(empRefDisplay) + '</td>' : '')
                      + '<td>' + empAmtDisplay + '</td>'
                      + (hasAnyRef ? '<td>' + rvEsc(accRefDisplay) + '</td>' : '')
                      + '<td>' + accAmtDisplay + '</td>'
                      + '<td class="' + stCls + '">' + diffStr + '</td>'
                      + '</tr>';
        }

        // 🔴 الرؤوس لازم تتغيّر مع الخانات — ٤ خانات تحت ٦ رؤوس = جدول مكسور
        var body = summaryHtml + '<div style="overflow-x:auto;"><table class="cmp-sub-table"><thead><tr>'
                 + '<th>الحالة</th>'
                 + (hasAnyRef ? '<th>سيريال الموظف</th>' : '')
                 + '<th>مبلغ الموظف</th>'
                 + (hasAnyRef ? '<th>سيريال المحاسب</th>' : '')
                 + '<th>مبلغ المحاسب</th><th>الفرق</th>'
                 + '</tr></thead><tbody>' + rowsHtml + '</tbody></table></div>';

        return '<tr class="cmp-detail"><td colspan="4">' + body + '</td></tr>';
    }
    function rvDecide(decision) {
        if (!rvRevealed) { alert('اضغط «قارن» الأول.'); return false; }
        rvCalc();
        document.getElementById('rv_decision').value = decision;

        // قيمة السيستم إجبارية عند الاعتماد بس.
        // (عند الرفض مش إجبارية — ممكن يرفض عشان الأرقام ناقصة أصلًا.)
        if (decision === 'approved') {
            var sysEl = document.getElementById('rv_acc_system');
            if (sysEl.value.trim() === '') {
                alert('اكتب قيمة السيستم قبل الاعتماد.');
                sysEl.focus();
                return false;
            }
        }

        if (decision === 'rejected') {
            var reason = document.getElementById('rv_reason').value.trim();
            if (!reason) { alert('اكتب سبب الرجوع أولاً.'); return false; }
            return confirm('تأكيد إرجاع الوردية للموظف؟');
        }
        return confirm('تأكيد اعتماد الوردية وإنشاء القيود؟ الأرقام هتدخل الخزائن.');
    }

    // إضافة صف جديد لجدول الفيزا/الإنستا.
    // كانت الجداول 10 صفوف ثابتة — لو الوردية فيها 11 عملية، المراجع
    // ما كانش يقدر يضيف الحادية عشرة ولا يقفل الوردية.
    function rvAddRow(mk, focusIt) {
        var tb = document.getElementById('tb_' + mk);
        if (!tb) return null;

        var n  = tb.rows.length + 1;
        var tr = tb.insertRow();

        var c1 = tr.insertCell();
        c1.style.cssText = 'padding:4px;color:var(--ink-500);font-size:13px;text-align:center;';
        c1.textContent = n;

        var c2 = tr.insertCell();
        c2.style.padding = '4px';
        c2.innerHTML = '<input type="text" class="rv-' + mk + '-ref" placeholder="رقم السيريال" ' // rv-visa-ref / rv-instapay-ref
                     + 'style="width:100%;padding:6px 8px;border:1.5px solid var(--line);'
                     + 'border-radius:7px;font-family:inherit;font-size:13px;" aria-label="رقم السيريال">';

        var c3 = tr.insertCell();
        c3.style.padding = '4px';
        c3.innerHTML = '<input type="number" step="0.01" min="0" class="rv-' + mk + '" '
                     + 'value="" placeholder="0.00" oninput="rvCalc()" '
                     + 'style="width:100%;padding:6px 8px;border:1.5px solid var(--line);'
                     + 'border-radius:7px;font-family:inherit;font-size:13px;text-align:center;" aria-label="0.00">';

        if (focusIt) c2.querySelector('input').focus();
        return tr;
    }

    // السهم لتحت: ينقل للصف اللي بعده، ولو إحنا في آخر صف يضيف صف جديد.
    (function(){
        ['visa','instapay'].forEach(function(mk){
            var tb = document.getElementById('tb_' + mk);
            if (!tb) return;

            tb.addEventListener('keydown', function(e){
                if (e.key !== 'ArrowDown' && e.key !== 'Enter') return;
                if (e.target.tagName !== 'INPUT') return;
                e.preventDefault();
                e.stopPropagation();   // مهم: يمنع listener الفورم من التحرك تاني

                var td  = e.target.closest('td');
                var tr  = e.target.closest('tr');
                var col = td.cellIndex;              // 1 = سيريال، 2 = مبلغ
                var nxt = tr.nextElementSibling;

                // آخر صف؟ ضيف واحد جديد
                if (!nxt) nxt = rvAddRow(mk, false);
                if (!nxt) return;

                var inp = nxt.cells[col] && nxt.cells[col].querySelector('input');
                if (inp) inp.focus();
            });
        });
    })();

    // Enter / الأسهم = انتقال بين الحقول (مش اعتماد المراجعة).
    //
    // ملاحظة: الجداول (فيزا/إنستا) ليها listener خاص فوق بيضيف
    // صف جديد لو إحنا في آخر صف. الـlistener ده بيشتغل الأول (على الـtbody)
    // وبيعمل preventDefault، فمش هيتعارض مع اللي تحت.
    /* تأكيد قرار العهدة. الاعتماد بيحرّك فلوس فعلاً، فالتأكيد إجباري.
       بنقرا decision من الحقل المخفي اللي زرار الإرسال ملاه في onclick. */
    function cstConfirm(form) {
        var decision = form.decision.value;
        var branch   = form.branch_label.value;
        if (decision === 'rejected') {
            if (!form.reason.value.trim()) {
                alert('اكتب سبب الرفض.');
                form.reason.focus();
                return false;
            }
            return confirm('رفض عهدة ' + branch + '؟\n\nهترجع للكاشير يظبّطها. مفيش فلوس هتتحرّك.');
        }
        var amt = form.acc_counted.value;
        if (amt === '' || isNaN(parseFloat(amt))) {
            alert('اكتب المبلغ اللي استلمته قبل الاعتماد.');
            form.acc_counted.focus();
            return false;
        }
        return confirm('اعتماد عهدة ' + branch + ' بمبلغ ' + amt + ' جنيه؟\n\n'
            + 'الفلوس هتدخل الخزينة فعلاً والقيود هتتعمل. مفيش تراجع.');
    }

    (function(){
        var form = document.getElementById('rvForm');
        if (!form) return;

        form.addEventListener('keydown', function(e){
            if (e.key !== 'Enter' && e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;

            var el = e.target;
            if (el.tagName === 'TEXTAREA') return;
            if (el.tagName !== 'INPUT' && el.tagName !== 'SELECT') return;
            if (e.defaultPrevented) return;   // الجدول اتعامل معاه خلاص

            e.preventDefault();

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
            if (next.select) next.select();
        });
    })();

    function filterReviewBranch(branchId) {
        try {
            localStorage.setItem('rv_branch', String(branchId));
        } catch (e) {}

        document.querySelectorAll('#reviewBranchTabs .btab').forEach(function(b) {
            b.classList.toggle('active', b.dataset.b === String(branchId));
        });

        var visiblePending = 0;
        document.querySelectorAll('#pane_pending .shift-card').forEach(function(el) {
            var match = (branchId === 'all' || el.dataset.branch === String(branchId));
            el.hidden = !match;
            if (match) visiblePending++;
        });

        var visibleCustody = 0;
        document.querySelectorAll('#pane_custody .rv-pending-custody').forEach(function(el) {
            var match = (branchId === 'all' || el.dataset.branch === String(branchId));
            el.hidden = !match;
            if (match) visibleCustody++;
        });

        document.querySelectorAll('#pane_history .shift-card, #pane_custody .rv-custody-history').forEach(function(el) {
            var match = (branchId === 'all' || el.dataset.branch === String(branchId));
            el.hidden = !match;
        });

        document.querySelectorAll('#pane_seq .seq-issue-row').forEach(function(el) {
            var match = (branchId === 'all' || el.dataset.branch === String(branchId));
            el.hidden = !match;
        });

        var cp = document.getElementById('cnt_pending');
        if (cp) cp.textContent = visiblePending;
        var cc = document.getElementById('cnt_custody');
        if (cc) cc.textContent = visibleCustody;
    }

    document.addEventListener('DOMContentLoaded', function() {
        var saved = 'all';
        try {
            saved = localStorage.getItem('rv_branch') || 'all';
        } catch (e) {}
        if (saved !== 'all' && !document.querySelector('#reviewBranchTabs .btab[data-b="' + saved + '"]')) {
            saved = 'all';
        }
        filterReviewBranch(saved);

        var urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('tab') === 'seq') {
            var sBtn = document.getElementById('tab_seq_btn');
            if (sBtn) {
                rvTab(sBtn, 'pane_seq');
            }
        }
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
