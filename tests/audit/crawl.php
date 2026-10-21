<?php
/**
 * crawl.php — زاحف الموقع وفاحص الـ APIs (بريف ١٨٢ — الجزء أ)
 * نظام الفجالة ستور المالي
 *
 * يعمل على Laragon (www_ds2) وينفّذ كل رندر في عملية مستقلة عبر worker.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = getenv('LARAGON_WWW') ?: 'D:/laragon/www_ds2';
$phpBin = 'D:/laragon/bin/php/php-8.3.33-Win32-vs16-x64/php.exe';
if (!is_file($phpBin)) {
    $phpBin = PHP_BINARY;
}

$outDir = $argv[1] ?? (__DIR__ . '/out/' . date('Y-m-d_His'));
if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}
$rendersDir = "$outDir/renders";
if (!is_dir($rendersDir)) {
    mkdir($rendersDir, 0777, true);
}
$tempDir = "$outDir/tmp";
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0777, true);
}

echo "=========================================================\n";
echo "   DEEP AUDIT — PART A: CRAWLER & API PROBE (Brief 182)\n";
echo "   Target WWW: $root\n";
echo "   Output Dir: $outDir\n";
echo "=========================================================\n\n";

require_once "$root/config.php";
$pdo = db();

// 1. إدارة الإشعارات قبل الزحف
echo "[1/6] فحص وإعداد طابور الإشعارات...\n";
$pqPending = (int) $pdo->query("SELECT COUNT(*) FROM push_queue WHERE status='pending'")->fetchColumn();
if ($pqPending > 0) {
    echo "  - وجد $pqPending إشعار معلق في push_queue، يتم تعليمهم failed كحزام أمان...\n";
    $pdo->exec("UPDATE push_queue SET status='failed' WHERE status='pending'");
}
$pqBefore = (int) $pdo->query("SELECT COUNT(*) FROM push_queue")->fetchColumn();
$notifBefore = (int) $pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
echo "  - عدد push_queue قبل الزحف: $pqBefore\n";
echo "  - عدد notifications قبل الزحف: $notifBefore\n\n";

// 2. إعداد قائمة المستخدمين السبعة
echo "[2/6] ضبط واختيار المستخدمين السبعة...\n";
$users = [
    'owner_locked'   => ['id' => 1,  'name' => 'المالك (مقفول)',       'role' => 'owner',     'branch' => 'الكل',    'unlocked' => false],
    'owner_unlocked' => ['id' => 1,  'name' => 'المالك (مفتوح)',       'role' => 'owner',     'branch' => 'الكل',    'unlocked' => true],
    'view_all'       => ['id' => 0,  'name' => 'مشاهدة فقط (view_all)','role' => 'غير متوفر', 'branch' => '—',       'unlocked' => false, 'skip' => true],
    'accountant'     => ['id' => 15, 'name' => 'محاسب (role_id=3)',    'role' => 'accountant','branch' => 'الادارة', 'unlocked' => false],
    'cashier'        => ['id' => 16, 'name' => 'كاشير فرع (role_id=4)', 'role' => 'cashier',   'branch' => 'بونجور',  'unlocked' => false],
    'employee_min'   => ['id' => 58, 'name' => 'موظف حضور/أقل صلاحيات', 'role' => 'role_6',   'branch' => 'الادارة', 'unlocked' => false],
    'ashraqa'        => ['id' => 66, 'name' => 'إشراقة (branch_id=5)', 'role' => 'cashier',   'branch' => 'اشراقة',  'unlocked' => false],
];

foreach ($users as $k => $u) {
    if (!empty($u['skip'])) {
        echo "  - [!] النوع $k: لا يوجد مستخدم في قاعدة البيانات لديه هذه الصفة (سيدوّن في التقرير).\n";
        continue;
    }
    $permsCount = (int) $pdo->query("SELECT COUNT(*) FROM user_permissions WHERE user_id = {$u['id']}")->fetchColumn();
    echo "  - [$k] ID: {$u['id']} | {$u['name']} | فرع: {$u['branch']} | صلاحيات مباشرة: $permsCount\n";
}
echo "\n";

// 3. حصر وتصنيف صفحات الموقع
echo "[3/6] حصر وتصنيف صفحات الموقع واستكشاف الصفحات غير المحمية...\n";
$siteFiles = glob("$root/*.php");
$pages = [];
$apiFiles = [];
$unprotectedPages = [];
$findings = [];

foreach ($siteFiles as $sf) {
    $base = basename($sf);
    if (str_ends_with($base, '_api.php')) {
        $apiFiles[] = $base;
        continue;
    }
    if (str_ends_with($base, '_lib.php') || $base === 'config.php' || $base === 'logout.php') {
        continue;
    }
    $code = file_get_contents($sf);
    if (str_contains($code, "define('API_MODE'") || str_contains($code, 'define("API_MODE"')) {
        continue;
    }

    // فحص حراسة الحماية
    $isProtected = preg_match('/\b(require_login|require_permission|require_module_access|br_inv_require)\b/', $code);
    if (!$isProtected) {
        $unprotectedPages[] = $base;
        $findings[] = [
            'severity' => 'red',
            'kind'     => 'unprotected_page',
            'file'     => "site/$base",
            'line'     => 1,
            'user'     => 'all',
            'url'      => $base,
            'detail'   => "صفحة بدون فحص تسجيل دخول أو صلاحيات (مفيش require_login/require_permission/require_module_access)",
        ];
    } else {
        $pages[] = $base;
    }
}

echo "  - إجمالي الصفحات المكتشفة: " . (count($pages) + count($unprotectedPages)) . " (محمية: " . count($pages) . "، غير محمية: " . count($unprotectedPages) . ")\n";
echo "  - إجمالي واجهات API المكتشفة: " . count($apiFiles) . "\n\n";

// دالة مساعدة لتشغيل الرندر عبر worker.php
function executeWorker(string $phpBin, string $page, string $userKey, array $userCfg, string $qs, string $method, string $postBody, string $outDir, string $tempDir): array {
    static $runSeq = 0;
    $runSeq++;
    $metaFile = "$tempDir/meta_{$runSeq}.json";
    $bodyFile = "$tempDir/body_{$runSeq}.html";

    $payload = [
        'page'      => $page,
        'user_type' => $userKey,
        'user_id'   => $userCfg['id'],
        'unlocked'  => $userCfg['unlocked'],
        'qs'        => $qs,
        'method'    => $method,
        'post'      => $postBody,
        'meta'      => $metaFile,
        'body'      => $bodyFile,
    ];

    $b64 = base64_encode(json_encode($payload));
    $workerScript = __DIR__ . '/worker.php';
    $cmd = sprintf('"%s" "%s" %s', $phpBin, $workerScript, escapeshellarg($b64));

    exec($cmd);

    $meta = [];
    if (file_exists($metaFile)) {
        $meta = json_decode(file_get_contents($metaFile), true) ?: [];
        unlink($metaFile);
    }
    $body = '';
    if (file_exists($bodyFile)) {
        $body = file_get_contents($bodyFile) ?: '';
        unlink($bodyFile);
    }

    return ['meta' => $meta, 'body' => $body];
}

// 4. زحف الصفحات لكل يوزر
echo "[4/6] بدء الزحف على الصفحات والروابط الداخلية...\n";
$crawlStats = [
    'renders_count' => 0,
    'links_found'   => 0,
    'slow_pages'    => [],
    'heavy_queries' => [],
];

$actionWords = ['delete', 'del', 'remove', 'approve', 'reject', 'reverse', 'cancel', 'post', 'close', 'pay', 'logout', 'action', 'do', 'confirm'];

// نحدد المستخدمين النشطين
$activeUsers = array_filter($users, fn($u) => empty($u['skip']));

foreach ($activeUsers as $userKey => $userCfg) {
    echo "  --- زحف للمستخدم: {$userCfg['name']} ($userKey) ---\n";
    $userRendersDir = "$rendersDir/$userKey";
    if (!is_dir($userRendersDir)) {
        mkdir($userRendersDir, 0777, true);
    }

    $queue = [];
    $visited = [];
    $linkPatternCounts = [];

    // البداية بجميع الصفحات (عمق 0)
    foreach ($pages as $p) {
        $queue[] = ['page' => $p, 'qs' => '', 'depth' => 0, 'from' => 'seed'];
    }

    while (!empty($queue)) {
        $item = array_shift($queue);
        $currPage  = $item['page'];
        $currQs    = $item['qs'];
        $currDepth = $item['depth'];
        $currFrom  = $item['from'];

        $urlKey = $currPage . ($currQs !== '' ? '?' . $currQs : '');
        if (isset($visited[$urlKey])) {
            continue;
        }
        $visited[$urlKey] = true;
        $crawlStats['renders_count']++;

        $res = executeWorker($phpBin, $currPage, $userKey, $userCfg, $currQs, 'GET', '', $outDir, $tempDir);
        $meta = $res['meta'];
        $body = $res['body'];

        // حفظ أول رندر لكل صفحة للمستخدمين المطلوبين للمتصفح (owner_unlocked و cashier)
        if ($currQs === '' && in_array($userKey, ['owner_unlocked', 'cashier'], true)) {
            $saveFile = "$userRendersDir/{$currPage}.html";
            file_put_contents($saveFile, $body);
        }

        // فحص الأداء والاستعلامات
        $timeMs = (float) ($meta['time_ms'] ?? 0);
        $questions = (int) ($meta['questions'] ?? 0);
        $writes = (int) ($meta['writes'] ?? 0);

        $crawlStats['slow_pages'][] = [
            'url'     => $urlKey,
            'user'    => $userKey,
            'time_ms' => $timeMs,
            'bytes'   => $meta['bytes'] ?? 0,
        ];
        $crawlStats['heavy_queries'][] = [
            'url'       => $urlKey,
            'user'      => $userKey,
            'questions' => $questions,
        ];

        // 🔴 كتابة في القاعدة أثناء GET = FINDING أحمر حاسم
        if ($writes > 0) {
            $findings[] = [
                'severity' => 'red',
                'kind'     => 'db_write_on_get',
                'file'     => "site/$currPage",
                'line'     => 1,
                'user'     => $userKey,
                'url'      => $urlKey,
                'detail'   => "طلب GET كتب في قاعدة البيانات ($writes عملية كتابة: " . json_encode($meta['writes_detail'] ?? [], JSON_UNESCAPED_UNICODE) . ")",
            ];
        }

        // Fatal errors
        if (!empty($meta['fatal'])) {
            $findings[] = [
                'severity' => 'red',
                'kind'     => 'fatal_error',
                'file'     => $meta['fatal']['file'] ?? "site/$currPage",
                'line'     => $meta['fatal']['line'] ?? 1,
                'user'     => $userKey,
                'url'      => $urlKey,
                'detail'   => $meta['fatal']['message'] ?? 'Fatal error',
            ];
        }

        // Warnings / Notices
        foreach ($meta['errors'] ?? [] as $err) {
            if ($err['type'] === 'Fatal') continue;
            $findings[] = [
                'severity' => ($err['type'] === 'Warning' ? 'orange' : 'yellow'),
                'kind'     => 'php_' . strtolower($err['type']),
                'file'     => $err['file'] ?? "site/$currPage",
                'line'     => $err['line'] ?? 1,
                'user'     => $userKey,
                'url'      => $urlKey,
                'detail'   => "{$err['type']}: {$err['message']}",
            ];
        }

        // استخراج الروابط إذا كان العمق أقل من 2 والمحتوى HTML
        if ($currDepth < 2 && !empty($body) && (empty($meta['status_code']) || $meta['status_code'] === 200)) {
            preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\']/i', $body, $linkMatches);
            $foundHrefs = $linkMatches[1] ?? [];
            $perPageLinkCount = 0;

            foreach ($foundHrefs as $href) {
                $href = trim($href);
                if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
                    continue;
                }
                if (preg_match('/^https?:\/\//i', $href) && !str_contains($href, 'localhost')) {
                    continue;
                }

                // تجريد المسار والـ query string
                $parts = parse_url($href);
                $path = $parts['path'] ?? '';
                $query = $parts['query'] ?? '';
                $targetPage = basename($path);

                if (!str_ends_with($targetPage, '.php')) {
                    continue;
                }
                if (!in_array($targetPage, $pages, true)) {
                    continue;
                }

                $crawlStats['links_found']++;

                // 🔴 فحص بارامترات العمليات في GET
                parse_str($query, $queryArr);
                $hasActionParam = false;
                $matchedActionWord = '';
                foreach ($queryArr as $qk => $qv) {
                    $qkLower = strtolower((string)$qk);
                    $qvLower = strtolower((string)$qv);
                    foreach ($actionWords as $aw) {
                        if ($qkLower === $aw || $qvLower === $aw) {
                            $hasActionParam = true;
                            $matchedActionWord = $aw;
                            break 2;
                        }
                    }
                }

                if ($hasActionParam) {
                    $findings[] = [
                        'severity' => 'orange',
                        'kind'     => 'action_param_in_get',
                        'file'     => "site/$currPage",
                        'line'     => 1,
                        'user'     => $userKey,
                        'url'      => "$targetPage?$query",
                        'detail'   => "رابط GET يحتوي على بارامتر عملية ($matchedActionWord) في الصفحة $currPage",
                    ];
                    // 🔴 ممنوع تتبع الروابط التي تحوي بارامترات عمليات
                    continue;
                }

                // ضبط السقف: 40 رابط كحد أقصى لكل صفحة
                if ($perPageLinkCount >= 40) {
                    continue;
                }

                // ضبط تشابه قيم الـ ID (أول 3 قيم فقط لكل نمط)
                $patternKey = $targetPage . '?' . implode('&', array_keys($queryArr));
                $linkPatternCounts[$patternKey] = ($linkPatternCounts[$patternKey] ?? 0) + 1;
                if ($linkPatternCounts[$patternKey] > 3) {
                    continue;
                }

                $perPageLinkCount++;
                $queue[] = [
                    'page'  => $targetPage,
                    'qs'    => $query,
                    'depth' => $currDepth + 1,
                    'from'  => $urlKey,
                ];
            }
        }
    }
    echo "    تم فحص " . count($visited) . " رابط بنجاح.\n";
}
echo "\n";

// 5. فحص واجهات الـ APIs
echo "[5/6] فحص واجهات الـ APIs وتسريب الحقول الحساسة واختبار POST probe...\n";
$sensitiveKeys = [
    'salary', 'base_salary', 'balance', 'cost', 'profit', 'supplier',
    'password', 'token', 'hash', 'phone', 'national', 'advance', 'deficit', 'amount'
];

$apiStats = [
    'tested_parts' => 0,
    'leaks_found'  => 0,
];

foreach ($apiFiles as $apiFile) {
    $apiCode = file_get_contents("$root/$apiFile");

    // استخراج أجزاء الـ API (parts/types)
    $parts = [];
    if (preg_match('/\$validParts\s*=\s*\[(.*?)\];/s', $apiCode, $m)) {
        preg_match_all("/['\"]([a-zA-Z0-9_-]+)['\"]/", $m[1], $pm);
        $parts = array_merge($parts, $pm[1]);
    }
    if (preg_match('/\$allowedParts\s*=\s*\[(.*?)\];/s', $apiCode, $m)) {
        preg_match_all("/['\"]([a-zA-Z0-9_-]+)['\"]/", $m[1], $pm);
        $parts = array_merge($parts, $pm[1]);
    }
    if (preg_match_all('/case\s+[\'"]([a-zA-Z0-9_-]+)[\'"]\s*:/', $apiCode, $cm)) {
        $parts = array_merge($parts, $cm[1]);
    }
    $parts = array_unique($parts);
    if (empty($parts)) {
        $parts = [''];
    }

    $paramName = (str_contains($apiCode, "\$_GET['type']") && !str_contains($apiCode, "\$_GET['part']")) ? 'type' : 'part';

    echo "    - فحص $apiFile...\n";
    $recordedLeakKeys = [];

    // فحص GET لكل جزء مع كل يوزر
    foreach ($activeUsers as $userKey => $userCfg) {
        $isOwner = !empty($userCfg['unlocked']) && $userCfg['role'] === 'owner';

        foreach ($parts as $pVal) {
            $apiStats['tested_parts']++;
            $qs = $pVal !== '' ? "$paramName=$pVal" : '';
            $res = executeWorker($phpBin, $apiFile, $userKey, $userCfg, $qs, 'GET', '', $outDir, $tempDir);
            $meta = $res['meta'];
            $body = $res['body'];

            $json = json_decode($body, true);
            if (!is_array($json)) {
                continue;
            }

            // فحص المفاتيح الحساسة لو المستخدم مش مالك أو الأرقام مقفولة
            if (!$isOwner) {
                $collectedKeys = [];
                $extractKeys = function($arr) use (&$extractKeys, &$collectedKeys) {
                    foreach ($arr as $k => $v) {
                        if (is_string($k)) {
                            $collectedKeys[$k] = true;
                        }
                        if (is_array($v)) {
                            $extractKeys($v);
                        }
                    }
                };
                $extractKeys($json);
                $uniqueKeys = array_keys($collectedKeys);

                $corrPage = str_replace('_api.php', '.php', $apiFile);
                $htmlPageFile = "$rendersDir/$userKey/{$corrPage}.html";
                $pageHtml = file_exists($htmlPageFile) ? file_get_contents($htmlPageFile) : '';

                foreach ($uniqueKeys as $kName) {
                    $kLower = strtolower($kName);
                    foreach ($sensitiveKeys as $sk) {
                        if (str_contains($kLower, $sk)) {
                            $leakKey = "$apiFile|$qs|$userKey|$kName";
                            if (isset($recordedLeakKeys[$leakKey])) {
                                break;
                            }

                            // التحقق مما إذا كانت الصفحة المقابلة تخفي هذا الحقل
                            $pageHidesField = false;
                            if ($pageHtml !== '') {
                                if (str_contains($pageHtml, 'sens-blur') || !str_contains($pageHtml, $kName)) {
                                    $pageHidesField = true;
                                }
                            } else {
                                $pageHidesField = true;
                            }

                            if ($pageHidesField) {
                                $recordedLeakKeys[$leakKey] = true;
                                $apiStats['leaks_found']++;
                                $findings[] = [
                                    'severity' => 'red',
                                    'kind'     => 'api_sensitive_leak',
                                    'file'     => "site/$apiFile",
                                    'line'     => 1,
                                    'user'     => $userKey,
                                    'url'      => "$apiFile?$qs",
                                    'detail'   => "مفتاح حساس ($kName) يرجع في API لغير المالك أو مع قفل الأرقام، بينما الصفحة تخفيه أو تحجبه",
                                ];
                            }
                            break;
                        }
                    }
                }
            }
        }
    }

    // فحص POST probe بدون CSRF وعلى part غير موجود
    // بيوزر عادي (كاشير)
    $cashierUser = $users['cashier'];
    $probeRes = executeWorker($phpBin, $apiFile, 'cashier', $cashierUser, "part=__probe__", 'POST', '', $outDir, $tempDir);
    $probeCode = $probeRes['meta']['status_code'] ?? 200;

    if (!in_array($probeCode, [400, 403, 404, 405], true)) {
        $findings[] = [
            'severity' => 'red',
            'kind'     => 'api_post_no_csrf_accepted',
            'file'     => "site/$apiFile",
            'line'     => 1,
            'user'     => 'cashier',
            'url'      => "$apiFile?part=__probe__",
            'detail'   => "طلب POST بدون CSRF على part غير موجود لم يرفض بـ 400/403/405 ورجع كود $probeCode",
        ];
    }
}
echo "  - تم فحص {$apiStats['tested_parts']} جزء/تاب في واجهات الـ APIs.\n";
echo "  - التسريبات الحساسة المكتشفة: {$apiStats['leaks_found']}\n\n";

// 6. التحقق النهائي من الإشعارات
echo "[6/6] التحقق من الإشعارات بعد انتهاء الزحف...\n";
$pqAfter = (int) $pdo->query("SELECT COUNT(*) FROM push_queue")->fetchColumn();
$notifAfter = (int) $pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
echo "  - push_queue بعد الزحف: $pqAfter (الزيادة: " . ($pqAfter - $pqBefore) . ")\n";
echo "  - notifications بعد الزحف: $notifAfter (الزيادة: " . ($notifAfter - $notifBefore) . ")\n";

if ($pqAfter > $pqBefore) {
    $diff = $pqAfter - $pqBefore;
    $findings[] = [
        'severity' => 'red',
        'kind'     => 'push_queue_leak',
        'file'     => 'push_queue',
        'line'     => 1,
        'user'     => 'crawler',
        'url'      => 'crawl_session',
        'detail'   => "حدثت زيادة في طابور الإشعارات push_queue بمقدار $diff أثناء الزحف بالقراءة!",
    ];
}
if ($notifAfter > $notifBefore) {
    $diff = $notifAfter - $notifBefore;
    $findings[] = [
        'severity' => 'red',
        'kind'     => 'notifications_leak',
        'file'     => 'notifications',
        'line'     => 1,
        'user'     => 'crawler',
        'url'      => 'crawl_session',
        'detail'   => "حدثت زيادة في جدول الإشعارات notifications بمقدار $diff أثناء الزحف بالقراءة!",
    ];
}

// حفظ النتائج
// ترتيب الصفحات الأبطأ والأكثر استعلامات
usort($crawlStats['slow_pages'], fn($a, $b) => $b['time_ms'] <=> $a['time_ms']);
usort($crawlStats['heavy_queries'], fn($a, $b) => $b['questions'] <=> $a['questions']);

$crawlStats['slow_pages'] = array_slice($crawlStats['slow_pages'], 0, 20);
$crawlStats['heavy_queries'] = array_slice($crawlStats['heavy_queries'], 0, 20);

file_put_contents("$outDir/crawl_findings.json", json_encode($findings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
file_put_contents("$outDir/crawl_summary.json", json_encode([
    'stats'    => $crawlStats,
    'api'      => $apiStats,
    'push'     => [
        'pq_before'     => $pqBefore,
        'pq_after'      => $pqAfter,
        'notif_before'  => $notifBefore,
        'notif_after'   => $notifAfter,
    ],
    'findings_count' => count($findings),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\n>>> اكتمل الجزء أ بنجاح. النتائج محفوظة في: $outDir <<<\n";
