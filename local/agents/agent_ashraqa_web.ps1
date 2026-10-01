# =====================================================================
#  agent_ashraqa_web.ps1  -  Ashraqa items / prices / balances
#                            -> the ASHRAQA website (its own database)
#  Version 1.1  (2026-10-01)  - also sends purchase invoices and returns to ashraqa/sync_ashraqa_purchases.php
# =====================================================================
#
#  WHY THIS EXISTS
#    ashraqa.hosnimanagement.com has its own MySQL database. Its price
#    inquiry and stock pages read hq_items / hq_balances / hq_stores -
#    and nothing was filling them. This agent fills them from Ashraqa's
#    own system (Redux, ReduxSalesPUB2), so the Ashraqa site shows
#    ASHRAQA prices and ASHRAQA stock.
#
#    It sends to ashraqa/sync_hq.php in exactly the format agent_hq.ps1
#    uses for the main site, so no PHP had to change on the receiving
#    side except the token.
#
#  THE OLD AGENT IS NOT REPLACED
#    agent_ashraqa.ps1 keeps sending balances by barcode to the MAIN
#    site (hosnimanagement.com) - the Ashraqa invoices page depends on
#    it. This is a second, separate agent with its own scheduled task.
#
#  WHAT IT SENDS (every run, the whole catalogue - no delta):
#    1) items     barcode, item_id, name, price   -> hq_items
#    2) stores    sto_id, name                    -> hq_stores
#    3) balances  item_id, sto_id, balance        -> hq_balances
#       then balances_commit  (removes rows that were not in this run,
#       so an item that ran out disappears instead of staying stale)
#    Rows are sent in chunks of $ChunkSize.
#
#  PRICE COLUMN
#    $PriceExpr = Item.selling_price (found with diag_redux_prices.sql on
#    2026-09-22). selling_price2..8 are other price lists. If it is ever
#    emptied the agent REFUSES to run - sending 0 would show every item
#    at zero on the site.
#
#  NOTE: This file is deliberately ASCII-only (no Arabic text) so that
#  Windows PowerShell can never mis-parse it because of file encoding.
#
#  SETUP:
#    1) Put this file at  C:\fagala\agent_ashraqa_web.ps1
#    2) ($PriceExpr is already filled - nothing to edit)
#    3) Test it manually from an ADMIN PowerShell window:
#         powershell -ExecutionPolicy Bypass -File "C:\fagala\agent_ashraqa_web.ps1"
#    4) If it prints "ALL OK", register the scheduled task
#       (commands are at the bottom of this file)
# =====================================================================

# ===== SETTINGS - EDIT THESE =====

# Ashraqa website endpoint (NOT hosnimanagement.com)
$SyncUrl = 'https://ashraqa.hosnimanagement.com/sync_hq.php'

# Secret token - MUST match HQ_SYNC_TOKEN inside ashraqa/sync_hq.php
$SyncToken = 'ashweb_15c488c25181d22bec038b314ba6a679'

# Heartbeat to the Ashraqa site. On that site this agent plays the role
# the HQ agent plays on the main site, so it reports as 'hq'.
# A failed beat never fails the sync (it only gets logged).
$BeatUrl   = 'https://ashraqa.hosnimanagement.com/sync_beat.php'
$BeatToken = $SyncToken   # same token; = SYNC_AGENT_TOKENS['hq'] in ashraqa/sync_tokens.php
$AgentKey  = 'hq'
$AgentVer  = 'ashweb-1.2'

# SQL expression for the SALE price, as a column of Item i.
# EMPTY = the agent refuses to run (see the header).
$PriceExpr = 'i.selling_price'   # retail price (2..8 are other price lists) - checked 2026-09-22

# Rows per HTTP request
$ChunkSize = 5000

# Local SQL Server connection (same PC and database as agent_ashraqa.ps1)
$SqlServer      = 'WINDOWS-OP1VF0Q\SQLEXPRESS'
$SqlDatabase    = 'ReduxSalesPUB2'
$UseWindowsAuth = $true
$SqlUser        = 'sa'
$SqlPassword    = ''

$LogFile = Join-Path $PSScriptRoot 'agent_ashraqa_web.log'

# ===== DO NOT EDIT BELOW =====

$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

function Write-Log {
    param([string]$Message)
    $line = "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')  $Message"
    Write-Host $line
    try { Add-Content -Path $LogFile -Value $line -Encoding UTF8 } catch { }
}

