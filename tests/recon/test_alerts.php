<?php
/**
 * tests/recon/test_alerts.php
 * اختبار خوارزمية تنبيهات مطابقة الفروع (recon_alerts_detect)
 * يغطي الحالات أ١ إلى أ١٤ في عقود الاكتمال للبريفين ٨٤ و ٨٥.
 *
 * 🔴 لا يتصل بقاعدة البيانات ولا يستدعي config.php أو recon_lib.php
 */

require_once __DIR__ . '/../../site/recon_alerts_lib.php';

$labels = [
    'hq'      => 'الإدارة',
    'degla'   => 'دجلة',
    'fagala'  => 'الفجالة',
    'abc'     => 'اي بي سي',
    'bonjour' => 'بونجور',
];

$today    = '2026-10-20';
$showFrom = '2026-10-01';
$showTo   = '2026-10-20';

$testsPassed = 0;
$totalTests  = 14;

function assertTest(bool $condition, string $testId, string $description, ?string $detail = null): bool
{
    global $testsPassed;
    if ($condition) {
        $testsPassed++;
        echo "  [PASS] {$testId}: {$description}\n";
        return true;
    } else {
        echo "  [FAIL] {$testId}: {$description}\n";
        if ($detail !== null) {
            echo "         Detail: {$detail}\n";
        }
        return false;
    }
}

echo "=== تشغيل اختبارات تنبيهات المطابقة (أ١ - أ١٤) ===\n";

// -------------------------------------------------------------------------
// أ١: دجلة شراء 10 من hq (5/10، صنفين 1×5 و2×3) + hq مردود 20 لدجلة بنفس الأصناف (5/10)
// المتوقع: صفر تنبيهات
// -------------------------------------------------------------------------
$docs_a1 = [
    [
        'key'      => '10_0',
        'sth_id'   => 10,
        'suffix'   => '0',
        'party'    => 'degla',
        'counter'  => 'hq',
        'type'     => 2, // شراء
        'no'       => '10',
        'date'     => '2026-10-05',
        'amount'   => 50.0 + 60.0,
        'unposted' => false,
        'items'    => [
            1 => ['q' => 5.0, 'p' => 10.0, 'name' => 'صنف 1'],
            2 => ['q' => 3.0, 'p' => 20.0, 'name' => 'صنف 2'],
        ],
    ],
    [
        'key'      => '20_0',
        'sth_id'   => 20,
        'suffix'   => '0',
        'party'    => 'hq',
        'counter'  => 'degla',
        'type'     => 6, // مردود
        'no'       => '20',
        'date'     => '2026-10-05',
        'amount'   => 50.0 + 60.0,
        'unposted' => false,
        'items'    => [
            1 => ['q' => 5.0, 'p' => 10.0, 'name' => 'صنف 1'],
            2 => ['q' => 3.0, 'p' => 20.0, 'name' => 'صنف 2'],
        ],
    ],
];
$res_a1 = recon_alerts_detect($docs_a1, $showFrom, $showTo, $today, $labels);
assertTest(count($res_a1) === 0, 'أ١', 'دجلة شراء 10 و hq مردود 20 متطابقين ← صفر تنبيهات', 'عدد التنبيهات: ' . count($res_a1));

// -------------------------------------------------------------------------
// أ٢: زي أ١ + دجلة شراء 11 بنفس الأصناف والكميات (8/10)
// المتوقع: تنبيه واحد dup أحمر، no=11
// -------------------------------------------------------------------------
$docs_a2 = $docs_a1;
$docs_a2[] = [
    'key'      => '11_0',
    'sth_id'   => 11,
    'suffix'   => '0',
    'party'    => 'degla',
    'counter'  => 'hq',
    'type'     => 2,
    'no'       => '11',
    'date'     => '2026-10-08',
    'amount'   => 110.0,
    'unposted' => false,
    'items'    => [
        1 => ['q' => 5.0, 'p' => 10.0, 'name' => 'صنف 1'],
        2 => ['q' => 3.0, 'p' => 20.0, 'name' => 'صنف 2'],
    ],
];
$res_a2 = recon_alerts_detect($docs_a2, $showFrom, $showTo, $today, $labels);
$pass_a2 = (count($res_a2) === 1 && $res_a2[0]['kind'] === 'dup' && $res_a2[0]['level'] === 'red' && in_array('11_0', $res_a2[0]['docs'], true));
assertTest($pass_a2, 'أ٢', 'تكرار دجلة شراء 11 ← تنبيه dup أحمر برقم 11', json_encode($res_a2, JSON_UNESCAPED_UNICODE));

