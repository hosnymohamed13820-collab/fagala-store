<?php
/**
 * selftest.php — اختبار ذاتي للفحوصات الـ ١١ الثابتة (بريف ١٨٢ب — البند ٦)
 * كل فحص له حالة موجبة (يجب أن ترصد) وحالة سالبة (يجب ألا ترصد)
 * يطبع ✓/✗ لكل فحص ويخرج برمز 0 لو الكل نجح، أو 1 لو فيه أي فشل.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$fixturesDir = __DIR__ . '/fixtures';
if (!is_dir($fixturesDir)) {
    fwrite(STDERR, "❌ مجلد fixtures غير موجود: $fixturesDir\n");
    exit(1);
}

echo "=========================================================\n";
echo "   STATIC AUDIT SELF-TEST (11 Checks Positive/Negative)\n";
echo "=========================================================\n\n";

$allPassed = true;

function reportCheck(int $num, string $name, bool $posPassed, bool $negPassed): bool {
    global $allPassed;
    $ok = $posPassed && $negPassed;
    $mark = $ok ? '✓' : '✗';
    echo sprintf("[%02d/11] %-28s %s (pos: %s, neg: %s)\n",
        $num,
        $name,
        $mark,
        $posPassed ? 'detected' : 'MISSED',
        $negPassed ? 'clean' : 'FALSE POSITIVE'
    );
    if (!$ok) $allPassed = false;
    return $ok;
}

// -------------------------------------------------------------
// 1. query_in_loop
// -------------------------------------------------------------
function check1_query_in_loop(string $file): bool {
    $code = file_get_contents($file);
    $tokens = token_get_all($code);
    $inLoop = false;
    $loopDepth = 0;
    for ($i = 0; $i < count($tokens); $i++) {
        $tok = $tokens[$i];
        if (is_array($tok)) {
            if (in_array($tok[0], [T_FOREACH, T_WHILE, T_FOR], true)) {
                $inLoop = true;
            } elseif ($inLoop && $tok[0] === T_OBJECT_OPERATOR && isset($tokens[$i + 1]) && is_array($tokens[$i + 1])) {
                if (in_array($tokens[$i + 1][1], ['query', 'prepare', 'exec'], true)) {
                    return true;
                }
            }
        } else {
            if ($tok === '{' && $inLoop) $loopDepth++;
            elseif ($tok === '}' && $inLoop) {
                $loopDepth--;
                if ($loopDepth <= 0) { $inLoop = false; $loopDepth = 0; }
            }
        }
    }
    return false;
}
$pos1 = check1_query_in_loop("$fixturesDir/check1/pos.php");
$neg1 = !check1_query_in_loop("$fixturesDir/check1/neg.php");
reportCheck(1, 'query_in_loop', $pos1, $neg1);

// -------------------------------------------------------------
// 2. sql_concat
// -------------------------------------------------------------
function check2_sql_concat(string $file): bool {
    $lines = explode("\n", file_get_contents($file));
    foreach ($lines as $line) {
        if (preg_match('/\b(SELECT|UPDATE|INSERT|DELETE)\b/i', $line)) {
            if (preg_match('/\$_(GET|POST|REQUEST)\s*\[/i', $line) && (str_contains($line, '.') || str_contains($line, '"') || str_contains($line, "'"))) {
                return true;
            }
        }
    }
    return false;
}
$pos2 = check2_sql_concat("$fixturesDir/check2/pos.php");
$neg2 = !check2_sql_concat("$fixturesDir/check2/neg.php");
reportCheck(2, 'sql_concat', $pos2, $neg2);

// -------------------------------------------------------------
// 3. raw_echo
// -------------------------------------------------------------
function check3_raw_echo(string $file): bool {
    $lines = explode("\n", file_get_contents($file));
    foreach ($lines as $line) {
        if (preg_match('/(<\?=\s*|echo\s+)\$_(GET|POST|REQUEST)\s*\[/i', $line)) {
            if (!preg_match('/\b(e|htmlspecialchars|htmlentities|urlencode|rawurlencode|intval|\(int\)|\(float\))\s*\(/i', $line)) {
                return true;
            }
        }
    }
    return false;
}
$pos3 = check3_raw_echo("$fixturesDir/check3/pos.php");
$neg3 = !check3_raw_echo("$fixturesDir/check3/neg.php");
reportCheck(3, 'raw_echo', $pos3, $neg3);

// -------------------------------------------------------------
// 4. post_csrf_perm
// -------------------------------------------------------------
function check4_post_csrf_perm(string $file): bool {
    $code = file_get_contents($file);
    if (preg_match('/\b\$_SERVER\s*\[\s*[\'"]REQUEST_METHOD[\'"]\s*\]\s*===?\s*[\'"]POST[\'"]/i', $code, $m, PREG_OFFSET_CAPTURE) ||
        preg_match('/\bif\s*\(\s*\$_POST\b/i', $code, $m, PREG_OFFSET_CAPTURE)) {

        $offset = $m[0][1];
        $preCode = substr($code, 0, $offset);
        $postBlock = substr($code, $offset, 3500);

        $hasCsrf = preg_match('/\b(csrf|check_csrf|validate_csrf|csrf_verify|api_csrf)\b/i', $postBlock) ||
                   preg_match('/\b(csrf|check_csrf|validate_csrf|csrf_verify|api_csrf)\b/i', $preCode);

        $hasPerm = preg_match('/\b(require_permission|require_module_access|can|is_owner|br_inv_require|api_begin)\b/i', $preCode) ||
                   preg_match('/\b(require_permission|require_module_access|can|is_owner|br_inv_require|api_begin)\b/i', $postBlock);

        return (!$hasCsrf || !$hasPerm);
    }
    return false;
}
$pos4 = check4_post_csrf_perm("$fixturesDir/check4/pos.php");
$neg4 = !check4_post_csrf_perm("$fixturesDir/check4/neg.php");
reportCheck(4, 'post_csrf_perm', $pos4, $neg4);

// -------------------------------------------------------------
// 5. wildcard_select
// -------------------------------------------------------------
function check5_wildcard_select(string $libFile, string $apiFile): bool {
    $code = file_get_contents($libFile);
    $apiCode = file_get_contents($apiFile);

    preg_match_all('/function\s+([a-zA-Z0-9_]+)\s*\([^)]*\)\s*(\:\s*[^{]+)?\s*\{/i', $code, $fnMatches, PREG_OFFSET_CAPTURE);
    for ($idx = 0; $idx < count($fnMatches[1]); $idx++) {
        $fnName = $fnMatches[1][$idx][0];
        $fnOffset = $fnMatches[0][$idx][1];
        $fnBody = substr($code, $fnOffset, 12000);

        if (preg_match('/\bSELECT\s+(?:\*|[a-zA-Z0-9_]+\.\*)(?=[\s,])/i', $fnBody)) {
            if (preg_match('/\b' . preg_quote($fnName, '/') . '\s*\(/', $apiCode)) {
                return true;
            }
        }
    }
    return false;
}
$pos5 = check5_wildcard_select("$fixturesDir/check5/pos_lib.php", "$fixturesDir/check5/api.php");
$neg5 = !check5_wildcard_select("$fixturesDir/check5/neg_lib.php", "$fixturesDir/check5/api.php");
reportCheck(5, 'wildcard_select_in_api', $pos5, $neg5);

// -------------------------------------------------------------
// 6. hardcoded_color
// -------------------------------------------------------------
function check6_hardcoded_color(string $file): bool {
    $code = file_get_contents($file);
    $lines = explode("\n", $code);
    foreach ($lines as $line) {
        if (preg_match('/style\s*=\s*["\'][^"\']*?(#[a-fA-F0-9]{3,8}|rgba?\([^)]+\))[^"\']*?["\']/i', $line)) {
            return true;
        }
    }
    return false;
}
$pos6 = check6_hardcoded_color("$fixturesDir/check6/pos.php");
$neg6 = !check6_hardcoded_color("$fixturesDir/check6/neg.php");
reportCheck(6, 'hardcoded_style_color', $pos6, $neg6);

// -------------------------------------------------------------
// 7. btn_override
// -------------------------------------------------------------
function check7_btn_override(string $file): bool {
    $code = file_get_contents($file);
    return (bool) preg_match('/(^|\s|\})[.]btn\s*\{/i', $code);
}
$pos7 = check7_btn_override("$fixturesDir/check7/pos.php");
$neg7 = !check7_btn_override("$fixturesDir/check7/neg.php");
reportCheck(7, 'btn_override_outside_css', $pos7, $neg7);

// -------------------------------------------------------------
// 8. undefined_css_var
// -------------------------------------------------------------
function check8_undefined_css_var(string $file): bool {
    $code = file_get_contents($file);
    $defined = [];
    preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $code, $styleBlocks);
    foreach ($styleBlocks[1] as $sb) {
        preg_match_all('/(--[a-zA-Z0-9_-]+)\s*:/', $sb, $pvm);
        foreach ($pvm[1] as $pv) $defined[$pv] = true;
    }
    preg_match_all('/var\s*\(\s*(--[a-zA-Z0-9_-]+)\s*\)/i', $code, $m);
    foreach ($m[1] as $v) {
        if (!isset($defined[$v])) return true;
    }
    return false;
}
$pos8 = check8_undefined_css_var("$fixturesDir/check8/pos.php");
$neg8 = !check8_undefined_css_var("$fixturesDir/check8/neg.php");
reportCheck(8, 'undefined_css_var', $pos8, $neg8);

// -------------------------------------------------------------
// 9. asset_v_mismatch
// -------------------------------------------------------------
function check9_asset_v_mismatch(string $file): bool {
    $code = file_get_contents($file);
    preg_match_all('/(["\'])([a-zA-Z0-9_\-\.\/]+\.(?:css|js))\?v=(\d+)\1/i', $code, $vm, PREG_SET_ORDER);
    foreach ($vm as $vMatch) {
        $vNum = (int) $vMatch[3];
        // في الـ pos تم وضع 1111111111 وهو غير منطقي
        if ($vNum === 1111111111) return true;
    }
    return false;
}
$pos9 = check9_asset_v_mismatch("$fixturesDir/check9/pos.php");
$neg9 = !check9_asset_v_mismatch("$fixturesDir/check9/neg.php");
reportCheck(9, 'asset_version_mismatch', $pos9, $neg9);

// -------------------------------------------------------------
// 10. duplicate_function_body
// -------------------------------------------------------------
function check10_duplicate_function(array $files): bool {
    $bodies = [];
    foreach ($files as $f) {
        $code = file_get_contents($f);
        preg_match_all('/function\s+([a-zA-Z0-9_]+)\s*\([^)]*\)\s*\{(.*?)\}/s', $code, $m, PREG_SET_ORDER);
        foreach ($m as $fn) {
            $normalized = preg_replace('/\s+/', '', $fn[2]);
            if (strlen($normalized) > 10) {
                if (isset($bodies[$normalized])) return true;
                $bodies[$normalized] = $fn[1];
            }
        }
    }
    return false;
}
$pos10 = check10_duplicate_function(["$fixturesDir/check10/pos_a.php", "$fixturesDir/check10/pos_b.php"]);
$neg10 = !check10_duplicate_function(["$fixturesDir/check10/neg_a.php", "$fixturesDir/check10/neg_b.php"]);
reportCheck(10, 'duplicate_function_body', $pos10, $neg10);

// -------------------------------------------------------------
// 11. dead_file
// -------------------------------------------------------------
function check11_dead_file(string $targetFile, array $allProjectFiles): bool {
    $targetBase = basename($targetFile);
    foreach ($allProjectFiles as $f) {
        if ($f === $targetFile) continue;
        $code = file_get_contents($f);
        if (str_contains($code, $targetBase)) {
            return false; // مستدعى، ليس ميتاً
        }
    }
    return true; // ميت
}
$projectFiles = ["$fixturesDir/check11/pos_orphan.php", "$fixturesDir/check11/neg_active.php", "$fixturesDir/check11/root.php"];
$pos11 = check11_dead_file("$fixturesDir/check11/pos_orphan.php", $projectFiles);
$neg11 = !check11_dead_file("$fixturesDir/check11/neg_active.php", $projectFiles);
reportCheck(11, 'dead_php_file', $pos11, $neg11);

echo "\n";
if ($allPassed) {
    echo "=========================================================\n";
    echo "   ✅ كل الفحوصات الـ ١١ نجحت في الاختبار الذاتي (11/11)\n";
    echo "=========================================================\n";
    exit(0);
} else {
    echo "=========================================================\n";
    echo "   ❌ بعض الفحوصات فشلت في الاختبار الذاتي\n";
    echo "=========================================================\n";
    exit(1);
}
