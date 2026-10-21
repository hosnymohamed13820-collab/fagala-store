<?php
$id = $_GET['id'];
$res = $pdo->query("SELECT * FROM users WHERE id = " . $_GET['id']);