// -------------------------------------------------------------------------
// أ٣: زي أ٢ + دجلة مردود 12 لـhq بنفس أصناف 11 (9/10)
// المتوقع: تنبيه واحد fixed_dup رمادي، مفيش أحمر
// -------------------------------------------------------------------------
$docs_a3 = $docs_a2;
$docs_a3[] = [
    'key'      => '12_0',
    'sth_id'   => 12,
    'suffix'   => '0',
    'party'    => 'degla',
    'counter'  => 'hq',
    'type'     => 6,
    'no'       => '12',
    'date'     => '2026-10-09',
    'amount'   => 110.0,
    'unposted' => false,
    'items'    => [
        1 => ['q' => 5.0, 'p' => 10.0, 'name' => 'صنف 1'],
        2 => ['q' => 3.0, 'p' => 20.0, 'name' => 'صنف 2'],
    ],
];
$res_a3 = recon_alerts_detect($docs_a3, $showFrom, $showTo, $today, $labels);
$pass_a3 = (count($res_a3) === 1 && $res_a3[0]['kind'] === 'fixed_dup' && $res_a3[0]['level'] === 'gray');
assertTest($pass_a3, 'أ٣', 'التكرار تم عكسه بمردود 12 ← تنبيه fixed_dup رمادي فقط', json_encode($res_a3, JSON_UNESCAPED_UNICODE));

// -------------------------------------------------------------------------
// أ٤: الفجالة مردود 46 على حساب abc (5/10، 5 أصناف) + hq شراء 350 من الفجالة بنفس الأصناف (6/10)، ومفيش حاجة عند abc
// المتوقع: تنبيه واحد wrong_acc: A=الفجالة، B=اي بي سي، C=الإدارة
// -------------------------------------------------------------------------
$fagala_items = [
    1 => ['q' => 2.0, 'p' => 15.0, 'name' => 'صنف A'],
    2 => ['q' => 4.0, 'p' => 25.0, 'name' => 'صنف B'],
    3 => ['q' => 1.0, 'p' => 50.0, 'name' => 'صنف C'],
    4 => ['q' => 10.0, 'p' => 5.0, 'name' => 'صنف D'],
    5 => ['q' => 3.0, 'p' => 30.0, 'name' => 'صنف E'],
];
$docs_a4 = [
    [
        'key'      => '46_0',
        'sth_id'   => 46,
        'suffix'   => '0',
        'party'    => 'fagala',
        'counter'  => 'abc',
        'type'     => 6,
        'no'       => '46',
        'date'     => '2026-10-05',
        'amount'   => 320.0,
        'unposted' => false,
        'items'    => $fagala_items,
    ],
    [
        'key'      => '350_0',
        'sth_id'   => 350,
        'suffix'   => '0',
        'party'    => 'hq',
        'counter'  => 'fagala',
        'type'     => 2,
        'no'       => '350',
        'date'     => '2026-10-06',
        'amount'   => 320.0,
        'unposted' => false,
        'items'    => $fagala_items,
    ],
];
$res_a4 = recon_alerts_detect($docs_a4, $showFrom, $showTo, $today, $labels);
$pass_a4 = (count($res_a4) === 1 && $res_a4[0]['kind'] === 'wrong_acc' && $res_a4[0]['level'] === 'red');
if ($pass_a4) {
    $text = $res_a4[0]['text'];
    if (strpos($text, 'الفجالة') === false || strpos($text, 'اي بي سي') === false || strpos($text, 'الإدارة') === false) {
        $pass_a4 = false;
    }
}
assertTest($pass_a4, 'أ٤', 'مردود على حساب غلط (الفجالة على abc والمقابل عند hq) ← wrong_acc أحمر', json_encode($res_a4, JSON_UNESCAPED_UNICODE));

