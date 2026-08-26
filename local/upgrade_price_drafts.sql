-- =====================================================================
--  upgrade_price_drafts.sql — تقرير «أصناف بدون سعر بيع»
--  نظام الفجالة ستور المالي — 2026-08-11
-- =====================================================================
--
--  ليه جدول جديد ومش invoice_item_prices؟
--    invoice_item_prices مربوط بـ distribution_id (فاتورة استلام).
--    التقرير ده مالوش فاتورة، واستخدام distribution_id = 0 هو نفس
--    الباج اللي كسر حفظ أسعار المجموعات قبل كده — فممنوع.
--
--  الجدول ده = «جدول التقرير» نفسه:
--    كل سطر فيه = صنف مضاف للتقرير + السعر المقترح ليه.
--    الإضافة  = INSERT · التعديل = UPDATE · الشيل = DELETE.
--    التصدير  = كل الصفوف اللي في الجدول.
--
--  ⚠️ زي الاستلامات بالظبط: السعر ده **عندنا بس**. مابيروحش لقاعدة
--     الشركة إلا لما تسحب ملف الـTXT وترفعه في نظام الشركة.
--
--  التشغيل: phpMyAdmin على هوستنجر → SQL → الصق ونفّذ.
--  آمن للتكرار (IF NOT EXISTS).
-- =====================================================================

CREATE TABLE IF NOT EXISTS item_price_drafts (
    item_code   VARCHAR(60)   NOT NULL,               -- الكود زي ما هو في hq_codes.code
    item_id     INT           NOT NULL DEFAULT 0,     -- للربط والتشخيص
    item_name   VARCHAR(255)  NULL,                   -- لقطة وقت الإضافة (لو الكود اختفى من المرآة)
    cost_price  DECIMAL(14,5) NOT NULL DEFAULT 0,     -- لقطة التكلفة وقت الإضافة
    sale_price  DECIMAL(14,5) NOT NULL DEFAULT 0,     -- السعر المقترح — ده اللي بيتصدّر
    markup_pct  DECIMAL(9,3)  NULL,                   -- النسبة المكتوبة على الصنف (للعرض)
    added_by    INT           NULL,
    updated_by  INT           NULL,
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (item_code),
    KEY idx_pdft_updated (updated_at),
    KEY idx_pdft_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- فهرس على سعر البيع في المرآة — نافذة «أصناف سعرها صفر» بتفلتر بيه
-- على ١٧ ألف كود، فمن غيره بتعمل full scan كل مرة.
-- لو الفهرس موجود، MySQL هيرمي خطأ 1061 — تجاهله وكمّل.
ALTER TABLE hq_codes ADD INDEX idx_hq_codes_price (price);
