<?php
/**
 * cashier_api.php — واجهة برمجة تقفيل الكاشير ونطاق الفروع (بريف ١٧٦ — موجة ٣)
 * نظام الفجالة ستور المالي
 *
 * عمليات GET (مرحلة أ — قراءة فقط):
 *   ?part=today[&branch=...]                   ← تقفيلات اليوم حسب نطاق المستخدم وإجمالياتها
 *   ?part=archive[&branch=...]                 ← تقفيلات الأرشيف المعلقة
 *   ?part=rejected                             ← التقفيلات المرفوضة القابلة للتعديل
 *   ?part=seq_issues                           ← مشاكل تسلسل الفواتير المعينة للمستخدم
 *   ?part=branches                             ← الفروع المتاحة والمسموح برؤيتها
 *   ?part=all[&branch=...]                     ← الشجرة الكاملة
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

// نفس حماية الصفحة بالحرف
api_require('cashier');

require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/cashier_lib.php';

// التقفيل في المرحلة الحالية قراءة فقط
api_method('GET');

$pdo  = db();
$u    = current_user();
$uid  = (int) $u['id'];
$part = (string) ($_GET['part'] ?? 'today');

$allowedParts = ['today', 'archive', 'rejected', 'seq_issues', 'branches', 'all'];
if (!in_array($part, $allowedParts, true)) {
    api_fail('الجزء المطلوب غير معروف', 400, 'bad_request');
}

$bizDateStr   = cashier_get_business_date();
$allBranches  = cashier_get_all_branches($pdo);
$allowedBranches = cashier_get_allowed_branches($pdo, $u, $allBranches, $bizDateStr);
$isOwner      = is_owner();

$filterBranch = ($isOwner && isset($_GET['branch'])) ? (int)$_GET['branch'] : 0;
if ($filterBranch > 0 && !in_array($filterBranch, $allowedBranches, true)) {
    $filterBranch = 0;
}

$branchTabs = array_values(array_filter(
    $allBranches,
    fn($b) => in_array((int)$b['id'], $allowedBranches, true)
));

try {
    switch ($part) {
        case 'branches':
            api_ok([
                'business_date'    => $bizDateStr,
                'is_owner'         => $isOwner,
                'my_branch_id'     => !empty($u['branch_id']) ? (int) $u['branch_id'] : null,
                'all_branches'     => $allBranches,
                'allowed_branches' => $allowedBranches,
                'branch_tabs'      => $branchTabs,
            ]);

        case 'today':
            $todayShifts = cashier_get_today_shifts($pdo, $allowedBranches, $filterBranch, $isOwner, $bizDateStr);
            $todayTotals = cashier_calc_today_totals($todayShifts);
            $shiftIds    = array_map(fn($s) => (int)$s['id'], $todayShifts);
            $badges      = cashier_get_shift_edit_badges($pdo, $shiftIds);

            api_ok([
                'business_date'    => $bizDateStr,
                'filter_branch'    => $filterBranch,
                'allowed_branches' => $allowedBranches,
                'branch_tabs'      => $branchTabs,
                'today_shifts'     => $todayShifts,
                'totals'           => $todayTotals,
                'edit_badges'      => $badges,
            ]);

        case 'archive':
            $archiveShifts = cashier_get_archive_shifts($pdo, $allowedBranches, $filterBranch, $isOwner, $bizDateStr);
            $shiftIds      = array_map(fn($s) => (int)$s['id'], $archiveShifts);
            $badges        = cashier_get_shift_edit_badges($pdo, $shiftIds);

            api_ok([
                'business_date'  => $bizDateStr,
                'filter_branch'  => $filterBranch,
                'archive_shifts' => $archiveShifts,
                'count'          => count($archiveShifts),
                'edit_badges'    => $badges,
            ]);

        case 'rejected':
            $myRejected = cashier_get_my_rejected($pdo, $uid);
            $shiftIds   = array_map(fn($s) => (int)$s['id'], $myRejected);
            $badges     = cashier_get_shift_edit_badges($pdo, $shiftIds);

            api_ok([
                'rejected_shifts' => $myRejected,
                'count'           => count($myRejected),
                'edit_badges'     => $badges,
            ]);

        case 'seq_issues':
            $mySeqIssues = cashier_get_seq_issues($pdo, $uid);
            api_ok([
                'seq_issues' => $mySeqIssues,
                'count'      => count($mySeqIssues),
            ]);

        case 'all':
            $todayShifts   = cashier_get_today_shifts($pdo, $allowedBranches, $filterBranch, $isOwner, $bizDateStr);
            $todayTotals   = cashier_calc_today_totals($todayShifts);
            $archiveShifts = cashier_get_archive_shifts($pdo, $allowedBranches, $filterBranch, $isOwner, $bizDateStr);
            $myRejected    = cashier_get_my_rejected($pdo, $uid);
            $mySeqIssues   = cashier_get_seq_issues($pdo, $uid);

            $allShiftIds = [];
            foreach ($todayShifts as $ts) $allShiftIds[] = (int)$ts['id'];
            foreach ($archiveShifts as $as) $allShiftIds[] = (int)$as['id'];
            foreach ($myRejected as $mr) $allShiftIds[] = (int)$mr['id'];
            $badges = cashier_get_shift_edit_badges($pdo, $allShiftIds);

            api_ok([
                'business_date'    => $bizDateStr,
                'filter_branch'    => $filterBranch,
                'allowed_branches' => $allowedBranches,
                'branch_tabs'      => $branchTabs,
                'today_shifts'     => $todayShifts,
                'totals'           => $todayTotals,
                'archive_shifts'   => $archiveShifts,
                'rejected_shifts'  => $myRejected,
                'seq_issues'       => $mySeqIssues,
                'edit_badges'      => $badges,
            ]);
    }
} catch (Throwable $e) {
    errlog_exception($e, 'cashier_api');
    api_fail('حدث خطأ أثناء معالجة بيانات الكاشير', 500, 'server_error');
}

