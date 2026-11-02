-- بريف ٨٥٥: طلب محمد ٢/١١ إعادة فتح عهدة بونجور 21 للمراجعة.
-- القيود الأصلية والمصاريف وسجل المراجعات بيفضلوا زي ما هم؛ مفيش جدول log لإعادة الفتح.
-- الأثر محفوظ هنا وفي أوصاف القيود العكسية بالمستخدم 1 والتاريخ 2026-11-02.
-- زي post_journal بالظبط: الرصيد = الحالي + الدائن - المدين.
-- من صفر: خزينة 6 -> -12086 -> +1964، وخزينة 11 بتنقص 14050.
-- مش -1964: الإشارة دي في البريف عكس الكود والقيود الأصلية.
-- بيتشغّل كملف كامل في mysql client أو phpMyAdmin بدعم DELIMITER وCREATE ROUTINE.
-- مش بسكربت migrate_server.sh الحالي: تقسيمه للأوامر مش بيدعم stored procedures.
-- آمن للتكرار حتى بعد إعادة الاعتماد: علامة الترحيل جوه نفس المعاملة، ومفيش عكس تاني.
-- قاعدة تانية من غير نفس هوية العهدة: تخطي من غير لمس الفلوس.
SET @brief855_ok = 0;
DROP PROCEDURE IF EXISTS brief855_reopen_custody;
DELIMITER $$
CREATE PROCEDURE brief855_reopen_custody()
reopen: BEGIN
    DECLARE custody_status VARCHAR(30) DEFAULT NULL;
    DECLARE balance6 DECIMAL(14,2) DEFAULT NULL;
    DECLARE balance11 DECIMAL(14,2) DEFAULT NULL;
    DECLARE previous6 DECIMAL(14,2) DEFAULT NULL;
    DECLARE previous11 DECIMAL(14,2) DEFAULT NULL;
    DECLARE locked_id BIGINT DEFAULT NULL;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET @brief855_ok = 0;
        RESIGNAL;
    END;

    START TRANSACTION;
    SELECT status INTO custody_status FROM branch_custody
     WHERE id=21 AND branch_id=1 AND custody_treasury_id=6 AND employee_id IS NULL
       AND period_start='2026-10-01' AND period_end='2026-10-31' FOR UPDATE;
    IF custody_status IS NULL THEN
        COMMIT;
        SET @brief855_ok = 1;
        LEAVE reopen;
    END IF;
    IF EXISTS (SELECT 1 FROM schema_migrations WHERE name='20261102_046_reopen_bonjour_custody_21') THEN
        COMMIT;
        SET @brief855_ok = 1;
        LEAVE reopen;
    END IF;

    IF custody_status <> 'closed' OR NOT EXISTS (
        SELECT 1 FROM branch_custody WHERE id=21
          AND opening_balance=12300.00 AND total_expenses=10336.00 AND closing_balance=1964.00
          AND counted_cash=14050.00 AND accountant_counted=14050.00
          AND difference=12086.00 AND settled_amount=14050.00
          AND submitted_by=1 AND submitted_at='2026-10-31 22:46:54'
          AND reviewed_by=15 AND reviewed_at='2026-10-31 22:47:09'
          AND closed_by=15 AND closed_at='2026-10-31 22:47:09' AND responsible_employee_id=11
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='brief855: custody snapshot mismatch';
    END IF;
    IF (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME IN ('branch_custody','journal_entries','treasuries','schema_migrations') AND ENGINE='InnoDB') <> 4 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='brief855: transactional tables required';
    END IF;

    -- القفل بنفس ترتيب أرقام الخزاين، قبل قراءة الرصيد أو كتابة أي قيد.
    SELECT balance INTO balance6 FROM treasuries WHERE id=6 AND type='branch_custody' AND branch_id=1 FOR UPDATE;
    SELECT balance INTO balance11 FROM treasuries WHERE id=11 AND type='main' FOR UPDATE;
    IF balance6 IS NULL OR balance11 IS NULL OR balance6 <> 0.00 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='brief855: treasury snapshot mismatch';
    END IF;
    SELECT id INTO locked_id FROM journal_entries WHERE id=3073 FOR UPDATE;
    SELECT id INTO locked_id FROM journal_entries WHERE id=3074 FOR UPDATE;
    SELECT id INTO locked_id FROM journal_entries WHERE id=3075 FOR UPDATE;
    IF (SELECT COUNT(*) FROM journal_entries WHERE ref_id=21 AND ref_type IN ('custody_surplus','custody_settlement','custody_deficit')) <> 3
       OR (SELECT COUNT(*) FROM journal_entries WHERE ref_id=21 AND (
           (id=3073 AND treasury_id=6 AND account_id IS NULL AND debit=0 AND credit=12086.00 AND ref_type='custody_surplus' AND running_balance=14050.00)
        OR (id=3074 AND treasury_id=6 AND account_id IS NULL AND debit=14050.00 AND credit=0 AND ref_type='custody_settlement' AND running_balance=0.00)
        OR (id=3075 AND treasury_id=11 AND account_id=73 AND debit=0 AND credit=14050.00 AND ref_type='custody_settlement')
       )) <> 3 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='brief855: original journals mismatch';
    END IF;
    IF EXISTS (SELECT 1 FROM journal_entries WHERE ref_type='custody_reopen' AND ref_id=21) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='brief855: reversals exist without migration marker';
    END IF;

    SELECT running_balance INTO previous6 FROM journal_entries WHERE treasury_id=6 AND id<3073 ORDER BY id DESC LIMIT 1 FOR UPDATE;
    SELECT running_balance INTO previous11 FROM journal_entries WHERE treasury_id=11 AND id<3075 ORDER BY id DESC LIMIT 1 FOR UPDATE;
    -- نثبت إشارة العهدة من القيد السابق؛ الرئيسية ممكن يكون حصل عليها حركات بعد الاعتماد.
    IF previous6 IS NULL OR previous6 <> 1964.00 OR previous11 IS NULL
       OR NOT EXISTS (SELECT 1 FROM journal_entries WHERE id=3075 AND running_balance=previous11+14050.00) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='brief855: pre-approval balances mismatch';
    END IF;

    SET balance6 = balance6 - 12086.00;
    INSERT INTO journal_entries (treasury_id,entry_date,description,account_id,debit,credit,running_balance,ref_type,ref_id,created_by)
    VALUES (6,'2026-11-02','إعادة فتح عهدة بونجور للمراجعة — عكس قيد #3073',NULL,12086.00,0,balance6,'custody_reopen',21,1);
    UPDATE treasuries SET balance=balance6 WHERE id=6;

    SET balance6 = balance6 + 14050.00;
    INSERT INTO journal_entries (treasury_id,entry_date,description,account_id,debit,credit,running_balance,ref_type,ref_id,created_by)
    VALUES (6,'2026-11-02','إعادة فتح عهدة بونجور للمراجعة — عكس قيد #3074',NULL,0,14050.00,balance6,'custody_reopen',21,1);
    UPDATE treasuries SET balance=balance6 WHERE id=6;

    SET balance11 = balance11 - 14050.00;
    INSERT INTO journal_entries (treasury_id,entry_date,description,account_id,debit,credit,running_balance,ref_type,ref_id,created_by)
    VALUES (11,'2026-11-02','إعادة فتح عهدة بونجور للمراجعة — عكس قيد #3075',73,14050.00,0,balance11,'custody_reopen',21,1);
    UPDATE treasuries SET balance=balance11 WHERE id=11;

    UPDATE branch_custody SET status='pending_review', reviewed_by=NULL, reviewed_at=NULL,
        closed_by=NULL, closed_at=NULL, accountant_counted=NULL, settled_amount=NULL WHERE id=21;
    -- العلامة مع الفلوس جوه نفس المعاملة، عشان إعادة الاعتماد ماتسمحش بإعادة العكس.
    INSERT IGNORE INTO schema_migrations (name) VALUES ('20261102_046_reopen_bonjour_custody_21');
    COMMIT;
    SET @brief855_ok = 1;
END$$
DELIMITER ;
CALL brief855_reopen_custody();
DROP PROCEDURE IF EXISTS brief855_reopen_custody;
-- لو الأداة كملت بعد خطأ، العلامة ماتتسجلش بالغلط.
INSERT IGNORE INTO schema_migrations (name) SELECT '20261102_046_reopen_bonjour_custody_21' WHERE @brief855_ok = 1;
