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

        foreach ($parents as $p) {
            $pid = (int) $p['id'];
            $src = (string) $p['dynamic_src'];

            // المصدر
            if ($src === 'employees') {
                $rows = $pdo->query(
                    'SELECT id, full_name AS name, is_active FROM employees ORDER BY full_name'
                )->fetchAll();
                $type = 'expense';
            } elseif ($src === 'suppliers') {
                $rows = $pdo->query(
                    'SELECT id, name, is_active FROM suppliers ORDER BY name'
                )->fetchAll();
                $type = 'liability';
            } else {
                continue;
            }

            // الموجود حالياً تحت الأب ده
            $st = $pdo->prepare(
                'SELECT id, src_id, name, is_active FROM accounts
                 WHERE parent_id = ? AND src_table = ?'
            );
            $st->execute([$pid, $src]);

            $existing = [];
            foreach ($st->fetchAll() as $e) {
                $existing[(int) $e['src_id']] = $e;
            }

            $insert = $pdo->prepare(
                'INSERT INTO accounts (parent_id, name, type, src_table, src_id, is_active, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, 0)'
            );
            $update = $pdo->prepare(
                'UPDATE accounts SET name = ?, is_active = ? WHERE id = ?'
            );

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
    if ($type === 'disbursement') {
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
        'cash'           => 'كاش',
        'visa'           => 'فيزا',
        'instapay'       => 'إنستا باي',
        'card'           => 'كارت',
        'branch_custody' => 'عهدة فرع',
        'main'           => 'رئيسية',
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
    $st = db()->prepare("SELECT * FROM branch_custody WHERE branch_id = ? AND status='open' ORDER BY id DESC LIMIT 1");
    $st->execute([$branchId]);
    $row = $st->fetch();
    return $row ?: null;
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
    ?int $userId = null
): int {
    if ($amount <= 0) {
        throw new InvalidArgumentException('مبلغ الإذن لازم يكون أكبر من صفر.');
    }
    // إذون العهدة بقت تطلع من الخزينة الرئيسية مش من خزينة الكاش
    // (2026-08-22). خزينة الكاش بقت استلام وتحويل بس.
    $cashId = require_main_treasury();

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // تسجيل الإذن
        $pdo->prepare(
            'INSERT INTO disbursement_orders (branch_id, custody_treasury_id, amount, order_date, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$branchId, $custodyTreasuryId, $amount, $date, $notes, $userId]);
        $orderId = (int) $pdo->lastInsertId();

        // حركة الفلوس: مدين الكاش، دائن العهدة
        post_journal($cashId, $amount, 0, 'إذن صرف لعهدة الفرع' . ($notes ? " - $notes" : ''),
                     $date, 'disbursement_order', $orderId, $userId, $pdo);
        post_journal($custodyTreasuryId, 0, $amount, 'استلام إذن صرف' . ($notes ? " - $notes" : ''),
                     $date, 'disbursement_order', $orderId, $userId, $pdo);

        // فتح عهدة أو تزويد أول المدة للعهدة المفتوحة
        $st = $pdo->prepare("SELECT id FROM branch_custody WHERE branch_id=? AND status='open' ORDER BY id DESC LIMIT 1");
        $st->execute([$branchId]);
        $custodyId = $st->fetchColumn();

        if ($custodyId) {
            // عهدة مفتوحة: اقرأ القيم الحالية واحسب صراحةً
            $cur = $pdo->prepare('SELECT opening_balance, total_expenses FROM branch_custody WHERE id=? FOR UPDATE');
            $cur->execute([$custodyId]);
            $row = $cur->fetch();
            $newOpening = (float)$row['opening_balance'] + $amount;
            $newClosing = $newOpening - (float)$row['total_expenses'];
            $pdo->prepare('UPDATE branch_custody SET opening_balance=?, closing_balance=? WHERE id=?')
                ->execute([$newOpening, $newClosing, $custodyId]);
        } else {
            // افتح عهدة جديدة (آخر المدة = أول المدة، مفيش مصروفات بعد)
            $pdo->prepare(
                'INSERT INTO branch_custody
                    (branch_id, custody_treasury_id, period_start, opening_balance, total_expenses, closing_balance, status)
                 VALUES (?, ?, ?, ?, 0, ?, "open")'
            )->execute([$branchId, $custodyTreasuryId, $date, $amount, $amount]);
        }

        $pdo->commit();
        return $orderId;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * إضافة مصروف على العهدة المفتوحة.
 * بيقلّل آخر المدة (closing_balance) ويطلّع الفلوس فعلياً من خزينة العهدة
 * (مدين على خزينة العهدة) عشان رصيد الخزينة يفضل مطابق للواقع دايماً.
 */
function add_custody_expense(
    int $custodyId,
    string $date,
    string $description,
    float $amount,
    ?int $userId = null,
    ?int $entryCodeId = null
): int {
    if ($amount <= 0) {
        throw new InvalidArgumentException('مبلغ المصروف لازم يكون أكبر من صفر.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // تأكد إن العهدة مفتوحة واقرأ خزينتها
        $st = $pdo->prepare("SELECT status, custody_treasury_id FROM branch_custody WHERE id=? FOR UPDATE");
        $st->execute([$custodyId]);
        $row = $st->fetch();
        if (!$row || $row['status'] !== 'open') {
            throw new RuntimeException('لا يمكن إضافة مصروف لعهدة غير مفتوحة.');
        }
        $custodyTreasuryId = (int) $row['custody_treasury_id'];

        $pdo->prepare(
            'INSERT INTO custody_lines (custody_id, expense_date, description, amount, entry_code_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$custodyId, $date, $description, $amount, $entryCodeId, $userId]);
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

        $pdo->commit();
        return $lineId;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
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
 * الشروط: العهدة لازم تكون مفتوحة. العهدة المقفولة اتعمل عليها
 * تسوية وقفل، فتعديلها بأثر رجعي بيبوّظ الإقفال.
 */
function delete_custody_expense(int $lineId, ?int $userId = null): void
{
    $pdo = db();
    $pdo->beginTransaction();
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
        if ($line['status'] !== 'open') {
            throw new RuntimeException('مينفعش تمسح مصروف من عهدة مقفولة. لو فيه غلط، كلّم المحاسب.');
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

        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * إقفال العهدة الشهري (أهم دالة).
 *
 * المنطق المتفق عليه:
 *  - $countedCash = الباقي الفعلي المعدود بالفئات.
 *  - الاستلام: الفعلي يخرج من خزينة العهدة (مدين) ويدخل الكاش الرئيسي (دائن).
 *  - الفرق = الفعلي − آخر المدة المفروض:
 *      • عجز (الفرق سالب): يتسجل في كشف الحساب على خزينة العهدة (مدين) لتصفيرها،
 *        وبيتحدد لو يتحمّله الموظف:
 *          - |العجز| >= العتبة → ذمة على الموظف (custody_deficits, status=pending).
 *          - |العجز| <  العتبة → المحل يتحمله (مفيش ذمة على الموظف).
 *      • زيادة (الفرق موجب): تدخل الكاش الرئيسي (دائن) وتطلع من العهدة... لكن العهدة
 *        أصلاً فيها أقل من الفعلي؟ لا — الزيادة معناها الفعلي أكبر من المفروض،
 *        يبقى خزينة العهدة (اللي رصيدها = آخر المدة المفروض) محتاجة credit بالزيادة
 *        عشان توصل للفعلي قبل ما الاستلام يصفّرها. التفصيل في الكود.
 *  - في كل الأحوال: خزينة العهدة تنتهي بصفر، والعهدة status=closed.
 *  - بعد الإقفال الفرع يبدأ فريش (مفيش عهدة مفتوحة) لحد أول إذن صرف جديد.
 *
 * @param int   $custodyId    العهدة المفتوحة
 * @param float $countedCash  الباقي الفعلي المعدود
 * @param string $date        تاريخ الإقفال
 * @param int|null $employeeId الموظف المسؤول (لتسجيل العجز عليه)
 * @param int|null $userId     منفّذ الإقفال
 *
 * @return array ['difference'=>float,'on_employee'=>bool,'deficit_id'=>?int]
 */
function close_custody(
    int $custodyId,
    float $countedCash,
    string $date,
    ?int $employeeId = null,
    ?int $userId = null
): array {
    if ($countedCash < 0) {
        throw new InvalidArgumentException('الباقي الفعلي لا يمكن أن يكون سالباً.');
    }
    // باقي العهدة بيرجع للرئيسية — نفس الخزينة اللي الإذن طلع منها،
    // عشان الدورة تقفل على نفس الخزينة.
    $cashId = require_main_treasury();

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // اقفل صف العهدة واقرأه محدّثاً
        $st = $pdo->prepare("SELECT * FROM branch_custody WHERE id=? FOR UPDATE");
        $st->execute([$custodyId]);
        $custody = $st->fetch();
        if (!$custody) {
            throw new RuntimeException('العهدة غير موجودة.');
        }
        if ($custody['status'] !== 'open') {
            throw new RuntimeException('العهدة مقفلة بالفعل.');
        }

        $custodyTreasuryId = (int) $custody['custody_treasury_id'];
        $expected = (float) $custody['closing_balance'];   // آخر المدة المفروض
        $difference = $countedCash - $expected;            // موجب=زيادة، سالب=عجز
        $tolerance = (float) setting('custody_deficit_tolerance', '30');

        // الرصيد الحالي لخزينة العهدة (المفروض = $expected نظرياً)
        $balStmt = $pdo->prepare('SELECT balance FROM treasuries WHERE id=? FOR UPDATE');
        $balStmt->execute([$custodyTreasuryId]);
        $custodyBalance = (float) $balStmt->fetchColumn();

        // الخطوة 1: لو فيه زيادة (الفعلي أكبر من رصيد العهدة)، نزوّد العهدة بالزيادة
        // عشان نقدر نستلم الفعلي كامل. الزيادة دائن على العهدة (مصدرها المحل/الفرع).
        if ($countedCash > $custodyBalance) {
            $surplus = $countedCash - $custodyBalance;
            post_journal($custodyTreasuryId, 0, $surplus, 'زيادة عهدة عند الإقفال',
                         $date, 'custody_surplus', $custodyId, $userId, $pdo);
        }

        // الخطوة 2: الاستلام الفعلي — يخرج من العهدة (مدين) ويدخل الكاش (دائن)
        if ($countedCash > 0) {
            post_journal($custodyTreasuryId, $countedCash, 0, 'استلام باقي العهدة عند الإقفال',
                         $date, 'custody_settlement', $custodyId, $userId, $pdo);
            post_journal($cashId, 0, $countedCash, 'استلام باقي عهدة الفرع',
                         $date, 'custody_settlement', $custodyId, $userId, $pdo);
        }

        // الخطوة 3: معالجة العجز (لو الفعلي أقل من المفروض)
        $onEmployee = false;
        $deficitId = null;
        if ($difference < 0) {
            $deficit = abs($difference);
            // تصفير خزينة العهدة من العجز (مدين على العهدة)
            // بعد الاستلام، العهدة لسه فيها قيمة العجز — نشيلها
            post_journal($custodyTreasuryId, $deficit, 0, 'عجز عهدة عند الإقفال',
                         $date, 'custody_deficit', $custodyId, $userId, $pdo);

            // هل يتحمّله الموظف؟
            if ($deficit >= $tolerance) {
                $onEmployee = true;
                $pdo->prepare(
                    'INSERT INTO custody_deficits (custody_id, employee_id, branch_id, amount, status, notes)
                     VALUES (?, ?, ?, ?, "pending", ?)'
                )->execute([$custodyId, $employeeId, (int)$custody['branch_id'], $deficit,
                            'عجز عند إقفال العهدة - يُخصم من المرتب']);
                $deficitId = (int) $pdo->lastInsertId();
            }
            // لو أقل من العتبة: المحل يتحمله، مفيش ذمة على الموظف (الحركة فوق كفّت التصفير)
        }

        // الخطوة 4: تحديث وإقفال العهدة
        $pdo->prepare(
            'UPDATE branch_custody
             SET counted_cash = ?, difference = ?, settled_amount = ?,
                 period_end = ?, status = "closed", closed_by = ?, closed_at = NOW()
             WHERE id = ?'
        )->execute([$countedCash, $difference, $countedCash, $date, $userId, $custodyId]);

        $pdo->commit();
        return ['difference' => $difference, 'on_employee' => $onEmployee, 'deficit_id' => $deficitId];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
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

        // الأرقام اللي دخلت الخزينة قبل كده (لو فيه مراجعة سابقة)
        $prev = $pdo->prepare("SELECT * FROM shift_reviews WHERE shift_id=? ORDER BY id DESC LIMIT 1");
        $prev->execute([$shiftId]);
        $prevReview = $prev->fetch();

        // حساب «إيراد مبيعات» من الإعدادات — مش رقم مكتوب في الكود، عشان
        // لو شجرة الحسابات اتغيّرت مايبوظش الترحيل في صمت. فاضي = null =
        // السلوك القديم بالظبط.
        $salesAccountId = ((int) setting('sales_revenue_account_id', 0)) ?: null;

        // حرّك الفرق لكل وسيلة دفع
        foreach ($methods as $m) {
            $newAmt = (float) ($accountantAmounts[$m] ?? 0);
            $oldAmt = $prevReview ? (float) $prevReview['accountant_' . $m] : 0.0;
            $delta  = $newAmt - $oldAmt;
            if (abs($delta) < 0.001) {
                continue; // مفيش تغيير
            }
            $treasuryId = central_treasury_by_type(payment_method_treasury_type($m));
            if ($treasuryId === null) {
                throw new RuntimeException('لا توجد خزينة مركزية من نوع: ' . $m);
            }
            $label = 'تقفيل وردية #' . $shiftId . ' (' . treasury_type_label($m) . ')';
            if ($delta > 0) {
                // زيادة → دائن (دخول)
                post_journal($treasuryId, 0, $delta, $label, $date, 'shift', $shiftId, $userId, $pdo, $salesAccountId);
            } else {
                // نقص (تصحيح بعد رفض) → مدين (خروج)
                post_journal($treasuryId, abs($delta), 0, $label . ' - تصحيح', $date, 'shift', $shiftId, $userId, $pdo, $salesAccountId);
            }
        }

        // حساب الفرق بين الكاشير والمحاسب (إجمالي)
        $cashierTotal = (float)$shift['cash_total'] + (float)$shift['visa_total']
                      + (float)$shift['instapay_total'];
        $accTotal = 0.0;
        foreach ($methods as $m) $accTotal += (float) ($accountantAmounts[$m] ?? 0);
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

        // تحديث حالة الوردية
        $pdo->prepare(
            'UPDATE shifts SET status=?, reject_reason=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?'
        )->execute([$decision, $decision === 'rejected' ? $reason : null, $userId, $shiftId]);

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

        // صرف من الخزينة
        post_journal($treasuryId, $amount, 0,
            'سلفة للموظف: ' . $empName, $date,
            'advance', null, $userId, $pdo);

        // تسجيل السلفة على الموظف
        $pdo->prepare(
            'INSERT INTO employee_advances (employee_id, amount, advance_date, status, notes)
             VALUES (?, ?, ?, "pending", ?)'
        )->execute([$employeeId, $amount, $date, $notes]);
        $advanceId = (int) $pdo->lastInsertId();

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
    $pdo->beginTransaction();
    try {
        $ct = $pdo->prepare("SELECT id FROM treasuries WHERE branch_id=? AND type='branch_custody' LIMIT 1");
        $ct->execute([$branchId]);
        $ctId = (int) $ct->fetchColumn();
        if (!$ctId) throw new RuntimeException('لا توجد خزينة عهدة لهذا الفرع.');

        // خزينة العهدة تنقص (مدين)، الخزينة الرئيسية تستلم (دائن)
        post_journal($ctId, $amount, 0, 'رد عهدة للخزينة الرئيسية' . ($notes ? " - $notes" : ''),
                     $date, 'custody_return', $branchId, $userId, $pdo);
        post_journal($mainTreasuryId, 0, $amount, 'استلام عهدة من الفرع' . ($notes ? " - $notes" : ''),
                     $date, 'custody_return', $branchId, $userId, $pdo);

        // قلّل أول المدة في العهدة المفتوحة
        $st = $pdo->prepare("SELECT id, opening_balance, total_expenses FROM branch_custody WHERE branch_id=? AND status='open' ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $st->execute([$branchId]);
        $row = $st->fetch();
        if ($row) {
            $newOpening = max(0, (float)$row['opening_balance'] - $amount);
            $newClosing = $newOpening - (float)$row['total_expenses'];
            $pdo->prepare('UPDATE branch_custody SET opening_balance=?, closing_balance=? WHERE id=?')
                ->execute([$newOpening, $newClosing, $row['id']]);
        }

        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
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
 * - exceeded: الجزء المتجاوز للعتبة = max(0, amount - tolerance)
 * - over_limit: هل تجاوز العتبة؟ (exceeded > 0)
 * - status_label: 'داخل العتبة' أو 'تجاوز العتبة'
 *
 * ⚠️ المصدر الموحد للحقيقة لمنطق العتبة في تقرير العجوزات وتاب الجزاءات.
 *
 * @param float $difference فرق الوردية (سالب=عجز، موجب=زيادة)
 * @param float|null $tolerance العتبة (افتراضي من إعداد custody_deficit_tolerance)
 * @return array{kind: string, amount: float, tolerance: float, exceeded: float, over_limit: bool, status_label: string}
 */
function shift_variance_status(float $difference, ?float $tolerance = null): array
{
    if ($tolerance === null) {
        $tolerance = (float) setting('custody_deficit_tolerance', '0');
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

    // المتجاوز = max(0, amount - tolerance) وفقاً لقرار الأونر
    $exceeded = max(0.0, round($amount - $tolerance, 2));
    $overLimit = $exceeded > 0.0001;
    $statusLabel = $overLimit ? 'تجاوز العتبة' : 'داخل العتبة';

    return [
        'kind'         => $kind,
        'amount'       => round($amount, 2),
        'tolerance'    => round($tolerance, 2),
        'exceeded'     => $exceeded,
        'over_limit'   => $overLimit,
        'status_label' => $statusLabel,
    ];
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
    ?float $dueDaysOverride = null
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
    if ($ledgerBal <= 0.0) {
        $advances = 0.0;
    } else {
        $adv = $pdo->prepare(
            'SELECT COALESCE(SUM(
                CASE
                    WHEN monthly_deduction > 0 THEN LEAST(monthly_deduction, COALESCE(remaining, amount))
                    ELSE COALESCE(remaining, amount)
                END
            ), 0)
            FROM employee_advances
            WHERE employee_id = ? AND status = "pending"'
        );
        $adv->execute([$employeeId]);
        $advCalculated = (float) $adv->fetchColumn();
        $advances = min($advCalculated, $ledgerBal);
    }

    // الجزاءات المعلّقة
    $pen = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM employee_penalties WHERE employee_id=? AND status="pending"');
    $pen->execute([$employeeId]);
    $penalties = (float) $pen->fetchColumn();

    // عجز العهدة المعلّق (المربوط بالموظف)
    $def = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM custody_deficits WHERE employee_id=? AND status="pending"');
    $def->execute([$employeeId]);
    $deficits = (float) $def->fetchColumn();

    $net = $noExpected ? 0.0 : ($grossSalary + $bonuses - $advances - $penalties - $deficits);

    return [
        'employee_name'          => $empName,
        'salary_mode'            => $salaryMode,
        'base_salary'            => $base,
        'expected_monthly_hours' => $expectedHours,
        'no_expected'            => $noExpected,
        'day_hours'              => $dayHours,
        'hour_rate'              => $hourRate,
        'total_bonuses'          => $bonuses,
        'total_advances'         => $advances,
        'total_penalties'        => $penalties,
        'total_deficits'         => $deficits,
        'net_salary'             => $net,
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
 * - يتيح للأونر تعديل أيام الإجازة والاستحقاق مع حفظ القيمة المحسوبة والمعدلة ومن قام بالتعديل.
 * - بيقفل المكافآت والسلف والجزاءات وعجز العهدة المعلّقة (paid/deducted/recovered).
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
    ?float $dueDaysOverride = null
): int {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // تأكد إن المرتب مش مصروف قبل كده للشهر ده
        $chk = $pdo->prepare('SELECT id FROM salaries WHERE employee_id=? AND salary_month=?');
        $chk->execute([$employeeId, $month]);
        if ($chk->fetchColumn()) {
            throw new RuntimeException('مرتب هذا الشهر مسجّل بالفعل.');
        }

        $calc = compute_salary($employeeId, $month, $from, $to, $leaveDaysOverride, $dueDaysOverride);
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

        // إعادة حساب الصافي بناءً على الاستحقاق الفعلي والخصومات والمكافآت
        $netSalary = $grossSalary + $calc['total_bonuses'] - $calc['total_advances'] - $calc['total_penalties'] - $calc['total_deficits'];

        $empName = $pdo->prepare('SELECT full_name FROM employees WHERE id=?');
        $empName->execute([$employeeId]);
        $name = $empName->fetchColumn() ?: $name;

        // تسجيل المرتب في جدول salaries
        $pdo->prepare(
            'INSERT INTO salaries
                (employee_id, salary_month, base_salary, total_bonuses, total_advances, total_penalties,
                 total_deficits, net_salary, status, paid_at,
                 period_from, period_to, work_hours, work_days, leave_days,
                 due_days_calc, due_days, due_days_edited_by, due_days_edited_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "paid", NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $employeeId, $month, $calc['base_salary'], $calc['total_bonuses'], $calc['total_advances'],
            $calc['total_penalties'], $calc['total_deficits'], $netSalary,
            $calc['period_from'], $calc['period_to'], $workHours, $workDays,
            $leaveDays, $dueDaysCalc, $dueDays, $editedBy, $editedAt,
        ]);
        $salaryId = (int) $pdo->lastInsertId();

        // صرف الصافي من الخزينة (لو موجب)
        if ($netSalary > 0) {
            post_journal($treasuryId, $netSalary, 0,
                'صرف مرتب: ' . $name . ' (' . $month . ')', $date,
                'salary', $salaryId, $userId, $pdo);
        }

        // إقفال المستحقات والخصومات المعلّقة
        $pdo->prepare('UPDATE employee_bonuses SET status="paid" WHERE employee_id=? AND status="pending"')
            ->execute([$employeeId]);

        // تسوية أقساط السلف المعلّقة للموظف (قراءة مجمّعة وتحديث الصفوف داخل المعاملة مع سقف رصيد السجل الموجب)
        // ⚠️ المعادلة مكررة بين هنا و compute_salary و hr.php ($advMap) — لو غيّرت هنا، غيّر في التلاتة.
        $ledgerBalance = emp_ledger_balance($employeeId, $pdo);
        $remCap = $ledgerBalance > 0 ? $ledgerBalance : 0.0;

        $advStmt = $pdo->prepare(
            'SELECT id, amount, monthly_deduction, remaining, adv_type
             FROM employee_advances
             WHERE employee_id = ? AND status = "pending"
             ORDER BY id ASC FOR UPDATE'
        );
        $advStmt->execute([$employeeId]);
        $pendingAdvances = $advStmt->fetchAll(PDO::FETCH_ASSOC);

        $updAdvStmt = $pdo->prepare(
            'UPDATE employee_advances
             SET remaining = ?, status = ?, adv_type = ?
             WHERE id = ?'
        );

        foreach ($pendingAdvances as $advRow) {
            $advId = (int) $advRow['id'];
            $origAmount = (float) $advRow['amount'];
            $monthlyDed = (float) $advRow['monthly_deduction'];
            $advType = $advRow['adv_type'] ?? 'monthly';
            $outstanding = $advRow['remaining'] !== null ? (float) $advRow['remaining'] : $origAmount;

            $take = ($monthlyDed > 0) ? min($monthlyDed, $outstanding) : $outstanding;
            $take = min($take, $remCap);

            if ($take <= 0.00001) {
                continue;
            }

            $remCap -= $take;
            $newRemaining = $outstanding - $take;

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

        $pdo->prepare('UPDATE employee_penalties SET status="deducted" WHERE employee_id=? AND status="pending"')
            ->execute([$employeeId]);
        $pdo->prepare('UPDATE custody_deficits SET status="recovered", recovered_in_salary_id=? WHERE employee_id=? AND status="pending"')
            ->execute([$salaryId, $employeeId]);

        $pdo->commit();
        return $salaryId;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
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
 * مسح وردية — بشروط أمان صارمة.
 * الموظف يقدر يمسح بس الوردية اللي هو عملها ولسه pending (ماتراجعتش).
 * لو الوردية اتعمدت/اترفضت أو مش بتاعته → بيرفض.
 * (سطور الكاش/الدفع/المراجعة بتتمسح CASCADE مع الوردية).
 */
function delete_shift(int $shiftId, int $userId, bool $isOwner = false): void
{
    $pdo = db();
    $st = $pdo->prepare('SELECT user_id, status FROM shifts WHERE id=?');
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

    $pdo->prepare('DELETE FROM shifts WHERE id=?')->execute([$shiftId]);
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
        $insNotif  = $pdo->prepare(
            'INSERT INTO notifications (user_id, type, title, body, link)
             VALUES (?, "task", ?, ?, ?)'
        );
        $prioLabel = ['normal'=>'', 'important'=>' (مهم)', 'urgent'=>' (عاجل)'][$priority];
        foreach ($userIds as $uid) {
            $insTarget->execute([$taskId, $uid]);
            $insNotif->execute([$uid, 'مهمة جديدة' . $prioLabel, mb_substr($title, 0, 200), 'tasks.php?task=' . $taskId]);
        }
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

/** تعليم مهمة "تمت" من مستخدم مستهدف. */
function mark_task_done(int $taskId, int $userId): void
{
    db()->prepare(
        'UPDATE task_targets SET status="done", done_at=NOW() WHERE task_id=? AND user_id=?'
    )->execute([$taskId, $userId]);
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

        $placeholders = [];
        $params = [];
        foreach ($userIds as $uid) {
            $placeholders[] = '(?, ?, ?, ?, ?)';
            $params[] = $uid;
            $params[] = $type;
            $params[] = $title;
            $params[] = $body;
            $params[] = $link;
        }

        $sql = 'INSERT INTO notifications (user_id, type, title, body, link) VALUES ' . implode(', ', $placeholders);
        $pdo->prepare($sql)->execute($params);
    } catch (Throwable $e) {
        error_log('notify_salaries_paid error: ' . $e->getMessage());
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
 * بيرجّع: ['dist'=>int|null, 'outside'=>0|1, 'checked'=>bool]
 *  - checked=false: مفيش تقييم (الموظف مسموحله من أي مكان، أو الفرع مش مفعّل نطاق،
 *    أو مفيش إحداثيات للفرع، أو الموظف مبعتش موقعه).
 *  - outside=1: بره النطاق (بيتسجّل بس مايمنعش البصمة).
 */
function evaluate_geofence(array $employee, ?float $lat, ?float $lng): array
{
    $out = ['dist' => null, 'outside' => 0, 'checked' => false];

    // الموظف مسموحله يبصم من أي مكان → مفيش تقييم
    if (!empty($employee['allow_anywhere'])) return $out;

    // مفيش موقع من الموظف → مفيش تقييم (بس بنسجّل إنه مبعتش)
    if ($lat === null || $lng === null) return $out;

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

    $dist = gps_distance_m((float)$b['latitude'], (float)$b['longitude'], $lat, $lng);
    $radius = (int)($b['geofence_radius'] ?: 50);

    $out['dist']    = (int)round($dist);
    $out['outside'] = $dist > $radius ? 1 : 0;
    $out['checked'] = true;
    return $out;
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
                $pdo->prepare('UPDATE attendance SET check_in=?, check_in_lat=?, check_in_lng=?,
                        check_in_dist=?, check_in_outside=?, status="present" WHERE id=?')
                    ->execute([$time, $lat, $lng, $dist, $outside, $row['id']]);
            } else {
                $pdo->prepare(
                    'INSERT INTO attendance (employee_id, att_date, check_in, check_in_lat, check_in_lng,
                        check_in_dist, check_in_outside, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, "present")'
                )->execute([$employeeId, $date, $time, $lat, $lng, $dist, $outside]);
            }
            $pdo->commit();
            return ['status' => 'in', 'hours' => null, 'outside' => $outside, 'dist' => $dist];
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
            $pdo->prepare('UPDATE attendance SET check_out=?, check_out_lat=?, check_out_lng=?,
                    check_out_dist=?, check_out_outside=?, work_hours=? WHERE id=?')
                ->execute([$time, $lat, $lng, $dist, $outside, $hours, $row['id']]);
            $pdo->commit();
            return ['status' => 'out', 'hours' => $hours, 'minutes' => $mins, 'outside' => $outside, 'dist' => $dist];
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

        $hours = null;
        if ($in && $out) {
            $hours = round(work_minutes($in, $out) / 60, 2);
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
