<?php
/**
 * errlog_lib.php — سجل أخطاء الموقع (PHP + جافاسكربت)
 * نظام الفجالة ستور المالي
 *
 * =====================================================================
 *  ليه الملف ده موجود
 * =====================================================================
 *  صفحتَي «مراجعة المحاسب» و«عهدة الفرع» فضلوا واقفين **أيام** (سبتمبر
 *  ٢٠٢٦) من غير ما حد يعرف — SyntaxError في الجافاسكربت، والزراير
 *  مابتعملش حاجة من غير أي رسالة. محمد عرف لما جرّب بنفسه.
 *
 *  السجل ده بيمسك أي خطأ عند أي مستخدم ويكتبه في ملف.
 *
 * =====================================================================
 *  ليه ملف مش جدول في القاعدة
 * =====================================================================
 *  محمد بيعمل «ميرورينج» لفولدر الموقع على جهازه — الملف بينزل معاه
 *  وكلود يقراه على طول. الجدول مابينزلش.
 *  وكمان: لو القاعدة نفسها هي اللي واقعة، السجل لازم يفضل شغّال.
 *
 * =====================================================================
 *  🔴 ليه الملف امتداده .php
 * =====================================================================
 *  أول سطر فيه `<?php http_response_code(404); exit; ?>` — لو حد فتحه من
 *  المتصفح بياخد 404 فاضية. الملف فيه مسارات ملفات ورسايل أخطاء وأسامي
 *  مستخدمين؛ مايتقراش من بره. ومع الميرورينج بينزل ويتقري عادي.
 *
 * =====================================================================
 *  قواعد مايتكسروش
 * =====================================================================
 *  ١) **السجل عمره ما يوقّع صفحة.** كل حاجة جوّه try/catch، ولو الفولدر
 *     مش قابل للكتابة بيسكت.
 *  ٢) **مابيغيّرش سلوك أي صفحة.** معالج الأخطاء بيرجّع false، يعني PHP
 *     بيكمّل تصرّفه العادي بالظبط زي قبل السجل. ومفيش
 *     set_exception_handler — الاستثناء اللي محدش مسكه بيوصل كفاتال
 *     عادي، وبنلقطه من error_get_last() في الآخر.
 *  ٣) **مفيش كتابة على الديسك مع كل ريكوست.** الأخطاء بتتجمّع في الذاكرة
 *     وتتكتب مرة واحدة في آخر الصفحة — ولو مفيش أخطاء، صفر عمليات ديسك.
 *  ٤) **مفيش باسوردات.** مابنسجّلش POST خالص، والـquery string بيتخبّى
 *     منه أي مفتاح شكله سري.
 *
 *  ⚠️ require_once بس — الدوال مابتتحميش من التعريف المكرر.
 */

define('ERRLOG_DIR', __DIR__ . '/logs');
define('ERRLOG_FILE', ERRLOG_DIR . '/errors.log.php');
define('ERRLOG_OLD', ERRLOG_DIR . '/errors.old.log.php');
define('ERRLOG_GUARD', "<?php http_response_code(404); exit; ?>\n");
define('ERRLOG_MAX_BYTES', 2 * 1024 * 1024);   // ~٥٠٠٠ سطر، وبعدها بيتلف
define('ERRLOG_MAX_PER_REQUEST', 30);           // حلقة فيها تحذير مايكتبش ألف سطر

/** تركيب المعالجات — مرة واحدة مهما اتندهت */
function errlog_install(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    set_error_handler('errlog_php_error');
    register_shutdown_function('errlog_shutdown');
}

/** اسم مستوى الخطأ للعرض */
function errlog_level(int $no): string
{
    switch ($no) {
        case E_ERROR: case E_CORE_ERROR: case E_COMPILE_ERROR: case E_USER_ERROR: return 'Fatal';
        case E_PARSE:             return 'Parse';
        case E_RECOVERABLE_ERROR: return 'Recoverable';
        case E_WARNING: case E_CORE_WARNING: case E_COMPILE_WARNING: case E_USER_WARNING: return 'Warning';
        case E_NOTICE: case E_USER_NOTICE: return 'Notice';
        default: return 'E' . $no;
    }
}

