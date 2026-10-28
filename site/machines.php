<?php
/**
 * عداد المكن: قرايات الصبح ومطابقة أيام البيع المقفولة.
 */
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_module_access('machines');
require_once __DIR__ . '/machines_lib.php';

$pdo = db();
$user = current_user();
$tabParts = mc_tab_parts();
$tab = (string)($_GET['tab'] ?? 'readings');
if (!isset($tabParts[$tab])) $tab = 'readings';
if (!can('machines:' . $tabParts[$tab])) {
    http_response_code(403); exit('مالكش صلاحية للتاب ده.');
}
try {
    // النطاق في المكتبة عشان machines_api.php يحسبه بنفس الطريقة بالحرف.
    $scope = mc_scope($pdo, $tab, $user, (string)($_GET['branch'] ?? ''));
} catch (McInputError $e) {
    http_response_code(403); exit($e->getMessage());
}
$allScope = $scope['all_scope'];
$allBranches = $scope['all_branches'];
$branchId = $scope['branch_id'];
$today = date('Y-m-d');
$readingDate = (string)($_GET['date'] ?? $today);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$readingDate) || $readingDate > $today) $readingDate=$today;

// الكتابة (القرايات · إضافة/تعطيل مكنة) بقت في machines_api.php — بريف ٢٢٣ج.
$view = mc_view($pdo, $scope, $tab, $today, $readingDate);
$allMachines = $view['machines'];
$readingRows = $view['readings'];
$daily = $view['daily'];
$monthly = $view['monthly'];
$unknown = $view['unknown'];
$unmapped = $view['unmapped'];
$salesReady = $view['sales_ready'];

if (($_GET['export'] ?? '') === 'xlsx' && in_array($tab,['daily','monthly'],true)) {
    require_once __DIR__ . '/xlsx_lib.php';
    $x=new SimpleXlsx($tab==='daily' ? 'عداد المكن يومي' : 'عداد المكن شهري');
    $x->addRow(['اليوم أو الشهر','الفرع','أسود مباع','أسود عداد','فرق الأسود',
        'ألوان مباع','ألوان عداد','فرق الألوان','الحالة'],true);
    foreach ($tab==='daily' ? $daily : $monthly as $r) {
        $x->addRow([$tab==='daily' ? $r['label'] : $r['month'],$r['branch'],
            $salesReady ? $r['bw_sold'] : '',$r['bw_counter'] ?? '',$salesReady ? ($r['bw_diff'] ?? '') : '',
            $salesReady ? $r['color_sold'] : '',$r['color_counter'] ?? '',$salesReady ? ($r['color_diff'] ?? '') : '',
            $salesReady ? $r['status'] : 'مبيعات ناقصة']);
    }
    $x->download('machine_' . $tab . '_' . $today . '.xlsx'); exit;
}

