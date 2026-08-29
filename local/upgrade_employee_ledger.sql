-- =====================================================================
--  upgrade_employee_ledger.sql — سجل حركات حساب الموظف (سجل موازي)
--  نظام الفجالة ستور المالي
-- =====================================================================
--
--  ليه اتعمل:
--    جدول employee_advances بيمسك الرصيد الحالي فقط (remaining)، وكل خصم
--    بيكسب فوق القديم فمفيش تاريخ تفصيلي لأي حركة.
--    الجدول ده بينشئ سجل موازي بوارد ومنصرف (debit / credit) لكل موظف:
--      * debit  = عليه (زوّد مديونيته)
--      * credit = له (قلّل مديونيته أو سداد)
--      * الرصيد = مجموع debit − مجموع credit (موجب عليه · سالب ليه)
--
--  🔒 صفر أثر في المرحلة الحالية (المرحلة ١ من ٤):
--     - كتابة وتسجيل فقط — مفيش أي قراءة منه في أي شاشة أو حسابات حالية.
--     - آمن للتكرار: CREATE TABLE IF NOT EXISTS.
--
--  التشغيل: phpMyAdmin → SQL → الصق ونفّذ.
--  الملف ده **مايترفعش على الدومين** — بيتشغّل من phpMyAdmin وخلاص.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `employee_ledger` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` INT UNSIGNED NOT NULL,
  `entry_date`  DATE NOT NULL,
  `direction`   ENUM('debit','credit') NOT NULL
                COMMENT 'debit = عليه (زوّد مديونيته) · credit = له (قلّلها)',
  `amount`      DECIMAL(12,2) NOT NULL,
  `move_type`   VARCHAR(30) NOT NULL
                COMMENT 'advance · salary_deduction · cash_repay · cancel · opening',
  `description` VARCHAR(255) NULL,
  `treasury_id` INT UNSIGNED NULL COMMENT 'الخزينة لو فيه كاش اتحرك — NULL لو مفيش',
  `ref_type`    VARCHAR(30) NULL,
  `ref_id`      BIGINT UNSIGNED NULL,
  `created_by`  INT UNSIGNED NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_emp_ledger` (`employee_id`, `entry_date`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
