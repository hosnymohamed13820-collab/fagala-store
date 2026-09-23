# =====================================================================
#  agent_ashraqa_web.ps1  -  Ashraqa items / prices / balances
#                            -> the ASHRAQA website (its own database)
#  Version 1.0  (2026-09-22)
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
#    $t2 = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 30) -RepetitionDuration ([TimeSpan]::MaxValue)
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
