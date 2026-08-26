-- ===========================================================================
--  delete_bad_invoice.sql
--  مسح الفاتورة التجريبية الغلط من نظام الشركة.
--
--  الفاتورة:  STH_ID = 100000019   (رقم مستند 2، تاريخ 13/07/2026، بقرش)
--             STH_ID_REF = 100000024
--             ATH_ID = 100000019, ATH_ID_REF = 100000023
--
--  ليه بنمسحها؟
--     اترحّلت بفهرس ناقص (ps_id, cur_id, net_value فاضيين)، فالبرنامج
--     مايعرفش يعرض أصنافها ولا يرجّعها. ومش قادر يحذفها بنفسه.
--
--  ⚠️ قبل التشغيل:
--     1. الباك أب موجود؟  (fagala_20260713_before_migrate.bak)
--     2. شغّل على قاعدة Anzmah_FagalaHQ  (مش master)
--
--  الأمان:
--     - transaction واحدة: أي فشل = ROLLBACK كامل
--     - بيتحقق من العدد قبل الـ COMMIT
--     - بيطبع النتيجة قبل وبعد
-- ===========================================================================

USE Anzmah_FagalaHQ;
GO

SET XACT_ABORT ON;   -- أي خطأ = rollback تلقائي

DECLARE @sth_id     BIGINT = 100000019;
DECLARE @sth_id_ref BIGINT = 100000024;
DECLARE @ath_id     BIGINT = 100000019;
DECLARE @ath_id_ref BIGINT = 100000023;

BEGIN TRANSACTION;

BEGIN TRY

    -- ---------- قبل ----------
    PRINT '=== قبل المسح ===';
    SELECT 'index' AS tbl, COUNT(*) AS c FROM sto_trans_index          WHERE sth_id = @sth_id
    UNION ALL SELECT 'header',    COUNT(*) FROM STO_TRANS_HEADER_26_07   WHERE sth_id = @sth_id
    UNION ALL SELECT 'lines',     COUNT(*) FROM STO_TRANS_LINES_26_07    WHERE sth_id = @sth_id
    UNION ALL SELECT 'itemlog',   COUNT(*) FROM STO_TRANS_ITEM_LOG_26_07 WHERE sth_id = @sth_id
    UNION ALL SELECT 'pos',       COUNT(*) FROM STO_TRANS_POS_26_07      WHERE sth_id = @sth_id
    UNION ALL SELECT 'acc_hdr',   COUNT(*) FROM ACC_TRANS_HEADER_26_07   WHERE ath_id = @ath_id
    UNION ALL SELECT 'acc_lines', COUNT(*) FROM ACC_TRANS_LINES_26_07    WHERE ath_id = @ath_id
    UNION ALL SELECT 'acc_log',   COUNT(*) FROM ACC_TRANS_LOG_26_07      WHERE ath_id = @ath_id
    UNION ALL SELECT 'acc_idx',   COUNT(*) FROM acc_trans_index          WHERE ath_id = @ath_id;


    -- ---------- المسح (من التفاصيل للرأس) ----------

    -- القيد المحاسبي
    DELETE FROM ACC_TRANS_LOG_26_07    WHERE ath_id = @ath_id;
    DELETE FROM ACC_TRANS_LINES_26_07  WHERE ath_id = @ath_id;
    DELETE FROM ACC_TRANS_HEADER_26_07 WHERE ath_id = @ath_id;
    DELETE FROM acc_trans_index        WHERE ath_id = @ath_id;

    -- المخزون والدفع والأصناف
    DELETE FROM STO_TRANS_ITEM_LOG_26_07 WHERE sth_id = @sth_id;
    DELETE FROM STO_TRANS_POS_26_07      WHERE sth_id = @sth_id;
    DELETE FROM STO_TRANS_LINES_26_07    WHERE sth_id = @sth_id;

    -- رأس الفاتورة والفهرس
    DELETE FROM STO_TRANS_HEADER_26_07 WHERE sth_id = @sth_id;
    DELETE FROM sto_trans_index        WHERE sth_id = @sth_id;

    -- طابور المزامنة (اللي لسه ماتصدّرش)
    DELETE FROM db_repl_send
    WHERE drs_text LIKE '%' + CAST(@sth_id_ref AS VARCHAR(20)) + '%';


    -- ---------- التحقق ----------
    DECLARE @left INT = (
        SELECT
            (SELECT COUNT(*) FROM sto_trans_index          WHERE sth_id = @sth_id) +
            (SELECT COUNT(*) FROM STO_TRANS_HEADER_26_07   WHERE sth_id = @sth_id) +
            (SELECT COUNT(*) FROM STO_TRANS_LINES_26_07    WHERE sth_id = @sth_id) +
            (SELECT COUNT(*) FROM STO_TRANS_ITEM_LOG_26_07 WHERE sth_id = @sth_id) +
            (SELECT COUNT(*) FROM STO_TRANS_POS_26_07      WHERE sth_id = @sth_id) +
            (SELECT COUNT(*) FROM ACC_TRANS_HEADER_26_07   WHERE ath_id = @ath_id) +
            (SELECT COUNT(*) FROM ACC_TRANS_LINES_26_07    WHERE ath_id = @ath_id) +
            (SELECT COUNT(*) FROM ACC_TRANS_LOG_26_07      WHERE ath_id = @ath_id) +
            (SELECT COUNT(*) FROM acc_trans_index          WHERE ath_id = @ath_id)
    );

    IF @left > 0
    BEGIN
        RAISERROR('لسه فيه %d صف — تم التراجع', 16, 1, @left);
    END

    COMMIT TRANSACTION;
    PRINT '=== تم المسح بالكامل ===';

END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    PRINT '=== فشل — تم التراجع، مفيش حاجة اتمسحت ===';
    PRINT ERROR_MESSAGE();
END CATCH
GO


-- ---------------------------------------------------------------------------
--  التأكيد بعد التشغيل — لازم كلهم صفر
-- ---------------------------------------------------------------------------
SELECT 'index' AS tbl, COUNT(*) AS c FROM sto_trans_index          WHERE sth_id = 100000019
UNION ALL SELECT 'header',    COUNT(*) FROM STO_TRANS_HEADER_26_07   WHERE sth_id = 100000019
UNION ALL SELECT 'lines',     COUNT(*) FROM STO_TRANS_LINES_26_07    WHERE sth_id = 100000019
UNION ALL SELECT 'itemlog',   COUNT(*) FROM STO_TRANS_ITEM_LOG_26_07 WHERE sth_id = 100000019
UNION ALL SELECT 'pos',       COUNT(*) FROM STO_TRANS_POS_26_07      WHERE sth_id = 100000019
UNION ALL SELECT 'acc_hdr',   COUNT(*) FROM ACC_TRANS_HEADER_26_07   WHERE ath_id = 100000019
UNION ALL SELECT 'acc_lines', COUNT(*) FROM ACC_TRANS_LINES_26_07    WHERE ath_id = 100000019
UNION ALL SELECT 'acc_log',   COUNT(*) FROM ACC_TRANS_LOG_26_07      WHERE ath_id = 100000019
UNION ALL SELECT 'acc_idx',   COUNT(*) FROM acc_trans_index          WHERE ath_id = 100000019;
