<?php
/**
 * static_audit.php — الفحص الثابت الشامل لكود site/ (بريف ١٨٢ — الجزء ج)
 * نظام الفجالة ستور المالي
 *
 * ينفذ الفحوصات الـ ١١ المحددة بالبريف والتي لا تغطيها فحوصات tests/check_*
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$siteDir = realpath(__DIR__ . '/../../site');
if (!$siteDir || !is_dir($siteDir)) {
    $siteDir = 'D:/fagala_wt_ds2/site';
}

$outDir = $argv[1] ?? (__DIR__ . '/out/latest');
if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}

echo "=========================================================\n";
echo "   DEEP AUDIT — PART C: STATIC CODE AUDIT (Brief 182)\n";
echo "   Target Site: $siteDir\n";
echo "=========================================================\n\n";

$findings = [];
$allPhpFiles = glob("$siteDir/*.php");

// -------------------------------------------------------------
// ١. استعلام جوه حلقة: foreach / while / for
// -------------------------------------------------------------
echo "[1/11] فحص الاستعلامات داخل الحلقات (Queries in loops)...\n";
$loopCount = 0;
foreach ($allPhpFiles as $f) {
    $code = file_get_contents($f);
    $rel = 'site/' . basename($f);
    $tokens = token_get_all($code);
    $inLoop = false;
    $loopDepth = 0;
    $loopType = '';
    $loopLine = 0;

    for ($i = 0; $i < count($tokens); $i++) {
        $tok = $tokens[$i];
        if (is_array($tok)) {
            $tId = $tok[0];
            $tVal = $tok[1];
            $tLine = $tok[2];

            if (in_array($tId, [T_FOREACH, T_WHILE, T_FOR], true)) {
                $inLoop = true;
                $loopType = $tVal;
                $loopLine = $tLine;
            } elseif ($inLoop) {
                // فحص مناداة استعلامات
                if ($tId === T_OBJECT_OPERATOR && isset($tokens[$i + 1]) && is_array($tokens[$i + 1])) {
                    $nextVal = $tokens[$i + 1][1];
                    if (in_array($nextVal, ['query', 'prepare', 'exec'], true)) {
                        $loopCount++;
                        $findings[] = [
                            'severity' => 'orange',
                            'kind'     => 'query_in_loop',
                            'file'     => $rel,
                            'line'     => $tLine,
                            'user'     => 'static',
                            'url'      => basename($f),
                            'detail'   => "استعلام ($nextVal) داخل حلقة $loopType التي بدأت في سطر $loopLine",
                        ];
                    }
                }
            }
        } else {
            if ($tok === '{') {
                if ($inLoop) $loopDepth++;
            } elseif ($tok === '}') {
                if ($inLoop) {
                    $loopDepth--;
                    if ($loopDepth <= 0) {
                        $inLoop = false;
                        $loopDepth = 0;
                    }
                }
            }
        }
    }
}
echo "  - تم رصد $loopCount استعلام داخل حلقات.\n\n";

// -------------------------------------------------------------
// ٢. SQL فيه مدخلات مستخدم مدمجة نصياً بدل الـ placeholders (?)
// -------------------------------------------------------------
echo "[2/11] فحص دمج مدخلات المستخدم في نصوص SQL...\n";
$sqlConcatCount = 0;
foreach ($allPhpFiles as $f) {
    $code = file_get_contents($f);
    $rel = 'site/' . basename($f);
    $lines = explode("\n", $code);

    foreach ($lines as $lnIdx => $line) {
        $lineNum = $lnIdx + 1;
        // البحث عن أنماط SQL
        if (preg_match('/\b(SELECT|UPDATE|INSERT|DELETE)\b/i', $line)) {
            if (preg_match('/\$_(GET|POST|REQUEST)\s*\[/i', $line) && (str_contains($line, '.') || str_contains($line, '"') || str_contains($line, "'"))) {
                $sqlConcatCount++;
                $findings[] = [
                    'severity' => 'red',
                    'kind'     => 'sql_injection_risk',
                    'file'     => $rel,
                    'line'     => $lineNum,
                    'user'     => 'static',
                    'url'      => basename($f),
                    'detail'   => "دمج مباشر لـ \$_GET/\$_POST في جملة SQL بدلاً من استخدام Prepared Statements (?)",
                ];
            }
        }
    }
}
echo "  - تم رصد $sqlConcatCount جملة SQL تحتوي على دمج مباشر.\n\n";

// -------------------------------------------------------------
// ٣. طباعة echo / <?= لمدخلات مستخدم بدون e() / htmlspecialchars()
// -------------------------------------------------------------
echo "[3/11] فحص طباعة المدخلات بدون هروب (XSS check)...\n";
$xssCount = 0;
foreach ($allPhpFiles as $f) {
    $code = file_get_contents($f);
    $rel = 'site/' . basename($f);
    $lines = explode("\n", $code);

    foreach ($lines as $lnIdx => $line) {
        $lineNum = $lnIdx + 1;
        if (preg_match('/(<\?=\s*|echo\s+)\$_(GET|POST|REQUEST)\s*\[/i', $line)) {
            if (!preg_match('/\b(e|htmlspecialchars|htmlentities|urlencode|rawurlencode|intval|\(int\)|\(float\))\s*\(/i', $line)) {
                $xssCount++;
                $findings[] = [
                    'severity' => 'orange',
                    'kind'     => 'xss_raw_output',
                    'file'     => $rel,
                    'line'     => $lineNum,
                    'user'     => 'static',
                    'url'      => basename($f),
                    'detail'   => "طباعة مباشرة لمدخلات المستخدم بدون دالة هروب e() أو htmlspecialchars()",
                ];
            }
        }
    }
}
echo "  - تم رصد $xssCount طباعة بدون هروب.\n\n";

// -------------------------------------------------------------
// ٤. معالجات POST بدون فحص CSRF أو بدون فحص صلاحيات
// -------------------------------------------------------------
echo "[4/11] فحص معالجات POST بدون فحص CSRF أو صلاحيات...\n";
$csrfCount = 0;
foreach ($allPhpFiles as $f) {
    $base = basename($f);
    if (str_ends_with($base, '_lib.php') || $base === 'config.php') {
        continue;
    }
    $code = file_get_contents($f);
    $rel = "site/$base";

    // التحقق من وجود معالجة POST
    if (preg_match('/\b\$_SERVER\s*\[\s*[\'"]REQUEST_METHOD[\'"]\s*\]\s*===?\s*[\'"]POST[\'"]/i', $code, $m, PREG_OFFSET_CAPTURE) ||
        preg_match('/\bif\s*\(\s*\$_POST\b/i', $code, $m, PREG_OFFSET_CAPTURE)) {

        $offset = $m[0][1];
        $preCode = substr($code, 0, $offset);
        $postBlock = substr($code, $offset, 3500);

        $hasCsrf = preg_match('/\b(csrf|check_csrf|validate_csrf|csrf_verify|api_csrf)\b/i', $postBlock) ||
                   preg_match('/\b(csrf|check_csrf|validate_csrf|csrf_verify|api_csrf)\b/i', $preCode);

        $hasPerm = preg_match('/\b(require_permission|require_module_access|can|is_owner|br_inv_require|api_begin)\b/i', $preCode) ||
                   preg_match('/\b(require_permission|require_module_access|can|is_owner|br_inv_require|api_begin)\b/i', $postBlock);

        if (!$hasCsrf || !$hasPerm) {
            $csrfCount++;
            $line = substr_count($preCode, "\n") + 1;
            $missing = [];
            if (!$hasCsrf) $missing[] = 'CSRF';
            if (!$hasPerm) $missing[] = 'صلاحيات';
            $findings[] = [
                'severity' => 'red',
                'kind'     => 'post_no_csrf_no_perm',
                'file'     => $rel,
                'line'     => $line,
                'user'     => 'static',
                'url'      => $base,
                'detail'   => "معالج POST يفتقر لـ: " . implode(' و', $missing),
            ];
        }
    }
}
echo "  - تم رصد $csrfCount معالج POST يفتقر لفحص CSRF أو الصلاحيات.\n\n";

// -------------------------------------------------------------
// ٥. دالة في _lib.php فيها SELECT * أو e.* / t.* تُستدعى من _api.php
// -------------------------------------------------------------
echo "[5/11] فحص الدوال ذات الـ SELECT * في _lib.php المستدعاة من _api.php...\n";
$wildcardCount = 0;
$libFiles = glob("$siteDir/*_lib.php");
$apiFiles = glob("$siteDir/*_api.php");

$apiCodeMerged = '';
foreach ($apiFiles as $af) {
    $apiCodeMerged .= file_get_contents($af) . "\n";
}

foreach ($libFiles as $lf) {
    $rel = 'site/' . basename($lf);
    $code = file_get_contents($lf);

    // استخراج الدوال
    preg_match_all('/function\s+([a-zA-Z0-9_]+)\s*\([^)]*\)\s*(\:\s*[^{]+)?\s*\{/i', $code, $fnMatches, PREG_OFFSET_CAPTURE);
    for ($idx = 0; $idx < count($fnMatches[1]); $idx++) {
        $fnName = $fnMatches[1][$idx][0];
        $fnOffset = $fnMatches[0][$idx][1];
        $fnLine = substr_count(substr($code, 0, $fnOffset), "\n") + 1;

        // مقتطف جسم الدالة
        $fnBody = substr($code, $fnOffset, 12000);

        if (preg_match('/\bSELECT\s+(?:\*|[a-zA-Z0-9_]+\.\*)(?=[\s,])/i', $fnBody)) {
            // هل تُستدعى من أي API؟
            if (preg_match('/\b' . preg_quote($fnName, '/') . '\s*\(/', $apiCodeMerged)) {
                $wildcardCount++;
                $findings[] = [
                    'severity' => 'orange',
                    'kind'     => 'wildcard_select_in_api_lib',
                    'file'     => $rel,
                    'line'     => $fnLine,
                    'user'     => 'static',
                    'url'      => basename($lf),
                    'detail'   => "دالة ($fnName) تحتوي على SELECT * أو جدول.* وتُستدعى من واجهة API",
                ];
            }
        }
    }
}
echo "  - تم رصد $wildcardCount دالة بـ SELECT * تُستدعى من APIs.\n\n";

// -------------------------------------------------------------
// ٦. ألوان ثابتة #hex أو rgb( في style= أو <style>
// -------------------------------------------------------------
echo "[6/11] فحص الألوان الثابتة (#hex / rgb) في الـ inline style...\n";
$colorCount = 0;
foreach ($allPhpFiles as $f) {
    $rel = 'site/' . basename($f);
    $code = file_get_contents($f);
    $lines = explode("\n", $code);

    foreach ($lines as $lnIdx => $line) {
        $lineNum = $lnIdx + 1;
        if (preg_match('/style\s*=\s*["\'][^"\']*(#[0-9a-fA-F]{3,6}|rgba?\s*\([^)]+\))[^"\']*["\']/i', $line)) {
            $colorCount++;
            if ($colorCount <= 40) { // نسجل أول 40 نموذج لتجنب إغراق التقرير
                $findings[] = [
                    'severity' => 'yellow',
                    'kind'     => 'hardcoded_style_color',
                    'file'     => $rel,
                    'line'     => $lineNum,
                    'user'     => 'static',
                    'url'      => basename($f),
                    'detail'   => "لون ثابت مدمج في inline style بدلاً من متغيرات fagala.css",
                ];
            }
        }
    }
}
echo "  - تم رصد $colorCount لون ثابت في الـ style.\n\n";

// -------------------------------------------------------------
// ٧. تعريف .btn { خارج fagala.css
// -------------------------------------------------------------
echo "[7/11] فحص إعادة تعريف .btn خارج fagala.css...\n";
$btnCount = 0;
$cssAndPhp = array_merge($allPhpFiles, glob("$siteDir/*.css"), glob("$siteDir/css/*.css"));
foreach ($cssAndPhp as $f) {
    $base = basename($f);
    if ($base === 'fagala.css' || str_contains($base, 'tabler') || str_contains($base, 'vendor')) {
        continue;
    }
    $code = file_get_contents($f);
    if (preg_match('/(^|\s|\})[.]btn\s*\{/i', $code, $m, PREG_OFFSET_CAPTURE)) {
        $btnCount++;
        $line = substr_count(substr($code, 0, $m[0][1]), "\n") + 1;
        $findings[] = [
            'severity' => 'yellow',
            'kind'     => 'btn_override_outside_css',
            'file'     => 'site/' . basename($f),
            'line'     => $line,
            'user'     => 'static',
            'url'      => basename($f),
            'detail'   => "إعادة تعريف لكلاس .btn { خارج ملف التصميم الأساسي fagala.css",
        ];
    }
}
echo "  - تم رصد $btnCount إعادة تعريف لـ .btn.\n\n";

// -------------------------------------------------------------
// ٨. استخدام var(--x) غير معرّف في fagala.css أو في ستايل الصفحة
// -------------------------------------------------------------
echo "[8/11] فحص استخدام متغيرات CSS غير معرّفة (var(--x))...\n";
$fagalaCssPath = "$siteDir/assets/fagala.css";
$definedVars = [];
if (file_exists($fagalaCssPath)) {
    preg_match_all('/(--[a-zA-Z0-9_-]+)\s*:/', file_get_contents($fagalaCssPath), $vm);
    $definedVars = array_fill_keys($vm[1] ?? [], true);
}

$undefVarCount = 0;
foreach ($allPhpFiles as $f) {
    $rel = 'site/' . basename($f);
    $code = file_get_contents($f);

    $pageVars = $definedVars;
    preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $code, $styleBlocks);
    foreach ($styleBlocks[1] as $sb) {
        preg_match_all('/(--[a-zA-Z0-9_-]+)\s*:/', $sb, $pvm);
        foreach ($pvm[1] as $pv) {
            $pageVars[$pv] = true;
        }
    }

    preg_match_all('/var\s*\(\s*(--[a-zA-Z0-9_-]+)\s*\)/i', $code, $m, PREG_OFFSET_CAPTURE);

    for ($i = 0; $i < count($m[1]); $i++) {
        $varName = $m[1][$i][0];
        $varOffset = $m[1][$i][1];
        if (!isset($pageVars[$varName])) {
            $undefVarCount++;
            $line = substr_count(substr($code, 0, $varOffset), "\n") + 1;
            $findings[] = [
                'severity' => 'yellow',
                'kind'     => 'undefined_css_var',
                'file'     => $rel,
                'line'     => $line,
                'user'     => 'static',
                'url'      => basename($f),
                'detail'   => "استخدام متغير CSS غير معرّف في fagala.css أو في الصفحة: $varName",
            ];
        }
    }
}
echo "  - تم رصد $undefVarCount استخدام لمتغيرات غير معرّفة.\n\n";

// -------------------------------------------------------------
// ٩. فحص ?v= في الروابط ومطابقتها لآخر تعديل في git
// -------------------------------------------------------------
echo "[9/11] فحص إصدارات الملفات (?v=) مقابل آخر تعديل git...\n";
$vMismatchCount = 0;
$gitCommitTimes = [];

foreach ($allPhpFiles as $f) {
    $rel = 'site/' . basename($f);
    $code = file_get_contents($f);
    preg_match_all('/(["\'])([a-zA-Z0-9_\-\.\/]+\.(?:css|js))\?v=(\d+)\1/i', $code, $vm, PREG_SET_ORDER);

    foreach ($vm as $vMatch) {
        $assetPath = $vMatch[2];
        $assetVer  = (int) $vMatch[3];

        if (!isset($gitCommitTimes[$assetPath])) {
            $cleanAsset = ltrim($assetPath, '/');
            $fullAsset = "$siteDir/$cleanAsset";
            if (file_exists($fullAsset)) {
                $time = exec("git -C " . escapeshellarg($siteDir) . " log -1 --format=%ct -- " . escapeshellarg($cleanAsset));
                $gitCommitTimes[$assetPath] = (int) $time;
            } else {
                $gitCommitTimes[$assetPath] = 0;
            }
        }

        $gitTime = $gitCommitTimes[$assetPath];
        if ($gitTime > 0 && abs($gitTime - $assetVer) > 3600) {
            $vMismatchCount++;
            $findings[] = [
                'severity' => 'yellow',
                'kind'     => 'asset_version_mismatch',
                'file'     => $rel,
                'line'     => 1,
                'user'     => 'static',
                'url'      => basename($f),
                'detail'   => "رقم إصدار المورد $assetPath (?v=$assetVer) يختلف عن تاريخ آخر تعديل في git ($gitTime)",
            ];
        }
    }
}
echo "  - تم رصد $vMismatchCount اختلاف في أرقام إصدار الموارد.\n\n";

// -------------------------------------------------------------
// ١٠. دوال مكرّرة بنفس الجسم في أكثر من ملف (باستثناء ashraqa/)
// -------------------------------------------------------------
echo "[10/11] فحص الدوال المكررة بنفس الجسم في أكثر من ملف...\n";
$dupFnCount = 0;
$functionsMap = [];

foreach ($allPhpFiles as $f) {
    $base = basename($f);
    if (str_starts_with($base, 'ashraqa_') || $base === 'finance.php') {
        continue;
    }
    $code = file_get_contents($f);
    preg_match_all('/function\s+([a-zA-Z0-9_]+)\s*\([^)]*\)\s*(?:\:\s*[^{]+)?\s*\{((?:[^{}]+|(?R))*)\}/s', $code, $fnm, PREG_SET_ORDER);

    foreach ($fnm as $item) {
        $fnName = $item[1];
        $fnBody = trim($item[2]);
        // تنظيف المسافات والتعليقات
        $normalizedBody = preg_replace('/\s+/', ' ', preg_replace('/\/\*.*?\*\/|\/\/.*?$/m', '', $fnBody));
        if (strlen($normalizedBody) > 40) {
            $hash = md5($normalizedBody);
            $functionsMap[$hash][] = ['name' => $fnName, 'file' => "site/$base"];
        }
    }
}

foreach ($functionsMap as $hash => $list) {
    if (count($list) > 1) {
        // التحقق من أنها في ملفات مختلفة وليست مجرد نفس الدالة
        $files = array_unique(array_column($list, 'file'));
        if (count($files) > 1) {
            $dupFnCount++;
            $findings[] = [
                'severity' => 'yellow',
                'kind'     => 'duplicate_function_body',
                'file'     => implode(', ', $files),
                'line'     => 1,
                'user'     => 'static',
                'url'      => $list[0]['file'],
                'detail'   => "دوال بنفس الجسم البرمجي مكررة في أكثر من ملف: " . implode(' و ', array_column($list, 'name')),
            ];
        }
    }
}
echo "  - تم رصد $dupFnCount دالة مكررة بنفس الجسم.\n\n";

// -------------------------------------------------------------
// ١١. ملفات PHP ميتة في site/ لا يوجد لها require أو رابط
// -------------------------------------------------------------
echo "[11/11] فحص ملفات PHP الميتة في site/...\n";
$deadFilesCount = 0;
$allCodeCombined = '';
foreach ($allPhpFiles as $f) {
    $allCodeCombined .= file_get_contents($f) . "\n";
}

foreach ($allPhpFiles as $f) {
    $base = basename($f);
    if (in_array($base, ['index.php', 'login.php', 'logout.php', 'dashboard.php', 'auth.php', 'config.php', 'finance.php'], true)) {
        continue;
    }
    // فحص ذكر الملف في بقية الكود
    $pattern = '/\b' . preg_quote($base, '/') . '\b/i';
    $matches = preg_match_all($pattern, $allCodeCombined);
    // إذا ذُكر مرة واحدة فقط (وهي داخل تعريفه هو شخصياً)
    if ($matches <= 1) {
        $deadFilesCount++;
        $findings[] = [
            'severity' => 'yellow',
            'kind'     => 'dead_php_file',
            'file'     => "site/$base",
            'line'     => 1,
            'user'     => 'static',
            'url'      => $base,
            'detail'   => "ملف PHP لا يتم عمل require أو include أو ربطه برابط من أي ملف آخر في site/",
        ];
    }
}
echo "  - تم رصد $deadFilesCount ملف قد يكون ميتاً/غير مستخدم.\n\n";

file_put_contents("$outDir/static_findings.json", json_encode($findings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo ">>> اكتمل الجزء ج بنجاح. النتائج محفوظة في: $outDir/static_findings.json <<<\n";
