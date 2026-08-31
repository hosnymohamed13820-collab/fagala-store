/* =====================================================================
 *  xtable.js — خصائص جداول الإكسيل لكل جداول النظام
 *  فجالة ستور · 2026-08-19
 *
 *  بيشتغل لوحده على كل جدول مؤهّل في أي صفحة — من غير أي تعديل PHP.
 *  الملف ده بيحقن الستايل بتاعه بنفسه، فمحتاج سطر واحد في footer.php بس.
 *
 *  ── اللي بيعمله ──────────────────────────────────────────────────
 *    • فلتر لكل عمود (قايمة القيم الفريدة + شخاطات + «بس ده»)
 *    • ترتيب تصاعدي/تنازلي بالضغط على رأس العمود
 *    • بحث عام على الجدول كله
 *    • عدّاد «ظاهر ٤٧ من ٦١٣»
 *    • زرار Σ لمجموع الأعمدة الرقمية **للصفوف الظاهرة بس**
 *    • تحذير لو حمّلت ملف والفلتر شغّال
 *
 *  ── مبادئ حاكمة (RULES.md) ───────────────────────────────────────
 *    • صفر نداءات سيرفر. كل حاجة في المتصفح. مالوش أي علاقة بالفايرول.
 *    • مفيش حفظ للفلتر بين الصفحات — تفتح الشاشة تلاقيها كاملة دايماً.
 *      (لو الفلتر اتحفظ، حد هيفتح الشاشة ويفتكر إن الداتا ناقصة.)
 *    • مابيلمسش أي <input> ولا بيمسح أي صف — الإخفاء بصري بحت،
 *      والصف المخفي لسه بيتبعت مع الفورم عادي. مفيش داتا بتضيع.
 *    • مابيلمسش صف الإجمالي (tfoot) — يفضل على الكل زي ما هو.
 *
 *  ── إزاي تستثني جدول ────────────────────────────────────────────
 *      <table data-xt-off>            ← المكتبة تعديه خالص
 *      <th data-xt-skip>              ← العمود ده مالوش فلتر (زي عمود الأزرار)
 *      <tr data-xt-ignore>            ← الصف ده مش صف داتا
 *
 *  ── المستثنى تلقائياً ───────────────────────────────────────────
 *      • جداول الإدخال اللي بتضاف ليها صفوف (class="repeatable")
 *      • جداول عندها أكورديون خاص ([data-branchhead]) — زي قايمة
 *        فواتير الاستلامات، عندها فلتر خاص بيها وتوحيدها شغل لوحده
 *      • أي جدول أقل من 6 صفوف — مالوش لازمة
 * ===================================================================== */
