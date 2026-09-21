<?php
/**
 * book_reserve.php — حجز الكتب (تاب ٣ في موديول النواقص)
 * نظام الفجالة ستور المالي
 *
 * العميل بيحجز كتب خارجية: بيانات العميل + مقدم + جدول كتب.
 * جنب كل كتاب حالته:
 *     نزل   = الصنف ظهر في فاتورة شراء (بالـ ITEM_ID)
 *     منزلش = عمره ما ظهر
 *
 * ⚠️ المقدم (Deposit) بيتخزّن كرقم على الحجز بس — مفيش أي قيد يومية
 *    ولا حركة خزينة. ربطه بالخزينة قرار لسه ما اتحسمش.
 *
 * ⏳ إيصال الطباعة لسه ما اتعملش — شكله وطريقته اتأجّلوا لجلسة تانية.
 */
require_once __DIR__ . '/auth.php';
require_permission('shortage');
require_once __DIR__ . '/book_lib.php';

$uid      = (int) current_user()['id'];
$myBranch = (int) (current_user()['branch_id'] ?? 0);

// ===== AJAX =====
$ajax = $_GET['ajax'] ?? '';
if ($ajax !== '') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        switch ($ajax) {
            // بحث في الكتب الخارجية (كود أو اسم)
            case 'search':
                echo json_encode([
                    'ok'   => true,
                    'rows' => books_search((string) ($_GET['term'] ?? ''), 40),
                ], JSON_UNESCAPED_UNICODE);
                break;

            // حفظ حجز جديد
            case 'save':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('طلب غير صالح.');
                $body = json_decode(file_get_contents('php://input'), true);
                if (!is_array($body)) throw new RuntimeException('بيانات غير صالحة.');
                if (!csrf_verify((string) ($body['csrf'] ?? ''))) {
                    throw new RuntimeException('انتهت صلاحية الجلسة — اعمل ريفريش وحاول تاني.');
                }
                $id = book_reservation_create(
                    (array) ($body['res'] ?? []),
                    (array) ($body['lines'] ?? []),
                    $uid
                );
                echo json_encode(['ok' => true, 'id' => $id,
                    'msg' => 'اتحفظ الحجز رقم ' . $id], JSON_UNESCAPED_UNICODE);
                break;

            default:
                echo json_encode(['ok' => false, 'error' => 'طلب غير معروف']);
        }
    } catch (Throwable $ex) {
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ===== تغيير حالة حجز (POST عادي + PRG) =====
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'set_status') {
    if (csrf_verify((string) ($_POST['csrf'] ?? ''))) {
        book_reservation_set_status((int) ($_POST['res'] ?? 0), (string) ($_POST['status'] ?? ''));
    }
    header('Location: book_reserve.php?view=' . (int) ($_POST['res'] ?? 0));
    exit;
}

$branches   = db()->query('SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name')->fetchAll();
$viewId     = (int) ($_GET['view'] ?? 0);
$viewing    = $viewId > 0 ? book_reservation_get($viewId) : null;
$statusFilt = (string) ($_GET['st'] ?? 'open');
$recent     = book_reservations_list($statusFilt, 60);
$catReady   = books_cat_ready();

