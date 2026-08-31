<?php
/**
 * attendance_import.php — استيراد سجلات الحضور والانصراف من نظام كروينجو (Crenjo)
 * نظام الفجالة ستور المالي
 *
 * شاشتان:
 *  1. رفع ملف الحضور (رفع -> معاينة شاملة قبل الحفظ -> استيراد على دفعات)
 *  2. ربط الموظفين (معرّف كروينجو empId -> موظف النظام)
 */
require_once __DIR__ . '/auth.php';
require_permission('hr:attendance_import');
require_once __DIR__ . '/xlsx_read_lib.php';

$pdo = db();
$tab = $_GET['tab'] ?? 'upload';
$flash = '';
$flashType = 'success';

$tmpDir = rtrim(sys_get_temp_dir(), '/') . '/fagala_attendance';
@mkdir($tmpDir, 0700, true);

// كنس الملفات المؤقتة التي مر عليها ساعتان
foreach ((array) glob($tmpDir . '/*.xlsx') as $old) {
    if (is_file($old) && filemtime($old) < time() - 7200) {
        @unlink($old);
    }
}

// ===== دوال مساعدة محلية لتنظيف وتحليل البيانات =====

function att_clean_val(?string $v): ?string
{
    if ($v === null) return null;
    $t = trim($v);
    if ($t === '' || $t === '-' || $t === '—') return null;
    return $t;
}

function att_parse_date(?string $v): ?string
{
    $v = att_clean_val($v);
    if ($v === null) return null;

    // YYYY-MM-DD أو YYYY/MM/DD
    if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', $v, $m)) {
        $y = (int) $m[1]; $mon = (int) $m[2]; $d = (int) $m[3];
        if (checkdate($mon, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mon, $d);
        }
    }

    // DD/MM/YYYY أو DD-MM-YYYY
    if (preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/', $v, $m)) {
        $d = (int) $m[1]; $mon = (int) $m[2]; $y = (int) $m[3];
        if (checkdate($mon, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mon, $d);
        }
    }

    // رقم تسلسلي من إكسيل (Excel Date Serial)
    if (is_numeric($v)) {
        $serial = (float) $v;
        if ($serial > 30000 && $serial < 70000) {
            $timestamp = ($serial - 25569) * 86400;
            return gmdate('Y-m-d', (int) $timestamp);
        }
    }

    $ts = strtotime($v);
    if ($ts !== false && $ts > 0) {
        return date('Y-m-d', $ts);
    }

    return null;
}

function att_parse_time(?string $v): ?string
{
    $v = att_clean_val($v);
    if ($v === null) return null;

    if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $v, $m)) {
        $h = (int) $m[1];
        $i = (int) $m[2];
        $s = isset($m[3]) ? (int) $m[3] : 0;
        if ($h >= 0 && $h < 24 && $i >= 0 && $i < 60 && $s >= 0 && $s < 60) {
            return sprintf('%02d:%02d:%02d', $h, $i, $s);
        }
    }

    // كسر يومي من إكسيل (مثلاً 0.375 يعادل 09:00:00)
    if (is_numeric($v)) {
        $num = (float) $v;
        if ($num >= 0 && $num < 1) {
            $sec = (int) round($num * 86400);
            $h = (int) floor($sec / 3600) % 24;
            $i = (int) floor(($sec % 3600) / 60);
            $s = $sec % 60;
            return sprintf('%02d:%02d:%02d', $h, $i, $s);
        }
    }

    return null;
}

function att_parse_hours(?string $v): ?float
{
    $v = att_clean_val($v);
    if ($v === null) return 0.00;

    // 1) صيغة الوقت HH:MM أو H:MM (مثل 11:00 أو 10:05 أو 07:43)
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $v, $m)) {
        $h = (int) $m[1];
        $i = (int) $m[2];
        if ($h >= 0 && $h <= 24 && $i >= 0 && $i < 60) {
            $dec = $h + ($i / 60.0);
            if ($dec <= 24.0) {
                return round($dec, 2);
            }
        }
        return null;
    }

    // 2) رقم عشري مباشر
    if (is_numeric($v)) {
        $f = (float) $v;
        if ($f >= 0 && $f <= 24.0) {
            return round($f, 2);
        }
        return null;
    }

    return null;
}

function att_parse_coord(?string $v): ?float
{
    $v = att_clean_val($v);
    if ($v === null) return null;
    if (is_numeric($v)) {
        return (float) $v;
    }
    return null;
}

function att_map_headers(array $headers): array
{
    $synonyms = [
        'emp_id'         => ['empid', 'emp id', 'معرف الموظف', 'كود الموظف'],
        'staff_no'       => ['رقم الموظف', 'كود', 'staff no', 'staff number'],
        'name'           => ['الاسم', 'اسم الموظف', 'name', 'employee name'],
        'job_title'      => ['المنصب الوظيفي', 'الوظيفة', 'المسمى الوظيفي', 'job title', 'position'],
        'dept'           => ['القسم', 'الإدارة', 'department', 'dept'],
        'branch'         => ['الفرع', 'branch'],
        'att_date'       => ['اليوم', 'التاريخ', 'تاريخ', 'date', 'day'],
        'expected_in'    => ['الحضور المنتظر', 'المتوقع حضور', 'expected in'],
        'expected_out'   => ['الانصراف المنتظر', 'المتوقع انصراف', 'expected out'],
        'check_in'       => ['الحضور الفعلي', 'حضور فعلي', 'وقت الحضور', 'check in', 'actual in', 'checkin'],
        'check_in_loc'   => ['موقع الحضور', 'in location'],
        'check_in_lat'   => ['latitude in', 'lat in', 'خط عرض الحضور'],
        'check_in_lng'   => ['longitude in', 'lng in', 'long in', 'خط طول الحضور'],
        'check_out'      => ['الانصراف الفعلي', 'انصراف فعلي', 'وقت الانصراف', 'check out', 'actual out', 'checkout'],
        'check_out_loc'  => ['موقع الانصراف', 'out location'],
        'check_out_lat'  => ['latitude out', 'lat out', 'خط عرض الانصراف'],
        'check_out_lng'  => ['longitude out', 'lng out', 'long out', 'خط طول الانصراف'],
        'work_hours'     => ['ساعات العمل', 'الساعات', 'ساعات', 'work hours', 'hours', 'total hours'],
        'leave_approved' => ['إجازة معتمدة', 'اجازة معتمدة', 'إجازة', 'اجازة', 'approved leave', 'leave'],
        'office_id'      => ['office id', 'office_id', 'معرف المكتب'],
        'team_id'        => ['team id', 'team_id', 'معرف الفريق'],
    ];

    $defaultPositions = [
        'emp_id'         => 0,
        'staff_no'       => 1,
        'name'           => 2,
        'job_title'      => 3,
        'dept'           => 4,
        'branch'         => 5,
        'att_date'       => 6,
        'expected_in'    => 7,
        'expected_out'   => 8,
        'check_in'       => 9,
        'check_in_loc'   => 10,
        'check_in_lat'   => 11,
        'check_in_lng'   => 12,
        'check_out'      => 13,
        'check_out_loc'  => 14,
        'check_out_lat'  => 15,
        'check_out_lng'  => 16,
        'work_hours'     => 17,
        'leave_approved' => 18,
        'office_id'      => 19,
        'team_id'        => 20,
    ];

    $map = [];
    $matchedByText = [];
    $fellBack = [];

    $cleanHeaders = [];
    foreach ($headers as $i => $h) {
        $t = mb_strtolower(trim((string) $h));
        $t = preg_replace('/\s+/u', ' ', $t);
        $cleanHeaders[$i] = $t;
    }

    foreach ($synonyms as $key => $syns) {
        foreach ($cleanHeaders as $colIdx => $headerText) {
            if ($headerText === '') continue;
            foreach ($syns as $syn) {
                if ($headerText === $syn || mb_strpos($headerText, $syn) !== false) {
                    $map[$key] = $colIdx;
                    $matchedByText[$key] = true;
                    break 2;
                }
            }
        }
    }

    foreach ($defaultPositions as $key => $pos) {
        if (!isset($map[$key])) {
            if ($pos < count($headers)) {
                $map[$key] = $pos;
                $fellBack[$key] = $pos;
            }
        }
    }

    return [
        'map'           => $map,
        'matched_text'  => $matchedByText,
        'fallback_keys' => $fellBack,
    ];
}

