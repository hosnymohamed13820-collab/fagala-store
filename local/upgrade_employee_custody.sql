-- =====================================================================
--  upgrade_employee_custody.sql — عهدة الموظف بنفس فلو عهدة الفرع
--  نظام الفجالة ستور المالي
-- =====================================================================

-- (١) تعديل نوع الخزائن لإضافة employee_custody وإضافة عمود employee_id
ALTER TABLE `treasuries`
  MODIFY COLUMN `type` ENUM('cash','visa','instapay','wallet','card','branch_custody','main','employee_custody')
  NOT NULL DEFAULT 'cash';

ALTER TABLE `treasuries`
  ADD COLUMN `employee_id` INT UNSIGNED NULL DEFAULT NULL AFTER `branch_id`;

ALTER TABLE `treasuries`
  ADD INDEX `idx_treasuries_employee` (`employee_id`);

-- (٢) دعم الموظف في إذن الصرف
ALTER TABLE `disbursement_orders`
  MODIFY COLUMN `branch_id` INT UNSIGNED NULL DEFAULT NULL,
  ADD COLUMN `employee_id` INT UNSIGNED NULL DEFAULT NULL AFTER `branch_id`;

ALTER TABLE `disbursement_orders`
  ADD INDEX `idx_orders_employee` (`employee_id`);

-- (٣) السماح بأن يكون branch_id فارغاً في عهدة الموظف إذا لم يكن مربوطاً بفرع
ALTER TABLE `branch_custody`
  MODIFY COLUMN `branch_id` INT UNSIGNED NULL DEFAULT NULL;

ALTER TABLE `branch_custody`
  ADD INDEX `idx_employee_status` (`employee_id`, `status`);
