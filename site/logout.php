<?php
/**
 * logout.php — تسجيل الخروج
 */
require_once __DIR__ . '/auth.php';
logout();
header('Location: login.php');
exit;
