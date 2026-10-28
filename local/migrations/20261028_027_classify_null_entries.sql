-- بريف ٨٢١: تصنيف القيود القديمة التي لم يُسجل لها حساب بعد اعتماد محمد لخطة بريف ٨٢٠.
-- آمن للتكرار: كل تحديث يستهدف account_id الفارغ فقط، ما عدا تصحيح القيد 2818 من الأب 204 إلى الابن 205.
-- لا يغيّر أي مبلغ أو رصيد أو تاريخ أو وصف أو خزينة أو مرجع؛ العمود الوحيد المعدّل هو account_id.

-- ١) التصنيفات المباشرة الثابتة.
UPDATE journal_entries
SET account_id = 109
WHERE ref_type = 'shift'
  AND account_id IS NULL;

UPDATE journal_entries
SET account_id = 33
WHERE ref_type IN ('salary', 'salary_reverse')
  AND account_id IS NULL;

UPDATE journal_entries
SET account_id = 10
WHERE ref_type IN ('advance', 'advance_recovery')
  AND account_id IS NULL;

-- ٢) طرف الخزينة الرئيسية/المركزية في إذن عهدة الفرع؛ طرف خزينة العهدة يفضل NULL.
UPDATE journal_entries AS je
JOIN disbursement_orders AS disb ON disb.id = je.ref_id
JOIN treasuries AS tr ON tr.id = je.treasury_id
SET je.account_id = 73
WHERE je.ref_type = 'disbursement_order'
  AND je.account_id IS NULL
  AND disb.branch_id IS NOT NULL
  AND disb.employee_id IS NULL
  AND tr.type NOT IN ('branch_custody', 'employee_custody');

-- ٣) طرف الخزينة الرئيسية/المركزية في إذن عهدة الموظف.
-- الربط يبدأ من disbursement_orders.employee_id؛ الاسم يُستخدم فقط لمطابقة الحساب القديم
-- الذي لا يحمل src_id، وليس لاستخراج الموظف من بيان القيد. لو مفيش حساب ابن يفضل القيد NULL.
UPDATE journal_entries AS je
JOIN disbursement_orders AS disb ON disb.id = je.ref_id
JOIN treasuries AS tr ON tr.id = je.treasury_id
JOIN (
    SELECT e.id AS employee_id,
           COALESCE(
               MIN(CASE
                   WHEN a.src_table = 'employees' AND a.src_id = e.id THEN a.id
               END),
               MIN(CASE
                   WHEN REPLACE(REPLACE(REPLACE(REPLACE(TRIM(a.name), 'ى', 'ي'), 'أ', 'ا'), 'إ', 'ا'), 'آ', 'ا')
                      = REPLACE(REPLACE(REPLACE(REPLACE(TRIM(e.full_name), 'ى', 'ي'), 'أ', 'ا'), 'إ', 'ا'), 'آ', 'ا')
                   THEN a.id
               END)
           ) AS account_id
    FROM employees AS e
    LEFT JOIN accounts AS a
      ON a.parent_id = 204
     AND a.is_active = 1
     AND (
          (a.src_table = 'employees' AND a.src_id = e.id)
          OR REPLACE(REPLACE(REPLACE(REPLACE(TRIM(a.name), 'ى', 'ي'), 'أ', 'ا'), 'إ', 'ا'), 'آ', 'ا')
           = REPLACE(REPLACE(REPLACE(REPLACE(TRIM(e.full_name), 'ى', 'ي'), 'أ', 'ا'), 'إ', 'ا'), 'آ', 'ا')
     )
    GROUP BY e.id
    HAVING account_id IS NOT NULL
) AS emp_account ON emp_account.employee_id = disb.employee_id
SET je.account_id = emp_account.account_id
WHERE je.ref_type = 'disbursement_order'
  AND je.account_id IS NULL
  AND disb.employee_id IS NOT NULL
  AND tr.type NOT IN ('branch_custody', 'employee_custody');

