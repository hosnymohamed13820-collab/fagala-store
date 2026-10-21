<?php
function pos_wildcard_fn($pdo) {
    return $pdo->query("SELECT s.* FROM shifts s")->fetchAll();
}
