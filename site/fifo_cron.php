<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/fifo_lib.php';
set_time_limit(0);
try {
    echo json_encode(fifo_rebuild(db()),JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $e) {
    error_log((string)$e);
    fwrite(STDERR,"الحساب ماكملش؛ راجع سجل الأخطاء\n");
    exit(1);
}
