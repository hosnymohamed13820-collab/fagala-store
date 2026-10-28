<?php
/**
 * db_check.php — فحص عدادات الجداول الخمسة قبل وبعد الزحف
 */
$www = getenv('LARAGON_WWW') ?: 'D:/laragon/www';
$configFile = rtrim($www, '/\\') . '/config.php';

if (!file_exists($configFile)) {
    echo json_encode(['error' => 'config.php not found']);
    exit(1);
}

require_once $configFile;

if (!function_exists('db')) {
    echo json_encode(['error' => 'db() function not defined']);
    exit(1);
}

try {
    $pdo = db();
} catch (Throwable $e) {
    echo json_encode(['error' => 'DB connection failed: ' . $e->getMessage()]);
    exit(1);
}

$tables = ['journal_entries', 'push_queue', 'sale_docs', 'notifications', 'activity_log'];
$counts = [];

foreach ($tables as $t) {
    try {
        $c = $pdo->query("SELECT COUNT(1) FROM `{$t}`")->fetchColumn();
        $counts[$t] = (int)$c;
    } catch (Throwable $e) {
        $counts[$t] = null;
    }
}

echo json_encode(['ok' => true, 'counts' => $counts]);
