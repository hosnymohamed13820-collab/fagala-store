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

    // حذف/إلغاء فاتورة أو مجموعة بالكامل من الاستلامات (للأونر فقط)
    if ($action === 'delete_invoice_receipts') {
        if (!$isOwner) {
            echo json_encode(['ok' => false, 'error' => 'إلغاء الفواتير متاح للأونر فقط.']);
            exit;
        }
        $delGroupId = (int)($_POST['group_id'] ?? 0);
        $delDistId  = (int)($_POST['distribution_id'] ?? 0);
        if ($delGroupId <= 0 && $delDistId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'معرف الفاتورة أو المجموعة غير صحيح.']);
            exit;
        }

        receipt_delete_by_invoice($pdo, $delGroupId, $delDistId);
        echo json_encode(['ok' => true]);
        exit;
    }

    $receipt = receipt_get($pdo, $receiptId);
    if (!$receipt) {
        echo json_encode(['ok' => false, 'error' => 'سجل الاستلام غير موجود.']);
        exit;
    }

    // حذف/إلغاء استلام فرع مفرد (للأونر فقط)
    if ($action === 'delete_receipt') {
        if (!$isOwner) {
            echo json_encode(['ok' => false, 'error' => 'إلغاء الاستلام متاح للأونر فقط.']);
            exit;
        }
        receipt_delete($pdo, $receiptId);
        echo json_encode(['ok' => true]);
        exit;
    }

    // حماية الأمان: مين مالوش فرع ومش أونر/مسؤول استلامات مايعملش أي حاجة،
    // وموظف الفرع ممنوع ينفذ أي عملية على فرع آخر (بريف ١٥٢: الحارس في المكتبة)
    $scopeErr = receiving_scope_error($receipt, $canManageAll, $userBranchId);
    if ($scopeErr !== null) {
        echo json_encode(['ok' => false, 'error' => $scopeErr]);
        exit;
    }

    if ($action === 'start') {
        try {
            receipt_start($pdo, $receipt, $uid);
            echo json_encode(['ok' => true]);
        } catch (RuntimeException $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // استعلام فوري عن بيانات الصنف قبل الإضافة (Lookup)
    if ($action === 'lookup') {
        try {
            echo json_encode(['ok' => true] + receipt_lookup($pdo, $receipt, (string)($_POST['code'] ?? '')));
        } catch (RuntimeException $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'scan') {
        try {
            echo json_encode(['ok' => true] + receipt_scan(
                $pdo,
                $receipt,
                (string)($_POST['code'] ?? ''),
                (float)($_POST['qty'] ?? 1.0),
                $uid
            ));
        } catch (RuntimeException $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // تعديل كمية مستلمة مباشرة لصنف (تصحيح خطأ أو إنقاص كمية زيادة)
    if ($action === 'update_qty') {
        try {
            echo json_encode(['ok' => true] + receipt_set_qty(
                $pdo,
                $receipt,
                (string)($_POST['code'] ?? ''),
                (float)($_POST['qty'] ?? 0),
                $uid
            ));
        } catch (RuntimeException $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'review') {
        try {
            receipt_review($pdo, $receipt, $uid, $supervisorId);
            echo json_encode(['ok' => true]);
        } catch (RuntimeException $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // رفض الاستلام وإعادة الفتح للفرع (للأونر أو المشرف)
    if ($action === 'reject_review') {
        if (!$canManageAll) {
            echo json_encode(['ok' => false, 'error' => 'رفض الاستلام متاح للأونر والمشرف فقط.']);
            exit;
        }
        $senderName = $user['full_name'] ?? ($isOwner ? 'الأونر' : 'مشرف الاستلامات');
        try {
            receipt_reject_review($pdo, $receipt, $uid, (string)($_POST['reason'] ?? ''), $senderName);
            echo json_encode(['ok' => true]);
        } catch (RuntimeException $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'approve') {
        if (!$isOwner) {
            echo json_encode(['ok' => false, 'error' => 'اعتماد الاستلام متاح للأونر فقط.']);
            exit;
        }
        try {
            receipt_approve($pdo, $receipt, $uid);
            echo json_encode(['ok' => true]);
        } catch (RuntimeException $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
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
/* الشكل كله من المكتبة الموحّدة — اللي هنا كروت الاستلام وشبكة المسح بس */
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
    font-size: var(--fs-base);
    transition: all 0.15s ease;
}
.branch-link-btn:hover {
    border-color: var(--accent);
    background: var(--accent-soft);
    color: var(--accent-text);
}
/* ==================================================================
   الموبايل: زرار الكاميرا كان بيطلع برّه مربع المسح.
   السبب: الـinput جوه flex عنده min-width:auto — مابيصغّرش عن عرضه
   الافتراضي، فالزرار بيتزقّ لبرّه الحدود بدل ما الخانة تصغّر.
   الحل: (١) نسمح للخانات تصغّر، (٢) على الموبايل كل خانة تاخد سطر.
   ================================================================== */
#barcodeScanForm .finput { min-width: 0; }

@media (max-width: 560px) {
    #barcodeScanForm > div { flex: 1 1 100% !important; min-width: 0 !important; }
    #barcodeScanForm #qtyInput { width: 100% !important; }
    #barcodeScanForm #addBtn  { width: 100%; }
}
</style>

<div class="content-container">

<?php if ($receiptId <= 0): ?>
    <!-- ===================================================================== -->
    <!--  عرض قايمة الهيدرز (فواتير / مجموعات للاستلام)                       -->
    <!-- ===================================================================== -->
    <div class="fpage-head">
        <div>
            <h1 class="fpage-title"><i class="ti ti-package"></i> استلام الفواتير في الفروع</h1>
        </div>
        <div class="fpage-actions">
        <?php if ($isOwner || $isSupervisor || can('receiving:diffs') || $userBranchId !== null): ?>
            <a href="receiving_diffs.php" class="btn btn-outline"><i class="ti ti-chart-bar"></i> صفحة الفروقات الشاملة</a>
        <?php endif; ?>
        </div>
    </div>

    <?php
    // جلب كل سجلات الاستلام المتاحة + تجميع الكروت
    // (بريف ١٥٢: الاستعلامات دي اتنقلت لـ receiving_list_data() — نفس الجمل بالحرف)
    $recvList      = receiving_list_data($pdo, $canManageAll, $userBranchId);
    $allReceipts   = $recvList['all_receipts'];
    $soloGroupName = $recvList['solo_group_name'];
    $headersMap    = $recvList['headers_map'];
    ?>

    <script>
    /* 🔴 لازم تفضل فوق الكروت.
           الكروت تحتيها بتتكتب في حلقة وبتزيد كل يوم. لو التعريف نزل
           آخر الصفحة، اللي يكتب في خانة البحث قبل ما الصفحة تكمّل
           مايحصلش حاجة وتتسجّل غلطة في سجل السيرفر.
           (نفس بق `toggleSubtype` اللي اتصلح في بريف ٢٤.) */
        function recvFilter() {
            var box = document.getElementById('recvSearch');
            if (!box) return;
            var q = (box.value || '').trim().toLowerCase();
            var cards = document.querySelectorAll('.receiving-card[data-s]');
            var shown = 0;
            for (var i = 0; i < cards.length; i++) {
                var ok = !q || (cards[i].dataset.s || '').indexOf(q) !== -1;
                cards[i].style.display = ok ? '' : 'none';
                if (ok) shown++;
            }
            var emp = document.getElementById('recvEmpty');
            if (emp) emp.style.display = (cards.length && !shown) ? '' : 'none';
        }
    </script>

    <?php /* الخانة مالهاش لزمة لو مفيش استلامات أصلاً — كانت بتطلع
             فوق رسالة «مفيش استلامات» وتبقى ضوضاء. */ ?>
    <?php if (!empty($allReceipts)): ?>
    <div class="fcard" style="padding:12px 14px;margin-bottom:14px;">
        <input class="finput" type="text" id="recvSearch" autocomplete="off" inputmode="search"
               aria-label="بحث في الاستلامات"
               placeholder="ابحث برقم الفاتورة أو اسم المجموعة…"
               oninput="recvFilter()" onkeydown="if(event.key==='Enter')event.preventDefault();">
        <div id="recvEmpty" class="fg-empty" style="display:none;">مفيش نتائج</div>
    </div>
    <?php endif; ?>

    <?php
    if (empty($allReceipts)):
    ?>
        <div class="fcard" style="text-align:center;padding:36px 20px;color:var(--ink-500);">
            <i class="ti ti-inbox" style="font-size:36px;display:block;margin-bottom:8px;"></i>
            لا توجد فواتير أو بضائع جاهزة للاستلام حالياً.
        </div>
    <?php
    else:
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
        <div class="fcard receiving-card clickable-card" style="cursor:pointer;margin-bottom:14px;"
             data-s="<?= e(mb_strtolower($hdr['title'])) ?>"
             data-url="<?= e($cardTargetUrl) ?>">
            <div class="rc-header">
                <div>
                    <h3 style="margin:0 0 4px 0;font-size:16px;">
                        <?= e($hdr['title']) ?>
                    </h3>
                    <?php if (!empty($hdr['group_note'])): ?>
                        <div style="font-size:12px;color:var(--ink-500);margin-bottom:4px;">من مجموعة: <?= e($hdr['group_note']) ?></div>
                    <?php endif; ?>
                    <span style="font-size:12px;color:var(--ink-400);">تاريخ التجهيز: <?= date('Y-m-d H:i', strtotime($hdr['created_at'])) ?></span>
                </div>
                <div style="display:flex;gap:6px;align-items:center;">
                    <a href="<?= e($diffUrl) ?>" class="btn btn-ghost btn-sm"><i class="ti ti-chart-bar"></i> الفروقات</a>
                    <?php if ($isOwner): ?>
                        <button type="button" class="btn btn-danger btn-sm btn-delete-invoice"
                                data-group="<?= (int)$hdr['group_id'] ?>"
                                data-dist="<?= (int)$hdr['distribution_id'] ?>"
                                data-title="<?= e($hdr['title']) ?>"
                                onclick="btnDeleteInvoice(this, event)">
                            <i class="ti ti-trash"></i> إلغاء من الاستلامات
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="rc-branches">
                <?php foreach ($hdr['branches'] as $br):
                    $chipClass = match($br['status']) {
                        'sent'      => 'fchip-mute',
                        'receiving' => 'fchip-warn',
                        'received'  => 'fchip-pos',
                        'approved'  => 'fchip-pos',
                        default     => 'fchip-mute'
                    };
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
                        <span class="fchip <?= $chipClass ?>"><?= e($statusText) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php
        endforeach;
    ?>
    <script>
    var csrfToken = <?= json_encode(csrf_token()) ?>;

    function btnDeleteInvoice(btn, e) {
        if (e) {
            if (e.stopPropagation) e.stopPropagation();
            if (e.preventDefault) e.preventDefault();
        }
        var groupId = parseInt(btn.getAttribute('data-group')) || 0;
        var distId  = parseInt(btn.getAttribute('data-dist')) || 0;
        var title   = btn.getAttribute('data-title') || 'هذه الفاتورة';
        deleteInvoiceReceipts(groupId, distId, title);
    }

    function deleteInvoiceReceipts(groupId, distId, title) {
        if (!confirm('هل أنت متأكد من إلغاء وحذف "' + title + '" من موديول الاستلامات بالكامل؟\nسيتم مسح سجلات استلام الفروع والإشعارات المرتبطة بها نهائياً.')) {
            return;
        }
        var fd = new FormData();
        fd.append('action', 'delete_invoice_receipts');
        fd.append('group_id', groupId);
        fd.append('distribution_id', distId);
        fd.append('csrf', csrfToken);

        fetch('receiving.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.ok) {
                    alert('تم إلغاء الفاتورة من موديول الاستلامات.');
                    location.reload();
                } else {
                    fgToast(d.error || 'حدث خطأ أثناء إلغاء الفاتورة.', 'err');
                }
            })
            .catch(function(err) { fgToast('تعذر الاتصال بالخادم', 'err'); });
    }

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
        <div class="fcard" style="text-align:center;color:var(--neg);padding:24px;margin-bottom:14px;">
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
        <div class="fcard" style="text-align:center;color:var(--neg);padding:36px 20px;margin-bottom:14px;">
            <i class="ti ti-ban" style="font-size:36px;display:block;margin-bottom:8px;"></i>
            <b style="font-size:16px;">غير مصرح لك بالوصول لاستلام فرع آخر.</b>
            <div style="margin-top:16px;"><a href="receiving.php" class="btn btn-ghost btn-sm">← الرجوع لاستلامات فرعك</a></div>
        </div>
<?php
        require_once __DIR__ . '/includes/footer.php';
        exit;
    endif;

    // داتا شاشة التفاصيل كلها من مكتبة الاستلام (بريف ١٥٢) — نفس الاستعلامات بالحرف
    $detail       = receiving_detail_data($pdo, $receipt, isset($_GET['diff']));
    $items        = $detail['items'];
    $status       = $detail['status'];
    $isGroup      = $detail['is_group'];
    $showDiffView = $detail['show_diff'];
    $diffUrl = 'receiving_diffs.php?' . ($isGroup ? ('g=' . $receipt['group_id']) : ('d=' . $receipt['distribution_id']));
?>

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
        <div>
            <div style="display:flex;align-items:center;gap:10px;">
                <h2 style="font-size:18px;font-weight:bold;margin:0;">
                    <i class="ti ti-package"></i> استلام فرع: <?= e($receipt['branch_name']) ?>
                </h2>
                <span class="fchip <?= match($status) {
                    'sent'      => 'fchip-mute',
                    'receiving' => 'fchip-warn',
                    'received'  => 'fchip-pos',
                    'approved'  => 'fchip-pos',
                    default     => 'fchip-mute'
                } ?>">
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
            <?php if ($isOwner): ?>
                <button type="button" class="btn btn-danger btn-sm" onclick="deleteSingleReceipt(<?= $receiptId ?>)">
                    <i class="ti ti-trash"></i> إلغاء هذا الاستلام
                </button>
            <?php endif; ?>
            <a href="receiving.php" class="btn btn-ghost btn-sm">← القائمة</a>
        </div>
    </div>

    <!-- التنبيهات -->
    <div id="scanAlertMsg" class="falert" style="display:none;"></div>

    <?php
    if (!empty($receipt['reject_reason']) && $status === 'receiving'):
        // اسم اللي رفض المحضر جاي من receiving_detail_data() (بريف ١٥٢)
        $rejecterName = $detail['rejecter_name'];
    ?>
        <div class="falert falert-neg" style="margin-bottom: 16px;">
            <div style="font-weight: bold; font-size: 15px; margin-bottom: 6px; display:flex; align-items:center; gap:8px;">
                <i class="ti ti-alert-triangle" style="font-size:20px;"></i>
                تم رفض الاستلام السابق بواسطة <?= e($rejecterName) ?> بتاريخ <?= date('Y-m-d H:i', strtotime($receipt['rejected_at'])) ?>
            </div>
            <div style="font-size: 13.5px; color: var(--text); margin-top: 6px;">
                <b>سبب الرفض والتوجيهات:</b> <?= nl2br(e($receipt['reject_reason'])) ?>
            </div>
            <div style="font-size: 12px; color: var(--ink-500); margin-top: 6px;">
                يرجى إعادة مسح ومراجعة الكميات وتصحيح الأخطاء ثم الضغط على «تم الاستلام ومراجعة الفروقات».
            </div>
        </div>
    <?php endif; ?>

    <?php if ($status === 'sent'): ?>
        <!-- ================================================================= -->
        <!--  المرحلة 1 (الحالة sent): جدول كود واسم الصنف وبس. بدون كميات ولا أسعار.  -->
        <!-- ================================================================= -->
        <div class="fcard receiving-card" style="margin-bottom:14px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <div>
                    <b>قائمة الأصناف المتوقعة للفرع</b>
                    <span style="font-size:12px;color:var(--ink-400);margin-right:6px;">(<?= count($items) ?> صنف)</span>
                </div>
                <button type="button" class="btn btn-success" onclick="startReceiving()">
                    <i class="ti ti-player-play"></i> بدء الاستلام
                </button>
            </div>

            <div class="ftable-wrap"><table class="ftable">
                <thead>
                    <tr>
                        <th class="num" style="width:50px;">#</th>
                        <th style="width:180px;">كود الصنف</th>
                        <th>اسم الصنف</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; foreach ($items as $it): ?>
                        <tr>
                            <td class="num"><?= $i++ ?></td>
                            <td><code><?= e($it['item_code']) ?></code></td>
                            <td class="name"><?= e($it['item_name']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>

    <?php elseif ($status === 'receiving' && !$showDiffView): ?>
        <!-- ================================================================= -->
        <!--  المرحلة 2 (الحالة receiving): شاشة المسح بالكاميرا أو الإدخال اليدوي -->
        <!-- ================================================================= -->
        <div class="fcard" style="margin-bottom:16px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <h3 style="margin:0;font-size:15px;"><i class="ti ti-scan"></i> مسح باركود الأصناف</h3>
                <a href="receiving.php?r=<?= $receiptId ?>&diff=1" class="btn btn-success">
                    <i class="ti ti-check"></i> تم الاستلام ومراجعة الفروقات
                </a>
            </div>

            <form id="barcodeScanForm" onsubmit="return handleManualScan(event);" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                <div class="ffield" style="flex:1.5;min-width:180px;">
                    <label for="codeInput">كود الصنف / الباركود</label>
                    <div style="display:flex;gap:6px;">
                        <input class="finput" type="text" id="codeInput" name="code" required autofocus autocomplete="off"
                               placeholder="امسح أو اكتب الكود..."
                               oninput="liveLookup(this.value)">
                        <button type="button" class="btn btn-primary" onclick="openBarcodeScanner('codeInput')" title="مسح بالكاميرا"
                                style="padding:0 14px;">
                            <i class="ti ti-camera" style="font-size:18px;"></i>
                        </button>
                    </div>
                </div>

                <div class="ffield" style="flex:2.5;min-width:220px;">
                    <label for="codeName">اسم الصنف</label>
                    <input class="finput" type="text" id="codeName" readonly placeholder="هيظهر بعد الكود...">
                </div>

                <div class="ffield" style="width:100px;">
                    <label for="qtyInput">الكمية</label>
                    <input class="finput num" type="number" id="qtyInput" name="qty" step="1" min="1" value="1">
                </div>

                <div>
                    <button type="submit" id="addBtn" class="btn"><i class="ti ti-plus"></i> إضافة</button>
                </div>
            </form>
        </div>

        <!-- آخر الأصناف الممسوحة -->
        <div class="fcard receiving-card" style="margin-bottom:14px;">
            <h4 style="margin:0 0 10px 0;font-size:14px;">آخر الأصناف الممسوحة</h4>
            <div id="lastScannedListContainer">
                <?php $scannedHistory = $detail['last_scanned']; ?>
                <div class="ftable-wrap"><table class="ftable" id="lastScannedTable">
                    <thead>
                        <tr>
                            <th>كود الصنف</th>
                            <th>اسم الصنف</th>
                            <th class="num" style="width:130px;">الكمية المستلمة</th>
                        </tr>
                    </thead>
                    <tbody id="lastScannedTbody">
                        <?php if (empty($scannedHistory)): ?>
                            <tr id="emptyScanRow"><td colspan="3" class="fg-empty">لم يتم مسح أي صنف حتى الآن.</td></tr>
                        <?php else: foreach ($scannedHistory as $sh): ?>
                            <tr>
                                <td><code><?= e($sh['item_code']) ?></code></td>
                                <td class="name"><?= e($sh['item_name']) ?></td>
                                <td class="num">
                                    <div style="display:inline-flex;align-items:center;gap:6px;">
                                        <input type="number" step="1" min="0"
                                               class="finput finput-sm num rc-qty-edit-input"
                                               data-code="<?= e($sh['item_code']) ?>"
                                               value="<?= (float)$sh['scanned_qty'] ?>"
                                               title="اضغط للتعديل، ويتم الحفظ تلقائياً"
                                               onfocus="this.select()"
                                               onchange="saveQtyEdit(this)"
                                               onkeydown="if(event.key==='Enter'){event.preventDefault();this.blur();}"
                                               style="width:75px;font-weight:700;">
                                        <span class="save-indicator" style="display:none;color:var(--pos);font-size:13px;" title="تم الحفظ"><i class="ti ti-check"></i></span>
                                    </div>
                                </td>
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
        <div class="fcard receiving-card" style="margin-bottom:14px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
                <div>
                    <h3 style="margin:0;font-size:16px;">جدول مطابقة وفروقات الفرع</h3>
                    <span style="font-size:12px;color:var(--ink-400);">يمكنك تعديل الكمية المستلمة في الجدول مباشرة لتصحيح أي خطأ أو زيادة قبل الاعتماد.</span>
                </div>

                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <?php if ($status === 'receiving'): ?>
                        <a href="receiving.php?r=<?= $receiptId ?>" class="btn btn-ghost btn-sm">← رجوع للمسح</a>
                        <button type="button" class="btn btn-success btn-sm" onclick="finishReview()">
                            <i class="ti ti-check"></i> تمت المراجعة
                        </button>
                    <?php elseif ($status === 'received'): ?>
                        <?php if ($canManageAll): ?>
                            <button type="button" class="btn btn-danger btn-sm" onclick="rejectReceiptModal()">
                                <i class="ti ti-x"></i> رفض الاستلام وإعادة الفتح
                            </button>
                        <?php endif; ?>
                        <?php if ($isOwner): ?>
                            <button type="button" class="btn btn-success btn-sm" onclick="approveReceipt()">
                                <i class="ti ti-circle-check"></i> اعتماد الاستلام النهائي
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="ftable-wrap"><table class="ftable" id="diffsTable">
                <thead>
                    <tr>
                        <th style="width:160px;">كود الصنف</th>
                        <th>اسم الصنف</th>
                        <th class="num" style="width:110px;">الموزّع</th>
                        <th class="num" style="width:130px;">المستلم</th>
                        <th class="num" style="width:130px;">الفرق</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $canEditQty = ($status !== 'approved');
                    $totalDist = 0.0;
                    $totalScan = 0.0;
                    foreach ($items as $it):
                        $distQ = (float)$it['distributed_qty'];
                        $scanQ = (float)$it['scanned_qty'];
                        $diff  = $distQ - $scanQ;
                        $totalDist += $distQ;
                        $totalScan += $scanQ;
                    ?>
                        <tr data-item-code="<?= e($it['item_code']) ?>" data-dist="<?= $distQ ?>">
                            <td><code><?= e($it['item_code']) ?></code></td>
                            <td class="name"><?= e($it['item_name']) ?></td>
                            <td class="num"><span class="dist-val"><?= $distQ ?></span></td>
                            <td class="num">
                                <?php if ($canEditQty): ?>
                                    <div style="display:inline-flex;align-items:center;gap:6px;">
                                        <input type="number" step="1" min="0"
                                               class="finput finput-sm num rc-qty-edit-input"
                                               data-code="<?= e($it['item_code']) ?>"
                                               value="<?= $scanQ ?>"
                                               title="اضغط للتعديل، ويتم الحفظ تلقائياً"
                                               onfocus="this.select()"
                                               onchange="saveQtyEdit(this)"
                                               onkeydown="if(event.key==='Enter'){event.preventDefault();this.blur();}"
                                               style="width:75px;font-weight:700;">
                                        <span class="save-indicator" style="display:none;color:var(--pos);font-size:13px;" title="تم الحفظ"><i class="ti ti-check"></i></span>
                                    </div>
                                <?php else: ?>
                                    <b><?= $scanQ ?></b>
                                <?php endif; ?>
                            </td>
                            <td class="num diff-val-cell">
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
                <tfoot>
                    <tr style="background:var(--bg);font-weight:bold;border-top:2px solid var(--line);">
                        <td colspan="2">الإجمالي:</td>
                        <td class="num" id="totalDistCell"><?= $totalDist ?></td>
                        <td class="num" id="totalScanCell"><?= $totalScan ?></td>
                        <td class="num" id="totalDiffCell">
                            <?php $totDiff = $totalDist - $totalScan; ?>
                            <?php if ($totDiff == 0): ?>
                                <span style="color:var(--pos);">0 <i class="ti ti-check"></i></span>
                            <?php elseif ($totDiff > 0): ?>
                                <span style="color:var(--neg);">-<?= $totDiff ?> (عجز)</span>
                            <?php else: ?>
                                <span style="color:var(--accent-text);">+<?= abs($totDiff) ?> (زيادة)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tfoot>
            </table></div>
        </div>
    <?php endif; ?>

    <!-- نافذة منبثقة لسبب الرفض وإعادة الفتح -->
    <div id="rejectModal" style="display:none;position:fixed;inset:0;background:rgba(30,27,75,0.6);z-index:var(--z-modal);align-items:center;justify-content:center;padding:16px;">
        <div style="background:var(--surface);border-radius:var(--r);max-width:480px;width:100%;padding:20px;border:1px solid var(--line);box-shadow:0 8px 30px rgba(0,0,0,0.2);">
            <h3 style="margin:0 0 10px 0;font-size:16px;color:var(--neg);display:flex;align-items:center;gap:6px;">
                <i class="ti ti-alert-triangle"></i> رفض الاستلام وإعادة الفتح للفرع
            </h3>
            <p style="font-size:13px;color:var(--ink-600);margin-bottom:12px;line-height:1.5;">
                سيتم إعادة فتح الاستلام لموظف الفرع ليتمكن من تصحيح الأخطاء، وسيصل إشعار للفرع بسبب الرفض والتوجيهات.
            </p>
            <label style="display:block;font-size:12.5px;font-weight:600;margin-bottom:6px;">سبب الرفض والتوجيهات:</label>
            <textarea class="ftextarea" id="rejectReasonInput" rows="3" placeholder="اكتب سبب الرفض هنا بالتفصيل (مثل: عجز ٥ قطع في صنف كذا، أو مسح صنف خطأ)..."></textarea>
            <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">
                <button type="button" class="btn btn-ghost" onclick="closeRejectModal()">إلغاء</button>
                <button type="button" class="btn btn-danger" onclick="submitRejectReceipt()">تأكيد الرفض والإرسال</button>
            </div>
        </div>
    </div>
<?php endif; ?>
</div>

<!-- المكون المشترك للسكانر (كاميرا الموبايل) -->
<script src="assets/barcode_scanner.js?v=9"></script>

<script>
var csrfToken = <?= json_encode(csrf_token()) ?>;
var receiptId = <?= (int)$receiptId ?>;

function showAlert(msg, isError) {
    var box = document.getElementById('scanAlertMsg');
    if (!box) return;
    if (!msg) {
        box.style.display = 'none';
        return;
    }
    box.textContent = msg;
    box.className = isError ? 'falert falert-neg' : 'falert falert-pos';
    box.style.display = 'block';
    if (!isError) {
        setTimeout(function(){ box.style.display = 'none'; }, 4000);
    }
}

// استعلام فوري عن اسم الصنف وبياناته (Lookup)
var lookupTimer = null;
function liveLookup(code, callback) {
    clearTimeout(lookupTimer);
    code = (code || '').trim();
    var nameBox = document.getElementById('codeName');
    if (!code) {
        if (nameBox) nameBox.value = '';
        if (callback) callback(false);
        return;
    }

    lookupTimer = setTimeout(function() {
        if (nameBox) nameBox.value = 'جاري البحث عن الصنف...';
        var fd = new FormData();
        fd.append('action', 'lookup');
        fd.append('receipt_id', receiptId);
        fd.append('code', code);
        fd.append('csrf', csrfToken);

        fetch('receiving.php?r=' + receiptId, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.ok) {
                    if (nameBox) nameBox.value = d.item_name;
                    showAlert('', false);
                    if (callback) callback(true, d);
                } else {
                    if (nameBox) nameBox.value = '';
                    showAlert(d.error || 'الصنف ده مش في الفاتورة', true);
                    if (callback) callback(false, d);
                }
            })
            .catch(function(err) {
                if (nameBox) nameBox.value = '';
                showAlert('تعذر الاتصال بالخادم للبحث', true);
                if (callback) callback(false);
            });
    }, 120);
}

// ربط أحداث الإدخال بلوحة المفاتيح
document.addEventListener('DOMContentLoaded', function() {
    var codeInput = document.getElementById('codeInput');
    var qtyInput  = document.getElementById('qtyInput');

    if (codeInput) {
        // عند الضغط على Enter في خانة الكود: استعلام ونقل الفوكس للكمية بدون حفظ
        codeInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var codeVal = (this.value || '').trim();
                if (!codeVal) return;
                liveLookup(codeVal, function(ok) {
                    if (ok && qtyInput) {
                        qtyInput.focus();
                        qtyInput.select();
                    }
                });
            }
        });
    }

    if (qtyInput) {
        // عند الضغط على Enter في خانة الكمية: تنفيذ الإضافة والحفظ
        qtyInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                handleManualScan(e);
            }
        });
    }
});

