<?php
/**
 * advances_import.php — استيراد ورفع أرصدة السلف من شيت إكسيل (.xlsx)
 * نظام الفجالة ستور المالي
 *
 * الرحلة:
 *  1. رفع ملف إكسيل (.xlsx) وحفظه مؤقتاً بتوكن
 *  2. ربط الأعمدة وتحديد عمود الاسم وعمود الإجمالي (المبلغ)
 *  3. مطابقة الموظفين ومعاينة الأرصدة وتعديل المبالغ والأقساط
 *  4. تأكيد الرفع وتحديث قاعدة البيانات في معاملة واحدة (Transaction)
 */
require_once __DIR__ . '/auth.php';
require_login();

if (!is_owner()) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;text-align:center;padding:60px;direction:rtl">'
        . '<h2>🚫 غير مصرّح</h2>'
        . '<p>هذه الصفحة خاصة بمالك النظام فقط.</p>'
        . '<a href="dashboard.php">العودة للرئيسية</a></div>');
}

require_once __DIR__ . '/xlsx_read_lib.php';

$pdo = db();
$flash = '';
$flashType = 'success';

$tmpDir = rtrim(sys_get_temp_dir(), '/') . '/fagala_advances';
@mkdir($tmpDir, 0700, true);

// كنس الملفات المؤقتة التي مر عليها ساعتان
foreach ((array) glob($tmpDir . '/*.xlsx') as $old) {
    if (is_file($old) && filemtime($old) < time() - 7200) {
        @unlink($old);
    }
}

// ===== دوال مساعدة محلية لتنظيف وتحليل البيانات =====

/**
 * تنظيف القيمة النصية والتأكد من أنها ليست فارغة أو شرطة
 */
function adv_clean_val(?string $v): ?string
{
    if ($v === null) return null;
    $t = trim($v);
    if ($t === '' || $t === '-' || $t === '—') return null;
    return $t;
}

/**
 * تطبيع الأسماء العربية لمطابقة اسم الشيتة باسم الموظف في النظام
 * - إزالة التشكيل والتطويل (الكشيدة)
 * - توحيد الهمزات والألفات (أ إ آ ٱ -> ا)
 * - توحيد ى -> ي
 * - توحيد ؤ -> و
 * - توحيد ئ -> ي
 * - تحويل الأرقام العربية/المشرقية إلى إنجليزية
 * - دمج المسافات الزائدة والمتكررة و trim
 */
function adv_normalize_name(string $name): string
{
    $easternNums = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩','۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $westernNums = ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
    $s = str_replace($easternNums, $westernNums, $name);

    $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $s);
    $s = preg_replace('/\x{0640}/u', '', $s);
    $s = preg_replace('/[أإآٱ]/u', 'ا', $s);
    $s = preg_replace('/ى/u', 'ي', $s);
    $s = preg_replace('/ؤ/u', 'و', $s);
    $s = preg_replace('/ئ/u', 'ي', $s);
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}

/**
 * استخراج وتحليل قيمة المبلغ من خلية الإكسيل
 * يعيد null إذا كانت الخلية #N/A أو فارغة أو غير رقمية
 * يعيد الرقم العشري مقرباً لخانتين إذا كانت القيمة صالحة
 */
function adv_parse_amount(?string $v): ?float
{
    if ($v === null) return null;
    $t = trim($v);
    if ($t === '' || in_array($t, ['-', '—', 'null', 'NULL'], true)) return null;

    $upper = strtoupper($t);
    if ($upper === '#N/A' || $upper === 'N/A' || mb_strpos($t, '#') !== false) {
        return null;
    }

    if (is_numeric($t)) {
        return round((float) $t, 2);
    }

    return null;
}

/**
 * اكتشاف الأعمدة الافتراضية (عمود الاسم وعمود الإجمالي) من نصوص العناوين
 */
function adv_detect_columns(array $headers): array
{
    $nameCol = -1;
    $amountCol = -1;

    foreach ($headers as $i => $h) {
        $norm = adv_normalize_name((string) $h);
        if ($nameCol === -1) {
            if (mb_strpos($norm, 'اسم') !== false || mb_strpos($norm, 'تسميات') !== false || mb_strpos($norm, 'موظف') !== false) {
                $nameCol = $i;
            }
        }
        if ($amountCol === -1) {
            if ($norm === 'الاجمالي' || $norm === 'اجمالي' || $norm === 'المبلغ' || $norm === 'المتبقي' || $norm === 'اجمالي المتبقي') {
                $amountCol = $i;
            }
        }
    }

    // محاولة ثانية لاكتشاف عمود المبلغ لو لم يتم العثور على تطابق كامل
    if ($amountCol === -1) {
        foreach ($headers as $i => $h) {
            $norm = adv_normalize_name((string) $h);
            if (mb_strpos($norm, 'اجمالي') !== false || mb_strpos($norm, 'متبقي') !== false) {
                $amountCol = $i;
                break;
            }
        }
    }

    // افتراضات احتياطية إذا لم يتطابق أي نص
    if ($nameCol === -1) {
        $nameCol = count($headers) > 1 ? 1 : 0;
    }
    if ($amountCol === -1) {
        $amountCol = count($headers) > 10 ? 10 : (count($headers) - 1);
    }

    return [
        'name_col'   => $nameCol,
        'amount_col' => $amountCol,
    ];
}

