<?php
/**
 * invoices_lib.php — مكتبة دوال فواتير الإدارة (بريف ١٦٥ وما بعده)
 * نظام الفجالة ستور المالي
 */
declare(strict_types=1);

require_once __DIR__ . '/item_lookup_lib.php';
require_once __DIR__ . '/receiving_lib.php';
require_once __DIR__ . '/group_lib.php';

/**
 * بحث الأصناف جوه الفواتير لبوب أب «تحديد أصناف» في المجموعات.
 */
function inv_pick_search(string $term, int $gid = 0): array
{
    if (!function_exists('ilk_pick_search')) {
        throw new RuntimeException('ارفع item_lookup_lib.php الجديد — النسخة اللي على السيرفر قديمة.');
    }
    if (!ilk_ready()) {
        throw new RuntimeException('المرآة لسه فاضية — شغّل وكيل المزامنة.');
    }

    $term = trim($term);
    $rows = ilk_pick_search($term);

    // الأصناف اللي جوه المجموعة بالفعل — عشان نمنع التكرار في الواجهة
    $have = [];
    if ($gid > 0) {
        foreach (group_selected_map($gid) as $k => $codes) {
            foreach (array_keys($codes) as $c) {
                $have[$k . '|' . $c] = true;
            }
        }
    }
    foreach ($rows as &$r) {
        $r['in_group'] = isset($have[$r['key']]);
    }
    unset($r);

    return $rows;
}

/**
 * أصناف فاتورة واحدة (تصفّح بالفاتورة جوه بوب أب تحديد الأصناف).
 */
function inv_pick_items(int $sth, string $suf = '', int $gid = 0): array
{
    if ($sth <= 0) {
        throw new RuntimeException('رقم الفاتورة ناقص.');
    }

    $h = sqlsrv_fetch_invoice_header($sth, $suf !== '' ? $suf : null);
    if (!$h) {
        throw new RuntimeException('الفاتورة مش موجودة في المرآة — يمكن اتلغت أو السينك متأخر.');
    }

    $realSuf = (string) ($h['suffix'] ?? $suf);
    $isRet   = ((int) ($h['doc_type'] ?? 0) === 6);
    $isUnp   = ($realSuf === 'YY_MM');

    // نفس الدمج المستخدم في شاشة الفاتورة: الكود المكرر سطر واحد
    $lines = merge_invoice_items(sqlsrv_fetch_invoice_items($sth, $realSuf ?: null));

    // الموجود في المجموعة بالفعل
    $have = $gid > 0 ? (group_selected_map($gid)[$sth . '|' . $realSuf] ?? []) : [];

    $rows = [];
    foreach ($lines as $ln) {
        $code = trim((string) ($ln['item_code'] ?? ''));
        if ($code === '') {
            continue;
        }
        $rows[] = [
            'key'        => $sth . '|' . $realSuf . '|' . $code,
            'sth_id'     => $sth,
            'suffix'     => $realSuf,
            'invoice_no' => (string) ($h['inv_no'] ?? ''),
            'type_label' => $isRet ? 'مردود' : 'فاتورة شراء',
            'is_return'  => $isRet,
            'unposted'   => $isUnp,
            'supplier'   => (string) ($h['supplier_name'] ?? ''),
            'date'       => (string) ($h['invoice_date'] ?? ''),
            'item_code'  => $code,
            'item_name'  => (string) ($ln['item_name'] ?? ''),
            'qty'        => (float) ($ln['quantity'] ?? 0),
            'in_group'   => !empty($have[$code]),
        ];
    }

    return [
        'title' => trim(($h['inv_no'] ?? '') . ' — ' . ($h['supplier_name'] ?? '')),
        'rows'  => $rows,
    ];
}

/**
 * فواتير مجموعة معيّنة (تصفّح بالمجموعة).
 */
function inv_pick_group_invoices(int $g2): array
{
    $og = $g2 > 0 ? group_get($g2) : null;
    if (!$og) {
        throw new RuntimeException('المجموعة مش موجودة.');
    }

    $rows = [];
    foreach (group_invoices($g2) as $iv) {
        $rows[] = [
            'sth_id'     => (int) $iv['sth_id'],
            'suffix'     => (string) $iv['suffix'],
            'invoice_no' => (string) ($iv['invoice_no'] ?? ''),
            'supplier'   => (string) ($iv['supplier_name'] ?? ''),
            'date'       => substr((string) ($iv['invoice_date'] ?? ''), 0, 10),
            'unposted'   => ((string) $iv['suffix'] === 'YY_MM'),
        ];
    }

    return [
        'title' => trim(($og['name'] ?: ('مجموعة #' . $g2)) . ' — ' . count($rows) . ' فاتورة'),
        'rows'  => $rows,
    ];
}

/**
 * جلب بيانات صفوف بوب أب المجموعات (unposted, posted, branch_count).
 */
function inv_group_picker_data(): array
{
    $unposted = sqlsrv_fetch_unposted_invoices();
    $posted   = sqlsrv_fetch_invoices(2026);

    $branchCount = 0;
    foreach (array_merge($unposted, $posted) as $iv) {
        if (!grp_is_admin_inv($iv)) {
            $branchCount++;
        }
    }

    return [
        'unposted'     => $unposted,
        'posted'       => $posted,
        'branch_count' => $branchCount,
    ];
}
