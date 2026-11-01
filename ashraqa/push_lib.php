<?php
/**
 * push_lib.php — نقطة الاختناق الموحدة للإشعارات وطابور إشعارات الويب (Push)
 * نظام الفجالة ستور المالي
 *
 * أي إشعار في النظام لازم يمر من notify():
 *   ١) بيكتب صف في notifications (للجرس في الموقع)
 *   ٢) بيحط صف في push_queue (لإشعارات الموبايل)
 *
 * استعلامين مجمّعين مهما كان عدد المستهدفين (مفيش استعلام جوه حلقة).
 * الدالة بتشتغل جوه الترانزاكشن المفتوحة ومابتفتحش ولا تقفل ترانزاكشن من نفسها.
 */
declare(strict_types=1);

/**
 * توكن وركر الإشعارات — مصدر واحد.
 * كان متعرّف في push_worker.php، ونقل هنا عشان push_kick_worker() تقدر
 * تستعمله من غير ما نكرر السر في مكانين.
 */
if (!defined('PUSH_WORKER_TOKEN')) {
    define('PUSH_WORKER_TOKEN', 'push_8Km4xQ9vR2Nw7Lt5Bz1Yd3Hs6Fj9Gc');
}

/**
 * إرسال إشعار لمجموعة مستخدمين (تسجيل في الجرس + طابور البوش)
 *
 * @param PDO         $pdo     اتصال الداتابيز الحالي
 * @param int[]       $userIds معرّفات المستخدمين المستهدفين
 * @param string      $type    نوع الإشعار (task, salary, shortage, ...)
 * @param string      $title   عنوان الإشعار
 * @param string|null $body    نص الإشعار (اختياري)
 * @param string|null $link    رابط الإشعار عند الضغط (اختياري)
 */
function notify(
    PDO $pdo,
    array $userIds,
    string $type,
    string $title,
    ?string $body = null,
    ?string $link = null
): void {
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), fn($x) => $x > 0)));
    if (empty($userIds)) {
        return;
    }

    $title = mb_substr(trim($title), 0, 200);
    $body  = $body !== null ? mb_substr(trim($body), 0, 255) : null;
    $link  = $link !== null ? mb_substr(trim($link), 0, 255) : null;
    $type  = mb_substr(trim($type), 0, 40);

    // ١) استعلام مجمّع واحد لجدول notifications (للجرس)
    $placeholders = [];
    $params = [];
    foreach ($userIds as $uid) {
        $placeholders[] = '(?, ?, ?, ?, ?)';
        $params[] = $uid;
        $params[] = $type;
        $params[] = $title;
        $params[] = $body;
        $params[] = $link;
    }

    $sqlNotif = 'INSERT INTO notifications (user_id, type, title, body, link) VALUES ' . implode(', ', $placeholders);
    $pdo->prepare($sqlNotif)->execute($params);

    // ٢) استعلام مجمّع واحد لطابور البوش
    push_enqueue($pdo, $userIds, $title, $body, $link);
}

/**
 * إدراج مجمّع في طابور إشعارات الويب (push_queue)
 *
 * @param PDO         $pdo     اتصال الداتابيز
 * @param int[]       $userIds معرّفات المستخدمين
 * @param string      $title   عنوان الإشعار
 * @param string|null $body    نص الإشعار
 * @param string|null $link    رابط الإشعار
 */
function push_enqueue(
    PDO $pdo,
    array $userIds,
    string $title,
    ?string $body,
    ?string $link
): void {
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), fn($x) => $x > 0)));
    if (empty($userIds)) {
        return;
    }

    $placeholders = [];
    $params = [];
    foreach ($userIds as $uid) {
        $placeholders[] = '(?, ?, ?, ?)';
        $params[] = $uid;
        $params[] = $title;
        $params[] = $body;
        $params[] = $link;
    }

    $sqlQueue = 'INSERT INTO push_queue (user_id, title, body, link) VALUES ' . implode(', ', $placeholders);
    $pdo->prepare($sqlQueue)->execute($params);

    // نبّه الوركر يشتغل أول ما الطلب يخلص بدل ما الإشعار يستنى الكرون.
    // بيتأجّل لبعد الـCOMMIT — شوف التعليق فوق push_kick_worker().
    push_kick_worker();
}

