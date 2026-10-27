<?php
/** pos_reports_lib.php — استعلامات تقارير حارس العجز لنقطة البيع. */
declare(strict_types=1);

require_once __DIR__ . '/sale_lib.php';
require_once __DIR__ . '/xlsx_lib.php';

/** تعريفات التابات والأعمدة من مصدر واحد للصفحة والـAPI والإكسيل. */
function pos_reports_definitions(): array
{
    return [
        'removed' => ['title' => 'أصناف ملغاة', 'columns' => [
            'event_time' => ['label' => 'الوقت'], 'branch_name' => ['label' => 'الفرع'],
            'device_name' => ['label' => 'الجهاز'], 'cashier_name' => ['label' => 'الكاشير'],
            'event_type' => ['label' => 'الحالة'], 'code' => ['label' => 'الكود'],
            'item_name' => ['label' => 'الصنف'], 'qty' => ['label' => 'الكمية', 'num' => true],
            'value' => ['label' => 'القيمة', 'num' => true],
            'cashier_count' => ['label' => 'عدد الكاشير', 'num' => true],
            'cashier_value' => ['label' => 'قيمة الكاشير', 'num' => true],
        ]],
        'drawer' => ['title' => 'فتح الدرج من غير بيع', 'columns' => [
            'event_time' => ['label' => 'الوقت'], 'branch_name' => ['label' => 'الفرع'],
            'device_name' => ['label' => 'الجهاز'], 'cashier_name' => ['label' => 'الموظف'],
            'reason' => ['label' => 'السبب'], 'employee_count' => ['label' => 'عدد مرات الموظف', 'num' => true],
        ]],
        'discounts' => ['title' => 'الخصومات', 'columns' => [
            'branch_name' => ['label' => 'الفرع'], 'cashier_name' => ['label' => 'الكاشير'],
            'supervisor_name' => ['label' => 'المشرف'], 'invoice_count' => ['label' => 'عدد الفواتير', 'num' => true],
            'discount_value' => ['label' => 'قيمة الخصم', 'num' => true],
            'discount_pct' => ['label' => 'نسبة الخصم', 'num' => true],
            'above_cap_count' => ['label' => 'فوق السقف', 'num' => true],
        ]],
        'returns' => ['title' => 'المرتجعات', 'columns' => [
            'completed_at' => ['label' => 'الوقت'], 'branch_name' => ['label' => 'الفرع'],
            'receipt_no' => ['label' => 'الإيصال'], 'cashier_name' => ['label' => 'الفاعل'],
            'return_type' => ['label' => 'النوع'], 'net' => ['label' => 'القيمة', 'num' => true],
            'is_late' => ['label' => 'بعد المدة'], 'method_override' => ['label' => 'طريقة مختلفة'],
            'approved_by' => ['label' => 'المعتمد'], 'reason' => ['label' => 'السبب'],
        ]],
        'held_cancelled' => ['title' => 'المعلّق الملغي', 'columns' => [
            'event_time' => ['label' => 'الوقت'], 'branch_name' => ['label' => 'الفرع'],
            'device_name' => ['label' => 'الجهاز'], 'cashier_name' => ['label' => 'الفاعل'],
            'doc_id' => ['label' => 'رقم المستند', 'num' => true], 'reason' => ['label' => 'السبب'],
        ]],
        'close_variances' => ['title' => 'فروق التقفيل', 'columns' => [
            'business_date' => ['label' => 'اليوم'], 'branch_name' => ['label' => 'الفرع'],
            'device_name' => ['label' => 'الجهاز'], 'cashier_name' => ['label' => 'الكاشير'],
            'status' => ['label' => 'الحالة'],
            'expected_cash' => ['label' => 'كاش متوقع', 'num' => true],
            'counted_cash' => ['label' => 'كاش معدود', 'num' => true],
            'cash_diff' => ['label' => 'فرق كاش', 'num' => true],
            'expected_visa' => ['label' => 'فيزا متوقعة', 'num' => true],
            'counted_visa' => ['label' => 'فيزا معدودة', 'num' => true],
            'visa_diff' => ['label' => 'فرق فيزا', 'num' => true],
            'expected_instapay' => ['label' => 'إنستا متوقع', 'num' => true],
            'counted_instapay' => ['label' => 'إنستا معدود', 'num' => true],
            'instapay_diff' => ['label' => 'فرق إنستا', 'num' => true],
            'total_diff' => ['label' => 'إجمالي الفرق', 'num' => true],
        ]],
    ];
}

