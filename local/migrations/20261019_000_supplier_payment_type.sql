-- نوع المورد: كاش ولا آجل (بريف ١٢٥ — تقرير «مشتريات مورد»)
-- القيم الأولية منقولة من شيت محمد ١٩/١٠/٢٠٢٦ بكود المورد في True.
-- suppliers مافيهاش كود True، فالربط بـhq_invoices: supplier_code ← supplier_id = suppliers.erp_supplier_id
-- الفروع والرصيد الافتتاحي والمستهلكات وتصليح التكلفة (11 13 14 82 86 130 176 199 336 379) فاضيين بقصد.
-- مابيلمسش مورد اتحدّد نوعه قبل كده (payment_type IS NULL بس) — ينفع يتشغّل مرتين.

ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS payment_type VARCHAR(10) NULL DEFAULT NULL AFTER is_active;
-- كود المورد في True — وكيل الإدارة (2.7+) بيبعته وsync_hq بيملاه (بريف ١٢٥). بيظهر في التعريفات عشان تحديد النوع يبقى سهل.
ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS erp_supplier_code VARCHAR(30) NULL DEFAULT NULL AFTER erp_supplier_id;

UPDATE suppliers s
JOIN (SELECT DISTINCT supplier_id, supplier_code FROM hq_invoices WHERE supplier_id IS NOT NULL) h
  ON h.supplier_id = s.erp_supplier_id
SET s.payment_type = 'credit'
WHERE s.payment_type IS NULL
  AND h.supplier_code IN ('1','2','8','12','18','29','32','49','50','55','77','80','90','101','131','315','334','335','378','383');

UPDATE suppliers s
JOIN (SELECT DISTINCT supplier_id, supplier_code FROM hq_invoices WHERE supplier_id IS NOT NULL) h
  ON h.supplier_id = s.erp_supplier_id
SET s.payment_type = 'cash'
WHERE s.payment_type IS NULL
  AND h.supplier_code IN ('57','58','59','60','61','62','69','71','72','73','74','75','76','78','79','83','85','87',
                          '107','109','111','112','114','116','122','124','125','132','145','171','206','219','254',
                          '300','303','304','314','316','354','376','385','394','404','406','407','408');

INSERT IGNORE INTO schema_migrations (name) VALUES ('20261019_000_supplier_payment_type');
