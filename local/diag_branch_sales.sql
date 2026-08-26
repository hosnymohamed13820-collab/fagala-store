-- =====================================================================
--  diag_branch_sales.sql
--  Run on the HQ PC, in SSMS, against database: Anzmah_FagalaHQ
--  Read-only. Nothing is written. Safe to run any time.
--
--  ONE QUESTION: does the HQ server already hold the SALES of the
--  four branches, or only the HQ store's own movements?
--
--  If YES -> the whole manual upload disappears. No branch agents.
--  If NO  -> we need an agent per branch (the on-hold path).
-- =====================================================================

-- ---------------------------------------------------------------------
-- (1) Which monthly movement tables exist?
-- ---------------------------------------------------------------------
SELECT TABLE_NAME
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_TYPE = 'BASE TABLE'
  AND TABLE_NAME LIKE 'STO_TRANS_HEADER[_][0-9][0-9][_][0-9][0-9]'
ORDER BY TABLE_NAME;


-- ---------------------------------------------------------------------
-- (2) Where does the store id live - header or lines?
--     (the purchase sync uses BRCH_ID, which is the COMPANY branch,
--      not the STORE. we need the store.)
-- ---------------------------------------------------------------------
SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME IN ('STO_TRANS_HEADER_YY_MM', 'STO_TRANS_LINES_YY_MM')
  AND (COLUMN_NAME LIKE '%STO%' OR COLUMN_NAME LIKE '%BRCH%'
       OR COLUMN_NAME LIKE '%SSTT%' OR COLUMN_NAME LIKE '%QTY%'
       OR COLUMN_NAME LIKE '%DATE%')
ORDER BY TABLE_NAME, ORDINAL_POSITION;


-- ---------------------------------------------------------------------
-- (3) The stores we care about
-- ---------------------------------------------------------------------
SELECT STO_ID, LTRIM(RTRIM(STO_NAME1)) AS store_name
FROM STORES WHERE STO_ID > 0 ORDER BY STO_ID;


-- ---------------------------------------------------------------------
-- (4) 🔴 THE ANSWER.
--     Document types present in the CURRENT month table, per store.
--     SSTT_ID: 2/6 = purchases (already synced), 4 = sales.
--
--     If rows come back for STO_ID 2, 3, 5 and 6 -> branch sales ARE
--     here, and one query at HQ replaces all five uploads.
--     If only one STO_ID shows up -> they are not.
--
--  ⚠️ If this errors with "Invalid column name 'STO_ID'", the store id
--     sits on the LINES table instead. Send me the error and the result
--     of query (2) and I will flip it.
-- ---------------------------------------------------------------------
SELECT h.STO_ID       AS store_id,
       h.SSTT_ID      AS doc_type,
       COUNT(*)       AS docs,
       MIN(h.STH_DOC_DATE) AS first_doc,
       MAX(h.STH_DOC_DATE) AS last_doc
FROM STO_TRANS_HEADER_YY_MM h
GROUP BY h.STO_ID, h.SSTT_ID
ORDER BY h.STO_ID, h.SSTT_ID;


-- ---------------------------------------------------------------------
-- (5) Same question, but across a full season (all monthly tables).
--     Change the two dates to your season, then run.
--     Uncomment after query (4) confirms the column name.
-- ---------------------------------------------------------------------
-- DECLARE @from DATE = '2025-07-01', @to DATE = '2026-06-30';
-- SELECT h.STO_ID AS store_id, h.SSTT_ID AS doc_type,
--        COUNT(*) AS docs, SUM(l.STL_QTY) AS qty
-- FROM STO_TRANS_HEADER_25_07 h
-- JOIN STO_TRANS_LINES_25_07  l ON l.STH_ID = h.STH_ID
-- WHERE h.STH_DOC_DATE BETWEEN @from AND @to
-- GROUP BY h.STO_ID, h.SSTT_ID
-- ORDER BY h.STO_ID, h.SSTT_ID;


-- ---------------------------------------------------------------------
-- (6) A sample sales document - so I can see the real column names
--     and how quantity/sign is stored before writing any query.
-- ---------------------------------------------------------------------
SELECT TOP 3 * FROM STO_TRANS_HEADER_YY_MM WHERE SSTT_ID = 4;
SELECT TOP 5 * FROM STO_TRANS_LINES_YY_MM;
