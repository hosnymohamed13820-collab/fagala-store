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

# Heartbeat endpoint - the agent reports "I am alive" every run, even
# when nothing changed. Without it, silence is ambiguous: we cannot
# tell a dead PC from a quiet sales day.
$BeatUrl   = 'https://hosnimanagement.com/sync_beat.php'
$GuardUrl  = 'https://hosnimanagement.com/sync_guard.php'
$AgentKey  = 'hq'          # must match agent_status.agent_key on the site
$AgentVer  = '2.8'

# The replication program has a different name on every machine (Oct 2 2026):
#   HQ repl_true.exe, Bonjour Repltrue.exe, Degla a_true.exe
# but it always lives in the "...autorepl" folder. a_true.exe is ALSO the name
# of True V4 (manual posting) in another folder - so a_true counts ONLY when it
# runs from an autorepl folder. Never kill True V4.
$ReplProcNames = @('repl_true', 'Repltrue', 'a_true')
function Get-ReplProcs {
    @(Get-Process -Name $ReplProcNames -ErrorAction SilentlyContinue | Where-Object {
        if ($_.Path) { $_.Path -like '*autorepl*' }
        else { $_.Name -ne 'a_true' }   # path unreadable: trust the unambiguous names only
    })
}

if (-not ([System.Management.Automation.PSTypeName]'ReplWinHelper').Type) {
    Add-Type -TypeDefinition @"
using System;
using System.Collections.Generic;
using System.Runtime.InteropServices;
using System.Text;

public static class ReplWinHelper {
    public delegate bool EnumWindowsProc(IntPtr hWnd, IntPtr lParam);

    [DllImport("user32.dll")]
    public static extern bool EnumWindows(EnumWindowsProc lpEnumFunc, IntPtr lParam);

    [DllImport("user32.dll")]
    public static extern bool EnumChildWindows(IntPtr hWnd, EnumWindowsProc lpEnumFunc, IntPtr lParam);

    [DllImport("user32.dll")]
    public static extern uint GetWindowThreadProcessId(IntPtr hWnd, out uint lpdwProcessId);

    [DllImport("user32.dll", CharSet = CharSet.Auto)]
    public static extern int GetClassName(IntPtr hWnd, StringBuilder lpClassName, int nMaxCount);

    [DllImport("user32.dll", CharSet = CharSet.Auto)]
    public static extern int GetWindowText(IntPtr hWnd, StringBuilder lpString, int nMaxCount);

    [DllImport("user32.dll")]
    public static extern IntPtr SendMessage(IntPtr hWnd, uint Msg, IntPtr wParam, IntPtr lParam);

    [DllImport("user32.dll")]
    public static extern bool PostMessage(IntPtr hWnd, uint Msg, IntPtr wParam, IntPtr lParam);

    public static string GetText(IntPtr hWnd) {
        StringBuilder sb = new StringBuilder(512);
        GetWindowText(hWnd, sb, sb.Capacity);
        return sb.ToString();
    }

    public static string GetClass(IntPtr hWnd) {
        StringBuilder sb = new StringBuilder(256);
        GetClassName(hWnd, sb, sb.Capacity);
        return sb.ToString();
    }

    public static List<IntPtr> GetDialogsForPid(uint targetPid) {
        List<IntPtr> result = new List<IntPtr>();
        EnumWindows(delegate(IntPtr hWnd, IntPtr lParam) {
            uint pid;
            GetWindowThreadProcessId(hWnd, out pid);
            if (pid == targetPid) {
                if (GetClass(hWnd) == "#32770") {
                    result.Add(hWnd);
                }
            }
            return true;
        }, IntPtr.Zero);
        return result;
    }

    public static List<IntPtr> GetChildren(IntPtr hWndParent) {
        List<IntPtr> result = new List<IntPtr>();
        EnumChildWindows(hWndParent, delegate(IntPtr hWnd, IntPtr lParam) {
            result.Add(hWnd);
            return true;
        }, IntPtr.Zero);
        return result;
    }
}
"@
}

function Close-ReplDialogs {
    param(
        [System.Diagnostics.Process[]]$Processes = $null
    )
    if ($null -eq $Processes) {
        $Processes = @(Get-ReplProcs)
    }
    if ($null -eq $Processes -or $Processes.Count -eq 0) {
        return @()
    }

    $results = @()
    $okLabels = @('OK', '&OK', 'Yes', '&Yes', [regex]::Unescape('\u0645\u0648\u0627\u0641\u0642'))

    foreach ($proc in $Processes) {
        if ($null -eq $proc -or $proc.HasExited) { continue }
        $dialogs = [ReplWinHelper]::GetDialogsForPid([uint32]$proc.Id)

        foreach ($dlg in $dialogs) {
            if ($results.Count -ge 10) { break }

            $title = [ReplWinHelper]::GetText($dlg)
            $children = [ReplWinHelper]::GetChildren($dlg)

            $staticTexts = @()
            $btnHandle = [IntPtr]::Zero

            foreach ($child in $children) {
                $cls = [ReplWinHelper]::GetClass($child)
                $txt = [ReplWinHelper]::GetText($child)

                if ($cls -eq 'Static') {
                    if ($txt -and $txt.Trim()) {
                        $staticTexts += $txt.Trim()
                    }
                } elseif ($cls -eq 'Button') {
                    if ($btnHandle -eq [IntPtr]::Zero -and $txt) {
                        $t = $txt.Trim()
                        if ($okLabels -contains $t) {
                            $btnHandle = $child
                        }
                    }
                }
            }

            $bodyText = if ($staticTexts.Count -gt 0) { $staticTexts -join ' ' } else { '' }
            $action = 'click_ok'

            if ($btnHandle -ne [IntPtr]::Zero) {
                [void][ReplWinHelper]::SendMessage($btnHandle, 0x00F5, [IntPtr]::Zero, [IntPtr]::Zero)
                $action = 'click_ok'
            } else {
                [void][ReplWinHelper]::PostMessage($dlg, 0x0010, [IntPtr]::Zero, [IntPtr]::Zero)
                $action = 'wm_close'
            }

            Write-Log "[guard] dismissed repl dialog: $title | $bodyText"
            $results += [PSCustomObject]@{
                title  = $title
                text   = $bodyText
                pid    = $proc.Id
                action = $action
            }
        }
        if ($results.Count -ge 10) { break }
    }

    return ,$results
}

# Secret token - must equal SYNC_AGENT_TOKENS['hq'] in site/sync_tokens.php
$SyncToken = 'hq_48c4cfd7ec51ede3faf01c94aba41504e95f7b9b'

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
            # no-beat: the run holding the lock sends the heartbeat for this cycle.
            #          A second beat would report two runs for one cycle.
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
        # READ UNCOMMITTED: read without taking shared locks, so the cashier and the
        # replication never wait on us. A value mid-write can be read once; the next
        # run (2 min) corrects it. We only read - nothing here writes to this DB.
        $cmd.CommandText    = "SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED;`n" + $Sql
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
#  REPL RESTART & WATCHDOG (Brief 94)
# ---------------------------------------------------------------------
function Get-ReplRestartState {
    $path = Join-Path $PSScriptRoot "repl_restart.json"
    if (Test-Path $path) {
        try {
            $content = Get-Content -Path $path -Raw -Encoding UTF8 -ErrorAction Stop
            if ($content) { return ($content | ConvertFrom-Json) }
        } catch { }
    }
    return [PSCustomObject]@{
        attempts          = @()
        motionFingerprint = ''
        motionTime        = ''
        downSince         = ''
        multiAlertTime    = ''
        pendingCmdResult  = $null
        pendingReplAction = $null
    }
}

