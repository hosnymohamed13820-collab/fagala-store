-- بريف ٢٠٤: سجل تعديل الوردية المعتمدة محتاج قيمة action مستقلة عن تعديل المستنية.
-- آمن للتكرار: بنوسّع الـENUM بس لو القيمة الجديدة مش موجودة.
SET @shift_action_type = (
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shift_edit_log'
      AND COLUMN_NAME = 'action'
);
SET @shift_action_nullable = (
    SELECT IS_NULLABLE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shift_edit_log' AND COLUMN_NAME = 'action'
);
SET @shift_action_default = (
    SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shift_edit_log' AND COLUMN_NAME = 'action'
);
SET @shift_approved_action_ddl = IF(@shift_action_type NOT LIKE '%date_branch_approved%',
    CONCAT('ALTER TABLE shift_edit_log MODIFY action ',
        LEFT(@shift_action_type, CHAR_LENGTH(@shift_action_type) - 1),
        ',''date_branch_approved'') ',
        IF(@shift_action_nullable = 'YES', 'NULL', 'NOT NULL'),
        IF(@shift_action_default IS NULL, '', CONCAT(' DEFAULT ', QUOTE(@shift_action_default)))),
    'DO 0');
PREPARE shift_approved_action_stmt FROM @shift_approved_action_ddl;
EXECUTE shift_approved_action_stmt;
DEALLOCATE PREPARE shift_approved_action_stmt;

INSERT IGNORE INTO schema_migrations (name) VALUES ('20261025_002_shift_edit_approved_action');
