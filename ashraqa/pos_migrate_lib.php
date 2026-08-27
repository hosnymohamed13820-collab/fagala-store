<?php
/**
 * ===========================================================================
 *  pos_migrate_lib.php  —  مولّد SQL لترحيل الفواتير لنظام الشركة
 * ===========================================================================
 *
 *  ⚠️ الملف ده لا ينفّذ أي شيء على SQL Server.
 *     كل اللي بيعمله: يقرأ العدادات (SELECT)، ويبني نصوص SQL كـ strings.
 *     التنفيذ الفعلي في ملف تاني منفصل، ولسه مش مكتوب.
 *
 *  الفاتورة الواحدة = 23 أمر:
 *     11 صف في الجداول الحقيقية   (عشان الفرع يشوف الفاتورة)
 *     12 نص في db_repl_send        (عشان الإدارة تستقبلها)
 *
 *  البنية دي مستخرجة من فواتير حقيقية اتضربت من برنامج الشركة
 *  على فرع بونجور، ومطابقة لها بالحرف.
 * ===========================================================================
 */

declare(strict_types=1);


/* ===========================================================================
 *  دوال مساعدة للصياغة
 *  مهم: الصياغة لازم تطابق برنامج الشركة بالضبط، خصوصًا في db_repl_send
 *  لأن النص بيتنفذ حرفيًا على الإدارة.
 * =========================================================================== */

/** تاريخ بصيغة ODBC اللي برنامج الشركة بيستخدمها: {ts '2026-07-12 16:01:35.900'} */
function sql_ts(string $datetime): string {
    $dt = new DateTime($datetime);
    return "{ts '" . $dt->format('Y-m-d H:i:s.v') . "'}";
}

/** تاريخ عادي للـ prepared statements */
function sql_dt(string $datetime): string {
    return (new DateTime($datetime))->format('Y-m-d H:i:s.v');
}

/** نص مع escape للـ single quotes */
function sql_str(?string $v): string {
    if ($v === null) return 'NULL';
    return "'" . str_replace("'", "''", $v) . "'";
}

/** رقم عشري بصيغة ثابتة (البرنامج بيستخدم 5 خانات للكميات/الأسعار) */
function sql_num($v, int $decimals = 5): string {
    if ($v === null) return 'NULL';
    return number_format((float)$v, $decimals, '.', '');
}

/** رقم صحيح */
function sql_int($v): string {
    if ($v === null) return 'NULL';
    return (string)(int)$v;
}


/* ===========================================================================
 *  حجز العدادات
 *
 *  ⚠️ أخطر جزء في المشروع.
 *
 *  نظام الشركة مفيهوش IDENTITY — الترقيم يدوي بالكامل (MAX + 1).
 *  يعني لو اتنين حجزوا في نفس اللحظة، هياخدوا نفس الرقم → تضارب.
 *
 *  الحماية:
 *    1. UPDLOCK + HOLDLOCK  →  يقفل الصفوف لحد نهاية الـ transaction
 *    2. الترحيل بعد قفل الفرع  →  مفيش حد بيضرب فواتير أصلًا
 *
 *  الاتنين مع بعض. مش واحد منهم.
 * =========================================================================== */

/**
 * بيقرأ آخر الأرقام المستخدمة في نظام الشركة.
 *
 * @param callable $query  دالة بتنفّذ SELECT على SQL Server وترجّع صفوف
 * @param string   $ym     الشهر بصيغة '26_07'
 * @return array
 */
function pos_read_counters(callable $query, string $ym, string $px = ''): array
{
    // $px = بادئة القاعدة، مثال: 'Anzmah_FagalaHQ.dbo.'
    // لازمة لأن الـ DSN بتاع ODBC ممكن يكون واقف على قاعدة تانية.
    $hdr = "{$px}STO_TRANS_HEADER_{$ym}";
    $log = "{$px}STO_TRANS_ITEM_LOG_{$ym}";
    $ath = "{$px}ACC_TRANS_HEADER_{$ym}";
    $atg = "{$px}ACC_TRANS_LOG_{$ym}";

    // ★★★ الجداول المعلّقة (YY_MM) ★★★
    //
    // اكتشاف حاسم: نظام الشركة بيحجز الرقم من ساعة "الحفظ بدون ترحيل"،
    // بس بيحط الفاتورة في جدول منفصل اسمه _YY_MM بدل جدول الشهر.
    //
    // مثال حقيقي شفناه:
    //   آخر رقم في STO_TRANS_HEADER_26_07  = 100000017  (مرحّلة)
    //   وفي STO_TRANS_HEADER_YY_MM         = 100000022  (معلّقة!)
    //
    // لو قرينا من جدول الشهر بس، هنحجز 18 — وده هيتضارب مع 22
    // لما الموظف يرحّل فاتورته المعلقة.
    //
    // فلازم ناخد أكبر رقم من الجدولين مع بعض.
    $hdrP = "{$px}STO_TRANS_HEADER_YY_MM";
    $logP = "{$px}STO_TRANS_ITEM_LOG_YY_MM";
    $athP = "{$px}ACC_TRANS_HEADER_YY_MM";
    $atgP = "{$px}ACC_TRANS_LOG_YY_MM";

    // WITH (UPDLOCK, HOLDLOCK) بيمنع أي حد تاني ياخد نفس الرقم
    // لحد ما الـ transaction بتاعتنا تخلص.
    $sql = "
        SELECT
            (SELECT MAX(v) FROM (
                SELECT ISNULL(MAX(sth_id), 0) AS v FROM {$hdr}  WITH (UPDLOCK, HOLDLOCK)
                UNION ALL
                SELECT ISNULL(MAX(sth_id), 0)      FROM {$hdrP} WITH (UPDLOCK, HOLDLOCK)
            ) t) AS max_sth_id,

            (SELECT ISNULL(MAX(sth_id_ref), 0) FROM {$px}sto_trans_index WITH (UPDLOCK, HOLDLOCK)) AS max_sth_id_ref,

            (SELECT MAX(v) FROM (
                SELECT ISNULL(MAX(stil_id), 0) AS v FROM {$log}  WITH (UPDLOCK, HOLDLOCK)
                UNION ALL
                SELECT ISNULL(MAX(stil_id), 0)      FROM {$logP} WITH (UPDLOCK, HOLDLOCK)
            ) t) AS max_stil_id,

            (SELECT MAX(v) FROM (
                SELECT ISNULL(MAX(ath_id), 0) AS v FROM {$ath}  WITH (UPDLOCK, HOLDLOCK)
                UNION ALL
                SELECT ISNULL(MAX(ath_id), 0)      FROM {$athP} WITH (UPDLOCK, HOLDLOCK)
            ) t) AS max_ath_id,

            (SELECT ISNULL(MAX(ath_id_ref), 0) FROM {$px}acc_trans_index WITH (UPDLOCK, HOLDLOCK)) AS max_ath_id_ref,

            (SELECT MAX(v) FROM (
                SELECT ISNULL(MAX(atg_id), 0) AS v FROM {$atg}  WITH (UPDLOCK, HOLDLOCK)
                UNION ALL
                SELECT ISNULL(MAX(atg_id), 0)      FROM {$atgP} WITH (UPDLOCK, HOLDLOCK)
            ) t) AS max_atg_id
    ";

    $rows = $query($sql);
    if (empty($rows)) {
        throw new RuntimeException('فشل قراءة العدادات من نظام الشركة');
    }
    return $rows[0];
}


