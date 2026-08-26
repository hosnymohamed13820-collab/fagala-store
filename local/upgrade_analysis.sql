-- =====================================================================
--  upgrade_analysis.sql — تحليل الموسم (analysis.php)
--  نظام الفجالة ستور المالي — 2026-08-23
-- =====================================================================
--
--  الفكرة الأساسية: **جدولين بس**، والمصدر عمود مش جدول منفصل.
--
--      رفع ملفات TXT يدوي  ─┐
--                            ├──►  shortage_lines  ──►  التقرير
--      سحب من الـERP تلقائي ─┘
--
--  ده مقصود: التقرير والتصنيف والتوزيع كود واحد مايعرفش الداتا جت
--  منين. إضافة المصدر التلقائي بعدين مش هتحتاج أي تغيير في التقرير.
--
--  ⚠️ الأرقام من المصدرين لازم تبقى على نفس المقياس:
--     TXT فيه «صافى مشتريات الكميه» و «صافى مبيعات الكميه».
--     والـERP بيحسبهم من STO_SYS_TRANS_TYPES.SSTT_TYPE:
--        صافي المشتريات = SUM(TYPE=2) - SUM(TYPE=5)
--        صافي المبيعات  = SUM(TYPE=3) - SUM(TYPE=6)
--
--  التشغيل: phpMyAdmin → SQL → الصق ونفّذ. آمن للتكرار.
--  الملف ده **مايترفعش على الدومين**.
-- =====================================================================


-- ---------------------------------------------------------------------
--  (١) الرفعات — كل عملية استيراد ليها صف
-- ---------------------------------------------------------------------
--  ليه جدول منفصل: الفترة والمصدر بيتخزنوا مرة واحدة مش مع كل صف
--  (١٢ ألف صف × تاريخين = هدر)، وبيدّينا سجل نعرف منه مين رفع إيه
--  وإمتى، ونقدر نمسح رفعة كاملة بصف واحد.
CREATE TABLE IF NOT EXISTS shortage_batches (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    source        ENUM('manual','erp') NOT NULL DEFAULT 'manual',
    period_from   DATE         NOT NULL,
    period_to     DATE         NOT NULL,
    label         VARCHAR(120) NULL      COMMENT 'اسم وصفي — "موسم 2025" مثلاً',
    rows_count    INT UNSIGNED NOT NULL DEFAULT 0,
    stores_count  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    note          TEXT         NULL      COMMENT 'ملاحظات الاستيراد — ملفات، ترميز، صفوف متجاهلة',
    created_by    INT UNSIGNED NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_period (period_from, period_to),
    KEY idx_source (source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='رفعات تقرير النواقص — كل استيراد صف';


-- ---------------------------------------------------------------------
--  (٢) البنود — صف لكل (صنف × مخزن)
-- ---------------------------------------------------------------------
--  التجميع «صف واحد لكل صنف وجنبه كل الفروع» بيتعمل في **الاستعلام**
--  مش في التخزين. التخزين يفضل مسطّح عشان:
--    - إضافة فرع جديد (إشراقة) مش محتاجة تغيير في الجدول
--    - نقدر نجيب فرع واحد لوحده من غير ما نقرا الباقي
--
--  المفتاح (batch_id, store_id, barcode): نفس الصنف في نفس المخزن في
--  نفس الرفعة مايتكررش. لو الملف فيه تكرار، آخر قيمة بتغلب.
--  (اتفحص يوم 2026-08-23: صفر تكرار في الخمس ملفات.)
CREATE TABLE IF NOT EXISTS shortage_lines (
    batch_id      INT UNSIGNED NOT NULL,
    store_id      SMALLINT     NOT NULL COMMENT 'رقم المخزن زي ما هو في الـERP',
    barcode       VARCHAR(64)  NOT NULL COMMENT 'كود الصنف — مفتاح الربط مع hq_codes',

    item_name     VARCHAR(255) NULL     COMMENT 'الاسم من الملف — احتياطي لو الصنف مش عندنا',
    balance       DECIMAL(16,3) NOT NULL DEFAULT 0.000 COMMENT 'الرصيد',
    in_qty        DECIMAL(16,3) NOT NULL DEFAULT 0.000 COMMENT 'الوارد — صافى مشتريات الكميه',
    in_cost       DECIMAL(16,3) NOT NULL DEFAULT 0.000 COMMENT 'قيمة المشتريات بسعر الشراء',
    in_sale       DECIMAL(16,3) NOT NULL DEFAULT 0.000 COMMENT 'قيمة المشتريات بسعر البيع',
    out_qty       DECIMAL(16,3) NOT NULL DEFAULT 0.000 COMMENT 'الصادر — صافى مبيعات الكميه',

    PRIMARY KEY (batch_id, store_id, barcode),
    KEY idx_barcode (barcode),
    KEY idx_batch_store (batch_id, store_id),
    CONSTRAINT fk_sl_batch FOREIGN KEY (batch_id)
        REFERENCES shortage_batches (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='بنود النواقص — صف لكل صنف في كل مخزن';


-- ---------------------------------------------------------------------
--  (٣) إعدادات التحليل
-- ---------------------------------------------------------------------
--  الماكرو كان الدستة فيه ثابتة 12 في الكود. بقت إعداد عشان تتغيّر من
--  غير ما نلمس كود.
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('shortage_dosta',       '12'),
    ('shortage_hq_store',    '1'),
    ('shortage_branch_ids',  '2,3,5,6');


-- ---------------------------------------------------------------------
--  للتأكد
-- ---------------------------------------------------------------------
-- SHOW COLUMNS FROM shortage_batches;
-- SHOW COLUMNS FROM shortage_lines;
-- SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'shortage%';


-- ---------------------------------------------------------------------
--  فهرس التقرير — (batch_id, barcode)
-- ---------------------------------------------------------------------
--  التقرير بيعمل: WHERE batch_id = ? GROUP BY barcode
--  المفتاح الأساسي أوله batch_id بس تانيه store_id، فالتجميع على
--  barcode كان هيعمل ترتيب مؤقت على ٦٠ ألف صف كل مرة.
--  ⚠️ MySQL القديم مابيعرفش ADD INDEX IF NOT EXISTS، فبنسأل الأول.
SET @has_ix = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'shortage_lines'
       AND INDEX_NAME   = 'idx_batch_barcode'
);
SET @sql = IF(@has_ix = 0,
    'ALTER TABLE shortage_lines ADD INDEX idx_batch_barcode (batch_id, barcode)',
    'SELECT ''idx_batch_barcode موجود قبل كده'' AS ملاحظة'
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ملاحظة: ربط أعمدة ملف الـTXT لكل فرع بيتخزن في `settings` تلقائياً
-- بمفتاح `shortage_map_<رقم المخزن>` — مش محتاج جدول جديد.
--
-- ⚠️ shortage_branch_ids بيحدد **ترتيب الأعمدة** في التقرير كمان.
--    الترتيب المتفق عليه: الفجالة(2) ← دجلة(5) ← بونجور(3) ← اي بي سي(6)
--    يعني '2,5,3,6'. لما إشراقة تدخل، زوّد رقمها هنا.
