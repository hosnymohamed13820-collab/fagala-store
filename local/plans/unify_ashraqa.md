# خطة توحيد كود الفجالة وإشراقة — بريف ٨٤٦، مرحلة ٠

جرد بتاريخ ٢ نوفمبر ٢٠٢٦، HEAD `b6100ed`. خطة بس: مفيش كود اتعدّل، ولا قاعدة اتلمست، ولا رفع اتعمل. القرار: site/ مصدر واحد للموقعين، وقاعدتين وإعدادات وأسرار منفصلة. البريف بيغلب قاعدة التعديل المتوازي القديمة في RULES.

الأرقام الفعلية من git ls-files: ٣١٦ ملف في site/ و١٣٥ في ashraqa/؛ ١٣٣ مشترك، ٨٧ مختلف بالبصمة، ٤٦ مطابق، ١٨٣ في الفجالة بس، و٢ في إشراقة بس. PHP في جذر المجلدين: ٢٧٥ و١٠٣. أرقام البريف ٢٧٦/٩٨ و٩٧/٦٥ مش مطابقة للـHEAD الحالي؛ الجرد تحت بيغطي الموجود كله، بما فيه الأصول الثنائية. migrations: **٤٦ SQL + README**، مش ٤٠؛ 040 رقم تسلسلي مش عدد الملفات.

المراجع: CLAUDE وRULES، سطور إشراقة وقسم ٤٣ فقط من CONTEXT، ملفا schema، محتوى migrations، مقارنة الملفات وتاريخ git log -p لكل ملف مختلف، وتاريخ تخصيصات إشراقة، وdeploy.sh. مفيش وصول للسيرفر. أي ثابت/دالة/سر من config.php = **محتاج تأكيد من السيرفر**. ملفات schema تثبت الأسماء والأنواع فقط، مش nullability/defaults/indexes/FKs أو صفوف سجل migrations.

## ١. فرق القاعدة

الفجالة ١٨٢ جدول، إشراقة ١٢٧: ٥٦ في الفجالة بس، جدول في إشراقة بس، ١٢٦ مشترك؛ ١٧ مختلفين و١٠٩ مطابقين في وصف الأعمدة المتاح. ترتيب الأعمدة مش فرق وظيفي. «—» يعني العمود غايب، مش NULL.

### جداول ناحية واحدة

| الجدول | موجود في | التصرف المقترح |
|---|---|---|
| `agent_commands` | الفجالة بس | True/الوكلاء أو توزيع فواتير الفجالة؛ يتقفل مساره، مش لازم ننشئه |
| `api_idempotency` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `api_tokens` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `ashraqa_invoice_status` | الفجالة بس | True/الوكلاء أو توزيع فواتير الفجالة؛ يتقفل مساره، مش لازم ننشئه |
| `ashraqa_item_prices` | الفجالة بس | True/الوكلاء أو توزيع فواتير الفجالة؛ يتقفل مساره، مش لازم ننشئه |
| `audit_requests` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `branch_calls` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `branch_invoice_status` | الفجالة بس | True/الوكلاء أو توزيع فواتير الفجالة؛ يتقفل مساره، مش لازم ننشئه |
| `branch_item_prices` | الفجالة بس | True/الوكلاء أو توزيع فواتير الفجالة؛ يتقفل مساره، مش لازم ننشئه |
| `customers` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `doc_events` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `doc_seq` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `fifo_alloc` | الفجالة بس | True/الوكلاء أو توزيع فواتير الفجالة؛ يتقفل مساره، مش لازم ننشئه |
| `fifo_lots` | الفجالة بس | True/الوكلاء أو توزيع فواتير الفجالة؛ يتقفل مساره، مش لازم ننشئه |
| `fifo_runs` | الفجالة بس | True/الوكلاء أو توزيع فواتير الفجالة؛ يتقفل مساره، مش لازم ننشئه |
| `hq_spec_names` | الفجالة بس | True/الوكلاء أو توزيع فواتير الفجالة؛ يتقفل مساره، مش لازم ننشئه |
| `item_class_assign` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `item_class_assign_log` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `item_classes` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `item_master` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `item_master_codes` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `item_price_batch_lines` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `item_price_batches` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `item_price_history` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `item_prices` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `item_reorder` | الفجالة بس | True/الوكلاء أو توزيع فواتير الفجالة؛ يتقفل مساره، مش لازم ننشئه |
| `journal_entry_marks` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `machine_readings` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `machine_service_codes` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `machines` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `password_reset_limits` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `password_reset_requests` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `rabt_incidents` | الفجالة بس | True/الوكلاء أو توزيع فواتير الفجالة؛ يتقفل مساره، مش لازم ننشئه |
| `role_permissions` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `sale_approval_tokens` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `sale_devices` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `sale_doc_seq` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `sale_docs` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `sale_events` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `sale_lines` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `sale_payments` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `sale_sessions` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `sms_marks` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `stock_balances` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `stock_doc_lines` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `stock_docs` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `stock_import_log` | الفجالة بس | True/الوكلاء أو توزيع فواتير الفجالة؛ يتقفل مساره، مش لازم ننشئه |
| `stock_import_runs` | الفجالة بس | True/الوكلاء أو توزيع فواتير الفجالة؛ يتقفل مساره، مش لازم ننشئه |
| `stock_moves` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `stock_transfer_diffs` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `stock_transfer_rx` | الفجالة بس | هيكل ناقص؛ يتراجع حسب الموديول في مرحلة ٢، من غير نقل صفوف الفجالة |
| `undist_notes` | الفجالة بس | True/الوكلاء أو توزيع فواتير الفجالة؛ يتقفل مساره، مش لازم ننشئه |
| `undist_state` | الفجالة بس | True/الوكلاء أو توزيع فواتير الفجالة؛ يتقفل مساره، مش لازم ننشئه |
| `user_permissions_backup_2026_08` | الفجالة بس | نسخة احتياطية خاصة بالفجالة؛ ماتتنقلش |
| `user_permissions_backup_import_cleanup` | الفجالة بس | نسخة احتياطية خاصة بالفجالة؛ ماتتنقلش |
| `user_permissions_backup_perms_split` | الفجالة بس | نسخة احتياطية خاصة بالفجالة؛ ماتتنقلش |
| `shift_expenses` | إشراقة بس | مصروفات الدرج؛ نحفظ الجدول وداتاه وندعمه في الكود المشترك بإعداد |

`shift_expenses`: `id bigint(20) unsigned`; `shift_id bigint(20) unsigned`; `account_id int(10) unsigned`; `amount decimal(14,2)`; `note varchar(255)`; `created_at timestamp`.

### كل فرق أعمدة في الجداول المشتركة

