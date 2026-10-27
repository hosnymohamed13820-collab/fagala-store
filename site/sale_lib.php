<?php
/** sale_lib.php — منطق نقطة البيع المشترك بين الـAPI والشاشات. */
declare(strict_types=1);

require_once __DIR__ . '/stock_lib.php';
require_once __DIR__ . '/item_master_lib.php';
require_once __DIR__ . '/push_lib.php';

final class SaleApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $http = 400,
        public readonly string $apiCode = 'bad_request'
    ) {
        parent::__construct($message);
    }
}

/** هل الفرع جاهز للبيع من مخزون الموقع؟ */
function sale_branch_ready(int $branchId): bool
{
    return stock_enabled() && stock_branch_mode($branchId) === 'live';
}

/** تاريخ يوم العمل المحاسبي، وحده الفاصل الساعة ٩ صباحاً. */
function sale_business_date(?DateTimeInterface $now = null): string
{
    $date = $now ? DateTime::createFromInterface($now) : new DateTime();
    if ((int) $date->format('H') < 9) {
        $date->modify('-1 day');
    }
    return $date->format('Y-m-d');
}

/** الفروع المسموح للمستخدم يشتغل عليها. @return int[] */
function sale_allowed_branch_ids(PDO $pdo, array $user): array
{
    if (!empty($user['is_owner'])) {
        $branchIds = $pdo->query(
            'SELECT id
               FROM branches
              WHERE is_active = 1
              ORDER BY id'
        )->fetchAll(PDO::FETCH_COLUMN);
        return array_map('intval', $branchIds);
    }

    $stmt = $pdo->prepare(
        'SELECT DISTINCT branch_id
           FROM shifts
          WHERE user_id = ? AND business_date = ?'
    );
    $stmt->execute([(int) ($user['id'] ?? 0), sale_business_date()]);
    $branchIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $branchId = (int) ($user['branch_id'] ?? 0);
    if ($branchId > 0 && !in_array($branchId, $branchIds, true)) {
        $branchIds[] = $branchId;
    }
    return array_values(array_unique($branchIds));
}

/** يرفض الفرع لو بره نطاق المستخدم. */
function sale_require_branch(PDO $pdo, int $branchId, array $user): void
{
    if ($branchId <= 0 || !in_array($branchId, sale_allowed_branch_ids($pdo, $user), true)) {
        throw new SaleApiException('مالكش صلاحية على الفرع ده.', 403, 'forbidden');
    }
}

/** يجيب الجهاز ويتأكد إنه نشط وفي نطاق المستخدم. */
function sale_device(PDO $pdo, string $uuid, array $user, bool $lock = false): array
{
    if (trim($uuid) === '') {
        throw new SaleApiException('معرّف الجهاز مطلوب.');
    }
    $sql = 'SELECT d.id, d.branch_id, d.name, d.device_uuid, d.code, d.is_active,
                   b.name branch_name, b.sto_id
              FROM sale_devices d
              JOIN branches b ON b.id = d.branch_id
             WHERE d.device_uuid = ?' . ($lock ? ' FOR UPDATE' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([trim($uuid)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new SaleApiException('الجهاز مش متسجل.', 404);
    }
    if (empty($row['is_active'])) {
        throw new SaleApiException('الجهاز متوقف، كلم المسؤول.', 409, 'locked');
    }
    sale_require_branch($pdo, (int) $row['branch_id'], $user);
    return $row;
}