/**
 * بيعدّ الفواتير المعلّقة (محفوظة بدون ترحيل) في نظام الشركة.
 *
 * ⚠️ لو فيه فواتير معلّقة، الترحيل خطر:
 *    الكاشير ممكن يرحّلها في نفس اللحظة اللي إحنا بنرحّل فيها
 *    → تضارب في الأرقام.
 *
 * القاعدة التشغيلية: الكاشير يخلّص كل المعلّق (يرحّل أو يلغي)
 * قبل ما نضغط ترحيل.
 */
function pos_pending_invoices(callable $query, int $brchId, string $px = ''): int
{
    $sql = "
        SELECT COUNT(*) AS c
        FROM {$px}STO_TRANS_HEADER_YY_MM
        WHERE brch_id = {$brchId} AND sth_id > 0
    ";

    $rows = $query($sql);
    return (int)($rows[0]['c'] ?? 0);
}


/**
 * بيجيب رقم المستند التالي (STH_DOC_NO).
 *
 * ⚠️ ملاحظة مهمة:
 *    DOC_NO عمود نصي (varchar) مش رقمي.
 *    شفنا قيم زي '1' و '2' — يعني بيبدأ من 1 ويتزود.
 *    لسه مش متأكدين هل بيتصفّر كل يوم/وردية/شهر.
 *    الافتراض هنا: MAX داخل الشهر + 1  (لأنه جدول شهري).
 *
 *    ده افتراض. لازم يتأكد قبل التشغيل الفعلي.
 */
/**
 * بيجيب رقم المستند التالي (STH_DOC_NO).
 *
 * ★★★ الترقيم بيتصفّر كل يوم ★★★
 *
 *   مش تسلسلي على الشهر، ولا على الوردية — بيرجع لـ 1 مع كل يوم جديد.
 *
 *   الدليل من بيانات حقيقية (فرع الإدارة، نفس الوردية ps_id=1):
 *       2026-07-03 →  1 .. 9
 *       2026-07-12 →  1 .. 3     ← رجع لـ 1
 *       2026-07-13 →  1          ← رجع لـ 1
 *
 *   الوردية دي مفتوحة من 25 يونيو ومحدش قفلها، فالتصفير مش بسببها.
 *
 * ملاحظة: الرقم ده مش unique أصلًا في نظام الشركة (شفنا 5,5 و 6,6
 *   في نفس اليوم — سببها المرتجعات). فهو رقم عرض مش مفتاح.
 *   المفتاح الحقيقي هو sth_id.
 *
 *   بس بنظبطه بدقة عشان الفاتورة تبقى مطابقة تمامًا لفاتورة النظام،
 *   والمراجع مايلاحظش أي فرق.
 *
 * @param string $day  يوم البيع بصيغة Y-m-d
 */
function pos_next_doc_no(callable $query, string $ym, int $brchId, string $day, string $px = ''): string
{
    $hdr  = "{$px}STO_TRANS_HEADER_{$ym}";
    $hdrP = "{$px}STO_TRANS_HEADER_YY_MM";   // المعلّقة — بتحجز رقم مستند برضه

    // العمود varchar وفيه مسافات زايدة ('3            ')، فلازم LTRIM/RTRIM
    // قبل أي فحص — من غيرها NOT LIKE '%[^0-9]%' بترفض القيمة بسبب المسافة.
    $clean = "LTRIM(RTRIM(sth_doc_no))";
    $expr = "CASE
                WHEN {$clean} <> ''
                     AND LEN({$clean}) <= 9
                     AND {$clean} NOT LIKE '%[^0-9]%'
                THEN CAST({$clean} AS INT)
                ELSE 0
             END";

    // الفلترة على اليوم — ده اللي بيخلي الترقيم يتصفّر يوميًا.
    // CAST(... AS DATE) عشان نتجاهل الوقت ونقارن اليوم بس.
    $d = "'" . str_replace("'", "", $day) . "'";

    $sql = "
        SELECT ISNULL(MAX(v), 0) AS max_doc FROM (
            SELECT ISNULL(MAX({$expr}), 0) AS v
            FROM {$hdr} WITH (UPDLOCK, HOLDLOCK)
            WHERE brch_id = {$brchId}
              AND CAST(sth_doc_date AS DATE) = CAST({$d} AS DATE)
            UNION ALL
            SELECT ISNULL(MAX({$expr}), 0)
            FROM {$hdrP} WITH (UPDLOCK, HOLDLOCK)
            WHERE brch_id = {$brchId}
              AND CAST(sth_doc_date AS DATE) = CAST({$d} AS DATE)
        ) t
    ";

    $rows = $query($sql);
    $max  = (int)($rows[0]['max_doc'] ?? 0);
    // ★ +2 مش +1:
    //   برنامج الشركة بيحسب رقم المستند وقت فتح شاشة نقطة البيع وبيشيله
    //   في الذاكرة (MAX+1). فلو الداتا فيها آخر رقم = 1، نقطة البيع حاجزة 2.
    //   لو إحنا كتبنا 2 كمان → تعارض. فبنسيب منطقة أمان ونبدأ من MAX+2.
    return (string)($max + 2);
}


