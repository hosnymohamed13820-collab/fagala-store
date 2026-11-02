<?php
require_once __DIR__ . '/auth.php';
require_login();
if (!is_real_owner()) { http_response_code(403); exit('الطلبات دي للمالك بس.'); }
$page_title = 'طلبات تعديل الشكل';
$active_module = '';
require_once __DIR__ . '/includes/header.php';
?>
<main class="content-container">
    <?= ui_page_head('طلبات تعديل الشكل', 'الطلبات دي معاينة عندك بس لحد ما تتنفّذ في الكود.', ui_btn('المكتبة', ['variant' => 'ghost', 'href' => 'ui_kit.php']) . ui_btn('جرّب النموذج', ['href' => 'mockups/example.php?ui_edit=1'])) ?>
    <div id="editsMessage" role="status"></div>
    <?= ui_card_open('الطلبات المحفوظة') ?>
    <div class="ftable-wrap">
        <table class="ftable" id="editsTable">
            <thead><tr><th>الصفحة</th><th>العنوان</th><th class="num">عدد التعديلات</th><th>الحالة</th><th>التاريخ</th><th>الإجراءات</th></tr></thead>
            <tbody></tbody>
        </table>
    </div>
    <div class="fcard-actions">
        <?= ui_btn('اللي قبلها', ['variant' => 'ghost', 'id' => 'editsPrev', 'attrs' => ['disabled' => true]]) ?>
        <span id="editsPage" class="fchip fchip-mute">1</span>
        <?= ui_btn('اللي بعدها', ['variant' => 'ghost', 'id' => 'editsNext', 'attrs' => ['disabled' => true]]) ?>
    </div>
    <?= ui_card_close() ?>
</main>
<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    const table = document.getElementById('editsTable'), body = table.tBodies[0];
    const message = document.getElementById('editsMessage');
    const states = {open:'مفتوح', briefed:'اتكتب له بريف', done:'اتنفّذ'};
    let page = 1, loading = false;
    const esc = window.ui.esc;
    function notice(text, kind) { message.innerHTML = window.ui.alert(kind || 'neg', text); }
    async function load() {
        if (loading) return;
        loading = true;
        document.getElementById('editsPrev').disabled = true;
        document.getElementById('editsNext').disabled = true;
        try {
            const result = await apiFetch('ui_edits_api.php?action=list&page=' + page);
            body.innerHTML = result.data.rows.map(row => {
                const link = new URL(row.page, location.href);
                if (link.origin !== location.origin) throw new Error('رابط الصفحة مش محلي.');
                link.searchParams.set('ui_edit', '1'); link.searchParams.set('req', row.id);
                return '<tr><td>' + esc(row.page) + '</td><td class="name">' + esc(row.title) + '</td><td class="num">' + esc(row.edit_count) + '</td><td>' +
                    window.ui.chip(states[row.status], row.status === 'done' ? 'pos' : row.status === 'briefed' ? 'warn' : 'mute') +
                    '</td><td>' + esc(row.created_at) + '</td><td><div class="fcard-actions">' +
                    window.ui.btn('افتح في وضع التعديل', {href:link.href, variant:'ghost'}) +
                    window.ui.btn('تصدير JSON', {variant:'ghost', attrs:{'data-export':row.id}}) +
                    '<select class="fselect" aria-label="غيّر حالة الطلب" data-status="' + esc(row.id) + '">' + Object.keys(states).map(key => '<option value="' + key + '"' + (key === row.status ? ' selected' : '') + '>' + states[key] + '</option>').join('') + '</select></div></td></tr>';
            }).join('');
            document.getElementById('editsPage').textContent = page;
            document.getElementById('editsPrev').disabled = page === 1;
            document.getElementById('editsNext').disabled = !result.data.has_more;
            if (!result.data.rows.length) notice('مفيش طلبات لسه. افتح النموذج وجرّب وضع التعديل.', 'info');
        } catch (error) { notice(error.message || 'تحميل الطلبات ماكملش.'); }
        finally { loading = false; }
    }
    body.addEventListener('click', async function (event) {
        const button = event.target.closest('[data-export]');
        if (!button || button.disabled) return;
        button.disabled = true;
        try {
            const result = await apiFetch('ui_edits_api.php?action=get&id=' + encodeURIComponent(button.dataset.export));
            if (!result.data.request) throw new Error('الطلب ده مش موجود.');
            const url = URL.createObjectURL(new Blob([JSON.stringify(result.data.request, null, 2)], {type:'application/json;charset=utf-8'}));
            const a = document.createElement('a'); a.href = url; a.download = 'ui_edit_' + button.dataset.export + '.json';
            a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
        } catch (error) { notice(error.message || 'التصدير ماكملش.'); }
        finally { button.disabled = false; }
    });
    body.addEventListener('change', async function (event) {
        const select = event.target.closest('[data-status]');
        if (!select || select.disabled) return;
        select.disabled = true;
        try {
            const result = await apiFetch('ui_edits_api.php?action=set_status', {method:'POST', body:{id:Number(select.dataset.status), status:select.value}});
            if (!result.data.found) throw new Error('الطلب ده مش موجود.');
            notice('الحالة اتغيّرت.', 'pos');
        } catch (error) { notice(error.message || 'تغيير الحالة ماكملش.'); }
        finally { select.disabled = false; load(); }
    });
    document.getElementById('editsPrev').addEventListener('click', function () { if (!loading && page > 1) { page--; load(); } });
    document.getElementById('editsNext').addEventListener('click', function () { if (!loading) { page++; load(); } });
    load();
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
