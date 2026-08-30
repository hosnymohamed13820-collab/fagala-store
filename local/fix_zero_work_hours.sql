-- ==============================================================================
-- ⚠️  الملف ده مايترفعش على الدومين — بيتشغّل من phpMyAdmin وبس.
-- ==============================================================================
--
-- إصلاح أثري: صفوف attendance فيها check_in و check_out الاتنين موجودين
-- بس work_hours = 0 أو NULL. ده حصل بسبب بق في approve_attendance_request()
-- اللي كان بيسيب work_hours صفر لما الطلب فيه طرف واحد بس.
--
-- 🔴 الحتة (١) تتشغّل الأول — ومحمد يراجع الناتج قبل ما ينفّذ الحتة (٢).
--
-- 🔴 الرواتب اللي اتصرفت قبل كده مابتتغيّرش — جدول salaries بيخزّن
-- work_hours كصورة وقت الصرف. التصليح ده بيأثر على الفترة اللي لسه
-- ما اتصرفتش بس.
--
-- 🔴 السكربت آمن للتكرار: الصف اللي اتصلّح مابقاش = 0 فمش هيتاخد تاني.
--
-- ⚠️ IF(a.check_out < a.check_in, 1440, 0) ← دي اللي بتخلي الورديات اللي
-- بتعدّي منتصف الليل (مثلاً 12:25 → 03:29) تطلع ١٥.٠٧ ساعة مش سالب ٨.٩٣.
-- من غيرها السكربت بيدمّر البيانات.
-- ==============================================================================

-- (١) معاينة الأول — اطبع اللي هيتغيّر قبل ما تغيّره
SELECT a.id, e.full_name, a.att_date, a.check_in, a.check_out,
       a.work_hours AS الساعات_دلوقتي,
       ROUND((TIMESTAMPDIFF(MINUTE, a.check_in, a.check_out)
              + IF(a.check_out < a.check_in, 1440, 0)) / 60, 2) AS الساعات_الصح
  FROM attendance a
  JOIN employees e ON e.id = a.employee_id
 WHERE a.check_in IS NOT NULL
   AND a.check_out IS NOT NULL
   AND (a.work_hours IS NULL OR a.work_hours = 0)
 ORDER BY a.att_date DESC;

-- (٢) بعد ما تراجع الناتج فوق وتتأكد — نفّذ ده
UPDATE attendance a
   SET a.work_hours = ROUND((TIMESTAMPDIFF(MINUTE, a.check_in, a.check_out)
                             + IF(a.check_out < a.check_in, 1440, 0)) / 60, 2)
 WHERE a.check_in IS NOT NULL
   AND a.check_out IS NOT NULL
   AND (a.work_hours IS NULL OR a.work_hours = 0);
