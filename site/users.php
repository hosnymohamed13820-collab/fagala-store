<?php
/**
 * users.php — إدارة المستخدمين والصلاحيات (للأونر فقط)
 * نظام الفجالة ستور المالي
 *
 * - عرض كل المستخدمين والصلاحيات اللي كل واحد واخدها.
 * - إضافة مستخدم جديد مع اختيار صلاحياته بالـ checkboxes.
 * - تعديل بيانات وصلاحيات مستخدم موجود.
 * - تفعيل / إيقاف مستخدم.
 */
require_once __DIR__ . '/auth.php';
require_login();

// ===== الأونر فقط =====
if (!is_owner()) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;text-align:center;padding:60px;direction:rtl">'
        . '<h2>🚫 غير مصرّح</h2><p>هذه الصفحة متاحة للمالك فقط.</p>'
        . '<a href="logout.php">تسجيل الخروج</a></div>');
}

$pdo      = db();
$modules  = all_modules();
$flash    = '';
$flashType = 'success';

// ===== معالجة العمليات =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $flash = 'انتهت صلاحية الجلسة، حاول مرة أخرى.';
        $flashType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        // --- إضافة مستخدم ---
        if ($action === 'add') {
            $username = trim($_POST['username'] ?? '');
            $fullName = trim($_POST['full_name'] ?? '');
            $password = $_POST['password'] ?? '';
            $roleId   = (int) ($_POST['role_id'] ?? 0);
            $branchId = ($_POST['branch_id'] ?? '') !== '' ? (int) $_POST['branch_id'] : null;
            $perms    = $_POST['permissions'] ?? [];
            // بيانات الموظف
            $isEmp    = !empty($_POST['is_employee']);
            $salary   = (float) ($_POST['base_salary'] ?? 0);
            $jobTitle = trim($_POST['job_title'] ?? '');
            $phone    = trim($_POST['phone'] ?? '');
            $expIn    = trim($_POST['expected_in'] ?? '') ?: null;
            $expOut   = trim($_POST['expected_out'] ?? '') ?: null;
            $allowAny = !empty($_POST['allow_anywhere']) ? 1 : 0;

            if ($username === '' || $fullName === '' || $password === '' || $roleId === 0) {
                $flash = 'من فضلك املأ كل الحقول المطلوبة.';
                $flashType = 'error';
            } else {
                $chk = $pdo->prepare('SELECT id FROM users WHERE username = ?');
                $chk->execute([$username]);
                if ($chk->fetchColumn()) {
                    $flash = 'اسم المستخدم موجود بالفعل، اختر اسماً آخر.';
                    $flashType = 'error';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $pdo->prepare(
                        'INSERT INTO users (username, full_name, password_hash, role_id, branch_id, is_active)
                         VALUES (?, ?, ?, ?, ?, 1)'
                    )->execute([$username, $fullName, $hash, $roleId, $branchId]);
                    $newId = (int) $pdo->lastInsertId();

                    $insP = $pdo->prepare('INSERT IGNORE INTO user_permissions (user_id, module_key) VALUES (?, ?)');
                    foreach ($perms as $mk) {
                        if (isset($modules[$mk])) $insP->execute([$newId, $mk]);
                    }
                    // إنشاء سجل موظف مربوط (لو متعلّم كموظف)
                    if ($isEmp) {
                        $pdo->prepare(
                            'INSERT INTO employees (full_name, branch_id, user_id, phone, job_title, base_salary, expected_in, expected_out, allow_anywhere, is_active)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
                        )->execute([$fullName, $branchId, $newId, $phone ?: null, $jobTitle ?: null, $salary, $expIn, $expOut, $allowAny]);
                    }
                    $flash = 'تم إضافة المستخدم بنجاح.';
                }
            }
        }

        // --- تعديل مستخدم ---
        elseif ($action === 'edit') {
            $userId   = (int) ($_POST['user_id'] ?? 0);
            $fullName = trim($_POST['full_name'] ?? '');
            $password = $_POST['password'] ?? '';
            $roleId   = (int) ($_POST['role_id'] ?? 0);
            $branchId = ($_POST['branch_id'] ?? '') !== '' ? (int) $_POST['branch_id'] : null;
            $perms    = $_POST['permissions'] ?? [];
            $isEmp    = !empty($_POST['is_employee']);
            $salary   = (float) ($_POST['base_salary'] ?? 0);
            $jobTitle = trim($_POST['job_title'] ?? '');
            $phone    = trim($_POST['phone'] ?? '');
            $expIn    = trim($_POST['expected_in'] ?? '') ?: null;
            $expOut   = trim($_POST['expected_out'] ?? '') ?: null;
            $allowAny = !empty($_POST['allow_anywhere']) ? 1 : 0;

            if ($userId > 0 && $fullName !== '' && $roleId > 0) {
                $pdo->beginTransaction();
                try {
                    if ($password !== '') {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $pdo->prepare(
                            'UPDATE users SET full_name = ?, role_id = ?, branch_id = ?, password_hash = ?, perms_version = perms_version + 1 WHERE id = ?'
                        )->execute([$fullName, $roleId, $branchId, $hash, $userId]);
                    } else {
                        $pdo->prepare(
                            'UPDATE users SET full_name = ?, role_id = ?, branch_id = ?, perms_version = perms_version + 1 WHERE id = ?'
                        )->execute([$fullName, $roleId, $branchId, $userId]);
                    }

                    $pdo->prepare('DELETE FROM user_permissions WHERE user_id = ?')->execute([$userId]);
                    $insP = $pdo->prepare('INSERT IGNORE INTO user_permissions (user_id, module_key) VALUES (?, ?)');
                    foreach ($perms as $mk) {
                        if (isset($modules[$mk])) $insP->execute([$userId, $mk]);
                    }

                    // upsert سجل الموظف المربوط
                    $empChk = $pdo->prepare('SELECT id FROM employees WHERE user_id = ?');
                    $empChk->execute([$userId]);
                    $empId = $empChk->fetchColumn();
                    if ($isEmp) {
                        if ($empId) {
                            $pdo->prepare(
                                'UPDATE employees SET full_name=?, branch_id=?, phone=?, job_title=?, base_salary=?, expected_in=?, expected_out=?, allow_anywhere=?, is_active=1 WHERE id=?'
                            )->execute([$fullName, $branchId, $phone ?: null, $jobTitle ?: null, $salary, $expIn, $expOut, $allowAny, $empId]);
                        } else {
                            $pdo->prepare(
                                'INSERT INTO employees (full_name, branch_id, user_id, phone, job_title, base_salary, expected_in, expected_out, allow_anywhere, is_active)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
                            )->execute([$fullName, $branchId, $userId, $phone ?: null, $jobTitle ?: null, $salary, $expIn, $expOut, $allowAny]);
                        }
                    } elseif ($empId) {
                        // اتشال عنه صفة الموظف → عطّل سجل الموظف
                        $pdo->prepare('UPDATE employees SET is_active=0 WHERE id=?')->execute([$empId]);
                    }

                    $pdo->commit();
                    $flash = 'تم تعديل المستخدم بنجاح.';
                } catch (Throwable $ex) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $flash = 'حدث خطأ أثناء حفظ التعديلات: ' . $ex->getMessage();
                    $flashType = 'error';
                }
            } else {
                $flash = 'بيانات غير مكتملة.';
                $flashType = 'error';
            }
        }

        // --- تفعيل / إيقاف ---
        elseif ($action === 'toggle') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            // منع الأونر من إيقاف نفسه
            if ($userId > 0 && $userId !== (int) current_user()['id']) {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('UPDATE users SET is_active = 1 - is_active, perms_version = perms_version + 1 WHERE id = ?')->execute([$userId]);
                    $pdo->commit();
                    $flash = 'تم تحديث حالة المستخدم.';
                } catch (Throwable $ex) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $flash = 'حدث خطأ أثناء تحديث حالة المستخدم.';
                    $flashType = 'error';
                }
            } else {
                $flash = 'لا يمكنك إيقاف حسابك الخاص.';
                $flashType = 'error';
            }
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

