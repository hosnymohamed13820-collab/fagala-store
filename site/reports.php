<?php
/**
 * reports.php — التقارير
 * نظام الفجالة ستور المالي
 *
 * تقارير: المبيعات | الخزينة | العهد | الموردين | الرواتب | الحضور
 * - كل تقرير بفلتر فترة (من/إلى).
 * - تصدير إكسل (.xlsx) لأي تقرير — الجرد له تصدير CSV خاص بيه.
 */
require_once __DIR__ . '/auth.php';
$requestedPurchaseReport = in_array((string)($_GET['type'] ?? ''), ['purchase_docs','purchase_by_item'], true)
    && can('purchase_docs:reports');
$requestedItemMovement = ((string)($_GET['type'] ?? '') === 'item_movement')
    && (can('reports:item_movement') || can('item_card'));
if ($requestedPurchaseReport) require_permission('purchase_docs:reports');
elseif ($requestedItemMovement) {
    require_login();
} else {
    require_module_access('reports');
}
require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/sensitive_lib.php';
require_once __DIR__ . '/reports_lib.php';

$pdo = db();

// قراءة التقارير المسموحة للمستخدم بناءً على أجزاء مديول التقارير
$repParts = module_parts()['reports'] ?? [];
$allowedTypes = [];
foreach ($repParts as $k => $label) {
    if (in_array($k, ['insta_recon', 'stock_recon', 'stock_movement'], true) && !is_owner()) continue;
    if (can('reports:' . $k) || ($k === 'item_movement' && can('item_card'))) {
        $allowedTypes[$k] = $label;
    }
}
if (can('purchase_docs:reports')) {
    $allowedTypes['purchase_docs'] = 'مستندات الشراء';
    $allowedTypes['purchase_by_item'] = 'الشراء بالصنف';
}

// لو المستخدم مالوش أي تقرير مسموح (حزام أمان إضافي)
if (empty($allowedTypes)) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;text-align:center;padding:60px;direction:rtl">'
        . '<h2>غير مصرّح</h2>'
        . '<p>ليس لديك صلاحية للوصول إلى هذه الصفحة.</p>'
        . '<a href="logout.php">تسجيل الخروج</a></div>');
}

// تحديد نوع التقرير النشط: لو طلب نوع مسموح يفتحه، لو مالوش صلاحيته (أو مبعتش) يتحوّل لأول نوع مسموح له
$requestedType = (string)($_GET['type'] ?? '');
if ($requestedType === 'dist_invoices' && !isset($allowedTypes['dist_invoices'])) {
    http_response_code(403);
    exit('غير مصرح');
}
if (in_array($requestedType, ['insta_recon', 'stock_recon', 'stock_movement'], true) && !is_owner()) {
    http_response_code(403);
    exit('غير مصرح');
}
if (isset($allowedTypes[$requestedType])) {
    $type = $requestedType;
} else {
    $type = (string) array_key_first($allowedTypes);
}
if (in_array($type, ['insta_recon', 'stock_recon', 'stock_movement'], true) && !is_owner()) {
    http_response_code(403);
    exit('غير مصرح');
}
// الملخص اليومي صفحة لوحدها (daily_summary.php) — التبويب لينك ليها
if ($type === 'daily') {
    header('Location: daily_summary.php');
    exit;
}

const SALREP_GRACE_DAYS = 5;
if ($type === 'salaries') {
    // نفس منطق فترة الرواتب: الشهر بيقفل يوم ٢٥، ومهلة ٥ أيام قبل ما
    // التقرير يقلب للشهر الجديد — عشان اللي بيصرف يوم ٣ أو ٤ يلاقي
    // الفترة اللي لسه بيشتغل عليها مفتوحة قدامه.
    $salAnchor = (int) date('j') <= SALREP_GRACE_DAYS
        ? strtotime('first day of last month')
        : strtotime('first day of this month');
    $defaultTo   = date('Y-m-25', $salAnchor);
    $defaultFrom = date('Y-m-26', strtotime('-1 month', $salAnchor));
    $from = $_GET['from'] ?? $defaultFrom;
    $to   = $_GET['to']   ?? $defaultTo;
    if (!strtotime($from)) $from = $defaultFrom;
    if (!strtotime($to))   $to   = $defaultTo;
} else {
    $from = $_GET['from'] ?? date('Y-m-01');           // أول الشهر افتراضياً
    $to   = $_GET['to']   ?? date('Y-m-d');
    if (!strtotime($from)) $from = date('Y-m-01');
    if (!strtotime($to))   $to   = date('Y-m-d');
}
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
    if (!can('reports:jtotals') && !can('reports:treasury')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'غير مصرّح لك بالوصول لتفاصيل القيود.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
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
                $branchCond = ' AND COALESCE(tm.branch_id, sh.branch_id, bc.branch_id, cl.branch_id, bc2.branch_id, dor.branch_id, 0) = ?';
                $branchArg  = $bVal;
            } else {
                $branchCond = ' AND COALESCE(tm.branch_id, sh.branch_id, bc.branch_id, cl.branch_id, bc2.branch_id, dor.branch_id, 0) = 0';
            }
        }

        $args = [$dFrom, $dTo];
        if ($accId > 0) $args[] = $accId;
        if ($branchArg !== null) $args[] = $branchArg;

        $st = $pdo->prepare(
            "SELECT je.id, je.entry_date, sh.shift_name, je.description, je.debit, je.credit,
                    je.running_balance, je.notes, je.ref_type,
                    t.name AS treasury, u.full_name AS by_name,
                    cb.name AS branch_name,
                    tfrom.name AS transfer_from_name,
                    tto.name AS transfer_to_name
               FROM journal_entries je
               JOIN treasuries t ON t.id = je.treasury_id
               LEFT JOIN users u ON u.id = je.created_by
               LEFT JOIN treasury_movements tm
                      ON tm.id = je.ref_id AND je.ref_type IN ('receipt','disbursement','date_fix_rev','reversal')
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
              WHERE je.entry_date BETWEEN ? AND ? AND $accCond $branchCond
              ORDER BY je.entry_date, je.id"
        );
        $st->execute($args);

        $kinds = ['receipt'=>'استلام','disbursement'=>'صرف','transfer'=>'تحويل',
                  'shift'=>'تقفيل وردية','custody'=>'عهدة','advance'=>'سلفة'];
        $out = [];
        foreach ($st as $r) {
            $out[] = [
                'id'       => (int) $r['id'],
                'date'     => $r['entry_date'],
                'shift'    => (string) ($r['shift_name'] ?: '—'),
                'desc'     => (string) $r['description'],
                'path'     => je_transfer_path($r['transfer_from_name'] ?? null, $r['transfer_to_name'] ?? null),
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
    } catch (PDOException $ex) {
        $errorId = substr(bin2hex(random_bytes(8)), 0, 10);
        errlog_record('php', "رقم الخطأ $errorId: " . $ex->getMessage(), $ex->getFile(), $ex->getLine(), $ex->getTraceAsString());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => "حصل خطأ — رقم: $errorId"], JSON_UNESCAPED_UNICODE);
    } catch (RuntimeException|DomainException $ex) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        $errorId = substr(bin2hex(random_bytes(8)), 0, 10);
        errlog_record('php', "رقم الخطأ $errorId: " . $ex->getMessage(), $ex->getFile(), $ex->getLine(), $ex->getTraceAsString());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => "حصل خطأ — رقم: $errorId"], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$ajax = $_GET['ajax'] ?? '';
if ($ajax !== '') {
    header('Content-Type: application/json; charset=utf-8');
    if (!can('reports:noprice')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'غير مصرّح لك بالوصول لهذه العمليات.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
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
    } catch (PDOException $ex) {
        $errorId = substr(bin2hex(random_bytes(8)), 0, 10);
        errlog_record('php', "رقم الخطأ $errorId: " . $ex->getMessage(), $ex->getFile(), $ex->getLine(), $ex->getTraceAsString());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => "حصل خطأ — رقم: $errorId"], JSON_UNESCAPED_UNICODE);
    } catch (RuntimeException|DomainException $ex) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        $errorId = substr(bin2hex(random_bytes(8)), 0, 10);
        errlog_record('php', "رقم الخطأ $errorId: " . $ex->getMessage(), $ex->getFile(), $ex->getLine(), $ex->getTraceAsString());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => "حصل خطأ — رقم: $errorId"], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($type === 'salaries' && !sens_unlocked()) {
    $report = ['title'=>'تقرير الرواتب','headers'=>[],'rows'=>[],'totals'=>[]];
} elseif ($type === 'item_movement') {
    $report = ['title'=>'حركة الصنف','headers'=>[],'rows'=>[],'totals'=>[]];
} else {
    $report = build_report($pdo, $type, $from, $to);
}

// تصدير إكسل (للتقارير العادية — الجرد له تصدير خاص بيه تحت)
//
// 🔴 كان CSV واتغيّر لـ.xlsx حقيقي — والسبب إن الـCSV مايقدرش يحل
//    التلات مشاكل مع بعض:
//      · العربي: محتاج BOM عشان إكسل يقراه UTF-8.
//      · الأعمدة: إكسل على ويندوز العربي فاصل قوايمه **فاصلة منقوطة**،
//        فالملف كان بينزل كله في عمود واحد.
//      · الأرقام: مترندرة بـ`number_format` يعني «2,100.00» — وإكسل
//        بيقراها نص فمش بتتجمع.
//    والحل اللي جرّبناه (`sep=,`) ظبّط الأعمدة **وكسر العربي**: إكسل
//    لما بيشوف `sep=` بيتجاهل الـBOM ويقرا بترميز ويندوز، فالعربي
//    بيطلع طلاسم. حل واحد منهم بيكسر التاني — فمفيش CSV يظبط.
//
//    `SimpleXlsx` بتكتب ملف إكسل حقيقي: الترميز جوّاه UTF-8 دايماً،
//    والأعمدة خلايا مش نص مفصول، والرقم بيدخل رقم.
//    نفس المكتبة شغالة في أربع صفحات تصدير تانية من زمان.
if ($export && $type !== 'stocktake' && $type !== 'customers' && $type !== 'supplier_purchases' && $type !== 'transfers' && $type !== 'transfer_shorts' && $type !== 'item_movement') {
    if ($type === 'salaries' && !sens_unlocked()) {
        http_response_code(403);
        exit('الرواتب مقفولة');
    }
    require_once __DIR__ . '/xlsx_lib.php';
    $x = new SimpleXlsx(mb_substr((string)($report['title'] ?? 'تقرير'), 0, 30));
    $hasShiftColumn = ($report['headers'][1] ?? '') === 'رقم الوردية';
    if ($hasShiftColumn) $x->setColumnTypes(['n', 's']);

    /**
     * الأرقام في `rows` مترندرة للعرض — «2,100.00». هنا بنرجّعها رقم
     * حقيقي عشان تدخل الخلية كرقم، فتتجمع وتترتب في إكسل.
     * أي نص فيه فاصلة (زي الملاحظات) بيعدّي زي ما هو.
     */
    $xlCell = static function ($v) {
        $s = trim((string) $v);
        if ($s === '' || $s === '—') return $s;
        if (preg_match('/^-?\d{1,3}(,\d{3})*(\.\d+)?$/', $s)) {
            return (float) str_replace(',', '', $s);
        }
        return $s;
    };

    $x->addRow($report['headers'], true);

    $descCol = ($type === 'treasury') ? 3 : (($type === 'custody' && isset($_GET['branch']) && $_GET['branch'] !== '') ? 2 : -1);
    foreach ($report['rows'] as $ri => $row) {
        if ($descCol >= 0 && !empty($report['row_paths'][$ri])) {
            $row[$descCol] = (string)$row[$descCol] !== ''
                ? ($row[$descCol] . ' — ' . $report['row_paths'][$ri])
                : $report['row_paths'][$ri];
        }
        foreach ($row as $ci => $cell) {
            $row[$ci] = $hasShiftColumn && $ci === 1 ? (string) $cell : $xlCell($cell);
        }
        $x->addRow($row);
    }
    if (!empty($report['totals'])) $x->addRow(array_map($xlCell, $report['totals']), true);
    if (!empty($report['balance_warning'])) $x->addRow([$report['balance_warning']]);
    if ($type === 'insta_recon') {
        $x->addBlank();
        $x->addRow(['اليوم', 'الفرع', 'الوردية', 'حالة الوردية', 'الكاشير', 'المبلغ', 'مرجع الكاشير', 'المطابقة', 'وقت الرسالة', 'رقم الرسالة', 'الراسل'], true);
        foreach ($report['details'] as $dayLines) {
            foreach ($dayLines as $line) {
                $sms = $line['sms'];
                $x->addRow([$line['date'], $line['branch'], $line['shift_name'], $line['shift_status'], $line['cashier'],
                    $line['amount_cents'] / 100, $line['reference'], $line['status'],
                    $sms['sms_at'] ?? '', $sms['id'] ?? implode('، ', $line['candidates']), $sms['sender'] ?? '']);
            }
        }
        if ($report['unmatched_sms']) {
            $x->addBlank();
            $x->addRow(['رسايل واردة من غير مطابقة مؤكدة'], true);
            $x->addRow(['يوم العمل', 'وقت الرسالة', 'رقم الرسالة', 'المبلغ', 'الراسل'], true);
            foreach ($report['unmatched_sms'] as $sms) {
                $x->addRow([$sms['business_date'], $sms['sms_at'], $sms['id'], $sms['amount_cents'] / 100, $sms['sender']]);
            }
        }
    }

    $x->download($type . '_' . $from . '_' . $to . '.xlsx');
    exit;
}

$reportTypes = [
    'sales'=>'المبيعات','insta_recon'=>'مطابقة إنستاباي','treasury'=>'الخزينة','expenses'=>'المصاريف','dist_invoices'=>'الفواتير الموزّعة','jtotals'=>'إجماليات القيود','custody'=>'العهد',
    'deficits'=>'العجوزات',
    'suppliers'=>'الموردين','salaries'=>'الرواتب','advances'=>'السلف','attendance'=>'الحضور','attendance_totals'=>'إجمالي الحضور',
    'stocktake'=>'الجرد','noprice'=>'أصناف بدون سعر بيع','customers'=>'داتا العملاء','receiving'=>'استلام الفواتير',
    'daily'=>'الملخص اليومي',
    'supplier_purchases'=>'مشتريات مورد',
    'transfers'=>'التحويلات بين المخازن',
    'transfer_shorts'=>'عجوزات التحويلات المستردة',
    'purchase_docs'=>'مستندات الشراء',
    'purchase_by_item'=>'الشراء بالصنف',
    'stock_recon'=>'مطابقة المخزن مع True',
    'stock_movement'=>'حركة الأصناف المخزنية',
    'item_movement'=>'حركة الصنف',
];

