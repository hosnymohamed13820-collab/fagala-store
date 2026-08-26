-- =====================================================================
--  schema_ashraqa.sql — هيكل قاعدة بيانات فرع اشراقة
--  نظام الفجالة ستور — ٢٧ أغسطس ٢٠٢٦
-- =====================================================================
--
--  ٩٧ جدول + فيو واحدة. **مفيش ولا صف بيانات** — قاعدة فاضية تماماً.
--
--  🟢 مصدر الملف ده: تصدير حقيقي من قاعدة البيانات الشغّالة
--     (phpMyAdmin → Export → Structure only، MariaDB 11.8.8).
--     يعني ده **مش تخمين** — ده الهيكل الحقيقي حرف بحرف.
--
--  ⚠️ اللي اتغيّر عن التصدير الأصلي، حاجة واحدة بس:
--     الفيو `hq_branch_balances_nz` كان مكتوب فيها
--     DEFINER='u834909335_hosni'@'127.0.0.1'
--     واتشالت، لأن المستخدم ده مش موجود على قاعدة اشراقة والاستيراد
--     كان هيفشل بـ«The user specified as a definer does not exist».
--     من غير DEFINER، الفيو بتتعمل باسم المستخدم اللي بيستورد — وده الصح.
--
--  التشغيل:
--     ١) اعمل قاعدة بيانات جديدة فاضية لاشراقة من لوحة هوستنجر
--     ٢) phpMyAdmin → اختار القاعدة الجديدة → Import → الملف ده
--     ٣) انسخ config.sample.php لـ config.php وحط فيها بيانات القاعدة دي
--
--  🔴 القاعدة دي مالهاش أي علاقة بقاعدة الفجالة — بيانات منفصلة تماماً.
--     متوصّلش الاتنين ببعض ولا تنسخ صفوف بينهم.
-- =====================================================================

-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- مضيف: 127.0.0.1:3306
-- وقت الجيل: 26 أغسطس 2026 الساعة 21:34
-- إصدار الخادم: 11.8.8-MariaDB-log
-- نسخة PHP: 7.2.34

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- قاعدة بيانات: `u834909335_fagala`
--

-- --------------------------------------------------------

--
-- بنية الجدول `accounts`
--

