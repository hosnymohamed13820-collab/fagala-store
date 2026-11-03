# تركيب وكيل الليبلز: انسخ الطقم في فولدر لوحده جنب طقم الإيصالات.
# ظبط $Kinds = "labels" و$PrinterName باسم طابعة الليبلز في ويندوز.
# ظبط $Session بكود مختلف و$BranchId للفرع و$Key محليًا وقت التركيب.
# شغل install_printer_agent.bat؛ هيسجل FagalaLabelAgent لوحده.
# =====================================================================
#  fagala_printer_agent.ps1 — وكيل الطباعة الحرارية لإيصالات الحجز
#  نظام الفجالة ستور المالي
#
#  بيشتغل في الخلفية على كمبيوتر الفرع:
#  ١. بيسأل السيرفر (print_pull.php) عن أي إيصالات حجز جديدة للفرع ده.
#  ٢. بيرسم الإيصال بمحرك ويندوز (GDI+) بخط Tahoma على الطابعة الحرارية
#     (80mm) كصورة نقطية عشان الحروف العربية تطلع موصّلة ومضبوطة.
#  ٣. بيبعت للسيرفر تأكيد إن الإيصال اتطبع بنجاح (done).
#
#  التشغيل:
#    powershell -ExecutionPolicy Bypass -File fagala_printer_agent.ps1
#
#  وضع الاختبار المحلي (بدون الاتصال بالسيرفر):
#    powershell -ExecutionPolicy Bypass -File fagala_printer_agent.ps1 -TestFile "sample.json" -OutPdf "receipt.pdf"
# =====================================================================

param(
    [string]$TestFile = "",
    [string]$OutPdf   = ""
)

Add-Type -AssemblyName System.Drawing

# ★★★ الإعدادات — ظبطها على كل جهاز قبل التشغيل ★★★
$BaseUrl     = "https://hosnimanagement.com"   # إشراقة: ".../ashraqa"
$Key         = ""                              # 🔴 كلود بيحطه وقت التركيب
$Session     = "PRN-FAGALA"                    # كود الوكيل — مختلف لكل جهاز
$BranchId    = 0                               # 🔴 كلود بيحطه — رقم الفرع من التعريفات
$Kinds       = "receipts"                     # لليبلز غيّرها لـ labels
$PrinterName = "XP-80C"                        # اسم الطابعة زي ما هو في الويندوز

if ($Kinds -eq 'labels' -and -not $Session.StartsWith('LBL-')) {
    $Session = 'LBL-' + $Session
}

# الأسامي المؤكدة من محمد للطابعات في الفروع:
# الفجالة · بونجور: XP-80C
# دجلة: XP-80C (copy 1)
# اي بي سي: XP-90
# إشراقة: EPSON TM-T20II Receipt

# سرعات الاستعلام (بالملي ثانية)
$FastMs   = 2000       # سريع: بعد طبع مهمة (لمدة ٦٠ ثانية)
$IdleMs   = 10000      # هادي: لما مفيش شغل
$BackMin  = 5000       # أول تراجع لو السيرفر مش بيرد (٥ ثواني)
$BackMax  = 300000     # أقصى تراجع: ٥ دقايق
$HotMs    = 60000      # يفضل سريع ٦٠ ثانية بعد آخر إيصال

# =====================================================================
#  لوج الوكيل — بيكتب جنب السكربت ويقص لو عدى ~٥٠٠ سطر
# =====================================================================
$LogFile = Join-Path $PSScriptRoot 'printer_agent.log'
function Log($msg) {
    try {
        $line = (Get-Date -Format 'yyyy-MM-dd HH:mm:ss') + '  ' + $msg
        Add-Content -Path $LogFile -Value $line -Encoding UTF8
        $lines = @(Get-Content -Path $LogFile -ErrorAction SilentlyContinue)
        if ($lines.Count -gt 500) {
            Set-Content -Path $LogFile -Value ($lines[-300..-1]) -Encoding UTF8
        }
    } catch { }
}

# أبعاد ورق الإيصالات الحراري (الوحدة: 1/100 بوصة — Display)
$script:PaperWidth     = 315   # 80mm
# دي مسافة راس الطباعة للسكينة؛ بتتظبط على الطابعة عشان آخر سطر مايتقصش.
$script:ReceiptBottomFeed = 60  # Display: 15.24mm
# 🔴 أول تجربة على الإبسون (إشراقة، ٢١ سبتمبر) قصّت أول حرف من كل كلمة
#    على اليمين: «حجز رقم» طلعت «جز رقم». الطابعة بتبدأ الرسم من أول
#    المساحة القابلة للطباعة (~٧٢مم = ٢٨٣ وحدة) مش من حرف الورقة،
#    فالحافة اليمين كانت عند ٢٩٥ = بره المساحة بـ٣مم. دلوقتي ٢٧٠.
$script:PrintableLeft  = 8     # هامش شمال
$script:PrintWidth     = 262   # عرض النص — الحافة اليمين عند ٢٧٠ بأمان
$script:PrintableRight = $script:PrintableLeft + $script:PrintWidth # 270

