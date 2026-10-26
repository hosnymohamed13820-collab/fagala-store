<?php
/**
 * item_movement_view.php — واجهة حركة الصنف في التقارير (بريف ٢٢٦)
 * منقولة من item_card.php (بريف ٢١٢)
 */
declare(strict_types=1);

require_once __DIR__ . '/../item_card_lib.php';

$period = ic_period(
    isset($_GET['from']) ? (string) $_GET['from'] : null,
    isset($_GET['to']) ? (string) $_GET['to'] : null
);
$types  = ic_types();

// رابط مباشر لحركة صنف: reports.php?type=item_movement&item_id=…&from=…&to=…&sto=…&mov_type=…
$initMovType = (string) ($_GET['mov_type'] ?? ($_GET['type'] ?? ''));
if ($initMovType === 'item_movement') {
    $initMovType = (string) ($_GET['mov_type'] ?? '');
}
$initQ = trim((string) ($_GET['q'] ?? ''));
$init = [
    'item_id' => (int) ($_GET['item_id'] ?? 0),
    'sto'     => (int) ($_GET['sto'] ?? 0),
    'type'    => isset($types[$initMovType]) ? $initMovType : '',
    'q'       => $initQ,
];
?>
<style>
    /* الصفحة بتستخدم .ftable و.fchip و.btn من fagala.css — مفيش تعريف جديد ليهم */

    .ic-bar { display:flex; gap:8px; align-items:flex-start; flex-wrap:wrap; }
    .ic-search-wrap { position:relative; flex:1; min-width:220px; }
    .ic-bar input.main {
        width:100%; box-sizing:border-box; padding:12px 16px;
        border:1.5px solid var(--line); border-radius:var(--r-md);
        background:var(--surface); color:var(--ink-800);
        font-size:15px; font-family:inherit;
    }
    .ic-bar input.main:focus { outline:none; border-color:var(--accent); }

    /* نتائج البحث المنسدلة */
    .ic-sug {
        position:absolute; top:100%; right:0; left:0; z-index:60;
        background:var(--surface); border:1px solid var(--line);
        border-radius:var(--r-md); margin-top:4px; max-height:340px; overflow-y:auto;
        box-shadow:var(--shadow); display:none;
    }
    .ic-sug.show { display:block; }
    .ic-sug-item {
        padding:9px 13px; cursor:pointer; display:flex; justify-content:space-between;
        gap:10px; border-bottom:1px solid var(--line-soft); font-size:13.5px;
    }
    .ic-sug-item:last-child { border-bottom:0; }
    .ic-sug-item:hover { background:var(--accent-soft); }
    .ic-sug-item .c { color:var(--ink-500); font-size:12px; direction:ltr; }

    .ic-status { font-size:13px; min-height:18px; margin-top:8px; color:var(--ink-500); }

    /* رأس الكارت */
    .ic-head-top { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; }
    .ic-name { margin:0 0 4px; font-size:19px; color:var(--ink-900); }
    .ic-sub { font-size:12.5px; color:var(--ink-500); }
    .ic-kpis { display:flex; gap:10px; flex-wrap:wrap; }
    .ic-kpi {
        background:var(--bg); border:1px solid var(--line); border-radius:var(--r);
        padding:8px 14px; min-width:120px;
    }
    .ic-kpi span { display:block; font-size:12px; color:var(--ink-500); margin-bottom:2px; }
    .ic-kpi b { font-size:17px; font-variant-numeric:tabular-nums; direction:ltr; display:inline-block; }

    .ic-block-title { font-size:14px; font-weight:700; color:var(--ink-700); margin:18px 0 8px; }
    .ic-note { font-size:12px; color:var(--ink-500); margin-top:8px; line-height:1.7; }
    .ic-est { font-size:11px; color:var(--ink-500); display:block; }
    .ic-empty { text-align:center; color:var(--ink-400); padding:26px 12px; font-size:14px; }

    .ic-filters { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; }
    .ic-filters label { font-size:12px; color:var(--ink-500); display:flex; flex-direction:column; gap:4px; }
    .ic-filters select, .ic-filters input {
        padding:8px 10px; border:1px solid var(--line); border-radius:var(--r-sm);
        background:var(--surface); color:var(--ink-800);
        font-size:13px; font-family:inherit; min-width:120px;
    }

    /* الموبايل: الجداول بتتحوّل كروت — مفيش سكرول بالعرض */
    @media (max-width: 560px) {
        .ic-card-tbl thead { display:none; }
        .ic-card-tbl, .ic-card-tbl tbody, .ic-card-tbl tr, .ic-card-tbl td { display:block; width:auto; }
        .ic-card-tbl tr {
            border-bottom:1px solid var(--line);
            padding:8px 2px;
        }
        .ic-card-tbl tr:last-child { border-bottom:0; }
        .ic-card-tbl td {
            border:0; padding:3px 10px; white-space:normal;
            display:flex; justify-content:space-between; align-items:baseline; gap:12px;
            text-align:right; direction:rtl;
        }
        .ic-card-tbl td::before {
            content:attr(data-l); color:var(--ink-500);
            font-size:11.5px; font-weight:600; flex:0 0 auto;
            direction:rtl; text-align:right;
        }
        .ic-card-tbl td.num { direction:ltr; text-align:left; }
        .ic-card-tbl td.num::before { direction:rtl; text-align:right; }
        .ic-card-tbl tfoot tr { border-top:2px solid var(--line); }
        .ic-ftable-wrap { border:0; }
        .ic-kpi { flex:1 1 44%; min-width:0; }
    }
