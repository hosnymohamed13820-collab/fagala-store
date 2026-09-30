-- 🔴 على قاعدة إشراقة (ashraqa.hosnimanagement.com) — مش قاعدة الفجالة.
CREATE TABLE IF NOT EXISTS `sms_inbox` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `msg_hash`    CHAR(64) NOT NULL,                 -- sha256(sender|sms_at_ms|body) — نفس الرسالة ماتتسجّلش مرتين
  `sender`      VARCHAR(64) NOT NULL,
  `body`        TEXT NOT NULL,
  `sms_at`      DATETIME NOT NULL,                 -- وقت الرسالة على الموبايل (Africa/Cairo)
  `android_id`  BIGINT DEFAULT NULL,               -- _id بتاع الرسالة في content://sms (للقديم)
  `source`      ENUM('live','backfill') NOT NULL,
  `device`      VARCHAR(60) DEFAULT NULL,
  `received_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sms_hash` (`msg_hash`),
  KEY `idx_sms_at` (`sms_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
  ('sms_include_keywords', CONCAT_WS(CHAR(10), 'BanK-AlAhly', 'تحويل لحظي', 'مسبقة الدفع', 'المدفوعة مقدما', '19623', 'InstaPay', 'انستاباي'), 'رسايل SMS اللي فيها أي كلمة من دول (في الراسل أو النص) بتتبعت للموقع — سطر لكل كلمة'),
  ('sms_exclude_keywords', CONCAT_WS(CHAR(10), 'OTP', 'كود', 'رمز', 'كلمة المرور', 'password', 'verification', 'لا تشارك'), 'أي رسالة فيها كلمة من دول ماتتبعتش خالص (أكواد التحقق) — سطر لكل كلمة');