# =====================================================================
#  دوال رسم وتخطيط الإيصال
# =====================================================================
function Render-Blocks($g, $blocks, [ref]$yPos, [bool]$isDraw) {
    # الخطوط (Tahoma مضمون ومناسب للعربي على كل إصدارات ويندوز)
    $fH1    = New-Object System.Drawing.Font("Tahoma", 13, [System.Drawing.FontStyle]::Bold)
    $fH2    = New-Object System.Drawing.Font("Tahoma", 11, [System.Drawing.FontStyle]::Bold)
    $fBig   = New-Object System.Drawing.Font("Tahoma", 11, [System.Drawing.FontStyle]::Bold)
    $fNorm  = New-Object System.Drawing.Font("Tahoma", 9,  [System.Drawing.FontStyle]::Regular)
    $fNote  = New-Object System.Drawing.Font("Tahoma", 8,  [System.Drawing.FontStyle]::Regular)
    $fStamp = New-Object System.Drawing.Font("Tahoma", 16, [System.Drawing.FontStyle]::Bold)

    $brush    = [System.Drawing.Brushes]::Black
    $penSolid = New-Object System.Drawing.Pen([System.Drawing.Color]::Black, 1)
    $penDash  = New-Object System.Drawing.Pen([System.Drawing.Color]::Black, 1)
    $penDash.DashStyle = [System.Drawing.Drawing2D.DashStyle]::Dash

    # صيغ النصوص والمحاذاة
    $sfCenterRtl = New-Object System.Drawing.StringFormat
    $sfCenterRtl.Alignment = [System.Drawing.StringAlignment]::Center
    $sfCenterRtl.FormatFlags = [System.Drawing.StringFormatFlags]::DirectionRightToLeft

    $sfRightRtl = New-Object System.Drawing.StringFormat
    $sfRightRtl.Alignment = [System.Drawing.StringAlignment]::Near
    $sfRightRtl.FormatFlags = [System.Drawing.StringFormatFlags]::DirectionRightToLeft

    $sfLeftLtr = New-Object System.Drawing.StringFormat
    $sfLeftLtr.Alignment = [System.Drawing.StringAlignment]::Near

    $sfLeftRtl = New-Object System.Drawing.StringFormat
    $sfLeftRtl.Alignment = [System.Drawing.StringAlignment]::Far
    $sfLeftRtl.FormatFlags = [System.Drawing.StringFormatFlags]::DirectionRightToLeft

    try {
        foreach ($b in $blocks) {
            $type = [string]$b.t
            switch ($type) {
                'h1' {
                    $txt = [string]$b.v
                    $rect = New-Object System.Drawing.RectangleF($script:PrintableLeft, $yPos.Value, $script:PrintWidth, 1000)
                    $sz = $g.MeasureString($txt, $fH1, [int]$script:PrintWidth, $sfCenterRtl)
                    $rect.Height = $sz.Height
                    if ($isDraw) { $g.DrawString($txt, $fH1, $brush, $rect, $sfCenterRtl) }
                    $yPos.Value += $sz.Height + 4
                }
                'h2' {
                    $txt = [string]$b.v
                    $rect = New-Object System.Drawing.RectangleF($script:PrintableLeft, $yPos.Value, $script:PrintWidth, 1000)
                    $sz = $g.MeasureString($txt, $fH2, [int]$script:PrintWidth, $sfCenterRtl)
                    $rect.Height = $sz.Height
                    if ($isDraw) { $g.DrawString($txt, $fH2, $brush, $rect, $sfCenterRtl) }
                    $yPos.Value += $sz.Height + 4
                }
                'line' {
                    $txt = [string]$b.v
                    $rect = New-Object System.Drawing.RectangleF($script:PrintableLeft, $yPos.Value, $script:PrintWidth, 1000)
                    $sz = $g.MeasureString($txt, $fNorm, [int]$script:PrintWidth, $sfCenterRtl)
                    $rect.Height = $sz.Height
                    if ($isDraw) { $g.DrawString($txt, $fNorm, $brush, $rect, $sfCenterRtl) }
                    $yPos.Value += $sz.Height + 3
                }
                'note' {
                    $txt = [string]$b.v
                    $rect = New-Object System.Drawing.RectangleF($script:PrintableLeft, $yPos.Value, $script:PrintWidth, 1000)
                    $sz = $g.MeasureString($txt, $fNote, [int]$script:PrintWidth, $sfCenterRtl)
                    $rect.Height = $sz.Height
                    if ($isDraw) { $g.DrawString($txt, $fNote, $brush, $rect, $sfCenterRtl) }
                    $yPos.Value += $sz.Height + 3
                }
                'stamp' {
                    $txt = [string]$b.v
                    $rect = New-Object System.Drawing.RectangleF($script:PrintableLeft, $yPos.Value, $script:PrintWidth, 1000)
                    $sz = $g.MeasureString($txt, $fStamp, [int]$script:PrintWidth, $sfCenterRtl)
                    $rect.Height = $sz.Height
                    if ($isDraw) { $g.DrawString($txt, $fStamp, $brush, $rect, $sfCenterRtl) }
                    $yPos.Value += $sz.Height + 6
                }
                'kv' {
                    $k = [string]$b.k
                    $v = [string]$b.v
                    $isLtr = ($b.dir -eq 'ltr')

                    $valWidth = 110
                    $keyWidth = $script:PrintWidth - $valWidth

                    $valRect = New-Object System.Drawing.RectangleF($script:PrintableLeft, $yPos.Value, $valWidth, 100)
                    $keyRect = New-Object System.Drawing.RectangleF(($script:PrintableLeft + $valWidth), $yPos.Value, $keyWidth, 100)

                    $szK = $g.MeasureString($k, $fNorm, [int]$keyWidth, $sfRightRtl)
                    $vSf = if ($isLtr) { $sfLeftLtr } else { $sfLeftRtl }
                    $szV = $g.MeasureString($v, $fNorm, [int]$valWidth, $vSf)

                    $rowH = [Math]::Max($szK.Height, $szV.Height)
                    $valRect.Height = $rowH
                    $keyRect.Height = $rowH

                    if ($isDraw) {
                        $g.DrawString($k, $fNorm, $brush, $keyRect, $sfRightRtl)
                        $g.DrawString($v, $fNorm, $brush, $valRect, $vSf)
                    }
                    $yPos.Value += $rowH + 2
                }
                'big' {
                    $k = [string]$b.k
                    $v = [string]$b.v
                    $isLtr = ($b.dir -eq 'ltr')

                    $valWidth = 110
                    $keyWidth = $script:PrintWidth - $valWidth

                    $valRect = New-Object System.Drawing.RectangleF($script:PrintableLeft, $yPos.Value, $valWidth, 100)
                    $keyRect = New-Object System.Drawing.RectangleF(($script:PrintableLeft + $valWidth), $yPos.Value, $keyWidth, 100)

                    $szK = $g.MeasureString($k, $fBig, [int]$keyWidth, $sfRightRtl)
                    $vSf = if ($isLtr) { $sfLeftLtr } else { $sfLeftRtl }
                    $szV = $g.MeasureString($v, $fBig, [int]$valWidth, $vSf)

                    $rowH = [Math]::Max($szK.Height, $szV.Height)
                    $valRect.Height = $rowH
                    $keyRect.Height = $rowH

                    if ($isDraw) {
                        $g.DrawString($k, $fBig, $brush, $keyRect, $sfRightRtl)
                        $g.DrawString($v, $fBig, $brush, $valRect, $vSf)
                    }
                    $yPos.Value += $rowH + 3
                }
                'row' {
                    $rTxt = [string]$b.r
                    $lTxt = [string]$b.l

                    $qtyWidth  = 55
                    $nameWidth = $script:PrintWidth - $qtyWidth

                    $qtyRect  = New-Object System.Drawing.RectangleF($script:PrintableLeft, $yPos.Value, $qtyWidth, 1000)
                    $nameRect = New-Object System.Drawing.RectangleF(($script:PrintableLeft + $qtyWidth), $yPos.Value, $nameWidth, 1000)

                    $szR = $g.MeasureString($rTxt, $fNorm, [int]$nameWidth, $sfRightRtl)
                    $szL = $g.MeasureString($lTxt, $fNorm, [int]$qtyWidth, $sfLeftLtr)

                    $rowH = [Math]::Max($szR.Height, $szL.Height)
                    $qtyRect.Height  = $rowH
                    $nameRect.Height = $rowH

                    if ($isDraw) {
                        $g.DrawString($rTxt, $fNorm, $brush, $nameRect, $sfRightRtl)
                        $g.DrawString($lTxt, $fNorm, $brush, $qtyRect, $sfLeftLtr)
                    }
                    $yPos.Value += $rowH + 2
                }
                'rule' {
                    $s = [string]$b.s
                    $y = $yPos.Value + 4
                    if ($isDraw) {
                        if ($s -eq 'double') {
                            $g.DrawLine($penSolid, $script:PrintableLeft, $y, $script:PrintableRight, $y)
                            $g.DrawLine($penSolid, $script:PrintableLeft, ($y + 3), $script:PrintableRight, ($y + 3))
                        } elseif ($s -eq 'dash') {
                            $g.DrawLine($penDash, $script:PrintableLeft, $y, $script:PrintableRight, $y)
                        } else {
                            $g.DrawLine($penSolid, $script:PrintableLeft, $y, $script:PrintableRight, $y)
                        }
                    }
                    if ($s -eq 'double') { $yPos.Value += 10 } else { $yPos.Value += 8 }
                }
                'gap' {
                    $n = if ($b.n) { [int]$b.n } else { 1 }
                    $yPos.Value += ($n * 12)
                }
                default {
                    # تجاهل أي نوع غير معروف بهدوء
                }
            }
        }
    } finally {
        $fH1.Dispose(); $fH2.Dispose(); $fBig.Dispose(); $fNorm.Dispose(); $fNote.Dispose(); $fStamp.Dispose()
        $penSolid.Dispose(); $penDash.Dispose()
        $sfCenterRtl.Dispose(); $sfRightRtl.Dispose(); $sfLeftLtr.Dispose(); $sfLeftRtl.Dispose()
    }
}

