<?php
/**
 * ashraqa_invoices.php — فواتير فرع إشراقة
 * نظام الفجالة ستور المالي
 *
 * مخصصة لمراجعة وتسعير الفواتير الموزعة لفرع إشراقة (branch_id = 5).
 * - يرى فرع إشراقة فقط بدون باقي الفروع.
 * - التسعير منفصل تماماً في جدول ashraqa_item_prices.
 * - لا تعديل على الكميات أو التوزيع أو الحالات.
 */
require_once __DIR__ . '/auth.php';
require_permission('invoices:ashraqa');
require_once __DIR__ . '/receiving_lib.php';
require_once __DIR__ . '/ashraqa_prices_lib.php';

$page_title    = 'فواتير إشراقة';
$active_module = 'invoices';
$user          = current_user();
$uid           = $user['id'] ?? null;
$pdo           = db();

// فرع إشراقة الثابت
const ASHRAQA_BRANCH_ID = 5;

// ١) أرقام الفواتير التي فيها توزيع لإشراقة — استعلام واحد مجمّع
$rows = $pdo->prepare(
    'SELECT DISTINCT d.invoice_no, d.id AS dist_id
       FROM invoice_distribution d
       JOIN invoice_distribution_lines l ON l.distribution_id = d.id
      WHERE l.branch_id = ? AND l.amount > 0'
);
$rows->execute([ASHRAQA_BRANCH_ID]);
$ashraqaDocNos = [];   // [doc_no => dist_id]
foreach ($rows as $r) {
    $ashraqaDocNos[(string)$r['invoice_no']] = (int)$r['dist_id'];
}

// معالج حفظ السعر لفرع إشراقة (AJAX على blur)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_price') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!csrf_verify($_POST['csrf'] ?? '')) {
            throw new RuntimeException('انتهت صلاحية الجلسة، اعمل تحديث للصفحة.');
        }
        $d = (int) ($_POST['distribution_id'] ?? 0);
        $c = trim((string) ($_POST['item_code'] ?? ''));
        $p = (float) ($_POST['price'] ?? 0);

        if ($d <= 0 || $c === '') {
            throw new RuntimeException('بيانات ناقصة.');
        }
        if ($p < 0) {
            throw new RuntimeException('السعر لازم يكون موجب.');
        }

        // حارس أمان: التحقق من أن الفاتورة فيها توزيع لإشراقة
        if (!in_array($d, $ashraqaDocNos, true)) {
            http_response_code(403);
            throw new RuntimeException('غير مصرح لك بتسعير هذه الفاتورة.');
        }

        ashraqa_save_price($d, $c, $p, $uid);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        if (http_response_code() === 200) {
            http_response_code(400);
        }
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// دقة تقريب نسبة التسعير لأقرب نصف جنيه
const ASH_MRK_ROUND_STEP = 0.5;

function ash_mrk_round(float $v): float
{
    return round($v / ASH_MRK_ROUND_STEP) * ASH_MRK_ROUND_STEP;
}

function ash_mrk_cost(array $item): float
{
    $c = (float) ($item['unit_price'] ?? 0);
    if ($c <= 0) $c = (float) ($item['cost_price'] ?? 0);
    return $c > 0 ? $c : 0.0;
}

function ash_mrk_pct(float $cost, float $sale): ?float
{
    if ($cost <= 0 || $sale <= 0) return null;
    return ($sale / $cost - 1) * 100;
}

function ash_mrk_fmt(?float $p): string
{
    if ($p === null) return '';
    $s = number_format($p, 1, '.', '');
    $s = rtrim(rtrim($s, '0'), '.');
    return ($s === '' || $s === '-') ? '0' : $s;
}

function ash_mrk_cell(string $code, float $cost, ?float $sale): string
{
    $has = $cost > 0;
    $pct = ($has && $sale !== null && $sale > 0) ? ash_mrk_pct($cost, $sale) : null;
    $ph  = $pct === null ? '—' : ash_mrk_fmt($pct) . '%';
    $cs  = $has ? rtrim(rtrim(number_format($cost, 5, '.', ''), '0'), '.') : '0';

    return '<td class="c-mrk">'
        . '<input type="number" step="0.5" class="mrk-input' . ($has ? '' : ' no-cost') . '"'
        . ' aria-label="نسبة التسعير" data-code="' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '" data-cost="' . htmlspecialchars($cs, ENT_QUOTES, 'UTF-8') . '"'
        . ' placeholder="' . htmlspecialchars($ph, ENT_QUOTES, 'UTF-8') . '"' . ($has ? '' : ' disabled')
        . ' title="' . ($has ? 'اكتب نسبة واضغط Enter — سعر البيع هيتحسب لوحده' : 'مفيش تكلفة للصنف ده') . '"'
        . ' onblur="applyAshMrk(this)" onkeydown="ashMrkKey(event,this)">'
        . '</td>';
}

