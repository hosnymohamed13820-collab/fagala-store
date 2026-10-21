<?php
/**
 * invoices_api.php — واجهة برمجة موديول الفواتير (بريف ١٦٥ وما بعده: مرحلة أ١ + أ٢ + ب١ + ب٢)
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
 * مرحلة (ب١): الحفظ التلقائي وحالة الفاتورة وسحب الحجز (كتابة POST)
 *   ?part=autosave                                 ← {ok: true, saved: …, …} (POST)
 *   ?part=group_autosave                           ← {ok: true, updated: …} (POST)
 *   ?part=set_status                               ← {ok: true, …} (POST)
 *   ?part=reservation_preview                      ← {items: […], total_candidates: N} (POST)
 *   ?part=reservation_apply                        ← {allocated_count: N} (POST)
 *
 * مرحلة (ب٢): الملاحظات والعلامات والمشاكل (كتابة POST)
 *   ?part=save_notes                               ← {ok: true} (POST)
 *   ?part=group_save_notes                         ← {ok: true} (POST)
 *   ?part=item_flags_save                          ← {ok: true, invoices: N} (POST)
 *   ?part=item_flags_clear                         ← {ok: true, count: N} (POST)
 *   ?part=add_to_problems                          ← {ok: true} (POST)
 *
 * مرحلة (ب٣): إدارة المجموعات (كتابة POST)
 *   ?part=group_create                             ← {group_id: N} (POST)
 *   ?part=group_create_items                       ← {group_id: N} (POST)
 *   ?part=group_add_invoices                       ← {added: N} (POST)
 *   ?part=group_add_items                          ← {added: N, dup: N, bad: N} (POST)
 *   ?part=group_delete                             ← {deleted: true} (POST)
 *   ?part=group_remove_invoice                     ← {removed: bool} (POST)
 *   ?part=group_remove_item                        ← {removed_count: N} (POST)
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
    // مرحلة ب١ (كتابة)
    'autosave',
    'group_autosave',
    'set_status',
    'reservation_preview',
    'reservation_apply',
    // مرحلة ب٢ (كتابة)
    'save_notes',
    'group_save_notes',
    'item_flags_save',
    'item_flags_clear',
    'add_to_problems',
    // مرحلة ب٣ (كتابة)
    'group_create',
    'group_create_items',
    'group_add_invoices',
    'group_add_items',
    'group_delete',
    'group_remove_invoice',
    'group_remove_item',
];

if (!in_array($part, $allowedParts, true)) {
    api_fail('جزء غير معروف (part)', 400, 'bad_request');
}

$postParts = [
    'autosave',
    'group_autosave',
    'set_status',
    'reservation_preview',
    'reservation_apply',
    'save_notes',
    'group_save_notes',
    'item_flags_save',
    'item_flags_clear',
    'add_to_problems',
    'group_create',
    'group_create_items',
    'group_add_invoices',
    'group_add_items',
    'group_delete',
    'group_remove_invoice',
    'group_remove_item',
];

if (in_array($part, $postParts, true)) {
    api_method('POST');
    api_csrf();
    api_idempotent();
    $in  = api_input();
    $u   = current_user();
    $uid = (int) ($u['id'] ?? 0);
} else {
    api_method('GET');
}

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

        // ===== مرحلة ب١ (كتابة) =====
        case 'autosave':
            $distId   = (int) ($in['distribution_id'] ?? 0);
            $itemCode = trim((string) ($in['item_code'] ?? ''));
            $itemQty  = (float) ($in['item_qty'] ?? 0);
            $branchId = (int) ($in['branch_id'] ?? 0);
            $qty      = (float) ($in['qty'] ?? 0);
            $invId    = $in['invoice_id'] ?? null;
            $suffix   = isset($in['suffix']) ? (string) $in['suffix'] : null;
            $res = inv_autosave(db(), $distId, $itemCode, $itemQty, $branchId, $qty, $uid, $invId, $suffix);
            api_ok($res);

        case 'group_autosave':
            $gid      = (int) ($in['group_id'] ?? 0);
            $itemCode = trim((string) ($in['item_code'] ?? ''));
            $branchId = (int) ($in['branch_id'] ?? 0);
            $qty      = (float) ($in['qty'] ?? 0);
            $res = inv_group_autosave(db(), $gid, $itemCode, $branchId, $qty, $uid);
            api_ok($res);

        case 'set_status':
            $distId = (int) ($in['distribution_id'] ?? 0);
            $status = trim((string) ($in['status'] ?? ''));
            $dry    = !empty($in['dry']);
            $invId  = $in['invoice_id'] ?? null;
            $suffix = isset($in['suffix']) ? (string) $in['suffix'] : null;
            $res = inv_set_status(db(), $distId, $status, $uid, $dry, $invId, $suffix);
            api_ok($res);

        case 'reservation_preview':
            $distId = (int) ($in['distribution_id'] ?? 0);
            $invId  = $in['invoice_id'] ?? null;
            $suffix = isset($in['suffix']) ? (string) $in['suffix'] : null;
            $res = inv_reservation_preview($distId, $invId, $suffix);
            api_ok($res);

        case 'reservation_apply':
            $distId    = (int) ($in['distribution_id'] ?? 0);
            $invoiceNo = trim((string) ($in['invoice_no'] ?? ''));
            $invId     = $in['invoice_id'] ?? null;
            $suffix    = isset($in['suffix']) ? (string) $in['suffix'] : null;
            $allocRaw  = $in['alloc'] ?? [];
            $alloc     = is_array($allocRaw) ? $allocRaw : json_decode((string) $allocRaw, true);
            if (!is_array($alloc)) $alloc = [];
            $res = inv_reservation_apply(db(), $distId, $invoiceNo, $invId, $suffix, $alloc, $uid);
            api_ok($res);

        // ===== مرحلة ب٢ (الملاحظات والعلامات والمشاكل) =====
        case 'save_notes':
            $distId = (int) ($in['distribution_id'] ?? 0);
            $notes  = (string) ($in['notes'] ?? '');
            $res    = inv_save_notes($distId, $notes, $uid);
            api_ok($res);

        case 'group_save_notes':
            $gid   = (int) ($in['group_id'] ?? 0);
            $notes = (string) ($in['notes'] ?? '');
            group_save_notes($gid, $notes);
            api_ok(['ok' => true]);

        case 'item_flags_save':
            $did  = (int) ($in['distribution_id'] ?? 0);
            $gid  = (int) ($in['group_id'] ?? 0);
            $code = (string) ($in['item_code'] ?? '');
            $res  = inv_item_flags_save($did, $gid, $code, $in, $uid);
            api_ok($res);

        case 'item_flags_clear':
            $did  = (int) ($in['distribution_id'] ?? 0);
            $gid  = (int) ($in['group_id'] ?? 0);
            $what = (string) ($in['what'] ?? 'highlight');
            $res  = inv_item_flags_clear($did, $gid, $what);
            api_ok($res);

        case 'add_to_problems':
            $did      = (int) ($in['distribution_id'] ?? 0);
            $gid      = (int) ($in['group_id'] ?? 0);
            $code     = (string) ($in['item_code'] ?? '');
            $note     = (string) ($in['note'] ?? '');
            $itemName = (string) ($in['item_name'] ?? '');
            $res      = inv_add_to_problems(db(), $did, $code, $note, $uid, $gid, $itemName);
            api_ok($res);

        // ===== مرحلة ب٣ (إدارة المجموعات) =====
        case 'group_create':
            $name     = (string) ($in['name'] ?? ($in['group_name'] ?? ''));
            $invoices = $in['invoices'] ?? ($in['pick'] ?? []);
            $res      = inv_group_create($invoices, $uid, $name);
            api_ok($res);

        case 'group_create_items':
            $name  = (string) ($in['name'] ?? ($in['group_name'] ?? ''));
            $items = $in['items'] ?? ($in['ipicks'] ?? []);
            $res   = inv_group_create_items($items, $uid, $name);
            api_ok($res);

        case 'group_add_invoices':
            $gid      = (int) ($in['group_id'] ?? 0);
            $invoices = $in['invoices'] ?? ($in['pick'] ?? []);
            $res      = inv_group_add_invoices($gid, $invoices, $uid);
            api_ok($res);

        case 'group_add_items':
            $gid   = (int) ($in['group_id'] ?? 0);
            $items = $in['items'] ?? ($in['ipicks'] ?? []);
            $res   = inv_group_add_items($gid, $items, $uid);
            api_ok($res);

        case 'group_delete':
            $gid = (int) ($in['group_id'] ?? 0);
            $res = inv_group_delete($gid);
            api_ok($res);

        case 'group_remove_invoice':
            $gid    = (int) ($in['group_id'] ?? 0);
            $sthId  = (int) ($in['sth_id'] ?? 0);
            $suffix = (string) ($in['suffix'] ?? '');
            $res    = inv_group_remove_invoice($gid, $sthId, $suffix);
            api_ok($res);

        case 'group_remove_item':
            $gid  = (int) ($in['group_id'] ?? 0);
            $code = (string) ($in['item_code'] ?? '');
            $res  = inv_group_remove_item($gid, $code);
            api_ok($res);
    }
} catch (Throwable $e) {
    api_fail($e->getMessage(), 422, 'error');
}