// -------------------------------------------------------------------------
// أ٥: hq مردود 30 لدجلة (أصناف a,b,c,d,e) و31 (a,b,c,d,f) بنفس الكميات؛
// دجلة شراء 40 = نفس 31 بالظبط، و41 = نفس 30 بالظبط (كلهم في أكتوبر، 30 الأول)
// المتوقع: صفر تنبيهات (30↔41 و 31↔40 — مش 30↔40)
// -------------------------------------------------------------------------
$items_30 = [
    1 => ['q' => 1.0, 'p' => 10.0, 'name' => 'a'],
    2 => ['q' => 1.0, 'p' => 10.0, 'name' => 'b'],
    3 => ['q' => 1.0, 'p' => 10.0, 'name' => 'c'],
    4 => ['q' => 1.0, 'p' => 10.0, 'name' => 'd'],
    5 => ['q' => 1.0, 'p' => 10.0, 'name' => 'e'],
];
$items_31 = [
    1 => ['q' => 1.0, 'p' => 10.0, 'name' => 'a'],
    2 => ['q' => 1.0, 'p' => 10.0, 'name' => 'b'],
    3 => ['q' => 1.0, 'p' => 10.0, 'name' => 'c'],
    4 => ['q' => 1.0, 'p' => 10.0, 'name' => 'd'],
    6 => ['q' => 1.0, 'p' => 10.0, 'name' => 'f'],
];
$docs_a5 = [
    [
        'key'      => '30_0',
        'sth_id'   => 30,
        'suffix'   => '0',
        'party'    => 'hq',
        'counter'  => 'degla',
        'type'     => 6,
        'no'       => '30',
        'date'     => '2026-10-01',
        'amount'   => 50.0,
        'unposted' => false,
        'items'    => $items_30,
    ],
    [
        'key'      => '31_0',
        'sth_id'   => 31,
        'suffix'   => '0',
        'party'    => 'hq',
        'counter'  => 'degla',
        'type'     => 6,
        'no'       => '31',
        'date'     => '2026-10-02',
        'amount'   => 50.0,
        'unposted' => false,
        'items'    => $items_31,
    ],
    [
        'key'      => '40_0',
        'sth_id'   => 40,
        'suffix'   => '0',
        'party'    => 'degla',
        'counter'  => 'hq',
        'type'     => 2,
        'no'       => '40',
        'date'     => '2026-10-03',
        'amount'   => 50.0,
        'unposted' => false,
        'items'    => $items_31, // مطابق لـ 31
    ],
    [
        'key'      => '41_0',
        'sth_id'   => 41,
        'suffix'   => '0',
        'party'    => 'degla',
        'counter'  => 'hq',
        'type'     => 2,
        'no'       => '41',
        'date'     => '2026-10-04',
        'amount'   => 50.0,
        'unposted' => false,
        'items'    => $items_30, // مطابق لـ 30
    ],
];
$res_a5 = recon_alerts_detect($docs_a5, $showFrom, $showTo, $today, $labels);
assertTest(count($res_a5) === 0, 'أ٥', 'الربط الجشع Greedy بالتشابه يطابق (30↔41 و 31↔40) ← صفر تنبيهات', 'عدد التنبيهات: ' . count($res_a5));

