<?php
$mode = $argv[1] ?? 'status';
session_save_path('D:/laragon/tmp');
session_id('clitestowner');
session_start();
if ($mode === 'lock') {
    unset($_SESSION['sens_until']);
    echo "LOCKED\n";
} elseif ($mode === 'unlock') {
    $_SESSION['sens_until'] = time() + 600;
    echo "UNLOCKED\n";
} else {
    $until = $_SESSION['sens_until'] ?? 0;
    $left = max(0, $until - time());
    echo "sens_until=" . ($until ? "$until (left: {$left}s)" : "unset") . "\n";
}
session_write_close();
