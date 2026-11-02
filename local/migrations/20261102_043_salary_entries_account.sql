-- بنربط قيود المرتبات القديمة بحساب المرتبات لو موجود؛ إعادة التشغيل آمنة.
UPDATE journal_entries SET account_id = 33
 WHERE ref_type IN ('salary','salary_reverse') AND account_id IS NULL
   AND EXISTS (SELECT 1 FROM accounts WHERE id = 33);

INSERT IGNORE INTO schema_migrations (name) VALUES ('20261102_043_salary_entries_account');
