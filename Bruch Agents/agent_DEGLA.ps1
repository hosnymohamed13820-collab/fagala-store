# =====================================================================
#  agent_branch.ps1 - branch stock balance agent
# =====================================================================
#  Runs on the PC INSIDE each branch. Reads COM_STO_ITEMS from that
#  branch's own SQL Server and pushes balances to the website.
#
#  WHY THIS EXISTS
#    Balances used to reach the site only through HQ, and HQ only
#    updates after "Tarheel" (posting) runs. When posting jams, the
#    site shows stale stock and people make decisions on it.
#    COM_STO_ITEMS in the branch is the ORIGINAL - the POS updates it
#    the moment a sale happens. This agent reads that directly, so the
#    site no longer waits on posting.
#
#  DELTA, NOT SNAPSHOT - read this before changing it
#    A full snapshot every 2 minutes would be roughly 20k rows x 720
#    runs x 5 branches = ~72 million MySQL writes a day. Hostinger
#    would fall over. So we keep the last snapshot in a local file,
#    compare, and send ONLY what moved - usually a few dozen rows.
#    A branch with no sales sends zero rows (just a heartbeat).
#
#  ASCII-only (no Arabic) so Windows PowerShell never mis-parses it.
#
#  SETUP PER BRANCH - only the six lines under SETTINGS change.
#    1) Put this file at  C:\fagala\agent_branch.ps1
#    2) Set $AgentKey + $SqlDatabase + $MyStoId + $SyncToken for THIS branch (table below)
#    3) Test from an ADMIN PowerShell:
#         powershell -ExecutionPolicy Bypass -File "C:\fagala\agent_DEGLA.ps1"
#    4) If it prints "finished OK", register the scheduled task
#       (commands at the bottom)
#
#      Branch    $AgentKey    $SqlDatabase
#      -------   ----------   -------------------
#      Degla     degla        Anzmah_DeglaStore
#      Bonjour   bonjour      Anzmah_Bonjour
#      ABC       abc          Anzmah_ABCStore
#      Fagala    zahraa       Anzmah_Zahraa
# =====================================================================

# ===== SETTINGS - EDIT THESE =====

# Which branch is this? Must match agent_status.agent_key on the site.
$AgentKey    = 'degla'

# This branch's own database, on this same PC.
$SqlServer   = 'localhost'
$SqlDatabase = 'Anzmah_DeglaStore'

# This branch's own store id in the company system (COM_STO_ITEMS.STO_ID).
# WHY THIS FILTER EXISTS - do not remove it:
#   Every branch database holds ALL six stores, not just its own (~14,827
#   items x 6 = ~89k rows). Without the filter each agent uploads all six
#   and the four agents overwrite each other on the website: the key of
#   hq_branch_balances is (item_id, sto_id) with NO branch column, so the
#   last agent to write wins. With the filter every store is written by
#   the ONE branch that actually owns it - and the upload drops ~6x.
#   Store map: 1 Main | 2 Fagala-Zahraa | 3 Bonjour | 4 Mobile | 5 Degla | 6 ABC
$MyStoId     = 5

# This branch's OWN secret token (since 2026-09-22 every agent has its own).
# The site checks it against $AgentKey, so one branch PC can no longer
# post data under another branch's name.
# Must equal SYNC_AGENT_TOKENS[$AgentKey] in site/sync_tokens.php.
$SyncToken   = 'degla_42e23f48817465124b78c8d6237e27e93259a154'

# Authentication: $true = Windows Auth (no user/pass), $false = SQL login
$UseWindowsAuth = $true
$SqlUser     = 'sa'
$SqlPassword = ''

# ===== SHARED - same on every branch =====

$BalanceUrl = 'https://hosnimanagement.com/sync_branch.php'
$BeatUrl    = 'https://hosnimanagement.com/sync_beat.php'
$AgentVer   = '1.2'

# Send everything (ignore the delta) once every N runs, so a lost
# update can never stay lost forever. 180 runs x 5 min = ~15 hours.
$FullEvery  = 180