// -------------------------------------------------------------------------
// أ٦: زي أ١ بس سعر صنف 1 عند دجلة 10 وعند hq 12 (كمية 5)
// المتوقع: price أصفر، الفرق 10.00
// -------------------------------------------------------------------------
$docs_a6 = [
    [
        'key'      => '10_0',
        'sth_id'   => 10,
        'suffix'   => '0',
        'party'    => 'degla',
        'counter'  => 'hq',
        'type'     => 2,
        'no'       => '10',
        'date'     => '2026-10-05',
        'amount'   => 5.0 * 10.0 + 3.0 * 20.0, // 110
        'unposted' => false,
        'items'    => [
            1 => ['q' => 5.0, 'p' => 10.0, 'name' => 'صنف 1'],
            2 => ['q' => 3.0, 'p' => 20.0, 'name' => 'صنف 2'],
        ],
    ],
    [
        'key'      => '20_0',
        'sth_id'   => 20,
        'suffix'   => '0',
        'party'    => 'hq',
        'counter'  => 'degla',
        'type'     => 6,
        'no'       => '20',
        'date'     => '2026-10-05',
        'amount'   => 5.0 * 12.0 + 3.0 * 20.0, // 120
        'unposted' => false,
        'items'    => [
            1 => ['q' => 5.0, 'p' => 12.0, 'name' => 'صنف 1'],
            2 => ['q' => 3.0, 'p' => 20.0, 'name' => 'صنف 2'],
        ],
    ],
];
$res_a6 = recon_alerts_detect($docs_a6, $showFrom, $showTo, $today, $labels);
$pass_a6 = (count($res_a6) === 1 && $res_a6[0]['kind'] === 'price' && $res_a6[0]['level'] === 'yellow' && abs($res_a6[0]['amount'] - 10.0) < 0.01);
assertTest($pass_a6, 'أ٦', 'فرق سعر صنف 1 (10 مقابل 12 على 5 قطع) ← price أصفر بقيمة 10.00', json_encode($res_a6, JSON_UNESCAPED_UNICODE));

// -------------------------------------------------------------------------
// أ٧: hq مردود 50 لدجلة (12/10)، ومفيش مقابل (اليوم 20/10 عدى 8 أيام)
// المتوقع: orphan أحمر (عدى 8 أيام)
// -------------------------------------------------------------------------
$docs_a7 = [
    [
        'key'      => '50_0',
        'sth_id'   => 50,
        'suffix'   => '0',
        'party'    => 'hq',
        'counter'  => 'degla',
        'type'     => 6,
        'no'       => '50',
        'date'     => '2026-10-12',
        'amount'   => 500.0,
        'unposted' => false,
        'items'    => [
            1 => ['q' => 10.0, 'p' => 50.0, 'name' => 'صنف X'],
        ],
    ],
];
$res_a7 = recon_alerts_detect($docs_a7, $showFrom, $showTo, $today, $labels);
$pass_a7 = (count($res_a7) === 1 && $res_a7[0]['kind'] === 'orphan' && $res_a7[0]['level'] === 'red');
assertTest($pass_a7, 'أ٧', 'فاتورة يتيمة مر عليها 8 أيام ← orphan أحمر', json_encode($res_a7, JSON_UNESCAPED_UNICODE));

// -------------------------------------------------------------------------
// أ٨: hq مردود 51 لدجلة (18/10)، ومفيش مقابل (اليوم 20/10 عدى يومين)
// المتوقع: pending رمادي
// -------------------------------------------------------------------------
$docs_a8 = [
    [
        'key'      => '51_0',
        'sth_id'   => 51,
        'suffix'   => '0',
        'party'    => 'hq',
        'counter'  => 'degla',
        'type'     => 6,
        'no'       => '51',
        'date'     => '2026-10-18',
        'amount'   => 300.0,
        'unposted' => false,
        'items'    => [
            1 => ['q' => 6.0, 'p' => 50.0, 'name' => 'صنف Y'],
        ],
    ],
];
$res_a8 = recon_alerts_detect($docs_a8, $showFrom, $showTo, $today, $labels);
$pass_a8 = (count($res_a8) === 1 && $res_a8[0]['kind'] === 'pending' && $res_a8[0]['level'] === 'gray');
assertTest($pass_a8, 'أ٨', 'فاتورة جديدة مر عليها يومين فقط ← pending رمادي', json_encode($res_a8, JSON_UNESCAPED_UNICODE));

