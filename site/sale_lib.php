<?php
/** sale_lib.php — منطق نقطة البيع المشترك بين الـAPI والشاشات. */
declare(strict_types=1);

require_once __DIR__ . '/stock_lib.php';
require_once __DIR__ . '/item_master_lib.php';
require_once __DIR__ . '/push_lib.php';
require_once __DIR__ . '/cash_denominations_lib.php';

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

/** التحقق من وجود العميل وأنه نشط، بدون قيد فرع. */
function sale_require_customer(PDO $pdo, mixed $customerId): ?int
{
    if ($customerId === null || $customerId === '' || (int) $customerId <= 0) {
        return null;
    }
    $id = (int) $customerId;
    $stmt = $pdo->prepare('SELECT id, is_active FROM customers WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['is_active'])) {
        throw new SaleApiException('العميل ده مش موجود أو موقوف.', 422, 'bad_request');
    }
    return $id;
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
    $settings = ['pos_max_payments' => '3', 'pos_return_days' => '14', 'stock_allow_negative' => '1'];
    $settingRows = $pdo->query(
        "SELECT setting_key, setting_value
           FROM settings
          WHERE setting_key IN ('pos_max_payments','pos_return_days','stock_allow_negative')"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($settingRows as $settingRow) {
        $settings[$settingRow['setting_key']] = $settingRow['setting_value'];
    }
    return sale_hide_expected_fields([
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
            'pos_return_days' => max(0, (int) $settings['pos_return_days']), 'stock_allow_negative' => $settings['stock_allow_negative'] === '1',
        ],
        'ready' => sale_branch_ready((int) $device['branch_id']),
    ], $user);
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

/** هل المستخدم يشوف المتوقع والفرق وهو بيقفل الوردية؟ (بريف ٨٢٧c / ٨٢٧d) */
function sale_shift_expected_visible(array $user = []): bool
{
    if (function_exists('is_real_owner') && is_real_owner()) {
        return true;
    }
    $perms = $user['permissions'] ?? ($_SESSION['permissions'] ?? []);
    if (is_array($perms) && in_array('pos:shift_expected', $perms, true)) {
        return true;
    }
    return false;
}

// session_invoices: الكاشير محتاج قيم الفواتير للطباعة والمرتجع (قرار كلود ٣١/١٠).
function sale_hide_expected_fields(array $payload, array $user): array
{
    if (!sale_shift_expected_visible($user)) {
        unset($payload['expected'], $payload['session']['opening_cash'], $payload['opening_cash']);
    }
    return $payload;
}

/** ملخص أرقام وردية جهاز من المستندات المكتملة فقط — من غير فلترة صلاحية. */
function sale_session_summary_full(PDO $pdo, string $uuid, array $user, bool $lock = false): array
{
    $device = sale_device($pdo, $uuid, $user, $lock);
    $session = sale_open_session($pdo, (int) $device['id'], $lock);
    if (!$session) {
        throw new SaleApiException('مفيش وردية مفتوحة على الجهاز.', 409, 'locked');
    }

    $stmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN d.doc_kind = 'sale' THEN p.cash_amount ELSE 0 END), 0) sale_cash,
            COALESCE(SUM(CASE WHEN d.doc_kind = 'sale' THEN d.change_due ELSE 0 END), 0) sale_change,
            COALESCE(SUM(CASE WHEN d.doc_kind = 'return' THEN p.cash_amount ELSE 0 END), 0) return_cash,
            COALESCE(SUM(CASE WHEN d.doc_kind = 'sale' THEN p.visa_amount ELSE 0 END), 0) sale_visa,
            COALESCE(SUM(CASE WHEN d.doc_kind = 'return' THEN p.visa_amount ELSE 0 END), 0) return_visa,
            COALESCE(SUM(CASE WHEN d.doc_kind = 'sale' THEN p.instapay_amount ELSE 0 END), 0) sale_instapay,
            COALESCE(SUM(CASE WHEN d.doc_kind = 'return' THEN p.instapay_amount ELSE 0 END), 0) return_instapay
           FROM sale_docs d
           LEFT JOIN (
                SELECT doc_id,
                       SUM(CASE WHEN method = 'cash' THEN amount ELSE 0 END) cash_amount,
                       SUM(CASE WHEN method = 'visa' THEN amount ELSE 0 END) visa_amount,
                       SUM(CASE WHEN method = 'instapay' THEN amount ELSE 0 END) instapay_amount
                  FROM sale_payments
                 GROUP BY doc_id
           ) p ON p.doc_id = d.id
          WHERE d.session_id = ? AND d.status = 'completed'"
    );
    $stmt->execute([(int) $session['id']]);
    $totals = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $openingCash = round((float) $session['opening_cash'], 2);
    $expectedCash = round(
        $openingCash + (float) ($totals['sale_cash'] ?? 0)
        - (float) ($totals['sale_change'] ?? 0) - (float) ($totals['return_cash'] ?? 0),
        2
    );
    $expectedVisa = round((float) ($totals['sale_visa'] ?? 0) - (float) ($totals['return_visa'] ?? 0), 2);
    $expectedInstapay = round(
        (float) ($totals['sale_instapay'] ?? 0) - (float) ($totals['return_instapay'] ?? 0),
        2
    );

    $heldStmt = $pdo->prepare(
        "SELECT id, client_uuid, gross, net, created_at
           FROM sale_docs
          WHERE session_id = ? AND doc_kind = 'sale' AND status = 'held'
          ORDER BY created_at, id"
    );
    $heldStmt->execute([(int) $session['id']]);

    return [
        'device' => ['id' => (int) $device['id'], 'name' => $device['name'], 'code' => $device['code']],
        'session' => [
            'id' => (int) $session['id'],
            'business_date' => $session['business_date'],
            'opened_at' => $session['opened_at'],
            'opening_cash' => $openingCash,
        ],
        'expected' => [
            'cash' => $expectedCash,
            'cash_sales' => round($expectedCash - $openingCash, 2),
            'visa' => $expectedVisa,
            'instapay' => $expectedInstapay,
            'total' => round($expectedCash + $expectedVisa + $expectedInstapay, 2),
        ],
        'held_docs' => $heldStmt->fetchAll(PDO::FETCH_ASSOC),
        'denominations' => cashier_cash_denominations(),
    ];
}

/**
 * ملخص الوردية للواجهة — المتوقع والافتتاحي والفرق للي معاه pos:shift_expected بس.
 * الكاشير من غير الصلاحية بياخد الأعداد اللي هيكتبها هو بس.
 */
function sale_session_summary(PDO $pdo, string $uuid, array $user, bool $lock = false): array
{
    return sale_hide_expected_fields(sale_session_summary_full($pdo, $uuid, $user, $lock), $user);
}

/** تقفيل وردية الجهاز وإرسال صف shifts لدورة المراجعة الموجودة. */
function sale_session_close(PDO $pdo, string $uuid, array $input, array $user): array
{
    $clientUuid = trim((string) ($input['client_uuid'] ?? ''));
    if ($clientUuid === '' || mb_strlen($clientUuid) > 64) {
        throw new SaleApiException('معرّف عملية التقفيل مطلوب وبحد أقصى ٦٤ خانة.');
    }

    $modeStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'pos_close_expenses_mode'");
    $modeStmt->execute();
    $expensesMode = (string) ($modeStmt->fetchColumn() ?: 'fagala');
    if ($expensesMode !== 'fagala') {
        throw new SaleApiException('وضع مصروفات إشراقة محتاج اعتماد مسار القيود الأول.', 409, 'locked');
    }

    $cashCounts = (array) ($input['cash_counts'] ?? []);
    $allowedDenoms = cashier_cash_denominations();
    $cleanCash = [];
    $countedCash = 0.0;
    foreach ($allowedDenoms as $denom) {
        $key = (string) $denom;
        $count = max(0, (int) ($cashCounts[$key] ?? $cashCounts[(int) $denom] ?? 0));
        if ($count > 0) {
            $subtotal = round($denom * $count, 2);
            $cleanCash[] = ['denomination' => $denom, 'count' => $count, 'subtotal' => $subtotal];
            $countedCash = round($countedCash + $subtotal, 2);
        }
    }

    $paymentLines = [];
    $counted = ['visa' => 0.0, 'instapay' => 0.0];
    foreach ((array) ($input['payment_lines'] ?? []) as $idx => $line) {
        if (!is_array($line)) {
            continue;
        }
        $method = (string) ($line['method'] ?? '');
        $amount = round((float) ($line['amount'] ?? 0), 2);
        if (!isset($counted[$method]) || $amount <= 0) {
            continue;
        }
        $reference = trim((string) ($line['reference'] ?? ''));
        $lineNo = count($paymentLines) + 1;
        if ($lineNo > 255) {
            throw new SaleApiException('عدد سطور المطابقة أكبر من المسموح.');
        }
        $paymentLines[] = [
            'method' => $method,
            'line_no' => $lineNo,
            'reference' => $reference !== '' ? mb_substr($reference, 0, 100) : null,
            'amount' => $amount,
        ];
        $counted[$method] = round($counted[$method] + $amount, 2);
    }

    $pdo->beginTransaction();
    try {
        $summary = sale_session_summary_full($pdo, $uuid, $user, true);
        $sessionId = (int) $summary['session']['id'];
        $sessionStmt = $pdo->prepare('SELECT * FROM sale_sessions WHERE id = ? FOR UPDATE');
        $sessionStmt->execute([$sessionId]);
        $session = $sessionStmt->fetch(PDO::FETCH_ASSOC);
        if (!$session || $session['status'] !== 'open' || !empty($session['shift_id'])) {
            throw new SaleApiException('الوردية دي اتقفلت قبل كده.', 409, 'duplicate');
        }

        // بريف ٨٢٧c: مفيش تقفيل وفيه فاتورة معلّقة — نفس شرط held_list بالحرف.
        $heldCountStmt = $pdo->prepare(
            "SELECT COUNT(*)
               FROM sale_docs
              WHERE session_id = ? AND doc_kind = 'sale' AND status = 'held'"
        );
        $heldCountStmt->execute([$sessionId]);
        $heldCount = (int) $heldCountStmt->fetchColumn();
        if ($heldCount > 0) {
            throw new SaleApiException(
                'فيه ' . $heldCount . ' فاتورة معلّقة — استرجعها وكمّلها أو الغيها الأول.',
                409,
                'held_open'
            );
        }

        $openingCash = round((float) $summary['session']['opening_cash'], 2);
        $cashToDeposit = round($countedCash - $openingCash, 2);
        $systemTotal = round((float) $summary['expected']['total'] - $openingCash, 2);
        $collected = round($countedCash + $counted['visa'] + $counted['instapay'], 2);
        $difference = round($collected - (float) $summary['expected']['total'], 2);
        $device = $summary['device'];
        $shiftName = 'POS-' . $sessionId;

        $shiftStmt = $pdo->prepare(
            "INSERT INTO shifts
                (user_id, branch_id, business_date, shift_name, cash_total, visa_total,
                 wallet_total, instapay_total, system_total, difference, status, source, session_id)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, 'submitted', 'pos', ?)"
        );
        $shiftStmt->execute([
            (int) $session['cashier_id'],
            (int) $session['branch_id'],
            $session['business_date'],
            $shiftName,
            $cashToDeposit,
            $counted['visa'],
            $counted['instapay'],
            $systemTotal,
            $difference,
            $sessionId,
        ]);
        $shiftId = (int) $pdo->lastInsertId();

        if ($cleanCash) {
            $groups = [];
            $params = [];
            foreach ($cleanCash as $row) {
                $groups[] = '(?,?,?,?)';
                array_push($params, $shiftId, $row['denomination'], $row['count'], $row['subtotal']);
            }
            $pdo->prepare(
                'INSERT INTO shift_cash (shift_id, denomination, count, subtotal) VALUES ' . implode(',', $groups)
            )->execute($params);
        }
        if ($paymentLines) {
            $groups = [];
            $params = [];
            foreach ($paymentLines as $row) {
                $groups[] = '(?,?,?,?,?)';
                array_push($params, $shiftId, $row['method'], $row['line_no'], $row['reference'], $row['amount']);
            }
            $pdo->prepare(
                'INSERT INTO shift_payment_lines (shift_id, method, line_no, reference, amount) VALUES '
                . implode(',', $groups)
            )->execute($params);
        }

        $pdo->prepare(
            "UPDATE sale_sessions
                SET status = 'closed', closed_at = NOW(), shift_id = ?, close_client_uuid = ?
              WHERE id = ? AND status = 'open' AND shift_id IS NULL"
        )->execute([$shiftId, $clientUuid, $sessionId]);

        sale_events_insert($pdo, [[
            'branch_id' => (int) $session['branch_id'],
            'device_id' => (int) $device['id'],
            'session_id' => $sessionId,
            'user_id' => (int) $user['id'],
            'doc_id' => null,
            'event_type' => 'session_closed',
            'payload' => [
                'shift_id' => $shiftId,
                'opening_cash' => $openingCash,
                'cash_to_deposit' => $cashToDeposit,
                'expected' => $summary['expected'],
                'counted' => ['cash' => $countedCash] + $counted,
                'difference' => [
                    'cash' => round($countedCash - (float) $summary['expected']['cash'], 2),
                    'visa' => round($counted['visa'] - (float) $summary['expected']['visa'], 2),
                    'instapay' => round($counted['instapay'] - (float) $summary['expected']['instapay'], 2),
                    'total' => $difference,
                ],
                'held_count' => count($summary['held_docs']),
            ],
            'approved_by' => null,
        ]]);

        $pdo->commit();
        $response = [
            'shift_id' => $shiftId,
            'status' => 'submitted',
            'counted' => ['cash' => $countedCash] + $counted,
            'held_docs' => $summary['held_docs'],
        ];
        // المتوقع والافتتاحي والفرق للي معاه pos:shift_expected بس (بريف ٨٢٧c).
        if (sale_shift_expected_visible($user)) {
            $response['opening_cash'] = $openingCash;
            $response['cash_to_deposit'] = $cashToDeposit;
            $response['expected'] = $summary['expected'];
            $response['difference'] = [
                'cash' => round($countedCash - (float) $summary['expected']['cash'], 2),
                'visa' => round($counted['visa'] - (float) $summary['expected']['visa'], 2),
                'instapay' => round($counted['instapay'] - (float) $summary['expected']['instapay'], 2),
                'total' => $difference,
            ];
        }
        return $response;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062) {
            throw new SaleApiException('الوردية دي اتقفلت قبل كده.', 409, 'duplicate');
        }
        throw $e;
    }
}

