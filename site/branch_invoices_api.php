<?php
/**
 * branch_invoices_api.php — واجهة برمجية لفواتير الفروع الأربعة (بريف ١٦٢)
 * نظام الفجالة ستور المالي
 *
 * الفروع الأربعة: ١ (بونجور) · ٢ (اي بي سي) · ٣ (الفجالة) · ٤ (دجلة)
 * (إشراقة فرع ٥ ليها صفحة وبريف لوحدها)
 *
 * الأجزاء المدعومة:
 *   ?part=pages                       ← {pages}                (GET)
 *   ?part=list&branch=<id>            ← {invoices, count}      (GET)
 *   ?part=invoice&branch=<id>&invoice=<no>[&suffix=<s>]        (GET)
 *   ?part=item_search&branch=<id>&term=<t>                     (GET)
 *   ?part=item_history&branch=<id>&code=<c>[&item_id=<i>]      (GET)
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

require_once __DIR__ . '/branch_invoices_lib.php';

$part = (string) ($_GET['part'] ?? ($_POST['part'] ?? ''));

// استخراج وفحص معرف الفرع (branch)
$rawBranch = $_GET['branch'] ?? ($_POST['branch'] ?? null);
if ($rawBranch === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $inCheck = api_input();
    if (isset($inCheck['branch'])) {
        $rawBranch = $inCheck['branch'];
    }
}

$branchId = ($rawBranch !== null && is_numeric($rawBranch)) ? (int) $rawBranch : null;

// التحقق من رقم الفرع: ١، ٢، ٣، ٤ فقط (أي رقم تاني ← 400)
if ($rawBranch !== null && !in_array($branchId, [1, 2, 3, 4], true)) {
    api_fail('معرف الفرع غير صالح (١، ٢، ٣، ٤ فقط)', 400, 'bad_request');
}

// جزء pages يسمح بعدم تمرير الفرع لعرض كل الفروع المتاحة للمستخدم
if ($part !== 'pages') {
    if ($branchId === null) {
        api_fail('معرف الفرع مطلوب (branch)', 400, 'bad_request');
    }
    // فحص الصلاحية والنطاق لنفس الفرع
    if (!br_inv_can_view($branchId)) {
        api_fail('ليس لديك صلاحية للوصول إلى هذا الفرع', 403, 'forbidden');
    }
}

// ===== القراءة — GET بس =====
switch ($part) {
    case 'pages':
        api_method('GET');
        $pages = [];
        foreach (br_inv_all_pages() as $b) {
            if (br_inv_can_view($b['id'])) {
                $pages[] = $b;
            }
        }
        api_ok(br_inv_strip_supplier(['pages' => array_values($pages)]));

    case 'list':
        api_method('GET');
        $distData = br_inv_distribution_data($branchId);
        $statusMap = br_inv_status_map($branchId);
        $invoices = br_inv_list_data($branchId, $distData, $statusMap);
        api_ok(br_inv_strip_supplier([
            'invoices' => br_inv_public_invoice_rows($invoices),
            'count'    => count($invoices),
        ]));

    case 'invoice':
        api_method('GET');
        $openId = trim((string) ($_GET['invoice'] ?? ''));
        $openSuf = isset($_GET['suffix']) ? (string) $_GET['suffix'] : null;
        if ($openId === '') {
            api_fail('رقم الفاتورة مطلوب (invoice)', 400, 'bad_request');
        }
        $distData = br_inv_distribution_data($branchId);
        $detail = br_inv_invoice_detail($branchId, $openId, $openSuf, $distData);
        if ($detail === null) {
            api_fail('الفاتورة غير موجودة أو ليس لديك صلاحية لعرضها', 403, 'forbidden');
        }
        $detail['header'] = br_inv_public_invoice_rows([$detail['header']])[0];
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
        $distData = br_inv_distribution_data($branchId);
        $filtered = br_inv_filter_item_history($res, $branchId, $distData, $searchCode);
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

$distData = br_inv_distribution_data($branchId);
$distId = (int) ($in['distribution_id'] ?? ($_POST['distribution_id'] ?? 0));

// التحقق من أن الفاتورة فيها توزيع للفرع
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
            br_inv_set_status($branchId, $distId, $status, $uid);
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
            br_inv_save_price($branchId, $distId, $itemCode, $price, $uid);
            api_ok(br_inv_strip_supplier([
                'distribution_id' => $distId,
                'item_code'       => $itemCode,
                'price'           => $price,
            ]));

        case 'save_prices_bulk':
            $rawPrices = $in['prices'] ?? ($_POST['prices'] ?? null);
            $pricesMap = is_array($rawPrices) ? $rawPrices : (is_string($rawPrices) ? json_decode($rawPrices, true) : null);
            if (!is_array($pricesMap) || empty($pricesMap)) {
                api_fail('بيانات الأسعار غير صالحة', 400, 'bad_request');
            }
            $count = br_inv_save_prices_bulk($branchId, $distId, $pricesMap, $uid);
            api_ok(br_inv_strip_supplier([
                'distribution_id' => $distId,
                'count'           => $count,
            ]));
    }
} catch (InvalidArgumentException $e) {
    api_fail($e->getMessage(), 422, 'invalid');
} catch (RuntimeException $e) {
    api_fail($e->getMessage(), 422, 'invalid');
}
