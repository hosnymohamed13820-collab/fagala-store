-- =====================================================================
--  upgrade_salary_hours.sql — ربط حساب الرواتب بساعات وأيام الحضور الفعلية
--  نظام الفجالة ستور المالي
-- =====================================================================
--
--  بيضيف أعمدة لفترة الحضور وساعات العمل وأيام الاستحقاق لجدول `salaries`:
--    period_from        = بداية فترة الحضور المعتمدة في الحساب
--    period_to          = نهاية فترة الحضور المعتمدة في الحساب
--    work_hours         = إجمالي ساعات الحضور المسجلة في الفترة
--    work_days          = أيام العمل الفعلية = work_hours ÷ (expected_monthly_hours ÷ 30)
--    leave_days         = أيام الإجازة المستحقة = min(4, floor(work_days ÷ 6))
--    due_days_calc      = إجمالي أيام الاستحقاق المحسوبة بالنظام (عمل + إجازة)
--    due_days           = أيام الاستحقاق الفعلية المعتمدة للصرف (بعد تعديل الأونر إن وجد)
--    due_days_edited_by = معرّف المستخدم (الأونر) الذي عدّل أيام الاستحقاق
--    due_days_edited_at = وقت تعديل أيام الاستحقاق
--
--  🔒 صفر تأثير على أي بيانات ورواتب سابقة:
--     - كل الأعمدة الجديدة NULL أو بقيمة افتراضية (DEFAULT 0 للإجازات)،
--       وبالتالي كل الرواتب السابقة المصروفة بتفضل سليمة ١٠٠٪.
--     - تم الحفاظ على عمود `salary_month` والمفتاح الفريد `uq_salary_emp_month`
--       بدون أي تغيير للحفاظ على هوية مسير الرواتب الشهري.
--
--  التشغيل: phpMyAdmin → SQL → الصق ونفّذ. آمن للتكرار.
--  الملف ده **مايترفعش على الدومين** — بيتشغّل من phpMyAdmin وخلاص.
-- =====================================================================

ALTER TABLE `salaries`
  ADD COLUMN IF NOT EXISTS `period_from` DATE NULL COMMENT 'بداية فترة الحضور',
  ADD COLUMN IF NOT EXISTS `period_to` DATE NULL COMMENT 'نهاية فترة الحضور',
  ADD COLUMN IF NOT EXISTS `work_hours` DECIMAL(7,2) NULL COMMENT 'إجمالي ساعات العمل في الفترة',
  ADD COLUMN IF NOT EXISTS `work_days` INT NULL COMMENT 'أيام العمل الفعلية = work_hours ÷ (expected_monthly_hours ÷ 30)',
  ADD COLUMN IF NOT EXISTS `leave_days` INT NOT NULL DEFAULT 0 COMMENT 'أيام الإجازة المستحقة = min(4, floor(work_days ÷ 6))',
  ADD COLUMN IF NOT EXISTS `due_days_calc` INT NULL COMMENT 'أيام الاستحقاق المحسوبة تلقائياً',
  ADD COLUMN IF NOT EXISTS `due_days` INT NULL COMMENT 'أيام الاستحقاق الفعلية المعتمدة للصرف',
  ADD COLUMN IF NOT EXISTS `due_days_edited_by` INT UNSIGNED NULL COMMENT 'معرّف الأونر الذي عدّل أيام الاستحقاق',
  ADD COLUMN IF NOT EXISTS `due_days_edited_at` DATETIME NULL COMMENT 'تاريخ ووقت تعديل أيام الاستحقاق';
