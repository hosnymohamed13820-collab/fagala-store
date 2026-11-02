<?php
/**
 * transfers_api.php — واجهة برمجة تطبيقات التحويلات بين المخازن (بريف ٢١٥)
 * نظام الفجالة ستور المالي
 *
 * GET:
 *   ?action=list&tab=out|in|diffs|archive&branch_id=&from=&to=
 *   ?action=doc&id=
 *   ?action=lookup_code&code=&from_branch_id=
 *   ?action=open_diffs
 *
 * POST (CSRF):
 *   action=create (from_branch_id, to_branch_id, lines, notes)
 *   action=prepare (id, lines, notes)
 *   action=send (id)
 *   action=count (id, line_id, pieces)
 *   action=receive (id)
 *   action=resolve_excess (diff_id, source_branch_id)
 *   action=cancel (id, reason)
 *
 * 🔴 فحص الفرع في كل POST: موظف بونجور يستلم تحويل للفجالة -> 403.
 * 🔴 مقفول بـ stock_require_enabled() لأي كتابة.
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
site_require_enabled('transfers');
require_once __DIR__ . '/stock_transfer_lib.php';

api_begin();
require_login();

// التحقق من صلاحية الموديول العام
if (!can('transfers') && !is_owner()) {
    api_fail('غير مصرح لك بالوصول للتحويلات', 403, 'forbidden');
}

$pdo = db();
$user = current_user();
$uid = (int) ($user['id'] ?? 1);
$isOwner = is_real_owner() || is_owner();
$userBranchId = !empty($user['branch_id']) ? (int) $user['branch_id'] : null;

// 🔴 بريف ٢١٥ر: غير المالك من غير فرع = مفيش داتا خالص في أي action.
// من غير الحارس ده الفلتر بيتبعت null والاستعلام بيرجّع تحويلات كل الفروع.
if (!$isOwner && !$userBranchId) {
    api_fail('حسابك مش مربوط بفرع — كلّم المدير يظبط فرعك الأول', 403, 'forbidden');
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    $action = (string) ($_GET['action'] ?? 'list');

    switch ($action) {
        case 'list':
            $tab = (string) ($_GET['tab'] ?? 'out');
            $filters = ['tab' => $tab];

            if (!$isOwner) {
                $filters['branch_id'] = $userBranchId;
            } else {
                $filters['branch_id'] = !empty($_GET['branch_id']) ? (int) $_GET['branch_id'] : null;
            }

            if (!empty($_GET['from'])) {
                $filters['from'] = (string) $_GET['from'];
            }
            if (!empty($_GET['to'])) {
                $filters['to'] = (string) $_GET['to'];
            }
            if (!empty($_GET['limit'])) {
                $filters['limit'] = (int) $_GET['limit'];
            }

            $docs = transfer_list($pdo, $filters);
            api_ok(['docs' => $docs]);

        case 'doc':
            $id = (int) ($_GET['id'] ?? 0);
            if ($id <= 0) {
                api_fail('معرّف التحويل مطلوب', 400, 'bad_request');
            }
            $data = transfer_get($pdo, $id);
            if (!$data) {
                api_fail('التحويل غير موجود', 404, 'not_found');
            }

            // فحص الفرع لغير المالك
            if (!$isOwner) {
                $fromId = (int) ($data['doc']['branch_id'] ?? 0);
                $toId   = (int) ($data['doc']['counter_branch_id'] ?? 0);
                if ($userBranchId !== $fromId && $userBranchId !== $toId) {
                    api_fail('غير مصرح لك بعرض هذا التحويل', 403, 'forbidden');
                }
            }

            api_ok($data);

        case 'lookup_code':
            $code = trim((string) ($_GET['code'] ?? ''));
            $fromBranchId = (int) ($_GET['from_branch_id'] ?? 0);
            if ($code === '') {
                api_fail('الكود مطلوب', 400, 'bad_request');
            }

            $st = $pdo->prepare(
                'SELECT c.code, c.name, c.item_id, c.unit_factor, c.code_type, c.cost
                   FROM hq_codes c
                  WHERE c.code = ?
                  ORDER BY c.code_type ASC
                  LIMIT 1'
            );
            $st->execute([$code]);
            $item = $st->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                api_fail('الكود غير موجود في شجرة الأصناف', 404, 'not_found');
            }

            $item['item_id']     = (int) $item['item_id'];
            $item['unit_factor'] = (float) ($item['unit_factor'] ?? 1.0);
            if ($item['unit_factor'] <= 0.0) {
                $item['unit_factor'] = 1.0;
            }

            // رصيد الراسل الحالي
            $item['balance'] = 0.0;
            if ($fromBranchId > 0) {
                $bal = stock_balance($pdo, $item['item_id'], $fromBranchId, 0);
                $item['balance'] = $bal['qty'];
            }

            api_ok(['item' => $item]);

        case 'open_diffs':
            $diffBranchId = $isOwner ? null : $userBranchId;
            $diffs = transfer_open_diffs($pdo, $diffBranchId);
            api_ok(['diffs' => $diffs]);

        default:
            api_fail('إجراء GET غير معروف', 400, 'bad_request');
    }
}

// -------------------------------------------------------------
// POST: كل عمليات الكتابة
// -------------------------------------------------------------
api_csrf();
$input = api_input();
$action = (string) ($input['action'] ?? '');

try {
    // 🔴 حزام أمان: فحص erp_stock_enabled لكل طلبات POST
    stock_require_enabled();

    switch ($action) {
        case 'create':
            $fromBranch = (int) ($input['from_branch_id'] ?? 0);
            $toBranch   = (int) ($input['to_branch_id'] ?? 0);
            $lines      = (array) ($input['lines'] ?? []);
            $notes      = isset($input['notes']) && $input['notes'] !== '' ? (string) $input['notes'] : null;

            if (!$isOwner) {
                if (!can('transfers:create')) {
                    api_fail('ليس لديك صلاحية إنشاء تحويل', 403, 'forbidden');
                }
                if ($fromBranch !== $userBranchId) {
                    api_fail('لا يمكنك إنشاء تحويل إلا من فرعك', 403, 'forbidden');
                }
            }

            $docId = transfer_create($pdo, $fromBranch, $toBranch, $lines, $uid, $notes);
            api_ok(['doc_id' => $docId]);

        case 'prepare':
            $id    = (int) ($input['id'] ?? 0);
            $lines = (array) ($input['lines'] ?? []);
            $notes = isset($input['notes']) && $input['notes'] !== '' ? (string) $input['notes'] : null;

            $stCheck = $pdo->prepare('SELECT branch_id FROM stock_docs WHERE id = ? AND doc_type = \'transfer\'');
            $stCheck->execute([$id]);
            $docRow = $stCheck->fetch(PDO::FETCH_ASSOC);
            if (!$docRow) {
                api_fail('التحويل غير موجود', 404, 'not_found');
            }

            if (!$isOwner) {
                if (!can('transfers:create')) {
                    api_fail('ليس لديك صلاحية تجهيز التحويل', 403, 'forbidden');
                }
                if ((int) $docRow['branch_id'] !== $userBranchId) {
                    api_fail('لا يمكنك تعديل تحويل ليس صادراً من فرعك', 403, 'forbidden');
                }
            }

            transfer_prepare($pdo, $id, $lines, $uid, $notes);
            api_ok(['doc_id' => $id]);

        case 'send':
            $id = (int) ($input['id'] ?? 0);

            $stCheck = $pdo->prepare('SELECT branch_id FROM stock_docs WHERE id = ? AND doc_type = \'transfer\'');
            $stCheck->execute([$id]);
            $docRow = $stCheck->fetch(PDO::FETCH_ASSOC);
            if (!$docRow) {
                api_fail('التحويل غير موجود', 404, 'not_found');
            }

            if (!$isOwner) {
                if (!can('transfers:create')) {
                    api_fail('ليس لديك صلاحية إرسال التحويل', 403, 'forbidden');
                }
                if ((int) $docRow['branch_id'] !== $userBranchId) {
                    api_fail('لا يمكنك إرسال تحويل ليس صادراً من فرعك', 403, 'forbidden');
                }
            }

            $docNo = transfer_send($pdo, $id, $uid);
            api_ok(['doc_id' => $id, 'doc_no' => $docNo]);

        case 'count':
            $id     = (int) ($input['id'] ?? 0);
            $lineId = (int) ($input['line_id'] ?? 0);
            $code   = isset($input['code']) ? trim((string) $input['code']) : (isset($input['barcode']) ? trim((string) $input['barcode']) : null);
            $units  = isset($input['units']) ? (float) $input['units'] : (isset($input['qty']) ? (float) $input['qty'] : null);
            $pieces = (float) ($input['pieces'] ?? 0.0);
            $mode   = (string) ($input['mode'] ?? 'set');

            $stCheck = $pdo->prepare('SELECT counter_branch_id FROM stock_docs WHERE id = ? AND doc_type = \'transfer\'');
            $stCheck->execute([$id]);
            $docRow = $stCheck->fetch(PDO::FETCH_ASSOC);
            if (!$docRow) {
                api_fail('التحويل غير موجود', 404, 'not_found');
            }

            // 🔴 التحقق الصارم من الفرع المستلم
            if (!$isOwner) {
                if (!can('transfers:receive')) {
                    api_fail('ليس لديك صلاحية عد واستلام التحويلات', 403, 'forbidden');
                }
                if ((int) $docRow['counter_branch_id'] !== $userBranchId) {
                    api_fail('لا يمكنك عد تحويل ليس مرسلاً لفرعك', 403, 'forbidden');
                }
            }

            $rxRes = transfer_count($pdo, $id, $lineId, $pieces, $uid, $code, $units, $mode);
            api_ok($rxRes);

        case 'receive':
            $id = (int) ($input['id'] ?? 0);

            $stCheck = $pdo->prepare('SELECT counter_branch_id FROM stock_docs WHERE id = ? AND doc_type = \'transfer\'');
            $stCheck->execute([$id]);
            $docRow = $stCheck->fetch(PDO::FETCH_ASSOC);
            if (!$docRow) {
                api_fail('التحويل غير موجود', 404, 'not_found');
            }

            // 🔴 التحقق الصارم من الفرع المستلم
            if (!$isOwner) {
                if (!can('transfers:receive')) {
                    api_fail('ليس لديك صلاحية استلام التحويلات', 403, 'forbidden');
                }
                if ((int) $docRow['counter_branch_id'] !== $userBranchId) {
                    api_fail('لا يمكنك استلام تحويل ليس مرسلاً لفرعك', 403, 'forbidden');
                }
            }

            $res = transfer_receive($pdo, $id, $uid);
            api_ok($res);

        case 'diff_approve':
            $diffId = (int) ($input['diff_id'] ?? 0);
            if ($diffId <= 0) {
                api_fail('معرّف الفرق مطلوب', 400, 'bad_request');
            }

            // فحص الصلاحية الصارم: مالك حقيقي أو صاحب صلاحية transfers:resolve بالتحديد
            $isReal = function_exists('is_real_owner') ? is_real_owner() : false;
            $hasResolve = in_array('transfers:resolve', $_SESSION['permissions'] ?? [], true);
            if (!$isReal && !$hasResolve) {
                api_fail('غير مصرح لك باعتماد فروق التحويل — يتطلب صلاحية الاستلامات أو المالك الحقيقي', 403, 'forbidden');
            }

            $res = transfer_diff_approve($pdo, $diffId, $uid);
            api_ok($res);

        case 'diff_reject':
            $diffId = (int) ($input['diff_id'] ?? 0);
            $reason = trim((string) ($input['reason'] ?? ''));
            if ($diffId <= 0) {
                api_fail('معرّف الفرق مطلوب', 400, 'bad_request');
            }
            if ($reason === '') {
                api_fail('سبب الرفض إجباري', 400, 'validation');
            }

            $isReal = function_exists('is_real_owner') ? is_real_owner() : false;
            $hasResolve = in_array('transfers:resolve', $_SESSION['permissions'] ?? [], true);
            if (!$isReal && !$hasResolve) {
                api_fail('غير مصرح لك برفض فروق التحويل — يتطلب صلاحية الاستلامات أو المالك الحقيقي', 403, 'forbidden');
            }

            $res = transfer_diff_reject($pdo, $diffId, $uid, $reason);
            api_ok($res);

        case 'resolve_excess':
            $diffId         = (int) ($input['diff_id'] ?? 0);
            $sourceBranchId = (int) ($input['source_branch_id'] ?? 0);

            // القديم: يفضل شغال للمالك بس في الفروق القديمة (open و resolution IS NULL)
            $isReal = function_exists('is_real_owner') ? is_real_owner() : false;
            if (!$isReal) {
                api_fail('فك فروق الزيادة القديمة متاح للمالك بس', 403, 'forbidden');
            }

            $stD = $pdo->prepare('SELECT status, resolution FROM stock_transfer_diffs WHERE id = ?');
            $stD->execute([$diffId]);
            $dRow = $stD->fetch(PDO::FETCH_ASSOC);
            if (!$dRow || $dRow['status'] !== 'open' || $dRow['resolution'] !== null) {
                api_fail('الفرق ده ماشي بالاعتماد', 400, 'validation');
            }

            $adjId = transfer_resolve_excess($pdo, $diffId, $sourceBranchId, $uid);
            api_ok(['adjust_doc_id' => $adjId]);

        case 'cancel':
            $id     = (int) ($input['id'] ?? 0);
            $reason = trim((string) ($input['reason'] ?? 'إلغاء التحويل'));

            $stCheck = $pdo->prepare('SELECT branch_id FROM stock_docs WHERE id = ? AND doc_type = \'transfer\'');
            $stCheck->execute([$id]);
            $docRow = $stCheck->fetch(PDO::FETCH_ASSOC);
            if (!$docRow) {
                api_fail('التحويل غير موجود', 404, 'not_found');
            }

            if (!$isOwner) {
                if (!can('transfers:create')) {
                    api_fail('ليس لديك صلاحية إلغاء التحويل', 403, 'forbidden');
                }
                if ((int) $docRow['branch_id'] !== $userBranchId) {
                    api_fail('لا يمكنك إلغاء تحويل ليس صادراً من فرعك', 403, 'forbidden');
                }
            }

            transfer_cancel($pdo, $id, $uid, $reason);
            api_ok(['doc_id' => $id, 'status' => 'cancelled']);

        default:
            api_fail('إجراء POST غير معروف', 400, 'bad_request');
    }
} catch (RuntimeException $re) {
    api_fail($re->getMessage(), 400, 'validation');
} catch (Throwable $e) {
    api_fail('حصل خطأ أثناء معالجة الطلب: ' . $e->getMessage(), 500, 'server_error');
}
