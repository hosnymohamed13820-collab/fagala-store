<?php
/**
 * transfers.php — شاشة إدارة التحويلات بين المخازن (بريف ٢١٥)
 * نظام الفجالة ستور المالي
 *
 * التابات:
 *   - «صادر»: تحويلات صادرة من فرعي (أو كل الفروع للمالك)
 *   - «وارد»: تحويلات واردة لفرعي (المستلم) مع زرار «ابدأ العد» للـ sent
 *   - «فروق مفتوحة»: حالات الزيادة المعلقة لحين تحديد المخزن المصدر
 *   - «الأرشيف»: التحويلات المكتملة (closed) والملغاة (cancelled)
 *
 * الطباعة: ?action=print&id=...
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/stock_transfer_lib.php';

require_login();

// التحقق من صلاحية موديول التحويلات
if (!can('transfers') && !is_owner()) {
    $active_module = 'transfers';
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="card"><div class="alert alert-danger" style="margin:20px;text-align:center;">غير مصرح لك بالوصول لشاشة التحويلات.</div></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$pdo = db();
$user = current_user();
$uid = (int) ($user['id'] ?? 1);
$isOwner = is_real_owner() || is_owner();
$userBranchId = !empty($user['branch_id']) ? (int) $user['branch_id'] : null;

// 🔴 بريف ٢١٥ر: غير المالك من غير فرع — مفيش داتا خالص.
// من غير الحارس ده الفلتر بيبقى null والاستعلام بيرجّع تحويلات كل الفروع.
if (!$isOwner && !$userBranchId) {
    $active_module = 'transfers';
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="card"><div class="alert alert-warning" style="margin:20px;text-align:center;">'
        . 'حسابك مش مربوط بفرع — كلّم المدير يظبط فرعك الأول عشان تشوف تحويلات فرعك.'
        . '</div></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// فحص تفعيل المخزن
$stockEnabled = stock_enabled();

// -------------------------------------------------------------
// معالجة صفحة طباعة إيصال التحويل
// -------------------------------------------------------------
if (($_GET['action'] ?? '') === 'print') {
    $docId = (int) ($_GET['id'] ?? 0);
    $data = transfer_get($pdo, $docId);
    if (!$data) {
        die('التحويل غير موجود');
    }

    $doc = $data['doc'];
    if (!$isOwner && $userBranchId !== (int)$doc['branch_id'] && $userBranchId !== (int)$doc['counter_branch_id']) {
        http_response_code(403);
        die('غير مصرح لك بطباعة هذا التحويل.');
    }

    $docNoLabel = $doc['doc_no'] ? ('#' . $doc['doc_no'] . ' / ' . $doc['doc_year']) : ('#مسودة-' . $doc['id']);
    ?>
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
        <meta charset="utf-8">
        <title>إذن تحويل بضاعة <?= e($docNoLabel) ?></title>
        <style>
            @page { size: A4 portrait; margin: 15mm; }
            body {
                font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
                direction: rtl;
                color: #000;
                background: #fff;
                margin: 0;
                padding: 15px;
                font-size: 13px;
                line-height: 1.5;
            }
            .print-header {
                text-align: center;
                border-bottom: 2px solid #000;
                padding-bottom: 10px;
                margin-bottom: 15px;
            }
            .print-header h1 { margin: 0 0 5px 0; font-size: 20px; }
            .print-header h2 { margin: 0; font-size: 15px; font-weight: normal; color: #444; }
            .meta-grid {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 8px 20px;
                margin-bottom: 15px;
                font-size: 13px;
            }
            .meta-item { display: flex; justify-content: space-between; border-bottom: 1px dotted #ccc; padding: 3px 0; }
            .meta-label { font-weight: bold; }
            table.items-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 20px;
            }
            table.items-table th, table.items-table td {
                border: 1px solid #333;
                padding: 6px 8px;
                font-size: 12px;
            }
            table.items-table th { background: #f0f0f0; text-align: right; }
            table.items-table td.num, table.items-table th.num { text-align: left; font-variant-numeric: tabular-nums; }
            .signatures {
                margin-top: 30px;
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                text-align: center;
                gap: 15px;
            }
            .sig-box { border-top: 1px solid #000; padding-top: 5px; min-height: 50px; font-weight: bold; }
            .no-print {
                margin-bottom: 15px;
                padding: 10px;
                background: #eef2ff;
                border: 1px solid #c7d2fe;
                border-radius: 8px;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .btn-print {
                background: #2563eb; color: #fff; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: bold;
            }
            @media print {
                .no-print { display: none !important; }
                body { padding: 0; }
            }
        </style>
    </head>
    <body>
        <div class="no-print">
            <span>معاينة طباعة إذن تحويل المخزن</span>
            <button class="btn-print" onclick="window.print()">طباعة الإذن</button>
        </div>

        <div class="print-header">
            <h1>سلسلة مكتبات الفجالة ستور</h1>
            <h2>إذن تحويل بضاعة بين المخازن</h2>
        </div>

        <div class="meta-grid">
            <div class="meta-item"><span class="meta-label">رقم الإذن:</span><span><?= e($docNoLabel) ?></span></div>
            <div class="meta-item"><span class="meta-label">تاريخ التحويل:</span><span><?= e($doc['doc_date']) ?></span></div>
            <div class="meta-item"><span class="meta-label">المخزن الراسل:</span><span><?= e($doc['from_branch_name'] ?? ('مخزن ' . $doc['branch_id'])) ?></span></div>
            <div class="meta-item"><span class="meta-label">المخزن المستلم:</span><span><?= e($doc['to_branch_name'] ?? ('مخزن ' . $doc['counter_branch_id'])) ?></span></div>
            <div class="meta-item"><span class="meta-label">تاريخ الإرسال:</span><span><?= e($doc['sent_at'] ?: '—') ?></span></div>
            <div class="meta-item"><span class="meta-label">مسؤول الإنشاء:</span><span><?= e($doc['created_by_name'] ?? '—') ?></span></div>
        </div>

        <?php if (!empty($doc['notes'])): ?>
            <div style="margin-bottom:12px;font-size:12px;color:#333;">
                <strong>ملاحظات:</strong> <?= e($doc['notes']) ?>
            </div>
        <?php endif; ?>

        <table class="items-table">
            <thead>
                <tr>
                    <th style="width:30px;">#</th>
                    <th style="width:130px;">الكود</th>
                    <th>اسم الصنف</th>
                    <th class="num" style="width:70px;">الكمية</th>
                    <th class="num" style="width:60px;">المعامل</th>
                    <th class="num" style="width:80px;">إجمالي القطع</th>
                    <th style="width:100px;">ملاحظات</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $totPieces = 0.0;
                foreach ($data['lines'] as $idx => $l):
                    $totPieces += (float) $l['qty_pieces'];
                ?>
                    <tr>
                        <td><?= $idx + 1 ?></td>
                        <td><?= e($l['code']) ?></td>
                        <td><?= e($l['item_name'] ?? ('صنف ' . $l['item_id'])) ?></td>
                        <td class="num"><?= number_format((float)$l['qty_units'], 2) ?></td>
                        <td class="num"><?= number_format((float)$l['unit_factor'], 2) ?></td>
                        <td class="num"><?= number_format((float)$l['qty_pieces'], 3) ?></td>
                        <td><?= e($l['notes'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="font-weight:bold;background:#f9f9f9;">
                    <td colspan="5" style="text-align:right;">الإجمالي</td>
                    <td class="num"><?= number_format($totPieces, 3) ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>

        <div class="signatures">
            <div class="sig-box">توقيع مسؤول التسليم (الراسل)</div>
            <div class="sig-box">توقيع السائق / مسؤول النقل</div>
            <div class="sig-box">توقيع مسؤول الاستلام (المستلم)</div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// -------------------------------------------------------------
// الشاشة الرئيسية للتحويلات
// -------------------------------------------------------------
$tab = (string) ($_GET['tab'] ?? 'out');
if (!in_array($tab, ['out', 'in', 'diffs', 'archive'], true)) {
    $tab = 'out';
}

// جلب المخازن المدعومة للتحويل (١ بونجور · ٢ اي بي سي · ٣ الفجالة · ٤ دجلة · ٦ الإدارة)
$stBranches = $pdo->query('SELECT id, name FROM branches WHERE id IN (1, 2, 3, 4, 6) ORDER BY id ASC');
$supportedBranches = $stBranches->fetchAll(PDO::FETCH_ASSOC);
$branchNamesMap = array_column($supportedBranches, 'name', 'id');

// جلب عدد الفروقات المفتوحة لعرض شارة في التاب
$openDiffsCount = (int) $pdo->query("SELECT COUNT(*) FROM stock_transfer_diffs WHERE status = 'open'")->fetchColumn();

// جلب قائمة التحويلات حسب التاب
$filters = [
    'tab'       => $tab,
    'branch_id' => $isOwner ? (!empty($_GET['branch_id']) ? (int)$_GET['branch_id'] : null) : $userBranchId,
    'from'      => !empty($_GET['from']) ? (string)$_GET['from'] : null,
    'to'        => !empty($_GET['to']) ? (string)$_GET['to'] : null,
];
$docs = transfer_list($pdo, $filters);
$openDiffsList = ($tab === 'diffs') ? transfer_open_diffs($pdo, $isOwner ? null : $userBranchId) : [];

$pageTitle = 'التحويلات بين المخازن';
$active_module = 'transfers';
require_once __DIR__ . '/includes/header.php';
?>

<style>
/* ضبط استجابة الشاشة للموبايل لمنع أي سكرول عرضي (عرض 390px) */
.transfers-container {
    width: 100%;
    max-width: 100%;
    padding: 10px 0;
    box-sizing: border-box;
}
.transfers-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 16px;
}
.transfers-tabs {
    display: flex;
    gap: 8px;
    border-bottom: 2px solid var(--line);
    margin-bottom: 16px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    padding-bottom: 4px;
}
.tab-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border-radius: 8px 8px 0 0;
    color: var(--ink-500);
    text-decoration: none;
    font-weight: 600;
    font-size: 13.5px;
    white-space: nowrap;
    border-bottom: 3px solid transparent;
    transition: all 0.2s ease;
}
.tab-link:hover {
    color: var(--text);
    background: var(--line-soft);
}
.tab-link.active {
    color: var(--accent);
    border-bottom-color: var(--accent);
    background: var(--surface);
}
.badge-count {
    display: inline-block;
    padding: 2px 6px;
    font-size: 11px;
    font-weight: bold;
    border-radius: 10px;
    background: var(--neg);
    color: #fff;
}
.ftable-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    width: 100%;
    border-radius: 10px;
    border: 1px solid var(--line);
    background: var(--surface);
}
.scanner-box {
    background: var(--bg);
    border: 1.5px dashed var(--line);
    border-radius: 10px;
    padding: 14px;
    margin-bottom: 16px;
}
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.55);
    z-index: var(--z-modal, 1000);
    align-items: center;
    justify-content: center;
    padding: 10px;
}
.modal-card {
    background: var(--surface);
    border-radius: 14px;
    max-width: 820px;
    width: 100%;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 12px 35px rgba(0,0,0,0.3);
    overflow: hidden;
}
.modal-header {
    padding: 14px 18px;
    border-bottom: 1px solid var(--line);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--bg);
}
.modal-body {
    padding: 18px;
    overflow-y: auto;
    flex: 1;
}
.modal-footer {
    padding: 12px 18px;
    border-top: 1px solid var(--line);
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    background: var(--bg);
}
@media (max-width: 600px) {
    .meta-grid { grid-template-columns: 1fr; }
    .transfers-header { flex-direction: column; align-items: stretch; }
    .btn { width: 100%; justify-content: center; margin-bottom: 6px; }
}
</style>