function Send-Beat {
    param([int]$Rows = 0, [string]$ErrorText = '')
    try {
        $body = @{ agent = $AgentKey; rows = $Rows; error = $ErrorText; version = $AgentVer; host = $env:COMPUTERNAME } |
                ConvertTo-Json -Compress
        $bytes = [System.Text.Encoding]::UTF8.GetBytes($body)
        $null = Invoke-RestMethod -Uri $BeatUrl -Method Post -Body $bytes `
                -ContentType 'application/json; charset=utf-8' `
                -Headers @{ 'X-Sync-Token' = $BeatToken } -TimeoutSec 20
        Write-Log "[beat] sent (rows=$Rows)"
    }
    catch { Write-Log "[beat] failed: $($_.Exception.Message)" }
}

function Stop-Agent {
    param([int]$Code = 0, [int]$Rows = 0, [string]$ErrorText = '')
    Send-Beat -Rows $Rows -ErrorText $ErrorText
    exit $Code
}

# One POST with retries. Returns the parsed response or throws.
function Send-Kind {
    param([string]$Kind, $Rows, [string]$Batch = '')
    $payload = @{ kind = $Kind; rows = @($Rows) }
    if ($Batch -ne '') { $payload.batch = $Batch }
    $json  = $payload | ConvertTo-Json -Depth 4 -Compress
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($json)
    $last = $null
    for ($try = 1; $try -le 3; $try++) {
        try {
            $r = Invoke-RestMethod -Uri $SyncUrl -Method Post -Body $bytes `
                 -ContentType 'application/json; charset=utf-8' `
                 -Headers @{ 'X-Sync-Token' = $SyncToken } -TimeoutSec 120
            if ($r.ok -ne $true) { throw "server said: $($r.error)" }
            return $r
        }
        catch {
            $last = $_.Exception.Message
            Write-Log "[$Kind] try $try failed: $last"
            Start-Sleep -Seconds (10 * $try)
        }
    }
    throw "[$Kind] gave up after 3 tries: $last"
}

# Sends a DataTable-like list in chunks. Returns rows stored.
function Send-Chunks {
    param([string]$Kind, [System.Collections.ArrayList]$List, [string]$Batch = '')
    $stored = 0
    for ($i = 0; $i -lt $List.Count; $i += $ChunkSize) {
        $end   = [Math]::Min($i + $ChunkSize, $List.Count) - 1
        $slice = $List[$i..$end]
        $r = Send-Kind -Kind $Kind -Rows $slice -Batch $Batch
        $stored += [int]$r.stored
    }
    return $stored
}

function Read-Sql {
    param($Conn, [string]$Sql)
    $cmd = $Conn.CreateCommand()
    # READ UNCOMMITTED: read without taking shared locks, so the cashier and the
    # replication never wait on us. A value mid-write can be read once; the next
    # run (2 min) corrects it. We only read - nothing here writes to this DB.
    $cmd.CommandText = "SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED;`n" + $Sql
    $cmd.CommandTimeout = 300
    $t = New-Object System.Data.DataTable
    [void](New-Object System.Data.SqlClient.SqlDataAdapter($cmd)).Fill($t)
    return ,$t      # the comma matters - without it PowerShell unrolls the table
}

Write-Log '===== run start ====='

if ([string]::IsNullOrWhiteSpace($PriceExpr)) {
    Write-Log 'PriceExpr is empty - refusing to run (would send every price as 0). Fill it first.'
    Stop-Agent -Code 2 -ErrorText 'PriceExpr not set'
}

# ---------------------------------------------------------------------
#  0) Purchase invoices + purchase returns  (new in 1.1 - 2026-10-01)
#
#  WHY: Ashraqa records the goods it sends to OUR branches as a
#  "purchase return" (SSTT 115) to supplier 8 ("the branches, Mr Mohamed
#  Essam"), with the branch name typed in NOTS. The branch books the
#  same goods as "bought from HQ", so HQ has no matching invoice and no
#  stock. The site needs these lines, item by item, to reconcile them.
#
#  - Runs at most once every $PurchEveryMin minutes (the agent itself
#    runs every 2 min for balances - this part does not need that).
#  - Sends only the invoices whose content changed; once a day sends
#    everything since $PurchSince (mode=full) so a deleted invoice on
#    their side also disappears on ours.
#  - Own state file, own endpoint. It NEVER throws and NEVER exits:
#    a failure here is logged and the balance sync below runs as usual.
#  - Read-only on their DB, same READ UNCOMMITTED as the balance query.
# ---------------------------------------------------------------------
$PurchUrl       = 'https://ashraqa.hosnimanagement.com/sync_ashraqa_purchases.php'
$PurchStateFile = Join-Path $PSScriptRoot 'agent_ashraqa_web.purch.json'
$PurchSince     = '2026-07-01'
$PurchEveryMin  = 30
$PurchFullHours = 24

