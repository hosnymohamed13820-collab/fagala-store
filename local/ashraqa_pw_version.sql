-- 🔴 على قاعدة إشراقة
ALTER TABLE users ADD COLUMN pw_version INT NOT NULL DEFAULT 1 COMMENT 'بيزيد مع تغيير الباسورد — الجلسات القديمة بتخرج';
