<?php
/**
 * receiving.php — موديول استلام الفواتير في الفروع
 * نظام الفجالة ستور المالي
 *
 * المراحل:
 * 1. الحالة sent: عرض كود واسم الصنف فقط (بدون كميات ولا أسعار). زر «بدء الاستلام».
 * 2. الحالة receiving: شاشة مسح باركود (كاميرا + يدوي) مع التحقق من وجود الصنف في فاتورة الفرع.
 * 3. بعد تم الاستلام: عرض شاشة الفروقات للفرع (موزع، مستلم، فرق) وزر «تمت المراجعة» للأونر والمشرف.
 * 4. الحالة approved: اعتماد الاستلام بواسطة الأونر، وتصبح السجلات غير قابلة للتعديل.
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
$canManageAll = $isOwner || $isSupervisor;

// ===== معالجة طلبات POST =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';
    $receiptId = (int) ($_POST['receipt_id'] ?? ($_GET['r'] ?? 0));

    if (!csrf_verify($_POST['csrf'] ?? '')) {
        echo json_encode(['ok' => false, 'error' => 'انتهت صلاحية الجلسة، حدث الصفحة وحاول مرة أخرى.']);
        exit;
    }

    $receipt = receipt_get($pdo, $receiptId);
    if (!$receipt) {
        echo json_encode(['ok' => false, 'error' => 'سجل الاستلام غير موجود.']);
        exit;
    }

    // حماية الأمان: مين مالوش فرع ومش أونر/مسؤول استلامات مايعملش أي حاجة.
    //    من غير الشرط ده، أي مستخدم معاه صلاحية «استلام» ومش مربوط بفرع
    //    كان يقدر يستلم باسم أي فرع في النظام.
    if (!$canManageAll && $userBranchId === null) {
        echo json_encode(['ok' => false, 'error' => 'حسابك مش مربوط بفرع — كلّم الأونر.']);
        exit;
    }
    // موظف الفرع ممنوع ينفذ أي عملية على فرع آخر
    if (!$canManageAll && $userBranchId !== null && (int)$receipt['branch_id'] !== $userBranchId) {
        echo json_encode(['ok' => false, 'error' => 'غير مصرح لك بتنفيذ عمليات على استلام فرع آخر.']);
        exit;
    }

    if ($action === 'start') {
        if ($receipt['status'] !== 'sent') {
            echo json_encode(['ok' => false, 'error' => 'الاستلام قد بدأ بالفعل أو مكتمل.']);
            exit;
        }
        $st = $pdo->prepare("UPDATE receipts SET status='receiving', started_at=NOW(), started_by=? WHERE id=? AND status='sent'");
        $st->execute([$uid, $receiptId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'scan') {
        if ($receipt['status'] !== 'receiving') {
            echo json_encode(['ok' => false, 'error' => 'لا يمكن المسح إلا في حالة جاري الاستلام.']);
            exit;
        }

        $code = trim((string)($_POST['code'] ?? ''));
        $qty  = (float)($_POST['qty'] ?? 1.0);
        if ($qty <= 0) $qty = 1.0;

        if ($code === '') {
            echo json_encode(['ok' => false, 'error' => 'كود الصنف مطلوب.']);
            exit;
        }

        // فحص الصنف في أصناف الفاتورة الخاصة بهذا الفرع
        $items = receipt_items_for_branch($pdo, $receiptId);
        $targetItem = null;
        foreach ($items as $it) {
            if ($it['item_code'] === $code) {
                $targetItem = $it;
                break;
            }
        }

        if (!$targetItem) {
            // الصنف غير موجود في فاتورة الفرع ← رفض ولا يتم الحفظ
            echo json_encode(['ok' => false, 'error' => 'الصنف ده مش في الفاتورة']);
            exit;
        }

        $ins = $pdo->prepare(
            'INSERT INTO receipt_lines (receipt_id, item_code, item_name, scanned_qty, updated_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 scanned_qty = scanned_qty + VALUES(scanned_qty),
                 updated_by  = VALUES(updated_by)'
        );
        $ins->execute([$receiptId, $code, $targetItem['item_name'], $qty, $uid]);

        $stScanned = $pdo->prepare('SELECT scanned_qty FROM receipt_lines WHERE receipt_id=? AND item_code=?');
        $stScanned->execute([$receiptId, $code]);
        $newQty = (float) $stScanned->fetchColumn();

        echo json_encode([
            'ok'          => true,
            'item_code'   => $code,
            'item_name'   => $targetItem['item_name'],
            'scanned_qty' => $newQty,
            'added_qty'   => $qty
        ]);
        exit;
    }

    if ($action === 'review') {
        if ($receipt['status'] !== 'receiving') {
            echo json_encode(['ok' => false, 'error' => 'لا يمكن إنهاء المراجعة في هذه الحالة.']);
            exit;
        }
        $st = $pdo->prepare("UPDATE receipts SET status='received', received_at=NOW(), received_by=? WHERE id=? AND status='receiving'");
        $st->execute([$uid, $receiptId]);

        // إشعار للأونر ولمسؤول الاستلامات
        $notifUsers = [];
        $stOwner = $pdo->query('SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.is_owner = 1 AND u.is_active = 1');
        foreach ($stOwner->fetchAll() as $ow) {
            $notifUsers[] = (int) $ow['id'];
        }
        if ($supervisorId > 0 && !in_array($supervisorId, $notifUsers, true)) {
            $notifUsers[] = $supervisorId;
        }
        if (!empty($notifUsers)) {
            notify(
                $pdo,
                $notifUsers,
                'receiving',
                'تمت مراجعة استلام بضاعة فرع ' . $receipt['branch_name'],
                'قام الفرع بمراجعة الاستلام والمحضر في انتظار الاعتماد النهائي.',
                'receiving.php?r=' . $receiptId
            );
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'approve') {
        if (!$isOwner) {
            echo json_encode(['ok' => false, 'error' => 'اعتماد الاستلام متاح للأونر فقط.']);
            exit;
        }
        if ($receipt['status'] !== 'received') {
            echo json_encode(['ok' => false, 'error' => 'يجب أن يكون الاستلام مراجعاً (تم الاستلام) قبل الاعتماد.']);
            exit;
        }
        $st = $pdo->prepare("UPDATE receipts SET status='approved', approved_at=NOW(), approved_by=? WHERE id=? AND status='received'");
        $st->execute([$uid, $receiptId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'أمر غير معروف.']);
    exit;
}

$receiptId = isset($_GET['r']) ? (int) $_GET['r'] : 0;
$active_module = 'receiving';
require_once __DIR__ . '/includes/header.php';
?>

<style>
/* تعيين أنماط الأزرار محلياً طبقاً للقواعد */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: var(--r);
    font-size: 13.5px;
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
.btn-success {
    background: var(--pos);
    color: #fff;
}
.btn-success:hover {
    background: var(--pos-fill);
    color: #fff;
}
.btn-danger {
    background: var(--neg);
    color: #fff;
}
.btn-danger:hover {
    background: var(--neg-fill);
    color: #fff;
}
.btn-sm {
    padding: 4px 10px;
    font-size: 12px;
}
.status-pill {
    display: inline-block;
    padding: 3px 8px;
    border-radius: var(--r-sm);
    font-size: 11.5px;
    font-weight: 600;
}
.pill-sent { background: var(--ink-200); color: var(--ink-700); }
.pill-receiving { background: var(--warn-soft); color: var(--warn); }
.pill-received { background: var(--pos-soft); color: var(--pos); }
.pill-approved { background: var(--pos); color: #fff; }

.receiving-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--r);
    padding: 16px;
    margin-bottom: 14px;
    box-shadow: var(--shadow-sm);
}
.clickable-card {
    cursor: pointer;
    transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
}
.clickable-card:hover {
    border-color: var(--accent);
    box-shadow: 0 4px 14px rgba(0,0,0,0.07);
}
.rc-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid var(--line-soft);
    padding-bottom: 10px;
    margin-bottom: 12px;
}
.rc-branches {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.branch-link-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    background: var(--bg);
    border: 1px solid var(--line);
    border-radius: var(--r-sm);
    text-decoration: none;
    color: var(--text);
    font-size: 13px;
    transition: all 0.15s ease;
}
.branch-link-btn:hover {
    border-color: var(--accent);
    background: var(--accent-soft);
    color: var(--accent-text);
}
.rc-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
    font-size: 13px;
}
.rc-table th, .rc-table td {
    padding: 8px 12px;
    border-bottom: 1px solid var(--line-soft);
    text-align: right;
}
.rc-table th {
    background: var(--bg);
    color: var(--ink-600);
    font-weight: 600;
}
.scan-box-wrap {
    background: var(--bg);
    border: 1px solid var(--line);
    border-radius: var(--r);
    padding: 16px;
    margin-bottom: 16px;
}
.scan-alert {
    padding: 10px 14px;
    border-radius: var(--r-sm);
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 12px;
    display: none;
}
.scan-alert-error {
    background: var(--neg-soft);
    color: var(--neg);
    border: 1px solid var(--neg);
}
.scan-alert-success {
    background: var(--pos-soft);
    color: var(--pos);
    border: 1px solid var(--pos);
}
</style>

