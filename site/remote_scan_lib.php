<?php
/**
 * remote_scan_lib.php — منطق «سكانر الكمبيوتر» (بريف ١٤٦)
 * نظام الفجالة ستور المالي
 *
 * الاستعلامات التلاتة اللي كانت مكتوبة جوه remote_scan.php اتنقلت هنا
 * زي ما هي بالحرف (نفس الأعمدة ونفس الترتيب ونفس الرسايل) — الصفحة
 * والـAPI بينادوا الدوال دي، فمفيش منطق متعاد في مكانين.
 */
declare(strict_types=1);

/** كود الاقتران: تنظيف وتوحيد الحروف — نفس اللي الصفحة بتعمله في ?s= */
function rs_session_code(mixed $raw): string
{
    return strtoupper((string) preg_replace('/[^A-Za-z0-9_-]/', '', (string) $raw));
}

/**
 * قايمة الأجهزة: أونلاين فوق وأوفلاين تحت.
 * الصفحة كانت بتنادي الاستعلام ده في ?action=devices.
 */
function rs_devices(): array
{
    return db()->query(
        'SELECT session_code, hostname,
                TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS ago
         FROM scan_agents
         ORDER BY (TIMESTAMPDIFF(SECOND, last_seen, NOW()) > 15), last_seen DESC'
    )->fetchAll();
}

/**
 * إيقاظ وكيل الكمبيوتر: علامة صلاحيتها ٣ دقايق على الجلسة.
 * الجهاز لازم يكون سجّل نفسه قبل كده (INSERT في scan_pull.php).
 */
function rs_wake(string $sessionRaw): void
{
    $s = rs_session_code($sessionRaw);
    if ($s === '' || strlen($s) < 2) throw new RuntimeException('كود الاقتران غلط.');
    db()->prepare(
        'UPDATE scan_agents SET wake_until = NOW() + INTERVAL 3 MINUTE
         WHERE session_code = ?'
    )->execute([$s]);
}

/**
 * استقبال الباركود من الموبايل وحطه في طابور الجلسة.
 * الوكيل على الكمبيوتر بيسحب من scan_queue (شوف scan_pull.php).
 */
function rs_push(string $sessionRaw, string $barcodeRaw, int $uid): void
{
    $s = rs_session_code($sessionRaw);
    $b = trim($barcodeRaw);
    if ($s === '' || strlen($s) < 2)   throw new RuntimeException('كود الاقتران غلط.');
    if ($b === '' || strlen($b) > 64)  throw new RuntimeException('باركود غير صالح.');
    db()->prepare('INSERT INTO scan_queue (session_code, barcode, created_by) VALUES (?, ?, ?)')
        ->execute([$s, $b, $uid]);
}
