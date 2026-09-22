<?php
/**
 * sync_hq.php — استقبال الأصناف والأسعار من جهاز الإدارة.
 * =====================================================================
 *  جهاز الإدارة (SQL Server / Anzmah_FagalaHQ) بيبعت هنا كل نص ساعة.
 *  الموقع بيقرا من الجداول دي بدل ما يسأل الجسر لحظياً — فيشتغل
 *  حتى لو النت عند الإدارة فاصل.
 *
 *  المزامنة upsert: الجديد يتحط والموجود يتحدّث والقديم يفضل.
 *
 *  الأمان: توكن سري + POST + JSON بس.
 *  ⚠️ الملف ده مش محتاج تسجيل دخول (الجهاز مش بني آدم)، فالتوكن
 *     لازم يكون طويل وعشوائي.
 * =====================================================================
 */
declare(strict_types=1);

// سجل الأخطاء — الملف ده مابيعملش require لـauth.php ولا agent_lib.php
require_once __DIR__ . '/errlog_lib.php';
errlog_install();

// ===== التوكن السري =====
// 🔴 على موقع إشراقة اللي بيبعت هنا هو local/agent_ashraqa_web.ps1 (أصناف وأسعار
//    وأرصدة إشراقة من Redux) — مش وكيل الإدارة. التوكن **مختلف** عن توكن الفجالة
//    عن قصد (٢٢ سبتمبر ٢٠٢٦): كانوا نفس القيمة، فاللي معاه واحد كان يكتب على الموقعين.
//    لازم يطابق $SyncToken في agent_ashraqa_web.ps1 بالحرف.
const HQ_SYNC_TOKEN = 'ashweb_15c488c25181d22bec038b314ba6a679';

const HQ_MAX_ROWS = 200000;

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');