</style>

<div class="card" style="margin-bottom:16px;">
    <div class="section-title"><i class="ti ti-list-details"></i> حركة الصنف</div>

    <div class="ic-bar">
        <div class="ic-search-wrap">
            <input type="text" class="main" id="icQ" autocomplete="off"
                   placeholder="امسح باركود، أو اكتب كود/اسم الصنف…"
                   enterkeyhint="search"
                   oninput="icOnType()"
                   onkeydown="if(isEnterKey(event)){event.preventDefault();icSearchNow();}">
            <div class="ic-sug" id="icSug"></div>
        </div>
        <button type="button" class="btn" style="background:var(--fill-strong);color:var(--fill-strong-fg);padding:11px 14px;"
                onclick="openBarcodeScanner('icQ')" title="مسح بالكاميرا">
            <i class="ti ti-camera" style="font-size:18px;"></i>
        </button>
        <button type="button" class="btn btn-primary" onclick="icSearchNow()">
            <i class="ti ti-search"></i> بحث
        </button>
    </div>
    <div class="ic-status" id="icStatus">امسح باركود أو اكتب اسم الصنف عشان تفتح كارتّه</div>
</div>

<div class="card" id="icFilterCard" style="margin-bottom:16px; display:none;">
    <div class="ic-filters">
        <label>من
            <input type="date" id="icFrom" value="<?= e($period['from']) ?>" onchange="icReload()">
        </label>
        <label>إلى
            <input type="date" id="icTo" value="<?= e($period['to']) ?>" onchange="icReload()">
        </label>
        <label>المخزن
            <select id="icSto" onchange="icReload()">
                <option value="0">كل المخازن</option>
            </select>
        </label>
        <label>نوع الحركة
            <select id="icType" onchange="icReload()">
                <option value="">كل الأنواع</option>
                <?php foreach ($types as $tk => $tl): ?>
                    <option value="<?= e($tk) ?>"><?= e($tl) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="button" class="btn btn-outline" id="icExport" onclick="icExport()">
            <i class="ti ti-download"></i> تصدير الحركات
        </button>
    </div>
</div>

<div id="icCard"></div>

