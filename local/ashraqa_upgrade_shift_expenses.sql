-- =====================================================================
--  ashraqa_upgrade_shift_expenses.sql — مصروفات الوردية من درج الكاشير
--  نظام إشراقة المالي (Ashraqa Site) — 2026-08-27
-- =====================================================================
--
--  الهدف:
--    تمكين الكاشير من تسجيل المصروفات النثرية التي تُصرف مباشرةً من الدرج
--    أثناء الوردية (بنزين، إكراميات، مياه، إلخ).
--
--  آلية الترحيل المالي عند اعتماد الوردية:
--    ١) استلام كامل المبلغ (كاش الدرج المعدود + إجمالي المصروفات) في الخزينة
--       النقدية كإيراد مبيعات (دائن / Credit).
--    ٢) صرف كل مصروف على حدة كقيد صرف (مدين / Debit) من نفس الخزينة النقدية
--       بحساب المصروف المختار من شجرة الحسابات (is_custody = 1).
--    الأثر الصافي على رصيد الخزينة = الكاش الفعلي المُسلّم.
--
--  الأمان والتكرار:
--    - آمن للتكرار: CREATE TABLE IF NOT EXISTS.
--    - الربط مع جدول الورديات shifts بحذف تلقائي ON DELETE CASCADE.
--    - الربط مع جدول الحسابات accounts بـ ON DELETE SET NULL.
--
--  🔴 تنبيه:
--    هذا الملف خاص بقاعدة بيانات فرع إشراقة فقط (Ashraqa DB).
--    يُشغَّل يدوياً من phpMyAdmin على قاعدة إشراقة.
--    الملف في مجلد local/ ولا يُرفع على الدومين أبداً.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `shift_expenses` (
  `id`         bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `shift_id`   bigint(20) UNSIGNED NOT NULL,
  `account_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'تصنيف المصروف من شجرة الحسابات',
  `amount`     decimal(14,2) NOT NULL,
  `note`       varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_shexp_shift` (`shift_id`),
  KEY `fk_shexp_account` (`account_id`),
  CONSTRAINT `fk_shexp_shift` FOREIGN KEY (`shift_id`)
    REFERENCES `shifts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_shexp_account` FOREIGN KEY (`account_id`)
    REFERENCES `accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