| الجدول | العمود | الفجالة | إشراقة |
|---|---|---|---|
| `attendance` | `accuracy_meters` | `—` | `int(11)` |
| `br_invoice_lines` | `bonus_qty` | `—` | `decimal(16,3)` |
| `br_invoice_lines` | `cost_price` | `decimal(16,3)` | `—` |
| `br_invoice_lines` | `discount_pct` | `—` | `decimal(8,4)` |
| `br_invoice_lines` | `last_modify` | `—` | `datetime` |
| `br_invoice_lines` | `net_price` | `—` | `decimal(16,3)` |
| `br_invoice_lines` | `price` | `—` | `decimal(16,3)` |
| `br_invoice_lines` | `qty` | `—` | `decimal(16,3)` |
| `br_invoice_lines` | `quantity` | `decimal(16,3)` | `—` |
| `br_invoice_lines` | `total_net` | `—` | `decimal(16,3)` |
| `br_invoice_lines` | `unit_factor` | `decimal(12,4)` | `—` |
| `br_invoice_lines` | `unit_id` | `—` | `int(11)` |
| `br_invoice_lines` | `unit_name` | `—` | `varchar(60)` |
| `br_invoice_lines` | `unit_price` | `decimal(16,3)` | `—` |
| `br_unposted` | `batch` | `varchar(20)` | `—` |
| `br_unposted` | `last_modify` | `—` | `datetime` |
| `br_unposted` | `remark` | `varchar(255)` | `—` |
| `br_unposted_lines` | `bonus_qty` | `—` | `decimal(16,3)` |
| `br_unposted_lines` | `cost_price` | `decimal(16,3)` | `—` |
| `br_unposted_lines` | `discount_pct` | `—` | `decimal(8,4)` |
| `br_unposted_lines` | `last_modify` | `—` | `datetime` |
| `br_unposted_lines` | `net_price` | `—` | `decimal(16,3)` |
| `br_unposted_lines` | `price` | `—` | `decimal(16,3)` |
| `br_unposted_lines` | `qty` | `—` | `decimal(16,3)` |
| `br_unposted_lines` | `quantity` | `decimal(16,3)` | `—` |
| `br_unposted_lines` | `total_net` | `—` | `decimal(16,3)` |
| `br_unposted_lines` | `unit_id` | `—` | `int(11)` |
| `br_unposted_lines` | `unit_name` | `—` | `varchar(60)` |
| `br_unposted_lines` | `unit_price` | `decimal(16,3)` | `—` |
| `custody_lines` | `branch_id` | `int(10) unsigned` | `—` |
| `custody_lines` | `employee_id` | `int(10) unsigned` | `—` |
| `custody_lines` | `line_type` | `enum('expense','advance','supplier')` | `—` |
| `custody_lines` | `supplier_id` | `int(10) unsigned` | `—` |
| `employees` | `external_id` | `varchar(32)` | `—` |
| `hq_codes` | `created_at` | `timestamp` | `—` |
| `hq_invoice_lines` | `unit_factor` | `decimal(12,4)` | `—` |
| `hq_item_cats` | `is2_id` | `int(11)` | `—` |
| `hq_item_cats` | `is3_id` | `int(11)` | `—` |
| `hq_item_cats` | `is4_id` | `int(11)` | `—` |
| `hq_item_cats` | `is5_id` | `int(11)` | `—` |
| `invoice_distribution` | `hq_guard_off` | `tinyint(1)` | `—` |
| `invoice_distribution` | `hq_guard_off_at` | `datetime` | `—` |
| `invoice_distribution` | `hq_guard_off_by` | `int(10) unsigned` | `—` |
| `invoice_problems` | `dismissed_at` | `timestamp` | `—` |
| `salaries` | `cashier_deficit` | `—` | `decimal(10,2)` |
| `salaries` | `cashier_deficit_deducted` | `—` | `decimal(10,2)` |
| `salaries` | `cashier_deficit_remaining` | `—` | `decimal(10,2)` |
| `salaries` | `reversal_entry_id` | `—` | `bigint(20) unsigned` |
| `salaries` | `reversed_at` | `datetime` | `timestamp` |
| `salaries` | `reversed_by` | `int(11)` | `int(10) unsigned` |
| `shift_edit_log` | `action` | `enum('edit','date_branch','delete','date_branch_approved')` | `enum('edit','date_branch','delete')` |
| `shifts` | `session_id` | `bigint(20) unsigned` | `—` |
| `shifts` | `source` | `varchar(10)` | `—` |
| `sms_inbox` | `dup_of` | `bigint(20) unsigned` | `—` |
| `sms_inbox` | `ref_no` | `varchar(20)` | `—` |
| `suppliers` | `credit_days` | `int(11)` | `—` |
| `suppliers` | `credit_limit` | `decimal(14,2)` | `—` |
| `suppliers` | `erp_supplier_code` | `varchar(30)` | `—` |
| `suppliers` | `notes` | `text` | `—` |
| `suppliers` | `payment_type` | `varchar(10)` | `—` |
| `suppliers` | `phone2` | `varchar(30)` | `—` |
| `suppliers` | `tax_no` | `varchar(40)` | `—` |
| `users` | `discount_cap_pct` | `decimal(5,2)` | `—` |
| `users` | `salary_mode` | `—` | `enum('hours','days','fixed')` |
| `users` | `salary_pin_block_until` | `datetime` | `—` |
| `users` | `salary_pin_fails` | `tinyint(3) unsigned` | `—` |
| `users` | `salary_pin_hash` | `varchar(255)` | `—` |
| `users` | `salary_pin_set_at` | `datetime` | `—` |

### الجداول المشتركة المطابقة

كل صف اتقارن لوحده؛ مش إثبات تطابق المفاتيح أو الداتا.