$distFilterOptions = ['branches' => [], 'suppliers' => []];
if ($type === 'dist_invoices') {
    $distFilterOptions = dr_filter_options();
}

// بيانات تقرير «مشتريات مورد»
$spReport = null;
$spBranches = [];
$spSelBranch = null;
if ($type === 'supplier_purchases') {
    require_once __DIR__ . '/supplier_purchases_lib.php';
    try {
        $spBranches = $pdo->query("SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name")->fetchAll();
    } catch (Throwable) {
        $spBranches = [];
    }
    $spSelBranch = isset($_GET['branch']) && $_GET['branch'] !== '' ? (int)$_GET['branch'] : null;
    $spReport = supplier_purchases_report($from, $to, $spSelBranch);

    if ($export) {
        require_once __DIR__ . '/xlsx_lib.php';
        $x = new SimpleXlsx('مشتريات مورد');
        $x->setColumnTypes(['s', 's', 's', 'n', 'n', 'n', 'n']);
        $x->addRow(['كود المورد', 'اسم المورد', 'نوع المورد', 'إجمالي مشتريات', 'مردودات مشتريات', 'إجمالي الخصومات', 'الصافي'], true);

        foreach ($spReport['rows'] as $r) {
            $pTypeLabel = $r['payment_type'] === 'cash' ? 'كاش' : ($r['payment_type'] === 'credit' ? 'آجل' : '—');
            $x->addRow([
                (string)$r['code'],
                $r['name'],
                $pTypeLabel,
                $r['purchases'],
                $r['returns'] > 0 ? -$r['returns'] : 0,
                $r['discounts'],
                $r['net'],
            ]);
        }

        $tot = $spReport['totals'];
        $x->addRow([
            'الإجمالي (' . $tot['count'] . ')',
            '',
            '',
            $tot['purchases'],
            $tot['returns'] > 0 ? -$tot['returns'] : 0,
            $tot['discounts'],
            $tot['net'],
        ], true);

        $x->download('supplier_purchases_' . $from . '_' . $to . '.xlsx');
        exit;
    }
}

