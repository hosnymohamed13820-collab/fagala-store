# =====================================================================
#  agent_ashraqa.ps1 - Ashraqa branch balance sync agent
#  Version 1.3  (2026-08-23)  - delta + no code-shape filter
#
#  WHY THE CODE FILTER WENT AWAY (1.3):
#    The query used to drop any code shorter than 8 chars, assuming
#    short = internal service sequence that would never match anything
#    on the site. That was wrong twice over.
#
#    First: it dropped 944 real product codes - 505039 (water bottle),
#    00111 (a book), 0096, 804512.
#
#    Second: the "junk" it was aimed at turned out to be real product
#    codes too. hosnimanagement.com/hq_codes carries letter codes
#    (easy, PRIMA, TRE, tech) and star-prefixed ones (*6222002206080,
#    *9*-*-9--). Two of the three sample words matched on the site.
#
#    So there is no shape rule. Any non-empty code goes. The site side
#    only rejects empty strings and anything over 64 chars, which is
#    the column width.
#
#    IMPORTANT - Leading zeros matter: 0096 is NOT 96. Both columns are
#       varchar(64) so they survive, but nothing in the chain may ever
#       cast a code to a number.
#
#  Runs on the Ashraqa PC (the one with SQL Server / ReduxSalesPUB2).
#  Reads item balances and pushes them to the website over HTTPS.
#
#  No open port, no DDNS, no static IP needed - the PC just needs
#  normal internet access, like any web browser.
#
#  WHAT CHANGED IN 1.1:
#    Every run now ends with a tiny heartbeat to sync_beat.php, on all
#    paths including failures. The site can finally tell "healthy but
#    no stock moved" apart from "PC is off". Nothing else changed - the
#    SQL query, the payload and the retry logic are byte-for-byte the
#    same as 1.0.
#
#  NOTE: This file is deliberately ASCII-only (no Arabic text) so that
#  Windows PowerShell can never mis-parse it because of file encoding.
#
#  SETUP:
#    1) Put this file at  C:\fagala\agent_ashraqa.ps1
#    2) Edit the settings below (URL, token, SQL password)
#    3) Test it manually from an ADMIN PowerShell window:
#         powershell -ExecutionPolicy Bypass -File "C:\fagala\agent_ashraqa.ps1"
#    4) If it prints "Sync OK", register the scheduled task
#       (commands are at the bottom of this file)
# =====================================================================

# ===== SETTINGS - EDIT THESE =====

# Website endpoint
$SyncUrl = 'https://hosnimanagement.com/sync_ashraqa.php'

# Secret token - MUST match the one inside sync_ashraqa.php exactly
$SyncToken = 'ashraqa_9xK2mQ7vR4tN8pL5wZ3hB6yF1cD0jS'

# ----- Heartbeat ("I am alive" ping) -----
# Sent once at the end of EVERY run, even when nothing was sent and even
# when the run failed. Without it, agent silence is ambiguous - the site
# cannot tell "PC is off" apart from "no stock changed".
$BeatUrl  = 'https://hosnimanagement.com/sync_beat.php'
$AgentKey = 'ashraqa'      # must match agent_status.agent_key on the site
$AgentVer = '1.5'

# Heartbeat token: the beat now uses the agent's own secret token, and the
# website verifies that it belongs to 'ashraqa'.
# If $SyncToken changes, it must be updated in sync_ashraqa.php AND
# SYNC_AGENT_TOKENS['ashraqa'] in site/sync_tokens.php.
$BeatToken = $SyncToken

# ----- Delta (only send what changed) -----
# Without this, every run uploads the whole catalogue. That is fine at
# one run per 30 min, but at 2 min it is 720 full uploads a day - the
# exact pattern that got the office IP banned on 2026-08-08.
#
# With the delta a quiet run sends ZERO rows and just a heartbeat.
#
# Send everything (ignore the delta) once every N runs, so a lost
# update can never stay lost forever. 720 runs x 2 min = ~24 hours.
$FullEvery = 720

