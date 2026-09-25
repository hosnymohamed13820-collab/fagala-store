<?php
/**
 * sync_branch.php — استقبال بيانات الفروع (أرصدة + فواتير)
 * =====================================================================
 *  كل فرع بيبعت هنا:
 *  - الأرصدة (kind = 'balances' أو غايب للتوافق): الفروق بس (delta)
 *  - فواتير الفرع (kind = 'invoices'): رؤوس الفواتير
 *  - بنود فواتير الفرع (kind = 'invoice_lines'): تفاصيل البنود
 *  - الفواتير غير المرحّلة (kind = 'unposted') وبنودها (unposted_lines)
 *
 *  ليه delta مش لقطة كاملة: لقطة كاملة كل ٥ دقايق × ٥ فروع =
 *  ملايين الكتابات في اليوم. الاستضافة مش هتستحمل.
 *
 *  رصيد صفر بيتخزّن زي ما هو (مابيتشالش): لازم نفرّق بين
 *  "الصنف رصيده صفر" و "الصنف مالوش خبر من الفرع ده".
 *
 *  الأمان: توكن في الهيدر + POST بس. الملف مش محتاج تسجيل دخول
 *  (الجهاز مش بني آدم) فالتوكن لازم يفضل سري.
 * =====================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/sync_tokens.php';
const BRANCH_MAX_ROWS   = 60000;
const BR_AGENT_BRCH     = ['zahraa' => 2, 'degla' => 5, 'bonjour' => 3, 'abc' => 6];

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
error_reporting(E_ALL);
ini_set('display_errors', '0');

function br_die(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') br_die('POST only.', 405);

$token = (string) ($_SERVER['HTTP_X_SYNC_TOKEN'] ?? '');
$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') br_die('Empty payload.');

$in = json_decode($raw, true);
if (!is_array($in)) br_die('Bad JSON.');

$agent = trim((string) ($in['agent'] ?? ''));
if (!preg_match('/^[a-z0-9_]{2,32}$/', $agent)) br_die('Bad agent key.');

// التوكن لازم يكون بتاع الوكيل ده بالذات — مش أي توكن صحيح (مراجعة ٢٢ سبتمبر ٢٠٢٦)
if (!synctok_ok($agent, $token, SYNC_BRANCH_AGENTS)) br_die('Unauthorized.', 401);

$kind = trim((string) ($in['kind'] ?? ''));
if ($kind === '') $kind = 'balances';

$rows = $in['rows'] ?? null;
if (!is_array($rows)) br_die('Missing rows.');
if (count($rows) > BRANCH_MAX_ROWS) br_die('Too many rows.', 413);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/agent_lib.php';

try {
    if (!agent_exists($agent)) br_die('Unknown agent: ' . $agent, 404);

    if (!in_array($kind, ['balances', 'invoices', 'invoice_lines', 'unposted', 'unposted_lines', 'purchases', 'purchase_lines'], true)) {
        br_die('Unknown kind: ' . $kind);
    }

    $pdo = db();

    $stored  = 0;
    $skipped = 0;

    $pdo->beginTransaction();
    try {
        if ($kind === 'balances') {
            // upsert: الصنف الموجود يتحدّث والجديد يتحط.
            // مابنمسحش أي حاجة — الوكيل بيبعت الصفر صراحةً لما الرصيد يخلص.
            $st = $pdo->prepare(
                'INSERT INTO hq_branch_balances (agent_key, item_id, sto_id, balance)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    agent_key  = VALUES(agent_key),
                    balance    = VALUES(balance),
                    updated_at = NOW()'
            );
            foreach ($rows as $r) {
                if (!is_array($r)) { $skipped++; continue; }
                $item = (int) ($r['item_id'] ?? 0);
                $sto  = (int) ($r['sto_id']  ?? 0);
                if ($item <= 0 || $sto <= 0) { $skipped++; continue; }

                $bal = $r['balance'] ?? 0;
                if (!is_numeric($bal)) { $skipped++; continue; }

                $st->execute([$agent, $item, $sto, round((float) $bal, 3)]);
                $stored++;
            }
        } elseif ($kind === 'invoices') {
            // فواتير الفرع — upsert بالـ (agent_key, sth_id, suffix)
            $st = $pdo->prepare(
                'INSERT INTO br_invoices
                    (agent_key, sth_id, suffix, doc_no, doc_type, supplier_id, supplier_code,
                     supplier_name, net_amount, total_amount, invoice_date, company_branch, last_modify)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    doc_no         = VALUES(doc_no),
                    doc_type       = VALUES(doc_type),
                    supplier_id    = VALUES(supplier_id),
                    supplier_code  = VALUES(supplier_code),
                    supplier_name  = VALUES(supplier_name),
                    net_amount     = VALUES(net_amount),
                    total_amount   = VALUES(total_amount),
                    invoice_date   = VALUES(invoice_date),
                    company_branch = VALUES(company_branch),
                    last_modify    = VALUES(last_modify)'
            );
            foreach ($rows as $r) {
                if (!is_array($r)) { $skipped++; continue; }
                $sid = (int) ($r['sth_id'] ?? 0);
                // suffix جزء من المفتاح — من غيره الصف بينزل بـ'' ويلخبط
                // فواتير شهور مختلفة على بعض. نرفضه زي ما بنعمل في البنود.
                $suf = (string) ($r['suffix'] ?? '');
                if ($sid <= 0 || $suf === '') { $skipped++; continue; }
                $st->execute([
                    $agent,
                    $sid,
                    $suf,
                    (string) ($r['doc_no'] ?? ''),
                    (int) ($r['doc_type'] ?? 0),
                    ($r['supplier_id'] ?? null) !== null ? (int) $r['supplier_id'] : null,
                    (string) ($r['supplier_code'] ?? ''),
                    mb_substr((string) ($r['supplier_name'] ?? ''), 0, 255),
                    (float) ($r['net_amount'] ?? 0),
                    (float) ($r['total_amount'] ?? 0),
                    ($r['invoice_date'] ?? null) ?: null,
                    ($r['company_branch'] ?? null) !== null ? (int) $r['company_branch'] : null,
                    ($r['last_modify'] ?? null) ?: null,
                ]);
                $stored++;
            }
        } elseif ($kind === 'invoice_lines') {
            // بنود فواتير الفرع — المفتاح (agent_key, sth_id, suffix, line_no)
            $st = $pdo->prepare(
                'INSERT INTO br_invoice_lines
                    (agent_key, sth_id, suffix, line_no, item_id, item_code, item_name,
                     quantity, unit_price, cost_price)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    item_id    = VALUES(item_id),
                    item_code  = VALUES(item_code),
                    item_name  = VALUES(item_name),
                    quantity   = VALUES(quantity),
                    unit_price = VALUES(unit_price),
                    cost_price = VALUES(cost_price)'
            );
            foreach ($rows as $r) {
                if (!is_array($r)) { $skipped++; continue; }
                $sid = (int) ($r['sth_id'] ?? 0);
                $ln  = (int) ($r['line_no'] ?? 0);
                $suf = (string) ($r['suffix'] ?? '');
                if ($sid <= 0 || $suf === '') { $skipped++; continue; }
                $st->execute([
                    $agent,
                    $sid,
                    $suf,
                    $ln,
                    ($r['item_id'] ?? null) !== null ? (int) $r['item_id'] : null,
                    mb_substr((string) ($r['item_code'] ?? ''), 0, 64),
                    mb_substr((string) ($r['item_name'] ?? ''), 0, 255),
                    (float) ($r['quantity'] ?? 0),
                    (float) ($r['unit_price'] ?? 0),
                    (float) ($r['cost_price'] ?? 0),
                ]);
                $stored++;
            }
        } elseif ($kind === 'unposted') {
            // فواتير الفرع غير المرحّلة — المفتاح (agent_key, sth_id)
            $batch = mb_substr((string) ($in['batch'] ?? ''), 0, 20);
            $st = $pdo->prepare(
                'INSERT INTO br_unposted
                    (agent_key, sth_id, doc_no, doc_type, supplier_id, supplier_code,
                     supplier_name, net_amount, total_amount, invoice_date,
                     company_branch, remark, batch)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    doc_no         = VALUES(doc_no),
                    doc_type       = VALUES(doc_type),
                    supplier_id    = VALUES(supplier_id),
                    supplier_code  = VALUES(supplier_code),
                    supplier_name  = VALUES(supplier_name),
                    net_amount     = VALUES(net_amount),
                    total_amount   = VALUES(total_amount),
                    invoice_date   = VALUES(invoice_date),
                    company_branch = VALUES(company_branch),
                    remark         = VALUES(remark),
                    batch          = VALUES(batch)'
            );
            foreach ($rows as $r) {
                if (!is_array($r)) { $skipped++; continue; }
                $sid = (int) ($r['sth_id'] ?? 0);
                if ($sid <= 0) { $skipped++; continue; }
                $st->execute([
                    $agent,
                    $sid,
                    (string) ($r['doc_no'] ?? ''),
                    (int) ($r['doc_type'] ?? 0),
                    ($r['supplier_id'] ?? null) !== null ? (int) $r['supplier_id'] : null,
                    (string) ($r['supplier_code'] ?? ''),
                    mb_substr((string) ($r['supplier_name'] ?? ''), 0, 255),
                    (float) ($r['net_amount'] ?? 0),
                    (float) ($r['total_amount'] ?? 0),
                    ($r['invoice_date'] ?? null) ?: null,
                    ($r['company_branch'] ?? null) !== null ? (int) $r['company_branch'] : null,
                    mb_substr((string) ($r['remark'] ?? ''), 0, 255),
                    $batch !== '' ? $batch : null,
                ]);
                $stored++;
            }
        } elseif ($kind === 'unposted_lines') {
            // بنود فواتير الفرع غير المرحّلة — المفتاح (agent_key, sth_id, line_no)
            $st = $pdo->prepare(
                'INSERT INTO br_unposted_lines
                    (agent_key, sth_id, line_no, item_id, item_code, item_name,
                     quantity, unit_price, cost_price)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    item_id    = VALUES(item_id),
                    item_code  = VALUES(item_code),
                    item_name  = VALUES(item_name),
                    quantity   = VALUES(quantity),
                    unit_price = VALUES(unit_price),
                    cost_price = VALUES(cost_price)'
            );
            foreach ($rows as $r) {
                if (!is_array($r)) { $skipped++; continue; }
                $sid = (int) ($r['sth_id'] ?? 0);
                $ln  = (int) ($r['line_no'] ?? 0);
                if ($sid <= 0) { $skipped++; continue; }
                $st->execute([
                    $agent,
                    $sid,
                    $ln,
                    ($r['item_id'] ?? null) !== null ? (int) $r['item_id'] : null,
                    mb_substr((string) ($r['item_code'] ?? ''), 0, 64),
                    mb_substr((string) ($r['item_name'] ?? ''), 0, 255),
                    (float) ($r['quantity'] ?? 0),
                    (float) ($r['unit_price'] ?? 0),
                    (float) ($r['cost_price'] ?? 0),
                ]);
                $stored++;
            }
        } elseif ($kind === 'purchases') {
            // فواتير الشراء والمردود — upsert بالـ (sth_id, suffix) في hq_invoices
            // كل فرع يبعت فواتير فرعه بس (company_branch = BR_AGENT_BRCH[$agent])
            $expectedBrch = BR_AGENT_BRCH[$agent] ?? 0;
            $ins = $pdo->prepare(
                'INSERT INTO hq_invoices
                    (sth_id, suffix, doc_no, doc_type, supplier_id, supplier_code,
                     supplier_name, net_amount, total_amount, invoice_date, company_branch, last_modify)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    suffix         = VALUES(suffix),
                    doc_no         = VALUES(doc_no),
                    doc_type       = VALUES(doc_type),
                    supplier_id    = VALUES(supplier_id),
                    supplier_code  = VALUES(supplier_code),
                    supplier_name  = VALUES(supplier_name),
                    net_amount     = VALUES(net_amount),
                    total_amount   = VALUES(total_amount),
                    invoice_date   = VALUES(invoice_date),
                    company_branch = VALUES(company_branch),
                    last_modify    = VALUES(last_modify)'
            );
            foreach ($rows as $r) {
                if (!is_array($r)) { $skipped++; continue; }
                $sid = (int) ($r['sth_id'] ?? 0);
                $suf = (string) ($r['suffix'] ?? '');
                if ($sid <= 0 || $suf === '') { $skipped++; continue; }

                $brch = ($r['company_branch'] ?? null) !== null ? (int) $r['company_branch'] : null;
                if ($brch !== $expectedBrch) {
                    $skipped++;
                    continue;
                }

                $ins->execute([
                    $sid,
                    $suf,
                    (string) ($r['doc_no'] ?? ''),
                    (int) ($r['doc_type'] ?? 0),
                    ($r['supplier_id'] ?? null) !== null ? (int) $r['supplier_id'] : null,
                    (string) ($r['supplier_code'] ?? ''),
                    mb_substr((string) ($r['supplier_name'] ?? ''), 0, 255),
                    (float) ($r['net_amount'] ?? 0),
                    (float) ($r['total_amount'] ?? 0),
                    ($r['invoice_date'] ?? null) ?: null,
                    $brch,
                    ($r['last_modify'] ?? null) ?: null,
                ]);
                $stored++;
            }
        } elseif ($kind === 'purchase_lines') {
            // بنود فواتير الشراء والمردود — المفتاح (sth_id, suffix, line_no) في hq_invoice_lines
            // تقبل فقط إذا كانت (sth_id, suffix) موجودة في hq_invoices تابعة لنفس الفرع
            $expectedBrch = BR_AGENT_BRCH[$agent] ?? 0;

            $candidateIds = [];
            foreach ($rows as $r) {
                if (is_array($r)) {
                    $sid = (int) ($r['sth_id'] ?? 0);
                    if ($sid > 0) $candidateIds[$sid] = true;
                }
            }

            $validHeaders = [];
            if (!empty($candidateIds) && $expectedBrch > 0) {
                $ids = array_keys($candidateIds);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $chk = $pdo->prepare(
                    "SELECT sth_id, suffix FROM hq_invoices WHERE company_branch = ? AND sth_id IN ($placeholders)"
                );
                $chk->execute(array_merge([$expectedBrch], $ids));
                while ($row = $chk->fetch(PDO::FETCH_ASSOC)) {
                    $validHeaders[$row['sth_id'] . '|' . $row['suffix']] = true;
                }
            }

            $ins = $pdo->prepare(
                'INSERT INTO hq_invoice_lines
                    (sth_id, suffix, line_no, item_id, item_code, item_name, quantity, unit_price, cost_price)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    item_id    = VALUES(item_id),
                    item_code  = VALUES(item_code),
                    item_name  = VALUES(item_name),
                    quantity   = VALUES(quantity),
                    unit_price = VALUES(unit_price),
                    cost_price = VALUES(cost_price)'
            );

            foreach ($rows as $r) {
                if (!is_array($r)) { $skipped++; continue; }
                $sid = (int) ($r['sth_id'] ?? 0);
                $ln  = (int) ($r['line_no'] ?? 0);
                $suf = (string) ($r['suffix'] ?? '');
                if ($sid <= 0 || $suf === '') { $skipped++; continue; }

                if (!isset($validHeaders[$sid . '|' . $suf])) {
                    $skipped++;
                    continue;
                }

                $ins->execute([
                    $sid,
                    $suf,
                    $ln,
                    ($r['item_id'] ?? null) !== null ? (int) $r['item_id'] : null,
                    mb_substr((string) ($r['item_code'] ?? ''), 0, 64),
                    mb_substr((string) ($r['item_name'] ?? ''), 0, 255),
                    (float) ($r['quantity'] ?? 0),
                    (float) ($r['unit_price'] ?? 0),
                    (float) ($r['cost_price'] ?? 0),
                ]);
                $stored++;
            }
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }

    // النبضة جوّه نفس الطلب — الوكيل مش محتاج يعمل نداء تاني للداتا
    try { agent_beat($agent, $stored); } catch (Throwable $ex) { }

    echo json_encode(
        ['ok' => true, 'stored' => $stored, 'skipped' => $skipped, 'kind' => $kind],
        JSON_UNESCAPED_UNICODE
    );
} catch (Throwable $ex) {
    br_die('Server error.', 500);
}