/** قص نص من غير ما يكسر حرف عربي في النص (json_encode بيرفض UTF-8 مكسور) */
function errlog_cut(string $s, int $n): string
{
    if (strlen($s) <= $n) return $s;
    return function_exists('mb_strcut') ? mb_strcut($s, 0, $n, 'UTF-8') : substr($s, 0, $n);
}

/** مسار الملف نسبةً لفولدر الموقع — المسار الكامل على السيرفر مالوش لازمة */
function errlog_relpath(string $file): string
{
    $file = str_replace('\\', '/', $file);
    $root = str_replace('\\', '/', __DIR__) . '/';
    return strpos($file, $root) === 0 ? substr($file, strlen($root)) : $file;
}

/** الصفحة اللي حصل فيها الخطأ، من غير أي قيمة سرية */
function errlog_request_url(): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if ($uri === '') return PHP_SAPI === 'cli' ? 'cli' : '';

    $parts = explode('?', $uri, 2);
    if (!isset($parts[1]) || $parts[1] === '') return errlog_cut($parts[0], 300);

    parse_str($parts[1], $q);
    foreach ($q as $k => $v) {
        if (preg_match('/pass|pwd|token|csrf|secret|key|pin|otp/i', (string) $k)) $q[$k] = '***';
    }
    return errlog_cut($parts[0] . '?' . http_build_query($q), 300);
}

/**
 * الأخطاء اللي اتجمّعت في الريكوست ده.
 * نفس الخطأ بيتجمّع في سطر واحد بعدّاد (`n`) — تحذير جوّه حلقة ٥٠٠ صنف
 * بيبقى سطر واحد مكتوب فيه n=500، مش ٥٠٠ سطر.
 */
function errlog_buffer(?array $entry = null): array
{
    static $buf = [];
    if ($entry !== null) {
        $fp = $entry['fp'];
        if (isset($buf[$fp]))                          $buf[$fp]['n']++;
        elseif (count($buf) < ERRLOG_MAX_PER_REQUEST)  $buf[$fp] = $entry;
    }
    return $buf;
}

/** تسجيل خطأ واحد في الذاكرة (بيتكتب في آخر الريكوست) */
function errlog_record(string $type, string $msg, string $file = '', int $line = 0, string $trace = ''): void
{
    try {
        $msg  = trim((string) preg_replace('/\s+/u', ' ', $msg));
        if ($msg === '') return;
        $file = errlog_relpath($file);

        // البصمة من غير الأرقام — «Undefined array key 5» و«key 9» نفس الغلطة
        $norm = (string) preg_replace('/\d+/', '#', $msg);
        $fp   = substr(sha1($type . '|' . $norm . '|' . $file . '|' . $line), 0, 12);

        $uid = null; $user = '';
        if (isset($_SESSION) && is_array($_SESSION) && !empty($_SESSION['user']['id'])) {
            $uid  = (int) $_SESSION['user']['id'];
            $user = (string) ($_SESSION['user']['username'] ?? '');
        }

        // هيدرز التطبيق والجهاز (بريف ١٠٤: X-App-Version و X-Device) — تنضيف بحروف وأرقام ونقط وشرط فقط
        $rawApp = (string) ($_SERVER['HTTP_X_APP_VERSION'] ?? '');
        if (strpos($rawApp, '%') !== false) $rawApp = rawurldecode($rawApp);
        $app    = $rawApp !== '' ? preg_replace('/[^\p{L}\p{N}._-]/u', '', $rawApp) : '';
        $app    = errlog_cut($app, 30);

        $rawDev = (string) ($_SERVER['HTTP_X_DEVICE'] ?? '');
        if (strpos($rawDev, '%') !== false) $rawDev = rawurldecode($rawDev);
        $dev    = $rawDev !== '' ? preg_replace('/[^\p{L}\p{N}._-]/u', '', $rawDev) : '';
        $dev    = errlog_cut($dev, 60);

        errlog_buffer([
            // 🔴 توقيت القاهرة صريح مش date(): السجل بيتحمّل **قبل** config.php
            //    (اللي بيظبط Africa/Cairo)، فأي خطأ قبله كان بيتسجّل UTC —
            //    فرق ٣ ساعات بيبوّظ ترتيب «الأحدث فوق». اتمسك في الاختبار.
            't'      => (new DateTime('now', new DateTimeZone('Africa/Cairo')))->format('Y-m-d H:i:s'),
            'type'   => $type,
            'msg'    => errlog_cut($msg, 600),
            'file'   => errlog_cut($file, 200),
            'line'   => $line,
            'url'    => errlog_request_url(),
            'uid'    => $uid,
            'user'   => errlog_cut($user, 60),
            'app'    => $app,
            'device' => $dev,
            'trace'  => errlog_cut($trace, 1500),
            'fp'     => $fp,
            'n'      => 1,
        ]);
    } catch (Throwable $ex) {
        // 🔴 السجل عمره ما يوقّع صفحة
    }
}

