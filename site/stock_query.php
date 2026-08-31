<?php
/**
 * stock_query.php — استعلام أرصدة الأصناف في الإدارة والفروع
 * نظام الفجالة ستور المالي
 *
 * بار بحث واحد فوق الجدول (بالكود أو الاسم) + كاميرا + زر إضافة.
 * الفلاتر أزرار ظاهرة على طول: لو مفيش أصناف مضافة، الفلتر بيجيب
 * الأصناف المطابقة من نظام الشركة مباشرة (الفلترة في SQL).
 * تابعة لموديول التقارير.
 */
require_once __DIR__ . '/auth.php';
require_module_access('reports');
if (!can('reports:stock')) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;text-align:center;padding:60px;direction:rtl">'
        . '<h2>🚫 غير مصرّح</h2>'
        . '<p>ليس لديك صلاحية للوصول إلى هذه الصفحة.</p>'
        . '<a href="logout.php">تسجيل الخروج</a></div>');
}
require_once __DIR__ . '/stock_query_lib.php';

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
                    : ['ok' => false, 'not_found' => true], JSON_UNESCAPED_UNICODE);
                break;

            // اقتراحات البحث بالاسم (منسدلة)
            case 'search':
                $rows = stock_search_by_name((string) ($_GET['term'] ?? ''), 25);
                echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
                break;

            // فلترة من نظام الشركة (لما مفيش أصناف مضافة)
            case 'filter':
                $rows = stock_filter_items(
                    (string) ($_GET['mode'] ?? ''),
                    (int) ($_GET['sto'] ?? 0),
                    (float) ($_GET['threshold'] ?? 0)
                );
                echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
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
// أي مخزن فيه رصيد ومش معرّف عندنا — يظهر كعمود "مخزن #رقم"
// عشان الأرصدة ماتختفيش لو فرع اتنسي في التعريفات.
$branches = array_merge($branches, stock_extra_stores(array_column($branches, 'sto_id')));

$page_title    = 'استعلام الأرصدة';
$active_module = 'reports';
require_once __DIR__ . '/includes/header.php';
?>
<style>
    /* زر النظام الموحد (نفس تعريف باقي الشاشات) */
    .btn { background:var(--accent); color:#fff; border:none; padding:11px 20px; border-radius:10px;
        font-size:14px; font-family:inherit; cursor:pointer; display:inline-flex; align-items:center;
        gap:7px; text-decoration:none; white-space:nowrap; }
    .btn:hover { filter:brightness(0.94); }
    .btn i { font-size:16px; }

    .sq-bar { display:flex; gap:10px; align-items:center; margin-bottom:12px; position:relative;
              flex-wrap:wrap; }
    .sq-bar input.main { flex:1; min-width:260px; padding:12px 16px; border:1.5px solid var(--line);
                         border-radius:10px; font-size:15px; font-family:inherit; }
    .sq-bar input.main:focus { outline:none; border-color:var(--accent-text); }
    /* نتائج البحث المنسدلة */
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
    .sq-table .name-cell { text-align:right; font-weight:500; white-space:normal; min-width:200px; }
    .sq-table .code-cell { font-family:monospace; color:var(--ink-500); font-size:12px; }
    .sq-table .zero { color:var(--ink-400); }
    .sq-table .pos { color:var(--pos); font-weight:600; }
    .sq-table tr.totals td { background:var(--line-soft); font-weight:700; border-top:2px solid var(--line); }
    .sq-table tbody tr:hover td { background:rgba(124,111,247,0.04); }
    .sq-table td .rm { color:var(--neg); cursor:pointer; opacity:0.5; }
    .sq-table td .rm:hover { opacity:1; }

    .sq-status { font-size:13px; min-height:20px; margin-bottom:10px; }
    .sq-empty { text-align:center; color:var(--ink-400); padding:40px 20px; font-size:15px; }
    .sq-meta { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap;
               gap:12px; margin-bottom:12px; }
    .sq-meta .tools { display:flex; gap:10px; }
