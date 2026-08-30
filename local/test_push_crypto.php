<?php
/**
 * test_push_crypto.php — اختبار ذهاب وعودة لتشفير Web Push وتوقيع VAPID
 * نظام الفجالة ستور المالي
 *
 * 🔴 سكربت CLI محلي في local/ — لا يترفع على السيرفر.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    echo "السكربت ده بيتشغّل من سطر الأوامر (CLI) بس.\n";
    exit(1);
}

require_once __DIR__ . '/../site/push_lib.php';

echo "\n============================================================\n";
echo "  اختبار تشفير Web Push وتوقيع VAPID (RFC 8291 / RFC 8292)\n";
echo "============================================================\n\n";

$errors = [];

// ١) اختبار توقيع VAPID JWT وتحويل DER إلى RAW
$vapidKeyRes = openssl_pkey_new(push_openssl_config());
if ($vapidKeyRes === false) {
    echo "❌ فشل توليد مفتاح VAPID للاختبار\n";
    exit(1);
}
$jwtHeader = ['typ' => 'JWT', 'alg' => 'ES256'];
$jwtClaims = [
    'aud' => 'https://fcm.googleapis.com',
    'exp' => time() + 43200,
    'sub' => 'mailto:admin@hosnimanagement.com',
];
$unsignedToken = push_base64url_encode((string) json_encode($jwtHeader, JSON_UNESCAPED_SLASHES))
    . '.' . push_base64url_encode((string) json_encode($jwtClaims, JSON_UNESCAPED_SLASHES));

$derSig = '';
openssl_sign($unsignedToken, $derSig, $vapidKeyRes, OPENSSL_ALGO_SHA256);
$rawSig = push_der_to_raw_sig($derSig);
$jwtSigLen = strlen($rawSig);

if ($jwtSigLen !== 64) {
    $errors[] = "طول توقيع الـJWT ليس 64 بايت (الناتج: $jwtSigLen)";
}

// ٢) توليد مفاتيح متصفح وهمية (UA)
$uaKeyRes = openssl_pkey_new(push_openssl_config());
$uaDetails = openssl_pkey_get_details($uaKeyRes);
$uaPublic = "\x04" . str_pad($uaDetails['ec']['x'], 32, "\x00", STR_PAD_LEFT) . str_pad($uaDetails['ec']['y'], 32, "\x00", STR_PAD_LEFT);
$uaPrivate = str_pad($uaDetails['ec']['d'], 32, "\x00", STR_PAD_LEFT);
$authSecret = random_bytes(16);

// ٣) تشفير حمولة معروفة
$payload = [
    'title' => 'الفجالة ستور — اختبار تجريبي',
    'body'  => 'هذا إشعار تجريبي لاختبار التشفير وفك التشفير',
    'link'  => '/notifications.php'
];

$enc = push_encrypt_payload($uaPublic, $authSecret, $payload);
$body = $enc['body'];
$asPublic = $enc['asPublic'];
$salt = $enc['salt'];
$prk = $enc['prk'];
$cek = $enc['cek'];
$nonce = $enc['nonce'];
$cipher = $enc['cipher'];
$tag = $enc['tag'];

// التحقق من الأطوال
$asPubLen = strlen($asPublic);
$asPubFirstByte = ord($asPublic[0]);
$saltLen = strlen($salt);
$authLen = strlen($authSecret);
$prkLen = strlen($prk);
$cekLen = strlen($cek);
$nonceLen = strlen($nonce);
$bodyLen = strlen($body);
$expectedBodyLen = 16 + 4 + 1 + 65 + strlen($cipher) + 16;

if ($asPubLen !== 65 || $asPubFirstByte !== 0x04) {
    $errors[] = "المفتاح المؤقت ليس 65 بايت أو لا يبدأ بـ 0x04";
}
if ($saltLen !== 16) {
    $errors[] = "طول الـsalt ليس 16 بايت";
}
if ($authLen !== 16) {
    $errors[] = "طول الـauth secret ليس 16 بايت";
}
if ($prkLen !== 32) {
    $errors[] = "طول الـPRK ليس 32 بايت";
}
if ($cekLen !== 16) {
    $errors[] = "طول الـCEK ليس 16 بايت";
}
if ($nonceLen !== 12) {
    $errors[] = "طول الـnonce ليس 12 بايت";
}
if ($bodyLen !== $expectedBodyLen) {
    $errors[] = "طول الجسم ($bodyLen) لا يطابق المتوقع ($expectedBodyLen)";
}

// ٤) فك التشفير لدى طرف المتصفح الوهمي (UA)
$recSalt = substr($body, 0, 16);
$recRs = unpack('N', substr($body, 16, 4))[1];
$recIdLen = ord($body[20]);
$recAsPublic = substr($body, 21, $recIdLen);
$recTag = substr($body, -16);
$recCipher = substr($body, 21 + $recIdLen, -16);

$asPubKeyPem = push_p256_pubkey_to_pem($recAsPublic);
$asPubKeyRes = openssl_pkey_get_public($asPubKeyPem);
$ecdhDec = openssl_pkey_derive($asPubKeyRes, $uaKeyRes);

$prkDec = hash_hkdf('sha256', $ecdhDec, 32, "WebPush: info\x00" . $uaPublic . $recAsPublic, $authSecret);
$cekDec = hash_hkdf('sha256', $prkDec, 16, "Content-Encoding: aes128gcm\x00", $recSalt);
$nonceDec = hash_hkdf('sha256', $prkDec, 12, "Content-Encoding: nonce\x00", $recSalt);

$decryptedRaw = openssl_decrypt($recCipher, 'aes-128-gcm', $cekDec, OPENSSL_RAW_DATA, $nonceDec, $recTag);

$match = false;
if ($decryptedRaw !== false) {
    $decClean = rtrim($decryptedRaw, "\x00");
    if (str_ends_with($decClean, "\x02")) {
        $decClean = substr($decClean, 0, -1);
    }
    $decPayload = json_decode($decClean, true);
    if ($decPayload === $payload) {
        $match = true;
    } else {
        $errors[] = "الحمولة بعد فك التشفير لا تطابق الأصلية";
    }
} else {
    $errors[] = "فشل فك التشفير عبر openssl_decrypt: " . (openssl_error_string() ?: 'خطأ غير معروف');
}

// طباعة التقرير
printf("طول توقيع الـJWT      = %d   %s\n", $jwtSigLen, ($jwtSigLen === 64 ? "✅" : "❌"));
printf("طول المفتاح المؤقت    = %d   وأول بايت = 0x%02x %s\n", $asPubLen, $asPubFirstByte, ($asPubLen === 65 && $asPubFirstByte === 0x04 ? "✅" : "❌"));
printf("طول الـsalt           = %d   %s\n", $saltLen, ($saltLen === 16 ? "✅" : "❌"));
printf("طول الـauth secret    = %d   %s\n", $authLen, ($authLen === 16 ? "✅" : "❌"));
printf("طول الـPRK            = %d   %s\n", $prkLen, ($prkLen === 32 ? "✅" : "❌"));
printf("طول الـCEK            = %d   %s\n", $cekLen, ($cekLen === 16 ? "✅" : "❌"));
printf("طول الـnonce          = %d   %s\n", $nonceLen, ($nonceLen === 12 ? "✅" : "❌"));
printf("طول الجسم             = %d (16+4+1+65+%d+16) %s\n", $bodyLen, strlen($cipher), ($bodyLen === $expectedBodyLen ? "✅" : "❌"));
printf("فكّ التشفير = الحمولة الأصلية؟  %s\n\n", ($match ? "✅" : "❌"));

if (empty($errors)) {
    echo "============================================================\n";
    echo "✅ التشفير سليم\n";
    echo "============================================================\n";
    exit(0);
} else {
    echo "============================================================\n";
    echo "❌ فيه غلط في:\n";
    foreach ($errors as $err) {
        echo "  - $err\n";
    }
    echo "============================================================\n";
    exit(1);
}
