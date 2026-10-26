<?php
/**
 * machines_api.php — داتا وكتابة «عداد المكن» (بريف ٢٢٣ج)
 * نظام الفجالة ستور المالي
 *
 * نفس منطق machines.php بالحرف: الدوال في machines_lib.php، والصفحة
 * بترسم منها، والـAPI بيرجّعها JSON للتطبيقات.
 *
 *   GET  ?part=readings[&branch=&date=]  ← {machines:[{…,fields:{…}}]}
 *   GET  ?part=daily[&branch=]           ← {rows, unknown, unmapped, sales_ready}
 *   GET  ?part=monthly[&branch=]         ← {rows, unmapped, sales_ready}
 *   GET  ?part=machines[&branch=]        ← {machines:[…]}   (تاب «المكن»)
 *   POST action=reading|add_machine|toggle_machine  (CSRF + الصلاحيات + الفرع)
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();
api_method('GET', 'POST');

// نفس حماية الصفحة: require_module_access('machines') وبعدين جزء module_parts()['machines'].
require_module_access('machines');

require_once __DIR__ . '/machines_lib.php';

$pdo = db();
$user = current_user();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$today = date('Y-m-d');

// ===== الكتابة (كانت جوه machines.php وانتقلت هنا) =====
if ($method === 'POST') {
    api_csrf();
    api_idempotent();
    $in = api_input();
    $action = (string)($in['action'] ?? '');
    $userBranch = (int)($user['branch_id'] ?? 0);
    $allowedIds = mc_allowed_branch_ids($pdo);

    try {
        if ($action === 'reading') {
            if (!can('machines:readings')) {
                api_fail('مالكش صلاحية إدخال قراية.', 403, 'forbidden');
            }
            $machineId = (int)($in['machine_id'] ?? 0);
            $st = $pdo->prepare('SELECT branch_id FROM machines WHERE id = ?');
            $st->execute([$machineId]);
            $machineBranch = (int)$st->fetchColumn();
            if (!$machineBranch || (!is_real_owner() && $machineBranch !== $userBranch)
                || !in_array($machineBranch,$allowedIds,true)) {
                api_fail('المكنة مش في فرعك.', 403, 'forbidden');
            }
            $raw = trim((string)($in['value'] ?? ''));
            if ($raw !== '' && (!ctype_digit($raw) || strlen($raw)>18)) {
                api_fail('اكتب عدد صحيح موجب أو سيب الخانة فاضية.', 400, 'bad_request');
            }
            $result = mc_save_reading($pdo,$machineId,$machineBranch,
                (string)($in['date'] ?? ''),(string)($in['field'] ?? ''),
                $raw === '' ? null : (int)$raw,(int)$user['id']);
            api_ok($result);
        }

        if ($action === 'add_machine' || $action === 'toggle_machine') {
            if (!can('machines:manage')) {
                api_fail('إدارة المكن للمالك أو مدير الفرع بس.', 403, 'forbidden');
            }
            $postBranch = (int)($in['branch_id'] ?? 0);
            if ((!is_real_owner() && $postBranch !== $userBranch)
                || !in_array($postBranch,$allowedIds,true)) {
                api_fail('الفرع مش متاح لك.', 403, 'forbidden');
            }
            if ($action === 'add_machine') {
                $id = mc_add_machine($pdo,$postBranch,(string)($in['name'] ?? ''),
                    (string)($in['kind'] ?? ''));
                api_ok(['id'=>$id]);
            }
            mc_toggle_machine($pdo,(int)($in['machine_id'] ?? 0),$postBranch,
                (string)($in['active'] ?? '') === '1');
            api_ok(null);
        }

        api_fail('العملية غير معروفة.', 400, 'bad_request');
    } catch (McInputError $e) {
        // الرسالة دي مكتوبة عندنا بالعامية؛ أي خطأ تاني بيروح لسجل الأخطاء ويرجع 500.
        api_fail($e->getMessage(), 400, 'bad_request');
    }
}

// ===== القراءة =====
$partTabs = ['readings'=>'readings', 'daily'=>'daily', 'monthly'=>'monthly', 'machines'=>'manage'];
$part = (string)($_GET['part'] ?? '');
if (!isset($partTabs[$part])) {
    api_fail('جزء غير معروف', 400, 'bad_request');
}
$tab = $partTabs[$part];
if (!can('machines:' . $tab)) {
    api_fail('مالكش صلاحية للتاب ده.', 403, 'forbidden');
}

try {
    // نفس حساب النطاق اللي الصفحة بتستخدمه (mc_scope في المكتبة).
    $scope = mc_scope($pdo, $tab, $user, (string)($_GET['branch'] ?? ''));
} catch (McInputError $e) {
    api_fail($e->getMessage(), 403, 'forbidden');
}

$readingDate = (string)($_GET['date'] ?? $today);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$readingDate) || $readingDate > $today) $readingDate = $today;

$view = mc_view($pdo, $scope, $tab, $today, $readingDate);
$meta = ['branch_id'=>$scope['branch_id'], 'all_scope'=>$scope['all_scope'], 'today'=>$today];

switch ($part) {
    case 'readings':
        api_ok($meta + ['date'=>$readingDate, 'machines'=>$view['readings']]);

    case 'daily':
        api_ok($meta + ['from'=>$view['from'], 'to'=>$today,
            'sales_ready'=>$view['sales_ready'], 'rows'=>$view['daily'],
            'unknown'=>$view['unknown'], 'unmapped'=>$view['unmapped']]);

    case 'monthly':
        api_ok($meta + ['from'=>$view['from'], 'to'=>$today,
            'sales_ready'=>$view['sales_ready'], 'rows'=>$view['monthly'],
            'unmapped'=>$view['unmapped']]);

    default: // machines
        api_ok($meta + ['machines'=>$view['machines']]);
}