<div class="content-container">

<?php if ($receiptId <= 0): ?>
    <!-- ===================================================================== -->
    <!--  عرض قايمة الهيدرز (فواتير / مجموعات للاستلام)                       -->
    <!-- ===================================================================== -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <h2 style="font-size:20px;font-weight:bold;margin:0;"><i class="ti ti-package"></i> استلام الفواتير في الفروع</h2>
        <?php if ($isOwner || $isSupervisor || can('receiving:diffs') || $userBranchId !== null): ?>
            <a href="receiving_diffs.php" class="btn btn-ghost"><i class="ti ti-chart-bar"></i> صفحة الفروقات الشاملة</a>
        <?php endif; ?>
    </div>

    <?php
    // جلب كل سجلات الاستلام المتاحة
    $where = [];
    $params = [];
    if (!$canManageAll) {
        // مالوش فرع ومش أونر/مسؤول ← مايشوفش أي استلام خالص (فرع 0 مالوش سجلات)
        $where[] = 'r.branch_id = ?';
        $params[] = $userBranchId !== null ? $userBranchId : 0;
    }
    $whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stList = $pdo->prepare(
        "SELECT r.id AS receipt_id, r.group_id, r.distribution_id, r.branch_id, r.status, r.created_at,
                b.name AS branch_name,
                g.name AS group_name,
                d.invoice_no
         FROM receipts r
         JOIN branches b ON b.id = r.branch_id
         LEFT JOIN invoice_groups g ON g.id = r.group_id AND r.group_id > 0
         LEFT JOIN invoice_distribution d ON d.id = r.distribution_id AND r.distribution_id > 0
         $whereSql
         ORDER BY r.id DESC"
    );
    $stList->execute($params);
    $allReceipts = $stList->fetchAll();

    if (empty($allReceipts)):
    ?>
        <div class="receiving-card" style="text-align:center;padding:36px 20px;color:var(--ink-500);">
            <i class="ti ti-inbox" style="font-size:36px;display:block;margin-bottom:8px;"></i>
            لا توجد فواتير أو بضائع جاهزة للاستلام حالياً.
        </div>
    <?php
    else:
        // جلب معرفات المجموعات والفواتير المفردة لحساب الكميات الموزعة خارج الحلقات
        $gIds = [];
        $singleDistIds = [];
        foreach ($allReceipts as $rc) {
            $gid = (int) $rc['group_id'];
            $did = (int) $rc['distribution_id'];
            if ($gid > 0) {
                $gIds[$gid] = true;
            } elseif ($did > 0) {
                $singleDistIds[$did] = true;
            }
        }
        $gIds = array_keys($gIds);
        $singleDistIds = array_keys($singleDistIds);

        // ربط المجموعات بسجلات التوزيع الخاصة بها باستعلام واحد مجمّع
        $groupDistsMap = []; // [group_id => [dist_id, ...]]
        $allDistIdsToQuery = $singleDistIds;
        if (!empty($gIds)) {
            $gPh = implode(',', array_fill(0, count($gIds), '?'));
            $stGDist = $pdo->prepare(
                "SELECT DISTINCT gi.group_id, d.id AS dist_id
                 FROM invoice_group_items gi
                 JOIN invoice_distribution d ON d.invoice_no = gi.invoice_no AND " . dist_key_sql('d', 'gi.company_branch', 'gi.doc_type') . "
                 WHERE gi.group_id IN ($gPh)"
            );
            $stGDist->execute($gIds);
            foreach ($stGDist->fetchAll() as $gRow) {
                $gid = (int) $gRow['group_id'];
                $did = (int) $gRow['dist_id'];
                if ($did > 0) {
                    $groupDistsMap[$gid][] = $did;
                    $allDistIdsToQuery[] = $did;
                }
            }
        }

        $allDistIdsToQuery = array_values(array_unique($allDistIdsToQuery));

        // جلب إجمالي الكميات الموزعة لكل فرع باستعلام واحد مجمّع خارج أي حلقة
        $distBranchQty = [];
        if (!empty($allDistIdsToQuery)) {
            $dPh = implode(',', array_fill(0, count($allDistIdsToQuery), '?'));
            $stBranchQty = $pdo->prepare(
                "SELECT dl.distribution_id, dl.branch_id, SUM(dl.amount) AS total_qty
                 FROM invoice_distribution_lines dl
                 WHERE dl.distribution_id IN ($dPh) AND dl.amount > 0 AND dl.branch_id <> 6
                 GROUP BY dl.distribution_id, dl.branch_id"
            );
            $stBranchQty->execute($allDistIdsToQuery);
            foreach ($stBranchQty->fetchAll() as $qRow) {
                $did = (int) $qRow['distribution_id'];
                $bid = (int) $qRow['branch_id'];
                $qty = (float) $qRow['total_qty'];
                $distBranchQty[$did][$bid] = ($distBranchQty[$did][$bid] ?? 0.0) + $qty;
            }
        }

        // تجميع السجلات حسب الهيدر مع إخفاء أي فرع ليس له كمية موزعة (> 0)
        $headersMap = [];
        foreach ($allReceipts as $rc) {
            $gid = (int) $rc['group_id'];
            $did = (int) $rc['distribution_id'];
            $bid = (int) $rc['branch_id'];

            // حساب الكمية الموزعة لهذا الفرع في هذا السياق
            $branchDistributedQty = 0.0;
            if ($gid > 0) {
                $contextDists = $groupDistsMap[$gid] ?? [];
                foreach ($contextDists as $cDid) {
                    $branchDistributedQty += ($distBranchQty[$cDid][$bid] ?? 0.0);
                }
            } else {
                $branchDistributedQty = ($distBranchQty[$did][$bid] ?? 0.0);
            }

            // الشرط ٥: لو الفرع ليس له أي كميات موزعة، لا يظهر نهائياً في الكارت
            if ($branchDistributedQty <= 0.0001) {
                continue;
            }

            $key = $gid > 0 ? ('g_' . $gid) : ('d_' . $did);
            if (!isset($headersMap[$key])) {
                $title = $gid > 0
                    ? ('مجموعة فواتير #' . $gid . ' — ' . ($rc['group_name'] ?: 'بدون اسم'))
                    : ('فاتورة #' . ($rc['invoice_no'] ?: $did));
                $headersMap[$key] = [
                    'group_id'        => $gid,
                    'distribution_id' => $did,
                    'title'           => $title,
                    'created_at'      => $rc['created_at'],
                    'branches'        => [],
                ];
            }
            $headersMap[$key]['branches'][] = $rc;
        }

        foreach ($headersMap as $hKey => $hdr):
            if (empty($hdr['branches'])) continue;
            $isGroup = $hdr['group_id'] > 0;
            $diffUrl = 'receiving_diffs.php?' . ($isGroup ? ('g=' . $hdr['group_id']) : ('d=' . $hdr['distribution_id']));

            // الحارس الذكي (Smart Guard):
            // - موظف الفرع (users.branch_id > 0): النقر على الكارت يوجهه مباشرةً لاستلام فرعه
            // - الإدارة / الأونر / المشرف: النقر على الكارت يوجهه لصفحة الفروقات الشاملة
            $cardTargetUrl = $diffUrl;
            if ($userBranchId !== null && $userBranchId > 0) {
                $myReceiptId = 0;
                foreach ($hdr['branches'] as $br) {
                    if ((int) $br['branch_id'] === $userBranchId) {
                        $myReceiptId = (int) $br['receipt_id'];
                        break;
                    }
                }
                if ($myReceiptId > 0) {
                    $cardTargetUrl = 'receiving.php?r=' . $myReceiptId;
                }
            }
    ?>
        <div class="receiving-card clickable-card" data-url="<?= e($cardTargetUrl) ?>">
            <div class="rc-header">
                <div>
                    <h3 style="margin:0 0 4px 0;font-size:16px;">
                        <?= e($hdr['title']) ?>
                    </h3>
                    <span style="font-size:12px;color:var(--ink-400);">تاريخ التجهيز: <?= date('Y-m-d H:i', strtotime($hdr['created_at'])) ?></span>
                </div>
                <div>
                    <a href="<?= e($diffUrl) ?>" class="btn btn-ghost btn-sm"><i class="ti ti-chart-bar"></i> الفروقات</a>
                </div>
            </div>

            <div class="rc-branches">
                <?php foreach ($hdr['branches'] as $br):
                    $pillClass = 'pill-' . $br['status'];
                    $statusText = match($br['status']) {
                        'sent'      => 'أُرسلت (جاهزة)',
                        'receiving' => 'يتم الاستلام',
                        'received'  => 'تم الاستلام',
                        'approved'  => 'معتمد',
                        default     => $br['status']
                    };
                    $rLink = 'receiving.php?r=' . (int)$br['receipt_id'];
                ?>
                    <a href="<?= e($rLink) ?>" class="branch-link-btn">
                        <b><?= e($br['branch_name']) ?></b>
                        <span class="status-pill <?= $pillClass ?>"><?= e($statusText) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php
        endforeach;
    ?>
    <script>
    document.querySelectorAll('.clickable-card').forEach(function(card) {
        card.addEventListener('click', function(e) {
            // لو النقر على رابط أو زرار داخلي نسيب الرابط يشتغل طبيعي
            if (e.target.closest('a, button, input, select')) return;
            var url = this.getAttribute('data-url');
            if (url && url !== '#') {
                window.location.href = url;
            }
        });
    });
    </script>
    <?php
    endif;
    ?>

