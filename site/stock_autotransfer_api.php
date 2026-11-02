<?php
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
site_require_enabled('transfers');
require_once __DIR__ . '/stock_autotransfer_lib.php';
api_begin();
require_login();

// نفس شروط إنشاء التحويل؛ صلاحية فرع الراسل بتتفحص لكل توزيع في المكتبة.
if (!can('transfers') && !is_owner()) {
    api_fail('غير مصرح لك بالوصول للتحويلات', 403, 'forbidden');
}
$user = current_user();
$isOwner = is_real_owner() || is_owner();
$userBranchId = !empty($user['branch_id']) ? (int) $user['branch_id'] : null;
if (!$isOwner && !$userBranchId) {
    api_fail('حسابك مش مربوط بفرع — كلّم المدير يظبط فرعك الأول', 403, 'forbidden');
}
if (!$isOwner) {
    if (!can('transfers:create')) {
        api_fail('ليس لديك صلاحية إنشاء تحويل', 403, 'forbidden');
    }
}

$input = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? $_GET : api_input();
$action = (string) ($input['action'] ?? '');
if ($action === 'autotransfer_preview') api_method('GET');
elseif ($action === 'autotransfer_run') {
    api_method('POST');
    api_csrf();
} else api_fail('الإجراء ده مش معروف', 400, 'bad_request');
if (isset($input['dist_ids']) && !is_array($input['dist_ids'])) {
    api_fail('ابعت أرقام التوزيعات في قايمة', 422, 'invalid');
}
try {
    stock_require_enabled();
    $pdo = db();
    // الغياب بس هو اللي بيختار الافتراضي؛ القايمة الفاضية ماتختارش أي حاجة.
    $ids = $input['dist_ids'] ?? autotransfer_default_ids($pdo);
    if ($action === 'autotransfer_preview') api_ok(autotransfer_preview($pdo, $ids));
    api_ok(autotransfer_run($pdo, $ids, (int) ($user['id'] ?? 0)));
} catch (PDOException $e) {
    throw $e;
} catch (RuntimeException $e) {
    api_fail($e->getMessage(), 422, 'invalid');
}
