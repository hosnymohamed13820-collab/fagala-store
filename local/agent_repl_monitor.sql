-- =====================================================================
-- مراقبة طابور ربط سيستم الفروع — بريف ٥٠ (٢٣ سبتمبر ٢٠٢٦)
-- 🔴 شغّله من phpMyAdmin **قبل** رفع الملفات — على قاعدة الفجالة (وإشراقة كمان عشان الجدول يفضل واحد)
-- كل الأعمدة NULL = «الوكيل لسه على النسخة القديمة / مابيقراش الطابور».
-- =====================================================================
ALTER TABLE agent_status
    ADD COLUMN repl_waiting    INT          NULL COMMENT 'أوامر مستنية في db_repl_receive',
    ADD COLUMN repl_age_min    INT          NULL COMMENT 'دقايق من وصول أقدم أمر مستني',
    ADD COLUMN repl_first_id   BIGINT       NULL COMMENT 'drr_id بتاع أول أمر واقف',
    ADD COLUMN repl_first_text VARCHAR(255) NULL COMMENT 'أول ٢٠٠ حرف من الأمر الواقف',
    ADD COLUMN repl_checked_at DATETIME     NULL COMMENT 'آخر مرة الوكيل قرا الطابور',
    ADD COLUMN repl_alerted_at DATETIME     NULL COMMENT 'إمتى اتبعت إشعار الوقفة الحالية — بيتصفّر لما الطابور يفضى';
