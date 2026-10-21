<?php
/**
 * invoices_api.php — واجهة برمجة موديول الفواتير (بريف ١٦٥: مرحلة أ١ + أ٢)
 * نظام الفجالة ستور المالي
 *
 * مرحلة (أ١): البحث والبوب أب
 *   ?part=item_search&term=…                       ← {rows: […]} (GET)
 *   ?part=item_history&code=…&item_id=…            ← {item: {…}, prices: […], invoices: […], …} (GET)
 *   ?part=pick_search&term=…[&g=…]                 ← {rows: […]} (GET)
 *   ?part=pick_items&sth=…&suffix=…[&g=…]          ← {title: '…', rows: […]} (GET)
 *   ?part=pick_group_invoices&g2=…                 ← {title: '…', rows: […]} (GET)
 *   ?part=group_rows                               ← {unposted: […], posted: […], branch_count: N} (GET)
 *
 * مرحلة (أ٢): القايمة والفاتورة والمجموعة والخصم
 *   ?part=list[&tab=posted|unposted][&scope=admin|branches|all] ← {invoices: […], itemCountMap: {…}, …} (GET)
 *   ?part=invoice&invoice=…[&suffix=…&cb=…&dt=…]   ← {openInvoice: {…}, items: […], distId: N, …} (GET)
 *   ?part=group[&g=…]                              ← {grpCurrent: {…}, grpData: {…}, grpCards: […], …} (GET)
 *   ?part=discount[&dinv=…&dsuf=…&dnet=…&dpct=…]   ← {discHeader: {…}, discItems: […], discCalc: {…}, …} (GET)
 *
 * جميع عمليات المرحلة (أ) هي قراءة GET فقط. عمليات POST تُرفض بـ 405 Method Not Allowed.
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

// نفس حماية الصفحة بالحرف: require_permission('invoices')
api_require('invoices');

require_once __DIR__ . '/item_lookup_lib.php';
require_once __DIR__ . '/invoices_lib.php';

$part = (string) ($_GET['part'] ?? ($_POST['part'] ?? ''));

$allowedParts = [
    // مرحلة أ١
    'item_search',
    'item_history',
    'pick_search',
    'pick_items',
    'pick_group_invoices',
    'group_rows',
    // مرحلة أ٢
    'list',
    'invoice',
    'group',
    'discount',
];

if (!in_array($part, $allowedParts, true)) {
    api_fail('جزء غير معروف (part)', 400, 'bad_request');
}

api_method('GET');

try {
    switch ($part) {
        // ===== مرحلة أ١ =====
        case 'item_search':
            if (!ilk_ready()) {
                api_fail('المرآة لسه فاضية — شغّل وكيل المزامنة.', 422, 'not_ready');
            }
            $term = (string) ($_GET['term'] ?? '');
            api_ok(['rows' => ilk_search($term, 15)]);

        case 'item_history':
            if (!ilk_ready()) {
                api_fail('المرآة لسه فاضية — شغّل وكيل المزامنة.', 422, 'not_ready');
            }
            $code   = (string) ($_GET['code'] ?? '');
            $itemId = (int) ($_GET['item_id'] ?? 0);
            $res = ilk_item_history($code, $itemId);
            api_ok($res);

        case 'pick_search':
            $term = (string) ($_GET['term'] ?? '');
            $gid  = (int) ($_GET['g'] ?? 0);
            api_ok(['rows' => inv_pick_search($term, $gid)]);

        case 'pick_items':
            $sth = (int) ($_GET['sth'] ?? 0);
            $suf = trim((string) ($_GET['suffix'] ?? ''));
            $gid = (int) ($_GET['g'] ?? 0);
            api_ok(inv_pick_items($sth, $suf, $gid));

        case 'pick_group_invoices':
            $g2 = (int) ($_GET['g2'] ?? 0);
            api_ok(inv_pick_group_invoices($g2));

        case 'group_rows':
            api_ok(inv_group_picker_data());

        // ===== مرحلة أ٢ =====
        case 'list':
            $tab = (string) ($_GET['tab'] ?? 'posted');
            $invScope = (string) ($_GET['scope'] ?? 'admin');
            if (!in_array($invScope, ['admin', 'branches', 'all'], true)) {
                $invScope = 'admin';
            }
            api_ok(inv_load_list($tab, $invScope));

        case 'invoice':
            $openInvoiceId = $_GET['invoice'] ?? null;
            if ($openInvoiceId === null || $openInvoiceId === '') {
                api_fail('رقم الفاتورة مطلوب', 400, 'bad_request');
            }
            $openSuffix = isset($_GET['suffix']) ? (string) $_GET['suffix'] : null;
            $openCb     = isset($_GET['cb']) && $_GET['cb'] !== '' ? (int) $_GET['cb'] : null;
            $openDt     = isset($_GET['dt']) && $_GET['dt'] !== '' ? (int) $_GET['dt'] : null;
            $u          = current_user();
            $uid        = (int) ($u['id'] ?? 0);
            $data       = inv_load_open_invoice(db(), $openInvoiceId, $openSuffix, $openCb, $openDt, $uid);
            if (!$data['openInvoice']) {
                api_fail('الفاتورة غير موجودة في المرآة أو اتلغت', 404, 'not_found');
            }
            api_ok($data);

        case 'group':
            $grpOpenId = isset($_GET['g']) && $_GET['g'] !== '' ? (int) $_GET['g'] : null;
            api_ok(inv_load_group($grpOpenId));

        case 'discount':
            $discInvId  = isset($_GET['dinv']) ? (string) $_GET['dinv'] : null;
            $discSuffix = isset($_GET['dsuf']) ? (string) $_GET['dsuf'] : null;
            $discNetRaw = trim((string) ($_GET['dnet'] ?? ''));
            $discNetOverride = ($discNetRaw !== '' && is_numeric($discNetRaw) && (float)$discNetRaw > 0)
                ? (float) $discNetRaw : null;

            $discItemPct = [];
            $dpctRaw = trim((string) ($_GET['dpct'] ?? ''));
            if ($dpctRaw !== '') {
                foreach (explode(',', $dpctRaw) as $pair) {
                    $pair = trim($pair);
                    if ($pair === '' || strpos($pair, ':') === false) continue;
                    [$c, $p] = explode(':', $pair, 2);
                    $c = trim($c);
                    $p = (float) $p;
                    if ($c !== '' && $p > 0 && $p <= 100) $discItemPct[$c] = $p;
                }
            }
            api_ok(inv_load_discount($discInvId, $discSuffix, $discNetOverride, $discItemPct));
    }
} catch (Throwable $e) {
    api_fail($e->getMessage(), 422, 'error');
}