# ===== DO NOT EDIT BELOW =====

$ErrorActionPreference = 'Stop'
$RunStarted = Get-Date

$LogFile   = Join-Path $PSScriptRoot "agent_$AgentKey.log"
$StateFile = Join-Path $PSScriptRoot "agent_$AgentKey.state.json"
$LockFile  = Join-Path $PSScriptRoot "agent_$AgentKey.lock"

function Write-Log {
    param([string]$Message)
    $line = "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')  $Message"
    Write-Host $line
    try { Add-Content -Path $LogFile -Value $line -Encoding UTF8 } catch { }
}

# Trim the log so it can never eat the disk
try {
    if ((Test-Path $LogFile) -and ((Get-Item $LogFile).Length -gt 2MB)) {
        Get-Content $LogFile -Tail 500 | Set-Content "$LogFile.tmp" -Encoding UTF8
        Move-Item "$LogFile.tmp" $LogFile -Force
    }
} catch { }

# ---------------------------------------------------------------------
#  RUN LOCK - only one run at a time, ever.
#  Two copies writing the same rows at once = corrupted balances.
#  A lock older than 30 minutes is treated as dead (crash / reboot).
# ---------------------------------------------------------------------
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
            # no-beat: the run that holds the lock sends the heartbeat. A second
            #          beat here would report two runs for one cycle.
            Write-Log "Another run is still going (pid $lockPid) - exiting."
            exit 0
        }
        Write-Log "Stale lock found (age $([int]$lockAge.TotalMinutes) min) - taking over."
        Remove-Item $LockFile -Force -ErrorAction SilentlyContinue
    }
    Set-Content -Path $LockFile -Value $PID -Encoding ASCII
    $LockMine = $true
} catch {
    # no-beat: cannot even take the lock - the disk or the folder is the
    #          problem. Going silent is correct: the dashboard SHOULD show
    #          this agent as stalled instead of "alive but idle".
    Write-Log "Could not take the lock: $($_.Exception.Message)"
    exit 1
}

function Release-Lock {
    if ($LockMine) { Remove-Item $LockFile -Force -ErrorAction SilentlyContinue }
}

# ---------------------------------------------------------------------
#  Replication queue (Anzmah db_repl_receive). The replication stops at
#  the first command that fails and never writes why, so the queue just
#  grows in silence (Sep 20-23 2026: 3 days, 797 then 1574 rows).
#  We report: how many are waiting, minutes since the oldest one ARRIVED
#  (drr_import_date - create_date is when HQ made it, can be days older),
#  and the first stuck command. Never throws: $null = "could not read".
# ---------------------------------------------------------------------
function Get-ReplQueue {
    try {
        $t = Invoke-Sql @"
SET NOCOUNT ON;
SELECT q.n, q.age_min, q.first_id,
       (SELECT LEFT(r.drr_text, 200) FROM db_repl_receive r WHERE r.drr_id = q.first_id) AS first_text
FROM (SELECT COUNT(*) AS n,
             DATEDIFF(MINUTE, MIN(ISNULL(drr_import_date, create_date)), GETDATE()) AS age_min,
             MIN(drr_id) AS first_id
        FROM db_repl_receive
       WHERE drr_execute_flag = 0) q;
"@
        if ($t.Rows.Count -lt 1) { return $null }
        $r = $t.Rows[0]
        $n = [int]$r['n']
        return @{
            waiting    = $n
            age_min    = $(if ($n -gt 0 -and $r['age_min'] -isnot [DBNull]) { [int]$r['age_min'] } else { 0 })
            first_id   = $(if ($n -gt 0 -and $r['first_id'] -isnot [DBNull]) { [string]$r['first_id'] } else { '' })
            first_text = $(if ($n -gt 0 -and $r['first_text'] -isnot [DBNull]) { [string]$r['first_text'] } else { '' })
        }
    } catch {
        Write-Log "[repl] could not read queue: $($_.Exception.Message)"
        return $null
    }
}

