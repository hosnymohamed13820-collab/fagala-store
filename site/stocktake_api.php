<?php
/**
 * stocktake_api.php — داتا موديول الجرد (بريف ١٥٦ — مرحلة أ: قراءة فقط)
 * نظام الفجالة ستور المالي
 *
 *   ?part=session                  ← {session, branches, places, users}    (GET)
 *   ?part=sheets[&session_id=…]    ← {sheets, sheet_groups}                (GET)
 *   ?part=sheet&id=…               ← {sheet, lines, can_review, can_edit,
 *                                     comparison}                          (GET)
 *   ?part=lookup&code=…            ← {found, name, info}                   (GET)
 *   ?part=linked&code=…            ← {found, …}                            (GET)
 *   ?part=search&term=…            ← {rows}                                (GET)
 *   ?part=search_in_sheets&term=…  ← {rows}                                (GET)
 *
 * مرحلة (أ): القراءة (GET) بس. عمليات الكتابة (بدء/إنهاء الجلسة، إضافة المحاضر،
 * إدخال الجرد، المراجعة، الحذف، والاعتماد) مؤجلة لمرحلة قادمة منعاً لأي تأثير مالي/مخزني.
 * الشاشة (stocktake.php) والـAPI بينادوا نفس دوال stocktake_lib.php بالحرف.
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

// نفس حماية الصفحة بالحرف: require_permission('stocktake')
api_require('stocktake');

require_once __DIR__ . '/stocktake_lib.php';

$u     = current_user();
$uid   = (int) ($u['id'] ?? 0);
$owner = is_owner();
$part  = (string) ($_GET['part'] ?? ($_POST['part'] ?? ''));

// ===== القراءة — GET بس =====
switch ($part) {
    case 'session':
        api_method('GET');
        $session = stk_active_session();
        api_ok([
            'session'  => $session,
            'branches' => stk_branches_list(),
            'places'   => stk_places_list(),
            'users'    => $owner ? stk_users_list() : [],
        ]);

    case 'sheets':
        api_method('GET');
        $session   = stk_active_session();
        $sessionId = isset($_GET['session_id']) ? (int) $_GET['session_id'] : ($session ? (int) $session['id'] : 0);
        if ($sessionId <= 0) {
            api_ok(['sheets' => [], 'sheet_groups' => []]);
        }
        $sheets = stk_active_sheets($sessionId);
        api_ok([
            'sheets'       => $sheets,
            'sheet_groups' => stk_group_sheets($sheets),
        ]);

    case 'sheet':
        api_method('GET');
        $sheetId = (int) ($_GET['id'] ?? $_GET['sheet_id'] ?? $_GET['sheet'] ?? 0);
        if ($sheetId <= 0) {
            api_fail('رقم المحضر مطلوب', 422, 'invalid');
        }
        $openSheet = stk_get_sheet($sheetId);
        if (!$openSheet) {
            api_fail('المحضر غير موجود', 404, 'not_found');
        }
        $lines      = stk_sheet_lines($sheetId);
        $canReview  = stk_can_review($openSheet, $u);
        $canEdit    = stk_can_edit($openSheet, $u);
        $comparison = null;
        $reviewMode = ($_GET['mode'] ?? '') === 'review';
        $compareReq = !empty($_GET['compare']);
        if ($compareReq || $reviewMode || ($openSheet['status'] ?? '') === 'reviewing' || ($openSheet['status'] ?? '') === 'done') {
            $comparison = stk_compare_sheet($sheetId);
        }
        api_ok([
            'sheet'       => $openSheet,
            'lines'       => $lines,
            'can_review'  => $canReview,
            'can_edit'    => $canEdit,
            'comparison'  => $comparison,
        ]);

    case 'lookup':
        api_method('GET');
        $code = trim((string) ($_GET['code'] ?? $_GET['lookup'] ?? ''));
        if ($code === '') {
            api_fail('الكود مطلوب', 422, 'invalid');
        }
        $info = stk_lookup_code($code);
        if (!$info) {
            api_ok(['found' => false, 'name' => null]);
        }
        api_ok(['found' => true, 'name' => $info['name'], 'info' => $info]);

    case 'linked':
        api_method('GET');
        $code = trim((string) ($_GET['code'] ?? $_GET['linked'] ?? ''));
        if ($code === '') {
            api_fail('الكود مطلوب', 422, 'invalid');
        }
        $res = stk_linked_codes($code);
        if (!$res) {
            api_ok(['found' => false]);
        }
        api_ok(['found' => true] + $res);

    case 'search':
        api_method('GET');
        $term = (string) ($_GET['term'] ?? $_GET['search'] ?? '');
        api_ok(['rows' => stk_search_by_name($term)]);

    case 'search_in_sheets':
        api_method('GET');
        $term      = trim((string) ($_GET['term'] ?? $_GET['q'] ?? ''));
        $session   = stk_active_session();
        $sessionId = isset($_GET['session_id']) ? (int) $_GET['session_id'] : ($session ? (int) $session['id'] : 0);
        if ($sessionId <= 0 || $term === '') {
            api_ok(['rows' => []]);
        }
        api_ok(['rows' => stk_search_in_sheets($sessionId, $term)]);
}

// ===== عمليات الكتابة — POST بس، بـCSRF ومحمي من التكرار =====
$writes = [
    'add_sheet',
    'add_line',
    'delete_line',
    'start_review',
    'set_review',
    'set_review_qty',
    'set_final',
    'set_final_bulk',
    'finalize',
    // بريف ١٦٤ب: عمليات المالك
    'end_session',
    'reopen_session',
    'delete_sheet',
    'reassign',
    'change_place',
    'reopen_review',
];

if (!in_array($part, $writes, true)) {
    api_fail('جزء غير معروف (part)', 400, 'bad_request');
}

api_method('POST');
api_csrf();
api_idempotent();

$in = api_input();

switch ($part) {
    case 'add_sheet':
        $session = stk_active_session();
        if (!$session) {
            api_fail('لا توجد جلسة جرد نشطة', 422, 'no_active_session');
        }
        $branchId = (int) ($in['branch_id'] ?? ($_POST['branch_id'] ?? 0));
        $place    = trim((string) ($in['place_note'] ?? ($_POST['place_note'] ?? '')));
        if ($owner && !empty($in['place_custom'] ?? $_POST['place_custom'] ?? '')) {
            $place = trim((string) ($in['place_custom'] ?? $_POST['place_custom']));
        }
        $res = stk_create_sheet((int)$session['id'], $branchId ?: null, $place, $uid);
        api_ok([
            'sheet_id'  => $res['sheet_id'],
            'duplicate' => $res['duplicate'],
        ]);

    case 'add_line':
        $sheetId = (int) ($in['sheet_id'] ?? ($_POST['sheet_id'] ?? 0));
        $code    = trim((string) ($in['code'] ?? ($_POST['code'] ?? '')));
        $qty     = (float) ($in['qty'] ?? ($_POST['qty'] ?? 0));
        $sheet   = stk_load_sheet_row($sheetId);
        if (!$sheet || !stk_can_edit($sheet, $u)) {
            api_fail('غير مسموح: المحضر ده مقترن بصاحبه فقط', 403, 'forbidden');
        }
        try {
            $session = stk_active_session();
            $res = stk_add_line($sheetId, $code, $qty, $session ? (int)$session['id'] : null);
        } catch (RuntimeException $codeEx) {
            if (mb_strpos($codeEx->getMessage(), 'غير موجود') !== false) {
                api_fail('الصنف غير موجود', 422, 'not_found', ['code' => $code]);
            }
            api_fail($codeEx->getMessage(), 422, 'invalid');
        } catch (InvalidArgumentException $qtyEx) {
            api_fail($qtyEx->getMessage(), 422, 'bad_qty', ['qty' => $qty]);
        }
        $ll = stk_sheet_lines($sheetId);
        api_ok([
            'ok'     => true,
            'merged' => $res['merged'],
            'name'   => $res['name'],
            'lines'  => $ll,
        ]);

    case 'delete_line':
        $lineId     = (int) ($in['line_id'] ?? ($_POST['line_id'] ?? 0));
        $sheetId    = (int) ($in['sheet_id'] ?? ($_POST['sheet_id'] ?? 0));
        $fromReview = !empty($in['from_review'] ?? $_POST['from_review'] ?? 0);
        $sheet      = stk_load_sheet_row($sheetId);
        $allowed    = $fromReview ? stk_can_review($sheet, $u) : stk_can_edit($sheet, $u);
        if (!$sheet || !$allowed) {
            api_fail('غير مسموح بحذف الصنف', 403, 'forbidden');
        }
        stk_delete_line($lineId, $sheetId);
        api_ok([
            'line_id'  => $lineId,
            'sheet_id' => $sheetId,
        ]);

    case 'start_review':
        $sheetId = (int) ($in['sheet_id'] ?? ($_POST['sheet_id'] ?? 0));
        $sheet   = stk_load_sheet_row($sheetId);
        if (!$sheet) {
            api_fail('المحضر غير موجود', 404, 'not_found');
        }
        if (!$owner && (int)$u['id'] === (int)$sheet['counted_by']) {
            api_fail('مينفعش تراجع محضرك بنفسك — لازم يوزر تاني يراجعه.', 422, 'self_review');
        }
        stk_start_review($sheetId, $uid);
        api_ok([
            'sheet_id' => $sheetId,
            'status'   => 'reviewing',
        ]);

    case 'set_review':
        $sheetId = (int) ($in['sheet_id'] ?? ($_POST['sheet_id'] ?? 0));
        $code    = trim((string) ($in['code'] ?? ($_POST['code'] ?? '')));
        $qty     = (float) ($in['review_qty'] ?? ($_POST['review_qty'] ?? 0));
        $sheet   = stk_load_sheet_row($sheetId);
        if (!$sheet || !stk_can_review($sheet, $u)) {
            api_fail('غير مسموح: المراجعة لصاحب المحضر والمراجع فقط', 403, 'forbidden');
        }
        try {
            $res = stk_add_review($sheetId, $code, $qty);
        } catch (RuntimeException $codeEx) {
            if (mb_strpos($codeEx->getMessage(), 'غير موجود') !== false) {
                api_fail('الصنف غير موجود', 422, 'not_found', ['code' => $code]);
            }
            api_fail($codeEx->getMessage(), 422, 'invalid');
        } catch (InvalidArgumentException $qtyEx) {
            api_fail($qtyEx->getMessage(), 422, 'bad_qty', ['qty' => $qty]);
        }
        api_ok([
            'new_item' => $res['new_item'],
            'name'     => $res['name'],
        ]);

    case 'set_review_qty':
        $sheetId = (int) ($in['sheet_id'] ?? ($_POST['sheet_id'] ?? 0));
        $lineId  = (int) ($in['line_id'] ?? ($_POST['line_id'] ?? 0));
        $qty     = (float) ($in['review_qty'] ?? ($_POST['review_qty'] ?? 0));
        $sheet   = stk_load_sheet_row($sheetId);
        if (!$sheet || !stk_can_review($sheet, $u)) {
            api_fail('غير مسموح', 403, 'forbidden');
        }
        try {
            $reviewBase = stk_update_review_qty($sheetId, $lineId, $qty);
        } catch (InvalidArgumentException $qtyEx) {
            api_fail($qtyEx->getMessage(), 422, 'bad_qty');
        } catch (Throwable $ex) {
            api_fail($ex->getMessage(), 422, 'invalid');
        }
        api_ok([
            'line_id'    => $lineId,
            'sheet_id'   => $sheetId,
            'review_qty' => $reviewBase,
        ]);

    case 'set_final':
        $sheetId = (int) ($in['sheet_id'] ?? ($_POST['sheet_id'] ?? 0));
        $lineId  = (int) ($in['line_id'] ?? ($_POST['line_id'] ?? 0));
        $qty     = (float) ($in['final_qty'] ?? ($_POST['final_qty'] ?? 0));
        $sheet   = stk_load_sheet_row($sheetId);
        if (!$sheet || !stk_can_review($sheet, $u)) {
            api_fail('غير مسموح: التعديل لصاحب المحضر والمراجع فقط', 403, 'forbidden');
        }
        try {
            stk_set_final_qty($sheetId, $lineId, $qty, $uid);
        } catch (RuntimeException $ex) {
            api_fail($ex->getMessage(), 404, 'not_found');
        }
        api_ok([
            'line_id'   => $lineId,
            'sheet_id'  => $sheetId,
            'final_qty' => $qty,
        ]);

    case 'set_final_bulk':
        $sheetId = (int) ($in['sheet_id'] ?? ($_POST['sheet_id'] ?? 0));
        $sheet   = stk_load_sheet_row($sheetId);
        if (!$sheet || !stk_can_review($sheet, $u)) {
            api_fail('غير مسموح', 403, 'forbidden');
        }
        $rawFinals = $in['final'] ?? ($_POST['final'] ?? []);
        if (is_string($rawFinals)) {
            $rawFinals = json_decode($rawFinals, true) ?: [];
        }
        if (!is_array($rawFinals)) {
            api_fail('بيانات غير صالحة', 400, 'bad_request');
        }
        try {
            stk_set_final_bulk($sheetId, $rawFinals, $uid);
        } catch (RuntimeException $ex) {
            api_fail($ex->getMessage(), 404, 'not_found');
        }
        if ($owner) {
            stk_finalize_sheet($sheetId, $uid);
        }
        api_ok([
            'sheet_id'  => $sheetId,
            'finalized' => $owner,
        ]);

    case 'finalize':
        $sheetId = (int) ($in['sheet_id'] ?? ($_POST['sheet_id'] ?? 0));
        $sheet   = stk_load_sheet_row($sheetId);
        if (!$sheet || !stk_can_review($sheet, $u)) {
            api_fail('غير مسموح: الإنهاء لصاحب المحضر والمراجع فقط', 403, 'forbidden');
        }
        stk_finalize_sheet($sheetId, $uid);
        api_ok([
            'sheet_id' => $sheetId,
            'status'   => 'done',
        ]);

    case 'end_session':
        if (!$owner) {
            api_fail('غير مصرّح — للمالك فقط', 403, 'forbidden');
        }
        $session = stk_active_session();
        if (!$session) {
            api_fail('لا توجد جلسة جرد نشطة', 422, 'no_active_session');
        }
        stk_end_session((int)$session['id'], $uid);
        api_ok([
            'session_id' => (int)$session['id'],
            'status'     => 'closed',
        ]);

    case 'reopen_session':
        if (!$owner) {
            api_fail('غير مصرّح — للمالك فقط', 403, 'forbidden');
        }
        try {
            $reopenedId = stk_reopen_session();
        } catch (RuntimeException $e) {
            api_fail($e->getMessage(), 422, 'cannot_reopen');
        }
        api_ok([
            'session_id' => $reopenedId,
            'status'     => 'active',
        ]);

    case 'delete_sheet':
        if (!$owner) {
            api_fail('غير مصرّح — للمالك فقط', 403, 'forbidden');
        }
        $sheetId = (int) ($in['sheet_id'] ?? ($_POST['sheet_id'] ?? 0));
        if ($sheetId <= 0) {
            api_fail('معرّف المحضر مطلوب', 422, 'invalid_sheet');
        }
        try {
            stk_delete_sheet($sheetId);
        } catch (RuntimeException $e) {
            api_fail($e->getMessage(), 422, 'cannot_delete');
        }
        api_ok([
            'sheet_id' => $sheetId,
            'deleted'  => true,
        ]);

    case 'reassign':
        if (!$owner) {
            api_fail('غير مصرّح — للمالك فقط', 403, 'forbidden');
        }
        $sheetId = (int) ($in['sheet_id'] ?? ($_POST['sheet_id'] ?? 0));
        $field   = trim((string) ($in['field'] ?? ($_POST['field'] ?? '')));
        $newUser = (int) ($in['user_id'] ?? ($_POST['user_id'] ?? 0)) ?: null;
        if (!in_array($field, ['counted_by', 'reviewed_by'], true)) {
            api_fail('حقل غير صالح', 422, 'invalid_field');
        }
        if ($sheetId <= 0) {
            api_fail('معرّف المحضر مطلوب', 422, 'invalid_sheet');
        }
        stk_reassign_sheet($sheetId, $field, $newUser);
        api_ok([
            'sheet_id' => $sheetId,
            'field'    => $field,
            'user_id'  => $newUser,
        ]);

    case 'change_place':
        if (!$owner) {
            api_fail('غير مصرّح — للمالك فقط', 403, 'forbidden');
        }
        $sheetId = (int) ($in['sheet_id'] ?? ($_POST['sheet_id'] ?? 0));
        $place   = trim((string) ($in['place_note'] ?? ($_POST['place_note'] ?? '')));
        if ($place === '__custom__') {
            $place = trim((string) ($in['place_custom'] ?? ($_POST['place_custom'] ?? '')));
        }
        if ($place === '') {
            api_fail('اكتب المكان', 422, 'empty_place');
        }
        if ($sheetId <= 0) {
            api_fail('معرّف المحضر مطلوب', 422, 'invalid_sheet');
        }
        stk_change_sheet_place($sheetId, $place);
        api_ok([
            'sheet_id' => $sheetId,
            'place'    => $place,
        ]);

    case 'reopen_review':
        if (!$owner) {
            api_fail('غير مصرّح — للمالك فقط', 403, 'forbidden');
        }
        $sheetId = (int) ($in['sheet_id'] ?? ($_POST['sheet_id'] ?? 0));
        if ($sheetId <= 0) {
            api_fail('معرّف المحضر مطلوب', 422, 'invalid_sheet');
        }
        try {
            stk_reopen_review($sheetId);
        } catch (RuntimeException $e) {
            api_fail($e->getMessage(), 422, 'cannot_reopen');
        }
        api_ok([
            'sheet_id' => $sheetId,
            'reopened' => true,
        ]);
}