# 🔴 كل نسخة صفحة لوحدها — مش النسختين على شريط واحد.
#    السبب: الطابعة الحرارية بتقص عند **نهاية الصفحة**. لما كانوا
#    صفحة واحدة، القصّة كانت بتيجي بعد النسختين والموظف يقص بإيده.
#    دلوقتي المكنة بتقص بينهم لوحدها لو القص مفعّل في الدرايفر،
#    ولو مش مفعّل بيفضل فيه فاصل واضح وبرضه ينفع يتقص بالإيد.
function Render-Copy($g, $copy, [bool]$isDraw) {
    [float]$y = 15
    $refY = [ref]$y
    Render-Blocks $g $copy.blocks $refY $isDraw
    $y = $refY.Value
    # 🔴 مسافة فاضية تحت قبل القصّة. كانت ١٥ (~٤مم) والإبسون قص آخر
    #    سطر من نصه — «احتفظ بالإيصال» طلعت مقطوعة. ٦٠ = ~١٥مم.
    $y += 60
    return $y
}

# 🔴 كل نسخة **أمر طباعة منفصل** مش صفحة تانية في نفس الأمر.
#    جرّبنا صفحتين في أمر واحد على الإبسون والإيصالين طلعوا لازقين —
#    الدرايفر بيقص في آخر **الأمر** مش آخر كل صفحة. أمرين = قصّتين.
function Print-Receipt($payload, [string]$targetPrinter, [string]$pdfFile) {
    if (-not $payload -or $payload.v -ne 1) {
        throw "نسخة تخطيط مش مدعومة (إصدار التخطيط غير صالح)"
    }
    $n = 0
    foreach ($copy in $payload.copies) {
        $n++
        # في وضع الاختبار كل نسخة ملف PDF لوحدها عشان التانية ماتكتبش فوق الأولى
        $outFile = ""
        if ($pdfFile -ne "") {
            $outFile = [System.IO.Path]::ChangeExtension($pdfFile, $null).TrimEnd('.') + "_$n.pdf"
        }
        Print-OneCopy $copy $targetPrinter $outFile
    }
}

function Print-OneCopy($copy, [string]$targetPrinter, [string]$pdfFile) {
    $doc = New-Object System.Drawing.Printing.PrintDocument
    $doc.PrinterSettings.PrinterName = $targetPrinter

    # التحقق من صلاحية الطابعة (لو بنطبع على طابعة حقيقية مش لملف PDF)
    $isPdf = ($targetPrinter -like "*PDF*" -or $pdfFile -ne "")
    if (-not $isPdf -and -not $doc.PrinterSettings.IsValid) {
        $doc.Dispose()
        throw "الطابعة '$targetPrinter' غير معرّفة أو غير متصلة بالنظام"
    }

    try {
        # مرحلة ١: قياس الارتفاع عشان الورق مايتهدرش
        $measG = $doc.PrinterSettings.CreateMeasurementGraphics()
        $h = Render-Copy $measG $copy $false
        $measG.Dispose()

        $paperHeight = [int][Math]::Ceiling($h)
        $doc.DefaultPageSettings.PaperSize = New-Object System.Drawing.Printing.PaperSize("Receipt", $script:PaperWidth, $paperHeight)

        if ($pdfFile -ne "") {
            $doc.PrinterSettings.PrintToFile = $true
            $doc.PrinterSettings.PrintFileName = $pdfFile
        }

        # مرحلة ٢: الرسم
        $script:currentCopy = $copy
        $doc.add_PrintPage({
            param($sender, $e)
            Render-Copy $e.Graphics $script:currentCopy $true | Out-Null
            $e.HasMorePages = $false
        })

        $doc.Print()
    } finally {
        $doc.Dispose()
    }
}

