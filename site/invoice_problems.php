<?php
/**
 * invoice_problems.php — صفحة مشاكل الفواتير
 * نظام الفجالة ستور المالي
 *
 * الغرض:
 * تجميع كل الأصناف التي بها مشاكل أو فروقات في مكان واحد:
 * 1. تلقائي: من المردودات (doc_type = 6 لنفس المورد خلال 90 يوم من تاريخ الشراء).
 *    مثال محمد بالحرف: قلم روتو في الفاتورة 12، اللي جه 10، المردود 2.
 *    العمود short_qty = الناقص (12 - 10 = 2)، مش 10 ومش 12.
 * 2. يدوي: من خانة ملاحظات الأصناف أو الإضافة المباشرة داخل هذه الصفحة.
 *
 * القواعد:
 * - الحفظ التلقائي لكل خانة على حدث blur فوري ومباشر.
 * - إذا كان الصنف مسجلاً مسبقاً بـ source = 'manual'، التحديث التلقائي يحدّث الكميات فقط
 *   ولا يمس note ولا source.
 * - الاستعلامات مجمّعة دائماً (ممنوع أي prepare أو استعلام داخل حلقة).
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_permission('invoices:problems');
require_once __DIR__ . '/receiving_lib.php';
require_once __DIR__ . '/invoice_problems_lib.php';

$pdo = db();
$user = current_user();
$uid = (int) ($user['id'] ?? 0);

// =========================================================================
//  معالجة طلبات POST (حفظ الخانات تلقائياً على blur وحذف المشاكل)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!csrf_verify($_POST['csrf'] ?? '')) {
            throw new RuntimeException('انتهت صلاحية الجلسة، اعمل تحديث للصفحة.');
        }

        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'save_problem_field') {
            $res = ip_save_problem_field(
                $pdo,
                $uid,
                (int) ($_POST['distribution_id'] ?? 0),
                trim((string) ($_POST['item_code'] ?? '')),
                trim((string) ($_POST['item_name'] ?? '')),
                (string) ($_POST['field'] ?? ''),
                trim((string) ($_POST['value'] ?? ''))
            );
            echo json_encode($res, JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'delete_problem') {
            $res = ip_delete_problem(
                $pdo,
                (int) ($_POST['distribution_id'] ?? 0),
                trim((string) ($_POST['item_code'] ?? ''))
            );
            echo json_encode($res, JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'recalc_all') {
            if (!is_owner()) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'هذا الإجراء متاح للمالك فقط.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            sync_all_invoice_problems_auto($pdo);
            $lastRecalc = ip_last_recalc_time($pdo);
            if (isset($_POST['format']) && $_POST['format'] === 'json' || (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))) {
                echo json_encode(['ok' => true, 'message' => 'تمت إعادة الحساب بنجاح.', 'last_recalc' => $lastRecalc], JSON_UNESCAPED_UNICODE);
                exit;
            }
            header('Location: invoice_problems.php' . (isset($_GET['d']) ? '?d=' . (int)$_GET['d'] : ''));
            exit;
        }

        if ($action === 'delete_invoice_problems') {
            $res = ip_delete_invoice_problems($pdo, (int) ($_POST['distribution_id'] ?? 0));
            echo json_encode($res, JSON_UNESCAPED_UNICODE);
            exit;
        }

        throw new RuntimeException('إجراء غير معروف.');
    } catch (Throwable $ex) {
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// =========================================================================
//  دوال الحساب التلقائي المجمّع للمردودات
// =========================================================================
// 🔴 اتنقلت كلها لـinvoice_problems_lib.php (بريف ١٥٩) — الصفحة بتناديها
//    من هنا بنفس الشغل بالظبط، ومفيش نسخة تانية في الصفحة (وإلا: Cannot redeclare).

$selectedDistId = isset($_GET['d']) ? (int) $_GET['d'] : 0;
$lastRecalcTime = ip_last_recalc_time($pdo);

$page_title    = 'مشاكل الفواتير';
$active_module = 'invoices';
require_once __DIR__ . '/includes/header.php';
?>

<style>
/* override محلي (بريف ٤٣): الزرار الموحّد في fagala.css، والصفحة دي مختلفة في دول بس */
.btn { padding: 8px 16px; font-size: 13.5px; font-weight: 600; border: 1px solid transparent; }
/* لازم يفضل هنا: .btn اللي فوقه بييجي بعد fagala.css فبيغلب .btn-sm بتاعها */
.btn-sm {
    padding: 4px 10px;
    font-size: 12px;
}

