<?php
/**
 * users_api.php — واجهة برمجة إدارة المستخدمين والصلاحيات (بريف ١٦٨)
 * نظام الفجالة ستور المالي
 *
 * عمليات GET (مرحلة أ — قراءة فقط):
 *   ?part=users ← {users: […]} (GET)
 *   ?part=roles ← {roles: […]} (GET)
 *   ?part=meta  ← {modules: …, module_parts: …, branches: […]} (GET)
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

// نفس حماية الصفحة بالحرف: المالك فقط (is_owner)
if (!is_owner()) {
    api_fail('هذه الصفحة متاحة للمالك فقط.', 403, 'forbidden');
}

require_once __DIR__ . '/sensitive_lib.php';
require_once __DIR__ . '/users_lib.php';

$part = (string) ($_GET['part'] ?? ($_POST['part'] ?? ''));

$allowedParts = [
    'users',
    'roles',
    'meta',
    'discount_cap',
];

if (!in_array($part, $allowedParts, true)) {
    api_fail('جزء غير معروف (part)', 400, 'bad_request');
}

$pdo = db();

try {
    switch ($part) {
        case 'users':
            api_method('GET');
            $usersRaw    = users_get_list($pdo);
            $permsByUser = users_get_perms_map($pdo);
            $rolePerms   = users_get_role_perms_map($pdo);
            $sensUnlocked = sens_unlocked();

            $users = [];
            foreach ($usersRaw as $row) {
                $uid = (int) $row['id'];
                $rid = (int) $row['role_id'];
                $uperms = $permsByUser[$uid] ?? [];

                $u = [
                    'id'             => $uid,
                    'username'       => (string) $row['username'],
                    'full_name'      => (string) $row['full_name'],
                    'is_active'      => (int) $row['is_active'],
                    'last_login'     => $row['last_login'],
                    'role_id'        => $rid,
                    'role_name'      => (string) $row['role_name'],
                    'is_owner'       => (int) $row['is_owner'],
                    'branch_id'      => $row['branch_id'] !== null ? (int) $row['branch_id'] : null,
                    'branch_name'    => $row['branch_name'],
                    'discount_cap_pct' => number_format((float) $row['discount_cap_pct'], 2, '.', ''),
                    'emp_id'         => $row['emp_id'] !== null ? (int) $row['emp_id'] : null,
                    'job_title'      => $row['job_title'],
                    'phone'          => $row['phone'],
                    'expected_in'    => $row['expected_in'],
                    'expected_out'   => $row['expected_out'],
                    'allow_anywhere' => (int) ($row['allow_anywhere'] ?? 0),
                    'permissions'    => $uperms,
                    'extra_permissions' => user_extra_permissions($uperms, $rid, $rolePerms),
                ];

                if ($sensUnlocked) {
                    $u['base_salary'] = $row['base_salary'] !== null ? (float) $row['base_salary'] : null;
                }
                // أمان صارم: التأكد التام من عدم وجود أي تجزئة لكلمة المرور أو إصدار الصلاحيات
                unset($u['password_hash'], $u['pw_version'], $u['perms_version']);

                $users[] = $u;
            }
            api_ok(['users' => $users]);

        case 'roles':
            api_method('GET');
            $rolesRaw  = users_get_roles($pdo);
            $rolePerms = users_get_role_perms_map($pdo);

            $roles = [];
            foreach ($rolesRaw as $r) {
                $rid = (int) $r['id'];
                $roles[] = [
                    'id'           => $rid,
                    'name_ar'      => (string) $r['name_ar'],
                    'is_owner'     => (int) $r['is_owner'],
                    'users_all'    => (int) $r['users_all'],
                    'users_active' => (int) $r['users_active'],
                    'permissions'  => $rolePerms[$rid] ?? [],
                ];
            }
            api_ok(['roles' => $roles]);

        case 'meta':
            api_method('GET');
            api_ok([
                'modules'      => all_modules(),
                'module_parts' => module_parts(),
                'branches'     => users_get_branches($pdo),
            ]);

        case 'discount_cap':
            api_method('POST');
            api_csrf();
            api_idempotent();
            $in = api_input();
            $rawCap = $in['discount_cap_pct'] ?? null;
            if (!is_numeric($rawCap)) {
                api_fail('سقف الخصم لازم يكون رقم.', 400, 'bad_request');
            }
            users_update_discount_cap($pdo, (int) ($in['user_id'] ?? 0), (float) $rawCap);
            api_ok(['discount_cap_pct' => number_format((float) $rawCap, 2, '.', '')]);
    }
} catch (InvalidArgumentException $e) {
    api_fail($e->getMessage(), 400, 'bad_request');
} catch (RuntimeException $e) {
    api_fail($e->getMessage(), 404, 'bad_request');
} catch (Throwable $e) {
    error_log('users_api: ' . $e->getMessage());
    api_fail('حصل خطأ في السيرفر', 500, 'server');
}
