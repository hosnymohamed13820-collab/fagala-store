<?php
/**
 * finance.php — الدوال المالية المشتركة (قلب النظام المحاسبي)
 * نظام الفجالة ستور المالي
 *
 * المبدأ: الخزينة هي المصدر الوحيد للحقيقة.
 * أي موديول عايز يحرّك فلوس لازم يمر من post_journal().
 * مفيش أي كود تاني بيلمس رصيد الخزينة مباشرةً.
 *
 * يتطلب: config.php (للدالة db())
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/site_profile.php';
require_once __DIR__ . '/push_lib.php';

/**
 * قراءة مبلغ من POST بالفاصلة أو من غيرها.
 *
 * 🔴 الواجهة بتعرض 10,000 — و(float)"10,000" في PHP بتساوي **10.0**
 *    من غير أي خطأ ولا تحذير. يعني صرف عشرة آلاف يتسجّل عشرة جنيه
 *    والقيد يتقفل والرصيد يتظبط على الغلط.
 *    الجافاسكربت بيشيل الفاصلة قبل الإرسال، والدالة دي هي الحماية
 *    التانية لو الجافاسكربت وقع أو الفورم اتبعت بطريقة تانية.
 */
function money_post(string $key, float $default = 0.0): float
{
    if (!isset($_POST[$key])) return $default;
    $raw = str_replace([',', '٬', ' ', "\xC2\xA0"], '', (string) $_POST[$key]);
    // أرقام عربية لو المستخدم كتبها من كيبورد عربي
    $raw = strtr($raw, ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4',
                        '٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
    return is_numeric($raw) ? (float) $raw : $default;
}

/**
 * تاريخ Y-m-d صحيح أو استثناء. بترجّع التاريخ بعد trim.
 * $withRange = true ← كمان مايبقاش بعد النهاردة ولا أقدم من ٤٠٠ يوم.
 */
function fin_valid_date(string $date, bool $withRange = true): string
{
    $date = trim($date);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new InvalidArgumentException('التاريخ غير صالح.');
    }
    [$y, $m, $d] = explode('-', $date);
    if (!checkdate((int) $m, (int) $d, (int) $y)) {
        throw new InvalidArgumentException('التاريخ غير صالح.');
    }
    if ($withRange) {
        // مدى تاريخ الحركة — قرار محمد ٢٣ سبتمبر ٢٠٢٦ (بريف ٣٩)
        if ($date > date('Y-m-d')) {
            throw new InvalidArgumentException('مينفعش تسجّل حركة بتاريخ لسه ماجاش.');
        }
        if ($date < date('Y-m-d', strtotime('-400 days'))) {
            throw new InvalidArgumentException('التاريخ أقدم من ٤٠٠ يوم.');
        }
    }
    return $date;
}

/**
 * تسجيل حركة في كشف الحساب + تحديث رصيد الخزينة (ذرّياً).
 *
 * @param int    $treasuryId  معرّف الخزينة
 * @param float  $debit       مدين (صرف/خروج فلوس) — موجب
 * @param float  $credit      دائن (استلام/دخول فلوس) — موجب
 * @param string $description وصف الحركة
 * @param string $date        تاريخ الحركة (Y-m-d)
 * @param string|null $refType نوع العملية المرتبطة
 * @param int|null    $refId   معرّف العملية المرتبطة
 * @param int|null    $userId  المستخدم اللي عمل الحركة
 * @param PDO|null    $pdo     اتصال موجود (للاستخدام داخل transaction خارجي)
 * @param int|null    $accountId الحساب (القيد) من شجرة الحسابات
 * @param string|null $notes   ملاحظات المحاسب — نص حر طويل (اختياري)
 *
 * @return int معرّف القيد الجديد
 *
 * ملاحظة: لو تم تمرير $pdo، الدالة بتفترض إن في transaction شغّال بالفعل
 * ومش بتفتح/تقفل واحد جديد — ده بيخلّي الموديولات تجمّع كذا حركة في عملية واحدة.
 *
 * $notes اتضاف آخر البراميترات عن قصد — كل النداءات القديمة (١٧ نداء في
 * الموديولات) شغّالة زي ما هي من غير أي تعديل، والملاحظة بترجع NULL.
 */
function post_journal(
    int $treasuryId,
    float $debit,
    float $credit,
    string $description,
    string $date,
    ?string $refType = null,
    ?int $refId = null,
    ?int $userId = null,
    ?PDO $pdo = null,
    ?int $accountId = null,
    ?string $notes = null
): int {
    $ownTransaction = false;
    if ($pdo === null) {
        $pdo = db();
        $pdo->beginTransaction();
        $ownTransaction = true;
    }

    try {
        // قفل صف الخزينة للقراءة المحدّثة (FOR UPDATE) لمنع التضارب
        $stmt = $pdo->prepare('SELECT balance FROM treasuries WHERE id = ? FOR UPDATE');
        $stmt->execute([$treasuryId]);
        $current = $stmt->fetchColumn();
        if ($current === false) {
            throw new RuntimeException('الخزينة غير موجودة (id=' . $treasuryId . ')');
        }

        // الرصيد الجديد = الحالي + الدائن − المدين
        $newBalance = (float) $current + $credit - $debit;

        // تسجيل القيد بالرصيد التراكمي
        $pdo->prepare(
            'INSERT INTO journal_entries
                (treasury_id, entry_date, description, account_id, debit, credit,
                 running_balance, ref_type, ref_id, created_by, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $treasuryId, $date, $description, $accountId, $debit, $credit, $newBalance,
            $refType, $refId, $userId, ($notes !== null && $notes !== '') ? $notes : null,
        ]);
        $entryId = (int) $pdo->lastInsertId();

        // تحديث رصيد الخزينة
        $pdo->prepare('UPDATE treasuries SET balance = ? WHERE id = ?')
            ->execute([$newBalance, $treasuryId]);

        if ($ownTransaction) {
            $pdo->commit();
        }
        return $entryId;

    } catch (Throwable $ex) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}

/**
 * تحويل بين خزينتين: مدين من المصدر، دائن للوجهة.
 * بيتعمل في transaction واحد (الحركتين معاً أو ولا واحدة).
 *
 * @return int معرّف التحويل في جدول treasury_transfers
 */
function transfer_between_treasuries(
    int $fromTreasuryId,
    int $toTreasuryId,
    float $amount,
    string $date,
    ?string $notes = null,
    ?int $userId = null
): int {
    $date = fin_valid_date($date);
    if ($amount <= 0) {
        throw new InvalidArgumentException('مبلغ التحويل لازم يكون أكبر من صفر.');
    }
    if ($fromTreasuryId === $toTreasuryId) {
        throw new InvalidArgumentException('لا يمكن التحويل لنفس الخزينة.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // قراءة أسامي الخزينتين في استعلام واحد مجمّع
        $tStmt = $pdo->prepare('SELECT id, name FROM treasuries WHERE id IN (?, ?)');
        $tStmt->execute([$fromTreasuryId, $toTreasuryId]);
        $tNames = [];
        foreach ($tStmt->fetchAll() as $tr) {
            $tNames[(int) $tr['id']] = (string) $tr['name'];
        }
        $fromName = $tNames[$fromTreasuryId] ?? ('خزينة #' . $fromTreasuryId);
        $toName   = $tNames[$toTreasuryId] ?? ('خزينة #' . $toTreasuryId);

        $outDesc = 'تحويل صادر إلى ' . $toName;
        if (mb_strlen($outDesc) > 255) {
            $outDesc = mb_substr($outDesc, 0, 255);
        }
        $inDesc = 'تحويل وارد من ' . $fromName;
        if (mb_strlen($inDesc) > 255) {
            $inDesc = mb_substr($inDesc, 0, 255);
        }

        // حساب «تحويل» من الإعدادات / شجرة الحسابات
        $transferAccId = transfer_account_id();

        // تسجيل التحويل
        $pdo->prepare(
            'INSERT INTO treasury_transfers
                (from_treasury_id, to_treasury_id, amount, transfer_date, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$fromTreasuryId, $toTreasuryId, $amount, $date, $notes, $userId]);
        $transferId = (int) $pdo->lastInsertId();

        // مدين من المصدر | دائن للوجهة (نفس الـ pdo = نفس الـ transaction)
        post_journal($fromTreasuryId, $amount, 0, $outDesc, $date,
                     'transfer', $transferId, $userId, $pdo, $transferAccId, $notes);
        post_journal($toTreasuryId, 0, $amount, $inDesc, $date,
                     'transfer', $transferId, $userId, $pdo, $transferAccId, $notes);

        $pdo->commit();
        return $transferId;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}

/**
 * فروع التكلفة لقوائم الصرف/الاستلام (الإدارة + الفروع).
 *
 * has_finance=1 → الفروع اللي ليها حركة مالية.
 * in_receiving=0 → الإدارة (مش فرع بيع، بس المصروف بيتحسب عليها).
 * الاتنين مع بعض عشان الإدارة تظهر في القائمة من غير ما يبقى ليها
 * عهدة ولا عمود توزيع في الاستلامات.
 */
function cost_branches_list(): array
{
    try {
        return db()->query(
            'SELECT id, name FROM branches
             WHERE is_active = 1 AND (has_finance = 1 OR in_receiving = 0)
             ORDER BY in_receiving ASC, name ASC'
        )->fetchAll();
    } catch (Throwable $ex) {
        return [];
    }
}

/**
 * هل المستخدم الحالي مسموحله يعدّل أوقات طلبات تصحيح الحضور؟
 * الأونر مسموحله دايماً، أو الموظف المحدد في الإعداد attendance_edit_user_id.
 *
 * ⚠️ بتقرا بـ setting() من config.php — مش دالة جديدة.
 *    config.php مش في المستودع (أسرار) بس **فيه دوال شغّالة** كمان،
 *    وأهمها setting() و shortage_supervisor_id() في shortage_lib.php.
 *    أي «دالة ناقصة» مش لاقيها في المستودع: دوّر في config.php على
 *    السيرفر الأول قبل ما تكتب واحدة جديدة — تعريف مكرر = Fatal error.
 */
function can_edit_attendance_time(): bool
{
    if (is_owner()) {
        return true;
    }
    $allowedUserId = (int) setting('attendance_edit_user_id', 0);
    if ($allowedUserId > 0) {
        $u = current_user();
        if ($u && (int) ($u['id'] ?? 0) === $allowedUserId) {
            return true;
        }
    }
    return false;
}

// =====================================================================
//  شجرة الحسابات (Chart of Accounts)
//  accounts فيه parent_id — الأب NULL يعني حساب رئيسي.
//  dynamic_src: الأبناء بيتجابوا لحظيًا من employees/suppliers.
// =====================================================================

/**
 * كل الحسابات النشطة مرتبة شجريًا (أب وبعده أبناؤه).
 * بترجّع صفوف فيها depth و path للعرض في القوائم.
 *
 * @param bool $custodyOnly حسابات العهدة بس (is_custody=1)
 */
function accounts_tree(bool $custodyOnly = false, bool $fresh = false): array
{
    static $cache = [];
    $ck = $custodyOnly ? 'c' : 'a';
    if ($fresh) { $cache = []; }
    if (isset($cache[$ck])) return $cache[$ck];

    try {
        $rows = db()->query(
            'SELECT id, parent_id, name, type, is_custody, dynamic_src, sort_order
             FROM accounts WHERE is_active = 1
             ORDER BY sort_order, name'
        )->fetchAll();
    } catch (Throwable $ex) {
        return [];
    }

    // تجميع الأبناء تحت كل أب
    $byParent = [];
    foreach ($rows as $r) {
        $byParent[(int) ($r['parent_id'] ?? 0)][] = $r;
    }

    $out = [];
    $walk = function (int $parentId, int $depth, string $prefix) use (&$walk, &$out, $byParent, $custodyOnly) {
        foreach ($byParent[$parentId] ?? [] as $r) {
            $id   = (int) $r['id'];
            $path = $prefix === '' ? $r['name'] : ($prefix . ' ← ' . $r['name']);
            $hasKids = !empty($byParent[$id]);

            // في وضع العهدة: نعرض الحساب لو هو نفسه عهدة أو تحته حساب عهدة
            $keep = !$custodyOnly || (int) $r['is_custody'] === 1;
            if ($keep) {
                $r['depth'] = $depth;
                $r['path']  = $path;
                $r['has_children'] = $hasKids;
                $out[] = $r;
            }
            $walk($id, $depth + 1, $path);
        }
    };
    $walk(0, 0, '');

    $cache[$ck] = $out;
    return $out;
}

/**
 * مزامنة الحسابات الديناميكية.
 * =====================================================================
 *  الحسابات اللي ليها dynamic_src (صيانة ← الموظفين، مورد ← الموردين)
 *  بيتعملها صفوف حقيقية في accounts تحت الأب.
 *
 *  ليه صفوف حقيقية مش قراءة لحظية؟
 *    journal_entries.account_id مفتاح أجنبي على accounts — فلو الموظف
 *    مش صف حقيقي، مش هينفع نخزّن القيد عليه أصلاً.
 *
 *  بتشتغل تلقائياً أول ما شاشة فيها القوايم تفتح (خفيفة: بتقارن
 *  الموجود بالمصدر وتكتب بس لو فيه فرق).
 *
 *  الحذف: لو موظف/مورد اتوقف، الحساب بيتوقف (is_active=0) لكن الصف
 *  بيفضل موجود عشان القيود القديمة ما تتكسرش.
 *
 * @return int عدد التغييرات (إضافة/تعديل/إيقاف)
 */
function sync_dynamic_accounts(): int
{
    // مرة واحدة في الريكوست — حتى لو اتنادت من كذا شاشة
    static $done = false;
    if ($done) return 0;
    $done = true;

    $changes = 0;

    try {
        $pdo = db();

        $parents = $pdo->query(
            "SELECT id, dynamic_src FROM accounts
             WHERE is_active = 1 AND dynamic_src IS NOT NULL"
        )->fetchAll();

        if (!$parents) return 0;

        $parentsBySource = [];
        $parentIds = [];
        foreach ($parents as $p) {
            $src = (string) $p['dynamic_src'];
            if ($src !== 'employees' && $src !== 'suppliers') continue;
            $pid = (int) $p['id'];
            $parentsBySource[$src] = true;
            $parentIds[$pid] = $pid;
        }

        if (!$parentIds) return 0;

        // كل مصدر بيتقري مرة واحدة مهما كان عدد الآباء اللي مربوطين بيه.
        $sourceRows = [];
        if (isset($parentsBySource['employees'])) {
            $sourceRows['employees'] = $pdo->query(
                'SELECT id, full_name AS name, is_active FROM employees ORDER BY full_name'
            )->fetchAll();
        }
        if (isset($parentsBySource['suppliers'])) {
            $sourceRows['suppliers'] = $pdo->query(
                'SELECT id, name, is_active FROM suppliers ORDER BY name'
            )->fetchAll();
        }

        // كل الأبناء الحاليين بيتجابوا مرة واحدة ويتقسموا في الذاكرة حسب الأب والمصدر.
        $parentPlaceholders = implode(',', array_fill(0, count($parentIds), '?'));
        $existingSt = $pdo->prepare(
            "SELECT id, parent_id, src_table, src_id, name, is_active FROM accounts
             WHERE parent_id IN ($parentPlaceholders)"
        );
        $existingSt->execute(array_values($parentIds));
        $existingByParent = [];
        foreach ($existingSt->fetchAll() as $e) {
            $key = (int) $e['parent_id'] . '|' . (string) $e['src_table'];
            $existingByParent[$key][(int) $e['src_id']] = $e;
        }

        $insert = $pdo->prepare(
            'INSERT INTO accounts (parent_id, name, type, src_table, src_id, is_active, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, 0)'
        );
        $update = $pdo->prepare(
            'UPDATE accounts SET name = ?, is_active = ? WHERE id = ?'
        );

        foreach ($parents as $p) {
            $pid = (int) $p['id'];
            $src = (string) $p['dynamic_src'];

            // المصدر
            if ($src === 'employees') {
                $rows = $sourceRows['employees'];
                $type = 'expense';
            } elseif ($src === 'suppliers') {
                $rows = $sourceRows['suppliers'];
                $type = 'liability';
            } else {
                continue;
            }

            // الموجود حالياً تحت الأب ده
            $existing = $existingByParent[$pid . '|' . $src] ?? [];

            $seen = [];
            foreach ($rows as $r) {
                $sid    = (int) $r['id'];
                $name   = trim((string) $r['name']);
                $active = (int) ($r['is_active'] ?? 1);
                $seen[$sid] = true;

                if ($name === '') continue;

                if (!isset($existing[$sid])) {
                    // جديد
                    $insert->execute([$pid, $name, $type, $src, $sid, $active]);
                    $changes++;
                } else {
                    // موجود — نحدّث بس لو الاسم أو الحالة اتغيروا
                    $cur = $existing[$sid];
                    if ($cur['name'] !== $name || (int) $cur['is_active'] !== $active) {
                        $update->execute([$name, $active, (int) $cur['id']]);
                        $changes++;
                    }
                }
            }

            // اللي اتشال من المصدر خالص → نوقفه بس (ما نمسحوش)
            foreach ($existing as $sid => $e) {
                if (!isset($seen[$sid]) && (int) $e['is_active'] === 1) {
                    $update->execute([$e['name'], 0, (int) $e['id']]);
                    $changes++;
                }
            }
        }
    } catch (Throwable $ex) {
        error_log('sync_dynamic_accounts: ' . $ex->getMessage());
        return 0;
    }

    return $changes;
}

/**
 * اسم الحساب بالمسار الكامل (أب ← ابن).
 */
function account_path(?int $id): ?string
{
    if (!$id) return null;
    foreach (accounts_tree() as $a) {
        if ((int) $a['id'] === $id) return $a['path'];
    }
    return null;
}

/**
 * تحديد حساب لقيد موجود (للمالك بس — القيود القديمة من غير حساب).
 */
function set_journal_account(int $entryId, ?int $accountId): void
{
    db()->prepare('UPDATE journal_entries SET account_id = ? WHERE id = ?')
        ->execute([$accountId ?: null, $entryId]);
}

/**
 * استلام أو صرف نقدية مباشر على خزينة.
 *
 * @param string $type      'receipt' (استلام/دائن) أو 'disbursement' (صرف/مدين)
 * @param int|null $branchId فرع التكلفة — القيد اتحسب على أنهي فرع.
 *                           ⚠️ تصنيف تحليلي بحت، ملهوش علاقة بخزينة العهدة
 *                           ولا بالفرع المستلم في issue_disbursement_order.
 * @return int معرّف الحركة في treasury_movements
 */
function treasury_movement(
    int $treasuryId,
    string $type,
    float $amount,
    string $date,
    ?string $description = null,
    ?int $userId = null,
    ?int $entryCodeId = null,
    ?int $branchId = null,
    ?string $notes = null
): int {
    $date = fin_valid_date($date);
    if ($amount <= 0) {
        throw new InvalidArgumentException('المبلغ لازم يكون أكبر من صفر.');
    }
    if (!in_array($type, ['receipt', 'disbursement'], true)) {
        throw new InvalidArgumentException('نوع الحركة غير صحيح.');
    }

    // ===== خزينة الكاش: استلام بس =====
    // مبيعات الفروع بتتصب هنا، والفلوس بتخرج منها بـ**تحويل** للرئيسية
    // وبس. كل صرف بيطلع من الرئيسية.
    //
    // المنع هنا في طبقة الفلوس مش في الواجهة عن قصد: إخفاء الحقل بيمنع
    // الضغطة، مش بيمنع طلب POST متزوّر ولا كود تاني بينادي الدالة.
    //
    // ⚠️ بيمنع `treasury_movement` بس — التحويلات
    // (`transfer_between_treasuries`) بتعدّي عادي لأنها بتنادي
    // `post_journal` مباشرةً، وده مقصود: التحويل من الكاش للرئيسية لازم
    // يفضل شغّال.
    if ($type === 'disbursement' && !(site_id() === 'ashraqa' && site_profile()['allow_cash_disbursement'])) {
        $tr = db()->prepare('SELECT type FROM treasuries WHERE id = ?');
        $tr->execute([$treasuryId]);
        if (($tr->fetchColumn() ?: '') === 'cash') {
            throw new RuntimeException(
                'لا يمكن الصرف من خزينة الكاش، هذه الخزينة مخصصة لاستلام وصب '
                . 'مبيعات الفروع. الصرف بيتم من الخزينة الرئيسية.'
            );
        }
    }

    $notes = ($notes !== null && trim($notes) !== '') ? trim($notes) : null;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO treasury_movements
                (treasury_id, movement_type, amount, entry_code_id, branch_id,
                 movement_date, description, created_by, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$treasuryId, $type, $amount, $entryCodeId, $branchId,
                    $date, $description, $userId, $notes]);
        $movementId = (int) $pdo->lastInsertId();

        // القيد المختار = الحساب من الشجرة، بيتسجّل في القيد نفسه
        if ($type === 'receipt') {
            post_journal($treasuryId, 0, $amount, $description ?? 'استلام نقدية', $date,
                         'receipt', $movementId, $userId, $pdo, $entryCodeId, $notes);
        } else {
            post_journal($treasuryId, $amount, 0, $description ?? 'صرف نقدية', $date,
                         'disbursement', $movementId, $userId, $pdo, $entryCodeId, $notes);
        }

        $pdo->commit();
        return $movementId;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}

/**
 * تعديل قيد بعد الحفظ — (القيد · البيان · الملاحظات) بس.
 * =====================================================================
 *
 * 🔴 الدالة دي **مابتلمسش فلوس نهائياً**:
 *    مفيش debit ولا credit ولا running_balance ولا treasuries.balance
 *    في أي سطر هنا. ولا هتتضاف. قرار محمد 2026-08-22.
 *
 *    السبب: running_balance رصيد **تراكمي مخزّن** في كل صف. تعديل مبلغ
 *    قيد قديم بيخلّي كل الصفوف اللي بعده في نفس الخزينة غلط، وكمان
 *    treasuries.balance، وكمان الجداول المرتبطة (branch_custody /
 *    shifts / disbursement_orders) اللي مش هتعرف إن حاجة اتغيّرت.
 *    تصحيح المبالغ بيتم **بقيد عكسي** زي delete_custody_expense —
 *    RULES.md §٤.
 *
 * بتحدّث الجدولين مع بعض في transaction واحد:
 *   journal_entries    → account_id · description · notes
 *   treasury_movements → entry_code_id · description · notes
 *     (بس لو القيد أصله حركة صرف/استلام — عشان الإيصال يفضل مطابق
 *      للكشف، وعشان فلتر «القيد» في تقرير الخزينة بيقرا من
 *      tm.entry_code_id فلو حدّثنا واحد بس الفلتر يبوظ.)
 *
 * وبتسجّل الأثر (edited_at / edited_by) — أي تعديل بعد الحفظ لازم
 * يسيب علامة حتى لو تعديل نص.
 *
 * @param int         $entryId     journal_entries.id
 * @param int|null    $accountId   الحساب من الشجرة (null = بدون قيد)
 * @param string      $description البيان (مايصحّش يبقى فاضي)
 * @param string|null $notes       الملاحظات (فاضي = NULL)
 * @param int|null    $userId      اللي بيعدّل
 *
 * @return array بيانات الصف بعد التعديل — للواجهة تحدّث الصف من غير ريفريش
 * @throws RuntimeException لو القيد مش موجود أو البيان فاضي
 */
function update_journal_entry(
    int $entryId,
    ?int $accountId,
    string $description,
    ?string $notes = null,
    ?int $userId = null
): array {
    $description = trim($description);
    if ($entryId <= 0)     throw new RuntimeException('قيد غير صحيح.');
    if ($description === '') throw new RuntimeException('البيان مايصحّش يكون فاضي.');
    if (mb_strlen($description) > 255) {
        // العمود varchar(255) — بنقص بدل ما الاستعلام يرمي خطأ
        $description = mb_substr($description, 0, 255);
    }
    $notes = ($notes !== null && trim($notes) !== '') ? trim($notes) : null;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // الصف الأصلي — محتاجين ref_type/ref_id عشان نعرف نحدّث الحركة كمان
        $st = $pdo->prepare('SELECT ref_type, ref_id FROM journal_entries WHERE id = ? FOR UPDATE');
        $st->execute([$entryId]);
        $row = $st->fetch();
        if (!$row) throw new RuntimeException('القيد ده مش موجود.');

        // لو الحساب اتبعت، نتأكد إنه موجود فعلاً (مش رقم من الهوا)
        if ($accountId !== null) {
            $chk = $pdo->prepare('SELECT COUNT(*) FROM accounts WHERE id = ?');
            $chk->execute([$accountId]);
            if ((int) $chk->fetchColumn() === 0) {
                throw new RuntimeException('القيد المختار مش موجود في شجرة الحسابات.');
            }
        }

        $pdo->prepare(
            'UPDATE journal_entries
                SET account_id = ?, description = ?, notes = ?,
                    edited_at = NOW(), edited_by = ?
              WHERE id = ?'
        )->execute([$accountId, $description, $notes, $userId, $entryId]);

        // الحركة الأصلية (صرف/استلام) — نفس القيم عشان الجدولين مايفترقوش
        if (in_array($row['ref_type'], ['receipt', 'disbursement'], true) && !empty($row['ref_id'])) {
            $pdo->prepare(
                'UPDATE treasury_movements
                    SET entry_code_id = ?, description = ?, notes = ?
                  WHERE id = ?'
            )->execute([$accountId, $description, $notes, (int) $row['ref_id']]);
        }

        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }

    // بنرجّع اسم الحساب الكامل (المسار) عشان الواجهة تحطه في الصف على طول
    return [
        'id'          => $entryId,
        'account_id'  => $accountId,
        'entry_code'  => $accountId !== null ? (account_path($accountId) ?? '') : '',
        'description' => $description,
        'notes'       => $notes ?? '',
    ];
}

/**
 * تعديل تاريخ حركة صرف أو استلام — قيد عكسي في اليومية + تحديث تاريخ الحركة.
 * =====================================================================
 *
 * 🔴 الأساس المحاسبي للتصميم:
 *    ١) اليومية (journal_entries): التعديل بيتعمل بقيد عكسي وقيد جديد
 *       مش بـ UPDATE، لأن running_balance رصيد تراكمي متخزّن وقت التسجيل
 *       في كل صف، وكشف الحساب بيترتب بـ je.id DESC مش بالتاريخ.
 *       الـ UPDATE على entry_date بيبوّظ تسلسل الأرصدة التراكمية.
 *    ٢) سجل الحركة (treasury_movements): بيتحدّث تاريخه مباشرةً بـ UPDATE
 *       بدل ما يتعكس، لأنه جدول مسطّح مالوش رصيد تراكمي، وتقرير المصاريف
 *       بيفلتر بـ tm.movement_type = 'disbursement' فقط. لو عملنا حركة
 *       استلام عكسية مش هتبان في تقرير المصاريف والتقرير هيحسب المصروف
 *       مرتين (عد مزدوج). الـ UPDATE هنا هو الصح والآمن.
 *
 * المنطق جوّه transaction واحدة ذرّياً:
 *   ١) قفل صف journal_entries بـ FOR UPDATE والتأكد إنه receipt أو disbursement.
 *   ٢) قراءة صف treasury_movements المرتبط بـ FOR UPDATE.
 *   ٣) حارس: التأكد إن entry_date في القيد مطابق لـ movement_date في الحركة
 *      (منع تعديل صف قديم اتعكس قبل كده).
 *   ٤) تحديث movement_date في صف treasury_movements الأصلي للتاريخ الجديد.
 *   ٥) قيد عكسي في اليومية بالتاريخ القديم (المدين والدائن متبادلين، ref_type='date_fix_rev'، ref_id=$refId).
 *   ٦) قيد جديد في اليومية بالتاريخ الجديد (نفس المدين والدائن الأصليين، ref_type الأصلي، ref_id=$refId).
 *   ٧) تسجيل الأثر على القيد الأصلي (edited_at / edited_by) مع بقائه مكانه.
 *
 * @param int      $entryId  معرّف القيد الأصلي في journal_entries
 * @param string   $newDate  التاريخ الجديد (Y-m-d)
 * @param int|null $userId   المستخدم اللي بيعدّل
 *
 * @return array ['changed' => bool, 'new_entry_id' => int, 'rev_entry_id' => int, 'old_date' => string, 'new_date' => string]
 * @throws RuntimeException|InvalidArgumentException لو القيد مش موجود أو مش صرف/استلام أو التاريخ غير صالح أو القيد قديم
 */
