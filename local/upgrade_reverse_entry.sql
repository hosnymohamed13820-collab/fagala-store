-- =====================================================================
--  upgrade_reverse_entry.sql — حقول القيد العكسي لحركات الخزينة
--  نظام الفجالة ستور المالي
-- =====================================================================

ALTER TABLE `u834909335_fagala`.`treasury_movements`
  ADD COLUMN IF NOT EXISTS `reversed_at` TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `reversed_by` INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS `reversal_entry_id` BIGINT UNSIGNED NULL COMMENT 'journal_entries.id بتاع القيد العكسي',
  ADD INDEX IF NOT EXISTS `idx_reversed_at` (`reversed_at`);
