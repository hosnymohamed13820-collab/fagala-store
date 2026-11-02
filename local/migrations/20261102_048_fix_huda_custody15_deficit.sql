-- بريف ٨٦١ب: قرار محمد ٣/١١ بإعادة فتح عهدة هدى 15 للمراجعة.
-- custody_reverse_order خطوة عادية في الاعتماد، مش دليل إن العجز وهمي.
-- بنعكس قيود الاعتماد التلاتة، وبنوقف خصم العجز القديم لحد المراجعة الجديدة.
-- الأصول 2586–2588 وسجل المراجعات وبيانات تقديم العهدة بيفضلوا زي ما هم.
-- الرئيسية صافيها صفر (-9110 ثم +9110)، وخزينة الموظف بتزيد 9110.
-- زي post_journal بالظبط: الرصيد الحالي تحت قفل + الدائن - المدين.
-- آمن للتكرار: العلامة مع الفلوس جوه نفس المعاملة. أي اختلاف أو خصم مرتب بيوقف الكل.
-- قاعدة تانية من غير نفس هوية العهدة: تخطي من غير لمس الفلوس.
-- يتشغّل كامل في mysql client أو phpMyAdmin بدعم DELIMITER وCREATE ROUTINE.
-- مش بسكربت migrate_server.sh الحالي: تقسيمه للأوامر مش بيدعم stored procedures.
SET @brief861_ok = 0;
DROP PROCEDURE IF EXISTS brief861_fix_huda_deficit;
DELIMITER $$
CREATE PROCEDURE brief861_fix_huda_deficit()
fix: BEGIN
    DECLARE custody_status VARCHAR(30) DEFAULT NULL;
    DECLARE deficit_status VARCHAR(30) DEFAULT NULL;
    DECLARE recovered_salary BIGINT DEFAULT NULL;
    DECLARE balance11 DECIMAL(14,2) DEFAULT NULL;
    DECLARE balance13 DECIMAL(14,2) DEFAULT NULL;
    DECLARE locked_id BIGINT DEFAULT NULL;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET @brief861_ok = 0;
        RESIGNAL;
    END;

    START TRANSACTION;
    SELECT status INTO custody_status FROM branch_custody
     WHERE id=15 AND employee_id=3 AND branch_id=6 AND custody_treasury_id=13 FOR UPDATE;
    IF custody_status IS NULL THEN
        COMMIT;
        SET @brief861_ok = 1;
        LEAVE fix;
    END IF;
    IF EXISTS (SELECT 1 FROM schema_migrations WHERE name='20261102_048_fix_huda_custody15_deficit') THEN
        COMMIT;
        SET @brief861_ok = 1;
        LEAVE fix;
    END IF;
    IF (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME IN ('branch_custody','custody_deficits','journal_entries','treasuries','schema_migrations') AND ENGINE='InnoDB') <> 5 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='brief861: transactional tables required';
    END IF;
    IF custody_status <> 'closed' OR NOT EXISTS (
        SELECT 1 FROM branch_custody WHERE id=15
          AND opening_balance=9110.00 AND total_expenses=0.00
          AND counted_cash=0.00 AND accountant_counted=0.00 AND difference=-9110.00
          AND submitted_at>='2026-09-28 18:50:00' AND submitted_at<'2026-09-28 18:51:00'
          AND reviewed_by=15 AND reviewed_at='2026-10-22 15:34:48'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='brief861: custody snapshot mismatch';
    END IF;

    -- قفل العجز بيمنع خصمه في مرتب بالتزامن مع الإلغاء.
    SELECT status,recovered_in_salary_id INTO deficit_status,recovered_salary
      FROM custody_deficits WHERE id=3 FOR UPDATE;
    IF recovered_salary IS NOT NULL OR deficit_status='recovered' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='brief861: deficit already recovered in salary';
    END IF;
    IF deficit_status IS NULL OR deficit_status <> 'pending' OR NOT EXISTS (
        SELECT 1 FROM custody_deficits WHERE id=3 AND custody_id=15 AND employee_id=3
          AND branch_id=6 AND amount=9110.00 AND recovered_in_salary_id IS NULL
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='brief861: deficit snapshot mismatch';
    END IF;

    -- قفل الخزاين بترتيب أرقامها؛ أرصدتها الحالية مش لقطة ثابتة.
    SELECT balance INTO balance11 FROM treasuries WHERE id=11 AND type='main' FOR UPDATE;
    SELECT balance INTO balance13 FROM treasuries WHERE id=13 AND type='employee_custody' FOR UPDATE;
    IF balance11 IS NULL OR balance13 IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='brief861: treasury missing';
    END IF;
    SELECT id INTO locked_id FROM journal_entries WHERE id=2586 FOR UPDATE;
    SELECT id INTO locked_id FROM journal_entries WHERE id=2587 FOR UPDATE;
    SELECT id INTO locked_id FROM journal_entries WHERE id=2588 FOR UPDATE;
    IF (SELECT COUNT(*) FROM journal_entries WHERE ref_id=15 AND ref_type IN ('custody_reverse_order','custody_deficit')) <> 3
       OR (SELECT COUNT(*) FROM journal_entries WHERE ref_id=15 AND (
           (id=2586 AND treasury_id=11 AND account_id=240 AND debit=0 AND credit=9110.00 AND ref_type='custody_reverse_order' AND entry_date='2026-09-28')
        OR (id=2587 AND treasury_id=13 AND account_id IS NULL AND debit=9110.00 AND credit=0 AND ref_type='custody_reverse_order' AND entry_date='2026-09-28')
        OR (id=2588 AND treasury_id=11 AND account_id=240 AND debit=9110.00 AND credit=0 AND ref_type='custody_deficit')
       )) <> 3 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='brief861: original journals mismatch';
    END IF;
    IF EXISTS (SELECT 1 FROM journal_entries WHERE ref_type='custody_reopen' AND ref_id=15)
       OR EXISTS (SELECT 1 FROM journal_entries WHERE ref_type='custody_deficit_reverse' AND ref_id=15) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='brief861: reversal exists without migration marker';
    END IF;

    SET balance11 = balance11 - 9110.00;
    INSERT INTO journal_entries (treasury_id,entry_date,description,account_id,debit,credit,running_balance,ref_type,ref_id,created_by)
    VALUES (11,'2026-11-03','إعادة فتح عهدة هدى عبدالفتاح للمراجعة — عكس قيد #2586',240,9110.00,0,balance11,'custody_reopen',15,1);
    UPDATE treasuries SET balance=balance11 WHERE id=11;

    SET balance13 = balance13 + 9110.00;
    INSERT INTO journal_entries (treasury_id,entry_date,description,account_id,debit,credit,running_balance,ref_type,ref_id,created_by)
    VALUES (13,'2026-11-03','إعادة فتح عهدة هدى عبدالفتاح للمراجعة — عكس قيد #2587',NULL,0,9110.00,balance13,'custody_reopen',15,1);
    UPDATE treasuries SET balance=balance13 WHERE id=13;

    SET balance11 = balance11 + 9110.00;
    INSERT INTO journal_entries (treasury_id,entry_date,description,account_id,debit,credit,running_balance,ref_type,ref_id,created_by)
    VALUES (11,'2026-11-03','إعادة فتح عهدة هدى عبدالفتاح للمراجعة — عكس قيد #2588',240,0,9110.00,balance11,'custody_reopen',15,1);
    UPDATE treasuries SET balance=balance11 WHERE id=11;
    UPDATE custody_deficits SET status='waived',
        notes=CONCAT_WS(' — ',NULLIF(notes,''),'اتلغى ٣/١١ — العهدة اتفتحت للمراجعة تاني (بريف ٨٦١ب)') WHERE id=3;

    UPDATE branch_custody SET status='pending_review', reviewed_by=NULL, reviewed_at=NULL,
        closed_by=NULL, closed_at=NULL, accountant_counted=NULL, settled_amount=NULL WHERE id=15;

    -- العلامة جوه نفس المعاملة، عشان إعادة التشغيل ماترجّعش الفلوس مرتين.
    INSERT IGNORE INTO schema_migrations (name) VALUES ('20261102_048_fix_huda_custody15_deficit');
    COMMIT;
    SET @brief861_ok = 1;
END$$
DELIMITER ;
CALL brief861_fix_huda_deficit();
DROP PROCEDURE IF EXISTS brief861_fix_huda_deficit;
-- لو الأداة كملت بعد خطأ، العلامة ماتتسجلش بالغلط.
INSERT IGNORE INTO schema_migrations (name) SELECT '20261102_048_fix_huda_custody15_deficit' WHERE @brief861_ok = 1;
