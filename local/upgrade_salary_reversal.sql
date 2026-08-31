-- =====================================================================
--  إرجاع صرف المرتب — الأعمدة اللي بتخلّي الإرجاع ممكن أصلاً
--  الفجالة ستور · ١ سبتمبر ٢٠٢٦
--
--  المشكلة: `pay_salary()` بتقفل المستحقات كده:
--      UPDATE employee_bonuses   SET status='paid'      WHERE employee_id=? AND status='pending'
--      UPDATE employee_penalties SET status='deducted'  WHERE employee_id=? AND status='pending'
--  مفيش أي ربط بالمرتب اللي قفلها. فلما نيجي نرجّع الصرف
--  **مش هنعرف** أنهي مكافأة أو جزاء كان بتاع المرتب ده وأنهي واحدة
--  كانت من مرتب قبله.
--
--  🔴 والملف ده بيصلّح المستقبل بس. المرتب اللي **اتصرف خلاص** قبل
--     نزوله مالوش ربط، والإرجاع بتاعه هيرجّع اللي يقدر يثبته
--     ويوري باقي البنود عشان محمد يظبطها بإيده.
--
--  مافيش عمود بيتشال ولا داتا بتتمسح. كله إضافة.
-- =====================================================================

-- 🔴 اسم القاعدة مكتوب على كل جدول تحت، و`USE` دي حزام زيادة.
--    السبب: phpMyAdmin بيعيد تشغيل آخر SELECT بنفسه (وبيضيف LIMIT)
--    على القاعدة المختارة **في الواجهة** مش اللي في الملف — فكان
--    بيرجّع #1109 Unknown table 'salaries' in information_schema
--    مهما حطينا USE. الاسم الكامل بيلغي الاعتماد على السياق خالص.
USE `u834909335_fagala`;

-- (١) المرتب المرجّع — عمود بدل ما نلمس الـENUM
--     🔴 سبب استخدام عمود جديد مش قيمة جديدة في `status`: توسيع ENUM
--        بيقفل الجدول ولازم نعرف قيمه الحالية بالظبط. العمود الفاضي
--        أأمن، ومعناه واضح: `reversed_at IS NULL` = مرتب شغّال.
ALTER TABLE `u834909335_fagala`.`salaries`
  ADD COLUMN IF NOT EXISTS `reversed_at`    DATETIME     NULL AFTER `paid_at`,
  ADD COLUMN IF NOT EXISTS `reversed_by`    INT          NULL AFTER `reversed_at`,
  ADD COLUMN IF NOT EXISTS `reverse_reason` VARCHAR(255) NULL AFTER `reversed_by`;

-- (٢) ربط المكافأة والجزاء بالمرتب اللي قفلهم
ALTER TABLE `u834909335_fagala`.`employee_bonuses`
  ADD COLUMN IF NOT EXISTS `paid_in_salary_id` BIGINT UNSIGNED NULL,
  ADD KEY IF NOT EXISTS `ix_bonus_salary` (`paid_in_salary_id`);

ALTER TABLE `u834909335_fagala`.`employee_penalties`
  ADD COLUMN IF NOT EXISTS `deducted_in_salary_id` BIGINT UNSIGNED NULL,
  ADD KEY IF NOT EXISTS `ix_pen_salary` (`deducted_in_salary_id`);

-- (٣) السلف: المرتب بيخصم من أكتر من سلفة، وكل واحدة بمبلغ مختلف.
--     `salaries.total_advances` فيه الإجمالي بس — مش كفاية للإرجاع.
CREATE TABLE IF NOT EXISTS `u834909335_fagala`.`salary_advance_recoveries` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `salary_id`  BIGINT UNSIGNED NOT NULL,
  `advance_id` BIGINT UNSIGNED NOT NULL,
  `amount`     DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_sar_salary` (`salary_id`),
  KEY `ix_sar_advance` (`advance_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  الفحص
-- =====================================================================
SELECT 'salaries'          AS `الجدول`, COUNT(*) AS `أعمدة الإرجاع` FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = 'u834909335_fagala' AND TABLE_NAME = 'salaries'
   AND COLUMN_NAME IN ('reversed_at','reversed_by','reverse_reason')
UNION ALL
SELECT 'employee_bonuses', COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = 'u834909335_fagala' AND TABLE_NAME = 'employee_bonuses'
   AND COLUMN_NAME = 'paid_in_salary_id'
UNION ALL
SELECT 'employee_penalties', COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = 'u834909335_fagala' AND TABLE_NAME = 'employee_penalties'
   AND COLUMN_NAME = 'deducted_in_salary_id';
-- المتوقع: 3 · 1 · 1

-- المرتبات اللي اتصرفت **قبل** الترقية — دي اللي إرجاعها هيبقى ناقص
SELECT s.id, e.full_name, s.salary_month, s.net_salary, s.paid_at
  FROM `u834909335_fagala`.`salaries` s JOIN `u834909335_fagala`.`employees` e ON e.id = s.employee_id
 WHERE s.reversed_at IS NULL
 ORDER BY s.id DESC;
