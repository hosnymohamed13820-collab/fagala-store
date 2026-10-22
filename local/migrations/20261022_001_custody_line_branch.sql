-- رقم الفرع على كل سطر مصروف عهدة عشان نقدر نوزّع مصروفات عهدة الموظف على الفروع.
-- MySQL 8.4 على Laragon مابيقبلش ADD COLUMN IF NOT EXISTS؛ فبنحقق نفس الشرط من information_schema.
-- قابل لإعادة التشغيل: الإضافة بتحصل لو العمود مش موجود، والتحديث للسطر اللي لسه فرعه NULL بس.
SET @custody_branch_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'custody_lines' AND COLUMN_NAME = 'branch_id'
);
SET @custody_branch_ddl = IF(
    @custody_branch_exists = 0,
    'ALTER TABLE custody_lines ADD COLUMN branch_id INT UNSIGNED NULL AFTER supplier_id',
    'DO 0'
);
PREPARE custody_branch_stmt FROM @custody_branch_ddl;
EXECUTE custody_branch_stmt;
DEALLOCATE PREPARE custody_branch_stmt;

-- افتراض للسطور القديمة: ناخد فرع العهدة، وده مش دليل على الفرع الحقيقي لكل سطر قديم.
UPDATE custody_lines cl
JOIN branch_custody bc ON bc.id = cl.custody_id
SET cl.branch_id = bc.branch_id
WHERE cl.branch_id IS NULL;
