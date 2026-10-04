<?php
/**
 * sync_tokens.php — توكن سري لكل وكيل مزامنة
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
    'hq'      => 'hq_48c4cfd7ec51ede3faf01c94aba41504e95f7b9b',        // agent_hq.ps1 — جهاز الإدارة
    'zahraa'  => 'zahraa_ee52f116193bd515a9bd734fa42a57950f1a0901',    // agent_FAGALA.ps1 — مفتاح الفجالة zahraa مش fagala
    'degla'   => 'degla_42e23f48817465124b78c8d6237e27e93259a154',     // agent_DEGLA.ps1
    'bonjour' => 'bonjour_f5721011c8b61eed015cb0fed706bdb78135ee3b',   // agent_BONJOUR.ps1
    'abc'     => 'abc_6916c3971bb821d05bbc45979f91075499f30fef',       // agent_ABC.ps1
    // agent_ashraqa.ps1 — نبضة بس (الداتا بتروح sync_ashraqa.php).
    // 🔴 نفس ASHRAQA_SYNC_TOKEN في sync_ashraqa.php — لو اتغيّر هناك غيّره هنا.
    'ashraqa' => 'ashraqa_9xK2mQ7vR4tN8pL5wZ3hB6yF1cD0jS',
    'sms_phone' => 'sms_638d41ecfeb47c0e949323da3f7e292ca2107baf',
    'guard_watch' => 'a221dee4938c9e9bbd000042bebb15ac6b3d2266', // برنامج كلود على جهاز محمد — guard_feed.php / guard_note.php بس
];

// الوكلاء المسموح لهم يبعتوا على sync_branch.php
const SYNC_BRANCH_AGENTS = ['zahraa', 'degla', 'bonjour', 'abc'];

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

