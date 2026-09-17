-- =====================================================================
--  upgrade_receipt_reopen.sql — دعم رفض وإعادة فتح الاستلام + حفظ السبب
--  نظام الفجالة ستور المالي
-- =====================================================================

ALTER TABLE `receipts`
  ADD COLUMN IF NOT EXISTS `reject_reason` VARCHAR(255) NULL DEFAULT NULL AFTER `approved_by`,
  ADD COLUMN IF NOT EXISTS `rejected_at` TIMESTAMP NULL DEFAULT NULL AFTER `reject_reason`,
  ADD COLUMN IF NOT EXISTS `rejected_by` INT UNSIGNED NULL DEFAULT NULL AFTER `rejected_at`;
