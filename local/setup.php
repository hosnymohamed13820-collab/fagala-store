<?php
/**
 * setup.php — سكريبت الإعداد الأولي (شغّله مرة واحدة بس)
 * نظام الفجالة ستور المالي
 *
 * بيعمل:
 *  1) المستخدم الأونر بباسورد صحيح (هاش بـ password_hash).
 *  2) بيدّي الأونر كل الصلاحيات الـ 13 تلقائياً.
 *
 * بعد التشغيل: امسح الملف ده أو غيّر اسمه للأمان.
 */

require __DIR__ . '/config.php';

// ===== بيانات الأونر الافتراضية (غيّرها لو حبيت) =====
$username = 'owner';
$fullName = 'المالك';
$password = 'admin123';   // ⚠️ غيّره فوراً بعد أول دخول

// كل الصلاحيات الـ 13
$allModules = [
    'dashboard', 'price_inquiry', 'cashier', 'custody', 'review',
    'treasury', 'reports', 'hr', 'attendance', 'tasks', 'tasks_issue', 'suppliers',
    'invoices', 'stocktake', 'definitions', 'settings',
];

try {
    $pdo = db();

    // هل الأونر موجود بالفعل؟
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $existing = $stmt->fetchColumn();

    $hash = password_hash($password, PASSWORD_DEFAULT);

    if ($existing) {
        // تحديث الباسورد بس
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([$hash, $existing]);
        $userId = $existing;
        echo "تم تحديث باسورد الأونر الموجود.<br>";
    } else {
        // إنشاء الأونر (role_id = 1)
        $pdo->prepare(
            'INSERT INTO users (username, full_name, password_hash, role_id, is_active)
             VALUES (?, ?, ?, 1, 1)'
        )->execute([$username, $fullName, $hash]);
        $userId = (int) $pdo->lastInsertId();
        echo "تم إنشاء المستخدم الأونر.<br>";
    }

    // إعطاء الأونر كل الصلاحيات
    $ins = $pdo->prepare(
        'INSERT IGNORE INTO user_permissions (user_id, module_key) VALUES (?, ?)'
    );
    foreach ($allModules as $mod) {
        $ins->execute([$userId, $mod]);
    }
    echo "تم إعطاء الأونر كل الصلاحيات (" . count($allModules) . ").<br>";

    echo "<hr><b>تم الإعداد بنجاح ✓</b><br>";
    echo "اسم المستخدم: <code>{$username}</code><br>";
    echo "الباسورد: <code>{$password}</code><br>";
    echo "<span style='color:#b00'>⚠️ غيّر الباسورد فوراً وامسح ملف setup.php للأمان.</span>";

} catch (PDOException $e) {
    die('خطأ: ' . htmlspecialchars($e->getMessage()));
}