# Local SQL Server connection
$SqlServer   = 'WINDOWS-OP1VF0Q\SQLEXPRESS'
$SqlDatabase = 'ReduxSalesPUB2'

# Authentication mode:
#   $true  = Windows Authentication (no username/password) - matches SSMS
#   $false = SQL Server login (fill in $SqlUser / $SqlPassword below)
$UseWindowsAuth = $true

$SqlUser     = 'sa'
$SqlPassword = ''

# Log file (created next to this script)
$LogFile = Join-Path $PSScriptRoot 'agent_ashraqa.log'
$StateFile = Join-Path $PSScriptRoot 'agent_ashraqa.state.json'

# ===== DO NOT EDIT BELOW =====

$ErrorActionPreference = 'Stop'

function Write-Log {
    param([string]$Message)
    $line = "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')  $Message"
    Write-Host $line
    try { Add-Content -Path $LogFile -Value $line -Encoding UTF8 } catch { }
}

# ---------------------------------------------------------------------
#  Heartbeat - a few bytes, sent once per run.
#
#  Lets the website tell these apart:
#      beat + rows 0   = agent healthy, no stock movement
#      beat + error    = PC alive, but the run had a problem
#      no beat at all  = PC off, agent crashed, or token wrong
#
#  Never throws. A failed heartbeat must NEVER fail the sync - the
#  stock data is the job, the beat is only reporting.
# ---------------------------------------------------------------------
function Send-Beat {
    param([int]$Rows = 0, [string]$ErrorText = '')

    try {
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

        $body = @{
            agent   = $AgentKey
            rows    = $Rows
            error   = $ErrorText
            version = $AgentVer
            host    = $env:COMPUTERNAME
        } | ConvertTo-Json -Compress

        $bytes = [System.Text.Encoding]::UTF8.GetBytes($body)

        # Beat token equals $SyncToken - verified against SYNC_AGENT_TOKENS['ashraqa'].
        $null = Invoke-RestMethod -Uri $BeatUrl -Method Post -Body $bytes `
                -ContentType 'application/json; charset=utf-8' `
                -Headers @{ 'X-Sync-Token' = $BeatToken } -TimeoutSec 20

        Write-Log "[beat] sent (rows=$Rows)"
    }
    catch {
        Write-Log "[beat] failed: $($_.Exception.Message)"
    }
}

# ---------------------------------------------------------------------
#  Single exit point - guarantees the heartbeat goes out on EVERY path,
#  including the failure paths. A crashed query still proves the PC and
#  the agent are alive, and the site needs to know that; otherwise a
#  broken SQL statement looks exactly like a dead machine.
# ---------------------------------------------------------------------
function Stop-Agent {
    param([int]$Code = 0, [int]$Rows = 0, [string]$ErrorText = '')

    Send-Beat -Rows $Rows -ErrorText $ErrorText
    Write-Log '--- Sync finished ---'
    exit $Code
}

# Keep the log from growing forever
try {
    if ((Test-Path $LogFile) -and ((Get-Item $LogFile).Length -gt 2MB)) {
        Get-Content $LogFile -Tail 500 | Set-Content "$LogFile.tmp" -Encoding UTF8
        Move-Item "$LogFile.tmp" $LogFile -Force
    }
} catch { }

Write-Log '--- Sync started ---'

# ---------------------------------------------------------------------
#  1) Read balances from SQL Server
#
#  Join: Item -> ItemBarcodes (all barcodes) -> SEARCH_ITEMS (balance)
#
#  Notes:
#   - One item can have several barcodes, all sharing the same balance.
#     We send one row per barcode; the website handles that correctly.
#   - Item.MainBarcode is sometimes NULL, so barcodes are pulled from
#     ItemBarcodes as well and merged with UNION.
#   - Only non-zero balances are sent (keeps the payload small).
#   - LEN >= 8 filters out internal service codes (photocopy items with
#     sequential codes 2, 3, 4 ...) which are not real barcodes.
# ---------------------------------------------------------------------

$Query = @"
SET NOCOUNT ON;