function handleManualScan(e) {
    if (e && e.preventDefault) e.preventDefault();
    var codeBox = document.getElementById('codeInput');
    var qtyBox  = document.getElementById('qtyInput');
    var nameBox = document.getElementById('codeName');
    var addBtn  = document.getElementById('addBtn');

    var code = (codeBox ? codeBox.value : '').trim();
    var qty  = parseFloat(qtyBox ? qtyBox.value : 1) || 1;

    if (!code) {
        showAlert('يرجى كتابة أو مسح كود الصنف أولاً.', true);
        if (codeBox) codeBox.focus();
        return false;
    }

    if (addBtn) addBtn.disabled = true;

    var fd = new FormData();
    fd.append('action', 'scan');
    fd.append('receipt_id', receiptId);
    fd.append('code', code);
    fd.append('qty', qty);
    fd.append('csrf', csrfToken);

    fetch('receiving.php?r=' + receiptId, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (addBtn) addBtn.disabled = false;
            if (d.ok) {
                showAlert('تم استلام: ' + d.item_name + ' (إجمالي المستلم: ' + d.scanned_qty + ')', false);
                if (codeBox) { codeBox.value = ''; codeBox.focus(); }
                if (nameBox) nameBox.value = '';
                if (qtyBox) qtyBox.value = '1';
                updateLastScannedTable(d.item_code, d.item_name, d.scanned_qty);
            } else {
                showAlert(d.error || 'الصنف ده مش في الفاتورة', true);
                if (codeBox) codeBox.select();
            }
        })
        .catch(function(err) {
            if (addBtn) addBtn.disabled = false;
            showAlert('تعذر الاتصال بالخادم', true);
        });

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
        var inp = existingRow.querySelector('input.rc-qty-edit-input');
        if (inp) inp.value = scannedQty;
        tbody.prepend(existingRow);
    } else {
        var tr = document.createElement('tr');
        tr.innerHTML = '<td><code>' + window.ui.esc(code) + '</code></td>' +
                       '<td>' + window.ui.esc(name) + '</td>' +
                       '<td><div style="display:inline-flex;align-items:center;gap:6px;">' +
                       '<input type="number" step="1" min="0" class="rc-qty-edit-input" data-code="' + window.ui.esc(code) + '" value="' + scannedQty + '" ' +
                       'title="اضغط للتعديل، ويتم الحفظ تلقائياً" onfocus="this.select()" onchange="saveQtyEdit(this)" onkeydown="if(event.key===\'Enter\'){event.preventDefault();this.blur();}" ' +
                       'style="width:75px;padding:3px 6px;border:1.5px solid var(--line);border-radius:var(--r-sm);text-align:center;font-weight:700;font-size:13px;font-family:inherit;background:var(--surface);color:var(--text);">' +
                       '<span class="save-indicator" style="display:none;color:var(--pos);font-size:13px;" title="تم الحفظ"><i class="ti ti-check"></i></span>' +
                       '</div></td>';
        tbody.prepend(tr);
    }
}

