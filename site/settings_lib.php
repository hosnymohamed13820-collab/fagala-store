<?php
/**
 * settings_lib.php — مكتبة دوال الإعدادات العامة (بريف ١٦٧)
 * نظام الفجالة ستور المالي
 */
declare(strict_types=1);

require_once __DIR__ . '/agent_lib.php';

/**
 * مصفوفة الإعدادات القابلة للتعديل من الواجهة:
 * [مفتاح => [التسمية, النوع]]
 */
function settings_schema(): array
{
    return [
        'store_name'                => ['اسم المحل', 'text'],
        'print_header'              => ['عنوان الطباعة (أعلى التقارير)', 'text'],
        'print_footer'              => ['تذييل الطباعة', 'text'],
        'custody_deficit_tolerance' => ['عتبة التسامح لعجز العهدة (بالجنيه)', 'number'],
        'cashier_deficit_carry'     => ['رحّل باقي عجز الكاشير لسلفة مستديمة تلقائياً', 'bool'],
        'supplier_site_docs'        => ['احسب فواتير شراء الموقع غير الموجودة في True بكشوف الموردين', 'bool'],
        'business_day_start'        => ['بداية اليوم المحاسبي', 'time'],
        'currency'                  => ['العملة', 'text'],
        'shortage_supervisor_id'    => ['مسؤول متابعة طلبات النواقص (بيوصله إشعار مع الأونر)', 'user'],
        'purchase_supervisor_id'    => ['مسؤول المشتريات (بيوصله طلب المشتريات مع الأونر)', 'user'],
        'purchase_supervisor_2_id'  => ['مسؤول المشتريات التاني (بيوصله نفس الطلب)', 'user'],
        'agent_watcher_id'          => ['مسؤول متابعة وكلاء المزامنة (بيوصله تنبيه مع الأونر)', 'user'],
        'agent_warn_min'            => ['تنبيه أصفر لو وكيل سكت (بالدقايق)', 'number'],
        'agent_alert_min'           => ['تنبيه أحمر لو وكيل سكت (بالدقايق)', 'number'],
        'agent_repl_alert_min'      => ['إشعار لو ربط سيستم الفروع واقف (بالدقايق — أقل حاجة ١٥)', 'number'],
        'agent_watch_from'          => ['مراقبة الوكلاء تبدأ الساعة', 'time'],
        'agent_watch_to'            => ['مراقبة الوكلاء تنتهي الساعة', 'time'],
        'sales_revenue_account_id'  => ['حساب «إيراد مبيعات» اللي الورديات بتترحّل عليه (رقم الحساب)', 'number'],
        'transfer_account_id'       => ['حساب «تحويل» اللي حركات تحويل الخزائن بتترحّل عليه (رقم الحساب)', 'number'],
        'attendance_edit_user_id'   => ['موظف مسموحله يعدّل ساعات طلبات التصحيح (مع الأونر)', 'user'],
        'shift_edit_accountant_id'  => ['المحاسب المسموحله يغيّر تاريخ وفرع الوردية المستنية (مع الأونر)', 'user'],
        'shift_seq_start_date'      => ['فحص تسلسل أرقام الورديات يبدأ من يوم (فاضي = مقفول)', 'date'],
        'vapid_public_key'          => ['المفتاح العام لإشعارات الموبايل (VAPID)', 'text'],
        'vapid_subject'             => ['بريد التواصل لإشعارات الموبايل (mailto:...)', 'text'],
    ];
}

/**
 * جلب جميع قيم الإعدادات القابلة للتعديل.
 * ملاحظة أمان: لا يُرجع أي مفاتيح خاصة أو كلمات مرور.
 */
function settings_get_values(PDO $pdo): array
{
    $schema = settings_schema();
    $keys = array_keys($schema);
    $in = implode(',', array_fill(0, count($keys), '?'));
    $st = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($in)");
    $st->execute($keys);
    $rows = $st->fetchAll(PDO::FETCH_KEY_PAIR);

    $out = [];
    foreach ($schema as $k => $meta) {
        $out[$k] = $rows[$k] ?? '';
    }
    return $out;
}

/**
 * قائمة المستخدمين النشطين لحقول الإعدادات من نوع user.
 */
