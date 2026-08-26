<?php
/**
 * pc_scan.php — صفحة الاقتران: بتظهر QR يمسحه الموبايل فيبقى سكانر للكمبيوتر
 * نظام الفجالة ستور المالي
 *
 * الخطوات:
 *  1) شغّل الوكيل fagala_scanner_agent.ps1 على الكمبيوتر (فيه نفس كود الاقتران)
 *  2) افتح الصفحة دي على نفس الكمبيوتر واكتب نفس الكود
 *  3) امسح الـ QR بالموبايل → صفحة المسح تفتح جاهزة
 */
require_once __DIR__ . '/auth.php';
require_login();

$page_title    = 'اقتران السكانر';
$active_module = 'price_inquiry';
require_once __DIR__ . '/includes/header.php';
?>
<style>
    .pc-wrap { max-width:520px; margin:0 auto; }
    .pc-sess { display:flex; gap:10px; align-items:center; margin-bottom:16px; }
    .pc-sess input { flex:1; padding:12px; border:1px solid var(--line); border-radius:10px;
                     font-size:20px; text-align:center; letter-spacing:3px; font-weight:700; }
    #qrBox { display:flex; justify-content:center; padding:18px; background:#fff;
             border-radius:14px; border:1px solid var(--line); min-height:200px; align-items:center; }
    .pc-steps { font-size:14px; color:var(--ink-800); line-height:2; margin-top:16px; }
</style>
<div class="pc-wrap">
    <div class="card">
        <div class="section-title">🖥️ اقتران الموبايل كسكانر للكمبيوتر</div>
        <div class="pc-sess">
            <label for="pcSession" style="font-size:13px;color:var(--ink-500);">كود الاقتران:</label>
            <input type="text" id="pcSession" placeholder="PC1" aria-label="كود الاقتران"
                   autocomplete="off" oninput="renderQR()">
        </div>
        <div id="qrBox"><span style="color:var(--ink-500);">اكتب كود الاقتران فوق وهيظهر الـ QR هنا</span></div>
        <div class="pc-steps">
            <b>الخطوات:</b><br>
            ١) شغّل <code>fagala_scanner_agent.ps1</code> على الكمبيوتر ده
               (اتأكد إن فيه نفس الكود اللي فوق).<br>
            ٢) امسح الـ QR بكاميرا الموبايل العادية → هتفتح صفحة المسح.<br>
            ٣) حط مؤشر الكتابة في الخانة المطلوبة في أي برنامج، وامسح براحتك —
               كل كود بيتكتب لوحده + Enter.
        </div>
    </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
    let qr = null;
    function renderQR() {
        const s = document.getElementById('pcSession').value.trim().toUpperCase();
        localStorage.setItem('pc_scan_code', s);
        const box = document.getElementById('qrBox');
        if (!s) { box.innerHTML = '<span style="color:var(--ink-500);">اكتب كود الاقتران فوق</span>'; qr = null; return; }
        const url = location.origin + location.pathname.replace('pc_scan.php', 'remote_scan.php') + '?s=' + encodeURIComponent(s);
        box.innerHTML = '';
        qr = new QRCode(box, { text: url, width: 190, height: 190 });
    }
    // آخر كود مستخدم
    const saved = localStorage.getItem('pc_scan_code');
    if (saved) { document.getElementById('pcSession').value = saved; renderQR(); }
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
