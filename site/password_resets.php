<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/password_reset_lib.php';
require_login();
try {
    pwreset_manager(db());
} catch (RuntimeException $e) {
    if ($e->getCode() !== 403) throw $e;
    http_response_code(403);
    exit('مالكش صلاحية');
}
header('Cache-Control: no-store');
$page_title = 'طلبات إعادة الباسورد';
$active_module = 'hr';
require_once __DIR__ . '/includes/header.php';
?>
<main class="content-container">
    <h1>طلبات إعادة الباسورد</h1>
    <p>طلبات آخر ٢٤ ساعة. اتأكد بنفسك من صاحب الحساب قبل ما توافق.</p>
    <div class="falert falert-pos" id="resetDecision" role="status" hidden></div>
    <section class="fcard">
        <div class="ftable-wrap">
            <table class="ftable">
                <thead><tr><th>الاسم</th><th>الفرع</th><th>الوقت</th><th>الـIP</th><th>القرار</th></tr></thead>
                <tbody id="resetRequests"></tbody>
            </table>
        </div>
        <div class="fcard-actions">
            <button type="button" class="btn btn-ghost" id="resetPrevious">اللي قبلها</button>
            <span id="resetPage"></span>
            <button type="button" class="btn btn-ghost" id="resetNext">اللي بعدها</button>
        </div>
    </section>
</main>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const csrf = <?= json_encode(csrf_token()) ?>;
    const body = document.getElementById('resetRequests');
    const message = document.getElementById('resetDecision');
    const previous = document.getElementById('resetPrevious');
    const next = document.getElementById('resetNext');
    let page = 1;
    let busy = false;
    async function call(action, id) {
        const data = new FormData();
        data.set('csrf', csrf); data.set('action', action); data.set('page', page);
        if (id) data.set('id', id);
        const response = await fetch('password_reset_api.php', {method: 'POST', body: data, credentials: 'same-origin'});
        const result = await response.json();
        if (!result.ok) throw new Error(result.error || 'حصل خطأ، جرّب تاني');
        return result.data;
    }
    async function load() {
        const data = await call('list');
        body.replaceChildren();
        data.requests.forEach(request => {
            const row = document.createElement('tr');
            [request.full_name, request.branch, request.created_at, request.ip].forEach(text => {
                const cell = document.createElement('td'); cell.textContent = text; row.append(cell);
            });
            const actions = document.createElement('td');
            if (request.can_decide) {
                [['approve', 'وافق', 'success'], ['reject', 'ارفض', 'danger']].forEach(([action, label, variant]) => {
                    const button = document.createElement('button');
                    button.type = 'button'; button.className = 'btn btn-sm btn-' + variant; button.textContent = label;
                    button.addEventListener('click', async () => {
                        if (busy) return;
                        busy = true;
                        message.replaceChildren(); message.hidden = true;
                        try {
                            const decision = await call(action, request.id);
                            message.className = 'falert falert-pos';
                            if (decision.reset_otp) {
                                const code = document.createElement('h1'); code.dir = 'ltr'; code.textContent = decision.reset_otp;
                                message.append(code);
                            }
                            const text = document.createElement('p'); text.textContent = decision.message; message.append(text);
                            const close = document.createElement('button'); close.type = 'button'; close.className = 'btn btn-ghost'; close.textContent = 'اقفل';
                            close.addEventListener('click', () => { message.replaceChildren(); message.hidden = true; }); message.append(close);
                            message.hidden = false;
                            await load();
                        } catch (error) { showError(error); }
                        finally { busy = false; }
                    });
                    actions.append(button);
                });
            } else { actions.textContent = 'الطلب ده محتاج المالك'; }
            row.append(actions); body.append(row);
        });
        if (!data.requests.length) {
            const row = body.insertRow(); const cell = row.insertCell(); cell.colSpan = 5; cell.textContent = 'مفيش طلبات مستنية';
        }
        previous.disabled = page <= 1; next.disabled = !data.more;
        document.getElementById('resetPage').textContent = 'صفحة ' + page;
    }
    function showError(error) { message.textContent = error.message || 'الاتصال وقف، جرّب تاني'; message.className = 'falert falert-neg'; message.hidden = false; }
    previous.addEventListener('click', () => { if (!busy && page > 1) { page--; load().catch(showError); } });
    next.addEventListener('click', () => { if (!busy) { page++; load().catch(showError); } });
    window.addEventListener('pagehide', () => { message.replaceChildren(); message.hidden = true; });
    load().catch(showError);
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
