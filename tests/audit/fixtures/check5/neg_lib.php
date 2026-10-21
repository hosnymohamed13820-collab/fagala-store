<?php
function neg_clean_fn($pdo) {
    return $pdo->query("SELECT id, name FROM shifts")->fetchAll();
}
