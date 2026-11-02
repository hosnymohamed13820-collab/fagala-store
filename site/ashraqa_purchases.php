<?php
/** مشتريات ومرتجعات Redux — العرض من الـAPI الموحّد، قراية بس. */
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_permission('ashraqa_purchases');
$page_title = 'مشتريات ومرتجعات إشراقة';
$active_module = 'ashraqa_purchases';
require_once __DIR__ . '/includes/header.php';
?>
<div class="fcard">
    <form id="purchaseFilters" class="form-row">
        <label>من <input class="finput" type="date" name="from" value="2026-07-01"></label>
        <label>لحد <input class="finput" type="date" name="to" value="<?= e(date('Y-m-d')) ?>"></label>
        <label>النوع <select class="fselect" name="sstt"><option value="0">الكل</option><option value="113">شراء</option><option value="115">مرتجع شراء</option></select></label>
        <label>المورد <select class="fselect" name="supplier" id="purchaseSupplier"><option value="0">كل الموردين</option></select></label>
        <label>بحث <input class="finput" name="q" placeholder="رقم الفاتورة أو الملاحظات"></label>
        <label><input type="checkbox" name="show_cancelled" value="1"> اعرض الملغي</label>
        <button class="btn btn-primary" type="submit">عرض</button>
        <a class="btn btn-ghost" href="ashraqa_purchases.php">القائمة</a>
    </form>
</div>
<div id="purchaseSummary" class="fcard" aria-live="polite"></div>
<div class="fcard"><table class="ftable" id="purchaseTable"><thead></thead><tbody></tbody></table></div>
<div class="form-row"><button type="button" class="btn btn-ghost" id="purchasePrevious">السابق</button><span id="purchasePage"></span><button type="button" class="btn btn-ghost" id="purchaseNext">التالي</button></div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('purchaseFilters');
    const initial = new URLSearchParams(location.search);
    for (const [key, value] of initial) {
        const input = form.elements.namedItem(key);
        if (input) {
            if (input.type === 'checkbox') input.checked = value === '1';
            else {
                if (key === 'supplier' && value !== '0') input.add(new Option(value, value));
                input.value = value;
            }
        }
    }
    let page = Math.max(1, Number(initial.get('page')) || 1);
    const money = value => Number(value || 0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
    const table = document.getElementById('purchaseTable');
    const summary = document.getElementById('purchaseSummary');
    const cell = (row, value, numeric = false) => {
        const td = document.createElement('td'); td.textContent = value ?? ''; if (numeric) td.className = 'num'; row.append(td); return td;
    };
    async function load() {
        const params = new URLSearchParams(new FormData(form)); params.set('page', String(page));
        if (initial.has('doc')) params.set('doc', initial.get('doc'));
        try {
            const result = await apiFetch('ashraqa_purchases_api.php?' + params);
            const data = result.data;
            table.tHead.replaceChildren(); table.tBodies[0].replaceChildren();
            const headers = data.detail ? ['#','الصنف','الباركود','الكمية','معامل الوحدة','السعر','الإجمالي'] : ['الرقم','النوع','التاريخ','المورد','الملاحظات','عدد الأصناف','إجمالي القطع','الصافي'];
            const head = table.tHead.insertRow();
            headers.forEach((title, i) => { const th = document.createElement('th'); th.textContent = title; if (i >= (data.detail ? 3 : 5)) th.className = 'num'; head.append(th); });
            if (data.detail) {
                summary.textContent = data.doc ? `فاتورة ${data.doc.add_doc} · ${data.doc.doc_date} · ${data.doc.supplier_name} · ${money(data.doc.net_amount)}${Number(data.doc.cancelled) ? ' · ملغية' : ''} · ${data.doc.notes || ''}` : 'الفاتورة مش موجودة';
                data.lines.forEach(line => {
                    const row = table.tBodies[0].insertRow();
                    [line.line_no,line.item_name,(line.barcodes || line.code || '').split('|')[0],line.quantity,line.unit_q,line.unit_price,line.line_total].forEach((value, i) => cell(row, i >= 3 ? money(value) : value, i >= 3));
                });
            } else {
                const totals = data.totals;
                summary.textContent = `عدد الفواتير: ${totals.count} · إجمالي الشراء: ${money(totals.buy)} · المرتجع: ${money(totals.returned)}`;
                data.rows.forEach(doc => {
                    const row = table.tBodies[0].insertRow();
                    const key = [doc.sstt_id,doc.brch_id,doc.fy_no,doc.add_doc].join('-');
                    const link = document.createElement('a'); link.href = 'ashraqa_purchases.php?doc=' + encodeURIComponent(key) + '&from=' + data.from + '&to=' + data.to; link.textContent = doc.add_doc;
                    row.setAttribute('role', 'link'); row.tabIndex = 0; row.style.cursor = 'pointer';
                    row.addEventListener('click', event => { if (!event.target.closest('a')) location.href = link.href; });
                    row.addEventListener('keydown', event => {
                        if (event.target === row && (event.key === 'Enter' || event.key === ' ')) {
                            event.preventDefault(); location.href = link.href;
                        }
                    });
                    cell(row, '').append(link);
                    [Number(doc.sstt_id) === 113 ? 'شراء' : 'مرتجع شراء',doc.doc_date,doc.supplier_name,doc.notes,doc.items_count,money(doc.total_pieces),money(doc.net_amount)].forEach((value, i) => cell(row,value,i >= 4));
                    if (Number(doc.cancelled)) { const chip = document.createElement('span'); chip.className = 'fchip'; chip.textContent = 'ملغية'; row.cells[1].append(chip); }
                });
                const supplier = document.getElementById('purchaseSupplier'); const selected = supplier.value || initial.get('supplier') || '0';
                supplier.replaceChildren(new Option('كل الموردين','0'));
                data.suppliers.forEach(item => supplier.add(new Option(item.supplier_name,item.supplier_id)));
                supplier.value = selected;
            }
            document.getElementById('purchasePage').textContent = data.detail ? '' : `صفحة ${page}`;
            document.getElementById('purchasePrevious').disabled = data.detail || page <= 1;
            document.getElementById('purchaseNext').disabled = data.detail || page * 100 >= Number(data.totals.count);
        } catch (error) { summary.textContent = error.message || 'تعذّر تحميل المشتريات'; }
    }
    form.addEventListener('submit', event => { event.preventDefault(); initial.delete('doc'); page = 1; load(); });
    document.getElementById('purchasePrevious').onclick = () => { page--; load(); };
    document.getElementById('purchaseNext').onclick = () => { page++; load(); };
    load();
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