// ===== معالجة الطلبات (POST) =====
$preview = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $flash = 'انتهت صلاحية الجلسة، برجاء إعادة المحاولة.';
        $flashType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            // (1) رفع ملف للمعاينة
            if ($action === 'upload_file') {
                $f = $_FILES['attendance_file'] ?? null;
                if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('لم يتم رفع الملف بنجاح — حاول مرة أخرى.');
                }
                if ($f['size'] > 25 * 1024 * 1024) {
                    throw new RuntimeException('حجم الملف كبير جداً (أقصى حد 25 ميجا).');
                }

                $tok = bin2hex(random_bytes(16));
                $destPath = $tmpDir . '/' . $tok . '.xlsx';
                if (!move_uploaded_file($f['tmp_name'], $destPath)) {
                    throw new RuntimeException('تعذر حفظ الملف المؤقت على الخادم.');
                }

                $_SESSION['att_import_tok'] = $tok;
                $_SESSION['att_import_orig_name'] = $f['name'];

                $tab = 'upload';
            }

            // (2) حفظ ربط الموظفين (الشاشة الأولى)
            elseif ($action === 'save_mappings') {
                $mappings = $_POST['mappings'] ?? []; // [empId => employee_id]
                if (!is_array($mappings)) $mappings = [];

                // التحقق من شرط عدم تكرار ربط نفس الموظف بأكثر من empId
                $selectedEmpIds = [];
                foreach ($mappings as $extId => $empIdVal) {
                    $empIdVal = trim((string) $empIdVal);
                    if ($empIdVal !== '') {
                        $eid = (int) $empIdVal;
                        if (in_array($eid, $selectedEmpIds, true)) {
                            throw new RuntimeException('خطأ: لا يمكن ربط نفس الموظف بأكثر من معرّف كروينجو (empId). كل موظف يجب أن يرتبط بمعرّف واحد فقط.');
                        }
                        $selectedEmpIds[] = $eid;
                    }
                }

                // الربط المطلوب من الفورم: [رقم الموظف => معرّف كروينجو]
                $desired = [];
                foreach ($mappings as $extId => $empIdVal) {
                    $empIdVal = trim((string) $empIdVal);
                    if ($empIdVal !== '') $desired[(int) $empIdVal] = (string) $extId;
                }
                // المعرّفات اللي الشاشة دي بتتكلم عنها
                $formExtIds = array_map('strval', array_keys($mappings));

                // =========================================================
                //  🔴 خطوتين مش خطوة — والسبب مش تنظيم، ده بق حصل فعلاً:
                //
                //  الفهرس الفريد `uq_emp_external_id` بيتفحص **صف صف** وقت
                //  التحديث، مش في آخر الاستعلام. فلو نقلنا معرّف من موظف
                //  لموظف في `UPDATE ... CASE` واحد، ممكن الصف الجديد ياخد
                //  المعرّف **قبل** ما الصف القديم يسيبه — فالاتنين ماسكينه
                //  في نفس اللحظة والقاعدة ترمي:
                //      Duplicate entry '20362' for key 'uq_emp_external_id'
                //  وده اللي كان بيحصل لما محمد يصلّح ربط اتعمل غلط.
                //
                //  فبنفكّ الأول في استعلام لوحده، وبنعيّن بعدين.
                //  وكله جوه معاملة عشان مايحصلش إن الفك ينجح والتعيين يقع
                //  فيفضل الموظفين من غير ربط خالص.
                // =========================================================
                $pdo->beginTransaction();
                try {
                    // (١) مين ماسك المعرّفات دي دلوقتي؟
                    //     🔴 من غير `is_active = 1` بقصد — لو موظف موقوف
                    //     ماسك المعرّف، الشاشة مابتعرضهوش فمكانش فيه أي
                    //     طريقة تفكّه، والحفظ كان بيفضل يقع للأبد.
                    $holders = [];
                    if ($formExtIds) {
                        $ph  = implode(',', array_fill(0, count($formExtIds), '?'));
                        $sel = $pdo->prepare(
                            "SELECT id, full_name, external_id, is_active
                               FROM employees WHERE external_id IN ($ph)"
                        );
                        $sel->execute($formExtIds);
                        $holders = $sel->fetchAll();
                    }

                    $releaseIds = [];
                    $stolenFrom = [];   // أسامي اتفك عنهم الربط — نقولها لمحمد
                    foreach ($holders as $h) {
                        $hid  = (int) $h['id'];
                        $keep = $desired[$hid] ?? null;
                        // ماسك معرّف مش المفروض يفضل معاه؟ يتفك.
                        if ($keep !== (string) $h['external_id']) {
                            $releaseIds[] = $hid;
                            $stolenFrom[] = (string) $h['full_name']
                                . (((int) $h['is_active'] === 0) ? ' (موقوف)' : '');
                        }
                    }

                    if ($releaseIds) {
                        $ph = implode(',', array_fill(0, count($releaseIds), '?'));
                        $pdo->prepare("UPDATE employees SET external_id = NULL WHERE id IN ($ph)")
                            ->execute($releaseIds);
                    }

                    // (٢) التعيين — دلوقتي مفيش أي صف ماسك المعرّفات دي،
                    //     فاستعلام واحد مجمّع بيعدّي من غير تصادم.
                    $assigned = 0;
                    if ($desired) {
                        $cases = []; $params = []; $ids = [];
                        foreach ($desired as $eid => $ext) {
                            $cases[]  = 'WHEN id = ? THEN ?';
                            $params[] = $eid;
                            $params[] = $ext;
                            $ids[]    = $eid;
                        }
                        $ph = implode(',', array_fill(0, count($ids), '?'));
                        $pdo->prepare(
                            'UPDATE employees SET external_id = CASE ' . implode(' ', $cases)
                            . ' ELSE external_id END WHERE id IN (' . $ph . ')'
                        )->execute(array_merge($params, $ids));
                        $assigned = count($desired);
                    }

                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }

                $msg = 'اتحفظ الربط — ' . $assigned . ' موظف مربوط.';
                if ($stolenFrom) {
                    $msg .= ' واتفك الربط عن: ' . implode(' · ', $stolenFrom) . '.';
                }
                $_SESSION['flash'] = $msg;
                $_SESSION['flash_type'] = 'success';
                header('Location: attendance_import.php?tab=mapping');
                exit;
            }

            // (3) تنفيذ الاستيراد الفعلي إلى جدول attendance (الشاشة الثانية)
            elseif ($action === 'confirm_import') {
                $tok = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['token'] ?? ''));
                $sessionTok = $_SESSION['att_import_tok'] ?? '';
                if ($tok === '' || $sessionTok === '' || !hash_equals($sessionTok, $tok)) {
                    throw new RuntimeException('رمز الملف غير صالح أو منتهي الصلاحية — أعد رفع الملف.');
                }

                $conflictPolicy = ($_POST['conflict_policy'] ?? 'skip') === 'overwrite' ? 'overwrite' : 'skip';
                $filePath = $tmpDir . '/' . $tok . '.xlsx';

                if (!file_exists($filePath)) {
                    throw new RuntimeException('الملف المؤقت لم يعد متاحاً أو انتهت جلسته. يرجى إعادة رفع الملف.');
                }

                $rows = read_xlsx_rows($filePath);
                if (count($rows) < 2) {
                    throw new RuntimeException('الملف فارغ أو لا يحتوي على صفوف بيانات.');
                }

                $headers = $rows[0]['cells'];
                $headerInfo = att_map_headers($headers);
                $map = $headerInfo['map'];

                // جلب خريطة الموظفين النشطين بالـ external_id (استعلام مجمّع واحد)
                $empRows = $pdo->query('SELECT id, external_id FROM employees WHERE is_active = 1 AND external_id IS NOT NULL AND external_id != ""')->fetchAll();
                $empByExt = [];
                foreach ($empRows as $er) {
                    $empByExt[(string) $er['external_id']] = (int) $er['id'];
                }

                $validRecords = [];
                $unmappedCount = 0;
                $malformedCount = 0;
                $minDate = null;
                $maxDate = null;

                for ($i = 1; $i < count($rows); $i++) {
                    $rowNum = $rows[$i]['row'];
                    $r = $rows[$i]['cells'];
                    $rawEmpId = att_clean_val($r[$map['emp_id']] ?? null);
                    if ($rawEmpId === null) {
                        $unmappedCount++;
                        continue;
                    }

                    if (!isset($empByExt[$rawEmpId])) {
                        $unmappedCount++;
                        continue;
                    }

                    $employeeId = $empByExt[$rawEmpId];
                    $attDate = att_parse_date($r[$map['att_date']] ?? null);
                    $workHours = att_parse_hours($r[$map['work_hours']] ?? null);

                    if ($attDate === null || $workHours === null) {
                        $malformedCount++;
                        continue;
                    }

                    if ($minDate === null || $attDate < $minDate) $minDate = $attDate;
                    if ($maxDate === null || $attDate > $maxDate) $maxDate = $attDate;

                    $checkIn = att_parse_time($r[$map['check_in']] ?? null);
                    $checkOut = att_parse_time($r[$map['check_out']] ?? null);
                    $latIn = att_parse_coord($r[$map['check_in_lat']] ?? null);
                    $lngIn = att_parse_coord($r[$map['check_in_lng']] ?? null);
                    $latOut = att_parse_coord($r[$map['check_out_lat']] ?? null);
                    $lngOut = att_parse_coord($r[$map['check_out_lng']] ?? null);

                    $rawLeave = att_clean_val($r[$map['leave_approved']] ?? null);
                    $status = ($rawLeave !== null) ? 'leave' : 'present';

                    $validRecords[] = [
                        'row'           => $rowNum,
                        'employee_id'   => $employeeId,
                        'att_date'      => $attDate,
                        'check_in'      => $checkIn,
                        'check_out'     => $checkOut,
                        'check_in_lat'  => $latIn,
                        'check_in_lng'  => $lngIn,
                        'check_out_lat' => $latOut,
                        'check_out_lng' => $lngOut,
                        'work_hours'    => $workHours,
                        'status'        => $status,
                    ];
                }

                if (empty($validRecords)) {
                    throw new RuntimeException('لم يتم العثور على أي صفوف صالحة ومطابقة لموظفين مسجلين للاستيراد.');
                }

                // فحص السجلات الموجودة مسبقاً في جدول attendance (استعلام مجمّع واحد)
                $uniqueEmpIds = array_values(array_unique(array_column($validRecords, 'employee_id')));
                $empPlaceholders = implode(',', array_fill(0, count($uniqueEmpIds), '?'));
                $conflictSql = "SELECT employee_id, att_date, work_hours FROM attendance WHERE employee_id IN ($empPlaceholders) AND att_date BETWEEN ? AND ?";
                $conflictStmt = $pdo->prepare($conflictSql);
                $conflictStmt->execute(array_merge($uniqueEmpIds, [$minDate, $maxDate]));
                $existingRows = $conflictStmt->fetchAll();

                $existingIndex = [];
                foreach ($existingRows as $exr) {
                    $existingIndex[$exr['employee_id'] . '_' . $exr['att_date']] = (float) $exr['work_hours'];
                }

                $insertedCount = 0;
                $updatedCount = 0;
                $skippedConflictCount = 0;
                $protectedHoursCount = 0;

                // إزالة التكرار داخل نفس الملف وتصنيف كل صف (جديد / تحديث / تخطي تعارض / حماية ساعات)
                $seenInBatch = [];
                foreach ($validRecords as $vr) {
                    $pairKey = $vr['employee_id'] . '_' . $vr['att_date'];
                    if (isset($existingIndex[$pairKey])) {
                        $storedHours = (float) $existingIndex[$pairKey];
                        $fileHours = (float) $vr['work_hours'];

                        // حماية الساعات الفعلية: لو مسجل عندنا ساعات > 0 وملف كروينجو 0.00 (فارغ أو شرطة)
                        // يتم حماية الساعات وتخطي السجل تلقائياً لعدم تصفير راتب الموظف
                        if ($storedHours > 0 && $fileHours <= 0) {
                            $protectedHoursCount++;
                            continue;
                        }

                        if ($conflictPolicy === 'skip') {
                            $skippedConflictCount++;
                            continue;
                        }
                        $seenInBatch[$pairKey] = ['rec' => $vr, 'is_update' => true];
                    } else {
                        $seenInBatch[$pairKey] = ['rec' => $vr, 'is_update' => false];
                    }
                }

                foreach ($seenInBatch as $item) {
                    if ($item['is_update']) {
                        $updatedCount++;
                    } else {
                        $insertedCount++;
                    }
                }
                $recordsToInsert = array_column($seenInBatch, 'rec');

                // تنفيذ الكتابة على دفعات من 200 صف داخل معاملة واحدة (Transaction)
                $pdo->beginTransaction();
                try {
                    $chunks = array_chunk($recordsToInsert, 200);
                    foreach ($chunks as $chunk) {
                        $rowPlaceholders = [];
                        $params = [];
                        foreach ($chunk as $rec) {
                            $rowPlaceholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                            $params[] = $rec['employee_id'];
                            $params[] = $rec['att_date'];
                            $params[] = $rec['check_in'];
                            $params[] = $rec['check_out'];
                            $params[] = $rec['check_in_lat'];
                            $params[] = $rec['check_in_lng'];
                            $params[] = $rec['check_out_lat'];
                            $params[] = $rec['check_out_lng'];
                            $params[] = $rec['work_hours'];
                            $params[] = $rec['status'];
                            $params[] = 'استيراد كروينجو ' . date('Y-m-d');
                        }

                        if ($conflictPolicy === 'overwrite') {
                            // مسار الاستبدال: كتابة بيانات كروينجو وتصفير أعمدة البصمة الجغرافية التابعة للتطبيق وكتابة ملاحظة التحديث
                            $sql = 'INSERT INTO attendance (employee_id, att_date, check_in, check_out, check_in_lat, check_in_lng, check_out_lat, check_out_lng, work_hours, status, notes) VALUES '
                                 . implode(', ', $rowPlaceholders)
                                 . ' ON DUPLICATE KEY UPDATE '
                                 . 'check_in = VALUES(check_in), check_out = VALUES(check_out), '
                                 . 'check_in_lat = VALUES(check_in_lat), check_in_lng = VALUES(check_in_lng), '
                                 . 'check_out_lat = VALUES(check_out_lat), check_out_lng = VALUES(check_out_lng), '
                                 . 'work_hours = VALUES(work_hours), status = VALUES(status), '
                                 . 'check_in_dist = NULL, check_in_outside = 0, check_out_dist = NULL, check_out_outside = 0, '
                                 . 'notes = VALUES(notes)';
                        } else {
                            // مسار التخطي: إدخال السجلات الجديدة فقط دون المساس بالمسجل مسبقاً
                            $sql = 'INSERT INTO attendance (employee_id, att_date, check_in, check_out, check_in_lat, check_in_lng, check_out_lat, check_out_lng, work_hours, status, notes) VALUES '
                                 . implode(', ', $rowPlaceholders)
                                 . ' ON DUPLICATE KEY UPDATE id = id';
                        }

                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($params);
                    }
                    $pdo->commit();
                } catch (Throwable $dbEx) {
                    $pdo->rollBack();
                    throw new RuntimeException('فشلت عملية حفظ الحضور في قاعدة البيانات: ' . $dbEx->getMessage());
                }

                // تنظيف الملف المؤقت بعد إتمام الاستيراد
                @unlink($filePath);
                unset($_SESSION['att_import_tok'], $_SESSION['att_import_orig_name']);

                $summaryMsg = 'تم استيراد الحضور بنجاح!'
                    . ' — أضيف جديد: ' . number_format($insertedCount) . ' سجل'
                    . ' · تم تحديثه: ' . number_format($updatedCount) . ' سجل'
                    . ($protectedHoursCount > 0 ? ' · تم حماية ' . number_format($protectedHoursCount) . ' سجل (مسجل لها ساعات فعلية سابقة وملف كروينجو فارغ)' : '')
                    . ($skippedConflictCount > 0 ? ' · تم تخطي ' . number_format($skippedConflictCount) . ' سجل تعارض (مسجل مسبقاً)' : '')
                    . ($unmappedCount > 0 ? ' · تم استبعاد ' . number_format($unmappedCount) . ' سجل لموظفين غير مربوطين' : '')
                    . ($malformedCount > 0 ? ' · تم استبعاد ' . number_format($malformedCount) . ' سجل بها أخطاء تنسيق' : '');

                $_SESSION['flash'] = $summaryMsg;
                $_SESSION['flash_type'] = 'success';
                header('Location: attendance_import.php?tab=upload');
                exit;
            }

            // (4) إلغاء المعاينة
            elseif ($action === 'cancel_preview') {
                $tok = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['token'] ?? ''));
                $sessionTok = $_SESSION['att_import_tok'] ?? '';
                if ($tok === '' || $sessionTok === '' || !hash_equals($sessionTok, $tok)) {
                    throw new RuntimeException('رمز الملف غير صالح أو منتهي الصلاحية — أعد رفع الملف.');
                }
                @unlink($tmpDir . '/' . $tok . '.xlsx');
                unset($_SESSION['att_import_tok'], $_SESSION['att_import_orig_name']);
                $_SESSION['flash'] = 'تم إلغاء المعاينة وحذف الملف المؤقت.';
                header('Location: attendance_import.php?tab=upload');
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