/**
 * تحويل نص إلى Base64 URL-safe بدون حشو
 */
function push_base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * فك تشفير نص Base64 URL-safe
 */
function push_base64url_decode(string $data): string
{
    $remainder = strlen($data) % 4;
    if ($remainder > 0) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    return (string) base64_decode(strtr($data, '-_', '+/'), true);
}

/**
 * تحويل توقيع ECDSA ASN.1 DER من openssl_sign إلى 64 بايت raw (r || s)
 * JWT ES256 يتطلب 32 بايت لـ r متبوعة بـ 32 بايت لـ s مع تبطين بأصفار إذا كانت أقصر
 */
function push_der_to_raw_sig(string $der): string
{
    if (strlen($der) < 8 || ord($der[0]) !== 0x30) {
        throw new InvalidArgumentException('Invalid DER signature format');
    }
    $pos = 2;
    if (ord($der[1]) & 0x80) {
        $pos = 2 + (ord($der[1]) & 0x7f);
    }
    if ($pos >= strlen($der) || ord($der[$pos]) !== 0x02) {
        throw new InvalidArgumentException('Invalid DER: expected INTEGER for R');
    }
    $pos++;
    $lenR = ord($der[$pos++]);
    $r = substr($der, $pos, $lenR);
    $pos += $lenR;

    if ($pos >= strlen($der) || ord($der[$pos]) !== 0x02) {
        throw new InvalidArgumentException('Invalid DER: expected INTEGER for S');
    }
    $pos++;
    $lenS = ord($der[$pos++]);
    $s = substr($der, $pos, $lenS);

    // إزالة بايت الصفر الإضافي إذا كان البايت الأول >= 0x80
    $r = ltrim($r, "\x00");
    $s = ltrim($s, "\x00");

    // تبطين بالأصفار من الشمال لتصبح 32 بايت بالضبط
    $r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
    $s = str_pad($s, 32, "\x00", STR_PAD_LEFT);

    if (strlen($r) > 32) {
        $r = substr($r, -32);
    }
    if (strlen($s) > 32) {
        $s = substr($s, -32);
    }

    return $r . $s;
}

/**
 * تغليف المفتاح العام P-256 الخام (65 بايت) في SubjectPublicKeyInfo DER بصيغة PEM
 */
