<?php
/**
 * bridge_write.php — جسر الكتابة (ترحيل فواتير نقطة البيع)
 * =====================================================================
 *
 *  ⚠️⚠️ الملف ده بيكتب في قاعدة الشركة. اقرا ده كله قبل ما ترفعه. ⚠️⚠️
 *
 *  ليه ملف منفصل عن bridge.php؟
 *      bridge.php قراءة فقط، وده حاجز أمان مهم — لو حد سرق مفتاحه،
 *      أقصى ضرر يعمله إنه يقرا. لو ضعّفناه عشان الترحيل، الحاجز ده يروح.
 *
 *      فبدل كده: ملف تاني، بمفتاح مختلف، بحمايات أقوى، وسويتش تشغيل.
 *      وbridge.php يفضل مقفول على القراءة زي ما هو.
 *
 *  الحمايات (7 طبقات):
 *      1. سويتش تشغيل  — مقفول افتراضيًا، تفتحه لما ترحّل وتقفله بعدها
 *      2. مفتاح منفصل  — غير مفتاح القراءة تمامًا
 *      3. قائمة بيضاء للجداول — أي جدول تاني = رفض
 *      4. أوامر مسموحة بس (INSERT/UPDATE) — مفيش DELETE/DROP/ALTER
 *      5. transaction واحدة — أي فشل = ROLLBACK كامل
 *      6. حد أقصى للأوامر — يمنع الطلبات الضخمة
 *      7. سجل كامل — كل عملية بتتسجل في ملف
 *
 *  الاستخدام:
 *      POST { key, statements: [...] }
 *      → ينفّذهم كلهم في transaction واحدة
 *      → لو أي واحد فشل: ROLLBACK + رسالة الخطأ
 * =====================================================================
 */
declare(strict_types=1);

/* ═══════════════════════════════════════════════════════════════════
 *  الإعدادات
 * ═══════════════════════════════════════════════════════════════════ */

// ★★★ سويتش التشغيل ★★★
// false = مقفول تمامًا (الافتراضي)
// خليه false، وحوّله true بس وقت الترحيل، ورجّعه false بعدها.
const WRITE_ENABLED = false;

// ★ مفتاح الكتابة — لازم يكون مختلف تمامًا عن مفتاح القراءة
//   غيّره لمفتاح طويل عشوائي (32 حرف على الأقل)
const WRITE_KEY = 'CHANGE_THIS_TO_A_LONG_RANDOM_KEY';

// بيانات الاتصال (نفس bridge.php)
const BRIDGE_ODBC_DSN  = 'True_SQL';
const BRIDGE_ODBC_USER = 'sa';
const BRIDGE_ODBC_PASS = 'ok';

// أقصى عدد أوامر في الطلب الواحد
const MAX_STATEMENTS = 500;

// ملف السجل (كل عملية بتتسجل هنا)
const LOG_FILE = __DIR__ . '/bridge_write.log';

/**
 * ★ القائمة البيضاء للجداول ★
 *
 * أي INSERT/UPDATE على جدول مش في القايمة دي → رفض فوري.
 * دي أهم حماية: حتى لو حد سرق المفتاح، مايقدرش يلمس أي جدول تاني.
 *
 * الجداول دي بس اللي فاتورة البيع محتاجاها.
 */
const ALLOWED_TABLES = [
    // الجداول الشهرية (بأي شهر: _26_07، _26_08، إلخ)
    'STO_TRANS_HEADER_',
    'STO_TRANS_LINES_',
    'STO_TRANS_ITEM_LOG_',
    'STO_TRANS_POS_',
    'ACC_TRANS_HEADER_',
    'ACC_TRANS_LINES_',
    'ACC_TRANS_LOG_',
    // الفهارس
    'STO_TRANS_INDEX',
    'ACC_TRANS_INDEX',
    // طابور المزامنة
    'DB_REPL_SEND',
    // جداول المزامنة (_REPL)
    'STO_TRANS_HEADER_REPL',
    'STO_TRANS_LINES_REPL',
    'STO_TRANS_ITEM_LOG_REPL',
    'STO_TRANS_POS_REPL',
    'STO_TRANS_INDEX_REPL',
    'ACC_TRANS_HEADER_REPL',
    'ACC_TRANS_LINES_REPL',
    'ACC_TRANS_LOG_REPL',
    'ACC_TRANS_INDEX_REPL',
];


/* ═══════════════════════════════════════════════════════════════════
 *  لا تعدّل تحت
 * ═══════════════════════════════════════════════════════════════════ */

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');

function wlog(string $msg): void {
    @file_put_contents(
        LOG_FILE,
        date('Y-m-d H:i:s') . ' | ' . ($_SERVER['REMOTE_ADDR'] ?? '?') . ' | ' . $msg . "\n",
        FILE_APPEND
    );
}

function wdie(string $msg, int $code = 400): void {
    wlog("REJECT ({$code}): {$msg}");
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}


