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
$userBranch = (int)($user['branch_id'] ?? 0);
$allScope = is_real_owner() || in_array('view_all', $_SESSION['permissions'] ?? [], true);
$tabParts = ['readings'=>'readings','daily'=>'daily','monthly'=>'monthly','manage'=>'manage'];
$tab = (string)($_GET['tab'] ?? 'readings');
if (!isset($tabParts[$tab])) $tab = 'readings';
if (!can('machines:' . $tabParts[$tab])) {
    http_response_code(403); exit('مالكش صلاحية للتاب ده.');
}
$allBranches = mc_branches($pdo);
$requestedBranch = (string)($_GET['branch'] ?? '');
$branchId = $allScope && ctype_digit($requestedBranch) ? (int)$requestedBranch : $userBranch;
if ($allScope && $requestedBranch === 'all' && $tab !== 'readings' && $tab !== 'manage') $branchId = 0;
if ($allScope && $branchId === 0 && in_array($tab,['readings','manage'],true)) {
    $branchId = $allBranches ? (int)$allBranches[0]['id'] : 0;
}
$allowedIds = array_map(static fn($b) => (int)$b['id'], $allBranches);
if ($branchId !== 0 && !in_array($branchId,$allowedIds,true)) {
    http_response_code(403); exit('الفرع مش متاح.');
}
if (!$allScope && $userBranch <= 0) {
    http_response_code(403); exit('حسابك مش مربوط بفرع.');
}
$scopeBranches = $branchId > 0 ? mc_branches($pdo,$branchId) : $allBranches;
$scopeIds = array_map(static fn($b) => (int)$b['id'], $scopeBranches);
$today = date('Y-m-d');
$readingDate = (string)($_GET['date'] ?? $today);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$readingDate) || $readingDate > $today) $readingDate=$today;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!csrf_verify($_POST['csrf'] ?? '')) throw new RuntimeException('الجلسة انتهت، حدّث الصفحة.');
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'reading') {
            if (!can('machines:readings')) throw new RuntimeException('مالكش صلاحية إدخال قراية.');
            $machineId=(int)($_POST['machine_id'] ?? 0);
            $st=$pdo->prepare('SELECT branch_id FROM machines WHERE id = ?');
            $st->execute([$machineId]);
            $machineBranch=(int)$st->fetchColumn();
            if (!$machineBranch || (!is_real_owner() && $machineBranch !== $userBranch)
                || !in_array($machineBranch,$allowedIds,true)) {
                http_response_code(403); throw new RuntimeException('المكنة مش في فرعك.');
            }
            $raw=trim((string)($_POST['value'] ?? ''));
            if ($raw !== '' && (!ctype_digit($raw) || strlen($raw)>18)) {
                throw new RuntimeException('اكتب عدد صحيح موجب أو سيب الخانة فاضية.');
            }
            $result=mc_save_reading($pdo,$machineId,$machineBranch,
                (string)($_POST['date'] ?? ''),(string)($_POST['field'] ?? ''),
                $raw === '' ? null : (int)$raw,(int)$user['id']);
            echo json_encode(['ok'=>true]+$result,JSON_UNESCAPED_UNICODE); exit;
        }
        if ($action === 'add_machine' || $action === 'toggle_machine') {
            if (!can('machines:manage')) {
                http_response_code(403); throw new RuntimeException('إدارة المكن للمالك أو مدير الفرع بس.');
            }
            $postBranch=(int)($_POST['branch_id'] ?? 0);
            if ((!is_real_owner() && $postBranch !== $userBranch)
                || !in_array($postBranch,$allowedIds,true)) {
                http_response_code(403); throw new RuntimeException('الفرع مش متاح لك.');
            }
            if ($action === 'add_machine') {
                $id=mc_add_machine($pdo,$postBranch,(string)($_POST['name'] ?? ''),
                    (string)($_POST['kind'] ?? ''));
                echo json_encode(['ok'=>true,'id'=>$id],JSON_UNESCAPED_UNICODE); exit;
            }
            mc_toggle_machine($pdo,(int)($_POST['machine_id'] ?? 0),$postBranch,
                ($_POST['active'] ?? '') === '1');
            echo json_encode(['ok'=>true],JSON_UNESCAPED_UNICODE); exit;
        }
        throw new RuntimeException('العملية غير معروفة.');
    } catch (Throwable $e) {
        if (http_response_code() !== 403) http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE); exit;
    }
}

