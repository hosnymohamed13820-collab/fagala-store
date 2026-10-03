# install_task.ps1 — بيسجّل الباك أب اليومي في Task Scheduler
# كل يوم ٣ الفجر. ولو الجهاز كان مقفول، بيشتغل أول ما يفتح (StartWhenAvailable).
# التشغيل مرة واحدة:  powershell -ExecutionPolicy Bypass -File local\backup\install_task.ps1
# الإلغاء:            Unregister-ScheduledTask -TaskName 'FagalaDailyBackup' -Confirm:$false

$bash   = 'C:\Program Files\Git\bin\bash.exe'
$script = (Resolve-Path (Join-Path $PSScriptRoot 'daily_backup.sh')).Path -replace '\\', '/'

# الفولدر فيه config.php بالباسوردات — مقفول على اليوزر ده + SYSTEM + الأدمن بس
New-Item -ItemType Directory -Force 'D:agala_backups' | Out-Null
icacls 'D:agala_backups' /inheritance:r /grant:r "$($env:USERNAME):(OI)(CI)F" "SYSTEM:(OI)(CI)F" "Administrators:(OI)(CI)F" /T /Q | Out-Null

if (-not (Test-Path $bash)) { throw "Git Bash mesh mawgood: $bash" }

$action   = New-ScheduledTaskAction -Execute $bash -Argument "-lc `"'$script'`""
$trigger  = New-ScheduledTaskTrigger -Daily -At 3:00am
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -DontStopIfGoingOnBatteries `
            -AllowStartIfOnBatteries -ExecutionTimeLimit (New-TimeSpan -Hours 1) `
            -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 30)

Register-ScheduledTask -TaskName 'FagalaDailyBackup' -Action $action -Trigger $trigger `
    -Settings $settings -Description 'Fagala store: daily DB + files backup to D:\fagala_backups' -Force | Out-Null

Get-ScheduledTask -TaskName 'FagalaDailyBackup' | Select-Object TaskName, State