// ===== قراءة وتجهيز بيانات المعاينة والشاشات =====

// فحص وجود ملف مؤقت نشط للمعاينة أو للربط
$activeTok = $_SESSION['att_import_tok'] ?? '';
$activeFileName = $_SESSION['att_import_orig_name'] ?? 'ملف الحضور';
$activeFilePath = ($activeTok !== '') ? ($tmpDir . '/' . $activeTok . '.xlsx') : '';

$fileDistinctCrenjoEmps = []; // لمصفوفة ربط الموظفين
$previewData = null; // لمصفوفة معاينة الاستيراد

if ($activeFilePath !== '' && file_exists($activeFilePath)) {
    try {
        $sheetRows = read_xlsx_rows($activeFilePath);
        if (count($sheetRows) >= 2) {
            $hInfo = att_map_headers($sheetRows[0]['cells']);
            $colMap = $hInfo['map'];

            // استخراج الموظفين المميزين من الملف
            for ($rIdx = 1; $rIdx < count($sheetRows); $rIdx++) {
                $row = $sheetRows[$rIdx]['cells'];
                $cEmpId = att_clean_val($row[$colMap['emp_id']] ?? null);
                if ($cEmpId === null) continue;

                if (!isset($fileDistinctCrenjoEmps[$cEmpId])) {
                    $fileDistinctCrenjoEmps[$cEmpId] = [
                        'emp_id'    => $cEmpId,
                        'staff_no'  => att_clean_val($row[$colMap['staff_no']] ?? null) ?? '—',
                        'name'      => att_clean_val($row[$colMap['name']] ?? null) ?? '—',
                        'branch'    => att_clean_val($row[$colMap['branch']] ?? null) ?? '—',
                        'job_title' => att_clean_val($row[$colMap['job_title']] ?? null) ?? '—',
                        'count'     => 0,
                    ];
                }
                $fileDistinctCrenjoEmps[$cEmpId]['count']++;
            }

            // إذا كان التبويب الحالي هو رفع الملف، نجهز بيانات المعاينة الكاملة
            if ($tab === 'upload') {
                // جلب الموظفين من القاعدة (استعلام مجمّع واحد)
                $dbEmployees = $pdo->query('SELECT id, full_name, external_id FROM employees WHERE is_active = 1 ORDER BY full_name')->fetchAll();
                $empByExtMap = [];
                foreach ($dbEmployees as $de) {
                    if ($de['external_id'] !== null && $de['external_id'] !== '') {
                        $empByExtMap[(string) $de['external_id']] = [
                            'id'   => (int) $de['id'],
                            'name' => $de['full_name'],
                        ];
                    }
                }

                $validRecords = [];
                $unmappedEmpDetails = [];
                $malformedRows = [];
                $fileDuplicates = [];
                $seenInPreview = [];
                $minDate = null;
                $maxDate = null;
                $mappedRowsCount = 0;

                for ($rIdx = 1; $rIdx < count($sheetRows); $rIdx++) {
                    $rowNum = $sheetRows[$rIdx]['row'];
                    $row = $sheetRows[$rIdx]['cells'];

                    $rawEmpId = att_clean_val($row[$colMap['emp_id']] ?? null);
                    $rawDate = att_clean_val($row[$colMap['att_date']] ?? null);
                    $rawHours = att_clean_val($row[$colMap['work_hours']] ?? null);
                    $rawName = att_clean_val($row[$colMap['name']] ?? null) ?? '—';
                    $rawStaffNo = att_clean_val($row[$colMap['staff_no']] ?? null) ?? '—';
                    $rawBranch = att_clean_val($row[$colMap['branch']] ?? null) ?? '—';

                    if ($rawEmpId === null || !isset($empByExtMap[$rawEmpId])) {
                        $key = $rawEmpId ?? ('unknown_' . $rowNum);
                        if (!isset($unmappedEmpDetails[$key])) {
                            $unmappedEmpDetails[$key] = [
                                'emp_id'   => $rawEmpId ?? 'مفقود',
                                'name'     => $rawName,
                                'staff_no' => $rawStaffNo,
                                'branch'   => $rawBranch,
                                'count'    => 0,
                            ];
                        }
                        $unmappedEmpDetails[$key]['count']++;
                        continue;
                    }

                    $mappedRowsCount++;
                    $empInfo = $empByExtMap[$rawEmpId];
                    $attDate = att_parse_date($rawDate);
                    $workHours = att_parse_hours($rawHours);

                    if ($attDate === null || $workHours === null) {
                        $malformedRows[] = [
                            'row'    => $rowNum,
                            'emp_id' => $rawEmpId,
                            'name'   => $empInfo['name'],
                            'date'   => $rawDate ?? 'فارغ',
                            'hours'  => $rawHours ?? 'فارغ',
                            'reason' => ($attDate === null ? 'تاريخ غير صالح' : 'قيمة ساعات غير صالحة'),
                        ];
                        continue;
                    }

                    // رصد التكرار داخل نفس الملف لنفس الموظف في نفس اليوم
                    $pairKey = $empInfo['id'] . '_' . $attDate;
                    if (isset($seenInPreview[$pairKey])) {
                        $prev = $seenInPreview[$pairKey];
                        $fileDuplicates[] = [
                            'employee_name' => $empInfo['name'],
                            'att_date'      => $attDate,
                            'first_row'     => $prev['row'],
                            'first_hours'   => $prev['work_hours'],
                            'second_row'    => $rowNum,
                            'second_hours'  => $workHours,
                        ];
                    }
                    $seenInPreview[$pairKey] = [
                        'row'        => $rowNum,
                        'work_hours' => $workHours,
                    ];

                    if ($minDate === null || $attDate < $minDate) $minDate = $attDate;
                    if ($maxDate === null || $attDate > $maxDate) $maxDate = $attDate;

                    $checkIn = att_parse_time($row[$colMap['check_in']] ?? null);
                    $checkOut = att_parse_time($row[$colMap['check_out']] ?? null);
                    $rawLeave = att_clean_val($row[$colMap['leave_approved']] ?? null);

                    $validRecords[] = [
                        'row'           => $rowNum,
                        'emp_id'        => $rawEmpId,
                        'employee_id'   => $empInfo['id'],
                        'employee_name' => $empInfo['name'],
                        'att_date'      => $attDate,
                        'check_in'      => $checkIn,
                        'check_out'     => $checkOut,
                        'work_hours'    => $workHours,
                        'status'        => ($rawLeave !== null ? 'leave' : 'present'),
                    ];
                }

                // فحص التعارضات مع البيانات المسجلة في النظام (استعلام مجمّع واحد)
                // 🔴 الاتنين لازم يتعرّفوا **بره** الشرط: لو مفيش ولا موظف مربوط
                //    (external_id فاضي عند الكل — وده حال أول استيراد)، الشرط
                //    بيبقى false والمعاينة كانت بتقع بـcount(null) في PHP 8.
                $conflicts = [];
                $protectedRecords = [];
                if (!empty($validRecords) && $minDate !== null && $maxDate !== null) {
                    $uniqueEmpIds = array_values(array_unique(array_column($validRecords, 'employee_id')));
                    $empPlaceholders = implode(',', array_fill(0, count($uniqueEmpIds), '?'));
                    $cSql = "SELECT a.employee_id, a.att_date, a.work_hours, a.check_in, a.check_out, e.full_name
                             FROM attendance a
                             JOIN employees e ON e.id = a.employee_id
                             WHERE a.employee_id IN ($empPlaceholders) AND a.att_date BETWEEN ? AND ?";
                    $cStmt = $pdo->prepare($cSql);
                    $cStmt->execute(array_merge($uniqueEmpIds, [$minDate, $maxDate]));
                    $existingRows = $cStmt->fetchAll();

                    $existingMap = [];
                    foreach ($existingRows as $er) {
                        $existingMap[$er['employee_id'] . '_' . $er['att_date']] = $er;
                    }

                    $conflicts = [];
                    $protectedRecords = [];

                    foreach ($validRecords as $vr) {
                        $pairKey = $vr['employee_id'] . '_' . $vr['att_date'];
                        if (isset($existingMap[$pairKey])) {
                            $ex = $existingMap[$pairKey];
                            $storedHours = (float) $ex['work_hours'];
                            $fileHours = (float) $vr['work_hours'];

                            // حماية الساعات الفعلية: لو مسجل ساعات > 0 وملف كروينجو 0.00 (فارغ أو شرطة)
                            if ($storedHours > 0 && $fileHours <= 0) {
                                $protectedRecords[] = [
                                    'employee_id'   => $vr['employee_id'],
                                    'employee_name' => $vr['employee_name'],
                                    'att_date'      => $vr['att_date'],
                                    'stored_hours'  => $storedHours,
                                    'file_hours'    => $fileHours,
                                    'stored_in'     => $ex['check_in'] ?? '—',
                                    'file_in'       => $vr['check_in'] ?? '—',
                                    'stored_out'    => $ex['check_out'] ?? '—',
                                    'file_out'      => $vr['check_out'] ?? '—',
                                ];
                            } else {
                                $conflicts[] = [
                                    'employee_id'   => $vr['employee_id'],
                                    'employee_name' => $vr['employee_name'],
                                    'att_date'      => $vr['att_date'],
                                    'stored_hours'  => $storedHours,
                                    'file_hours'    => $fileHours,
                                    'stored_in'     => $ex['check_in'] ?? '—',
                                    'file_in'       => $vr['check_in'] ?? '—',
                                    'stored_out'    => $ex['check_out'] ?? '—',
                                    'file_out'      => $vr['check_out'] ?? '—',
                                ];
                            }
                        }
                    }
                }

                $previewData = [
                    'token'             => $activeTok,
                    'file_name'         => $activeFileName,
                    'total_rows'        => count($sheetRows) - 1,
                    'mapped_count'      => $mappedRowsCount,
                    'unmapped_list'     => $unmappedEmpDetails,
                    'malformed_rows'    => $malformedRows,
                    'file_duplicates'   => $fileDuplicates,
                    'protected_records' => $protectedRecords,
                    'conflicts'         => $conflicts,
                    'date_from'         => $minDate,
                    'date_to'           => $maxDate,
                    'header_fallback'   => $hInfo['fallback_keys'],
                    'valid_count'       => count($validRecords),
                ];
            }
        }
    } catch (Throwable $readEx) {
        $flash = 'تعذر قراءة الملف المرفوع: ' . $readEx->getMessage();
        $flashType = 'error';
    }
}