CREATE TABLE `accounts` (
  `id` int(10) UNSIGNED NOT NULL,
  `parent_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'الحساب الأب — NULL يعني حساب رئيسي',
  `name` varchar(150) NOT NULL,
  `type` enum('asset','liability','revenue','expense') NOT NULL DEFAULT 'expense' COMMENT 'أصل / التزام / إيراد / مصروف',
  `is_custody` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'يظهر في شاشة العهدة كتصنيف مصروف',
  `dynamic_src` enum('employees','suppliers') DEFAULT NULL COMMENT 'الأبناء بيتجابوا لحظيًا من جدول تاني بدل صفوف ثابتة',
  `src_table` varchar(20) DEFAULT NULL COMMENT 'الصف ده اتولّد من أنهي جدول — employees / suppliers',
  `src_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'رقم الصف في الجدول الأصلي',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` smallint(5) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `agent_status`
--

CREATE TABLE `agent_status` (
  `agent_key` varchar(32) NOT NULL,
  `label` varchar(64) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_beat_at` datetime DEFAULT NULL,
  `last_data_at` datetime DEFAULT NULL,
  `last_rows` int(11) NOT NULL DEFAULT 0,
  `last_error` varchar(255) DEFAULT NULL,
  `last_error_at` datetime DEFAULT NULL,
  `agent_version` varchar(24) DEFAULT NULL,
  `host_name` varchar(64) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `ashraqa_balances`
--

CREATE TABLE `ashraqa_balances` (
  `barcode` varchar(64) NOT NULL COMMENT 'الباركود — مفتاح الربط مع أصنافنا',
  `remote_item_id` int(11) NOT NULL COMMENT 'رقم الصنف في نظامهم (Item.ID)',
  `item_name` varchar(255) DEFAULT NULL,
  `balance` decimal(14,3) NOT NULL DEFAULT 0.000 COMMENT 'ممكن يبقى سالب — بنعرضه زي ما هو',
  `store_id` int(11) NOT NULL DEFAULT 1 COMMENT 'المخزن عندهم (1 = المخزن الرئيسي)',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `ashraqa_sync_log`
--

CREATE TABLE `ashraqa_sync_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `synced_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `rows_count` int(11) NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'ok',
  `note` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `attendance`
--

CREATE TABLE `attendance` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `att_date` date NOT NULL,
  `check_in` time DEFAULT NULL,
  `check_in_lat` decimal(10,7) DEFAULT NULL,
  `check_in_lng` decimal(10,7) DEFAULT NULL,
  `check_in_dist` int(10) UNSIGNED DEFAULT NULL,
  `check_in_outside` tinyint(1) NOT NULL DEFAULT 0,
  `check_out` time DEFAULT NULL,
  `check_out_lat` decimal(10,7) DEFAULT NULL,
  `check_out_lng` decimal(10,7) DEFAULT NULL,
  `check_out_dist` int(10) UNSIGNED DEFAULT NULL,
  `check_out_outside` tinyint(1) NOT NULL DEFAULT 0,
  `work_hours` decimal(5,2) NOT NULL DEFAULT 0.00,
  `status` enum('present','absent','leave','holiday') NOT NULL DEFAULT 'present',
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `attendance_bak_20260712`
--

CREATE TABLE `attendance_bak_20260712` (
  `id` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `att_date` date NOT NULL,
  `check_in` time DEFAULT NULL,
  `check_in_lat` decimal(10,7) DEFAULT NULL,
  `check_in_lng` decimal(10,7) DEFAULT NULL,
  `check_in_dist` int(10) UNSIGNED DEFAULT NULL,
  `check_in_outside` tinyint(1) NOT NULL DEFAULT 0,
  `check_out` time DEFAULT NULL,
  `check_out_lat` decimal(10,7) DEFAULT NULL,
  `check_out_lng` decimal(10,7) DEFAULT NULL,
  `check_out_dist` int(10) UNSIGNED DEFAULT NULL,
  `check_out_outside` tinyint(1) NOT NULL DEFAULT 0,
  `work_hours` decimal(5,2) NOT NULL DEFAULT 0.00,
  `status` enum('present','absent','leave','holiday') NOT NULL DEFAULT 'present',
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `attendance_requests`
--

CREATE TABLE `attendance_requests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `req_date` date NOT NULL,
  `requested_in` time DEFAULT NULL,
  `requested_out` time DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `attendance_requests_bak_20260712`
--

CREATE TABLE `attendance_requests_bak_20260712` (
  `id` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `req_date` date NOT NULL,
  `requested_in` time DEFAULT NULL,
  `requested_out` time DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `book_catalog`
--

CREATE TABLE `book_catalog` (
  `id` int(11) NOT NULL,
  `code` varchar(64) NOT NULL,
  `name` varchar(255) NOT NULL,
  `publisher` varchar(120) DEFAULT NULL,
  `term` varchar(60) DEFAULT NULL,
  `cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `needed` decimal(12,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `book_order_lines`
--

CREATE TABLE `book_order_lines` (
  `book_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `req_qty` decimal(12,2) NOT NULL DEFAULT 0.00,
  `supplier_qty` decimal(12,2) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `book_order_lines_bak_20260809`
--

CREATE TABLE `book_order_lines_bak_20260809` (
  `book_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `req_qty` decimal(12,2) NOT NULL DEFAULT 0.00,
  `supplier_qty` decimal(12,2) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `book_reservations`
--

CREATE TABLE `book_reservations` (
  `id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `customer_name` varchar(150) NOT NULL,
  `customer_phone` varchar(40) DEFAULT NULL,
  `reserved_by` varchar(150) DEFAULT NULL,
  `deposit` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('open','delivered','cancelled') NOT NULL DEFAULT 'open',
  `notes` varchar(500) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `book_reservation_lines`
--

CREATE TABLE `book_reservation_lines` (
  `id` int(11) NOT NULL,
  `reservation_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL DEFAULT 0,
  `code` varchar(64) NOT NULL,
  `item_name` varchar(255) DEFAULT NULL,
  `qty` decimal(12,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `book_suppliers`
--

CREATE TABLE `book_suppliers` (
  `id` int(11) NOT NULL,
  `name` varchar(120) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `branches`
--

CREATE TABLE `branches` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `sto_id` smallint(5) UNSIGNED DEFAULT NULL COMMENT 'رقم المخزن المقابل في نظام الشركة (STORES.STO_ID)',
  `in_receiving` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'يظهر كعمود توزيع في الاستلامات',
  `has_finance` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'له عهدة ويظهر في الخزينة والداشبورد',
  `address` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `geofence_radius` int(10) UNSIGNED NOT NULL DEFAULT 50,
  `geofence_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `phone` varchar(30) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `ext_balance_src` varchar(20) DEFAULT NULL COMMENT 'مصدر خارجي للأرصدة — ashraqa يعني من جدول ashraqa_balances'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `branch_custody`
--

CREATE TABLE `branch_custody` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` int(10) UNSIGNED NOT NULL,
  `custody_treasury_id` int(10) UNSIGNED NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date DEFAULT NULL,
  `opening_balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_expenses` decimal(14,2) NOT NULL DEFAULT 0.00,
  `closing_balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `counted_cash` decimal(14,2) DEFAULT NULL,
  `difference` decimal(14,2) DEFAULT NULL,
  `settled_amount` decimal(14,2) DEFAULT NULL,
  `status` enum('open','closed') NOT NULL DEFAULT 'open',
  `closed_by` int(10) UNSIGNED DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `custody_cash`
--

CREATE TABLE `custody_cash` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `custody_id` bigint(20) UNSIGNED NOT NULL,
  `denomination` decimal(8,2) NOT NULL,
  `count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `subtotal` decimal(14,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `custody_deficits`
--

CREATE TABLE `custody_deficits` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `custody_id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED DEFAULT NULL,
  `branch_id` int(10) UNSIGNED NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `status` enum('pending','recovered','waived') NOT NULL DEFAULT 'pending',
  `recovered_in_salary_id` bigint(20) UNSIGNED DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `custody_lines`
--

CREATE TABLE `custody_lines` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `custody_id` bigint(20) UNSIGNED NOT NULL,
  `expense_date` date NOT NULL,
  `description` varchar(255) NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `entry_code_id` int(10) UNSIGNED DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `disbursement_orders`
--

CREATE TABLE `disbursement_orders` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` int(10) UNSIGNED NOT NULL,
  `custody_treasury_id` int(10) UNSIGNED NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `order_date` date NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `employees`
--

CREATE TABLE `employees` (
  `id` int(10) UNSIGNED NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `branch_id` int(10) UNSIGNED DEFAULT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `national_id` varchar(20) DEFAULT NULL,
  `job_title` varchar(80) DEFAULT NULL,
  `base_salary` decimal(12,2) NOT NULL DEFAULT 0.00,
  `expected_in` time DEFAULT '09:00:00',
  `expected_out` time DEFAULT '17:00:00',
  `allow_anywhere` tinyint(1) NOT NULL DEFAULT 0,
  `hire_date` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `employee_advances`
--

CREATE TABLE `employee_advances` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `advance_date` date NOT NULL,
  `status` enum('pending','deducted') NOT NULL DEFAULT 'pending',
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `employee_leaves`
--

CREATE TABLE `employee_leaves` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `leave_type` enum('annual','sick','unpaid','other') NOT NULL DEFAULT 'annual',
  `days` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `employee_penalties`
--

CREATE TABLE `employee_penalties` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `penalty_date` date NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `status` enum('pending','deducted') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `entry_codes`
--

CREATE TABLE `entry_codes` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `code` varchar(40) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `expense_categories`
--

CREATE TABLE `expense_categories` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `hq_balances`
--

CREATE TABLE `hq_balances` (
  `item_id` int(11) NOT NULL,
  `sto_id` int(11) NOT NULL,
  `balance` decimal(16,3) NOT NULL DEFAULT 0.000 COMMENT 'CSI_CURRENT_BALANCE',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `batch` varchar(20) DEFAULT NULL COMMENT 'ختم الجولة — الصفوف اللي ختمها قديم بتتمسح في الآخر'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `hq_branch_balances`
--

CREATE TABLE `hq_branch_balances` (
  `agent_key` varchar(32) NOT NULL,
  `item_id` int(11) NOT NULL,
  `sto_id` int(11) NOT NULL,
  `balance` decimal(16,3) NOT NULL DEFAULT 0.000,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `hq_branch_balances_nz`
-- (See below for the actual view)
--
CREATE TABLE `hq_branch_balances_nz` (
`item_id` int(11)
,`sto_id` int(11)
,`balance` decimal(16,3)
,`agent_key` varchar(32)
,`updated_at` datetime
);

-- --------------------------------------------------------

--
-- بنية الجدول `hq_cat_names`
--

CREATE TABLE `hq_cat_names` (
  `is1_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `hq_codes`
--

CREATE TABLE `hq_codes` (
  `code` varchar(64) NOT NULL COMMENT 'IC_CODE — الباركود',
  `item_id` int(11) NOT NULL COMMENT 'ITEM_ID — الرابط بين الأكواد',
  `name` varchar(255) DEFAULT NULL,
  `code_type` tinyint(4) NOT NULL DEFAULT 1 COMMENT '0=أساسي، 1=فرعي',
  `unit_factor` decimal(12,3) NOT NULL DEFAULT 1.000 COMMENT 'معامل الوحدة',
  `price` decimal(14,2) DEFAULT NULL COMMENT 'CIP_PRICE',
  `cost` decimal(14,3) DEFAULT NULL COMMENT 'CI_AVR_COST',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `hq_invoices`
--

CREATE TABLE `hq_invoices` (
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
  `synced_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `hq_invoice_lines`
--

CREATE TABLE `hq_invoice_lines` (
  `sth_id` bigint(20) NOT NULL,
  `suffix` varchar(8) NOT NULL DEFAULT '' COMMENT 'جدول الشهر — جزء من المفتاح',
  `line_no` int(11) NOT NULL COMMENT 'STL_LINE_NO',
  `item_id` int(11) DEFAULT NULL,
  `item_code` varchar(64) DEFAULT NULL COMMENT 'الباركود من ITEM_CODES',
  `item_name` varchar(255) DEFAULT NULL,
  `quantity` decimal(16,3) NOT NULL DEFAULT 0.000,
  `unit_price` decimal(16,3) NOT NULL DEFAULT 0.000 COMMENT 'STL_ITEM_PRICE',
  `cost_price` decimal(16,3) NOT NULL DEFAULT 0.000 COMMENT 'STL_ITEM_COST'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `hq_invoice_sync_state`
--

CREATE TABLE `hq_invoice_sync_state` (
  `suffix` varchar(8) NOT NULL COMMENT 'جدول الشهر',
  `last_modify` varchar(30) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `hq_items`
--

CREATE TABLE `hq_items` (
  `barcode` varchar(64) NOT NULL COMMENT 'IC_CODE — الباركود',
  `item_id` int(11) NOT NULL COMMENT 'ITEM_ID في نظام الشركة',
  `name` varchar(255) DEFAULT NULL,
  `sale_price` decimal(14,2) NOT NULL DEFAULT 0.00 COMMENT 'CIP_PRICE',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `hq_item_cats`
--

CREATE TABLE `hq_item_cats` (
  `item_id` int(11) NOT NULL,
  `is1_id` int(11) NOT NULL DEFAULT 0,
  `item_name` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `hq_purchased_items`
--

CREATE TABLE `hq_purchased_items` (
  `item_id` int(11) NOT NULL,
  `batch` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `hq_stores`
--

CREATE TABLE `hq_stores` (
  `sto_id` int(11) NOT NULL,
  `name` varchar(150) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `hq_sync_log`
--

CREATE TABLE `hq_sync_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `kind` varchar(20) NOT NULL COMMENT 'items / stores / suppliers / invoices',
  `synced_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `rows_count` int(11) NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'ok',
  `note` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `hq_unposted`
--

CREATE TABLE `hq_unposted` (
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
  `batch` varchar(20) DEFAULT NULL COMMENT 'ختم الجولة — نفس الفكرة'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `hq_unposted_lines`
--

CREATE TABLE `hq_unposted_lines` (
  `sth_id` bigint(20) NOT NULL,
  `line_no` int(11) NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `item_code` varchar(64) DEFAULT NULL,
  `item_name` varchar(255) DEFAULT NULL,
  `quantity` decimal(16,3) NOT NULL DEFAULT 0.000,
  `unit_price` decimal(16,3) NOT NULL DEFAULT 0.000,
  `cost_price` decimal(16,3) NOT NULL DEFAULT 0.000
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `invoice_branch_exports`
--

CREATE TABLE `invoice_branch_exports` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `distribution_id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` int(10) UNSIGNED NOT NULL,
  `exported_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `exported_by` int(10) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `invoice_distribution`
--

CREATE TABLE `invoice_distribution` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `invoice_no` varchar(60) DEFAULT NULL,
  `supplier_id` int(10) UNSIGNED DEFAULT NULL,
  `dist_date` date NOT NULL,
  `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `notes` varchar(255) DEFAULT NULL,
  `user_notes` text DEFAULT NULL COMMENT 'ملاحظات المستخدم — منفصلة عن notes اللي بيتكتب تلقائي',
  `notes_at` timestamp NULL DEFAULT NULL COMMENT 'آخر تعديل للملاحظات',
  `notes_by` int(10) UNSIGNED DEFAULT NULL COMMENT 'مين آخر واحد عدّل الملاحظات',
  `status` enum('new','split','done') NOT NULL DEFAULT 'new',
  `status_manual` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'الحالة اتغيرت يدوي — التلقائي ما يدوسش عليها',
  `status_by` int(10) UNSIGNED DEFAULT NULL COMMENT 'مين آخر واحد غيّر الحالة يدوي',
  `status_at` timestamp NULL DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `invoice_distribution_lines`
--

CREATE TABLE `invoice_distribution_lines` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `distribution_id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` int(10) UNSIGNED NOT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `notes` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `invoice_groups`
--

CREATE TABLE `invoice_groups` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(150) DEFAULT NULL COMMENT 'اسم اختياري للمجموعة',
  `status` enum('open','closed') NOT NULL DEFAULT 'open',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `user_notes` text DEFAULT NULL COMMENT 'ملاحظات حرة بيكتبها المستخدم — بتبان على كارت المجموعة',
  `group_type` enum('full','items') NOT NULL DEFAULT 'full' COMMENT 'full = فواتير كاملة (السلوك القديم) · items = أصناف محددة'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `invoice_group_items`
--

CREATE TABLE `invoice_group_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `group_id` bigint(20) UNSIGNED NOT NULL,
  `sth_id` bigint(20) NOT NULL COMMENT 'معرّف الفاتورة في نظام الشركة',
  `suffix` varchar(10) NOT NULL DEFAULT '' COMMENT 'YY_MM = غير محفوظة، أو لاحقة الشهر',
  `invoice_no` varchar(60) DEFAULT NULL,
  `supplier_name` varchar(150) DEFAULT NULL,
  `invoice_date` datetime DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `invoice_group_lines`
--

CREATE TABLE `invoice_group_lines` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `group_id` bigint(20) UNSIGNED NOT NULL,
  `sth_id` bigint(20) NOT NULL COMMENT 'الفاتورة اللي الصنف اتاخد منها',
  `suffix` varchar(10) NOT NULL DEFAULT '' COMMENT 'YY_MM = غير محفوظة، أو لاحقة الشهر',
  `item_code` varchar(60) NOT NULL,
  `added_by` int(10) UNSIGNED DEFAULT NULL,
  `added_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `invoice_item_flags`
--

CREATE TABLE `invoice_item_flags` (
  `distribution_id` bigint(20) UNSIGNED NOT NULL COMMENT 'invoice_distribution.id',
  `item_code` varchar(60) NOT NULL COMMENT 'كود الصنف (نفس اللي في invoice_distribution_lines.notes)',
  `highlight` varchar(10) NOT NULL DEFAULT '' COMMENT 'red / green / yellow / orange — فاضي = بدون تمييز',
  `done_actual` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'تم التوزيع الفعلي — علامة بصرية، مالهاش أي أثر على الكميات ولا الحالة',
  `user_notes` text DEFAULT NULL COMMENT 'ملاحظة حرة على الصنف في الفاتورة دي',
  `updated_by` int(10) UNSIGNED DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `invoice_item_prices`
--

CREATE TABLE `invoice_item_prices` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `distribution_id` bigint(20) UNSIGNED NOT NULL,
  `item_code` varchar(64) NOT NULL,
  `sale_price` decimal(14,5) NOT NULL DEFAULT 0.00000,
  `source_price` decimal(14,5) DEFAULT NULL COMMENT 'السعر الأصلي من السيستم وقت أول جلب',
  `fetched_at` timestamp NULL DEFAULT NULL COMMENT 'وقت آخر جلب للسعر من سيستم الشركة',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(10) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `item_export_flags`
--

CREATE TABLE `item_export_flags` (
  `item_code` varchar(60) NOT NULL,
  `skip_export` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = مابينزلش في ملف الفرع نهائيًا',
  `updated_by` int(10) UNSIGNED DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `item_price_drafts`
--

CREATE TABLE `item_price_drafts` (
  `item_code` varchar(60) NOT NULL,
  `item_id` int(11) NOT NULL DEFAULT 0,
  `item_name` varchar(255) DEFAULT NULL,
  `cost_price` decimal(14,5) NOT NULL DEFAULT 0.00000,
  `sale_price` decimal(14,5) NOT NULL DEFAULT 0.00000,
  `markup_pct` decimal(9,3) DEFAULT NULL,
  `added_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `journal_entries`
--

CREATE TABLE `journal_entries` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `treasury_id` int(10) UNSIGNED NOT NULL,
  `entry_date` date NOT NULL,
  `description` varchar(255) NOT NULL,
  `account_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'الحساب من شجرة الحسابات',
  `debit` decimal(14,2) NOT NULL DEFAULT 0.00,
  `credit` decimal(14,2) NOT NULL DEFAULT 0.00,
  `running_balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `ref_type` varchar(40) DEFAULT NULL,
  `ref_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL COMMENT 'ملاحظات المحاسب — نص حر طويل، بيبان آخر عمود في كشف الحساب والتقارير',
  `edited_at` timestamp NULL DEFAULT NULL COMMENT 'آخر تعديل على (القيد/البيان/الملاحظات) بعد الحفظ — NULL = ما اتعدلش',
  `edited_by` int(10) UNSIGNED DEFAULT NULL COMMENT 'users.id بتاع اللي عدّل'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `login_log`
--

CREATE TABLE `login_log` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `username` varchar(60) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `market_fetch_log`
--

CREATE TABLE `market_fetch_log` (
  `id` int(11) NOT NULL,
  `products` int(11) NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'ok',
  `note` varchar(255) DEFAULT NULL,
  `fetched_by` int(11) DEFAULT NULL,
  `fetched_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `market_ignored`
--

CREATE TABLE `market_ignored` (
  `item_id` int(11) NOT NULL,
  `ignored_by` int(11) DEFAULT NULL,
  `ignored_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `market_links`
--

CREATE TABLE `market_links` (
  `item_id` int(11) NOT NULL,
  `market_id` int(11) NOT NULL,
  `score` decimal(5,3) DEFAULT NULL,
  `confirmed_by` int(11) DEFAULT NULL,
  `confirmed_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `market_products`
--

CREATE TABLE `market_products` (
  `market_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `name_norm` varchar(255) NOT NULL DEFAULT '',
  `price` decimal(14,2) NOT NULL DEFAULT 0.00,
  `permalink` varchar(500) DEFAULT NULL,
  `in_stock` tinyint(1) NOT NULL DEFAULT 1,
  `fetched_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `notifications`
--

CREATE TABLE `notifications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `type` varchar(40) NOT NULL DEFAULT 'task',
  `title` varchar(200) NOT NULL,
  `body` varchar(255) DEFAULT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `pos_branch_map`
--

CREATE TABLE `pos_branch_map` (
  `branch_id` int(11) NOT NULL COMMENT 'BRCH_ID في نظام الشركة',
  `branch_name` varchar(100) NOT NULL,
  `bridge_url` varchar(255) NOT NULL COMMENT 'رابط bridge.php بتاع الفرع',
  `sqlsrv_db` varchar(100) NOT NULL COMMENT 'اسم قاعدة الفرع',
  `sto_id` int(11) NOT NULL COMMENT 'المخزن',
  `com_id` int(11) NOT NULL DEFAULT 1 COMMENT 'الشركة',
  `safe_id` int(11) NOT NULL COMMENT 'الخزنة',
  `create_hw_id` bigint(20) NOT NULL COMMENT 'معرّف الجهاز',
  `create_user_id` int(11) NOT NULL COMMENT 'المستخدم في نظام الشركة',
  `sstt_id_sale` int(11) NOT NULL DEFAULT 4 COMMENT 'نوع الحركة: بيع',
  `astt_id_sale` int(11) NOT NULL DEFAULT 10 COMMENT 'نوع القيد: بيع',
  `ac_cash` int(11) NOT NULL DEFAULT 28 COMMENT 'حساب الخزنة',
  `ac_sales` int(11) NOT NULL DEFAULT 441 COMMENT 'حساب المبيعات',
  `ac_cogs` int(11) NOT NULL DEFAULT 554 COMMENT 'تكلفة المبيعات',
  `ac_inventory` int(11) NOT NULL DEFAULT 39 COMMENT 'حساب المخزون',
  `is_active` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0 = الترحيل موقوف (الافتراضي — أمان)',
  `emit_repl` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `pos_invoices`
--

CREATE TABLE `pos_invoices` (
  `id` int(11) NOT NULL,
  `shift_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `sold_at` datetime NOT NULL,
  `total_value` decimal(14,2) NOT NULL DEFAULT 0.00 COMMENT 'الإجمالي قبل الخصم',
  `head_disc` decimal(14,2) NOT NULL DEFAULT 0.00 COMMENT 'خصم الفاتورة',
  `lines_disc` decimal(14,2) NOT NULL DEFAULT 0.00 COMMENT 'مجموع خصم الأصناف',
  `net_value` decimal(14,2) NOT NULL DEFAULT 0.00 COMMENT 'الصافي المدفوع',
  `items_count` int(11) NOT NULL DEFAULT 0,
  `status` enum('open','completed','migrated','failed') NOT NULL DEFAULT 'open',
  `sql_sth_id` bigint(20) DEFAULT NULL COMMENT 'رقم الفاتورة في نظام الشركة',
  `sql_sth_id_ref` bigint(20) DEFAULT NULL,
  `sql_ath_id` bigint(20) DEFAULT NULL COMMENT 'رقم القيد',
  `sql_doc_no` varchar(20) DEFAULT NULL,
  `sql_ps_id` bigint(20) DEFAULT NULL COMMENT 'وردية الكاشير اللي دخلت عليها',
  `migrated_at` datetime DEFAULT NULL,
  `migrate_error` text DEFAULT NULL COMMENT 'رسالة الخطأ لو الترحيل فشل',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `pos_invoice_lines`
--

CREATE TABLE `pos_invoice_lines` (
  `id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `line_no` int(11) NOT NULL COMMENT 'رقم السطر (1، 2، 3...)',
  `item_id` bigint(20) NOT NULL COMMENT 'ITEM_ID في نظام الشركة',
  `ic_id` bigint(20) DEFAULT NULL COMMENT 'كود/باركود الصنف',
  `item_name` varchar(255) NOT NULL,
  `unit_id` int(11) NOT NULL DEFAULT 1,
  `unit_factor` decimal(14,5) NOT NULL DEFAULT 1.00000,
  `qty` decimal(14,3) NOT NULL,
  `price` decimal(14,2) NOT NULL,
  `disc_value` decimal(14,2) NOT NULL DEFAULT 0.00,
  `disc_perc` decimal(9,3) NOT NULL DEFAULT 0.000,
  `total` decimal(14,2) NOT NULL COMMENT 'qty × price − disc',
  `cost` decimal(14,5) NOT NULL DEFAULT 0.00000 COMMENT 'csi_avr_cost وقت البيع — لقيد تكلفة المبيعات',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `pos_migration_log`
--

CREATE TABLE `pos_migration_log` (
  `id` int(11) NOT NULL,
  `shift_id` int(11) NOT NULL,
  `invoice_id` int(11) DEFAULT NULL COMMENT 'NULL = عملية على مستوى الوردية كلها',
  `branch_id` int(11) NOT NULL,
  `mode` enum('dry_run','live') NOT NULL,
  `result` enum('success','failed','skipped') NOT NULL,
  `sth_id` bigint(20) DEFAULT NULL,
  `sth_id_ref` bigint(20) DEFAULT NULL,
  `ath_id` bigint(20) DEFAULT NULL,
  `stil_id` bigint(20) DEFAULT NULL,
  `atg_id` bigint(20) DEFAULT NULL,
  `doc_no` varchar(20) DEFAULT NULL,
  `ps_id` bigint(20) DEFAULT NULL,
  `sql_dump` longtext DEFAULT NULL COMMENT 'الـSQL كامل — للمراجعة والتدقيق',
  `error_msg` text DEFAULT NULL,
  `duration_ms` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `pos_shifts`
--

CREATE TABLE `pos_shifts` (
  `id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL COMMENT 'الفرع (يطابق BRCH_ID في SQL Server)',
  `user_id` int(11) NOT NULL COMMENT 'الموظف اللي فاتح الوردية',
  `opened_at` datetime NOT NULL,
  `closed_at` datetime DEFAULT NULL,
  `status` enum('open','closed','migrated') NOT NULL DEFAULT 'open',
  `total_sales` decimal(14,2) NOT NULL DEFAULT 0.00,
  `invoice_count` int(11) NOT NULL DEFAULT 0,
  `migrated_at` datetime DEFAULT NULL COMMENT 'وقت الترحيل لنظام الشركة',
  `migrated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `reconciliations`
--

CREATE TABLE `reconciliations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `treasury_id` int(10) UNSIGNED NOT NULL,
  `book_balance` decimal(14,2) NOT NULL,
  `counted_balance` decimal(14,2) NOT NULL,
  `difference` decimal(14,2) NOT NULL,
  `recon_date` date NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `report_archive`
--

CREATE TABLE `report_archive` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `report_type` varchar(60) NOT NULL,
  `ref_id` bigint(20) UNSIGNED DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `generated_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `roles`
--

CREATE TABLE `roles` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(50) NOT NULL,
  `name_ar` varchar(50) NOT NULL,
  `is_owner` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `salaries`
--

CREATE TABLE `salaries` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `salary_month` char(7) NOT NULL,
  `base_salary` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_advances` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_penalties` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_deficits` decimal(12,2) NOT NULL DEFAULT 0.00,
  `net_salary` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','paid') NOT NULL DEFAULT 'draft',
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `scan_agents`
--

CREATE TABLE `scan_agents` (
  `session_code` varchar(32) NOT NULL,
  `hostname` varchar(64) DEFAULT NULL COMMENT 'اسم الجهاز من الويندوز',
  `last_seen` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `wake_until` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `scan_queue`
--

CREATE TABLE `scan_queue` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `session_code` varchar(32) NOT NULL COMMENT 'كود الاقتران — بيربط موبايل بكمبيوتر معين',
  `barcode` varchar(64) NOT NULL,
  `consumed` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `schedule_payments`
--

CREATE TABLE `schedule_payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `schedule_id` bigint(20) UNSIGNED NOT NULL,
  `payment_id` bigint(20) UNSIGNED NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `settings`
--

CREATE TABLE `settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(60) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `shifts`
--

CREATE TABLE `shifts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `branch_id` int(10) UNSIGNED NOT NULL,
  `business_date` date NOT NULL,
  `shift_name` varchar(100) DEFAULT NULL,
  `cash_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `visa_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `wallet_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `instapay_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `system_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `difference` decimal(14,2) NOT NULL DEFAULT 0.00,
  `status` enum('submitted','approved','rejected','resubmitted') NOT NULL DEFAULT 'submitted',
  `reject_reason` varchar(255) DEFAULT NULL,
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `shift_cash`
--

CREATE TABLE `shift_cash` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `shift_id` bigint(20) UNSIGNED NOT NULL,
  `denomination` decimal(8,2) NOT NULL,
  `count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `subtotal` decimal(14,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `shift_payment_lines`
--

CREATE TABLE `shift_payment_lines` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `shift_id` bigint(20) UNSIGNED NOT NULL,
  `method` enum('visa','wallet','instapay') NOT NULL,
  `line_no` tinyint(3) UNSIGNED NOT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `wallet_provider` varchar(30) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `shift_reviews`
--

CREATE TABLE `shift_reviews` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `shift_id` bigint(20) UNSIGNED NOT NULL,
  `accountant_cash` decimal(14,2) NOT NULL DEFAULT 0.00,
  `accountant_visa` decimal(14,2) NOT NULL DEFAULT 0.00,
  `accountant_wallet` decimal(14,2) NOT NULL DEFAULT 0.00,
  `accountant_instapay` decimal(14,2) NOT NULL DEFAULT 0.00,
  `accountant_system` decimal(14,2) NOT NULL DEFAULT 0.00,
  `variance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `decision` enum('approved','rejected') NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `shortage_batches`
--

CREATE TABLE `shortage_batches` (
  `id` int(10) UNSIGNED NOT NULL,
  `source` enum('manual','erp') NOT NULL DEFAULT 'manual',
  `period_from` date NOT NULL,
  `period_to` date NOT NULL,
  `label` varchar(120) DEFAULT NULL COMMENT 'اسم وصفي — "موسم 2025" مثلاً',
  `rows_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `stores_count` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `note` text DEFAULT NULL COMMENT 'ملاحظات الاستيراد — ملفات، ترميز، صفوف متجاهلة',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='رفعات تحليل الموسم — كل استيراد صف';

-- --------------------------------------------------------

--
-- بنية الجدول `shortage_lines`
--

CREATE TABLE `shortage_lines` (
  `batch_id` int(10) UNSIGNED NOT NULL,
  `store_id` smallint(6) NOT NULL COMMENT 'رقم المخزن زي ما هو في الـERP',
  `barcode` varchar(64) NOT NULL COMMENT 'كود الصنف — مفتاح الربط مع hq_codes',
  `item_name` varchar(255) DEFAULT NULL COMMENT 'الاسم من الملف — احتياطي لو الصنف مش عندنا',
  `balance` decimal(16,3) NOT NULL DEFAULT 0.000 COMMENT 'الرصيد',
  `in_qty` decimal(16,3) NOT NULL DEFAULT 0.000 COMMENT 'الوارد — صافى مشتريات الكميه',
  `in_cost` decimal(16,3) NOT NULL DEFAULT 0.000 COMMENT 'قيمة المشتريات بسعر الشراء',
  `in_sale` decimal(16,3) NOT NULL DEFAULT 0.000 COMMENT 'قيمة المشتريات بسعر البيع',
  `out_qty` decimal(16,3) NOT NULL DEFAULT 0.000 COMMENT 'الصادر — صافى مبيعات الكميه'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='بنود تحليل الموسم — صف لكل صنف في كل مخزن';

-- --------------------------------------------------------

--
-- بنية الجدول `shortage_requests`
--

CREATE TABLE `shortage_requests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `from_branch_id` int(10) UNSIGNED NOT NULL COMMENT 'الفرع الطالب (اللي ناقصه)',
  `to_branch_id` int(10) UNSIGNED NOT NULL COMMENT 'الفرع المطلوب منه (اللي هيجهّز)',
  `requested_by` int(10) UNSIGNED DEFAULT NULL COMMENT 'الموظف اللي عمل الطلب',
  `notes` varchar(1000) DEFAULT NULL,
  `task_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'المهمة المرتبطة',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `shortage_request_lines`
--

CREATE TABLE `shortage_request_lines` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `request_id` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(64) NOT NULL COMMENT 'الباركود',
  `item_name` varchar(255) DEFAULT NULL,
  `qty` decimal(12,3) NOT NULL DEFAULT 0.000 COMMENT 'الكمية المطلوبة'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `stocktake_balances`
--

CREATE TABLE `stocktake_balances` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `session_id` bigint(20) UNSIGNED NOT NULL,
  `item_code` varchar(80) NOT NULL,
  `system_qty` decimal(14,3) NOT NULL DEFAULT 0.000,
  `unit` varchar(40) DEFAULT NULL,
  `item_name` varchar(255) DEFAULT NULL,
  `sale_price` decimal(14,3) DEFAULT NULL,
  `cost_price` decimal(14,3) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `stocktake_lines`
--

CREATE TABLE `stocktake_lines` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `sheet_id` bigint(20) UNSIGNED NOT NULL,
  `item_id` varchar(80) DEFAULT NULL,
  `entered_code` varchar(80) NOT NULL,
  `base_code` varchar(80) DEFAULT NULL,
  `item_name` varchar(255) DEFAULT NULL,
  `unit` varchar(40) DEFAULT NULL,
  `unit_factor` decimal(14,4) NOT NULL DEFAULT 1.0000,
  `count_qty` decimal(14,3) NOT NULL DEFAULT 0.000,
  `base_qty` decimal(14,3) NOT NULL DEFAULT 0.000,
  `system_qty` decimal(14,3) DEFAULT NULL,
  `sale_price` decimal(14,3) DEFAULT NULL,
  `cost_price` decimal(14,3) DEFAULT NULL,
  `review_qty` decimal(14,3) DEFAULT NULL,
  `final_qty` decimal(14,3) DEFAULT NULL,
  `review_log` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `stocktake_places`
--

CREATE TABLE `stocktake_places` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `stocktake_sessions`
--

CREATE TABLE `stocktake_sessions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `status` enum('active','closed') NOT NULL DEFAULT 'active',
  `has_balance` tinyint(1) NOT NULL DEFAULT 0,
  `started_by` int(10) UNSIGNED DEFAULT NULL,
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `closed_by` int(10) UNSIGNED DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `stocktake_sheets`
--

CREATE TABLE `stocktake_sheets` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `session_id` bigint(20) UNSIGNED NOT NULL,
  `sheet_no` int(10) UNSIGNED NOT NULL,
  `branch_id` int(10) UNSIGNED DEFAULT NULL,
  `place_note` varchar(255) DEFAULT NULL,
  `counted_by` int(10) UNSIGNED DEFAULT NULL,
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL,
  `status` enum('open','reviewing','done') NOT NULL DEFAULT 'open',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `done_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `supplier_invoices`
--

CREATE TABLE `supplier_invoices` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `supplier_id` int(10) UNSIGNED NOT NULL,
  `invoice_no` varchar(60) DEFAULT NULL,
  `invoice_date` date NOT NULL,
  `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `status` enum('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `supplier_payments`
--

CREATE TABLE `supplier_payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `supplier_id` int(10) UNSIGNED NOT NULL,
  `invoice_id` bigint(20) UNSIGNED DEFAULT NULL,
  `treasury_id` int(10) UNSIGNED DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL,
  `payment_date` date NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `supplier_payment_schedules`
--

CREATE TABLE `supplier_payment_schedules` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `invoice_id` bigint(20) UNSIGNED NOT NULL,
  `due_date` date NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `status` enum('pending','paid') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `tasks`
--

CREATE TABLE `tasks` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(200) NOT NULL,
  `details` text DEFAULT NULL,
  `priority` enum('normal','important','urgent') NOT NULL DEFAULT 'normal',
  `due_date` date DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `status` enum('open','closed') NOT NULL DEFAULT 'open',
  `ref_type` varchar(40) DEFAULT NULL COMMENT 'مصدر المهمة: shortage ...',
  `ref_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'معرّف السجل في موديول المصدر',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `task_attachments`
--

CREATE TABLE `task_attachments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `task_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'مين رفع الملف',
  `orig_name` varchar(255) NOT NULL COMMENT 'اسم الملف الأصلي',
  `stored_name` varchar(120) NOT NULL COMMENT 'الاسم المخزّن على السيرفر',
  `mime` varchar(80) NOT NULL,
  `size_bytes` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `task_comments`
--

CREATE TABLE `task_comments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `task_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `body` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `task_targets`
--

CREATE TABLE `task_targets` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `task_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `status` enum('pending','done') NOT NULL DEFAULT 'pending',
  `done_at` timestamp NULL DEFAULT NULL,
  `seen_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `treasuries`
--

CREATE TABLE `treasuries` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `type` enum('cash','visa','instapay','wallet','card','branch_custody','main') NOT NULL COMMENT 'main = الخزينة الرئيسية (مستقلة — مش بتدخل في تقفيل الكاشير ولا إذون العهدة)',
  `branch_id` int(10) UNSIGNED DEFAULT NULL,
  `balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `show_in_receipts` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = تظهر في قوائم الاستلام والصرف، 0 = مخفية من القوائم'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `treasury_movements`
--

CREATE TABLE `treasury_movements` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `treasury_id` int(10) UNSIGNED NOT NULL,
  `movement_type` enum('receipt','disbursement') NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `entry_code_id` int(10) UNSIGNED DEFAULT NULL,
  `branch_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'فرع التكلفة — القيد اتحسب على أنهي فرع (تحليلي)',
  `movement_date` date NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL COMMENT 'نفس ملاحظة القيد — متخزّنة هنا كمان عشان الإيصال يفضل مطابق'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `treasury_transfers`
--

CREATE TABLE `treasury_transfers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `from_treasury_id` int(10) UNSIGNED NOT NULL,
  `to_treasury_id` int(10) UNSIGNED NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `transfer_date` date NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `username` varchar(60) NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role_id` int(10) UNSIGNED NOT NULL,
  `branch_id` int(10) UNSIGNED DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `user_permissions`
--

CREATE TABLE `user_permissions` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `module_key` varchar(40) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- فهارس للجدول `accounts`
--
ALTER TABLE `accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_acc_src` (`src_table`,`src_id`),
  ADD KEY `idx_acc_parent` (`parent_id`),
  ADD KEY `idx_acc_type` (`type`),
  ADD KEY `idx_acc_custody` (`is_custody`);

--
-- فهارس للجدول `agent_status`
--
ALTER TABLE `agent_status`
  ADD PRIMARY KEY (`agent_key`),
  ADD KEY `idx_active` (`is_active`,`sort_order`);

--
-- فهارس للجدول `ashraqa_balances`
--
ALTER TABLE `ashraqa_balances`
  ADD PRIMARY KEY (`barcode`),
  ADD KEY `idx_ashraqa_item` (`remote_item_id`),
  ADD KEY `idx_ashraqa_updated` (`updated_at`);

--
-- فهارس للجدول `ashraqa_sync_log`
--
ALTER TABLE `ashraqa_sync_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_asy_time` (`synced_at`);

--
-- فهارس للجدول `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_att_emp_date` (`employee_id`,`att_date`);

--
-- فهارس للجدول `attendance_requests`
--
ALTER TABLE `attendance_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_attreq_emp` (`employee_id`),
  ADD KEY `fk_attreq_reviewer` (`reviewed_by`);

--
-- فهارس للجدول `book_catalog`
--
ALTER TABLE `book_catalog`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_code` (`code`),
  ADD KEY `idx_name` (`name`);

--
-- فهارس للجدول `book_order_lines`
--
ALTER TABLE `book_order_lines`
  ADD PRIMARY KEY (`book_id`,`supplier_id`),
  ADD KEY `idx_sup` (`supplier_id`);

--
-- فهارس للجدول `book_reservations`
--
ALTER TABLE `book_reservations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_branch` (`branch_id`,`status`),
  ADD KEY `idx_phone` (`customer_phone`);

--
-- فهارس للجدول `book_reservation_lines`
--
ALTER TABLE `book_reservation_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_res` (`reservation_id`),
  ADD KEY `idx_item` (`item_id`);

--
-- فهارس للجدول `book_suppliers`
--
ALTER TABLE `book_suppliers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_name` (`name`);

--
-- فهارس للجدول `branches`
--
ALTER TABLE `branches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_branches_name` (`name`);

--
-- فهارس للجدول `branch_custody`
--
ALTER TABLE `branch_custody`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_custody_branch` (`branch_id`),
  ADD KEY `fk_custody_treasury` (`custody_treasury_id`),
  ADD KEY `idx_custody_status` (`branch_id`,`status`),
  ADD KEY `fk_custody_closer` (`closed_by`);

--
-- فهارس للجدول `custody_cash`
--
ALTER TABLE `custody_cash`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_ccash_custody` (`custody_id`);

--
-- فهارس للجدول `custody_deficits`
--
ALTER TABLE `custody_deficits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_deficit_custody` (`custody_id`),
  ADD KEY `fk_deficit_branch` (`branch_id`);

--
-- فهارس للجدول `custody_lines`
--
ALTER TABLE `custody_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_cline_custody` (`custody_id`),
  ADD KEY `fk_cline_user` (`created_by`);

--
-- فهارس للجدول `disbursement_orders`
--
ALTER TABLE `disbursement_orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_disb_branch` (`branch_id`),
  ADD KEY `fk_disb_treasury` (`custody_treasury_id`),
  ADD KEY `fk_disb_user` (`created_by`);

--
-- فهارس للجدول `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_emp_branch` (`branch_id`),
  ADD KEY `fk_emp_user` (`user_id`);

--
-- فهارس للجدول `employee_advances`
--
ALTER TABLE `employee_advances`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_adv_emp` (`employee_id`);

--
-- فهارس للجدول `employee_leaves`
--
ALTER TABLE `employee_leaves`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_leave_emp` (`employee_id`);

--
-- فهارس للجدول `employee_penalties`
--
ALTER TABLE `employee_penalties`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_pen_emp` (`employee_id`);

--
-- فهارس للجدول `entry_codes`
--
ALTER TABLE `entry_codes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_entry_name` (`name`);

--
-- فهارس للجدول `expense_categories`
--
ALTER TABLE `expense_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_expcat_name` (`name`);

--
-- فهارس للجدول `hq_balances`
--
ALTER TABLE `hq_balances`
  ADD PRIMARY KEY (`item_id`,`sto_id`),
  ADD KEY `idx_hqbal_store` (`sto_id`),
  ADD KEY `idx_hqbal_updated` (`updated_at`),
  ADD KEY `idx_hqbal_batch` (`batch`);

--
-- فهارس للجدول `hq_branch_balances`
--
ALTER TABLE `hq_branch_balances`
  ADD PRIMARY KEY (`item_id`,`sto_id`),
  ADD KEY `idx_agent` (`agent_key`),
  ADD KEY `idx_item` (`item_id`),
  ADD KEY `idx_fresh` (`updated_at`);

--
-- فهارس للجدول `hq_cat_names`
--
ALTER TABLE `hq_cat_names`
  ADD PRIMARY KEY (`is1_id`);

--
-- فهارس للجدول `hq_codes`
--
ALTER TABLE `hq_codes`
  ADD PRIMARY KEY (`code`),
  ADD KEY `idx_hqcodes_item` (`item_id`),
  ADD KEY `idx_hqcodes_type` (`code_type`),
  ADD KEY `idx_hq_codes_price` (`price`);

--
-- فهارس للجدول `hq_invoices`
--
ALTER TABLE `hq_invoices`
  ADD PRIMARY KEY (`sth_id`,`suffix`),
  ADD KEY `idx_hqinv_suffix` (`suffix`),
  ADD KEY `idx_hqinv_doctype` (`doc_type`),
  ADD KEY `idx_hqinv_date` (`invoice_date`),
  ADD KEY `idx_hqinv_supplier` (`supplier_id`),
  ADD KEY `idx_hq_inv_doc_no` (`doc_no`);

--
-- فهارس للجدول `hq_invoice_lines`
--
ALTER TABLE `hq_invoice_lines`
  ADD PRIMARY KEY (`sth_id`,`suffix`,`line_no`),
  ADD KEY `idx_hqline_item` (`item_id`),
  ADD KEY `idx_hqline_code` (`item_code`),
  ADD KEY `idx_hqline_suffix` (`suffix`),
  ADD KEY `idx_hqil_code` (`item_code`);

--
-- فهارس للجدول `hq_invoice_sync_state`
--
ALTER TABLE `hq_invoice_sync_state`
  ADD PRIMARY KEY (`suffix`);

--
-- فهارس للجدول `hq_items`
--
ALTER TABLE `hq_items`
  ADD PRIMARY KEY (`barcode`),
  ADD KEY `idx_hqitems_item` (`item_id`),
  ADD KEY `idx_hqitems_updated` (`updated_at`);

--
-- فهارس للجدول `hq_item_cats`
--
ALTER TABLE `hq_item_cats`
  ADD PRIMARY KEY (`item_id`),
  ADD KEY `idx_cat` (`is1_id`);

--
-- فهارس للجدول `hq_purchased_items`
--
ALTER TABLE `hq_purchased_items`
  ADD PRIMARY KEY (`item_id`),
  ADD KEY `idx_batch` (`batch`);

--
-- فهارس للجدول `hq_stores`
--
ALTER TABLE `hq_stores`
  ADD PRIMARY KEY (`sto_id`);

--
-- فهارس للجدول `hq_sync_log`
--
ALTER TABLE `hq_sync_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_hqsync_kind` (`kind`,`synced_at`);

--
-- فهارس للجدول `hq_unposted`
--
ALTER TABLE `hq_unposted`
  ADD PRIMARY KEY (`sth_id`),
  ADD KEY `idx_hqunp_doctype` (`doc_type`),
  ADD KEY `idx_hqunp_date` (`invoice_date`),
  ADD KEY `idx_hqunp_batch` (`batch`);

--
-- فهارس للجدول `hq_unposted_lines`
--
ALTER TABLE `hq_unposted_lines`
  ADD PRIMARY KEY (`sth_id`,`line_no`),
  ADD KEY `idx_hqunpline_item` (`item_id`),
  ADD KEY `idx_hqunpline_code` (`item_code`),
  ADD KEY `idx_hqul_code` (`item_code`);

--
-- فهارس للجدول `invoice_branch_exports`
--
ALTER TABLE `invoice_branch_exports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_dist_branch` (`distribution_id`,`branch_id`),
  ADD KEY `fk_bexp_branch` (`branch_id`);

--
-- فهارس للجدول `invoice_distribution`
--
ALTER TABLE `invoice_distribution`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_dist_supplier` (`supplier_id`),
  ADD KEY `fk_dist_user` (`created_by`),
  ADD KEY `idx_dist_invoice_no` (`invoice_no`);

--
-- فهارس للجدول `invoice_distribution_lines`
--
ALTER TABLE `invoice_distribution_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_distline_dist` (`distribution_id`),
  ADD KEY `fk_distline_branch` (`branch_id`),
  ADD KEY `idx_idl_dist_code` (`distribution_id`,`notes`(40)),
  ADD KEY `idx_idl_branch` (`branch_id`);

--
-- فهارس للجدول `invoice_groups`
--
ALTER TABLE `invoice_groups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ig_status` (`status`),
  ADD KEY `fk_ig_user` (`created_by`);

--
-- فهارس للجدول `invoice_group_items`
--
ALTER TABLE `invoice_group_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_igi` (`group_id`,`sth_id`,`suffix`),
  ADD KEY `idx_igi_group` (`group_id`),
  ADD KEY `idx_igi_invno` (`invoice_no`);

--
-- فهارس للجدول `invoice_group_lines`
--
ALTER TABLE `invoice_group_lines`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_igl` (`group_id`,`sth_id`,`suffix`,`item_code`),
  ADD KEY `idx_igl_group` (`group_id`),
  ADD KEY `idx_igl_inv` (`sth_id`,`suffix`);

--
-- فهارس للجدول `invoice_item_flags`
--
ALTER TABLE `invoice_item_flags`
  ADD PRIMARY KEY (`distribution_id`,`item_code`),
  ADD KEY `idx_iif_code` (`item_code`);

--
-- فهارس للجدول `invoice_item_prices`
--
ALTER TABLE `invoice_item_prices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_dist_item` (`distribution_id`,`item_code`),
  ADD KEY `idx_iip_code` (`item_code`);

--
-- فهارس للجدول `item_export_flags`
--
ALTER TABLE `item_export_flags`
  ADD PRIMARY KEY (`item_code`),
  ADD KEY `idx_ief_skip` (`skip_export`);

--
-- فهارس للجدول `item_price_drafts`
--
ALTER TABLE `item_price_drafts`
  ADD PRIMARY KEY (`item_code`),
  ADD KEY `idx_pdft_updated` (`updated_at`),
  ADD KEY `idx_pdft_item` (`item_id`);

--
-- فهارس للجدول `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_journal_treasury` (`treasury_id`),
  ADD KEY `idx_journal_date` (`entry_date`),
  ADD KEY `idx_journal_ref` (`ref_type`,`ref_id`),
  ADD KEY `fk_journal_user` (`created_by`),
  ADD KEY `fk_journal_account` (`account_id`);

--
-- فهارس للجدول `login_log`
--
ALTER TABLE `login_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_loginlog_user` (`user_id`),
  ADD KEY `idx_ll_user_time` (`username`,`created_at`),
  ADD KEY `idx_ll_ip_time` (`ip_address`,`created_at`);

--
-- فهارس للجدول `market_fetch_log`
--
ALTER TABLE `market_fetch_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_mf_at` (`fetched_at`);

--
-- فهارس للجدول `market_ignored`
--
ALTER TABLE `market_ignored`
  ADD PRIMARY KEY (`item_id`);

--
-- فهارس للجدول `market_links`
--
ALTER TABLE `market_links`
  ADD PRIMARY KEY (`item_id`),
  ADD KEY `idx_ml_market` (`market_id`);

--
-- فهارس للجدول `market_products`
--
ALTER TABLE `market_products`
  ADD PRIMARY KEY (`market_id`),
  ADD KEY `idx_mp_norm` (`name_norm`),
  ADD KEY `idx_mp_price` (`price`);

--
-- فهارس للجدول `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notif_user` (`user_id`,`is_read`);

--
-- فهارس للجدول `pos_branch_map`
--
ALTER TABLE `pos_branch_map`
  ADD PRIMARY KEY (`branch_id`);

--
-- فهارس للجدول `pos_invoices`
--
ALTER TABLE `pos_invoices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_shift` (`shift_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_sold` (`sold_at`),
  ADD KEY `idx_sql_id` (`sql_sth_id`);

--
-- فهارس للجدول `pos_invoice_lines`
--
ALTER TABLE `pos_invoice_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_invoice` (`invoice_id`),
  ADD KEY `idx_item` (`item_id`);

--
-- فهارس للجدول `pos_migration_log`
--
ALTER TABLE `pos_migration_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_shift` (`shift_id`),
  ADD KEY `idx_invoice` (`invoice_id`),
  ADD KEY `idx_result` (`result`),
  ADD KEY `idx_created` (`created_at`);

--
-- فهارس للجدول `pos_shifts`
--
ALTER TABLE `pos_shifts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_branch_status` (`branch_id`,`status`),
  ADD KEY `idx_opened` (`opened_at`);

--
-- فهارس للجدول `reconciliations`
--
ALTER TABLE `reconciliations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_recon_treasury` (`treasury_id`),
  ADD KEY `fk_recon_user` (`created_by`);

--
-- فهارس للجدول `report_archive`
--
ALTER TABLE `report_archive`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_report_user` (`generated_by`);

--
-- فهارس للجدول `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_roles_name` (`name`);

--
-- فهارس للجدول `salaries`
--
ALTER TABLE `salaries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_salary_emp_month` (`employee_id`,`salary_month`);

--
-- فهارس للجدول `scan_agents`
--
ALTER TABLE `scan_agents`
  ADD PRIMARY KEY (`session_code`);

--
-- فهارس للجدول `scan_queue`
--
ALTER TABLE `scan_queue`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sess` (`session_code`,`consumed`,`id`);

--
-- فهارس للجدول `schedule_payments`
--
ALTER TABLE `schedule_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_schpay_schedule` (`schedule_id`),
  ADD KEY `fk_schpay_payment` (`payment_id`);

--
-- فهارس للجدول `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_settings_key` (`setting_key`);

--
-- فهارس للجدول `shifts`
--
ALTER TABLE `shifts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_shift_user` (`user_id`),
  ADD KEY `fk_shift_branch` (`branch_id`),
  ADD KEY `idx_shift_date` (`business_date`),
  ADD KEY `idx_shift_status` (`status`),
  ADD KEY `fk_shift_reviewer` (`reviewed_by`);

--
-- فهارس للجدول `shift_cash`
--
ALTER TABLE `shift_cash`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_scash_shift` (`shift_id`);

--
-- فهارس للجدول `shift_payment_lines`
--
ALTER TABLE `shift_payment_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_sline_shift` (`shift_id`);

--
-- فهارس للجدول `shift_reviews`
--
ALTER TABLE `shift_reviews`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_review_shift` (`shift_id`),
  ADD KEY `fk_review_user` (`reviewed_by`);

--
-- فهارس للجدول `shortage_batches`
--
ALTER TABLE `shortage_batches`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_period` (`period_from`,`period_to`),
  ADD KEY `idx_source` (`source`);

--
-- فهارس للجدول `shortage_lines`
--
ALTER TABLE `shortage_lines`
  ADD PRIMARY KEY (`batch_id`,`store_id`,`barcode`),
  ADD KEY `idx_barcode` (`barcode`),
  ADD KEY `idx_batch_store` (`batch_id`,`store_id`),
  ADD KEY `idx_batch_barcode` (`batch_id`,`barcode`);

--
-- فهارس للجدول `shortage_requests`
--
ALTER TABLE `shortage_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_shr_from` (`from_branch_id`),
  ADD KEY `idx_shr_to` (`to_branch_id`),
  ADD KEY `idx_shr_task` (`task_id`),
  ADD KEY `fk_shr_user` (`requested_by`);

--
-- فهارس للجدول `shortage_request_lines`
--
ALTER TABLE `shortage_request_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_shrl_req` (`request_id`);

--
-- فهارس للجدول `stocktake_balances`
--
ALTER TABLE `stocktake_balances`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_stkbal_session_code` (`session_id`,`item_code`);

--
-- فهارس للجدول `stocktake_lines`
--
ALTER TABLE `stocktake_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_stkline_sheet` (`sheet_id`),
  ADD KEY `idx_stkline_item` (`sheet_id`,`item_id`);

--
-- فهارس للجدول `stocktake_places`
--
ALTER TABLE `stocktake_places`
  ADD PRIMARY KEY (`id`);

--
-- فهارس للجدول `stocktake_sessions`
--
ALTER TABLE `stocktake_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_stk_sess_starter` (`started_by`),
  ADD KEY `fk_stk_sess_closer` (`closed_by`);

--
-- فهارس للجدول `stocktake_sheets`
--
ALTER TABLE `stocktake_sheets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_stksheet_session` (`session_id`),
  ADD KEY `fk_stksheet_counter` (`counted_by`),
  ADD KEY `fk_stksheet_reviewer` (`reviewed_by`);

--
-- فهارس للجدول `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_supplier_name` (`name`);

--
-- فهارس للجدول `supplier_invoices`
--
ALTER TABLE `supplier_invoices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_sinv_supplier` (`supplier_id`);

--
-- فهارس للجدول `supplier_payments`
--
ALTER TABLE `supplier_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_spay_supplier` (`supplier_id`),
  ADD KEY `fk_spay_invoice` (`invoice_id`),
  ADD KEY `fk_spay_treasury` (`treasury_id`),
  ADD KEY `fk_spay_user` (`created_by`);

--
-- فهارس للجدول `supplier_payment_schedules`
--
ALTER TABLE `supplier_payment_schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_sched_invoice` (`invoice_id`);

--
-- فهارس للجدول `tasks`
--
ALTER TABLE `tasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_task_creator` (`created_by`),
  ADD KEY `idx_task_ref` (`ref_type`,`ref_id`);

--
-- فهارس للجدول `task_attachments`
--
ALTER TABLE `task_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ta_task` (`task_id`),
  ADD KEY `fk_ta_user` (`user_id`);

--
-- فهارس للجدول `task_comments`
--
ALTER TABLE `task_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tc_task` (`task_id`),
  ADD KEY `fk_tc_user` (`user_id`);

--
-- فهارس للجدول `task_targets`
--
ALTER TABLE `task_targets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_task_user` (`task_id`,`user_id`),
  ADD KEY `idx_tt_user` (`user_id`);

--
-- فهارس للجدول `treasuries`
--
ALTER TABLE `treasuries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_treasury_branch` (`branch_id`);

--
-- فهارس للجدول `treasury_movements`
--
ALTER TABLE `treasury_movements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_movement_treasury` (`treasury_id`),
  ADD KEY `fk_movement_user` (`created_by`),
  ADD KEY `fk_movement_branch` (`branch_id`);

--
-- فهارس للجدول `treasury_transfers`
--
ALTER TABLE `treasury_transfers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_transfer_from` (`from_treasury_id`),
  ADD KEY `fk_transfer_to` (`to_treasury_id`),
  ADD KEY `fk_transfer_user` (`created_by`);

--
-- فهارس للجدول `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_username` (`username`),
  ADD KEY `fk_users_role` (`role_id`),
  ADD KEY `fk_users_branch` (`branch_id`);

--
-- فهارس للجدول `user_permissions`
--
ALTER TABLE `user_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_module` (`user_id`,`module_key`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `accounts`
--
ALTER TABLE `accounts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ashraqa_sync_log`
--
ALTER TABLE `ashraqa_sync_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance_requests`
--
ALTER TABLE `attendance_requests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `book_catalog`
--
ALTER TABLE `book_catalog`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `book_reservations`
--
ALTER TABLE `book_reservations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `book_reservation_lines`
--
ALTER TABLE `book_reservation_lines`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `book_suppliers`
--
ALTER TABLE `book_suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `branches`
--
ALTER TABLE `branches`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `branch_custody`
--
ALTER TABLE `branch_custody`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `custody_cash`
--
ALTER TABLE `custody_cash`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `custody_deficits`
--
ALTER TABLE `custody_deficits`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `custody_lines`
--
ALTER TABLE `custody_lines`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `disbursement_orders`
--
ALTER TABLE `disbursement_orders`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employee_advances`
--
ALTER TABLE `employee_advances`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employee_leaves`
--
ALTER TABLE `employee_leaves`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employee_penalties`
--
ALTER TABLE `employee_penalties`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `entry_codes`
--
ALTER TABLE `entry_codes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expense_categories`
--
ALTER TABLE `expense_categories`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hq_sync_log`
--
ALTER TABLE `hq_sync_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoice_branch_exports`
--
ALTER TABLE `invoice_branch_exports`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoice_distribution`
--
ALTER TABLE `invoice_distribution`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoice_distribution_lines`
--
ALTER TABLE `invoice_distribution_lines`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoice_groups`
--
ALTER TABLE `invoice_groups`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoice_group_items`
--
ALTER TABLE `invoice_group_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoice_group_lines`
--
ALTER TABLE `invoice_group_lines`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoice_item_prices`
--
ALTER TABLE `invoice_item_prices`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `journal_entries`
--
ALTER TABLE `journal_entries`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `login_log`
--
ALTER TABLE `login_log`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `market_fetch_log`
--
ALTER TABLE `market_fetch_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pos_invoices`
--
ALTER TABLE `pos_invoices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pos_invoice_lines`
--
ALTER TABLE `pos_invoice_lines`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pos_migration_log`
--
ALTER TABLE `pos_migration_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pos_shifts`
--
ALTER TABLE `pos_shifts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reconciliations`
--
ALTER TABLE `reconciliations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `report_archive`
--
ALTER TABLE `report_archive`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `salaries`
--
ALTER TABLE `salaries`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `scan_queue`
--
ALTER TABLE `scan_queue`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `schedule_payments`
--
ALTER TABLE `schedule_payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shifts`
--
ALTER TABLE `shifts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shift_cash`
--
ALTER TABLE `shift_cash`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shift_payment_lines`
--
ALTER TABLE `shift_payment_lines`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shift_reviews`
--
ALTER TABLE `shift_reviews`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shortage_batches`
--
ALTER TABLE `shortage_batches`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shortage_requests`
--
ALTER TABLE `shortage_requests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shortage_request_lines`
--
ALTER TABLE `shortage_request_lines`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stocktake_balances`
--
ALTER TABLE `stocktake_balances`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stocktake_lines`
--
ALTER TABLE `stocktake_lines`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stocktake_places`
--
ALTER TABLE `stocktake_places`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stocktake_sessions`
--
ALTER TABLE `stocktake_sessions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stocktake_sheets`
--
ALTER TABLE `stocktake_sheets`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `supplier_invoices`
--
ALTER TABLE `supplier_invoices`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `supplier_payments`
--
ALTER TABLE `supplier_payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `supplier_payment_schedules`
--
ALTER TABLE `supplier_payment_schedules`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tasks`
--
ALTER TABLE `tasks`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `task_attachments`
--
ALTER TABLE `task_attachments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `task_comments`
--
ALTER TABLE `task_comments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `task_targets`
--
ALTER TABLE `task_targets`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `treasuries`
--
ALTER TABLE `treasuries`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `treasury_movements`
--
ALTER TABLE `treasury_movements`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `treasury_transfers`
--
ALTER TABLE `treasury_transfers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_permissions`
--
ALTER TABLE `user_permissions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------

--
-- Structure for view `hq_branch_balances_nz`
--
DROP TABLE IF EXISTS `hq_branch_balances_nz`;

CREATE ALGORITHM=UNDEFINED VIEW `hq_branch_balances_nz`  AS SELECT `hq_branch_balances`.`item_id` AS `item_id`, `hq_branch_balances`.`sto_id` AS `sto_id`, `hq_branch_balances`.`balance` AS `balance`, `hq_branch_balances`.`agent_key` AS `agent_key`, `hq_branch_balances`.`updated_at` AS `updated_at` FROM `hq_branch_balances` WHERE `hq_branch_balances`.`balance` <> 0 ;

--
-- القيود المفروضة على الجداول الملقاة
--

--
-- قيود الجداول `accounts`
--
ALTER TABLE `accounts`
  ADD CONSTRAINT `fk_acc_parent` FOREIGN KEY (`parent_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `fk_att_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `attendance_requests`
--
ALTER TABLE `attendance_requests`
  ADD CONSTRAINT `fk_attreq_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_attreq_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `branch_custody`
--
ALTER TABLE `branch_custody`
  ADD CONSTRAINT `fk_custody_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `fk_custody_closer` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_custody_treasury` FOREIGN KEY (`custody_treasury_id`) REFERENCES `treasuries` (`id`);

--
-- قيود الجداول `custody_cash`
--
ALTER TABLE `custody_cash`
  ADD CONSTRAINT `fk_ccash_custody` FOREIGN KEY (`custody_id`) REFERENCES `branch_custody` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `custody_deficits`
--
ALTER TABLE `custody_deficits`
  ADD CONSTRAINT `fk_deficit_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `fk_deficit_custody` FOREIGN KEY (`custody_id`) REFERENCES `branch_custody` (`id`);

--
-- قيود الجداول `custody_lines`
--
ALTER TABLE `custody_lines`
  ADD CONSTRAINT `fk_cline_custody` FOREIGN KEY (`custody_id`) REFERENCES `branch_custody` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cline_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `disbursement_orders`
--
ALTER TABLE `disbursement_orders`
  ADD CONSTRAINT `fk_disb_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `fk_disb_treasury` FOREIGN KEY (`custody_treasury_id`) REFERENCES `treasuries` (`id`),
  ADD CONSTRAINT `fk_disb_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `employees`
--
ALTER TABLE `employees`
  ADD CONSTRAINT `fk_emp_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_emp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `employee_advances`
--
ALTER TABLE `employee_advances`
  ADD CONSTRAINT `fk_adv_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `employee_leaves`
--
ALTER TABLE `employee_leaves`
  ADD CONSTRAINT `fk_leave_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `employee_penalties`
--
ALTER TABLE `employee_penalties`
  ADD CONSTRAINT `fk_pen_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `invoice_branch_exports`
--
ALTER TABLE `invoice_branch_exports`
  ADD CONSTRAINT `fk_bexp_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `fk_bexp_dist` FOREIGN KEY (`distribution_id`) REFERENCES `invoice_distribution` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `invoice_distribution`
--
ALTER TABLE `invoice_distribution`
  ADD CONSTRAINT `fk_dist_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_dist_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `invoice_distribution_lines`
--
ALTER TABLE `invoice_distribution_lines`
  ADD CONSTRAINT `fk_distline_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `fk_distline_dist` FOREIGN KEY (`distribution_id`) REFERENCES `invoice_distribution` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `invoice_groups`
--
ALTER TABLE `invoice_groups`
  ADD CONSTRAINT `fk_ig_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `invoice_group_items`
--
ALTER TABLE `invoice_group_items`
  ADD CONSTRAINT `fk_igi_group` FOREIGN KEY (`group_id`) REFERENCES `invoice_groups` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `invoice_group_lines`
--
ALTER TABLE `invoice_group_lines`
  ADD CONSTRAINT `fk_igl_group` FOREIGN KEY (`group_id`) REFERENCES `invoice_groups` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `invoice_item_prices`
--
ALTER TABLE `invoice_item_prices`
  ADD CONSTRAINT `fk_iip_dist` FOREIGN KEY (`distribution_id`) REFERENCES `invoice_distribution` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD CONSTRAINT `fk_journal_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_journal_treasury` FOREIGN KEY (`treasury_id`) REFERENCES `treasuries` (`id`),
  ADD CONSTRAINT `fk_journal_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `login_log`
--
ALTER TABLE `login_log`
  ADD CONSTRAINT `fk_loginlog_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `reconciliations`
--
ALTER TABLE `reconciliations`
  ADD CONSTRAINT `fk_recon_treasury` FOREIGN KEY (`treasury_id`) REFERENCES `treasuries` (`id`),
  ADD CONSTRAINT `fk_recon_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `report_archive`
--
ALTER TABLE `report_archive`
  ADD CONSTRAINT `fk_report_user` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `salaries`
--
ALTER TABLE `salaries`
  ADD CONSTRAINT `fk_salary_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `schedule_payments`
--
ALTER TABLE `schedule_payments`
  ADD CONSTRAINT `fk_schpay_payment` FOREIGN KEY (`payment_id`) REFERENCES `supplier_payments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_schpay_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `supplier_payment_schedules` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `shifts`
--
ALTER TABLE `shifts`
  ADD CONSTRAINT `fk_shift_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `fk_shift_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_shift_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- قيود الجداول `shift_cash`
--
ALTER TABLE `shift_cash`
  ADD CONSTRAINT `fk_scash_shift` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `shift_payment_lines`
--
ALTER TABLE `shift_payment_lines`
  ADD CONSTRAINT `fk_sline_shift` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `shift_reviews`
--
ALTER TABLE `shift_reviews`
  ADD CONSTRAINT `fk_review_shift` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_review_user` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `shortage_lines`
--
ALTER TABLE `shortage_lines`
  ADD CONSTRAINT `fk_sl_batch` FOREIGN KEY (`batch_id`) REFERENCES `shortage_batches` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `shortage_requests`
--
ALTER TABLE `shortage_requests`
  ADD CONSTRAINT `fk_shr_from` FOREIGN KEY (`from_branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `fk_shr_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_shr_to` FOREIGN KEY (`to_branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `fk_shr_user` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `shortage_request_lines`
--
ALTER TABLE `shortage_request_lines`
  ADD CONSTRAINT `fk_shrl_req` FOREIGN KEY (`request_id`) REFERENCES `shortage_requests` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `stocktake_balances`
--
ALTER TABLE `stocktake_balances`
  ADD CONSTRAINT `fk_stkbal_session` FOREIGN KEY (`session_id`) REFERENCES `stocktake_sessions` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `stocktake_lines`
--
ALTER TABLE `stocktake_lines`
  ADD CONSTRAINT `fk_stkline_sheet` FOREIGN KEY (`sheet_id`) REFERENCES `stocktake_sheets` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `stocktake_sessions`
--
ALTER TABLE `stocktake_sessions`
  ADD CONSTRAINT `fk_stk_sess_closer` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_stk_sess_starter` FOREIGN KEY (`started_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `stocktake_sheets`
--
ALTER TABLE `stocktake_sheets`
  ADD CONSTRAINT `fk_stksheet_counter` FOREIGN KEY (`counted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_stksheet_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_stksheet_session` FOREIGN KEY (`session_id`) REFERENCES `stocktake_sessions` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `supplier_invoices`
--
ALTER TABLE `supplier_invoices`
  ADD CONSTRAINT `fk_sinv_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `supplier_payments`
--
ALTER TABLE `supplier_payments`
  ADD CONSTRAINT `fk_spay_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `supplier_invoices` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_spay_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  ADD CONSTRAINT `fk_spay_treasury` FOREIGN KEY (`treasury_id`) REFERENCES `treasuries` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_spay_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `supplier_payment_schedules`
--
ALTER TABLE `supplier_payment_schedules`
  ADD CONSTRAINT `fk_sched_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `supplier_invoices` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `tasks`
--
ALTER TABLE `tasks`
  ADD CONSTRAINT `fk_task_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `task_attachments`
--
ALTER TABLE `task_attachments`
  ADD CONSTRAINT `fk_ta_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ta_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `task_comments`
--
ALTER TABLE `task_comments`
  ADD CONSTRAINT `fk_tc_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_tc_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `task_targets`
--
ALTER TABLE `task_targets`
  ADD CONSTRAINT `fk_tt_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_tt_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `treasuries`
--
ALTER TABLE `treasuries`
  ADD CONSTRAINT `fk_treasury_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `treasury_movements`
--
ALTER TABLE `treasury_movements`
  ADD CONSTRAINT `fk_movement_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_movement_treasury` FOREIGN KEY (`treasury_id`) REFERENCES `treasuries` (`id`),
  ADD CONSTRAINT `fk_movement_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `treasury_transfers`
--
ALTER TABLE `treasury_transfers`
  ADD CONSTRAINT `fk_transfer_from` FOREIGN KEY (`from_treasury_id`) REFERENCES `treasuries` (`id`),
  ADD CONSTRAINT `fk_transfer_to` FOREIGN KEY (`to_treasury_id`) REFERENCES `treasuries` (`id`),
  ADD CONSTRAINT `fk_transfer_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- قيود الجداول `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`);

--
-- قيود الجداول `user_permissions`
--
ALTER TABLE `user_permissions`
  ADD CONSTRAINT `fk_perm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