# =====================================================================
#  دوال رسم وتخطيط إيصال البيع والمرتجع (بريف ٢٤٤)
# =====================================================================
function Render-SaleReceipt($g, $p, [bool]$isDraw) {
    $fH1    = New-Object System.Drawing.Font("Tahoma", 12, [System.Drawing.FontStyle]::Bold)
    $fH2    = New-Object System.Drawing.Font("Tahoma", 10.5, [System.Drawing.FontStyle]::Bold)
    $fBig   = New-Object System.Drawing.Font("Tahoma", 10.5, [System.Drawing.FontStyle]::Bold)
    $fBold  = New-Object System.Drawing.Font("Tahoma", 8.25,  [System.Drawing.FontStyle]::Bold)
    $fNorm  = New-Object System.Drawing.Font("Tahoma", 8.25,  [System.Drawing.FontStyle]::Bold)
    $fNote  = New-Object System.Drawing.Font("Tahoma", 7.5,  [System.Drawing.FontStyle]::Bold)
    $fStamp = New-Object System.Drawing.Font("Tahoma", 14, [System.Drawing.FontStyle]::Bold)

    $brush    = [System.Drawing.Brushes]::Black
    $penSolid = New-Object System.Drawing.Pen([System.Drawing.Color]::Black, 1)
    $penDash  = New-Object System.Drawing.Pen([System.Drawing.Color]::Black, 1)
    $penDash.DashStyle = [System.Drawing.Drawing2D.DashStyle]::Dash

    $sfCenterRtl = New-Object System.Drawing.StringFormat
    $sfCenterRtl.Alignment = [System.Drawing.StringAlignment]::Center
    $sfCenterRtl.FormatFlags = [System.Drawing.StringFormatFlags]::DirectionRightToLeft

    $sfRightRtl = New-Object System.Drawing.StringFormat
    $sfRightRtl.Alignment = [System.Drawing.StringAlignment]::Near
    $sfRightRtl.FormatFlags = [System.Drawing.StringFormatFlags]::DirectionRightToLeft

    $sfLeftLtr = New-Object System.Drawing.StringFormat
    $sfLeftLtr.Alignment = [System.Drawing.StringAlignment]::Near

    $sfLeftRtl = New-Object System.Drawing.StringFormat
    $sfLeftRtl.Alignment = [System.Drawing.StringAlignment]::Far
    $sfLeftRtl.FormatFlags = [System.Drawing.StringFormatFlags]::DirectionRightToLeft

    $sfCenterLtr = New-Object System.Drawing.StringFormat
    $sfCenterLtr.Alignment = [System.Drawing.StringAlignment]::Center
    # خانات الجدول من غير حشو GDI الزيادة عشان عناوين الأعمدة تدخل زي الصفحة.
    $sfCellRtl = [System.Drawing.StringFormat]::GenericTypographic.Clone()
    $sfCellRtl.FormatFlags = [System.Drawing.StringFormatFlags]::DirectionRightToLeft
    $sfCellRtl.Alignment = [System.Drawing.StringAlignment]::Near
    $sfCellCenter = $sfCellRtl.Clone()
    $sfCellCenter.Alignment = [System.Drawing.StringAlignment]::Center
    $sfCellLtr = [System.Drawing.StringFormat]::GenericTypographic.Clone()
    $sfCellLtr.Alignment = [System.Drawing.StringAlignment]::Center
    $sfCellName = $sfCellRtl.Clone()
    $sfCellName.Trimming = [System.Drawing.StringTrimming]::EllipsisWord
    $sfCellName.FormatFlags = [System.Drawing.StringFormatFlags]([int]$sfCellName.FormatFlags -bor [int][System.Drawing.StringFormatFlags]::LineLimit)
    $sfCellCode = [System.Drawing.StringFormat]::GenericTypographic.Clone()
    $sfCellCode.Alignment = [System.Drawing.StringAlignment]::Far
    $sfCellCode.FormatFlags = [System.Drawing.StringFormatFlags]::NoWrap
    $fCode = New-Object System.Drawing.Font("Tahoma", 7.5, [System.Drawing.FontStyle]::Bold)
    $fHeader = New-Object System.Drawing.Font("Tahoma", 7, [System.Drawing.FontStyle]::Bold)
    $fNum = New-Object System.Drawing.Font("Tahoma", 8.25, [System.Drawing.FontStyle]::Bold)
    $g.PageUnit = [System.Drawing.GraphicsUnit]::Display
    $g.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::SingleBitPerPixelGridFit
    $barcodeBits = [string]$p.receipt_barcode_bits
    $hasBarcode = $barcodeBits -match '^[01]+$'
    [float]$y = 2.5

    try {
        # ختم نسخة إذا كانت إعادة طباعة
        if ($p.is_reprint) {
            $stampTxt = "نسخة"
            $rect = New-Object System.Drawing.RectangleF($script:PrintableLeft, $y, $script:PrintWidth, 1000)
            $sz = $g.MeasureString($stampTxt, $fStamp, [int]$script:PrintWidth, $sfCenterRtl)
            $rect.Height = $sz.Height
            if ($isDraw) {
                $boxX = $script:PrintableLeft + ($script:PrintWidth - $sz.Width) / 2 - 8
                $boxW = $sz.Width + 16
                $g.DrawRectangle($penSolid, $boxX, $y, $boxW, ($sz.Height + 2))
                $g.DrawString($stampTxt, $fStamp, $brush, $rect, $sfCenterRtl)
            }
            $y += $sz.Height + 2
        }

        # اسم الفرع
        $branchTxt = [string]$p.branch_name
        if ($branchTxt -ne "") {
            $rect = New-Object System.Drawing.RectangleF($script:PrintableLeft, $y, $script:PrintWidth, 1000)
            $sz = $g.MeasureString($branchTxt, $fH1, [int]$script:PrintWidth, $sfCenterRtl)
            $rect.Height = $sz.Height
            if ($isDraw) { $g.DrawString($branchTxt, $fH1, $brush, $rect, $sfCenterRtl) }
            $y += $sz.Height + 1
        }

        # تليفون الفرع
        $phoneTxt = [string]$p.branch_phone
        if ($phoneTxt -ne "") {
            $rect = New-Object System.Drawing.RectangleF($script:PrintableLeft, $y, $script:PrintWidth, 1000)
            $sz = $g.MeasureString($phoneTxt, $fNote, [int]$script:PrintWidth, $sfCenterRtl)
            $rect.Height = $sz.Height
            if ($isDraw) { $g.DrawString($phoneTxt, $fNote, $brush, $rect, $sfCenterRtl) }
            $y += $sz.Height + 1
        }

        # نوع الإيصال (بيع / مرتجع)
        $isReturn = [bool]$p.is_return
        $titleTxt = if ($isReturn) { "إيصال مرتجع" } else { "إيصال بيع" }
        $rect = New-Object System.Drawing.RectangleF($script:PrintableLeft, $y, $script:PrintWidth, 1000)
        $sz = $g.MeasureString($titleTxt, $fH2, [int]$script:PrintWidth, $sfCenterRtl)
        $rect.Height = $sz.Height
        if ($isDraw) { $g.DrawString($titleTxt, $fH2, $brush, $rect, $sfCenterRtl) }
        $y += $sz.Height + 1

        # لو مرتجع: رقم الفاتورة الأصلية
        if ($isReturn -and [string]$p.ref_receipt_no -ne "") {
            $refTxt = "فاتورة أصلية: " + [string]$p.ref_receipt_no
            $rect = New-Object System.Drawing.RectangleF($script:PrintableLeft, $y, $script:PrintWidth, 1000)
            $sz = $g.MeasureString($refTxt, $fNote, [int]$script:PrintWidth, $sfCenterRtl)
            $rect.Height = $sz.Height
            if ($isDraw) { $g.DrawString($refTxt, $fNote, $brush, $rect, $sfCenterRtl) }
            $y += $sz.Height + 1
        }

        # خط مزدوج
        if ($isDraw) {
            $g.DrawLine($penSolid, $script:PrintableLeft, $y, $script:PrintableRight, $y)
            $g.DrawLine($penSolid, $script:PrintableLeft, ($y + 3), $script:PrintableRight, ($y + 3))
        }
        $y += 5

        $script:tmpY = $y
        $DrawKV = {
            param($k, $v, [bool]$ltr = $false)
            if ([string]::IsNullOrWhiteSpace($v)) { return }
            $valWidth = $script:PrintWidth * 0.62
            $keyWidth = $script:PrintWidth - $valWidth
            $valRect = New-Object System.Drawing.RectangleF($script:PrintableLeft, $script:tmpY, $valWidth, 100)
            $keyRect = New-Object System.Drawing.RectangleF(($script:PrintableLeft + $valWidth), $script:tmpY, $keyWidth, 100)
            $szK = $g.MeasureString($k, $fNorm, (New-Object System.Drawing.SizeF($keyWidth, 10000)), $sfRightRtl)
            $vSf = if ($ltr) { $sfLeftLtr } else { $sfLeftRtl }
            $szV = $g.MeasureString($v, $fNorm, (New-Object System.Drawing.SizeF($valWidth, 10000)), $vSf)
            $rowH = [Math]::Max($szK.Height, $szV.Height)
            $valRect.Height = $rowH
            $keyRect.Height = $rowH
            if ($isDraw) {
                $g.DrawString($k, $fNorm, $brush, $keyRect, $sfRightRtl)
                $g.DrawString($v, $fNorm, $brush, $valRect, $vSf)
            }
            $script:tmpY += $rowH + 1
        }

        if (-not $hasBarcode) { & $DrawKV "رقم الإيصال:" ([string]$p.receipt_no) $true }
        & $DrawKV "التاريخ والوقت:" ("$([string]$p.date) $([string]$p.time)") $false
        $deviceText = if ($null -ne $p.device_name) { [string]$p.device_name } else { [string]$p.device_code }
        & $DrawKV "الجهاز:" $deviceText $false
        & $DrawKV "الكاشير:" ([string]$p.cashier_name) $false
        if ([string]$p.customer_name -ne "") {
            & $DrawKV "العميل:" ([string]$p.customer_name) $false
        }
        $y = $script:tmpY

        # خط مفرد
        if ($isDraw) {
            $g.DrawLine($penSolid, $script:PrintableLeft, $y, $script:PrintableRight, $y)
        }
        $y += 3

        # ترويسة الأصناف
        # نسب الصفحة من ٢٧٢ بكسل جوه عرض الطباعة الآمن، عشان حافة الطابعة ماتقصّش الكلام.
        $widths = @((132.0 / 272 * $script:PrintWidth), (32.0 / 272 * $script:PrintWidth), (50.0 / 272 * $script:PrintWidth), (58.0 / 272 * $script:PrintWidth))
        $headers = @("الصنف", "الكمية", "السعر", "الإجمالي")
        $script:tmpY = $y
        $DrawCells = {
            param($texts, [bool]$isHeader, [string]$code = "")
            $cells = @()
            $rowH = 0.0
            $nameH = 0.0
            $codeH = 0.0
            $right = [float]$script:PrintableRight
            for ($i = 0; $i -lt 4; $i++) {
                $right -= $widths[$i]
                $font = if ($isHeader) { $fHeader } elseif ($i -eq 0) { $fBold } else { $fNum }
                $format = if ($i -eq 0) { $sfCellRtl } elseif ($isHeader) { $sfCellCenter } else { $sfCellLtr }
                # القياس والرسم بنفس عرض الخانة عشان الاسم والأرقام مايدخلوش على اللي جنبهم.
                $cellWidth = [float]($widths[$i] - 2)
                $szCell = $g.MeasureString([string]$texts[$i], $font, (New-Object System.Drawing.SizeF($cellWidth, 10000)), $format)
                if (-not $isHeader -and $i -eq 0) {
                    # سطرين للاسم بالكتير، والسطر اللي بعدهم للكود كامل.
                    $lineH = $fBold.GetHeight($g) + 2
                    $nameH = [Math]::Min(($szCell.Height + 2), (2 * $lineH))
                    $codeH = $fCode.GetHeight($g) + 1
                    $rowH = [Math]::Max($rowH, $nameH + $codeH)
                } else {
                    $rowH = [Math]::Max($rowH, $szCell.Height)
                }
                $cells += @{ X = $right + 1; Width = $cellWidth; Font = $font; Format = $format }
            }
            if ($isDraw) {
                for ($i = 0; $i -lt 4; $i++) {
                    $cell = $cells[$i]
                    if (-not $isHeader -and $i -eq 0) {
                        $nameRect = New-Object System.Drawing.RectangleF($cell.X, $script:tmpY, $cell.Width, $nameH)
                        $codeRect = New-Object System.Drawing.RectangleF($cell.X, ($script:tmpY + $nameH + 1), $cell.Width, ($codeH - 1))
                        $g.DrawString([string]$texts[$i], $fBold, $brush, $nameRect, $sfCellName)
                        $g.DrawString($code, $fCode, $brush, $codeRect, $sfCellCode)
                    } else {
                        $rect = New-Object System.Drawing.RectangleF($cell.X, $script:tmpY, $cell.Width, $rowH)
                        $g.DrawString([string]$texts[$i], $cell.Font, $brush, $rect, $cell.Format)
                    }
                }
            }
            $script:tmpY += $rowH + 3
        }
        & $DrawCells $headers $true
        $y = $script:tmpY
        if ($isDraw) { $g.DrawLine($penSolid, $script:PrintableLeft, $y, $script:PrintableRight, $y) }
        $y += 2

        # خط منقط
        # السطور
        foreach ($line in $p.lines) {
            $script:tmpY = $y
            $texts = @([string]$line.name, ([double]$line.qty).ToString("0.##", [Globalization.CultureInfo]::InvariantCulture), ([double]$line.price).ToString("N2", [Globalization.CultureInfo]::InvariantCulture), ([double]$line.net).ToString("N2", [Globalization.CultureInfo]::InvariantCulture))
            & $DrawCells $texts $false ([string]$line.code)
            $y = $script:tmpY
            if ([double]$line.discount -gt 0) {
                $discountText = "خصم السطر: " + ([double]$line.discount).ToString("N2", [Globalization.CultureInfo]::InvariantCulture)
                $szDisc = $g.MeasureString($discountText, $fNote, [int]$script:PrintWidth, $sfRightRtl)
                $rect = New-Object System.Drawing.RectangleF($script:PrintableLeft, $y, $script:PrintWidth, $szDisc.Height)
                if ($isDraw) { $g.DrawString($discountText, $fNote, $brush, $rect, $sfRightRtl) }
                $y += $szDisc.Height + 1
            }
            if ($isDraw) { $g.DrawLine($penDash, $script:PrintableLeft, $y, $script:PrintableRight, $y) }
            $y += 2
        }

        # خط مزدوج
        if ($isDraw) {
            $g.DrawLine($penSolid, $script:PrintableLeft, $y, $script:PrintableRight, $y)
            $g.DrawLine($penSolid, $script:PrintableLeft, ($y + 3), $script:PrintableRight, ($y + 3))
        }
        $y += 5

        # الإجماليات
        $script:tmpY = $y
        $DrawTotal = {
            param($k, $v, [bool]$isBig = $false)
            $font = if ($isBig) { $fBig } else { $fNorm }
            $valWidth = $script:PrintWidth * 0.55
            $keyWidth = $script:PrintWidth - $valWidth
            $valRect = New-Object System.Drawing.RectangleF($script:PrintableLeft, $script:tmpY, $valWidth, 30)
            $keyRect = New-Object System.Drawing.RectangleF(($script:PrintableLeft + $valWidth), $script:tmpY, $keyWidth, 30)
            $sz = $g.MeasureString($k, $font, (New-Object System.Drawing.SizeF($keyWidth, 10000)), $sfRightRtl)
            $szValue = $g.MeasureString($v, $font, (New-Object System.Drawing.SizeF($valWidth, 10000)), $sfLeftLtr)
            $rowH = [Math]::Max($sz.Height, $szValue.Height)
            $valRect.Height = $rowH
            $keyRect.Height = $rowH
            if ($isDraw) {
                $g.DrawString($k, $font, $brush, $keyRect, $sfRightRtl)
                $g.DrawString($v, $font, $brush, $valRect, $sfLeftLtr)
            }
            $script:tmpY += $rowH + 1
        }

        & $DrawTotal "الإجمالي:" (([double]$p.gross).ToString("N2", [Globalization.CultureInfo]::InvariantCulture)) $false
        if ([double]$p.discount -gt 0) {
            & $DrawTotal "الخصم:" (([double]$p.discount).ToString("N2", [Globalization.CultureInfo]::InvariantCulture)) $false
        }
        if ($isDraw) { $g.DrawLine($penSolid, $script:PrintableLeft, $script:tmpY, $script:PrintableRight, $script:tmpY) }
        $script:tmpY += 3
        & $DrawTotal "الصافي:" ("$(([double]$p.net).ToString('N2', [Globalization.CultureInfo]::InvariantCulture)) ج.م") $true
        $y = $script:tmpY

        # خط مفرد
        if ($isDraw) {
            $g.DrawLine($penSolid, $script:PrintableLeft, $y, $script:PrintableRight, $y)
        }
        $y += 3

        # الدفعات والباقي
        $script:tmpY = $y
        foreach ($pay in $p.payments) {
            $mLabel = if ([string]$pay.name -ne "") { [string]$pay.name } else { [string]$pay.method }
            & $DrawTotal "دفع ${mLabel}:" (([double]$pay.amount).ToString("N2", [Globalization.CultureInfo]::InvariantCulture)) $false
        }
        & $DrawTotal "المدفوع:" (([double]$p.paid).ToString("N2", [Globalization.CultureInfo]::InvariantCulture)) $false
        & $DrawTotal "الباقي:" (([double]$p.change_due).ToString("N2", [Globalization.CultureInfo]::InvariantCulture)) $false
        $y = $script:tmpY

        if ($hasBarcode) {
            if ($isDraw) { $g.DrawLine($penDash, $script:PrintableLeft, $y, $script:PrintableRight, $y) }
            $y += 3
            # الموديول عدد صحيح من نقط الطابعة، والفراغ الهادي ١٠ موديولات زي مكتبة الموقع.
            $dot = 100.0 / $g.DpiX
            $moduleDots = [Math]::Floor($script:PrintWidth / (($barcodeBits.Length + 20) * $dot))
            if ($moduleDots -lt 1) { throw "عرض الطباعة مش كفاية لباركود الإيصال" }
            $module = $moduleDots * $dot
            $barcodeWidth = ($barcodeBits.Length + 20) * $module
            $startX = [Math]::Round(($script:PrintableLeft + ($script:PrintWidth - $barcodeWidth) / 2) / $dot) * $dot + 10 * $module
            $barY = [Math]::Round($y / (100.0 / $g.DpiY)) * (100.0 / $g.DpiY)
            $barHeight = 10.0 / 25.4 * 100
            if ($isDraw) {
                $saved = $g.Save()
                try {
                    $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::None
                    $g.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::None
                    for ($i = 0; $i -lt $barcodeBits.Length; $i++) {
                        if ($barcodeBits[$i] -eq '1') { $g.FillRectangle($brush, [float]($startX + $i * $module), [float]$barY, [float]$module, [float]$barHeight) }
                    }
                } finally { $g.Restore($saved) }
            }
            $y = $barY + $barHeight + 1
            $szNumber = $g.MeasureString([string]$p.receipt_no, $fNote, [int]$script:PrintWidth, $sfCenterLtr)
            $rect = New-Object System.Drawing.RectangleF($script:PrintableLeft, $y, $script:PrintWidth, $szNumber.Height)
            if ($isDraw) { $g.DrawString([string]$p.receipt_no, $fNote, $brush, $rect, $sfCenterLtr) }
            $y += $szNumber.Height + 2
        }

        # خط منقط
        if ($isDraw) {
            $g.DrawLine($penDash, $script:PrintableLeft, $y, $script:PrintableRight, $y)
        }
        $y += 3

        # نفس شرط وتاريخ سياسة الاستبدال اللي في صفحة المتصفح.
        if (-not $isReturn -and [int]$p.return_days -gt 0 -and [string]$p.return_until -ne "") {
            $until = [datetime]::ParseExact([string]$p.return_until, 'yyyy-MM-dd', [Globalization.CultureInfo]::InvariantCulture)
            foreach ($policyText in @("الاستبدال والاسترجاع خلال $($p.return_days) يوم بأصل الإيصال", ("حتى يوم " + $until.ToString('dd/MM/yyyy')))) {
                $szPolicy = $g.MeasureString($policyText, $fNote, [int]$script:PrintWidth, $sfCenterRtl)
                $rect = New-Object System.Drawing.RectangleF($script:PrintableLeft, $y, $script:PrintWidth, $szPolicy.Height)
                if ($isDraw) { $g.DrawString($policyText, $fNote, $brush, $rect, $sfCenterRtl) }
                $y += $szPolicy.Height + 1
            }
        }

        # سطر الختام
        $noteTxt = "شكراً لتعاملكم معنا"
        $rect = New-Object System.Drawing.RectangleF($script:PrintableLeft, $y, $script:PrintWidth, 1000)
        $sz = $g.MeasureString($noteTxt, $fNote, [int]$script:PrintWidth, $sfCenterRtl)
        $rect.Height = $sz.Height
        if ($isDraw) { $g.DrawString($noteTxt, $fNote, $brush, $rect, $sfCenterRtl) }
        $y += $sz.Height + 2

        # مسافة قبل القص
        $y += $script:ReceiptBottomFeed
        return $y

    } finally {
        $fH1.Dispose(); $fH2.Dispose(); $fBig.Dispose(); $fBold.Dispose(); $fNorm.Dispose(); $fNote.Dispose(); $fStamp.Dispose()
        $penSolid.Dispose(); $penDash.Dispose()
        $sfCenterRtl.Dispose(); $sfRightRtl.Dispose(); $sfLeftLtr.Dispose(); $sfLeftRtl.Dispose(); $sfCenterLtr.Dispose(); $fCode.Dispose(); $fNum.Dispose(); $fHeader.Dispose(); $sfCellRtl.Dispose(); $sfCellCenter.Dispose(); $sfCellLtr.Dispose(); $sfCellName.Dispose(); $sfCellCode.Dispose()
    }
}