function change_movement_date(
    int $entryId,
    string $newDate,
    ?int $userId = null
): array {
    $newDate = trim($newDate);
    if ($entryId <= 0) {
        throw new RuntimeException('قيد غير صحيح.');
    }
    if ($newDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate)) {
        throw new InvalidArgumentException('التاريخ غير صالح.');
    }
    [$y, $m, $d] = explode('-', $newDate);
    if (!checkdate((int) $m, (int) $d, (int) $y)) {
        throw new InvalidArgumentException('التاريخ غير صالح.');
    }

    // نفس مدى حركة الخزينة (بريف ٣٩): مش بعد النهاردة ولا أقدم من ٤٠٠ يوم (بريف ٥١)
    $newDate = fin_valid_date($newDate);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // 1. اقرا القيد بـ FOR UPDATE
        $jeStmt = $pdo->prepare('SELECT * FROM journal_entries WHERE id = ? FOR UPDATE');
        $jeStmt->execute([$entryId]);
        $je = $jeStmt->fetch();
        if (!$je) {
            throw new RuntimeException('القيد ده مش موجود.');
        }

        $refType = (string) ($je['ref_type'] ?? '');
        if (!in_array($refType, ['receipt', 'disbursement'], true)) {
            throw new RuntimeException(
                'تاريخ القيد ده جاي من الموديول بتاعه (وردية / عهدة / راتب / تحويل / فاتورة مورد) ويتعدّل من هناك.'
            );
        }

        $refId = (int) ($je['ref_id'] ?? 0);
        if ($refId <= 0) {
            throw new RuntimeException('حركة الخزينة المرتبطة بالقيد غير موجودة.');
        }

        // substr(0,10): لو العمود اتغيّر يوم من الأيام لـDATETIME، المقارنة
        // النصية كانت هتفشل دايماً والفيتشر يقع. بناخد الجزء التاريخي وبس.
        $oldDate = substr((string) $je['entry_date'], 0, 10);
        if ($newDate === $oldDate) {
            $pdo->commit();
            return [
                'changed'  => false,
                'old_date' => $oldDate,
                'new_date' => $newDate,
            ];
        }

        // 2. اقرا صف treasury_movements المرتبط
        $tmStmt = $pdo->prepare('SELECT * FROM treasury_movements WHERE id = ? FOR UPDATE');
        $tmStmt->execute([$refId]);
        $tm = $tmStmt->fetch();
        if (!$tm) {
            throw new RuntimeException('صف حركة الخزينة المرتبط مش موجود.');
        }

        // 3. حارس: التأكد إن القيد ده هو الصف الحي مش صف قديم اتعكس قبل كده
        if ($oldDate !== substr((string) $tm['movement_date'], 0, 10)) {
            throw new RuntimeException('القيد ده اتعدّل تاريخه قبل كده وبقى صف قديم، التعديل بيتعمل من الصف الجديد.');
        }

        $treasuryId = (int) $je['treasury_id'];
        $accountId  = $je['account_id'] !== null ? (int) $je['account_id'] : null;
        $desc       = (string) ($je['description'] ?? '');
        $notes      = ($je['notes'] !== null && trim($je['notes']) !== '') ? trim($je['notes']) : null;
        $debit      = (float) $je['debit'];
        $credit     = (float) $je['credit'];

        // 4. تحديث تاريخ صف treasury_movements الأصلي بالتاريخ الجديد (مفيش INSERT)
        $pdo->prepare('UPDATE treasury_movements SET movement_date = ? WHERE id = ?')
            ->execute([$newDate, $refId]);

        $revDesc = 'إلغاء (تصحيح تاريخ): ' . $desc;
        if (mb_strlen($revDesc) > 255) {
            $revDesc = mb_substr($revDesc, 0, 255);
        }

        // 5. قيد عكسي في اليومية بالتاريخ القديم (المدين والدائن متبادلين)
        $revEntryId = post_journal(
            $treasuryId,
            $credit, // المدين الجديد = الدائن الأصلي
            $debit,  // الدائن الجديد = المدين الأصلي
            $revDesc,
            $oldDate,
            'date_fix_rev',
            $refId,
            $userId,
            $pdo,
            $accountId,
            $notes
        );

        // 6. قيد جديد في اليومية بالتاريخ الجديد (نفس المدين والدائن الأصليين)
        $newEntryId = post_journal(
            $treasuryId,
            $debit,
            $credit,
            $desc,
            $newDate,
            $refType, // 'receipt' أو 'disbursement'
            $refId,
            $userId,
            $pdo,
            $accountId,
            $notes
        );

        // 7. سجل الأثر على القيد الأصلي (يفضل مكانه بتاريخه ومبلغه)
        $pdo->prepare(
            'UPDATE journal_entries
                SET edited_at = NOW(), edited_by = ?
              WHERE id = ?'
        )->execute([$userId, $entryId]);

        $pdo->commit();

        return [
            'changed'      => true,
            'new_entry_id' => $newEntryId,
            'rev_entry_id' => $revEntryId,
            'old_date'     => $oldDate,
            'new_date'     => $newDate,
        ];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}

/**
 * هل أعمدة القيد العكسي موجودة في جدول treasury_movements؟
 * للحماية لو ملف ترقية الداتابيز لسه ما نزلش.
 */
function treasury_movements_has_reverse_cols(PDO $pdo): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    try {
        $st = $pdo->query("SHOW COLUMNS FROM treasury_movements LIKE 'reversed_at'");
        $has = ($st && $st->rowCount() > 0);
    } catch (Throwable $e) {
        $has = false;
    }
    return $has;
}

/**
 * قيد عكسي لحركة صرف أو استلام يدوي في الخزينة.
 *
 * القواعد:
 *  - استلام يتعكس بصرف بنفس المبلغ، وصرف يتعكس باستلام.
 *  - القيم (المبلغ والخزينة والحساب والملاحظات والتاريخ) بتتاخد من treasury_movements مباشرة.
 *  - بينادي post_journal مباشرة لتحديث الرصيد التراكمي ورصيد الخزينة، ومفيش صف جديد في treasury_movements.
 *  - بيتسجّل وقت ومستخدم القيد العكسي في صف treasury_movements الأصلي.
 *
 * @param int $journalEntryId  معرّف قيد اليومية المراد عكسه
 * @param int $userId          معرّف المستخدم اللي بينفّذ العملية
 * @return int                 معرّف القيد العكسي المنشأ في journal_entries
 * @throws RuntimeException    لو القيد مش موجود أو مش صرف/استلام أو اتعكس قبل كده
 */
