<?php
/**
 * users_lib.php — مكتبة دوال إدارة المستخدمين والصلاحيات (بريف ١٦٨)
 * نظام الفجالة ستور المالي
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/**
 * تنقية الصلاحيات والتحقق منها وتطبيق قاعدة «كامل ولا أجزاء» في السيرفر.
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
 * الصلاحيات اللي تتخزن للموظف = المتعلّم عليه ناقص اللي جاي من دوره.
 */
function user_extra_permissions(array $rawPerms, int $roleId, array $rolePerms): array
{
    return array_values(array_diff(sanitize_user_permissions($rawPerms), $rolePerms[$roleId] ?? []));
}

/**
 * جلب قائمة جميع المستخدمين مع بيانات الدور والفرع والموظف.
 */
function users_get_list(PDO $pdo): array
{
    return $pdo->query(
        'SELECT u.id, u.username, u.full_name, u.is_active, u.last_login, u.role_id, u.branch_id,
                u.discount_cap_pct,
                r.name_ar AS role_name, r.is_owner, b.name AS branch_name,
                e.id AS emp_id, e.base_salary, e.job_title, e.phone, e.expected_in, e.expected_out, e.allow_anywhere
         FROM users u
         JOIN roles r ON r.id = u.role_id
         LEFT JOIN branches b ON b.id = u.branch_id
         LEFT JOIN employees e ON e.user_id = u.id AND e.is_active = 1
         ORDER BY u.id'
    )->fetchAll();
}

/**
 * حفظ سقف خصم المستخدم كنسبة من إجمالي الفاتورة.
 */
function users_update_discount_cap(PDO $pdo, int $userId, float $cap): void
{
    if ($userId <= 0 || !is_finite($cap) || $cap < 0 || $cap > 100) {
        throw new InvalidArgumentException('سقف الخصم لازم يكون من ٠ لـ١٠٠.');
    }
    $st = $pdo->prepare('UPDATE users SET discount_cap_pct = ? WHERE id = ?');
    $st->execute([number_format($cap, 2, '.', ''), $userId]);
    if ($st->rowCount() === 0) {
        $chk = $pdo->prepare('SELECT id FROM users WHERE id = ?');
        $chk->execute([$userId]);
        if (!$chk->fetchColumn()) {
            throw new RuntimeException('المستخدم ده مش موجود.');
        }
    }
}

/**
 * جلب خريطة صلاحيات المستخدمين: [user_id => [module_key, ...]]
 */
function users_get_perms_map(PDO $pdo): array
{
    $permsByUser = [];
    foreach ($pdo->query('SELECT user_id, module_key FROM user_permissions') as $row) {
        $permsByUser[$row['user_id']][] = $row['module_key'];
    }
    return $permsByUser;
}

/**
 * جلب قائمة الأدوار مع أعداد المستخدمين التابعين لكل دور.
 */
function users_get_roles(PDO $pdo): array
{
    return $pdo->query(
        'SELECT r.id, r.name_ar, r.is_owner,
                COUNT(u.id) AS users_all, COALESCE(SUM(u.is_active), 0) AS users_active
         FROM roles r LEFT JOIN users u ON u.role_id = r.id
         GROUP BY r.id, r.name_ar, r.is_owner
         ORDER BY r.id'
    )->fetchAll();
}

/**
 * جلب خريطة صلاحيات الأدوار: [role_id => [module_key, ...]]
 */
function users_get_role_perms_map(PDO $pdo): array
{
    $rolePerms = [];
    foreach ($pdo->query('SELECT role_id, module_key FROM role_permissions ORDER BY id') as $row) {
        $rolePerms[(int) $row['role_id']][] = $row['module_key'];
    }
    return $rolePerms;
}

/**
 * جلب قائمة الفروع النشطة.
 */
function users_get_branches(PDO $pdo): array
{
    return $pdo->query('SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name')->fetchAll();
}
