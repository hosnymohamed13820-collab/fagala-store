<?php
/**
 * receiving_diffs_api.php — داتا «فروقات الاستلام بين الفروع» (بريف ١٢٦)
 * نظام الفجالة ستور المالي
 *
 * نفس داتا receiving_diffs.php بالظبط — الصفحة والـapi بينادوا
 * receiving_diffs_data() في receiving_flow_lib.php، فمفيش رقم بيتصرف
 * في مكان غير اللي بيتعرض في التاني.
 *
 *   ?part=list                             ← {available_list}
 *   ?part=diffs&g=…   أو   ?part=diffs&d=… ← {group_id, distribution_id, is_group,
 *                                              title, branches, receipts_by_branch, items}
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

api_method('GET');

// نفس حماية الصفحة بالظبط: require_permission('receiving') في receiving_diffs.php
api_require('receiving');

require_once __DIR__ . '/receiving_flow_lib.php';

// 🔴 نفس شرط $canView في الصفحة بالحرف (سطور ١٥–٣١) — من غيره موظف
//    من غير صلاحية كان هيشوف الداتا من الـapi وهو ممنوع من الصفحة.
$user = current_user();
$uid  = (int) ($user['id'] ?? 0);
$userBranchId = !empty($user['branch_id']) ? (int) $user['branch_id'] : null;
$isOwner = is_owner();
$supervisorId = (int) setting('receiving_supervisor_id', '0');
$isSupervisor = ($supervisorId > 0 && $uid === $supervisorId);

$canView = $isOwner || $isSupervisor || can('receiving:diffs') || $userBranchId !== null;
if (!$canView) {
    api_fail('مالكش صلاحية', 403, 'forbidden');
}

$ctx = [
    'uid'            => $uid,
    'user_branch_id' => $userBranchId,
    'is_owner'       => $isOwner,
    'is_supervisor'  => $isSupervisor,
        // الجزء نفسه بالحرف — can() بترجّع true لأي حد معاه موديول receiving كامل
        'can_diffs'      => in_array('receiving:diffs', $_SESSION['permissions'] ?? [], true),
    'can_view'       => $canView,
];

// نفس الفلاتر اللي الصفحة بتقراها من $_GET (g و d)
$groupId = isset($_GET['g']) ? (int) $_GET['g'] : 0;
$distId  = isset($_GET['d']) ? (int) $_GET['d'] : 0;

$part = (string) ($_GET['part'] ?? '');

switch ($part) {
    case 'list':
        // قايمة الفواتير/المجموعات المفتوح لها استلام (زي $availableList في الصفحة)
        $data = receiving_diffs_data($ctx, ['group_id' => 0, 'distribution_id' => 0]);
        api_ok(['available_list' => $data['available_list']]);

    case 'diffs':
        // جدول الفروقات لسياق مختار (زي ما الصفحة بترسم بعد الفلتر)
        $data = receiving_diffs_data($ctx, ['group_id' => $groupId, 'distribution_id' => $distId]);
        api_ok([
            'group_id'           => $data['group_id'],
            'distribution_id'    => $data['distribution_id'],
            'is_group'           => $data['is_group'],
            'title'              => $data['title'],
            'branches'           => $data['branches'],
            'receipts_by_branch' => $data['receipts_by_branch'],
            'items'              => $data['items'],
        ]);

    default:
        api_fail('جزء غير معروف', 400, 'bad_request');
}
