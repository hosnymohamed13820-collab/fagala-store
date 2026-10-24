-- فرادة رسالة إنستاباي تبقى بالراسل والرقم المرجعي مع بعض.
-- قابل لإعادة التشغيل: لو المفتاح مركب بالفعل، الأمر DO 0. مفيش تعديل في صفوف الرسايل.

SET @sms_ref_columns = (
    SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms_inbox' AND INDEX_NAME = 'uq_sms_ref'
);
SET @sms_ref_ddl = IF(
    @sms_ref_columns = 'ref_no',
    'ALTER TABLE sms_inbox DROP INDEX uq_sms_ref, ADD UNIQUE KEY uq_sms_ref (sender, ref_no)',
    'DO 0'
);
PREPARE stmt_sms_ref FROM @sms_ref_ddl;
EXECUTE stmt_sms_ref;
DEALLOCATE PREPARE stmt_sms_ref;

INSERT IGNORE INTO schema_migrations (name) VALUES ('20261025_005_sms_ref_sender');
