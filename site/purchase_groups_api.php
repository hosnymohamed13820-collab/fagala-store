<?php
/**
 * purchase_groups_api.php — API مجموعات الشراء (بريف ١٣٩)
 * نظام الفجالة ستور المالي
 *
 * API بينادي دوال `purchase_group_lib.php` الموجودة.
 * Every part calls exactly one existing function — لا يُعاد كتابة منطق الدالة جوه الـAPI.
 *
 * GET: `list` · `get` · `candidates`.
 * POST: `create` · `toggle` · `toggle_source` · `remove` · `uncheck_all` · `delete_checked` · `rename` · `sort`.
 * كل part يدعي دالة موجودة في `purchase_group_lib.php` — من غير إعادة كتابة.
 *
 * قواعد الأخطاء: part مجهول ← 400 · GET على Part POST ← 405.
 */

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();
api_require('shortage');

require_once __DIR__ . '/purchase_group_lib.php';

// Parts that are valid for GET requests
$getParts = ['list', 'get', 'candidates'];
// Parts that are valid for POST requests only
$postParts = ['create', 'toggle', 'toggle_source', 'remove', 'uncheck_all', 'delete_checked', 'rename', 'sort'];

$part = (string) ($_GET['part'] ?? '');

//------ GET cases ------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (in_array($part, $getParts, true)) {
        // Valid GET part — handle it
        switch ($part) {
            case 'list':
                api_ok(['groups' => purchase_groups_list((string) ($_GET['status'] ?? 'open'))]);
                break;

            case 'get':
                $id = (int) ($_GET['id'] ?? 0);
                if ($id <= 0) {
                    api_fail('معرف مجموعة غير صالح', 404, 'not_found');
                }
                $res = purchase_group_get($id);
                if ($res === null) {
                    api_fail('المجموعة غير موجودة', 404, 'not_found');
                }
                api_ok($res);
                break;

            case 'candidates':
                api_ok(purchase_group_candidates());
                break;
        }
        exit;
    }

    // At this point: method is GET but part is not a valid GET part
    if (in_array($part, $postParts, true)) {
        // Part is known but method is GET → 405
        api_fail('الجزء ده Method GET مش مدعوم', 405, 'method_not_allowed');
    } else {
        // Part completely unknown → 400
        api_fail('جزء غير معروف', 400, 'bad_request');
    }
    exit;
}

//------ POST cases ------
api_method('POST');
api_csrf();
api_idempotent();

try {

$body = api_input();
if (!is_array($body)) {
    api_fail('بيانات غير صالحة', 400, 'bad_request');
}

$uid = (int) current_user()['id'];

switch ($part) {
    case 'create':
        $resLineIds   = (array) ($body['res_line_ids'] ?? []);
        $shortLineIds = (array) ($body['short_line_ids'] ?? []);
        $name         = (string) ($body['name'] ?? '');
        $skipped      = 0;
        $id = purchase_group_create($resLineIds, $shortLineIds, $name, $uid, $skipped);
        api_ok(['id' => $id, 'skipped' => $skipped]);
        break;

    case 'toggle':
        $groupId   = (int) ($body['group_id'] ?? 0);
        $lineId    = (int) ($body['line_id'] ?? 0);
        $isChecked = !empty($body['is_checked']);
        $res = purchase_group_toggle_line($groupId, $lineId, $isChecked, $uid);
        api_ok($res);
        break;

    case 'toggle_source':
        $groupId   = (int) ($body['group_id'] ?? 0);
        $sourceId  = (int) ($body['source_id'] ?? 0);
        $isChecked = !empty($body['is_checked']);
        $res = purchase_group_toggle_source($groupId, $sourceId, $isChecked, $uid);
        api_ok($res);
        break;

    case 'remove':
        $groupId = (int) ($body['group_id'] ?? 0);
        $lineId  = (int) ($body['line_id'] ?? 0);
        $res = purchase_group_remove_line($groupId, $lineId);
        api_ok($res);
        break;

    case 'uncheck_all':
        $groupId = (int) ($body['group_id'] ?? 0);
        $res = purchase_group_uncheck_all($groupId);
        api_ok($res);
        break;

    case 'delete_checked':
        $groupId = (int) ($body['group_id'] ?? 0);
        $res = purchase_group_delete_checked($groupId);
        api_ok($res);
        break;

    case 'rename':
        $groupId = (int) ($body['group_id'] ?? 0);
        $name    = (string) ($body['name'] ?? '');
        $res = purchase_group_rename($groupId, $name);
        api_ok($res);
        break;

    case 'sort':
        $groupId = (int) ($body['group_id'] ?? 0);
        $lineIds = (array) ($body['line_ids'] ?? []);
        $res = purchase_group_sort($groupId, $lineIds);
        api_ok($res);
        break;

    default:
        api_fail('جزء غير معروف', 400, 'bad_request');
        break;
}
} catch (RuntimeException $e) {
    api_fail($e->getMessage(), 422, 'invalid');
}