// فحص الفاتورة المفتوحة
$openId  = $_GET['invoice'] ?? null;
$openSuf = $_GET['suffix'] ?? null;
$openInvoice = null;
$distId = null;

if ($openId !== null && $openId !== '') {
    $header = sqlsrv_fetch_invoice_header($openId, $openSuf);
    $docNo = $header ? (string)($header['inv_no'] ?? '') : '';
    // حارس إجباري: التحقق من أن الفاتورة فعلاً فيها توزيع لإشراقة
    if (!$header || !isset($ashraqaDocNos[$docNo])) {
        http_response_code(403);
        require_once __DIR__ . '/includes/header.php';
        ?>
        <div class="card" style="margin:20px 0;text-align:center;padding:40px;">
            <h2 style="color:var(--neg);margin-bottom:12px;">403 — غير مصرح بعرض هذه الفاتورة</h2>
            <p style="color:var(--ink-500);font-size:14px;margin-bottom:20px;">
                الفاتورة المطلوبة غير مخصصة لفرع إشراقة أو لا تحتوي على كميات موزعة للفرع.
            </p>
            <a href="ashraqa_invoices.php" class="btn btn-primary">
                <i class="ti ti-arrow-right"></i> رجوع لفواتير إشراقة
            </a>
        </div>
        <?php
        require_once __DIR__ . '/includes/footer.php';
        exit;
    }
    $openInvoice = $header;
    $distId = $ashraqaDocNos[$docNo];
}

