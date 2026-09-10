-- =====================================================================
-- upgrade_branch_docs.sql — جداول فواتير وبنود الفروع (المرحلة أ)
-- =====================================================================
--
-- ليه agent_key هو أول عمود في المفتاح الأساسي؟
-- -----------------------------------------------------------
-- STH_ID ده رقم تسلسلي محلي جوّه قاعدة بيانات كل فرع (PowerTech SQL Server).
-- يعني الفاتورة رقم 1500 في فرع بونجور (Anzmah_Bonjour) والفاتورة رقم 1500
-- في فرع دجلة (Anzmah_Degla) أو الزهراء هما فاتورتين مختلفتين تماماً
-- ببيانات وبنود ومبالغ مختلفة وبتاريخ مختلف.
--
-- جداول الإدارة القديمة (hq_invoices, hq_invoice_lines, hq_unposted, hq_unposted_lines)
-- مفاتيحها الأساسية معتمدة على (sth_id, suffix) بدون عمود فرع لأنها بتسحب
-- مشتريات الإدارة بس. لو حطينا فواتير الفروع فيها فرع هيمسح فواتير فرع تاني
-- بصمت بدون ما حد يحس — وده خراب بيوت وفلوس حقيقية.
--
-- وعشان نتجنب المخاطرة بتعديل مفاتيح جداول hq_* اللي شغالة حالياً في الإنتاج
-- وفيها داتا مشتريات حية، الحل الآمن تماماً بمخاطرة صفر هو:
-- عمل 4 جداول جديدة خاصة بالفروع تبدأ بـ br_ ويكون agent_key هو أول عمود
-- في المفتاح الأساسي لكل جدول منها لعزل فواتير كل فرع بالكامل.
--
-- التشغيل: آمن للتكرار (IF NOT EXISTS).
-- الملف ده محلي ما بيترفعش على الدومين.
-- =====================================================================

-- ---------------------------------------------------------------------
-- (١) br_invoices — رؤوس فواتير الفروع
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `br_invoices` (
  `agent_key` varchar(32) NOT NULL,
  `sth_id` bigint(20) NOT NULL COMMENT 'STH_ID — مفتاح الفاتورة',
  `suffix` varchar(8) NOT NULL COMMENT 'جدول الشهر: 26_06 ...',
  `doc_no` varchar(40) DEFAULT NULL COMMENT 'STH_DOC_NO — الرقم الظاهر',
  `doc_type` smallint(6) DEFAULT NULL COMMENT 'SSTT_ID: 2=شراء، 6=مردود',
  `supplier_id` int(11) DEFAULT NULL,
  `supplier_code` varchar(40) DEFAULT NULL,
  `supplier_name` varchar(255) DEFAULT NULL,
  `net_amount` decimal(16,3) NOT NULL DEFAULT 0.000,
  `total_amount` decimal(16,3) NOT NULL DEFAULT 0.000,
  `invoice_date` datetime DEFAULT NULL,
  `company_branch` int(11) DEFAULT NULL COMMENT 'BRCH_ID في نظام الشركة',
  `last_modify` datetime DEFAULT NULL COMMENT 'LAST_MODIFY_DATE — للمزامنة الذكية',
  `synced_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`agent_key`, `sth_id`, `suffix`),
  KEY `idx_bri_agent_date` (`agent_key`, `invoice_date`),
  KEY `idx_bri_doctype` (`doc_type`),
  KEY `idx_bri_doc_no` (`doc_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- (٢) br_invoice_lines — بنود فواتير الفروع
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `br_invoice_lines` (
  `agent_key` varchar(32) NOT NULL,
  `sth_id` bigint(20) NOT NULL,
  `suffix` varchar(8) NOT NULL DEFAULT '' COMMENT 'جدول الشهر — جزء من المفتاح',
  `line_no` int(11) NOT NULL COMMENT 'STL_LINE_NO',
  `item_id` int(11) DEFAULT NULL,
  `item_code` varchar(64) DEFAULT NULL COMMENT 'الباركود من ITEM_CODES',
  `item_name` varchar(255) DEFAULT NULL,
  `quantity` decimal(16,3) NOT NULL DEFAULT 0.000,
  `unit_price` decimal(16,3) NOT NULL DEFAULT 0.000 COMMENT 'STL_ITEM_PRICE',
  `cost_price` decimal(16,3) NOT NULL DEFAULT 0.000 COMMENT 'STL_ITEM_COST',
  PRIMARY KEY (`agent_key`, `sth_id`, `suffix`, `line_no`),
  KEY `idx_brl_item` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- (٣) br_unposted — فواتير الفروع غير المرحّلة
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `br_unposted` (
  `agent_key` varchar(32) NOT NULL,
  `sth_id` bigint(20) NOT NULL,
  `doc_no` varchar(40) DEFAULT NULL,
  `doc_type` smallint(6) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `supplier_code` varchar(40) DEFAULT NULL,
  `supplier_name` varchar(255) DEFAULT NULL,
  `net_amount` decimal(16,3) NOT NULL DEFAULT 0.000,
  `total_amount` decimal(16,3) NOT NULL DEFAULT 0.000,
  `invoice_date` datetime DEFAULT NULL,
  `company_branch` int(11) DEFAULT NULL,
  `remark` varchar(255) DEFAULT NULL,
  `synced_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `batch` varchar(20) DEFAULT NULL COMMENT 'ختم الجولة — نفس الفكرة',
  PRIMARY KEY (`agent_key`, `sth_id`),
  KEY `idx_bru_agent` (`agent_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- (٤) br_unposted_lines — بنود فواتير الفروع غير المرحّلة
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `br_unposted_lines` (
  `agent_key` varchar(32) NOT NULL,
  `sth_id` bigint(20) NOT NULL,
  `line_no` int(11) NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `item_code` varchar(64) DEFAULT NULL,
  `item_name` varchar(255) DEFAULT NULL,
  `quantity` decimal(16,3) NOT NULL DEFAULT 0.000,
  `unit_price` decimal(16,3) NOT NULL DEFAULT 0.000,
  `cost_price` decimal(16,3) NOT NULL DEFAULT 0.000,
  PRIMARY KEY (`agent_key`, `sth_id`, `line_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
