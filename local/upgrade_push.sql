-- =====================================================================
--  upgrade_push.sql — جداول إشعارات الويب (Web Push Notifications)
--  نظام الفجالة ستور المالي
--  المرحلة (أ): جداول الاشتراكات والطابور وإعدادات VAPID
--  شغّله في phpMyAdmin مرة واحدة.
--  ترميز utf8mb4_unicode_ci صراحةً (تفاديًا لمشاكل الـ collation).
-- =====================================================================

-- (١) جدول اشتراكات الأجهزة (جهاز واحد = صف واحد)
CREATE TABLE IF NOT EXISTS `push_subscriptions` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `endpoint`      VARCHAR(500) NOT NULL,
  `endpoint_hash` CHAR(64) NOT NULL,        -- SHA-256 hex للـendpoint لتفادي تخطي حد الفهرس 3072 بايت
  `p256dh`        VARCHAR(255) NOT NULL,
  `auth`          VARCHAR(64) NOT NULL,
  `user_agent`    VARCHAR(255) NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_ok_at`    TIMESTAMP NULL,
  `fail_count`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_push_endpoint` (`endpoint_hash`),
  KEY `idx_push_user` (`user_id`),
  CONSTRAINT `fk_push_sub_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- (٢) جدول طابور الإشعارات (رسالة واحدة لمستخدم واحد)
CREATE TABLE IF NOT EXISTS `push_queue` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `title`      VARCHAR(200) NOT NULL,
  `body`       VARCHAR(255) NULL,
  `link`       VARCHAR(255) NULL,
  `status`     ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `tries`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_error` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at`    TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `idx_push_q` (`status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- (٣) إعدادات VAPID العامة (المفتاح الخاص مكانه config.php على السيرفر بس)
INSERT INTO `settings` (`setting_key`, `setting_value`)
VALUES ('vapid_public_key', ''), ('vapid_subject', '')
ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`;

-- سطر الكرون على السيرفر (تشغيل الوركر كل دقيقة عبر POST مع الهيدر):
-- * * * * * curl -s -m 50 -X POST -H "X-Push-Token: push_8Km4xQ9vR2Nw7Lt5Bz1Yd3Hs6Fj9Gc" https://hosnimanagement.com/push_worker.php