$allMachines=mc_machines($pdo,$scopeIds,false);
$machineIds=array_map(static fn($m)=>(int)$m['id'],$allMachines);
$readings=mc_readings($pdo,$machineIds,$today);
$daily=[]; $monthly=[]; $unknown=[]; $unmapped=[]; $salesReady=true;
if ($tab==='daily' || $tab==='monthly') {
    $from=$tab==='daily' ? date('Y-m-d',strtotime($today . ' -30 days'))
        : date('Y-m-01',strtotime($today . ' -11 months'));
    $saleData=mc_sales($pdo,$scopeBranches,$from,$today);
    $unknown=$saleData['unknown'];
    $unmapped=$saleData['unmapped'];
    $salesReady=$saleData['sales_ready'];
    $daily=mc_daily_rows($scopeBranches,$allMachines,$readings,$saleData['sales'],$from,$today);
    if ($tab==='monthly') $monthly=mc_monthly_rows($scopeBranches,$daily,$from,$today);
}

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
<div class="card">
    <h3>عداد المكن</h3>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
        <?php foreach (['readings'=>'القراءات','daily'=>'اليومي','monthly'=>'الشهري','manage'=>'المكن'] as $key=>$label): ?>
            <?php if (can('machines:' . $tabParts[$key])): ?>
                <a class="btn btn-sm <?= $tab===$key?'btn-primary':'btn-ghost' ?>"
                   href="?tab=<?= e($key) ?>&branch=<?= $branchId ?: 'all' ?>"><?= e($label) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <form method="get" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <?php if ($allScope): ?>
            <label>الفرع<br><select name="branch">
                <?php if ($tab==='daily' || $tab==='monthly'): ?><option value="all" <?= !$branchId?'selected':'' ?>>كل الفروع</option><?php endif; ?>
                <?php foreach ($allBranches as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $branchId===(int)$b['id']?'selected':'' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
            </select></label>
        <?php else: ?><input type="hidden" name="branch" value="<?= $branchId ?>"><?php endif; ?>
        <?php if ($tab==='readings'): ?>
            <label>تاريخ قراية الصبح<br><input type="date" name="date" max="<?= e($today) ?>" value="<?= e($readingDate) ?>"></label>
        <?php endif; ?>
        <button class="btn btn-sm" type="submit">عرض</button>
    </form>
</div>

<?php if ($tab==='readings'): ?>
<div class="card">
    <h3>قرايات صبح <?= e($readingDate) ?></h3>
    <p>قراية النهارده بداية استهلاك النهارده؛ يوم امبارح بيتقفل بقراية الصبح دي.</p>
    <?php if (!$allMachines): ?><p>مفيش مكن متسجل للفرع ده.</p><?php else: ?>
    <div style="overflow-x:auto"><table class="ftable" data-xt-off>
        <thead><tr><th>المكنة</th><th>النوع</th><th>العدادات</th></tr></thead><tbody>
        <?php foreach ($allMachines as $m): if (!(int)$m['is_active']) continue;
            $mid=(int)$m['id']; $current=$readings[$mid][$readingDate] ?? [];
        ?>
        <tr><td><?= e($m['name']) ?></td><td><?= $m['kind']==='color'?'ألوان':'أبيض وأسود' ?></td><td>
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <?php foreach (mc_fields($m['kind']) as $field=>$label):
                $prev=null; $prevDate=null;
                foreach ($readings[$mid] ?? [] as $d=>$old) {
                    if ($d >= $readingDate || $old[$field]===null) continue;
                    if ($prevDate===null || $d>$prevDate) { $prevDate=$d; $prev=(int)$old[$field]; }
                }
                $value=$current[$field] ?? null;
                $delta=$value!==null && $prev!==null ? (int)$value-$prev : null;
            ?>
                <label style="min-width:150px;display:block;"><?= e($label) ?><br>
                    <input type="number" min="0" step="1" inputmode="numeric"
                           value="<?= $value===null?'':(int)$value ?>" data-machine="<?= $mid ?>"
                           data-field="<?= e($field) ?>" data-day="<?= e($readingDate) ?>"
                           data-prev="<?= $prev===null?'':$prev ?>" data-saved="<?= $value===null?'':(int)$value ?>"
                           aria-label="<?= e($m['name'] . ' ' . $label) ?>">
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
    const csrf = <?= json_encode(csrf_token()) ?>;
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
            const body = new URLSearchParams({csrf,action:'reading',machine_id:input.dataset.machine,
                date:input.dataset.day,field:input.dataset.field,value:input.value});
            try {
                const res = await fetch('machines.php',{method:'POST',body});
                const data = await res.json();
                if (!res.ok || !data.ok) throw new Error(data.error || 'الحفظ فشل.');
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
<div class="card">
    <h3><?= $tab==='daily'?'اليومي — آخر ٣١ يوم':'الشهري — آخر ١٢ شهر' ?></h3>
    <?php if (!$salesReady): ?><p style="color:var(--neg)">بيانات مبيعات الفروع مش متزامنة هنا؛ عمود المباع وفروق المطابقة غير موثوقين لحد ما ربط الوكلاء يتوفر.</p><?php endif; ?>
    <p>الضغطات بتتحسب من قراية صباح بداية اليوم لقراية الصبح اللي بعده. الأيام اللي في النص بتتجمع من غير تقسيم تقديري.</p>
    <a class="btn btn-sm btn-ghost" href="?tab=<?= e($tab) ?>&branch=<?= $branchId ?: 'all' ?>&export=xlsx">تصدير إكسيل</a>
    <div style="overflow-x:auto"><table class="ftable" data-xt-min="1"><thead><tr>
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
<div class="card"><h3>أكواد مش متعرّفة</h3>
    <table class="ftable"><thead><tr><th>اليوم</th><th>الفرع</th><th>الكود</th><th>الاسم</th><th class="num">صافي الكمية</th></tr></thead><tbody>
    <?php foreach ($unknown as $r):
        $name=''; foreach ($scopeBranches as $b) if ((int)$b['id']===$r['branch_id']) { $name=$b['name']; break; } ?>
        <tr><td><?= e($r['day']) ?></td><td><?= e($name) ?></td><td><?= e($r['code']) ?></td>
            <td><?= e($r['name']) ?></td><td class="num"><?= mc_fmt($r['qty']) ?></td></tr>
    <?php endforeach; ?></tbody></table>
</div>
<?php endif; ?>
<?php if ($unmapped): ?><div class="card">فيه مبيعات وكلاء من غير ربط مؤكد بفرع: <?= e(implode('، ',$unmapped)) ?>.</div><?php endif; ?>
<?php elseif ($tab==='manage'): ?>
<div class="card">
    <h3>إضافة مكنة</h3>
    <form id="mc-add" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add_machine">
        <input type="hidden" name="branch_id" value="<?= $branchId ?>">
        <label>الاسم<br><input name="name" required maxlength="120"></label>
        <label>النوع<br><select name="kind"><option value="bw">أبيض وأسود</option><option value="color">ألوان</option></select></label>
        <button class="btn btn-sm" type="submit">إضافة</button><span id="mc-add-msg" aria-live="polite"></span>
    </form>
    <table class="ftable"><thead><tr><th>المكنة</th><th>النوع</th><th>الحالة</th><th>إجراء</th></tr></thead><tbody>
    <?php foreach ($allMachines as $m): ?>
        <tr><td><?= e($m['name']) ?></td><td><?= $m['kind']==='color'?'ألوان':'أبيض وأسود' ?></td>
            <td><span class="fchip <?= (int)$m['is_active']?'fchip-pos':'fchip-mute' ?>"><?= (int)$m['is_active']?'شغالة':'متوقفة' ?></span></td>
            <td><button class="btn btn-sm btn-ghost mc-toggle" type="button" data-id="<?= (int)$m['id'] ?>"
                        data-branch="<?= (int)$m['branch_id'] ?>" data-active="<?= (int)$m['is_active']?'0':'1' ?>">
                <?= (int)$m['is_active']?'تعطيل':'تفعيل' ?></button></td></tr>
    <?php endforeach; ?></tbody></table>
</div>
<script>
(function () {
    const form = document.getElementById('mc-add'), msg = document.getElementById('mc-add-msg');
    form.addEventListener('submit', async e => {
        e.preventDefault();
        try {
            const res = await fetch('machines.php',{method:'POST',body:new URLSearchParams(new FormData(form))});
            const data = await res.json();
            if (!res.ok || !data.ok) throw new Error(data.error || 'الإضافة فشلت.');
            location.reload();
        } catch (err) { msg.textContent = err.message; }
    });
    document.querySelectorAll('.mc-toggle').forEach(btn => btn.addEventListener('click', async () => {
        const body = new URLSearchParams({csrf:form.querySelector('[name=csrf]').value,
            action:'toggle_machine',machine_id:btn.dataset.id,branch_id:btn.dataset.branch,
            active:btn.dataset.active});
        try {
            const res = await fetch('machines.php',{method:'POST',body});
            const data = await res.json();
            if (!res.ok || !data.ok) throw new Error(data.error || 'التغيير فشل.');
            location.reload();
        } catch (err) { msg.textContent = err.message; }
    }));
})();
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
