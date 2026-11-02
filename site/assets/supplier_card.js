(function () {
    'use strict';
    const boot = JSON.parse(document.getElementById('supBoot').textContent);
    const $ = id => document.getElementById(id);
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
    const num = v => v == null ? '—' : Number(v).toLocaleString('en-US', {maximumFractionDigits: 3});
    const money = v => v == null ? '—' : Number(v).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 3});
    const state = {card: boot.card, tab: 'info', dialog: null, generation: 0, search: 0, requests: {}, pending: new Set()};
    function status(msg, bad = false, id = 'scStatus') {
        const el = $(id); el.textContent = msg; el.hidden = !msg;
        el.className = 'falert ' + (bad ? 'falert-neg' : 'falert-info');
    }
    async function api(part, params = {}, body = null) {
        const q = new URLSearchParams({part, ...params});
        const r = await window.apiFetch('supplier_card_api.php?' + q, body === null ? {} : {method: 'POST', body});
        return r.data;
    }
    function show(mode) {
        $('scCard').hidden = mode !== 'card';
        if ($('scCreate')) $('scCreate').hidden = mode !== 'new';
        $('scMissing').hidden = mode !== 'missing';
    }
    function missing(code) {
        show('missing'); $('scMissingText').textContent = 'كود المورد ' + code + ' مش متسجّل عندنا';
    }
    function read(label, value, dir = 'auto', wide = false, classes = '', html = false) {
        const text = value === '' || value == null ? '—' : value;
        return '<div class="ffield ' + (wide ? 'sc-wide' : '') + '"><label>' + esc(label) + '</label><div class="sc-val ' + classes + '" dir="' + dir + '">' + (html ? text : esc(text)) + '</div></div>';
    }
    function field(label, key, value, type = 'text', wide = false) {
        const attrs = ' name="' + key + '" data-field="' + key + '" data-old="' + esc(value ?? '') + '" dir="' + (['phone','phone2','tax_no'].includes(key) ? 'ltr' : 'auto') + '"';
        let input;
        if (key === 'notes') input = '<textarea class="finput"' + attrs + ' rows="3">' + esc(value) + '</textarea>';
        else if (key === 'payment_type' || key === 'is_active') {
            const opts = key === 'is_active' ? [['1','نشط'],['0','متوقف']] : [['','من غير تحديد'],['cash','كاش'],['credit','آجل']];
            input = '<select class="fselect"' + attrs + '>' + opts.map(([v,t]) => '<option value="' + v + '" ' + (String(value ?? '') === v ? 'selected' : '') + '>' + t + '</option>').join('') + '</select>';
        } else input = '<input class="finput"' + attrs + ' type="' + type + '" value="' + esc(value) + '"' + (key === 'name' ? ' required maxlength="120"' : '') + (type === 'number' ? ' min="0" step="' + (key === 'credit_limit' ? '0.01' : '1') + '"' : '') + '>';
        return '<div class="ffield ' + (wide ? 'sc-wide' : '') + '"><label>' + esc(label) + '</label>' + input + '<small class="falert falert-info" data-save-status role="status" hidden></small></div>';
    }
    function fields(s) {
        return '<div class="sc-fields">' + read('كود المورد — تلقائي (تقديري قبل الحفظ)', s.erp_supplier_code || boot.nextCode, 'ltr') +
            field('الرقم الضريبي', 'tax_no', s.tax_no) + field('اسم المورد / الشركة التجارية', 'name', s.name, 'text', true) +
            field('التليفون الأساسي', 'phone', s.phone) + field('تليفون إضافي / واتساب', 'phone2', s.phone2) +
            field('طريقة التعامل', 'payment_type', s.payment_type) + field('فترة السماح (أيام)', 'credit_days', s.credit_days, 'number') +
            (boot.canPrices ? field('حد الائتمان', 'credit_limit', s.credit_limit, 'number') : '') + field('الحالة', 'is_active', s.is_active ?? 1) +
            field('العنوان بالتفصيل', 'address', s.address, 'text', true) + field('مكان الاستلام', 'receiving_location', s.receiving_location) + field('التخصص التجاري', 'trade_specialty', s.trade_specialty) + field('ملاحظات التعامل', 'notes', s.notes, 'text', true) + '</div>';
    }
    function newSupplier() {
        if (!boot.canEdit) return;
        state.generation++; show('new'); status(''); $('scCreateFields').innerHTML = fields({});
        $('scCreate').elements.name.focus();
    }
    function renderInfo(edit = false) {
        const s = state.card.supplier;
        if (edit) {
            $('scInfo').innerHTML = '<form id="scEdit">' + fields(s) + '<div class="fcard-actions"><button class="btn btn-ghost" type="button" id="scCancelEdit">قفل التعديل</button></div></form>';
            return;
        }
        const active = Number(s.is_active);
        const days = s.credit_days == null ? '—' : num(s.credit_days) + ' يوم';
        const credit = boot.canPrices && s.credit_limit != null ? money(s.credit_limit) + ' ج.م' : '—';
        $('scInfo').innerHTML = (boot.canEdit ? '<button class="btn btn-outline sc-edit-action" id="scEditBtn">تعديل</button>' : '') +
            '<div class="sc-fields sc-fields-top">' + read('كود المورد', s.erp_supplier_code, 'ltr') +
            read('الحالة التجارية', '<span class="fchip ' + (active ? 'fchip-pos' : 'fchip-mute') + '">' + (active ? 'نشط ومتاح للتوريد' : 'متوقف') + '</span>', 'auto', false, '', true) + '</div>' +
            read('اسم المورد التجاري', s.name, 'auto', true, 'sc-name-val') +
            '<div class="sc-fields sc-fields-contact">' + read('التليفون الأساسي', s.phone, 'ltr') + read('تليفون إضافي / واتساب', s.phone2, 'ltr') +
            read('طريقة الدفع المعتمدة', s.payment_type ? payment(s) : '—') + read('فترة السماح والائتمان', days + ' · ' + credit) + '</div>' +
            '<div class="sc-sec">بيانات الموقع والتسليم</div><div class="sc-fields sc-fields-location">' + read('العنوان ومقر التوزيع', s.address) + read('مكان استلام البضاعة', s.receiving_location) + read('التخصص التجاري', s.trade_specialty) + '</div>' +
            '<div class="sc-sec">ملاحظات التعامل التجاري</div><div class="sc-val sc-notes">' + esc(s.notes || '—') + '</div>';
    }
    function payment(s) {return s.payment_type === 'credit' ? 'آجل' : s.payment_type === 'cash' ? 'كاش' : 'من غير تحديد';}
    function sideRows(rows) {
        return '<div class="sc-rows">' + rows.map(([label, value]) => '<div><span>' + esc(label) + '</span><span>' + esc(value == null || value === '' ? '—' : value) + '</span></div>').join('') + '</div>';
    }
    function sideCard(title, value, details = '', tone = '') {
        return '<div class="fcard"><h3 class="sc-side-title">' + esc(title) + '</h3><div class="sc-big ' + tone + '">' + value + '</div>' + details + '</div>';
    }
    function renderHeader() {
        const c = state.card, s = c.supplier;
        $('scCodeInput').value = s.erp_supplier_code; $('scCode').textContent = s.erp_supplier_code; $('scName').textContent = s.name;
        $('scActive').textContent = Number(s.is_active) ? 'نشط ✓' : 'متوقف';
        $('scActive').className = 'fchip ' + (Number(s.is_active) ? 'fchip-pos' : 'fchip-mute');
        $('scPayment').textContent = s.payment_type ? payment(s) + (s.payment_type === 'credit' && s.credit_days != null ? ' (' + num(s.credit_days) + ' يوم)' : '') : '—';
        $('scItemCount').textContent = num(c.counts.items) + ' صنف مورّد';
        $('scClassification').textContent = s.trade_specialty || '';
        $('scClassification').hidden = !s.trade_specialty;
    }
    function renderCard() {
        const c = state.card, s = c.supplier, latest = c.latest;
        const amount = value => boot.canPrices && value != null ? money(value) + ' ج.م' : '—';
        const bigAmount = value => boot.canPrices && value != null ? money(value) + ' <small>ج.م</small>' : '—';
        renderHeader();
        $('scPrint').href = c.statement_url; $('scPrint').hidden = !boot.canPrices;
        document.querySelectorAll('#scTabs [data-tab]').forEach(b => {b.textContent = b.dataset.label + (c.counts[b.dataset.tab] == null ? '' : ' (' + num(c.counts[b.dataset.tab]) + ')');});
        renderInfo();
        document.querySelectorAll('[data-period]').forEach(f => f.reset());
        const bal = boot.canPrices ? c.statement.period.final_balance : null;
        const badge = bal == null ? '—' : bal > 0 ? 'مستحق للمورد' : bal < 0 ? 'مستحق على المورد' : 'الحساب متعادل';
        const balanceDetails = '<div class="sc-badges"><span class="fchip ' + (bal > 0 ? 'fchip-warn' : 'fchip-mute') + '">' + badge + '</span><span>حد الائتمان ' + esc(amount(s.credit_limit)) + '</span></div>';
        const invoices = c.last_purchases || [];
        const balanceRows = [0, 1].map(i => [invoices[i] ? 'فاتورة ' + invoices[i].doc_date : (i ? 'الفاتورة اللي قبلها' : 'آخر فاتورة'), amount(invoices[i]?.net_amount)]);
        // مفيش تخصيص للسداد على الفواتير، فمانعرضش ميعاد سداد تقديري كأنه مؤكد.
        balanceRows.push(['فترة السداد', c.payment_days_left == null ? '—' : 'متبقي ' + num(c.payment_days_left) + ' يوم']);
        let side = sideCard('الرصيد الحالي', bigAmount(bal == null ? null : Math.abs(bal)), balanceDetails + sideRows(balanceRows), bal > 0 ? 'sc-due' : '');
        side += sideCard('آخر فاتورة شراء', bigAmount(latest?.net_amount), sideRows([
            ['رقم الفاتورة', latest?.doc_no], ['فاتورة المورد', latest?.supplier_invoice], ['تاريخ الشراء', latest?.doc_date],
            ['فرع الاستلام', latest?.branch], ['عدد الأصناف', latest ? num(latest.item_count) + ' أصناف' : '—']
        ]), 'sc-accent');
        side += sideCard('مشتريات آخر 90 يوم', bigAmount(c.recent?.net), sideRows([
            ['عدد الفواتير', c.recent ? num(c.recent.count) + ' فاتورة شراء' : '—'], ['إجمالي المرتجعات', amount(c.recent?.returns)],
            ['إجمالي المسدد', amount(c.recent?.paid)], ['المتبقي غير مسدد', amount(c.recent?.unpaid)]
        ]));
        side += sideCard('الأصناف المورّدة', num(c.counts.items) + ' <small>صنف</small>', sideRows((c.sections || []).length ? c.sections.map(r => [r.name, num(r.item_count) + ' أصناف']) : [['القسم', '—']]));
        $('scSidebar').innerHTML = side;
        show('card'); switchTab(boot.print && boot.canPrices ? 'statement' : 'info');
    }
    function lockedMessage() {return boot.hasPrices ? 'الأرقام الحساسة مقفولة — افتحها من الهيدر' : 'مالكش صلاحية التكاليف وكشف الحساب';}
    async function openCard(code) {
        const generation = ++state.generation; status('جاري تحميل الكارت…');
        try {
            const card = await api('card', {code}); if (generation !== state.generation) return;
            if (!card) {missing(code); status(''); return;}
            state.card = card; renderCard(); history.replaceState(null, '', '?code=' + encodeURIComponent(code)); status('');
            if (state.dialog) state.dialog.querySelector('.fg-dlg-ok').click();
        } catch (e) {if (generation === state.generation) status(e.message, true);}
    }
    async function lookup() {
        const code = $('scCodeInput').value.trim(); if (!code) return;
        status('جاري البحث…');
        try {const s = await api('find', {code}); if (s) await openCard(s.erp_supplier_code); else {state.generation++; missing(code); status('');}}
        catch (e) {status(e.message, true);}
    }
    async function search() {
        const q = $('scSearchInput').value.trim(), seq = ++state.search;
        if (!q) {$('scResults').innerHTML = ''; return;}
        status('جاري البحث…', false, 'scSearchStatus');
        try {
            const d = await api('search', {q}); if (seq !== state.search) return;
            $('scResults').innerHTML = table('scSearchTable', [['erp_supplier_code','الكود'],['name','اسم المورد'],['phone','التليفون'],['payment_type','طريقة التعامل'],['is_active','الحالة']], d.rows.map(r => ({...r, payment_type: payment(r), is_active: Number(r.is_active) ? 'نشط' : 'متوقف'})), r => r.erp_supplier_code ? ' class="sc-lines-row" role="button" tabindex="0" data-code="' + esc(r.erp_supplier_code) + '"' : '');
            status(d.rows.length === 50 ? 'أول ٥٠ نتيجة — كمّل الاسم لتضييق البحث' : d.rows.length ? '' : 'مفيش نتايج', false, 'scSearchStatus');
        } catch (e) {if (seq === state.search) status(e.message, true, 'scSearchStatus');}
    }
    async function nameSearch() {
        if (state.dialog) return;
        const done = window.fgAlert('ابحث باسم المورد', 'بحث بالاسم');
        await Promise.resolve(); await Promise.resolve();
        const dialog = document.querySelector('.fg-dlg-bg:last-of-type .fg-dlg'); if (!dialog) return;
        state.dialog = dialog; dialog.classList.add('sc-search-dialog');
        // المرجع محفوظ: المودال لما يتقفل بيتشال من الصفحة ومعاه الـview، فـ$('scSearchView') بيرجع null
        const view = $('scSearchView');
        const host = dialog.querySelector('.fg-dlg-msg'); host.textContent = ''; host.appendChild(view);
        view.hidden = false; $('scSearchInput').focus();
        await done; view.hidden = true; $('scSearchHome').appendChild(view); state.dialog = null;
    }
    function table(id, cols, rows, attributes = () => '', detail = false) {
        return '<div class="ftable-wrap"><table class="ftable" id="' + id + '"><thead><tr>' + cols.map(c => '<th' + (c[2] ? ' class="num"' : '') + '>' + c[1] + '</th>').join('') + '</tr></thead><tbody>' + rows.map(r => '<tr' + attributes(r) + ' data-v-total="' + esc(r.total ?? 0) + '" data-v-net="' + esc(r.net_amount ?? 0) + '" data-v-debit="' + esc(r.debit ?? 0) + '" data-v-credit="' + esc(r.credit ?? 0) + '" data-v-quantity="' + esc(r.quantity ?? 0) + '">' + cols.map(([k,,n]) => '<td' + (n ? ' class="num"' : '') + (k === 'name' ? ' dir="auto" class="name" title="' + esc(r[k]) + '"' : k === 'phone' ? ' dir="ltr"' : '') + '>' + (n ? (n === 'money' ? money(r[k]) : num(r[k])) : esc(r[k] ?? '—')) + '</td>').join('') + '</tr>' + (detail ? '<tr class="ftable-detail" data-xt-ignore data-xt-nosort hidden><td colspan="' + cols.length + '"></td></tr>' : '')).join('') + '</tbody></table></div>';
    }
    function kpis(id, rows, sums) {
        return '<div class="fkpis" data-xt-kpis="#' + id + '"><div class="fkpi"><span>العدد</span><strong data-xt-count>' + num(rows.length) + '</strong></div>' + sums.map(([key,label]) => '<div class="fkpi"><span>' + label + '</span><strong data-xt-sum="' + key + '">' + money(rows.reduce((a,r) => a + Number(r[key === 'net' ? 'net_amount' : key] || 0), 0)) + '</strong></div>').join('') + '</div>';
    }
    function switchTab(tab) {
        state.tab = tab;
        document.querySelectorAll('#scTabs [data-tab]').forEach(b => {const on = b.dataset.tab === tab; b.classList.toggle('is-active', on); b.setAttribute('aria-selected', String(on));});
        document.querySelectorAll('[data-panel]').forEach(p => {p.hidden = p.dataset.panel !== tab;});
        if (tab !== 'info') loadTab(tab);
    }
    async function loadTab(tab) {
        const host = $('scData-' + tab);
        if (!boot.canPrices) {host.innerHTML = '<div class="falert falert-warn">' + lockedMessage() + '</div>'; return;}
        const generation = state.generation, code = state.card.supplier.erp_supplier_code;
        const request = (state.requests[tab] || 0) + 1; state.requests[tab] = request;
        const params = {code}, form = document.querySelector('[data-period="' + tab + '"]');
        if (form) ['from','to'].forEach(k => {if (form.elements[k].value) params[k] = form.elements[k].value;});
        host.classList.remove('falert', 'falert-warn'); host.textContent = 'جاري التحميل…';
        try {
            const d = await api(tab, params); if (generation !== state.generation || request !== state.requests[tab]) return;
            const id = 'scTable-' + tab;
            if (tab === 'statement') {
                form.elements.from.value = d.from; form.elements.to.value = d.to;
                // الرصيد التراكمي من دالة كشف الحساب الأصلية، مابيتحسبش تاني هنا.
                const rows = d.moves.map(r => ({...r, doc: r.doc_no || '—', name: r.description || (Number(r.credit) > 0 ? 'فاتورة شراء' : 'مرتجع شراء')}));
                host.innerHTML = (boot.print ? '<h2>' + esc(state.card.supplier.name) + ' · ' + esc(state.card.supplier.erp_supplier_code) + '</h2>' : '') + '<p class="fpage-sub">رصيد قبل الفترة: ' + money(d.prior.balance) + ' · رصيد آخر الفترة: ' + money(d.period.final_balance) + '</p>' +
                    kpis(id, rows, [['debit','مدين'],['credit','دائن']]) + table(id, [['tx_date','التاريخ'],['doc','المستند'],['name','البيان'],['debit','مدين','money'],['credit','دائن','money'],['balance','الرصيد التراكمي','money']], rows);
            } else if (tab === 'items') {
                host.innerHTML = '<p class="fpage-sub">الكمية وآخر تكلفة بالقطعة الأساسية</p>' + kpis(id, d.rows, [['quantity','إجمالي الكمية']]) + table(id, [['code','كود الصنف'],['name','اسم الصنف'],['quantity','إجمالي الكمية','number'],['cost','آخر تكلفة','money'],['min_cost','أقل تكلفة','money'],['max_cost','أعلى تكلفة','money'],['purchase_date','آخر تاريخ شراء'],['item_balance','رصيد الصنف','number']], d.rows);
            } else {
                const rows = d.rows.map(r => ({...r, source: r.src === 'hq' ? 'ترو' : 'الموقع'}));
                const extra = tab === 'purchases' ? [['supplier_invoice','فاتورة المورد'],['payment_status','حالة السداد']] : [['return_reason','سبب المرتجع']];
                host.innerHTML = kpis(id, rows, [['net','الصافي']]) + table(id, [['doc_no','الرقم'],['doc_date','التاريخ'],['branch','الفرع'],['item_count','عدد الأصناف','number'],['total_amount','الإجمالي','money'],['net_amount','الصافي','money'],...extra,['source','المصدر']], rows,
                    r => ' class="sc-lines-row" role="button" tabindex="0" aria-expanded="false" data-src="' + r.src + '" data-doc="' + esc(r.id) + '"', true);
            }
            if (boot.print && tab === 'statement') window.print();
        } catch (e) {if (generation === state.generation && request === state.requests[tab]) {host.textContent = e.message; host.classList.add('falert', 'falert-warn');}}
    }
    async function toggleRow(row) {
        const detail = row.nextElementSibling;
        const open = row.getAttribute('aria-expanded') !== 'true';
        row.setAttribute('aria-expanded', String(open)); row.classList.toggle('is-open', open); detail.hidden = !open;
        if (!open || detail.dataset.loaded || detail.dataset.loading) return;
        detail.dataset.loading = '1'; detail.firstElementChild.textContent = 'جاري تحميل الأصناف…';
        try {
            const d = await api('lines', {src: row.dataset.src, id: row.dataset.doc});
            detail.firstElementChild.innerHTML = table('scLines-' + row.dataset.src + '-' + row.dataset.doc.replace(/[^a-zA-Z0-9]/g, '-'), [['code','كود الصنف'],['name','اسم الصنف'],['quantity','الكمية','number'],['cost','التكلفة','money'],['total','الإجمالي','money']], d.rows);
            detail.dataset.loaded = '1';
        } catch (e) {detail.firstElementChild.textContent = e.message;}
        finally {delete detail.dataset.loading;}
    }
    async function saveField(el) {
        if (!boot.canEdit || !state.card || el.dataset.saving) return;
        const value = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
        if (String(value) === el.dataset.old) return;
        const supplier = state.card.supplier, old = el.dataset.old;
        const mark = el.parentElement.querySelector('[data-save-status]');
        const feedback = (message, tone) => {mark.textContent = message; mark.hidden = false; mark.className = 'falert falert-' + tone;};
        feedback('جاري الحفظ…', 'info'); el.dataset.saving = '1'; el.disabled = true;
        const request = api('save_field', {}, {code: supplier.erp_supplier_code, field: el.dataset.field, value});
        state.pending.add(request);
        try {
            const data = await request;
            const saved = String(data.value ?? '');
            el.dataset.old = saved;
            if (el.type === 'checkbox') el.checked = saved === '1'; else el.value = saved;
            supplier[el.dataset.field] = data.value;
            if (state.card.supplier === supplier) renderHeader();
            feedback('✓', 'pos');
        } catch (e) {
            if (el.type === 'checkbox') el.checked = old === '1'; else el.value = old;
            feedback(e.message, 'neg');
        } finally {el.disabled = false; delete el.dataset.saving; state.pending.delete(request);}
    }
    async function closeEdit() {
        const card = state.card;
        await Promise.allSettled(Array.from(state.pending));
        if (state.card === card) renderCard();
    }
    async function save(form, create) {
        if (!form.reportValidity()) return;
        const body = Object.fromEntries(new FormData(form)); if (!create) body.code = state.card.supplier.erp_supplier_code;
        const button = form.querySelector('[type="submit"]'); button.disabled = true; status('جاري الحفظ…');
        try {const d = await api('save', {}, body); await openCard(d.code); if (create) boot.nextCode = String(Number(d.code) + 1);}
        catch (e) {status(e.message, true);}
        finally {button.disabled = false;}
    }
    document.addEventListener('DOMContentLoaded', () => {
        $('scCodeInput').addEventListener('keydown', e => {if (e.key === 'Enter') {e.preventDefault(); lookup();}});
        $('scSearchBtn').addEventListener('click', nameSearch);
        if ($('scNewBtn')) $('scNewBtn').addEventListener('click', newSupplier);
        if ($('scDefine')) $('scDefine').addEventListener('click', newSupplier);
        let timer; $('scSearchInput').addEventListener('input', () => {clearTimeout(timer); timer = setTimeout(search, 300);});
        document.addEventListener('click', e => {
            const pick = e.target.closest('tr[data-code]'); if (pick) {openCard(pick.dataset.code); return;}
            const row = e.target.closest('tr[data-doc]'); if (row && !e.target.closest('a,button,input')) {toggleRow(row); return;}
            const tab = e.target.closest('#scTabs [data-tab]'); if (tab) switchTab(tab.dataset.tab);
            if (e.target.closest('#scEditBtn')) renderInfo(true);
            if (e.target.closest('#scCancelEdit')) closeEdit();
            if (e.target.closest('[data-cancel]')) {show(state.card ? 'card' : 'empty'); status('');}
        });
        document.addEventListener('keydown', e => {
            if (state.dialog && e.key === 'Tab' && state.dialog.contains(e.target)) {
                const controls = Array.from(state.dialog.querySelectorAll('input,select,button,a[href],[tabindex="0"]')).filter(el => !el.disabled && el.offsetParent !== null);
                if (controls.length) {e.preventDefault(); e.stopImmediatePropagation(); const index = controls.indexOf(document.activeElement); controls[(index + (e.shiftKey ? -1 : 1) + controls.length) % controls.length].focus();} return;
            }
            if (!['Enter',' '].includes(e.key)) return;
            const row = e.target.closest('tr[data-code],tr[data-doc]');
            if (row) {e.preventDefault(); e.stopImmediatePropagation(); row.click();}
            else if (e.target === $('scSearchInput') && e.key === 'Enter') {e.preventDefault(); e.stopImmediatePropagation(); search();}
        }, true);
        $('scInfo').addEventListener('blur', e => {if (e.target.matches('[data-field]') && e.target.tagName !== 'SELECT' && e.target.type !== 'checkbox') saveField(e.target);}, true);
        $('scInfo').addEventListener('change', e => {if (e.target.matches('select[data-field],input[type=checkbox][data-field]')) saveField(e.target);});
        document.addEventListener('submit', e => {
            const form = e.target;
            if (form.id === 'scEdit') {e.preventDefault(); document.activeElement.blur();}
            else if (form.id === 'scCreate') {e.preventDefault(); save(form, true);}
            else if (form.dataset.period) {e.preventDefault(); loadTab(form.dataset.period);}
        });
        if (state.card) renderCard(); else if (boot.code) missing(boot.code);
    });
}());