/** معالج أخطاء PHP (تحذيرات ونوتس) */
function errlog_php_error(int $no, string $str, string $file = '', int $line = 0): bool
{
    // مكتومة بـ@ — اللي كتبها قاصد يسكّتها
    if (!(error_reporting() & $no)) return false;
    // الـdeprecated بيغرق السجل مع كل تحديث PHP ومش أخطاء بتوقف حاجة
    if ($no === E_DEPRECATED || $no === E_USER_DEPRECATED) return false;

    errlog_record('php', errlog_level($no) . ': ' . $str, $file, $line);

    // 🔴 false = PHP يكمّل سلوكه العادي بالظبط. السجل بيتفرّج بس.
    return false;
}

/** آخر الريكوست: الفاتال (مابيعدّيش على set_error_handler) + الكتابة */
function errlog_shutdown(): void
{
    try {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            // الاستثناء اللي محدش مسكه بيوصل هنا ومعاه الـstack trace في الرسالة
            $parts = explode("\nStack trace:", (string) $e['message'], 2);
            errlog_record(
                'php',
                errlog_level((int) $e['type']) . ': ' . $parts[0],
                (string) $e['file'],
                (int) $e['line'],
                isset($parts[1]) ? 'Stack trace:' . $parts[1] : ''
            );
        }
        errlog_flush();
    } catch (Throwable $ex) {
        // 🔴 السجل عمره ما يوقّع صفحة
    }
}

/** الكتابة على الديسك — مرة واحدة في الريكوست، وبس لو فيه أخطاء */
function errlog_flush(): void
{
    $buf = errlog_buffer();
    if (!$buf) return;   // صفر أخطاء = صفر عمليات ديسك

    if (!is_dir(ERRLOG_DIR)) {
        if (!@mkdir(ERRLOG_DIR, 0755, true)) return;
    }
    errlog_guard_dir();

    // لفّ الملف لما يكبر: الحالي بيبقى «القديم»، والقديم القديم بيتمسح
    clearstatcache(true, ERRLOG_FILE);
    if (is_file(ERRLOG_FILE) && filesize(ERRLOG_FILE) > ERRLOG_MAX_BYTES) {
        @unlink(ERRLOG_OLD);            // ويندوز مابيعملش rename فوق ملف موجود
        @rename(ERRLOG_FILE, ERRLOG_OLD);
    }

    $lines = '';
    foreach ($buf as $entry) {
        $json = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json !== false) $lines .= $json . "\n";
    }
    if ($lines === '') return;

    $fh = @fopen(ERRLOG_FILE, 'ab');
    if (!$fh) return;
    try {
        if (flock($fh, LOCK_EX)) {
            $stat = fstat($fh);
            // 🔴 سطر الحماية لازم يبقى أول سطر — من غيره الملف بيتقري من المتصفح
            if (($stat['size'] ?? 0) === 0) fwrite($fh, ERRLOG_GUARD);
            fwrite($fh, $lines);
            fflush($fh);
            flock($fh, LOCK_UN);
        }
    } finally {
        fclose($fh);
    }
}

/** حماية الفولدر نفسه: مفيش عرض لمحتوياته ولا وصول مباشر */
function errlog_guard_dir(): void
{
    $idx = ERRLOG_DIR . '/index.php';
    if (!is_file($idx)) @file_put_contents($idx, "<?php http_response_code(404); exit;\n");
    $ht = ERRLOG_DIR . '/.htaccess';
    if (!is_file($ht)) @file_put_contents($ht, "Require all denied\nOptions -Indexes\n");
}

/**
 * قراية السجل ومجمّع بالبصمة — للصفحة ولكلود من سطر الأوامر.
 *
 * @param string[] $files الملفات بالترتيب (القديم الأول)
 * @return array قايمة مجموعات مرتّبة بآخر ظهور، كل واحدة:
 *               fp · type · msg · file · line · count · first · last · url · users[] · trace
 */
