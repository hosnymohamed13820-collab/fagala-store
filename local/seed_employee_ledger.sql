-- =====================================================================
--  seed_employee_ledger.sql — بذور الأرصدة الافتتاحية لسجل حركات الموظفين
--  نظام الفجالة ستور المالي
-- =====================================================================
--
--  ليه اتعمل:
--    ترحيل الأرصدة القائمة الحالية من employee_advances إلى employee_ledger
--    كرصيد افتتاحي (opening) مدين (debit) لكل سلفة معلّقة.
--
--  🔴 القواعد المتبعة:
--    * المبلغ = COALESCE(remaining, amount) أي المتبقي الفعلي وليس الأصلي.
--    * السلف الملغاة أو التي متبقيها صفر أو سالب مايتعملهاش صف خالص.
--    * آمن للتكرار: WHERE NOT EXISTS على (ref_type='advance' AND ref_id=a.id)
--      فلو اتشغّل أكتر من مرة مايكرّرش أي صفوف.
--
--  التشغيل: phpMyAdmin → SQL → الصق ونفّذ.
--  الملف ده **مايترفعش على الدومين** — بيتشغّل من phpMyAdmin وخلاص.
-- =====================================================================

INSERT INTO `employee_ledger` (
  `employee_id`,
  `entry_date`,
  `direction`,
  `amount`,
  `move_type`,
  `description`,
  `treasury_id`,
  `ref_type`,
  `ref_id`,
  `created_by`
)
SELECT
  a.`employee_id`,
  a.`advance_date`,
  'debit' AS `direction`,
  COALESCE(a.`remaining`, a.`amount`) AS `amount`,
  'opening' AS `move_type`,
  CONCAT('رصيد مرحّل من سلفة معلّقة #', a.`id`) AS `description`,
  NULL AS `treasury_id`,
  'advance' AS `ref_type`,
  a.`id` AS `ref_id`,
  NULL AS `created_by`
FROM `employee_advances` a
WHERE a.`status` = 'pending'
  AND COALESCE(a.`remaining`, a.`amount`) > 0
  AND NOT EXISTS (
    SELECT 1
    FROM `employee_ledger` el
    WHERE el.`ref_type` = 'advance'
      AND el.`ref_id` = a.`id`
  );
