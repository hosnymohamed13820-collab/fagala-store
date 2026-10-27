<?php
/**
 * customers_lib.php — مكتبة موديول العملاء (بيانات + كشف حساب + رقم العميل مربوط بكل حاجة)
 * نظام الفجالة ستور المالي
 *
 * مفيش DELETE نهائياً — الإيقاف بـ is_active فقط.
 * كشف الحساب استعلامان مجمّعان فقط (المستندات + الدفعات).
 */
declare(strict_types=1);

/**
 * تقسيم نص البحث لكلمات (مطابق لنمط im_words).
 *
 * @return string[]
 */
function cust_words(string $q): array
{
    $q = trim((string) preg_replace('/\s+/u', ' ', $q));
    if ($q === '') {
        return [];
    }
    return array_values(array_slice(preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 8));
}

/**
 * تنظيف رقم الموبايل وتوحيده لأرقام إنجليزية فقط مع تحويل +20/0020 إلى 0.
 */
function cust_clean_phone(?string $phone): ?string
{
    if ($phone === null) {
        return null;
    }
    $arabic = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $western = ['0','1','2','3','4','5','6','7','8','9'];
    $phone = str_replace($arabic, $western, $phone);
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if ($digits === '') {
        return null;
    }
    if (str_starts_with($digits, '0020')) {
        $digits = '0' . substr($digits, 4);
    } elseif (str_starts_with($digits, '20') && strlen($digits) === 12) {
        $digits = '0' . substr($digits, 2);
    } elseif (strlen($digits) === 10 && in_array(substr($digits, 0, 2), ['10', '11', '12', '15'], true)) {
        $digits = '0' . $digits;
    }
    return $digits;
}

/**
 * الفروع المتاحة للمستخدم الحالي بحسب الصلاحية ونطاق الفرع.
 *
 * @return array<int, array{id:int, name:string}>
 */
