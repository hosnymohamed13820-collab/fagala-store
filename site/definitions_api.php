<?php
/**
 * definitions_api.php — واجهة برمجة موديول التعريفات (بريف ١٦٩ — مرحلة أ: قراءة فقط)
 * نظام الفجالة ستور المالي
 *
 *   ?part=branches                 ← {branches, stores}         (GET)
 *   ?part=employees                ← {employees, link_users}    (GET)
 *   ?part=categories               ← {categories}               (GET)
 *   ?part=suppliers[&filter=…]     ← {suppliers}                (GET)
 *
 * مرحلة (أ): القراءة (GET) فقط وبدون تاب الخزاين (treasuries).
 * عمليات الكتابة (إضافة/تعديل فرع، موظف، تصنيف، خزينة، نوع دفع المورد)
 * مؤجلة لمرحلة قادمة (الموجة ٣: الفلوس) منعاً لأي مساس بحركات الحسابات والخزائن.
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

// نفس حماية الصفحة بالحرف: require_permission('definitions')
api_require('definitions');

require_once __DIR__ . '/definitions_lib.php';
require_once __DIR__ . '/sensitive_lib.php';

$part = (string) ($_GET['part'] ?? ($_POST['part'] ?? ''));

$allowedParts = [
    'branches',
    'employees',
    'categories',
    'suppliers',
];

if (!in_array($part, $allowedParts, true)) {
    api_fail('جزء غير معروف (part)', 400, 'bad_request');
}

// في مرحلة (أ): جميع الـ parts المسموحة هي GET فقط
api_method('GET');

switch ($part) {
    case 'branches':
        api_ok([
            'branches' => def_branches_list(),
            'stores'   => def_store_list(),
        ]);

    case 'employees':
        // إخفاء أعمدة المرتب الحساسة إذا كانت الأرقام مقفولة
        $maskSens = !sens_unlocked();
        api_ok([
            'employees'  => def_employees_list($maskSens),
            'link_users' => def_link_users_list(),
        ]);

    case 'categories':
        $catData = def_categories_tree();
        api_ok([
            'categories' => $catData['acc_flat'],
        ]);

    case 'suppliers':
        $filter = trim((string) ($_GET['filter'] ?? 'all'));
        api_ok([
            'suppliers' => def_suppliers_list($filter),
        ]);
}
