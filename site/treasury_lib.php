<?php
/**
 * treasury_lib.php — مكتبة الخزينة وكشف الحساب المركزية (بريف ١٧٥ — موجة ٣)
 * نظام الفجالة ستور المالي
 *
 * دوال قراءة ومحايدة تماماً:
 *   - treasury_parse_period(): ضبط تواريخ كشف الحساب (أول الشهر ← اليوم)
 *   - treasury_get_treasuries(): جلب كل الخزائن وأرصدتها والكاش الفعلي (أرصدة مخزنة فقط)
 *   - treasury_get_statement(): جلب كشف الحساب (لخزينة محددة أو مجمّع) مع الإجماليات
 *   - treasury_get_auxiliary_lists(): جلب القوائم المساعدة (موظفون، موردون، فروع العهدة، إلخ)
 */
declare(strict_types=1);

require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/reports_lib.php';

/**
 * ضبط فترة كشف الحساب.
 * الافتراضي: من أول الشهر الحالي حتى اليوم.
 *
 * @return array{from: string, to: string}
 */
function treasury_parse_period(?string $from, ?string $to): array {
    $stFrom = ($from !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from))
        ? $from
        : date('Y-m-01');
    $stTo = ($to !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))
        ? $to
        : date('Y-m-d');

    return [
        'from' => $stFrom,
        'to'   => $stTo,
    ];
}

/**
 * جلب جميع الخزائن وأرصدتها المخزنة.
 *
 * @return array{
 *     treasuries: array,
 *     total_balance: float,
 *     real_cash: float
 * }
 */
function treasury_get_treasuries(PDO $pdo): array {
    $treasuries = $pdo->query(
        'SELECT t.*, b.name AS branch_name
         FROM treasuries t
         LEFT JOIN branches b ON b.id = t.branch_id
         WHERE t.is_active = 1
         ORDER BY FIELD(t.type, "cash","main","visa","instapay","card","branch_custody","employee_custody"), t.name'
    )->fetchAll(PDO::FETCH_ASSOC);

    $totalBalance = (float) array_sum(array_column($treasuries, 'balance'));

    // «خزينة الكاش الفعلي» = الخزينة الرئيسية + خزينة الكاش (أرصدة مخزنة)
    $realCash = 0.0;
    foreach ($treasuries as $t) {
        if (in_array($t['type'], ['main', 'cash'], true)) {
            $realCash += (float) $t['balance'];
        }
    }

    return [
        'treasuries'    => $treasuries,
        'total_balance' => $totalBalance,
        'real_cash'     => $realCash,
    ];
}

/**
 * جلب كشف الحساب لخزينة محددة أو مجمّع لكل الخزائن مع الإجماليات.
 *
 * @return array{
 *     selected_param: string,
 *     selected_treasury_id: ?int,
 *     selected_treasury: ?array,
 *     is_all_statement: bool,
 *     statement: array,
 *     totals: array{
 *         total_debit: float,
 *         total_credit: float,
 *         total_net: float,
 *         total_count: int
 *     }
 * }
 */