// جلب قائمة كل الموظفين النشطين لشاشة الربط
$activeEmployeesList = $pdo->query('SELECT id, full_name, external_id, job_title FROM employees WHERE is_active = 1 ORDER BY full_name')->fetchAll();

$page_title = 'استيراد الحضور (كروينجو)';
$active_module = 'attendance_import';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    /* ===== أزرار متوافقة مع قواعد تصميم الفجالة ===== */
    .btn { background:var(--accent); color:#fff; border:none; padding:10px 18px; border-radius:8px;
        font-size:14px; font-family:inherit; cursor:pointer; text-decoration:none;
        display:inline-flex; align-items:center; gap:7px; white-space:nowrap; font-weight:500; }
    .btn:hover { background:var(--accent-hover); color:#fff; }
    .btn-sm { padding:6px 12px; font-size:12.5px; border-radius:6px; }
    .btn-ghost { background:var(--surface); color:var(--accent-text); border:1.5px solid var(--line); }
    .btn-ghost:hover { background:var(--accent-soft); border-color:var(--accent-text); color:var(--accent-text); }
    .btn-danger { background:var(--surface); color:var(--neg); border:1.5px solid var(--neg-soft); }
    .btn-danger:hover { background:var(--neg-soft); color:var(--neg); }
    .btn-success { background:var(--pos-fill); color:#fff; }
    .btn-success:hover { background:var(--pos-fill); color:#fff; }

    /* الكروت والتبويبات والجداول */
    .att-card { background:var(--surface); border:1px solid var(--line); border-radius:var(--r); padding:20px; margin-bottom:20px; box-shadow:var(--shadow-sm); }
    .att-h { font-size:16px; font-weight:600; color:var(--ink-900); margin-bottom:14px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
    .tabs { display:flex; gap:8px; margin-bottom:20px; border-bottom:1px solid var(--line); padding-bottom:10px; }
    .tab { padding:8px 16px; border-radius:8px; text-decoration:none; color:var(--ink-600); font-weight:500; font-size:14px; transition:all .15s; display:inline-flex; align-items:center; gap:6px; }
    .tab:hover { background:var(--accent-soft); color:var(--accent-text); }
    .tab.active { background:var(--accent); color:#fff; }

    .att-stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:14px; margin-bottom:20px; }
    .stat-box { background:var(--bg); border:1px solid var(--line); border-radius:var(--r); padding:14px; text-align:center; }
    .stat-val { font-size:22px; font-weight:700; color:var(--ink-900); font-family:'IBM Plex Mono', monospace; }
    .stat-lbl { font-size:13px; color:var(--ink-500); margin-top:4px; }

    .att-table { width:100%; border-collapse:collapse; font-size:13.5px; }
    .att-table th { background:var(--bg); padding:10px 12px; text-align:right; font-weight:600; color:var(--ink-700); border-bottom:1.5px solid var(--line); }
    .att-table td { padding:10px 12px; border-bottom:1px solid var(--line-soft); color:var(--ink-800); }
    .att-table tr:hover td { background:var(--line-soft); }

    .badge { display:inline-flex; align-items:center; padding:3px 8px; border-radius:6px; font-size:12px; font-weight:500; }
    .badge-pos { background:var(--pos-soft); color:var(--pos); }
    .badge-neg { background:var(--neg-soft); color:var(--neg); }
    .badge-warn { background:var(--warn-soft); color:var(--warn); }
    .badge-neutral { background:var(--bg); color:var(--ink-600); border:1px solid var(--line); }

    .alert { padding:12px 16px; border-radius:var(--r); margin-bottom:16px; font-size:13.5px; line-height:1.5; display:flex; align-items:flex-start; gap:10px; }
    .alert-pos { background:var(--pos-soft); color:var(--pos); border:1px solid rgba(44,110,82,.2); }
    .alert-neg { background:var(--neg-soft); color:var(--neg); border:1px solid rgba(157,59,56,.2); }
    .alert-warn { background:var(--warn-soft); color:var(--warn); border:1px solid rgba(138,101,36,.2); }

    .upload-zone { border:2px dashed var(--line); border-radius:var(--r); padding:30px; text-align:center; background:var(--bg); transition:border-color .2s; }
    .upload-zone:hover { border-color:var(--accent-text); }
    .select-emp { padding:7px 10px; border:1px solid var(--line); border-radius:6px; font-size:13.5px; font-family:inherit; background:var(--surface); min-width:220px; }
</style>

<div class="tabs">
    <a href="?tab=upload" class="tab <?= $tab === 'upload' ? 'active' : '' ?>">
        <i class="ti ti-upload"></i> رفع ومعاينة ملف الحضور
    </a>
    <a href="?tab=mapping" class="tab <?= $tab === 'mapping' ? 'active' : '' ?>">
        <i class="ti ti-link"></i> ربط الموظفين (كروينجو)
    </a>
</div>

<?php if ($flash): ?>
    <div class="alert <?= $flashType === 'error' ? 'alert-neg' : 'alert-pos' ?>">
        <i class="ti <?= $flashType === 'error' ? 'ti-alert-triangle' : 'ti-circle-check' ?>" style="font-size:18px;margin-top:2px;"></i>
        <div><?= nl2br(e($flash)) ?></div>
    </div>
<?php endif; ?>

<?php // =============================================================
      //  التبويب الأول: رفع ومعاينة ملف الحضور (upload -> preview -> import)
      // ============================================================= ?>
<?php if ($tab === 'upload'): ?>

    <?php if (!$previewData): ?>
        <div class="att-card">
            <div class="att-h">
                <span>رفع ملف الحضور الشهري من كروينجو</span>
            </div>
            <p style="font-size:13.5px;color:var(--ink-600);margin-bottom:18px;line-height:1.6;">
                الملف المصدّر من نظام كروينجو (Crenjo) يكون عادةً باسم <code>reportAttendance.xls</code> (وهو ملف إكسيل XLSX حديث).
                الرفع والمعاينة <b>للقراءة فقط</b> ولن يتم تعديل أو كتابة أي سجل في الحضور إلا بعد مراجعة المعاينة والضغط على زر الاستيراد.
            </p>

            <form method="post" enctype="multipart/form-data" class="upload-zone">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="upload_file">
                <i class="ti ti-file-spreadsheet" style="font-size:42px;color:var(--accent-text);display:block;margin-bottom:12px;"></i>
                <div style="font-weight:600;color:var(--ink-800);margin-bottom:8px;">اختر ملف الحضور من جهازك</div>
                <div style="margin-bottom:16px;">
                    <input type="file" name="attendance_file" accept=".xls,.xlsx" required style="font-size:13.5px;color:var(--ink-700);">
                </div>
                <button type="submit" class="btn">
                    <i class="ti ti-eye"></i> رفع ومعاينة الملف
                </button>
            </form>
        </div>
    <?php else: ?>
        <?php // ===== شاشة المعاينة الكاملة قبل الحفظ ===== ?>
        <div class="att-card" style="border:2px solid var(--accent);">
            <div class="att-h">
                <span>
                    <i class="ti ti-eye" style="color:var(--accent-text);margin-left:6px;"></i>
                    معاينة ملف: <b><?= e($previewData['file_name']) ?></b>
                </span>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="cancel_preview">
                    <input type="hidden" name="token" value="<?= e($previewData['token']) ?>">
                    <button type="submit" class="btn btn-ghost btn-sm">
                        <i class="ti ti-arrow-back-up"></i> إلغاء ورفع ملف آخر
                    </button>
                </form>
            </div>

            <?php if (!empty($previewData['header_fallback'])): ?>
                <div class="alert alert-warn">
                    <i class="ti ti-alert-triangle" style="font-size:18px;"></i>
                    <div>
                        <b>تنبيه بخصوص ترتيب الأعمدة:</b> تم التعرف على بعض الأعمدة حسب ترتيبها الافتراضي لعدم تطابق نص العنوان بالضبط
                        (<?= e(implode(' · ', array_keys($previewData['header_fallback']))) ?>).
                    </div>
                </div>
            <?php endif; ?>

            <div class="att-stats">
                <div class="stat-box">
                    <div class="stat-val"><?= number_format($previewData['total_rows']) ?></div>
                    <div class="stat-lbl">إجمالي صفوف الملف</div>
                </div>
                <div class="stat-box">
                    <div class="stat-val" style="color:var(--pos);"><?= number_format($previewData['valid_count']) ?></div>
                    <div class="stat-lbl">جاهز للاستيراد (موظف معرّف)</div>
                </div>
                <div class="stat-box">
                    <div class="stat-val" style="color:<?= !empty($previewData['unmapped_list']) ? 'var(--neg)' : 'var(--ink-700)' ?>;">
                        <?= count($previewData['unmapped_list']) ?>
                    </div>
                    <div class="stat-lbl">موظفون غير مربوطين بالنظام</div>
                </div>
                <div class="stat-box">
                    <div class="stat-val" style="color:<?= !empty($previewData['protected_records']) ? 'var(--pos)' : 'var(--ink-700)' ?>;">
                        <?= count($previewData['protected_records']) ?>
                    </div>
                    <div class="stat-lbl">سجلات محمية من التصفير</div>
                </div>
                <div class="stat-box">
                    <div class="stat-val" style="color:<?= !empty($previewData['conflicts']) ? 'var(--warn)' : 'var(--ink-700)' ?>;">
                        <?= count($previewData['conflicts']) ?>
                    </div>
                    <div class="stat-lbl">تعارض مع حضور مسجّل مسبقاً</div>
                </div>
            </div>

            <div style="font-size:13.5px;color:var(--ink-700);margin-bottom:16px;background:var(--bg);padding:10px 14px;border-radius:6px;">
                <b>نطاق التواريخ المكتشف في الملف:</b>
                <?= $previewData['date_from'] ? e($previewData['date_from']) : '—' ?>
                إلى
                <?= $previewData['date_to'] ? e($previewData['date_to']) : '—' ?>
            </div>

            <?php // قائمة الموظفين غير المربوطين ?>
            <?php if (!empty($previewData['unmapped_list'])): ?>
                <div class="alert alert-neg" style="flex-direction:column;gap:8px;">
                    <div style="font-weight:600;display:flex;align-items:center;gap:6px;">
                        <i class="ti ti-alert-triangle"></i>
                        يوجد <?= count($previewData['unmapped_list']) ?> معرّف موظف في كروينجو غير مربوط بأي موظف في نظامنا:
                    </div>
                    <div style="font-size:13px;">
                        لن يتم استيراد صفوف هؤلاء الموظفين إطلاقاً حتى يتم ربطهم لضمان عدم ترحيل ساعات لأشخاص خاطئين.
                    </div>
                    <div style="margin-top:6px;width:100%;overflow-x:auto;">
                        <table class="att-table" style="background:var(--surface);border-radius:6px;">
                            <thead>
                                <tr>
                                    <th>معرّف كروينجو (empId)</th>
                                    <th>رقم الموظف</th>
                                    <th>الاسم في الملف</th>
                                    <th>الفرع</th>
                                    <th>عدد الصفوف</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($previewData['unmapped_list'] as $um): ?>
                                    <tr>
                                        <td><code><?= e((string)$um['emp_id']) ?></code></td>
                                        <td><?= e((string)$um['staff_no']) ?></td>
                                        <td><b><?= e((string)$um['name']) ?></b></td>
                                        <td><?= e((string)$um['branch']) ?></td>
                                        <td><span class="badge badge-neutral"><?= (int)$um['count'] ?> صف</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div style="margin-top:6px;">
                        <a href="?tab=mapping" class="btn btn-sm btn-ghost" style="background:var(--surface);">
                            <i class="ti ti-link"></i> الذهاب لشاشة ربط الموظفين الآن
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <?php // قائمة الصفوف ذات التنسيق غير الصالح ?>
            <?php if (!empty($previewData['malformed_rows'])): ?>
                <div class="alert alert-warn" style="flex-direction:column;gap:8px;">
                    <div style="font-weight:600;display:flex;align-items:center;gap:6px;">
                        <i class="ti ti-alert-triangle"></i>
                        يوجد <?= count($previewData['malformed_rows']) ?> صف يحتوي على بيانات غير صالحة (تاريخ أو ساعات):
                    </div>
                    <div style="font-size:13px;">سيتم استبعاد هذه الصفوف تلقائياً أثناء الاستيراد.</div>
                    <div style="width:100%;overflow-x:auto;">
                        <table class="att-table" style="background:var(--surface);border-radius:6px;">
                            <thead>
                                <tr>
                                    <th>رقم الصف</th>
                                    <th>الموظف</th>
                                    <th>التاريخ بالملف</th>
                                    <th>الساعات بالملف</th>
                                    <th>سبب الخطأ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($previewData['malformed_rows'] as $mf): ?>
                                    <tr>
                                        <td>#<?= (int)$mf['row'] ?></td>
                                        <td><?= e($mf['name']) ?> (<code><?= e((string)$mf['emp_id']) ?></code>)</td>
                                        <td><?= e((string)$mf['date']) ?></td>
                                        <td><?= e((string)$mf['hours']) ?></td>
                                        <td><span class="badge badge-neg"><?= e($mf['reason']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <?php // قائمة السجلات المكررة داخل نفس الملف ?>
            <?php if (!empty($previewData['file_duplicates'])): ?>
                <div class="alert alert-warn" style="flex-direction:column;gap:8px;">
                    <div style="font-weight:600;display:flex;align-items:center;gap:6px;">
                        <i class="ti ti-alert-triangle"></i>
                        يوجد <?= count($previewData['file_duplicates']) ?> تكرار لنفس الموظف في نفس التاريخ داخل الملف (سيتم اعتماد السطر الأخير تلقائياً):
                    </div>
                    <div style="font-size:13px;">يوضح الجدول التالي السطور المكررة بالملف وساعات كل منها، مع بيان السطر الذي سيتم اعتماده:</div>
                    <div style="width:100%;overflow-x:auto;">
                        <table class="att-table" style="background:var(--surface);border-radius:6px;">
                            <thead>
                                <tr>
                                    <th>الموظف</th>
                                    <th>التاريخ</th>
                                    <th>السطر الأول وساعاته</th>
                                    <th>السطر الأخير وساعاته (المعتمد)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($previewData['file_duplicates'] as $dup): ?>
                                    <tr>
                                        <td><b><?= e($dup['employee_name']) ?></b></td>
                                        <td><?= e($dup['att_date']) ?></td>
                                        <td>صف #<?= (int)$dup['first_row'] ?> (<span class="badge badge-neutral"><?= number_format((float)$dup['first_hours'], 2) ?> س</span>)</td>
                                        <td>صف #<?= (int)$dup['second_row'] ?> (<span class="badge badge-pos"><?= number_format((float)$dup['second_hours'], 2) ?> س</span>)</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <?php // قائمة السجلات المحمية تلقائياً لمنع تصفير الساعات والرواتب ?>
            <?php if (!empty($previewData['protected_records'])): ?>
                <div class="alert alert-pos" style="flex-direction:column;gap:8px;margin-top:20px;margin-bottom:20px;">
                    <div style="font-weight:600;display:flex;align-items:center;gap:6px;">
                        <i class="ti ti-circle-check"></i>
                        سجلات محمية تلقائياً من التصفير (<?= count($previewData['protected_records']) ?> سجل):
                    </div>
                    <div style="font-size:13px;">
                        هذه السجلات مسجل لها ساعات عمل فعلية سابقة في النظام بينما ملف كروينجو لا يحمل ساعات لهذا اليوم (فارغ أو -).
                        <b>تمت حمايتها والاحتفاظ بساعاتها تلقائياً لضمان عدم ضياع أي أجر مستحق للموظف</b> حتى في حال اختيار الاستبدال.
                    </div>
                    <div style="max-height:240px;overflow-y:auto;width:100%;border:1px solid rgba(44,110,82,.2);border-radius:var(--r);">
                        <table class="att-table" style="background:var(--surface);">
                            <thead>
                                <tr>
                                    <th>الموظف</th>
                                    <th>التاريخ</th>
                                    <th>الساعات المحفوظة بالنظام (المعتمدة)</th>
                                    <th>الساعات بملف كروينجو</th>
                                    <th>حالة الحماية</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($previewData['protected_records'] as $pr): ?>
                                    <tr>
                                        <td><b><?= e($pr['employee_name']) ?></b></td>
                                        <td><?= e($pr['att_date']) ?></td>
                                        <td><span class="badge badge-pos"><?= number_format((float)$pr['stored_hours'], 2) ?> س (محفوظة)</span></td>
                                        <td><span class="badge badge-neutral"><?= number_format((float)$pr['file_hours'], 2) ?> س (فارغ)</span></td>
                                        <td><span class="badge badge-pos"><i class="ti ti-check" style="margin-left:3px;"></i> محمي من التصفير</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <?php // جدول التعارضات مع السجلات المسجلة مسبقاً ?>
            <?php if (!empty($previewData['conflicts'])): ?>
                <div style="margin-top:20px;margin-bottom:20px;">
                    <div style="font-weight:600;color:var(--ink-900);margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                        <i class="ti ti-clock-hour-4" style="color:var(--warn);"></i>
                        تفاصيل السجلات المتعارضة مع بيانات الحضور المسجلة مسبقاً (<?= count($previewData['conflicts']) ?> سجل):
                    </div>
                    <p style="font-size:13px;color:var(--ink-600);margin-bottom:10px;">
                        قد تكون هذه السجلات ناتجة عن تسجيل دخول سابق من التطبيق أو استيراد سابق لنفس الشهر:
                    </p>
                    <div style="max-height:280px;overflow-y:auto;border:1px solid var(--line);border-radius:var(--r);">
                        <table class="att-table">
                            <thead>
                                <tr>
                                    <th>الموظف</th>
                                    <th>التاريخ</th>
                                    <th>الساعات عندنا</th>
                                    <th>الساعات بالملف</th>
                                    <th>الوقت عندنا (حضور/انصراف)</th>
                                    <th>الوقت بالملف (حضور/انصراف)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($previewData['conflicts'] as $conf): ?>
                                    <tr>
                                        <td><b><?= e($conf['employee_name']) ?></b></td>
                                        <td><?= e($conf['att_date']) ?></td>
                                        <td><span class="badge badge-neutral"><?= number_format((float)$conf['stored_hours'], 2) ?> س</span></td>
                                        <td><span class="badge badge-warn"><?= number_format((float)$conf['file_hours'], 2) ?> س</span></td>
                                        <td><small><?= e($conf['stored_in']) ?> → <?= e($conf['stored_out']) ?></small></td>
                                        <td><small><?= e($conf['file_in']) ?> → <?= e($conf['file_out']) ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <?php // نموذج تأكيد الاستيراد واختيار سياسة التعارض ?>
            <form method="post" style="margin-top:24px;border-top:1.5px solid var(--line);padding-top:18px;">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="confirm_import">
                <input type="hidden" name="token" value="<?= e($previewData['token']) ?>">

                <div style="margin-bottom:18px;background:var(--bg);padding:14px;border-radius:var(--r);border:1px solid var(--line);">
                    <div style="font-weight:600;color:var(--ink-900);margin-bottom:10px;">سياسة التعامل مع السجلات المسجلة مسبقاً (التعارضات):</div>
                    <label style="display:flex;align-items:center;gap:8px;font-size:13.5px;color:var(--ink-800);margin-bottom:8px;cursor:pointer;">
                        <input type="radio" name="conflict_policy" value="skip" checked>
                        <span><b>خلّي المسجّل عندنا زي ما هو</b> — تخطّي السجلات المتعارضة والاحتفاظ ببياناتنا الحالية (الخيار الافتراضي والآمن)</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;font-size:13.5px;color:var(--ink-800);cursor:pointer;">
                        <input type="radio" name="conflict_policy" value="overwrite">
                        <span><b>اكتب فوقه ببيانات كروينجو</b> — استبدال وتحديث السجلات المتعارضة بساعات وبيانات الملف المرفوع</span>
                    </label>
                </div>

                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <button type="submit" class="btn btn-success" style="font-size:15px;padding:12px 24px;">
                        <i class="ti ti-check"></i> تأكيد استيراد <?= number_format($previewData['valid_count']) ?> سجل إلى جدول الحضور
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>

<?php // =============================================================
      //  التبويب الثاني: ربط الموظفين (Screen One: Employee Mapping)
      // ============================================================= ?>
<?php elseif ($tab === 'mapping'): ?>

    <div class="att-card">
        <div class="att-h">
            <span>ربط موظفي كروينجو (empId) بموظفي النظام</span>
            <?php if (!empty($fileDistinctCrenjoEmps)): ?>
                <span class="badge badge-pos">
                    <i class="ti ti-file-spreadsheet" style="margin-left:4px;"></i>
                    الملف النشط: <?= e($activeFileName) ?> (<?= count($fileDistinctCrenjoEmps) ?> موظف)
                </span>
            <?php endif; ?>
        </div>

        <p style="font-size:13.5px;color:var(--ink-600);margin-bottom:16px;line-height:1.6;">
            يتم الربط حصراً بواسطة <b>كود كروينجو (empId)</b>، ويُمنع الاعتماد على الأسماء لتجنب أخطاء المسافات وتشابه الأسماء.
            كل موظف في نظامنا يرتبط بمعرّف واحد فقط. الموظفون غير المربوطين لن يتم استيراد حضورهم.
        </p>

        <?php if (empty($fileDistinctCrenjoEmps)): ?>
            <div class="alert alert-warn" style="margin-bottom:20px;">
                <i class="ti ti-alert-triangle" style="font-size:18px;"></i>
                <div>
                    لا يوجد ملف حضور مرفوع حالياً. يمكنك رفع ملف كروينجو الآن لقراءة جميع أكواد الموظفين (empId) الموجودة به وربطها بسهولة.
                </div>
            </div>

            <form method="post" enctype="multipart/form-data" class="upload-zone" style="margin-bottom:24px;">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="upload_file">
                <i class="ti ti-upload" style="font-size:36px;color:var(--accent-text);display:block;margin-bottom:10px;"></i>
                <div style="font-weight:600;margin-bottom:8px;">ارفع ملف كروينجو لاستخراج معرّفات الموظفين</div>
                <input type="file" name="attendance_file" accept=".xls,.xlsx" required style="font-size:13px;margin-bottom:12px;">
                <br>
                <button type="submit" class="btn btn-sm"><i class="ti ti-eye"></i> قراءة الموظفين من الملف</button>
            </form>
        <?php endif; ?>

        <?php if (!empty($fileDistinctCrenjoEmps)): ?>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_mappings">

                <div style="overflow-x:auto;margin-bottom:20px;">
                    <table class="att-table">
                        <thead>
                            <tr>
                                <th>معرّف كروينجو (empId)</th>
                                <th>رقم الموظف بالملف</th>
                                <th>الاسم في كروينجو</th>
                                <th>الوظيفة / الفرع</th>
                                <th>عدد الصفوف</th>
                                <th>الموظف المقابل في نظامنا</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($fileDistinctCrenjoEmps as $cId => $cEmp): ?>
                                <?php
                                    // البحث عن الموظف المربوط حالياً بهذا المعرف
                                    $currentMatchedEmpId = 0;
                                    foreach ($activeEmployeesList as $ae) {
                                        if ($ae['external_id'] !== null && (string)$ae['external_id'] === (string)$cId) {
                                            $currentMatchedEmpId = (int) $ae['id'];
                                            break;
                                        }
                                    }
                                ?>
                                <tr>
                                    <td><code><?= e((string)$cId) ?></code></td>
                                    <td><?= e((string)$cEmp['staff_no']) ?></td>
                                    <td><b><?= e((string)$cEmp['name']) ?></b></td>
                                    <td><small><?= e((string)$cEmp['job_title']) ?> · <?= e((string)$cEmp['branch']) ?></small></td>
                                    <td><span class="badge badge-neutral"><?= (int)$cEmp['count'] ?> صف</span></td>
                                    <td>
                                        <select name="mappings[<?= e((string)$cId) ?>]" class="select-emp">
                                            <option value="">— غير مربوط —</option>
                                            <?php foreach ($activeEmployeesList as $ae): ?>
                                                <option value="<?= (int)$ae['id'] ?>" <?= $currentMatchedEmpId === (int)$ae['id'] ? 'selected' : '' ?>>
                                                    <?= e($ae['full_name']) ?>
                                                    <?= !empty($ae['job_title']) ? ' (' . e($ae['job_title']) . ')' : '' ?>
                                                    <?= ($ae['external_id'] !== null && (string)$ae['external_id'] === (string)$cId) ? ' [مربوط حالياً]' : '' ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div style="display:flex;align-items:center;gap:10px;">
                    <button type="submit" class="btn btn-success">
                        <i class="ti ti-device-floppy"></i> حفظ ربط الموظفين
                    </button>
                    <a href="?tab=upload" class="btn btn-ghost">
                        <i class="ti ti-arrow-right"></i> الذهاب لمعاينة الحضور والاستيراد
                    </a>
                </div>
            </form>
        <?php else: ?>
            <?php // عرض الموظفين المربوطين حالياً في قاعدة البيانات ?>
            <div style="margin-top:20px;">
                <div style="font-weight:600;color:var(--ink-900);margin-bottom:12px;">الموظفون المسجلون في النظام وحالة ربطهم بكروينجو:</div>
                <div style="overflow-x:auto;">
                    <table class="att-table">
                        <thead>
                            <tr>
                                <th>اسم الموظف في النظام</th>
                                <th>الوظيفة</th>
                                <th>معرّف كروينجو المرتبط (external_id)</th>
                                <th>حالة الربط</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activeEmployeesList as $ae): ?>
                                <tr>
                                    <td><b><?= e($ae['full_name']) ?></b></td>
                                    <td><?= e($ae['job_title'] ?? '—') ?></td>
                                    <td>
                                        <?php if ($ae['external_id']): ?>
                                            <code><?= e($ae['external_id']) ?></code>
                                        <?php else: ?>
                                            <span style="color:var(--ink-400);">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($ae['external_id']): ?>
                                            <span class="badge badge-pos"><i class="ti ti-check" style="margin-left:3px;"></i> مربوط</span>
                                        <?php else: ?>
                                            <span class="badge badge-neutral">غير مربوط</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

<?php endif; ?>

<?php
require_once __DIR__ . '/includes/footer.php';
