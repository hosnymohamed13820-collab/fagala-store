<?php
/**
 * sync_ashraqa_purchases.php — استقبال فواتير الشراء ومرتجعات الشراء من إشراقة.
 * =====================================================================
 *  وكيل إشراقة (agent_ashraqa.ps1 من نسخة 1.6) بيبعت هنا كل نص ساعة
 *  الفواتير اللي اتغيّرت بس، ومرة في اليوم كله (full).
 *
 *  ليه (٣٠ سبتمبر ٢٠٢٦): إشراقة بتسجّل البضاعة اللي بتبعتها لفروعنا
 *  «مرتجع شراء» (115) للمورد 8 «الفروع أستاذ محمد عصام»، والفرع
 *  بيسجّلها «شراء من الإدارة». من غير الفواتير دي ماكانش فيه طريقة نعرف
 *  الصنف ده جه منين. شوف local/ashraqa_purchases.sql.
 *
 *  منفصل عن sync_ashraqa.php عن قصد: لو حصل هنا أي خطأ، الأرصدة ماتتأثرش.
 *
 *  الأمان: POST بس · JSON بس · توكن إشراقة من sync_tokens.php.
 *  الجدولين مرآة بس — الوكيل هو المصدر، والملف ده الوحيد اللي بيكتب فيهم.
 * =====================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/errlog_lib.php';
errlog_install();
require_once __DIR__ . '/sync_tokens.php';

const AP_MAX_INVOICES = 5000;
const AP_MAX_LINES    = 60000;
// أقدم تاريخ بنقبله — نفس الشباك اللي الوكيل بيسحبه
const AP_MIN_SINCE    = '2026-01-01';

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');

function ap_die(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// نص من غير مسافات زيادة، ومقصوص على عرض العمود — NULL لو فاضي
function ap_str($v, int $max): ?string {
    $s = trim((string) ($v ?? ''));
    if ($s === '') return null;
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max) : $s;
}

function ap_date($v): ?string {
    $s = substr(trim((string) ($v ?? '')), 0, 10);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ap_die('POST only.', 405);
}

$raw     = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    ap_die('Invalid JSON.');
}
$token = (string) ($_SERVER['HTTP_X_SYNC_TOKEN'] ?? ($payload['token'] ?? ''));
if (!hash_equals(SYNC_AGENT_TOKENS['hq'], $token)) {
    ap_die('Unauthorized.', 401);
}

$invoices = $payload['invoices'] ?? null;
if (!is_array($invoices)) {
    ap_die('Missing invoices.');
}
if (count($invoices) > AP_MAX_INVOICES) {
    ap_die('Too many invoices.');
}

// full    = الوكيل باعت كل الفواتير من تاريخ since → اللي مش مبعوت يتشال (اتمسح عندهم)
// partial = المتغيّر بس → الباقي زي ما هو
$mode  = ($payload['mode'] ?? 'partial') === 'full' ? 'full' : 'partial';
$since = ap_date($payload['since'] ?? '');
if ($mode === 'full' && ($since === null || $since < AP_MIN_SINCE)) {
    ap_die('full mode needs a valid since date.');
}

// ---------------------------------------------------------------------
// التحقق من كل الداتا **قبل** ما نفتح الترانزاكشن — عشان فاتورة بايظة
// ماتسيبش الجدول نص متحدّث.
// ---------------------------------------------------------------------
$heads = [];
$lines = [];
$skipped = 0;
foreach ($invoices as $inv) {
    if (!is_array($inv)) { $skipped++; continue; }
    $sstt = (int) ($inv['sstt'] ?? 0);
    $brch = (int) ($inv['brch'] ?? 0);
    $fy   = (int) ($inv['fy'] ?? 0);
    $doc  = (int) ($inv['doc'] ?? 0);
    $date = ap_date($inv['date'] ?? '');
    if (!in_array($sstt, [113, 115], true) || $doc <= 0 || $date === null) { $skipped++; continue; }

    $key = [$sstt, $brch, $fy, $doc];
    $heads[] = [
        $sstt, $brch, $fy, $doc, $date,
        isset($inv['supplier_id']) ? (int) $inv['supplier_id'] : null,
        ap_str($inv['supplier_name'] ?? null, 255),
        round((float) ($inv['net'] ?? 0), 3),
        round((float) ($inv['total'] ?? 0), 3),
        !empty($inv['cancelled']) ? 1 : 0,
        ap_str($inv['notes'] ?? null, 500),
        ap_str($inv['supplier_inv_no'] ?? null, 100),
        ap_str($inv['internal_no'] ?? null, 100),
        ap_str($inv['last_modify'] ?? null, 19),
    ];
    foreach ((array) ($inv['lines'] ?? []) as $ln) {
        if (!is_array($ln)) continue;
        $lno = (int) ($ln['line_no'] ?? 0);
        if ($lno <= 0) continue;
        $uq = (float) ($ln['unit_q'] ?? 1);
        $lines[] = array_merge($key, [
            $lno,
            isset($ln['item_id']) ? (int) $ln['item_id'] : null,
            ap_str($ln['code'] ?? null, 64),       // ⚠️ نص — 0096 غير 96
            ap_str($ln['barcodes'] ?? null, 500),
            ap_str($ln['name'] ?? null, 255),
            isset($ln['unit_id']) ? (int) $ln['unit_id'] : null,
            $uq > 0 ? round($uq, 3) : 1.0,
            round((float) ($ln['qty'] ?? 0), 3),
            round((float) ($ln['price'] ?? 0), 3),
            round((float) ($ln['total'] ?? 0), 3),
        ]);
    }
}
if (count($lines) > AP_MAX_LINES) {
    ap_die('Too many lines.');
}

require_once __DIR__ . '/config.php';
try {
    $pdo = db();
} catch (Throwable $ex) {
    ap_die('DB connection failed.', 500);
}

try {
    $pdo->beginTransaction();

    if ($mode === 'full') {
        // المرآة كلها من since بتتبني من جديد — كده الفاتورة اللي اتمسحت
        // عندهم تختفي هنا كمان. (جدول مرآة، مش سجل مالي عندنا.)
        $pdo->prepare('DELETE l FROM ashraqa_purchase_lines l
                         JOIN ashraqa_purchases h
                           ON h.sstt_id = l.sstt_id AND h.brch_id = l.brch_id
                          AND h.fy_no = l.fy_no AND h.add_doc = l.add_doc
                        WHERE h.doc_date >= ?')->execute([$since]);
        $pdo->prepare('DELETE FROM ashraqa_purchases WHERE doc_date >= ?')->execute([$since]);
    } elseif ($heads) {
        // جزئي: سطور الفواتير المبعوتة بتتشال وتتكتب من جديد (عدد السطور ممكن يكون اتغيّر).
        // استعلام واحد لكل الفواتير — مش واحد لكل فاتورة.
        $conds = implode(' OR ', array_fill(0, count($heads),
            '(sstt_id = ? AND brch_id = ? AND fy_no = ? AND add_doc = ?)'));
        $params = [];
        foreach ($heads as $h) { array_push($params, $h[0], $h[1], $h[2], $h[3]); }
        $pdo->prepare("DELETE FROM ashraqa_purchase_lines WHERE {$conds}")->execute($params);
    }

    $insH = $pdo->prepare(
        'INSERT INTO ashraqa_purchases
            (sstt_id, brch_id, fy_no, add_doc, doc_date, supplier_id, supplier_name,
             net_amount, total_amount, cancelled, notes, supplier_inv_no, internal_no, last_modify)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            doc_date = VALUES(doc_date), supplier_id = VALUES(supplier_id),
            supplier_name = VALUES(supplier_name), net_amount = VALUES(net_amount),
            total_amount = VALUES(total_amount), cancelled = VALUES(cancelled),
            notes = VALUES(notes), supplier_inv_no = VALUES(supplier_inv_no),
            internal_no = VALUES(internal_no), last_modify = VALUES(last_modify)'
    );   // 14 عمود = 14 علامة ?
    foreach ($heads as $h) { $insH->execute($h); }

    $insL = $pdo->prepare(
        'INSERT INTO ashraqa_purchase_lines
            (sstt_id, brch_id, fy_no, add_doc, line_no, item_id, code, barcodes,
             item_name, unit_id, unit_q, quantity, unit_price, line_total)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            item_id = VALUES(item_id), code = VALUES(code), barcodes = VALUES(barcodes),
            item_name = VALUES(item_name), unit_id = VALUES(unit_id), unit_q = VALUES(unit_q),
            quantity = VALUES(quantity), unit_price = VALUES(unit_price), line_total = VALUES(line_total)'
    );   // 14 عمود = 14 علامة ?
    foreach ($lines as $l) { $insL->execute($l); }

    $note = 'فواتير شراء ' . ($mode === 'full' ? "كامل من {$since}" : 'جزئي')
          . ' · ' . count($lines) . ' سطر' . ($skipped ? " · اتجاهل {$skipped}" : '');
    $pdo->prepare('INSERT INTO ashraqa_sync_log (rows_count, status, note) VALUES (?, ?, ?)')
        ->execute([count($heads), 'ok', $note]);

    $pdo->commit();
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    try {
        $pdo->prepare('INSERT INTO ashraqa_sync_log (rows_count, status, note) VALUES (?, ?, ?)')
            ->execute([0, 'error', mb_substr('فواتير شراء: ' . $ex->getMessage(), 0, 255)]);
    } catch (Throwable $ignore) {}
    ap_die('Write failed.', 500);
}

echo json_encode([
    'ok'       => true,
    'mode'     => $mode,
    'invoices' => count($heads),
    'lines'    => count($lines),
    'skipped'  => $skipped,
    'time'     => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE);
