<?php
/**
 * site/sms_permits_lib.php
 * إدارة أذونات رسايل إنستاباي (إنشاء، اعتماد، رفض، وجلب أحدث إذن لكل رسالة)
 * (بريف ٨٦)
 */
declare(strict_types=1);

require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/treasury_ops_lib.php';
require_once __DIR__ . '/sms_lib.php';

/**
 * إنشاء إذن جديد لرقم رسالة في sms_inbox
 *
 * @throws RuntimeException في حال عدم وجود الرسالة أو وجود إذن معلق/معتمد أو خطأ تحقق
 */
function sms_permit_create(int $smsId, string $action, array $in, int $uid): int
{
    $pdo = db();

    // ١. التأكد من وجود الرسالة في sms_inbox
    $stSms = $pdo->prepare('SELECT id, body, received_at FROM sms_inbox WHERE id = ? LIMIT 1');
    $stSms->execute([$smsId]);
    $sms = $stSms->fetch(PDO::FETCH_ASSOC);
    if (!$sms) {
        throw new RuntimeException('الرسالة مش موجودة.');
    }

    // ٢. فحص الأذونات السابقة على نفس الرسالة
    $stCheck = $pdo->prepare(
        "SELECT id, status FROM sms_permits WHERE sms_id = ? AND status IN ('pending', 'approving', 'approved') LIMIT 1"
    );
    $stCheck->execute([$smsId]);
    $existing = $stCheck->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        if ($existing['status'] === 'approved') {
            throw new RuntimeException("الرسالة دي اتسجّلت خلاص (إذن #{$existing['id']}).");
        }
        throw new RuntimeException('الرسالة دي عليها إذن مستني المراجعة.');
    }

    // ٣. التحقق من صحة الخانات بنفس منطق الخزينة قبل الحفظ
    treasury_validate_op($action, $in);

    // ٤. تجهيز وسم الملاحظات الذي يربط القيد بالرسالة
    $parsed = sms_parse((string) $sms['body'], (string) $sms['received_at']);
    $ref = $parsed['ref'] ?? null;
    $refTag = ($ref !== null && $ref !== '')
        ? "[إنستا · رسالة #{$smsId} · مرجع {$ref}]"
        : "[إنستا · رسالة #{$smsId}]";

    $origNotes = trim((string) ($in['notes'] ?? ''));
    $cleanNotes = ($origNotes !== '') ? ($origNotes . "\n" . $refTag) : $refTag;

    $amount = treasury_clean_amount($in['amount'] ?? 0.0);
    $payload = [
        'treasury_id'    => (int) ($in['treasury_id'] ?? 0),
        'op_type'        => (string) ($in['op_type'] ?? 'normal'),
        'entry_code_id'  => ($in['entry_code_id'] ?? '') !== '' ? (int) $in['entry_code_id'] : null,
        'cost_branch_id' => ($in['cost_branch_id'] ?? '') !== '' ? (int) $in['cost_branch_id'] : null,
        'supplier_id'    => ($in['supplier_id'] ?? '') !== '' ? (int) $in['supplier_id'] : null,
        'employee_id'    => ($in['employee_id'] ?? '') !== '' ? (int) $in['employee_id'] : null,
        'branch_id'      => ($in['branch_id'] ?? '') !== '' ? (int) $in['branch_id'] : null,
        'amount'         => $amount,
        'date'           => fin_valid_date((string) ($in['date'] ?? date('Y-m-d'))),
        'description'    => trim((string) ($in['description'] ?? '')),
        'notes'          => $cleanNotes,
    ];

    $stmt = $pdo->prepare(
        "INSERT INTO sms_permits (sms_id, action, payload, amount, status, created_by, created_at)
         VALUES (?, ?, ?, ?, 'pending', ?, NOW())"
    );
    $stmt->execute([
        $smsId,
        $action,
        json_encode($payload, JSON_UNESCAPED_UNICODE),
        $amount,
        $uid,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * اعتماد الإذن من قِبل المحاسب وتطبيق العملية على الخزينة
 *
 * @throws RuntimeException في حال تم مراجعته مسبقاً أو فشل التطبيق
 */
function sms_permit_approve(int $permitId, int $uid): string
{
    $pdo = db();

    // ١. قفل الإذن لمنع التكرار (حماية ضد الضغط المتعدد والـ race conditions)
    $stmt = $pdo->prepare(
        "UPDATE sms_permits SET status='approving', reviewed_by=?, reviewed_at=NOW()
         WHERE id=? AND status='pending'"
    );
    $stmt->execute([$uid, $permitId]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('الإذن ده اتراجع خلاص.');
    }

    // ٢. جلب بيانات الإذن والتأكد من عدم وجود إذن معتمد آخر لنفس الرسالة
    $stFetch = $pdo->prepare('SELECT * FROM sms_permits WHERE id = ?');
    $stFetch->execute([$permitId]);
    $p = $stFetch->fetch(PDO::FETCH_ASSOC);
    if (!$p) {
        throw new RuntimeException('الإذن مش موجود.');
    }

    $stDup = $pdo->prepare(
        "SELECT id FROM sms_permits WHERE sms_id = ? AND status = 'approved' AND id != ? LIMIT 1"
    );
    $stDup->execute([(int) $p['sms_id'], $permitId]);
    $alreadyApprovedId = $stDup->fetchColumn();
    if ($alreadyApprovedId) {
        $pdo->prepare("UPDATE sms_permits SET status='pending', reviewed_by=NULL, reviewed_at=NULL WHERE id=?")
            ->execute([$permitId]);
        throw new RuntimeException("الرسالة دي اتسجّلت خلاص (إذن #{$alreadyApprovedId}).");
    }

    // ٣. تطبيق العملية في الخزينة
    $payload = json_decode((string) $p['payload'], true) ?: [];
    $movementId = null;

    try {
        $flashMsg = treasury_apply_op((string) $p['action'], $payload, $uid, $movementId, true);
    } catch (Throwable $e) {
        // الفلوس ماتحركتش ← الإذن يرجع pending والمحاسب يقدر يعيد
        $pdo->prepare("UPDATE sms_permits SET status='pending', reviewed_by=NULL, reviewed_at=NULL WHERE id=?")
            ->execute([$permitId]);
        throw $e;
    }

    // ٤. تحديث الحالة إلى approved — 🔴 برّه الـtry عن قصد: الفلوس اتحركت خلاص،
    //    فلو الـUPDATE ده وقع الإذن لازم يفضل «approving» (بيظهر بتحذير ومن غير زرار)
    //    مش يرجع pending ويتعتمد تاني فالخزينة تتخصم مرتين.
    $stOk = $pdo->prepare("UPDATE sms_permits SET status='approved', movement_id=? WHERE id=?");
    $stOk->execute([$movementId, $permitId]);

    return $flashMsg;
}

/**
 * رفض الإذن مع تسجيل سبب الرفض
 */
function sms_permit_reject(int $permitId, int $uid, string $note): void
{
    $note = trim($note);
    if ($note === '') {
        throw new RuntimeException('سبب الرفض إجباري.');
    }

    $pdo = db();
    $stmt = $pdo->prepare(
        "UPDATE sms_permits SET status='rejected', reviewed_by=?, reviewed_at=NOW(), review_note=?
         WHERE id=? AND status='pending'"
    );
    $stmt->execute([$uid, $note, $permitId]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('الإذن ده اتراجع خلاص.');
    }
}

/**
 * جلب أحدث إذن لكل رسالة من مصفوفة معرّفات الرسايل (استعلام واحد مجمّع)
 *
 * @param array $smsIds مصفوفة أرقام الرسايل
 * @return array [sms_id => row]
 */
function sms_permits_for(array $smsIds): array
{
    $ids = array_values(array_filter(array_map('intval', $smsIds), fn($id) => $id > 0));
    if (empty($ids)) {
        return [];
    }

    $pdo = db();
    $inClause = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT p.*
            FROM sms_permits p
            INNER JOIN (
                SELECT MAX(id) AS max_id
                FROM sms_permits
                WHERE sms_id IN ($inClause)
                GROUP BY sms_id
            ) m ON p.id = m.max_id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($rows as $r) {
        $map[(int) $r['sms_id']] = $r;
    }

    return $map;
}
