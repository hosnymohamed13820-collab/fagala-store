-- بريف ٨٢٣: رجوع رصيد أول مدة عهدة اي بي سي المفتوحة للقيمة الصحيحة 13,000 ج.
-- آمن للتكرار: بيعدّل العهدة 20 فقط وهي مفتوحة ورصيد أول مدتها صفر.
-- مابيلمسش قيود اليومية ولا رصيد خزينة العهدة.

UPDATE branch_custody
SET opening_balance = 13000.00,
    closing_balance = 13000.00 - total_expenses
WHERE id = 20
  AND branch_id = 2
  AND status = 'open'
  AND opening_balance = 0.00;

INSERT IGNORE INTO schema_migrations (name) VALUES ('20261029_029_abc_custody_opening');
