<?php
declare(strict_types=1);
/**
 * labels_api.php — داتا صفحة طباعة الليبلز (بريف ٢١٦)
 * نظام الفجالة ستور المالي
 *
 *   ?part=code&code=…     ← {row} (null لو الكود مش موجود)
 *   ?part=search&term=…   ← {rows} (أقرب ٢٥ اسم)
 *   ?part=settings        ← GET: {settings} · POST: يحفظ (المالك بس)
 *   ?part=xlsx            ← POST: ملف إكسيل لـBarTender (مش JSON)
 *
 * 🔴 السعر دايماً من hq_codes.price — العميل بيبعت الكود والعدد بس،
 *    ومستحيل يبعت سعر من عنده.
 */

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
require_once __DIR__ . '/labels_lib.php';

// ===== تصدير الإكسيل =====
// ده ملف مش رد JSON، فبيتعالج **قبل** api_begin (اللي بيقفل نوع الرد على JSON).
if ((string) ($_GET['part'] ?? '') === 'xlsx') {
    require_login();
    if (!can('labels')) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('مالكش صلاحية');
    }
    api_method('POST');
    api_csrf();

    [$rows, $total] = labels_parse_rows((string) ($_POST['rows'] ?? ''));
    if (!$rows) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        exit('مفيش أصناف مختارة');
    }
    if ($total > LABELS_MAX) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        exit('الحد الأقصى ' . LABELS_MAX . ' ليبل في المرة');
    }

    $map = labels_codes_map(array_column($rows, 'code'));
    if (count($map) !== count($rows)) {
        http_response_code(409);
        header('Content-Type: text/plain; charset=utf-8');
        exit('فيه أصناف اتغيّرت — حدّث الصفحة وجرّب تاني');
    }

    require_once __DIR__ . '/xlsx_lib.php';
    $x = new SimpleXlsx('ليبلز');
    // الكود نص عشان إكسيل مايحوّلش الباركود لـ6.2E+12
    $x->setColumnTypes(['s', 's', 'n', 'n']);
    $x->addRow(['barcode', 'name', 'price', 'copies'], true);
    foreach ($rows as $r) {
        $it = $map[$r['code']];
        $x->addRow([$it['code'], $it['name'], $it['price'], $r['copies']]);
    }
    $x->download('labels_bartender_' . date('Y-m-d') . '.xlsx');
    exit;
}

api_begin();
require_login();
api_require('labels');

$part = (string) ($_GET['part'] ?? '');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

switch ($part) {
    case 'code':
        api_method('GET');
        // «مش موجود» نتيجة عادية مش خطأ ← row = null
        api_ok(['row' => labels_item_by_code((string) ($_GET['code'] ?? ''))]);

    case 'search':
        api_method('GET');
        api_ok(['rows' => labels_search((string) ($_GET['term'] ?? ''))]);

    case 'settings':
        if ($method === 'POST') {
            api_csrf();
            if (!is_real_owner()) {
                api_fail('إعدادات الليبل بتتظبط من المالك', 403, 'forbidden');
            }
            try {
                $settings = labels_save_settings(api_input());
            } catch (InvalidArgumentException $e) {
                api_fail($e->getMessage(), 400, 'bad_request');
            }
            api_ok(['settings' => $settings]);
        }
        api_ok(['settings' => labels_settings()]);

    default:
        api_fail('جزء غير معروف', 400, 'bad_request');
}
