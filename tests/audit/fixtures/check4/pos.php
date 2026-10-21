<?php
if ($_POST) {
    $pdo->exec("UPDATE settings SET val = 'x'");
}
