        </div><!-- /.content -->
    </div><!-- /.main -->
</div><!-- /.layout -->

<script>
    /* 🔴 `toggleSidebar` و`toggleNotif` اتنقلوا لـ`includes/header.php`.
       كانوا هنا، والفوتر بيوصل آخر الصفحة — فاللي يدوس على القايمة
       قبل ما الصفحة تكمّل كان الزرار مابيشتغلش معاه. متنقلهمش تاني. */
    // اقفل قائمة الإشعارات لو دوست بره
    document.addEventListener('click', function(e) {
        const p = document.getElementById('notifPanel');
        if (p && p.classList.contains('open') && !e.target.closest('.notif-wrap')) {
            p.classList.remove('open');
        }
    });
</script>
<?php if (!empty($GLOBALS['SENS_PAGE'])): ?>
<style>
.sens-blur {
    filter: blur(6px);
    user-select: none;
    cursor: pointer;
    display: inline-block;
}
.sens-eye {
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: transparent;
    border: 1px solid var(--line);
    border-radius: var(--r-sm);
    padding: 3px 10px;
    color: var(--text);
    cursor: pointer;
    font-family: inherit;
    line-height: 1.25;
    transition: border-color 0.15s, background 0.15s;
}
.sens-eye:hover {
    border-color: var(--accent);
    background: var(--surface);
}
.sens-eye-main {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: var(--fs-xs);
    font-weight: 600;
}
.sens-eye-sub {
    font-size: 10px;
    color: var(--ink-500);
    margin-top: 1px;
}
.sens-lock-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--r);
    padding: 36px 20px;
    text-align: center;
    margin: 28px auto;
    max-width: 440px;
    box-shadow: var(--shadow-sm);
}
.sens-lock-icon {
    font-size: 40px;
    color: var(--ink-500);
    margin-bottom: 10px;
}
.sens-lock-title {
    font-size: var(--fs-lg);
    font-weight: 700;
    color: var(--text);
    margin: 0 0 6px;
}
.sens-lock-msg {
    font-size: var(--fs-sm);
    color: var(--ink-500);
    margin: 0 0 18px;
}
.sens-lock-act {
    display: flex;
    justify-content: center;
}
.sens-modal-bg {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.45);
    z-index: var(--z-modal);
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.sens-modal-bg.open {
    display: flex;
}
.sens-modal {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--r);
    width: 100%;
    max-width: 360px;
    padding: 20px;
    box-shadow: var(--shadow-lg);
}
.sens-modal-title {
    font-size: var(--fs-md);
    font-weight: 700;
    color: var(--text);
    margin: 0 0 14px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.sens-input-wrap {
    margin-bottom: 12px;
}
.sens-input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--line);
    border-radius: var(--r-sm);
    background: var(--surface);
    color: var(--text);
    font-size: var(--fs-sm);
    font-family: inherit;
    box-sizing: border-box;
}
.sens-input:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 2px var(--accent-soft);
}
.sens-error {
    color: var(--neg);
    font-size: var(--fs-xs);
    margin-bottom: 12px;
}
.sens-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}
</style>
<div id="sensModal" class="sens-modal-bg">
    <div class="sens-modal">
        <h3 class="sens-modal-title"><i class="ti ti-key"></i> دخّل الباسورد بتاعك</h3>
        <form id="sensForm" onsubmit="sensSubmit(event)">
            <div class="sens-input-wrap">
                <input type="password" id="sensPassword" class="sens-input" autocomplete="current-password" placeholder="كلمة المرور">
            </div>
            <div id="sensError" class="sens-error" style="display:none;"></div>
            <div class="sens-actions">
                <button type="submit" class="btn btn-primary" id="sensSubmitBtn">افتح</button>
                <button type="button" class="btn btn-cancel" onclick="sensClose()">إلغاء</button>
            </div>
        </form>
    </div>
</div>
<script>window.SENS = {unlocked: <?= sens_unlocked() ? 'true' : 'false' ?>, left: <?= sens_left() ?>, csrf: <?= json_encode(csrf_token()) ?>};</script>
<script src="assets/sensitive.js?v=1"></script>
<?php endif; ?>
<script src="assets/app.js?v=8"></script>
<!-- خصائص جداول الإكسيل (فلتر · ترتيب · بحث) لكل جداول النظام.
     بيشتغل لوحده — مفيش تعديل مطلوب في أي صفحة.
     لاستثناء جدول: <table data-xt-off> -->
<script src="assets/xtable.js?v=8"></script>
<script src="assets/anicons.js?v=1"></script>
</body>
</html>
