# =====================================================================
#  fagala_scanner_agent.ps1 — وكيل السكانر اللاسلكي
#  نظام الفجالة ستور المالي
#
#  بيحوّل الموبايل لسكانر باركود لاسلكي للكمبيوتر ده:
#  أي كود ممسوح بيتكتب في البرنامج المفتوح (اللي عليه الفوكس) + Enter
#  — زي سكانر USB بالظبط، وفي أي برنامج.
#
#  التشغيل:
#    كليك يمين على الملف ← Run with PowerShell
#    (لو ويندوز رفض: افتح PowerShell واكتب:
#     powershell -ExecutionPolicy Bypass -File fagala_scanner_agent.ps1)
#
#  الإيقاف: اقفل النافذة أو Ctrl+C
#
# =====================================================================
#  مهم — ليه السرعة بتتغير لوحدها
# =====================================================================
#  النسخة القديمة كانت بتسأل السيرفر كل 800 جزء من الثانية على طول،
#  ليل ونهار، سواء فيه حد بيمسح ولا لأ. ده معناه:
#
#      ~100,000 نداء في اليوم — من كل كمبيوتر عليه الوكيل
#
#  وأسوأ حاجة: لما الموقع كان بيقع، كانت بتفضل تخبط بنفس المعدل من
#  غير أي تراجع. يوم 2026-08-08 فايرول هوستنجر حظر IP الإدارة كله
#  وقفل الموقع على كل أجهزة المكتب.
#
#  دلوقتي بتشتغل بتلات سرعات:
#
#      سريع (400ms)  — الموبايل فاتح الكاميرا، أو كود لسه واصل
#      هادي (10s)    — مفيش حد بيمسح. لسه بيعد "شغال" في قايمة
#                       الأجهزة، لأن الحد بتاعها 15 ثانية
#      تراجع (5s←5د) — الموقع مش بيرد. بيتضاعف كل مرة
#
#  الموبايل بيصحّي الوكيل أول ما يفتح الكاميرا (قبل ما توجّهها)،
#  فأول كود بيوصل فورًا زي الأول بالظبط. الفرق إن الوكيل الساكن
#  بقى يعمل ~8,600 نداء في اليوم بدل 100,000.
# =====================================================================

# ★★★ الإعدادات — ظبطها قبل التشغيل ★★★
$BaseUrl = "https://hosnimanagement.com"          # رابط الموقع
$Key     = "zovUE8swzsSMcvUnPX5I84ymZ3FYeYSS"     # نفس مفتاح scan_pull.php بالظبط
$Session = "PC3"                                   # كود الاقتران — نفس اللي في صفحة pc_scan.php

# سرعات السؤال (بالملي ثانية)
$FastMs   = 400        # وقت المسح
$IdleMs   = 10000      # ساكن — لازم يفضل تحت 15 ثانية عشان يعد "أونلاين"
$HotMs    = 90000      # يفضل سريع 90 ثانية بعد آخر كود
$BackMin  = 5000       # أول تراجع لما الموقع ما يردش
$BackMax  = 300000     # أقصى تراجع: 5 دقايق

# =====================================================================
#  لوج بسيط — لأن الوكيل بيشتغل مخفي، فمن غير ده لو وقع محدش هيعرف.
#  بيكتب جنب السكربت، وبيتقصّ لو عدّى ~٥٠٠ سطر عشان مايكبرش للأبد.
$LogFile = Join-Path $PSScriptRoot 'scanner_agent.log'
function Log($msg) {
    try {
        $line = (Get-Date -Format 'yyyy-MM-dd HH:mm:ss') + '  ' + $msg
        Add-Content -Path $LogFile -Value $line -Encoding UTF8
        # تقصير الملف لو كبر
        $lines = @(Get-Content -Path $LogFile -ErrorAction SilentlyContinue)
        if ($lines.Count -gt 500) {
            Set-Content -Path $LogFile -Value ($lines[-300..-1]) -Encoding UTF8
        }
    } catch { }
}

Add-Type -AssemblyName System.Windows.Forms

