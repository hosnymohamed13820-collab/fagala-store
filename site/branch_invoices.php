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
<style>
    .bi-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(190px,1fr)); gap:14px; }
    .bi-card { display:flex; align-items:center; gap:12px; padding:18px; border-radius:14px;
               background:var(--surface); border:1.5px solid var(--line); text-decoration:none;
               color:var(--ink-900); font-weight:700; font-size:15px; }
    .bi-card:hover { border-color:var(--accent); color:var(--accent-text); }
    .bi-card i { font-size:22px; color:var(--accent-text); }
    .bi-empty { text-align:center; padding:40px; color:var(--ink-500); }
</style>

<div class="card">
    <h1 style="font-size:18px;margin:0 0 4px 0;color:var(--ink-900);">فواتير الفروع</h1>
    <div style="font-size:13px;color:var(--ink-500);margin-bottom:16px;">
        اختار الفرع — هتلاقي الفواتير اللي اتوزّع منها كميات ليه.
    </div>

    <?php if ($full || $scope === 'all'): ?>
        <div class="bi-grid">
            <?php foreach ($pages as $p): ?>
                <a class="bi-card" href="<?= e($p['url']) ?>">
                    <i class="ti ti-building-store"></i> فواتير <?= e($p['name']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="bi-empty">
            حسابك مش متسجّل على فرع — كلّم الإدارة تسجّلك على فرعك.
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
