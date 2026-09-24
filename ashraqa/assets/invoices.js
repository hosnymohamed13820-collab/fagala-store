// invoices.js — سكربتات شاشة الاستلامات (بريف ٥٧)
window.INV = window.INV || {};

    // ===== نسبة خصم لكل صنف — حساب فوري في المتصفح (بدون ريفرش) =====
    //
    //  بيطابق منطق PHP بالظبط:
    //    السعر بعد الخصم = round(السعر × (1 − نسبة/100), DP)
    //    قيمة السطر      = round(الكمية × السعر بعد الخصم, 2)
    //
    //  الصف اللي فيه نسبة → بيتحسب بنسبته (locked).
    //  الصف الفاضي:
    //     • مفيش أي نسبة في الفاتورة كلها → يرجع لقيمة السيرفر
    //       (اللي فيها التسوية الذكية) زي ما هي.
    //     • فيه نسبة في صنف تاني → التسوية بتتوقف على السيرفر،
    //       فبنحسب الصف بخصم الفاتورة العادي من غير تسوية.
    //
    //  ملف التصدير بيتحدّث لينكه بس؛ الحساب الفعلي بيتعمل على
    //  السيرفر وقت التنزيل بنفس المعادلة دي بالظبط.
    // =============================================================
    const _dt = document.getElementById('discTable');
    const DISC_RATE = _dt ? parseFloat(_dt.dataset.rate || '0') : 0;   // نسبة خصم الفاتورة (سالبة)
    const DISC_DP   = _dt ? parseInt(_dt.dataset.dp || '3', 10) : 3;

    function _round(v, dp) {
        const f = Math.pow(10, dp);
        return Math.round((v + Number.EPSILON) * f) / f;
    }
    // عرض السعر بعد الخصم: لغاية DP خانات، بحد أدنى خانتين، بدون أصفار زيادة
    function _fmtAfter(v) {
        let s = v.toFixed(DISC_DP);
        if (DISC_DP > 2) {
            s = s.replace(/0+$/, '');
            const dec = s.length - s.indexOf('.') - 1;
            if (s.indexOf('.') === -1) s += '.00';
            else if (dec < 2) s = v.toFixed(2);
        }
        return s;
    }
    function _fmt2(v) {
        return v.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    // في أي نسبة صنف مكتوبة دلوقتي؟
    function _anyPct() {
        let any = false;
        document.querySelectorAll('.disc-pct-inp').forEach(function (el) {
            const v = parseFloat(el.value);
            if (!isNaN(v) && v > 0 && v <= 100) any = true;
        });
        return any;
    }

    // إعادة حساب صف واحد (بيرجّع قيمة السطر عشان نجمّعها)
    function _recalcRow(tr) {
        const inp = tr.querySelector('.disc-pct-inp');
        const qty = parseFloat(tr.dataset.qty || '0');
        const prc = parseFloat(tr.dataset.price || '0');
        const afterCell = tr.querySelector('.js-after');
        const totalCell = tr.querySelector('.js-total');

        let pct = parseFloat(inp.value);
        const hasPct = (!isNaN(pct) && pct > 0 && pct <= 100);

        // لو الصف فاضي ومفيش أي نسبة في الفاتورة → رجّع قيمة السيرفر بالظبط
        if (!hasPct && !_anyPct()) {
            tr.classList.toggle('disc-locked', false);
            const sa = tr.dataset.serverAfter || '';
            const st = parseFloat(tr.dataset.serverTotal || '0');
            let badge = '';
            if (tr.dataset.adjusted === '1')
                badge = '<span class="disc-dot" title="اتعدّل بأقل وحدة ممكنة لضبط الإجمالي" style="color:var(--warn);font-size:11px;"> ●</span>';
            afterCell.innerHTML = sa + badge;
            totalCell.textContent = _fmt2(st);
            return st;
        }

        // نسبة الصف: خصم الصنف لو مكتوب، وإلا خصم الفاتورة
        const rate  = hasPct ? (-pct / 100) : DISC_RATE;
        const after = _round(prc * (1 + rate), DISC_DP);
        const total = _round(qty * after, 2);

        tr.classList.toggle('disc-locked', hasPct);
        afterCell.innerHTML = _fmtAfter(after) +
            (hasPct ? '<span class="disc-star" title="خصم خاص بالصنف — بيتجاهل خصم الفاتورة" style="color:var(--accent-text);font-size:11px;"> ★</span>' : '');
        totalCell.textContent = _fmt2(total);
        return total;
    }

    // حساب فوري وأنا بكتب (الصف ده + المجموع + لينك التصدير)
    let _pctTimer = null;
    function discPctLive() {
        let grand = 0;
        document.querySelectorAll('#discTable tbody tr.disc-row').forEach(function (tr) {
            grand += _recalcRow(tr);
        });
        const g = document.getElementById('discGrand');
        if (g) g.textContent = _fmt2(_round(grand, 2));
        _updateExportLink();

        // بدّل الرسالة: خصم صنف شغّال ولا الصافي العادي
        const any = _anyPct();
        const lk = document.getElementById('discLockedMsg');
        const nm = document.getElementById('discNetMsg');
        if (lk) lk.style.display = any ? '' : 'none';
        if (nm) nm.style.display = any ? 'none' : '';
    }

    // تحديث لينك التصدير بالنِسب الحالية (من غير ما نحمّل الصفحة)
    function _updateExportLink() {
        const btn = document.getElementById('discExportBtn');
        if (!btn) return;
        const parts = [];
        document.querySelectorAll('.disc-pct-inp').forEach(function (el) {
            const code = (el.dataset.code || '').trim();
            let v = parseFloat(el.value);
            if (code && !isNaN(v) && v > 0 && v <= 100) {
                v = Math.round(v * 100) / 100;
                parts.push(code + ':' + v);
            }
        });
        let href = btn.dataset.base;
        if (parts.length) href += '&dpct=' + encodeURIComponent(parts.join(','));
        btn.href = href;
    }

    // onchange: مفيش ريفرش خلاص — بس نتأكد إن كله متحدّث
    function discPctSync() { discPctLive(); }

    function branchRows(head) {
        const rows = [];
        let sib = head.nextElementSibling;
        while (sib && !sib.dataset.branchhead) {
            if (sib.classList.contains('inv-row')) rows.push(sib);
            sib = sib.nextElementSibling;
        }
        return rows;
    }

    let invFilter = { q: '', st: 'all', scope: (window.INV && window.INV.invScope) || 'admin' };

    function filterInvList() {
        const inp = document.getElementById('invSearch');
        invFilter.q = (inp ? inp.value : '').trim().toLowerCase();
        applyInvFilter();
    }

    function setStatusFilter(btn) {
        document.querySelectorAll('.sf-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        invFilter.st = btn.dataset.st;
        applyInvFilter();
    }

    function clearInvSearch() {
        const inp = document.getElementById('invSearch');
        if (inp) { inp.value = ''; inp.focus(); }
        invFilter.q = '';
        applyInvFilter();
    }

    function applyInvFilter() {
        const rows = document.querySelectorAll('.inv-row');
        let shown = 0, n = 0;
        rows.forEach(r => {
            const okQ  = !invFilter.q || (r.dataset.s || '').includes(invFilter.q);
            const okSt = invFilter.st === 'all' || (r.dataset.st || 'new') === invFilter.st;
            const okScope = invFilter.scope === 'all' || (r.dataset.scope === invFilter.scope) || (invFilter.scope === 'branches' && r.dataset.scope === 'branch');
            const hit  = okQ && okSt && okScope;
            if (hit) {
                delete r.dataset.filterHidden;
                shown++;
                // ترقيم متسلسل للمعروض بس
                const c = r.querySelector('td:first-child');
                if (c) c.textContent = ++n;
            } else {
                r.dataset.filterHidden = '1';
            }
        });
        // رأس الفرع يفضل ظاهر لو مطوي عمداً؛ يتخفى بس لو الفلتر شال كل فواتيره
        document.querySelectorAll('.branch-head').forEach(h => {
            const okScope = invFilter.scope === 'all' || (h.dataset.scope === invFilter.scope) || (invFilter.scope === 'branches' && h.dataset.scope === 'branch');
            const rows = branchRows(h);
            const anyMatch = rows.some(r => !r.dataset.filterHidden);
            h.style.display = (okScope && anyMatch) ? '' : 'none';
        });
        // نفس الشيء للرؤوس الفرعية (شراء/مردود)
        document.querySelectorAll('.subtype-head').forEach(h => {
            const okScope = invFilter.scope === 'all' || (h.dataset.scope === invFilter.scope) || (invFilter.scope === 'branches' && h.dataset.scope === 'branch');
            let any = false, sib = h.nextElementSibling;
            while (sib && !sib.dataset.subhead && !sib.dataset.branchhead) {
                if (sib.classList.contains('inv-row') && !sib.dataset.filterHidden) { any = true; break; }
                sib = sib.nextElementSibling;
            }
            // نخفيه بس لو فرعه مش مطوي (عشان ماينافسش طي الفرع)
            if (h.style.display !== 'none' || any) h.style.display = (okScope && any) ? '' : 'none';
        });
        const cnt = document.getElementById('listCount');
        if (cnt) cnt.textContent = shown + (shown === rows.length ? '' : ' من ' + rows.length);
        const nm = document.getElementById('noMatch');
        if (nm) nm.style.display = (shown === 0 && rows.length > 0) ? 'block' : 'none';
    }

    // عدّادات الحالة (مرة واحدة عند التحميل — مفيش أي طلب للسيرفر)
    (function initCounts() {
        const rows = document.querySelectorAll('.inv-row');
        if (!rows.length) return;
        const c = { all: rows.length, new: 0, split: 0, done: 0 };
        rows.forEach(r => { const k = r.dataset.st || 'new'; if (c[k] !== undefined) c[k]++; });
        document.querySelectorAll('.sf-btn').forEach(b => {
            const sp = b.querySelector('span');
            if (sp) sp.textContent = c[b.dataset.st] ?? 0;
        });
        const urlQ = new URLSearchParams(location.search).get('q');
        if (urlQ) {
            const inp = document.getElementById('invSearch');
            if (inp) inp.value = urlQ;
            invFilter.q = urlQ.trim().toLowerCase();
        }
        // تطبيق الفلترة المبدئية لحصر العرض على فواتير الإدارة
        applyInvFilter();
    })();

    // إعادة حالة الأكورديونات المحفوظة (بعد العدّادات عشان الأرقام تتحسب على الكل)
    // 🔴 الحارس مش زيادة: `accRestore` متعرّفة في السكربت اللي **فوق جدول
    //    الفواتير**، وده جوّه فرع `else` بتاع التابات — يعني مابيترسمش
    //    في صفحة فاتورة مفتوحة ولا في تاب الخصم. أما النداء ده فبيترسم
    //    في كل الصفحات. من غير الحارس بتطلع
    //    «accRestore is not defined» (اتسجّلت ٨ مرات ٢١ سبتمبر ٢٠٢٦).
    //    والنداء أصلاً مالوش معنى من غير الجدول.
    if (typeof accRestore === 'function') accRestore();

    // فتح أصناف الفاتورة بالضغط على الصف كله (مع تمرير الفرع ونوع المستند)
    function openInvoice(id, suffix, cb, dt) {
        var url = '?invoice=' + encodeURIComponent(id) + '&suffix=' + encodeURIComponent(suffix);
        if (cb !== undefined && cb !== null && cb !== '') url += '&cb=' + encodeURIComponent(cb);
        if (dt !== undefined && dt !== null && dt !== '') url += '&dt=' + encodeURIComponent(dt);
        url += '&tab=' + encodeURIComponent((window.INV && window.INV.tab) || 'posted');
        window.location = url;
    }

    // أسماء الفروع (ديناميكي من التعريفات — يتحدّث لوحده لو ضفت فرع)
    const BRANCH_NAMES = (window.INV && window.INV.branchNames) || {};
    const BRANCH_LIST  = (window.INV && window.INV.branchList) || [];

    // ===== تاب الخصم: بوب أب اختيار الفاتورة =====
    // ===================== الفواتير المجمّعة =====================
    const GROUP_ID = (window.INV && window.INV.groupId) || 0;

    let grpRowsLoaded = false;
    let grpRowsLoading = false;

    function openGrpPicker() {
        const picker = document.getElementById('grpPicker');
        if (!picker) return;
        picker.classList.add('open');

        if (grpRowsLoaded) {
            filterGrp();
            grpFilterInvList();
            return;
        }

        loadGrpRows();
    }

    function loadGrpRows() {
        if (grpRowsLoading) return;
        grpRowsLoading = true;
        const body = document.getElementById('grpBody');
        const invList = document.getElementById('grpInvList');
        if (body) {
            body.innerHTML = '<div class="pk-loading" style="padding:28px;text-align:center;color:var(--ink-500);"><i class="ti ti-loader ti-spin"></i> جاري تحميل الفواتير…</div>';
        }

        fetch('invoices.php?ajax=grp_rows' + (GROUP_ID > 0 ? '&group_id=' + GROUP_ID : ''))
            .then(function (r) {
                if (!r.ok) throw new Error('تعذّر جلب الفواتير (' + r.status + ')');
                return r.json();
            })
            .then(function (data) {
                grpRowsLoading = false;
                if (!data || !data.ok) throw new Error(data && data.error ? data.error : 'خطأ غير معروف');

                if (body) {
                    body.innerHTML = data.html || '';
                }
                if (invList && data.inv_list_html) {
                    invList.innerHTML = data.inv_list_html;
                }

                const bc = data.branch_count || 0;
                document.querySelectorAll('.grp-admtog').forEach(function (el) {
                    if (bc > 0) {
                        el.style.display = '';
                        const b = el.querySelector('b');
                        if (b) b.textContent = '(' + bc + ')';
                    } else {
                        el.style.display = 'none';
                    }
                });

                grpRowsLoaded = true;
                filterGrp();
                grpFilterInvList();
            })
            .catch(function (err) {
                grpRowsLoading = false;
                if (body) {
                    body.innerHTML = '<div class="pk-error" style="padding:24px;text-align:center;color:var(--neg);">'
                        + '<i class="ti ti-alert-triangle"></i> ' + (err.message || 'فشل تحميل الفواتير')
                        + '<br><button type="button" class="btn btn-sm" style="margin-top:10px;" onclick="loadGrpRows()">'
                        + '<i class="ti ti-reload"></i> جرّب تاني</button></div>';
                }
            });
    }
    function closeGrpPicker() { document.getElementById('grpPicker').classList.remove('open'); }

    /* ===== شيل فاتورة / شيل صنف من المجموعة =====
       بيستخدم data-* مش onclick بمعاملات — الأسماء فيها علامات تنصيص
       وأقواس وكانت بتكسر الـattribute (بق موثّق في RULES.md).
       التوزيع مابيتمسحش — نفس مبدأ «امسح المجموعة». */
    function grpSubmitDrop(action, fields) {
        const f = document.getElementById('grpDropForm');
        if (!f) return;
        document.getElementById('grpDropAction').value = action;
        document.getElementById('grpDropSth').value  = fields.sth  || '';
        document.getElementById('grpDropSuf').value  = fields.suf  || '';
        document.getElementById('grpDropCode').value = fields.code || '';
        formSubmit(f);
    }

    document.addEventListener('click', function (ev) {
        const inv = ev.target.closest('.js-grp-drop');
        if (inv) {
            const no = inv.dataset.no || '';
            if (!confirm('تشيل فاتورة ' + no + ' من المجموعة؟\n\n'
                + 'التوزيع بتاعها هيفضل محفوظ، وهترجع تشتغل عادي من تاب الفواتير.\n'
                + 'تقدر تضيفها تاني في أي وقت وتلاقي شغلك زي ما هو.')) return;
            grpSubmitDrop('group_remove_invoice',
                { sth: inv.dataset.sth, suf: inv.dataset.suf });
            return;
        }

        const itm = ev.target.closest('.js-grp-dropitem');
        if (itm) {
            const nm = itm.dataset.name || itm.dataset.code || '';
            if (!confirm('تشيل «' + nm + '» من المجموعة؟\n\n'
                + 'هيتشال من كل فواتير المجموعة، والتوزيع اللي اتعمل عليه\n'
                + 'هيفضل محفوظ لو رجّعته تاني.')) return;
            grpSubmitDrop('group_remove_item', { code: itm.dataset.code });
        }
    });

    /* =================================================================
       بوب أب المجموعة — وضعان: [فواتير كاملة] و[تحديد أصناف]
       -----------------------------------------------------------------
       GRP_ADD_ID > 0 يعني إحنا بنضيف لمجموعة موجودة (مش بننشئ جديدة)،
       وده اللي بيحدد الـaction اللي هيتبعت للسيرفر.
       ================================================================= */
    // ⚠️ GROUP_ID (فوق) مش GRP_ID — GRP_ID متعرّف في بلوك سكربت تاني
    //    بعد ده، وقراءته هنا كانت هترمي ReferenceError. القيمة واحدة.
    const GRP_ADD_ID = GROUP_ID;            // مجموعة مفتوحة = إضافة
    let   grpModeCur = 'inv';               // الوضع الحالي
    const grpPicked  = {};                  // key -> {s,f,c,name,inv}

    function grpMode(m) {
        grpModeCur = m;
        document.querySelectorAll('.gm-btn').forEach(b =>
            b.classList.toggle('active', b.dataset.mode === m));
        const pi = document.getElementById('grpPanelInv');
        const pt = document.getElementById('grpPanelItem');
        if (pi) pi.style.display = m === 'inv'  ? '' : 'none';
        if (pt) pt.style.display = m === 'item' ? '' : 'none';

        const go = document.getElementById('grpGoTxt');
        if (go) go.textContent = GRP_ADD_ID > 0
            ? (m === 'item' ? 'ضيف الأصناف' : 'ضيف الفواتير')
            : (m === 'item' ? 'افتح مجموعة أصناف' : 'افتح المجموعة');

        if (m === 'item') { grpWay(grpWayCur); grpItemRefresh(); } else grpCount();
    }

    /**
     * Enter جوه خانات البحث كان بيبعت الفورم (إرسال ضمني) —
     * يعني مجموعة تتفتح بالغلط وانت بتكتب. ممنوع.
     */
    function grpNoEnter(ev) {
        if (ev.key === 'Enter') {
            ev.preventDefault();
            // Enter = دوّر دلوقتي من غير انتظار — كل خانة وشغلها
            if (ev.target.id === 'grpItemSearch')      grpFindItem();
            else if (ev.target.id === 'grpInvFilter')  grpFilterInvList();
            return false;
        }
        return true;
    }

    /** الـaction الصح حسب الوضع والسياق */
    function grpSubmit(ev) {
        const act = GRP_ADD_ID > 0
            ? (grpModeCur === 'item' ? 'group_add_items' : 'group_add_invoices')
            : (grpModeCur === 'item' ? 'group_create_items' : 'group_create');
        document.getElementById('grpAction').value = act;

        if (grpModeCur === 'inv') {
            const n = document.querySelectorAll('#grpBody input[name="pick[]"]:checked').length;
            if (n < (GRP_ADD_ID > 0 ? 1 : 2)) { ev.preventDefault(); return false; }
        }

        if (grpModeCur === 'item') {
            const list = Object.keys(grpPicked).map(k => {
                const p = grpPicked[k];
                return { s: p.s, f: p.f, c: p.c };
            });
            if (!list.length) { ev.preventDefault(); return false; }
            document.getElementById('grpIpicks').value = JSON.stringify(list);
            // اسم المجموعة في وضع الأصناف خانة منفصلة
            const ni = document.getElementById('grpNameI');
            const nm = document.getElementById('grpName');
            if (ni && nm) nm.value = ni.value;
        }
        const go = document.getElementById('grpGo');
        if (go) go.disabled = true;   // منع الضغط المتكرر
        return true;
    }

    function grpCount() {
        const n = document.querySelectorAll('#grpBody input[name="pick[]"]:checked').length;
        const go = document.getElementById('grpGo');
        const hint = document.getElementById('grpHint');
        // إضافة لمجموعة موجودة: فاتورة واحدة كفاية.
        // مجموعة جديدة: فاتورتين على الأقل (الواحدة تتفتح عادي من تاب الفواتير).
        const min = GRP_ADD_ID > 0 ? 1 : 2;
        go.disabled = n < min;
        hint.textContent = n < min
            ? (min === 1 ? 'اختار فاتورة أو أكتر' : 'اختار فاتورتين على الأقل')
            : (n + ' فاتورة مختارة');
        hint.style.color = n < min ? 'var(--ink-500)' : 'var(--pos)';
    }

    // ---------- طريقتين للوصول للصنف: بحث · تصفّح بالفاتورة ----------
    let grpWayCur = 'find';

    let grpFromGroup = 0;   // المجموعة اللي بنتصفّح فواتيرها (0 = كل الفواتير)

    function grpWay(w) {
        grpWayCur = w;
        grpFromGroup = 0;
        document.querySelectorAll('.giw-btn').forEach(b =>
            b.classList.toggle('active', b.dataset.way === w));
        ['find', 'browse', 'grp'].forEach(function (k) {
            const el = document.getElementById('grpWay' + k.charAt(0).toUpperCase() + k.slice(1));
            if (el) el.style.display = (k === w) ? '' : 'none';
        });

        grpHideAllBodies();
        if (w === 'browse') {
            grpBackToInvoices();
        } else if (w === 'grp') {
            document.getElementById('grpGrpList').style.display = '';
        } else {
            document.getElementById('grpItemBody').style.display = '';
            const inp = document.getElementById('grpItemSearch');
            if (inp) inp.focus();
        }
    }

    function grpHideAllBodies() {
        ['grpCrumb', 'grpInvList', 'grpItemBody', 'grpGrpList'].forEach(function (id) {
            const el = document.getElementById(id);
            if (el) el.style.display = 'none';
        });
    }

    /** إظهار/إخفاء فواتير الفروع في القايمتين */
    function grpShowBranches(on) {
        document.querySelectorAll('[data-adm="0"]').forEach(function (r) {
            r.dataset.hidebranch = on ? '' : '1';
            r.style.display = on ? '' : 'none';
        });
        // خلي الشخاطتين متطابقتين
        document.querySelectorAll('.grp-admtog input').forEach(c => { c.checked = on; });
        filterGrp();
        grpFilterInvList();
    }

    function grpBranchesShown() {
        const c = document.querySelector('.grp-admtog input');
        return c ? c.checked : true;
    }

    /** فواتير مجموعة موجودة */
    function grpOpenGroup(gid) {
        grpFromGroup = gid;
        grpHideAllBodies();
        const crumb = document.getElementById('grpCrumb');
        const body  = document.getElementById('grpItemBody');
        crumb.style.display = '';
        body.style.display  = '';
        document.getElementById('grpCrumbTitle').textContent = 'بيحمّل…';
        body.innerHTML = '<div class="pk-empty">بيجيب فواتير المجموعة…</div>';

        fetch('invoices.php?gpick=ginv&g2=' + gid)
            .then(r => r.json())
            .then(d => {
                if (!d.ok) throw new Error(d.error || 'حصل خطأ.');
                document.getElementById('grpCrumbTitle').textContent = d.title;
                body.innerHTML = d.rows.length ? d.rows.map(function (r) {
                    return '<div class="picker-row gi-invrow"'
                        + ' onclick="grpOpenInvoice(' + r.sth_id + ", '" + r.suffix + "')\">"
                        + '<span class="pk-no">' + grpEsc(r.invoice_no) + '</span>'
                        + '<span class="pk-sup">' + grpEsc(r.supplier || '—') + '</span>'
                        + '<span class="pk-date">' + grpEsc(r.date)
                        + (r.unposted ? ' · غير محفوظة' : '') + '</span>'
                        + '<span class="pk-amt"><i class="ti ti-chevron-left"></i></span>'
                        + '</div>';
                }).join('') : '<div class="pk-empty">المجموعة دي مفيهاش فواتير.</div>';
            })
            .catch(e => {
                document.getElementById('grpCrumbTitle').textContent = '';
                body.innerHTML = '<div class="pk-empty" style="color:var(--neg);">'
                    + grpEsc(e.message) + '</div>';
            });
    }

    /** رجوع: للمجموعة اللي جاي منها، أو لقايمة الفواتير/المجموعات */
    function grpBackToInvoices() {
        grpHideAllBodies();
        if (grpWayCur === 'grp') {
            if (grpFromGroup) { grpOpenGroup(grpFromGroup); return; }
            document.getElementById('grpGrpList').style.display = '';
            return;
        }
        document.getElementById('grpInvList').style.display = '';
        const f = document.getElementById('grpInvFilter');
        if (f) f.focus();
    }

    /** فلتر قايمة الفواتير في وضع التصفّح */
    /**
     * بيعد الصفوف الظاهرة تحت كل عنوان ويحدّث الرقم، ويخفي العنوان
     * لو مفيش تحته حاجة. بتتنده من كل الفلاتر.
     * @return int إجمالي الظاهر
     */
    // أول ما الصفحة تحمّل: ظبّط الأرقام على المخفي/الظاهر الحقيقي
    document.addEventListener('DOMContentLoaded', function () {
        if (document.getElementById('grpPicker')) {
            grpSyncGroupCounts('#grpBody', 'grp-row');
            grpSyncGroupCounts('#grpInvList', 'gi-invrow');
        }
    });

    function grpSyncGroupCounts(listSel, rowClass) {
        const list = document.querySelector(listSel);
        if (!list) return 0;
        let total = 0;
        list.querySelectorAll('.picker-group').forEach(function (g) {
            let n = 0, el = g.nextElementSibling;
            while (el && !el.classList.contains('picker-group')) {
                if (el.classList.contains(rowClass) && el.style.display !== 'none') n++;
                el = el.nextElementSibling;
            }
            const num = g.querySelector('.pg-n');
            if (num) num.textContent = n;
            g.style.display = n ? '' : 'none';
            total += n;
        });
        return total;
    }

    function grpFilterInvList() {
        // 🔴 المجموعة نوع «فواتير كاملة» مابتطلّعش لوحة تحديد الأصناف
        //    (`$allowItems` = false في `grp_picker`)، يعني `#grpInvFilter`
        //    و`#grpInvEmpty` **مش موجودين في الصفحة أصلاً**. و`openGrpPicker`
        //    بينده الدالة دي على طول، فكانت بترمي
        //    «Cannot read properties of null» وتوقّف باقي فتح البوب أب.
        const box = document.getElementById('grpInvFilter');
        if (!box) return;
        const q = (box.value || '').trim().toLowerCase();
        let shown = 0;
        const showBr = grpBranchesShown();
        document.querySelectorAll('#grpInvList .gi-invrow').forEach(function (r) {
            const ok = (!q || (r.dataset.s || '').includes(q))
                    && (showBr || r.dataset.adm !== '0');
            r.style.display = ok ? '' : 'none';
            if (ok) shown++;
        });
        grpSyncGroupCounts('#grpInvList', 'gi-invrow');
        const emp = document.getElementById('grpInvEmpty');
        if (emp) emp.style.display = shown ? 'none' : '';
    }

    /** افتح أصناف فاتورة معيّنة */
    function grpOpenInvoice(sth, suffix) {
        const body  = document.getElementById('grpItemBody');
        const crumb = document.getElementById('grpCrumb');

        document.getElementById('grpInvList').style.display = 'none';
        body.style.display = '';
        crumb.style.display = '';
        document.getElementById('grpCrumbTitle').textContent = 'بيحمّل…';
        body.innerHTML = '<div class="pk-empty">بيجيب أصناف الفاتورة…</div>';

        fetch('invoices.php?gpick=items&g=' + GRP_ADD_ID
              + '&sth=' + encodeURIComponent(sth)
              + '&suffix=' + encodeURIComponent(suffix || ''))
            .then(r => r.json())
            .then(d => {
                if (!d.ok) throw new Error(d.error || 'حصل خطأ.');
                document.getElementById('grpCrumbTitle').textContent =
                    d.title + ' — ' + d.rows.length + ' صنف';
                body.innerHTML = d.rows.length
                    ? d.rows.map(grpItemRow).join('')
                    : '<div class="pk-empty">الفاتورة دي مالهاش بنود في المرآة.</div>';
            })
            .catch(e => {
                document.getElementById('grpCrumbTitle').textContent = '';
                body.innerHTML = '<div class="pk-empty" style="color:var(--neg);">'
                    + grpEsc(e.message) + '</div>';
            });
    }

    /** حدد كل الأصناف الظاهرة (جوه فاتورة) */
    function grpPickAllVisible() {
        const btns = document.querySelectorAll('#grpItemBody .gi-add:not(:disabled)');
        if (!btns.length) return;
        // لو كلهم متحددين، الزرار يشيلهم
        let allOn = true;
        btns.forEach(b => { if (!b.classList.contains('on')) allOn = false; });
        btns.forEach(function (b) {
            const on = b.classList.contains('on');
            if (on === !allOn) return;
            grpItemToggle(b);
        });
    }

    // ---------- بحث الأصناف ----------
    let grpItemTimer = null;

    /**
     * ⚠️ الاسم لازم يفضل مختلف عن id الخانة (grpItemSearch).
     *    الـinline handler جوه <form> بيدوّر على الاسم في: العنصر →
     *    **الفورم** → document → window. والفورم بيرجّع أي كنترول
     *    بنفس الـid، فـ grpItemSearch() كانت بتنده على <input> مش على
     *    الدالة → "not a function" وسكوت تام. (اتكشف 14/08)
     */
    function grpFindItem() {
        const term = (document.getElementById('grpItemSearch').value || '').trim();
        const body = document.getElementById('grpItemBody');
        clearTimeout(grpItemTimer);

        if (term.length < 2) {
            body.innerHTML = '<div class="pk-empty">اكتب كود أو اسم صنف، أو امسح باركود بالكاميرا.</div>';
            return;
        }
        body.innerHTML = '<div class="pk-empty">بيدوّر…</div>';

        // debounce 300ms — نفس قاعدة باقي البحث في النظام
        grpItemTimer = setTimeout(function () {
            fetch('invoices.php?gpick=search&g=' + GRP_ADD_ID + '&term=' + encodeURIComponent(term))
                .then(r => r.json())
                .then(d => {
                    if (!d.ok) throw new Error(d.error || 'حصل خطأ.');
                    if (!d.rows.length) {
                        body.innerHTML = '<div class="pk-empty">الصنف ده مانزلش في أي فاتورة شراء أو مردود.</div>';
                        return;
                    }
                    body.innerHTML = d.rows.map(grpItemRow).join('');
                })
                .catch(e => {
                    body.innerHTML = '<div class="pk-empty" style="color:var(--neg);">' + grpEsc(e.message) + '</div>';
                });
        }, 300);
    }

    function grpEsc(t) {
        return String(t == null ? '' : t).replace(/[&<>"']/g,
            c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
    }

    function grpItemRow(r) {
        const on  = !!grpPicked[r.key];
        const dis = !!r.in_group;
        const tag = r.is_return ? 'ret' : (r.unposted ? 'unp' : '');
        const lbl = r.type_label + (r.unposted ? ' (غير محفوظة)' : '');

        return '<div class="gi-row' + (on ? ' on' : '') + (dis ? ' dis' : '') + '">'
            + '<div class="gi-main">'
            +   '<span class="gi-nm">' + grpEsc(r.item_name || r.item_code) + '</span>'
            +   '<span class="gi-sub">'
            +     '<span class="gi-doc ' + tag + '">' + grpEsc(lbl) + '</span>'
            +     '<b>' + grpEsc(r.invoice_no) + '</b>'
            +     '<span>' + grpEsc(r.item_code) + '</span>'
            +     '<span>كمية ' + (Math.round(r.qty * 100) / 100) + '</span>'
            +     '<span>' + grpEsc((r.supplier || '—')) + '</span>'
            +     '<span>' + grpEsc((r.date || '').substring(0, 10)) + '</span>'
            +   '</span>'
            + '</div>'
            + '<button type="button" class="gi-add' + (on ? ' on' : '') + '"'
            +   (dis ? ' disabled' : '')
            +   ' data-p="' + grpEsc(JSON.stringify({
                    key: r.key, s: r.sth_id, f: r.suffix, c: r.item_code,
                    name: r.item_name || r.item_code, inv: r.invoice_no
                })) + '"'
            +   ' onclick="grpItemToggle(this)">'
            + (dis ? 'في المجموعة' : (on ? '✓ متحدد' : 'تحديد')) + '</button>'
            + '</div>';
    }

    function grpItemToggle(btn) {
        const p = JSON.parse(btn.dataset.p);
        if (grpPicked[p.key]) delete grpPicked[p.key];
        else                  grpPicked[p.key] = p;

        const on = !!grpPicked[p.key];
        btn.classList.toggle('on', on);
        btn.textContent = on ? '✓ متحدد' : 'تحديد';
        btn.closest('.gi-row').classList.toggle('on', on);
        grpItemRefresh();
    }

    function grpItemDrop(key) {
        delete grpPicked[key];
        // لو الصف لسه ظاهر في النتايج، رجّعه لحالته
        document.querySelectorAll('#grpItemBody .gi-add').forEach(function (b) {
            try {
                if (JSON.parse(b.dataset.p).key === key) {
                    b.classList.remove('on'); b.textContent = 'تحديد';
                    b.closest('.gi-row').classList.remove('on');
                }
            } catch (e) {}
        });
        grpItemRefresh();
    }

    /** شريط المختار + حالة زرار الحفظ */
    function grpItemRefresh() {
        const keys = Object.keys(grpPicked);
        const box  = document.getElementById('grpChosen');
        if (box) {
            box.style.display = keys.length ? '' : 'none';
            box.innerHTML = keys.map(function (k) {
                const p = grpPicked[k];
                return '<span class="gi-chip">'
                    + '<b>' + grpEsc(p.name) + '</b>'
                    + '<span style="color:var(--ink-500);">' + grpEsc(p.inv) + '</span>'
                    + '<button type="button" title="شيل"'
                    + ' onclick="grpItemDrop(' + JSON.stringify(k).replace(/"/g, '&quot;') + ')">&times;</button>'
                    + '</span>';
            }).join('');
        }
        const go = document.getElementById('grpGo');
        const hint = document.getElementById('grpHint');
        if (grpModeCur !== 'item') return;
        if (go) go.disabled = keys.length < 1;
        if (hint) {
            hint.textContent = keys.length ? ('تم تحديد ' + keys.length + ' صنف') : 'اختار صنف واحد على الأقل';
            hint.style.color = keys.length ? 'var(--pos)' : 'var(--ink-500)';
        }
    }

    function filterGrp() {
        const q = (document.getElementById('grpSearch').value || '').trim().toLowerCase();
        let any = false;
        const showBr = grpBranchesShown();
        document.querySelectorAll('#grpBody .grp-row').forEach(r => {
            const hit = !q || (r.dataset.s || '').indexOf(q) !== -1;
            // الصف المختار يفضل ظاهر عشان ما يضيعش من المستخدم
            const checked = r.querySelector('input') && r.querySelector('input').checked;
            const admOk   = showBr || r.dataset.adm !== '0';
            r.style.display = ((hit && admOk) || checked) ? '' : 'none';
            if (hit && admOk) any = true;
        });
        grpSyncGroupCounts('#grpBody', 'grp-row');
        const emp = document.getElementById('grpEmpty');
        if (emp) emp.style.display = any ? 'none' : '';
    }

    // حفظ توزيع من الشاشة المجمّعة — السيرفر بيقسّمه بالشلال على الفواتير
    function grpSave(input) {
        const qty = parseFloat(input.value) || 0;
        const saved = parseFloat(input.dataset.saved) || 0;
        if (Math.abs(qty - saved) < 0.0001) return;

        const key = input.dataset.code + ':' + input.dataset.branch;
        clearTimeout(saveTimers[key]);

        setInd(input, '⏳', 'var(--warn)');
        const body = new URLSearchParams({
            action: 'group_autosave', csrf: CSRF,
            group_id: GROUP_ID,
            item_code: input.dataset.code,
            branch_id: input.dataset.branch,
            qty: qty
        });
        fetch('invoices.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body.toString()
        })
        .then(r => r.json())
        .then(d => {
            if (!d.ok) {
                input.value = saved > 0 ? saved : '';
                setInd(input, '✗', 'var(--neg)');
                fgToast('خطأ في الحفظ: ' + (d.error || 'حاول تاني'), 'err');
                return;
            }
            input.dataset.saved = qty;
            if (window.iflRecalcDownloads) iflRecalcDownloads();
            setInd(input, '✓', 'var(--pos)');
            setTimeout(() => setInd(input, '', ''), 1800);

            // المخزن المتبقي
            const row = input.closest('tr');
            const storeEl = row.querySelector('.store-v');
            if (storeEl && d.remaining !== undefined) {
                storeEl.textContent = Number(d.remaining).toFixed(2);
                storeEl.style.color = d.remaining <= 0.0001 ? 'var(--pos)' : '';
            }
            // تلميح: اتقسمت على أنهي فواتير (يظهر لما الصنف في أكتر من فاتورة)
            if (d.split && d.split.length > 1) {
                const txt = d.split.map(s => s.invoice_no + ':' + (+s.qty)).join(' · ');
                input.title = 'اتقسمت — ' + txt;
            }
            // حدّث أزرار التحميل كلها لحظيًا (المجمّع + كل فاتورة)
            grpUpdateDownloads(d.group_totals, d.invoice_totals);
        })
        .catch(() => {
            input.value = saved > 0 ? saved : '';
            setInd(input, '✗', 'var(--neg)');
            fgToast('مفيش اتصال بالسيرفر.', 'err');
        });
    }

    // تحديث أزرار التحميل بعد أي حفظ — كل الأزرار موجودة في الصفحة
    // من الأول (مخفية)، فإحنا بنظهر/نخفي ونحدّث العداد بس.
    // كده الفاتورة اللي اتوزّعلها دلوقتي أزرارها تبان فورًا من غير ريفريش.
    function grpUpdateDownloads(groupTotals, invoiceTotals) {
        if (!groupTotals && !invoiceTotals) return;

        // (١) المجمّع
        if (groupTotals) {
            let anyCombo = false;
            document.querySelectorAll('.grp-combo').forEach(a => {
                const t = +(groupTotals[a.dataset.branch] || 0);
                a.style.display = t > 0 ? '' : 'none';
                const c = a.querySelector('.dl-count');
                if (c) c.textContent = '(' + Math.round(t) + ')';
                if (t > 0) anyCombo = true;
            });
            const none = document.getElementById('grpComboNone');
            if (none) none.style.display = anyCombo ? 'none' : '';
        }

        // (٢) كل فاتورة على حدة
        if (invoiceTotals) {
            const perInv = {};
            document.querySelectorAll('.grp-dl').forEach(a => {
                const sth = a.dataset.sth;
                const t = +(((invoiceTotals[sth]) || {})[a.dataset.branch] || 0);
                a.style.display = t > 0 ? '' : 'none';
                const c = a.querySelector('.dl-count');
                if (c) c.textContent = '(' + Math.round(t) + ')';
                if (t > 0) perInv[sth] = true;
            });
            document.querySelectorAll('.grp-none').forEach(s => {
                s.style.display = perInv[s.dataset.sth] ? 'none' : '';
            });
        }
    }

    function openInvPicker() {
        const p = document.getElementById('invPicker');
        if (!p) return;
        p.classList.add('open');
        const s = document.getElementById('pickerSearch');
        if (s) { s.value = ''; filterPicker(); setTimeout(() => s.focus(), 60); }
    }
    function closeInvPicker() {
        const p = document.getElementById('invPicker');
        if (p) p.classList.remove('open');
    }
    function filterPicker() {
        const q = (document.getElementById('pickerSearch').value || '').trim().toLowerCase();
        let shown = 0;
        document.querySelectorAll('#pickerBody .picker-row').forEach(r => {
            const hit = !q || (r.dataset.s || '').includes(q);
            r.style.display = hit ? '' : 'none';
            if (hit) shown++;
        });
        // نخفي عنوان أي مجموعة مالهاش نتائج
        document.querySelectorAll('#pickerBody .picker-group').forEach(g => {
            let n = g.nextElementSibling, any = false;
            while (n && n.classList.contains('picker-row')) {
                if (n.style.display !== 'none') { any = true; break; }
                n = n.nextElementSibling;
            }
            g.style.display = any ? '' : 'none';
        });
        const e = document.getElementById('pkEmpty');
        if (e) e.style.display = shown ? 'none' : 'block';
    }
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeInvPicker();
    });

    // ===== التنقّل بين خانات الكميات بالأسهم =====
    // الجدول RTL: السهم الشمال بيروح للعمود اللي على شماله بصريًا.
    function gridNav(ev) {
        const k = ev.key;
        if (!['ArrowUp','ArrowDown','ArrowLeft','ArrowRight','Enter'].includes(k)) return;

        const inp = ev.target;
        if (!inp.classList.contains('dist-input')) return;

        // كل الخانات الظاهرة، مرتبة صف بصف
        const rows = [...document.querySelectorAll('.item-row')];
        const grid = rows.map(r => [...r.querySelectorAll('.dist-input')]
            .filter(i => i.offsetParent !== null));

        let r = -1, c = -1;
        grid.forEach((cells, ri) => {
            const ci = cells.indexOf(inp);
            if (ci > -1) { r = ri; c = ci; }
        });
        if (r < 0) return;

        let tr = r, tc = c;
        if (k === 'ArrowUp')    tr = r - 1;
        if (k === 'ArrowDown' || k === 'Enter') tr = r + 1;
        if (k === 'ArrowRight') tc = c - 1;   // RTL: يمين = العمود اللي قبله
        if (k === 'ArrowLeft')  tc = c + 1;

        // لو الصف الهدف مالوش خانات ظاهرة، نكمّل لحد ما نلاقي
        const step = tr > r ? 1 : -1;
        while (tr >= 0 && tr < grid.length && grid[tr].length === 0) tr += step;

        const target = grid[tr] && (grid[tr][tc] || grid[tr][Math.min(c, grid[tr].length - 1)]);
        if (target) {
            ev.preventDefault();
            target.focus();
            target.select();
        }
    }
    document.addEventListener('keydown', gridNav);

    // ===== فك قفل صف مكتمل التوزيع للتعديل =====
    function toggleRowEdit(btn) {
        const row = btn.closest('tr');
        if (!row) return;
        const open = row.classList.toggle('editing');

        row.querySelectorAll('.c-branch .prev-val').forEach(v => v.style.display = open ? 'none' : '');
        row.querySelectorAll('.c-branch .qty-wrap').forEach(w => w.style.display = open ? 'inline-block' : 'none');

        const badge = row.querySelector('.done-badge');
        const storeV = row.querySelector('.store-v');
        if (badge)  badge.style.display  = open ? 'none' : '';
        if (storeV) storeV.style.display = open ? '' : 'none';

        btn.innerHTML = open ? '<i class="ti ti-check"></i>' : '<i class="ti ti-pencil"></i>';
        btn.classList.toggle('is-open', open);
        btn.title = open ? 'إنهاء التعديل' : 'تعديل الكميات';

        if (open) {
            const first = row.querySelector('.qty-wrap .dist-input');
            if (first) { first.focus(); first.select(); }
        } else {
            // لو اتقفل والمخزن لسه فيه باقي، نحدّث الشارة
            refreshRowState(row);
        }
    }

    // تحديث شكل الصف حسب المتبقي (بعد تعديل)
    function refreshRowState(row) {
        const storeV = row.querySelector('.store-v');
        if (!storeV) return;
        const rem = parseFloat(storeV.textContent) || 0;
        const badge = row.querySelector('.done-badge');
        if (badge) {
            const done = rem <= 0.0001;
            badge.style.display = done ? '' : 'none';
            storeV.style.display = done ? 'none' : '';
            storeV.classList.toggle('has', !done);
        }
    }

    // ===== نسبة التسعير =====
    // النسبة = (سعر البيع ÷ التكلفة − 1) × 100
    // الخانة بتفضل فاضية دايمًا — الرقم الباهت جواها هو النسبة المحسوبة.
    const MRK_STEP = 0.5;   // تقريب السعر الناتج لأقرب نص جنيه

    function mrkRound(v) { return Math.round(v / MRK_STEP) * MRK_STEP; }

    function mrkFmt(p) {
        const r = Math.round(p * 10) / 10;
        return String(r);
    }

    // تحديث الرقم الباهت في خانة النسبة حسب سعر البيع الحالي
    function refreshMrk(row) {
        if (!row) return;
        const m = row.querySelector('.mrk-input');
        const s = row.querySelector('.sale-input');
        if (!m || !s) return;
        const cost = parseFloat(m.dataset.cost || '0') || 0;
        const sale = parseFloat(s.value || '0') || 0;
        m.placeholder = (cost > 0 && sale > 0) ? mrkFmt((sale / cost - 1) * 100) + '%' : '—';
    }

    // Enter في خانة النسبة = تطبيق (الموبايل بيبعت مفاتيح مختلفة)
    function mrkKey(ev, inp) {
        const k = ev.key || '';
        const isEnter = k === 'Enter' || k === 'Go' || k === 'Done' || k === 'Next'
                     || ev.keyCode === 13 || ev.which === 13;
        if (!isEnter) return;
        ev.preventDefault();
        inp.blur();   // onblur بينفّذ applyMrk
    }

    // تطبيق نسبة على صنف واحد: بتحسب سعر البيع، تحفظه، وتفضّي الخانة
    function applyMrk(inp) {
        const raw = (inp.value || '').trim();
        inp.value = '';                       // الخانة فاضية دايمًا
        if (raw === '') return;

        const p = parseFloat(raw);
        if (isNaN(p) || p <= -100) return;

        const cost = parseFloat(inp.dataset.cost || '0') || 0;
        if (cost <= 0) return;

        const row  = inp.closest('tr');
        const sale = row && row.querySelector('.sale-input');
        if (!sale) return;

        const val = mrkRound(cost * (1 + p / 100));
        sale.value = String(val);
        sale.classList.add('dirty');
        saveSalePrice(sale);
        refreshMrk(row);
    }

    // ===== النسبة العامة: تسعير كل الأصناف مرة واحدة =====
    function mrkBulkKey(ev) {
        const k = ev.key || '';
        const isEnter = k === 'Enter' || k === 'Go' || k === 'Done' || k === 'Next'
                     || ev.keyCode === 13 || ev.which === 13;
        if (!isEnter) return;
        ev.preventDefault();
        applyBulkMrk();
    }

    function applyBulkMrk() {
        const box  = document.getElementById('mrkBulk');
        const note = document.getElementById('mrkBulkNote');
        const setNote = (txt, color) => {
            if (!note) return;
            note.textContent = txt; note.style.color = color; note.style.display = 'inline';
            clearTimeout(window.__mrkNoteT);
            window.__mrkNoteT = setTimeout(() => { note.style.display = 'none'; }, 6000);
        };
        if (!box) return;

        const raw = (box.value || '').trim();
        if (raw === '') { box.focus(); return; }
        const p = parseFloat(raw);
        if (isNaN(p) || p <= -100) { setNote('✗ اكتب نسبة صحيحة', 'var(--neg)'); return; }

        // الحساب في المتصفح، والحفظ في طلب واحد
        const prices = {};
        const rows = [];
        let noCost = 0;
        document.querySelectorAll('tr.item-row').forEach(row => {
            const m = row.querySelector('.mrk-input');
            const s = row.querySelector('.sale-input');
            if (!m || !s) return;
            const cost = parseFloat(m.dataset.cost || '0') || 0;
            if (cost <= 0) { noCost++; return; }
            const val = mrkRound(cost * (1 + p / 100));
            prices[s.dataset.code] = val;
            rows.push({ input: s, row: row, val: val });
        });

        const n = rows.length;
        if (!n) { setNote('✗ مفيش أصناف ليها تكلفة', 'var(--neg)'); return; }

        const extra = noCost > 0 ? '\n(' + noCost + ' صنف من غير تكلفة هيتساب زي ما هو)' : '';
        if (!confirm('هيتسعّر ' + n + ' صنف بنسبة ' + p + '%\n\n'
                   + '⚠️ أي سعر بيع موجود دلوقتي هيتكتب فوقه.' + extra + '\n\nتمام؟')) return;

        setNote('بيحفظ ' + n + ' صنف…', 'var(--ink-500)');
        box.disabled = true;

        const body = GRP_ID > 0
            ? { action: 'group_bulk_prices', csrf: CSRF, group_id: GRP_ID, prices: JSON.stringify(prices) }
            : { action: 'bulk_prices', csrf: CSRF, distribution_id: DIST_ID, prices: JSON.stringify(prices) };

        fetch('invoices.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams(body).toString()
        })
        .then(r => r.json())
        .then(j => {
            if (!j.ok) throw new Error(j.error || 'فشل الحفظ');
            rows.forEach(r => {
                r.input.value = String(r.val);
                r.input.dataset.saved = String(r.val);
                r.input.classList.remove('dirty');
                refreshMrk(r.row);
            });
            box.value = '';
            setNote('✓ اتسعّر ' + n + ' صنف بنسبة ' + p + '%', 'var(--pos)');
        })
        .catch(err => setNote('✗ ' + err.message, 'var(--neg)'))
        .finally(() => { box.disabled = false; });
    }

    // ===== سعر البيع: تعديل وحفظ (على نسختنا فقط) =====
    function onSaleInput(inp) {
        const ind = inp.parentElement.querySelector('.sale-ind');
        if (ind) { ind.textContent = ''; ind.className = 'sale-ind'; }
        inp.classList.add('dirty');
        refreshMrk(inp.closest('tr'));   // النسبة بتتحدث مع الكتابة
    }

    // تحديث كل أسعار الفاتورة فورًا من سيستم الشركة.
    // السيستم هو المرجع — بيكتب فوق أي تعديل يدوي في الخانات.
    function refreshPrices(btn) {
        const note = document.getElementById('priceRefreshNote');
        const setNote = (txt, color) => {
            if (!note) return;
            note.textContent = txt;
            note.style.color = color;
            note.style.display = 'inline';
        };
        btn.disabled = true;
        const icon = btn.querySelector('i');
        if (icon) icon.classList.add('ti-spin');
        setNote('بيجيب الأسعار من السيستم…', 'var(--ink-500)');

        // في المجموعة: كل أصناف كل الفواتير مرة واحدة
        const body = GRP_ID > 0
            ? { action: 'group_refresh_prices', csrf: CSRF, group_id: GRP_ID }
            : { action: 'refresh_prices', csrf: CSRF,
                distribution_id: DIST_ID, invoice_id: INV_ID, suffix: INV_SUFFIX };

        fetch('invoices.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams(body).toString()
        })
        .then(r => r.json())
        .then(j => {
            if (!j.ok) throw new Error(j.error || 'فشل التحديث');
            let changed = 0;
            document.querySelectorAll('.sale-input').forEach(inp => {
                const code = inp.dataset.code;
                if (!(code in j.prices)) return;
                const v = +parseFloat(j.prices[code]).toFixed(5);
                const prev = parseFloat(inp.dataset.saved || '0') || 0;
                if (Math.abs(v - prev) >= 0.000001) changed++;
                inp.value = v || v === 0 ? String(v) : '';
                inp.dataset.saved = String(v);
                inp.classList.remove('dirty');
                refreshMrk(inp.closest('tr'));
            });
            setNote(changed > 0
                ? '✓ اتحدثت — ' + changed + ' صنف اتغير سعره'
                : '✓ اتحدثت — مفيش أسعار اتغيرت', 'var(--pos)');
        })
        .catch(err => setNote('✗ ' + err.message, 'var(--neg)'))
        .finally(() => {
            btn.disabled = false;
            if (icon) icon.classList.remove('ti-spin');
            clearTimeout(window.__refNoteT);
            window.__refNoteT = setTimeout(() => { if (note) note.style.display = 'none'; }, 5000);
        });
    }

    function saveSalePrice(inp) {
        const code = inp.dataset.code;
        const raw  = (inp.value || '').trim();
        const val  = raw === '' ? 0 : parseFloat(raw);
        if (isNaN(val) || val < 0) { inp.value = inp.dataset.saved || ''; return; }

        const prev = parseFloat(inp.dataset.saved || '0') || 0;
        if (Math.abs(val - prev) < 0.000001) { inp.classList.remove('dirty'); return; }

        const ind = inp.parentElement.querySelector('.sale-ind');
        if (ind) { ind.textContent = '⏳'; ind.className = 'sale-ind wait'; }

        // في المجموعة السعر بيتكتب على كل الفواتير اللي فيها الصنف
        const body = GRP_ID > 0
            ? { action: 'group_save_price', csrf: CSRF,
                group_id: GRP_ID, item_code: code, price: val }
            : { action: 'save_price', csrf: CSRF,
                distribution_id: DIST_ID, item_code: code, price: val };

        fetch('invoices.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams(body).toString()
        })
        .then(r => r.json())
        .then(j => {
            if (j.ok) {
                inp.dataset.saved = val;
                inp.classList.remove('dirty');
                if (ind) { ind.textContent = '✓'; ind.className = 'sale-ind ok'; }
                setTimeout(() => { if (ind) ind.textContent = ''; }, 1400);
                const note = document.getElementById('priceEditNote');
                if (note) {
                    note.style.display = 'inline';
                    clearTimeout(window.__noteT);
                    window.__noteT = setTimeout(() => note.style.display = 'none', 4000);
                }
            } else {
                if (ind) { ind.textContent = '✕'; ind.className = 'sale-ind err'; }
                fgToast(j.error || 'تعذّر الحفظ', 'err');
            }
        })
        .catch(() => { if (ind) { ind.textContent = '✕'; ind.className = 'sale-ind err'; } });
    }

    // ===== بحث الصنف (بوب أب — زي المشروع القديم) =====
    function openLookup() {
        document.getElementById('lookupResult').innerHTML = '';
        document.getElementById('lookupCode').value = '';
        document.getElementById('lookupOverlay').classList.add('open');
        setTimeout(() => document.getElementById('lookupCode').focus(), 50);
    }

    function closeLookup() {
        document.getElementById('lookupOverlay').classList.remove('open');
    }

    // Enter على الموبايل بيبعت key مختلف (Go / Search / Unidentified)
    // وممكن مايوصلش أصلاً، فبنغطي كل الاحتمالات + keyCode 13
    function handleLookupKey(ev) {
        const k = ev.key || '';
        const isEnter = k === 'Enter' || k === 'Go' || k === 'Search'
                     || k === 'Done' || k === 'Next' || k === 'Unidentified'
                     || ev.keyCode === 13 || ev.which === 13;
        if (!isEnter) return;
        ev.preventDefault();
        ev.stopPropagation();
        // نمنع التكرار لو keydown و keyup اتنفذوا مع بعض
        const now = Date.now();
        if (now - (window.__lkLast || 0) < 350) return false;
        window.__lkLast = now;
        doLookup();
        return false;
    }

    function doLookup() {
        const code = document.getElementById('lookupCode').value.trim();
        if (!code) return;
        const result = document.getElementById('lookupResult');
        result.innerHTML = '<p class="lk-muted">جاري البحث...</p>';

        // البحث في الجدول الحالي
        let found = false;
        document.querySelectorAll('.item-row').forEach(tr => {
            const rowCode = (tr.dataset.code || '').trim();
            if (rowCode === code || rowCode.includes(code)) {
                found = true;
                const name = (tr.dataset.name || '').trim();
                const qty  = tr.querySelector('.c-qty') ? tr.querySelector('.c-qty').textContent.trim() : '';
                let html = `<div class="lk-alert lk-alert-info"><strong>${escRes(name)}</strong> — كود: ${escRes(rowCode)} — إجمالي: ${escRes(qty)}</div>
                            <table class="lk-table"><thead><tr><th>الفرع</th><th>الكمية</th></tr></thead><tbody>`;

                // الكميات: من الخانات (لو لسه بيتوزّع) أو من النص (لو خلص التوزيع)
                const inputs = tr.querySelectorAll('.dist-input');
                if (inputs.length) {
                    inputs.forEach(inp => {
                        const q = parseFloat(inp.value) || 0;
                        if (q > 0) html += `<tr><td>${BRANCH_NAMES[inp.dataset.branch] || inp.dataset.branch}</td><td style="font-weight:700;">${q}</td></tr>`;
                    });
                } else {
                    tr.querySelectorAll('.c-branch').forEach((td, i) => {
                        const q = parseFloat(td.textContent.trim()) || 0;
                        if (q > 0) html += `<tr><td>${BRANCH_LIST[i] || ''}</td><td style="font-weight:700;">${q}</td></tr>`;
                    });
                }

                html += '</tbody></table>';
                // زرار «تم التوزيع الفعلي» تحت جدول الفرع/الكمية
                if (typeof iflLookupBtn === 'function') html += iflLookupBtn(rowCode);
                result.innerHTML = html;
            }
        });
        if (!found) result.innerHTML = '<div class="lk-alert lk-alert-warning">لم يتم العثور على الصنف</div>';
    }

    // ===== الحفظ التلقائي للتوزيع =====
    const DIST_ID = (window.INV && window.INV.distId) || 0;
    // المجموعة المفتوحة (تاب الفواتير المجمّعة) — صفر في باقي الشاشات.
    // الأسعار في الشاشة دي ليها مسار حفظ مختلف (كل فواتير المجموعة).
    const GRP_ID  = (window.INV && window.INV.groupId) || 0;
    const INV_ID     = (window.INV && window.INV.invId) || '';
    const INV_NO     = (window.INV && window.INV.invNo) || '';
    const INV_SUFFIX = (window.INV && window.INV.invSuffix) || '';
    const CSRF = (window.INV && window.INV.csrf) || '';
    const saveTimers = {};
    let initialStatusSel = document.getElementById('invStatusSel') ? document.getElementById('invStatusSel').value : 'auto';

    // ===== سحب أعداد الفروع من الحجوزات =====
    const resPullOverlay = document.getElementById('resPullOverlay');
    const escRes = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    function openReservationPull() {
        if (!resPullOverlay) return;
        resPullOverlay.classList.add('open');
        const body = document.getElementById('resPullBody');
        const btnSubmit = document.getElementById('btnSubmitResPull');
        const summary = document.getElementById('resPullSummaryNote');
        if (btnSubmit) btnSubmit.disabled = true;
        if (summary) summary.textContent = '';
        if (body) {
            body.innerHTML = '<div style="text-align:center;padding:40px;color:var(--ink-500);">'
                + '<i class="ti ti-loader ti-spin" style="font-size:24px;display:block;margin-bottom:8px;"></i>'
                + 'جاري فحص الحجوزات المفتوحة…</div>';
        }

        const fd = new FormData();
        fd.append('action', 'reservation_pull_preview');
        fd.append('csrf', CSRF);
        fd.append('distribution_id', DIST_ID);
        fd.append('invoice_id', INV_ID);
        fd.append('suffix', INV_SUFFIX);

        fetch('invoices.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (!d.ok) {
                    body.innerHTML = '<div class="lk-alert lk-alert-warning" style="margin:10px;">'
                        + escRes(d.error || 'تعذّر جلب بيانات الحجوزات.') + '</div>';
                    return;
                }
                renderReservationPull(d.items || []);
            })
            .catch(() => {
                body.innerHTML = '<div class="lk-alert lk-alert-warning" style="margin:10px;">تعذّر الاتصال بالسيرفر.</div>';
            });
    }

    function closeReservationPull() {
        if (resPullOverlay) resPullOverlay.classList.remove('open');
    }

    function renderReservationPull(items) {
        const body = document.getElementById('resPullBody');
        const btnSubmit = document.getElementById('btnSubmitResPull');
        const summary = document.getElementById('resPullSummaryNote');
        if (!body) return;

        if (!items || items.length === 0) {
            body.innerHTML = '<div class="res-empty-msg">'
                + '<i class="ti ti-users" style="font-size:36px;display:block;margin-bottom:10px;color:var(--ink-400);"></i>'
                + 'مفيش حجوزات على أصناف الفاتورة دي'
                + '</div>';
            if (btnSubmit) btnSubmit.disabled = true;
            if (summary) summary.textContent = '';
            return;
        }

        let html = '';
        items.forEach((it) => {
            const lines = it.lines || [];
            html += `<div class="res-card" data-item-code="${escRes(it.item_code)}" data-received="${it.received_qty}">
                <div class="res-card-head">
                    <div class="res-card-title">${escRes(it.item_name)} <span style="font-size:12px;color:var(--ink-500);font-weight:normal;">(${escRes(it.item_code)})</span></div>
                    <div class="res-card-meta">
                        <span>المستلم: <b>${it.received_qty}</b></span>
                        <span>إجمالي الحجوزات: <b>${it.total_reserved}</b></span>
                        <span>المطلوب توزيعه: <b>${it.available_qty}</b></span>
                    </div>
                </div>
                <table class="res-table">
                    <thead>
                        <tr>
                            <th>التاريخ</th>
                            <th>العميل</th>
                            <th>الفرع</th>
                            <th>المحجوز</th>
                            <th>المتبقي</th>
                            <th>المخصص</th>
                        </tr>
                    </thead>
                    <tbody>`;

            lines.forEach(l => {
                const dt = l.created_at ? l.created_at.substring(0, 10) : '—';
                const branchName = l.branch_name || 'بدون فرع';
                html += `<tr>
                    <td>${escRes(dt)}</td>
                    <td>${escRes(l.customer_name)}</td>
                    <td>${escRes(branchName)}</td>
                    <td>${l.qty}</td>
                    <td>${l.remaining_qty}</td>
                    <td>
                        <input type="number" step="1" min="0" max="${l.remaining_qty}"
                               class="res-alloc-inp"
                               data-line-id="${l.res_line_id}"
                               data-code="${escRes(it.item_code)}"
                               data-branch-id="${l.branch_id || ''}"
                               data-branch-name="${escRes(branchName)}"
                               data-max="${l.remaining_qty}"
                               value="${l.suggested_qty}"
                               oninput="onResAllocInput()">
                    </td>
                </tr>`;
            });

            html += `</tbody>
                </table>
                <div class="res-branch-sum"></div>
                <div class="res-card-foot">
                    <span>إجمالي المستلم: <b>${it.received_qty}</b></span>
                    <span>إجمالي الموزّع: <b class="res-dist-total">0</b></span>
                    <span>متبقي: <b class="res-dist-remain">${it.received_qty}</b></span>
                </div>
            </div>`;
        });

        body.innerHTML = html;
        onResAllocInput();
    }

    function onResAllocInput() {
        const cards = document.querySelectorAll('.res-card');
        const btnSubmit = document.getElementById('btnSubmitResPull');
        let allValid = true;
        let totalItemsCount = cards.length;
        let totalAllocatedSum = 0;

        cards.forEach(card => {
            const received = parseFloat(card.dataset.received) || 0;
            const inputs = card.querySelectorAll('.res-alloc-inp');
            let itemAllocSum = 0;
            const branchSums = {};

            inputs.forEach(inp => {
                const val = parseFloat(inp.value) || 0;
                const max = parseFloat(inp.dataset.max) || 0;
                const bName = inp.dataset.branchName;
                const bId = inp.dataset.branchId;

                if (val < 0 || val > max + 0.0001) {
                    inp.classList.add('invalid');
                    allValid = false;
                } else {
                    inp.classList.remove('invalid');
                }

                itemAllocSum += val;
                if (val > 0 && bId && bName && bName !== 'بدون فرع') {
                    branchSums[bName] = (branchSums[bName] || 0) + val;
                }
            });

            totalAllocatedSum += itemAllocSum;

            // تحديث الإجمالي والمتبقي للصنف
            const distTotalEl = card.querySelector('.res-dist-total');
            const distRemainEl = card.querySelector('.res-dist-remain');
            const remain = received - itemAllocSum;

            if (distTotalEl) {
                distTotalEl.textContent = itemAllocSum;
                distTotalEl.style.color = (itemAllocSum > received + 0.0001) ? 'var(--neg)' : '';
            }
            if (distRemainEl) {
                distRemainEl.textContent = remain;
                distRemainEl.style.color = (remain < -0.0001) ? 'var(--neg)' : '';
            }

            if (itemAllocSum > received + 0.0001) {
                allValid = false;
            }

            // ملخص توزيع الفروع
            const branchSumEl = card.querySelector('.res-branch-sum');
            if (branchSumEl) {
                const parts = [];
                for (const [bn, bq] of Object.entries(branchSums)) {
                    parts.push(`${bn} ${bq}`);
                }
                branchSumEl.textContent = parts.length ? ('توزيع الفروع: ' + parts.join(' · ')) : 'توزيع الفروع: —';
            }
        });

        if (totalItemsCount === 0) allValid = false;
        if (btnSubmit) {
            btnSubmit.disabled = !allValid;
        }

        const summaryNote = document.getElementById('resPullSummaryNote');
        if (summaryNote) {
            summaryNote.textContent = allValid
                ? `جاهز للاعتماد (${totalItemsCount} صنف — إجمالي التخصيص: ${totalAllocatedSum})`
                : 'يوجد خطأ في توزيع الكميات (تأكد من عدم تجاوز المستلم أو المتبقي في الحجز)';
            summaryNote.style.color = allValid ? 'var(--ink-500)' : 'var(--neg)';
        }
    }

    function submitReservationPull() {
        const btnSubmit = document.getElementById('btnSubmitResPull');
        if (btnSubmit && btnSubmit.disabled) return;

        const alloc = {};
        document.querySelectorAll('.res-alloc-inp').forEach(inp => {
            const lid = inp.dataset.lineId;
            const val = parseFloat(inp.value) || 0;
            if (lid) alloc[lid] = val;
        });

        if (btnSubmit) {
            btnSubmit.disabled = true;
            btnSubmit.innerHTML = '<i class="ti ti-loader ti-spin"></i> جاري الاعتماد…';
        }

        const fd = new FormData();
        fd.append('action', 'reservation_pull_apply');
        fd.append('csrf', CSRF);
        fd.append('distribution_id', DIST_ID);
        fd.append('invoice_no', INV_NO);
        fd.append('invoice_id', INV_ID);
        fd.append('suffix', INV_SUFFIX);
        fd.append('alloc', JSON.stringify(alloc));

        fetch('invoices.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (!d.ok) {
                    fgToast('خطأ: ' + (d.error || 'تعذّر اعتماد التوزيع'), 'err');
                    if (btnSubmit) {
                        btnSubmit.disabled = false;
                        btnSubmit.innerHTML = '<i class="ti ti-check"></i> اعتماد التوزيع';
                    }
                    return;
                }
                location.reload();
            })
            .catch(() => {
                fgToast('تعذّر الاتصال بالسيرفر.', 'err');
                if (btnSubmit) {
                    btnSubmit.disabled = false;
                    btnSubmit.innerHTML = '<i class="ti ti-check"></i> اعتماد التوزيع';
                }
            });
    }

    // ===== ملاحظات الفاتورة (حفظ تلقائي بعد وقفة كتابة) =====
    let notesTimer = null;

    function queueNotesSave() {
        const st = document.getElementById('notesState');
        if (st) { st.textContent = 'جاري الحفظ...'; st.className = 'notes-state saving'; }
        clearTimeout(notesTimer);
        notesTimer = setTimeout(saveNotes, 900);
    }

    function saveNotes() {
        const box = document.getElementById('invNotes');
        const st  = document.getElementById('notesState');
        if (!box) return;

        const fd = new FormData();
        fd.append('csrf', CSRF);
        fd.append('notes', box.value);

        // نفس الخانة بتخدم الفاتورة والمجموعة — المسار بيختلف بس
        if (GRP_ID > 0) {
            fd.append('action', 'group_save_notes');
            fd.append('group_id', GRP_ID);
        } else {
            fd.append('action', 'save_notes');
            fd.append('distribution_id', DIST_ID);
        }

        fetch('invoices.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(j => {
                if (!st) return;
                if (j.ok) { st.textContent = 'محفوظة'; st.className = 'notes-state saved'; }
                else      { st.textContent = j.error || 'فشل الحفظ'; st.className = 'notes-state'; }
            })
            .catch(() => {
                if (st) { st.textContent = 'فشل الحفظ'; st.className = 'notes-state'; }
            });
    }

    // ===== تغيير حالة الفاتورة يدوي =====
    function resetStatusSel() {
        const sel = document.getElementById('invStatusSel');
        if (sel) sel.value = initialStatusSel;
    }

    function changeStatus(val) {
        // 🔴 «تم التوزيع» اليدوي بيفتح الاستلام على طول — حتى لو فيه أصناف
        //    توزيعها ناقص. ساعتها الأصناف دي مابتوصلش لأي فرع ومحدش ياخد
        //    باله. فبنسأل السيرفر الأول ونحذّر. (قرار محمد ٢١ سبتمبر ٢٠٢٦)
        if (val !== 'done') { doChangeStatus(val); return; }

        // 🔴 بنسأل السيرفر **دلوقتي** — مش بنعتمد على قايمة محسوبة وقت
        //    تحميل الصفحة. جدول التوزيع بيتحفظ لوحده من غير ريفرش، فلو
        //    اعتمدنا على القديمة كنا هنحذّر من صنف اتوزّع خلاص، أو نسكت
        //    عن صنف اتصفّر بعد التحميل. التحذير اللي بيكدب أسوأ من مفيش.
        var fd = new FormData();
        fd.append('action', 'set_status');
        fd.append('csrf', CSRF);
        fd.append('distribution_id', DIST_ID);
        fd.append('status', val);
        fd.append('invoice_id', INV_ID);
        fd.append('suffix', INV_SUFFIX);
        fd.append('dry', '1');

        var sel = document.getElementById('invStatusSel');
        if (sel) sel.disabled = true;

        fetch('invoices.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (sel) sel.disabled = false;
                var pend = (j && j.ok && j.pending) ? j.pending : [];
                if (!pend.length) { doChangeStatus(val); return; }

                var lines = ['خلي بالك: فيه ' + pend.length + ' صنف لسه ما اتوزّعش بالكامل:', ''];
                for (var i = 0; i < pend.length; i++) {
                    lines.push('• ' + pend[i].name
                             + ' — اتوزّع ' + pend[i].done
                             + ' من ' + pend[i].qty);
                }
                lines.push('');
                lines.push('لو كمّلت، الفاتورة هتروح للفروع والأصناف دي مش هتوصل لحد.');
                lines.push('الأحسن توزّعها الأول وهي هتروح لوحدها.');
                lines.push('');
                lines.push('تكمّل برضه؟');

                if (!confirm(lines.join('\n'))) { resetStatusSel(); return; }
                doChangeStatus(val);
            })
            .catch(function () {
                // السيرفر ماردّش — التحذير مش سبب نمنع المستخدم من شغله
                if (sel) sel.disabled = false;
                doChangeStatus(val);
            });
    }

    function doChangeStatus(val) {
        const sel  = document.getElementById('invStatusSel');
        const lock = document.getElementById('invStatusLock');
        if (sel) sel.disabled = true;

        const fd = new FormData();
        fd.append('action', 'set_status');
        fd.append('csrf', CSRF);
        fd.append('distribution_id', DIST_ID);
        fd.append('status', val);
        fd.append('invoice_id', INV_ID);
        fd.append('suffix', INV_SUFFIX);

        fetch('invoices.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(j => {
                if (sel) sel.disabled = false;
                if (!j.ok) { fgToast(j.error || 'تعذّر تغيير الحالة', 'err'); resetStatusSel(); return; }

                initialStatusSel = val;
                if (lock) lock.style.display = j.manual ? '' : 'none';

                // تحديث اسم الحالة في خيار "تلقائي"
                if (!j.manual && sel) {
                    const labels = { new: 'جديدة', split: 'تم التقسيم', done: 'تم التوزيع' };
                    sel.options[0].textContent = 'تلقائي (' + (labels[j.status] || j.status) + ')';
                }
            })
            .catch(() => {
                if (sel) sel.disabled = false;
                fgToast('تعذّر الاتصال بالسيرفر', 'err');
                resetStatusSel();
            });
    }

    // التحقق اللحظي: مجموع خانات الصف ميزيدش عن كمية الصنف
    function onDistInput(input) {
        const row = input.closest('tr');
        const itemQty = parseFloat(input.dataset.qty) || 0;

        const rawBal = input.dataset.hqbal;
        const hasBal = rawBal !== undefined && rawBal !== '';

        /* السقف = الأقل بين كمية الفاتورة ورصيد الإدارة. الفاتورة ممكن
           ١٢ والواصل فعلاً ١٠ (والباقي مردود)، فتوزيع ١٢ بيطلّع بضاعة
           مش موجودة والفرق يظهر بعدين كعجز على الفرع.

           🔴 والرصيد مش واصل = التوزيع متقفل. الخانة `readonly` أصلاً،
              وده حزام أمان تاني لو حد فك الـreadonly من أدوات المطوّر.
              الحماية الحقيقية في السيرفر جوّه set_branch_distribution(). */
        if (!hasBal) {
            /* 🔴 ممنوع تزوّد، مسموح تقلّل — نفس قاعدة السيرفر بالظبط. */
            const savedNA = parseFloat(input.dataset.saved) || 0;
            if ((parseFloat(input.value) || 0) > savedNA + 0.0001) {
                input.value = savedNA > 0 ? input.dataset.saved : '';
                setInd(input, '✗ الرصيد مش واصل — تقدر تقلّل بس', 'var(--neg)');
                return;
            }
            /* تقليل أو تصفير: عدّي للحفظ زي أي تعديل عادي */
            const kNA = input.dataset.code + ':' + input.dataset.branch;
            clearTimeout(saveTimers[kNA]);
            saveTimers[kNA] = setTimeout(
                () => (input.classList.contains('grp-input') ? grpSave(input) : autoSave(input)),
                800
            );
            return;
        }

        const hqBal = Math.max(0, parseFloat(rawBal) || 0);
        const cap   = Math.min(itemQty, hqBal);

        const inputs = row.querySelectorAll('.dist-input');

        let sum = 0;
        inputs.forEach(i => sum += (parseFloat(i.value) || 0));
        if (sum > cap + 0.0001) {
            // رجّع القيمة لآخر محفوظ
            input.value = parseFloat(input.dataset.saved) > 0 ? input.dataset.saved : '';
            // قول **ليه** الحد أقل — من غير كده الرسالة بتبان غلط في
            // النظام، وهي أصلاً بتمنع توزيع بضاعة مش موجودة
            const why = (hqBal !== null && cap < itemQty - 0.0001)
                ? ('✗ رصيد الإدارة ' + cap)
                : '✗ أكبر من المتاح';
            setInd(input, why, 'var(--neg)');
            return;
        }

        // debounce: احفظ بعد وقفة قصيرة من الكتابة
        const key = input.dataset.code + ':' + input.dataset.branch;
        clearTimeout(saveTimers[key]);
        saveTimers[key] = setTimeout(
            () => (input.classList.contains('grp-input') ? grpSave(input) : autoSave(input)),
            800
        );
    }

    function autoSave(input) {
        const qty = parseFloat(input.value) || 0;
        const saved = parseFloat(input.dataset.saved) || 0;
        if (Math.abs(qty - saved) < 0.0001) return; // مفيش تغيير

        const key = input.dataset.code + ':' + input.dataset.branch;
        clearTimeout(saveTimers[key]);

        setInd(input, '⏳', 'var(--warn)');
        const body = new URLSearchParams({
            action: 'autosave', csrf: CSRF,
            distribution_id: DIST_ID,
            item_code: input.dataset.code,
            item_qty: input.dataset.qty,
            branch_id: input.dataset.branch,
            qty: qty,
            invoice_id: INV_ID,
            suffix: INV_SUFFIX
        });
        fetch('invoices.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body.toString()
        })
        .then(r => r.json())
        .then(d => {
            if (!d.ok) {
                input.value = saved > 0 ? saved : '';
                setInd(input, '✗', 'var(--neg)');
                fgToast('خطأ في الحفظ: ' + (d.error || 'حاول تاني'), 'err');
                return;
            }
            input.dataset.saved = qty;
            if (window.iflRecalcDownloads) iflRecalcDownloads();
            setInd(input, '✓', 'var(--pos)');
            setTimeout(() => setInd(input, '', ''), 1800);
            // حدّث "المخزن المتبقي" في نفس الصف
            const row = input.closest('tr');
            const storeEl = row.querySelector('.store-v');
            if (storeEl && d.remaining !== undefined) {
                storeEl.textContent = Number(d.remaining).toFixed(2);
                storeEl.style.color = d.remaining <= 0.0001 ? 'var(--pos)' : '';
            }
            // في صف اتفك قفله: نحدّث الرقم المعروض جنب الخانة
            const cell = input.closest('.c-branch');
            if (cell) {
                const pv = cell.querySelector('.prev-val');
                if (pv) {
                    const v = parseFloat(input.value) || 0;
                    pv.textContent = v > 0 ? v.toFixed(1) : '—';
                }
            }
            // حدّث زرار تحميل الفرع (يظهر لما ليه كميات، يختفي لما تبقى صفر)
            if (d.branch_total !== undefined) {
                const dl = document.querySelector('.branch-dl[data-branch="' + input.dataset.branch + '"]');
                if (dl) {
                    const cnt = dl.querySelector('.dl-count');
                    if (cnt) cnt.textContent = '(' + Math.round(d.branch_total) + ')';
                    dl.style.display = d.branch_total > 0 ? '' : 'none';
                }
                // أظهر كارت التحميل لو فيه أي زرار ظاهر
                const card = document.getElementById('branchDlCard');
                if (card) {
                    const anyVisible = Array.from(document.querySelectorAll('.branch-dl'))
                        .some(a => a.style.display !== 'none');
                    card.style.display = anyVisible ? '' : 'none';
                }
            }
        })
        .catch(() => {
            input.value = saved > 0 ? saved : '';
            setInd(input, '✗ اتصال', 'var(--neg)');
        });
    }

    function setInd(input, txt, color) {
        const ind = input.parentElement.querySelector('.save-ind');
        if (ind) { ind.textContent = txt; ind.style.color = color; }
    }

    // حفظ أي تعديلات معلّقة قبل مغادرة الصفحة
    // ملاحظة: خانات الشاشة المجمّعة ليها مسار حفظ مختلف (grpSave)،
    // لأن autoSave بيعتمد على DIST_ID/INV_ID بتوع الفاتورة الواحدة
    // واللي بيبقوا فاضيين في تاب المجموعة.
    window.addEventListener('beforeunload', () => {
        document.querySelectorAll('.dist-input').forEach(i => {
            const q = parseFloat(i.value) || 0;
            const s = parseFloat(i.dataset.saved) || 0;
            if (Math.abs(q - s) <= 0.0001) return;
            if (i.classList.contains('grp-input')) grpSave(i);
            else autoSave(i);
        });
    });