function Save-ReplRestartState {
    param($StateObj)
    $path = Join-Path $PSScriptRoot "repl_restart.json"
    try {
        $json = $StateObj | ConvertTo-Json -Depth 4 -Compress
        Set-Content -Path $path -Value $json -Encoding UTF8 -ErrorAction Stop
    } catch {
        Write-Log "[repl] could not save repl_restart.json: $($_.Exception.Message)"
    }
}

function Invoke-ReplRestart {
    Write-Log "[repl] restart requested"

    # 1. Hold file: if repl_hold.txt is present and < 4 hours old, abort
    $holdFile = Join-Path $PSScriptRoot 'repl_hold.txt'
    if (Test-Path $holdFile) {
        try {
            $holdAge = (Get-Date) - (Get-Item $holdFile).LastWriteTime
            if ($holdAge.TotalHours -lt 4) {
                Write-Log "[repl] restart aborted: hold file present and < 4h old"
                return @{ ok = $false; text = 'hold file' }
            }
        } catch { }
    }

    # 2. Limit: max 3 attempts in the last 60 minutes
    $rState = Get-ReplRestartState
    $now = Get-Date
    $recentAttempts = @()
    if ($rState.attempts) {
        foreach ($att in $rState.attempts) {
            try {
                $dt = [datetime]$att
                if (($now - $dt).TotalMinutes -lt 60) {
                    $recentAttempts += $att
                }
            } catch { }
        }
    }
    if ($recentAttempts.Count -ge 3) {
        Write-Log "[repl] restart aborted: limit 3/hour"
        return @{ ok = $false; text = 'limit 3/hour' }
    }

    # 3. Motion check: if process is running, verify queue is not moving
    $procs = @((Get-ReplProcs))
    if ($procs.Count -gt 0) {
        try {
            $mTable = Invoke-Sql @"
SET NOCOUNT ON;
SELECT (SELECT COUNT(*) FROM db_repl_receive) AS c_recv,
       (SELECT ISNULL(MAX(drr_id), 0) FROM db_repl_receive_hist) AS max_hist,
       (SELECT COUNT(*) FROM STO_TRANS_HEADER_REPL) AS c_repl;
"@
            if ($mTable.Rows.Count -gt 0) {
                $mr = $mTable.Rows[0]
                $curMotion = "$($mr['c_recv'])|$($mr['max_hist'])|$($mr['c_repl'])"
                if ($rState.motionFingerprint -and $rState.motionTime) {
                    $mAge = ($now - [datetime]$rState.motionTime).TotalMinutes
                    if ($mAge -lt 15 -or $curMotion -ne $rState.motionFingerprint) {
                        Write-Log "[repl] restart aborted: busy - queue moving"
                        return @{ ok = $false; text = 'busy - queue moving' }
                    }
                }
            }
        } catch {
            Write-Log "[repl] motion check error: $($_.Exception.Message)"
        }
    }

    # Record attempt timestamp in state
    $recentAttempts += $now.ToString('o')
    $rState.attempts = $recentAttempts
    Save-ReplRestartState $rState

    # 4. Kill repl_true process
    Write-Log "[repl] killing repl_true process..."
    try {
        (Get-ReplProcs) | Stop-Process -Force
    } catch { }

    $killed = $false
    for ($i = 0; $i -lt 30; $i++) {
        $pLeft = @((Get-ReplProcs))
        if ($pLeft.Count -eq 0) {
            $killed = $true
            break
        }
        Start-Sleep -Seconds 1
    }
    if (-not $killed) {
        $pLeft = @((Get-ReplProcs))
        Write-Log "[repl] could not kill $($pLeft.Count)"
        return @{ ok = $false; text = "could not kill $($pLeft.Count)" }
    }

    # 5. Start via FagalaReplStart scheduled task
    Write-Log "[repl] starting FagalaReplStart task..."
    try {
        Start-ScheduledTask -TaskName 'FagalaReplStart' -ErrorAction Stop
    } catch {
        Write-Log "[repl] FagalaReplStart task missing: $($_.Exception.Message)"
        return @{ ok = $false; text = 'FagalaReplStart task missing' }
    }

    $finalCount = 0
    for ($i = 0; $i -lt 30; $i++) {
        Start-Sleep -Seconds 1
        $pAfter = @((Get-ReplProcs))
        if ($pAfter.Count -eq 1) {
            $finalCount = 1
            break
        }
        if ($pAfter.Count -gt 1) {
            $finalCount = $pAfter.Count
            break
        }
    }

    if ($finalCount -eq 1) {
        Write-Log "[repl] restarted successfully (1 copy running)"
        return @{ ok = $true; text = 'ok' }
    } elseif ($finalCount -eq 0) {
        Write-Log "[repl] started 0 (user logged off?)"
        return @{ ok = $false; text = 'started 0 (user logged off?)' }
    } else {
        Write-Log "[repl] started $finalCount"
        return @{ ok = $false; text = "started $finalCount" }
    }
}

function Check-ReplAutoWatchdog {
    try {
        $rState = Get-ReplRestartState
        $now = Get-Date
        $procs = @((Get-ReplProcs))

        if ($procs.Count -eq 0) {
            if (-not $rState.downSince) {
                $rState.downSince = $now.ToString('o')
                Save-ReplRestartState $rState
                Write-Log "[watchdog] repl_true is down, recorded timestamp"
            } else {
                $downMin = ($now - [datetime]$rState.downSince).TotalMinutes
                if ($downMin -ge 10) {
                    Write-Log "[watchdog] repl_true down for $([int]$downMin) min -> invoking restart"
                    $res = Invoke-ReplRestart
                    $rState.downSince = ''
                    $resText = if ($res.text) { [string]$res.text } else { '' }
                    if ($resText.Length -gt 180) { $resText = $resText.Substring(0, 180) }
                    $rState.pendingReplAction = @{
                        ok   = [bool]$res.ok
                        text = "auto start: $resText"
                    }
                    Save-ReplRestartState $rState
                }
            }
        } elseif ($procs.Count -eq 1) {
            $dirty = $false
            if ($rState.downSince) {
                $rState.downSince = ''
                $dirty = $true
            }
            try {
                $mTable = Invoke-Sql @"
SET NOCOUNT ON;
SELECT (SELECT COUNT(*) FROM db_repl_receive) AS c_recv,
       (SELECT ISNULL(MAX(drr_id), 0) FROM db_repl_receive_hist) AS max_hist,
       (SELECT COUNT(*) FROM STO_TRANS_HEADER_REPL) AS c_repl;
"@
                if ($mTable.Rows.Count -gt 0) {
                    $mr = $mTable.Rows[0]
                    # motionTime = when the queue LAST CHANGED (not when we last looked),
                    # so the restart check really means "no movement for 15 minutes".
                    $fpNow = "$($mr['c_recv'])|$($mr['max_hist'])|$($mr['c_repl'])"
                    if ($fpNow -ne $rState.motionFingerprint -or -not $rState.motionTime) {
                        $rState.motionFingerprint = $fpNow
                        $rState.motionTime = $now.ToString('o')
                        $dirty = $true
                    }
                }
            } catch { }
            if ($dirty) { Save-ReplRestartState $rState }
        } else {
            # Multiple copies running (> 1)
            $sendMultiAlert = $true
            if ($rState.multiAlertTime) {
                try {
                    $alertAge = ($now - [datetime]$rState.multiAlertTime).TotalMinutes
                    if ($alertAge -lt 60) { $sendMultiAlert = $false }
                } catch { }
            }
            if ($sendMultiAlert) {
                $rState.multiAlertTime = $now.ToString('o')
                $rState.pendingReplAction = @{
                    ok   = $false
                    text = "$($procs.Count) copies of repl_true running"
                }
                Save-ReplRestartState $rState
                Write-Log "[watchdog] alert: $($procs.Count) copies of repl_true running"
            }
        }
    } catch {
        Write-Log "[watchdog] error: $($_.Exception.Message)"
    }
}

