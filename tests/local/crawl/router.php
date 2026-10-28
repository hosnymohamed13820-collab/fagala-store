<?php
/**
 * router.php — راوتر لسيرفر PHP المدمج أثناء زحف الاختبارات
 */
$docRoot = $_SERVER['DOCUMENT_ROOT'] ?? __DIR__;
$rawUri = $_SERVER['REQUEST_URI'] ?? '/';
$uri = parse_url($rawUri, PHP_URL_PATH);
$file = $docRoot . $uri;

// 1. لو الملف موجود كملف حقيقي، سيب السيرفر يخدمه مباشرة
if ($uri !== '/' && file_exists($file)) {
    if (is_file($file)) {
        return false;
    }
}

// 2. لو الرابط مش منتهي بـ .php وفيه ملف php بنفس الاسم
if ($uri !== '/' && file_exists($file . '.php')) {
    include_once $file . '.php';
    return true;
}

// 3. مسار البداية
if ($uri === '/' || $uri === '') {
    if (file_exists($docRoot . '/index.php')) {
        include_once $docRoot . '/index.php';
        return true;
    }
}

return false;
