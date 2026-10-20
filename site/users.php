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
        . '<h2>غير مصرّح</h2><p>هذه الصفحة متاحة للمالك فقط.</p>'
        . '<a href="logout.php">تسجيل الخروج</a></div>');
}

require_once __DIR__ . '/sensitive_lib.php';

$pdo         = db();
$modules     = all_modules();
$moduleParts = module_parts();
$flash       = '';
$flashType   = 'success';

/**
 * تنقية الصلاحيات والتحقق منها وتطبيق قاعدة «كامل ولا أجزاء» في السيرفر.
 *
 * القواعد:
 * 1. المفاتيح المقبولة:
 *    - مديول بسيط موجود في all_modules().
 *    - مفتاح مركب module:part حيث module في all_modules() و part في module_parts()[$module].
 *    أي مفتاح تاني يُرفض ويتجاهل بصمت.
 * 2. قاعدة «كامل ولا أجزاء»:
 *    - لو تحدد المديول كامل ← يتخزن المديول بس (مثل 'hr') وما يتخزنش أي 'hr:*'.
 *    - لو تحدد أجزاء بس ← يتخزن 'hr:part' لكل جزء محدد وما يتخزنش 'hr'.
 *    - لو مفيش حاجة تحددت للمديول ← ما يتخزنش أي مفتاح ليه.
 *
 * @param array $rawPerms
 * @return array<string>
 */
function sanitize_user_permissions(array $rawPerms): array
{
    $allMods   = all_modules();
    $modParts  = module_parts();

    $selectedFull  = []; // المديولات الكاملة
    $selectedParts = []; // الأجزاء المحددة: [mod => [partKey => true]]
    $simpleMods    = []; // مديولات عادية مالهاش أجزاء

    foreach ($rawPerms as $rawKey) {
        if (!is_string($rawKey)) continue;
        $rawKey = trim($rawKey);
        if ($rawKey === '') continue;

        if (str_contains($rawKey, ':')) {
            $pieces = explode(':', $rawKey, 2);
            if (count($pieces) === 2) {
                [$mod, $part] = $pieces;
                // شرط صارم: المديول موجود في all_modules والجزء معرّف في module_parts
                if (isset($allMods[$mod]) && isset($modParts[$mod][$part])) {
                    $selectedParts[$mod][$part] = true;
                }
            }
        } else {
            if (isset($allMods[$rawKey])) {
                if (isset($modParts[$rawKey])) {
                    // مديول ليه أجزاء بس اتحدد كـ «كامل»
                    $selectedFull[$rawKey] = true;
                } else {
                    // مديول عادي مالوش أجزاء
                    $simpleMods[$rawKey] = true;
                }
            }
        }
    }

    $cleanPerms = [];

    // 1. المديولات العادية التي ليس لها أجزاء
    foreach (array_keys($simpleMods) as $mk) {
        $cleanPerms[] = $mk;
    }

    // 2. المديولات التي لها أجزاء: تطبيق قاعدة «كامل ولا أجزاء»
    foreach ($modParts as $modKey => $parts) {
        if (!empty($selectedFull[$modKey])) {
            // تحدد كامل ← نخزن المديول فقط ونلغي أي أجزاء تابعة
            $cleanPerms[] = $modKey;
        } elseif (!empty($selectedParts[$modKey])) {
            // تحدد أجزاء فقط ← نخزن كل جزء صالح
            foreach (array_keys($parts) as $partKey) {
                if (!empty($selectedParts[$modKey][$partKey])) {
                    $cleanPerms[] = $modKey . ':' . $partKey;
                }
            }
        }
    }

    return $cleanPerms;
}

/**
 * تجهيز شرائح الصلاحيات لجدول المستخدمين.
 *
 * @param array $uperms قائمة مفاتيح الصلاحيات المخزنة للمستخدم
 * @param array $modules كل المديولات
 * @param array $moduleParts أجزاء المديولات
 * @return array<array{text: string, title: string}>
 */
function format_user_perm_chips(array $uperms, array $modules, array $moduleParts): array
{
    $chips = [];
    $groupedParts = []; // [mod => [partKey => partLabel]]
    $fullMods = [];
    $otherKeys = [];

    foreach ($uperms as $mk) {
        if (str_contains($mk, ':')) {
            [$mod, $part] = explode(':', $mk, 2);
            if (isset($moduleParts[$mod][$part])) {
                $groupedParts[$mod][$part] = $moduleParts[$mod][$part];
            } else {
                $modLabel = $modules[$mod]['label'] ?? $mod;
                $groupedParts[$mod][$part] = $part;
            }
        } else {
            if (isset($modules[$mk])) {
                $fullMods[$mk] = $modules[$mk]['label'];
            } else {
                $otherKeys[] = $mk;
            }
        }
    }

    // 1. المديولات الكاملة
    foreach ($fullMods as $mk => $label) {
        $chips[] = [
            'text'  => $label,
            'title' => '',
        ];
    }

    // 2. المديولات ذات الأجزاء
    foreach ($groupedParts as $mod => $parts) {
        // حماية: لو المديول الكامل موجود ما نكررش الأجزاء
        if (isset($fullMods[$mod])) {
            continue;
        }
        $modLabel = $modules[$mod]['label'] ?? $mod;
        $partLabels = array_values($parts);
        $count = count($partLabels);

        if ($count <= 3) {
            $chips[] = [
                'text'  => $modLabel . ': ' . implode(' · ', $partLabels),
                'title' => '',
            ];
        } else {
            $visible = array_slice($partLabels, 0, 3);
            $remaining = $count - 3;
            $chips[] = [
                'text'  => $modLabel . ': ' . implode(' · ', $visible) . ' +' . $remaining,
                'title' => $modLabel . ': ' . implode(' · ', $partLabels),
            ];
        }
    }

    // 3. أي مفتاح آخر
    foreach ($otherKeys as $k) {
        $chips[] = [
            'text'  => $k,
            'title' => '',
        ];
    }

    return $chips;
}