<?php else:
    // =====================================================================
    //  عرض شاشة تفاصيل الاستلام لفرع معين (?r=<receipt_id>)
    // =====================================================================
    $receipt = receipt_get($pdo, $receiptId);
    if (!$receipt):
?>
        <div class="receiving-card" style="text-align:center;color:var(--neg);padding:24px;">
            <i class="ti ti-alert-triangle"></i> سجل الاستلام المطلوب غير موجود.
            <div style="margin-top:12px;"><a href="receiving.php" class="btn btn-ghost btn-sm">← رجوع للقائمة</a></div>
        </div>
<?php
        require_once __DIR__ . '/includes/footer.php';
        exit;
    endif;

    // حماية الأمان (الحالة 205): موظف الفرع يفتح استلام فرع آخر ← رفض فوري وممنوع ظهور أي كميات
    if (!$canManageAll && $userBranchId !== null && (int)$receipt['branch_id'] !== $userBranchId):
?>
        <div class="receiving-card" style="text-align:center;color:var(--neg);padding:36px 20px;">
            <i class="ti ti-ban" style="font-size:36px;display:block;margin-bottom:8px;"></i>
            <b style="font-size:16px;">غير مصرح لك بالوصول لاستلام فرع آخر.</b>
            <div style="margin-top:16px;"><a href="receiving.php" class="btn btn-ghost btn-sm">← الرجوع لاستلامات فرعك</a></div>
        </div>
