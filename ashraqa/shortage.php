<?php
/**
 * shortage.php — موديول طلب النواقص
 * نظام الفجالة ستور المالي
 *
 * تطوير لشاشة استعلام الأرصدة: الموظف بيمسح/بيكتب الكود، يشوف أرصدة
 * الفروع، يكتب الكمية المطلوبة، ويدوس "طلب نواقص" → بوب أب يحدد فيه
 * الفرع الطالب (تلقائي من فرعه) والفرع المطلوب منه + ملاحظات →
 * مهمة وإشعارات لموظفين الفرع المطلوب منه + متابعة للأونر والمسؤول.
 *
 * البيانات كلها من المرآة المحلية (stock_query_lib) — مش محتاجة الجسر.
 */
require_once __DIR__ . '/auth.php';
require_permission('shortage');
require_once __DIR__ . '/stock_query_lib.php';
require_once __DIR__ . '/shortage_lib.php';
// book_lib بيوفّر شريط التابات المشترك (طلب نواقص / طلبيات الكتب / حجز الكتب)
require_once __DIR__ . '/book_lib.php';

// 🔴 طلب النواقص بيقرا من مرآة الوكلاء بس. الجسر واقف وكل نداء عليه
//    بيستنى الاتصال يفشل، والشاشة دي بتبحث بالباركود عشرات المرات.
stock_mirror_only(true);

$uid = (int) current_user()['id'];