/* =====================================================================
   علامات الأصناف: لون · ملاحظة · تم التوزيع الفعلي · ملف الفرع
   ---------------------------------------------------------------
   الحالة كلها متخزّنة على الـ<tr> نفسه في data-* ، والسيرفر هو اللي
   بيحدد الفواتير اللي الحفظ هيتكتب عليها (فاتورة واحدة أو كل فواتير
   المجموعة). الواجهة مابتبعتش أرقام سجلات توزيع.
   ===================================================================== */
(function () {
    'use strict';

    let curCode = null;          // الصنف المفتوح في البوب أب
    let noteTimer = null;        // مؤقت حفظ الملاحظة من البوب أب
    const cellTimers = {};       // مؤقتات خانات الملاحظة في الجدول
    const skipSeq = {};          // آخر ضغطة لكل كود — الرد القديم بيتتجاهل

    const ov = document.getElementById('iflOverlay');

    function rowOf(code) {
        const rows = document.querySelectorAll('tr.item-row');
        for (let i = 0; i < rows.length; i++) {
            if ((rows[i].dataset.code || '') === code) return rows[i];
        }
        return null;
    }

    function state(txt, color) {
        const el = document.getElementById('iflState');
        if (el) { el.textContent = txt || ''; el.style.color = color || 'var(--ink-500)'; }
    }

    /**
     * الحفظ — بيبعت الحقول المتغيّرة بس.
     * `busy` بيمنع الضغط المتكرر السريع في تمييز الألوان من إنه يسبق نفسه.
     * الراديو والملاحظات الاتنين مش متحرسين (`guard === false`) لأن
     * المفتاح واحد وآخر كتابة هي الصح على item_export_flags وسجلات التوزيع.
     */
    const busy = {};
    function post(code, fields, guard) {
        // الملاحظات مابتتحرسش: آخر كتابة هي الصح (نفس المفتاح)، والحراسة
        // كانت هتلغي اللي المستخدم كتبه لو الحفظة اللي قبلها لسه ماردّتش.
        if (guard !== false) {
            if (busy[code]) return Promise.reject(new Error('استنى الحفظة اللي قبلها…'));
            busy[code] = true;
        }

        const fd = new FormData();
        fd.append('action', 'item_flags_save');
        fd.append('csrf', CSRF);
        fd.append('item_code', code);
        fd.append('distribution_id', DIST_ID);
        fd.append('group_id', GRP_ID);
        Object.keys(fields).forEach(k => fd.append(k, fields[k]));

        return fetch('invoices.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(j => {
                busy[code] = false;
                if (!j.ok) throw new Error(j.error || 'فشل الحفظ');
                return j;
            })
            .catch(err => { busy[code] = false; throw err; });
    }

    // ---------- تلوين الصف ----------
    function paint(row) {
        const hl   = row.dataset.hl || '';
        const done = row.dataset.done === '1';

        row.classList.remove('hl-red', 'hl-green', 'hl-yellow', 'hl-orange', 'hl-done');
        if (hl) row.classList.add('hl-' + hl);
        else if (done) row.classList.add('hl-done');

        const tick = row.querySelector('.ifl-donetick');
        if (tick) tick.style.display = done ? '' : 'none';

        const noexp = row.querySelector('.ifl-noexp');
        if (noexp) noexp.style.display = row.dataset.skip === '1' ? '' : 'none';

        const note = row.dataset.note || '';
        const ic = row.querySelector('.ifl-noteic');
        if (ic) {
            ic.classList.toggle('has', note !== '');
            ic.title = note !== '' ? note : 'مفيش ملاحظة';
        }
    }

    // ---------- فتح/قفل البوب أب ----------
    window.iflOpen = function (el) {
        const row = el.closest('tr.item-row');
        if (!row) return;
        curCode = row.dataset.code || '';

        document.getElementById('iflName').textContent = row.dataset.name || curCode;
        document.getElementById('iflCode').textContent = curCode;

        // اللون
        document.querySelectorAll('#iflSwatches button').forEach(b => {
            b.classList.toggle('on', (b.dataset.c || '') === (row.dataset.hl || ''));
        });
        // ملف الفرع
        const want = row.dataset.skip === '1' ? '1' : '0';
        document.querySelectorAll('input[name="iflSkip"]').forEach(r => { r.checked = (r.value === want); });
        // الملاحظة
        document.getElementById('iflNote').value = row.dataset.note || '';

        state('');
        ov.classList.add('open');
    };

    window.iflClose = function () {
        // لو الملاحظة لسه مستنية حفظ، احفظها فورًا قبل القفل
        if (noteTimer) { clearTimeout(noteTimer); noteTimer = null; saveNoteBox(); }
        ov.classList.remove('open');
        curCode = null;
    };

    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && ov.classList.contains('open')) iflClose();
    });

    // ---------- اللون ----------
    document.getElementById('iflSwatches').addEventListener('click', function (ev) {
        const b = ev.target.closest('button[data-c]');
        if (!b || !curCode) return;
        const c = b.dataset.c || '';
        const row = rowOf(curCode);
        if (!row) return;

        const prev = row.dataset.hl || '';
        row.dataset.hl = c;
        paint(row);
        document.querySelectorAll('#iflSwatches button').forEach(x =>
            x.classList.toggle('on', (x.dataset.c || '') === c));
        state('جاري الحفظ…');

        post(curCode, { highlight: c })
            .then(() => state('اتحفظ ✓', 'var(--pos)'))
            .catch(e => {
                row.dataset.hl = prev; paint(row);
                document.querySelectorAll('#iflSwatches button').forEach(x =>
                    x.classList.toggle('on', (x.dataset.c || '') === prev));
                state(e.message, 'var(--neg)');
            });
    });

    // ---------- ملف الفرع ----------
    window.iflSetSkip = function (v) {
        if (!curCode) return;
        const code = curCode;

        const rows = [];
        document.querySelectorAll('tr.item-row').forEach(r => {
            if ((r.dataset.code || '') === code) rows.push(r);
        });
        if (!rows.length) return;

        const prev = rows[0].dataset.skip || '0';
        rows.forEach(r => {
            r.dataset.skip = String(v);
            paint(r);
        });
        state('جاري الحفظ…');

        const my = (skipSeq[code] = (skipSeq[code] || 0) + 1);

        post(code, { skip_export: v }, false)
            .then(() => {
                if (skipSeq[code] !== my) return;
                state(v === 1
                    ? 'اتحفظ ✓ — الصنف مش هينزل في ملف أي فرع'
                    : 'اتحفظ ✓ — الصنف هينزل عادي', 'var(--pos)');
                iflRecalcDownloads();   // الأرقام على الأزرار تتظبط فورًا
            })
            .catch(e => {
                if (skipSeq[code] !== my) return;
                rows.forEach(r => {
                    r.dataset.skip = prev;
                    paint(r);
                });
                // الراديو بتاع البوب أب يترجّع **بس** لو لسه فاتح على نفس
                // الصنف — المستخدم ممكن يكون قفله وفتح صنف تاني في الوقت ده.
                if (curCode === code) {
                    document.querySelectorAll('input[name="iflSkip"]').forEach(r => {
                        r.checked = (r.value === prev);
                    });
                    state(e.message, 'var(--neg)');
                }
            });
    };

    // ---------- تم التوزيع الفعلي — من بوب أب «بحث صنف» ----------
    // الزرار بيتبني جوه نتيجة البحث (تحت جدول الفرع/الكمية) عشان تكون
    // شايف توزيعة الصنف قدامك وانت بتعلّم إنه اتسلّم فعلاً.

    /** HTML الزرار — بيتنده من doLookup */
    window.iflLookupBtn = function (code) {
        const row = rowOf(code);
        if (!row) return '';
        const on = row.dataset.done === '1';
        return '<button type="button" class="ifl-donebtn' + (on ? ' on' : '') + '"'
             + ' data-code="' + code.replace(/"/g, '&quot;') + '"'
             + ' onclick="iflLookupDone(this)">'
             + '<i class="ti ti-circle-check"></i> <span>'
             + (on ? 'تم التوزيع الفعلي ✓ (اضغط للتراجع)' : 'تم التوزيع الفعلي')
             + '</span></button>'
             + '<div class="ifl-donehint">علامة بصرية بس (الصف بيبقى أخضر). '
             + '<b>مالهاش أي أثر</b> على الكميات ولا ملفات الفروع ولا حالة الفاتورة.</div>';
    };

    function paintLookupBtn(btn, on) {
        btn.classList.toggle('on', on);
        const sp = btn.querySelector('span');
        if (sp) sp.textContent = on ? 'تم التوزيع الفعلي ✓ (اضغط للتراجع)' : 'تم التوزيع الفعلي';
    }

    window.iflLookupDone = function (btn) {
        const code = btn.dataset.code || '';
        const row  = rowOf(code);
        if (!row) return;

        const prev = row.dataset.done === '1';
        const next = !prev;
        row.dataset.done = next ? '1' : '0';
        paint(row); paintLookupBtn(btn, next);
        btn.disabled = true;

        post(code, { done_actual: next ? 1 : 0 })
            .then(() => { btn.disabled = false; })
            .catch(e => {
                btn.disabled = false;
                row.dataset.done = prev ? '1' : '0';
                paint(row); paintLookupBtn(btn, prev);
                fgToast(e.message, 'err');
            });
    };

    // ---------- الملاحظة من البوب أب ----------
    function saveNoteBox() {
        if (!curCode) return;
        const code = curCode;
        const val  = document.getElementById('iflNote').value;
        const row  = rowOf(code);
        if (!row || (row.dataset.note || '') === val.trim()) return;

        row.dataset.note = val.trim();
        paint(row);
        const cell = row.querySelector('.inote-input');
        if (cell) cell.value = val.trim();
        state('جاري الحفظ…');

        post(code, { user_notes: val }, false)
            .then(() => state('اتحفظت ✓', 'var(--pos)'))
            .then(() => {
                if (!window.isPageUnloading && val.trim() !== '') {
                    showProblemPrompt(code, val.trim(), row ? (row.dataset.name || code) : code);
                }
            })
            .catch(e => state(e.message, 'var(--neg)'));
    }

    window.iflNoteBoxTyping = function () {
        state('جاري الحفظ…');
        clearTimeout(noteTimer);
        noteTimer = setTimeout(function () { noteTimer = null; saveNoteBox(); }, 800);
    };

    // ---------- الملاحظة من خانة الجدول ----------
    function cellInd(input, txt, color) {
        const ind = input.parentElement.querySelector('.inote-ind');
        if (ind) { ind.textContent = txt; ind.style.color = color || 'var(--ink-500)'; }
    }

    window.iflNoteTyping = function (input) {
        const code = input.dataset.code;
        cellInd(input, '…');
        clearTimeout(cellTimers[code]);
        cellTimers[code] = setTimeout(function () { iflNoteSave(input); }, 800);
    };

    window.iflNoteSave = function (input) {
        const code = input.dataset.code;
        clearTimeout(cellTimers[code]);
        const row = input.closest('tr.item-row');
        const val = input.value.trim();
        if (!row || (row.dataset.note || '') === val) { cellInd(input, ''); return; }

        const prev = row.dataset.note || '';
        row.dataset.note = val;
        paint(row);
        cellInd(input, '…');

        post(code, { user_notes: val }, false)
            .then(() => {
                cellInd(input, '✓', 'var(--pos)');
                setTimeout(() => cellInd(input, ''), 1400);
                if (curCode === code) document.getElementById('iflNote').value = val;
                if (!window.isPageUnloading && val !== '') {
                    showProblemPrompt(code, val, row.dataset.name || code);
                }
            })
            .catch(e => {
                row.dataset.note = prev; paint(row);
                input.value = prev;
                cellInd(input, '✗', 'var(--neg)');
            });
    };

    /**
     * ---------- تحديث أرقام أزرار التحميل لحظيًا ----------
     * الرقم على الزرار لازم يساوي اللي هينزل في الملف بالظبط.
     * PHP بيحسبه أول ما الصفحة تفتح، لكن لما تعلّم صنف «لا ينزل في
     * التقرير» من البوب أب الرقم كان بيفضل قديم لحد ما تعمل تحديث.
     * الدالة دي بتعيد الحساب من الجدول نفسه من غير أي نداء للسيرفر.
     *
     * ⚠️ الأصناف اليتيمة (اتشالت من فاتورة الشركة) مش في الجدول أصلاً،
     *    فهي مستبعدة تلقائيًا من الحساب ده — زي التصدير بالظبط.
     */
    window.iflRecalcDownloads = function () {
        const sums = {};
        document.querySelectorAll('input.dist-input').forEach(function (inp) {
            const row = inp.closest('tr.item-row');
            if (!row || row.dataset.skip === '1') return;   // مش هينزل في الملف
            const b = inp.dataset.branch;
            const q = parseFloat(inp.dataset.saved);
            if (!b || !q || q <= 0) return;
            sums[b] = (sums[b] || 0) + q;
        });

        let any = false;
        document.querySelectorAll('a.branch-dl, a.grp-combo').forEach(function (a) {
            const t = sums[a.dataset.branch] || 0;
            const c = a.querySelector('.dl-count');
            if (c) c.textContent = '(' + Math.round(t).toLocaleString('en-US') + ')';
            a.style.display = t > 0 ? '' : 'none';
            if (t > 0) any = true;
        });

        const none = document.getElementById('grpComboNone');
        if (none) none.style.display = any ? 'none' : '';

        // لافتة «X صنف معلّم لا ينزل في التقرير»
        const skipped = [];
        document.querySelectorAll('tr.item-row').forEach(function (r) {
            if (r.dataset.skip === '1') skipped.push(r.dataset.code || '');
        });
        document.querySelectorAll('.ifl-skipnote').forEach(function (box) {
            box.style.display = skipped.length ? '' : 'none';
            const n = box.querySelector('.ifl-skipn');
            if (n) n.textContent = skipped.length;
            const l = box.querySelector('.ifl-skiplist');
            if (l) l.textContent = skipped.slice(0, 12).join(' · ') + (skipped.length > 12 ? ' …' : '');
        });
    };

    // ---------- مسح التمييز / علامات التوزيع الفعلي ----------
    window.iflClear = function (what) {
        const isDone = what === 'done';
        const msg = isDone
            ? 'مسح علامة «تم التوزيع الفعلي» من كل الأصناف؟\n\nالألوان اليدوية والملاحظات هتفضل زي ما هي.'
            : 'رجّع كل الصفوف للون الطبيعي؟\n\nعلامات «تم التوزيع الفعلي» والملاحظات هتفضل زي ما هي.';
        if (!confirm(msg)) return;

        const fd = new FormData();
        fd.append('action', 'item_flags_clear');
        fd.append('csrf', CSRF);
        fd.append('what', isDone ? 'done' : 'highlight');
        fd.append('distribution_id', DIST_ID);
        fd.append('group_id', GRP_ID);

        fetch('invoices.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(j => {
                if (!j.ok) { fgToast(j.error || 'فشل المسح', 'err'); return; }
                document.querySelectorAll('tr.item-row').forEach(row => {
                    if (isDone) row.dataset.done = '0';
                    else        row.dataset.hl = '';
                    paint(row);
                });
                if (ov.classList.contains('open')) iflClose();
            })
            .catch(() => fgToast('تعذّر الاتصال بالسيرفر', 'err'));
    };

    // ---------- إشعار إضافة الصنف لمشاكل الفواتير ----------
    var problemToastTimer = null;
    var pendingProblemItem = null;

    function showProblemPrompt(code, note, name) {
        if (window.isPageUnloading) return;
        if (!note || !note.trim()) return;

        pendingProblemItem = { code: code, note: note.trim(), name: name || code };

        var toast = document.getElementById('problemToast');
        var label = document.getElementById('problemToastItem');
        if (!toast) return;

        if (label) label.textContent = name ? ('«' + name + '»') : ('[' + code + ']');
        toast.style.display = 'block';

        if (problemToastTimer) clearTimeout(problemToastTimer);
        // الإشعار يختفي لوحده بعد 15 ثانية لو محدش داس
        problemToastTimer = setTimeout(function () {
            hideProblemPrompt();
        }, 15000);
    }

    function hideProblemPrompt() {
        var toast = document.getElementById('problemToast');
        if (toast) toast.style.display = 'none';
        if (problemToastTimer) {
            clearTimeout(problemToastTimer);
            problemToastTimer = null;
        }
        pendingProblemItem = null;
    }

    var btnNo = document.getElementById('problemToastNo');
    if (btnNo) {
        btnNo.addEventListener('click', function () {
            hideProblemPrompt();
        });
    }

    var btnYes = document.getElementById('problemToastYes');
    if (btnYes) {
        btnYes.addEventListener('click', function () {
            if (!pendingProblemItem) return;
            var item = pendingProblemItem;
            hideProblemPrompt();

            var fd = new FormData();
            fd.append('action', 'add_to_problems');
            fd.append('csrf', CSRF);
            fd.append('distribution_id', DIST_ID);
            fd.append('group_id', GRP_ID);
            fd.append('item_code', item.code);
            fd.append('item_name', item.name);
            fd.append('note', item.note);

            fetch('invoices.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(j => {
                    if (!j.ok) {
                        console.error(j.error || 'فشل إضافة الصنف لمشاكل الفواتير');
                    }
                })
                .catch(e => console.error('تعذر الاتصال بالسيرفر'));
        });
    }

    // احفظ أي ملاحظة معلّقة قبل مغادرة الصفحة
    window.addEventListener('beforeunload', function () {
        window.isPageUnloading = true;
        document.querySelectorAll('.inote-input').forEach(function (i) {
            const row = i.closest('tr.item-row');
            if (row && (row.dataset.note || '') !== i.value.trim()) iflNoteSave(i);
        });
    });

    // تأكيد فتح الاستلام عند تنزيل ملف التوزيع لأول مرة
    document.addEventListener('click', function (e) {
        var link = e.target.closest('.branch-dl, .grp-combo, .grp-dl');
        if (!link) return;

        var card = link.closest('[data-receiving-open]');
        if (!card) return;

        var isOpen = card.getAttribute('data-receiving-open');
        if (isOpen === '1') return; // تم فتح الاستلام مسبقاً — تنزيل عادي بدون سؤال

        e.preventDefault();
        var targetUrl = link.href;
        var isGrp = link.classList.contains('grp-combo');
        var headMsg = isGrp
            ? 'هل تريد إرسال المجموعة كلها لموديول استلام الفروع؟'
            : 'هل تريد إرسال الفاتورة دي لوحدها لموديول استلام الفروع؟';
        if (isGrp) {
            var solo = parseInt(card.getAttribute('data-solo-count') || '0', 10);
            if (solo > 0) {
                headMsg += '\n⚠️ ' + solo + ' فاتورة اتبعتت لوحدها ومش هتنزل في الملف ده.';
            }
        }
        var msg = headMsg + '\n\n'
                + '• الضغط على "موافق": إرسال لموديول الاستلام وتنبيه الفروع وبدء المتابعة.\n'
                + '• الضغط على "إلغاء": تنزيل الملف فقط كتقرير دون إرساله للاستلام وبدون إشعارات.';

        if (confirm(msg)) {
            card.setAttribute('data-receiving-open', '1');
            window.location.href = targetUrl;
        } else {
            var sep = targetUrl.indexOf('?') !== -1 ? '&' : '?';
            window.location.href = targetUrl + sep + 'skip_receiving=1';
        }
    });
})();