// تعديل مباشر للكمية المستلمة (تصحيح خطأ أو إنقاص زيادة)
function saveQtyEdit(input) {
    var tr = input.closest('tr');
    var code = input.dataset.code;
    var distQ = parseFloat(tr ? tr.dataset.dist : 0) || 0;
    var newQty = parseFloat(input.value);

    if (isNaN(newQty) || newQty < 0) {
        newQty = 0;
        input.value = 0;
    }

    input.style.borderColor = 'var(--accent)';
    var indicator = tr ? tr.querySelector('.save-indicator') : null;
    if (indicator) indicator.style.display = 'none';

    var fd = new FormData();
    fd.append('action', 'update_qty');
    fd.append('receipt_id', receiptId);
    fd.append('code', code);
    fd.append('qty', newQty);
    fd.append('csrf', csrfToken);

    fetch('receiving.php?r=' + receiptId, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.ok) {
                input.style.borderColor = 'var(--pos)';
                setTimeout(function(){ input.style.borderColor = 'var(--line)'; }, 1500);
                if (indicator) {
                    indicator.style.display = 'inline';
                    setTimeout(function(){ indicator.style.display = 'none'; }, 2500);
                }

                // تحديث عمود الفرق فوراً إن وجد في نفس الجدول
                var diffCell = tr ? tr.querySelector('.diff-val-cell') : null;
                if (diffCell) {
                    var diff = distQ - newQty;
                    if (diff === 0) {
                        diffCell.innerHTML = '<span style="color:var(--pos);font-weight:bold;">0 <i class="ti ti-check"></i></span>';
                    } else if (diff > 0) {
                        diffCell.innerHTML = '<span style="color:var(--neg);font-weight:bold;">-' + diff + ' (عجز)</span>';
                    } else {
                        diffCell.innerHTML = '<span style="color:var(--accent-text);font-weight:bold;">+' + Math.abs(diff) + ' (زيادة)</span>';
                    }
                }

                // تحديث الإجماليات في الفوتر إن وجد
                recalcDiffTotals();
            } else {
                input.style.borderColor = 'var(--neg)';
                fgToast(d.error || 'تعذر حفظ الكمية.', 'err');
            }
        })
        .catch(function() {
            input.style.borderColor = 'var(--neg)';
            fgToast('تعذر الاتصال بالخادم لحفظ التعديل.', 'err');
        });
}

