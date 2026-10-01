<?php
/**
 * guard_feed.php — جلب الحوادث الجديدة لطابور كلود على جهاز محمد
 * =====================================================================
 *  نظام الفجالة ستور المالي
 *  بريف ٩٤ — أكتوبر ٢٠٢٦
 * =====================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/sync_tokens.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
error_reporting(E_ALL);
ini_set('display_errors', '0');

function feed_die(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    feed_die('GET only.', 405);
}

$token = (string) ($_SERVER['HTTP_X_SYNC_TOKEN'] ?? '');
if (!synctok_ok('guard_watch', $token, ['guard_watch'])) {
    feed_die('Unauthorized.', 401);
}

require_once __DIR__ . '/config.php';

try {
    $pdo = db();
    $st = $pdo->query(
        'SELECT * FROM rabt_incidents
          WHERE status = \'new\'
             OR (status = \'claimed\' AND claimed_at < NOW() - INTERVAL 2 HOUR)
          ORDER BY id ASC
          LIMIT 20'
    );
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($rows)) {
        $ids = array_column($rows, 'id');
        $inClause = implode(',', array_fill(0, count($ids), '?'));
        $upd = $pdo->prepare(
            "UPDATE rabt_incidents
                SET status = 'claimed', claimed_at = NOW()
              WHERE id IN ($inClause)"
        );
        $upd->execute($ids);
    }

    echo json_encode(['ok' => true, 'incidents' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    feed_die('Server error: ' . $e->getMessage(), 500);
}
