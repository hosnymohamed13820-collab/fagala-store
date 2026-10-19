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
/* override محلي (بريف ٤٣): الزرار الموحّد في fagala.css، والصفحة دي مختلفة في دول بس */
.btn { padding: 8px 16px; font-size: 13px; font-weight: 600; border: 1px solid transparent; }
/* لازم يفضل هنا: .btn اللي فوقه بييجي بعد fagala.css فبيغلب .btn-sm بتاعها */
.btn-sm {
    padding: 4px 10px;
    font-size: 12px;
}
.diff-card {
    margin-bottom: 16px;
}
.diff-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
    font-size: 13px;
}
.diff-table th, .diff-table td {
    padding: 8px 12px;
    border-bottom: 1px solid var(--line-soft);
    text-align: center;
    vertical-align: middle;
}
.diff-table th {
    background: var(--line-soft);
    color: var(--ink-700);
    font-weight: 600;
}
.diff-table th.th-item {
    text-align: right;
}
.diff-table td.td-item {
    text-align: right;
}
.pill-yet {
    display: inline-block;
    padding: 2px 7px;
    background: var(--ink-200);
    color: var(--ink-600);
    border-radius: var(--r-sm);
    font-size: 11.5px;
    font-weight: 500;
}
</style>

<div class="content-container">

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px;">
        <div>
            <h2 style="font-size:20px;font-weight:bold;margin:0;"><i class="ti ti-chart-bar"></i> صفحة فروقات الاستلام بين الفروع</h2>
            <span style="font-size:12.5px;color:var(--ink-400);">مقارنة الكميات الموزعة بما تم مسحه واستلامه فعلياً في كل فرع.</span>
        </div>
        <div>
            <a href="receiving.php" class="btn btn-ghost btn-sm">← الرجوع لشاشة الاستلام</a>
        </div>
    </div>

    <?php if ($groupId <= 0 && $distId <= 0): ?>
        <!-- قايمة لاختيار الفاتورة أو المجموعة -->
        <div class="card diff-card">
            <h3 style="font-size:15px;margin-top:0;">اختر فاتورة أو مجموعة لعرض فروقاتها:</h3>
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
                        <a href="<?= e($link) ?>" style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:var(--bg);border:1px solid var(--line);border-radius:var(--r);text-decoration:none;color:var(--text);"
                           onmouseover="this.style.borderColor='var(--accent)'" onmouseout="this.style.borderColor='var(--line)'">
                            <span><b><?= e($title) ?></b> (<?= (int)$av['branches_count'] ?> فروع)</span>
                            <span style="font-size:12px;color:var(--ink-400);"><?= date('Y-m-d H:i', strtotime($av['last_date'])) ?> ←</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    <?php else: ?>

        <div class="card diff-card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <h3 style="margin:0;font-size:16px;">
                    <?= e($title) ?>
                </h3>
                <a href="receiving_diffs.php" class="btn btn-ghost btn-sm">اختيار فاتورة أخرى</a>
            </div>

            <?php if (empty($itemsMap)): ?>
                <p style="color:var(--ink-400);text-align:center;padding:24px 0;">لا توجد أصناف موزعة في هذا السياق.</p>
            <?php else: ?>
                <?php /* 🔴 ألوان المفتاح لازم تطابق اللي الجدول بيرسمه (السطور ٣٤٧-٣٥٣):
                         الكود بيحسب $diff = الموزّع − الممسوح، وبعدين **بيقلب الإشارة**
                         وهو بيطبع: الموزّع أكبر ← بيطلع «-» بـ--neg، الممسوح أكبر ←
                         «+» بـ--accent-text (مش --pos). لو غيّرت لون هنا، غيّره هناك. */ ?>
                <div class="card" style="padding:10px 14px;margin-bottom:12px;
                                         font-size:13px;color:var(--ink-500);
                                         display:flex;gap:18px;flex-wrap:wrap;">
                    <span><span class="pill-yet">لسه</span> الفرع لسه ما استلمش — مش فرق</span>
                    <span><b>—</b> مفيش قيمة للخانة دي</span>
                    <span><b style="color:var(--neg);">بالسالب</b> ناقص عن المطلوب</span>
                    <span><b style="color:var(--accent-text);">بالموجب</b> زيادة عن المطلوب</span>
                </div>
                <table class="diff-table">
                        <thead>
                            <tr>
                                <th class="th-item" style="width:140px;">كود الصنف</th>
                                <th class="th-item" style="min-width:200px;">اسم الصنف</th>
                                <?php foreach ($branches as $br): ?>
                                    <th style="min-width:90px;">
                                        <?= e($br['name']) ?>
                                    </th>
                                <?php endforeach; ?>
                                <th style="min-width:100px;text-align:center;background:var(--line-soft);">الإجمالي</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($itemsMap as $it): ?>
                                <tr>
                                    <td class="td-item"><code><?= e($it['item_code']) ?></code></td>
                                    <td class="td-item"><?= e($it['item_name']) ?></td>
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
                                            echo '<td style="color:var(--ink-300);">—</td>';
                                            continue;
                                        }

                                        $anyDistributed = true;
                                        $rowTotalDist += $distQ;

                                        // الشرط الصارم: الفرع الذي لسه ما استلمش (sent أو receiving) يُكتب «لسه»
                                        if (!$rc || in_array($status, ['sent', 'receiving'], true)) {
                                            $allDone = false;
                                            echo '<td><span class="pill-yet">لسه</span></td>';
                                        } else {
                                            $anyDone = true;
                                            $rowTotalScan += $scanQ;
                                            // الحالة received أو approved ← حساب الفرق الفعلي
                                            $diff = $distQ - $scanQ;
                                            if ($diff == 0) {
                                                echo '<td><span style="color:var(--pos);font-weight:bold;">0 <i class="ti ti-check"></i></span></td>';
                                            } elseif ($diff > 0) {
                                                echo '<td><span style="color:var(--neg);font-weight:bold;">-' . $diff . '</span></td>';
                                            } else {
                                                echo '<td><span style="color:var(--accent-text);font-weight:bold;">+' . abs($diff) . '</span></td>';
                                            }
                                        }
                                    endforeach;

                                    // خلية الإجمالي للصنف
                                    echo '<td style="font-weight:bold;background:var(--line-soft);text-align:center;">';
                                    if (!$anyDistributed) {
                                        echo '<span style="color:var(--ink-300);">—</span>';
                                    } elseif (!$anyDone) {
                                        echo '<span class="pill-yet">لسه</span>';
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
            <?php endif; ?>
        </div>

    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
