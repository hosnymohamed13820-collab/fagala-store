<?php
/**
 * branch_invoices.php — موديول «فواتير الفروع»
 *
 * صفحة توزيع بس: كل موظف بيتحوّل لفواتير فرعه لوحده، من غير ما يعدّي
 * على الاستلامات ومن غير صلاحية لكل فرع بإيد (قرار محمد ٢٢ سبتمبر ٢٠٢٦):
 *   · فرع بيع      ← صفحة فرعه على طول (إشراقة ← ashraqa_invoices.php)
 *   · الإدارة      ← قايمة بكل الفروع
 *   · من غير فرع   ← ولا حاجة
 * والأونر ← القايمة كمان. («الاستلامات» مالهاش دعوة.)
 * الحماية الحقيقية في صفحات الفروع نفسها (br_inv_can_view) مش هنا.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/branch_invoices_lib.php';

$pages = br_inv_all_pages();
$scope = br_inv_user_scope();
$full  = is_owner();

if (!$full && !can('branch_invoices')) {
    require_permission('branch_invoices');   // بيصد بالشكل المعتاد
}

// فرع بيع ← على طول لصفحته
if (!$full && is_int($scope)) {
    if (isset($pages[$scope])) {
        header('Location: ' . $pages[$scope]['url']);
        exit;
    }
    $scope = null;   // فرعه مالوش صفحة فواتير (مش المفروض يحصل)
}

$page_title    = 'فواتير الفروع';
$active_module = 'branch_invoices';
require_once __DIR__ . '/includes/header.php';
?>
<?= ui_page_head('فواتير الفروع', 'اختار الفرع — هتلاقي الفواتير اللي اتوزّع منها كميات ليه.') ?>

<section class="fcard">
    <?php if ($full || $scope === 'all'): ?>
        <div class="bi-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:var(--sp-4);">
            <?php foreach ($pages as $p): ?>
                <a class="btn btn-outline" style="justify-content:flex-start;padding:var(--sp-4);gap:var(--sp-3);font-size:var(--fs-md);font-weight:700;" href="<?= e($p['url']) ?>">
                    <i class="ti ti-building-store" style="font-size:22px;color:var(--accent);"></i> فواتير <?= e($p['name']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <?= ui_alert('info', 'حسابك مش متسجّل على فرع — كلّم الإدارة تسجّلك على فرعك.') ?>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
