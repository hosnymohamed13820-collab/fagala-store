-- =====================================================================
-- diag_redux_prices.sql — فين سعر البيع في قاعدة إشراقة (Redux)؟
-- 🔴 قراية بس — مفيش أي تعديل. شغّله في SSMS على جهاز إشراقة،
--    على قاعدة ReduxSalesPUB2، وابعت الخرج (الأربع نتايج) لكلود.
-- =====================================================================
USE ReduxSalesPUB2;
SET NOCOUNT ON;

-- ١) كل عمود في القاعدة اسمه فيه «سعر» — عشان نعرف السعر متخزّن فين
SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE
  FROM INFORMATION_SCHEMA.COLUMNS
 WHERE COLUMN_NAME LIKE '%price%' OR COLUMN_NAME LIKE '%sale%'
    OR COLUMN_NAME LIKE '%sell%' OR COLUMN_NAME LIKE '%cost%'
    OR COLUMN_NAME LIKE N'%سعر%'
 ORDER BY TABLE_NAME, COLUMN_NAME;

-- ٢) أعمدة جدول الأصناف نفسه
SELECT COLUMN_NAME, DATA_TYPE
  FROM INFORMATION_SCHEMA.COLUMNS
 WHERE TABLE_NAME = 'Item'
 ORDER BY ORDINAL_POSITION;

-- ٣) عينة ٥ أصناف بكل أعمدتها — عشان نشوف الأرقام بعينينا
SELECT TOP 5 * FROM Item WHERE MainBarcode IS NOT NULL;

-- ٤) المخازن وعدد الأصناف اللي ليها رصيد في كل واحد
SELECT id_store, COUNT(*) AS items, SUM(CASE WHEN Balance_UNIT_Basic <> 0 THEN 1 ELSE 0 END) AS with_balance
  FROM SEARCH_ITEMS
 GROUP BY id_store
 ORDER BY id_store;