| الجدول | النتيجة |
|---|---|
| `accounts` | مطابق في الأعمدة والأنواع المتاحة |
| `agent_status` | مطابق في الأعمدة والأنواع المتاحة |
| `ashraqa_balances` | مطابق في الأعمدة والأنواع المتاحة |
| `ashraqa_purchase_lines` | مطابق في الأعمدة والأنواع المتاحة |
| `ashraqa_purchases` | مطابق في الأعمدة والأنواع المتاحة |
| `ashraqa_sync_log` | مطابق في الأعمدة والأنواع المتاحة |
| `attendance_bak_20260712` | مطابق في الأعمدة والأنواع المتاحة |
| `attendance_requests` | مطابق في الأعمدة والأنواع المتاحة |
| `attendance_requests_bak_20260712` | مطابق في الأعمدة والأنواع المتاحة |
| `book_catalog` | مطابق في الأعمدة والأنواع المتاحة |
| `book_order_lines` | مطابق في الأعمدة والأنواع المتاحة |
| `book_order_lines_bak_20260809` | مطابق في الأعمدة والأنواع المتاحة |
| `book_reservation_lines` | مطابق في الأعمدة والأنواع المتاحة |
| `book_reservations` | مطابق في الأعمدة والأنواع المتاحة |
| `book_suppliers` | مطابق في الأعمدة والأنواع المتاحة |
| `br_invoices` | مطابق في الأعمدة والأنواع المتاحة |
| `branch_custody` | مطابق في الأعمدة والأنواع المتاحة |
| `branches` | مطابق في الأعمدة والأنواع المتاحة |
| `custody_cash` | مطابق في الأعمدة والأنواع المتاحة |
| `custody_deficits` | مطابق في الأعمدة والأنواع المتاحة |
| `custody_reviews` | مطابق في الأعمدة والأنواع المتاحة |
| `disbursement_orders` | مطابق في الأعمدة والأنواع المتاحة |
| `employee_advances` | مطابق في الأعمدة والأنواع المتاحة |
| `employee_bonuses` | مطابق في الأعمدة والأنواع المتاحة |
| `employee_leaves` | مطابق في الأعمدة والأنواع المتاحة |
| `employee_ledger` | مطابق في الأعمدة والأنواع المتاحة |
| `employee_penalties` | مطابق في الأعمدة والأنواع المتاحة |
| `entry_codes` | مطابق في الأعمدة والأنواع المتاحة |
| `expense_categories` | مطابق في الأعمدة والأنواع المتاحة |
| `hq_balances` | مطابق في الأعمدة والأنواع المتاحة |
| `hq_branch_balances` | مطابق في الأعمدة والأنواع المتاحة |
| `hq_branch_balances_nz` | مطابق في الأعمدة والأنواع المتاحة |
| `hq_cat_names` | مطابق في الأعمدة والأنواع المتاحة |
| `hq_invoice_sync_state` | مطابق في الأعمدة والأنواع المتاحة |
| `hq_invoices` | مطابق في الأعمدة والأنواع المتاحة |
| `hq_items` | مطابق في الأعمدة والأنواع المتاحة |
| `hq_purchased_items` | مطابق في الأعمدة والأنواع المتاحة |
| `hq_stores` | مطابق في الأعمدة والأنواع المتاحة |
| `hq_sync_log` | مطابق في الأعمدة والأنواع المتاحة |
| `hq_unposted` | مطابق في الأعمدة والأنواع المتاحة |
| `hq_unposted_lines` | مطابق في الأعمدة والأنواع المتاحة |
| `invoice_branch_exports` | مطابق في الأعمدة والأنواع المتاحة |
| `invoice_distribution_lines` | مطابق في الأعمدة والأنواع المتاحة |
| `invoice_group_items` | مطابق في الأعمدة والأنواع المتاحة |
| `invoice_group_lines` | مطابق في الأعمدة والأنواع المتاحة |
| `invoice_groups` | مطابق في الأعمدة والأنواع المتاحة |
| `invoice_item_flags` | مطابق في الأعمدة والأنواع المتاحة |
| `invoice_item_prices` | مطابق في الأعمدة والأنواع المتاحة |
| `item_export_flags` | مطابق في الأعمدة والأنواع المتاحة |
| `item_price_drafts` | مطابق في الأعمدة والأنواع المتاحة |
| `journal_entries` | مطابق في الأعمدة والأنواع المتاحة |
| `login_log` | مطابق في الأعمدة والأنواع المتاحة |
| `market_fetch_log` | مطابق في الأعمدة والأنواع المتاحة |
| `market_ignored` | مطابق في الأعمدة والأنواع المتاحة |
| `market_links` | مطابق في الأعمدة والأنواع المتاحة |
| `market_products` | مطابق في الأعمدة والأنواع المتاحة |
| `notifications` | مطابق في الأعمدة والأنواع المتاحة |
| `payroll_adjustments` | مطابق في الأعمدة والأنواع المتاحة |
| `pos_branch_map` | مطابق في الأعمدة والأنواع المتاحة |
| `pos_invoice_lines` | مطابق في الأعمدة والأنواع المتاحة |
| `pos_invoices` | مطابق في الأعمدة والأنواع المتاحة |
| `pos_migration_log` | مطابق في الأعمدة والأنواع المتاحة |
| `pos_shifts` | مطابق في الأعمدة والأنواع المتاحة |
| `print_agents` | مطابق في الأعمدة والأنواع المتاحة |
| `print_queue` | مطابق في الأعمدة والأنواع المتاحة |
| `purchase_group_lines` | مطابق في الأعمدة والأنواع المتاحة |
| `purchase_group_sources` | مطابق في الأعمدة والأنواع المتاحة |
| `purchase_groups` | مطابق في الأعمدة والأنواع المتاحة |
| `push_queue` | مطابق في الأعمدة والأنواع المتاحة |
| `push_subscriptions` | مطابق في الأعمدة والأنواع المتاحة |
| `receipt_lines` | مطابق في الأعمدة والأنواع المتاحة |
| `receipts` | مطابق في الأعمدة والأنواع المتاحة |
| `recon_marks` | مطابق في الأعمدة والأنواع المتاحة |
| `reconciliations` | مطابق في الأعمدة والأنواع المتاحة |
| `report_archive` | مطابق في الأعمدة والأنواع المتاحة |
| `reservation_allocations` | مطابق في الأعمدة والأنواع المتاحة |
| `roles` | مطابق في الأعمدة والأنواع المتاحة |
| `salary_advance_recoveries` | مطابق في الأعمدة والأنواع المتاحة |
| `salary_shift_deficits` | مطابق في الأعمدة والأنواع المتاحة |
| `scan_agents` | مطابق في الأعمدة والأنواع المتاحة |
| `scan_queue` | مطابق في الأعمدة والأنواع المتاحة |
| `schedule_payments` | مطابق في الأعمدة والأنواع المتاحة |
| `schema_migrations` | مطابق في الأعمدة والأنواع المتاحة |
| `settings` | مطابق في الأعمدة والأنواع المتاحة |
| `shift_cash` | مطابق في الأعمدة والأنواع المتاحة |
| `shift_payment_lines` | مطابق في الأعمدة والأنواع المتاحة |
| `shift_reviews` | مطابق في الأعمدة والأنواع المتاحة |
| `shift_sequence_issues` | مطابق في الأعمدة والأنواع المتاحة |
| `shortage_batches` | مطابق في الأعمدة والأنواع المتاحة |
| `shortage_lines` | مطابق في الأعمدة والأنواع المتاحة |
| `shortage_request_lines` | مطابق في الأعمدة والأنواع المتاحة |
| `shortage_requests` | مطابق في الأعمدة والأنواع المتاحة |
| `sms_permits` | مطابق في الأعمدة والأنواع المتاحة |
| `stocktake_balances` | مطابق في الأعمدة والأنواع المتاحة |
| `stocktake_lines` | مطابق في الأعمدة والأنواع المتاحة |
| `stocktake_places` | مطابق في الأعمدة والأنواع المتاحة |
| `stocktake_sessions` | مطابق في الأعمدة والأنواع المتاحة |
| `stocktake_sheets` | مطابق في الأعمدة والأنواع المتاحة |
| `supplier_invoices` | مطابق في الأعمدة والأنواع المتاحة |
| `supplier_payment_schedules` | مطابق في الأعمدة والأنواع المتاحة |
| `supplier_payments` | مطابق في الأعمدة والأنواع المتاحة |
| `task_attachments` | مطابق في الأعمدة والأنواع المتاحة |
| `task_comments` | مطابق في الأعمدة والأنواع المتاحة |
| `task_targets` | مطابق في الأعمدة والأنواع المتاحة |
| `tasks` | مطابق في الأعمدة والأنواع المتاحة |
| `treasuries` | مطابق في الأعمدة والأنواع المتاحة |
| `treasury_movements` | مطابق في الأعمدة والأنواع المتاحة |
| `treasury_transfers` | مطابق في الأعمدة والأنواع المتاحة |
| `user_permissions` | مطابق في الأعمدة والأنواع المتاحة |

### قرار كل migration

«آمن» = مراجعة ساكنة مع الهيكل المتاح وبعد backup وتجربة نسخة إشراقة، مش إذن تشغيل إنتاج. السجل الناقص مش دليل نقص الهيكل. ما نسجلش اسم ملف كامل بعد تنفيذ جزء منه؛ نعمل ملفات توافق منفصلة أو سجل استثناءات واضح. INSERT في schema_migrations لوحده مش داتا فجالة.

