<?php
/**
 * gen_vapid.php — مولّد مفاتيح VAPID لإشعارات الويب (P-256 / prime256v1)
 * نظام الفجالة ستور المالي
 *
 * 🔴 السكربت ده بيتشغّل مرة واحدة على جهاز محمد (CLI محلياً).
 *    مكانه في local/ ومايترفعش على السيرفر أبداً.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    echo "السكربت ده بيتشغّل من سطر الأوامر (CLI) بس.\n";
    exit(1);
}

function base64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

// مسار إعدادات OpenSSL على ويندوز إذا لزم
$args = [
    'curve_name'       => 'prime256v1',
    'private_key_type' => OPENSSL_KEYTYPE_EC,
];
$cnf = getenv('OPENSSL_CONF');
if (!$cnf || !file_exists($cnf)) {
    $candidates = [
        dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf',
        dirname(PHP_BINARY) . '/openssl.cnf',
        'C:/xampp/apache/bin/openssl.cnf',
        'C:/laragon/bin/apache/httpd-2.4.54-win64-VS16/conf/openssl.cnf',
    ];
    foreach ($candidates as $cand) {
        if (file_exists($cand)) {
            $args['config'] = $cand;
            break;
        }
    }
}

$keyRes = openssl_pkey_new($args);
if ($keyRes === false) {
    echo "❌ فشل توليد المفاتيح عبر OpenSSL: " . (openssl_error_string() ?: 'خطأ غير معروف') . "\n";
    exit(1);
}

$details = openssl_pkey_get_details($keyRes);
if (!$details || !isset($details['ec']['x'], $details['ec']['y'], $details['ec']['d'])) {
    echo "❌ فشل استخراج تفاصيل مفاتيح EC.\n";
    exit(1);
}

$x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
$y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
$d = str_pad($details['ec']['d'], 32, "\x00", STR_PAD_LEFT);

// المفتاح العام: النقطة غير المضغوطة ٦٥ بايت (أولها 0x04)
$pubRaw = "\x04" . $x . $y;
$pubBase64Url = base64url($pubRaw);

// المفتاح الخاص: الرقم السري ٣٢ بايت
$privRaw = $d;
$privBase64Url = base64url($privRaw);

echo "\n============================================================\n";
echo "  مولّد مفاتيح VAPID — نظام الفجالة ستور (P-256)\n";
echo "============================================================\n\n";

echo "المفتاح العام (Public Key - 65 بايت - base64url):\n";
echo "  " . $pubBase64Url . "\n\n";

echo "المفتاح الخاص (Private Key - 32 بايت - base64url):\n";
echo "  " . $privBase64Url . "\n\n";

echo "------------------------------------------------------------\n";
echo "تعليمات الاستخدام:\n";
echo "------------------------------------------------------------\n";
echo "١) المفتاح العام: انسخه وحطه في شاشة «الإعدادات» في الموقع\n";
echo "   (حقل: المفتاح العام لإشعارات الموبايل VAPID).\n\n";
echo "٢) المفتاح الخاص: حطه في ملف config.php على السيرفر كالتالي:\n";
echo "   define('VAPID_PRIVATE_KEY', '" . $privBase64Url . "');\n\n";
echo "🔴 تنبيه مهم: المفتاح الخاص سري جداً — ما يتحطش في أي ملف في الريبو\n";
echo "   ولا يترفع على جيت هب. مكانه config.php على السيرفر بس.\n";
echo "============================================================\n\n";