/**
 * توليد HTML منتقي الصلاحيات (مشترك بين مودال الموظف ومودال الدور — بريف ١٠٧).
 */
function perm_picker_html(string $idPrefix): string
{
    $modules     = all_modules();
    $moduleParts = module_parts();

    ob_start();
    ?>
    <div class="perms-box">
        <?php foreach ($modules as $key => $mod): ?>
            <?php if (isset($moduleParts[$key])): ?>
                <div class="perm-group">
                    <label class="perm-item" style="font-weight:600;">
                        <input type="checkbox" name="permissions[]" value="<?= e($key) ?>"
                               class="perm-check perm-mod-full" data-mod="<?= e($key) ?>"
                               onchange="onModuleFullChange(this)">
                        <span><?= e($mod['label']) ?> (كامل)</span>
                    </label>
                    <div class="perm-group-hint">«كامل» يعني كل الأجزاء دلوقتي وأي جزء يتضاف بعدين.</div>
                    <div class="perm-parts-grid">
                        <?php foreach ($moduleParts[$key] as $pKey => $pLabel): ?>
                            <label class="perm-item perm-part-item">
                                <input type="checkbox" name="permissions[]" value="<?= e($key . ':' . $pKey) ?>"
                                       class="perm-check perm-part-check" data-mod="<?= e($key) ?>"
                                       onchange="onPartChange(this)">
                                <span><?= e($pLabel) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <label class="perm-item">
                    <input type="checkbox" name="permissions[]" value="<?= e($key) ?>"
                           class="perm-check">
                    <span><?= e($mod['label']) ?></span>
                </label>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

// ===== صلاحيات الأدوار (بريف ١٠٧) =====
// صلاحيات الموظف = صلاحيات دوره ∪ صلاحياته الزيادة (user_permissions).
// لو جدول role_permissions لسه ماتعملش ← الأدوار فاضية والصفحة شغالة زي الأول.
$rolePermsReady = true;
$rolePerms      = [];   // [role_id => [module_key, ...]]
try {
    foreach ($pdo->query('SELECT role_id, module_key FROM role_permissions ORDER BY id') as $row) {
        $rolePerms[(int) $row['role_id']][] = $row['module_key'];
    }
} catch (Throwable $e) {
    $rolePermsReady = false;
}

/**
 * الصلاحيات اللي تتخزن للموظف = المتعلّم عليه ناقص اللي جاي من دوره.
 */
function user_extra_permissions(array $rawPerms, int $roleId, array $rolePerms): array
{
    return array_values(array_diff(sanitize_user_permissions($rawPerms), $rolePerms[$roleId] ?? []));
}

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
            } elseif (mb_strlen($password) < 8) {
                $flash = 'الباسورد لازم ٨ حروف على الأقل.';
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
                    $cleanPerms = user_extra_permissions((array) $perms, $roleId, $rolePerms);
                    foreach ($cleanPerms as $mk) {
                        $insP->execute([$newId, $mk]);
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
                if ($password !== '' && mb_strlen($password) < 8) {
                    $flash = 'الباسورد لازم ٨ حروف على الأقل.';
                    $flashType = 'error';
                } else {
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
                        $cleanPerms = user_extra_permissions((array) $perms, $roleId, $rolePerms);
                        foreach ($cleanPerms as $mk) {
                            $insP->execute([$userId, $mk]);
                        }

                        // upsert سجل الموظف المربوط
                        $empChk = $pdo->prepare('SELECT id FROM employees WHERE user_id = ?');
                        $empChk->execute([$userId]);
                        $empId = $empChk->fetchColumn();
                        if ($isEmp) {
                            if ($empId) {
                                if (sens_unlocked()) {
                                    $pdo->prepare(
                                        'UPDATE employees SET full_name=?, branch_id=?, phone=?, job_title=?, base_salary=?, expected_in=?, expected_out=?, allow_anywhere=?, is_active=1 WHERE id=?'
                                    )->execute([$fullName, $branchId, $phone ?: null, $jobTitle ?: null, $salary, $expIn, $expOut, $allowAny, $empId]);
                                } else {
                                    $pdo->prepare(
                                        'UPDATE employees SET full_name=?, branch_id=?, phone=?, job_title=?, expected_in=?, expected_out=?, allow_anywhere=?, is_active=1 WHERE id=?'
                                    )->execute([$fullName, $branchId, $phone ?: null, $jobTitle ?: null, $expIn, $expOut, $allowAny, $empId]);
                                }
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
                }
            } else {
                $flash = 'بيانات غير مكتملة.';
                $flashType = 'error';
            }
        }

        // --- صلاحية لمجموعة موظفين مرة واحدة (إضافة أو شيل) ---
        // محمد: «أختار الموظفين وأفتح لهم موديول معين كلهم مرة واحدة».
        // 🔴 المفتاح بيعدّي على sanitize_user_permissions زي الإضافة والتعديل —
        //    مفتاح مش موجود في all_modules/module_parts بيتصد.
        // 🔴 استعلامين بس مهما كان عدد الموظفين (INSERT متعدد + UPDATE بـIN)،
        //    و perms_version بيزيد عشان الجلسات المفتوحة تقرا الجديد من غير خروج.
        elseif ($action === 'bulk_perm') {
            $mk    = (string) ($_POST['module_key'] ?? '');
            $mode  = ($_POST['mode'] ?? '') === 'remove' ? 'remove' : 'add';
            $clean = sanitize_user_permissions([$mk]);
            $ids   = array_values(array_unique(array_filter(
                array_map('intval', (array) ($_POST['user_ids'] ?? [])),
                static fn($v) => $v > 0
            )));
            if (count($clean) !== 1 || $clean[0] !== $mk) {
                $flash = 'الصلاحية دي مش معروفة.';
                $flashType = 'error';
            } elseif (!$ids) {
                $flash = 'ماختارتش أي موظف.';
                $flashType = 'error';
            } else {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $pdo->beginTransaction();
                try {
                    if ($mode === 'add') {
                        $vals = implode(',', array_fill(0, count($ids), '(?, ?)'));
                        $args = [];
                        foreach ($ids as $id) { $args[] = $id; $args[] = $mk; }
                        $pdo->prepare("INSERT IGNORE INTO user_permissions (user_id, module_key) VALUES $vals")->execute($args);
                    } else {
                        $pdo->prepare("DELETE FROM user_permissions WHERE module_key = ? AND user_id IN ($ph)")
                            ->execute(array_merge([$mk], $ids));
                    }
                    $pdo->prepare("UPDATE users SET perms_version = perms_version + 1 WHERE id IN ($ph)")->execute($ids);
                    $pdo->commit();
                    $flash = ($mode === 'add' ? 'اتفتحت الصلاحية لـ ' : 'اتشالت الصلاحية من ') . count($ids) . ' موظف.';
                } catch (Throwable $ex) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('users bulk_perm: ' . $ex->getMessage());
                    $flash = 'حصل خطأ ومااتحفظش حاجة.';
                    $flashType = 'error';
                }
            }
        }

        // --- حفظ دور (جديد أو تعديل) — بريف ١٠٧ ---
        // 🔴 دور الأونر مايتعدّلش من هنا (الأونر أصلاً معاه كل حاجة).
        //    perms_version لكل موظفين الدور بيزيد عشان الجلسات المفتوحة تقرا الجديد.
        elseif ($action === 'save_role') {
            $roleId = (int) ($_POST['role_id'] ?? 0);
            $nameAr = trim((string) ($_POST['name_ar'] ?? ''));
            $perms  = sanitize_user_permissions((array) ($_POST['permissions'] ?? []));

            $roleRow = null;
            if ($roleId > 0) {
                $st = $pdo->prepare('SELECT id, is_owner FROM roles WHERE id = ?');
                $st->execute([$roleId]);
                $roleRow = $st->fetch();
            }

            if (!$rolePermsReady) {
                $flash = 'جدول صلاحيات الأدوار لسه ماتعملش — شغّل local/upgrade_role_perms.sql الأول.';
                $flashType = 'error';
            } elseif ($nameAr === '' || mb_strlen($nameAr) > 50) {
                $flash = 'اسم الدور مطلوب (٥٠ حرف بالكتير).';
                $flashType = 'error';
            } elseif ($roleId > 0 && !$roleRow) {
                $flash = 'الدور ده مش موجود.';
                $flashType = 'error';
            } elseif ($roleRow && (int) $roleRow['is_owner'] === 1) {
                $flash = 'دور الأونر مايتعدّلش.';
                $flashType = 'error';
            } else {
                $pdo->beginTransaction();
                try {
                    if ($roleId > 0) {
                        $pdo->prepare('UPDATE roles SET name_ar = ? WHERE id = ?')->execute([$nameAr, $roleId]);
                    } else {
                        // roles.name عليه UNIQUE — اسم مؤقت وبعدين role_<id>
                        $pdo->prepare('INSERT INTO roles (name, name_ar, is_owner) VALUES (?, ?, 0)')
                            ->execute(['tmp_' . bin2hex(random_bytes(6)), $nameAr]);
                        $roleId = (int) $pdo->lastInsertId();
                        $pdo->prepare('UPDATE roles SET name = ? WHERE id = ?')->execute(['role_' . $roleId, $roleId]);
                    }
                    $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$roleId]);
                    if ($perms) {
                        $vals = implode(',', array_fill(0, count($perms), '(?, ?)'));
                        $args = [];
                        foreach ($perms as $mk) { $args[] = $roleId; $args[] = $mk; }
                        $pdo->prepare("INSERT IGNORE INTO role_permissions (role_id, module_key) VALUES $vals")->execute($args);
                    }
                    $pdo->prepare('UPDATE users SET perms_version = perms_version + 1 WHERE role_id = ?')->execute([$roleId]);
                    $pdo->commit();
                    $flash = 'اتحفظ الدور «' . $nameAr . '».';
                } catch (Throwable $ex) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('users save_role: ' . $ex->getMessage());
                    $flash = 'حصل خطأ ومااتحفظش حاجة.';
                    $flashType = 'error';
                }
            }
        }

        // --- مسح دور — بس لو مفيش ولا موظف عليه (حتى الموقوفين) ---
        elseif ($action === 'delete_role') {
            $roleId = (int) ($_POST['role_id'] ?? 0);
            $st = $pdo->prepare(
                'SELECT r.is_owner, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS n
                 FROM roles r WHERE r.id = ?'
            );
            $st->execute([$roleId]);
            $roleRow = $st->fetch();
            if (!$roleRow) {
                $flash = 'الدور ده مش موجود.';
                $flashType = 'error';
            } elseif ((int) $roleRow['is_owner'] === 1) {
                $flash = 'دور الأونر مايتمسحش.';
                $flashType = 'error';
            } elseif ((int) $roleRow['n'] > 0) {
                $flash = 'الدور ده عليه ' . (int) $roleRow['n'] . ' موظف — انقلهم الأول.';
                $flashType = 'error';
            } else {
                $pdo->beginTransaction();
                try {
                    if ($rolePermsReady) {
                        $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$roleId]);
                    }
                    $pdo->prepare('DELETE FROM roles WHERE id = ? AND is_owner = 0')->execute([$roleId]);
                    $pdo->commit();
                    $flash = 'اتمسح الدور.';
                } catch (Throwable $ex) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('users delete_role: ' . $ex->getMessage());
                    $flash = 'حصل خطأ ومااتمسحش.';
                    $flashType = 'error';
                }
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

