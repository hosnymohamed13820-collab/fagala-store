<?php
/**
 * book_orders.php — طلبيات الكتب (تاب ٢ في موديول النواقص)
 * نظام الفجالة ستور المالي
 *
 * =====================================================================
 *  الشاشة شغالة بنفس طريقة "طلب النواقص" بالظبط:
 *    تبدأ فاضية → تدوّر على الكتاب بالاسم → يتضاف صف →
 *    الصف بيوريك كمية كل مورد جنب بعض وانت بتكتب.
 *
 *  الكتاب اللي ملوش طلبية بيتضاف عادي من غير كميات، والشاشة بتقولك
 *  كده صريح — وانت اللي بتكتب هتجيب منه كام من كل مورد.
 *
 *  البحث بالاسم لأن الموردين بيتعاملوا بالاسم مش بالكود الدولي.
 *
 *  ---------------------------------------------------------------
 *  عمود واحد لكل مورد — 2026-08-09
 *  ---------------------------------------------------------------
 *  كان لكل مورد عمودين: "مطلوب" (req_qty) و"المورد" (supplier_qty،
 *  للأونر بس). ده كان ملخبط ومحدش فهم الفرق، فبقى عمود واحد
 *  = الكمية المطلوبة (req_qty) وهو اللي أزرار النسخ والعدّادات
 *  بتشتغل عليه أصلاً.
 *
 *  عمود supplier_qty **لسه في الداتابيز زي ما هو** ومفيش داتا
 *  اتمسحت، و book_save_qty() لسه بتقبل field='sup' — فرجوعه
 *  لو اتطلب = إضافة الخانة في الرسم بس.
 * =====================================================================
 */
require_once __DIR__ . '/auth.php';
require_permission('shortage');
require_once __DIR__ . '/book_lib.php';

$uid = (int) current_user()['id'];

