<?php
/**
 * settings.php — الإعدادات العامة
 * نظام الفجالة ستور المالي
 *
 * تعديل القيم المخزّنة في جدول settings.
 */
require_once __DIR__ . '/auth.php';
require_permission('settings');

$pdo       = db();
$flash     = '';
$flashType = 'success';

// الإعدادات القابلة للتعديل من الواجهة (مفتاح => [التسمية, النوع])
$editable = [
    'store_name'                => ['اسم المحل', 'text'],
    'print_header'              => ['عنوان الطباعة (أعلى التقارير)', 'text'],
    'print_footer'              => ['تذييل الطباعة', 'text'],
    'custody_deficit_tolerance' => ['عتبة التسامح لعجز العهدة (بالجنيه)', 'number'],
    'business_day_start'        => ['بداية اليوم المحاسبي', 'time'],
    'currency'                  => ['العملة', 'text'],
    'shortage_supervisor_id'    => ['مسؤول متابعة طلبات النواقص (بيوصله إشعار مع الأونر)', 'user'],
    'sales_revenue_account_id'  => ['حساب إيراد المبيعات لتقفيل الورديات (رقم الحساب)', 'number'],
    'attendance_edit_user_id'   => ['موظف مسموحله يعدّل ساعات طلبات التصحيح (مع الأونر)', 'user'],
];

// قائمة المستخدمين لحقول نوع user
$settingsUsers = $pdo->query(
    'SELECT u.id, u.full_name, b.name AS branch_name
     FROM users u LEFT JOIN branches b ON b.id = u.branch_id
     WHERE u.is_active = 1 ORDER BY u.full_name'
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $flash = 'انتهت صلاحية الجلسة، حاول مرة أخرى.';
        $flashType = 'error';
    } else {
        $upd = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        foreach ($editable as $key => $meta) {
            if (isset($_POST[$key])) {
                $val = trim($_POST[$key]);
                // التحقق من عتبة العجز إنها رقم موجب
                if ($key === 'custody_deficit_tolerance') {
                    $val = (string) max(0, (float) $val);
                }
                // مسؤول النواقص وموظف تعديل الحضور: رقم مستخدم (٠ = مفيش)
                if ($key === 'shortage_supervisor_id' || $key === 'attendance_edit_user_id') {
                    $val = (string) max(0, (int) $val);
                }
                // رقم حساب إيراد المبيعات: رقم موجب (٠ = بدون)
                if ($key === 'sales_revenue_account_id') {
                    $val = (string) max(0, (int) $val);
                }
                $upd->execute([$key, $val]);
            }
        }
        $flash = 'تم حفظ الإعدادات بنجاح.';
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

// جلب القيم الحالية
$current = [];
foreach ($pdo->query('SELECT setting_key, setting_value FROM settings') as $row) {
    $current[$row['setting_key']] = $row['setting_value'];
}

$page_title    = 'الإعدادات';
$active_module = 'settings';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .flash { padding:12px 16px; border-radius:10px; margin-bottom:18px; font-size:14px; }
    .flash.success { background:var(--pos-soft); color:var(--pos); }
    .flash.error   { background:var(--neg-soft); color:var(--neg); }
    .settings-form { max-width:560px; }
    .form-row { margin-bottom:18px; }
    .form-row label { display:block; font-size:14px; color:var(--ink-700); margin-bottom:7px; font-weight:500; }
    .form-row input {
        width:100%; padding:11px 13px; border:1.5px solid var(--line); border-radius:10px;
        font-size:14px; font-family:inherit; background:var(--line-soft); }
    .form-row input:focus { outline:none; border-color:var(--accent); background:#fff; }
    .btn { background:var(--accent); color:#fff; border:none; padding:12px 28px; border-radius:10px;
        font-size:15px; font-family:inherit; cursor:pointer; font-weight:600; }
    .btn:hover { background:var(--accent-hover); }
    .nav-links { margin-bottom:20px; display:flex; gap:10px; flex-wrap:wrap; }
    .nav-link { padding:9px 16px; background:#fff; border-radius:9px; text-decoration:none;
        color:var(--accent-hover); font-size:14px; }
    .nav-link:hover { background:var(--bg); }
</style>

<?php if (is_owner()): ?>
<div class="nav-links">
    <a href="users.php" class="nav-link"><i class="ti ti-users"></i> إدارة المستخدمين</a>
    <a href="definitions.php" class="nav-link"><i class="ti ti-list-details"></i> التعريفات</a>
</div>
<?php endif; ?>

<?php if ($flash): ?>
    <div class="flash <?= e($flashType) ?>"><?= e($flash) ?></div>
<?php endif; ?>

<div class="card">
    <form method="post" class="settings-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <?php foreach ($editable as $key => $meta): ?>
            <?php [$label, $type] = $meta; ?>
            <div class="form-row">
                <label><?= e($label) ?></label>
                <?php if ($type === 'user'): ?>
                    <select name="<?= e($key) ?>" style="width:100%;padding:11px 13px;border:1.5px solid var(--line);border-radius:10px;font-size:14px;font-family:inherit;background:var(--line-soft);">
                        <option value="0">— مفيش (الأونر بس) —</option>
                        <?php foreach ($settingsUsers as $su): ?>
                            <option value="<?= (int)$su['id'] ?>" <?= (int)($current[$key] ?? 0) === (int)$su['id'] ? 'selected' : '' ?>>
                                <?= e($su['full_name']) ?><?= $su['branch_name'] ? ' — ' . e($su['branch_name']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="<?= e($type) ?>"
                           <?= $type === 'number' ? 'step="0.01" min="0"' : '' ?>
                           name="<?= e($key) ?>"
                           value="<?= e($current[$key] ?? '') ?>">
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <button type="submit" class="btn">حفظ الإعدادات</button>
    </form>
</div>

<!-- فحص اتصال قاعدة بيانات الأصناف (ODBC) -->
<?php $conn = sqlsrv_check(); ?>
<div class="card">
    <h3 style="font-size:16px;color:var(--ink-900);margin-bottom:12px;">اتصال قاعدة بيانات الأصناف (ODBC)</h3>
    <div style="display:flex;align-items:center;gap:12px;padding:14px;border-radius:10px;
         background:<?= $conn['ok'] ? 'var(--pos-soft)' : 'var(--neg-soft)' ?>;">
        <span style="font-size:24px;"><?= $conn['ok'] ? '✅' : '❌' ?></span>
        <div>
            <div style="font-weight:700;color:<?= $conn['ok'] ? 'var(--pos)' : 'var(--neg)' ?>;">
                <?= $conn['ok'] ? 'الاتصال شغّال' : 'الاتصال غير شغّال' ?>
            </div>
            <div style="font-size:13px;color:#666;margin-top:4px;"><?= e($conn['message']) ?></div>
        </div>
    </div>
    <p style="font-size:13px;color:var(--ink-500);margin-top:12px;">
        الإعداد: DSN = <strong><?= e(ODBC_DSN) ?></strong> · المستخدم = <strong><?= e(ODBC_USER) ?></strong>.
        لتغييرها عدّل config.php. (يُستخدم في: استعلام الأسعار، الاستلامات، الجرد).
    </p>
    <a href="settings.php" class="btn" style="margin-top:12px;display:inline-block;text-decoration:none;">إعادة الفحص</a>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
