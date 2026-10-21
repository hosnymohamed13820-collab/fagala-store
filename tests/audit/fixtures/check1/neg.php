<?php
$stmt = $pdo->query("SELECT * FROM table");
foreach ($stmt->fetchAll() as $row) {
    echo $row['name'];
}
