# ===========================================================================
#  export_schema.ps1  --  SQL Server schema extractor (READ ONLY)
#  Pure ASCII: no Arabic, so ANSI/Notepad encoding cannot break it.
#
#  Extracts: tables, columns, PKs, FKs, IDENTITY, sequences,
#            triggers (+code), procedures, views, indexes, row counts.
#  Extracts NO actual row data.
#
#  Run from CMD:
#    cd /d G:\
#    powershell -ExecutionPolicy Bypass -File export_schema.ps1
# ===========================================================================

# ---------- SETTINGS ----------
$Server   = "localhost"
$Database = "Anzmah_FagalaHQ"
$User     = "sa"
$Pass     = "ok"
# ------------------------------

$Root   = Split-Path -Parent $MyInvocation.MyCommand.Path
$OutDir = Join-Path $Root "schema_export"
$ZipOut = Join-Path $Root "schema_export.zip"

if (Test-Path $OutDir) { Remove-Item $OutDir -Recurse -Force }
New-Item -ItemType Directory -Path $OutDir | Out-Null

function Run-Query {
    param([string]$Name, [string]$Sql)
    Write-Host ("  -> " + $Name + " ...") -NoNewline
    $out = Join-Path $OutDir ($Name + ".csv")
    sqlcmd -S $Server -d $Database -U $User -P $Pass -Q $Sql -s "," -W -h -1 -w 8000 -o $out 2>&1 | Out-Null
    if (Test-Path $out) {
        $n = (Get-Content $out | Measure-Object -Line).Lines
        Write-Host (" OK (" + $n + " lines)") -ForegroundColor Green
    } else {
        Write-Host " EMPTY" -ForegroundColor Yellow
    }
}

Write-Host ""
Write-Host ("=== Exporting schema: " + $Database + " ===") -ForegroundColor Cyan
Write-Host ""

Run-Query "01_columns" "SET NOCOUNT ON; SELECT t.TABLE_SCHEMA, t.TABLE_NAME, c.ORDINAL_POSITION, c.COLUMN_NAME, c.DATA_TYPE, ISNULL(CAST(c.CHARACTER_MAXIMUM_LENGTH AS VARCHAR),'') AS MAXLEN, ISNULL(CAST(c.NUMERIC_PRECISION AS VARCHAR),'') AS PREC, ISNULL(CAST(c.NUMERIC_SCALE AS VARCHAR),'') AS SCALE, c.IS_NULLABLE, ISNULL(CAST(c.COLUMN_DEFAULT AS VARCHAR(500)),'') AS COL_DEFAULT, COLUMNPROPERTY(OBJECT_ID(t.TABLE_SCHEMA+'.'+t.TABLE_NAME), c.COLUMN_NAME, 'IsIdentity') AS IS_IDENTITY FROM INFORMATION_SCHEMA.TABLES t JOIN INFORMATION_SCHEMA.COLUMNS c ON c.TABLE_SCHEMA=t.TABLE_SCHEMA AND c.TABLE_NAME=t.TABLE_NAME WHERE t.TABLE_TYPE='BASE TABLE' ORDER BY t.TABLE_NAME, c.ORDINAL_POSITION;"

Run-Query "02_primary_keys" "SET NOCOUNT ON; SELECT tc.TABLE_NAME, kcu.COLUMN_NAME, kcu.ORDINAL_POSITION, tc.CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu ON kcu.CONSTRAINT_NAME=tc.CONSTRAINT_NAME WHERE tc.CONSTRAINT_TYPE='PRIMARY KEY' ORDER BY tc.TABLE_NAME, kcu.ORDINAL_POSITION;"

Run-Query "03_foreign_keys" "SET NOCOUNT ON; SELECT fk.name AS FK_NAME, OBJECT_NAME(fk.parent_object_id) AS FROM_TABLE, cp.name AS FROM_COLUMN, OBJECT_NAME(fk.referenced_object_id) AS TO_TABLE, cr.name AS TO_COLUMN, fk.delete_referential_action_desc AS ON_DELETE, fk.update_referential_action_desc AS ON_UPDATE FROM sys.foreign_keys fk JOIN sys.foreign_key_columns fkc ON fkc.constraint_object_id=fk.object_id JOIN sys.columns cp ON cp.object_id=fkc.parent_object_id AND cp.column_id=fkc.parent_column_id JOIN sys.columns cr ON cr.object_id=fkc.referenced_object_id AND cr.column_id=fkc.referenced_column_id ORDER BY FROM_TABLE;"