$roles    = $pdo->query(
    'SELECT r.id, r.name_ar, r.is_owner,
            COUNT(u.id) AS users_all, COALESCE(SUM(u.is_active), 0) AS users_active
     FROM roles r LEFT JOIN users u ON u.role_id = r.id
     GROUP BY r.id, r.name_ar, r.is_owner
     ORDER BY r.id'
)->fetchAll();
$view     = (($_GET['view'] ?? '') === 'roles') ? 'roles' : 'users';
$branches = $pdo->query('SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name')->fetchAll();

$page_title    = 'إدارة المستخدمين';
$active_module = 'settings';
require_once __DIR__ . '/sensitive_lib.php';
sens_page();
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .flash { padding:12px 16px; border-radius:10px; margin-bottom:18px; font-size:14px; }
    .flash.success { background:var(--pos-soft); color:var(--pos); }
    .flash.error   { background:var(--neg-soft); color:var(--neg); }
    .toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; }
    table { width:100%; border-collapse:collapse; }
    th, td { padding:13px 14px; text-align:right; font-size:14px; border-bottom:1px solid var(--line); }
    th { color:var(--ink-500); font-weight:600; font-size:13px; }
    td { color:var(--ink-800); }
    .badge {
        display:inline-block; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:500;
    }
    .badge.active { background:var(--pos-soft); color:var(--pos); }
    .badge.inactive { background:var(--line-soft); color:var(--ink-400); }
    .badge.owner { background:var(--line-soft); color:var(--accent-text); }
    .perm-chips { display:flex; flex-wrap:wrap; gap:4px; }
    .perm-chip { background:var(--bg); color:var(--accent-text); padding:2px 8px; border-radius:6px; font-size:11px; }
    .perm-all { color:var(--accent-text); font-weight:600; font-size:12px; }

    /* ===== Modal ===== */
    .modal-bg {
        display:none; position:fixed; inset:0; background:rgba(30,27,75,0.45);
        z-index:200; align-items:flex-start; justify-content:center; padding:30px 16px; overflow-y:auto;
    }
    .modal-bg.open { display:flex; }
    .modal {
        background:var(--surface); border-radius:16px; width:100%; max-width:560px; padding:26px;
    }
    .modal h3 { color:var(--ink-900); margin-bottom:18px; font-size:18px; }
    .form-row { margin-bottom:15px; }
    .form-row label { display:block; font-size:13px; color:var(--ink-700); margin-bottom:6px; font-weight:500; }
    .form-row input, .form-row select {
        width:100%; padding:10px 12px; border:1.5px solid var(--line); border-radius:9px;
        font-size:14px; font-family:inherit; background:var(--line-soft);
    }
    .form-row input:focus, .form-row select:focus { outline:none; border-color:var(--accent-text); background:var(--surface); }
    .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    .perms-box {
        border:1.5px solid var(--line); border-radius:10px; padding:14px;
        display:grid; grid-template-columns:1fr 1fr; gap:9px; max-height:230px; overflow-y:auto;
    }
    .perm-item { display:flex; align-items:center; gap:8px; font-size:14px; color:var(--ink-800); cursor:pointer; }
    .perm-item input { width:auto; }
    .perm-group {
        grid-column: 1 / -1;
        background: var(--line-soft);
        border: 1px solid var(--line);
        border-radius: 9px;
        padding: 10px 12px;
        margin: 2px 0;
    }
    .perm-group-hint {
        font-size: 11.5px;
        color: var(--ink-500);
        margin: 3px 22px 8px 0;
    }
    .perm-parts-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 6px 10px;
        padding-right: 22px;
    }
    .perm-part-item {
        font-size: 12.5px;
        color: var(--ink-700);
    }
    .perm-part-item input:disabled + span {
        color: var(--ink-400);
    }
    .modal-actions { display:flex; gap:10px; justify-content:flex-start; margin-top:22px; }
    .hint { font-size:12px; color:var(--ink-400); margin-top:4px; }
    @media (max-width:600px){ .form-grid, .perms-box, .perm-parts-grid { grid-template-columns:1fr; } }
    /* تابات الموظفين / الأدوار (بريف ١٠٧) — نفس شكل تابات التعريفات */
    .tabs { display:flex; gap:6px; margin-bottom:20px; flex-wrap:wrap; }
    .tab {
        padding:10px 20px; border-radius:10px; text-decoration:none;
        color:var(--accent-text); background:var(--surface); font-size:14px; font-weight:500;
        border:1.5px solid transparent;
    }
    .tab.active { background:var(--accent); color:var(--fill-strong-fg); }
    .tab:not(.active):hover { background:var(--bg); }
    .perm-chip.role { background:var(--line-soft); color:var(--ink-600); font-weight:600; }
    .role-tag { font-size:11px; color:var(--ink-500); margin-inline-start:4px; }
