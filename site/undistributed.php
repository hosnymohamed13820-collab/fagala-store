<?php
/**
 * undistributed.php — صفحة الأصناف غير الموزّعة
 * نظام الفجالة ستور المالي
 *
 * تجاوب على سؤال: «اللي اشتريناه في الفترة دي، اتوزّع كله ولا لسه فيه حاجة عندنا في الإدارة؟»
 * الإدارة بس (company_branch = 1).
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_permission('invoices:undistributed');

$pdo  = db();
$user = current_user();
$uid  = (int) ($user['id'] ?? 0);

// حسابات الفروع في نظام الشركة — المردود ليها = توزيع، مش مرتجع للمورد
const UNDIST_BRANCH_ACCOUNTS = [
    4       => 'بونجور',
    1000181 => 'ABC',
    1000180 => 'دجلة',
    3000091 => 'الفجالة',
    1000245 => 'إشراقة',
    1000252 => 'إشراقة',
];

// =========================================================================
//  معالجة طلبات AJAX لجلب تفاصيل الفاتورة للنافذة المنبثقة
// =========================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'inv') {
    header('Content-Type: application/json; charset=utf-8');
    $sth    = (int) ($_GET['sth'] ?? 0);
    $suffix = trim((string) ($_GET['suffix'] ?? ''));

    if ($sth <= 0 || $suffix === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'بيانات الفاتورة غير صحيحة'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stHdr = $pdo->prepare(
        'SELECT sth_id, suffix, doc_no, doc_type, supplier_id, supplier_name, invoice_date
         FROM hq_invoices
         WHERE sth_id = ? AND suffix = ? AND company_branch = 1
         LIMIT 1'
    );
    $stHdr->execute([$sth, $suffix]);
    $hdr = $stHdr->fetch(PDO::FETCH_ASSOC);

    if (!$hdr) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'الفاتورة مش موجودة'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $docType = (int) $hdr['doc_type'];
    $typeLabel = 'أخرى';
    if ($docType === 2) {
        $typeLabel = 'شراء';
    } elseif ($docType === 6) {
        $suppId = (int) ($hdr['supplier_id'] ?? 0);
        if (isset(UNDIST_BRANCH_ACCOUNTS[$suppId])) {
            $typeLabel = 'توزيع على فرع (' . UNDIST_BRANCH_ACCOUNTS[$suppId] . ')';
        } else {
            $typeLabel = 'مردود للمورد';
        }
    }

    $stLines = $pdo->prepare(
        'SELECT line_no, item_code, item_name, quantity, unit_price
         FROM hq_invoice_lines
         WHERE sth_id = ? AND suffix = ?
         ORDER BY line_no ASC'
    );
    $stLines->execute([$sth, $suffix]);
    $lines = $stLines->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok'     => true,
        'header' => [
            'doc_no'        => (string) ($hdr['doc_no'] ?? ''),
            'type_label'    => $typeLabel,
            'supplier_name' => (string) ($hdr['supplier_name'] ?? ''),
            'invoice_date'  => (string) ($hdr['invoice_date'] ?? ''),
        ],
        'lines'  => $lines,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// =========================================================================
//  معالجة طلبات POST (تحديد الفترة + الحفظ التلقائي للملاحظات)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!csrf_verify($_POST['csrf'] ?? '')) {
            throw new RuntimeException('انتهت صلاحية الجلسة، اعمل تحديث للصفحة.');
        }

        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'set_period') {
            $from    = trim((string) ($_POST['from'] ?? ''));
            $to      = trim((string) ($_POST['to'] ?? ''));
            $confirm = trim((string) ($_POST['confirm'] ?? '0'));

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                throw new RuntimeException('صيغة التاريخ غير صالحة.');
            }

            if ($from > $to) {
                throw new RuntimeException('تاريخ البداية بعد النهاية.');
            }

            $diffDays = (strtotime($to) - strtotime($from)) / 86400;
            if ($diffDays > 180) {
                throw new RuntimeException('الفترة طويلة — ١٨٠ يوم بالكتير.');
            }

            $stCur = $pdo->query('SELECT date_from, date_to FROM undist_state WHERE id = 1 LIMIT 1');
            $curState = $stCur->fetch(PDO::FETCH_ASSOC);
            $samePeriod = ($curState && $curState['date_from'] === $from && $curState['date_to'] === $to);

            if (!$samePeriod) {
                $notesCount = (int) $pdo->query('SELECT COUNT(*) FROM undist_notes')->fetchColumn();
                if ($notesCount > 0 && $confirm !== '1') {
                    throw new RuntimeException('يوجد ملاحظات مسجلة على الفترة الحالية، تأكيد المسح مطلوب.');
                }

                $pdo->beginTransaction();
                try {
                    $pdo->exec('DELETE FROM undist_notes');
                    $st = $pdo->prepare(
                        'INSERT INTO undist_state (id, date_from, date_to, updated_by)
                         VALUES (1, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE
                            date_from  = VALUES(date_from),
                            date_to    = VALUES(date_to),
                            updated_by = VALUES(updated_by),
                            updated_at = CURRENT_TIMESTAMP'
                    );
                    $st->execute([$from, $to, $uid > 0 ? $uid : null]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
            }

            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'save_note') {
            $code = trim((string) ($_POST['item_code'] ?? ''));
            $note = trim((string) ($_POST['note'] ?? ''));

            if ($code === '') {
                throw new RuntimeException('كود الصنف مطلوب.');
            }

            if ($note === '') {
                $st = $pdo->prepare('DELETE FROM undist_notes WHERE item_code = ?');
                $st->execute([$code]);
            } else {
                $st = $pdo->prepare(
                    'INSERT INTO undist_notes (item_code, note, updated_by)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        note       = VALUES(note),
                        updated_by = VALUES(updated_by),
                        updated_at = CURRENT_TIMESTAMP'
                );
                $st->execute([$code, $note, $uid > 0 ? $uid : null]);
            }

            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }

        throw new RuntimeException('إجراء غير معروف.');
    } catch (Throwable $ex) {
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// =========================================================================
//  عرض الصفحة (GET)
// =========================================================================
$stState  = $pdo->query('SELECT date_from, date_to FROM undist_state WHERE id = 1 LIMIT 1');
$stateRow = $stState->fetch(PDO::FETCH_ASSOC);

$hasState  = ($stateRow !== false);
$savedFrom = $hasState ? (string) $stateRow['date_from'] : '';
$savedTo   = $hasState ? (string) $stateRow['date_to'] : '';

$notesCount = (int) $pdo->query('SELECT COUNT(*) FROM undist_notes')->fetchColumn();

$defaultFrom = $hasState ? $savedFrom : date('Y-m-d', strtotime('-7 days'));
$defaultTo   = $hasState ? $savedTo   : date('Y-m-d');

$items         = [];
$diffCount     = 0;
$zeroDiffCount = 0;

if ($hasState) {
    $branchIds       = array_keys(UNDIST_BRANCH_ACCOUNTS);
    $inPlaceholders  = implode(',', array_fill(0, count($branchIds), '?'));

    // الاستعلام ١: التجميع لكل صنف في الإدارة فقط (company_branch = 1)
    $aggSql = "SELECT 
        l.item_code,
        MAX(l.item_name) AS item_name,
        SUM(CASE WHEN h.doc_type = 2 THEN l.quantity ELSE 0 END) AS qty_purchased,
        SUM(CASE WHEN h.doc_type = 6 AND (h.supplier_id NOT IN ($inPlaceholders) OR h.supplier_id IS NULL) THEN l.quantity ELSE 0 END) AS qty_returned_vendor,
        SUM(CASE WHEN h.doc_type = 6 AND h.supplier_id IN ($inPlaceholders) THEN l.quantity ELSE 0 END) AS qty_distributed_branch
    FROM hq_invoice_lines l
    JOIN hq_invoices h ON h.sth_id = l.sth_id AND h.suffix = l.suffix
    WHERE h.company_branch = 1
      AND h.doc_type IN (2, 6)
      AND h.invoice_date >= ?
      AND h.invoice_date < DATE_ADD(?, INTERVAL 1 DAY)
      AND l.item_code <> ''
    GROUP BY l.item_code";

    $aggParams = array_merge($branchIds, $branchIds, [$savedFrom, $savedTo]);
    $stAgg     = $pdo->prepare($aggSql);
    $stAgg->execute($aggParams);
    $aggRows   = $stAgg->fetchAll(PDO::FETCH_ASSOC);

    // الاستعلام ٢: فواتير الشراء والمردود لنفس الفترة في الإدارة فقط (company_branch = 1)
    $invSql = "SELECT DISTINCT
        l.item_code,
        h.sth_id,
        h.suffix,
        h.doc_no,
        h.doc_type,
        h.supplier_id,
        h.invoice_date
    FROM hq_invoice_lines l
    JOIN hq_invoices h ON h.sth_id = l.sth_id AND h.suffix = l.suffix
    WHERE h.company_branch = 1
      AND h.doc_type IN (2, 6)
      AND h.invoice_date >= ?
      AND h.invoice_date < DATE_ADD(?, INTERVAL 1 DAY)
      AND l.item_code <> ''
    ORDER BY h.invoice_date ASC";

    $stInv = $pdo->prepare($invSql);
    $stInv->execute([$savedFrom, $savedTo]);
    $invRows = $stInv->fetchAll(PDO::FETCH_ASSOC);

    $itemInvoices = [];
    foreach ($invRows as $inv) {
        $code    = (string) $inv['item_code'];
        $docType = (int) $inv['doc_type'];
        if ($docType === 2) {
            $itemInvoices[$code]['purchases'][] = [
                'sth_id' => (int) $inv['sth_id'],
                'suffix' => (string) $inv['suffix'],
                'doc_no' => (string) $inv['doc_no'],
            ];
        } elseif ($docType === 6) {
            $suppId     = (int) ($inv['supplier_id'] ?? 0);
            $branchName = UNDIST_BRANCH_ACCOUNTS[$suppId] ?? null;
            $label      = (string) $inv['doc_no'] . ' · ' . ($branchName !== null ? $branchName : 'مورد');
            $itemInvoices[$code]['returns'][] = [
                'sth_id' => (int) $inv['sth_id'],
                'suffix' => (string) $inv['suffix'],
                'label'  => $label,
            ];
        }
    }

    // الاستعلام ٣: الملاحظات المحفوظة
    $notesMap = [];
    $stNotes  = $pdo->query('SELECT item_code, note FROM undist_notes');
    while ($nRow = $stNotes->fetch(PDO::FETCH_ASSOC)) {
        $notesMap[(string) $nRow['item_code']] = (string) $nRow['note'];
    }

    // بناء قائمة الأصناف مع حساب الفرق والترتيب
    foreach ($aggRows as $r) {
        $code          = (string) $r['item_code'];
        $name          = (string) ($r['item_name'] ?? '');
        $qtyPurchased  = round((float) ($r['qty_purchased'] ?? 0), 3);
        $qtyRetVendor  = round((float) ($r['qty_returned_vendor'] ?? 0), 3);
        $qtyDistBranch = round((float) ($r['qty_distributed_branch'] ?? 0), 3);
        $diff          = round($qtyPurchased - $qtyRetVendor - $qtyDistBranch, 3);

        if ($diff == 0) {
            $zeroDiffCount++;
        } else {
            $diffCount++;
        }

        $items[] = [
            'item_code'       => $code,
            'item_name'       => $name,
            'qty_purchased'   => $qtyPurchased,
            'qty_ret_vendor'  => $qtyRetVendor,
            'qty_dist_branch' => $qtyDistBranch,
            'diff'            => $diff,
            'note'            => $notesMap[$code] ?? '',
            'purchases'       => $itemInvoices[$code]['purchases'] ?? [],
            'returns'         => $itemInvoices[$code]['returns'] ?? [],
        ];
    }

    // الترتيب: الفرق من الأكبر للأصغر، وبعدين الاسم
    usort($items, function ($a, $b) {
        if ($a['diff'] != $b['diff']) {
            return ($b['diff'] <=> $a['diff']);
        }
        return strcmp($a['item_name'], $b['item_name']);
    });
}

$page_title    = 'الأصناف غير الموزّعة';
$active_module = 'invoices';
require_once __DIR__ . '/includes/header.php';
?>

<style>
.prob-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--r);
    padding: 16px 20px;
    margin-bottom: 16px;
    box-shadow: var(--shadow-sm);
}
.prob-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    background: var(--surface);
}
.prob-table th, .prob-table td {
    padding: 8px 10px;
    border-bottom: 1px solid var(--line-soft);
    text-align: right;
    vertical-align: middle;
}
.prob-table th {
    background: var(--line-soft);
    color: var(--ink-700);
    font-weight: 600;
}
.undist-note-input {
    width: 100%;
    min-width: 130px;
    padding: 5px 8px;
    border: 1px solid var(--line);
    border-radius: var(--r-sm);
    font-size: 12.5px;
    background: var(--surface);
    color: var(--text);
    box-sizing: border-box;
    font-family: inherit;
}
.undist-note-input:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 2px var(--accent-soft);
}
.save-ind {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 16px;
    margin-right: 4px;
    font-size: 13px;
}
.filter-btn-group {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
    flex-wrap: wrap;
}
.filter-pill {
    padding: 6px 14px;
    border-radius: var(--r);
    border: 1px solid var(--line);
    background: var(--surface);
    color: var(--text);
    cursor: pointer;
    font-size: 12.5px;
    font-weight: 600;
    font-family: inherit;
    transition: all 0.15s;
}
.filter-pill:hover {
    border-color: var(--accent);
    color: var(--accent-text);
}
.filter-pill.active {
    background: var(--accent);
    color: #fff;
    border-color: var(--accent);
}
.inv-pill-btn {
    padding: 2px 7px;
    font-size: 11px;
    white-space: nowrap;
}
.modal-bg {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(30, 27, 75, 0.45);
    z-index: var(--z-modal);
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.modal-bg.open {
    display: flex;
}
.modal {
    background: var(--surface);
    border-radius: var(--r);
    width: 100%;
    padding: 20px;
    box-shadow: var(--shadow);
    border: 1px solid var(--line);
    color: var(--text);
}
</style>

<div class="content-container">

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
        <h2 style="font-size:18px;font-weight:700;margin:0;display:flex;align-items:center;gap:8px;">
            <i class="ti ti-package-export"></i> الأصناف غير الموزّعة
        </h2>
    </div>

    <!-- شريط اختيار وتحديد الفترة -->
    <div class="prob-card">
        <form id="periodForm" style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;">
            <div style="display:flex;flex-direction:column;gap:4px;">
                <label style="font-size:12px;font-weight:600;color:var(--ink-700);">من</label>
                <input type="date" id="dateFrom" name="from" value="<?= htmlspecialchars($defaultFrom) ?>" required style="padding:6px 10px;border:1px solid var(--line);border-radius:var(--r-sm);font-family:inherit;font-size:13px;background:var(--surface);color:var(--text);">
            </div>
            <div style="display:flex;flex-direction:column;gap:4px;">
                <label style="font-size:12px;font-weight:600;color:var(--ink-700);">إلى</label>
                <input type="date" id="dateTo" name="to" value="<?= htmlspecialchars($defaultTo) ?>" required style="padding:6px 10px;border:1px solid var(--line);border-radius:var(--r-sm);font-family:inherit;font-size:13px;background:var(--surface);color:var(--text);">
            </div>
            <button type="submit" class="btn btn-primary" id="btnSubmitPeriod">
                <i class="ti ti-eye"></i> عرض
            </button>
        </form>
    </div>

<?php if (!$hasState): ?>
    <!-- رسالة عند عدم حفظ أي فترة مسبقاً (ممنوع إظهار أي جدول هنا) -->
    <div class="prob-card" style="text-align:center;padding:48px 20px;color:var(--ink-500);font-size:15px;">
        <i class="ti ti-calendar" style="font-size:32px;display:block;margin-bottom:10px;color:var(--ink-400);"></i>
        اختار الفترة ودوس عرض
    </div>
<?php else: ?>

    <!-- شريط الفلترة والعدّاد فوق الجدول -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px;">
        <div class="filter-btn-group" style="margin-bottom:0;">
            <button type="button" class="filter-pill" id="toggleDistributedBtn" onclick="toggleDistributedFilter()">
                اخفي اللي اتوزّع (<?= $zeroDiffCount ?>)
            </button>
        </div>
        <div style="font-size:13px;color:var(--ink-600);font-weight:600;">
            <?= count($items) ?> صنف · <?= $diffCount ?> لسه فيه فرق
        </div>
    </div>

    <!-- جدول الأصناف -->
    <div class="prob-card" style="padding:0;overflow:hidden;">
        <div style="overflow-x:auto;">
            <table class="prob-table" id="undistTable">
                <thead>
                    <tr>
                        <th style="width:120px;">كود الصنف</th>
                        <th>اسم الصنف</th>
                        <th style="width:75px;text-align:center;">المشترى</th>
                        <th style="width:85px;text-align:center;">مردود للمورد</th>
                        <th style="width:95px;text-align:center;">موزّع على الفروع</th>
                        <th style="width:75px;text-align:center;">الفرق</th>
                        <th style="width:200px;">ملاحظات</th>
                        <th style="width:140px;">فواتير الشراء</th>
                        <th style="width:180px;">فواتير المردود</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="9" style="text-align:center;padding:32px;color:var(--ink-500);">
                            مفيش فواتير شراء أو مردود للإدارة في الفترة دي.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($items as $it): 
                        $diffVal = $it['diff'];
                        $isZero = ($diffVal == 0);
                        $diffColor = $diffVal > 0 ? 'var(--warn)' : ($isZero ? 'var(--pos)' : 'var(--neg)');
                    ?>
                    <tr data-code="<?= htmlspecialchars($it['item_code']) ?>" data-diff-zero="<?= $isZero ? '1' : '0' ?>">
                        <td><code><?= htmlspecialchars($it['item_code']) ?></code></td>
                        <td>
                            <div style="font-weight:600;"><?= htmlspecialchars($it['item_name']) ?></div>
                            <?php if ($it['qty_purchased'] == 0): ?>
                            <div style="font-size:11px;color:var(--warn);margin-top:2px;">
                                <i class="ti ti-alert-circle"></i> مالوش فاتورة شراء في الفترة دي — اشتراه قبلها، وسّع التاريخ
                            </div>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;"><?= ($it['qty_purchased'] + 0) ?></td>
                        <td style="text-align:center;"><?= ($it['qty_ret_vendor'] + 0) ?></td>
                        <td style="text-align:center;"><?= ($it['qty_dist_branch'] + 0) ?></td>
                        <td style="text-align:center;font-weight:bold;color:<?= $diffColor ?>;">
                            <?= ($diffVal + 0) ?>
                        </td>
                        <td>
                            <div style="display:flex;align-items:center;">
                                <input type="text"
                                       class="undist-note-input"
                                       value="<?= htmlspecialchars($it['note']) ?>"
                                       data-saved="<?= htmlspecialchars($it['note']) ?>"
                                       onblur="saveNote(this)">
                                <span class="save-ind"></span>
                            </div>
                        </td>
                        <td>
                            <div style="display:flex;flex-wrap:wrap;gap:4px;">
                            <?php foreach ($it['purchases'] as $p): ?>
                                <button type="button"
                                        class="btn btn-sm btn-ghost inv-pill-btn"
                                        onclick="openInvoiceModal(<?= $p['sth_id'] ?>, <?= htmlspecialchars(json_encode($p['suffix'], JSON_UNESCAPED_UNICODE)) ?>, <?= htmlspecialchars(json_encode($it['item_code'], JSON_UNESCAPED_UNICODE)) ?>)"
                                        title="عرض تفاصيل الفاتورة">
                                    <i class="ti ti-file-invoice"></i> <?= htmlspecialchars($p['doc_no']) ?>
                                </button>
                            <?php endforeach; ?>
                            </div>
                        </td>
                        <td>
                            <div style="display:flex;flex-wrap:wrap;gap:4px;">
                            <?php foreach ($it['returns'] as $r): ?>
                                <button type="button"
                                        class="btn btn-sm btn-ghost inv-pill-btn"
                                        onclick="openInvoiceModal(<?= $r['sth_id'] ?>, <?= htmlspecialchars(json_encode($r['suffix'], JSON_UNESCAPED_UNICODE)) ?>, <?= htmlspecialchars(json_encode($it['item_code'], JSON_UNESCAPED_UNICODE)) ?>)"
                                        title="عرض تفاصيل الفاتورة">
                                    <i class="ti ti-file-invoice"></i> <?= htmlspecialchars($r['label']) ?>
                                </button>
                            <?php endforeach; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- نافذة تفاصيل الفاتورة -->
    <div class="modal-bg" id="invoiceModal" onclick="handleModalBgClick(event)">
        <div class="modal" style="max-width:760px;max-height:85vh;display:flex;flex-direction:column;">
            <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--line-soft);padding-bottom:12px;margin-bottom:14px;">
                <div style="font-weight:700;font-size:15px;display:flex;align-items:center;gap:6px;">
                    <i class="ti ti-file-invoice"></i>
                    <span>تفاصيل الفاتورة</span>
                </div>
                <button type="button" class="btn btn-sm btn-ghost" onclick="closeInvoiceModal()" title="إغلاق">
                    <i class="ti ti-x"></i>
                </button>
            </div>
            <div id="modalMeta" style="display:flex;flex-wrap:wrap;gap:16px;margin-bottom:12px;font-size:12.5px;color:var(--ink-700);background:var(--line-soft);padding:10px 14px;border-radius:var(--r-sm);">
                <div><strong>رقم الفاتورة:</strong> <span id="modalDocNo">-</span></div>
                <div><strong>النوع:</strong> <span id="modalType">-</span></div>
                <div><strong>الطرف:</strong> <span id="modalSupplier">-</span></div>
                <div><strong>التاريخ:</strong> <span id="modalDate">-</span></div>
            </div>
            <div style="overflow-y:auto;flex:1;border:1px solid var(--line-soft);border-radius:var(--r-sm);" id="modalTableWrap">
                <table class="prob-table" style="margin-top:0;">
                    <thead style="position:sticky;top:0;z-index:2;">
                        <tr>
                            <th style="width:45px;text-align:center;">#</th>
                            <th style="width:130px;">كود الصنف</th>
                            <th>اسم الصنف</th>
                            <th style="width:75px;text-align:center;">الكمية</th>
                            <th style="width:85px;text-align:center;">السعر</th>
                        </tr>
                    </thead>
                    <tbody id="modalLinesBody">
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php endif; ?>

</div>

<script>
var SAVED_FROM  = <?= json_encode($savedFrom) ?>;
var SAVED_TO    = <?= json_encode($savedTo) ?>;
var NOTES_COUNT = <?= (int) $notesCount ?>;
var CSRF = <?= json_encode(csrf_token()) ?>;

// إرسال نموذج تحديد الفترة
document.getElementById('periodForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var from = document.getElementById('dateFrom').value.trim();
    var to   = document.getElementById('dateTo').value.trim();

    if (!from || !to) {
        if (typeof fgToast === 'function') {
            fgToast('اختار تاريخ البداية والنهاية', 'err');
        }
        return;
    }

    if (from > to) {
        if (typeof fgToast === 'function') {
            fgToast('تاريخ البداية بعد النهاية.', 'err');
        }
        return;
    }

    if (from === SAVED_FROM && to === SAVED_TO) {
        window.location.reload();
        return;
    }

    if (NOTES_COUNT > 0) {
        var msg = 'فيه ' + NOTES_COUNT + ' ملاحظة مكتوبة على الفترة الحالية، وهتتمسح كلها لو غيّرت الفترة. تكمّل؟';
        if (typeof fgConfirm === 'function') {
            fgConfirm(msg, { danger: true, okText: 'غيّر الفترة' }).then(function (ok) {
                if (ok) {
                    doSetPeriod(from, to, 1);
                }
            });
        } else {
            if (confirm(msg)) {
                doSetPeriod(from, to, 1);
            }
        }
    } else {
        doSetPeriod(from, to, 0);
    }
});

function doSetPeriod(from, to, confirm) {
    var fd = new FormData();
    fd.append('action', 'set_period');
    fd.append('from', from);
    fd.append('to', to);
    fd.append('confirm', confirm ? '1' : '0');
    fd.append('csrf', CSRF);

    fetch('undistributed.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.ok) {
                window.location.reload();
            } else {
                if (typeof fgToast === 'function') {
                    fgToast(d.error || 'حدث خطأ أثناء حفظ الفترة', 'err');
                } else {
                    alert(d.error || 'حدث خطأ');
                }
            }
        })
        .catch(function () {
            if (typeof fgToast === 'function') {
                fgToast('تعذر الاتصال بالسيرفر', 'err');
            } else {
                alert('تعذر الاتصال بالسيرفر');
            }
        });
}

// حفظ الملاحظة تلقائياً على حدث blur
function saveNote(input) {
    var row = input.closest('tr');
    if (!row) return;
    var code = row.dataset.code;
    var val  = input.value.trim();
    var prev = input.dataset.saved || '';

    if (val === prev) return;

    var ind = input.parentElement.querySelector('.save-ind');
    if (ind) {
        ind.innerHTML = '<i class="ti ti-clock"></i>';
        ind.style.color = 'var(--warn)';
    }

    var fd = new FormData();
    fd.append('action', 'save_note');
    fd.append('item_code', code);
    fd.append('note', val);
    fd.append('csrf', CSRF);

    fetch('undistributed.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.ok) {
                input.dataset.saved = val;
                if (ind) {
                    ind.innerHTML = '<i class="ti ti-check"></i>';
                    ind.style.color = 'var(--pos)';
                    setTimeout(function () { ind.innerHTML = ''; }, 1800);
                }
            } else {
                input.value = prev;
                if (ind) {
                    ind.innerHTML = '<i class="ti ti-x"></i>';
                    ind.style.color = 'var(--neg)';
                }
                if (typeof fgToast === 'function') {
                    fgToast(d.error || 'حدث خطأ في الحفظ', 'err');
                }
            }
        })
        .catch(function () {
            input.value = prev;
            if (ind) {
                ind.innerHTML = '<i class="ti ti-x"></i>';
                ind.style.color = 'var(--neg)';
            }
            if (typeof fgToast === 'function') {
                fgToast('تعذر الاتصال بالسيرفر', 'err');
            }
        });
}

// فلترة إخفاء/إظهار الأصناف التي تم توزيعها بالكامل
var hideDistributed = false;
function toggleDistributedFilter() {
    hideDistributed = !hideDistributed;
    var btn = document.getElementById('toggleDistributedBtn');
    if (!btn) return;
    var rows = document.querySelectorAll('#undistTable tbody tr[data-diff-zero="1"]');

    if (hideDistributed) {
        btn.classList.add('active');
        btn.textContent = 'اظهر الكل';
        rows.forEach(function (r) { r.style.display = 'none'; });
    } else {
        btn.classList.remove('active');
        btn.textContent = 'اخفي اللي اتوزّع (' + <?= (int) $zeroDiffCount ?> + ')';
        rows.forEach(function (r) { r.style.display = ''; });
    }
}

// نافذة تفاصيل الفاتورة
function openInvoiceModal(sth, suffix, highlightCode) {
    var modal = document.getElementById('invoiceModal');
    if (!modal) return;
    var body = document.getElementById('modalLinesBody');
    body.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:24px;color:var(--ink-500);"><i class="ti ti-clock"></i> جاري التحميل...</td></tr>';
    document.getElementById('modalDocNo').textContent = '...';
    document.getElementById('modalType').textContent = '...';
    document.getElementById('modalSupplier').textContent = '...';
    document.getElementById('modalDate').textContent = '...';

    modal.classList.add('open');

    fetch('undistributed.php?ajax=inv&sth=' + encodeURIComponent(sth) + '&suffix=' + encodeURIComponent(suffix))
        .then(function (r) {
            if (!r.ok) {
                return r.json().then(function (d) { throw new Error(d.error || 'فشل تحميل الفاتورة'); });
            }
            return r.json();
        })
        .then(function (d) {
            if (!d.ok) throw new Error(d.error || 'فشل تحميل الفاتورة');
            document.getElementById('modalDocNo').textContent    = d.header.doc_no || '-';
            document.getElementById('modalType').textContent     = d.header.type_label || '-';
            document.getElementById('modalSupplier').textContent = d.header.supplier_name || '-';
            document.getElementById('modalDate').textContent     = d.header.invoice_date || '-';

            body.innerHTML = '';
            var targetRow = null;

            (d.lines || []).forEach(function (ln, idx) {
                var tr = document.createElement('tr');
                var isTarget = (String(ln.item_code).trim() === String(highlightCode).trim());
                if (isTarget) {
                    tr.style.background = 'var(--accent-soft)';
                    targetRow = tr;
                }
                tr.innerHTML = 
                    '<td style="text-align:center;">' + (idx + 1) + '</td>' +
                    '<td><code>' + escapeHtml(ln.item_code) + '</code></td>' +
                    '<td>' + escapeHtml(ln.item_name) + '</td>' +
                    '<td style="text-align:center;font-weight:600;">' + (parseFloat(ln.quantity) || 0) + '</td>' +
                    '<td style="text-align:center;">' + (parseFloat(ln.unit_price) || 0) + '</td>';
                body.appendChild(tr);
            });

            if (targetRow) {
                setTimeout(function () {
                    targetRow.scrollIntoView({ block: 'center', behavior: 'smooth' });
                }, 80);
            }
        })
        .catch(function (err) {
            body.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--neg);padding:20px;"><i class="ti ti-alert-circle"></i> ' + escapeHtml(err.message) + '</td></tr>';
            if (typeof fgToast === 'function') {
                fgToast(err.message, 'err');
            }
        });
}

function closeInvoiceModal() {
    var modal = document.getElementById('invoiceModal');
    if (modal) modal.classList.remove('open');
}

function handleModalBgClick(e) {
    if (e.target.id === 'invoiceModal') {
        closeInvoiceModal();
    }
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        closeInvoiceModal();
    }
});

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