// ===== معالجة الطلبات (POST) =====

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $flash = 'انتهت صلاحية الجلسة، برجاء إعادة المحاولة.';
        $flashType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            // (1) رفع ملف للمعاينة
            if ($action === 'upload_file') {
                $f = $_FILES['advances_file'] ?? null;
                if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('لم يتم رفع الملف بنجاح — حاول مرة أخرى.');
                }
                if ($f['size'] > 25 * 1024 * 1024) {
                    throw new RuntimeException('حجم الملف كبير جداً (أقصى حد 25 ميجا).');
                }

                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                if ($ext !== 'xlsx') {
                    throw new RuntimeException('نوع الملف غير مدعوم — يرجى رفع ملف إكسيل بصيغة .xlsx فقط.');
                }

                $tok = bin2hex(random_bytes(16));
                $destPath = $tmpDir . '/' . $tok . '.xlsx';
                if (!move_uploaded_file($f['tmp_name'], $destPath)) {
                    throw new RuntimeException('تعذر حفظ الملف المؤقت على الخادم.');
                }

                $_SESSION['adv_import_tok'] = $tok;
                $_SESSION['adv_import_orig_name'] = $f['name'];

                header('Location: advances_import.php');
                exit;
            }

            // (2) إلغاء المعاينة وحذف الملف المؤقت
            elseif ($action === 'cancel_file') {
                $tok = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['token'] ?? ''));
                $sessionTok = $_SESSION['adv_import_tok'] ?? '';
                if ($tok !== '' && $sessionTok !== '' && hash_equals($sessionTok, $tok)) {
                    @unlink($tmpDir . '/' . $tok . '.xlsx');
                }
                unset($_SESSION['adv_import_tok'], $_SESSION['adv_import_orig_name']);
                $_SESSION['flash'] = 'تم إلغاء المعاينة وحذف الملف المؤقت.';
                $_SESSION['flash_type'] = 'success';
                header('Location: advances_import.php');
                exit;
            }

            // (3) تأكيد الاستيراد وحفظ السلف في قاعدة البيانات
            elseif ($action === 'confirm_import') {
                $tok = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['token'] ?? ''));
                $sessionTok = $_SESSION['adv_import_tok'] ?? '';
                if ($tok === '' || $sessionTok === '' || !hash_equals($sessionTok, $tok)) {
                    throw new RuntimeException('رمز الملف غير صالح أو منتهي الصلاحية — أعد رفع الملف.');
                }

                $filePath = $tmpDir . '/' . $tok . '.xlsx';
                if (!file_exists($filePath)) {
                    throw new RuntimeException('الملف المؤقت لم يعد متاحاً أو انتهت جلسته. يرجى إعادة رفع الملف.');
                }

                $submittedRows = $_POST['rows'] ?? [];
                if (!is_array($submittedRows) || empty($submittedRows)) {
                    throw new RuntimeException('لم يتم استلام أي بيانات صفوف للحفظ.');
                }

                // 1) جلب الموظفين النشطين (استعلام واحد مجمّع)
                $activeEmpsStmt = $pdo->query('SELECT id, full_name FROM employees WHERE is_active = 1');
                $activeEmpsMap = $activeEmpsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

                // 2) جلب السلف المستديمة المعلّقة مجمّعة لكل موظف (استعلام واحد مجمّع)
                $advStmt = $pdo->query(
                    "SELECT employee_id,
                            COUNT(*) AS pending_count,
                            MIN(id) AS single_id,
                            COALESCE(SUM(COALESCE(remaining, amount)), 0) AS current_balance
                     FROM employee_advances
                     WHERE status = 'pending' AND adv_type = 'standing'
                     GROUP BY employee_id"
                );
                $pendingAdvMap = [];
                foreach ($advStmt->fetchAll(PDO::FETCH_ASSOC) as $ar) {
                    $pendingAdvMap[(int)$ar['employee_id']] = [
                        'pending_count'   => (int)$ar['pending_count'],
                        'single_id'       => $ar['single_id'] !== null ? (int)$ar['single_id'] : null,
                        'current_balance' => (float)$ar['current_balance'],
                    ];
                }

                $toProcess = [];
                $seenEmpIds = [];
                $skippedCount = 0;

                foreach ($submittedRows as $rIdx => $r) {
                    $isSelected = !empty($r['selected']);
                    $rowNum = (int)($r['row_num'] ?? $rIdx);
                    $sheetName = trim((string)($r['sheet_name'] ?? ''));

                    if (!$isSelected) {
                        $skippedCount++;
                        continue;
                    }

                    $empId = (int)($r['employee_id'] ?? 0);
                    if ($empId <= 0 || !isset($activeEmpsMap[$empId])) {
                        throw new RuntimeException("صف #{$rowNum} ({$sheetName}): يرجى اختيار موظف نشط مسجل في النظام.");
                    }

                    $empName = $activeEmpsMap[$empId];

                    // التحقق من عدم تكرار ربط نفس الموظف في أكثر من صف
                    if (isset($seenEmpIds[$empId])) {
                        throw new RuntimeException("تعذر التأكيد: الموظف ({$empName}) تم اختياره في أكثر من صف. لا يمكن تكرار نفس الموظف.");
                    }
                    $seenEmpIds[$empId] = true;

                    // فحص المبلغ
                    $rawAmount = trim((string)($r['amount'] ?? ''));
                    if ($rawAmount === '' || !is_numeric($rawAmount)) {
                        throw new RuntimeException("تعذر التأكيد: الموظف ({$empName}) لا يوجد له مبلغ صالح للرفع.");
                    }

                    $amount = round((float)$rawAmount, 2);
                    if ($amount < 0) {
                        throw new RuntimeException("تعذر التأكيد: الموظف ({$empName}) مبلغه سالب ({$amount}). برجاء تعديل المبلغ لقيمة موجبة أو إلغاء تحديد الصف قبل الرفع.");
                    }

                    // فحص عدد السلف المستديمة المعلّقة
                    $advInfo = $pendingAdvMap[$empId] ?? [
                        'pending_count'   => 0,
                        'single_id'       => null,
                        'current_balance' => 0.0,
                    ];

                    if ($advInfo['pending_count'] > 1) {
                        throw new RuntimeException("تعذر التأكيد: الموظف ({$empName}) لديه أكثر من سلفة مستديمة معلّقة حالياً ({$advInfo['pending_count']} سلف مستديمة). برجاء تسوية سلفه من تاب السلف أولاً.");
                    }

                    // القسط الشهري
                    $monthlyDed = (isset($r['monthly_deduction']) && is_numeric($r['monthly_deduction']))
                        ? max(0.0, round((float)$r['monthly_deduction'], 2))
                        : 0.0;

                    $toProcess[] = [
                        'row_num'     => $rowNum,
                        'emp_id'      => $empId,
                        'emp_name'    => $empName,
                        'amount'      => $amount,
                        'monthly_ded' => $monthlyDed,
                        'adv_info'    => $advInfo,
                    ];
                }

                if (empty($toProcess)) {
                    throw new RuntimeException('لم يتم تحديد أي صف صالح للرفع.');
                }

                // تنفيذ الحفظ داخل معاملة واحدة (Transaction)
                $pdo->beginTransaction();
                try {
                    $insAdv = $pdo->prepare(
                        'INSERT INTO employee_advances (employee_id, amount, advance_date, status, notes, monthly_deduction, remaining, adv_type)
                         VALUES (?, ?, ?, "pending", ?, ?, ?, "standing")'
                    );
                    $updAdv = $pdo->prepare(
                        'UPDATE employee_advances SET remaining = ?, monthly_deduction = ? WHERE id = ? AND status = "pending" AND adv_type = \'standing\''
                    );

                    $today = date('Y-m-d');
                    $importNote = 'استيراد شيت السلف ' . $today;
                    $insertedCount = 0;
                    $updatedCount = 0;

                    foreach ($toProcess as $item) {
                        if ($item['adv_info']['pending_count'] === 0) {
                            $insAdv->execute([
                                $item['emp_id'],
                                $item['amount'],
                                $today,
                                $importNote,
                                $item['monthly_ded'],
                                $item['amount'],
                            ]);
                            $insertedCount++;
                        } else {
                            // pending_count === 1
                            $updAdv->execute([
                                $item['amount'],
                                $item['monthly_ded'],
                                $item['adv_info']['single_id'],
                            ]);
                            $updatedCount++;
                        }
                    }

                    $pdo->commit();
                } catch (Throwable $dbEx) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw new RuntimeException('فشلت عملية حفظ السلف في قاعدة البيانات: ' . $dbEx->getMessage());
                }

                // حذف الملف المؤقت بعد نجاح الاستيراد
                @unlink($filePath);
                unset($_SESSION['adv_import_tok'], $_SESSION['adv_import_orig_name']);

                $summaryMsg = 'تم رفع شيت السلف المستديمة بنجاح!'
                    . ' — تم إنشاء ' . number_format($insertedCount) . ' سلفة مستديمة جديدة'
                    . ' · تم تحديث ' . number_format($updatedCount) . ' رصيد مستديم قائم'
                    . ($skippedCount > 0 ? ' · تم تخطي ' . number_format($skippedCount) . ' صف' : '');

                $_SESSION['flash'] = $summaryMsg;
                $_SESSION['flash_type'] = 'success';
                header('Location: advances_import.php');
                exit;
            }

        } catch (Throwable $ex) {
            $flash = $ex->getMessage();
            $flashType = 'error';
        }
    }
}