.prob-card {
    margin-bottom: 16px;
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
.prob-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid var(--line-soft);
    padding-bottom: 12px;
    margin-bottom: 14px;
    flex-wrap: wrap;
    gap: 10px;
}
.prob-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
    font-size: 13px;
}
.prob-table th, .prob-table td {
    padding: 8px 12px;
    border-bottom: 1px solid var(--line-soft);
    text-align: right;
    vertical-align: middle;
}
.prob-table th {
    background: var(--line-soft);
    color: var(--ink-700);
    font-weight: 600;
}
.prob-table tbody tr.inv-row {
    cursor: pointer;
    transition: background 0.12s ease;
}
.prob-table tbody tr.inv-row:hover {
    background: var(--accent-soft);
}
.prob-input {
    width: 100%;
    padding: 6px 8px;
    border: 1px solid var(--line);
    border-radius: var(--r-sm);
    font-size: 13px;
    background: var(--surface);
    color: var(--text);
    box-sizing: border-box;
}
.prob-input:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 2px var(--accent-soft);
}
.prob-input-num {
    text-align: center;
    max-width: 90px;
}
.save-ind {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 16px;
    margin-right: 4px;
    font-size: 13px;
}
.badge-src {
    display: inline-block;
    padding: 2px 7px;
    border-radius: var(--r-sm);
    font-size: 11px;
    font-weight: 600;
}
.badge-auto { background: var(--warn-soft); color: var(--warn); }
.badge-manual { background: var(--accent-soft); color: var(--accent-text); }
.filter-btn-group {
    display: flex;
    gap: 6px;
    margin-bottom: 12px;
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
</style>

<div class="content-container">

<?php if ($selectedDistId <= 0): ?>
    <!-- ===================================================================== -->
    <!--  عرض القائمة العامة: الفواتير التي بها مشاكل                         -->
    <!-- ===================================================================== -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
        <div>
            <h2 style="font-size:20px;font-weight:bold;margin:0;"><i class="ti ti-alert-triangle"></i> مشاكل الفواتير</h2>
            <span style="font-size:12.5px;color:var(--ink-400);">الأصناف التي بها فروقات في الاستلام (عجز/زيادة) أو ملاحظات</span>
            <?php if (!empty($lastRecalcTime)): ?>
                <div style="font-size:11.5px;color:var(--ink-400);margin-top:2px;">
                    <i class="ti ti-clock"></i> آخر إعادة حساب: <?= e($lastRecalcTime) ?>
                </div>
            <?php endif; ?>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            <?php if (is_owner()): ?>
                <form method="POST" action="invoice_problems.php" style="margin:0;" onsubmit="return confirm('إعادة حساب ومزامنة مشاكل جميع فواتير الشراء؟');">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="recalc_all">
                    <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--accent-text);border:1px solid var(--line);">
                        <i class="ti ti-refresh"></i> إعادة حساب الكل
                    </button>
                </form>
            <?php endif; ?>
            <a href="invoices.php" class="btn btn-ghost btn-sm">← الرجوع للاستلامات</a>
        </div>
    </div>

    <?php
    // الاستعلامات دي اتنقلت لinvoice_problems_lib.php (بريف ١٥٩) — الصفحة
    // والـAPI بينادوا نفس الدوال، فمفيش نسخة تانية من الـSQL في أي ملف.
    $allInvoices = ip_all_invoices($pdo);
    $problemInvoices = ip_problem_invoices($pdo);
    ?>

    <!-- شريط اختيار فاتورة لفتحها وإضافة مشاكل لها -->
    <div class="card prob-card" style="padding:14px 18px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <div style="font-size:14px;font-weight:700;color:var(--ink-900);margin-bottom:3px;display:flex;align-items:center;gap:6px;">
                <i class="ti ti-file-invoice" style="color:var(--accent-text);"></i> فتح فاتورة معينة لعرض أصنافها أو إضافة مشاكل
            </div>
            <div style="font-size:12.5px;color:var(--ink-500);">
                اختر أي فاتورة مشتريات للإدارة للتحقق من كمياتها، أصنافها، أو تسجيل عجز/زيادة وملاحظات
            </div>
        </div>
        <button type="button" class="btn" onclick="openInvoicePickerModal()">
            <i class="ti ti-search"></i> اختيار فاتورة مشتريات...
        </button>
    </div>

    <!-- مودال اختيار فاتورة مشتريات مع بحث فوري -->
    <div class="modal-bg" id="invPickModal" onclick="if(event.target===this)closeInvoicePickerModal()">
        <div class="modal" style="max-width:700px;display:flex;flex-direction:column;max-height:85vh;">
            <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--line);padding-bottom:12px;margin-bottom:14px;">
                <div style="font-size:16px;font-weight:700;color:var(--ink-900);display:flex;align-items:center;gap:8px;">
                    <i class="ti ti-file-invoice" style="color:var(--accent-text);"></i> اختيار فاتورة مشتريات
                </div>
                <button type="button" class="btn-ghost btn-sm" onclick="closeInvoicePickerModal()" style="border:none;font-size:16px;cursor:pointer;padding:4px 8px;" title="إغلاق">
                    <i class="ti ti-x"></i>
                </button>
            </div>
            
            <div style="margin-bottom:12px;">
                <input type="text" id="invSearchInput" oninput="filterInvoicesList()" placeholder="ابحث برقم الفاتورة أو اسم المورد..."
                       style="width:100%;padding:10px 14px;border:1px solid var(--line);border-radius:var(--r);font-size:13.5px;background:var(--surface);color:var(--text);box-sizing:border-box;">
            </div>

            <div style="overflow-y:auto;flex:1;border:1px solid var(--line-soft);border-radius:var(--r);min-height:220px;max-height:50vh;">
                <table class="prob-table" id="invPickTable" style="margin:0;">
                    <thead style="position:sticky;top:0;z-index:1;">
                        <tr>
                            <th style="width:110px;">رقم الفاتورة</th>
                            <th>المورد</th>
                            <th style="width:110px;">التاريخ</th>
                            <th style="width:70px;text-align:center;">إجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allInvoices as $ai): ?>
                            <tr class="inv-picker-row" 
                                data-inv="<?= e((string)$ai['invoice_no']) ?>" 
                                data-supplier="<?= e(mb_strtolower((string)$ai['supplier_name'])) ?>"
                                style="cursor:pointer;"
                                onclick="location.href='invoice_problems.php?d=<?= (int)$ai['id'] ?>'">
                                <td><b>#<?= e((string)$ai['invoice_no']) ?></b></td>
                                <td><?= e((string)$ai['supplier_name']) ?></td>
                                <td style="font-size:12px;color:var(--ink-500);"><?= e((string)$ai['dist_date']) ?></td>
                                <td style="text-align:center;">
                                    <a href="invoice_problems.php?d=<?= (int)$ai['id'] ?>" class="btn btn-ghost btn-sm" style="padding:3px 8px;" onclick="event.stopPropagation();">
                                        فتح
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($allInvoices)): ?>
                            <tr>
                                <td colspan="4" style="text-align:center;padding:24px;color:var(--ink-400);">لا توجد فواتير مشتريات متاحة</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div style="margin-top:14px;display:flex;justify-content:space-between;align-items:center;">
                <span id="invCountText" style="font-size:12.5px;color:var(--ink-500);">إجمالي: <?= count($allInvoices) ?> فاتورة</span>
                <button type="button" class="btn btn-ghost" onclick="closeInvoicePickerModal()">إغلاق</button>
            </div>
        </div>
    </div>

    <?php if (empty($problemInvoices)): ?>
        <div class="card prob-card" style="text-align:center;padding:40px 20px;color:var(--ink-400);">
            <i class="ti ti-circle-check" style="font-size:38px;color:var(--pos);display:block;margin-bottom:8px;"></i>
            لا توجد مشاكل أو فروقات مسجلة في فواتير الإدارة حالياً.
        </div>
    <?php else: ?>
        <div class="card prob-card">
            <h3 style="margin:0 0 12px 0;font-size:15px;">قائمة فواتير الإدارة التي بها مشاكل (<?= count($problemInvoices) ?> فاتورة)</h3>
            <table class="prob-table">
                <thead>
                    <tr>
                        <th style="width:100px;">رقم الفاتورة</th>
                        <th>اسم المورد</th>
                        <th style="width:120px;">تاريخ الفاتورة</th>
                        <th style="width:150px;">مين دخّلها</th>
                        <th style="width:120px;">الأصناف المتأثرة</th>
                        <th style="width:60px;text-align:center;">إجراء</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($problemInvoices as $pi):
                        $dUrl = 'invoice_problems.php?d=' . (int)$pi['dist_id'];
                    ?>
                        <tr class="inv-row" tabindex="0" role="link"
                            onclick="location.href='<?= $dUrl ?>'"
                            onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();location.href='<?= $dUrl ?>';}">
                            <td><b>#<?= e((string)$pi['invoice_no']) ?></b></td>
                            <td><?= e((string)$pi['supplier_name']) ?></td>
                            <td><?= e((string)$pi['dist_date']) ?></td>
                            <td><?= e((string)($pi['entered_by'] ?: 'غير معروف')) ?></td>
                            <td>
                                <span style="font-weight:bold;color:var(--neg);"><?= (int)$pi['problems_count'] ?> صنف</span>
                                <?php if ($pi['short_count'] > 0): ?>
                                    <span style="font-size:11px;color:var(--neg);">(<?= (int)$pi['short_count'] ?> عجز)</span>
                                <?php endif; ?>
                                <?php if ($pi['over_count'] > 0): ?>
                                    <span style="font-size:11px;color:var(--accent-text);">(<?= (int)$pi['over_count'] ?> زيادة)</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;">
                                <button type="button" class="btn btn-ghost btn-sm" style="color:var(--neg);padding:4px 8px;"
                                        title="حذف كل مشاكل هذه الفاتورة"
                                        onclick="event.stopPropagation(); deleteInvoiceProblems(<?= (int)$pi['dist_id'] ?>, this)">
                                    <i class="ti ti-trash"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <script>
    var CSRF = <?= json_encode(csrf_token()) ?>;

    function openInvoicePickerModal() {
        var m = document.getElementById('invPickModal');
        if (m) {
            m.classList.add('open');
            var inp = document.getElementById('invSearchInput');
            if (inp) {
                inp.value = '';
                filterInvoicesList();
                setTimeout(function() { inp.focus(); }, 80);
            }
        }
    }

    function closeInvoicePickerModal() {
        var m = document.getElementById('invPickModal');
        if (m) m.classList.remove('open');
    }

    function filterInvoicesList() {
        var inp = document.getElementById('invSearchInput');
        var q = (inp ? inp.value : '').trim().toLowerCase();
        var rows = document.querySelectorAll('#invPickTable tbody tr.inv-picker-row');
        var visible = 0;
        rows.forEach(function(r) {
            var inv = (r.getAttribute('data-inv') || '').toLowerCase();
            var sup = (r.getAttribute('data-supplier') || '').toLowerCase();
            if (!q || inv.indexOf(q) !== -1 || sup.indexOf(q) !== -1) {
                r.style.display = '';
                visible++;
            } else {
                r.style.display = 'none';
            }
        });
        var cnt = document.getElementById('invCountText');
        if (cnt) cnt.textContent = 'المعروض: ' + visible + ' فاتورة';
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeInvoicePickerModal();
    });

    function deleteInvoiceProblems(distId, btn) {
        if (!confirm('هل أنت متأكد من حذف هذه الفاتورة من قائمة المشاكل؟')) return;
        var fd = new FormData();
        fd.append('action', 'delete_invoice_problems');
        fd.append('csrf', CSRF);
        fd.append('distribution_id', distId);

        fetch('invoice_problems.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.ok) {
                    var tr = btn.closest('tr');
                    if (tr) tr.remove();
                } else {
                    fgToast(d.error || 'حدث خطأ أثناء الحذف', 'err');
                }
            })
            .catch(() => fgToast('تعذر الاتصال بالسيرفر', 'err'));
    }
    </script>

