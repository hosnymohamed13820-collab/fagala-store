/* أرباح الموردين: الداتا والإجماليات من نفس رد الـAPI. */
document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    const root = document.getElementById('fifoRoot');
    if (!root) return;
    const el = id => document.getElementById(id);
    const form = el('fifoFilters');
    const esc = value => String(value == null ? '' : value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const num = value => value == null ? '—' : Number(value).toLocaleString('ar-EG', {maximumFractionDigits: 2});
    const ageKeys = ['0-30','31-60','61-90','90+'];
    let report = 'supplier_invoices', data = null, page = 0, sequence = 0, serial = 0;
    let loadedQuery = '';
    const actions = new Map();
    const common = [['revenue','الإيراد'],['cost','التكلفة'],['profit','الربح'],['margin','النسبة %']];
    const agingCols = ageKeys.map(k => ['age:' + k, 'عمر الباقي ' + (k === '90+' ? 'أكتر من 90' : k) + ' (قطعة / قيمة)']);
    const lineCols = [['code','الكود'],['name','الصنف'],['qty_in','الكمية'],['qty_returned','المرتجع'],['unit_cost','تكلفة القطعة'],['sale_price','سعر البيع'],
        ['sold','إجمالي المباع'],['zahraa','الفجالة'],['bonjour','بونجور'],['degla','دجلة'],['abc','اي بي سي'],['ashraqa','إشراقة'],['remaining','الباقي'],
        ...common,['sell_through','نسبة البيع %'],['sold_days','اتباعت في']];
    const invoiceCols = [['doc_no','الفاتورة'],['lot_date','التاريخ'],['store_name','المخزن'],['qty_in','الكمية'],['sold','المباع'],['remaining','الباقي'],
        ...common,['sell_through','نسبة البيع %'],['ashraqa','إشراقة'],...agingCols];
    const supplierCols = [['name','المورد'],['qty_in','المشترى'],['qty_returned','المرتجع'],['sold','المباع'],['ashraqa','إشراقة'],
        ...common,['sell_through','نسبة البيع %'],['no_purchase_qty','من غير شراء'],...agingCols];
    const storeCols = [['code','الكود'],['name','الصنف / التصنيف'],['supplier_name','المورد'],['in_qty','الوارد'],['sold','الصادر'],['ashraqa','إشراقة'],
        ...common,['balance','الرصيد الحالي'],['no_purchase_qty','من غير شراء']];
    const textKeys = ['code','name','supplier_name','doc_no','lot_date','store_name'];
    function visible(node, show) { node.hidden = !show; node.style.display = show ? '' : 'none'; }
    function query(part) {
        const q = new URLSearchParams(new FormData(form));
        q.set('part', part);
        return q;
    }
    async function request(q, options) {
        try {
            const result = await apiFetch('fifo_report_api.php?' + q, options);
            return result.data;
        } catch (err) {
            if (err.status === 423) {
                ++sequence; data = null; loadedQuery = ''; actions.clear();
                el('fifoResults').replaceChildren(); el('fifoTotals').replaceChildren(); el('fifoNotes').textContent = '';
                visible(el('fifoPages'), false);
                message('الأرقام الحساسة مقفولة');
            }
            throw err;
        }
    }
    function message(text) { el('fifoMessage').textContent = text; }
    function status(value) {
        const current = value && value.current;
        const latest = value && value.latest;
        let text = current ? 'آخر حساب: ' + current.finished_at : 'لسه مفيش حساب مكتمل';
        if (latest && latest.status === 'running') text += ' · فيه حساب شغّال';
        if (latest && latest.status === 'failed') text += ' · آخر محاولة ماكملتش';
        if (current && current.note) {
            try {
                const w = JSON.parse(current.note);
                text += ' · مردود من غير دفعة: ' + num(w.purchase_return_without_lot)
                    + ' · بيع من غير شراء: ' + num(w.sale_without_purchase)
                    + ' · مردود بيع من غير بيعة: ' + num(w.sale_return_without_sale);
            } catch (_) { /* التشغيلة القديمة ممكن ميبقاش فيها ملخص. */ }
        }
        el('fifoStatus').textContent = text;
    }
    function cell(row, key) {
        if (key.startsWith('age:')) {
            const a = (row.aging || {})[key.slice(4)] || {};
            return num(a.qty || 0) + ' / ' + num(a.value || 0);
        }
        if (key === 'sold_days') return row[key] == null ? 'لسه' : num(row[key]) + ' أيام';
        if (textKeys.includes(key)) return '<span title="' + esc(row[key]) + '" style="display:inline-block;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:middle">' + esc(row[key]) + '</span>';
        if (key === 'no_purchase_qty' && Number(row[key])) return '<span class="fchip fchip-warn">' + num(row[key]) + '</span>';
        return num(row[key]);
    }
    function table(rows, cols, expand) {
        return '<div class="ftable-wrap"><table class="ftable"><thead><tr>'
            + cols.map(([key, label]) => '<th' + (textKeys.includes(key) ? '' : ' class="num"') + '>' + esc(label) + '</th>').join('')
            + '</tr></thead><tbody>' + rows.map(row => {
                let id;
                if (expand) { id = 'fifoDetail' + (++serial); actions.set(id, {row, expand}); }
                return '<tr' + (expand ? ' data-fifo-open="' + id + '" role="button" tabindex="0" aria-expanded="false" aria-controls="' + id + '" style="cursor:pointer"' : '') + '>'
                    + cols.map(([key]) => '<td' + (textKeys.includes(key) ? '' : ' class="num"')
                    + (key === 'profit' && row[key] < 0 ? ' style="color:var(--neg)"' : '') + '>'
                    + cell(row, key)
                    + '</td>').join('') + '</tr>'
                    + (expand ? '<tr id="' + id + '" class="ftable-detail" hidden data-xt-ignore data-xt-nosort><td colspan="' + cols.length + '"></td></tr>' : '');
            }).join('') + '</tbody></table></div>';
    }
    function showDetails(target, html) {
        // المكتبة بتعدّي الجدول المتداخل، فبنجهّز فلاتره قبل ما نحطه جوه صف التفاصيل.
        const content = document.createElement('div');
        content.innerHTML = html;
        if (window.xtableScan) window.xtableScan(content);
        target.replaceChildren(...content.childNodes);
    }
    function show() {
        actions.clear();
        if (!data) return;
        const t = data.totals;
        el('fifoTotals').innerHTML = [['sold','المباع'],['revenue','الإيراد'],['cost','تكلفة الربح'],['profit','الربح'],['margin','النسبة %']]
            .map(([k,label]) => '<div class="fkpi"><span>' + label + '</span><strong' + (k === 'profit' && t[k] < 0 ? ' style="color:var(--neg)"' : '') + '>' + num(t[k]) + '</strong></div>').join('');
        el('fifoNotes').innerHTML = 'منه <span class="fchip fchip-warn">' + num(t.no_purchase_qty) + ' قطعة من غير فاتورة شراء</span>'
            + ' بإيراد ' + num(t.no_purchase_revenue) + ' · ربحها مش محسوب. إجمالي الإيراد والربح والنسبة من البيع اللي له تكلفة بس.<br>اتبعت لإشراقة ' + num(t.ashraqa) + ' قطعة بتكلفة ' + num(t.ashraqa_cost)
            + ' · عمر الباقي لحد النهارده، من غير فلتر الفترة.';
        const byItem = report === 'store_profit' && new URLSearchParams(loadedQuery).get('group') !== 'category';
        const pageSize = byItem ? 500 : Math.max(1, data.rows.length);
        const rows = data.rows.slice(page * pageSize, (page + 1) * pageSize);
        const columns = report === 'supplier_invoices' ? invoiceCols : report === 'store_profit'
            ? storeCols.filter(([key]) => byItem || key !== 'code') : supplierCols;
        const supplierAge = report === 'supplier_invoices' ? '<p>عمر باقي المورد كله لحد النهارده</p>' + table([{aging:data.aging}],agingCols) : '';
        el('fifoResults').innerHTML = supplierAge + (rows.length ? table(rows, columns,
            report === 'supplier_invoices' ? 'invoice' : report === 'suppliers_profit' ? 'supplier' : null) : '<p>مفيش بيانات في الفترة دي.</p>');
        visible(el('fifoPages'), data.rows.length > pageSize);
        el('fifoPageLabel').innerHTML = 'صفحة ' + num(page + 1) + ' من ' + num(Math.max(1,Math.ceil(data.rows.length / pageSize)))
            + (byItem ? '<br><small>الفلتر على الصفحة الحالية بس</small>' : '');
        el('fifoPrev').disabled = page === 0;
        el('fifoNext').disabled = (page + 1) * pageSize >= data.rows.length;
    }
    async function load() {
        const ticket = ++sequence;
        if (report === 'supplier_invoices' && !el('fifoSupplier').value) { message('اختار المورد واضغط عرض'); return; }
        message('بنحمّل…');
        const q = query(report);
        try {
            const result = await request(q);
            if (ticket !== sequence) return;
            data = result; loadedQuery = q.toString(); page = 0;
            status(data.status); message(''); show();
        } catch (err) { if (ticket === sequence) message(err.message); }
    }
    root.addEventListener('click', async function (event) {
        const tab = event.target.closest('[data-fifo-report]');
        if (tab) {
            report = tab.dataset.fifoReport;
            root.querySelectorAll('[data-fifo-report]').forEach(b => b.classList.toggle('is-active', b === tab));
            visible(el('fifoSupplierField'), report === 'supplier_invoices');
            visible(el('fifoStoreField'), report === 'store_profit'); visible(el('fifoGroupField'), report === 'store_profit');
            data = null; el('fifoResults').replaceChildren(); el('fifoTotals').replaceChildren(); el('fifoNotes').textContent = ''; visible(el('fifoPages'), false);
            await load(); return;
        }
        const row = event.target.closest('[data-fifo-open]');
        if (!row) return;
        const target = el(row.dataset.fifoOpen), action = actions.get(row.dataset.fifoOpen);
        target.hidden = !target.hidden; row.setAttribute('aria-expanded', String(!target.hidden));
        row.classList.toggle('is-open', !target.hidden);
        if (target.hidden || target.dataset.loaded) return;
        if (action.expand === 'invoice') {
            showDetails(target.firstElementChild, table(action.row.lines, lineCols));
            target.dataset.loaded = '1'; return;
        }
        if (!action.row.supplier_id) { target.firstElementChild.textContent = 'المباع ده من غير فاتورة شراء'; return; }
        target.firstElementChild.textContent = 'بنحمّل فواتير المورد…';
        try {
            const q = new URLSearchParams(loadedQuery); q.set('part','supplier_invoices'); q.set('supplier_id',action.row.supplier_id);
            const invoices = await request(q);
            showDetails(target.firstElementChild, table(invoices.rows,invoiceCols,'invoice')); target.dataset.loaded = '1';
        } catch (err) { target.firstElementChild.textContent = err.message; }
    });
    root.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        const row = event.target.closest('[data-fifo-open]');
        if (!row) return;
        event.preventDefault();
        row.click();
    });
    form.addEventListener('submit', function (event) { event.preventDefault(); load(); });
    el('fifoPrev').addEventListener('click', () => { --page; show(); });
    el('fifoNext').addEventListener('click', () => { ++page; show(); });
    el('fifoExport').addEventListener('click', function () {
        if (report === 'supplier_invoices' && !el('fifoSupplier').value) { message('اختار مورد الأول'); return; }
        const q = query('export'); q.set('report',report); window.location.href = 'fifo_report_api.php?' + q;
    });
    el('fifoRebuild').addEventListener('click', async function () {
        this.disabled = true; message('الحساب شغّال، ممكن ياخد شوية وقت…');
        try {
            const result = await request('part=rebuild',{method:'POST',body:{}});
            if (result.busy) { message(result.message); return; }
            status(await request('part=status')); await load();
        } catch (err) { message(err.message); }
        finally { this.disabled = false; }
    });
    Promise.all([request('part=status'),request('part=suppliers')]).then(([s,list]) => {
        status(s);
        list.rows.forEach(row => el('fifoSupplier').add(new Option(row.name,row.id)));
        list.stores.forEach(row => el('fifoStore').add(new Option(row.name,row.key)));
        if (list.rows.length) el('fifoSupplier').value = list.rows[0].id;
        load();
    }).catch(err => message(err.message));
});