/** فلتر موحد ومقفول على نطاق فروع المستخدم. */
function pos_reports_filters(PDO $pdo, array $input, array $user): array
{
    $from = (string) ($input['from'] ?? date('Y-m-01'));
    $to = (string) ($input['to'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) {
        throw new SaleApiException('فترة التقرير مش صحيحة.');
    }
    $allowed = sale_allowed_branch_ids($pdo, $user);
    $branchId = max(0, (int) ($input['branch_id'] ?? 0));
    if ($branchId > 0 && !in_array($branchId, $allowed, true)) {
        throw new SaleApiException('مالكش صلاحية على الفرع ده.', 403, 'forbidden');
    }
    return [
        'from' => $from,
        'to' => $to,
        'branch_id' => $branchId,
        'cashier_id' => max(0, (int) ($input['cashier_id'] ?? 0)),
        'allowed_branches' => $allowed,
    ];
}

/** شروط الفرع والكاشير المشتركة، من غير تجميع SQL من إدخال المستخدم. */
function pos_reports_scope_sql(array $filter, string $branchColumn, string $cashierColumn, array &$params): string
{
    $parts = [];
    if ($filter['branch_id'] > 0) {
        $parts[] = "$branchColumn = ?";
        $params[] = $filter['branch_id'];
    } else {
        $ids = array_values(array_map('intval', $filter['allowed_branches']));
        if (!$ids) {
            $parts[] = '1 = 0';
        } else {
            $parts[] = "$branchColumn IN (" . implode(',', array_fill(0, count($ids), '?')) . ')';
            array_push($params, ...$ids);
        }
    }
    if ($filter['cashier_id'] > 0) {
        $parts[] = "$cashierColumn = ?";
        $params[] = $filter['cashier_id'];
    }
    return $parts ? ' AND ' . implode(' AND ', $parts) : '';
}

/** تشغيل استعلام واحد مجمّع للتقرير المطلوب. */
function pos_reports_run(PDO $pdo, string $report, array $filter): array
{
    $defs = pos_reports_definitions();
    if (!isset($defs[$report])) {
        throw new SaleApiException('نوع التقرير مش معروف.');
    }
    $params = [$filter['from'], $filter['to']];

    if ($report === 'removed') {
        $scope = pos_reports_scope_sql($filter, 'r.branch_id', 'r.user_id', $params);
        $sql = "SELECT r.event_time, b.name branch_name, d.name device_name,
                       u.full_name cashier_name, r.event_type, r.code,
                       COALESCE(NULLIF(r.payload_name, ''),
                           (SELECT hc.name FROM hq_codes hc WHERE hc.item_id = r.item_id ORDER BY hc.code_type, hc.code LIMIT 1),
                           (SELECT im.name1 FROM item_master im WHERE im.id = -r.item_id LIMIT 1), '') item_name,
                       r.qty, ROUND(r.qty * r.price, 2) value,
                       COUNT(*) OVER (PARTITION BY r.user_id) cashier_count,
                       ROUND(SUM(r.qty * r.price) OVER (PARTITION BY r.user_id), 2) cashier_value
                  FROM (
                    SELECT e.id, e.created_at event_time, e.branch_id, e.device_id, e.user_id,
                           e.event_type,
                           CAST(JSON_UNQUOTE(JSON_EXTRACT(e.payload, '$.item_id')) AS SIGNED) item_id,
                           COALESCE(JSON_UNQUOTE(JSON_EXTRACT(e.payload, '$.code')), '') code,
                           COALESCE(JSON_UNQUOTE(JSON_EXTRACT(e.payload, '$.name')), '') payload_name,
                           CAST(JSON_UNQUOTE(JSON_EXTRACT(e.payload, '$.qty')) AS DECIMAL(14,3)) qty,
                           CAST(JSON_UNQUOTE(JSON_EXTRACT(e.payload, '$.price')) AS DECIMAL(14,2)) price
                      FROM sale_events e
                     WHERE e.event_type = 'line_removed'
                    UNION ALL
                    SELECT e.id, e.created_at, e.branch_id, e.device_id, e.user_id,
                           e.event_type, jt.item_id, jt.code, jt.item_name, jt.qty, jt.price
                      FROM sale_events e
                      JOIN JSON_TABLE(e.payload, '$.lines[*]' COLUMNS (
                           item_id BIGINT PATH '$.item_id', code VARCHAR(64) PATH '$.code',
                           item_name VARCHAR(255) PATH '$.name', qty DECIMAL(14,3) PATH '$.qty',
                           price DECIMAL(14,2) PATH '$.price'
                      )) jt ON TRUE
                     WHERE e.event_type = 'cart_cleared'
                  ) r
                  JOIN branches b ON b.id = r.branch_id
                  JOIN sale_devices d ON d.id = r.device_id
                  JOIN users u ON u.id = r.user_id
                 WHERE r.event_time >= ? AND r.event_time < DATE_ADD(?, INTERVAL 1 DAY)$scope
                 ORDER BY r.event_time DESC, r.id DESC";
    } elseif ($report === 'drawer') {
        $scope = pos_reports_scope_sql($filter, 'e.branch_id', 'e.user_id', $params);
        $sql = "SELECT e.created_at event_time, b.name branch_name, d.name device_name,
                       u.full_name cashier_name,
                       COALESCE(JSON_UNQUOTE(JSON_EXTRACT(e.payload, '$.reason')), '') reason,
                       COUNT(*) OVER (PARTITION BY e.user_id) employee_count
                  FROM sale_events e
                  JOIN branches b ON b.id = e.branch_id
                  JOIN sale_devices d ON d.id = e.device_id
                  JOIN users u ON u.id = e.user_id
                 WHERE e.event_type = 'drawer_open'
                   AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(e.payload, '$.reason')), '') NOT IN ('cash_sale','cash_return')
                   AND e.created_at >= ? AND e.created_at < DATE_ADD(?, INTERVAL 1 DAY)$scope
                 ORDER BY e.created_at DESC, e.id DESC";
    } elseif ($report === 'discounts') {
        $scope = pos_reports_scope_sql($filter, 'sd.branch_id', 'sd.cashier_id', $params);
        $sql = "SELECT b.name branch_name, cu.full_name cashier_name,
                       COALESCE(su.full_name, 'من غير اعتماد') supervisor_name,
                       COUNT(*) invoice_count,
                       ROUND(SUM(sd.line_discount + sd.invoice_discount), 2) discount_value,
                       ROUND(100 * SUM(sd.line_discount + sd.invoice_discount) / NULLIF(SUM(sd.gross), 0), 2) discount_pct,
                       SUM(CASE WHEN tok.doc_id IS NOT NULL THEN 1 ELSE 0 END) above_cap_count
                  FROM sale_docs sd
                  JOIN branches b ON b.id = sd.branch_id
                  JOIN users cu ON cu.id = sd.cashier_id
                  LEFT JOIN (
                       SELECT used_doc_id doc_id, MAX(approved_by) approved_by
                         FROM sale_approval_tokens
                        WHERE approval_type = 'discount' AND used_doc_id IS NOT NULL
                        GROUP BY used_doc_id
                  ) tok ON tok.doc_id = sd.id
                  LEFT JOIN users su ON su.id = COALESCE(sd.discount_approved_by, tok.approved_by)
                 WHERE sd.doc_kind = 'sale' AND sd.status = 'completed'
                   AND sd.line_discount + sd.invoice_discount > 0
                   AND sd.completed_at >= ? AND sd.completed_at < DATE_ADD(?, INTERVAL 1 DAY)$scope
                 GROUP BY sd.branch_id, b.name, sd.cashier_id, cu.full_name,
                          COALESCE(sd.discount_approved_by, tok.approved_by), su.full_name
                 ORDER BY b.name, cu.full_name, su.full_name";
    } elseif ($report === 'returns') {
        $scope = pos_reports_scope_sql($filter, 'rd.branch_id', 'rd.cashier_id', $params);
        $sql = "SELECT rd.completed_at, b.name branch_name, rd.receipt_no, u.full_name cashier_name,
                       CASE WHEN rd.ref_doc_id IS NULL THEN 'مرتجع عام' ELSE 'مرتجع على فاتورة' END return_type,
                       rd.net,
                       CASE WHEN tok.has_late = 1 THEN 'نعم' ELSE 'لا' END is_late,
                       CASE WHEN tok.has_method = 1 THEN 'نعم' ELSE 'لا' END method_override,
                       COALESCE(au.full_name, '') approved_by,
                       COALESCE(ev.reason, '') reason
                  FROM sale_docs rd
                  JOIN branches b ON b.id = rd.branch_id
                  JOIN users u ON u.id = rd.cashier_id
                  LEFT JOIN (
                       SELECT used_doc_id doc_id,
                              MAX(approval_type = 'return_late') has_late,
                              MAX(approval_type = 'return_method') has_method,
                              MAX(approved_by) approved_by
                         FROM sale_approval_tokens
                        WHERE used_doc_id IS NOT NULL AND approval_type IN ('return_late','return_method')
                        GROUP BY used_doc_id
                  ) tok ON tok.doc_id = rd.id
                  LEFT JOIN users au ON au.id = tok.approved_by
                  LEFT JOIN (
                       SELECT doc_id,
                              MAX(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.reason')),
                                           JSON_UNQUOTE(JSON_EXTRACT(payload, '$.method_override_reason')), '')) reason
                         FROM sale_events
                        WHERE event_type IN ('return_general','return_method_override')
                        GROUP BY doc_id
                  ) ev ON ev.doc_id = rd.id
                 WHERE rd.doc_kind = 'return' AND rd.status = 'completed'
                   AND rd.completed_at >= ? AND rd.completed_at < DATE_ADD(?, INTERVAL 1 DAY)$scope
                 ORDER BY rd.completed_at DESC, rd.id DESC";
    } elseif ($report === 'held_cancelled') {
        $scope = pos_reports_scope_sql($filter, 'e.branch_id', 'e.user_id', $params);
        $sql = "SELECT e.created_at event_time, b.name branch_name, d.name device_name,
                       u.full_name cashier_name, e.doc_id,
                       COALESCE(JSON_UNQUOTE(JSON_EXTRACT(e.payload, '$.reason')), '') reason
                  FROM sale_events e
                  JOIN branches b ON b.id = e.branch_id
                  JOIN sale_devices d ON d.id = e.device_id
                  JOIN users u ON u.id = e.user_id
                 WHERE e.event_type = 'held_cancelled'
                   AND e.created_at >= ? AND e.created_at < DATE_ADD(?, INTERVAL 1 DAY)$scope
                 ORDER BY e.created_at DESC, e.id DESC";
    } else {
        $scope = pos_reports_scope_sql($filter, 's.branch_id', 's.user_id', $params);
        $sql = "SELECT s.business_date, b.name branch_name, d.name device_name, u.full_name cashier_name,
                       s.status,
                       ROUND(ss.opening_cash + COALESCE(x.sale_cash,0) - COALESCE(x.sale_change,0) - COALESCE(x.return_cash,0),2) expected_cash,
                       s.cash_total counted_cash,
                       ROUND(s.cash_total - (ss.opening_cash + COALESCE(x.sale_cash,0) - COALESCE(x.sale_change,0) - COALESCE(x.return_cash,0)),2) cash_diff,
                       ROUND(COALESCE(x.sale_visa,0) - COALESCE(x.return_visa,0),2) expected_visa,
                       s.visa_total counted_visa,
                       ROUND(s.visa_total - (COALESCE(x.sale_visa,0) - COALESCE(x.return_visa,0)),2) visa_diff,
                       ROUND(COALESCE(x.sale_instapay,0) - COALESCE(x.return_instapay,0),2) expected_instapay,
                       s.instapay_total counted_instapay,
                       ROUND(s.instapay_total - (COALESCE(x.sale_instapay,0) - COALESCE(x.return_instapay,0)),2) instapay_diff,
                       s.difference total_diff
                  FROM shifts s
                  JOIN sale_sessions ss ON ss.id = s.session_id
                  JOIN sale_devices d ON d.id = ss.device_id
                  JOIN branches b ON b.id = s.branch_id
                  JOIN users u ON u.id = s.user_id
                  LEFT JOIN (
                       SELECT docs.session_id,
                              SUM(CASE WHEN docs.doc_kind='sale' THEN pay.cash_amount ELSE 0 END) sale_cash,
                              SUM(CASE WHEN docs.doc_kind='sale' THEN docs.change_due ELSE 0 END) sale_change,
                              SUM(CASE WHEN docs.doc_kind='return' THEN pay.cash_amount ELSE 0 END) return_cash,
                              SUM(CASE WHEN docs.doc_kind='sale' THEN pay.visa_amount ELSE 0 END) sale_visa,
                              SUM(CASE WHEN docs.doc_kind='return' THEN pay.visa_amount ELSE 0 END) return_visa,
                              SUM(CASE WHEN docs.doc_kind='sale' THEN pay.instapay_amount ELSE 0 END) sale_instapay,
                              SUM(CASE WHEN docs.doc_kind='return' THEN pay.instapay_amount ELSE 0 END) return_instapay
                         FROM sale_docs docs
                         LEFT JOIN (
                              SELECT doc_id,
                                     SUM(CASE WHEN method='cash' THEN amount ELSE 0 END) cash_amount,
                                     SUM(CASE WHEN method='visa' THEN amount ELSE 0 END) visa_amount,
                                     SUM(CASE WHEN method='instapay' THEN amount ELSE 0 END) instapay_amount
                                FROM sale_payments GROUP BY doc_id
                         ) pay ON pay.doc_id = docs.id
                        WHERE docs.status='completed'
                        GROUP BY docs.session_id
                  ) x ON x.session_id = ss.id
                 WHERE s.source='pos' AND s.business_date >= ? AND s.business_date <= ?$scope
                 ORDER BY s.business_date DESC, s.id DESC";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return ['definition' => $defs[$report], 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
}

/** تنزيل نفس نتيجة التقرير كإكسيل. */
function pos_reports_export(string $report, array $result, array $filter): never
{
    $columns = $result['definition']['columns'];
    $xlsx = new SimpleXlsx($result['definition']['title']);
    $xlsx->setColumnTypes(array_map(static fn(array $col): string => !empty($col['num']) ? 'n' : 's', $columns));
    $xlsx->addRow(array_column($columns, 'label'), true);
    foreach ($result['rows'] as $row) {
        $cells = [];
        foreach ($columns as $key => $column) {
            $cells[] = !empty($column['num']) ? (float) ($row[$key] ?? 0) : (string) ($row[$key] ?? '');
        }
        $xlsx->addRow($cells);
    }
    $xlsx->download('pos_' . $report . '_' . $filter['from'] . '_' . $filter['to'] . '.xlsx');
    exit;
}
