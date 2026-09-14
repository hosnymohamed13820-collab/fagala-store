<?php
/**
 * run_page.php — تشغيل صفحة من النسخة المحلية (Laragon) من سطر الأوامر
 *                بـsession جاهزة، عشان نختبر على داتا حقيقية قبل الرفع.
 *
 * 🔴 ده **CLI بس** ومكانه tests/local/ — عمره ما يتنسخ لـ D:\laragon\www
 *    ولا لـ site/. لو اتحط في www يبقى باب دخول من غير باسورد على داتا
 *    مالية حقيقية، وApache المحلي سامع على 0.0.0.0:80 — يعني أي جهاز في
 *    شبكة المكتب يوصله.
 *
 * قبل ما تشغّله: bash tests/local/sync_to_laragon.sh (عشان www يبقى فيه آخر كود)
 *
 * الاستعمال (بـphp بتاع Laragon — نفس نسخة السيرفر 8.3):
 *   /d/laragon/bin/php/php-8.3.33-Win32-vs16-x64/php.exe tests/local/run_page.php \
 *       <page.php> <owner|ashraqa> ["query=string"] ["post=body"]
 *
 * ⚠️ require_login() بيتأكد من المستخدم في القاعدة مع **كل** صفحة — يوزر
 *    وهمي مش موجود بيتعمله خروج بصمت (خرج صفر بايت ومفيش خطأ).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = getenv('LARAGON_WWW') ?: 'D:/laragon/www';
[, $page, $as, $qs, $post] = array_pad($argv, 5, '');
if ($page === '' || !is_file("$root/$page")) { fwrite(STDERR, "مفيش صفحة: $page\n"); exit(2); }

chdir($root);
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/' . $page;
$_SERVER['REQUEST_URI'] = '/' . $page . ($qs !== '' ? '?' . $qs : '');
$_SERVER['REQUEST_METHOD'] = $post !== '' ? 'POST' : 'GET';
parse_str($qs, $_GET);
if ($post !== '') parse_str($post, $_POST);

session_id('clitest' . preg_replace('/\W/', '', $as));
session_start();
if ($as === 'owner') {
    $_SESSION['user'] = ['id' => 1, 'username' => 'owner', 'full_name' => 'المالك', 'is_owner' => 1, 'branch_id' => null, 'perms_version' => 0];
    $_SESSION['permissions'] = [];
} else {
    // عاشور مش موجود في النسخة المحلية (الداتا قبل ما يتعمل).
    // require_login() بيتأكد من المستخدم في القاعدة مع كل صفحة، فلازم يوزر حقيقي:
    // ashraqa_cli_test (id 66) في القاعدة المحلية بس، بباسورد عشوائي مش محفوظ.
    $_SESSION['user'] = ['id' => 66, 'username' => 'ashraqa_cli_test', 'full_name' => 'اختبار إشراقة', 'is_owner' => 0, 'branch_id' => 5, 'perms_version' => 1];
    $_SESSION['permissions'] = ['invoices:ashraqa'];
}

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        fwrite(STDERR, "FATAL: {$e['message']} @ {$e['file']}:{$e['line']}\n");
    }
});

require "$root/$page";
