<?php
/**
 * reports.php — التقارير
 * نظام الفجالة ستور المالي
 *
 * تقارير: المبيعات | الخزينة | العهد | الموردين | الرواتب | الحضور
 * - كل تقرير بفلتر فترة (من/إلى).
 * - تصدير CSV لأي تقرير.
 */
require_once __DIR__ . '/auth.php';
require_permission('reports');
require_once __DIR__ . '/finance.php';

$pdo = db();

$type = $_GET['type'] ?? 'sales';
$from = $_GET['from'] ?? date('Y-m-01');           // أول الشهر افتراضياً
$to   = $_GET['to']   ?? date('Y-m-d');
if (!strtotime($from)) $from = date('Y-m-01');
if (!strtotime($to))   $to   = date('Y-m-d');
$export = isset($_GET['export']);

// =====================================================================
//  AJAX — تقرير «أصناف بدون سعر بيع»
//  كله محلي على hq_codes + item_price_drafts، مفيش نداء للجسر.
//  كل عملية كتابة = استعلام واحد مجمّع (batch)، مش صف-بصف.
// =====================================================================
// =====================================================================
//  AJAX — تفاصيل القيد (أكورديون تقرير إجماليات القيود)
//  بيتنادى قبل أي طباعة. منفصل عن بلوك np_* اللي تحت لأن ده بيتطلّب
//  جدول مسوّدات الأسعار وده مالوش علاقة بيه.
//  بيجيب حركات حساب واحد في الفترة، بالـid الحقيقي مش بالاسم.
// =====================================================================
if (($_GET['ajax'] ?? '') === 'je_details') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $accId = (int) ($_GET['acc'] ?? 0);          // 0 = «بدون قيد»
        $dFrom = $_GET['from'] ?? date('Y-m-01');
        $dTo   = $_GET['to']   ?? date('Y-m-d');
        if (!strtotime($dFrom) || !strtotime($dTo)) {
            throw new RuntimeException('تاريخ غير صحيح.');
        }

        $accCond = $accId > 0
            ? 'COALESCE(ja.id, ec.id, 0) = ?'
            : 'COALESCE(ja.id, ec.id, 0) = 0';

        $branchCond = '';
        $branchArg  = null;
        if (isset($_GET['branch']) && $_GET['branch'] !== '') {
            $bVal = (int) $_GET['branch'];
            if ($bVal > 0) {
                $branchCond = ' AND COALESCE(tm.branch_id, sh.branch_id, bc.branch_id, bc2.branch_id, dor.branch_id, 0) = ?';
                $branchArg  = $bVal;
            } else {
                $branchCond = ' AND COALESCE(tm.branch_id, sh.branch_id, bc.branch_id, bc2.branch_id, dor.branch_id, 0) = 0';
            }
        }

        $args = [$dFrom, $dTo];
        if ($accId > 0) $args[] = $accId;
        if ($branchArg !== null) $args[] = $branchArg;

        $st = $pdo->prepare(
            "SELECT je.id, je.entry_date, je.description, je.debit, je.credit,
                    je.running_balance, je.notes, je.ref_type,
                    t.name AS treasury, u.full_name AS by_name,
                    cb.name AS branch_name
               FROM journal_entries je
               JOIN treasuries t ON t.id = je.treasury_id
               LEFT JOIN users u ON u.id = je.created_by
               LEFT JOIN treasury_movements tm
                      ON tm.id = je.ref_id AND je.ref_type IN ('receipt','disbursement')
               LEFT JOIN accounts ec ON ec.id = tm.entry_code_id
               LEFT JOIN accounts ja ON ja.id = je.account_id
               LEFT JOIN shifts sh ON sh.id = je.ref_id AND je.ref_type = 'shift'
               LEFT JOIN branch_custody bc ON bc.id = je.ref_id
                    AND je.ref_type IN ('custody_return','custody_settlement','custody_surplus','custody_deficit')
               LEFT JOIN custody_lines cl ON cl.id = je.ref_id AND je.ref_type = 'custody_expense'
               LEFT JOIN branch_custody bc2 ON bc2.id = cl.custody_id
               LEFT JOIN disbursement_orders dor ON dor.id = je.ref_id AND je.ref_type = 'disbursement_order'
               LEFT JOIN branches cb ON cb.id = COALESCE(tm.branch_id, sh.branch_id, bc.branch_id, bc2.branch_id, dor.branch_id)
              WHERE je.entry_date BETWEEN ? AND ? AND $accCond $branchCond
              ORDER BY je.entry_date, je.id
              LIMIT 500"
        );
        $st->execute($args);

        $kinds = ['receipt'=>'استلام','disbursement'=>'صرف','transfer'=>'تحويل',
                  'shift'=>'تقفيل وردية','custody'=>'عهدة','advance'=>'سلفة'];
        $out = [];
        foreach ($st as $r) {
            $out[] = [
                'id'       => (int) $r['id'],
                'date'     => $r['entry_date'],
                'desc'     => (string) $r['description'],
                'kind'     => $kinds[$r['ref_type']] ?? '—',
                'treasury' => (string) $r['treasury'],
                'branch'   => (string) ($r['branch_name'] ?? '—'),
                'debit'    => (float) $r['debit'],
                'credit'   => (float) $r['credit'],
                'balance'  => (float) $r['running_balance'],
                'by'       => (string) ($r['by_name'] ?? '—'),
                'notes'    => (string) ($r['notes'] ?? ''),
            ];
        }
        echo json_encode(['ok' => true, 'rows' => $out], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$ajax = $_GET['ajax'] ?? '';
if ($ajax !== '') {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/price_draft_lib.php';
    $uid = current_user()['id'] ?? null;

    /** قراءة جسم الطلب JSON + فحص التوكن (للعمليات اللي بتكتب) */
    $npBody = static function (): array {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new RuntimeException('طلب غير صالح.');
        }
        $b = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($b)) throw new RuntimeException('بيانات غير صالحة.');
        if (!csrf_verify((string) ($b['csrf'] ?? ''))) {
            throw new RuntimeException('انتهت صلاحية الجلسة — اعمل ريفريش وحاول تاني.');
        }
        return $b;
    };

    try {
        if (!pdft_ready()) {
            throw new RuntimeException('جدول الأسعار لسه ما اتعملش — شغّل upgrade_price_drafts.sql.');
        }

        switch ($ajax) {
            // صفوف التقرير (بعد أي عملية — عشان الواجهة تتجدد من مصدر واحد)
            case 'np_rows':
                echo json_encode(['ok' => true, 'rows' => pdft_rows()], JSON_UNESCAPED_UNICODE);
                break;

            // بحث بالكود أو الاسم (للإضافة اليدوية)
            case 'np_search':
                echo json_encode([
                    'ok'   => true,
                    'rows' => pdft_search((string) ($_GET['term'] ?? ''), 25),
                ], JSON_UNESCAPED_UNICODE);
                break;

            // نافذة «أصناف سعرها صفر» — بصفحات
            case 'np_zero':
                $res = pdft_zero_price((string) ($_GET['term'] ?? ''), (int) ($_GET['page'] ?? 1));
                echo json_encode(['ok' => true] + $res, JSON_UNESCAPED_UNICODE);
                break;

            // «تحديد الكل» — بيرجّع الأكواد بس (خفيفة)
            case 'np_zero_all':
                $codes = pdft_zero_price_codes((string) ($_GET['term'] ?? ''));
                echo json_encode(['ok' => true, 'codes' => $codes], JSON_UNESCAPED_UNICODE);
                break;

            // إضافة أصناف للتقرير
            case 'np_add':
                $b = $npBody();
                $n = pdft_add((array) ($b['codes'] ?? []), $uid);
                echo json_encode(['ok' => true, 'added' => $n, 'rows' => pdft_rows()], JSON_UNESCAPED_UNICODE);
                break;

            // شيل أصناف
            case 'np_remove':
                $b = $npBody();
                $n = pdft_remove((array) ($b['codes'] ?? []));
                echo json_encode(['ok' => true, 'removed' => $n], JSON_UNESCAPED_UNICODE);
                break;

            // تفريغ التقرير
            case 'np_clear':
                $npBody();
                echo json_encode(['ok' => true, 'removed' => pdft_clear()], JSON_UNESCAPED_UNICODE);
                break;

            // حفظ سعر صنف واحد
            case 'np_price':
                $b = $npBody();
                $code  = trim((string) ($b['code'] ?? ''));
                $price = (float) ($b['price'] ?? 0);
                $pct   = isset($b['pct']) && $b['pct'] !== '' && $b['pct'] !== null
                    ? (float) $b['pct'] : null;
                if ($code === '') throw new RuntimeException('كود ناقص.');
                if ($price < 0)   throw new RuntimeException('السعر مايصحّش يكون بالسالب.');
                pdft_set_price($code, $price, $pct, $uid);
                echo json_encode(['ok' => true, 'price' => pdft_round($price)], JSON_UNESCAPED_UNICODE);
                break;

            // نسبة موحّدة على كل الصفوف — استعلام واحد
            case 'np_markup':
                $b = $npBody();
                $pct = (float) ($b['pct'] ?? 0);
                if ($pct < -100) throw new RuntimeException('النسبة غير منطقية.');
                $res = pdft_apply_markup($pct, $uid);
                echo json_encode(['ok' => true] + $res + ['rows' => pdft_rows()], JSON_UNESCAPED_UNICODE);
                break;

            default:
                throw new RuntimeException('عملية غير معروفة.');
        }
    } catch (Throwable $ex) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/**
 * بيانات كل تقرير: بترجّع ['title','headers'=>[],'rows'=>[[]],'totals'=>[]]
 */