function recalcDiffTotals() {
    var table = document.getElementById('diffsTable');
    if (!table) return;
    var totDist = 0, totScan = 0;
    table.querySelectorAll('tbody tr').forEach(function(row) {
        var d = parseFloat(row.dataset.dist) || 0;
        var inp = row.querySelector('.rc-qty-edit-input');
        var s = inp ? (parseFloat(inp.value) || 0) : 0;
        totDist += d;
        totScan += s;
    });
    var dCell = document.getElementById('totalDistCell');
    var sCell = document.getElementById('totalScanCell');
    var diffCell = document.getElementById('totalDiffCell');
    if (dCell) dCell.textContent = totDist;
    if (sCell) sCell.textContent = totScan;
    if (diffCell) {
        var totDiff = totDist - totScan;
        if (totDiff === 0) {
            diffCell.innerHTML = '<span style="color:var(--pos);">0 <i class="ti ti-check"></i></span>';
        } else if (totDiff > 0) {
            diffCell.innerHTML = '<span style="color:var(--neg); font-weight:bold;">-' + totDiff + ' (عجز)</span>';
        } else {
            diffCell.innerHTML = '<span style="color:var(--accent-text); font-weight:bold;">+' + Math.abs(totDiff) + ' (زيادة)</span>';
        }
    }
}