WITH AllCodes AS (
    SELECT b.ProductID AS item_id, LTRIM(RTRIM(b.Barcode)) AS barcode
    FROM ItemBarcodes b
    WHERE b.Barcode IS NOT NULL

    UNION

    SELECT i.ID AS item_id, LTRIM(RTRIM(i.MainBarcode)) AS barcode
    FROM Item i
    WHERE i.MainBarcode IS NOT NULL
)
SELECT DISTINCT
       c.barcode                                   AS barcode,
       i.ID                                        AS item_id,
       LTRIM(RTRIM(i.description))                 AS name,
       CAST(s.Balance_UNIT_Basic AS DECIMAL(14,3)) AS balance,
       s.id_store                                  AS store_id
FROM Item i
INNER JOIN SEARCH_ITEMS s ON s.ID = i.ID
INNER JOIN AllCodes     c ON c.item_id = i.ID
WHERE s.Balance_UNIT_Basic <> 0;
"@

try {
    if ($UseWindowsAuth) {
        # Windows Authentication - same as SSMS. No password stored anywhere.
        $connStr = "Server=$SqlServer;Database=$SqlDatabase;Integrated Security=True;TrustServerCertificate=True;Connection Timeout=30"
        Write-Log "Connecting to $SqlServer as $env:USERNAME (Windows Auth)"
    }
    else {
        $connStr = "Server=$SqlServer;Database=$SqlDatabase;User Id=$SqlUser;Password=$SqlPassword;TrustServerCertificate=True;Connection Timeout=30"
        Write-Log "Connecting to $SqlServer as SQL login '$SqlUser'"
    }

    $conn = New-Object System.Data.SqlClient.SqlConnection($connStr)
    $conn.Open()
    $cmd = $conn.CreateCommand()

    # READ UNCOMMITTED: read without taking shared locks, so the cashier and the
    # replication never wait on us. A value mid-write can be read once; the next
    # run (2 min) corrects it. We only read - nothing here writes to this DB.
    $cmd.CommandText    = "SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED;`n" + $Query
    $cmd.CommandTimeout = 300

    $adapter = New-Object System.Data.SqlClient.SqlDataAdapter($cmd)
    $table   = New-Object System.Data.DataTable
    [void]$adapter.Fill($table)

    $conn.Close()

    Write-Log "Read $($table.Rows.Count) rows from SQL Server."
}
catch {
    $msg = $_.Exception.Message
    Write-Log "SQL Server read failed: $msg"
    # Beat still goes out: the PC is clearly alive, it is SQL that broke.
    Stop-Agent -Code 1 -Rows 0 -ErrorText "SQL read failed: $msg"
}

if ($table.Rows.Count -eq 0) {
    Write-Log 'No rows found - nothing sent.'
    # rows=0 with no error is the "healthy but idle" signal.
    Stop-Agent -Code 0 -Rows 0
}

# ---------------------------------------------------------------------
#  2) Build the JSON payload
# ---------------------------------------------------------------------
# ---------------------------------------------------------------------
#  DELTA - work out what actually changed since the last successful run
# ---------------------------------------------------------------------
#  State file holds: runCount + a map of  barcode -> "iid|name|bal|sid"
#  We fingerprint the WHOLE row, not just the balance, so a renamed
#  item or a moved store also gets sent.
# ---------------------------------------------------------------------
$prev     = @{}
$runCount = 0

if (Test-Path $StateFile) {
    try {
        $st = Get-Content $StateFile -Raw -Encoding UTF8 | ConvertFrom-Json
        $runCount = [int]$st.runCount
        foreach ($p in $st.fp.PSObject.Properties) { $prev[$p.Name] = [string]$p.Value }
    } catch {
        Write-Log 'State file unreadable - treating this run as a full send.'
        $prev = @{}
    }
}

# First run ever, or time for the periodic safety net
$forceFull = ($prev.Count -eq 0) -or ($runCount -ge $FullEvery)

$cur  = @{}
$rows = New-Object System.Collections.ArrayList

