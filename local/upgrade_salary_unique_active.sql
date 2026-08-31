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
--  الحل: عمود عادي `reversal_seq`:
--      المرتب الشغّال  →  0
--      المرتب المرجّع  →  رقم المرتب نفسه (مضمون إنه مختلف)
--  والفهرس بقى على (employee_id, salary_month, reversal_seq).
--  فالشغّال يفضل **واحد بس**، والمرجّع بيخرج من الحساب لوحده.
--
--  ⚠️ النسخة الأولى من الملف ده كانت بتستخدم عمود محسوب
--     (GENERATED ALWAYS AS) والسيرفر رفضه بـ#1901. أعمدة عادية
--     بس من هنا ورايح — مافيش نسخة MySQL بترفضها.
--
--  مافيش داتا بتتمسح. الصفوف المرجّعة تفضل مكانها.
-- =====================================================================

USE `u834909335_fagala`;

-- (١) العمود
ALTER TABLE `u834909335_fagala`.`salaries`
  ADD COLUMN IF NOT EXISTS `reversal_seq` BIGINT UNSIGNED NOT NULL DEFAULT 0;

-- (٢) الصفوف المرجّعة الموجودة خلاص تاخد رقمها
--     🔴 لازم **قبل** الفهرس — لو فيه أكتر من مرتب مرجّع لنفس
--        الموظف في نفس الشهر، كلهم دلوقتي 0 والفهرس هيرفض.
UPDATE `u834909335_fagala`.`salaries`
   SET `reversal_seq` = `id`
 WHERE `reversed_at` IS NOT NULL
   AND `reversal_seq` = 0;

-- (٣) الفهرس الجديد
ALTER TABLE `u834909335_fagala`.`salaries`
  ADD UNIQUE KEY IF NOT EXISTS `uq_salary_emp_active`
      (`employee_id`, `salary_month`, `reversal_seq`);

-- (٤) وبعدين بس نشيل القديم — الترتيب مقصود: لو شلناه الأول
--     وأمر (٣) وقع، الجدول يفضل من غير أي حماية.
ALTER TABLE `u834909335_fagala`.`salaries`
  DROP INDEX IF EXISTS `uq_salary_emp_month`;

-- =====================================================================
--  الفحص
-- =====================================================================

-- لازم يبان `uq_salary_emp_active` ومايبانش `uq_salary_emp_month`
SHOW INDEX FROM `u834909335_fagala`.`salaries`
 WHERE Key_name LIKE 'uq_salary%';

-- المرجّع لازم `reversal_seq` بتاعه = id، والشغّال = 0
SELECT s.id, e.full_name, s.salary_month, s.reversed_at, s.reversal_seq
  FROM `u834909335_fagala`.`salaries` s
  JOIN `u834909335_fagala`.`employees` e ON e.id = s.employee_id
 ORDER BY s.id DESC;