/**
 * بيجيب الوردية المفتوحة بتاعة الكاشير في نظام الشركة.
 *
 *   PS_STATUS:  2 = مفتوحة   (الحالة اللي شفناها فعليًا)
 *               1 = مقفولة
 *               0 = صف وهمي (ps_id = 0)
 *
 *   ملاحظة: القيم دي اتلخبطت علينا أثناء التطوير، فالشرط هنا معمول
 *   عشان يبقى متسامح:
 *      - بنقبل أي حالة غير 1 (المقفولة)
 *      - والشرط الحاسم: مفيش تاريخ إقفال (ps_end_date)
 *
 *   تاريخ الإقفال ممكن يكون NULL أو تاريخ فاضي (1900-01-01)،
 *   فبنفحص الاتنين.
 *
 * ⚠️ لو مفيش وردية مفتوحة → لازم نوقف.
 *    الفواتير لازم تدخل على وردية الكاشير عشان الفلوس تتجمع عنده.
 */
function pos_open_shift(callable $query, int $brchId, string $px = ''): ?array
{
    $sql = "
        SELECT TOP 1 ps_id, safe_id, ps_code, ps_status
        FROM {$px}POS_SHIFTS
        WHERE brch_id = {$brchId}
          AND ps_id > 0
          AND ps_status <> 1
          AND (ps_end_date IS NULL OR ps_end_date < '1901-01-01')
        ORDER BY ps_id DESC
    ";

    $rows = $query($sql);
    return $rows[0] ?? null;
}


/**
 * بيقرأ متوسط التكلفة للأصناف (لقيد تكلفة المبيعات).
 *
 * التكلفة بتتقري وقت البيع وبتتخزن في pos_invoice_lines،
 * مش وقت الترحيل — عشان القيد يطابق لحظة البيع الفعلية.
 */
function pos_read_costs(callable $query, array $itemIds, int $stoId, int $comId, string $px = ''): array
{
    if (empty($itemIds)) return [];

    $ids = implode(',', array_map('intval', $itemIds));
    $sql = "
        SELECT item_id, csi_avr_cost
        FROM {$px}COM_STO_ITEMS
        WHERE item_id IN ({$ids}) AND sto_id = {$stoId} AND com_id = {$comId}
    ";

    $out = [];
    foreach ($query($sql) as $r) {
        $out[(int)$r['item_id']] = (float)$r['csi_avr_cost'];
    }
    return $out;
}


/**
 * بيتحقق إن جدول الشهر موجود.
 *
 * ⚠️ الجداول شهرية (STO_TRANS_HEADER_26_07).
 *    أول يوم في الشهر، الجدول الجديد ممكن يكون لسه مااتعملش.
 *    لو حاولنا نكتب فيه → انفجار.
 */
function pos_month_tables_exist(callable $query, string $ym, string $db = ''): bool
{
    $need = [
        "STO_TRANS_HEADER_{$ym}",
        "STO_TRANS_LINES_{$ym}",
        "STO_TRANS_ITEM_LOG_{$ym}",
        "STO_TRANS_POS_{$ym}",
        "ACC_TRANS_HEADER_{$ym}",
        "ACC_TRANS_LINES_{$ym}",
        "ACC_TRANS_LOG_{$ym}",
    ];

    $list = "'" . implode("','", $need) . "'";
    // لازم نحدد القاعدة صراحةً، وإلا هيدوّر في قاعدة الـ DSN الافتراضية
    $pfx  = $db !== '' ? "{$db}." : '';
    $sql  = "SELECT COUNT(*) AS c FROM {$pfx}sys.tables WHERE name IN ({$list})";

    $rows = $query($sql);
    return (int)($rows[0]['c'] ?? 0) === count($need);
}


/* ===========================================================================
 *  مولّد الفاتورة  ★ قلب المشروع
 *
 *  بيبني الـ23 أمر لفاتورة واحدة، وبيرجّعهم كـ array.
 *  ⚠️ مش بينفّذ أي حاجة — بيبني نصوص بس.
 *
 *  الترتيب مهم جدًا ومطابق لبرنامج الشركة:
 *    (أ) الجداول الحقيقية  → عشان الفرع يشوف الفاتورة
 *    (ب) db_repl_send      → عشان الإدارة تستقبلها
 *
 *  لو (أ) نجح و(ب) فشل → الفاتورة في الفرع بس، والإدارة عمياء.
 *  عشان كده الاتنين لازم يكونوا في TRANSACTION واحدة.
 * =========================================================================== */

/**
 * @param array $inv    الفاتورة من pos_invoices
 * @param array $lines  الأصناف من pos_invoice_lines
 * @param array $cfg    خريطة الفرع من pos_branch_map
 * @param array $ctr    العدادات المحجوزة من pos_read_counters
 * @param array $shift  الوردية المفتوحة من pos_open_shift
 *
 * @return array{sql: string[], repl: string[], ids: array}
 */
