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

require_once __DIR__ . '/undistributed_lib.php';

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

    $inv = undist_get_invoice($sth, $suffix);
    if (!$inv) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'الفاتورة مش موجودة'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => true] + $inv, JSON_UNESCAPED_UNICODE);
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
            $confirm = trim((string) ($_POST['confirm'] ?? '0')) === '1';

            undist_set_period($from, $to, $confirm, $uid > 0 ? $uid : null);
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'save_note') {
            $code = trim((string) ($_POST['item_code'] ?? ''));
            $note = trim((string) ($_POST['note'] ?? ''));

            undist_save_note($code, $note, $uid > 0 ? $uid : null);
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
$rep = undist_calculate_report();

$hasState         = $rep['has_state'];
$savedFrom        = $rep['saved_from'];
$savedTo          = $rep['saved_to'];
$defaultFrom      = $rep['default_from'];
$defaultTo        = $rep['default_to'];
$notesCount       = $rep['notes_count'];
$items            = $rep['items'];
$purchaseInvoices = $rep['purchase_invoices'];
$diffCount        = $rep['diff_count'];
$zeroDiffCount    = $rep['zero_diff_count'];
$mismatchCount    = $rep['mismatch_count'];
$lastBalanceSync  = $rep['last_balance_sync'];

$page_title    = 'الأصناف غير الموزّعة';
$active_module = 'invoices';
require_once __DIR__ . '/includes/header.php';
?>

<style>
/* الشكل كله من المكتبة الموحّدة — اللي هنا النوافذ المنبثقة وأعمدة الجدول بس */
.save-ind {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 16px;
    margin-right: 4px;
    font-size: var(--fs-base);
}
.filter-btn-group {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
    flex-wrap: wrap;
}
.inv-pill-btn {
    padding: 1px 6px;
    font-size: 11px;
    white-space: nowrap;
}
.ret-grp-lbl {
    min-width: 52px;
    font-size: 11px;
    color: var(--ink-500);
    font-weight: 600;
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
    border-radius: var(--r-lg);
    width: 100%;
    padding: 20px;
    box-shadow: var(--shadow);
    border: 1px solid var(--line);
    color: var(--text);
}
</style>

<div class="content-container">

    <div class="fpage-head">
        <div>
            <h1 class="fpage-title"><i class="ti ti-package-export"></i> الأصناف غير الموزّعة</h1>
        </div>
    </div>

    <!-- شريط اختيار وتحديد الفترة -->
    <section class="fcard">
        <form id="periodForm" class="ffilters">
            <div class="ffield">
                <label for="dateFrom">من</label>
                <input class="finput" type="date" id="dateFrom" name="from" value="<?= e($defaultFrom) ?>" required>
            </div>
            <div class="ffield">
                <label for="dateTo">إلى</label>
                <input class="finput" type="date" id="dateTo" name="to" value="<?= e($defaultTo) ?>" required>
            </div>
            <button type="submit" class="btn btn-primary" id="btnSubmitPeriod">
                <i class="ti ti-eye"></i> عرض
            </button>
        </form>
    </section>

<?php if (!$hasState): ?>
    <!-- رسالة عند عدم حفظ أي فترة مسبقاً (ممنوع إظهار أي جدول هنا) -->
    <div class="fcard" style="text-align:center;padding:48px 20px;color:var(--ink-500);font-size:15px;">
        <i class="ti ti-calendar" style="font-size:32px;display:block;margin-bottom:10px;color:var(--ink-400);"></i>
        اختار الفترة ودوس عرض
    </div>
<?php else: ?>

    <?php if ($lastBalanceSync): ?>
    <div style="font-size:12px;color:var(--ink-600);margin-bottom:8px;display:flex;align-items:center;gap:4px;">
        <i class="ti ti-clock"></i> الرصيد من آخر مزامنة: <?= htmlspecialchars($lastBalanceSync) ?>
    </div>
    <?php endif; ?>

    <!-- شريط الفلترة والعدّاد فوق الجدول -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px;">
        <div class="filter-btn-group" style="margin-bottom:0;">
            <button type="button" class="ftab" id="toggleDistributedBtn" onclick="toggleFilter('hideDistributed')">
                اخفي اللي اتوزّع (<?= $zeroDiffCount ?>)
            </button>
            <button type="button" class="ftab" id="toggleMismatchBtn" onclick="toggleFilter('onlyMismatch')">
                الرصيد مش مساوي (<?= $mismatchCount ?>)
            </button>
            <?php if (!empty($purchaseInvoices)): ?>
            <button type="button" class="ftab" id="invListBtn" onclick="openInvListModal()">
                <i class="ti ti-file-invoice"></i> <span id="invListBtnLbl">إجمالي الفواتير (<?= count($purchaseInvoices) ?>)</span>
            </button>
            <?php endif; ?>
        </div>
        <div style="font-size:13px;color:var(--ink-600);font-weight:600;">
            <?= count($items) ?> صنف · <?= $diffCount ?> لسه فيه فرق · <?= $mismatchCount ?> الرصيد مش مساوي
        </div>
    </div>

    <!-- جدول الأصناف -->
    <div class="ftable-wrap">
            <table class="ftable" id="undistTable">
                <thead>
                    <tr>
                        <th>اسم الصنف</th>
                        <th class="num" style="width:75px;">المشترى</th>
                        <th class="num" style="width:85px;">مردود للمورد</th>
                        <th class="num" style="width:95px;">موزّع على الفروع</th>
                        <th class="num" style="width:75px;">الفرق</th>
                        <th class="num" style="width:90px;">رصيد الإدارة</th>
                        <th style="width:140px;" data-xt-skip>ملاحظات</th>
                        <th style="width:110px;" data-xt-skip>فواتير الشراء</th>
                        <th style="width:220px;" data-xt-skip>فواتير المردود</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="9" class="fg-empty">
                            مفيش فواتير شراء أو مردود للإدارة في الفترة دي.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($items as $it): 
                        $diffVal = $it['diff'];
                        $isZero = ($diffVal == 0);
                        $diffColor = $diffVal > 0 ? 'var(--warn)' : ($isZero ? 'var(--pos)' : 'var(--neg)');
                    ?>
                    <tr data-code="<?= htmlspecialchars($it['item_code']) ?>"
                        data-diff-zero="<?= $isZero ? '1' : '0' ?>"
                        data-bal-mismatch="<?= !empty($it['bal_mismatch']) ? '1' : '0' ?>"
                        data-pinv="<?= htmlspecialchars(json_encode(array_map(fn($p) => $p['sth_id'] . '|' . $p['suffix'], $it['purchases']), JSON_UNESCAPED_UNICODE)) ?>">
                        <td>
                            <div style="font-weight:600;"><?= htmlspecialchars($it['item_name']) ?></div>
                            <div style="margin-top:2px;"><code style="font-size:11px;color:var(--ink-500);"><?= htmlspecialchars($it['item_code']) ?></code></div>
                            <?php if ($it['qty_purchased'] == 0): ?>
                            <div style="font-size:11px;color:var(--warn);margin-top:2px;">
                                <i class="ti ti-alert-circle"></i> مالوش فاتورة شراء في الفترة دي — اشتراه قبلها، وسّع التاريخ
                            </div>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= ($it['qty_purchased'] + 0) ?></td>
                        <td class="num"><?= ($it['qty_ret_vendor'] + 0) ?></td>
                        <td class="num"><?= ($it['qty_dist_branch'] + 0) ?></td>
                        <td class="num" style="font-weight:bold;color:<?= $diffColor ?>;">
                            <?= ($diffVal + 0) ?>
                        </td>
                        <td class="num">
                        <?php if ($it['item_id'] === null): ?>
                            <span style="color:var(--ink-400);">—</span>
                        <?php elseif ($it['is_shared']): ?>
                            <div style="font-weight:600;"><?= ($it['balance'] + 0) ?></div>
                            <div style="font-size:11px;color:var(--ink-500);margin-top:2px;white-space:nowrap;">كل الأكواد (<?= $it['shared_count'] ?>)</div>
                        <?php else: ?>
                            <?php if (!$it['bal_mismatch']): ?>
                                <span style="color:var(--pos);font-size:16px;" title="الرصيد <?= ($it['balance'] + 0) ?> = الفرق"><i class="ti ti-check"></i></span>
                                <span style="display:none;"><?= ($it['balance'] + 0) ?></span>
                            <?php else: ?>
                                <div style="font-weight:600;color:var(--neg);">
                                    <?= ($it['balance'] + 0) ?>
                                </div>
                                <div style="font-size:11px;color:var(--neg);margin-top:2px;">
                                    الفرق عن الرصيد: <?= ($it['bal_diff'] > 0 ? '+' : '') . ($it['bal_diff'] + 0) ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex;align-items:center;">
                                <input type="text"
                                       class="finput finput-sm"
                                       value="<?= htmlspecialchars($it['note']) ?>"
                                       data-saved="<?= htmlspecialchars($it['note']) ?>"
                                       title="<?= htmlspecialchars($it['note']) ?>"
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
                        <?php if (!empty($it['return_groups'])): ?>
                            <?php foreach ($it['return_groups'] as $grpName => $grpInvs): 
                                $isBack = str_starts_with($grpName, 'رجع من ');
                                $isVendor = ($grpName === 'مورد');
                                $lblColor = $isBack ? 'var(--pos)' : ($isVendor ? 'var(--warn)' : 'var(--ink-500)');
                            ?>
                            <div style="display:flex;align-items:center;gap:4px;flex-wrap:wrap;margin-bottom:3px;">
                                <span class="ret-grp-lbl" style="color:<?= $lblColor ?>;"><?= htmlspecialchars($grpName) ?></span>
                                <?php foreach ($grpInvs as $r): ?>
                                    <button type="button"
                                            class="btn btn-sm btn-ghost inv-pill-btn"
                                            <?= !empty($r['is_back']) ? 'style="color:var(--pos);font-weight:600;"' : '' ?>
                                            onclick="openInvoiceModal(<?= $r['sth_id'] ?>, <?= htmlspecialchars(json_encode($r['suffix'], JSON_UNESCAPED_UNICODE)) ?>, <?= htmlspecialchars(json_encode($it['item_code'], JSON_UNESCAPED_UNICODE)) ?>)"
                                            title="فاتورة <?= htmlspecialchars($r['doc_no'] . ' · ' . $r['grp']) ?>">
                                        <?= htmlspecialchars($r['doc_no']) ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
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
            <div class="ftable-wrap" style="overflow-y:auto;flex:1;border:1px solid var(--line-soft);border-radius:var(--r-sm);" id="modalTableWrap">
                <table class="ftable" style="margin-top:0;">
                    <thead style="position:sticky;top:0;z-index:2;">
                        <tr>
                            <th class="num" style="width:45px;">#</th>
                            <th style="width:130px;">كود الصنف</th>
                            <th>اسم الصنف</th>
                            <th class="num" style="width:75px;">الكمية</th>
                            <th class="num" style="width:85px;">السعر</th>
                        </tr>
                    </thead>
                    <tbody id="modalLinesBody">
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- نافذة إجمالي فواتير الشراء -->
    <div class="modal-bg" id="invListModal" onclick="handleModalBgClick(event)">
        <div class="modal" style="max-width:640px;max-height:85vh;display:flex;flex-direction:column;">
            <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--line-soft);padding-bottom:12px;margin-bottom:14px;">
                <div>
                    <div style="font-weight:700;font-size:15px;display:flex;align-items:center;gap:6px;">
                        <i class="ti ti-file-invoice"></i>
                        <span>إجمالي فواتير الشراء</span>
                    </div>
                    <div style="font-size:12px;color:var(--ink-500);margin-top:2px;">
                        <?= count($purchaseInvoices) ?> فاتورة · دوس على فاتورة تفلتر الأصناف بتاعتها
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-ghost" onclick="closeInvListModal()" title="إغلاق">
                    <i class="ti ti-x"></i>
                </button>
            </div>
            <div class="ftable-wrap" style="overflow-y:auto;flex:1;border:1px solid var(--line-soft);border-radius:var(--r-sm);">
                <table class="ftable" style="margin-top:0;">
                    <thead style="position:sticky;top:0;z-index:2;">
                        <tr>
                            <th style="width:110px;">رقم الفاتورة</th>
                            <th>المورد</th>
                            <th class="num" style="width:105px;">التاريخ</th>
                            <th class="num" style="width:90px;">عدد الأصناف</th>
                        </tr>
                    </thead>
                    <tbody id="invListBody">
                        <tr style="cursor:pointer;" data-key="" onclick="setInvFilter('')">
                            <td colspan="4" style="text-align:center;font-weight:600;color:var(--accent-text);padding:10px;">
                                كل الأصناف — شيل فلتر الفاتورة
                            </td>
                        </tr>
                        <?php foreach ($purchaseInvoices as $pi): ?>
                        <tr style="cursor:pointer;"
                            data-key="<?= htmlspecialchars($pi['key']) ?>"
                            data-doc="<?= htmlspecialchars($pi['doc_no']) ?>"
                            data-count="<?= (int) $pi['count'] ?>"
                            onclick="setInvFilter(this.dataset.key)">
                            <td style="font-weight:600;">
                                <i class="ti ti-file-invoice"></i> <?= htmlspecialchars($pi['doc_no']) ?>
                            </td>
                            <td><?= htmlspecialchars($pi['supplier_name'] !== '' ? $pi['supplier_name'] : '—') ?></td>
                            <td class="num"><?= htmlspecialchars(substr($pi['invoice_date'], 0, 10)) ?></td>
                            <td class="num" style="font-weight:700;"><?= (int) $pi['count'] ?></td>
                        </tr>
                        <?php endforeach; ?>
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