-- ٤) طرف الخزينة الرئيسية/المركزية في رد وتسوية عهدة الفرع؛ طرف العهدة يفضل NULL.
UPDATE journal_entries AS je
JOIN branch_custody AS bc ON bc.id = je.ref_id
JOIN treasuries AS tr ON tr.id = je.treasury_id
SET je.account_id = 73
WHERE je.ref_type IN ('custody_return', 'custody_settlement')
  AND je.account_id IS NULL
  AND bc.branch_id IS NOT NULL
  AND bc.employee_id IS NULL
  AND tr.type NOT IN ('branch_custody', 'employee_custody');

-- ٥) عجز عهدة الفرع يُصنف على 73 حسب قرار محمد.
UPDATE journal_entries AS je
JOIN branch_custody AS bc ON bc.id = je.ref_id
SET je.account_id = 73
WHERE je.ref_type = 'custody_deficit'
  AND je.account_id IS NULL
  AND bc.branch_id IS NOT NULL
  AND bc.employee_id IS NULL;

-- ٦) طرف الخزينة الرئيسية في عكس/زيادة عهدة الموظف.
-- الربط يبدأ من branch_custody.employee_id؛ لو مفيش حساب ابن تحت 204 يفضل القيد NULL.
UPDATE journal_entries AS je
JOIN branch_custody AS bc ON bc.id = je.ref_id
JOIN treasuries AS tr ON tr.id = je.treasury_id
JOIN (
    SELECT e.id AS employee_id,
           COALESCE(
               MIN(CASE
                   WHEN a.src_table = 'employees' AND a.src_id = e.id THEN a.id
               END),
               MIN(CASE
                   WHEN REPLACE(REPLACE(REPLACE(REPLACE(TRIM(a.name), 'ى', 'ي'), 'أ', 'ا'), 'إ', 'ا'), 'آ', 'ا')
                      = REPLACE(REPLACE(REPLACE(REPLACE(TRIM(e.full_name), 'ى', 'ي'), 'أ', 'ا'), 'إ', 'ا'), 'آ', 'ا')
                   THEN a.id
               END)
           ) AS account_id
    FROM employees AS e
    LEFT JOIN accounts AS a
      ON a.parent_id = 204
     AND a.is_active = 1
     AND (
          (a.src_table = 'employees' AND a.src_id = e.id)
          OR REPLACE(REPLACE(REPLACE(REPLACE(TRIM(a.name), 'ى', 'ي'), 'أ', 'ا'), 'إ', 'ا'), 'آ', 'ا')
           = REPLACE(REPLACE(REPLACE(REPLACE(TRIM(e.full_name), 'ى', 'ي'), 'أ', 'ا'), 'إ', 'ا'), 'آ', 'ا')
     )
    GROUP BY e.id
    HAVING account_id IS NOT NULL
) AS emp_account ON emp_account.employee_id = bc.employee_id
SET je.account_id = emp_account.account_id
WHERE je.ref_type IN ('custody_reverse_order', 'custody_surplus')
  AND je.account_id IS NULL
  AND bc.employee_id IS NOT NULL
  AND tr.type = 'main';

-- ٧) مصاريف العهدة القديمة المعتمدة بالـid فقط (٥ أصل + ٣ عكس).
UPDATE journal_entries
SET account_id = 42
WHERE id IN (1300, 1581, 1595, 1566)
  AND account_id IS NULL;

UPDATE journal_entries
SET account_id = 29
WHERE id IN (1574, 1575)
  AND account_id IS NULL;

UPDATE journal_entries
SET account_id = 33
WHERE id IN (2630, 2666)
  AND account_id IS NULL;

-- ٨) الحالات الفردية المعتمدة بالـid.
UPDATE journal_entries
SET account_id = 24
WHERE id = 1037
  AND account_id IS NULL;

UPDATE journal_entries
SET account_id = 205
WHERE id = 2818
  AND account_id = 204;

INSERT IGNORE INTO schema_migrations (name) VALUES ('20261028_027_classify_null_entries');