function pos_build_invoice(array $inv, array $lines, array $cfg, array $ctr, array $shift): array
{
    // ---------- الأرقام المحجوزة ----------
    $sthId    = (int)$ctr['max_sth_id']     + 1;
    $sthIdRef = (int)$ctr['max_sth_id_ref'] + 1;
    $athId    = (int)$ctr['max_ath_id']     + 1;
    $athIdRef = (int)$ctr['max_ath_id_ref'] + 1;

    // ★ رقم مستند القيد = آخر 6 أرقام من ath_id_ref
    //
    //   من بيانات حقيقية:
    //     الإدارة:  ath_id_ref = 100000022  →  ath_doc_no = '22'
    //     بونجور:   ath_id_ref = 300001245  →  ath_doc_no = '1245'
    //
    //   يعني بيشيلوا البادئة (100000000 / 300000000) ويسيبوا الباقي.
    //   modulo 1000000 بيعمل ده صح للاتنين.
    //
    //   ⚠️ لو حطينا الرقم كامل (زي ما كان في أول ترحيل)، القيد بيبان
    //     برقم غريب في نظام الشركة.
    $athDocNo = (string)($athIdRef % 1000000);
    $stilId   = (int)$ctr['max_stil_id'];   // بيتزود مع كل صنف
    $atgId    = (int)$ctr['max_atg_id'];    // بيتزود مع كل سطر قيد
    $docNo    = (string)$ctr['next_doc_no'];

    // ---------- بيانات الفرع ----------
    $brch   = (int)$cfg['branch_id'];
    $stoId  = (int)$cfg['sto_id'];
    $comId  = (int)$cfg['com_id'];
    $hwId   = (int)$cfg['create_hw_id'];
    $userId = (int)$cfg['create_user_id'];
    $sstt   = (int)$cfg['sstt_id_sale'];   // 4 = بيع
    $astt   = (int)$cfg['astt_id_sale'];   // 10 = قيد بيع

    // ---------- الوردية والخزنة ----------
    $psId   = (int)$shift['ps_id'];
    $safeId = (int)$shift['safe_id'];

    // ---------- التواريخ ----------
    // مهم: soldAt = وقت البيع الفعلي (مش وقت الترحيل)
    // ده اللي بيخلي الفاتورة تظهر في الوردية الصح.
    $soldAt  = sql_dt($inv['sold_at']);
    $soldTs  = sql_ts($inv['sold_at']);
    $nowTs   = sql_ts(date('Y-m-d H:i:s'));
    $nowDt   = sql_dt(date('Y-m-d H:i:s'));

    // ---------- القيم ----------
    $net       = (float)$inv['net_value'];
    $total     = (float)$inv['total_value'];
    $headDisc  = (float)$inv['head_disc'];
    $linesDisc = (float)$inv['lines_disc'];
    $noItems   = count($lines);

    // ---------- العلامة المميزة ★ ----------
    // ده اللي هيخلي المراجع يعرف إن الفاتورة دي جاية من الويب سايت.
    // بيتكتب في ملاحظات 1 (STH_HEAD_REMARK1).
    $remark = 'ويب سايت | ' . date('Y-m-d H:i', strtotime($inv['sold_at']))
            . ' | #' . $inv['id'];

    // ---------- الجداول الشهرية ----------
    $ym  = date('y_m', strtotime($inv['sold_at']));   // 26_07
    $fnm = date('ym',  strtotime($inv['sold_at']));   // 2607

    // بادئة القاعدة (مثال: Anzmah_FagalaHQ.dbo.)
    $px = rtrim((string)($cfg['sqlsrv_db'] ?? ''), '.');
    $px = $px !== '' ? $px . '.dbo.' : '';

    $HDR = "{$px}STO_TRANS_HEADER_{$ym}";
    $LIN = "{$px}STO_TRANS_LINES_{$ym}";
    $ILG = "{$px}STO_TRANS_ITEM_LOG_{$ym}";
    $POS = "{$px}STO_TRANS_POS_{$ym}";
    $AHD = "{$px}ACC_TRANS_HEADER_{$ym}";
    $ALN = "{$px}ACC_TRANS_LINES_{$ym}";
    $ALG = "{$px}ACC_TRANS_LOG_{$ym}";
    $SIX = "{$px}sto_trans_index";
    $AIX = "{$px}acc_trans_index";
    $DRS = "{$px}db_repl_send";

    $sql  = [];   // أوامر الجداول الحقيقية
    $repl = [];   // نصوص db_repl_send


    /* ─────────────────────────────────────────────────────────────────────
     *  [1] الفهرس  —  sto_trans_index
     *      لازم الأول: هو اللي بيحجز sth_id_ref (المفتاح العالمي).
     *
     *  ★★★ الفهرس ده هو اللي البرنامج بيقرا منه الفاتورة ★★★
     *
     *      لو ناقصه أعمدة (ps_id, cur_id, net_value, exchange_rate)،
     *      البرنامج بيلاقي الفاتورة بس مايعرفش يربطها بالوردية،
     *      والنتيجة: الفاتورة تظهر من غير أصناف، والمرتجع مايلاقيهاش.
     *
     *      دي كانت المشكلة في أول ترحيل فعلي — الأعمدة دي كانت NULL.
     * ───────────────────────────────────────────────────────────────────── */
    $sql[] = "INSERT INTO {$SIX} "
           . "(sth_id_ref, sth_id, sstt_id, sutt_id, suth_id, com_id, sto_id, brch_id, sth_file_name, sth_doc_no, sth_doc_date, sth_status, sth_net_value, sth_paid_value, sth_ret_value, cust_id, supp_id, ch_id, tp_id, pch_id, ps_id, cur_id, sth_exchange_rate, sth_collect_flag, del_user_id, repl_user_id, create_date, create_user_id, create_hw_id, last_modify_date, last_modify_user_id, last_modify_hw_id, sth_repl_send_end) VALUES ("
           . "{$sthIdRef}, {$sthId}, {$sstt}, 0, 0, {$comId}, {$stoId}, {$brch}, "
           . sql_str($fnm) . ", " . sql_str($docNo) . ", {$soldTs}, 1, "
           . sql_num($net, 5) . ", " . sql_num($net, 5) . ", 0.00000, "
           . "0, 0, 0, 0, 0, "
           . "{$psId}, 1, 1.00000, 0, "
           . "0, 0, "
           . "{$nowTs}, {$userId}, {$hwId}, "
           . "{$nowTs}, {$userId}, {$hwId}, "
           . "0)";


    /* ─────────────────────────────────────────────────────────────────────
     *  [2] رأس الفاتورة  —  STO_TRANS_HEADER
     * ───────────────────────────────────────────────────────────────────── */
    $sql[] = "INSERT INTO {$HDR} "
           . "(sth_id, sth_id_ref, sstt_id, sutt_id, com_id, sto_id, brch_id, "
           . "sth_tot_value, sth_net_value, sth_head_disc_value, sth_tot_line_disc_value, "
           . "sth_no_items, sth_status, cur_id, sth_exchange_rate, "
           . "sth_doc_no, sth_doc_date, sth_posting_date, "
           . "sth_head_remark1, ps_id, "
           . "create_date, create_user_id, create_hw_id) VALUES ("
           . "{$sthId}, {$sthIdRef}, {$sstt}, 0, {$comId}, {$stoId}, {$brch}, "
           . sql_num($total, 5) . ", " . sql_num($net, 5) . ", "
           . sql_num($headDisc, 5) . ", " . sql_num($linesDisc, 5) . ", "
           . "{$noItems}, 1, 1, 1.00000, "
           . sql_str($docNo) . ", {$soldTs}, {$soldTs}, "
           . sql_str($remark) . ", {$psId}, "
           . "{$nowTs}, {$userId}, {$hwId})";

    $repl[] = "INSERT INTO STO_TRANS_HEADER_REPL "
            . "(sth_id, sth_id_ref, sstt_id, sutt_id, com_id, sto_id, brch_id, "
            . "sth_tot_value, sth_net_value, sth_head_disc_value, sth_tot_line_disc_value, "
            . "sth_no_items, sth_status, cur_id, sth_exchange_rate, "
            . "sth_doc_no, sth_doc_date, sth_posting_date, "
            . "sth_head_remark1, ps_id, "
            . "create_date, create_user_id, create_hw_id) VALUES ("
            . "{$sthId}, {$sthIdRef}, {$sstt}, 0, {$comId}, {$stoId}, {$brch}, "
            . sql_num($total, 5) . ", " . sql_num($net, 5) . ", "
            . sql_num($headDisc, 5) . ", " . sql_num($linesDisc, 5) . ", "
            . "{$noItems}, 1, 1, 1.00000, "
            . sql_str($docNo) . ", {$soldTs}, {$soldTs}, "
            . sql_str($remark) . ", {$psId}, "
            . "{$nowTs}, {$userId}, {$hwId})";


    /* ─────────────────────────────────────────────────────────────────────
     *  [3] الأصناف + حركة المخزون
     *
     *  ★ STL_QTY_FLAG = -1  →  بيع  (الكمية موجبة، الفلاج سالب)
     *  ★ STIL_QTY     سالب   →  خروج من المخزن
     * ───────────────────────────────────────────────────────────────────── */
    $totalCost = 0.0;
    $lineNo    = 0;

    foreach ($lines as $ln) {
        $lineNo++;
        $stilId++;

        $itemId = (int)$ln['item_id'];
        $icId   = (int)($ln['ic_id'] ?? 0);
        $qty    = (float)$ln['qty'];
        $price  = (float)$ln['price'];
        $disc   = (float)$ln['disc_value'];
        $lnTot  = (float)$ln['total'];
        $cost   = (float)$ln['cost'];
        $unit   = (int)$ln['unit_id'];
        $factor = (float)$ln['unit_factor'];

        $totalCost += $cost * $qty;

        // --- سطر الصنف ---
        $sql[] = "INSERT INTO {$LIN} "
               . "(sth_id, sth_id_ref, stl_line_no, sstt_id, sutt_id, com_id, sto_id, brch_id, cur_id, item_id, ic_id, unit_id, stl_unit_factor, rc_id, sts_id_ref, sts_source_id_ref, stl_qty, stl_qty_flag, stl_item_price, stl_item_cost, stl_item_disc_value, stl_item_disc_perc, stl_head_item_disc, stl_item_tax_value, stl_item_tax_perc, stl_head_item_tax, stl_item_service_value, stl_item_net_value, stl_item_value, stl_ret_qty, stl_to_sto, stl_item_type, balance_flag, sc_id, isr_id, sm_id, sto_sales_price, stl_source_line_no, stl_balance_before, stl_staking_balance, stl_item_disc_points, stl_item_exp_value, stl_head_item_exp, stl_counter_reading, stl_child_item_id, stl_child_qty, stl_child_per, stl_child_cost, stl_parent_flag, stl_parent_line_no, stl_child_isr_id, stl_child_sc_id, stl_qty2, stl_sm_value, stl_loss_qty, stl_loss_value, stl_item_other_value, stl_item_other_perc, stl_item_tax_disc_value, stl_item_tax_disc_perc, stl_item_gurnt_value, stl_item_gurnt_perc, sth_exchange_rate, stl_status, sth_status, sth_doc_date, stl_posting_date, create_date, create_user_id, create_hw_id, pj_id, cct_id, woh_id, sutr_id) VALUES ("
               . "{$sthId}, {$sthIdRef}, {$lineNo}, {$sstt}, 0, {$comId}, {$stoId}, {$brch}, 1, "
               . "{$itemId}, {$icId}, {$unit}, " . sql_num($factor, 5) . ", 1, 0, 0, "
               . sql_num($qty, 5) . ", -1, " . sql_num($price, 5) . ", " . sql_num($cost, 5) . ", "
               . sql_num($disc, 5) . ", 0.00000, 0.00000, "
               . "0.00000, 0.00000, 0.00000, "
               . "0.00000, " . sql_num($lnTot, 5) . ", " . sql_num($lnTot, 5) . ", "
               . "0, 0, 0, 0, "
               . "0, 0, 0, 0.00000, 0, "
               . "0.00000, 0.00000, 0.00000, "
               . "0.00000, 0.00000, 0.00000, "
               . "0, 0.00000, 0.00000, 0.00000, "
               . "0, 0, 0, 0, "
               . "0.00000, 0.00000, 0.00000, 0.00000, "
               . "0.00000, 0.00000, "
               . "0.00000, 0.00000, "
               . "0.00000, 0.00000, "
               . "1.00000, 1, 1, "
               . "{$soldTs}, {$soldTs}, {$nowTs}, {$userId}, {$hwId}, 0, 0, 0, 0)";

        $repl[] = "INSERT INTO STO_TRANS_LINES_REPL "
                . "(sth_id, sth_id_ref, stl_line_no, sstt_id, sutt_id, com_id, sto_id, brch_id, cur_id, item_id, ic_id, unit_id, stl_unit_factor, rc_id, sts_id_ref, sts_source_id_ref, stl_qty, stl_qty_flag, stl_item_price, stl_item_cost, stl_item_disc_value, stl_item_disc_perc, stl_head_item_disc, stl_item_tax_value, stl_item_tax_perc, stl_head_item_tax, stl_item_service_value, stl_item_net_value, stl_item_value, stl_ret_qty, stl_to_sto, stl_item_type, balance_flag, sc_id, isr_id, sm_id, sto_sales_price, stl_source_line_no, stl_balance_before, stl_staking_balance, stl_item_disc_points, stl_item_exp_value, stl_head_item_exp, stl_counter_reading, stl_child_item_id, stl_child_qty, stl_child_per, stl_child_cost, stl_parent_flag, stl_parent_line_no, stl_child_isr_id, stl_child_sc_id, stl_qty2, stl_sm_value, stl_loss_qty, stl_loss_value, stl_item_other_value, stl_item_other_perc, stl_item_tax_disc_value, stl_item_tax_disc_perc, stl_item_gurnt_value, stl_item_gurnt_perc, sth_exchange_rate, stl_status, sth_status, sth_doc_date, stl_posting_date, create_date, create_user_id, create_hw_id, pj_id, cct_id, woh_id, sutr_id) VALUES ("
                . "{$sthId}, {$sthIdRef}, {$lineNo}, {$sstt}, 0, {$comId}, {$stoId}, {$brch}, 1, "
                . "{$itemId}, {$icId}, {$unit}, " . sql_num($factor, 5) . ", 1, 0, 0, "
                . sql_num($qty, 5) . ", -1, " . sql_num($price, 5) . ", " . sql_num($cost, 5) . ", "
                . sql_num($disc, 5) . ", 0.00000, 0.00000, "
                . "0.00000, 0.00000, 0.00000, "
                . "0.00000, " . sql_num($lnTot, 5) . ", " . sql_num($lnTot, 5) . ", "
                . "0, 0, 0, 0, "
                . "0, 0, 0, 0.00000, 0, "
                . "0.00000, 0.00000, 0.00000, "
                . "0.00000, 0.00000, 0.00000, "
                . "0, 0.00000, 0.00000, 0.00000, "
                . "0, 0, 0, 0, "
                . "0.00000, 0.00000, 0.00000, 0.00000, "
                . "0.00000, 0.00000, "
                . "0.00000, 0.00000, "
                . "0.00000, 0.00000, "
                . "1.00000, 1, 1, "
                . "{$soldTs}, {$soldTs}, {$nowTs}, {$userId}, {$hwId}, 0, 0, 0, 0)";

        // --- حركة المخزون (الكمية سالبة = خروج) ---
        // ★ sutt_id, sc_id, stil_qty2 → صفر (برنامج الشركة بيحطها صفر، اتأكدنا من فاتورة حقيقية)
        //   stil_qty_per, stil_value_per → بنسيبهم NULL (النظام نفسه بيسيبهم NULL)
        //   الفرق مهم: لو حطينا صفر مكان NULL أو العكس، ممكن البرنامج يتجاهل الصف.
        $sql[] = "INSERT INTO {$ILG} "
               . "(stil_id, sth_id, sth_id_ref, stl_line_no, sstt_id, sutt_id, com_id, sto_id, brch_id, "
               . "item_id, stil_qty, stil_value, stil_cost, sc_id, stil_qty2, stil_trans_date, "
               . "create_date, create_user_id, create_hw_id) VALUES ("
               . "{$stilId}, {$sthId}, {$sthIdRef}, {$lineNo}, {$sstt}, 0, {$comId}, {$stoId}, {$brch}, "
               . "{$itemId}, " . sql_num(-$qty, 5) . ", " . sql_num(-$lnTot, 5) . ", " . sql_num(-$cost, 5) . ", 0, 0.00000, {$soldTs}, "
               . "{$nowTs}, {$userId}, {$hwId})";

        $repl[] = "INSERT INTO STO_TRANS_ITEM_LOG_REPL "
                . "(stil_id, sth_id, sth_id_ref, stl_line_no, sstt_id, sutt_id, com_id, sto_id, brch_id, "
                . "item_id, stil_qty, stil_value, stil_cost, sc_id, stil_qty2, stil_trans_date, "
                . "create_date, create_user_id, create_hw_id) VALUES ("
                . "{$stilId}, {$sthId}, {$sthIdRef}, {$lineNo}, {$sstt}, 0, {$comId}, {$stoId}, {$brch}, "
                . "{$itemId}, " . sql_num(-$qty, 5) . ", " . sql_num(-$lnTot, 5) . ", " . sql_num(-$cost, 5) . ", 0, 0.00000, {$soldTs}, "
                . "{$nowTs}, {$userId}, {$hwId})";
    }


    /* ─────────────────────────────────────────────────────────────────────
     *  [4] الدفع  —  STO_TRANS_POS
     *      stp_type = 0  →  نقدي (كاش)
     *      اتفقنا: كل الفواتير تترحّل كاش، والتسوية بتحصل في النظام بتاعنا.
     * ───────────────────────────────────────────────────────────────────── */
    // ★ stp_typeid, stp_collect_flag, stp_install_value, stp_exp_value → صفر (النظام بيحطها صفر)
    //   stp_r_doc_id → بنسيبه NULL (النظام نفسه بيسيبه NULL)
    $sql[] = "INSERT INTO {$POS} "
           . "(sth_id, sth_id_ref, stp_line_no, sstt_id, safe_id, ps_id, stp_type, stp_typeid, stp_collect_flag, "
           . "cur_id, stp_exchange_rate, sth_exchange_rate, stp_value, stp_install_value, stp_exp_value, "
           . "stp_status, sth_status, stp_posting_date, "
           . "create_date, create_user_id, create_hw_id, brch_id) VALUES ("
           . "{$sthId}, {$sthIdRef}, 1, {$sstt}, {$safeId}, {$psId}, 0, 0, 0, "
           . "1, 1.00000, 1.00000, " . sql_num($net, 5) . ", 0.00000, 0.00000, "
           . "1, 1, {$soldTs}, "
           . "{$nowTs}, {$userId}, {$hwId}, {$brch})";

    $repl[] = "INSERT INTO STO_TRANS_POS_REPL "
            . "(sth_id, sth_id_ref, stp_line_no, sstt_id, safe_id, ps_id, stp_type, stp_typeid, stp_collect_flag, "
            . "cur_id, stp_exchange_rate, sth_exchange_rate, stp_value, stp_install_value, stp_exp_value, "
            . "stp_status, sth_status, stp_posting_date, "
            . "create_date, create_user_id, create_hw_id, brch_id) VALUES ("
            . "{$sthId}, {$sthIdRef}, 1, {$sstt}, {$safeId}, {$psId}, 0, 0, 0, "
            . "1, 1.00000, 1.00000, " . sql_num($net, 5) . ", 0.00000, 0.00000, "
            . "1, 1, {$soldTs}, "
            . "{$nowTs}, {$userId}, {$hwId}, {$brch})";


    /* ─────────────────────────────────────────────────────────────────────
     *  [5] القيد المحاسبي
     *
     *  سطرين دايمًا:
     *      مدين  28  = الصافي   (الخزنة)
     *      دائن 441  = الصافي   (المبيعات)
     *
     *  + سطرين لو التكلفة > 0:
     *      مدين 554  = التكلفة  (تكلفة المبيعات)
     *      دائن  39  = التكلفة  (المخزون)
     *
     *  ★ القيد التاني ده هو اللي اكتشفناه متأخر.
     *    لو نسيناه: الأرباح هتطلع أعلى من الحقيقة، والمخزون المحاسبي
     *    مش هيتخصم. عطل صامت بيتراكم لشهور.
     * ───────────────────────────────────────────────────────────────────── */

    $acCash = (int)$cfg['ac_cash'];       // 28
    $acSale = (int)$cfg['ac_sales'];      // 441
    $acCogs = (int)$cfg['ac_cogs'];       // 554
    $acInv  = (int)$cfg['ac_inventory'];  // 39

    // --- رأس القيد ---
    $sql[] = "INSERT INTO {$AHD} "
           . "(ath_id, ath_id_ref, astt_id, autt_id, com_id, brch_id, "
           . "ath_tot_value, ath_exchange_rate, cur_id, ath_status, "
           . "ath_doc_date, ath_doc_no, ath_r_doc_id, ath_r_mod_id, ath_r_sto_id, "
           . "ath_r_type1, ath_r_type2, "
           . "create_date, create_user_id, create_hw_id) VALUES ("
           . "{$athId}, {$athIdRef}, {$astt}, 0, {$comId}, {$brch}, "
           . sql_num($net, 5) . ", 1.00000, 1, 1, "
           . "{$soldTs}, " . sql_str($athDocNo) . ", {$sthId}, 1, {$stoId}, "
           . "{$sstt}, 0, "
           . "{$nowTs}, {$userId}, {$hwId})";

    $repl[] = "INSERT INTO ACC_TRANS_HEADER_REPL "
            . "(ath_id, ath_id_ref, astt_id, autt_id, com_id, brch_id, "
            . "ath_tot_value, ath_exchange_rate, cur_id, ath_status, "
            . "ath_doc_date, ath_doc_no, ath_r_doc_id, ath_r_mod_id, ath_r_sto_id, "
            . "ath_r_type1, ath_r_type2, "
            . "create_date, create_user_id, create_hw_id) VALUES ("
            . "{$athId}, {$athIdRef}, {$astt}, 0, {$comId}, {$brch}, "
            . sql_num($net, 5) . ", 1.00000, 1, 1, "
            . "{$soldTs}, " . sql_str($athDocNo) . ", {$sthId}, 1, {$stoId}, "
            . "{$sstt}, 0, "
            . "{$nowTs}, {$userId}, {$hwId})";

    // --- سطور القيد ---
    // [حساب, مدين, دائن, نوع]  —  atg_ac_type من الفواتير الحقيقية
    $entries = [
        [$acCash, $net, 0.0, 30],   // مدين  الخزنة
        [$acSale, 0.0, $net, 31],   // دائن  المبيعات
    ];

    if ($totalCost > 0) {
        $entries[] = [$acCogs, $totalCost, 0.0, 32];   // مدين  تكلفة المبيعات
        $entries[] = [$acInv,  0.0, $totalCost, 33];   // دائن  المخزون
    }

    $atlLine = 0;
    foreach ($entries as [$acId, $db, $cr, $acType]) {
        $atlLine++;
        $atgId++;

        // ★ atl_db_cr_flag: 1 = مدين، 0 = دائن (اتأكدنا من فاتورة حقيقية)
        //   NULLABLE بس النظام بيملّاه فعليًا حسب نوع السطر.
        $dbCrFlag = ($db > 0) ? 1 : 0;
        $sql[] = "INSERT INTO {$ALN} "
               . "(ath_id, ath_id_ref, atl_line_no, com_id, ac_id, astt_id, autt_id, brch_id, "
               . "atl_db_value, atl_cr_value, atl_db_cr_flag, cct_id, ath_doc_date, atl_ac_type, atl_ac_type_id, cur_id, ath_exchange_rate, atl_status, ath_status, "
               . "create_date, create_user_id, create_hw_id) VALUES ("
               . "{$athId}, {$athIdRef}, {$atlLine}, {$comId}, {$acId}, {$astt}, 0, {$brch}, "
               . sql_num($db, 5) . ", " . sql_num($cr, 5) . ", {$dbCrFlag}, 0, {$soldTs}, {$acType}, {$stoId}, 1, 1.00000, 1, 1, "
               . "{$nowTs}, {$userId}, {$hwId})";

        $repl[] = "INSERT INTO ACC_TRANS_LINES_REPL "
                . "(ath_id, ath_id_ref, atl_line_no, com_id, ac_id, astt_id, autt_id, brch_id, "
                . "atl_db_value, atl_cr_value, atl_db_cr_flag, cct_id, ath_doc_date, atl_ac_type, atl_ac_type_id, cur_id, ath_exchange_rate, atl_status, ath_status, "
                . "create_date, create_user_id, create_hw_id) VALUES ("
                . "{$athId}, {$athIdRef}, {$atlLine}, {$comId}, {$acId}, {$astt}, 0, {$brch}, "
                . sql_num($db, 5) . ", " . sql_num($cr, 5) . ", {$dbCrFlag}, 0, {$soldTs}, {$acType}, {$stoId}, 1, 1.00000, 1, 1, "
                . "{$nowTs}, {$userId}, {$hwId})";

        // سجل القيد (ACC_TRANS_LOG)
        $sql[] = "INSERT INTO {$ALG} "
               . "(atg_id, ath_id, ath_id_ref, atl_line_no, com_id, ac_id, "
               . "atg_trans_date, astt_id, autt_id, brch_id, "
               . "atg_ac_type, atg_ac_type_id, cct_id, atg_db_value, atg_cr_value, "
               . "create_date, create_user_id, create_hw_id) VALUES ("
               . "{$atgId}, {$athId}, {$athIdRef}, {$atlLine}, {$comId}, {$acId}, "
               . "{$soldTs}, {$astt}, 0, {$brch}, "
               . "{$acType}, {$stoId}, 0, " . sql_num($db, 5) . ", " . sql_num($cr, 5) . ", "
               . "{$nowTs}, {$userId}, {$hwId})";

        $repl[] = "INSERT INTO ACC_TRANS_LOG_REPL "
                . "(atg_id, ath_id, ath_id_ref, atl_line_no, com_id, ac_id, "
                . "atg_trans_date, astt_id, autt_id, brch_id, "
                . "atg_ac_type, atg_ac_type_id, cct_id, atg_db_value, atg_cr_value, "
                . "create_date, create_user_id, create_hw_id) VALUES ("
                . "{$atgId}, {$athId}, {$athIdRef}, {$atlLine}, {$comId}, {$acId}, "
                . "{$soldTs}, {$astt}, 0, {$brch}, "
                . "{$acType}, {$stoId}, 0, " . sql_num($db, 5) . ", " . sql_num($cr, 5) . ", "
                . "{$nowTs}, {$userId}, {$hwId})";
    }

    // --- فهرس القيد ---
    $sql[] = "INSERT INTO {$AIX} "
           . "(ath_id_ref, ath_id, astt_id, autt_id, com_id, brch_id, "
           . "ath_tot_value, ath_exchange_rate, cur_id, ath_status, "
           . "ath_doc_date, ath_doc_no, ath_r_doc_id, ath_r_mod_id, ath_r_sto_id, "
           . "ath_r_type1, ath_r_type2, ath_file_name, "
           . "create_date, create_user_id, create_hw_id) VALUES ("
           . "{$athIdRef}, {$athId}, {$astt}, 0, {$comId}, {$brch}, "
           . sql_num($net, 5) . ", 1.00000, 1, 1, "
           . "{$soldTs}, " . sql_str($athDocNo) . ", {$sthId}, 1, {$stoId}, "
           . "{$sstt}, 0, " . sql_str($fnm) . ", "
           . "{$nowTs}, {$userId}, {$hwId})";

    $repl[] = "INSERT INTO ACC_TRANS_INDEX_REPL "
            . "(ath_id_ref, ath_id, astt_id, autt_id, com_id, brch_id, "
            . "ath_tot_value, ath_exchange_rate, cur_id, ath_status, "
            . "ath_doc_date, ath_doc_no, ath_r_doc_id, ath_r_mod_id, ath_r_sto_id, "
            . "ath_r_type1, ath_r_type2, ath_file_name, "
            . "create_date, create_user_id, create_hw_id) VALUES ("
            . "{$athIdRef}, {$athId}, {$astt}, 0, {$comId}, {$brch}, "
            . sql_num($net, 5) . ", 1.00000, 1, 1, "
            . "{$soldTs}, " . sql_str($athDocNo) . ", {$sthId}, 1, {$stoId}, "
            . "{$sstt}, 0, " . sql_str($fnm) . ", "
            . "{$nowTs}, {$userId}, {$hwId})";


    /* ─────────────────────────────────────────────────────────────────────
     *  [6] ختم الإرسال  —  آخر أمر دايمًا
     *      برنامج الشركة بيعمل ده في الآخر بالظبط.
     * ───────────────────────────────────────────────────────────────────── */
    $repl[] = "UPDATE STO_TRANS_INDEX_REPL SET sth_repl_send_end = 1 "
            . "WHERE sth_id_ref = {$sthIdRef}";


    return [
        'sql'  => $sql,
        'repl' => $repl,
        'ids'  => [
            'sth_id'     => $sthId,
            'sth_id_ref' => $sthIdRef,
            'ath_id'     => $athId,
            'ath_id_ref' => $athIdRef,
            'stil_id'    => $stilId,
            'atg_id'     => $atgId,
            'doc_no'     => $docNo,
            'ps_id'      => $psId,
            'safe_id'    => $safeId,
            'remark'     => $remark,
            'total_cost' => $totalCost,
        ],
    ];
}


