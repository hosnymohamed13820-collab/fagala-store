<?php
/**
 * import_users.php — إدارة استيراد المستخدمين من نظام الأصناف.
 *
 * خطوتين:
 *  1) تصدير لفايل (export_file): يسحب اليوزرز من SQL Server مرة واحدة
 *     ويحفظهم في data/users_export.json. (يتعمل مرة في الفرع اللي فيه SQL Server).
 *  2) استيراد من الفايل (import): يقرا من data/users_export.json وينشئ المستخدمين
 *     بباسورد 123 + دور كاشير + صلاحية الجرد. (مش محتاج SQL Server).
 *
 * للأونر فقط. آمنة تتشغّل أكتر من مرة (بتتخطّى الموجود).
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();
if (!is_owner()) {
    http_response_code(403);
    exit('هذه الصفحة للأونر فقط.');
}
require_once __DIR__ . '/config.php';

$pdo = db();

$DEFAULT_PASSWORD = '123';
$EXPORT_FILE      = __DIR__ . '/data/users_export.json';
$PROJECT_FILE     = __DIR__ . '/data/project_users.json';
$SKIP_NAMES       = ['admin', 'autorepl', 'cashier', 'autorep'];

$flash = ''; $flashType = '';
$results = ['added' => [], 'skipped' => [], 'errors' => []];
$ran = false; $exported = 0;

// حالة الفايل
$fileExists = is_file($EXPORT_FILE);
$fileCount = 0; $fileDate = '';
if ($fileExists) {
    $j = json_decode((string)file_get_contents($EXPORT_FILE), true);
    $fileCount = is_array($j['users'] ?? null) ? count($j['users']) : 0;
    $fileDate = $j['exported_at'] ?? '';
}

// حالة فايل يوزرز المشروع
$projExists = is_file($PROJECT_FILE);
$projCount = 0; $projDate = '';
if ($projExists) {
    $pj = json_decode((string)file_get_contents($PROJECT_FILE), true);
    $projCount = is_array($pj['users'] ?? null) ? count($pj['users']) : 0;
    $projDate = $pj['exported_at'] ?? '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $flash = 'انتهت صلاحية الجلسة، حاول تاني.'; $flashType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'export_project') {
                // ===== تصدير يوزرز المشروع نفسه (طبق الأصل) لفايل =====
                $rows = $pdo->query(
                    "SELECT u.username, u.full_name, u.password_hash, u.is_active,
                            r.name AS role_name, b.name AS branch_name
                     FROM users u
                     JOIN roles r ON r.id = u.role_id
                     LEFT JOIN branches b ON b.id = u.branch_id"
                )->fetchAll();
                $users = [];
                $ps = $pdo->prepare(
                    'SELECT module_key FROM user_permissions up
                     JOIN users uu ON uu.id = up.user_id WHERE uu.username = ?'
                );
                foreach ($rows as $r) {
                    $ps->execute([$r['username']]);
                    $users[] = [
                        'username'      => $r['username'],
                        'full_name'     => $r['full_name'],
                        'password_hash' => $r['password_hash'],   // الباسورد المشفّر زي ما هو
                        'is_active'     => (int)$r['is_active'],
                        'role_name'     => $r['role_name'],
                        'branch_name'   => $r['branch_name'],
                        'permissions'   => $ps->fetchAll(PDO::FETCH_COLUMN),
                    ];
                }
                if (!is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0775, true);
                file_put_contents($PROJECT_FILE, json_encode([
                    'exported_at' => date('Y-m-d H:i:s'),
                    'count'       => count($users),
                    'users'       => $users,
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                $flash = 'تم تصدير ' . count($users) . ' مستخدم من المشروع (بصلاحياتهم وباسوردهم) للفايل.';
                $flashType = 'success';
                $projExists = true; $projCount = count($users); $projDate = date('Y-m-d H:i:s');
            }
            elseif ($action === 'import_project') {
                // ===== استيراد يوزرز المشروع من الفايل (طبق الأصل) =====
                if (!is_file($PROJECT_FILE)) {
                    throw new RuntimeException('مفيش فايل يوزرز مشروع. صدّرهم من الفرع الأصلي الأول.');
                }
                $data = json_decode((string)file_get_contents($PROJECT_FILE), true);
                $users = $data['users'] ?? [];
                if (!$users) throw new RuntimeException('الفايل فاضي أو تالف.');

                $ran = true;
                $roleMap = [];
                foreach ($pdo->query('SELECT id, name FROM roles') as $r) $roleMap[$r['name']] = (int)$r['id'];
                $branchMap = [];
                foreach ($pdo->query('SELECT id, name FROM branches') as $b) $branchMap[$b['name']] = (int)$b['id'];

                $existing = [];
                foreach ($pdo->query('SELECT username FROM users') as $u) {
                    $existing[mb_strtolower(trim($u['username']))] = true;
                }

                $insUser = $pdo->prepare(
                    'INSERT INTO users (username, full_name, password_hash, role_id, branch_id, is_active)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $insPerm = $pdo->prepare('INSERT IGNORE INTO user_permissions (user_id, module_key) VALUES (?, ?)');

                foreach ($users as $u) {
                    $username = trim((string)($u['username'] ?? ''));
                    $fullName = trim((string)($u['full_name'] ?? ''));
                    if ($username === '') { $results['skipped'][] = '— (فاضي)'; continue; }
                    $key = mb_strtolower($username);
                    if (isset($existing[$key])) {
                        $results['skipped'][] = "$username — $fullName (موجود)"; continue;
                    }
                    $roleId = $roleMap[$u['role_name'] ?? ''] ?? ($roleMap['cashier'] ?? 0);
                    if (!$roleId) { $results['errors'][] = "$username: الدور غير موجود"; continue; }
                    $branchId = isset($u['branch_name']) && isset($branchMap[$u['branch_name']])
                              ? $branchMap[$u['branch_name']] : null;
                    try {
                        $insUser->execute([
                            $username, $fullName,
                            $u['password_hash'] ?? '',
                            $roleId, $branchId, (int)($u['is_active'] ?? 1),
                        ]);
                        $newId = (int) $pdo->lastInsertId();
                        foreach (($u['permissions'] ?? []) as $perm) {
                            $insPerm->execute([$newId, $perm]);
                        }
                        $existing[$key] = true;
                        $results['added'][] = "$username — $fullName";
                    } catch (Throwable $ex) {
                        $results['errors'][] = "$username — $fullName: " . $ex->getMessage();
                    }
                }
                $flash = 'تم استيراد يوزرز المشروع: ' . count($results['added']) . ' جديد، ' . count($results['skipped']) . ' متخطّى.';
                $flashType = 'success';
            }
            elseif ($action === 'export_file') {
                // ===== سحب اليوزرز من SQL Server لفايل =====
                require_once __DIR__ . '/price_lib.php';
                $srcTable = SQLSRV_PREFIX . 'USERS';
                $rows = sqlsrv_query("SELECT USER_CODE, USER_NAME1 FROM {$srcTable}");
                $users = [];
                foreach ($rows as $r) {
                    $code = trim((string)($r['USER_CODE'] ?? ''));
                    $name = trim(sqlsrv_fix_encoding((string)($r['USER_NAME1'] ?? '')));
                    if ($code === '' || $name === '' || strtoupper($name) === '[NULL]') continue;
                    $users[] = ['code' => $code, 'name' => $name];
                }
                if (!is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0775, true);
                file_put_contents($EXPORT_FILE, json_encode([
                    'exported_at' => date('Y-m-d H:i:s'),
                    'source'      => SQLSRV_DB,
                    'users'       => $users,
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                $exported = count($users);
                $flash = "تم سحب $exported مستخدم وحفظهم في الفايل. تقدر دلوقتي تستورد منهم.";
                $flashType = 'success';
                $fileExists = true; $fileCount = $exported; $fileDate = date('Y-m-d H:i:s');
            }
            elseif ($action === 'import') {
                // ===== استيراد من الفايل =====
                if (!is_file($EXPORT_FILE)) {
                    throw new RuntimeException('مفيش فايل مستخدمين. اسحبهم من السيستم الأول.');
                }
                $data = json_decode((string)file_get_contents($EXPORT_FILE), true);
                $users = $data['users'] ?? [];
                if (!$users) throw new RuntimeException('الفايل فاضي أو تالف.');

                $ran = true;
                $roleId = (int) $pdo->query("SELECT id FROM roles WHERE name='cashier' LIMIT 1")->fetchColumn();
                if (!$roleId) throw new RuntimeException('دور الكاشير غير موجود. شغّل setup.php أولاً.');

                $existing = [];
                foreach ($pdo->query('SELECT username FROM users') as $u) {
                    $existing[mb_strtolower(trim($u['username']))] = true;
                }

                $seenInBatch = [];
                $insUser = $pdo->prepare('INSERT INTO users (username, full_name, password_hash, role_id, is_active) VALUES (?, ?, ?, ?, 1)');
                $insPerm = $pdo->prepare('INSERT IGNORE INTO user_permissions (user_id, module_key) VALUES (?, ?)');
                $hash = password_hash($DEFAULT_PASSWORD, PASSWORD_DEFAULT);

                foreach ($users as $u) {
                    $code = trim((string)($u['code'] ?? ''));
                    $name = trim((string)($u['name'] ?? ''));
                    if ($code === '' || $name === '') { $results['skipped'][] = ($code ?: '—').' (فاضي)'; continue; }
                    if (in_array(mb_strtolower($name), $SKIP_NAMES, true) || in_array(mb_strtolower($code), $SKIP_NAMES, true)) {
                        $results['skipped'][] = "$code — $name (مستبعد)"; continue;
                    }
                    $key = mb_strtolower($code);
                    if (isset($existing[$key]) || isset($seenInBatch[$key])) {
                        $results['skipped'][] = "$code — $name (موجود/مكرر)"; continue;
                    }
                    try {
                        $insUser->execute([$code, $name, $hash, $roleId]);
                        $newId = (int) $pdo->lastInsertId();
                        $insPerm->execute([$newId, 'stocktake']);
                        $seenInBatch[$key] = true;
                        $results['added'][] = "$code — $name";
                    } catch (Throwable $ex) {
                        $results['errors'][] = "$code — $name: " . $ex->getMessage();
                    }
                }
                $flash = 'تم الاستيراد: ' . count($results['added']) . ' جديد، ' . count($results['skipped']) . ' متخطّى.';
                $flashType = 'success';
            }
        } catch (Throwable $ex) {
            $flash = 'خطأ: ' . $ex->getMessage();
            $flashType = 'error';
        }
    }
}

$page_title    = 'استيراد المستخدمين';
$active_module = 'users';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <h1>استيراد المستخدمين</h1>
</div>

<?php if ($flash): ?>
    <div class="flash <?= $flashType ?>"><?= e($flash) ?></div>
<?php endif; ?>

<!-- نقل يوزرز المشروع (طبق الأصل) -->
<div class="card" style="border:2px solid var(--accent);">
    <h3 style="font-size:16px;color:var(--accent);margin-bottom:10px;">نقل يوزرز المشروع لفرع جديد (طبق الأصل)</h3>
    <p style="color:#555;font-size:14px;line-height:1.9;">
        ينقل المستخدمين الموجودين في المشروع <strong>زي ما هم بالظبط</strong>: نفس الباسورد،
        الصلاحيات، الأدوار، والفرع. استخدمه لما تكون ظبّطت اليوزرز على فرع (زي أي بي سي)
        وعايز تنقلهم لفرع جديد من غير ما تعيد ظبطهم.
    </p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:12px 0;">
        <form method="post" onsubmit="return confirm('تصدير كل يوزرز المشروع الحاليين لفايل؟')" style="display:inline;">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="export_project">
            <button type="submit" class="btn"><i class="ti ti-package-export"></i> 1) تصدير يوزرز المشروع لفايل</button>
        </form>
        <form method="post" onsubmit="return confirm('استيراد يوزرز المشروع من الفايل دلوقتي؟')" style="display:inline;">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="import_project">
            <button type="submit" class="btn" style="background:var(--pos);" <?= $projExists?'':'disabled style=opacity:0.5;background:var(--pos);' ?>>
                <i class="ti ti-package-import"></i> 2) استيراد يوزرز المشروع من الفايل
            </button>
        </form>
    </div>
    <?php if ($projExists): ?>
        <p style="color:var(--pos);font-size:13px;background:var(--pos-soft);padding:10px 14px;border-radius:8px;">
            ✅ فيه فايل يوزرز مشروع: <strong><?= (int)$projCount ?></strong> مستخدم
            <?= $projDate ? '(آخر تصدير: '.e($projDate).')' : '' ?>
        </p>
    <?php else: ?>
        <p style="color:var(--warn);font-size:13px;background:var(--warn-soft);padding:10px 14px;border-radius:8px;">
            مفيش فايل لسه. صدّر اليوزرز من الفرع الأصلي (أي بي سي)، انقل الفايل <code>data/project_users.json</code> للفرع الجديد، وبعدين استورد.
        </p>
    <?php endif; ?>
    <p style="color:var(--ink-500);font-size:12px;margin-top:8px;">
        ملاحظة: بيتخطّى أي يوزر اسمه موجود بالفعل (مايكررش ومايعدّلش). الأدوار والفروع بتتطابق بالاسم.
    </p>
</div>

<hr style="border:none;border-top:1.5px dashed var(--line);margin:24px 0;">
<p style="color:var(--ink-500);font-size:13px;margin-bottom:12px;">— أو سحب اليوزرز من نظام الأصناف (SQL Server) لأول مرة —</p>

<!-- الخطوة 1: سحب لفايل -->
<div class="card">
    <h3 style="font-size:16px;color:var(--ink-900);margin-bottom:10px;">الخطوة 1: سحب المستخدمين من السيستم لفايل</h3>
    <p style="color:#555;font-size:14px;line-height:1.9;">
        بيسحب المستخدمين من نظام الأصناف (SQL Server) ويحفظهم في فايل ثابت.
        اعملها مرة واحدة في الفرع اللي متصل بالـ SQL Server.
    </p>
    <?php if ($fileExists): ?>
        <p style="color:var(--pos);font-size:13px;background:var(--pos-soft);padding:10px 14px;border-radius:8px;">
            ✅ فيه فايل محفوظ: <strong><?= (int)$fileCount ?></strong> مستخدم
            <?= $fileDate ? '(آخر سحب: '.e($fileDate).')' : '' ?>
        </p>
    <?php else: ?>
        <p style="color:var(--warn);font-size:13px;background:var(--warn-soft);padding:10px 14px;border-radius:8px;">
            مفيش فايل لسه. اسحب المستخدمين الأول.
        </p>
    <?php endif; ?>
    <form method="post" onsubmit="return confirm('سحب المستخدمين من السيستم وحفظهم في فايل؟')">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="export_file">
        <button type="submit" class="btn"><i class="ti ti-database-export"></i> سحب من السيستم لفايل</button>
    </form>
</div>

<!-- الخطوة 2: استيراد من الفايل -->
<div class="card">
    <h3 style="font-size:16px;color:var(--ink-900);margin-bottom:10px;">الخطوة 2: استيراد المستخدمين من الفايل</h3>
    <p style="color:#555;font-size:14px;line-height:1.9;">
        بينشئ حساب لكل مستخدم في الفايل: اسم الدخول = الكود، الباسورد = <strong>123</strong>،
        الدور = كاشير، مع فتح صلاحية <strong>الجرد</strong>. مش محتاج اتصال بالسيستم.
    </p>
    <p style="color:var(--ink-500);font-size:12px;">بيتخطّى الفاضي والمكرر وأسماء النظام. آمن تشغّله أكتر من مرة.</p>
    <form method="post" onsubmit="return confirm('استيراد المستخدمين من الفايل دلوقتي؟')">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="import">
        <button type="submit" class="btn" <?= $fileExists?'':'disabled style=opacity:0.5;' ?>>
            <i class="ti ti-user-plus"></i> استيراد من الفايل
        </button>
        <a href="users.php" class="btn btn-ghost" style="text-decoration:none;">رجوع للمستخدمين</a>
    </form>
</div>

<?php if ($ran): ?>
<div class="card">
    <h3 style="font-size:15px;color:var(--pos);margin-bottom:8px;">تم إضافتهم (<?= count($results['added']) ?>)</h3>
    <?php if ($results['added']): ?>
        <div style="max-height:240px;overflow-y:auto;font-size:13px;line-height:1.9;color:#444;">
            <?php foreach ($results['added'] as $a): ?><div>✅ <?= e($a) ?></div><?php endforeach; ?>
        </div>
    <?php else: ?>
        <p style="color:#888;font-size:13px;">لم يُضف أحد (كلهم موجودين أو متخطّين).</p>
    <?php endif; ?>
</div>
<?php if ($results['skipped']): ?>
<div class="card">
    <h3 style="font-size:15px;color:var(--warn);margin-bottom:8px;">متخطّى (<?= count($results['skipped']) ?>)</h3>
    <div style="max-height:200px;overflow-y:auto;font-size:13px;line-height:1.8;color:#777;">
        <?php foreach ($results['skipped'] as $s): ?><div>⏭️ <?= e($s) ?></div><?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
<?php if ($results['errors']): ?>
<div class="card">
    <h3 style="font-size:15px;color:var(--neg);margin-bottom:8px;">أخطاء (<?= count($results['errors']) ?>)</h3>
    <div style="max-height:200px;overflow-y:auto;font-size:13px;line-height:1.8;color:var(--neg);">
        <?php foreach ($results['errors'] as $er): ?><div>❌ <?= e($er) ?></div><?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