// ===== AJAX =====
$ajax = $_GET['ajax'] ?? '';
if ($ajax !== '') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        switch ($ajax) {
            // إضافة صنف بالكود (مسح/كتابة)
            case 'code':
                $row = stock_item_by_code((string) ($_GET['code'] ?? ''));
                echo json_encode($row
                    ? ['ok' => true, 'row' => $row]
                    : ['ok' => false, 'not_found' => true, 'local_ready' => stock_local_ready()], JSON_UNESCAPED_UNICODE);
                break;

            // اقتراحات البحث بالاسم (منسدلة)
            case 'search':
                $rows = stock_search_by_name((string) ($_GET['term'] ?? ''), 25);
                echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
                break;

            // فلترة (لما مفيش أصناف مضافة)
            case 'filter':
                $rows = stock_filter_items(
                    (string) ($_GET['mode'] ?? ''),
                    (int) ($_GET['sto'] ?? 0),
                    (float) ($_GET['threshold'] ?? 0)
                );
                echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
                break;

            // إرسال طلب النواقص
            case 'submit':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    throw new RuntimeException('طلب غير صالح.');
                }
                $body = json_decode(file_get_contents('php://input'), true);
                if (!is_array($body)) throw new RuntimeException('بيانات غير صالحة.');
                if (!csrf_verify((string) ($body['csrf'] ?? ''))) {
                    throw new RuntimeException('انتهت صلاحية الجلسة — اعمل ريفريش وحاول تاني.');
                }
                $res = create_shortage_request(
                    (int) ($body['from_branch'] ?? 0),
                    (int) ($body['to_branch'] ?? 0),
                    (array) ($body['lines'] ?? []),
                    (string) ($body['notes'] ?? ''),
                    $uid,
                    (string) ($body['source'] ?? 'branch')
                );
                echo json_encode([
                    'ok'      => true,
                    'task_id' => $res['task_id'],
                    'msg'     => 'تم إرسال الطلب — وصل إشعار لـ ' . $res['targets'] . ' موظف في الفرع.',
                ], JSON_UNESCAPED_UNICODE);
                break;

            default:
                echo json_encode(['ok' => false, 'error' => 'طلب غير معروف'], JSON_UNESCAPED_UNICODE);
        }
    } catch (Throwable $ex) {
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$branches = stock_branches_map();
// أي مخزن فيه رصيد ومش معرّف عندنا — يظهر كعمود عشان الأرصدة ماتختفيش
$branches = array_merge($branches, stock_extra_stores(array_column($branches, 'sto_id')));

// الفروع المحلية (للبوب أب) + فرع المستخدم الحالي
$localBranches = db()->query('SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name')->fetchAll();
$myBranchId    = (int) (current_user()['branch_id'] ?? 0);

$page_title    = 'طلب نواقص';
$active_module = 'shortage';
require_once __DIR__ . '/includes/header.php';
?>
<style>
    /* override محلي (بريف ٤٣): الزرار الموحّد في fagala.css، والصفحة دي مختلفة في دول بس */
    .btn { white-space: nowrap; }
    .btn i { font-size:16px; }

    .sq-bar { display:flex; gap:10px; align-items:center; margin-bottom:12px; position:relative;
              flex-wrap:wrap; }
    .sq-bar input.main { flex:1; min-width:260px; padding:12px 16px; border:1.5px solid var(--line);
                         border-radius:10px; font-size:15px; font-family:inherit; }
    .sq-bar input.main:focus { outline:none; border-color:var(--accent-text); }
    .sq-sug { position:absolute; top:100%; right:0; left:0; background:var(--surface); z-index:50;
              border:1px solid var(--line); border-radius:10px; margin-top:4px; max-height:320px;
              overflow-y:auto; box-shadow:0 8px 24px rgba(30,27,75,0.12); display:none; }
    .sq-sug.show { display:block; }
    .sq-sug-item { padding:10px 14px; cursor:pointer; display:flex; justify-content:space-between;
                   gap:10px; border-bottom:1px solid var(--line-soft); font-size:14px; }
    .sq-sug-item:hover { background:var(--accent-soft); }
    .sq-sug-item .sug-code { font-family:monospace; color:var(--ink-500); font-size:12px; }
    .sq-sug-item .sug-bal { font-weight:700; color:var(--pos); white-space:nowrap; }

    .sq-filters { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin:16px 0;
                  padding:14px 0; border-top:1px solid var(--line); border-bottom:1px solid var(--line); }
    .sq-filter { padding:9px 18px; border:1.5px solid var(--line); border-radius:22px; cursor:pointer;
                 background:var(--surface); color:var(--ink-700,var(--accent-text)); font-size:13.5px; font-weight:600;
                 user-select:none; transition:all 0.15s; }
    .sq-filter:hover { border-color:var(--accent-text); color:var(--accent-text); }
    .sq-filter.active { background:var(--accent); color:#fff; border-color:var(--accent-text); }
    .sq-sub { display:none; align-items:center; gap:6px; }
    .sq-sub.show { display:inline-flex; }
    .sq-sub select, .sq-sub input { padding:8px 12px; border:1.5px solid var(--line); border-radius:9px;
                                     font-size:13.5px; font-family:inherit; }
    .sq-sub input { width:90px; }

    .sq-table { width:100%; border-collapse:collapse; }
    .sq-table th, .sq-table td { padding:10px 12px; border-bottom:1px solid var(--line-soft);
                                  text-align:center; font-size:13px; white-space:nowrap; }
    .sq-table th { background:var(--line-soft); color:var(--accent-text); font-weight:600; }
    .sq-table th.admin, .sq-table td.admin { background:var(--accent-soft); font-weight:700; }
    .sq-table th.req, .sq-table td.req { background:rgba(34,197,94,0.07); }
    .sq-table .name-cell { text-align:right; font-weight:500; white-space:normal; min-width:200px; }
    .sq-table .code-cell { font-family:monospace; color:var(--ink-500); font-size:12px; }
    .sq-table .zero { color:var(--ink-400); }
    .sq-table .pos { color:var(--pos); font-weight:600; }
    .sq-table tr.totals td { background:var(--line-soft); font-weight:700; border-top:2px solid var(--line); }
    .sq-table tbody tr:hover td { background:rgba(124,111,247,0.04); }
    .sq-table td .rm { color:var(--neg); cursor:pointer; opacity:0.5; }
    .sq-table td .rm:hover { opacity:1; }
    .qty-inp { width:74px; padding:7px 8px; border:1.5px solid var(--line); border-radius:8px;
               font-size:14px; font-family:inherit; text-align:center; }
    .qty-inp:focus { outline:none; border-color:var(--pos); }

    .sq-status { font-size:13px; min-height:20px; margin-bottom:10px; }
    .sq-empty { text-align:center; color:var(--ink-400); padding:40px 20px; font-size:15px; }
    .sq-meta { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap;
               gap:12px; margin-bottom:12px; }
    .sq-meta .tools { display:flex; gap:10px; flex-wrap:wrap; }

    /* ===== بوب أب الطلب ===== */
    .shr-overlay { display:none; position:fixed; inset:0; background:rgba(22,35,61,0.55); z-index:200;
                   align-items:center; justify-content:center; padding:16px; }
    .shr-overlay.open { display:flex; }
    .shr-modal { background:var(--surface); border-radius:16px; width:100%; max-width:440px; max-height:92vh;
                 overflow-y:auto; padding:22px; box-shadow:0 24px 60px rgba(0,0,0,0.25); }
    .shr-modal h3 { color:var(--ink-900); font-size:17px; margin-bottom:16px; }
    .shr-fld { margin-bottom:14px; }
    .shr-fld label { display:block; font-size:13px; color:var(--ink-700); margin-bottom:6px; font-weight:600; }
    .shr-fld select, .shr-fld textarea { width:100%; padding:10px 12px; border:1.5px solid var(--line);
        border-radius:9px; font-family:inherit; font-size:14px; box-sizing:border-box; }
    .shr-fld textarea { min-height:70px; resize:vertical; }
    .shr-fixed { padding:10px 12px; background:var(--line-soft); border-radius:9px; font-size:14px;
                 color:var(--ink-900); font-weight:600; }
    .shr-summary { background:rgba(34,197,94,0.08); border-radius:10px; padding:10px 14px;
                   font-size:13.5px; color:var(--ink-700); margin-bottom:14px; }
    .shr-src-btn { flex:1; padding:9px 14px; border:1.5px solid var(--line); border-radius:9px;
                   background:var(--surface); color:var(--ink-700); font-size:13.5px; font-weight:600;
                   cursor:pointer; font-family:inherit; transition:all 0.15s; }
    .shr-src-btn:hover { border-color:var(--accent-text); color:var(--accent-text); background:var(--line-soft); }
    .shr-src-btn.active { background:var(--accent); color:#fff; border-color:var(--accent-text); }
    .shr-src-btn.active:hover { background:var(--accent-hover); color:#fff; }
    .shr-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:18px; }
    .shr-err { color:var(--neg); font-size:13px; min-height:18px; margin-top:8px; }
</style>

<?= book_tabs_bar('shortage') ?>

<div class="card">
    <div class="section-title"><i class="ti ti-shopping-cart"></i> طلب نواقص</div>

    <!-- بار البحث: كود أو اسم + كاميرا + إضافة -->
    <div class="sq-bar">
        <input type="text" class="main" id="q" autocomplete="off"
               placeholder="امسح باركود، أو اكتب كود/اسم الصنف…"
               enterkeyhint="search"
               oninput="onType()"
               onkeydown="if(isEnterKey(event)){event.preventDefault();addByCode();}">
        <button type="button" class="btn" style="background:var(--fill-strong);color:var(--fill-strong-fg);padding:11px 16px;"
                onclick="openBarcodeScanner('q')" title="مسح بالكاميرا">
            <i class="ti ti-camera" style="font-size:18px;"></i>
        </button>
        <button type="button" class="btn" style="background:var(--accent);" onclick="addByCode()">
            <i class="ti ti-plus"></i> إضافة
        </button>
        <div class="sq-sug" id="sug"></div>
    </div>
    <div class="sq-status" id="status"></div>

    <!-- الفلاتر -->
    <div class="sq-filters">
        <span class="sq-filter active" data-f="none" onclick="setFilter(this)">المضاف</span>
        <span class="sq-filter" data-f="has_stock" onclick="setFilter(this)">عندها رصيد</span>
        <span class="sq-sub" id="subBranch">
            <select id="branchPick" onchange="runFilter()">
                <option value="0">في كل المخازن</option>
                <?php foreach ($branches as $b): ?>
                    <option value="<?= (int)$b['sto_id'] ?>"><?= e($b['is_admin']?'الإدارة':$b['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </span>
        <span class="sq-filter" data-f="admin_only" onclick="setFilter(this)">في الإدارة فقط</span>
        <span class="sq-filter" data-f="zero" onclick="setFilter(this)">رصيد صفر</span>
        <span class="sq-filter" data-f="below" onclick="setFilter(this)">أقل من</span>
        <span class="sq-sub" id="subBelow">
            <input type="number" id="belowVal" value="5" min="0" onchange="runFilter()">
        </span>
    </div>

    <div class="sq-meta">
        <span id="count" style="color:var(--ink-500);font-size:13.5px;font-weight:600;"></span>
        <span class="tools">
            <button type="button" class="btn" id="reqBtn" style="background:var(--pos-fill);" onclick="openRequest()">
                <i class="ti ti-shopping-cart"></i> طلب نواقص
            </button>
            <button type="button" class="btn" style="background:var(--fill-strong);color:var(--fill-strong-fg);" onclick="exportCsv()">
                <i class="ti ti-download"></i> تصدير
            </button>
            <button type="button" class="btn" style="background:var(--neg-fill);" onclick="clearAll()">
                <i class="ti ti-trash"></i> تفريغ
            </button>
        </span>
    </div>

    <div style="overflow-x:auto;">
        <table class="sq-table" id="tbl">
            <thead>
                <tr>
                    <th style="width:34px;">#</th>
                    <th>الكود</th>
                    <th class="name-cell">اسم الصنف</th>
                    <th class="req">الكمية المطلوبة</th>
                    <?php foreach ($branches as $b): ?>
                        <th class="<?= $b['is_admin']?'admin':'' ?>"><?= e($b['is_admin']?'الإدارة':$b['name']) ?></th>
                    <?php endforeach; ?>
                    <th style="width:34px;"></th>
                </tr>
            </thead>
            <tbody id="body"></tbody>
        </table>
    </div>
</div>

<!-- ===== بوب أب طلب النواقص ===== -->
<div class="shr-overlay" id="shrOverlay" onclick="if(event.target===this)closeRequest()">
    <div class="shr-modal">
        <h3 id="shrModalTitle">🛒 إرسال طلب نواقص</h3>
        <div class="shr-summary" id="shrSummary"></div>

        <div class="shr-fld">
            <label>وجهة الطلب</label>
            <div style="display:flex;gap:8px;">
                <button type="button" class="shr-src-btn active" id="srcBranchBtn" onclick="setReqSource('branch')">من فرع تاني</button>
                <button type="button" class="shr-src-btn" id="srcPurchaseBtn" onclick="setReqSource('purchase')">من المشتريات</button>
            </div>
        </div>

        <div class="shr-fld" id="fromBranchFld">
            <label>الفرع الطالب (اللي ناقصه الأصناف)</label>
            <?php if ($myBranchId > 0):
                $myBranchName = '';
                foreach ($localBranches as $lb) if ((int)$lb['id'] === $myBranchId) $myBranchName = $lb['name'];
            ?>
                <div class="shr-fixed"><?= e($myBranchName ?: ('فرع #' . $myBranchId)) ?> (فرعك)</div>
                <input type="hidden" id="fromBranch" value="<?= $myBranchId ?>">
            <?php else: ?>
                <select id="fromBranch">
                    <option value="0">— اختار الفرع اللي انت واقف فيه —</option>
                    <?php foreach ($localBranches as $lb): ?>
                        <option value="<?= (int)$lb['id'] ?>"><?= e($lb['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </div>

        <div class="shr-fld" id="toBranchFld">
            <label>الفرع المطلوب منه (اللي هيجهّز)</label>
            <select id="toBranch">
                <option value="0">— اختار الفرع —</option>
                <?php foreach ($localBranches as $lb): ?>
                    <option value="<?= (int)$lb['id'] ?>"><?= e($lb['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="shr-fld">
            <label>ملاحظات (اختياري)</label>
            <textarea id="shrNotes" placeholder="أي تفاصيل زيادة…"></textarea>
        </div>

        <div class="shr-err" id="shrErr"></div>
        <div class="shr-actions">
            <button type="button" class="btn" style="background:var(--line);color:var(--ink-700);" onclick="closeRequest()">إلغاء</button>
            <button type="button" class="btn" id="shrSubmit" style="background:var(--pos-fill);" onclick="submitRequest()">
                <i class="ti ti-send"></i> طلب
            </button>
        </div>
    </div>
</div>

<script src="assets/barcode_scanner.js?v=7"></script>
<script>
    const BRANCHES  = <?= json_encode($branches, JSON_UNESCAPED_UNICODE) ?>;
    const ADMIN_STO = <?= (int) ADMIN_STO_ID ?>;
    const CSRF      = <?= json_encode(csrf_token()) ?>;
    const MY_BRANCH = <?= $myBranchId ?>;
    const NCOLS     = BRANCHES.length + 5;

    let added   = [];        // الأصناف اللي أضافها المستخدم
    let shown   = [];        // اللي معروض دلوقتي (مضاف أو نتيجة فلتر)
    let mode    = 'none';    // الفلتر الحالي
    let sugTimer = null;
    const reqQty  = {};      // code → الكمية المطلوبة (بتعيش مع إعادة الرسم)
    const rowByCode = {};    // code → آخر صف اتعرض بالبيانات دي

    // ===== البحث والإضافة =====
    function onType() {
        const v = document.getElementById('q').value.trim();
        clearTimeout(sugTimer);
        if (v.length < 2) { hideSug(); return; }
        // لو أرقام بس (باركود) مش هنقترح — Enter هيضيفه
        if (/^\d+$/.test(v)) { hideSug(); return; }
        sugTimer = setTimeout(() => fetchSug(v), 350);
    }

    function fetchSug(term) {
        fetch('shortage.php?ajax=search&term=' + encodeURIComponent(term))
            .then(r => r.json())
            .then(j => {
                const box = document.getElementById('sug');
                if (!j.ok || !j.rows.length) { hideSug(); return; }
                box.innerHTML = j.rows.map(r =>
                    '<div class="sq-sug-item" onclick=\'pick(' + JSON.stringify(r).replace(/'/g,"&#39;") + ')\'>' +
                      '<span><b>' + esc(r.name) + '</b><br><span class="sug-code">' + esc(r.code) + '</span></span>' +
                      '<span class="sug-bal">' + fmt(r.total) + '</span>' +
                    '</div>').join('');
                box.classList.add('show');
            })
            .catch(hideSug);
    }
    function hideSug() { document.getElementById('sug').classList.remove('show'); }

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
        setStatus('⏳ بيدور…', 'var(--ink-500)');
        fetch('shortage.php?ajax=code&code=' + encodeURIComponent(code))
            .then(r => r.json())
            .then(j => {
                if (j.ok) {
                    inp.value = '';
                    pushRow(j.row);
                    if (navigator.vibrate) navigator.vibrate(50);
                } else if (j.not_found) {
                    if (j.local_ready === false) {
                        setStatus('✗ الصنف ده مش في مرآة الإدارة. شغّل وكيل مزامنة الإدارة وجرّب تاني.', 'var(--neg)');
                    } else {
                        setStatus('✗ الكود ده مش موجود — جرّب تكتب اسم الصنف', 'var(--neg)');
                    }
                } else {
                    setStatus('✗ ' + (j.error || 'خطأ'), 'var(--neg)');
                }
            })
            .catch(() => setStatus('✗ مفيش اتصال', 'var(--neg)'))
            .finally(() => inp.focus());
    }

    function pushRow(row) {
        if (added.some(r => r.code === row.code)) {
            setStatus('⚠️ ' + esc(row.name) + ' مضاف قبل كده', 'var(--warn)');
            // بنركّز على خانة الكمية بتاعته بدل ما نتجاهله
            focusQty(row.code);
            return;
        }
        added.push(row);
        mode = 'none';
        document.querySelectorAll('.sq-filter').forEach(f =>
            f.classList.toggle('active', f.dataset.f === 'none'));
        hideSubs();
        render(added);
        setStatus('✓ اتضاف ' + esc(row.name), 'var(--pos)');
        focusQty(row.code);
    }

    function focusQty(code) {
        const el = document.querySelector('.qty-inp[data-code="' + CSS.escape(code) + '"]');
        if (el) { el.focus(); el.select(); }
    }

    function removeRow(code) {
        added = added.filter(r => r.code !== code);
        delete reqQty[code];
        render(mode === 'none' ? added : shown.filter(r => r.code !== code));
    }

    function onQty(code, val) {
        const q = parseFloat(val);
        if (q > 0) reqQty[code] = q; else delete reqQty[code];
        updateReqBtn();
    }
    function updateReqBtn() {
        const n = Object.keys(reqQty).length;
        const b = document.getElementById('reqBtn');
        b.innerHTML = '<i class="ti ti-shopping-cart"></i> طلب نواقص' + (n ? ' (' + n + ')' : '');
    }

    // ===== الفلاتر =====
    function setFilter(el) {
        mode = el.dataset.f;
        document.querySelectorAll('.sq-filter').forEach(f => f.classList.remove('active'));
        el.classList.add('active');
        hideSubs();
        if (mode === 'has_stock') document.getElementById('subBranch').classList.add('show');
        if (mode === 'below')     document.getElementById('subBelow').classList.add('show');
        runFilter();
    }
    function hideSubs() {
        document.getElementById('subBranch').classList.remove('show');
        document.getElementById('subBelow').classList.remove('show');
    }

    function runFilter() {
        if (mode === 'none') { render(added); return; }

        // لو فيه أصناف مضافة → نفلتر عليها محلياً
        if (added.length) { render(added.filter(passes)); return; }

        // مفيش أصناف مضافة → نجيب بالفلتر
        setStatus('⏳ بيجيب الأصناف…', 'var(--ink-500)');
        const p = new URLSearchParams({ajax:'filter', mode:mode});
        if (mode === 'has_stock') {
            const sto = +document.getElementById('branchPick').value || 0;
            if (sto > 0) { p.set('mode', 'branch_only'); p.set('sto', String(sto)); }
        }
        if (mode === 'below') p.set('threshold', document.getElementById('belowVal').value || '0');
        fetch('shortage.php?' + p.toString())
            .then(r => r.json())
            .then(j => {
                if (!j.ok) { setStatus('✗ ' + (j.error||'خطأ'), 'var(--neg)'); return; }
                render(j.rows);
                setStatus(j.rows.length ? '' : 'مفيش أصناف مطابقة', 'var(--ink-500)');
            })
            .catch(() => setStatus('✗ مفيش اتصال', 'var(--neg)'));
    }

    function passes(r) {
        const bal = r.balances || {}, total = +r.total || 0;
        const admin = +(bal[ADMIN_STO] || 0);
        switch (mode) {
            case 'has_stock': {
                const sto = +document.getElementById('branchPick').value || 0;
                return sto > 0 ? (+(bal[sto] || 0)) > 0.0001 : total > 0.0001;
            }
            case 'zero':      return total <= 0.0001;
            case 'admin_only':
                if (admin <= 0.0001) return false;
                return Object.keys(bal).every(s => (+s === ADMIN_STO) || (+bal[s] <= 0.0001));
            case 'below':
                return total < (+document.getElementById('belowVal').value || 0) - 0.0001;
        }
        return true;
    }

    // ===== العرض =====
    function render(list) {
        // نشيل الأصناف اللي رصيدها صفر من نتايج الفلترة —
        // بس اللي المستخدم ضافه بنفسه بيفضل ظاهر حتى بصفر
        list = (list || []);
        if (mode !== 'zero' && mode !== 'none') {
            list = list.filter(r => (+r.total || 0) > 0.0001);
        }
        shown = list;
        const body = document.getElementById('body');
        if (!shown.length) {
            body.innerHTML = '<tr><td colspan="' + NCOLS + '" class="sq-empty">' +
                (mode === 'none'
                    ? 'امسح باركود أو اكتب اسم صنف، اكتب الكمية المطلوبة، وبعدين اضغط "طلب نواقص"'
                    : 'مفيش أصناف مطابقة للفلتر') + '</td></tr>';
            document.getElementById('count').textContent = '';
            return;
        }
        const tot = {}; BRANCHES.forEach(b => tot[b.sto_id] = 0);
        let html = '';
        shown.forEach((r, i) => {
            rowByCode[r.code] = r;
            const bal = r.balances || {};
            const q = reqQty[r.code] || '';
            html += '<tr><td>' + (i+1) + '</td>' +
                    '<td class="code-cell">' + esc(r.code) + '</td>' +
                    '<td class="name-cell">' + esc(r.name) + '</td>' +
                    '<td class="req"><input type="number" class="qty-inp" min="0" step="any"' +
                        ' data-code="' + esc(r.code) + '" value="' + q + '"' +
                        ' placeholder="0" oninput="onQty(this.dataset.code, this.value)"></td>';
            BRANCHES.forEach(b => {
                const v = +(bal[b.sto_id] || 0);
                tot[b.sto_id] += v;
                html += '<td class="' + (b.is_admin?'admin ':'') + (v>0.0001?'pos':'zero') + '">' + fmt(v) + '</td>';
            });
            html += '<td><span class="rm" title="شيل" onclick="removeRow(\'' + esc(r.code) + '\')">✕</span></td></tr>';
        });
        html += '<tr class="totals"><td></td><td></td><td class="name-cell">الإجمالي</td><td class="req"></td>';
        BRANCHES.forEach(b => html += '<td class="' + (b.is_admin?'admin':'') + '">' + fmt(tot[b.sto_id]) + '</td>');
        html += '<td></td></tr>';
        body.innerHTML = html;
        document.getElementById('count').textContent = shown.length + ' صنف';
        updateReqBtn();
    }

    function clearAll() {
        if (!added.length && !shown.length) return;
        if (!confirm('تفريغ الجدول؟')) return;
        added = []; mode = 'none';
        Object.keys(reqQty).forEach(k => delete reqQty[k]);
        document.querySelectorAll('.sq-filter').forEach(f =>
            f.classList.toggle('active', f.dataset.f === 'none'));
        hideSubs(); render([]); setStatus('', '');
    }

    function exportCsv() {
        if (!shown.length) { fgToast('مفيش بيانات', 'warn'); return; }
        let csv = '\ufeff' + ['الكود','اسم الصنف','الكمية المطلوبة', ...BRANCHES.map(b => b.is_admin?'الإدارة':b.name)].join(',') + '\n';
        shown.forEach(r => {
            const bal = r.balances || {};
            csv += ['"'+r.code+'"', '"'+(r.name||'').replace(/"/g,'""')+'"', (reqQty[r.code]||0),
                    ...BRANCHES.map(b => +(bal[b.sto_id]||0))].join(',') + '\n';
        });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([csv], {type:'text/csv;charset=utf-8'}));
        a.download = 'طلب_نواقص_' + new Date().toISOString().slice(0,10) + '.csv';
        a.click();
    }

    // ===== بوب أب الطلب =====
    let reqSource = 'branch';

    function setReqSource(src) {
        reqSource = (src === 'purchase') ? 'purchase' : 'branch';
        const isPurch = (reqSource === 'purchase');
        document.getElementById('srcBranchBtn').classList.toggle('active', !isPurch);
        document.getElementById('srcPurchaseBtn').classList.toggle('active', isPurch);
        document.getElementById('toBranchFld').style.display = isPurch ? 'none' : 'block';
        document.getElementById('shrModalTitle').textContent = isPurch ? '🛒 طلب من المشتريات' : '🛒 إرسال طلب نواقص';
        document.getElementById('shrErr').textContent = '';
    }

    function requestLines() {
        return Object.keys(reqQty).map(code => {
            const r = rowByCode[code] || added.find(x => x.code === code) || {};
            return { code: code, name: r.name || '', qty: reqQty[code] };
        });
    }

    function openRequest() {
        const lines = requestLines();
        if (!lines.length) {
            setStatus('✗ اكتب الكمية المطلوبة لصنف واحد على الأقل الأول', 'var(--neg)');
            return;
        }
        setReqSource('branch');
        document.getElementById('shrSummary').textContent =
            'هتطلب ' + lines.length + ' صنف — راجع الكميات في الجدول قبل الإرسال.';
        document.getElementById('shrErr').textContent = '';
        document.getElementById('shrOverlay').classList.add('open');
    }
    function closeRequest() {
        document.getElementById('shrOverlay').classList.remove('open');
    }

    function submitRequest() {
        const err  = document.getElementById('shrErr');
        const from = +document.getElementById('fromBranch').value || 0;
        const to   = +document.getElementById('toBranch').value || 0;
        err.textContent = '';
        if (!from) { err.textContent = 'اختار الفرع الطالب.'; return; }
        if (reqSource === 'branch') {
            if (!to)   { err.textContent = 'اختار الفرع المطلوب منه.'; return; }
            if (from === to) { err.textContent = 'الفرعين مينفعش يكونوا نفس الفرع.'; return; }
        }

        const btn = document.getElementById('shrSubmit');
        btn.disabled = true;
        fetch('shortage.php?ajax=submit', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                csrf: CSRF,
                source: reqSource,
                from_branch: from,
                to_branch: reqSource === 'branch' ? to : 0,
                notes: document.getElementById('shrNotes').value,
                lines: requestLines(),
            }),
        })
        .then(r => r.json())
        .then(j => {
            if (!j.ok) { err.textContent = j.error || 'خطأ غير معروف'; return; }
            closeRequest();
            // نفضّي الطلب — الجدول بيفضل زي ما هو لو حابب يطلب من فرع تاني
            Object.keys(reqQty).forEach(k => delete reqQty[k]);
            document.getElementById('shrNotes').value = '';
            render(mode === 'none' ? added : shown);
            setStatus('✓ ' + (j.msg || 'تم إرسال الطلب') + ' — تقدر تتابعها من المهام', 'var(--pos)');
            if (navigator.vibrate) navigator.vibrate([60, 40, 60]);
        })
        .catch(() => { err.textContent = 'مفيش اتصال بالسيرفر.'; })
        .finally(() => { btn.disabled = false; });
    }

    function setStatus(msg, color) {
        const s = document.getElementById('status');
        s.textContent = msg; s.style.color = color || 'var(--ink-500)';
        if (msg && msg[0] === '✓') setTimeout(() => { if (s.textContent === msg) s.textContent = ''; }, 5000);
    }
    function fmt(n) { n = +n || 0; return Number.isInteger(n) ? n.toString() : n.toFixed(2); }
    function esc(s) {
        return String(s==null?'':s).replace(/[&<>"']/g, c =>
            ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    document.addEventListener('click', e => {
        if (!e.target.closest('.sq-bar')) hideSug();
    });

    render([]);
    document.getElementById('q').focus();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