| ملف SQL في local/migrations/ | الحالة والحاجة ليه | آمن زي ما هو؟ |
|---|---|---|
| `20261004_000_schema_migrations.sql` | الجدول موجود بالفعل؛ 040 أول تسجيل حسب البريف | DDL متكرر؛ مانسجلش baseline الفجالة كدليل إن ترقياتها حصلت |
| `20261012_000_custody_line_types.sql` | محتاج custody_lines.line_type/employee_id/supplier_id الناقصين | لأ للتكرار: ADD غير محروس؛ أول مرة مطابق للأنواع لكن لازم فحص كل عمود |
| `20261012_001_rabt_reminded.sql` | True؛ rabt_incidents نفسه غايب | مش مطلوب؛ ALTER على جدول غايب وغير محروس |
| `20261019_000_supplier_payment_type.sql` | مختلط: payment_type/erp_supplier_code ناقصين؛ UPDATE قوائم موردين = داتا فجالة | لأ كامل؛ DDL فقط بفحص متوافق مع السيرفر، لا تصنيف كاش/آجل من أكواد الفجالة |
| `20261022_000_advance_account.sql` | داتا فجالة: ربط السلف بحساب 10؛ ممنوع | لأ؛ نفس الرقم مش إثبات نفس الحساب |
| `20261022_001_custody_line_branch.sql` | محتاج custody_lines.branch_id؛ backfill بفرع العهدة كتابة على داتا مالية قديمة | لأ كامل؛ DDL فقط والتعبئة القديمة بمراجعة محلية مرحلة ٤، فرع العهدة مش دليل فرع المصروف |
| `20261022_002_sms_marks.sql` | محتاج sms_marks الغايب | نعم بعد مطابقة FK مع sms_inbox.id ومفتاحه وengine على السيرفر؛ COLLATE مش صريح |
| `20261024_001_machine_counters.sql` | جداول machines/readings/service_codes غايبة؛ الموديول بيقرا مبيعات True | لأ كامل؛ فيه seed أكواد تصوير الفجالة وABC. يؤجل مع machines؛ لو اتفتح ناخد DDL فقط وأكواد إشراقة المؤكدة |
| `20261025_001_advance_journal_ref.sql` | داتا فجالة: UPDATE journal_entries.ref_id للسلف القديمة بالمطابقة | ممنوع تشغيله على إشراقة؛ حتى تطابق ١:١ مش تفويض تغيير قيودها |
| `20261025_002_sms_ref_no.sql` | ناقص ref_no/dup_of؛ محتاج sms_marks؛ فيه إعادة تصنيف رسائل قديمة | لأ تلقائياً؛ DDL منفصل وbackfill محلي بعد مراجعة التعارضات؛ تأكيد إصدار REGEXP_SUBSTR وwindow functions؛ فحص ref_no وحده مش كفاية لتشغيل جزئي |
| `20261025_003_hq_codes_created_at.sql` | True: hq_codes.created_at ناقص | مش مطلوب مع القفل؛ متحصن تكرارياً لكن فيه تصفير تاريخ الصفوف القديمة أول إضافة |
| `20261025_004_shift_edit_approved_action.sql` | محتاج توسيع shift_edit_log.action لـdate_branch_approved | نعم؛ enum الحالي مثبت، بيحفظ nullability/default ولا يغير ورديات قديمة |
| `20261025_006_branch_calls.sql` | branch_calls غايب؛ مكالمات أندرويد مستقلة عن True | DDL نعم بعد FK؛ مهلة 60 إعداد عام يتراجع؛ توكنات وربط فروع إشراقة مستقلين؛ لا تفعيل تلقائي |
| `20261025_007_line_unit_factor.sql` | True: unit_factor ناقص في hq_invoice_lines وbr_invoice_lines | مش مطلوب؛ مش آمن كحل كامل لأن br_invoice_lines في إشراقة مافيهاش cost_price اللي AFTER بيعتمد عليه |
| `20261025_008_item_reorder.sql` | True: item_reorder غايب والحساب من br/hq | مش مطلوب مع قفل reorder؛ DDL متكرر وseed إعدادات عامة |
| `20261025_009_stock_ledger.sql` | جداول دفتر المخزن غايبة؛ أساس محلي محتمل | لأ كامل؛ seed stock_mode_1/2/3/4/6 فروع فجالة. DDL فقط مع تهيئة فروع إشراقة وerp_stock_enabled=0 |
| `20261025_010_stock_transfers.sql` | stock_transfer_rx/diffs غايبين؛ تحويلات محلية محتملة | نعم بعد 009؛ التفعيل قرار مستقل ومن غير import True |
| `20261025_011_purchase_docs.sql` | أعمدة الشراء والجداول نفسها غايبة | نعم بعد 009؛ hq_match مقفول؛ مش بديل purchases.php؛ يتراجع فهرس orig_doc_id في التشغيل الجزئي |
| `20261025_012_stock_import_log.sql` | True: stock_import_log/runs غايبين | مش مطلوب؛ DDL متكرر لكن الاستيراد مقفول |
| `20261025_013_reopen_shift_651.sql` | داتا فجالة: إعادة فتح وردية 651 بأرقام ثابتة | ممنوع حتى لو فيه وردية بنفس الرقم عند إشراقة |
| `20261026_014_cancel_dup_shift_386.sql` | داتا فجالة: عكس قيود 993/994/995 للوردية 386 وتغيير أرصدة وحالة | ممنوع تماماً؛ إصلاح إنتاج مخصوص |
| `20261026_015_item_movement_perm.sql` | صلاحيات حركة صنف من item_card؛ role_permissions غايب | لأ؛ جدول ناقص ومسار True مقفول، لا منح صلاحيات تلقائي |
| `20261026_016_item_master.sql` | item_master/codes/prices/history غايبين؛ كتالوج موقع مستقل | DDL نعم لو الموديول هيتفتح بعد فصل True؛ مفيش seed داتا |
| `20261026_017_item_price_batches.sql` | جداول دفعات الأسعار ناقصة؛ فهارس ومنح صلاحيات وتحديث perms_version | لأ كامل؛ محتاج 009/016 وrole_permissions؛ نفصل DDL عن منح view/apply وتحديث الصلاحيات |
| `20261026_018_item_classes.sql` | item_classes غايب؛ seed hq_cat_names وتحويل معرف cat_is1_id | لأ كامل؛ DDL للأقسام المحلية ممكن، seed وتحويل IDs مش مطلوبين مع قفل True |
| `20261027_019_pos_core.sql` | sale_* غايبة؛ shifts.source/session_id وusers.discount_cap_pct ناقصين | DDL بعد أساس المخزن ومراجعة بذور الإعدادات؛ POS مؤجل لأن وضع مصروفات إشراقة غير مدعوم حالياً |
| `20261027_020_customers.sql` | customers ناقص | DDL نعم؛ لا نقل عملاء/أرصدة، تفعيل النطاق بعد قرار الفروع |
| `20261027_021_pos_api.sql` | sale_approval_tokens ناقص | DDL نعم بعد POS؛ لا نقل توكنات الفجالة |
| `20261027_023_custody_order_accounts.sql` | داتا فجالة: حساب حسني تحت 204 وتصنيف عهد خزينة 11 وحساب 73 | ممنوع، حتى INSERT الحساب جزء من شجرة الفجالة |
| `20261027_024_pos_print.sql` | sale_devices غايب وprint_mode مطلوب لو POS اتفتح | نعم بعد 019؛ browser الافتراضي؛ queue مقفول لحد تجهيز طابعة مستقلة |
| `20261027_025_pos_close_security.sql` | ref_doc_id/close_client_uuid وجداولها غايبة | نعم بعد 019 و021؛ الفهارس تتأكد من السيرفر مش من وصف الأعمدة |
| `20261028_026_speed_indexes.sql` | فهارس True على hq_branch_balances وbr_invoices | مش مطلوب مع القفل؛ محروس بوجود الجدول والفهرس، وجود الفهرس الحالي مش معروف |
| `20261028_027_classify_null_entries.sql` | داتا فجالة: بريف ٨٢١؛ تصنيف بحسابات وأرقام قيود ثابتة | ممنوع كله؛ 109/33/10/73/42/29/24/205 وعهد 204 مش تعميم لإشراقة |
| `20261028_028_custody_return_ids.sql` | داتا فجالة: قيود 2693/2810/2812 خزينة 11 إلى 73 | ممنوع |
| `20261029_029_abc_custody_opening.sql` | داتا فجالة: عهدة ABC رقم 20 فرع 2 افتتاحي 13000 | ممنوع؛ بيغير افتتاحي وختامي العهدة |
| `20261030_030_journal_entry_marks.sql` | journal_entry_marks غايب؛ مطلوب لكشف الخزينة الجديد | DDL نعم؛ مفيش نقل ألوان/قيود |
| `20261030_031_purchase_invoice_discount.sql` | خصم الشراء ناقص والجداول غايبة | نعم بعد 009 و011؛ لا تعديل مستندات قديمة |
| `20261101_032_user_salary_pin.sql` | salary_pin الأربعة ناقصين في users | نعم؛ لازم قبل نشر الحساب/المرتب المشتركين |
| `20261101_033_password_reset_requests.sql` | password_reset_requests/limits غايبين رغم وجود كود استرجاع | DDL نعم قبل نشر الصفحات؛ لا نقل طلبات أو أكواد |
| `20261101_034_fifo_cost.sql` | True: fifo_runs/lots/alloc غايبين | مش مطلوب؛ تقفل التقارير والبناء التلقائي |
| `20261101_035_mahmoud_rabie_owner.sql` | داتا فجالة/صلاحيات: محمود ربيع users.id=4 يبقى أونر | ممنوع؛ مش هيكل |
| `20261101_036_true_specs.sql` | True: hq_spec_names وis2..is5 ناقصين | مش مطلوب؛ DDL متكرر لكن True مقفول |
| `20261101_037_item_sys_code.sql` | item_master.sys_code ناقص والجدول نفسه غايب | نعم بعد 016؛ لا backfill؛ فحص uniqueness لو اتوجدت داتا لاحقاً |
| `20261101_038_item_class_assign.sql` | item_class_assign/log غايبين؛ التقسيم الحالي معتمد على True | يؤجل مع قفل التقسيم؛ DDL متكرر لو اتقرر دعم كتالوج محلي |
| `20261102_039_supplier_card.sql` | ناقص phone2/tax_no/credit_days/credit_limit/notes وerp_supplier_code من 019 | لأ وحده؛ نضيف الكود أولاً بـDDL منفصل ونفحص تكراره قبل UNIQUE؛ ممنوع ملء الكود من id بالتخمين |
| `20261102_040_penalty_cancel.sql` | موجود: enum cancelled وcancelled_at/by/reason مطابقين؛ مسجّل حسب البريف | متكرر آمن بنيوياً؛ لا إعادة بلا سبب ولا افتراض فشل الهيكل من نقص سجل قديم |