// في حالة الشاشة الأولى (قائمة الفواتير)
$invoices = [];
if ($openInvoice === null) {
    $all = sqlsrv_fetch_invoices();
    foreach ($all as $inv) {
        $no = (string) $inv['inv_no'];
        if (!isset($ashraqaDocNos[$no])) continue;
        $inv['dist_id'] = $ashraqaDocNos[$no];
        $invoices[] = $inv;
    }
} else {
    // جلب أصناف الفاتورة ودمج المتكرر
    $rawItems = sqlsrv_fetch_invoice_items($openId, $openSuf);
    $items = merge_invoice_items($rawItems);

    // كميات إشراقة باستعلام واحد مجمّع
    $stQty = $pdo->prepare(
        'SELECT notes AS item_code, SUM(amount) AS qty
           FROM invoice_distribution_lines
          WHERE distribution_id = ? AND branch_id = ?
          GROUP BY notes'
    );
    $stQty->execute([$distId, ASHRAQA_BRANCH_ID]);
    $ashraqaQtys = [];
    foreach ($stQty as $qr) {
        $ashraqaQtys[(string)$qr['item_code']] = (float)$qr['qty'];
    }

    // فلترة الأصناف: اللي كميته لإشراقة صفر ما يظهرش خالص
    $ashraqaItems = [];
    $ashraqaCodes = [];
    foreach ($items as $it) {
        $c = (string)($it['item_code'] ?? '');
        $q = (float)($ashraqaQtys[$c] ?? 0);
        if ($q <= 0) continue;
        $ashraqaItems[] = $it;
        $ashraqaCodes[] = $c;
    }

    // الأرصدة والأسعار باستعلام واحد لكل منها
    $balances   = ashraqa_fetch_balances($ashraqaCodes);
    $salePrices = ashraqa_get_prices($distId);
    $lastSync   = ashraqa_last_sync();
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
    .btn {
        background: var(--accent);
        color: #fff;
        border: none;
        padding: 8px 16px;
        border-radius: 9px;
        font-size: 13.5px;
        font-family: inherit;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 7px;
        text-decoration: none;
        transition: background 0.15s ease;
    }
    .btn:hover {
        background: var(--accent-hover);
        color: #fff;
    }
    .btn-primary {
        background: var(--accent);
        color: #fff;
    }
    .btn-primary:hover {
        background: var(--accent-hover);
        color: #fff;
    }
    .btn-outline {
        background: var(--surface);
        color: var(--ink-700);
        border: 1px solid var(--line);
    }
    .btn-outline:hover {
        background: var(--bg);
        color: var(--ink-700);
    }
    .btn-sm {
        padding: 5px 11px;
        font-size: 12.5px;
    }
    .st-badge {
        display: inline-block;
        padding: 3px 9px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
        background: var(--accent-soft);
        color: var(--accent-text);
    }
    .ash-table-wrap {
        overflow-x: auto;
        border: 1px solid var(--line);
        border-radius: 10px;
        background: var(--surface);
    }
    .ash-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13.5px;
        text-align: right;
    }
    .ash-table th, .ash-table td {
        padding: 10px 12px;
        border-bottom: 1px solid var(--line-soft);
        vertical-align: middle;
    }
    .ash-table th {
        background: var(--bg);
        color: var(--ink-700);
        font-weight: 600;
        white-space: nowrap;
    }
    .ash-table tr:last-child td {
        border-bottom: none;
    }
    .ash-table tbody tr:hover {
        background: var(--bg);
    }
    .c-code {
        font-family: monospace;
        font-size: 12.5px;
        color: var(--ink-600);
        direction: ltr;
        text-align: right;
    }
    .c-sale {
        white-space: nowrap;
    }
    .sale-input {
        width: 88px;
        padding: 6px 8px;
        border: 1px solid var(--line);
        border-radius: 7px;
        font-family: inherit;
        font-size: 13.5px;
        background: var(--surface);
        color: var(--ink-900);
        box-sizing: border-box;
    }
    .sale-input:focus {
        outline: none;
        border-color: var(--accent-text);
        box-shadow: 0 0 0 3px var(--accent-ring);
    }
    .sale-input.dirty {
        border-color: var(--warn);
        background: var(--warn-soft);
    }
    .sale-ind {
        font-size: 12px;
        width: 14px;
        display: inline-block;
        text-align: center;
        margin-right: 3px;
    }
    .sale-ind.wait { color: var(--ink-500); }
    .sale-ind.ok   { color: var(--pos); font-weight: bold; }
    .sale-ind.err  { color: var(--neg); font-weight: bold; }
    .c-mrk {
        width: 96px;
    }
    .mrk-input {
        width: 80px;
        padding: 6px 8px;
        border: 1px solid var(--line);
        border-radius: 7px;
        font-family: inherit;
        font-size: 13.5px;
        background: var(--surface);
        color: var(--ink-900);
        box-sizing: border-box;
    }
    .mrk-input:focus {
        outline: none;
        border-color: var(--accent-text);
        box-shadow: 0 0 0 3px var(--accent-ring);
    }
    .mrk-input.no-cost {
        opacity: 0.5;
        background: var(--bg);
    }
</style>

