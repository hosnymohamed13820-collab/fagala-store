# =====================================================================
#  agent_hq.ps1 - HQ (Anzmah_FagalaHQ) items and prices sync agent
# =====================================================================
#  Runs on the HQ PC (the admin machine with SQL Server).
#  Pushes the item catalog + sale prices + store names to the website
#  every 30 minutes, so the site can read a local copy and keep working
#  even when the HQ internet connection drops.
#
#  This is SEPARATE from agent_ashraqa.ps1 - different source database.
#
#  ASCII-only (no Arabic) so Windows PowerShell never mis-parses it.
#
#  SETUP:
#    1) Put this file at  C:\fagala\agent_hq.ps1
#    2) Edit the settings below (URL, token, SQL connection)
#    3) Test manually from an ADMIN PowerShell:
#         powershell -ExecutionPolicy Bypass -File "C:\fagala\agent_hq.ps1"
#    4) If it prints "Sync OK", register the scheduled task
#       (commands at the bottom of this file)
# =====================================================================

# ===== SETTINGS - EDIT THESE =====

$SyncUrl = 'https://hosnimanagement.com/sync_hq.php'

# Secret token - MUST match the one inside sync_hq.php exactly
$SyncToken = 'hq_7Kp9mXq2Rv5Nw8Lt3Bz6Yd4Hs1Fj0Gc'

# Local SQL Server connection
$SqlServer   = 'localhost'
$SqlDatabase = 'Anzmah_FagalaHQ'

# Authentication: $true = Windows Auth (no user/pass), $false = SQL login
$UseWindowsAuth = $true
$SqlUser     = 'sa'
$SqlPassword = ''

$LogFile = Join-Path $PSScriptRoot 'agent_hq.log'

# ===== DO NOT EDIT BELOW =====

$ErrorActionPreference = 'Stop'

function Write-Log {
    param([string]$Message)
    $line = "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')  $Message"
    Write-Host $line
    try { Add-Content -Path $LogFile -Value $line -Encoding UTF8 } catch { }
}

try {
    if ((Test-Path $LogFile) -and ((Get-Item $LogFile).Length -gt 2MB)) {
        Get-Content $LogFile -Tail 500 | Set-Content "$LogFile.tmp" -Encoding UTF8
        Move-Item "$LogFile.tmp" $LogFile -Force
    }
} catch { }

# =====================================================================
#  RUN LOCK - only one run at a time, ever.
#
#  The schedule can now be as tight as 2 minutes. If a run is still
#  going when the next one fires, the new one exits immediately and
#  lets the running one finish. Without this you get two copies
#  writing the same tables at the same time = corrupted data.
#
#  A lock older than 30 minutes is treated as dead (crash / reboot)
#  and taken over.
# =====================================================================
$LockFile = Join-Path $PSScriptRoot 'agent_hq.lock'
$LockMine = $false

try {
    if (Test-Path $LockFile) {
        $lockAge = (Get-Date) - (Get-Item $LockFile).LastWriteTime
        $lockPid = 0
        try { $lockPid = [int](Get-Content $LockFile -First 1 -ErrorAction Stop) } catch { }

        $alive = $false
        if ($lockPid -gt 0) {
            try { $alive = $null -ne (Get-Process -Id $lockPid -ErrorAction Stop) } catch { $alive = $false }
        }

        if ($alive -and $lockAge.TotalMinutes -lt 30) {
            Write-Log "Another run is still going (PID $lockPid, started $([int]$lockAge.TotalMinutes) min ago) - skipping this one."
            exit 0
        }
        Write-Log "Stale lock found (age $([int]$lockAge.TotalMinutes) min) - taking over."
        Remove-Item $LockFile -Force -ErrorAction SilentlyContinue
    }

    Set-Content -Path $LockFile -Value $PID -Encoding ASCII
    $LockMine = $true
} catch {
    Write-Log "Lock handling failed: $($_.Exception.Message)"
}

# Always drop the lock, even on crash or Ctrl+C
$ExecutionContext.SessionState.Module | Out-Null
trap {
    if ($LockMine) { Remove-Item $LockFile -Force -ErrorAction SilentlyContinue }
    break
}

# =====================================================================
#  CHANGE DETECTION - do not send what did not change.
#
#  Every payload gets a SHA256 fingerprint. If it matches the one we
#  sent last time, we skip the upload completely. The item catalog and
#  prices change once or twice a day, so most runs send nothing at all
#  and cost almost zero - which is what makes a 2 minute schedule safe.
#
#  Delete agent_hq_state.json to force a full resend.
# =====================================================================
$StateFile = Join-Path $PSScriptRoot 'agent_hq_state.json'
$State = @{}

try {
    if (Test-Path $StateFile) {
        $raw = Get-Content $StateFile -Raw -Encoding UTF8
        if ($raw -and $raw.Trim() -ne '') {
            $obj = $raw | ConvertFrom-Json
            foreach ($prop in $obj.PSObject.Properties) { $State[$prop.Name] = [string]$prop.Value }
        }
    }
} catch {
    Write-Log "Could not read state file (starting fresh): $($_.Exception.Message)"
    $State = @{}
}

function Get-Fingerprint {
    param([string]$Text)
    $sha = [System.Security.Cryptography.SHA256]::Create()
    try {
        $bytes = $sha.ComputeHash([System.Text.Encoding]::UTF8.GetBytes($Text))
        return [System.BitConverter]::ToString($bytes).Replace('-', '').Substring(0, 32)
    } finally {
        $sha.Dispose()
    }
}

# Did this data change since the last successful send?
function Test-Changed {
    param([string]$Key, [string]$Text)
    $fp = Get-Fingerprint $Text
    return -not ($State.ContainsKey($Key) -and $State[$Key] -eq $fp)
}

# Record the fingerprint - ONLY call this after a successful send,
# otherwise a failed upload would be skipped forever on the next run.
function Set-Sent {
    param([string]$Key, [string]$Text)
    $State[$Key] = Get-Fingerprint $Text
}