</style>

<?php if ($flash): ?>
    <div class="flash <?= e($flashType) ?>"><?= e($flash) ?></div>
<?php endif; ?>

<div class="tabs">
    <a href="users.php" class="tab <?= $view === 'users' ? 'active' : '' ?>">الموظفين</a>
    <a href="users.php?view=roles" class="tab <?= $view === 'roles' ? 'active' : '' ?>">الأدوار</a>
</div>

<?php if ($view === 'roles'): ?>

<?php if (!$rolePermsReady): ?>
    <div class="flash error">جدول صلاحيات الأدوار لسه ماتعملش على القاعدة — شغّل <b>local/upgrade_role_perms.sql</b> من phpMyAdmin.</div>
<?php endif; ?>

<div class="toolbar">
    <span style="color:var(--ink-500);font-size:14px;">صلاحيات الموظف = صلاحيات دوره + أي صلاحية زيادة ليه هو.</span>
    <button class="btn" onclick="openRole(null)"<?= $rolePermsReady ? '' : ' disabled' ?>><i class="ti ti-plus"></i> دور جديد</button>
</div>

<div class="card">
    <table>
        <thead>
            <tr>
                <th>الدور</th>
                <th>الموظفين</th>
                <th>صلاحيات الدور</th>
                <th>إجراءات</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($roles as $r): ?>
                <?php $rperms = $rolePerms[(int) $r['id']] ?? []; ?>
                <tr>
                    <td style="font-weight:500;"><?= e($r['name_ar']) ?></td>
                    <td><?= (int) $r['users_active'] ?><?php if ((int) $r['users_all'] > (int) $r['users_active']): ?> <span style="color:var(--ink-400);font-size:12px;">(+<?= (int) $r['users_all'] - (int) $r['users_active'] ?> موقوف)</span><?php endif; ?></td>
                    <td>
                        <?php if ($r['is_owner']): ?>
                            <span class="perm-all">كل الصلاحيات</span>
                        <?php elseif (empty($rperms)): ?>
                            <span style="color:var(--ink-400);font-size:12px;">لسه مالوش صلاحيات</span>
                        <?php else: ?>
                            <div class="perm-chips">
                                <?php foreach (format_user_perm_chips($rperms, $modules, $moduleParts) as $chip): ?>
                                    <span class="perm-chip"<?php if ($chip['title'] !== ''): ?> title="<?= e($chip['title']) ?>"<?php endif; ?>><?= e($chip['text']) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!$r['is_owner'] && $rolePermsReady): ?>
                            <button class="btn btn-sm btn-ghost"
                                onclick='openRole(<?= json_encode(['id' => (int) $r['id'], 'name_ar' => $r['name_ar'], 'perms' => $rperms], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                تعديل
                            </button>
                            <?php if ((int) $r['users_all'] === 0): ?>
                                <form method="post" style="display:inline;" data-confirm="تمسح الدور «<?= e($r['name_ar']) ?>»؟">
                                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete_role">
                                    <input type="hidden" name="role_id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-ghost">مسح</button>
                                </form>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:var(--ink-400);font-size:13px;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ===== Modal: دور ===== -->