function startReceiving() {
    if (!confirm('هل تريد بدء استلام هذه الفاتورة الآن؟')) return;
    var fd = new FormData();
    fd.append('action', 'start');
    fd.append('receipt_id', receiptId);
    fd.append('csrf', csrfToken);

    fetch('receiving.php?r=' + receiptId, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.ok) {
                location.reload();
            } else {
                fgToast(d.error || 'حدث خطأ', 'err');
            }
        })
        .catch(function(err) { fgToast('تعذر الاتصال بالخادم', 'err'); });
}

function finishReview() {
    if (!confirm('هل تأكدت من مراجعة كل البضاعة المستلمة وإنهاء المراجعة؟')) return;
    var fd = new FormData();
    fd.append('action', 'review');
    fd.append('receipt_id', receiptId);
    fd.append('csrf', csrfToken);

    fetch('receiving.php?r=' + receiptId, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.ok) {
                location.href = 'receiving.php?r=' + receiptId;
            } else {
                fgToast(d.error || 'حدث خطأ', 'err');
            }
        })
        .catch(function(err) { fgToast('تعذر الاتصال بالخادم', 'err'); });
}

function approveReceipt() {
    if (!confirm('هل تريد اعتماد استلام هذا الفرع نهائياً؟')) return;
    var fd = new FormData();
    fd.append('action', 'approve');
    fd.append('receipt_id', receiptId);
    fd.append('csrf', csrfToken);

    fetch('receiving.php?r=' + receiptId, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.ok) {
                location.reload();
            } else {
                fgToast(d.error || 'حدث خطأ', 'err');
            }
        })
        .catch(function(err) { fgToast('تعذر الاتصال بالخادم', 'err'); });
}

