/* =====================================================================
 *  acctree.js - منتقي القيود بليستين  |  الفجالة ستور  |  2026-08-22
 * ---------------------------------------------------------------------
 *  الشكل: ليستين جنب بعض.
 *      اليمين = القيود الأب.
 *      الشمال = تفرعات الأب اللي اخترته، وبس.
 *
 *  الأب اللي مالوش تفرعات بيتختار على طول.
 *  الأب اللي ليه تفرعات بيملا الليست الشمال، وفوقها سطر لاختيار الأب
 *  نفسه كقيد. لو التفرع نفسه ليه تفرعات، الليست الشمال بتنزل مستوى
 *  وفوقها زرار رجوع.
 *
 *  قواعد مقصودة:
 *  - الشجرة من parent_id بتاع الداتابيز، مش بتقسيم الاسم. أسامي
 *    الحسابات فيها شرطات أصلاً (زي "egy Bank - البنك").
 *  - اللي بيتبعت للسيرفر هو id الحقيقي مش الاسم.
 *  - الاستايل متحقون من هنا عن قصد: المكوّن يبقى قائم بذاته ومايتكسرش
 *    لو fagala.css اتكاشت نسخة قديمة.
 *  - صفر نداءات سيرفر.
 * ===================================================================== */
(function () {
    'use strict';

    var OPEN = null;

    /* ---------------- الاستايل (مرة واحدة للصفحة) ---------------- */
    var CSS = ''
    + '.act{position:relative}'
    + '.act-btn{width:100%;display:flex;align-items:center;justify-content:space-between;gap:8px;'
    +   'padding:10px 12px;border:1.5px solid var(--line,#e5e7eb);border-radius:9px;'
    +   'background:var(--line-soft,#f8fafc);font-size:14px;font-family:inherit;cursor:pointer;'
    +   'text-align:right;color:var(--ink-800,#1f2937)}'
    + '.act-btn:hover{border-color:var(--accent,#16233d)}'
    + '.act-label{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
    + '.act-label.act-empty{color:var(--ink-400,#9ca3af)}'
    + '.act-caret{color:var(--ink-400,#9ca3af);font-size:11px;flex-shrink:0}'
    + '.act-pop{position:absolute;z-index:400;top:calc(100% + 4px);right:0;left:0;background:var(--surface,#fff);'
    +   'border:1.5px solid var(--line,#e5e7eb);border-radius:12px;'
    +   'box-shadow:0 12px 32px rgba(15,23,42,.25);overflow:hidden;min-width:340px}'
    + '.act-head{display:flex;gap:6px;padding:8px;border-bottom:1px solid var(--line,#e5e7eb);background:var(--surface,#fff)}'
    + '.act-search{flex:1;min-width:0;padding:7px 10px;border:1.5px solid var(--line,#e5e7eb);'
    +   'border-radius:8px;font-size:13px;font-family:inherit;background:var(--line-soft,#f8fafc);color:var(--ink-800,#1f2937)}'
    + '.act-search:focus{outline:none;border-color:var(--accent-text,#1e6fbf);background:var(--surface,#fff)}'
    + '.act-clear{padding:7px 12px;border:none;border-radius:8px;cursor:pointer;flex-shrink:0;'
    +   'background:var(--line-soft,#f1f5f9);color:var(--ink-600,#4b5563);font-size:12px;font-family:inherit}'
    + '.act-clear:hover{background:var(--neg-soft,#fee2e2);color:var(--neg,#dc2626)}'
    + '.act-cols{display:flex;align-items:stretch;background:var(--surface,#fff)}'
    + '.act-col{width:50%;max-height:280px;overflow-y:auto;padding:5px 0}'
    + '.act-col+.act-col{border-right:1px solid var(--line,#e5e7eb)}'
    + '.act-col-title{padding:7px 11px 6px;font-size:11px;font-weight:600;color:var(--ink-400,#9ca3af);'
    +   'position:sticky;top:0;background:var(--surface,#fff);z-index:1}'
    + '.act-row{display:flex;align-items:center;gap:6px;padding:8px 11px;cursor:pointer;'
    +   'font-size:13px;color:var(--ink-800,#1f2937)}'
    + '.act-row:hover{background:var(--line-soft,#f1f5f9)}'
    + '.act-row.on{background:var(--accent-soft,#e8ecf5);font-weight:600;color:var(--accent-text,#1e6fbf)}'
    + '.act-nm{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
    + '.act-more{color:var(--ink-400,#9ca3af);font-size:11px;flex-shrink:0}'
    + '.act-row.on .act-more{color:var(--accent-text,#1e6fbf)}'
    + '.act-self{border-bottom:1px solid var(--line,#e5e7eb);color:var(--accent-text,#1e6fbf)}'
    + '.act-back{color:var(--ink-500,#6b7280);font-size:12px}'
    + '.act-hint{padding:22px 14px;text-align:center;color:var(--ink-400,#9ca3af);'
    +   'font-size:12px;line-height:1.8}'
    + '.act-results{max-height:280px;overflow-y:auto;padding:5px 0;background:var(--surface,#fff)}'
    + '@media(max-width:520px){.act-cols{flex-direction:column}.act-col{width:100%;max-height:170px}'
    +   '.act-col+.act-col{border-right:none;border-top:1px solid var(--line,#e5e7eb)}}';

    function injectCss() {
        if (document.getElementById('act-css')) return;
        var st = document.createElement('style');
        st.id = 'act-css';
        st.textContent = CSS;
        document.head.appendChild(st);
    }

    /* ---------------- أدوات ---------------- */
    function buildIndex(rows) {
        var byId = {}, kids = {}, i, r, p;
        for (i = 0; i < rows.length; i++) byId[rows[i].id] = rows[i];
        for (i = 0; i < rows.length; i++) {
            r = rows[i];
            p = r.parent_id;
            if (!p || !byId[p]) p = 0;      // يتيم = جذر
            (kids[p] = kids[p] || []).push(r);
        }
        return { byId: byId, kids: kids };
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;')
            .replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function norm(s) {
        return String(s || '').toLowerCase()
            .replace(/[\u064B-\u0652\u0640]/g, '')
            .replace(/[\u0623\u0625\u0622]/g, '\u0627')
            .replace(/\u0649/g, '\u064A')
            .replace(/\u0629/g, '\u0647')
            .trim();
    }

    function hasKids(ctx, id) {
        return !!(ctx.idx.kids[id] && ctx.idx.kids[id].length);
    }

    function mkRow(text, cls, more) {
        var d = document.createElement('div');
        d.className = 'act-row' + (cls ? ' ' + cls : '');
        d.innerHTML = '<span class="act-nm">' + esc(text) + '</span>'
                    + (more ? '<span class="act-more">' + more + '</span>' : '');
        return d;
    }

    /* ---------------- الليست اليمين: الآباء ---------------- */
    function drawParents(ctx) {
        var box = ctx.colA;
        box.innerHTML = '<div class="act-col-title">القيود</div>';
        (ctx.idx.kids[0] || []).forEach(function (r) {
            var kid = hasKids(ctx, r.id);
            var row = mkRow(r.name, null, kid ? '\u2039' : '');
            row.addEventListener('click', function (ev) {
                ev.stopPropagation();
                var cur = box.querySelector('.act-row.on');
                if (cur) cur.classList.remove('on');
                row.classList.add('on');
                if (kid) drawKids(ctx, r, null);
                else choose(ctx, r);
            });
            box.appendChild(row);
        });
    }

    /* ---------------- الليست الشمال: تفرعات الأب المختار ----------------
     *  backTo = نرجعله لو كنا نزلنا مستوى أعمق
     * ------------------------------------------------------------------ */
    function drawKids(ctx, node, backTo) {
        var box = ctx.colB;
        box.innerHTML = '<div class="act-col-title">تفرعات: ' + esc(node.name) + '</div>';

        if (backTo) {
            var back = mkRow('\u203A رجوع لـ ' + backTo.name, 'act-back');
            back.addEventListener('click', function (ev) {
                ev.stopPropagation();
                drawKids(ctx, backTo, null);
            });
            box.appendChild(back);
        }

        var self = mkRow('\u2713 اختيار «' + node.name + '» نفسه', 'act-self');
        self.addEventListener('click', function (ev) {
            ev.stopPropagation();
            choose(ctx, node);
        });
        box.appendChild(self);

        (ctx.idx.kids[node.id] || []).forEach(function (r) {
            var kid = hasKids(ctx, r.id);
            var row = mkRow(r.name, null, kid ? '\u2039' : '');
            row.addEventListener('click', function (ev) {
                ev.stopPropagation();
                if (kid) drawKids(ctx, r, node);
                else choose(ctx, r);
            });
            box.appendChild(row);
        });
        box.scrollTop = 0;
    }

    function emptyKids(ctx) {
        ctx.colB.innerHTML = '<div class="act-hint">اختار قيد من الليست<br>عشان تفرعاته تبان هنا</div>';
    }

    /* ---------------- الاختيار ---------------- */
    function choose(ctx, r) {
        ctx.input.value = r.id;
        ctx.label.textContent = r.path || r.name;
        ctx.label.classList.remove('act-empty');
        close(ctx);
        ctx.input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function clear(ctx) {
        ctx.input.value = '';
        ctx.label.textContent = ctx.placeholder;
        ctx.label.classList.add('act-empty');
        close(ctx);
        ctx.input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function open(ctx) {
        if (OPEN && OPEN !== ctx) close(OPEN);
        ctx.pop.style.display = 'block';
        OPEN = ctx;
        if (!ctx.built) { drawParents(ctx); emptyKids(ctx); ctx.built = true; }
        ctx.search.value = '';
        applySearch(ctx);
        try { ctx.search.focus(); } catch (e) {}
    }

    function close(ctx) {
        ctx.pop.style.display = 'none';
        if (OPEN === ctx) OPEN = null;
    }

    /* ---------------- البحث: نتيجة مسطّحة بالمسار الكامل ---------------- */
    function applySearch(ctx) {
        var q = norm(ctx.search.value);
        if (!q) {
            ctx.cols.style.display = 'flex';
            ctx.results.style.display = 'none';
            return;
        }
        ctx.cols.style.display = 'none';
        ctx.results.style.display = 'block';
        ctx.results.innerHTML = '';

        var hits = 0;
        for (var i = 0; i < ctx.rows.length && hits < 60; i++) {
            var r = ctx.rows[i];
            if (norm(r.path).indexOf(q) === -1) continue;
            hits++;
            var row = mkRow(r.path);
            (function (rr) {
                row.addEventListener('click', function (ev) { ev.stopPropagation(); choose(ctx, rr); });
            })(r);
            ctx.results.appendChild(row);
        }
        if (!hits) ctx.results.innerHTML = '<div class="act-hint">مفيش نتايج</div>';
    }

    /* ---------------- التركيب ---------------- */
    function init(root) {
        if (root.__act) return;
        root.__act = true;
        injectCss();

        var rows = (window.ACCT_DATA && window.ACCT_DATA[root.dataset.set || 'all']) || [];

        var ctx = {
            root: root, rows: rows, idx: buildIndex(rows),
            placeholder: root.dataset.placeholder || '\u2014 بدون قيد \u2014',
            built: false
        };
        ctx.input   = root.querySelector('input[type=hidden]');
        ctx.label   = root.querySelector('.act-label');
        ctx.pop     = root.querySelector('.act-pop');
        ctx.search  = root.querySelector('.act-search');
        ctx.cols    = root.querySelector('.act-cols');
        ctx.colA    = root.querySelector('.act-col-a');
        ctx.colB    = root.querySelector('.act-col-b');
        ctx.results = root.querySelector('.act-results');

        if (ctx.input.value) {
            var cur = ctx.idx.byId[ctx.input.value];
            if (cur) {
                ctx.label.textContent = cur.path || cur.name;
                ctx.label.classList.remove('act-empty');
            }
        }

        root.querySelector('.act-btn').addEventListener('click', function (ev) {
            ev.stopPropagation();
            if (ctx.pop.style.display === 'block') close(ctx); else open(ctx);
        });
        ctx.search.addEventListener('input', function () { applySearch(ctx); });
        ctx.pop.addEventListener('click', function (ev) { ev.stopPropagation(); });
        var clr = root.querySelector('.act-clear');
        if (clr) clr.addEventListener('click', function (ev) { ev.stopPropagation(); clear(ctx); });

        root.__ctx = ctx;
    }

    function scan() {
        var els = document.querySelectorAll('[data-acctree]'), i;
        for (i = 0; i < els.length; i++) init(els[i]);
    }

    document.addEventListener('click', function () { if (OPEN) close(OPEN); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && OPEN) close(OPEN);
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scan);
    } else {
        scan();
    }

    window.acctreeScan = scan;

    window.acctreeSet = function (root, id) {
        if (!root || !root.__ctx) return;
        var ctx = root.__ctx;
        if (!id) {
            ctx.input.value = '';
            ctx.label.textContent = ctx.placeholder;
            ctx.label.classList.add('act-empty');
            return;
        }
        var r = ctx.idx.byId[id];
        ctx.input.value = id;
        ctx.label.textContent = r ? (r.path || r.name) : '';
        ctx.label.classList.toggle('act-empty', !r);
    };
})();
