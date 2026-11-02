<?php
/**
 * calls_hook.php — استقبال مكالمات الفروع من تطبيق الأندرويد
 * =====================================================================
 *  - GET ?ping=1 : اختبار الاتصال والتأكد من التوكن وتحديد الفرع
 *  - POST : استقبال دفعة مكالمات (حتى ٥٠٠ مكالمة) وتخزينها في branch_calls
 *  - الأمان : هيدر X-Calls-Token يطابق أحد توكنات calls_* في sync_tokens.php
 * =====================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/site_profile.php';
if (!site_permission_enabled('calls')) { http_response_code(404); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/sync_tokens.php';

// استخراج التوكن من الهيدر
$token = (string) ($_SERVER['HTTP_X_CALLS_TOKEN'] ?? '');
if ($token === '' && function_exists('getallheaders')) {
    $allHeaders = getallheaders();
    foreach ($allHeaders as $hKey => $hVal) {
        if (strcasecmp($hKey, 'X-Calls-Token') === 0) {
            $token = (string) $hVal;
            break;
        }
    }
}

// التحقق من التوكن قبل لمس قاعدة البيانات
$branchId = null;
if ($token !== '') {
    foreach (CALLS_TOKEN_BRANCH as $tokenKey => $bId) {
        $want = (string) (SYNC_AGENT_TOKENS[$tokenKey] ?? '');
        if ($want !== '' && hash_equals($want, $token)) {
            $branchId = (int) $bId;
            break;
        }
    }
}

if ($branchId === null) {
    http_response_code(401);
    echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/calls_lib.php';

try {
    $pdo = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // --- (١) اختبار الاتصال (Ping) ---
    if ($method === 'GET' && isset($_GET['ping'])) {
        $st = $pdo->prepare('SELECT name FROM branches WHERE id = ?');
        $st->execute([$branchId]);
        $branchName = (string) ($st->fetchColumn() ?: '');

        echo json_encode([
            'ok'     => true,
            'branch' => $branchName,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- (٢) استقبال المكالمات (POST) ---
    if ($method === 'POST') {
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > 1048576) {
            http_response_code(413);
            echo json_encode(['ok' => false, 'error' => 'حجم الطلب أكبر من ١ ميجا.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'مفيش بيانات مبعوتة.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (strlen($raw) > 1048576) {
            http_response_code(413);
            echo json_encode(['ok' => false, 'error' => 'حجم الطلب أكبر من ١ ميجا.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // إزالة أي UTF-8 BOM
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'صيغة JSON غير صحيحة.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $calls = $payload['calls'] ?? null;
        if (!is_array($calls) || count($calls) < 1 || count($calls) > 500) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'عدد المكالمات لازم يكون من ١ لـ ٥٠٠.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $totalReceived = count($calls);
        $cairoTz = new DateTimeZone('Africa/Cairo');
        $minDateMs = 1577836800000; // 2020-01-01 00:00:00 UTC
        $maxDateMs = (time() + 86400) * 1000; // now + 1 day

        $accepted = [];
        $rejected = 0;

        foreach ($calls as $c) {
            if (!is_array($c)) {
                $rejected++;
                continue;
            }

            $callId   = $c['call_id'] ?? null;
            $type     = $c['type'] ?? null;
            $dateMs   = $c['date_ms'] ?? null;
            $duration = $c['duration'] ?? null;
            $number   = $c['number'] ?? null;

            // التحقق من صحة الحقول
            if (!is_numeric($callId) || (int) $callId <= 0) {
                $rejected++;
                continue;
            }
            if (!is_numeric($type) || (int) $type < 1 || (int) $type > 20) {
                $rejected++;
                continue;
            }
            if (!is_numeric($dateMs) || (float) $dateMs < $minDateMs || (float) $dateMs > $maxDateMs) {
                $rejected++;
                continue;
            }
            if (!is_numeric($duration) || (int) $duration < 0 || (int) $duration > 86400) {
                $rejected++;
                continue;
            }
            if ($number !== null && is_string($number) && mb_strlen($number) > 32) {
                $rejected++;
                continue;
            }

            // تحويل التاريخ إلى Africa/Cairo
            $sec = (int) floor(((float) $dateMs) / 1000);
            $dt  = new DateTime('@' . $sec);
            $dt->setTimezone($cairoTz);
            $callAt = $dt->format('Y-m-d H:i:s');

            $rawNum = ($number !== null && is_string($number)) ? trim($number) : null;
            if ($rawNum === '') {
                $rawNum = null;
            }
            $normNum = calls_norm_number($rawNum);

            $accepted[] = [
                'call_id'     => (int) $callId,
                'call_type'   => (int) $type,
                'call_at'     => $callAt,
                'call_at_ms'  => (int) $dateMs,
                'duration'    => (int) $duration,
                'number_raw'  => $rawNum,
                'number_norm' => $normNum,
            ];
        }

        $inserted = 0;
        $updated  = 0;

        if (!empty($accepted)) {
            // استعلام سريع لفحص المكالمات الموجودة مسبقاً لحساب inserted و updated بدقة
            $callIds = array_column($accepted, 'call_id');
            $inQ = implode(',', array_fill(0, count($callIds), '?'));
            $chkStmt = $pdo->prepare("SELECT android_call_id, duration_sec, call_type FROM branch_calls WHERE branch_id = ? AND android_call_id IN ($inQ)");
            $chkStmt->execute(array_merge([$branchId], $callIds));

            $existing = [];
            while ($row = $chkStmt->fetch(PDO::FETCH_ASSOC)) {
                $existing[(int) $row['android_call_id']] = [
                    'duration_sec' => (int) $row['duration_sec'],
                    'call_type'    => (int) $row['call_type'],
                ];
            }

            foreach ($accepted as $row) {
                $cid = $row['call_id'];
                if (!isset($existing[$cid])) {
                    $inserted++;
                } else {
                    $old = $existing[$cid];
                    if ($old['duration_sec'] !== $row['duration'] || $old['call_type'] !== $row['call_type']) {
                        $updated++;
                    }
                }
            }

            // تنفيذ batch upsert دفعة واحدة
            $rowPlaceholders = implode(',', array_fill(0, count($accepted), '(?, ?, ?, ?, ?, ?, ?, ?)'));
            $sql = "INSERT INTO branch_calls (
                        branch_id, android_call_id, number, number_norm, call_type, call_at, call_at_ms, duration_sec
                    ) VALUES $rowPlaceholders
                    ON DUPLICATE KEY UPDATE
                        duration_sec = VALUES(duration_sec),
                        call_type    = VALUES(call_type),
                        updated_at   = CURRENT_TIMESTAMP";

            $params = [];
            foreach ($accepted as $item) {
                $params[] = $branchId;
                $params[] = $item['call_id'];
                $params[] = $item['number_raw'];
                $params[] = $item['number_norm'];
                $params[] = $item['call_type'];
                $params[] = $item['call_at'];
                $params[] = $item['call_at_ms'];
                $params[] = $item['duration'];
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }

        echo json_encode([
            'ok'       => true,
            'received' => $totalReceived,
            'inserted' => $inserted,
            'updated'  => $updated,
            'rejected' => $rejected,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'طريقة الطلب غير مدعومة.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[calls_hook] Exception: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'خطأ داخلي في الخادم.'], JSON_UNESCAPED_UNICODE);
}