<div class="transfers-container">

    <!-- رسالة قفل المخزن إن وجد -->
    <?php if (!$stockEnabled): ?>
        <div class="alert alert-warning" style="margin-bottom:16px;">
            <i class="ti ti-alert-triangle"></i>
            المخزن على الموقع مقفول حالياً بإعداد النظام (erp_stock_enabled = 0). يمكنك الاستعراض والاطلاع، لكن عمليات الإنشاء والإرسال والاستلام معطلة.
        </div>
    <?php endif; ?>

    <div class="transfers-header">
        <div>
            <h2 style="margin:0 0 4px 0;font-size:20px;display:flex;align-items:center;gap:8px;">
                <i class="ti ti-transfer"></i> التحويلات بين المخازن
            </h2>
            <div style="font-size:12.5px;color:var(--ink-500);">
                مستند واحد بمراحل لنقل البضاعة وضبط الفروقات تلقائياً بدون تسجيل مرتين
            </div>
        </div>

        <div>
            <?php if ($isOwner || can('transfers:create')): ?>
                <button type="button" class="btn btn-primary" onclick="openNewTransferModal()">
                    <i class="ti ti-plus"></i> طلب تحويل جديد
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- التابات -->
    <div class="transfers-tabs">
        <a href="?tab=out" class="tab-link <?= $tab === 'out' ? 'active' : '' ?>">
            <i class="ti ti-arrow-up-right"></i> الصادر
        </a>
        <a href="?tab=in" class="tab-link <?= $tab === 'in' ? 'active' : '' ?>">
            <i class="ti ti-arrow-down-left"></i> الوارد
        </a>
        <a href="?tab=diffs" class="tab-link <?= $tab === 'diffs' ? 'active' : '' ?>">
            <i class="ti ti-alert-triangle"></i> فروق مفتوحة
            <?php if ($openDiffsCount > 0): ?>
                <span class="badge-count"><?= $openDiffsCount ?></span>
            <?php endif; ?>
        </a>
        <a href="?tab=archive" class="tab-link <?= $tab === 'archive' ? 'active' : '' ?>">
            <i class="ti ti-inbox"></i> الأرشيف
        </a>
    </div>

    <!-- فلتر البحث السريع -->
    <form method="get" class="card" style="padding:12px;margin-bottom:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">

        <?php if ($isOwner): ?>
            <div style="display:flex;align-items:center;gap:6px;">
                <label style="font-size:13px;font-weight:600;">الفرع:</label>
                <select name="branch_id" style="padding:6px 10px;border:1px solid var(--line);border-radius:6px;background:var(--surface);color:var(--text);font-family:inherit;">
                    <option value="">كل المخازن</option>
                    <?php foreach ($supportedBranches as $sb): ?>
                        <option value="<?= $sb['id'] ?>" <?= (!empty($_GET['branch_id']) && (int)$_GET['branch_id'] === $sb['id']) ? 'selected' : '' ?>><?= e($sb['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <div style="display:flex;align-items:center;gap:6px;">
            <label style="font-size:13px;font-weight:600;">من:</label>
            <input type="date" name="from" value="<?= e($_GET['from'] ?? '') ?>" style="padding:5px 8px;border:1px solid var(--line);border-radius:6px;background:var(--surface);color:var(--text);font-family:inherit;">
        </div>

        <div style="display:flex;align-items:center;gap:6px;">
            <label style="font-size:13px;font-weight:600;">إلى:</label>
            <input type="date" name="to" value="<?= e($_GET['to'] ?? '') ?>" style="padding:5px 8px;border:1px solid var(--line);border-radius:6px;background:var(--surface);color:var(--text);font-family:inherit;">
        </div>

        <button type="submit" class="btn btn-sm btn-secondary"><i class="ti ti-filter"></i> تصفية</button>
        <?php if (!empty($_GET['from']) || !empty($_GET['to']) || !empty($_GET['branch_id'])): ?>
            <a href="?tab=<?= e($tab) ?>" class="btn btn-sm btn-ghost">مسح</a>
        <?php endif; ?>
    </form>

    <!-- محتوى التاب -->
    <?php if ($tab === 'diffs'): ?>
        <!-- جدول الفروقات المفتوحة (الزيادات المعلقة) -->
        <?php if (empty($openDiffsList)): ?>
            <div class="card" style="padding:30px;text-align:center;color:var(--ink-500);">
                <i class="ti ti-check" style="font-size:32px;color:var(--pos);display:block;margin-bottom:8px;"></i>
                لا توجد فروق زيادة معلقة حالياً. جميع التحويلات مغلقة أو لا تحتوي على زيادات.
            </div>
        <?php else: ?>
            <div class="ftable-wrap">
                <table class="ftable">
                    <thead>
                        <tr>
                            <th class="num">رقم التحويل</th>
                            <th>المخزن الراسل</th>
                            <th>المخزن المستلم</th>
                            <th>كود الصنف</th>
                            <th>اسم الصنف</th>
                            <th class="num">الكمية الزائدة</th>
                            <th>تحديد المخزن المصدر</th>
                            <th style="text-align:center;">إجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($openDiffsList as $df):
                            $docLabel = $df['doc_no'] ? ('#' . $df['doc_no'] . '/' . $df['doc_year']) : ('#' . $df['doc_id']);
                        ?>
                            <tr>
                                <td class="num"><a href="javascript:void(0)" onclick="viewTransfer(<?= (int)$df['doc_id'] ?>)" style="font-weight:bold;"><?= e($docLabel) ?></a></td>
                                <td><?= e($df['from_branch_name'] ?? ('مخزن ' . $df['branch_id'])) ?></td>
                                <td><?= e($df['to_branch_name'] ?? ('مخزن ' . $df['counter_branch_id'])) ?></td>
                                <td><?= e($df['code'] ?? '') ?></td>
                                <td><?= e($df['item_name'] ?? ('صنف ' . $df['item_id'])) ?></td>
                                <td class="num" style="color:var(--pos);font-weight:700;"><?= number_format((float)$df['qty'], 3) ?></td>
                                <td>
                                    <?php if (transfers_can_resolve()): ?>
                                        <select id="diff_source_<?= (int)$df['id'] ?>" style="padding:5px 8px;border:1px solid var(--line);border-radius:6px;background:var(--surface);color:var(--text);font-family:inherit;font-size:12.5px;">
                                            <option value="">-- اختر المخزن المصدر --</option>
                                            <?php foreach ($supportedBranches as $sb):
                                                if ($sb['id'] === (int)$df['counter_branch_id']) continue; // المستلم مايبقاش مصدر زيادة نفسه
                                            ?>
                                                <option value="<?= $sb['id'] ?>" <?= $sb['id'] === (int)$df['branch_id'] ? 'selected' : '' ?>><?= e($sb['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <span style="color:var(--ink-500);">بانتظار تحديد المدير</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center;">
                                    <?php if (transfers_can_resolve()): ?>
                                        <button type="button" class="btn btn-sm btn-primary" onclick="resolveExcess(<?= (int)$df['id'] ?>)">
                                            تأكيد وفك الزيادة
                                        </button>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <!-- جدول التحويلات (صادر، وارد، أرشيف) -->
        <?php if (empty($docs)): ?>
            <div class="card" style="padding:30px;text-align:center;color:var(--ink-500);">
                لا توجد تحويلات في هذا القسم.
            </div>
        <?php else: ?>
            <div class="ftable-wrap">
                <table class="ftable">
                    <thead>
                        <tr>
                            <th class="num">المستند</th>
                            <th>من مخزن</th>
                            <th>إلى مخزن</th>
                            <th>التاريخ</th>
                            <th class="num">الأصناف</th>
                            <th class="num">إجمالي القطع</th>
                            <th class="num">فروق</th>
                            <th style="text-align:center;">الحالة</th>
                            <th style="text-align:center;">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($docs as $d):
                            $docLabel = $d['doc_no'] ? ('#' . $d['doc_no'] . '/' . $d['doc_year']) : ('#مسودة-' . $d['id']);
                            $statusChipClass = match($d['status']) {
                                'sent'      => 'fchip-warn',
                                'closed'    => 'fchip-pos',
                                'diff_open' => 'fchip-neg',
                                'cancelled' => 'fchip-dim',
                                default     => 'fchip-info',
                            };
                            $statusLabel = match($d['status']) {
                                'requested' => 'طلب جديد',
                                'preparing' => 'جاري التجهيز',
                                'sent'      => 'في الطريق',
                                'diff_open' => 'فروق مفتوحة',
                                'closed'    => 'مكتمل ومغلق',
                                'cancelled' => 'ملغي',
                                default     => $d['status'],
                            };
                        ?>
                            <tr>
                                <td class="num">
                                    <a href="javascript:void(0)" onclick="viewTransfer(<?= (int)$d['id'] ?>)" style="font-weight:700;">
                                        <?= e($docLabel) ?>
                                    </a>
                                </td>
                                <td><?= e($d['from_branch_name'] ?? ('مخزن ' . $d['branch_id'])) ?></td>
                                <td><?= e($d['to_branch_name'] ?? ('مخزن ' . $d['counter_branch_id'])) ?></td>
                                <td><?= e($d['doc_date']) ?></td>
                                <td class="num"><?= number_format((int)$d['lines_count']) ?></td>
                                <td class="num"><?= number_format((float)$d['total_pieces'], 3) ?></td>
                                <td class="num">
                                    <?php if ((float)$d['short_qty'] > 0 || (float)$d['excess_qty'] > 0): ?>
                                        <?php if ((float)$d['short_qty'] > 0): ?>
                                            <span style="color:var(--neg);font-size:12px;">-<?= number_format((float)$d['short_qty'], 3) ?></span>
                                        <?php endif; ?>
                                        <?php if ((float)$d['excess_qty'] > 0): ?>
                                            <span style="color:var(--pos);font-size:12px;">+<?= number_format((float)$d['excess_qty'], 3) ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color:var(--ink-500);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center;">
                                    <span class="fchip <?= $statusChipClass ?>"><?= e($statusLabel) ?></span>
                                </td>
                                <td style="text-align:center;white-space:nowrap;">
                                    <?php if ($tab === 'in' && $d['status'] === 'sent' && ($isOwner || $userBranchId === (int)$d['counter_branch_id'])): ?>
                                        <button type="button" class="btn btn-sm btn-primary" onclick="openReceiveModal(<?= (int)$d['id'] ?>)">
                                            <i class="ti ti-check"></i> ابدأ العد والاستلام
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-secondary" onclick="viewTransfer(<?= (int)$d['id'] ?>)">
                                            عرض
                                        </button>
                                    <?php endif; ?>

                                    <a href="?action=print&id=<?= (int)$d['id'] ?>" target="_blank" class="btn btn-sm btn-ghost" title="طباعة الإذن">
                                        <i class="ti ti-printer"></i>
                                    </a>

                                    <?php if (in_array($d['status'], ['requested', 'preparing'], true) && ($isOwner || $userBranchId === (int)$d['branch_id'])): ?>
                                        <button type="button" class="btn btn-sm btn-danger" onclick="cancelTransfer(<?= (int)$d['id'] ?>)">
                                            إلغاء
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>

</div>

<!-- مودال طلب تحويل جديد -->
<div id="newTransferModal" class="modal-overlay">
    <div class="modal-card">
        <div class="modal-header">
            <h3 style="margin:0;font-size:16px;display:flex;align-items:center;gap:6px;">
                <i class="ti ti-plus"></i> طلب تحويل جديد
            </h3>
            <button type="button" onclick="closeModal('newTransferModal')" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--text);">&times;</button>
        </div>
        <div class="modal-body">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
                <div>
                    <label style="font-size:13px;font-weight:600;display:block;margin-bottom:4px;">المخزن الراسل:</label>
                    <?php if ($isOwner): ?>
                        <select id="new_from_branch" onchange="onSenderBranchChanged()" style="width:100%;padding:8px;border:1px solid var(--line);border-radius:6px;background:var(--surface);color:var(--text);font-family:inherit;">
                            <?php foreach ($supportedBranches as $sb): ?>
                                <option value="<?= $sb['id'] ?>"><?= e($sb['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="hidden" id="new_from_branch" value="<?= (int)$userBranchId ?>">
                        <input type="text" readonly value="<?= e($branchNamesMap[$userBranchId] ?? ('مخزن ' . $userBranchId)) ?>" style="width:100%;padding:8px;border:1px solid var(--line);border-radius:6px;background:var(--bg);color:var(--text);font-family:inherit;">
                    <?php endif; ?>
                </div>

                <div>
                    <label style="font-size:13px;font-weight:600;display:block;margin-bottom:4px;">المخزن المستلم:</label>
                    <select id="new_to_branch" style="width:100%;padding:8px;border:1px solid var(--line);border-radius:6px;background:var(--surface);color:var(--text);font-family:inherit;">
                        <?php foreach ($supportedBranches as $sb):
                            if (!$isOwner && $sb['id'] === $userBranchId) continue;
                        ?>
                            <option value="<?= $sb['id'] ?>"><?= e($sb['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- سكانر الباركود وإضافة الأصناف -->
            <div class="scanner-box">
                <div style="font-size:13px;font-weight:600;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                    <i class="ti ti-scan"></i> مسح باركود الصنف (أو كتابة الكود):
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <input type="text" id="scan_code_input" placeholder="امسح الباركود أو اكتب الكود ثم اضغط Enter..." style="flex:1;min-width:200px;padding:8px 12px;border:1.5px solid var(--line);border-radius:6px;font-family:inherit;font-size:14px;" onkeydown="if(event.key==='Enter'){event.preventDefault();lookupAndAddCode();}">
                    <button type="button" class="btn btn-secondary" onclick="lookupAndAddCode()"><i class="ti ti-search"></i> بحث وإضافة</button>
                </div>
                <div id="scan_msg" style="font-size:12px;color:var(--neg);margin-top:6px;display:none;"></div>
            </div>

            <!-- جدول سطور التحويل -->
            <div class="ftable-wrap">
                <table class="ftable" id="transfer_lines_table">
                    <thead>
                        <tr>
                            <th>الكود</th>
                            <th>اسم الصنف</th>
                            <th class="num">رصيد الراسل</th>
                            <th class="num">الكمية بالوحدة</th>
                            <th class="num">المعامل</th>
                            <th class="num">إجمالي القطع</th>
                            <th>ملاحظات</th>
                            <th style="width:40px;"></th>
                        </tr>
                    </thead>
                    <tbody id="transfer_lines_body">
                        <tr id="empty_lines_row">
                            <td colspan="8" style="text-align:center;color:var(--ink-500);padding:20px;">لم يتم إضافة أصناف بعد. استخدم السكانر أو البحث لإضافة سطور.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div style="margin-top:14px;">
                <label style="font-size:13px;font-weight:600;display:block;margin-bottom:4px;">ملاحظات الإذن:</label>
                <textarea id="new_transfer_notes" rows="2" style="width:100%;padding:8px;border:1px solid var(--line);border-radius:6px;font-family:inherit;box-sizing:border-box;" placeholder="أي ملاحظات تخص الشحنة أو سبب التحويل..."></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" onclick="closeModal('newTransferModal')">إلغاء</button>
            <button type="button" class="btn btn-primary" id="btn_submit_transfer" onclick="submitNewTransfer()">حفظ طلب التحويل</button>
        </div>
    </div>
</div>

<!-- مودال تفاصيل التحويل / الإرسال / التجهيز -->
<div id="viewTransferModal" class="modal-overlay">
    <div class="modal-card">
        <div class="modal-header">
            <h3 style="margin:0;font-size:16px;display:flex;align-items:center;gap:6px;" id="view_modal_title">
                <i class="ti ti-file-invoice"></i> تفاصيل التحويل
            </h3>
            <button type="button" onclick="closeModal('viewTransferModal')" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--text);">&times;</button>
        </div>
        <div class="modal-body" id="view_modal_content">
            <div style="text-align:center;padding:30px;color:var(--ink-500);">جاري التحميل...</div>
        </div>
        <div class="modal-footer" id="view_modal_footer">
            <button type="button" class="btn btn-ghost" onclick="closeModal('viewTransferModal')">إغلاق</button>
        </div>
    </div>
</div>

<!-- مودال العد والاستلام -->
<div id="receiveModal" class="modal-overlay">
    <div class="modal-card">
        <div class="modal-header">
            <h3 style="margin:0;font-size:16px;display:flex;align-items:center;gap:6px;" id="receive_modal_title">
                <i class="ti ti-check"></i> عد واستلام الشحنة
            </h3>
            <button type="button" onclick="closeModal('receiveModal')" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--text);">&times;</button>
        </div>
        <div class="modal-body">
            <div id="receive_info_banner" style="background:var(--bg);padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px;">
                جاري التحميل...
            </div>

            <div class="ftable-wrap">
                <table class="ftable">
                    <thead>
                        <tr>
                            <th>الكود</th>
                            <th>اسم الصنف</th>
                            <th class="num">المبعوت (قطع)</th>
                            <th class="num" style="width:130px;">المعدود (قطع)</th>
                            <th class="num">الفرق المتوقع</th>
                            <th style="width:80px;text-align:center;">حفظ</th>
                        </tr>
                    </thead>
                    <tbody id="receive_lines_body"></tbody>
                </table>
            </div>

            <div id="receive_summary_box" style="margin-top:14px;display:none;background:var(--bg);border-radius:8px;padding:12px;"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" onclick="closeModal('receiveModal')">إلغاء</button>
            <button type="button" class="btn btn-primary" id="btn_confirm_receive" onclick="confirmReceive()">تأكيد الاستلام النهائي</button>
        </div>
    </div>
</div>

<script src="assets/barcode_scanner.js?v=9"></script>
<script>
const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
const USER_BRANCH_ID = <?= (int) ($userBranchId ?? 0) ?>;
const IS_OWNER = <?= $isOwner ? 'true' : 'false' ?>;

const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

let currentTransferLines = [];
let activeReceiveDocId = null;
let activeReceiveDoc = null;

// دالة نداء الـ API الموحدة
async function apiCall(action, data = null) {
    const isPost = data !== null;
    const url = 'transfers_api.php' + (!isPost ? ('?' + new URLSearchParams({ action, ...action })) : '');
    const opts = {
        method: isPost ? 'POST' : 'GET',
        headers: {
            'Accept': 'application/json',
            'X-CSRF-Token': CSRF_TOKEN,
        }
    };
    if (isPost) {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify({ action, ...data });
    }
    const res = await fetch(isPost ? 'transfers_api.php' : url, opts);
    const json = await res.json();
    if (!json.ok) {
        throw new Error(json.error || 'حصل خطأ في العملية');
    }
    return json.data;
}

function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
}

function openModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'flex';
}

function openNewTransferModal() {
    currentTransferLines = [];
    renderLinesTable();
    document.getElementById('scan_code_input').value = '';
    document.getElementById('new_transfer_notes').value = '';
    document.getElementById('scan_msg').style.display = 'none';
    openModal('newTransferModal');
    setTimeout(() => {
        document.getElementById('scan_code_input').focus();
    }, 150);
}

function onSenderBranchChanged() {
    // تحديث رصيد الراسل لكل السطور المضافة حال تغيير الفرع
    const fromId = parseInt(document.getElementById('new_from_branch').value, 10);
    currentTransferLines.forEach(async (line) => {
        try {
            const res = await apiCall({ action: 'lookup_code', code: line.code, from_branch_id: fromId });
            line.balance = res.item.balance;
            renderLinesTable();
        } catch (e) {}
    });
}

async function lookupAndAddCode() {
    const input = document.getElementById('scan_code_input');
    const msg = document.getElementById('scan_msg');
    const code = input.value.trim();
    if (!code) return;

    msg.style.display = 'none';
    const fromBranch = parseInt(document.getElementById('new_from_branch').value, 10);

    try {
        const res = await apiCall({ action: 'lookup_code', code: code, from_branch_id: fromBranch });
        const item = res.item;

        // التحقق هل الصنف موجود بالفعل في السطور
        const existingIdx = currentTransferLines.findIndex(l => l.code === item.code);
        if (existingIdx >= 0) {
            currentTransferLines[existingIdx].qty_units += 1;
            currentTransferLines[existingIdx].qty_pieces = currentTransferLines[existingIdx].qty_units * currentTransferLines[existingIdx].unit_factor;
        } else {
            currentTransferLines.push({
                item_id: item.item_id,
                code: item.code,
                name: item.name,
                unit_factor: item.unit_factor || 1.0,
                qty_units: 1.0,
                qty_pieces: item.unit_factor || 1.0,
                balance: item.balance || 0.0,
                notes: '',
            });
        }
        input.value = '';
        renderLinesTable();
    } catch (e) {
        msg.textContent = e.message;
        msg.style.display = 'block';
    }
}

function updateLineQty(idx, val) {
    const qty = parseFloat(val) || 0;
    currentTransferLines[idx].qty_units = qty;
    currentTransferLines[idx].qty_pieces = Math.round(qty * currentTransferLines[idx].unit_factor * 1000) / 1000;
    const piecesEl = document.getElementById('line_pieces_' + idx);
    if (piecesEl) {
        piecesEl.textContent = currentTransferLines[idx].qty_pieces.toFixed(3);
    }
}

function updateLineNotes(idx, val) {
    currentTransferLines[idx].notes = val;
}

function removeLine(idx) {
    currentTransferLines.splice(idx, 1);
    renderLinesTable();
}

function renderLinesTable() {
    const tbody = document.getElementById('transfer_lines_body');
    if (!currentTransferLines.length) {
        tbody.innerHTML = `<tr id="empty_lines_row"><td colspan="8" style="text-align:center;color:var(--ink-500);padding:20px;">لم يتم إضافة أصناف بعد. استخدم السكانر أو البحث لإضافة سطور.</td></tr>`;
        return;
    }

    let html = '';
    currentTransferLines.forEach((l, idx) => {
        html += `
            <tr>
                <td><strong>${esc(l.code)}</strong></td>
                <td>${esc(l.name || 'صنف ' + l.item_id)}</td>
                <td class="num" style="color:var(--ink-500);">${esc(l.balance)}</td>
                <td class="num">
                    <input type="number" step="any" min="0" value="${esc(l.qty_units)}" oninput="updateLineQty(${idx}, this.value)" style="width:75px;padding:4px 6px;border:1px solid var(--line);border-radius:4px;text-align:left;font-family:inherit;">
                </td>
                <td class="num">${esc(l.unit_factor)}</td>
                <td class="num" id="line_pieces_${idx}" style="font-weight:bold;">${l.qty_pieces.toFixed(3)}</td>
                <td>
                    <input type="text" value="${esc(l.notes)}" placeholder="ملاحظة..." oninput="updateLineNotes(${idx}, this.value)" style="width:100%;padding:4px;border:1px solid var(--line);border-radius:4px;font-family:inherit;font-size:12px;">
                </td>
                <td style="text-align:center;">
                    <button type="button" onclick="removeLine(${idx})" style="background:none;border:none;color:var(--neg);cursor:pointer;font-size:16px;">&times;</button>
                </td>
            </tr>
        `;
    });
    tbody.innerHTML = html;
}

async function submitNewTransfer() {
    const fromBranch = parseInt(document.getElementById('new_from_branch').value, 10);
    const toBranch   = parseInt(document.getElementById('new_to_branch').value, 10);
    const notes      = document.getElementById('new_transfer_notes').value.trim();

    if (fromBranch === toBranch) {
        alert('لا يمكن التحويل لنفس المخزن.');
        return;
    }
    if (!currentTransferLines.length) {
        alert('يجب إضافة صنف واحد على الأقل.');
        return;
    }

    const btn = document.getElementById('btn_submit_transfer');
    btn.disabled = true;

    try {
        const payload = {
            from_branch_id: fromBranch,
            to_branch_id: toBranch,
            notes: notes,
            lines: currentTransferLines.map(l => ({
                item_id: l.item_id,
                code: l.code,
                unit_factor: l.unit_factor,
                qty_units: l.qty_units,
                notes: l.notes,
            }))
        };
        const res = await apiCall('create', payload);
        alert(`تم إنشاء طلب التحويل بنجاح (#${res.doc_id})`);
        location.reload();
    } catch (e) {
        alert('فشل حفظ التحويل: ' + e.message);
        btn.disabled = false;
    }
}

async function viewTransfer(docId) {
    openModal('viewTransferModal');
    const content = document.getElementById('view_modal_content');
    const footer  = document.getElementById('view_modal_footer');
    content.innerHTML = '<div style="text-align:center;padding:30px;color:var(--ink-500);">جاري التحميل...</div>';

    try {
        const res = await apiCall({ action: 'doc', id: docId });
        const doc   = res.doc;
        const lines = res.lines;
        const diffs = res.diffs;
        const events = res.events;

        const docLabel = doc.doc_no ? (`#${doc.doc_no} / ${doc.doc_year}`) : (`#مسودة-${doc.id}`);

        let linesHtml = '';
        lines.forEach((l, idx) => {
            linesHtml += `
                <tr>
                    <td>${idx + 1}</td>
                    <td><strong>${esc(l.code)}</strong></td>
                    <td>${esc(l.item_name || 'صنف ' + l.item_id)}</td>
                    <td class="num">${parseFloat(l.qty_units).toFixed(2)}</td>
                    <td class="num">${parseFloat(l.unit_factor).toFixed(2)}</td>
                    <td class="num" style="font-weight:bold;">${parseFloat(l.qty_pieces).toFixed(3)}</td>
                    <td class="num">${l.received_pieces !== null ? parseFloat(l.received_pieces).toFixed(3) : '<span style="color:var(--ink-500);">لم يُعد</span>'}</td>
                </tr>
            `;
        });

        let diffsHtml = '';
        if (diffs.length) {
            diffsHtml = `
                <div style="margin-top:16px;">
                    <h4 style="margin:0 0 8px 0;font-size:14px;color:var(--neg);"><i class="ti ti-alert-triangle"></i> الفروقات المسجلة:</h4>
                    <table class="ftable">
                        <thead><tr><th>النوع</th><th>الصنف</th><th class="num">الكمية</th><th>الحالة</th><th>التفاصيل</th></tr></thead>
                        <tbody>
                            ${diffs.map(d => `
                                <tr>
                                    <td>${d.kind === 'short' ? '<span class="fchip fchip-neg">عجز (ناقص)</span>' : '<span class="fchip fchip-pos">زيادة</span>'}</td>
                                    <td>${esc(d.code)} — ${esc(d.item_name || '')}</td>
                                    <td class="num" style="font-weight:bold;">${parseFloat(d.qty).toFixed(3)}</td>
                                    <td>${d.status === 'resolved' ? '<span class="fchip fchip-pos">محلولة</span>' : '<span class="fchip fchip-warn">معلقة</span>'}</td>
                                    <td style="font-size:12px;color:var(--ink-500);">${esc(d.note || (d.source_branch_name ? 'المصدر: ' + d.source_branch_name : '—'))}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            `;
        }

        let eventsHtml = '';
        if (events.length) {
            eventsHtml = `
                <div style="margin-top:16px;">
                    <h4 style="margin:0 0 8px 0;font-size:14px;"><i class="ti ti-clock"></i> سجل أحداث المستند:</h4>
                    <div style="font-size:12px;background:var(--bg);border-radius:8px;padding:10px;">
                        ${events.map(ev => `
                            <div style="margin-bottom:6px;display:flex;gap:10px;">
                                <span style="color:var(--ink-500);width:140px;">${esc(ev.at)}</span>
                                <strong>${esc(ev.event)}</strong>
                                <span>بواسطة: ${esc(ev.user_name || 'النظام')}</span>
                                ${ev.details ? `<span style="color:var(--ink-500);">${esc(ev.details)}</span>` : ''}
                            </div>
                        `).join('')}
                    </div>
                </div>
            `;
        }

        content.innerHTML = `
            <div style="display:grid;grid-template-columns:repeat(2, 1fr);gap:10px 16px;background:var(--bg);padding:12px;border-radius:8px;margin-bottom:14px;font-size:13px;">
                <div><strong>رقم المستند:</strong> ${esc(docLabel)}</div>
                <div><strong>التاريخ:</strong> ${esc(doc.doc_date)}</div>
                <div><strong>من مخزن:</strong> ${esc(doc.from_branch_name || 'مخزن ' + doc.branch_id)}</div>
                <div><strong>إلى مخزن:</strong> ${esc(doc.to_branch_name || 'مخزن ' + doc.counter_branch_id)}</div>
                <div><strong>الحالة:</strong> <span class="fchip fchip-info">${esc(doc.status)}</span></div>
                <div><strong>تاريخ الإرسال:</strong> ${esc(doc.sent_at || '—')}</div>
                <div><strong>تاريخ الاستلام:</strong> ${esc(doc.received_at || '—')}</div>
                <div><strong>المنشئ:</strong> ${esc(doc.created_by_name || '—')}</div>
            </div>

            ${doc.notes ? `<div style="margin-bottom:12px;font-size:12.5px;"><strong>ملاحظات:</strong> ${esc(doc.notes)}</div>` : ''}

            <h4 style="margin:0 0 8px 0;font-size:14px;">أصناف التحويل:</h4>
            <div class="ftable-wrap">
                <table class="ftable">
                    <thead><tr><th>#</th><th>الكود</th><th>اسم الصنف</th><th class="num">الكمية</th><th class="num">المعامل</th><th class="num">المبعوت (قطع)</th><th class="num">المعدود (قطع)</th></tr></thead>
                    <tbody>${linesHtml}</tbody>
                </table>
            </div>

            ${diffsHtml}
            ${eventsHtml}
        `;

        // أزرار الفوتر بناءً على الحالة
        let footerButtons = `<button type="button" class="btn btn-ghost" onclick="closeModal('viewTransferModal')">إغلاق</button>`;
        footerButtons += `<a href="?action=print&id=${doc.id}" target="_blank" class="btn btn-secondary"><i class="ti ti-printer"></i> طباعة الإذن</a>`;

        if (['requested', 'preparing'].includes(doc.status) && (IS_OWNER || USER_BRANCH_ID === parseInt(doc.branch_id, 10))) {
            footerButtons += `<button type="button" class="btn btn-primary" onclick="sendTransfer(${doc.id})"><i class="ti ti-send"></i> إرسال البضاعة الآن</button>`;
            footerButtons += `<button type="button" class="btn btn-danger" onclick="cancelTransfer(${doc.id})">إلغاء التحويل</button>`;
        }

        if (doc.status === 'sent' && (IS_OWNER || USER_BRANCH_ID === parseInt(doc.counter_branch_id, 10))) {
            footerButtons += `<button type="button" class="btn btn-primary" onclick="closeModal('viewTransferModal');openReceiveModal(${doc.id})"><i class="ti ti-check"></i> ابدأ العد والاستلام</button>`;
        }

        footer.innerHTML = footerButtons;

    } catch (e) {
        content.innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`;
    }
}

async function sendTransfer(docId) {
    if (!confirm('هل أنت متأكد من إرسال البضاعة؟ سيتم خصمها من مخزن الراسل وتحويلها إلى رصيد "في الطريق" للمستلم وحجز رقم المستند.')) {
        return;
    }
    try {
        const res = await apiCall('send', { id: docId });
        alert(`تم إرسال التحويل بنجاح برقم إذن #${res.doc_no}`);
        location.reload();
    } catch (e) {
        alert('فشل إرسال التحويل: ' + e.message);
    }
}

async function cancelTransfer(docId) {
    const reason = prompt('برجاء كتابة سبب إلغاء التحويل:');
    if (reason === null) return;
    try {
        await apiCall('cancel', { id: docId, reason: reason });
        alert('تم إلغاء التحويل.');
        location.reload();
    } catch (e) {
        alert('فشل إلغاء التحويل: ' + e.message);
    }
}

async function openReceiveModal(docId) {
    activeReceiveDocId = docId;
    openModal('receiveModal');
    const banner = document.getElementById('receive_info_banner');
    const tbody  = document.getElementById('receive_lines_body');
    const summary = document.getElementById('receive_summary_box');
    summary.style.display = 'none';
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;">جاري التحميل...</td></tr>';

    try {
        const res = await apiCall({ action: 'doc', id: docId });
        activeReceiveDoc = res;
        const doc   = res.doc;
        const lines = res.lines;

        banner.innerHTML = `
            <strong>تحويل #${esc(doc.doc_no || doc.id)}</strong> من <strong>${esc(doc.from_branch_name)}</strong> إلى <strong>${esc(doc.to_branch_name)}</strong> — تاريخ الإرسال: ${esc(doc.sent_at || '—')}
            <div style="font-size:12px;color:var(--ink-500);margin-top:4px;">قم بعد البضاعة المستلمة فعلياً وإدخال عدد القطع لكل صنف (الحفظ تلقائي عند الخروج من الخانة).</div>
        `;

        let html = '';
        lines.forEach(l => {
            const sent = parseFloat(l.qty_pieces);
            const counted = l.received_pieces !== null ? parseFloat(l.received_pieces) : sent; // افتراضي كمية المبعوت للتسهيل أو خانة
            html += `
                <tr id="rx_row_${l.id}">
                    <td><strong>${esc(l.code)}</strong></td>
                    <td>${esc(l.item_name || 'صنف ' + l.item_id)}</td>
                    <td class="num" id="rx_sent_${l.id}">${sent.toFixed(3)}</td>
                    <td class="num">
                        <input type="number" step="any" min="0" value="${counted}" id="rx_input_${l.id}"
                               onblur="saveCount(${l.id})"
                               oninput="calcDiff(${l.id})"
                               style="width:90px;padding:5px 8px;border:1.5px solid var(--line);border-radius:6px;text-align:left;font-family:inherit;font-weight:bold;">
                    </td>
                    <td class="num" id="rx_diff_${l.id}" style="font-weight:bold;">—</td>
                    <td style="text-align:center;" id="rx_status_${l.id}">
                        <i class="ti ti-check" style="color:var(--pos);display:none;"></i>
                    </td>
                </tr>
            `;
        });
        tbody.innerHTML = html;

        lines.forEach(l => calcDiff(l.id));

    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="6" class="alert alert-danger">${esc(e.message)}</td></tr>`;
    }
}

function calcDiff(lineId) {
    const sentVal = parseFloat(document.getElementById('rx_sent_' + lineId).textContent) || 0;
    const inputVal = parseFloat(document.getElementById('rx_input_' + lineId).value) || 0;
    const diff = Math.round((inputVal - sentVal) * 1000) / 1000;
    const diffEl = document.getElementById('rx_diff_' + lineId);

    if (diff === 0) {
        diffEl.textContent = 'مطابق (0)';
        diffEl.style.color = 'var(--ink-500)';
    } else if (diff < 0) {
        diffEl.textContent = `عجز (${diff})`;
        diffEl.style.color = 'var(--neg)';
    } else {
        diffEl.textContent = `زيادة (+${diff})`;
        diffEl.style.color = 'var(--pos)';
    }
}

async function saveCount(lineId) {
    const inputVal = parseFloat(document.getElementById('rx_input_' + lineId).value) || 0;
    const statusEl = document.getElementById('rx_status_' + lineId);

    try {
        await apiCall('count', {
            id: activeReceiveDocId,
            line_id: lineId,
            pieces: inputVal
        });
        statusEl.innerHTML = '<span style="color:var(--pos);font-size:12px;">تم الحفظ</span>';
        setTimeout(() => { statusEl.innerHTML = ''; }, 2000);
    } catch (e) {
        statusEl.innerHTML = '<span style="color:var(--neg);font-size:12px;">خطأ</span>';
    }
}

async function confirmReceive() {
    if (!activeReceiveDoc || !activeReceiveDocId) return;

    // حفظ كل الخانات أولاً
    const lines = activeReceiveDoc.lines;
    let shorts = [];
    let excesses = [];

    for (const l of lines) {
        const sent = parseFloat(l.qty_pieces);
        const inputVal = parseFloat(document.getElementById('rx_input_' + l.id).value) || 0;
        const diff = Math.round((inputVal - sent) * 1000) / 1000;
        if (diff < 0) {
            shorts.push(`${l.code}: عجز ${Math.abs(diff)} قطعة (سيرد للراسل)`);
        } else if (diff > 0) {
            excesses.push(`${l.code}: زيادة ${diff} قطعة (ستعلق كحالة مفتوحة)`);
        }
    }

    let confirmMsg = 'هل أنت متأكد من تأكيد الاستلام النهائي؟';
    if (shorts.length || excesses.length) {
        confirmMsg += '\n\nملخص الفروقات:\n';
        if (shorts.length) confirmMsg += '\n' + shorts.join('\n');
        if (excesses.length) confirmMsg += '\n' + excesses.join('\n');
    } else {
        confirmMsg += '\nجميع الأصناف مطابقة 100%.';
    }

    if (!confirm(confirmMsg)) {
        return;
    }

    const btn = document.getElementById('btn_confirm_receive');
    btn.disabled = true;

    try {
        const res = await apiCall('receive', { id: activeReceiveDocId });
        alert(`تم استلام التحويل بنجاح! الحالة الحالية: ${res.status}`);
        location.reload();
    } catch (e) {
        alert('فشل استلام التحويل: ' + e.message);
        btn.disabled = false;
    }
}

async function resolveExcess(diffId) {
    const sel = document.getElementById('diff_source_' + diffId);
    const sourceBranchId = parseInt(sel.value, 10);
    if (!sourceBranchId) {
        alert('برجاء اختيار المخزن المصدر للزيادة أولاً.');
        return;
    }

    if (!confirm('هل تريد تأكيد تسوية هذه الزيادة؟ سيتم خصم الكمية من المخزن المصدر وإضافتها لمخزن المستلم بمستند تسوية مرتبط.')) {
        return;
    }

    try {
        const res = await apiCall('resolve_excess', {
            diff_id: diffId,
            source_branch_id: sourceBranchId
        });
        alert(`تم فك الزيادة وإنشاء مستند تسوية مرتبط (#${res.adjust_doc_id})`);
        location.reload();
    } catch (e) {
        alert('فشل فك الزيادة: ' + e.message);
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