// -------------------------------------------------------------------------
// أ٩: hq مردود 60 لدجلة (أصناف 1,2,3,4) + 61 (5,6,7,8)؛
// دجلة شراء 70 (1,2,5,6) + 71 (3,4,7,8)، نفس الكميات والأسعار
// المتوقع: صفر تنبيهات (مجموعة واحدة متطابقة)
// -------------------------------------------------------------------------
$docs_a9 = [
    [
        'key'      => '60_0',
        'sth_id'   => 60,
        'suffix'   => '0',
        'party'    => 'hq',
        'counter'  => 'degla',
        'type'     => 6,
        'no'       => '60',
        'date'     => '2026-10-05',
        'amount'   => 40.0,
        'unposted' => false,
        'items'    => [
            1 => ['q' => 1.0, 'p' => 10.0, 'name' => '1'],
            2 => ['q' => 1.0, 'p' => 10.0, 'name' => '2'],
            3 => ['q' => 1.0, 'p' => 10.0, 'name' => '3'],
            4 => ['q' => 1.0, 'p' => 10.0, 'name' => '4'],
        ],
    ],
    [
        'key'      => '61_0',
        'sth_id'   => 61,
        'suffix'   => '0',
        'party'    => 'hq',
        'counter'  => 'degla',
        'type'     => 6,
        'no'       => '61',
        'date'     => '2026-10-06',
        'amount'   => 40.0,
        'unposted' => false,
        'items'    => [
            5 => ['q' => 1.0, 'p' => 10.0, 'name' => '5'],
            6 => ['q' => 1.0, 'p' => 10.0, 'name' => '6'],
            7 => ['q' => 1.0, 'p' => 10.0, 'name' => '7'],
            8 => ['q' => 1.0, 'p' => 10.0, 'name' => '8'],
        ],
    ],
    [
        'key'      => '70_0',
        'sth_id'   => 70,
        'suffix'   => '0',
        'party'    => 'degla',
        'counter'  => 'hq',
        'type'     => 2,
        'no'       => '70',
        'date'     => '2026-10-07',
        'amount'   => 40.0,
        'unposted' => false,
        'items'    => [
            1 => ['q' => 1.0, 'p' => 10.0, 'name' => '1'],
            2 => ['q' => 1.0, 'p' => 10.0, 'name' => '2'],
            5 => ['q' => 1.0, 'p' => 10.0, 'name' => '5'],
            6 => ['q' => 1.0, 'p' => 10.0, 'name' => '6'],
        ],
    ],
    [
        'key'      => '71_0',
        'sth_id'   => 71,
        'suffix'   => '0',
        'party'    => 'degla',
        'counter'  => 'hq',
        'type'     => 2,
        'no'       => '71',
        'date'     => '2026-10-08',
        'amount'   => 40.0,
        'unposted' => false,
        'items'    => [
            3 => ['q' => 1.0, 'p' => 10.0, 'name' => '3'],
            4 => ['q' => 1.0, 'p' => 10.0, 'name' => '4'],
            7 => ['q' => 1.0, 'p' => 10.0, 'name' => '7'],
            8 => ['q' => 1.0, 'p' => 10.0, 'name' => '8'],
        ],
    ],
];
$res_a9 = recon_alerts_detect($docs_a9, $showFrom, $showTo, $today, $labels);
assertTest(count($res_a9) === 0, 'أ٩', 'مجموعة فواتير متطابقة إجمالياً (80 مقابل 80) ← صفر تنبيهات', 'عدد التنبيهات: ' . count($res_a9));

// -------------------------------------------------------------------------
// أ١٠: زي أ٩ بس صنف 8 عند دجلة كميته أقل بـ1 (بسعر 7)
// المتوقع: group أحمر، الفرق 7.00
// -------------------------------------------------------------------------
$docs_a10 = $docs_a9;
// تعديل صنف 8 عند hq بسعر 7، وعند دجلة غير موجود أو كميته 0
$docs_a10[1]['amount'] = 30.0 + 7.0; // 37.0
$docs_a10[1]['items'][8] = ['q' => 1.0, 'p' => 7.0, 'name' => '8'];
$docs_a10[3]['amount'] = 30.0; // بدون صنف 8
unset($docs_a10[3]['items'][8]);

$res_a10 = recon_alerts_detect($docs_a10, $showFrom, $showTo, $today, $labels);
$pass_a10 = (count($res_a10) === 1 && $res_a10[0]['kind'] === 'group' && $res_a10[0]['level'] === 'red' && abs($res_a10[0]['amount'] - 7.0) < 0.01);
assertTest($pass_a10, 'أ١٠', 'مجموعة فواتير بها فرق صنف بقيمة 7 ← group أحمر بقيمة 7.00', json_encode($res_a10, JSON_UNESCAPED_UNICODE));