/** الوردية المفتوحة على الجهاز، مع قفل اختياري. */
function sale_open_session(PDO $pdo, int $deviceId, bool $lock = false): ?array
{
    $sql = "SELECT s.*, u.full_name cashier_name
              FROM sale_sessions s
              LEFT JOIN users u ON u.id = s.cashier_id
             WHERE s.device_id = ? AND s.status = 'open'
             ORDER BY s.id DESC
             LIMIT 1" . ($lock ? ' FOR UPDATE' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$deviceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** سياق تشغيل شاشة البيع للجهاز الحالي. */
function sale_context(PDO $pdo, string $uuid, array $user): array
{
    $device = sale_device($pdo, $uuid, $user);
    $session = sale_open_session($pdo, (int) $device['id']);
    $capStmt = $pdo->prepare('SELECT discount_cap_pct FROM users WHERE id = ?');
    $capStmt->execute([(int) $user['id']]);
    $settings = ['pos_max_payments' => '3', 'pos_return_days' => '14'];
    $settingRows = $pdo->query(
        "SELECT setting_key, setting_value
           FROM settings
          WHERE setting_key IN ('pos_max_payments','pos_return_days')"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($settingRows as $settingRow) {
        $settings[$settingRow['setting_key']] = $settingRow['setting_value'];
    }
    return [
        'device' => [
            'id' => (int) $device['id'],
            'uuid' => $device['device_uuid'],
            'name' => $device['name'],
            'code' => $device['code'],
        ],
        'branch' => ['id' => (int) $device['branch_id'], 'name' => $device['branch_name']],
        'session' => $session ? [
            'id' => (int) $session['id'],
            'cashier_id' => (int) $session['cashier_id'],
            'cashier_name' => $session['cashier_name'],
            'business_date' => $session['business_date'],
            'opened_at' => $session['opened_at'],
            'opening_cash' => (float) $session['opening_cash'],
        ] : null,
        'discount_cap_pct' => round((float) ($capStmt->fetchColumn() ?: 0), 2),
        'settings' => [
            'pos_max_payments' => max(1, (int) $settings['pos_max_payments']),
            'pos_return_days' => max(0, (int) $settings['pos_return_days']),
        ],
        'ready' => sale_branch_ready((int) $device['branch_id']),
    ];
}

/** إدراج أحداث البيع في استعلام مجمّع واحد. */
function sale_events_insert(PDO $pdo, array $events): void
{
    if (!$events) {
        return;
    }
    $valueGroups = [];
    $params = [];
    foreach ($events as $event) {
        $valueGroups[] = '(?,?,?,?,?,?,?,?,NOW())';
        array_push(
            $params,
            (int) $event['branch_id'],
            (int) $event['device_id'],
            $event['session_id'] !== null ? (int) $event['session_id'] : null,
            (int) $event['user_id'],
            $event['doc_id'] !== null ? (int) $event['doc_id'] : null,
            (string) $event['event_type'],
            json_encode($event['payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $event['approved_by'] !== null ? (int) $event['approved_by'] : null
        );
    }
    $pdo->prepare(
        'INSERT INTO sale_events
            (branch_id, device_id, session_id, user_id, doc_id, event_type, payload, approved_by, created_at)
         VALUES ' . implode(',', $valueGroups)
    )->execute($params);
}

/** فتح وردية تشغيلية واحدة للجهاز. */
function sale_session_open(PDO $pdo, string $uuid, float $openingCash, array $user): array
{
    if ($openingCash < 0) {
        throw new SaleApiException('عهدة البداية مينفعش تبقى سالبة.');
    }
    $pdo->beginTransaction();
    try {
        $device = sale_device($pdo, $uuid, $user, true);
        if (sale_open_session($pdo, (int) $device['id'], true)) {
            throw new SaleApiException('الجهاز عليه وردية مفتوحة بالفعل.', 409, 'duplicate');
        }
        $stmt = $pdo->prepare(
            "INSERT INTO sale_sessions
                (device_id, branch_id, cashier_id, business_date, opened_at, opening_cash, status)
             VALUES (?, ?, ?, ?, NOW(), ?, 'open')"
        );
        $stmt->execute([
            (int) $device['id'],
            (int) $device['branch_id'],
            (int) $user['id'],
            sale_business_date(),
            round($openingCash, 2),
        ]);
        $sessionId = (int) $pdo->lastInsertId();
        sale_events_insert($pdo, [[
            'branch_id' => (int) $device['branch_id'],
            'device_id' => (int) $device['id'],
            'session_id' => $sessionId,
            'user_id' => (int) $user['id'],
            'doc_id' => null,
            'event_type' => 'session_opened',
            'payload' => ['opening_cash' => round($openingCash, 2)],
            'approved_by' => null,
        ]]);
        $pdo->commit();
        return ['session_id' => $sessionId, 'business_date' => sale_business_date()];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** كل كلمات الاسم بأي ترتيب، وبحد أقصى ٢٠ صنف. */
function sale_lookup(PDO $pdo, int $branchId, string $code, string $q): array
{
    $code = trim($code);
    $q = trim($q);
    if ($code === '' && $q === '') {
        throw new SaleApiException('اكتب كود أو اسم الصنف.');
    }

    $words = im_words($q);
    $trueNameParts = [];
    $siteNameParts = [];
    foreach ($words as $word) {
        $trueNameParts[] = "CONCAT_WS(' ',c.name,ic.item_name) LIKE ?";
        $siteNameParts[] = "CONCAT_WS(' ',m.name1,mc.name1,mc.name2) LIKE ?";
    }
    $trueWhere = $code !== '' ? 'c.code=?' : '0';
    $siteWhere = $code !== '' ? 'mc.code=?' : '0';
    if ($trueNameParts) {
        $trueWhere .= ' OR (' . implode(' AND ', $trueNameParts) . ')';
        $siteWhere .= ' OR (' . implode(' AND ', $siteNameParts) . ')';
    }
    $trueParams = [$code];
    $siteParams = [$code];
    if ($code !== '') {
        $trueParams[] = $code;
        $siteParams[] = $code;
    }
    foreach ($words as $word) {
        $trueParams[] = '%' . $word . '%';
        $siteParams[] = '%' . $word . '%';
    }

    $sql = "SELECT source, source_id, item_id, item_name, exact_code
              FROM (
                    SELECT 'true' source, c.item_id source_id, c.item_id item_id,
                           COALESCE(MAX(ic.item_name),MAX(c.name),'') item_name,
                           MAX(c.code=?) exact_code
                      FROM hq_codes c
                      LEFT JOIN hq_item_cats ic ON ic.item_id=c.item_id
                     WHERE $trueWhere
                     GROUP BY c.item_id
                    UNION ALL
                    SELECT 'site', m.id, -m.id, m.name1, MAX(mc.code=?)
                      FROM item_master m
                      JOIN item_master_codes mc ON mc.item_master_id=m.id
                     WHERE m.is_cancelled=0 AND mc.is_sale_code=1 AND ($siteWhere)
                     GROUP BY m.id,m.name1
                   ) x
             ORDER BY exact_code DESC,item_name,source
             LIMIT 20";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($trueParams, $siteParams));
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) {
        return [];
    }

    $trueItemIds = [];
    $siteItemIds = [];
    foreach ($items as $item) {
        if ($item['source'] === 'true') {
            $trueItemIds[] = (int) $item['source_id'];
        } else {
            $siteItemIds[] = (int) $item['source_id'];
        }
    }

    $queryParts = [];
    $params = [];
    if ($trueItemIds) {
        $placeholders = implode(',', array_fill(0, count($trueItemIds), '?'));
        $queryParts[] = "SELECT 'true' source, c.item_id source_id, c.code,
                               COALESCE(c.name,'') code_name,
                               CASE WHEN c.unit_factor=1 THEN 'قطعة'
                                    ELSE CONCAT('وحدة × ',c.unit_factor) END unit_name,
                               c.unit_factor, COALESCE(ip.price,c.price) price,
                               COALESCE(sb.qty,0) balance
                          FROM hq_codes c
                          LEFT JOIN item_prices ip ON ip.code=c.code
                          LEFT JOIN stock_balances sb
                            ON sb.item_id=c.item_id AND sb.branch_id=? AND sb.is_transit=0
                         WHERE c.item_id IN ($placeholders)";
        array_push($params, $branchId, ...$trueItemIds);
    }
    if ($siteItemIds) {
        $placeholders = implode(',', array_fill(0, count($siteItemIds), '?'));
        $queryParts[] = "SELECT 'site', m.id, mc.code, COALESCE(mc.name1,m.name1),
                               mc.unit_name, mc.unit_factor, ip.price, COALESCE(sb.qty,0)
                          FROM item_master m
                          JOIN item_master_codes mc
                            ON mc.item_master_id=m.id AND mc.is_sale_code=1
                          LEFT JOIN item_prices ip ON ip.code=mc.code
                          LEFT JOIN stock_balances sb
                            ON sb.item_id=-m.id AND sb.branch_id=? AND sb.is_transit=0
                         WHERE m.id IN ($placeholders)";
        array_push($params, $branchId, ...$siteItemIds);
    }

    $codesStmt = $pdo->prepare(
        implode(' UNION ALL ', $queryParts) . ' ORDER BY source,source_id,code'
    );
    $codesStmt->execute($params);
    $codesByItem = [];
    $balancesByItem = [];
    foreach ($codesStmt->fetchAll(PDO::FETCH_ASSOC) as $codeRow) {
        $itemKey = $codeRow['source'] . ':' . $codeRow['source_id'];
        $codesByItem[$itemKey][] = [
            'code' => $codeRow['code'],
            'name' => $codeRow['code_name'],
            'unit_name' => $codeRow['unit_name'],
            'unit_factor' => (float) $codeRow['unit_factor'],
            'price' => $codeRow['price'] !== null ? (float) $codeRow['price'] : null,
        ];
        $balancesByItem[$itemKey] = (float) $codeRow['balance'];
    }

    $result = [];
    foreach ($items as $item) {
        $itemKey = $item['source'] . ':' . $item['source_id'];
        $result[] = [
            'item_id' => (int) $item['item_id'],
            'source' => $item['source'],
            'name' => $item['item_name'],
            'codes' => $codesByItem[$itemKey] ?? [],
            'balance' => $balancesByItem[$itemKey] ?? 0.0,
        ];
    }
    return $result;
}

/** تحويل الخصم المدخل، مبلغ أو نسبة، لقيمة مالية. */
function sale_discount_amount(mixed $discount, float $base, string $label): float
{
    $type = 'amount';
    $value = 0.0;
    if (is_array($discount)) {
        $type = (string) ($discount['type'] ?? 'amount');
        $value = (float) ($discount['value'] ?? 0);
    } elseif (is_string($discount) && str_ends_with(trim($discount), '%')) {
        $type = 'percent';
        $value = (float) rtrim(trim($discount), "% \t\n\r\0\x0B");
    } elseif ($discount !== null && $discount !== '') {
        $value = (float) $discount;
    }
    if (!is_finite($value) || $value < 0) {
        throw new InvalidArgumentException("$label مينفعش يبقى سالب.");
    }
    if (!in_array($type, ['amount', 'percent'], true)) {
        throw new InvalidArgumentException("نوع $label مش صحيح.");
    }
    if ($type === 'percent') {
        if ($value > 100) {
            throw new InvalidArgumentException("نسبة $label مينفعش تعدي ١٠٠٪.");
        }
        return round($base * $value / 100, 2);
    }
    return round($value, 2);
}

/** مصدر الحساب الوحيد لإجماليات البيع. */
function sale_compute(array $lines, mixed $invoiceDiscount = 0): array
{
    if (!$lines) {
        throw new InvalidArgumentException('الفاتورة لازم يكون فيها صنف واحد على الأقل.');
    }
    $gross = 0.0;
    $lineDiscountTotal = 0.0;
    $computedLines = [];
    foreach ($lines as $index => $line) {
        $qty = (float) ($line['qty'] ?? 0);
        $unitPrice = (float) ($line['unit_price'] ?? 0);
        if (!is_finite($qty) || $qty <= 0) {
            throw new InvalidArgumentException('كمية السطر ' . ($index + 1) . ' لازم تبقى أكبر من صفر.');
        }
        if (!is_finite($unitPrice) || $unitPrice < 0) {
            throw new InvalidArgumentException('سعر السطر ' . ($index + 1) . ' مش صحيح.');
        }
        $lineGross = round($qty * $unitPrice, 2);
        $lineDiscount = sale_discount_amount(
            $line['line_discount'] ?? 0,
            $lineGross,
            'خصم السطر'
        );
        if ($lineDiscount > $lineGross) {
            throw new InvalidArgumentException('خصم السطر أكبر من قيمة السطر.');
        }
        $gross = round($gross + $lineGross, 2);
        $lineDiscountTotal = round($lineDiscountTotal + $lineDiscount, 2);
        $computedLines[] = array_merge($line, [
            'qty' => $qty,
            'unit_price' => round($unitPrice, 2),
            'gross' => $lineGross,
            'line_discount' => $lineDiscount,
            'net' => round($lineGross - $lineDiscount, 2),
        ]);
    }
    $afterLineDiscounts = round($gross - $lineDiscountTotal, 2);
    $invoiceDiscountAmount = sale_discount_amount(
        $invoiceDiscount,
        $afterLineDiscounts,
        'خصم الفاتورة'
    );
    if ($invoiceDiscountAmount > $afterLineDiscounts) {
        throw new InvalidArgumentException('خصم الفاتورة أكبر من الباقي بعد خصم السطور.');
    }
    $net = round($afterLineDiscounts - $invoiceDiscountAmount, 2);
    $totalDiscount = round($lineDiscountTotal + $invoiceDiscountAmount, 2);
    return [
        'lines' => $computedLines,
        'gross' => $gross,
        'line_discount' => $lineDiscountTotal,
        'invoice_discount' => $invoiceDiscountAmount,
        'net' => $net,
        'discount_pct' => $gross > 0 ? round($totalDiscount / $gross * 100, 3) : 0.0,
    ];
}

/** التحقق من الدفعات وحساب الباقي. */
function sale_validate_payments(array $payments, float $net, int $maxPayments = 3): array
{
    if (count($payments) < 1) {
        throw new InvalidArgumentException('لازم تسجل دفعة واحدة على الأقل.');
    }
    if (count($payments) > $maxPayments) {
        throw new InvalidArgumentException("أقصى عدد دفعات هو $maxPayments.");
    }
    $cleanPayments = [];
    $paid = 0.0;
    $cash = 0.0;
    $nonCash = 0.0;
    foreach ($payments as $payment) {
        $method = (string) ($payment['method'] ?? '');
        $amount = (float) ($payment['amount'] ?? 0);
        if (!in_array($method, ['cash', 'visa', 'instapay'], true)) {
            throw new InvalidArgumentException('طريقة الدفع مش مدعومة.');
        }
        if (!is_finite($amount) || $amount <= 0) {
            throw new InvalidArgumentException('قيمة كل دفعة لازم تبقى أكبر من صفر.');
        }
        $amount = round($amount, 2);
        $paid = round($paid + $amount, 2);
        if ($method === 'cash') {
            $cash = round($cash + $amount, 2);
        } else {
            $nonCash = round($nonCash + $amount, 2);
        }
        $reference = trim((string) ($payment['reference'] ?? ''));
        $cleanPayments[] = [
            'method' => $method,
            'amount' => $amount,
            'reference' => $reference !== '' ? mb_substr($reference, 0, 100) : null,
        ];
    }
    $net = round($net, 2);
    if ($nonCash > $net) {
        throw new InvalidArgumentException('الفيزا وإنستا باي مينفعش يزيدوا عن الصافي.');
    }
    if ($paid < $net) {
        throw new InvalidArgumentException('المدفوع أقل من الصافي.');
    }
    $change = round($paid - $net, 2);
    if ($change > $cash) {
        throw new InvalidArgumentException('الباقي لازم يرجع من دفعة الكاش بس.');
    }
    return [
        'payments' => $cleanPayments,
        'paid' => $paid,
        'cash' => $cash,
        'non_cash' => $nonCash,
        'change_due' => $change,
    ];
}

/** تسعير كل أكواد السلة من السيرفر في استعلام واحد. */
function sale_resolve_lines(PDO $pdo, array $lines): array
{
    if (!$lines || count($lines) > 200) {
        throw new SaleApiException('عدد سطور الفاتورة لازم يكون من ١ لـ٢٠٠.');
    }
    $uniqueCodeSet = [];
    foreach ($lines as $line) {
        $code = trim((string) ($line['code'] ?? ''));
        if ($code === '') {
            throw new SaleApiException('كود الصنف مطلوب في كل سطر.');
        }
        $uniqueCodeSet[$code] = true;
    }
    $codes = array_keys($uniqueCodeSet);
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $sql = "SELECT c.code, c.item_id, COALESCE(ic.item_name,c.name,'') item_name,
                   CASE WHEN c.unit_factor=1 THEN 'قطعة'
                        ELSE CONCAT('وحدة × ',c.unit_factor) END unit_name,
                   c.unit_factor, COALESCE(ip.price,c.price) unit_price
              FROM hq_codes c
              LEFT JOIN hq_item_cats ic ON ic.item_id=c.item_id
              LEFT JOIN item_prices ip ON ip.code=c.code
             WHERE c.code IN ($placeholders)
            UNION ALL
            SELECT mc.code, -m.id, m.name1, mc.unit_name, mc.unit_factor, ip.price
              FROM item_master_codes mc
              JOIN item_master m ON m.id=mc.item_master_id
              LEFT JOIN item_prices ip ON ip.code=mc.code
             WHERE mc.code IN ($placeholders)
               AND mc.is_sale_code=1 AND m.is_cancelled=0
               AND NOT EXISTS(SELECT 1 FROM hq_codes hc WHERE hc.code=mc.code)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($codes, $codes));
    $codesByValue = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $codeRow) {
        $codesByValue[$codeRow['code']] = $codeRow;
    }

    $resolvedLines = [];
    foreach ($lines as $line) {
        $code = trim((string) $line['code']);
        $codeRow = $codesByValue[$code] ?? null;
        if (!$codeRow) {
            throw new SaleApiException("الكود $code مش موجود أو مش متاح للبيع.");
        }
        if ($codeRow['unit_price'] === null) {
            throw new SaleApiException(
                'الصنف ' . ($codeRow['item_name'] ?: $code) . ' مالوش سعر بيع.'
            );
        }
        $qty = (float) ($line['qty'] ?? 0);
        $piecesCalc = item_line_pieces($qty, null, (float) $codeRow['unit_factor']);
        $resolvedLines[] = [
            'item_id' => (int) $codeRow['item_id'],
            'item_name' => $codeRow['item_name'] ?: $code,
            'code' => $code,
            'unit_name' => $codeRow['unit_name'],
            'unit_factor' => (float) $piecesCalc['factor'],
            'qty' => $qty,
            'qty_pieces' => (float) $piecesCalc['pieces'],
            'unit_price' => (float) $codeRow['unit_price'],
            'line_discount' => $line['line_discount'] ?? 0,
        ];
    }
    return $resolvedLines;
}

/** إدراج سطور البيع مجمعة. */
function sale_lines_insert(PDO $pdo, int $docId, array $lines): void
{
    $valueGroups = [];
    $params = [];
    foreach ($lines as $index => $line) {
        $valueGroups[] = '(?,?,?,?,?,?,?,?,?,?,?,NULL,NULL,NOW())';
        array_push(
            $params,
            $docId,
            $index + 1,
            (int) $line['item_id'],
            $line['code'],
            $line['unit_name'],
            round((float) $line['unit_factor'], 3),
            round((float) $line['qty'], 3),
            round((float) $line['qty_pieces'], 3),
            round((float) $line['unit_price'], 2),
            round((float) $line['line_discount'], 2),
            round((float) $line['net'], 2)
        );
    }
    $pdo->prepare(
        'INSERT INTO sale_lines
            (doc_id, line_no, item_id, code, unit_name, unit_factor, qty, qty_pieces,
             unit_price, line_discount, net, unit_cost, ref_line_id, created_at)
         VALUES ' . implode(',', $valueGroups)
    )->execute($params);
}

/** إدراج دفعات البيع مجمعة. */
function sale_payments_insert(PDO $pdo, int $docId, array $payments): void
{
    $valueGroups = [];
    $params = [];
    foreach ($payments as $payment) {
        $valueGroups[] = '(?,?,?,?,NOW())';
        array_push(
            $params,
            $docId,
            $payment['method'],
            $payment['amount'],
            $payment['reference']
        );
    }
    $pdo->prepare(
        'INSERT INTO sale_payments (doc_id, method, amount, reference, created_at)
         VALUES ' . implode(',', $valueGroups)
    )->execute($params);
}

/** شكل رد الفاتورة المكتملة، وبيستخدم كمان في إعادة الطلب. */
function sale_doc_result(array $doc): array
{
    return [
        'doc_id' => (int) $doc['id'],
        'receipt_no' => (string) $doc['receipt_no'],
        'gross' => (float) $doc['gross'],
        'line_discount' => (float) $doc['line_discount'],
        'invoice_discount' => (float) $doc['invoice_discount'],
        'net' => (float) $doc['net'],
        'paid' => (float) $doc['paid'],
        'change_due' => (float) $doc['change_due'],
    ];
}

/** فاتورة بنفس مفتاح منع التكرار، مع قفلها. */
function sale_existing_by_client(PDO $pdo, int $deviceId, string $uuid): ?array
{
    $stmt = $pdo->prepare(
        'SELECT *
           FROM sale_docs
          WHERE device_id = ? AND client_uuid = ?
          LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([$deviceId, $uuid]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    return $doc ?: null;
}

/** حجز رقم الإيصال التالي للجهاز والسنة. */
function sale_next_receipt(PDO $pdo, array $device): string
{
    $year = (int) date('Y');
    $pdo->prepare(
        'INSERT INTO sale_doc_seq (device_id, doc_year, last_no)
         VALUES (?, ?, 0)
         ON DUPLICATE KEY UPDATE device_id=VALUES(device_id)'
    )->execute([(int) $device['id'], $year]);
    $stmt = $pdo->prepare(
        'SELECT last_no
           FROM sale_doc_seq
          WHERE device_id = ? AND doc_year = ?
            FOR UPDATE'
    );
    $stmt->execute([(int) $device['id'], $year]);
    $next = (int) $stmt->fetchColumn() + 1;
    $pdo->prepare(
        'UPDATE sale_doc_seq
            SET last_no = ?
          WHERE device_id = ? AND doc_year = ?'
    )->execute([$next, (int) $device['id'], $year]);
    // branches مافيهاش code؛ الـid هو كود الفرع الداخلي الثابت والفريد.
    return (int) $device['branch_id'] . '/' . $device['code'] . '/' . $year . '/' . $next;
}

/** التحقق من اعتماد الخصم لو النسبة عدت سقف الكاشير. */
function sale_discount_approval(PDO $pdo, int $deviceId, float $pct, float $cap, string $token): array
{
    if ($pct <= $cap + 0.0005) {
        return ['approval_id' => null, 'approved_by' => null];
    }
    if (trim($token) === '') {
        throw new SaleApiException('الخصم فوق سقفك — محتاج اعتماد مشرف', 403, 'forbidden');
    }
    $stmt = $pdo->prepare(
        "SELECT id, approved_by, approved_value
           FROM sale_approval_tokens
          WHERE token_hash = ? AND device_id = ? AND approval_type = 'discount'
            AND used_at IS NULL AND expires_at >= NOW()
          LIMIT 1 FOR UPDATE"
    );
    $stmt->execute([hash('sha256', $token), $deviceId]);
    $approval = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$approval || (float) $approval['approved_value'] + 0.0005 < $pct) {
        throw new SaleApiException(
            'اعتماد المشرف مش صالح أو اتستخدم قبل كده.',
            403,
            'forbidden'
        );
    }
    return [
        'approval_id' => (int) $approval['id'],
        'approved_by' => (int) $approval['approved_by'],
    ];
}

/** إنهاء البيع كله في transaction واحدة. */
function sale_finalize(PDO $pdo, array $input, array $user): array
{
    $clientUuid = trim((string) ($input['client_uuid'] ?? ''));
    if ($clientUuid === '' || mb_strlen($clientUuid) > 64) {
        throw new SaleApiException('معرّف عملية البيع مطلوب وبحد أقصى ٦٤ خانة.');
    }

    $pdo->beginTransaction();
    try {
        // قفل الجهاز قبل فحص الوردية والمسلسل.
        $device = sale_device(
            $pdo,
            (string) ($input['device_uuid'] ?? ''),
            $user,
            true
        );
        $deviceId = (int) $device['id'];
        $heldDocId = (int) ($input['doc_id'] ?? 0);

        // منع التكرار قبل أي كتابة وإرجاع نفس نتيجة البيع المكتمل.
        $existingDoc = sale_existing_by_client($pdo, $deviceId, $clientUuid);
        if ($existingDoc && $existingDoc['status'] === 'completed') {
            $result = sale_doc_result($existingDoc);
            $pdo->commit();
            return $result;
        }
        if ($existingDoc && ($heldDocId <= 0 || (int) $existingDoc['id'] !== $heldDocId)) {
            throw new SaleApiException(
                'معرّف العملية مستخدم في فاتورة تانية.',
                409,
                'duplicate'
            );
        }

        // قفل الوردية والتأكد إن الفرع انتقل من True للموقع.
        $session = sale_open_session($pdo, $deviceId, true);
        if (!$session) {
            throw new SaleApiException('افتح وردية على الجهاز الأول.', 409, 'locked');
        }
        if (!sale_branch_ready((int) $device['branch_id'])) {
            throw new SaleApiException(
                'الفرع لسه على True — البيع من نقطة البيع الجديدة مقفول.',
                409,
                'locked'
            );
        }

        // الأسعار والمعاملات كلها من السيرفر في استعلام واحد.
        $resolvedLines = sale_resolve_lines($pdo, (array) ($input['lines'] ?? []));

        // الحساب من المصدر المشترك فقط.
        try {
            $calculation = sale_compute(
                $resolvedLines,
                $input['invoice_discount'] ?? 0
            );
        } catch (InvalidArgumentException $e) {
            throw new SaleApiException($e->getMessage());
        }

        // الدفعات وحدها والباقي النقدي.
        $maxPayments = max(
            1,
            (int) (
                $pdo->query(
                    "SELECT setting_value
                       FROM settings
                      WHERE setting_key='pos_max_payments'"
                )->fetchColumn() ?: 3
            )
        );
        try {
            $paymentResult = sale_validate_payments(
                (array) ($input['payments'] ?? []),
                (float) $calculation['net'],
                $maxPayments
            );
        } catch (InvalidArgumentException $e) {
            throw new SaleApiException($e->getMessage());
        }

        // سقف الخصم واعتماد المشرف عند تجاوزه.
        $capStmt = $pdo->prepare('SELECT discount_cap_pct FROM users WHERE id=?');
        $capStmt->execute([(int) $user['id']]);
        $approval = sale_discount_approval(
            $pdo,
            $deviceId,
            (float) $calculation['discount_pct'],
            round((float) ($capStmt->fetchColumn() ?: 0), 2),
            (string) ($input['approval_token'] ?? '')
        );

        // قفل الفاتورة المعلقة لو الإنهاء مبني عليها.
        $heldDoc = null;
        if ($heldDocId > 0) {
            $heldStmt = $pdo->prepare('SELECT * FROM sale_docs WHERE id=? FOR UPDATE');
            $heldStmt->execute([$heldDocId]);
            $heldDoc = $heldStmt->fetch(PDO::FETCH_ASSOC);
            if (
                !$heldDoc
                || $heldDoc['status'] !== 'held'
                || (int) $heldDoc['device_id'] !== $deviceId
                || (string) $heldDoc['client_uuid'] !== $clientUuid
            ) {
                throw new SaleApiException(
                    'الفاتورة المعلّقة مش متاحة على الجهاز ده.',
                    409,
                    'locked'
                );
            }
        }

        // حجز المسلسل ورقم الإيصال جوه نفس المعاملة.
        $receiptNo = sale_next_receipt($pdo, $device);
        $customerId = isset($input['customer_id']) && (int) $input['customer_id'] > 0
            ? (int) $input['customer_id']
            : null;

        // تحديث رأس المعلقة أو إنشاء رأس بيع جديد.
        if ($heldDoc) {
            $docId = (int) $heldDoc['id'];
            $pdo->prepare(
                "UPDATE sale_docs
                    SET status='completed', session_id=?, cashier_id=?, customer_id=?,
                        receipt_no=?, gross=?, line_discount=?, invoice_discount=?, net=?,
                        paid=?, change_due=?, discount_approved_by=?, completed_at=NOW(),
                        updated_at=NOW()
                  WHERE id=? AND status='held'"
            )->execute([
                (int) $session['id'],
                (int) $user['id'],
                $customerId,
                $receiptNo,
                $calculation['gross'],
                $calculation['line_discount'],
                $calculation['invoice_discount'],
                $calculation['net'],
                $paymentResult['paid'],
                $paymentResult['change_due'],
                $approval['approved_by'],
                $docId,
            ]);
        } else {
            $docStmt = $pdo->prepare(
                "INSERT INTO sale_docs
                    (doc_kind, status, branch_id, device_id, session_id, cashier_id,
                     customer_id, client_uuid, receipt_no, gross, line_discount,
                     invoice_discount, net, paid, change_due, discount_approved_by,
                     completed_at)
                 VALUES ('sale','completed',?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())"
            );
            $docStmt->execute([
                (int) $device['branch_id'],
                $deviceId,
                (int) $session['id'],
                (int) $user['id'],
                $customerId,
                $clientUuid,
                $receiptNo,
                $calculation['gross'],
                $calculation['line_discount'],
                $calculation['invoice_discount'],
                $calculation['net'],
                $paymentResult['paid'],
                $paymentResult['change_due'],
                $approval['approved_by'],
            ]);
            $docId = (int) $pdo->lastInsertId();
        }
        sale_lines_insert($pdo, $docId, $calculation['lines']);
        sale_payments_insert($pdo, $docId, $paymentResult['payments']);

        // إنشاء مستند المخزن وترحيله، ثم تصوير تكلفة كل سطر.
        $stockLines = [];
        foreach ($calculation['lines'] as $line) {
            $stockLines[] = [
                'item_id' => (int) $line['item_id'],
                'code' => $line['code'],
                'unit_factor' => (float) $line['unit_factor'],
                'qty_units' => (float) $line['qty'],
                'unit_cost' => null,
            ];
        }
        $stockDocId = stock_doc_create(
            $pdo,
            'sale',
            [
                'branch_id' => (int) $device['branch_id'],
                'doc_date' => date('Y-m-d'),
                'ref_text' => $receiptNo,
                'notes' => 'بيع نقطة البيع #' . $docId,
            ],
            $stockLines,
            (int) $user['id']
        );
        try {
            stock_post($pdo, $stockDocId, (int) $user['id']);
        } catch (RuntimeException $e) {
            if (preg_match('/للصنف\s+(-?\d+)/u', $e->getMessage(), $matches)) {
                $badItemId = (int) $matches[1];
                $badItemName = 'الصنف ' . $badItemId;
                foreach ($calculation['lines'] as $line) {
                    if ((int) $line['item_id'] === $badItemId) {
                        $badItemName = $line['item_name'];
                        break;
                    }
                }
                throw new SaleApiException(
                    "الرصيد مش كفاية للصنف $badItemName.",
                    409,
                    'locked'
                );
            }
            throw $e;
        }
        $pdo->prepare('UPDATE sale_docs SET stock_doc_id=? WHERE id=?')
            ->execute([$stockDocId, $docId]);
        $pdo->prepare(
            'UPDATE sale_lines sl
              JOIN stock_doc_lines sdl ON sdl.doc_id=? AND sdl.line_no=sl.line_no
              JOIN stock_moves sm ON sm.doc_id=? AND sm.doc_line_id=sdl.id
                SET sl.unit_cost=sm.unit_cost
              WHERE sl.doc_id=?'
        )->execute([$stockDocId, $stockDocId, $docId]);

        // تعليم توكن الاعتماد كمستخدم لمرة واحدة.
        if ($approval['approval_id'] !== null) {
            $usedStmt = $pdo->prepare(
                'UPDATE sale_approval_tokens
                    SET used_at=NOW(), used_doc_id=?
                  WHERE id=? AND used_at IS NULL'
            );
            $usedStmt->execute([$docId, $approval['approval_id']]);
            if ($usedStmt->rowCount() !== 1) {
                throw new SaleApiException(
                    'اعتماد المشرف اتستخدم قبل كده.',
                    403,
                    'forbidden'
                );
            }
        }

        // أحداث الإتمام والخصم وفتح الدرج بعد البيع الكاش.
        $events = [[
            'branch_id' => (int) $device['branch_id'],
            'device_id' => $deviceId,
            'session_id' => (int) $session['id'],
            'user_id' => (int) $user['id'],
            'doc_id' => $docId,
            'event_type' => 'sale_completed',
            'payload' => [
                'receipt_no' => $receiptNo,
                'net' => $calculation['net'],
                'paid' => $paymentResult['paid'],
            ],
            'approved_by' => $approval['approved_by'],
        ]];
        if (
            (float) $calculation['line_discount'] > 0
            || (float) $calculation['invoice_discount'] > 0
        ) {
            $events[] = [
                'branch_id' => (int) $device['branch_id'],
                'device_id' => $deviceId,
                'session_id' => (int) $session['id'],
                'user_id' => (int) $user['id'],
                'doc_id' => $docId,
                'event_type' => 'discount_applied',
                'payload' => [
                    'line' => $calculation['line_discount'],
                    'invoice' => $calculation['invoice_discount'],
                    'pct' => $calculation['discount_pct'],
                ],
                'approved_by' => $approval['approved_by'],
            ];
        }
        if ((float) $paymentResult['cash'] > 0) {
            $events[] = [
                'branch_id' => (int) $device['branch_id'],
                'device_id' => $deviceId,
                'session_id' => (int) $session['id'],
                'user_id' => (int) $user['id'],
                'doc_id' => $docId,
                'event_type' => 'drawer_open',
                'payload' => ['reason' => 'cash_sale'],
                'approved_by' => null,
            ];
        }
        sale_events_insert($pdo, $events);

        $pdo->commit();
        return [
            'doc_id' => $docId,
            'receipt_no' => $receiptNo,
            'gross' => (float) $calculation['gross'],
            'line_discount' => (float) $calculation['line_discount'],
            'invoice_discount' => (float) $calculation['invoice_discount'],
            'net' => (float) $calculation['net'],
            'paid' => (float) $paymentResult['paid'],
            'change_due' => (float) $paymentResult['change_due'],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062) {
            throw new SaleApiException(
                'عملية البيع دي اتسجلت قبل كده.',
                409,
                'duplicate'
            );
        }
        throw $e;
    }
}

/** تعليق السلة من غير إيصال أو دفعات أو حركة مخزن. */
function sale_hold(PDO $pdo, array $input, array $user): array
{
    $clientUuid = trim((string) ($input['client_uuid'] ?? ''));
    if ($clientUuid === '' || mb_strlen($clientUuid) > 64) {
        throw new SaleApiException('معرّف السلة مطلوب وبحد أقصى ٦٤ خانة.');
    }
    $pdo->beginTransaction();
    try {
        $device = sale_device($pdo, (string) ($input['device_uuid'] ?? ''), $user, true);
        $session = sale_open_session($pdo, (int) $device['id'], true);
        if (!$session) {
            throw new SaleApiException('افتح وردية على الجهاز الأول.', 409, 'locked');
        }
        $existingDoc = sale_existing_by_client($pdo, (int) $device['id'], $clientUuid);
        if ($existingDoc) {
            if ($existingDoc['status'] === 'held') {
                $pdo->commit();
                return ['doc_id' => (int) $existingDoc['id'], 'status' => 'held'];
            }
            throw new SaleApiException('معرّف السلة مستخدم قبل كده.', 409, 'duplicate');
        }
        $resolvedLines = sale_resolve_lines($pdo, (array) ($input['lines'] ?? []));
        try {
            $calculation = sale_compute(
                $resolvedLines,
                $input['invoice_discount'] ?? 0
            );
        } catch (InvalidArgumentException $e) {
            throw new SaleApiException($e->getMessage());
        }
        $customerId = isset($input['customer_id']) && (int) $input['customer_id'] > 0
            ? (int) $input['customer_id']
            : null;
        $stmt = $pdo->prepare(
            "INSERT INTO sale_docs
                (doc_kind, status, branch_id, device_id, session_id, cashier_id,
                 customer_id, client_uuid, gross, line_discount, invoice_discount,
                 net, paid, change_due)
             VALUES ('sale','held',?,?,?,?,?,?,?,?,?,?,0,0)"
        );
        $stmt->execute([
            (int) $device['branch_id'],
            (int) $device['id'],
            (int) $session['id'],
            (int) $user['id'],
            $customerId,
            $clientUuid,
            $calculation['gross'],
            $calculation['line_discount'],
            $calculation['invoice_discount'],
            $calculation['net'],
        ]);
        $docId = (int) $pdo->lastInsertId();
        sale_events_insert($pdo, [[
            'branch_id' => (int) $device['branch_id'],
            'device_id' => (int) $device['id'],
            'session_id' => (int) $session['id'],
            'user_id' => (int) $user['id'],
            'doc_id' => $docId,
            'event_type' => 'held_created',
            'payload' => [
                'lines' => $calculation['lines'],
                'invoice_discount' => $input['invoice_discount'] ?? 0,
            ],
            'approved_by' => null,
        ]]);
        $pdo->commit();
        return ['doc_id' => $docId, 'status' => 'held'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062) {
            throw new SaleApiException('السلة دي اتعلقت قبل كده.', 409, 'duplicate');
        }
        throw $e;
    }
}

/** قائمة الفواتير المعلقة في فرع الجهاز كله. */
function sale_held_list(PDO $pdo, string $uuid, array $user): array
{
    $device = sale_device($pdo, $uuid, $user);
    $stmt = $pdo->prepare(
        "SELECT x.id, x.client_uuid, x.gross, x.line_discount,
                x.invoice_discount, x.net, x.customer_id, x.created_at,
                x.updated_at, u.full_name cashier_name,
                v.name device_name, v.code device_code
           FROM sale_docs x
           JOIN users u ON u.id=x.cashier_id
           JOIN sale_devices v ON v.id=x.device_id
          WHERE x.branch_id=? AND x.doc_kind='sale' AND x.status='held'
          ORDER BY x.updated_at DESC,x.id DESC"
    );
    $stmt->execute([(int) $device['branch_id']]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** استرجاع المعلقة وقفلها على الجهاز الحالي. */
function sale_held_resume(PDO $pdo, string $uuid, int $docId, array $user): array
{
    $pdo->beginTransaction();
    try {
        $device = sale_device($pdo, $uuid, $user, true);
        $session = sale_open_session($pdo, (int) $device['id'], true);
        if (!$session) {
            throw new SaleApiException('افتح وردية على الجهاز الأول.', 409, 'locked');
        }
        $docStmt = $pdo->prepare(
            "SELECT * FROM sale_docs WHERE id=? AND doc_kind='sale' FOR UPDATE"
        );
        $docStmt->execute([$docId]);
        $doc = $docStmt->fetch(PDO::FETCH_ASSOC);
        if (
            !$doc
            || $doc['status'] !== 'held'
            || (int) $doc['branch_id'] !== (int) $device['branch_id']
        ) {
            throw new SaleApiException(
                'الفاتورة المعلّقة مش موجودة في الفرع ده.',
                409,
                'locked'
            );
        }
        $lockStmt = $pdo->prepare(
            "SELECT device_id
               FROM sale_events
              WHERE doc_id=? AND event_type='held_resumed'
              ORDER BY id DESC LIMIT 1"
        );
        $lockStmt->execute([$docId]);
        $lockedDeviceId = $lockStmt->fetchColumn();
        if ($lockedDeviceId !== false && (int) $lockedDeviceId !== (int) $device['id']) {
            throw new SaleApiException(
                'الفاتورة المعلّقة مفتوحة على جهاز تاني.',
                409,
                'locked'
            );
        }
        $eventStmt = $pdo->prepare(
            "SELECT payload
               FROM sale_events
              WHERE doc_id=? AND event_type='held_created'
              ORDER BY id DESC LIMIT 1"
        );
        $eventStmt->execute([$docId]);
        $payload = json_decode((string) $eventStmt->fetchColumn(), true);
        if (!is_array($payload) || !isset($payload['lines'])) {
            throw new SaleApiException('بيانات السلة المعلّقة ناقصة.', 409, 'locked');
        }
        try {
            $pdo->prepare(
                'UPDATE sale_docs
                    SET device_id=?, session_id=?, cashier_id=?, updated_at=NOW()
                  WHERE id=?'
            )->execute([
                (int) $device['id'],
                (int) $session['id'],
                (int) $user['id'],
                $docId,
            ]);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new SaleApiException(
                    'معرّف السلة مستخدم على الجهاز ده.',
                    409,
                    'duplicate'
                );
            }
            throw $e;
        }
        sale_events_insert($pdo, [[
            'branch_id' => (int) $device['branch_id'],
            'device_id' => (int) $device['id'],
            'session_id' => (int) $session['id'],
            'user_id' => (int) $user['id'],
            'doc_id' => $docId,
            'event_type' => 'held_resumed',
            'payload' => ['from_device_id' => (int) $doc['device_id']],
            'approved_by' => null,
        ]]);
        $pdo->commit();
        return [
            'doc_id' => $docId,
            'client_uuid' => $doc['client_uuid'],
            'customer_id' => $doc['customer_id'] !== null ? (int) $doc['customer_id'] : null,
            'lines' => $payload['lines'],
            'invoice_discount' => $payload['invoice_discount'] ?? 0,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** إلغاء المعلقة بسبب، من غير مسح أي بيانات. */
function sale_held_cancel(
    PDO $pdo,
    string $uuid,
    int $docId,
    string $reason,
    array $user
): void {
    $reason = trim($reason);
    if ($reason === '') {
        throw new SaleApiException('سبب الإلغاء مطلوب.');
    }
    $pdo->beginTransaction();
    try {
        $device = sale_device($pdo, $uuid, $user, true);
        $stmt = $pdo->prepare(
            "SELECT * FROM sale_docs WHERE id=? AND doc_kind='sale' FOR UPDATE"
        );
        $stmt->execute([$docId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (
            !$doc
            || $doc['status'] !== 'held'
            || (int) $doc['branch_id'] !== (int) $device['branch_id']
        ) {
            throw new SaleApiException(
                'الفاتورة المعلّقة مش متاحة للإلغاء.',
                409,
                'locked'
            );
        }
        $pdo->prepare(
            "UPDATE sale_docs
                SET status='void', updated_at=NOW()
              WHERE id=? AND status='held'"
        )->execute([$docId]);
        $session = sale_open_session($pdo, (int) $device['id']);
        sale_events_insert($pdo, [[
            'branch_id' => (int) $device['branch_id'],
            'device_id' => (int) $device['id'],
            'session_id' => $session['id'] ?? null,
            'user_id' => (int) $user['id'],
            'doc_id' => $docId,
            'event_type' => 'held_cancelled',
            'payload' => ['reason' => mb_substr($reason, 0, 500)],
            'approved_by' => null,
        ]]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** التحقق من المشرف وإصدار توكن اعتماد خصم لمدة خمس دقايق. */
function sale_approve(PDO $pdo, array $input, array $user): array
{
    $device = sale_device($pdo, (string) ($input['device_uuid'] ?? ''), $user);
    $approvalType = trim((string) ($input['approval_type'] ?? ''));
    $value = (float) ($input['value'] ?? 0);
    $username = trim((string) ($input['username'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    if (
        $approvalType !== 'discount'
        || !is_finite($value)
        || $value < 0
        || $value > 100
    ) {
        throw new SaleApiException('نوع الاعتماد أو قيمته مش صحيح.');
    }
    $stmt = $pdo->prepare(
        "SELECT u.id, u.password_hash, u.is_active, r.is_owner,
                MAX(CASE WHEN up.module_key IN ('pos','pos:discount_override')
                         THEN 1 ELSE 0 END) has_perm
           FROM users u
           JOIN roles r ON r.id=u.role_id
           LEFT JOIN user_permissions up ON up.user_id=u.id
          WHERE u.username=?
          GROUP BY u.id,u.password_hash,u.is_active,r.is_owner"
    );
    $stmt->execute([$username]);
    $supervisor = $stmt->fetch(PDO::FETCH_ASSOC);
    $valid = $supervisor
        && !empty($supervisor['is_active'])
        && password_verify($password, (string) $supervisor['password_hash'])
        && (!empty($supervisor['is_owner']) || !empty($supervisor['has_perm']));
    $session = sale_open_session($pdo, (int) $device['id']);
    if (!$valid) {
        sale_events_insert($pdo, [[
            'branch_id' => (int) $device['branch_id'],
            'device_id' => (int) $device['id'],
            'session_id' => $session['id'] ?? null,
            'user_id' => (int) $user['id'],
            'doc_id' => null,
            'event_type' => 'approval_failed',
            'payload' => [
                'approval_type' => $approvalType,
                'value' => round($value, 3),
                'username' => $username,
            ],
            'approved_by' => null,
        ]]);
        throw new SaleApiException(
            'بيانات المشرف أو صلاحيته مش صحيحة.',
            403,
            'forbidden'
        );
    }

    $plainToken = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + 300);
    $pdo->prepare(
        'INSERT INTO sale_approval_tokens
            (token_hash, device_id, approval_type, approved_value, approved_by,
             requested_by, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        hash('sha256', $plainToken),
        (int) $device['id'],
        $approvalType,
        round($value, 3),
        (int) $supervisor['id'],
        (int) $user['id'],
        $expiresAt,
    ]);
    sale_events_insert($pdo, [[
        'branch_id' => (int) $device['branch_id'],
        'device_id' => (int) $device['id'],
        'session_id' => $session['id'] ?? null,
        'user_id' => (int) $user['id'],
        'doc_id' => null,
        'event_type' => 'approval_granted',
        'payload' => [
            'approval_type' => $approvalType,
            'value' => round($value, 3),
            'expires_at' => $expiresAt,
        ],
        'approved_by' => (int) $supervisor['id'],
    ]]);
    return [
        'approval_token' => $plainToken,
        'expires_at' => $expiresAt,
        'approved_by' => (int) $supervisor['id'],
    ];
}

/** فتح الدرج يدوياً بسبب وإشعار المالك. */
function sale_drawer_open(PDO $pdo, string $uuid, string $reason, array $user): void
{
    $reason = trim($reason);
    if ($reason === '') {
        throw new SaleApiException('سبب فتح الدرج مطلوب.');
    }
    $pdo->beginTransaction();
    try {
        $device = sale_device($pdo, $uuid, $user, true);
        $session = sale_open_session($pdo, (int) $device['id'], true);
        if (!$session) {
            throw new SaleApiException('افتح وردية على الجهاز الأول.', 409, 'locked');
        }
        sale_events_insert($pdo, [[
            'branch_id' => (int) $device['branch_id'],
            'device_id' => (int) $device['id'],
            'session_id' => (int) $session['id'],
            'user_id' => (int) $user['id'],
            'doc_id' => null,
            'event_type' => 'drawer_open',
            'payload' => ['reason' => mb_substr($reason, 0, 500)],
            'approved_by' => null,
        ]]);
        $owners = $pdo->query(
            'SELECT u.id
               FROM users u
               JOIN roles r ON r.id=u.role_id
              WHERE u.is_active=1 AND r.is_owner=1'
        )->fetchAll(PDO::FETCH_COLUMN);
        notify(
            $pdo,
            array_map('intval', $owners),
            'pos_drawer',
            'فتح درج من غير بيع',
            $user['full_name'] . ' فتح درج ' . $device['name'] . ' بسبب: '
                . mb_substr($reason, 0, 140),
            null
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** تسجيل حذف سطر أو تفريغ السلة وقت حدوثه. */
function sale_cart_event(PDO $pdo, array $input, array $user): void
{
    $device = sale_device($pdo, (string) ($input['device_uuid'] ?? ''), $user);
    $session = sale_open_session($pdo, (int) $device['id']);
    if (!$session) {
        throw new SaleApiException('افتح وردية على الجهاز الأول.', 409, 'locked');
    }
    $eventType = (string) ($input['event_type'] ?? '');
    if (!in_array($eventType, ['line_removed', 'cart_cleared'], true)) {
        throw new SaleApiException('نوع حدث السلة مش صحيح.');
    }
    if ($eventType === 'line_removed') {
        $payload = [
            'item_id' => (int) ($input['item_id'] ?? 0),
            'code' => trim((string) ($input['code'] ?? '')),
            'qty' => round((float) ($input['qty'] ?? 0), 3),
            'price' => round((float) ($input['price'] ?? 0), 2),
        ];
        if (
            $payload['item_id'] === 0
            || $payload['code'] === ''
            || $payload['qty'] <= 0
            || $payload['price'] < 0
        ) {
            throw new SaleApiException('بيانات الصنف الملغي ناقصة.');
        }
    } else {
        $payload = ['lines_count' => max(0, (int) ($input['lines_count'] ?? 0))];
    }
    sale_events_insert($pdo, [[
        'branch_id' => (int) $device['branch_id'],
        'device_id' => (int) $device['id'],
        'session_id' => (int) $session['id'],
        'user_id' => (int) $user['id'],
        'doc_id' => null,
        'event_type' => $eventType,
        'payload' => $payload,
        'approved_by' => null,
    ]]);
}