</style>

<div class="card">
    <div class="section-title"><i class="ti ti-package"></i> استعلام أرصدة الأصناف</div>

    <!-- بار البحث: كود أو اسم + كاميرا + إضافة -->
    <div class="sq-bar">
        <input type="text" class="main" id="q" autocomplete="off"
               placeholder="امسح باركود، أو اكتب كود/اسم الصنف…"
               enterkeyhint="search"
               oninput="onType()"
               onkeydown="if(isEnterKey(event)){event.preventDefault();addByCode();}">
        <button type="button" class="btn" style="background:var(--ink-800);padding:11px 16px;"
                onclick="openBarcodeScanner('q')" title="مسح بالكاميرا">
            <i class="ti ti-camera" style="font-size:18px;"></i>
        </button>
        <button type="button" class="btn" style="background:var(--accent);" onclick="addByCode()">
            <i class="ti ti-plus"></i> إضافة
        </button>
        <div class="sq-sug" id="sug"></div>
    </div>
    <div class="sq-status" id="status"></div>

    <!-- الفلاتر: ظاهرة على طول -->
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
            <button type="button" class="btn" style="background:var(--ink-800);" onclick="exportCsv()">
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

<script src="assets/barcode_scanner.js?v=4"></script>
<script>
    const BRANCHES  = <?= json_encode($branches, JSON_UNESCAPED_UNICODE) ?>;
    const ADMIN_STO = <?= (int) ADMIN_STO_ID ?>;
    const NCOLS     = BRANCHES.length + 4;

    let added   = [];        // الأصناف اللي أضافها المستخدم
    let shown   = [];        // اللي معروض دلوقتي (مضاف أو نتيجة فلتر)
    let mode    = 'none';    // الفلتر الحالي
    let sugTimer = null;

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
        fetch('stock_query.php?ajax=search&term=' + encodeURIComponent(term))
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
        fetch('stock_query.php?ajax=code&code=' + encodeURIComponent(code))
            .then(r => r.json())
            .then(j => {
                if (j.ok) {
                    inp.value = '';
                    pushRow(j.row);
                    if (navigator.vibrate) navigator.vibrate(50);
                } else if (j.not_found) {
                    setStatus('✗ الكود ده مش موجود — جرّب تكتب اسم الصنف', 'var(--neg)');
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
            return;
        }
        added.push(row);
        // الإضافة بترجّعنا لعرض المضاف
        mode = 'none';
        document.querySelectorAll('.sq-filter').forEach(f =>
            f.classList.toggle('active', f.dataset.f === 'none'));
        hideSubs();
        render(added);
        setStatus('✓ اتضاف ' + esc(row.name), 'var(--pos)');
    }

    function removeRow(code) {
        added = added.filter(r => r.code !== code);
        render(mode === 'none' ? added : shown.filter(r => r.code !== code));
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

        // مفيش أصناف مضافة → نجيب من نظام الشركة بالفلتر
        setStatus('⏳ بيجيب الأصناف…', 'var(--ink-500)');
        const p = new URLSearchParams({ajax:'filter', mode:mode});
        if (mode === 'has_stock') {
            // مخزن محدد → نستخدم فلتر الفرع في SQL
            const sto = +document.getElementById('branchPick').value || 0;
            if (sto > 0) { p.set('mode', 'branch_only'); p.set('sto', String(sto)); }
        }
        if (mode === 'below') p.set('threshold', document.getElementById('belowVal').value || '0');
        fetch('stock_query.php?' + p.toString())
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
                // لو اتحدد مخزن معين، نفلتر عليه؛ غير كده أي رصيد في أي مخزن
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
        // نشيل الأصناف اللي رصيدها صفر — ملهاش لازمة في نتايج الفلترة.
        // بس الأصناف اللي المستخدم ضافها بنفسه (mode='none') بتفضل ظاهرة
        // حتى لو رصيدها صفر — ده بالظبط اللي هو عايز يعرفه لما يمسح باركود.
        list = (list || []);
        if (mode !== 'zero' && mode !== 'none') {
            list = list.filter(r => (+r.total || 0) > 0.0001);
        }
        shown = list;
        const body = document.getElementById('body');
        if (!shown.length) {
            body.innerHTML = '<tr><td colspan="' + NCOLS + '" class="sq-empty">' +
                (mode === 'none'
                    ? 'امسح باركود أو اكتب اسم صنف، أو اختار فلتر عشان تشوف الأصناف'
                    : 'مفيش أصناف مطابقة للفلتر') + '</td></tr>';
            document.getElementById('count').textContent = '';
            return;
        }
        const tot = {}; BRANCHES.forEach(b => tot[b.sto_id] = 0);
        let html = '';
        shown.forEach((r, i) => {
            const bal = r.balances || {};
            html += '<tr><td>' + (i+1) + '</td>' +
                    '<td class="code-cell">' + esc(r.code) + '</td>' +
                    '<td class="name-cell">' + esc(r.name) + '</td>';
            BRANCHES.forEach(b => {
                const q = +(bal[b.sto_id] || 0);
                tot[b.sto_id] += q;
                html += '<td class="' + (b.is_admin?'admin ':'') + (q>0.0001?'pos':'zero') + '">' + fmt(q) + '</td>';
            });
            html += '<td><span class="rm" title="شيل" onclick="removeRow(\'' + esc(r.code) + '\')">✕</span></td></tr>';
        });
        html += '<tr class="totals"><td></td><td></td><td class="name-cell">الإجمالي</td>';
        BRANCHES.forEach(b => html += '<td class="' + (b.is_admin?'admin':'') + '">' + fmt(tot[b.sto_id]) + '</td>');
        html += '<td></td></tr>';
        body.innerHTML = html;
        document.getElementById('count').textContent = shown.length + ' صنف';
    }

    function clearAll() {
        if (!added.length && !shown.length) return;
        if (!confirm('تفريغ الجدول؟')) return;
        added = []; mode = 'none';
        document.querySelectorAll('.sq-filter').forEach(f =>
            f.classList.toggle('active', f.dataset.f === 'none'));
        hideSubs(); render([]); setStatus('', '');
    }

    function exportCsv() {
        if (!shown.length) { alert('مفيش بيانات'); return; }
        let csv = '\ufeff' + ['الكود','اسم الصنف', ...BRANCHES.map(b => b.is_admin?'الإدارة':b.name)].join(',') + '\n';
        shown.forEach(r => {
            const bal = r.balances || {};
            csv += ['"'+r.code+'"', '"'+(r.name||'').replace(/"/g,'""')+'"',
                    ...BRANCHES.map(b => +(bal[b.sto_id]||0))].join(',') + '\n';
        });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([csv], {type:'text/csv;charset=utf-8'}));
        a.download = 'ارصدة_الاصناف_' + new Date().toISOString().slice(0,10) + '.csv';
        a.click();
    }

    function setStatus(msg, color) {
        const s = document.getElementById('status');
        s.textContent = msg; s.style.color = color || 'var(--ink-500)';
        if (msg && msg[0] === '✓') setTimeout(() => { if (s.textContent === msg) s.textContent = ''; }, 3000);
    }
    function fmt(n) { n = +n || 0; return Number.isInteger(n) ? n.toString() : n.toFixed(2); }
    function esc(s) {
        return String(s==null?'':s).replace(/[&<>"']/g, c =>
            ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    // إخفاء الاقتراحات عند الضغط بره
    document.addEventListener('click', e => {
        if (!e.target.closest('.sq-bar')) hideSug();
    });

    render([]);
    document.getElementById('q').focus();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