function build_report(PDO $pdo, string $type, string $from, string $to): array
{
    switch ($type) {
        case 'sales':
            // فلتر اختياري بالفرع (أزرار الفروع فوق الجدول)
            $branchFilter = isset($_GET['branch']) && $_GET['branch'] !== '' ? (int)$_GET['branch'] : null;
            $sqlSales =
                "SELECT s.business_date, b.name AS branch, u.full_name AS cashier,
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
                $rows[] = [$r['business_date'], $r['branch'], $r['cashier'],
                    number_format((float)$r['cash_total'],2), number_format((float)$r['visa_total'],2),
                    number_format((float)$r['instapay_total'],2),
                    number_format((float)$r['system_total'],2), number_format((float)$r['difference'],2),
                    $stMap[$r['status']] ?? $r['status']];
            }
            return ['title'=>'تقرير المبيعات','headers'=>['التاريخ','الفرع','الكاشير','كاش','فيزا','إنستا','المحصّل','الفرق','الحالة'],
                'rows'=>$rows,
                // الترتيب مقصود: المحصل · الكاش · الفيزا · الإنستا ·
                // الفيزا+الإنستا · الفرق — نفس ترتيب شاشة تقفيل الكاشير.
                // كارت الفيزا+الإنستا بيجمع عمودين (cols) مش عمود واحد.
                'sum_cards'=>[
                    ['label'=>'المحصل',        'col'=>6],
                    ['label'=>'الكاش',         'col'=>3],
                    ['label'=>'الفيزا',        'col'=>4],
                    ['label'=>'الإنستا',       'col'=>5],
                    ['label'=>'الفيزا + الإنستا','cols'=>[4,5]],
                    ['label'=>'الفرق',         'col'=>7, 'signed'=>true],
                ],
                'totals'=>['','','','','','','إجمالي محصّل: '.number_format($tCollected,2),'فرق: '.number_format($tDiff,2),'']];

        case 'treasury':
            // فلتر اختياري بالقيد
            $entryFilter = isset($_GET['entry_code']) && $_GET['entry_code'] !== '' ? (int)$_GET['entry_code'] : null;
            // فلتر اختياري بفرع التكلفة
            $branchFilter = isset($_GET['cost_branch']) && $_GET['cost_branch'] !== '' ? (int)$_GET['cost_branch'] : null;
            $sqlT =
                "SELECT je.entry_date, t.name AS treasury, je.description, je.debit, je.credit, je.running_balance,
                        u.full_name AS by_name,
                        COALESCE(ja.name, ec.name) AS entry_code_name,
                        je.notes,
                        cb.name AS cost_branch_name
                 FROM journal_entries je JOIN treasuries t ON t.id=je.treasury_id
                 LEFT JOIN users u ON u.id=je.created_by
                 LEFT JOIN treasury_movements tm ON tm.id = je.ref_id AND je.ref_type IN ('receipt','disbursement')
                 LEFT JOIN accounts ec ON ec.id = tm.entry_code_id
                 LEFT JOIN accounts ja ON ja.id = je.account_id
                 LEFT JOIN shifts sh ON sh.id = je.ref_id AND je.ref_type = 'shift'
                 LEFT JOIN branch_custody bc ON bc.id = je.ref_id
                      AND je.ref_type IN ('custody_return','custody_settlement','custody_surplus','custody_deficit')
                 LEFT JOIN custody_lines cl ON cl.id = je.ref_id AND je.ref_type = 'custody_expense'
                 LEFT JOIN branch_custody bc2 ON bc2.id = cl.custody_id
                 LEFT JOIN disbursement_orders dor ON dor.id = je.ref_id AND je.ref_type = 'disbursement_order'
                 LEFT JOIN branches cb ON cb.id = COALESCE(tm.branch_id, sh.branch_id, bc.branch_id, bc2.branch_id, dor.branch_id)
                 WHERE je.entry_date BETWEEN ? AND ?";
            $paramsT = [$from, $to];
            if ($entryFilter !== null) {
                // COALESCE مش tm.entry_code_id لوحده: القيد ممكن يكون
                // متسجّل على القيد نفسه (je.account_id) — زي القيود اللي
                // مش صرف/استلام، واللي اتحدّد قيدها من بوب أب التعديل.
                // العرض بقى COALESCE فالفلتر لازم يبقى نفسه، وإلا يفلتر
                // صفوف بتبان في الشاشة بقيد صح.
                $sqlT .= " AND COALESCE(je.account_id, tm.entry_code_id) = ?";
                $paramsT[] = $entryFilter;
            }
            if ($branchFilter !== null) {
                $sqlT .= " AND COALESCE(tm.branch_id, sh.branch_id, bc.branch_id, bc2.branch_id, dor.branch_id) = ?";
                $paramsT[] = $branchFilter;
            }
            $sqlT .= " ORDER BY je.id DESC";
            $st = $pdo->prepare($sqlT);
            $st->execute($paramsT);
            $rows=[]; $tDr=0; $tCr=0;
            foreach ($st as $r) {
                $tDr+=(float)$r['debit']; $tCr+=(float)$r['credit'];
                $rows[]=[$r['entry_date'], $r['treasury'], $r['description'], $r['entry_code_name'] ?? '—',
                    $r['cost_branch_name'] ?? '—',
                    number_format((float)$r['debit'],2), number_format((float)$r['credit'],2),
                    number_format((float)$r['running_balance'],2), $r['by_name'] ?? '—',
                    (string)($r['notes'] ?? '')];
            }
            // notes_col = رقم عمود الملاحظات. الجدول بيقصّه بـ ellipsis
            // ويحط النص الكامل في tooltip، والتصدير بياخده كامل من $rows.
            return ['title'=>'تقرير الخزينة',
                'headers'=>['التاريخ','الخزينة','البيان','القيد','الفرع','مدين','دائن','الرصيد','بواسطة','الملاحظات'],
                'rows'=>$rows,'notes_col'=>9,
                // الصافي = مدين − دائن (مش العكس). القيمة السالبة معناها
                // دخل أكتر من اللي خرج.
                'sum_cards'=>[
                    ['label'=>'إجمالي مدين', 'col'=>5],
                    ['label'=>'إجمالي دائن', 'col'=>6],
                    ['label'=>'الصافي',      'cols'=>[5], 'minus'=>[6], 'signed'=>true],
                ],
                'totals'=>['','','','','إجمالي:','مدين '.number_format($tDr,2),'دائن '.number_format($tCr,2),'','','']];

        case 'custody':
            // فلتر الفرع: أزرار فوق التقرير. الفترة فلتر مستقل تماماً —
            // الزرار بيبدّل الفرع بس والتاريخ بيفضل زي ما هو، والنتيجة
            // بتعتمد على الاتنين مع بعض.
            $cbId = isset($_GET['branch']) && $_GET['branch'] !== '' ? (int)$_GET['branch'] : null;

            if ($cbId !== null) {
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
                        'headers'=>['التاريخ','البيان','القيد','مدين','دائن','الرصيد','بواسطة','الملاحظات'],
                        'rows'=>[], 'totals'=>[], 'notes_col'=>7,
                        'note'=>'الفرع ده مالوش خزينة عهدة.'];
                }

                $st = $pdo->prepare(
                    "SELECT je.entry_date, je.description, je.debit, je.credit,
                            je.running_balance, je.notes,
                            u.full_name AS by_name,
                            COALESCE(ja.name, ec.name) AS entry_code_name
                       FROM journal_entries je
                       LEFT JOIN users u ON u.id = je.created_by
                       LEFT JOIN treasury_movements tm
                              ON tm.id = je.ref_id
                             AND je.ref_type IN ('receipt','disbursement')
                       LEFT JOIN accounts ec ON ec.id = tm.entry_code_id
                       LEFT JOIN accounts ja ON ja.id = je.account_id
                      WHERE je.treasury_id = ?
                        AND je.entry_date BETWEEN ? AND ?
                      ORDER BY je.entry_date, je.id"
                );
                $st->execute([(int)$trId, $from, $to]);

                $rows = []; $tDr = 0; $tCr = 0;
                foreach ($st as $r) {
                    $tDr += (float)$r['debit'];
                    $tCr += (float)$r['credit'];
                    $rows[] = [$r['entry_date'], $r['description'],
                        $r['entry_code_name'] ?? '—',
                        (float)$r['debit']  > 0 ? number_format((float)$r['debit'],2)  : '—',
                        (float)$r['credit'] > 0 ? number_format((float)$r['credit'],2) : '—',
                        number_format((float)$r['running_balance'],2),
                        $r['by_name'] ?? '—',
                        (string)($r['notes'] ?? '')];
                }
                // المصروف = مدين (خارج من العهدة) · المستلم = دائن (داخل).
                // الكروت بتتحسب من نفس الصفوف الظاهرة، فبتتغيّر مع
                // التاريخ والفرع وأي فلتر على الجدول.
                return ['title'=>'كشف عهدة: '.$branchName,
                    'headers'=>['التاريخ','البيان','القيد','مدين','دائن','الرصيد','بواسطة','الملاحظات'],
                    'rows'=>$rows, 'notes_col'=>7,
                    'sum_cards'=>[
                        ['label'=>'إجمالي المصروف', 'col'=>3],
                        ['label'=>'إجمالي المستلم', 'col'=>4],
                    ],
                    'totals'=>['','','إجمالي:','مدين '.number_format($tDr,2),
                               'دائن '.number_format($tCr,2),'','','']];
            }

            // ===== الافتراضي: فترات العهدة لكل الفروع (زي ما كان) =====
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

            $sqlExp =
                "SELECT tm.movement_date, t.name AS treasury, tm.amount,
                        COALESCE(a.name, '— بدون قيد —') AS entry_name,
                        cb.name AS cost_branch, u.full_name AS by_name,
                        tm.description, tm.notes
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
                $sqlExp .= " AND tm.entry_code_id = ?";
                $paramsExp[] = $entryFilter;
            }
            if ($userFilter !== null) {
                $sqlExp .= " AND tm.created_by = ?";
                $paramsExp[] = $userFilter;
            }
            $sqlExp .= " ORDER BY tm.movement_date DESC, tm.id DESC";
            $st = $pdo->prepare($sqlExp);
            $st->execute($paramsExp);

            $rows = []; $tExp = 0.0;
            foreach ($st as $r) {
                $tExp += (float) $r['amount'];
                $rows[] = [$r['movement_date'], $r['entry_name'],
                    $r['description'] ?: '—', $r['treasury'],
                    $r['cost_branch'] ?? '—',
                    number_format((float)$r['amount'], 2),
                    $r['by_name'] ?? '—', (string)($r['notes'] ?? '')];
            }
            return ['title'=>'تقرير المصاريف',
                'headers'=>['التاريخ','القيد','البيان','الخزينة','الفرع','المبلغ','بواسطة','الملاحظات'],
                'rows'=>$rows, 'notes_col'=>7,
                'sum_cards'=>[
                    ['label'=>'إجمالي المصاريف', 'col'=>5],
                    ['label'=>'عدد الحركات',     'col'=>5, 'count'=>true],
                ],
                'totals'=>['','','','','إجمالي:', number_format($tExp,2),'','']];

        case 'jtotals':
            // إجماليات القيود — مجمّعة على مستوى الحساب (القيد) + الفرع.
            // التجميع في الداتابيز مش في الـPHP: صف واحد لكل (قيد + فرع).
            // الفرع بيتجاب بـ COALESCE على مصادر الحركة الممكنة (نفس منطق تقرير الخزينة).
            $st = $pdo->prepare(
                "SELECT COALESCE(ja.id, ec.id, 0) AS acc_id,
                        COALESCE(ja.name, ec.name, '— بدون قيد —') AS entry_name,
                        COALESCE(tm.branch_id, sh.branch_id, bc.branch_id, bc2.branch_id, dor.branch_id, 0) AS branch_id,
                        cb.name AS branch_name,
                        COUNT(*)          AS cnt,
                        SUM(je.debit)     AS dr,
                        SUM(je.credit)    AS cr
                   FROM journal_entries je
                   LEFT JOIN treasury_movements tm
                          ON tm.id = je.ref_id
                         AND je.ref_type IN ('receipt','disbursement')
                   LEFT JOIN accounts ec ON ec.id = tm.entry_code_id
                   LEFT JOIN accounts ja ON ja.id = je.account_id
                   LEFT JOIN shifts sh ON sh.id = je.ref_id AND je.ref_type = 'shift'
                   LEFT JOIN branch_custody bc ON bc.id = je.ref_id
                        AND je.ref_type IN ('custody_return','custody_settlement','custody_surplus','custody_deficit')
                   LEFT JOIN custody_lines cl ON cl.id = je.ref_id AND je.ref_type = 'custody_expense'
                   LEFT JOIN branch_custody bc2 ON bc2.id = cl.custody_id
                   LEFT JOIN disbursement_orders dor ON dor.id = je.ref_id AND je.ref_type = 'disbursement_order'
                   LEFT JOIN branches cb ON cb.id = COALESCE(tm.branch_id, sh.branch_id, bc.branch_id, bc2.branch_id, dor.branch_id)
                  WHERE je.entry_date BETWEEN ? AND ?
                  GROUP BY acc_id, entry_name, branch_id, branch_name
                  ORDER BY (SUM(je.debit) + SUM(je.credit)) DESC"
            );
            $st->execute([$from, $to]);

            $rows = []; $rowIds = []; $tDr = 0; $tCr = 0; $tCnt = 0;
            foreach ($st as $r) {
                $dr = (float)$r['dr']; $cr = (float)$r['cr'];
                $tDr += $dr; $tCr += $cr; $tCnt += (int)$r['cnt'];
                // عمود «إجمالي الحركة» (مدين+دائن) اتشال — ماكانش بيقول
                // حاجة مفيدة، ومجموع المدين والدائن مع بعض رقم بلا معنى
                // محاسبي. الصافي = مدين − دائن (اتصلّح، كان العكس).
                $rows[]   = [$r['entry_name'], $r['branch_name'] ?? '—', (int)$r['cnt'],
                    number_format($dr,2), number_format($cr,2),
                    number_format($dr - $cr, 2)];
                // الـid الحقيقي للحساب والفرع للأكورديون
                $rowIds[] = ['acc' => (int)$r['acc_id'], 'branch' => (int)$r['branch_id']];
            }
            return ['title'=>'إجماليات القيود',
                'headers'=>['القيد','الفرع','عدد القيود','مدين','دائن','الصافي'],
                'rows'=>$rows, 'row_ids'=>$rowIds, 'accordion'=>'je_details',
                'sum_cards'=>[
                    ['label'=>'إجمالي مدين', 'col'=>3],
                    ['label'=>'إجمالي دائن', 'col'=>4],
                    ['label'=>'الصافي',      'col'=>5, 'signed'=>true],
                    ['label'=>'عدد القيود',  'col'=>2, 'int'=>true],
                ],
                'totals'=>['إجمالي:', '', $tCnt, number_format($tDr,2), number_format($tCr,2),
                           number_format($tDr-$tCr,2)]];

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
                "SELECT s.salary_month, e.full_name, s.base_salary, s.total_advances,
                        s.total_penalties, s.total_deficits, s.net_salary, s.status
                 FROM salaries s JOIN employees e ON e.id=s.employee_id
                 WHERE s.paid_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY) OR s.salary_month BETWEEN ? AND ?
                 ORDER BY s.id DESC"
            );
            $st->execute([$from, $to, substr($from,0,7), substr($to,0,7)]);
            $rows=[]; $tNet=0;
            foreach ($st as $r) {
                $tNet+=(float)$r['net_salary'];
                $rows[]=[$r['salary_month'], $r['full_name'], number_format((float)$r['base_salary'],2),
                    number_format((float)$r['total_advances'],2), number_format((float)$r['total_penalties'],2),
                    number_format((float)$r['total_deficits'],2), number_format((float)$r['net_salary'],2),
                    $r['status']==='paid'?'مدفوع':'مسودة'];
            }
            return ['title'=>'تقرير الرواتب','headers'=>['الشهر','الموظف','الأساسي','سلف','جزاءات','عجز عهدة','الصافي','الحالة'],
                'rows'=>$rows,'totals'=>['','','','','','','إجمالي صافي: '.number_format($tNet,2),'']];

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

        default:
            return ['title'=>'','headers'=>[],'rows'=>[],'totals'=>[]];
    }
}