foreach ($r in $table.Rows) {
    $bc = [string]$r['barcode']
    if ([string]::IsNullOrWhiteSpace($bc)) { continue }

    $iid  = [int]$r['item_id']
    $name = [string]$r['name']
    $bal  = [double]$r['balance']
    $sid  = [int]$r['store_id']

    # Fingerprint. Balance uses InvariantCulture so a machine with a
    # comma decimal separator cannot make every row look "changed".
    $fp = '{0}|{1}|{2}|{3}' -f $iid, $name,
          $bal.ToString([System.Globalization.CultureInfo]::InvariantCulture), $sid
    $cur[$bc] = $fp

    if ($forceFull -or -not $prev.ContainsKey($bc) -or $prev[$bc] -ne $fp) {
        $null = $rows.Add([ordered]@{
            barcode  = $bc
            item_id  = $iid
            name     = $name
            balance  = $bal
            store_id = $sid
        })
    }
}

# Items that VANISHED from the query result.
#
# The SQL has `WHERE Balance_UNIT_Basic <> 0`, so an item that drops to
# zero disappears instead of coming back as 0. Under the old full-send
# that was fine - the whole table was replaced. With a delta we have to
# report those ourselves, otherwise a sold-out item keeps showing its
# old balance on the site forever.
$goneCount = 0
if (-not $forceFull) {
    foreach ($k in $prev.Keys) {
        if (-not $cur.ContainsKey($k)) {
            $goneCount++
            $null = $rows.Add([ordered]@{
                barcode  = [string]$k
                item_id  = 0
                name     = ''
                balance  = 0
                store_id = 0
            })
        }
    }
}

if ($forceFull) {
    Write-Log "Full send (first run, or $FullEvery runs since the last one)."
}
Write-Log "Delta: $($rows.Count) to send of $($cur.Count) rows (gone: $goneCount)."

# Nothing moved -> heartbeat only. This is what makes a 2-minute
# schedule cheap: a quiet run is one tiny request, not a full upload.
if ($rows.Count -eq 0) {
    Write-Log 'Nothing changed - no upload this run.'
    if ($runCount -lt $FullEvery) {
        try {
            @{ runCount = ($runCount + 1); savedAt = (Get-Date -Format 'o'); fp = $cur } |
                ConvertTo-Json -Depth 4 -Compress | Set-Content $StateFile -Encoding UTF8
        } catch { Write-Log "Could not save state: $($_.Exception.Message)" }
    }
    Stop-Agent -Code 0 -Rows 0
}

$mode    = if ($forceFull) { 'full' } else { 'partial' }
$payload = @{ token = $SyncToken; mode = $mode; rows = $rows } | ConvertTo-Json -Depth 4 -Compress

$sizeKb = [math]::Round(([System.Text.Encoding]::UTF8.GetByteCount($payload) / 1KB), 1)
Write-Log "Payload size: $sizeKb KB"

# ---------------------------------------------------------------------
#  3) Send it (3 attempts, in case the connection drops)
# ---------------------------------------------------------------------
$maxAttempts = 3
$sent    = $false
$stored  = 0
$lastErr = 'unknown error'

for ($attempt = 1; $attempt -le $maxAttempts; $attempt++) {
    try {
        # Some older Windows builds need TLS 1.2 forced on
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

        $bytes = [System.Text.Encoding]::UTF8.GetBytes($payload)

        $response = Invoke-RestMethod -Uri $SyncUrl -Method Post -Body $bytes -ContentType 'application/json; charset=utf-8' -Headers @{ 'X-Sync-Token' = $SyncToken } -TimeoutSec 120

        if ($response.ok -eq $true) {
            Write-Log "Sync OK - stored: $($response.stored) | skipped: $($response.skipped)"
            $stored = [int]$response.stored
            $sent   = $true
            break
        }
        else {
            $lastErr = "server rejected: $($response.error)"
            Write-Log "Server rejected the request: $($response.error)"
        }
    }
    catch {
        $lastErr = $_.Exception.Message
        Write-Log "Attempt $attempt of $maxAttempts failed: $lastErr"
        if ($attempt -lt $maxAttempts) {
            Start-Sleep -Seconds 20
        }
    }
}

