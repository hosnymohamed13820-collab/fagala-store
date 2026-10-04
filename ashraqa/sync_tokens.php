<?php
/**
 * sync_tokens.php — توكن سري لكل وكيل مزامنة (إشراقة)
 * =====================================================================
 *  لحد ٢٢ سبتمبر ٢٠٢٦ كان فيه توكن واحد لكل الوكلاء، و`sync_branch.php`
 *  كان بياخد اسم الفرع من الطلب ويصدّقه — جهاز أي فرع كان يقدر يبعت
 *  أرصدة وفواتير باسم فرع تاني. دلوقتي التوكن لازم يطابق اسم الوكيل.
 *
 *  كل وكيل = توكن واحد، هو هو للداتا وللنبضة. ونفس القيمة بالظبط
 *  في ملف الـ.ps1 بتاعه ($SyncToken).
 *
 *  🔴 الملف ده **مختلف** بين site/ وashraqa/ عن قصد — ممنوع cp.
 * =====================================================================
 */
declare(strict_types=1);

// الملف ده بيتعمله require بس — مالوش لازمة يتفتح من المتصفح
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

const SYNC_AGENT_TOKENS = [
    // agent_ashraqa_web.ps1 — بيلعب دور «الإدارة» على موقع إشراقة.
    // 🔴 نفس HQ_SYNC_TOKEN في ashraqa/sync_hq.php — لو اتغيّر هناك غيّره هنا.
    'hq' => 'ashweb_15c488c25181d22bec038b314ba6a679',
    'sms_phone' => 'sms_ash_365d9671970117b9506e46de4c985e0a01c3bbc5',
];

// مفيش فرع بيبعت على موقع إشراقة — sync_branch.php هناك بيرفض أي طلب
const SYNC_BRANCH_AGENTS = [];

// 🔴 انتقالي — التوكن القديم المشترك. بيتقبل لأي وكيل مسموح له بالملف،
//    لحد ما كل الأجهزة تتحدّث. المرحلة التانية = امسح السطر ده وبس.
const SYNC_LEGACY_TOKEN = 'hq_7Kp9mXq2Rv5Nw8Lt3Bz6Yd4Hs1Fj0Gc';

/**
 * التوكن ده مسموح له يبعت باسم الوكيل ده على النقطة دي؟
 * $allowed = الوكلاء المسموحين على الملف اللي بينده (مش كل الخريطة).
 */
function synctok_ok(string $agent, string $token, array $allowed): bool
{
    if ($token === '' || !in_array($agent, $allowed, true)) return false;
    $want = SYNC_AGENT_TOKENS[$agent] ?? '';
    if ($want !== '' && hash_equals($want, $token)) return true;
    if (defined('SYNC_LEGACY_TOKEN') && SYNC_LEGACY_TOKEN !== ''
        && hash_equals(SYNC_LEGACY_TOKEN, $token)) {
        synctok_log_legacy($agent);
        return true;
    }
    return false;
}

/**
 * تسجيل استخدام التوكن القديم المشترك (انتقالي — بريف ٩٨).
 * مرة واحدة في الساعة لكل وكيل بالكتير، لحماية الملف من التضخم.
 * صامت تماماً — عمره ما يوقّع طلب مزامنة.
 */
function synctok_log_legacy(string $agent): void
{
    try {
        $dir = __DIR__ . '/logs';
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) return;
        }

        if (!function_exists('errlog_guard_dir')) {
            @require_once __DIR__ . '/errlog_lib.php';
        }
        if (function_exists('errlog_guard_dir')) {
            errlog_guard_dir();
        } else {
            $idx = $dir . '/index.php';
            if (!is_file($idx)) @file_put_contents($idx, "<?php http_response_code(404); exit;\n");
            $ht = $dir . '/.htaccess';
            if (!is_file($ht)) @file_put_contents($ht, "Require all denied\nOptions -Indexes\n");
        }

        $logFile = $dir . '/legacy_token.log.php';
        $now = date('Y-m-d H:i:s');
        $thisHour = substr($now, 0, 13); // 'YYYY-MM-DD HH'

        // لو فيه نفس الوكيل في نفس الساعة في آخر ٢٠٠ سطر متكتبش
        if (is_file($logFile)) {
            $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines) && !empty($lines)) {
                $recent = array_slice($lines, -200);
                foreach ($recent as $line) {
                    if (str_starts_with($line, $thisHour) && str_contains($line, ' · ' . $agent . ' · ')) {
                        return;
                    }
                }
            }
        }

        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) ?: basename((string) ($_SERVER['PHP_SELF'] ?? 'unknown'));
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $entry = $now . ' · ' . $agent . ' · ' . $script . ' · ' . $ip . "\n";

        $fh = @fopen($logFile, 'ab');
        if (!$fh) return;
        try {
            if (flock($fh, LOCK_EX)) {
                $stat = fstat($fh);
                if (($stat['size'] ?? 0) === 0) {
                    $guard = defined('ERRLOG_GUARD') ? ERRLOG_GUARD : "<?php http_response_code(404); exit; ?>\n";
                    fwrite($fh, $guard);
                }
                fwrite($fh, $entry);
                fflush($fh);
                flock($fh, LOCK_UN);
            }
        } finally {
            fclose($fh);
        }
    } catch (\Throwable $e) {
        // صامت تماماً
    }
}