// ===== AJAX =====
$ajax = $_GET['ajax'] ?? '';
if ($ajax !== '') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        switch ($ajax) {
            // بحث بالاسم — بيرجّع الكتاب ومعاه كمية كل مورد
            case 'search':
                $rows = book_catalog_with_orders((string) ($_GET['term'] ?? ''), 25);
                foreach ($rows as &$r) {
                    // "ليه طلبية" = فيه مورد واحد على الأقل بكمية أكبر من صفر
                    $has = false;
                    foreach ($r['sup'] as $s) { if ((float) $s['req'] > 0) { $has = true; break; } }
                    $r['has_order'] = $has;
                }
                unset($r);
                echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
                break;

            // حفظ خانة واحدة (كمية مطلوبة / كمية مورد)
            case 'save':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('طلب غير صالح.');
                if (!csrf_verify((string) ($_POST['csrf'] ?? ''))) {
                    throw new RuntimeException('انتهت صلاحية الجلسة — اعمل ريفريش.');
                }
                $raw = trim((string) ($_POST['qty'] ?? ''));
                book_save_qty(
                    (int) ($_POST['book'] ?? 0),
                    (int) ($_POST['supplier'] ?? 0),
                    (string) ($_POST['field'] ?? 'req'),
                    $raw === '' ? null : (float) $raw,
                    $uid
                );
                // النسخ بقى في المتصفح من اللي على الشاشة، فمفيش
                // حاجة تترد هنا غير التأكيد.
                echo json_encode(['ok' => true]);
                break;

            default:
                echo json_encode(['ok' => false, 'error' => 'طلب غير معروف']);
        }
    } catch (Throwable $ex) {
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$suppliers = book_suppliers();

$page_title    = 'طلبيات الكتب';
$active_module = 'shortage';
require_once __DIR__ . '/includes/header.php';
?>
<style>
    .btn { background:var(--accent); color:#fff; border:none; padding:11px 20px; border-radius:10px;
        font-size:14px; font-family:inherit; cursor:pointer; display:inline-flex; align-items:center;
        gap:7px; text-decoration:none; white-space:nowrap; }
    .btn:hover { filter:brightness(0.94); }
    .btn:disabled { opacity:.55; cursor:not-allowed; }

    .bo-bar { display:flex; gap:10px; align-items:center; margin-bottom:10px; position:relative;
              flex-wrap:wrap; }
    .bo-bar input.main { flex:1; min-width:250px; padding:12px 16px; border:1.5px solid var(--line);
                         border-radius:10px; font-size:15px; font-family:inherit; }
    .bo-bar input.main:focus { outline:none; border-color:var(--accent); }
    .bo-sug { position:absolute; top:100%; right:0; left:0; background:#fff; z-index:50;
              border:1px solid var(--line); border-radius:10px; margin-top:4px; max-height:340px;
              overflow-y:auto; box-shadow:0 8px 24px rgba(30,27,75,.12); display:none; }
    .bo-sug.show { display:block; }
    .bo-sug-item { padding:10px 14px; cursor:pointer; display:flex; justify-content:space-between;
                   gap:10px; align-items:center; border-bottom:1px solid var(--line-soft);
                   font-size:14px; }
    .bo-sug-item:hover { background:var(--accent-soft); }
    .bo-sug-item .c { font-family:monospace; color:var(--ink-500); font-size:12px; }

    .bo-copybar { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }

    .bo-table { width:100%; border-collapse:collapse; }
    .bo-table th, .bo-table td { padding:9px 10px; border-bottom:1px solid var(--line-soft);
                                 text-align:center; font-size:13.5px; white-space:nowrap; }
    .bo-table th { background:var(--line-soft); color:var(--accent-hover); font-weight:600; }
    .bo-table th.sup-e, .bo-table td.sup-e { border-left:2px solid var(--line); }
    .bo-table .nm { text-align:right; font-weight:500; min-width:210px; white-space:normal; }
    .bo-table .nm .pub { display:block; font-size:11.5px; color:var(--ink-500); font-weight:400; }
    .bo-table .cd { font-family:monospace; color:var(--ink-500); font-size:12px; }
    .bo-table tbody tr:hover td { background:rgba(124,111,247,.04); }
    .bo-table td .rm { color:var(--neg); cursor:pointer; opacity:.5; }
    .bo-table td .rm:hover { opacity:1; }

    .q-inp { width:74px; padding:7px 8px; border:1.5px solid var(--line); border-radius:8px;
             font-size:14px; font-family:inherit; text-align:center; }
    .q-inp:focus { outline:none; border-color:var(--pos); }
    .q-ind { font-size:12px; margin-right:4px; }

    .tag-no { display:inline-block; padding:3px 10px; border-radius:20px; font-size:11.5px;
              font-weight:700; background:rgba(217,119,6,.13); color:var(--warn,#d97706); }

    .bo-empty { text-align:center; color:#bbb; padding:40px 20px; font-size:15px; }
    .bo-status { font-size:13px; min-height:20px; margin-bottom:10px; }
    .bo-toast { position:fixed; bottom:24px; left:50%; transform:translateX(-50%);
                background:var(--ink-900,#16233d); color:#fff; padding:13px 24px; border-radius:12px;
                font-size:14px; z-index:400; opacity:0; pointer-events:none; transition:opacity .2s; }
    .bo-toast.show { opacity:1; }
</style>

<?= book_tabs_bar('books') ?>

<div class="card" style="margin-bottom:16px;">
    <div class="section-title"><i class="ti ti-book"></i> طلبيات الكتب</div>

    <!-- البحث والإضافة -->
    <div class="bo-bar">
        <input type="text" class="main" id="q" autocomplete="off"
               placeholder="اكتب اسم الكتاب…"
               enterkeyhint="search"
               oninput="onType()"
               onkeydown="if(isEnterKey(event)){event.preventDefault();addFirst();}">
        <div class="bo-sug" id="sug"></div>
    </div>
    <div class="bo-status" id="status"></div>

    <div style="overflow-x:auto;">
        <table class="bo-table">
            <thead>
                <tr>
                    <th style="width:34px;">#</th>
                    <th>الكود</th>
                    <th class="nm">اسم الكتاب</th>
                    <?php foreach ($suppliers as $s): ?>
                        <th class="sup-e"><?= e($s['name']) ?></th>
                    <?php endforeach; ?>
                    <th style="width:34px;"></th>
                </tr>
            </thead>
            <tbody id="body"></tbody>
        </table>
    </div>

    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;
                flex-wrap:wrap;margin-top:14px;">
        <span id="count" style="color:var(--ink-500);font-size:13.5px;font-weight:600;"></span>
        <button type="button" class="btn" style="background:var(--neg);" onclick="clearAll()">
            <i class="ti ti-trash"></i> تفريغ الشاشة
        </button>
    </div>
    <div style="font-size:12px;color:var(--ink-500);margin-top:8px;">
        الكميات بتتحفظ لحظياً مع كل خانة. "تفريغ الشاشة" بيمسح العرض بس — الطلبيات المحفوظة ما بتتلمسش.
    </div>
</div>

<div class="card">
    <div class="section-title">نسخ الطلبيات</div>
    <p style="color:var(--ink-500);font-size:13px;margin-bottom:14px;line-height:1.8;">
        كل زرار بينسخ <b>اللي على الشاشة فوق بس</b> — الكتب اللي ضفتها بكمياتها عند المورد ده.
        سطر لكل كتاب بالشكل <span style="font-family:monospace;">اسم الكتاب × الكمية</span>،
        والكتب اللي كميتها فاضية أو صفر عند المورد ده مابتنزلش.
    </p>
    <div class="bo-copybar">
        <?php foreach ($suppliers as $s): ?>
            <button type="button" class="btn cpy" data-sup="<?= (int)$s['id'] ?>" disabled
                    style="background:var(--pos);"
                    onclick="copyOrder(<?= (int)$s['id'] ?>, this)">
                <i class="ti ti-copy"></i> طلبية <?= e($s['name']) ?>
                <span class="cnt" data-sup="<?= (int)$s['id'] ?>" style="opacity:.8;font-size:12px;">(0)</span>
            </button>
        <?php endforeach; ?>
        <?php if (!$suppliers): ?>
            <span style="color:var(--ink-500);font-size:14px;">
                مفيش موردين — شغّل <span style="font-family:monospace;">upgrade_book_orders.sql</span> الأول.
            </span>
        <?php endif; ?>
    </div>
</div>

<div class="bo-toast" id="toast"></div>

<script>
    const CSRF      = <?= json_encode(csrf_token()) ?>;
    const SUPPLIERS = <?= json_encode(array_map(fn($s) => ['id' => (int)$s['id'], 'name' => $s['name']], $suppliers), JSON_UNESCAPED_UNICODE) ?>;
    const NCOLS     = 4 + SUPPLIERS.length;

    let added    = [];   // الكتب المضافة للشاشة
    let lastRows = [];   // آخر نتايج بحث (عشان الاختيار بالرقم مش بلزق JSON في الـHTML)
    let lastTerm = '';   // الكلمة اللي طلّعت lastRows — عشان Enter مايختارش نتيجة بايتة
    let sugTimer = null;
    const qty    = {};   // "bookId:supId:field" → القيمة (بتعيش مع إعادة الرسم)

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g,
            c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
    function setStatus(t, c) {
        const el = document.getElementById('status');
        el.textContent = t; el.style.color = c || 'var(--ink-500)';
    }
    function toast(msg) {
        const t = document.getElementById('toast');
        t.textContent = msg; t.classList.add('show');
        clearTimeout(t._h);
        t._h = setTimeout(() => t.classList.remove('show'), 2200);
    }
    function num(v) {
        if (v === null || v === undefined || v === '') return '';
        const n = parseFloat(v);
        if (!n) return '';
        return (n === Math.round(n)) ? String(Math.round(n)) : String(n);
    }

    // ===== البحث =====
    function onType() {
        const v = document.getElementById('q').value.trim();
        clearTimeout(sugTimer);
        // النتايج القديمة بقت بايتة دلوقتي — امسحها عشان Enter مايختارش
        // كتاب من بحث قديم مالوش علاقة باللي المستخدم بيكتبه
        if (v !== lastTerm) { lastRows = []; lastTerm = ''; }
        if (v.length < 2) { hideSug(); return; }
        sugTimer = setTimeout(() => fetchSug(v), 350);
    }

    function fetchSug(term) {
        fetch('book_orders.php?ajax=search&term=' + encodeURIComponent(term))
            .then(r => r.json())
            .then(j => {
                const box = document.getElementById('sug');
                lastRows = (j.ok && j.rows) ? j.rows : [];
                lastTerm = term;
                if (!lastRows.length) {
                    box.innerHTML = '<div class="bo-sug-item" style="cursor:default;color:var(--ink-500);">' +
                                    'مفيش كتاب بالاسم ده في الكتالوج.</div>';
                    box.classList.add('show');
                    return;
                }
                box.innerHTML = lastRows.map((r, i) =>
                    '<div class="bo-sug-item" onclick="pick(' + i + ')">' +
                      '<span><b>' + esc(r.name) + '</b><br><span class="c">' + esc(r.code) +
                        (r.publisher ? ' · ' + esc(r.publisher) : '') + '</span></span>' +
                      (r.has_order ? '' : '<span class="tag-no">ملوش طلبية</span>') +
                    '</div>').join('');
                box.classList.add('show');
            })
            .catch(hideSug);
    }
    function hideSug() { document.getElementById('sug').classList.remove('show'); }

    function pick(i) {
        const row = lastRows[i];
        if (!row) return;
        hideSug();
        clearTimeout(sugTimer);
        lastRows = []; lastTerm = '';
        document.getElementById('q').value = '';
        pushRow(row);
        document.getElementById('q').focus();
    }

    // Enter = أول نتيجة
    function addFirst() {
        const v = document.getElementById('q').value.trim();
        if (v.length < 2) return;                       // حرف واحد مش بحث
        if (lastRows.length && lastTerm === v) { pick(0); return; }
        setStatus('⏳ بيدور…');
        clearTimeout(sugTimer);                          // ماتعملش نفس البحث مرتين
        fetch('book_orders.php?ajax=search&term=' + encodeURIComponent(v))
            .then(r => r.json())
            .then(j => {
                lastRows = (j.ok && j.rows) ? j.rows : [];
                lastTerm = v;
                if (lastRows.length) pick(0);
                else setStatus('✗ مفيش كتاب بالاسم ده في الكتالوج', 'var(--neg)');
            })
            .catch(() => setStatus('✗ مفيش اتصال', 'var(--neg)'));
    }

    function pushRow(row) {
        if (added.some(r => r.id === row.id)) {
            setStatus('⚠️ ' + row.name + ' مضاف قبل كده', 'var(--warn,#d97706)');
            focusFirstQty(row.id);
            return;
        }
        added.unshift(row);   // الأحدث فوق

        // الكميات المحفوظة في الداتابيز تنزل في الخانات
        SUPPLIERS.forEach(s => {
            const cell = row.sup ? row.sup[s.id] : null;
            qty[row.id + ':' + s.id + ':req'] = cell ? num(cell.req) : '';
        });

        if (row.has_order) {
            setStatus('✓ اتضاف ' + row.name, 'var(--pos)');
        } else {
            setStatus('⚠️ ' + row.name + ' ملوش طلبية — اتضاف فاضي، اكتب هتجيب منه كام من كل مورد',
                      'var(--warn,#d97706)');
        }
        render();
        focusFirstQty(row.id);
    }

    function focusFirstQty(bookId) {
        const i = document.querySelector('.q-inp[data-book="' + bookId + '"][data-field="req"]');
        if (i) { i.focus(); i.select(); }
    }

    function removeRow(bookId) {
        added = added.filter(r => r.id !== bookId);
        render();
    }

    function clearAll() {
        if (added.length && !confirm('تفريغ الشاشة؟ (الطلبيات المحفوظة مش هتتمسح)')) return;
        added = [];
        setStatus('');
        render();
    }

    // ===== الرسم =====
    function render() {
        const body = document.getElementById('body');
        if (!added.length) {
            body.innerHTML = '<tr><td colspan="' + NCOLS + '" class="bo-empty">' +
                'اكتب اسم الكتاب فوق عشان تضيفه وتشوف كمية كل مورد.</td></tr>';
            document.getElementById('count').textContent = '';
            updateCounts();
            return;
        }

        body.innerHTML = added.map((r, i) => {
            let tds = '';
            SUPPLIERS.forEach(s => {
                const kReq = r.id + ':' + s.id + ':req';
                tds += '<td class="sup-e">' +
                    '<input type="number" class="q-inp" min="0" step="1" ' +
                        'aria-label="' + esc(s.name) + ' — الكمية المطلوبة" ' +
                        'value="' + esc(qty[kReq] || '') + '" ' +
                        'data-book="' + r.id + '" data-sup="' + s.id + '" data-field="req" ' +
                        'oninput="onQty(this)" onblur="saveQty(this)">' +
                    '<span class="q-ind"></span></td>';
            });

            return '<tr>' +
                '<td>' + (i + 1) + '</td>' +
                '<td class="cd">' + esc(r.code) + '</td>' +
                '<td class="nm">' + esc(r.name) +
                    '<span class="pub">' +
                        (r.publisher ? esc(r.publisher) : '') +
                        (r.term ? (r.publisher ? ' · ' : '') + esc(r.term) : '') +
                        (r.has_order ? '' : ' <span class="tag-no">ملوش طلبية</span>') +
                    '</span></td>' +
                tds +
                '<td><span class="rm" title="شيل من الشاشة" onclick="removeRow(' + r.id + ')">✕</span></td>' +
            '</tr>';
        }).join('');

        document.getElementById('count').textContent = added.length + ' كتاب على الشاشة';
        updateCounts();
    }

    // ===== الحفظ =====
    function onQty(inp) {
        qty[inp.dataset.book + ':' + inp.dataset.sup + ':' + inp.dataset.field] = inp.value.trim();
        updateCounts();   // العدّاد بيتحرك مع الكتابة، مش مستني الحفظ
    }

    function saveQty(inp) {
        const ind = inp.parentElement.querySelector('.q-ind');
        const key = inp.dataset.book + ':' + inp.dataset.sup + ':' + inp.dataset.field;
        const val = inp.value.trim();
        // القيمة اللي جت من الداتابيز محفوظة أصلاً — مانبعتش حفظ من غير تغيير
        const prev = inp.dataset.saved !== undefined ? inp.dataset.saved : (qty[key] || '');
        if (val === prev) return;

        ind.textContent = '⏳'; ind.style.color = 'var(--ink-500)';

        fetch('book_orders.php?ajax=save', {
            method: 'POST',
            body: new URLSearchParams({
                csrf: CSRF, book: inp.dataset.book, supplier: inp.dataset.sup,
                field: inp.dataset.field, qty: val
            })
        })
        .then(r => r.json())
        .then(j => {
            if (j.ok) {
                inp.dataset.saved = val;
                qty[key] = val;
                ind.textContent = '✓'; ind.style.color = 'var(--pos)';
                setTimeout(() => { ind.textContent = ''; }, 1400);
                updateCounts();
                markHasOrder(inp.dataset.book);
            } else {
                ind.textContent = '✗'; ind.style.color = 'var(--neg)';
                toast('✗ ' + (j.error || 'فشل الحفظ'));
            }
        })
        .catch(() => {
            ind.textContent = '✗'; ind.style.color = 'var(--neg)';
            toast('✗ مفيش اتصال');
        });
    }

    // أول ما تتكتب كمية، علامة "ملوش طلبية" تتشال
    function markHasOrder(bookId) {
        const b = added.find(r => String(r.id) === String(bookId));
        if (!b || b.has_order) return;
        const any = SUPPLIERS.some(s => parseFloat(qty[bookId + ':' + s.id + ':req']) > 0);
        if (any) { b.has_order = true; render(); }
    }


    // ===== نسخ نص الطلبية =====
    //
    //  بيتبني من **اللي على الشاشة بس**، مش من الداتابيز.
    //
    //  قبل كده كان بيسحب الطلبية كاملة من السيرفر — يعني كل الـ312
    //  كتاب المتراكمين، مش الكتب اللي انت لسه ضايفها. ده مكانش
    //  مفيد: انت بتجهّز طلبية النهاردة، مش بتبعت الأرشيف كله.
    //
    //  الترتيب زي ما هو على الشاشة (الأحدث فوق) عشان اللي تنسخه
    //  يبقى مطابق للي شايفه بالظبط.
    function orderLines(sup) {
        var out = [];
        added.forEach(function (r) {
            var v = parseFloat(qty[r.id + ':' + sup + ':req']);
            if (!v || v <= 0) return;
            var q = (v === Math.round(v)) ? String(Math.round(v)) : String(v);
            out.push(r.name.trim() + ' × ' + q);
        });
        return out;
    }

    // العدّاد على كل زرار = عدد كتب المورد ده على الشاشة دلوقتي
    function updateCounts() {
        SUPPLIERS.forEach(function (s) {
            var n = orderLines(s.id).length;
            document.querySelectorAll('.cnt[data-sup="' + s.id + '"]').forEach(function (el) {
                el.textContent = '(' + n + ')';
            });
            document.querySelectorAll('.cpy[data-sup="' + s.id + '"]').forEach(function (b) {
                b.disabled = (n === 0);
            });
        });
    }

    function copyOrder(sup, btn) {
        var lines = orderLines(sup);
        if (!lines.length) { toast('⚠️ مفيش كتاب بكمية للمورد ده على الشاشة'); return; }

        copyText(lines.join('\n'));

        var old = btn.innerHTML;
        btn.innerHTML = '<i class="ti ti-check"></i> اتنسخت';
        toast('✓ اتنسخ ' + lines.length + ' سطر — الزقها في واتساب');
        setTimeout(function () { btn.innerHTML = old; }, 1800);
    }

    // clipboard API محتاجة HTTPS — بنرجع لطريقة قديمة على اللوكال (http)
    // عشان الزرار مايبوظش.
    function copyText(txt) {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(txt).catch(() => fallbackCopy(txt));
        } else {
            fallbackCopy(txt);
        }
    }
    function fallbackCopy(txt) {
        const ta = document.createElement('textarea');
        ta.value = txt;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
    }

    document.addEventListener('click', e => { if (!e.target.closest('.bo-bar')) hideSug(); });

    render();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
