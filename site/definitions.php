<?php
/**
 * definitions.php — التعريفات
 * نظام الفجالة ستور المالي
 *
 * تبويبات: الفروع | الموظفين | تصنيفات المصروفات
 * ملاحظة مهمة: عند إضافة فرع جديد، تُنشأ له خزينة عهدة تلقائياً.
 */
require_once __DIR__ . '/auth.php';
require_permission('definitions');

$pdo       = db();
$flash     = '';
$flashType = 'success';
$tab       = $_GET['tab'] ?? 'branches';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $flash = 'انتهت صلاحية الجلسة، حاول مرة أخرى.';
        $flashType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        // قراءة حقول الموقع/النطاق (مشتركة بين add/edit)
        $readGeo = function() {
            $lat = ($_POST['latitude'] ?? '')  !== '' ? (float)$_POST['latitude']  : null;
            $lng = ($_POST['longitude'] ?? '') !== '' ? (float)$_POST['longitude'] : null;
            $rad = ($_POST['geofence_radius'] ?? '') !== '' ? max(5, (int)$_POST['geofence_radius']) : 50;
            $en  = !empty($_POST['geofence_enabled']) ? 1 : 0;
            return [$lat, $lng, $rad, $en];
        };

        // ===== الفروع =====
        if ($action === 'add_branch') {
            $name    = trim($_POST['name'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $phone   = trim($_POST['phone'] ?? '');
            $stoId   = ($_POST['sto_id'] ?? '') === '' ? null : (int) $_POST['sto_id'];
            $inRecv  = isset($_POST['in_receiving']) ? 1 : 0;
            $hasFin  = isset($_POST['has_finance'])  ? 1 : 0;
            [$lat, $lng, $rad, $en] = $readGeo();
            if ($name === '') {
                $flash = 'اسم الفرع مطلوب.'; $flashType = 'error';
            } else {
                try {
                    $pdo->beginTransaction();
                    // إنشاء الفرع
                    $pdo->prepare('INSERT INTO branches (name, sto_id, in_receiving, has_finance, address, phone, latitude, longitude, geofence_radius, geofence_enabled) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$name, $stoId, $inRecv, $hasFin, $address ?: null, $phone ?: null, $lat, $lng, $rad, $en]);
                    $branchId = (int) $pdo->lastInsertId();
                    // خزينة العهدة بتتعمل بس لو الفرع ليه تعاملات مالية
                    if ($hasFin) {
                        $pdo->prepare(
                            "INSERT INTO treasuries (name, type, branch_id, balance)
                             VALUES (?, 'branch_custody', ?, 0)"
                        )->execute(['عهدة ' . $name, $branchId]);
                    }
                    $pdo->commit();
                    $flash = $hasFin
                        ? 'تم إضافة الفرع وإنشاء خزينة عهدة له تلقائياً.'
                        : 'تم إضافة الفرع (توزيع فقط — من غير عهدة).';
                } catch (PDOException $ex) {
                    $pdo->rollBack();
                    $flash = (str_contains($ex->getMessage(), 'uq_branches_name'))
                        ? 'اسم الفرع موجود بالفعل.' : 'حدث خطأ أثناء الإضافة.';
                    $flashType = 'error';
                }
            }
            $tab = 'branches';
        }
        elseif ($action === 'edit_branch') {
            $id      = (int) ($_POST['id'] ?? 0);
            $name    = trim($_POST['name'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $phone   = trim($_POST['phone'] ?? '');
            $stoId   = ($_POST['sto_id'] ?? '') === '' ? null : (int) $_POST['sto_id'];
            $inRecv  = isset($_POST['in_receiving']) ? 1 : 0;
            $hasFin  = isset($_POST['has_finance'])  ? 1 : 0;
            [$lat, $lng, $rad, $en] = $readGeo();
            if ($id > 0 && $name !== '') {
                $pdo->prepare('UPDATE branches SET name=?, sto_id=?, in_receiving=?, has_finance=?, address=?, phone=?, latitude=?, longitude=?, geofence_radius=?, geofence_enabled=? WHERE id=?')
                    ->execute([$name, $stoId, $inRecv, $hasFin, $address ?: null, $phone ?: null, $lat, $lng, $rad, $en, $id]);

                // مزامنة خزينة العهدة مع حالة "له تعاملات مالية"
                $tr = $pdo->prepare("SELECT id, balance FROM treasuries WHERE branch_id=? AND type='branch_custody' LIMIT 1");
                $tr->execute([$id]);
                $cust = $tr->fetch();

                if ($hasFin) {
                    if ($cust) {
                        $pdo->prepare('UPDATE treasuries SET is_active=1, name=? WHERE id=?')
                            ->execute(['عهدة ' . $name, (int)$cust['id']]);
                    } else {
                        $pdo->prepare("INSERT INTO treasuries (name, type, branch_id, balance) VALUES (?, 'branch_custody', ?, 0)")
                            ->execute(['عهدة ' . $name, $id]);
                    }
                } elseif ($cust) {
                    // مابنمسحش — بنخفيها بس عشان البيانات تفضل
                    if ((float)$cust['balance'] != 0.0) {
                        $flash = 'اتحفظ، بس خزينة العهدة رصيدها مش صفر — اتخفت من غير مسح.';
                        $flashType = 'error';
                    }
                    $pdo->prepare('UPDATE treasuries SET is_active=0 WHERE id=?')->execute([(int)$cust['id']]);
                }

                if ($flash === '') $flash = 'تم تعديل الفرع.';
            }
            $tab = 'branches';
        }
        elseif ($action === 'toggle_branch') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare('UPDATE branches SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
                $flash = 'تم تحديث حالة الفرع.';
            }
            $tab = 'branches';
        }

        // ===== الموظفون =====
        elseif ($action === 'add_employee') {
            $name     = trim($_POST['full_name'] ?? '');
            $branchId = ($_POST['branch_id'] ?? '') !== '' ? (int) $_POST['branch_id'] : null;
            $phone    = trim($_POST['phone'] ?? '');
            $jobTitle = trim($_POST['job_title'] ?? '');
            $salary   = (float) ($_POST['base_salary'] ?? 0);
            $expIn    = trim($_POST['expected_in'] ?? '') ?: null;
            $expOut   = trim($_POST['expected_out'] ?? '') ?: null;
            $userId   = ($_POST['user_id'] ?? '') !== '' ? (int) $_POST['user_id'] : null;
            if ($name === '') {
                $flash = 'اسم الموظف مطلوب.'; $flashType = 'error';
            } else {
                $pdo->prepare(
                    'INSERT INTO employees (full_name, branch_id, user_id, phone, job_title, base_salary, expected_in, expected_out)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$name, $branchId, $userId, $phone ?: null, $jobTitle ?: null, $salary, $expIn, $expOut]);
                $flash = 'تم إضافة الموظف.';
            }
            $tab = 'employees';
        }
        elseif ($action === 'edit_employee') {
            $id       = (int) ($_POST['id'] ?? 0);
            $name     = trim($_POST['full_name'] ?? '');
            $branchId = ($_POST['branch_id'] ?? '') !== '' ? (int) $_POST['branch_id'] : null;
            $phone    = trim($_POST['phone'] ?? '');
            $jobTitle = trim($_POST['job_title'] ?? '');
            $salary   = (float) ($_POST['base_salary'] ?? 0);
            $expIn    = trim($_POST['expected_in'] ?? '') ?: null;
            $expOut   = trim($_POST['expected_out'] ?? '') ?: null;
            $userId   = ($_POST['user_id'] ?? '') !== '' ? (int) $_POST['user_id'] : null;
            if ($id > 0 && $name !== '') {
                $pdo->prepare(
                    'UPDATE employees SET full_name=?, branch_id=?, user_id=?, phone=?, job_title=?, base_salary=?, expected_in=?, expected_out=? WHERE id=?'
                )->execute([$name, $branchId, $userId, $phone ?: null, $jobTitle ?: null, $salary, $expIn, $expOut, $id]);
                $flash = 'تم تعديل الموظف.';
            }
            $tab = 'employees';
        }
        elseif ($action === 'toggle_employee') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare('UPDATE employees SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
                $flash = 'تم تحديث حالة الموظف.';
            }
            $tab = 'employees';
        }

        // ===== تصنيفات المصروفات =====
        elseif ($action === 'add_category') {
            $name = trim($_POST['name'] ?? '');
            if ($name === '') {
                $flash = 'اسم التصنيف مطلوب.'; $flashType = 'error';
            } else {
                try {
                    $parentId = ($_POST['parent_id'] ?? '') !== '' ? (int) $_POST['parent_id'] : null;
                    $accType  = in_array($_POST['acc_type'] ?? '', ['asset','liability','revenue','expense'], true)
                                ? $_POST['acc_type'] : 'expense';
                    $isCust   = isset($_POST['is_custody']) ? 1 : 0;
                    $pdo->prepare(
                        'INSERT INTO accounts (parent_id, name, type, is_custody) VALUES (?, ?, ?, ?)'
                    )->execute([$parentId, $name, $accType, $isCust]);
                    $flash = 'تم إضافة الحساب.';
                } catch (PDOException $ex) {
                    $flash = 'التصنيف موجود بالفعل.'; $flashType = 'error';
                }
            }
            $tab = 'categories';
        }
        elseif ($action === 'toggle_category') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare('UPDATE accounts SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
                $flash = 'تم تحديث حالة الحساب.';
            }
            $tab = 'categories';
        }
        elseif ($action === 'import_categories') {
            $tab = 'categories';
            if (empty($_FILES['cats_file']['tmp_name'])) {
                $flash = 'اختر ملف الأول.'; $flashType = 'error';
            } else {
                $tmp = $_FILES['cats_file']['tmp_name'];
                $names = [];
                if (($fh = fopen($tmp, 'r')) !== false) {
                    $first = true;
                    while (($row = fgetcsv($fh)) !== false) {
                        $val = trim((string)($row[0] ?? ''));
                        // تجاهل سطر العنوان لو كان فيه كلمة زي "الاسم/name/التصنيف"
                        if ($first) {
                            $first = false;
                            $low = mb_strtolower($val);
                            if ($val === '' || in_array($low, ['name','الاسم','التصنيف','اسم التصنيف','category'], true)) continue;
                        }
                        if ($val !== '') $names[] = $val;
                    }
                    fclose($fh);
                }
                // تنظيف: إزالة التكرار مع الحفاظ على الترتيب
                $names = array_values(array_unique($names));
                if (empty($names)) {
                    $flash = 'الملف فاضي أو مش مقروء. تأكد إنه CSV والعمود الأول فيه أسماء التصنيفات.'; $flashType = 'error';
                } else {
                    $ins = $pdo->prepare('INSERT IGNORE INTO accounts (name, type) VALUES (?, \'expense\')');
                    $added = 0;
                    foreach ($names as $n) {
                        $ins->execute([mb_substr($n, 0, 120)]);
                        $added += $ins->rowCount();
                    }
                    $skipped = count($names) - $added;
                    $flash = "تم استيراد $added تصنيف" . ($skipped > 0 ? " (تخطّي $skipped مكرر/موجود)" : '') . '.';
                }
            }
        }

        // ===== الخزائن (المركزية فقط — عهد الفروع تُنشأ تلقائياً) =====
        elseif ($action === 'add_treasury') {
            $name = trim($_POST['name'] ?? '');
            $type = $_POST['type'] ?? '';
            // 'main' = الخزينة الرئيسية. نوع مستقل بذاته عن قصد ومش 'cash'
            // تانية: التقفيل وإذون العهدة بيختاروا أول خزينة cash بالـ id،
            // فخزينتين كاش كانوا هيبقوا متفرقين بترتيب الـ id بس — وده
            // بيقع أول ما حد يوقف القديمة. RULES: النية تتكتب في الداتا.
            $validTypes = ['cash','main','visa','instapay','card'];
            if ($name === '' || !in_array($type, $validTypes, true)) {
                $flash = 'بيانات الخزينة غير صحيحة.'; $flashType = 'error';
            } else {
                $pdo->prepare("INSERT INTO treasuries (name, type, branch_id, balance, is_active) VALUES (?, ?, NULL, 0, 1)")
                    ->execute([$name, $type]);
                $flash = 'تم إضافة الخزينة.';
            }
            $tab = 'treasuries';
        }
        elseif ($action === 'edit_treasury') {
            $id   = (int) ($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            if ($id > 0 && $name !== '') {
                // الاسم فقط — النوع لا يتغيّر لتجنّب بهدلة كشف الحساب
                $pdo->prepare('UPDATE treasuries SET name=? WHERE id=?')->execute([$name, $id]);
                $flash = 'تم تعديل الخزينة.';
            }
            $tab = 'treasuries';
        }
        elseif ($action === 'toggle_treasury') {
            $id = (int) ($_POST['id'] ?? 0);
            // منع إيقاف خزينة عهدة فرع من هنا (تُدار مع الفرع)
            if ($id > 0) {
                $chk = $pdo->prepare("SELECT type FROM treasuries WHERE id=?");
                $chk->execute([$id]);
                if ($chk->fetchColumn() === 'branch_custody') {
                    $flash = 'خزائن عهد الفروع تُدار مع الفرع نفسه.'; $flashType = 'error';
                } else {
                    $pdo->prepare('UPDATE treasuries SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
                    $flash = 'تم تحديث حالة الخزينة.';
                }
            }
            $tab = 'treasuries';
        }
    }
    // PRG: بعد أي POST نعمل تحويل لصفحة GET عشان الـ Refresh ما يعيدش الإرسال (منع التكرار)
    $_SESSION['flash'] = $flash;
    $_SESSION['flash_type'] = $flashType;
    header('Location: ' . ('definitions.php?tab=' . urlencode($tab)));
    exit;
}

// نقرأ رسالة الفلاش من السيشن (بعد التحويل)
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    $flashType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash'], $_SESSION['flash_type']);
}

// ===== جلب البيانات =====
$branches   = $pdo->query('SELECT * FROM branches ORDER BY name')->fetchAll();

// مخازن نظام الشركة (لربط الفرع بمخزنه). لو الجسر واقف بترجع فاضية والحقل يفضل اختياري.
$storeList = [];
try {
    require_once __DIR__ . '/receiving_lib.php';
    $storeList = sqlsrv_store_names();
} catch (Throwable $ex) {
    error_log('definitions: تعذّر جلب المخازن — ' . $ex->getMessage());
}
$employees  = $pdo->query(
    'SELECT e.*, b.name AS branch_name FROM employees e
     LEFT JOIN branches b ON b.id = e.branch_id ORDER BY e.full_name'
)->fetchAll();
$categories = $pdo->query(
    'SELECT id, parent_id, name, type, is_custody, dynamic_src, src_table, src_id, is_active, sort_order
     FROM accounts ORDER BY sort_order, name'
)->fetchAll();
// شجرة مسطّحة للعرض (أب وبعده أبناؤه بمسافة بادئة)
$accByParent = [];
foreach ($categories as $c) { $accByParent[(int)($c['parent_id'] ?? 0)][] = $c; }
$accFlat = [];
// الحسابات المتولّدة تلقائياً (موظفين/موردين) مش بتتعرض صف بصف —
// بتتعد تحت الأب بس، عشان الجدول ما يبقاش 80+ سطر.
$accAutoCount = [];
$accWalk = function(int $pid, int $depth) use (&$accWalk, &$accFlat, &$accAutoCount, $accByParent) {
    foreach ($accByParent[$pid] ?? [] as $c) {
        if (!empty($c['src_table'])) {
            $accAutoCount[$pid] = ($accAutoCount[$pid] ?? 0) + 1;
            continue;
        }
        $c['depth'] = $depth; $accFlat[] = $c;
        $accWalk((int)$c['id'], $depth + 1);
    }
};
$accWalk(0, 0);
// الآباء المتاحين للاختيار عند الإضافة
$accParents = array_values(array_filter($accFlat, fn($a) => (int)$a['depth'] < 2));
$treasuries = $pdo->query(
    "SELECT t.*, b.name AS branch_name FROM treasuries t
     LEFT JOIN branches b ON b.id = t.branch_id
     ORDER BY FIELD(t.type,'cash','main','visa','instapay','card','branch_custody'), t.name"
)->fetchAll();
$activeBranches = array_filter($branches, fn($b) => $b['is_active']);
// المستخدمون المتاحون للربط بموظف (لتسجيل الحضور)
$linkUsers = $pdo->query('SELECT id, full_name, username FROM users WHERE is_active=1 ORDER BY full_name')->fetchAll();

$page_title    = 'التعريفات';
$active_module = 'definitions';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .flash { padding:12px 16px; border-radius:10px; margin-bottom:18px; font-size:14px; }
    .flash.success { background:var(--pos-soft); color:var(--pos); }
    .flash.error   { background:var(--neg-soft); color:var(--neg); }
    .tabs { display:flex; gap:6px; margin-bottom:20px; flex-wrap:wrap; }
    .tab {
        padding:10px 20px; border-radius:10px; text-decoration:none;
        color:var(--accent-hover); background:#fff; font-size:14px; font-weight:500;
        border:1.5px solid transparent;
    }
    .tab.active { background:var(--accent); color:#fff; }
    .tab:not(.active):hover { background:var(--bg); }
    .toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; }
    .btn { background:var(--accent); color:#fff; border:none; padding:11px 20px; border-radius:10px;
        font-size:14px; font-family:inherit; cursor:pointer; text-decoration:none;
        display:inline-flex; align-items:center; gap:7px; }
    .btn:hover { background:var(--accent-hover); }
    .btn-sm { padding:6px 12px; font-size:13px; border-radius:8px; }
    .btn-ghost { background:var(--bg); color:var(--accent-hover); }
    .btn-ghost:hover { background:var(--line); }
    table { width:100%; border-collapse:collapse; }
    th, td { padding:12px 14px; text-align:right; font-size:14px; border-bottom:1px solid var(--line); }
    th { color:var(--ink-500); font-weight:600; font-size:13px; }
    td { color:var(--ink-800); }
    .badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:12px; }
    .badge.active { background:var(--pos-soft); color:var(--pos); }
    .badge.inactive { background:var(--line-soft); color:#999; }
    .modal-bg { display:none; position:fixed; inset:0; background:rgba(30,27,75,0.45);
        z-index:200; align-items:flex-start; justify-content:center; padding:30px 16px; overflow-y:auto; }
    .modal-bg.open { display:flex; }
    .modal { background:#fff; border-radius:16px; width:100%; max-width:480px; padding:26px; }
    .modal h3 { color:var(--ink-900); margin-bottom:18px; font-size:18px; }
    .form-row { margin-bottom:14px; }
    .tag-on { display:inline-block; padding:2px 8px; margin:1px 2px; border-radius:5px;
        background:var(--pos-soft); color:var(--pos); font-size:11px; font-weight:600; }
    .tag-off { display:inline-block; padding:2px 8px; margin:1px 2px; border-radius:5px;
        background:var(--line-soft); color:var(--ink-500); font-size:11px; font-weight:600; }
    .chk-row { display:flex; align-items:flex-start; gap:9px; padding:9px 11px; margin-top:7px;
        border:1px solid var(--line); border-radius:9px; cursor:pointer; transition:border-color .15s, background .15s; }
    .chk-row:hover { border-color:var(--ink-300); background:var(--line-soft); }
    .chk-row input { margin-top:3px; width:16px; height:16px; flex:0 0 auto; cursor:pointer; }
    .chk-row span { display:flex; flex-direction:column; gap:2px; }
    .chk-row b { font-size:13.5px; color:var(--ink-800); font-weight:600; }
    .chk-row small { font-size:11.5px; color:var(--ink-500); }
    .form-row label { display:block; font-size:13px; color:var(--ink-700); margin-bottom:6px; font-weight:500; }
    .form-row input, .form-row select {
        width:100%; padding:10px 12px; border:1.5px solid var(--line); border-radius:9px;
        font-size:14px; font-family:inherit; background:var(--line-soft); }
    .form-row input:focus, .form-row select:focus { outline:none; border-color:var(--accent); background:#fff; }
    .modal-actions { display:flex; gap:10px; margin-top:20px; }
    .btn-cancel { background:var(--line-soft); color:#666; }
    .empty { text-align:center; color:#bbb; padding:30px; font-size:14px; }
</style>

<?php if ($flash): ?>
    <div class="flash <?= e($flashType) ?>"><?= e($flash) ?></div>
<?php endif; ?>

<div class="tabs">
    <a href="?tab=branches"   class="tab <?= $tab==='branches'?'active':'' ?>">الفروع</a>
    <a href="?tab=employees"  class="tab <?= $tab==='employees'?'active':'' ?>">الموظفون</a>
    <a href="?tab=treasuries" class="tab <?= $tab==='treasuries'?'active':'' ?>">الخزائن</a>
    <a href="?tab=categories" class="tab <?= $tab==='categories'?'active':'' ?>">تصنيفات المصروفات</a>
</div>

<?php if ($tab === 'branches'): ?>
    <div class="toolbar">
        <span style="color:var(--ink-500);font-size:14px;"><?= count($branches) ?> فرع</span>
        <button class="btn" onclick="openBranch()"><i class="ti ti-plus"></i> إضافة فرع</button>
    </div>
    <div class="card">
        <?php if (empty($branches)): ?>
            <div class="empty">لا توجد فروع بعد. أضف أول فرع.</div>
        <?php else: ?>
        <table>
            <thead><tr><th>الاسم</th><th>العنوان</th><th>التليفون</th><th>الخصائص</th><th>الحالة</th><th>إجراءات</th></tr></thead>
            <tbody>
            <?php foreach ($branches as $b): ?>
                <tr>
                    <td style="font-weight:500;"><?= e($b['name']) ?></td>
                    <td><?= e($b['address'] ?? '—') ?></td>
                    <td><?= e($b['phone'] ?? '—') ?></td>
                    <td style="white-space:nowrap;">
                        <?php if (!empty($b['in_receiving'])): ?><span class="tag-on">استلامات</span><?php endif; ?>
                        <?php if (!empty($b['has_finance'])): ?><span class="tag-on">مالي</span>
                        <?php else: ?><span class="tag-off">توزيع فقط</span><?php endif; ?>
                    </td>
                    <td><span class="badge <?= $b['is_active']?'active':'inactive' ?>"><?= $b['is_active']?'نشط':'موقوف' ?></span></td>
                    <td>
                        <button class="btn btn-sm btn-ghost"
                            onclick='openBranch(<?= json_encode($b, JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>تعديل</button>
                        <form method="post" style="display:inline;" onsubmit="return confirm('تأكيد؟')">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="toggle_branch">
                            <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                            <button class="btn btn-sm btn-ghost"><?= $b['is_active']?'إيقاف':'تفعيل' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div class="modal-bg" id="branchModal">
        <div class="modal" style="max-width:560px;">
            <h3 id="branchTitle">إضافة فرع</h3>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" id="branchAction" value="add_branch">
                <input type="hidden" name="id" id="branchId" value="">
                <div class="form-row"><label for="b_name">اسم الفرع *</label><input type="text" name="name" id="b_name" required></div>
                <div class="form-row">
                    <label for="b_sto">المخزن في نظام الشركة</label>
                    <select name="sto_id" id="b_sto">
                        <option value="">— مفيش (فرع توزيع بس) —</option>
                        <?php foreach ($storeList as $sid => $sname): ?>
                            <option value="<?= (int)$sid ?>"><?= e($sname) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small style="display:block;color:var(--ink-500);font-size:12px;margin-top:4px;">
                        بيستخدم لعرض رصيد الأصناف في الفرع جوه الاستلامات. سيبه فاضي لو الفرع مالوش مخزن.
                    </small>
                </div>

                <div class="form-row">
                    <label>خصائص الفرع</label>
                    <label class="chk-row">
                        <input type="checkbox" name="in_receiving" id="b_recv" value="1" checked>
                        <span><b>يظهر في الاستلامات</b>
                            <small>عمود توزيع في فواتير الشراء</small></span>
                    </label>
                    <label class="chk-row">
                        <input type="checkbox" name="has_finance" id="b_fin" value="1" checked>
                        <span><b>له تعاملات مالية</b>
                            <small>عهدة + كارت في الخزينة + يظهر في الرئيسية</small></span>
                    </label>
                </div>
                <div class="form-row"><label for="b_address">العنوان</label><input type="text" name="address" id="b_address"></div>
                <div class="form-row"><label for="b_phone">التليفون</label><input type="text" name="phone" id="b_phone"></div>

                <!-- ===== موقع الفرع ونطاق الحضور ===== -->
                <div style="border-top:1px solid var(--line);margin:16px 0 12px;padding-top:14px;">
                    <label for="b_lat" style="display:flex;align-items:center;gap:8px;font-size:14px;color:var(--ink-900);font-weight:600;cursor:pointer;">
                        <input type="checkbox" name="geofence_enabled" id="b_geoEnabled" value="1" onchange="toggleGeo()" style="width:auto;">
                        تفعيل نطاق تسجيل الحضور بالـ GPS لهذا الفرع
                    </label>
                    <p style="font-size:12px;color:var(--ink-500);margin-top:6px;">لما يكون مفعّل، الموظف لازم يكون جوه النطاق عشان يبصم (إلا لو مسموحله يبصم من أي مكان). البصمة برّة النطاق بتتسجّل مع تنبيه.</p>
                </div>

                <div id="geoFields" style="display:none;">
                    <div style="display:flex;gap:10px;">
                        <div class="form-row" style="flex:1;"><label>خط العرض (Latitude)</label><input type="text" name="latitude" id="b_lat" readonly style="background:var(--accent-soft);"></div>
                        <div class="form-row" style="flex:1;"><label for="b_lng">خط الطول (Longitude)</label><input type="text" name="longitude" id="b_lng" readonly style="background:var(--accent-soft);"></div>
                    </div>
                    <div class="form-row"><label for="b_radius">نصف قطر النطاق (متر) — الأنسب 30–100م</label><input type="number" name="geofence_radius" id="b_radius" value="50" min="5" onchange="updateCircle()"></div>

                    <div style="display:flex;gap:8px;margin-bottom:8px;">
                        <button type="button" class="btn btn-sm" onclick="useMyLocation()"><i class="ti ti-current-location"></i> استخدم موقعي الحالي</button>
                        <span style="font-size:12px;color:var(--ink-500);align-self:center;">أو دوس على الخريطة لتحديد المكان</span>
                    </div>
                    <div id="branchMap" style="height:280px;border-radius:10px;border:1.5px solid var(--line);margin-bottom:8px;"></div>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn">حفظ</button>
                    <button type="button" class="btn btn-cancel" onclick="closeBranch()">إلغاء</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Leaflet (خرائط مجانية بدون مفتاح) -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<?php elseif ($tab === 'employees'): ?>
    <div class="card" style="background:var(--warn-soft);border:1.5px solid #ffe0a8;margin-bottom:14px;">
        <div style="color:var(--warn);font-size:13px;line-height:1.7;">
            <strong>ملاحظة:</strong> الأفضل إنشاء الموظف من صفحة <strong>المستخدمين</strong> (كل موظف = حساب مستخدم،
            تحدّدله كل حاجة من مكان واحد: الدخول، الصلاحيات، الفرع، المرتب، مواعيد الحضور). الموظفون اللي بتضيفهم هنا
            مش مربوطين بحساب دخول، فمش هيقدروا يسجّلوا حضورهم. استخدم الصفحة دي للموظفين بدون حساب فقط.
        </div>
    </div>
    <div class="toolbar">
        <span style="color:var(--ink-500);font-size:14px;"><?= count($employees) ?> موظف</span>
        <button class="btn" onclick="openEmp()"><i class="ti ti-plus"></i> إضافة موظف</button>
    </div>
    <div class="card">
        <?php if (empty($employees)): ?>
            <div class="empty">لا يوجد موظفون بعد.</div>
        <?php else: ?>
        <table>
            <thead><tr><th>الاسم</th><th>الفرع</th><th>الوظيفة</th><th>الراتب</th><th>التليفون</th><th>الحالة</th><th>إجراءات</th></tr></thead>
            <tbody>
            <?php foreach ($employees as $emp): ?>
                <tr>
                    <td style="font-weight:500;"><?= e($emp['full_name']) ?></td>
                    <td><?= e($emp['branch_name'] ?? '—') ?></td>
                    <td><?= e($emp['job_title'] ?? '—') ?></td>
                    <td><?= number_format((float)$emp['base_salary'], 2) ?></td>
                    <td><?= e($emp['phone'] ?? '—') ?></td>
                    <td><span class="badge <?= $emp['is_active']?'active':'inactive' ?>"><?= $emp['is_active']?'نشط':'موقوف' ?></span></td>
                    <td>
                        <button class="btn btn-sm btn-ghost"
                            onclick='openEmp(<?= json_encode($emp, JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>تعديل</button>
                        <form method="post" style="display:inline;" onsubmit="return confirm('تأكيد؟')">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="toggle_employee">
                            <input type="hidden" name="id" value="<?= (int)$emp['id'] ?>">
                            <button class="btn btn-sm btn-ghost"><?= $emp['is_active']?'إيقاف':'تفعيل' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div class="modal-bg" id="empModal">
        <div class="modal">
            <h3 id="empTitle">إضافة موظف</h3>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" id="empAction" value="add_employee">
                <input type="hidden" name="id" id="empId" value="">
                <div class="form-row"><label for="e_name">الاسم الكامل *</label><input type="text" name="full_name" id="e_name" required></div>
                <div class="form-row">
                    <label for="e_job">الفرع</label>
                    <select name="branch_id" id="e_branch">
                        <option value="">— بدون —</option>
                        <?php foreach ($activeBranches as $b): ?>
                            <option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>الوظيفة</label><input type="text" name="job_title" id="e_job"></div>
                <div class="form-row"><label for="e_salary">الراتب الأساسي</label><input type="number" step="0.01" name="base_salary" id="e_salary" value="0"></div>
                <div class="form-row"><label for="e_phone">التليفون</label><input type="text" name="phone" id="e_phone"></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="form-row"><label for="t_name">الحضور المتوقع</label><input type="time" name="expected_in" id="e_in" value="09:00"></div>
                    <div class="form-row"><label>الانصراف المتوقع</label><input type="time" name="expected_out" id="e_out" value="17:00"></div>
                </div>
                <div class="form-row">
                    <label>ربط بحساب مستخدم (لتسجيل الحضور)</label>
                    <select name="user_id" id="e_user">
                        <option value="">— بدون —</option>
                        <?php foreach ($linkUsers as $lu): ?>
                            <option value="<?= (int)$lu['id'] ?>"><?= e($lu['full_name']) ?> (<?= e($lu['username']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn">حفظ</button>
                    <button type="button" class="btn btn-cancel" onclick="closeEmp()">إلغاء</button>
                </div>
            </form>
        </div>
    </div>

<?php elseif ($tab === 'treasuries'): ?>
    <div class="toolbar">
        <span style="color:var(--ink-500);font-size:14px;"><?= count($treasuries) ?> خزينة</span>
        <button class="btn" onclick="openTre()"><i class="ti ti-plus"></i> إضافة خزينة</button>
    </div>
    <div class="card">
        <?php if (empty($treasuries)): ?>
            <div class="empty">لا توجد خزائن بعد. استورد ملف الخزائن أو أضف خزينة.</div>
        <?php else: ?>
        <?php
        // مين الخزينة اللي بتستقبل فعلاً؟ بننادي نفس الدوال اللي النظام
        // بيستخدمها وقت التشغيل، فاللي بيتعرض هنا هو الحقيقة مش تخمين.
        // ده اللي بيمنع اللبس: الفرق بين خزينتين كاش كان بترتيب الـ id بس
        // ومش مكتوب في أي حتة.
        require_once __DIR__ . '/finance.php';
        $closingTreasuryId = central_treasury_by_type('cash'); // تقفيل الكاشير
        $custodyTreasuryId = main_cash_treasury_id();          // إذون عهدة الفروع
        ?>
        <table>
            <thead><tr><th>الاسم</th><th>النوع</th><th>الرصيد</th><th>الحالة</th><th>إجراءات</th></tr></thead>
            <tbody>
            <?php foreach ($treasuries as $t): ?>
                <?php
                $isCustody = $t['type'] === 'branch_custody';
                $tid       = (int) $t['id'];
                $roles     = [];
                if ($closingTreasuryId !== null && $tid === $closingTreasuryId) $roles[] = 'تقفيل الكاشير';
                if ($custodyTreasuryId !== null && $tid === $custodyTreasuryId) $roles[] = 'إذون العهدة';
                ?>
                <tr>
                    <td style="font-weight:500;">
                        <?= e($t['name']) ?>
                        <?php if ($roles): ?>
                            <span title="النظام بيستخدم الخزينة دي تلقائياً لـ: <?= e(implode(' و', $roles)) ?>. لو وقفتها، اللي بعدها في الترتيب هياخد مكانها."
                                  style="display:inline-block;margin-right:6px;padding:1px 8px;border-radius:20px;
                                         font-size:11px;background:var(--pos-soft);color:var(--pos);white-space:nowrap;">
                                ⇦ <?= e(implode(' · ', $roles)) ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $typeLabels = ['cash'=>'كاش','visa'=>'فيزا','instapay'=>'إنستا باي',
                            'card'=>'كارت','branch_custody'=>'عهدة فرع',
                            'main'=>'رئيسية'];
                        echo e($typeLabels[$t['type']] ?? $t['type']);
                        if ($isCustody && $t['branch_name']) echo ' (' . e($t['branch_name']) . ')';
                        ?>
                    </td>
                    <td style="font-weight:500;"><?= number_format((float)$t['balance'],2) ?></td>
                    <td><span class="badge <?= $t['is_active']?'active':'inactive' ?>"><?= $t['is_active']?'نشط':'موقوف' ?></span></td>
                    <td>
                        <?php if ($isCustody): ?>
                            <span style="color:#bbb;font-size:13px;">تُدار مع الفرع</span>
                        <?php else: ?>
                            <button class="btn btn-sm btn-ghost"
                                onclick='openTre(<?= json_encode(["id"=>$t["id"],"name"=>$t["name"]], JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>تعديل</button>
                            <?php
                            // الرسالة بتتبني هنا وبتتشفّر مرة واحدة.
                            // الـattribute بعلامة مفردة عن قصد: ناتج json_encode
                            // محاط بعلامتين مزدوجتين، فلو الـattribute مزدوج كمان
                            // أول علامة بتقفله والزرار يتكسر.
                            // JSON_HEX_APOS بيمنع أي علامة مفردة جوه النص من إنها
                            // تكسر الـattribute نفسه.
                            $confirmMsg = ($roles && $t['is_active'])
                                ? '⚠️ الخزينة دي بيدخل فيها تلقائياً: ' . implode(' و', $roles) . ".\n\n"
                                  . "لو وقفتها، النظام هيحوّل ده لأول خزينة كاش شغّالة بعدها — من غير ما يقولك.\n\n"
                                  . 'متأكد؟'
                                : 'تأكيد؟';
                            ?>
                            <form method="post" style="display:inline;"
                                  onsubmit='return confirm(<?= json_encode($confirmMsg, JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="toggle_treasury">
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                <button class="btn btn-sm btn-ghost"><?= $t['is_active']?'إيقاف':'تفعيل' ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div class="modal-bg" id="treModal">
        <div class="modal">
            <h3 id="treTitle">إضافة خزينة</h3>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" id="treAction" value="add_treasury">
                <input type="hidden" name="id" id="treId" value="">
                <div class="form-row"><label>اسم الخزينة *</label><input type="text" name="name" id="t_name" required></div>
                <div class="form-row" id="typeRow">
                    <label>النوع *</label>
                    <select name="type" id="t_type" onchange="treTypeHint()">
                        <option value="cash">كاش</option>
                        <option value="visa">فيزا</option>
                        <option value="instapay">إنستا باي</option>
                        <option value="card">كارت</option>
                        <option value="main">رئيسية</option>
                    </select>
                    <div id="treHint" style="display:none;margin-top:8px;padding:9px 11px;border-radius:9px;
                         background:var(--line-soft);color:var(--ink-600);font-size:12px;line-height:1.7;">
                        خزينة مستقلة تماماً: <b>مابيصبّش فيها تقفيل الكاشير</b> ومابتطلعش منها إذون
                        عهدة الفروع. بتتمول بتحويل من خزينة الكاش، ومسموح رصيدها يبقى بالسالب.
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn">حفظ</button>
                    <button type="button" class="btn btn-cancel" onclick="closeTre()">إلغاء</button>
                </div>
            </form>
        </div>
    </div>

<?php else: // categories ?>
    <div class="toolbar">
        <span style="color:var(--ink-500);font-size:14px;"><?= count($accFlat) ?> حساب</span>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <form method="post" enctype="multipart/form-data" style="display:flex;gap:6px;align-items:center;">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="import_categories">
                <input type="file" name="cats_file" accept=".csv,text/csv" required
                       style="font-size:13px;max-width:200px;">
                <button type="submit" class="btn btn-ghost btn-sm"><i class="ti ti-upload"></i> استيراد من ملف</button>
            </form>
            <button class="btn" onclick="openCat()"><i class="ti ti-plus"></i> إضافة حساب</button>
        </div>
    </div>
    <p style="color:var(--ink-500);font-size:12px;margin:-6px 0 14px;">الملف CSV، والعمود الأول فيه أسماء التصنيفات (سطر لكل تصنيف). المكرر والموجود بيتخطّى تلقائيًا.</p>
    <div class="card">
        <?php if (empty($categories)): ?>
            <div class="empty">لا توجد حسابات بعد.</div>
        <?php else: ?>
        <table>
            <thead><tr><th>الاسم</th><th>النوع</th><th>العهدة</th><th>الحالة</th><th>إجراءات</th></tr></thead>
            <tbody>
            <?php
            $accTypeLabels = ['asset'=>'أصل','liability'=>'التزام','revenue'=>'إيراد','expense'=>'مصروف'];
            foreach ($accFlat as $c): ?>
                <tr>
                    <td style="font-weight:<?= (int)$c['depth']===0 ? '500' : '400' ?>;padding-right:<?= 12 + (int)$c['depth']*22 ?>px;">
                        <?php if ((int)$c['depth'] > 0): ?><span style="color:var(--ink-300);">└ </span><?php endif; ?>
                        <?= e($c['name']) ?>
                        <?php if (!empty($c['dynamic_src'])): $n = $accAutoCount[(int)$c['id']] ?? 0; ?>
                            <span style="font-size:11px;color:var(--ink-400);">
                                (<?= $c['dynamic_src']==='employees'?'الموظفين':'الموردين' ?><?= $n ? ' — ' . $n . ' حساب' : '' ?>)
                            </span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;color:var(--ink-500);"><?= e($accTypeLabels[$c['type']] ?? $c['type']) ?></td>
                    <td><?= !empty($c['is_custody']) ? '<span class="tag-on">عهدة</span>' : '<span style="color:var(--ink-300);">—</span>' ?></td>
                    <td><span class="badge <?= $c['is_active']?'active':'inactive' ?>"><?= $c['is_active']?'نشط':'موقوف' ?></span></td>
                    <td>
                        <form method="post" style="display:inline;" onsubmit="return confirm('تأكيد؟')">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="toggle_category">
                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                            <button class="btn btn-sm btn-ghost"><?= $c['is_active']?'إيقاف':'تفعيل' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div class="modal-bg" id="catModal">
        <div class="modal">
            <h3>إضافة حساب</h3>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="add_category">
                <div class="form-row"><label>اسم الحساب *</label><input type="text" name="name" required aria-label="اسم الحساب"></div>
                <div class="form-row">
                    <label>الحساب الأب</label>
                    <select name="parent_id">
                        <option value="">— حساب رئيسي —</option>
                        <?php foreach ($accParents as $ap): ?>
                            <option value="<?= (int)$ap['id'] ?>">
                                <?= str_repeat('— ', (int)$ap['depth']) ?><?= e($ap['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <label>النوع</label>
                    <select name="acc_type">
                        <option value="expense">مصروف</option>
                        <option value="revenue">إيراد</option>
                        <option value="asset">أصل</option>
                        <option value="liability">التزام</option>
                    </select>
                </div>
                <div class="form-row" style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="is_custody" id="acc_cust" value="1">
                    <label for="acc_cust" style="margin:0;">يظهر في شاشة العهدة</label>
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn">حفظ</button>
                    <button type="button" class="btn btn-cancel" onclick="closeCat()">إلغاء</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<script>
    function show(id){ document.getElementById(id).classList.add('open'); }
    function hide(id){ document.getElementById(id).classList.remove('open'); }

    // ===== الفروع + الخريطة =====
    var bMap = null, bMarker = null, bCircle = null;
    const DEFAULT_CENTER = [30.0444, 31.2357]; // القاهرة

    function openBranch(d){
        document.getElementById('branchTitle').textContent = d ? 'تعديل فرع' : 'إضافة فرع';
        document.getElementById('branchAction').value = d ? 'edit_branch' : 'add_branch';
        document.getElementById('branchId').value = d ? d.id : '';
        document.getElementById('b_name').value = d ? d.name : '';
        document.getElementById('b_address').value = d && d.address ? d.address : '';
        document.getElementById('b_phone').value = d && d.phone ? d.phone : '';
        const stoSel = document.getElementById('b_sto');
        if (stoSel) stoSel.value = (d && d.sto_id != null) ? String(d.sto_id) : '';
        // الجديد بياخد الاتنين مفعّلين افتراضيًا
        document.getElementById('b_recv').checked = d ? (d.in_receiving == 1) : true;
        document.getElementById('b_fin').checked  = d ? (d.has_finance  == 1) : true;

        // الموقع/النطاق
        const en  = d && (d.geofence_enabled==1 || d.geofence_enabled=='1');
        const lat = d && d.latitude  != null && d.latitude  !== '' ? parseFloat(d.latitude)  : null;
        const lng = d && d.longitude != null && d.longitude !== '' ? parseFloat(d.longitude) : null;
        const rad = d && d.geofence_radius ? parseInt(d.geofence_radius) : 50;

        document.getElementById('b_geoEnabled').checked = !!en;
        document.getElementById('b_lat').value = lat != null ? lat : '';
        document.getElementById('b_lng').value = lng != null ? lng : '';
        document.getElementById('b_radius').value = rad;

        show('branchModal');
        toggleGeo(lat, lng);
    }
    function closeBranch(){ hide('branchModal'); }

    function toggleGeo(initLat, initLng){
        const on = document.getElementById('b_geoEnabled').checked;
        document.getElementById('geoFields').style.display = on ? 'block' : 'none';
        if (on) {
            // ننتظر ظهور العنصر ثم نهيّئ الخريطة
            setTimeout(function(){ initMap(initLat, initLng); }, 60);
        }
    }

    function initMap(initLat, initLng){
        const savedLat = parseFloat(document.getElementById('b_lat').value);
        const savedLng = parseFloat(document.getElementById('b_lng').value);
        const hasPoint = !isNaN(savedLat) && !isNaN(savedLng);
        const center = hasPoint ? [savedLat, savedLng] : DEFAULT_CENTER;

        if (!bMap) {
            bMap = L.map('branchMap').setView(center, hasPoint ? 17 : 12);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19, attribution: '© OpenStreetMap'
            }).addTo(bMap);
            bMap.on('click', function(e){ setPoint(e.latlng.lat, e.latlng.lng); });
        } else {
            bMap.setView(center, hasPoint ? 17 : 12);
            setTimeout(function(){ bMap.invalidateSize(); }, 80);
        }
        // امسح العلامات القديمة
        if (bMarker) { bMap.removeLayer(bMarker); bMarker = null; }
        if (bCircle) { bMap.removeLayer(bCircle); bCircle = null; }
        if (hasPoint) setPoint(savedLat, savedLng);
        setTimeout(function(){ bMap.invalidateSize(); }, 120);
    }

    function setPoint(lat, lng){
        document.getElementById('b_lat').value = lat.toFixed(7);
        document.getElementById('b_lng').value = lng.toFixed(7);
        const rad = parseInt(document.getElementById('b_radius').value) || 50;
        if (bMarker) bMarker.setLatLng([lat, lng]);
        else bMarker = L.marker([lat, lng]).addTo(bMap);
        if (bCircle) { bCircle.setLatLng([lat, lng]); bCircle.setRadius(rad); }
        else bCircle = L.circle([lat, lng], { radius: rad, color:'var(--accent)', fillColor:'var(--accent)', fillOpacity:0.12 }).addTo(bMap);
    }

    function updateCircle(){
        const lat = parseFloat(document.getElementById('b_lat').value);
        const lng = parseFloat(document.getElementById('b_lng').value);
        const rad = parseInt(document.getElementById('b_radius').value) || 50;
        if (bCircle && !isNaN(lat) && !isNaN(lng)) bCircle.setRadius(rad);
    }

    function useMyLocation(){
        if (!navigator.geolocation) { alert('المتصفح لا يدعم تحديد الموقع.'); return; }
        navigator.geolocation.getCurrentPosition(
            function(pos){
                const lat = pos.coords.latitude, lng = pos.coords.longitude;
                if (bMap) bMap.setView([lat, lng], 17);
                setPoint(lat, lng);
            },
            function(){ alert('تعذّر تحديد موقعك. تأكد من السماح بالوصول للموقع.'); },
            { enableHighAccuracy: true, timeout: 10000 }
        );
    }

    // الموظفون
    function openEmp(d){
        document.getElementById('empTitle').textContent = d ? 'تعديل موظف' : 'إضافة موظف';
        document.getElementById('empAction').value = d ? 'edit_employee' : 'add_employee';
        document.getElementById('empId').value = d ? d.id : '';
        document.getElementById('e_name').value = d ? d.full_name : '';
        document.getElementById('e_branch').value = d && d.branch_id ? d.branch_id : '';
        document.getElementById('e_job').value = d && d.job_title ? d.job_title : '';
        document.getElementById('e_salary').value = d ? d.base_salary : '0';
        document.getElementById('e_phone').value = d && d.phone ? d.phone : '';
        document.getElementById('e_in').value = d && d.expected_in ? d.expected_in.substring(0,5) : '09:00';
        document.getElementById('e_out').value = d && d.expected_out ? d.expected_out.substring(0,5) : '17:00';
        document.getElementById('e_user').value = d && d.user_id ? d.user_id : '';
        show('empModal');
    }
    function closeEmp(){ hide('empModal'); }

    // التصنيفات
    function openCat(){ show('catModal'); }
    function closeCat(){ hide('catModal'); }

    // الخزائن
    function openTre(d){
        document.getElementById('treTitle').textContent = d ? 'تعديل خزينة' : 'إضافة خزينة';
        document.getElementById('treAction').value = d ? 'edit_treasury' : 'add_treasury';
        document.getElementById('treId').value = d ? d.id : '';
        document.getElementById('t_name').value = d ? d.name : '';
        // عند التعديل: نخفي النوع (لا يتغيّر)
        document.getElementById('typeRow').style.display = d ? 'none' : 'block';
        if (!d) document.getElementById('t_type').value = 'cash';  // الفورم بيفضل محتفظ بآخر اختيار
        treTypeHint();
        show('treModal');
    }
    function closeTre(){ hide('treModal'); }

    /* تنبيه بيبان لما تختار "رئيسية" — عشان الفرق يبقى واضح ساعة الاختيار
       مش بعد ما الفلوس تروح مكان غلط. */
    function treTypeHint(){
        var sel = document.getElementById('t_type');
        document.getElementById('treHint').style.display =
            (sel && sel.value === 'main') ? 'block' : 'none';
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
