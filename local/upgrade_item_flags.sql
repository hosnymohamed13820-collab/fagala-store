-- =====================================================================
--  upgrade_item_flags.sql — علامات الأصناف في الاستلامات
--  نظام الفجالة ستور المالي — 2026-08-13
-- =====================================================================
--
--  بيضيف جدولين:
--
--  (١) invoice_item_flags — على مستوى (سجل التوزيع + كود الصنف)
--      نفس مفتاح invoice_item_prices بالظبط، وعن قصد: عشان الملاحظة
--      واللون يمشوا مع الفاتورة زي التوزيع والسعر تمامًا. تكتب ملاحظة
--      من تاب الفواتير المرحّلة → تلاقيها في المجموعة، والعكس.
--        highlight   = لون تمييز الصف اليدوي (فاضي = بدون)
--        done_actual = تم التوزيع الفعلي (علامة بصرية بس)
--        user_notes  = ملاحظة حرة على الصنف في الفاتورة دي
--
--  (٢) item_export_flags — على مستوى **كود الصنف** في كل النظام
--        skip_export = 1 → الصنف ده مابينزلش في ملف الفرع (TXT) نهائيًا
--                          في أي فاتورة وأي مجموعة وأي فرع.
--
--      ⚠️ ليه بالكود مش بالـ ITEM_ID: الصنف الواحد ليه أكتر من باركود
--         بوحدات مختلفة (قطعة / علبة ١٢) وتكاليف مختلفة، والتوزيع كله
--         في النظام ماشي بالكود. الربط بالـ ITEM_ID كان هيخفي العلبة
--         لما تخفي القطعة. قرار محمد 2026-08-13.
--
--  🔒 صفر تأثير على أي بيانات موجودة:
--     صنف مالوش صف في الجدولين = أبيض · بدون ملاحظة · بينزل عادي.
--     يعني كل الفواتير والمجموعات الحالية شغالة زي ما هي بالحرف.
--
--  مفيش FOREIGN KEY على distribution_id عن قصد — سجلات التوزيع
--  مابتتمسحش أصلاً، والقيد كان هيربطنا بنوع عمود مش متأكدين منه.
--
--  التشغيل: phpMyAdmin → SQL → الصق ونفّذ. آمن للتكرار.
--  لو الجداول موجودة MySQL بتتخطاها من غير خطأ (IF NOT EXISTS).
-- =====================================================================

-- (١) علامات الصنف جوه فاتورة
CREATE TABLE IF NOT EXISTS `invoice_item_flags` (
  `distribution_id` BIGINT UNSIGNED NOT NULL COMMENT 'invoice_distribution.id',
  `item_code`       VARCHAR(60) NOT NULL COMMENT 'كود الصنف (نفس اللي في invoice_distribution_lines.notes)',
  `highlight`       VARCHAR(10) NOT NULL DEFAULT '' COMMENT 'red / green / yellow / orange — فاضي = بدون تمييز',
  `done_actual`     TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'تم التوزيع الفعلي — علامة بصرية، مالهاش أي أثر على الكميات ولا الحالة',
  `user_notes`      TEXT NULL COMMENT 'ملاحظة حرة على الصنف في الفاتورة دي',
  `updated_by`      INT UNSIGNED NULL,
  `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`distribution_id`,`item_code`),
  KEY `idx_iif_code` (`item_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- (٢) منع الصنف من ملف الفرع (TXT) — عالمي بالكود
CREATE TABLE IF NOT EXISTS `item_export_flags` (
  `item_code`   VARCHAR(60) NOT NULL,
  `skip_export` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = مابينزلش في ملف الفرع نهائيًا',
  `updated_by`  INT UNSIGNED NULL,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`item_code`),
  KEY `idx_ief_skip` (`skip_export`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