<div class="modal-bg" id="roleModal">
    <div class="modal">
        <h3 id="roleTitle">دور جديد</h3>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_role">
            <input type="hidden" name="role_id" id="r_id" value="">
            <div class="form-row">
                <label for="r_name">اسم الدور *</label>
                <input type="text" name="name_ar" id="r_name" maxlength="50" required>
            </div>
            <div class="form-row">
                <label>صلاحيات الدور — كل موظف على الدور ده هياخدها لوحده</label>
                <?= perm_picker_html('r') ?>
                <div class="hint">التغيير بيوصل للموظفين اللي فاتحين الموقع من غير خروج ودخول.</div>
            </div>
            <div class="modal-actions">
                <button type="submit" class="btn">حفظ</button>
                <button type="button" class="btn btn-cancel" onclick="document.getElementById('roleModal').classList.remove('open')">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<?php else: ?>

<div class="toolbar">
    <span style="color:var(--ink-500);font-size:14px;"><?= count($users) ?> مستخدم</span>
    <div style="display:flex;gap:8px;">
        <?php if (is_owner()): ?>
            <a href="import_users.php" class="btn btn-ghost" style="text-decoration:none;"><i class="ti ti-download"></i> استيراد من النظام</a>
        <?php endif; ?>
        <button class="btn btn-ghost" onclick="openBulk()"><i class="ti ti-users"></i> صلاحية لمجموعة</button>
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
                        <?php $hasRolePerms = !empty($rolePerms[(int) $usr['role_id']]); ?>
                        <?php if ($usr['is_owner']): ?>
                            <span class="perm-all">كل الصلاحيات</span>
                        <?php elseif (empty($uperms) && !$hasRolePerms): ?>
                            <span style="color:var(--ink-400);font-size:12px;">لا يوجد</span>
                        <?php else: ?>
                            <?php $chips = $uperms ? format_user_perm_chips($uperms, $modules, $moduleParts) : []; ?>
                            <div class="perm-chips">
                                <?php if ($hasRolePerms): ?>
                                    <span class="perm-chip role" title="صلاحيات الدور — بتتعدّل من تاب «الأدوار»"><?= e($usr['role_name']) ?></span>
                                <?php endif; ?>
                                <?php foreach ($chips as $chip): ?>
                                    <span class="perm-chip"<?php if ($chip['title'] !== ''): ?> title="<?= e($chip['title']) ?>"<?php endif; ?>><?= e($chip['text']) ?></span>
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
                            <?php
                                $editData = [
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
                                ];
                                if (!sens_unlocked()) {
                                    unset($editData['base_salary']);
                                }
                            ?>
                            <button class="btn btn-sm btn-ghost"
                                onclick='openEdit(<?= json_encode($editData, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                تعديل
                            </button>
                            <form method="post" style="display:inline;"
                                  data-confirm="تأكيد تغيير حالة المستخدم؟">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="user_id" value="<?= (int)$usr['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-ghost">
                                    <?= $usr['is_active'] ? 'إيقاف' : 'تفعيل' ?>
                                </button>
                            </form>
                        <?php else: ?>
                            <span style="color:var(--ink-400);font-size:13px;">—</span>
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
                    <input type="password" name="password" id="f_password" minlength="8" autocomplete="new-password">
                    <div class="hint" id="passHint" style="display:none;">اتركه فارغاً لعدم التغيير</div>
                    <div class="hint">٨ حروف على الأقل</div>
                    <div id="passErr" class="hint" style="display:none;color:var(--neg);font-weight:600;"></div>
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
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                                <label style="margin-bottom:0;">المرتب الأساسي</label>
                                <?= sens_eye() ?>
                            </div>
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
                <?= perm_picker_html('u') ?>
                <div class="hint">المقفول ومكتوب جنبه «من الدور» جاي من دور الموظف — بيتعدّل من تاب «الأدوار».</div>
            </div>

            <div class="modal-actions">
                <button type="submit" class="btn">حفظ</button>
                <button type="button" class="btn btn-cancel" onclick="closeModal()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== صلاحية لمجموعة موظفين مرة واحدة ===== -->
