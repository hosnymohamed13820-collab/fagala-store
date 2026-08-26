<?php
/**
 * stocktake_export.php — تصدير تقارير الجرد (TEXT)
 * نظام الفجالة ستور المالي
 *
 * ?sheet=ID   → تقرير محضر واحد (كود <TAB> الكمية النهائية)
 * ?session=ID → تقرير مجمّع لكل المحاضر المنتهية في الجلسة
 */
require_once __DIR__ . '/auth.php';
require_permission('stocktake');
require_once __DIR__ . '/stocktake_lib.php';

$pdo = db();

if (isset($_GET['sheet'])) {
    $sheetId = (int) $_GET['sheet'];
    // المحضر لازم يكون منتهي
    $st = $pdo->prepare('SELECT sheet_no, status FROM stocktake_sheets WHERE id=?');
    $st->execute([$sheetId]);
    $sh = $st->fetch();
    if (!$sh) { http_response_code(404); die('المحضر غير موجود.'); }
    if ($sh['status'] !== 'done') { http_response_code(400); die('المحضر لم يُنهَ بعد.'); }
    $content = stk_report_sheet($sheetId);
    $filename = 'stocktake_sheet_' . $sh['sheet_no'] . '.txt';
} elseif (isset($_GET['session'])) {
    $sessionId = (int) $_GET['session'];
    $content = stk_report_session($sessionId);
    $filename = 'stocktake_session_' . $sessionId . '_all.txt';
} else {
    http_response_code(400);
    die('حدد محضر أو جلسة.');
}

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($content));
echo $content;
exit;
