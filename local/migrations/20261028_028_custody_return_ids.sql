-- بريف ٨٢٢: تصنيف أطراف الخزينة الرئيسية لثلاثة قيود رد عهدة فرع فاتتها migration 027.
-- آمن للتكرار: يعدّل account_id الفارغ فقط، ولا يغيّر أي مبلغ أو رصيد أو تاريخ أو مرجع.

UPDATE journal_entries
SET account_id = 73
WHERE id IN (2693, 2810, 2812)
  AND account_id IS NULL
  AND treasury_id = 11;

INSERT IGNORE INTO schema_migrations (name) VALUES ('20261028_028_custody_return_ids');
