<?php
/**
 * receiving_diffs.php — صفحة الفروقات الشاملة بين الفروع
 * نظام الفجالة ستور المالي
 *
 * الغرض:
 * - عرض الفروقات بين الكميات الموزعة والكميات المستلمة فعلياً لكل فرع.
 * - للعرض فقط (بدون أي تعديل).
 * - الفرع الذي لم يستلم بعد (الحالة sent أو receiving) يكتب له «لسه» وليس فرق كامل.
 * - سعر التكلفة لا يظهر نهائياً.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_permission('receiving');
require_once __DIR__ . '/receiving_flow_lib.php';

$user = current_user();
$uid  = (int) ($user['id'] ?? 0);
$userBranchId = !empty($user['branch_id']) ? (int) $user['branch_id'] : null;
$isOwner = is_owner();
$supervisorId = (int) setting('receiving_supervisor_id', '0');
$isSupervisor = ($supervisorId > 0 && $uid === $supervisorId);

// التحقق من الصلاحية: الأونر أو المشرف أو صلاحية receiving:diffs أو أي موظف فرع
$canView = $isOwner || $isSupervisor || can('receiving:diffs') || $userBranchId !== null;
if (!$canView) {
    http_response_code(403);
    die('غير مصرح لك بالوصول لصفحة الفروقات.');
}

$groupId = isset($_GET['g']) ? (int) $_GET['g'] : 0;
$distId  = isset($_GET['d']) ? (int) $_GET['d'] : 0;

// 🔴 كل استعلامات الصفحة اتطلعت لدالة receiving_diffs_data() في receiving_flow_lib.php
//    — نفس الدالة اللي receiving_diffs_api.php بينده عليها، فالشكل واحد للويب
//    وللتطبيقات. الـHTML تحت بيرسم من نفس أسماء المتغيّرات زي ما كان.
$diffsData = receiving_diffs_data(
    [
        'uid'            => $uid,
        'user_branch_id' => $userBranchId,
        'is_owner'       => $isOwner,
        'is_supervisor'  => $isSupervisor,
        // الجزء نفسه بالحرف — can() بترجّع true لأي حد معاه موديول receiving كامل
        'can_diffs'      => in_array('receiving:diffs', $_SESSION['permissions'] ?? [], true),
        'can_view'       => $canView,
    ],
    ['group_id' => $groupId, 'distribution_id' => $distId]
);

$availableList    = $diffsData['available_list'];
$isGrp            = $diffsData['is_group'];
$title            = $diffsData['title'];
$branches         = $diffsData['branches'];
$receiptsByBranch = $diffsData['receipts_by_branch'];
$itemsMap         = $diffsData['items'];

$active_module = 'receiving';
require_once __DIR__ . '/includes/header.php';
?>

<style>
/* الشكل كله من المكتبة الموحّدة — اللي هنا أعمدة الجدول بس */
.ftable .total-col { background: var(--line-soft); font-weight: 700; }
</style>

