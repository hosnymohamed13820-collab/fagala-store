-- إشراقة: تصنيف القيود القديمة اللي من غير حساب، بنفس قواعد بريف ٨٢١ اللي محمد اعتمدها للفجالة (٢٨/١٠).
-- محمد ٢/١١: «ارفع كل التحديثات… وحوار القيود اللي صلحناه». الحسابات بنفس الأرقام والأسماء في قاعدة إشراقة:
--   109 ايراد مبيعات · 33 مرتبات · 10 سلف موظفين · 73 عهدة فرع (اتأكدت ٢/١١ بـsrvq).
-- 🔴 بيشتغل على إشراقة بس: كل UPDATE محروس بوجود جدول shift_expenses (موجود في إشراقة بس) — على الفجالة no-op.
-- العمود الوحيد اللي بيتغيّر هو account_id، وبس لو فاضي. مفيش مبلغ ولا رصيد ولا تاريخ بيتغيّر.
-- اللي بيفضل NULL زي الفجالة: التحويلات بين الخزاين (26) · طرف خزينة العهدة نفسها · عهدة الموظف (مفيش حساب 204 في إشراقة) · مصروفات الوردية (3 قيود بـ635، مستنية قرار محمد).
-- العدد المتوقع على إشراقة ٢/١١: وردية 213 · إرجاع مرتب 2 · سلفة 2 · الخزينة الرئيسية في إذن عهدة الفرع 1 · الخزينة الرئيسية في تسوية عهدة الفرع 1 = 219.
SET @cn_ash = EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shift_expenses');

SET @cn_sql = IF(@cn_ash, "UPDATE journal_entries SET account_id = 109 WHERE ref_type = 'shift' AND account_id IS NULL", 'DO 0');
PREPARE cn_stmt FROM @cn_sql; EXECUTE cn_stmt; DEALLOCATE PREPARE cn_stmt;

SET @cn_sql = IF(@cn_ash, "UPDATE journal_entries SET account_id = 33 WHERE ref_type IN ('salary', 'salary_reverse') AND account_id IS NULL", 'DO 0');
PREPARE cn_stmt FROM @cn_sql; EXECUTE cn_stmt; DEALLOCATE PREPARE cn_stmt;

SET @cn_sql = IF(@cn_ash, "UPDATE journal_entries SET account_id = 10 WHERE ref_type IN ('advance', 'advance_recovery') AND account_id IS NULL", 'DO 0');
PREPARE cn_stmt FROM @cn_sql; EXECUTE cn_stmt; DEALLOCATE PREPARE cn_stmt;

-- طرف الخزينة الرئيسية في إذن صرف لعهدة الفرع ← 73 (طرف خزينة العهدة يفضل NULL) — نفس شروط ٨٢١ بند ٢ بالحرف
SET @cn_sql = IF(@cn_ash, "UPDATE journal_entries je JOIN disbursement_orders d ON d.id = je.ref_id JOIN treasuries tr ON tr.id = je.treasury_id SET je.account_id = 73 WHERE je.ref_type = 'disbursement_order' AND je.account_id IS NULL AND d.branch_id IS NOT NULL AND d.employee_id IS NULL AND tr.type NOT IN ('branch_custody', 'employee_custody')", 'DO 0');
PREPARE cn_stmt FROM @cn_sql; EXECUTE cn_stmt; DEALLOCATE PREPARE cn_stmt;

-- طرف الخزينة الرئيسية في رد/تسوية عهدة الفرع ← 73 — نفس شروط ٨٢١ بند ٤ بالحرف
SET @cn_sql = IF(@cn_ash, "UPDATE journal_entries je JOIN branch_custody bc ON bc.id = je.ref_id JOIN treasuries tr ON tr.id = je.treasury_id SET je.account_id = 73 WHERE je.ref_type IN ('custody_return', 'custody_settlement') AND je.account_id IS NULL AND bc.branch_id IS NOT NULL AND bc.employee_id IS NULL AND tr.type NOT IN ('branch_custody', 'employee_custody')", 'DO 0');
PREPARE cn_stmt FROM @cn_sql; EXECUTE cn_stmt; DEALLOCATE PREPARE cn_stmt;

INSERT IGNORE INTO schema_migrations (name) VALUES ('20261102_042_ashraqa_classify_null_entries');