# ---------------------------------------------------------------------
#  Heartbeat - tiny "I am alive" ping. Never throws: a failed beat
#  must not fail the sync. Sent on EVERY path, including failure -
#  a broken query must not look like a dead PC.
# ---------------------------------------------------------------------
function Send-Beat {
    param([int]$Rows = 0, [string]$ErrorText = '')
    try {
        $body = @{
            agent   = $AgentKey
            rows    = $Rows
            error   = $ErrorText
            version = $AgentVer
            host    = $env:COMPUTERNAME
            repl    = (Get-ReplQueue)
        } | ConvertTo-Json -Compress
        $bytes = [System.Text.Encoding]::UTF8.GetBytes($body)
        $null = Invoke-RestMethod -Uri $BeatUrl -Method Post -Body $bytes `
                -ContentType 'application/json; charset=utf-8' `
                -Headers @{ 'X-Sync-Token' = $SyncToken } -TimeoutSec 20
        Write-Log "[beat] sent (rows=$Rows)"
    } catch {
        Write-Log "[beat] failed: $($_.Exception.Message)"
    }
}

function Stop-Run {
    param([int]$Rows, [string]$ErrorText, [int]$Code)
    Release-Lock
    Send-Beat -Rows $Rows -ErrorText $ErrorText
    $sec = [int]((Get-Date) - $RunStarted).TotalSeconds
    if ($Code -eq 0) { Write-Log "--- $AgentKey sync finished OK in $sec sec ---" }
    else             { Write-Log "--- $AgentKey sync FAILED in $sec sec ---" }
    exit $Code
}

# ---------------------------------------------------------------------
#  SQL helper
# ---------------------------------------------------------------------
function Invoke-Sql {
    param([string]$Query)

    if ($UseWindowsAuth) {
        $cs = "Server=$SqlServer;Database=$SqlDatabase;Integrated Security=True;Connect Timeout=30"
    } else {
        $cs = "Server=$SqlServer;Database=$SqlDatabase;User Id=$SqlUser;Password=$SqlPassword;Connect Timeout=30"
    }

    $conn = New-Object System.Data.SqlClient.SqlConnection $cs
    try {
        $conn.Open()
        $cmd = $conn.CreateCommand()
        # READ UNCOMMITTED: read without taking shared locks, so the cashier and the
        # replication never wait on us. A value mid-write can be read once; the next
        # run (2 min) corrects it. We only read - nothing here writes to this DB.
        $cmd.CommandText = "SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED;`n" + $Query
        $cmd.CommandTimeout = 120
        $ad = New-Object System.Data.SqlClient.SqlDataAdapter $cmd
        $dt = New-Object System.Data.DataTable
        $null = $ad.Fill($dt)
        # NOTE: the comma is load-bearing. "return $dt" makes PowerShell unroll
        #       the DataTable into a DataRow array, and then $table.Rows yields
        #       objects whose columns read back empty - every row ships as
        #       item_id=0 and the website rejects the lot. Do not remove it.
        return ,$dt
    } finally {
        $conn.Close()
        $conn.Dispose()
    }
}

# ---------------------------------------------------------------------
#  Upload with backoff.
#
#  Hostinger's firewall banned the office IP once already (2026-08-08)
#  after thousands of requests in a day. Retries here are few and slow
#  on purpose: a branch that cannot reach the site must go quiet, not
#  hammer it. The next scheduled run will try again anyway.
# ---------------------------------------------------------------------
function Send-Payload {
    param([string]$Json)

    $bytes = [System.Text.Encoding]::UTF8.GetBytes($Json)
    $delays = @(0, 5, 20)

    foreach ($d in $delays) {
        if ($d -gt 0) { Start-Sleep -Seconds $d }
        try {
            $resp = Invoke-RestMethod -Uri $BalanceUrl -Method Post -Body $bytes `
                    -ContentType 'application/json; charset=utf-8' `
                    -Headers @{ 'X-Sync-Token' = $SyncToken } -TimeoutSec 120
            if ($resp.ok) {
                Write-Log "[balances] stored $($resp.stored) rows."
                return $true
            }
            # Server answered, so the link is fine - this payload is bad.
            Write-Log "[balances] server rejected: $($resp.error)"
            return $false
        } catch {
            Write-Log "[balances] upload failed: $($_.Exception.Message)"
        }
    }
    return $false
}

