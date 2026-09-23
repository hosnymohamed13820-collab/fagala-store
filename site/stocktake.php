<?php
/**
 * stocktake.php — الجرد
 * نظام الفجالة ستور المالي
 *
 * المرحلة 1: الجلسة + المحاضر + إدخال الأصناف (مع تحويل الوحدة والتجميع).
 * - الأونر يبدأ الجلسة (مع رفع ملف الرصيد اختيارياً) → زر إضافة محضر ينوّر للكل.
 * - الموظف يضيف محضر، يجرد الأصناف (الكود → الاسم/الوحدة/رصيد السيستم تلقائياً).
 * - لو ضرب كود مربوط بصنف موجود → النظام يجمّع ويطلّع السطر فوق.
 * - الأونر ينهي الجلسة → الأزرار تتقفل.
 *
 * كل حاجة مقترنة باليوزر والوقت.
 */
require_once __DIR__ . '/auth.php';
require_permission('stocktake');
require_once __DIR__ . '/stocktake_lib.php';

// بحث بالاسم (AJAX) — يرجّع JSON
if (isset($_GET['search'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        echo json_encode(stk_search_by_name($_GET['search']), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        echo json_encode([]);
    }
    exit;
}

// جلب اسم الصنف بالكود (لخانة الاسم في فورم الجرد/المراجعة)
if (isset($_GET['lookup'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $info = stk_lookup_code(trim($_GET['lookup']));
        if ($info) {
            echo json_encode(['name' => $info['name']], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['name' => null, 'notfound' => true], JSON_UNESCAPED_UNICODE);
        }
    } catch (Throwable $ex) {
        echo json_encode(['name' => null, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// الأكواد المربوطة بكود واحد (أداة استعلام مستقلة) — يرجّع JSON
if (isset($_GET['linked'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $res = stk_linked_codes(trim($_GET['linked']));
        if ($res) {
            echo json_encode(['ok' => true] + $res, JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['ok' => false, 'notfound' => true], JSON_UNESCAPED_UNICODE);
        }
    } catch (Throwable $ex) {
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$pdo = db();
$u   = current_user();
$uid = (int) $u['id'];
$owner = is_owner();
$flash = ''; $flashType = 'success';

$branches = $pdo->query('SELECT id, name FROM branches WHERE is_active=1 ORDER BY name')->fetchAll();
// قائمة المستخدمين (للأونر — لإعادة تعيين الجرد/المراجعة)
$allUsers = [];
if (is_owner()) {
    $allUsers = $pdo->query('SELECT id, full_name FROM users WHERE is_active=1 ORDER BY full_name')->fetchAll();
}
// أماكن الجرد (الأقسام/الأرفف) — لو الجدول موجود
$stkPlaces = [];
try {
    $stkPlaces = $pdo->query('SELECT name FROM stocktake_places WHERE is_active=1 ORDER BY sort_order, id')->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $ex) { $stkPlaces = []; }
$session = stk_active_session();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $flash = 'انتهت صلاحية الجلسة، حاول مرة أخرى.';
        $flashType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        // helper: جلب المحضر للتحقق من الصلاحية
        $loadSheet = function(int $sid) use ($pdo) {
            $s = $pdo->prepare('SELECT * FROM stocktake_sheets WHERE id=?');
            $s->execute([$sid]);
            return $s->fetch() ?: null;
        };
        try {
            if ($action === 'start_session' && $owner) {
                if ($session) throw new RuntimeException('يوجد جلسة جرد نشطة بالفعل.');
                $pdo->prepare('INSERT INTO stocktake_sessions (status, started_by) VALUES ("active", ?)')->execute([$uid]);
                $session = stk_active_session();
                // رفع ملف الرصيد لو موجود
                if (!empty($_FILES['balance_file']['tmp_name'])) {
                    $n = stk_import_balance((int)$session['id'], $_FILES['balance_file']['tmp_name']);
                    $flash = "تم بدء الجرد ورفع رصيد $n صنف.";
                } else {
                    $flash = 'تم بدء الجرد (بدون رصيد).';
                }
            }
            elseif ($action === 'upload_balance' && $owner && $session) {
                if (empty($_FILES['balance_file']['tmp_name'])) throw new RuntimeException('اختر ملف الرصيد.');
                $n = stk_import_balance((int)$session['id'], $_FILES['balance_file']['tmp_name']);
                $flash = "تم رفع رصيد $n صنف.";
            }
            elseif ($action === 'end_session' && $owner && $session) {
                $pdo->prepare('UPDATE stocktake_sessions SET status="closed", closed_by=?, closed_at=NOW() WHERE id=?')
                    ->execute([$uid, (int)$session['id']]);
                $flash = 'تم إنهاء الجرد. الأزرار مقفولة الآن.';
                $session = null;
            }
            elseif ($action === 'reopen_session' && $owner) {
                // إعادة فتح آخر جلسة مقفولة (لو حد أنهى بالغلط) — البيانات كلها محفوظة
                $last = $pdo->query("SELECT id FROM stocktake_sessions WHERE status='closed' ORDER BY id DESC LIMIT 1")->fetchColumn();
                if ($last) {
                    // اتأكد إن مفيش جلسة active تانية
                    $act = $pdo->query("SELECT COUNT(*) FROM stocktake_sessions WHERE status='active'")->fetchColumn();
                    if ($act > 0) throw new RuntimeException('فيه جلسة جرد نشطة بالفعل. اقفلها الأول.');
                    $pdo->prepare("UPDATE stocktake_sessions SET status='active', closed_by=NULL, closed_at=NULL WHERE id=?")->execute([(int)$last]);
                    $flash = 'تم إعادة فتح آخر جرد. كل المحاضر والبيانات رجعت زي ما هي.';
                    header('Location: stocktake.php?msg=' . urlencode($flash));
                    exit;
                } else {
                    throw new RuntimeException('مفيش جرد مقفول نقدر نرجّعه.');
                }
            }
            elseif ($action === 'delete_sheet') {
                $sheetId = (int) ($_POST['sheet_id'] ?? 0);
                if (!$owner) throw new RuntimeException('مسح المحضر للأونر فقط.');
                stk_delete_sheet($sheetId);
                $flash = 'تم مسح المحضر بالكامل.';
                header('Location: stocktake.php?msg=' . urlencode($flash));
                exit;
            }
            elseif ($action === 'reassign' && $owner) {
                $sheetId = (int) ($_POST['sheet_id'] ?? 0);
                $field   = $_POST['field'] ?? '';   // counted_by أو reviewed_by
                $newUser = (int) ($_POST['user_id'] ?? 0) ?: null;
                if (!in_array($field, ['counted_by','reviewed_by'], true)) {
                    throw new RuntimeException('حقل غير صالح.');
                }
                $pdo->prepare("UPDATE stocktake_sheets SET {$field}=? WHERE id=?")->execute([$newUser, $sheetId]);
                $flash = ($field==='counted_by'?'تم تغيير القائم بالجرد.':'تم تغيير المراجع.');
                header('Location: stocktake.php?sheet=' . $sheetId . ($field==='reviewed_by'?'&mode=review':'') . '&msg=' . urlencode($flash));
                exit;
            }
            elseif ($action === 'change_place' && $owner) {
                // الأونر يغيّر مكان المحضر (الناس بتجرد أماكن غير المحددة)
                $sheetId = (int) ($_POST['sheet_id'] ?? 0);
                $place   = trim($_POST['place_note'] ?? '');
                if ($place === '__custom__') {
                    $place = trim($_POST['place_custom'] ?? '');
                }
                if ($place === '') throw new RuntimeException('اكتب المكان.');
                $pdo->prepare('UPDATE stocktake_sheets SET place_note=? WHERE id=?')->execute([$place, $sheetId]);
                $flash = 'تم تغيير مكان المحضر إلى: ' . $place;
                header('Location: stocktake.php?sheet=' . $sheetId . '&msg=' . urlencode($flash));
                exit;
            }
            elseif ($action === 'add_sheet' && $session) {
                $branchId = (int) ($_POST['branch_id'] ?? 0);
                $place    = trim($_POST['place_note'] ?? '');
                // الأونر يقدر يكتب مكان مخصص يتجاوز القائمة
                if ($owner && !empty($_POST['place_custom'])) {
                    $place = trim($_POST['place_custom']);
                }

                // حماية من التكرار: نمنع بس تكرار *نفس المكان* لو محضره لسه مفتوح/مراجعة.
                // (كده الموظف يقدر يعمل محاضر لأماكن مختلفة عادي — رف تاني، قسم تاني —
                //  لكن لو ضرب نفس المكان تاني بالغلط أو النت أعاد الإرسال، نوديه للمحضر الموجود.)
                if ($place !== '') {
                    // نطابق بعد توحيد المسافات عشان الاختلافات البسيطة ماتعملش محاضر مكررة
                    $normPlace = preg_replace('/\s+/u', ' ', $place);
                    $dupPlace = $pdo->prepare(
                        "SELECT id FROM stocktake_sheets
                         WHERE session_id=? AND REPLACE(REPLACE(place_note,'  ',' '),'  ',' ')=? AND status IN ('open','reviewing')
                         ORDER BY id DESC LIMIT 1"
                    );
                    $dupPlace->execute([(int)$session['id'], $normPlace]);
                    $dupId = (int) $dupPlace->fetchColumn();
                    if ($dupId > 0) {
                        $_SESSION['flash'] = 'فيه محضر بنفس المكان "' . $place . '" مفتوح بالفعل — كمّل فيه بدل ما تعمل جديد.';
                        header('Location: stocktake.php?sheet=' . $dupId);
                        exit;
                    }
                }

                // رقم المحضر التسلسلي داخل الجلسة
                $maxNo = (int) $pdo->query('SELECT COALESCE(MAX(sheet_no),0) FROM stocktake_sheets WHERE session_id='.(int)$session['id'])->fetchColumn();
                $pdo->prepare(
                    'INSERT INTO stocktake_sheets (session_id, sheet_no, branch_id, place_note, counted_by, status)
                     VALUES (?, ?, ?, ?, ?, "open")'
                )->execute([(int)$session['id'], $maxNo+1, $branchId ?: null, $place ?: null, $uid]);
                $newId = (int) $pdo->lastInsertId();
                header('Location: stocktake.php?sheet=' . $newId);
                exit;
            }
            elseif ($action === 'add_line') {
                $sheetId = (int) ($_POST['sheet_id'] ?? 0);
                $code    = trim($_POST['code'] ?? '');
                $qty     = (float) ($_POST['qty'] ?? 0);
                $isAjax  = !empty($_POST['ajax']);
                $sheet = $loadSheet($sheetId);
                if (!$sheet || !stk_can_edit($sheet, $u)) {
                    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'err'=>'غير مسموح']); exit; }
                    throw new RuntimeException('غير مسموح: المحضر ده مقترن بصاحبه فقط.');
                }
                try {
                    $res = stk_add_line($sheetId, $code, $qty, $session ? (int)$session['id'] : null);
                } catch (RuntimeException $codeEx) {
                    if (mb_strpos($codeEx->getMessage(), 'غير موجود') !== false) {
                        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'notfound'=>$code]); exit; }
                        header('Location: stocktake.php?sheet=' . $sheetId . '&notfound=' . urlencode($code));
                        exit;
                    }
                    throw $codeEx;
                } catch (InvalidArgumentException $qtyEx) {
                    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'badqty'=>(string)$qty]); exit; }
                    header('Location: stocktake.php?sheet=' . $sheetId . '&badqty=' . urlencode((string)$qty));
                    exit;
                }
                // طلب AJAX: نرجّع كل أصناف المحضر JSON (عشان الجدول يتحدّث من غير reload)
                if ($isAjax) {
                    $ll = $pdo->prepare('SELECT * FROM stocktake_lines WHERE sheet_id=? ORDER BY id DESC');
                    $ll->execute([$sheetId]);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'ok' => true,
                        'merged' => $res['merged'],
                        'name' => $res['name'],
                        'lines' => $ll->fetchAll(),
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $flash = $res['merged']
                    ? 'الكود مربوط بصنف مجرود — تم تجميعه: ' . $res['name']
                    : 'تمت إضافة: ' . $res['name'];
                header('Location: stocktake.php?sheet=' . $sheetId . '&msg=' . urlencode($flash) . ($res['merged']?'&merged=1':''));
                exit;
            }
            elseif ($action === 'delete_line') {
                $lineId  = (int) ($_POST['line_id'] ?? 0);
                $sheetId = (int) ($_POST['sheet_id'] ?? 0);
                $fromReview = !empty($_POST['from_review']);
                $sheet = $loadSheet($sheetId);
                // الحذف من المراجعة بصلاحية المراجع، ومن الجرد بصلاحية صاحب المحضر
                $allowed = $fromReview ? stk_can_review($sheet, $u) : stk_can_edit($sheet, $u);
                if (!$sheet || !$allowed) throw new RuntimeException('غير مسموح بحذف الصنف.');
                stk_delete_line($lineId, $sheetId);
                $flash = 'تم حذف الصنف.';
                $back = $fromReview ? '&mode=review' : '';
                header('Location: stocktake.php?sheet=' . $sheetId . $back . '&msg=' . urlencode($flash));
                exit;
            }
            elseif ($action === 'start_review') {
                $sheetId = (int) ($_POST['sheet_id'] ?? 0);
                $sheet = $loadSheet($sheetId);
                if (!$sheet) throw new RuntimeException('المحضر غير موجود.');
                if (!$owner && (int)$u['id'] === (int)$sheet['counted_by']) {
                    throw new RuntimeException('مينفعش تراجع محضرك بنفسك — لازم يوزر تاني يراجعه.');
                }
                stk_start_review($sheetId, $uid);
                $flash = 'بدأت المراجعة. عُدّ الأصناف وأدخل كمياتك.';
                header('Location: stocktake.php?sheet=' . $sheetId . '&mode=review&msg=' . urlencode($flash));
                exit;
            }
            elseif ($action === 'set_review') {
                $sheetId = (int) ($_POST['sheet_id'] ?? 0);
                $code    = trim($_POST['code'] ?? '');
                $qty     = (float) ($_POST['review_qty'] ?? 0);
                $isAjax  = !empty($_POST['ajax']);
                $sheet = $loadSheet($sheetId);
                if (!$sheet || !stk_can_review($sheet, $u)) {
                    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'err'=>'غير مسموح']); exit; }
                    throw new RuntimeException('غير مسموح: المراجعة لصاحب المحضر والمراجع فقط.');
                }
                try {
                    $res = stk_add_review($sheetId, $code, $qty);
                } catch (RuntimeException $codeEx) {
                    if (mb_strpos($codeEx->getMessage(), 'غير موجود') !== false) {
                        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'notfound'=>$code]); exit; }
                        header('Location: stocktake.php?sheet=' . $sheetId . '&mode=review&notfound=' . urlencode($code));
                        exit;
                    }
                    throw $codeEx;
                } catch (InvalidArgumentException $qtyEx) {
                    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'badqty'=>(string)$qty]); exit; }
                    header('Location: stocktake.php?sheet=' . $sheetId . '&mode=review&badqty=' . urlencode((string)$qty));
                    exit;
                }
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok'=>true, 'new_item'=>$res['new_item'], 'name'=>$res['name']], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $flash = $res['new_item']
                    ? 'صنف زيادة لقاه المراجع: ' . $res['name']
                    : 'تم تسجيل جرد المراجع: ' . $res['name'];
                header('Location: stocktake.php?sheet=' . $sheetId . '&mode=review&msg=' . urlencode($flash));
                exit;
            }
            elseif ($action === 'set_review_qty') {
                // تعديل كمية المراجع لصنف (لو المراجع كتب رقم غلط)
                $sheetId = (int) ($_POST['sheet_id'] ?? 0);
                $lineId  = (int) ($_POST['line_id'] ?? 0);
                $qty     = (float) ($_POST['review_qty'] ?? 0);
                $isAjax  = !empty($_POST['ajax']);
                $sheet = $loadSheet($sheetId);
                if (!$sheet || !stk_can_review($sheet, $u)) {
                    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'err'=>'غير مسموح']); exit; }
                    throw new RuntimeException('غير مسموح.');
                }
                // نحوّل الكمية لوحدة أساسية حسب وحدة السطر
                $ln = $pdo->prepare('SELECT unit, entered_code, base_qty FROM stocktake_lines WHERE id=? AND sheet_id=?');
                $ln->execute([$lineId, $sheetId]);
                $row = $ln->fetch();
                $unit = $row ? (float)($row['unit'] ?? 1) : 1;
                if ($unit <= 0) $unit = 1;

                // ===== حماية ضد الكود المضروب في خانة الكمية =====
                // لو الكمية = كود الصنف نفسه (السكانر ضرب الكود بالغلط) → ارفض
                $enteredCode = $row['entered_code'] ?? '';
                if ($enteredCode !== '' && abs($qty - (float)$enteredCode) < 0.0001 && $qty > 100) {
                    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'badqty'=>'يبدو أن السكانر أدخل الكود في خانة الكمية. اكتب الكمية بيدك.']); exit; }
                    throw new RuntimeException('يبدو أن السكانر أدخل الكود في خانة الكمية.');
                }
                // كمية غير منطقية (فلكية)
                if ($qty > 100000) {
                    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'badqty'=>'الكمية كبيرة بشكل غير منطقي ('.$qty.').']); exit; }
                    throw new RuntimeException('الكمية كبيرة بشكل غير منطقي.');
                }

                $reviewBase = $qty * $unit;
                $pdo->prepare('UPDATE stocktake_lines SET review_qty=? WHERE id=? AND sheet_id=?')
                    ->execute([$reviewBase, $lineId, $sheetId]);
                if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit; }
                header('Location: stocktake.php?sheet=' . $sheetId . '&mode=review');
                exit;
            }
            elseif ($action === 'set_final') {
                $sheetId = (int) ($_POST['sheet_id'] ?? 0);
                $lineId  = (int) ($_POST['line_id'] ?? 0);
                $qty     = (float) ($_POST['final_qty'] ?? 0);
                $isAjax  = !empty($_POST['ajax']);
                $sheet = $loadSheet($sheetId);
                if (!$sheet || !stk_can_review($sheet, $u)) {
                    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'err'=>'غير مسموح']); exit; }
                    throw new RuntimeException('غير مسموح: التعديل لصاحب المحضر والمراجع فقط.');
                }
                stk_set_final_qty($lineId, $qty, $uid);
                if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit; }
                header('Location: stocktake.php?sheet=' . $sheetId . '&mode=review');
                exit;
            }
            elseif ($action === 'set_final_bulk') {
                // حفظ كل التعديلات مرة واحدة
                $sheetId = (int) ($_POST['sheet_id'] ?? 0);
                $sheet = $loadSheet($sheetId);
                if (!$sheet || !stk_can_review($sheet, $u)) throw new RuntimeException('غير مسموح.');
                $finals = $_POST['final'] ?? [];
                foreach ($finals as $lid => $q) {
                    stk_set_final_qty((int)$lid, (float)$q, $uid);
                }
                // الإنهاء للأونر فقط — الموظف بيحفظ بس، وصاحب المكان يراجع وينهي
                if ($owner) {
                    stk_finalize_sheet($sheetId, $uid);
                    $flash = 'تم حفظ التعديلات وإنهاء المحضر.';
                } else {
                    $flash = 'تم حفظ التعديلات. صاحب المكان هو اللي ينهي الجرد بعد مراجعة الفروقات.';
                }
                header('Location: stocktake.php?sheet=' . $sheetId . '&mode=review&compare=1&msg=' . urlencode($flash));
                exit;
            }
            elseif ($action === 'mark_reviewed') {
                // المراجع خلّص إدخال → يظهر جدول المقارنة
                $sheetId = (int) ($_POST['sheet_id'] ?? 0);
                header('Location: stocktake.php?sheet=' . $sheetId . '&mode=review&compare=1');
                exit;
            }
            elseif ($action === 'reopen_review') {
                $sheetId = (int) ($_POST['sheet_id'] ?? 0);
                if (!$owner) throw new RuntimeException('إرجاع المراجعة للأونر فقط.');
                stk_reopen_review($sheetId);
                $flash = 'تم فتح المحضر للمراجعة تاني. تقدر تكمّل التعديل.';
                header('Location: stocktake.php?sheet=' . $sheetId . '&mode=review&msg=' . urlencode($flash));
                exit;
            }
            elseif ($action === 'finalize') {
                $sheetId = (int) ($_POST['sheet_id'] ?? 0);
                $sheet = $loadSheet($sheetId);
                if (!$sheet || !stk_can_review($sheet, $u)) throw new RuntimeException('غير مسموح: الإنهاء لصاحب المحضر والمراجع فقط.');
                stk_finalize_sheet($sheetId, $uid);
                $flash = 'تم إنهاء المحضر. صار للقراءة فقط ويمكن طباعته.';
                header('Location: stocktake.php?sheet=' . $sheetId . '&msg=' . urlencode($flash));
                exit;
            }
        } catch (Throwable $ex) {
            $flash = 'خطأ: ' . $ex->getMessage();
            $flashType = 'error';
        }
    }
}

