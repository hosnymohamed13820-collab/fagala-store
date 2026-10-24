<?php
/**
 * reports_lib.php — مكتبة دوال التقارير (بريف ١٧١)
 * نظام الفجالة ستور المالي
 */
declare(strict_types=1);

require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/sensitive_lib.php';
require_once __DIR__ . '/sms_lib.php';
require_once __DIR__ . '/cashier_lib.php';

/** آخر رصيد محفوظ قبل الفترة لنفس الخزنة؛ لا نعيد حساب أرصدة القيود. */
function treasury_prior_balance(PDO $pdo, int $treasuryId, string $from, bool $showCancelled = false): float {
    $cancelledFilter = $showCancelled ? '' : " AND je.ref_type <> 'custody_expense_del'
        AND NOT (je.ref_type = 'custody_expense' AND EXISTS (
            SELECT 1 FROM journal_entries rev
            WHERE rev.ref_type = 'custody_expense_del' AND rev.ref_id = je.ref_id
        ))";
    $st = $pdo->prepare("SELECT je.running_balance FROM journal_entries je
        WHERE je.treasury_id = ? AND je.entry_date < ? " . $cancelledFilter . "
        ORDER BY je.entry_date DESC, je.id DESC LIMIT 1");
    $st->execute([$treasuryId, $from]);
    $balance = $st->fetchColumn();
    return $balance === false ? 0.0 : (float) $balance;
}

/** مطابقة سطور إنستاباي برسائل الوارد على يوم العمل والمبلغ بالقرش. */
function rep_insta_recon(PDO $pdo, string $from, string $to): array {
    $st = $pdo->prepare("SELECT s.id AS shift_id, s.business_date, s.shift_name, s.status AS shift_status, s.instapay_total,
            b.id AS branch_id, b.name AS branch_name, u.full_name AS cashier_name,
            l.id AS line_id, l.line_no, l.reference, l.amount
        FROM shifts s
        JOIN branches b ON b.id = s.branch_id
        LEFT JOIN users u ON u.id = s.user_id
        LEFT JOIN shift_payment_lines l ON l.shift_id = s.id AND l.method = 'instapay'
        WHERE s.business_date BETWEEN ? AND ?
          AND (s.instapay_total <> 0 OR l.id IS NOT NULL)
        ORDER BY s.business_date DESC, b.name, s.id, l.line_no");
    $st->execute([$from, $to]);
    $shiftRows = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare("SELECT id, sms_at, sender, body FROM sms_inbox
        WHERE sms_at >= CONCAT(?, ' 09:00:00')
          AND sms_at < DATE_ADD(CONCAT(?, ' 09:00:00'), INTERVAL 1 DAY)
        ORDER BY sms_at, id");
    $st->execute([$from, $to]);
    $smsRows = $st->fetchAll(PDO::FETCH_ASSOC);
    $messages = [];
    $smsByKey = [];
    foreach ($smsRows as $sms) {
        if (sms_is_foreign((string) $sms['body'])) continue;
        $parsed = sms_parse((string) $sms['body'], (string) $sms['sms_at']);
        if ($parsed['kind'] !== 'in' || $parsed['amount'] === null) continue;
        $sms['amount_cents'] = (int) round((float) $parsed['amount'] * 100);
        $sms['business_date'] = cashier_get_business_date(new DateTime((string) $sms['sms_at']));
        $sms['key'] = $sms['business_date'] . '|' . $sms['amount_cents'];
        $smsByKey[$sms['key']][] = count($messages);
        $messages[] = $sms;
    }

    $groups = [];
    $lines = [];
    $linesByKey = [];
    $seenShifts = [];
    foreach ($shiftRows as $r) {
        $groupKey = $r['business_date'] . '|' . $r['branch_id'];
        if (!isset($groups[$groupKey])) {
            $groups[$groupKey] = ['date'=>$r['business_date'], 'branch'=>$r['branch_name'],
                'shift_cents'=>0, 'line_cents'=>0, 'matched_cents'=>0,
                'shift_count'=>0, 'matched_count'=>0, 'ambiguous_count'=>0, 'missing_count'=>0,
                'lines'=>[]];
        }
        $shiftId = (int) $r['shift_id'];
        if (!isset($seenShifts[$shiftId])) {
            $seenShifts[$shiftId] = true;
            $groups[$groupKey]['shift_cents'] += (int) round((float) $r['instapay_total'] * 100);
            $groups[$groupKey]['shift_count']++;
        }
        if ($r['line_id'] === null) continue;
        $cents = (int) round((float) $r['amount'] * 100);
        $key = $r['business_date'] . '|' . $cents;
        $linesByKey[$key][] = count($lines);
        $lines[] = ['id'=>(int)$r['line_id'], 'shift_id'=>$shiftId,
            'shift_name'=>$r['shift_name'] ?: '—', 'shift_status'=>$r['shift_status'],
            'cashier'=>$r['cashier_name'] ?: '—',
            'reference'=>$r['reference'] ?: '—', 'date'=>$r['business_date'],
            'branch'=>$r['branch_name'], 'group'=>$groupKey, 'key'=>$key,
            'amount_cents'=>$cents, 'status'=>'', 'sms'=>null, 'candidates'=>[]];
        $groups[$groupKey]['line_cents'] += $cents;
    }

    $usedSms = [];
    foreach ($lines as &$line) {
        $candidateIndexes = $smsByKey[$line['key']] ?? [];
        if (count($candidateIndexes) === 1 && count($linesByKey[$line['key']]) === 1) {
            $idx = $candidateIndexes[0];
            $line['status'] = 'مطابق';
            $line['sms'] = $messages[$idx];
            $usedSms[$idx] = true;
            $groups[$line['group']]['matched_cents'] += $line['amount_cents'];
            $groups[$line['group']]['matched_count']++;
        } elseif ($candidateIndexes) {
            $line['status'] = 'متكرر — محتاج مراجعة';
            $line['candidates'] = array_map(static fn($idx) => (int)$messages[$idx]['id'], $candidateIndexes);
            $groups[$line['group']]['ambiguous_count']++;
        } else {
            $line['status'] = 'مفيش رسالة';
            $groups[$line['group']]['missing_count']++;
        }
        $groups[$line['group']]['lines'][] = $line;
    }
    unset($line);

    $unmatched = [];
    foreach ($messages as $idx => $sms) {
        if (!isset($usedSms[$idx])) $unmatched[] = $sms;
    }
    $rows = []; $details = [];
    $diffOnly = ($_GET['diff_only'] ?? '') === '1';
    foreach ($groups as $g) {
        $difference = $g['shift_cents'] - $g['matched_cents'];
        $status = $g['ambiguous_count'] ? 'مبلغ متكرر' : ($g['missing_count'] ? 'رسالة ناقصة'
            : ($g['line_cents'] !== $g['shift_cents'] ? 'إجمالي الوردية مختلف' : 'مطابق'));
        if ($diffOnly && $status === 'مطابق') continue;
        $rows[] = [$g['date'], $g['branch'], number_format($g['shift_cents'] / 100, 2),
            number_format($g['matched_cents'] / 100, 2), number_format($difference / 100, 2),
            $g['matched_count'], count($g['lines']), $g['shift_count'], $status];
        $details[] = $g['lines'];
    }
    return ['title'=>'مطابقة إنستاباي بالورديات',
        'headers'=>['اليوم','الفرع','إنستا الورديات','رسائل مؤكدة','غير مؤكد','عدد الرسائل','عدد العمليات','عدد الورديات','الحالة'],
        'rows'=>$rows, 'details'=>$details, 'unmatched_sms'=>$unmatched,
        'note'=>'المطابقة مؤكدة بس لما يكون فيه سطر وردية واحد ورسالة واردة واحدة بنفس المبلغ ويوم العمل (٩ صباحًا لليوم التالي). التكرار بيتعرض للمراجعة من غير نسبته لفرع.'];
}

/**
 * جلب معرّف الحساب المختار وجميع الحسابات المتفرعة منه (النسل) من شجرة الحسابات.
 * تضمن إن اختيار رأس قيد يطابق حركات كل أبنائه وتفرعاته.
 *
 * @param int $rootId معرّف الحساب المختار
 * @return int[] مصفوفة معرّفات الحسابات (الأب + النسل)
 */
function rep_account_family_ids(int $rootId): array
{
    // 🔴 بيرجّع [$rootId] على الأقل — مايرجّعش [] أبداً. مصفوفة فاضية معناها
    //    `IN ()` وده خطأ صياغة في SQL بيوقّع الصفحة (يوصلها `entry_code=0`).
    if ($rootId <= 0) return [$rootId];

    // 🔴 بنقرا من `accounts` مباشرةً مش من `accounts_tree()`: الشجرة بتفلتر
    //    `is_active = 1`، والفلتر هنا شغال على **بيانات تاريخية**. لو تصنيف
    //    اتوقف، حركاته القديمة لازم تفضل تبان لما تفلتر برأس القيد بتاعه،
    //    وإلا التقرير بينقص فلوس في صمت.
    static $byParent = null;
    if ($byParent === null) {
        $byParent = [];
        try {
            foreach (db()->query('SELECT id, parent_id FROM accounts') as $a) {
                $byParent[(int) ($a['parent_id'] ?? 0)][] = (int) $a['id'];
            }
        } catch (Throwable $ex) {
            $byParent = [];
        }
    }
    $family = [$rootId];
    $visited = [$rootId => true];
    $queue = [$rootId];
    while (!empty($queue)) {
        $curr = array_shift($queue);
        foreach ($byParent[$curr] ?? [] as $childId) {
            if (!isset($visited[$childId])) {
                $visited[$childId] = true;
                $family[] = $childId;
                $queue[] = $childId;
            }
        }
    }
    return $family;
}

/**
 * أسماء رأس القيد والقيد لكل حساب مطلوب. بنقرأ الشجرة كلها مرة واحدة،
 * وبنمشي للأب الأعلى في الذاكرة حتى لو الحساب أعمق من مستويين.
 *
 * @param int[] $accountIds
 * @return array<int, array{root_name: ?string, leaf_name: ?string}>
 */
function rep_account_labels(array $accountIds): array
{
    static $accounts = null;
    static $resolved = [];
    if ($accounts === null) {
        $accounts = [];
        foreach (db()->query('SELECT id, parent_id, name FROM accounts') as $account) {
            $accounts[(int) $account['id']] = $account;
        }
    }

    $result = [];
    foreach ($accountIds as $rawId) {
        $id = (int) $rawId;
        if (!isset($resolved[$id])) {
            $resolved[$id] = ['root_name' => null, 'leaf_name' => null];
            if (isset($accounts[$id])) {
                $rootId = $id;
                $seen = [$id => true];
                while (($parentId = (int) ($accounts[$rootId]['parent_id'] ?? 0)) > 0
                    && isset($accounts[$parentId]) && !isset($seen[$parentId])) {
                    $rootId = $parentId;
                    $seen[$rootId] = true;
                }
                $resolved[$id] = [
                    'root_name' => (string) $accounts[$rootId]['name'],
                    'leaf_name' => $rootId === $id ? null : (string) $accounts[$id]['name'],
                ];
            }
        }
        $result[$id] = $resolved[$id];
    }
    return $result;
}

/** الاسم المرتبط بنفس القيد؛ بنجمع المعرّفات وبنقرأ كل مصدر مرة واحدة. */
function rep_row_names(PDO $pdo, array $rows): array {
    $accountIds = []; $advanceIds = []; $recoveryIds = []; $custodyIds = [];
    foreach ($rows as $r) {
        $accountId = (int)($r['display_account_id'] ?? $r['account_id'] ?? 0);
        if ($accountId > 0) $accountIds[$accountId] = true;
        $refId = (int)($r['ref_id'] ?? 0);
        if ($refId <= 0) continue;
        switch ($r['ref_type'] ?? '') {
            case 'advance': $advanceIds[$refId] = true; break;
            case 'advance_recovery': $recoveryIds[$refId] = true; break;
            case 'custody_expense': $custodyIds[$refId] = true; break;
        }
    }
    $accounts = []; $advances = []; $recoveries = []; $custody = [];
    if ($accountIds) {
        $marks = implode(',', array_fill(0, count($accountIds), '?'));
        $st = $pdo->prepare("SELECT id, name, parent_id, src_table FROM accounts WHERE id IN ($marks)");
        $st->execute(array_keys($accountIds));
        foreach ($st as $r) $accounts[(int)$r['id']] = $r;
    }
    if ($advanceIds) {
        $marks = implode(',', array_fill(0, count($advanceIds), '?'));
        $st = $pdo->prepare("SELECT ea.id, e.full_name FROM employee_advances ea
            JOIN employees e ON e.id=ea.employee_id WHERE ea.id IN ($marks)");
        $st->execute(array_keys($advanceIds));
        foreach ($st as $r) $advances[(int)$r['id']] = $r['full_name'];
    }
    if ($recoveryIds) {
        $marks = implode(',', array_fill(0, count($recoveryIds), '?'));
        $st = $pdo->prepare("SELECT id, full_name FROM employees WHERE id IN ($marks)");
        $st->execute(array_keys($recoveryIds));
        foreach ($st as $r) $recoveries[(int)$r['id']] = $r['full_name'];
    }
    if ($custodyIds) {
        $marks = implode(',', array_fill(0, count($custodyIds), '?'));
        $st = $pdo->prepare("SELECT cl.id, e.full_name AS employee_name, s.name AS supplier_name
            FROM custody_lines cl LEFT JOIN employees e ON e.id=cl.employee_id
            LEFT JOIN suppliers s ON s.id=cl.supplier_id WHERE cl.id IN ($marks)");
        $st->execute(array_keys($custodyIds));
        foreach ($st as $r) $custody[(int)$r['id']] = $r['employee_name'] ?: ($r['supplier_name'] ?: null);
    }
    $names = [];
    foreach ($rows as $i => $r) {
        $accountId = (int)($r['display_account_id'] ?? $r['account_id'] ?? 0);
        $account = $accounts[$accountId] ?? null;
        if ($account && (in_array($account['src_table'], ['suppliers','employees'], true)
            || (int)$account['parent_id'] === 204)) {
            $names[$i] = (string)$account['name'];
            continue;
        }
        $refId = (int)($r['ref_id'] ?? 0);
        $names[$i] = match ($r['ref_type'] ?? '') {
            'advance' => $advances[$refId] ?? '—',
            'advance_recovery' => $recoveries[$refId] ?? '—',
            'custody_expense' => $custody[$refId] ?? '—',
            default => '—',
        };
    }
    return $names;
}

/**
 * بيانات كل تقرير: بترجّع ['title','headers'=>[],'rows'=>[[]],'totals'=>[]]
 */
function build_report(PDO $pdo, string $type, string $from, string $to): array
{
    switch ($type) {
        case 'insta_recon':
            return rep_insta_recon($pdo, $from, $to);
        case 'sales':
            // فلتر اختياري بالفرع (أزرار الفروع فوق الجدول)
            $branchFilter = isset($_GET['branch']) && $_GET['branch'] !== '' ? (int)$_GET['branch'] : null;
            $sqlSales =
                "SELECT s.business_date, s.shift_name, b.name AS branch, u.full_name AS cashier,
                        s.cash_total, s.visa_total, s.instapay_total, s.system_total,
                        (s.cash_total+s.visa_total+s.instapay_total) AS paid_sum,
                        s.difference, s.status
                 FROM shifts s JOIN branches b ON b.id=s.branch_id JOIN users u ON u.id=s.user_id
                 WHERE s.business_date BETWEEN ? AND ?";
            $paramsSales = [$from, $to];
            if ($branchFilter !== null) {
                $sqlSales .= " AND s.branch_id = ?";
                $paramsSales[] = $branchFilter;
            }
            $sqlSales .= " ORDER BY s.business_date DESC, s.id DESC";
            $st = $pdo->prepare($sqlSales);
            $st->execute($paramsSales);
            $rows = []; $tCollected = 0; $tDiff = 0;
            $stMap = ['submitted'=>'مستلمة','approved'=>'معتمدة','rejected'=>'مرفوضة','resubmitted'=>'معاد إرسال'];
            foreach ($st as $r) {
                // «المحصّل» = إجمالي السيستم (قرار محمد 2026-08-22).
                // كان قبل كده مجموع وسايل الدفع، والاتنين بيفرقوا بمقدار
                // «الفرق» بالظبط — فلو الكارت سيستم والعمود مجموع وسايل،
                // الكارت مش هيطابق عموده. خلّينا الاتنين سيستم.
                $tCollected += (float)$r['system_total']; $tDiff += (float)$r['difference'];
                $visaInsta = (float)$r['visa_total'] + (float)$r['instapay_total'];
                $rows[] = [$r['business_date'], $r['shift_name'] ?: '—', $r['branch'], $r['cashier'],
                    number_format((float)$r['cash_total'],2), number_format((float)$r['visa_total'],2),
                    number_format((float)$r['instapay_total'],2),
                    number_format($visaInsta,2),
                    number_format((float)$r['system_total'],2), number_format((float)$r['difference'],2),
                    $stMap[$r['status']] ?? $r['status']];
            }
            return ['title'=>'تقرير المبيعات','headers'=>['التاريخ','رقم الوردية','الفرع','الكاشير','كاش','فيزا','إنستا','فيزا + إنستا','المحصّل','الفرق','الحالة'],
                'rows'=>$rows,
                // الترتيب مقصود: المحصل · الكاش · الفيزا · الإنستا ·
                // الفيزا+الإنستا · الفرق — نفس ترتيب شاشة تقفيل الكاشير.
                // كارت الفيزا+الإنستا كان بيجمع عمودين (cols)؛ بقى عمود
                // حقيقي في الجدول فبيشاور عليه بـcol زي باقي الكروت.
                'sum_cards'=>[
                    ['label'=>'المحصل',        'col'=>8],
                    ['label'=>'الكاش',         'col'=>4],
                    ['label'=>'الفيزا',        'col'=>5],
                    ['label'=>'الإنستا',       'col'=>6],
                    ['label'=>'الفيزا + الإنستا','col'=>7],
                    ['label'=>'الفرق',         'col'=>9, 'signed'=>true],
                ],
                'totals'=>['','','','','','','','','إجمالي محصّل: '.number_format($tCollected,2),'فرق: '.number_format($tDiff,2),'']];

        case 'treasury':
            $showCancelled = ($_GET['show_cancelled'] ?? '') === '1';
            $treasuryFilter = isset($_GET['treasury']) && ctype_digit((string) $_GET['treasury']) && (int) $_GET['treasury'] > 0
                ? (int) $_GET['treasury'] : null;
            // فلتر اختياري بالقيد
            $entryFilter = isset($_GET['entry_code']) && $_GET['entry_code'] !== '' ? (int)$_GET['entry_code'] : null;
            // فلتر اختياري بفرع التكلفة
            $branchFilter = isset($_GET['cost_branch']) && $_GET['cost_branch'] !== '' ? (int)$_GET['cost_branch'] : null;
            $singleFullTreasury = $treasuryFilter !== null && $entryFilter === null && $branchFilter === null;
            $sqlT =
                "SELECT je.id, je.entry_date, je.ref_type, je.ref_id, je.edited_at, sh.shift_name, t.name AS treasury, je.description, je.debit, je.credit, je.running_balance,
                        u.full_name AS by_name,
                        COALESCE(je.account_id, tm.entry_code_id) AS account_id,
                        je.notes,
                        cb.name AS cost_branch_name,
                        tfrom.name AS transfer_from_name,
                        tto.name AS transfer_to_name
                 FROM journal_entries je JOIN treasuries t ON t.id=je.treasury_id
                 LEFT JOIN users u ON u.id=je.created_by
                 LEFT JOIN treasury_movements tm ON tm.id = je.ref_id AND je.ref_type IN ('receipt','disbursement','date_fix_rev','reversal')
                 LEFT JOIN accounts ec ON ec.id = tm.entry_code_id
                 LEFT JOIN accounts ja ON ja.id = je.account_id
                 LEFT JOIN shifts sh ON sh.id = je.ref_id AND je.ref_type = 'shift'
                 LEFT JOIN branch_custody bc ON bc.id = je.ref_id
                      AND je.ref_type IN ('custody_return','custody_settlement','custody_surplus','custody_deficit')
                 LEFT JOIN custody_lines cl ON cl.id = je.ref_id AND je.ref_type = 'custody_expense'
                 LEFT JOIN branch_custody bc2 ON bc2.id = cl.custody_id
                 LEFT JOIN disbursement_orders dor ON dor.id = je.ref_id AND je.ref_type = 'disbursement_order'
                 LEFT JOIN branches cb ON cb.id = COALESCE(tm.branch_id, sh.branch_id, bc.branch_id, cl.branch_id, bc2.branch_id, dor.branch_id)
                 LEFT JOIN treasury_transfers tt ON tt.id = je.ref_id AND je.ref_type = 'transfer'
                 LEFT JOIN treasuries tfrom ON tfrom.id = tt.from_treasury_id
                 LEFT JOIN treasuries tto   ON tto.id   = tt.to_treasury_id
                 WHERE je.entry_date BETWEEN ? AND ?";
            $paramsT = [$from, $to];
            if ($treasuryFilter !== null) {
                $sqlT .= ' AND je.treasury_id = ?';
                $paramsT[] = $treasuryFilter;
            }
            if (!$showCancelled) {
                $sqlT .= " AND je.ref_type <> 'custody_expense_del'
                    AND NOT (je.ref_type = 'custody_expense' AND EXISTS (
                        SELECT 1 FROM journal_entries rev
                        WHERE rev.ref_type = 'custody_expense_del' AND rev.ref_id = je.ref_id
                    ))";
            }
            if ($entryFilter !== null) {
                // COALESCE مش tm.entry_code_id لوحده: القيد ممكن يكون
                // متسجّل على القيد نفسه (je.account_id) — زي القيود اللي
                // مش صرف/استلام، واللي اتحدّد قيدها من بوب أب التعديل.
                // العرض بقى COALESCE فالفلتر لازم يبقى نفسه، وإلا يفلتر
                // صفوف بتبان في الشاشة بقيد صح.
                // الحساب المختار أو أي حساب من نسله
                $familyIds = rep_account_family_ids($entryFilter);
                $placeholders = implode(',', array_fill(0, count($familyIds), '?'));
                $sqlT .= " AND COALESCE(je.account_id, tm.entry_code_id) IN ($placeholders)";
                foreach ($familyIds as $fid) {
                    $paramsT[] = $fid;
                }
            }
            if ($branchFilter !== null) {
                $sqlT .= " AND COALESCE(tm.branch_id, sh.branch_id, bc.branch_id, cl.branch_id, bc2.branch_id, dor.branch_id) = ?";
                $paramsT[] = $branchFilter;
            }
            $sqlT .= $treasuryFilter !== null
                ? ' ORDER BY je.entry_date ASC, je.id ASC'
                : ' ORDER BY je.id DESC';
            $st = $pdo->prepare($sqlT);
            $st->execute($paramsT);
            $data = $st->fetchAll(PDO::FETCH_ASSOC);
            $accountLabels = rep_account_labels(array_column($data, 'account_id'));
            $rowNames = rep_row_names($pdo, $data);
            $rows=[]; $rowPaths=[]; $tDr=0; $tCr=0;
            foreach ($data as $i => $r) {
                $tDr+=(float)$r['debit']; $tCr+=(float)$r['credit'];
                $labels = $accountLabels[(int) $r['account_id']] ?? ['root_name'=>null, 'leaf_name'=>null];
                $name = $rowNames[$i] ?? '—';
                $entryName = in_array($r['ref_type'], ['advance','advance_recovery'], true) && $name !== '—'
                    ? $name : ($labels['leaf_name'] ?? '—');
                $rows[]=[$r['entry_date'], $r['shift_name'] ?: '—', $r['treasury'], $r['description'],
                    in_array($r['ref_type'], ['advance','advance_recovery'], true) ? 'سلف موظفين' : ($labels['root_name'] ?? '—'),
                    $entryName, $name, $r['cost_branch_name'] ?? '—',
                    number_format((float)$r['debit'],2), number_format((float)$r['credit'],2),
                    number_format((float)$r['running_balance'],2), $r['by_name'] ?? '—',
                    (string)($r['notes'] ?? '')];
                $rowPaths[]=je_transfer_path($r['transfer_from_name'] ?? null, $r['transfer_to_name'] ?? null);
            }
            $priorBalance = null;
            $balanceWarning = '';
            if ($treasuryFilter !== null && !$singleFullTreasury) {
                $balanceWarning = 'الرصيد السابق والختامي بيظهروا لما تختار خزينة واحدة من غير فلتر قيد أو فرع؛ الرصيد المحفوظ يخص كل حركات الخزينة.';
            }
            if ($singleFullTreasury) {
                    $priorBalance = treasury_prior_balance($pdo, $treasuryFilter, $from, $showCancelled);
                    array_unshift($rows, [$from, '—', $data[0]['treasury'] ?? '—', 'رصيد سابق', '—', '—', '—', '—', '0.00', '0.00', number_format($priorBalance, 2), '—', '']);
                    array_unshift($rowPaths, '');
                    if ($data) {
                        $lastBalance = (float) $data[count($data) - 1]['running_balance'];
                        $gap = round($priorBalance + $tCr - $tDr - $lastBalance, 2);
                        if ($gap != 0.0) {
                            $edits = array_values(array_filter($data, static fn($r) =>
                                $r['ref_type'] === 'date_fix_rev' || !empty($r['edited_at'])));
                            $ids = array_map(static fn($r) => '#' . $r['id'], $edits);
                            $balanceWarning = 'فرق تاريخي في الرصيد: ' . number_format($gap, 2)
                                . '. قيود بتاريخ متعدّل: ' . ($ids ? implode('، ', $ids) : 'مفيش قيد ظاهر متعلّم بتعديل تاريخ؛ محتاج مراجعة');
                        }
                    }
            }
            $closingBalance = $data ? (float) $data[count($data) - 1]['running_balance'] : $priorBalance;
            // notes_col = رقم عمود الملاحظات. الجدول بيقصّه بـ ellipsis
            // ويحط النص الكامل في tooltip، والتصدير بياخده كامل من $rows.
            return ['title'=>'تقرير الخزينة',
                'headers'=>['التاريخ','رقم الوردية','الخزينة','البيان','رأس القيد','القيد','الاسم','الفرع','مدين','دائن','الرصيد','بواسطة','الملاحظات'],
                'rows'=>$rows,'row_paths'=>$rowPaths,'notes_col'=>12,'balance_warning'=>$balanceWarning,
                'single_full_treasury'=>$singleFullTreasury,
                // الصافي = مدين − دائن (مش العكس). القيمة السالبة معناها
                // دخل أكتر من اللي خرج.
                'sum_cards'=>[
                    ['label'=>'إجمالي مدين', 'col'=>8],
                    ['label'=>'إجمالي دائن', 'col'=>9],
                    ['label'=>'الصافي',      'cols'=>[8], 'minus'=>[9], 'signed'=>true],
                ],
                'totals'=>['','','','','','','','الإجماليات',number_format($tDr,2),number_format($tCr,2),$singleFullTreasury && $closingBalance !== null ? number_format($closingBalance,2) : '','','']];

        case 'custody':
            // فلتر الفرع: أزرار فوق التقرير. الفترة فلتر مستقل تماماً —
            // الزرار بيبدّل الفرع بس والتاريخ بيفضل زي ما هو، والنتيجة
            // بتعتمد على الاتنين مع بعض.
            $cbId = isset($_GET['branch']) && $_GET['branch'] !== '' ? (int)$_GET['branch'] : null;

            if ($cbId !== null) {
                $showCancelled = ($_GET['show_cancelled'] ?? '') === '1';
                // ===== كشف حساب خزينة عهدة الفرع =====
                // نفس شكل كشف الحساب اللي في موديول الخزينة بالظبط.
                // بيقرا من journal_entries على خزينة العهدة بتاعة الفرع.
                $bn = $pdo->prepare('SELECT name FROM branches WHERE id = ?');
                $bn->execute([$cbId]);
                $branchName = (string) ($bn->fetchColumn() ?: '');

                $tr = $pdo->prepare(
                    "SELECT id FROM treasuries
                      WHERE type='branch_custody' AND branch_id = ? ORDER BY id LIMIT 1"
                );
                $tr->execute([$cbId]);
                $trId = $tr->fetchColumn();

                if (!$trId) {
                    return ['title'=>'كشف عهدة: '.$branchName,
                        'headers'=>['التاريخ','رقم الوردية','البيان','رأس القيد','القيد','الاسم','الفرع','مدين','دائن','الرصيد','بواسطة','الملاحظات'],
                        'rows'=>[], 'totals'=>[], 'notes_col'=>11,
                        'note'=>'الفرع ده مالوش خزينة عهدة.'];
                }

                $st = $pdo->prepare(
                    "SELECT je.entry_date, je.ref_type, je.ref_id, sh.shift_name, je.description, je.debit, je.credit,
                            je.running_balance, je.notes,
                            u.full_name AS by_name,
                            COALESCE(je.account_id, tm.entry_code_id) AS account_id,
                            tfrom.name AS transfer_from_name,
                            tto.name AS transfer_to_name
                       FROM journal_entries je
                       LEFT JOIN shifts sh ON sh.id = je.ref_id AND je.ref_type = 'shift'
                       LEFT JOIN users u ON u.id = je.created_by
                       LEFT JOIN treasury_movements tm
                              ON tm.id = je.ref_id
                             AND je.ref_type IN ('receipt','disbursement','date_fix_rev','reversal')
                       LEFT JOIN accounts ec ON ec.id = tm.entry_code_id
                       LEFT JOIN accounts ja ON ja.id = je.account_id
                       LEFT JOIN treasury_transfers tt ON tt.id = je.ref_id AND je.ref_type = 'transfer'
                       LEFT JOIN treasuries tfrom ON tfrom.id = tt.from_treasury_id
                       LEFT JOIN treasuries tto   ON tto.id   = tt.to_treasury_id
                      WHERE je.treasury_id = ?
                        AND je.entry_date BETWEEN ? AND ?
                        " . ($showCancelled ? '' : "AND je.ref_type <> 'custody_expense_del'
                            AND NOT (je.ref_type = 'custody_expense' AND EXISTS (
                                SELECT 1 FROM journal_entries rev
                                WHERE rev.ref_type = 'custody_expense_del' AND rev.ref_id = je.ref_id
                            ))") . "
                      ORDER BY je.entry_date, je.id"
                );
                $st->execute([(int)$trId, $from, $to]);
                $data = $st->fetchAll(PDO::FETCH_ASSOC);
                $accountLabels = rep_account_labels(array_column($data, 'account_id'));
                $rowNames = rep_row_names($pdo, $data);

                $rows = []; $rowPaths = []; $tDr = 0; $tCr = 0;
                foreach ($data as $i => $r) {
                    $tDr += (float)$r['debit'];
                    $tCr += (float)$r['credit'];
                    $labels = $accountLabels[(int) $r['account_id']] ?? ['root_name'=>null, 'leaf_name'=>null];
                    $name = $rowNames[$i] ?? '—';
                    $entryName = in_array($r['ref_type'], ['advance','advance_recovery'], true) && $name !== '—'
                        ? $name : ($labels['leaf_name'] ?? '—');
                    $rows[] = [$r['entry_date'], $r['shift_name'] ?: '—', $r['description'],
                        in_array($r['ref_type'], ['advance','advance_recovery'], true) ? 'سلف موظفين' : ($labels['root_name'] ?? '—'),
                        $entryName, $name, $branchName ?: '—',
                        (float)$r['debit']  > 0 ? number_format((float)$r['debit'],2)  : '—',
                        (float)$r['credit'] > 0 ? number_format((float)$r['credit'],2) : '—',
                        number_format((float)$r['running_balance'],2),
                        $r['by_name'] ?? '—',
                        (string)($r['notes'] ?? '')];
                    $rowPaths[] = je_transfer_path($r['transfer_from_name'] ?? null, $r['transfer_to_name'] ?? null);
                }
                $priorBalance = treasury_prior_balance($pdo, (int)$trId, $from, $showCancelled);
                array_unshift($rows, [$from, '—', 'رصيد سابق', '—', '—', '—', '—', '0.00', '0.00',
                    number_format($priorBalance, 2), '—', '']);
                array_unshift($rowPaths, '');
                $lastBalance = $data ? (float)$data[count($data)-1]['running_balance'] : $priorBalance;
                $gap = round($priorBalance + $tCr - $tDr - $lastBalance, 2);
                // المصروف = مدين (خارج من العهدة) · المستلم = دائن (داخل).
                // الكروت بتتحسب من نفس الصفوف الظاهرة، فبتتغيّر مع
                // التاريخ والفرع وأي فلتر على الجدول.
                return ['title'=>'كشف عهدة: '.$branchName,
                    'headers'=>['التاريخ','رقم الوردية','البيان','رأس القيد','القيد','الاسم','الفرع','مدين','دائن','الرصيد','بواسطة','الملاحظات'],
                    'rows'=>$rows, 'row_paths'=>$rowPaths, 'notes_col'=>11,
                    'balance_warning'=>$gap != 0.0 ? 'فرق تاريخي في رصيد العهدة: ' . number_format($gap, 2) . '؛ محتاج مراجعة.' : '',
                    'sum_cards'=>[
                        ['label'=>'إجمالي المصروف', 'col'=>7],
                        ['label'=>'إجمالي المستلم', 'col'=>8],
                    ],
                    'totals'=>['','','','','','','إجمالي:','مدين '.number_format($tDr,2),
                               'دائن '.number_format($tCr,2),number_format($lastBalance,2),'','']];
            }

            // مصروفات وسلف عهد الموظفين والفروع مجمّعة حسب الفرع المنسوب للسطر.
            // فترات العهدة القديمة لسه متاحة باختيار view=periods.
            if (($_GET['view'] ?? '') !== 'periods') {
                $costBranchId = isset($_GET['cost_branch']) && $_GET['cost_branch'] !== '' ? (int) $_GET['cost_branch'] : null;
                $sql = "SELECT COALESCE(cl.branch_id, bc.branch_id) AS branch_id,
                               COALESCE(b.name, '—') AS branch_name,
                               COUNT(*) AS line_count,
                               SUM(CASE WHEN cl.line_type = 'expense' THEN cl.amount ELSE 0 END) AS expenses,
                               SUM(CASE WHEN cl.line_type = 'advance' THEN cl.amount ELSE 0 END) AS advances,
                               SUM(CASE WHEN cl.line_type = 'supplier' THEN cl.amount ELSE 0 END) AS suppliers,
                               SUM(cl.amount) AS total_amount
                        FROM custody_lines cl
                        JOIN branch_custody bc ON bc.id = cl.custody_id
                        LEFT JOIN branches b ON b.id = COALESCE(cl.branch_id, bc.branch_id)
                        WHERE cl.expense_date BETWEEN ? AND ?";
                $params = [$from, $to];
                if ($costBranchId !== null) {
                    $sql .= ' AND COALESCE(cl.branch_id, bc.branch_id) = ?';
                    $params[] = $costBranchId;
                }
                $sql .= ' GROUP BY COALESCE(cl.branch_id, bc.branch_id), b.name ORDER BY b.name';
                $st = $pdo->prepare($sql);
                $st->execute($params);
                $rows = [];
                $counts = 0; $expenses = 0.0; $advances = 0.0; $suppliers = 0.0; $total = 0.0;
                foreach ($st as $r) {
                    $counts += (int) $r['line_count'];
                    $expenses += (float) $r['expenses'];
                    $advances += (float) $r['advances'];
                    $suppliers += (float) $r['suppliers'];
                    $total += (float) $r['total_amount'];
                    $rows[] = [$r['branch_name'], (int) $r['line_count'],
                        number_format((float) $r['expenses'], 2), number_format((float) $r['advances'], 2),
                        number_format((float) $r['suppliers'], 2), number_format((float) $r['total_amount'], 2)];
                }
                return ['title'=>'مصروفات العهد حسب الفرع',
                    'headers'=>['الفرع','عدد السطور','مصروفات','سلف','دفعات موردين','الإجمالي'],
                    'rows'=>$rows,
                    'sum_cards'=>[['label'=>'إجمالي مصروفات العهد','col'=>5]],
                    'totals'=>['الإجمالي', $counts, number_format($expenses, 2), number_format($advances, 2),
                        number_format($suppliers, 2), number_format($total, 2)]];
            }

            // فترات العهدة لكل الفروع (الشكل القديم).
            $st = $pdo->prepare(
                "SELECT bc.period_start, bc.period_end, b.name AS branch, bc.opening_balance,
                        bc.total_expenses, bc.closing_balance, bc.counted_cash, bc.difference, bc.status
                 FROM branch_custody bc JOIN branches b ON b.id=bc.branch_id
                 WHERE bc.period_start BETWEEN ? AND ? OR (bc.period_end BETWEEN ? AND ?)
                 ORDER BY bc.id DESC"
            );
            $st->execute([$from, $to, $from, $to]);
            $rows=[];
            foreach ($st as $r) {
                $rows[]=[$r['period_start'], $r['period_end'] ?? '—', $r['branch'],
                    number_format((float)$r['opening_balance'],2), number_format((float)$r['total_expenses'],2),
                    number_format((float)$r['closing_balance'],2),
                    $r['counted_cash']!==null?number_format((float)$r['counted_cash'],2):'—',
                    $r['difference']!==null?number_format((float)$r['difference'],2):'—',
                    $r['status']==='open'?'مفتوحة':'مقفلة'];
            }
            return ['title'=>'تقرير العهد','headers'=>['من','إلى','الفرع','أول المدة','المصروفات','آخر المدة','المعدود','الفرق','الحالة'],
                'rows'=>$rows,'totals'=>[]];

        case 'expenses':
            // المصاريف = كل صرف نقدي من الخزائن في الفترة.
            // (`treasury_movements.movement_type='disbursement'`)
            // التجميع في الداتابيز، صف لكل حركة + كروت فوق.
            $branchFilter   = isset($_GET['cost_branch']) && $_GET['cost_branch'] !== '' ? (int)$_GET['cost_branch'] : (isset($_GET['branch']) && $_GET['branch'] !== '' ? (int)$_GET['branch'] : null);
            $treasuryFilter = isset($_GET['treasury']) && $_GET['treasury'] !== '' ? (int)$_GET['treasury'] : (isset($_GET['treasury_id']) && $_GET['treasury_id'] !== '' ? (int)$_GET['treasury_id'] : null);
            $entryFilter    = isset($_GET['entry_code']) && $_GET['entry_code'] !== '' ? (int)$_GET['entry_code'] : null;
            $userFilter     = isset($_GET['user']) && $_GET['user'] !== '' ? (int)$_GET['user'] : (isset($_GET['created_by']) && $_GET['created_by'] !== '' ? (int)$_GET['created_by'] : null);

            $hasRevCols = treasury_movements_has_reverse_cols($pdo);
            $revSelect = $hasRevCols ? ', tm.reversed_at' : ', NULL AS reversed_at';
            $sqlExp =
                "SELECT tm.movement_date, t.name AS treasury, tm.amount,
                        tm.entry_code_id AS account_id,
                        cb.name AS cost_branch, u.full_name AS by_name,
                        tm.description, tm.notes{$revSelect}
                   FROM treasury_movements tm
                   JOIN treasuries t  ON t.id = tm.treasury_id
                   LEFT JOIN accounts a  ON a.id = tm.entry_code_id
                   LEFT JOIN branches cb ON cb.id = tm.branch_id
                   LEFT JOIN users u     ON u.id = tm.created_by
                  WHERE tm.movement_type = 'disbursement'
                    AND tm.movement_date BETWEEN ? AND ?";
            $paramsExp = [$from, $to];
            if ($branchFilter !== null) {
                $sqlExp .= " AND tm.branch_id = ?";
                $paramsExp[] = $branchFilter;
            }
            if ($treasuryFilter !== null) {
                $sqlExp .= " AND tm.treasury_id = ?";
                $paramsExp[] = $treasuryFilter;
            }
            if ($entryFilter !== null) {
                $familyIds = rep_account_family_ids($entryFilter);
                $placeholders = implode(',', array_fill(0, count($familyIds), '?'));
                $sqlExp .= " AND tm.entry_code_id IN ($placeholders)";
                foreach ($familyIds as $fid) {
                    $paramsExp[] = $fid;
                }
            }
            if ($userFilter !== null) {
                $sqlExp .= " AND tm.created_by = ?";
                $paramsExp[] = $userFilter;
            }
            $sqlExp .= " ORDER BY tm.movement_date DESC, tm.id DESC";
            $st = $pdo->prepare($sqlExp);
            $st->execute($paramsExp);
            $data = $st->fetchAll(PDO::FETCH_ASSOC);
            $accountLabels = rep_account_labels(array_column($data, 'account_id'));
            $rowNames = rep_row_names($pdo, $data);

            $rows = []; $reversedRows = []; $tExp = 0.0;
            foreach ($data as $i => $r) {
                $isRev = !empty($r['reversed_at']);
                if (!$isRev) {
                    $tExp += (float) $r['amount'];
                } else {
                    $reversedRows[$i] = true;
                }
                $desc = (string) ($r['description'] ?: '—');
                if ($isRev) {
                    $desc .= ' (اتعكس)';
                }
                $labels = $accountLabels[(int) $r['account_id']] ?? ['root_name'=>null, 'leaf_name'=>null];
                $rows[] = [$r['movement_date'], $labels['root_name'] ?? '—', $labels['leaf_name'] ?? '—',
                    $rowNames[$i] ?? '—',
                    $desc, $r['treasury'],
                    $r['cost_branch'] ?? '—',
                    number_format((float)$r['amount'], 2),
                    $r['by_name'] ?? '—', (string)($r['notes'] ?? '')];
            }
            return ['title'=>'تقرير المصاريف',
                'headers'=>['التاريخ','رأس القيد','القيد','الاسم','البيان','الخزينة','الفرع','المبلغ','بواسطة','الملاحظات'],
                'rows'=>$rows, 'notes_col'=>9,
                'reversed_rows'=>$reversedRows,
                'sum_cards'=>[
                    ['label'=>'إجمالي المصاريف', 'col'=>7],
                    ['label'=>'عدد الحركات',     'col'=>7, 'count'=>true],
                ],
                'totals'=>['','','','','','','إجمالي:', number_format($tExp,2),'','']];

        case 'jtotals':
            // إجماليات القيود — مجمّعة على مستوى الحساب (القيد) + الفرع.
            // التجميع في الداتابيز مش في الـPHP: صف واحد لكل (قيد + فرع).
            // الفرع بيتجاب بـ COALESCE على مصادر الحركة الممكنة (نفس منطق تقرير الخزينة).
            $st = $pdo->prepare(
                "SELECT COALESCE(ja.id, ec.id, 0) AS acc_id,
                        COALESCE(ja.name, ec.name, '— بدون قيد —') AS entry_name,
                        COALESCE(tm.branch_id, sh.branch_id, bc.branch_id, cl.branch_id, bc2.branch_id, dor.branch_id, 0) AS branch_id,
                        cb.name AS branch_name,
                        COUNT(*)          AS cnt,
                        SUM(je.debit)     AS dr,
                        SUM(je.credit)    AS cr
                   FROM journal_entries je
                   LEFT JOIN treasury_movements tm
                          ON tm.id = je.ref_id
                         AND je.ref_type IN ('receipt','disbursement','date_fix_rev','reversal')
                   LEFT JOIN accounts ec ON ec.id = tm.entry_code_id
                   LEFT JOIN accounts ja ON ja.id = je.account_id
                   LEFT JOIN shifts sh ON sh.id = je.ref_id AND je.ref_type = 'shift'
                   LEFT JOIN branch_custody bc ON bc.id = je.ref_id
                        AND je.ref_type IN ('custody_return','custody_settlement','custody_surplus','custody_deficit')
                   LEFT JOIN custody_lines cl ON cl.id = je.ref_id AND je.ref_type = 'custody_expense'
                   LEFT JOIN branch_custody bc2 ON bc2.id = cl.custody_id
                   LEFT JOIN disbursement_orders dor ON dor.id = je.ref_id AND je.ref_type = 'disbursement_order'
                   LEFT JOIN branches cb ON cb.id = COALESCE(tm.branch_id, sh.branch_id, bc.branch_id, cl.branch_id, bc2.branch_id, dor.branch_id)
                  WHERE je.entry_date BETWEEN ? AND ?
                  GROUP BY acc_id, entry_name, branch_id, branch_name
                  ORDER BY (SUM(je.debit) + SUM(je.credit)) DESC"
            );
            $st->execute([$from, $to]);
            $data = $st->fetchAll(PDO::FETCH_ASSOC);
            $accountLabels = rep_account_labels(array_column($data, 'acc_id'));

            $rows = []; $rowIds = []; $tDr = 0; $tCr = 0; $tCnt = 0;
            foreach ($data as $r) {
                $dr = (float)$r['dr']; $cr = (float)$r['cr'];
                $tDr += $dr; $tCr += $cr; $tCnt += (int)$r['cnt'];
                // عمود «إجمالي الحركة» (مدين+دائن) اتشال — ماكانش بيقول
                // حاجة مفيدة، ومجموع المدين والدائن مع بعض رقم بلا معنى
                // محاسبي. الصافي = مدين − دائن (اتصلّح، كان العكس).
                $labels = $accountLabels[(int) $r['acc_id']] ?? ['root_name'=>null, 'leaf_name'=>null];
                $rows[]   = [$labels['root_name'] ?? '—', $labels['leaf_name'] ?? '—',
                    $r['branch_name'] ?? '—', (int)$r['cnt'],
                    number_format($dr,2), number_format($cr,2),
                    number_format($dr - $cr, 2)];
                // الـid الحقيقي للحساب والفرع للأكورديون
                $rowIds[] = ['acc' => (int)$r['acc_id'], 'branch' => (int)$r['branch_id']];
            }
            return ['title'=>'إجماليات القيود',
                'headers'=>['رأس القيد','القيد','الفرع','عدد القيود','مدين','دائن','الصافي'],
                'rows'=>$rows, 'row_ids'=>$rowIds, 'accordion'=>'je_details',
                'sum_cards'=>[
                    ['label'=>'إجمالي مدين', 'col'=>4],
                    ['label'=>'إجمالي دائن', 'col'=>5],
                    ['label'=>'الصافي',      'col'=>6, 'signed'=>true],
                    ['label'=>'عدد القيود',  'col'=>3, 'int'=>true],
                ],
                'totals'=>['إجمالي:', '', '', $tCnt, number_format($tDr,2), number_format($tCr,2),
                           number_format($tDr-$tCr,2)]];

        case 'deficits':
            // تقرير العجوزات — يجمع فروق الورديات (عجز وزيادة) وعجز العهد بعتبة التسامح
            // فروق الورديات: s.difference <> 0 (عجز لو سالب، زيادة لو موجب)
            // عجز العهدة: مسجل في custody_deficits (معمولة أصلاً بعد تطبيق العتبة عند الإقفال في close_custody)
            // تماسك الصف: اسم الكاشير من users (shifts.user_id) واسم الموظف من employees (custody_deficits.employee_id)
            $statusFilter = $_GET['status'] ?? ''; // 'within' أو 'exceeded' أو فارغ (الكل)
            $tolerance = custody_tolerance();

            $stMap = [
                'submitted'   => 'مستلمة',
                'approved'    => 'معتمدة',
                'rejected'    => 'مرفوضة',
                'resubmitted' => 'معاد إرسال',
                'pending'     => 'معلق',
                'recovered'   => 'مسترد',
                'waived'      => 'معفى',
            ];

            $st = $pdo->prepare(
                "SELECT s.business_date AS deficit_date,
                        b.name AS branch_name,
                        u.full_name AS person_name,
                        'shift' AS source_type,
                        s.id AS shift_id,
                        s.shift_name AS shift_name,
                        s.difference AS diff_amount,
                        s.status AS record_status,
                        s.id AS sort_id
                   FROM shifts s
                   LEFT JOIN branches b ON b.id = s.branch_id
                   LEFT JOIN users u ON u.id = s.user_id
                  WHERE s.difference <> 0
                    AND s.business_date BETWEEN ? AND ?

                   UNION ALL

                 SELECT DATE(cd.created_at) AS deficit_date,
                        b.name AS branch_name,
                        e.full_name AS person_name,
                        'custody_deficit' AS source_type,
                        NULL AS shift_id,
                        NULL AS shift_name,
                        cd.amount AS diff_amount,
                        cd.status AS record_status,
                        cd.id AS sort_id
                   FROM custody_deficits cd
                   LEFT JOIN branches b ON b.id = cd.branch_id
                   LEFT JOIN employees e ON e.id = cd.employee_id
                  WHERE DATE(cd.created_at) BETWEEN ? AND ?

                  ORDER BY deficit_date DESC, sort_id DESC"
            );
            $st->execute([$from, $to, $from, $to]);

            $rows = [];
            $tShortage = 0.0;
            $tOver     = 0.0;
            $tExceeded = 0.0;

            foreach ($st as $r) {
                $collectionStatus = $stMap[$r['record_status']] ?? ($r['record_status'] ?: '—');

                if ($r['source_type'] === 'custody_deficit') {
                    // صفوف العهدة: معمولة بعد تطبيق العتبة في save_custody_review()
                    // فحالتها دايماً «تجاوز العتبة» والمتجاوز = المبلغ نفسه.
                    // متطبّقش العتبة عليها تاني هنا.
                    // عجز العهدة اتسجل وقت إقفال العهدة بالعتبة اللي كانت موجودة ساعتها
                    // فبنعرض العتبة '—' عشان منعرضش عتبة النهاردة مع سجل قديم، والمتجاوز = المبلغ نفسه
                    $amount = (float) $r['diff_amount'];
                    $statusLabel = 'تجاوز العتبة';

                    if ($statusFilter === 'within') {
                        continue;
                    }

                    $movementType = 'عجز عهدة';
                    $shiftNo      = '—';
                    $shortageVal  = number_format($amount, 2);
                    $overVal      = '—';
                    $tolVal       = '—';
                    $exceededVal  = number_format($amount, 2);

                    $tShortage += $amount;
                    $tExceeded += $amount;
                } else {
                    // صفوف الوردية: نطبق دالة العتبة الموحدة shift_variance_status()
                    $diff = (float) $r['diff_amount'];
                    $vs = shift_variance_status($diff, $tolerance);

                    if ($statusFilter === 'within' && $vs['over_limit']) {
                        continue;
                    }
                    if ($statusFilter === 'exceeded' && !$vs['over_limit']) {
                        continue;
                    }

                    $movementType = $vs['kind'] === 'shortage' ? 'عجز وردية' : 'زيادة وردية';
                    $shiftNo      = ($r['shift_name'] !== null && $r['shift_name'] !== '') ? (string) $r['shift_name'] : '—';
                    $shortageVal  = $vs['kind'] === 'shortage' ? number_format($vs['amount'], 2) : '—';
                    $overVal      = $vs['kind'] === 'over' ? number_format($vs['amount'], 2) : '—';
                    $tolVal       = number_format($vs['tolerance'], 2);
                    $exceededVal  = number_format($vs['exceeded'], 2);
                    $statusLabel  = $vs['status_label'];

                    if ($vs['kind'] === 'shortage') {
                        $tShortage += $vs['amount'];
                    } elseif ($vs['kind'] === 'over') {
                        $tOver += $vs['amount'];
                    }
                    $tExceeded += $vs['exceeded'];
                }

                $rows[] = [
                    (string) $r['deficit_date'],
                    $shiftNo,
                    $r['branch_name'] ?? '—',
                    $r['person_name'] ?? '—',
                    $movementType,
                    $shortageVal,
                    $overVal,
                    $tolVal,
                    $exceededVal,
                    $statusLabel,
                    $collectionStatus,
                ];
            }

            return [
                'title'     => 'تقرير العجوزات',
                'headers'   => ['التاريخ', 'رقم الوردية', 'الفرع', 'الموظف', 'نوع الحركة', 'العجز', 'الزيادة', 'العتبة', 'اللي بيتخصم', 'الحالة', 'حالة التحصيل'],
                'rows'      => $rows,
                'sum_cards' => [
                    ['label' => 'إجمالي العجز',    'col' => 5],
                    ['label' => 'إجمالي الزيادة',   'col' => 6],
                    ['label' => 'إجمالي اللي بيتخصم', 'col' => 8],
                    ['label' => 'عدد الحالات',     'col' => 8, 'count' => true],
                ],
                'totals'    => ['', '', '', '', 'إجمالي:', number_format($tShortage, 2), number_format($tOver, 2), '', number_format($tExceeded, 2), '', ''],
            ];

        case 'suppliers':
            $st = $pdo->prepare(
                "SELECT si.invoice_date, s.name AS supplier, si.invoice_no, si.total_amount,
                        si.paid_amount, (si.total_amount-si.paid_amount) AS remaining, si.status
                 FROM supplier_invoices si JOIN suppliers s ON s.id=si.supplier_id
                 WHERE si.invoice_date BETWEEN ? AND ?
                 ORDER BY si.id DESC"
            );
            $st->execute([$from, $to]);
            $rows=[]; $tTotal=0; $tPaid=0; $tRem=0;
            $stMap=['unpaid'=>'مستحقة','partial'=>'جزئية','paid'=>'مدفوعة'];
            foreach ($st as $r) {
                $tTotal+=(float)$r['total_amount']; $tPaid+=(float)$r['paid_amount']; $tRem+=(float)$r['remaining'];
                $rows[]=[$r['invoice_date'], $r['supplier'], $r['invoice_no']??'—',
                    number_format((float)$r['total_amount'],2), number_format((float)$r['paid_amount'],2),
                    number_format((float)$r['remaining'],2), $stMap[$r['status']]??$r['status']];
            }
            return ['title'=>'تقرير الموردين','headers'=>['التاريخ','المورد','رقم','الإجمالي','المدفوع','المتبقي','الحالة'],
                'rows'=>$rows,'totals'=>['','','إجمالي:',number_format($tTotal,2),number_format($tPaid,2),number_format($tRem,2),'']];

        case 'salaries':
            $st = $pdo->prepare(
                "SELECT s.id, s.salary_month, s.period_from, s.period_to, s.work_hours,
                        s.base_salary, s.total_bonuses, s.total_advances, s.total_penalties,
                        s.total_deficits, s.total_cashier_deficits, s.net_salary, s.status, s.paid_at,
                        e.full_name,
                        b.name AS branch_name,
                        GROUP_CONCAT(DISTINCT t.name ORDER BY je.id DESC SEPARATOR '، ') AS treasury_name
                 FROM salaries s
                 JOIN employees e ON e.id = s.employee_id
                 LEFT JOIN branches b ON b.id = e.branch_id
                 LEFT JOIN journal_entries je ON je.ref_type = 'salary' AND je.ref_id = s.id
                 LEFT JOIN treasuries t ON t.id = je.treasury_id
                 WHERE s.paid_at >= ? AND s.paid_at < DATE_ADD(?, INTERVAL 1 DAY)
                   AND s.reversed_at IS NULL
                 GROUP BY s.id
                 ORDER BY s.id DESC"
            );
            $st->execute([$from, $to]);
            $rows = [];
            $details = [];
            $tNet = 0;
            $treasuriesList = [];

            foreach ($st as $r) {
                $net = (float) $r['net_salary'];
                $tNet += $net;
                $tName = $r['treasury_name'] ? $r['treasury_name'] : '—';
                if (!empty($r['treasury_name'])) {
                    $parts = explode('، ', $r['treasury_name']);
                    foreach ($parts as $p) {
                        $p = trim($p);
                        if ($p !== '' && $p !== '—' && !in_array($p, $treasuriesList, true)) {
                            $treasuriesList[] = $p;
                        }
                    }
                }

                $bName = $r['branch_name'] ?: '—';
                $statusLabel = ($r['status'] === 'paid') ? 'مدفوع' : 'مسودة';

                // صف التصدير لإكسل (يحتوي على عمود الخزينة وباقي الحسابات)
                $rows[] = [
                    $r['salary_month'] ?? '—',
                    $r['full_name'],
                    $bName,
                    $tName,
                    number_format((float)$r['base_salary'], 2),
                    number_format((float)$r['total_bonuses'], 2),
                    number_format((float)$r['total_advances'], 2),
                    number_format((float)$r['total_penalties'], 2),
                    number_format((float)$r['total_deficits'], 2),
                    number_format((float)$r['total_cashier_deficits'], 2),
                    number_format($net, 2),
                    $statusLabel,
                ];

                // بيانات واجهة القائمة والتفصيل
                $base      = (float)$r['base_salary'];
                $bonuses   = (float)$r['total_bonuses'];
                $advances  = (float)$r['total_advances'];
                $penalties = (float)$r['total_penalties'];
                $deficits  = (float)$r['total_deficits'];
                $cDeficits = (float)$r['total_cashier_deficits'];

                $entitlement = $base + $bonuses;
                $deductions  = $advances + $penalties + $deficits + $cDeficits;

                $pFromFmt = $r['period_from'] ? date('j/n', strtotime($r['period_from'])) : '';
                $pToFmt   = $r['period_to']   ? date('j/n', strtotime($r['period_to'])) : '';
                $periodStr = ($pFromFmt && $pToFmt) ? ($pFromFmt . ' ← ' . $pToFmt) : ($r['salary_month'] ?? '');
                $hoursStr  = ($r['work_hours'] !== null && (float)$r['work_hours'] > 0) ? (number_format((float)$r['work_hours'], 2) . ' ساعة') : '';

                $metaParts = array_filter([$bName !== '—' ? $bName : '', $periodStr, $hoursStr]);
                $subLine = implode(' · ', $metaParts);

                $details[] = [
                    'id'                     => (int)$r['id'],
                    'full_name'              => $r['full_name'],
                    'net_salary'             => $net,
                    'net_salary_fmt'         => number_format($net, 2),
                    'status'                 => $r['status'],
                    'status_label'           => $statusLabel,
                    'branch_name'            => $bName,
                    'treasury_name'          => $tName,
                    'sub_line'               => $subLine,
                    'base_salary'            => $base,
                    'total_bonuses'          => $bonuses,
                    'total_entitlement'      => $entitlement,
                    'total_advances'         => $advances,
                    'total_penalties'        => $penalties,
                    'total_deficits'         => $deficits,
                    'total_cashier_deficits' => $cDeficits,
                    'total_deductions'       => $deductions,
                    'entitlement_fmt'        => number_format($entitlement, 2),
                    'bonuses_fmt'            => number_format($bonuses, 2),
                    'advances_fmt'           => number_format($advances, 2),
                    'penalties_fmt'          => number_format($penalties, 2),
                    'deficits_fmt'           => number_format($deficits, 2),
                    'cdeficits_fmt'          => number_format($cDeficits, 2),
                    'deductions_fmt'         => number_format($deductions, 2),
                ];
            }

            return [
                'title'      => 'تقرير الرواتب',
                'layout'     => 'master_detail',
                'headers'    => ['الشهر', 'الموظف', 'الفرع', 'خزينة الصرف', 'الأساسي', 'مكافآت', 'سلف', 'جزاءات', 'عجز عهدة', 'عجز كاشير', 'الصافي', 'الحالة'],
                'rows'       => $rows,
                'totals'     => ['إجمالي:', '', '', '', '', '', '', '', '', '', number_format($tNet, 2), ''],
                'details'    => $details,
                'treasuries' => $treasuriesList,
                'count'      => count($details),
                'total_net'  => $tNet,
            ];

        case 'advances':
            $st = $pdo->prepare(
                "SELECT a.id, a.employee_id, a.amount, a.advance_date, a.status,
                        a.notes, a.monthly_deduction,
                        COALESCE(a.remaining, a.amount) AS remaining_amount,
                        a.adv_type,
                        e.full_name,
                        b.name AS branch_name
                 FROM employee_advances a
                 JOIN employees e ON e.id = a.employee_id
                 LEFT JOIN branches b ON b.id = e.branch_id
                 WHERE a.advance_date BETWEEN ? AND ?
                 ORDER BY e.full_name ASC, a.advance_date DESC, a.id DESC"
            );
            $st->execute([$from, $to]);

            $typeMap = [
                'monthly'  => 'سلفة شهر',
                'standing' => 'مستديمة',
            ];
            $statusMap = [
                'pending'   => 'معلّقة',
                'deducted'  => 'اتخصمت',
                'cancelled' => 'ملغاة',
            ];

            $rows = [];
            $employees = [];
            $branchesList = [];
            $tAmount = 0;
            $tRemaining = 0;

            foreach ($st as $r) {
                $empId = (int)$r['employee_id'];
                $amount = (float)$r['amount'];
                $remaining = (float)$r['remaining_amount'];
                $monthlyDed = (float)$r['monthly_deduction'];
                $bName = $r['branch_name'] ? $r['branch_name'] : '—';
                $tName = $typeMap[$r['adv_type']] ?? $r['adv_type'];
                $sName = $statusMap[$r['status']] ?? $r['status'];

                // 🔴 المتبقي بيتجمع من **المعلّقة بس**. السلفة اللي اتخصمت
                //    أو اتلغت مالهاش متبقي على الموظف — لو جمعناها، التقرير
                //    هيقول إن موظف سدّد سلفته بالكامل لسه عليه فلوس.
                //    (`remaining` بتفضل بقيمتها في الصفوف الملغاة.)
                $isOwed = ($r['status'] === 'pending');

                $tAmount += $amount;
                if ($isOwed) $tRemaining += $remaining;

                if ($bName !== '—' && !in_array($bName, $branchesList, true)) {
                    $branchesList[] = $bName;
                }

                // صف التصدير لإكسل
                $rows[] = [
                    $r['advance_date'],
                    $r['full_name'],
                    $bName,
                    $tName,
                    number_format($amount, 2),
                    number_format($remaining, 2),
                    number_format($monthlyDed, 2),
                    $sName,
                    (string)($r['notes'] ?? ''),
                ];

                if (!isset($employees[$empId])) {
                    $employees[$empId] = [
                        'id'                  => $empId,
                        'full_name'           => $r['full_name'],
                        'branch_name'         => $bName,
                        'total_remaining'     => 0.0,
                        'total_remaining_fmt' => '0.00',
                        'pending_count'       => 0,
                        'advances'            => [],
                    ];
                }

                // نفس القاعدة: المعلّقة بس هي اللي عليه
                if ($isOwed) {
                    $employees[$empId]['total_remaining'] += $remaining;
                    $employees[$empId]['pending_count']++;
                }

                $employees[$empId]['advances'][] = [
                    'id'                    => (int)$r['id'],
                    'advance_date'          => $r['advance_date'],
                    'adv_type'              => $r['adv_type'],
                    'type_label'            => $tName,
                    'amount'                => $amount,
                    'amount_fmt'            => number_format($amount, 2),
                    'remaining'             => $remaining,
                    'remaining_fmt'         => number_format($remaining, 2),
                    'monthly_deduction'     => $monthlyDed,
                    'monthly_deduction_fmt' => number_format($monthlyDed, 2),
                    'status'                => $r['status'],
                    'status_label'          => $sName,
                    'notes'                 => (string)($r['notes'] ?? ''),
                ];
            }

            foreach ($employees as &$emp) {
                $emp['total_remaining_fmt'] = number_format($emp['total_remaining'], 2);
            }
            unset($emp);

            $details = array_values($employees);

            return [
                'title'           => 'تقرير السلف',
                'layout'          => 'master_detail',
                'report_subtype'  => 'advances',
                'headers'         => ['التاريخ', 'الموظف', 'الفرع', 'النوع', 'المبلغ الأصلي', 'المتبقي', 'القسط الشهري', 'الحالة', 'الملاحظات'],
                'rows'            => $rows,
                'totals'          => ['إجمالي:', '', '', '', number_format($tAmount, 2), number_format($tRemaining, 2), '', '', ''],
                'details'         => $details,
                'branches'        => $branchesList,
                'count'           => count($details),
                'total_remaining' => $tRemaining,
            ];

        case 'attendance':
            $st = $pdo->prepare(
                "SELECT a.att_date, e.full_name, a.check_in, a.check_out, a.work_hours, a.status
                 FROM attendance a JOIN employees e ON e.id=a.employee_id
                 WHERE a.att_date BETWEEN ? AND ?
                 ORDER BY a.att_date DESC, e.full_name"
            );
            $st->execute([$from, $to]);
            $rows=[]; $tMin=0;
            $stMap=['present'=>'حاضر','absent'=>'غائب','leave'=>'إجازة','holiday'=>'عطلة'];
            foreach ($st as $r) {
                $mins = (int) round((float)$r['work_hours']*60);
                $tMin += $mins;
                $rows[]=[$r['att_date'], $r['full_name'],
                    $r['check_in']?substr($r['check_in'],0,5):'—',
                    $r['check_out']?substr($r['check_out'],0,5):'—',
                    minutes_to_hhmm($mins), $stMap[$r['status']]??$r['status']];
            }
            return ['title'=>'تقرير الحضور','headers'=>['التاريخ','الموظف','حضور','انصراف','ساعات العمل','الحالة'],
                'rows'=>$rows,'totals'=>['','','','','إجمالي: '.minutes_to_hhmm($tMin),'']];

        case 'attendance_totals':
            $st = $pdo->prepare(
                "SELECT e.full_name, b.name AS branch_name,
                        COUNT(CASE WHEN a.work_hours > 0 THEN 1 END) AS attend_days,
                        COALESCE(SUM(a.work_hours), 0) AS total_hours
                 FROM attendance a
                 JOIN employees e ON e.id = a.employee_id
                 LEFT JOIN branches b ON b.id = e.branch_id
                 WHERE a.att_date BETWEEN ? AND ?
                 GROUP BY e.id, e.full_name, b.name
                 HAVING attend_days > 0
                 ORDER BY e.full_name"
            );
            $st->execute([$from, $to]);
            $rows = []; $tDays = 0; $tMin = 0;
            foreach ($st as $r) {
                $days = (int) $r['attend_days'];
                $mins = (int) round((float) $r['total_hours'] * 60);
                $tDays += $days;
                $tMin += $mins;
                $rows[] = [
                    $r['full_name'],
                    !empty($r['branch_name']) ? $r['branch_name'] : '—',
                    $days,
                    minutes_to_hhmm($mins),
                ];
            }
            return ['title' => 'تقرير إجمالي الحضور', 'headers' => ['الموظف', 'الفرع', 'أيام الحضور', 'إجمالي الساعات'],
                'rows' => $rows, 'totals' => ['إجمالي:', '', $tDays, minutes_to_hhmm($tMin)]];

        case 'receiving':
            // تقرير استلام الفواتير: مين استلم ومين اعتمد
            $branchFilter = isset($_GET['branch']) && $_GET['branch'] !== '' ? (int)$_GET['branch'] : null;
            $statusFilter = isset($_GET['status']) && $_GET['status'] !== '' ? trim($_GET['status']) : null;

            $supervisorId = (int) setting('receiving_supervisor_id', '0');
            $supervisorName = '—';
            if ($supervisorId > 0) {
                $supStmt = $pdo->prepare("SELECT full_name FROM users WHERE id=?");
                $supStmt->execute([$supervisorId]);
                $supervisorName = $supStmt->fetchColumn() ?: '—';
            }

            $sqlRec =
                "SELECT r.id AS receipt_id, r.group_id, r.distribution_id, r.status,
                        r.created_at, r.started_at, r.received_at, r.approved_at,
                        b.name AS branch_name,
                        d.invoice_no, s.name AS supplier_name,
                        g.name AS group_name,
                        u_start.full_name AS started_by_name,
                        u_rec.full_name AS received_by_name,
                        u_app.full_name AS approved_by_name
                 FROM receipts r
                 JOIN branches b ON b.id = r.branch_id
                 LEFT JOIN invoice_distribution d ON d.id = r.distribution_id
                 LEFT JOIN suppliers s ON s.id = d.supplier_id
                 LEFT JOIN invoice_groups g ON g.id = r.group_id
                 LEFT JOIN users u_start ON u_start.id = r.started_by
                 LEFT JOIN users u_rec ON u_rec.id = r.received_by
                 LEFT JOIN users u_app ON u_app.id = r.approved_by
                 WHERE (r.created_at BETWEEN ? AND ? OR r.received_at BETWEEN ? AND ? OR r.approved_at BETWEEN ? AND ?)";
            $paramsRec = [
                $from . ' 00:00:00', $to . ' 23:59:59',
                $from . ' 00:00:00', $to . ' 23:59:59',
                $from . ' 00:00:00', $to . ' 23:59:59',
            ];
            if ($branchFilter !== null) {
                $sqlRec .= " AND r.branch_id = ?";
                $paramsRec[] = $branchFilter;
            }
            if ($statusFilter !== null) {
                $sqlRec .= " AND r.status = ?";
                $paramsRec[] = $statusFilter;
            }
            $sqlRec .= " ORDER BY r.id DESC";

            $st = $pdo->prepare($sqlRec);
            $st->execute($paramsRec);

            $statusMap = [
                'sent'      => 'موزعة (بانتظار البدء)',
                'receiving' => 'جاري الاستلام',
                'received'  => 'تم الاستلام (بانتظار الاعتماد)',
                'approved'  => 'معتمدة',
            ];

            $rows = [];
            $cntApproved = 0; $cntReceived = 0; $cntReceiving = 0; $cntSent = 0;
            foreach ($st as $r) {
                $docLabel = '';
                if (!empty($r['invoice_no'])) {
                    $docLabel = 'فاتورة #' . $r['invoice_no'];
                } elseif (!empty($r['group_name'])) {
                    $docLabel = 'مجموعة: ' . $r['group_name'];
                } else {
                    $docLabel = 'إذن #' . $r['receipt_id'];
                }

                $stVal = (string)$r['status'];
                if ($stVal === 'approved') $cntApproved++;
                elseif ($stVal === 'received') $cntReceived++;
                elseif ($stVal === 'receiving') $cntReceiving++;
                else $cntSent++;

                // مدة الاستلام = من لما الموظف دوس «ابدأ» لحد ما دوس «خلصت».
                // الاتنين من نفس المصدر (نفس الصف ونفس السيرفر)، فالفرق بينهم
                // مابيتأثرش بفرق التوقيت — حتى لو العرض نفسه متزحلق ساعة.
                // ملاحظة: لو المحاسب رفض الاستلام، الحالة بترجع receiving من غير ما
                // started_at تتصفّر، ولما الفرع يخلّص تاني بتتسجل received_at جديدة،
                // فبتتحسب المدة الكلية شاملة وقت الرفض وإعادة الاستلام.
                $durTxt = '—';
                if (!empty($r['started_at']) && !empty($r['received_at'])) {
                    $secs = strtotime($r['received_at']) - strtotime($r['started_at']);
                    // سالب = مستحيل منطقياً، بس ممكن يحصل لو حد عدّل في
                    // الداتابيز بإيده. بنوريه '—' بدل ما نطلّع '-03:20'.
                    $durTxt = $secs >= 0 ? minutes_to_hhmm((int) round($secs / 60)) : '—';
                }

                $rows[] = [
                    $docLabel,
                    $r['supplier_name'] ?: '—',
                    $r['branch_name'],
                    $statusMap[$stVal] ?? $stVal,
                    $r['created_at'] ? date('Y-m-d H:i', strtotime($r['created_at'])) : '—',
                    $r['started_by_name'] ?: '—',
                    $r['started_at'] ? date('Y-m-d H:i', strtotime($r['started_at'])) : '—',
                    $r['received_by_name'] ?: '—',
                    $r['received_at'] ? date('Y-m-d H:i', strtotime($r['received_at'])) : '—',
                    $durTxt,
                    $r['approved_by_name'] ?: '—',
                    $r['approved_at'] ? date('Y-m-d H:i', strtotime($r['approved_at'])) : '—',
                ];
            }

            $recHeaders = [
                'المستند / الفاتورة',
                'المورد',
                'الفرع',
                'الحالة',
                'وصلت الفرع',
                'بدء الاستلام بواسطة',
                'تاريخ البدء',
                'المستلم (مراجعة الكميات)',
                'تاريخ الاستلام',
                'مدة الاستلام',
                'المعتمد (اعتماد الكميات)',
                'تاريخ الاعتماد',
            ];

            // عدد الخانات لازم يساوي عدد العناوين بالظبط — في الشاشة وفي
            // الإكسل. عشان كده بنبنيه من count($recHeaders) مش بالعد بالإيد.
            $recTotals = array_fill(0, count($recHeaders), '');
            $recTotals[0] = 'إجمالي: ' . count($rows) . ' (معتمدة: ' . $cntApproved
                          . ' · مستلمة: ' . $cntReceived . ' · جاري: ' . $cntReceiving
                          . ' · جديدة: ' . $cntSent . ')';

            return [
                'title' => 'تقرير استلام الفواتير (مسؤول الاستلامات في الإعدادات: ' . $supervisorName . ')',
                'headers' => $recHeaders,
                'rows' => $rows,
                'sum_cards' => [
                    ['label' => 'إجمالي الإشعارات', 'count' => true],
                ],
                'totals' => $recTotals,
            ];

        default:
            return ['title'=>'','headers'=>[],'rows'=>[],'totals'=>[]];
    }
}


/**
 * بيانات تقرير داتا العملاء (حجوزات الكتب).
 */
function rep_customers_data(PDO $pdo, string $from, string $to, int $selBranch = 0, string $search = ''): array
{
    $custReady = true;
    $custMsg = '';
    $custBranches = [];
    $custBranchName = '';
    $custRows = [];

    try {
        $pdo->query("SELECT 1 FROM book_reservations LIMIT 1");
    } catch (Throwable $e) {
        $custReady = false;
        $custMsg = 'جدول الحجوزات مش موجود';
    }

    if ($custReady) {
        try {
            $custBranches = $pdo->query("SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name")->fetchAll();
        } catch (Throwable $e) {
            $custBranches = [];
        }

        $fromTs = $from . ' 00:00:00';
        $toTs   = $to . ' 23:59:59';
        $search = trim($search);

        if ($selBranch > 0) {
            foreach ($custBranches as $cb) {
                if ((int)$cb['id'] === $selBranch) {
                    $custBranchName = $cb['name'];
                    break;
                }
            }
        }

        $branchSql = $selBranch > 0 ? " AND r.branch_id = ?" : "";

        if ($search === '') {
            $sql = "SELECT
                        latest.customer_name,
                        CASE
                            WHEN latest.customer_phone IS NOT NULL AND TRIM(latest.customer_phone) != ''
                            THEN TRIM(latest.customer_phone)
                            ELSE ''
                        END AS customer_phone,
                        grp.branches,
                        grp.reservations_count,
                        latest.created_at AS last_reservation_date
                    FROM (
                        SELECT
                            CASE
                                WHEN r.customer_phone IS NOT NULL AND TRIM(r.customer_phone) != ''
                                THEN TRIM(r.customer_phone)
                                ELSE CONCAT('__NAME__', TRIM(COALESCE(r.customer_name, 'بدون اسم')))
                            END AS cust_key,
                            MAX(r.id) AS max_id,
                            COUNT(*) AS reservations_count,
                            GROUP_CONCAT(DISTINCT COALESCE(b.name, '—') ORDER BY b.name SEPARATOR '، ') AS branches
                        FROM book_reservations r
                        LEFT JOIN branches b ON b.id = r.branch_id
                        WHERE r.created_at >= ? AND r.created_at <= ? $branchSql
                        GROUP BY cust_key
                    ) grp
                    JOIN book_reservations latest ON latest.id = grp.max_id
                    ORDER BY latest.id DESC";

            $args = [$fromTs, $toTs];
            if ($selBranch > 0) $args[] = $selBranch;
        } else {
            $searchLike = '%' . $search . '%';
            $sql = "SELECT
                        latest.customer_name,
                        CASE
                            WHEN latest.customer_phone IS NOT NULL AND TRIM(latest.customer_phone) != ''
                            THEN TRIM(latest.customer_phone)
                            ELSE ''
                        END AS customer_phone,
                        grp.branches,
                        grp.reservations_count,
                        latest.created_at AS last_reservation_date
                    FROM (
                        SELECT
                            CASE
                                WHEN r.customer_phone IS NOT NULL AND TRIM(r.customer_phone) != ''
                                THEN TRIM(r.customer_phone)
                                ELSE CONCAT('__NAME__', TRIM(COALESCE(r.customer_name, 'بدون اسم')))
                            END AS cust_key,
                            MAX(r.id) AS max_id,
                            COUNT(*) AS reservations_count,
                            GROUP_CONCAT(DISTINCT COALESCE(b.name, '—') ORDER BY b.name SEPARATOR '، ') AS branches,
                            MAX(CASE WHEN r.customer_name LIKE ? OR r.customer_phone LIKE ? THEN 1 ELSE 0 END) AS name_matched
                        FROM book_reservations r
                        LEFT JOIN branches b ON b.id = r.branch_id
                        WHERE r.created_at >= ? AND r.created_at <= ? $branchSql
                        GROUP BY cust_key
                    ) grp
                    JOIN book_reservations latest ON latest.id = grp.max_id
                    WHERE (latest.customer_name LIKE ? OR latest.customer_phone LIKE ? OR grp.name_matched = 1)
                    ORDER BY latest.id DESC";

            $args = [$searchLike, $searchLike, $fromTs, $toTs];
            if ($selBranch > 0) $args[] = $selBranch;
            $args[] = $searchLike;
            $args[] = $searchLike;
        }

        try {
            $st = $pdo->prepare($sql);
            $st->execute($args);
            $custRows = $st->fetchAll();
        } catch (Throwable $e) {
            $custRows = [];
        }
    }

    return [
        'title'         => 'داتا العملاء' . ($custBranchName !== '' ? ' — ' . $custBranchName : ''),
        'ready'         => $custReady,
        'msg'           => $custMsg,
        'branches'      => $custBranches,
        'branch_name'   => $custBranchName,
        'rows'          => $custRows,
        'totals'        => ['count' => count($custRows)],
    ];
}

/**
 * بيانات تقرير أرشيف الجرد (للقراءة فقط).
 */
function rep_stocktake_data(?int $selSession = 0, ?array $selSheets = null): array
{
    require_once __DIR__ . '/stocktake_lib.php';
    $stkSessions = stk_all_sessions();
    $stkSheets = [];
    $stkLines = [];
    $stkSelSheets = [];

    $selSession = (int) ($selSession ?? 0);
    if ($selSession > 0) {
        $stkSheets = stk_session_sheets($selSession);
        if ($selSheets !== null && is_array($selSheets)) {
            $stkSelSheets = array_map('intval', $selSheets);
        } else {
            $stkSelSheets = array_map(fn($s) => (int)$s['id'], $stkSheets);
        }
        if (!empty($stkSelSheets)) {
            $stkLines = stk_lines_for_sheets($stkSelSheets);
        }
    }

    return [
        'title'         => 'أرشيف الجرد (للقراءة فقط)',
        'sessions'      => $stkSessions,
        'session_id'    => $selSession,
        'sheets'        => $stkSheets,
        'sel_sheets'    => $stkSelSheets,
        'lines'         => $stkLines,
        'rows'          => $stkLines,
        'totals'        => ['count' => count($stkLines)],
    ];
}
