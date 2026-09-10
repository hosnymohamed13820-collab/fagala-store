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
#  SETUP PER BRANCH - only the four lines under SETTINGS change.
#    1) Put this file at  C:\fagala\agent_branch.ps1
#    2) Set $AgentKey + $SqlDatabase for THIS branch (table below)
#    3) Test from an ADMIN PowerShell:
#         powershell -ExecutionPolicy Bypass -File "C:\fagala\agent_FAGALA.ps1"
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
$AgentKey    = 'zahraa'

# This branch's own database, on this same PC.
$SqlServer   = 'localhost'
$SqlDatabase = 'Anzmah_Zahraa'

# This branch's own store id in the company system (COM_STO_ITEMS.STO_ID).
# WHY THIS FILTER EXISTS - do not remove it:
#   Every branch database holds ALL six stores, not just its own (~14,827
#   items x 6 = ~89k rows). Without the filter each agent uploads all six
#   and the four agents overwrite each other on the website: the key of
#   hq_branch_balances is (item_id, sto_id) with NO branch column, so the
#   last agent to write wins. With the filter every store is written by
#   the ONE branch that actually owns it - and the upload drops ~6x.
#   Store map: 1 Main | 2 Fagala-Zahraa | 3 Bonjour | 4 Mobile | 5 Degla | 6 ABC
$MyStoId     = 2

# Authentication: $true = Windows Auth (no user/pass), $false = SQL login
$UseWindowsAuth = $true
$SqlUser     = 'sa'
$SqlPassword = ''

# ===== SHARED - same on every branch =====

$BalanceUrl = 'https://hosnimanagement.com/sync_branch.php'
$BeatUrl    = 'https://hosnimanagement.com/sync_beat.php'
$SyncToken  = 'hq_7Kp9mXq2Rv5Nw8Lt3Bz6Yd4Hs1Fj0Gc'
$AgentVer   = '1.0'

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
            Write-Log "Another run is still going (pid $lockPid) - exiting."
            exit 0
        }
        Write-Log "Stale lock found (age $([int]$lockAge.TotalMinutes) min) - taking over."
        Remove-Item $LockFile -Force -ErrorAction SilentlyContinue
    }
    Set-Content -Path $LockFile -Value $PID -Encoding ASCII
    $LockMine = $true
} catch {
    Write-Log "Could not take the lock: $($_.Exception.Message)"
    exit 1
}

function Release-Lock {
    if ($LockMine) { Remove-Item $LockFile -Force -ErrorAction SilentlyContinue }
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
        $cmd.CommandText = $Query
        $cmd.CommandTimeout = 120
        $ad = New-Object System.Data.SqlClient.SqlDataAdapter $cmd
        $dt = New-Object System.Data.DataTable
        $null = $ad.Fill($dt)
        return $dt
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

# ---------------------------------------------------------------------
#  SAVE STATE - only if everything landed.
#
#  If any chunk failed we keep the OLD state, so the next run resends
#  those rows. Saving on a partial success is how balances silently
#  drift out of sync and nobody notices.
# ---------------------------------------------------------------------
if ($ok) {
    try {
        $balObj = @{}
        foreach ($k in $cur.Keys) { $balObj[$k] = $cur[$k] }
        $nextCount = if ($forceFull) { 0 } else { $runCount + 1 }
        @{ runCount = $nextCount; savedAt = (Get-Date -Format 'o'); bal = $balObj } |
            ConvertTo-Json -Depth 4 -Compress | Set-Content $StateFile -Encoding UTF8
    } catch {
        Write-Log "Could not save state: $($_.Exception.Message)"
    }
} else {
    Write-Log "Upload incomplete - keeping old state so next run retries."
}

if ($ok) { Stop-Run -Rows $sent -ErrorText '' -Code 0 }
else     { Stop-Run -Rows $sent -ErrorText 'balance upload failed' -Code 1 }


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