# ---------------------------------------------------------------------
#  READ BALANCES
#
#  No WHERE on the balance value: we need zeros too. An item that
#  dropped from 5 to 0 must be sent as 0, otherwise the website keeps
#  showing 5 forever. Rows that are zero AND were zero last time get
#  dropped by the delta below, so this costs nothing.
# ---------------------------------------------------------------------
$balSql = @"
SET NOCOUNT ON;
SELECT ITEM_ID AS item_id, STO_ID AS sto_id,
       CAST(CSI_CURRENT_BALANCE AS DECIMAL(16,3)) AS balance
FROM COM_STO_ITEMS
WHERE STO_ID = $MyStoId
ORDER BY ITEM_ID, STO_ID;
"@

try {
    $table = Invoke-Sql $balSql
    Write-Log "Read $($table.Rows.Count) balance rows from $SqlDatabase."
} catch {
    $msg = "balance query failed: $($_.Exception.Message)"
    Write-Log $msg
    Stop-Run -Rows 0 -ErrorText $msg -Code 1
}

# ---------------------------------------------------------------------
#  DELTA
#
#  prev = what the website already has (our last successful push)
#  cur  = what the branch says right now
#
#  send = changed + new + (in prev, gone from cur -> send as 0)
# ---------------------------------------------------------------------
$prev = @{}
$runCount = 0
if (Test-Path $StateFile) {
    try {
        $st = Get-Content $StateFile -Raw | ConvertFrom-Json
        $runCount = [int]$st.runCount
        foreach ($p in $st.bal.PSObject.Properties) { $prev[$p.Name] = [decimal]$p.Value }
    } catch {
        Write-Log "State file unreadable - treating this run as a full send."
        $prev = @{}
    }
}

$forceFull = ($prev.Count -eq 0) -or ($runCount -ge $FullEvery)

$cur  = @{}
$send = New-Object System.Collections.ArrayList

foreach ($row in $table.Rows) {
    $key = "$($row.item_id)|$($row.sto_id)"
    $val = [decimal]$row.balance
    $cur[$key] = $val

    $changed = $true
    if (-not $forceFull -and $prev.ContainsKey($key)) {
        $changed = ($prev[$key] -ne $val)
    } elseif (-not $forceFull -and -not $prev.ContainsKey($key)) {
        # new key: only worth sending if it is not zero
        $changed = ($val -ne 0)
    }

    if ($changed) {
        $null = $send.Add(@{
            item_id = [int]$row.item_id
            sto_id  = [int]$row.sto_id
            balance = $val
        })
    }
}

# Items that vanished from the source entirely -> tell the site they are 0
if (-not $forceFull) {
    foreach ($k in $prev.Keys) {
        if (-not $cur.ContainsKey($k)) {
            $parts = $k -split '\|'
            $null = $send.Add(@{
                item_id = [int]$parts[0]
                sto_id  = [int]$parts[1]
                balance = 0
            })
            $cur[$k] = 0
        }
    }
}

if ($forceFull) {
    Write-Log "Full send (first run, or $FullEvery runs since last full)."
}
Write-Log "Delta: $($send.Count) of $($cur.Count) rows need sending."

# ---------------------------------------------------------------------
#  SEND
# ---------------------------------------------------------------------
$sent = 0
$ok   = $true

