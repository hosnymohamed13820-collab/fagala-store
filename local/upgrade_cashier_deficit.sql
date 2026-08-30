-- =====================================================================
--  upgrade_cashier_deficit.sql — دعم خصم عجز الكاشير في الرواتب وترحيل الباقي
--  نظام الفجالة ستور المالي
-- =====================================================================
--
--  الهدف:
--    ١) إضافة عمود `total_cashier_deficits` لجدول `salaries` لتسجيل عجز الكاشير المخصوم
--    ٢) إنشاء جدول `salary_shift_deficits` لربط الورديات التي تم خصم عجزها بالمرتب
--       مع قيد فريد `uq_shift (shift_id)` لضمان استحالة تكرار خصم الوردية مرتين.
--
--  🔒 آمن للتكرار:
--     - بيستخدم IF NOT EXISTS في العمود والجدول.
--     - الصفوف القديمة في salaries بتاخد القيمة الافتراضية 0.00.
--
--  التشغيل: phpMyAdmin → SQL → الصق ونفّذ.
--  الملف ده **مايترفعش على الدومين** — بيتشغّل من phpMyAdmin وخلاص.
--  🔴 متعملش أي استعلام ALTER TABLE أو CREATE TABLE من كود PHP أبداً.
-- =====================================================================

-- (١) عجز الكاشير المخصوم في المرتب
ALTER TABLE `salaries`
  ADD COLUMN IF NOT EXISTS `total_cashier_deficits` DECIMAL(12,2) NOT NULL DEFAULT 0.00
  COMMENT 'عجز الكاشير المخصوم في المرتب ده — الصفوف القديمة صفر يعني ما اتخصمش';

-- (٢) الورديات اللي اتخصمت — الضمان الوحيد إن الوردية ماتتخصمش مرتين
CREATE TABLE IF NOT EXISTS `salary_shift_deficits` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `salary_id`   INT UNSIGNED NOT NULL,
  `shift_id`    INT UNSIGNED NOT NULL,
  `employee_id` INT UNSIGNED NOT NULL,
  `shift_date`  DATE NOT NULL,
  `variance`    DECIMAL(12,2) NOT NULL COMMENT 'فرق الوردية الخام — سالب = عجز',
  `tolerance`   DECIMAL(12,2) NOT NULL COMMENT 'العتبة وقت الصرف',
  `exceeded`    DECIMAL(12,2) NOT NULL COMMENT 'المتجاوز = |الفرق| ناقص العتبة',
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_shift` (`shift_id`),
  KEY `idx_salary` (`salary_id`),
  KEY `idx_emp` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
