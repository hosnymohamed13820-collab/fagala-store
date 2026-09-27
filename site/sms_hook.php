<?php
/**
 * sms_hook.php — استقبال رسايل SMS من تطبيق الأندرويد
 * =====================================================================
 *  - GET ?config=1 : قراءة كلمات التضمين والاستبعاد للتطبيق
 *  - POST : استقبال دفعة رسايل (حتى ٢٠٠ رسالة) وتخزينها في sms_inbox
 *  - الأمان : هيدر X-Sms-Token يطابق توكن sms_phone في sync_tokens.php
 * =====================================================================
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/sync_tokens.php';

// استخراج التوكن من الهيدر
$token = (string) ($_SERVER['HTTP_X_SMS_TOKEN'] ?? '');
if ($token === '' && function_exists('getallheaders')) {
    $allHeaders = getallheaders();
    foreach ($allHeaders as $hKey => $hVal) {
        if (strcasecmp($hKey, 'X-Sms-Token') === 0) {
            $token = (string) $hVal;
            break;
        }
    }
}

// التحقق من التوكن قبل لمس قاعدة البيانات
$wantToken = (string) (SYNC_AGENT_TOKENS['sms_phone'] ?? '');
if ($wantToken === '' || $token === '' || !hash_equals($wantToken, $token)) {
    http_response_code(401);
    echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/config.php';

try {
    $pdo = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // --- (١) قراءة الإعدادات ---
    if ($method === 'GET' && isset($_GET['config'])) {
        $st = $pdo->query(
            "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('sms_include_keywords', 'sms_exclude_keywords')"
        );
        $settingsMap = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $settingsMap[(string)$row['setting_key']] = (string)$row['setting_value'];
        }

        $parseWords = function (string $text): array {
            $lines = preg_split('/[\r\n]+/', $text);
            $words = [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $words[] = $line;
                }
            }
            return array_values($words);
        };

        $include = $parseWords($settingsMap['sms_include_keywords'] ?? '');
        $exclude = $parseWords($settingsMap['sms_exclude_keywords'] ?? '');

        echo json_encode([
            'ok'      => true,
            'include' => $include,
            'exclude' => $exclude,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- (٢) استقبال رسايل SMS ---
    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'مفيش بيانات مبعوتة.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // إزالة أي UTF-8 BOM في بداية البيانات إن وجد
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'صيغة JSON غير صحيحة.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $messages = $payload['messages'] ?? null;
        if (!is_array($messages) || count($messages) < 1 || count($messages) > 200) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'عدد الرسايل لازم يكون من ١ لـ ٢٠٠.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $device = isset($payload['device']) && is_string($payload['device'])
            ? mb_substr(trim($payload['device']), 0, 60)
            : null;
        if ($device === '') $device = null;

        $cairoTz   = new DateTimeZone('Africa/Cairo');
        $accepted  = [];
        $rejected  = 0;

        foreach ($messages as $m) {
            if (!is_array($m)) {
                $rejected++;
                continue;
            }

            $sender  = isset($m['sender']) && is_string($m['sender']) ? trim($m['sender']) : '';
            $body    = isset($m['body']) && is_string($m['body']) ? $m['body'] : '';
            $smsAtMs = $m['sms_at_ms'] ?? null;
            $source  = isset($m['source']) && is_string($m['source']) ? trim($m['source']) : '';
            $andId   = isset($m['android_id']) && is_numeric($m['android_id']) ? (int) $m['android_id'] : null;

            // التحقق من الحدود
            if ($sender === '' || mb_strlen($sender) > 64) {
                $rejected++;
                continue;
            }
            if (mb_strlen($body) > 2000) {
                $rejected++;
                continue;
            }
            if (!is_numeric($smsAtMs) || (float)$smsAtMs <= 0) {
                $rejected++;
                continue;
            }
            if (!in_array($source, ['live', 'backfill'], true)) {
                $rejected++;
                continue;
            }

            // تحويل التوقيت إلى Africa/Cairo
            $sec = (int) floor((float)$smsAtMs / 1000);
            $dt  = new DateTime('@' . $sec);
            $dt->setTimezone($cairoTz);
            $smsAt = $dt->format('Y-m-d H:i:s');

            // حساب الـ Hash لمنع التكرار
            $msgHash = hash('sha256', $sender . '|' . $smsAtMs . '|' . $body);

            $accepted[] = [
                'msg_hash'   => $msgHash,
                'sender'     => $sender,
                'body'       => $body,
                'sms_at'     => $smsAt,
                'android_id' => $andId,
                'source'     => $source,
                'device'     => $device,
            ];
        }

        $inserted   = 0;
        $duplicates = 0;

        if (!empty($accepted)) {
            $rowPlaceholders = implode(',', array_fill(0, count($accepted), '(?, ?, ?, ?, ?, ?, ?)'));
            $sql = "INSERT IGNORE INTO sms_inbox (`msg_hash`, `sender`, `body`, `sms_at`, `android_id`, `source`, `device`) VALUES $rowPlaceholders";

            $params = [];
            foreach ($accepted as $row) {
                $params[] = $row['msg_hash'];
                $params[] = $row['sender'];
                $params[] = $row['body'];
                $params[] = $row['sms_at'];
                $params[] = $row['android_id'];
                $params[] = $row['source'];
                $params[] = $row['device'];
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $inserted = (int) $stmt->rowCount();
            $duplicates = count($accepted) - $inserted;
        }

        echo json_encode([
            'ok'         => true,
            'inserted'   => $inserted,
            'duplicates' => $duplicates,
            'rejected'   => $rejected,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'طريقة الطلب غير مدعومة.'], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    error_log('sms_hook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'حصل خطأ في السيرفر.'], JSON_UNESCAPED_UNICODE);
    exit;
}