if (-not $sent) {
    Write-Log 'Sending failed after all attempts.'
    # State is deliberately NOT saved here. Keeping the old state means
    # the next run resends these same rows. Saving after a failed upload
    # is exactly how balances drift out of sync with nobody noticing.
    # rows=0 on purpose: nothing actually landed on the site, so
    # last_data_at must NOT move forward. The error text explains why.
    Stop-Agent -Code 1 -Rows 0 -ErrorText "send failed: $lastErr"
}

# ---------------------------------------------------------------------
#  SAVE STATE - only after the site confirmed it stored the rows.
#  A full send resets the counter; a partial one advances it.
# ---------------------------------------------------------------------
try {
    $nextCount = if ($forceFull) { 0 } else { $runCount + 1 }
    @{ runCount = $nextCount; savedAt = (Get-Date -Format 'o'); fp = $cur } |
        ConvertTo-Json -Depth 4 -Compress | Set-Content $StateFile -Encoding UTF8
} catch {
    # Not fatal: worst case the next run does a full send.
    Write-Log "Could not save state: $($_.Exception.Message)"
}

# Report what the SITE confirmed it stored, not what we read locally.
Stop-Agent -Code 0 -Rows $stored


# =====================================================================
#  SCHEDULED TASK SETUP
#  Runs hidden, every 30 minutes, and again at every Windows startup.
# =====================================================================
#
#  IMPORTANT - Windows Authentication users read this first:
#  ---------------------------------------------------------
#  The task below runs as SYSTEM. With Windows Auth, SQL Server sees
#  that as the machine account (DOMAIN\COMPUTERNAME$), NOT as your
#  personal login. If SYSTEM has no access, the task fails even though
#  running the script manually works fine.
#
#  Grant access once - open SSMS, click "New Query", paste and run:
#
#    USE [master];
#    CREATE LOGIN [NT AUTHORITY\SYSTEM] FROM WINDOWS;
#    USE [ReduxSalesPUB2];
#    CREATE USER [NT AUTHORITY\SYSTEM] FOR LOGIN [NT AUTHORITY\SYSTEM];
#    ALTER ROLE [db_datareader] ADD MEMBER [NT AUTHORITY\SYSTEM];
#
#  (If a line errors with "already exists", that is fine - skip it.)
#  db_datareader is read-only, so the agent can never modify anything.
#
#  Alternative: instead of SYSTEM, run the task as the logged-in user
#  by replacing  -User 'SYSTEM'  with  -User 'WINDOWS-OP1VF0Q\User'
#  and adding  -Password 'thewindowspassword'  -- but then the task
#  breaks whenever that Windows password changes.
#
#  ---------------------------------------------------------
#  Open PowerShell AS ADMINISTRATOR and paste this whole block:
#
#    $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument '-ExecutionPolicy Bypass -WindowStyle Hidden -NonInteractive -File "C:\fagala\agent_ashraqa.ps1"'
#
#    $t1 = New-ScheduledTaskTrigger -AtStartup
#    $t1.Delay = 'PT2M'
#
#    $t2 = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 30) -RepetitionDuration (New-TimeSpan -Days 3650)
#
#    $set = New-ScheduledTaskSettingsSet -StartWhenAvailable -DontStopOnIdleEnd -DontStopIfGoingOnBatteries -AllowStartIfOnBatteries -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 5) -ExecutionTimeLimit (New-TimeSpan -Minutes 15) -MultipleInstances IgnoreNew
#
#    Register-ScheduledTask -TaskName 'FagalaAshraqaSync' -Action $action -Trigger @($t1,$t2) -Settings $set -RunLevel Highest -User 'SYSTEM' -Force
#
#  CHECK IT:
#    Get-ScheduledTaskInfo -TaskName 'FagalaAshraqaSync'
#    Start-ScheduledTask   -TaskName 'FagalaAshraqaSync'
#    Get-Content C:\fagala\agent_ashraqa.log -Tail 20
#
#  REMOVE IT:
#    Unregister-ScheduledTask -TaskName 'FagalaAshraqaSync' -Confirm:$false
# =====================================================================
