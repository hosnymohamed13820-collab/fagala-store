-- =====================================================================
-- diag_redux_invoices.sql — فواتير البيع والشراء فين في قاعدة إشراقة (Redux)؟
-- 🔴 قراية بس — مفيش أي تعديل. شغّله في SSMS على جهاز إشراقة على قاعدة ReduxSalesPUB2.
--    قبل التشغيل: Ctrl+T (Results to Text) — وبعد ما يخلص: File ← Save Results As
--    واحفظه diag_redux_invoices.txt وابعته لكلود.
-- ليه: عايزين الوكيل يسحب فواتير إشراقة (بيع للفروع + شراء من الموردين)
--      عشان نطابقها صنف بصنف مع مشتريات الفروع من «الإدارة».
-- =====================================================================
USE ReduxSalesPUB2;
SET NOCOUNT ON;

-- ١) الجداول اللي اسمها شكله فواتير/حركات/عملاء/موردين + عدد صفوفها
SELECT t.name AS table_name, SUM(p.rows) AS row_count
  FROM sys.tables t
  JOIN sys.partitions p ON p.object_id = t.object_id AND p.index_id IN (0,1)
 WHERE t.name LIKE '%Invoice%' OR t.name LIKE '%Inv%' OR t.name LIKE '%Bill%'
    OR t.name LIKE '%Sale%'    OR t.name LIKE '%Purch%' OR t.name LIKE '%Buy%'
    OR t.name LIKE '%Trans%'   OR t.name LIKE '%Doc%'   OR t.name LIKE '%Move%'
    OR t.name LIKE '%Order%'   OR t.name LIKE '%Return%'
    OR t.name LIKE '%Customer%' OR t.name LIKE '%Client%' OR t.name LIKE '%Supplier%'
    OR t.name LIKE '%Vendor%'  OR t.name LIKE '%Account%' OR t.name LIKE '%Person%'
 GROUP BY t.name
 ORDER BY t.name;

-- ٢) كل الجداول وعدد صفوفها (لو أسماء الفواتير مش إنجليزي واضح)
SELECT t.name AS table_name, SUM(p.rows) AS row_count
  FROM sys.tables t
  JOIN sys.partitions p ON p.object_id = t.object_id AND p.index_id IN (0,1)
 GROUP BY t.name
HAVING SUM(p.rows) > 0
 ORDER BY SUM(p.rows) DESC;

-- ٣) أعمدة الجداول اللي في (١)
SELECT c.TABLE_NAME, c.ORDINAL_POSITION, c.COLUMN_NAME, c.DATA_TYPE
  FROM INFORMATION_SCHEMA.COLUMNS c
 WHERE c.TABLE_NAME LIKE '%Invoice%' OR c.TABLE_NAME LIKE '%Inv%' OR c.TABLE_NAME LIKE '%Bill%'
    OR c.TABLE_NAME LIKE '%Sale%'    OR c.TABLE_NAME LIKE '%Purch%' OR c.TABLE_NAME LIKE '%Buy%'
    OR c.TABLE_NAME LIKE '%Trans%'   OR c.TABLE_NAME LIKE '%Doc%'   OR c.TABLE_NAME LIKE '%Move%'
    OR c.TABLE_NAME LIKE '%Order%'   OR c.TABLE_NAME LIKE '%Return%'
    OR c.TABLE_NAME LIKE '%Customer%' OR c.TABLE_NAME LIKE '%Client%' OR c.TABLE_NAME LIKE '%Supplier%'
    OR c.TABLE_NAME LIKE '%Vendor%'  OR c.TABLE_NAME LIKE '%Account%' OR c.TABLE_NAME LIKE '%Person%'
 ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION;

-- ٤) فين أسماء الفروع؟ بيدوّر في كل عمود نصّي في جداول العملاء/الموردين/الحسابات
DECLARE @sql NVARCHAR(MAX) = N'';
SELECT @sql = @sql + N'
SELECT TOP 5 ''' + c.TABLE_NAME + N''' AS tbl, ''' + c.COLUMN_NAME + N''' AS col, CAST([' + c.COLUMN_NAME + N'] AS NVARCHAR(200)) AS val
  FROM [' + c.TABLE_NAME + N']
 WHERE [' + c.COLUMN_NAME + N'] LIKE N''%دجلة%'' OR [' + c.COLUMN_NAME + N'] LIKE N''%بونجور%''
    OR [' + c.COLUMN_NAME + N'] LIKE N''%ABC%''   OR [' + c.COLUMN_NAME + N'] LIKE N''%اي بي سي%''
    OR [' + c.COLUMN_NAME + N'] LIKE N''%الفجالة%'' OR [' + c.COLUMN_NAME + N'] LIKE N''%ادارة%''
    OR [' + c.COLUMN_NAME + N'] LIKE N''%الإدارة%'' OR [' + c.COLUMN_NAME + N'] LIKE N''%حسني%'';'
  FROM INFORMATION_SCHEMA.COLUMNS c
  JOIN INFORMATION_SCHEMA.TABLES t ON t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE'
 WHERE c.DATA_TYPE IN ('nvarchar','varchar','nchar','char')
   AND (c.TABLE_NAME LIKE '%Customer%' OR c.TABLE_NAME LIKE '%Client%' OR c.TABLE_NAME LIKE '%Supplier%'
     OR c.TABLE_NAME LIKE '%Vendor%'  OR c.TABLE_NAME LIKE '%Account%' OR c.TABLE_NAME LIKE '%Person%');
EXEC sp_executesql @sql;

-- ٥) أول ٣ صفوف من كل جدول في (١) فيه داتا — عشان نشوف الأرقام بعينينا
DECLARE @s2 NVARCHAR(MAX) = N'';
SELECT @s2 = @s2 + N'
PRINT ''===== ' + t.name + N' ====='';
SELECT TOP 3 * FROM [' + t.name + N'] ORDER BY 1 DESC;'
  FROM sys.tables t
 WHERE (t.name LIKE '%Invoice%' OR t.name LIKE '%Inv%' OR t.name LIKE '%Bill%'
    OR t.name LIKE '%Sale%'    OR t.name LIKE '%Purch%' OR t.name LIKE '%Buy%'
    OR t.name LIKE '%Trans%'   OR t.name LIKE '%Doc%'   OR t.name LIKE '%Move%'
    OR t.name LIKE '%Return%'
    OR t.name LIKE '%Customer%' OR t.name LIKE '%Client%' OR t.name LIKE '%Supplier%'
    OR t.name LIKE '%Vendor%'  OR t.name LIKE '%Account%')
   AND EXISTS (SELECT 1 FROM sys.partitions p WHERE p.object_id = t.object_id AND p.index_id IN (0,1) AND p.rows > 0);
EXEC sp_executesql @s2;