$page_title    = 'حجز الكتب';
$active_module = 'shortage';
require_once __DIR__ . '/includes/header.php';
?>
<style>
    .btn { background:var(--accent); color:#fff; border:none; padding:11px 20px; border-radius:10px;
        font-size:14px; font-family:inherit; cursor:pointer; display:inline-flex; align-items:center;
        gap:7px; text-decoration:none; white-space:nowrap; }
    .btn:hover { filter:brightness(0.94); }
    .btn:disabled { opacity:.55; cursor:not-allowed; }

    .rs-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:12px;
               margin-bottom:6px; }
    .rs-fld label { display:block; font-size:12.5px; color:var(--ink-700); margin-bottom:5px;
                    font-weight:600; }
    .rs-fld input, .rs-fld select { width:100%; padding:10px 12px; border:1.5px solid var(--line);
        border-radius:9px; font-family:inherit; font-size:14px; box-sizing:border-box; }
    .rs-fld input:focus, .rs-fld select:focus { outline:none; border-color:var(--accent-text); }

    .rs-bar { display:flex; gap:10px; align-items:center; margin:6px 0 12px; position:relative;
              flex-wrap:wrap; }
    .rs-bar input.main { flex:1; min-width:250px; padding:12px 16px; border:1.5px solid var(--line);
                         border-radius:10px; font-size:15px; font-family:inherit; }
    .rs-bar input.main:focus { outline:none; border-color:var(--accent-text); }
    .rs-sug { position:absolute; top:100%; right:0; left:0; background:var(--surface); z-index:50;
              border:1px solid var(--line); border-radius:10px; margin-top:4px; max-height:340px;
              overflow-y:auto; box-shadow:0 8px 24px rgba(30,27,75,.12); display:none; }
    .rs-sug.show { display:block; }
    .rs-sug-item { padding:10px 14px; cursor:pointer; display:flex; justify-content:space-between;
                   gap:10px; border-bottom:1px solid var(--line-soft); font-size:14px; }
    .rs-sug-item:hover { background:var(--accent-soft); }
    .rs-sug-item .c { font-family:monospace; color:var(--ink-500); font-size:12px; }

    .rs-table { width:100%; border-collapse:collapse; }
    .rs-table th, .rs-table td { padding:10px 12px; border-bottom:1px solid var(--line-soft);
                                 text-align:center; font-size:13.5px; }
    .rs-table th { background:var(--line-soft); color:var(--accent-text); font-weight:600; }
    .rs-table .nm { text-align:right; font-weight:500; min-width:210px; }
    .rs-table .cd { font-family:monospace; color:var(--ink-500); font-size:12px; }
    .rs-table td .rm { color:var(--neg); cursor:pointer; opacity:.5; }
    .rs-table td .rm:hover { opacity:1; }

    .pill { display:inline-block; padding:4px 13px; border-radius:20px; font-size:12.5px;
            font-weight:700; white-space:nowrap; }
    .pill.yes { background:rgba(34,197,94,.13); color:var(--pos); }
    .pill.no  { background:var(--warn-soft); color:var(--warn); }
    .pill.st-open { background:var(--accent-soft); color:var(--accent-text); }
    .pill.st-delivered { background:rgba(34,197,94,.13); color:var(--pos); }
    .pill.st-cancelled { background:var(--line-soft); color:var(--ink-500); }

    .q-inp { width:80px; padding:7px 8px; border:1.5px solid var(--line); border-radius:8px;
             font-size:14px; font-family:inherit; text-align:center; }
    .q-inp:focus { outline:none; border-color:var(--pos); }

    .rs-empty { text-align:center; color:var(--ink-400); padding:36px 20px; font-size:14.5px; }
    .rs-status { font-size:13px; min-height:20px; margin-bottom:8px; }
    .rs-list-row { display:flex; justify-content:space-between; align-items:center; gap:12px;
                   flex-wrap:wrap; padding:12px 16px; border:1px solid var(--line-soft);
                   border-radius:11px; margin-bottom:8px; text-decoration:none; color:inherit; }
    .rs-list-row:hover { border-color:var(--accent-text); background:var(--accent-soft); }
    .rs-warn { padding:12px 18px; background:var(--warn-soft,var(--line-soft)); border-radius:10px;
               border:1.5px solid var(--warn,var(--warn)); color:var(--warn,var(--warn));
               font-size:13.5px; margin-bottom:16px; line-height:1.8; }
</style>

<?= book_tabs_bar('reserve') ?>

<?php if (!$catReady): ?>
    <div class="rs-warn">
        <i class="ti ti-alert-triangle"></i>
        جدول التصنيفات (<span style="font-family:monospace;">hq_item_cats</span>) لسه فاضي — البحث دلوقتي
        بيدوّر في <b>كل الأصناف</b> مش الكتب الخارجية بس.
        ارفع <span style="font-family:monospace;">agent_hq.ps1</span> الجديد وشغّله مرة يدوي، والفلتر هيشتغل لوحده.
    </div>