if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    $flashType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash'], $_SESSION['flash_type']);
}

// ===== قراءة وتجهيز بيانات المعاينة =====

$activeTok = $_SESSION['adv_import_tok'] ?? '';
$activeFileName = $_SESSION['adv_import_orig_name'] ?? 'ملف السلف';
$activeFilePath = ($activeTok !== '') ? ($tmpDir . '/' . $activeTok . '.xlsx') : '';

$previewData = null;

if ($activeFilePath !== '' && file_exists($activeFilePath)) {
    try {
        $sheetRows = read_xlsx_rows($activeFilePath);
        if (count($sheetRows) < 2) {
            throw new RuntimeException('الملف فارغ أو لا يحتوي على صفوف بيانات.');
        }

        $headers = $sheetRows[0]['cells'];
        $detectedCols = adv_detect_columns($headers);

        $nameCol = isset($_REQUEST['name_col']) && is_numeric($_REQUEST['name_col'])
            ? (int) $_REQUEST['name_col']
            : $detectedCols['name_col'];

        $amountCol = isset($_REQUEST['amount_col']) && is_numeric($_REQUEST['amount_col'])
            ? (int) $_REQUEST['amount_col']
            : $detectedCols['amount_col'];

        // جلب قائمة الموظفين النشطين (استعلام واحد مجمّع)
        $activeEmployeesList = $pdo->query(
            'SELECT e.id, e.full_name, b.name AS branch_name
             FROM employees e
             LEFT JOIN branches b ON b.id = e.branch_id
             WHERE e.is_active = 1
             ORDER BY e.full_name'
        )->fetchAll();

        // بناء فهرس للبحث بالاسم المطبع
        $empByNameNorm = [];
        $empListMap = [];
        foreach ($activeEmployeesList as $ae) {
            $eId = (int) $ae['id'];
            $empListMap[$eId] = $ae;
            $norm = adv_normalize_name($ae['full_name']);
            if ($norm !== '') {
                $empByNameNorm[$norm] = $eId;
            }
        }

        // جلب أرصدة السلف المستديمة المعلّقة مجمّعة لكل موظف (استعلام واحد مجمّع)
        $advStmt = $pdo->query(
            "SELECT employee_id,
                    COUNT(*) AS pending_count,
                    MIN(id) AS single_id,
                    COALESCE(SUM(COALESCE(remaining, amount)), 0) AS current_balance
             FROM employee_advances
             WHERE status = 'pending' AND adv_type = 'standing'
             GROUP BY employee_id"
        );
        $pendingAdvMap = [];
        foreach ($advStmt->fetchAll(PDO::FETCH_ASSOC) as $ar) {
            $pendingAdvMap[(int)$ar['employee_id']] = [
                'pending_count'   => (int)$ar['pending_count'],
                'single_id'       => $ar['single_id'] !== null ? (int)$ar['single_id'] : null,
                'current_balance' => (float)$ar['current_balance'],
            ];
        }

        $parsedRows = [];
        $unmappedCount = 0;
        $missingAmountCount = 0;
        $negativeCount = 0;
        $lockedCount = 0;
        $newCount = 0;
        $updateCount = 0;

        for ($rIdx = 1; $rIdx < count($sheetRows); $rIdx++) {
            $rowNum = $sheetRows[$rIdx]['row'];
            $cells = $sheetRows[$rIdx]['cells'];

            $rawName = trim((string)($cells[$nameCol] ?? ''));
            if ($rawName === '') {
                continue; // تخطي الصفوف الفارغة تماماً من الاسم
            }

            $rawAmount = $cells[$amountCol] ?? null;
            $parsedAmount = adv_parse_amount($rawAmount);

            $normName = adv_normalize_name($rawName);
            $matchedEmpId = $empByNameNorm[$normName] ?? 0;

            $advInfo = $pendingAdvMap[$matchedEmpId] ?? [
                'pending_count'   => 0,
                'single_id'       => null,
                'current_balance' => 0.0,
            ];

            $isUnmapped = ($matchedEmpId === 0);
            $isMissing  = ($parsedAmount === null);
            $isNegative = ($parsedAmount !== null && $parsedAmount < 0);
            $isLocked   = ($matchedEmpId > 0 && $advInfo['pending_count'] > 1);

            $isSelectedByDefault = (!$isUnmapped && !$isMissing && !$isNegative && !$isLocked);

            if ($isUnmapped) $unmappedCount++;
            if ($isMissing) $missingAmountCount++;
            if ($isNegative) $negativeCount++;
            if ($isLocked) $lockedCount++;

            if ($isSelectedByDefault) {
                if ($advInfo['pending_count'] === 0) {
                    $newCount++;
                } else {
                    $updateCount++;
                }
            }

            $parsedRows[] = [
                'index'                 => $rIdx,
                'row_num'               => $rowNum,
                'raw_name'              => $rawName,
                'raw_amount'            => $rawAmount,
                'parsed_amount'         => $parsedAmount,
                'matched_emp_id'        => $matchedEmpId,
                'adv_info'              => $advInfo,
                'is_unmapped'           => $isUnmapped,
                'is_missing'            => $isMissing,
                'is_negative'           => $isNegative,
                'is_locked'             => $isLocked,
                'selected_by_default'   => $isSelectedByDefault,
            ];
        }

        // خريطة JS للموظفين
        $empJsData = [];
        foreach ($activeEmployeesList as $ae) {
            $eId = (int)$ae['id'];
            $aInfo = $pendingAdvMap[$eId] ?? ['pending_count' => 0, 'current_balance' => 0.0];
            $empJsData[$eId] = [
                'name'            => $ae['full_name'],
                'branch'          => $ae['branch_name'] ?? '',
                'pending_count'   => $aInfo['pending_count'],
                'current_balance' => $aInfo['current_balance'],
            ];
        }

        $previewData = [
            'token'                => $activeTok,
            'file_name'            => $activeFileName,
            'headers'              => $headers,
            'name_col'             => $nameCol,
            'amount_col'           => $amountCol,
            'rows'                 => $parsedRows,
            'total_sheet_rows'     => count($sheetRows) - 1,
            'unmapped_count'       => $unmappedCount,
            'missing_amount_count' => $missingAmountCount,
            'negative_count'       => $negativeCount,
            'locked_count'         => $lockedCount,
            'new_count'            => $newCount,
            'update_count'         => $updateCount,
            'employees_list'       => $activeEmployeesList,
            'emp_js_data'          => $empJsData,
            'sample_rows'          => array_slice($sheetRows, 0, 6),
        ];

    } catch (Throwable $readEx) {
        $flash = 'تعذر قراءة ملف الإكسيل المرفوع: ' . $readEx->getMessage();
        $flashType = 'error';
    }
}

