<?php
/**
 * shortage_lib.php — منطق موديول طلب النواقص + مرفقات المهام
 * نظام الفجالة ستور المالي
 *
 * فرع ناقصه أصناف بيطلبها من فرع تاني:
 *   - الطلب بيتسجّل في shortage_requests + lines
 *   - بيتعمل مهمة (tasks) مستهدفها موظفين الفرع المطلوب منه
 *   - إشعارات مختلفة: للفرع المنفّذ / للأونر / لمسؤول المتابعة
 *   - الفرع المنفّذ بيجهّز ويرفع إثبات الفاتورة كمرفق على المهمة
 *
 * مسؤول المتابعة بيتحدد من الإعدادات (shortage_supervisor_id).
 */
require_once __DIR__ . '/auth.php';

/** مسؤول متابعة النواقص من الإعدادات (0 = مش متحدد) */
function shortage_supervisor_id(): int
{
    return (int) setting('shortage_supervisor_id', 0);
}

/** مسؤول المشتريات من الإعدادات (0 = مش متحدد) */
function purchase_supervisor_id(): int
{
    return (int) setting('purchase_supervisor_id', 0);
}

/** كل الأونرز النشطين @return int[] */
function shortage_owner_ids(): array
{
    return array_map('intval', db()->query(
        'SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
         WHERE u.is_active = 1 AND r.is_owner = 1'
    )->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * كل مستخدمي فرع معيّن النشطين — من users.branch_id ومن employees
 * مع بعض (اتحاد) عشان محدش يفلت لو مسجّل في جدول واحد بس.
 * @return int[]
 */
function shortage_branch_user_ids(int $branchId): array
{
    if ($branchId <= 0) return [];
    $st = db()->prepare(
        'SELECT DISTINCT u.id
         FROM users u
         LEFT JOIN employees e ON e.user_id = u.id AND e.is_active = 1
         WHERE u.is_active = 1
           AND (u.branch_id = ? OR e.branch_id = ?)'
    );
    $st->execute([$branchId, $branchId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * إنشاء طلب نواقص كامل: السجل + الأسطر + المهمة + الإشعارات.
 *
 * @param array $lines كل عنصر: ['code'=>..,'name'=>..,'qty'=>..]
 * @return array{request_id:int, task_id:int, targets:int}
 */
function create_shortage_request(
    int $fromBranchId,
    int $toBranchId,
    array $lines,
    ?string $notes,
    int $userId,
    string $source = 'branch'
): array {
    // ===== تحقق =====
    if ($source !== 'branch' && $source !== 'purchase') {
        throw new InvalidArgumentException('مصدر الطلب غير صالح.');
    }
    if ($fromBranchId <= 0) throw new RuntimeException('اختار الفرع الطالب.');
    if ($source === 'branch') {
        if ($toBranchId <= 0) throw new RuntimeException('اختار الفرع المطلوب منه.');
        if ($fromBranchId === $toBranchId) {
            throw new RuntimeException('الفرع الطالب والمطلوب منه مينفعش يكونوا نفس الفرع.');
        }
    } else {
        $toBranchId = 0;
    }

    $clean = [];
    foreach ($lines as $l) {
        if (!is_array($l)) continue;
        $code = trim((string) ($l['code'] ?? ''));
        $qty  = (float) ($l['qty'] ?? 0);
        if ($code === '' || $qty <= 0) continue;
        $clean[] = [
            'code' => mb_substr($code, 0, 64),
            'name' => mb_substr(trim((string) ($l['name'] ?? '')), 0, 255),
            'qty'  => $qty,
        ];
    }
    if (empty($clean)) {
        throw new RuntimeException('اكتب الكمية المطلوبة لصنف واحد على الأقل.');
    }

    $notes = trim((string) $notes);
    if ($notes === '') $notes = null;
    if ($notes !== null) $notes = mb_substr($notes, 0, 1000);

    $pdo = db();

    // أسماء الفروع والمستهدفين
    if ($source === 'branch') {
        $bst = $pdo->prepare('SELECT id, name FROM branches WHERE id IN (?, ?)');
        $bst->execute([$fromBranchId, $toBranchId]);
        $bmap = [];
        foreach ($bst->fetchAll() as $b) $bmap[(int) $b['id']] = (string) $b['name'];
        if (!isset($bmap[$fromBranchId], $bmap[$toBranchId])) {
            throw new RuntimeException('فرع غير موجود.');
        }
        $fromName = $bmap[$fromBranchId];
        $toName   = $bmap[$toBranchId];

        // المستهدفين = موظفين الفرع المطلوب منه (من غير الطالب نفسه لو صدف)
        $targets = array_values(array_diff(shortage_branch_user_ids($toBranchId), [$userId]));
        if (empty($targets)) {
            throw new RuntimeException('مفيش مستخدمين نشطين متسجّلين على فرع ' . $toName . ' — ظبّط المستخدمين الأول.');
        }
    } else {
        $bst = $pdo->prepare('SELECT id, name FROM branches WHERE id = ?');
        $bst->execute([$fromBranchId]);
        $b = $bst->fetch();
        if (!$b) {
            throw new RuntimeException('فرع غير موجود.');
        }
        $fromName = (string) $b['name'];
        $toName   = '';

        // المستهدفين = مسؤول المشتريات + كل الأونرز (من غير الطالب نفسه)
        $targets = shortage_owner_ids();
        $psup = purchase_supervisor_id();
        if ($psup > 0) $targets[] = $psup;
        $targets = array_values(array_diff(array_unique($targets), [$userId]));
        if (empty($targets)) {
            throw new RuntimeException('مفيش مسؤول مشتريات متحدد ولا أونر نشط — ظبّط «مسؤول المشتريات» من الإعدادات الأول.');
        }
    }

    $n = count($clean);

    // ترجمة الأكواد لـ item_id باستعلام واحد من السيرفر
    require_once __DIR__ . '/stock_query_lib.php';
    $idMap = stock_codes_to_ids(array_column($clean, 'code'));

    // ===== الكتابة (معاملة واحدة) =====
    $pdo->beginTransaction();
    try {
        // ١) الطلب
        $pdo->prepare(
            'INSERT INTO shortage_requests (source, from_branch_id, to_branch_id, requested_by, notes)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $source,
            $fromBranchId,
            ($source === 'purchase' ? null : $toBranchId),
            $userId,
            $notes
        ]);
        $requestId = (int) $pdo->lastInsertId();

        // ٢) الأسطر
        $insLine = $pdo->prepare(
            'INSERT INTO shortage_request_lines (request_id, item_id, code, item_name, qty) VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($clean as $l) {
            $itemId = (int) ($idMap[$l['code']] ?? 0);
            $insLine->execute([$requestId, $itemId, $l['code'], $l['name'], $l['qty']]);
        }

        // ٣) المهمة — المنشئ هو الموظف الطالب عشان يتابعها في "اللي أصدرتها"
        if ($source === 'purchase') {
            $title = 'طلب مشتريات من فرع ' . $fromName . ' — ' . $n . ' صنف';
            $details = 'فرع ' . $fromName . ' طالب ' . $n . ' صنف من المشتريات.'
                     . "\nالأصناف والكميات موجودة جوه المهمة."
                     . ($notes !== null ? "\n\nملاحظات الطالب: " . $notes : '');
        } else {
            $title = 'طلب نواقص لفرع ' . $fromName . ' — ' . $n . ' صنف';
            $details = 'فرع ' . $fromName . ' طالب ' . $n . ' صنف من فرع ' . $toName . '.'
                     . "\nالأصناف والكميات موجودة جوه المهمة."
                     . "\nبعد تجهيز الطلب وعمل الفاتورة: ارفعوا صورة/ملف الفاتورة كمرفق هنا وعلّموا المهمة تمت."
                     . ($notes !== null ? "\n\nملاحظات الطالب: " . $notes : '');
        }

        $pdo->prepare(
            'INSERT INTO tasks (title, details, priority, due_date, created_by, status, ref_type, ref_id)
             VALUES (?, ?, "important", NULL, ?, "open", "shortage", ?)'
        )->execute([$title, $details, $userId, $requestId]);
        $taskId = (int) $pdo->lastInsertId();

        $pdo->prepare('UPDATE shortage_requests SET task_id = ? WHERE id = ?')
            ->execute([$taskId, $requestId]);

        // ٤) المستهدفين + إشعارهم
        $insTarget = $pdo->prepare('INSERT IGNORE INTO task_targets (task_id, user_id) VALUES (?, ?)');
        $insNotif  = $pdo->prepare(
            'INSERT INTO notifications (user_id, type, title, body, link) VALUES (?, "shortage", ?, ?, ?)'
        );
        $link = 'tasks.php?task=' . $taskId;

        if ($source === 'purchase') {
            $notifTitle = '🛒 طلب مشتريات من فرع ' . $fromName;
            $notifBody  = mb_substr('مطلوب توفير ' . $n . ' صنف من المشتريات', 0, 255);
        } else {
            $notifTitle = '🛒 طلب نواقص من فرع ' . $fromName;
            $notifBody  = mb_substr('مطلوب تجهيز ' . $n . ' صنف — جهّزوا الطلب وارفعوا إثبات الفاتورة على المهمة', 0, 255);
        }

        foreach ($targets as $tid) {
            $insTarget->execute([$taskId, $tid]);
            $insNotif->execute([
                $tid,
                $notifTitle,
                $notifBody,
                $link,
            ]);
        }

        // ٥) إشعار المتابعة (لطلب الفرع فقط) — الأونر + المسؤول، من غير تكرار
        //    ومن غير الطالب نفسه (هو عارف إنه طلب) ولا المستهدفين
        if ($source === 'branch') {
            $watchers = shortage_owner_ids();
            $sup = shortage_supervisor_id();
            if ($sup > 0) $watchers[] = $sup;
            $watchers = array_values(array_diff(array_unique($watchers), $targets, [$userId]));
            foreach ($watchers as $wid) {
                $insNotif->execute([
                    $wid,
                    '📋 متابعة نواقص',
                    mb_substr('فرع ' . $fromName . ' طلب ' . $n . ' صنف من فرع ' . $toName, 0, 255),
                    $link,
                ]);
            }
        }

        $pdo->commit();
        return ['request_id' => $requestId, 'task_id' => $taskId, 'targets' => count($targets)];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * بيانات طلب النواقص المرتبط بمهمة (للعرض جوه المهمة).
 * @return array{request:array, lines:array}|null
 */
function shortage_by_task(int $taskId): ?array
{
    if ($taskId <= 0) return null;
    try {
        $st = db()->prepare(
            'SELECT r.*, bf.name AS from_name, bt.name AS to_name, u.full_name AS requester
             FROM shortage_requests r
             JOIN branches bf ON bf.id = r.from_branch_id
             LEFT JOIN branches bt ON bt.id = r.to_branch_id
             LEFT JOIN users u ON u.id = r.requested_by
             WHERE r.task_id = ? LIMIT 1'
        );
        $st->execute([$taskId]);
        $req = $st->fetch();
        if (!$req) return null;

        $ls = db()->prepare(
            'SELECT code, item_name, qty FROM shortage_request_lines WHERE request_id = ? ORDER BY id'
        );
        $ls->execute([(int) $req['id']]);
        return ['request' => $req, 'lines' => $ls->fetchAll()];
    } catch (Throwable $ex) {
        return null;
    }
}

/**
 * عدد مهام النواقص المفتوحة المستنياني (للزنّ في الهيدر).
 */
function shortage_pending_count(int $userId): int
{
    try {
        $st = db()->prepare(
            "SELECT COUNT(*) FROM task_targets tt
             JOIN tasks t ON t.id = tt.task_id
             WHERE tt.user_id = ? AND tt.status = 'pending'
               AND t.status = 'open' AND t.ref_type = 'shortage'"
        );
        $st->execute([$userId]);
        return (int) $st->fetchColumn();
    } catch (Throwable $ex) {
        return 0;   // الجداول لسه مش موجودة
    }
}

// =====================================================================
//  مرفقات المهام (عامة لكل المهام)
// =====================================================================

/** هل المستخدم مصرّح يشوف المهمة دي؟ (مستهدف/منشئ/أونر/مسؤول النواقص/مسؤول المشتريات) */
function can_view_task(int $taskId, int $userId): bool
{
    if ($taskId <= 0 || $userId <= 0) return false;
    if (is_owner()) return true;

    $st = db()->prepare('SELECT created_by, ref_type FROM tasks WHERE id = ? LIMIT 1');
    $st->execute([$taskId]);
    $t = $st->fetch();
    if (!$t) return false;
    if ((int) $t['created_by'] === $userId) return true;
    if (($t['ref_type'] ?? '') === 'shortage' && (
        $userId === shortage_supervisor_id() || $userId === purchase_supervisor_id()
    )) return true;

    $chk = db()->prepare('SELECT 1 FROM task_targets WHERE task_id = ? AND user_id = ? LIMIT 1');
    $chk->execute([$taskId, $userId]);
    return (bool) $chk->fetchColumn();
}

/** فولدر تخزين مرفقات المهام (بيتعمل تلقائي) */
function task_upload_dir(): string
{
    $dir = __DIR__ . '/uploads/tasks';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

/**
 * رفع مرفق على مهمة. صور + PDF بس، حد أقصى ٥ ميجا.
 * @param array $file عنصر من $_FILES
 */
function add_task_attachment(int $taskId, int $userId, array $file): void
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('فشل رفع الملف — جرّب تاني.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        throw new RuntimeException('حجم الملف لازم يكون أقل من ٥ ميجا.');
    }

    // نوع الملف الفعلي (مش الامتداد اللي المتصفح باعته)
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = (string) finfo_file($fi, $file['tmp_name']);
        finfo_close($fi);
    } else {
        $mime = (string) ($file['type'] ?? '');
    }
    $allowed = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('مسموح صور (JPG/PNG/WebP) أو PDF بس.');
    }

    $orig   = mb_substr(trim((string) ($file['name'] ?? 'ملف')), 0, 255);
    $stored = 't' . $taskId . '_' . bin2hex(random_bytes(10)) . '.' . $allowed[$mime];
    $dest   = task_upload_dir() . '/' . $stored;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('فشل حفظ الملف على السيرفر.');
    }

    try {
        db()->prepare(
            'INSERT INTO task_attachments (task_id, user_id, orig_name, stored_name, mime, size_bytes)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$taskId, $userId, $orig, $stored, $mime, $size]);
    } catch (Throwable $ex) {
        @unlink($dest);   // مانسيبش ملف يتيم
        throw $ex;
    }
}

/** مرفقات مهمة @return array */
function task_attachments(int $taskId): array
{
    try {
        $st = db()->prepare(
            'SELECT a.*, u.full_name FROM task_attachments a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE a.task_id = ? ORDER BY a.id'
        );
        $st->execute([$taskId]);
        return $st->fetchAll();
    } catch (Throwable $ex) {
        return [];
    }
}
