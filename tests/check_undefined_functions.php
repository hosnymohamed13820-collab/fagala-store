<?php
/**
 * check_undefined_functions.php — دالة متنادية ومش متعرّفة في أي حتة
 * =====================================================================
 *  ليه: `php -l` بيفحص البنية بس. نداء لدالة مش موجودة بيعدّي الجيت
 *  ويوقّع الصفحة على السيرفر (Fatal: Call to undefined function).
 *  حصلت ٢٤ سبتمبر ٢٠٢٦: `current_user_id()` في settings.php (بريف ٥٤)
 *  — زرار «فك الحارس» كان بيقول «تعذّر الاتصال بالسيرفر» ٦ مرات.
 *
 *  بيفحص site/ و ashraqa/ كل واحد لوحده (كل موقع ليه دواله).
 *  المتعرّف = دوال PHP نفسها + أي `function x(` في ملفات الموقع
 *            + دوال config.php اللي على السيرفر بس (مش في الريبو — تحت)
 *            + tests/check_undefined_functions_allow.txt (دوال إضافات
 *              موجودة على السيرفر ومش على الجهاز، زي sqlsrv_*).
 *  المتنادي = اسم بعده «(» ومش قبله -> أو :: أو new أو function.
 * =====================================================================
 */

// دوال config.php على السيرفر (الملف مش في الريبو). لو ضفت دالة هناك ضيفها هنا.
const CONFIG_FUNCS = ['bridge_query', 'db', 'setting', 'shortage_supervisor_id',
                      'sqlsrv_check', 'sqlsrv_db', 'sqlsrv_fix_encoding', 'sqlsrv_query'];

$root  = dirname(__DIR__);
$allow = [];
$allowFile = __DIR__ . '/check_undefined_functions_allow.txt';
if (is_file($allowFile)) {
    foreach (file($allowFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        $l = trim(preg_replace('/#.*/', '', $l));
        if ($l !== '') $allow[strtolower($l)] = true;
    }
}
$internal = array_flip(get_defined_functions()['internal']);

$skipPrev = [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_NULLSAFE_OBJECT_OPERATOR, T_CONST];
$bad = 0;

foreach (['site', 'ashraqa'] as $siteDir) {
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$siteDir", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = str_replace('\\', '/', $f->getPathname());
        if (substr($p, -4) !== '.php') continue;
        if (preg_match('#/(vendor|uploads|data|downloads)/#', $p)) continue;
        $files[] = $p;
    }

    $defined = [];
    $calls   = [];   // name => [file:line, ...]
    foreach ($files as $p) {
        $toks = token_get_all(file_get_contents($p));
        $sig  = [];  // التوكنز المهمة بس (من غير مسافات وتعليقات)
        foreach ($toks as $t) {
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
            $sig[] = $t;
        }
        $n = count($sig);
        for ($i = 0; $i < $n; $i++) {
            $t = $sig[$i];
            if (!is_array($t) || $t[0] !== T_STRING) continue;
            $name = strtolower($t[1]);
            $prev = $sig[$i - 1] ?? null;
            $next = $sig[$i + 1] ?? null;
            if (is_array($prev) && $prev[0] === T_FUNCTION) { $defined[$name] = true; continue; }
            // function &name(  — الـ& توكن لوحده في PHP 8.1+
            if (($prev === '&' || (is_array($prev) && in_array(token_name($prev[0]), ['T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG', 'T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG'], true))) && is_array($sig[$i - 2] ?? null) && $sig[$i - 2][0] === T_FUNCTION) { $defined[$name] = true; continue; }
            if ($next !== '(') continue;
            if (is_array($prev) && in_array($prev[0], $skipPrev, true)) continue;
            $calls[$name][] = substr($p, strlen($root) + 1) . ':' . $t[2];
        }
    }

    foreach ($calls as $name => $where) {
        if (isset($internal[$name]) || isset($defined[$name]) || isset($allow[$name])) continue;
        if (in_array($name, CONFIG_FUNCS, true)) continue;
        echo "  ❌ {$name}() — مش متعرّفة في {$siteDir}/ · " . implode(' · ', array_slice($where, 0, 3))
           . (count($where) > 3 ? ' …+' . (count($where) - 3) : '') . "\n";
        $bad++;
    }
}

if ($bad === 0) {
    echo "✅ كل الدوال المتنادية متعرّفة (site + ashraqa)\n";
    exit(0);
}
echo "❌ {$bad} دالة متنادية ومش متعرّفة — الصفحة هتقع على السيرفر\n";
exit(1);