$report = build_report($pdo, $type, $from, $to);

// تصدير CSV (للتقارير العادية — الجرد له تصدير خاص بيه تحت)
if ($export && $type !== 'stocktake') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $type . '_' . $from . '_' . $to . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM للعربي في إكسل
    $out = fopen('php://output', 'w');
    fputcsv($out, $report['headers']);
    foreach ($report['rows'] as $row) fputcsv($out, $row);
    if (!empty($report['totals'])) fputcsv($out, $report['totals']);
    fclose($out);
    exit;
}

$reportTypes = [
    'sales'=>'المبيعات','treasury'=>'الخزينة','expenses'=>'المصاريف','jtotals'=>'إجماليات القيود','custody'=>'العهد',
    'suppliers'=>'الموردين','salaries'=>'الرواتب','attendance'=>'الحضور',
    'stocktake'=>'الجرد','noprice'=>'أصناف بدون سعر بيع',
];

// بيانات تقرير «أصناف بدون سعر بيع»
$npRows = []; $npReady = true; $npMsg = '';
if ($type === 'noprice') {
    require_once __DIR__ . '/price_draft_lib.php';
    if (!pdft_ready()) {
        $npReady = false;
        $npMsg = 'جدول الأسعار لسه ما اتعملش. شغّل ملف upgrade_price_drafts.sql على قاعدة البيانات الأول.';
    } elseif (!pdft_codes_ready()) {
        $npReady = false;
        $npMsg = 'مرآة الأصناف (hq_codes) فاضية. شغّل وكيل المزامنة الأول.';
    } else {
        $npRows = pdft_rows();
    }
}

// بيانات أرشيف الجرد (تاب الجرد - للقراءة فقط)
$stkSessions = []; $stkSheets = []; $stkLines = []; $stkSelSession = 0; $stkSelSheets = [];
if ($type === 'stocktake') {
    require_once __DIR__ . '/stocktake_lib.php';
    $stkSessions = stk_all_sessions();
    $stkSelSession = (int)($_GET['session'] ?? 0);
    if ($stkSelSession) {
        $stkSheets = stk_session_sheets($stkSelSession);
        // المحاضر المختارة: من sheets[] (الفورم) أو sheet_ids (نص مفصول بفواصل للتصدير)
        if (isset($_GET['sheets']) && is_array($_GET['sheets'])) {
            $stkSelSheets = array_map('intval', $_GET['sheets']);
        } elseif (!empty($_GET['sheet_ids'])) {
            $stkSelSheets = array_filter(array_map('intval', explode(',', (string)$_GET['sheet_ids'])));
        } else {
            $stkSelSheets = array_map(fn($s)=>(int)$s['id'], $stkSheets);
        }
        if ($stkSelSheets) $stkLines = stk_lines_for_sheets($stkSelSheets);
    }

    // تصدير أصناف الجرد (CSV أو Excel)
    if ($export && $stkLines) {
        if (ob_get_length()) ob_end_clean(); // امسح أي output سابق
        $fmt = $_GET['fmt'] ?? 'csv';
        $headers = ['المحضر','الكود','اسم الصنف','الوحدة','جرد الجارد','جرد المراجع','النهائية','رصيد السيستم','الفرق','سعر التكلفة','إجمالي التكلفة','القائم بالجرد','المراجع'];

        // مؤشرات الأعمدة (0-based) للتلوين
        $COL_GARD = 11;  // القائم بالجرد
        $COL_REV  = 12;  // المراجع

        // جهّز الصفوف + خريطة الألوان
        $NUM_COLS = count($headers);   // لتلوين السطر كامل
        $dataRows = [];
        $cellFills = [];
        foreach ($stkLines as $i => $l) {
            $base=(float)$l['base_qty'];                                  // كمية الجارد (بالأساسي)
            $rev=$l['review_qty']!==null?(float)$l['review_qty']:null;    // كمية المراجع
            $reviewed = ($l['sheet_status'] ?? '') === 'done' || $rev !== null || $l['final_qty']!==null;
            $fin=$l['final_qty']!==null?(float)$l['final_qty']:($rev!==null?$rev:$base);  // النهائية
            $sys=$l['system_qty']!==null?(float)$l['system_qty']:null;    // رصيد السيستم
            $hasSys = $sys!==null;
            $diff=$hasSys?$fin-$sys:null;
            $cost=$l['cost_price']!==null?(float)$l['cost_price']:null;
            $dataRows[] = [
                '#'.$l['sheet_no'], (string)$l['entered_code'], $l['item_name'], $l['unit'] ?? '',
                $base, $rev ?? '', $fin, $sys ?? '', $diff ?? '',
                $cost ?? '', $cost!==null?($fin*$cost):'', $l['counter_name'] ?? '', $l['reviewer_name'] ?? '',
            ];

            // ===== منطق الألوان =====
            // لسه ماتراجعش المحضر → سطر عادي (بدون تلوين مقارنة)
            if (!$reviewed) continue;

            // (أ) تحديد المطابقة للسطر كله:
            //     - لو فيه رصيد سيستم: مطابق يعني النهائية = السيستم (مفيش فرق مخزون).
            //     - لو مفيش رصيد سيستم: مطابق يعني الجارد والمراجع اتفقوا (النهائية = الجارد = المراجع).
            if ($hasSys) {
                $isMatch = abs($fin - $sys) < 0.0001;
            } else {
                $isMatch = ($rev!==null) && abs($base - $rev) < 0.0001 && abs($fin - $base) < 0.0001;
            }

            // (ب) تلوين السطر كامل: أخضر لو مطابق، أحمر لو غير مطابق
            $rowFill = $isMatch ? 'green' : 'red';
            for ($c = 0; $c < $NUM_COLS; $c++) {
                $cellFills[$i . ':' . $c] = $rowFill;
            }

            // (ج) تحديد المخطئ من الكمية النهائية (بس لو فيه اختلاف بين الجارد والمراجع)
            //     المطابق تمامًا مايبقاش عليه مخطئ.
            if ($rev !== null && abs($base - $rev) >= 0.0001) {
                $finEqBase = abs($fin - $base) < 0.0001;   // النهائية = الجارد؟ → المراجع غلط
                $finEqRev  = abs($fin - $rev)  < 0.0001;   // النهائية = المراجع؟ → الجارد غلط
                if ($finEqBase) {
                    $cellFills[$i . ':' . $COL_REV]  = 'redfill_redtext';   // المراجع مخطئ
                } elseif ($finEqRev) {
                    $cellFills[$i . ':' . $COL_GARD] = 'redfill_redtext';   // الجارد مخطئ
                } else {
                    // النهائية مخالفة للاتنين → الاتنين غلط
                    $cellFills[$i . ':' . $COL_GARD] = 'redfill_redtext';
                    $cellFills[$i . ':' . $COL_REV]  = 'redfill_redtext';
                }
            }
        }

        if ($fmt === 'xlsx') {
            // ===== ملف Excel حقيقي (.xlsx) — العمود B (الكود) نص + ألوان المخطئ =====
            stk_export_xlsx($headers, $dataRows, 'stocktake_session_' . $stkSelSession . '.xlsx', $cellFills);
            exit;
        }

        // ===== CSV — العمود B (الكود) بصيغة ="..." يجبر إكسل يقرأه نص =====
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="stocktake_session_' . $stkSelSession . '.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, $headers);
        foreach ($dataRows as $r) {
            // العمود B (index 1 = الكود) كنص
            $r[1] = '="' . $r[1] . '"';
            fputcsv($out, $r);
        }
        fclose($out);
        exit;
    }
}