function Print-SaleReceipt($payload, [string]$targetPrinter, [string]$pdfFile) {
    if (-not $payload) {
        throw "بيانات الإيصال غير صالحة"
    }

    $doc = New-Object System.Drawing.Printing.PrintDocument
    $doc.PrinterSettings.PrinterName = $targetPrinter

    $isPdf = ($targetPrinter -like "*PDF*" -or $pdfFile -ne "")
    if (-not $isPdf -and -not $doc.PrinterSettings.IsValid) {
        $doc.Dispose()
        throw "الطابعة '$targetPrinter' غير معرّفة أو غير متصلة بالنظام"
    }

    try {
        $measG = $doc.PrinterSettings.CreateMeasurementGraphics()
        $h = Render-SaleReceipt $measG $payload $false
        $measG.Dispose()

        $paperHeight = [int][Math]::Ceiling($h)
        $doc.DefaultPageSettings.PaperSize = New-Object System.Drawing.Printing.PaperSize("Receipt", $script:PaperWidth, $paperHeight)

        if ($pdfFile -ne "") {
            $doc.PrinterSettings.PrintToFile = $true
            $doc.PrinterSettings.PrintFileName = $pdfFile
        }

        $script:currentSalePayload = $payload
        $doc.add_PrintPage({
            param($sender, $e)
            Render-SaleReceipt $e.Graphics $script:currentSalePayload $true | Out-Null
            $e.HasMorePages = $false
        })

        $doc.Print()
    } finally {
        $doc.Dispose()
    }
}

