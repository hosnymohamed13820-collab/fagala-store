<?php
/**
 * diag_stock.php — تشخيص سريع لشاشة استعلام الأرصدة (للمالك فقط)
 * ⚠️ امسحه بعد ما تبعت اللقطة.
 */
require_once __DIR__ . '/auth.php';
require_login();
if (!is_owner()) { http_response_code(403); die('للمالك فقط'); }
require_once __DIR__ . '/stock_query_lib.php';

header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html dir="rtl"><head><meta charset="utf-8"><style>'
   . 'body{font-family:monospace;background:#0f172a;color:#e2e8f0;padding:20px;line-height:1.8}'
   . 'h3{color:#7dd3fc;margin-top:22px}table{border-collapse:collapse;margin:8px 0}'
   . 'td,th{border:1px solid #334155;padding:5px 10px;font-size:13px;text-align:right}'
   . 'th{background:#1e293b}.ok{color:#4ade80}.bad{color:#f87171}</style></head><body>';

echo '<h3>1) ثابت مخزن الإدارة</h3>';
echo 'ADMIN_STO_ID = <b>' . ADMIN_STO_ID . '</b>';

echo '<h3>2) الفروع في قاعدتنا (اللي بتبني الأعمدة)</h3>';
$rows = db()->query('SELECT id, name, sto_id, in_receiving, is_active FROM branches ORDER BY id')->fetchAll();
echo '<table><tr><th>id</th><th>الاسم</th><th>sto_id</th><th>in_receiving</th><th>is_active</th></tr>';
foreach ($rows as $r) {
    $warn = ($r['sto_id'] === null) ? ' class="bad"' : '';
    echo "<tr$warn><td>{$r['id']}</td><td>" . e($r['name']) . '</td><td>'
       . ($r['sto_id'] === null ? '<b class="bad">NULL</b>' : $r['sto_id'])
       . "</td><td>{$r['in_receiving']}</td><td>{$r['is_active']}</td></tr>";
}
echo '</table>';

echo '<h3>3) الأعمدة اللي الشاشة هتبنيها فعلاً</h3>';
$map = stock_branches_map();
if (!$map) echo '<b class="bad">فاضي! — عشان كده مفيش أعمدة</b>';
echo '<table><tr><th>sto_id</th><th>الاسم</th><th>is_admin</th></tr>';
foreach ($map as $m) {
    echo '<tr><td>' . $m['sto_id'] . '</td><td>' . e($m['name']) . '</td><td>'
       . ($m['is_admin'] ? '<b class="ok">نعم (الإدارة)</b>' : 'لأ') . '</td></tr>';
}
echo '</table>';
$hasAdmin = false;
foreach ($map as $m) if ($m['sto_id'] === (int) ADMIN_STO_ID) $hasAdmin = true;
echo $hasAdmin
    ? '<p class="ok">✓ مخزن الإدارة (' . ADMIN_STO_ID . ') موجود ضمن الأعمدة</p>'
    : '<p class="bad">✗ مخزن الإدارة (' . ADMIN_STO_ID . ') مش موجود في جدول branches — ده سبب اختفاء العمود</p>';

echo '<h3>4) المخازن الموجودة فعلاً في نظام الشركة (COM_STO_ITEMS)</h3>';
try {
    $sql = "SELECT TOP 30 STO_ID, COUNT(*) AS items, SUM(CSI_CURRENT_BALANCE) AS total_bal
            FROM " . SQLSRV_PREFIX . "COM_STO_ITEMS
            GROUP BY STO_ID ORDER BY STO_ID";
    $r = sqlsrv_query($sql, []);
    echo '<table><tr><th>STO_ID</th><th>عدد أصناف</th><th>إجمالي الرصيد</th><th>عندنا؟</th></tr>';
    $known = array_column($map, 'sto_id');
    foreach ($r as $row) {
        $sid = (int) $row['STO_ID'];
        $in  = in_array($sid, $known, true);
        echo '<tr><td>' . $sid . '</td><td>' . (int)$row['items'] . '</td><td>'
           . number_format((float)$row['total_bal'], 2) . '</td><td>'
           . ($in ? '<span class="ok">✓</span>' : '<span class="bad">✗ مش معرّف عندنا</span>') . '</td></tr>';
    }
    echo '</table>';
} catch (Throwable $ex) {
    echo '<p class="bad">خطأ: ' . e($ex->getMessage()) . '</p>';
}

echo '<h3>5) تجربة فلتر "عندها رصيد" (has_stock)</h3>';
try {
    $res = stock_filter_items('has_stock', 0, 0, 5);
    echo 'رجّع <b>' . count($res) . '</b> صنف (أول 5)';
    if ($res) {
        echo '<table><tr><th>الكود</th><th>الاسم</th><th>الأرصدة (sto=qty)</th><th>الإجمالي</th></tr>';
        foreach ($res as $x) {
            $b = [];
            foreach ($x['balances'] as $s => $q) $b[] = "$s=" . round((float)$q, 2);
            echo '<tr><td>' . e($x['code']) . '</td><td>' . e($x['name']) . '</td><td>'
               . e(implode(' · ', $b)) . '</td><td>' . round($x['total'], 2) . '</td></tr>';
        }
        echo '</table>';
    } else {
        echo '<p class="bad">✗ فاضي — الفلتر مش راجع حاجة</p>';
    }
} catch (Throwable $ex) {
    echo '<p class="bad">خطأ: ' . e($ex->getMessage()) . '</p>';
}

if (!empty($GLOBALS['recv_errors'])) {
    echo '<h3>أخطاء الجسر</h3><ul>';
    foreach ($GLOBALS['recv_errors'] as $er) echo '<li class="bad">' . e($er) . '</li>';
    echo '</ul>';
}

echo '<p style="color:#64748b;margin-top:24px">⚠️ امسح الملف ده بعد ما تبعت اللقطة.</p>';
echo '</body></html>';
