<?php
/** بريف ٨٤٧: اختبارات معزولة من غير أسرار أو اتصال بقاعدة المشروع. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);

// بنحمّل الدوال الحقيقية بس، من غير bootstrap الجلسة وقاعدة البيانات.
function sp_functions(string $path, array $wanted): void
{
    $tokens = token_get_all(file_get_contents($path));
    for ($i = 0; $i < count($tokens); $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
        $code = 'function'; $name = ''; $depth = 0; $started = false;
        for ($i++; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;
            if ($name === '' && is_array($token) && $token[0] === T_STRING) $name = $text;
            $code .= $text;
            if ($text === '{') { $depth++; $started = true; }
            if ($text === '}') $depth--;
            if ($started && $depth === 0) break;
        }
        if ($name === 'all_modules' && ($GLOBALS['argv'][2] ?? '') === 'future') {
            $code = str_replace('return $modules;', "return \$modules + ['future_module' => ['label' => 'اختبار', 'url' => '', 'icon' => 'ti-eye']];", $code);
        }
        if (in_array($name, $wanted, true)) eval($code);
    }
}
function sp_check(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}
function sp_child(array $args): string
{
    $process = proc_open(array_merge([PHP_BINARY, __FILE__], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    sp_check(proc_close($process) === 0 && $error === '', 'child failed: ' . $error . $out);
    return $out;
}
$mode = $argv[1] ?? '';
if ($mode !== '') define('SITE_ID', $mode === 'invalid' ? 'other' : $mode);
if ($mode === 'invalid') {
    try { require $root . '/site/includes/site_profile.php'; site_id(); }
    catch (RuntimeException $e) { echo 'invalid rejected'; exit; }
    throw new RuntimeException('invalid SITE_ID accepted');
}
require_once $root . '/site/includes/site_profile.php';
if (($argv[2] ?? '') === 'session') {
    // تخزين الجلسة في الذاكرة عشان الاختبار مايكتبش ملفات أو يحتاج إعداد السيرفر.
    session_set_save_handler(new class implements SessionHandlerInterface {
        public function open(string $path, string $name): bool { return true; }
        public function close(): bool { return true; }
        public function read(string $id): string { return ''; }
        public function write(string $id, string $data): bool { return true; }
        public function destroy(string $id): bool { return true; }
        public function gc(int $max_lifetime): int|false { return 0; }
    });
    require $root . '/site/auth.php';
    sp_check(session_name() === ($mode === 'ashraqa' ? 'ASHSESS' : 'FGSESS'), 'actual auth session');
    echo 'session ok'; exit;
}
sp_functions($root . '/site/auth.php', ['all_modules', 'module_parts', 'is_logged_in', 'is_real_owner', 'can', 'can_module', 'require_permission', 'require_module_access']);
if (($argv[2] ?? '') === 'future') {
    $_SESSION = ['user' => ['id' => 1, 'is_owner' => true], 'permissions' => ['view_all']];
    sp_check(isset(all_modules()['future_module']), 'new module actually in registry');
    sp_check(!can('future_module') && !can_module('future_module') && !isset(site_enabled_modules()['future_module']), 'new registry module closed');
    echo 'future closed'; exit;
}
if (($argv[2] ?? '') === 'endpoint') {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    register_shutdown_function(static function () { echo '|status=' . http_response_code(); });
    require $root . '/site/' . $argv[3];
    exit;
}
if (($argv[2] ?? '') === 'api') {
    sp_functions($root . '/site/api_lib.php', ['api_require', 'api_fail']);
    register_shutdown_function(static function () { echo '|status=' . http_response_code(); });
    $_SESSION = ['user' => ['id' => 1, 'is_owner' => true], 'permissions' => ['view_all']];
    api_require('pos');
    throw new RuntimeException('api_require allowed pos');
}
if (($argv[2] ?? '') === 'require') {
    register_shutdown_function(static function () { echo '|status=' . http_response_code(); });
    $guard = $argv[3]; $guard('pos');
    throw new RuntimeException('guard allowed pos');
}
// قايمة ثابتة من all_modules قبل التعديل؛ أي اختفاء في الفجالة يفشّل الاختبار.
$baseline = explode(' ', 'dashboard price_inquiry remote_scan cashier machines custody review treasury hr attendance tasks tasks_issue suppliers branch_recon invoices receiving branch_invoices stocktake labels shortage reorder analysis item_sales reports definitions settings errors ops sms_inbox calls stock_ledger purchase_docs item_master supplier_card sale_prices customers pos transfers view_all');
$allow = explode(' ', 'dashboard cashier review treasury custody hr attendance tasks tasks_issue suppliers stocktake reports definitions settings errors sms_inbox view_all ashraqa_purchases');
$modules = array_keys(all_modules());
if ($mode === 'ashraqa') {
    $enabled = array_keys(site_enabled_modules()); sort($enabled); $expected = $allow; sort($expected);
    sp_check($enabled === $expected, 'exact allowlist');
    foreach ([true, false] as $owner) {
        $_SESSION = ['user' => ['id' => 1, 'is_owner' => $owner], 'permissions' => ['view_all']];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        foreach ($modules as $module) {
            sp_check(can($module) === in_array($module, $allow, true), 'can ' . $module);
            sp_check(can_module($module) === in_array($module, $allow, true), 'can_module ' . $module);
        }
        foreach (site_profile()['disabled_parts'] as $part) sp_check(!can($part), 'part ' . $part);
    }
    sp_check(site_profile()['session_name'] === 'ASHSESS' && site_profile()['redux_sync'], 'ashraqa identity');
    sp_check(sp_child(['ashraqa', 'future']) === 'future closed', 'future registry module');
    sp_check(sp_child(['ashraqa', 'session']) === 'session ok', 'actual ashraqa session');
    foreach (['require_permission', 'require_module_access'] as $guard) sp_check(str_contains(sp_child(['ashraqa', 'require', $guard]), '|status=403'), $guard . ' 403');
    sp_check(str_contains(sp_child(['ashraqa', 'api']), '|status=403'), 'api 403');
    foreach (['sync_branch.php', 'sync_beat.php', 'agent_update.php', 'fifo_cron.php', 'fifo_report_api.php', 'rabt_guard.php', 'rabt_guard_api.php', 'guard_feed.php', 'guard_note.php', 'pos_api.php', 'pos_migrate.php', 'sync_guard.php', 'daily_summary.php'] as $endpoint) {
        sp_check(sp_child(['ashraqa', 'endpoint', $endpoint]) === '|status=404', $endpoint . ' before dependencies');
    }
    foreach (glob($root . '/site/*.php') as $path) {
        $source = file_get_contents($path);
        if (basename($path) !== 'sync_hq.php' && str_contains($source, "if (!site_profile()['true_integration']) { http_response_code(404); exit; }")) {
            sp_check(sp_child(['ashraqa', 'endpoint', basename($path)]) === '|status=404', basename($path) . ' 404');
        }
    }
    foreach (['sync_ashraqa.php', 'sync_ashraqa_purchases.php', 'sync_hq.php'] as $endpoint) {
        sp_check(str_contains(sp_child(['ashraqa', 'endpoint', $endpoint]), '|status=405'), $endpoint . ' Redux reachable, GET rejected');
    }
    sp_functions($root . '/site/branch_invoices_lib.php', ['br_inv_can_view']);
    sp_check(!br_inv_can_view(5), 'branch invoice owner bypass closed');
    sp_functions($root . '/site/users_lib.php', ['sanitize_user_permissions']);
    sp_check(sanitize_user_permissions(['pos', 'reports:daily', 'hr:employees']) === ['hr:employees'], 'permissions save filtered');
    echo "PASS ashraqa allowlist, owner/view_all, future module, parts, 403/404\n";
    exit;
}
sp_check(site_id() === 'fagala' && $modules === $baseline, 'fagala baseline modules');
sp_check(array_keys(site_enabled_modules()) === $baseline, 'fagala menu baseline');
sp_check(site_profile()['session_name'] === 'FGSESS' && site_profile()['app'] === 'fagala', 'fagala session baseline');
sp_check(sp_child(['fagala', 'session']) === 'session ok', 'actual fagala session');
sp_check(site_profile()['manifest'] === 'manifest.json?v=4' && site_profile()['theme_css'] === null, 'fagala PWA baseline');
foreach (['apple-touch-icon' => 'apple-touch-icon.png', 'favicon-32' => 'favicon-32.png', 'favicon' => 'favicon.ico', 'icon-192' => 'icon-192.png'] as $key => $file) sp_check(site_profile()['icons'][$key] === $file . '?v=4', 'fagala icon ' . $key);
// منطق can القديم ثابت: أونر، مشاهدة GET/HEAD، موديول كامل أو جزء مطابق.
foreach ([false, true] as $logged) foreach ([false, true] as $owner) foreach (['GET', 'HEAD', 'POST'] as $method) foreach ([[], ['hr'], ['hr:salaries'], ['view_all'], ['pos', 'reports:daily']] as $permissions) {
    $_SESSION = ['permissions' => $permissions];
    if ($logged) $_SESSION['user'] = ['id' => 1, 'is_owner' => $owner];
    $_SERVER['REQUEST_METHOD'] = $method;
    foreach (array_merge($baseline, ['hr:salaries', 'hr:employees', 'reports:daily', 'unknown']) as $key) {
        $module = explode(':', $key, 2)[0];
        $expected = $logged && ($owner || (in_array('view_all', $permissions, true) && in_array($method, ['GET', 'HEAD'], true)) || in_array($key, $permissions, true) || (str_contains($key, ':') && in_array($module, $permissions, true)));
        sp_check(can($key) === $expected, 'fagala can parity ' . $key);
    }
}
sp_functions($root . '/site/hr_lib.php', ['hr_get_employees_tab', 'hr_get_employees_list']);
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE employees (id INT, full_name TEXT, job_title TEXT, base_salary REAL, expected_in TEXT, expected_out TEXT, branch_id INT, is_active INT, national_id TEXT, phone TEXT, expected_monthly_hours INT, salary_mode TEXT);
    CREATE TABLE branches (id INT, name TEXT); CREATE TABLE employee_advances (employee_id INT, remaining REAL, amount REAL, status TEXT);
    INSERT INTO branches VALUES(1,'فرع'); INSERT INTO employees VALUES(1,'موظف','محاسب',3000,'09:00','17:00',1,1,'secret','secret',200,'hourly');
    INSERT INTO employee_advances VALUES(1,25,50,'pending');");
$tab = hr_get_employees_tab($pdo)[0];
sp_check(array_keys($tab) === ['full_name','job_title','base_salary','expected_in','expected_out','branch_name','pending_advance'], 'employees exact projection');
sp_check(!isset($tab['national_id'], $tab['phone']), 'employees no private fields');
$full = hr_get_employees_list($pdo)[0];
sp_check(isset($full['expected_monthly_hours'], $full['salary_mode'], $full['national_id']), 'payroll reader unchanged');
foreach ($tab as $key => $value) sp_check($full[$key] === $value, 'employee tab parity ' . $key);
$apiSource = file_get_contents($root . '/site/hr_api.php');
sp_check(substr_count($apiSource, 'hr_api_mask_employee_salary(hr_get_employees_tab($pdo))') === 2, 'employees and all use projection');
sp_functions($root . '/site/hr_api.php', ['hr_api_mask_employee_salary']);
function sens_unlocked(): bool { return false; }
sp_check(!array_key_exists('base_salary', hr_api_mask_employee_salary([$tab])[0]), 'salary masked on server');
echo "PASS fagala modules/session/PWA and can parity; employees projection/payroll reader/mask\n";
echo sp_child(['ashraqa']);
sp_check(sp_child(['invalid']) === 'invalid rejected', 'invalid identity');
echo "PASS invalid identity\n";
