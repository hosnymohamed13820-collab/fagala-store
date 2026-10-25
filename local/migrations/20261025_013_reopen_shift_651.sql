-- =====================================================================
--  إعادة فتح وردية الفجالة رقم 147 (shifts.id = 651، يوم 2026-10-20) للمراجعة
-- ---------------------------------------------------------------------
--  ليه: الكاشير بعتها صح (كاش 4875 · فيزا 676.45 · إنستا 266 · سيستم 5830.72
--  = مبيعات الفجالة الحقيقية يوم 20/10 بالقرش). المحاسبة اعتمدتها يوم 21/10
--  بأرقام وردية 146 (id 646) بالظبط: 4233 · 634.37 · 552.50 · 5430.48، بفرق
--  -397.58 كان ظاهر وقت الاعتماد. والاعتماد بيكتب أرقام المحاسب على الوردية.
--  قرار محمد 25/10: تتفتح تاني للمراجعة.
--
--  اللي بيعمله:
--   ١) سطر في shift_edit_log (قبل/بعد) — أثر التعديل.
--   ٢) الوردية ترجع submitted بأرقام الكاشير الأصلية (من طلب الإرسال
--      audit_requests #3992، ومطابقة لسطور shift_cash/shift_payment_lines).
--  اللي مابيعملوش:
--   - مابيلمسش journal_entries: القيود الحالية (4233 / 634.37 / 552.50) فاضلة،
--     والاعتماد الجاي (save_shift_review) بيقارن بالمترحّل ويعمل قيد تصحيح بالفرق بس.
--   - مابيمسحش مراجعة shift_reviews #555 (تاريخ).
--  آمن للتكرار: كل حاجة مشروطة إن الوردية لسه approved وبالأرقام الغلط بالظبط.
-- =====================================================================

INSERT INTO shift_edit_log (shift_id, user_id, action, before_json, after_json)
SELECT s.id, 1, 'edit',
       JSON_OBJECT('status', s.status, 'cash_total', s.cash_total, 'visa_total', s.visa_total,
                   'instapay_total', s.instapay_total, 'system_total', s.system_total,
                   'difference', s.difference, 'reviewed_by', s.reviewed_by, 'reviewed_at', s.reviewed_at),
       JSON_OBJECT('status', 'submitted', 'cash_total', 4875.00, 'visa_total', 676.45,
                   'instapay_total', 266.00, 'system_total', 5830.72, 'difference', -13.27,
                   'note', 'إعادة فتح للمراجعة — اتعتمدت بأرقام وردية 146 (قرار محمد 25/10)')
  FROM shifts s
 WHERE s.id = 651 AND s.status = 'approved'
   AND s.cash_total = 4233.00 AND s.visa_total = 634.37
   AND s.instapay_total = 552.50 AND s.system_total = 5430.48;

UPDATE shifts
   SET status = 'submitted', reject_reason = NULL, reviewed_by = NULL, reviewed_at = NULL,
       cash_total = 4875.00, visa_total = 676.45, instapay_total = 266.00,
       system_total = 5830.72, difference = -13.27
 WHERE id = 651 AND status = 'approved'
   AND cash_total = 4233.00 AND visa_total = 634.37
   AND instapay_total = 552.50 AND system_total = 5430.48;

INSERT IGNORE INTO schema_migrations (name) VALUES ('20261025_013_reopen_shift_651');