function errlog_read_grouped(array $files): array
{
    $groups = [];
    foreach ($files as $f) {
        if (!is_file($f)) continue;
        $fh = @fopen($f, 'rb');
        if (!$fh) continue;
        while (($raw = fgets($fh)) !== false) {
            $raw = trim($raw);
            if ($raw === '' || strpos($raw, '<?php') === 0) continue;   // سطر الحماية
            $e = json_decode($raw, true);
            if (!is_array($e) || empty($e['fp'])) continue;

            $fp = $e['fp'];
            if (!isset($groups[$fp])) {
                $groups[$fp] = [
                    'fp' => $fp, 'type' => $e['type'] ?? '', 'msg' => $e['msg'] ?? '',
                    'file' => $e['file'] ?? '', 'line' => (int) ($e['line'] ?? 0),
                    'count' => 0, 'first' => $e['t'] ?? '', 'last' => $e['t'] ?? '',
                    'url' => '', 'users' => [], 'trace' => '',
                    'apps' => [], 'devices' => [],
                ];
            }
            $g = &$groups[$fp];
            $g['count'] += max(1, (int) ($e['n'] ?? 1));
            if (($e['t'] ?? '') >= $g['last']) {
                $g['last']  = $e['t'] ?? $g['last'];
                $g['msg']   = $e['msg'] ?? $g['msg'];     // آخر رسالة (الأرقام فيها بتتغيّر)
                $g['url']   = $e['url'] ?? '';
                if (!empty($e['trace'])) $g['trace'] = $e['trace'];
            }
            if (($e['t'] ?? '') !== '' && $e['t'] < $g['first']) $g['first'] = $e['t'];
            if (!empty($e['user'])) $g['users'][$e['user']] = true;
            if (!empty($e['app'])) $g['apps'][$e['app']] = true;
            if (!empty($e['device'])) $g['devices'][$e['device']] = true;
            unset($g);
        }
        fclose($fh);
    }

    foreach ($groups as &$g) {
        $g['users']   = array_keys($g['users']);
        $g['apps']    = array_keys($g['apps']);
        $g['devices'] = array_keys($g['devices']);
    }
    unset($g);
    usort($groups, fn($a, $b) => strcmp($b['last'], $a['last']));
    return $groups;
}

/**
 * كل اللي شاشة «سجل الأخطاء» بتعرضه في نداء واحد: المجموعات + الأرقام اللي
 * فوق الجدول + حجم الملفات. الصفحة والـAPI بينادوا الدالة دي، فمفيش حساب
 * متعاد في مكانين ورقم الكارت مايختلفش عن الجدول.
 *
 * @return array{groups: array, count: int, total: int, php: int, js: int, size: int}
 */
function errlog_snapshot(): array
{
    $groups = errlog_read_grouped([ERRLOG_OLD, ERRLOG_FILE]);

    // الويب بيرندر بعد الكتابة في نفس العملية — من غير المسح ممكن الحجم يطلع قديم
    clearstatcache(true, ERRLOG_FILE);
    clearstatcache(true, ERRLOG_OLD);

    return [
        'groups' => $groups,
        'count'  => count($groups),
        'total'  => (int) array_sum(array_column($groups, 'count')),
        'php'    => count(array_filter($groups, fn($g) => $g['type'] === 'php')),
        'js'     => count(array_filter($groups, fn($g) => $g['type'] === 'js')),
        'size'   => (is_file(ERRLOG_FILE) ? (int) filesize(ERRLOG_FILE) : 0)
                  + (is_file(ERRLOG_OLD) ? (int) filesize(ERRLOG_OLD) : 0),
    ];
}

/**
 * مسح السجل الحالي والقديم. بيسكت لو الملف مش موجود أو مش قابل للمسح —
 * زي ما الصفحة كانت بتعمل بالحرف (@unlink)، فمفيش استثناء بيطلع للمستخدم.
 */
function errlog_clear(): void
{
    @unlink(ERRLOG_FILE);
    @unlink(ERRLOG_OLD);
    clearstatcache(true, ERRLOG_FILE);
    clearstatcache(true, ERRLOG_OLD);
}
