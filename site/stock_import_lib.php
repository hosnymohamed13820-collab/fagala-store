<?php
/**
 * stock_import_lib.php — استيراد حركات True للدفتر والمطابقة وتقارير الحركة (بريف ٢١٨)
 * نظام الفجالة ستور المالي
 *
 * بيستعمل stock_lib.php و item_units_lib.php بس.
 * الدفتر والوحدات بيطلعوا نفس رصيد True بالظبط.
 */
declare(strict_types=1);

require_once __DIR__ . '/stock_lib.php';
require_once __DIR__ . '/item_units_lib.php';

// حد الرن الواحد في التشغيل التلقائي (بريف ٢١٨ر):
// ٣٠٠ فاتورة اتعملت فعلاً أو ٢٠ ثانية — أيهما أول. الباقي بيكمّل في الرن الجاي.
// زرار «استورد دلوقتي» مابيستخدمش الحد ده (بيمرّر maxInvoices = null).
const STOCK_IMPORT_AUTO_MAX_INVOICES = 300;
const STOCK_IMPORT_AUTO_MAX_SECONDS  = 20;

// الموردين الداخليين لتحويلات True
const STOCK_INTERNAL_SUPPLIERS = [
    4,       // فرع بونجور
    1000180, // فرع دجلة
    1000181, // فرع ABC
    3000091, // فرع الفجالة
    1000182, // الرئيسية (الإدارة)
    1000245, // إشراقة
    1000252, // إشراقة
    1000195, // رصيد افتتاحي
    1000192, // مستهلكات
];

/**
 * حساب بصمة الفاتورة sha1 لمنع التكرار واكتشاف التعديل في True.
 */
function stock_invoice_hash(array $head, array $lines): string
{
    $parts = [];
    $parts[] = implode('|', [
        $head['doc_type'] ?? '',
        $head['supplier_id'] ?? '',
        $head['company_branch'] ?? '',
        $head['invoice_date'] ?? '',
    ]);

    // ترتيب السطور برقم السطر لضمان تطابق التجزئة
    usort($lines, static function ($a, $b) {
        return ((int) ($a['line_no'] ?? 0)) <=> ((int) ($b['line_no'] ?? 0));
    });

    foreach ($lines as $l) {
        $parts[] = implode('|', [
            $l['line_no'] ?? '',
            $l['item_id'] ?? '',
            trim((string) ($l['item_code'] ?? '')),
            round((float) ($l['quantity'] ?? 0), 3),
            round((float) ($l['cost_price'] ?? 0), 4),
            round((float) ($l['unit_factor'] ?? 0), 4),
        ]);
    }

    return sha1(implode("\n", $parts));
}

/**
 * خريطة تحويل agent_key إلى branch_id
 */
function stock_agent_to_branch_map(PDO $pdo): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $map = [
        'zahraa'  => 3, // الفجالة
        'bonjour' => 1, // بونجور
        'degla'   => 4, // دجلة
        'abc'     => 2, // ABC
    ];

    try {
        $st = $pdo->query('SELECT agent_key, sto_id FROM hq_branch_balances GROUP BY agent_key, sto_id');
        $bRows = $pdo->query('SELECT id, sto_id FROM branches WHERE sto_id IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC);
        $stoToBranch = [];
        foreach ($bRows as $b) {
            $stoToBranch[(int) $b['sto_id']] = (int) $b['id'];
        }
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $ak = trim((string) $r['agent_key']);
            $sid = (int) $r['sto_id'];
            if (isset($stoToBranch[$sid])) {
                $map[$ak] = $stoToBranch[$sid];
            }
        }
    } catch (Throwable $e) {}

    return $map;
}

/**
 * خريطة الموردين المحليين من suppliers.erp_supplier_id
 */
function stock_suppliers_erp_map(PDO $pdo): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    try {
        $st = $pdo->query('SELECT id, erp_supplier_id FROM suppliers WHERE erp_supplier_id IS NOT NULL');
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $map[(int) $r['erp_supplier_id']] = (int) $r['id'];
        }
    } catch (Throwable $e) {}
    return $map;
}

/**
 * فحص حد الرن الواحد (بريف ٢١٨ر): ٢٠ ثانية أو العدد المسموح — أيهما أول
 *
 * بتتنادي **قبل** الفاتورة الجديدة (مش بعدها) عشان الرن مايعدّيش الحد.
 * لو رجّعت true يبقى وقفنا والكمّل في الرن الجاي.
 */
function stock_import_cap_hit(array &$capState): bool
{
    if (!empty($capState['stop'])) {
        return true;
    }
    if ($capState['max'] !== null && $capState['worked'] >= $capState['max']) {
        $capState['stop'] = true;
        return true;
    }
    if ($capState['deadline'] !== null && microtime(true) >= $capState['deadline']) {
        $capState['stop'] = true;
        return true;
    }
    return false;
}

/**
 * تحديث watermark الرن: أكبر synced_at لفاتورة **اتعملت فعلاً** (بريف ٢١٨ر)
 */
function stock_import_wm_touch(array &$capState, array $inv): void
{
    $syncAt = (string) ($inv['synced_at'] ?? '');
    if ($syncAt === '') {
        return;
    }
    if ($capState['watermark'] === null || $syncAt > $capState['watermark']) {
        $capState['watermark'] = $syncAt;
    }
}

/**
 * تنفيذ استيراد حركات True لدفتر المخزن
 *
 * @param PDO $pdo
 * @param int $uid المستخدم المنفذ
 * @param int|null $branchId مخزن محدد أو كل المخازن التي لها رصيد افتتاحي مرحل
 * @param int|null $maxInvoices حد الرن الواحد (عدد الفواتير اللي بتتعمل فعلاً).
 *                              null = من غير حد (زرار «استورد دلوقتي»).
 *                              لو اتحط، بيسري معاه كمان حد زمني STOCK_IMPORT_AUTO_MAX_SECONDS.
 * @return array عدادات التقرير
 */
