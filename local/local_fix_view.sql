-- =====================================================================
--  إصلاح الـview على النسخة المحلية (Laragon)
--  🔴 الملف ده **للوكل بس** — عمره ما يتشغّل على السيرفر.
--
--  استيراد نسخة السيرفر بيرجّع:
--      Note (1449) The user specified as a definer
--      ('u834909335_hosni'@'127.0.0.1') does not exist
--
--  السبب: الـview متسجّل عليها مستخدم Hostinger كمالك، وهو مش
--  موجود على جهازك. النتيجة إن أي صفحة بتستخدمها بتقع —
--  و`agent_lib.php:239` بتستخدمها في استعلام المخزون وتحليل الموسم.
-- =====================================================================

USE `fagala`;

DROP VIEW IF EXISTS `hq_branch_balances_nz`;

CREATE SQL SECURITY INVOKER VIEW `hq_branch_balances_nz` AS
SELECT `hq_branch_balances`.`item_id` AS `item_id`, `hq_branch_balances`.`sto_id` AS `sto_id`, `hq_branch_balances`.`balance` AS `balance`, `hq_branch_balances`.`agent_key` AS `agent_key`, `hq_branch_balances`.`updated_at` AS `updated_at` FROM `hq_branch_balances` WHERE `hq_branch_balances`.`balance` <> 0;

-- الفحص — لازم ترجّع صفوف من غير خطأ
SELECT COUNT(*) AS `عدد الصفوف` FROM `hq_branch_balances_nz`;