# رسم الليبلز مستقل عن الإيصالات: الورقة بالمية من البوصة والرسم بالمم.
function Print-Labels($payload, [string]$printer) {
    $ErrorActionPreference = 'Stop'
    $doc = New-Object System.Drawing.Printing.PrintDocument
    $handler = $null
    try {
        $doc.PrinterSettings.PrinterName = $printer
        if (-not $doc.PrinterSettings.IsValid) { throw "طابعة الليبلز مش موجودة: $printer" }
        $w = [double]$payload.w_mm
        $h = [double]$payload.h_mm
        $cols = [int]$payload.cols
        $rows = [int]$payload.rows
        $gap = [double]$payload.gap_mm
        $items = @($payload.labels)
        if ($w -le 0 -or $h -le 0 -or $cols -lt 1 -or $rows -lt 1 -or $items.Count -eq 0) {
            throw 'مقاسات الليبل مش مظبوطة أو القايمة فاضية'
        }
        $paperW = [int][Math]::Round(($w * $cols + $gap * ($cols - 1)) / 25.4 * 100)
        $paperH = [int][Math]::Round(($h * $rows) / 25.4 * 100)
        $doc.DefaultPageSettings.PaperSize = New-Object System.Drawing.Printing.PaperSize('Labels', $paperW, $paperH)
        $doc.DefaultPageSettings.Margins = New-Object System.Drawing.Printing.Margins(0, 0, 0, 0)
        $doc.DefaultPageSettings.Landscape = $false
        $doc.OriginAtMargins = $false
        $doc.PrintController = New-Object System.Drawing.Printing.StandardPrintController
        # كائن حالة عشان تقدم الصفحات يفضل موجود بين نداءات PrintPage.
        $state = @{ Index = 0; Error = $null }
        $handler = [System.Drawing.Printing.PrintPageEventHandler] {
            param($sender, $ev)
            try {
                $g = $ev.Graphics
                $g.PageUnit = [System.Drawing.GraphicsUnit]::Millimeter
                $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::None
                $g.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::Half
                $g.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::SingleBitPerPixelGridFit
                # GDI بيبدأ عند المساحة القابلة للطباعة؛ نشيل إزاحتها عشان إحداثياتنا تبقى من أول الورقة.
                $g.TranslateTransform(-$ev.PageSettings.HardMarginX * 25.4 / 100, -$ev.PageSettings.HardMarginY * 25.4 / 100)
                $g.TranslateTransform([single]$payload.offset_x_mm, [single]$payload.offset_y_mm)
                for ($slot = 0; $slot -lt ($rows * $cols) -and $state.Index -lt $items.Count; $slot++) {
                    $col = $slot % $cols
                    # المتصفح RTL: أول ليبل في الصف ناحية اليمين.
                    $x = ($cols - 1 - $col) * ($w + $gap)
                    $y = [Math]::Floor($slot / $cols) * $h
                    Draw-Label $g $items[$state.Index] $payload $x $y
                    $state.Index++
                }
                $ev.HasMorePages = $state.Index -lt $items.Count
            } catch {
                $state.Error = $_.Exception
                $ev.Cancel = $true
                $ev.HasMorePages = $false
            }
        }.GetNewClosure()
        $doc.add_PrintPage($handler)
        $doc.Print()
        if ($state.Error) { throw $state.Error }
    } finally {
        if ($handler) { $doc.remove_PrintPage($handler) }
        $doc.Dispose()
    }
}