function push_p256_pubkey_to_pem(string $raw65): string
{
    if (strlen($raw65) !== 65 || $raw65[0] !== "\x04") {
        throw new InvalidArgumentException('Invalid P-256 public key (must be 65 bytes starting with 0x04)');
    }
    // البادئة الثابتة لـ P-256 (26 بايت)
    $spkiPrefix = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
    $der = $spkiPrefix . $raw65;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/**
 * تغليف المفتاح الخاص P-256 الخام (32 بايت) في SEC1 DER بصيغة PEM
 */
function push_p256_privkey_to_pem(string $raw32): string
{
    if (strlen($raw32) !== 32) {
        throw new InvalidArgumentException('Invalid P-256 private key (must be 32 bytes)');
    }
    // SEC1 DER لـ prime256v1 (مفتاح 32 بايت)
    $der = "\x30\x31\x02\x01\x01\x04\x20" . $raw32 . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
    return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
}

/**
 * إعدادات توليد مفاتيح OpenSSL مع دعم مسار openssl.cnf على ويندوز
 */
function push_openssl_config(): array
{
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
    return $args;
}

/**
 * تشفير حمولة الإشعار وفق RFC 8291 (aes128gcm)
 *
 * @param string      $uaPublic     المفتاح العام للمتصفح (65 بايت يبدأ بـ 0x04)
 * @param string      $authSecret   الرمز السري للمتصفح (16 بايت)
 * @param array       $payload      بيانات الإشعار (title, body, link)
 * @param array|null  $ephemeralKey زوج مفاتيح مؤقت اختياري (للاختبارات)
 * @return array{body:string, asPublic:string, salt:string, prk:string, cek:string, nonce:string, cipher:string, tag:string}
 */
function push_encrypt_payload(string $uaPublic, string $authSecret, array $payload, ?array $ephemeralKey = null): array
{
    if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04") {
        throw new InvalidArgumentException('Invalid user public key (must be 65 bytes starting with 0x04)');
    }
    if (strlen($authSecret) !== 16) {
        throw new InvalidArgumentException('Invalid auth secret (must be 16 bytes)');
    }

    // ١) توليد زوج مفاتيح مؤقت (Sender / AS)
    if ($ephemeralKey !== null && isset($ephemeralKey['asPublic'], $ephemeralKey['asKeyRes'])) {
        $asPublic = $ephemeralKey['asPublic'];
        $asKeyRes = $ephemeralKey['asKeyRes'];
    } else {
        $asKeyRes = openssl_pkey_new(push_openssl_config());
        if ($asKeyRes === false) {
            throw new RuntimeException('Failed to generate ephemeral EC key: ' . (openssl_error_string() ?: 'unknown error'));
        }
        $asDetails = openssl_pkey_get_details($asKeyRes);
        if (!$asDetails || !isset($asDetails['ec']['x'], $asDetails['ec']['y'])) {
            throw new RuntimeException('Failed to extract ephemeral EC key details');
        }
        $asPublic = "\x04" . str_pad($asDetails['ec']['x'], 32, "\x00", STR_PAD_LEFT) . str_pad($asDetails['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    }

    // ٢) حساب السر المشترك عبر ECDH
    $uaPem = push_p256_pubkey_to_pem($uaPublic);
    $uaPubKeyRes = openssl_pkey_get_public($uaPem);
    if ($uaPubKeyRes === false) {
        throw new RuntimeException('Failed to load user public key into OpenSSL: ' . (openssl_error_string() ?: 'unknown error'));
    }
    $ecdh = openssl_pkey_derive($uaPubKeyRes, $asKeyRes);
    if ($ecdh === false || strlen($ecdh) !== 32) {
        throw new RuntimeException('ECDH derivation failed: ' . (openssl_error_string() ?: 'unknown error'));
    }

    // ٣) اشتقاق مفتاح PRK عبر HKDF
    $prk = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\x00" . $uaPublic . $asPublic, $authSecret);

    // ٤) توليد salt واشتقاق CEK و nonce
    $salt = random_bytes(16);
    $cek = hash_hkdf('sha256', $prk, 16, "Content-Encoding: aes128gcm\x00", $salt);
    $nonce = hash_hkdf('sha256', $prk, 12, "Content-Encoding: nonce\x00", $salt);

    // ٥) النص الصريح متبوعاً بفاصل الحشو \x02
    $plain = json_encode($payload, JSON_UNESCAPED_UNICODE) . "\x02";

    // ٦) التشفير بـ AES-128-GCM
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($cipher === false || strlen($tag) !== 16) {
        throw new RuntimeException('AES-128-GCM encryption failed: ' . (openssl_error_string() ?: 'unknown error'));
    }

    // ٧) تجميع الجسم النهائي: salt (16) || rs (4) || idlen (1) || asPublic (65) || cipher || tag (16)
    $body = $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;

    return [
        'body'     => $body,
        'asPublic' => $asPublic,
        'salt'     => $salt,
        'prk'      => $prk,
        'cek'      => $cek,
        'nonce'    => $nonce,
        'cipher'   => $cipher,
        'tag'      => $tag,
    ];
}

/**
 * إرسال إشعار بوش لجهاز واحد مع توقيع VAPID وتشفير aes128gcm.
 *
 * @param array $sub     بيانات الاشتراك (endpoint, p256dh, auth)
 * @param array $payload محتوى الإشعار (title, body, link)
 * @return array{ok:bool, code:int, error:string}
 */
function push_send_one(array $sub, array $payload): array
{
    $privateKeyB64 = defined('VAPID_PRIVATE_KEY') ? (string) VAPID_PRIVATE_KEY : '';
    if ($privateKeyB64 === '') {
        return [
            'ok'    => false,
            'code'  => 0,
            'error' => 'مفتاح VAPID الخاص مش موجود في config.php'
        ];
    }

    $endpoint = trim((string) ($sub['endpoint'] ?? ''));
    $p256dh   = trim((string) ($sub['p256dh'] ?? ''));
    $auth     = trim((string) ($sub['auth'] ?? ''));

    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        return [
            'ok'    => false,
            'code'  => 0,
            'error' => 'بيانات الاشتراك ناقصة (endpoint أو p256dh أو auth)'
        ];
    }

    try {
        $uaPublic = push_base64url_decode($p256dh);
        $authSecret = push_base64url_decode($auth);
        $privateKeyRaw = push_base64url_decode($privateKeyB64);

        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04") {
            return [
                'ok'    => false,
                'code'  => 0,
                'error' => 'مفتاح p256dh غير صالح (لازم 65 بايت ويبدأ بـ 0x04)'
            ];
        }

        if (strlen($authSecret) !== 16) {
            return [
                'ok'    => false,
                'code'  => 0,
                'error' => 'رمز auth غير صالح (لازم 16 بايت)'
            ];
        }

        if (strlen($privateKeyRaw) !== 32) {
            return [
                'ok'    => false,
                'code'  => 0,
                'error' => 'مفتاح VAPID الخاص غير صالح (لازم 32 بايت)'
            ];
        }

        // المفتاح العام وبريد التواصل من جدول settings
        $publicKey = function_exists('setting') ? trim((string) setting('vapid_public_key', '')) : '';
        $subject   = function_exists('setting') ? trim((string) setting('vapid_subject', '')) : '';
        if ($subject === '') {
            $subject = 'mailto:admin@hosnimanagement.com';
        }

        // تحميل المفتاح الخاص في OpenSSL
        $privPem = push_p256_privkey_to_pem($privateKeyRaw);
        $privKeyRes = openssl_pkey_get_private($privPem);
        if ($privKeyRes === false) {
            return [
                'ok'    => false,
                'code'  => 0,
                'error' => 'فشل تحميل مفتاح VAPID الخاص: ' . (openssl_error_string() ?: 'unknown error')
            ];
        }

        // إذا كان المفتاح العام غير مسجل في settings نشتقه من الخاص
        if ($publicKey === '') {
            $details = openssl_pkey_get_details($privKeyRes);
            if ($details && isset($details['ec']['x'], $details['ec']['y'])) {
                $rawPub = "\x04" . str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
                $publicKey = push_base64url_encode($rawPub);
            }
        }

        // ١) إنشاء وتوقيع VAPID JWT (RFC 8292)
        $parsed = parse_url($endpoint);
        $aud = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '') . (!empty($parsed['port']) ? ':' . $parsed['port'] : '');

        $jwtHeader = ['typ' => 'JWT', 'alg' => 'ES256'];
        $jwtClaims = [
            'aud' => $aud,
            'exp' => time() + 43200,
            'sub' => $subject,
        ];

        $unsignedToken = push_base64url_encode((string) json_encode($jwtHeader, JSON_UNESCAPED_SLASHES))
            . '.' . push_base64url_encode((string) json_encode($jwtClaims, JSON_UNESCAPED_SLASHES));

        $derSig = '';
        if (!openssl_sign($unsignedToken, $derSig, $privKeyRes, OPENSSL_ALGO_SHA256)) {
            return [
                'ok'    => false,
                'code'  => 0,
                'error' => 'فشل توقيع VAPID JWT: ' . (openssl_error_string() ?: 'unknown error')
            ];
        }

        $rawSig = push_der_to_raw_sig($derSig);
        $jwt = $unsignedToken . '.' . push_base64url_encode($rawSig);

        // ٢) تشفير الحمولة (RFC 8291)
        $enc = push_encrypt_payload($uaPublic, $authSecret, $payload);
        $body = $enc['body'];

        // ٣) إرسال الطلب عبر cURL POST
        $headers = [
            'TTL: 86400',
            'Content-Encoding: aes128gcm',
            'Content-Type: application/octet-stream',
            'Authorization: vapid t=' . $jwt . ', k=' . $publicKey,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $endpoint,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr !== '') {
            return [
                'ok'    => false,
                'code'  => $httpCode,
                'error' => mb_substr('خطأ في الاتصال (cURL): ' . $curlErr, 0, 255)
            ];
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'ok'    => true,
                'code'  => $httpCode,
                'error' => ''
            ];
        }

        $respMsg = is_string($response) ? trim($response) : '';
        $errText = $respMsg !== '' ? $respMsg : ('HTTP error ' . $httpCode);
        return [
            'ok'    => false,
            'code'  => $httpCode,
            'error' => mb_substr($errText, 0, 255)
        ];

    } catch (Throwable $e) {
        return [
            'ok'    => false,
            'code'  => 0,
            'error' => mb_substr('خطأ أثناء تجهيز الإشعار: ' . $e->getMessage(), 0, 255)
        ];
    }
}


