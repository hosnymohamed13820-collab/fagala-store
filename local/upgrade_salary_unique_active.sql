-- =====================================================================
--  الفهرس الفريد يشوف المرتبات **الشغّالة** بس
--  الفجالة ستور · ١ سبتمبر ٢٠٢٦
--
--  البق: بعد إرجاع صرف مرتب، إعادة صرفه بترجّع
--      #1062 Duplicate entry '7-2026-08' for key 'uq_salary_emp_month'
--
--  السبب: الإرجاع **بيسيب صف `salaries` موجود** — وده صح، القيد
--  المحاسبي حصل فعلاً ومينفعش يتمسح. لكن الفهرس الفريد
--  `(employee_id, salary_month)` مايعرفش حاجة عن `reversed_at`،
--  فبيرفض الصف الجديد.
--
--  🔴 الحل **مش** إننا نشيل الفهرس. من غيره ممكن موظف ياخد مرتبين
--     في نفس الشهر بالغلط — وده أوحش من البق نفسه.
--
--  الحل: عمود محسوب بيساوي الشهر للمرتب الشغّال و`NULL` للمرجّع.
--  و MySQL بيسمح بتكرار `NULL` في الفهرس الفريد — فالمرجّع بيخرج
--  من الحساب لوحده، والشغّال يفضل واحد بس.
--
--  مافيش داتا بتتمسح. الصفوف المرجّعة تفضل مكانها.
-- =====================================================================

USE `u834909335_fagala`;

-- (١) العمود المحسوب — بيتحدّث لوحده مع أي تغيير في `reversed_at`
ALTER TABLE `u834909335_fagala`.`salaries`
  ADD COLUMN IF NOT EXISTS `active_month` VARCHAR(7)
      GENERATED ALWAYS AS (CASE WHEN `reversed_at` IS NULL
                                THEN `salary_month` ELSE NULL END) STORED;

-- (٢) الفهرس الجديد — موظف واحد + شهر واحد = مرتب شغّال واحد
ALTER TABLE `u834909335_fagala`.`salaries`
  ADD UNIQUE KEY IF NOT EXISTS `uq_salary_emp_active` (`employee_id`, `active_month`);

-- (٣) وبعدين بس نشيل القديم — الترتيب مهم:
--     لو شلناه الأول وأمر (٢) وقع، الجدول يفضل من غير أي حماية.
ALTER TABLE `u834909335_fagala`.`salaries`
  DROP INDEX IF EXISTS `uq_salary_emp_month`;

-- =====================================================================
--  الفحص
-- =====================================================================

-- لازم يبان `uq_salary_emp_active` ومايبانش `uq_salary_emp_month`
SHOW INDEX FROM `u834909335_fagala`.`salaries`
 WHERE Key_name LIKE 'uq_salary%';

-- المرجّع لازم `active_month` بتاعه NULL، والشغّال فيه الشهر
SELECT s.id, e.full_name, s.salary_month, s.reversed_at, s.active_month
  FROM `u834909335_fagala`.`salaries` s
  JOIN `u834909335_fagala`.`employees` e ON e.id = s.employee_id
 ORDER BY s.id DESC;