$pageTitle='عداد المكن';
$active_module='machines';
require_once __DIR__ . '/includes/header.php';
function mc_fmt(?float $n): string { return $n===null ? '—' : number_format($n,3); }
function mc_chip(string $status): string {
    return match($status) {
        'مطابق'=>'fchip-pos','عجز'=>'fchip-neg','زيادة'=>'fchip-warn',
        'عجز وزيادة'=>'fchip-neg',default=>'fchip-mute'
    };
}
?>
<div class="fcard">
    <h3>عداد المكن</h3>
    <div class="ftabs">
        <?php foreach (['readings'=>'القراءات','daily'=>'اليومي','monthly'=>'الشهري','manage'=>'المكن'] as $key=>$label): ?>
            <?php if (can('machines:' . $tabParts[$key])): ?>
                <a class="ftab <?= $tab===$key?'is-active':'' ?>"
                   href="?tab=<?= e($key) ?>&branch=<?= $branchId ?: 'all' ?>"><?= e($label) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <form method="get" class="ffilters">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <?php if ($allScope): ?>
            <label class="ffield">الفرع<select class="fselect" name="branch">
                <?php if ($tab==='daily' || $tab==='monthly'): ?><option value="all" <?= !$branchId?'selected':'' ?>>كل الفروع</option><?php endif; ?>
                <?php foreach ($allBranches as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $branchId===(int)$b['id']?'selected':'' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
            </select></label>
        <?php else: ?><input type="hidden" name="branch" value="<?= $branchId ?>"><?php endif; ?>
        <?php if ($tab==='readings'): ?>
            <label class="ffield">تاريخ قراية الصبح<input class="finput" type="date" name="date" max="<?= e($today) ?>" value="<?= e($readingDate) ?>"></label>
        <?php endif; ?>
        <button class="btn btn-sm" type="submit">عرض</button>
    </form>
</div>

<?php if ($tab==='readings'): ?>
<div class="fcard">
    <h3>قرايات صبح <?= e($readingDate) ?></h3>
    <p>قراية النهارده بداية استهلاك النهارده؛ يوم امبارح بيتقفل بقراية الصبح دي.</p>
    <?php if (!$allMachines): ?><p>مفيش مكن متسجل للفرع ده.</p><?php else: ?>
    <div class="ftable-wrap"><table class="ftable" data-xt-off>
        <thead><tr><th>المكنة</th><th>النوع</th><th>العدادات</th></tr></thead><tbody>
        <?php foreach ($readingRows as $m): if (!(int)$m['is_active']) continue;
            $mid=(int)$m['id'];
        ?>
        <tr><td><?= e($m['name']) ?></td><td><?= $m['kind']==='color'?'ألوان':'أبيض وأسود' ?></td><td>
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <?php foreach ($m['fields'] as $field=>$f):
                $prev=$f['prev']; $prevDate=$f['prev_date']; $value=$f['value']; $delta=$f['delta'];
            ?>
                <label style="min-width:150px;display:block;"><?= e($f['label']) ?><br>
                    <input class="finput finput-sm num" type="number" min="0" step="1" inputmode="numeric"
                           value="<?= $value===null?'':(int)$value ?>" data-machine="<?= $mid ?>"
                           data-field="<?= e($field) ?>" data-day="<?= e($readingDate) ?>"
                           data-prev="<?= $prev===null?'':$prev ?>" data-saved="<?= $value===null?'':(int)$value ?>"
                           aria-label="<?= e($m['name'] . ' ' . $f['label']) ?>">
                    <small style="display:block;color:var(--ink-500);">قبلها: <?= $prev===null?'—':number_format($prev) ?><?= $prevDate?' (' . e($prevDate) . ')':'' ?></small>
                    <small class="mc-delta" style="display:block;">الفرق: <?= $delta===null?'—':number_format($delta) ?></small>
                    <small class="mc-warning" style="display:<?= $delta!==null && $delta<0?'block':'none' ?>;color:var(--neg);">العداد رجع لورا؟ مكنة اتغيّرت؟ اليوم ده مش هيدخل الحسبة.</small>
                    <small class="mc-save" aria-live="polite"></small>
                </label>
            <?php endforeach; ?>
            </div>
        </td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</div>
<script>
// الحفظ لما تسيب الخانة، والتنقّل بالسهم أو Enter من غير زرار حفظ.
(function () {
    const fields = [...document.querySelectorAll('[data-machine][data-field]')];
    function show(input) {
        const box = input.closest('label'), prev = input.dataset.prev === '' ? null : Number(input.dataset.prev);
        const val = input.value === '' ? null : Number(input.value);
        const delta = val === null || prev === null ? null : val - prev;
        box.querySelector('.mc-delta').textContent = 'الفرق: ' + (delta === null ? '—' : delta.toLocaleString('en-US'));
        box.querySelector('.mc-warning').style.display = delta !== null && delta < 0 ? 'block' : 'none';
    }
    fields.forEach((input, i) => {
        input.addEventListener('input', () => show(input));
        input.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                fields[Math.max(0,Math.min(fields.length-1,i+(e.key==='ArrowUp'?-1:1)))].focus();
            }
        });
        input.addEventListener('blur', async () => {
            if (input.value === input.dataset.saved) return;
            const msg = input.closest('label').querySelector('.mc-save');
            msg.textContent = 'بيتحفظ…';
            try {
                const j = await apiFetch('machines_api.php',{method:'POST',body:{action:'reading',
                    machine_id:input.dataset.machine,date:input.dataset.day,
                    field:input.dataset.field,value:input.value}});
                const data = j.data || {};
                input.dataset.saved = input.value;
                msg.textContent = data.backwards ? 'اتحفظت؛ راجع القراية.' : 'اتحفظت';
                msg.style.color = data.backwards ? 'var(--neg)' : 'var(--pos)';
            } catch (err) {
                msg.textContent = err.message;
                msg.style.color = 'var(--neg)';
            }
        });
    });
})();
</script>
<?php elseif ($tab==='daily' || $tab==='monthly'): ?>
<div class="fcard">
    <h3><?= $tab==='daily'?'اليومي — آخر ٣١ يوم':'الشهري — آخر ١٢ شهر' ?></h3>
    <?php if (!$salesReady): ?><p style="color:var(--neg)">بيانات مبيعات الفروع مش متزامنة هنا؛ عمود المباع وفروق المطابقة غير موثوقين لحد ما ربط الوكلاء يتوفر.</p><?php endif; ?>
    <p>الضغطات بتتحسب من قراية صباح بداية اليوم لقراية الصبح اللي بعده. الأيام اللي في النص بتتجمع من غير تقسيم تقديري.</p>
    <a class="btn btn-sm btn-ghost" href="?tab=<?= e($tab) ?>&branch=<?= $branchId ?: 'all' ?>&export=xlsx">تصدير إكسيل</a>
    <div class="ftable-wrap"><table class="ftable" data-xt-min="1"><thead><tr>
        <th><?= $tab==='daily'?'اليوم':'الشهر' ?></th><th>الفرع</th>
        <th class="num">أسود مباع</th><th class="num">أسود عداد</th><th class="num">فرق الأسود</th>
        <th class="num">ألوان مباع</th><th class="num">ألوان عداد</th><th class="num">فرق الألوان</th><th>الحالة</th>
    </tr></thead><tbody>
        <?php foreach ($tab==='daily'?$daily:$monthly as $r): ?>
        <tr><td><?= e($tab==='daily'?$r['label']:$r['month']) ?></td><td><?= e($r['branch']) ?></td>
            <td class="num"><?= $salesReady ? mc_fmt($r['bw_sold']) : '—' ?></td><td class="num"><?= mc_fmt($r['bw_counter']) ?></td>
            <td class="num"><?= $salesReady ? mc_fmt($r['bw_diff']) : '—' ?></td><td class="num"><?= $salesReady ? mc_fmt($r['color_sold']) : '—' ?></td>
            <td class="num"><?= mc_fmt($r['color_counter']) ?></td><td class="num"><?= $salesReady ? mc_fmt($r['color_diff']) : '—' ?></td>
            <td><span class="fchip <?= $salesReady ? mc_chip($r['status']) : 'fchip-mute' ?>"><?= e($salesReady ? $r['status'] : 'مبيعات ناقصة') ?></span></td></tr>
        <?php endforeach; ?>
    </tbody></table></div>