function treasury_get_statement(PDO $pdo, string $selectedParam, string $stFrom, string $stTo, array $treasuries, int $stMaxRows = 5000): array {
    $selectedTreasuryId = (ctype_digit((string)$selectedParam) && (int)$selectedParam > 0)
        ? (int)$selectedParam
        : null;

    $statement        = [];
    $selectedTreasury = null;
    $isAllStatement   = ($selectedParam === 'all');
    $totalDebit       = 0.0;
    $totalCredit      = 0.0;
    $totalNet         = 0.0;
    $totalCount       = 0;

    $tmRevSelect = treasury_movements_has_reverse_cols($pdo) ? ', tm.reversed_at' : ', NULL AS reversed_at';

    if ($isAllStatement) {
        // كشف مجمّع لكل الخزائن (مع اسم الخزينة في كل حركة)
        $st = $pdo->prepare(
            "SELECT je.*, t.name AS treasury_name, u.full_name AS by_name,
                    COALESCE(je.account_id, tm.entry_code_id) AS display_account_id,
                    CASE WHEN ja.id IS NOT NULL THEN ja_p.name ELSE ec_p.name END AS parent_account_name,
                    CASE
                        WHEN ja.id IS NOT NULL THEN (CASE WHEN ja.src_table = 'suppliers' THEN ja.name ELSE NULL END)
                        WHEN ec.id IS NOT NULL THEN (CASE WHEN ec.src_table = 'suppliers' THEN ec.name ELSE NULL END)
                        ELSE NULL
                    END AS supplier_name,
                    eu.full_name AS edited_by_name,
                    cb.name AS cost_branch_name,
                    sh.shift_name,
                    tfrom.name AS transfer_from_name,
                    tto.name AS transfer_to_name{$tmRevSelect}
             FROM journal_entries je
             JOIN treasuries t ON t.id = je.treasury_id
             LEFT JOIN users u ON u.id = je.created_by
             LEFT JOIN users eu ON eu.id = je.edited_by
             LEFT JOIN treasury_movements tm ON tm.id = je.ref_id AND je.ref_type IN ('receipt','disbursement','date_fix_rev','reversal')
             LEFT JOIN accounts ec ON ec.id = tm.entry_code_id
             LEFT JOIN accounts ec_p ON ec_p.id = ec.parent_id
             LEFT JOIN accounts ja ON ja.id = je.account_id
             LEFT JOIN accounts ja_p ON ja_p.id = ja.parent_id
             LEFT JOIN shifts sh ON sh.id = je.ref_id AND je.ref_type = 'shift'
             LEFT JOIN branch_custody bc ON bc.id = je.ref_id
                  AND je.ref_type IN ('custody_return','custody_settlement','custody_surplus','custody_deficit')
             LEFT JOIN custody_lines cl ON cl.id = je.ref_id AND je.ref_type = 'custody_expense'
             LEFT JOIN branch_custody bc2 ON bc2.id = cl.custody_id
             LEFT JOIN disbursement_orders dor ON dor.id = je.ref_id AND je.ref_type = 'disbursement_order'
             LEFT JOIN branches cb ON cb.id = COALESCE(tm.branch_id, sh.branch_id, bc.branch_id, bc2.branch_id, dor.branch_id)
             LEFT JOIN treasury_transfers tt ON tt.id = je.ref_id AND je.ref_type = 'transfer'
             LEFT JOIN treasuries tfrom ON tfrom.id = tt.from_treasury_id
             LEFT JOIN treasuries tto   ON tto.id   = tt.to_treasury_id
             WHERE je.entry_date BETWEEN ? AND ?
             ORDER BY je.id DESC LIMIT {$stMaxRows}"
        );
        $st->execute([$stFrom, $stTo]);
        $statement = $st->fetchAll(PDO::FETCH_ASSOC);

        // حساب الكروت باستعلام تجميعي منفصل (SUM) على نفس الفترة من غير LIMIT
        $sumSt = $pdo->prepare(
            "SELECT COALESCE(SUM(je.debit), 0) AS total_debit,
                    COALESCE(SUM(je.credit), 0) AS total_credit,
                    COUNT(*) AS cnt
             FROM journal_entries je
             WHERE je.entry_date BETWEEN ? AND ?"
        );
        $sumSt->execute([$stFrom, $stTo]);
        $sumRow = $sumSt->fetch(PDO::FETCH_ASSOC) ?: [];
        $totalDebit  = (float) ($sumRow['total_debit'] ?? 0);
        $totalCredit = (float) ($sumRow['total_credit'] ?? 0);
        $totalNet    = $totalDebit - $totalCredit;
        $totalCount  = (int) ($sumRow['cnt'] ?? 0);
    } else {
        $selectedId = (int) $selectedParam;
        if ($selectedId > 0) {
            foreach ($treasuries as $t) {
                if ((int) $t['id'] === $selectedId) {
                    $selectedTreasury = $t;
                    break;
                }
            }
            if ($selectedTreasury) {
                $st = $pdo->prepare(
                    "SELECT je.*, u.full_name AS by_name,
                            COALESCE(je.account_id, tm.entry_code_id) AS display_account_id,
                            CASE WHEN ja.id IS NOT NULL THEN ja_p.name ELSE ec_p.name END AS parent_account_name,
                            CASE
                                WHEN ja.id IS NOT NULL THEN (CASE WHEN ja.src_table = 'suppliers' THEN ja.name ELSE NULL END)
                                WHEN ec.id IS NOT NULL THEN (CASE WHEN ec.src_table = 'suppliers' THEN ec.name ELSE NULL END)
                                ELSE NULL
                            END AS supplier_name,
                            eu.full_name AS edited_by_name,
                            cb.name AS cost_branch_name,
                            sh.shift_name,
                            tfrom.name AS transfer_from_name,
                            tto.name AS transfer_to_name{$tmRevSelect}
                     FROM journal_entries je
                     LEFT JOIN users u ON u.id = je.created_by
                     LEFT JOIN users eu ON eu.id = je.edited_by
                     LEFT JOIN treasury_movements tm ON tm.id = je.ref_id AND je.ref_type IN ('receipt','disbursement','date_fix_rev','reversal')
                     LEFT JOIN accounts ec ON ec.id = tm.entry_code_id
                     LEFT JOIN accounts ec_p ON ec_p.id = ec.parent_id
                     LEFT JOIN accounts ja ON ja.id = je.account_id
                     LEFT JOIN accounts ja_p ON ja_p.id = ja.parent_id
                     LEFT JOIN shifts sh ON sh.id = je.ref_id AND je.ref_type = 'shift'
                     LEFT JOIN branch_custody bc ON bc.id = je.ref_id
                          AND je.ref_type IN ('custody_return','custody_settlement','custody_surplus','custody_deficit')
                     LEFT JOIN custody_lines cl ON cl.id = je.ref_id AND je.ref_type = 'custody_expense'
                     LEFT JOIN branch_custody bc2 ON bc2.id = cl.custody_id
                     LEFT JOIN disbursement_orders dor ON dor.id = je.ref_id AND je.ref_type = 'disbursement_order'
                     LEFT JOIN branches cb ON cb.id = COALESCE(tm.branch_id, sh.branch_id, bc.branch_id, bc2.branch_id, dor.branch_id)
                     LEFT JOIN treasury_transfers tt ON tt.id = je.ref_id AND je.ref_type = 'transfer'
                     LEFT JOIN treasuries tfrom ON tfrom.id = tt.from_treasury_id
                     LEFT JOIN treasuries tto   ON tto.id   = tt.to_treasury_id
                     WHERE je.treasury_id = ? AND je.entry_date BETWEEN ? AND ?
                     ORDER BY je.id DESC
                     LIMIT {$stMaxRows}"
                );
                $st->execute([$selectedId, $stFrom, $stTo]);
                $statement = $st->fetchAll(PDO::FETCH_ASSOC);

                // حساب الكروت باستعلام تجميعي منفصل (SUM) على نفس الفترة من غير LIMIT
                $sumSt = $pdo->prepare(
                    "SELECT COALESCE(SUM(je.debit), 0) AS total_debit,
                            COALESCE(SUM(je.credit), 0) AS total_credit,
                            COUNT(*) AS cnt
                     FROM journal_entries je
                     WHERE je.treasury_id = ? AND je.entry_date BETWEEN ? AND ?"
                );
                $sumSt->execute([$selectedId, $stFrom, $stTo]);
                $sumRow = $sumSt->fetch(PDO::FETCH_ASSOC) ?: [];
                $totalDebit  = (float) ($sumRow['total_debit'] ?? 0);
                $totalCredit = (float) ($sumRow['total_credit'] ?? 0);
                $totalNet    = $totalDebit - $totalCredit;
                $totalCount  = (int) ($sumRow['cnt'] ?? 0);
            }
        }
    }

    $accountLabels = rep_account_labels(array_column($statement, 'display_account_id'));
    foreach ($statement as &$row) {
        $labels = $accountLabels[(int) $row['display_account_id']] ?? ['root_name'=>null, 'leaf_name'=>null];
        $row['parent_account_name'] = $labels['root_name'];
        $row['entry_code_name'] = $labels['leaf_name'];
    }
    unset($row);

    return [
        'selected_param'       => $selectedParam,
        'selected_treasury_id' => $selectedTreasuryId,
        'selected_treasury'    => $selectedTreasury,
        'is_all_statement'     => $isAllStatement,
        'statement'            => $statement,
        'totals'               => [
            'total_debit'  => $totalDebit,
            'total_credit' => $totalCredit,
            'total_net'    => $totalNet,
            'total_count'  => $totalCount,
        ],
    ];
}

/**
 * جلب القوائم المساعدة المستخدمة في شاشات ونماذج الخزينة.
 *
 * @return array{
 *     employees: array,
 *     suppliers: array,
 *     branches_for_custody: array,
 *     entry_codes: array,
 *     cost_branches: array
 * }
 */
function treasury_get_auxiliary_lists(PDO $pdo): array {
    return [
        'employees'            => $pdo->query('SELECT id, full_name FROM employees WHERE is_active=1 ORDER BY full_name')->fetchAll(PDO::FETCH_ASSOC),
        'suppliers'            => $pdo->query('SELECT id, name FROM suppliers WHERE is_active=1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC),
        'branches_for_custody' => $pdo->query('SELECT id, name FROM branches WHERE is_active=1 AND has_finance=1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC),
        'entry_codes'          => entry_codes_list(),
        'cost_branches'        => cost_branches_list(),
    ];
}