// بيانات تقرير «التحويلات بين المخازن»
$trReportRows = [];
$trBranches = [];
$trSelBranch = null;
if ($type === 'transfers') {
    require_once __DIR__ . '/stock_transfer_lib.php';
    try {
        $trBranches = $pdo->query("SELECT id, name FROM branches WHERE is_active = 1 AND id <> 5 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        $trBranches = [];
    }
    $trSelBranch = isset($_GET['branch']) && $_GET['branch'] !== '' ? (int)$_GET['branch'] : null;
    $trReportRows = transfer_reports_transfers($pdo, $from, $to, $trSelBranch);

    if ($export) {
        require_once __DIR__ . '/xlsx_lib.php';
        $x = new SimpleXlsx('التحويلات بين المخازن');
        $x->setColumnTypes(['s', 's', 's', 's', 's', 'n', 'n', 'n', 'n', 'n', 's']);
        $x->addRow(['التحويل', 'من مخزن', 'إلى مخزن', 'تاريخ الإرسال', 'تاريخ الاستلام', 'المدة (ساعات)', 'عدد الأصناف', 'إجمالي القطع', 'عجز (ناقص)', 'زيادة', 'الحالة'], true);

        foreach ($trReportRows as $r) {
            $docLabel = $r['doc_no'] ? ('#' . $r['doc_no'] . '/' . $r['doc_year']) : ('#مسودة-' . $r['id']);
            $statusLabel = match($r['status']) {
                'requested' => 'طلب جديد',
                'preparing' => 'جاري التجهيز',
                'sent'      => 'في الطريق',
                'diff_open' => 'فروق مفتوحة',
                'closed'    => 'مكتمل ومغلق',
                'cancelled' => 'ملغي',
                default     => $r['status'],
            };
            $x->addRow([
                $docLabel,
                $r['from_branch_name'] ?? ('مخزن ' . $r['branch_id']),
                $r['to_branch_name'] ?? ('مخزن ' . $r['counter_branch_id']),
                $r['sent_at'] ?: '—',
                $r['received_at'] ?: '—',
                $r['duration_hours'] !== null ? $r['duration_hours'] : 0,
                $r['lines_count'],
                $r['total_pieces'],
                $r['short_qty'],
                $r['excess_qty'],
                $statusLabel,
            ]);
        }
        $x->download('transfers_' . $from . '_' . $to . '.xlsx');
        exit;
    }
}

// بيانات تقرير «عجوزات التحويلات المستردة»
$trShortRows = [];
if ($type === 'transfer_shorts') {
    require_once __DIR__ . '/stock_transfer_lib.php';
    try {
        $trBranches = $pdo->query("SELECT id, name FROM branches WHERE is_active = 1 AND id <> 5 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        $trBranches = [];
    }
    $trSelBranch = isset($_GET['branch']) && $_GET['branch'] !== '' ? (int)$_GET['branch'] : null;
    $trShortRows = transfer_reports_shorts($pdo, $from, $to, $trSelBranch);

    if ($export) {
        require_once __DIR__ . '/xlsx_lib.php';
        $x = new SimpleXlsx('عجوزات التحويلات المستردة');
        $x->setColumnTypes(['s', 's', 's', 's', 'n', 's', 's', 's']);
        $x->addRow(['تاريخ التحويل', 'رقم التحويل', 'كود الصنف', 'اسم الصنف', 'الكمية المستردة (بالقطعة)', 'من مخزن (المستلم)', 'إلى مخزن (الراسل)', 'الملاحظات'], true);

        foreach ($trShortRows as $r) {
            $docLabel = $r['doc_no'] ? ('#' . $r['doc_no'] . '/' . $r['doc_year']) : ('#' . $r['doc_id']);
            $x->addRow([
                $r['doc_date'],
                $docLabel,
                $r['code'] ?? '',
                $r['item_name'] ?? ('صنف ' . $r['item_id']),
                $r['qty'],
                $r['to_branch_name'] ?? ('مخزن ' . $r['counter_branch_id']),
                $r['from_branch_name'] ?? ('مخزن ' . $r['branch_id']),
                $r['note'] ?: 'تم الرد للراسل',
            ]);
        }
        $x->download('transfer_shorts_' . $from . '_' . $to . '.xlsx');
        exit;
    }
}

// بيانات تقرير «داتا العملاء»
$custRows = []; $custReady = true; $custMsg = ''; $custBranches = []; $custBranchName = '';
if ($type === 'customers') {
    $selBranch = (int)($_GET['branch'] ?? 0);
    $search    = trim((string)($_GET['q'] ?? ''));
    $cData = rep_customers_data($pdo, $from, $to, $selBranch, $search);
    $custReady      = $cData['ready'];
    $custMsg        = $cData['msg'];
    $custBranches   = $cData['branches'];
    $custBranchName = $cData['branch_name'];
    $custRows       = $cData['rows'];

    if ($export && $custReady) {
            require_once __DIR__ . '/xlsx_lib.php';
            $x = new SimpleXlsx('داتا العملاء');
            $x->setColumnTypes(['s', 's', 's', 'n', 's']);
            $x->addRow(['الاسم', 'رقم التليفون', 'الفروع', 'عدد الحجوزات', 'تاريخ آخر حجز'], true);
            foreach ($custRows as $r) {
                $x->addRow([
                    $r['customer_name'],
                    !empty($r['customer_phone']) ? $r['customer_phone'] : '—',
                    $r['branches'] ?: '—',
                    (int)$r['reservations_count'],
                    date('Y-m-d', strtotime($r['last_reservation_date'])),
                ]);
            }
            $x->download('customers_' . $from . '_' . $to . '.xlsx');
            exit;
        }
}

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
    $stkSelSession = (int)($_GET['session'] ?? 0);
    $selSheetsParam = null;
    if (isset($_GET['sheets']) && is_array($_GET['sheets'])) {
        $selSheetsParam = array_map('intval', $_GET['sheets']);
    } elseif (!empty($_GET['sheet_ids'])) {
        $selSheetsParam = array_filter(array_map('intval', explode(',', (string)$_GET['sheet_ids'])));
    }
    $stkData = rep_stocktake_data($stkSelSession, $selSheetsParam);
    $stkSessions   = $stkData['sessions'];
    $stkSheets     = $stkData['sheets'];
    $stkSelSheets  = $stkData['sel_sheets'];
    $stkLines      = $stkData['lines'];

    // تصدير أصناف الجرد (Excel بـ SimpleXlsx)
    if ($export && $stkLines) {
        if (ob_get_length()) ob_end_clean(); // امسح أي output سابق
        require_once __DIR__ . '/xlsx_lib.php';

        $headers = ['المحضر','الكود','اسم الصنف','الوحدة','جرد الجارد','جرد المراجع','النهائية','رصيد السيستم','الفرق','سعر التكلفة','إجمالي التكلفة','القائم بالجرد','المراجع'];

        $x = new SimpleXlsx('أصناف الجرد');
        $x->setColumnTypes(['s', 's', 's', 's', 'n', 'n', 'n', 'n', 'n', 'n', 'n', 's', 's']);
        $x->addRow($headers, true);

        foreach ($stkLines as $l) {
            $base = (float)$l['base_qty'];
            $rev  = $l['review_qty'] !== null ? (float)$l['review_qty'] : null;
            $fin  = $l['final_qty'] !== null ? (float)$l['final_qty'] : ($rev !== null ? $rev : $base);
            $sys  = $l['system_qty'] !== null ? (float)$l['system_qty'] : null;
            $diff = $sys !== null ? ($fin - $sys) : '';
            $cost = $l['cost_price'] !== null ? (float)$l['cost_price'] : null;
            $totCost = $cost !== null ? ($fin * $cost) : '';

            $x->addRow([
                '#' . $l['sheet_no'],
                (string)$l['entered_code'],
                $l['item_name'],
                $l['unit'] ?? '',
                $base,
                $rev !== null ? $rev : '',
                $fin,
                $sys !== null ? $sys : '',
                $diff,
                $cost !== null ? $cost : '',
                $totCost,
                $l['counter_name'] ?? '',
                $l['reviewer_name'] ?? '',
            ]);
        }
        $x->download('stocktake_session_' . $stkSelSession . '.xlsx');
        exit;
    }
}

$page_title    = 'التقارير';
$active_module = 'reports';
sens_page();
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .rep-nav { margin-bottom:18px; }
    .rep-filters { margin-bottom:18px; }
    .rep-filters .ffield { flex:0 1 175px; }
    .btn-csv { background:var(--pos-fill); } .btn-csv:hover { background:var(--pos-fill); }
    .rep-title { font-size:17px; color:var(--ink-900); font-weight:700; margin-bottom:4px; }
    /* كروت الإجماليات — بتتحسب من الصفوف الظاهرة في الجدول */
    .sum-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(135px,1fr));
        gap:10px; margin:14px 0 4px; }
    .sc-label { font-size:12px; color:var(--ink-500); margin-bottom:5px; }
    .sc-value { font-size:19px; font-weight:700; color:var(--ink-900); direction:ltr; text-align:right; }
    .sc-value.neg { color:var(--neg); }
    .sc-value.pos { color:var(--pos); }
    .sum-cards.filtered .sum-box { border-color:var(--accent-text); background:var(--accent-soft); }
    .sc-note { font-size:11px; color:var(--accent-text); margin-top:4px; }
    /* أكورديون تفاصيل القيد */
    tr.acc-row { cursor:pointer; }
    tr.acc-row:hover { background:var(--bg); }
    tr.acc-row.open { background:var(--accent-soft); font-weight:600; }
    .acc-caret { display:inline-block; color:var(--accent-text); font-size:11px;
        transition:transform .15s; }
    tr.acc-row.open .acc-caret { transform:rotate(-90deg); }
    tr.acc-body > td { background:var(--line-soft); padding:0; }
    .acc-inner { padding:12px 16px; }
    .acc-inner table { width:100%; background:var(--surface); border-radius:9px; overflow:hidden; }
    .acc-inner th { background:var(--line-soft); font-size:12px; }
    .acc-inner td, .acc-inner th { padding:7px 10px; font-size:12.5px; }
    .acc-msg { padding:14px; text-align:center; color:var(--ink-400); font-size:13px; }
    tr.dist-row { cursor:pointer; }
    tr.dist-row:hover, tr.dist-row.open { background:var(--accent-soft); }
    tr.dist-body > td { background:var(--line-soft); padding:0; }
    .dist-inner { padding:12px 16px; overflow-x:auto; }
    .dist-item-name { max-width:260px; overflow:hidden; text-overflow:ellipsis; }
    /* أزرار الفروع في تقرير العهدة */
    .br-nav { margin-bottom:16px; }
    .rep-period { font-size:13px; color:var(--ink-500); margin-bottom:16px; }
    /* عمود الملاحظات — نص طويل بيتقص و tooltip بالنص الكامل.
       التصدير بياخد النص كامل (مش من الخلية). */
    td.rep-note { max-width:230px; overflow:hidden; text-overflow:ellipsis;
        white-space:nowrap; color:var(--ink-700); }
    table { width:100%; border-collapse:collapse; }
    th, td { padding:10px 12px; text-align:right; font-size:13px; border-bottom:1px solid var(--line); white-space:nowrap; }
    th { color:var(--ink-500); font-weight:600; }
    td { color:var(--ink-800); }
    .empty { text-align:center; color:var(--ink-400); padding:40px; font-size:15px; }

    /* قائمة وتفصيل الرواتب (Master-Detail) */
    .sal-sum-cards { margin-bottom:16px; }
    .sal-filter-bar { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:14px; }
    .sal-input { flex:1; min-width:160px; }
    .sal-select { min-width:130px; }
    .sal-master-detail { display:grid; grid-template-columns:330px 1fr; gap:16px; align-items:start; }
    @media (max-width: 768px) {
        .sal-master-detail { grid-template-columns:1fr; }
    }
    .sal-master-list { display:flex; flex-direction:column; gap:8px; max-height:750px; overflow-y:auto; padding-left:2px; }
    .sal-item { background:var(--surface); border:1.5px solid var(--line); border-radius:12px; padding:12px 14px; cursor:pointer; transition:border-color .15s, background .15s, box-shadow .15s; user-select:none; }
    .sal-item:hover { border-color:var(--ink-300); background:var(--bg); }
    .sal-item.active { border-color:var(--accent); background:var(--accent-soft); box-shadow:0 0 0 1px var(--accent); }
    .sal-item:focus-visible { outline:2px solid var(--accent); outline-offset:2px; }
    .sal-card-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; gap:8px; }
    .sal-card-name { font-weight:600; color:var(--ink-900); font-size:14px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .sal-card-net { font-size:17px; font-weight:700; margin-bottom:4px; }
    .sal-card-net.pos { color:var(--pos); }
    .sal-card-sub { font-size:11.5px; color:var(--ink-500); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .sal-curr { font-size:11px; font-weight:normal; color:var(--ink-500); }
    .sal-badge-paid { background:var(--pos-soft); color:var(--pos); padding:2px 8px; border-radius:6px; font-size:11.5px; font-weight:600; }
    .sal-badge-draft { background:var(--warn-soft); color:var(--warn); padding:2px 8px; border-radius:6px; font-size:11.5px; font-weight:600; }
    .sal-detail-box { display:flex; flex-direction:column; gap:14px; }
    .sal-detail-box[hidden] { display:none !important; }
    .sal-detail-header { border-bottom:1.5px solid var(--line-soft); padding-bottom:12px; }
    .sal-detail-name-row { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:6px; }
    .sal-detail-name { font-size:18px; font-weight:700; color:var(--ink-900); margin:0; }
    .sal-detail-sub { font-size:12.5px; color:var(--ink-500); margin-bottom:8px; }
    .sal-detail-treasury-row { display:inline-flex; align-items:center; gap:6px; background:var(--line-soft); padding:4px 10px; border-radius:6px; font-size:12.5px; }
    .sal-treasury-label { color:var(--ink-500); }
    .sal-treasury-val { font-weight:600; color:var(--ink-800); }
    .sal-breakdown { display:flex; flex-direction:column; gap:8px; }
    .sal-line { display:flex; align-items:baseline; gap:8px; font-size:13.5px; }
    .sal-line-label { color:var(--ink-700); white-space:nowrap; }
    .sal-line-dots { flex:1; border-bottom:1px dotted var(--ink-300); height:1px; margin:0 4px; }
    .sal-line-val { font-weight:600; color:var(--ink-900); white-space:nowrap; direction:ltr; text-align:left; }
    .sal-line-val.pos { color:var(--pos); }
    .sal-line-val.neg { color:var(--neg); }
    .sal-divider { border-top:1px dashed var(--line); margin:4px 0; }
    .sal-divider-solid { border-top:1.5px solid var(--line); margin:6px 0; }
    .sal-subtotal .sal-line-label { font-weight:600; color:var(--ink-900); }
    .sal-net-row { font-size:16px; margin-top:4px; }
    .sal-net-row .sal-line-label { font-weight:700; color:var(--ink-900); font-size:16px; }
    .sal-net-val { font-size:18px; font-weight:800; }
</style>

<div class="ftabs rep-nav">
    <?php foreach ($reportTypes as $k=>$label): ?>
        <?php if (!isset($allowedTypes[$k])) continue; ?>
        <a href="<?= $k === 'daily' ? 'daily_summary.php' : '?type=' . $k . '&from=' . e($from) . '&to=' . e($to) ?>"
           class="ftab rep-tab <?= $type===$k?'is-active active':'' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<?php
$repEntryGroups = [];
if (in_array($type, ['treasury', 'expenses'], true)) {
    $repEntryCodes = entry_codes_tree();
    $repLabels = rep_account_labels(array_column($repEntryCodes, 'id'));
    foreach ($repEntryCodes as $ec) {
        $root = $repLabels[(int) $ec['id']]['root_name'] ?? (string) $ec['name'];
        $repEntryGroups[$root][] = $ec;
    }
}
?>
<?php if ($type !== 'stocktake' && $type !== 'noprice' && $type !== 'item_movement'): ?>
<form method="get" class="ffilters rep-filters">
    <input type="hidden" name="type" value="<?= e($type) ?>">
    <?php if (($type === 'custody' || $type === 'sales') && isset($_GET['branch']) && $_GET['branch'] !== ''): ?>
        <?php /* الفرع فلتر مستقل عن التاريخ: لازم يفضل معانا لما نغيّر
                 الفترة، وإلا التقرير كان هيرجع لكل الفروع كل مرة. */ ?>
        <input type="hidden" name="branch" value="<?= (int)$_GET['branch'] ?>">
    <?php endif; ?>
    <?php if ($type === 'custody' && ($_GET['view'] ?? '') === 'periods'): ?>
        <input type="hidden" name="view" value="periods">
    <?php endif; ?>
    <div class="ffield">
        <label>من</label>
        <input type="date" name="from" class="finput" value="<?= e($from) ?>">
    </div>
    <div class="ffield">
        <label>إلى</label>
        <input type="date" name="to" class="finput" value="<?= e($to) ?>">
    </div>
    <?php if ($type === 'insta_recon'): ?>
        <label class="ffield-check" style="display:inline-flex;align-items:center;gap:6px;padding-bottom:10px;cursor:pointer;"><input type="checkbox" name="diff_only" value="1" <?= ($_GET['diff_only'] ?? '') === '1' ? 'checked' : '' ?>> المحتاج مراجعة بس</label>
    <?php endif; ?>
    <?php if ($type === 'treasury'): $selEntry = $_GET['entry_code'] ?? ''; ?>
        <?php $repTreasuries = $pdo->query('SELECT id, name FROM treasuries WHERE is_active = 1 ORDER BY name')->fetchAll(); $selTreasury = (int)($_GET['treasury'] ?? 0); ?>
        <div class="ffield">
            <label>الخزينة</label>
            <select name="treasury" class="fselect">
                <option value="">كل الخزائن</option>
                <?php foreach ($repTreasuries as $tr): ?>
                    <option value="<?= (int)$tr['id'] ?>" <?= $selTreasury === (int)$tr['id'] ? 'selected' : '' ?>><?= e($tr['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ffield">
            <label>القيد</label>
            <select name="entry_code" class="fselect">
                <option value="">كل القيود</option>
                <?php foreach ($repEntryGroups as $root => $codes): ?>
                    <optgroup label="<?= e($root) ?>">
                        <?php foreach ($codes as $ec): ?>
                            <option value="<?= (int)$ec['id'] ?>" <?= (string)$selEntry===(string)$ec['id']?'selected':'' ?>><?= e($ec['path']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </div>
        <?php $repCostBranches = cost_branches_list(); $selCostBranch = $_GET['cost_branch'] ?? ''; ?>
        <div class="ffield">
            <label>الفرع</label>
            <select name="cost_branch" class="fselect">
                <option value="">كل الفروع</option>
                <?php foreach ($repCostBranches as $cb): ?>
                    <option value="<?= (int)$cb['id'] ?>" <?= (string)$selCostBranch===(string)$cb['id']?'selected':'' ?>><?= e($cb['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <?php if ($type === 'custody' && !isset($_GET['branch']) && ($_GET['view'] ?? '') !== 'periods'):
        $repCustodyBranches = cost_branches_list(); $selCustodyCostBranch = $_GET['cost_branch'] ?? '';
    ?>
        <div class="ffield">
            <label>فرع المصروف</label>
            <select name="cost_branch" class="fselect">
                <option value="">كل الفروع</option>
                <?php foreach ($repCustodyBranches as $cb): ?>
                    <option value="<?= (int)$cb['id'] ?>" <?= (string)$selCustodyCostBranch === (string)$cb['id'] ? 'selected' : '' ?>><?= e($cb['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <?php if ($type === 'expenses'):
        $repCostBranches = cost_branches_list();
        $selCostBranch   = $_GET['cost_branch'] ?? '';
        $repTreasuries   = $pdo->query("SELECT id, name FROM treasuries WHERE is_active = 1 ORDER BY name")->fetchAll();
        $selTreasury     = $_GET['treasury'] ?? '';
        $selEntry        = $_GET['entry_code'] ?? '';
        $repUsers        = $pdo->query("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name")->fetchAll();
        $selUser         = $_GET['user'] ?? '';
    ?>
        <div class="ffield">
            <label>الفرع</label>
            <select name="cost_branch" class="fselect">
                <option value="">كل الفروع</option>
                <?php foreach ($repCostBranches as $cb): ?>
                    <option value="<?= (int)$cb['id'] ?>" <?= (string)$selCostBranch===(string)$cb['id']?'selected':'' ?>><?= e($cb['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ffield">
            <label>الخزينة</label>
            <select name="treasury" class="fselect">
                <option value="">كل الخزائن</option>
                <?php foreach ($repTreasuries as $tr): ?>
                    <option value="<?= (int)$tr['id'] ?>" <?= (string)$selTreasury===(string)$tr['id']?'selected':'' ?>><?= e($tr['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ffield">
            <label>القيد</label>
            <select name="entry_code" class="fselect">
                <option value="">كل القيود</option>
                <?php foreach ($repEntryGroups as $root => $codes): ?>
                    <optgroup label="<?= e($root) ?>">
                        <?php foreach ($codes as $ec): ?>
                            <option value="<?= (int)$ec['id'] ?>" <?= (string)$selEntry===(string)$ec['id']?'selected':'' ?>><?= e($ec['path']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ffield">
            <label>بواسطة</label>
            <select name="user" class="fselect">
                <option value="">الكل</option>
                <?php foreach ($repUsers as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= (string)$selUser===(string)$u['id']?'selected':'' ?>><?= e($u['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <?php if ($type === 'dist_invoices'):
        $selDistBranch = (int) ($_GET['branch'] ?? 0);
        $selDistSupplier = (int) ($_GET['supplier_id'] ?? 0);
        $selDistStatus = (string) ($_GET['status'] ?? '');
    ?>
        <div class="ffield">
            <label>الفرع</label>
            <select name="branch" class="fselect">
                <option value="">كل الفروع</option>
                <?php foreach ($distFilterOptions['branches'] as $branchOption): ?>
                    <option value="<?= (int) $branchOption['id'] ?>" <?= $selDistBranch === (int) $branchOption['id'] ? 'selected' : '' ?>><?= e($branchOption['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ffield">
            <label>المورد</label>
            <select name="supplier_id" class="fselect">
                <option value="">كل الموردين</option>
                <?php foreach ($distFilterOptions['suppliers'] as $supplierOption): ?>
                    <option value="<?= (int) $supplierOption['id'] ?>" <?= $selDistSupplier === (int) $supplierOption['id'] ? 'selected' : '' ?>><?= e($supplierOption['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ffield">
            <label>الحالة</label>
            <select name="status" class="fselect">
                <option value="">كل الحالات</option>
                <option value="new" <?= $selDistStatus === 'new' ? 'selected' : '' ?>>جديدة</option>
                <option value="split" <?= $selDistStatus === 'split' ? 'selected' : '' ?>>متقسّمة</option>
                <option value="done" <?= $selDistStatus === 'done' ? 'selected' : '' ?>>تمت</option>
            </select>
        </div>
    <?php endif; ?>
    <?php if ($type === 'deficits'): $selDefStatus = $_GET['status'] ?? ''; ?>
        <div class="ffield">
            <label>الحالة</label>
            <select name="status" class="fselect">
                <option value="">الكل</option>
                <option value="within" <?= $selDefStatus==='within'?'selected':'' ?>>داخل العتبة</option>
                <option value="exceeded" <?= $selDefStatus==='exceeded'?'selected':'' ?>>تجاوز العتبة</option>
            </select>
        </div>
    <?php endif; ?>
    <?php if ($type === 'customers'): $selCustBranch = (int)($_GET['branch'] ?? 0); ?>
        <div class="ffield">
            <label>الفرع</label>
            <select name="branch" class="fselect">
                <option value="">كل الفروع</option>
                <?php foreach ($custBranches as $cb): ?>
                    <option value="<?= (int)$cb['id'] ?>" <?= $selCustBranch === (int)$cb['id'] ? 'selected' : '' ?>><?= e($cb['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ffield">
            <label>بحث</label>
            <input type="text" name="q" class="finput" value="<?= e((string)($_GET['q'] ?? '')) ?>" placeholder="الاسم أو التليفون" aria-label="بحث بالاسم أو التليفون">
        </div>
    <?php endif; ?>
    <?php if ($type === 'receiving'):
        $recBranches = $pdo->query("SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name")->fetchAll();
        $selRecBranch = (int)($_GET['branch'] ?? 0);
        $selRecStatus = (string)($_GET['status'] ?? '');
    ?>
        <div class="ffield">
            <label>الفرع</label>
            <select name="branch" class="fselect">
                <option value="">كل الفروع</option>
                <?php foreach ($recBranches as $cb): ?>
                    <option value="<?= (int)$cb['id'] ?>" <?= $selRecBranch === (int)$cb['id'] ? 'selected' : '' ?>><?= e($cb['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ffield">
            <label>الحالة</label>
            <select name="status" class="fselect">
                <option value="">كل الحالات</option>
                <option value="sent" <?= $selRecStatus === 'sent' ? 'selected' : '' ?>>موزعة (بانتظار البدء)</option>
                <option value="receiving" <?= $selRecStatus === 'receiving' ? 'selected' : '' ?>>جاري الاستلام</option>
                <option value="received" <?= $selRecStatus === 'received' ? 'selected' : '' ?>>تم الاستلام (بانتظار الاعتماد)</option>
                <option value="approved" <?= $selRecStatus === 'approved' ? 'selected' : '' ?>>معتمدة</option>
            </select>
        </div>
    <?php endif; ?>
    <?php if ($type === 'supplier_purchases'): ?>
        <div class="ffield">
            <label>الفرع</label>
            <select name="branch" class="fselect">
                <option value="">كل الفروع</option>
                <?php foreach ($spBranches as $cb): ?>
                    <option value="<?= (int)$cb['id'] ?>" <?= $spSelBranch === (int)$cb['id'] ? 'selected' : '' ?>><?= e($cb['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <?php if ($type === 'transfers' || $type === 'transfer_shorts'): ?>
        <div class="ffield">
            <label>الفرع</label>
            <select name="branch" class="fselect">
                <option value="">كل الفروع</option>
                <?php foreach ($trBranches as $tb): ?>
                    <option value="<?= (int)$tb['id'] ?>" <?= (isset($_GET['branch']) && (int)$_GET['branch'] === (int)$tb['id']) ? 'selected' : '' ?>><?= e($tb['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <?php if ($type === 'purchase_docs' || $type === 'purchase_by_item'): ?>
        <?php
        $pdBranch = is_real_owner() ? (int)($_GET['branch'] ?? 0) : (int)($_SESSION['user']['branch_id'] ?? 0);
        $pdSupplier = (int)($_GET['supplier_id'] ?? 0);
        $pdBranches = $pdo->query('SELECT id,name FROM branches WHERE is_active=1 AND id IN (1,2,3,4,6) ORDER BY name')->fetchAll();
        $pdSuppliers = $pdo->query('SELECT id,name FROM suppliers WHERE is_active=1 ORDER BY name')->fetchAll();
        ?>
        <div class="ffield">
            <label>المخزن</label>
            <select name="branch" class="fselect" <?= !is_real_owner()?'disabled':'' ?>>
                <option value="">كل المخازن</option>
                <?php foreach($pdBranches as $pb): if(!is_real_owner() && (int)$pb['id']!==$pdBranch)continue; ?>
                    <option value="<?= (int)$pb['id'] ?>" <?= $pdBranch===(int)$pb['id']?'selected':'' ?>><?= e($pb['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ffield">
            <label>المورد</label>
            <select name="supplier_id" class="fselect">
                <option value="">كل الموردين</option>
                <?php foreach($pdSuppliers as $ps): ?>
                    <option value="<?= (int)$ps['id'] ?>" <?= $pdSupplier===(int)$ps['id']?'selected':'' ?>><?= e($ps['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <?php if ($type === 'treasury' || ($type === 'custody' && isset($_GET['branch']))): ?>
        <label class="ffield-check" style="display:inline-flex;align-items:center;gap:6px;padding-bottom:10px;cursor:pointer;"><input type="checkbox" name="show_cancelled" value="1" <?= ($_GET['show_cancelled'] ?? '') === '1' ? 'checked' : '' ?>> إظهار الملغي</label>
    <?php endif; ?>
    <button type="submit" class="btn"><i class="ti ti-filter"></i> عرض</button>
    <a href="?type=<?= e($type) ?>&from=<?= e($from) ?>&to=<?= e($to) ?><?= $type==='treasury' && !empty($_GET['treasury']) ? '&treasury='.(int)$_GET['treasury'] : '' ?><?= $type==='treasury' && !empty($_GET['entry_code']) ? '&entry_code='.(int)$_GET['entry_code'] : '' ?><?= $type==='treasury' && !empty($_GET['cost_branch']) ? '&cost_branch='.(int)$_GET['cost_branch'] : '' ?><?= $type==='expenses' && !empty($_GET['cost_branch']) ? '&cost_branch='.(int)$_GET['cost_branch'] : '' ?><?= $type==='expenses' && !empty($_GET['treasury']) ? '&treasury='.(int)$_GET['treasury'] : '' ?><?= $type==='expenses' && !empty($_GET['entry_code']) ? '&entry_code='.(int)$_GET['entry_code'] : '' ?><?= $type==='expenses' && !empty($_GET['user']) ? '&user='.(int)$_GET['user'] : '' ?><?= ($type==='custody' || $type==='sales' || $type==='supplier_purchases' || $type==='transfers' || $type==='transfer_shorts' || $type==='purchase_docs' || $type==='purchase_by_item' || $type==='dist_invoices') && !empty($_GET['branch']) ? '&branch='.(int)$_GET['branch'] : '' ?><?= ($type==='purchase_docs' || $type==='purchase_by_item' || $type==='dist_invoices') && !empty($_GET['supplier_id']) ? '&supplier_id='.(int)$_GET['supplier_id'] : '' ?><?= $type==='dist_invoices' && !empty($_GET['status']) ? '&status='.urlencode($_GET['status']) : '' ?><?= $type==='custody' && !empty($_GET['cost_branch']) ? '&cost_branch='.(int)$_GET['cost_branch'] : '' ?><?= $type==='custody' && ($_GET['view'] ?? '') === 'periods' ? '&view=periods' : '' ?><?= ($type==='treasury' || $type==='custody') && ($_GET['show_cancelled'] ?? '') === '1' ? '&show_cancelled=1' : '' ?><?= $type==='deficits' && !empty($_GET['status']) ? '&status='.urlencode($_GET['status']) : '' ?><?= $type==='customers' && !empty($_GET['branch']) ? '&branch='.(int)$_GET['branch'] : '' ?><?= $type==='customers' && trim((string)($_GET['q'] ?? '')) !== '' ? '&q='.urlencode(trim((string)$_GET['q'])) : '' ?><?= $type==='receiving' && !empty($_GET['branch']) ? '&branch='.(int)$_GET['branch'] : '' ?><?= $type==='receiving' && !empty($_GET['status']) ? '&status='.urlencode($_GET['status']) : '' ?><?= $type==='insta_recon' && ($_GET['diff_only'] ?? '') === '1' ? '&diff_only=1' : '' ?>&export=1" class="btn btn-csv">
        <i class="ti ti-download"></i> تصدير إكسل
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
<div class="ftabs br-nav">
    <a href="?<?= $qs ?>" class="ftab br-tab <?= $selBranch===null?'is-active active':'' ?>">كل المبيعات</a>
    <?php foreach ($salesBranches as $sb): ?>
        <a href="?<?= $qs ?>&branch=<?= (int)$sb['id'] ?>"
           class="ftab br-tab <?= $selBranch===(int)$sb['id']?'is-active active':'' ?>"><?= e($sb['name']) ?></a>
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
    $custodyView = (string) ($_GET['view'] ?? '');
    $qs .= ($_GET['show_cancelled'] ?? '') === '1' ? '&show_cancelled=1' : '';
?>
<div class="ftabs br-nav">
    <a href="?<?= $qs ?>" class="ftab br-tab <?= $selBranch===null && $custodyView !== 'periods'?'is-active active':'' ?>">مصروفات العهد حسب الفرع</a>
    <a href="?<?= $qs ?>&view=periods" class="ftab br-tab <?= $selBranch===null && $custodyView === 'periods'?'is-active active':'' ?>">فترات العهدة</a>
    <?php foreach ($repBranches as $rb): ?>
        <a href="?<?= $qs ?>&branch=<?= (int)$rb['id'] ?>"
           class="ftab br-tab <?= $selBranch===(int)$rb['id']?'is-active active':'' ?>"><?= e($rb['name']) ?></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($type === 'stocktake'): ?>
    <!-- تاب الجرد: أرشيف للقراءة فقط -->
    <div class="fcard card">
        <div class="rep-title">أرشيف الجرد (للقراءة فقط)</div>
        <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin-bottom:8px;">
            <input type="hidden" name="type" value="stocktake">
            <div>
                <label style="display:block;font-size:13px;color:var(--ink-500);margin-bottom:4px;">اختر جلسة الجرد</label>
                <select name="session" onchange="formSubmit(this.form)" style="padding:9px 12px;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;min-width:280px;">
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
                <div id="sheetsModal" style="display:none;position:fixed;inset:0;background:rgba(30,27,75,0.45);z-index:var(--z-modal);align-items:center;justify-content:center;">
                    <div style="background:var(--surface);border-radius:16px;padding:24px;max-width:560px;width:92%;max-height:80vh;display:flex;flex-direction:column;box-shadow:0 10px 40px rgba(0,0,0,0.2);">
                        <h3 style="color:var(--ink-900);font-size:17px;margin-bottom:14px;">اختر المحاضر</h3>
                        <div style="display:flex;gap:8px;margin-bottom:12px;">
                            <button type="button" class="btn btn-sm" onclick="toggleAllSheets(true)">اختيار الكل</button>
                            <button type="button" class="btn btn-sm btn-ghost" onclick="toggleAllSheets(false)">إلغاء الكل</button>
                        </div>
                        <div class="ftable-wrap" style="overflow-y:auto;flex:1;border:1.5px solid var(--line);border-radius:10px;">
                            <table class="ftable" style="width:100%;border-collapse:collapse;">
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
    <div class="fcard card">
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
            <div>
                <a href="?<?= $expQs ?>" class="btn btn-green" style="text-decoration:none;"><i class="ti ti-file-spreadsheet"></i> تصدير Excel</a>
            </div>
        </div>
        <div class="ftable-wrap">
        <table class="ftable">
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
                        <td style="font-weight:600;color:var(--accent-text);"><?= $fmt($fin) ?></td>
                        <td><?= $fmt($sys) ?></td>
                        <td style="color:<?= $diff!==null&&$diff<0?'var(--neg)':($diff>0?'var(--pos)':'var(--ink-500)') ?>;"><?= $fmt($diff) ?></td>
                        <td><?= $cost!==null?number_format($cost,2):'—' ?></td>
                        <td style="color:var(--ink-500);font-size:12px;"><?= e($l['counter_name'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p style="color:var(--ink-500);font-size:12px;margin-top:10px;">عرض للقراءة فقط. للتعديل، استخدم زر "إرجاع للمراجعة" من صفحة الجرد (للطوارئ).</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

<?php elseif ($type === 'noprice'): ?>
<?php /* ===== تقرير «أصناف بدون سعر بيع» — الواجهة ===== */ ?>
<?php if (!$npReady): ?>
    <div class="fcard card">
        <div class="empty" style="color:var(--neg);"><?= e($npMsg) ?></div>
    </div>
<?php else: ?>

<style>
    .np-bar { background:var(--surface); border-radius:12px; padding:14px 16px; margin-bottom:14px;
        display:flex; gap:10px; align-items:center; flex-wrap:wrap;
        box-shadow:0 2px 10px rgba(124,111,247,0.06); }
    .np-search-wrap { position:relative; flex:1 1 280px; min-width:220px; }
    .np-sugg { position:absolute; top:calc(100% + 4px); right:0; left:0; z-index:40;
        background:var(--surface); border:1.5px solid var(--line); border-radius:10px;
        max-height:300px; overflow-y:auto; box-shadow:0 6px 20px rgba(0,0,0,0.12); display:none; }
    .np-sugg div { padding:9px 12px; cursor:pointer; font-size:13px; border-bottom:1px solid var(--line-soft); }
    .np-sugg div:last-child { border-bottom:none; }
    .np-sugg div:hover, .np-sugg div.on { background:var(--bg); }
    .np-sugg small { color:var(--ink-500); }

    .np-table td, .np-table th { white-space:nowrap; }
    .np-table td.np-name { white-space:normal; min-width:180px; }
    .np-table .finput { width:100px; text-align:left; direction:ltr; }
    .np-table .np-dirty { border-color:var(--pos); }
    .np-table tr.np-nocost td { background:var(--line-soft); }
    .np-x { background:none; border:none; cursor:pointer; color:var(--neg); font-size:16px; padding:2px 6px; }

    .np-modal { position:fixed; inset:0; background:rgba(20,16,50,0.55); z-index:200;
        display:none; align-items:center; justify-content:center; padding:16px; }
    .np-modal.on { display:flex; }
    .np-box { background:var(--surface); border-radius:14px; width:min(820px,100%); max-height:88vh;
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
    .btn-ghost { background:var(--surface); color:var(--accent-text); border:1.5px solid var(--line); }
    .btn-ghost:hover { background:var(--bg); }
    .np-note { font-size:12px; color:var(--ink-500); margin-top:10px; line-height:1.7; }
</style>

<!-- ===================== الشريط العلوي ===================== -->
<div class="np-bar">
    <div class="np-search-wrap">
        <input type="text" id="npSearch" class="finput" placeholder="ابحث بالكود أو اسم الصنف وأضفه…" autocomplete="off">
        <div class="np-sugg" id="npSugg"></div>
    </div>

    <label style="font-size:13px;color:var(--ink-500);">نسبة موحدة</label>
    <input type="number" id="npPct" step="0.5" class="finput finput-sm" style="width:90px;" placeholder="%">
    <button class="btn" id="npApply"><i class="ti ti-percentage"></i> تطبيق النسبة</button>

    <button class="btn btn-ghost" id="npZeroBtn"><i class="ti ti-filter"></i> أصناف أسعار بيعها 0</button>
</div>

<!-- ===================== الجدول ===================== -->
<div class="fcard card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px;">
        <div class="rep-title" style="margin:0;">
            أصناف بدون سعر بيع <span class="np-muted" id="npCount"></span>
        </div>
        <div id="npStatus" class="np-muted"></div>
    </div>

    <div class="ftable-wrap">
        <table class="ftable np-table">
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
        <i class="ti ti-alert-triangle"></i> السعر ده بيتحفظ <b>عندنا بس</b> — زي الاستلامات بالظبط. عشان يوصل نظام الشركة،
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
                '<td><input type="number" step="0.01" min="0" class="finput finput-sm np-price" ' +
                    'data-code="' + esc(r.code) + '" value="' + money(r.sale) + '"></td>' +
                '<td><input type="number" step="0.5" class="finput finput-sm np-pct" ' +
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

<?php elseif ($type === 'customers'): ?>
<?php /* ===== تقرير «داتا العملاء» — الواجهة (سطر لكل تليفون، والاسم وآخر تاريخ من نفس آخر حجز) ===== */ ?>
<div class="fcard card">
    <div class="rep-title">داتا العملاء<?= $custBranchName !== '' ? ' — ' . e($custBranchName) : '' ?></div>
    <div class="rep-period">من <?= e($from) ?> إلى <?= e($to) ?> · <?= number_format(count($custRows)) ?> عميل</div>
    <?php if (!$custReady): ?>
        <div class="empty" style="color:var(--neg);"><?= e($custMsg) ?></div>
    <?php elseif (!$custRows): ?>
        <div class="empty">مفيش حجوزات في الفترة دي.</div>
    <?php else: ?>
    <div class="ftable-wrap">
    <table class="ftable">
        <thead><tr>
            <th>الاسم</th><th>رقم التليفون</th><th>الفروع</th><th>عدد الحجوزات</th><th>تاريخ آخر حجز</th>
        </tr></thead>
        <tbody>
        <?php foreach ($custRows as $r): ?>
            <tr>
                <td><?= e((string)$r['customer_name']) ?></td>
                <td dir="ltr" style="text-align:right;"><?= $r['customer_phone'] !== '' ? e($r['customer_phone']) : '—' ?></td>
                <td><?= e((string)($r['branches'] ?: '—')) ?></td>
                <td><?= (int)$r['reservations_count'] ?></td>
                <td><?= e(date('Y-m-d', strtotime((string)$r['last_reservation_date']))) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($type === 'supplier_purchases'): ?>
<?php /* ===== تقرير «مشتريات مورد» (بريف ١٢٥) ===== */ ?>
<div class="fcard card">
    <div class="rep-title">تقرير مشتريات مورد<?= $spSelBranch !== null ? ' — ' . e(array_column($spBranches, 'name', 'id')[$spSelBranch] ?? 'فرع ' . $spSelBranch) : ' — كل الفروع' ?></div>
    <div class="rep-period">من <?= e($from) ?> إلى <?= e($to) ?> · <?= number_format($spReport['totals']['count']) ?> مورد</div>

    <?php if (empty($spReport['rows'])): ?>
        <div class="empty">لا توجد بيانات في هذه الفترة.</div>
    <?php else: ?>
        <div class="ftable-wrap">
            <table class="ftable">
                <thead>
                    <tr>
                        <th class="num">كود المورد</th>
                        <th>اسم المورد</th>
                        <th style="text-align:center;">نوع المورد</th>
                        <th class="num">إجمالي مشتريات</th>
                        <th class="num">مردودات مشتريات</th>
                        <th class="num">إجمالي الخصومات</th>
                        <th class="num">الصافي</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($spReport['rows'] as $r): ?>
                        <tr>
                            <td class="num"><?= e($r['code']) ?></td>
                            <td class="name" title="<?= e($r['name']) ?>"><?= e($r['name']) ?></td>
                            <td style="text-align:center;">
                                <?php if ($r['payment_type'] === 'cash'): ?>
                                    <span class="fchip fchip-pos">كاش</span>
                                <?php elseif ($r['payment_type'] === 'credit'): ?>
                                    <span class="fchip fchip-warn">آجل</span>
                                <?php else: ?>
                                    <span class="dim">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="num"><?= number_format($r['purchases'], 2) ?></td>
                            <td class="num" style="<?= $r['returns'] > 0 ? 'color:var(--neg);' : '' ?>">
                                <?= $r['returns'] > 0 ? '(' . number_format($r['returns'], 2) . ')' : '0.00' ?>
                            </td>
                            <td class="num dim"><?= number_format($r['discounts'], 2) ?></td>
                            <td class="num" style="font-weight:600;<?= $r['net'] < 0 ? 'color:var(--neg);' : '' ?>">
                                <?= $r['net'] < 0 ? '(' . number_format(abs($r['net']), 2) . ')' : number_format($r['net'], 2) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <?php $tot = $spReport['totals']; ?>
                    <tr style="background:var(--bg);font-weight:700;">
                        <td class="num">الإجمالي</td>
                        <td class="name"><?= number_format($tot['count']) ?> مورد</td>
                        <td></td>
                        <td class="num"><?= number_format($tot['purchases'], 2) ?></td>
                        <td class="num" style="<?= $tot['returns'] > 0 ? 'color:var(--neg);' : '' ?>">
                            <?= $tot['returns'] > 0 ? '(' . number_format($tot['returns'], 2) . ')' : '0.00' ?>
                        </td>
                        <td class="num dim"><?= number_format($tot['discounts'], 2) ?></td>
                        <td class="num" style="<?= $tot['net'] < 0 ? 'color:var(--neg);' : '' ?>">
                            <?= $tot['net'] < 0 ? '(' . number_format(abs($tot['net']), 2) . ')' : number_format($tot['net'], 2) ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php elseif ($type === 'transfers'): ?>
<?php /* ===== تقرير «التحويلات بين المخازن» (بريف ٢١٥) ===== */ ?>
<div class="fcard card">
    <div class="rep-title">تقرير التحويلات بين المخازن<?= $trSelBranch !== null ? ' — ' . e(array_column($trBranches, 'name', 'id')[$trSelBranch] ?? 'مخزن ' . $trSelBranch) : ' — كل المخازن' ?></div>
    <div class="rep-period">من <?= e($from) ?> إلى <?= e($to) ?> · <?= number_format(count($trReportRows)) ?> تحويل</div>

    <?php if (empty($trReportRows)): ?>
        <div class="empty">لا توجد تحويلات مسجلة في هذه الفترة.</div>
    <?php else: ?>
        <div class="ftable-wrap">
            <table class="ftable">
                <thead>
                    <tr>
                        <th class="num">التحويل</th>
                        <th>من مخزن</th>
                        <th>إلى مخزن</th>
                        <th>تاريخ الإرسال</th>
                        <th>تاريخ الاستلام</th>
                        <th class="num">المدة (ساعات)</th>
                        <th class="num">عدد الأصناف</th>
                        <th class="num">إجمالي القطع</th>
                        <th class="num">عجز (ناقص)</th>
                        <th class="num">زيادة</th>
                        <th style="text-align:center;">الحالة</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $totPieces = 0.0;
                    $totShort = 0.0;
                    $totExcess = 0.0;
                    foreach ($trReportRows as $r):
                        $totPieces += (float) $r['total_pieces'];
                        $totShort += (float) $r['short_qty'];
                        $totExcess += (float) $r['excess_qty'];
                        $docLabel = $r['doc_no'] ? ('#' . $r['doc_no'] . '/' . $r['doc_year']) : ('#مسودة-' . $r['id']);
                        $statusChipClass = match($r['status']) {
                            'sent'      => 'fchip-warn',
                            'closed'    => 'fchip-pos',
                            'diff_open' => 'fchip-neg',
                            'cancelled' => 'fchip-dim',
                            default     => 'fchip-mute',
                        };
                        $statusLabel = match($r['status']) {
                            'requested' => 'طلب جديد',
                            'preparing' => 'جاري التجهيز',
                            'sent'      => 'في الطريق',
                            'diff_open' => 'فروق مفتوحة',
                            'closed'    => 'مكتمل ومغلق',
                            'cancelled' => 'ملغي',
                            default     => $r['status'],
                        };
                    ?>
                        <tr>
                            <td class="num"><a href="transfers.php?id=<?= (int)$r['id'] ?>" style="font-weight:600;"><?= e($docLabel) ?></a></td>
                            <td><?= e($r['from_branch_name'] ?? ('مخزن ' . $r['branch_id'])) ?></td>
                            <td><?= e($r['to_branch_name'] ?? ('مخزن ' . $r['counter_branch_id'])) ?></td>
                            <td><?= $r['sent_at'] ? e($r['sent_at']) : '<span style="color:var(--ink-400);">—</span>' ?></td>
                            <td><?= $r['received_at'] ? e($r['received_at']) : '<span style="color:var(--ink-400);">—</span>' ?></td>
                            <td class="num"><?= $r['duration_hours'] !== null ? number_format((float)$r['duration_hours'], 2) : '—' ?></td>
                            <td class="num"><?= number_format((int)$r['lines_count']) ?></td>
                            <td class="num"><?= number_format((float)$r['total_pieces'], 3) ?></td>
                            <td class="num" style="<?= (float)$r['short_qty'] > 0 ? 'color:var(--neg);font-weight:600;' : '' ?>">
                                <?= (float)$r['short_qty'] > 0 ? number_format((float)$r['short_qty'], 3) : '0' ?>
                            </td>
                            <td class="num" style="<?= (float)$r['excess_qty'] > 0 ? 'color:var(--pos);font-weight:600;' : '' ?>">
                                <?= (float)$r['excess_qty'] > 0 ? number_format((float)$r['excess_qty'], 3) : '0' ?>
                            </td>
                            <td style="text-align:center;">
                                <span class="fchip <?= $statusChipClass ?>"><?= e($statusLabel) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:var(--bg);font-weight:700;">
                        <td class="num">الإجمالي</td>
                        <td><?= count($trReportRows) ?> تحويل</td>
                        <td colspan="5"></td>
                        <td class="num"><?= number_format($totPieces, 3) ?></td>
                        <td class="num" style="<?= $totShort > 0 ? 'color:var(--neg);' : '' ?>"><?= number_format($totShort, 3) ?></td>
                        <td class="num" style="<?= $totExcess > 0 ? 'color:var(--pos);' : '' ?>"><?= number_format($totExcess, 3) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php elseif ($type === 'transfer_shorts'): ?>
<?php /* ===== تقرير «عجوزات التحويلات المستردة» (بريف ٢١٥) ===== */ ?>
<div class="fcard card">
    <div class="rep-title">تقرير عجز التحويلات المسترد تلقائياً<?= $trSelBranch !== null ? ' — ' . e(array_column($trBranches, 'name', 'id')[$trSelBranch] ?? 'مخزن ' . $trSelBranch) : ' — كل المخازن' ?></div>
    <div class="rep-period">من <?= e($from) ?> إلى <?= e($to) ?> · <?= number_format(count($trShortRows)) ?> حالة عجز مستردة</div>

    <?php if (empty($trShortRows)): ?>
        <div class="empty">لا توجد حالات عجز مستردة في هذه الفترة.</div>
    <?php else: ?>
        <div class="ftable-wrap">
            <table class="ftable">
                <thead>
                    <tr>
                        <th>تاريخ التحويل</th>
                        <th class="num">التحويل</th>
                        <th>كود الصنف</th>
                        <th>اسم الصنف</th>
                        <th class="num">الكمية المستردة (بالقطعة)</th>
                        <th>من مخزن (المستلم)</th>
                        <th>إلى مخزن (الراسل)</th>
                        <th>الملاحظات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $totShortQty = 0.0;
                    foreach ($trShortRows as $r):
                        $totShortQty += (float) $r['qty'];
                        $docLabel = $r['doc_no'] ? ('#' . $r['doc_no'] . '/' . $r['doc_year']) : ('#' . $r['doc_id']);
                    ?>
                        <tr>
                            <td><?= e($r['doc_date']) ?></td>
                            <td class="num"><a href="transfers.php?id=<?= (int)$r['doc_id'] ?>" style="font-weight:600;"><?= e($docLabel) ?></a></td>
                            <td><?= e($r['code'] ?? '') ?></td>
                            <td class="name" title="<?= e($r['item_name'] ?? '') ?>"><?= e($r['item_name'] ?? ('صنف ' . $r['item_id'])) ?></td>
                            <td class="num" style="color:var(--pos);font-weight:600;"><?= number_format((float)$r['qty'], 3) ?></td>
                            <td><?= e($r['to_branch_name'] ?? ('مخزن ' . $r['counter_branch_id'])) ?></td>
                            <td><?= e($r['from_branch_name'] ?? ('مخزن ' . $r['branch_id'])) ?></td>
                            <td style="color:var(--ink-400);font-size:12px;"><?= e($r['note'] ?: 'تم الرد للراسل') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:var(--bg);font-weight:700;">
                        <td class="num">الإجمالي</td>
                        <td><?= count($trShortRows) ?> حالة</td>
                        <td colspan="2"></td>
                        <td class="num" style="color:var(--pos);"><?= number_format($totShortQty, 3) ?></td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php elseif ($type === 'item_movement'): ?>
    <?php require __DIR__ . '/includes/item_movement_view.php'; ?>

<?php else: ?>
<div class="fcard card">
    <div class="rep-title"><?= e($report['title']) ?></div>
    <div class="rep-period">من <?= e($from) ?> إلى <?= e($to) ?></div>
    <?php if ($type === 'insta_recon'): ?>
        <div style="margin:10px 0;color:var(--ink-600);font-size:13px;">
            <?= count($report['unmatched_sms']) ?> رسالة واردة من غير مطابقة مؤكدة خلال الفترة؛ ممكن تكون متكررة أو مالهاش عملية في الوردية.
        </div>
    <?php endif; ?>
    <?php if (!empty($report['note'])): ?>
        <div class="empty"><?= e($report['note']) ?></div>
    <?php endif; ?>

    <?php if ($type === 'salaries' && !sens_unlocked()): ?>
        <?php sens_lock_screen('تقرير الرواتب'); ?>
    <?php elseif (($report['layout'] ?? '') === 'master_detail'): ?>
        <?php if (($report['report_subtype'] ?? '') === 'advances'): ?>
            <?php if (empty($report['details'])): ?>
                <div class="empty">لا توجد بيانات في هذه الفترة.</div>
            <?php else: ?>
                <div class="sum-cards sal-sum-cards" id="advSummaryCards">
                    <div class="fcard sum-box sum-card">
                        <div class="sc-label">عدد الموظفين</div>
                        <div class="sc-value" id="advCountCard"><?= number_format($report['count']) ?></div>
                    </div>
                    <div class="fcard sum-box sum-card">
                        <div class="sc-label">إجمالي المتبقي</div>
                        <div class="sc-value" id="advRemainingCard" style="color:var(--ink-900);"><?= number_format($report['total_remaining'], 2) ?></div>
                    </div>
                </div>

                <div class="sal-filter-bar">
                    <input type="text" id="advSearch" class="finput sal-input" placeholder="بحث باسم الموظف..." autocomplete="off">
                    <select id="advBranchFilter" class="fselect sal-select">
                        <option value="">كل الفروع</option>
                        <?php foreach ($report['branches'] as $br): ?>
                            <option value="<?= e($br) ?>"><?= e($br) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="advTypeFilter" class="fselect sal-select">
                        <option value="">كل الأنواع</option>
                        <option value="monthly">سلفة شهر</option>
                        <option value="standing">مستديمة</option>
                    </select>
                    <select id="advStatusFilter" class="fselect sal-select">
                        <option value="">كل الحالات</option>
                        <option value="pending">معلّقة</option>
                        <option value="deducted">اتخصمت</option>
                        <option value="cancelled">ملغاة</option>
                    </select>
                </div>

                <div class="sal-master-detail">
                    <!-- القائمة (يمين) -->
                    <div class="sal-master-list" id="advMasterList">
                        <?php foreach ($report['details'] as $idx => $d): ?>
                            <div class="fcard sal-item sal-card <?= $idx === 0 ? 'is-active active' : '' ?>"
                                 data-id="<?= $d['id'] ?>"
                                 data-name="<?= e($d['full_name']) ?>"
                                 data-branch="<?= e($d['branch_name']) ?>"
                                 data-remaining="<?= $d['total_remaining'] ?>"
                                 data-pending="<?= $d['pending_count'] ?>"
                                 tabindex="0"
                                 role="button"
                                 aria-pressed="<?= $idx === 0 ? 'true' : 'false' ?>">
                                <div class="sal-card-header">
                                    <div class="sal-card-name"><?= e($d['full_name']) ?></div>
                                    <?php if ($d['pending_count'] > 0): ?>
                                        <span class="badge" style="background:var(--warn-soft);color:var(--warn);padding:2px 8px;border-radius:6px;font-size:11.5px;font-weight:600;">
                                            <?= $d['pending_count'] ?> معلّقة
                                        </span>
                                    <?php else: ?>
                                        <span class="badge" style="background:var(--pos-soft);color:var(--pos);padding:2px 8px;border-radius:6px;font-size:11.5px;font-weight:600;">
                                            لا توجد معلّقة
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="sal-card-net" style="font-size:17px;font-weight:700;color:var(--ink-900);margin-bottom:4px;">
                                    <span class="adv-card-emp-rem"><?= e($d['total_remaining_fmt']) ?></span> <span class="sal-curr">ج.م</span>
                                </div>
                                <?php if ($d['branch_name'] !== '—'): ?>
                                    <div class="sal-card-sub">
                                        <?= e($d['branch_name']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <div id="advEmptyList" class="empty" style="display:none;padding:24px 12px;">
                            مفيش موظف مطابق للفلتر.
                        </div>
                    </div>

                    <!-- التفصيل (شمال) -->
                    <div class="fcard sal-detail-panel" id="advDetailPanel">
                        <?php foreach ($report['details'] as $idx => $d): ?>
                            <div class="sal-detail-box sal-detail-card" id="advDetail_<?= $d['id'] ?>" <?= $idx === 0 ? '' : 'hidden' ?>>
                                <div class="sal-detail-header">
                                    <div class="sal-detail-name-row">
                                        <h3 class="sal-detail-name"><?= e($d['full_name']) ?></h3>
                                        <?php if ($d['branch_name'] !== '—'): ?>
                                            <span class="badge" style="background:var(--line-soft);color:var(--ink-700);padding:4px 10px;border-radius:6px;font-size:12.5px;">
                                                <?= e($d['branch_name']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="sal-detail-sub">
                                        عدد السلف: <?= count($d['advances']) ?> · المعلّقة: <?= $d['pending_count'] ?>
                                    </div>
                                </div>

                                <div class="ftable-wrap">
                                    <table class="ftable" style="width:100%;border-collapse:collapse;font-size:13px;margin-top:8px;">
                                        <thead>
                                            <tr style="background:var(--line-soft);">
                                                <th style="padding:8px 10px;text-align:right;">التاريخ</th>
                                                <th style="padding:8px 10px;text-align:right;">النوع</th>
                                                <th style="padding:8px 10px;text-align:right;">الأصل</th>
                                                <th style="padding:8px 10px;text-align:right;">المتبقي</th>
                                                <th style="padding:8px 10px;text-align:right;">القسط الشهري</th>
                                                <th style="padding:8px 10px;text-align:right;">الحالة</th>
                                                <th style="padding:8px 10px;text-align:right;">الملاحظات</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($d['advances'] as $adv): ?>
                                                <tr class="adv-item-row"
                                                    data-type="<?= e($adv['adv_type']) ?>"
                                                    data-status="<?= e($adv['status']) ?>"
                                                    data-remaining="<?= $adv['remaining'] ?>">
                                                    <td style="padding:9px 10px;"><?= e($adv['advance_date']) ?></td>
                                                    <td style="padding:9px 10px;">
                                                        <span class="badge" style="background:<?= $adv['adv_type'] === 'monthly' ? 'var(--accent-soft)' : 'var(--line-soft)' ?>;color:<?= $adv['adv_type'] === 'monthly' ? 'var(--accent-text)' : 'var(--ink-700)' ?>;">
                                                            <?= e($adv['type_label']) ?>
                                                        </span>
                                                    </td>
                                                    <td style="padding:9px 10px;font-variant-numeric:tabular-nums;direction:ltr;text-align:right;"><?= e($adv['amount_fmt']) ?></td>
                                                    <td style="padding:9px 10px;font-variant-numeric:tabular-nums;font-weight:600;direction:ltr;text-align:right;color:var(--ink-900);"><?= e($adv['remaining_fmt']) ?></td>
                                                    <td style="padding:9px 10px;font-variant-numeric:tabular-nums;direction:ltr;text-align:right;"><?= e($adv['monthly_deduction_fmt']) ?></td>
                                                    <td style="padding:9px 10px;">
                                                        <?php if ($adv['status'] === 'pending'): ?>
                                                            <span class="badge" style="background:var(--warn-soft);color:var(--warn);">معلّقة</span>
                                                        <?php elseif ($adv['status'] === 'deducted'): ?>
                                                            <span class="badge" style="background:var(--pos-soft);color:var(--pos);">اتخصمت</span>
                                                        <?php else: ?>
                                                            <span class="badge" style="background:var(--bg);color:var(--ink-400);border:1px solid var(--line);">ملغاة</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="padding:9px 10px;color:var(--ink-600);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= e($adv['notes']) ?>">
                                                        <?= e($adv['notes'] ?: '—') ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="sal-divider-solid"></div>

                                <div class="sal-line sal-net-row" style="margin-top:8px;">
                                    <span class="sal-line-label" style="font-size:15px;">إجمالي المتبقي</span>
                                    <span class="sal-line-dots"></span>
                                    <span class="sal-line-val sal-net-val" style="color:var(--ink-900);font-size:18px;">
                                        <span class="adv-detail-total-rem"><?= e($d['total_remaining_fmt']) ?></span> <span class="sal-curr">ج.م</span>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <div id="advDetailEmpty" class="sal-detail-box sal-detail-card" hidden>
                            <div class="empty" style="padding:40px 10px;">مفيش موظف مطابق للفلتر.</div>
                        </div>
                    </div>
                </div>

                <script>
                (function () {
                    var masterList = document.getElementById('advMasterList');
                    if (!masterList) return;
                    var cards = Array.from(masterList.querySelectorAll('.sal-card'));
                    var searchInput = document.getElementById('advSearch');
                    var branchSelect = document.getElementById('advBranchFilter');
                    var typeSelect = document.getElementById('advTypeFilter');
                    var statusSelect = document.getElementById('advStatusFilter');
                    var countEl = document.getElementById('advCountCard');
                    var remEl = document.getElementById('advRemainingCard');
                    var summaryBox = document.getElementById('advSummaryCards');
                    var emptyListEl = document.getElementById('advEmptyList');
                    var emptyDetailEl = document.getElementById('advDetailEmpty');
                    var detailPanel = document.getElementById('advDetailPanel');

                    function norm(str) {
                        return String(str || '').toLowerCase()
                            .replace(/[أإآ]/g, 'ا')
                            .replace(/ة/g, 'ه')
                            .replace(/ى/g, 'ي')
                            .replace(/[\u064B-\u065F]/g, '')
                            .trim();
                    }

                    function selectCard(card) {
                        cards.forEach(function (c) {
                            c.classList.remove('active');
                            c.classList.remove('is-active');
                            c.setAttribute('aria-pressed', 'false');
                        });
                        if (detailPanel) {
                            var allDetails = detailPanel.querySelectorAll('.sal-detail-card');
                            allDetails.forEach(function (d) { d.hidden = true; });
                        }

                        if (!card) {
                            if (emptyDetailEl) emptyDetailEl.hidden = false;
                            return;
                        }

                        card.classList.add('active');
                        card.classList.add('is-active');
                        card.setAttribute('aria-pressed', 'true');
                        var targetId = 'advDetail_' + card.dataset.id;
                        var targetDetail = document.getElementById(targetId);
                        if (targetDetail) {
                            targetDetail.hidden = false;
                        }
                        if (emptyDetailEl) emptyDetailEl.hidden = true;
                    }

                    function applyFilter() {
                        var q = searchInput ? norm(searchInput.value) : '';
                        var br = branchSelect ? (branchSelect.value || '').trim() : '';
                        var tp = typeSelect ? (typeSelect.value || '').trim() : '';
                        var st = statusSelect ? (statusSelect.value || '').trim() : '';

                        var visibleCount = 0;
                        var visibleRemaining = 0;
                        var firstVisible = null;
                        var activeVisible = false;

                        cards.forEach(function (card) {
                            var cardName = norm(card.dataset.name);
                            var cardBr = card.dataset.branch || '';

                            var matchName = !q || cardName.indexOf(q) !== -1;
                            var matchBr = !br || cardBr === br;

                            // فلترة بنود السلف لهذا الموظف
                            var targetId = 'advDetail_' + card.dataset.id;
                            var targetDetail = document.getElementById(targetId);
                            var empMatchingRem = 0;
                            var empMatchingCount = 0;

                            if (targetDetail) {
                                var itemRows = targetDetail.querySelectorAll('.adv-item-row');
                                itemRows.forEach(function (row) {
                                    var rowType = row.dataset.type || '';
                                    var rowStatus = row.dataset.status || '';
                                    var rowRem = parseFloat(row.dataset.remaining || '0');

                                    var matchType = !tp || rowType === tp;
                                    var matchStatus = !st || rowStatus === st;

                                    if (matchType && matchStatus) {
                                        row.hidden = false;
                                        empMatchingCount++;
                                        empMatchingRem += rowRem;
                                    } else {
                                        row.hidden = true;
                                    }
                                });

                                var detailRemEl = targetDetail.querySelector('.adv-detail-total-rem');
                                if (detailRemEl) {
                                    detailRemEl.textContent = empMatchingRem.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                                }
                            }

                            // الموظف يظهر لو طابق الاسم والفرع وعنده سلف مطابقة لفلتر النوع والحالة
                            var match = matchName && matchBr && empMatchingCount > 0;
                            card.hidden = !match;

                            if (match) {
                                visibleCount++;
                                visibleRemaining += empMatchingRem;
                                var cardRemEl = card.querySelector('.adv-card-emp-rem');
                                if (cardRemEl) {
                                    cardRemEl.textContent = empMatchingRem.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                                }
                                if (!firstVisible) firstVisible = card;
                                if (card.classList.contains('active')) activeVisible = true;
                            }
                        });

                        if (countEl) countEl.textContent = visibleCount.toLocaleString('en-US');
                        if (remEl) remEl.textContent = visibleRemaining.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

                        var isFiltered = Boolean(q || br || tp || st);
                        if (summaryBox) {
                            if (isFiltered) summaryBox.classList.add('filtered');
                            else summaryBox.classList.remove('filtered');
                        }

                        if (visibleCount === 0) {
                            if (emptyListEl) emptyListEl.style.display = 'block';
                            selectCard(null);
                        } else {
                            if (emptyListEl) emptyListEl.style.display = 'none';
                            if (!activeVisible && firstVisible) {
                                selectCard(firstVisible);
                            }
                        }
                    }

                    masterList.addEventListener('click', function (e) {
                        var card = e.target.closest('.sal-card');
                        if (!card || card.hidden) return;
                        selectCard(card);
                    });

                    masterList.addEventListener('keydown', function (e) {
                        if (e.key === 'Enter' || e.key === ' ') {
                            var card = e.target.closest('.sal-card');
                            if (card && !card.hidden) {
                                e.preventDefault();
                                selectCard(card);
                            }
                        }
                    });

                    if (searchInput) searchInput.addEventListener('input', applyFilter);
                    if (branchSelect) branchSelect.addEventListener('change', applyFilter);
                    if (typeSelect) typeSelect.addEventListener('change', applyFilter);
                    if (statusSelect) statusSelect.addEventListener('change', applyFilter);
                })();
                </script>
            <?php endif; ?>
        <?php else: ?>
        <?php if (empty($report['details'])): ?>
            <div class="empty">لا توجد بيانات في هذه الفترة.</div>
        <?php else: ?>
            <div class="sum-cards sal-sum-cards" id="salSummaryCards">
                <div class="fcard sum-box sum-card">
                    <div class="sc-label">عدد الموظفين</div>
                    <div class="sc-value" id="salCountCard"><?= number_format($report['count']) ?></div>
                </div>
                <div class="fcard sum-box sum-card">
                    <div class="sc-label">إجمالي الصافي</div>
                    <div class="sc-value pos" id="salNetCard"><?= number_format($report['total_net'], 2) ?></div>
                </div>
            </div>

            <div class="sal-filter-bar">
                <input type="text" id="salSearch" class="finput sal-input" placeholder="بحث باسم الموظف..." autocomplete="off">
                <select id="salTreasuryFilter" class="fselect sal-select">
                    <option value="">كل الخزائن</option>
                    <?php foreach ($report['treasuries'] as $tr): ?>
                        <option value="<?= e($tr) ?>"><?= e($tr) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="salStatusFilter" class="fselect sal-select">
                    <option value="">كل الحالات</option>
                    <option value="paid">مدفوع</option>
                    <option value="draft">مسودة</option>
                </select>
            </div>

            <div class="sal-master-detail">
                <!-- القائمة (يمين) -->
                <div class="sal-master-list" id="salMasterList">
                    <?php foreach ($report['details'] as $idx => $d): ?>
                        <div class="fcard sal-item sal-card <?= $idx === 0 ? 'is-active active' : '' ?>"
                             data-id="<?= $d['id'] ?>"
                             data-name="<?= e($d['full_name']) ?>"
                             data-treasury="<?= e($d['treasury_name']) ?>"
                             data-status="<?= e($d['status']) ?>"
                             data-net="<?= $d['net_salary'] ?>"
                             tabindex="0"
                             role="button"
                             aria-pressed="<?= $idx === 0 ? 'true' : 'false' ?>">
                            <div class="sal-card-header">
                                <div class="sal-card-name"><?= e($d['full_name']) ?></div>
                                <span class="badge sal-badge-<?= e($d['status']) ?>">
                                    <?= e($d['status_label']) ?>
                                </span>
                            </div>
                            <div class="sal-card-net pos"><?= e($d['net_salary_fmt']) ?> <span class="sal-curr">ج.م</span></div>
                            <?php if ($d['branch_name'] !== '—' || $d['treasury_name'] !== '—'): ?>
                                <div class="sal-card-sub">
                                    <?= e(implode(' · ', array_filter([$d['branch_name'] !== '—' ? $d['branch_name'] : '', $d['treasury_name'] !== '—' ? $d['treasury_name'] : '']))) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <div id="salEmptyList" class="empty" style="display:none;padding:24px 12px;">
                        مفيش موظف مطابق للفلتر.
                    </div>
                </div>

                <!-- التفصيل (شمال) -->
                <div class="fcard sal-detail-panel" id="salDetailPanel">
                    <?php foreach ($report['details'] as $idx => $d): ?>
                        <div class="sal-detail-box sal-detail-card" id="salDetail_<?= $d['id'] ?>" <?= $idx === 0 ? '' : 'hidden' ?>>
                            <div class="sal-detail-header">
                                <div class="sal-detail-name-row">
                                    <h3 class="sal-detail-name"><?= e($d['full_name']) ?></h3>
                                    <span class="badge sal-badge-<?= e($d['status']) ?>">
                                        <?= e($d['status_label']) ?>
                                    </span>
                                </div>
                                <?php if (!empty($d['sub_line'])): ?>
                                    <div class="sal-detail-sub"><?= e($d['sub_line']) ?></div>
                                <?php endif; ?>
                                <div class="sal-detail-treasury-row">
                                    <span class="sal-treasury-label">خزينة الصرف:</span>
                                    <span class="sal-treasury-val"><?= e($d['treasury_name']) ?></span>
                                </div>
                            </div>

                            <div class="sal-breakdown">
                                <div class="sal-line">
                                    <span class="sal-line-label">الاستحقاق قبل الخصم</span>
                                    <span class="sal-line-dots"></span>
                                    <span class="sal-line-val"><?= e($d['entitlement_fmt']) ?></span>
                                </div>
                                <div class="sal-line">
                                    <span class="sal-line-label">المكافآت</span>
                                    <span class="sal-line-dots"></span>
                                    <span class="sal-line-val <?= $d['total_bonuses'] > 0 ? 'pos' : '' ?>"><?= e($d['bonuses_fmt']) ?></span>
                                </div>
                                <div class="sal-line">
                                    <span class="sal-line-label">السلف</span>
                                    <span class="sal-line-dots"></span>
                                    <span class="sal-line-val <?= $d['total_advances'] > 0 ? 'neg' : '' ?>"><?= e($d['advances_fmt']) ?></span>
                                </div>
                                <div class="sal-line">
                                    <span class="sal-line-label">الجزاءات</span>
                                    <span class="sal-line-dots"></span>
                                    <span class="sal-line-val <?= $d['total_penalties'] > 0 ? 'neg' : '' ?>"><?= e($d['penalties_fmt']) ?></span>
                                </div>
                                <div class="sal-line">
                                    <span class="sal-line-label">عجز العهدة</span>
                                    <span class="sal-line-dots"></span>
                                    <span class="sal-line-val <?= $d['total_deficits'] > 0 ? 'neg' : '' ?>"><?= e($d['deficits_fmt']) ?></span>
                                </div>
                                <div class="sal-line">
                                    <span class="sal-line-label">عجز الكاشير</span>
                                    <span class="sal-line-dots"></span>
                                    <span class="sal-line-val <?= $d['total_cashier_deficits'] > 0 ? 'neg' : '' ?>"><?= e($d['cdeficits_fmt']) ?></span>
                                </div>

                                <div class="sal-divider-solid"></div>

                                <div class="sal-line sal-subtotal">
                                    <span class="sal-line-label">إجمالي الخصومات</span>
                                    <span class="sal-line-dots"></span>
                                    <span class="sal-line-val <?= $d['total_deductions'] > 0 ? 'neg' : '' ?>"><?= e($d['deductions_fmt']) ?></span>
                                </div>

                                <div class="sal-line sal-net-row">
                                    <span class="sal-line-label">الصافي</span>
                                    <span class="sal-line-dots"></span>
                                    <span class="sal-line-val pos sal-net-val"><?= e($d['net_salary_fmt']) ?> <span class="sal-curr">ج.م</span></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div id="salDetailEmpty" class="sal-detail-box sal-detail-card" hidden>
                        <div class="empty" style="padding:40px 10px;">مفيش موظف مطابق للفلتر.</div>
                    </div>
                </div>
            </div>

            <script>
            (function () {
                var masterList = document.getElementById('salMasterList');
                if (!masterList) return;
                var cards = Array.from(masterList.querySelectorAll('.sal-card'));
                var searchInput = document.getElementById('salSearch');
                var treasurySelect = document.getElementById('salTreasuryFilter');
                var statusSelect = document.getElementById('salStatusFilter');
                var countEl = document.getElementById('salCountCard');
                var netEl = document.getElementById('salNetCard');
                var summaryBox = document.getElementById('salSummaryCards');
                var emptyListEl = document.getElementById('salEmptyList');
                var emptyDetailEl = document.getElementById('salDetailEmpty');
                var detailPanel = document.getElementById('salDetailPanel');

                function norm(str) {
                    return String(str || '').toLowerCase()
                        .replace(/[أإآ]/g, 'ا')
                        .replace(/ة/g, 'ه')
                        .replace(/ى/g, 'ي')
                        .replace(/[\u064B-\u065F]/g, '')
                        .trim();
                }

                function selectCard(card) {
                    cards.forEach(function (c) {
                        c.classList.remove('active');
                        c.classList.remove('is-active');
                        c.setAttribute('aria-pressed', 'false');
                    });
                    if (detailPanel) {
                        var allDetails = detailPanel.querySelectorAll('.sal-detail-card');
                        allDetails.forEach(function (d) { d.hidden = true; });
                    }

                    if (!card) {
                        if (emptyDetailEl) emptyDetailEl.hidden = false;
                        return;
                    }

                    card.classList.add('active');
                    card.classList.add('is-active');
                    card.setAttribute('aria-pressed', 'true');
                    var targetId = 'salDetail_' + card.dataset.id;
                    var targetDetail = document.getElementById(targetId);
                    if (targetDetail) {
                        targetDetail.hidden = false;
                    }
                    if (emptyDetailEl) emptyDetailEl.hidden = true;
                }

                function applyFilter() {
                    var q = searchInput ? norm(searchInput.value) : '';
                    var tr = treasurySelect ? (treasurySelect.value || '').trim() : '';
                    var st = statusSelect ? (statusSelect.value || '').trim() : '';

                    var visibleCount = 0;
                    var visibleNet = 0;
                    var firstVisible = null;
                    var activeVisible = false;

                    cards.forEach(function (card) {
                        var cardName = norm(card.dataset.name);
                        var cardTr = card.dataset.treasury || '';
                        var cardSt = card.dataset.status || '';

                        var matchName = !q || cardName.indexOf(q) !== -1;
                        var matchTr = !tr || cardTr === tr || cardTr.indexOf(tr) !== -1;
                        var matchSt = !st || cardSt === st;

                        var match = matchName && matchTr && matchSt;
                        card.hidden = !match;

                        if (match) {
                            visibleCount++;
                            visibleNet += parseFloat(card.dataset.net || '0');
                            if (!firstVisible) firstVisible = card;
                            if (card.classList.contains('active')) activeVisible = true;
                        }
                    });

                    if (countEl) countEl.textContent = visibleCount.toLocaleString('en-US');
                    if (netEl) netEl.textContent = visibleNet.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

                    var isFiltered = Boolean(q || tr || st);
                    if (summaryBox) {
                        if (isFiltered) summaryBox.classList.add('filtered');
                        else summaryBox.classList.remove('filtered');
                    }

                    if (visibleCount === 0) {
                        if (emptyListEl) emptyListEl.style.display = 'block';
                        selectCard(null);
                    } else {
                        if (emptyListEl) emptyListEl.style.display = 'none';
                        if (!activeVisible && firstVisible) {
                            selectCard(firstVisible);
                        }
                    }
                }

                masterList.addEventListener('click', function (e) {
                    var card = e.target.closest('.sal-card');
                    if (!card || card.hidden) return;
                    selectCard(card);
                });

                masterList.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        var card = e.target.closest('.sal-card');
                        if (card && !card.hidden) {
                            e.preventDefault();
                            selectCard(card);
                        }
                    }
                });

                if (searchInput) searchInput.addEventListener('input', applyFilter);
                if (treasurySelect) treasurySelect.addEventListener('change', applyFilter);
                if (statusSelect) statusSelect.addEventListener('change', applyFilter);
            })();
            </script>
        <?php endif; ?>
    <?php endif; ?>
    <?php else: ?>
    <?php if (!empty($report['sum_cards']) && !empty($report['rows'])): ?>
        <?php /* الكروت بتتحسب في المتصفح من الصفوف الظاهرة في الجدول.
                 القيم الابتدائية سيرفر-سايد عشان الكروت تبان صح حتى لو
                 الجافاسكربت اتأخر. أول ما xtable يفلتر، بتتحدّث. */ ?>
        <div class="sum-cards" id="sumCards" data-xt-kpis="#reportMainTable"
             data-cards='<?= json_encode($report['sum_cards'], JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT) ?>'>
            <?php foreach ($report['sum_cards'] as $ci => $cd): ?>
                <?php
                // كارت ممكن يجمع عمود واحد (col) أو أكتر (cols)
                // cols = أعمدة بتتجمع · minus = أعمدة بتتطرح
                // (الصافي = مدين − دائن)
                // 🔴 كارت العدّاد (`count`) مالوش عمود خالص — تقرير الإشعارات
                //    مثلاً كارته `['label'=>..., 'count'=>true]` وبس.
                //    `?? [$cd['col']]` بتتنفّذ قبل فحص `count` تحت، فكانت
                //    بترمي «Undefined array key "col"» في كل فتحة للتقرير.
                $cols  = $cd['cols'] ?? (isset($cd['col']) ? [$cd['col']] : []);
                $minus = $cd['minus'] ?? [];
                $t = 0.0;
                if (!empty($cd['count'])) {          // كارت عدّاد مش مجموع
                    $t = count($report['rows']);
                }
                foreach ($report['rows'] as $ri => $rw) {
                    if (!empty($cd['count'])) break;
                    if (!empty($report['reversed_rows'][$ri])) continue;
                    foreach ($cols as $cx) {
                        $t += (float) str_replace([',', '—'], '', (string)($rw[$cx] ?? '0'));
                    }
                    foreach ($minus as $cx) {
                        $t -= (float) str_replace([',', '—'], '', (string)($rw[$cx] ?? '0'));
                    }
                }
                ?>
                <div class="fcard sum-box sum-card" data-card="<?= (int)$ci ?>">
                    <div class="sc-label"><?= e($cd['label']) ?></div>
                    <div class="sc-value" <?= !empty($cd['count']) ? 'data-xt-count' : 'data-xt-sum="card' . (int)$ci . '"' ?> data-xt-dec="<?= !empty($cd['qty']) ? 10 : ((!empty($cd['int']) || !empty($cd['count'])) ? 0 : 2) ?>"<?= !empty($cd['qty']) ? ' data-xt-trim' : '' ?><?= !empty($cd['signed']) ? ' data-xt-signed' : '' ?>><?= !empty($cd['qty']) ? rtrim(rtrim(number_format($t, 10, '.', ','), '0'), '.') : ((!empty($cd['int']) || !empty($cd['count'])) ? number_format($t) : number_format($t, 2)) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($report['balance_warning'])): ?>
        <div style="background:var(--warn-soft);color:var(--ink-900);padding:10px 14px;border-radius:8px;margin:12px 0;">
            <?= e($report['balance_warning']) ?>
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
    <script>
    /* 🔴 الدالة دي **لازم تفضل فوق الجدول**.
           الجدول تحتيها `foreach` على كل صفوف التقرير. المتصفح
           بيرسمها ويسيب المستخدم يدوس قبل ما يوصل للسكربت اللي
           في آخر الصفحة. لما كانت تحت، كان ممكن يحصل ReferenceError
           والضغطة تضيع. متنقلهاش تحت تاني. */
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
            var exportUrl = 'journal_account_export.php?acc=' + encodeURIComponent(tr.dataset.acc) +
                            '&branch=' + encodeURIComponent(branchId) +
                            '&from='   + encodeURIComponent(p.get('from') || '') +
                            '&to='     + encodeURIComponent(p.get('to')   || '');
            var h = '<div class="acc-inner">' +
                    '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px;">' +
                    '<span style="font-size:13px;font-weight:600;color:var(--ink-700);">حركات الحساب (' + d.rows.length + ' حركة)</span>' +
                    '<a href="' + exportUrl + '" class="btn btn-csv" style="font-size:12px;padding:6px 14px;text-decoration:none;"><i class="ti ti-file-spreadsheet"></i> تصدير حركات الحساب (Excel)</a>' +
                    '</div>' +
                    '<div class="ftable-wrap"><table class="ftable"><thead><tr>' +
                    '<th>التاريخ</th><th>رقم الوردية</th><th>البيان</th><th>النوع</th><th>الخزينة</th><th>الفرع</th>' +
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
                var descHtml = esc(r.desc) + (r.path ? '<div style="font-size:11.5px;color:var(--ink-400);margin-top:2px;">' + esc(r.path) + '</div>' : '');
                h += '<tr><td>' + esc(r.date) + '</td><td>' + esc(r.shift) + '</td><td>' + descHtml + '</td>' +
                     '<td>' + esc(r.kind) + '</td><td>' + esc(r.treasury) + '</td>' +
                     '<td>' + esc(r.branch || '—') + '</td>' +
                     '<td>' + f(r.debit) + '</td><td>' + f(r.credit) + '</td>' +
                     '<td>' + f(r.balance) + '</td><td>' + esc(r.by) + '</td>' +
                     '<td>' + (r.notes ? esc(r.notes) : '—') + '</td></tr>';
            });
            h += '</tbody></table></div></div>';
            cell.innerHTML = h;
            tr.dataset.loaded = '1';
        }).catch(function (ex) {
            cell.innerHTML = '<div class="acc-msg">' + (ex.message || 'الاتصال فشل') + '</div>';
        });
    }

    function distToggle(tr) {
        var body = tr.nextElementSibling;
        if (!body || !body.classList.contains('dist-body')) return;

        var isOpen = !body.hidden;
        if (isOpen) {
            body.hidden = true;
            tr.classList.remove('open');
            tr.classList.remove('is-open');
            tr.setAttribute('aria-expanded', 'false');
            return;
        }

        body.hidden = false;
        tr.classList.add('open');
        tr.classList.add('is-open');
        tr.setAttribute('aria-expanded', 'true');
        if (tr.dataset.loaded === '1') return;

        var cell = body.firstElementChild;
        cell.innerHTML = '<div class="acc-msg">جارِ تحميل الأصناف...</div>';
        apiFetch('reports_api.php?type=dist_invoices&part=items&dist_id=' + encodeURIComponent(tr.dataset.distId))
            .then(function (reply) {
                var data = reply.data || {};
                var branches = data.branches || {};
                var branchIds = Object.keys(branches).sort(function (a, b) { return Number(a) - Number(b); });
                var items = data.items || [];
                if (!items.length) {
                    cell.innerHTML = '<div class="acc-msg">الفاتورة دي مافيهاش كميات موزّعة.</div>';
                    tr.dataset.loaded = '1';
                    return;
                }
                var esc = function (value) {
                    return String(value == null ? '' : value).replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                };
                var qty = function (value) {
                    return Number(value || 0).toLocaleString('en-US', { maximumFractionDigits: 20 });
                };
                var html = '<div class="ftable-wrap dist-inner"><table class="ftable" data-xt-off><thead><tr>' +
                    '<th>الصنف</th><th>الكود</th>';
                branchIds.forEach(function (branchId) {
                    html += '<th class="num">' + esc(branches[branchId]) + '</th>';
                });
                html += '<th class="num">الإجمالي</th></tr></thead><tbody>';
                items.forEach(function (item) {
                    html += '<tr><td class="dist-item-name" title="' + esc(item.name) + '">' + esc(item.name) + '</td>' +
                        '<td>' + esc(item.code) + '</td>';
                    branchIds.forEach(function (branchId) {
                        html += '<td class="num">' + qty((item.qty_by_branch || {})[branchId]) + '</td>';
                    });
                    html += '<td class="num">' + qty(item.qty_total) + '</td></tr>';
                });
                html += '</tbody></table></div>';
                cell.innerHTML = html;
                tr.dataset.loaded = '1';
            })
            .catch(function (error) {
                cell.innerHTML = '<div class="acc-msg">' + (error.message || 'معرفناش نحمّل الأصناف.') + '</div>';
            });
    }
    </script>

    <div class="ftable-wrap">
    <table id="reportMainTable" data-xt-min="1" class="ftable"<?= in_array($type, ['insta_recon', 'dist_invoices'], true) ? ' data-xt-nosort' : '' ?>>
        <thead>
            <tr><?php foreach ($report['headers'] as $hi => $h): ?><th<?= in_array($hi, $report['num_cols'] ?? [], true) ? ' class="num"' : '' ?><?= $hi === $noteCol ? ' data-xt-skip' : '' ?>><?= e($h) ?></th><?php endforeach; ?></tr>
        </thead>
        <tbody>
            <?php foreach ($report['rows'] as $ri => $row): ?>
                <?php
                // صف قابل للفتح؟ (تقرير إجماليات القيود بس دلوقتي)
                $rowMeta = $report['row_ids'][$ri] ?? null;
                $isAcc   = !empty($report['accordion']) && $rowMeta !== null;
                $accId   = is_array($rowMeta) ? (int)$rowMeta['acc'] : (int)$rowMeta;
                $brId    = is_array($rowMeta) ? (int)($rowMeta['branch'] ?? 0) : 0;
                $trClasses = [];
                if ($isAcc) $trClasses[] = 'acc-row';
                $isDist = $type === 'dist_invoices' && $rowMeta !== null;
                if ($isDist) $trClasses[] = 'dist-row';
                if (!empty($report['reversed_rows'][$ri])) $trClasses[] = 'is-reversed';
                $xtKpiAttrs = '';
                foreach (($report['sum_cards'] ?? []) as $xtIndex => $xtCard) {
                    if (!empty($xtCard['count'])) continue;
                    $xtValue = 0.0;
                    if (empty($report['reversed_rows'][$ri])) {
                        foreach (($xtCard['cols'] ?? (isset($xtCard['col']) ? [$xtCard['col']] : [])) as $xtCol) {
                            $xtValue += (float) str_replace([',', '—'], '', (string)($row[$xtCol] ?? '0'));
                        }
                        foreach (($xtCard['minus'] ?? []) as $xtCol) {
                            $xtValue -= (float) str_replace([',', '—'], '', (string)($row[$xtCol] ?? '0'));
                        }
                    }
                    $xtKpiAttrs .= ' data-v-card' . (int)$xtIndex . '="' . e((string)$xtValue) . '"';
                }
                $trClassAttr = !empty($trClasses) ? ' class="' . implode(' ', $trClasses) . '"' : '';
                ?>
                <tr<?= $trClassAttr ?><?= $xtKpiAttrs ?><?= (($type === 'treasury' && ($row[3] ?? '') === 'رصيد سابق') || ($type === 'custody' && ($row[2] ?? '') === 'رصيد سابق')) ? ' data-xt-ignore style="background:var(--line-soft);font-weight:600;"' : '' ?><?= $type === 'insta_recon' ? ' data-insta-summary tabindex="0" role="button" aria-expanded="false"' : '' ?><?= $isAcc ? ' data-acc="'.$accId.'" data-branch="'.$brId.'" onclick="jeToggle(this)"' : '' ?><?= $isDist ? ' data-dist-id="'.(int)$rowMeta.'" tabindex="0" role="button" aria-expanded="false" onclick="distToggle(this)" onkeydown="if(event.key===\'Enter\'||event.key===\' \'){event.preventDefault();distToggle(this);}"' : '' ?>><?php foreach ($row as $ci => $cell): ?>
                    <?php if ($type === 'purchase_docs' && $ci === 0 && !empty($report['row_links'][$ri])): ?>
                        <td><a href="<?= e($report['row_links'][$ri]) ?>"><?= e((string)$cell) ?></a></td>
                    <?php elseif ($type === 'purchase_docs' && $ci === 8): ?>
                        <td><span class="fchip <?= $cell === 'مترحلة' ? 'fchip-pos' : ($cell === 'ملغية' ? 'fchip-neg' : 'fchip-warn') ?>"><?= e((string)$cell) ?></span></td>
                    <?php elseif ($ci === $noteCol): ?>
                        <td class="rep-note" data-xt-skip title="<?= e((string)$cell) ?>"><?= $cell !== '' ? e((string)$cell) : '—' ?></td>
                    <?php elseif ($type === 'insta_recon' && $ci === 8): ?>
                        <td><span class="fchip <?= $cell === 'مطابق' ? 'fchip-pos' : 'fchip-warn' ?>"><?= e((string)$cell) ?></span></td>
                    <?php elseif ($type === 'dist_invoices' && $ci === ($report['status_col'] ?? -1)): ?>
                        <td><span class="fchip <?= $cell === 'تمت' ? 'fchip-pos' : ($cell === 'متقسّمة' ? 'fchip-warn' : 'fchip-mute') ?>"><?= e((string)$cell) ?></span></td>
                    <?php elseif ($isAcc && $ci === 0): ?>
                        <td><span class="acc-caret">&#9656;</span> <?= e((string)$cell) ?></td>
                    <?php elseif ($type === 'deficits' && $ci === 5 && $cell !== '—'): ?>
                        <td style="color:var(--neg);font-weight:600;"><?= e((string)$cell) ?></td>
                    <?php elseif ($type === 'deficits' && $ci === 6 && $cell !== '—'): ?>
                        <td style="color:var(--pos);font-weight:600;"><?= e((string)$cell) ?></td>
                    <?php elseif ($type === 'deficits' && $ci === 9): ?>
                        <td>
                            <?php if ($cell === 'تجاوز العتبة'): ?>
                                <span class="badge" style="background:var(--neg-soft);color:var(--neg);padding:3px 8px;border-radius:6px;font-size:12px;font-weight:600;"><?= e((string)$cell) ?></span>
                            <?php else: ?>
                                <span class="badge" style="background:var(--line-soft);color:var(--ink-600);padding:3px 8px;border-radius:6px;font-size:12px;"><?= e((string)$cell) ?></span>
                            <?php endif; ?>
                        </td>
                    <?php elseif (!empty($report['row_paths'][$ri]) && (($type === 'treasury' && $ci === 3) || ($type === 'custody' && isset($_GET['branch']) && $_GET['branch'] !== '' && $ci === 2))): ?>
                        <td>
                            <?= e((string)$cell) ?>
                            <div style="font-size:11.5px;color:var(--ink-400);margin-top:2px;">
                                <?= e($report['row_paths'][$ri]) ?>
                            </div>
                        </td>
                    <?php elseif (in_array($ci, $report['num_cols'] ?? [], true)): ?>
                        <td class="num"><?= e((string)$cell) ?></td>
                    <?php else: ?>
                        <td><?= e((string)$cell) ?></td>
                    <?php endif; ?>
                <?php endforeach; ?></tr>
                <?php if ($isDist): ?>
                    <tr class="dist-body ftable-detail" data-xt-ignore data-xt-nosort hidden>
                        <td colspan="<?= count($report['headers']) ?>"></td>
                    </tr>
                <?php endif; ?>
                <?php if ($type === 'insta_recon'): ?>
                    <tr class="ftable-detail" data-xt-ignore data-xt-nosort hidden>
                        <td colspan="<?= count($report['headers']) ?>">
                            <div class="ftable-wrap">
                            <table class="ftable"><thead><tr><th>الوردية</th><th>حالتها</th><th>الكاشير</th><th>المبلغ</th><th>مرجع الكاشير</th><th>المطابقة</th><th>وقت الرسالة</th><th>رقم الرسالة</th><th>الراسل</th></tr></thead><tbody>
                            <?php foreach ($report['details'][$ri] as $line): $sms = $line['sms']; ?>
                                <tr><td><?= e($line['shift_name']) ?></td><td><?= e($line['shift_status']) ?></td><td><?= e($line['cashier']) ?></td>
                                    <td class="num"><?= number_format($line['amount_cents'] / 100, 2) ?></td><td><?= e($line['reference']) ?></td>
                                    <td><span class="fchip <?= $line['status'] === 'مطابق' ? 'fchip-pos' : 'fchip-warn' ?>"><?= e($line['status']) ?></span></td>
                                    <td><?= e($sms['sms_at'] ?? '—') ?></td>
                                    <td><?= $sms ? (int)$sms['id'] : e(implode('، ', $line['candidates'])) ?></td>
                                    <td><?= e($sms['sender'] ?? '—') ?></td></tr>
                            <?php endforeach; ?>
                            </tbody></table>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
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
            <?php if ($type === 'treasury' && !empty($_GET['treasury']) && !empty($report['totals'])): ?>
                <tr class="totals" data-xt-ignore style="background:var(--line-soft);font-weight:700;">
                    <?php foreach ($report['totals'] as $cell): ?><td><?= e((string)$cell) ?></td><?php endforeach; ?>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
    <?php if ($type === 'insta_recon'): ?>
        <div style="margin-top:24px;">
            <h3 style="margin:0 0 10px;font-size:16px;font-weight:700;">كل العمليات</h3>
            <div class="ftable-wrap">
            <table class="ftable" data-xt-off>
                <thead>
                    <tr>
                        <th>اليوم</th>
                        <th>الفرع</th>
                        <th>الوردية</th>
                        <th>الكاشير</th>
                        <th class="num">المبلغ</th>
                        <th>مرجع الكاشير</th>
                        <th>الحالة</th>
                        <th>رقم الرسالة</th>
                        <th>وقت الرسالة</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $hasAnyLine = false;
                foreach ($report['details'] as $lines):
                    foreach ($lines as $line):
                        $hasAnyLine = true;
                        $sms = $line['sms'];
                        $smsIdText = $sms ? (string)$sms['id'] : ($line['candidates'] ? implode('، ', $line['candidates']) : '—');
                        $smsTimeText = $sms['sms_at'] ?? '—';
                        $chipClass = $line['status'] === 'مطابق' ? 'fchip-pos' : 'fchip-warn';
                ?>
                    <tr>
                        <td><?= e($line['date']) ?></td>
                        <td><?= e($line['branch']) ?></td>
                        <td><?= e($line['shift_name']) ?></td>
                        <td><?= e($line['cashier']) ?></td>
                        <td class="num"><?= number_format($line['amount_cents'] / 100, 2) ?></td>
                        <td><?= e($line['reference']) ?></td>
                        <td><span class="fchip <?= $chipClass ?>"><?= e($line['status']) ?></span></td>
                        <td><?= e($smsIdText) ?></td>
                        <td><?= e($smsTimeText) ?></td>
                    </tr>
                <?php
                    endforeach;
                endforeach;
                if (!$hasAnyLine):
                ?>
                    <tr><td colspan="9" style="text-align:center;color:var(--ink-400);padding:14px;">لا توجد عمليات إنستاباي في الورديات لهذه الفترة.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($type === 'insta_recon' && $report['unmatched_sms']): ?>
        <details style="margin-top:14px;"><summary>رسايل واردة من غير مطابقة مؤكدة (<?= count($report['unmatched_sms']) ?>)</summary>
            <div class="ftable-wrap">
            <table class="ftable"><thead><tr><th>يوم العمل</th><th>وقت الرسالة</th><th>رقمها</th><th>المبلغ</th><th>الراسل</th></tr></thead><tbody>
            <?php foreach ($report['unmatched_sms'] as $sms): ?>
                <tr><td><?= e($sms['business_date']) ?></td><td><?= e($sms['sms_at']) ?></td>
                    <td><?= (int)$sms['id'] ?></td><td class="num"><?= number_format($sms['amount_cents'] / 100, 2) ?></td>
                    <td><?= e($sms['sender']) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
            </div>
        </details>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($type === 'insta_recon'): ?>
<script src="assets/insta_recon.js?v=2" defer></script>
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
   فالكروت بتجمع من سمات الصفوف الخام في المكتبة. يعني مستحيل يحصل اختلاف بين رقم
   الكارت واللي تحته في الجدول.

   لو xtable مش موجود لأي سبب، القيم اللي السيرفر حسبها بتفضل ظاهرة.
   ===================================================================== */
/* تحويل "1,234.50" و«—» بقى وقت طباعة data-v-card، مش من نص الخلية في المتصفح. */
// أعمدة بتتجمع في قيمة السمة الخام للكارت.
// أعمدة بتتطرح (الصافي) في نفس السمة، من غير تغيير حساب السيرفر.
// كارت عدّاد: data-xt-count لعدد الصفوف الظاهرة.
// الملاحظة بقت الشارة الموحّدة fkpi-scope جنب الرقم،
// والمكتبة بتخبيها لما الفلتر يترفع.
/* الصفوف الظاهرة فعلاً في الجدول دلوقتي.
       المكتبة بتبعتهم في ev.detail.rows وبتتجاهل صفوف التفاصيل والإجماليات.
       قيم الكروت بتتجمع من data-v-* بس. */
/* المصدر الأساسي بقى ربط data-xt-kpis من جوه xtable.
       المراقب القديم اتشال، والمكتبة بتميز الجدول نفسه وقت كل حدث.
       ده اللي بيخلّي الكروت = اللي في الجدول حرفياً من غير ربط يدوي. */
/* الحدث وطباعة الشارة واسترجاع الأصل كلهم مسؤولية المكتبة. */

</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