if ($send.Count -eq 0) {
    Write-Log "Nothing changed - no upload this run."
} else {
    $chunkSize = 5000
    $chunk = New-Object System.Collections.ArrayList

    foreach ($r in $send) {
        $null = $chunk.Add($r)
        if ($chunk.Count -ge $chunkSize) {
            $p = @{ agent = $AgentKey; rows = $chunk } | ConvertTo-Json -Depth 4 -Compress
            if (Send-Payload $p) { $sent += $chunk.Count } else { $ok = $false; break }
            $chunk.Clear()
        }
    }
    if ($ok -and $chunk.Count -gt 0) {
        $p = @{ agent = $AgentKey; rows = $chunk } | ConvertTo-Json -Depth 4 -Compress
        if (Send-Payload $p) { $sent += $chunk.Count } else { $ok = $false }
    }
}

# =====================================================================
#  BRANCH INVOICES & LINES (Monthly partitioned tables)
#
#  Sales (SSTT_TYPE = 3) and sale returns (SSTT_TYPE = 6).
#  Filter uses STO_SYS_TRANS_TYPES.SSTT_TYPE IN (3, 6) via JOIN so any
#  new sales transaction type added to the ERP is caught automatically.
#  Purchases (SSTT_TYPE = 2, 5) are excluded - HQ agent handles them.
# =====================================================================

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

function Test-Changed {
    param([string]$Key, [string]$Text)
    $fp = Get-Fingerprint $Text
    return -not ($State.ContainsKey($Key) -and $State[$Key] -eq $fp)
}

function Set-Sent {
    param([string]$Key, [string]$Text)
    $State[$Key] = Get-Fingerprint $Text
}

$watermarks = @{}
$State = @{}
if ($st) {
    if ($st.watermarks) {
        foreach ($p in $st.watermarks.PSObject.Properties) {
            $watermarks[$p.Name] = [string]$p.Value
        }
    }
    if ($st.fingerprints) {
        foreach ($p in $st.fingerprints.PSObject.Properties) {
            $State[$p.Name] = [string]$p.Value
        }
    }
}

$okInvoices = $true
$sentInvoices = 0
$sentInvoiceLines = 0

$StartYear  = 2026
$StartMonth = 6

$now = Get-Date
$suffixes = @()
$y = $StartYear; $m = $StartMonth
while (($y -lt $now.Year) -or ($y -eq $now.Year -and $m -le $now.Month)) {
    $suffixes += ('{0:00}_{1:00}' -f ($y % 100), $m)
    $m++; if ($m -gt 12) { $m = 1; $y++ }
}