function stock_import_run(PDO $pdo, int $uid, ?int $branchId = null, ?int $maxInvoices = null): array
{
    stock_require_enabled();

    $startTime = microtime(true);
    $queryCount = 0;

    // حالة حد الرن الواحد — الفواتير اللي بتتحسب هي اللي اتعملت فعلاً (مستوردة/محدثة)،
    // المتخطية (skipped) مش بتاكل من الـ٣٠٠ لأنها خلصت في رن قديم. (بريف ٢١٨ر)
    $capOn = ($maxInvoices !== null && $maxInvoices > 0);
    $capState = [
        'max'       => $capOn ? $maxInvoices : null,
        'deadline'  => $capOn ? $startTime + STOCK_IMPORT_AUTO_MAX_SECONDS : null,
        'worked'    => 0,
        'stop'      => false,
        'watermark' => null,   // أكبر synced_at لفاتورة اتعملت فعلاً
    ];

    $counters = [
        'ok'        => true,
        'imported'  => 0,
        'updated'   => 0,
        'skipped'   => 0,
        'failed'    => 0,
        'errors'    => [],
        'duration'  => 0.0,
        'queries'   => 0,
        'truncated' => false,   // وقفنا على الحد والكمّل في الرن الجاي
    ];

    // 1. المخازن التي لديها رصيد افتتاحي مرحل فقط
    $openingsSql = "
        SELECT branch_id, id, posted_at, doc_date
          FROM stock_docs
         WHERE doc_type = 'opening' AND status = 'posted'
    ";
    if ($branchId !== null && $branchId > 0) {
        $openingsSql .= " AND branch_id = " . (int) $branchId;
    }
    $queryCount++;
    $stOpenings = $pdo->query($openingsSql);
    $eligibleBranches = [];
    while ($r = $stOpenings->fetch(PDO::FETCH_ASSOC)) {
        $bId = (int) $r['branch_id'];
        $eligibleBranches[$bId] = [
            'branch_id' => $bId,
            'doc_id'    => (int) $r['id'],
            'posted_at' => $r['posted_at'] ?: ($r['doc_date'] . ' 00:00:00'),
        ];
    }

    if (empty($eligibleBranches)) {
        $counters['duration'] = round(microtime(true) - $startTime, 2);
        $counters['queries']  = $queryCount;
        return $counters;
    }

    // معلومات الفروع
    $bInfoRows = $pdo->query('SELECT id, name, sto_id FROM branches WHERE id IN (' . implode(',', array_keys($eligibleBranches)) . ')')->fetchAll(PDO::FETCH_ASSOC);
    $queryCount++;
    $branchInfoMap = [];
    foreach ($bInfoRows as $bi) {
        $branchInfoMap[(int) $bi['id']] = $bi;
    }

    $agentToBranch = stock_agent_to_branch_map($pdo);
    $supplierErpMap = stock_suppliers_erp_map($pdo);

    // آخر تشغيل ناجح للاستيراد التزايدي
    $stLast = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'stock_import_last'");
    $queryCount++;
    $globalLast = $stLast->fetchColumn();
    $lastImportTime = (!empty($globalLast) && is_string($globalLast)) ? $globalLast : null;

    // معالجة كل مخزن مؤهل
    foreach ($eligibleBranches as $bId => $opInfo) {
        // حد الرن الواحد — وقفنا خلاص؟ الباقي في الرن الجاي (بريف ٢١٨ر)
        if (stock_import_cap_hit($capState)) {
            break;
        }

        $bMode = stock_branch_mode($bId);
        $postedAt = $opInfo['posted_at'];
        $stoId = (int) ($branchInfoMap[$bId]['sto_id'] ?? 0);

        // =================================================================
        // أ) فواتير الإدارة والفروع (hq_invoices) — فقط لو المخزن mirror
        // =================================================================
        if ($bMode === 'mirror' && $stoId > 0) {
            $sqlHq = "
                SELECT sth_id, suffix, doc_no, doc_type, supplier_id,
                       invoice_date, company_branch, synced_at, last_modify
                  FROM hq_invoices
                 WHERE company_branch = ?
                   AND doc_type IN (2, 6)
                   AND invoice_date >= ?
            ";
            $paramsHq = [$stoId, $postedAt];
            if ($lastImportTime !== null && $branchId === null) {
                // الفواتير الجديدة أو المعدلة منذ آخر تشغيل (للتشغيل الآلي العام)
                $sqlHq .= " AND synced_at >= ?";
                $paramsHq[] = $lastImportTime;
            }
            $sqlHq .= " ORDER BY invoice_date ASC, sth_id ASC";

            $stHq = $pdo->prepare($sqlHq);
            $queryCount++;
            $stHq->execute($paramsHq);
            $hqInvoices = $stHq->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($hqInvoices)) {
                stock_import_process_hq_batches(
                    $pdo,
                    $bId,
                    $hqInvoices,
                    $supplierErpMap,
                    $uid,
                    $counters,
                    $queryCount,
                    $capState
                );
            }
        }

        // =================================================================
        // ب) بيع الفروع (br_invoices) — دائماً (mirror و live)
        // =================================================================
        // العثور على agent_key الخاص بالمخزن
        $agentKey = null;
        foreach ($agentToBranch as $ak => $branchTargetId) {
            if ($branchTargetId === $bId) {
                $agentKey = $ak;
                break;
            }
        }

        if ($agentKey !== null) {
            $sqlBr = "
                SELECT agent_key, sth_id, suffix, doc_no, doc_type, supplier_id,
                       invoice_date, company_branch, synced_at, last_modify
                  FROM br_invoices
                 WHERE agent_key = ?
                   AND doc_type IN (4, 7, 17)
                   AND invoice_date >= ?
            ";
            $paramsBr = [$agentKey, $postedAt];
            if ($lastImportTime !== null && $branchId === null) {
                // الفواتير الجديدة أو المعدلة منذ آخر تشغيل (للتشغيل الآلي العام)
                $sqlBr .= " AND synced_at >= ?";
                $paramsBr[] = $lastImportTime;
            }
            $sqlBr .= " ORDER BY invoice_date ASC, sth_id ASC";

            $stBr = $pdo->prepare($sqlBr);
            $queryCount++;
            $stBr->execute($paramsBr);
            $brInvoices = $stBr->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($brInvoices)) {
                stock_import_process_br_batches(
                    $pdo,
                    $bId,
                    $brInvoices,
                    $uid,
                    $counters,
                    $queryCount,
                    $capState
                );
            }
        }
    }

    $duration = round(microtime(true) - $startTime, 2);
    $counters['duration'] = $duration;
    $counters['queries']  = $queryCount;

    // حفظ توقيت آخر استيراد ناجح
    // 🔴 بريف ٢١٨ر: الـwatermark = آخر فاتورة **اتعملت فعلاً** — مش وقت آخر الرن.
    //    (القديم كان بيحطّ now()، فأي فاتورة بتوصل أثناء الرن أو بعد ما وقفنا على
    //     الحد كانت بتضيع للأبد لأن الاختيار بيفلتر بـsynced_at).
    //    ولو وقفنا على الحد مابنقدّمش الـwatermark خالص: الاختيار مرتب بـinvoice_date
    //    مش synced_at، فالفواتير اللي لسه ماتعملتش ممكن يكون synced_at بتاعها أقدم
    //    من آخر واحدة اتعملت — تقديمه كان بيضيّعها. الرن الجاي بيكمّل من نفس الشباك
    //    والـstock_import_log بيمنع تكرار اللي اتعمل.
    $nowStr = date('Y-m-d H:i:s');
    if ($capState['stop']) {
        $counters['truncated'] = true;
        $wmValue = null;   // سايبينه زي ما هو — نكمّل في الرن الجاي
    } else {
        // مافيش فواتير جديدة في الشباك خالص ← نقفل الشباك على دلوقتي
        $wmValue = $capState['watermark'] ?? $nowStr;
    }

    if ($wmValue !== null) {
        $stUpd = $pdo->prepare("
            INSERT INTO settings (setting_key, setting_value)
            VALUES ('stock_import_last', ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $queryCount++;
        $stUpd->execute([$wmValue]);
    }

    // تسجيل دورة التشغيل في stock_import_runs
    $detailsJson = json_encode([
        'branch_id' => $branchId,
        'truncated' => $capState['stop'],
        'errors'    => array_slice($counters['errors'], 0, 20),
    ], JSON_UNESCAPED_UNICODE);

    $stRun = $pdo->prepare("
        INSERT INTO stock_import_runs
          (run_at, duration_sec, imported_count, updated_count, skipped_count, failed_count, details, created_by)
        VALUES
          (NOW(), ?, ?, ?, ?, ?, ?, ?)
    ");
    $queryCount++;
    $stRun->execute([
        $duration,
        $counters['imported'],
        $counters['updated'],
        $counters['skipped'],
        $counters['failed'],
        $detailsJson,
        $uid > 0 ? $uid : null,
    ]);

    return $counters;
}

/**
 * معالجة دفعات فواتير الإدارة والشراء/التحويل (hq_invoices)
 */
function stock_import_process_hq_batches(
    PDO $pdo,
    int $branchId,
    array $invoices,
    array $supplierErpMap,
    int $uid,
    array &$counters,
    int &$queryCount,
    array &$capState
): void {
    $chunks = array_chunk($invoices, 200);

    foreach ($chunks as $chunk) {
        // وقفنا على حد الرن؟ مفيش داعي نسحب دفعة جديدة
        if (stock_import_cap_hit($capState)) {
            return;
        }

        $keys = [];
        $pairTuples = [];
        $srcKeys = [];
        foreach ($chunk as $inv) {
            $sth = (int) $inv['sth_id'];
            $suf = (string) $inv['suffix'];
            $keys["$sth:$suf"] = $inv;
            $pairTuples[] = $sth;
            $pairTuples[] = $suf;
            $srcKeys[] = "hq:$sth:$suf";
        }

        // استعلام السطور باستعلام واحد للدفعة
        $ph = implode(',', array_fill(0, count($chunk), '(?, ?)'));
        $sqlLines = "
            SELECT sth_id, suffix, line_no, item_id, item_code, item_name,
                   quantity, unit_price, cost_price, unit_factor
              FROM hq_invoice_lines
             WHERE (sth_id, suffix) IN ($ph)
             ORDER BY sth_id ASC, suffix ASC, line_no ASC
        ";
        $stLines = $pdo->prepare($sqlLines);
        $queryCount++;
        $stLines->execute($pairTuples);
        $linesGrouped = [];
        $allCodes = [];
        while ($lr = $stLines->fetch(PDO::FETCH_ASSOC)) {
            $k = ((int) $lr['sth_id']) . ':' . ((string) $lr['suffix']);
            $linesGrouped[$k][] = $lr;
            $allCodes[] = (string) $lr['item_code'];
        }

        // جلب معاملات الوحدات للأكواد باستعلام واحد
        $codeFactors = item_code_factors($pdo, $allCodes);
        $queryCount++;

        // جلب السجلات السابقة من stock_import_log باستعلام واحد
        $phLog = implode(',', array_fill(0, count($srcKeys), '?'));
        $stLog = $pdo->prepare("
            SELECT src_key, src_hash, doc_id
              FROM stock_import_log
             WHERE source = 'hq' AND src_key IN ($phLog)
        ");
        $queryCount++;
        $stLog->execute($srcKeys);
        $existingLogs = [];
        while ($lRow = $stLog->fetch(PDO::FETCH_ASSOC)) {
            $existingLogs[(string) $lRow['src_key']] = $lRow;
        }

        // معالجة كل فاتورة في transaction مستقل
        foreach ($chunk as $inv) {
            // الحد بيتفحص قبل الفاتورة — لو خلص وقفنا (بريف ٢١٨ر)
            if (stock_import_cap_hit($capState)) {
                return;
            }

            $sth = (int) $inv['sth_id'];
            $suf = (string) $inv['suffix'];
            $invKey = "$sth:$suf";
            $srcKey = "hq:$sth:$suf";
            $lines = $linesGrouped[$invKey] ?? [];

            stock_import_single_invoice(
                $pdo,
                'hq',
                $srcKey,
                $branchId,
                $inv,
                $lines,
                $codeFactors,
                $supplierErpMap,
                $existingLogs[$srcKey] ?? null,
                $uid,
                $counters,
                $queryCount,
                $capState
            );
        }
    }
}

/**
 * معالجة دفعات مبيعات الفروع (br_invoices)
 */
function stock_import_process_br_batches(
    PDO $pdo,
    int $branchId,
    array $invoices,
    int $uid,
    array &$counters,
    int &$queryCount,
    array &$capState
): void {
    $chunks = array_chunk($invoices, 200);

    foreach ($chunks as $chunk) {
        // وقفنا على حد الرن؟ مفيش داعي نسحب دفعة جديدة
        if (stock_import_cap_hit($capState)) {
            return;
        }

        $triples = [];
        $srcKeys = [];
        foreach ($chunk as $inv) {
            $ak = (string) $inv['agent_key'];
            $sth = (int) $inv['sth_id'];
            $suf = (string) $inv['suffix'];
            $triples[] = $ak;
            $triples[] = $sth;
            $triples[] = $suf;
            $srcKeys[] = "br:$ak:$sth:$suf";
        }

        // استعلام السطور باستعلام واحد للدفعة
        $ph = implode(',', array_fill(0, count($chunk), '(?, ?, ?)'));
        $sqlLines = "
            SELECT agent_key, sth_id, suffix, line_no, item_id, item_code, item_name,
                   quantity, unit_price, cost_price, unit_factor
              FROM br_invoice_lines
             WHERE (agent_key, sth_id, suffix) IN ($ph)
             ORDER BY agent_key ASC, sth_id ASC, suffix ASC, line_no ASC
        ";
        $stLines = $pdo->prepare($sqlLines);
        $queryCount++;
        $stLines->execute($triples);
        $linesGrouped = [];
        $allCodes = [];
        while ($lr = $stLines->fetch(PDO::FETCH_ASSOC)) {
            $k = ((string) $lr['agent_key']) . ':' . ((int) $lr['sth_id']) . ':' . ((string) $lr['suffix']);
            $linesGrouped[$k][] = $lr;
            $allCodes[] = (string) $lr['item_code'];
        }

        // معاملات الأكواد باستعلام واحد
        $codeFactors = item_code_factors($pdo, $allCodes);
        $queryCount++;

        // جلب السجلات السابقة من stock_import_log
        $phLog = implode(',', array_fill(0, count($srcKeys), '?'));
        $stLog = $pdo->prepare("
            SELECT src_key, src_hash, doc_id
              FROM stock_import_log
             WHERE source = 'br' AND src_key IN ($phLog)
        ");
        $queryCount++;
        $stLog->execute($srcKeys);
        $existingLogs = [];
        while ($lRow = $stLog->fetch(PDO::FETCH_ASSOC)) {
            $existingLogs[(string) $lRow['src_key']] = $lRow;
        }

        // معالجة كل فاتورة في transaction مستقل
        foreach ($chunk as $inv) {
            // الحد بيتفحص قبل الفاتورة — لو خلص وقفنا (بريف ٢١٨ر)
            if (stock_import_cap_hit($capState)) {
                return;
            }

            $ak = (string) $inv['agent_key'];
            $sth = (int) $inv['sth_id'];
            $suf = (string) $inv['suffix'];
            $invKey = "$ak:$sth:$suf";
            $srcKey = "br:$ak:$sth:$suf";
            $lines = $linesGrouped[$invKey] ?? [];

            stock_import_single_invoice(
                $pdo,
                'br',
                $srcKey,
                $branchId,
                $inv,
                $lines,
                $codeFactors,
                [],
                $existingLogs[$srcKey] ?? null,
                $uid,
                $counters,
                $queryCount,
                $capState
            );
        }
    }
}

/**
 * معالجة فاتورة مفردة داخل معاملة مستقلة مع منع التكرار والتحديث
 */
function stock_import_single_invoice(
    PDO $pdo,
    string $source,
    string $srcKey,
    int $branchId,
    array $inv,
    array $lines,
    array $codeFactors,
    array $supplierErpMap,
    ?array $existingLog,
    int $uid,
    array &$counters,
    int &$queryCount,
    array &$capState
): void {
    $docType = (int) ($inv['doc_type'] ?? 0);
    $sthId = (int) ($inv['sth_id'] ?? 0);
    $docNo = (string) ($inv['doc_no'] ?? $sthId);
    $invDate = !empty($inv['invoice_date']) ? (string) $inv['invoice_date'] : date('Y-m-d');
    $erpSuppId = isset($inv['supplier_id']) ? (int) $inv['supplier_id'] : 0;

    // 1. حساب البصمة للرأس والسطور
    $newHash = stock_invoice_hash($inv, $lines);

    // 2. فحص التطابق مع ما تم استيراده سابقاً
    if ($existingLog !== null && ($existingLog['src_hash'] ?? '') === $newHash) {
        // فاتورة خلصت قبل كده — بتتحسب في الـwatermark ومش بتاكل من حد الرن
        stock_import_wm_touch($capState, $inv);
        $counters['skipped']++;
        return;
    }

    // 3. التحقق من وجود الأصناف في hq_codes
    foreach ($lines as $l) {
        $code = trim((string) ($l['item_code'] ?? ''));
        if (!isset($codeFactors[$code])) {
            $counters['failed']++;
            $counters['errors'][] = [
                'source'  => $source,
                'src_key' => $srcKey,
                'error'   => "كود الصنف غير موجود في hq_codes: $code",
            ];
            return;
        }
    }

    // 4. تجهيز الرأس والسطور لمستند المخزن
    // تحديد المورد ومورد داخلي/حقيقي
    $supplierId = null;
    $isRealSupplier = false;
    if ($source === 'hq' && $erpSuppId > 0) {
        $supplierId = $supplierErpMap[$erpSuppId] ?? null;
        $isRealSupplier = !in_array($erpSuppId, STOCK_INTERNAL_SUPPLIERS, true);
    }

    $head = [
        'branch_id'   => $branchId,
        'doc_date'    => substr($invDate, 0, 10),
        'supplier_id' => $supplierId,
        'ref_text'    => $srcKey,
        'notes'       => $source === 'hq'
            ? "استيراد من True (فاتورة HQ رقم $docNo)"
            : "استيراد مبيعات فرع (فاتورة BR رقم $docNo)",
    ];

    $docLines = [];
    foreach ($lines as $l) {
        $code = trim((string) ($l['item_code'] ?? ''));
        $rawQty = (float) ($l['quantity'] ?? 0.0);

        // تحديد الإشارة حسب نوع الفاتورة
        // hq: doc_type=2 وارد (+) · doc_type=6 مردود (-)
        // br: doc_type=4 بيع (-) · doc_type=7/17 مرتجع (+)
        if ($source === 'hq') {
            $sign = ($docType === 2) ? 1.0 : -1.0;
        } else {
            $sign = ($docType === 4) ? -1.0 : 1.0;
        }

        $signedUnits = round($sign * $rawQty, 3);
        $lineFactor = isset($l['unit_factor']) && (float) $l['unit_factor'] > 0 ? (float) $l['unit_factor'] : null;
        $codeFactor = isset($codeFactors[$code]['unit_factor']) ? (float) $codeFactors[$code]['unit_factor'] : null;

        // فحص ما إذا كان المعامل مجهولاً
        $hasFactor = ($lineFactor !== null && $lineFactor > 0) || ($codeFactor !== null && $codeFactor > 0);
        $lineNotes = !$hasFactor ? 'unit:unknown' : null;

        // التكلفة: الوارد من مورد حقيقي بـ cost_price السطر ÷ المعامل · الباقي بمتوسط التكلفة (null)
        $unitCost = null;
        if ($isRealSupplier && $docType === 2 && isset($l['cost_price']) && (float) $l['cost_price'] > 0) {
            $effFactor = $lineFactor ?: ($codeFactor ?: 1.0);
            $unitCost = round(((float) $l['cost_price']) / $effFactor, 4);
        }

        $docLines[] = [
            'item_id'     => (int) $l['item_id'],
            'code'        => $code,
            'unit_factor' => $lineFactor,
            'qty_units'   => $signedUnits,
            'unit_cost'   => $unitCost,
            'notes'       => $lineNotes,
        ];
    }

    // 5. التنفيذ داخل Transaction صريح ومستقل للفاتورة
    $pdo->beginTransaction();
    try {
        if ($existingLog !== null) {
            // الفاتورة كانت مستوردة سابقاً وتغيرت في True
            $oldDocId = (int) $existingLog['doc_id'];
            stock_cancel($pdo, $oldDocId, $uid, 'تعديل الفاتورة في True');

            $newDocId = stock_doc_create($pdo, 'import', $head, $docLines, $uid);
            stock_post($pdo, $newDocId, $uid);

            $stUpd = $pdo->prepare("
                UPDATE stock_import_log
                   SET src_hash = ?, doc_id = ?, imported_at = NOW()
                 WHERE source = ? AND src_key = ?
            ");
            $stUpd->execute([$newHash, $newDocId, $source, $srcKey]);

            // تسجيل ملاحظة في doc_events
            $stEv = $pdo->prepare("
                INSERT INTO doc_events (doc_kind, doc_id, event, user_id, at, details)
                VALUES ('stock', ?, 'note', ?, NOW(), ?)
            ");
            $stEv->execute([
                $newDocId,
                $uid,
                json_encode([
                    'note'       => "تحديث استيراد بعد تعديل الفاتورة في True (المستند القديم الملغي #$oldDocId)",
                    'old_doc_id' => $oldDocId,
                    'src_key'    => $srcKey,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            $pdo->commit();
            $capState['worked']++;
            stock_import_wm_touch($capState, $inv);
            $counters['updated']++;
        } else {
            // فاتورة جديدة لأول مرة
            $newDocId = stock_doc_create($pdo, 'import', $head, $docLines, $uid);
            stock_post($pdo, $newDocId, $uid);

            $stIns = $pdo->prepare("
                INSERT INTO stock_import_log
                  (source, src_key, src_hash, doc_id, imported_at)
                VALUES
                  (?, ?, ?, ?, NOW())
            ");
            $stIns->execute([$source, $srcKey, $newHash, $newDocId]);

            $pdo->commit();
            $capState['worked']++;
            stock_import_wm_touch($capState, $inv);
            $counters['imported']++;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $counters['failed']++;
        $counters['errors'][] = [
            'source'  => $source,
            'src_key' => $srcKey,
            'error'   => $e->getMessage(),
        ];
    }
}

/**
 * تشغيل كسول للاستيراد إذا كان قد مضى أكثر من X دقيقة وبحماية GET_LOCK
 */
function stock_import_maybe_run(PDO $pdo, int $uid = 0, int $intervalMinutes = 10): ?array
{
    if (!stock_enabled()) {
        return null;
    }

    try {
        $st = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'stock_import_last'");
        $last = $st->fetchColumn();
        if ($last && is_string($last)) {
            $lastTs = strtotime($last);
            if ($lastTs && (time() - $lastTs) < ($intervalMinutes * 60)) {
                return null;
            }
        }

        // محاولة حجز القفل بدون انتظار (timeout = 0)
        $lockSt = $pdo->query("SELECT GET_LOCK('stock_import', 0)");
        $locked = (int) $lockSt->fetchColumn();
        if ($locked !== 1) {
            return null;
        }

        try {
            // بريف ٢١٨ر: التشغيل التلقائي بحد (٣٠٠ فاتورة / ٢٠ ثانية) عشان مايأخّرش
            // وركر الإشعارات. اللي مايخلصش بيكمّل في الرن الجاي.
            $result = stock_import_run($pdo, $uid, null, STOCK_IMPORT_AUTO_MAX_INVOICES);
            return $result;
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('stock_import')");
        }
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * المطابقة بين رصيد الدفتر ورصيد True لكل صنف في مخزن محدد
 *
 * استعلامين فقط للمخزن (الدفتر + True) والمقارنة في PHP.
 *
 * @param PDO $pdo
 * @param int $branchId معرف الفرع
 * @return array نتائج المطابقة
 */
function stock_recon(PDO $pdo, int $branchId): array
{
    $bInfo = stock_get_branch_info($branchId);
    if (!$bInfo) {
        throw new RuntimeException("المخزن غير موجود: $branchId");
    }

    $branchName = (string) ($bInfo['name'] ?? ('مخزن ' . $branchId));
    $stoId = (int) ($bInfo['sto_id'] ?? 0);
    $bMode = stock_branch_mode($branchId);

    // توقيت آخر استيراد
    $stLast = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'stock_import_last'");
    $ledgerUpdatedAt = $stLast->fetchColumn() ?: 'لم يتم الاستيراد بعد';

    // 1. استعلام رصيد الدفتر stock_balances
    $sqlLedger = "
        SELECT item_id, qty, avg_cost, updated_at
          FROM stock_balances
         WHERE branch_id = ? AND is_transit = 0
    ";
    $stLedger = $pdo->prepare($sqlLedger);
    $stLedger->execute([$branchId]);
    $ledgerMap = [];
    while ($r = $stLedger->fetch(PDO::FETCH_ASSOC)) {
        $ledgerMap[(int) $r['item_id']] = [
            'qty'        => round((float) $r['qty'], 3),
            'avg_cost'   => round((float) $r['avg_cost'], 4),
            'updated_at' => $r['updated_at'],
        ];
    }

    // 2. استعلام رصيد True
    $trueMap = [];
    $trueUpdatedAt = null;
    if ($branchId === 6) { // الإدارة sto_id = 1
        $sqlTrue = "SELECT item_id, balance, updated_at FROM hq_balances WHERE sto_id = 1";
        $stTrue = $pdo->query($sqlTrue);
        while ($r = $stTrue->fetch(PDO::FETCH_ASSOC)) {
            $trueMap[(int) $r['item_id']] = round((float) $r['balance'], 3);
            if ($r['updated_at'] && ($trueUpdatedAt === null || $r['updated_at'] > $trueUpdatedAt)) {
                $trueUpdatedAt = $r['updated_at'];
            }
        }
    } else {
        // فحص جدول الفروع hq_branch_balances مع fallback إلى hq_balances
        $cntSt = $pdo->prepare('SELECT COUNT(*) FROM hq_branch_balances WHERE sto_id = ?');
        $cntSt->execute([$stoId]);
        $hasBranchTable = ((int) $cntSt->fetchColumn()) > 0;
        $srcTable = $hasBranchTable ? 'hq_branch_balances' : 'hq_balances';

        $stTrue = $pdo->prepare("SELECT item_id, balance, updated_at FROM $srcTable WHERE sto_id = ?");
        $stTrue->execute([$stoId]);
        while ($r = $stTrue->fetch(PDO::FETCH_ASSOC)) {
            $trueMap[(int) $r['item_id']] = round((float) $r['balance'], 3);
            if ($r['updated_at'] && ($trueUpdatedAt === null || $r['updated_at'] > $trueUpdatedAt)) {
                $trueUpdatedAt = $r['updated_at'];
            }
        }
    }

    $trueUpdatedAtStr = $trueUpdatedAt ?: date('Y-m-d H:i:s');

    // 3. المقارنة في PHP
    $allItemIds = array_unique(array_merge(array_keys($ledgerMap), array_keys($trueMap)));
    sort($allItemIds);

    $matchedCount = 0;
    $diffCount = 0;
    $totalDiffPieces = 0.0;
    $totalDiffValue = 0.0;
    $diffItems = [];

    foreach ($allItemIds as $iId) {
        $ledgerQty = $ledgerMap[$iId]['qty'] ?? 0.0;
        $trueQty = $trueMap[$iId] ?? 0.0;
        $avgCost = $ledgerMap[$iId]['avg_cost'] ?? 0.0;
        $diff = round($ledgerQty - $trueQty, 3);

        if (abs($diff) < 0.0001) {
            $matchedCount++;
        } else {
            $diffCount++;
            $totalDiffPieces += $diff;
            $diffVal = round($diff * $avgCost, 2);
            $totalDiffValue += $diffVal;

            $diffItems[$iId] = [
                'item_id'          => $iId,
                'ledger_qty'       => $ledgerQty,
                'true_qty'         => $trueQty,
                'diff_qty'         => $diff,
                'avg_cost'         => $avgCost,
                'diff_value'       => $diffVal,
                'code'             => 'ITEM-' . $iId,
                'name'             => 'صنف ' . $iId,
                'has_unknown_unit' => false,
                'last_move_at'     => null,
            ];
        }
    }

    // جلب بيانات الأصناف المختلفة فقط باستعلامات مجمعة
    if (!empty($diffItems)) {
        $diffIds = array_keys($diffItems);
        $phDiff = implode(',', array_fill(0, count($diffIds), '?'));

        // كود واسم الصنف
        $stCodes = $pdo->prepare("
            SELECT item_id, code, name
              FROM hq_codes
             WHERE item_id IN ($phDiff)
             ORDER BY code_type ASC
        ");
        $stCodes->execute($diffIds);
        while ($cr = $stCodes->fetch(PDO::FETCH_ASSOC)) {
            $cid = (int) $cr['item_id'];
            if (isset($diffItems[$cid])) {
                $diffItems[$cid]['code'] = $cr['code'];
                if (!empty($cr['name'])) {
                    $diffItems[$cid]['name'] = $cr['name'];
                }
            }
        }

        // فحص السطور بوحدة مجهولة unit:unknown
        $stUnk = $pdo->prepare("
            SELECT DISTINCT l.item_id
              FROM stock_doc_lines l
              JOIN stock_docs d ON d.id = l.doc_id
             WHERE d.branch_id = ?
               AND l.item_id IN ($phDiff)
               AND l.notes LIKE '%unit:unknown%'
        ");
        $stUnk->execute(array_merge([$branchId], $diffIds));
        while ($uId = $stUnk->fetchColumn()) {
            if (isset($diffItems[(int) $uId])) {
                $diffItems[(int) $uId]['has_unknown_unit'] = true;
            }
        }

        // آخر حركة للصنف في هذا المخزن
        $stMoves = $pdo->prepare("
            SELECT item_id, MAX(move_at) AS last_move_at
              FROM stock_moves
             WHERE branch_id = ?
               AND item_id IN ($phDiff)
             GROUP BY item_id
        ");
        $stMoves->execute(array_merge([$branchId], $diffIds));
        while ($mr = $stMoves->fetch(PDO::FETCH_ASSOC)) {
            $mid = (int) $mr['item_id'];
            if (isset($diffItems[$mid])) {
                $diffItems[$mid]['last_move_at'] = $mr['last_move_at'];
            }
        }
    }

    return [
        'branch_id'         => $branchId,
        'branch_name'       => $branchName,
        'mode'              => $bMode,
        'matched_count'     => $matchedCount,
        'diff_count'        => $diffCount,
        'total_diff_pieces' => round($totalDiffPieces, 3),
        'total_diff_value'  => round($totalDiffValue, 2),
        'true_updated_at'   => $trueUpdatedAtStr,
        'ledger_updated_at' => (string) $ledgerUpdatedAt,
        'differences'       => array_values($diffItems),
    ];
}

/**
 * تقرير حركة الأصناف المخزنية لفترة (type=stock_movement)
 *
 * من الدفتر لفترة: الصنف · المخزن · رصيد أول المدة · وارد شراء · وارد تحويل · منصرف بيع · منصرف تحويل/مردود · تسويات · رصيد آخر المدة.
 * المعادلة الحاكمة: أول المدة + الحركات = آخر المدة لكل سطر.
 */
function stock_movement_report(
    PDO $pdo,
    string $from,
    string $to,
    ?int $selBranch = null,
    ?int $selItem = null
): array {
    $fromDt = $from . ' 00:00:00';
    $toDt   = $to . ' 23:59:59';

    // 1. تحديد الأصناف والمخازن المتأثرة بالحركات أو الأرصدة
    $pairSql = "
        SELECT DISTINCT m.item_id, m.branch_id
          FROM stock_moves m
         WHERE m.move_at <= ?
    ";
    $params = [$toDt];
    if ($selBranch !== null && $selBranch > 0) {
        $pairSql .= " AND m.branch_id = ?";
        $params[] = $selBranch;
    }
    if ($selItem !== null && $selItem > 0) {
        $pairSql .= " AND m.item_id = ?";
        $params[] = $selItem;
    }
    $pairSql .= " ORDER BY m.branch_id ASC, m.item_id ASC";

    $stPairs = $pdo->prepare($pairSql);
    $stPairs->execute($params);
    $pairs = $stPairs->fetchAll(PDO::FETCH_ASSOC);

    if (empty($pairs)) {
        return [
            'title'   => 'تقرير حركة الأصناف المخزنية',
            'headers' => ['كود الصنف', 'اسم الصنف', 'المخزن', 'رصيد أول المدة', 'وارد شراء', 'وارد تحويل', 'منصرف بيع', 'منصرف تحويل/مردود', 'تسويات', 'رصيد آخر المدة'],
            'rows'    => [],
            'totals'  => [
                'opening' => 0.0,
                'pur_in'  => 0.0,
                'tr_in'   => 0.0,
                'sale_out'=> 0.0,
                'tr_out'  => 0.0,
                'adjust'  => 0.0,
                'closing' => 0.0,
            ],
        ];
    }

    // جلب أسماء المخازن
    $bMap = [];
    $stB = $pdo->query('SELECT id, name FROM branches');
    while ($br = $stB->fetch(PDO::FETCH_ASSOC)) {
        $bMap[(int) $br['id']] = $br['name'];
    }

    // جلب أسماء وأكواد الأصناف
    $itemIds = array_values(array_unique(array_map('intval', array_column($pairs, 'item_id'))));
    $phItems = implode(',', array_fill(0, count($itemIds), '?'));
    $stCodes = $pdo->prepare("
        SELECT item_id, code, name
          FROM hq_codes
         WHERE item_id IN ($phItems)
         ORDER BY code_type ASC
    ");
    $stCodes->execute($itemIds);
    $itemInfo = [];
    while ($cr = $stCodes->fetch(PDO::FETCH_ASSOC)) {
        $cid = (int) $cr['item_id'];
        if (!isset($itemInfo[$cid])) {
            $itemInfo[$cid] = [
                'code' => $cr['code'],
                'name' => $cr['name'] ?: ('صنف ' . $cid),
            ];
        }
    }

    // 2. جلب رصيد أول المدة لكل (item_id, branch_id): qty_after لآخر حركة قبل الفترة
    $sqlOpening = "
        SELECT m1.item_id, m1.branch_id, m1.qty_after
          FROM stock_moves m1
          JOIN (
              SELECT item_id, branch_id, MAX(id) AS max_id
                FROM stock_moves
               WHERE move_at < ?
               GROUP BY item_id, branch_id
          ) m2 ON m1.id = m2.max_id
    ";
    $stOp = $pdo->prepare($sqlOpening);
    $stOp->execute([$fromDt]);
    $openings = [];
    while ($r = $stOp->fetch(PDO::FETCH_ASSOC)) {
        $k = ((int) $r['item_id']) . ':' . ((int) $r['branch_id']);
        $openings[$k] = round((float) $r['qty_after'], 3);
    }

    // 3. جلب جميع الحركات خلال الفترة وتصنيفها
    $sqlMoves = "
        SELECT m.item_id, m.branch_id, m.qty, d.doc_type, d.ref_text, d.supplier_id
          FROM stock_moves m
          JOIN stock_docs d ON d.id = m.doc_id
         WHERE m.move_at BETWEEN ? AND ?
    ";
    $paramsMoves = [$fromDt, $toDt];
    if ($selBranch !== null && $selBranch > 0) {
        $sqlMoves .= " AND m.branch_id = ?";
        $paramsMoves[] = $selBranch;
    }
    if ($selItem !== null && $selItem > 0) {
        $sqlMoves .= " AND m.item_id = ?";
        $paramsMoves[] = $selItem;
    }

    $stM = $pdo->prepare($sqlMoves);
    $stM->execute($paramsMoves);

    $moveStats = []; // [k => [pur_in, tr_in, sale_out, tr_out, adjust]]
    while ($mr = $stM->fetch(PDO::FETCH_ASSOC)) {
        $k = ((int) $mr['item_id']) . ':' . ((int) $mr['branch_id']);
        if (!isset($moveStats[$k])) {
            $moveStats[$k] = [
                'pur_in'   => 0.0,
                'tr_in'    => 0.0,
                'sale_out' => 0.0,
                'tr_out'   => 0.0,
                'adjust'   => 0.0,
            ];
        }

        $q = (float) $mr['qty'];
        $dtype = (string) $mr['doc_type'];
        $ref = (string) ($mr['ref_text'] ?? '');
        $suppId = isset($mr['supplier_id']) ? (int) $mr['supplier_id'] : 0;

        if ($dtype === 'purchase') {
            $moveStats[$k]['pur_in'] += $q;
        } elseif ($dtype === 'purchase_return') {
            $moveStats[$k]['tr_out'] += abs($q);
        } elseif ($dtype === 'transfer') {
            if ($q > 0) {
                $moveStats[$k]['tr_in'] += $q;
            } else {
                $moveStats[$k]['tr_out'] += abs($q);
            }
        } elseif ($dtype === 'adjust' || $dtype === 'opening') {
            $moveStats[$k]['adjust'] += $q;
        } elseif ($dtype === 'import') {
            if (str_starts_with($ref, 'hq:')) {
                if ($q > 0) {
                    // فحص المورد: هل هو مورد حقيقي أم تحويل داخلي؟
                    if ($suppId > 0 && in_array($suppId, STOCK_INTERNAL_SUPPLIERS, true)) {
                        $moveStats[$k]['tr_in'] += $q;
                    } else {
                        $moveStats[$k]['pur_in'] += $q;
                    }
                } else {
                    $moveStats[$k]['tr_out'] += abs($q);
                }
            } elseif (str_starts_with($ref, 'br:')) {
                if ($q < 0) {
                    $moveStats[$k]['sale_out'] += abs($q);
                } else {
                    // مردود مبيعات يعتبر عكس المبيعات
                    $moveStats[$k]['sale_out'] -= $q;
                }
            } else {
                if ($q >= 0) {
                    $moveStats[$k]['pur_in'] += $q;
                } else {
                    $moveStats[$k]['sale_out'] += abs($q);
                }
            }
        } else {
            $moveStats[$k]['adjust'] += $q;
        }
    }

    $rows = [];
    $tot = [
        'opening' => 0.0,
        'pur_in'  => 0.0,
        'tr_in'   => 0.0,
        'sale_out'=> 0.0,
        'tr_out'  => 0.0,
        'adjust'  => 0.0,
        'closing' => 0.0,
    ];

    foreach ($pairs as $p) {
        $iId = (int) $p['item_id'];
        $bId = (int) $p['branch_id'];
        $k = "$iId:$bId";

        $opVal = $openings[$k] ?? 0.0;
        $ms = $moveStats[$k] ?? [
            'pur_in'   => 0.0,
            'tr_in'    => 0.0,
            'sale_out' => 0.0,
            'tr_out'   => 0.0,
            'adjust'   => 0.0,
        ];

        $purIn   = round($ms['pur_in'], 3);
        $trIn    = round($ms['tr_in'], 3);
        $saleOut = round($ms['sale_out'], 3);
        $trOut   = round($ms['tr_out'], 3);
        $adj     = round($ms['adjust'], 3);

        // المعادلة: أول المدة + وارد شراء + وارد تحويل - منصرف بيع - منصرف تحويل + تسويات = آخر المدة
        $closing = round($opVal + $purIn + $trIn - $saleOut - $trOut + $adj, 3);

        // تخطي الصفوف التي ليس بها رصيد ولا حركة في الفترة
        if (abs($opVal) < 0.0001 && abs($closing) < 0.0001 && abs($purIn) < 0.0001 && abs($trIn) < 0.0001 && abs($saleOut) < 0.0001 && abs($trOut) < 0.0001 && abs($adj) < 0.0001) {
            continue;
        }

        $codeStr = $itemInfo[$iId]['code'] ?? ('ITEM-' . $iId);
        $nameStr = $itemInfo[$iId]['name'] ?? ('صنف ' . $iId);
        $bName   = $bMap[$bId] ?? ('مخزن ' . $bId);

        $rows[] = [
            'code'        => $codeStr,
            'name'        => $nameStr,
            'branch'      => $bName,
            'opening_qty' => $opVal,
            'purchase_in' => $purIn,
            'transfer_in' => $trIn,
            'sale_out'    => $saleOut,
            'transfer_out'=> $trOut,
            'adjust_qty'  => $adj,
            'closing_qty' => $closing,
        ];

        $tot['opening'] += $opVal;
        $tot['pur_in']  += $purIn;
        $tot['tr_in']   += $trIn;
        $tot['sale_out']+= $saleOut;
        $tot['tr_out']  += $trOut;
        $tot['adjust']  += $adj;
        $tot['closing'] += $closing;
    }

    return [
        'title'   => 'تقرير حركة الأصناف المخزنية',
        'headers' => ['كود الصنف', 'اسم الصنف', 'المخزن', 'رصيد أول المدة', 'وارد شراء', 'وارد تحويل', 'منصرف بيع', 'منصرف تحويل/مردود', 'تسويات', 'رصيد آخر المدة'],
        'rows'    => $rows,
        'totals'  => [
            'opening' => round($tot['opening'], 3),
            'pur_in'  => round($tot['pur_in'], 3),
            'tr_in'   => round($tot['tr_in'], 3),
            'sale_out'=> round($tot['sale_out'], 3),
            'tr_out'  => round($tot['tr_out'], 3),
            'adjust'  => round($tot['adjust'], 3),
            'closing' => round($tot['closing'], 3),
        ],
    ];
}