(function () {
    'use strict';

    /* علم بيوقف المراقب وإحنا بنعدّل الـDOM بنفسنا (منع لوب).
       معرّف هنا فوق عشان يبان بوضوح — مش معتمدين على الـhoisting. */
    var watching = false, wtmr = null;

    var CFG = {
        minRows:   6,     // أقل من كده مفيش فلتر
        maxValues: 400,   // أقصى عدد قيم في لوحة عمود واحد
        lazyBuild: true
    };

    /* ================================================================
       1) التطبيع العربي
       ملاحظة: من غير ده، البحث عن «احمد» مش هيلاقي «أحمد»،
       و«١٢٣» مش هتلاقي «123».
       ================================================================ */
    function norm(s) {
        if (s == null) return '';
        return String(s)
            .replace(/[\u0660-\u0669]/g, function (d) {          // أرقام عربية
                return String.fromCharCode(d.charCodeAt(0) - 0x0630);
            })
            .replace(/[\u06F0-\u06F9]/g, function (d) {          // أرقام فارسية
                return String.fromCharCode(d.charCodeAt(0) - 0x06C0);
            })
            .replace(/[\u064B-\u0652\u0640\u0670]/g, '')         // تشكيل + تطويل
            .replace(/[\u0623\u0625\u0622\u0671]/g, '\u0627')    // أ إ آ ← ا
            .replace(/\u0649/g, '\u064A')                        // ى ← ي
            .replace(/\u0629/g, '\u0647')                        // ة ← ه
            .replace(/\u0624/g, '\u0648')                        // ؤ ← و
            .replace(/\u0626/g, '\u064A')                        // ئ ← ي
            .replace(/\s+/g, ' ')
            .trim()
            .toLowerCase();
    }

    var NUM_RE = /^-?\d{1,3}(?:[,\u066C]\d{3})+(?:\.\d+)?$|^-?\d+(?:\.\d+)?$/;

    function asNum(s) {
        var t = norm(s).replace(/[,\u066C\s]/g, '');
        if (t === '' || !NUM_RE.test(t)) return null;
        var v = parseFloat(t);
        return isNaN(v) ? null : v;
    }

    function fmtNum(v) {
        if (!isFinite(v)) return '—';
        var r = Math.round(v * 1000) / 1000;
        var s = (Math.abs(r) >= 1000)
            ? r.toLocaleString('en-US', { maximumFractionDigits: 3 })
            : String(r);
        return s;
    }

    function txt(el) {
        // نتجاهل نص الأزرار والأيقونات — مش داتا
        var t = '';
        for (var i = 0; i < el.childNodes.length; i++) {
            var n = el.childNodes[i];
            if (n.nodeType === 3) { t += n.nodeValue; continue; }
            if (n.nodeType !== 1) continue;
            var tag = n.tagName;
            if (tag === 'BUTTON' || tag === 'I' || tag === 'SVG') continue;
            if (tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA') {
                if (tag === 'SELECT' && n.selectedIndex >= 0 && n.options[n.selectedIndex]) {
                    t += ' ' + n.options[n.selectedIndex].text;
                } else if (n.type === 'checkbox' || n.type === 'radio') {
                    /* تجاهل */
                } else {
                    t += ' ' + (n.value || '');
                }
                continue;
            }
            t += ' ' + txt(n);
        }
        return t;
    }

    /* ================================================================
       2) تصنيف الصفوف
       صف الداتا = خلاياه بعدد الأعمدة. أي صف بـcolspan (رأس مجموعة،
       «مفيش نتائج»، صف إجمالي داخلي) بيتعامل كصف خاص ومابيتفلترش.
       ================================================================ */
    function rowKind(tr, cols) {
        // ثلاث حالات مش اتنين:
        //   data    = صف عادي بيتفلتر ويترتب
        //   ignore  = صف يتعدّى وخلاص (إجماليات) — **الترتيب يفضل متاح**
        //   special = بنية بتتكسر لو رتّبنا (رؤوس مجموعات · صف تفاصيل
        //             مربوط بالصف اللي فوقه) — الترتيب بيتقفل
        //
        // كان الاتنين بيرجّعوا 'skip' فصف الإجماليات لوحده كان بيقفل
        // زرار الترتيب في كل تقارير النظام. صف متثبّت تحت مابيمنعش
        // ترتيب اللي فوقه.
        // hasAttribute مش dataset: السمة من غير قيمة بتدّي نص فاضي
        // و "" بتتقرا false. ده وقع في الاختبار.
        if (tr.hasAttribute('data-xt-nosort')) return 'special';
        if (tr.hasAttribute('data-xt-ignore')) return 'ignore';
        if (tr.dataset.branchhead || tr.dataset.subhead || tr.dataset.acckey) return 'special';
        var cells = tr.children;
        if (cells.length === 0) return 'ignore';
        // أي صف عدد خلاياه أقل من عدد الأعمدة = فيه دمج = مش صف داتا.
        // (صف «مفيش نتائج» بـcolspan كان بيعدّي كداتا — اتكشف في الاختبار.)
        if (cells.length < cols) return 'special';
        return 'data';
    }

    /* ================================================================
       3) الأهلية
       ================================================================ */
    function eligible(t) {
        if (t.hasAttribute('data-xt-off') || t.closest('[data-xt-off]')) return false;
        if (t.classList.contains('repeatable') || t.hasAttribute('data-repeatable')) return false;
        if (t.classList.contains('xt-on')) return false;              // اتعمل قبل كده
        if (t.querySelector('[data-branchhead]')) return false;       // أكورديون خاص
        if (t.parentElement && t.parentElement.closest('table')) return false; // جدول جوه جدول

        var head = t.tHead;
        if (!head || !head.rows.length) return false;
        var hrow = head.rows[head.rows.length - 1];
        if (!hrow || hrow.cells.length < 2) return false;

        // رأس بصفين وdمج أعمدة = بنية معقدة، نسيبها
        if (head.rows.length > 1) {
            for (var i = 0; i < hrow.cells.length; i++) {
                if ((parseInt(hrow.cells[i].getAttribute('colspan') || '1', 10) || 1) > 1) return false;
            }
        }

        var body = t.tBodies[0];
        if (!body) return false;

        var cols = 0;
        for (var c = 0; c < hrow.cells.length; c++) {
            cols += parseInt(hrow.cells[c].getAttribute('colspan') || '1', 10) || 1;
        }
        if (cols !== hrow.cells.length) return false;   // رأس مدموج

        var n = 0;
        for (var r = 0; r < body.rows.length; r++) {
            if (rowKind(body.rows[r], cols) === 'data') n++;
        }
        // `data-xt-min` بتخلّي الجدول يتجاوز الحد الافتراضي.
        // السبب: جداول التقارير للقراءة والتحليل، والترتيب وزرار Σ
        // مفيدين فيها حتى في صف واحد. الحد الافتراضي معمول لجداول
        // الإدخال في الشاشات التانية.
        var minRows = CFG.minRows;
        var attrMin = t.getAttribute('data-xt-min');
        if (attrMin !== null && attrMin !== '') {
            var v = parseInt(attrMin, 10);
            if (!isNaN(v) && v >= 1) minRows = v;
        }
        return n >= minRows;
    }

    /* ================================================================
       4) بناء الجدول
       ================================================================ */
    function build(table) {
        var head  = table.tHead;
        var hrow  = head.rows[head.rows.length - 1];
        var cols  = hrow.cells.length;
        var body  = table.tBodies[0];

        var st = {
            table: table, hrow: hrow, cols: cols, body: body,
            rows: [],            // {tr, cells:[نص مطبّع], nums:[رقم|null]}
            picks: {},           // idx -> {قيمة مختارة: true}
            colType: [],         // 'num' | 'text'
            skip: [],            // أعمدة بلا فلتر
            q: '',
            sortIdx: -1, sortDir: 0,
            sumOn: false,
            hasSpecial: false
        };
        table.__xt = st;
        table.classList.add('xt-on');

        // ---- فهرسة الصفوف مرة واحدة
        for (var r = 0; r < body.rows.length; r++) {
            var tr = body.rows[r];
            var kind = rowKind(tr, cols);
            if (kind !== 'data') {
                // 'ignore' بيتعدّى من غير ما يقفل الترتيب
                if (kind === 'special') st.hasSpecial = true;
                continue;
            }
            var cellsN = [], nums = [];
            for (var c = 0; c < cols; c++) {
                var td = tr.cells[c];
                var raw = td ? txt(td) : '';
                cellsN.push(norm(raw));
                nums.push(asNum(raw));
            }
            st.rows.push({ tr: tr, cells: cellsN, nums: nums, on: true });
        }
        if (!st.rows.length) return;

        // ---- نوع كل عمود
        for (var c2 = 0; c2 < cols; c2++) {
            var th = hrow.cells[c2];
            st.skip[c2] = th.hasAttribute('data-xt-skip') || norm(txt(th)) === '';
            var numeric = 0, filled = 0;
            for (var i = 0; i < st.rows.length; i++) {
                if (st.rows[i].cells[c2] === '') continue;
                filled++;
                if (st.rows[i].nums[c2] !== null) numeric++;
            }
            st.colType[c2] = (filled > 0 && numeric === filled) ? 'num' : 'text';
        }

        buildBar(st);
        buildHeads(st);
        /* (١١) لو فيه فلتر محفوظ، tryRestore بتطبّقه وبتنادي apply بنفسها.
               لو ما فيش، نادي apply العادي عشان العدّاد والحدث يشتغلوا. */
        if (!tryRestore(st)) apply(st);
    }


    /* ---- الشريط العلوي ---------------------------------------- */
    function buildBar(st) {
        var bar = document.createElement('div');
        bar.className = 'xt-bar';
        bar.innerHTML =
            '<div class="xt-search">' +
              '<i class="ti ti-search"></i>' +
              '<input type="text" placeholder="بحث في الجدول…" autocomplete="off">' +
              '<button type="button" class="xt-qclear" title="مسح البحث">&times;</button>' +
            '</div>' +
            '<span class="xt-count"></span>' +
            '<span class="xt-flag" hidden><i class="ti ti-filter"></i> فلتر شغّال</span>' +
            '<button type="button" class="xt-btn xt-sum" title="مجموع الصفوف الظاهرة">&#931;</button>' +
            '<button type="button" class="xt-btn xt-reset" hidden>مسح الفلاتر</button>';

        var host = st.table.parentNode;
        host.insertBefore(bar, st.table);
        st.bar = bar;

        var inp = bar.querySelector('input');
        var tmr = null;
        inp.addEventListener('input', function () {
            clearTimeout(tmr);
            tmr = setTimeout(function () { st.q = norm(inp.value); apply(st); }, 120);
        });
        bar.querySelector('.xt-qclear').addEventListener('click', function () {
            inp.value = ''; st.q = ''; apply(st); inp.focus();
        });
        bar.querySelector('.xt-reset').addEventListener('click', function () {
            st.picks = {}; st.q = ''; inp.value = '';
            var ths = st.hrow.cells;
            for (var i = 0; i < ths.length; i++) ths[i].classList.remove('xt-active');
            apply(st);
        });
        bar.querySelector('.xt-sum').addEventListener('click', function () {
            st.sumOn = !st.sumOn;
            this.classList.toggle('on', st.sumOn);
            apply(st);
        });
    }

    /* ---- رؤوس الأعمدة ------------------------------------------ */
    function buildHeads(st) {
        for (var c = 0; c < st.cols; c++) {
            if (st.skip[c]) continue;
            (function (idx) {
                var th = st.hrow.cells[idx];
                th.classList.add('xt-th');

                var tools = document.createElement('span');
                tools.className = 'xt-tools';

                // الترتيب متاح بس لو مفيش صفوف خاصة (رؤوس مجموعات وخلافه)
                if (!st.hasSpecial) {
                    var sb = document.createElement('button');
                    sb.type = 'button';
                    sb.className = 'xt-sort';
                    sb.title = 'ترتيب';
                    // رمز نصّي مش أيقونة — ti-arrows-sort مش موجود في
                    // tabler-subset.css (CONTEXT §٢١) وكان هيطلع مربع فاضي.
                    sb.textContent = '\u21C5';
                    sb.addEventListener('click', function (e) {
                        e.stopPropagation();
                        sortBy(st, idx);
                    });
                    tools.appendChild(sb);
                }

                var fb = document.createElement('button');
                fb.type = 'button';
                fb.className = 'xt-filt';
                fb.title = 'فلتر';
                fb.innerHTML = '<i class="ti ti-filter"></i>';
                fb.addEventListener('click', function (e) {
                    e.stopPropagation();
                    openPanel(st, idx, fb);
                });
                tools.appendChild(fb);

                th.appendChild(tools);
            })(c);
        }
    }

    /* ================================================================
       5) لوحة الفلتر
       fixed مش absolute — عشان ماتتقصّش لو الجدول جوه حاوية بـoverflow.
       ================================================================ */
    var openBox = null;

    function closePanel() {
        if (openBox) { openBox.remove(); openBox = null; }
    }
    document.addEventListener('click', function (e) {
        if (openBox && !e.target.closest('.xt-panel')) closePanel();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closePanel();
    });
    window.addEventListener('resize', closePanel);

    function openPanel(st, idx, anchor) {
        closePanel();

        // القيم الفريدة — بتتحسب عند الفتح بس (lazy)
        var map = {}, order = [];
        for (var i = 0; i < st.rows.length; i++) {
            var raw = st.rows[i].tr.cells[idx] ? txt(st.rows[i].tr.cells[idx]).replace(/\s+/g, ' ').trim() : '';
            var key = st.rows[i].cells[idx];
            if (!(key in map)) { map[key] = { label: raw || '(فاضي)', n: 0, key: key }; order.push(key); }
            map[key].n++;
        }

        if (st.colType[idx] === 'num') {
            order.sort(function (a, b) { return (parseFloat(a) || 0) - (parseFloat(b) || 0); });
        } else {
            order.sort(function (a, b) { return map[a].label.localeCompare(map[b].label, 'ar'); });
        }

        var picked = st.picks[idx] || null;   // null = الكل
        var box = document.createElement('div');
        box.className = 'xt-panel';
        box.innerHTML =
            '<div class="xt-p-head">' +
              '<input type="text" class="xt-p-q" placeholder="بحث في القيم…" autocomplete="off">' +
            '</div>' +
            '<label class="xt-p-all"><input type="checkbox" class="xt-p-allbox"><span>تحديد الكل</span></label>' +
            '<div class="xt-p-list"></div>' +
            '<div class="xt-p-foot">' +
              '<button type="button" class="xt-btn xt-p-clear">مسح فلتر العمود</button>' +
            '</div>';
        document.body.appendChild(box);
        openBox = box;

        var list = box.querySelector('.xt-p-list');
        var allBox = box.querySelector('.xt-p-allbox');
        var shown = order.slice(0, CFG.maxValues);

        function draw(filterTxt) {
            list.innerHTML = '';
            var f = norm(filterTxt || '');
            var frag = document.createDocumentFragment();
            var n = 0;
            for (var i = 0; i < shown.length; i++) {
                var k = shown[i], m = map[k];
                if (f && m.key.indexOf(f) === -1) continue;
                n++;
                var row = document.createElement('div');
                row.className = 'xt-p-row';
                var on = !picked || picked[k];
                row.innerHTML =
                    '<label><input type="checkbox"' + (on ? ' checked' : '') + '>' +
                    '<span class="xt-p-lbl"></span>' +
                    '<span class="xt-p-n">' + m.n + '</span></label>' +
                    '<button type="button" class="xt-p-only">بس ده</button>';
                row.querySelector('.xt-p-lbl').textContent = m.label;
                (function (key, rowEl) {
                    rowEl.querySelector('input').addEventListener('change', function () {
                        if (!picked) {                 // كان الكل مختار
                            picked = {};
                            for (var j = 0; j < order.length; j++) picked[order[j]] = true;
                        }
                        if (this.checked) picked[key] = true; else delete picked[key];
                        commit();
                    });
                    // ⭐ «بس ده» — ده اللي محمد طلبه: يسيب الصنف ده ويخفي الباقي
                    rowEl.querySelector('.xt-p-only').addEventListener('click', function (e) {
                        e.preventDefault(); e.stopPropagation();
                        picked = {}; picked[key] = true;
                        commit();
                        draw(box.querySelector('.xt-p-q').value);
                        syncAll();
                    });
                })(k, row);
                frag.appendChild(row);
            }
            if (!n) {
                var em = document.createElement('div');
                em.className = 'xt-p-empty';
                em.textContent = 'مفيش قيم مطابقة';
                frag.appendChild(em);
            }
            list.appendChild(frag);
            if (order.length > CFG.maxValues) {
                var more = document.createElement('div');
                more.className = 'xt-p-more';
                more.textContent = 'القيم كتير — بيتعرض أول ' + CFG.maxValues +
                                   '. استخدم البحث فوق للوصول للباقي.';
                list.appendChild(more);
            }
            syncAll();
        }

        function syncAll() {
            var total = order.length;
            var on = picked ? Object.keys(picked).length : total;
            allBox.checked = (on === total);
            allBox.indeterminate = (on > 0 && on < total);
        }

        function commit() {
            if (picked) {
                var cnt = Object.keys(picked).length;
                if (cnt === order.length) picked = null;
            }
            if (picked) st.picks[idx] = picked; else delete st.picks[idx];
            st.hrow.cells[idx].classList.toggle('xt-active', !!picked);
            apply(st);
        }

        allBox.addEventListener('change', function () {
            picked = this.checked ? null : {};
            commit();
            draw(box.querySelector('.xt-p-q').value);
        });
        box.querySelector('.xt-p-q').addEventListener('input', function () { draw(this.value); });
        box.querySelector('.xt-p-clear').addEventListener('click', function () {
            picked = null; commit(); draw(''); 
        });

        draw('');

        // الموضع — مع مراعاة حواف الشاشة
        var r = anchor.getBoundingClientRect();
        var w = 260;
        var left = Math.min(Math.max(8, r.left - w + r.width), window.innerWidth - w - 8);
        var top  = r.bottom + 6;
        if (top + 340 > window.innerHeight) top = Math.max(8, r.top - 346);
        box.style.left = left + 'px';
        box.style.top  = top + 'px';
        box.querySelector('.xt-p-q').focus();
    }

    /* ================================================================
       6) التطبيق
       ================================================================ */
    function apply(st) {
        var idxs = Object.keys(st.picks);
        var shown = 0;

        for (var i = 0; i < st.rows.length; i++) {
            var row = st.rows[i], ok = true;

            if (st.q) {
                ok = false;
                for (var c = 0; c < st.cols; c++) {
                    if (row.cells[c].indexOf(st.q) !== -1) { ok = true; break; }
                }
            }
            if (ok) {
                for (var k = 0; k < idxs.length; k++) {
                    var ci = idxs[k];
                    if (!st.picks[ci][row.cells[ci]]) { ok = false; break; }
                }
            }
            row.on = ok;
            row.tr.classList.toggle('xt-out', !ok);
            if (ok) shown++;
        }

        var active = idxs.length > 0 || !!st.q;
        st.bar.querySelector('.xt-reset').hidden = !active;
        st.bar.querySelector('.xt-flag').hidden  = !active;
        st.bar.querySelector('.xt-count').textContent =
            active ? ('ظاهر ' + shown + ' من ' + st.rows.length)
                   : (st.rows.length + ' صف');
        st.table.classList.toggle('xt-filtered', active);
        st.table.classList.toggle('xt-empty', shown === 0);

        drawSum(st, shown);

        /* حدث عام: أي صفحة عايزة تحسب على الصفوف الظاهرة تسمعه.
           بيتبعت على الجدول نفسه وبيطلع لفوق (bubbles).
           detail.rows = عناصر <tr> الظاهرة دلوقتي بالترتيب.
           كروت تقرير المبيعات بتعتمد عليه — يعني الكروت بتقرا من نفس
           الصفوف اللي الجدول بيعرضها بالظبط، مش من استعلام تاني. */
        try {
            st.table.dispatchEvent(new CustomEvent('xtable:filter', {
                bubbles: true,
                detail: {
                    table: st.table,
                    shown: shown,
                    total: st.rows.length,
                    active: active,
                    rows: st.rows.filter(function (r) { return r.on; })
                                 .map(function (r) { return r.tr; })
                }
            }));
        } catch (e) { /* CustomEvent مش مدعوم — الفلتر يفضل شغّال عادي */ }
    }

    /* ---- صف مجموع الظاهر (اختياري) ------------------------------ */
    function drawSum(st, shown) {
        var old = st.table.querySelector('tr.xt-sumrow');
        if (!st.sumOn) { if (old) old.parentNode.removeChild(old); return; }

        var tf = st.table.tFoot;
        if (!tf) { tf = st.table.createTFoot(); }
        var tr = old;
        if (!tr) {
            tr = document.createElement('tr');
            tr.className = 'xt-sumrow';
            tr.setAttribute('data-xt-ignore', '1');
            for (var c = 0; c < st.cols; c++) tr.appendChild(document.createElement('td'));
            tf.appendChild(tr);
        }
        for (var c2 = 0; c2 < st.cols; c2++) {
            var cell = tr.cells[c2];
            if (st.colType[c2] !== 'num') {
                cell.textContent = (c2 === 0) ? ('مجموع الظاهر (' + shown + ')') : '';
                cell.className = 'xt-sum-lbl';
                continue;
            }
            var s = 0, any = false;
            for (var i = 0; i < st.rows.length; i++) {
                if (!st.rows[i].on) continue;
                var v = st.rows[i].nums[c2];
                if (v !== null) { s += v; any = true; }
            }
            cell.textContent = any ? fmtNum(s) : '—';
            cell.className = 'xt-sum-val';
        }
    }

    /* ================================================================
       7) الترتيب
       ================================================================ */
    function sortBy(st, idx) {
        if (st.sortIdx === idx) st.sortDir = (st.sortDir === 1) ? -1 : (st.sortDir === -1 ? 0 : 1);
        else { st.sortIdx = idx; st.sortDir = 1; }

        var ths = st.hrow.cells;
        for (var i = 0; i < ths.length; i++) ths[i].classList.remove('xt-asc', 'xt-desc');

        if (st.sortDir === 0) {
            st.sortIdx = -1;
            var byOrig = st.rows.slice().sort(function (a, b) { return a.ord - b.ord; });
            reorder(st, byOrig);
            return;
        }
        ths[idx].classList.add(st.sortDir === 1 ? 'xt-asc' : 'xt-desc');

        for (var j = 0; j < st.rows.length; j++) {
            if (st.rows[j].ord === undefined) st.rows[j].ord = j;
        }
        var isNum = st.colType[idx] === 'num';
        var d = st.sortDir;
        var sorted = st.rows.slice().sort(function (a, b) {
            var av, bv;
            if (isNum) {
                av = a.nums[idx]; bv = b.nums[idx];
                if (av === null && bv === null) return a.ord - b.ord;
                if (av === null) return 1;
                if (bv === null) return -1;
                return (av - bv) * d;
            }
            av = a.cells[idx]; bv = b.cells[idx];
            if (av === bv) return a.ord - b.ord;
            if (av === '') return 1;
            if (bv === '') return -1;
            return av.localeCompare(bv, 'ar') * d;
        });
        reorder(st, sorted);
    }

    function reorder(st, arr) {
        watching = true;                 // منع المراقب من إعادة المسح بسببنا
        try {
            // الصفوف المتعدّاة (إجماليات) مش في st.rows، فلو رصّينا صفوف
            // الداتا بس بـappendChild بيفضلوا مكانهم وينتهوا **فوق**
            // الجدول. بنلمّهم الأول ونحطهم تحت بعد الترتيب.
            var tail = [];
            for (var r = 0; r < st.body.rows.length; r++) {
                var tr = st.body.rows[r];
                if (tr.classList.contains('xt-sumrow')) continue;   // بيتدار لوحده
                var isData = false;
                for (var k = 0; k < arr.length; k++) {
                    if (arr[k].tr === tr) { isData = true; break; }
                }
                if (!isData) tail.push(tr);
            }

            var frag = document.createDocumentFragment();
            for (var i = 0; i < arr.length; i++) frag.appendChild(arr[i].tr);
            for (var j = 0; j < tail.length; j++) frag.appendChild(tail[j]);
            st.body.appendChild(frag);
        } finally { watching = false; }
    }

    /* ================================================================
       8) تحذير التصدير
       الملفات بتتولد على السيرفر، فالفلتر بتاع الشاشة مالوش تأثير عليها.
       ================================================================ */
    document.addEventListener('click', function (e) {
        var el = e.target.closest('a[href], button');
        if (!el) return;
        var t = el.closest('table.xt-filtered');
        var near = t || (function () {
            // الأزرار غالباً بره الجدول — ندوّر على جدول مفلتر في نفس الكارت
            var box = el.closest('.card, .box, .panel, form, section, body');
            return box ? box.querySelector('table.xt-filtered') : null;
        })();
        if (!near) return;

        var label = norm((el.textContent || '') + ' ' + (el.getAttribute('href') || '') +
                         ' ' + (el.getAttribute('onclick') || ''));
        var isExport = /export|download|\.txt|\.csv|\.xls|تحميل|تصدير|اكسيل|excel/.test(label);
        if (!isExport) return;
        if (el.dataset.xtOk === '1') { delete el.dataset.xtOk; return; }

        e.preventDefault();
        e.stopPropagation();
        var go = window.confirm(
            'الفلتر شغّال على الشاشة.\n\n' +
            'الملف بيتعمل على السيرفر، فهينزل بكل الصفوف — مش الظاهر بس.\n\n' +
            'تكمّل التحميل؟'
        );
        if (go) {
            el.dataset.xtOk = '1';
            el.click();
        }
    }, true);

    /* ================================================================
       9) الستايل — جوه الملف عشان يبقى التركيب ملف واحد
       ================================================================ */
    var CSS = [
        '.xt-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 8px;}',
        '.xt-search{position:relative;display:flex;align-items:center;}',
        '.xt-search .ti{position:absolute;right:9px;color:var(--ink-400,#94a3b8);font-size:15px;pointer-events:none;}',
        '.xt-search input{padding:7px 30px 7px 26px;border:1px solid var(--line,#e3e7ed);border-radius:var(--r-sm,8px);',
            'font-family:inherit;font-size:13px;background:var(--surface,#fff);color:var(--ink-800,#22334f);min-width:200px;}',
        '.xt-search input:focus{outline:none;border-color:var(--accent,#2f5d8a);box-shadow:0 0 0 3px var(--accent-ring,rgba(47,93,138,.16));}',
        '.xt-qclear{position:absolute;left:6px;border:0;background:none;color:var(--ink-400,#94a3b8);',
            'font-size:17px;line-height:1;cursor:pointer;padding:2px 4px;}',
        '.xt-qclear:hover{color:var(--neg,#9d3b38);}',
        '.xt-count{font-size:12px;color:var(--ink-500,#6d7f9a);font-weight:600;}',
        '.xt-flag{font-size:11.5px;font-weight:600;color:var(--warn,#8a6524);background:var(--warn-soft,#f7f0e2);',
            'padding:3px 9px;border-radius:5px;display:inline-flex;align-items:center;gap:4px;}',
        '.xt-btn{border:1px solid var(--line,#e3e7ed);background:var(--surface,#fff);color:var(--ink-700,#3b4e6d);',
            'border-radius:var(--r-sm,8px);padding:6px 11px;font-family:inherit;font-size:12.5px;cursor:pointer;}',
        '.xt-btn:hover{background:var(--accent-soft,#eaf1f8);border-color:var(--accent,#2f5d8a);}',
        '.xt-btn.on{background:var(--accent,#2f5d8a);color:#fff;border-color:var(--accent,#2f5d8a);}',
        '.xt-sum{font-size:15px;line-height:1;padding:5px 11px;}',

        'table.xt-on th.xt-th{white-space:nowrap;}',
        '.xt-tools{display:inline-flex;gap:1px;margin-right:5px;vertical-align:middle;}',
        '.xt-tools button{border:0;background:none;cursor:pointer;color:var(--ink-300,#c3ccd8);',
            'padding:1px 2px;font-size:13px;line-height:1;border-radius:4px;}',
        '.xt-tools button:hover{color:var(--accent,#2f5d8a);background:var(--accent-soft,#eaf1f8);}',
        'th.xt-active .xt-filt{color:var(--accent,#2f5d8a);}',
        'th.xt-active{background:var(--accent-soft,#eaf1f8) !important;}',
        '.xt-sort{font-size:12px !important;font-weight:700;}',
        'th.xt-asc .xt-sort,th.xt-desc .xt-sort{color:var(--accent,#2f5d8a);}',
        'th.xt-asc .xt-sort:after{content:" \\2191";}',
        'th.xt-desc .xt-sort:after{content:" \\2193";}',

        'tr.xt-out{display:none !important;}',
        'tr.xt-sumrow td{background:var(--accent-soft,#eaf1f8);font-weight:700;',
            'border-top:2px solid var(--accent,#2f5d8a);font-size:12.5px;}',
        'tr.xt-sumrow .xt-sum-lbl{color:var(--ink-600,#4d6180);font-weight:600;white-space:nowrap;}',

        '.xt-panel{position:fixed;z-index:9999;width:260px;max-height:340px;display:flex;flex-direction:column;',
            'background:var(--surface,#fff);border:1px solid var(--line,#e3e7ed);border-radius:var(--r,10px);',
            'box-shadow:var(--shadow-lg,0 16px 40px rgba(22,35,61,.15));overflow:hidden;font-size:13px;}',
        '.xt-p-head{padding:8px;border-bottom:1px solid var(--line-soft,#eff2f6);}',
        '.xt-p-q{width:100%;padding:6px 9px;border:1px solid var(--line,#e3e7ed);border-radius:6px;',
            'font-family:inherit;font-size:12.5px;}',
        '.xt-p-q:focus{outline:none;border-color:var(--accent,#2f5d8a);}',
        '.xt-p-all{display:flex;align-items:center;gap:7px;padding:7px 10px;cursor:pointer;',
            'font-weight:600;font-size:12.5px;border-bottom:1px solid var(--line-soft,#eff2f6);color:var(--ink-700,#3b4e6d);}',
        '.xt-p-list{overflow-y:auto;flex:1;padding:2px 0;}',
        '.xt-p-row{display:flex;align-items:center;gap:4px;padding:1px 10px;}',
        '.xt-p-row:hover{background:var(--line-soft,#eff2f6);}',
        '.xt-p-row label{display:flex;align-items:center;gap:7px;flex:1;cursor:pointer;min-width:0;padding:4px 0;}',
        '.xt-p-lbl{flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
        '.xt-p-n{font-size:11px;color:var(--ink-400,#94a3b8);font-weight:600;}',
        '.xt-p-only{border:0;background:none;color:var(--accent,#2f5d8a);font-family:inherit;font-size:11px;',
            'cursor:pointer;padding:2px 5px;border-radius:4px;opacity:0;white-space:nowrap;}',
        '.xt-p-row:hover .xt-p-only{opacity:1;}',
        '.xt-p-only:hover{background:var(--accent,#2f5d8a);color:#fff;}',
        '.xt-p-empty,.xt-p-more{padding:10px;color:var(--ink-400,#94a3b8);font-size:11.5px;text-align:center;}',
        '.xt-p-foot{padding:7px 8px;border-top:1px solid var(--line-soft,#eff2f6);}',
        '.xt-p-foot .xt-btn{width:100%;}',

        '@media(max-width:640px){',
          '.xt-search input{min-width:0;width:100%;}',
          '.xt-search{flex:1 1 100%;}',
          '.xt-p-only{opacity:1;}',
          '.xt-panel{width:calc(100vw - 24px);left:12px !important;}',
        '}',
        '@media print{.xt-bar,.xt-tools,.xt-panel{display:none !important;}}'
    ].join('');

    function injectCSS() {
        if (document.getElementById('xt-css')) return;
        var s = document.createElement('style');
        s.id = 'xt-css';
        s.textContent = CSS;
        document.head.appendChild(s);
    }

    /* ================================================================
       10) الإقلاع
       ================================================================ */

    /* ── ترتيب الجداول في الصفحة ─────────────────────────────────────
       بنحتاج رقم الجدول (ترتيبه وسط الجداول المؤهّلة في الصفحة) عشان
       نحفظه ونرجّعه. بنحسبه من table.xt-on اللي اتبنوا بالفعل. */
    function tableIndex(table) {
        var all = document.querySelectorAll('table.xt-on');
        for (var i = 0; i < all.length; i++) {
            if (all[i] === table) return i;
        }
        return -1;
    }

    function scan(root) {
        var list = (root || document).querySelectorAll('table');
        for (var i = 0; i < list.length; i++) {
            try {
                if (eligible(list[i])) build(list[i]);
            } catch (err) {
                if (window.console) console.warn('[xtable] جدول اتعدّى:', err);
            }
        }
    }

    function watch() {
        if (!window.MutationObserver) return;
        var obs = new MutationObserver(function () {
            if (watching) return;                     // إحنا اللي بنعدّل — تجاهل
            clearTimeout(wtmr);
            wtmr = setTimeout(function () {
                if (!document.querySelector('table:not(.xt-on)')) return;
                watching = true;
                try { scan(document); } finally { watching = false; }
            }, 400);
        });
        obs.observe(document.body, { childList: true, subtree: true });
    }

    function init() {
        injectCSS();
        attachSubmitSave();   // (١١) ربط الحفظ عند الـsubmit
        watching = true;
        try { scan(document); } finally { watching = false; }
        watch();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // للجداول اللي بتتبني بجافاسكربت بعد التحميل
    window.xtableScan = function (root) { injectCSS(); scan(root); };

    /* ================================================================
       11) حفظ الفلتر عند الـsubmit واسترجاعه مرة واحدة
       ─────────────────────────────────────────────────────────────────
       المبدأ الأصلي (السطر 18 فوق) لازم يفضل صح:
         «مفيش حفظ للفلتر بين الصفحات — تفتح الشاشة تلاقيها كاملة دايماً.»

       الاستثناء الوحيد: المستخدم دوس زرار/فورم جوّا نفس الشاشة والصفحة
       رجعت لنفس المسار. في الحالة دي مابساش الشاشة — بس POST/redirect
       بيخلي الصفحة تتحمّل من الأول.

       الحل:
         (١) وقت الـsubmit: لو فيه فلتر شغّال → اخزّنه في sessionStorage.
         (٢) وقت بناء الجدول: لو لقينا مفتاح → امسحه فوراً ← طبّق الفلتر.
         النتيجة: رجع للصفحة مرة → فلتر. F5 أو فتح من جديد → كاملة.

       ── تفاصيل تقنية ────────────────────────────────────────────────
       • المفتاح: 'xt-restore:' + location.pathname (بدون search/hash)
         لأن الـredirect بعد POST ممكن يغيّر الكويري (?tab=attendance وكده).
       • sessionStorage مش storage الدايمة — يموت مع التاب.
       • كل نداء على sessionStorage ملفوف في try/catch — التصفح الخاص
         بيرمي استثناء فعلي مش بيرجّع null، والمكتبة لازم تفضل شغالة.
       • المسح قبل التطبيق — لو التطبيق رمى استثناء والمفتاح لسه موجود،
         الفلتر هيرجع في كل تحميل للأبد وده أوحش من المشكلة الأصلية.
       ================================================================ */

    /* المفتاح: pathname بس من غير search — الـredirect بيغيّر الكويري */
    function restoreKey() {
        return 'xt-restore:' + location.pathname;
    }

    /* (١) الحفظ — بيتنادى مرة واحدة من init() */
    function attachSubmitSave() {
        /* 🔴 مرحلة الفقاعة (bubble) مش الالتقاط، وبنتخطى لو الفورم اتلغى.
           السبب: فورمات المسح عندها onsubmit="return confirm(...)".
           في مرحلة الالتقاط كنا بنسبق التأكيد، فلو المستخدم دوس «إلغاء»
           الفلتر بيتخزّن وهو أصلاً ماعملش حاجة — ويرجع له بعدين من غير
           سبب. الحدث بيوصل لـdocument في الحالتين (preventDefault
           مابيوقفش الانتشار)، فالفرق الوحيد هو defaultPrevented. */
        document.addEventListener('submit', function (e) {
            if (e.defaultPrevented) return;   /* المستخدم لغى — مفيش تنقّل */
            try {
                var tables = document.querySelectorAll('table.xt-on');
                var snapshot = [];
                var hasActive = false;

                for (var i = 0; i < tables.length; i++) {
                    var st = tables[i].__xt;
                    if (!st) continue;
                    var picksKeys = Object.keys(st.picks);
                    if (!st.q && picksKeys.length === 0) continue;
                    hasActive = true;
                    snapshot.push({
                        idx:   i,           /* ترتيب الجدول وسط جداول الصفحة */
                        cols:  st.cols,     /* عدد الأعمدة — للتحقق عند الاسترجاع */
                        q:     st.q,
                        picks: st.picks
                    });
                }

                if (!hasActive) return;     /* مفيش فلاتر — مفيش حاجة تتحفظ */
                sessionStorage.setItem(restoreKey(), JSON.stringify(snapshot));
            } catch (e) { /* sessionStorage ممكن يرمي في التصفح الخاص */ }
        });
    }

    /* (٢) الاسترجاع — بيتنادى من build() بعد ما الجدول يتبنى */
    function tryRestore(st) {
        var key = restoreKey();
        var raw;
        try { raw = sessionStorage.getItem(key); } catch (e) { return; }
        if (!raw) return;

        var snapshot;
        try { snapshot = JSON.parse(raw); } catch (e) {
            /* JSON تالف — امسح عشان مانفضّلش عالقين */
            try { sessionStorage.removeItem(key); } catch (e2) {}
            return;
        }

        /* رقم هذا الجدول في الصفحة دلوقتي */
        var myIdx = tableIndex(st.table);
        if (myIdx < 0) return;

        /* دوّر على صنبشوت الجدول ده بالتحديد */
        var entry = null;
        for (var i = 0; i < snapshot.length; i++) {
            if (snapshot[i].idx === myIdx) { entry = snapshot[i]; break; }
        }
        if (!entry) return;

        /* ────────────────────────────────────────────────────────────
           🔴 امسح المفتاح الأول — قبل أي تطبيق.
           لو التطبيق رمى استثناء بعد كده المفتاح بيبقى ماشي وده سليم.
           لو تركناه ورمى استثناء، الفلتر هيرجع في كل تحميل للأبد.
           ──────────────────────────────────────────────────────────── */
        try { sessionStorage.removeItem(key); } catch (e) {}

        /* لو عدد الأعمدة اتغيّر → الصفحة اترندرت مختلفة → تجاهل خالص */
        if (entry.cols !== st.cols) return;

        /* طبّق البحث العام */
        if (entry.q) {
            st.q = entry.q;
            var inp = st.bar && st.bar.querySelector('input');
            if (inp) inp.value = entry.q;
        }

        /* طبّق picks لكل عمود */
        var picks = entry.picks || {};
        var pickKeys = Object.keys(picks);
        for (var j = 0; j < pickKeys.length; j++) {
            var ci = pickKeys[j];
            if (picks[ci] && Object.keys(picks[ci]).length > 0) {
                st.picks[ci] = picks[ci];
                /* لوّن رأس العمود عشان المستخدم يشوف الفلتر شغّال */
                if (st.hrow && st.hrow.cells[Number(ci)]) {
                    st.hrow.cells[Number(ci)].classList.add('xt-active');
                }
            }
        }

        /* نادِ apply عشان الصفوف تتفلتر والعدّاد يتحدّث وحدث xtable:filter يتبعت */
        apply(st);
        return true;   /* علامة للـbuild() إن الفلتر اتطبّق */
    }
})();
