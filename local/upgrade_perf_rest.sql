-- =====================================================================
--  الجزء الناقص من upgrade_perf.sql
--  (الفهارس اتظبطت خلاص — ده التنضيف بس)
--
--  التصحيح: عمود الوقت في hq_sync_log اسمه synced_at مش created_at.
-- =====================================================================

DELETE FROM `hq_sync_log`   WHERE synced_at   < NOW() - INTERVAL 60 DAY;
DELETE FROM `login_log`     WHERE created_at  < NOW() - INTERVAL 180 DAY;
DELETE FROM `notifications` WHERE is_read = 1 AND created_at < NOW() - INTERVAL 90 DAY;

ANALYZE TABLE `invoice_distribution_lines`, `invoice_item_prices`,
              `hq_invoice_lines`, `hq_unposted_lines`, `login_log`;
