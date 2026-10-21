<?php
if ($_POST) {
    check_csrf();
    require_permission('settings');
    $pdo->exec("UPDATE settings SET val = 'x'");
}
