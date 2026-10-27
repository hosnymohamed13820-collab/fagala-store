-- بريف ٢٤٨: تصنيف قيود إذن صرف العهدة وعجز العهدة بحساباتها في شجرة الحسابات
-- آمن للتكرار: التحديث يتم للقيود التي account_id فيها فارغ فقط (WHERE account_id IS NULL)
-- مفيش تعديل على أي مبالغ أو أرصدة أو تواريخ — تعديل عمود account_id فقط

-- ١) إضافة حساب «حسني» تحت 204 بنفس أعمدة 205
INSERT INTO accounts (parent_id, name, type, is_custody, is_active)
SELECT 204, 'حسني', 'expense', 0, 1
WHERE NOT EXISTS (
    SELECT 1 FROM accounts WHERE parent_id = 204 AND name = 'حسني'
);

-- ٢) ربط قيود إذن صرف عهدة الفرع بحساب 73 «عهدة فرع»
UPDATE journal_entries
SET account_id = 73
WHERE treasury_id = 11
  AND account_id IS NULL
  AND ref_type = 'disbursement_order'
  AND description LIKE 'إذن صرف لعهدة الفرع%';

-- ٣) ربط قيود إذن صرف عهدة موظف وعجز عهدة موظف بحساب الموظف تحت 204 (مع معالجة ى/ي واستخراج الاسم بدقة)
UPDATE journal_entries je
JOIN accounts a ON a.parent_id = 204
  AND REPLACE(TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(CASE
        WHEN je.description LIKE '%إذن صرف لعهدة موظف:%' THEN SUBSTRING_INDEX(je.description, 'إذن صرف لعهدة موظف:', -1)
        WHEN je.description LIKE '%عجز عهدة موظف عند الإقفال:%' THEN SUBSTRING_INDEX(je.description, 'عجز عهدة موظف عند الإقفال:', -1)
        ELSE ''
      END, ' - ', 1), ':', -1)), 'ى', 'ي') = REPLACE(a.name, 'ى', 'ي')
SET je.account_id = a.id
WHERE je.treasury_id = 11
  AND je.account_id IS NULL
  AND je.ref_type IN ('disbursement_order', 'custody_deficit');

INSERT IGNORE INTO schema_migrations (name) VALUES ('20261027_023_custody_order_accounts');
