<?php
/**
 * bridge.php — الجسر بين Hostinger و SQL Server.
 * =====================================================================
 *  يتحط على جهاز الإدارة (اللي فيه SQL Server + XAMPP).
 *  الموقع على Hostinger بيبعتله استعلام SQL → الجسر ينفّذه على SQL Server
 *  المحلي → يرجّع النتيجة JSON. كده Hostinger مش محتاج درايفر SQL Server.
 *
 *  الأمان: محمي بمفتاح سري (BRIDGE_KEY). أي طلب من غير المفتاح يترفض.
 *  وكمان: بيسمح بـ SELECT فقط (قراءة) — يرفض أي تعديل/حذف.
 * =====================================================================
 */
declare(strict_types=1);

// ===== المفتاح السري (لازم يطابق اللي في config.php على Hostinger) =====
// **غيّره لمفتاح طويل عشوائي، ونفس القيمة تتحط في config.php**
const BRIDGE_KEY = '77552213820200325';

// ===== بيانات الاتصال بـ SQL Server المحلي (نفس بيانات config المحلي) =====
const BRIDGE_ODBC_DSN  = 'True_SQL';   // اسم الـ DSN في الويندوز
const BRIDGE_ODBC_USER = 'sa';
const BRIDGE_ODBC_PASS = 'ok';

// ===== لا تعدّل تحت إلا لو تعرف =====
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// منع أي مخرجات غير الـ JSON
error_reporting(E_ALL);
ini_set('display_errors', '0');

function bridge_die(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// 1) التحقق من المفتاح السري
$key = $_POST['key'] ?? $_SERVER['HTTP_X_BRIDGE_KEY'] ?? '';
if (!hash_equals(BRIDGE_KEY, (string)$key)) {
    bridge_die('مفتاح غير صحيح.', 401);
}

// 2) قراءة الاستعلام والبارامترات
$sql    = $_POST['sql'] ?? '';
$params = $_POST['params'] ?? '[]';
if (is_string($params)) {
    $params = json_decode($params, true) ?: [];
}
if (!is_string($sql) || trim($sql) === '') {
    bridge_die('استعلام فاضي.');
}

// 3) أمان: SELECT فقط (قراءة) — نرفض أي تعديل
$check = ltrim($sql);
if (!preg_match('/^(SELECT|WITH)\s/i', $check)) {
    bridge_die('مسموح بالقراءة فقط (SELECT).', 403);
}
// منع كلمات التعديل الخطيرة (طبقة حماية إضافية)
if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|EXEC|EXECUTE|MERGE|CREATE)\b/i', $sql)) {
    bridge_die('الاستعلام يحتوي على أمر غير مسموح.', 403);
}

// 4) الاتصال بـ SQL Server وتنفيذ الاستعلام
try {
    if (!in_array('odbc', PDO::getAvailableDrivers(), true)) {
        bridge_die('pdo_odbc غير مفعّل على جهاز الإدارة.', 500);
    }
    $pdo = new PDO('odbc:' . BRIDGE_ODBC_DSN, BRIDGE_ODBC_USER, BRIDGE_ODBC_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $st = $pdo->prepare($sql);

    // تحويل الترميز في الاتجاه الرايح: الباراميترات جاية UTF-8 من الموقع،
    // بس ODBC/SQL Server (قاعدة الشركة) متوقع Windows-1256 للعربي.
    // من غير التحويل ده، البحث بكلمة عربية مبيلاقيش تطابق (بيرجّع فاضي).
    // الأرقام/الإنجليزي مبيتأثروش لأنهم ASCII.
    $paramsOut = array_map(function ($p) {
        if (is_string($p) && mb_check_encoding($p, 'UTF-8')
            && preg_match('/[\x{0600}-\x{06FF}]/u', $p)) {
            $conv = @iconv('UTF-8', 'Windows-1256//IGNORE', $p);
            if ($conv !== false && $conv !== '') return $conv;
        }
        return $p;
    }, array_values($params));

    $st->execute($paramsOut);
    $rows = $st->fetchAll();

    // تحويل الترميز: SQL Server بيرجّع عربي بترميز Windows-1256،
    // نحوّله UTF-8 عشان JSON والموقع يعرضه صح.
    $fix = function ($v) use (&$fix) {
        if (is_string($v)) {
            // لو مش UTF-8 صالح، نحوّله من Windows-1256
            if (!mb_check_encoding($v, 'UTF-8')) {
                $conv = @iconv('Windows-1256', 'UTF-8//IGNORE', $v);
                return $conv !== false ? $conv : $v;
            }
            return $v;
        }
        if (is_array($v)) {
            return array_map($fix, $v);
        }
        return $v;
    };
    $rows = array_map($fix, $rows);

    echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $ex) {
    bridge_die('خطأ SQL Server: ' . $ex->getMessage(), 500);
}