### فجوات مش بتتحل بالـ٤٦ ملف

- baseline إشراقة أقدم. api_tokens وapi_idempotency وrole_permissions وaudit_requests ناقصين ومش بتعملهم الملفات دي. محتاجين migrations توافق هيكل مستقلة، ومفاتيح/FKs مؤكدة من السيرفر؛ الـcatch الصامت مش تكافؤ Bearer/صلاحيات/تدقيق.
- employees.external_id ناقص. salary_mode موجود في employees في الاتنين؛ الزيادة في إشراقة users.salary_mode، مايتنقلش للموظف بالتخمين ولا يتحذف. فروق salaries (reversed_at/by وأعمدة العجز والقيد العكسي) تتساب لحد حصر المستهلكين؛ لا تضييق نوع أو حذف تاريخ.
- invoice_distribution.hq_guard_off* وinvoice_problems.dismissed_at وجداول حالات التوزيع مش مغطاة كلها؛ مع القفل مانضيفهاش بس نمنع استدعاءاتها التلقائية كمان.
- br_invoice_lines/br_unposted_lines اختلاف بنيوي: qty/price/net_price في إشراقة مقابل quantity/unit_price/cost_price في الفجالة. مش بس unit_factor. ممنوع rename أو تحويل price لتكلفة بالتخمين. القفل يعزلهم، وأي مستقبل للمرآة محتاج عقد مصدر مستقل.
- مش مطلوب حذف attendance.accuracy_meters أو shift_expenses أو أي أعمدة إشراقة الزيادة؛ توحيد الكود مش معناه محو التاريخ أو تطابق DB حرفي.


## ٢. فرق الكود

الجرد يغطي الـ٨٧ ملف المختلفين فعلياً في الـHEAD الحالي، مش أرقام النسخة الأقدم في البريف. راجعت git log -p للملفات وتاريخ التخصيص: 9dcdbc4 للجلسات، f5af46d للهوية، **3fdcc54 لمصروفات الدرج والصرف من الكاش**، 407b32f للمشتريات، و54f2eef/85d0524 لفصل فواتير الفروع. «ناخد site» تحت معناها بعد فصل التخصيص وحراسة المسارات وتجهيز القاعدة، مش نسخ ورفع فوري.