</div>
<?php if ($tab==='daily' && $unknown): ?>
<div class="fcard"><h3>أكواد مش متعرّفة</h3>
    <div class="ftable-wrap"><table class="ftable"><thead><tr><th>اليوم</th><th>الفرع</th><th>الكود</th><th>الاسم</th><th class="num">صافي الكمية</th></tr></thead><tbody>
    <?php foreach ($unknown as $r): ?>
        <tr><td><?= e($r['day']) ?></td><td><?= e($r['branch']) ?></td><td><?= e($r['code']) ?></td>
            <td><?= e($r['name']) ?></td><td class="num"><?= mc_fmt($r['qty']) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
</div>
<?php endif; ?>
<?php if ($unmapped): ?><div class="fcard">فيه مبيعات وكلاء من غير ربط مؤكد بفرع: <?= e(implode('، ',$unmapped)) ?>.</div><?php endif; ?>
<?php elseif ($tab==='manage'): ?>
<div class="fcard">
    <h3>إضافة مكنة</h3>
    <form id="mc-add" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add_machine">
        <input type="hidden" name="branch_id" value="<?= $branchId ?>">
        <label class="ffield">الاسم<input class="finput" name="name" required maxlength="120"></label>
        <label class="ffield">النوع<select class="fselect" name="kind"><option value="bw">أبيض وأسود</option><option value="color">ألوان</option></select></label>
        <button class="btn btn-sm" type="submit">إضافة</button><span id="mc-add-msg" aria-live="polite"></span>
    </form>
    <div class="ftable-wrap"><table class="ftable"><thead><tr><th>المكنة</th><th>النوع</th><th>الحالة</th><th>إجراء</th></tr></thead><tbody>
    <?php foreach ($allMachines as $m): ?>
        <tr><td><?= e($m['name']) ?></td><td><?= $m['kind']==='color'?'ألوان':'أبيض وأسود' ?></td>
            <td><span class="fchip <?= (int)$m['is_active']?'fchip-pos':'fchip-mute' ?>"><?= (int)$m['is_active']?'شغالة':'متوقفة' ?></span></td>
            <td><button class="btn btn-sm btn-ghost mc-toggle" type="button" data-id="<?= (int)$m['id'] ?>"
                        data-branch="<?= (int)$m['branch_id'] ?>" data-active="<?= (int)$m['is_active']?'0':'1' ?>">
                <?= (int)$m['is_active']?'تعطيل':'تفعيل' ?></button></td></tr>
    <?php endforeach; ?></tbody></table></div>
</div>
<script>
(function () {
    const form = document.getElementById('mc-add'), msg = document.getElementById('mc-add-msg');
    form.addEventListener('submit', async e => {
        e.preventDefault();
        try {
            await apiFetch('machines_api.php',{method:'POST',body:new FormData(form)});
            location.reload();
        } catch (err) { msg.textContent = err.message; }
    });
    document.querySelectorAll('.mc-toggle').forEach(btn => btn.addEventListener('click', async () => {
        try {
            await apiFetch('machines_api.php',{method:'POST',body:{action:'toggle_machine',
                machine_id:btn.dataset.id,branch_id:btn.dataset.branch,active:btn.dataset.active}});
            location.reload();
        } catch (err) { msg.textContent = err.message; }
    }));
})();
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