if (isset($_GET['msg'])) { $flash = $_GET['msg']; $flashType = isset($_GET['merged'])?'merged':'success'; }

// المحضر المفتوح
$openSheet = null; $lines = [];
if (isset($_GET['sheet'])) {
    $s = $pdo->prepare(
        "SELECT sh.*, b.name AS branch_name, u.full_name AS counter_name, r.full_name AS reviewer_name
         FROM stocktake_sheets sh
         LEFT JOIN branches b ON b.id=sh.branch_id
         LEFT JOIN users u ON u.id=sh.counted_by
         LEFT JOIN users r ON r.id=sh.reviewed_by
         WHERE sh.id=?"
    );
    $s->execute([(int)$_GET['sheet']]);
    $openSheet = $s->fetch();
    if ($openSheet) {
        $l = $pdo->prepare('SELECT * FROM stocktake_lines WHERE sheet_id=? ORDER BY id DESC');
        $l->execute([(int)$openSheet['id']]);
        $lines = $l->fetchAll();
    }
}

// وضع المراجعة + الصلاحيات + المقارنة
$reviewMode = ($_GET['mode'] ?? '') === 'review';
$showCompare = !empty($_GET['compare']);   // جدول المقارنة يظهر بس بعد "تمت المراجعة"
$canReview = false; $canEdit = false; $comparison = null;
if ($openSheet) {
    $canReview = stk_can_review($openSheet, $u);
    $canEdit   = stk_can_edit($openSheet, $u);
    if ($reviewMode || $openSheet['status'] === 'reviewing' || $openSheet['status'] === 'done') {
        $comparison = stk_compare_sheet((int)$openSheet['id']);
    }
    // المحضر المنتهي: نعرض المقارنة دايماً (مفيش مرحلة إدخال)
    if ($openSheet['status'] === 'done') $showCompare = true;
}

// قائمة المحاضر في الجلسة النشطة
$sheets = [];
if ($session) {
    $sh = $pdo->prepare(
        "SELECT sh.*, b.name AS branch_name, u.full_name AS counter_name, r.full_name AS reviewer_name,
                (SELECT COUNT(*) FROM stocktake_lines WHERE sheet_id=sh.id) AS items_count
         FROM stocktake_sheets sh
         LEFT JOIN branches b ON b.id=sh.branch_id
         LEFT JOIN users u ON u.id=sh.counted_by
         LEFT JOIN users r ON r.id=sh.reviewed_by
         WHERE sh.session_id=? ORDER BY sh.sheet_no DESC"
    );
    $sh->execute([(int)$session['id']]);
    $sheets = $sh->fetchAll();
}

// تجميع المحاضر حسب اسم القسم (الجزء قبل "-" في المكان) للعرض الشجري
$sheetGroups = [];
foreach ($sheets as $shRow) {
    $note = trim($shRow['place_note'] ?? '');
    if ($note === '') {
        $section = $shRow['branch_name'] ?? 'بدون قسم';
    } else {
        // "الأقلام - الرف الأول" → القسم = "الأقلام"
        $parts = preg_split('/\s*[-–]\s*/u', $note, 2);
        $section = trim($parts[0]) !== '' ? trim($parts[0]) : $note;
    }
    if (!isset($sheetGroups[$section])) $sheetGroups[$section] = [];
    $sheetGroups[$section][] = $shRow;
}