/**
 * تنبيه وركر الإشعارات فوراً بعد ما الطلب يخلص.
 *
 * من غيره الإشعار بيستنى الكرون — لحد دقيقة كاملة قاعد في الطابور.
 *
 * 🔴 ليه register_shutdown_function ومش نداء مباشر؟
 * لأن notify() بتشتغل **جوّه ترانزاكشن** مفتوحة (صرف المرتبات، طلبات
 * النواقص). لو نبّهنا الوركر في نفس اللحظة، هيتصل بالقاعدة ويدوّر على
 * صفوف لسه ما اتعملهاش COMMIT ومايلاقيش حاجة — والإشعار يستنى الكرون
 * برضه. الـshutdown بيشتغل بعد ما السكربت يخلص، يعني بعد الـCOMMIT.
 * ولو الترانزاكشن اترجعت (ROLLBACK)، الصفوف مش موجودة أصلاً والوركر
 * بيلاقي الطابور فاضي — مفيش ضرر.
 *
 * والنداء fire-and-forget: مهلة قصيرة والنتيجة بتتجاهل. الكرون شبكة
 * الأمان لو الطلب ده فشل لأي سبب.
 */
if (!defined('PUSH_KICK_HOSTS')) {
    define('PUSH_KICK_HOSTS', ['hosnimanagement.com', 'www.hosnimanagement.com', 'localhost']);
}

