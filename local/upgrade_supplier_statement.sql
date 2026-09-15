-- =====================================================================
--  upgrade_supplier_statement.sql — كشف حساب الموردين وربط نظام الشركة
--  نظام الفجالة ستور المالي
-- =====================================================================

ALTER TABLE `u834909335_fagala`.`suppliers`
  ADD COLUMN IF NOT EXISTS `erp_supplier_id` INT NULL COMMENT 'رقم المورد في نظام الشركة (hq_invoices.supplier_id)',
  ADD INDEX IF NOT EXISTS `idx_erp_supplier_id` (`erp_supplier_id`);

-- تعبئة رقم المورد من فواتير نظام الشركة بالاسم (آمنة للتكرار)
UPDATE `u834909335_fagala`.`suppliers` s
JOIN (
    SELECT supplier_name, MIN(supplier_id) AS sid
    FROM `u834909335_fagala`.`hq_invoices`
    WHERE supplier_id IS NOT NULL
    GROUP BY supplier_name
) h ON h.supplier_name = s.name
SET s.erp_supplier_id = h.sid
WHERE s.erp_supplier_id IS NULL;

-- تاريخ بداية كشف الحساب (بعد جرد ٢٧ يونيو ٢٠٢٦)
INSERT IGNORE INTO `u834909335_fagala`.`settings` (`setting_key`, `setting_value`, `description`)
VALUES ('supplier_statement_start', '2026-06-27', 'تاريخ بداية كشف حساب الموردين');

-- استعلام فحص: الموردين اللي erp_supplier_id فاضي
-- SELECT id, name FROM `u834909335_fagala`.`suppliers` WHERE erp_supplier_id IS NULL AND is_active = 1;

-- استعلام فحص: أسماء الموردين في hq_invoices اللي مالهاش مورد مطابق في suppliers
-- SELECT DISTINCT supplier_name FROM `u834909335_fagala`.`hq_invoices` WHERE supplier_name NOT IN (SELECT name FROM `u834909335_fagala`.`suppliers`);
