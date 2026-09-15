-- =====================================================================
--  upgrade_distribution_keys.sql — إضافة مفتاح الفرع ونوع المستند لسجلات التوزيع
--  نظام الفجالة ستور (بريف ٩)
-- =====================================================================

-- 1) إضافة الأعمدة والفهارس لجدول invoice_distribution
ALTER TABLE `u834909335_fagala`.`invoice_distribution`
  ADD COLUMN IF NOT EXISTS `company_branch` INT NULL COMMENT 'فرع الشركة من نظام الإدارة',
  ADD COLUMN IF NOT EXISTS `doc_type` SMALLINT NULL COMMENT 'نوع المستند (2 شراء، 6 مردود، الخ)',
  ADD INDEX IF NOT EXISTS `idx_dist_key` (`invoice_no`, `company_branch`, `doc_type`);

-- 2) إضافة الأعمدة لجدول invoice_group_items
ALTER TABLE `u834909335_fagala`.`invoice_group_items`
  ADD COLUMN IF NOT EXISTS `company_branch` INT NULL COMMENT 'فرع الشركة من نظام الإدارة',
  ADD COLUMN IF NOT EXISTS `doc_type` SMALLINT NULL COMMENT 'نوع المستند';

-- 3) تعبئة invoice_distribution (آمنة للتكرار: WHERE company_branch IS NULL)
--    تطابق واحد بالظبط (COUNT = 1) بين رقم المستند واسم المورد وتاريخ التوزيع
UPDATE `u834909335_fagala`.`invoice_distribution` d
JOIN (
    SELECT 
        d_sub.id AS dist_id,
        MIN(h.company_branch) AS company_branch,
        MIN(h.doc_type) AS doc_type
    FROM `u834909335_fagala`.`invoice_distribution` d_sub
    JOIN `u834909335_fagala`.`suppliers` s ON s.id = d_sub.supplier_id
    JOIN `u834909335_fagala`.`hq_invoices` h ON h.doc_no = d_sub.invoice_no 
        AND h.supplier_name = s.name 
        AND DATE(h.invoice_date) = d_sub.dist_date
    WHERE d_sub.company_branch IS NULL
    GROUP BY d_sub.id
    HAVING COUNT(h.sth_id) = 1
) matched ON matched.dist_id = d.id
SET d.company_branch = matched.company_branch,
    d.doc_type = matched.doc_type
WHERE d.company_branch IS NULL;

-- 4) تعبئة invoice_group_items من hq_invoices (الفواتير المرحّلة)
UPDATE `u834909335_fagala`.`invoice_group_items` gi
JOIN `u834909335_fagala`.`hq_invoices` h 
  ON h.sth_id = gi.sth_id AND h.suffix = gi.suffix
SET gi.company_branch = h.company_branch,
    gi.doc_type = h.doc_type
WHERE gi.company_branch IS NULL;

-- 5) تعبئة invoice_group_items من hq_unposted (الفواتير غير المحفوظة YY_MM)
UPDATE `u834909335_fagala`.`invoice_group_items` gi
JOIN `u834909335_fagala`.`hq_unposted` u 
  ON u.sth_id = gi.sth_id
SET gi.company_branch = u.company_branch,
    gi.doc_type = u.doc_type
WHERE gi.suffix = 'YY_MM' AND gi.company_branch IS NULL;

-- =====================================================================
--  استعلامات فحص (SELECT بس)
-- =====================================================================

-- (أ) سجلات التوزيع التي فضلت NULL (رقمها · المورد · التاريخ · عدد الفواتير المرشحة)
SELECT 
    d.id AS dist_id,
    d.invoice_no,
    s.name AS supplier_name,
    d.dist_date,
    COUNT(h.sth_id) AS candidate_invoices_count
FROM `u834909335_fagala`.`invoice_distribution` d
LEFT JOIN `u834909335_fagala`.`suppliers` s ON s.id = d.supplier_id
LEFT JOIN `u834909335_fagala`.`hq_invoices` h ON h.doc_no = d.invoice_no 
    AND h.supplier_name = s.name 
    AND DATE(h.invoice_date) = d.dist_date
WHERE d.company_branch IS NULL
GROUP BY d.id, d.invoice_no, s.name, d.dist_date
ORDER BY d.id ASC;

-- (ب) لكل سجل توزيع اتربط: عدد سطور التوزيع التي كودها غير موجود في سطور فاتورته
SELECT 
    d.id AS dist_id,
    d.invoice_no,
    s.name AS supplier_name,
    COUNT(dl.id) AS total_dist_lines,
    COUNT(CASE WHEN hl.item_code IS NULL THEN 1 END) AS unmatched_lines
FROM `u834909335_fagala`.`invoice_distribution` d
JOIN `u834909335_fagala`.`suppliers` s ON s.id = d.supplier_id
JOIN `u834909335_fagala`.`hq_invoices` h 
  ON h.doc_no = d.invoice_no 
 AND h.company_branch = d.company_branch 
 AND h.doc_type = d.doc_type
 AND h.supplier_name = s.name 
 AND DATE(h.invoice_date) = d.dist_date
JOIN `u834909335_fagala`.`invoice_distribution_lines` dl ON dl.distribution_id = d.id
LEFT JOIN `u834909335_fagala`.`hq_invoice_lines` hl 
    ON hl.sth_id = h.sth_id 
   AND hl.suffix = h.suffix 
   AND COALESCE(NULLIF(hl.item_code,''), CONCAT('#', hl.item_id)) = dl.notes
WHERE d.company_branch IS NOT NULL
GROUP BY d.id, d.invoice_no, s.name
HAVING unmatched_lines > 0
ORDER BY unmatched_lines DESC;
