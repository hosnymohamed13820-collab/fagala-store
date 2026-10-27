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
require_once __DIR__ . '/shortage_lib.php';

$uid      = (int) current_user()['id'];
$myBranch = (int) (current_user()['branch_id'] ?? 0);
$resScope = book_res_scope(is_owner(), $myBranch);

$data = branch_requests_data($resScope, $uid);
$branches             = $data['branches'];
$stLines              = $data['lines'];
$resLines             = $data['reservations'];
$shortagesByBranch    = $data['shortages_by_branch'];
$reservationsByBranch = $data['reservations_by_branch'];

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
    .br-sub-title { font-size:var(--fs-base); font-weight:700; color:var(--ink-800); margin:14px 0 8px;
                    display:flex; align-items:center; gap:6px; }
    .br-empty { text-align:center; color:var(--ink-400); padding:18px 12px; font-size:13.5px; }
</style>

<?= book_tabs_bar('breq') ?>

<?php
$sChipClasses = [
    'open'      => 'fchip',
    'grouped'   => 'fchip fchip-warn',
    'purchased' => 'fchip fchip-pos',
];

$knownBranchIds = [];
foreach ($branches as $b):
    $bId = (int)$b['id'];
    $knownBranchIds[$bId] = true;
    $bShortages    = $shortagesByBranch[$bId] ?? [];
    $bReservations = $reservationsByBranch[$bId] ?? [];
    $hasItems      = !empty($bShortages) || !empty($bReservations);
?>
    <section class="fcard" style="margin-bottom:16px;">
        <div class="fcard-head" style="margin-bottom:12px;">
            <h2 class="fcard-title"><i class="ti ti-building-store"></i> فرع <?= e($b['name']) ?></h2>
        </div>

        <?php if (!$hasItems): ?>
            <div class="br-empty">مفيش حاجة مستنية من الفرع ده</div>
        <?php else: ?>
            <?php if (!empty($bShortages)): ?>
                <div class="br-sub-title">
                    <i class="ti ti-shopping-cart"></i> طلبات النواقص
                </div>
                <div class="ftable-wrap">
                    <table class="ftable">
                        <thead>
                            <tr>
                                <th class="cd" style="width:140px;font-variant-numeric:tabular-nums;">كود الصنف</th>
                                <th class="nm">اسم الصنف</th>
                                <th class="num" style="width:100px;">الكمية</th>
                                <th style="width:140px;">تاريخ الطلب</th>
                                <th style="width:130px;">الحالة</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bShortages as $sl):
                                $stKey = (string)($sl['status'] ?? 'open');
                                $stLabel = $sLabels[$stKey] ?? $stKey;
                                $stChipClass = $sChipClasses[$stKey] ?? 'fchip';
                            ?>
                                <tr>
                                    <td class="cd" style="font-variant-numeric:tabular-nums;color:var(--ink-500);font-size:12px;"><?= e($sl['code']) ?></td>
                                    <td class="nm"><?= e($sl['item_name']) ?></td>
                                    <td class="num"><?= rtrim(rtrim(number_format((float)$sl['qty'], 2, '.', ''), '0'), '.') ?></td>
                                    <td><?= e(date('Y-m-d H:i', strtotime((string)$sl['created_at']))) ?></td>
                                    <td><span class="<?= e($stChipClass) ?>"><?= e($stLabel) ?></span></td>
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
                <div class="ftable-wrap">
                    <table class="ftable">
                        <thead>
                            <tr>
                                <th class="nm">اسم الكتاب</th>
                                <th class="num" style="width:100px;">الكمية</th>
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
                                    $resChipClass   = 'fchip';
                                } elseif ($alloc < $qty) {
                                    $resStatusText  = 'تم توفير جزء من الحجز';
                                    $resChipClass   = 'fchip fchip-warn';
                                } else {
                                    $resStatusText  = 'تم توفير كامل الحجز';
                                    $resChipClass   = 'fchip fchip-pos';
                                }
                            ?>
                                <tr>
                                    <td class="nm"><?= e($rl['item_name']) ?></td>
                                    <td class="num"><?= rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') ?></td>
                                    <td><?= e($rl['customer_name']) ?></td>
                                    <td><?= e(date('Y-m-d H:i', strtotime((string)$rl['created_at']))) ?></td>
                                    <td><span class="<?= e($resChipClass) ?>"><?= e($resStatusText) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
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
    <section class="fcard" style="margin-bottom:16px;">
        <div class="fcard-head" style="margin-bottom:12px;">
            <h2 class="fcard-title"><i class="ti ti-building-store"></i> من غير فرع</h2>
        </div>

        <?php if (!empty($unassignedShortages)): ?>
            <div class="br-sub-title">
                <i class="ti ti-shopping-cart"></i> طلبات النواقص
            </div>
            <div class="ftable-wrap">
                <table class="ftable">
                    <thead>
                        <tr>
                            <th class="cd" style="width:140px;font-variant-numeric:tabular-nums;">كود الصنف</th>
                            <th class="nm">اسم الصنف</th>
                            <th class="num" style="width:100px;">الكمية</th>
                            <th style="width:140px;">تاريخ الطلب</th>
                            <th style="width:130px;">الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($unassignedShortages as $sl):
                            $stKey = (string)($sl['status'] ?? 'open');
                            $stLabel = $sLabels[$stKey] ?? $stKey;
                            $stChipClass = $sChipClasses[$stKey] ?? 'fchip';
                        ?>
                            <tr>
                                <td class="cd" style="font-variant-numeric:tabular-nums;color:var(--ink-500);font-size:12px;"><?= e($sl['code']) ?></td>
                                <td class="nm"><?= e($sl['item_name']) ?></td>
                                <td class="num"><?= rtrim(rtrim(number_format((float)$sl['qty'], 2, '.', ''), '0'), '.') ?></td>
                                <td><?= e(date('Y-m-d H:i', strtotime((string)$sl['created_at']))) ?></td>
                                <td><span class="<?= e($stChipClass) ?>"><?= e($stLabel) ?></span></td>
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
            <div class="ftable-wrap">
                <table class="ftable">
                    <thead>
                        <tr>
                            <th class="nm">اسم الكتاب</th>
                            <th class="num" style="width:100px;">الكمية</th>
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
                                $resChipClass   = 'fchip';
                            } elseif ($alloc < $qty) {
                                $resStatusText  = 'تم توفير جزء من الحجز';
                                $resChipClass   = 'fchip fchip-warn';
                            } else {
                                $resStatusText  = 'تم توفير كامل الحجز';
                                $resChipClass   = 'fchip fchip-pos';
                            }
                        ?>
                            <tr>
                                <td class="nm"><?= e($rl['item_name']) ?></td>
                                <td class="num"><?= rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') ?></td>
                                <td><?= e($rl['customer_name']) ?></td>
                                <td><?= e(date('Y-m-d H:i', strtotime((string)$rl['created_at']))) ?></td>
                                <td><span class="<?= e($resChipClass) ?>"><?= e($resStatusText) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
