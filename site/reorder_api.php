<?php
/**
 * reorder_api.php — واجهة برمجة التطبيقات لحد إعادة الطلب واقتراحات النواقص (بريف ٢١٣)
 * نظام الفجالة ستور المالي
 *
 * النمط الموحّد: API_MODE + api_begin + api_require + api_csrf + api_ok / api_fail.
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

// التحقق من صلاحية الموديول
api_require('reorder');

require_once __DIR__ . '/reorder_lib.php';
require_once __DIR__ . '/shortage_lib.php';

$pdo = db();
$user = $_SESSION['user'] ?? null;
$userId = (int) ($user['id'] ?? 0);

$action = (string) ($_GET['action'] ?? '');
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// استخراج مدخلات JSON أو POST
$input = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw !== false && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $input = $decoded;
        }
    }
    if (empty($input)) {
        $input = $_POST;
    }
}

switch ($action) {
    case 'items':
        api_method('GET');
        $branchId = (int) ($_GET['branch_id'] ?? 0);
        if ($branchId <= 0) {
            api_fail('اختار الفرع المطلوب', 400, 'bad_request');
        }

        // فحص الصلاحية للفرع
        if (!reorder_can_access_branch($branchId, $user)) {
            api_fail('غير مسموح بالوصول لفرع آخر', 403, 'forbidden');
        }

        $catId = isset($_GET['cat_id']) && (int) $_GET['cat_id'] > 0 ? (int) $_GET['cat_id'] : null;
        $search = isset($_GET['q']) ? trim((string) $_GET['q']) : null;

        $items = reorder_branch_items($pdo, $branchId, $catId, $search);

        api_ok([
            'branch_id' => $branchId,
            'count'     => count($items),
            'items'     => $items,
        ]);

    case 'update_limits':
        api_method('POST');
        api_csrf();

        $branchId = (int) ($input['branch_id'] ?? 0);
        $itemId   = (int) ($input['item_id'] ?? 0);
        $minQty   = (float) ($input['min_qty'] ?? 0);
        $maxQty   = (float) ($input['max_qty'] ?? 0);

        if ($branchId <= 0 || $itemId <= 0) {
            api_fail('بيانات الصنف أو الفرع غير مكتملة', 400, 'bad_request');
        }

        if (!reorder_can_access_branch($branchId, $user)) {
            api_fail('غير مسموح بتعديل فرع آخر', 403, 'forbidden');
        }

        $res = reorder_update_item($pdo, $itemId, $branchId, $minQty, $maxQty, $userId);
        api_ok($res);

    case 'reset_manual':
        api_method('POST');
        api_csrf();

        $branchId = (int) ($input['branch_id'] ?? 0);
        $itemId   = (int) ($input['item_id'] ?? 0);

        if ($branchId <= 0 || $itemId <= 0) {
            api_fail('بيانات الصنف أو الفرع غير مكتملة', 400, 'bad_request');
        }

        if (!reorder_can_access_branch($branchId, $user)) {
            api_fail('غير مسموح بتعديل فرع آخر', 403, 'forbidden');
        }

        $res = reorder_reset_manual($pdo, $itemId, $branchId, $userId);
        api_ok($res);

    case 'recalc':
        api_method('POST');
        api_csrf();

        // زرار «احسب دلوقتي» متاح للمالك فقط
        if (!is_owner()) {
            api_fail('المالك فقط يستطيع إعادة الحساب اليدوي', 403, 'forbidden');
        }

        $calc = reorder_recalc_locked($pdo);
        if ($calc === null) {
            api_fail('جاري الحساب في الخلفية بواسطة عملية أخرى...', 409, 'duplicate');
        }

        api_ok($calc);

    case 'create_shortage':
        api_method('POST');
        api_csrf();

        $branchId = (int) ($input['branch_id'] ?? 0);
        $rawLines = $input['lines'] ?? $input['items'] ?? [];

        if ($branchId <= 0) {
            api_fail('اختار الفرع الطالب', 400, 'bad_request');
        }

        if (!reorder_can_access_branch($branchId, $user)) {
            api_fail('غير مسموح بطلب فرع آخر', 403, 'forbidden');
        }

        if (!is_array($rawLines) || empty($rawLines)) {
            api_fail('اختار صنفاً واحداً على الأقل لطلب النواقص', 400, 'bad_request');
        }

        // تجهيز الأسطر والتأكد من استخدام الكود الأساسي code_type=0
        $cleanLines = [];
        foreach ($rawLines as $l) {
            if (!is_array($l)) continue;
            $itemId = (int) ($l['item_id'] ?? 0);
            $qty = (float) ($l['qty'] ?? $l['proposed_qty'] ?? 0);
            if ($qty <= 0) continue;

            $code = trim((string) ($l['code'] ?? ''));
            $name = trim((string) ($l['name'] ?? ''));

            // لو متوفر item_id نتأكد من جلب الكود الأساسي (code_type=0)
            if ($itemId > 0) {
                $cst = $pdo->prepare(
                    'SELECT code, name 
                       FROM hq_codes 
                      WHERE item_id = ? 
                      ORDER BY code_type ASC, code ASC 
                      LIMIT 1'
                );
                $cst->execute([$itemId]);
                $cRow = $cst->fetch(PDO::FETCH_ASSOC);
                if ($cRow) {
                    $code = (string) $cRow['code'];
                    if ($name === '') {
                        $name = (string) ($cRow['name'] ?? '');
                    }
                }
            }

            if ($code === '') continue;

            $cleanLines[] = [
                'code' => $code,
                'name' => $name,
                'qty'  => $qty,
            ];
        }

        if (empty($cleanLines)) {
            api_fail('مفيش أصناف بكميات صالحة للإرسال', 400, 'bad_request');
        }

        try {
            // الإرسال للإدارة (toBranchId = 6)
            $res = create_shortage_request(
                $branchId,
                6,
                $cleanLines,
                'اقتراح تلقائي من حد إعادة الطلب',
                $userId,
                'branch'
            );

            api_ok([
                'request_id' => $res['request_id'],
                'task_id'    => $res['task_id'],
                'targets'    => $res['targets'],
                'task_url'   => 'tasks.php?id=' . $res['task_id'],
                'lines_count'=> count($cleanLines),
            ]);
        } catch (Throwable $e) {
            api_fail($e->getMessage(), 400, 'bad_request');
        }

    default:
        api_fail('طلب غير معروف', 400, 'bad_request');
}