foreach ($suffix in $suffixes) {
    $headTable = "STO_TRANS_HEADER_$suffix"

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
JOIN STO_SYS_TRANS_TYPES t ON t.SSTT_ID = h.SSTT_ID
WHERE t.SSTT_TYPE IN (3, 6) $wmClause
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

    $ids = @()
    $maxModify = $wm
    $hdrOut = New-Object System.Collections.ArrayList
    foreach ($r in $headRows.Rows) {
        $ids += [int64]$r['sth_id']
        $lm = $r['last_modify']
        if ($lm -isnot [DBNull]) {
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

    Write-Log "[$suffix] $($hdrOut.Count) changed invoice(s)."

    $hdrFinger = @{ kind = 'invoices'; suffix = $suffix; rows = $hdrOut } | ConvertTo-Json -Depth 5 -Compress
    if (-not (Test-Changed "invoices_$suffix" $hdrFinger)) {
        Write-Log "[$suffix] unchanged - nothing to send."
        continue
    }

    $monthOk = $true

    $chunkSize = 500
    for ($i = 0; $i -lt $hdrOut.Count; $i += $chunkSize) {
        $hChunk = @($hdrOut[$i..([math]::Min($i + $chunkSize - 1, $hdrOut.Count - 1))])
        $p = @{ agent = $AgentKey; kind = 'invoices'; rows = $hChunk } | ConvertTo-Json -Depth 5 -Compress
        if (Send-Payload $p) {
            $sentInvoices += $hChunk.Count
        } else {
            $monthOk = $false
            $okInvoices = $false
            break
        }
    }
    if (-not $monthOk) { continue }

    $idChunks = @()
    for ($i = 0; $i -lt $ids.Count; $i += 500) {
        $idChunks += ,($ids[$i..([math]::Min($i + 499, $ids.Count - 1))])
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
            $monthOk = $false
            break
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
            for ($j = 0; $j -lt $lnOut.Count; $j += 500) {
                $lnChunk = @($lnOut[$j..([math]::Min($j + 499, $lnOut.Count - 1))])
                $lnPayload = @{ agent = $AgentKey; kind = 'invoice_lines'; rows = $lnChunk } | ConvertTo-Json -Depth 5 -Compress
                if (Send-Payload $lnPayload) {
                    $sentInvoiceLines += $lnChunk.Count
                } else {
                    $okInvoices = $false
                    $monthOk = $false
                    break
                }
            }
        }
        if (-not $monthOk) { break }
    }

    if ($monthOk) {
        Set-Sent "invoices_$suffix" $hdrFinger
        if ($maxModify) { $watermarks[$suffix] = $maxModify }
    }
}

# =====================================================================
#  UNPOSTED INVOICES & LINES (STO_TRANS_HEADER_YY_MM, STH_STATUS=0)
#
#  Unposted sales invoices and their lines.
#  Fingerprinted over headers + lines so unchanged data sends zero requests.
# =====================================================================
$okUnposted = $true
$sentUnposted = 0
$sentUnpostedLines = 0

try {
    $unpExistsSql = "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'STO_TRANS_HEADER_YY_MM'"
    $unpExists = 0
    try {
        $unpExists = (Invoke-Sql $unpExistsSql).Rows[0]['c']
    } catch { }

    if ($unpExists -gt 0) {
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
JOIN STO_SYS_TRANS_TYPES t ON t.SSTT_ID = h.SSTT_ID
WHERE t.SSTT_TYPE IN (3, 6) AND h.STH_STATUS = 0
ORDER BY h.STH_DOC_DATE DESC;
"@
        $unpTable = Invoke-Sql $unpSql
        Write-Log "Read $($unpTable.Rows.Count) unposted invoice(s)."

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

        $unpLnOut = New-Object System.Collections.ArrayList
        if ($unpIds.Count -gt 0) {
            $unpIdChunks = @()
            for ($i = 0; $i -lt $unpIds.Count; $i += 500) {
                $unpIdChunks += ,($unpIds[$i..([math]::Min($i + 499, $unpIds.Count - 1))])
            }
            foreach ($chunk in $unpIdChunks) {
                $idList = ($chunk -join ',')
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
        }

        $unpFinger = @{ kind = 'unposted'; heads = $unpOut; lines = $unpLnOut } | ConvertTo-Json -Depth 5 -Compress

        if (-not (Test-Changed 'unposted' $unpFinger)) {
            Write-Log "[unposted] unchanged - nothing to send."
        } else {
            if ($unpOut.Count -gt 0) {
                for ($i = 0; $i -lt $unpOut.Count; $i += 500) {
                    $uChunk = @($unpOut[$i..([math]::Min($i + 499, $unpOut.Count - 1))])
                    $unpPayload = @{ agent = $AgentKey; kind = 'unposted'; rows = $uChunk } | ConvertTo-Json -Depth 5 -Compress
                    if (Send-Payload $unpPayload) {
                        $sentUnposted += $uChunk.Count
                    } else {
                        $okUnposted = $false
                        break
                    }
                }

                if ($okUnposted -and $unpLnOut.Count -gt 0) {
                    for ($j = 0; $j -lt $unpLnOut.Count; $j += 500) {
                        $lnChunk = @($unpLnOut[$j..([math]::Min($j + 499, $unpLnOut.Count - 1))])
                        $unpLnPayload = @{ agent = $AgentKey; kind = 'unposted_lines'; rows = $lnChunk } | ConvertTo-Json -Depth 5 -Compress
                        if (Send-Payload $unpLnPayload) {
                            $sentUnpostedLines += $lnChunk.Count
                        } else {
                            $okUnposted = $false
                            break
                        }
                    }
                }
            }
            if ($okUnposted) {
                Set-Sent 'unposted' $unpFinger
            }
        }
    }
} catch {
    Write-Log "Unposted query failed: $($_.Exception.Message)"
    $okUnposted = $false
}

# ---------------------------------------------------------------------
#  SAVE STATE - balances + watermarks + fingerprints
#
#  If any chunk failed we keep the OLD state for that section, so the
#  next run retries.
# ---------------------------------------------------------------------
$allOk = ($ok -and $okInvoices -and $okUnposted)

try {
    $balObj = @{}
    if ($ok) {
        foreach ($k in $cur.Keys) { $balObj[$k] = $cur[$k] }
    } else {
        foreach ($k in $prev.Keys) { $balObj[$k] = $prev[$k] }
    }
    $nextCount = if ($forceFull) { 0 } else { $runCount + 1 }
    $stateObj = @{
        runCount     = if ($ok) { $nextCount } else { $runCount }
        savedAt      = (Get-Date -Format 'o')
        bal          = $balObj
        watermarks   = $watermarks
        fingerprints = $State
    }
    $stateObj | ConvertTo-Json -Depth 5 -Compress | Set-Content $StateFile -Encoding UTF8
} catch {
    Write-Log "Could not save state: $($_.Exception.Message)"
}

$totalSent = $sent + $sentInvoices + $sentInvoiceLines + $sentUnposted + $sentUnpostedLines

if ($allOk) {
    Stop-Run -Rows $totalSent -ErrorText '' -Code 0
} else {
    $errParts = @()
    if (-not $ok)         { $errParts += 'balances' }
    if (-not $okInvoices) { $errParts += 'invoices' }
    if (-not $okUnposted) { $errParts += 'unposted' }
    Stop-Run -Rows $totalSent -ErrorText ("sync failed: " + ($errParts -join ', ')) -Code 1
}


# =====================================================================
#  SCHEDULED TASK SETUP  (hidden, every 5 min, and at startup)
# =====================================================================
#
#  Run ONCE per branch, as ADMINISTRATOR, after a clean manual run.
#  Change FagalaBranchSync to match the branch if you like.
#
#    $a = New-ScheduledTaskAction -Execute 'powershell.exe' `
#         -Argument '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "C:\fagala\agent_branch.ps1"'
#    $t1 = New-ScheduledTaskTrigger -Once -At (Get-Date)
#    $t1.Repetition = (New-ScheduledTaskTrigger -Once -At (Get-Date) `
#                      -RepetitionInterval (New-TimeSpan -Minutes 5)).Repetition
#    $t2 = New-ScheduledTaskTrigger -AtStartup
#    $p  = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
#    $s  = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries `
#          -DontStopIfGoingOnBatteries -StartWhenAvailable -MultipleInstances IgnoreNew
#    Register-ScheduledTask -TaskName 'FagalaBranchSync' -Action $a `
#          -Trigger $t1,$t2 -Principal $p -Settings $s -Force
#
# ---------------------------------------------------------------------
#  WHY 5 MINUTES AND NOT 2 - read this before shortening it.
#
#  On 2026-08-08 Hostinger's firewall banned the whole office IP after
#  ~7,200 requests in a day from an agent that re-uploaded unchanged
#  rows every run. The delta above means a quiet branch sends ZERO
#  data requests and one tiny heartbeat.
#
#  Branches are on separate IPs, which spreads the load instead of
#  stacking it on the office line - but that is headroom, not a
#  licence to poll harder. The alert threshold on the site is 20
#  minutes, so 5-minute runs already give 4 misses before anyone is
#  warned. Two minutes buys nothing and costs 2.5x the requests.
#
#  HEALTH CHECK - after any change, watch a few runs:
#    Get-Content C:\fagala\agent_degla.log -Tail 40
#
#  A quiet run should say "Nothing changed - no upload this run" and
#  finish in a couple of seconds. If it uploads thousands of rows on
#  every run while the branch is closed, the delta is broken - stop
#  and fix it before it builds up.
# =====================================================================
