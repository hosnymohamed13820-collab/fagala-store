<?php
/**
 * branch_requests.php — طلبات الفروع (شاشة المشتريات)
 * نظام الفجالة ستور المالي
 *
 * شاشة عرض بس للمشتريات:
 *   - كارت مستقل لكل فرع
 *   - طلبات النواقص (source = 'purchase')
 *   - حجوزات الكتب المفتوحة
 *
 * 🔴 صفر كتابة في الداتابيز في المرحلة دي.
 * 🔴 ٣ استعلامات على الأكثر للصفحة كلها ومفيش أي استعلام جوه حلقة.
 */
require_once __DIR__ . '/auth.php';
require_permission('shortage');
require_once __DIR__ . '/book_lib.php';

$uid      = (int) current_user()['id'];
$myBranch = (int) (current_user()['branch_id'] ?? 0);
$resScope = book_res_scope(is_owner(), $myBranch);

// (١) الفروع النشطة
$branches = db()->query(
    'SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name'
)->fetchAll();

// (٢) سطور طلبات النواقص للمشتريات فقط (استعلام واحد للصفحة كلها)
$stLines = db()->query(
    "SELECT l.id, l.request_id, l.item_id, l.code, l.item_name, l.qty, l.status,
            r.from_branch_id, r.created_at
     FROM shortage_request_lines l
     JOIN shortage_requests r ON r.id = l.request_id
     WHERE r.source = 'purchase'
       AND l.status IN ('open', 'grouped', 'purchased')
     ORDER BY r.created_at ASC, l.id ASC"
)->fetchAll();

// (٣) سطور حجوزات الكتب المفتوحة (استعلام واحد للصفحة كلها)
$resSql = "SELECT l.id, l.reservation_id, l.item_id, l.code, l.item_name, l.qty, l.allocated_qty, l.stage,
            r.branch_id, r.customer_name, r.customer_phone, r.created_at
     FROM book_reservation_lines l
     JOIN book_reservations r ON r.id = l.reservation_id
     WHERE r.status = 'open'";
$resParams = [];
if ($resScope !== null) {
    $resSql .= " AND (r.branch_id = ? OR r.created_by = ?)";
    $resParams[] = $resScope;
    $resParams[] = $uid;
}
$resSql .= " ORDER BY r.created_at ASC, l.id ASC";

$stmt = db()->prepare($resSql);
$stmt->execute($resParams);
$resLines = $stmt->fetchAll();

// تجميع النواقص حسب الفرع الطالب
$shortagesByBranch = [];
foreach ($stLines as $sl) {
    $bId = (int)($sl['from_branch_id'] ?? 0);
    $shortagesByBranch[$bId][] = $sl;
}

// تجميع الحجوزات حسب الفرع
$reservationsByBranch = [];
foreach ($resLines as $rl) {
    $bId = (int)($rl['branch_id'] ?? 0);
    $reservationsByBranch[$bId][] = $rl;
}

$sLabels = [
    'open'      => 'مفتوح',
    'grouped'   => 'مجمّع',
    'purchased' => 'تم الشراء',
];

