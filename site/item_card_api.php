<?php
/**
 * item_card_api.php — داتا كارت الصنف (بريف ٢١٢)
 * نظام الفجالة ستور المالي
 *
 *   ?q=…                                  ← بحث (باركود/كود/اسم) → {exact, items}
 *   ?item_id=&from=&to=&sto=&type=…       ← الكارت كله JSON → {card}
 *   ?part=xlsx&item_id=&from=&to=&sto=…   ← ملف إكسل للحركات (مش JSON)
 *
 * 🔴 الصلاحية هنا في الـAPI نفسه — مش بس في الصفحة. من غيرها أي حد
 *    يشوف التكلفة والأسعار.
 * 🔴 قراية بس — مفيش أي كتابة.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/site_profile.php';
if (!site_profile()['true_integration']) { http_response_code(404); exit; }


define('API_MODE', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_lib.php';
require_once __DIR__ . '/item_card_lib.php';

$part = (string) ($_GET['part'] ?? '');

// ===== تصدير الإكسيل =====
// ملف مش رد JSON، فبيتعالج **قبل** api_begin (اللي بيقفل نوع الرد على JSON).
if ($part === 'xlsx') {
    require_login();
    if (!can('reports:item_movement') && !can('item_card')) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('غير مصرّح');
    }
    if (view_all_borrowed('reports:item_movement') || view_all_borrowed('item_card')) {
        $GLOBALS['VIEW_ALL_PAGE'] = true;
    }
    api_method('GET');

    $itemId = (int) ($_GET['item_id'] ?? 0);
    $card   = ic_card(
        $itemId,
        isset($_GET['from']) ? (string) $_GET['from'] : null,
        isset($_GET['to']) ? (string) $_GET['to'] : null,
        (int) ($_GET['sto'] ?? 0),
        (string) ($_GET['type'] ?? '')
    );
    if ($card === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('الصنف مش موجود');
    }

    require_once __DIR__ . '/xlsx_lib.php';

    $x = new SimpleXlsx('حركة الصنف');
    $x->setColumnTypes(['s', 's', 's', 's', 's', 'n', 's', 's']);
    $x->addRow([
        'التاريخ', 'المخزن', 'النوع', 'الطرف التاني', 'المستند',
        'الكمية بالقطعة', 'الوحدة الأصلية', 'السعر',
    ], true);

    foreach ($card['movements'] as $m) {
        $x->addRow([
            $m['date'],
            $m['store'],
            $m['label'],
            $m['party'],
            $m['doc'],
            $m['qty'],
            $m['unit'],
            // السعر جوه قفل الأرقام الحساسة — في الإكسيل كمان
            $m['price'] === null ? 'مقفول' : $m['price'],
        ]);
    }

    // صف فاضي + ملخّص الفترة
    $x->addBlank();
    $x->addRow([
        'إجمالي حركة الفترة',
        '', '', '', '',
        (float) $card['totals']['moved'],
        'من ' . $card['period']['from'] . ' لـ ' . $card['period']['to'],
        '',
    ]);

    $x->download(
        'item_card_' . (string) $itemId . '_' . $card['period']['from'] . '_' . $card['period']['to'] . '.xlsx'
    );
    exit;
}

api_begin();
require_login();
if (!can('reports:item_movement') && !can('item_card')) {
    api_fail('مالكش صلاحية', 403, 'forbidden');
}
if (view_all_borrowed('reports:item_movement') || view_all_borrowed('item_card')) {
    $GLOBALS['VIEW_ALL_PAGE'] = true;
}
api_method('GET');

// ===== بحث =====
if (isset($_GET['q']) && trim((string) $_GET['q']) !== '') {
    $found = ic_search((string) $_GET['q'], 20);
    api_ok([
        'exact' => $found['exact'],
        'items' => $found['items'],
    ]);
}

// ===== الكارت =====
$itemId = (int) ($_GET['item_id'] ?? 0);
if ($itemId <= 0) {
    api_fail('ابعت رقم الصنف أو ابحث عنه', 400, 'bad_request');
}

$card = ic_card(
    $itemId,
    isset($_GET['from']) ? (string) $_GET['from'] : null,
    isset($_GET['to']) ? (string) $_GET['to'] : null,
    (int) ($_GET['sto'] ?? 0),
    (string) ($_GET['type'] ?? '')
);

// «مش موجود» نتيجة عادية مش خطأ
api_ok(['card' => $card]);
