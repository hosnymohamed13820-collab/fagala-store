<?php
/** عرض تقسيم الأصناف؛ أول صفحة في boot علشان التصوير من غير API. */
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_login();
if (!can('item_master:classify') && !can('item_master:classify_review')) {
    http_response_code(403);die('مالكش صلاحية تقسيم الأصناف');
}
require_once __DIR__ . '/item_classify_lib.php';
$page_title='تقسيم الأصناف';$active_module='item_classify';
$filters=array_merge(['no_section'=>'1','sold90'=>'1'],$_GET);
$icBoot=ica_list($filters);
$icBoot['canReview']=can('item_master:classify_review');
$icBoot['filters']=$filters;
require_once __DIR__ . '/includes/header.php';
?>
<style>
/* تخطيط بس؛ المكوّنات كلها من المكتبة. */
.ica-layout{padding-bottom:100px}.ica-head,.ica-actions{display:flex;gap:var(--sp-3);align-items:center;flex-wrap:wrap}.ica-head{justify-content:space-between}.ica-filters{margin-block:var(--sp-4)}.ica-search{flex:1;min-width:240px}.ica-scroll{overflow:auto}.ica-pages{display:flex;gap:var(--sp-3);align-items:center;justify-content:center;margin-block:var(--sp-3)}.ica-actions .finput,.ica-actions .fselect{width:180px}
</style>
<main class="ica-layout">
    <div class="fpage-head ica-head"><h1>تقسيم الأصناف</h1><span class="fchip fchip-warn" id="icRemaining">باقي <?= (int)$icBoot['remaining'] ?> صنف من غير قسم</span></div>
    <p class="fpage-sub">اختار التصنيف وهـيتحفظ لوحده عندنا، مش في True. اختيار الموقع بيغلب True في تقاريرنا. علّم كذا صنف وطبّق عليهم من الشريط تحت.</p>
    <form id="icFilters" class="ffilters ica-filters">
        <label class="fchip fchip-mute"><input type="checkbox" name="no_section" value="1" <?= !empty($filters['no_section'])?'checked':'' ?>> مالوش قسم</label>
        <label class="fchip fchip-mute"><input type="checkbox" name="sold90" value="1" <?= !empty($filters['sold90'])?'checked':'' ?>> اتباع آخر 90 يوم</label>
        <div class="ffield ica-search"><label for="icSearch">بحث بالاسم أو الكود</label><input id="icSearch" name="q" class="finput finput-sm" value="<?= e((string)($filters['q'] ?? '')) ?>" placeholder="اكتب اسم الصنف أو كوده"></div>
        <div class="ffield"><label for="icUser">قسّمه مين</label><select id="icUser" name="assigned_by" class="fselect finput-sm"><option value="">كل المستخدمين</option><option value="none">بدون تقسيم</option><?php foreach($icBoot['users'] as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?></select></div>
        <div class="ffield"><label for="icStatus">حالة المراجعة</label><select id="icStatus" name="status" class="fselect finput-sm"><option value="">كل الحالات</option><option value="pending">مستني مراجعة</option><option value="approved">متراجع</option></select></div>
        <button type="reset" class="btn btn-ghost btn-sm">استعادة الفلاتر</button>
    </form>
    <div id="icMessage" class="falert falert-info" role="status" hidden></div>
    <p class="fpage-sub">فلتر الجدول على الصفحة الحالية بس · الأسهم بين الخانات · Space يعلّم الصف · Enter يفتح الخانة · Ctrl وسهم لتحت يفتح كل الاختيارات</p>
    <div class="fcard"><div class="ica-scroll"><table id="icTable" class="ftable" data-xt-min="1">
        <thead><tr><th><input id="icAll" type="checkbox" aria-label="علّم الصفحة"></th><th>الكود</th><th>الاسم</th><th class="num">مبيعات 90 يوم (قطعة)</th><th class="num">الرصيد</th><th>القسم</th><th>الوصف</th><th>النوع</th><th>المرحلة</th><th>الشركات</th><th>اقتراح</th><th>الحالة</th><th>قسّمه</th></tr></thead>
        <tbody></tbody>
    </table></div></div>
    <div class="ica-pages"><button id="icPrev" class="btn btn-outline btn-sm">اللي قبلها</button><span id="icPage"></span><button id="icNext" class="btn btn-outline btn-sm">اللي بعدها</button></div>
    <div id="icBulk" class="ftable-action-bar floating" hidden>
        <div class="ica-actions"><strong id="icSelected"></strong><select id="icKind" class="fselect finput-sm" aria-label="نوع التصنيف"><?php foreach(ic_kinds() as $kind=>$label): ?><option value="<?= e($kind) ?>"><?= e($label) ?></option><?php endforeach; ?></select><input id="icClass" class="finput finput-sm" aria-label="التصنيف"><button id="icApply" class="btn btn-primary btn-sm">طبّق على المعلّم</button></div>
        <div class="ica-actions"><?php if($icBoot['canReview']): ?><button id="icApprove" class="btn btn-success btn-sm">وافق على المعلّم</button><?php endif; ?><button id="icUnselect" class="btn btn-ghost btn-sm">إلغاء التحديد</button></div>
    </div>
</main>
<script type="application/json" id="icBoot"><?= json_encode($icBoot,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<script src="assets/item_classify.js?v=1"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