function settings_users(PDO $pdo): array
{
    return $pdo->query(
        'SELECT u.id, u.full_name, b.name AS branch_name
         FROM users u LEFT JOIN branches b ON b.id = u.branch_id
         WHERE u.is_active = 1 ORDER BY u.full_name'
    )->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * قائمة المستخدمين للملخص اليومي.
 */
function settings_ds_users(PDO $pdo): array
{
    return $pdo->query(
        'SELECT u.id, u.full_name, r.name_ar AS role_name
         FROM users u LEFT JOIN roles r ON r.id = u.role_id
         WHERE u.is_active = 1 ORDER BY r.is_owner DESC, u.full_name'
    )->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * جلب حالة وكلاء المزامنة والعتبات ووقت المراقبة.
 */
function settings_agents_info(): array
{
    $agents   = agent_list();
    $inWindow = agent_in_window();
    $wMin     = agent_cfg_int('agent_warn_min', 20);
    $aMin     = agent_cfg_int('agent_alert_min', 60);

    return [
        'agents'    => $agents,
        'in_window' => $inWindow,
        'warn_min'  => $wMin,
        'alert_min' => $aMin,
    ];
}

/**
 * جلب الفواتير لحارس رصيد الإدارة.
 */
function settings_guard_invoices(PDO $pdo): array
{
    try {
        $chkCol = $pdo->query("SHOW COLUMNS FROM invoice_distribution LIKE 'hq_guard_off'")->fetch();
        $hasHqGuardCol = !empty($chkCol);

        $gSql = "SELECT d.id, d.invoice_no, d.dist_date, d.status, "
              . ($hasHqGuardCol ? "d.hq_guard_off" : "0 AS hq_guard_off")
              . ", COALESCE(s.name, '—') AS supplier_name
                 FROM invoice_distribution d
                 LEFT JOIN suppliers s ON s.id = d.supplier_id
                 ORDER BY " . ($hasHqGuardCol ? "d.hq_guard_off DESC, " : "") . "d.id DESC
                 LIMIT 300";
        return $pdo->query($gSql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return [];
    }
}

/**
 * تحديث فك حارس رصيد الإدارة لفاتورة.
 */
function settings_set_invoice_guard(PDO $pdo, int $distId, int $off, int $uid): array
{
    if ($distId <= 0) {
        throw new RuntimeException('رقم الفاتورة غير صحيح.');
    }
    $off = $off ? 1 : 0;
    try {
        $st = $pdo->prepare(
            'UPDATE invoice_distribution
             SET hq_guard_off = ?, hq_guard_off_by = ?, hq_guard_off_at = NOW()
             WHERE id = ?'
        );
        $st->execute([$off, $uid, $distId]);
        return ['off' => $off, 'dist_id' => $distId];
    } catch (PDOException $e) {
        if ($e->getCode() === '42S22' || str_contains($e->getMessage(), 'Unknown column') || str_contains($e->getMessage(), 'hq_guard_off')) {
            throw new RuntimeException('شغّل local/invoice_guard_off.sql الأول');
        }
        throw new RuntimeException('خطأ في قاعدة البيانات: ' . $e->getMessage());
    }
}

/**
 * حفظ إعدادات الملخص اليومي للإدارة.
 */
function settings_save_daily_summary(PDO $pdo, string $on, int $hour, string $users): array
{
    $on = ($on === '1' || $on === 'on') ? '1' : '0';
    if ($hour !== 24) {
        $hour = max(18, min(23, $hour));
    }

    // المستلمين: أرقام بس، ويوزرز موجودين فعلاً (استعلام واحد)
    $ids = array_values(array_unique(array_filter(
        array_map('intval', explode(',', $users)),
        static fn($x) => $x > 0
    )));
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT id FROM users WHERE id IN ($in) ORDER BY id");
        $st->execute($ids);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    $upd = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $upd->execute(['daily_summary_on', $on]);
    $upd->execute(['daily_summary_hour', (string) $hour]);
    $upd->execute(['daily_summary_users', implode(',', $ids)]);

    return ['saved' => true, 'on' => $on, 'hour' => $hour, 'users' => $ids];
}