function Save-State {
    try {
        ($State | ConvertTo-Json -Compress) | Set-Content -Path $StateFile -Encoding UTF8
    } catch {
        Write-Log "Could not save state file: $($_.Exception.Message)"
    }
}

# One batch stamp for this whole run (used by balances + unposted)
$BatchId = Get-Date -Format 'yyMMddHHmmss'
$RunStarted = Get-Date

Write-Log '--- HQ sync started ---'

# Build the SQL connection string once
if ($UseWindowsAuth) {
    $ConnStr = "Server=$SqlServer;Database=$SqlDatabase;Integrated Security=True;TrustServerCertificate=True;Connection Timeout=30"
} else {
    $ConnStr = "Server=$SqlServer;Database=$SqlDatabase;User Id=$SqlUser;Password=$SqlPassword;TrustServerCertificate=True;Connection Timeout=30"
}

# ---------------------------------------------------------------------
#  Helper: run a query and return a DataTable
# ---------------------------------------------------------------------
function Invoke-Sql {
    param([string]$Sql)
    $conn = New-Object System.Data.SqlClient.SqlConnection($ConnStr)
    $conn.Open()
    try {
        $cmd = $conn.CreateCommand()
        $cmd.CommandText    = $Sql
        $cmd.CommandTimeout = 300
        $adapter = New-Object System.Data.SqlClient.SqlDataAdapter($cmd)
        $table   = New-Object System.Data.DataTable
        [void]$adapter.Fill($table)
        return ,$table
    } finally {
        $conn.Close()
    }
}

# ---------------------------------------------------------------------
#  Helper: POST rows to the website
# ---------------------------------------------------------------------
function Send-Batch {
    param([string]$Kind, $Rows)

    if ($Rows.Count -eq 0) {
        Write-Log "[$Kind] no rows - skipped."
        return $true
    }

    $payload = @{ token = $SyncToken; kind = $Kind; rows = $Rows } | ConvertTo-Json -Depth 4 -Compress

    # Nothing changed since last time? Do not spend the upload.
    if (-not (Test-Changed $Kind $payload)) {
        Write-Log "[$Kind] unchanged - nothing to send."
        return $true
    }

    if (Send-Payload $payload $Kind) {
        Set-Sent $Kind $payload
        return $true
    }
    return $false
}

# Sends an already-built JSON payload string. Returns $true on success.
# ---------------------------------------------------------------------
#  CIRCUIT BREAKER
#
#  If the site cannot be reached at all, every remaining section used to
#  retry three times with 20 second sleeps of its own. One dead run cost
#  about 30 failed connections spread over 14 minutes, and the scheduler
#  started another run right behind it. On 2026-08-08 that pattern kept
#  a firewall block alive instead of letting it expire.
#
#  Now the first "cannot connect" trips the breaker and the whole run
#  gives up immediately. A dead run costs 3 attempts, not 30.
#
#  Only connection-level errors trip it. An HTTP error, or the server
#  answering with ok:false, means the link is fine and the problem is
#  this one payload - those must NOT stop the other sections.
# ---------------------------------------------------------------------
$script:LinkDown = $false

function Test-ConnectionError {
    param($Err)
    # WebException with no HTTP response = never reached the server
    # (DNS failure, TCP timeout, connection refused, TLS handshake).
    $ex = $Err.Exception
    if ($ex -is [System.Net.WebException]) {
        if ($null -eq $ex.Response) { return $true }
        return $false
    }
    $m = [string]$ex.Message
    return ($m -like '*Unable to connect*') -or
           ($m -like '*could not be resolved*') -or
           ($m -like '*timed out*') -or
           ($m -like '*actively refused*')
}

