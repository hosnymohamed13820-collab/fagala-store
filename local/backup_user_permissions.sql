-- backup_user_permissions.sql
-- يتعمل قبل رفع شاشة الصلاحيات الجديدة (المرحلة 2).
-- دي نسخة احتياطية لمرة واحدة من جدول user_permissions نرجع منها لو حصل أي غلط في صلاحيات المستخدمين.
-- للتنفيذ يدوياً من phpMyAdmin أو السيرفر (ما بيتشغلش من كود PHP).

CREATE TABLE IF NOT EXISTS `user_permissions_backup_2026_08`
  AS SELECT * FROM `user_permissions`;