| الملف/الموضع الحالي | الحكم | اقتراح التوحيد في site/ |
|---|---|---|
| `agent_lib.php`, `analysis.php`, `api_lib.php`, `ashraqa_invoice_export.php`, `ashraqa_invoices.php`, `ashraqa_prices_export.php`, `assets/acctree.js`, `assets/anicons.js`, `assets/app.js`, `assets/fagala.css`, `assets/invoices.css`, `assets/xtable.js`, `attendance.php`, `book_lib.php`, `book_orders.php`, `book_reserve.php`, `branch_export.php`, `branch_requests.php`, `custody.php`, `dashboard.php`, `definitions.php`, `employee_statement.php`, `employee_statement_api.php`, `errlog_lib.php`, `errors.php`, `group_lib.php`, `hr.php`, `hr_lib.php`, `includes/footer.php`, `includes/nav_icons.php`, `invoice_problems.php`, `invoices.php`, `item_lookup_lib.php`, `journal_account_export.php`, `market_prices.php`, `my_salary.php`, `my_salary_api.php`, `pc_scan.php`, `pos_migrate.php`, `price_inquiry.php`, `purchase_groups.php`, `push_subscribe.php`, `push_worker.php`, `receiving.php`, `receiving_diffs.php`, `receiving_flow_lib.php`, `receiving_lib.php`, `reports.php`, `salary_view_lib.php`, `sensitive_lib.php`, `settings.php`, `shortage.php`, `shortage_lib.php`, `sms_hook.php`, `sms_lib.php`, `stock_query.php`, `stocktake.php`, `stocktake_lib.php`, `stocktake_places.php`, `suppliers.php`, `sync_beat.php`, `tasks.php`, `tasks_issue.php`, `users.php` | تحديثات ماوصلتش بس: فصل lib/API، مكتبة UI، الأمان والصلاحيات والكاش والتقارير. hr_lib فيه إصلاح التابات وتعليقات قديمة، salary_view_lib تعليقات بس، anicons سطر فاضي. | ناخد site. ashraqa_invoices وexport وbranch_export فيها صلاحية عاشور القديمة invoices:ashraqa مقابل نطاق branch_invoices الأحدث؛ ده تطور صلاحيات مثبت بالتاريخ، مش تخصيص موقع جديد. مايتنقلش الوصول تلقائياً: يتراجع mapping النطاق، وصفحات True تقفل. settings.cashier_deficit_carry موجود في الاتنين مش تخصيص. push_worker وsync_beat محتاجين حراسة وظائف True قبل تشغيلها. |
| ashraqa/auth.php:54،294،474 مقابل site/auth.php:75،409،461،760؛ ashraqa/auth.php:102 | تخصيص جلسة ASHSESS وapp=ashraqa وموديول ashraqa_purchases؛ الباقي تحديثات | session_name وsite_id من profile قبل session_start، ونفس marker يتراجع عند الدخول وتجديد الجلسة؛ تسجيل الموديول المشترك مع تفعيله حسب الموقع. ترتيب تحميل config وعدم تعارض الدوال **محتاج تأكيد من السيرفر**. |
| ashraqa/cashier.php:125،137،163،231؛ review.php:272،536؛ finance.php:1811،1817،1838،1915،4782،4803؛ shift_data.php:71 | تخصيص مالي حقيقي: مصروفات الدرج ضمن collected، وتسجيل إجمالي الكاش ومصروفاته في القيود؛ رجعت عمداً في 3fdcc54 | وضع مصروفات مشترك shift_expenses_mode، مع إعادة استخدام/ربط pos_close_expenses_mode الموجود. شرط واحد للحفظ/الإرسال/المراجعة/الاعتماد/العكس وAPI والـKPI. الحفاظ على داتا shift_expenses؛ لا نسخ DELETE القديم من غير مراجعة تاريخ المال، ولا استعلام جوه حلقة. |
| ashraqa/finance.php:510؛ treasury.php:990–1002 | تخصيص السماح بالصرف من خزينة cash | allow_cash_disbursement=true لإشراقة وfalse للفجالة؛ يتطبق في منطق الخادم وقايمة الصرف مع بعض، وباقي الملف يتاخد من site. |
| ashraqa/includes/header.php:76،108،133؛ login.php:47،67؛ remote_scan.php:86،89 | هوية نبيتي واسم التطبيق وتحميل theme، وسط تحديثات مشتركة | profile يختار theme/اسم/ألوان meta، ونحتفظ بمكتبة fagala.css واحدة. الملف الإضافي ashraqa/assets/ashraqa-theme.css تخصيص مقصود، يتنقل للمصدر site في مرحلة التنفيذ. |
| `apple-touch-icon.png`, `badge-96.png`, `favicon-32.png`, `favicon.ico`, `icon-192.png`, `icon-512.png`, `icon-maskable.png`؛ ashraqa/manifest.json:2؛ sw.js:31 | أصول هوية مختلفة واسم/ألوان PWA وبادئة كاش خاصة | نسختا branding داخل site/assets/branding، يختار profile روابطهما. manifest ديناميكي أو ملفان ثابتان من المصدر نفسه. SW مشترك بكاش مرتبط بالـscope؛ تنظيف كاش الجذر مايمسحش كاش /ashraqa/ والعكس. فرق VERSION وقايمة الأصول تحديثات. |
| ashraqa/sms_inbox.php:131،134 | رابط hook ثابت وعنوان إشراقة وسط تحديثات | public_base_url موثوق + display_name من profile؛ مانبنيش عنوان الاستقبال من HTTP_HOST الخام. باقي الصفحة من site. |
| ashraqa/sync_tokens.php:31؛ sync_hq.php:27،99،384؛ sync_branch.php؛ sync_ashraqa_purchases.php:62 | أسرار مختلفة وقائمة فروع فاضية، ووكيل Redux بيستقبل كتالوجه في جداول hq_* | الأسرار من config كل موقع خارج الريبو — **محتاج تأكيد من السيرفر**. sync_hq/branch/beat مقفولين في إشراقة طبقاً للقرار. لو Redux مطلوب يستمر، endpoint/provider مستقل قبل التحويل؛ لا توريث توكنات الفجالة أو فتح استقبال فروعها. |
| ashraqa/purchases.php:6،12،36،48،92 | ملف مستقل قراءة فقط لمشتريات 113 ومرتجعات 115 من ashraqa_purchases/ashraqa_purchase_lines، بالمفتاح الرباعي (sstt_id,brch_id,fy_no,add_doc) | **مش بديل purchase_docs.php**: الأخيرة إنشاء واعتماد مستندات مخزن محلية stock_docs/lines وبتغيّر أرصدة. الحفاظ على شاشة المرآة وروابط doc وصلاحية ashraqa_purchases؛ نقلها لـsite كـlib/api/page من ui_kit لو مصدر Redux مستمر. ممنوع تحويل فواتيرها لمستندات محلية تلقائياً. |

أرقام فرع عاشور الثابتة 5 تخص فرع إشراقة جوه قاعدة الفجالة، ومش دليل إن فرع قاعدة إشراقة المستقلة رقمه 5. ملفات ashraqa_* المشتركة موجودة على الفجالة لخدمة الفرع كمان؛ الاسم مش سبب نقل تخصيص أو فتح True على الموقع المستقل.

## ٣. ملف تعريف الموقع