$PurchQuery = @"
SET NOCOUNT ON;

WITH Codes AS (
    SELECT b.ProductID AS item_id, LTRIM(RTRIM(b.Barcode)) AS barcode
    FROM ItemBarcodes b
    WHERE b.Barcode IS NOT NULL AND LTRIM(RTRIM(b.Barcode)) <> ''

    UNION

    SELECT i.ID AS item_id, LTRIM(RTRIM(i.MainBarcode)) AS barcode
    FROM Item i
    WHERE i.MainBarcode IS NOT NULL AND LTRIM(RTRIM(i.MainBarcode)) <> ''
)
SELECT p.SSTT_ID                                   AS sstt,
       p.BRCH_ID                                   AS brch,
       p.FY_NO                                     AS fy,
       p.add_doc                                   AS doc,
       CONVERT(varchar(10), p.Zdate, 120)          AS doc_date,
       p.supplier_id                               AS supplier_id,
       s.supplier_name                             AS supplier_name,
       CAST(p.Net_invoice   AS DECIMAL(16,3))      AS net,
       CAST(p.Total_Invoice AS DECIMAL(16,3))      AS total,
       CAST(ISNULL(p.Cancel_Doc, 0) AS int)        AS cancelled,
       p.NOTS                                      AS notes,
       p.Supplier_invoice_number                   AS supplier_inv_no,
       p.Internal_No                               AS internal_no,
       CONVERT(varchar(19), p.LAST_MODIFY_DATE, 120) AS last_modify,
       ROW_NUMBER() OVER (PARTITION BY p.SSTT_ID, p.BRCH_ID, p.FY_NO, p.add_doc
                          ORDER BY d.serial, d.index_row)  AS line_no,
       d.ID_item                                   AS item_id,
       LTRIM(RTRIM(d.CODE))                        AS code,
       STUFF((SELECT '|' + c.barcode FROM Codes c WHERE c.item_id = d.ID_item
              FOR XML PATH(''), TYPE).value('.', 'nvarchar(max)'), 1, 1, '') AS barcodes,
       LTRIM(RTRIM(i.description))                 AS name,
       d.id_unit                                   AS unit_id,
       CAST(ISNULL(d.unit_q, 1) AS DECIMAL(12,3))  AS unit_q,
       CAST(d.Quantity   AS DECIMAL(16,3))         AS qty,
       CAST(d.unit_price AS DECIMAL(16,3))         AS price,
       CAST(d.TOTVALUE   AS DECIMAL(16,3))         AS line_total
FROM Purchases p
LEFT JOIN supplier s          ON s.ID = p.supplier_id
LEFT JOIN Purchases_details d ON d.DOC_NO = p.add_doc AND d.SSTT_ID = p.SSTT_ID
                             AND d.BRCH_ID = p.BRCH_ID AND d.FY_NO = p.FY_NO
LEFT JOIN Item i              ON i.ID = d.ID_item
WHERE p.SSTT_ID IN (113, 115)
  AND p.Zdate >= '$PurchSince'
ORDER BY p.SSTT_ID, p.BRCH_ID, p.FY_NO, p.add_doc, line_no;
"@

function Get-Nz {
    param($Value)
    if ($Value -is [System.DBNull] -or $null -eq $Value) { return $null }
    return $Value
}

