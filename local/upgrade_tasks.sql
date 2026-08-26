-- =====================================================================
--  موديول المهام (Tasks) والإشعارات (Notifications) — نظام الفجالة ستور
--  شغّله مرة على اللوكال ومرة على الهوستنج.
--  آمن: CREATE TABLE IF NOT EXISTS + فحص قبل إضافة الموديول للصلاحيات.
--  ترميز utf8mb4_unicode_ci صراحةً (تفاديًا لمشاكل الـ collation).
-- =====================================================================

-- (1) المهام
CREATE TABLE IF NOT EXISTS `tasks` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`       VARCHAR(200) NOT NULL,
  `details`     TEXT NULL,
  `priority`    ENUM('normal','important','urgent') NOT NULL DEFAULT 'normal',
  `due_date`    DATE NULL,
  `created_by`  INT UNSIGNED NULL,             -- مصدر المهمة (user)
  `status`      ENUM('open','closed') NOT NULL DEFAULT 'open',
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_task_creator` (`created_by`),
  CONSTRAINT `fk_task_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- (2) المستهدفون بالمهمة (صف لكل مستخدم)
CREATE TABLE IF NOT EXISTS `task_targets` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id`     BIGINT UNSIGNED NOT NULL,
  `user_id`     INT UNSIGNED NOT NULL,
  `status`      ENUM('pending','done') NOT NULL DEFAULT 'pending',
  `done_at`     TIMESTAMP NULL,
  `seen_at`     TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_task_user` (`task_id`,`user_id`),
  KEY `idx_tt_user` (`user_id`),
  CONSTRAINT `fk_tt_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tt_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- (3) تعليقات/ردود على المهمة
CREATE TABLE IF NOT EXISTS `task_comments` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id`     BIGINT UNSIGNED NOT NULL,
  `user_id`     INT UNSIGNED NULL,
  `body`        TEXT NOT NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tc_task` (`task_id`),
  CONSTRAINT `fk_tc_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tc_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- (4) الإشعارات (للجرس)
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,         -- صاحب الإشعار
  `type`        VARCHAR(40) NOT NULL DEFAULT 'task',
  `title`       VARCHAR(200) NOT NULL,
  `body`        VARCHAR(255) NULL,
  `link`        VARCHAR(255) NULL,             -- لينك يودّي للمهمة
  `is_read`     TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user` (`user_id`,`is_read`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- (5) إضافة صلاحية موديول tasks للأونر (لو مش موجودة)
--     الأونر بياخد كل الصلاحيات تلقائياً، فده احتياطي للأدوار اللي بتُدار يدوياً.
--     (مش بنضيف صف في أي جدول صلاحيات هنا — الموديول بيتعرّف في auth.php)