function Draw-Label($g, $item, $layout, [double]$x, [double]$y) {
    $w = [double]$layout.w_mm
    $h = [double]$layout.h_mm
    $m = $layout.layout
    $pad = [double]$m.pad
    $avail = [Math]::Round($w - 2 * $pad, 2)
    $bits = [string]$item.bits
    $module = [double]$item.module_mm
    $barcodeW = ($bits.Length + 20) * $module
    if ($bits -notmatch '^[01]+$' -or $module -le 0 -or $barcodeW -gt ($avail + 0.001)) {
        throw 'باركود الليبل أو عرضه مش مظبوط'
    }
    $fonts = @{}
    $saved = $g.Save()
    try {
        foreach ($key in @('Shop', 'Name', 'Price', 'Code')) {
            $size = [single]$m.("fs$key")
            $fonts[$key] = [System.Drawing.Font]::new('Tahoma', $size, [System.Drawing.FontStyle]::Bold, [System.Drawing.GraphicsUnit]::Millimeter)
        }
        # ارتفاع الخط الفعلي ممكن يزيد عن الرقم الاسمي؛ نحجزله مساحة قبل البارات.
        $shopH = 0.0
        if ($layout.show_shop) { $shopH = [Math]::Max([double]$m.shopH, $fonts.Shop.GetHeight($g)) }
        $nameLines = 1
        if ($h -ge 16) { $nameLines = 2 }
        $nameH = [Math]::Max([double]$m.nameH, $fonts.Name.GetHeight($g) * $nameLines)
        $priceH = 0.0
        if ($layout.show_price) { $priceH = [Math]::Max([double]$m.priceH, $fonts.Price.GetHeight($g)) }
        $codeH = [Math]::Max([double]$m.codeH, $fonts.Code.GetHeight($g))
        $barH = [Math]::Min([double]$m.barH, $h - 2 * $pad - $shopH - $nameH - $priceH - $codeH - [double]$m.gap)
        if ($barH -lt 4.0) { throw 'ارتفاع الليبل مش كفاية للنص والباركود' }
        $parts = @()
        if ($layout.show_shop) { $parts += @{ Text = [string]$layout.shop_name; Font = $fonts.Shop; Height = $shopH; Rtl = $true } }
        $parts += @{ Text = [string]$item.name; Font = $fonts.Name; Height = $nameH; Rtl = $true }
        if ($layout.show_price) { $parts += @{ Text = [string]$item.price; Font = $fonts.Price; Height = $priceH; Rtl = $false } }
        $parts += @{ Barcode = $true; Height = $barH }
        $parts += @{ Text = [string]$item.code; Font = $fonts.Code; Height = $codeH; Rtl = $false }
        $g.SetClip([System.Drawing.RectangleF]::new($x, $y, $w, $h))
        $top = $y + $pad
        foreach ($part in $parts) {
            if ($part.Barcode) {
                $top += [double]$m.gap
                $left = $x + ($w - $barcodeW) / 2 + 10 * $module
                for ($b = 0; $b -lt $bits.Length; $b++) {
                    if ($bits[$b] -eq '1') {
                        $g.FillRectangle([System.Drawing.Brushes]::Black, [single]($left + $b * $module), [single]$top, [single]$module, [single]$barH)
                    }
                }
            } else {
                $format = [System.Drawing.StringFormat]::new()
                try {
                    $format.Alignment = [System.Drawing.StringAlignment]::Center
                    $format.LineAlignment = [System.Drawing.StringAlignment]::Center
                    $format.Trimming = [System.Drawing.StringTrimming]::EllipsisCharacter
                    if ($part.Rtl) { $format.FormatFlags = [System.Drawing.StringFormatFlags]::DirectionRightToLeft }
                    if ($h -lt 16 -or $part.Text -ne [string]$item.name) {
                        $format.FormatFlags = $format.FormatFlags -bor [System.Drawing.StringFormatFlags]::NoWrap
                    }
                    $rect = [System.Drawing.RectangleF]::new(($x + $pad), $top, $avail, $part.Height)
                    $g.DrawString($part.Text, $part.Font, [System.Drawing.Brushes]::Black, $rect, $format)
                } finally { $format.Dispose() }
            }
            $top += $part.Height
        }
    } finally {
        foreach ($font in $fonts.Values) { $font.Dispose() }
        $g.Restore($saved)
    }
}