<?php endif; ?>

<?php if ($viewing): // ===== عرض حجز محفوظ ===== ?>
    <?php $r = $viewing['res']; $stLabels = ['open'=>'مفتوح','delivered'=>'اتسلّم','cancelled'=>'ملغي']; ?>
    <div class="card" style="margin-bottom:16px;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;">
            <div>
                <div class="section-title" style="margin-bottom:8px;">
                    حجز رقم <?= (int)$r['id'] ?>
                    <span class="pill st-<?= e($r['status']) ?>"><?= e($stLabels[$r['status']] ?? $r['status']) ?></span>
                </div>
                <div style="font-size:13.5px;color:var(--ink-700);line-height:2;">
                    <b><?= e($r['customer_name']) ?></b>
                    <?= $r['customer_phone'] ? ' · ' . e($r['customer_phone']) : '' ?><br>
                    الفرع: <?= e($r['branch_name'] ?: '—') ?>
                    · الحاجز: <?= e($r['reserved_by'] ?: ($r['creator'] ?: '—')) ?>
                    · المقدم: <b><?= number_format((float)$r['deposit'], 2) ?></b> ج<br>
                    <span style="color:var(--ink-500);font-size:12.5px;">
                        <?= e(date('Y-m-d H:i', strtotime((string)$r['created_at']))) ?>
                    </span>
                </div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <?php if ($r['status'] === 'open'): ?>
                    <?php foreach ([['delivered','اتسلّم','var(--pos)'], ['cancelled','إلغاء','var(--neg)']] as [$sv,$lb,$col]): ?>
                        <form method="post" style="display:inline;"
                              onsubmit="return confirm('تأكيد: <?= e($lb) ?>؟');">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="set_status">
                            <input type="hidden" name="res" value="<?= (int)$r['id'] ?>">
                            <input type="hidden" name="status" value="<?= $sv ?>">
                            <button class="btn" style="background:<?= $col ?>;padding:9px 16px;font-size:13px;"><?= e($lb) ?></button>
                        </form>
                    <?php endforeach; ?>
                <?php endif; ?>
                <a class="btn" style="background:var(--fill-strong);color:var(--fill-strong-fg);padding:9px 16px;font-size:13px;"
                   href="book_reserve.php"><i class="ti ti-plus"></i> حجز جديد</a>
            </div>
        </div>

        <table class="rs-table" style="margin-top:18px;">
            <thead><tr>
                <th>كود الصنف</th><th class="nm">اسم الصنف</th><th>الكمية</th><th>حالة الكتاب</th>
            </tr></thead>
            <tbody>
            <?php foreach ($viewing['lines'] as $l): ?>
                <tr>
                    <td class="cd"><?= e($l['code']) ?></td>
                    <td class="nm"><?= e($l['item_name']) ?></td>
                    <td><?= rtrim(rtrim(number_format((float)$l['qty'], 2, '.', ''), '0'), '.') ?></td>
                    <td><span class="pill <?= $l['purchased'] ? 'yes' : 'no' ?>">
                        <?= $l['purchased'] ? 'نزل' : 'منزلش' ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div style="margin-top:14px;padding:10px 14px;background:var(--line-soft);border-radius:9px;
                    font-size:12.5px;color:var(--ink-500);">
            <i class="ti ti-printer"></i> إيصال الطباعة لسه ما اتعملش — شكله وطريقته في الخطوة الجاية.
        </div>
    </div>

