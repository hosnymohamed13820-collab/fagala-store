-- إضافة عمود الرقم المرجعي ref_no ومؤشر التكرار dup_of لرسايل إنستاباي
-- لمنع تكرار الرسائل بنفس الرقم المرجعي وإخفاء المكرر من العرض (بريف ٢٠٥)
-- قابل لإعادة التشغيل ولا يمس بيانات الرسائل إطلاقاً وبدون أي مسح DELETE.

-- ١. إضافة الأعمدة إن لم تكن موجودة
SET @col_ref_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms_inbox' AND COLUMN_NAME = 'ref_no'
);
SET @ddl_col_ref = IF(
    @col_ref_exists = 0,
    'ALTER TABLE sms_inbox ADD COLUMN ref_no VARCHAR(20) NULL AFTER body, ADD COLUMN dup_of BIGINT UNSIGNED NULL AFTER ref_no',
    'DO 0'
);
PREPARE stmt_col_ref FROM @ddl_col_ref;
EXECUTE stmt_col_ref;
DEALLOCATE PREPARE stmt_col_ref;

-- ٢. إضافة مؤشر dup_of إن لم يكن موجوداً
SET @idx_dup_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms_inbox' AND INDEX_NAME = 'idx_sms_dup'
);
SET @ddl_idx_dup = IF(
    @idx_dup_exists = 0,
    'ALTER TABLE sms_inbox ADD KEY idx_sms_dup (dup_of)',
    'DO 0'
);
PREPARE stmt_idx_dup FROM @ddl_idx_dup;
EXECUTE stmt_idx_dup;
DEALLOCATE PREPARE stmt_idx_dup;

-- ٣. معالجة وتصنيف السجلات التاريخية لتحديد الأساسي والمكرر
-- استخراج الرقم المرجعي ومؤشرات الإذن والعلامة
DROP TEMPORARY TABLE IF EXISTS _tmp_sms_ranked;
CREATE TEMPORARY TABLE _tmp_sms_ranked AS
SELECT 
    s.id,
    REGEXP_SUBSTR(REGEXP_SUBSTR(s.body, 'مرجعي[[:space:]]*[0-9]+'), '[0-9]+') AS ref_no,
    IF(p.id IS NOT NULL, 1, 0) AS has_permit,
    IF(m.sms_id IS NOT NULL, 1, 0) AS has_mark
FROM sms_inbox s
LEFT JOIN sms_permits p ON p.sms_id = s.id
LEFT JOIN sms_marks m ON m.sms_id = s.id
WHERE s.body REGEXP 'مرجعي[[:space:]]*[0-9]+';

-- ترتيب صفوف كل مجموعة: إذن أولاً ثم علامة ثم أصغر معرف
DROP TEMPORARY TABLE IF EXISTS _tmp_sms_groups;
CREATE TEMPORARY TABLE _tmp_sms_groups AS
SELECT 
    id,
    ref_no,
    has_permit,
    has_mark,
    ROW_NUMBER() OVER (
        PARTITION BY ref_no 
        ORDER BY has_permit DESC, has_mark DESC, id ASC
    ) AS rnk,
    FIRST_VALUE(id) OVER (
        PARTITION BY ref_no 
        ORDER BY has_permit DESC, has_mark DESC, id ASC
    ) AS primary_id
FROM _tmp_sms_ranked;

-- حصر المجموعات المتنازع عليها (إذن أو علامة على صف بخلاف الأساسي) لتركها دون مساس
DROP TEMPORARY TABLE IF EXISTS _tmp_sms_conflicted;
CREATE TEMPORARY TABLE _tmp_sms_conflicted AS
SELECT DISTINCT ref_no
FROM _tmp_sms_groups
WHERE rnk > 1 AND (has_permit = 1 OR has_mark = 1);

-- تحديث الصف الأساسي لكل مجموعة غير متنازع عليها
UPDATE sms_inbox s
JOIN _tmp_sms_groups g ON g.id = s.id
LEFT JOIN _tmp_sms_conflicted c ON c.ref_no = g.ref_no
SET s.ref_no = g.ref_no, s.dup_of = NULL
WHERE g.rnk = 1 AND c.ref_no IS NULL AND (s.ref_no IS NULL OR s.ref_no != g.ref_no);

-- تحديث الصفوف المكررة لتشير إلى الأساسي مع تفريغ ref_no
UPDATE sms_inbox s
JOIN _tmp_sms_groups g ON g.id = s.id
LEFT JOIN _tmp_sms_conflicted c ON c.ref_no = g.ref_no
SET s.ref_no = NULL, s.dup_of = g.primary_id
WHERE g.rnk > 1 AND c.ref_no IS NULL AND (s.dup_of IS NULL OR s.dup_of != g.primary_id OR s.ref_no IS NOT NULL);

-- تنظيف الجداول المؤقتة
DROP TEMPORARY TABLE IF EXISTS _tmp_sms_conflicted;
DROP TEMPORARY TABLE IF EXISTS _tmp_sms_groups;
DROP TEMPORARY TABLE IF EXISTS _tmp_sms_ranked;

-- ٤. إضافة قيد الفرادة uq_sms_ref إن لم يكن موجوداً
SET @idx_ref_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms_inbox' AND INDEX_NAME = 'uq_sms_ref'
);
SET @ddl_idx_ref = IF(
    @idx_ref_exists = 0,
    'ALTER TABLE sms_inbox ADD UNIQUE KEY uq_sms_ref (ref_no)',
    'DO 0'
);
PREPARE stmt_idx_ref FROM @ddl_idx_ref;
EXECUTE stmt_idx_ref;
DEALLOCATE PREPARE stmt_idx_ref;

-- تسجيل المايجريشن
INSERT IGNORE INTO schema_migrations (name) VALUES ('20261025_002_sms_ref_no');