function Send-Payload {
    param([string]$Payload, [string]$Label)

    if ($script:LinkDown) {
        Write-Log "[$Label] skipped - link is down."
        return $false
    }

    $sizeKb = [math]::Round(([System.Text.Encoding]::UTF8.GetByteCount($Payload) / 1KB), 1)
    Write-Log "[$Label] size: $sizeKb KB"

    for ($attempt = 1; $attempt -le 3; $attempt++) {
        try {
            [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
            $bytes = [System.Text.Encoding]::UTF8.GetBytes($Payload)
            $resp = Invoke-RestMethod -Uri $SyncUrl -Method Post -Body $bytes -ContentType 'application/json; charset=utf-8' -Headers @{ 'X-Sync-Token' = $SyncToken } -TimeoutSec 120
            if ($resp.ok -eq $true) {
                Write-Log "[$Label] Sync OK - stored: $($resp.stored) | skipped: $($resp.skipped)"
                return $true
            } else {
                # Server answered, so the link is fine - this payload is bad.
                Write-Log "[$Label] server rejected: $($resp.error)"
            }
        } catch {
            Write-Log "[$Label] attempt $attempt of 3 failed: $($_.Exception.Message)"
            if (Test-ConnectionError $_) {
                if ($attempt -ge 3) {
                    $script:LinkDown = $true
                    Write-Log "Site unreachable after 3 attempts - aborting this run. Next run will retry."
                    return $false
                }
            } else {
                # HTTP 4xx/5xx: the server is alive, do not trip the breaker.
                if ($attempt -ge 3) { return $false }
            }
            if ($attempt -lt 3) { Start-Sleep -Seconds 20 }
        }
    }
    return $false
}

# ---------------------------------------------------------------------
#  1) ITEMS + PRICES
#
#  Join: ITEM_CODES (barcode) -> COM_ITEM_PRICE (sale price)
#  CIP_PRICE is the correct sale price source (confirmed earlier).
#  We send every barcode with its item id, name, and price.
# ---------------------------------------------------------------------
$itemsSql = @"
SET NOCOUNT ON;

SELECT LTRIM(RTRIM(c.IC_CODE))        AS barcode,
       c.ITEM_ID                      AS item_id,
       ISNULL(c.IC_CODE_TYPE, 0)      AS code_type,
       ISNULL(c.IC_UNIT_FACTOR, 1)   AS unit_type,
       LTRIM(RTRIM(c.IC_NAME1))       AS name,
       CAST(ISNULL(p.CIP_PRICE, 0) AS DECIMAL(14,2)) AS price,
       CAST(ISNULL(ci.CI_AVR_COST, 0) AS DECIMAL(14,3)) AS cost
FROM ITEM_CODES c
LEFT JOIN COM_ITEM_PRICE p ON p.CIP_ID = c.CIP_ID
LEFT JOIN COM_ITEMS ci ON ci.ITEM_ID = c.ITEM_ID
WHERE c.IC_CODE IS NOT NULL
  AND LTRIM(RTRIM(c.IC_CODE)) <> '';
"@

try {
    $itemsTable = Invoke-Sql $itemsSql
    Write-Log "Read $($itemsTable.Rows.Count) item rows."
} catch {
    Write-Log "Items query failed: $($_.Exception.Message)"
    if ($LockMine) { Remove-Item $LockFile -Force -ErrorAction SilentlyContinue }
    exit 1
}

$itemRows = New-Object System.Collections.ArrayList
$codeRows = New-Object System.Collections.ArrayList
foreach ($r in $itemsTable.Rows) {
    # for fast price lookups (hq_items)
    $null = $itemRows.Add([ordered]@{
        barcode = [string]$r['barcode']
        item_id = [int]$r['item_id']
        name    = [string]$r['name']
        price   = [double]$r['price']
    })
    # for the full codes table (hq_codes) - used by stocktake
    $null = $codeRows.Add([ordered]@{
        code        = [string]$r['barcode']
        item_id     = [int]$r['item_id']
        name        = [string]$r['name']
        code_type   = [int]$r['code_type']
        unit_factor = [double]$r['unit_type']
        price       = [double]$r['price']
        cost        = [double]$r['cost']
    })
}

$okItems = Send-Batch 'items' $itemRows
$okCodes = Send-Batch 'codes' $codeRows

# ---------------------------------------------------------------------
#  2) STORES
# ---------------------------------------------------------------------
$storesSql = @"
SET NOCOUNT ON;
SELECT STO_ID AS sto_id, LTRIM(RTRIM(STO_NAME1)) AS name
FROM STORES WHERE STO_ID > 0 ORDER BY STO_ID;
"@

try {
    $storesTable = Invoke-Sql $storesSql
    Write-Log "Read $($storesTable.Rows.Count) store rows."
} catch {
    Write-Log "Stores query failed: $($_.Exception.Message)"
    $storesTable = $null
}

$okStores = $true
if ($storesTable -ne $null) {
    $storeRows = New-Object System.Collections.ArrayList
    foreach ($r in $storesTable.Rows) {
        $null = $storeRows.Add([ordered]@{
            sto_id = [int]$r['sto_id']
            name   = [string]$r['name']
        })
    }
    $okStores = Send-Batch 'stores' $storeRows
}

# ---------------------------------------------------------------------
#  3) INVOICES (headers + lines) - smart sync
#
#  Monthly tables: STO_TRANS_HEADER_YY_MM. We loop from 26_06 to the
#  current month automatically - new months get picked up on their own.
#
#  For each month we only pull invoices modified since the last run
#  (LAST_MODIFY_DATE > stored watermark), so batches stay small even
#  when there are many months of history.
#
#  SSTT_ID IN (2,6) = purchase invoices + purchase returns (same as
#  what the bridge reads today).
# ---------------------------------------------------------------------

$StartYear  = 2026
$StartMonth = 6

# Read the per-month watermarks we already have (last modify date synced)
$watermarks = @{}
try {
    # Token goes in the header, NOT the query string - query strings end up
    # in the web server access logs in plain text.
    $wmUrl = $SyncUrl + '?watermarks=1'
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    $wmResp = Invoke-RestMethod -Uri $wmUrl -Method Get -Headers @{ 'X-Sync-Token' = $SyncToken } -TimeoutSec 60
    if ($wmResp.ok -eq $true -and $wmResp.watermarks) {
        foreach ($p in $wmResp.watermarks.PSObject.Properties) {
            $watermarks[$p.Name] = $p.Value
        }
    }
    Write-Log "Loaded $($watermarks.Count) month watermark(s)."
} catch {
    Write-Log "Could not load watermarks (first run?): $($_.Exception.Message)"
    # If the site is unreachable, stop here. Carrying on with no
    # watermarks makes every month look fully changed, so the agent
    # would try to upload the entire history to a host it cannot even
    # reach - exactly the 125 KB retry storm seen on 2026-08-08.
    if (Test-ConnectionError $_) { $script:LinkDown = $true }
}

# Build the list of month suffixes from start to now
$now = Get-Date
$suffixes = @()
$y = $StartYear; $m = $StartMonth
while (($y -lt $now.Year) -or ($y -eq $now.Year -and $m -le $now.Month)) {
    $suffixes += ('{0:00}_{1:00}' -f ($y % 100), $m)
    $m++; if ($m -gt 12) { $m = 1; $y++ }
}
Write-Log "Months to check: $($suffixes -join ', ')"

$okInvoices = $true
$newState = @{}

foreach ($suffix in $suffixes) {
    if ($script:LinkDown) { $okInvoices = $false; break }

    $headTable = "STO_TRANS_HEADER_$suffix"
    $lineTable = "STO_TRANS_LINES_$suffix"

    # does the table exist? (older months may not)
    $existsSql = "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = '$headTable'"
    try {
        $exists = (Invoke-Sql $existsSql).Rows[0]['c']
    } catch {
        Write-Log "[$suffix] existence check failed: $($_.Exception.Message)"
        $okInvoices = $false
        continue
    }
    if ($exists -eq 0) { continue }

    $wm = $watermarks[$suffix]
    $wmClause = ''
    if ($wm) { $wmClause = "AND COALESCE(h.LAST_MODIFY_DATE, h.CREATE_DATE) > '$wm'" }

    # headers modified since watermark
    # LAST_MODIFY_DATE is NULL until an invoice is first edited, so we fall
    # back to CREATE_DATE - this way brand-new invoices are picked up too.
    $headSql = @"
SET NOCOUNT ON;
SELECT h.STH_ID AS sth_id, '$suffix' AS suffix,
       LTRIM(RTRIM(h.STH_DOC_NO)) AS doc_no, h.SSTT_ID AS doc_type,
       h.SUPP_ID AS supplier_id,
       LTRIM(RTRIM(s.SUPP_CODE)) AS supplier_code,
       LTRIM(RTRIM(s.SUPP_NAME1)) AS supplier_name,
       h.STH_NET_VALUE AS net_amount, h.STH_TOT_VALUE AS total_amount,
       h.STH_DOC_DATE AS invoice_date, h.BRCH_ID AS company_branch,
       COALESCE(h.LAST_MODIFY_DATE, h.CREATE_DATE) AS last_modify
FROM STO_TRANS_HEADER_$suffix h
LEFT JOIN SUPPLIERS s ON s.SUPP_ID = h.SUPP_ID
WHERE h.SSTT_ID IN (2,6) $wmClause
ORDER BY COALESCE(h.LAST_MODIFY_DATE, h.CREATE_DATE);
"@

    try {
        $headRows = Invoke-Sql $headSql
    } catch {
        Write-Log "[$suffix] header query failed: $($_.Exception.Message)"
        $okInvoices = $false
        continue
    }

    if ($headRows.Rows.Count -eq 0) { continue }

    # collect ids + max modify date
    $ids = @()
    $maxModify = $wm
    $hdrOut = New-Object System.Collections.ArrayList
    foreach ($r in $headRows.Rows) {
        $ids += [int64]$r['sth_id']
        $lm = $r['last_modify']
        if ($lm -isnot [DBNull]) {
            # Milliseconds matter. Without .fff the watermark rounds DOWN and
            # the newest invoice keeps satisfying "> watermark" on every run,
            # forever. That is what flooded the server on 2026-08-08.
            # Needs hq_invoice_sync_state.last_modify to be VARCHAR(30) -
            # see upgrade_sync_precision.sql. A DATETIME column would round
            # the value off again on the way in.
            $lmStr = ([datetime]$lm).ToString('yyyy-MM-dd HH:mm:ss.fff')
            if (-not $maxModify -or $lmStr -gt $maxModify) { $maxModify = $lmStr }
        }
        $null = $hdrOut.Add([ordered]@{
            sth_id         = [int64]$r['sth_id']
            suffix         = $suffix
            doc_no         = [string]$r['doc_no']
            doc_type       = [int]$r['doc_type']
            supplier_id    = if ($r['supplier_id'] -is [DBNull]) { $null } else { [int]$r['supplier_id'] }
            supplier_code  = [string]$r['supplier_code']
            supplier_name  = [string]$r['supplier_name']
            net_amount     = [double]$r['net_amount']
            total_amount   = [double]$r['total_amount']
            invoice_date   = if ($r['invoice_date'] -is [DBNull]) { $null } else { ([datetime]$r['invoice_date']).ToString('yyyy-MM-dd HH:mm:ss') }
            company_branch = if ($r['company_branch'] -is [DBNull]) { $null } else { [int]$r['company_branch'] }
            last_modify    = if ($r['last_modify'] -is [DBNull]) { $null } else { ([datetime]$r['last_modify']).ToString('yyyy-MM-dd HH:mm:ss') }
        })
    }

    $newState[$suffix] = $maxModify
    Write-Log "[$suffix] $($hdrOut.Count) changed invoice(s)."

    # -----------------------------------------------------------------
    #  Fingerprint before sending. THIS IS THE FIX FOR 2026-08-08.
    #
    #  The watermark is stored as 'yyyy-MM-dd HH:mm:ss' with no
    #  milliseconds, but SQL Server keeps them. So the newest invoice
    #  of the month always satisfies  LAST_MODIFY_DATE > watermark
    #  (10:00:00.457 > 10:00:00) and came back on EVERY run forever.
    #
    #  That is why the log showed "1 changed invoice(s)" for all three
    #  months, every two minutes, all day. Six pointless uploads per
    #  run, ~4300 a day, on top of the unposted section. Hostinger read
    #  the volume as an attack and dropped the whole office IP.
    #
    #  Items, codes and balances never had this problem because they go
    #  through Send-Batch, which fingerprints. Invoices called
    #  Send-Payload directly and skipped that check entirely.
    #
    #  Two layers now, on purpose:
    #    - the watermark carries milliseconds (below), so SQL Server
    #      stops returning the boundary invoice at all
    #    - the fingerprint catches anything the watermark still misses
    #
    #  The fingerprint covers the ROWS only. sync_state is excluded, so
    #  a watermark that ticks forward with no real data change does not
    #  count as a change.
    # -----------------------------------------------------------------
    $hdrFinger = @{ kind = 'invoices'; suffix = $suffix; rows = $hdrOut } | ConvertTo-Json -Depth 5 -Compress
    if (-not (Test-Changed "invoices_$suffix" $hdrFinger)) {
        Write-Log "[$suffix] unchanged - nothing to send."
        continue
    }

    $monthOk = $true

    # send headers (with the new watermark for this month)
    $hdrPayload = @{ token = $SyncToken; kind = 'invoices'; rows = $hdrOut; sync_state = @{ $suffix = $maxModify } } | ConvertTo-Json -Depth 5 -Compress
    if (-not (Send-Payload $hdrPayload "invoices/$suffix")) { $okInvoices = $false; if ($script:LinkDown) { break }; continue }

    # lines for the same invoices, in id chunks
    $idChunks = @()
    for ($i = 0; $i -lt $ids.Count; $i += 500) {
        $idChunks += ,($ids[$i..([math]::Min($i+499, $ids.Count-1))])
    }

    foreach ($chunk in $idChunks) {
        $idList = ($chunk -join ',')
        $lineSql = @"
SET NOCOUNT ON;
SELECT l.STH_ID AS sth_id, l.STL_LINE_NO AS line_no, l.ITEM_ID AS item_id,
       LTRIM(RTRIM(ic.IC_CODE)) AS item_code,
       LTRIM(RTRIM(ic.IC_NAME1)) AS item_name,
       l.STL_QTY AS quantity, l.STL_ITEM_PRICE AS unit_price, l.STL_ITEM_COST AS cost_price
FROM STO_TRANS_LINES_$suffix l
LEFT JOIN ITEM_CODES ic ON ic.IC_ID = l.IC_ID
WHERE l.STH_ID IN ($idList)
ORDER BY l.STH_ID, l.STL_LINE_NO;
"@
        try {
            $lineRows = Invoke-Sql $lineSql
        } catch {
            Write-Log "[$suffix] lines query failed: $($_.Exception.Message)"
            $okInvoices = $false
            continue
        }

        $lnOut = New-Object System.Collections.ArrayList
        foreach ($r in $lineRows.Rows) {
            $null = $lnOut.Add([ordered]@{
                sth_id     = [int64]$r['sth_id']
                suffix     = $suffix
                line_no    = [int]$r['line_no']
                item_id    = if ($r['item_id'] -is [DBNull]) { $null } else { [int]$r['item_id'] }
                item_code  = [string]$r['item_code']
                item_name  = [string]$r['item_name']
                quantity   = [double]$r['quantity']
                unit_price = [double]$r['unit_price']
                cost_price = [double]$r['cost_price']
            })
        }

        if ($lnOut.Count -gt 0) {
            $lnPayload = @{ token = $SyncToken; kind = 'invoice_lines'; rows = $lnOut } | ConvertTo-Json -Depth 5 -Compress
            if (-not (Send-Payload $lnPayload "lines/$suffix")) { $okInvoices = $false; $monthOk = $false }
        }
        if ($script:LinkDown) { $monthOk = $false; break }
    }

    # Remember this month only if the headers AND every line chunk landed.
    # A half-sent month must come back next run, so we deliberately do not
    # record the fingerprint here.
    if ($monthOk) { Set-Sent "invoices_$suffix" $hdrFinger }

    if ($script:LinkDown) { break }
}

# ---------------------------------------------------------------------
#  4) BALANCES (COM_STO_ITEMS) - full snapshot every run
#
#  Balances change constantly (every sale), so no smart sync here -
#  we send the whole set each time, in chunks. The website replaces
#  its copy. The number shown = last sync.
# ---------------------------------------------------------------------
$balSql = @"
SET NOCOUNT ON;
SELECT ITEM_ID AS item_id, STO_ID AS sto_id,
       CAST(CSI_CURRENT_BALANCE AS DECIMAL(16,3)) AS balance
FROM COM_STO_ITEMS
WHERE CSI_CURRENT_BALANCE <> 0
ORDER BY ITEM_ID, STO_ID;
"@

$okBalances = $true
try {
    $balTable = Invoke-Sql $balSql
    Write-Log "Read $($balTable.Rows.Count) balance rows."

    # Build the whole set once so we can fingerprint it before sending.
    $balAll = New-Object System.Collections.ArrayList
    foreach ($r in $balTable.Rows) {
        $null = $balAll.Add([ordered]@{
            item_id = [int]$r['item_id']
            sto_id  = [int]$r['sto_id']
            balance = [double]$r['balance']
        })
    }
    $balJson = $balAll | ConvertTo-Json -Depth 4 -Compress

    if (-not (Test-Changed 'balances' $balJson)) {
        Write-Log "[balances] unchanged - nothing to send."
    } else {
        # No reset any more. Rows are written over the old ones as they
        # arrive, each stamped with this run's batch id, and only at the
        # very end do we delete whatever did not arrive. The table is
        # never empty, not even for a moment.
        $chunk = New-Object System.Collections.ArrayList
        $chunkSize = 5000
        $sent = 0
        foreach ($row in $balAll) {
            $null = $chunk.Add($row)
            if ($chunk.Count -ge $chunkSize) {
                $p = @{ token = $SyncToken; kind = 'balances'; batch = $BatchId; rows = $chunk } | ConvertTo-Json -Depth 4 -Compress
                if (-not (Send-Payload $p "balances/$sent")) { $okBalances = $false; break }
                $sent += $chunk.Count
                $chunk.Clear()
            }
        }
        if ($okBalances -and $chunk.Count -gt 0) {
            $p = @{ token = $SyncToken; kind = 'balances'; batch = $BatchId; rows = $chunk } | ConvertTo-Json -Depth 4 -Compress
            if (-not (Send-Payload $p "balances/$sent")) { $okBalances = $false }
        }

        # Commit: drop rows that belong to an older batch (balance hit
        # zero, or the item is gone). Only if every chunk landed.
        if ($okBalances) {
            $c = @{ token = $SyncToken; kind = 'balances_commit'; batch = $BatchId; rows = @() } | ConvertTo-Json -Depth 3 -Compress
            if (Send-Payload $c 'balances/commit') {
                Set-Sent 'balances' $balJson
            } else {
                $okBalances = $false
            }
        }
    }
} catch {
    Write-Log "Balances query failed: $($_.Exception.Message)"
    $okBalances = $false
}

# ---------------------------------------------------------------------
#  5) UNPOSTED INVOICES (STO_TRANS_HEADER_YY_MM, STH_STATUS=0)
#
#  These change fast (an invoice disappears once it is posted), so we
#  do a full replace each run - headers + their lines.
# ---------------------------------------------------------------------
$okUnposted = $true
try {
    $unpSql = @"
SET NOCOUNT ON;
SELECT h.STH_ID AS sth_id, LTRIM(RTRIM(h.STH_DOC_NO)) AS doc_no, h.SSTT_ID AS doc_type,
       h.SUPP_ID AS supplier_id, LTRIM(RTRIM(s.SUPP_CODE)) AS supplier_code,
       LTRIM(RTRIM(s.SUPP_NAME1)) AS supplier_name,
       h.STH_NET_VALUE AS net_amount, h.STH_TOT_VALUE AS total_amount,
       h.STH_DOC_DATE AS invoice_date, h.BRCH_ID AS company_branch,
       LTRIM(RTRIM(h.STH_HEAD_REMARK1)) AS remark
FROM STO_TRANS_HEADER_YY_MM h
LEFT JOIN SUPPLIERS s ON s.SUPP_ID = h.SUPP_ID
WHERE h.SSTT_ID IN (2,6) AND h.STH_STATUS = 0
ORDER BY h.STH_DOC_DATE DESC;
"@

    $unpTable = Invoke-Sql $unpSql
    Write-Log "Read $($unpTable.Rows.Count) unposted invoice(s)."

    # Nothing unposted at all? Tell the site to clear both tables - but
    # only once. Before the fingerprint this fired on every single run,
    # which on a quiet day was ~720 pointless requests.
    if ($unpTable.Rows.Count -eq 0) {
        if (Test-Changed 'unposted' 'EMPTY') {
            $c = @{ token = $SyncToken; kind = 'unposted_commit'; batch = ''; rows = @() } | ConvertTo-Json -Depth 3 -Compress
            if (Send-Payload $c 'unposted/clear') { Set-Sent 'unposted' 'EMPTY' } else { $okUnposted = $false }
        } else {
            Write-Log "[unposted] unchanged (still empty) - nothing to send."
        }
    }

    if ($okUnposted -and $unpTable.Rows.Count -gt 0) {
        $unpIds = @()
        $unpOut = New-Object System.Collections.ArrayList
        foreach ($r in $unpTable.Rows) {
            $unpIds += [int64]$r['sth_id']
            $null = $unpOut.Add([ordered]@{
                sth_id         = [int64]$r['sth_id']
                doc_no         = [string]$r['doc_no']
                doc_type       = [int]$r['doc_type']
                supplier_id    = if ($r['supplier_id'] -is [DBNull]) { $null } else { [int]$r['supplier_id'] }
                supplier_code  = [string]$r['supplier_code']
                supplier_name  = [string]$r['supplier_name']
                net_amount     = [double]$r['net_amount']
                total_amount   = [double]$r['total_amount']
                invoice_date   = if ($r['invoice_date'] -is [DBNull]) { $null } else { ([datetime]$r['invoice_date']).ToString('yyyy-MM-dd HH:mm:ss') }
                company_branch = if ($r['company_branch'] -is [DBNull]) { $null } else { [int]$r['company_branch'] }
                remark         = [string]$r['remark']
            })
        }

        # ---------------------------------------------------------------
        #  Read the lines BEFORE sending anything, so headers and lines
        #  can share one fingerprint.
        #
        #  Fingerprinting the headers alone would be wrong: an invoice
        #  can keep the same header while a line quantity changes, and
        #  that edit has to reach the site.
        #
        #  This section used to send 4 requests every single run whether
        #  or not anything moved (clear / unposted / lines / commit).
        #  Together with the invoice sections that is what took the
        #  agent to ~300 requests an hour.
        # ---------------------------------------------------------------
        $unpLnOut = New-Object System.Collections.ArrayList
        if ($unpIds.Count -gt 0) {
            $idList = ($unpIds -join ',')
            $unpLineSql = @"
SET NOCOUNT ON;
SELECT l.STH_ID AS sth_id, l.STL_LINE_NO AS line_no, l.ITEM_ID AS item_id,
       LTRIM(RTRIM(ic.IC_CODE)) AS item_code, LTRIM(RTRIM(ic.IC_NAME1)) AS item_name,
       l.STL_QTY AS quantity, l.STL_ITEM_PRICE AS unit_price, l.STL_ITEM_COST AS cost_price
FROM STO_TRANS_LINES_YY_MM l
LEFT JOIN ITEM_CODES ic ON ic.IC_ID = l.IC_ID
WHERE l.STH_ID IN ($idList)
ORDER BY l.STH_ID, l.STL_LINE_NO;
"@
            $unpLineTable = Invoke-Sql $unpLineSql
            foreach ($r in $unpLineTable.Rows) {
                $null = $unpLnOut.Add([ordered]@{
                    sth_id     = [int64]$r['sth_id']
                    line_no    = [int]$r['line_no']
                    item_id    = if ($r['item_id'] -is [DBNull]) { $null } else { [int]$r['item_id'] }
                    item_code  = [string]$r['item_code']
                    item_name  = [string]$r['item_name']
                    quantity   = [double]$r['quantity']
                    unit_price = [double]$r['unit_price']
                    cost_price = [double]$r['cost_price']
                })
            }
        }

        # One fingerprint over headers + lines. BatchId is excluded on
        # purpose - it changes every run and would defeat the check.
        $unpFinger = @{ kind = 'unposted'; heads = $unpOut; lines = $unpLnOut } | ConvertTo-Json -Depth 5 -Compress

        if (-not (Test-Changed 'unposted' $unpFinger)) {
            Write-Log "[unposted] unchanged - nothing to send."
        } else {
            $unpPayload = @{ token = $SyncToken; kind = 'unposted'; batch = $BatchId; rows = $unpOut } | ConvertTo-Json -Depth 5 -Compress
            if (-not (Send-Payload $unpPayload 'unposted')) { $okUnposted = $false }

            if ($okUnposted -and $unpLnOut.Count -gt 0) {
                $unpLnPayload = @{ token = $SyncToken; kind = 'unposted_lines'; rows = $unpLnOut } | ConvertTo-Json -Depth 5 -Compress
                if (-not (Send-Payload $unpLnPayload 'unposted_lines')) { $okUnposted = $false }
            }

            # Commit last: drop the invoices that got posted or cancelled,
            # and their lines. Headers and lines are both in place by now,
            # so nobody ever sees an invoice with no items.
            if ($okUnposted) {
                $c = @{ token = $SyncToken; kind = 'unposted_commit'; batch = $BatchId; rows = @() } | ConvertTo-Json -Depth 3 -Compress
                if (Send-Payload $c 'unposted/commit') {
                    Set-Sent 'unposted' $unpFinger
                } else {
                    $okUnposted = $false
                }
            }
        }
    }
} catch {
    Write-Log "Unposted query failed: $($_.Exception.Message)"
    $okUnposted = $false
}

# ---------------------------------------------------------------------
#  6) ITEM CATEGORIES (first category)
#
#  Source: ITEMS.IS1_ID  ->  ITEM_SPEC1.IS1_NAME1
#  Used by the book reservation screen to show ONLY items whose first
#  category is "external books" (IS1_ID 1000032 on this database).
#
#  Small and slow changing, so the fingerprint means most runs send
#  nothing at all.
# ---------------------------------------------------------------------
$catNamesSql = @"
SET NOCOUNT ON;
SELECT IS1_ID AS is1_id, LTRIM(RTRIM(IS1_NAME1)) AS name
FROM ITEM_SPEC1
WHERE IS1_ID > 0 AND IS1_NAME1 IS NOT NULL;
"@

$itemCatsSql = @"
SET NOCOUNT ON;
SELECT ITEM_ID AS item_id,
       ISNULL(IS1_ID, 0) AS is1_id,
       LTRIM(RTRIM(ITEM_NAME1)) AS name
FROM ITEMS
WHERE ITEM_ID > 0 AND ISNULL(ITEM_DEL_FLAG, 0) = 0;
"@

$okCats = $true
try {
    $catNamesTable = Invoke-Sql $catNamesSql
    $catNameRows = New-Object System.Collections.ArrayList
    foreach ($r in $catNamesTable.Rows) {
        $null = $catNameRows.Add([ordered]@{
            is1_id = [int]$r['is1_id']
            name   = [string]$r['name']
        })
    }
    if (-not (Send-Batch 'cat_names' $catNameRows)) { $okCats = $false }
} catch {
    Write-Log "Cat names query failed: $($_.Exception.Message)"
    $okCats = $false
}

try {
    $itemCatsTable = Invoke-Sql $itemCatsSql
    Write-Log "Read $($itemCatsTable.Rows.Count) item category rows."
    $catRows = New-Object System.Collections.ArrayList
    foreach ($r in $itemCatsTable.Rows) {
        $null = $catRows.Add([ordered]@{
            item_id = [int]$r['item_id']
            is1_id  = [int]$r['is1_id']
            name    = [string]$r['name']
        })
    }
    # Chunked because this is one row per item (~14k rows).
    $catPayload = @{ token = $SyncToken; kind = 'item_cats'; rows = $catRows } | ConvertTo-Json -Depth 4 -Compress
    if (-not (Test-Changed 'item_cats' $catPayload)) {
        Write-Log "[item_cats] unchanged - nothing to send."
    } else {
        $catChunk = 4000
        $catOk = $true
        for ($i = 0; $i -lt $catRows.Count; $i += $catChunk) {
            $end = [Math]::Min($i + $catChunk - 1, $catRows.Count - 1)
            $slice = $catRows[$i..$end]
            $p = @{ token = $SyncToken; kind = 'item_cats'; rows = $slice } | ConvertTo-Json -Depth 4 -Compress
            if (-not (Send-Payload $p "item_cats $i-$end")) { $catOk = $false; break }
        }
        if ($catOk) { Set-Sent 'item_cats' $catPayload } else { $okCats = $false }
    }
} catch {
    Write-Log "Item cats query failed: $($_.Exception.Message)"
    $okCats = $false
}

# ---------------------------------------------------------------------
#  7) PURCHASED ITEMS - every ITEM_ID that ever appeared on a purchase
#
#  The book reservation screen shows "arrived / not arrived" per book.
#  Comparing by ITEM_ID only: one item has several barcodes (main +
#  sub codes) and they all share the same ITEM_ID, so comparing by
#  code or name gives wrong answers.
#
#  We walk EVERY monthly STO_TRANS_LINES table (not just the mirrored
#  months) so the answer covers the whole life of the system. The
#  result is a plain list of ids - tiny even after years.
#
#  Batch stamped like balances: rows are overwritten in place and the
#  leftovers are deleted at the end, so the table is never empty.
# ---------------------------------------------------------------------
$okPurchased = $true
try {
    # Find the monthly line tables that actually exist
    $tblSql = @"
SET NOCOUNT ON;
SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME LIKE 'STO_TRANS_LINES[_][0-9][0-9][_][0-9][0-9]'
ORDER BY TABLE_NAME;
"@
    $tblTable = Invoke-Sql $tblSql
    $unionParts = New-Object System.Collections.ArrayList
    foreach ($r in $tblTable.Rows) {
        $t = [string]$r['TABLE_NAME']
        $suffix = $t.Replace('STO_TRANS_LINES_', '')
        # Only purchases and purchase returns: SSTT_ID 2 and 6
        $null = $unionParts.Add("SELECT l.ITEM_ID FROM [$t] l JOIN [STO_TRANS_HEADER_$suffix] h ON h.STH_ID = l.STH_ID WHERE h.SSTT_ID IN (2,6)")
    }
    # The unsaved-invoice table too, so a book counts the moment it is entered
    $null = $unionParts.Add("SELECT l.ITEM_ID FROM [STO_TRANS_LINES_YY_MM] l JOIN [STO_TRANS_HEADER_YY_MM] h ON h.STH_ID = l.STH_ID WHERE h.SSTT_ID IN (2,6)")

    if ($unionParts.Count -eq 0) {
        Write-Log "No monthly line tables found - purchased items skipped."
    } else {
        $purSql = "SET NOCOUNT ON;`nSELECT DISTINCT ITEM_ID AS item_id FROM (`n" + ($unionParts -join "`nUNION ALL`n") + "`n) x WHERE ITEM_ID > 0;"
        $purTable = Invoke-Sql $purSql
        Write-Log "Read $($purTable.Rows.Count) purchased item ids."

        $purRows = New-Object System.Collections.ArrayList
        foreach ($r in $purTable.Rows) {
            $null = $purRows.Add([ordered]@{ item_id = [int]$r['item_id'] })
        }

        $purFinger = @{ kind = 'purchased_items'; rows = $purRows } | ConvertTo-Json -Depth 3 -Compress
        if (-not (Test-Changed 'purchased_items' $purFinger)) {
            Write-Log "[purchased_items] unchanged - nothing to send."
        } elseif ($purRows.Count -eq 0) {
            Write-Log "[purchased_items] empty result - not sending (would wipe the table)."
        } else {
            $purChunk = 5000
            $purOk = $true
            for ($i = 0; $i -lt $purRows.Count; $i += $purChunk) {
                $end = [Math]::Min($i + $purChunk - 1, $purRows.Count - 1)
                $slice = $purRows[$i..$end]
                $p = @{ token = $SyncToken; kind = 'purchased_items'; batch = $BatchId; rows = $slice } | ConvertTo-Json -Depth 3 -Compress
                if (-not (Send-Payload $p "purchased_items $i-$end")) { $purOk = $false; break }
            }
            if ($purOk) {
                $c = @{ token = $SyncToken; kind = 'purchased_commit'; batch = $BatchId; rows = @() } | ConvertTo-Json -Depth 3 -Compress
                if (Send-Payload $c 'purchased/commit') { Set-Sent 'purchased_items' $purFinger } else { $purOk = $false }
            }
            if (-not $purOk) { $okPurchased = $false }
        }
    }
} catch {
    Write-Log "Purchased items query failed: $($_.Exception.Message)"
    $okPurchased = $false
}

# Save the fingerprints of everything that landed successfully, then
# release the lock so the next scheduled run can start.
Save-State
if ($LockMine) { Remove-Item $LockFile -Force -ErrorAction SilentlyContinue }

$elapsed = [int]((Get-Date) - $RunStarted).TotalSeconds

if ($okItems -and $okCodes -and $okStores -and $okInvoices -and $okBalances -and $okUnposted -and $okCats -and $okPurchased) {
    Write-Log "--- HQ sync finished OK in $elapsed sec ---"
    exit 0
} else {
    Write-Log "--- HQ sync finished WITH ERRORS in $elapsed sec ---"
    exit 1
}


# =====================================================================
#  SCHEDULED TASK SETUP  (hidden, every 2 min, and at startup)
# =====================================================================
#
#  IMPORTANT - Windows Auth users: the task runs as SYSTEM. Grant it
#  read access once in SSMS:
#
#    USE [master];
#    CREATE LOGIN [NT AUTHORITY\SYSTEM] FROM WINDOWS;
#    USE [Anzmah_FagalaHQ];
#    CREATE USER [NT AUTHORITY\SYSTEM] FOR LOGIN [NT AUTHORITY\SYSTEM];
#    ALTER ROLE [db_datareader] ADD MEMBER [NT AUTHORITY\SYSTEM];
#
#  (Skip any line that says "already exists".)
#
#  Open PowerShell AS ADMINISTRATOR and paste:
#
#    $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument '-ExecutionPolicy Bypass -WindowStyle Hidden -NonInteractive -File "C:\fagala\agent_hq.ps1"'
#
#    $t1 = New-ScheduledTaskTrigger -AtStartup
#    $t1.Delay = 'PT2M'
#
#    $t2 = New-ScheduledTaskTrigger -Once -At (Get-Date)
#    $t2.Repetition = (New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 2)).Repetition
#
#    $set = New-ScheduledTaskSettingsSet -StartWhenAvailable -DontStopOnIdleEnd -DontStopIfGoingOnBatteries -AllowStartIfOnBatteries -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 5) -ExecutionTimeLimit (New-TimeSpan -Minutes 15) -MultipleInstances IgnoreNew
#
#    Register-ScheduledTask -TaskName 'FagalaHQSync' -Action $action -Trigger @($t1,$t2) -Settings $set -RunLevel Highest -User 'SYSTEM' -Force
#
#  CHECK:
#    Get-ScheduledTaskInfo -TaskName 'FagalaHQSync'
#    Start-ScheduledTask   -TaskName 'FagalaHQSync'
#    Get-Content C:\fagala\agent_hq.log -Tail 20
#
# ---------------------------------------------------------------------
#  ALREADY REGISTERED? Just change the interval - one command,
#  as ADMINISTRATOR. Do this LAST, after the agent runs clean by hand.
#
#    $t = New-ScheduledTaskTrigger -Once -At (Get-Date)
#    $t.Repetition = (New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 5)).Repetition
#    Set-ScheduledTask -TaskName 'FagalaHQSync' -Trigger $t
#
# ---------------------------------------------------------------------
#  WHY 5 MINUTES AND NOT 2 - read this before shortening it again.
#
#  This block used to say two minutes was safe because "unchanged data
#  is not uploaded at all (fingerprints)". That was only true for
#  items, codes and balances. Invoices and unposted called Send-Payload
#  directly and never checked a fingerprint, so they re-uploaded the
#  same rows every single run.
#
#  Measured from agent_hq.log on 2026-08-08, before the fix:
#      30 runs/hour x ~10 requests = ~300 requests/hour
#      ~7,200 requests/day from one office IP, around the clock
#
#  Hostinger's firewall read that as an attack and dropped the whole
#  office IP - the site went dark for every device in the building.
#
#  With fingerprints on every section, a run with no new data now sends
#  ZERO requests. Five minutes with real dedup is far lighter than two
#  minutes without it, and leaves headroom if a section regresses.
#
#  To go back to 30 minutes, run the same thing with -Minutes 30.
#
#  HEALTH CHECK - after any change, watch the log for a few runs:
#    Get-Content C:\fagala\agent_hq.log -Tail 40
#
#  A quiet run should say "unchanged - nothing to send" for EVERY
#  section, including invoices and unposted, and finish in a few
#  seconds. If any section uploads on every run while nothing is
#  actually changing in the ERP, that is the same bug coming back -
#  stop and fix it before it builds up again.
# =====================================================================
