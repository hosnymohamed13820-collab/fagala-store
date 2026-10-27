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
        . '<h2>غير مصرّح</h2>'
        . '<p>ليس لديك صلاحية للوصول إلى هذه الصفحة.</p>'
        . '<a href="logout.php">تسجيل الخروج</a></div>');
}
require_once __DIR__ . '/stock_query_lib.php';

// الداتا (كود · بحث · فلتر) في stock_query_api.php — بريف ١١٠.

$branches = stock_branches_map();
// أي مخزن فيه رصيد ومش معرّف عندنا — يظهر كعمود "مخزن #رقم"
// عشان الأرصدة ماتختفيش لو فرع اتنسي في التعريفات.
$branches = array_merge($branches, stock_extra_stores(array_column($branches, 'sto_id')));

$page_title    = 'استعلام الأرصدة';
$active_module = 'reports';
require_once __DIR__ . '/includes/header.php';
?>
<style>
    /* الشكل كله من المكتبة الموحّدة — اللي هنا تخطيط الصفحة وأعمدة الجدول بس */
    .sq-bar { display:flex; gap:10px; align-items:center; margin-bottom:12px; position:relative;
              flex-wrap:wrap; }
    .sq-bar .finput { flex:1; min-width:260px; }
    /* نتائج البحث المنسدلة */
    .sq-sug { position:absolute; top:100%; right:0; left:0; background:var(--surface); z-index:50;
              border:1px solid var(--line); border-radius:var(--r-md); margin-top:4px; max-height:320px;
              overflow-y:auto; box-shadow:var(--shadow); display:none; }
    .sq-sug.show { display:block; }
    .sq-sug-item { padding:10px 14px; cursor:pointer; display:flex; justify-content:space-between;
                   gap:10px; border-bottom:1px solid var(--line-soft); font-size:var(--fs-md); }
    .sq-sug-item:hover { background:var(--accent-soft); }
    .sq-sug-item .sug-code { font-variant-numeric:tabular-nums; color:var(--ink-500); font-size:var(--fs-xs); }
    .sq-sug-item .sug-bal { font-weight:700; color:var(--pos); white-space:nowrap; }

    .sq-filters { display:flex; flex-wrap:wrap; gap:var(--sp-2); align-items:center; margin:16px 0;
                  padding:14px 0; border-top:1px solid var(--line); border-bottom:1px solid var(--line); }
    .sq-sub { display:none; align-items:center; gap:6px; }
    .sq-sub.show { display:inline-flex; }
    .sq-sub .fselect { width:auto; }
    .sq-sub .finput { width:90px; }

    /* أعمدة الجدول اللي مش في المكتبة */
    .ftable .admin { background:var(--accent-soft); font-weight:700; }
    .ftable .code-cell { font-variant-numeric:tabular-nums; color:var(--ink-500); font-size:var(--fs-xs); }
    .ftable .zero { color:var(--ink-400); }
    .ftable .pos { color:var(--pos); font-weight:600; }
    .ftable tr.totals td { background:var(--line-soft); font-weight:700; border-top:2px solid var(--line); }
    .ftable td .rm { color:var(--neg); cursor:pointer; opacity:0.5; }
    .ftable td .rm:hover { opacity:1; }

    .sq-status { font-size:var(--fs-base); min-height:20px; margin-bottom:10px; }
    .sq-meta { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap;
               gap:12px; margin-bottom:12px; }
    .sq-meta .tools { display:flex; gap:10px; }
</style>

