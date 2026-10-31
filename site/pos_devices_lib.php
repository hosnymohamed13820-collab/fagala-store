<?php
/**
 * pos_devices_lib.php — دوال تسجيل أجهزة نقطة البيع.
 */
declare(strict_types=1);

/**
 * الفروع اللي المستخدم يقدر يدير أجهزتها.
 */
function posdev_branches(PDO $pdo): array
{
    if (is_real_owner() || view_all_borrowed('pos:devices')) {
        return $pdo->query('SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    }

    $branchId = (int) (current_user()['branch_id'] ?? 0);
    if ($branchId <= 0) {
        return [];
    }
    $st = $pdo->prepare('SELECT id, name FROM branches WHERE id = ? AND is_active = 1');
    $st->execute([$branchId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * التأكد إن الفرع جوه نطاق المستخدم.
 */
function posdev_require_branch(PDO $pdo, int $branchId): void
{
    if ($branchId <= 0) {
        throw new InvalidArgumentException('اختار الفرع الأول.');
    }
    $allowed = array_map('intval', array_column(posdev_branches($pdo), 'id'));
    if (!in_array($branchId, $allowed, true)) {
        throw new RuntimeException('مالكش صلاحية على الفرع ده.');
    }
}

/**
 * تنقية والتحقق من كود الجهاز القصير.
 */
function posdev_clean_code(string $code): string
{
    $code = strtoupper(trim($code));
    if (!preg_match('/^[A-Z0-9_-]{1,12}$/', $code)) {
        throw new InvalidArgumentException('كود الجهاز من ١ لـ١٢ حرف إنجليزي أو رقم، ومسموح - و _.');
    }
    return $code;
}

/**
 * قائمة الأجهزة داخل فرع واحد.
 */
function posdev_list(PDO $pdo, int $branchId): array
{
    posdev_require_branch($pdo, $branchId);
    $st = $pdo->prepare(
        'SELECT d.id, d.branch_id, d.name, d.device_uuid, d.code, d.is_active,
                d.print_mode,
                d.last_seen_at, d.created_at, u.full_name AS created_by_name
           FROM sale_devices d
           LEFT JOIN users u ON u.id = d.created_by
          WHERE d.branch_id = ?
          ORDER BY d.is_active DESC, d.name, d.id'
    );
    $st->execute([$branchId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** قائمة أجهزة كل الفروع المسموحة باستعلام واحد. */
function posdev_list_all(PDO $pdo, array $branchIds): array
{
    $branchIds = array_values(array_unique(array_filter(array_map('intval', $branchIds))));
    if (!$branchIds) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($branchIds), '?'));
    $st = $pdo->prepare(
        "SELECT d.id, d.branch_id, b.name AS branch_name, d.name, d.device_uuid,
                d.code, d.is_active, d.print_mode, d.last_seen_at, d.created_at,
                u.full_name AS created_by_name
           FROM sale_devices d
           JOIN branches b ON b.id = d.branch_id
           LEFT JOIN users u ON u.id = d.created_by
          WHERE d.branch_id IN ($marks)
          ORDER BY b.name, d.is_active DESC, d.name, d.id"
    );
    $st->execute($branchIds);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * إضافة جهاز جديد وتوليد UUID على السيرفر.
 */
function posdev_add(PDO $pdo, int $branchId, string $name, string $code, int $uid, string $printMode = 'browser'): array
{
    posdev_require_branch($pdo, $branchId);
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 120) {
        throw new InvalidArgumentException('اسم الجهاز مطلوب وبحد أقصى ١٢٠ حرف.');
    }
    $code = posdev_clean_code($code);
    $printMode = in_array($printMode, ['browser', 'queue'], true) ? $printMode : 'browser';
    $uuid = bin2hex(random_bytes(16));

    try {
        $st = $pdo->prepare(
            'INSERT INTO sale_devices
                (branch_id, name, device_uuid, code, is_active, print_mode, created_by, created_at)
             VALUES (?, ?, ?, ?, 1, ?, ?, NOW())'
        );
        $st->execute([$branchId, $name, $uuid, $code, $printMode, $uid]);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            throw new DomainException('كود الجهاز مستخدم قبل كده في الفرع ده.');
        }
        throw $e;
    }

    return [
        'id'          => (int) $pdo->lastInsertId(),
        'device_uuid' => $uuid,
    ];
}

/**
 * تعديل بيانات جهاز موجود من غير حذف أو تغيير UUID.
 */
function posdev_update(PDO $pdo, int $deviceId, int $branchId, string $name, string $code, bool $isActive, string $printMode = 'browser'): void
{
    posdev_require_branch($pdo, $branchId);
    $name = trim($name);
    if ($deviceId <= 0 || $name === '' || mb_strlen($name) > 120) {
        throw new InvalidArgumentException('بيانات الجهاز مش كاملة.');
    }
    $code = posdev_clean_code($code);
    $printMode = in_array($printMode, ['browser', 'queue'], true) ? $printMode : 'browser';

    try {
        $st = $pdo->prepare(
            'UPDATE sale_devices
                SET name = ?, code = ?, is_active = ?, print_mode = ?
              WHERE id = ? AND branch_id = ?'
        );
        $st->execute([$name, $code, $isActive ? 1 : 0, $printMode, $deviceId, $branchId]);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            throw new DomainException('كود الجهاز مستخدم قبل كده في الفرع ده.');
        }
        throw $e;
    }

    if ($st->rowCount() === 0) {
        $chk = $pdo->prepare('SELECT id FROM sale_devices WHERE id = ? AND branch_id = ?');
        $chk->execute([$deviceId, $branchId]);
        if (!$chk->fetchColumn()) {
            throw new RuntimeException('الجهاز ده مش موجود في الفرع.');
        }
    }
}
