-- =====================================================================
-- ashraqa_purchases.sql — مرآة فواتير الشراء ومرتجعات الشراء بتاعة إشراقة
-- ---------------------------------------------------------------------
-- 🔴 يتشغّل من phpMyAdmin على قاعدة الفجالة (u834909335_fagala) **قبل** رفع
--    sync_ashraqa_purchases.php وقبل تحديث agent_ashraqa.ps1 على جهاز إشراقة.
--
-- ليه (٣٠ سبتمبر ٢٠٢٦):
--   إشراقة (نظام Redux) بتسجّل البضاعة اللي بتبعتها لفروعنا على إنها
--   «مرتجع شراء» (SSTT 115) للمورد رقم 8 «الفروع أستاذ محمد عصام»،
--   واسم الفرع مكتوب في الملاحظات (NOTS): دجلة · بونجور · ABC · الفجالة.
--   والفرع بيسجّلها عنده «شراء من الإدارة» — فالإدارة مالهاش مقابل ولا رصيد.
--   الجدولين دول عشان نطابقهم صنف بصنف مع مشتريات الفروع.
--
-- المصدر عندهم: Purchases + Purchases_details، SSTT_ID 113 (شراء) و115 (مرتجع شراء).
-- المفتاح عندهم: (SSTT_ID, BRCH_ID, FY_NO, add_doc) — نفس المفتاح هنا.
-- الجدولين مرآة بس: الوكيل هو المصدر، وsync_ashraqa_purchases.php هو الوحيد اللي بيكتب فيهم.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `ashraqa_purchases` (
  `sstt_id`         smallint(6)   NOT NULL COMMENT '113 شراء · 115 مرتجع شراء',
  `brch_id`         int(11)       NOT NULL,
  `fy_no`           int(11)       NOT NULL,
  `add_doc`         int(11)       NOT NULL COMMENT 'رقم الفاتورة عندهم',
  `doc_date`        date          NOT NULL,
  `supplier_id`     int(11)       DEFAULT NULL COMMENT '8 = «الفروع أستاذ محمد عصام»',
  `supplier_name`   varchar(255)  DEFAULT NULL,
  `net_amount`      decimal(16,3) NOT NULL DEFAULT 0.000 COMMENT 'Net_invoice',
  `total_amount`    decimal(16,3) NOT NULL DEFAULT 0.000 COMMENT 'Total_Invoice — قبل الخصم',
  `cancelled`       tinyint(1)    NOT NULL DEFAULT 0 COMMENT 'Cancel_Doc',
  `notes`           varchar(500)  DEFAULT NULL COMMENT 'NOTS — فيها اسم الفرع في المرتجعات',
  `supplier_inv_no` varchar(100)  DEFAULT NULL,
  `internal_no`     varchar(100)  DEFAULT NULL,
  `last_modify`     datetime      DEFAULT NULL COMMENT 'LAST_MODIFY_DATE عندهم',
  `synced_at`       timestamp     NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`sstt_id`, `brch_id`, `fy_no`, `add_doc`),
  KEY `idx_ap_date` (`doc_date`),
  KEY `idx_ap_supplier` (`supplier_id`, `sstt_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ashraqa_purchase_lines` (
  `sstt_id`     smallint(6)   NOT NULL,
  `brch_id`     int(11)       NOT NULL,
  `fy_no`       int(11)       NOT NULL,
  `add_doc`     int(11)       NOT NULL,
  `line_no`     int(11)       NOT NULL COMMENT 'ترتيب السطر جوه الفاتورة (ROW_NUMBER عندهم)',
  `item_id`     int(11)       DEFAULT NULL COMMENT 'Item.ID عندهم — مالوش علاقة بـitem_id عندنا',
  `code`        varchar(64)   DEFAULT NULL COMMENT 'CODE في السطر — ساعات كود داخلي قصير مش باركود',
  `barcodes`    varchar(500)  DEFAULT NULL COMMENT 'كل باركودات الصنف مفصولة بـ| — الرابط الوحيد مع أصنافنا',
  `item_name`   varchar(255)  DEFAULT NULL,
  `unit_id`     int(11)       DEFAULT NULL,
  `unit_q`      decimal(12,3) NOT NULL DEFAULT 1.000 COMMENT 'معامل الوحدة — الكمية بالقطعة = quantity × unit_q',
  `quantity`    decimal(16,3) NOT NULL DEFAULT 0.000,
  `unit_price`  decimal(16,3) NOT NULL DEFAULT 0.000,
  `line_total`  decimal(16,3) NOT NULL DEFAULT 0.000 COMMENT 'TOTVALUE',
  PRIMARY KEY (`sstt_id`, `brch_id`, `fy_no`, `add_doc`, `line_no`),
  KEY `idx_apl_item` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