$page_title    = 'طلبات الفروع';
$active_module = 'shortage';
require_once __DIR__ . '/includes/header.php';
?>
<style>
    .btn { background:var(--accent); color:#fff; border:none; padding:11px 20px; border-radius:10px;
        font-size:14px; font-family:inherit; cursor:pointer; display:inline-flex; align-items:center;
        gap:7px; text-decoration:none; white-space:nowrap; }
    .btn:hover { filter:brightness(0.94); color:#fff; }
    .btn:disabled { opacity:.55; cursor:not-allowed; }

    .br-table { width:100%; border-collapse:collapse; }
    .br-table th, .br-table td { padding:10px 12px; border-bottom:1px solid var(--line-soft);
                                 text-align:center; font-size:13.5px; }
    .br-table th { background:var(--line-soft); color:var(--accent-text); font-weight:600; }
    .br-table .nm { text-align:right; font-weight:500; min-width:200px; }
    .br-table .cd { font-family:monospace; color:var(--ink-500); font-size:12px; }

    .pill { display:inline-block; padding:4px 13px; border-radius:20px; font-size:12.5px;
            font-weight:700; white-space:nowrap; }
    .pill.st-open { background:var(--accent-soft); color:var(--accent-text); }
    .pill.st-reserved { background:var(--accent-soft); color:var(--accent-text); }
    .pill.st-grouped { background:var(--warn-soft); color:var(--warn); }
    .pill.st-purchased { background:var(--pos-soft); color:var(--pos); }
    .pill.st-part { background:var(--warn-soft); color:var(--warn); }
    .pill.st-full { background:var(--pos-soft); color:var(--pos); }

    .br-sub-title { font-size:14.5px; font-weight:700; color:var(--ink-800); margin:14px 0 8px;
                    display:flex; align-items:center; gap:6px; }
    .br-empty { text-align:center; color:var(--ink-400); padding:18px 12px; font-size:13.5px; }
</style>

<?= book_tabs_bar('breq') ?>

<?php
$knownBranchIds = [];
foreach ($branches as $b):
    $bId = (int)$b['id'];
    $knownBranchIds[$bId] = true;
    $bShortages    = $shortagesByBranch[$bId] ?? [];
    $bReservations = $reservationsByBranch[$bId] ?? [];
    $hasItems      = !empty($bShortages) || !empty($bReservations);
?>
    <div class="card" style="margin-bottom:16px;">
        <div class="section-title" style="margin-bottom:12px;">
            <i class="ti ti-building-store"></i> فرع <?= e($b['name']) ?>
        </div>

        <?php if (!$hasItems): ?>
            <div class="br-empty">مفيش حاجة مستنية من الفرع ده</div>
        <?php else: ?>
            <?php if (!empty($bShortages)): ?>
                <div class="br-sub-title">
                    <i class="ti ti-shopping-cart"></i> طلبات النواقص
                </div>
                <div style="overflow-x:auto;">
                    <table class="br-table">
                        <thead>
                            <tr>
                                <th class="cd" style="width:140px;">كود الصنف</th>
                                <th class="nm">اسم الصنف</th>
                                <th style="width:100px;">الكمية</th>
                                <th style="width:140px;">تاريخ الطلب</th>
                                <th style="width:130px;">الحالة</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bShortages as $sl):
                                $stKey = (string)($sl['status'] ?? 'open');
                                $stLabel = $sLabels[$stKey] ?? $stKey;
                                $stPillClass = 'st-' . $stKey;
                            ?>
                                <tr>
                                    <td class="cd"><?= e($sl['code']) ?></td>
                                    <td class="nm"><?= e($sl['item_name']) ?></td>
                                    <td><?= rtrim(rtrim(number_format((float)$sl['qty'], 2, '.', ''), '0'), '.') ?></td>
                                    <td><?= e(date('Y-m-d H:i', strtotime((string)$sl['created_at']))) ?></td>
                                    <td><span class="pill <?= e($stPillClass) ?>"><?= e($stLabel) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if (!empty($bReservations)): ?>
                <div class="br-sub-title" style="<?= !empty($bShortages) ? 'margin-top:20px;' : '' ?>">
                    <i class="ti ti-user-check"></i> حجوزات الكتب
                </div>
                <div style="overflow-x:auto;">
                    <table class="br-table">
                        <thead>
                            <tr>
                                <th class="nm">اسم الكتاب</th>
                                <th style="width:100px;">الكمية</th>
                                <th style="width:160px;">العميل</th>
                                <th style="width:140px;">تاريخ الحجز</th>
                                <th style="width:170px;">الحالة</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bReservations as $rl):
                                $alloc = (float)($rl['allocated_qty'] ?? 0);
                                $qty   = (float)($rl['qty'] ?? 0);
                                if ($alloc <= 0) {
                                    $resStatusText  = 'محجوز';
                                    $resStatusClass = 'st-reserved';
                                } elseif ($alloc < $qty) {
                                    $resStatusText  = 'تم توفير جزء من الحجز';
                                    $resStatusClass = 'st-part';
                                } else {
                                    $resStatusText  = 'تم توفير كامل الحجز';
                                    $resStatusClass = 'st-full';
                                }
                            ?>
                                <tr>
                                    <td class="nm"><?= e($rl['item_name']) ?></td>
                                    <td><?= rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') ?></td>
                                    <td><?= e($rl['customer_name']) ?></td>
                                    <td><?= e(date('Y-m-d H:i', strtotime((string)$rl['created_at']))) ?></td>
                                    <td><span class="pill <?= e($resStatusClass) ?>"><?= e($resStatusText) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<?php
