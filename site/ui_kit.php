<?php
require_once __DIR__ . '/auth.php';
require_login();
if (!is_real_owner()) {
    http_response_code(403);
    exit('الصفحة دي للمالك بس.');
}
$page_title = 'مكتبة الواجهة';
$active_module = '';
require_once __DIR__ . '/includes/header.php';

function ui_code(string $code): string
{
    return '<pre class="fcode" dir="ltr"><code dir="ltr">' . htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>';
}
?>
<main class="content-container">
    <div class="fcard-actions">
        <?= ui_btn('طلبات تعديل الشكل', ['variant' => 'ghost', 'href' => 'ui_edits.php']) ?>
        <?= ui_btn('نموذج للتجربة', ['variant' => 'ghost', 'href' => 'mockups/example.php?ui_edit=1']) ?>
    </div>
    <?= ui_card_open('الإضاءة (.fhl)') ?>
    <div class="fcard fhl">الإضاءة بتتضاف لأي مكوّن بكلاس fhl، وبتشتغل في الفاتح والليلي.</div>
    <?= ui_code('<div class="fcard fhl">مكوّن عليه إضاءة</div>') ?>
    <?= ui_card_close() ?>
    <?= ui_page_head('مكتبة الواجهة الموحّدة', 'المرجع الحي للخانات والزراير والكروت والجداول. غيّر الوضع من السويتش العادي عشان تراجع الفاتح والليلي.') ?>

    <?= ui_card_open('الخانات') ?>
    <div class="fgrid fgrid-3">
        <?= ui_field('خانة عادية', ui_input('demo_name', ['id' => 'demoName', 'placeholder' => 'اكتب الاسم']), ['for' => 'demoName']) ?>
        <?= ui_field('رقم صغير', ui_input('demo_num', ['id' => 'demoNum', 'sm' => true, 'num' => true, 'value' => '12345']), ['for' => 'demoNum']) ?>
        <?= ui_field('اختيار', ui_select('demo_select', ['' => 'اختار', '1' => 'اختيار أول', '2' => 'اختيار تاني'], '1', ['id' => 'demoSelect']), ['for' => 'demoSelect']) ?>
        <?= ui_field('خانة مقفولة', ui_input('demo_disabled', ['value' => 'مش متاحة', 'attrs' => ['disabled' => true]])) ?>
        <?= ui_field('للقراية بس', ui_input('demo_readonly', ['value' => 'قيمة ثابتة', 'attrs' => ['readonly' => true]])) ?>
        <?= ui_field('خانة فيها مشكلة', ui_input('demo_bad', ['invalid' => true, 'value' => 'قيمة غلط']), ['error' => 'راجع القيمة دي']) ?>
        <?= ui_field('ملاحظات', '<textarea class="ftextarea" name="demo_notes" rows="3">نص تجريبي</textarea>') ?>
    </div>
    <?= ui_code("ui_field('الاسم', ui_input('name', ['id' => 'name']), ['for' => 'name']);\nui_select('kind', ['a' => 'أول'], 'a');") ?>
    <?= ui_card_close() ?>

    <?= ui_card_open('الزراير') ?>
    <div class="fcard-actions">
        <?= ui_btn('أساسي', ['icon' => 'check']) ?>
        <?= ui_btn('صغير', ['variant' => 'primary', 'class' => 'btn-sm', 'icon' => 'plus']) ?>
        <?= ui_btn('شفاف', ['variant' => 'ghost']) ?>
        <?= ui_btn('ثانوي', ['variant' => 'secondary']) ?>
        <?= ui_btn('خطر', ['variant' => 'danger', 'icon' => 'trash']) ?>
        <?= ui_btn('نجاح', ['variant' => 'success', 'icon' => 'circle-check']) ?>
        <?= ui_btn('برواز', ['variant' => 'outline']) ?>
        <?= ui_btn('مقفول', ['attrs' => ['disabled' => true]]) ?>
    </div>
    <?= ui_code("ui_btn('حفظ', ['variant' => 'success', 'icon' => 'device-floppy']);") ?>
    <?= ui_card_close() ?>

    <?= ui_card_open('التابات والتنبيهات') ?>
    <nav class="ftabs" aria-label="مثال للتابات">
        <button type="button" class="ftab is-active">النشط</button>
        <button type="button" class="ftab">تاب تاني</button>
        <a href="#alerts" class="ftab">رابط</a>
    </nav>
    <div id="alerts">
        <?= ui_alert('info', 'دي معلومة محتاجة تاخد بالك منها.') ?>
        <?= ui_alert('warn', 'راجع البيانات قبل ما تكمل.') ?>
        <?= ui_alert('neg', 'العملية ماكملتش.') ?>
        <?= ui_alert('pos', 'العملية تمت بنجاح.') ?>
    </div>
    <?= ui_code("ui_alert('pos', 'العملية تمت بنجاح.');") ?>
    <?= ui_card_close() ?>

    <?= ui_card_open('الفلاتر والإجماليات') ?>
    <form class="ffilters">
        <?= ui_field('من', ui_input('from', ['type' => 'date'])) ?>
        <?= ui_field('إلى', ui_input('to', ['type' => 'date'])) ?>
        <?= ui_field('الحالة', ui_select('status', ['' => 'الكل', 'ok' => 'مكتمل'])) ?>
        <?= ui_btn('تصفية', ['type' => 'submit', 'icon' => 'filter']) ?>
    </form>
    <div class="fkpis">
        <div class="fkpi"><span>عدد الحركات</span><strong>١٢٥</strong></div>
        <div class="fkpi"><span>الإجمالي</span><strong>٤٥٬٦٧٨٫٥٠</strong></div>
        <div class="fkpi"><span>المعلّق</span><strong>٧</strong></div>
    </div>
    <?= ui_code('<form class="ffilters">…</form>\n<div class="fkpis"><div class="fkpi"><span>الإجمالي</span><strong>١٢٣</strong></div></div>') ?>
    <?= ui_card_close() ?>

    <?= ui_card_open('الجدول والشارات والحالة الفاضية') ?>
    <div class="fkpis" data-xt-kpis="#demoKpiTable">
        <div class="fkpi"><span>إجمالي المبلغ</span><strong data-xt-sum="amount">50.00</strong></div>
        <div class="fkpi"><span>عدد الصفوف</span><strong data-xt-count>6</strong></div>
        <div class="fkpi"><span>نسبة الربح</span><strong><span data-xt-ratio="profit,amount">20.00</span>%</strong></div>
    </div>
    <div class="ftable-wrap">
        <table class="ftable" id="demoKpiTable">
            <thead><tr><th>الصنف</th><th class="num">المبلغ</th><th class="num">الربح</th></tr></thead>
            <tbody>
                <?php foreach ([[10,2],[20.5,4],[-5,-1],[15,3],[0,0],[9.5,2]] as $demoIndex => [$demoAmount,$demoProfit]): ?>
                    <tr data-v-amount="<?= $demoAmount ?>" data-v-profit="<?= $demoProfit ?>">
                        <td>صنف <?= $demoIndex + 1 ?></td><td class="num"><?= $demoAmount ?></td><td class="num"><?= $demoProfit ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= ui_code('<div class="fkpis" data-xt-kpis="#demoKpiTable">' . "\n" . '<strong data-xt-sum="amount">50.00</strong>' . "\n" . '<strong data-xt-count>6</strong>' . "\n" . '<strong data-xt-ratio="profit,amount">20.00</strong></div>' . "\n" . '<tr data-v-amount="10" data-v-profit="2">…</tr>') ?>

    <div class="ftable-wrap">
        <table class="ftable">
            <thead><tr><th>الصنف</th><th>الحالة</th><th class="num">الكمية</th></tr></thead>
            <tbody>
                <tr class="frow-green"><td class="name">كشكول سلك (أخضر)</td><td><span class="fchip fchip-pos">متاح</span></td><td class="num">١٢</td></tr>
                <tr class="frow-yellow"><td class="name">قلم جاف (أصفر)</td><td><span class="fchip fchip-warn">قليل</span></td><td class="num">٣</td></tr>
                <tr class="frow-red"><td class="name">ورق تصوير (أحمر)</td><td><span class="fchip fchip-neg">ناقص</span></td><td class="num">−٢</td></tr>
                <tr class="frow-blue"><td class="name">ملف بلاستيك (أزرق)</td><td><span class="fchip fchip-accent">وارد</span></td><td class="num">٥٠</td></tr>
                <tr class="frow-selected"><td class="name">براية معدن (متحدد)</td><td><span class="fchip fchip-mute">عادي</span></td><td class="num">١٠</td></tr>
            </tbody>
        </table>
    </div>
    <div class="fg-empty"><?= ui_icon('inbox') ?><span>مفيش بيانات للعرض</span></div>
    <?= ui_code('<table class="ftable">…</table>') ?>

    <h4>صف بيفتح تفاصيل</h4>
    <div class="ftable-wrap">
        <table class="ftable">
            <thead><tr><th>الفاتورة</th><th>الحالة</th><th class="num">الكمية</th></tr></thead>
            <tbody>
                <tr data-ftable-demo role="button" tabindex="0" aria-expanded="false" aria-controls="demoClosedDetail" style="cursor:pointer">
                    <td>فاتورة ١٠١</td><td>مقفولة — دوس على الصف عشان تفتحها</td><td class="num">٥</td>
                </tr>
                <tr id="demoClosedDetail" class="ftable-detail" data-xt-ignore data-xt-nosort hidden>
                    <td colspan="3">تفاصيل الفاتورة ١٠١</td>
                </tr>
                <tr class="is-open" data-ftable-demo role="button" tabindex="0" aria-expanded="true" aria-controls="demoOpenDetail" style="cursor:pointer">
                    <td>فاتورة ١٠٢</td><td>مفتوحة — الإطار حواليها هي وتفاصيلها</td><td class="num">١٢</td>
                </tr>
                <tr id="demoOpenDetail" class="ftable-detail" data-xt-ignore data-xt-nosort>
                    <td colspan="3">
                        <div class="ftable-wrap">
                            <table class="ftable">
                                <thead><tr><th>الكود</th><th>الصنف</th><th class="num">الكمية</th></tr></thead>
                                <tbody><tr><td>00123</td><td>كشكول سلك</td><td class="num">١٢</td></tr></tbody>
                            </table>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <?= ui_code('<tr class="is-open" role="button" tabindex="0" aria-expanded="true">…</tr>' . "\n" . '<tr class="ftable-detail" data-xt-ignore data-xt-nosort><td colspan="3">…</td></tr>') ?>

    <h4 style="margin:var(--sp-4) 0 var(--sp-2) 0; font-size:var(--fs-base); color:var(--ink-700);">خانات داخل الخلايا (<code>.finput-cell</code> + <code>td.fcell</code>)</h4>
    <div class="ftable-wrap">
        <table class="ftable">
            <thead><tr><th>الصنف</th><th class="num" style="width:110px;">الكمية</th><th class="num" style="width:110px;">خصم %</th></tr></thead>
            <tbody>
                <tr>
                    <td class="name">قلم جاف أزرق روترنج</td>
                    <td class="fcell"><input type="number" class="finput finput-cell num" value="5"></td>
                    <td class="fcell"><input type="number" class="finput finput-cell num" value="10"></td>
                </tr>
                <tr>
                    <td class="name">كشكول سلك ٨٠ ورقة مسطر</td>
                    <td class="fcell"><input type="number" class="finput finput-cell num" value="2"></td>
                    <td class="fcell"><input type="number" class="finput finput-cell num" value="0"></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?= ui_code('<td class="fcell"><input type="number" class="finput finput-cell num" value="…"></td>') ?>
    <?= ui_card_close() ?>

    <?= ui_card_open('كارت التوثيق والدخول (.fauth)') ?>
    <div class="fcard fauth">
        <div class="fauth-steps" aria-label="خطوات توثيق تجريبية">
            <span class="fauth-step is-done">١</span>
            <span class="fauth-step-sep">·</span>
            <span class="fauth-step is-active">٢</span>
            <span class="fauth-step-sep">·</span>
            <span class="fauth-step">٣</span>
        </div>
        <div class="fcard-head"><h3 class="fcard-title">عنوان الكارت</h3></div>
        <p class="fauth-desc">شرح مختصر أو إرشادات الخطوة الحالية.</p>
        <form onsubmit="return false;">
            <div class="ffield">
                <label for="demoAuthUser">اسم المستخدم</label>
                <input class="finput" id="demoAuthUser" value="demo_user">
            </div>
            <button class="btn btn-primary" type="button">زرار أساسي بعرض الكارت</button>
            <div class="fauth-actions">
                <a class="btn btn-ghost" href="#auth">رابط أو زر ثانوي</a>
            </div>
        </form>
    </div>
    <?= ui_code('<div class="fcard fauth">' . "\n" . '  <div class="fauth-steps"><span class="fauth-step is-active">١</span>…</div>' . "\n" . '  <div class="fcard-head"><h2 class="fcard-title">…</h2></div>' . "\n" . '  <form><div class="ffield"><input class="finput"></div><button class="btn btn-primary">…</button></form>' . "\n" . '</div>') ?>
    <?= ui_card_close() ?>

    <?= ui_card_open('الرسم من الجافاسكربت') ?>
    <div class="ffield"><label for="comboDemo">خانة بقايمة — اكتب أو افتح بـCtrl وسهم لتحت</label><input id="comboDemo" class="finput" value="ورق وتصوير"><span id="comboPicked" class="fpage-sub"></span></div>
    <div id="jsUiDemo"></div>
    <?= ui_code("ui.btn('إضافة', {icon:'plus'})\nui.chip('متاح', 'pos')\nui.alert('info', 'رسالة')\nui.empty('مفيش بيانات')\nui.esc('نص حر أو قيمة attribute')") ?>
    <?= ui_card_close() ?>
</main>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-ftable-demo]').forEach(function (row) {
        function toggle() {
            var detail = row.nextElementSibling;
            detail.hidden = !detail.hidden;
            row.classList.toggle('is-open', !detail.hidden);
            row.setAttribute('aria-expanded', String(!detail.hidden));
        }
        row.addEventListener('click', toggle);
        row.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                toggle();
            }
        });
    });
    window.ui.combo(document.getElementById('comboDemo'), ['أدوات مدرسية','أدوات مكتبية','ورق وتصوير'], {onPick: function(o){document.getElementById('comboPicked').textContent=o.name;}});
    document.getElementById('jsUiDemo').innerHTML =
        window.ui.btn('إضافة', {icon:'plus'}) + ' ' +
        window.ui.chip('متاح', 'pos') +
        window.ui.alert('info', 'دي رسالة طالعة من الجافاسكربت') +
        window.ui.empty('مفيش بيانات جاية من الـAPI');
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
