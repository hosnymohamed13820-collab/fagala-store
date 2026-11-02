<?php
require_once dirname(__DIR__) . '/auth.php';
require_login();
if (!is_real_owner()) { http_response_code(403); exit('النموذج ده للمالك بس.'); }
header('X-Robots-Tag: noindex');
$page_title = 'نموذج لتجربة وضع التعديل';
$active_module = '';
// روابط الهيدر والفوتر نسبية؛ النموذج جوه مجلد فبنوصلها لجذر الموقع.
$mockupBase = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/') . '/';
ob_start();
require_once dirname(__DIR__) . '/includes/header.php';
$mockupHeader = ob_get_clean();
echo preg_replace('/<head>/', '<head><base href="' . ui_e($mockupBase) . '">', $mockupHeader, 1);
$items = [
    ['1001', 'كشكول سلك', 12], ['1002', 'قلم جاف', 8], ['1003', 'ورق تصوير', 25],
    ['1004', 'ملف بلاستيك', 7], ['1005', 'براية معدن', 3],
];
?>
<main class="content-container">
    <?= ui_page_head('نموذج لتجربة التعديل', 'الداتا هنا ثابتة للتجربة بس.', ui_btn('حفظ', ['id' => 'mockSave']) . ui_btn('رجوع', ['variant' => 'ghost', 'href' => 'ui_edits.php'])) ?>
    <div class="ffilters" id="mockFilters">
        <?= ui_field('دوّر على صنف', ui_input('search', ['id' => 'mockSearch', 'placeholder' => 'اكتب اسم الصنف'])) ?>
        <?= ui_field('الحالة', ui_select('status', ['all' => 'الكل', 'available' => 'متاح'], 'all')) ?>
    </div>
    <div class="fkpis" id="mockKpis" data-xt-kpis="#mockTable">
        <div class="fkpi"><span>عدد الأصناف</span><strong data-xt-count>5</strong></div>
        <div class="fkpi"><span>إجمالي الكمية</span><strong data-xt-sum="qty">55</strong></div>
        <div class="fkpi"><span>متاح</span><strong data-xt-sum="available">5</strong></div>
    </div>
    <?= ui_card_open('أصناف للتجربة') ?>
    <div class="ftable-wrap">
        <table class="ftable" id="mockTable">
            <thead><tr><th>الكود</th><th>الصنف</th><th>الحالة</th><th class="num">الكمية</th></tr></thead>
            <tbody>
            <?php foreach ($items as $index => [$code, $name, $qty]): ?>
                <tr data-v-qty="<?= $qty ?>" data-v-available="1">
                    <td><?= ui_e($code) ?></td><td class="name"><?= ui_e($name) ?></td>
                    <td><span class="fchip fchip-pos" data-ui-key="mock-status-<?= $index ?>">متاح</span></td><td class="num"><?= $qty ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= ui_card_close() ?>
</main>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