// فلترة الجدول
var filters = {
    hideDistributed: false,
    onlyMismatch: false,
    invKey: ''
};

function toggleFilter(filterName) {
    filters[filterName] = !filters[filterName];

    var btnDist = document.getElementById('toggleDistributedBtn');
    if (btnDist) {
        if (filters.hideDistributed) {
            btnDist.classList.add('active');
            btnDist.textContent = 'اظهر الكل';
        } else {
            btnDist.classList.remove('active');
            btnDist.textContent = 'اخفي اللي اتوزّع (' + <?= (int) $zeroDiffCount ?> + ')';
        }
    }

    var btnMis = document.getElementById('toggleMismatchBtn');
    if (btnMis) {
        if (filters.onlyMismatch) {
            btnMis.classList.add('active');
            btnMis.textContent = 'اظهر الكل';
        } else {
            btnMis.classList.remove('active');
            btnMis.textContent = 'الرصيد مش مساوي (' + <?= (int) $mismatchCount ?> + ')';
        }
    }

    applyFilters();
}

function applyFilters() {
    var rows = document.querySelectorAll('#undistTable tbody tr[data-code]');
    rows.forEach(function (r) {
        var isZeroDiff = (r.getAttribute('data-diff-zero') === '1');
        var isMismatch = (r.getAttribute('data-bal-mismatch') === '1');

        var hide = false;
        if (filters.hideDistributed && isZeroDiff) {
            hide = true;
        }
        if (filters.onlyMismatch && !isMismatch) {
            hide = true;
        }
        if (filters.invKey) {
            var keys = JSON.parse(r.getAttribute('data-pinv') || '[]');
            if (keys.indexOf(filters.invKey) === -1) {
                hide = true;
            }
        }

        r.style.display = hide ? 'none' : '';
    });
}