<div class="modal-bg" id="bulkModal">
    <div class="modal">
        <h3>صلاحية لمجموعة موظفين</h3>
        <form method="post" onsubmit="return bulkConfirm()">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="bulk_perm">

            <div class="form-grid">
                <div class="form-row">
                    <label for="b_mod">الصلاحية</label>
                    <select name="module_key" id="b_mod" required>
                        <?php foreach ($modules as $key => $mod): ?>
                            <?php if (isset($moduleParts[$key])): ?>
                                <option value="<?= e($key) ?>"><?= e($mod['label']) ?> (كامل)</option>
                                <?php foreach ($moduleParts[$key] as $pKey => $pLabel): ?>
                                    <option value="<?= e($key . ':' . $pKey) ?>">&nbsp;&nbsp;↳ <?= e($mod['label']) ?>: <?= e($pLabel) ?></option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="<?= e($key) ?>"><?= e($mod['label']) ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <label>العملية</label>
                    <div style="display:flex;gap:16px;padding-top:8px;">
                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="radio" name="mode" value="add" checked style="width:auto;"> افتحها</label>
                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="radio" name="mode" value="remove" style="width:auto;"> اقفلها</label>
                    </div>
                </div>
            </div>

            <div class="form-row">
                <label>الموظفين <span id="b_count" style="color:var(--ink-500);font-weight:400;"></span></label>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
                    <select id="b_branch" onchange="bulkFilter()" style="flex:1;min-width:140px;">
                        <option value="">كل الفروع</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option>
                        <?php endforeach; ?>
                        <option value="0">— بدون فرع —</option>
                    </select>
                    <button type="button" class="btn btn-ghost" onclick="bulkAll(true)">علّم الظاهرين</button>
                    <button type="button" class="btn btn-ghost" onclick="bulkAll(false)">شيل العلامات</button>
                </div>
                <div class="perms-box" style="max-height:260px;">
                    <?php foreach ($users as $bu): if (!(int)$bu['is_active'] || (int)$bu['is_owner']) continue; ?>
                        <label class="perm-item b-user" data-branch="<?= (int)($bu['branch_id'] ?? 0) ?>">
                            <input type="checkbox" name="user_ids[]" value="<?= (int)$bu['id'] ?>" onchange="bulkCount()">
                            <?= e($bu['full_name']) ?>
                            <span style="color:var(--ink-400);font-size:12px;">· <?= e($bu['branch_name'] ?? 'بدون فرع') ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="hint">الموقوفين والأونر مش في القايمة. الصلاحية بتشتغل عندهم من غير خروج ودخول.</div>
            </div>

            <div class="modal-actions">
                <button type="submit" class="btn">طبّق</button>
                <button type="button" class="btn btn-cancel" onclick="document.getElementById('bulkModal').classList.remove('open')">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>

