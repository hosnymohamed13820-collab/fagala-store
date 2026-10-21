<?php
/**
 * ashraqa_invoices_api.php — واجهة برمجية لفواتير فرع إشراقة (بريف ١٦٣)
 * نظام الفجالة ستور المالي
 *
 * مخصصة لمراجعة وتسعير فواتير فرع إشراقة (branch_id = 5، عاشور).
 *
 * الأجزاء المدعومة:
 *   ?part=list                                          ← {invoices, count}      (GET)
 *   ?part=invoice&invoice=<no>[&suffix=<s>]                                      (GET)
 *   ?part=item_search&term=<t>                                                   (GET)
 *   ?part=item_history&code=<c>[&item_id=<i>]                                    (GET)
 *   ?part=set_status (POST)           ← {distribution_id, status}
 *   ?part=save_price (POST)           ← {distribution_id, item_code, price}
 *   ?part=save_prices_bulk (POST)      ← {distribution_id, count}
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

require_once __DIR__ . '/ashraqa_invoices_lib.php';

// الحماية: فرع إشراقة الثابت (٥)
if (!br_inv_can_view(ASHRAQA_BRANCH_ID)) {
    api_fail('ليس لديك صلاحية للوصول إلى فواتير إشراقة', 403, 'forbidden');
}

$part = (string) ($_GET['part'] ?? ($_POST['part'] ?? ''));

// ===== القراءة — GET بس =====
switch ($part) {
    case 'list':
        api_method('GET');
        $distData = ash_inv_distribution_data();
        $statusMap = ash_inv_status_map();
        $invoices = ash_inv_list_data($distData, $statusMap);
        api_ok(br_inv_strip_supplier([
            'invoices' => $invoices,
            'count'    => count($invoices),
        ]));

    case 'invoice':
        api_method('GET');
        $openId = trim((string) ($_GET['invoice'] ?? ''));
        $openSuf = isset($_GET['suffix']) ? (string) $_GET['suffix'] : null;
        if ($openId === '') {
            api_fail('رقم الفاتورة مطلوب (invoice)', 400, 'bad_request');
        }
        $distData = ash_inv_distribution_data();
        $detail = ash_inv_invoice_detail($openId, $openSuf, $distData);
        if ($detail === null) {
            api_fail('الفاتورة غير موجودة أو ليس لديك صلاحية لعرضها', 403, 'forbidden');
        }
        api_ok(br_inv_strip_supplier($detail));

    case 'item_search':
        api_method('GET');
        require_once __DIR__ . '/item_lookup_lib.php';
        if (!ilk_ready()) {
            api_fail('المزامنة لسه ما وصلتش — استنى شوية وجرّب تاني.', 503, 'not_ready');
        }
        $term = (string) ($_GET['term'] ?? '');
        api_ok(br_inv_strip_supplier(['rows' => ilk_search($term, 15)]));

    case 'item_history':
        api_method('GET');
        require_once __DIR__ . '/item_lookup_lib.php';
        if (!ilk_ready()) {
            api_fail('المزامنة لسه ما وصلتش — استنى شوية وجرّب تاني.', 503, 'not_ready');
        }
        $searchCode = trim((string) ($_GET['code'] ?? ''));
        $itemId = (int) ($_GET['item_id'] ?? 0);
        $res = ilk_item_history($searchCode, $itemId);
        $distData = ash_inv_distribution_data();
        $filtered = ash_inv_filter_item_history($res, $distData, $searchCode);
        api_ok(br_inv_strip_supplier($filtered));
}

// ===== الكتابة — POST بس، بـCSRF ومحمي من التكرار =====
$writes = ['set_status', 'save_price', 'save_prices_bulk'];

if (!in_array($part, $writes, true)) {
    api_fail('جزء غير معروف (part)', 400, 'bad_request');
}

api_method('POST');
api_csrf();
api_idempotent();

$in = api_input();
$uid = (int) (current_user()['id'] ?? 0);

$distData = ash_inv_distribution_data();
$distId = (int) ($in['distribution_id'] ?? ($_POST['distribution_id'] ?? 0));

// التحقق من أن الفاتورة فيها توزيع لإشراقة
if ($distId <= 0 || !in_array($distId, $distData['doc_nos'], true)) {
    api_fail('مالكش صلاحية على الفاتورة دي', 403, 'forbidden');
}

try {
    switch ($part) {
        case 'set_status':
            $status = (string) ($in['status'] ?? ($_POST['status'] ?? ''));
            if (!in_array($status, ['new', 'entered'], true)) {
                api_fail('حالة غير معروفة', 400, 'bad_request');
            }
            ash_inv_set_status($distId, $status, $uid);
            api_ok(br_inv_strip_supplier([
                'distribution_id' => $distId,
                'status'          => $status,
            ]));

        case 'save_price':
            $itemCode = trim((string) ($in['item_code'] ?? ($_POST['item_code'] ?? '')));
            $price = (float) ($in['price'] ?? ($_POST['price'] ?? 0));
            if ($itemCode === '') {
                api_fail('كود الصنف مطلوب', 400, 'bad_request');
            }
            if ($price < 0) {
                api_fail('السعر لازم يكون موجب', 400, 'bad_request');
            }
            ashraqa_save_price($distId, $itemCode, $price, $uid);
            api_ok(br_inv_strip_supplier([
                'distribution_id' => $distId,
                'item_code'       => $itemCode,
                'price'           => $price,
            ]));

        case 'save_prices_bulk':
            $rawPrices = $in['prices'] ?? ($_POST['prices'] ?? null);
            if (is_string($rawPrices)) {
                $map = json_decode($rawPrices, true);
            } elseif (is_array($rawPrices)) {
                $map = $rawPrices;
            } else {
                $map = null;
            }
            if (!is_array($map) || empty($map)) {
                api_fail('بيانات ناقصة', 400, 'bad_request');
            }
            if (count($map) > 5000) {
                api_fail('عدد الأصناف كبير جداً', 400, 'bad_request');
            }
            $count = ash_inv_save_prices_bulk($distId, $map, $uid);
            api_ok(br_inv_strip_supplier([
                'distribution_id' => $distId,
                'count'           => $count,
            ]));
    }
} catch (InvalidArgumentException $ex) {
    api_fail($ex->getMessage(), 400, 'bad_request');
} catch (Throwable $ex) {
    api_fail('تعذر حفظ البيانات: ' . $ex->getMessage(), 500, 'server_error');
}
