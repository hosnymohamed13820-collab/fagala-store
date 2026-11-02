<?php
/**
 * guard_note.php — تسجيل تحليل كلود لحادثة ربط وإرسال إشعار
 * =====================================================================
 *  نظام الفجالة ستور المالي
 *  بريف ٩٤ — أكتوبر ٢٠٢٦
 * =====================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/site_profile.php';
if (!site_profile()['true_integration']) { http_response_code(404); exit; }


require_once __DIR__ . '/sync_tokens.php';
require_once __DIR__ . '/errlog_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
error_reporting(E_ALL);
ini_set('display_errors', '0');

function note_die(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    note_die('POST only.', 405);
}

$token = (string) ($_SERVER['HTTP_X_SYNC_TOKEN'] ?? '');
if (!synctok_ok('guard_watch', $token, ['guard_watch'])) {
    note_die('Unauthorized.', 401);
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 256 * 1024) {
    note_die('Bad payload size.');
}

$in = json_decode($raw, true);
if (!is_array($in)) {
    note_die('Bad JSON.');
}

$id = (int) ($in['id'] ?? $in['incident_id'] ?? 0);
if ($id <= 0) {
    note_die('Bad incident id.');
}

$analysis = trim((string) ($in['analysis'] ?? ''));
$headline = trim((string) ($in['headline'] ?? ''));
if ($analysis === '') {
    note_die('Missing analysis text.');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/push_lib.php';

try {
    $pdo = db();
    $st = $pdo->prepare(
        "UPDATE rabt_incidents
            SET status = 'analyzed', analysis = ?, analysis_at = NOW()
          WHERE id = ? AND status IN ('claimed', 'new')"
    );
    $st->execute([$analysis, $id]);

    if ($st->rowCount() > 0) {
        $ids = $pdo->query(
            'SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.is_owner = 1 AND u.is_active = 1'
        )->fetchAll(PDO::FETCH_COLUMN);
        $wid = (int) setting('agent_watcher_id', '0');
        if ($wid > 0) $ids[] = $wid;

        $body = $headline !== '' ? $headline : mb_substr($analysis, 0, 150);
        notify($pdo, $ids, 'rabt_guard', 'كلود حلّل مشكلة ربط', $body, 'rabt_guard.php#i' . $id);
    }

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $errorId = substr(bin2hex(random_bytes(8)), 0, 10);
    errlog_record('php', "رقم الخطأ $errorId: " . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
    note_die("حصل خطأ — رقم: $errorId", 500);
}