/* ---- (1) سويتش التشغيل ---- */
if (!WRITE_ENABLED) {
    wdie('الكتابة موقوفة. غيّر WRITE_ENABLED إلى true في bridge_write.php.', 403);
}

/* ---- (2) المفتاح ---- */
$key = $_POST['key'] ?? $_SERVER['HTTP_X_BRIDGE_KEY'] ?? '';
if (!hash_equals(WRITE_KEY, (string)$key)) {
    wdie('مفتاح غير صحيح.', 401);
}

/* ---- (3) قراءة الأوامر ---- */
$raw = $_POST['statements'] ?? '';
if (is_string($raw)) {
    $statements = json_decode($raw, true);
} else {
    $statements = $raw;
}

if (!is_array($statements) || count($statements) === 0) {
    wdie('مفيش أوامر.');
}
if (count($statements) > MAX_STATEMENTS) {
    wdie('عدد الأوامر أكبر من الحد المسموح (' . MAX_STATEMENTS . ').');
}

/* ---- (4) فحص كل أمر ---- */
foreach ($statements as $i => $sql) {
    if (!is_string($sql) || trim($sql) === '') {
        wdie("الأمر رقم {$i} فاضي.");
    }

    $s = ltrim($sql);

    // (أ) الأوامر المسموحة بس
    if (!preg_match('/^(INSERT|UPDATE)\s/i', $s)) {
        wdie("الأمر رقم {$i}: مسموح INSERT و UPDATE بس.", 403);
    }

    // (ب) منع الأوامر الخطيرة صراحةً
    if (preg_match('/\b(DELETE|DROP|ALTER|TRUNCATE|EXEC|EXECUTE|MERGE|CREATE|GRANT|SHUTDOWN|xp_|sp_)\b/i', $s)) {
        wdie("الأمر رقم {$i}: يحتوي على أمر غير مسموح.", 403);
    }

    // (ج) القائمة البيضاء للجداول
    //     بنستخرج اسم الجدول من INSERT INTO x أو UPDATE x
    if (!preg_match('/^(?:INSERT\s+INTO|UPDATE)\s+([^\s(]+)/i', $s, $m)) {
        wdie("الأمر رقم {$i}: مش قادر أحدد الجدول.", 403);
    }

    // نشيل بادئة القاعدة: Anzmah_FagalaHQ.dbo.TABLE → TABLE
    $tbl = strtoupper($m[1]);
    $tbl = preg_replace('/^.*\./', '', $tbl);
    $tbl = trim($tbl, '[]"`');

    $allowed = false;
    foreach (ALLOWED_TABLES as $prefix) {
        // مطابقة كاملة أو بادئة (للجداول الشهرية)
        if ($tbl === $prefix || strpos($tbl, $prefix) === 0) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) {
        wdie("الأمر رقم {$i}: الجدول '{$tbl}' غير مسموح.", 403);
    }
}

/* ---- (5) التنفيذ في transaction واحدة ---- */
try {
    if (!in_array('odbc', PDO::getAvailableDrivers(), true)) {
        wdie('pdo_odbc غير مفعّل.', 500);
    }

    $pdo = new PDO('odbc:' . BRIDGE_ODBC_DSN, BRIDGE_ODBC_USER, BRIDGE_ODBC_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    // تحويل الترميز: UTF-8 → Windows-1256 للعربي.
    // من غيره، الملاحظة العربية ('ويب سايت | ...') هتتكتب طلاسم في القاعدة.
    // الأرقام والإنجليزي مبيتأثروش (ASCII).
    $toWin = function (string $sql): string {
        if (!preg_match('/[\x{0600}-\x{06FF}]/u', $sql)) {
            return $sql;   // مفيش عربي — سيبه زي ما هو
        }
        $conv = @iconv('UTF-8', 'Windows-1256//IGNORE', $sql);
        return ($conv !== false && $conv !== '') ? $conv : $sql;
    };

    $t0 = microtime(true);
    $pdo->beginTransaction();

    $done = 0;
    foreach ($statements as $i => $sql) {
        try {
            $pdo->exec($toWin($sql));
            $done++;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $short = mb_substr($sql, 0, 200);
            wlog("ROLLBACK at #{$i}: " . $e->getMessage() . " | SQL: {$short}");
            echo json_encode([
                'ok'          => false,
                'error'       => "فشل الأمر رقم {$i}: " . $e->getMessage(),
                'failed_at'   => $i,
                'failed_sql'  => $short,
                'rolled_back' => true,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $pdo->commit();
    $ms = (int)round((microtime(true) - $t0) * 1000);

    wlog("COMMIT: {$done} أمر في {$ms}ms");

    echo json_encode([
        'ok'          => true,
        'executed'    => $done,
        'duration_ms' => $ms,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $ex) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    wdie('خطأ SQL Server: ' . $ex->getMessage(), 500);
}