# ---------------------------------------------------------------------
#  RABT GUARD (Brief 94 - HQ only)
# ---------------------------------------------------------------------
function Get-GuardSha1 {
    param([string]$InputStr)
    $sha1 = [System.Security.Cryptography.SHA1]::Create()
    try {
        $bytes = [System.Text.Encoding]::UTF8.GetBytes($InputStr)
        $hashBytes = $sha1.ComputeHash($bytes)
        return [System.BitConverter]::ToString($hashBytes).Replace('-', '').ToLowerInvariant()
    } finally {
        $sha1.Dispose()
    }
}

function Invoke-Guard {
    $guardStatePath = Join-Path $PSScriptRoot "guard_state.json"
    $guardState = $null
    if (Test-Path $guardStatePath) {
        try {
            $raw = Get-Content -Path $guardStatePath -Raw -Encoding UTF8 -ErrorAction Stop
            if ($raw) { $guardState = ($raw | ConvertFrom-Json) }
        } catch { }
    }
    if (-not $guardState) {
        $guardState = [PSCustomObject]@{
            last_run          = ''
            h04_count         = 0
            h04_time          = ''
            h14_last_checked  = [PSCustomObject]@{}
            h17_last_checked  = [PSCustomObject]@{}
        }
    }

    $now = Get-Date
    if ($guardState.last_run) {
        try {
            $lastRunAge = ($now - [datetime]$guardState.last_run).TotalSeconds
            if ($lastRunAge -lt 580) {
                # Guard runs once every ~10 minutes, throttle
                return
            }
        } catch { }
    }

    Write-Log "[guard] starting 12 checks..."
    $findings = @()
    $checked  = @()
    $branchMap = @{ 2 = 'zahraa'; 3 = 'bonjour'; 5 = 'degla'; 6 = 'abc' }

    try {
        # -----------------------------------------------------------------
        # H18: Auto-dismiss replication dialogs
        # -----------------------------------------------------------------
        try {
            $dismissed = @(Close-ReplDialogs)
            if ($dismissed.Count -gt 0) {
                $firstDlg = $dismissed[0]
                $firstText = if ($firstDlg.text) { $firstDlg.text } else { $firstDlg.title }
                $findings += @{
                    code    = 'H18'
                    branch  = 'hq'
                    summary = "Auto-OK on $($dismissed.Count) repl dialog(s): $($firstDlg.title)"
                    details = ($dismissed | ForEach-Object { "$($_.title) (PID $($_.pid)): $($_.text)" }) -join "`n"
                    restart = $null
                    sha     = Get-GuardSha1 "H18|$firstText"
                }
            }
            $checked += 'H18'
        } catch {
            Write-Log "[guard] H18 error: $($_.Exception.Message)"
        }

        # -----------------------------------------------------------------
        # H01: repl_true process count != 1 or Event Log crash in last hour
        # -----------------------------------------------------------------
        try {
            $h01Procs = @((Get-ReplProcs))
            if ($h01Procs.Count -ne 1) {
                $findings += @{
                    code    = 'H01'
                    branch  = 'hq'
                    summary = "repl_true process count is $($h01Procs.Count) (expected 1)"
                    details = "Running processes: $($h01Procs.Count)"
                    restart = $null
                    sha     = Get-GuardSha1 "H01|count"
                }
            }

            try {
                $oneHourAgo = (Get-Date).AddHours(-1)
                $crashes = @(Get-EventLog -LogName Application -Source "Application Error" -InstanceId 1000 -After $oneHourAgo -ErrorAction SilentlyContinue |
                    Where-Object { $_.Message -like "*repl_true.exe*" -or $_.Message -like "*Repltrue.exe*" })
                if ($crashes.Count -gt 0) {
                    $firstCrashTime = $crashes[0].TimeGenerated.ToString('yyyy-MM-dd HH:mm:ss')
                    $findings += @{
                        code    = 'H01'
                        branch  = 'hq'
                        summary = "repl_true.exe crash found in Event Log (count: $($crashes.Count))"
                        details = ($crashes | Select-Object -First 5 | ForEach-Object { "$($_.TimeGenerated): $($_.Message)" }) -join "`n"
                        restart = $null
                        sha     = Get-GuardSha1 "H01|crash|$firstCrashTime"
                    }
                }
            } catch { }

            $checked += 'H01'
        } catch {
            Write-Log "[guard] H01 error: $($_.Exception.Message)"
        }

        # -----------------------------------------------------------------
        # H02: Last import age > 45 minutes per branch
        # -----------------------------------------------------------------
        try {
            $h02Table = Invoke-Sql @"
SET NOCOUNT ON;
SELECT brch_id, MAX(max_d) AS last_import, DATEDIFF(MINUTE, MAX(max_d), GETDATE()) AS age_min
FROM (
    SELECT brch_id, MAX(drr_import_date) AS max_d FROM db_repl_receive WHERE brch_id IN (2, 3, 5, 6) GROUP BY brch_id
    UNION ALL
    SELECT brch_id, MAX(drr_import_date) AS max_d FROM db_repl_receive_hist WHERE brch_id IN (2, 3, 5, 6) GROUP BY brch_id
) u
GROUP BY brch_id;
"@
            $importPath = 'D:\Repl\Import\HQ'
            $importFiles = @()
            if (Test-Path $importPath) {
                $importFiles = @(Get-ChildItem -Path $importPath -Filter "*.txt" -ErrorAction SilentlyContinue)
            }
            $brchPrefixes = @{ 2 = '02'; 3 = '03'; 5 = '05'; 6 = '06' }

            foreach ($r in $h02Table.Rows) {
                $bId = [int]$r['brch_id']
                $bKey = $branchMap[$bId]
                $ageMin = if ($r['age_min'] -isnot [DBNull]) { [int]$r['age_min'] } else { 9999 }
                $lastImportStr = if ($r['last_import'] -isnot [DBNull]) { [string]$r['last_import'] } else { 'never' }

                if ($ageMin -gt 45) {
                    $prefix = $brchPrefixes[$bId]
                    $hasFiles = $false
                    if ($importFiles.Count -gt 0) {
                        $hasFiles = ($importFiles | Where-Object { $_.Name -like "BB_${prefix}_*" -or $_.Name -like "${prefix}_*" }).Count -gt 0
                    }
                    $targetRestart = if ($hasFiles) { 'hq' } else { $bKey }

                    $findings += @{
                        code    = 'H02'
                        branch  = $bKey
                        summary = "Last import for $bKey was $ageMin min ago (last: $lastImportStr)"
                        details = "Branch: $bKey (id $bId)`nLast import: $lastImportStr`nAge: $ageMin minutes`nFiles in Import/HQ: $(if ($hasFiles) { 'yes' } else { 'none' })"
                        restart = $targetRestart
                        sha     = Get-GuardSha1 "H02|$bKey|$bKey"
                    }
                }
            }
            $checked += 'H02'
        } catch {
            Write-Log "[guard] H02 error: $($_.Exception.Message)"
        }

        # -----------------------------------------------------------------
        # H03: Stuck queue command age > 60 min
        # -----------------------------------------------------------------
        try {
            $h03Table = Invoke-Sql @"
SET NOCOUNT ON;
SELECT brch_id, MIN(drs_id_ref) AS first_ref,
       DATEDIFF(MINUTE, MIN(ISNULL(drr_import_date, create_date)), GETDATE()) AS age_min,
       COUNT(*) AS cnt
FROM db_repl_receive
WHERE drr_execute_flag = 0 AND brch_id IN (2, 3, 5, 6)
GROUP BY brch_id
HAVING DATEDIFF(MINUTE, MIN(ISNULL(drr_import_date, create_date)), GETDATE()) > 60;
"@
            foreach ($r in $h03Table.Rows) {
                $bId = [int]$r['brch_id']
                $bKey = $branchMap[$bId]
                $firstRef = [string]$r['first_ref']
                $ageMin = [int]$r['age_min']
                $cnt = [int]$r['cnt']

                $findings += @{
                    code    = 'H03'
                    branch  = $bKey
                    summary = "Replication queue stuck for $bKey (first ref: $firstRef, age: $ageMin min, total waiting: $cnt)"
                    details = "Branch: $bKey (id $bId)`nFirst stuck drs_id_ref: $firstRef`nAge: $ageMin minutes`nTotal waiting: $cnt"
                    restart = 'hq'
                    sha     = Get-GuardSha1 "H03|$bKey|$firstRef"
                }
            }
            $checked += 'H03'
        } catch {
            Write-Log "[guard] H03 error: $($_.Exception.Message)"
        }

        # -----------------------------------------------------------------
        # H04: STO_TRANS_HEADER_REPL > 100 not decreasing for 15 min
        # -----------------------------------------------------------------
        try {
            $h04Table = Invoke-Sql @"
SET NOCOUNT ON;
SELECT COUNT(*) AS c FROM STO_TRANS_HEADER_REPL;
"@
            $h04Count = [int]$h04Table.Rows[0]['c']

            if ($h04Count -gt 100) {
                if ($guardState.h04_count -and $guardState.h04_time) {
                    $prevH04Count = [int]$guardState.h04_count
                    $h04Age = ($now - [datetime]$guardState.h04_time).TotalMinutes
                    if ($h04Age -ge 15 -and $h04Count -ge $prevH04Count) {
                        $findings += @{
                            code    = 'H04'
                            branch  = 'hq'
                            summary = "STO_TRANS_HEADER_REPL stagnant (> 100) for $([int]$h04Age) min (count: $h04Count, previous: $prevH04Count)"
                            details = "Current count: $h04Count`nPrevious count: $prevH04Count`nStagnant duration: $([int]$h04Age) minutes"
                            restart = 'hq'
                            sha     = Get-GuardSha1 "H04|hq|H04"
                        }
                    }
                } else {
                    $guardState.h04_count = $h04Count
                    $guardState.h04_time = $now.ToString('o')
                }
            } else {
                $guardState.h04_count = $h04Count
                $guardState.h04_time = $now.ToString('o')
            }
            $checked += 'H04'
        } catch {
            Write-Log "[guard] H04 error: $($_.Exception.Message)"
        }

        # -----------------------------------------------------------------
        # H05: Import files sequence gap / duplicates / old / delayed
        # -----------------------------------------------------------------
        try {
            $importPath = 'D:\Repl\Import\HQ'
            if (Test-Path $importPath) {
                $maxRefTable = Invoke-Sql @"
SET NOCOUNT ON;
SELECT brch_id, MAX(max_ref) AS max_ref
FROM (
    SELECT brch_id, MAX(drs_id_ref) AS max_ref FROM db_repl_receive WHERE brch_id IN (2, 3, 5, 6) GROUP BY brch_id
    UNION ALL
    SELECT brch_id, MAX(drs_id_ref) AS max_ref FROM db_repl_receive_hist WHERE brch_id IN (2, 3, 5, 6) GROUP BY brch_id
) u GROUP BY brch_id;
"@
                $expectedMap = @{}
                $maxRefMap   = @{}
                foreach ($mr in $maxRefTable.Rows) {
                    $bId = [int]$mr['brch_id']
                    $mRef = if ($mr['max_ref'] -isnot [DBNull]) { [int64]$mr['max_ref'] } else { 0 }
                    $maxRefMap[$bId] = $mRef
                    $expectedMap[$bId] = $mRef + 1
                }

                $txtFiles = @(Get-ChildItem -Path $importPath -Filter "*.txt" -ErrorAction SilentlyContinue)
                $branchFiles = @{ 2 = @(); 3 = @(); 5 = @(); 6 = @() }

                foreach ($file in $txtFiles) {
                    $matchedBId = $null
                    if ($file.Name -match '^(?:BB_)?02_') { $matchedBId = 2 }
                    elseif ($file.Name -match '^(?:BB_)?03_') { $matchedBId = 3 }
                    elseif ($file.Name -match '^(?:BB_)?05_') { $matchedBId = 5 }
                    elseif ($file.Name -match '^(?:BB_)?06_') { $matchedBId = 6 }

                    if ($matchedBId) {
                        $firstNum = $null
                        try {
                            $stream = [System.IO.File]::OpenRead($file.FullName)
                            $buf = New-Object byte[] 200
                            $bytesRead = $stream.Read($buf, 0, 200)
                            $stream.Close()
                            $stream.Dispose()
                            $txtHeader = [System.Text.Encoding]::Unicode.GetString($buf, 0, $bytesRead)
                            $firstLine = ($txtHeader -split "`r?`n")[0]
                            $firstCell = ($firstLine -split "`t")[0].Trim()
                            if ($firstCell -match '^\d+$') {
                                $firstNum = [int64]$firstCell
                            }
                        } catch { }

                        $branchFiles[$matchedBId] += @{
                            file      = $file
                            first_num = $firstNum
                        }
                    }
                }

                foreach ($bId in @(2, 3, 5, 6)) {
                    $bKey = $branchMap[$bId]
                    $fList = $branchFiles[$bId]
                    if ($fList.Count -eq 0) { continue }

                    $exp = if ($expectedMap.ContainsKey($bId)) { $expectedMap[$bId] } else { 1 }
                    $lastRecv = if ($maxRefMap.ContainsKey($bId)) { $maxRefMap[$bId] } else { 0 }

                    # Delayed check (> 30 min)
                    $oldestFile = $fList | Sort-Object { $_.file.LastWriteTime } | Select-Object -First 1
                    if ($oldestFile) {
                        $fAge = ($now - $oldestFile.file.LastWriteTime).TotalMinutes
                        if ($fAge -gt 30) {
                            $findings += @{
                                code    = 'H05'
                                branch  = $bKey
                                summary = "File in Import/HQ delayed > 30 min for $bKey ($($oldestFile.file.Name), age $([int]$fAge) min)"
                                details = "File: $($oldestFile.file.Name)`nPath: $($oldestFile.file.FullName)`nAge: $([int]$fAge) min"
                                restart = $null
                                sha     = Get-GuardSha1 "H05|$bKey|delayed|$($oldestFile.first_num)"
                            }
                        }
                    }

                    # Sequence gap check
                    $hasExpected = ($fList | Where-Object { $_.first_num -eq $exp }).Count -gt 0
                    if (-not $hasExpected -and $fList.Count -gt 0) {
                        $firstAvail = ($fList | Where-Object { $_.first_num } | Sort-Object { $_.first_num } | Select-Object -First 1).first_num
                        $findings += @{
                            code    = 'H05'
                            branch  = $bKey
                            summary = "Sequence gap in Import/HQ for $($bKey): expected $exp, but first file starts at $firstAvail"
                            details = "Expected drs_id_ref: $exp`nFiles present for branch: $($fList.Count)`nLowest file start: $firstAvail"
                            restart = $null
                            sha     = Get-GuardSha1 "H05|$bKey|gap|$exp"
                        }
                    }

                    # Duplicate and old files check
                    $seenNums = @{}
                    foreach ($item in $fList) {
                        if ($item.first_num -ne $null) {
                            $fNum = $item.first_num
                            if ($fNum -le $lastRecv) {
                                $findings += @{
                                    code    = 'H05'
                                    branch  = $bKey
                                    summary = "Old file in Import/HQ for $($bKey): starts at $fNum <= last received $lastRecv ($($item.file.Name))"
                                    details = "File: $($item.file.Name)`nStart num: $fNum`nLast received: $lastRecv"
                                    restart = $null
                                    sha     = Get-GuardSha1 "H05|$bKey|old|$fNum"
                                }
                            }
                            if ($seenNums.ContainsKey($fNum)) {
                                $findings += @{
                                    code    = 'H05'
                                    branch  = $bKey
                                    summary = "Duplicate start num in Import/HQ for $($bKey): $fNum in $($item.file.Name) and $($seenNums[$fNum].Name)"
                                    details = "First file: $($seenNums[$fNum].Name)`nSecond file: $($item.file.Name)`nStart number: $fNum"
                                    restart = $null
                                    sha     = Get-GuardSha1 "H05|$bKey|duplicate|$fNum"
                                }
                            } else {
                                $seenNums[$fNum] = $item.file
                            }
                        }
                    }
                }
            }
            $checked += 'H05'
        } catch {
            Write-Log "[guard] H05 error: $($_.Exception.Message)"
        }

        # -----------------------------------------------------------------
        # H06: Error log of today > 5MB or log folder missing
        # -----------------------------------------------------------------
        try {
            $errDir = $null
            $proc = (Get-ReplProcs) | Select-Object -First 1
            if ($proc -and $proc.Path) {
                $pDir = [System.IO.Path]::GetDirectoryName($proc.Path)
                $cand1 = Join-Path $pDir 'repl_error'
                $cand2 = Join-Path ([System.IO.Path]::GetDirectoryName($pDir)) 'repl_error'
                if (Test-Path $cand1) { $errDir = $cand1 }
                elseif (Test-Path $cand2) { $errDir = $cand2 }
            }
            if (-not $errDir) {
                $candDef = 'D:\PowerTech\exe_v4_20220707  autorepl\repl_error'
                $candDef2 = 'D:\PowerTech\repl_error'
                if (Test-Path $candDef) { $errDir = $candDef }
                elseif (Test-Path $candDef2) { $errDir = $candDef2 }
            }

            if (-not $errDir) {
                $findings += @{
                    code    = 'H06'
                    branch  = 'hq'
                    summary = "Log folder repl_error not found"
                    details = "Could not locate repl_error directory near repl_true.exe or default paths"
                    restart = $null
                    sha     = Get-GuardSha1 "H06|hq|not_found"
                }
            } else {
                $todayStr = (Get-Date).ToString('yyMMdd')
                $errFile = Join-Path $errDir "error$todayStr.TXT"
                if (Test-Path $errFile) {
                    $fLen = (Get-Item $errFile).Length
                    if ($fLen -gt 5MB) {
                        $mbSize = [math]::Round($fLen / 1MB, 2)
                        $findings += @{
                            code    = 'H06'
                            branch  = 'hq'
                            summary = "Error log today exceeds 5MB ($mbSize MB) - loop suspected"
                            details = "File: $errFile`nSize: $mbSize MB ($fLen bytes)"
                            restart = $null
                            sha     = Get-GuardSha1 "H06|hq|$todayStr"
                        }
                    }
                }
            }
            $checked += 'H06'
        } catch {
            Write-Log "[guard] H06 error: $($_.Exception.Message)"
        }

        # -----------------------------------------------------------------
        # H07: Orphan rows in sto_trans_lines_ex_repl (catalog M4)
        # -----------------------------------------------------------------
        try {
            $h07Table = Invoke-Sql @"
SET NOCOUNT ON;
SELECT sth_id_ref, stl_line_no, sth_id, sto_id, CREATE_DATE
FROM sto_trans_lines_ex_repl
WHERE CREATE_DATE < DATEADD(day, -30, GETDATE())
   OR NOT EXISTS (SELECT 1 FROM STO_TRANS_HEADER_REPL h WHERE h.STH_ID_REF = sto_trans_lines_ex_repl.sth_id_ref);
"@
            if ($h07Table.Rows.Count -gt 0) {
                $totalCnt = $h07Table.Rows.Count
                $detLines = @("Total orphan rows: $totalCnt")
                for ($i = 0; $i -lt [math]::Min(50, $totalCnt); $i++) {
                    $hr = $h07Table.Rows[$i]
                    $detLines += "ref:$($hr['sth_id_ref']) line:$($hr['stl_line_no']) sth:$($hr['sth_id']) sto:$($hr['sto_id']) date:$($hr['CREATE_DATE'])"
                }
                $detStr = $detLines -join "`n"

                for ($i = 0; $i -lt [math]::Min(50, $totalCnt); $i++) {
                    $hr = $h07Table.Rows[$i]
                    $sRef = [string]$hr['sth_id_ref']
                    $lNo  = [string]$hr['stl_line_no']
                    $findings += @{
                        code    = 'H07'
                        branch  = 'hq'
                        summary = "Orphan row in sto_trans_lines_ex_repl (ref: $sRef, line: $lNo)"
                        details = $detStr
                        restart = $null
                        sha     = Get-GuardSha1 "H07|hq|$sRef|$lNo"
                    }
                }
            }
            $checked += 'H07'
        } catch {
            Write-Log "[guard] H07 error: $($_.Exception.Message)"
        }

        # -----------------------------------------------------------------
        # H08: Return line linked to wrong/missing source (catalog M5)
        # -----------------------------------------------------------------
        try {
            $h08Table = Invoke-Sql @"
SET NOCOUNT ON;
SELECT h.BRCH_ID, h.STH_DOC_NO, l.STH_ID_REF, l.STL_LINE_NO, l.STS_ID_REF, s.sth_id AS link_owner
FROM STO_TRANS_HEADER_REPL h JOIN STO_TRANS_LINES_REPL l ON l.STH_ID_REF = h.STH_ID_REF
LEFT JOIN (SELECT sts_id, sth_id FROM sto_trans_source_index_repl UNION ALL SELECT sts_id, sth_id FROM sto_trans_source_index) s ON s.sts_id = l.STS_ID_REF
WHERE h.SSTT_ID = 17 AND (s.sts_id IS NULL OR s.sth_id <> l.STH_ID);
"@
            if ($h08Table.Rows.Count -gt 0) {
                $seenH08 = @{}
                foreach ($r in $h08Table.Rows) {
                    $bId = [int]$r['BRCH_ID']
                    $bKey = if ($branchMap.ContainsKey($bId)) { $branchMap[$bId] } else { "brch_$bId" }
                    $sthRef = [string]$r['STH_ID_REF']
                    $docNo = [string]$r['STH_DOC_NO']
                    $k = "$bKey|$sthRef"
                    if (-not $seenH08.ContainsKey($k)) {
                        $seenH08[$k] = $true
                        $findings += @{
                            code    = 'H08'
                            branch  = $bKey
                            summary = "Return document with invalid source link (branch: $bKey, doc: $docNo, ref: $sthRef)"
                            details = "Branch: $bKey (id $bId)`nDoc No: $docNo`nSTH_ID_REF: $sthRef`nSTS_ID_REF: $($r['STS_ID_REF'])`nLink Owner: $($r['link_owner'])"
                            restart = $null
                            sha     = Get-GuardSha1 "H08|$bKey|$sthRef"
                        }
                    }
                }
            }
            $checked += 'H08'
        } catch {
            Write-Log "[guard] H08 error: $($_.Exception.Message)"
        }

        # -----------------------------------------------------------------
        # H10: Document stuck in STO_TRANS_HEADER_REPL > 7 days
        # -----------------------------------------------------------------
        try {
            $h10Table = Invoke-Sql @"
SET NOCOUNT ON;
SELECT BRCH_ID, STH_DOC_NO, SSTT_ID, STH_ID_REF, CREATE_DATE
FROM STO_TRANS_HEADER_REPL
WHERE CREATE_DATE < DATEADD(day, -7, GETDATE())
ORDER BY CREATE_DATE, STH_ID_REF;
"@
            if ($h10Table.Rows.Count -gt 0) {
                for ($i = 0; $i -lt [math]::Min(50, $h10Table.Rows.Count); $i++) {
                    $r = $h10Table.Rows[$i]
                    $bId = [int]$r['BRCH_ID']
                    $bKey = if ($branchMap.ContainsKey($bId)) { $branchMap[$bId] } else { "brch_$bId" }
                    $sthRef = [string]$r['STH_ID_REF']
                    $docNo = [string]$r['STH_DOC_NO']
                    $cDate = [string]$r['CREATE_DATE']

                    $findings += @{
                        code    = 'H10'
                        branch  = $bKey
                        summary = "Document stuck > 7 days in REPL waiting (branch: $bKey, doc: $docNo, date: $cDate)"
                        details = "Branch: $bKey (id $bId)`nDoc No: $docNo`nType: $($r['SSTT_ID'])`nSTH_ID_REF: $sthRef`nCreated: $cDate"
                        restart = $null
                        sha     = Get-GuardSha1 "H10|$bKey|$sthRef"
                    }
                }
            }
            $checked += 'H10'
        } catch {
            Write-Log "[guard] H10 error: $($_.Exception.Message)"
        }

        # -----------------------------------------------------------------
        # H14: Unposted transactions in STO_TRANS_HEADER_REPL > 24 hours
        # One finding per (branch x transaction type)
        # -----------------------------------------------------------------
        try {
            $h14Table = Invoke-Sql @"
SET NOCOUNT ON;
SELECT BRCH_ID, SSTT_ID, COUNT(*) AS stuck_count,
       MIN(CREATE_DATE) AS oldest, MAX(CREATE_DATE) AS newest
FROM STO_TRANS_HEADER_REPL
WHERE CREATE_DATE < DATEADD(hour, -24, GETDATE())
GROUP BY BRCH_ID, SSTT_ID
ORDER BY BRCH_ID, SSTT_ID;
"@
            if ($h14Table.Rows.Count -gt 0) {
                foreach ($r in $h14Table.Rows) {
                    $bId = [int]$r['BRCH_ID']
                    $bKey = if ($branchMap.ContainsKey($bId)) { $branchMap[$bId] } else { "brch_$bId" }
                    $ssttId = [string]$r['SSTT_ID']
                    $n = [int]$r['stuck_count']
                    $oldest = [string]$r['oldest']
                    $newest = [string]$r['newest']

                    $findings += @{
                        code    = 'H14'
                        branch  = $bKey
                        summary = "Unposted > 24h in REPL (branch: $bKey, type: $ssttId, count: $n, oldest: $oldest)"
                        details = "Branch: $bKey (id $bId)`nType: $ssttId`nCount: $n`nOldest: $oldest`nNewest: $newest"
                        restart = $null
                        sha     = Get-GuardSha1 "H14|$bKey|$ssttId"
                    }
                }
            }
            $checked += 'H14'
        } catch {
            Write-Log "[guard] H14 error: $($_.Exception.Message)"
        }

        # -----------------------------------------------------------------
        # H13: Orphan document in REPL header without index row > 2 hours (catalog M7)
        # -----------------------------------------------------------------
        try {
            $h13Table = Invoke-Sql @"
SET NOCOUNT ON;
SELECT h.BRCH_ID, h.STH_DOC_NO, h.SSTT_ID, h.STH_ID_REF, h.CREATE_DATE FROM STO_TRANS_HEADER_REPL h
WHERE NOT EXISTS (SELECT 1 FROM STO_TRANS_INDEX_REPL i WHERE i.brch_id = h.BRCH_ID AND i.sth_id_ref = h.STH_ID_REF)
  AND h.CREATE_DATE < DATEADD(hour, -2, GETDATE());
"@
            if ($h13Table.Rows.Count -gt 0) {
                for ($i = 0; $i -lt [math]::Min(50, $h13Table.Rows.Count); $i++) {
                    $r = $h13Table.Rows[$i]
                    $bId = [int]$r['BRCH_ID']
                    $bKey = if ($branchMap.ContainsKey($bId)) { $branchMap[$bId] } else { "brch_$bId" }
                    $sthRef = [string]$r['STH_ID_REF']
                    $docNo = [string]$r['STH_DOC_NO']
                    $cDate = [string]$r['CREATE_DATE']

                    $findings += @{
                        code    = 'H13'
                        branch  = $bKey
                        summary = "Orphan document in REPL waiting without index row > 2h (branch: $bKey, doc: $docNo)"
                        details = "Branch: $bKey (id $bId)`nDoc No: $docNo`nSTH_ID_REF: $sthRef`nCreated: $cDate"
                        restart = $null
                        sha     = Get-GuardSha1 "H13|$bKey|$sthRef"
                    }
                }
            }
            $checked += 'H13'
        } catch {
            Write-Log "[guard] H13 error: $($_.Exception.Message)"
        }

        # -----------------------------------------------------------------
        # H14: Received sequence gap or brch_last_receive jumped ahead
        # -----------------------------------------------------------------
        try {
            $jumpTable = Invoke-Sql @"
SET NOCOUNT ON;
SELECT b.brch_id, b.brch_last_receive, m.max_recv
FROM branches b
JOIN (
    SELECT brch_id, MAX(drs_id_ref) AS max_recv
    FROM (
        SELECT brch_id, drs_id_ref FROM db_repl_receive WHERE brch_id IN (2, 3, 5, 6)
        UNION ALL
        SELECT brch_id, drs_id_ref FROM db_repl_receive_hist WHERE brch_id IN (2, 3, 5, 6)
    ) u GROUP BY brch_id
) m ON m.brch_id = b.brch_id
WHERE b.brch_last_receive > m.max_recv;
"@
            foreach ($jr in $jumpTable.Rows) {
                $bId = [int]$jr['brch_id']
                $bKey = if ($branchMap.ContainsKey($bId)) { $branchMap[$bId] } else { "brch_$bId" }
                $counterVal = [int64]$jr['brch_last_receive']
                $maxRecvVal = [int64]$jr['max_recv']

                $findings += @{
                    code    = 'H14'
                    branch  = $bKey
                    summary = "Counter jumped ahead for $($bKey): brch_last_receive ($counterVal) > MAX drs_id_ref ($maxRecvVal)"
                    details = "Branch: $bKey (id $bId)`nbrch_last_receive: $counterVal`nMAX received: $maxRecvVal`nDifference: $($counterVal - $maxRecvVal)"
                    restart = $null
                    sha     = Get-GuardSha1 "H14|$bKey|jump|$counterVal"
                }
            }

            if (-not $guardState.h14_last_checked) {
                $guardState.h14_last_checked = [PSCustomObject]@{}
            }

            foreach ($bId in @(2, 3, 5, 6)) {
                $bKey = $branchMap[$bId]
                $maxTbl = Invoke-Sql @"
SET NOCOUNT ON;
SELECT MAX(m) AS max_id FROM (
    SELECT MAX(drs_id_ref) AS m FROM db_repl_receive WHERE brch_id = $bId
    UNION ALL
    SELECT MAX(drs_id_ref) AS m FROM db_repl_receive_hist WHERE brch_id = $bId
) u;
"@
                if ($maxTbl.Rows.Count -gt 0 -and $maxTbl.Rows[0]['max_id'] -isnot [DBNull]) {
                    $maxId = [int64]$maxTbl.Rows[0]['max_id']
                    $startId = 0
                    $bIdStr = [string]$bId
                    if ($guardState.h14_last_checked.$bIdStr) {
                        $startId = [int64]$guardState.h14_last_checked.$bIdStr
                    } else {
                        $startId = [math]::Max(1, $maxId - 50000)
                    }

                    if ($maxId -gt $startId) {
                        $gapSql = @"
SET NOCOUNT ON;
SELECT t.drs_id_ref AS gap_start, t.drs_id_ref + 1 AS missing_id
FROM (
    SELECT drs_id_ref FROM db_repl_receive WHERE brch_id = $bId AND drs_id_ref BETWEEN $startId AND $maxId
    UNION
    SELECT drs_id_ref FROM db_repl_receive_hist WHERE brch_id = $bId AND drs_id_ref BETWEEN $startId AND $maxId
) t
WHERE t.drs_id_ref < $maxId
  AND NOT EXISTS (
      SELECT 1 FROM (
          SELECT drs_id_ref FROM db_repl_receive WHERE brch_id = $bId AND drs_id_ref BETWEEN $startId AND $maxId
          UNION
          SELECT drs_id_ref FROM db_repl_receive_hist WHERE brch_id = $bId AND drs_id_ref BETWEEN $startId AND $maxId
      ) t2 WHERE t2.drs_id_ref = t.drs_id_ref + 1
  );
"@
                        $gapTable = Invoke-Sql $gapSql
                        if ($gapTable.Rows.Count -gt 0) {
                            for ($g = 0; $g -lt [math]::Min(50, $gapTable.Rows.Count); $g++) {
                                $gr = $gapTable.Rows[$g]
                                $gStart = [int64]$gr['gap_start']
                                $missId = [int64]$gr['missing_id']
                                $findings += @{
                                    code    = 'H14'
                                    branch  = $bKey
                                    summary = "Received sequence gap for $bKey starting at $gStart (missing $missId)"
                                    details = "Branch: $bKey (id $bId)`nGap after: $gStart`nMissing order: $missId`nRange checked: $startId to $maxId"
                                    restart = $null
                                    sha     = Get-GuardSha1 "H14|$bKey|$gStart"
                                }
                            }
                        }
                        $guardState.h14_last_checked | Add-Member -MemberType NoteProperty -Name $bIdStr -Value $maxId -Force
                    }
                }
            }
            $checked += 'H14'
        } catch {
            Write-Log "[guard] H14 error: $($_.Exception.Message)"
        }

        # -----------------------------------------------------------------
        # H17: Errors recorded in drr_error column
        # -----------------------------------------------------------------
        try {
            if (-not $guardState.h17_last_checked) {
                $guardState.h17_last_checked = [PSCustomObject]@{}
            }

            foreach ($bId in @(2, 3, 5, 6)) {
                $bKey = $branchMap[$bId]
                $bIdStr = [string]$bId
                $lastChecked = 0
                if ($guardState.h17_last_checked.$bIdStr) {
                    $lastChecked = [int64]$guardState.h17_last_checked.$bIdStr
                } else {
                    $mRefTbl = Invoke-Sql @"
SET NOCOUNT ON;
SELECT MAX(m) AS max_id FROM (
    SELECT MAX(drs_id_ref) AS m FROM db_repl_receive WHERE brch_id = $bId
    UNION ALL
    SELECT MAX(drs_id_ref) AS m FROM db_repl_receive_hist WHERE brch_id = $bId
) u;
"@
                    if ($mRefTbl.Rows.Count -gt 0 -and $mRefTbl.Rows[0]['max_id'] -isnot [DBNull]) {
                        $lastChecked = [math]::Max(0, [int64]$mRefTbl.Rows[0]['max_id'] - 50000)
                    }
                }

                $errSql = @"
SET NOCOUNT ON;
SELECT brch_id, drs_id_ref, drr_error, create_date
FROM (
    SELECT brch_id, drs_id_ref, drr_error, create_date FROM db_repl_receive
    WHERE brch_id = $bId AND drr_error IS NOT NULL AND LTRIM(RTRIM(drr_error)) <> '' AND drs_id_ref > $lastChecked
    UNION ALL
    SELECT brch_id, drs_id_ref, drr_error, create_date FROM db_repl_receive_hist
    WHERE brch_id = $bId AND drr_error IS NOT NULL AND LTRIM(RTRIM(drr_error)) <> '' AND drs_id_ref > $lastChecked
) u
ORDER BY drs_id_ref;
"@
                $errTable = Invoke-Sql $errSql
                $maxSeenInTbl = $lastChecked
                if ($errTable.Rows.Count -gt 0) {
                    for ($e = 0; $e -lt [math]::Min(50, $errTable.Rows.Count); $e++) {
                        $er = $errTable.Rows[$e]
                        $drsRef = [int64]$er['drs_id_ref']
                        if ($drsRef -gt $maxSeenInTbl) { $maxSeenInTbl = $drsRef }
                        $errTxt = [string]$er['drr_error']
                        $cDate  = [string]$er['create_date']

                        $findings += @{
                            code    = 'H17'
                            branch  = $bKey
                            summary = "Replication error on $bKey (ref $drsRef): $(if ($errTxt.Length -gt 80) { $errTxt.Substring(0, 80) + '...' } else { $errTxt })"
                            details = "Branch: $bKey (id $bId)`ndrs_id_ref: $drsRef`nError: $errTxt`nDate: $cDate"
                            restart = $null
                            sha     = Get-GuardSha1 "H17|$bKey|$drsRef"
                        }
                    }
                }
                $guardState.h17_last_checked | Add-Member -MemberType NoteProperty -Name $bIdStr -Value $maxSeenInTbl -Force
            }
            $checked += 'H17'
        } catch {
            Write-Log "[guard] H17 error: $($_.Exception.Message)"
        }

        # -----------------------------------------------------------------
        # Send findings to sync_guard.php
        # -----------------------------------------------------------------
        if ($checked.Count -gt 0) {
            try {
                $guardPayload = @{
                    agent    = 'hq'
                    checked  = $checked
                    findings = $findings
                } | ConvertTo-Json -Depth 6 -Compress

                $guardBytes = [System.Text.Encoding]::UTF8.GetBytes($guardPayload)
                $guardResp = Invoke-RestMethod -Uri $GuardUrl -Method Post -Body $guardBytes `
                             -ContentType 'application/json; charset=utf-8' `
                             -Headers @{ 'X-Sync-Token' = $SyncToken } -TimeoutSec 60

                Write-Log "[guard] sent findings ($($findings.Count) findings, $($checked.Count) checked): stored=$($guardResp.stored) resolved=$($guardResp.resolved)"
            } catch {
                Write-Log "[guard] upload to sync_guard.php failed: $($_.Exception.Message)"
            }
        }
    } catch {
        Write-Log "[guard] top-level error: $($_.Exception.Message)"
    } finally {
        $guardState.last_run = $now.ToString('o')
        try {
            $guardState | ConvertTo-Json -Depth 5 -Compress | Set-Content -Path $guardStatePath -Encoding UTF8
        } catch { }
    }
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
       (SELECT LEFT(r.drr_text, 200) FROM db_repl_receive r WHERE r.drr_id = q.first_id) AS first_text,
       (SELECT MAX(x) FROM (SELECT MAX(drr_id) AS x FROM db_repl_receive WHERE brch_id = 1
                            UNION ALL SELECT MAX(drr_id) FROM db_repl_receive_hist WHERE brch_id = 1) h) AS last_recv
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
            last_recv  = $(if ($r['last_recv'] -isnot [DBNull]) { [string]$r['last_recv'] } else { '' })
        }
    } catch {
        Write-Log "[repl] could not read queue: $($_.Exception.Message)"
        return $null
    }
}

