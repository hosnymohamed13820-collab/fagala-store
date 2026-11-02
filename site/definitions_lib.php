<?php
/**
 * definitions_lib.php — مكتبة دوال التعريفات العامة
 * نظام الفجالة ستور المالي
 */
declare(strict_types=1);

/**
 * قائمة الفروع مرتبة بالاسم.
 */
function def_branches_list(): array
{
    return db()->query('SELECT * FROM branches ORDER BY name')->fetchAll();
}

/**
 * قائمة مخازن نظام الشركة (لربط الفرع بمخزنه).
 */
function def_store_list(): array
{
    if (!site_permission_enabled('definitions:stores')) return [];
    try {
        require_once __DIR__ . '/receiving_lib.php';
        return sqlsrv_store_names();
    } catch (Throwable $ex) {
        error_log('definitions: تعذّر جلب المخازن — ' . $ex->getMessage());
        return [];
    }
}

/**
 * قائمة الموظفين مع اسم الفرع مرتبة بالاسم الكامل.
 * في حالة $maskSensitive = true يتم إخفاء أعمدة المرتب الحساسة:
 * (base_salary, expected_monthly_hours, salary_mode).
 */
function def_employees_list(bool $maskSensitive = false): array
{
    $rows = db()->query(
        'SELECT e.*, b.name AS branch_name FROM employees e
         LEFT JOIN branches b ON b.id = e.branch_id ORDER BY e.full_name'
    )->fetchAll();

    if ($maskSensitive) {
        foreach ($rows as &$r) {
            unset($r['base_salary'], $r['expected_monthly_hours'], $r['salary_mode']);
        }
        unset($r);
    }

    return $rows;
}

/**
 * قائمة المستخدمين النشطين المتاحين للربط بموظف (لتسجيل الحضور).
 */
function def_link_users_list(): array
{
    return db()->query('SELECT id, full_name, username FROM users WHERE is_active=1 ORDER BY full_name')->fetchAll();
}

/**
 * شجرة حسابات المصروفات (مرتبة بحسب الأبناء والعمق).
 * ترجع [categories, acc_flat, acc_auto_count].
 */
function def_categories_tree(): array
{
    $pdo = db();
    $categories = $pdo->query(
        'SELECT id, parent_id, name, type, is_custody, dynamic_src, src_table, src_id, is_active, sort_order
         FROM accounts ORDER BY sort_order, name'
    )->fetchAll();

    $accByParent = [];
    foreach ($categories as $c) {
        $accByParent[(int)($c['parent_id'] ?? 0)][] = $c;
    }
    $accFlat = [];
    $accAutoCount = [];
    $accWalk = function (int $pid, int $depth) use (&$accWalk, &$accFlat, &$accAutoCount, $accByParent) {
        foreach ($accByParent[$pid] ?? [] as $c) {
            if (!empty($c['src_table'])) {
                $accAutoCount[$pid] = ($accAutoCount[$pid] ?? 0) + 1;
                continue;
            }
            $c['depth'] = $depth;
            $accFlat[] = $c;
            $accWalk((int)$c['id'], $depth + 1);
        }
    };
    $accWalk(0, 0);

    return [
        'categories'     => $categories,
        'acc_flat'       => $accFlat,
        'acc_auto_count' => $accAutoCount,
    ];
}

/**
 * قائمة الخزائن مرتبة بنوع الخزينة والاسم.
 */
function def_treasuries_list(): array
{
    return db()->query(
        "SELECT t.*, b.name AS branch_name FROM treasuries t
         LEFT JOIN branches b ON b.id = t.branch_id
         ORDER BY FIELD(t.type,'cash','main','visa','instapay','card','branch_custody','employee_custody'), t.name"
    )->fetchAll();
}

/**
 * قائمة الموردين لتاب «الموردين» مع كود True ومفلترة حسب النوع:
 * - 'all': جميع الموردين
 * - 'cash': كاش فقط
 * - 'credit': آجل فقط
 * - 'missing': من غير نوع دفع
 */
function def_suppliers_list(string $filter = 'all'): array
{
    $suppSql = "SELECT
                    s.id,
                    s.name,
                    s.payment_type,
                    s.erp_supplier_code,
                    COALESCE(NULLIF(s.erp_supplier_code, ''), MAX(h.supplier_code), '—') AS display_code
                FROM suppliers s
                LEFT JOIN hq_invoices h ON h.supplier_id = s.erp_supplier_id
                GROUP BY s.id, s.name, s.payment_type, s.erp_supplier_code
                ORDER BY CAST(COALESCE(NULLIF(s.erp_supplier_code, ''), MAX(h.supplier_code), '—') AS UNSIGNED) ASC,
                         COALESCE(NULLIF(s.erp_supplier_code, ''), MAX(h.supplier_code), '—') ASC,
                         s.name ASC";
    if (!site_permission_enabled('definitions:true_suppliers')) {
        $suppSql = "SELECT s.id, s.name, s.payment_type, s.erp_supplier_code,
            COALESCE(NULLIF(s.erp_supplier_code, ''), '—') AS display_code
            FROM suppliers s ORDER BY CAST(s.erp_supplier_code AS UNSIGNED), s.erp_supplier_code, s.name";
    }
    $rows = db()->query($suppSql)->fetchAll();

    if ($filter === 'all' || $filter === '') {
        return $rows;
    }

    return array_values(array_filter($rows, function ($s) use ($filter) {
        $pt = (string)($s['payment_type'] ?? '');
        if ($filter === 'missing') {
            return $pt === '';
        }
        if ($filter === 'cash') {
            return $pt === 'cash';
        }
        if ($filter === 'credit') {
            return $pt === 'credit';
        }
        return true;
    }));
}