// بحث عن صنف داخل كل المحاضر
$searchTerm = trim($_GET['q'] ?? '');
$searchResults = [];
if ($session && $searchTerm !== '') {
    $searchResults = stk_search_in_sheets((int)$session['id'], $searchTerm);
}



$page_title    = 'الجرد';
$active_module = 'stocktake';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .flash { padding:12px 16px; border-radius:10px; margin-bottom:18px; font-size:14px; }
    .flash.success { background:var(--pos-soft); color:var(--pos); }
    .flash.error   { background:var(--neg-soft); color:var(--neg); }
    .flash.merged  { background:var(--warn-soft); color:var(--warn); }
    .card { background:var(--surface); border-radius:14px; padding:20px; box-shadow:0 2px 10px rgba(124,111,247,0.06); margin-bottom:18px; }
    .section-title { font-size:16px; color:var(--ink-900); font-weight:600; margin-bottom:14px; }
    .session-bar { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; }
    .session-status { font-size:14px; }
    .dot { display:inline-block; width:9px; height:9px; border-radius:50%; margin-left:6px; }
    .dot.on { background:var(--pos-fill); } .dot.off { background:var(--ink-300); }
    .hdr-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:14px; margin-bottom:8px; }
    .hdr-item .l { font-size:12px; color:var(--ink-500); } .hdr-item .v { font-size:15px; color:var(--ink-900); font-weight:600; }
    table { width:100%; border-collapse:collapse; }
    th, td { padding:10px 12px; text-align:right; font-size:13px; border-bottom:1px solid var(--line); white-space:nowrap; }
    th { color:var(--ink-500); font-weight:600; }
    td { color:var(--ink-800); }
    .diff-neg { color:var(--neg); font-weight:600; } .diff-pos { color:var(--pos); font-weight:600; } .diff-zero { color:var(--ink-500); }
    .empty { text-align:center; color:var(--ink-400); padding:24px; font-size:14px; }
    .form-inline { display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; margin-bottom:14px; }
    .form-inline .fld { display:flex; flex-direction:column; gap:4px; }
    .form-inline label { font-size:12px; color:var(--ink-500); }
    .form-inline input, .form-inline select { padding:9px 11px; border:1.5px solid var(--line); border-radius:9px; font-family:inherit; font-size:14px; background:var(--line-soft); }
    .modal-bg { display:none; position:fixed; inset:0; background:rgba(30,27,75,0.45); z-index:200; align-items:flex-start; justify-content:center; padding:30px 16px; overflow-y:auto; }
    .modal-bg.open { display:flex; }
    .modal { background:var(--surface); border-radius:16px; width:100%; max-width:440px; padding:26px; }
    .modal h3 { color:var(--ink-900); margin-bottom:16px; }
    .form-row { margin-bottom:14px; }
    .form-row label { display:block; font-size:13px; color:var(--ink-700); margin-bottom:6px; }
    .form-row input, .form-row select { width:100%; padding:10px 12px; border:1.5px solid var(--line); border-radius:9px; font-family:inherit; font-size:14px; background:var(--line-soft); }
    .modal-actions { display:flex; gap:10px; margin-top:20px; }
    .sheet-link { color:var(--accent-text); font-weight:600; text-decoration:none; }
    /* شجرة الأقسام */
    .status-chip { padding:6px 14px; border:1.5px solid var(--line); background:var(--surface); border-radius:20px; cursor:pointer; font-family:inherit; font-size:13px; color:var(--ink-500); }
    .status-chip:hover { border-color:var(--accent); }
    .status-chip.active { background:var(--accent); color:#fff; border-color:var(--accent-text); font-weight:600; }
    .tree-section { border:1.5px solid var(--line); border-radius:12px; margin-bottom:10px; overflow:hidden; }
    .tree-head { display:flex; align-items:center; gap:10px; padding:14px 16px; cursor:pointer; background:var(--line-soft); user-select:none; }
    .tree-head:hover { background:var(--accent-soft); }
    .tree-caret { color:var(--accent-text); font-size:13px; transition:transform .15s; display:inline-block; }
    .tree-section.open .tree-caret { transform:rotate(90deg); }
    .tree-name { font-weight:600; color:var(--ink-900); font-size:15px; flex:1; }
    .tree-count { font-size:12px; color:var(--ink-500); background:var(--line-soft); padding:3px 10px; border-radius:20px; }
    .tree-body { display:none; padding:4px 8px 8px; overflow-x:auto; }
    .tree-section.open .tree-body { display:block; }
    .sheet-link:hover { text-decoration:underline; }
    .sheet-row:hover { background:var(--accent-soft); }
</style>
<style>
    /* رسالة عائمة تحت — متحركش الصفحة فوق (مهم للجرد على الموبايل) */
    .stk-toast {
        position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%);
        z-index: var(--z-toast); padding: 12px 22px; border-radius: 10px; font-size: 14px; font-weight: 500;
        box-shadow: 0 6px 24px rgba(0,0,0,0.18); max-width: 90%; text-align: center;
        animation: toastIn 0.25s ease; }
    .stk-toast.success { background:var(--pos-fill); color: #fff; }
    .stk-toast.error { background:var(--neg-fill); color: #fff; }
    @keyframes toastIn { from { opacity: 0; transform: translate(-50%, 20px); } to { opacity: 1; transform: translate(-50%, 0); } }
</style>

<?php if ($flash): ?>
<div class="stk-toast <?= e($flashType) ?>" id="stkToast"><?= e($flash) ?></div>
<script>
    setTimeout(function(){
        var t = document.getElementById('stkToast');
        if (t) { t.style.transition = 'opacity 0.4s'; t.style.opacity = '0'; setTimeout(function(){ t.remove(); }, 400); }
    }, 2500);
</script>
<?php endif; ?>

<!-- شريط الجلسة -->
<div class="card">
    <div class="session-bar">
        <div class="session-status">
            حالة الجرد:
            <?php if ($session): ?>
                <strong style="color:var(--pos);">نشط<span class="dot on"></span></strong>
                <?php if ($session['has_balance']): ?><span style="color:var(--ink-500);font-size:13px;">— رصيد مرفوع</span><?php endif; ?>
            <?php else: ?>
                <strong style="color:var(--ink-400);">متوقف<span class="dot off"></span></strong>
            <?php endif; ?>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <?php if ($owner && !$session): ?>
                <button class="btn btn-green" onclick="document.getElementById('startModal').classList.add('open')">
                    <i class="ti ti-player-play"></i> بدء الجرد
                </button>
                <form method="post" style="display:inline;" data-confirm="إعادة فتح آخر جرد مقفول؟ كل المحاضر هترجع زي ما هي.">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="reopen_session">
                    <button class="btn" style="background:var(--warn-fill);"><i class="ti ti-rotate"></i> إعادة فتح آخر جرد</button>
                </form>
            <?php endif; ?>
            <?php if ($session): ?>
                <button class="btn" onclick="document.getElementById('sheetModal').classList.add('open')">
                    <i class="ti ti-plus"></i> إضافة محضر جرد
                </button>
                <?php if ($owner): ?>
                    <?php if (!$session['has_balance']): ?>
                        <button class="btn btn-ghost" onclick="document.getElementById('balanceModal').classList.add('open')">
                            <i class="ti ti-upload"></i> رفع رصيد
                        </button>
                    <?php endif; ?>
                    <form method="post" style="display:inline;" data-confirm="تأكيد إنهاء الجرد؟ هتتقفل كل الأزرار." data-confirm-danger>
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="end_session">
                        <button class="btn btn-red"><i class="ti ti-player-stop"></i> إنهاء الجرد</button>
                    </form>
                    <a href="stocktake_places.php" class="btn btn-ghost" style="text-decoration:none;"><i class="ti ti-layout-grid"></i> أقسام وأرفف</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($openSheet): ?>
    <!-- محضر مفتوح -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
        <a href="stocktake.php" class="btn btn-ghost btn-sm"><i class="ti ti-arrow-right"></i> رجوع للمحاضر</a>
        <?php if ($owner && $openSheet['status'] !== 'done'): ?>
            <form method="post" data-confirm="مسح المحضر #<?= (int)$openSheet['sheet_no'] ?> بالكامل بكل أصنافه؟ مش هينفع ترجعه." data-confirm-danger style="display:inline;">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete_sheet">
                <input type="hidden" name="sheet_id" value="<?= (int)$openSheet['id'] ?>">
                <button type="submit" class="btn btn-sm" style="background:var(--neg-fill);"><i class="ti ti-trash"></i> مسح المحضر</button>
            </form>
        <?php endif; ?>
    </div>
    <div class="card">
        <div class="hdr-grid">
            <div class="hdr-item"><div class="l">رقم المحضر</div><div class="v">#<?= (int)$openSheet['sheet_no'] ?></div></div>
            <div class="hdr-item"><div class="l">المكان</div><div class="v"><?= e($openSheet['place_note'] ?: ($openSheet['branch_name'] ?? '—')) ?></div></div>
            <div class="hdr-item"><div class="l">القائم بالجرد</div><div class="v"><?= e($openSheet['counter_name'] ?? '—') ?></div></div>
            <div class="hdr-item"><div class="l">المراجع</div><div class="v"><?= e($openSheet['reviewer_name'] ?? '—') ?></div></div>
            <div class="hdr-item"><div class="l">عدد الأصناف</div><div class="v"><?= count($lines) ?></div></div>
            <div class="hdr-item"><div class="l">التاريخ</div><div class="v"><?= e(date('Y-m-d', strtotime($openSheet['created_at']))) ?></div></div>
        </div>
    </div>

    <?php if ($owner && $openSheet['status'] !== 'done'): ?>
    <div class="card" style="background:var(--line-soft);">
        <div class="section-title" style="font-size:14px;"><i class="ti ti-user-cog"></i> تغيير المسؤولين (للأونر)</div>
        <p style="color:var(--ink-500);font-size:12px;margin-bottom:10px;">لو حد مشي في نص الشغل، تقدر تحوّل المحضر ليوزر تاني يكمّل.</p>
        <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <form method="post" style="display:flex;gap:6px;align-items:end;">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="reassign">
                <input type="hidden" name="field" value="counted_by">
                <input type="hidden" name="sheet_id" value="<?= (int)$openSheet['id'] ?>">
                <div class="fld"><label for="nameSearch" style="font-size:12px;color:var(--ink-500);">القائم بالجرد</label>
                    <select name="user_id" style="padding:7px 10px;border:1.5px solid var(--line);border-radius:8px;font-family:inherit;">
                        <?php foreach ($allUsers as $au): ?>
                            <option value="<?= (int)$au['id'] ?>" <?= (int)$au['id']===(int)$openSheet['counted_by']?'selected':'' ?>><?= e($au['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn btn-sm">تغيير</button>
            </form>
            <form method="post" style="display:flex;gap:6px;align-items:end;">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="reassign">
                <input type="hidden" name="field" value="reviewed_by">
                <input type="hidden" name="sheet_id" value="<?= (int)$openSheet['id'] ?>">
                <div class="fld"><label style="font-size:12px;color:var(--ink-500);">المراجع</label>
                    <select name="user_id" style="padding:7px 10px;border:1.5px solid var(--line);border-radius:8px;font-family:inherit;">
                        <option value="">— بدون —</option>
                        <?php foreach ($allUsers as $au): ?>
                            <option value="<?= (int)$au['id'] ?>" <?= (int)$au['id']===(int)$openSheet['reviewed_by']?'selected':'' ?>><?= e($au['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn btn-sm">تغيير</button>
            </form>
            <form method="post" style="display:flex;gap:6px;align-items:end;">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="change_place">
                <input type="hidden" name="sheet_id" value="<?= (int)$openSheet['id'] ?>">
                <div class="fld"><label style="font-size:12px;color:var(--ink-500);">المكان</label>
                    <?php if (!empty($stkPlaces)): ?>
                        <select name="place_note" style="padding:7px 10px;border:1.5px solid var(--line);border-radius:8px;font-family:inherit;">
                            <?php foreach ($stkPlaces as $plName): ?>
                                <option value="<?= e($plName) ?>" <?= $plName===$openSheet['place_note']?'selected':'' ?>><?= e($plName) ?></option>
                            <?php endforeach; ?>
                            <option value="__custom__">— مكان آخر (اكتبه) —</option>
                        </select>
                        <input type="text" name="place_custom" placeholder="لو اخترت مكان آخر" aria-label="مكان آخر"
                               style="padding:7px 10px;border:1.5px solid var(--line);border-radius:8px;font-family:inherit;margin-top:4px;">
                    <?php else: ?>
                        <input type="text" name="place_note" value="<?= e($openSheet['place_note'] ?? '') ?>" required
                               aria-label="ملاحظة المكان"
                               style="padding:7px 10px;border:1.5px solid var(--line);border-radius:8px;font-family:inherit;">
                    <?php endif; ?>
                </div>
                <button class="btn btn-sm">تغيير المكان</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($session && $openSheet['status']==='open' && $canEdit): ?>
    <div class="card">
        <div class="section-title">إضافة صنف</div>
        <!-- بحث بالاسم -->
        <div style="position:relative;margin-bottom:14px;">
            <label style="font-size:12px;color:var(--ink-500);">بحث بالاسم (لو مش عارف الكود)</label>
            <input type="text" id="nameSearch" autocomplete="off" placeholder="اكتب جزء من اسم الصنف..." aria-label="بحث باسم الصنف"
                   oninput="searchByName(this.value)"
                   style="width:100%;padding:9px 11px;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;font-size:14px;background:var(--line-soft);">
            <div id="searchResults" style="display:none;position:absolute;z-index:50;background:var(--surface);border:1.5px solid var(--line);
                 border-radius:9px;width:100%;max-height:220px;overflow-y:auto;box-shadow:0 4px 16px rgba(0,0,0,0.1);margin-top:4px;"></div>
        </div>
        <form method="post" class="form-inline" id="addLineForm" onsubmit="return validateScan(this)">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_line">
            <input type="hidden" name="sheet_id" value="<?= (int)$openSheet['id'] ?>">
            <div class="fld"><label for="codeName">كود الصنف</label>
                <div style="display:flex;gap:6px;">
                    <input type="text" name="code" id="codeInput" maxlength="14" required autofocus autocomplete="off"
                           style="width:160px;" oninput="lookupName(this.value)" aria-label="كود الصنف">
                    <button type="button" onclick="openBarcodeScanner('codeInput')" title="مسح بالكاميرا"
                            style="background:var(--accent);color:#fff;border:none;border-radius:9px;padding:0 14px;cursor:pointer;font-size:18px;"><i class="ti ti-camera"></i></button>
                </div>
            </div>
            <div class="fld" style="flex:2;"><label>اسم الصنف</label>
                <input type="text" id="codeName" readonly placeholder="هيظهر بعد الكود..." aria-label="اسم الصنف"
                       style="background:var(--accent-soft);color:var(--ink-900);border:1.5px solid var(--line);cursor:default;">
            </div>
            <div class="fld"><label for="qtyInput">كمية الجرد</label><input type="number" step="0.001" min="0" name="qty" id="qtyInput" required></div>
            <button type="submit" class="btn">إضافة</button>
        </form>
        <script>
            // جلب اسم الصنف لما يتضرب الكود (للعرض فقط) — معرّفة على window لضمان توفرها
            var nameTimer = null;
            window.lookupName = function(code) {
                code = (code || '').trim();
                var box = document.getElementById('codeName');
                if (!box) return;
                if (code.length < 1) { box.value = ''; return; }
                clearTimeout(nameTimer);
                nameTimer = setTimeout(function() {
                    box.value = '...';
                    fetch('stocktake.php?lookup=' + encodeURIComponent(code))
                        .then(function(r){ return r.json(); })
                        .then(function(d){
                            if (d && d.name) box.value = d.name;
                            else if (d && d.error) box.value = '⚠️ خطأ: ' + d.error;
                            else box.value = '⚠️ كود غير موجود';
                        })
                        .catch(function(){ box.value = '⚠️ تعذّر الاتصال'; });
                }, 150);
            };
        </script>
        <script>
            // حماية من لخبطة السكانر: تكرار نفس الكود بسرعة + كمية كبيرة
            let lastScanCode = '';
            let lastScanTime = 0;
            function validateScan(form) {
                const code = form.code.value.trim();
                const qty = parseFloat(form.qty.value) || 0;
                const now = Date.now();

                // (1) كمية كبيرة غير منطقية
                if (qty > 1000) {
                    if (!confirm('الكمية كبيرة جداً (' + qty + '). متأكد إنها صح؟ ده ممكن يكون غلط من السكانر.')) {
                        return false;
                    }
                }
                // (2) نفس الكود اتضرب خلال ثانيتين (لخبطة سكانر)
                if (code === lastScanCode && (now - lastScanTime) < 2000) {
                    if (!confirm('نفس الكود (' + code + ') اتضرب من ثواني. متأكد إنك عايز تضيفه تاني؟')) {
                        return false;
                    }
                }
                lastScanCode = code;
                lastScanTime = now;
                // إضافة بـ AJAX (من غير reload — أسرع بكتير في الجرد)
                submitLineAjax(form);
                return false; // نمنع الـ submit العادي
            }

            function stkEsc(s) {
                return String(s).replace(/[&<>"']/g, function(m){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]; });
            }

            var addingLine = false;
            function submitLineAjax(form) {
                if (addingLine) return;
                addingLine = true;
                var codeBox = document.getElementById('codeInput');
                var qtyBox  = document.getElementById('qtyInput');
                var fd = new FormData(form);
                fd.append('ajax', '1');
                fetch('stocktake.php', { method:'POST', body: fd })
                    .then(function(r){ return r.json(); })
                    .then(function(d){
                        addingLine = false;
                        if (d.notfound !== undefined) { showStkPopup('كود غير موجود', 'الكود <b>'+stkEsc(d.notfound)+'</b> مش موجود في بيانات الأصناف.'); return; }
                        if (d.badqty !== undefined) { showStkPopup('كمية كبيرة', 'الكمية كبيرة بشكل غير منطقي. اتأكد إنك مدخّلتش الكود في خانة الكمية.'); return; }
                        if (!d.ok) { showStkToast(d.err || 'حصل خطأ', 'error'); return; }
                        // تحديث الجدول
                        renderJardLines();
                        showStkToast((d.merged?'تم تجميعه: ':'تمت إضافة: ') + d.name, 'success');
                        // تفضية وإرجاع الفوكس للكود (نفس الديناميكية)
                        if (codeBox) codeBox.value = '';
                        if (qtyBox) qtyBox.value = '';
                        var nameBox = document.getElementById('codeName');
                        if (nameBox) nameBox.value = '';
                        if (codeBox) codeBox.focus();
                    })
                    .catch(function(){
                        addingLine = false;
                        // فشل AJAX → نرجع للطريقة العادية (submit)
                        form.removeAttribute('onsubmit');
                        form.submit();
                    });
            }
        </script>
    </div>
    <?php endif; ?>

    <script>
        let searchTimer = null;
        function searchByName(term) {
            clearTimeout(searchTimer);
            const box = document.getElementById('searchResults');
            if (term.trim().length < 2) { box.style.display='none'; return; }
            searchTimer = setTimeout(() => {
                fetch('stocktake.php?search=' + encodeURIComponent(term))
                    .then(r => r.json())
                    .then(items => {
                        if (!items.length) { box.innerHTML='<div style="padding:10px;color:var(--ink-400);">مفيش نتائج</div>'; box.style.display='block'; return; }
                        box.innerHTML = items.map(it => {
                            var prices = '';
                            if (it.sale != null) prices += '<span style="color:var(--pos);">بيع: '+it.sale+'</span> ';
                            if (it.cost != null) prices += '<span style="color:var(--warn);">تكلفة: '+it.cost+'</span>';
                            return '<div onclick="pickItem(\''+escapeHtml(it.code)+'\')" style="padding:9px 12px;cursor:pointer;border-bottom:1px solid var(--line-soft);font-size:14px;" '+
                            'onmouseover="this.style.background=\'var(--accent-soft)\'" onmouseout="this.style.background=\'\'">'+
                            '<div><strong>'+escapeHtml(it.code)+'</strong> — '+escapeHtml(it.name)+'</div>'+
                            (prices ? '<div style="font-size:12px;margin-top:3px;">'+prices+'</div>' : '')+
                            '</div>';
                        }).join('');
                        box.style.display='block';
                    }).catch(()=>{ box.style.display='none'; });
            }, 300);
        }
        function pickItem(code) {
            document.getElementById('codeInput').value = code;
            document.getElementById('searchResults').style.display='none';
            document.getElementById('nameSearch').value='';
            document.querySelector('#addLineForm input[name=qty]').focus();
        }
        document.addEventListener('click', e => {
            if (!e.target.closest('#nameSearch') && !e.target.closest('#searchResults')) {
                const box = document.getElementById('searchResults');
                if (box) box.style.display='none';
            }
        });

        // تحديث جدول الجرد بعد إضافة AJAX — يعيد جلب الجدول من الصفحة (نفس منطق PHP بالظبط)
        var curSheetId = <?= isset($openSheet['id']) ? (int)$openSheet['id'] : 0 ?>;
        function renderJardLines() {
            fetch('stocktake.php?sheet=' + curSheetId)
                .then(function(r){ return r.text(); })
                .then(function(html){
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var newTable = doc.getElementById('jardTable');
                    var oldTable = document.getElementById('jardTable');
                    if (newTable && oldTable) {
                        oldTable.parentNode.replaceChild(newTable, oldTable);
                    } else {
                        // أول صنف (الجدول ماكانش ظاهر) — نعمل reload خفيف
                        location.reload();
                    }
                })
                .catch(function(){});
        }

        // رسالة عائمة (toast)
        function showStkToast(msg, type) {
            var t = document.createElement('div');
            t.className = 'stk-toast ' + (type || 'success');
            t.textContent = msg;
            document.body.appendChild(t);
            setTimeout(function(){ t.style.transition='opacity 0.4s'; t.style.opacity='0'; setTimeout(function(){ t.remove(); }, 400); }, 2200);
        }

        // بوب أب (للكود الغلط / الكمية الكبيرة)
        function showStkPopup(title, html) {
            var ov = document.createElement('div');
            ov.style.cssText = 'position:fixed;inset:0;background:rgba(30,27,75,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;padding:20px;';
            ov.innerHTML = '<div style="background:var(--surface);border-radius:14px;padding:24px;max-width:380px;text-align:center;box-shadow:0 12px 40px rgba(0,0,0,0.25);">'
                + '<h3 style="color:var(--neg);font-size:17px;margin-bottom:10px;"><i class="ti ti-alert-triangle"></i> ' + title + '</h3>'
                + '<p style="color:var(--ink-600);font-size:14px;line-height:1.8;">' + html + '</p>'
                + '<button style="margin-top:16px;padding:9px 28px;background:var(--accent);color:#fff;border:none;border-radius:8px;cursor:pointer;font-family:inherit;font-size:14px;">تمام</button></div>';
            ov.querySelector('button').onclick = function(){ ov.remove(); var ci=document.getElementById('codeInput'); if(ci) ci.focus(); };
            document.body.appendChild(ov);
        }
    </script>

    <!-- شريط أزرار المراجعة / التقارير -->
    <?php
        // بدء المراجعة: أي يوزر تاني (مش صاحب المحضر) يقدر يبدأها ويبقى المراجع. الأونر برضو.
        $canStartReview = $openSheet['status']==='open' && count($lines)>0
            && ((int)$u['id'] !== (int)$openSheet['counted_by'] || $owner);
    ?>
    <?php if ($canReview || $canStartReview): ?>
    <div class="card">
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <?php if ($canStartReview): ?>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="start_review">
                    <input type="hidden" name="sheet_id" value="<?= (int)$openSheet['id'] ?>">
                    <button class="btn btn-green"><i class="ti ti-checklist"></i> بدء المراجعة</button>
                </form>
                <span style="color:var(--ink-500);font-size:13px;">(اللي يبدأ المراجعة يتسجّل كمراجع للمحضر ده)</span>
            <?php elseif ($canReview && $openSheet['status']==='reviewing'): ?>
                <?php if (!$reviewMode): ?>
                    <a href="stocktake.php?sheet=<?= (int)$openSheet['id'] ?>&mode=review" class="btn"><i class="ti ti-eye"></i> فتح المراجعة</a>
                <?php elseif (!$showCompare): ?>
                    <!-- مرحلة الإدخال: المراجع بيدخل أصنافه، وبعدين "تمت المراجعة" -->
                    <form method="post" style="display:inline;" data-confirm="خلصت إدخال أصنافك؟ هنعرض الفروقات للمراجعة.">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="mark_reviewed">
                        <input type="hidden" name="sheet_id" value="<?= (int)$openSheet['id'] ?>">
                        <button class="btn btn-green"><i class="ti ti-checklist"></i> تمت المراجعة</button>
                    </form>
                    <span style="color:var(--ink-500);font-size:13px;">(اضغط لما تخلص إدخال الأصناف، عشان تشوف الفروقات)</span>
                <?php else: ?>
                    <!-- مرحلة المقارنة: زر تم الانتهاء (للأونر فقط) بيحفظ كل التعديلات + ينهي -->
                    <?php if ($owner): ?>
                        <button type="submit" form="finalForm" class="btn btn-green"><i class="ti ti-check"></i> تم الانتهاء</button>
                        <span style="color:var(--ink-500);font-size:13px;">(بيحفظ كل التعديلات وينهي المحضر)</span>
                    <?php else: ?>
                        <button type="submit" form="finalForm" class="btn btn-ghost"><i class="ti ti-device-floppy"></i> حفظ التعديلات</button>
                        <span style="color:var(--warn);font-size:13px;">(صاحب المكان بس هو اللي ينهي الجرد بعد مراجعة الفروقات معاك)</span>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($openSheet['status']==='done'): ?>
                <span style="color:var(--pos);font-weight:600;"><i class="ti ti-lock"></i> المحضر منتهي (للقراءة فقط)</span>
                <a href="stocktake_export.php?sheet=<?= (int)$openSheet['id'] ?>" class="btn btn-green"><i class="ti ti-download"></i> طباعة التقرير</a>
                <?php if ($owner): ?>
                <form method="post" style="display:inline;" data-confirm="فتح المحضر للمراجعة تاني؟ هتقدر تعدّل وتنهي من جديد.">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="reopen_review">
                    <input type="hidden" name="sheet_id" value="<?= (int)$openSheet['id'] ?>">
                    <button class="btn" style="background:var(--warn-fill);"><i class="ti ti-rotate"></i> إرجاع للمراجعة</button>
                </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($comparison !== null && !$showCompare): ?>
    <!-- مرحلة الإدخال: المراجع بيدخل أصنافه (من غير مقارنة) -->
    <?php if ($canReview && $openSheet['status']==='reviewing'): ?>
    <div class="card">
        <div class="section-title">إدخال جرد المراجع</div>
        <p style="color:var(--ink-500);font-size:12px;margin-bottom:10px;">
            اعمل جردك بنفسك: اضرب كل صنف بكوده وكميته. لما تخلص، اضغط <strong>"تمت المراجعة"</strong> فوق عشان تشوف الفروقات.
        </p>
        <form method="post" class="form-inline" id="revAddForm" onsubmit="return submitReviewAjax(this)">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="set_review">
            <input type="hidden" name="sheet_id" value="<?= (int)$openSheet['id'] ?>">
            <div class="fld"><label for="revCodeName">كود الصنف</label>
                <div style="display:flex;gap:6px;">
                    <input type="text" name="code" id="revCode" maxlength="14" required autocomplete="off"
                           placeholder="اضرب أو اكتب الكود" style="width:160px;" oninput="lookupRevName(this.value)" aria-label="كود الصنف">
                    <button type="button" onclick="openBarcodeScanner('revCode')" title="مسح بالكاميرا"
                            style="background:var(--accent);color:#fff;border:none;border-radius:9px;padding:0 14px;cursor:pointer;font-size:18px;"><i class="ti ti-camera"></i></button>
                </div>
            </div>
            <div class="fld" style="flex:2;"><label>اسم الصنف</label>
                <input type="text" id="revCodeName" readonly placeholder="هيظهر بعد الكود..."
                       style="background:var(--accent-soft);color:var(--ink-900);border:1.5px solid var(--line);cursor:default;">
            </div>
            <div class="fld"><label for="revQty">كمية المراجع (بوحدة الكود)</label><input type="number" step="0.001" min="0" name="review_qty" id="revQty" required></div>
            <button class="btn">حفظ</button>
        </form>
        <script>
            var revNameTimer = null;
            window.lookupRevName = function(code) {
                code = (code || '').trim();
                var box = document.getElementById('revCodeName');
                if (!box) return;
                if (code.length < 1) { box.value = ''; return; }
                clearTimeout(revNameTimer);
                revNameTimer = setTimeout(function() {
                    box.value = '...';
                    fetch('stocktake.php?lookup=' + encodeURIComponent(code))
                        .then(function(r){ return r.json(); })
                        .then(function(d){
                            if (d && d.name) box.value = d.name;
                            else if (d && d.error) box.value = '⚠️ خطأ: ' + d.error;
                            else box.value = '⚠️ كود غير موجود';
                        })
                        .catch(function(){ box.value = '⚠️ تعذّر الاتصال'; });
                }, 150);
            };
            function stkEsc(s) {
                return String(s).replace(/[&<>"']/g, function(m){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]; });
            }

            // إضافة صنف المراجع بـ AJAX — الصفحة متطلعش فوق (زي الجرد)
            var revAdding = false;
            function submitReviewAjax(form) {
                if (revAdding) return false;
                revAdding = true;
                var fd = new FormData(form); fd.append('ajax','1');
                fetch('stocktake.php', { method:'POST', body: fd })
                    .then(function(r){ return r.json(); })
                    .then(function(d){
                        revAdding = false;
                        if (d.notfound !== undefined) { showStkPopup('كود غير موجود','الكود <b>'+stkEsc(d.notfound)+'</b> مش موجود.'); return; }
                        if (d.badqty !== undefined) { showStkPopup('كمية كبيرة','الكمية كبيرة بشكل غير منطقي.'); return; }
                        if (!d.ok) { showStkToast(d.err||'خطأ','error'); return; }
                        // حدّث جدول المراجع (يعيد جلب الجدول من الصفحة)
                        refreshRevTable();
                        showStkToast((d.new_item?'صنف زيادة: ':'تم التسجيل: ')+d.name, 'success');
                        document.getElementById('revCode').value = '';
                        document.getElementById('revQty').value = '';
                        document.getElementById('revCodeName').value = '';
                        document.getElementById('revCode').focus();
                    })
                    .catch(function(){ revAdding=false; form.removeAttribute('onsubmit'); form.submit(); });
                return false;
            }
            function refreshRevTable() {
                fetch('stocktake.php?sheet=<?= (int)$openSheet['id'] ?>&mode=review')
                    .then(function(r){ return r.text(); })
                    .then(function(html){
                        var doc = new DOMParser().parseFromString(html,'text/html');
                        var nw = doc.getElementById('revInTable'), old = document.getElementById('revInTable');
                        if (nw && old) old.parentNode.replaceChild(nw, old);
                    }).catch(function(){});
            }
        </script>
    </div>
    <?php endif; ?>

    <!-- جدول أصناف المراجع اللي دخّلها (قابل للتعديل والحذف) -->
    <div class="card">
        <div class="section-title">الأصناف اللي دخّلتها (<?= count(array_filter($comparison['lines'], fn($c)=>!empty($c['_reviewed']))) ?>)</div>
        <p style="color:var(--ink-500);font-size:12px;margin-bottom:10px;">لو كتبت كمية غلط، عدّلها في الخانة واضغط Enter (تتحفظ فوراً)، أو احذف الصنف.</p>
        <input type="text" id="revInSearch" placeholder="🔍 ابحث..." onkeyup="filterTable('revInSearch','revInTable')"
               style="width:100%;padding:10px 14px;margin-bottom:12px;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;font-size:14px;background:var(--line-soft);" aria-label="🔍 ابحث...">
        <table id="revInTable">
            <thead><tr><th>الكود</th><th>اسم الصنف</th><th>كمية المراجع</th><th>حذف</th></tr></thead>
            <tbody>
            <?php foreach ($comparison['lines'] as $cl): if (empty($cl['_reviewed'])) continue; ?>
                <tr>
                    <td><?= e($cl['entered_code']) ?></td>
                    <td style="white-space:normal;"><?= e($cl['item_name']) ?></td>
                    <td>
                        <?php if ($canReview && $openSheet['status']==='reviewing'): ?>
                            <input type="number" aria-label="الكمية بالمراجعة" step="0.001" min="0" value="<?= rtrim(rtrim(number_format((float)$cl['_review'],3),'0'),'.') ?>"
                                   data-line="<?= (int)$cl['id'] ?>"
                                   enterkeyhint="done" onkeydown="if(isEnterKey(event)){event.preventDefault();saveReviewQty(this);}"
                                   style="width:90px;padding:5px 7px;border:1.5px solid var(--line);border-radius:7px;font-family:inherit;font-weight:700;color:var(--accent-text);">
                        <?php else: ?>
                            <span style="font-weight:700;color:var(--accent-text);"><?= rtrim(rtrim(number_format((float)$cl['_review'],3),'0'),'.') ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($canReview && $openSheet['status']==='reviewing'): ?>
                            <button type="button" onclick="deleteRevLine(<?= (int)$cl['id'] ?>, <?= (int)$openSheet['id'] ?>)"
                                    style="background:var(--neg-soft);color:var(--neg);border:none;border-radius:7px;padding:5px 10px;cursor:pointer;font-family:inherit;font-size:12px;">حذف</button>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($canReview && $openSheet['status']==='reviewing'): ?>
        <script>
            // تعديل كمية المراجع بـ Enter (يحفظ فوراً)
            function saveReviewQty(input) {
                var fd = new FormData();
                fd.append('csrf','<?= e(csrf_token()) ?>');
                fd.append('action','set_review_qty');
                fd.append('ajax','1');
                fd.append('sheet_id','<?= (int)$openSheet['id'] ?>');
                fd.append('line_id', input.dataset.line);
                fd.append('review_qty', input.value);
                input.style.borderColor = 'var(--warn)';
                fetch('stocktake.php', { method:'POST', body: fd })
                    .then(function(r){ return r.json(); })
                    .then(function(d){
                        input.style.borderColor = d.ok ? 'var(--pos)' : 'var(--neg)';
                        showStkToast(d.ok ? 'تم تعديل الكمية' : (d.err||'خطأ'), d.ok?'success':'error');
                    })
                    .catch(function(){ input.style.borderColor = 'var(--neg)'; });
            }
            function deleteRevLine(lineId, sheetId) {
                if (!confirm('حذف الصنف ده من مراجعتك؟')) return;
                var f = document.createElement('form'); f.method='post';
                f.innerHTML = '<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">'
                    + '<input type="hidden" name="action" value="delete_line">'
                    + '<input type="hidden" name="line_id" value="'+lineId+'">'
                    + '<input type="hidden" name="sheet_id" value="'+sheetId+'">'
                    + '<input type="hidden" name="from_review" value="1">';
                document.body.appendChild(f); formSubmit(f);
            }
        </script>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($comparison !== null && $showCompare): ?>
    <!-- مرحلة المقارنة: الأصناف المفرّقة بس -->
    <?php
        $diffLines = array_filter($comparison['lines'], fn($c) => ($c['_state'] ?? '') !== 'equal');
        $isReviewing = $canReview && $openSheet['status']==='reviewing';
    ?>
    <div class="card">
        <div class="section-title">
            مقارنة المراجعة — الأصناف المفرّقة بس (<?= count($diffLines) ?>)
            <?php if (empty($diffLines)): ?>
                <span style="color:var(--pos);font-size:13px;">— كل الأصناف مطابقة ✓</span>
            <?php endif; ?>
        </div>
        <p style="color:var(--ink-500);font-size:12px;margin-bottom:12px;">
            <span style="color:var(--neg);">أحمر</span>: المراجع لقى أقل ·
            <span style="color:var(--warn);">أصفر</span>: لقى أكثر.
            <?= $isReviewing ? 'عدّل الكمية النهائية واضغط Enter (يحفظ فوراً)، أو عدّل الكل واضغط "تم الانتهاء".' : '' ?>
        </p>
        <?php if (empty($diffLines)): ?>
            <div class="empty" style="color:var(--pos);">مفيش أي فروقات — كل أصناف المراجع مطابقة للجرد الأصلي. <i class="ti ti-confetti"></i></div>
        <?php endif; ?>
        <input type="text" id="reviewSearch" placeholder="🔍 ابحث في الأصناف المفرّقة..."
               onkeyup="filterTable('reviewSearch','reviewTable')"
               style="width:100%;padding:10px 14px;margin-bottom:12px;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;font-size:14px;background:var(--line-soft);<?= empty($diffLines)?'display:none;':'' ?>" aria-label="ابحث في الأصناف المفرّقة">
        <!-- form الحفظ الجماعي (زر تم الانتهاء بيستخدمه) -->
        <form method="post" id="finalForm" data-confirm="حفظ كل التعديلات وإنهاء المحضر؟">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="set_final_bulk">
            <input type="hidden" name="sheet_id" value="<?= (int)$openSheet['id'] ?>">
            <table id="reviewTable">
                <thead><tr>
                    <th>الكود</th><th>اسم الصنف</th><th>الجرد الأصلي</th><th>جرد المراجع</th>
                    <th>النهائية</th><?php if ($isReviewing): ?><th>حذف</th><?php endif; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($diffLines as $cl): ?>
                    <?php
                        $bg = ['less'=>'background:var(--line-soft);','more'=>'background:var(--line-soft);'][$cl['_state']] ?? '';
                        $final = $cl['final_qty']!==null ? (float)$cl['final_qty'] : $cl['_review'];
                    ?>
                    <tr style="<?= $bg ?>">
                        <td><?= e($cl['entered_code']) ?></td>
                        <td style="white-space:normal;"><?= e($cl['item_name']) ?></td>
                        <td><?= rtrim(rtrim(number_format((float)$cl['base_qty'],3),'0'),'.') ?></td>
                        <td><?= rtrim(rtrim(number_format((float)$cl['_review'],3),'0'),'.') ?></td>
                        <td>
                            <?php if ($isReviewing): ?>
                                <input type="number" step="0.001" name="final[<?= (int)$cl['id'] ?>]" value="<?= $final ?>" aria-label="الكمية النهائية"
                                       data-line="<?= (int)$cl['id'] ?>" enterkeyhint="done" onkeydown="if(isEnterKey(event)){event.preventDefault();saveFinalOne(this);}"
                                       style="width:100px;padding:5px 7px;border:1.5px solid var(--line);border-radius:7px;font-family:inherit;font-weight:700;">
                            <?php else: ?>
                                <span style="font-weight:700;"><?= rtrim(rtrim(number_format($final,3),'0'),'.') ?></span>
                            <?php endif; ?>
                        </td>
                        <?php if ($isReviewing): ?>
                        <td>
                            <button type="button" onclick="deleteLineReview(<?= (int)$cl['id'] ?>, <?= (int)$openSheet['id'] ?>)"
                                    style="background:var(--neg-soft);color:var(--neg);border:none;border-radius:7px;padding:6px 9px;cursor:pointer;font-family:inherit;font-size:13px;">حذف</button>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </form>
        <?php if ($isReviewing): ?>
        <script>
            // حفظ صنف واحد بـ Enter (AJAX)
            function saveFinalOne(input) {
                var fd = new FormData();
                fd.append('csrf', '<?= e(csrf_token()) ?>');
                fd.append('action', 'set_final');
                fd.append('ajax', '1');
                fd.append('sheet_id', '<?= (int)$openSheet['id'] ?>');
                fd.append('line_id', input.dataset.line);
                fd.append('final_qty', input.value);
                input.style.borderColor = 'var(--warn)';
                fetch('stocktake.php', { method:'POST', body: fd })
                    .then(function(r){ return r.json(); })
                    .then(function(d){
                        input.style.borderColor = d.ok ? 'var(--pos)' : 'var(--neg)';
                        showStkToast(d.ok ? 'تم حفظ الكمية' : (d.err||'خطأ'), d.ok?'success':'error');
                    })
                    .catch(function(){ input.style.borderColor = 'var(--neg)'; });
            }
            // حذف صنف في وضع المقارنة
            function deleteLineReview(lineId, sheetId) {
                if (!confirm('حذف الصنف ده نهائياً من المحضر؟')) return;
                var f = document.createElement('form'); f.method='post';
                f.innerHTML = '<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">'
                    + '<input type="hidden" name="action" value="delete_line">'
                    + '<input type="hidden" name="line_id" value="'+lineId+'">'
                    + '<input type="hidden" name="sheet_id" value="'+sheetId+'">'
                    + '<input type="hidden" name="from_review" value="1">';
                document.body.appendChild(f); formSubmit(f);
            }
        </script>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- أداة الأكواد المربوطة داخل المحضر (متاحة للجارد والمراجع) -->
    <div class="card">
        <div class="section-title" style="cursor:pointer;" onclick="var b=document.getElementById('linkedBoxInner');b.style.display=b.style.display==='none'?'block':'none';">
            <i class="ti ti-link"></i> معرفة الأكواد المربوطة (اضغط للفتح/الإغلاق)
        </div>
        <div id="linkedBoxInner" style="display:none;">
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <input type="text" id="linkedCodeInput2" autocomplete="off"
                       placeholder="اضرب أو اكتب الكود ودوس Enter..."
                       enterkeyhint="search" onkeydown="if(isEnterKey(event)){event.preventDefault();lookupLinked('linkedCodeInput2','linkedResult2');}"
                       style="flex:1;min-width:200px;padding:10px 14px;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;font-size:14px;" aria-label="اضرب أو اكتب الكود ودوس Enter...">
                <button type="button" onclick="openBarcodeScanner('linkedCodeInput2')" title="مسح بالكاميرا"
                        style="background:var(--accent);color:#fff;border:none;border-radius:9px;padding:0 16px;cursor:pointer;font-size:18px;"><i class="ti ti-camera"></i></button>
                <button type="button" class="btn" onclick="lookupLinked('linkedCodeInput2','linkedResult2')"><i class="ti ti-link"></i> عرض</button>
            </div>
            <div id="linkedResult2" style="margin-top:14px;"></div>
        </div>
    </div>

    <?php if (!$reviewMode): ?>

    <div class="card">
        <div class="section-title">أصناف المحضر</div>
        <?php if (empty($lines)): ?>
            <div class="empty">لا توجد أصناف بعد. اضرب كود الصنف وأدخل الكمية.</div>
        <?php else: ?>
        <input type="text" id="jardSearch" placeholder="🔍 ابحث في أصناف المحضر (بالكود أو الاسم)..."
               onkeyup="filterTable('jardSearch','jardTable')"
               style="width:100%;padding:10px 14px;margin-bottom:12px;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;font-size:14px;background:var(--line-soft);" aria-label="🔍 ابحث في أصناف المحضر (بالكود أو الاسم)...">
        <table id="jardTable">
            <thead><tr>
                <th>الكود</th><th>اسم الصنف</th><th>الوحدة</th><th>كمية الجرد (بوحدة الكود)</th>
                <th>الإجمالي بالأساسي</th><th>رصيد السيستم</th><th>الفرق</th>
                <th>سعر التكلفة</th><th>إجمالي تكلفة الجرد</th><th>فرق التكلفة</th><th>سجل</th>
                <?php if ($session && $openSheet['status']==='open' && $canEdit): ?><th>حذف</th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php
                $totCostJard = 0; $totCostDiff = 0;
                foreach ($lines as $ln):
                    $sys = $ln['system_qty']!==null ? (float)$ln['system_qty'] : null;
                    $base = (float)$ln['base_qty'];
                    $diff = $sys!==null ? $base - $sys : null;
                    $diffCls = $diff===null?'diff-zero':($diff<0?'diff-neg':($diff>0?'diff-pos':'diff-zero'));
                    $cost = $ln['cost_price']!==null ? (float)$ln['cost_price'] : null;
                    // إجمالي تكلفة الجرد = الكمية بالأساسي × سعر التكلفة
                    $costJard = $cost!==null ? $base * $cost : null;
                    // فرق التكلفة = (جرد − سيستم) × سعر التكلفة (لو فيه رصيد)
                    $costDiff = ($cost!==null && $diff!==null) ? $diff * $cost : null;
                    if ($costJard!==null) $totCostJard += $costJard;
                    if ($costDiff!==null) $totCostDiff += $costDiff;
                    $costDiffCls = $costDiff===null?'diff-zero':($costDiff<0?'diff-neg':($costDiff>0?'diff-pos':'diff-zero'));
                ?>
                <tr>
                    <td><?= e($ln['entered_code']) ?></td>
                    <td style="white-space:normal;"><?= e($ln['item_name']) ?></td>
                    <td><?= e($ln['unit'] ?? '—') ?></td>
                    <td><?= rtrim(rtrim(number_format((float)$ln['count_qty'],3),'0'),'.') ?></td>
                    <td style="font-weight:600;color:var(--accent-text);"><?= rtrim(rtrim(number_format($base,3),'0'),'.') ?></td>
                    <td><?= $sys!==null ? rtrim(rtrim(number_format($sys,3),'0'),'.') : '—' ?></td>
                    <td class="<?= $diffCls ?>"><?= $diff!==null ? rtrim(rtrim(number_format($diff,3),'0'),'.') : '—' ?></td>
                    <td><?= $cost!==null ? number_format($cost,2) : '—' ?></td>
                    <td><?= $costJard!==null ? number_format($costJard,2) : '—' ?></td>
                    <td class="<?= $costDiffCls ?>"><?= $costDiff!==null ? number_format($costDiff,2) : '—' ?></td>
                    <td style="color:var(--ink-400);font-size:12px;"><?= e($ln['review_log'] ?? '') ?></td>
                    <?php if ($session && $openSheet['status']==='open' && $canEdit): ?>
                    <td>
                        <form method="post" data-confirm="حذف الصنف ده من المحضر؟" data-confirm-danger style="display:inline;">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete_line">
                            <input type="hidden" name="line_id" value="<?= (int)$ln['id'] ?>">
                            <input type="hidden" name="sheet_id" value="<?= (int)$openSheet['id'] ?>">
                            <button type="submit" style="background:var(--neg-soft);color:var(--neg);border:none;border-radius:7px;padding:5px 10px;cursor:pointer;font-family:inherit;font-size:13px;">حذف</button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
                <!-- صف إجماليات التكلفة -->
                <tr style="background:var(--accent-soft);font-weight:700;">
                    <td colspan="8" style="text-align:left;color:var(--ink-900);">الإجماليات</td>
                    <td style="color:var(--ink-900);"><?= number_format($totCostJard,2) ?></td>
                    <td class="<?= $totCostDiff<0?'diff-neg':($totCostDiff>0?'diff-pos':'diff-zero') ?>"><?= number_format($totCostDiff,2) ?></td>
                    <td<?= ($session && $openSheet['status']==='open' && $canEdit) ? ' colspan="2"' : '' ?>></td>
                </tr>
            </tbody>
        </table>
        <p style="color:var(--ink-500);font-size:12px;margin-top:10px;">
            سعر التكلفة من بيانات السيستم (متوسط التكلفة بالوحدة الأساسية). فرق التكلفة = (الجرد − رصيد السيستم) × سعر التكلفة.
        </p>
        <?php endif; ?>
    </div>
    <?php endif; // !reviewMode ?>

<?php else: ?>
    <!-- أداة: الأكواد المربوطة ببعض (استعلام مستقل عن الجرد) -->
    <div class="card">
        <div class="section-title"><i class="ti ti-link"></i> الأكواد المربوطة (اضرب كود يطلعلك كل الأكواد المربوطة بيه)</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <input type="text" id="linkedCodeInput" autocomplete="off"
                   placeholder="اضرب أو اكتب الكود ودوس Enter..."
                   enterkeyhint="search" onkeydown="if(isEnterKey(event)){event.preventDefault();lookupLinked();}"
                   style="flex:1;min-width:220px;padding:10px 14px;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;font-size:14px;" aria-label="اضرب أو اكتب الكود ودوس Enter...">
            <button type="button" onclick="openBarcodeScanner('linkedCodeInput')" title="مسح بالكاميرا"
                    style="background:var(--accent);color:#fff;border:none;border-radius:9px;padding:0 16px;cursor:pointer;font-size:18px;"><i class="ti ti-camera"></i></button>
            <button type="button" class="btn" onclick="lookupLinked()"><i class="ti ti-link"></i> عرض المربوط</button>
        </div>
        <div id="linkedResult" style="margin-top:14px;"></div>
    </div>

    <!-- بحث عن صنف في كل المحاضر -->
    <?php if ($session): ?>
    <div class="card">
        <div class="section-title">بحث عن صنف في كل المحاضر (بالكود)</div>
        <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;">
            <input type="text" name="q" id="sheetItemSearch" value="<?= e($searchTerm) ?>" autofocus autocomplete="off" aria-label="بحث في الأصناف"
                   placeholder="اضرب أو اكتب كود الصنف بالظبط..."
                   style="flex:1;min-width:220px;padding:10px 14px;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;font-size:14px;">
            <button type="button" onclick="openBarcodeScanner('sheetItemSearch')" title="مسح بالكاميرا"
                    style="background:var(--accent);color:#fff;border:none;border-radius:9px;padding:0 16px;cursor:pointer;font-size:18px;"><i class="ti ti-camera"></i></button>
            <button class="btn"><i class="ti ti-search"></i> بحث</button>
            <?php if ($searchTerm !== ''): ?>
                <a href="stocktake.php" class="btn btn-ghost" style="text-decoration:none;">مسح</a>
            <?php endif; ?>
        </form>

        <?php if ($searchTerm !== ''): ?>
            <?php if (empty($searchResults)): ?>
                <div class="empty" style="margin-top:14px;">مفيش نتائج لـ "<?= e($searchTerm) ?>" في أي محضر.</div>
            <?php else: ?>
            <p style="color:var(--ink-500);font-size:13px;margin:12px 0;"><?= count($searchResults) ?> نتيجة (الصنف لو اتجرد في أكتر من محضر بيظهر متكرر):</p>
            <table style="margin-top:6px;">
                <thead><tr>
                    <th>الكود</th><th>اسم الصنف</th><th>الوحدة</th>
                    <th>الكمية (نهائية/مراجع/جرد)</th><th>المحضر</th><th>القائم بالجرد</th><th>حالة المحضر</th>
                </tr></thead>
                <tbody>
                <?php foreach ($searchResults as $sr): ?>
                    <?php
                        $q = $sr['final_qty'] !== null ? (float)$sr['final_qty']
                           : ($sr['review_qty'] !== null ? (float)$sr['review_qty'] : (float)$sr['base_qty']);
                        $qOut = rtrim(rtrim(number_format($q,3),'0'),'.');
                        $stMap2 = ['open'=>['مفتوح','var(--ink-700)','var(--line-soft)'],'reviewing'=>['مراجعة','var(--warn)','var(--warn-soft)'],'done'=>['منتهي','var(--pos)','var(--pos-soft)']];
                        $bb = $stMap2[$sr['sheet_status']] ?? ['—','var(--ink-500)','var(--line)'];
                    ?>
                    <tr>
                        <td><?= e($sr['entered_code']) ?></td>
                        <td style="white-space:normal;font-weight:500;"><?= e($sr['item_name']) ?></td>
                        <td><?= e($sr['unit'] ?? '—') ?></td>
                        <td style="font-weight:600;color:var(--accent-text);"><?= $qOut ?></td>
                        <td>#<?= (int)$sr['sheet_no'] ?><?= $sr['place_note'] ? ' — '.e($sr['place_note']) : '' ?></td>
                        <td><?= e($sr['counter_name'] ?? '—') ?></td>
                        <td><span style="padding:3px 10px;border-radius:20px;font-size:12px;color:<?=$bb[1]?>;background:<?=$bb[2]?>;"><?= e($bb[0]) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- قائمة المحاضر -->
    <div class="card">
        <div class="section-title" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
            <span>محاضر الجرد <?= $session ? '' : '(الجلسة متوقفة)' ?></span>
            <?php
                // عدد المحاضر المنتهية
                $doneCount = 0;
                foreach ($sheets as $sh) { if ($sh['status'] === 'done') $doneCount++; }
            ?>
            <?php if ($session && $doneCount > 0): ?>
                <a href="stocktake_export.php?session=<?= (int)$session['id'] ?>" class="btn btn-green" style="text-decoration:none;font-size:14px;">
                    <i class="ti ti-download"></i> طباعة التقرير المجمّع (<?= $doneCount ?> محضر منتهي)
                </a>
            <?php endif; ?>
        </div>
        <?php if (empty($sheets)): ?>
            <div class="empty"><?= $session ? 'لا توجد محاضر بعد. اضغط "إضافة محضر جرد".' : 'مفيش جلسة جرد نشطة.' ?></div>
        <?php else: ?>
        <input type="text" id="sheetsFilter" placeholder="🔍 ابحث برقم المحضر / اسم الجارد / المراجع / المكان..."
               onkeyup="filterSheets()"
               style="width:100%;padding:11px 14px;margin-bottom:10px;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;font-size:14px;background:var(--line-soft);" aria-label="🔍 ابحث برقم المحضر / اسم الجارد / المراجع / المكان...">
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:14px;">
            <span style="font-size:13px;color:var(--ink-500);">الحالة:</span>
            <button type="button" class="status-chip active" data-status="" onclick="filterByStatus(this)">الكل</button>
            <button type="button" class="status-chip" data-status="open" onclick="filterByStatus(this)">مفتوح</button>
            <button type="button" class="status-chip" data-status="reviewing" onclick="filterByStatus(this)">مراجعة</button>
            <button type="button" class="status-chip" data-status="done" onclick="filterByStatus(this)">منتهي</button>
            <span id="sheetsCount" style="margin-inline-start:auto;font-size:13px;color:var(--accent-text);font-weight:600;background:var(--accent-soft);padding:5px 12px;border-radius:20px;"></span>
        </div>
        <div id="sheetsTree">
        <?php foreach ($sheetGroups as $section => $groupSheets): ?>
            <?php
                $secDone = 0; $secSearch = mb_strtolower($section);
                foreach ($groupSheets as $g) { if ($g['status']==='done') $secDone++; $secSearch .= ' '.mb_strtolower(($g['place_note']??'').' '.($g['counter_name']??'').' '.($g['reviewer_name']??'').' #'.$g['sheet_no']); }
            ?>
            <div class="tree-section" data-search="<?= e($secSearch) ?>">
                <div class="tree-head" data-acckey="stk:<?= e((string)$section) ?>" onclick="toggleSection(this)">
                    <span class="tree-caret">▸</span>
                    <span class="tree-name"><?= e($section) ?></span>
                    <span class="tree-count"><?= count($groupSheets) ?> محضر<?= $secDone>0 ? ' · '.$secDone.' منتهي' : '' ?></span>
                </div>
                <div class="tree-body">
                    <table>
                        <thead><tr><th>رقم المحضر</th><th>المكان</th><th>القائم بالجرد</th><th>المراجع</th><th>الأصناف</th><th>الحالة</th><th>التاريخ</th><th>تقرير</th><?php if ($owner): ?><th>حذف</th><?php endif; ?></tr></thead>
                        <tbody>
                        <?php foreach ($groupSheets as $sh): ?>
                            <?php
                                $stMap = ['open'=>['مفتوح','var(--ink-700)','var(--line-soft)'],'reviewing'=>['مراجعة','var(--warn)','var(--warn-soft)'],'done'=>['منتهي','var(--pos)','var(--pos-soft)']];
                                $b = $stMap[$sh['status']] ?? ['—','var(--ink-500)','var(--line)'];
                                $searchStr = '#'.(int)$sh['sheet_no'].' '.($sh['sheet_no']).' '.($sh['place_note'] ?: ($sh['branch_name'] ?? '')).' '.($sh['counter_name'] ?? '').' '.($sh['reviewer_name'] ?? '');
                            ?>
                            <tr class="sheet-row" onclick="openSheet(<?= (int)$sh['id'] ?>)" style="cursor:pointer;" title="اضغط لفتح المحضر" data-status="<?= e($sh['status']) ?>" data-search="<?= e(mb_strtolower($searchStr)) ?>">
                                <td><span class="sheet-link">#<?= (int)$sh['sheet_no'] ?></span></td>
                                <td><?= e($sh['place_note'] ?: ($sh['branch_name'] ?? '—')) ?></td>
                                <td><?= e($sh['counter_name'] ?? '—') ?></td>
                                <td><?= e($sh['reviewer_name'] ?? '—') ?></td>
                                <td><?= (int)$sh['items_count'] ?></td>
                                <td><span style="padding:3px 11px;border-radius:20px;font-size:12px;color:<?=$b[1]?>;background:<?=$b[2]?>;"><?= e($b[0]) ?></span></td>
                                <td style="font-size:12px;"><?= e(date('Y-m-d', strtotime($sh['created_at']))) ?></td>
                                <td onclick="event.stopPropagation();">
                                    <?php if ($sh['status'] === 'done'): ?>
                                        <a href="stocktake_export.php?sheet=<?= (int)$sh['id'] ?>" class="btn btn-sm" style="text-decoration:none;font-size:12px;padding:4px 10px;">تقرير</a>
                                    <?php else: ?><span style="color:var(--ink-300);">—</span><?php endif; ?>
                                </td>
                                <?php if ($owner): ?>
                                <td onclick="event.stopPropagation();">
                                    <?php if ($sh['status'] !== 'done'): ?>
                                        <form method="post" data-confirm="مسح المحضر #<?= (int)$sh['sheet_no'] ?> بالكامل؟ مش هينفع ترجعه." data-confirm-danger style="display:inline;">
                                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="delete_sheet">
                                            <input type="hidden" name="sheet_id" value="<?= (int)$sh['id'] ?>">
                                            <button type="submit" style="background:var(--neg-soft);color:var(--neg);border:none;border-radius:7px;padding:5px 11px;cursor:pointer;font-family:inherit;font-size:12px;">حذف</button>
                                        </form>
                                    <?php else: ?><span style="color:var(--ink-300);" title="مايتمسحش لأنه منتهي"><i class="ti ti-lock"></i></span><?php endif; ?>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Modal: بدء الجرد -->
<?php if ($owner && !$session): ?>
<div class="modal-bg" id="startModal">
    <div class="modal">
        <h3>بدء الجرد</h3>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="start_session">
            <p style="color:var(--ink-500);font-size:13px;margin-bottom:14px;">
                ممكن ترفع ملف رصيد المخزن (اختياري) عشان يظهر فرق الجرد. أو تبدأ من غير رصيد.
            </p>
            <div class="form-row">
                <label>ملف الرصيد (TAB-separated) — اختياري</label>
                <input type="file" name="balance_file" accept=".txt,.tsv,.csv">
            </div>
            <div class="modal-actions">
                <button type="submit" class="btn btn-green">بدء الجرد</button>
                <button type="button" class="btn btn-cancel" onclick="document.getElementById('startModal').classList.remove('open')">إلغاء</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Modal: رفع رصيد -->
<?php if ($owner && $session): ?>
<div class="modal-bg" id="balanceModal">
    <div class="modal">
        <h3>رفع ملف الرصيد</h3>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="upload_balance">
            <div class="form-row">
                <label>ملف الرصيد (TAB-separated)</label>
                <input type="file" name="balance_file" accept=".txt,.tsv,.csv" required>
            </div>
            <div class="modal-actions">
                <button type="submit" class="btn">رفع</button>
                <button type="button" class="btn btn-cancel" onclick="document.getElementById('balanceModal').classList.remove('open')">إلغاء</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Modal: إضافة محضر -->
<?php if ($session): ?>
<div class="modal-bg" id="sheetModal">
    <div class="modal">
        <h3>محضر جرد جديد</h3>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_sheet">
            <div class="form-row">
                <label>الفرع</label>
                <select name="branch_id">
                    <option value="">— بدون —</option>
                    <?php foreach ($branches as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= !empty($u['branch_id'])&&(int)$u['branch_id']===(int)$b['id']?'selected':'' ?>><?= e($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label>القسم / الرف</label>
                <?php if (!empty($stkPlaces)): ?>
                    <select name="place_note" required>
                        <option value="">— اختر القسم/الرف —</option>
                        <?php foreach ($stkPlaces as $pl): ?>
                            <option value="<?= e($pl) ?>"><?= e($pl) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($owner): ?>
                        <div style="margin-top:8px;">
                            <label style="font-size:12px;color:var(--ink-500);">أو اكتب مكان مخصص (للأونر فقط)</label>
                            <input type="text" name="place_custom" placeholder="مكان مخصص (اختياري)..." aria-label="أو اكتب مكان مخصص (للأونر فقط)">
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <!-- مفيش أماكن معرّفة: تكست بوكس حر (والأونر يقدر يعرّف أماكن من الإعدادات) -->
                    <input type="text" name="place_note" placeholder="مثلاً: مخزن أول، رف 3..." aria-label="ملاحظة المكان" <?= $owner?'':'required' ?>>
                    <?php if ($owner): ?>
                        <p style="font-size:12px;color:var(--ink-500);margin-top:6px;">
                            <a href="stocktake_places.php" style="color:var(--accent-text);">عرّف أقسام وأرفف ثابتة</a> عشان تظهر كقائمة للجميع.
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="modal-actions">
                <button type="submit" class="btn">بدء المحضر</button>
                <button type="button" class="btn btn-cancel" onclick="document.getElementById('sheetModal').classList.remove('open')">إلغاء</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
    document.querySelectorAll('.modal-bg').forEach(m => {
        m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
    });
</script>

<?php if (isset($_GET['notfound'])): ?>
<div id="notfoundModal" style="position:fixed;inset:0;background:rgba(30,27,75,0.45);z-index:var(--z-modal);display:flex;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:16px;padding:28px 32px;max-width:380px;text-align:center;box-shadow:0 10px 40px rgba(0,0,0,0.2);">
        <div style="font-size:48px;margin-bottom:8px;"><i class="ti ti-alert-triangle"></i></div>
        <h3 style="color:var(--neg);font-size:18px;margin-bottom:10px;">الكود غير موجود</h3>
        <p style="color:var(--ink-600);font-size:15px;line-height:1.7;margin-bottom:20px;">
            الكود <strong style="color:var(--ink-900);"><?= e($_GET['notfound']) ?></strong> مش موجود في قاعدة بيانات الأصناف.
            راجع الكود وحاول تاني.
        </p>
        <button onclick="closeNotFound()" class="btn" style="width:100%;">حسناً</button>
    </div>
</div>
<script>
    function closeNotFound() {
        document.getElementById('notfoundModal').style.display = 'none';
        // شيل المعامل من الـ URL ورجّع الفوكس على خانة الكود
        history.replaceState(null, '', 'stocktake.php?sheet=<?= (int)($_GET['sheet'] ?? 0) ?>');
        var ci = document.getElementById('codeInput');
        if (ci) ci.focus();
    }
    // إغلاق بـ Enter أو Escape
    document.addEventListener('keydown', function(e){
        if (document.getElementById('notfoundModal') && (e.key==='Enter' || e.key==='Escape')) closeNotFound();
    });
</script>
<?php endif; ?>

<?php if (isset($_GET['badqty'])): ?>
<div id="badqtyModal" style="position:fixed;inset:0;background:rgba(30,27,75,0.45);z-index:var(--z-modal);display:flex;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:16px;padding:28px 32px;max-width:400px;text-align:center;box-shadow:0 10px 40px rgba(0,0,0,0.2);">
        <div style="font-size:48px;margin-bottom:8px;"><i class="ti ti-ban"></i></div>
        <h3 style="color:var(--neg);font-size:18px;margin-bottom:10px;">كمية غير منطقية</h3>
        <p style="color:var(--ink-600);font-size:15px;line-height:1.7;margin-bottom:20px;">
            الكمية <strong style="color:var(--neg);"><?= e($_GET['badqty']) ?></strong> كبيرة جداً ومش منطقية.
            غالباً السكانر ضرب الكود في خانة الكمية بالغلط. الصنف <strong>ما اتسجّلش</strong>. حاول تاني.
        </p>
        <button onclick="closeBadQty()" class="btn" style="width:100%;">حسناً</button>
    </div>
</div>
<script>
    function closeBadQty() {
        document.getElementById('badqtyModal').style.display = 'none';
        history.replaceState(null, '', 'stocktake.php?sheet=<?= (int)($_GET['sheet'] ?? 0) ?>');
        var ci = document.getElementById('codeInput');
        if (ci) ci.focus();
    }
    document.addEventListener('keydown', function(e){
        if (document.getElementById('badqtyModal') && (e.key==='Enter' || e.key==='Escape')) closeBadQty();
    });
</script>
<?php endif; ?>

<script>
    // بحث/فلترة داخل جدول معروض (بالكود أو الاسم)
    function filterTable(inputId, tableId) {
        var q = (document.getElementById(inputId).value || '').trim().toLowerCase();
        var table = document.getElementById(tableId);
        if (!table) return;
        var rows = table.tBodies[0] ? table.tBodies[0].rows : [];
        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            // تجاهل صف الإجماليات (لو موجود)
            if (row.classList.contains('totals-row') || row.querySelector('[colspan]')) {
                continue;
            }
            var code = (row.cells[0] ? row.cells[0].textContent : '').toLowerCase();
            var name = (row.cells[1] ? row.cells[1].textContent : '').toLowerCase();
            row.style.display = (!q || code.indexOf(q) !== -1 || name.indexOf(q) !== -1) ? '' : 'none';
        }
    }
</script>

<script>
    // فتح المحضر بالضغط على الصف كله
    function openSheet(id) {
        window.location = 'stocktake.php?sheet=' + id;
    }
    // فتح/طي قسم في الشجرة
    // ===== حفظ حالة الأقسام لحد نهاية الجلسة =====
    var ACC_KEY = 'fagala_acc_state';

    function accLoad() {
        try { return JSON.parse(sessionStorage.getItem(ACC_KEY) || '{}'); }
        catch (e) { return {}; }
    }

    function accSave(key, open) {
        if (!key) return;
        try {
            var st = accLoad();
            // هنا بنحفظ المفتوح (عكس الاستلامات) لأن الأقسام مقفولة افتراضياً
            if (open) st[key] = 1; else delete st[key];
            sessionStorage.setItem(ACC_KEY, JSON.stringify(st));
        } catch (e) { }
    }

    function toggleSection(head) {
        var sec = head.parentElement;
        var isOpen = sec.classList.toggle('open');
        accSave(head.getAttribute('data-acckey'), isOpen);
    }

    // إعادة الأقسام المفتوحة بعد تحميل الصفحة
    (function accRestore() {
        var st = accLoad();
        if (!st) return;
        document.querySelectorAll('.tree-head').forEach(function (h) {
            if (st[h.getAttribute('data-acckey')]) {
                h.parentElement.classList.add('open');
            }
        });
    })();
    var currentStatus = '';
    // اختيار فلتر الحالة
    function filterByStatus(btn) {
        document.querySelectorAll('.status-chip').forEach(function(c){ c.classList.remove('active'); });
        btn.classList.add('active');
        currentStatus = btn.getAttribute('data-status') || '';
        filterSheets();
    }
    // فلترة الشجرة: بحث نصي + فلتر حالة، مع عدّاد الإجمالي
    function filterSheets() {
        var q = (document.getElementById('sheetsFilter').value || '').trim().toLowerCase();
        var sections = document.querySelectorAll('#sheetsTree .tree-section');
        var totalShown = 0;
        var hasFilter = (q !== '' || currentStatus !== '');
        sections.forEach(function(sec){
            var anyMatch = false;
            sec.querySelectorAll('tbody tr').forEach(function(r){
                var hay = r.getAttribute('data-search') || '';
                var st  = r.getAttribute('data-status') || '';
                var okText = (q === '' || hay.indexOf(q) !== -1);
                var okStat = (currentStatus === '' || st === currentStatus);
                var show = okText && okStat;
                r.style.display = show ? '' : 'none';
                if (show) { anyMatch = true; totalShown++; }
            });
            sec.style.display = anyMatch ? '' : 'none';
            if (hasFilter) {
                if (anyMatch) sec.classList.add('open'); else sec.classList.remove('open');
            } else {
                sec.classList.remove('open');
            }
        });
        // عدّاد الإجمالي
        var cnt = document.getElementById('sheetsCount');
        if (cnt) {
            if (hasFilter) {
                var lbl = {'':'', 'open':'مفتوح', 'reviewing':'مراجعة', 'done':'منتهي'}[currentStatus] || '';
                cnt.textContent = 'الإجمالي: ' + totalShown + ' محضر' + (lbl ? ' (' + lbl + ')' : '');
                cnt.style.display = '';
            } else {
                cnt.textContent = '';
                cnt.style.display = 'none';
            }
        }
    }
</script>

<script>
    // ===== أداة الأكواد المربوطة =====
    function lookupLinked(inputId, resultId) {
        inputId  = inputId  || 'linkedCodeInput';
        resultId = resultId || 'linkedResult';
        const inp = document.getElementById(inputId);
        const box = document.getElementById(resultId);
        const code = inp.value.trim();
        if (!code) { box.innerHTML = ''; return; }
        box.innerHTML = '<div style="color:var(--ink-500);padding:10px;">جاري البحث...</div>';

        fetch('stocktake.php?linked=' + encodeURIComponent(code))
            .then(r => r.json())
            .then(d => {
                if (!d.ok) {
                    box.innerHTML = '<div class="empty" style="padding:14px;">الكود "' + escapeHtml(code) + '" مش موجود في السيستم.</div>';
                    return;
                }
                if (!d.codes || d.codes.length === 0) {
                    box.innerHTML = '<div class="empty" style="padding:14px;">مفيش أكواد مربوطة.</div>';
                    return;
                }
                let html = '<div style="background:var(--bg);border-radius:9px;padding:10px 14px;margin-bottom:10px;">'
                    + '<span style="color:var(--ink-500);font-size:13px;">الصنف:</span> '
                    + '<span style="font-weight:600;color:var(--ink-900);">' + escapeHtml(d.name || '—') + '</span>'
                    + '<span style="color:var(--ink-300);font-size:12px;"> — ' + d.codes.length + ' كود مربوط</span></div>';
                html += '<div style="max-height:280px;overflow-y:auto;border:1px solid var(--line);border-radius:9px;">'
                    + '<table style="width:100%;border-collapse:collapse;">'
                    + '<thead style="position:sticky;top:0;background:var(--line-soft);z-index:1;"><tr>'
                    + '<th style="padding:8px 10px;text-align:right;font-size:13px;color:var(--ink-500);border-bottom:1px solid var(--line);">الكود</th>'
                    + '<th style="padding:8px 10px;text-align:right;font-size:13px;color:var(--ink-500);border-bottom:1px solid var(--line);">الوحدة</th>'
                    + '<th style="padding:8px 10px;text-align:right;font-size:13px;color:var(--ink-500);border-bottom:1px solid var(--line);">النوع</th>'
                    + '<th style="padding:8px 10px;text-align:right;font-size:13px;color:var(--ink-500);border-bottom:1px solid var(--line);">السعر</th>'
                    + '</tr></thead><tbody>';
                d.codes.forEach(c => {
                    const isSearched = c.code === code;
                    const rowBg = isSearched ? 'background:var(--warn-soft);' : '';
                    const typeBadge = c.is_base
                        ? '<span style="padding:2px 9px;border-radius:20px;font-size:12px;color:var(--pos);background:var(--pos-soft);">أساسي</span>'
                        : '<span style="padding:2px 9px;border-radius:20px;font-size:12px;color:var(--ink-700);background:var(--line-soft);">فرعي</span>';
                    const priceTxt = c.price !== null && c.price !== undefined ? Number(c.price).toFixed(2) + ' ج.م' : '—';
                    const unitTxt = rtrimZeros(Number(c.unit));
                    html += '<tr style="' + rowBg + 'border-bottom:1px solid var(--line);">'
                        + '<td style="padding:9px 10px;font-weight:600;color:var(--accent-text);">' + escapeHtml(c.code)
                        + (isSearched ? ' <span style="font-size:11px;color:var(--warn);">(اللي ضربته)</span>' : '') + '</td>'
                        + '<td style="padding:9px 10px;">' + unitTxt + '</td>'
                        + '<td style="padding:9px 10px;">' + typeBadge + '</td>'
                        + '<td style="padding:9px 10px;">' + priceTxt + '</td>'
                        + '</tr>';
                });
                html += '</tbody></table></div>';
                box.innerHTML = html;
            })
            .catch(() => {
                box.innerHTML = '<div class="empty" style="padding:14px;color:var(--neg);">تعذّر الاتصال بالسيستم (الجسر). حاول تاني.</div>';
            });
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    }
    function rtrimZeros(n) {
        return String(n).replace(/\.?0+$/, '') || '0';
    }
</script>

<script src="assets/barcode_scanner.js?v=6"></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