# =====================================================================
#  وضع الاختبار المحلي (-TestFile)
# =====================================================================
if ($TestFile -ne "") {
    Write-Host ""
    Write-Host "=== Fagala Printer Agent [TEST MODE] ===" -ForegroundColor Cyan
    Write-Host "Reading test payload from: $TestFile" -ForegroundColor Gray

    if (-not (Test-Path $TestFile)) {
        Write-Host "ERROR: File not found: $TestFile" -ForegroundColor Red
        exit 1
    }

    $rawText = [System.IO.File]::ReadAllText($TestFile, [System.Text.Encoding]::UTF8)
    $payloadData = $rawText | ConvertFrom-Json

    $actualPrinter = $PrinterName
    $pdfTarget = $OutPdf

    # لو بنختبر على جهاز تطوير والطابعة مش موجودة، نحوّل لـ PDF تلقائي
    $chkDoc = New-Object System.Drawing.Printing.PrintDocument
    $chkDoc.PrinterSettings.PrinterName = $PrinterName
    if (-not $chkDoc.PrinterSettings.IsValid -and $Kinds -ne 'labels' -and $payloadData.kind -ne 'labels') {
        $actualPrinter = "Microsoft Print to PDF"
        if ($pdfTarget -eq "") {
            $pdfTarget = Join-Path $PSScriptRoot "test_receipt.pdf"
        }
        Write-Host "Notice: Printer '$PrinterName' not found locally. Using '$actualPrinter'." -ForegroundColor Yellow
        Write-Host "Output PDF will be saved to: $pdfTarget" -ForegroundColor Yellow
    }
    $chkDoc.Dispose()

    try {
        if ($payloadData.kind -eq 'labels') {
            Print-Labels $payloadData $actualPrinter
        } elseif ($payloadData.kind -eq 'sale_receipt' -or $payloadData.receipt_no) {
            Print-SaleReceipt $payloadData $actualPrinter $pdfTarget
        } else {
            Print-Receipt $payloadData $actualPrinter $pdfTarget
        }
        Write-Host "SUCCESS: Receipt printed successfully." -ForegroundColor Green
        if ($pdfTarget -ne "" -and (Test-Path $pdfTarget)) {
            Write-Host "PDF size: $((Get-Item $pdfTarget).Length) bytes" -ForegroundColor Green
        }
        exit 0
    } catch {
        Write-Host "PRINT ERROR: $($_.Exception.Message)" -ForegroundColor Red
        exit 1
    }
}

# =====================================================================
#  الوضع الطبيعي — تشغيل الوكيل وسؤال السيرفر
# =====================================================================

# منع تشغيل نسختين في نفس الوقت
$me = $PID
$twin = Get-CimInstance Win32_Process -Filter "Name='powershell.exe'" |
        Where-Object { $_.ProcessId -ne $me -and ($_.CommandLine -like ('*"' + $PSCommandPath + '"*') -or $_.CommandLine -like ('*-File ' + $PSCommandPath)) }
if ($twin) { exit }

# فحص المفتاح والفرع (الباب مقفول لو لسه مش مظبوطين)
if ([string]::IsNullOrWhiteSpace($Key) -or ($BranchId -le 0 -and $Kinds -ne 'labels')) {
    Write-Host ""
    Write-Host "  ! تحذير: المفتاح أو رقم الفرع غير مضبوط في السكربت." -ForegroundColor Yellow
    Write-Host "  عدّل `$Key و `$BranchId قبل تشغيل الوكيل على جهاز الفرع." -ForegroundColor Gray
    Write-Host ""
    Log "stopped: Key or BranchId is missing"
    exit 1
}

Write-Host ""
Write-Host "  Fagala Thermal Printer Agent" -ForegroundColor Cyan
Write-Host "  Session: $Session | Branch: $BranchId | Printer: $PrinterName" -ForegroundColor Cyan
Write-Host "  Waiting for print jobs... (Ctrl+C to stop)" -ForegroundColor Gray
Write-Host ""
Log "=== started (session $Session, branch $BranchId, host $env:COMPUTERNAME, printer $PrinterName) ==="

$lastPrint = [datetime]::MinValue
$backoff   = 0
$doneList  = @()
$failList  = @()
$lastErr   = ""
$errShown  = $false

while ($true) {
    $sleepMs = $IdleMs

    try {
        $doneStr = ($doneList -join ',')
        $failStr = ($failList -join ',')

        $body = @{
            key     = $Key
            session = $Session
            branch  = $BranchId
            host    = $env:COMPUTERNAME
            printer = $PrinterName
            kinds   = $Kinds
            done    = $doneStr
            failed  = $failStr
            error   = $lastErr
        }

        # تفريغ القوائم بعد تجهيز الإرسال
        $doneList = @()
        $failList = @()
        $lastErr  = ""

        $r = Invoke-RestMethod -Uri "$BaseUrl/print_pull.php" -Method Post -Body $body -TimeoutSec 15

        if ($backoff -gt 0) {
            Write-Host "  * Connection restored." -ForegroundColor Green
            Log "connection restored"
            $backoff = 0
        }
        $errShown = $false

        if ($r.ok -and $r.jobs) {
            foreach ($job in $r.jobs) {
                $jobId = [int]$job.id
                Write-Host ("  " + (Get-Date -Format "HH:mm:ss") + " -> New print job #$jobId ($($job.kind))") -ForegroundColor Cyan
                Log "job #$jobId received ($($job.kind))"

                try {
                    if ($job.kind -eq 'labels') {
                        if ($Kinds -ne 'labels') { throw 'وكيل الإيصالات ماينفعش يطبع ليبلز' }
                        Print-Labels $job.payload $PrinterName
                    } elseif ($Kinds -eq 'labels') {
                        throw 'وكيل الليبلز ماينفعش يطبع إيصالات'
                    } elseif ($job.kind -eq 'sale_receipt') {
                        Print-SaleReceipt $job.payload $PrinterName ""
                    } else {
                        Print-Receipt $job.payload $PrinterName ""
                    }
                    $doneList += $jobId
                    $lastPrint = Get-Date
                    Write-Host ("    ✓ Printed successfully: #$jobId") -ForegroundColor Green
                    Log "job #$jobId printed successfully"
                } catch {
                    $failMsg = $_.Exception.Message
                    $failList += $jobId
                    $lastErr = $failMsg
                    Write-Host ("    ✗ Print failed: #$jobId - $failMsg") -ForegroundColor Red
                    Log "job #$jobId print failed: $failMsg"
                }
            }
        }

        # تسريع فترة الانتظار لو لسه طابعين قريب (أقل من دقيقة)
        $isHot = (((Get-Date) - $lastPrint).TotalMilliseconds -lt $HotMs)
        if ($isHot) {
            $sleepMs = $FastMs
        } else {
            $sleepMs = $IdleMs
        }

    } catch {
        # تراجع تصاعدي لو السيرفر مش بيرد أو فيه مشكلة شبكة
        if ($backoff -eq 0) { $backoff = $BackMin }
        else { $backoff = [Math]::Min($backoff * 2, $BackMax) }

        if (-not $errShown) {
            Write-Host ("  ! Connection problem: " + $_.Exception.Message) -ForegroundColor Yellow
            Write-Host "  (Backing off — retry in $([int]($backoff / 1000))s...)" -ForegroundColor Gray
            Log "connection problem: " + $_.Exception.Message
            $errShown = $true
        } else {
            Write-Host ("    retry in " + [int]($backoff / 1000) + "s") -ForegroundColor DarkGray
        }

        $sleepMs = $backoff
    }

    Start-Sleep -Milliseconds $sleepMs
}

