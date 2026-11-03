param([switch]$Uninstall)
# =====================================================================
#  install_printer_agent.ps1 — مثبّت وكيل طباعة الإيصالات (نسخة من مثبّت السكانر)
#
#  التشغيل: دبل كليك على install_printer_agent.bat  (مش محتاج أدمن)
#  الإزالة: دبل كليك على uninstall_printer_agent.bat
#
#  ---------------------------------------------------------------
#  ليه اتغيّر عن النسخة القديمة (2026-08-10)
#  ---------------------------------------------------------------
#  القديم كان بيعمل ملف VBS مخفي ويحطه في مجلد Startup. التوليفة
#  دي (سكربت مخفي + Startup + SendKeys) = التوقيع اللي كل برامج
#  الحماية بتعتبره فيروس تحكم عن بعد. 360 Total Security تحديداً
#  بيمسحه لوحده من غير ما يسأل، فالسكانر كان بيقف من غير سبب واضح.
#
#  الجديد بيستخدم Task Scheduler — دي الطريقة الرسمية في ويندوز
#  لتشغيل حاجة في الخلفية، وبرامج الحماية بتثق فيها أكتر بكتير.
#  مفيش VBS، مفيش ملف في Startup. المهمة بتشتغل:
#     • مع دخول المستخدم       (فتح الويندوز)
#     • مخفية تماماً           (بدون نافذة)
#     • وتعيد التشغيل لو وقعت   (كل دقيقة، للأبد)
#
#  المسار بيتحدد لوحده من مكان السكربت — منقول الفولدر على أي درايف
#  أو أي جهاز، يشتغل زي ما هو. مفيش D:\ مسمّرة تكسره بصمت.
# =====================================================================
$ErrorActionPreference = 'Stop'

$TaskName = 'FagalaPrinterAgent'
$agent    = Join-Path $PSScriptRoot 'fagala_printer_agent.ps1'
# بنقرا الإعداد كنص بس، من غير تشغيل الوكيل أو قراءة المفتاح.
if (Test-Path -LiteralPath $agent) {
    $agentText = [System.IO.File]::ReadAllText($agent)
    if ($agentText -match '(?m)^\$Kinds\s*=\s*["'']labels["'']') {
        $TaskName = 'FagalaLabelAgent'
    }
}

Write-Host ''
Write-Host '  Fagala Receipt Printer - Installer' -ForegroundColor Cyan
Write-Host ''

# --- إيقاف أي نسخة شغالة دلوقتي ---------------------------------------
Write-Host '  [1] Stopping any running agent...'
Get-CimInstance Win32_Process -Filter "Name='powershell.exe'" -ErrorAction SilentlyContinue |
    Where-Object { ($_.CommandLine -like ('*"' + $agent + '"*') -or $_.CommandLine -like ('*-File ' + $agent)) -and $_.ProcessId -ne $PID } |
    ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }

# --- الإزالة ----------------------------------------------------------
if ($Uninstall) {
    Write-Host '  [2] Removing scheduled task...'
    schtasks /Delete /TN $TaskName /F 2>$null | Out-Null

    # تنظيف بقايا النسخة القديمة لو موجودة
    $oldVbs = Join-Path ([Environment]::GetFolderPath('Startup')) 'fagala_printer_hidden.vbs'
    if ($TaskName -eq 'FagalaPrinterAgent' -and (Test-Path $oldVbs)) { Remove-Item $oldVbs -Force -ErrorAction SilentlyContinue }

    Write-Host ''
    Write-Host '  Agent stopped and removed. Bye!' -ForegroundColor Green
    Write-Host ''
    exit
}

# --- تحقّق من وجود الوكيل ---------------------------------------------
if (-not (Test-Path $agent)) {
    Write-Host ''
    Write-Host '  ERROR: fagala_printer_agent.ps1 not found next to this installer.' -ForegroundColor Red
    Write-Host '  Keep all the kit files together in one folder.' -ForegroundColor Red
    Write-Host ''
    exit 1
}

# --- تنظيف بقايا النسخة القديمة (VBS + Startup) ----------------------
Write-Host '  [2] Cleaning up old version (if any)...'
$oldVbsLocal = Join-Path $PSScriptRoot 'fagala_printer_hidden.vbs'
$oldVbsStart = Join-Path ([Environment]::GetFolderPath('Startup')) 'fagala_printer_hidden.vbs'
if ($TaskName -eq 'FagalaPrinterAgent' -and (Test-Path $oldVbsStart)) { Remove-Item $oldVbsStart -Force -ErrorAction SilentlyContinue }
if ($TaskName -eq 'FagalaPrinterAgent' -and (Test-Path $oldVbsLocal)) { Remove-Item $oldVbsLocal -Force -ErrorAction SilentlyContinue }

# --- إنشاء المهمة المجدولة -------------------------------------------
Write-Host '  [3] Registering scheduled task...'

# الأمر اللي هيتنفّذ: PowerShell مخفي يشغّل الوكيل
$psArgs = '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $agent + '"'

$action    = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $psArgs
$trigger   = New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME
$principal = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType Interactive -RunLevel Limited

# إعدادات: يبدأ فورًا، يعيد المحاولة لو وقع، مايوقفش لو الجهاز شغال ببطارية
$settings  = New-ScheduledTaskSettingsSet `
                -AllowStartIfOnBatteries `
                -DontStopIfGoingOnBatteries `
                -StartWhenAvailable `
                -RestartInterval (New-TimeSpan -Minutes 1) `
                -RestartCount 999 `
                -ExecutionTimeLimit (New-TimeSpan -Hours 0)   # 0 = بدون حد زمني

# سجّل المهمة (تستبدل القديمة لو موجودة)
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger `
    -Principal $principal -Settings $settings -Force | Out-Null

# --- تشغيلها دلوقتي ---------------------------------------------------
Write-Host '  [4] Starting agent now (hidden)...'
Start-ScheduledTask -TaskName $TaskName

Write-Host ''
Write-Host '  Done! The printer agent is now running hidden,' -ForegroundColor Green
Write-Host '  and starts automatically every time you log in.' -ForegroundColor Green
Write-Host ''
Write-Host '  Test it: open Notepad, then scan a barcode from your phone.'
Write-Host '  Log:     printer_agent.log  (next to the agent file)'
Write-Host '  Remove:  run uninstall_printer_agent.bat'
Write-Host ''

# --- ملاحظة برامج الحماية --------------------------------------------
Write-Host '  --------------------------------------------------------' -ForegroundColor DarkGray
Write-Host '  If 360 Total Security still flags the agent, add this' -ForegroundColor Yellow
Write-Host '  folder to its whitelist (Settings > Exclude Files):' -ForegroundColor Yellow
Write-Host ('  ' + $PSScriptRoot) -ForegroundColor White
Write-Host '  --------------------------------------------------------' -ForegroundColor DarkGray
Write-Host ''
