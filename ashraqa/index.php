<?php
/**
 * index.php — نقطة الدخول للنظام.
 * بيوجّه المستخدم للداشبورد لو مسجّل دخول، أو لصفحة الدخول لو لأ.
 */
require_once __DIR__ . '/auth.php';

if (function_exists('is_logged_in') && is_logged_in()) {
    // أول مودويل متاح للمستخدم — مش كل اليوزرز عندهم داشبورد
    header('Location: ' . first_allowed_module());
    exit;
}
header('Location: login.php');
exit;