function rejectReceiptModal() {
    var modal = document.getElementById('rejectModal');
    var txt = document.getElementById('rejectReasonInput');
    if (txt) txt.value = '';
    if (modal) modal.style.display = 'flex';
    if (txt) txt.focus();
}

function closeRejectModal() {
    var modal = document.getElementById('rejectModal');
    if (modal) modal.style.display = 'none';
}

function submitRejectReceipt() {
    var txt = document.getElementById('rejectReasonInput');
    var reason = (txt ? txt.value : '').trim();
    if (!reason) {
        fgToast('يرجى كتابة سبب رفض الاستلام.', 'warn');
        if (txt) txt.focus();
        return;
    }

    var fd = new FormData();
    fd.append('action', 'reject_review');
    fd.append('receipt_id', receiptId);
    fd.append('reason', reason);
    fd.append('csrf', csrfToken);

    fetch('receiving.php?r=' + receiptId, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.ok) {
                location.reload();
            } else {
                fgToast(d.error || 'حدث خطأ أثناء رفض الاستلام.', 'err');
            }
        })
        .catch(function(err) { fgToast('تعذر الاتصال بالخادم', 'err'); });
}

function deleteSingleReceipt(id) {
    if (!confirm('هل أنت متأكد من إلغاء وحذف هذا الاستلام بالكامل؟\nسيتم مسح الكميات الممسوحة وسجل الاستلام والإشعارات المرتبطة به نهائياً.')) {
        return;
    }
    var fd = new FormData();
    fd.append('action', 'delete_receipt');
    fd.append('receipt_id', id);
    fd.append('csrf', csrfToken);

    fetch('receiving.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.ok) {
                alert('تم إلغاء الاستلام بنجاح.');
                window.location.href = 'receiving.php';
            } else {
                fgToast(d.error || 'حدث خطأ أثناء إلغاء الاستلام.', 'err');
            }
        })
        .catch(function(err) { fgToast('تعذر الاتصال بالخادم', 'err'); });
}

</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