// الحجوزات أو الطلبات غير المنسوبة لفرع (branch_id IS NULL أو 0)
$unassignedShortages = [];
foreach ($shortagesByBranch as $bId => $items) {
    if (!isset($knownBranchIds[$bId])) {
        $unassignedShortages = array_merge($unassignedShortages, $items);
    }
}
$unassignedReservations = [];
foreach ($reservationsByBranch as $bId => $items) {
    if (!isset($knownBranchIds[$bId])) {
        $unassignedReservations = array_merge($unassignedReservations, $items);
    }
}

if (!empty($unassignedShortages) || !empty($unassignedReservations)):
?>
    <div class="card" style="margin-bottom:16px;">
        <div class="section-title" style="margin-bottom:12px;">
            <i class="ti ti-building-store"></i> من غير فرع
        </div>

        <?php if (!empty($unassignedShortages)): ?>
            <div class="br-sub-title">
                <i class="ti ti-shopping-cart"></i> طلبات النواقص
            </div>
            <div style="overflow-x:auto;">
                <table class="br-table">
                    <thead>
                        <tr>
                            <th class="cd" style="width:140px;">كود الصنف</th>
                            <th class="nm">اسم الصنف</th>
                            <th style="width:100px;">الكمية</th>
                            <th style="width:140px;">تاريخ الطلب</th>
                            <th style="width:130px;">الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($unassignedShortages as $sl):
                            $stKey = (string)($sl['status'] ?? 'open');
                            $stLabel = $sLabels[$stKey] ?? $stKey;
                            $stPillClass = 'st-' . $stKey;
                        ?>
                            <tr>
                                <td class="cd"><?= e($sl['code']) ?></td>
                                <td class="nm"><?= e($sl['item_name']) ?></td>
                                <td><?= rtrim(rtrim(number_format((float)$sl['qty'], 2, '.', ''), '0'), '.') ?></td>
                                <td><?= e(date('Y-m-d H:i', strtotime((string)$sl['created_at']))) ?></td>
                                <td><span class="pill <?= e($stPillClass) ?>"><?= e($stLabel) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if (!empty($unassignedReservations)): ?>
            <div class="br-sub-title" style="<?= !empty($unassignedShortages) ? 'margin-top:20px;' : '' ?>">
                <i class="ti ti-user-check"></i> حجوزات الكتب
            </div>
            <div style="overflow-x:auto;">
                <table class="br-table">
                    <thead>
                        <tr>
                            <th class="nm">اسم الكتاب</th>
                            <th style="width:100px;">الكمية</th>
                            <th style="width:160px;">العميل</th>
                            <th style="width:140px;">تاريخ الحجز</th>
                            <th style="width:170px;">الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($unassignedReservations as $rl):
                            $alloc = (float)($rl['allocated_qty'] ?? 0);
                            $qty   = (float)($rl['qty'] ?? 0);
                            if ($alloc <= 0) {
                                $resStatusText  = 'محجوز';
                                $resStatusClass = 'st-reserved';
                            } elseif ($alloc < $qty) {
                                $resStatusText  = 'تم توفير جزء من الحجز';
                                $resStatusClass = 'st-part';
                            } else {
                                $resStatusText  = 'تم توفير كامل الحجز';
                                $resStatusClass = 'st-full';
                            }
                        ?>
                            <tr>
                                <td class="nm"><?= e($rl['item_name']) ?></td>
                                <td><?= rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') ?></td>
                                <td><?= e($rl['customer_name']) ?></td>
                                <td><?= e(date('Y-m-d H:i', strtotime((string)$rl['created_at']))) ?></td>
                                <td><span class="pill <?= e($resStatusClass) ?>"><?= e($resStatusText) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