/**
 * بيحوّل نصوص repl لأوامر INSERT في db_repl_send.
 *
 * ⚠️ النص بيتنفّذ حرفيًا على الإدارة، فالـ escaping لازم يكون مظبوط.
 *
 * ★ drs_id إجباري (NOT NULL) ومفيش IDENTITY في القاعدة،
 *   فلازم نحسبه بنفسنا ونزوّده مع كل أمر.
 *
 * @param int $startDrsId  آخر drs_id مستخدم (هنبدأ من اللي بعده)
 */
function pos_wrap_repl(array $replTexts, array $cfg, array $ids, int $startDrsId = 0): array
{
    $px = rtrim((string)($cfg['sqlsrv_db'] ?? ''), '.');
    $px = $px !== '' ? $px . '.dbo.' : '';

    $brch   = (int)$cfg['branch_id'];
    $hwId   = (int)$cfg['create_hw_id'];
    $userId = (int)$cfg['create_user_id'];
    $nowTs  = sql_ts(date('Y-m-d H:i:s'));

    $drsId = $startDrsId;

    $out = [];
    foreach ($replTexts as $text) {
        $drsId++;
        $out[] = "INSERT INTO {$px}db_repl_send "
               . "(drs_id, drs_text, drs_export_flag, drs_import_flag, drs_execute_flag, "
               . "drs_error, drs_status, create_date, create_user_id, brch_id, create_hw_id) VALUES ("
               . "{$drsId}, " . sql_str($text) . ", 0, 0, 0, 0, 0, {$nowTs}, {$userId}, {$brch}, {$hwId})";
    }
    return $out;
}


