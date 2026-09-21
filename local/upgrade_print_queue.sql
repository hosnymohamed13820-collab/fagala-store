-- ======================================================================
--  upgrade_print_queue.sql — طابور الطباعة
--  نظام الفجالة ستور المالي
--
--  الموقع بيرمي هنا مهمة، ووكيل الفرع بيسحبها ويطبعها.
--  نفس فكرة scan_queue بالظبط بس بالمقلوب: هناك الموبايل بيبعت
--  والكمبيوتر بيستقبل، وهنا السيرفر بيبعت والطابعة بتستقبل.
--
--  🔴 يتشغّل على قاعدة الفجالة **و** قاعدة إشراقة — الاتنين.
-- ======================================================================

CREATE TABLE IF NOT EXISTS `print_queue` (
  `id`         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `branch_id`  INT(10) UNSIGNED DEFAULT NULL COMMENT 'نفس نوع branches.id',
  `kind`       VARCHAR(32) NOT NULL COMMENT 'نوع المستند — دلوقتي book_reserve بس',
  `ref_id`     BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'رقم المستند (رقم الحجز)',
  `payload`    MEDIUMTEXT NOT NULL COMMENT 'JSON — تخطيط الإيصال جاهز للرسم',
  `status`     ENUM('queued','claimed','done','failed') NOT NULL DEFAULT 'queued',
  `claimed_by` VARCHAR(32) DEFAULT NULL COMMENT 'session_code بتاع الوكيل اللي خدها',
  `attempts`   TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
  `last_error` VARCHAR(255) DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  `claimed_at` DATETIME DEFAULT NULL,
  `printed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pick` (`branch_id`,`status`,`id`),
  KEY `idx_ref`  (`kind`,`ref_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `print_agents` (
  `session_code` VARCHAR(32) NOT NULL COMMENT 'كود الوكيل — مكتوب في السكربت على الجهاز',
  `branch_id`    INT(10) UNSIGNED DEFAULT NULL,
  `hostname`     VARCHAR(64) DEFAULT NULL,
  `printer`      VARCHAR(120) DEFAULT NULL COMMENT 'اسم الطابعة في الويندوز',
  `last_seen`    TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`session_code`),
  KEY `idx_branch` (`branch_id`,`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- أساس سياسة رد المقدم.
-- 🔴 مخفي بقصد: مفيش أي واجهة بتقراه ولا بتكتبه ولا بيتطبع.
--    محمد لسه بيحدد الحالات اللي المقدم مابيترجعش فيها، والأساس
--    اتبنى دلوقتي عشان لما يحدّدها ما نعملش تعديل بنية تاني.
-- 🔴 `IF NOT EXISTS` مقصودة: الملف ده بيتشغّل **مرتين** — مرة على قاعدة
--    الفجالة ومرة على قاعدة إشراقة. ولو اتشغّل نص تشغيلة وأعيد، مايقعش.
--    (MariaDB 11.8 بتدعمها على ADD COLUMN — اتأكدنا من إصدار السيرفر.)
ALTER TABLE `book_reservations`
  ADD COLUMN IF NOT EXISTS `deposit_refundable` TINYINT(1) NOT NULL DEFAULT 1
      COMMENT 'أساس مخفي — مفيش واجهة بتستعمله لسه',
  ADD COLUMN IF NOT EXISTS `refund_note` VARCHAR(200) DEFAULT NULL
      COMMENT 'أساس مخفي — سبب عدم رد المقدم';

-- مفتاح وكيل الطباعة. 🔴 القيمة الحقيقية بيحطها كلود على السيرفر —
-- الصف ده بيتزرع فاضي، وprint_pull.php بيرفض كل حاجة وهو فاضي.
INSERT INTO `settings` (`setting_key`, `setting_value`)
VALUES ('print_agent_key', '')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

-- نص سياسة المقدم في الإيصال. فاضي = السطر مايطبعش خالص.
INSERT INTO `settings` (`setting_key`, `setting_value`)
VALUES ('reserve_policy_line', '')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

