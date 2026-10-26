-- =====================================================================
--  إلغاء وردية الفجالة 87 المكررة (shifts.id = 386، يوم 2026-09-09)
-- ---------------------------------------------------------------------
--  ليه: الكاشير (عمرو) قدّم كاش 4435 · فيزا 14660 · إنستا 75. المحاسبة اعتمدتها
--  يوم 10/9 بأرقام وردية 88 (id 388) بالظبط: 9130 · 11678.63 · 2282 · 22983.57
--  (بفرق 3920.63 كان ظاهر)، فاترحّلت أرقام وردية 88 مرتين.
--  محمد سجّل وردية 87 صح يوم 20/9 (id 457) واعتمدها، بس 386 ما اتلغتش.
--  التأكيد: صافي مبيعات الفجالة 9/9 من الوكيل = 42046.75 = 457 + 388 بالقرش.
--  قرار محمد 26/10: «الغيها».
--
--  اللي بيعمله (نفس طريقة post_journal وعكس تعديل التاريخ في finance.php):
--   ١) قيد عكسي لكل قيد من التلاتة (مدين بنفس المبلغ، نفس الخزينة والحساب
--      والتاريخ 9/9)، والرصيد التراكمي = رصيد الخزينة الحالي − المبلغ.
--   ٢) رصيد الخزائن 1/2/3 ينقص 9130 / 11678.63 / 2282.
--   ٣) القيود الأصلية والعكسية تتعلّم edited_at (مايتعكسوش تاني).
--   ٤) الوردية rejected بسبب مكتوب + return_expired_at (الكاشير مايقدرش يبعتها تاني).
--   ٥) سطر في shift_edit_log.
--  مابيلمسش 388 ولا 457 ولا shift_reviews (تاريخ).
--  آمن للتكرار: كل خطوة مشروطة.
-- =====================================================================

-- ١) القيود العكسية (مرة واحدة بس)
INSERT INTO journal_entries
    (treasury_id, entry_date, description, account_id, debit, credit,
     running_balance, ref_type, ref_id, created_by, notes)
SELECT je.treasury_id, je.entry_date,
       CONCAT('إلغاء وردية مكررة #386 — عكس ', je.description),
       je.account_id, je.credit, 0.00, t.balance - je.credit,
       'shift', 386, 1,
       'وردية 87 اتعتمدت بأرقام وردية 88 (388)، والصح اتسجّل في 457 — قرار محمد 26/10'
  FROM journal_entries je
  JOIN treasuries t ON t.id = je.treasury_id
 WHERE je.id IN (993, 994, 995)
   AND je.ref_type = 'shift' AND je.ref_id = 386
   AND je.edited_at IS NULL AND je.debit = 0
   AND (SELECT COUNT(*) FROM shifts s WHERE s.id = 386 AND s.status = 'approved'
          AND s.cash_total = 9130.00 AND s.visa_total = 11678.63
          AND s.instapay_total = 2282.00 AND s.system_total = 22983.57) = 1
   AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM journal_entries
                                   WHERE ref_type = 'shift' AND ref_id = 386 AND debit > 0) x);

-- ٢) رصيد الخزائن — بس للقيود العكسية اللي لسه ما اتطبّقتش (edited_at فاضي)
UPDATE treasuries t
  JOIN journal_entries r ON r.treasury_id = t.id
   SET t.balance = t.balance - r.debit
 WHERE r.ref_type = 'shift' AND r.ref_id = 386
   AND r.debit > 0 AND r.edited_at IS NULL;

-- ٣) تعليم القيود (الأصلية + العكسية) إنها اتعدلت
UPDATE journal_entries
   SET edited_at = NOW(), edited_by = 1
 WHERE ref_type = 'shift' AND ref_id = 386 AND edited_at IS NULL;

-- ٤) أثر في سجل الوردية
INSERT INTO shift_edit_log (shift_id, user_id, action, before_json, after_json)
SELECT s.id, 1, 'edit',
       JSON_OBJECT('status', s.status, 'cash_total', s.cash_total, 'visa_total', s.visa_total,
                   'instapay_total', s.instapay_total, 'system_total', s.system_total,
                   'reviewed_by', s.reviewed_by, 'reviewed_at', s.reviewed_at),
       JSON_OBJECT('status', 'rejected',
                   'note', 'إلغاء — مكررة: اتعتمدت بأرقام وردية 88 (388)، ووردية 87 الصح هي 457. القيود اتعكست (قرار محمد 26/10)')
  FROM shifts s
 WHERE s.id = 386 AND s.status = 'approved';

-- ٥) الوردية مرفوضة ومقفولة
UPDATE shifts
   SET status = 'rejected',
       reject_reason = 'مكررة — اتعتمدت بأرقام وردية 88، ووردية 87 الصح اتسجّلت برقم 457. القيود اتعكست.',
       return_expired_at = NOW()
 WHERE id = 386 AND status = 'approved';

INSERT IGNORE INTO schema_migrations (name) VALUES ('20261026_014_cancel_dup_shift_386');