function hq_die(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// طلب watermarks (GET) — الوكيل بيسأل: آخر تعديل نقلته لكل شهر كان إيه؟
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['watermarks'])) {
    // التوكن من الهيدر بس. كان بييجي في الـURL (?token=) وده بيتسجّل
    // في لوجات السيرفر بالنص الصريح.
    $token = (string) ($_SERVER['HTTP_X_SYNC_TOKEN'] ?? '');
    if (!hash_equals(HQ_SYNC_TOKEN, $token)) hq_die('Unauthorized.', 401);
    require_once __DIR__ . '/config.php';
    try {
        $pdo = db();
        $rows = $pdo->query('SELECT suffix, last_modify FROM hq_invoice_sync_state')->fetchAll();
        $wm = [];
        foreach ($rows as $r) {
            $wm[(string) $r['suffix']] = $r['last_modify'];
        }
        echo json_encode(['ok' => true, 'watermarks' => (object) $wm], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        echo json_encode(['ok' => true, 'watermarks' => (object) []], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 1) POST بس
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    hq_die('POST only.', 405);
}

// 2) التوكن
$raw   = file_get_contents('php://input') ?: '';
$token = $_SERVER['HTTP_X_SYNC_TOKEN'] ?? '';

$payload = json_decode($raw, true);
if (!is_array($payload)) hq_die('Invalid JSON.');
if ($token === '' && isset($payload['token'])) $token = (string) $payload['token'];
if (!hash_equals(HQ_SYNC_TOKEN, (string) $token)) hq_die('Unauthorized.', 401);

// 3) نوع الدفعة (items / stores) — كل نوع ليه معالجة
$kind = (string) ($payload['kind'] ?? '');
$rows = $payload['rows'] ?? null;
if (!is_array($rows)) hq_die('Missing rows.');
if (count($rows) > HQ_MAX_ROWS) hq_die('Too many rows.');

require_once __DIR__ . '/config.php';

try {
    $pdo = db();
} catch (Throwable $ex) {
    hq_die('DB connection failed.', 500);
}

$stored = 0;
$skipped = 0;

try {
    $pdo->beginTransaction();

    if ($kind === 'items') {
        // upsert للأصناف — الجديد يتحط والموجود يتحدّث
        $ins = $pdo->prepare(
            'INSERT INTO hq_items (barcode, item_id, name, sale_price)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                item_id = VALUES(item_id),
                name = VALUES(name),
                sale_price = VALUES(sale_price)'
        );

        foreach ($rows as $r) {
            if (!is_array($r)) { $skipped++; continue; }
            $bc = trim((string) ($r['barcode'] ?? ''));
            // نظام الإدارة أكواده منتجات عادية (زي '91' لقلم) — مش زي
            // اشراقة اللي فيها أكواد خدمات قصيرة. فمنفلترش بالطول هنا،
            // بنرفض الفاضي بس.
            if ($bc === '') { $skipped++; continue; }
            if (!preg_match('/^[0-9A-Za-z\-]+$/', $bc)) { $skipped++; continue; }

            $iid   = (int) ($r['item_id'] ?? 0);
            $name  = trim((string) ($r['name'] ?? ''));
            if (mb_strlen($name) > 255) $name = mb_substr($name, 0, 255);
            $price = (float) ($r['price'] ?? 0);

            $ins->execute([$bc, $iid, $name !== '' ? $name : null, $price]);
            $stored++;
        }
    } elseif ($kind === 'stores') {
        $ins = $pdo->prepare(
            'INSERT INTO hq_stores (sto_id, name)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name)'
        );
        foreach ($rows as $r) {
            if (!is_array($r)) { $skipped++; continue; }
            $sid = (int) ($r['sto_id'] ?? 0);
            if ($sid <= 0) { $skipped++; continue; }
            $name = trim((string) ($r['name'] ?? ''));
            if (mb_strlen($name) > 150) $name = mb_substr($name, 0, 150);
            $ins->execute([$sid, $name !== '' ? $name : null]);
            $stored++;
        }
    } elseif ($kind === 'invoices') {
        // رؤوس الفواتير — upsert بالـ sth_id
        $ins = $pdo->prepare(
            'INSERT INTO hq_invoices
                (sth_id, suffix, doc_no, doc_type, supplier_id, supplier_code,
                 supplier_name, net_amount, total_amount, invoice_date, company_branch, last_modify)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                suffix = VALUES(suffix), doc_no = VALUES(doc_no), doc_type = VALUES(doc_type),
                supplier_id = VALUES(supplier_id), supplier_code = VALUES(supplier_code),
                supplier_name = VALUES(supplier_name), net_amount = VALUES(net_amount),
                total_amount = VALUES(total_amount), invoice_date = VALUES(invoice_date),
                company_branch = VALUES(company_branch), last_modify = VALUES(last_modify)'
        );
        foreach ($rows as $r) {
            if (!is_array($r)) { $skipped++; continue; }
            $sid = (int) ($r['sth_id'] ?? 0);
            if ($sid <= 0) { $skipped++; continue; }
            $ins->execute([
                $sid,
                (string) ($r['suffix'] ?? ''),
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

        // تحديث حالة المزامنة لكل شهر وصل
        if (!empty($payload['sync_state']) && is_array($payload['sync_state'])) {
            $ss = $pdo->prepare(
                'INSERT INTO hq_invoice_sync_state (suffix, last_modify)
                 VALUES (?, ?) ON DUPLICATE KEY UPDATE last_modify = VALUES(last_modify)'
            );
            foreach ($payload['sync_state'] as $suf => $lm) {
                $ss->execute([(string) $suf, $lm ?: null]);
            }
        }
    } elseif ($kind === 'invoice_lines') {
        // بنود الفواتير — المفتاح (sth_id, suffix, line_no)
        // الـ suffix ضروري: STH_ID بيتكرر بين جداول الشهور، ومن غيره
        // بنود فاتورة بتمسح بنود فاتورة تانية بنفس الرقم من شهر مختلف.
        $ins = $pdo->prepare(
            'INSERT INTO hq_invoice_lines
                (sth_id, suffix, line_no, item_id, item_code, item_name, quantity, unit_price, cost_price)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                item_id = VALUES(item_id), item_code = VALUES(item_code),
                item_name = VALUES(item_name), quantity = VALUES(quantity),
                unit_price = VALUES(unit_price), cost_price = VALUES(cost_price)'
        );
        foreach ($rows as $r) {
            if (!is_array($r)) { $skipped++; continue; }
            $sid = (int) ($r['sth_id'] ?? 0);
            $ln  = (int) ($r['line_no'] ?? 0);
            $suf = (string) ($r['suffix'] ?? '');
            // من غير suffix مش هنقدر نضمن الربط الصح — نرفض بدل ما نخرّب
            if ($sid <= 0 || $suf === '') { $skipped++; continue; }
            $ins->execute([
                $sid, $suf, $ln,
                ($r['item_id'] ?? null) !== null ? (int) $r['item_id'] : null,
                mb_substr((string) ($r['item_code'] ?? ''), 0, 64),
                mb_substr((string) ($r['item_name'] ?? ''), 0, 255),
                (float) ($r['quantity'] ?? 0),
                (float) ($r['unit_price'] ?? 0),
                (float) ($r['cost_price'] ?? 0),
            ]);
            $stored++;
        }
    } elseif ($kind === 'balances_reset') {
        // ⚠️ الطريقة القديمة (مسح كامل قبل الإرسال) — سايبينها عشان
        //    وكيل قديم مايقعش، بس الوكيل الجديد مابيستخدمهاش.
        //    مشكلتها إن الجدول بيفضل فاضي لحد ما آخر دفعة توصل.
        $pdo->exec('DELETE FROM hq_balances');
        $stored = 0;
    } elseif ($kind === 'balances') {
        // دفعة أرصدة — upsert مع ختم الدفعة (batch).
        // الصفوف الجديدة بتتكتب فوق القديمة على طول، والقديم اللي
        // ماوصلش في الدفعة دي بيتمسح في balances_commit في الآخر.
        // كده الجدول مايفضاش ولا لحظة واحدة.
        $batch = mb_substr((string) ($payload['batch'] ?? ''), 0, 20);
        $ins = $pdo->prepare(
            'INSERT INTO hq_balances (item_id, sto_id, balance, batch)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                balance = VALUES(balance), batch = VALUES(batch)'
        );

        // ★ عكس مخزن الإدارة في جدول الفروع كمان.
        //   وكلاء الفروع بيبعتوا مخزن كل واحد فيهم، ومخزن الإدارة
        //   مالوش وكيل فرع — فمن غير العكس ده مخزون الإدارة بيختفي
        //   من الموقع أول ما balance_source يبقى 'branch'.
        //   الوكيل أصلاً بيبعت الصفوف دي هنا، فمفيش ولا طلب زيادة.
        $hqStores = array_values(array_filter(array_map(
            'intval',
            explode(',', (string) setting('hq_own_sto_ids', '1'))
        ), fn($x) => $x > 0));

        // 🔴 مجهّز بره الحلقة — ممنوع prepare جوه foreach
        $insBr = $hqStores ? $pdo->prepare(
            'INSERT INTO hq_branch_balances (agent_key, item_id, sto_id, balance, batch)
             VALUES (\'hq\', ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                agent_key  = VALUES(agent_key),
                balance    = VALUES(balance),
                batch      = VALUES(batch),
                updated_at = NOW()'
        ) : null;

        foreach ($rows as $r) {
            if (!is_array($r)) { $skipped++; continue; }
            $iid = (int) ($r['item_id'] ?? 0);
            $sid = (int) ($r['sto_id'] ?? 0);
            if ($iid <= 0 || $sid <= 0) { $skipped++; continue; }
            $bal = (float) ($r['balance'] ?? 0);
            $ins->execute([$iid, $sid, $bal, $batch]);
            if ($insBr !== null && in_array($sid, $hqStores, true)) {
                $insBr->execute([$iid, $sid, $bal, $batch]);
            }
            $stored++;
        }
    } elseif ($kind === 'balances_commit') {
        // نهاية الاستبدال — نمسح اللي ماوصلش في الدفعة دي (أرصدة بقت صفر
        // أو أصناف اتشالت). عملية واحدة سريعة جوه transaction.
        $batch = mb_substr((string) ($payload['batch'] ?? ''), 0, 20);
        if ($batch === '') {
            $pdo->rollBack();
            hq_die('Missing batch.');
        }
        $st = $pdo->prepare('DELETE FROM hq_balances WHERE batch <> ? OR batch IS NULL');
        $st->execute([$batch]);
        $stored = $st->rowCount();

        // ونفس التنضيف لصفوف الإدارة المعكوسة في جدول الفروع — الصنف
        // اللي اتشال من مخزن الإدارة خالص لازم يمشي من هنا كمان.
        // 🔴 مقصور على agent_key='hq' وبس. وكلاء الفروع مابيبعتوش ختم
        //    (صفوفهم batch = NULL) فلو شيلنا الشرط ده هنمسح أرصدة
        //    الفروع الأربعة كلها كل جولة.
        $stBr = $pdo->prepare(
            'DELETE FROM hq_branch_balances
              WHERE agent_key = \'hq\' AND (batch <> ? OR batch IS NULL)'
        );
        $stBr->execute([$batch]);
    } elseif ($kind === 'unposted_reset') {
        // ⚠️ الطريقة القديمة — سايبينها للتوافق بس (شوف balances_reset)
        $pdo->exec('DELETE FROM hq_unposted');
        $stored = 0;
    } elseif ($kind === 'unposted') {
        // الفواتير غير المرحّلة — upsert مع ختم الدفعة.
        // اللي بيتحفظ في نظام الشركة بيختفي من هنا في unposted_commit.
        $batch = mb_substr((string) ($payload['batch'] ?? ''), 0, 20);
        $ins = $pdo->prepare(
            'INSERT INTO hq_unposted
                (sth_id, doc_no, doc_type, supplier_id, supplier_code, supplier_name,
                 net_amount, total_amount, invoice_date, company_branch, remark, batch)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                doc_no = VALUES(doc_no), doc_type = VALUES(doc_type),
                supplier_id = VALUES(supplier_id), supplier_code = VALUES(supplier_code),
                supplier_name = VALUES(supplier_name), net_amount = VALUES(net_amount),
                total_amount = VALUES(total_amount), invoice_date = VALUES(invoice_date),
                company_branch = VALUES(company_branch), remark = VALUES(remark),
                batch = VALUES(batch)'
        );
        foreach ($rows as $r) {
            if (!is_array($r)) { $skipped++; continue; }
            $sid = (int) ($r['sth_id'] ?? 0);
            if ($sid <= 0) { $skipped++; continue; }
            $ins->execute([
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
                $batch,
            ]);
            $stored++;
        }
    } elseif ($kind === 'unposted_commit') {
        // نهاية الاستبدال: نشيل الفواتير اللي ماوصلتش (اتحفظت أو اتلغت)
        // وبنودها معاها. لو الدفعة فاضية (مفيش فواتير غير محفوظة خالص)
        // الوكيل بيبعت batch فاضي ومعناها "فضّي الجدولين".
        $batch = (string) ($payload['batch'] ?? '');

        if ($batch === '') {
            $pdo->exec('DELETE FROM hq_unposted_lines');
            $pdo->exec('DELETE FROM hq_unposted');
            $stored = 0;
        } else {
            $batch = mb_substr($batch, 0, 20);
            // البنود الأول (عشان مانسيبش بنود يتيمة)
            $dl = $pdo->prepare(
                'DELETE FROM hq_unposted_lines
                 WHERE sth_id IN (SELECT sth_id FROM hq_unposted
                                  WHERE batch <> ? OR batch IS NULL)'
            );
            $dl->execute([$batch]);

            $st = $pdo->prepare('DELETE FROM hq_unposted WHERE batch <> ? OR batch IS NULL');
            $st->execute([$batch]);
            $stored = $st->rowCount();
        }
    } elseif ($kind === 'unposted_lines') {
        // بنود الفواتير غير المرحّلة
        $ins = $pdo->prepare(
            'INSERT INTO hq_unposted_lines
                (sth_id, line_no, item_id, item_code, item_name, quantity, unit_price, cost_price)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                item_id = VALUES(item_id), item_code = VALUES(item_code),
                item_name = VALUES(item_name), quantity = VALUES(quantity),
                unit_price = VALUES(unit_price), cost_price = VALUES(cost_price)'
        );
        foreach ($rows as $r) {
            if (!is_array($r)) { $skipped++; continue; }
            $sid = (int) ($r['sth_id'] ?? 0);
            $ln  = (int) ($r['line_no'] ?? 0);
            if ($sid <= 0) { $skipped++; continue; }
            $ins->execute([
                $sid, $ln,
                ($r['item_id'] ?? null) !== null ? (int) $r['item_id'] : null,
                mb_substr((string) ($r['item_code'] ?? ''), 0, 64),
                mb_substr((string) ($r['item_name'] ?? ''), 0, 255),
                (float) ($r['quantity'] ?? 0),
                (float) ($r['unit_price'] ?? 0),
                (float) ($r['cost_price'] ?? 0),
            ]);
            $stored++;
        }
    } elseif ($kind === 'codes') {
        // جدول الأكواد الكامل (للجرد) — upsert
        $ins = $pdo->prepare(
            'INSERT INTO hq_codes (code, item_id, name, code_type, unit_factor, price, cost)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                item_id = VALUES(item_id), name = VALUES(name),
                code_type = VALUES(code_type), unit_factor = VALUES(unit_factor),
                price = VALUES(price), cost = VALUES(cost)'
        );
        foreach ($rows as $r) {
            if (!is_array($r)) { $skipped++; continue; }
            $code = trim((string) ($r['code'] ?? ''));
            if ($code === '') { $skipped++; continue; }
            $ins->execute([
                mb_substr($code, 0, 64),
                (int) ($r['item_id'] ?? 0),
                mb_substr((string) ($r['name'] ?? ''), 0, 255),
                (int) ($r['code_type'] ?? 1),
                (float) ($r['unit_factor'] ?? 1),
                ($r['price'] ?? null) !== null ? (float) $r['price'] : null,
                ($r['cost'] ?? null) !== null ? (float) $r['cost'] : null,
            ]);
            $stored++;
        }
    } elseif ($kind === 'item_cats') {
        // تصنيف الأصناف (القسم الأول) — المصدر ITEMS.IS1_ID
        // بيتستخدم في فلترة "الكتب الخارجية" في موديول الحجز.
        // upsert عادي — الكتالوج بيتغير مرة أو مرتين في اليوم.
        $ins = $pdo->prepare(
            'INSERT INTO hq_item_cats (item_id, is1_id, item_name)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                is1_id = VALUES(is1_id), item_name = VALUES(item_name)'
        );
        foreach ($rows as $r) {
            if (!is_array($r)) { $skipped++; continue; }
            $iid = (int) ($r['item_id'] ?? 0);
            if ($iid <= 0) { $skipped++; continue; }
            $ins->execute([
                $iid,
                (int) ($r['is1_id'] ?? 0),
                mb_substr(trim((string) ($r['name'] ?? '')), 0, 255) ?: null,
            ]);
            $stored++;
        }
    } elseif ($kind === 'cat_names') {
        // أسماء الأقسام (ITEM_SPEC1) — جدول صغير جداً (~22 صف)
        $ins = $pdo->prepare(
            'INSERT INTO hq_cat_names (is1_id, name) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name)'
        );
        foreach ($rows as $r) {
            if (!is_array($r)) { $skipped++; continue; }
            $cid  = (int) ($r['is1_id'] ?? 0);
            $name = trim((string) ($r['name'] ?? ''));
            if ($cid <= 0 || $name === '') { $skipped++; continue; }
            $ins->execute([$cid, mb_substr($name, 0, 255)]);
            $stored++;
        }
    } elseif ($kind === 'purchased_items') {
        // الأصناف اللي نزلت في أي فاتورة شراء — DISTINCT ITEM_ID من
        // **كل** جداول الشهور. بختم الدفعة (batch) زي الأرصدة، عشان
        // الجدول مايفضاش ولا لحظة بين المسح وآخر دفعة.
        $batch = mb_substr((string) ($payload['batch'] ?? ''), 0, 20);
        $ins = $pdo->prepare(
            'INSERT INTO hq_purchased_items (item_id, batch) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE batch = VALUES(batch)'
        );
        foreach ($rows as $r) {
            $iid = is_array($r) ? (int) ($r['item_id'] ?? 0) : (int) $r;
            if ($iid <= 0) { $skipped++; continue; }
            $ins->execute([$iid, $batch]);
            $stored++;
        }
    } elseif ($kind === 'purchased_commit') {
        // آخر دفعة وصلت — امسح اللي فضل بختم قديم.
        // بنرفض الختم الفاضي: ده معناه مسح الجدول كله وده غلط دايماً هنا
        // (لازم يكون فيه أصناف اتشرت فعلاً).
        $batch = mb_substr((string) ($payload['batch'] ?? ''), 0, 20);
        if ($batch === '') {
            $pdo->rollBack();
            hq_die('Missing batch.');
        }
        $st = $pdo->prepare('DELETE FROM hq_purchased_items WHERE batch <> ? OR batch IS NULL');
        $st->execute([$batch]);
        $stored = $st->rowCount();
    } else {
        $pdo->rollBack();
        hq_die('Unknown kind: ' . $kind);
    }

    $pdo->prepare('INSERT INTO hq_sync_log (kind, rows_count, status, note) VALUES (?, ?, ?, ?)')
        ->execute([$kind, $stored, 'ok', $skipped > 0 ? "تم تجاهل {$skipped} صف" : null]);

    $pdo->commit();

    // ===== تنضيف دوري =====
    // الوكلاء بيكتبوا في hq_sync_log كل نص ساعة، والإشعارات بتتراكم —
    // من غير تنضيف الجدولين بيكبروا للأبد. بنشغّلها مرة كل ~20 دفعة
    // عشان ماتتقلش على كل طلب.
    if (random_int(1, 20) === 1) {
        try {
            // عمود الوقت في hq_sync_log اسمه synced_at (مش created_at)
            $pdo->exec('DELETE FROM hq_sync_log WHERE synced_at < NOW() - INTERVAL 60 DAY');
        } catch (Throwable $ignore) {}
        try {
            $pdo->exec('DELETE FROM notifications WHERE is_read = 1 AND created_at < NOW() - INTERVAL 90 DAY');
        } catch (Throwable $ignore) {}
        try {
            $pdo->exec('DELETE FROM login_log WHERE created_at < NOW() - INTERVAL 180 DAY');
        } catch (Throwable $ignore) {}
    }
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    try {
        $pdo->prepare('INSERT INTO hq_sync_log (kind, rows_count, status, note) VALUES (?, ?, ?, ?)')
            ->execute([$kind, 0, 'error', mb_substr($ex->getMessage(), 0, 255)]);
    } catch (Throwable $ignore) {}
    hq_die('Write failed.', 500);
}

echo json_encode([
    'ok'       => true,
    'kind'     => $kind,
    'received' => count($rows),
    'stored'   => $stored,
    'skipped'  => $skipped,
    'time'     => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE);