// -------------------------------------------------------------------------
// أ١١: فاتورة واحدة تاريخها 5/9 (قبل showFrom) ومالهاش مقابل
// المتوقع: صفر تنبيهات (لأنها خارج فترة العرض)
// -------------------------------------------------------------------------
$docs_a11 = [
    [
        'key'      => '99_0',
        'sth_id'   => 99,
        'suffix'   => '0',
        'party'    => 'degla',
        'counter'  => 'hq',
        'type'     => 2,
        'no'       => '99',
        'date'     => '2026-09-05',
        'amount'   => 450.0,
        'unposted' => false,
        'items'    => [
            1 => ['q' => 5.0, 'p' => 90.0, 'name' => 'قديم'],
        ],
    ],
];
$res_a11 = recon_alerts_detect($docs_a11, $showFrom, $showTo, $today, $labels);
assertTest(count($res_a11) === 0, 'أ١١', 'فاتورة خارج نافذة العرض showFrom ← صفر تنبيهات', 'عدد التنبيهات: ' . count($res_a11));

// -------------------------------------------------------------------------
// أ١٢: hq مردود 80 لدجلة + مقابله عند دجلة، بس فاتورة الإدارة unposted=true
// المتوقع: unposted أصفر
// -------------------------------------------------------------------------
$docs_a12 = [
    [
        'key'      => '80_',
        'sth_id'   => 80,
        'suffix'   => '',
        'party'    => 'hq',
        'counter'  => 'degla',
        'type'     => 6,
        'no'       => '80',
        'date'     => '2026-10-10',
        'amount'   => 200.0,
        'unposted' => true,
        'items'    => [
            1 => ['q' => 2.0, 'p' => 100.0, 'name' => 'صنف Z'],
        ],
    ],
    [
        'key'      => '90_0',
        'sth_id'   => 90,
        'suffix'   => '0',
        'party'    => 'degla',
        'counter'  => 'hq',
        'type'     => 2,
        'no'       => '90',
        'date'     => '2026-10-10',
        'amount'   => 200.0,
        'unposted' => false,
        'items'    => [
            1 => ['q' => 2.0, 'p' => 100.0, 'name' => 'صنف Z'],
        ],
    ],
];
$res_a12 = recon_alerts_detect($docs_a12, $showFrom, $showTo, $today, $labels);
$pass_a12 = (count($res_a12) === 1 && $res_a12[0]['kind'] === 'unposted' && $res_a12[0]['level'] === 'yellow');
assertTest($pass_a12, 'أ١٢', 'فاتورة الإدارة غير مرحلة unposted=true ← unposted أصفر', json_encode($res_a12, JSON_UNESCAPED_UNICODE));

// -------------------------------------------------------------------------
// أ١٣: الإدارة مردود 90 و91 لدجلة نفس الأصناف والكميات (5/10 و7/10)؛ دجلة شراء 95 و96 بنفس الأصناف (5/10 و7/10)
// المتوقع: صفر تنبيهات
// -------------------------------------------------------------------------
$items_a13 = [
    1 => ['q' => 5.0, 'p' => 20.0, 'name' => 'صنف أ'],
];
$docs_a13 = [
    [
        'key'      => '90_0',
        'sth_id'   => 90,
        'suffix'   => '0',
        'party'    => 'hq',
        'counter'  => 'degla',
        'type'     => 6, // مردود
        'no'       => '90',
        'date'     => '2026-10-05',
        'amount'   => 100.0,
        'unposted' => false,
        'items'    => $items_a13,
    ],
    [
        'key'      => '91_0',
        'sth_id'   => 91,
        'suffix'   => '0',
        'party'    => 'hq',
        'counter'  => 'degla',
        'type'     => 6, // مردود
        'no'       => '91',
        'date'     => '2026-10-07',
        'amount'   => 100.0,
        'unposted' => false,
        'items'    => $items_a13,
    ],
    [
        'key'      => '95_0',
        'sth_id'   => 95,
        'suffix'   => '0',
        'party'    => 'degla',
        'counter'  => 'hq',
        'type'     => 2, // شراء
        'no'       => '95',
        'date'     => '2026-10-05',
        'amount'   => 100.0,
        'unposted' => false,
        'items'    => $items_a13,
    ],
    [
        'key'      => '96_0',
        'sth_id'   => 96,
        'suffix'   => '0',
        'party'    => 'degla',
        'counter'  => 'hq',
        'type'     => 2, // شراء
        'no'       => '96',
        'date'     => '2026-10-07',
        'amount'   => 100.0,
        'unposted' => false,
        'items'    => $items_a13,
    ],
];
$res_a13 = recon_alerts_detect($docs_a13, $showFrom, $showTo, $today, $labels);
assertTest(count($res_a13) === 0, 'أ١٣', 'توأم عند الإدارة وتوأم عند الفرع بنفس الأصناف ← صفر تنبيهات', json_encode($res_a13, JSON_UNESCAPED_UNICODE));