<?php else: // ===== حجز جديد ===== ?>
    <div class="card" style="margin-bottom:16px;">
        <div class="section-title"><i class="ti ti-bookmark"></i> حجز جديد</div>

        <div class="rs-grid">
            <div class="rs-fld">
                <label>اسم العميل *</label>
                <input type="text" id="cName" autocomplete="off" placeholder="اسم العميل">
            </div>
            <div class="rs-fld">
                <label>رقم هاتف العميل</label>
                <input type="tel" id="cPhone" autocomplete="off" inputmode="tel" placeholder="01xxxxxxxxx">
            </div>
            <div class="rs-fld">
                <label>اللي حجز</label>
                <input type="text" id="cBy" autocomplete="off"
                       value="<?= e(current_user()['full_name'] ?? '') ?>">
            </div>
            <div class="rs-fld">
                <label>المقدم (Deposit)</label>
                <input type="number" id="cDep" min="0" step="0.01" placeholder="0.00">
            </div>
            <div class="rs-fld">
                <label>الفرع</label>
                <select id="cBranch">
                    <option value="0">— اختار الفرع —</option>
                    <?php foreach ($branches as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= $myBranch === (int)$b['id'] ? 'selected' : '' ?>>
                            <?= e($b['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div style="font-size:12px;color:var(--ink-500);margin-bottom:14px;">
            المقدم بيتسجّل على الحجز للإيصال بس — مش بيدخل الخزينة ولا بيعمل قيد.
        </div>

        <!-- بحث وإضافة الكتب -->
        <div class="rs-bar">
            <input type="text" class="main" id="q" autocomplete="off"
                   placeholder="امسح باركود، أو اكتب كود/اسم الكتاب…"
                   enterkeyhint="search"
                   oninput="onType()"
                   onkeydown="if(isEnterKey(event)){event.preventDefault();addByCode();}">
            <button type="button" class="btn" style="background:var(--fill-strong);color:var(--fill-strong-fg);padding:11px 16px;"
                    onclick="openBarcodeScanner('q')" title="مسح بالكاميرا">
                <i class="ti ti-camera" style="font-size:18px;"></i>
            </button>
            <div class="rs-sug" id="sug"></div>
        </div>
        <div class="rs-status" id="status"></div>

        <div style="overflow-x:auto;">
            <table class="rs-table">
                <thead><tr>
                    <th style="width:34px;">#</th>
                    <th>كود الصنف</th>
                    <th class="nm">اسم الصنف</th>
                    <th style="width:110px;">كمية الحجز</th>
                    <th style="width:110px;">حالة الكتاب</th>
                    <th style="width:34px;"></th>
                </tr></thead>
                <tbody id="body"></tbody>
            </table>
        </div>

        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;
                    flex-wrap:wrap;margin-top:16px;">
            <span id="count" style="color:var(--ink-500);font-size:13.5px;font-weight:600;"></span>
            <span style="display:flex;gap:10px;flex-wrap:wrap;">
                <button type="button" class="btn" style="background:var(--neg-fill);" onclick="clearAll()">
                    <i class="ti ti-trash"></i> تفريغ
                </button>
                <button type="button" class="btn" id="saveBtn" style="background:var(--pos-fill);" onclick="saveRes()">
                    <i class="ti ti-bookmark"></i> احفظ الحجز
                </button>
            </span>
        </div>
        <div id="err" style="color:var(--neg);font-size:13px;min-height:18px;margin-top:8px;"></div>
    </div>
<?php endif; ?>

<!-- ===== الحجوزات ===== -->
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;
                flex-wrap:wrap;margin-bottom:14px;">
        <div class="section-title" style="margin:0;">الحجوزات</div>
        <div style="display:flex;gap:7px;flex-wrap:wrap;">
            <?php foreach ([['open','المفتوحة'],['delivered','اتسلّمت'],['cancelled','الملغية'],['all','الكل']] as [$sv,$lb]): ?>
                <a class="btn" style="padding:8px 15px;font-size:13px;background:<?= $statusFilt===$sv?'var(--accent)':'var(--ink-800)' ?>;"
                   href="book_reserve.php?st=<?= $sv ?>"><?= e($lb) ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (!$recent): ?>
        <div class="rs-empty">مفيش حجوزات هنا.</div>
    <?php else: ?>
        <?php $stLabels = ['open'=>'مفتوح','delivered'=>'اتسلّم','cancelled'=>'ملغي']; ?>
        <?php foreach ($recent as $rr): ?>
            <a class="rs-list-row" href="book_reserve.php?view=<?= (int)$rr['id'] ?>">
                <span>
                    <b>#<?= (int)$rr['id'] ?> — <?= e($rr['customer_name']) ?></b>
                    <?= $rr['customer_phone'] ? ' · ' . e($rr['customer_phone']) : '' ?>
                    <br>
                    <span style="font-size:12.5px;color:var(--ink-500);">
                        <?= (int)$rr['n_books'] ?> كتاب · مقدم <?= number_format((float)$rr['deposit'], 2) ?> ج
                        · <?= e($rr['branch_name'] ?: '—') ?>
                        · <?= e(date('Y-m-d H:i', strtotime((string)$rr['created_at']))) ?>
                    </span>
                </span>
                <span class="pill st-<?= e($rr['status']) ?>"><?= e($stLabels[$rr['status']] ?? $rr['status']) ?></span>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script src="assets/barcode_scanner.js?v=6"></script>
<script>
    const CSRF = <?= json_encode(csrf_token()) ?>;
    let added = [];          // الكتب المضافة للحجز الحالي
    let sugTimer = null;
    const resQty = {};       // code → كمية الحجز (بتعيش مع إعادة الرسم)

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g,
            c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
    function setStatus(t, c) {
        const el = document.getElementById('status');
        if (!el) return;
        el.textContent = t; el.style.color = c || 'var(--ink-500)';
    }

    // ===== البحث =====
    function onType() {
        const v = document.getElementById('q').value.trim();
        clearTimeout(sugTimer);
        if (v.length < 2) { hideSug(); return; }
        if (/^\d+$/.test(v)) { hideSug(); return; }   // باركود — Enter هيضيفه
        sugTimer = setTimeout(() => fetchSug(v), 350);
    }

    function fetchSug(term) {
        fetch('book_reserve.php?ajax=search&term=' + encodeURIComponent(term))
            .then(r => r.json())
            .then(j => {
                const box = document.getElementById('sug');
                if (!j.ok || !j.rows.length) { hideSug(); return; }
                box.innerHTML = j.rows.map(r =>
                    '<div class="rs-sug-item" onclick=\'pick(' +
                        JSON.stringify(r).replace(/'/g, "&#39;") + ')\'>' +
                      '<span><b>' + esc(r.name) + '</b><br><span class="c">' + esc(r.code) + '</span></span>' +
                      '<span class="pill ' + (r.purchased ? 'yes' : 'no') + '">' +
                        (r.purchased ? 'نزل' : 'منزلش') + '</span>' +
                    '</div>').join('');
                box.classList.add('show');
            })
            .catch(hideSug);
    }
    function hideSug() { const b = document.getElementById('sug'); if (b) b.classList.remove('show'); }

    function pick(row) {
        hideSug();
        document.getElementById('q').value = '';
        pushRow(row);
        document.getElementById('q').focus();
    }

    function addByCode() {
        const inp = document.getElementById('q');
        const code = inp.value.trim();
        if (!code) return;
        hideSug();
        setStatus('⏳ بيدور…');
        fetch('book_reserve.php?ajax=search&term=' + encodeURIComponent(code))
            .then(r => r.json())
            .then(j => {
                if (j.ok && j.rows.length) {
                    inp.value = '';
                    pushRow(j.rows[0]);
                    if (navigator.vibrate) navigator.vibrate(50);
                } else {
                    setStatus('✗ مش لاقيينه — جرّب تكتب اسم الكتاب', 'var(--neg)');
                }
            })
            .catch(() => setStatus('✗ مفيش اتصال', 'var(--neg)'))
            .finally(() => inp.focus());
    }

    function pushRow(row) {
        if (added.some(r => r.code === row.code)) {
            setStatus('⚠️ ' + row.name + ' مضاف قبل كده', 'var(--warn,var(--warn))');
            focusQty(row.code);
            return;
        }
        added.push(row);
        if (resQty[row.code] === undefined) resQty[row.code] = 1;
        setStatus('✓ اتضاف ' + row.name, 'var(--pos)');
        render();
        focusQty(row.code);
    }

    function focusQty(code) {
        const i = document.querySelector('.q-inp[data-code="' + CSS.escape(code) + '"]');
        if (i) { i.focus(); i.select(); }
    }

    function removeRow(code) {
        added = added.filter(r => r.code !== code);
        delete resQty[code];
        render();
    }

    function clearAll() {
        if (added.length && !confirm('تفريغ كل الكتب المضافة؟')) return;
        added = [];
        for (const k in resQty) delete resQty[k];
        setStatus('');
        render();
    }

    function onQty(inp) { resQty[inp.dataset.code] = parseFloat(inp.value) || 0; }

    function render() {
        const body = document.getElementById('body');
        if (!body) return;
        if (!added.length) {
            body.innerHTML = '<tr><td colspan="6" class="rs-empty">' +
                'امسح باركود أو اكتب اسم الكتاب عشان تضيفه للحجز.</td></tr>';
            document.getElementById('count').textContent = '';
            return;
        }
        body.innerHTML = added.map((r, i) =>
            '<tr>' +
              '<td>' + (i + 1) + '</td>' +
              '<td class="cd">' + esc(r.code) + '</td>' +
              '<td class="nm">' + esc(r.name) + '</td>' +
              '<td><input type="number" class="q-inp" min="0" step="1" aria-label="كمية الحجز" ' +
                  'value="' + (resQty[r.code] != null ? resQty[r.code] : 1) + '" ' +
                  'data-code="' + esc(r.code) + '" oninput="onQty(this)"></td>' +
              '<td><span class="pill ' + (r.purchased ? 'yes' : 'no') + '">' +
                  (r.purchased ? 'نزل' : 'منزلش') + '</span></td>' +
              '<td><span class="rm" title="شيل" onclick="removeRow(' +
                  JSON.stringify(r.code).replace(/"/g, '&quot;') + ')">✕</span></td>' +
            '</tr>').join('');
        document.getElementById('count').textContent = added.length + ' كتاب في الحجز';
    }

    // ===== الحفظ =====
    function saveRes() {
        const err = document.getElementById('err');
        err.textContent = '';

        const name = document.getElementById('cName').value.trim();
        if (!name) { err.textContent = 'اكتب اسم العميل.'; document.getElementById('cName').focus(); return; }

        const lines = added
            .map(r => ({ item_id: r.item_id, code: r.code, name: r.name, qty: resQty[r.code] || 0 }))
            .filter(l => l.qty > 0);
        if (!lines.length) { err.textContent = 'اكتب كمية حجز لكتاب واحد على الأقل.'; return; }

        const btn = document.getElementById('saveBtn');
        btn.disabled = true;

        fetch('book_reserve.php?ajax=save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf: CSRF,
                res: {
                    customer_name:  name,
                    customer_phone: document.getElementById('cPhone').value.trim(),
                    reserved_by:    document.getElementById('cBy').value.trim(),
                    deposit:        parseFloat(document.getElementById('cDep').value) || 0,
                    branch_id:      parseInt(document.getElementById('cBranch').value, 10) || 0
                },
                lines: lines
            })
        })
        .then(r => r.json())
        .then(j => {
            if (j.ok) {
                location.href = 'book_reserve.php?view=' + j.id;
            } else {
                err.textContent = j.error || 'فشل الحفظ.';
                btn.disabled = false;
            }
        })
        .catch(() => { err.textContent = 'مفيش اتصال بالسيرفر.'; btn.disabled = false; });
    }

    // اقفل الاقتراحات لو دوست بره
    document.addEventListener('click', e => { if (!e.target.closest('.rs-bar')) hideSug(); });

    render();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
