-- =====================================================================
--  تصفير البيانات — الخطوة (١): المسح
--  الفجالة ستور · ١ سبتمبر ٢٠٢٦
--
--  🔴🔴 الملف ده **بيمسح فعلاً ومفيش رجوع**.
--       متشغّلوش قبل التلاتة دول:
--         ١) باك أب كامل من hPanel نزل على جهازك واتأكدت إنه فتح.
--         ٢) `reset_00_precheck.sql` اتشغّل والقسم (١) كله أصفار.
--         ٣) اتجرّب على القاعدة المحلية (`fagala`) وعدّى.
--
--  النطاق — قرار محمد بالحرف: «الخزن والداشبورد والعهدة وتقفيل
--  الكاشير ومراجعة المحاسب بس فقط لا غير، والسلف زي ما هي وكل
--  حاجة زي ما هي حرفياً».
--
--  ✅ **بيتمسح:**
--     journal_entries · treasury_movements · treasury_transfers ·
--     reconciliations · shifts (+shift_cash · shift_payment_lines ·
--     shift_reviews) · branch_custody (+custody_cash · custody_lines ·
--     custody_deficits) · disbursement_orders · salary_shift_deficits
--     و`treasuries.balance` بيتصفّر.
--
--  ❌ **مابيتلمسش:**
--     employee_advances (السلف زي ما هي) · employee_ledger ·
--     employee_bonuses · employee_penalties · salaries · employees ·
--     users · suppliers وفواتيرهم · الأصناف والفواتير والمخزون ·
--     pos_* · hq_* · كل الباقي.
--
--  ⚠️ اللي محمد متبلّغ بيه ووافق:
--     · `salaries` بتفضل، بس **قيود صرفها في اليومية بتتمسح** —
--       يعني كشف الخزينة مش هيوري «صرف مرتب» تاني، السجل في تاب
--       الرواتب بس.
--     · تصفير `treasuries.balance` معناه إن أي كاش موجود فعلاً في
--       خزينة **بيختفي من النظام**.
-- =====================================================================

USE `fagala`;   -- 🔴 غيّرها لـ `u834909335_fagala` على السيرفر الحقيقي

-- ---------------------------------------------------------------------
-- (أ) الحارس — لازم يرجّع **صفر**. لو رجّع أي رقم تاني: **وقّف**.
--     دي ديون على موظفين لسه معلّقة، ومسحها بيخلّي الفلوس تختفي.
-- ---------------------------------------------------------------------
SELECT COUNT(*) AS `🔴 عجز عهدة معلّق — لازم صفر`
  FROM `custody_deficits` WHERE `status` = 'pending';

-- ---------------------------------------------------------------------
-- (ب) المسح — الترتيب من الابن للأب عشان مايبقاش صف يتيم
--     (الترتيب مأخوذ من مفاتيح القاعدة الحقيقية مش من التخمين)
-- ---------------------------------------------------------------------
START TRANSACTION;

-- ورديات الكاشير وتوابعها
DELETE FROM `salary_shift_deficits`;
DELETE FROM `shift_reviews`;          -- مراجعة المحاسب
DELETE FROM `shift_payment_lines`;
DELETE FROM `shift_cash`;
DELETE FROM `shifts`;                 -- تقفيل الكاشير

-- العهدة — العجز الأول لأن مفتاحه على branch_custody من غير CASCADE
DELETE FROM `custody_deficits`;
DELETE FROM `custody_lines`;
DELETE FROM `custody_cash`;
DELETE FROM `branch_custody`;
DELETE FROM `disbursement_orders`;    -- أذون صرف العهدة

-- الخزينة والداشبورد
DELETE FROM `reconciliations`;
DELETE FROM `treasury_transfers`;
DELETE FROM `treasury_movements`;
DELETE FROM `journal_entries`;        -- 🔴 دفتر اليومية كله

-- 🔴 الرصيد **رقم متخزّن** مش محسوب من اليومية — مسح اليومية لوحده
--    مايصفّروش، وكان هيسيب الاتنين مختلفين وده أسوأ من الأصل.
UPDATE `treasuries` SET `balance` = 0.00;

COMMIT;

-- ---------------------------------------------------------------------
-- (ج) الفحص بعد المسح — كله لازم يبقى صفر
-- ---------------------------------------------------------------------
SELECT 'journal_entries'       AS `الجدول`, COUNT(*) AS `لازم صفر` FROM `journal_entries`
UNION ALL SELECT 'treasury_movements',    COUNT(*) FROM `treasury_movements`
UNION ALL SELECT 'treasury_transfers',    COUNT(*) FROM `treasury_transfers`
UNION ALL SELECT 'reconciliations',       COUNT(*) FROM `reconciliations`
UNION ALL SELECT 'shifts',                COUNT(*) FROM `shifts`
UNION ALL SELECT 'shift_cash',            COUNT(*) FROM `shift_cash`
UNION ALL SELECT 'shift_payment_lines',   COUNT(*) FROM `shift_payment_lines`
UNION ALL SELECT 'shift_reviews',         COUNT(*) FROM `shift_reviews`
UNION ALL SELECT 'branch_custody',        COUNT(*) FROM `branch_custody`
UNION ALL SELECT 'custody_cash',          COUNT(*) FROM `custody_cash`
UNION ALL SELECT 'custody_lines',         COUNT(*) FROM `custody_lines`
UNION ALL SELECT 'custody_deficits',      COUNT(*) FROM `custody_deficits`
UNION ALL SELECT 'disbursement_orders',   COUNT(*) FROM `disbursement_orders`
UNION ALL SELECT 'salary_shift_deficits', COUNT(*) FROM `salary_shift_deficits`
UNION ALL SELECT '🔴 خزائن رصيدها مش صفر', COUNT(*) FROM `treasuries` WHERE `balance` <> 0;

-- ---------------------------------------------------------------------
-- (د) واللي **لازم يفضل زي ما هو** — الأرقام دي متتغيّرش
-- ---------------------------------------------------------------------
SELECT 'السلف المعلّقة' AS `اللي بيفضل`, COUNT(*) AS `العدد`,
       COALESCE(SUM(COALESCE(`remaining`,`amount`)),0) AS `المبلغ`
  FROM `employee_advances` WHERE `status` = 'pending'
UNION ALL
SELECT 'سجل حساب الموظفين', COUNT(*), 0 FROM `employee_ledger`
UNION ALL
SELECT 'المرتبات المسجّلة',  COUNT(*), 0 FROM `salaries`
UNION ALL
SELECT 'الموظفين النشطين',   COUNT(*), 0 FROM `employees` WHERE `is_active` = 1
UNION ALL
SELECT 'الموردين',           COUNT(*), 0 FROM `suppliers`;