$page_title    = 'التقارير';
$active_module = 'reports';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .rep-tabs { display:flex; gap:6px; margin-bottom:18px; flex-wrap:wrap; }
    .rep-tab { padding:9px 16px; border-radius:10px; text-decoration:none; color:var(--accent-hover); background:#fff;
        font-size:14px; font-weight:500; }
    .rep-tab.active { background:var(--accent); color:#fff; }
    .rep-tab:not(.active):hover { background:var(--bg); }
    .filter-bar { background:#fff; border-radius:12px; padding:16px; margin-bottom:18px;
        display:flex; gap:10px; align-items:center; flex-wrap:wrap; box-shadow:0 2px 10px rgba(124,111,247,0.06); }
    .filter-bar label { font-size:13px; color:var(--ink-500); }
    .filter-bar input { padding:9px 11px; border:1.5px solid var(--line); border-radius:9px; font-family:inherit; font-size:13px; }
    .btn { background:var(--accent); color:#fff; border:none; border-radius:9px; padding:10px 18px; cursor:pointer; font-family:inherit; font-size:14px; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
    .btn:hover { background:var(--accent-hover); }
    .btn-csv { background:var(--pos); } .btn-csv:hover { background:#15835f; }
    .card { background:#fff; border-radius:14px; padding:20px; box-shadow:0 2px 10px rgba(124,111,247,0.06); }
    .rep-title { font-size:17px; color:var(--ink-900); font-weight:700; margin-bottom:4px; }
    /* كروت الإجماليات — بتتحسب من الصفوف الظاهرة في الجدول */
    .sum-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(135px,1fr));
        gap:10px; margin:14px 0 4px; }
    .sum-card { background:var(--line-soft); border:1.5px solid var(--line);
        border-radius:12px; padding:12px 14px; }
    .sc-label { font-size:12px; color:var(--ink-500); margin-bottom:5px; }
    .sc-value { font-size:19px; font-weight:700; color:var(--ink-900); direction:ltr; text-align:right; }
    .sc-value.neg { color:var(--neg); }
    .sc-value.pos { color:var(--pos); }
    .sum-cards.filtered .sum-card { border-color:var(--accent); background:var(--accent-soft); }
    .sc-note { font-size:11px; color:var(--accent-hover); margin-top:4px; }
    /* أكورديون تفاصيل القيد */
    tr.acc-row { cursor:pointer; }
    tr.acc-row:hover { background:var(--bg); }
    tr.acc-row.open { background:var(--accent-soft); font-weight:600; }
    .acc-caret { display:inline-block; color:var(--accent); font-size:11px;
        transition:transform .15s; }
    tr.acc-row.open .acc-caret { transform:rotate(-90deg); }
    tr.acc-body > td { background:var(--line-soft); padding:0; }
    .acc-inner { padding:12px 16px; }
    .acc-inner table { width:100%; background:#fff; border-radius:9px; overflow:hidden; }
    .acc-inner th { background:var(--line-soft); font-size:12px; }
    .acc-inner td, .acc-inner th { padding:7px 10px; font-size:12.5px; }
    .acc-msg { padding:14px; text-align:center; color:var(--ink-400); font-size:13px; }
    /* أزرار الفروع في تقرير العهدة */
    .br-tabs { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px; }
    .br-tab { padding:8px 15px; border-radius:9px; background:#fff; border:1.5px solid var(--line);
        color:var(--ink-700); font-size:13px; text-decoration:none; }
    .br-tab:hover { border-color:var(--accent); }
    .br-tab.active { background:var(--accent); border-color:var(--accent); color:#fff; font-weight:600; }
    .rep-period { font-size:13px; color:var(--ink-500); margin-bottom:16px; }
    /* عمود الملاحظات — نص طويل بيتقص و tooltip بالنص الكامل.
       التصدير بياخد النص كامل (مش من الخلية). */
    td.rep-note { max-width:230px; overflow:hidden; text-overflow:ellipsis;
        white-space:nowrap; color:var(--ink-700); }
    table { width:100%; border-collapse:collapse; }
    th, td { padding:10px 12px; text-align:right; font-size:13px; border-bottom:1px solid var(--line); white-space:nowrap; }
    th { color:var(--ink-500); font-weight:600; }
    td { color:var(--ink-800); }
    .empty { text-align:center; color:#bbb; padding:40px; font-size:15px; }
</style>

<div class="rep-tabs">
    <?php foreach ($reportTypes as $k=>$label): ?>
        <a href="?type=<?= $k ?>&from=<?= e($from) ?>&to=<?= e($to) ?>"
           class="rep-tab <?= $type===$k?'active':'' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<?php if ($type !== 'stocktake' && $type !== 'noprice'): ?>
<form method="get" class="filter-bar">
    <input type="hidden" name="type" value="<?= e($type) ?>">
    <?php if (($type === 'custody' || $type === 'sales') && isset($_GET['branch']) && $_GET['branch'] !== ''): ?>
        <?php /* الفرع فلتر مستقل عن التاريخ: لازم يفضل معانا لما نغيّر
                 الفترة، وإلا التقرير كان هيرجع لكل الفروع كل مرة. */ ?>
        <input type="hidden" name="branch" value="<?= (int)$_GET['branch'] ?>">
    <?php endif; ?>
    <label>من</label>
    <input type="date" name="from" value="<?= e($from) ?>">
    <label>إلى</label>
    <input type="date" name="to" value="<?= e($to) ?>">
    <?php if ($type === 'treasury'): $repEntryCodes = entry_codes_list(); $selEntry = $_GET['entry_code'] ?? ''; ?>
        <label>القيد</label>
        <select name="entry_code" style="padding:9px 11px;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;font-size:13px;">
            <option value="">كل القيود</option>
            <?php foreach ($repEntryCodes as $ec): ?>
                <option value="<?= (int)$ec['id'] ?>" <?= (string)$selEntry===(string)$ec['id']?'selected':'' ?>><?= e($ec['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php $repCostBranches = cost_branches_list(); $selCostBranch = $_GET['cost_branch'] ?? ''; ?>
        <label>الفرع</label>
        <select name="cost_branch" style="padding:9px 11px;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;font-size:13px;">
            <option value="">كل الفروع</option>
            <?php foreach ($repCostBranches as $cb): ?>
                <option value="<?= (int)$cb['id'] ?>" <?= (string)$selCostBranch===(string)$cb['id']?'selected':'' ?>><?= e($cb['name']) ?></option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>
    <?php if ($type === 'expenses'):
        $repCostBranches = cost_branches_list();
        $selCostBranch   = $_GET['cost_branch'] ?? '';
        $repTreasuries   = $pdo->query("SELECT id, name FROM treasuries WHERE is_active = 1 ORDER BY name")->fetchAll();
        $selTreasury     = $_GET['treasury'] ?? '';
        $repEntryCodes   = entry_codes_list();
        $selEntry        = $_GET['entry_code'] ?? '';
        $repUsers        = $pdo->query("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name")->fetchAll();
        $selUser         = $_GET['user'] ?? '';
    ?>
        <label>الفرع</label>
        <select name="cost_branch" style="padding:9px 11px;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;font-size:13px;">
            <option value="">كل الفروع</option>
            <?php foreach ($repCostBranches as $cb): ?>
                <option value="<?= (int)$cb['id'] ?>" <?= (string)$selCostBranch===(string)$cb['id']?'selected':'' ?>><?= e($cb['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <label>الخزينة</label>
        <select name="treasury" style="padding:9px 11px;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;font-size:13px;">
            <option value="">كل الخزائن</option>
            <?php foreach ($repTreasuries as $tr): ?>
                <option value="<?= (int)$tr['id'] ?>" <?= (string)$selTreasury===(string)$tr['id']?'selected':'' ?>><?= e($tr['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <label>القيد</label>
        <select name="entry_code" style="padding:9px 11px;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;font-size:13px;">
            <option value="">كل القيود</option>
            <?php foreach ($repEntryCodes as $ec): ?>
                <option value="<?= (int)$ec['id'] ?>" <?= (string)$selEntry===(string)$ec['id']?'selected':'' ?>><?= e($ec['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <label>بواسطة</label>
        <select name="user" style="padding:9px 11px;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;font-size:13px;">
            <option value="">الكل</option>
            <?php foreach ($repUsers as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= (string)$selUser===(string)$u['id']?'selected':'' ?>><?= e($u['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>
    <button type="submit" class="btn"><i class="ti ti-filter"></i> عرض</button>
    <a href="?type=<?= e($type) ?>&from=<?= e($from) ?>&to=<?= e($to) ?><?= $type==='treasury' && !empty($_GET['entry_code']) ? '&entry_code='.(int)$_GET['entry_code'] : '' ?><?= $type==='treasury' && !empty($_GET['cost_branch']) ? '&cost_branch='.(int)$_GET['cost_branch'] : '' ?><?= $type==='expenses' && !empty($_GET['cost_branch']) ? '&cost_branch='.(int)$_GET['cost_branch'] : '' ?><?= $type==='expenses' && !empty($_GET['treasury']) ? '&treasury='.(int)$_GET['treasury'] : '' ?><?= $type==='expenses' && !empty($_GET['entry_code']) ? '&entry_code='.(int)$_GET['entry_code'] : '' ?><?= $type==='expenses' && !empty($_GET['user']) ? '&user='.(int)$_GET['user'] : '' ?><?= ($type==='custody' || $type==='sales') && !empty($_GET['branch']) ? '&branch='.(int)$_GET['branch'] : '' ?>&export=1" class="btn btn-csv">
        <i class="ti ti-download"></i> تصدير CSV
    </a>
</form>

<?php if ($type === 'sales'):
    // أزرار الفروع لتقرير المبيعات من واقع الورديات (مش أسامي ثابتة)
    $salesBranches = $pdo->query(
        "SELECT DISTINCT b.id, b.name
           FROM shifts s
           JOIN branches b ON b.id = s.branch_id
          ORDER BY b.name"
    )->fetchAll();
    $selBranch = isset($_GET['branch']) && $_GET['branch'] !== '' ? (int)$_GET['branch'] : null;
    $qs = 'type=sales&from=' . urlencode($from) . '&to=' . urlencode($to);
?>
<div class="br-tabs">
    <a href="?<?= $qs ?>" class="br-tab <?= $selBranch===null?'active':'' ?>">كل المبيعات</a>
    <?php foreach ($salesBranches as $sb): ?>
        <a href="?<?= $qs ?>&branch=<?= (int)$sb['id'] ?>"
           class="br-tab <?= $selBranch===(int)$sb['id']?'active':'' ?>"><?= e($sb['name']) ?></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($type === 'custody'):
    // أزرار الفروع. الفرع فلتر والتاريخ فلتر مستقل — الزرار بيشيل نفس
    // from/to الحاليين، فتغيير التاريخ بيغيّر الكشف للفرع المختار على طول.
    // الأسامي من جدول branches مش مكتوبة بالإيد.
    $repBranches = $pdo->query(
        "SELECT b.id, b.name FROM branches b
          WHERE b.is_active = 1
            AND EXISTS (SELECT 1 FROM treasuries t
                         WHERE t.type='branch_custody' AND t.branch_id = b.id)
          ORDER BY b.name"
    )->fetchAll();
    $selBranch = isset($_GET['branch']) && $_GET['branch'] !== '' ? (int)$_GET['branch'] : null;
    $qs = 'type=custody&from=' . urlencode($from) . '&to=' . urlencode($to);
?>
<div class="br-tabs">
    <a href="?<?= $qs ?>" class="br-tab <?= $selBranch===null?'active':'' ?>">كل الفروع (فترات العهدة)</a>
    <?php foreach ($repBranches as $rb): ?>
        <a href="?<?= $qs ?>&branch=<?= (int)$rb['id'] ?>"
           class="br-tab <?= $selBranch===(int)$rb['id']?'active':'' ?>"><?= e($rb['name']) ?></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($type === 'stocktake'): ?>
    <!-- تاب الجرد: أرشيف للقراءة فقط -->
    <div class="card">
        <div class="rep-title">أرشيف الجرد (للقراءة فقط)</div>
        <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin-bottom:8px;">
            <input type="hidden" name="type" value="stocktake">
            <div>
                <label style="display:block;font-size:13px;color:var(--ink-500);margin-bottom:4px;">اختر جلسة الجرد</label>
                <select name="session" onchange="this.form.submit()" style="padding:9px 12px;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;min-width:280px;">
                    <option value="">— اختر —</option>
                    <?php foreach ($stkSessions as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= $stkSelSession===(int)$s['id']?'selected':'' ?>>
                            جلسة #<?= (int)$s['id'] ?> — <?= e(date('Y-m-d', strtotime($s['started_at']))) ?>
                            (<?= (int)$s['sheets_count'] ?> محضر، <?= $s['status']==='active'?'نشطة':'مقفولة' ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <?php if ($stkSelSession && $stkSheets): ?>
            <!-- اختيار المحاضر عبر بوب أب -->
            <form method="get" id="sheetsForm" style="margin-top:10px;">
                <input type="hidden" name="type" value="stocktake">
                <input type="hidden" name="session" value="<?= $stkSelSession ?>">
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <button type="button" class="btn" onclick="document.getElementById('sheetsModal').style.display='flex'">
                        <i class="ti ti-list-check"></i> اختر المحاضر
                    </button>
                    <span style="font-size:13px;color:var(--ink-500);">
                        <?= count($stkSelSheets) ?> محضر مختار من <?= count($stkSheets) ?>
                    </span>
                </div>

                <!-- البوب أب -->
                <div id="sheetsModal" style="display:none;position:fixed;inset:0;background:rgba(30,27,75,0.45);z-index:9999;align-items:center;justify-content:center;">
                    <div style="background:#fff;border-radius:16px;padding:24px;max-width:560px;width:92%;max-height:80vh;display:flex;flex-direction:column;box-shadow:0 10px 40px rgba(0,0,0,0.2);">
                        <h3 style="color:var(--ink-900);font-size:17px;margin-bottom:14px;">اختر المحاضر</h3>
                        <div style="display:flex;gap:8px;margin-bottom:12px;">
                            <button type="button" class="btn btn-sm" onclick="toggleAllSheets(true)">اختيار الكل</button>
                            <button type="button" class="btn btn-sm btn-ghost" onclick="toggleAllSheets(false)">إلغاء الكل</button>
                        </div>
                        <div style="overflow-y:auto;flex:1;border:1.5px solid var(--line);border-radius:10px;">
                            <table style="width:100%;border-collapse:collapse;">
                                <thead><tr style="background:var(--line-soft);">
                                    <th style="padding:9px 12px;width:40px;"></th>
                                    <th style="padding:9px 12px;text-align:right;font-size:13px;color:var(--ink-500);">المحضر</th>
                                    <th style="padding:9px 12px;text-align:right;font-size:13px;color:var(--ink-500);">القائم بالجرد</th>
                                    <th style="padding:9px 12px;text-align:right;font-size:13px;color:var(--ink-500);">الأصناف</th>
                                    <th style="padding:9px 12px;text-align:right;font-size:13px;color:var(--ink-500);">الحالة</th>
                                </tr></thead>
                                <tbody>
                                    <?php foreach ($stkSheets as $sh): ?>
                                        <?php $stMap3=['open'=>'مفتوح','reviewing'=>'مراجعة','done'=>'منتهي']; ?>
                                        <tr style="border-bottom:1px solid var(--line-soft);">
                                            <td style="padding:9px 12px;text-align:center;">
                                                <input type="checkbox" class="sheet-cb" name="sheets[]" value="<?= (int)$sh['id'] ?>"
                                                       <?= in_array((int)$sh['id'],$stkSelSheets,true)?'checked':'' ?> style="width:18px;height:18px;cursor:pointer;">
                                            </td>
                                            <td style="padding:9px 12px;font-size:13px;font-weight:500;">#<?= (int)$sh['sheet_no'] ?><?= $sh['place_note']?' — '.e($sh['place_note']):'' ?></td>
                                            <td style="padding:9px 12px;font-size:13px;"><?= e($sh['counter_name'] ?? '—') ?></td>
                                            <td style="padding:9px 12px;font-size:13px;"><?= (int)$sh['items_count'] ?></td>
                                            <td style="padding:9px 12px;font-size:12px;color:var(--ink-500);"><?= $stMap3[$sh['status']]??'—' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div style="display:flex;gap:8px;margin-top:16px;">
                            <button type="submit" class="btn" style="flex:1;"><i class="ti ti-check"></i> عرض المختار</button>
                            <button type="button" class="btn btn-ghost" onclick="document.getElementById('sheetsModal').style.display='none'">إلغاء</button>
                        </div>
                    </div>
                </div>
            </form>
            <script>
                function toggleAllSheets(on) {
                    document.querySelectorAll('.sheet-cb').forEach(cb => cb.checked = on);
                }
            </script>
        <?php endif; ?>
    </div>

    <?php if ($stkSelSession): ?>
    <div class="card">
        <?php if (empty($stkLines)): ?>
            <div class="empty">لا توجد أصناف في المحاضر المختارة.</div>
        <?php else: ?>
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px;">
            <div class="rep-title" style="margin:0;">أصناف الجرد (<?= count($stkLines) ?> سطر)</div>
            <?php
                // رابط التصدير: المحاضر كنص مفصول بفواصل (يتجنب مشكلة ترميز الأقواس)
                $expQs = 'type=stocktake&session=' . $stkSelSession . '&export=1'
                       . '&sheet_ids=' . implode(',', array_map('intval', $stkSelSheets));
            ?>
            <div style="display:flex;gap:8px;">
                <a href="?<?= $expQs ?>&fmt=csv" class="btn btn-csv"><i class="ti ti-download"></i> تصدير CSV</a>
                <a href="?<?= $expQs ?>&fmt=xlsx" class="btn btn-green" style="text-decoration:none;"><i class="ti ti-file-spreadsheet"></i> تصدير Excel</a>
            </div>
        </div>
        <table>
            <thead><tr>
                <th>المحضر</th><th>الكود</th><th>اسم الصنف</th><th>الوحدة</th>
                <th>جرد الجارد</th><th>جرد المراجع</th><th>النهائية</th>
                <th>رصيد السيستم</th><th>الفرق</th><th>سعر التكلفة</th><th>القائم بالجرد</th>
            </tr></thead>
            <tbody>
                <?php foreach ($stkLines as $l): ?>
                    <?php
                        $base=(float)$l['base_qty'];
                        $rev=$l['review_qty']!==null?(float)$l['review_qty']:null;
                        $fin=$l['final_qty']!==null?(float)$l['final_qty']:($rev!==null?$rev:$base);
                        $sys=$l['system_qty']!==null?(float)$l['system_qty']:null;
                        $diff=$sys!==null?$fin-$sys:null;
                        $cost=$l['cost_price']!==null?(float)$l['cost_price']:null;
                        $fmt=fn($v)=>$v===null?'—':rtrim(rtrim(number_format($v,3),'0'),'.');
                    ?>
                    <tr>
                        <td>#<?= (int)$l['sheet_no'] ?></td>
                        <td><?= e($l['entered_code']) ?></td>
                        <td style="white-space:normal;"><?= e($l['item_name']) ?></td>
                        <td><?= e($l['unit'] ?? '—') ?></td>
                        <td><?= $fmt($base) ?></td>
                        <td><?= $fmt($rev) ?></td>
                        <td style="font-weight:600;color:var(--accent);"><?= $fmt($fin) ?></td>
                        <td><?= $fmt($sys) ?></td>
                        <td style="color:<?= $diff!==null&&$diff<0?'var(--neg)':($diff>0?'var(--pos)':'#666') ?>;"><?= $fmt($diff) ?></td>
                        <td><?= $cost!==null?number_format($cost,2):'—' ?></td>
                        <td style="color:var(--ink-500);font-size:12px;"><?= e($l['counter_name'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p style="color:var(--ink-500);font-size:12px;margin-top:10px;">عرض للقراءة فقط. للتعديل، استخدم زر "إرجاع للمراجعة" من صفحة الجرد (للطوارئ).</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

<?php elseif ($type === 'noprice'): ?>
<?php /* ===== تقرير «أصناف بدون سعر بيع» — الواجهة ===== */ ?>
<?php if (!$npReady): ?>
    <div class="card">
        <div class="empty" style="color:var(--neg);"><?= e($npMsg) ?></div>
    </div>
<?php else: ?>

<style>
    .np-bar { background:#fff; border-radius:12px; padding:14px 16px; margin-bottom:14px;
        display:flex; gap:10px; align-items:center; flex-wrap:wrap;
        box-shadow:0 2px 10px rgba(124,111,247,0.06); }
    .np-bar input[type=text], .np-bar input[type=number] {
        padding:9px 11px; border:1.5px solid var(--line); border-radius:9px;
        font-family:inherit; font-size:13px; }
    .np-search-wrap { position:relative; flex:1 1 280px; min-width:220px; }
    .np-search-wrap input { width:100%; box-sizing:border-box; }
    .np-sugg { position:absolute; top:calc(100% + 4px); right:0; left:0; z-index:40;
        background:#fff; border:1.5px solid var(--line); border-radius:10px;
        max-height:300px; overflow-y:auto; box-shadow:0 6px 20px rgba(0,0,0,0.12); display:none; }
    .np-sugg div { padding:9px 12px; cursor:pointer; font-size:13px; border-bottom:1px solid var(--line-soft); }
    .np-sugg div:last-child { border-bottom:none; }
    .np-sugg div:hover, .np-sugg div.on { background:var(--bg); }
    .np-sugg small { color:var(--ink-500); }

    .np-table td, .np-table th { white-space:nowrap; }
    .np-table td.np-name { white-space:normal; min-width:180px; }
    .np-table input { padding:7px 9px; border:1.5px solid var(--line); border-radius:8px;
        font-family:inherit; font-size:13px; width:100px; text-align:left; direction:ltr; }
    .np-table input:focus { outline:none; border-color:var(--accent); }
    .np-table input.np-dirty { border-color:var(--pos); background:#f2fdf8; }
    .np-table tr.np-nocost td { background:#fff9ec; }
    .np-x { background:none; border:none; cursor:pointer; color:var(--neg); font-size:16px; padding:2px 6px; }

    .np-modal { position:fixed; inset:0; background:rgba(20,16,50,0.55); z-index:200;
        display:none; align-items:center; justify-content:center; padding:16px; }
    .np-modal.on { display:flex; }
    .np-box { background:#fff; border-radius:14px; width:min(820px,100%); max-height:88vh;
        display:flex; flex-direction:column; overflow:hidden; }
    .np-box header { padding:14px 18px; border-bottom:1px solid var(--line);
        display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .np-box .np-list { overflow-y:auto; padding:0 6px; flex:1; }
    .np-box footer { padding:12px 18px; border-top:1px solid var(--line);
        display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .np-row { display:flex; gap:10px; align-items:center; padding:9px 12px;
        border-bottom:1px solid var(--line-soft); font-size:13px; }
    .np-row:hover { background:var(--bg); }
    .np-row.np-have { opacity:.5; }
    .np-row label { flex:1; cursor:pointer; display:flex; gap:10px; align-items:center; }
    .np-muted { color:var(--ink-500); font-size:12px; }
    .btn[disabled] { opacity:.5; cursor:not-allowed; }
    .btn-ghost { background:#fff; color:var(--accent-hover); border:1.5px solid var(--line); }
    .btn-ghost:hover { background:var(--bg); }
    .np-note { font-size:12px; color:var(--ink-500); margin-top:10px; line-height:1.7; }
</style>

<!-- ===================== الشريط العلوي ===================== -->
<div class="np-bar">
    <div class="np-search-wrap">
        <input type="text" id="npSearch" placeholder="ابحث بالكود أو اسم الصنف وأضفه…" autocomplete="off">
        <div class="np-sugg" id="npSugg"></div>
    </div>

    <label style="font-size:13px;color:var(--ink-500);">نسبة موحدة</label>
    <input type="number" id="npPct" step="0.5" style="width:90px;" placeholder="%">
    <button class="btn" id="npApply"><i class="ti ti-percentage"></i> تطبيق النسبة</button>

    <button class="btn btn-ghost" id="npZeroBtn"><i class="ti ti-list-search"></i> أصناف أسعار بيعها 0</button>
</div>

<!-- ===================== الجدول ===================== -->
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px;">
        <div class="rep-title" style="margin:0;">
            أصناف بدون سعر بيع <span class="np-muted" id="npCount"></span>
        </div>
        <div id="npStatus" class="np-muted"></div>
    </div>

    <div style="overflow-x:auto;">
        <table class="np-table">
            <thead><tr>
                <th>كود الصنف</th>
                <th>اسم الصنف</th>
                <th>سعر التكلفة</th>
                <th>سعر البيع</th>
                <th>النسبة %</th>
                <th></th>
            </tr></thead>
            <tbody id="npBody"></tbody>
        </table>
    </div>

    <div id="npEmpty" class="empty" style="display:none;">
        مفيش أصناف في التقرير. ابحث عن صنف فوق، أو افتح «أصناف أسعار بيعها 0».
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px;">
        <a class="btn btn-csv" id="npExport" href="prices_draft_export.php">
            <i class="ti ti-download"></i> سحب تقرير الأسعار
        </a>
        <button class="btn btn-ghost" id="npClear"><i class="ti ti-trash"></i> تفريغ الجدول</button>
    </div>

    <p class="np-note">
        النسبة بتتحسب على <b>سعر التكلفة</b> مش على السعر القديم (تكلفة 100 + نسبة 3 = 103).
        الأصناف اللي تكلفتها صفر بتتلوّن بالأصفر وبتتخطّى في النسبة الموحدة — اكتب سعرها بإيدك.
        <br>
        ⚠️ السعر ده بيتحفظ <b>عندنا بس</b> — زي الاستلامات بالظبط. عشان يوصل نظام الشركة،
        اسحب ملف «تقرير الأسعار» وارفعه هناك.
    </p>
</div>

<!-- ===================== نافذة «سعرها صفر» ===================== -->
<div class="np-modal" id="npModal">
    <div class="np-box">
        <header>
            <b style="font-size:15px;">أصناف أسعار بيعها 0</b>
            <input type="text" id="npZSearch" placeholder="فلترة بالكود أو الاسم…"
                   style="flex:1;min-width:160px;padding:8px 10px;border:1.5px solid var(--line);border-radius:8px;font-family:inherit;font-size:13px;">
            <button class="btn np-x" id="npClose" style="color:var(--ink-500);font-size:20px;">✕</button>
        </header>

        <div style="padding:10px 18px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;border-bottom:1px solid var(--line-soft);">
            <button class="btn btn-ghost" id="npSelAll" style="padding:7px 13px;font-size:13px;">تحديد الكل</button>
            <button class="btn btn-ghost" id="npSelNone" style="padding:7px 13px;font-size:13px;">إلغاء تحديد الكل</button>
            <span class="np-muted" id="npSelInfo"></span>
        </div>

        <div class="np-list" id="npZList"></div>

        <footer>
            <button class="btn btn-ghost" id="npPrev" style="padding:7px 13px;">السابق</button>
            <span class="np-muted" id="npPageInfo"></span>
            <button class="btn btn-ghost" id="npNext" style="padding:7px 13px;">التالي</button>
            <span style="flex:1;"></span>
            <button class="btn" id="npAddSel"><i class="ti ti-plus"></i> إضافة المحدد</button>
        </footer>
    </div>
</div>

<script>
(function () {
    'use strict';

    const CSRF = <?= json_encode(csrf_token()) ?>;
    let rows   = <?= json_encode($npRows, JSON_UNESCAPED_UNICODE) ?>;

    const $ = id => document.getElementById(id);
    const money = v => (Math.round(v * 100) / 100).toFixed(2);

    // ------------------------------------------------------------
    //  نداءات السيرفر
    // ------------------------------------------------------------
    async function api(action, body, qs) {
        const url = 'reports.php?ajax=' + action + (qs ? '&' + qs : '');
        const opt = body
            ? { method:'POST', headers:{'Content-Type':'application/json'},
                body: JSON.stringify(Object.assign({ csrf: CSRF }, body)) }
            : {};
        const res  = await fetch(url, opt);
        const data = await res.json().catch(() => ({ ok:false, error:'رد غير مفهوم من السيرفر.' }));
        if (!data.ok) throw new Error(data.error || 'حصل خطأ.');
        return data;
    }

    function status(msg, bad) {
        const el = $('npStatus');
        el.textContent = msg || '';
        el.style.color = bad ? 'var(--neg)' : 'var(--pos)';
        if (msg && !bad) setTimeout(() => { if (el.textContent === msg) el.textContent = ''; }, 2500);
    }

    // قفل الأزرار وقت الشغل — يمنع الضغط المتكرر
    let busy = false;
    function lock(on) {
        busy = on;
        document.querySelectorAll('.np-bar .btn, #npClear, #npAddSel')
                .forEach(b => b.disabled = on);
    }

    // ------------------------------------------------------------
    //  رسم الجدول
    // ------------------------------------------------------------
    function draw() {
        const tb = $('npBody');
        tb.innerHTML = '';
        $('npCount').textContent = rows.length ? '(' + rows.length + ' صنف)' : '';
        $('npEmpty').style.display = rows.length ? 'none' : 'block';
        $('npExport').style.display = rows.length ? '' : 'none';

        rows.forEach(r => {
            const tr = document.createElement('tr');
            if (r.cost <= 0) tr.className = 'np-nocost';
            tr.innerHTML =
                '<td style="direction:ltr;text-align:right;">' + esc(r.code) + '</td>' +
                '<td class="np-name">' + esc(r.name || '—') + '</td>' +
                '<td>' + (r.cost > 0 ? money(r.cost) : '—') + '</td>' +
                '<td><input type="number" step="0.01" min="0" class="np-price" ' +
                    'data-code="' + esc(r.code) + '" value="' + money(r.sale) + '"></td>' +
                '<td><input type="number" step="0.5" class="np-pct" ' +
                    'data-code="' + esc(r.code) + '" value="' + (r.pct === null ? '' : r.pct) + '"' +
                    (r.cost > 0 ? '' : ' disabled title="مفيش تكلفة"') + '></td>' +
                '<td><button class="np-x" data-del="' + esc(r.code) + '" title="شيل">✕</button></td>';
            tb.appendChild(tr);
        });
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g,
            c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function rowOf(code) { return rows.find(r => r.code === code); }

    // ------------------------------------------------------------
    //  تعديل الجدول (تفويض الأحداث — سطر واحد يغطي كل الصفوف)
    // ------------------------------------------------------------
    $('npBody').addEventListener('input', function (ev) {
        const t = ev.target;
        if (!t.dataset.code) return;
        const r = rowOf(t.dataset.code);
        if (!r) return;

        // كتابة نسبة → احسب السعر من التكلفة فورًا في الشاشة
        if (t.classList.contains('np-pct') && r.cost > 0 && t.value !== '') {
            const p = Math.round(r.cost * (1 + parseFloat(t.value) / 100) * 100) / 100;
            const pi = t.closest('tr').querySelector('.np-price');
            pi.value = money(p);
            pi.classList.add('np-dirty');
        }
        t.classList.add('np-dirty');
    });

    // الحفظ عند الخروج من الخانة (مش مع كل حرف)
    $('npBody').addEventListener('change', async function (ev) {
        const t = ev.target;
        if (!t.dataset.code) return;
        const tr = t.closest('tr');
        const code = t.dataset.code;
        const r = rowOf(code);
        if (!r) return;

        const price = parseFloat(tr.querySelector('.np-price').value || '0');
        const pctV  = tr.querySelector('.np-pct').value;
        if (!isFinite(price) || price < 0) { status('السعر غير صالح.', true); return; }

        try {
            await api('np_price', { code: code, price: price, pct: pctV === '' ? null : parseFloat(pctV) });
            r.sale = price;
            r.pct  = pctV === '' ? null : parseFloat(pctV);
            tr.querySelectorAll('input').forEach(i => i.classList.remove('np-dirty'));
            status('اتحفظ ✓');
        } catch (e) {
            status(e.message, true);
        }
    });

    // شيل صنف
    $('npBody').addEventListener('click', async function (ev) {
        const b = ev.target.closest('[data-del]');
        if (!b) return;
        const code = b.dataset.del;
        try {
            await api('np_remove', { codes: [code] });
            rows = rows.filter(r => r.code !== code);
            draw();
        } catch (e) { status(e.message, true); }
    });

    // ------------------------------------------------------------
    //  النسبة الموحدة
    // ------------------------------------------------------------
    $('npApply').addEventListener('click', async function () {
        if (busy) return;
        const pct = parseFloat($('npPct').value);
        if (!isFinite(pct)) { status('اكتب نسبة الأول.', true); return; }
        if (!rows.length)   { status('الجدول فاضي.', true); return; }
        if (!confirm('هتتطبّق نسبة ' + pct + '% على سعر التكلفة لكل ' + rows.length + ' صنف. تمام؟')) return;

        lock(true); status('بيتحسب…');
        try {
            const d = await api('np_markup', { pct: pct });
            rows = d.rows; draw();
            status('اتحدّث ' + d.updated + ' صنف' + (d.skipped ? ' · اتخطّى ' + d.skipped + ' (من غير تكلفة)' : ''));
        } catch (e) { status(e.message, true); }
        lock(false);
    });

    // ------------------------------------------------------------
    //  تفريغ الجدول
    // ------------------------------------------------------------
    $('npClear').addEventListener('click', async function () {
        if (busy || !rows.length) return;
        if (!confirm('هيتشال كل الـ' + rows.length + ' صنف من التقرير. الأسعار المكتوبة هتضيع. تمام؟')) return;
        lock(true);
        try { await api('np_clear', {}); rows = []; draw(); status('اتفضى ✓'); }
        catch (e) { status(e.message, true); }
        lock(false);
    });

    // ------------------------------------------------------------
    //  البحث + الإضافة اليدوية
    // ------------------------------------------------------------
    let sTimer = null;
    $('npSearch').addEventListener('input', function () {
        clearTimeout(sTimer);
        const term = this.value.trim();
        if (term.length < 2) { $('npSugg').style.display = 'none'; return; }
        sTimer = setTimeout(() => doSearch(term), 300);
    });

    async function doSearch(term) {
        try {
            const d = await api('np_search', null, 'term=' + encodeURIComponent(term));
            const box = $('npSugg');
            if (!d.rows.length) {
                box.innerHTML = '<div class="np-muted" style="cursor:default;">مفيش نتائج</div>';
            } else {
                box.innerHTML = d.rows.map(r =>
                    '<div data-code="' + esc(r.code) + '"><b>' + esc(r.name || '—') + '</b><br>' +
                    '<small>' + esc(r.code) + ' · تكلفة ' + money(r.cost) +
                    ' · بيع ' + money(r.price) + '</small></div>'
                ).join('');
            }
            box.style.display = 'block';
        } catch (e) { status(e.message, true); }
    }

    $('npSugg').addEventListener('click', async function (ev) {
        const d = ev.target.closest('[data-code]');
        if (!d) return;
        $('npSugg').style.display = 'none';
        $('npSearch').value = '';
        await addCodes([d.dataset.code]);
    });

    document.addEventListener('click', function (ev) {
        if (!ev.target.closest('.np-search-wrap')) $('npSugg').style.display = 'none';
    });

    async function addCodes(codes) {
        if (!codes.length) return;
        lock(true); status('بيتضاف…');
        try {
            const d = await api('np_add', { codes: codes });
            rows = d.rows; draw();
            status(d.added ? ('اتضاف ' + d.added + ' صنف ✓') : 'موجود بالفعل في الجدول.');
        } catch (e) { status(e.message, true); }
        lock(false);
    }

    // ------------------------------------------------------------
    //  نافذة «سعرها صفر»
    // ------------------------------------------------------------
    const picked = new Set();
    let zPage = 1, zPages = 1, zTerm = '';

    $('npZeroBtn').addEventListener('click', () => { $('npModal').classList.add('on'); loadZero(1); });
    $('npClose').addEventListener('click',  () => $('npModal').classList.remove('on'));
    $('npModal').addEventListener('click', ev => { if (ev.target === $('npModal')) $('npModal').classList.remove('on'); });

    let zTimer = null;
    $('npZSearch').addEventListener('input', function () {
        clearTimeout(zTimer);
        zTerm = this.value.trim();
        zTimer = setTimeout(() => loadZero(1), 350);
    });

    async function loadZero(page) {
        $('npZList').innerHTML = '<div class="np-muted" style="padding:20px;text-align:center;">بيحمّل…</div>';
        try {
            const d = await api('np_zero', null,
                'page=' + page + '&term=' + encodeURIComponent(zTerm));
            zPage = d.page; zPages = d.pages;
            $('npPageInfo').textContent = 'صفحة ' + d.page + ' من ' + d.pages + ' · ' + d.total + ' صنف';
            $('npPrev').disabled = d.page <= 1;
            $('npNext').disabled = d.page >= d.pages;

            $('npZList').innerHTML = d.rows.length ? d.rows.map(r =>
                '<div class="np-row' + (r.in_report ? ' np-have' : '') + '">' +
                  '<label><input type="checkbox" class="np-cb" value="' + esc(r.code) + '"' +
                    (picked.has(r.code) ? ' checked' : '') + (r.in_report ? ' disabled' : '') + '>' +
                    '<span><b>' + esc(r.name || '—') + '</b><br>' +
                    '<small class="np-muted">' + esc(r.code) + ' · تكلفة ' + money(r.cost) +
                    (r.in_report ? ' · <b>في الجدول</b>' : '') + '</small></span>' +
                  '</label></div>'
            ).join('') : '<div class="np-muted" style="padding:24px;text-align:center;">مفيش أصناف مطابقة.</div>';

            info();
        } catch (e) {
            $('npZList').innerHTML = '<div style="padding:20px;text-align:center;color:var(--neg);">' + esc(e.message) + '</div>';
        }
    }

    $('npZList').addEventListener('change', function (ev) {
        const cb = ev.target.closest('.np-cb');
        if (!cb) return;
        cb.checked ? picked.add(cb.value) : picked.delete(cb.value);
        info();
    });

    function info() { $('npSelInfo').textContent = picked.size ? ('محدد: ' + picked.size) : ''; }

    $('npPrev').addEventListener('click', () => { if (zPage > 1) loadZero(zPage - 1); });
    $('npNext').addEventListener('click', () => { if (zPage < zPages) loadZero(zPage + 1); });

    // تحديد الكل = كل المطابق للفلتر، مش الصفحة الحالية بس
    $('npSelAll').addEventListener('click', async function () {
        try {
            const d = await api('np_zero_all', null, 'term=' + encodeURIComponent(zTerm));
            d.codes.forEach(c => picked.add(c));
            document.querySelectorAll('.np-cb:not(:disabled)').forEach(cb => cb.checked = true);
            info();
        } catch (e) { status(e.message, true); }
    });

    $('npSelNone').addEventListener('click', function () {
        picked.clear();
        document.querySelectorAll('.np-cb').forEach(cb => cb.checked = false);
        info();
    });

    $('npAddSel').addEventListener('click', async function () {
        if (busy || !picked.size) return;
        const codes = Array.from(picked);
        picked.clear();
        $('npModal').classList.remove('on');
        await addCodes(codes);
    });

    draw();
})();
</script>

<?php endif; ?>

<?php else: ?>
<div class="card">
    <div class="rep-title"><?= e($report['title']) ?></div>
    <div class="rep-period">من <?= e($from) ?> إلى <?= e($to) ?></div>
    <?php if (!empty($report['note'])): ?>
        <div class="empty"><?= e($report['note']) ?></div>
    <?php endif; ?>
    <?php if (!empty($report['sum_cards']) && !empty($report['rows'])): ?>
        <?php /* الكروت بتتحسب في المتصفح من الصفوف الظاهرة في الجدول.
                 القيم الابتدائية سيرفر-سايد عشان الكروت تبان صح حتى لو
                 الجافاسكربت اتأخر. أول ما xtable يفلتر، بتتحدّث. */ ?>
        <div class="sum-cards" id="sumCards"
             data-cards='<?= json_encode($report['sum_cards'], JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT) ?>'>
            <?php foreach ($report['sum_cards'] as $ci => $cd): ?>
                <?php
                // كارت ممكن يجمع عمود واحد (col) أو أكتر (cols)
                // cols = أعمدة بتتجمع · minus = أعمدة بتتطرح
                // (الصافي = مدين − دائن)
                $cols  = $cd['cols']  ?? [$cd['col']];
                $minus = $cd['minus'] ?? [];
                $t = 0.0;
                if (!empty($cd['count'])) {          // كارت عدّاد مش مجموع
                    $t = count($report['rows']);
                }
                foreach ($report['rows'] as $rw) {
                    if (!empty($cd['count'])) break;
                    foreach ($cols as $cx) {
                        $t += (float) str_replace([',', '—'], '', (string)($rw[$cx] ?? '0'));
                    }
                    foreach ($minus as $cx) {
                        $t -= (float) str_replace([',', '—'], '', (string)($rw[$cx] ?? '0'));
                    }
                }
                ?>
                <div class="sum-card" data-card="<?= (int)$ci ?>">
                    <div class="sc-label"><?= e($cd['label']) ?></div>
                    <div class="sc-value"><?= (!empty($cd['int']) || !empty($cd['count'])) ? number_format($t) : number_format($t, 2) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if (empty($report['rows'])): ?>
        <div class="empty">لا توجد بيانات في هذه الفترة.</div>
    <?php else: ?>
    <?php
    // عمود الملاحظات (لو التقرير فيه واحد): بيتقصّ بـ ellipsis والنص
    // الكامل في الـ tooltip. التصدير بياخد النص كامل من $report['rows']
    // مباشرةً فمابيتأثرش بالقص ده.
    $noteCol = $report['notes_col'] ?? -1;
    ?>
    <table>
        <thead>
            <tr><?php foreach ($report['headers'] as $hi => $h): ?><th<?= $hi === $noteCol ? ' data-xt-skip' : '' ?>><?= e($h) ?></th><?php endforeach; ?></tr>
        </thead>
        <tbody>
            <?php foreach ($report['rows'] as $ri => $row): ?>
                <?php
                // صف قابل للفتح؟ (تقرير إجماليات القيود بس دلوقتي)
                $rowMeta = $report['row_ids'][$ri] ?? null;
                $isAcc   = !empty($report['accordion']) && $rowMeta !== null;
                $accId   = is_array($rowMeta) ? (int)$rowMeta['acc'] : (int)$rowMeta;
                $brId    = is_array($rowMeta) ? (int)($rowMeta['branch'] ?? 0) : 0;
                ?>
                <tr<?= $isAcc ? ' class="acc-row" data-acc="'.$accId.'" data-branch="'.$brId.'" onclick="jeToggle(this)"' : '' ?>><?php foreach ($row as $ci => $cell): ?>
                    <?php if ($ci === $noteCol): ?>
                        <td class="rep-note" data-xt-skip title="<?= e((string)$cell) ?>"><?= $cell !== '' ? e((string)$cell) : '—' ?></td>
                    <?php elseif ($isAcc && $ci === 0): ?>
                        <td><span class="acc-caret">&#9656;</span> <?= e((string)$cell) ?></td>
                    <?php else: ?>
                        <td><?= e((string)$cell) ?></td>
                    <?php endif; ?>
                <?php endforeach; ?></tr>
                <?php if ($isAcc): ?>
                    <?php /* صف التفاصيل — بيتملى أول مرة تفتحه وبيتخزّن بعدها */ ?>
                    <?php /* data-xt-nosort: صف التفاصيل مربوط بالصف اللي
                             فوقه. الترتيب هيفصلهم عن بعض، فبيتقفل في
                             الجدول ده وحده. باقي التقارير الترتيب فيها شغّال. */ ?>
                    <tr class="acc-body" data-xt-ignore data-xt-nosort style="display:none;">
                        <td colspan="<?= count($report['headers']) ?>"></td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
/* =====================================================================
   كروت الإجماليات — بتقرا من نفس الصفوف اللي الجدول بيعرضها
   ---------------------------------------------------------------------
   الفكرة:
       الصفوف الظاهرة  ->  الجدول  ->  الكروت
   مش:
       الداتابيز  ->  استعلام للجدول  +  استعلام تاني للكروت

   xtable بيبعت 'xtable:filter' في كل فلترة ومعاه الصفوف الظاهرة،
   فالكروت بتجمع من الـDOM نفسه. يعني مستحيل يحصل اختلاف بين رقم
   الكارت واللي تحته في الجدول.

   لو xtable مش موجود لأي سبب، القيم اللي السيرفر حسبها بتفضل ظاهرة.
   ===================================================================== */
(function () {
    var box = document.getElementById('sumCards');
    if (!box) return;

    var cards;
    try { cards = JSON.parse(box.dataset.cards || '[]'); } catch (e) { return; }
    if (!cards.length) return;

    var table = document.querySelector('.card table');
    if (!table) return;

    /* "1,234.50" -> 1234.5   |   "—" أو فاضي -> 0 */
    function num(txt) {
        var v = parseFloat(String(txt).replace(/,/g, '').replace(/[^\d.\-]/g, ''));
        return isNaN(v) ? 0 : v;
    }

    function fmt(v, isInt) {
        return isInt
            ? v.toLocaleString('en-US', { maximumFractionDigits: 0 })
            : v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function paint(rows, active, shown, total) {
        for (var i = 0; i < cards.length; i++) {
            var cd = cards[i], sum = 0;
            var cols  = cd.cols || [cd.col];   // أعمدة بتتجمع
            var minus = cd.minus || [];        // أعمدة بتتطرح (الصافي)
            if (cd.count) {                    // كارت عدّاد: عدد الصفوف الظاهرة
                var el0 = box.querySelector('[data-card="' + i + '"] .sc-value');
                if (el0) { el0.textContent = fmt(rows.length, true);
                           el0.classList.remove('neg','pos'); }
                continue;
            }
            for (var r = 0; r < rows.length; r++) {
                for (var c = 0; c < cols.length; c++) {
                    var cell = rows[r].cells[cols[c]];
                    if (cell) sum += num(cell.textContent);
                }
                for (var m = 0; m < minus.length; m++) {
                    var mc = rows[r].cells[minus[m]];
                    if (mc) sum -= num(mc.textContent);
                }
            }
            var el = box.querySelector('[data-card="' + i + '"] .sc-value');
            if (!el) continue;
            el.textContent = fmt(sum, !!cd.int);
            el.classList.remove('neg', 'pos');
            if (cd.signed && sum < 0) el.classList.add('neg');
            else if (cd.signed && sum > 0) el.classList.add('pos');
        }
        box.classList.toggle('filtered', !!active);

        // الملاحظة شقيقة للـbox مش جواه — لازم ندوّر عليها في الأب،
        // وإلا مابتتلقاش ومابتتشالش لما الفلتر يترفع.
        var note = box.parentNode.querySelector('.sc-note-wrap');
        if (active) {
            if (!note) {
                note = document.createElement('div');
                note.className = 'sc-note-wrap sc-note';
                box.parentNode.insertBefore(note, box.nextSibling);
            }
            note.textContent = 'الأرقام دي للـ ' + shown + ' صف الظاهرين بعد الفلترة (من ' + total + ')';
        } else if (note) {
            note.parentNode.removeChild(note);
        }
    }

    /* الصفوف الظاهرة فعلاً في الجدول دلوقتي.
       بنقرا من الـDOM مباشرةً: أي صف عليه xt-out يبقى مخفي بالفلتر.
       صف الإجمالي وصف مجموع xtable مش صفوف داتا فبيتشالوا. */
    function visibleRows() {
        var out = [], total = 0, tb = table.tBodies[0];
        if (!tb) return { rows: out, total: 0 };
        for (var i = 0; i < tb.rows.length; i++) {
            var tr = tb.rows[i];
            if (tr.classList.contains('totals') ||
                tr.classList.contains('xt-sumrow') ||
                tr.hasAttribute('data-xt-ignore')) continue;
            total++;
            if (!tr.classList.contains('xt-out') && tr.style.display !== 'none') out.push(tr);
        }
        return { rows: out, total: total };
    }

    function refresh() {
        var v = visibleRows();
        paint(v.rows, v.rows.length !== v.total, v.rows.length, v.total);
    }

    /* المصدر الأساسي: مراقبة الجدول نفسه.
       xtable بيخفي الصفوف بإضافة الكلاس xt-out، فأي فلترة بتوصلنا
       من غير ما نعتمد على حدث من مكتبة معيّنة أو نسخة معيّنة منها.
       ده اللي بيخلّي الكروت = اللي في الجدول حرفياً. */
    var tmr = null;
    function schedule() { clearTimeout(tmr); tmr = setTimeout(refresh, 30); }

    if (window.MutationObserver) {
        new MutationObserver(schedule).observe(table, {
            attributes: true, attributeFilter: ['class', 'style'],
            subtree: true, childList: true
        });
    }

    /* مسار سريع لو المكتبة بتبعت الحدث (بيوفّر لفة المراقب) */
    document.addEventListener('xtable:filter', function (ev) {
        if (ev.detail && ev.detail.table !== table) return;
        schedule();
    });

    refresh();
})();
</script>

<script>
/* =====================================================================
   أكورديون تفاصيل القيد — تقرير إجماليات القيود
   ---------------------------------------------------------------------
   - مفيش ريفريش للصفحة ولا إعادة تحميل للتقرير.
   - بيجيب التفاصيل مرة واحدة بس؛ الفتح التاني بيعرض المخزّن من غير طلب.
   - بيبعت الـid الحقيقي للحساب مش الاسم.
   ===================================================================== */
function jeToggle(tr) {
    var body = tr.nextElementSibling;
    if (!body || !body.classList.contains('acc-body')) return;

    var open = body.style.display !== 'none';
    if (open) {                       // اقفل
        body.style.display = 'none';
        tr.classList.remove('open');
        return;
    }
    body.style.display = '';
    tr.classList.add('open');

    if (tr.dataset.loaded === '1') return;   // متحمّل خلاص — مفيش طلب تاني

    var cell = body.firstElementChild;
    cell.innerHTML = '<div class="acc-msg">جارِ التحميل…</div>';

    var p = new URLSearchParams(location.search);
    var branchId = tr.dataset.branch !== undefined ? tr.dataset.branch : '';
    var url = 'reports.php?ajax=je_details&acc=' + encodeURIComponent(tr.dataset.acc) +
              '&branch=' + encodeURIComponent(branchId) +
              '&from='   + encodeURIComponent(p.get('from') || '') +
              '&to='     + encodeURIComponent(p.get('to')   || '');

    fetch(url).then(function (r) { return r.json(); }).then(function (d) {
        if (!d.ok) throw new Error(d.error || 'حصل خطأ.');
        if (!d.rows.length) {
            cell.innerHTML = '<div class="acc-msg">مفيش حركات في الفترة دي.</div>';
            tr.dataset.loaded = '1';
            return;
        }
        var h = '<div class="acc-inner"><table><thead><tr>' +
                '<th>التاريخ</th><th>البيان</th><th>النوع</th><th>الخزينة</th><th>الفرع</th>' +
                '<th>مدين</th><th>دائن</th><th>الرصيد</th><th>بواسطة</th><th>الملاحظات</th>' +
                '</tr></thead><tbody>';
        var esc = function (x) {
            return String(x == null ? '' : x).replace(/&/g, '&amp;')
                .replace(/</g, '&lt;').replace(/>/g, '&gt;');
        };
        var f = function (v) {
            return v ? v.toLocaleString('en-US', { minimumFractionDigits: 2,
                                                   maximumFractionDigits: 2 }) : '—';
        };
        d.rows.forEach(function (r) {
            h += '<tr><td>' + esc(r.date) + '</td><td>' + esc(r.desc) + '</td>' +
                 '<td>' + esc(r.kind) + '</td><td>' + esc(r.treasury) + '</td>' +
                 '<td>' + esc(r.branch || '—') + '</td>' +
                 '<td>' + f(r.debit) + '</td><td>' + f(r.credit) + '</td>' +
                 '<td>' + f(r.balance) + '</td><td>' + esc(r.by) + '</td>' +
                 '<td>' + (r.notes ? esc(r.notes) : '—') + '</td></tr>';
        });
        cell.innerHTML = h + '</tbody></table></div>';
        tr.dataset.loaded = '1';
    }).catch(function (ex) {
        cell.innerHTML = '<div class="acc-msg">' + (ex.message || 'الاتصال فشل') + '</div>';
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