<script src="assets/barcode_scanner.js?v=9"></script>
<script>
    let IC_ITEM = 0;          // الصنف المفتوح دلوقتي
    let IC_TIMER = null;      // مؤقت البحث
    const IC_INIT = <?= json_encode($init, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
    let IC_STO_OVERRIDE = null;   // مخزن جاي من رابط مباشر (أول تحميل بس)

    // ===== البحث =====
    function icOnType() {
        const v = document.getElementById('icQ').value.trim();
        clearTimeout(IC_TIMER);
        if (v.length < 2) { icHideSug(); return; }
        if (/^\d+$/.test(v)) { icHideSug(); return; }   // باركود — Enter بيفتحه
        IC_TIMER = setTimeout(() => icDoSearch(v), 300);
    }

    function icSearchNow() {
        const v = document.getElementById('icQ').value.trim();
        if (!v) return;
        icHideSug();
        icDoSearch(v);
    }

    function icDoSearch(term) {
        icStatus('بيدور…');
        apiFetch('item_card_api.php?q=' + encodeURIComponent(term))
            .then(j => {
                const d = j.data || {};
                if (d.exact) { icOpen(d.exact); return; }
                const rows = d.items || [];
                if (!rows.length) {
                    icStatus('✗ مفيش صنف بالكود أو الاسم ده', 'var(--neg)');
                    return;
                }
                icStatus(rows.length + ' نتيجة — اختار الصنف');
                icShowSug(rows);
            })
            .catch(icErr);
    }

    function icShowSug(rows) {
        const box = document.getElementById('icSug');
        box.innerHTML = rows.map(r =>
            '<div class="ic-sug-item" onclick="icOpen(' + (+r.item_id) + ')">' +
                '<span>' + icEsc(r.name) + '<br><span class="c">' + icEsc(r.code) + '</span></span>' +
                '<span class="c">' + icEsc(r.label) + '</span>' +
            '</div>').join('');
        box.classList.add('show');
    }

    function icHideSug() {
        document.getElementById('icSug').classList.remove('show');
    }

    // ===== الكارت =====
    function icOpen(itemId) {
        itemId = +itemId || 0;
        if (!itemId) return;
        IC_ITEM = itemId;
        icHideSug();
        document.getElementById('icFilterCard').style.display = 'block';
        icLoad();
    }

    function icReload() {
        if (IC_ITEM) icLoad();
    }

    function icLoad() {
        if (!IC_ITEM) return;
        icStatus('بيجيب الكارت…');
        const stoSel = document.getElementById('icSto');
        const p = new URLSearchParams({
            item_id: String(IC_ITEM),
            from: document.getElementById('icFrom').value || '',
            to:   document.getElementById('icTo').value || '',
            sto:  IC_STO_OVERRIDE !== null ? String(IC_STO_OVERRIDE) : (stoSel.value || '0'),
            type: document.getElementById('icType').value || '',
        });
        apiFetch('item_card_api.php?' + p.toString())
            .then(j => {
                const card = j.data ? j.data.card : null;
                if (!card) {
                    document.getElementById('icCard').innerHTML =
                        '<div class="card"><div class="ic-empty">الصنف ده مش موجود في المرآة</div></div>';
                    icStatus('✗ مش موجود', 'var(--neg)');
                    return;
                }
                icRender(card);
                icStatus('');
            })
            .catch(icErr);
    }

    function icRender(card) {
        const it = card.item || {};
        const locked = !!(card.sens && card.sens.locked);

        // فلتر المخازن بيتملى من الكارت نفسه (الإدارة + الفروع)
        const stoSel = document.getElementById('icSto');
        const cur = IC_STO_OVERRIDE !== null ? String(IC_STO_OVERRIDE) : (stoSel.value || '0');
        stoSel.innerHTML = '<option value="0">كل المخازن</option>' +
            (card.stores || []).map(s =>
                '<option value="' + (+s.sto_id) + '">' + icEsc(s.name) + '</option>').join('');
        stoSel.value = cur;
        if (!stoSel.value) stoSel.value = '0';
        IC_STO_OVERRIDE = null;

        let html = '';

        // ---------- الرأس ----------
        html += '<div class="card" style="margin-bottom:16px;">' +
            '<div class="ic-head-top">' +
                '<div>' +
                    '<h2 class="ic-name">' + icEsc(it.name) + '</h2>' +
                    '<div class="ic-sub">' +
                        (it.category ? icEsc(it.category) + ' · ' : '') +
                        'كود: <span dir="ltr">' + icEsc(it.base_code || '—') + '</span>' +
                        (it.barcode && it.barcode !== it.base_code ? ' · باركود: <span dir="ltr">' + icEsc(it.barcode) + '</span>' : '') +
                        ' · رقم الصنف: ' + (+it.item_id) +
                    '</div>' +
                '</div>' +
                '<div class="ic-kpis">' +
                    '<div class="ic-kpi"><span>سعر البيع</span><b>' + (it.sale_price_html || '—') + '</b></div>' +
                    '<div class="ic-kpi"><span>متوسط التكلفة</span><b>' + (it.cost_html || '—') + '</b></div>' +
                '</div>' +
            '</div>' +
            (locked
                ? '<div class="ic-note">التكلفة والأسعار مقفولة — دوس على الرقم أو Ctrl+L عشان تفتحها.</div>'
                : '');

        // أكواد الصنف
        const codes = it.codes || [];
        html += '<div class="ic-block-title">أكواد الصنف (' + codes.length + ')</div>';
        if (!codes.length) {
            html += '<div class="ic-empty">الصنف ده مالوش أكواد في المرآة</div>';
        } else {
            html += '<div class="ftable-wrap ic-ftable-wrap"><table class="ftable ic-card-tbl" data-xt-off>' +
                '<thead><tr>' +
                    '<th>الكود</th><th>النوع</th><th class="num">المعامل</th>' +
                    '<th class="num">السعر</th><th class="num">التكلفة</th>' +
                '</tr></thead><tbody>' +
                codes.map(c =>
                    '<tr>' +
                        '<td data-l="الكود" dir="ltr">' + icEsc(c.code) + '</td>' +
                        '<td data-l="النوع"><span class="fchip ' + (c.is_base ? 'fchip-pos' : 'fchip-mute') + '">' +
                            icEsc(c.type_label) + '</span></td>' +
                        '<td class="num" data-l="المعامل">' + icNum(c.factor) + '</td>' +
                        '<td class="num" data-l="السعر">' + (c.price_html || '—') + '</td>' +
                        '<td class="num" data-l="التكلفة">' + (c.cost_html || '—') + '</td>' +
                    '</tr>').join('') +
                '</tbody></table></div>';
        }
        html += '</div>';

        // ---------- الأرصدة ----------
        html += '<div class="card" style="margin-bottom:16px;">' +
            '<div class="ic-block-title" style="margin-top:0;">الرصيد دلوقتي</div>' +
            '<div class="ftable-wrap ic-ftable-wrap"><table class="ftable ic-card-tbl" data-xt-off>' +
            '<thead><tr><th>المخزن</th><th class="num">الرصيد</th>' +
            '<th class="num">حركة الفترة</th><th>آخر تحديث</th></tr></thead><tbody>' +
            (card.stores || []).map(s =>
                '<tr>' +
                    '<td data-l="المخزن">' + icEsc(s.name) + '</td>' +
                    '<td class="num" data-l="الرصيد">' + icEsc(s.balance_txt) + '</td>' +
                    '<td class="num" data-l="حركة الفترة" style="color:' +
                        ((+s.moved > 0) ? 'var(--pos)' : ((+s.moved < 0) ? 'var(--neg)' : 'var(--ink-500)')) + '">' +
                        icSigned(+s.moved) + '</td>' +
                    '<td data-l="آخر تحديث">' + icEsc(s.updated || '—') + '</td>' +
                '</tr>').join('') +
            '</tbody><tfoot><tr>' +
                '<td data-l="الإجمالي"><b>الإجمالي</b></td>' +
                '<td class="num" data-l="الرصيد"><b>' + icEsc(card.totals.balance_txt) + '</b></td>' +
                '<td class="num" data-l="حركة الفترة"><b>' + icSigned(+card.totals.moved) + '</b></td>' +
                '<td data-l="آخر تحديث">' + icEsc(card.totals.updated || '—') + '</td>' +
            '</tr></tfoot></table></div>' +
            '<div class="ic-note">الرصيد بالقطعة. عمود «حركة الفترة» مجموع حركات الفترة، مش الرصيد — ' +
                'من ' + icEsc(card.period.from) + ' لـ ' + icEsc(card.period.to) + '.</div>' +
            '</div>';

        // ---------- الحركات ----------
        const movs = card.movements || [];
        html += '<div class="card">' +
            '<div class="ic-head-top">' +
                '<div class="ic-block-title" style="margin:0;">الحركات (' + movs.length + ')</div>' +
                ((card.counts && card.counts.truncated)
                    ? '<span class="fchip fchip-warn">ظاهر أول ' + (+card.counts.limit) + ' — ضيّق الفترة</span>'
                    : '') +
            '</div>';

        if (!movs.length) {
            html += '<div class="ic-empty">مفيش حركة في الفترة دي</div>';
        } else {
            html += '<div class="ftable-wrap ic-ftable-wrap" style="margin-top:10px;">' +
                '<table class="ftable ic-card-tbl" data-xt-off><thead><tr>' +
                '<th>التاريخ</th><th>المخزن</th><th>النوع</th><th>الطرف التاني</th>' +
                '<th>المستند</th><th class="num">الكمية</th><th>الوحدة الأصلية</th><th class="num">السعر</th>' +
                '</tr></thead><tbody>' +
                movs.map(m => icMoveRow(m)).join('') +
                '</tbody></table></div>';
        }
        html += '<div class="ic-note">الكمية بالقطعة دايماً: + داخل المخزن · − خارجه. ' +
            '«الوحدة الأصلية» = الكمية اللي اتكتبت في الفاتورة × معامل الوحدة.</div>';
        html += '</div>';

        document.getElementById('icCard').innerHTML = html;
    }

    function icMoveRow(m) {
        let unit = icEsc(m.unit);
        if (m.estimated) {
            unit += '<span class="ic-est">المعامل متقدّر من الكود</span>';
        } else if (m.source === 'unknown') {
            unit = '<span class="fchip fchip-warn">وحدة مش معروفة</span>';
        }
        return '<tr>' +
            '<td data-l="التاريخ" dir="ltr">' + icEsc(m.date) + '</td>' +
            '<td data-l="المخزن">' + icEsc(m.store || '—') + '</td>' +
            '<td data-l="النوع"><span class="fchip ' + icEsc(m.chip) + '">' + icEsc(m.label) + '</span></td>' +
            '<td data-l="الطرف التاني">' + icEsc(m.party || '—') + '</td>' +
            '<td data-l="المستند" dir="ltr">' + icEsc(m.doc || '—') + '</td>' +
            '<td class="num" data-l="الكمية" style="color:' +
                ((+m.qty > 0) ? 'var(--pos)' : ((+m.qty < 0) ? 'var(--neg)' : 'var(--ink-500)')) + '">' +
                icEsc(m.qty_txt) + '</td>' +
            '<td data-l="الوحدة الأصلية">' + unit + '</td>' +
            '<td class="num" data-l="السعر">' + (m.price_html || '—') + '</td>' +
        '</tr>';
    }

    // ===== التصدير =====
    function icExport() {
        if (!IC_ITEM) { icStatus('افتح كارت صنف الأول', 'var(--warn)'); return; }
        const p = new URLSearchParams({
            part: 'xlsx',
            item_id: String(IC_ITEM),
            from: document.getElementById('icFrom').value || '',
            to:   document.getElementById('icTo').value || '',
            sto:  document.getElementById('icSto').value || '0',
            type: document.getElementById('icType').value || '',
        });
        window.location.href = 'item_card_api.php?' + p.toString();
    }

    // ===== مساعدات =====
    function icStatus(msg, color) {
        const el = document.getElementById('icStatus');
        el.textContent = msg || '';
        el.style.color = color || 'var(--ink-500)';
    }

    function icNum(n) {
        n = +n || 0;
        return (Math.round(n * 1000) / 1000).toString();
    }

    function icSigned(n) {
        n = +n || 0;
        if (!n) return '0';
        return (n > 0 ? '+' : '−') + icNum(Math.abs(n));
    }

    function icEsc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c =>
            ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function icErr(err) {
        if (err && err.code === 'auth') { location.reload(); return; }
        if (err && err.code === 'forbidden') { icStatus('✗ مالكش صلاحية حركة الصنف', 'var(--neg)'); return; }
        icStatus('✗ ' + ((err && err.error) || 'مفيش اتصال'), 'var(--neg)');
    }

    document.addEventListener('click', e => {
        if (!e.target.closest('.ic-search-wrap')) icHideSug();
    });

    // رابط مباشر: نفتح الكارت على طول لو الرابط فيه صنف أو بحث.
    // ⚠️ لازم بعد تحميل app.js (بيوصل في الفوتر) — وإلا apiFetch مش موجودة.
    if (IC_INIT.item_id > 0) {
        document.addEventListener('DOMContentLoaded', function () {
            if (IC_INIT.type) document.getElementById('icType').value = IC_INIT.type;
            IC_STO_OVERRIDE = IC_INIT.sto > 0 ? IC_INIT.sto : null;
            icOpen(IC_INIT.item_id);
        });
    } else if (IC_INIT.q) {
        document.addEventListener('DOMContentLoaded', function () {
            document.getElementById('icQ').value = IC_INIT.q;
            icSearchNow();
        });
    }

    document.getElementById('icQ').focus();
</script>