Run-Query "04_identity" "SET NOCOUNT ON; SELECT OBJECT_NAME(ic.object_id) AS TABLE_NAME, ic.name AS COLUMN_NAME, ic.seed_value AS SEED, ic.increment_value AS INCR, ic.last_value AS LAST_VALUE FROM sys.identity_columns ic ORDER BY TABLE_NAME;"

Run-Query "05_sequences" "SET NOCOUNT ON; SELECT name, start_value, increment, current_value FROM sys.sequences;"

Run-Query "06_triggers_list" "SET NOCOUNT ON; SELECT OBJECT_NAME(tr.parent_id) AS TABLE_NAME, tr.name AS TRIGGER_NAME, tr.is_disabled, tr.is_instead_of_trigger FROM sys.triggers tr WHERE tr.parent_class=1 ORDER BY TABLE_NAME;"

Run-Query "07_triggers_code" "SET NOCOUNT ON; SELECT OBJECT_NAME(tr.parent_id) AS TABLE_NAME, tr.name AS TRIGGER_NAME, REPLACE(REPLACE(m.definition,CHAR(13),' '),CHAR(10),' ') AS CODE FROM sys.triggers tr JOIN sys.sql_modules m ON m.object_id=tr.object_id WHERE tr.parent_class=1;"

Run-Query "08_procedures" "SET NOCOUNT ON; SELECT p.name AS PROC_NAME, REPLACE(REPLACE(m.definition,CHAR(13),' '),CHAR(10),' ') AS CODE FROM sys.procedures p JOIN sys.sql_modules m ON m.object_id=p.object_id;"

Run-Query "09_views" "SET NOCOUNT ON; SELECT v.name AS VIEW_NAME, REPLACE(REPLACE(m.definition,CHAR(13),' '),CHAR(10),' ') AS CODE FROM sys.views v JOIN sys.sql_modules m ON m.object_id=v.object_id;"

Run-Query "10_indexes" "SET NOCOUNT ON; SELECT OBJECT_NAME(i.object_id) AS TABLE_NAME, i.name AS INDEX_NAME, i.type_desc, i.is_unique, c.name AS COLUMN_NAME, ic.key_ordinal FROM sys.indexes i JOIN sys.index_columns ic ON ic.object_id=i.object_id AND ic.index_id=i.index_id JOIN sys.columns c ON c.object_id=i.object_id AND c.column_id=ic.column_id WHERE i.type>0 AND OBJECTPROPERTY(i.object_id,'IsUserTable')=1 ORDER BY TABLE_NAME, i.name, ic.key_ordinal;"

Run-Query "11_check_constraints" "SET NOCOUNT ON; SELECT OBJECT_NAME(cc.parent_object_id) AS TABLE_NAME, cc.name AS CONSTRAINT_NAME, REPLACE(cc.definition,CHAR(10),' ') AS DEFINITION FROM sys.check_constraints cc;"

Run-Query "12_row_counts" "SET NOCOUNT ON; SELECT t.name AS TABLE_NAME, SUM(p.rows) AS ROW_COUNT FROM sys.tables t JOIN sys.partitions p ON p.object_id=t.object_id AND p.index_id IN (0,1) GROUP BY t.name ORDER BY SUM(p.rows) DESC;"

Run-Query "13_sales_tables" "SET NOCOUNT ON; SELECT t.name AS TABLE_NAME, SUM(p.rows) AS ROW_COUNT FROM sys.tables t JOIN sys.partitions p ON p.object_id=t.object_id AND p.index_id IN (0,1) WHERE t.name LIKE '%SAL%' OR t.name LIKE '%INV%' OR t.name LIKE '%BILL%' OR t.name LIKE '%POS%' OR t.name LIKE '%RETURN%' OR t.name LIKE '%TRANS%' OR t.name LIKE '%HEADER%' OR t.name LIKE '%LINE%' OR t.name LIKE '%DETAIL%' GROUP BY t.name ORDER BY SUM(p.rows) DESC;"

Write-Host ""
if (Test-Path $ZipOut) { Remove-Item $ZipOut -Force }
Compress-Archive -Path (Join-Path $OutDir "*") -DestinationPath $ZipOut -Force

Write-Host "=== DONE ===" -ForegroundColor Cyan
Write-Host ("File: " + $ZipOut) -ForegroundColor Green
Write-Host ""
Write-Host "Review the ZIP yourself before sending. Schema only - no data." -ForegroundColor Yellow
Write-Host ""
