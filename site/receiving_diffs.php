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

$pdo  = db();
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

$active_module = 'receiving';
require_once __DIR__ . '/includes/header.php';
?>

<style>
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: var(--r);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid transparent;
    background: var(--accent);
    color: #fff;
    text-decoration: none;
    transition: background 0.15s ease, border-color 0.15s ease;
}
.btn:hover {
    background: var(--accent-hover);
    color: #fff;
}
.btn-ghost {
    background: var(--surface);
    color: var(--text);
    border-color: var(--line);
}
.btn-ghost:hover {
    background: var(--accent-soft);
    border-color: var(--accent);
    color: var(--accent-text);
}
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
            <?php
            $stAvailable = $pdo->query(
                "SELECT r.group_id, r.distribution_id,
                        MAX(r.created_at) AS last_date,
                        g.name AS group_name,
                        d.invoice_no,
                        COUNT(DISTINCT r.branch_id) AS branches_count
                 FROM receipts r
                 LEFT JOIN invoice_groups g ON g.id = r.group_id AND r.group_id > 0
                 LEFT JOIN invoice_distribution d ON d.id = r.distribution_id AND r.distribution_id > 0
                 GROUP BY r.group_id, r.distribution_id
                 ORDER BY last_date DESC"
            );
            $availableList = $stAvailable ? $stAvailable->fetchAll() : [];

            if (empty($availableList)):
            ?>
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

    <?php else:
        // جلب سياق المجموعة أو الفاتورة
        $isGrp = $groupId > 0;
        $title = '';
        if ($isGrp) {
            $stG = $pdo->prepare('SELECT name FROM invoice_groups WHERE id = ?');
            $stG->execute([$groupId]);
            $gName = $stG->fetchColumn();
            $title = 'مجموعة فواتير #' . $groupId . ($gName ? ' — ' . $gName : '');
        } else {
            $stD = $pdo->prepare('SELECT invoice_no FROM invoice_distribution WHERE id = ?');
            $stD->execute([$distId]);
            $invNo = $stD->fetchColumn();
            $title = 'فاتورة مفردة #' . ($invNo ?: $distId);
        }

        // جلب الفروع الخمسة (باستثناء الإدارة 6)
        $branches = $pdo->query('SELECT id, name FROM branches WHERE id <> 6 AND is_active = 1 ORDER BY id')->fetchAll();

        // جلب سجلات receipts المفتوحة لهذا السياق
        $stReceipts = $pdo->prepare(
            'SELECT id, branch_id, status FROM receipts
             WHERE (group_id = ? AND distribution_id = 0) OR (group_id = 0 AND distribution_id = ?)'
        );
        $stReceipts->execute([$groupId, $distId]);
        $receiptsByBranch = [];
        $receiptIds = [];
        foreach ($stReceipts->fetchAll() as $rc) {
            $b = (int) $rc['branch_id'];
            $receiptsByBranch[$b] = $rc;
            $receiptIds[] = (int) $rc['id'];
        }

        // جلب معرفات التوزيع
        $distIds = receiving_ctx_dist_ids($distId, $groupId);
        $distIdsClean = array_values(array_unique(array_filter(array_map('intval', $distIds), fn($x) => $x > 0)));

        $itemsMap = [];
        $codes = [];

        if (!empty($distIdsClean)) {
            $inClause = implode(',', $distIdsClean);

            // جلب الكميات الموزعة لكل صنف وكل فرع
            $stDistLines = $pdo->query(
                "SELECT dl.notes AS item_code, dl.branch_id, SUM(dl.amount) AS distributed_qty
                 FROM invoice_distribution_lines dl
                 WHERE dl.distribution_id IN ($inClause) AND dl.amount > 0 AND dl.branch_id <> 6
                 GROUP BY dl.notes, dl.branch_id"
            );
            $distRows = $stDistLines ? $stDistLines->fetchAll() : [];

            foreach ($distRows as $dr) {
                $c   = trim((string)$dr['item_code']);
                $bid = (int)$dr['branch_id'];
                $qty = (float)$dr['distributed_qty'];
                if ($c === '') continue;

                $codes[] = $c;
                if (!isset($itemsMap[$c])) {
                    $itemsMap[$c] = [
                        'item_code'   => $c,
                        'item_name'   => $c,
                        'by_branch'   => [],
                    ];
                }
                $itemsMap[$c]['by_branch'][$bid]['dist'] = $qty;
            }

            // جلب الكميات الممسوحة لكل صنف وكل فرع
            if (!empty($receiptIds)) {
                $rcIn = implode(',', array_unique($receiptIds));
                $stScanned = $pdo->query(
                    "SELECT r.branch_id, rl.item_code, SUM(rl.scanned_qty) AS scanned_qty
                     FROM receipt_lines rl
                     JOIN receipts r ON r.id = rl.receipt_id
                     WHERE r.id IN ($rcIn)
                     GROUP BY r.branch_id, rl.item_code"
                );
                $scannedRows = $stScanned ? $stScanned->fetchAll() : [];

                foreach ($scannedRows as $sr) {
                    $c   = trim((string)$sr['item_code']);
                    $bid = (int)$sr['branch_id'];
                    $sq  = (float)$sr['scanned_qty'];
                    if (isset($itemsMap[$c])) {
                        $itemsMap[$c]['by_branch'][$bid]['scan'] = $sq;
                    }
                }
            }

            // جلب أسماء الأصناف باستعلام واحد مجمّع
            if (!empty($codes)) {
                $codesUnique = array_values(array_unique($codes));
                $placeholders = implode(',', array_fill(0, count($codesUnique), '?'));
                $stNames = $pdo->prepare(
                    "SELECT c.code AS item_code, i.name AS item_name
                     FROM hq_codes c
                     JOIN hq_items i ON i.item_id = c.item_id
                     WHERE c.code IN ($placeholders)"
                );
                $stNames->execute($codesUnique);
                foreach ($stNames->fetchAll() as $nr) {
                    $c = (string)$nr['item_code'];
                    if (isset($itemsMap[$c])) {
                        $itemsMap[$c]['item_name'] = (string)($nr['item_name'] ?: $c);
                    }
                }
            }
        }

        uasort($itemsMap, fn($a, $b) => strcmp($a['item_name'], $b['item_name']));
    ?>

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