function cust_branches(PDO $pdo): array
{
    if (is_real_owner() || view_all_borrowed('customers:view') || view_all_borrowed('customers')) {
        return $pdo->query('SELECT id, name FROM branches WHERE is_active = 1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
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
 * التحقق من صلاحية المستخدم على فرع معيّن.
 */
function cust_require_branch(PDO $pdo, int $branchId): void
{
    if ($branchId <= 0) {
        throw new InvalidArgumentException('اختار الفرع الأول.');
    }
    $allowed = array_map('intval', array_column(cust_branches($pdo), 'id'));
    if (!in_array($branchId, $allowed, true)) {
        throw new RuntimeException('مالكش صلاحية على الفرع ده.');
    }
}

/**
 * توليد كود العميل التالي مسلسل تلقائياً.
 */
function cust_next_code(PDO $pdo): string
{
    $max = (int) $pdo->query('SELECT COALESCE(MAX(CAST(code AS UNSIGNED)), 0) FROM customers')->fetchColumn();
    return (string) ($max + 1);
}

/**
 * البحث في العملاء (بالاسم بكل كلمة بأي ترتيب أو بالموبايل أو بالكود، صفحات ٥٠).
 *
 * @param array<string, mixed> $filters
 * @return array{rows: array<int, array<string, mixed>>, total: int, page: int, pages: int}
 */
function cust_search(PDO $pdo, array $filters, int $page = 1, int $perPage = 50): array
{
    $page = max(1, $page);
    $perPage = max(1, min(100, $perPage));

    $where = [];
    $params = [];

    // فلتر الفرع
    if (is_real_owner() || view_all_borrowed('customers:view') || view_all_borrowed('customers')) {
        $filterBranch = (int) ($filters['branch_id'] ?? 0);
        if ($filterBranch > 0) {
            $where[] = 'c.branch_id = ?';
            $params[] = $filterBranch;
        }
    } else {
        $userBranch = (int) (current_user()['branch_id'] ?? 0);
        $where[] = 'c.branch_id = ?';
        $params[] = $userBranch;
    }

    // فلتر النوع
    $kind = (string) ($filters['kind'] ?? '');
    if (in_array($kind, ['regular', 'walkin'], true)) {
        $where[] = 'c.kind = ?';
        $params[] = $kind;
    }

    // فلتر الحالة
    if (isset($filters['is_active']) && $filters['is_active'] !== '') {
        $where[] = 'c.is_active = ?';
        $params[] = !empty($filters['is_active']) ? 1 : 0;
    }

    // فلتر البحث النصي (اسم / موبايل / كود)
    $q = trim((string) ($filters['q'] ?? $filters['term'] ?? ''));
    if ($q !== '') {
        $cleanPhone = cust_clean_phone($q);
        $words = cust_words($q);

        $searchClauses = [];
        // تطابق الكود
        $searchClauses[] = 'c.code = ?';
        $params[] = $q;

        // تطابق الموبايل
        if ($cleanPhone !== null) {
            $searchClauses[] = '(c.phone IS NOT NULL AND c.phone LIKE ?)';
            $params[] = '%' . $cleanPhone . '%';
            $searchClauses[] = '(c.phone2 IS NOT NULL AND c.phone2 LIKE ?)';
            $params[] = '%' . $cleanPhone . '%';
        }

        // تطابق كل كلمة في الاسم بأي ترتيب
        if ($words !== []) {
            $nameWordClauses = [];
            foreach ($words as $w) {
                $nameWordClauses[] = 'c.name LIKE ?';
                $params[] = '%' . $w . '%';
            }
            $searchClauses[] = '(' . implode(' AND ', $nameWordClauses) . ')';
        }

        $where[] = '(' . implode(' OR ', $searchClauses) . ')';
    }

    $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

    // إجمالي النتائج
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM customers c $whereSql");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    $sql = "SELECT c.id, c.code, c.name, c.phone, c.phone2, c.kind, c.branch_id,
                   b.name AS branch_name, c.notes, c.is_active, c.created_at, c.updated_at
              FROM customers c
              LEFT JOIN branches b ON b.id = c.branch_id
             $whereSql
             ORDER BY c.id DESC
             LIMIT $perPage OFFSET $offset";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'rows'  => $rows,
        'total' => $total,
        'page'  => $page,
        'pages' => $pages,
    ];
}

/**
 * جلب بيانات عميل واحد بالمعرّف مع مراعاة صلاحية الفرع.
 *
 * @return array<string, mixed>|null
 */
function cust_get(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT c.id, c.code, c.name, c.phone, c.phone2, c.kind, c.branch_id,
                b.name AS branch_name, c.notes, c.is_active, c.created_by,
                c.created_at, c.updated_at
           FROM customers c
           LEFT JOIN branches b ON b.id = c.branch_id
          WHERE c.id = ?'
    );
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    if (!is_real_owner() && !view_all_borrowed('customers:view') && !view_all_borrowed('customers')) {
        $userBranch = (int) (current_user()['branch_id'] ?? 0);
        if ((int) $row['branch_id'] !== $userBranch) {
            return null;
        }
    }

    return $row;
}

/**
 * إضافة عميل جديد وتوليد الكود تلقائياً.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function cust_create(PDO $pdo, array $input, int $userId): array
{
    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('اسم العميل مطلوب.');
    }

    // تحديد الفرع
    if (is_real_owner() || view_all_borrowed('customers:edit')) {
        $branchId = (int) ($input['branch_id'] ?? 0);
        if ($branchId <= 0) {
            $branches = cust_branches($pdo);
            $branchId = (int) ($branches[0]['id'] ?? 1);
        }
    } else {
        $branchId = (int) (current_user()['branch_id'] ?? 0);
        if ($branchId <= 0) {
            throw new RuntimeException('المستخدم مش مربوط بفرع.');
        }
    }

    $phone = cust_clean_phone(isset($input['phone']) ? (string) $input['phone'] : null);
    $phone2 = cust_clean_phone(isset($input['phone2']) ? (string) $input['phone2'] : null);

    // التحقق من تكرار الموبايل
    if ($phone !== null) {
        $chk = $pdo->prepare('SELECT id, name FROM customers WHERE phone = ?');
        $chk->execute([$phone]);
        $existing = $chk->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            throw new RuntimeException('الموبايل ده متسجّل لعميل تاني: ' . $existing['name']);
        }
    }

    $kind = ($input['kind'] ?? '') === 'regular' ? 'regular' : 'walkin';
    $notes = isset($input['notes']) && trim((string) $input['notes']) !== '' ? trim((string) $input['notes']) : null;

    // توليد الكود والإدراج
    $attempts = 0;
    while ($attempts < 3) {
        $attempts++;
        $code = cust_next_code($pdo);
        try {
            $ins = $pdo->prepare(
                'INSERT INTO customers (code, name, phone, phone2, kind, branch_id, notes, is_active, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())'
            );
            $ins->execute([$code, $name, $phone, $phone2, $kind, $branchId, $notes, $userId]);
            $newId = (int) $pdo->lastInsertId();
            $created = cust_get($pdo, $newId);
            if ($created === null) {
                throw new RuntimeException('تعذر جلب العميل بعد إضافته.');
            }
            return $created;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_customers_code')) {
                continue;
            }
            if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_customers_phone')) {
                $chk = $pdo->prepare('SELECT id, name FROM customers WHERE phone = ?');
                $chk->execute([$phone]);
                $existing = $chk->fetch(PDO::FETCH_ASSOC);
                $dupName = $existing ? $existing['name'] : '';
                throw new RuntimeException('الموبايل ده متسجّل لعميل تاني: ' . $dupName);
            }
            throw $e;
        }
    }

    throw new RuntimeException('تعذر توليد كود فريد للعميل.');
}

/**
 * تحديث خانة واحدة لعميل موجود (حفظ تلقائي على blur).
 *
 * @return array<string, mixed>
 */
function cust_update_field(PDO $pdo, int $id, string $field, mixed $value, int $userId): array
{
    $allowed = ['name', 'phone', 'phone2', 'kind', 'notes', 'is_active', 'branch_id'];
    if (!in_array($field, $allowed, true)) {
        throw new InvalidArgumentException('الخانة دي مش قابلة للتعديل.');
    }

    $cust = cust_get($pdo, $id);
    if (!$cust) {
        throw new RuntimeException('العميل مش موجود أو مالكش صلاحية عليه.');
    }

    if ($field === 'name') {
        $value = trim((string) $value);
        if ($value === '') {
            throw new InvalidArgumentException('اسم العميل مطلوب.');
        }
    } elseif ($field === 'phone') {
        $value = cust_clean_phone($value !== null ? (string) $value : null);
        if ($value !== null) {
            $chk = $pdo->prepare('SELECT id, name FROM customers WHERE phone = ? AND id != ?');
            $chk->execute([$value, $id]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                throw new RuntimeException('الموبايل ده متسجّل لعميل تاني: ' . $existing['name']);
            }
        }
    } elseif ($field === 'phone2') {
        $value = cust_clean_phone($value !== null ? (string) $value : null);
    } elseif ($field === 'kind') {
        if (!in_array($value, ['regular', 'walkin'], true)) {
            throw new InvalidArgumentException('نوع العميل غير صحيح.');
        }
    } elseif ($field === 'notes') {
        $value = ($value === null || trim((string) $value) === '') ? null : trim((string) $value);
    } elseif ($field === 'is_active') {
        $value = !empty($value) ? 1 : 0;
    } elseif ($field === 'branch_id') {
        if (!is_real_owner() && !view_all_borrowed('customers:edit')) {
            throw new RuntimeException('مالكش صلاحية تغيير فرع العميل.');
        }
        $value = (int) $value;
        cust_require_branch($pdo, $value);
    }

    $st = $pdo->prepare("UPDATE customers SET `$field` = ?, updated_at = NOW() WHERE id = ?");
    $st->execute([$value, $id]);

    $updated = cust_get($pdo, $id);
    if (!$updated) {
        throw new RuntimeException('تعذر جلب بيانات العميل بعد التعديل.');
    }
    return $updated;
}

/**
 * كشف حساب العميل (سجل مشتريات) باستعلامين مجمّعين فقط:
 * ١) المستندات المكتملة بالتاريخ.
 * ٢) الدفعات مجمّعة لكل المستندات.
 *
 * @return array{customer: array<string, mixed>, kpis: array<string, mixed>, statement: array<int, array<string, mixed>>, notice: string}
 */
function cust_statement(PDO $pdo, int $customerId): array
{
    $cust = cust_get($pdo, $customerId);
    if (!$cust) {
        throw new RuntimeException('العميل مش موجود أو مالكش صلاحية عليه.');
    }

    // استعلام المستندات المكتملة
    $docsStmt = $pdo->prepare(
        "SELECT d.id, d.receipt_no, d.doc_kind, d.branch_id, b.name AS branch_name,
                d.gross, d.line_discount, d.invoice_discount, d.net,
                d.completed_at, d.created_at
           FROM sale_docs d
           LEFT JOIN branches b ON b.id = d.branch_id
          WHERE d.customer_id = ? AND d.status = 'completed'
          ORDER BY COALESCE(d.completed_at, d.created_at) ASC, d.id ASC"
    );
    $docsStmt->execute([$customerId]);
    $docs = $docsStmt->fetchAll(PDO::FETCH_ASSOC);

    // استعلام الدفعات المجمّع
    $paymentsByDoc = [];
    if ($docs !== []) {
        $docIds = array_map('intval', array_column($docs, 'id'));
        $placeholders = implode(',', array_fill(0, count($docIds), '?'));
        $payStmt = $pdo->prepare(
            "SELECT doc_id, method, amount
               FROM sale_payments
              WHERE doc_id IN ($placeholders)
              ORDER BY id ASC"
        );
        $payStmt->execute($docIds);
        $methodLabels = [
            'cash'     => 'كاش',
            'visa'     => 'فيزا',
            'instapay' => 'إنستاباي',
        ];
        while ($p = $payStmt->fetch(PDO::FETCH_ASSOC)) {
            $dId = (int) $p['doc_id'];
            $method = (string) $p['method'];
            $mLabel = $methodLabels[$method] ?? $method;
            $amt = (float) $p['amount'];
            if (!isset($paymentsByDoc[$dId])) {
                $paymentsByDoc[$dId] = [];
            }
            $paymentsByDoc[$dId][] = $mLabel . ': ' . number_format($amt, 2);
        }
    }

    // حساب الرصيد التراكمي ومؤشرات الأداء
    $runningBalance = 0.00;
    $totalSales = 0.00;
    $totalReturns = 0.00;
    $lastVisit = null;

    foreach ($docs as &$doc) {
        $net = (float) $doc['net'];
        $kind = (string) $doc['doc_kind'];
        if ($kind === 'sale') {
            $runningBalance += $net;
            $totalSales += $net;
        } else {
            $runningBalance -= $net;
            $totalReturns += $net;
        }
        $doc['running_balance'] = round($runningBalance, 2);

        $docId = (int) $doc['id'];
        $doc['payments_summary'] = isset($paymentsByDoc[$docId]) ? implode(' · ', $paymentsByDoc[$docId]) : '-';

        $visitDate = (string) ($doc['completed_at'] ?? $doc['created_at']);
        if ($visitDate !== '' && ($lastVisit === null || $visitDate > $lastVisit)) {
            $lastVisit = $visitDate;
        }
    }
    unset($doc);

    $kpis = [
        'invoices_count' => count($docs),
        'total_sales'    => round($totalSales, 2),
        'total_returns'  => round($totalReturns, 2),
        'net_total'      => round($totalSales - $totalReturns, 2),
        'last_visit'     => $lastVisit,
    ];

    return [
        'customer'  => $cust,
        'kpis'      => $kpis,
        'statement' => $docs,
        'notice'    => 'ده سجل مشتريات مش مديونية — مفيش آجل في نقطة البيع دلوقتي',
    ];
}

/**
 * تصدير كشف الحساب إلى ملف إكسيل باستخدام SimpleXlsx (ممنوع CSV).
 *
 * @param array{customer: array<string, mixed>, kpis: array<string, mixed>, statement: array<int, array<string, mixed>>, notice: string} $statementData
 */
function cust_export_statement_xlsx(array $statementData): void
{
    require_once __DIR__ . '/xlsx_lib.php';

    $cust = $statementData['customer'];
    $kpis = $statementData['kpis'];
    $statement = $statementData['statement'];

    $code = (string) ($cust['code'] ?? $cust['id']);
    $x = new SimpleXlsx('كشف حساب ' . mb_substr($code, 0, 20));
    $x->setColumnTypes(['s', 's', 's', 's', 's', 'n', 'n']);

    $x->addRow([
        'العميل:', (string) $cust['name'] . ' (' . $code . ')',
        'الموبايل:', (string) ($cust['phone'] ?? '-'),
        'الفرع:', (string) ($cust['branch_name'] ?? '-'),
        'نوع العميل:', ($cust['kind'] ?? '') === 'regular' ? 'دايم' : 'طياري',
    ], true);

    $x->addRow(['ملاحظة:', $statementData['notice']], false);

    $x->addRow([
        'عدد الفواتير:', (string) $kpis['invoices_count'],
        'إجمالي المشتريات:', number_format((float) $kpis['total_sales'], 2),
        'المرتجع:', number_format((float) $kpis['total_returns'], 2),
        'الصافي:', number_format((float) $kpis['net_total'], 2),
        'آخر زيارة:', (string) ($kpis['last_visit'] ?? '-'),
    ], true);

    $x->addBlank();

    $x->addRow([
        'التاريخ', 'رقم الإيصال', 'النوع', 'الفرع', 'طرق الدفع', 'الصافي', 'الرصيد التراكمي',
    ], true);

    foreach ($statement as $r) {
        $kindLabel = $r['doc_kind'] === 'sale' ? 'بيع' : 'مرتجع';
        $netVal = (float) $r['net'];
        if ($r['doc_kind'] === 'return') {
            $netVal = -$netVal;
        }
        $x->addRow([
            (string) ($r['completed_at'] ?? $r['created_at']),
            (string) ($r['receipt_no'] ?? ('#' . $r['id'])),
            $kindLabel,
            (string) ($r['branch_name'] ?? '-'),
            (string) ($r['payments_summary'] ?? '-'),
            $netVal,
            (float) $r['running_balance'],
        ]);
    }

    $filename = 'customer_' . $code . '_statement.xlsx';
    $x->download($filename);
    exit;
}