<?php
        require_once __DIR__ . '/includes/footer.php';
        exit;
    endif;

    $items = receipt_items_for_branch($pdo, $receiptId);
    $status = $receipt['status'];
    $isGroup = (int)$receipt['group_id'] > 0;
    $diffUrl = 'receiving_diffs.php?' . ($isGroup ? ('g=' . $receipt['group_id']) : ('d=' . $receipt['distribution_id']));
    $showDiffView = ($status === 'received' || $status === 'approved' || isset($_GET['diff']));
?>

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
        <div>
            <div style="display:flex;align-items:center;gap:10px;">
                <h2 style="font-size:18px;font-weight:bold;margin:0;">
                    <i class="ti ti-package"></i> استلام فرع: <?= e($receipt['branch_name']) ?>
                </h2>
                <span class="status-pill pill-<?= $status ?>">
                    <?= match($status) {
                        'sent'      => 'أُرسلت (جاهزة للبدء)',
                        'receiving' => 'يتم الاستلام بالكاميرا',
                        'received'  => 'تم الاستلام (بانتظار الاعتماد)',
                        'approved'  => 'معتمد نهائياً',
                        default     => $status
                    } ?>
                </span>
            </div>
            <div style="font-size:12.5px;color:var(--ink-400);margin-top:4px;">
                <?= $isGroup ? ('المجموعة #' . $receipt['group_id']) : ('الفاتورة #' . $receipt['distribution_id']) ?>
            </div>
        </div>

        <div style="display:flex;gap:8px;align-items:center;">
            <a href="<?= e($diffUrl) ?>" class="btn btn-ghost btn-sm"><i class="ti ti-chart-bar"></i> صفحة الفروقات</a>
            <a href="receiving.php" class="btn btn-ghost btn-sm">← القائمة</a>
        </div>
    </div>

    <!-- التنبيهات -->
    <div id="scanAlertMsg" class="scan-alert"></div>

    <?php if ($status === 'sent'): ?>
        <!-- ================================================================= -->
        <!--  المرحلة 1 (الحالة sent): جدول كود واسم الصنف وبس. بدون كميات ولا أسعار.  -->
        <!-- ================================================================= -->
        <div class="receiving-card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <div>
                    <b>قائمة الأصناف المتوقعة للفرع</b>
                    <span style="font-size:12px;color:var(--ink-400);margin-right:6px;">(<?= count($items) ?> صنف)</span>
                </div>
                <button type="button" class="btn btn-success" onclick="startReceiving()">
                    <i class="ti ti-player-play"></i> بدء الاستلام
                </button>
            </div>

            <table class="rc-table">
                <thead>
                    <tr>
                        <th style="width:50px;">#</th>
                        <th style="width:180px;">كود الصنف</th>
                        <th>اسم الصنف</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; foreach ($items as $it): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><code><?= e($it['item_code']) ?></code></td>
                            <td><?= e($it['item_name']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($status === 'receiving' && !$showDiffView): ?>
        <!-- ================================================================= -->
        <!--  المرحلة 2 (الحالة receiving): شاشة المسح بالكاميرا أو الإدخال اليدوي -->
        <!-- ================================================================= -->
        <div class="scan-box-wrap">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <h3 style="margin:0;font-size:15px;"><i class="ti ti-scan"></i> مسح باركود الأصناف</h3>
                <a href="receiving.php?r=<?= $receiptId ?>&diff=1" class="btn btn-success">
                    <i class="ti ti-check"></i> تم الاستلام ومراجعة الفروقات
                </a>
            </div>

            <form id="barcodeScanForm" onsubmit="return handleManualScan(event);" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                <div style="flex:2;min-width:200px;">
                    <label style="display:block;font-size:12px;color:var(--ink-600);margin-bottom:4px;">كود الصنف / الباركود</label>
                    <div style="display:flex;gap:6px;">
                        <input type="text" id="codeInput" name="code" required autofocus autocomplete="off"
                               placeholder="امسح أو اكتب الكود..."
                               style="flex:1;padding:8px 12px;border:1px solid var(--line);border-radius:var(--r);font-size:14px;">
                        <button type="button" onclick="openBarcodeScanner('codeInput')" title="مسح بالكاميرا"
                                style="background:var(--accent);color:#fff;border:none;border-radius:var(--r);padding:0 14px;cursor:pointer;font-size:18px;">
                            <i class="ti ti-camera"></i>
                        </button>
                    </div>
                </div>

                <div style="width:100px;">
                    <label style="display:block;font-size:12px;color:var(--ink-600);margin-bottom:4px;">الكمية</label>
                    <input type="number" id="qtyInput" name="qty" step="1" min="1" value="1"
                           style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:var(--r);font-size:14px;text-align:center;">
                </div>

                <div>
                    <button type="submit" class="btn" style="height:38px;">مسح / إضافة</button>
                </div>
            </form>
        </div>

        <!-- آخر الأصناف الممسوحة -->
        <div class="receiving-card">
            <h4 style="margin:0 0 10px 0;font-size:14px;">آخر الأصناف الممسوحة</h4>
            <div id="lastScannedListContainer">
                <?php $scannedHistory = receipt_last_scanned($pdo, $receiptId, 20); ?>
                <table class="rc-table" id="lastScannedTable">
                    <thead>
                        <tr>
                            <th>كود الصنف</th>
                            <th>اسم الصنف</th>
                            <th style="width:110px;">الكمية المستلمة</th>
                        </tr>
                    </thead>
                    <tbody id="lastScannedTbody">
                        <?php if (empty($scannedHistory)): ?>
                            <tr id="emptyScanRow"><td colspan="3" style="text-align:center;color:var(--ink-400);">لم يتم مسح أي صنف حتى الآن.</td></tr>
                        <?php else: foreach ($scannedHistory as $sh): ?>
                            <tr>
                                <td><code><?= e($sh['item_code']) ?></code></td>
                                <td><?= e($sh['item_name']) ?></td>
                                <td><b style="color:var(--pos);"><?= (float)$sh['scanned_qty'] ?></b></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php else: ?>
        <!-- ================================================================= -->
        <!--  المرحلة 3 و 4: شاشة الفروقات الخاصة بالفرع بعد الانتهاء من المسح  -->
        <!-- ================================================================= -->
        <div class="receiving-card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
                <div>
                    <h3 style="margin:0;font-size:16px;">جدول مطابقة وفروقات الفرع</h3>
                    <span style="font-size:12px;color:var(--ink-400);">الفروقات بين الكمية الموزعة والكمية المستلمة فعلياً بالكاميرا.</span>
                </div>

                <div style="display:flex;gap:8px;">
                    <?php if ($status === 'receiving'): ?>
                        <a href="receiving.php?r=<?= $receiptId ?>" class="btn btn-ghost btn-sm">← رجوع للمسح</a>
                        <button type="button" class="btn btn-success" onclick="finishReview()">
                            <i class="ti ti-check"></i> تمت المراجعة
                        </button>
                    <?php elseif ($status === 'received' && $isOwner): ?>
                        <button type="button" class="btn btn-success" onclick="approveReceipt()">
                            <i class="ti ti-circle-check"></i> اعتماد الاستلام النهائي
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <table class="rc-table">
                <thead>
                    <tr>
                        <th style="width:160px;">كود الصنف</th>
                        <th>اسم الصنف</th>
                        <th style="width:110px;">الموزّع</th>
                        <th style="width:110px;">المستلم</th>
                        <th style="width:110px;">الفرق</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $it):
                        $distQ = (float)$it['distributed_qty'];
                        $scanQ = (float)$it['scanned_qty'];
                        $diff  = $distQ - $scanQ;
                    ?>
                        <tr>
                            <td><code><?= e($it['item_code']) ?></code></td>
                            <td><?= e($it['item_name']) ?></td>
                            <td><?= $distQ ?></td>
                            <td><b><?= $scanQ ?></b></td>
                            <td>
                                <?php if ($diff == 0): ?>
                                    <span style="color:var(--pos);font-weight:bold;">0 <i class="ti ti-check"></i></span>
                                <?php elseif ($diff > 0): ?>
                                    <span style="color:var(--neg);font-weight:bold;">-<?= $diff ?> (عجز)</span>
                                <?php else: ?>
                                    <span style="color:var(--accent-text);font-weight:bold;">+<?= abs($diff) ?> (زيادة)</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