function Sync-Purchases {
    try {
        # --- throttle: at most once every $PurchEveryMin minutes ---
        $pst = $null
        if (Test-Path $PurchStateFile) {
            try { $pst = Get-Content $PurchStateFile -Raw -Encoding UTF8 | ConvertFrom-Json } catch { $pst = $null }
        }
        $now = Get-Date
        if ($pst -and $pst.lastRun) {
            $mins = ($now - [datetime]$pst.lastRun).TotalMinutes
            if ($mins -lt $PurchEveryMin) { return }
        }

        $prevFp = @{}
        if ($pst -and $pst.fp) {
            foreach ($p in $pst.fp.PSObject.Properties) { $prevFp[$p.Name] = [string]$p.Value }
        }
        $lastFull = $null
        if ($pst -and $pst.lastFull) { $lastFull = [datetime]$pst.lastFull }
        $forceFull = ($prevFp.Count -eq 0) -or ($null -eq $lastFull) -or (($now - $lastFull).TotalHours -ge $PurchFullHours)

        # --- read ---
        if ($UseWindowsAuth) {
            $cs = "Server=$SqlServer;Database=$SqlDatabase;Integrated Security=True;TrustServerCertificate=True;Connection Timeout=30"
        } else {
            $cs = "Server=$SqlServer;Database=$SqlDatabase;User Id=$SqlUser;Password=$SqlPassword;TrustServerCertificate=True;Connection Timeout=30"
        }
        $pc = New-Object System.Data.SqlClient.SqlConnection($cs)
        $pc.Open()
        $pcmd = $pc.CreateCommand()
        $pcmd.CommandText    = "SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED;`n" + $PurchQuery
        $pcmd.CommandTimeout = 300
        $pad = New-Object System.Data.SqlClient.SqlDataAdapter($pcmd)
        $ptab = New-Object System.Data.DataTable
        [void]$pad.Fill($ptab)
        $pc.Close()

        # --- group rows into invoices (rows arrive ordered by key, line_no) ---
        $invs  = [ordered]@{}
        foreach ($r in $ptab.Rows) {
            $key = '{0}-{1}-{2}-{3}' -f $r['sstt'], $r['brch'], $r['fy'], $r['doc']
            if (-not $invs.Contains($key)) {
                $invs[$key] = [ordered]@{
                    sstt            = [int]$r['sstt']
                    brch            = [int]$r['brch']
                    fy              = [int]$r['fy']
                    doc             = [int]$r['doc']
                    date            = [string]$r['doc_date']
                    supplier_id     = Get-Nz $r['supplier_id']
                    supplier_name   = Get-Nz $r['supplier_name']
                    net             = [double](Get-Nz $r['net'])
                    total           = [double](Get-Nz $r['total'])
                    cancelled       = [int]$r['cancelled']
                    notes           = Get-Nz $r['notes']
                    supplier_inv_no = Get-Nz $r['supplier_inv_no']
                    internal_no     = Get-Nz $r['internal_no']
                    last_modify     = Get-Nz $r['last_modify']
                    lines           = New-Object System.Collections.ArrayList
                }
            }
            # An invoice with no lines still comes back once, with a NULL item.
            if (-not ($r['item_id'] -is [System.DBNull])) {
                $null = $invs[$key].lines.Add([ordered]@{
                    line_no  = [int]$r['line_no']
                    item_id  = [int]$r['item_id']
                    code     = Get-Nz $r['code']
                    barcodes = Get-Nz $r['barcodes']
                    name     = Get-Nz $r['name']
                    unit_id  = Get-Nz $r['unit_id']
                    unit_q   = [double](Get-Nz $r['unit_q'])
                    qty      = [double](Get-Nz $r['qty'])
                    price    = [double](Get-Nz $r['price'])
                    total    = [double](Get-Nz $r['line_total'])
                })
            }
        }

        # --- fingerprint each invoice; keep only the changed ones ---
        $md5  = [System.Security.Cryptography.MD5]::Create()
        $curFp = @{}
        $send  = New-Object System.Collections.ArrayList
        foreach ($k in $invs.Keys) {
            $json = $invs[$k] | ConvertTo-Json -Depth 5 -Compress
            $hash = [BitConverter]::ToString($md5.ComputeHash([System.Text.Encoding]::UTF8.GetBytes($json))).Replace('-', '')
            $curFp[$k] = $hash
            if ($forceFull -or -not $prevFp.ContainsKey($k) -or $prevFp[$k] -ne $hash) {
                $null = $send.Add($invs[$k])
            }
        }

        $mode = if ($forceFull) { 'full' } else { 'partial' }
        Write-Log "[purch] read $($ptab.Rows.Count) lines / $($invs.Count) invoices - to send: $($send.Count) ($mode)"

        if ($send.Count -gt 0 -or $forceFull) {
            $body  = @{ token = $SyncToken; mode = $mode; since = $PurchSince; invoices = $send } |
                     ConvertTo-Json -Depth 6 -Compress
            $bytes = [System.Text.Encoding]::UTF8.GetBytes($body)
            Write-Log ("[purch] payload {0} KB" -f [math]::Round($bytes.Length / 1KB, 1))

            [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
            $resp = Invoke-RestMethod -Uri $PurchUrl -Method Post -Body $bytes `
                    -ContentType 'application/json; charset=utf-8' `
                    -Headers @{ 'X-Sync-Token' = $SyncToken } -TimeoutSec 120

            if ($resp.ok -ne $true) {
                # State NOT saved (fp) -> the same invoices go again next time.
                # lastRun IS saved -> next try in $PurchEveryMin min, not in 2 min.
                Write-Log "[purch] server rejected: $($resp.error)"
                @{ lastRun = $now.ToString('o'); lastFull = $pst.lastFull; fp = $prevFp } |
                    ConvertTo-Json -Depth 3 -Compress | Set-Content $PurchStateFile -Encoding UTF8
                return
            }
            Write-Log "[purch] OK - invoices: $($resp.invoices) | lines: $($resp.lines) | skipped: $($resp.skipped)"
        }

        $newFull = if ($forceFull) { $now.ToString('o') } else { $pst.lastFull }
        @{ lastRun = $now.ToString('o'); lastFull = $newFull; fp = $curFp } |
            ConvertTo-Json -Depth 3 -Compress | Set-Content $PurchStateFile -Encoding UTF8
    }
    catch {
        # Never let this part break the balance sync.
        Write-Log "[purch] failed: $($_.Exception.Message)"
        # Record the attempt time (fingerprints untouched) so a broken
        # endpoint is retried every $PurchEveryMin min, NOT every 2 min -
        # a full upload every 2 min is what got the office IP banned on
        # 2026-08-08.
        try {
            $keepFp = @{}; $keepFull = $null
            if (Test-Path $PurchStateFile) {
                $old = Get-Content $PurchStateFile -Raw -Encoding UTF8 | ConvertFrom-Json
                if ($old.fp) { foreach ($p in $old.fp.PSObject.Properties) { $keepFp[$p.Name] = [string]$p.Value } }
                $keepFull = $old.lastFull
            }
            @{ lastRun = (Get-Date).ToString('o'); lastFull = $keepFull; fp = $keepFp } |
                ConvertTo-Json -Depth 3 -Compress | Set-Content $PurchStateFile -Encoding UTF8
        } catch { }
    }
}

Sync-Purchases

# ---------------------------------------------------------------------
#  1) Read from Redux
#     Same barcode logic as agent_ashraqa.ps1: ItemBarcodes UNION
#     Item.MainBarcode. Unlike that agent, EVERY item is sent (not only
#     items with stock) - an item at zero stock still has a price.
# ---------------------------------------------------------------------
$ItemsSql = @"
SET NOCOUNT ON;
WITH AllCodes AS (
    SELECT b.ProductID AS item_id, LTRIM(RTRIM(b.Barcode)) AS barcode
    FROM ItemBarcodes b WHERE b.Barcode IS NOT NULL
    UNION
    SELECT i.ID, LTRIM(RTRIM(i.MainBarcode))
    FROM Item i WHERE i.MainBarcode IS NOT NULL
)
SELECT DISTINCT
       c.barcode                               AS barcode,
       i.ID                                    AS item_id,
       LTRIM(RTRIM(i.description))             AS name,
       CAST($PriceExpr AS DECIMAL(14,2))       AS price
FROM Item i
INNER JOIN AllCodes c ON c.item_id = i.ID
WHERE c.barcode <> '';
"@

$BalSql = @"
SET NOCOUNT ON;
SELECT s.ID AS item_id, s.id_store AS sto_id,
       CAST(s.Balance_UNIT_Basic AS DECIMAL(16,3)) AS balance
FROM SEARCH_ITEMS s
WHERE s.Balance_UNIT_Basic <> 0;
"@

try {
    if ($UseWindowsAuth) {
        $connStr = "Server=$SqlServer;Database=$SqlDatabase;Integrated Security=True;TrustServerCertificate=True;Connection Timeout=30"
    } else {
        $connStr = "Server=$SqlServer;Database=$SqlDatabase;User Id=$SqlUser;Password=$SqlPassword;TrustServerCertificate=True;Connection Timeout=30"
    }
    $conn = New-Object System.Data.SqlClient.SqlConnection($connStr)
    $conn.Open()
    $itemsT = Read-Sql $conn $ItemsSql
    $balT   = Read-Sql $conn $BalSql
    $conn.Close()
    Write-Log "Read $($itemsT.Rows.Count) barcodes, $($balT.Rows.Count) balance rows."
}
catch {
    $msg = $_.Exception.Message
    Write-Log "SQL Server read failed: $msg"
    Stop-Agent -Code 1 -ErrorText "SQL read failed: $msg"
}

# Plain lists (ConvertTo-Json cannot serialise DataRows cleanly).
# Barcodes stay STRINGS - 0096 is not 96.
$items = New-Object System.Collections.ArrayList
foreach ($r in $itemsT.Rows) {
    [void]$items.Add(@{ barcode = [string]$r.barcode; item_id = [int]$r.item_id; name = [string]$r.name; price = [double]$r.price })
}
$bals = New-Object System.Collections.ArrayList
$stores = @{}
foreach ($r in $balT.Rows) {
    [void]$bals.Add(@{ item_id = [int]$r.item_id; sto_id = [int]$r.sto_id; balance = [double]$r.balance })
    $stores[[int]$r.sto_id] = $true
}
$storeList = New-Object System.Collections.ArrayList
foreach ($s in $stores.Keys) { [void]$storeList.Add(@{ sto_id = [int]$s; name = "Store $s" }) }

if ($items.Count -eq 0) {
    Write-Log 'Zero items read - not sending (would look like an empty catalogue).'
    Stop-Agent -Code 1 -ErrorText 'zero items read'
}

# ---------------------------------------------------------------------
#  2) Send
#     A failed balances step skips balances_commit on purpose: commit
#     deletes every row not stamped with this batch, so committing a
#     half-sent batch would wipe real stock from the site.
# ---------------------------------------------------------------------
try {
    $n1 = Send-Chunks -Kind 'items'  -List $items
    Write-Log "items: $n1 stored"
    if ($storeList.Count -gt 0) {
        $n2 = Send-Chunks -Kind 'stores' -List $storeList
        Write-Log "stores: $n2 stored"
    }
    $batch = Get-Date -Format 'yyMMddHHmmss'
    $n3 = Send-Chunks -Kind 'balances' -List $bals -Batch $batch
    Write-Log "balances: $n3 stored (batch $batch)"
    $null = Send-Kind -Kind 'balances_commit' -Rows @() -Batch $batch
    Write-Log 'balances_commit done.'
    Write-Log 'ALL OK'
    Stop-Agent -Code 0 -Rows ($n1 + $n3)
}
catch {
    $msg = $_.Exception.Message
    Write-Log "Send failed: $msg"
    Stop-Agent -Code 1 -ErrorText "send failed: $msg"
}

# =====================================================================
#  SCHEDULED TASK - every 30 minutes, and at every Windows startup.
#  (SYSTEM needs read access to ReduxSalesPUB2 - if agent_ashraqa.ps1
#   already runs as a task, that access is already granted.)
#
#  Open PowerShell AS ADMINISTRATOR and paste:
#
#    $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument '-ExecutionPolicy Bypass -WindowStyle Hidden -NonInteractive -File "C:\fagala\agent_ashraqa_web.ps1"'
#    $t1 = New-ScheduledTaskTrigger -AtStartup
#    $t1.Delay = 'PT4M'
#    $t2 = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 30) -RepetitionDuration (New-TimeSpan -Days 3650)
#    $set = New-ScheduledTaskSettingsSet -StartWhenAvailable -DontStopOnIdleEnd -DontStopIfGoingOnBatteries -AllowStartIfOnBatteries -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 5) -ExecutionTimeLimit (New-TimeSpan -Minutes 15) -MultipleInstances IgnoreNew
#    Register-ScheduledTask -TaskName 'AshraqaWebSync' -Action $action -Trigger @($t1,$t2) -Settings $set -RunLevel Highest -User 'SYSTEM' -Force
#
#  CHECK IT:
#    Start-ScheduledTask -TaskName 'AshraqaWebSync'
#    Get-Content C:\fagala\agent_ashraqa_web.log -Tail 20
#
#  REMOVE IT:
#    Unregister-ScheduledTask -TaskName 'AshraqaWebSync' -Confirm:$false
# =====================================================================