function openInvListModal() {
    var modal = document.getElementById('invListModal');
    if (modal) modal.classList.add('open');
}

function closeInvListModal() {
    var modal = document.getElementById('invListModal');
    if (modal) modal.classList.remove('open');
}

function setInvFilter(key) {
    filters.invKey = key || '';

    var activeDoc = '';
    var activeCount = '';
    var rows = document.querySelectorAll('#invListBody tr[data-key]');
    rows.forEach(function (r) {
        if (r.dataset.key === filters.invKey) {
            r.style.background = 'var(--accent-soft)';
            activeDoc = r.dataset.doc || '';
            activeCount = r.dataset.count || '';
        } else {
            r.style.background = '';
        }
    });

    closeInvListModal();
    applyFilters();

    var btn = document.getElementById('invListBtn');
    var lbl = document.getElementById('invListBtnLbl');
    if (btn && lbl) {
        if (filters.invKey) {
            btn.classList.add('active');
            lbl.textContent = 'فاتورة ' + activeDoc + ' (' + activeCount + ')';
        } else {
            btn.classList.remove('active');
            lbl.textContent = 'إجمالي الفواتير (' + <?= (int) count($purchaseInvoices) ?> + ')';
        }
    }

    var table = document.getElementById('undistTable');
    if (table) {
        table.scrollIntoView({ block: 'start', behavior: 'smooth' });
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
    if (e.target.classList.contains('modal-bg')) {
        e.target.classList.remove('open');
    }
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        closeInvoiceModal();
        closeInvListModal();
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