| البند | الشكل المقترح/القفل |
|---|---|
| الدالة ومصدر الهوية | site_profile() في includes/site_profile.php مع bootstrap واحد؛ بحث دوال auth ما أظهرش نفس الاسم. config.php غير متاح: تعارض الاسم وأي db/setting/ثابت/دالة منه **محتاج تأكيد من السيرفر**. SITE_ID ثابت نشر موثوق في config أو environment: fagala/ashraqa؛ مش session ولا اسم الفرع ولا HTTP_HOST وحده، لأن إشراقة بتتفتح بالسب دومين وبالمسار الفرعي. قيمة غايبة/غلط تقفل الطلب بدل افتراض فجالة. |
| محتويات profile | id،display_name،public_base_url،session_name/app،branding/scope،enabled_modules/parts،true_integration/agents،مصدر كتالوج/مشتريات Redux،shift_expenses_mode،allow_cash_disbursement،local_stock/pos،supplier_create_policy،account_map. الأسرار ما تتبعتش للمتصفح؛ اتصال DB يفضل منفصل في config. |
| تطبيق القفل | all_modules يفضل السجل المرجعي؛ فلترة موحدة للقائمة وشاشة المستخدمين وحفظ صلاحياتها. can/can_module/require_permission/require_module_access والـAPI/export يتحققوا من profile **قبل owner وview_all** وقبل query/require لـTrue. نفس الحراسة للـcron والـjobs؛ إخفاء القائمة وحده مش قفل. |
| branch_recon «مطابقة الفروع»، invoices «الاستلامات»، receiving «استلام الفواتير»، branch_invoices «فواتير الفروع» | قفل كامل على إشراقة: branch_recon_lib/recon_alerts/receiving_lib/group_lib وbranch_invoices_lib تعتمد hq_invoices/hq_unposted وbr_inv_*؛ يشمل ashraqa_invoices والتصدير ومشاكل الفواتير والمجموعات. |
| analysis «تحليل الموسم»، item_sales «تحليل المبيعات»، reorder «حد إعادة الطلب»، machines «عداد المكن» | قفل كامل بالوضع الحالي: analysis_lib/item_sales_lib/reorder_lib/machines_lib تعتمد br_invoices/lines وhq_branch_balances وagent_key. لا تشغيل حساباتها على هيكل br_* المختلف. |
| price_inquiry «استعلام الأسعار»، remote_scan «سكانر الكمبيوتر»، labels «طباعة ليبلز»، stocktake «الجرد»، shortage «طلب نواقص» | price_lib:46 وstocktake_lib:53،888 وlabels وstock_query تقرأ hq_items/codes وجسر True. البحث/الأرصدة True مقفولين؛ فتح الشاشة التشغيلية يتطلب provider كتالوج محلي أو Redux معتمد بلا fallback True. حفظ المحاضر/الطلبات القديمة مش محتاج حذف. |
| stock_ledger «دفتر المخزن»، purchase_docs «فواتير الشراء»، transfers «التحويلات»، item_master «كارت الصنف»، sale_prices «أسعار البيع»، pos «نقطة البيع» | قفل استيراد/مطابقة True والبحث والتقسيم/الأرباح المعتمدين عليه: stock_import_lib وpurchase_doc_lib وitem_master_lib (hq_*/br_*/fifo_store_profit). المحلي ممكن بعد تهيئة مستقلة وقرار. pos_migrate مقفول، وPOS مؤجل لأن pos_close_expenses_mode=ashraqa حالياً بيرفض صراحة (CONTEXT قسم٤٣). |
| supplier_card «كارت المورد»، suppliers «الموردون»، reports «التقارير» | كارت المورد supplier_card_lib:58،79،101 يعتمد hq_invoices/lines في المشتريات/الأصناف؛ الأجزاء دي وFIFO والمقارنات تتقفل، وبيانات المورد والكشوف والمدفوعات المحلية تفضل. SC_ALLOW_CREATE=false عند سطر5 ورسالة سطر185 «عرفه في ترو» تحتاج سياسة موقع، مش فتح إنشاء تلقائي. erp_supplier_code في إشراقة ناقص؛ مايتعوضش بـid. |
| settings «الإعدادات»، ops «المراقبة»، dashboard «الرئيسية»، definitions «التعريفات» | إغلاق agent_list/agent_dist_update/الربط/invoice_guard وagent nag والملخصات والمزامنة True، بما فيها POST. باقي الإعدادات والصحة والتعريفات المحلية تفضل. |
| cashier،custody،review،treasury،hr،attendance،tasks،tasks_issue،errors،sms_inbox،calls،customers،view_all | مفاتيح all_modules الباقية؛ لا قفل شامل لمجرد include مشترك. تتوقف الاستدعاءات الجانبية True من header/reports/receiving؛ calls يحتاج تجهيز موقعه وتوكناته، وview_all مايتجاوزش قفل profile. |
| endpoints مش مفاتيح all_modules | مفيش موديول اسمه hq_* أو fifo_* أو sync_hq أو agent_* في السجل. تتقفل sync_hq/branch/beat وأوامر/توزيع/تحديث agents وFIFO/حارس الربط وPOS migrate مباشرة، حتى لو URL مش في القايمة. stock_query/item_card وصفحات الكتب لها guards حسب المصدر الفعلي. |
| شغل الخلفية ومشكلة Redux | push_worker يفضل للإشعارات المحلية؛ daily_summary ومشاكل الفواتير وstock_import_maybe_run تتراجع وتتحرس قبل require/query. sync_hq الحالي في إشراقة بيملأ hq_items/codes من Redux؛ قفله يوقف تحديثها. نحتاج قرار مصدر بديل، مش نفترض كل hq_* داتا True ولا نفتح sync_hq كاستثناء صامت. sync_ashraqa وsync_ashraqa_purchases استقبال Redux يتحدد صراحةً بقرار محمد. |

## ٤. الرفع

| النقطة | المقترح |
|---|---|
| الوضع الحالي | tests/local/deploy.sh بيختار site→public_html وashraqa→public_html/ashraqa. candidates من git بأسماء الملفات، مقارنة بصمات مستقلة لكل وجهة، tar بالقائمة فقط، وبعده مقارنة وphp -l وقراءة logs وتحديث snapshot محلي. ما اتشغّلش في الجرد. |
| مصدر واحد | SRC_OF للموقعين يبقى site؛ الوجهتان تفضل domains/hosnimanagement.com/public_html و…/public_html/ashraqa. كل وجهة لها diff/list مستقلين من نفس المصدر، مع الحفاظ على خيارات موقع واحد و--head والتحقق بعد الرفع؛ ما نولّدش نسخة كود تانية. |
| المحميات | استمرار استبعاد config.php/config.php.* وuploads/data/downloads/logs في كل وجهة، وحارس مستقل قبل tar. رفض paths مطلقة/.. وروابط خارجة عن المصدر. رفع الملفات المحددة فقط؛ لا --delete ولا synchronize مجلد ولا نقل local. config الحقيقي مايتلمسش؛ SITE_ID يتضبط تشغيلياً قبل النشر. |
| قبل وبعد التحويل | backup كود وDB إشراقة؛ بصمات config وجرد المحميات قبل/بعد بدون إخراج أسرار. إكمال توافق الهيكل والحسابات، ثم smoke للهوستين ومسار /ashraqa/: عزل جلسات، قفل True حتى للأونر، HR والدرج والكشوف حسب الموقع؛ مراجعة errors.log.php. كلود يشغّل run_all قبل الرفع خارج وضع الوكيل حسب البريف. |
| الرجوع | وقف التحويل عند فشل؛ استرجاع كود الوجهة المتأثرة فقط. لا استعادة DB قديم يمس معاملات جديدة؛ نافذة وقف الكتابة المالية القصيرة تتحدد في بريف الرفع. |
| مجلد ashraqa في الريبو | يفضل لحد نجاح التحويل؛ ننقل purchases/theme/branding المطلوبين لـsite ونزيله من مصادر deploy، ثم إزالة الشجرة المتكررة في بريف لاحق مع آخر commit مرجعي. التاريخ في git يكفي؛ **ممنوع حذف public_html/ashraqa على السيرفر** أو نقله تحت site/legacy. |

## ٥. المراحل والقرارات

