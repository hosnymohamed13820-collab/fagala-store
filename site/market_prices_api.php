<?php
/**
 * market_prices_api.php — داتا «أسعار السوق» (بريف ١٤١)
 * نظام الفجالة ستور المالي
 *
 *   ?part=search&term=…   ← {rows}            (GET)
 *   ?part=bulk_preview    ← {count, …}        (GET)
 *   ?part=<عملية>  (POST) ← {…}               (fetch · link · unlink · ignore
 *                                              · unignore · bulk_link
 *                                              · bulk_undo · renorm)
 *
 * الشاشة (market_prices.php) لسه على حالها — الـAPI ده بينادي نفس دوال
 * market_lib.php بالحرف: نفس تحويلات الأنواع ونفس مفاتيح الرد. المنطق
 * كله في المكتبة، مفيش سطر منه متعاد هنا.
 */
declare(strict_types=1);

define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
api_begin();
require_login();

// نفس حماية الصفحة بالظبط: require_permission('shortage') في market_prices.php
api_require('shortage');

require_once __DIR__ . '/book_lib.php';
require_once __DIR__ . '/market_lib.php';

$uid = (int) (current_user()['id'] ?? 0);

// نفس فحص الصفحة: الجداول لسه ما اتعملتش = مفيش داتا أصلاً
if (!mk_ready()) {
    api_fail('الجداول لسه ما اتعملتش — شغّل upgrade_market_prices.sql.', 503, 'not_ready');
}

$part = (string) ($_GET['part'] ?? '');

// ===== القراءة — GET بس =====
switch ($part) {
    case 'search':
        api_method('GET');
        api_ok(['rows' => mk_search_products((string) ($_GET['term'] ?? ''), 20)]);

    case 'bulk_preview':
        api_method('GET');
        api_ok(mk_bulk_link(MARKET_STRONG, $uid, true));
}

// ===== الكتابة — POST بس، بـCSRF ومحمي من التكرار =====
$writes = ['fetch', 'link', 'unlink', 'ignore', 'unignore',
           'bulk_link', 'bulk_undo', 'renorm'];

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
        case 'fetch':
            $res = mk_fetch_all($uid);
            if (!$res['ok']) {
                throw new RuntimeException((string) $res['error']);
            }
            api_ok(['products' => $res['products']]);

        case 'link':
            mk_link(
                (int) ($in['item_id'] ?? 0),
                (int) ($in['market_id'] ?? 0),
                isset($in['score']) ? (float) $in['score'] : null,
                $uid
            );
            api_ok(null);

        case 'unlink':
            mk_unlink((int) ($in['item_id'] ?? 0));
            api_ok(null);

        case 'ignore':
            mk_ignore((int) ($in['item_id'] ?? 0), $uid);
            api_ok(null);

        case 'unignore':
            mk_unignore((int) ($in['item_id'] ?? 0));
            api_ok(null);

        case 'bulk_link':
            // العلامة قبل الربط — عشان التراجع يمسك اللي اتربط في العملية دي
            $mark = date('Y-m-d H:i:s');
            $r    = mk_bulk_link(MARKET_STRONG, $uid, false);
            api_ok(['mark' => $mark] + $r);

        case 'bulk_undo':
            $since = (string) ($in['since'] ?? '');
            if ($since === '') {
                throw new RuntimeException('مفيش وقت للتراجع.');
            }
            $n = mk_bulk_unlink_since($since);
            api_ok(['removed' => $n]);

        case 'renorm':
            $n = mk_renorm_all();
            api_ok(['updated' => $n]);
    }
} catch (RuntimeException $e) {
    api_fail($e->getMessage(), 422, 'invalid');
}