<script>
    function openBulk() {
        document.getElementById('bulkModal').classList.add('open');
        bulkFilter();
    }
    // فلتر الفرع بيخفي بس — العلامة على المخفي بتفضل (عشان تختار من كذا فرع)
    function bulkFilter() {
        const b = document.getElementById('b_branch').value;
        document.querySelectorAll('#bulkModal .b-user').forEach(el => {
            el.style.display = (b === '' || el.dataset.branch === b) ? '' : 'none';
        });
        bulkCount();
    }
    function bulkAll(on) {
        document.querySelectorAll('#bulkModal .b-user').forEach(el => {
            if (el.style.display !== 'none') el.querySelector('input').checked = on;
        });
        bulkCount();
    }
    function bulkCount() {
        const n = document.querySelectorAll('#bulkModal .b-user input:checked').length;
        document.getElementById('b_count').textContent = n ? '(' + n + ' متعلّم)' : '';
        return n;
    }
    function bulkConfirm() {
        const n = bulkCount();
        if (!n) { alert('علّم على موظف واحد على الأقل.'); return false; }
        const sel  = document.getElementById('b_mod');
        const name = sel.options[sel.selectedIndex].text.replace(/^\s*↳\s*/, '').trim();
        const add  = document.querySelector('#bulkModal input[name="mode"]:checked').value === 'add';
        return confirm((add ? 'تفتح «' : 'تقفل «') + name + '» ' + (add ? 'لـ ' : 'عن ') + n + ' موظف؟');
    }
