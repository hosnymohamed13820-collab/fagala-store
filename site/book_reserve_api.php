<?php
/**
 * book_reserve_api.php — داتا «حجز الكتب» (بريف ١٤٣)
 * نظام الفجالة ستور المالي
 *
 *   ?part=search&term=…      ← {rows}                     (GET)
 *   ?part=view               ← {reservations, viewing?}   (GET)
 *   ?part=save        (POST) ← {id, msg}                  (customer_name, phone, by, deposit, branch_id, lines)
 *   ?part=set_status  (POST) ← {id, status}              (res, status)
 *   ?part=reprint     (POST) ← {id}                      (res)
 *
 * الشاشة (book_reserve.php) زي ما هي — الـAPI ده بينادي نفس دوال
 * book_lib.php و book_receipt_lib.php بالحرف، وبنفس النطاق وحساب الفرع.
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

// نفس حماية الصفحة بالظبط: require_permission('shortage') في book_reserve.php:20
api_require('shortage');

require_once __DIR__ . '/book_lib.php';
require_once __DIR__ . '/book_receipt_lib.php';

$uid      = (int) (current_user()['id'] ?? 0);
$myBranch = (int) (current_user()['branch_id'] ?? 0);

// نطاق الحجوزات — نفس book_reserve.php:28 بالحرف
$resScope = book_res_scope(is_owner(), $myBranch);

$part = (string) ($_GET['part'] ?? '');

// ===== القراءة — GET بس =====
switch ($part) {
    case 'search':
        api_method('GET');
        api_ok(['rows' => books_search((string) ($_GET['term'] ?? ''), 40)]);

    case 'view':
        api_method('GET');
        $statusFilt = (string) ($_GET['st'] ?? 'open');
        $limit      = isset($_GET['limit']) ? min(100, max(1, (int) $_GET['limit'])) : 60;
        $recent     = book_reservations_list($statusFilt, $limit, $resScope, $uid);

        $viewId  = (int) ($_GET['view'] ?? $_GET['id'] ?? 0);
        $viewing = null;
        if ($viewId > 0) {
            $chk = book_reservation_get($viewId);
            if (!$chk || !book_res_can_access($chk['res'], $resScope, $uid)) {
                api_fail('الحجز غير موجود أو مالكش صلاحية عليه', 404, 'not_found');
            }
            $viewing = $chk;
            $viewing['receipt'] = book_receipt_status($viewId);
        }

        api_ok([
            'reservations' => $recent,
            'rows'         => $recent,
            'viewing'      => $viewing,
        ]);
}

// ===== الكتابة — POST بس، بـCSRF ومحمي من التكرار =====
$writes = ['save', 'set_status', 'reprint'];

if (!in_array($part, $writes, true)) {
    api_fail('جزء غير معروف', 400, 'bad_request');
}

api_method('POST');
api_csrf();
api_idempotent();

$in = api_input();

// الدوال بترمي RuntimeException برسايل للمستخدم — بنحوّلها 422
try {
    switch ($part) {
        case 'save':
            $resIn = (array) ($in['res'] ?? $in);
            // 🔴 موظف الفرع بيحجز لفرعه بس — مهما الواجهة بعتت إيه
            if ($resScope !== null) {
                $resIn['branch_id'] = $resScope;
            }
            $lines = (array) ($in['lines'] ?? []);
            $id = book_reservation_create($resIn, $lines, $uid);

            // الطباعة ممنوع تفشّل الحفظ — زي book_reserve.php:66-70
            try {
                book_receipt_enqueue($id, $uid);
            } catch (Throwable $pex) {
                errlog_record('warning', 'الإيصال ما اتحطش في الطابور: ' . $pex->getMessage(), __FILE__, __LINE__);
            }

            api_ok([
                'id'  => $id,
                'msg' => 'اتحفظ الحجز رقم ' . $id,
            ]);

        case 'set_status':
            $sid    = (int) ($in['res'] ?? $in['id'] ?? $in['reservation_id'] ?? 0);
            $status = (string) ($in['status'] ?? '');
            $chk    = book_reservation_get($sid);
            if (!$chk || !book_res_can_access($chk['res'], $resScope, $uid)) {
                api_fail('الحجز غير موجود أو مالكش صلاحية عليه', 403, 'forbidden');
            }
            if (!in_array($status, ['open', 'delivered', 'cancelled'], true)) {
                api_fail('حالة غير صالحة', 422, 'invalid');
            }
            book_reservation_set_status($sid, $status);
            api_ok([
                'id'     => $sid,
                'status' => $status,
            ]);

        case 'reprint':
            $rid = (int) ($in['res'] ?? $in['id'] ?? $in['reservation_id'] ?? 0);
            $chk = book_reservation_get($rid);
            if (!$chk || !book_res_can_access($chk['res'], $resScope, $uid)) {
                $resBranch = $chk ? (int) ($chk['res']['branch_id'] ?? 0) : 0;
                if ($chk) {
                    errlog_record('warning', 'محاولة إعادة طباعة حجز فرع تاني — حجز ' . $rid
                        . ' فرعه ' . $resBranch . ' واليوزر فرعه ' . $myBranch, __FILE__, __LINE__);
                }
                api_fail('الحجز غير موجود أو مالكش صلاحية عليه', 403, 'forbidden');
            }

            try {
                book_receipt_enqueue($rid, $uid);
            } catch (Throwable $pex) {
                errlog_record('warning', 'إعادة الطباعة فشلت: ' . $pex->getMessage(), __FILE__, __LINE__);
                api_fail('فشلت إعادة الطباعة', 500, 'print_failed');
            }

            api_ok([
                'id' => $rid,
            ]);
    }
} catch (RuntimeException $e) {
    api_fail($e->getMessage(), 422, 'invalid');
}

