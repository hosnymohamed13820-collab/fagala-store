<?php
/**
 * treasury_api.php — واجهة برمجة الخزينة وكشف الحساب (بريف ١٧٥ — موجة ٣)
 * نظام الفجالة ستور المالي
 *
 * عمليات GET (مرحلة أ — قراءة فقط):
 *   ?part=treasuries                             ← أرصدة الخزائن المركزية والفرعية والكاش الفعلي (حساس 423)
 *   ?part=statement[&treasury=...&from=...&to=...] ← كشف الحساب والإجماليات والرصيد التراكمي (حساس 423)
 *   ?part=meta                                   ← القوائم المساعدة وصلاحيات العمليات (غير حساس 200)
 *   ?part=all[&treasury=...&from=...&to=...]     ← الشجرة الكاملة (حساس 423)
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

// نفس حماية الصفحة بالحرف
api_require('treasury:statement');

require_once __DIR__ . '/sensitive_lib.php';
require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/treasury_lib.php';

// الخزينة تدعم القراءة وتلوين الصفوف
api_method('GET', 'POST');

$pdo = db();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'POST') {
    api_csrf();
    $input = api_input();
    $action = (string) ($input['action'] ?? $_POST['action'] ?? $_GET['action'] ?? 'mark');

    if ($action !== 'mark') {
        api_fail('العملية المطلوبة غير معروفة', 400, 'bad_request');
    }

    $rawIds = $input['ids'] ?? $_POST['ids'] ?? [];
    if (is_string($rawIds)) {
        $rawIds = explode(',', $rawIds);
    }
    if (!is_array($rawIds)) {
        api_fail('مافيش قيود محددة', 400, 'no_ids');
    }

    $color = (string) ($input['color'] ?? $_POST['color'] ?? '');
    $uid = (int) current_user()['id'];

    try {
        $res = treasury_mark_entries($pdo, $rawIds, $color, $uid);
        if (!$res['ok']) {
            api_fail($res['error'], $res['http'] ?? 400, $res['code'] ?? 'bad_request');
        }

        api_ok([
            'ids'            => $res['ids'],
            'color'          => $res['color'],
            'marked_by'      => $uid,
            'marked_by_name' => (string) (current_user()['full_name'] ?? current_user()['username'] ?? ''),
            'marked_at'      => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        errlog_exception($e, 'treasury_api');
        api_fail('حدث خطأ أثناء حفظ ألوان القيود', 500, 'server_error');
    }
}

$part = (string) ($_GET['part'] ?? 'all');

$allowedParts = ['treasuries', 'statement', 'meta', 'all', 'marks'];
if (!in_array($part, $allowedParts, true)) {
    api_fail('الجزء المطلوب غير معروف', 400, 'bad_request');
}

// الأجزاء الحساسة تتطلب فك القفل (HTTP 423)
$sensitiveParts = ['treasuries', 'statement', 'all'];
if (in_array($part, $sensitiveParts, true) && !sens_unlocked()) {
    api_fail('الأرقام الحساسة مقفولة', 423, 'locked');
}

$periodInfo    = treasury_parse_period($_GET['from'] ?? null, $_GET['to'] ?? null);
$stFrom        = $periodInfo['from'];
$stTo          = $periodInfo['to'];
$selectedParam = (string) ($_GET['treasury'] ?? 'all');
$stMaxRows     = 5000;

try {
    switch ($part) {
        case 'marks':
            $rawIds = $_GET['ids'] ?? [];
            if (is_string($rawIds)) {
                $rawIds = explode(',', $rawIds);
            }
            $entryIds = is_array($rawIds) ? $rawIds : [];
            $treId = isset($_GET['treasury']) && ctype_digit((string)$_GET['treasury']) ? (int)$_GET['treasury'] : null;
            $marks = treasury_get_marks($pdo, $entryIds, $treId, $_GET['from'] ?? null, $_GET['to'] ?? null);
            api_ok(['marks' => $marks]);
        case 'meta':
            $auxLists = treasury_get_auxiliary_lists($pdo);
            api_ok([
                'period' => $periodInfo,
                'can'    => [
                    'operate'    => can('treasury:operate'),
                    'reverse'    => can('treasury:reverse'),
                    'edit_entry' => is_owner(),
                ],
                'lists'  => $auxLists,
            ]);

        case 'treasuries':
            $treData = treasury_get_treasuries($pdo);
            api_ok($treData);

        case 'statement':
            $treData = treasury_get_treasuries($pdo);
            $stmtData = treasury_get_statement($pdo, $selectedParam, $stFrom, $stTo, $treData['treasuries'], $stMaxRows, ($_GET['show_cancelled'] ?? '') === '1');
            api_ok([
                'period'    => $periodInfo,
                'statement' => $stmtData,
            ]);

        case 'all':
            $treData   = treasury_get_treasuries($pdo);
            $stmtData  = treasury_get_statement($pdo, $selectedParam, $stFrom, $stTo, $treData['treasuries'], $stMaxRows, ($_GET['show_cancelled'] ?? '') === '1');
            $auxLists  = treasury_get_auxiliary_lists($pdo);

            api_ok([
                'period'     => $periodInfo,
                'treasuries' => $treData,
                'statement'  => $stmtData,
                'can'        => [
                    'operate'    => can('treasury:operate'),
                    'reverse'    => can('treasury:reverse'),
                    'edit_entry' => is_owner(),
                ],
                'lists'      => $auxLists,
            ]);
    }
} catch (Throwable $e) {
    errlog_exception($e, 'treasury_api');
    api_fail('حدث خطأ أثناء معالجة بيانات الخزينة', 500, 'server_error');
}