/** كل كلمات الاسم بأي ترتيب، وبحد أقصى ٢٠ صنف. */
function sale_lookup(PDO $pdo, int $branchId, string $code, string $q, string $mode = 'middle', bool $modeExplicit = false): array
{
    $code = trim($code);
    $q = trim($q); $mode = in_array($mode, ['start', 'middle', 'end'], true) ? $mode : 'middle';
    if ($code === '' && $q === '') {
        throw new SaleApiException('اكتب كود أو اسم الصنف.');
    }
    if ($code === '0' && $q === '') {
        return [];
    }

    $words = im_words($q);
    $trueNameParts = [];
    $siteNameParts = [];
    foreach ($words as $wordIdx => $word) {
        $trueNameParts[] = "CONCAT_WS(' ',c.name,ic.item_name) LIKE ?";
        $siteNameParts[] = "CONCAT_WS(' ',m.name1,mc.name1,mc.name2) LIKE ?";
    }
    $trueWhere = $code !== '' ? ($modeExplicit ? 'c.code LIKE ?' : 'c.code = ?') : '0';
    $siteWhere = $code !== '' ? ($modeExplicit ? 'mc.code LIKE ?' : 'mc.code = ?') : '0';
    if ($trueNameParts) {
        $trueWhere .= ' OR (' . implode(' AND ', $trueNameParts) . ')';
        $siteWhere .= ' OR (' . implode(' AND ', $siteNameParts) . ')';
    }
    $trueParams = [$code, $q];
    $siteParams = [$code, $q];
    if ($code !== '') {
        $trueParams[] = $siteParams[] = $modeExplicit ? ($mode === 'start' ? $code . '%' : ($mode === 'end' ? '%' . $code : '%' . $code . '%')) : $code;
    }
    foreach ($words as $wordIdx => $word) {
        $pattern = $mode === 'start' && $wordIdx === 0 ? $word . '%'
            : ($mode === 'end' && $wordIdx === count($words) - 1 ? '%' . $word : '%' . $word . '%');
        $trueParams[] = $siteParams[] = $pattern;
    }

    $sql = "SELECT source, source_id, item_id, item_name, exact_code
              FROM (
                    SELECT 'true' source, c.item_id source_id, c.item_id item_id,
                           COALESCE(MAX(ic.item_name),MAX(c.name),'') item_name,
                           MAX(c.code=?) exact_code, COALESCE(MAX(ic.item_name),MAX(c.name),'') = ? exact_name
                      FROM hq_codes c
                      LEFT JOIN hq_item_cats ic ON ic.item_id=c.item_id
                     WHERE c.code != '0' AND ($trueWhere)
                     GROUP BY c.item_id
                    UNION ALL
                    SELECT 'site', m.id, -m.id, m.name1, MAX(mc.code=?), m.name1 = ?
                      FROM item_master m
                      JOIN item_master_codes mc ON mc.item_master_id=m.id
                     WHERE m.is_cancelled=0 AND mc.is_sale_code=1 AND mc.code != '0' AND ($siteWhere)
                     GROUP BY m.id,m.name1
                   ) x
             ORDER BY exact_code DESC,exact_name DESC,item_name,source
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
                         WHERE c.item_id IN ($placeholders) AND c.code != '0'";
        array_push($params, $branchId, ...$trueItemIds);
    }
    if ($siteItemIds) {
        $placeholders = implode(',', array_fill(0, count($siteItemIds), '?'));
        $queryParts[] = "SELECT 'site', m.id, mc.code, COALESCE(mc.name1,m.name1),
                               mc.unit_name, mc.unit_factor, ip.price, COALESCE(sb.qty,0)
                          FROM item_master m
                          JOIN item_master_codes mc
                            ON mc.item_master_id=m.id AND mc.is_sale_code=1 AND mc.code != '0'
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
        if ($code === '0') {
            throw new SaleApiException('الكود 0 «فتح درج» مش بيتباع هنا — استخدم زرار «فتح الدرج» واكتب السبب.', 422, 'drawer_code');
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
        $customerId = sale_require_customer($pdo, $input['customer_id'] ?? null);

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
        $customerId = sale_require_customer($pdo, $input['customer_id'] ?? null);
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

/** التحقق من المشرف وإصدار توكن اعتماد خصم أو مرتجع لمدة خمس دقايق. */
function sale_approve(PDO $pdo, array $input, array $user): array
{
    $device = sale_device($pdo, (string) ($input['device_uuid'] ?? ''), $user);
    $approvalType = trim((string) ($input['approval_type'] ?? ''));
    $value = (float) ($input['value'] ?? 0);
    $username = trim((string) ($input['username'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    $refDocId = (int) ($input['doc_id'] ?? 0);

    $allowedTypes = ['discount', 'return_late', 'return_method'];
    if (!in_array($approvalType, $allowedTypes, true) || !is_finite($value) || $value < 0) {
        throw new SaleApiException('نوع الاعتماد أو قيمته مش صحيح.');
    }
    if ($approvalType === 'discount' && $value > 100) {
        throw new SaleApiException('نوع الاعتماد أو قيمته مش صحيح.');
    }
    if ($approvalType !== 'discount') {
        if ($refDocId <= 0 || $value <= 0) {
            throw new SaleApiException('الفاتورة الأصلية ومبلغ الرد مطلوبين للاعتماد.');
        }
        $docStmt = $pdo->prepare(
            "SELECT id
               FROM sale_docs
              WHERE id = ? AND branch_id = ? AND doc_kind = 'sale' AND status = 'completed'"
        );
        $docStmt->execute([$refDocId, (int) $device['branch_id']]);
        if (!$docStmt->fetchColumn()) {
            throw new SaleApiException('الفاتورة الأصلية مش موجودة في فرع الجهاز.', 403, 'forbidden');
        }
    } else {
        $refDocId = 0;
    }

    $stmt = $pdo->prepare(
        "SELECT u.id, u.password_hash, u.is_active, r.is_owner,
                MAX(CASE WHEN up.module_key IN ('pos','pos:discount_override')
                         THEN 1 ELSE 0 END) can_discount,
                MAX(CASE WHEN up.module_key IN ('pos','pos:return_general')
                         THEN 1 ELSE 0 END) can_late,
                MAX(CASE WHEN up.module_key IN ('pos','pos:return_method_override')
                         THEN 1 ELSE 0 END) can_method
           FROM users u
           JOIN roles r ON r.id=u.role_id
           LEFT JOIN user_permissions up ON up.user_id=u.id
          WHERE u.username=?
          GROUP BY u.id,u.password_hash,u.is_active,r.is_owner"
    );
    $stmt->execute([$username]);
    $supervisor = $stmt->fetch(PDO::FETCH_ASSOC);

    $hasPerm = false;
    if ($supervisor) {
        if (!empty($supervisor['is_owner'])) {
            $hasPerm = true;
        } elseif ($approvalType === 'discount' && !empty($supervisor['can_discount'])) {
            $hasPerm = true;
        } elseif ($approvalType === 'return_late' && !empty($supervisor['can_late'])) {
            $hasPerm = true;
        } elseif ($approvalType === 'return_method' && !empty($supervisor['can_method'])) {
            $hasPerm = true;
        }
    }

    $valid = $supervisor
        && !empty($supervisor['is_active'])
        && password_verify($password, (string) $supervisor['password_hash'])
        && $hasPerm;
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
            (token_hash, device_id, approval_type, approved_value, ref_doc_id, approved_by,
             requested_by, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        hash('sha256', $plainToken),
        (int) $device['id'],
        $approvalType,
        round($value, 3),
        $refDocId > 0 ? $refDocId : null,
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
            'ref_doc_id' => $refDocId > 0 ? $refDocId : null,
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
            'name' => mb_substr(trim((string) ($input['name'] ?? '')), 0, 255),
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
        $cleanLines = [];
        foreach (array_slice((array) ($input['lines'] ?? []), 0, 200) as $line) {
            if (!is_array($line)) {
                continue;
            }
            $itemId = (int) ($line['item_id'] ?? 0);
            $code = trim((string) ($line['code'] ?? ''));
            $qty = round((float) ($line['qty'] ?? 0), 3);
            $price = round((float) ($line['price'] ?? 0), 2);
            if ($itemId === 0 || $code === '' || $qty <= 0 || $price < 0) {
                continue;
            }
            $cleanLines[] = [
                'item_id' => $itemId,
                'code' => mb_substr($code, 0, 64),
                'name' => mb_substr(trim((string) ($line['name'] ?? '')), 0, 255),
                'qty' => $qty,
                'price' => $price,
            ];
        }
        $payload = [
            'lines_count' => max(0, (int) ($input['lines_count'] ?? count($cleanLines))),
            'lines' => $cleanLines,
        ];
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

/** البحث عن توكن اعتماد نشط للجهاز ونوعه وقفله بـ FOR UPDATE. */
function sale_lookup_token(PDO $pdo, int $deviceId, string $type, string $token): ?array
{
    $token = trim($token);
    if ($token === '') {
        return null;
    }
    $stmt = $pdo->prepare(
        "SELECT id, approved_by, approved_value, ref_doc_id
           FROM sale_approval_tokens
          WHERE token_hash = ? AND device_id = ? AND approval_type = ?
            AND used_at IS NULL AND expires_at >= NOW()
          LIMIT 1 FOR UPDATE"
    );
    $stmt->execute([hash('sha256', $token), $deviceId, $type]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** اعتماد المرتجع لازم يخص نفس الفاتورة ويغطي مبلغ الرد الفعلي لحد فرق قرش. */
function sale_require_return_approval(array $approval, int $origDocId, float $actualValue): void
{
    if (
        (int) ($approval['ref_doc_id'] ?? 0) !== $origDocId
        || $actualValue - (float) ($approval['approved_value'] ?? 0) > 0.01001
    ) {
        throw new SaleApiException('اعتماد المشرف مش خاص بالفاتورة أو مبلغ المرتجع ده.', 403, 'forbidden');
    }
}

/** تعليم توكن الاعتماد كمستهلك. */
function sale_consume_token(PDO $pdo, int $tokenId, int $docId): void
{
    $pdo->prepare(
        'UPDATE sale_approval_tokens
            SET used_at = NOW(), used_doc_id = ?
          WHERE id = ? AND used_at IS NULL'
    )->execute([$docId, $tokenId]);
}

/** البحث عن فاتورة بيع للمرتجع وحساب المتاح والمردود وطرق الدفع. */
function sale_doc_find(PDO $pdo, array $input, array $user): array
{
    $deviceUuid = (string) ($input['device_uuid'] ?? '');
    $device = sale_device($pdo, $deviceUuid, $user);

    $docId = (int) ($input['doc_id'] ?? 0);
    $receiptNo = trim((string) ($input['receipt_no'] ?? ($input['q'] ?? '')));

    if ($docId <= 0 && $receiptNo === '') {
        throw new SaleApiException('اكتب رقم الإيصال أو رقم الفاتورة.');
    }

    if ($docId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM sale_docs WHERE id = ?');
        $stmt->execute([$docId]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM sale_docs WHERE receipt_no = ? OR id = ?');
        $stmt->execute([$receiptNo, is_numeric($receiptNo) ? (int) $receiptNo : 0]);
    }
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        throw new SaleApiException('الفاتورة مش موجودة.', 404, 'not_found');
    }
    if ($doc['doc_kind'] !== 'sale' || $doc['status'] !== 'completed') {
        throw new SaleApiException('الفاتورة مش فاتورة بيع مكتملة.', 409, 'locked');
    }
    if ((int) $doc['branch_id'] !== (int) $device['branch_id']) {
        throw new SaleApiException('الفاتورة دي من فرع تاني — المرتجع من نفس الفرع بس.', 409, 'locked');
    }

    $completedAt = new DateTime($doc['completed_at']);
    $now = new DateTime();
    $diffDays = (int) $now->diff($completedAt)->days;

    $posReturnDays = 14;
    $daysSetting = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'pos_return_days'")->fetchColumn();
    if ($daysSetting !== false && is_numeric($daysSetting)) {
        $posReturnDays = max(0, (int) $daysSetting);
    }
    $isLate = $diffDays > $posReturnDays;

    $cashierName = (string) ($pdo->query('SELECT full_name FROM users WHERE id = ' . (int) $doc['cashier_id'])->fetchColumn() ?: '');
    $customerName = '';
    if (!empty($doc['customer_id'])) {
        $customerName = (string) ($pdo->query('SELECT name FROM customers WHERE id = ' . (int) $doc['customer_id'])->fetchColumn() ?: '');
    }

    $linesStmt = $pdo->prepare(
        "SELECT sl.id, sl.doc_id, sl.line_no, sl.item_id, sl.code, sl.unit_name,
                sl.unit_factor, sl.qty, sl.qty_pieces, sl.unit_price, sl.line_discount,
                sl.net, sl.unit_cost,
                COALESCE((SELECT name FROM hq_codes WHERE code = sl.code LIMIT 1),
                         (SELECT name1 FROM item_master_codes WHERE code = sl.code LIMIT 1),
                         sl.code) AS item_name,
                COALESCE(
                    (SELECT SUM(rl.qty)
                       FROM sale_lines rl
                       JOIN sale_docs rd ON rd.id = rl.doc_id
                      WHERE rl.ref_line_id = sl.id AND rd.doc_kind = 'return' AND rd.status = 'completed'),
                    0
                ) AS returned_qty,
                COALESCE(
                    (SELECT SUM(rl.net)
                       FROM sale_lines rl
                       JOIN sale_docs rd ON rd.id = rl.doc_id
                      WHERE rl.ref_line_id = sl.id AND rd.doc_kind = 'return' AND rd.status = 'completed'),
                    0
                ) AS returned_net
           FROM sale_lines sl
          WHERE sl.doc_id = ?
          ORDER BY sl.line_no"
    );
    $linesStmt->execute([(int) $doc['id']]);
    $rawLines = $linesStmt->fetchAll(PDO::FETCH_ASSOC);

    $afterLineDiscountsTotal = round((float) $doc['gross'] - (float) $doc['line_discount'], 2);
    $invDiscTotal = (float) $doc['invoice_discount'];

    $linesData = [];
    $accumInvDisc = 0.0;
    $totalCount = count($rawLines);

    foreach ($rawLines as $idx => $line) {
        $lineNet = (float) $line['net'];
        $lineQty = (float) $line['qty'];
        $retQty = (float) $line['returned_qty'];
        $retNet = (float) $line['returned_net'];
        $availQty = max(0.0, round($lineQty - $retQty, 3));

        if ($idx === $totalCount - 1) {
            $lineInvDisc = round($invDiscTotal - $accumInvDisc, 2);
        } elseif ($afterLineDiscountsTotal > 0) {
            $lineInvDisc = round($invDiscTotal * ($lineNet / $afterLineDiscountsTotal), 2);
            $accumInvDisc = round($accumInvDisc + $lineInvDisc, 2);
        } else {
            $lineInvDisc = 0.0;
        }

        $finalLineNet = round($lineNet - $lineInvDisc, 2);
        $unitNet = $lineQty > 0 ? round($finalLineNet / $lineQty, 4) : 0.0;

        $linesData[] = [
            'id' => (int) $line['id'],
            'line_no' => (int) $line['line_no'],
            'item_id' => (int) $line['item_id'],
            'item_name' => $line['item_name'],
            'code' => $line['code'],
            'unit_name' => $line['unit_name'],
            'unit_factor' => (float) $line['unit_factor'],
            'qty' => $lineQty,
            'unit_price' => (float) $line['unit_price'],
            'line_discount' => (float) $line['line_discount'],
            'invoice_discount_share' => $lineInvDisc,
            'final_line_net' => $finalLineNet,
            'unit_net' => $unitNet,
            'returned_qty' => $retQty,
            'returned_net' => $retNet,
            'available_qty' => $availQty,
            'unit_cost' => $line['unit_cost'] !== null ? (float) $line['unit_cost'] : null,
        ];
    }

    $origPaymentsStmt = $pdo->prepare(
        'SELECT method, SUM(amount) AS amount
           FROM sale_payments
          WHERE doc_id = ?
          GROUP BY method'
    );
    $origPaymentsStmt->execute([(int) $doc['id']]);
    $origPayments = $origPaymentsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $refPaymentsStmt = $pdo->prepare(
        "SELECT rp.method, SUM(rp.amount) AS amount
           FROM sale_payments rp
           JOIN sale_docs rd ON rd.id = rp.doc_id
          WHERE rd.ref_doc_id = ? AND rd.doc_kind = 'return' AND rd.status = 'completed'
          GROUP BY rp.method"
    );
    $refPaymentsStmt->execute([(int) $doc['id']]);
    $refPayments = $refPaymentsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $paymentsData = [];
    foreach (['cash', 'visa', 'instapay'] as $m) {
        $orig = (float) ($origPayments[$m] ?? 0);
        $refunded = (float) ($refPayments[$m] ?? 0);
        $avail = max(0.0, round($orig - $refunded, 2));
        if ($orig > 0 || $refunded > 0) {
            $paymentsData[$m] = [
                'method' => $m,
                'original_amount' => $orig,
                'refunded_amount' => $refunded,
                'available_refund' => $avail,
            ];
        }
    }

    return [
        'doc' => [
            'id' => (int) $doc['id'],
            'receipt_no' => (string) $doc['receipt_no'],
            'completed_at' => $doc['completed_at'],
            'days_since' => $diffDays,
            'is_late' => $isLate,
            'pos_return_days' => $posReturnDays,
            'gross' => (float) $doc['gross'],
            'line_discount' => (float) $doc['line_discount'],
            'invoice_discount' => (float) $doc['invoice_discount'],
            'net' => (float) $doc['net'],
            'paid' => (float) $doc['paid'],
            'customer_id' => $doc['customer_id'] !== null ? (int) $doc['customer_id'] : null,
            'customer_name' => $customerName,
            'cashier_name' => $cashierName,
        ],
        'lines' => $linesData,
        'payments' => $paymentsData,
    ];
}

/**
 * حساب صافي مرتجع السطور وتوزيع الخصومات بدقة القرش.
 * @return array{lines: array, total_gross: float, total_net: float, is_full_return: bool}
 */
function sale_calculate_return_lines(
    array $origDoc,
    array $origLinesById,
    array $prevReturnsByLineId,
    array $requestedLines
): array {
    if (!$requestedLines) {
        throw new SaleApiException('لازم تختار صنف واحد على الأقل للمرتجع.');
    }

    $afterLineDiscountsTotal = round((float) $origDoc['gross'] - (float) $origDoc['line_discount'], 2);
    $invDiscTotal = (float) $origDoc['invoice_discount'];
    $totalCount = count($origLinesById);

    $origLinesFinalNet = [];
    $accumInvDisc = 0.0;
    $idx = 0;
    foreach ($origLinesById as $lineId => $origLine) {
        $lineNet = (float) $origLine['net'];
        if ($idx === $totalCount - 1) {
            $lineInvDisc = round($invDiscTotal - $accumInvDisc, 2);
        } elseif ($afterLineDiscountsTotal > 0) {
            $lineInvDisc = round($invDiscTotal * ($lineNet / $afterLineDiscountsTotal), 2);
            $accumInvDisc = round($accumInvDisc + $lineInvDisc, 2);
        } else {
            $lineInvDisc = 0.0;
        }
        $origLinesFinalNet[$lineId] = round($lineNet - $lineInvDisc, 2);
        $idx++;
    }

    $returnLines = [];
    $totalGross = 0.0;
    $totalNet = 0.0;
    $requestedByRef = [];

    foreach ($requestedLines as $req) {
        $refLineId = (int) ($req['ref_line_id'] ?? 0);
        $qty = round((float) ($req['qty'] ?? 0), 3);
        if ($refLineId <= 0 || !isset($origLinesById[$refLineId])) {
            throw new SaleApiException('السطر المرتجع مش موجود في الفاتورة الأصلية.');
        }
        if ($qty <= 0) {
            continue;
        }
        if (isset($requestedByRef[$refLineId])) {
            throw new SaleApiException('السطر مكرر في طلب المرتجع.');
        }
        $requestedByRef[$refLineId] = $qty;

        $origLine = $origLinesById[$refLineId];
        $origQty = (float) $origLine['qty'];
        $prevQty = (float) ($prevReturnsByLineId[$refLineId]['qty'] ?? 0);
        $prevNet = (float) ($prevReturnsByLineId[$refLineId]['net'] ?? 0);
        $availQty = round($origQty - $prevQty, 3);

        if ($qty > $availQty + 0.0001) {
            throw new SaleApiException(
                'الكمية المرتجعة أكبر من المتاح للصنف ' . $origLine['code'] . '.',
                409,
                'locked'
            );
        }

        $finalLineNet = $origLinesFinalNet[$refLineId];
        $isFinalPiece = abs(($prevQty + $qty) - $origQty) < 0.0001;

        if ($isFinalPiece) {
            $lineNetRefund = round($finalLineNet - $prevNet, 2);
        } else {
            $unitNetRefund = $finalLineNet / $origQty;
            $lineNetRefund = round($qty * $unitNetRefund, 2);
        }

        $lineGross = round($qty * (float) $origLine['unit_price'], 2);
        $lineDiscount = round($lineGross - $lineNetRefund, 2);

        $returnLines[] = [
            'ref_line_id' => $refLineId,
            'item_id' => (int) $origLine['item_id'],
            'code' => $origLine['code'],
            'unit_name' => $origLine['unit_name'],
            'unit_factor' => (float) $origLine['unit_factor'],
            'qty' => $qty,
            'qty_pieces' => round($qty * (float) $origLine['unit_factor'], 3),
            'unit_price' => (float) $origLine['unit_price'],
            'gross' => $lineGross,
            'line_discount' => $lineDiscount,
            'net' => $lineNetRefund,
            'unit_cost' => $origLine['unit_cost'] !== null ? (float) $origLine['unit_cost'] : null,
        ];

        $totalGross = round($totalGross + $lineGross, 2);
        $totalNet = round($totalNet + $lineNetRefund, 2);
    }

    if (!$returnLines) {
        throw new SaleApiException('لازم تحدد كمية أكبر من صفر لصنف واحد على الأقل.');
    }

    $isFullReturn = true;
    foreach ($origLinesById as $lid => $oline) {
        $totalSoFar = (float) ($prevReturnsByLineId[$lid]['qty'] ?? 0) + (float) ($requestedByRef[$lid] ?? 0);
        if (abs($totalSoFar - (float) $oline['qty']) > 0.0001) {
            $isFullReturn = false;
            break;
        }
    }

    if ($isFullReturn) {
        $prevTotalRefundedNet = 0.0;
        foreach ($prevReturnsByLineId as $pr) {
            $prevTotalRefundedNet = round($prevTotalRefundedNet + (float) ($pr['net'] ?? 0), 2);
        }
        $remainingExpectedNet = round((float) $origDoc['net'] - $prevTotalRefundedNet, 2);
        if (abs($totalNet - $remainingExpectedNet) > 0.0001) {
            $diff = round($remainingExpectedNet - $totalNet, 2);
            $lastIdx = count($returnLines) - 1;
            $returnLines[$lastIdx]['net'] = round($returnLines[$lastIdx]['net'] + $diff, 2);
            $returnLines[$lastIdx]['line_discount'] = round($returnLines[$lastIdx]['gross'] - $returnLines[$lastIdx]['net'], 2);
            $totalNet = $remainingExpectedNet;
        }
    }

    return [
        'lines' => $returnLines,
        'total_gross' => $totalGross,
        'total_net' => $totalNet,
        'is_full_return' => $isFullReturn,
    ];
}

/**
 * التحقق من طرق ومبالغ رد المرتجع وما إذا كانت تتطلب اعتماد مشرف.
 * @return array{payments: array, sum: float, override_needed: bool}
 */
function sale_validate_return_payments(
    array $origPayments,
    array $prevRefunds,
    array $inputPayments,
    float $totalReturnNet
): array {
    if (!$inputPayments) {
        throw new SaleApiException('لازم تحدد طريقة رد المرتجع.');
    }

    $paymentSum = 0.0;
    $methodOverrideNeeded = false;
    $cleanPayments = [];

    foreach ($inputPayments as $p) {
        $m = (string) ($p['method'] ?? '');
        $amt = round((float) ($p['amount'] ?? 0), 2);
        if (!in_array($m, ['cash', 'visa', 'instapay'], true)) {
            throw new SaleApiException('طريقة الرد مش مدعومة.');
        }
        if ($amt <= 0) {
            throw new SaleApiException('قيمة الرد لازم تبقى أكبر من صفر.');
        }
        $origPaidForMethod = (float) ($origPayments[$m] ?? 0);
        $prevRefundedForMethod = (float) ($prevRefunds[$m] ?? 0);
        $availForMethod = max(0.0, round($origPaidForMethod - $prevRefundedForMethod, 2));

        if ($amt > $availForMethod + 0.0001) {
            $methodOverrideNeeded = true;
        }

        $paymentSum = round($paymentSum + $amt, 2);
        $cleanPayments[] = [
            'method' => $m,
            'amount' => $amt,
            'reference' => trim((string) ($p['reference'] ?? '')) ?: null,
        ];
    }

    if (abs($paymentSum - $totalReturnNet) > 0.0001) {
        throw new SaleApiException('مجموع مبالغ الرد لازم يساوي صافي المرتجع بالظبط (' . $totalReturnNet . ' ج.م).');
    }

    return [
        'payments' => $cleanPayments,
        'sum' => $paymentSum,
        'override_needed' => $methodOverrideNeeded,
    ];
}

/** تنفيذ مرتجع على فاتورة بيع مكتملة في transaction واحدة. */
function sale_return_ref(PDO $pdo, array $input, array $user): array
{
    $clientUuid = trim((string) ($input['client_uuid'] ?? ''));
    if ($clientUuid === '' || mb_strlen($clientUuid) > 64) {
        throw new SaleApiException('معرّف عملية المرتجع مطلوب وبحد أقصى ٦٤ خانة.');
    }

    $pdo->beginTransaction();
    try {
        $device = sale_device($pdo, (string) ($input['device_uuid'] ?? ''), $user, true);
        $deviceId = (int) $device['id'];

        $existingDoc = sale_existing_by_client($pdo, $deviceId, $clientUuid);
        if ($existingDoc && $existingDoc['status'] === 'completed') {
            $res = sale_doc_result($existingDoc);
            $pdo->commit();
            return $res;
        }
        if ($existingDoc) {
            throw new SaleApiException('معرّف العملية مستخدم قبل كده.', 409, 'duplicate');
        }

        $session = sale_open_session($pdo, $deviceId, true);
        if (!$session) {
            throw new SaleApiException('افتح وردية على الجهاز الأول.', 409, 'locked');
        }
        if (!sale_branch_ready((int) $device['branch_id'])) {
            throw new SaleApiException('الفرع لسه على True — المرتجع من نقطة البيع الجديدة مقفول.', 409, 'locked');
        }

        $origDocId = (int) ($input['doc_id'] ?? 0);
        if ($origDocId <= 0) {
            throw new SaleApiException('معرّف الفاتورة الأصلية مطلوب.');
        }

        $origStmt = $pdo->prepare("SELECT * FROM sale_docs WHERE id = ? AND doc_kind = 'sale' FOR UPDATE");
        $origStmt->execute([$origDocId]);
        $origDoc = $origStmt->fetch(PDO::FETCH_ASSOC);
        if (!$origDoc || $origDoc['status'] !== 'completed') {
            throw new SaleApiException('الفاتورة الأصلية مش موجودة أو مش مكتملة.', 409, 'locked');
        }
        if ((int) $origDoc['branch_id'] !== (int) $device['branch_id']) {
            throw new SaleApiException('الفاتورة دي من فرع تاني — المرتجع من نفس الفرع بس.', 409, 'locked');
        }

        $completedAt = new DateTime($origDoc['completed_at']);
        $diffDays = (int) (new DateTime())->diff($completedAt)->days;
        $posReturnDays = 14;
        $daysSetting = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'pos_return_days'")->fetchColumn();
        if ($daysSetting !== false && is_numeric($daysSetting)) {
            $posReturnDays = max(0, (int) $daysSetting);
        }

        $lateApproval = null;
        if ($diffDays > $posReturnDays) {
            $tokenStr = (string) ($input['approval_token'] ?? ($input['late_approval_token'] ?? ''));
            $lateApproval = sale_lookup_token($pdo, $deviceId, 'return_late', $tokenStr);
            if (!$lateApproval) {
                throw new SaleApiException(
                    'الفاتورة أقدم من ' . $posReturnDays . ' يوم — محتاجة اعتماد مشرف.',
                    409,
                    'locked'
                );
            }
        }

        $origLinesStmt = $pdo->prepare('SELECT * FROM sale_lines WHERE doc_id = ? ORDER BY line_no FOR UPDATE');
        $origLinesStmt->execute([$origDocId]);
        $origLinesById = [];
        foreach ($origLinesStmt->fetchAll(PDO::FETCH_ASSOC) as $ol) {
            $origLinesById[(int) $ol['id']] = $ol;
        }

        $prevReturnsStmt = $pdo->prepare(
            "SELECT rl.ref_line_id, SUM(rl.qty) AS qty, SUM(rl.net) AS net
               FROM sale_lines rl
               JOIN sale_docs rd ON rd.id = rl.doc_id
              WHERE rd.ref_doc_id = ? AND rd.doc_kind = 'return' AND rd.status = 'completed'
              GROUP BY rl.ref_line_id
                FOR UPDATE"
        );
        $prevReturnsStmt->execute([$origDocId]);
        $prevReturnsByLineId = [];
        foreach ($prevReturnsStmt->fetchAll(PDO::FETCH_ASSOC) as $pr) {
            $prevReturnsByLineId[(int) $pr['ref_line_id']] = $pr;
        }

        $calc = sale_calculate_return_lines($origDoc, $origLinesById, $prevReturnsByLineId, (array) ($input['lines'] ?? []));
        $returnLines = $calc['lines'];
        $totalReturnNet = $calc['total_net'];
        $totalReturnGross = $calc['total_gross'];
        if ($lateApproval) {
            sale_require_return_approval($lateApproval, $origDocId, $totalReturnNet);
        }

        $origPayStmt = $pdo->prepare('SELECT method, SUM(amount) AS amount FROM sale_payments WHERE doc_id = ? GROUP BY method');
        $origPayStmt->execute([$origDocId]);
        $origPayments = $origPayStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $prevRefundsStmt = $pdo->prepare(
            "SELECT rp.method, SUM(rp.amount) AS amount
               FROM sale_payments rp
               JOIN sale_docs rd ON rd.id = rp.doc_id
              WHERE rd.ref_doc_id = ? AND rd.doc_kind = 'return' AND rd.status = 'completed'
              GROUP BY rp.method
                FOR UPDATE"
        );
        $prevRefundsStmt->execute([$origDocId]);
        $prevRefunds = $prevRefundsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $payValidation = sale_validate_return_payments(
            $origPayments,
            $prevRefunds,
            (array) ($input['payments'] ?? []),
            $totalReturnNet
        );
        $cleanPayments = $payValidation['payments'];
        $methodOverrideNeeded = $payValidation['override_needed'];

        $methodApproval = null;
        $methodOverrideReason = trim((string) ($input['method_override_reason'] ?? ($input['reason'] ?? '')));
        if ($methodOverrideNeeded) {
            $tokenStr = (string) ($input['approval_token'] ?? ($input['method_approval_token'] ?? ''));
            $methodApproval = sale_lookup_token($pdo, $deviceId, 'return_method', $tokenStr);
            if (!$methodApproval) {
                throw new SaleApiException(
                    'طريقة الرد مختلفة عن الفاتورة الأصلية — محتاجة اعتماد مشرف.',
                    403,
                    'forbidden'
                );
            }
            sale_require_return_approval($methodApproval, $origDocId, $totalReturnNet);
        }

        $receiptNo = sale_next_receipt($pdo, $device);
        $customerId = sale_require_customer($pdo, $input['customer_id'] ?? $origDoc['customer_id']);

        $docStmt = $pdo->prepare(
            "INSERT INTO sale_docs
                (doc_kind, status, branch_id, device_id, session_id, cashier_id,
                 customer_id, client_uuid, receipt_no, ref_doc_id, gross, line_discount,
                 invoice_discount, net, paid, change_due, completed_at)
             VALUES ('return', 'completed', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())"
        );
        // المبالغ موجبة والنوع doc_kind='return' بيحدد الاتجاه
        $docStmt->execute([
            (int) $device['branch_id'],
            $deviceId,
            (int) $session['id'],
            (int) $user['id'],
            $customerId,
            $clientUuid,
            $receiptNo,
            $origDocId,
            $totalReturnGross,
            round($totalReturnGross - $totalReturnNet, 2),
            0.00,
            $totalReturnNet,
            $totalReturnNet,
        ]);
        $returnDocId = (int) $pdo->lastInsertId();

        $lineValGroups = [];
        $lineParams = [];
        foreach ($returnLines as $lidx => $rl) {
            $lineValGroups[] = '(?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())';
            array_push(
                $lineParams,
                $returnDocId,
                $lidx + 1,
                (int) $rl['item_id'],
                $rl['code'],
                $rl['unit_name'],
                $rl['unit_factor'],
                $rl['qty'],
                $rl['qty_pieces'],
                $rl['unit_price'],
                $rl['line_discount'],
                $rl['net'],
                $rl['unit_cost'],
                $rl['ref_line_id']
            );
        }
        $pdo->prepare(
            'INSERT INTO sale_lines
                (doc_id, line_no, item_id, code, unit_name, unit_factor, qty, qty_pieces,
                 unit_price, line_discount, net, unit_cost, ref_line_id, created_at)
             VALUES ' . implode(',', $lineValGroups)
        )->execute($lineParams);

        sale_payments_insert($pdo, $returnDocId, $cleanPayments);

        $stockLines = [];
        foreach ($returnLines as $rl) {
            $stockLines[] = [
                'item_id' => (int) $rl['item_id'],
                'code' => $rl['code'],
                'unit_factor' => (float) $rl['unit_factor'],
                'qty_units' => (float) $rl['qty'],
                'unit_cost' => $rl['unit_cost'],
            ];
        }
        $stockDocId = stock_doc_create(
            $pdo,
            'sale_return',
            [
                'branch_id' => (int) $device['branch_id'],
                'doc_date' => date('Y-m-d'),
                'ref_text' => $receiptNo,
                'notes' => 'مرتجع نقطة البيع #' . $returnDocId . ' على الفاتورة ' . $origDoc['receipt_no'],
            ],
            $stockLines,
            (int) $user['id']
        );
        stock_post($pdo, $stockDocId, (int) $user['id']);
        $pdo->prepare('UPDATE sale_docs SET stock_doc_id=? WHERE id=?')->execute([$stockDocId, $returnDocId]);

        if ($lateApproval) {
            sale_consume_token($pdo, (int) $lateApproval['id'], $returnDocId);
        }
        if ($methodApproval) {
            sale_consume_token($pdo, (int) $methodApproval['id'], $returnDocId);
        }

        $events = [[
            'branch_id' => (int) $device['branch_id'],
            'device_id' => $deviceId,
            'session_id' => (int) $session['id'],
            'user_id' => (int) $user['id'],
            'doc_id' => $returnDocId,
            'event_type' => 'return_completed',
            'payload' => [
                'receipt_no' => $receiptNo,
                'ref_doc_id' => $origDocId,
                'ref_receipt_no' => $origDoc['receipt_no'],
                'net' => $totalReturnNet,
                'lines_count' => count($returnLines),
            ],
            'approved_by' => $lateApproval['approved_by'] ?? ($methodApproval['approved_by'] ?? null),
        ]];

        $cashRefunded = 0.0;
        foreach ($cleanPayments as $cp) {
            if ($cp['method'] === 'cash') {
                $cashRefunded += $cp['amount'];
            }
        }
        if ($cashRefunded > 0) {
            $events[] = [
                'branch_id' => (int) $device['branch_id'],
                'device_id' => $deviceId,
                'session_id' => (int) $session['id'],
                'user_id' => (int) $user['id'],
                'doc_id' => $returnDocId,
                'event_type' => 'drawer_open',
                'payload' => ['reason' => 'cash_return', 'amount' => $cashRefunded],
                'approved_by' => null,
            ];
        }

        if ($methodApproval) {
            $events[] = [
                'branch_id' => (int) $device['branch_id'],
                'device_id' => $deviceId,
                'session_id' => (int) $session['id'],
                'user_id' => (int) $user['id'],
                'doc_id' => $returnDocId,
                'event_type' => 'return_method_override',
                'payload' => [
                    'reason' => $methodOverrideReason ?: 'تغيير طريقة رد المرتجع',
                    'payments' => $cleanPayments,
                ],
                'approved_by' => (int) $methodApproval['approved_by'],
            ];
        }

        sale_events_insert($pdo, $events);
        $pdo->commit();

        return [
            'doc_id' => $returnDocId,
            'receipt_no' => $receiptNo,
            'gross' => $totalReturnGross,
            'line_discount' => round($totalReturnGross - $totalReturnNet, 2),
            'invoice_discount' => 0.00,
            'net' => $totalReturnNet,
            'paid' => $totalReturnNet,
            'change_due' => 0.00,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062) {
            throw new SaleApiException('عملية المرتجع دي اتسجلت قبل كده.', 409, 'duplicate');
        }
        throw $e;
    }
}

/** مرتجع عام بدون فاتورة أصلية (بصلاحية pos:return_general وسبب إجباري). */
function sale_return_general(PDO $pdo, array $input, array $user): array
{
    if (!can('pos:return_general') && !is_real_owner()) {
        throw new SaleApiException('مالكش صلاحية المرتجع العام.', 403, 'forbidden');
    }

    $reason = trim((string) ($input['reason'] ?? ''));
    if ($reason === '') {
        throw new SaleApiException('سبب المرتجع العام إجباري.');
    }

    $clientUuid = trim((string) ($input['client_uuid'] ?? ''));
    if ($clientUuid === '' || mb_strlen($clientUuid) > 64) {
        throw new SaleApiException('معرّف عملية المرتجع مطلوب وبحد أقصى ٦٤ خانة.');
    }

    $pdo->beginTransaction();
    try {
        $device = sale_device($pdo, (string) ($input['device_uuid'] ?? ''), $user, true);
        $deviceId = (int) $device['id'];

        $existingDoc = sale_existing_by_client($pdo, $deviceId, $clientUuid);
        if ($existingDoc && $existingDoc['status'] === 'completed') {
            $res = sale_doc_result($existingDoc);
            $pdo->commit();
            return $res;
        }
        if ($existingDoc) {
            throw new SaleApiException('معرّف العملية مستخدم قبل كده.', 409, 'duplicate');
        }

        $session = sale_open_session($pdo, $deviceId, true);
        if (!$session) {
            throw new SaleApiException('افتح وردية على الجهاز الأول.', 409, 'locked');
        }
        if (!sale_branch_ready((int) $device['branch_id'])) {
            throw new SaleApiException('الفرع لسه على True — المرتجع من نقطة البيع الجديدة مقفول.', 409, 'locked');
        }

        $resolvedLines = sale_resolve_lines($pdo, (array) ($input['lines'] ?? []));

        $totalNet = 0.0;
        $returnLines = [];
        foreach ($resolvedLines as $line) {
            $qty = (float) $line['qty'];
            $unitPrice = (float) $line['unit_price'];
            $lineGross = round($qty * $unitPrice, 2);
            $returnLines[] = array_merge($line, [
                'gross' => $lineGross,
                'line_discount' => 0.00,
                'net' => $lineGross,
                'unit_cost' => null,
            ]);
            $totalNet = round($totalNet + $lineGross, 2);
        }

        $inputPayments = (array) ($input['payments'] ?? []);
        if (!$inputPayments) {
            throw new SaleApiException('لازم تسجل طريقة رد المرتجع.');
        }
        $paymentSum = 0.0;
        $cashRefunded = 0.0;
        $cleanPayments = [];
        foreach ($inputPayments as $p) {
            $m = (string) ($p['method'] ?? '');
            $amt = round((float) ($p['amount'] ?? 0), 2);
            if (!in_array($m, ['cash', 'visa', 'instapay'], true)) {
                throw new SaleApiException('طريقة الرد مش مدعومة.');
            }
            if ($amt <= 0) {
                throw new SaleApiException('قيمة الرد لازم تبقى أكبر من صفر.');
            }
            if ($m === 'cash') {
                $cashRefunded += $amt;
            }
            $paymentSum = round($paymentSum + $amt, 2);
            $cleanPayments[] = [
                'method' => $m,
                'amount' => $amt,
                'reference' => trim((string) ($p['reference'] ?? '')) ?: null,
            ];
        }

        if (abs($paymentSum - $totalNet) > 0.0001) {
            throw new SaleApiException('مجموع مبالغ الرد لازم يساوي صافي المرتجع بالظبط (' . $totalNet . ' ج.م).');
        }

        $receiptNo = sale_next_receipt($pdo, $device);
        $customerId = sale_require_customer($pdo, $input['customer_id'] ?? null);

        $docStmt = $pdo->prepare(
            "INSERT INTO sale_docs
                (doc_kind, status, branch_id, device_id, session_id, cashier_id,
                 customer_id, client_uuid, receipt_no, ref_doc_id, gross, line_discount,
                 invoice_discount, net, paid, change_due, completed_at)
             VALUES ('return','completed',?,?,?,?,?,?,?,NULL,?,0,0,?,?,0,NOW())"
        );
        $docStmt->execute([
            (int) $device['branch_id'],
            $deviceId,
            (int) $session['id'],
            (int) $user['id'],
            $customerId,
            $clientUuid,
            $receiptNo,
            $totalNet,
            $totalNet,
            $totalNet,
        ]);
        $returnDocId = (int) $pdo->lastInsertId();

        $lineValGroups = [];
        $lineParams = [];
        foreach ($returnLines as $lidx => $rl) {
            $lineValGroups[] = '(?,?,?,?,?,?,?,?,?,?,?,NULL,NULL,NOW())';
            array_push(
                $lineParams,
                $returnDocId,
                $lidx + 1,
                (int) $rl['item_id'],
                $rl['code'],
                $rl['unit_name'],
                $rl['unit_factor'],
                $rl['qty'],
                $rl['qty_pieces'],
                $rl['unit_price'],
                0.00,
                $rl['net']
            );
        }
        $pdo->prepare(
            'INSERT INTO sale_lines
                (doc_id, line_no, item_id, code, unit_name, unit_factor, qty, qty_pieces,
                 unit_price, line_discount, net, unit_cost, ref_line_id, created_at)
             VALUES ' . implode(',', $lineValGroups)
        )->execute($lineParams);

        sale_payments_insert($pdo, $returnDocId, $cleanPayments);

        $stockLines = [];
        foreach ($returnLines as $rl) {
            $stockLines[] = [
                'item_id' => (int) $rl['item_id'],
                'code' => $rl['code'],
                'unit_factor' => (float) $rl['unit_factor'],
                'qty_units' => (float) $rl['qty'],
                'unit_cost' => null,
            ];
        }
        $stockDocId = stock_doc_create(
            $pdo,
            'sale_return',
            [
                'branch_id' => (int) $device['branch_id'],
                'doc_date' => date('Y-m-d'),
                'ref_text' => $receiptNo,
                'notes' => 'مرتجع عام نقطة البيع #' . $returnDocId,
            ],
            $stockLines,
            (int) $user['id']
        );
        stock_post($pdo, $stockDocId, (int) $user['id']);
        $pdo->prepare('UPDATE sale_docs SET stock_doc_id=? WHERE id=?')->execute([$stockDocId, $returnDocId]);

        $pdo->prepare(
            'UPDATE sale_lines sl
               JOIN stock_doc_lines sdl ON sdl.doc_id=? AND sdl.line_no=sl.line_no
               JOIN stock_moves sm ON sm.doc_id=? AND sm.doc_line_id=sdl.id
                SET sl.unit_cost=sm.unit_cost
              WHERE sl.doc_id=?'
        )->execute([$stockDocId, $stockDocId, $returnDocId]);

        $events = [[
            'branch_id' => (int) $device['branch_id'],
            'device_id' => $deviceId,
            'session_id' => (int) $session['id'],
            'user_id' => (int) $user['id'],
            'doc_id' => $returnDocId,
            'event_type' => 'return_general',
            'payload' => [
                'reason' => mb_substr($reason, 0, 500),
                'receipt_no' => $receiptNo,
                'net' => $totalNet,
                'lines_count' => count($returnLines),
            ],
            'approved_by' => null,
        ]];
        if ($cashRefunded > 0) {
            $events[] = [
                'branch_id' => (int) $device['branch_id'],
                'device_id' => $deviceId,
                'session_id' => (int) $session['id'],
                'user_id' => (int) $user['id'],
                'doc_id' => $returnDocId,
                'event_type' => 'drawer_open',
                'payload' => ['reason' => 'cash_return', 'amount' => $cashRefunded],
                'approved_by' => null,
            ];
        }
        sale_events_insert($pdo, $events);

        $pdo->commit();
        return [
            'doc_id' => $returnDocId,
            'receipt_no' => $receiptNo,
            'gross' => $totalNet,
            'line_discount' => 0.00,
            'invoice_discount' => 0.00,
            'net' => $totalNet,
            'paid' => $totalNet,
            'change_due' => 0.00,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062) {
            throw new SaleApiException('عملية المرتجع العام دي اتسجلت قبل كده.', 409, 'duplicate');
        }
        throw $e;
    }
}

/** عملية الاستبدال: مرتجع على فاتورة + بيع جديد في transaction واحدة مربوطين ببعض. */
function sale_exchange(PDO $pdo, array $input, array $user): array
{
    $clientUuid = trim((string) ($input['client_uuid'] ?? ''));
    if ($clientUuid === '' || mb_strlen($clientUuid) > 64) {
        throw new SaleApiException('معرّف عملية الاستبدال مطلوب وبحد أقصى ٦٤ خانة.');
    }

    $pdo->beginTransaction();
    try {
        $device = sale_device($pdo, (string) ($input['device_uuid'] ?? ''), $user, true);
        $deviceId = (int) $device['id'];

        $existingDoc = sale_existing_by_client($pdo, $deviceId, $clientUuid);
        if ($existingDoc && $existingDoc['status'] === 'completed') {
            $returnDoc = null;
            if ($existingDoc['ref_doc_id'] > 0) {
                $retStmt = $pdo->prepare('SELECT * FROM sale_docs WHERE id = ?');
                $retStmt->execute([(int) $existingDoc['ref_doc_id']]);
                $returnDoc = $retStmt->fetch(PDO::FETCH_ASSOC);
            }
            $diff = round((float) $existingDoc['net'] - (float) ($returnDoc['net'] ?? 0), 2);
            $res = [
                'sale_doc_id' => (int) $existingDoc['id'],
                'sale_receipt_no' => (string) $existingDoc['receipt_no'],
                'sale_net' => (float) $existingDoc['net'],
                'return_doc_id' => $returnDoc ? (int) $returnDoc['id'] : null,
                'return_receipt_no' => $returnDoc ? (string) $returnDoc['receipt_no'] : null,
                'return_net' => $returnDoc ? (float) $returnDoc['net'] : 0.0,
                'difference' => $diff,
                'change_due' => (float) $existingDoc['change_due'],
            ];
            $pdo->commit();
            return $res;
        }
        if ($existingDoc) {
            throw new SaleApiException('معرّف العملية مستخدم قبل كده.', 409, 'duplicate');
        }

        $session = sale_open_session($pdo, $deviceId, true);
        if (!$session) {
            throw new SaleApiException('افتح وردية على الجهاز الأول.', 409, 'locked');
        }
        if (!sale_branch_ready((int) $device['branch_id'])) {
            throw new SaleApiException('الفرع لسه على True — الاستبدال من نقطة البيع الجديدة مقفول.', 409, 'locked');
        }

        $returnInput = (array) ($input['return'] ?? []);
        $saleInput = (array) ($input['sale'] ?? []);

        $origDocId = (int) ($returnInput['doc_id'] ?? 0);
        if ($origDocId <= 0) {
            throw new SaleApiException('معرّف الفاتورة الأصلية للمرتجع مطلوب.');
        }

        $origStmt = $pdo->prepare("SELECT * FROM sale_docs WHERE id = ? AND doc_kind = 'sale' FOR UPDATE");
        $origStmt->execute([$origDocId]);
        $origDoc = $origStmt->fetch(PDO::FETCH_ASSOC);
        if (!$origDoc || $origDoc['status'] !== 'completed') {
            throw new SaleApiException('الفاتورة الأصلية للمرتجع مش موجودة أو مش مكتملة.', 409, 'locked');
        }
        if ((int) $origDoc['branch_id'] !== (int) $device['branch_id']) {
            throw new SaleApiException('الفاتورة دي من فرع تاني — الاستبدال من نفس الفرع بس.', 409, 'locked');
        }

        $completedAt = new DateTime($origDoc['completed_at']);
        $diffDays = (int) (new DateTime())->diff($completedAt)->days;
        $posReturnDays = 14;
        $daysSetting = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'pos_return_days'")->fetchColumn();
        if ($daysSetting !== false && is_numeric($daysSetting)) {
            $posReturnDays = max(0, (int) $daysSetting);
        }

        $lateApproval = null;
        if ($diffDays > $posReturnDays) {
            $tokenStr = (string) ($returnInput['approval_token'] ?? ($input['late_approval_token'] ?? ''));
            $lateApproval = sale_lookup_token($pdo, $deviceId, 'return_late', $tokenStr);
            if (!$lateApproval) {
                throw new SaleApiException(
                    'الفاتورة أقدم من ' . $posReturnDays . ' يوم — محتاجة اعتماد مشرف.',
                    409,
                    'locked'
                );
            }
        }

        $origLinesStmt = $pdo->prepare('SELECT * FROM sale_lines WHERE doc_id = ? ORDER BY line_no FOR UPDATE');
        $origLinesStmt->execute([$origDocId]);
        $origLinesById = [];
        foreach ($origLinesStmt->fetchAll(PDO::FETCH_ASSOC) as $ol) {
            $origLinesById[(int) $ol['id']] = $ol;
        }

        $prevReturnsStmt = $pdo->prepare(
            "SELECT rl.ref_line_id, SUM(rl.qty) AS qty, SUM(rl.net) AS net
               FROM sale_lines rl
               JOIN sale_docs rd ON rd.id = rl.doc_id
              WHERE rd.ref_doc_id = ? AND rd.doc_kind = 'return' AND rd.status = 'completed'
              GROUP BY rl.ref_line_id
                FOR UPDATE"
        );
        $prevReturnsStmt->execute([$origDocId]);
        $prevReturnsByLineId = [];
        foreach ($prevReturnsStmt->fetchAll(PDO::FETCH_ASSOC) as $pr) {
            $prevReturnsByLineId[(int) $pr['ref_line_id']] = $pr;
        }

        $returnCalc = sale_calculate_return_lines($origDoc, $origLinesById, $prevReturnsByLineId, (array) ($returnInput['lines'] ?? []));
        $returnLines = $returnCalc['lines'];
        $returnNet = $returnCalc['total_net'];
        $returnGross = $returnCalc['total_gross'];
        if ($lateApproval) {
            sale_require_return_approval($lateApproval, $origDocId, $returnNet);
        }

        $saleLinesInput = (array) ($saleInput['lines'] ?? []);
        if (!$saleLinesInput) {
            throw new SaleApiException('سلة البيع في الاستبدال فاضية.');
        }
        $resolvedSaleLines = sale_resolve_lines($pdo, $saleLinesInput);
        try {
            $saleCalc = sale_compute($resolvedSaleLines, $saleInput['invoice_discount'] ?? 0);
        } catch (InvalidArgumentException $e) {
            throw new SaleApiException($e->getMessage());
        }
        $saleNet = (float) $saleCalc['net'];

        $capStmt = $pdo->prepare('SELECT discount_cap_pct FROM users WHERE id=?');
        $capStmt->execute([(int) $user['id']]);
        $saleApproval = sale_discount_approval(
            $pdo,
            $deviceId,
            (float) $saleCalc['discount_pct'],
            round((float) ($capStmt->fetchColumn() ?: 0), 2),
            (string) ($saleInput['approval_token'] ?? '')
        );

        $diff = round($saleNet - $returnNet, 2);
        $paymentsInput = (array) ($input['payments'] ?? ($saleInput['payments'] ?? []));
        $cleanPayments = [];
        $paidDiff = 0.0;
        $changeDue = 0.0;
        $cashAmount = 0.0;
        $methodApproval = null;
        $methodOverrideReason = '';

        if ($diff > 0) {
            $maxPayments = max(1, (int) ($pdo->query("SELECT setting_value FROM settings WHERE setting_key='pos_max_payments'")->fetchColumn() ?: 3));
            try {
                $payValidation = sale_validate_payments($paymentsInput, $diff, $maxPayments);
            } catch (InvalidArgumentException $e) {
                throw new SaleApiException($e->getMessage());
            }
            $cleanPayments = $payValidation['payments'];
            $paidDiff = $payValidation['paid'];
            $changeDue = $payValidation['change_due'];
            $cashAmount = $payValidation['cash'];
        } elseif ($diff < 0) {
            $refundNeeded = abs($diff);
            $origPayStmt = $pdo->prepare('SELECT method, SUM(amount) AS amount FROM sale_payments WHERE doc_id = ? GROUP BY method');
            $origPayStmt->execute([$origDocId]);
            $origPayments = $origPayStmt->fetchAll(PDO::FETCH_KEY_PAIR);

            $prevRefundsStmt = $pdo->prepare(
                "SELECT rp.method, SUM(rp.amount) AS amount
                   FROM sale_payments rp
                   JOIN sale_docs rd ON rd.id = rp.doc_id
                  WHERE rd.ref_doc_id = ? AND rd.doc_kind = 'return' AND rd.status = 'completed'
                  GROUP BY rp.method
                    FOR UPDATE"
            );
            $prevRefundsStmt->execute([$origDocId]);
            $prevRefunds = $prevRefundsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

            $payValidation = sale_validate_return_payments($origPayments, $prevRefunds, $paymentsInput, $refundNeeded);
            $cleanPayments = $payValidation['payments'];
            $methodOverrideNeeded = $payValidation['override_needed'];

            foreach ($cleanPayments as $cp) {
                if ($cp['method'] === 'cash') {
                    $cashAmount += $cp['amount'];
                }
            }

            if ($methodOverrideNeeded) {
                $tokenStr = (string) ($input['method_approval_token'] ?? ($input['approval_token'] ?? ''));
                $methodApproval = sale_lookup_token($pdo, $deviceId, 'return_method', $tokenStr);
                if (!$methodApproval) {
                    throw new SaleApiException('طريقة رد الفرق مختلفة عن الفاتورة الأصلية — محتاجة اعتماد مشرف.', 403, 'forbidden');
                }
                sale_require_return_approval($methodApproval, $origDocId, $refundNeeded);
                $methodOverrideReason = trim((string) ($input['method_override_reason'] ?? ($input['reason'] ?? '')));
            }
        } else {
            $cleanPayments = [];
        }

        $customerId = sale_require_customer($pdo, $input['customer_id'] ?? $origDoc['customer_id']);

        $returnReceiptNo = sale_next_receipt($pdo, $device);
        $saleReceiptNo = sale_next_receipt($pdo, $device);

        $returnClientUuid = 'ret:' . mb_substr($clientUuid, 0, 60);
        $retDocStmt = $pdo->prepare(
            "INSERT INTO sale_docs
                (doc_kind, status, branch_id, device_id, session_id, cashier_id,
                 customer_id, client_uuid, receipt_no, ref_doc_id, gross, line_discount,
                 invoice_discount, net, paid, change_due, completed_at)
             VALUES ('return', 'completed', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())"
        );
        $retDocStmt->execute([
            (int) $device['branch_id'],
            $deviceId,
            (int) $session['id'],
            (int) $user['id'],
            $customerId,
            $returnClientUuid,
            $returnReceiptNo,
            $origDocId,
            $returnGross,
            round($returnGross - $returnNet, 2),
            0.00,
            $returnNet,
            $returnNet,
        ]);
        $returnDocId = (int) $pdo->lastInsertId();

        $retLineGroups = [];
        $retLineParams = [];
        foreach ($returnLines as $lidx => $rl) {
            $retLineGroups[] = '(?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())';
            array_push(
                $retLineParams,
                $returnDocId,
                $lidx + 1,
                (int) $rl['item_id'],
                $rl['code'],
                $rl['unit_name'],
                $rl['unit_factor'],
                $rl['qty'],
                $rl['qty_pieces'],
                $rl['unit_price'],
                $rl['line_discount'],
                $rl['net'],
                $rl['unit_cost'],
                $rl['ref_line_id']
            );
        }
        $pdo->prepare(
            'INSERT INTO sale_lines
                (doc_id, line_no, item_id, code, unit_name, unit_factor, qty, qty_pieces,
                 unit_price, line_discount, net, unit_cost, ref_line_id, created_at)
             VALUES ' . implode(',', $retLineGroups)
        )->execute($retLineParams);

        if ($diff < 0 && $cleanPayments) {
            sale_payments_insert($pdo, $returnDocId, $cleanPayments);
        }

        $saleDocStmt = $pdo->prepare(
            "INSERT INTO sale_docs
                (doc_kind, status, branch_id, device_id, session_id, cashier_id,
                 customer_id, client_uuid, receipt_no, ref_doc_id, gross, line_discount,
                 invoice_discount, net, paid, change_due, discount_approved_by, completed_at)
             VALUES ('sale','completed',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())"
        );
        $saleDocStmt->execute([
            (int) $device['branch_id'],
            $deviceId,
            (int) $session['id'],
            (int) $user['id'],
            $customerId,
            $clientUuid,
            $saleReceiptNo,
            $returnDocId,
            $saleCalc['gross'],
            $saleCalc['line_discount'],
            $saleCalc['invoice_discount'],
            $saleNet,
            round($returnNet + ($diff > 0 ? $paidDiff : 0), 2),
            $changeDue,
            $saleApproval['approved_by'],
        ]);
        $saleDocId = (int) $pdo->lastInsertId();

        sale_lines_insert($pdo, $saleDocId, $saleCalc['lines']);

        if ($diff > 0 && $cleanPayments) {
            sale_payments_insert($pdo, $saleDocId, $cleanPayments);
        }

        $retStockLines = [];
        foreach ($returnLines as $rl) {
            $retStockLines[] = [
                'item_id' => (int) $rl['item_id'],
                'code' => $rl['code'],
                'unit_factor' => (float) $rl['unit_factor'],
                'qty_units' => (float) $rl['qty'],
                'unit_cost' => $rl['unit_cost'],
            ];
        }
        $retStockDocId = stock_doc_create(
            $pdo,
            'sale_return',
            [
                'branch_id' => (int) $device['branch_id'],
                'doc_date' => date('Y-m-d'),
                'ref_text' => $returnReceiptNo,
                'notes' => 'مرتجع استبدال #' . $returnDocId,
            ],
            $retStockLines,
            (int) $user['id']
        );
        stock_post($pdo, $retStockDocId, (int) $user['id']);
        $pdo->prepare('UPDATE sale_docs SET stock_doc_id=? WHERE id=?')->execute([$retStockDocId, $returnDocId]);

        $saleStockLines = [];
        foreach ($saleCalc['lines'] as $sl) {
            $saleStockLines[] = [
                'item_id' => (int) $sl['item_id'],
                'code' => $sl['code'],
                'unit_factor' => (float) $sl['unit_factor'],
                'qty_units' => (float) $sl['qty'],
                'unit_cost' => null,
            ];
        }
        $saleStockDocId = stock_doc_create(
            $pdo,
            'sale',
            [
                'branch_id' => (int) $device['branch_id'],
                'doc_date' => date('Y-m-d'),
                'ref_text' => $saleReceiptNo,
                'notes' => 'بيع استبدال #' . $saleDocId,
            ],
            $saleStockLines,
            (int) $user['id']
        );
        stock_post($pdo, $saleStockDocId, (int) $user['id']);
        $pdo->prepare('UPDATE sale_docs SET stock_doc_id=? WHERE id=?')->execute([$saleStockDocId, $saleDocId]);

        $pdo->prepare(
            'UPDATE sale_lines sl
               JOIN stock_doc_lines sdl ON sdl.doc_id=? AND sdl.line_no=sl.line_no
               JOIN stock_moves sm ON sm.doc_id=? AND sm.doc_line_id=sdl.id
                SET sl.unit_cost=sm.unit_cost
              WHERE sl.doc_id=?'
        )->execute([$saleStockDocId, $saleStockDocId, $saleDocId]);

        if ($lateApproval) {
            sale_consume_token($pdo, (int) $lateApproval['id'], $returnDocId);
        }
        if ($methodApproval) {
            sale_consume_token($pdo, (int) $methodApproval['id'], $returnDocId);
        }
        if ($saleApproval['approval_id'] !== null) {
            sale_consume_token($pdo, (int) $saleApproval['approval_id'], $saleDocId);
        }

        $events = [
            [
                'branch_id' => (int) $device['branch_id'],
                'device_id' => $deviceId,
                'session_id' => (int) $session['id'],
                'user_id' => (int) $user['id'],
                'doc_id' => $returnDocId,
                'event_type' => 'exchange_completed',
                'payload' => [
                    'return_doc_id' => $returnDocId,
                    'sale_doc_id' => $saleDocId,
                    'return_receipt_no' => $returnReceiptNo,
                    'sale_receipt_no' => $saleReceiptNo,
                    'return_net' => $returnNet,
                    'sale_net' => $saleNet,
                    'difference' => $diff,
                ],
                'approved_by' => $lateApproval['approved_by'] ?? ($methodApproval['approved_by'] ?? $saleApproval['approved_by']),
            ],
        ];

        if ($diff > 0 && $cashAmount > 0) {
            $events[] = [
                'branch_id' => (int) $device['branch_id'],
                'device_id' => $deviceId,
                'session_id' => (int) $session['id'],
                'user_id' => (int) $user['id'],
                'doc_id' => $saleDocId,
                'event_type' => 'drawer_open',
                'payload' => ['reason' => 'cash_sale', 'amount' => $cashAmount],
                'approved_by' => null,
            ];
        } elseif ($diff < 0 && $cashAmount > 0) {
            $events[] = [
                'branch_id' => (int) $device['branch_id'],
                'device_id' => $deviceId,
                'session_id' => (int) $session['id'],
                'user_id' => (int) $user['id'],
                'doc_id' => $returnDocId,
                'event_type' => 'drawer_open',
                'payload' => ['reason' => 'cash_return', 'amount' => $cashAmount],
                'approved_by' => null,
            ];
        }

        sale_events_insert($pdo, $events);
        $pdo->commit();

        return [
            'return_doc_id' => $returnDocId,
            'return_receipt_no' => $returnReceiptNo,
            'return_net' => $returnNet,
            'sale_doc_id' => $saleDocId,
            'sale_receipt_no' => $saleReceiptNo,
            'sale_net' => $saleNet,
            'difference' => $diff,
            'change_due' => $changeDue,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062) {
            throw new SaleApiException('عملية الاستبدال دي اتسجلت قبل كده.', 409, 'duplicate');
        }
        throw $e;
    }
}

/**
 * بريف ٢٤٤: تجهيز payload موحد لإيصال البيع والمرتجع من الأرقام المحفوظة.
 *
 * @param int $docId معرّف المستند في sale_docs
 * @param bool $forceReprint هل دي نسخة إعادة طباعة صريحة
 * @param array|null $user المستخدم الحالي للتحقق من الصلاحيات (اختياري)
 * @return array
 */
function sale_receipt_payload(int $docId, bool $forceReprint = false, ?array $user = null): array
{
    if ($docId <= 0) {
        throw new SaleApiException('رقم الفاتورة غير صحيح.', 400, 'bad_request');
    }
    $pdo = db();

    $stmt = $pdo->prepare('SELECT * FROM sale_docs WHERE id = ? LIMIT 1');
    $stmt->execute([$docId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc) {
        throw new SaleApiException('الفاتورة مش موجودة.', 404, 'not_found');
    }

    $currentUser = $user ?? (function_exists('current_user') ? current_user() : null);
    if ($currentUser && !empty($currentUser['id'])) {
        if ($user === null && function_exists('is_real_owner') && is_real_owner()) {
            $currentUser['is_owner'] = 1;
        }
        sale_require_branch($pdo, (int) $doc['branch_id'], $currentUser);
    }

    // بيانات الفرع
    $branchStmt = $pdo->prepare('SELECT name, phone FROM branches WHERE id = ? LIMIT 1');
    $branchStmt->execute([(int) $doc['branch_id']]);
    $branchRow = $branchStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $branchName = (string) ($branchRow['name'] ?? '');
    $branchPhone = (string) ($branchRow['phone'] ?? '');

    // بيانات الجهاز
    $devStmt = $pdo->prepare('SELECT name, code FROM sale_devices WHERE id = ? LIMIT 1');
    $devStmt->execute([(int) $doc['device_id']]);
    $devRow = $devStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $deviceName = (string) ($devRow['name'] ?? '');
    $deviceCode = (string) ($devRow['code'] ?? '');

    // بيانات الكاشير
    $cashierStmt = $pdo->prepare('SELECT full_name FROM users WHERE id = ? LIMIT 1');
    $cashierStmt->execute([(int) $doc['cashier_id']]);
    $cashierName = (string) ($cashierStmt->fetchColumn() ?: '');

    // بيانات العميل لو موجود
    $customerName = '';
    $customerPhone = '';
    if (!empty($doc['customer_id'])) {
        $custStmt = $pdo->prepare('SELECT name, phone FROM customers WHERE id = ? LIMIT 1');
        $custStmt->execute([(int) $doc['customer_id']]);
        if ($custRow = $custStmt->fetch(PDO::FETCH_ASSOC)) {
            $customerName = (string) ($custRow['name'] ?? '');
            $customerPhone = (string) ($custRow['phone'] ?? '');
        }
    }

    // رقم الفاتورة الأصلية لو مرتجع
    $refReceiptNo = '';
    if (!empty($doc['ref_doc_id'])) {
        $refStmt = $pdo->prepare('SELECT receipt_no FROM sale_docs WHERE id = ? LIMIT 1');
        $refStmt->execute([(int) $doc['ref_doc_id']]);
        $refReceiptNo = (string) ($refStmt->fetchColumn() ?: '');
    }

    // فحص أحداث إعادة الطباعة
    $reprintStmt = $pdo->prepare("SELECT COUNT(*) FROM sale_events WHERE doc_id = ? AND event_type = 'receipt_reprint'");
    $reprintStmt->execute([$docId]);
    $reprintCount = (int) $reprintStmt->fetchColumn();
    $isReprint = $forceReprint || ($reprintCount > 0);

    // السطور من sale_lines
    $linesStmt = $pdo->prepare(
        "SELECT sl.id, sl.line_no, sl.item_id, sl.code, sl.unit_name, sl.unit_factor,
                sl.qty, sl.qty_pieces, sl.unit_price, sl.line_discount, sl.net,
                COALESCE(
                    (SELECT COALESCE(ic.item_name, c.name)
                       FROM hq_codes c
                       LEFT JOIN hq_item_cats ic ON ic.item_id = c.item_id
                      WHERE c.code = sl.code
                      LIMIT 1),
                    (SELECT m.name1
                       FROM item_master_codes mc
                       JOIN item_master m ON m.id = mc.item_master_id
                      WHERE mc.code = sl.code
                      LIMIT 1),
                    sl.code
                ) AS item_name
           FROM sale_lines sl
          WHERE sl.doc_id = ?
          ORDER BY sl.line_no ASC"
    );
    $linesStmt->execute([$docId]);
    $rawLines = $linesStmt->fetchAll(PDO::FETCH_ASSOC);

    $linesOut = [];
    foreach ($rawLines as $l) {
        $linesOut[] = [
            'id'            => (int) $l['id'],
            'line_no'       => (int) $l['line_no'],
            'item_id'       => (int) $l['item_id'],
            'name'          => (string) $l['item_name'],
            'item_name'     => (string) $l['item_name'],
            'code'          => (string) $l['code'],
            'unit'          => (string) $l['unit_name'],
            'unit_name'     => (string) $l['unit_name'],
            'unit_factor'   => (float) $l['unit_factor'],
            'qty'           => (float) $l['qty'],
            'price'         => (float) $l['unit_price'],
            'unit_price'    => (float) $l['unit_price'],
            'discount'      => (float) $l['line_discount'],
            'line_discount' => (float) $l['line_discount'],
            'net'           => (float) $l['net'],
        ];
    }

    // الدفعات من sale_payments
    $payStmt = $pdo->prepare('SELECT method, amount, reference FROM sale_payments WHERE doc_id = ? ORDER BY id ASC');
    $payStmt->execute([$docId]);
    $rawPays = $payStmt->fetchAll(PDO::FETCH_ASSOC);

    $methodNames = [
        'cash'     => 'كاش',
        'visa'     => 'فيزا',
        'instapay' => 'إنستا باي',
    ];

    $paymentsOut = [];
    foreach ($rawPays as $p) {
        $m = (string) $p['method'];
        $paymentsOut[] = [
            'method'    => $m,
            'name'      => $methodNames[$m] ?? $m,
            'amount'    => (float) $p['amount'],
            'reference' => (string) ($p['reference'] ?? ''),
        ];
    }

    $rawCompletedAt = !empty($doc['completed_at']) ? (string) $doc['completed_at'] : (string) $doc['created_at'];
    $ts = strtotime($rawCompletedAt) ?: time();
    $dateStr = date('Y-m-d', $ts);
    $timeStr = date('h:i', $ts) . (date('A', $ts) === 'AM' ? ' ص' : ' م');
    $datetimeStr = $dateStr . ' ' . $timeStr;

    $gross = (float) $doc['gross'];
    $lineDiscount = (float) $doc['line_discount'];
    $invoiceDiscount = (float) $doc['invoice_discount'];
    $totalDiscount = round($lineDiscount + $invoiceDiscount, 2);
    $net = (float) $doc['net'];
    $paid = (float) $doc['paid'];
    $changeDue = (float) $doc['change_due'];

    // إعداد مدة الاسترجاع وتاريخ نهاية الاسترجاع (بريف ٨٢٧h)
    $posReturnDays = 14;
    $daysSetting = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'pos_return_days'")->fetchColumn();
    if ($daysSetting !== false && is_numeric($daysSetting)) {
        $posReturnDays = max(0, (int) $daysSetting);
    }
    $completedAtBase = !empty($doc['completed_at']) ? (string) $doc['completed_at'] : (string) ($doc['created_at'] ?? 'now');
    try {
        $returnUntilObj = new DateTime($completedAtBase);
    } catch (Throwable) {
        $returnUntilObj = new DateTime();
    }
    $returnUntilObj->modify('+' . $posReturnDays . ' days');
    $returnUntil = $returnUntilObj->format('Y-m-d');

    return [
        'v'                => 1,
        'doc_id'           => (int) $doc['id'],
        'doc_kind'         => (string) $doc['doc_kind'],
        'is_return'        => $doc['doc_kind'] === 'return',
        'status'           => (string) $doc['status'],
        'receipt_no'       => (string) ($doc['receipt_no'] ?? ''),
        'ref_doc_id'       => !empty($doc['ref_doc_id']) ? (int) $doc['ref_doc_id'] : null,
        'ref_receipt_no'   => $refReceiptNo,
        'branch_id'        => (int) $doc['branch_id'],
        'branch_name'      => $branchName,
        'branch_phone'     => $branchPhone,
        'device_id'        => (int) $doc['device_id'],
        'device_name'      => $deviceName,
        'device_code'      => $deviceCode,
        'cashier_id'       => (int) $doc['cashier_id'],
        'cashier_name'     => $cashierName,
        'customer_id'      => !empty($doc['customer_id']) ? (int) $doc['customer_id'] : null,
        'customer_name'    => $customerName !== '' ? $customerName : null,
        'customer_phone'   => $customerPhone !== '' ? $customerPhone : null,
        'date'             => $dateStr,
        'time'             => $timeStr,
        'datetime'         => $datetimeStr,
        'created_at'       => $rawCompletedAt,
        'is_reprint'       => $isReprint,
        'reprint_count'    => $reprintCount,
        'return_days'      => $posReturnDays,
        'return_until'     => $returnUntil,
        'lines'            => $linesOut,
        'gross'            => $gross,
        'line_discount'    => $lineDiscount,
        'invoice_discount' => $invoiceDiscount,
        'discount'         => $totalDiscount,
        'net'              => $net,
        'payments'         => $paymentsOut,
        'paid'             => $paid,
        'change'           => $changeDue,
        'change_due'       => $changeDue,
    ];
}