// ===== جلب البيانات للعرض =====
$users = $pdo->query(
    'SELECT u.id, u.username, u.full_name, u.is_active, u.last_login, u.role_id, u.branch_id,
            r.name_ar AS role_name, r.is_owner, b.name AS branch_name,
            e.id AS emp_id, e.base_salary, e.job_title, e.phone, e.expected_in, e.expected_out, e.allow_anywhere
     FROM users u
     JOIN roles r ON r.id = u.role_id
     LEFT JOIN branches b ON b.id = u.branch_id
     LEFT JOIN employees e ON e.user_id = u.id AND e.is_active = 1
     ORDER BY u.id'
)->fetchAll();

// صلاحيات كل مستخدم (للعرض)
$permsByUser = [];
foreach ($pdo->query('SELECT user_id, module_key FROM user_permissions') as $row) {
    $permsByUser[$row['user_id']][] = $row['module_key'];
}

$roles    = $pdo->query('SELECT id, name_ar, is_owner FROM roles ORDER BY id')->fetchAll();
$branches = $pdo->query('SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name')->fetchAll();

$page_title    = 'إدارة المستخدمين';
$active_module = 'settings';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .flash { padding:12px 16px; border-radius:10px; margin-bottom:18px; font-size:14px; }
    .flash.success { background:var(--pos-soft); color:var(--pos); }
    .flash.error   { background:var(--neg-soft); color:var(--neg); }
    .toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; }
    .btn {
        background:var(--accent); color:#fff; border:none; padding:11px 20px;
        border-radius:10px; font-size:14px; font-family:inherit; cursor:pointer;
        text-decoration:none; display:inline-flex; align-items:center; gap:7px;
    }
    .btn:hover { background:var(--accent-hover); }
    .btn-sm { padding:7px 13px; font-size:13px; border-radius:8px; }
    .btn-ghost { background:var(--bg); color:var(--accent-hover); }
    .btn-ghost:hover { background:var(--line); }
    table { width:100%; border-collapse:collapse; }
    th, td { padding:13px 14px; text-align:right; font-size:14px; border-bottom:1px solid var(--line); }
    th { color:var(--ink-500); font-weight:600; font-size:13px; }
    td { color:var(--ink-800); }
    .badge {
        display:inline-block; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:500;
    }
    .badge.active { background:var(--pos-soft); color:var(--pos); }
    .badge.inactive { background:var(--line-soft); color:#999; }
    .badge.owner { background:#ede9ff; color:var(--accent-hover); }
    .perm-chips { display:flex; flex-wrap:wrap; gap:4px; }
    .perm-chip { background:var(--bg); color:var(--accent-hover); padding:2px 8px; border-radius:6px; font-size:11px; }
    .perm-all { color:var(--accent); font-weight:600; font-size:12px; }

    /* ===== Modal ===== */
    .modal-bg {
        display:none; position:fixed; inset:0; background:rgba(30,27,75,0.45);
        z-index:200; align-items:flex-start; justify-content:center; padding:30px 16px; overflow-y:auto;
    }
    .modal-bg.open { display:flex; }
    .modal {
        background:#fff; border-radius:16px; width:100%; max-width:560px; padding:26px;
    }
    .modal h3 { color:var(--ink-900); margin-bottom:18px; font-size:18px; }
    .form-row { margin-bottom:15px; }
    .form-row label { display:block; font-size:13px; color:var(--ink-700); margin-bottom:6px; font-weight:500; }
    .form-row input, .form-row select {
        width:100%; padding:10px 12px; border:1.5px solid var(--line); border-radius:9px;
        font-size:14px; font-family:inherit; background:var(--line-soft);
    }
    .form-row input:focus, .form-row select:focus { outline:none; border-color:var(--accent); background:#fff; }
    .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    .perms-box {
        border:1.5px solid var(--line); border-radius:10px; padding:14px;
        display:grid; grid-template-columns:1fr 1fr; gap:9px; max-height:230px; overflow-y:auto;
    }
    .perm-item { display:flex; align-items:center; gap:8px; font-size:14px; color:var(--ink-800); cursor:pointer; }
    .perm-item input { width:auto; }
    .modal-actions { display:flex; gap:10px; justify-content:flex-start; margin-top:22px; }
    .btn-cancel { background:var(--line-soft); color:#666; }
    .btn-cancel:hover { background:#e8e5f3; }
    .hint { font-size:12px; color:var(--ink-400); margin-top:4px; }
    @media (max-width:600px){ .form-grid, .perms-box { grid-template-columns:1fr; } }
</style>

<?php if ($flash): ?>
    <div class="flash <?= e($flashType) ?>"><?= e($flash) ?></div>
<?php endif; ?>

<div class="toolbar">
    <span style="color:var(--ink-500);font-size:14px;"><?= count($users) ?> مستخدم</span>
    <div style="display:flex;gap:8px;">
        <?php if (is_owner()): ?>
            <a href="import_users.php" class="btn btn-ghost" style="text-decoration:none;"><i class="ti ti-download"></i> استيراد من النظام</a>
        <?php endif; ?>
        <button class="btn" onclick="openAdd()"><i class="ti ti-plus"></i> إضافة مستخدم</button>
    </div>
</div>

<div class="card">
    <table>
        <thead>
            <tr>
                <th>الاسم</th>
                <th>اسم الدخول</th>
                <th>الدور</th>
                <th>الفرع</th>
                <th>الصلاحيات</th>
                <th>الحالة</th>
                <th>إجراءات</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $usr): ?>
                <?php $uperms = $permsByUser[$usr['id']] ?? []; ?>
                <tr>
                    <td style="font-weight:500;"><?= e($usr['full_name']) ?></td>
                    <td><?= e($usr['username']) ?></td>
                    <td>
                        <?php if ($usr['is_owner']): ?>
                            <span class="badge owner"><?= e($usr['role_name']) ?></span>
                        <?php else: ?>
                            <?= e($usr['role_name']) ?>
                        <?php endif; ?>
                    </td>
                    <td><?= e($usr['branch_name'] ?? '—') ?></td>
                    <td>
                        <?php if ($usr['is_owner']): ?>
                            <span class="perm-all">كل الصلاحيات</span>
                        <?php elseif (empty($uperms)): ?>
                            <span style="color:#bbb;font-size:12px;">لا يوجد</span>
                        <?php else: ?>
                            <div class="perm-chips">
                                <?php foreach ($uperms as $mk): ?>
                                    <span class="perm-chip"><?= e($modules[$mk]['label'] ?? $mk) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?= $usr['is_active'] ? 'active' : 'inactive' ?>">
                            <?= $usr['is_active'] ? 'نشط' : 'موقوف' ?>
                        </span>
                    </td>
                    <td>
                        <?php if (!$usr['is_owner']): ?>
                            <button class="btn btn-sm btn-ghost"
                                onclick='openEdit(<?= json_encode([
                                    "id" => $usr["id"],
                                    "full_name" => $usr["full_name"],
                                    "username" => $usr["username"],
                                    "role_id" => (int)$usr["role_id"],
                                    "branch_id" => $usr["branch_id"] !== null ? (int)$usr["branch_id"] : "",
                                    "perms" => $uperms,
                                    "is_employee" => $usr["emp_id"] ? 1 : 0,
                                    "base_salary" => $usr["base_salary"] !== null ? (float)$usr["base_salary"] : "",
                                    "job_title" => $usr["job_title"] ?? "",
                                    "phone" => $usr["phone"] ?? "",
                                    "expected_in" => $usr["expected_in"] ?? "",
                                    "expected_out" => $usr["expected_out"] ?? "",
                                    "allow_anywhere" => (int)($usr["allow_anywhere"] ?? 0),
                                ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                تعديل
                            </button>
                            <form method="post" style="display:inline;"
                                  onsubmit="return confirm('تأكيد تغيير حالة المستخدم؟')">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="user_id" value="<?= (int)$usr['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-ghost">
                                    <?= $usr['is_active'] ? 'إيقاف' : 'تفعيل' ?>
                                </button>
                            </form>
                        <?php else: ?>
                            <span style="color:#ccc;font-size:13px;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ===== Modal: إضافة / تعديل ===== -->
<div class="modal-bg" id="modal">
    <div class="modal">
        <h3 id="modalTitle">إضافة مستخدم</h3>
        <form method="post" id="userForm">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="user_id" id="formUserId" value="">

            <div class="form-grid">
                <div class="form-row">
                    <label for="f_fullname">الاسم الكامل *</label>
                    <input type="text" name="full_name" id="f_fullname" required>
                </div>
                <div class="form-row">
                    <label for="f_username">اسم الدخول *</label>
                    <input type="text" name="username" id="f_username" required autocomplete="off">
                    <div class="hint" id="usernameHint" style="display:none;">لا يمكن تغيير اسم الدخول</div>
                </div>
            </div>

            <div class="form-grid">
                <div class="form-row">
                    <label for="f_password">الباسورد <span id="passLabel">*</span></label>
                    <input type="password" name="password" id="f_password" autocomplete="new-password">
                    <div class="hint" id="passHint" style="display:none;">اتركه فارغاً لعدم التغيير</div>
                </div>
                <div class="form-row">
                    <label for="f_salary">الدور *</label>
                    <select name="role_id" id="f_role" required>
                        <option value="">— اختر —</option>
                        <?php foreach ($roles as $r): ?>
                            <?php if (!$r['is_owner']): // الأونر مش بيتعمل من هنا ?>
                                <option value="<?= (int)$r['id'] ?>"><?= e($r['name_ar']) ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <label>الفرع</label>
                <select name="branch_id" id="f_branch">
                    <option value="">— بدون فرع —</option>
                    <?php foreach ($branches as $b): ?>
                        <option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="hint">مهم للكاشير المسؤول عن عهدة فرع.</div>
            </div>

            <!-- بيانات الموظف (اليوزر = موظف) -->
            <div class="form-row" style="background:var(--line-soft);padding:12px;border-radius:10px;border:1.5px solid var(--line);">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="is_employee" id="f_isemp" value="1" onchange="toggleEmp()" style="width:auto;">
                    <span>ده موظف كمان (مرتب + حضور)</span>
                </label>
                <div id="empFields" style="display:none;margin-top:12px;">
                    <div class="form-grid">
                        <div class="form-row">
                            <label>المرتب الأساسي</label>
                            <input type="number" step="0.01" min="0" name="base_salary" id="f_salary" value="0">
                        </div>
                        <div class="form-row">
                            <label for="f_job">الوظيفة</label>
                            <input type="text" name="job_title" id="f_job" placeholder="كاشير، محاسب...">
                        </div>
                    </div>
                    <div class="form-grid">
                        <div class="form-row">
                            <label for="f_phone">التليفون</label>
                            <input type="text" name="phone" id="f_phone">
                        </div>
                        <div class="form-row">
                            <label>ميعاد الحضور</label>
                            <input type="time" name="expected_in" id="f_in">
                        </div>
                    </div>
                    <div class="form-row">
                        <label>ميعاد الانصراف</label>
                        <input type="time" name="expected_out" id="f_out">
                    </div>
                    <div class="form-row" style="margin-top:6px;">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:500;">
                            <input type="checkbox" name="allow_anywhere" id="f_anywhere" value="1" style="width:auto;">
                            يسجّل الحضور من أي مكان (موظف متنقّل — بدون التقيّد بنطاق الفرع)
                        </label>
                        <p style="font-size:12px;color:var(--ink-500);margin-top:4px;">فعّلها للموظف اللي بيتنقّل بين أماكن مختلفة. مكان بصمته هيتسجّل عادي لكن من غير رفض/تنبيه.</p>
                    </div>
                </div>
            </div>

            <div class="form-row">
                <label>الصلاحيات — علّم على اللي عايز تديهاله</label>
                <div class="perms-box">
                    <?php foreach ($modules as $key => $mod): ?>
                        <label class="perm-item">
                            <input type="checkbox" name="permissions[]" value="<?= e($key) ?>"
                                   class="perm-check">
                            <?= e($mod['label']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="modal-actions">
                <button type="submit" class="btn">حفظ</button>
                <button type="button" class="btn btn-cancel" onclick="closeModal()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<script>
    const modal = document.getElementById('modal');

    function openAdd() {
        document.getElementById('modalTitle').textContent = 'إضافة مستخدم';
        document.getElementById('formAction').value = 'add';
        document.getElementById('formUserId').value = '';
        document.getElementById('userForm').reset();
        clearPerms();
        // إظهار/إخفاء التلميحات
        document.getElementById('f_username').readOnly = false;
        document.getElementById('usernameHint').style.display = 'none';
        document.getElementById('passLabel').textContent = '*';
        document.getElementById('passHint').style.display = 'none';
        document.getElementById('f_password').required = true;
        modal.classList.add('open');
    }

    function openEdit(data) {
        document.getElementById('modalTitle').textContent = 'تعديل مستخدم';
        document.getElementById('formAction').value = 'edit';
        document.getElementById('formUserId').value = data.id;
        document.getElementById('f_fullname').value = data.full_name;
        document.getElementById('f_username').value = data.username;
        document.getElementById('f_username').readOnly = true;
        document.getElementById('usernameHint').style.display = 'block';
        document.getElementById('f_password').value = '';
        document.getElementById('f_password').required = false;
        document.getElementById('passLabel').textContent = '';
        document.getElementById('passHint').style.display = 'block';
        // الدور والفرع (نقطة 6: ما يضيعوش)
        document.getElementById('f_role').value = data.role_id || '';
        document.getElementById('f_branch').value = (data.branch_id === '' || data.branch_id == null) ? '' : data.branch_id;
        // بيانات الموظف
        document.getElementById('f_isemp').checked = !!data.is_employee;
        document.getElementById('f_salary').value = data.base_salary !== '' ? data.base_salary : 0;
        document.getElementById('f_job').value = data.job_title || '';
        document.getElementById('f_phone').value = data.phone || '';
        document.getElementById('f_in').value = data.expected_in || '';
        document.getElementById('f_out').value = data.expected_out || '';
        document.getElementById('f_anywhere').checked = !!data.allow_anywhere;
        toggleEmp();
        // تعليم الصلاحيات
        clearPerms();
        document.querySelectorAll('.perm-check').forEach(cb => {
            cb.checked = data.perms.includes(cb.value);
        });
        modal.classList.add('open');
    }

    function toggleEmp() {
        const on = document.getElementById('f_isemp').checked;
        document.getElementById('empFields').style.display = on ? 'block' : 'none';
    }

    function clearPerms() {
        document.querySelectorAll('.perm-check').forEach(cb => cb.checked = false);
    }

    function closeModal() {
        modal.classList.remove('open');
    }

    modal.addEventListener('click', e => {
        if (e.target === modal) closeModal();
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
