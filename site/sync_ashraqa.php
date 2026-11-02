<?php
/**
 * sync_ashraqa.php — استقبال أرصدة فرع اشراقة.
 * =====================================================================
 *  جهاز اشراقة (نظام Redux) بيبعت هنا POST كل نص ساعة عن طريق HTTPS.
 *  مفيش بورت مفتوح ولا DDNS عندهم — الجهاز بس بيطلع نت زي أي متصفح.
 *
 *  الأمان:
 *    - محمي بتوكن سري (ASHRAQA_SYNC_TOKEN) — نفس القيمة في السكربت عندهم
 *    - POST بس
 *    - بيقبل JSON فقط
 *
 *  ⚠️ الملف ده مش بيحتاج تسجيل دخول (الجهاز مش بني آدم)، عشان كده
 *     التوكن لازم يكون طويل وعشوائي.
 * =====================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/site_profile.php';
require_once __DIR__ . '/sync_tokens.php';
if (!site_profile()['redux_sync']) { http_response_code(404); exit; }


// سجل الأخطاء — الملف ده مابيعملش require لـauth.php ولا agent_lib.php
require_once __DIR__ . '/errlog_lib.php';
errlog_install();

// ===== التوكن السري — غيّره لقيمة طويلة عشوائية =====
// ونفس القيمة بالظبط تتحط في agent_ashraqa.ps1 عند اشراقة
const ASHRAQA_SYNC_TOKEN = 'ashraqa_9xK2mQ7vR4tN8pL5wZ3hB6yF1cD0jS';

// أقصى عدد صفوف مقبول في الدفعة الواحدة (حماية من طلب مبالغ فيه)
const ASHRAQA_MAX_ROWS = 100000;

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');

function sync_die(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------------
// 1) POST بس
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    sync_die('POST only.', 405);
}

// ---------------------------------------------------------------------
// 2) التحقق من التوكن (من الهيدر أو من الـ body)
// ---------------------------------------------------------------------
$raw   = file_get_contents('php://input') ?: '';
$token = $_SERVER['HTTP_X_SYNC_TOKEN'] ?? '';

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    sync_die('Invalid JSON.');
}
if ($token === '' && isset($payload['token'])) {
    $token = (string) $payload['token'];
}
if (!(site_id() === 'ashraqa' ? synctok_ok('hq', (string) $token, ['hq']) : hash_equals(ASHRAQA_SYNC_TOKEN, (string) $token))) {
    sync_die('Unauthorized.', 401);
}

// ---------------------------------------------------------------------
// 3) قراءة الصفوف
// ---------------------------------------------------------------------
$rows = $payload['rows'] ?? null;
if (!is_array($rows)) {
    sync_die('Missing rows.');
}

// وضع الإرسال:
//   full    = الوكيل باعت كل الأصناف   -> استبدال كامل (الافتراضي)
//   partial = الوكيل باعت المتغيّر بس  -> تحديث موضعي
//
// الافتراضي `full` عن قصد: أي وكيل قديم مش عارف الوضع ده هيفضل
// يشتغل بالسلوك الأصلي بالحرف.
$mode = ($payload['mode'] ?? 'full') === 'partial' ? 'partial' : 'full';
if (count($rows) > ASHRAQA_MAX_ROWS) {
    sync_die('Too many rows.');
}

require_once __DIR__ . '/config.php';

try {
    $pdo = db();
} catch (Throwable $ex) {
    sync_die('DB connection failed.', 500);
}

// ---------------------------------------------------------------------
// 4) الكتابة في ترانزاكشن
//
//    full:    نمسح القديم ونكتب الجديد. لو صنف اتشال عندهم يختفي عندنا.
//    partial: نحدّث المبعوت بس والباقي زي ما هو. الوكيل هو اللي مسؤول
//             إنه يبعت الأصناف اللي اختفت برصيد صفر، لأن استعلامه فيه
//             `WHERE Balance <> 0` فالصنف اللي بيوصل صفر بيختفي من
//             نتيجته بدل ما يرجع بصفر.
//
//    ⚠️ في الوضع الجزئي بنمسح الصفوف اللي رصيدها وصل صفر بدل ما نسيبها،
//       عشان الجدول ما يكبرش بصفوف ملهاش لازمة ويفضل مطابق للـfull.
//
//    ولو حصل أي غلط في النص، الترانزاكشن بترجع كل حاجة زي ما كانت.
// ---------------------------------------------------------------------
$inserted = 0;
$skipped  = 0;
$removed  = 0;   // أصناف رصيدها بقى صفر (الوضع الجزئي)

try {
    $pdo->beginTransaction();

    if ($mode === 'full') {
        // نكتب في جدول مؤقت الأول، وبعدين نبدّل — عشان الموقع ما يشوفش
        // الجدول فاضي لحظة المزامنة
        $pdo->exec('CREATE TEMPORARY TABLE tmp_ashraqa LIKE ashraqa_balances');
        $target = 'tmp_ashraqa';
    } else {
        // جزئي: بنكتب في الجدول الأصلي على طول
        $target = 'ashraqa_balances';
    }

    $ins = $pdo->prepare(
        "INSERT INTO {$target} (barcode, remote_item_id, item_name, balance, store_id)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            balance        = VALUES(balance),
            remote_item_id = VALUES(remote_item_id),
            item_name      = VALUES(item_name),
            store_id       = VALUES(store_id)"
    );
    // في الوضع الجزئي: الصنف اللي رصيده بقى صفر بيتشال
    $del = $pdo->prepare('DELETE FROM ashraqa_balances WHERE barcode = ?');

    foreach ($rows as $r) {
        if (!is_array($r)) { $skipped++; continue; }

        $bc = trim((string) ($r['barcode'] ?? ''));

        // مفيش أي شرط على شكل الكود — الفاضي والأطول من العمود بس.
        //
        // كان الشرط «٨ خانات على الأقل» على افتراض إن القصير = رقم
        // تسلسلي لخدمة. الافتراض طلع غلط مرتين:
        //
        //   ١) كان بيرمي ٩٤٤ كود منتج حقيقي — 505039 (زمزمية) و
        //      00111 (كتاب) و 0096 و 804512.
        //
        //   ٢) و«القمامة» اللي كان بيستهدفها طلعت بضاعة كمان. hq_codes
        //      عندنا فيه أكواد بحروف (easy · PRIMA · TRE · tech) وأكواد
        //      بنجمة (*6222002206080 · *9*-*-9--). اتنين من تلات عيّنات
        //      اتأكد إنهم موجودين عندنا فعلاً.
        //
        // ⚠️ الأصفار البادئة: 0096 غير 96. العمودين varchar(64) فمحفوظة،
        //    بس ممنوع أي كود يتحوّل لرقم في أي خطوة.
        //
        // ⚠️ الترميز utf8mb4_unicode_ci غير حساس لحالة الحرف والهمزات،
        //    و barcode مفتاح أساسي. اتفحص يوم 2026-08-23: مفيش أي تصادم
        //    في hq_codes. لو ظهر تصادم بعدين، الحل تغيير الـcollation
        //    لـ utf8mb4_bin مش إرجاع الفلتر.
        if ($bc === '' || strlen($bc) > 64) { $skipped++; continue; }

        $iid  = (int) ($r['item_id'] ?? 0);
        $name = trim((string) ($r['name'] ?? ''));
        if (mb_strlen($name) > 255) $name = mb_substr($name, 0, 255);

        // الرصيد ممكن يكون سالب — بنسيبه زي ما هو عشان يبان إن فيه
        // مشكلة عندهم، مش بنصفّره.
        $bal  = (float) ($r['balance'] ?? 0);
        $sid  = (int) ($r['store_id'] ?? 1);

        if ($mode === 'partial' && abs($bal) < 0.0001) {
            // رصيد صفر في الوضع الجزئي = الصنف خلص عندهم
            $del->execute([$bc]);
            $removed++;
            continue;
        }

        $ins->execute([$bc, $iid, $name !== '' ? $name : null, $bal, $sid]);
        $inserted++;
    }

    if ($mode === 'full') {
        // التبديل: نفضّي الأصلي ونملاه من المؤقت
        $pdo->exec('DELETE FROM ashraqa_balances');
        $pdo->exec('INSERT INTO ashraqa_balances (barcode, remote_item_id, item_name, balance, store_id)
                    SELECT barcode, remote_item_id, item_name, balance, store_id FROM tmp_ashraqa');
    }

    $note = [];
    if ($skipped > 0) $note[] = "تم تجاهل {$skipped} صف";
    if ($mode === 'partial') $note[] = "جزئي (شيل {$removed})";
    $pdo->prepare('INSERT INTO ashraqa_sync_log (rows_count, status, note) VALUES (?, ?, ?)')
        ->execute([$inserted, 'ok', $note ? implode(' · ', $note) : null]);

    $pdo->commit();
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    try {
        $pdo->prepare('INSERT INTO ashraqa_sync_log (rows_count, status, note) VALUES (?, ?, ?)')
            ->execute([0, 'error', mb_substr($ex->getMessage(), 0, 255)]);
    } catch (Throwable $ignore) {}
    sync_die('Write failed.', 500);
}

echo json_encode([
    'ok'       => true,
    'received' => count($rows),
    'stored'   => $inserted,
    'skipped'  => $skipped,
    'removed'  => $removed,
    'mode'     => $mode,
    'time'     => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE);
