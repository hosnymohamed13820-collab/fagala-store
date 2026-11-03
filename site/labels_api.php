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
require_once __DIR__ . '/purchase_doc_lib.php';

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
    case 'queue':
        api_method('POST');
        api_csrf();
        api_idempotent();
        $input = api_input();
        [$parsed] = labels_parse_rows((string) ($input['rows'] ?? ''));
        try {
            $payload = labels_build_print_payload($parsed);
        } catch (InvalidArgumentException $e) {
            api_fail($e->getMessage(), 400, 'bad_request');
        }
        $branchId = (int) ($_SESSION['user']['branch_id'] ?? 0);
        if ($branchId <= 0 && !is_real_owner()) {
            api_fail('حسابك مش مربوط بفرع', 403, 'forbidden');
        }
        $st = db()->prepare(
            'INSERT INTO print_queue (branch_id, kind, ref_id, payload, created_by) VALUES (?, ?, ?, ?, ?)'
        );
        $st->execute([$branchId > 0 ? $branchId : null, 'labels', 0,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            (int) $_SESSION['user']['id']]);
        api_ok(['id' => (int) db()->lastInsertId(), 'count' => count($payload['labels'])]);

    case 'agent_status':
        api_method('GET');
        $branchId = (int) ($_SESSION['user']['branch_id'] ?? 0);
        $st = db()->prepare(
            "SELECT printer FROM print_agents WHERE session_code LIKE ?
             AND last_seen >= NOW() - INTERVAL 2 MINUTE
             AND (branch_id = ? OR branch_id IS NULL) ORDER BY last_seen DESC LIMIT 1"
        );
        $st->execute(['LBL-%', $branchId > 0 ? $branchId : null]);
        $agent = $st->fetch(PDO::FETCH_ASSOC);
        api_ok(['online' => (bool) $agent, 'printer' => $agent['printer'] ?? null]);

    case 'job_status':
        api_method('GET');
        $st = db()->prepare("SELECT status, last_error FROM print_queue WHERE id = ? AND kind = ? AND created_by = ?");
        $st->execute([(int) ($_GET['id'] ?? 0), 'labels', (int) $_SESSION['user']['id']]);
        $job = $st->fetch(PDO::FETCH_ASSOC);
        if (!$job) {
            api_fail('مهمة الطباعة مش موجودة', 404, 'not_found');
        }
        api_ok($job);

    case 'code':
        api_method('GET');
        // «مش موجود» نتيجة عادية مش خطأ ← row = null
        api_ok(['row' => labels_item_by_code((string) ($_GET['code'] ?? ''))]);

    case 'search':
        api_method('GET');
        api_ok(['rows' => labels_search((string) ($_GET['term'] ?? ''))]);

    case 'purchase_doc':
        api_method('GET');
        $term = (string) ($_GET['term'] ?? ($_GET['doc_no'] ?? ''));
        $branchId = is_real_owner() ? null : (int) ($_SESSION['user']['branch_id'] ?? 0);
        $docData = purchase_doc_items_for_labels(db(), $term, $branchId);
        if (!$docData) {
            api_fail('فاتورة الشراء مش موجودة', 404, 'not_found');
        }
        api_ok($docData);

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