# ---------------------------------------------------------------------
#  HQ outgoing replication (db_repl_send). drs_export_flag = 0 means the
#  command is not in any file yet (Sep 17-20 2026: HQ made no files for
#  3 days). last_exported = newest command already put in a file; the
#  website compares it with what each branch says it received.
#  Never throws: $null = "could not read".
# ---------------------------------------------------------------------
function Get-ReplSend {
    try {
        $t = Invoke-Sql @"
SET NOCOUNT ON;
SELECT (SELECT COUNT(*) FROM db_repl_send WHERE drs_export_flag = 0) AS pending,
       (SELECT DATEDIFF(MINUTE, MIN(create_date), GETDATE()) FROM db_repl_send WHERE drs_export_flag = 0) AS pend_age,
       (SELECT MAX(x) FROM (SELECT MAX(drs_id) AS x FROM db_repl_send WHERE drs_export_flag = 1
                            UNION ALL SELECT MAX(drs_id) FROM db_repl_send_hist) h) AS last_exported;
"@
        if ($t.Rows.Count -lt 1) { return $null }
        $r = $t.Rows[0]
        return @{
            pending       = [int]$r['pending']
            pend_age      = $(if ($r['pend_age'] -isnot [DBNull]) { [int]$r['pend_age'] } else { 0 })
            last_exported = $(if ($r['last_exported'] -isnot [DBNull]) { [string]$r['last_exported'] } else { '' })
        }
    } catch {
        Write-Log "[repl] could not read send queue: $($_.Exception.Message)"
        return $null
    }
}