<?php if ($openInvoice === null): ?>
    <!-- ================= الشاشة الأولى: قائمة فواتير إشراقة ================= -->
    <div class="card" style="margin-bottom:16px;">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
            <div>
                <h1 style="font-size:18px;margin:0 0 4px 0;color:var(--ink-900);">فواتير إشراقة</h1>
                <div style="font-size:13px;color:var(--ink-500);">
                    الفواتير التي تحتوي على كميات موزعة لفرع إشراقة (<?= count($invoices) ?> فاتورة)
                </div>
            </div>
        </div>
    </div>

    <?php if (empty($invoices)): ?>
        <div class="card" style="text-align:center;padding:36px;color:var(--ink-500);">
            <i class="ti ti-package" style="font-size:32px;display:block;margin-bottom:8px;"></i>
            لا توجد فواتير موزعة لفرع إشراقة حالياً.
        </div>
    <?php else: ?>
        <div class="ash-table-wrap">
            <table class="ash-table">
                <thead>
                    <tr>
                        <th style="width:50px;">#</th>
                        <th>رقم الفاتورة</th>
                        <th>التاريخ</th>
                        <th>الحالة</th>
                        <th style="width:110px;">الإجراء</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $idx => $inv): ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td style="font-weight:600;color:var(--ink-900);">
                                <?= htmlspecialchars((string)($inv['inv_no'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td style="color:var(--ink-600);font-size:13px;">
                                <?= !empty($inv['invoice_date']) ? htmlspecialchars(date('Y-m-d', strtotime((string)$inv['invoice_date'])), ENT_QUOTES, 'UTF-8') : '—' ?>
                            </td>
                            <td>
                                <span class="st-badge">جديدة</span>
                            </td>
                            <td>
                                <a href="ashraqa_invoices.php?invoice=<?= urlencode((string)$inv['inv_id']) ?>&suffix=<?= urlencode((string)($inv['suffix'] ?? '')) ?>"
                                   class="btn btn-outline btn-sm" title="فتح أصناف الفاتورة">
                                    عرض الأصناف <i class="ti ti-chevron-left"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

<?php else: ?>
    <!-- ================= الشاشة الثانية: فاتورة واحدة ================= -->
    <div class="card" style="margin-bottom:16px;">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:12px;">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <a href="ashraqa_invoices.php" class="btn btn-outline btn-sm">
                    <i class="ti ti-arrow-right"></i> رجوع لقائمة الفواتير
                </a>
                <h1 style="font-size:18px;margin:0;color:var(--ink-900);">
                    فاتورة رقم <?= htmlspecialchars((string)$openInvoice['inv_no'], ENT_QUOTES, 'UTF-8') ?>
                </h1>
                <span class="st-badge">جديدة</span>
            </div>
            <div style="font-size:13px;color:var(--ink-600);">
                تاريخ الفاتورة: <?= !empty($openInvoice['invoice_date']) ? htmlspecialchars(date('Y-m-d', strtotime((string)$openInvoice['invoice_date'])), ENT_QUOTES, 'UTF-8') : '—' ?>
            </div>
        </div>
        <div style="font-size:12.5px;color:var(--ink-500);border-top:1px solid var(--line-soft);padding-top:8px;">
            <i class="ti ti-clock"></i> آخر تحديث أرصدة إشراقة: <?= $lastSync ? htmlspecialchars($lastSync, ENT_QUOTES, 'UTF-8') : 'لا يوجد مزامنة سابقة' ?>
        </div>
    </div>

    <?php if (empty($ashraqaItems)): ?>
        <div class="card" style="text-align:center;padding:36px;color:var(--ink-500);">
            لا توجد أصناف موزعة لفرع إشراقة في هذه الفاتورة.
        </div>
    <?php else: ?>
        <div class="ash-table-wrap">
            <table class="ash-table">
                <thead>
                    <tr>
                        <th>كود الصنف</th>
                        <th>اسم الصنف</th>
                        <th>كمية إشراقة</th>
                        <th>رصيد إشراقة</th>
                        <th>سعر الوحدة</th>
                        <th>سعر البيع</th>
                        <th>نسبة التسعير</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ashraqaItems as $it):
                        $code = (string)($it['item_code'] ?? '');
                        $qty  = (float)($ashraqaQtys[$code] ?? 0);
                        $hasPrice = isset($salePrices[$code]);
                        $sPrice = $hasPrice ? (float)$salePrices[$code] : null;

                        // رصيد إشراقة: قد يكون سالب ويعرض كما هو، ولو غير موجود يعرض —
                        if (isset($balances[$code])) {
                            $bVal = (float)$balances[$code]['balance'];
                            $bDisp = rtrim(rtrim(number_format($bVal, 2, '.', ''), '0'), '.');
                            if ($bDisp === '-0' || $bDisp === '') $bDisp = '0';
                        } else {
                            $bDisp = '—';
                        }
                    ?>
                        <tr class="item-row">
                            <td class="c-code"><?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="font-weight:500;color:var(--ink-900);">
                                <?= htmlspecialchars((string)($it['item_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td style="font-weight:600;">
                                <?= rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') ?>
                            </td>
                            <td>
                                <span><?= htmlspecialchars($bDisp, ENT_QUOTES, 'UTF-8') ?></span>
                            </td>
                            <td style="color:var(--ink-600);">
                                <?= number_format((float)($it['unit_price'] ?? 0), 2) ?>
                            </td>
                            <td class="c-sale">
                                <div style="display:inline-flex;align-items:center;">
                                    <input type="number" step="0.01" min="0"
                                           class="sale-input" aria-label="سعر البيع"
                                           value="<?= $hasPrice ? rtrim(rtrim(number_format($sPrice, 5, '.', ''), '0'), '.') : '' ?>"
                                           placeholder="0"
                                           data-code="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>"
                                           data-saved="<?= $hasPrice ? htmlspecialchars((string)$sPrice, ENT_QUOTES, 'UTF-8') : '' ?>"
                                           oninput="ashOnSaleInput(this)"
                                           onblur="ashSaveSalePrice(this)">
                                    <span class="sale-ind"></span>
                                </div>
                            </td>
                            <?= ash_mrk_cell($code, ash_mrk_cost($it), $sPrice) ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <script>
            const CSRF = '<?= csrf_token() ?>';
            const DIST_ID = <?= (int)$distId ?>;

            function ashMrkRound(v) {
                return Math.round(v / 0.5) * 0.5;
            }

            function ashMrkFmt(p) {
                if (p === null || isNaN(p)) return '';
                let s = Number(p).toFixed(1);
                if (s.endsWith('.0')) s = s.slice(0, -2);
                return (s === '' || s === '-') ? '0' : s;
            }

            function refreshAshMrk(row) {
                if (!row) return;
                const m = row.querySelector('.mrk-input');
                const s = row.querySelector('.sale-input');
                if (!m || !s) return;
                const cost = parseFloat(m.dataset.cost || '0') || 0;
                const sale = parseFloat(s.value || '0') || 0;
                m.placeholder = (cost > 0 && sale > 0) ? ashMrkFmt((sale / cost - 1) * 100) + '%' : '—';
            }

            function ashOnSaleInput(inp) {
                inp.classList.add('dirty');
                const row = inp.closest('tr');
                if (row) refreshAshMrk(row);
            }

            function ashMrkKey(ev, inp) {
                const k = ev.key || '';
                const isEnter = k === 'Enter' || k === 'Go' || k === 'Done' || k === 'Next'
                             || ev.keyCode === 13 || ev.which === 13;
                if (!isEnter) return;
                ev.preventDefault();
                inp.blur();
            }

            function applyAshMrk(inp) {
                const raw = (inp.value || '').trim();
                inp.value = '';
                if (raw === '') return;

                const p = parseFloat(raw);
                if (isNaN(p) || p <= -100) return;

                const cost = parseFloat(inp.dataset.cost || '0') || 0;
                if (cost <= 0) return;

                const row = inp.closest('tr');
                const sale = row && row.querySelector('.sale-input');
                if (!sale) return;

                const val = ashMrkRound(cost * (1 + p / 100));
                sale.value = String(val);
                sale.classList.add('dirty');
                ashSaveSalePrice(sale);
                refreshAshMrk(row);
            }

            function ashSaveSalePrice(inp) {
                const code = inp.dataset.code;
                const raw  = (inp.value || '').trim();
                const val  = raw === '' ? 0 : parseFloat(raw);
                if (isNaN(val) || val < 0) {
                    inp.value = inp.dataset.saved || '';
                    return;
                }

                const prev = parseFloat(inp.dataset.saved || '0') || 0;
                if (inp.dataset.saved !== '' && Math.abs(val - prev) < 0.000001) {
                    inp.classList.remove('dirty');
                    return;
                }

                const ind = inp.parentElement ? inp.parentElement.querySelector('.sale-ind') : null;
                if (ind) { ind.textContent = '⏳'; ind.className = 'sale-ind wait'; }

                const body = {
                    action: 'save_price',
                    csrf: CSRF,
                    distribution_id: DIST_ID,
                    item_code: code,
                    price: val
                };

                fetch('ashraqa_invoices.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams(body).toString()
                })
                .then(r => r.json())
                .then(j => {
                    if (j.ok) {
                        inp.dataset.saved = String(val);
                        inp.classList.remove('dirty');
                        if (ind) { ind.textContent = '✓'; ind.className = 'sale-ind ok'; }
                        setTimeout(() => { if (ind) ind.textContent = ''; }, 1400);
                        const row = inp.closest('tr');
                        if (row) refreshAshMrk(row);
                    } else {
                        if (ind) { ind.textContent = '✕'; ind.className = 'sale-ind err'; }
                        alert(j.error || 'تعذّر الحفظ');
                    }
                })
                .catch(() => {
                    if (ind) { ind.textContent = '✕'; ind.className = 'sale-ind err'; }
                });
            }
        </script>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