</div>

<!-- المكون المشترك للسكانر (كاميرا الموبايل) -->
<script src="assets/barcode_scanner.js?v=4"></script>

<script>
var csrfToken = <?= json_encode(csrf_token()) ?>;
var receiptId = <?= (int)$receiptId ?>;

function showAlert(msg, isError) {
    var box = document.getElementById('scanAlertMsg');
    if (!box) return;
    box.textContent = msg;
    box.className = isError ? 'scan-alert scan-alert-error' : 'scan-alert scan-alert-success';
    box.style.display = 'block';
    if (!isError) {
        setTimeout(function(){ box.style.display = 'none'; }, 3000);
    }
}

function startReceiving() {
    if (!confirm('هل تريد بدء استلام هذه الفاتورة الآن؟')) return;
    var fd = new FormData();
    fd.append('action', 'start');
    fd.append('receipt_id', receiptId);
    fd.append('csrf', csrfToken);

    fetch('receiving.php?r=' + receiptId, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                location.reload();
            } else {
                alert(d.error || 'حدث خطأ');
            }
        })
        .catch(err => alert('تعذر الاتصال بالخادم'));
}

function handleManualScan(e) {
    e.preventDefault();
    var codeBox = document.getElementById('codeInput');
    var qtyBox  = document.getElementById('qtyInput');
    var code = (codeBox.value || '').trim();
    var qty  = parseFloat(qtyBox.value) || 1;

    if (!code) return false;

    var fd = new FormData();
    fd.append('action', 'scan');
    fd.append('receipt_id', receiptId);
    fd.append('code', code);
    fd.append('qty', qty);
    fd.append('csrf', csrfToken);

    fetch('receiving.php?r=' + receiptId, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                showAlert('تم مسح: ' + d.item_name + ' (الكمية المستلمة: ' + d.scanned_qty + ')', false);
                codeBox.value = '';
                qtyBox.value = '1';
                codeBox.focus();
                updateLastScannedTable(d.item_code, d.item_name, d.scanned_qty);
            } else {
                showAlert(d.error || 'الصنف ده مش في الفاتورة', true);
                codeBox.select();
            }
        })
        .catch(err => showAlert('تعذر الاتصال بالخادم', true));

    return false;
}

