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
$part  = (string) ($_GET['part'] ?? '');

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

// ===== مرحلة (أ): لا توجد أي عمليات كتابة مسموحة =====
$writes = [];

if (!in_array($part, $writes, true)) {
    api_fail('جزء غير معروف', 400, 'bad_request');
}

