        </div><!-- /.content -->
    </div><!-- /.main -->
</div><!-- /.layout -->

<script>
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('overlay').classList.toggle('show');
    }
    function toggleNotif(e) {
        e.stopPropagation();
        const p = document.getElementById('notifPanel');
        if (p) p.classList.toggle('open');
    }
    // اقفل قائمة الإشعارات لو دوست بره
    document.addEventListener('click', function(e) {
        const p = document.getElementById('notifPanel');
        if (p && p.classList.contains('open') && !e.target.closest('.notif-wrap')) {
            p.classList.remove('open');
        }
    });
</script>
<script src="assets/app.js?v=4"></script>
<!-- خصائص جداول الإكسيل (فلتر · ترتيب · بحث) لكل جداول النظام.
     بيشتغل لوحده — مفيش تعديل مطلوب في أي صفحة.
     لاستثناء جدول: <table data-xt-off> -->
<script src="assets/xtable.js?v=5"></script>
</body>
</html>