function reverse_treasury_movement(int $journalEntryId, int $userId): int
{
    if ($journalEntryId <= 0) {
        throw new RuntimeException('قيد غير صحيح.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // 1. اقرا القيد بـ FOR UPDATE
        $jeStmt = $pdo->prepare('SELECT * FROM journal_entries WHERE id = ? FOR UPDATE');
        $jeStmt->execute([$journalEntryId]);
        $je = $jeStmt->fetch();
        if (!$je) {
            throw new RuntimeException('القيد ده مش موجود.');
        }

        $refType = (string) ($je['ref_type'] ?? '');
        if (!in_array($refType, ['receipt', 'disbursement'], true)) {
            throw new RuntimeException('القيد ده مايتعكسش من هنا — ليه طريقة إرجاع خاصة.');
        }

        $refId = (int) ($je['ref_id'] ?? 0);
        if ($refId <= 0) {
            throw new RuntimeException('حركة الخزينة المرتبطة بالقيد غير موجودة.');
        }

        // 2. اقرا صف حركة الخزينة الأصلي بـ FOR UPDATE
        $tmStmt = $pdo->prepare('SELECT * FROM treasury_movements WHERE id = ? FOR UPDATE');
        $tmStmt->execute([$refId]);
        $tm = $tmStmt->fetch();
        if (!$tm) {
            throw new RuntimeException('صف حركة الخزينة المرتبط مش موجود.');
        }

        // التأكد إنه ما اتعكسش قبل كده
        if (!empty($tm['reversed_at'])) {
            throw new RuntimeException('القيد ده اتعكس قبل كده.');
        }

        // 3. القيم من treasury_movements مش من الـ je
        $treasuryId   = (int) $tm['treasury_id'];
        $amount       = (float) $tm['amount'];
        $accountId    = $tm['entry_code_id'] !== null ? (int) $tm['entry_code_id'] : null;
        $notes        = ($tm['notes'] !== null && trim((string) $tm['notes']) !== '') ? trim((string) $tm['notes']) : null;
        $movementDate = substr((string) $tm['movement_date'], 0, 10);
        $movementType = (string) $tm['movement_type'];

        $rawDesc = trim((string) ($tm['description'] ?? ''));
        if ($rawDesc === '') {
            $rawDesc = ($movementType === 'disbursement') ? 'صرف نقدية' : 'استلام نقدية';
        }
        $revDesc = 'قيد عكسي — ' . $rawDesc;
        if (mb_strlen($revDesc) > 255) {
            $revDesc = mb_substr($revDesc, 0, 255);
        }

        // 4. قيد عكسي مباشر بـ post_journal
        // - صرف: كان خارج (مدين) ← العكس دائن (الفلوس رجعت)
        // - استلام: كان داخل (دائن) ← العكس مدين (الفلوس خرجت)
        if ($movementType === 'disbursement') {
            $revEntryId = post_journal(
                $treasuryId,
                0,        // debit
                $amount,  // credit
                $revDesc,
                $movementDate,
                'reversal',
                $refId,
                $userId,
                $pdo,
                $accountId,
                $notes
            );
        } elseif ($movementType === 'receipt') {
            $revEntryId = post_journal(
                $treasuryId,
                $amount,  // debit
                0,        // credit
                $revDesc,
                $movementDate,
                'reversal',
                $refId,
                $userId,
                $pdo,
                $accountId,
                $notes
            );
        } else {
            throw new RuntimeException('نوع حركة الخزينة غير معروف.');
        }

        // 5. تحديث صف treasury_movements بعلامة العكس
        $upStmt = $pdo->prepare(
            'UPDATE treasury_movements
                SET reversed_at = NOW(),
                    reversed_by = ?,
                    reversal_entry_id = ?
              WHERE id = ? AND reversed_at IS NULL'
        );
        $upStmt->execute([$userId, $revEntryId, $refId]);
        if ($upStmt->rowCount() !== 1) {
            throw new RuntimeException('القيد ده اتعكس في نفس اللحظة من مستخدم تاني.');
        }

        $pdo->commit();
        return $revEntryId;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}

/**
 * رصيد خزينة حالي.
 */
function treasury_balance(int $treasuryId): float
{
    $stmt = db()->prepare('SELECT balance FROM treasuries WHERE id = ?');
    $stmt->execute([$treasuryId]);
    return (float) $stmt->fetchColumn();
}

/**
 * الاسم العربي لنوع الخزينة.
 */
function treasury_type_label(string $type): string
{
    return [
        'cash'             => 'كاش',
        'visa'             => 'فيزا',
        'instapay'         => 'إنستا باي',
        'card'             => 'كارت',
        'branch_custody'   => 'عهدة فرع',
        'employee_custody' => 'عهدة موظف',
        'main'             => 'رئيسية',
    ][$type] ?? $type;
}

/* =====================================================================
 *  دوال العهدة
 * ===================================================================== */

/**
 * الخزينة الرئيسية — كل فلوس **خارجة** بتطلع منها.
 * =====================================================================
 *  إذون عهدة الفروع · سلف الموظفين · كل الصرف اليدوي.
 *
 *  ⚠️ كانت `main_cash_treasury_id()` بترجّع أول خزينة `cash`، يعني كل
 *     ده كان بيطلع من **خزينة الكاش**. اتغيّر يوم 2026-08-22:
 *
 *       خزينة الكاش  = استلام مبيعات الفروع + تحويل للرئيسية. وبس.
 *       الخزينة الرئيسية = كل الصرف.
 *
 *  الدالة القديمة اتسابت تحت عشان أي كود تاني مايتكسرش، بس مبقاش
 *  فيه حاجة بتناديها في المسارات المالية.
 */
function main_treasury_id(): ?int
{
    $id = db()->query("SELECT id FROM treasuries WHERE type='main' AND is_active=1 ORDER BY id LIMIT 1")
              ->fetchColumn();
    return $id ? (int) $id : null;
}

/**
 * زي `main_treasury_id()` بس بترمي خطأ واضح بدل ما ترجّع null —
 * عشان المستخدم يفهم المشكلة بدل رسالة عامة.
 */
function require_main_treasury(): int
{
    $id = main_treasury_id();
    if ($id === null) {
        throw new RuntimeException(
            'مفيش خزينة رئيسية نشطة. ضيفها من التعريفات ← الخزائن (النوع: رئيسية).'
        );
    }
    return $id;
}

/**
 * أول خزينة كاش نشطة — **بتستقبل بس دلوقتي**.
 * تقفيل الكاشير بيصب فيها. مفيش صرف بيطلع منها.
 */
function main_cash_treasury_id(): ?int
{
    $id = db()->query("SELECT id FROM treasuries WHERE type='cash' AND is_active=1 ORDER BY id LIMIT 1")
              ->fetchColumn();
    return $id ? (int) $id : null;
}

/**
 * العهدة المفتوحة الحالية لفرع (status=open). بترجّع صف أو null.
 */
function open_custody_for_branch(int $branchId): ?array
{
    $st = db()->prepare("SELECT * FROM branch_custody WHERE branch_id = ? AND employee_id IS NULL AND status='open' ORDER BY id DESC LIMIT 1");
    $st->execute([$branchId]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * العهدة الحالية للفرع أياً كانت حالتها طول ما هي مش مقفولة.
 * (open / pending_review / rejected)
 */
function current_custody_for_branch(int $branchId): ?array
{
    $st = db()->prepare(
        "SELECT * FROM branch_custody
         WHERE branch_id = ? AND employee_id IS NULL AND status IN ('open','pending_review','rejected')
         ORDER BY id DESC LIMIT 1"
    );
    $st->execute([$branchId]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * العهدة المفتوحة الحالية لموظف (status=open). بترجّع صف أو null.
 */
function open_custody_for_employee(int $employeeId): ?array
{
    $st = db()->prepare("SELECT * FROM branch_custody WHERE employee_id = ? AND status='open' ORDER BY id DESC LIMIT 1");
    $st->execute([$employeeId]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * العهدة الحالية للموظف أياً كانت حالتها طول ما هي مش مقفولة.
 * (open / pending_review / rejected)
 */
function current_custody_for_employee(int $employeeId): ?array
{
    $st = db()->prepare(
        "SELECT * FROM branch_custody
         WHERE employee_id = ? AND status IN ('open','pending_review','rejected')
         ORDER BY id DESC LIMIT 1"
    );
    $st->execute([$employeeId]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * إيجاد حساب عهدة الفرع تحت حساب «عهدة فرع» (73).
 * لو الفرع مالوش ابن تحت 73، نرجع 73 نفسه.
 */
function get_branch_custody_account(int $branchId, ?PDO $pdo = null): int
{
    $pdo = $pdo ?? db();
    $parentStmt = $pdo->query("SELECT id FROM accounts WHERE id = 73 OR (parent_id IS NULL AND name = 'عهدة فرع' AND is_active = 1) ORDER BY (id = 73) DESC LIMIT 1");
    $parentId = (int) $parentStmt->fetchColumn();
    if (!$parentId) {
        $parentId = 73;
    }

    if ($branchId > 0) {
        // فحص لو فيه حساب فرعي تحت 73 باسم الفرع
        $brStmt = $pdo->prepare("SELECT name FROM branches WHERE id = ? LIMIT 1");
        $brStmt->execute([$branchId]);
        $brName = $brStmt->fetchColumn();
        if ($brName) {
            $chk = $pdo->prepare("SELECT id FROM accounts WHERE parent_id = ? AND is_active = 1 AND (name = ? OR name LIKE ?) LIMIT 1");
            $chk->execute([$parentId, $brName, '%' . $brName . '%']);
            $accId = (int) $chk->fetchColumn();
            if ($accId > 0) {
                return $accId;
            }
        }
    }

    return $parentId;
}

/**
 * إيجاد أو إنشاء حساب العهدة للموظف تحت حساب «عهدة موظف» (204).
 *
 * @param int $employeeId معرّف الموظف في جدول employees
 * @param string|null $employeeName اسم الموظف
 * @param PDO|null $pdo
 * @return int account_id في جدول accounts
 */
function get_or_create_employee_custody_account(int $employeeId, ?string $employeeName = null, ?PDO $pdo = null): int
{
    $pdo = $pdo ?? db();

    if ($employeeName === null || trim($employeeName) === '') {
        $st = $pdo->prepare("SELECT full_name FROM employees WHERE id = ? LIMIT 1");
        $st->execute([$employeeId]);
        $employeeName = (string) $st->fetchColumn();
    }
    $cleanName = trim((string) $employeeName);
    if ($cleanName === '') {
        throw new RuntimeException("اسم الموظف غير صالح لإنشاء حساب عهدة (id={$employeeId})");
    }

    // 1. إيجاد الحساب الأب «عهدة موظف» (الافتراضي 204)
    $parentStmt = $pdo->query("SELECT id FROM accounts WHERE id = 204 OR (parent_id IS NULL AND name = 'عهدة موظف' AND is_active = 1) ORDER BY (id = 204) DESC LIMIT 1");
    $parentId = (int) $parentStmt->fetchColumn();
    if (!$parentId) {
        throw new RuntimeException('حساب «عهدة موظف» (204) غير موجود في شجرة الحسابات');
    }

    // 2. البحث عن حساب الموظف تحت الأب 204
    if ($employeeId > 0) {
        $st = $pdo->prepare("SELECT id FROM accounts WHERE parent_id = ? AND is_active = 1 AND src_table = 'employees' AND src_id = ? LIMIT 1");
        $st->execute([$parentId, $employeeId]);
        $accId = (int) $st->fetchColumn();
        if ($accId > 0) return $accId;
    }

    $st = $pdo->prepare("SELECT id FROM accounts WHERE parent_id = ? AND is_active = 1 AND name = ? LIMIT 1");
    $st->execute([$parentId, $cleanName]);
    $accId = (int) $st->fetchColumn();
    if ($accId > 0) return $accId;

    // معالجة الفروق الإملائية (ى / ي) و(أ / ا)
    $st = $pdo->prepare("SELECT id, name FROM accounts WHERE parent_id = ? AND is_active = 1");
    $st->execute([$parentId]);
    $normClean = strtr($cleanName, ['ى' => 'ي', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $normRow = strtr($row['name'], ['ى' => 'ي', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا']);
        if ($normClean === $normRow) {
            return (int) $row['id'];
        }
    }

    // 3. لو مش موجود: أنشئه بنفس الطريقة اللي اتعمل بيها 205/206/240
    $ins = $pdo->prepare(
        "INSERT INTO accounts (parent_id, name, type, is_custody, is_active)
         VALUES (?, ?, 'expense', 0, 1)"
    );
    $ins->execute([$parentId, $cleanName]);
    return (int) $pdo->lastInsertId();
}

/**
 * إصدار إذن صرف: الكاش الرئيسي → خزينة عهدة الفرع.
 * - بيسجل الإذن في disbursement_orders.
 * - بيحرّك الفلوس (مدين الكاش، دائن العهدة) عبر post_journal.
 * - بيفتح عهدة (branch_custody) للفرع لو مفيش وحدة مفتوحة، أو بيزوّد أول المدة لو فيه.
 *
 * @return int معرّف الإذن
 */
function issue_disbursement_order(
    int $branchId,
    int $custodyTreasuryId,
    float $amount,
    string $date,
    ?string $notes = null,
    ?int $userId = null,
    ?int $sourceTreasuryId = null
): int {
    if ($amount <= 0) {
        throw new InvalidArgumentException('مبلغ الإذن لازم يكون أكبر من صفر.');
    }
    $pdo = db();
    if ($sourceTreasuryId !== null) {
        $stChk = $pdo->prepare("SELECT id, type, is_active FROM treasuries WHERE id = ? LIMIT 1");
        $stChk->execute([$sourceTreasuryId]);
        $tRow = $stChk->fetch(PDO::FETCH_ASSOC);
        if (!$tRow || (int)$tRow['is_active'] !== 1 || in_array($tRow['type'], ['cash', 'branch_custody', 'employee_custody'], true)) {
            throw new RuntimeException('الخزينة دي ماينفعش يطلع منها إذن عهدة.');
        }
    }
    // إذون العهدة بقت تطلع من الخزينة الرئيسية مش من خزينة الكاش
    // (2026-08-22). خزينة الكاش بقت استلام وتحويل بس.
    // الافتراضي الرئيسية (قرار ٢٢ أغسطس) — إذن إنستا بيبعت خزينته
    $cashId = $sourceTreasuryId ?? require_main_treasury();

    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        // اقفل العهدة الحالية قبل أي حركة مالية: المفتوحة/المرفوضة تتغذى، والمقدّمة تتوقف للمراجعة.
        $custodyStmt = $pdo->prepare(
            "SELECT id, status, opening_balance, total_expenses
               FROM branch_custody
              WHERE branch_id=? AND employee_id IS NULL AND status <> 'closed'
              ORDER BY (status = 'pending_review') DESC, id DESC LIMIT 1 FOR UPDATE"
        );
        $custodyStmt->execute([$branchId]);
        $currentCustody = $custodyStmt->fetch();
        if ($currentCustody && $currentCustody['status'] === 'pending_review') {
            throw new RuntimeException('فيه عهدة للفرع ده مستنية الاعتماد — اعتمدها أو ارفضها الأول. العهدة مقدّمة للمراجعة.');
        }

        // تسجيل الإذن
        $pdo->prepare(
            'INSERT INTO disbursement_orders (branch_id, custody_treasury_id, amount, order_date, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$branchId, $custodyTreasuryId, $amount, $date, $notes, $userId]);
        $orderId = (int) $pdo->lastInsertId();

        // حساب العهدة للفرع تحت 73 (لو مفيش ابن، 73 نفسه)
        $branchAccountId = get_branch_custody_account($branchId, $pdo);

        // حركة الفلوس: مدين الكاش، دائن العهدة
        post_journal($cashId, $amount, 0, 'إذن صرف لعهدة الفرع' . ($notes ? " - $notes" : ''),
                     $date, 'disbursement_order', $orderId, $userId, $pdo, $branchAccountId);
        post_journal($custodyTreasuryId, 0, $amount, 'استلام إذن صرف' . ($notes ? " - $notes" : ''),
                     $date, 'disbursement_order', $orderId, $userId, $pdo);

        // فتح عهدة جديدة فقط لو كل العهد السابقة مقفولة؛ المفتوحة أو المرفوضة تتغذى كما هي.
        if ($currentCustody) {
            $newOpening = (float)$currentCustody['opening_balance'] + $amount;
            $newClosing = $newOpening - (float)$currentCustody['total_expenses'];
            $pdo->prepare('UPDATE branch_custody SET opening_balance=?, closing_balance=? WHERE id=?')
                ->execute([$newOpening, $newClosing, $currentCustody['id']]);
        } else {
            // افتح عهدة جديدة (آخر المدة = أول المدة، مفيش مصروفات بعد)
            $pdo->prepare(
                'INSERT INTO branch_custody
                    (branch_id, custody_treasury_id, period_start, opening_balance, total_expenses, closing_balance, status)
                 VALUES (?, ?, ?, ?, 0, ?, "open")'
            )->execute([$branchId, $custodyTreasuryId, $date, $amount, $amount]);
        }

        if ($own) $pdo->commit();
        return $orderId;
    } catch (Throwable $ex) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * إصدار إذن صرف لعهدة موظف: الكاش الرئيسي → خزينة عهدة الموظف.
 * - بيسجل الإذن في disbursement_orders برقم الموظف.
 * - بيحرّك الفلوس (مدين الكاش، دائن العهدة) عبر post_journal.
 * - بيفتح عهدة (branch_custody) للموظف لو مفيش وحدة مفتوحة، أو بيزوّد أول المدة لو فيه.
 *
 * @return int معرّف الإذن
 */
function issue_employee_disbursement_order(
    int $employeeId,
    float $amount,
    string $date,
    ?string $notes = null,
    ?int $userId = null,
    ?int $sourceTreasuryId = null
): int {
    if ($amount <= 0) {
        throw new InvalidArgumentException('مبلغ الإذن لازم يكون أكبر من صفر.');
    }
    $pdo = db();
    if ($sourceTreasuryId !== null) {
        $stChk = $pdo->prepare("SELECT id, type, is_active FROM treasuries WHERE id = ? LIMIT 1");
        $stChk->execute([$sourceTreasuryId]);
        $tRow = $stChk->fetch(PDO::FETCH_ASSOC);
        if (!$tRow || (int)$tRow['is_active'] !== 1 || in_array($tRow['type'], ['cash', 'branch_custody', 'employee_custody'], true)) {
            throw new RuntimeException('الخزينة دي ماينفعش يطلع منها إذن عهدة.');
        }
    }
    // الافتراضي الرئيسية (قرار ٢٢ أغسطس) — إذن إنستا بيبعت خزينته
    $cashId = $sourceTreasuryId ?? require_main_treasury();

    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        // التحقق من وجود الموظف
        $empStmt = $pdo->prepare("SELECT id, full_name, branch_id FROM employees WHERE id=? AND is_active=1 LIMIT 1");
        $empStmt->execute([$employeeId]);
        $emp = $empStmt->fetch();
        if (!$emp) {
            throw new RuntimeException('الموظف غير موجود أو غير نشط.');
        }
        $empName = $emp['full_name'];
        $empBranchId = !empty($emp['branch_id']) ? (int) $emp['branch_id'] : null;

        // اقفل العهدة الحالية قبل أي حركة مالية: المفتوحة/المرفوضة تتغذى، والمقدّمة تتوقف للمراجعة.
        $custodyStmt = $pdo->prepare(
            "SELECT id, status, opening_balance, total_expenses
               FROM branch_custody
              WHERE employee_id=? AND status <> 'closed'
              ORDER BY (status = 'pending_review') DESC, id DESC LIMIT 1 FOR UPDATE"
        );
        $custodyStmt->execute([$employeeId]);
        $currentCustody = $custodyStmt->fetch();
        if ($currentCustody && $currentCustody['status'] === 'pending_review') {
            throw new RuntimeException('فيه عهدة للموظف ده مستنية الاعتماد — اعتمدها أو ارفضها الأول. العهدة مقدّمة للمراجعة.');
        }

        // حارس منع التكرار اللحظي (Double click / Debounce):
        $dupChk = $pdo->prepare(
            "SELECT id FROM disbursement_orders 
             WHERE employee_id = ? AND amount = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 SECOND)
             LIMIT 1"
        );
        $dupChk->execute([$employeeId, $amount]);
        if ($dupChk->fetchColumn()) {
            throw new RuntimeException('تم إصدار إذن صرف بنفس المبلغ لهذا الموظف منذ لحظات. يرجى الانتظار قليلاً أو مراجعة كشف الحساب.');
        }

        // إيجاد أو إنشاء خزينة عهدة الموظف
        $trStmt = $pdo->prepare("SELECT id FROM treasuries WHERE type='employee_custody' AND employee_id=? LIMIT 1");
        $trStmt->execute([$employeeId]);
        $custodyTreasuryId = (int) $trStmt->fetchColumn();
        if (!$custodyTreasuryId) {
            $pdo->prepare(
                "INSERT INTO treasuries (name, type, employee_id, branch_id, balance, is_active)
                 VALUES (?, 'employee_custody', ?, ?, 0.00, 1)"
            )->execute(['عهدة: ' . $empName, $employeeId, $empBranchId]);
            $custodyTreasuryId = (int) $pdo->lastInsertId();
        }

        // تسجيل الإذن
        $pdo->prepare(
            'INSERT INTO disbursement_orders (employee_id, branch_id, custody_treasury_id, amount, order_date, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$employeeId, $empBranchId, $custodyTreasuryId, $amount, $date, $notes, $userId]);
        $orderId = (int) $pdo->lastInsertId();

        // حساب العهدة للموظف تحت 204
        $empAccountId = get_or_create_employee_custody_account($employeeId, $empName, $pdo);

        // حركة الفلوس: مدين الكاش، دائن العهدة
        post_journal($cashId, $amount, 0, 'إذن صرف لعهدة موظف: ' . $empName . ($notes ? " - $notes" : ''),
                     $date, 'disbursement_order', $orderId, $userId, $pdo, $empAccountId);
        post_journal($custodyTreasuryId, 0, $amount, 'استلام إذن صرف عهدة' . ($notes ? " - $notes" : ''),
                     $date, 'disbursement_order', $orderId, $userId, $pdo);

        // فتح عهدة جديدة فقط لو كل العهد السابقة مقفولة؛ المفتوحة أو المرفوضة تتغذى كما هي.
        if ($currentCustody) {
            $newOpening = (float)$currentCustody['opening_balance'] + $amount;
            $newClosing = $newOpening - (float)$currentCustody['total_expenses'];
            $pdo->prepare('UPDATE branch_custody SET opening_balance=?, closing_balance=? WHERE id=?')
                ->execute([$newOpening, $newClosing, $currentCustody['id']]);
        } else {
            // افتح عهدة جديدة للموظف
            $pdo->prepare(
                'INSERT INTO branch_custody
                    (employee_id, branch_id, custody_treasury_id, period_start, opening_balance, total_expenses, closing_balance, status)
                 VALUES (?, ?, ?, ?, ?, 0, ?, "open")'
            )->execute([$employeeId, $empBranchId, $custodyTreasuryId, $date, $amount, $amount]);
        }

        if ($own) $pdo->commit();
        return $orderId;
    } catch (Throwable $ex) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * إضافة مصروف على العهدة المفتوحة أو المرفوضة (راجعة للكاشير).
 * بيقلّل آخر المدة (closing_balance) ويطلّع الفلوس فعلياً من خزينة العهدة
 * (مدين على خزينة العهدة) عشان رصيد الخزينة يفضل مطابق للواقع دايماً.
 */
function add_custody_expense(
    int $custodyId,
    string $date,
    string $description,
    float $amount,
    ?int $userId = null,
    ?int $entryCodeId = null,
    string $lineType = 'expense',
    ?int $advEmployeeId = null,
    ?int $supplierId = null,
    ?int $branchId = null
): int {
    if ($amount <= 0) {
        throw new InvalidArgumentException('مبلغ المصروف لازم يكون أكبر من صفر.');
    }
    if (!in_array($lineType, ['expense', 'advance', 'supplier'], true)) {
        $lineType = 'expense';
    }
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        // تأكد إن العهدة مفتوحة أو مرفوضة واقرأ خزينتها
        $st = $pdo->prepare("SELECT status, custody_treasury_id, employee_id, branch_id FROM branch_custody WHERE id=? FOR UPDATE");
        $st->execute([$custodyId]);
        $row = $st->fetch();
        if (!$row || !in_array($row['status'], ['open', 'rejected'], true)) {
            throw new RuntimeException('مينفعش تضيف مصروف لعهدة مقفولة أو مقدّمة للمراجعة.');
        }
        $custodyTreasuryId = (int) $row['custody_treasury_id'];
        $isEmpCustody = !empty($row['employee_id']);
        $branchId = $branchId ?? (int) $row['branch_id'];

        if ($lineType === 'advance') {
            if (!$isEmpCustody) {
                throw new RuntimeException('السلفة من عهدة موظف بس.');
            }
            if (!$advEmployeeId || $advEmployeeId <= 0) {
                throw new RuntimeException('الموظف غير موجود أو غير نشط.');
            }
            $chkEmp = $pdo->prepare('SELECT full_name FROM employees WHERE id = ? AND is_active = 1');
            $chkEmp->execute([$advEmployeeId]);
            $advEmpName = $chkEmp->fetchColumn();
            if ($advEmpName === false) {
                throw new RuntimeException('الموظف غير موجود أو غير نشط.');
            }
            $entryCodeId = null;
            if (trim($description) === '') {
                $description = 'سلفة للموظف: ' . $advEmpName;
            }
        } elseif ($lineType === 'supplier') {
            if (!$isEmpCustody) {
                throw new RuntimeException('دفعة المورد من عهدة موظف بس.');
            }
            if (!$supplierId || $supplierId <= 0) {
                throw new RuntimeException('المورد غير موجود أو غير نشط.');
            }
            $chkSupp = $pdo->prepare('SELECT name FROM suppliers WHERE id = ? AND is_active = 1');
            $chkSupp->execute([$supplierId]);
            $suppName = $chkSupp->fetchColumn();
            if ($suppName === false) {
                throw new RuntimeException('المورد غير موجود أو غير نشط.');
            }
            sync_dynamic_accounts();
            $stAcc = $pdo->prepare("SELECT id FROM accounts WHERE src_table = 'suppliers' AND src_id = ? AND is_active = 1 LIMIT 1");
            $stAcc->execute([$supplierId]);
            $accId = (int) $stAcc->fetchColumn();
            if (!$accId) {
                throw new RuntimeException('المورد ده مالوش حساب في شجرة الحسابات — اتأكد إن فيه أب «موردين» في التعريفات.');
            }
            $entryCodeId = $accId;
            if (trim($description) === '') {
                $description = 'صرف للمورد ' . $suppName;
            }
        } else {
            $advEmployeeId = null;
            $supplierId = null;
        }

        $pdo->prepare(
            'INSERT INTO custody_lines (custody_id, line_type, expense_date, description, amount, entry_code_id, employee_id, supplier_id, branch_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$custodyId, $lineType, $date, $description, $amount, $entryCodeId, $advEmployeeId, $supplierId, $branchId, $userId]);
        $lineId = (int) $pdo->lastInsertId();

        // حدّث الإجماليات المحاسبية (حساب صريح: آخر المدة = أول المدة − المصروفات)
        $cur = $pdo->prepare('SELECT opening_balance, total_expenses FROM branch_custody WHERE id=?');
        $cur->execute([$custodyId]);
        $cr = $cur->fetch();
        $newExp = (float)$cr['total_expenses'] + $amount;
        $newClosing = (float)$cr['opening_balance'] - $newExp;
        $pdo->prepare('UPDATE branch_custody SET total_expenses=?, closing_balance=? WHERE id=?')
            ->execute([$newExp, $newClosing, $custodyId]);

        // الفلوس تطلع فعلياً من خزينة العهدة (مدين) — كشف الحساب يفضل سليم
        // التصنيف المختار = الحساب من الشجرة (بيتمرر آخر السطر تحت)
        post_journal($custodyTreasuryId, $amount, 0, 'مصروف عهدة: ' . $description,
                     $date, 'custody_expense', $lineId, $userId, $pdo, $entryCodeId);

        if ($own) $pdo->commit();
        return $lineId;
    } catch (Throwable $ex) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * إلغاء مصروف عهدة (مصروف اتكتب غلط).
 *
 * ⚠️ مابنمسحش القيد من اليومية.
 *    journal_entries فيه running_balance (رصيد تراكمي محسوب وقت
 *    التسجيل). لو مسحنا قيد من النص، كل القيود اللي بعده يبقى
 *    رصيدها التراكمي غلط وكشف الحساب يبوظ.
 *    فبنعمل الصح محاسبياً: **قيد عكسي** يرجّع المبلغ لخزينة العهدة
 *    (دائن)، والاتنين يفضلوا ظاهرين في كشف الحساب — أثر واضح لمين
 *    كتب ومين لغى.
 *
 *    اللي بيتمسح فعلاً هو سطر المصروف من custody_lines بس، عشان
 *    يختفي من شاشة الفرع ومايتحسبش في الإجماليات.
 *
 * الشروط: العهدة لازم تكون مفتوحة أو مرفوضة (راجعة للكاشير). العهدة المقفولة
 * اتعمل عليها تسوية وقفل، والمقدّمة مستنية قرار المحاسب.
 */
function delete_custody_expense(int $lineId, ?int $userId = null): void
{
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        // اقرا السطر والعهدة بتاعته مع القفل
        $st = $pdo->prepare(
            'SELECT cl.*, bc.status, bc.custody_treasury_id, bc.opening_balance, bc.total_expenses
             FROM custody_lines cl
             JOIN branch_custody bc ON bc.id = cl.custody_id
             WHERE cl.id = ? FOR UPDATE'
        );
        $st->execute([$lineId]);
        $line = $st->fetch();
        if (!$line) throw new RuntimeException('المصروف مش موجود (يمكن اتمسح قبل كده).');
        if (!in_array($line['status'], ['open', 'rejected'], true)) {
            throw new RuntimeException('مينفعش تمسح مصروف من عهدة مقفولة أو مقدّمة للمراجعة.');
        }

        $custodyId = (int) $line['custody_id'];
        $amount    = (float) $line['amount'];

        // ١) القيد العكسي — الفلوس ترجع لخزينة العهدة (دائن)
        post_journal(
            (int) $line['custody_treasury_id'],
            0, $amount,
            'إلغاء مصروف عهدة: ' . $line['description'],
            date('Y-m-d'),
            'custody_expense_del', $lineId, $userId, $pdo,
            $line['entry_code_id'] !== null ? (int) $line['entry_code_id'] : null
        );

        // ٢) امسح السطر
        $pdo->prepare('DELETE FROM custody_lines WHERE id = ?')->execute([$lineId]);

        // ٣) أعِد حساب الإجماليات من السطور الفعلية
        //    (جمع من الأول أأمن من الطرح — أي فرق قديم بيتصلّح لوحده)
        $sum = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM custody_lines WHERE custody_id = ?');
        $sum->execute([$custodyId]);
        $newExp     = (float) $sum->fetchColumn();
        $newClosing = (float) $line['opening_balance'] - $newExp;
        $pdo->prepare('UPDATE branch_custody SET total_expenses=?, closing_balance=? WHERE id=?')
            ->execute([$newExp, $newClosing, $custodyId]);

        if ($own) $pdo->commit();
    } catch (Throwable $ex) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * إقفال العهدة الشهري على مرحلتين (كاشير ← محاسب).
 *
 * المرحلة ١: الكاشير بيعدّ الكاش ويقدّم (submit_custody_count).
 *   - 🔴 صفر قيود — الفلوس مش بتتحرّك هنا خالص.
 *   - بيسجّل العدّ الفعلي ($countedCash) ويحسب الفرق المتوقع.
 *   - بيثبّت الموظف المسؤول لو فيه عجز فوق العتبة.
 *   - حالة العهدة بتتحوّل لـ pending_review.
 *
 * المرحلة ٢: المحاسب بيراجع ويعتمد أو يرفض (save_custody_review).
 *   - عند الرفض: العهدة بترجع rejected مع تسجيل سبب الرفض وصف في custody_reviews.
 *     🔴 مفيش أي قيد بيتعمل في مسار الرفض.
 *   - عند الاعتماد: 🔴 هنا وهنا بس الفلوس بتتحرّك برقم المحاسب ($accountantCounted).
 *       • زيادة (الفعلي > رصيد العهدة) → قيد custody_surplus.
 *       • استلام باقي العهدة → قيد custody_settlement (من العهدة للكاش الرئيسي).
 *       • عجز (الفعلي < المفروض) → قيد custody_deficit لتصفير العهدة + صف في custody_deficits
 *         لو تعدّى العتبة على الموظف المسؤول اللي اتحدد وقت التقديم.
 *       • حالة العهدة بتتحوّل لـ closed.
 */

/**
 * تقديم جرد العهدة من الكاشير (المرحلة ١).
 * 🔴 صفر قيود مالية.
 *
 * @param int      $custodyId
 * @param float    $countedCash  الباقي الفعلي المعدود
 * @param string   $date         تاريخ الإقفال
 * @param int|null $employeeId   الموظف المسؤول
 * @param int|null $userId       منفّذ التقديم (الكاشير)
 *
 * @return array ['difference' => float, 'expected' => float]
 */
function submit_custody_count(
    int $custodyId,
    float $countedCash,
    string $date,
    ?int $employeeId = null,
    ?int $userId = null
): array {
    if ($countedCash < 0) {
        throw new InvalidArgumentException('الباقي الفعلي لا يمكن أن يكون سالباً.');
    }

    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM branch_custody WHERE id=? FOR UPDATE");
        $st->execute([$custodyId]);
        $custody = $st->fetch();
        if (!$custody) {
            throw new RuntimeException('العهدة غير موجودة.');
        }
        if (!in_array($custody['status'], ['open', 'rejected'], true)) {
            throw new RuntimeException('العهدة دي مش في حالة تسمح بالتقديم.');
        }

        $expected   = (float) $custody['closing_balance'];
        $difference = $countedCash - $expected;
        $tolerance  = custody_tolerance();

        // حارس: لو فيه عجز أكبر من العتبة والموظف المسؤول مش محدد، نرفض قبل أي تعديل
        if ($difference < 0) {
            $deficit = abs($difference);
            if ($deficit > $tolerance && ($employeeId === null || $employeeId <= 0)) {
                throw new RuntimeException(
                    'العجز ' . number_format($deficit, 2) . ' ج أكبر من الحد المسموح (' .
                    number_format($tolerance, 0) . ' ج) — لازم تحدد الموظف المسؤول قبل الإقفال.'
                );
            }
        }

        $stUp = $pdo->prepare(
            "UPDATE branch_custody
                SET counted_cash=?, difference=?, responsible_employee_id=?,
                    period_end=?, status='pending_review',
                    submitted_by=?, submitted_at=NOW(),
                    reject_reason=NULL
              WHERE id=?"
        );
        $stUp->execute([
            $countedCash,
            $difference,
            ($employeeId !== null && $employeeId > 0) ? $employeeId : null,
            $date,
            $userId,
            $custodyId,
        ]);

        if ($own) $pdo->commit();
        return ['difference' => $difference, 'expected' => $expected];
    } catch (Throwable $ex) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * حفظ مراجعة المحاسب للعهدة + اعتماد أو رفض (المرحلة ٢).
 * 🔴 هنا وهنا بس الفلوس بتتحرّك (في مسار approved بس).
 *
 * @param int         $custodyId
 * @param float       $accountantCounted المبلغ الفعلي اللي استلمه المحاسب
 * @param string      $decision          'approved' | 'rejected'
 * @param string|null $reason            سبب الرفض (إجباري عند الرفض)
 * @param int|null    $userId            المحاسب المراجع
 *
 * @return array ['variance' => float, 'on_employee' => bool, 'deficit_id' => ?int]
 */
function save_custody_review(
    int $custodyId,
    float $accountantCounted,
    string $decision,
    ?string $reason = null,
    ?int $userId = null
): array {
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        throw new InvalidArgumentException('قرار المراجعة غير صحيح.');
    }
    if ($decision === 'rejected' && (!$reason || trim($reason) === '')) {
        throw new InvalidArgumentException('سبب الرفض مطلوب.');
    }
    if ($accountantCounted < 0) {
        throw new InvalidArgumentException('المبلغ المعدود لا يمكن أن يكون سالباً.');
    }

    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM branch_custody WHERE id=? FOR UPDATE");
        $st->execute([$custodyId]);
        $custody = $st->fetch();
        if (!$custody) {
            throw new RuntimeException('العهدة غير موجودة.');
        }
        if ($custody['status'] !== 'pending_review') {
            throw new RuntimeException('العهدة دي مش مقدّمة للمراجعة.');
        }

        // بريف ٨٦١: الجلسة هي هوية المراجع؛ وفي التشغيل المحلي بناخد المستخدم الممرّر.
        $reviewer = function_exists('current_user') ? current_user() : null;
        $reviewerId = isset($reviewer['id']) ? (int) $reviewer['id'] : $userId;
        // الاستثناء لدور المالك الحقيقي من القاعدة، مش صلاحية مشاهدة الكل.
        $selfStmt = $pdo->prepare(
            'SELECT e.id FROM employees e JOIN users u ON u.id=e.user_id
             LEFT JOIN roles r ON r.id=u.role_id
             WHERE e.user_id=? AND e.id IN (?, ?) AND COALESCE(r.is_owner,0)=0 LIMIT 1'
        );
        $selfStmt->execute([$reviewerId, $custody['employee_id'], $custody['responsible_employee_id']]);
        if ($selfStmt->fetchColumn() !== false) {
            throw new RuntimeException('ماينفعش تراجع عهدتك');
        }

        // بريف ٨٦٢: المقدّم كمان مايراجعش، حتى لو حسابه مش مربوط بموظف.
        if ((int) ($custody['submitted_by'] ?? 0) === $reviewerId) {
            $submitterStmt = $pdo->prepare(
                'SELECT COALESCE(r.is_owner,0) FROM users u
                 LEFT JOIN roles r ON r.id=u.role_id WHERE u.id=?'
            );
            $submitterStmt->execute([$reviewerId]);
            if ((int) $submitterStmt->fetchColumn() !== 1) {
                throw new RuntimeException('ماينفعش تراجع عهدتك');
            }
        }

        $expected   = (float) $custody['closing_balance'];
        $variance   = $accountantCounted - $expected;
        $tolerance  = custody_tolerance();
        $date       = !empty($custody['period_end']) ? $custody['period_end'] : date('Y-m-d');
        $employeeId = $custody['responsible_employee_id'] !== null ? (int) $custody['responsible_employee_id'] : null;

        // تسجيل صف في custody_reviews في الحالتين (اعتماد أو رفض)
        $revStmt = $pdo->prepare(
            'INSERT INTO custody_reviews (custody_id, expected, cashier_counted, accountant_counted, variance, decision, reason, reviewed_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $revStmt->execute([
            $custodyId,
            $expected,
            (float) ($custody['counted_cash'] ?? 0),
            $accountantCounted,
            $variance,
            $decision,
            $reason ?: null,
            $userId,
        ]);

        if ($decision === 'rejected') {
            // مسار الرفض: تحديث الحالة فقط. 🔴 مفيش أي post_journal هنا خالص.
            $upRej = $pdo->prepare(
                "UPDATE branch_custody
                    SET status='rejected', reject_reason=?, reviewed_by=?, reviewed_at=NOW()
                  WHERE id=?"
            );
            $upRej->execute([$reason, $userId, $custodyId]);

            if ($own) $pdo->commit();
            return ['variance' => $variance, 'on_employee' => false, 'deficit_id' => null];
        }

        // مسار الاعتماد: الفلوس بتتحرك دلوقتي
        // حارس العتبة: لو فيه عجز أكبر من العتبة والموظف المسؤول مش محدد، نرفض قبل أي قيد
        if ($variance < 0) {
            $deficit = abs($variance);
            if ($deficit > $tolerance && ($employeeId === null || $employeeId <= 0)) {
                throw new RuntimeException(
                    'العجز ' . number_format($deficit, 2) . ' ج أكبر من الحد المسموح (' .
                    number_format($tolerance, 0) . ' ج) — لازم تحديد الموظف المسؤول قبل الاعتماد.'
                );
            }
        }

        $cashId = require_main_treasury();
        $custodyTreasuryId = (int) $custody['custody_treasury_id'];

        $isEmployeeCustody = !empty($custody['employee_id']);
        $onEmployee = false;
        $deficitId = null;
        $advItems = [];

        if ($isEmployeeCustody) {
            // =================================================================
            // مسار عهدة الموظف:
            // ١. قيد عكسي لإذن الصرف الأصلي (الفلوس ترجع للخزينة الرئيسية)
            // ٢. المصاريف اللي حصلت تنزل على الخزينة الرئيسية مباشرة
            // ٣. معالجة العجز أو الزيادة
            // =================================================================
            $empStmt = $pdo->prepare("SELECT full_name FROM employees WHERE id=?");
            $empStmt->execute([(int)$custody['employee_id']]);
            $empName = $empStmt->fetchColumn() ?: 'موظف';
            $empAccountId = get_or_create_employee_custody_account(
                (int) $custody['employee_id'],
                $empName,
                $pdo
            );

            $openingBalance = (float) $custody['opening_balance'];
            if ($openingBalance > 0) {
                post_journal($cashId, 0, $openingBalance, 'قيد عكسي لإذن صرف عهدة: ' . $empName,
                             $date, 'custody_reverse_order', $custodyId, $userId, $pdo, $empAccountId);
                post_journal($custodyTreasuryId, $openingBalance, 0, 'قيد عكسي لإذن صرف عهدة: ' . $empName,
                             $date, 'custody_reverse_order', $custodyId, $userId, $pdo);
            }

            // المصاريف تنزل على الخزينة الرئيسية مباشرة (مدين) وتتسوى من خزينة العهدة
            $linesStmt = $pdo->prepare('SELECT * FROM custody_lines WHERE custody_id=?');
            // السلفة من العهدة بتنزل على الموظف هنا بس (بريف ١١٢) — نفس disburse_advance() من غير قيد خزينة تاني
            $insAdv = $pdo->prepare(
                'INSERT INTO employee_advances (employee_id, amount, advance_date, status, notes)
                 VALUES (?, ?, ?, "pending", ?)'
            );
            $linesStmt->execute([$custodyId]);
            $custodyExpenses = $linesStmt->fetchAll();
            foreach ($custodyExpenses as $cl) {
                $expAmt  = (float) $cl['amount'];
                $expDesc = $cl['description'];
                $expDate = $cl['expense_date'];
                $ecId    = $cl['entry_code_id'] !== null ? (int) $cl['entry_code_id'] : null;

                post_journal($cashId, $expAmt, 0, 'مصروف عهدة موظف (' . $empName . '): ' . $expDesc,
                             $expDate, 'custody_expense_main', (int)$cl['id'], $userId, $pdo, $ecId);
                post_journal($custodyTreasuryId, 0, $expAmt, 'تسوية مصروف عهدة موظف: ' . $expDesc,
                             $expDate, 'custody_expense_settle', (int)$cl['id'], $userId, $pdo, $ecId);

                if (($cl['line_type'] ?? '') === 'advance' && !empty($cl['employee_id'])) {
                    $advEmpId = (int) $cl['employee_id'];
                    $insAdv->execute([$advEmpId, $expAmt, $expDate, $expDesc]);
                    $advId = (int) $pdo->lastInsertId();
                    emp_ledger_add($advEmpId, 'debit', $expAmt, $expDate, 'advance', $expDesc, $cashId, 'advance', $advId, $userId, $pdo);
                    $advItems[] = ['employee_id' => $advEmpId, 'amount' => $expAmt, 'date' => $expDate];
                }
            }

            // عجز أو زيادة في الكاش المستلم
            if ($variance < 0) {
                $deficit = abs($variance);
                post_journal($cashId, $deficit, 0, 'عجز عهدة موظف عند الإقفال: ' . $empName,
                             $date, 'custody_deficit', $custodyId, $userId, $pdo, $empAccountId);

                if ($deficit > $tolerance && $employeeId !== null && $employeeId > 0) {
                    $pdo->prepare(
                        'INSERT INTO custody_deficits (custody_id, employee_id, branch_id, amount, status, notes)
                         VALUES (?, ?, ?, ?, "pending", ?)'
                    )->execute([$custodyId, $employeeId, (int)($custody['branch_id'] ?? 0), $deficit,
                                'عجز عند إقفال عهدة الموظف - يُخصم من المرتب']);
                    $deficitId = (int) $pdo->lastInsertId();
                    $onEmployee = true;
                }
            } elseif ($variance > 0) {
                post_journal($cashId, 0, $variance, 'زيادة عهدة موظف عند الإقفال: ' . $empName,
                             $date, 'custody_surplus', $custodyId, $userId, $pdo, $empAccountId);
            }
        } else {
            // =================================================================
            // مسار عهدة الفرع الأصلي (بدون أي مساس بالفلو المالي للفرع)
            // =================================================================
            // الرصيد الحالي لخزينة العهدة
            $balStmt = $pdo->prepare('SELECT balance FROM treasuries WHERE id=? FOR UPDATE');
            $balStmt->execute([$custodyTreasuryId]);
            $custodyBalance = (float) $balStmt->fetchColumn();

            // 1. لو فيه زيادة (الفعلي أكبر من رصيد العهدة)، نزوّد العهدة بالزيادة
            if ($accountantCounted > $custodyBalance) {
                $surplus = $accountantCounted - $custodyBalance;
                post_journal($custodyTreasuryId, 0, $surplus, 'زيادة عهدة عند الإقفال',
                             $date, 'custody_surplus', $custodyId, $userId, $pdo);
            }

            // 2. الاستلام الفعلي: يخرج من العهدة (مدين) ويدخل الكاش الرئيسي (دائن)
            if ($accountantCounted > 0) {
                post_journal($custodyTreasuryId, $accountantCounted, 0, 'استلام باقي العهدة عند الإقفال',
                             $date, 'custody_settlement', $custodyId, $userId, $pdo);
                post_journal($cashId, 0, $accountantCounted, 'استلام باقي عهدة الفرع',
                             $date, 'custody_settlement', $custodyId, $userId, $pdo, 73);
            }

            // 3. معالجة العجز (لو الفعلي أقل من المفروض)
            if ($variance < 0) {
                $deficit = abs($variance);
                // تصفير خزينة العهدة من العجز (مدين على العهدة)
                post_journal($custodyTreasuryId, $deficit, 0, 'عجز عهدة عند الإقفال',
                             $date, 'custody_deficit', $custodyId, $userId, $pdo);

                if ($deficit > $tolerance && $employeeId !== null && $employeeId > 0) {
                    $pdo->prepare(
                        'INSERT INTO custody_deficits (custody_id, employee_id, branch_id, amount, status, notes)
                         VALUES (?, ?, ?, ?, "pending", ?)'
                    )->execute([$custodyId, $employeeId, (int)$custody['branch_id'], $deficit,
                                'عجز عند إقفال العهدة - يُخصم من المرتب']);
                    $deficitId = (int) $pdo->lastInsertId();
                    $onEmployee = true;
                }
            }
        }

        // 4. تحديث وإقفال العهدة
        $upApp = $pdo->prepare(
            "UPDATE branch_custody
                SET accountant_counted=?, difference=?, settled_amount=?,
                    status='closed', closed_by=?, closed_at=NOW(),
                    reviewed_by=?, reviewed_at=NOW(), reject_reason=NULL
              WHERE id=?"
        );
        $upApp->execute([
            $accountantCounted,
            $variance,
            $accountantCounted,
            $userId,
            $userId,
            $custodyId,
        ]);

        if ($own) $pdo->commit();
        if (!empty($advItems)) {
            notify_advance_disbursed($advItems);
        }
        return ['variance' => $variance, 'on_employee' => $onEmployee, 'deficit_id' => $deficitId];
    } catch (Throwable $ex) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/* =====================================================================
 *  دوال التقفيل والمراجعة
 * ===================================================================== */

/**
 * خريطة وسيلة الدفع → نوع الخزينة المطابق.
 */
function payment_method_treasury_type(string $method): string
{
    return [
        'cash'     => 'cash',
        'visa'     => 'visa',
        'instapay' => 'instapay',
    ][$method] ?? $method;
}

/**
 * إيجاد الخزينة المركزية المطابقة لنوع (cash/visa/instapay).
 * بترجّع id أو null.
 */
function central_treasury_by_type(string $type): ?int
{
    $st = db()->prepare("SELECT id FROM treasuries WHERE type=? AND branch_id IS NULL AND is_active=1 ORDER BY id LIMIT 1");
    $st->execute([$type]);
    $id = $st->fetchColumn();
    return $id ? (int) $id : null;
}

/**
 * حفظ مراجعة المحاسب لوردية + دخول أرقام المحاسب للخزائن.
 *
 * المنطق:
 *  - أرقام المحاسب (cash/visa/instapay) هي اللي تدخل الخزائن المطابقة.
 *  - الدخول بيحصل وقت المراجعة (اعتماد أو رفض) — مش معتمد على كلمة "معتمد".
 *  - لو الوردية اتراجعت قبل كده (إعادة مراجعة)، بنحرّك الفرق فقط:
 *      الجديد − القديم لكل وسيلة (ممكن يبقى سالب لو المحاسب قلّل الرقم).
 *  - كل حركة عبر post_journal (مقترنة باليوزر والوقت).
 *
 * @param int   $shiftId
 * @param array $accountantAmounts ['cash'=>..,'visa'=>..,'instapay'=>..,'system'=>..]
 * @param string $decision 'approved' | 'rejected'
 * @param string|null $reason سبب الرفض (إجباري عند الرفض)
 * @param int|null $userId المحاسب
 * @return int معرّف المراجعة
 */
function save_shift_review(
    int $shiftId,
    array $accountantAmounts,
    string $decision,
    ?string $reason,
    ?int $userId = null
): int {
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        throw new InvalidArgumentException('قرار المراجعة غير صحيح.');
    }
    if ($decision === 'rejected' && (!$reason || trim($reason) === '')) {
        throw new InvalidArgumentException('سبب الرفض مطلوب.');
    }

    $methods = ['cash', 'visa', 'instapay'];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // اقفل الوردية
        $st = $pdo->prepare("SELECT * FROM shifts WHERE id=? FOR UPDATE");
        $st->execute([$shiftId]);
        $shift = $st->fetch();
        if (!$shift) {
            throw new RuntimeException('الوردية غير موجودة.');
        }

        $date = $shift['business_date'];

        // 🔴 الترحيل للخزينة للمعتمد بس: الوردية المرجوعة ماتدخلش الخزينة خالص
        if ($decision === 'approved') {
            // حساب «إيراد مبيعات» من الإعدادات — مش رقم مكتوب في الكود، عشان
            // لو شجرة الحسابات اتغيّرت مايبوظش الترحيل في صمت. فاضي = null =
            // السلوك القديم بالظبط.
            $salesAccountId = ((int) setting('sales_revenue_account_id', 0)) ?: null;

            // «اللي اترحّل فعلاً» يتحسب من journal_entries مش من shift_reviews (استعلام واحد قبل حلقة الوسائل)
            $qPosted = $pdo->prepare(
                "SELECT treasury_id, SUM(credit - debit) AS posted
                 FROM journal_entries
                 WHERE ref_type = 'shift' AND ref_id = ?
                 GROUP BY treasury_id"
            );
            $qPosted->execute([$shiftId]);
            $postedByTreasury = [];
            foreach ($qPosted->fetchAll(PDO::FETCH_ASSOC) as $pRow) {
                $postedByTreasury[(int)$pRow['treasury_id']] = (float)$pRow['posted'];
            }

            if (site_profile()['shift_expenses_mode'] === 'ashraqa') {
            // -----------------------------------------------------------------
            //  (١) ترحيل الكاش والمصروفات:
            //  - الاستلام = كاش المحاسب + إجمالي المصروفات (كامل ما تم بيعه).
            //  - الصرف = كل بند مصروف على حدة من نفس الخزينة النقدية بحسابه الخاص.
            // -----------------------------------------------------------------
            $cashTreasuryId = central_treasury_by_type('cash');
            if ($cashTreasuryId === null) {
                throw new RuntimeException('لا توجد خزينة مركزية من نوع: cash');
            }

            // جلب مصروفات الوردية
            $stExp = $pdo->prepare('SELECT id, account_id, amount, note FROM shift_expenses WHERE shift_id=? ORDER BY id ASC');
            $stExp->execute([$shiftId]);
            $shiftExpenses = $stExp->fetchAll();
            $totalExpenses = 0.0;
            foreach ($shiftExpenses as $se) {
                $totalExpenses += (float) $se['amount'];
            }

            // إجمالي الكاش المبيوع (كاش المحاسب + المصروفات)
            $accCash      = (float) ($accountantAmounts['cash'] ?? 0);
            $newGrossCash = $accCash + $totalExpenses;

            // ما تم تسجيله كاستلام كاش سابقاً لهذه الوردية في قيود اليومية
            $stPrevReceipt = $pdo->prepare(
                "SELECT COALESCE(SUM(credit) - SUM(debit), 0)
                 FROM journal_entries
                 WHERE treasury_id = ? AND ref_type = 'shift' AND ref_id = ?"
            );
            $stPrevReceipt->execute([$cashTreasuryId, $shiftId]);
            $prevGrossReceipt = (float) $stPrevReceipt->fetchColumn();

            $grossCashDelta = $newGrossCash - $prevGrossReceipt;
            $cashLabel = 'تقفيل وردية #' . $shiftId . ' (' . treasury_type_label('cash') . ')';

            if ($grossCashDelta > 0.001) {
                // استلام كامل / زيادة → دائن (دخول)
                post_journal($cashTreasuryId, 0, $grossCashDelta, $cashLabel, $date, 'shift', $shiftId, $userId, $pdo, $salesAccountId);
            } elseif ($grossCashDelta < -0.001) {
                // نقص (تصحيح بعد رفض) → مدين (خروج)
                post_journal($cashTreasuryId, abs($grossCashDelta), 0, $cashLabel . ' - تصحيح', $date, 'shift', $shiftId, $userId, $pdo, $salesAccountId);
            }

            // صرف المصروفات من الخزينة النقدية (مرة واحدة فقط للوردية)
            $stPrevExp = $pdo->prepare(
                "SELECT COALESCE(SUM(debit) - SUM(credit), 0)
                 FROM journal_entries
                 WHERE treasury_id = ? AND ref_type = 'shift_expense' AND ref_id = ?"
            );
            $stPrevExp->execute([$cashTreasuryId, $shiftId]);
            $prevExpDisbursed = (float) $stPrevExp->fetchColumn();

            if ($prevExpDisbursed < 0.001) {
                // أول مرة يتم فيها اعتماد/مراجعة المصروفات: ترحيل كل سطر على حدة
                foreach ($shiftExpenses as $se) {
                    $amt = (float) $se['amount'];
                    if ($amt <= 0) continue;
                    $accId = !empty($se['account_id']) ? (int) $se['account_id'] : null;
                    $note  = trim((string) ($se['note'] ?? ''));
                    $expLabel = 'مصروف وردية #' . $shiftId . ($note !== '' ? ' — ' . $note : '');
                    post_journal(
                        $cashTreasuryId,
                        $amt,                // مدين (صرف)
                        0,                   // دائن
                        $expLabel,
                        $date,
                        'shift_expense',
                        $shiftId,
                        $userId,
                        $pdo,
                        $accId,
                        $note !== '' ? $note : null
                    );
                }
            } elseif (abs($totalExpenses - $prevExpDisbursed) >= 0.001) {
                // إعادة مراجعة مع تغير إجمالي المصروفات: تحريك الفرق فقط
                $expDelta = $totalExpenses - $prevExpDisbursed;
                if ($expDelta > 0.001) {
                    post_journal($cashTreasuryId, $expDelta, 0, 'مصروف وردية #' . $shiftId . ' — تسوية فرق مصروفات', $date, 'shift_expense', $shiftId, $userId, $pdo, null, 'تسوية فرق مصروفات');
                } elseif ($expDelta < -0.001) {
                    post_journal($cashTreasuryId, 0, abs($expDelta), 'مصروف وردية #' . $shiftId . ' — استرداد فرق مصروفات', $date, 'shift_expense', $shiftId, $userId, $pdo, null, 'استرداد فرق مصروفات');
                }
            }

            // -----------------------------------------------------------------
            //  (٢) ترحيل الفيزا والإنستا باي (بدون أي تغيير)
            // -----------------------------------------------------------------
            $nonCashMethods = ['visa', 'instapay'];
            foreach ($nonCashMethods as $m) {
                $newAmt = (float) ($accountantAmounts[$m] ?? 0);
                $treasuryId = central_treasury_by_type(payment_method_treasury_type($m));
                if ($treasuryId === null) {
                    throw new RuntimeException('لا توجد خزينة مركزية من نوع: ' . $m);
                }
                $oldAmt = $postedByTreasury[$treasuryId] ?? 0.0;
                $delta  = $newAmt - $oldAmt;
                if (abs($delta) < 0.001) {
                    continue; // مفيش تغيير
                }
                $label = 'تقفيل وردية #' . $shiftId . ' (' . treasury_type_label($m) . ')';
                if ($delta > 0) {
                    post_journal($treasuryId, 0, $delta, $label, $date, 'shift', $shiftId, $userId, $pdo, $salesAccountId);
                } else {
                    post_journal($treasuryId, abs($delta), 0, $label . ' - تصحيح', $date, 'shift', $shiftId, $userId, $pdo, $salesAccountId);
                }
            }
            } else {
            // حرّك الفرق لكل وسيلة دفع
            foreach ($methods as $m) {
                $newAmt = (float) ($accountantAmounts[$m] ?? 0);
                $treasuryId = central_treasury_by_type(payment_method_treasury_type($m));
                if ($treasuryId === null) {
                    throw new RuntimeException('لا توجد خزينة مركزية من نوع: ' . $m);
                }
                $oldAmt = $postedByTreasury[$treasuryId] ?? 0.0;
                $delta  = $newAmt - $oldAmt;
                if (abs($delta) < 0.001) {
                    continue; // مفيش تغيير
                }
                $label = 'تقفيل وردية #' . $shiftId . ' (' . treasury_type_label($m) . ')';
                if ($delta > 0) {
                    // زيادة → دائن (دخول)
                    post_journal($treasuryId, 0, $delta, $label, $date, 'shift', $shiftId, $userId, $pdo, $salesAccountId);
                } else {
                    // نقص (تصحيح بعد رفض أو مراجعة سابقة) → مدين (خروج)
                    post_journal($treasuryId, abs($delta), 0, $label . ' - تصحيح', $date, 'shift', $shiftId, $userId, $pdo, $salesAccountId);
                }
            }
            }
        }

        if (site_profile()['shift_expenses_mode'] === 'ashraqa') {
        // جلب مجموع المصروفات لحساب الإجمالي الصحيح للوردية
        $stExpSum = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM shift_expenses WHERE shift_id=?');
        $stExpSum->execute([$shiftId]);
        $shiftExpSum = (float) $stExpSum->fetchColumn();

        // حساب الفرق بين الكاشير والمحاسب (إجمالي)
        $cashierTotal = (float)$shift['cash_total'] + $shiftExpSum + (float)$shift['visa_total']
                      + (float)$shift['instapay_total'];
        $accTotal = (float)($accountantAmounts['cash'] ?? 0)
                  + $shiftExpSum
                  + (float)($accountantAmounts['visa'] ?? 0)
                  + (float)($accountantAmounts['instapay'] ?? 0);
        } else {
        // حساب الفرق بين الكاشير والمحاسب (إجمالي)
        $cashierTotal = (float)$shift['cash_total'] + (float)$shift['visa_total']
                      + (float)$shift['instapay_total'];
        $accTotal = 0.0;
        foreach ($methods as $m) $accTotal += (float) ($accountantAmounts[$m] ?? 0);
        }
        $variance = $accTotal - $cashierTotal;

        // تسجيل المراجعة
        $pdo->prepare(
            'INSERT INTO shift_reviews
                (shift_id, accountant_cash, accountant_visa, accountant_wallet, accountant_instapay,
                 accountant_system, variance, decision, reason, reviewed_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $shiftId,
            (float)($accountantAmounts['cash'] ?? 0),
            (float)($accountantAmounts['visa'] ?? 0),
            0, // المحفظة اتلغت — العمود باقي في الجدول وبيتكتب صفر
            (float)($accountantAmounts['instapay'] ?? 0),
            (float)($accountantAmounts['system'] ?? 0),
            $variance, $decision, $reason, $userId,
        ]);
        $reviewId = (int) $pdo->lastInsertId();

        // 🔴 فرق المحاسب هو الفرق المعتمد — والموظف بيتخصم منه.
        //    قبل كده `shifts.difference` كان بيفضل رقم **الكاشير** للأبد،
        //    وتصحيح المحاسب كان بيروح للخزينة بس. يعني كاشير يدخّل رقم
        //    غلط → عجز وهمي → المحاسب يصحّحه → والموظف بيتخصم منه على
        //    الرقم الغلط برضو. ده كان هيخصم ٧٠٠٠ ج على موظفين.
        //    نفس معادلة الكاشير بالظبط (cashier.php:102): المحصّل − السيستم.
        $accSystem     = (float) ($accountantAmounts['system'] ?? 0);
        $accDifference = round($accTotal - $accSystem, 2);

        // تحديث حالة الوردية
        $pdo->prepare(
            'UPDATE shifts
                SET status=?, reject_reason=?, reviewed_by=?, reviewed_at=NOW(),
                    cash_total=?, visa_total=?, instapay_total=?,
                    system_total=?, difference=?
              WHERE id=?'
        )->execute([
            $decision, $decision === 'rejected' ? $reason : null, $userId,
            (float)($accountantAmounts['cash'] ?? 0),
            (float)($accountantAmounts['visa'] ?? 0),
            (float)($accountantAmounts['instapay'] ?? 0),
            $accSystem, $accDifference,
            $shiftId,
        ]);

        $pdo->commit();
        return $reviewId;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/* =====================================================================
 *  دوال الموردين
 * ===================================================================== */

/**
 * تسجيل فاتورة مورد يدوي (هيدر فقط).
 *
 * - كاش: تتسجّل مدفوعة + الفلوس تطلع من الخزينة فوراً (مدين).
 * - آجل: تفضل مستحقة (unpaid) + رصيد المورد يزيد.
 *
 * @param string $supplierName اسم المورد (يُنشأ لو غير موجود)
 * @param float  $netAmount    الصافي
 * @param float  $totalAmount  الإجمالي
 * @param string $payType      'cash' | 'credit'
 * @param string $date
 * @param int|null $treasuryId  خزينة الدفع (للكاش فقط)
 * @param int|null $userId
 * @return int معرّف الفاتورة
 */
function add_supplier_invoice(
    string $supplierName,
    float $netAmount,
    float $totalAmount,
    string $payType,
    string $date,
    ?int $treasuryId = null,
    ?int $userId = null,
    ?string $invoiceNo = null
): int {
    if (trim($supplierName) === '') {
        throw new InvalidArgumentException('اسم المورد مطلوب.');
    }
    if ($totalAmount <= 0) {
        throw new InvalidArgumentException('إجمالي الفاتورة لازم يكون أكبر من صفر.');
    }
    if (!in_array($payType, ['cash', 'credit'], true)) {
        throw new InvalidArgumentException('نوع الدفع غير صحيح.');
    }
    if ($payType === 'cash' && $treasuryId === null) {
        throw new InvalidArgumentException('اختر خزينة الدفع للفاتورة الكاش.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // إيجاد/إنشاء المورد
        $sp = $pdo->prepare('SELECT id FROM suppliers WHERE name = ? LIMIT 1');
        $sp->execute([trim($supplierName)]);
        $supplierId = $sp->fetchColumn();
        if (!$supplierId) {
            $pdo->prepare('INSERT INTO suppliers (name) VALUES (?)')->execute([trim($supplierName)]);
            $supplierId = (int) $pdo->lastInsertId();
        }
        $supplierId = (int) $supplierId;

        $status   = $payType === 'cash' ? 'paid' : 'unpaid';
        $paidAmt  = $payType === 'cash' ? $totalAmount : 0;

        // تسجيل الفاتورة
        $pdo->prepare(
            'INSERT INTO supplier_invoices
                (supplier_id, invoice_no, invoice_date, total_amount, paid_amount, status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId, $invoiceNo, $date, $totalAmount, $paidAmt, $status,
            'الصافي: ' . number_format($netAmount, 2),
        ]);
        $invoiceId = (int) $pdo->lastInsertId();

        if ($payType === 'cash') {
            // صرف من الخزينة فوراً (مدين)
            post_journal($treasuryId, $totalAmount, 0,
                'سداد فاتورة مورد: ' . trim($supplierName), $date,
                'supplier_invoice', $invoiceId, $userId, $pdo);
            // تسجيل الدفعة
            $pdo->prepare(
                'INSERT INTO supplier_payments (supplier_id, invoice_id, treasury_id, amount, payment_date, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$supplierId, $invoiceId, $treasuryId, $totalAmount, $date, 'سداد كاش عند التسجيل', $userId]);
        } else {
            // آجل: رصيد المورد يزيد (مستحق علينا)
            $pdo->prepare('UPDATE suppliers SET balance = balance + ? WHERE id = ?')
                ->execute([$totalAmount, $supplierId]);
        }

        $pdo->commit();
        return $invoiceId;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * سداد دفعة على فاتورة آجلة.
 * - الفلوس تطلع من الخزينة (مدين).
 * - paid_amount يزيد، رصيد المورد ينقص، والحالة تتحدّث.
 *
 * @return int معرّف الدفعة
 */
function pay_supplier_invoice(
    int $invoiceId,
    float $amount,
    int $treasuryId,
    string $date,
    ?string $notes = null,
    ?int $userId = null
): int {
    if ($amount <= 0) {
        throw new InvalidArgumentException('مبلغ الدفعة لازم يكون أكبر من صفر.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // اقفل الفاتورة
        $st = $pdo->prepare('SELECT * FROM supplier_invoices WHERE id = ? FOR UPDATE');
        $st->execute([$invoiceId]);
        $inv = $st->fetch();
        if (!$inv) {
            throw new RuntimeException('الفاتورة غير موجودة.');
        }

        $remaining = (float) $inv['total_amount'] - (float) $inv['paid_amount'];
        if ($amount > $remaining + 0.0001) {
            throw new RuntimeException('المبلغ أكبر من المتبقي (' . number_format($remaining, 2) . ').');
        }

        $supplierId = (int) $inv['supplier_id'];

        // صرف من الخزينة
        post_journal($treasuryId, $amount, 0,
            'سداد دفعة لمورد (فاتورة #' . $invoiceId . ')', $date,
            'supplier_payment', $invoiceId, $userId, $pdo);

        // تسجيل الدفعة
        $pdo->prepare(
            'INSERT INTO supplier_payments (supplier_id, invoice_id, treasury_id, amount, payment_date, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$supplierId, $invoiceId, $treasuryId, $amount, $date, $notes, $userId]);
        $paymentId = (int) $pdo->lastInsertId();

        // تحديث الفاتورة
        $newPaid = (float) $inv['paid_amount'] + $amount;
        $newStatus = ($newPaid >= (float) $inv['total_amount'] - 0.0001) ? 'paid'
                   : ($newPaid > 0 ? 'partial' : 'unpaid');
        $pdo->prepare('UPDATE supplier_invoices SET paid_amount = ?, status = ? WHERE id = ?')
            ->execute([$newPaid, $newStatus, $invoiceId]);

        // تحديث رصيد المورد
        $pdo->prepare('UPDATE suppliers SET balance = balance - ? WHERE id = ?')
            ->execute([$amount, $supplierId]);

        $pdo->commit();
        return $paymentId;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/* =====================================================================
 *  دوال الموارد البشرية (HR)
 * ===================================================================== */

/**
 * تسجيل حركة في سجل حساب الموظف (employee_ledger).
 * - تسجيل فقط في المرحلة دي — مفيش أي قراءة منه في النظام.
 * - المبلغ <= 0 يتم تخطيه بهدوء دون تسجيل ودون استثناء.
 * - الاتجاه لازم يكون 'debit' أو 'credit' وإلا يرمي استثناء.
 * - الدالة لا تفتح معاملة بنفسها ولا تعمل commit — بتنفذ INSERT واحد فقط داخل المعاملة الممررة.
 *
 * @param int         $employeeId  معرّف الموظف
 * @param string      $direction   'debit' (عليه / زوّد مديونيته) أو 'credit' (له / قلّل مديونيته)
 * @param float       $amount      مبلغ الحركة (موجب)
 * @param string      $date        تاريخ الحركة YYYY-MM-DD
 * @param string      $moveType    نوع الحركة (advance, salary_deduction, cash_repay, cancel, opening)
 * @param string|null $description وصف الحركة بالعربية
 * @param int|null    $treasuryId  معرّف الخزينة لو الحركة نقدية كاش
 * @param string|null $refType     نوع المرجع المرتبط (advance, salary, ...)
 * @param int|null    $refId       معرّف المرجع
 * @param int|null    $userId      معرّف المستخدم الذي قام بالعملية
 * @param PDO|null    $pdo         كائن PDO لو المعاملة مفتوحة مسبقاً
 */
function emp_ledger_add(
    int $employeeId,
    string $direction,
    float $amount,
    string $date,
    string $moveType,
    ?string $description = null,
    ?int $treasuryId = null,
    ?string $refType = null,
    ?int $refId = null,
    ?int $userId = null,
    ?PDO $pdo = null
): void {
    if ($amount <= 0.00001) {
        return;
    }

    if ($direction !== 'debit' && $direction !== 'credit') {
        throw new InvalidArgumentException("اتجاه الحركة غير صحيح ($direction) — يجب أن يكون debit أو credit.");
    }

    $db = $pdo ?? db();
    $st = $db->prepare(
        'INSERT INTO employee_ledger
            (employee_id, entry_date, direction, amount, move_type, description, treasury_id, ref_type, ref_id, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([
        $employeeId,
        $date,
        $direction,
        round($amount, 2),
        $moveType,
        $description,
        $treasuryId,
        $refType,
        $refId,
        $userId,
    ]);
}

/**
 * معرّف المستخدم الحالي لتسجيل حركات سجل حساب الموظف (null لو مفيش جلسة).
 */
function emp_ledger_uid(): ?int
{
    $u = function_exists('current_user') ? current_user() : null;
    return isset($u['id']) ? (int) $u['id'] : null;
}

/**
 * تسجيل **الفرق** بين رصيد سلفة قديم وجديد في سجل حساب الموظف.
 *
 * 🔴 الفرق مش القيمة الجديدة: رصيد من 800 لـ600 = حركة 200 له (credit)،
 * ومن 800 لـ1000 = حركة 200 عليه (debit). لو سجّلنا القيمة الجديدة
 * الرصيد كله بيبوظ.
 */
function emp_ledger_diff(PDO $pdo, int $employeeId, float $oldAmount, float $newAmount,
                         string $date, string $refType, int $refId): void
{
    $diff = round($newAmount - $oldAmount, 2);
    if (abs($diff) < 0.005) {
        return;
    }
    if ($diff < 0) {
        emp_ledger_add($employeeId, 'credit', -$diff, $date, 'cancel',
                       'تعديل رصيد سلفة بالنقصان', null, $refType, $refId, emp_ledger_uid(), $pdo);
    } else {
        emp_ledger_add($employeeId, 'debit', $diff, $date, 'advance',
                       'تعديل رصيد سلفة بالزيادة', null, $refType, $refId, emp_ledger_uid(), $pdo);
    }
}

/**
 * رصيد سجل حساب الموظف الحالي (debit - credit).
 * 
 * - موجب = الموظف عليه فلوس (مديونية للشركة).
 * - سالب = الموظف له فلوس (الشركة مدينة له).
 * - صفر  = حسابه متصفّر أو مفيش حركات.
 *
 * @param int      $employeeId معرّف الموظف
 * @param PDO|null $pdo        كائن PDO اختياري (لو جوه معاملة)
 * @return float رصيد الموظف
 */
function emp_ledger_balance(int $employeeId, ?PDO $pdo = null): float
{
    if ($employeeId <= 0) {
        return 0.0;
    }
    $db = $pdo ?? db();
    $st = $db->prepare(
        'SELECT COALESCE(SUM(CASE WHEN direction = "debit" THEN amount ELSE -amount END), 0)
         FROM employee_ledger
         WHERE employee_id = ?'
    );
    $st->execute([$employeeId]);
    return (float) $st->fetchColumn();
}

/**
 * جلب أرصدة سجل حساب مجموعة موظفين دفعة واحدة باستعلام واحد مجمّع.
 * 🔴 ممنوع نداء emp_ledger_balance() جوه حلقة — استعمل دي للشاشات المجمّعة.
 *
 * @param array    $employeeIds مصفوفة معرّفات الموظفين
 * @param PDO|null $pdo         كائن PDO اختياري
 * @return array مصفوفة [employee_id => balance]
 */
function emp_ledger_balances(array $employeeIds, ?PDO $pdo = null): array
{
    $cleanIds = array_values(array_unique(array_filter(array_map('intval', $employeeIds), fn($id) => $id > 0)));
    if (empty($cleanIds)) {
        return [];
    }

    $res = [];
    foreach ($cleanIds as $id) {
        $res[$id] = 0.0;
    }

    $db = $pdo ?? db();
    $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
    $st = $db->prepare(
        "SELECT employee_id,
                COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount ELSE -amount END), 0) AS balance
         FROM employee_ledger
         WHERE employee_id IN ($placeholders)
         GROUP BY employee_id"
    );
    $st->execute($cleanIds);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $res[(int)$row['employee_id']] = (float)$row['balance'];
    }

    return $res;
}

const ADVANCE_ACCOUNT_ID = 10;
const SALARY_ACCOUNT_ID = 33;

/**
 * صرف سلفة لموظف عبر إذن صرف.
 * - الفلوس تطلع من الخزينة (مدين).
 * - تتسجّل سلفة على الموظف (employee_advances) بحالة pending.
 *
 * @return int معرّف السلفة
 */
function disburse_advance(
    int $employeeId,
    float $amount,
    int $treasuryId,
    string $date,
    ?string $notes = null,
    ?int $userId = null
): int {
    if ($amount <= 0) {
        throw new InvalidArgumentException('مبلغ السلفة لازم يكون أكبر من صفر.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // تأكد إن الموظف موجود
        $chk = $pdo->prepare('SELECT full_name FROM employees WHERE id = ? AND is_active = 1');
        $chk->execute([$employeeId]);
        $empName = $chk->fetchColumn();
        if ($empName === false) {
            throw new RuntimeException('الموظف غير موجود أو غير نشط.');
        }

        $advanceAccountId = null;
        try {
            $accountCheck = $pdo->prepare('SELECT id FROM accounts WHERE id = ? AND is_active = 1');
            $accountCheck->execute([ADVANCE_ACCOUNT_ID]);
            if ($accountCheck->fetchColumn() !== false) {
                $advanceAccountId = ADVANCE_ACCOUNT_ID;
            } else {
                error_log('حساب سلف موظفين غير موجود أو غير نشط؛ السلفة هتتسجل من غير قيد حساب.');
            }
        } catch (Throwable $ex) {
            error_log('تعذر فحص حساب سلف موظفين؛ السلفة هتتسجل من غير قيد حساب: ' . $ex->getMessage());
        }

        // نسجّل السلفة الأول عشان قيد الخزينة يشاور على رقمها الصح.
        $pdo->prepare(
            'INSERT INTO employee_advances (employee_id, amount, advance_date, status, notes)
             VALUES (?, ?, ?, "pending", ?)'
        )->execute([$employeeId, $amount, $date, $notes]);
        $advanceId = (int) $pdo->lastInsertId();

        // صرف من الخزينة
        post_journal($treasuryId, $amount, 0,
            'سلفة للموظف: ' . $empName, $date,
            'advance', $advanceId, $userId, $pdo, $advanceAccountId, null);

        // تسجيل الحركة في سجل حساب الموظف
        emp_ledger_add(
            $employeeId,
            'debit',
            $amount,
            $date,
            'advance',
            $notes ?: ('سلفة نقدية من الخزينة — ' . $empName),
            $treasuryId,
            'advance',
            $advanceId,
            $userId,
            $pdo
        );

        $pdo->commit();
        return $advanceId;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * رد سلفة من موظف (عكس disburse_advance).
 * - الخزينة تستلم المبلغ (دائن).
 * - السلف المعلّقة للموظف تتسدّد بالترتيب (الأقدم أولاً) حتى يُستهلك المبلغ.
 *
 * @return float المبلغ اللي اتسدّد فعلاً
 */
function recover_advance(
    int $employeeId,
    float $amount,
    int $treasuryId,
    string $date,
    ?string $notes = null,
    ?int $userId = null
): float {
    if ($amount <= 0) {
        throw new InvalidArgumentException('مبلغ الرد لازم يكون أكبر من صفر.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $chk = $pdo->prepare('SELECT full_name FROM employees WHERE id = ?');
        $chk->execute([$employeeId]);
        $empName = $chk->fetchColumn();
        if ($empName === false) throw new RuntimeException('الموظف غير موجود.');

        // فحص إجمالي السلف المستحقة المعلّقة على الموظف باستعلام واحد قبل عمل القيد
        $chkAdv = $pdo->prepare(
            "SELECT COALESCE(SUM(COALESCE(remaining, amount)), 0)
             FROM employee_advances
             WHERE employee_id = ? AND status = 'pending'"
        );
        $chkAdv->execute([$employeeId]);
        $totalOutstanding = (float) $chkAdv->fetchColumn();

        if ($totalOutstanding <= 0.0001) {
            throw new RuntimeException("لا توجد سلف معلّقة مستحقة على الموظف: {$empName}.");
        }

        if ($amount - $totalOutstanding > 0.01) {
            throw new RuntimeException(
                "مبلغ الرد (" . number_format($amount, 2) . " ج.م) أكبر من إجمالي السلف المستحقة على الموظف {$empName} (" . number_format($totalOutstanding, 2) . " ج.م)."
            );
        }

        // الخزينة تستلم (القيد يتم فقط بعد التأكد من صحة المبلغ والمستحق)
        post_journal($treasuryId, 0, $amount,
            'رد سلفة من الموظف: ' . $empName, $date,
            'advance_recovery', $employeeId, $userId, $pdo);

        // تسديد السلف المعلّقة (الأقدم أولاً) مع الحفاظ على المبلغ الأصلي amount
        $remaining = $amount;
        $st = $pdo->prepare(
            "SELECT id, amount, remaining
             FROM employee_advances
             WHERE employee_id = ? AND status = 'pending'
             ORDER BY id ASC FOR UPDATE"
        );
        $st->execute([$employeeId]);

        $updFull = $pdo->prepare("UPDATE employee_advances SET remaining = 0, status = 'recovered' WHERE id = ?");
        $updPart = $pdo->prepare("UPDATE employee_advances SET remaining = ? WHERE id = ?");

        foreach ($st->fetchAll() as $adv) {
            if ($remaining <= 0.0001) break;

            $outstanding = $adv['remaining'] !== null ? (float) $adv['remaining'] : (float) $adv['amount'];
            if ($outstanding <= 0.0001) {
                // سلفة ملغاة أو مسددة من قبل — تخطي دون لمس الحالة أو استهلاك المبلغ
                continue;
            }

            if ($remaining >= $outstanding - 0.0001) {
                // سداد كامل للسلفة
                $updFull->execute([$adv['id']]);
                $remaining -= $outstanding;
            } else {
                // سداد جزئي للسلفة: يتبقى منها الفرق وتظل الحالة pending
                $newRemaining = $outstanding - $remaining;
                $updPart->execute([$newRemaining, $adv['id']]);
                $remaining = 0;
            }
        }

        // تسجيل السداد النقدي في سجل حساب الموظف
        $repaidAmount = $amount - $remaining;
        if ($repaidAmount > 0.0001) {
            emp_ledger_add(
                $employeeId,
                'credit',
                $repaidAmount,
                $date,
                'cash_repay',
                $notes ?: ('سداد نقدي لسلفة — ' . $empName),
                $treasuryId,
                'advance_recovery',
                $employeeId,
                $userId,
                $pdo
            );
        }

        $pdo->commit();
        return $amount - $remaining;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * استلام عهدة من فرع (عكس issue_disbursement_order).
 * - الخزينة الرئيسية تستلم المبلغ (دائن).
 * - خزينة عهدة الفرع تنقص (مدين).
 * - أول المدة في العهدة المفتوحة تنقص بالمبلغ.
 */
function receive_custody(
    int $branchId,
    float $amount,
    int $mainTreasuryId,
    string $date,
    ?string $notes = null,
    ?int $userId = null
): void {
    if ($amount <= 0) {
        throw new InvalidArgumentException('المبلغ لازم يكون أكبر من صفر.');
    }
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $ct = $pdo->prepare("SELECT id FROM treasuries WHERE branch_id=? AND type='branch_custody' LIMIT 1");
        $ct->execute([$branchId]);
        $ctId = (int) $ct->fetchColumn();
        if (!$ctId) throw new RuntimeException('لا توجد خزينة عهدة لهذا الفرع.');

        // افحص رصيد العهدة واقفل صفها قبل القيود؛ الرفض لازم يسبق أي حركة مالية.
        $st = $pdo->prepare("SELECT id, opening_balance, total_expenses FROM branch_custody WHERE branch_id=? AND employee_id IS NULL AND status IN ('open','rejected') ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $st->execute([$branchId]);
        $row = $st->fetch();
        if ($row && $amount > (float)$row['opening_balance']) {
            throw new RuntimeException(
                'مبلغ رد العهدة أكبر من الرصيد المتاح. الرصيد المتاح: ' .
                number_format((float)$row['opening_balance'], 2) . ' ج.'
            );
        }

        // خزينة العهدة تنقص (مدين)، الخزينة الرئيسية تستلم (دائن)
        post_journal($ctId, $amount, 0, 'رد عهدة للخزينة الرئيسية' . ($notes ? " - $notes" : ''),
                     $date, 'custody_return', $branchId, $userId, $pdo);
        post_journal($mainTreasuryId, 0, $amount, 'استلام عهدة من الفرع' . ($notes ? " - $notes" : ''),
                     $date, 'custody_return', $branchId, $userId, $pdo, 73);

        // قلّل أول المدة في العهدة المفتوحة أو المرفوضة
        if ($row) {
            $newOpening = (float)$row['opening_balance'] - $amount;
            $newClosing = $newOpening - (float)$row['total_expenses'];
            $pdo->prepare('UPDATE branch_custody SET opening_balance=?, closing_balance=? WHERE id=?')
                ->execute([$newOpening, $newClosing, $row['id']]);
        }

        if ($own) $pdo->commit();
    } catch (Throwable $ex) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * حساب أيام العمل والإجازة والاستحقاق من ساعات العمل وفقاً للساعات المنتظرة شهرياً للموظف (المصدر الموحد):
 * - ساعات اليوم     = الساعات المنتظرة ÷ 30
 * - أيام العمل      = ساعات العمل الفعلية ÷ ساعات اليوم
 * - أيام الإجازة    = Floor(أيام العمل ÷ 6) بحد أقصى 4 أيام في الشهر (min(4, floor(أيام العمل ÷ 6)))
 * - أيام الاستحقاق  = أيام العمل + أيام الإجازة
 * - الراتب          = سعر الساعة × (الساعات الفعلية + أيام الإجازة × ساعات اليوم)
 *                   = (الأساسي ÷ 30) × أيام الاستحقاق
 *
 * ⚠️ لو لم تُحدد الساعات المنتظرة ($expectedMonthlyHours <= 0 أو null):
 *    يُرجع علم no_expected => true مع أصفار، ولا يتم افتراض أي قيمة افتراضية أبداً.
 *
 * @param float $workHours إجمالي ساعات العمل الفعلية
 * @param float|null $expectedMonthlyHours الساعات المنتظرة من الموظف في الشهر (مثلاً 240 لـ 8 س/يوم، 300 لـ 10 س/يوم)
 * @return array{work_days: float, leave_days: int, due_days: float, day_hours: float, no_expected: bool}
 */
function salary_days_from_hours(float $workHours, ?float $expectedMonthlyHours = null): array
{
    if ($expectedMonthlyHours === null || $expectedMonthlyHours <= 0.0) {
        return [
            'work_days'   => 0.0,
            'leave_days'  => 0,
            'due_days'    => 0.0,
            'day_hours'   => 0.0,
            'no_expected' => true,
        ];
    }

    if ($workHours < 0.0) {
        $workHours = 0.0;
    }

    $dayHours = $expectedMonthlyHours / 30.0;
    $workDays = $dayHours > 0.0 ? ($workHours / $dayHours) : 0.0;

    $leaveDays = (int) floor($workDays / 6.0);
    if ($leaveDays < 0) {
        $leaveDays = 0;
    }
    if ($leaveDays > 4) {
        $leaveDays = 4;
    }

    $dueDays = $workDays + $leaveDays;

    return [
        'work_days'   => round($workDays, 4),
        'leave_days'  => $leaveDays,
        'due_days'    => round($dueDays, 4),
        'day_hours'   => round($dayHours, 4),
        'no_expected' => false,
    ];
}

/**
 * حساب حالة فرق الوردية (عجز أو زيادة) وعتبة التسامح:
 * - kind: 'shortage' (عجز لو difference < 0) · 'over' (زيادة لو difference > 0) · 'none' (لو 0)
 * - amount: القيمة المطلقة للفرق (abs($difference))
 * - tolerance: عتبة التسامح المستعملة
 * - exceeded: المبلغ المتجاوز أو المخصوم = لو تجاوز العتبة يتخصم المبلغ كامل ($amount)، لو داخلها أو مساويها 0.0 (قاعدة بوابة: ٣١ ← ٣١، ٣٠ ← ٠)
 * - above_tolerance: الجزء فوق العتبة = max(0, amount - tolerance) للعرض المعلوماتي فقط
 * - over_limit: هل تجاوز العتبة؟ (amount - tolerance > 0.0001)
 * - status_label: 'داخل العتبة' أو 'تجاوز العتبة'
 *
 * ⚠️ المصدر الموحد للحقيقة لمنطق العتبة في تقرير العجوزات وتاب الجزاءات.
 *
 * @param float $difference فرق الوردية (سالب=عجز، موجب=زيادة)
 * @param float|null $tolerance العتبة (افتراضي من إعداد custody_deficit_tolerance)
 * @return array{kind: string, amount: float, tolerance: float, exceeded: float, above_tolerance: float, over_limit: bool, status_label: string}
 */
function shift_variance_status(float $difference, ?float $tolerance = null): array
{
    if ($tolerance === null) {
        $tolerance = custody_tolerance();
    }
    if ($tolerance < 0) {
        $tolerance = 0.0;
    }

    $amount = abs($difference);
    if ($difference < -0.0001) {
        $kind = 'shortage';
    } elseif ($difference > 0.0001) {
        $kind = 'over';
    } else {
        $kind = 'none';
    }

    // العتبة بوابة: اللي فوقها يتخصم كامل ($amount)، واللي تحتها أو مساويها يتسامح (0.0)
    // مثال: عتبة ٣٠ ← عجز ٣٠ يتسامح (0)، عجز ٣١ يتخصم ٣١ كاملة
    $aboveTolerance = max(0.0, round($amount - $tolerance, 2));
    $overLimit = ($amount - $tolerance) > 0.0001;
    $exceeded = $overLimit ? round($amount, 2) : 0.0;
    $statusLabel = $overLimit ? 'تجاوز العتبة' : 'داخل العتبة';

    return [
        'kind'            => $kind,
        'amount'          => round($amount, 2),
        'tolerance'       => round($tolerance, 2),
        'exceeded'        => $exceeded,
        'above_tolerance' => $aboveTolerance,
        'over_limit'      => $overLimit,
        'status_label'    => $statusLabel,
    ];
}

/**
 * عتبة التسامح لعجز/زيادة الكاش — مصدر واحد لكل الصفحات.
 * كانت متكتبة في ٥ أماكن بافتراضيين مختلفين (٣٠ و٠)، والنتيجة كانت
 * إن تقفيل العهدة بيتعامل بعتبة ٣٠ وتاب الجزاءات بعتبة ٠.
 */
function custody_tolerance(): float
{
    $t = (float) setting('custody_deficit_tolerance', '30');
    return $t > 0 ? $t : 0.0;
}

/**
 * بداية اليوم المحاسبي بالساعات (0..23) — مصدر واحد لكل الصفحات.
 * بتُستخدم لتحديد نافذة الورديات اللي بتعدّي منتصف الليل،
 * والتبديل بيتم من صفحة الإعدادات (business_day_start).
 */
function business_day_start_hour(): int
{
    $t = (string) setting('business_day_start', '09:00');
    $h = (int) substr($t, 0, 2);
    return ($h >= 0 && $h <= 23) ? $h : 9;
}

/**
 * هل نرحّل باقي عجز الكاشير لسلفة مستديمة؟
 *
 * 🔴 الافتراضي **أيوه** (`'1'`) عشان السلوك مايتغيّرش لوحده لو المفتاح
 *    مش موجود في `settings` لسه. اللي يقفله محمد من الإعدادات بإيده.
 *
 * السبب اللي المفتاح ده اتعمل عشانه (٣١ أغسطس ٢٠٢٦): ورديات كتير
 * اتسجّلت غلط من الفروع، فالنظام كان بيحوّل فروق وهمية لدين حقيقي —
 * موظفة عليها ٢,٠٠٠ ج في النظام وهي فعلياً عندها زيادة ٢ ج.
 */
function cashier_deficit_carry_enabled(): bool
{
    return (string) setting('cashier_deficit_carry', '1') === '1';
}

/**
 * حساب صافي مرتب موظف لشهر معيّن وفترة حضور محددة (مع المكافآت والخصومات).
 *
 * القواعد المعتمدة من المالك:
 * - حساب أيام العمل والإجازة والاستحقاق من salary_days_from_hours() بناءً على الساعات المنتظرة
 * - سعر الساعة       = الراتب الأساسي ÷ الساعات المنتظرة
 * - قيمة اليوم       = الراتب الأساسي ÷ 30 (ثابتة دائماً)
 * - الراتب قبل الخصم = قيمة اليوم × أيام الاستحقاق = سعر الساعة × (الساعات الفعلية + أيام الإجازة × ساعات اليوم)
 * - الصافي          = الراتب قبل الخصم + المكافآت − السلف − الجزاءات − عجز العهدة
 *
 * @param int $employeeId معرّف الموظف
 * @param string $month شهر الراتب (YYYY-MM)
 * @param string|null $from بداية فترة الحضور (افتراضي أول يوم في الشهر)
 * @param string|null $to نهاية فترة الحضور (افتراضي آخر يوم في الشهر)
 * @param int|null $leaveDaysOverride أيام إجازة معدّلة يدوياً بواسطة الأونر
 * @param float|null $dueDaysOverride أيام استحقاق معدّلة يدوياً بواسطة الأونر (float لدعم الكسور)
 * @return array تفاصيل المرتب وحساب الساعات والأيام للعرض قبل الاعتماد
 */
function compute_salary(
    int $employeeId,
    string $month,
    ?string $from = null,
    ?string $to = null,
    ?int $leaveDaysOverride = null,
    ?float $dueDaysOverride = null,
    ?float $monthlyTakeOverride = null,
    ?float $standingTakeOverride = null,
    ?float $cashierTakeOverride = null
): array
{
    $pdo = db();

    // ضبط فترة الحضور الافتراضية لأول وآخر يوم في الشهر إن لم تُحدد
    if ($from === null || $from === '') {
        $from = date('Y-m-01', strtotime($month . '-01'));
    }
    if ($to === null || $to === '') {
        $to = date('Y-m-t', strtotime($month . '-01'));
    }

    $emp = $pdo->prepare('SELECT full_name, base_salary, expected_monthly_hours, salary_mode FROM employees WHERE id = ?');
    $emp->execute([$employeeId]);
    $empRow = $emp->fetch(PDO::FETCH_ASSOC);
    $empName = $empRow['full_name'] ?? ('موظف #' . $employeeId);
    $base = (float) ($empRow['base_salary'] ?? 0);
    $salaryMode = $empRow['salary_mode'] ?? 'hours';
    $isFixed = ($salaryMode === 'fixed');
    $expectedHours = isset($empRow['expected_monthly_hours']) && $empRow['expected_monthly_hours'] !== null ? (float) $empRow['expected_monthly_hours'] : null;

    if ($isFixed) {
        // مسار المرتب الثابت: الاستحقاق قبل الخصم = الأساسي كامل بدون حساب ساعات أو أيام
        $noExpected    = false;
        $workHours     = 0.0;
        $workDays      = 0.0;
        $leaveDaysCalc = 0;
        $leaveDays     = 0;
        $dueDaysCalc   = 0.0;
        $dueDays       = 0.0;
        $dayHours      = 0.0;
        $hourRate      = 0.0;
        $dayRate       = $base > 0 ? ($base / 30.0) : 0.0;
        $grossSalary   = $base;
    } else {
        // مسار حساب الساعات: إجمالي ساعات العمل في فترة الحضور المحددة (استعلام واحد)
        $att = $pdo->prepare('SELECT COALESCE(SUM(work_hours), 0) FROM attendance WHERE employee_id = ? AND att_date BETWEEN ? AND ?');
        $att->execute([$employeeId, $from, $to]);
        $workHours = (float) $att->fetchColumn();

        // حساب أيام العمل والإجازة والاستحقاق من الدالة الموحدة
        $salDays       = salary_days_from_hours($workHours, $expectedHours);
        $noExpected    = !empty($salDays['no_expected']);
        $workDays      = $salDays['work_days'];
        $leaveDaysCalc = $salDays['leave_days'];
        $leaveDays     = ($leaveDaysOverride !== null && $leaveDaysOverride >= 0) ? $leaveDaysOverride : $leaveDaysCalc;
        $dueDaysCalc   = round($workDays + $leaveDays, 4);

        // ترتيب الأولوية لأيام الاستحقاق:
        // الخانة في المتصفح بتعرض خانتين عشرية، فالحد الأقصى لخطأ التقريب لوحده هو 0.005 (نص أصغر خانة معروضة 0.01 ÷ 2).
        // 1. لو $dueDaysOverride مبعوت والفرق عن المحسوب أكبر من 0.005 ← ده تعديل يدوي حقيقي من الأونر ونعتمد القيمة المبعوتة.
        // 2. لو $dueDaysOverride هو null أو الفرق <= 0.005 ← نعتبره مش تعديل (مجرد تقريب العرض) ونعتمد المحسوب الدقيق.
        if ($dueDaysOverride !== null && abs($dueDaysOverride - $dueDaysCalc) > 0.005) {
            $dueDays = round((float) $dueDaysOverride, 4);
        } else {
            $dueDays = $dueDaysCalc;
        }

        $dayHours      = $salDays['day_hours'];
        $hourRate      = ($expectedHours !== null && $expectedHours > 0) ? ($base / $expectedHours) : 0.0;
        $dayRate       = $base > 0 ? ($base / 30.0) : 0.0;
        $grossSalary   = $noExpected ? 0.0 : ($dayRate * $dueDays);
    }

    // المكافآت المعلّقة
    $bon = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM employee_bonuses WHERE employee_id=? AND status="pending"');
    $bon->execute([$employeeId]);
    $bonuses = (float) $bon->fetchColumn();

    // السلف المعلّقة (حساب الأقساط الشهرية أو كامل المبلغ المتبقي لو القسط 0 مع سقف رصيد السجل الموجب)
    // ⚠️ المعادلة مكررة بين هنا و pay_salary و hr.php ($advMap) — لو غيّرت هنا، غيّر في التلاتة.
    $ledgerBal = emp_ledger_balance($employeeId, $pdo);
    $remCap = $ledgerBal > 0 ? $ledgerBal : 0.0;

    $advStmt = $pdo->prepare(
        'SELECT id, amount, monthly_deduction, remaining, adv_type
         FROM employee_advances
         WHERE employee_id = ? AND status = "pending"
         ORDER BY id ASC'
    );
    $advStmt->execute([$employeeId]);
    $pendingAdvances = $advStmt->fetchAll(PDO::FETCH_ASSOC);

    $monthlyTakeCalc = 0.0;
    $standingTakeCalc = 0.0;

    foreach ($pendingAdvances as $advRow) {
        $origAmount = (float) $advRow['amount'];
        $monthlyDed = (float) $advRow['monthly_deduction'];
        $advType = $advRow['adv_type'] ?? 'monthly';
        $outstanding = $advRow['remaining'] !== null ? (float) $advRow['remaining'] : $origAmount;

        $take = ($monthlyDed > 0) ? min($monthlyDed, $outstanding) : $outstanding;
        $take = min($take, $remCap);
        $remCap -= $take;

        if ($advType === 'standing') {
            $standingTakeCalc += $take;
        } else {
            $monthlyTakeCalc += $take;
        }
    }

    $monthlyTake = ($monthlyTakeOverride !== null && $monthlyTakeOverride >= 0) ? $monthlyTakeOverride : $monthlyTakeCalc;
    $standingTake = ($standingTakeOverride !== null && $standingTakeOverride >= 0) ? $standingTakeOverride : $standingTakeCalc;

    $totalAdvances = $monthlyTake + $standingTake;
    $maxCap = $ledgerBal > 0 ? $ledgerBal : 0.0;
    $totalAdvances = min($totalAdvances, $maxCap);

    // الجزاءات المعلّقة
    $pen = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM employee_penalties WHERE employee_id=? AND status="pending"');
    $pen->execute([$employeeId]);
    $penalties = (float) $pen->fetchColumn();

    // عجز العهدة المعلّق (المربوط بالموظف)
    $def = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM custody_deficits WHERE employee_id=? AND status="pending"');
    $def->execute([$employeeId]);
    $deficits = (float) $def->fetchColumn();

    // عجز الكاشير المتجاوز للعتبة في الفترة المحددة (غير المخصوم مسبقاً)
    $tolerance = custody_tolerance();
    $stCashierDef = $pdo->prepare(
        "SELECT s.id AS shift_id,
                s.business_date AS shift_date,
                s.difference AS variance,
                ? AS tolerance,
                ABS(s.difference) AS exceeded
           FROM shifts s
           JOIN users     u ON u.id = s.user_id
           JOIN employees e ON e.user_id = u.id
           LEFT JOIN salary_shift_deficits d ON d.shift_id = s.id
          WHERE e.id = ?
            AND s.business_date BETWEEN ? AND ?
            AND s.difference < 0
            AND ABS(s.difference) - ? > 0.0001
            AND d.id IS NULL
          ORDER BY s.id ASC"
    );
    $stCashierDef->execute([$tolerance, $employeeId, $from, $to, $tolerance]);
    $cashierDefRows = $stCashierDef->fetchAll(PDO::FETCH_ASSOC);

    $cashierDefCalc = 0.0;
    $cashierShiftIds = [];
    $cashierShiftDetails = [];
    foreach ($cashierDefRows as $cRow) {
        $exceeded = round((float)$cRow['exceeded'], 2);
        $cashierDefCalc += $exceeded;
        $cashierShiftIds[] = (int)$cRow['shift_id'];
        $cashierShiftDetails[] = [
            'shift_id'   => (int)$cRow['shift_id'],
            'shift_date' => (string)$cRow['shift_date'],
            'variance'   => round((float)$cRow['variance'], 2),
            'tolerance'  => round((float)$cRow['tolerance'], 2),
            'exceeded'   => $exceeded,
        ];
    }
    $cashierDefCalc = round($cashierDefCalc, 2);

    $cashierDeficits = ($cashierTakeOverride !== null && $cashierTakeOverride >= 0)
        ? min(round((float)$cashierTakeOverride, 2), $cashierDefCalc)
        : $cashierDefCalc;

    // ---------------------------------------------------------------
    // ترتيب الخصومات وسقف المرتب: المرتب بيتصفّر ومابينزلش تحت الصفر.
    //
    // قرار محمد: نخصم بالترتيب لحد ما المرتب يبقى صفر، واللي مقدرناش
    // نخصمه ينزل سلفة مستديمة على الموظف. مفيش صافي سالب ومفيش فلوس تضيع.
    //
    // الترتيب الملزم:
    // الوعاء = الاستحقاق قبل الخصم + المكافآت
    // ١) السلف      = min(المحسوب, سقف رصيد السجل, الوعاء)  ← الوعاء -= اللي اتخصم
    // ٢) الجزاءات    = min(الجزاءات, الوعاء)                 ← الوعاء -= اللي اتخصم
    // ٣) عجز العهدة  = min(عجز العهدة, الوعاء)               ← الوعاء -= اللي اتخصم
    // ٤) عجز الكاشير = min(المخصوم — وسقفه المحسوب —, الوعاء)  ← الوعاء -= اللي اتخصم
    //
    // الصافي = الوعاء (لازم >= 0 دايماً)
    // المرحّل = (الجزاءات − المخصوم) + (عجز العهدة − المخصوم) + (عجز الكاشير المحسوب − المخصوم)
    // السلف مالهاش مرحّل — الباقي بيفضل في employee_advances.remaining تلقائي.
    // ---------------------------------------------------------------
    $pot = $noExpected ? 0.0 : ($grossSalary + $bonuses);

    // ١) السلف = min(المحسوب, سقف رصيد السجل, الوعاء)
    $advApplied = min($totalAdvances, $pot);
    $advApplied = max(0.0, round($advApplied, 2));
    $pot -= $advApplied;

    // ٢) الجزاءات = min(الجزاءات, الوعاء)
    $penaltiesFull = $penalties;
    $penApplied = min($penaltiesFull, $pot);
    $penApplied = max(0.0, round($penApplied, 2));
    $pot -= $penApplied;

    // ٣) عجز العهدة = min(عجز العهدة, الوعاء)
    $deficitsFull = $deficits;
    $defApplied = min($deficitsFull, $pot);
    $defApplied = max(0.0, round($defApplied, 2));
    $pot -= $defApplied;

    // ٤) عجز الكاشير = min(المخصوم — وسقفه المحسوب —, الوعاء)
    $cashierTake = ($cashierTakeOverride !== null && $cashierTakeOverride >= 0)
        ? min(round((float)$cashierTakeOverride, 2), $cashierDefCalc)
        : $cashierDefCalc;
    $cashierApplied = min($cashierTake, $pot);
    $cashierApplied = max(0.0, round($cashierApplied, 2));
    $pot -= $cashierApplied;

    $netSalary = $noExpected ? 0.0 : max(0.0, round($pot, 2));

    // 🔴 حد عجز الكاشير بس هو اللي بيتحكم فيه المفتاح.
    //    الجزاءات وعجز العهدة **بيترحّلوا زي ما هما** — محمد طلب
    //    عجز الكاشير بالتحديد، وده اللي بيانات الورديات مشكوك فيها.
    $cashierCarry = cashier_deficit_carry_enabled()
        ? max(0.0, $cashierDefCalc - $cashierApplied)
        : 0.0;

    $carryToAdvance = round(($penaltiesFull - $penApplied)
                          + ($deficitsFull  - $defApplied)
                          + $cashierCarry, 2);
    $carryToAdvance = max(0.0, $carryToAdvance);

    return [
        'employee_name'          => $empName,
        'salary_mode'            => $salaryMode,
        'base_salary'            => $base,
        'expected_monthly_hours' => $expectedHours,
        'no_expected'            => $noExpected,
        'day_hours'              => $dayHours,
        'hour_rate'              => $hourRate,
        'total_bonuses'          => $bonuses,
        'total_advances'         => $advApplied,
        'total_penalties'        => $penApplied,
        'total_deficits'         => $defApplied,
        'total_cashier_deficits' => $cashierApplied,
        'penalties_full'         => $penaltiesFull,
        'deficits_full'          => $deficitsFull,
        'carry_to_advance'       => $carryToAdvance,
        'cashier_shift_ids'      => $cashierShiftIds,
        'cashier_shift_details'  => $cashierShiftDetails,
        'cashier_deficits_calc'  => $cashierDefCalc,
        'net_salary'             => $netSalary,
        'period_from'            => $from,
        'period_to'              => $to,
        'work_hours'             => $workHours,
        'work_days'              => $workDays,
        'leave_days_calc'        => $leaveDaysCalc,
        'leave_days'             => $leaveDays,
        'due_days_calc'          => $dueDaysCalc,
        'due_days'               => $dueDays,
        'day_rate'               => $dayRate,
        'gross_salary'           => $grossSalary,
    ];
}

/**
 * اعتماد وصرف مرتب موظف لشهر.
 * - بيحسب المرتب وفترة الحضور، يسجّله، يصرفه من الخزينة (مدين بالصافي).
 * - يتيح للأونر تعديل أيام الإجازة والاستحقاق والمخصوم من السلف وعجز الكاشير مع حفظ التعديلات.
 * - بيقفل المكافآت والسلف والجزاءات وعجز العهدة المعلّقة (paid/deducted/recovered).
 * - بيسجّل الورديات التي تم فحص عجزها في salary_shift_deficits لمنع تكرار الخصم، ويحوّل باقي الخصومات غير المخصومة لسلفة مستديمة.
 *
 * @return int معرّف المرتب
 */
function pay_salary(
    int $employeeId,
    string $month,
    int $treasuryId,
    string $date,
    ?int $userId = null,
    ?string $from = null,
    ?string $to = null,
    ?int $leaveDaysOverride = null,
    ?float $dueDaysOverride = null,
    ?float $monthlyTakeOverride = null,
    ?float $standingTakeOverride = null,
    ?float $cashierTakeOverride = null
): int {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // تأكد إن المرتب مش مصروف قبل كده للشهر ده
        $chk = $pdo->prepare('SELECT id FROM salaries WHERE employee_id=? AND salary_month=? AND reversed_at IS NULL');
        $chk->execute([$employeeId, $month]);
        if ($chk->fetchColumn()) {
            throw new RuntimeException('مرتب هذا الشهر مسجّل بالفعل.');
        }

        // نقفل المعلّق قبل الحساب، ونقفل صرف نفس الصفوف بس بعده.
        $penaltyLock = $pdo->prepare("SELECT id FROM employee_penalties WHERE employee_id = ? AND status = 'pending' FOR UPDATE");
        $penaltyLock->execute([$employeeId]);
        $penaltyIds = $penaltyLock->fetchAll(PDO::FETCH_COLUMN);
        $bonusLock = $pdo->prepare("SELECT id FROM employee_bonuses WHERE employee_id = ? AND status = 'pending' FOR UPDATE");
        $bonusLock->execute([$employeeId]);
        $bonusIds = $bonusLock->fetchAll(PDO::FETCH_COLUMN);
        $deficitLock = $pdo->prepare("SELECT id FROM custody_deficits WHERE employee_id = ? AND status = 'pending' FOR UPDATE");
        $deficitLock->execute([$employeeId]);
        $deficitIds = $deficitLock->fetchAll(PDO::FETCH_COLUMN);

        $calc = compute_salary($employeeId, $month, $from, $to, $leaveDaysOverride, $dueDaysOverride, $monthlyTakeOverride, $standingTakeOverride, $cashierTakeOverride);
        $name = $calc['employee_name'] ?? ('الموظف #' . $employeeId);
        $isFixed = ($calc['salary_mode'] ?? 'hours') === 'fixed';

        // حماية طبقة الفلوس: منع صرف مرتب أي موظف على الساعات لم تُحدد ساعاته المنتظرة
        if (!$isFixed && !empty($calc['no_expected'])) {
            throw new RuntimeException("لا يمكن صرف المرتب للموظف ($name) لعدم تحديد الساعات المنتظرة شهرياً. يرجى تحديدها من شاشة الموظفين أولاً.");
        }

        // معالجة وتدقيق أيام العمل والإجازة والاستحقاق
        if ($isFixed) {
            $workHours   = null;
            $workDays    = null;
            $leaveDays   = 0;
            $dueDaysCalc = null;
            $dueDays     = null;
            $editedBy    = null;
            $editedAt    = null;
            $grossSalary = (float) $calc['base_salary'];
        } else {
            $workHours     = (float) $calc['work_hours'];
            $workDays      = (float) $calc['work_days'];
            $leaveDaysCalc = (int) $calc['leave_days_calc'];
            $leaveDays     = (int) $calc['leave_days'];
            $dueDaysCalc   = (float) $calc['due_days_calc'];
            $dueDays       = (float) $calc['due_days'];
            $editedBy      = null;
            $editedAt      = null;

            if ($leaveDaysOverride !== null) {
                if ($leaveDaysOverride < 0 || $leaveDaysOverride > 60) {
                    throw new RuntimeException('أيام الإجازة المعدّلة غير صحيحة (يجب أن تكون بين 0 و 60 يوم).');
                }
            }

            if ($dueDaysOverride !== null) {
                if ($dueDaysOverride < 0 || $dueDaysOverride > 60) {
                    throw new RuntimeException('أيام الاستحقاق المعدّلة غير صحيحة (يجب أن تكون بين 0 و 60 يوم).');
                }
            }

            // التدقيق: الخانة بتعرض خانتين عشرية (أقصى خطأ تقريب 0.01 ÷ 2 = 0.005).
            // لو الفرق أكبر من 0.005 يبقى ده تعديل حقيقي من الأونر مش مجرد تقريب، فنسجّل مين عدّل وتاريخ التعديل.
            if ($dueDaysOverride !== null && abs($dueDaysOverride - $dueDaysCalc) > 0.005) {
                $editedBy = $userId;
                $editedAt = date('Y-m-d H:i:s');
            }

            $grossSalary = $calc['day_rate'] * $dueDays;
        }

        // ---------------------------------------------------------------
        // ترتيب الخصومات وسقف المرتب: المرتب بيتصفّر ومابينزلش تحت الصفر.
        //
        // 🔴 بنعيد تطبيق الترتيب بنفسه هنا مش بناخد اللي جه من compute_salary()
        // — لأن $grossSalary هنا ممكن يكون اتغيّر بسبب $dueDaysOverride.
        //
        // الترتيب الملزم:
        // الوعاء = الاستحقاق قبل الخصم + المكافآت
        // ١) السلف      = min(المحسوب, سقف رصيد السجل, الوعاء)  ← الوعاء -= اللي اتخصم
        // ٢) الجزاءات    = min(الجزاءات, الوعاء)                 ← الوعاء -= اللي اتخصم
        // ٣) عجز العهدة  = min(عجز العهدة, الوعاء)               ← الوعاء -= اللي اتخصم
        // ٤) عجز الكاشير = min(المخصوم, الوعاء)                  ← الوعاء -= اللي اتخصم
        //
        // الصافي = الوعاء (لازم >= 0 دايماً)
        // المرحّل = (الجزاءات − المخصوم) + (عجز العهدة − المخصوم) + (عجز الكاشير المحسوب − المخصوم)
        // السلف مالهاش مرحّل — الباقي بيفضل في employee_advances.remaining تلقائي.
        // ---------------------------------------------------------------
        $pot = $grossSalary + (float)$calc['total_bonuses'];

        // ١) تسوية أقساط السلف المعلّقة للموظف (قراءة مجمّعة وتحديث الصفوف داخل المعاملة مع سقف رصيد السجل والوعاء)
        // ⚠️ المعادلة مكررة بين هنا و compute_salary و hr.php ($advMap) — لو غيّرت هنا، غيّر في التلاتة.
        $ledgerBalance = emp_ledger_balance($employeeId, $pdo);
        $maxLedgerCap = $ledgerBalance > 0 ? $ledgerBalance : 0.0;
        $remCap = min($maxLedgerCap, $pot);

        $advStmt = $pdo->prepare(
            'SELECT id, amount, monthly_deduction, remaining, adv_type
             FROM employee_advances
             WHERE employee_id = ? AND status = "pending"
             ORDER BY id ASC FOR UPDATE'
        );
        $advStmt->execute([$employeeId]);
        $pendingAdvances = $advStmt->fetchAll(PDO::FETCH_ASSOC);

        // حساب الأقساط المحسوبة لكل نوع كقيمة افتراضية عند عدم وجود override
        $monthlyCapCalc = 0.0;
        $standingCapCalc = 0.0;
        foreach ($pendingAdvances as $advRow) {
            $origAmount = (float) $advRow['amount'];
            $monthlyDed = (float) $advRow['monthly_deduction'];
            $advType = $advRow['adv_type'] ?? 'monthly';
            $outstanding = $advRow['remaining'] !== null ? (float) $advRow['remaining'] : $origAmount;
            $defaultTake = ($monthlyDed > 0) ? min($monthlyDed, $outstanding) : $outstanding;
            if ($advType === 'standing') {
                $standingCapCalc += $defaultTake;
            } else {
                $monthlyCapCalc += $defaultTake;
            }
        }

        $monthlyTypeCap = ($monthlyTakeOverride !== null && $monthlyTakeOverride >= 0) ? (float)$monthlyTakeOverride : $monthlyCapCalc;
        $standingTypeCap = ($standingTakeOverride !== null && $standingTakeOverride >= 0) ? (float)$standingTakeOverride : $standingCapCalc;

        $updAdvStmt = $pdo->prepare(
            'UPDATE employee_advances
             SET remaining = ?, status = ?, adv_type = ?
             WHERE id = ?'
        );

        $advApplied = 0.0;
        $advRecoveries = [];
        foreach ($pendingAdvances as $advRow) {
            $advId = (int) $advRow['id'];
            $origAmount = (float) $advRow['amount'];
            $advType = $advRow['adv_type'] ?? 'monthly';
            $outstanding = $advRow['remaining'] !== null ? (float) $advRow['remaining'] : $origAmount;

            if ($advType === 'standing') {
                $take = min($outstanding, $standingTypeCap, $remCap);
                $take = max(0.0, round($take, 2));
                $standingTypeCap -= $take;
            } else {
                $take = min($outstanding, $monthlyTypeCap, $remCap);
                $take = max(0.0, round($take, 2));
                $monthlyTypeCap -= $take;
            }
            $remCap -= $take;

            if ($take <= 0.00001) {
                continue;
            }

            $advApplied += $take;
            $advRecoveries[] = ['advance_id' => $advId, 'amount' => $take];
            $newRemaining = round($outstanding - $take, 2);

            if ($newRemaining <= 0.00001) {
                $updAdvStmt->execute([0.00, 'deducted', $advType, $advId]);
            } else {
                $newType = ($advType === 'monthly') ? 'standing' : $advType;
                $updAdvStmt->execute([$newRemaining, 'pending', $newType, $advId]);
            }

            // تسجيل الخصم في سجل حساب الموظف — بالمبلغ اللي اتخصم الشهر ده بس ($take)
            // مش المتبقي كله ولا المبلغ الأصلي. جوّه نفس المعاملة عشان لو المرتب اترجع يترجع معاه.
            emp_ledger_add(
                $employeeId,
                'credit',
                $take,
                $date,
                'salary_deduction',
                'خصم سلفة من مرتب شهر ' . $month,
                null,
                'advance',
                $advId,
                $userId,
                $pdo
            );
        }
        $advApplied = round($advApplied, 2);
        $pot -= $advApplied;

        // ٢) الجزاءات = min(الجزاءات, الوعاء)
        $penaltiesFull = (float)($calc['penalties_full'] ?? $calc['total_penalties']);
        $penApplied = min($penaltiesFull, $pot);
        $penApplied = max(0.0, round($penApplied, 2));
        $pot -= $penApplied;

        // ٣) عجز العهدة = min(عجز العهدة, الوعاء)
        $deficitsFull = (float)($calc['deficits_full'] ?? $calc['total_deficits']);
        $defApplied = min($deficitsFull, $pot);
        $defApplied = max(0.0, round($defApplied, 2));
        $pot -= $defApplied;

        // ٤) عجز الكاشير = min(المخصوم — وسقفه المحسوب —, الوعاء)
        $cashierCalcTotal = (float)($calc['cashier_deficits_calc'] ?? 0.0);
        $cashierTake = ($cashierTakeOverride !== null && $cashierTakeOverride >= 0)
            ? min(round((float)$cashierTakeOverride, 2), $cashierCalcTotal)
            : $cashierCalcTotal;
        $cashierApplied = min($cashierTake, $pot);
        $cashierApplied = max(0.0, round($cashierApplied, 2));
        $pot -= $cashierApplied;

        $netSalary = max(0.0, round($pot, 2));

        // 🔴 حد عجز الكاشير بس هو اللي بيتحكم فيه المفتاح.
        //    الجزاءات وعجز العهدة **بيترحّلوا زي ما هما** — محمد طلب
        //    عجز الكاشير بالتحديد، وده اللي بيانات الورديات مشكوك فيها.
        //    ⚠️ المتغيّر هنا اسمه $cashierCalcTotal (مش $cashierDefCalc زي compute_salary).
        $cashierCarry = cashier_deficit_carry_enabled()
            ? max(0.0, $cashierCalcTotal - $cashierApplied)
            : 0.0;

        $carryToAdvance = round(($penaltiesFull - $penApplied)
                              + ($deficitsFull  - $defApplied)
                              + $cashierCarry, 2);
        $carryToAdvance = max(0.0, $carryToAdvance);

        $empName = $pdo->prepare('SELECT full_name FROM employees WHERE id=?');
        $empName->execute([$employeeId]);
        $name = $empName->fetchColumn() ?: $name;

        // تسجيل المرتب في جدول salaries (20 عمود و 18 علامة ? بالإضافة لـ "paid" و NOW())
        $pdo->prepare(
            'INSERT INTO salaries
                (employee_id, salary_month, base_salary, total_bonuses, total_advances, total_penalties,
                 total_deficits, total_cashier_deficits, net_salary, status, paid_at,
                 period_from, period_to, work_hours, work_days, leave_days,
                 due_days_calc, due_days, due_days_edited_by, due_days_edited_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "paid", NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $employeeId, $month, $calc['base_salary'], $calc['total_bonuses'], $advApplied,
            $penApplied, $defApplied, $cashierApplied, $netSalary,
            $calc['period_from'], $calc['period_to'], $workHours, $workDays,
            $leaveDays, $dueDaysCalc, $dueDays, $editedBy, $editedAt,
        ]);
        $salaryId = (int) $pdo->lastInsertId();

        // ربط السلف المستردة بالمرتب في salary_advance_recoveries (استعلام واحد مجمّع)
        if (!empty($advRecoveries)) {
            $sarPlaceholders = [];
            $sarValues = [];
            foreach ($advRecoveries as $rec) {
                $sarPlaceholders[] = '(?, ?, ?)';
                $sarValues[] = $salaryId;
                $sarValues[] = $rec['advance_id'];
                $sarValues[] = $rec['amount'];
            }
            $sqlSar = 'INSERT INTO salary_advance_recoveries (salary_id, advance_id, amount) VALUES ' . implode(', ', $sarPlaceholders);
            $pdo->prepare($sqlSar)->execute($sarValues);
        }

        // صرف الصافي من الخزينة (لو موجب)
        if ($netSalary > 0) {
            $salaryAccountId = null;
            try {
                $accountCheck = $pdo->prepare('SELECT id FROM accounts WHERE id = ? AND is_active = 1');
                $accountCheck->execute([SALARY_ACCOUNT_ID]);
                if ($accountCheck->fetchColumn() !== false) {
                    $salaryAccountId = SALARY_ACCOUNT_ID;
                } else {
                    error_log('حساب المرتبات مش موجود أو مش نشط؛ المرتب هيتسجل من غير قيد حساب.');
                }
            } catch (Throwable $ex) {
                error_log('فحص حساب المرتبات ماكملش؛ المرتب هيتسجل من غير قيد حساب: ' . $ex->getMessage());
            }
            post_journal($treasuryId, $netSalary, 0,
                'صرف مرتب: ' . $name . ' (' . $month . ')', $date,
                'salary', $salaryId, $userId, $pdo, $salaryAccountId);
        }

        // إقفال المستحقات والخصومات المعلّقة مع تسجيل ربط المرتب
        if (!empty($bonusIds)) {
            $bonusPlaceholders = implode(',', array_fill(0, count($bonusIds), '?'));
            $pdo->prepare('UPDATE employee_bonuses SET status="paid", paid_in_salary_id=? WHERE id IN (' . $bonusPlaceholders . ') AND status="pending"')
                ->execute(array_merge([$salaryId], $bonusIds));
        }

        if (!empty($penaltyIds)) {
            $penaltyPlaceholders = implode(',', array_fill(0, count($penaltyIds), '?'));
            $pdo->prepare('UPDATE employee_penalties SET status="deducted", deducted_in_salary_id=? WHERE id IN (' . $penaltyPlaceholders . ') AND status="pending"')
                ->execute(array_merge([$salaryId], $penaltyIds));
        }
        if (!empty($deficitIds)) {
            $deficitPlaceholders = implode(',', array_fill(0, count($deficitIds), '?'));
            $pdo->prepare('UPDATE custody_deficits SET status="recovered", recovered_in_salary_id=? WHERE id IN (' . $deficitPlaceholders . ') AND status="pending"')
                ->execute(array_merge([$salaryId], $deficitIds));
        }

        // (٢-أ) إقفال الورديات التي تم فحص عجزها في الفترة (جملة INSERT واحدة بقيم متعددة)
        if (!empty($calc['cashier_shift_details'])) {
            $placeholders = [];
            $shiftValues = [];
            foreach ($calc['cashier_shift_details'] as $sr) {
                $placeholders[] = '(?, ?, ?, ?, ?, ?, ?)';
                $shiftValues[] = $salaryId;
                $shiftValues[] = (int)$sr['shift_id'];
                $shiftValues[] = $employeeId;
                $shiftValues[] = $sr['shift_date'];
                $shiftValues[] = (float)$sr['variance'];
                $shiftValues[] = (float)$sr['tolerance'];
                $shiftValues[] = (float)$sr['exceeded'];
            }
            $sqlShifts = 'INSERT INTO salary_shift_deficits (salary_id, shift_id, employee_id, shift_date, variance, tolerance, exceeded) VALUES ' . implode(', ', $placeholders);
            $pdo->prepare($sqlShifts)->execute($shiftValues);
        }

        // (٢-ب) سلفة المرحّل: باقي الخصومات (جزاءات + عجز عهدة + عجز كاشير) غير المخصومة يتحول لسلفة مستديمة وقيد debit في سجل الموظف
        if ($carryToAdvance > 0.005) {
            $insStandingAdv = $pdo->prepare(
                'INSERT INTO employee_advances
                    (employee_id, amount, advance_date, status, notes, monthly_deduction, remaining, adv_type)
                 VALUES (?, ?, ?, "pending", ?, 0.00, ?, "standing")'
            );
            $advNote = 'باقي خصومات من مرتب شهر ' . $month;
            $insStandingAdv->execute([$employeeId, $carryToAdvance, $date, $advNote, $carryToAdvance]);
            $newAdvId = (int)$pdo->lastInsertId();

            emp_ledger_add(
                $employeeId,
                'debit',
                $carryToAdvance,
                $date,
                'advance',
                'باقي خصومات اتحوّلت لسلفة مستديمة — شهر ' . $month,
                null,
                'advance',
                $newAdvId,
                $userId,
                $pdo
            );
        }

        $pdo->commit();
        return $salaryId;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * إرجاع صرف مرتب اتصرف بالخطأ.
 * - بقيد عكسي في اليومية (استلام = دائن بالصافي على نفس الخزينة بنفس التاريخ الحالي).
 * - استرجاع مبالغ السلف المستقطعة لحالة pending وزيادة remaining باستعلام مجمّع.
 * - إعادة المكافآت والجزاءات المرتبطة بهذا المرتب فقط لحالة pending.
 * - إعادة عجز العهدة لحالة pending وتصفير recovered_in_salary_id.
 * - حذف سجلات الورديات المفحوصة من salary_shift_deficits.
 * - إلغاء السلفة المرحّلة (إن وُجدت) مع قيد عكسي credit في سجل الموظف.
 * - تسجيل قيود عكسية في employee_ledger لكل خصم سلفة تم استرجاعه.
 * - تحديث سجل المرتب ببيانات الإرجاع (reversed_at = NOW(), reversed_by, reverse_reason).
 *
 * @param int      $salaryId معرف المرتب المراد إرجاعه
 * @param string   $reason   سبب الإرجاع
 * @param int|null $userId   معرف المستخدم القائم بالإرجاع
 * @param PDO|null $pdo      كائن PDO اختياري
 * @return array قائمة التنبيهات والبنود التي لم يتم إرجاعها تلقائياً لعدم وجود ربط
 */
function reverse_salary(
    int $salaryId,
    string $reason,
    ?int $userId = null,
    ?PDO $pdo = null
): array {
    $ownTransaction = false;
    if ($pdo === null) {
        $pdo = db();
        $pdo->beginTransaction();
        $ownTransaction = true;
    }

    try {
        // ١. قفل سجل المرتب والتأكد من عدم إرجاعه مسبقاً
        $st = $pdo->prepare(
            'SELECT s.*, e.full_name
             FROM salaries s
             JOIN employees e ON e.id = s.employee_id
             WHERE s.id = ? FOR UPDATE'
        );
        $st->execute([$salaryId]);
        $sal = $st->fetch(PDO::FETCH_ASSOC);
        if (!$sal) {
            throw new RuntimeException('سجل المرتب غير موجود.');
        }
        if (!empty($sal['reversed_at'])) {
            throw new RuntimeException('المرتب ده اترجّع قبل كده.');
        }

        $trimmedReason = trim($reason);
        if ($trimmedReason === '') {
            throw new RuntimeException('يجب إدخال سبب إرجاع المرتب.');
        }

        $employeeId  = (int) $sal['employee_id'];
        $empName     = $sal['full_name'] ?? ('الموظف #' . $employeeId);
        $month       = $sal['salary_month'];
        $today       = date('Y-m-d');
        $manualItems = [];

        // ٢. القيد العكسي في اليومية (استلام = دائن بنفس المبلغ وعلى نفس الخزينة)
        $jeStmt = $pdo->prepare(
            'SELECT treasury_id, debit, credit, account_id
             FROM journal_entries
             WHERE ref_type = "salary" AND ref_id = ?
             ORDER BY id ASC'
        );
        $jeStmt->execute([$salaryId]);
        $jeRow = $jeStmt->fetch(PDO::FETCH_ASSOC);

        if ($jeRow && (float)$jeRow['debit'] > 0.00001) {
            $treasuryId = (int) $jeRow['treasury_id'];
            $amount     = (float) $jeRow['debit'];
            $salaryAccountId = $jeRow['account_id'] !== null ? (int) $jeRow['account_id'] : null;
            if ($salaryAccountId === null) {
                try {
                    $accountCheck = $pdo->prepare('SELECT id FROM accounts WHERE id = ? AND is_active = 1');
                    $accountCheck->execute([SALARY_ACCOUNT_ID]);
                    if ($accountCheck->fetchColumn() !== false) {
                        $salaryAccountId = SALARY_ACCOUNT_ID;
                    } else {
                        error_log('حساب المرتبات مش موجود أو مش نشط؛ إرجاع المرتب هيتسجل من غير قيد حساب.');
                    }
                } catch (Throwable $ex) {
                    error_log('فحص حساب المرتبات ماكملش؛ إرجاع المرتب هيتسجل من غير قيد حساب: ' . $ex->getMessage());
                }
            }
            post_journal(
                $treasuryId,
                0,
                $amount,
                'إرجاع صرف مرتب: ' . $empName . ' (' . $month . ')',
                $today,
                'salary_reverse',
                $salaryId,
                $userId,
                $pdo,
                $salaryAccountId
            );
        }

        // ٣. السلف: استرجاع مبالغ الأقساط من salary_advance_recoveries
        $sarStmt = $pdo->prepare(
            'SELECT advance_id, amount
             FROM salary_advance_recoveries
             WHERE salary_id = ?'
        );
        $sarStmt->execute([$salaryId]);
        $sarRows = $sarStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($sarRows)) {
            $advIds = [];
            $cases  = [];
            $params = [];
            foreach ($sarRows as $sr) {
                $advId    = (int) $sr['advance_id'];
                $amt      = (float) $sr['amount'];
                $advIds[] = $advId;
                $cases[]  = 'WHEN id = ? THEN ?';
                $params[] = $advId;
                $params[] = $amt;
            }
            $inPlaceholders = implode(',', array_fill(0, count($advIds), '?'));
            $sqlAdv = 'UPDATE employee_advances
                       SET remaining = COALESCE(remaining, 0) + CASE ' . implode(' ', $cases) . ' ELSE 0 END,
                           status = "pending"
                       WHERE id IN (' . $inPlaceholders . ')';
            $advUpdateParams = array_merge($params, $advIds);
            $pdo->prepare($sqlAdv)->execute($advUpdateParams);

            // ٨-أ. قيود عكسية في سجل الموظف لكل قسط سلفة تم استرجاعه
            foreach ($sarRows as $sr) {
                emp_ledger_add(
                    $employeeId,
                    'debit',
                    (float) $sr['amount'],
                    $today,
                    'salary_deduction',
                    'إلغاء خصم سلفة لإرجاع مرتب شهر ' . $month,
                    null,
                    'advance',
                    (int) $sr['advance_id'],
                    $userId,
                    $pdo
                );
            }
        } elseif ((float)$sal['total_advances'] > 0.005) {
            $manualItems[] = 'السلف: تم خصم ' . number_format((float)$sal['total_advances'], 2) . ' ج في هذا المرتب بدون ربط مباشر (مرتب قديم) — يرجى مراجعة أرصدة السلف يدوياً.';
        }

        // ٤. المكافآت والجزاءات بالربط المباشر فقط
        $bonUpd = $pdo->prepare('UPDATE employee_bonuses SET status = "pending", paid_in_salary_id = NULL WHERE paid_in_salary_id = ?');
        $bonUpd->execute([$salaryId]);
        $bonCount = $bonUpd->rowCount();
        if ($bonCount === 0 && (float)$sal['total_bonuses'] > 0.005) {
            $manualItems[] = 'المكافآت: صرف مكافآت بقيمة ' . number_format((float)$sal['total_bonuses'], 2) . ' ج بدون ربط مباشر (مرتب قديم) — يرجى مراجعة حالة المكافآت يدوياً.';
        }

        $penUpd = $pdo->prepare('UPDATE employee_penalties SET status = "pending", deducted_in_salary_id = NULL WHERE deducted_in_salary_id = ?');
        $penUpd->execute([$salaryId]);
        $penCount = $penUpd->rowCount();
        if ($penCount === 0 && (float)$sal['total_penalties'] > 0.005) {
            $manualItems[] = 'الجزاءات: خصم جزاءات بقيمة ' . number_format((float)$sal['total_penalties'], 2) . ' ج بدون ربط مباشر (مرتب قديم) — يرجى مراجعة حالة الجزاءات يدوياً.';
        }

        // ٥. عجز العهدة: الربط موجود (recovered_in_salary_id)
        $pdo->prepare('UPDATE custody_deficits SET status = "pending", recovered_in_salary_id = NULL WHERE recovered_in_salary_id = ?')
            ->execute([$salaryId]);

        // ٦. مسح الورديات المفحوصة في هذا المرتب (ليس سجلاً مالياً)
        $pdo->prepare('DELETE FROM salary_shift_deficits WHERE salary_id = ?')
            ->execute([$salaryId]);

        // ٧. السلفة المرحّلة (إن وجدت): إلغاؤها وقيد عكسي في السجل
        $advNote = 'باقي خصومات من مرتب شهر ' . $month;
        $carryAdvStmt = $pdo->prepare(
            'SELECT id, amount, remaining, status, notes
             FROM employee_advances
             WHERE employee_id = ? AND notes = ?
             ORDER BY id DESC LIMIT 1'
        );
        $carryAdvStmt->execute([$employeeId, $advNote]);
        $carryAdv = $carryAdvStmt->fetch(PDO::FETCH_ASSOC);

        if ($carryAdv) {
            $carryAdvId = (int) $carryAdv['id'];
            $carryAmt   = (float) $carryAdv['amount'];

            // التحقق من وجود حالة cancelled في enum عمود status
            $colStmt = $pdo->prepare(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_advances' AND COLUMN_NAME = 'status'"
            );
            $colStmt->execute();
            $colType = (string) $colStmt->fetchColumn();
            $hasCancelledEnum = (strpos($colType, "'cancelled'") !== false);

            if ($hasCancelledEnum) {
                $pdo->prepare('UPDATE employee_advances SET status = "cancelled", remaining = 0 WHERE id = ?')
                    ->execute([$carryAdvId]);
            } else {
                $cancelNote = ($carryAdv['notes'] ? $carryAdv['notes'] . "\n" : '') . 'تم الإلغاء لإرجاع مرتب شهر ' . $month . ' بتاريخ ' . $today;
                $pdo->prepare('UPDATE employee_advances SET remaining = 0, notes = ? WHERE id = ?')
                    ->execute([$cancelNote, $carryAdvId]);
                $manualItems[] = 'السلفة المرحّلة: السلفة رقم #' . $carryAdvId . ' (باقي خصومات بقيمة ' . number_format($carryAmt, 2) . ' ج) تم تصفير متبقيها — يُرجى مراجعتها يدوياً لعدم وجود حالة cancelled في جدول السلف.';
            }

            // ٨-ب. قيد عكسي credit في سجل حساب الموظف لإلغاء السلفة المرحّلة
            emp_ledger_add(
                $employeeId,
                'credit',
                $carryAmt,
                $today,
                'cancel',
                'إلغاء سلفة مرحّلة لإرجاع مرتب شهر ' . $month,
                null,
                'advance',
                $carryAdvId,
                $userId,
                $pdo
            );
        }

        // ٨-ج. أي قيود أخرى في سجل الموظف مرتبطة مباشرة بهذا المرتب (ref_type = 'salary')
        $salLedStmt = $pdo->prepare(
            'SELECT id, direction, amount, move_type
             FROM employee_ledger
             WHERE employee_id = ? AND ref_type = "salary" AND ref_id = ?'
        );
        $salLedStmt->execute([$employeeId, $salaryId]);
        foreach ($salLedStmt->fetchAll(PDO::FETCH_ASSOC) as $sle) {
            $revDir = ($sle['direction'] === 'debit') ? 'credit' : 'debit';
            emp_ledger_add(
                $employeeId,
                $revDir,
                (float) $sle['amount'],
                $today,
                $sle['move_type'],
                'قيد عكسي لإرجاع مرتب شهر ' . $month,
                null,
                'salary',
                $salaryId,
                $userId,
                $pdo
            );
        }

        // ٩. تحديث سجل المرتب بالبيانات وسبب الإرجاع
        $pdo->prepare(
            /* 🔴 `reversal_seq = id` مش تفصيلة — ده اللي بيخلّي إعادة
               الصرف ممكنة. الفهرس الفريد بقى على
               (employee_id, salary_month, reversal_seq): الشغّال دايماً
               صفر فبيفضل واحد بس، والمرجّع بياخد رقمه فبيخرج من الحساب.
               من غير السطر ده الصرف تاني بيقع بـ#1062. */
            'UPDATE salaries
             SET reversed_at = NOW(),
                 reversed_by = ?,
                 reverse_reason = ?,
                 reversal_seq = id
             WHERE id = ?'
        )->execute([$userId, $trimmedReason, $salaryId]);

        if ($ownTransaction) {
            $pdo->commit();
        }

        return $manualItems;

    } catch (Throwable $ex) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}

/* =====================================================================
 *  دوال الحضور والانصراف
 * ===================================================================== */

/**
 * تحويل دقائق إلى صيغة "HH:MM" للعرض.
 */
/**
 * معرف حساب «تحويل» لحركات التحويل بين الخزائن.
 * 1. بيقرا من الإعدادات `transfer_account_id` ويتأكد إنه موجود ونشط في accounts.
 * 2. وإلا بيبحث عن حساب نشط باسم «تحويل» وبيحفظه في settings ويرجّعه.
 * 3. وإلا بينشئ حساب «تحويل» جديد بنوع 'asset' وبيحفظه في settings ويرجّعه.
 *
 * @return int|null معرف الحساب أو null في حالة الفشل
 */
function transfer_account_id(): ?int
{
    $pdo = db();
    try {
        $setVal = (int) setting('transfer_account_id', 0);
        if ($setVal > 0) {
            $st = $pdo->prepare('SELECT id FROM accounts WHERE id = ? AND is_active = 1 LIMIT 1');
            $st->execute([$setVal]);
            $found = $st->fetchColumn();
            if ($found) {
                return (int) $found;
            }
        }

        // الخطوة ٢: البحث بالاسم لمنع التكرار
        $st = $pdo->prepare("SELECT id FROM accounts WHERE name = 'تحويل' AND is_active = 1 LIMIT 1");
        $st->execute();
        $found = $st->fetchColumn();
        if ($found) {
            $id = (int) $found;
            $pdo->prepare(
                'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
            )->execute(['transfer_account_id', (string) $id]);
            return $id;
        }

        // الخطوة ٣: إنشاء الحساب لو مش موجود خالص
        $pdo->prepare(
            "INSERT INTO accounts (parent_id, name, type, is_custody)
             VALUES (NULL, 'تحويل', 'asset', 0)"
        )->execute();
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        )->execute(['transfer_account_id', (string) $id]);
        return $id;
    } catch (Throwable $ex) {
        error_log('transfer_account_id: ' . $ex->getMessage());
        return null;
    }
}

/**
 * قائمة تصنيفات المصروفات النشطة (تُستخدم كـ "القيد" في الحركات المالية).
 * بترجّع [] بأمان لو الجدول مش موجود.
 */
function entry_codes_list(bool $custodyOnly = false): array
{
    // مزامنة الحسابات الديناميكية (موظفين/موردين) أول ما أي شاشة
    // فيها القوايم دي تفتح. بتشتغل مرة واحدة في الريكوست.
    $changed = sync_dynamic_accounts();

    // بقت بتقرا من شجرة الحسابات. الاسم اتساب زي ما هو عشان
    // الصفحات اللي بتناديها ما تتكسرش.
    // لو المزامنة غيّرت حاجة، نقرا من الأول عشان الجديد يبان فوراً.
    $rows = accounts_tree($custodyOnly, $changed > 0);
    if ($rows) {
        return array_map(
            fn($a) => ['id' => (int) $a['id'], 'name' => $a['path'], 'depth' => (int) $a['depth']],
            $rows
        );
    }
    // احتياطي: لو الشجرة لسه ما اتعملتش، نرجع للجدول القديم
    try {
        return db()->query('SELECT id, name FROM expense_categories WHERE is_active=1 ORDER BY name')->fetchAll();
    } catch (Throwable $ex) {
        return [];
    }
}

/**
 * نفس قايمة القيود بس بالعلاقة الحقيقية (أب ← أبناء) محفوظة.
 * =====================================================================
 *  `entry_codes_list()` بترجّع `name` = المسار الكامل ("البنك ← فيرا فوري")
 *  والـ parent_id بيضيع، فمينفعش تبني منها شجرة.
 *
 *  ⚠️ الشجرة بتتبني من `parent_id` في الداتابيز — **مش** بتقسيم الاسم
 *     على الشرطة أو السهم. أسامي الحسابات ممكن تحتوي على شرطات أصلاً
 *     (زي "egy Bank - البنك")، وتقسيم النص كان هيكسّرها غلط.
 *
 *  الدالة القديمة اتسابت زي ما هي بالحرف — أي شاشة بتناديها شغّالة عادي.
 *
 * @param bool $custodyOnly حسابات العهدة بس (is_custody=1)
 * @return array صفوف فيها: id · parent_id · name (الاسم لوحده) · path
 *               (المسار الكامل، للبحث) · depth · has_children
 */
function entry_codes_tree(bool $custodyOnly = false): array
{
    $changed = sync_dynamic_accounts();
    $rows    = accounts_tree($custodyOnly, $changed > 0);

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'        => (int) $r['id'],
            'parent_id' => isset($r['parent_id']) && $r['parent_id'] !== null ? (int) $r['parent_id'] : 0,
            'name'      => (string) $r['name'],
            'path'      => (string) ($r['path'] ?? $r['name']),
            'depth'     => (int) ($r['depth'] ?? 0),
            'kids'      => !empty($r['has_children']) ? 1 : 0,
        ];
    }
    return $out;
}

/**
 * حقل اختيار القيد الشجري — بديل الـ<select> المسطّح.
 * =====================================================================
 *  بيرسم زرار + بوب أب شجرة. الـ<input type=hidden> اللي جواه هو اللي
 *  بيتبعت مع الفورم، وفيه **id الحساب الحقيقي** مش الاسم.
 *
 *  الاسم والقيمة نفس القديم بالظبط، فمعالجات الـPOST الموجودة
 *  ماتتغيّرش ولا حرف.
 *
 * @param string   $name        اسم الحقل في الفورم (زي 'entry_code_id')
 * @param int|null $selected    القيمة الحالية
 * @param string   $placeholder النص لما مفيش اختيار
 * @param string   $set         'all' أو 'custody' — أنهي مجموعة داتا
 * @param string   $id          id اختياري للعنصر (لو الجافاسكربت محتاجه)
 */
function acctree_field(string $name, ?int $selected = null,
                       string $placeholder = '— بدون قيد —',
                       string $set = 'all', string $id = ''): string
{
    $empty = $selected ? '' : ' act-empty';
    $label = $selected ? (account_path($selected) ?? '') : $placeholder;

    return '<div class="act" data-acctree data-set="' . e($set) . '"'
        . ' data-placeholder="' . e($placeholder) . '"'
        . ($id !== '' ? ' id="' . e($id) . '"' : '') . '>'
        . '<input type="hidden" name="' . e($name) . '" value="' . ($selected ?: '') . '">'
        . '<button type="button" class="act-btn">'
        .   '<span class="act-label' . $empty . '">' . e($label) . '</span>'
        .   '<span class="act-caret" aria-hidden="true">▾</span>'
        . '</button>'
        . '<div class="act-pop" style="display:none;">'
        .   '<div class="act-head">'
        .     '<input type="text" class="act-search" placeholder="ابحث في القيود…" aria-label="بحث في القيود">'
        .     '<button type="button" class="act-clear" title="مسح الاختيار">مسح</button>'
        .   '</div>'
        .   '<div class="act-cols">'
        .     '<div class="act-col act-col-a"></div>'   // الآباء
        .     '<div class="act-col act-col-b"></div>'   // تفرعات المختار
        .   '</div>'
        .   '<div class="act-results" style="display:none;"></div>'
        . '</div></div>';
}

/**
 * بيانات الشجرة اللي المكتبة بتقراها. بتتحقن **مرة واحدة** في الصفحة
 * مهما كان عدد الحقول (٤ بوب أب في شاشة الخزينة بيشاركوا نفس الداتا).
 *
 * @param bool $withCustody يحقن مجموعة العهدة كمان (شاشة العهدة بس)
 */
function acctree_data(bool $withCustody = false): string
{
    $sets = ['all' => entry_codes_tree(false)];
    if ($withCustody) {
        $sets['custody'] = entry_codes_tree(true);
    }
    return '<script>window.ACCT_DATA=' .
        json_encode($sets, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
                         | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
}

/**
 * اسم التصنيف (القيد) بالـ id (أو null).
 */
function entry_code_name(?int $id): ?string
{
    if (!$id) return null;
    $p = account_path($id);
    if ($p !== null) return $p;
    try {
        $st = db()->prepare('SELECT name FROM expense_categories WHERE id=?');
        $st->execute([$id]);
        $n = $st->fetchColumn();
        return $n !== false ? $n : null;
    } catch (Throwable $ex) {
        return null;
    }
}

/**
 * هل المستخدم الحالي مسموحله يغيّر تاريخ وفرع الوردية المستنية؟
 * الأونر مسموحله دايماً، أو المحاسب المحدد في الإعداد shift_edit_accountant_id.
 */
function can_edit_shift_date_branch(): bool
{
    if (is_owner()) {
        return true;
    }
    $allowedUserId = (int) setting('shift_edit_accountant_id', 0);
    if ($allowedUserId > 0) {
        $u = current_user();
        if ($u && (int) ($u['id'] ?? 0) === $allowedUserId) {
            return true;
        }
    }
    return false;
}

/**
 * تسجيل حركة تعديل أو مسح وردية في shift_edit_log.
 * try/catch صامت لضمان عدم توقف العمليات لو جدول الترقية لسه مانزلش.
 */
function shift_edit_log(PDO $pdo, int $shiftId, int $userId, string $action, array $before, ?array $after): void
{
    try {
        $st = $pdo->prepare(
            'INSERT INTO shift_edit_log (shift_id, user_id, action, before_json, after_json)
             VALUES (?, ?, ?, ?, ?)'
        );
        $st->execute([
            $shiftId,
            $userId,
            $action,
            json_encode($before, JSON_UNESCAPED_UNICODE),
            $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (Throwable $ex) {
        // صامت لو الجدول مش موجود
        error_log('shift_edit_log: ' . $ex->getMessage());
    }
}

/**
 * تعديل تاريخ وفرع الوردية المستنية (المحاسب المفوّض أو الأونر).
 * يحدّث branch_id و business_date فقط بدون لمس أي أرقام أو خانات مالية.
 */
function update_shift_date_branch(int $shiftId, string $date, int $branchId, int $userId): void
{
    if (!can_edit_shift_date_branch()) {
        throw new RuntimeException('غير مصرح لك بتعديل تاريخ أو فرع الوردية.');
    }
    $date = trim($date);
    $dObj = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dObj || $dObj->format('Y-m-d') !== $date) {
        throw new RuntimeException('تاريخ غير صالح.');
    }
    if ($branchId <= 0) {
        throw new RuntimeException('اختر الفرع.');
    }

    $pdo = db();
    // التحقق من وجود ونشاط الفرع
    $bCheck = false;
    try {
        $bst = $pdo->prepare('SELECT 1 FROM branches WHERE id = ? AND is_active = 1 AND has_finance = 1 AND has_sales = 1 LIMIT 1');
        $bst->execute([$branchId]);
        $bCheck = (bool) $bst->fetchColumn();
    } catch (Throwable $ex) {
        $bst = $pdo->prepare('SELECT 1 FROM branches WHERE id = ? AND is_active = 1 AND has_finance = 1 LIMIT 1');
        $bst->execute([$branchId]);
        $bCheck = (bool) $bst->fetchColumn();
    }
    if (!$bCheck) {
        throw new RuntimeException('الفرع غير متاح أو غير نشط.');
    }

    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM shifts WHERE id = ? FOR UPDATE');
        $st->execute([$shiftId]);
        $shift = $st->fetch();
        if (!$shift) {
            throw new RuntimeException('الوردية مش موجودة.');
        }
        if (!in_array($shift['status'], ['submitted', 'resubmitted'], true)) {
            throw new RuntimeException('مش ممكن تعديل وردية اتراجعت (لازم تكون مستنية مراجعة).');
        }

        $up = $pdo->prepare('UPDATE shifts SET branch_id = ?, business_date = ? WHERE id = ?');
        $up->execute([$branchId, $date, $shiftId]);

        $after = $shift;
        $after['branch_id'] = $branchId;
        $after['business_date'] = $date;

        shift_edit_log($pdo, $shiftId, $userId, 'date_branch', $shift, $after);

        if ($own) $pdo->commit();
        try {
            shift_sequence_check($pdo, $shiftId);
        } catch (Throwable $seqEx) {
            // صامت
        }
    } catch (Throwable $ex) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/** تعديل وردية اتراجعت؛ تغيير التاريخ بيتسجّل بقيد عكسي وجديد من غير لمس الرصيد القديم. */
function update_approved_shift_date_branch(int $shiftId, string $date, int $branchId, int $userId): bool
{
    if (!can_edit_shift_date_branch()) {
        throw new RuntimeException('غير مصرح لك بتعديل تاريخ أو فرع الوردية.');
    }
    $date = trim($date);
    $dObj = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dObj || $dObj->format('Y-m-d') !== $date || $date > date('Y-m-d')) {
        throw new RuntimeException('تاريخ غير صالح أو لسه ماجاش.');
    }
    if ($branchId <= 0) throw new RuntimeException('اختر الفرع.');

    $pdo = db();
    $branchActive = false;
    try {
        $bst = $pdo->prepare('SELECT 1 FROM branches WHERE id = ? AND is_active = 1 AND has_finance = 1 AND has_sales = 1 LIMIT 1');
        $bst->execute([$branchId]);
        $branchActive = (bool)$bst->fetchColumn();
    } catch (Throwable $ex) {
        $bst = $pdo->prepare('SELECT 1 FROM branches WHERE id = ? AND is_active = 1 AND has_finance = 1 LIMIT 1');
        $bst->execute([$branchId]);
        $branchActive = (bool)$bst->fetchColumn();
    }
    if (!$branchActive) throw new RuntimeException('الفرع غير متاح أو غير نشط.');
    $auditColumn = $pdo->query("SHOW COLUMNS FROM shift_edit_log LIKE 'action'")->fetch(PDO::FETCH_ASSOC);
    if (!$auditColumn || !str_contains((string)$auditColumn['Type'], 'date_branch_approved')) {
        throw new RuntimeException('تحديث سجل تعديل الوردية لسه ما اتطبّقش؛ شغّل ملف الترقية الأول.');
    }

    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM shifts WHERE id = ? FOR UPDATE');
        $st->execute([$shiftId]);
        $shift = $st->fetch(PDO::FETCH_ASSOC);
        if (!$shift) throw new RuntimeException('الوردية مش موجودة.');
        if (!in_array($shift['status'], ['approved','rejected'], true)) {
            throw new RuntimeException('التعديل ده للوردية المعتمدة أو المرفوضة بس.');
        }
        $oldDate = (string)$shift['business_date'];
        $branchChanged = (int)$shift['branch_id'] !== $branchId;
        $dateChanged = $oldDate !== $date;
        if (!$branchChanged && !$dateChanged) {
            if ($own) $pdo->commit();
            return false;
        }

        $paid = $pdo->prepare("SELECT 1 FROM salary_shift_deficits d
            JOIN salaries sal ON sal.id = d.salary_id
            WHERE d.shift_id = ? AND sal.status = 'paid' AND sal.reversed_at IS NULL LIMIT 1");
        $paid->execute([$shiftId]);
        if ($paid->fetchColumn()) {
            throw new RuntimeException('الوردية دي عجزها اتخصم في مرتب مصروف؛ مينفعش تغيّر تاريخها أو فرعها.');
        }

        if ($branchChanged) {
            $up = $pdo->prepare('UPDATE shifts SET branch_id = ? WHERE id = ?');
            $up->execute([$branchId, $shiftId]);
        }
        if ($dateChanged) {
            if ($shift['status'] === 'approved') {
                $je = $pdo->prepare("SELECT id, treasury_id, debit, credit, description, account_id
                    FROM journal_entries WHERE ref_type = 'shift' AND ref_id = ? AND edited_at IS NULL
                    ORDER BY id FOR UPDATE");
                $je->execute([$shiftId]);
                $entries = $je->fetchAll(PDO::FETCH_ASSOC);
                $markIds = [];
                foreach ($entries as $entry) {
                    $treasuryId = (int)$entry['treasury_id'];
                    $accountId = $entry['account_id'] === null ? null : (int)$entry['account_id'];
                    $reverseId = post_journal($treasuryId, (float)$entry['credit'], (float)$entry['debit'],
                        'عكس تعديل تاريخ وردية #' . $shiftId, $oldDate,
                        'shift', $shiftId, $userId, $pdo, $accountId);
                    post_journal($treasuryId, (float)$entry['debit'], (float)$entry['credit'],
                        (string)$entry['description'], $date,
                        'shift', $shiftId, $userId, $pdo, $accountId);
                    $markIds[] = (int)$entry['id'];
                    // قيد العكس تاريخي؛ مايتعكسش تاني لو التاريخ اتعدل مرة تانية.
                    $markIds[] = $reverseId;
                }
                if ($markIds) {
                    $marks = implode(',', array_fill(0, count($markIds), '?'));
                    $mark = $pdo->prepare("UPDATE journal_entries SET edited_at = NOW(), edited_by = ? WHERE id IN ($marks)");
                    $mark->execute([$userId, ...$markIds]);
                }
            }
            $up = $pdo->prepare('UPDATE shifts SET business_date = ? WHERE id = ?');
            $up->execute([$date, $shiftId]);
        }

        $after = $shift;
        $after['branch_id'] = $branchId;
        $after['business_date'] = $date;
        shift_edit_log($pdo, $shiftId, $userId, 'date_branch_approved', $shift, $after);
        if ($own) $pdo->commit();
        try { shift_sequence_check($pdo, $shiftId); } catch (Throwable $seqEx) { /* فحص التسلسل ما يوقّعش التعديل. */ }
        return true;
    } catch (Throwable $ex) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * مسح وردية — بشروط أمان صارمة.
 * الموظف يقدر يمسح بس الوردية اللي هو عملها ولسه pending (ماتراجعتش).
 * لو الوردية اتعمدت/اترفضت أو مش بتاعته → بيرفض.
 * (سطور الكاش/الدفع/المراجعة بتتمسح CASCADE مع الوردية).
 */
function delete_shift(int $shiftId, int $userId, bool $isOwner = false): void
{
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM shifts WHERE id=?');
    $st->execute([$shiftId]);
    $shift = $st->fetch();
    if (!$shift) throw new RuntimeException('الوردية مش موجودة.');

    // لازم تكون لسه مستنية المراجعة (submitted/resubmitted) — اللي اتعمدت دخلت الخزينة
    if (!in_array($shift['status'], ['submitted', 'resubmitted'], true)) {
        throw new RuntimeException('مش ممكن تمسح وردية اتراجعت. اطلب من المحاسب.');
    }
    // الموظف يمسح بس اللي هو عمله (الأونر يقدر يمسح أي pending)
    if (!$isOwner && (int)$shift['user_id'] !== $userId) {
        throw new RuntimeException('مسموح تمسح بس التقفيلات اللي إنت عملتها.');
    }

    shift_edit_log($pdo, $shiftId, $userId, 'delete', $shift, null);

    $pdo->prepare('DELETE FROM shifts WHERE id=?')->execute([$shiftId]);
}

/**
 * حساب نهاية مهلة تعديل الوردية المرجوعة كـ timestamp، أو null لو مش مرفوضة.
 */
function shift_return_deadline(array $shift): ?int
{
    if (($shift['status'] ?? '') !== 'rejected' || empty($shift['reviewed_at'])) {
        return null;
    }
    $hours = (int) setting('shift_return_hours', 48);
    $ts = strtotime((string) $shift['reviewed_at']);
    if ($ts === false || $ts <= 0) {
        return null;
    }
    return $ts + ($hours * 3600);
}

/**
 * من إمتى مهلة الـ٤٨ ساعة بتشتغل — `settings.shift_return_since` (بيتحط وقت نزول
 * upgrade_shift_returns.sql).
 *
 * 🔴 من غيره كل وردية مرجوعة **قديمة** (من أغسطس) كانت هتتحمّل على الكاشير بكامل
 *    قيمة السيستم أول ما الكود يترفع — وعجز الوردية بيتخصم من المرتب لوحده.
 *    اتمسك على Laragon (١٥ سبتمبر ٢٠٢٦): ٦ ورديات قديمة اتعلّمت «عدّت المهلة» فوراً.
 *    الإعداد فاضي أو غلط = مفيش ولا وردية جوّه المهلة = مفيش تحميل خالص.
 */
function shift_return_since(): string
{
    $v = trim((string) setting('shift_return_since', ''));
    return preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $v) ? $v : '9999-12-31';
}

/**
 * هل انتهت مهلة تعديل الوردية المرجوعة؟
 * الفحص يتم في SQL لضمان مطابقة توقيت القاعدة بدون مشاكل الفروق الزمنية مع PHP.
 */
function is_shift_return_expired(int $shiftId): bool
{
    $pdo = db();
    $hours = (int) setting('shift_return_hours', 48);
    $since = shift_return_since();
    try {
        $st = $pdo->prepare(
            "SELECT (return_expired_at IS NOT NULL OR (reviewed_at >= ? AND reviewed_at + INTERVAL ? HOUR < NOW())) AS is_exp
             FROM shifts WHERE id = ?"
        );
        $st->execute([$since, $hours, $shiftId]);
        $val = $st->fetchColumn();
        return (bool) $val;
    } catch (Throwable $e) {
        try {
            $st = $pdo->prepare(
                "SELECT (reviewed_at >= ? AND reviewed_at + INTERVAL ? HOUR < NOW()) AS is_exp
                 FROM shifts WHERE id = ?"
            );
            $st->execute([$since, $hours, $shiftId]);
            return (bool) $st->fetchColumn();
        } catch (Throwable $e2) {
            return false;
        }
    }
}

/**
 * تعليم الورديات المرفوضة التي تجاوزت مهلة التعديل وإرسال إشعار مجمّع للأونرز والمحاسب.
 * آمنة للتكرار.
 */
function shift_returns_expire(PDO $pdo): int
{
    $hours = (int) setting('shift_return_hours', 48);
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            "SELECT s.id, s.user_id, s.shift_name, s.branch_id, s.system_total,
                    u.full_name AS cashier_name, b.name AS branch_name
             FROM shifts s
             JOIN users u ON u.id = s.user_id
             JOIN branches b ON b.id = s.branch_id
             WHERE s.status = 'rejected'
               AND s.return_expired_at IS NULL
               AND s.reviewed_at >= ?
               AND s.reviewed_at + INTERVAL ? HOUR < NOW()
             FOR UPDATE"
        );
        $st->execute([shift_return_since(), $hours]);
        $expired = $st->fetchAll(PDO::FETCH_ASSOC);

        if (empty($expired)) {
            if ($own && $pdo->inTransaction()) $pdo->commit();
            return 0;
        }

        $ids = array_map('intval', array_column($expired, 'id'));
        $in = implode(',', array_fill(0, count($ids), '?'));
        $up = $pdo->prepare(
            "UPDATE shifts
             SET return_expired_at = NOW(),
                 cash_total = 0,
                 visa_total = 0,
                 instapay_total = 0,
                 difference = -system_total
             WHERE id IN ($in)"
        );
        $up->execute($ids);

        if ($own) $pdo->commit();

        // إشعار للأونرز والمحاسب + إشعار للكاشير بعد commit
        try {
            require_once __DIR__ . '/push_lib.php';
            $owners = array_map('intval', $pdo->query(
                'SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
                 WHERE u.is_active = 1 AND r.is_owner = 1'
            )->fetchAll(PDO::FETCH_COLUMN));
            $accId = (int) setting('shift_edit_accountant_id', 0);
            $recipients = $owners;
            if ($accId > 0) {
                $recipients[] = $accId;
            }
            $recipients = array_values(array_unique(array_filter($recipients)));

            if (!empty($recipients)) {
                $count = count($expired);
                if ($count === 1) {
                    $row = $expired[0];
                    $sName = !empty($row['shift_name']) ? $row['shift_name'] : $row['id'];
                    $bName = $row['branch_name'] ?? '';
                    $cName = $row['cashier_name'] ?? '';
                    $sysTotal = number_format((float)$row['system_total'], 2);
                    $title = "وردية رقم {$sName} ({$bName}) عدّت المهلة واتحمّلت على {$cName}: {$sysTotal} ج";
                    $body  = "عدّت المهلة ({$hours} ساعة) بدون تعديل، وتم تحميل كامل قيمة السيستم عجزاً على الموظف.";
                } else {
                    $title = "{$count} ورديات عدّت المهلة واتحمّلت على الموظفين";
                    $names = [];
                    foreach ($expired as $ex) {
                        $sName = !empty($ex['shift_name']) ? $ex['shift_name'] : $ex['id'];
                        $bName = $ex['branch_name'] ?? '';
                        $cName = $ex['cashier_name'] ?? '';
                        $sysTotal = number_format((float)$ex['system_total'], 2);
                        $names[] = "رقم {$sName} ({$bName}) على {$cName} ({$sysTotal} ج)";
                    }
                    $body  = 'الورديات: ' . implode(' · ', $names);
                }
                notify($pdo, $recipients, 'shift_return_expired', $title, $body, 'review.php');
            }

            // إشعار لكل كاشير اتعلّمت ورديته
            foreach ($expired as $ex) {
                $cUserId = (int)$ex['user_id'];
                if ($cUserId > 0) {
                    $sName = !empty($ex['shift_name']) ? $ex['shift_name'] : $ex['id'];
                    $sysTotal = number_format((float)$ex['system_total'], 2);
                    notify(
                        $pdo,
                        [$cUserId],
                        'shift_return_expired',
                        "ورديتك رقم {$sName} عدّت مهلة التعديل",
                        "ورديتك رقم {$sName} عدّت مهلة التعديل واتحمّلت عليك بالكامل ({$sysTotal} ج).",
                        'cashier.php'
                    );
                }
            }
        } catch (Throwable $notifEx) {
            error_log('shift_returns_expire notify error: ' . $notifEx->getMessage());
        }

        return count($ids);
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        error_log('shift_returns_expire error: ' . $e->getMessage());
        return 0;
    }
}

/**
 * فحص تسلسل أرقام الورديات في الفرع (كشف الفجوات والتكرار).
 * try/catch صامت بالكامل.
 */
function shift_sequence_check(PDO $pdo, int $shiftId): void
{
    try {
        $st = $pdo->prepare(
            'SELECT s.id, s.branch_id, s.business_date, s.shift_name, b.name AS branch_name
             FROM shifts s
             JOIN branches b ON b.id = s.branch_id
             WHERE s.id = ?'
        );
        $st->execute([$shiftId]);
        $shift = $st->fetch(PDO::FETCH_ASSOC);
        if (!$shift) return;

        $rawName = trim((string)($shift['shift_name'] ?? ''));
        if (!ctype_digit($rawName) || (int)$rawName <= 0) return;
        $currentNo = (int) $rawName;

        $startDate = trim((string) setting('shift_seq_start_date', ''));
        if ($startDate === '' || $shift['business_date'] < $startDate) return;

        $branchId = (int) $shift['branch_id'];
        $bName    = (string) ($shift['branch_name'] ?? '');

        // أرقام ورديات نفس الفرع من تاريخ البداية (غير الوردية دي)
        $q = $pdo->prepare(
            "SELECT CAST(shift_name AS UNSIGNED) AS num
             FROM shifts
             WHERE branch_id = ?
               AND business_date >= ?
               AND id != ?
               AND shift_name REGEXP '^[0-9]+$'
               AND CAST(shift_name AS UNSIGNED) > 0"
        );
        $q->execute([$branchId, $startDate, $shiftId]);
        $existingNums = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));

        // ١) فحص التكرار
        if (in_array($currentNo, $existingNums, true)) {
            $ins = $pdo->prepare(
                "INSERT IGNORE INTO shift_sequence_issues (branch_id, shift_id, kind, expected_no, found_no, status)
                 VALUES (?, ?, 'duplicate', NULL, ?, 'open')"
            );
            $ins->execute([$branchId, $shiftId, $currentNo]);
            if ($ins->rowCount() > 0) {
                _notify_seq_issue($pdo, "مشكلة في تسلسل ورديات {$bName}: رقم {$currentNo} اتكرر", "وردية برقم {$currentNo} اتكررت في فرع {$bName}.");
            }
            return;
        }

        // ٢) فحص الفجوة أو حل فجوة قديمة
        if (!empty($existingNums)) {
            $maxNo = max($existingNums);
            if ($currentNo > $maxNo + 1) {
                $expectedNo = $maxNo + 1;
                $ins = $pdo->prepare(
                    "INSERT IGNORE INTO shift_sequence_issues (branch_id, shift_id, kind, expected_no, found_no, status)
                     VALUES (?, ?, 'gap', ?, ?, 'open')"
                );
                $ins->execute([$branchId, $shiftId, $expectedNo, $currentNo]);
                if ($ins->rowCount() > 0) {
                    _notify_seq_issue($pdo, "مشكلة في تسلسل ورديات {$bName}: بعد رقم {$maxNo} جه رقم {$currentNo}", "المتوقع كان رقم {$expectedNo}، جه رقم {$currentNo}.");
                }
            } elseif ($currentNo < $maxNo) {
                // الرقم أصغر من الأكبر (وردية اتقفلت متأخر) — نشوف هل يحل مشكلة فجوة مفتوحة
                $allNums = array_flip(array_merge($existingNums, [$currentNo]));
                $gapIssues = $pdo->prepare(
                    "SELECT id, expected_no, found_no
                     FROM shift_sequence_issues
                     WHERE branch_id = ? AND kind = 'gap' AND status = 'open'
                       AND expected_no <= ? AND found_no > ?"
                );
                $gapIssues->execute([$branchId, $currentNo, $currentNo]);
                foreach ($gapIssues->fetchAll(PDO::FETCH_ASSOC) as $gi) {
                    $expStart = (int) $gi['expected_no'];
                    $expEnd   = (int) $gi['found_no'];
                    $allFilled = true;
                    for ($n = $expStart; $n < $expEnd; $n++) {
                        if (!isset($allNums[$n])) {
                            $allFilled = false;
                            break;
                        }
                    }
                    if ($allFilled) {
                        $upd = $pdo->prepare(
                            "UPDATE shift_sequence_issues
                             SET status = 'answered', reply = ?, resolved_at = NOW()
                             WHERE id = ?"
                        );
                        $upd->execute(["اتقفلت الوردية الناقصة رقم {$currentNo}", (int)$gi['id']]);
                    }
                }
            }
        }
    } catch (Throwable $e) {
        error_log('shift_sequence_check error: ' . $e->getMessage());
    }
}

/**
 * إشعار داخلي للأونرز والمحاسب لمشاكل التسلسل
 */
function _notify_seq_issue(PDO $pdo, string $title, string $body): void
{
    try {
        require_once __DIR__ . '/push_lib.php';
        $owners = array_map('intval', $pdo->query(
            'SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.is_active = 1 AND r.is_owner = 1'
        )->fetchAll(PDO::FETCH_COLUMN));
        $accId = (int) setting('shift_edit_accountant_id', 0);
        $recipients = $owners;
        if ($accId > 0) {
            $recipients[] = $accId;
        }
        $recipients = array_values(array_unique(array_filter($recipients)));
        if (!empty($recipients)) {
            notify($pdo, $recipients, 'shift_seq', $title, $body, 'review.php?tab=seq');
        }
    } catch (Throwable $e) {
        error_log('_notify_seq_issue error: ' . $e->getMessage());
    }
}

// =====================================================================
//  المهام (Tasks) والإشعارات (Notifications)
// =====================================================================

/**
 * إنشاء مهمة وتوجيهها لمستخدمين + إنشاء إشعارات لهم.
 * @param array $userIds قائمة معرّفات المستخدمين المستهدفين
 * @return int معرّف المهمة
 */
function create_task(string $title, ?string $details, string $priority, ?string $dueDate, array $userIds, ?int $createdBy): int
{
    $title = trim($title);
    if ($title === '') throw new RuntimeException('عنوان المهمة مطلوب.');
    if (!in_array($priority, ['normal','important','urgent'], true)) $priority = 'normal';
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), fn($x) => $x > 0)));
    if (empty($userIds)) throw new RuntimeException('اختر مين يستلم المهمة.');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO tasks (title, details, priority, due_date, created_by, status)
             VALUES (?, ?, ?, ?, ?, "open")'
        )->execute([$title, $details ?: null, $priority, $dueDate ?: null, $createdBy]);
        $taskId = (int) $pdo->lastInsertId();

        $insTarget = $pdo->prepare('INSERT IGNORE INTO task_targets (task_id, user_id) VALUES (?, ?)');
        foreach ($userIds as $uid) {
            $insTarget->execute([$taskId, $uid]);
        }
        $prioLabel = ['normal'=>'', 'important'=>' (مهم)', 'urgent'=>' (عاجل)'][$priority];
        notify(
            $pdo,
            $userIds,
            'task',
            'مهمة جديدة' . $prioLabel,
            mb_substr($title, 0, 200),
            'tasks.php?task=' . $taskId
        );
        $pdo->commit();
        return $taskId;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * تحويل اختيار (فرع/موظفين) لقائمة user_ids.
 * $scope: 'branch' | 'users' | 'all'
 */
function resolve_task_targets(string $scope, array $branchIds, array $userIds): array
{
    $pdo = db();
    if ($scope === 'all') {
        // كل المستخدمين المرتبطين بموظفين نشطين
        return array_map('intval', $pdo->query(
            'SELECT DISTINCT u.id FROM users u JOIN employees e ON e.user_id=u.id
             WHERE u.is_active=1 AND e.is_active=1'
        )->fetchAll(PDO::FETCH_COLUMN));
    }
    if ($scope === 'branch') {
        $branchIds = array_values(array_filter(array_map('intval', $branchIds), fn($x)=>$x>0));
        if (empty($branchIds)) return [];
        $in = implode(',', array_fill(0, count($branchIds), '?'));
        $st = $pdo->prepare(
            "SELECT DISTINCT u.id FROM users u JOIN employees e ON e.user_id=u.id
             WHERE u.is_active=1 AND e.is_active=1 AND e.branch_id IN ($in)"
        );
        $st->execute($branchIds);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
    // users
    return array_values(array_filter(array_map('intval', $userIds), fn($x)=>$x>0));
}

/**
 * تعليم مهمة "تمت" من مستخدم مستهدف — **وقفل المهمة للكل**.
 *
 * 🔴 المهمة بتتقفل بأول واحد يخلّصها، ومابتفضلش مفتوحة لباقي
 *    المستهدفين. السبب: طلب نواقص بيتبعت لكل موظفي الفرع، فأول ما
 *    واحد يجهّزه الباقي مالهمش لازمة يفضلوا شايفينه «مطلوب».
 *
 * 🔴 قبل ١١ سبتمبر ٢٠٢٦ ماكانش فيه **ولا سطر** في المشروع كله بيكتب
 *    `tasks.status = 'closed'` (`grep -rn "UPDATE tasks" site/` كان
 *    بيرجّع ولا حاجة). يعني كل مهمة اتعملت من أول يوم فضلت `open`
 *    للأبد، وكانت بتفضل في قايمة الناس لشهور. ده كان بيبان كإنه بق
 *    في الصلاحيات وهو مجرد حاجة ماتعملتش.
 */
function mark_task_done(int $taskId, int $userId): void
{
    if ($taskId <= 0) return;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // ١) اللي دوس — بيتسجّل باسمه وبوقته هو
        $pdo->prepare(
            'UPDATE task_targets SET status="done", done_at=NOW()
              WHERE task_id=? AND user_id=?'
        )->execute([$taskId, $userId]);

        // ٢) باقي المستهدفين — المهمة خلصت، مايفضلوش شايفينها مطلوبة.
        //    COALESCE عشان اللي خلّص قبل كده يفضل وقته الأصلي.
        $pdo->prepare(
            'UPDATE task_targets SET status="done", done_at=COALESCE(done_at, NOW())
              WHERE task_id=? AND status <> "done"'
        )->execute([$taskId]);

        // ٣) المهمة نفسها
        $pdo->prepare(
            'UPDATE tasks SET status="closed" WHERE id=? AND status <> "closed"'
        )->execute([$taskId]);

        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/** إضافة تعليق/رد على مهمة. */
function add_task_comment(int $taskId, ?int $userId, string $body): void
{
    $body = trim($body);
    if ($body === '') return;
    db()->prepare('INSERT INTO task_comments (task_id, user_id, body) VALUES (?, ?, ?)')
        ->execute([$taskId, $userId, $body]);
}

/** عدد الإشعارات غير المقروءة لمستخدم. */
function unread_notifications_count(int $userId): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0');
    $st->execute([$userId]);
    return (int) $st->fetchColumn();
}

/** آخر إشعارات لمستخدم (للجرس). */
function recent_notifications(int $userId, int $limit = 15): array
{
    $st = db()->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT ' . (int)$limit);
    $st->execute([$userId]);
    return $st->fetchAll();
}

/** تعليم كل إشعارات مستخدم كمقروءة. */
function mark_notifications_read(int $userId): void
{
    db()->prepare('UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0')->execute([$userId]);
}

/**
 * إرسال إشعار بصرف المرتب للموظفين.
 *
 * - استعلام واحد مجمّع لجلب user_id لكل الموظفين المحددين (WHERE user_id IS NOT NULL).
 * - إدراج واحد مجمّع في notifications لكل الموظفين.
 * - مفيش أي استعلام أو إدراج جوه حلقة.
 * - الموظف اللي user_id بتاعه NULL أو فاضي بيتخطى بهدوء.
 * - الدالة كلها ملفوفة في try/catch عشان فشل الإشعار ما يوقّعش الصرف المالي.
 * - ممنوع ظهور أي مبالغ في نص الإشعار.
 *
 * @param array<int> $employeeIds معرّفات الموظفين
 * @param string     $month       شهر المرتب (Y-m)
 */
function notify_salaries_paid(array $employeeIds, string $month): void
{
    $employeeIds = array_values(array_unique(array_filter(array_map('intval', $employeeIds), fn($x) => $x > 0)));
    if (empty($employeeIds)) {
        return;
    }

    try {
        $pdo = db();
        $in = implode(',', array_fill(0, count($employeeIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, user_id, full_name
             FROM employees
             WHERE id IN ($in) AND user_id IS NOT NULL"
        );
        $stmt->execute($employeeIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return;
        }

        $userIds = [];
        foreach ($rows as $r) {
            $uid = (int)($r['user_id'] ?? 0);
            if ($uid > 0) {
                $userIds[] = $uid;
            }
        }
        $userIds = array_values(array_unique($userIds));
        if (empty($userIds)) {
            return;
        }

        $title = 'تم صرف مرتبك';
        $body  = 'مرتب شهر ' . $month . ' اتصرف — افتح التفاصيل';
        $link  = 'my_salary.php';
        $type  = 'salary';

        notify($pdo, $userIds, $type, $title, $body, $link);
    } catch (Throwable $e) {
        error_log('notify_salaries_paid error: ' . $e->getMessage());
    }
}

/**
 * إشعار للموظفين بعد صرف سلفة (من الخزينة أو عند اعتماد العهدة)
 *
 * - استعلام واحد مجمّع يجيب user_id لكل الموظفين المطلوبين (تجنباً لأي استعلام جوه حلقة).
 * - الموظف اللي user_id بتاعه NULL أو فاضي بيتخطى بهدوء.
 * - لو موظف عنده أكتر من سلفة في نفس العملية: إشعار واحد بمجموع المبالغ.
 * - الدالة كلها ملفوفة في try/catch عشان فشل الإشعار ما يوقّعش الصرف المالي أبداً.
 *
 * @param array<array{employee_id: int, amount: float, date?: string}> $items
 */
function notify_advance_disbursed(array $items): void
{
    if (empty($items)) {
        return;
    }

    try {
        // تجميع المبالغ لكل موظف لو اتكرر في نفس العملية
        $empTotals = [];
        foreach ($items as $it) {
            $empId = (int)($it['employee_id'] ?? 0);
            $amt   = (float)($it['amount'] ?? 0.0);
            $dt    = trim((string)($it['date'] ?? ''));
            if ($dt === '') {
                $dt = date('Y-m-d');
            }
            if ($empId <= 0 || $amt <= 0.005) {
                continue;
            }
            if (!isset($empTotals[$empId])) {
                $empTotals[$empId] = [
                    'amount' => 0.0,
                    'date'   => $dt,
                ];
            }
            $empTotals[$empId]['amount'] += $amt;
            if ($dt > $empTotals[$empId]['date']) {
                $empTotals[$empId]['date'] = $dt;
            }
        }

        if (empty($empTotals)) {
            return;
        }

        $pdo = db();
        $empIds = array_keys($empTotals);
        $in = implode(',', array_fill(0, count($empIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, user_id
             FROM employees
             WHERE id IN ($in) AND user_id IS NOT NULL"
        );
        $stmt->execute($empIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return;
        }

        $empToUser = [];
        foreach ($rows as $r) {
            $uid = (int)($r['user_id'] ?? 0);
            if ($uid > 0) {
                $empToUser[(int)$r['id']] = $uid;
            }
        }

        if (empty($empToUser)) {
            return;
        }

        // تجميع المستخدمين حسب نص الإشعار لو اتطابق المبلغ والتاريخ
        $groups = [];
        foreach ($empTotals as $empId => $data) {
            if (!isset($empToUser[$empId])) {
                continue;
            }
            $userId    = $empToUser[$empId];
            $body      = 'افتح التفاصيل من صفحة المرتب';
            $groups[$body][] = $userId;
        }

        $title = 'اتصرفتلك سلفة';
        $type  = 'advance';
        $link  = 'my_salary.php';

        foreach ($groups as $body => $userIds) {
            $userIds = array_values(array_unique($userIds));
            if (!empty($userIds)) {
                notify($pdo, $userIds, $type, $title, $body, $link);
            }
        }
    } catch (Throwable $e) {
        error_log('notify_advance_disbursed error: ' . $e->getMessage());
    }
}


function minutes_to_hhmm(int $minutes): string
{
    if ($minutes < 0) $minutes = 0;
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return sprintf('%02d:%02d', $h, $m);
}

/**
 * تنسيق مبلغ فلوس بذكاء:
 *  - لو الرقم صحيح (مفيش قروش) → يظهر من غير كسور: 283900 مش 283900.00
 *  - لو فيه قروش → يظهر بالكسور: 283900.50 أو 100.25
 * بيشيل الفاصلة العشرية والأصفار الزايدة، مع فواصل الآلاف.
 */
function money_fmt($amount): string
{
    $amount = (float) $amount;
    // قرّب لأقرب قرش لتفادي مشاكل الفاصلة العائمة (283900.00000001)
    $rounded = round($amount, 2);
    if (abs($rounded - round($rounded)) < 0.005) {
        // رقم صحيح فعليًا → بدون كسور
        return number_format($rounded, 0);
    }
    // فيه قروش → اعرضها وشيل أي صفر زايد في آخر القروش (100.50 تفضل .50، بس .5 مش مطلوبة)
    return number_format($rounded, 2);
}

/** مبلغ في خلية جدول: كسرين ثابتين دايماً عشان العلامة العشرية تتصفّ (قرار محمد ٢٣ سبتمبر ٢٠٢٦). */
function money_fmt_table($amount): string
{
    return number_format(round((float) $amount, 2), 2);
}


/**
 * ⬅️ `ltr_out()` كانت هنا. اتنقلت لـ`auth.php` جنب `e()`.
 *    السبب: `book_reserve.php` بيستعملها وهو **مابيعملش require
 *    لـfinance.php** — فالصفحة وقعت بـFatal على السيرفر
 *    (٢٠ سبتمبر ٢٠٢٦، ٧ مرات على مستخدم حقيقي).
 *    مكانها الصح مع دوال العرض الأساسية اللي كل صفحة بتحمّلها.
 */

/**
 * مسار التحويل بين الخزائن كنص عادي (بدون HTML).
 * يرجع '' لو الاسمين فاضيين، أو 'من: X ← إلى: Y' مع تعويض الفاضي بـ'—'.
 */
function je_transfer_path(?string $fromName, ?string $toName): string
{
    $from = trim((string) $fromName);
    $to   = trim((string) $toName);
    if ($from === '' && $to === '') {
        return '';
    }
    return 'من: ' . ($from !== '' ? $from : '—') . ' ← إلى: ' . ($to !== '' ? $to : '—');
}

/**
 * حساب دقائق العمل بين وقتين (HH:MM:SS).
 * بيتعامل مع الورديات اللي بتعدّي منتصف الليل (خروج < دخول).
 */
function work_minutes(string $checkIn, string $checkOut): int
{
    $in  = strtotime($checkIn);
    $out = strtotime($checkOut);
    if ($out < $in) {
        $out += 86400; // عدّى منتصف الليل
    }
    return (int) round(($out - $in) / 60);
}

/**
 * تسجيل حضور أو انصراف لموظف في يوم.
 * - أول مرة في اليوم: بيسجّل check_in.
 * - تاني مرة: بيسجّل check_out ويحسب ساعات العمل.
 *
 * @param string $action 'in' | 'out'
 * @return array ['status'=>'in'|'out'|'done', 'hours'=>?float]
 */
/**
 * مسافة بين نقطتين GPS بالمتر (صيغة Haversine).
 */
function gps_distance_m(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $R = 6371000.0; // نصف قطر الأرض بالمتر
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2) * sin($dLat/2)
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2) * sin($dLng/2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $R * $c;
}

/**
 * تقييم موقع البصمة مقابل نطاق فرع الموظف.
 * بيرجّع: ['dist'=>int|null, 'outside'=>0|1, 'uncertain'=>0|1, 'checked'=>bool, 'enforced'=>bool, 'radius'=>int|null]
 *  - checked=false: مفيش تقييم (الموظف مسموحله من أي مكان، أو الفرع مش مفعّل نطاق،
 *    أو مفيش إحداثيات للفرع، أو الموظف مبعتش موقعه).
 *  - enforced=true: الموظف مش allow_anywhere وفرعه عنده نطاق مفعّل بإحداثيات.
 *  - outside=1: برّه النطاق **فعلاً** (المسافة المؤكدة أكبر من الراديوس).
 *  - uncertain=1: المسافة الخام أكبر من الراديوس لكن الدقة ضعيفة فمش متأكدين.
 *
 * @param ?float $acc  دقة القراءة بالمتر (من pos.coords.accuracy).
 *                     null = مش معروفة → السلوك القديم بالظبط.
 */
function evaluate_geofence(array $employee, ?float $lat, ?float $lng, ?float $acc = null): array
{
    $out = ['dist' => null, 'outside' => 0, 'uncertain' => 0, 'checked' => false, 'enforced' => false, 'radius' => null];

    // الموظف مسموحله يبصم من أي مكان → مفيش تقييم
    if (!empty($employee['allow_anywhere'])) return $out;

    $branchId = (int)($employee['branch_id'] ?? 0);
    if ($branchId <= 0) return $out;

    $st = db()->prepare('SELECT latitude, longitude, geofence_radius, geofence_enabled FROM branches WHERE id=?');
    $st->execute([$branchId]);
    $b = $st->fetch();
    if (!$b) return $out;

    // الفرع مش مفعّل نطاق، أو مالوش إحداثيات → مفيش تقييم
    if (empty($b['geofence_enabled']) || $b['latitude'] === null || $b['longitude'] === null) {
        return $out;
    }

    $radius = (int)($b['geofence_radius'] ?: 50);
    $out['enforced'] = true;
    $out['radius']   = $radius;

    // مفيش موقع من الموظف → مفيش تقييم (بس بنسجّل إنه مبعتش)
    if ($lat === null || $lng === null) return $out;

    $dist = gps_distance_m((float)$b['latitude'], (float)$b['longitude'], $lat, $lng);

    $out['dist']    = (int)round($dist);
    $out['checked'] = true;

    if ($acc === null) {
        // الدقة مش معروفة (صفوف قديمة / نداء قديم) → السلوك القديم بالظبط
        $out['outside'] = $dist > $radius ? 1 : 0;
    } else {
        // المسافة المؤكدة = أقل مسافة ممكنة بناءً على هامش الخطأ
        $confirmedDist = max(0.0, $dist - $acc);
        if ($confirmedDist > $radius) {
            // برّه فعلاً — حتى لو طرحنا كل هامش الخطأ لسه بعيد
            $out['outside'] = 1;
        } elseif ($dist > $radius) {
            // المسافة الخام أكبر من الراديوس بس بعد خصم الدقة ممكن يكون جوّه
            $out['uncertain'] = 1;
        }
        // غير كده: dist <= radius → جوّه، outside=0 و uncertain=0
    }

    return $out;
}

/**
 * عدّ مرات البصمة «مش دقيقة» المتتالية للموظف من آخر بصمة دقيقة جوّه النطاق.
 * البصمة مش دقيقة لو: dist > radius و outside = 0 و acc مش NULL.
 * البصمة دقيقة جوّه لو: dist <= radius.
 * بنمشي من الأحدث للأقدم (الانصراف قبل الحضور في نفس اليوم).
 */
function geofence_consecutive_uncertain(int $employeeId, int $radius): int
{
    static $cache = [];
    $cacheKey = $employeeId . ':' . $radius;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $st = db()->prepare(
        'SELECT check_in, check_in_dist, check_in_outside, check_in_acc,
                check_out, check_out_dist, check_out_outside, check_out_acc
         FROM attendance
         WHERE employee_id = ?
         ORDER BY att_date DESC, id DESC
         LIMIT 10'
    );
    $st->execute([$employeeId]);
    $rows = $st->fetchAll();

    $count = 0;
    foreach ($rows as $r) {
        // انصراف اليوم (أحدث من الحضور)
        if ($r['check_out'] !== null && $r['check_out_dist'] !== null) {
            $dist = (float)$r['check_out_dist'];
            if ($dist <= $radius) {
                break;
            }
            if ($dist > $radius && empty($r['check_out_outside']) && $r['check_out_acc'] !== null) {
                $count++;
            }
        }

        // حضور اليوم
        if ($r['check_in'] !== null && $r['check_in_dist'] !== null) {
            $dist = (float)$r['check_in_dist'];
            if ($dist <= $radius) {
                break;
            }
            if ($dist > $radius && empty($r['check_in_outside']) && $r['check_in_acc'] !== null) {
                $count++;
            }
        }
    }

    return $cache[$cacheKey] = $count;
}

/**
 * فحص سبب منع تسجيل الحضور/الانصراف بناءً على النطاق الجغرافي.
 * بيرجّع null لو مسموح، أو نص الرفض لو ممنوع.
 */
function geofence_block_reason(array $employee, ?float $lat, ?float $lng, array $geo): ?string
{
    // النطاق مش مفعّل على الموظف أو الفرع
    if (empty($geo['enforced'])) {
        return null;
    }

    // الموبايل مابعتش موقع
    if ($lat === null || $lng === null) {
        return 'مش قادرين نحدد موقعك. افتح الـGPS واسمح للمتصفح يعرف موقعك، وجرّب تاني.';
    }

    // برّه النطاق فعلاً
    if (!empty($geo['outside'])) {
        $dist = (int)($geo['dist'] ?? 0);
        return 'إنت برّه نطاق الفرع (بعيد حوالي ' . $dist . ' متر). الحضور والانصراف من جوّه الفرع بس.';
    }

    // الموقع مش دقيق — سماح ٣ مرات ورا بعض، والرابعة تترفض
    if (!empty($geo['uncertain'])) {
        $radius = (int)($geo['radius'] ?: 50);
        $grace = (int)setting('geofence_uncertain_grace', '3');
        $prevCount = geofence_consecutive_uncertain((int)($employee['id'] ?? 0), $radius);
        if ($prevCount >= $grace) {
            return 'موقعك مش دقيق للمرة الرابعة ورا بعض. افتح إعدادات المتصفح وخلّي إذن الموقع «دقيق» (مش «تقريبي»)، وجرّب جنب الشباك.';
        }
    }

    return null;
}

/**
 * تسجيل حضور/انصراف.
 * @param array|null $geo نتيجة evaluate_geofence: ['dist'=>?, 'outside'=>0|1, ...]
 *                        + اختياري 'lat','lng' لتخزين نقطة البصمة.
 */
function clock_attendance(int $employeeId, string $action, ?string $time = null, ?string $date = null, ?array $geo = null): array
{
    $pdo = db();

    // مصدر الوقت = MySQL نفسها (بتوقيت الجلسة المضبوط في config.php على Africa/Cairo).
    // ده يمنع أي فرق بين توقيت سيرفر PHP (UTC على Hostinger) وتوقيت القاعدة.
    // ملاحظة: بنجيب التاريخ والوقت في استعلام واحد عشان مايحصلش انقسام لو
    // اللحظة عدّت منتصف الليل بين استعلامين.
    if ($time === null || $date === null) {
        $nowRow = $pdo->query('SELECT CURDATE() AS d, CURTIME() AS t')->fetch();
        $date = $date ?: $nowRow['d'];
        $time = $time ?: $nowRow['t'];
    }
    if (strlen($time) === 5) $time .= ':00'; // HH:MM -> HH:MM:SS

    $lat     = $geo['lat']     ?? null;
    $lng     = $geo['lng']     ?? null;
    $dist    = $geo['dist']    ?? null;
    $outside = !empty($geo['outside']) ? 1 : 0;
    $acc     = $geo['acc']     ?? null;

    $pdo->beginTransaction();
    try {
        // سجل اليوم لو موجود
        $st = $pdo->prepare('SELECT * FROM attendance WHERE employee_id=? AND att_date=? FOR UPDATE');
        $st->execute([$employeeId, $date]);
        $row = $st->fetch();

        if ($action === 'in') {
            if ($row && $row['check_in']) {
                throw new RuntimeException('تم تسجيل الحضور بالفعل اليوم.');
            }
            if ($row) {
                // UPDATE حضور: ٧ علامات ?, ٧ قيم
                $pdo->prepare('UPDATE attendance SET check_in=?, check_in_lat=?, check_in_lng=?,
                        check_in_dist=?, check_in_outside=?, check_in_acc=?, status="present" WHERE id=?')
                    ->execute([$time, $lat, $lng, $dist, $outside, $acc, $row['id']]);
            } else {
                // INSERT حضور: ٨ علامات ?, ٨ قيم
                $pdo->prepare(
                    'INSERT INTO attendance (employee_id, att_date, check_in, check_in_lat, check_in_lng,
                        check_in_dist, check_in_outside, check_in_acc, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, "present")'
                )->execute([$employeeId, $date, $time, $lat, $lng, $dist, $outside, $acc]);
            }
            $pdo->commit();
            return ['status' => 'in', 'hours' => null, 'outside' => $outside, 'dist' => $dist, 'uncertain' => (!empty($geo['uncertain']) ? 1 : 0)];
        } else { // out
            if (!$row || !$row['check_in']) {
                throw new RuntimeException('لازم تسجّل حضور الأول.');
            }
            if ($row['check_out']) {
                throw new RuntimeException('تم تسجيل الانصراف بالفعل اليوم.');
            }
            $mins = work_minutes($row['check_in'], $time);
            if ($mins < 5) {
                throw new RuntimeException('لازم يكون فيه 5 دقايق على الأقل بين الحضور والانصراف.');
            }
            $hours = round($mins / 60, 2);
            // UPDATE انصراف: ٨ علامات ?, ٨ قيم
            $pdo->prepare('UPDATE attendance SET check_out=?, check_out_lat=?, check_out_lng=?,
                    check_out_dist=?, check_out_outside=?, check_out_acc=?, work_hours=? WHERE id=?')
                ->execute([$time, $lat, $lng, $dist, $outside, $acc, $hours, $row['id']]);
            $pdo->commit();
            return ['status' => 'out', 'hours' => $hours, 'minutes' => $mins, 'outside' => $outside, 'dist' => $dist, 'uncertain' => (!empty($geo['uncertain']) ? 1 : 0)];
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * مسح سجل حضور/انصراف يوم معيّن لموظف (للأونر/HR).
 * بيمسح صف الحضور بالكامل لليوم ده، فيقدر الموظف يسجّل من جديد.
 */
function delete_attendance(int $attendanceId): void
{
    db()->prepare('DELETE FROM attendance WHERE id=?')->execute([$attendanceId]);
}

/**
 * اعتماد طلب تصحيح حضور: بيطبّق الأوقات المطلوبة على سجل الحضور.
 */
function approve_attendance_request(int $requestId, ?int $userId = null): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM attendance_requests WHERE id=? FOR UPDATE');
        $st->execute([$requestId]);
        $req = $st->fetch();
        if (!$req || $req['status'] !== 'pending') {
            throw new RuntimeException('الطلب غير متاح.');
        }

        $empId = (int) $req['employee_id'];
        $date  = $req['req_date'];
        $in    = $req['requested_in'];
        $out   = $req['requested_out'];

        // سجل الحضور لليوم ده
        $a = $pdo->prepare('SELECT * FROM attendance WHERE employee_id=? AND att_date=? FOR UPDATE');
        $a->execute([$empId, $date]);
        $att = $a->fetch();

        // الساعات تتحسب من الأوقات النهائية بعد الدمج، مش من اللي في الطلب بس.
        // لو الطلب فيه طرف واحد (مثلاً requested_out بس) والتاني موجود في سجل الحضور،
        // لازم نحسب الساعات من الاتنين مع بعض — وإلا work_hours تفضل صفر للأبد.
        $finalIn  = $in  ?: ($att ? $att['check_in']  : null);
        $finalOut = $out ?: ($att ? $att['check_out'] : null);
        $hours = null;
        if ($finalIn && $finalOut) {
            $hours = round(work_minutes($finalIn, $finalOut) / 60, 2);
        }

        if ($att) {
            $pdo->prepare(
                'UPDATE attendance SET check_in=COALESCE(?,check_in), check_out=COALESCE(?,check_out),
                    work_hours=COALESCE(?,work_hours), status="present" WHERE id=?'
            )->execute([$in, $out, $hours, $att['id']]);
        } else {
            $pdo->prepare(
                'INSERT INTO attendance (employee_id, att_date, check_in, check_out, work_hours, status)
                 VALUES (?, ?, ?, ?, ?, "present")'
            )->execute([$empId, $date, $in, $out, $hours ?? 0]);
        }

        $pdo->prepare('UPDATE attendance_requests SET status="approved", reviewed_by=?, reviewed_at=NOW() WHERE id=?')
            ->execute([$userId, $requestId]);

        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * قائمة مصروفات وردية محددة.
 * ترجع مصفوفة فارغة بأمان لو الجدول غير موجود بعد.
 */
function shift_expenses_list(int $shiftId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    try {
        $st = $pdo->prepare(
            'SELECT se.*, a.name AS account_name
             FROM shift_expenses se
             LEFT JOIN accounts a ON a.id = se.account_id
             WHERE se.shift_id = ?
             ORDER BY se.id ASC'
        );
        $st->execute([$shiftId]);
        return $st->fetchAll();
    } catch (Throwable $ex) {
        return [];
    }
}

/**
 * جلب مصروفات مجموعة ورديات في استعلام واحد مجمّع (منع أي استعلام في حلقة).
 */
function shift_expenses_for_shifts(array $shiftIds, ?PDO $pdo = null): array
{
    if (empty($shiftIds)) return [];
    $pdo = $pdo ?? db();
    try {
        $ph = implode(',', array_fill(0, count($shiftIds), '?'));
        $st = $pdo->prepare(
            "SELECT se.*, a.name AS account_name
             FROM shift_expenses se
             LEFT JOIN accounts a ON a.id = se.account_id
             WHERE se.shift_id IN ($ph)
             ORDER BY se.shift_id ASC, se.id ASC"
        );
        $st->execute(array_values($shiftIds));
        $rows = $st->fetchAll();
        $out = [];
        foreach ($shiftIds as $sid) {
            $out[(int)$sid] = [];
        }
        foreach ($rows as $r) {
            $out[(int)$r['shift_id']][] = $r;
        }
        return $out;
    } catch (Throwable $ex) {
        return [];
    }
}