<div class="content-container">

    <div class="fpage-head">
        <div>
            <h1 class="fpage-title"><i class="ti ti-chart-bar"></i> صفحة فروقات الاستلام بين الفروع</h1>
            <p class="fpage-sub">مقارنة الكميات الموزعة بما تم مسحه واستلامه فعلياً في كل فرع.</p>
        </div>
        <div class="fpage-actions">
            <a href="receiving.php" class="btn btn-ghost btn-sm">← الرجوع لشاشة الاستلام</a>
        </div>
    </div>

    <?php if ($groupId <= 0 && $distId <= 0): ?>
        <!-- قايمة لاختيار الفاتورة أو المجموعة -->
        <div class="fcard" style="margin-bottom:16px;">
            <h3 class="fcard-title" style="margin-top:0;">اختر فاتورة أو مجموعة لعرض فروقاتها:</h3>
            <?php if (empty($availableList)): ?>
                <p style="color:var(--ink-400);margin:14px 0;">لا توجد فواتير أو مجموعات مفتوح لها استلام حالياً.</p>
            <?php else: ?>
                <div style="display:flex;flex-direction:column;gap:8px;margin-top:12px;">
                    <?php foreach ($availableList as $av):
                        $isGrp = (int)$av['group_id'] > 0;
                        $link = 'receiving_diffs.php?' . ($isGrp ? ('g=' . $av['group_id']) : ('d=' . $av['distribution_id']));
                        $title = $isGrp
                            ? ($av['group_name'] ? ('مجموعة فواتير #' . $av['group_id'] . ' — ' . $av['group_name']) : ('مجموعة فواتير #' . $av['group_id']))
                            : ('فاتورة مفردة #' . ($av['invoice_no'] ?: $av['distribution_id']));
                    ?>
                        <a href="<?= e($link) ?>" style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:var(--bg);border:1px solid var(--line);border-radius:var(--r-md);text-decoration:none;color:var(--text);"
                           onmouseover="this.style.borderColor='var(--accent)'" onmouseout="this.style.borderColor='var(--line)'">
                            <span><b><?= e($title) ?></b> (<?= (int)$av['branches_count'] ?> فروع)</span>
                            <span style="font-size:12px;color:var(--ink-400);"><?= date('Y-m-d H:i', strtotime($av['last_date'])) ?> ←</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    <?php else: ?>

        <div class="fcard" style="margin-bottom:16px;">
            <div class="fcard-head">
                <h3 class="fcard-title">
                    <?= e($title) ?>
                </h3>
                <div class="fcard-actions">
                    <a href="receiving_diffs.php" class="btn btn-ghost btn-sm">اختيار فاتورة أخرى</a>
                </div>
            </div>

            <?php if (empty($itemsMap)): ?>
                <div class="fg-empty"><i class="ti ti-inbox"></i><span>لا توجد أصناف موزعة في هذا السياق.</span></div>
            <?php else: ?>
                <?php /* 🔴 ألوان المفتاح لازم تطابق اللي الجدول بيرسمه (السطور ٣٤٧-٣٥٣):
                         الكود بيحسب $diff = الموزّع − الممسوح، وبعدين **بيقلب الإشارة**
                         وهو بيطبع: الموزّع أكبر ← بيطلع «-» بـ--neg، الممسوح أكبر ←
                         «+» بـ--accent-text (مش --pos). لو غيّرت لون هنا، غيّره هناك. */ ?>
                <div class="fcard" style="padding:10px 14px;margin-bottom:12px;
                                         font-size:13px;color:var(--ink-500);
                                         display:flex;gap:18px;flex-wrap:wrap;">
                    <span><span class="fchip fchip-mute">لسه</span> الفرع لسه ما استلمش — مش فرق</span>
                    <span><b>—</b> مفيش قيمة للخانة دي</span>
                    <span><b style="color:var(--neg);">بالسالب</b> ناقص عن المطلوب</span>
                    <span><b style="color:var(--accent-text);">بالموجب</b> زيادة عن المطلوب</span>
                </div>
                <div class="ftable-wrap">
                    <table class="ftable">
                        <thead>
                            <tr>
                                <th style="width:140px;">كود الصنف</th>
                                <th style="min-width:200px;">اسم الصنف</th>
                                <?php foreach ($branches as $br): ?>
                                    <th class="num" style="min-width:90px;">
                                        <?= e($br['name']) ?>
                                    </th>
                                <?php endforeach; ?>
                                <th class="num total-col" style="min-width:100px;">الإجمالي</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($itemsMap as $it): ?>
                                <tr>
                                    <td><code><?= e($it['item_code']) ?></code></td>
                                    <td class="name"><?= e($it['item_name']) ?></td>
                                    <?php
                                    $rowTotalDist = 0.0;
                                    $rowTotalScan = 0.0;
                                    $anyDistributed = false;
                                    $allDone = true;
                                    $anyDone = false;

                                    foreach ($branches as $br):
                                        $bid = (int)$br['id'];
                                        $distQ = (float)($it['by_branch'][$bid]['dist'] ?? 0);
                                        $scanQ = (float)($it['by_branch'][$bid]['scan'] ?? 0);
                                        $rc = $receiptsByBranch[$bid] ?? null;
                                        $status = $rc['status'] ?? 'sent';

                                        // إذا الصنف لم يوزع للفرع أصلاً
                                        if ($distQ <= 0) {
                                            echo '<td class="num" style="color:var(--ink-400);">—</td>';
                                            continue;
                                        }

                                        $anyDistributed = true;
                                        $rowTotalDist += $distQ;

                                        // الشرط الصارم: الفرع الذي لسه ما استلمش (sent أو receiving) يُكتب «لسه»
                                        if (!$rc || in_array($status, ['sent', 'receiving'], true)) {
                                            $allDone = false;
                                            echo '<td class="num"><span class="fchip fchip-mute">لسه</span></td>';
                                        } else {
                                            $anyDone = true;
                                            $rowTotalScan += $scanQ;
                                            // الحالة received أو approved ← حساب الفرق الفعلي
                                            $diff = $distQ - $scanQ;
                                            if ($diff == 0) {
                                                echo '<td class="num"><span style="color:var(--pos);font-weight:bold;">0 <i class="ti ti-check"></i></span></td>';
                                            } elseif ($diff > 0) {
                                                echo '<td class="num"><span style="color:var(--neg);font-weight:bold;">-' . $diff . '</span></td>';
                                            } else {
                                                echo '<td class="num"><span style="color:var(--accent-text);font-weight:bold;">+' . abs($diff) . '</span></td>';
                                            }
                                        }
                                    endforeach;

                                    // خلية الإجمالي للصنف
                                    echo '<td class="num total-col">';
                                    if (!$anyDistributed) {
                                        echo '<span style="color:var(--ink-400);">—</span>';
                                    } elseif (!$anyDone) {
                                        echo '<span class="fchip fchip-mute">لسه</span>';
                                    } else {
                                        $totalDiff = $rowTotalDist - $rowTotalScan;
                                        if ($allDone) {
                                            if ($totalDiff == 0) {
                                                echo '<span style="color:var(--pos);font-weight:bold;">0 <i class="ti ti-check"></i></span>';
                                            } elseif ($totalDiff > 0) {
                                                echo '<span style="color:var(--neg);font-weight:bold;">-' . $totalDiff . '</span>';
                                            } else {
                                                echo '<span style="color:var(--accent-text);font-weight:bold;">+' . abs($totalDiff) . '</span>';
                                            }
                                        } else {
                                            if ($totalDiff == 0) {
                                                echo '<span style="color:var(--pos);font-size:12px;" title="الفروع المكتملة مطابقة">' . $rowTotalScan . ' / ' . $rowTotalDist . '</span>';
                                            } elseif ($totalDiff > 0) {
                                                echo '<span style="color:var(--neg);font-size:12px;" title="عجز حتى الآن">' . $rowTotalScan . ' / ' . $rowTotalDist . ' (-' . $totalDiff . ')</span>';
                                            } else {
                                                echo '<span style="color:var(--accent-text);font-size:12px;" title="زيادة حتى الآن">' . $rowTotalScan . ' / ' . $rowTotalDist . ' (+' . abs($totalDiff) . ')</span>';
                                            }
                                        }
                                    }
                                    echo '</td>';
                                    ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