| البريف بالترتيب | الملفات/النطاق | الخطر وبوابة التسليم |
|---|---|---|
| ١ — الإعدادات والسلوك | site/includes/site_profile.php/bootstrap،auth/api_lib،header/footer/settings،branding/manifest/sw/login/remote_scan/sms،purchases lib/api/page،حراسة صفحات/API/export/jobs؛ finance/cashier/review/shift_data/treasury ومكتباتها؛ HR projection | عالي: جلسات وصلاحيات وقيود جديدة. تثبيت سلوك الدرج وخريطة الحسابات على نسخة اختبار، وقفل True قبل owner. لا رفع جزئي؛ الدوال والأسرار من config تتأكد على السيرفر. |
| ٢ — migrations | ملفات توافق جديدة في local/migrations،baseline الناقص وسجل الاستثناءات وأدوات migrations manifest/status عند الحاجة | عالي: whitelist DDL حسب القسم١؛ ممنوع تشغيل pending كله على إشراقة أو ملفات داتا فجالة. FK/UNIQUE من السيرفر، وتجربة مرتين على نسخة DB، ومن غير منح owner أو تغيير أرصدة. |
| ٣ — الرفع | tests/local/deploy.sh،الكود المشترك المجهز،ثم إزالة ashraqa المتكرر ومراجع الاختبارات اللي بتفترض نسختين | عالي: محميات ثابتة ومقارنة مستقلة لكل وجهة وsmoke ثم اعتماد التحويل. متوقف على ١ و٢ ومطابقة المالية؛ مش مستني تصحيح تاريخ المرحلة٤ عشان يمنع قيود جديدة غلط. |
| ٤ — داتا القيود | جرد مالي محلي لإشراقة،وتقرير قبل/بعد ثم ملفات تصحيح مستقلة لو اتعمدت | أعلى خطر: mapping من داتا إشراقة فقط؛ لا reuse لتصنيف٨٢١ أو عهد ABC أو أي IDs فجالة. التصحيح بقيد عكسي؛ تغيير تصنيف تاريخي يحتاج قرار وقايمة محلية محددة. |

| قرار محمد | المطلوب حسمه |
|---|---|
| ١ | اعتماد SITE_ID من config/البيئة،والـcanonical URL لإشراقة؛ ثوابت ودوال config وترتيب session وdocument root وcron/CORS/VAPID **محتاج تأكيد من السيرفر**،من غير إخراج أسرار. |
| ٢ | هل كتالوج/مشتريات Redux يستمروا بعد قفل الوكلاء؟ المقترح provider/endpoints مستقلة وتحويل عنوان الوكيل قبل cutover؛ لو كل الوكلاء هيقفوا،المرآة القديمة قراءة فقط والشاشات المحتاجة تحديث تتقفل. |
| ٣ | تثبيت مصروفات الدرج والصرف من cash لإشراقة طبقاً للتاريخ؛ المقترح POS يفضل مقفول أول نشر لحد دعمه بنفس معادلة الدرج. |
| ٤ | تأكيد معرفات الفروع والحسابات والخزائن المحلية لإشراقة؛ لا نفترض الفرع5 أو الحسابات10/73/204/33/109. إعادة استخدام settings الموجودة لإيراد المبيعات والتحويل بدل مصدر إعداد تاني. |
| ٥ | تحديد الموديولات المحلية الجديدة المطلوب فتحها فعلاً: مخزن/شراء/تحويلات/أصناف/أسعار/عملاء/مكالمات؛ لا فتح كل الملفات الزيادة تلقائياً. |
| ٦ | فواتير عاشور تفضل خدمة توزيع على الفجالة وحدها بنطاق branch_invoices؟ المقترح آه؛ موقع إشراقة يعرض purchases من مرآته لو قرار٢ يسمح،من غير خلط المصدرين. |
| ٧ | المورد الجديد في إشراقة يتعرّف في Redux ولا محلياً؟ تحديد سياسة إنشاء وكود مرجعي موثوق؛ لا رسالة «ترو» ولا توليد erp_supplier_code من id بالتخمين. |
| ٨ | اعتماد whitelist الهيكل والاستثناءات،ونافذة التحويل/الرجوع،ثم إزالة مجلد الكود المتكرر بعد smoke؛ مرحلة٤ جرد القيود المحلية أولاً وأي تصحيح يتعمد على صفوف محددة. |

## ٦. ملاحظة أمان hr_api employees

| البند | المقترح للمرحلة١ |
|---|---|
| مصدر التسريب | hr_lib.php:89–96 بيرجع e.*؛ hr_api.php:71–79 يبعته بعد mask المرتب،ونفسه part=all عند226–228. الرقم القومي والتليفون وحقول مش في التاب بتوصل للعميل. |
| الأعمدة الصريحة | التاب hr.php:1121–1145 بيعرض فقط: **full_name،branch_name،job_title،base_salary،pending_advance،expected_in،expected_out**. من employees الأعمدة full_name/job_title/base_salary/expected_in/expected_out؛ branches.name باسم branch_name؛ pending_advance تجميع employee_advances.remaining/amount للحالة pending حسب employee_id،زي الاستعلام الحالي. الأسماء اتراجعت في ملفي schema. |
| المعرف والحقول المستبعدة | id مطلوب داخلياً لربط التجميع،مش مطلوب في عرض التاب الحالي؛ مايتبعتش إلا لو مستهلك موثق محتاجه. لا national_id ولا phone ولا ملاحظات/حقول موظف تانية؛ لا e.* في رد التاب. |
| الصلاحيات والقفل | نفس can(hr:employees) وحجب base_salary على الخادم وقت القفل؛ مفيش رقم خام تحت mask شكلي. pending_advance مبلغ مالي يتراجع قفله مع سياسة الحساسية. نفس projection داخل part=all يمنع تجاوز القايمة. |
| حماية حساب الرواتب | reader/projection مخصوص للتاب؛ مانضيّقش hr_get_employees_list العامة عمياني لأنها داخلة كمان في حساب الرواتب وبتحتاج حقول زيادة. التحقق يثبت مفاتيح الرد المسموحة،وحجب المرتب،وتكافؤ حساب الرواتب قبل/بعد. |

## قرارات محمد (٢/١١)
- **٢ Redux:** يفضل شغال. كتالوج إشراقة ومشترياتها يفضلوا يتحدّثوا من وكيل Redux، و`purchases.php` (المرآة) تفضل. اللي يتقفل في إشراقة هو صفحات ترو بتاعة الفجالة بس.
- **٣ الدرج:** كل موقع على طريقته. `shift_expenses_mode` و`allow_cash_disbursement` في profile، وإشراقة تفضل بسلوكها الحالي بالحرف.
- **٥ موديولات تتفتح في إشراقة:** العملاء والمكالمات · كشف الخزينة الجديد · المخزن والشراء والتحويلات · **نقطة البيع** (محمد عايزها، فلازم POS يدعم وضع مصروفات الدرج بتاع إشراقة قبل ما تتفتح).
- **٧ مورد جديد:** في Redux بس. الموقع يعدّل البيانات (زي الفجالة مع ترو).
- القرارات ١ و٤ و٦ و٨ تقنية: كلود بيحسمها من السيرفر والقاعدة (SITE_ID في config، ومعرّفات الفروع والحسابات من قاعدة إشراقة نفسها)، وفواتير عاشور تفضل على الفجالة.