function updateLastScannedTable(code, name, scannedQty) {
    var tbody = document.getElementById('lastScannedTbody');
    if (!tbody) return;
    var emptyRow = document.getElementById('emptyScanRow');
    if (emptyRow) emptyRow.remove();

    var existingRow = null;
    var rows = tbody.getElementsByTagName('tr');
    for (var i = 0; i < rows.length; i++) {
        var codeCell = rows[i].querySelector('code');
        if (codeCell && codeCell.textContent === code) {
            existingRow = rows[i];
            break;
        }
    }

    if (existingRow) {
        var qtyCell = existingRow.querySelector('b');
        if (qtyCell) qtyCell.textContent = scannedQty;
        tbody.prepend(existingRow);
    } else {
        var tr = document.createElement('tr');
        tr.innerHTML = '<td><code>' + escapeHtml(code) + '</code></td>' +
                       '<td>' + escapeHtml(name) + '</td>' +
                       '<td><b style="color:var(--pos);">' + scannedQty + '</b></td>';
        tbody.prepend(tr);
    }
}

function finishReview() {
    if (!confirm('هل تأكدت من مراجعة كل البضاعة المستلمة وإنهاء المراجعة؟')) return;
    var fd = new FormData();
    fd.append('action', 'review');
    fd.append('receipt_id', receiptId);
    fd.append('csrf', csrfToken);

    fetch('receiving.php?r=' + receiptId, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                location.href = 'receiving.php?r=' + receiptId;
            } else {
                alert(d.error || 'حدث خطأ');
            }
        })
        .catch(err => alert('تعذر الاتصال بالخادم'));
}

function approveReceipt() {
    if (!confirm('هل تريد اعتماد استلام هذا الفرع نهائياً؟')) return;
    var fd = new FormData();
    fd.append('action', 'approve');
    fd.append('receipt_id', receiptId);
    fd.append('csrf', csrfToken);

    fetch('receiving.php?r=' + receiptId, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                location.reload();
            } else {
                alert(d.error || 'حدث خطأ');
            }
        })
        .catch(err => alert('تعذر الاتصال بالخادم'));
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
