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
<script src="assets/app.js?v=8"></script>
<!-- خصائص جداول الإكسيل (فلتر · ترتيب · بحث) لكل جداول النظام.
     بيشتغل لوحده — مفيش تعديل مطلوب في أي صفحة.
     لاستثناء جدول: <table data-xt-off> -->
<script src="assets/xtable.js?v=8"></script>
<script src="assets/anicons.js?v=1"></script>
</body>
</html>