<section class="fcard">
    <div class="fcard-head">
        <h2 class="fcard-title"><i class="ti ti-package"></i> استعلام أرصدة الأصناف</h2>
    </div>

    <!-- بار البحث: كود أو اسم + كاميرا + إضافة -->
    <div class="sq-bar">
        <input type="text" class="finput" id="q" autocomplete="off"
               placeholder="امسح باركود، أو اكتب كود/اسم الصنف…"
               enterkeyhint="search"
               oninput="onType()"
               onkeydown="if(isEnterKey(event)){event.preventDefault();addByCode();}">
        <button type="button" class="btn btn-primary" style="padding:11px 16px;"
                onclick="openBarcodeScanner('q')" title="مسح بالكاميرا">
            <i class="ti ti-camera" style="font-size:18px;"></i>
        </button>
        <button type="button" class="btn btn-primary" onclick="addByCode()">
            <i class="ti ti-plus"></i> إضافة
        </button>
        <div class="sq-sug" id="sug"></div>
    </div>
    <div class="sq-status" id="status"></div>

    <!-- الفلاتر: ظاهرة على طول -->
    <div class="sq-filters">
        <span class="ftab sq-filter is-active" data-f="none" onclick="setFilter(this)">المضاف</span>
        <span class="ftab sq-filter" data-f="has_stock" onclick="setFilter(this)">عندها رصيد</span>
        <span class="sq-sub" id="subBranch">
            <select class="fselect" id="branchPick" onchange="runFilter()">
                <option value="0">في كل المخازن</option>
                <?php foreach ($branches as $b): ?>
                    <option value="<?= (int)$b['sto_id'] ?>"><?= e($b['is_admin']?'الإدارة':$b['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </span>
        <span class="ftab sq-filter" data-f="admin_only" onclick="setFilter(this)">في الإدارة فقط</span>
        <span class="ftab sq-filter" data-f="zero" onclick="setFilter(this)">رصيد صفر</span>
        <span class="ftab sq-filter" data-f="below" onclick="setFilter(this)">أقل من</span>
        <span class="sq-sub" id="subBelow">
            <input type="number" class="finput num" id="belowVal" value="5" min="0" onchange="runFilter()">
        </span>
    </div>

    <div class="sq-meta">
        <span id="count" style="color:var(--ink-500);font-size:13.5px;font-weight:600;"></span>
        <span class="tools">
            <button type="button" class="btn btn-secondary" onclick="exportCsv()">
                <i class="ti ti-download"></i> تصدير
            </button>
            <button type="button" class="btn btn-danger" onclick="clearAll()">
                <i class="ti ti-trash"></i> تفريغ
            </button>
        </span>
    </div>

    <div class="ftable-wrap">
        <table class="ftable" id="tbl">
            <thead>
                <tr>
                    <th class="num" style="width:34px;">#</th>
                    <th>الكود</th>
                    <th>اسم الصنف</th>
                    <?php foreach ($branches as $b): ?>
                        <th class="num <?= $b['is_admin']?'admin':'' ?>"><?= e($b['is_admin']?'الإدارة':$b['name']) ?></th>
                    <?php endforeach; ?>
                    <th style="width:34px;"></th>
                </tr>
            </thead>
            <tbody id="body"></tbody>
        </table>
    </div>
</section>

<script src="assets/barcode_scanner.js?v=9"></script>
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
        apiFetch('stock_query_api.php?part=search&term=' + encodeURIComponent(term))
            .then(j => {
                const box = document.getElementById('sug');
                const rows = j.data.rows || [];
                if (!rows.length) { hideSug(); return; }
                box.innerHTML = rows.map(r =>
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
        setStatus('بيدور…', 'var(--ink-500)');
        apiFetch('stock_query_api.php?part=code&code=' + encodeURIComponent(code))
            .then(j => {
                if (j.data.row) {
                    inp.value = '';
                    pushRow(j.data.row);
                    if (navigator.vibrate) navigator.vibrate(50);
                } else {
                    setStatus('✗ الكود ده مش موجود — جرّب تكتب اسم الصنف', 'var(--neg)');
                }
            })
            .catch(apiErr)
            .finally(() => inp.focus());
    }

    function pushRow(row) {
        if (added.some(r => r.code === row.code)) {
            setStatus(esc(row.name) + ' مضاف قبل كده', 'var(--warn)');
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
        setStatus('بيجيب الأصناف…', 'var(--ink-500)');
        const p = new URLSearchParams({part:'filter', mode:mode});
        if (mode === 'has_stock') {
            // مخزن محدد → نستخدم فلتر الفرع في SQL
            const sto = +document.getElementById('branchPick').value || 0;
            if (sto > 0) { p.set('mode', 'branch_only'); p.set('sto', String(sto)); }
        }
        if (mode === 'below') p.set('threshold', document.getElementById('belowVal').value || '0');
        apiFetch('stock_query_api.php?' + p.toString())
            .then(j => {
                const rows = j.data.rows || [];
                render(rows);
                setStatus(rows.length ? '' : 'مفيش أصناف مطابقة', 'var(--ink-500)');
            })
            .catch(apiErr);
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
            body.innerHTML = '<tr><td colspan="' + NCOLS + '" class="fg-empty">' +
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
            html += '<tr><td class="num">' + (i+1) + '</td>' +
                    '<td class="code-cell">' + esc(r.code) + '</td>' +
                    '<td class="name">' + esc(r.name) + '</td>';
            BRANCHES.forEach(b => {
                const q = +(bal[b.sto_id] || 0);
                tot[b.sto_id] += q;
                html += '<td class="num ' + (b.is_admin?'admin ':'') + (q>0.0001?'pos':'zero') + '">' + fmt(q) + '</td>';
            });
            html += '<td><span class="rm" title="شيل" onclick="removeRow(\'' + esc(r.code) + '\')">✕</span></td></tr>';
        });
        html += '<tr class="totals"><td class="num"></td><td></td><td class="name">الإجمالي</td>';
        BRANCHES.forEach(b => html += '<td class="num ' + (b.is_admin?'admin':'') + '">' + fmt(tot[b.sto_id]) + '</td>');
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
        if (!shown.length) { fgToast('مفيش بيانات', 'warn'); return; }
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

    // فشل الـAPI: الجلسة خلصت ← الصفحة تتحوّل للدخول · من غير status = مفيش نت
    function apiErr(err) {
        if (err && err.code === 'auth') { location.reload(); return; }
        if (err && typeof err.status === 'undefined') { setStatus('✗ مفيش اتصال', 'var(--neg)'); return; }
        setStatus('✗ ' + ((err && err.error) || 'خطأ'), 'var(--neg)');
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