</script>
<script>
    const modal = document.getElementById('modal');
    // صلاحيات كل دور (بريف ١٠٧) — مرة واحدة مع الصفحة، مش fetch مع كل تغيير
    const ROLE_PERMS = <?= json_encode((object) $rolePerms, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    // صلاحيات الموظف المفتوح (عشان لو الدور اتغيّر في المودال نرجّع اللي كان معاه هو)
    let currentUserPerms = [];

    // «كامل» متعلّم ← أجزاءه تتقفل. جوه المنتقي بتاعه بس (فيه منتقي للموظف وواحد للدور).
    function syncParts(fullCb) {
        const box = fullCb.closest('.perms-box');
        box.querySelectorAll('.perm-part-check[data-mod="' + fullCb.dataset.mod + '"]').forEach(cb => {
            if (cb.hasAttribute('data-role-lock')) return;
            if (fullCb.checked) { cb.checked = false; cb.disabled = true; }
            else { cb.disabled = false; }
        });
    }

    // صلاحيات الدور المختار تبان متعلّمة ومقفولة ومكتوب جنبها «من الدور».
    // المقفول مابيتبعتش مع الفورم، والسيرفر كمان بيطرح صلاحيات الدور قبل الحفظ.
    function applyRoleLock() {
        if (!modal) return;
        const box = modal.querySelector('.perms-box');
        const roleSel = document.getElementById('f_role');
        const rp = ROLE_PERMS[roleSel ? roleSel.value : ''] || [];
        box.querySelectorAll('.perm-check[data-role-lock]').forEach(cb => {
            cb.removeAttribute('data-role-lock');
            cb.disabled = false;
            cb.checked = currentUserPerms.includes(cb.value);
            const t = cb.parentNode.querySelector('.role-tag');
            if (t) t.remove();
        });
        box.querySelectorAll('.perm-mod-full').forEach(syncParts);
        box.querySelectorAll('.perm-check').forEach(cb => {
            const fromRole = rp.includes(cb.value)
                || (cb.classList.contains('perm-part-check') && rp.includes(cb.dataset.mod));
            if (!fromRole) return;
            cb.checked = true;
            cb.disabled = true;
            cb.setAttribute('data-role-lock', '1');
            const tag = document.createElement('span');
            tag.className = 'role-tag';
            tag.textContent = 'من الدور';
            cb.parentNode.appendChild(tag);
        });
    }

    function openRole(data) {
        const rm = document.getElementById('roleModal');
        document.getElementById('roleTitle').textContent = data ? 'تعديل الدور' : 'دور جديد';
        document.getElementById('r_id').value = data ? data.id : '';
        document.getElementById('r_name').value = data ? data.name_ar : '';
        const perms = data && Array.isArray(data.perms) ? data.perms : [];
        rm.querySelectorAll('.perm-check').forEach(cb => {
            cb.disabled = false;
            cb.checked = perms.includes(cb.value);
        });
        rm.querySelectorAll('.perm-mod-full').forEach(syncParts);
        rm.classList.add('open');
    }

    function openAdd() {
        document.getElementById('modalTitle').textContent = 'إضافة مستخدم';
        document.getElementById('formAction').value = 'add';
        document.getElementById('formUserId').value = '';
        document.getElementById('userForm').reset();
        document.getElementById('f_salary').disabled = false;
        document.getElementById('f_salary').placeholder = '';
        clearPerms();
        currentUserPerms = [];
        applyRoleLock();
        // إظهار/إخفاء التلميحات
        document.getElementById('f_username').readOnly = false;
        document.getElementById('usernameHint').style.display = 'none';
        document.getElementById('passLabel').textContent = '*';
        document.getElementById('passHint').style.display = 'none';
        document.getElementById('f_password').required = true;
        var passErr = document.getElementById('passErr');
        if (passErr) passErr.style.display = 'none';
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
        var passErr = document.getElementById('passErr');
        if (passErr) passErr.style.display = 'none';
        // الدور والفرع (نقطة 6: ما يضيعوش)
        document.getElementById('f_role').value = data.role_id || '';
        document.getElementById('f_branch').value = (data.branch_id === '' || data.branch_id == null) ? '' : data.branch_id;
        // بيانات الموظف
        document.getElementById('f_isemp').checked = !!data.is_employee;
        if (!('base_salary' in data)) {
            document.getElementById('f_salary').disabled = true;
            document.getElementById('f_salary').value = '';
            document.getElementById('f_salary').placeholder = 'مقفول — Ctrl+L';
        } else {
            document.getElementById('f_salary').disabled = false;
            document.getElementById('f_salary').placeholder = '';
            document.getElementById('f_salary').value = data.base_salary !== '' ? data.base_salary : 0;
        }
        document.getElementById('f_job').value = data.job_title || '';
        document.getElementById('f_phone').value = data.phone || '';
        document.getElementById('f_in').value = data.expected_in || '';
        document.getElementById('f_out').value = data.expected_out || '';
        document.getElementById('f_anywhere').checked = !!data.allow_anywhere;
        toggleEmp();
        // تعليم الصلاحيات
        clearPerms();
        currentUserPerms = Array.isArray(data.perms) ? data.perms : [];
        modal.querySelectorAll('.perm-check').forEach(cb => {
            cb.checked = currentUserPerms.includes(cb.value);
        });
        // لو «كامل» متعلم للمديول، نقفل أجزاءه — وبعدين صلاحيات الدور
        applyRoleLock();
        modal.classList.add('open');
    }

    function onModuleFullChange(fullCb) {
        syncParts(fullCb);
    }

    function onPartChange(partCb) {
        const fullCb = partCb.closest('.perms-box').querySelector('.perm-mod-full[data-mod="' + partCb.dataset.mod + '"]');
        if (partCb.checked && fullCb && !fullCb.hasAttribute('data-role-lock')) {
            fullCb.checked = false;
        }
    }

    function toggleEmp() {
        const on = document.getElementById('f_isemp').checked;
        document.getElementById('empFields').style.display = on ? 'block' : 'none';
    }

    function clearPerms() {
        if (!modal) return;
        modal.querySelectorAll('.perm-check').forEach(cb => {
            cb.checked = false;
            cb.disabled = false;
            cb.removeAttribute('data-role-lock');
            const t = cb.parentNode.querySelector('.role-tag');
            if (t) t.remove();
        });
    }

    function closeModal() {
        modal.classList.remove('open');
    }

    if (modal) {
        modal.addEventListener('click', e => {
            if (e.target === modal) closeModal();
        });
        document.getElementById('f_role').addEventListener('change', applyRoleLock);
    }

    var userForm = document.getElementById('userForm');
    var pw = document.getElementById('f_password');
    var passErr = document.getElementById('passErr');

    function showPassError(len) {
        var msg = 'الباسورد لازم ٨ حروف على الأقل — انت كاتب ' + len;
        if (passErr) {
            passErr.textContent = msg;
            passErr.style.display = 'block';
        }
        if (typeof fgToast === 'function') {
            fgToast(msg, 'err');
        }
        if (pw) pw.focus();
    }

    if (pw) {
        pw.addEventListener('input', function() {
            if (passErr) passErr.style.display = 'none';
        });
        pw.addEventListener('invalid', function(e) {
            if (pw.value !== '' && pw.value.length < 8) {
                e.preventDefault();
                showPassError(pw.value.length);
            }
        });
    }

    if (userForm) {
        userForm.addEventListener('submit', function(e) {
            if (pw && pw.value !== '' && pw.value.length < 8) {
                e.preventDefault();
                showPassError(pw.value.length);
            }
        });
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