// -------------------------------------------------------------------------
// أ١٤: دجلة شراء 100 (3 أصناف، مبلغ 250) و101 (نفس الـ3 + 17 صنف تاني، مبلغ 13,000)
//      والإدارة مردود 110 = نفس 100 و111 = نفس 101
// المتوقع: صفر تنبيهات
// -------------------------------------------------------------------------
$items_3 = [
    1 => ['q' => 1.0, 'p' => 50.0, 'name' => 'صنف 1'],
    2 => ['q' => 2.0, 'p' => 50.0, 'name' => 'صنف 2'],
    3 => ['q' => 1.0, 'p' => 100.0, 'name' => 'صنف 3'],
];
$items_20 = $items_3;
for ($it = 4; $it <= 20; $it++) {
    $items_20[$it] = ['q' => 1.0, 'p' => 750.0, 'name' => "صنف {$it}"];
}

$docs_a14 = [
    [
        'key'      => '100_0',
        'sth_id'   => 100,
        'suffix'   => '0',
        'party'    => 'degla',
        'counter'  => 'hq',
        'type'     => 2, // شراء
        'no'       => '100',
        'date'     => '2026-10-05',
        'amount'   => 250.0,
        'unposted' => false,
        'items'    => $items_3,
    ],
    [
        'key'      => '101_0',
        'sth_id'   => 101,
        'suffix'   => '0',
        'party'    => 'degla',
        'counter'  => 'hq',
        'type'     => 2, // شراء
        'no'       => '101',
        'date'     => '2026-10-07',
        'amount'   => 13000.0,
        'unposted' => false,
        'items'    => $items_20,
    ],
    [
        'key'      => '110_0',
        'sth_id'   => 110,
        'suffix'   => '0',
        'party'    => 'hq',
        'counter'  => 'degla',
        'type'     => 6, // مردود
        'no'       => '110',
        'date'     => '2026-10-05',
        'amount'   => 250.0,
        'unposted' => false,
        'items'    => $items_3,
    ],
    [
        'key'      => '111_0',
        'sth_id'   => 111,
        'suffix'   => '0',
        'party'    => 'hq',
        'counter'  => 'degla',
        'type'     => 6, // مردود
        'no'       => '111',
        'date'     => '2026-10-07',
        'amount'   => 13000.0,
        'unposted' => false,
        'items'    => $items_20,
    ],
];
$res_a14 = recon_alerts_detect($docs_a14, $showFrom, $showTo, $today, $labels);
assertTest(count($res_a14) === 0, 'أ١٤', 'احتواء بدون نسبة 80% لا يعتبر تكرار وكل فاتورة تقابل نظيرتها ← صفر تنبيهات', json_encode($res_a14, JSON_UNESCAPED_UNICODE));

echo "\n------------------------------------------------------------\n";
if ($testsPassed === $totalTests) {
    echo "✅ {$testsPassed}/{$totalTests}\n";
    exit(0);
} else {
    echo "❌ {$testsPassed}/{$totalTests}\n";
    exit(1);
}