$page_title = 'رفع أرصدة السلف المستديمة من إكسيل';
$active_module = 'hr';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    /* ===== أزرار متوافقة مع قواعد تصميم الفجالة (من attendance_import.php) ===== */
    .btn { background:var(--accent); color:#fff; border:none; padding:10px 18px; border-radius:8px;
        font-size:14px; font-family:inherit; cursor:pointer; text-decoration:none;
        display:inline-flex; align-items:center; gap:7px; white-space:nowrap; font-weight:500; }
    .btn:hover { background:var(--accent-hover); color:#fff; }
    .btn-sm { padding:6px 12px; font-size:12.5px; border-radius:6px; }
    .btn-ghost { background:var(--surface); color:var(--accent); border:1.5px solid var(--line); }
    .btn-ghost:hover { background:var(--accent-soft); border-color:var(--accent); color:var(--accent); }
    .btn-danger { background:var(--surface); color:var(--neg); border:1.5px solid var(--neg-soft); }
    .btn-danger:hover { background:var(--neg-soft); color:var(--neg); }
    .btn-success { background:var(--pos); color:#fff; }
    .btn-success:hover { background:#245942; color:#fff; }

    /* الكروت والتبويبات والجداول */
    .att-card { background:var(--surface); border:1px solid var(--line); border-radius:var(--r); padding:20px; margin-bottom:20px; box-shadow:var(--shadow-sm); }
    .att-h { font-size:16px; font-weight:600; color:var(--ink-900); margin-bottom:14px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }

    .att-stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:12px; margin-bottom:20px; }
    .stat-box { background:var(--bg); border:1px solid var(--line); border-radius:var(--r); padding:12px; text-align:center; }
    .stat-val { font-size:20px; font-weight:700; color:var(--ink-900); font-family:'IBM Plex Mono', monospace; }
    .stat-lbl { font-size:12.5px; color:var(--ink-500); margin-top:4px; }

    .att-table { width:100%; border-collapse:collapse; font-size:13.5px; }
    .att-table th { background:var(--bg); padding:10px 12px; text-align:right; font-weight:600; color:var(--ink-700); border-bottom:1.5px solid var(--line); white-space:nowrap; }
    .att-table td { padding:10px 12px; border-bottom:1px solid var(--line-soft); color:var(--ink-800); vertical-align:middle; }
    .att-table tr:hover td { background:var(--line-soft); }
    .att-table tr.row-locked td { background:rgba(157,59,56,0.03); }

    .badge { display:inline-flex; align-items:center; padding:3px 8px; border-radius:6px; font-size:12px; font-weight:500; }
    .badge-pos { background:var(--pos-soft); color:var(--pos); }
    .badge-neg { background:var(--neg-soft); color:var(--neg); }
    .badge-warn { background:var(--warn-soft); color:var(--warn); }
    .badge-neutral { background:var(--bg); color:var(--ink-600); border:1px solid var(--line); }

    .alert { padding:12px 16px; border-radius:var(--r); margin-bottom:16px; font-size:13.5px; line-height:1.5; display:flex; align-items:flex-start; gap:10px; }
    .alert-pos { background:var(--pos-soft); color:var(--pos); border:1px solid rgba(44,110,82,.2); }
    .alert-neg { background:var(--neg-soft); color:var(--neg); border:1px solid rgba(157,59,56,.2); }
    .alert-warn { background:var(--warn-soft); color:var(--warn); border:1px solid rgba(138,101,36,.2); }

    .upload-zone { border:2px dashed var(--line); border-radius:var(--r); padding:35px 20px; text-align:center; background:var(--bg); transition:border-color .2s; }
    .upload-zone:hover { border-color:var(--accent); }
    .select-emp { padding:6px 8px; border:1px solid var(--line); border-radius:6px; font-size:13.5px; font-family:inherit; background:var(--surface); width:100%; min-width:190px; }
    .row-amount-input { width:110px; padding:6px 8px; border:1px solid var(--line); border-radius:6px; font-family:'IBM Plex Mono', monospace; font-size:13.5px; text-align:center; background:var(--surface); }
    .row-amount-input.is-negative { border-color:var(--neg); color:var(--neg); background:var(--neg-soft); font-weight:600; }
    .monthly-ded-input { width:100px; padding:6px 8px; border:1px solid var(--line); border-radius:6px; font-family:'IBM Plex Mono', monospace; font-size:13.5px; text-align:center; background:var(--surface); }
</style>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px;">
    <div style="display:flex;align-items:center;gap:10px;">
        <a href="hr.php?tab=advances" class="btn btn-ghost btn-sm">
            <i class="ti ti-arrow-right"></i> العودة لتاب السلف
        </a>
        <h2 style="font-size:18px;font-weight:700;color:var(--ink-900);margin:0;">رفع أرصدة السلف المستديمة من شيت إكسيل</h2>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert <?= $flashType === 'error' ? 'alert-neg' : 'alert-pos' ?>">
        <i class="ti <?= $flashType === 'error' ? 'ti-alert-triangle' : 'ti-circle-check' ?>" style="font-size:18px;margin-top:2px;"></i>
        <div><?= nl2br(e($flash)) ?></div>
    </div>
<?php endif; ?>

<?php if (!$previewData): ?>
    <div class="att-card">
        <div class="att-h">
            <span><i class="ti ti-file-spreadsheet" style="color:var(--accent);margin-left:6px;"></i> رفع شيت إكسيل بأرصدة السلف المستديمة</span>
        </div>
        <p style="font-size:13.5px;color:var(--ink-600);margin-bottom:18px;line-height:1.6;">
            ارفع ملف إكسيل (.xlsx) الذي يحتوي على أسماء الموظفين وإجمالي أرصدة السلف المستديمة المستحقة عليهم.
            النظام سيقرأ عمود الاسم وعمود الإجمالي فقط ويتجاهل باقي الأعمدة تماماً،
            ويتيح لك ربط كل اسم بالموظف المقابل في النظام وتحديد القسط الشهري ومعاينة الرصيد قبل الحفظ.
        </p>

        <form method="post" enctype="multipart/form-data" class="upload-zone">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="upload_file">
            <i class="ti ti-file-spreadsheet" style="font-size:46px;color:var(--accent);display:block;margin-bottom:12px;"></i>
            <div style="font-weight:600;color:var(--ink-800);margin-bottom:8px;font-size:15px;">اختر ملف الإكسيل (.xlsx) من جهازك</div>
            <div style="margin-bottom:18px;">
                <input type="file" name="advances_file" accept=".xlsx" required style="font-size:13.5px;color:var(--ink-700);">
            </div>
            <button type="submit" class="btn">
                <i class="ti ti-eye"></i> رفع ومعاينة الملف
            </button>
        </form>
    </div>
<?php else: ?>
    <?php // ===== شاشة المعاينة وتحديد الأعمدة وتأكيد الرفع ===== ?>
    <div class="att-card" style="border:2px solid var(--accent);">
        <div class="att-h">
            <span>
                <i class="ti ti-eye" style="color:var(--accent);margin-left:6px;"></i>
                معاينة ملف: <b><?= e($previewData['file_name']) ?></b> (<?= number_format($previewData['total_sheet_rows']) ?> صف بيانات)
            </span>
            <form method="post" style="display:inline;">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="cancel_file">
                <input type="hidden" name="token" value="<?= e($previewData['token']) ?>">
                <button type="submit" class="btn btn-ghost btn-sm">
                    <i class="ti ti-arrow-back-up"></i> إلغاء ورفع ملف آخر
                </button>
            </form>
        </div>

        <?php // خطوة ٢: تحديد العمودين وتأكيد الاختيار ?>
        <div style="background:var(--bg);border:1px solid var(--line);border-radius:var(--r);padding:14px;margin-bottom:18px;">
            <form method="get" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                <div style="font-weight:600;color:var(--ink-800);display:flex;align-items:center;gap:6px;">
                    <i class="ti ti-adjustments" style="color:var(--accent);"></i> تحديد العمودين من الشيتة:
                </div>
                <div style="display:flex;align-items:center;gap:6px;">
                    <label style="font-size:13px;color:var(--ink-700);font-weight:500;">عمود الاسم:</label>
                    <select name="name_col" style="padding:6px 10px;border:1px solid var(--line);border-radius:6px;font-family:inherit;font-size:13px;background:var(--surface);">
                        <?php foreach ($previewData['headers'] as $hIdx => $hText): ?>
                            <?php $colLetter = chr(65 + $hIdx); ?>
                            <option value="<?= $hIdx ?>" <?= $previewData['name_col'] === $hIdx ? 'selected' : '' ?>>
                                عمود <?= $colLetter ?>: <?= e(trim((string)$hText) ?: '(بدون عنوان)') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display:flex;align-items:center;gap:6px;">
                    <label style="font-size:13px;color:var(--ink-700);font-weight:500;">عمود المبلغ (الإجمالي):</label>
                    <select name="amount_col" style="padding:6px 10px;border:1px solid var(--line);border-radius:6px;font-family:inherit;font-size:13px;background:var(--surface);">
                        <?php foreach ($previewData['headers'] as $hIdx => $hText): ?>
                            <?php $colLetter = chr(65 + $hIdx); ?>
                            <option value="<?= $hIdx ?>" <?= $previewData['amount_col'] === $hIdx ? 'selected' : '' ?>>
                                عمود <?= $colLetter ?>: <?= e(trim((string)$hText) ?: '(بدون عنوان)') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn btn-sm btn-ghost">
                    <i class="ti ti-refresh"></i> تحديث الأعمدة
                </button>
            </form>
        </div>

        <?php // إحصائيات سريعة للبيانات ?>
        <div class="att-stats">
            <div class="stat-box">
                <div class="stat-val"><?= number_format($previewData['total_sheet_rows']) ?></div>
                <div class="stat-lbl">إجمالي صفوف الملف</div>
            </div>
            <div class="stat-box">
                <div class="stat-val" id="statChecked" style="color:var(--pos);">
                    <?= number_format($previewData['new_count'] + $previewData['update_count']) ?>
                </div>
                <div class="stat-lbl">محدد للرفع</div>
            </div>
            <div class="stat-box">
                <div class="stat-val" id="statNew" style="color:var(--accent);">
                    <?= number_format($previewData['new_count']) ?>
                </div>
                <div class="stat-lbl">سلف مستديمة جديدة مقترحة</div>
            </div>
            <div class="stat-box">
                <div class="stat-val" id="statUpdate" style="color:var(--ink-700);">
                    <?= number_format($previewData['update_count']) ?>
                </div>
                <div class="stat-lbl">تحديث رصيد قائم</div>
            </div>
            <div class="stat-box">
                <div class="stat-val" style="color:<?= $previewData['unmapped_count'] > 0 ? 'var(--warn)' : 'var(--ink-500)' ?>;">
                    <?= number_format($previewData['unmapped_count']) ?>
                </div>
                <div class="stat-lbl">أسماء غير مربوطة</div>
            </div>
            <div class="stat-box">
                <div class="stat-val" id="statNeg" style="color:<?= $previewData['negative_count'] > 0 ? 'var(--neg)' : 'var(--ink-500)' ?>;">
                    <?= number_format($previewData['negative_count']) ?>
                </div>
                <div class="stat-lbl">مبالغ سالبة (تتطلب تعديل)</div>
            </div>
            <div class="stat-box">
                <div class="stat-val" style="color:<?= $previewData['missing_amount_count'] > 0 ? 'var(--warn)' : 'var(--ink-500)' ?>;">
                    <?= number_format($previewData['missing_amount_count']) ?>
                </div>
                <div class="stat-lbl">بدون رقم / #N/A</div>
            </div>
        </div>

        <?php // خطوة ٣: شريط القسط الشهري الافتراضي وتحديد الكل ?>
        <div style="background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:14px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <label style="font-size:13.5px;color:var(--ink-800);font-weight:600;">
                    <i class="ti ti-cash" style="color:var(--accent);"></i> القسط الشهري الافتراضي:
                </label>
                <input type="number" step="0.01" min="0" id="globalMonthlyDed" value="500.00" style="width:110px;padding:6px 10px;border:1.5px solid var(--line);border-radius:6px;font-family:'IBM Plex Mono', monospace;font-size:13.5px;text-align:center;">
                <button type="button" class="btn btn-sm btn-ghost" id="btnApplyGlobalDed">
                    <i class="ti ti-checklist"></i> تطبيق على كل الصفوف
                </button>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
                <button type="button" class="btn btn-sm btn-ghost" id="btnSelectAll">تحديد الكل</button>
                <button type="button" class="btn btn-sm btn-ghost" id="btnDeselectAll">إلغاء التحديد</button>
            </div>
        </div>

        <?php // نموذج جدول المعاينة وتأكيد الحفظ ?>
        <form method="post" id="importForm">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="confirm_import">
            <input type="hidden" name="token" value="<?= e($previewData['token']) ?>">

            <div style="overflow-x:auto;margin-bottom:20px;border:1px solid var(--line);border-radius:var(--r);">
                <table class="att-table" id="advancesTable">
                    <thead>
                        <tr>
                            <th style="width:36px;text-align:center;">
                                <input type="checkbox" id="masterCb" title="تحديد/إلغاء الكل">
                            </th>
                            <th style="width:40px;">#</th>
                            <th>الاسم في الشيتة</th>
                            <th>الموظف في النظام</th>
                            <th>رصيد السلف المستديمة الحالي</th>
                            <th>المبلغ من الشيتة</th>
                            <th>القسط الشهري</th>
                            <th>النتيجة بعد الرفع</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($previewData['rows'] as $r): ?>
                            <?php
                                $rIdx = $r['index'];
                                $isMissing = $r['is_missing'];
                                $isNegative = $r['is_negative'];
                                $isLocked = $r['is_locked'];
                                $matchedId = $r['matched_emp_id'];
                                $advInfo = $r['adv_info'];
                                $curBal = (float)$advInfo['current_balance'];
                            ?>
                            <tr data-row-idx="<?= $rIdx ?>"
                                data-row-num="<?= $r['row_num'] ?>"
                                data-sheet-name="<?= e($r['raw_name']) ?>"
                                class="<?= $isLocked ? 'row-locked' : '' ?>">
                                <td style="text-align:center;">
                                    <input type="checkbox"
                                           name="rows[<?= $rIdx ?>][selected]"
                                           value="1"
                                           class="row-cb"
                                           <?= $r['selected_by_default'] ? 'checked' : '' ?>
                                           <?= $isLocked ? 'disabled' : '' ?>>
                                    <input type="hidden" name="rows[<?= $rIdx ?>][row_num]" value="<?= (int)$r['row_num'] ?>">
                                    <input type="hidden" name="rows[<?= $rIdx ?>][sheet_name]" value="<?= e($r['raw_name']) ?>">
                                </td>
                                <td><small style="color:var(--ink-400);">#<?= (int)$r['row_num'] ?></small></td>
                                <td>
                                    <b><?= e($r['raw_name']) ?></b>
                                </td>
                                <td>
                                    <select name="rows[<?= $rIdx ?>][employee_id]" class="select-emp" <?= $isLocked ? 'disabled' : '' ?>>
                                        <option value="">— اختر الموظف —</option>
                                        <?php foreach ($previewData['employees_list'] as $emp): ?>
                                            <option value="<?= (int)$emp['id'] ?>" <?= $matchedId === (int)$emp['id'] ? 'selected' : '' ?>>
                                                 <?= e($emp['full_name']) ?>
                                                <?= !empty($emp['branch_name']) ? ' (' . e($emp['branch_name']) . ')' : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="cell-cur-balance">
                                    <?php if ($matchedId === 0): ?>
                                        <span style="color:var(--ink-400);">—</span>
                                    <?php elseif ($advInfo['pending_count'] === 0): ?>
                                        <span style="color:var(--ink-500);">0.00 (لا توجد سلف مستديمة)</span>
                                    <?php elseif ($advInfo['pending_count'] === 1): ?>
                                        <span style="font-weight:600;color:var(--ink-800);"><?= number_format($curBal, 2) ?> ج</span>
                                    <?php else: ?>
                                        <span class="badge badge-neg">أكثر من سلفة مستديمة (<?= $advInfo['pending_count'] ?>)</span>
                                        <a href="hr.php?tab=advances" target="_blank" style="font-size:11px;color:var(--accent);display:block;margin-top:2px;">عرض في تاب السلف</a>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($isMissing): ?>
                                        <input type="number" step="0.01" name="rows[<?= $rIdx ?>][amount]" class="row-amount-input" value="" placeholder="مفيش رقم" style="border-color:var(--warn);">
                                        <span class="badge badge-warn" style="margin-top:3px;display:inline-block;">مفيش رقم</span>
                                    <?php elseif ($isNegative): ?>
                                        <input type="number" step="0.01" name="rows[<?= $rIdx ?>][amount]" class="row-amount-input is-negative" value="<?= number_format($r['parsed_amount'], 2, '.', '') ?>">
                                        <div style="font-size:11px;color:var(--neg);margin-top:3px;white-space:nowrap;"><i class="ti ti-alert-triangle"></i> الرقم سالب — عدّله قبل الرفع</div>
                                    <?php else: ?>
                                        <input type="number" step="0.01" name="rows[<?= $rIdx ?>][amount]" class="row-amount-input" value="<?= number_format($r['parsed_amount'], 2, '.', '') ?>" style="font-weight:600;">
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <input type="number" step="0.01" min="0" name="rows[<?= $rIdx ?>][monthly_deduction]" class="monthly-ded-input" value="500.00">
                                    <div class="warn-zero-ded" style="font-size:11px;color:var(--neg);margin-top:3px;display:none;white-space:nowrap;">
                                        <i class="ti ti-alert-triangle"></i> القسط صفر يعني السلفة كلها هتتخصم مرة واحدة
                                    </div>
                                </td>
                                <td class="cell-result">
                                    <?php if (!$r['selected_by_default']): ?>
                                        <?php if ($isLocked): ?>
                                            <span class="badge badge-neg">مقفول (أكثر من سلفة مستديمة معلّقة)</span>
                                        <?php elseif ($isNegative): ?>
                                            <span class="badge badge-neg">رقم سالب (مرفوض)</span>
                                        <?php elseif ($isMissing): ?>
                                            <span class="badge badge-warn">مفيش رقم (تخطي)</span>
                                        <?php elseif ($matchedId === 0): ?>
                                            <span class="badge badge-warn">غير مربوط بموظف</span>
                                        <?php else: ?>
                                            <span class="badge badge-neutral">تخطي (لن يتم رفعه)</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php if ($advInfo['pending_count'] === 0): ?>
                                            <span class="badge badge-pos"><i class="ti ti-plus"></i> سلفة مستديمة جديدة: <?= number_format($r['parsed_amount'], 2) ?> ج</span>
                                        <?php else: ?>
                                            <span class="badge badge-neutral"><i class="ti ti-refresh"></i> تحديث الرصيد إلى <?= number_format($r['parsed_amount'], 2) ?> ج</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <button type="submit" class="btn btn-success" id="btnConfirmImport" style="font-size:15px;padding:12px 28px;">
                    <i class="ti ti-check"></i> تأكيد رفع السلف المستديمة إلى قاعدة البيانات
                </button>
            </div>
        </form>
    </div>

    <script>
        const EMP_DATA = <?= json_encode($previewData['emp_js_data'], JSON_UNESCAPED_UNICODE) ?>;

        function updateRow(tr) {
            const cb = tr.querySelector('.row-cb');
            const empSelect = tr.querySelector('.select-emp');
            const amountInput = tr.querySelector('.row-amount-input');
            const dedInput = tr.querySelector('.monthly-ded-input');
            const curBalCell = tr.querySelector('.cell-cur-balance');
            const resultCell = tr.querySelector('.cell-result');
            const zeroDedWarn = tr.querySelector('.warn-zero-ded');

            const isChecked = cb && cb.checked;
            const empId = empSelect ? parseInt(empSelect.value, 10) || 0 : 0;
            const empInfo = EMP_DATA[empId] || null;

            const rawAmountStr = amountInput ? amountInput.value.trim() : '';
            const hasAmount = rawAmountStr !== '' && !isNaN(parseFloat(rawAmountStr));
            const amount = hasAmount ? parseFloat(rawAmountStr) : null;
            const isNegative = amount !== null && amount < 0;

            const rawDedStr = dedInput ? dedInput.value.trim() : '';
            const monthlyDed = (rawDedStr !== '' && !isNaN(parseFloat(rawDedStr))) ? Math.max(0, parseFloat(rawDedStr)) : 0;

            // 1) تحديث رصيد السلف المستديمة الحالي
            if (curBalCell) {
                if (!empInfo) {
                    curBalCell.innerHTML = '<span style="color:var(--ink-400);">—</span>';
                } else if (empInfo.pending_count === 0) {
                    curBalCell.innerHTML = '<span style="color:var(--ink-500);">0.00 (لا توجد سلف مستديمة)</span>';
                } else if (empInfo.pending_count === 1) {
                    curBalCell.innerHTML = '<span style="font-weight:600;color:var(--ink-800);">' + empInfo.current_balance.toFixed(2) + ' ج</span>';
                } else {
                    curBalCell.innerHTML = '<span class="badge badge-neg">أكثر من سلفة مستديمة (' + empInfo.pending_count + ')</span>'
                                         + '<a href="hr.php?tab=advances" target="_blank" style="font-size:11px;color:var(--accent);display:block;margin-top:2px;">عرض في تاب السلف</a>';
                }
            }

            // 2) معالجة القفل إذا كان للموظف أكثر من سلفة مستديمة معلّقة
            const isLocked = empInfo && empInfo.pending_count > 1;
            if (cb) {
                if (isLocked) {
                    cb.checked = false;
                    cb.disabled = true;
                    tr.classList.add('row-locked');
                } else {
                    cb.disabled = false;
                    tr.classList.remove('row-locked');
                }
            }

            // 3) ستايل حقل المبلغ
            if (amountInput) {
                if (isNegative) {
                    amountInput.classList.add('is-negative');
                } else {
                    amountInput.classList.remove('is-negative');
                }
            }

            // 4) تحذير القسط بصفر
            if (zeroDedWarn) {
                if (isChecked && monthlyDed <= 0 && amount !== null && amount > 0) {
                    zeroDedWarn.style.display = 'block';
                } else {
                    zeroDedWarn.style.display = 'none';
                }
            }

            // 5) النتيجة بعد الرفع
            if (resultCell) {
                if (!isChecked) {
                    resultCell.innerHTML = '<span class="badge badge-neutral">تخطي (لن يتم رفعه)</span>';
                } else if (empId === 0) {
                    resultCell.innerHTML = '<span class="badge badge-warn"><i class="ti ti-alert-triangle"></i> اختر الموظف</span>';
                } else if (isLocked) {
                    resultCell.innerHTML = '<span class="badge badge-neg">مقفول (أكثر من سلفة مستديمة)</span>';
                } else if (!hasAmount) {
                    resultCell.innerHTML = '<span class="badge badge-warn"><i class="ti ti-alert-triangle"></i> أدخل المبلغ</span>';
                } else if (isNegative) {
                    resultCell.innerHTML = '<span class="badge badge-neg"><i class="ti ti-alert-triangle"></i> مبلغ سالب (مرفوض)</span>';
                } else if (empInfo && empInfo.pending_count === 0) {
                    resultCell.innerHTML = '<span class="badge badge-pos"><i class="ti ti-plus"></i> سلفة مستديمة جديدة: ' + amount.toFixed(2) + ' ج'
                                         + (monthlyDed > 0 ? ' (قسط ' + monthlyDed.toFixed(2) + ' ج)' : ' (خصم كامل)') + '</span>';
                } else if (empInfo && empInfo.pending_count === 1) {
                    resultCell.innerHTML = '<span class="badge badge-neutral"><i class="ti ti-refresh"></i> تحديث الرصيد: ' + amount.toFixed(2) + ' ج'
                                         + ' (كان ' + empInfo.current_balance.toFixed(2) + ' ج)' + '</span>';
                }
            }
        }

        function updateStats() {
            let checkedCount = 0;
            let newCount = 0;
            let updateCount = 0;
            let negCount = 0;

            document.querySelectorAll('#advancesTable tbody tr').forEach(tr => {
                const cb = tr.querySelector('.row-cb');
                if (cb && cb.checked) {
                    checkedCount++;
                    const empSelect = tr.querySelector('.select-emp');
                    const empId = empSelect ? parseInt(empSelect.value, 10) || 0 : 0;
                    const empInfo = EMP_DATA[empId] || null;
                    const amountInput = tr.querySelector('.row-amount-input');
                    const rawAmount = amountInput ? amountInput.value.trim() : '';
                    const hasAmount = rawAmount !== '' && !isNaN(parseFloat(rawAmount));
                    const amount = hasAmount ? parseFloat(rawAmount) : null;

                    if (amount !== null && amount < 0) negCount++;

                    if (empInfo && hasAmount && amount >= 0) {
                        if (empInfo.pending_count === 0) newCount++;
                        else if (empInfo.pending_count === 1) updateCount++;
                    }
                }
            });

            const statChecked = document.getElementById('statChecked');
            const statNew = document.getElementById('statNew');
            const statUpdate = document.getElementById('statUpdate');
            const statNeg = document.getElementById('statNeg');

            if (statChecked) statChecked.textContent = checkedCount;
            if (statNew) statNew.textContent = newCount;
            if (statUpdate) statUpdate.textContent = updateCount;
            if (statNeg) statNeg.textContent = negCount;
        }

        // تفويض الأحداث على مستوى الجدول
        document.querySelector('#advancesTable tbody').addEventListener('change', function(e) {
            const tr = e.target.closest('tr');
            if (tr) updateRow(tr);
            updateStats();
        });

        document.querySelector('#advancesTable tbody').addEventListener('input', function(e) {
            const tr = e.target.closest('tr');
            if (tr) updateRow(tr);
            updateStats();
        });

        // تحديد / إلغاء تحديد الكل
        const masterCb = document.getElementById('masterCb');
        if (masterCb) {
            masterCb.addEventListener('change', function() {
                const cbs = document.querySelectorAll('.row-cb:not(:disabled)');
                cbs.forEach(cb => {
                    cb.checked = masterCb.checked;
                    updateRow(cb.closest('tr'));
                });
                updateStats();
            });
        }

        const btnSelectAll = document.getElementById('btnSelectAll');
        if (btnSelectAll) {
            btnSelectAll.addEventListener('click', function() {
                const cbs = document.querySelectorAll('.row-cb:not(:disabled)');
                cbs.forEach(cb => {
                    cb.checked = true;
                    updateRow(cb.closest('tr'));
                });
                if (masterCb) masterCb.checked = true;
                updateStats();
            });
        }

        const btnDeselectAll = document.getElementById('btnDeselectAll');
        if (btnDeselectAll) {
            btnDeselectAll.addEventListener('click', function() {
                const cbs = document.querySelectorAll('.row-cb');
                cbs.forEach(cb => {
                    cb.checked = false;
                    updateRow(cb.closest('tr'));
                });
                if (masterCb) masterCb.checked = false;
                updateStats();
            });
        }

        // تطبيق القسط الشهري الافتراضي على كل الصفوف
        const btnApplyGlobalDed = document.getElementById('btnApplyGlobalDed');
        if (btnApplyGlobalDed) {
            btnApplyGlobalDed.addEventListener('click', function() {
                const valStr = document.getElementById('globalMonthlyDed').value.trim();
                const val = (valStr !== '' && !isNaN(parseFloat(valStr))) ? Math.max(0, parseFloat(valStr)).toFixed(2) : '0.00';
                document.querySelectorAll('.monthly-ded-input').forEach(inp => {
                    inp.value = val;
                    updateRow(inp.closest('tr'));
                });
                updateStats();
            });
        }

        // التحقق من صحة النموذج قبل التأكيد
        const importForm = document.getElementById('importForm');
        if (importForm) {
            importForm.addEventListener('submit', function(e) {
                let checkedRows = 0;
                let errors = [];
                let seenEmp = {};

                document.querySelectorAll('#advancesTable tbody tr').forEach(tr => {
                    const cb = tr.querySelector('.row-cb');
                    if (cb && cb.checked) {
                        checkedRows++;
                        const rowNum = tr.dataset.rowNum || '';
                        const sheetName = tr.dataset.sheetName || '';
                        const empSelect = tr.querySelector('.select-emp');
                        const empId = empSelect ? parseInt(empSelect.value, 10) || 0 : 0;
                        const empText = empSelect && empSelect.selectedIndex > 0 ? empSelect.options[empSelect.selectedIndex].text : sheetName;

                        if (empId === 0) {
                            errors.push('صف #' + rowNum + ' (' + sheetName + '): يرجى اختيار الموظف المقابل في النظام.');
                            return;
                        }

                        if (seenEmp[empId]) {
                            errors.push('الموظف (' + empText + ') تم اختياره في أكثر من صف. لا يمكن تكرار نفس الموظف.');
                        }
                        seenEmp[empId] = true;

                        const amountInput = tr.querySelector('.row-amount-input');
                        const rawAmount = amountInput ? amountInput.value.trim() : '';
                        if (rawAmount === '' || isNaN(parseFloat(rawAmount))) {
                            errors.push('الموظف (' + empText + '): لا يوجد مبلغ صالح للرفع.');
                            return;
                        }

                        const amount = parseFloat(rawAmount);
                        if (amount < 0) {
                            errors.push('تعذر التأكيد: الموظف (' + empText + ') مبلغه سالب (' + amount + '). برجاء تعديل المبلغ أو إلغاء تحديد الصف قبل الرفع.');
                            return;
                        }

                        const empInfo = EMP_DATA[empId];
                        if (empInfo && empInfo.pending_count > 1) {
                            errors.push('الموظف (' + empText + ') لديه أكثر من سلفة مستديمة معلّقة حالياً. لا يمكن رفعه.');
                        }
                    }
                });

                if (checkedRows === 0) {
                    alert('برجاء تحديد صف واحد على الأقل للرفع.');
                    e.preventDefault();
                    return false;
                }

                if (errors.length > 0) {
                    alert(errors.join('\n'));
                    e.preventDefault();
                    return false;
                }

                if (!confirm('تأكيد رفع وتحديث أرصدة السلف المستديمة لـ ' + checkedRows + ' موظف في قاعدة البيانات؟')) {
                    e.preventDefault();
                    return false;
                }
            });
        }

        // تهيئة أولية للصفوف
        document.querySelectorAll('#advancesTable tbody tr').forEach(tr => {
            updateRow(tr);
        });
        updateStats();
    </script>
<?php endif; ?>

<?php
require_once __DIR__ . '/includes/footer.php';