function push_kick_worker(): void
{
    static $armed = false;
    if ($armed) return;

    // من CLI (الكرون نفسه أو أي سكربت) مفيش داعي — ومفيش HTTP_HOST أصلاً
    if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) return;
    if (!function_exists('curl_init')) return;

    // الهيدر بيتقارن بالقائمة بس؛ الرابط بياخد القيمة الموثوقة منها.
    $requestHost = strtolower(preg_replace('/:[0-9]+$/D', '', (string) $_SERVER['HTTP_HOST']));
    $hostIndex = array_search($requestHost, PUSH_KICK_HOSTS, true);
    if ($hostIndex === false) return;
    $host = PUSH_KICK_HOSTS[$hostIndex];
    $armed = true;
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
          || (($_SERVER['SERVER_PORT'] ?? '') == 443)
          || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');

    register_shutdown_function(static function () use ($host, $https) {
        // نقفل الرد للمستخدم الأول لو السيرفر بيدعم — عشان مايستناش الطلب ده
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        $url = ($https ? 'https' : 'http') . '://' . $host . '/push_worker.php';
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '',
            CURLOPT_HTTPHEADER     => ['X-Push-Token: ' . PUSH_WORKER_TOKEN],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT       => 8,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_NOSIGNAL       => true,
        ]);
        @curl_exec($ch);
        curl_close($ch);
    });
}