function Send-Beat {
    param([int]$Rows = 0, [string]$ErrorText = '')

    try {
        $rState = Get-ReplRestartState
        $beatCmdResult = $null
        $beatReplAction = $null

        if ($rState.pendingCmdResult) {
            $cTxt = [string]$rState.pendingCmdResult.text
            if ($cTxt.Length -gt 200) { $cTxt = $cTxt.Substring(0, 200) }
            $beatCmdResult = @{
                id   = [int]$rState.pendingCmdResult.id
                ok   = [bool]$rState.pendingCmdResult.ok
                text = $cTxt
            }
        }
        if ($rState.pendingReplAction) {
            $aTxt = [string]$rState.pendingReplAction.text
            if ($aTxt.Length -gt 200) { $aTxt = $aTxt.Substring(0, 200) }
            $beatReplAction = @{
                ok   = [bool]$rState.pendingReplAction.ok
                text = $aTxt
            }
        }

        $bodyObj = [ordered]@{
            agent     = $AgentKey
            rows      = $Rows
            error     = $ErrorText
            version   = $AgentVer
            host      = $env:COMPUTERNAME
            repl      = (Get-ReplQueue)
            repl_send = (Get-ReplSend)
        }
        if ($beatCmdResult)  { $bodyObj['cmd_result']  = $beatCmdResult }
        if ($beatReplAction) { $bodyObj['repl_action'] = $beatReplAction }

        $body = $bodyObj | ConvertTo-Json -Compress
        $bytes = [System.Text.Encoding]::UTF8.GetBytes($body)
        $resp = Invoke-RestMethod -Uri $BeatUrl -Method Post -Body $bytes `
                -ContentType 'application/json; charset=utf-8' `
                -Headers @{ 'X-Sync-Token' = $SyncToken } -TimeoutSec 20

        # Success - clear pending items that were sent
        if ($rState.pendingCmdResult -or $rState.pendingReplAction) {
            $rState.pendingCmdResult = $null
            $rState.pendingReplAction = $null
            Save-ReplRestartState $rState
        }

        # Check if server returned a command
        if ($resp -and $resp.cmd -and $resp.cmd.id) {
            $cmdId = [int]$resp.cmd.id
            $cmdName = [string]$resp.cmd.name
            Write-Log "[beat] received command $cmdName (id $cmdId)"
            if ($cmdName -eq 'restart_repl') {
                $rResult = Invoke-ReplRestart
                $rState.pendingCmdResult = @{
                    id   = $cmdId
                    ok   = [bool]$rResult.ok
                    text = [string]$rResult.text
                }
            } else {
                $rState.pendingCmdResult = @{
                    id   = $cmdId
                    ok   = $false
                    text = 'unknown cmd'
                }
            }
            Save-ReplRestartState $rState
        }

        Write-Log "[beat] sent (rows=$Rows)"
    } catch {
        Write-Log "[beat] failed: $($_.Exception.Message)"
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
        $script:BeatRows += $Rows.Count
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
    $itemsErr = "items query failed: $($_.Exception.Message)"
    Write-Log $itemsErr
    if ($LockMine) { Remove-Item $LockFile -Force -ErrorAction SilentlyContinue }
    # This path used to exit WITHOUT a heartbeat, which made a failed
    # items query look exactly like a dead PC on the agents board - the
    # one thing the heartbeat exists to rule out. See the comment at the
    # bottom of this file: "Heartbeat LAST, and on both paths."
    Send-Beat -Rows 0 -ErrorText $itemsErr
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
-- branch purchases now come from each branch agent (brief 66)
WHERE h.SSTT_ID IN (2,6) AND h.BRCH_ID = 1 $wmClause
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
#  6b) SUPPLIERS - catalog of suppliers from True ERP (brief 117)
# ---------------------------------------------------------------------
$suppSql = @"
SET NOCOUNT ON;
SELECT SUPP_ID AS supp_id,
       LTRIM(RTRIM(SUPP_CODE)) AS code,
       LTRIM(RTRIM(SUPP_NAME1)) AS name
FROM SUPPLIERS;
"@

$okSuppliers = $true
try {
    $suppTable = Invoke-Sql $suppSql
    Write-Log "Read $($suppTable.Rows.Count) supplier rows."
    $suppRows = New-Object System.Collections.ArrayList
    foreach ($r in $suppTable.Rows) {
        $null = $suppRows.Add([ordered]@{
            supp_id = [int]$r['supp_id']
            code    = [string]$r['code']
            name    = [string]$r['name']
        })
    }
    $suppPayload = @{ token = $SyncToken; kind = 'suppliers'; rows = $suppRows } | ConvertTo-Json -Depth 4 -Compress
    if (-not (Test-Changed 'suppliers' $suppPayload)) {
        Write-Log "[suppliers] unchanged - nothing to send."
    } else {
        if (Send-Payload $suppPayload "suppliers ($($suppRows.Count) rows)") {
            Set-Sent 'suppliers' $suppPayload
        } else {
            $okSuppliers = $false
        }
    }
} catch {
    Write-Log "Suppliers query failed: $($_.Exception.Message)"
    $okSuppliers = $false
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

$allOk = ($okItems -and $okCodes -and $okStores -and $okInvoices -and `
          $okBalances -and $okUnposted -and $okCats -and $okPurchased)

# Heartbeat LAST, and on both paths. A failed run still proves the PC
# and the agent are alive - the site needs to know that, otherwise a
# broken query would look exactly like a dead machine.
$beatErr = ''
if (-not $allOk) {
    $failed = @()
    if (-not $okItems)     { $failed += 'items' }
    if (-not $okCodes)     { $failed += 'codes' }
    if (-not $okStores)    { $failed += 'stores' }
    if (-not $okInvoices)  { $failed += 'invoices' }
    if (-not $okBalances)  { $failed += 'balances' }
    if (-not $okUnposted)  { $failed += 'unposted' }
    if (-not $okCats)      { $failed += 'cats' }
    if (-not $okPurchased) { $failed += 'purchased' }
    $beatErr = 'sections failed: ' + ($failed -join ', ')
}

Check-ReplAutoWatchdog
Invoke-Guard
Send-Beat -Rows $script:BeatRows -ErrorText $beatErr

if ($allOk) {
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
#
# ---------------------------------------------------------------------
#  REPL RESTART HELPER TASK (FagalaReplStart)
#
#  Run ONCE per machine logged in as the interactive cashier/user:
#
#    $p = Get-Process repl_true -ErrorAction SilentlyContinue | Select-Object -First 1
#    $exe = if ($p -and $p.Path) { $p.Path } else { 'D:\PowerTech\exe_v4_20220707  autorepl\repl_true.exe' }
#    $dir = [System.IO.Path]::GetDirectoryName($exe)
#    $act = New-ScheduledTaskAction -Execute 'powershell.exe' `
#         -Argument "-WindowStyle Minimized -Command Start-Process -FilePath `"$exe`" -WorkingDirectory `"$dir`" -WindowStyle Minimized"
#    $prn = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType Interactive
#    Register-ScheduledTask -TaskName 'FagalaReplStart' -Action $act -Principal $prn -Force
# =====================================================================
