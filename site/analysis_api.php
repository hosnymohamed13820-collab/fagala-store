<?php
/**
 * analysis_api.php — API تحليل الموسم (بريف ١٥٨)
 * نظام الفجالة ستور المالي
 *
 *   ?part=batches · batch · report · summary · cats · stores · batch_stores  (GET)
 *   ?part=new_batch · del_batch · build_erp                               (POST)
 *
 * الشاشة (analysis.php) لسه على حالها — الـAPI ده بينادي نفس دوال
 * analysis_lib.php بالحرف: نفس تحويلات الأنواع ونفس مفاتيح الرد. المنطق
 * كله في المكتبة، مفيش سطر منه متعاد هنا.
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

// نفس حماية الصفحة بالظبط: require_permission('analysis') في analysis.php
api_require('analysis');

require_once __DIR__ . '/analysis_lib.php';

$uid = (int) (current_user()['id'] ?? 0);

// نفس فحص الصفحة: الجداول لسه ما اتعملتش = مفيش داتا أصلاً
if (!an_ready()) {
    api_fail('الجداول لسه ما اتعملتش — شغّل upgrade_shortage.sql.', 503, 'not_ready');
}

$part = (string) ($_GET['part'] ?? '');

// رقم الرفعة — من الـquery أو من جسم الطلب، وثلاثة أسماء مقبولة
$batchIdOf = static function (array $src): int {
    foreach (['batch', 'batch_id', 'id'] as $k) {
        $v = trim((string) ($src[$k] ?? ''));
        if ($v !== '') return (int) $v;
    }
    return 0;
};

// حالة التصنيف والتنقيلات — زي الصفحة بالظبط: 1 = احسبها · 0 = الأرقام بس
$withStOf = static function (array $src): bool {
    $v = strtolower(trim((string) ($src['st'] ?? $src['with_st'] ?? '1')));
    return ($v === '1' || $v === 'true');
};

// ===== القراءة — GET بس =====
switch ($part) {
    case 'batches':
        api_method('GET');
        api_ok(an_batches());

    case 'batch':
        api_method('GET');
        // «مش موجود» نتيجة عادية مش خطأ (زي stock_query_api)
        api_ok(an_batch($batchIdOf($_GET)));

    case 'report':
        api_method('GET');
        api_ok(an_report(
            $batchIdOf($_GET),
            (int) ($_GET['cat'] ?? 0),
            $withStOf($_GET)
        ));

    case 'summary':
        api_method('GET');
        $rep = an_report(
            $batchIdOf($_GET),
            (int) ($_GET['cat'] ?? 0),
            $withStOf($_GET)
        );
        api_ok(an_summary($rep['rows']));

    case 'cats':
        api_method('GET');
        api_ok(an_cats($batchIdOf($_GET)));

    case 'stores':
        api_method('GET');
        api_ok(an_stores());

    case 'batch_stores':
        api_method('GET');
        api_ok(an_batch_stores($batchIdOf($_GET)));
}

// ===== الكتابة — POST بس، بـCSRF ومحمي من التكرار =====
$writes = ['new_batch', 'del_batch', 'build_erp'];

if (!in_array($part, $writes, true)) {
    api_fail('جزء غير معروف', 400, 'bad_request');
}

api_method('POST');
api_csrf();
api_idempotent();

$in = api_input();

// الدوال بترمي RuntimeException برسايل للمستخدم — بنحوّلها 422.
// أي Throwable تاني مايتلقطش هنا ويروح لمعالج api_begin().
try {
    switch ($part) {
        case 'new_batch':
            $bid = an_batch_create(
                trim((string) ($in['label'] ?? '')),
                trim((string) ($in['period_from'] ?? '')),
                trim((string) ($in['period_to'] ?? '')),
                $uid
            );
            api_ok(['id' => $bid]);

        case 'del_batch':
            api_ok(['deleted' => an_batch_delete($batchIdOf($in))]);

        case 'build_erp':
            api_ok(an_import_erp(
                $batchIdOf($in),
                trim((string) ($in['period_from'] ?? '')),
                trim((string) ($in['period_to'] ?? ''))
            ));
    }
} catch (RuntimeException $e) {
    api_fail($e->getMessage(), 422, 'invalid');
}