<?php else: ?>
    <!-- ===================================================================== -->
    <!--  عرض أصناف فاتورة محددة (?d=<distribution_id>)                         -->
    <!-- ===================================================================== -->
    <?php
    // الاستعلامات دي اتنقلت لinvoice_problems_lib.php (بريف ١٥٩) — نفس الدوال
    // اللي الـAPI بيستخدمها، فمفيش نسخة تانية من الـSQL في أي ملف.
    $distHeader = ip_dist_header($pdo, $selectedDistId);

    if (!$distHeader):
    ?>
        <div class="card prob-card" style="text-align:center;color:var(--neg);padding:30px;">
            <i class="ti ti-alert-triangle"></i> الفاتورة المطلوبة غير موجودة أو ليست من فواتير مشتريات الإدارة.
            <div style="margin-top:12px;"><a href="invoice_problems.php" class="btn btn-ghost btn-sm">← الرجوع للقائمة</a></div>
        </div>
    <?php
    else:
        $itemsList = ip_invoice_items($pdo, $distHeader);
        $problemsOnlyCount = count(array_filter($itemsList, fn($it) => $it['has_problem']));
    ?>
        <div class="card prob-card">
            <div class="prob-header">
                <div>
                    <h2 style="margin:0 0 6px 0;font-size:18px;">
                        فاتورة #<?= e((string)$distHeader['invoice_no']) ?> — <?= e((string)$distHeader['supplier_name']) ?>
                    </h2>
                    <div style="font-size:12.5px;color:var(--ink-500);display:flex;gap:14px;flex-wrap:wrap;">
                        <span><b>تاريخ الفاتورة:</b> <?= e((string)$distHeader['dist_date']) ?></span>
                        <span><b>مين دخلها:</b> <?= e((string)($distHeader['entered_by'] ?: 'غير معروف')) ?></span>
                        <span><b>أصناف المشاكل:</b> <b style="color:var(--neg);"><?= $problemsOnlyCount ?></b> من <?= count($itemsList) ?> صنف</span>
                    </div>
                </div>
                <div style="display:flex;gap:8px;align-items:center;">
                    <button type="button" class="btn btn-ghost btn-sm" style="color:var(--neg);"
                            title="حذف كل مشاكل هذه الفاتورة"
                            onclick="deleteCurrentInvoiceProblems(<?= (int)$selectedDistId ?>)">
                        <i class="ti ti-trash"></i> حذف مشاكل الفاتورة
                    </button>
                    <a href="invoice_problems.php" class="btn btn-ghost btn-sm">← رجوع لكل الفواتير</a>
                </div>
            </div>

            <!-- أزرار الفلترة بين عرض المشاكل فقط وعرض كل الأصناف -->
            <div class="filter-btn-group">
                <button type="button" class="filter-pill active" onclick="setProblemsFilter('problems', this)">
                    أصناف المشاكل فقط (<?= $problemsOnlyCount ?>)
                </button>
                <button type="button" class="filter-pill" onclick="setProblemsFilter('all', this)">
                    كل أصناف الفاتورة (<?= count($itemsList) ?>)
                </button>
            </div>

            <table class="prob-table" id="itemsTable">
                <thead>
                    <tr>
                        <th style="width:140px;">كود الصنف</th>
                        <th>اسم الصنف</th>
                        <th style="width:85px;text-align:center;">كمية الفاتورة</th>
                        <th style="width:110px;text-align:center;">الناقص</th>
                        <th style="width:110px;text-align:center;">الزيادة</th>
                        <th>الملاحظة</th>
                        <th style="width:80px;text-align:center;">المصدر</th>
                        <th style="width:130px;">التسجيل</th>
                        <th style="width:70px;text-align:center;">إجراء</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($itemsList as $it):
                        $rowClass = $it['has_problem'] ? 'prob-row is-problem' : 'prob-row not-problem';
                        $rowStyle = $it['has_problem'] ? '' : 'display:none;';
                    ?>
                        <tr class="<?= $rowClass ?>" style="<?= $rowStyle ?>"
                            data-code="<?= e((string)$it['code']) ?>"
                            data-name="<?= e((string)$it['name']) ?>"
                            data-has-problem="<?= $it['has_problem'] ? '1' : '0' ?>">
                            <td><code><?= e((string)$it['code']) ?></code></td>
                            <td><b><?= e((string)$it['name']) ?></b></td>
                            <td style="text-align:center;color:var(--ink-500);"><?= (float)$it['inv_qty'] ?></td>
                            <td style="text-align:center;">
                                <div style="display:flex;align-items:center;justify-content:center;">
                                    <input type="number" step="any" min="0"
                                           class="prob-input prob-input-num input-short"
                                           value="<?= $it['short_qty'] > 0 ? (float)$it['short_qty'] : '' ?>"
                                           placeholder="0"
                                           data-saved="<?= $it['short_qty'] > 0 ? (float)$it['short_qty'] : '' ?>"
                                           onblur="saveField(this, 'short_qty')">
                                    <span class="save-ind"></span>
                                </div>
                            </td>
                            <td style="text-align:center;">
                                <div style="display:flex;align-items:center;justify-content:center;">
                                    <input type="number" step="any" min="0"
                                           class="prob-input prob-input-num input-over"
                                           value="<?= $it['over_qty'] > 0 ? (float)$it['over_qty'] : '' ?>"
                                           placeholder="0"
                                           data-saved="<?= $it['over_qty'] > 0 ? (float)$it['over_qty'] : '' ?>"
                                           onblur="saveField(this, 'over_qty')">
                                    <span class="save-ind"></span>
                                </div>
                            </td>
                            <td>
                                <div style="display:flex;align-items:center;">
                                    <input type="text"
                                           class="prob-input input-note"
                                           value="<?= e((string)$it['note']) ?>"
                                           placeholder="اكتب ملاحظة المشكلة…"
                                           data-saved="<?= e((string)$it['note']) ?>"
                                           onblur="saveField(this, 'note')">
                                    <span class="save-ind"></span>
                                </div>
                            </td>
                            <td style="text-align:center;">
                                <?php if ($it['source'] === 'auto'): ?>
                                    <span class="badge-src badge-auto src-badge">تلقائي</span>
                                <?php elseif ($it['source'] === 'manual'): ?>
                                    <span class="badge-src badge-manual src-badge">يدوي</span>
                                <?php else: ?>
                                    <span class="badge-src src-badge" style="color:var(--ink-400);">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:11.5px;color:var(--ink-500);">
                                <?php if ($it['recorder']): ?>
                                    <div><?= e((string)$it['recorder']) ?></div>
                                    <div style="font-size:10.5px;color:var(--ink-400);"><?= e((string)date('m-d H:i', strtotime((string)$it['time']))) ?></div>
                                <?php else: ?>
                                    <span style="color:var(--ink-400);">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;">
                                <button type="button" class="btn btn-ghost btn-sm btn-del-item"
                                        style="color:var(--neg);padding:2px 6px;<?= $it['has_problem'] ? '' : 'display:none;' ?>"
                                        title="حذف مشكلة هذا الصنف"
                                        onclick="deleteItemProblem(this, '<?= e((string)$it['code']) ?>')">
                                    <i class="ti ti-trash"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <script>
        var CSRF = <?= json_encode(csrf_token()) ?>;
        var DIST_ID = <?= (int)$selectedDistId ?>;
        var currentFilter = 'problems';

        function setProblemsFilter(mode, btn) {
            currentFilter = mode;
            document.querySelectorAll('.filter-pill').forEach(b => b.classList.remove('active'));
            if (btn) btn.classList.add('active');

            var rows = document.querySelectorAll('tr.prob-row');
            rows.forEach(r => {
                if (mode === 'all') {
                    r.style.display = '';
                } else {
                    r.style.display = (r.dataset.hasProblem === '1') ? '' : 'none';
                }
            });
        }

        function saveField(input, fieldName) {
            var row = input.closest('tr');
            if (!row) return;
            var code = row.dataset.code;
            var name = row.dataset.name || '';
            var val  = input.value.trim();
            var prev = input.dataset.saved || '';

            if (val === prev) return; // مفيش تعديل

            var ind = input.parentElement.querySelector('.save-ind');
            if (ind) { ind.innerHTML = '<i class="ti ti-clock"></i>'; ind.style.color = 'var(--warn)'; }

            var fd = new FormData();
            fd.append('action', 'save_problem_field');
            fd.append('csrf', CSRF);
            fd.append('distribution_id', DIST_ID);
            fd.append('item_code', code);
            fd.append('item_name', name);
            fd.append('field', fieldName);
            fd.append('value', val);

            fetch('invoice_problems.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if (d.ok) {
                        input.dataset.saved = val;
                        if (ind) {
                            ind.innerHTML = '<i class="ti ti-check"></i>';
                            ind.style.color = 'var(--pos)';
                            setTimeout(function(){ ind.innerHTML = ''; }, 1800);
                        }
                        if (d.source) {
                            var srcBadge = row.querySelector('.src-badge');
                            if (srcBadge) {
                                srcBadge.textContent = (d.source === 'auto') ? 'تلقائي' : 'يدوي';
                                srcBadge.className = 'badge-src badge-' + d.source + ' src-badge';
                            }
                        }

                        // فحص هل الصف أصبح مشكلة أم لا
                        var sq = parseFloat(row.querySelector('.input-short').value) || 0;
                        var oq = parseFloat(row.querySelector('.input-over').value) || 0;
                        var nt = row.querySelector('.input-note').value.trim();
                        var hasProb = (sq > 0 || oq > 0 || nt !== '');
                        row.dataset.hasProblem = hasProb ? '1' : '0';
                        if (hasProb) {
                            row.classList.add('is-problem');
                            row.classList.remove('not-problem');
                        } else {
                            row.classList.remove('is-problem');
                            row.classList.add('not-problem');
                            if (currentFilter === 'problems') {
                                row.style.display = 'none';
                            }
                        }
                    } else {
                        input.value = prev;
                        if (ind) { ind.innerHTML = '<i class="ti ti-x"></i>'; ind.style.color = 'var(--neg)'; }
                        fgToast(d.error || 'حدث خطأ في الحفظ', 'err');
                    }
                })
                .catch(function () {
                    input.value = prev;
                    if (ind) { ind.innerHTML = '<i class="ti ti-x"></i>'; ind.style.color = 'var(--neg)'; }
                    fgToast('تعذر الاتصال بالسيرفر', 'err');
                });
        }

        function deleteItemProblem(btn, code) {
            if (!confirm('هل أنت متأكد من حذف مشكلة هذا الصنف؟')) return;
            var row = btn.closest('tr');
            if (!row) return;

            var fd = new FormData();
            fd.append('action', 'delete_problem');
            fd.append('csrf', CSRF);
            fd.append('distribution_id', DIST_ID);
            fd.append('item_code', code);

            fetch('invoice_problems.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if (d.ok) {
                        // تصفير الخانات
                        var inS = row.querySelector('.input-short');
                        var inO = row.querySelector('.input-over');
                        var inN = row.querySelector('.input-note');
                        if (inS) { inS.value = ''; inS.dataset.saved = ''; }
                        if (inO) { inO.value = ''; inO.dataset.saved = ''; }
                        if (inN) { inN.value = ''; inN.dataset.saved = ''; }
                        var ind = row.querySelector('.save-ind');
                        if (ind) ind.innerHTML = '';
                        var srcBadge = row.querySelector('.src-badge');
                        if (srcBadge) {
                            srcBadge.textContent = '—';
                            srcBadge.className = 'badge-src src-badge';
                            srcBadge.style.color = 'var(--ink-400)';
                        }
                        btn.style.display = 'none';

                        row.dataset.hasProblem = '0';
                        row.classList.remove('is-problem');
                        row.classList.add('not-problem');
                        if (currentFilter === 'problems') {
                            row.style.display = 'none';
                        }
                    } else {
                        fgToast(d.error || 'حدث خطأ أثناء الحذف', 'err');
                    }
                })
                .catch(() => fgToast('تعذر الاتصال بالسيرفر', 'err'));
        }

        function deleteCurrentInvoiceProblems(distId) {
            if (!confirm('هل أنت متأكد من حذف كل المشاكل المسجلة على هذه الفاتورة؟')) return;
            var fd = new FormData();
            fd.append('action', 'delete_invoice_problems');
            fd.append('csrf', CSRF);
            fd.append('distribution_id', distId);

            fetch('invoice_problems.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if (d.ok) {
                        window.location.href = 'invoice_problems.php';
                    } else {
                        fgToast(d.error || 'حدث خطأ أثناء الحذف', 'err');
                    }
                })
                .catch(() => fgToast('تعذر الاتصال بالسيرفر', 'err'));
        }
        </script>
    <?php endif; ?>

<?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
