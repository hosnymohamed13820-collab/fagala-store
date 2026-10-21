-- بريف ١٨٥: السلف القديمة اتسجلت من غير حساب رغم إن حساب «سلف موظفين» موجود.
-- التحديث آمن للتكرار: بيمس قيود صرف السلفة اللي account_id فيها NULL بس، من غير تعديل مبلغ أو رصيد.
UPDATE journal_entries SET account_id = 10
 WHERE ref_type = 'advance' AND account_id IS NULL;