/**
 * بيجيب آخر drs_id في طابور المزامنة.
 */
function pos_max_drs_id(callable $query, string $px = ''): int
{
    $sql = "SELECT ISNULL(MAX(drs_id), 0) AS m FROM {$px}db_repl_send WITH (UPDLOCK, HOLDLOCK)";
    $rows = $query($sql);
    return (int)($rows[0]['m'] ?? 0);
}


/* ===========================================================================
 *  إرسال أوامر الكتابة لجسر الكتابة
 *
 *  ⚠️ الدالة دي هي اللي بتكتب فعليًا في قاعدة الشركة.
 *
 *  كل الأوامر بتتبعت في طلب HTTP واحد، والجسر بينفّذهم في
 *  transaction واحدة. ده مهم جدًا: لو بعتناهم في طلبات منفصلة،
 *  كل طلب هيبقى اتصال جديد، والـ transaction مش هتشتغل عبرهم.
 *
 *  النتيجة: يا كله ينجح، يا كله يترجع (ROLLBACK). مفيش فاتورة نُص.
 * =========================================================================== */
function pos_bridge_write(array $statements): array
{
    if (!defined('BRIDGE_WRITE_URL') || BRIDGE_WRITE_URL === '') {
        throw new RuntimeException('BRIDGE_WRITE_URL مش معرّف في config.php');
    }
    if (!defined('BRIDGE_WRITE_KEY') || BRIDGE_WRITE_KEY === '') {
        throw new RuntimeException('BRIDGE_WRITE_KEY مش معرّف في config.php');
    }
    if (empty($statements)) {
        throw new RuntimeException('مفيش أوامر للتنفيذ');
    }

    $ch = curl_init(BRIDGE_WRITE_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,   // الترحيل ممكن ياخد وقت
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER     => ['X-Bridge-Key: ' . BRIDGE_WRITE_KEY],
        CURLOPT_POSTFIELDS     => [
            'key'        => BRIDGE_WRITE_KEY,
            'statements' => json_encode(array_values($statements), JSON_UNESCAPED_UNICODE),
        ],
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('فشل الاتصال بجسر الكتابة: ' . $err);
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        throw new RuntimeException("رد غير مفهوم من الجسر (HTTP {$code}): "
            . mb_substr((string)$body, 0, 300));
    }

    if (empty($json['ok'])) {
        $msg = $json['error'] ?? 'خطأ غير معروف';
        // لو الجسر عمل rollback، الفاتورة مش اتكتبت أصلًا — ده كويس.
        if (!empty($json['rolled_back'])) {
            $msg .= ' [تم التراجع — لم تُكتب أي بيانات]';
        }
        throw new RuntimeException($msg);
    }

    return $json;
}
