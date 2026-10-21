<?php
foreach ($items as $item) {
    $pdo->query("SELECT * FROM table WHERE id=" . $item['id']);
}
