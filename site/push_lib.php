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
}

/**
 * إرسال بوش لجهاز واحد.
 * 🔴 المرحلة (ب) لسه ما اتعملتش — التشفير aes128gcm وتوقيع VAPID.
 *    لحد ما تتعمل، الوركر بيشوف كود 0 ويعتبرها فشل مؤقت ويعيد المحاولة.
 * @return array{ok:bool, code:int, error:string}
 */
function push_send_one(array $sub, array $payload): array
{
    return ['ok' => false, 'code' => 0, 'error' => 'المرحلة (ب) لسه ما اتعملتش'];
}
