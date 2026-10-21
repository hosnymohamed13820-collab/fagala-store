<?php
/**
 * undistributed_api.php — واجهة برمجة موديول الأصناف غير الموزعة (بريف ١٦٠)
 * نظام الفجالة ستور المالي
 *
 *   ?part=report                   ← {has_state, items, purchase_invoices, …} (GET)
 *   ?part=invoice&sth=…&suffix=…   ← {header, lines}                          (GET)
 *   ?part=set_period               ← {ok: true}                               (POST)
 *   ?part=save_note                ← {ok: true}                               (POST)
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

// نفس حماية الصفحة بالحرف: require_permission('invoices:undistributed')
api_require('invoices:undistributed');

require_once __DIR__ . '/undistributed_lib.php';

$u    = current_user();
$uid  = (int) ($u['id'] ?? 0);
$part = (string) ($_GET['part'] ?? ($_POST['part'] ?? ''));

$reads  = ['report', 'invoice'];
$writes = ['set_period', 'save_note'];

if (!in_array($part, $reads, true) && !in_array($part, $writes, true)) {
    api_fail('جزء غير معروف (part)', 400, 'bad_request');
}

// ===== عمليات القراءة — GET بس =====
if (in_array($part, $reads, true)) {
    api_method('GET');

    switch ($part) {
        case 'report':
            api_ok(undist_calculate_report());

        case 'invoice':
            $sth    = (int) ($_GET['sth'] ?? 0);
            $suffix = trim((string) ($_GET['suffix'] ?? ''));
            $inv    = undist_get_invoice($sth, $suffix);
            if (!$inv) {
                api_fail('الفاتورة مش موجودة', 404, 'not_found');
            }
            api_ok($inv);
    }
}

// ===== عمليات الكتابة — POST بس بـ CSRF و Idempotency =====
api_method('POST');
api_csrf();
api_idempotent();

$in = api_input();

switch ($part) {
    case 'set_period':
        $from    = trim((string) ($in['from'] ?? ($_POST['from'] ?? '')));
        $to      = trim((string) ($in['to'] ?? ($_POST['to'] ?? '')));
        $confirm = !empty($in['confirm'] ?? ($_POST['confirm'] ?? 0));
        try {
            undist_set_period($from, $to, $confirm, $uid > 0 ? $uid : null);
        } catch (Throwable $e) {
            api_fail($e->getMessage(), 422, 'invalid_period');
        }
        api_ok(['ok' => true]);

    case 'save_note':
        $code = trim((string) ($in['item_code'] ?? ($_POST['item_code'] ?? '')));
        $note = trim((string) ($in['note'] ?? ($_POST['note'] ?? '')));
        try {
            undist_save_note($code, $note, $uid > 0 ? $uid : null);
        } catch (Throwable $e) {
            api_fail($e->getMessage(), 422, 'invalid_note');
        }
        api_ok(['ok' => true]);
}