# منع تشغيل نسختين في نفس الوقت (وإلا الكود يتكتب مرتين)
$me = $PID
$twin = Get-CimInstance Win32_Process -Filter "Name='powershell.exe'" |
        Where-Object { $_.ProcessId -ne $me -and $_.CommandLine -like '*fagala_scanner_agent*' }
if ($twin) { exit }

Write-Host ""
Write-Host "  Fagala Wireless Scanner Agent" -ForegroundColor Cyan
Write-Host "  Session: $Session" -ForegroundColor Cyan
Write-Host "  Waiting for scans... (Ctrl+C to stop)" -ForegroundColor Gray
Write-Host ""
Log "=== started (session $Session, host $env:COMPUTERNAME) ==="

$errShown  = $false
$lastCode  = [datetime]::MinValue   # آخر مرة وصل كود
$backoff   = 0                      # تراجع حالي بالملي ثانية (0 = الاتصال سليم)
$wasIdle   = $false                 # عشان نطبع تغيّر الحالة مرة واحدة بس

while ($true) {

    $sleepMs = $IdleMs

    try {
        $r = Invoke-RestMethod -Uri "$BaseUrl/scan_pull.php" -Method Post `
             -Body @{ key = $Key; session = $Session; host = $env:COMPUTERNAME } -TimeoutSec 10

        # وصلنا للسيرفر — صفّر التراجع
        if ($backoff -gt 0) {
            Write-Host "  * Connection is back." -ForegroundColor Green
            Log "connection restored"
            $backoff = 0
        }
        $errShown = $false

        if ($r.ok -and $r.codes) {
            foreach ($c in $r.codes) {
                # SendKeys بيعامل الرموز دي كأوامر — بنهرّبها (الباركود عادة أرقام بس احتياط)
                $safe = $c -replace '([+^%~(){}])', '{$1}'
                [System.Windows.Forms.SendKeys]::SendWait($safe + "{ENTER}")
                Write-Host ("  " + (Get-Date -Format "HH:mm:ss") + "  -> " + $c) -ForegroundColor Green
                Log ("scan -> " + $c)
                Start-Sleep -Milliseconds 150
            }
            $lastCode = Get-Date
        }

        # نسرّع لو الموبايل فاتح الكاميرا (wake من السيرفر)
        # أو لو لسه واصل كود من شوية — عشان المسح المتتابع يفضل فوري.
        #
        # $r.wake بترجع من scan_pull.php الجديد. لو السيرفر لسه القديم
        # هي بتبقى $null، وساعتها بنعتمد على آخر كود بس — الوكيل يفضل
        # شغال، بس أول كود بعد سكون ممكن يتأخر لحد 10 ثواني.
        $hot = ($r.wake -eq $true) -or (((Get-Date) - $lastCode).TotalMilliseconds -lt $HotMs)

        if ($hot) {
            if ($wasIdle) { Write-Host "  * Scanner active." -ForegroundColor Cyan; $wasIdle = $false }
            $sleepMs = $FastMs
        } else {
            if (-not $wasIdle) { $wasIdle = $true }
            $sleepMs = $IdleMs
        }

    } catch {
        # تراجع تصاعدي. ده أهم سطر في الملف: من غيره الوكيل بيفضل
        # يخبط على موقع واقع (أو IP محظور) بنفس المعدل للأبد،
        # وبيمنع الحظر من إنه يفك لوحده.
        if ($backoff -eq 0) { $backoff = $BackMin }
        else { $backoff = [Math]::Min($backoff * 2, $BackMax) }

        if (-not $errShown) {
            Write-Host ("  ! Connection problem: " + $_.Exception.Message) -ForegroundColor Yellow
            Write-Host "  (Backing off — will keep retrying more slowly...)" -ForegroundColor Gray
            Log ("connection problem: " + $_.Exception.Message)
            $errShown = $true
        }
        Write-Host ("    retry in " + [int]($backoff / 1000) + "s") -ForegroundColor DarkGray

        $sleepMs = $backoff
        $wasIdle = $true
    }

    Start-Sleep -Milliseconds $sleepMs
}
