-- =====================================================================
--  تحسين الأداء + تنضيف — نظام الفجالة ستور
--  شغّله مرة على اللوكال ومرة على الهوستنج. آمن للتكرار وماسحش أي شغل.
--  (كله فهارس وحذف لوجات قديمة — مفيش أي بيانات شغل بتتلمس)
-- =====================================================================

-- ---------------------------------------------------------------------
-- (1) أهم فهرس في النظام
--     invoice_distribution_lines.notes بيخزّن **كود الصنف**، وبيتبحث فيه
--     في 12+ استعلام (WHERE notes = ? و GROUP BY notes). ده أكبر جدول
--     بالصفوف — من غير الفهرس ده كل فتح فاتورة بيمشي على الجدول كله.
-- ---------------------------------------------------------------------
ALTER TABLE `invoice_distribution_lines`
  ADD KEY IF NOT EXISTS `idx_idl_dist_code` (`distribution_id`, `notes`(40));

-- الاستعلامات اللي بتجمّع بالفرع (كشوف الفروع)
ALTER TABLE `invoice_distribution_lines`
  ADD KEY IF NOT EXISTS `idx_idl_branch` (`branch_id`);

-- ---------------------------------------------------------------------
-- (2) أسعار البيع — البحث دايمًا بـ (سجل التوزيع + الكود)
-- ---------------------------------------------------------------------
ALTER TABLE `invoice_item_prices`
  ADD KEY IF NOT EXISTS `idx_iip_code` (`item_code`);

-- ---------------------------------------------------------------------
-- (3) المرآة: البحث بالكود جوه بنود الفواتير (التسعير المجمّع)
-- ---------------------------------------------------------------------
ALTER TABLE `hq_invoice_lines`
  ADD KEY IF NOT EXISTS `idx_hqline_code` (`item_code`);

ALTER TABLE `hq_unposted_lines`
  ADD KEY IF NOT EXISTS `idx_hqunpline_code` (`item_code`);

-- ---------------------------------------------------------------------
-- (4) سجل الدخول — بيتقري دلوقتي في كل محاولة دخول (قفل التخمين)
-- ---------------------------------------------------------------------
ALTER TABLE `login_log`
  ADD KEY IF NOT EXISTS `idx_ll_user_time` (`username`, `created_at`),
  ADD KEY IF NOT EXISTS `idx_ll_ip_time`   (`ip_address`, `created_at`);

-- ---------------------------------------------------------------------
-- (5) ربط المجموعات بسجلات التوزيع (بيتعمل عليه JOIN كتير)
-- ---------------------------------------------------------------------
ALTER TABLE `invoice_group_items`
  ADD KEY IF NOT EXISTS `idx_igi_invno` (`invoice_no`);

-- ---------------------------------------------------------------------
-- (6) تنضيف لمرة واحدة للّوجات المتراكمة
--     (بعد كده sync_hq.php بينضّف لوحده كل فترة)
-- ---------------------------------------------------------------------
-- ملحوظة: عمود الوقت في hq_sync_log اسمه synced_at (مش created_at)
DELETE FROM `hq_sync_log`   WHERE synced_at < NOW() - INTERVAL 60 DAY;
DELETE FROM `login_log`     WHERE created_at < NOW() - INTERVAL 180 DAY;
DELETE FROM `notifications` WHERE is_read = 1 AND created_at < NOW() - INTERVAL 90 DAY;

-- ---------------------------------------------------------------------
-- (7) تحديث إحصائيات الجداول عشان MySQL يستخدم الفهارس الجديدة صح
-- ---------------------------------------------------------------------
ANALYZE TABLE `invoice_distribution_lines`, `invoice_item_prices`,
              `hq_invoice_lines`, `hq_unposted_lines`, `login_log`;
