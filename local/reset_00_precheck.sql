-- =====================================================================
--  تصفير البيانات — الخطوة (٠): الفحص قبل المسح
--  الفجالة ستور · ١ سبتمبر ٢٠٢٦
--
--  🔴 الملف ده **قراءة بس**. مافيش DELETE ولا UPDATE ولا ALTER.
--     شغّله وابعت الناتج قبل ما نكتب أي سكربت مسح.
--
--  🔴 **أي رقم مش صفر في الأقسام (١) و(٢) = وقّف.**
--     دي ديون على موظفين لسه معلّقة. لو مسحنا وهي معلّقة، الفلوس
--     دي **بتختفي** من النظام والموظف مايتحاسبش عليها.
--
--  شغّله على القاعدة المحلية الأول (`fagala`) وبعدين على الحقيقية.
-- =====================================================================

-- ---------------------------------------------------------------------
-- (١) ديون معلّقة على الموظفين — لازم كلها صفر
-- ---------------------------------------------------------------------
SELECT 'جزاءات معلّقة'        AS `الفحص`, COUNT(*) AS `العدد`, COALESCE(SUM(amount),0) AS `المبلغ`
  FROM `employee_penalties` WHERE `status` = 'pending'
UNION ALL
SELECT 'عجز عهدة معلّق',      COUNT(*), COALESCE(SUM(amount),0)
  FROM `custody_deficits`   WHERE `status` = 'pending'
UNION ALL
SELECT 'مكافآت لسه ماتصرفتش', COUNT(*), COALESCE(SUM(amount),0)
  FROM `employee_bonuses`   WHERE `status` = 'pending';

-- ---------------------------------------------------------------------
-- (٢) موظفين نشطين مرتبهم ما اتصرفش عن الشهر ده
--     🔴 لو فيه حد هنا، متمسحش — اصرف الأول.
-- ---------------------------------------------------------------------
SELECT e.id, e.full_name, e.base_salary, e.expected_monthly_hours
  FROM `employees` e
 WHERE e.is_active = 1
   AND NOT EXISTS (
        SELECT 1 FROM `salaries` s
         WHERE s.employee_id = e.id
           AND s.salary_month = '2026-08'
           AND s.reversed_at IS NULL
   )
 ORDER BY e.full_name;

-- ---------------------------------------------------------------------
-- (٣) السلف — دي **بتفضل** كرصيد افتتاحي، مش بتتمسح.
--     الرقم ده هو اللي هيفضل على الناس بعد التصفير.
-- ---------------------------------------------------------------------
SELECT a.adv_type AS `النوع`, COUNT(*) AS `العدد`,
       COALESCE(SUM(COALESCE(a.remaining, a.amount)),0) AS `المتبقي`
  FROM `employee_advances` a
 WHERE a.status = 'pending'
 GROUP BY a.adv_type;

-- ---------------------------------------------------------------------
-- (٤) أرصدة الخزائن دلوقتي
--     🔴 القرار المسجّل: **تتصفّر**. يعني أي كاش موجود فعلاً في
--        خزينة يوم التصفير **هيختفي من النظام**. اقرا الأرقام دي
--        بعينك وتأكد إنك موافق قبل ما نكمّل.
-- ---------------------------------------------------------------------
SELECT t.id, t.name AS `الخزينة`, t.type AS `النوع`, t.balance AS `الرصيد`
  FROM `treasuries` t
 WHERE t.is_active = 1
 ORDER BY t.balance DESC;

-- ---------------------------------------------------------------------
-- (٥) حجم اللي هيتمسح — عشان نعرف إحنا بنتكلم عن إيه
-- ---------------------------------------------------------------------
SELECT 'journal_entries'       AS `الجدول`, COUNT(*) AS `الصفوف` FROM `journal_entries`
UNION ALL SELECT 'treasury_movements',   COUNT(*) FROM `treasury_movements`
UNION ALL SELECT 'treasury_transfers',   COUNT(*) FROM `treasury_transfers`
UNION ALL SELECT 'shifts',               COUNT(*) FROM `shifts`
UNION ALL SELECT 'shift_cash',           COUNT(*) FROM `shift_cash`
UNION ALL SELECT 'shift_payment_lines',  COUNT(*) FROM `shift_payment_lines`
UNION ALL SELECT 'shift_reviews',        COUNT(*) FROM `shift_reviews`
UNION ALL SELECT 'branch_custody',       COUNT(*) FROM `branch_custody`
UNION ALL SELECT 'custody_lines',        COUNT(*) FROM `custody_lines`
UNION ALL SELECT 'custody_deficits',     COUNT(*) FROM `custody_deficits`
UNION ALL SELECT 'disbursement_orders',  COUNT(*) FROM `disbursement_orders`
UNION ALL SELECT 'salary_shift_deficits',COUNT(*) FROM `salary_shift_deficits`
UNION ALL SELECT 'salaries (بتفضل)',      COUNT(*) FROM `salaries`
UNION ALL SELECT 'employee_advances (بتفضل)', COUNT(*) FROM `employee_advances`
UNION ALL SELECT 'suppliers (بتفضل)',     COUNT(*) FROM `suppliers`;

-- ---------------------------------------------------------------------
-- (٦) 🔴 قيود الموردين جوّه اليومية
--     الموردين قرارهم «يفضلوا زي ما هم» — لكن **حركاتهم** في
--     `journal_entries`. لو مسحنا اليومية، أرصدتهم تفضل في جدولهم
--     من غير أي تاريخ حركة يشرحها.
-- ---------------------------------------------------------------------
SELECT je.ref_type AS `نوع القيد`, COUNT(*) AS `العدد`,
       COALESCE(SUM(je.debit),0) AS `مدين`, COALESCE(SUM(je.credit),0) AS `دائن`
  FROM `journal_entries` je
 GROUP BY je.ref_type
 ORDER BY COUNT(*) DESC;
