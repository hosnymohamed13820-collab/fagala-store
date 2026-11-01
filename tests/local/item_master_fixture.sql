-- بريف ٨٣٥: جداول مؤقتة للجلسة بس؛ مفيش تغيير في الجداول الأصلية.
CREATE TEMPORARY TABLE hq_invoices (
 sth_id BIGINT, suffix VARCHAR(8), doc_type INT, supplier_id INT, supplier_code VARCHAR(40),
 supplier_name VARCHAR(255), invoice_date DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TEMPORARY TABLE hq_invoice_lines (
 sth_id BIGINT, suffix VARCHAR(8), line_no INT, item_id INT, quantity DECIMAL(16,3),
 unit_price DECIMAL(16,3), unit_factor DECIMAL(12,3), item_code VARCHAR(64)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TEMPORARY TABLE item_master (
 id INT, sys_code VARCHAR(20) NULL, true_item_id INT, name1 VARCHAR(255), sale_price_class VARCHAR(20), item_type VARCHAR(30),
 is_cancelled INT, base_unit VARCHAR(40), open_price INT, cat_is1_id INT, cat_description_id INT,
 cat_type_id INT, cat_stage_id INT, cat_company_id INT, min_qty DECIMAL(12,3), max_qty DECIMAL(12,3),
 max_discount_pct DECIMAL(12,3), created_by INT, updated_by INT, updated_at DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TEMPORARY TABLE item_master_codes (
 id INT DEFAULT 835, item_master_id INT, code VARCHAR(64), name1 VARCHAR(255), name2 VARCHAR(255),
 code_type INT, unit_name VARCHAR(40), unit_type VARCHAR(30), unit_factor DECIMAL(12,3),
 whole_qty INT, open_price INT, prep_days INT, display_screen VARCHAR(40), display_order INT,
 is_purchase_code INT, is_sale_code INT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO hq_invoices (sth_id,suffix,doc_type,supplier_id,supplier_code,supplier_name,invoice_date) VALUES
 (83501,'99_83',2,4,'4','الرئيسية (الادارة)','2026-11-01'),
 (83502,'99_83',2,1000181,'1000181','فرع دجلة','2026-10-31'),
 (83503,'99_83',2,1000245,'1000245','إشراقة','2026-10-30'),
 (83504,'99_83',2,1000195,'1000195','رصيد افتتاحي','2026-10-29'),
 (83505,'99_83',2,99999835,'835','مورد اختبار','2026-10-28');
INSERT INTO hq_invoice_lines (sth_id,suffix,line_no,item_id,quantity,unit_price,unit_factor) VALUES
 (83501,'99_83',1,99999835,10,15,1),
 (83502,'99_83',1,99999835,10,15,1),
 (83503,'99_83',1,99999835,10,15,1),
 (83504,'99_83',1,99999835,10,15,1),
 (83505,'99_83',1,99999835,10,15,1);
INSERT INTO item_master (id,name1,created_by,updated_by) VALUES (99999835,'صنف اختبار ٨٣٥',1,1);
INSERT INTO item_master_codes (item_master_id,code,name1,code_type,unit_name,unit_type,unit_factor)
 VALUES (99999835,'B835-SITE','صنف اختبار ٨٣٥',0,'قطعة','base',1);
