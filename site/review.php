<?php
/**
 * review.php — مراجعة المحاسب (شكل جديد: كروت + بوب أب بتابات + مقارنة)
 * نظام الفجالة ستور المالي
 *
 * - أرقام الكاشير ظاهرة للمحاسب في كل التابات (شريط أعلى البوب أب).
 * - تابات: كاش (فئات) + فيزا/إنستا (سطور) + مقارنة.
 * - الاعتماد أو الرفض → الأرقام تدخل الخزائن (عبر save_shift_review).
 */
require_once __DIR__ . '/auth.php';
require_permission('review');
require_once __DIR__ . '/finance.php';

$pdo  = db();
$uid  = (int) current_user()['id'];
$flash = ''; $flashType = 'success';

$denominations = [200, 100, 50, 20, 10, 5, 1, 0.5, 0.25];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $flash = 'انتهت صلاحية الجلسة، حاول مرة أخرى.';
        $flashType = 'error';
    } else {
        try {
            $shiftId  = (int) ($_POST['shift_id'] ?? 0);
            $decision = $_POST['decision'] ?? '';
            $reason   = trim($_POST['reason'] ?? '');

            // ★ قيمة السيستم إجباري عند الاعتماد.
            //   من غيرها الفرق بيتحسب على أساس صفر، والقيود تدخل الخزائن غلط.
            //   (عند الرفض مش إجباري — ممكن يرفض عشان الأرقام ناقصة أصلًا.)
            $rawSys = trim((string)($_POST['acc_system'] ?? ''));
            if ($decision === 'approved' && ($rawSys === '' || !is_numeric($rawSys))) {
                throw new RuntimeException('اكتب قيمة السيستم قبل الاعتماد.');
            }

            $amounts = [
                'cash'     => (float) ($_POST['acc_cash'] ?? 0),
                'visa'     => (float) ($_POST['acc_visa'] ?? 0),
                'instapay' => (float) ($_POST['acc_instapay'] ?? 0),
                'system'   => (float) $rawSys,
            ];
            save_shift_review($shiftId, $amounts, $decision, $reason ?: null, $uid);
            $flash = $decision === 'approved'
                ? 'تم اعتماد الوردية ودخلت الأرقام الخزائن.'
                : 'تم رفض الوردية وأُعيدت للكاشير. الأرقام دخلت الخزائن.';
        } catch (Throwable $ex) {
            $flash = 'خطأ: ' . $ex->getMessage();
            $flashType = 'error';
        }
    }
    // PRG: بعد أي POST نعمل تحويل لصفحة GET عشان الـ Refresh ما يعيدش الإرسال (منع التكرار)
    $_SESSION['flash'] = $flash;
    $_SESSION['flash_type'] = $flashType;
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF']));
    exit;
}

// نقرأ رسالة الفلاش من السيشن (بعد التحويل)
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    $flashType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash'], $_SESSION['flash_type']);
}

// الورديات المنتظرة للمراجعة
$pending = $pdo->query(
    "SELECT s.*, u.full_name AS cashier_name, b.name AS branch_name
     FROM shifts s
     JOIN users u ON u.id = s.user_id
     JOIN branches b ON b.id = s.branch_id
     WHERE s.status IN ('submitted','resubmitted')
     ORDER BY s.id DESC"
)->fetchAll();

// السجل (آخر 50 وردية تمت مراجعتها)
$history = $pdo->query(
    "SELECT s.*, u.full_name AS cashier_name, b.name AS branch_name
     FROM shifts s
     JOIN users u ON u.id = s.user_id
     JOIN branches b ON b.id = s.branch_id
     WHERE s.status IN ('approved','rejected')
     ORDER BY s.id DESC LIMIT 50"
)->fetchAll();

$page_title    = 'مراجعة المحاسب';
$active_module = 'review';
require_once __DIR__ . '/includes/header.php';

// دالة مساعدة: أرقام الكاشير لوردية
function shift_cashier_amounts(array $s): array {
    return [
        'cash'     => (float)($s['cash_total'] ?? 0),
        'visa'     => (float)($s['visa_total'] ?? 0),
        'instapay' => (float)($s['instapay_total'] ?? 0),
        'system'   => (float)($s['system_total'] ?? 0),
    ];
}
?>

<style>
    .flash { padding:12px 16px; border-radius:10px; margin-bottom:18px; font-size:14px; }
    .flash.success { background:var(--pos-soft); color:var(--pos); }
    .flash.error   { background:var(--neg-soft); color:var(--neg); }
    .rv-tabs { display:flex; gap:18px; border-bottom:2px solid var(--line); margin-bottom:20px; }
    .rv-tab { padding:10px 4px; cursor:pointer; color:var(--ink-500); font-weight:600; font-size:15px;
        border-bottom:3px solid transparent; margin-bottom:-2px; background:none; border-top:none; border-left:none; border-right:none; font-family:inherit; }
    .rv-tab.active { color:var(--accent-text); border-bottom-color:var(--accent-text); }
    .rv-pane { display:none; } .rv-pane.active { display:block; }
    /* كروت الورديات */
    .shift-card { background:var(--surface); border-radius:14px; padding:18px 20px; margin-bottom:16px;
        box-shadow:0 2px 10px rgba(124,111,247,0.06); border-right:4px solid #f0a500; }
    .shift-card.done { border-right-color:var(--pos); }
    .shift-card.rej { border-right-color:var(--neg); }
    .sc-head { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:14px; }
    .sc-title { font-size:16px; color:var(--ink-900); }
    .sc-title b { color:var(--accent-text); }
    .sc-sub { color:var(--ink-500); font-size:13px; }
    .sc-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; }
    .sc-stat { background:var(--line-soft); border-radius:10px; padding:12px; text-align:center; }
    .sc-stat .l { font-size:12px; color:var(--ink-500); margin-bottom:4px; }
    .sc-stat .v { font-size:16px; font-weight:700; color:var(--ink-900); }
    .sc-stat .v.red { color:var(--neg); }
    .btn-review { background:var(--accent); color:#fff; border:none; border-radius:9px; padding:9px 18px; cursor:pointer; font-family:inherit; font-size:14px; display:inline-flex; align-items:center; gap:6px; }
    /* بوب أب */
    .rv-modal { display:none; position:fixed; inset:0; background:rgba(30,27,75,0.5); z-index:9999; align-items:flex-start; justify-content:center; padding:24px 14px; overflow-y:auto; }
    .rv-modal.open { display:flex; }
    .rv-box { background:var(--surface); border-radius:16px; width:100%; max-width:620px; margin:auto; box-shadow:0 18px 50px rgba(0,0,0,0.25); }
    .rv-mhead { display:flex; justify-content:space-between; align-items:center; padding:16px 22px; border-bottom:1.5px solid var(--line); position:sticky; top:0; background:var(--surface); border-radius:16px 16px 0 0; }
    .rv-mhead .t { font-size:16px; color:var(--ink-900); font-weight:700; }
    .rv-x { background:none; border:none; font-size:26px; color:var(--ink-400); cursor:pointer; }
    .rv-mbody { padding:18px 22px; }
    .rv-note { background:#eef5ff; color:#2d6cc0; padding:10px 14px; border-radius:9px; font-size:13px; margin-bottom:14px; }
    .rv-sys { margin-bottom:14px; }
    .rv-sys label { font-size:13px; color:var(--ink-500); display:block; margin-bottom:5px; }
    .rv-sys input { width:100%; padding:9px 12px; border:1.5px solid var(--line); border-radius:9px; font-family:inherit; font-size:15px; }
    .rv-ptabs { display:flex; gap:4px; flex-wrap:wrap; border-bottom:2px solid var(--line); margin-bottom:16px; }
    .rv-ptab { padding:8px 12px; cursor:pointer; color:var(--ink-500); font-size:13px; font-weight:500; border:none; background:none; border-bottom:2px solid transparent; margin-bottom:-2px; font-family:inherit; }
    .rv-ptab.active { color:var(--accent-text); border-bottom-color:var(--accent-text); }
    .rv-ppane { display:none; } .rv-ppane.active { display:block; }
    /* تاب الكاش (شكل قديم: قائمة + بوكس إجمالي) */
    .rv-cash-layout { display:grid; grid-template-columns:1fr 0.9fr; gap:14px; align-items:start; }
    @media (max-width:560px){ .rv-cash-layout { grid-template-columns:1fr; } }
    .denom-row { display:flex; align-items:center; gap:10px; margin-bottom:8px; justify-content:flex-end; }
    .denom-row .dn { width:70px; color:var(--ink-700); font-size:13px; text-align:left; }
    .denom-row input { width:80px; padding:6px 8px; border:1.5px solid var(--line); border-radius:7px; text-align:center; font-family:inherit; }
    .denom-row .sub { width:90px; color:var(--ink-500); font-size:13px; }
    .rv-cash-box { background:var(--bg); border-radius:12px; padding:16px; }
    .rv-cash-box .l { font-size:13px; color:var(--ink-500); }
    .rv-cash-box .v { font-size:24px; font-weight:700; color:var(--accent-text); margin-top:2px; }
    .pay-row { display:grid; grid-template-columns:1fr 100px 1fr 36px; gap:8px; margin-bottom:6px; align-items:center; }
    .pay-row input { padding:6px 8px; border:1.5px solid var(--line); border-radius:7px; font-family:inherit; font-size:13px; }
    .rv-total { background:var(--line-soft); border-radius:9px; padding:10px 14px; font-weight:600; color:var(--accent-text); text-align:center; margin-top:8px; }
    .cmp-table { width:100%; border-collapse:collapse; }
    .cmp-table th, .cmp-table td { padding:9px 12px; text-align:center; border-bottom:1px solid var(--line-soft); font-size:14px; }
    .cmp-table th { background:var(--line-soft); color:var(--ink-500); }
    .cmp-ok { color:var(--pos); } .cmp-bad { color:var(--neg); }
    .rv-actions { display:flex; gap:8px; margin-top:14px; }
    .btn-approve { background:var(--pos-fill); color:#fff; flex:1; }
    .btn-reject  { background:var(--neg-fill); color:#fff; flex:1; }
    @media (max-width:560px){ .sc-stats { grid-template-columns:repeat(2,1fr); } .pay-row { grid-template-columns:1fr 80px 36px; } }
</style>

<?php if ($flash): ?>
    <div class="flash <?= $flashType ?>"><?= e($flash) ?></div>
<?php endif; ?>

<div class="rv-tabs">
    <button class="rv-tab active" onclick="rvTab(this,'pane_pending')">في انتظار المراجعة (<?= count($pending) ?>)</button>
    <button class="rv-tab" onclick="rvTab(this,'pane_history')">السجل</button>
</div>

<!-- في انتظار المراجعة -->
<div class="rv-pane active" id="pane_pending">
    <?php if (empty($pending)): ?>
        <div class="card" style="text-align:center;color:var(--ink-500);padding:30px;">مفيش ورديات في انتظار المراجعة.</div>
    <?php else: ?>
        <?php foreach ($pending as $s): $amt = shift_cashier_amounts($s); $diff = ($amt['cash']+$amt['visa']+$amt['instapay']) - $amt['system']; ?>
            <div class="shift-card">
                <div class="sc-head">
                    <div>
                        <div class="sc-title"><b><?= e($s['branch_name']) ?></b> — وردية <span class="sc-sub"><?= e($s['business_date']) ?> · كاشير: <?= e($s['cashier_name']) ?></span></div>
                    </div>
                    <button class="btn-review" onclick='openReview(<?= json_encode([
                        "id"=>(int)$s["id"], "branch"=>$s["branch_name"], "cashier"=>$s["cashier_name"],
                        "date"=>$s["business_date"],
                        "cash"=>$amt["cash"], "visa"=>$amt["visa"], "instapay"=>$amt["instapay"],
                        "system"=>$amt["system"],
                    ], JSON_UNESCAPED_UNICODE) ?>)'><i class="ti ti-eye"></i> مراجعة</button>
                </div>
                <div class="sc-stats">
                    <div class="sc-stat"><div class="l">مبيعات السيستم</div><div class="v"><?= number_format($amt['system'],2) ?></div></div>
                    <div class="sc-stat"><div class="l">كاش</div><div class="v"><?= number_format($amt['cash'],2) ?></div></div>
                    <div class="sc-stat"><div class="l">فيزا + إنستا باي</div><div class="v"><?= number_format($amt['visa']+$amt['instapay'],2) ?></div></div>
                    <div class="sc-stat"><div class="l">الفرق</div><div class="v <?= abs($diff)>=0.01?'red':'' ?>"><?= number_format($diff,2) ?></div></div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- السجل -->
<div class="rv-pane" id="pane_history">
    <?php if (empty($history)): ?>
        <div class="card" style="text-align:center;color:var(--ink-500);padding:30px;">مفيش سجل بعد.</div>
    <?php else: ?>
        <?php foreach ($history as $s): $amt = shift_cashier_amounts($s); $isDone = $s['status']==='approved'; ?>
            <div class="shift-card <?= $isDone?'done':'rej' ?>">
                <div class="sc-head">
                    <div class="sc-title"><b><?= e($s['branch_name']) ?></b> — وردية <span class="sc-sub"><?= e($s['business_date']) ?> · <?= e($s['cashier_name']) ?></span></div>
                    <span style="font-size:13px;font-weight:600;color:<?= $isDone?'var(--pos)':'var(--neg)' ?>;"><?= $isDone?'معتمدة':'مرفوضة' ?></span>
                </div>
                <div class="sc-stats">
                    <div class="sc-stat"><div class="l">السيستم</div><div class="v"><?= number_format($amt['system'],2) ?></div></div>
                    <div class="sc-stat"><div class="l">كاش</div><div class="v"><?= number_format($amt['cash'],2) ?></div></div>
                    <div class="sc-stat"><div class="l">فيزا+إنستا</div><div class="v"><?= number_format($amt['visa']+$amt['instapay'],2) ?></div></div>
                    <div class="sc-stat"><div class="l">الفرق</div><div class="v"><?= number_format(($amt['cash']+$amt['visa']+$amt['instapay'])-$amt['system'],2) ?></div></div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- بوب أب المراجعة -->
<div class="rv-modal" id="rvModal">
  <div class="rv-box">
    <div class="rv-mhead">
        <span class="t" id="rvTitle">مراجعة وردية</span>
        <button type="button" class="rv-x" onclick="closeReview()">&times;</button>
    </div>
    <div class="rv-mbody">
        <form method="post" id="rvForm">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="shift_id" id="rv_shift_id" value="">
            <input type="hidden" name="decision" id="rv_decision" value="">
            <input type="hidden" name="acc_cash" id="rv_acc_cash" value="0">
            <input type="hidden" name="acc_visa" id="rv_acc_visa" value="0">
            <input type="hidden" name="acc_instapay" id="rv_acc_instapay" value="0">

            <div class="rv-sys">
                <label for="rv_acc_system">مبيعات السيستم</label>
                <input type="number" step="0.01" min="0" name="acc_system" id="rv_acc_system" value="" placeholder="0.00" oninput="rvCalc()">
            </div>

            <div class="rv-ptabs">
                <button type="button" class="rv-ptab active" onclick="rvPtab(this,'rp_cash')">كاش</button>
                <button type="button" class="rv-ptab" onclick="rvPtab(this,'rp_visa')">فيزا</button>
                <button type="button" class="rv-ptab" onclick="rvPtab(this,'rp_instapay')">إنستا باي</button>
                <button type="button" class="rv-ptab" id="rv_cmp_tab" onclick="rvCompare(this)">قارن</button>
            </div>

            <!-- كاش -->
            <div class="rv-ppane active" id="rp_cash">
                <div class="rv-cash-layout">
                    <div>
                        <?php foreach ($denominations as $d): ?>
                            <div class="denom-row">
                                <span class="sub" id="rvd_<?= str_replace('.','_',(string)$d) ?>">0.00</span>
                                <span>×</span>
                                <input type="number" aria-label="عدد فئة <?= $d ?> جنيه" min="0" class="rv-denom" data-d="<?= $d ?>" value="" placeholder="0" oninput="rvCalc()">
                                <span class="dn"><?= $d ?> ج.م</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="rv-cash-box">
                        <div class="l">إجمالي الكاش</div>
                        <div class="v"><span id="rv_cash_t">0.00</span> ج.م</div>
                    </div>
                </div>
            </div>

            <!-- فيزا / إنستا (سطور) -->
            <?php foreach (['visa'=>'فيزا','instapay'=>'إنستا باي'] as $mk=>$ml): ?>
            <div class="rv-ppane" id="rp_<?= $mk ?>">
                <table class="pay-table" style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th style="width:28px;padding:4px 6px 8px;text-align:right;font-size:12px;color:var(--ink-500);font-weight:600;">#</th>
                            <th style="padding:4px 6px 8px;text-align:right;font-size:12px;color:var(--ink-500);font-weight:600;">رقم السيريال</th>
                            <th style="padding:4px 6px 8px;text-align:right;font-size:12px;color:var(--ink-500);font-weight:600;">المبلغ</th>
                        </tr>
                    </thead>
                    <tbody id="tb_<?= $mk ?>">
                    <?php for ($i=1;$i<=10;$i++): ?>
                        <tr>
                            <td style="padding:4px;color:var(--ink-500);font-size:13px;text-align:center;"><?= $i ?></td>
                            <td style="padding:4px;"><input type="text" placeholder="رقم السيريال" style="width:100%;padding:6px 8px;border:1.5px solid var(--line);border-radius:7px;font-family:inherit;font-size:13px;" aria-label="رقم السيريال"></td>
                            <td style="padding:4px;"><input type="number" aria-label="المبلغ" step="0.01" min="0" class="rv-<?= $mk ?>" value="" placeholder="0.00" oninput="rvCalc()" style="width:100%;padding:6px 8px;border:1.5px solid var(--line);border-radius:7px;font-family:inherit;font-size:13px;text-align:center;"></td>
                        </tr>
                    <?php endfor; ?>
                    </tbody>
                </table>
                <button type="button" onclick="rvAddRow('<?= $mk ?>')" style="margin-top:8px;background:#f0eefc;color:#5b52c9;border:1.5px dashed #c7c0f0;border-radius:8px;padding:7px 14px;font-family:inherit;font-size:13px;cursor:pointer;">+ إضافة صف</button>
                <div class="rv-total">إجمالي <?= $ml ?>: <span id="rv_<?= $mk ?>_t">0.00</span> ج.م</div>
            </div>
            <?php endforeach; ?>

            <!-- مقارنة -->
            <div class="rv-ppane" id="rp_compare">
                <!-- ─────────────────────────────────────────────────────
                     مرحلتين:
                       1. المحاسب يدخل أرقامه — أرقام الموظف مخفية تمامًا
                       2. يدوس «قارن» → الأرقام تظهر + المقارنة + القرار

                     السبب: لو شاف أرقام الموظف وهو بيعدّ، هينسخها بدل
                     ما يعدّ بنفسه، والمراجعة تبقى بلا قيمة.
                     ───────────────────────────────────────────────────── -->
                <div id="cmp_locked" style="background:#f6f5fd;border-radius:10px;
                     padding:22px;text-align:center;color:var(--ink-500);font-size:14px;">
                    <i class="ti ti-lock"></i> أدخل أرقامك في التابات الأولى، وبعدها اضغط «قارن».
                </div>
                <table class="cmp-table" id="cmp_table" style="display:none;">
                    <thead><tr><th>البند</th><th>الموظف</th><th>المحاسب</th><th>الفرق</th></tr></thead>
                    <tbody id="cmp_body"></tbody>
                </table>
                <div id="cmp_msg" style="margin:14px 0;padding:10px 14px;border-radius:9px;text-align:center;font-size:14px;display:none;"></div>
                <div id="cmp_decide" style="display:none;">
                    <label style="font-size:13px;color:var(--ink-500);display:block;margin-bottom:5px;">ملاحظات المحاسب (مطلوب للرفض)</label>
                    <textarea name="reason" id="rv_reason" rows="2" placeholder="اكتب سبب الرجوع هنا..." style="width:100%;padding:9px 12px;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;"></textarea>
                    <div class="rv-actions">
                        <button type="submit" class="btn-approve" onclick="return rvDecide('approved')" style="border:none;border-radius:9px;padding:11px;cursor:pointer;font-family:inherit;font-size:14px;">✓ اعتماد وإنشاء القيود</button>
                        <button type="submit" class="btn-reject" onclick="return rvDecide('rejected')" style="border:none;border-radius:9px;padding:11px;cursor:pointer;font-family:inherit;font-size:14px;">↩ إرجاع للموظف</button>
                    </div>
                </div>
            </div>
        </form>
    </div>
  </div>
</div>

<script>
    var rvEmp = {};       // أرقام الموظف (الكاشير)
    var rvRevealed = false;  // اتكشفت أرقام الموظف؟ (بعد ما يدوس «قارن»)

    // ─────────────────────────────────────────────────────────────────
    //  «قارن» — المرحلة التانية
    //  بيكشف أرقام الموظف، يبني المقارنة، ويظهر أزرار القرار.
    //  قبل كده المحاسب بيدخل أرقامه وهو مش شايف حاجة.
    // ─────────────────────────────────────────────────────────────────
    function rvCompare(btn) {
        var sysEl = document.getElementById('rv_acc_system');
        if (sysEl.value.trim() === '') {
            alert('اكتب قيمة السيستم الأول.');
            sysEl.focus();
            return;
        }

        rvRevealed = true;
        document.getElementById('cmp_locked').style.display  = 'none';
        document.getElementById('cmp_table').style.display   = '';
        document.getElementById('cmp_msg').style.display     = '';
        document.getElementById('cmp_decide').style.display  = '';

        rvCalc();
        rvPtab(btn, 'rp_compare');
    }
    function rvTab(btn, pane) {
        document.querySelectorAll('.rv-tab').forEach(t=>t.classList.remove('active'));
        btn.classList.add('active');
        document.querySelectorAll('.rv-pane').forEach(p=>p.classList.remove('active'));
        document.getElementById(pane).classList.add('active');
    }
    function rvPtab(btn, pane) {
        document.querySelectorAll('.rv-ptab').forEach(t=>t.classList.remove('active'));
        btn.classList.add('active');
        document.querySelectorAll('.rv-ppane').forEach(p=>p.classList.remove('active'));
        document.getElementById(pane).classList.add('active');
    }
    function openReview(data) {
        rvEmp = data;
        document.getElementById('rvForm').reset();
        document.getElementById('rv_shift_id').value = data.id;
        document.getElementById('rvTitle').textContent = 'مراجعة — ' + data.branch + ' — وردية';

        // تصفير الحالة — كل وردية بتبدأ مقفولة
        rvRevealed = false;
        document.getElementById('cmp_locked').style.display  = '';
        document.getElementById('cmp_table').style.display   = 'none';
        document.getElementById('cmp_msg').style.display     = 'none';
        document.getElementById('cmp_decide').style.display  = 'none';

        // تصفير الكاش والسطور
        document.querySelectorAll('.rv-denom').forEach(i=>i.value='');
        rvCalc();
        rvPtab(document.querySelector('.rv-ptab'), 'rp_cash');
        document.getElementById('rvModal').classList.add('open');
        document.body.style.overflow = 'hidden';
    }
    function closeReview() {
        document.getElementById('rvModal').classList.remove('open');
        document.body.style.overflow = '';
    }
    function sumClass(cls) {
        var t = 0;
        document.querySelectorAll('.' + cls).forEach(function(i){ t += parseFloat(i.value)||0; });
        return t;
    }
    function rvCalc() {
        // كاش
        var cash = 0;
        document.querySelectorAll('.rv-denom').forEach(function(i){
            var d = parseFloat(i.dataset.d)||0, c = parseFloat(i.value)||0, sub = d*c;
            cash += sub;
            var el = document.getElementById('rvd_' + String(d).replace('.','_'));
            if (el) el.textContent = sub.toFixed(2);
        });
        var visa = sumClass('rv-visa'), insta = sumClass('rv-instapay');
        document.getElementById('rv_cash_t').textContent = cash.toFixed(2);
        document.getElementById('rv_visa_t').textContent = visa.toFixed(2);
        document.getElementById('rv_instapay_t').textContent = insta.toFixed(2);
        // خزّن في الحقول المخفية
        document.getElementById('rv_acc_cash').value = cash;
        document.getElementById('rv_acc_visa').value = visa;
        document.getElementById('rv_acc_instapay').value = insta;
        // جدول المقارنة
        var sys = parseFloat(document.getElementById('rv_acc_system').value)||0;
        var rows = [
            ['كاش', rvEmp.cash, cash],
            ['فيزا', rvEmp.visa, visa],
            ['إنستا باي', rvEmp.instapay, insta],
            ['السيستم', rvEmp.system, sys],
        ];
        var empTotal = (rvEmp.cash||0)+(rvEmp.visa||0)+(rvEmp.instapay||0);
        var accTotal = cash+visa+insta;
        rows.push(['الإجمالي', empTotal, accTotal]);

        // ★ بند الفرق: (الإجمالي − السيستم) لكل طرف.
        //   ده الرقم اللي بيظهر في التقفيل، وكان ناقص من المقارنة —
        //   فالمحاسب ماكانش شايف فرق الموظف قصاد فرقه.
        var empDiff = empTotal - (rvEmp.system||0);
        var accDiff = accTotal - sys;
        rows.push(['__DIFF__', empDiff, accDiff]);

        var html = '', allMatch = true;
        rows.forEach(function(r){
            var emp = parseFloat(r[1])||0, acc = parseFloat(r[2])||0, df = acc - emp;
            var ok = Math.abs(df) < 0.01;

            // صف الفرق: شكل مختلف — بنوري العجز/الزيادة مش المطابقة
            if (r[0] === '__DIFF__') {
                var cls = function(v){
                    if (Math.abs(v) < 0.01) return 'cmp-ok';
                    return 'cmp-bad';
                };
                html += '<tr style="border-top:2px solid var(--line);font-weight:bold;">'
                      + '<td>الفرق</td>'
                      + '<td class="'+cls(emp)+'">'+emp.toFixed(2)+'</td>'
                      + '<td class="'+cls(acc)+'">'+acc.toFixed(2)+'</td>'
                      + '<td class="'+(ok?'cmp-ok':'cmp-bad')+'">'+(ok?'✓ مطابق':df.toFixed(2))+'</td>'
                      + '</tr>';
                return;
            }

            if (r[0] !== 'السيستم' && !ok) allMatch = false;
            html += '<tr><td>'+r[0]+'</td><td>'+emp.toFixed(2)+'</td><td>'+acc.toFixed(2)+'</td>'+
                    '<td class="'+(ok?'cmp-ok':'cmp-bad')+'">'+(ok?'✓ مطابق':df.toFixed(2))+'</td></tr>';
        });
        // مايكشفش أرقام الموظف قبل ما يدوس «قارن»
        if (!rvRevealed) return;
        document.getElementById('cmp_body').innerHTML = html;
        var msg = document.getElementById('cmp_msg');
        if (allMatch) { msg.textContent = '✓ أرقامك مطابقة تماماً — يمكنك الاعتماد'; msg.style.background='var(--pos-soft)'; msg.style.color='var(--pos)'; }
        else { msg.textContent = '⚠ فيه فروقات بين أرقامك وأرقام الموظف — راجع قبل القرار'; msg.style.background='var(--warn-soft)'; msg.style.color='var(--warn)'; }
    }
    function rvDecide(decision) {
        if (!rvRevealed) { alert('اضغط «قارن» الأول.'); return false; }
        rvCalc();
        document.getElementById('rv_decision').value = decision;

        // قيمة السيستم إجبارية عند الاعتماد بس.
        // (عند الرفض مش إجبارية — ممكن يرفض عشان الأرقام ناقصة أصلًا.)
        if (decision === 'approved') {
            var sysEl = document.getElementById('rv_acc_system');
            if (sysEl.value.trim() === '') {
                alert('اكتب قيمة السيستم قبل الاعتماد.');
                sysEl.focus();
                return false;
            }
        }

        if (decision === 'rejected') {
            var reason = document.getElementById('rv_reason').value.trim();
            if (!reason) { alert('اكتب سبب الرجوع أولاً.'); return false; }
            return confirm('تأكيد إرجاع الوردية للموظف؟');
        }
        return confirm('تأكيد اعتماد الوردية وإنشاء القيود؟ الأرقام هتدخل الخزائن.');
    }

    // إضافة صف جديد لجدول الفيزا/الإنستا.
    // كانت الجداول 10 صفوف ثابتة — لو الوردية فيها 11 عملية، المراجع
    // ما كانش يقدر يضيف الحادية عشرة ولا يقفل الوردية.
    function rvAddRow(mk, focusIt) {
        var tb = document.getElementById('tb_' + mk);
        if (!tb) return null;

        var n  = tb.rows.length + 1;
        var tr = tb.insertRow();

        var c1 = tr.insertCell();
        c1.style.cssText = 'padding:4px;color:var(--ink-500);font-size:13px;text-align:center;';
        c1.textContent = n;

        var c2 = tr.insertCell();
        c2.style.padding = '4px';
        c2.innerHTML = '<input type="text" placeholder="رقم السيريال" '
                     + 'style="width:100%;padding:6px 8px;border:1.5px solid var(--line);'
                     + 'border-radius:7px;font-family:inherit;font-size:13px;" aria-label="رقم السيريال">';

        var c3 = tr.insertCell();
        c3.style.padding = '4px';
        c3.innerHTML = '<input type="number" step="0.01" min="0" class="rv-' + mk + '" '
                     + 'value="" placeholder="0.00" oninput="rvCalc()" '
                     + 'style="width:100%;padding:6px 8px;border:1.5px solid var(--line);'
                     + 'border-radius:7px;font-family:inherit;font-size:13px;text-align:center;" aria-label="0.00">';

        if (focusIt) c2.querySelector('input').focus();
        return tr;
    }

    // السهم لتحت: ينقل للصف اللي بعده، ولو إحنا في آخر صف يضيف صف جديد.
    (function(){
        ['visa','instapay'].forEach(function(mk){
            var tb = document.getElementById('tb_' + mk);
            if (!tb) return;

            tb.addEventListener('keydown', function(e){
                if (e.key !== 'ArrowDown' && e.key !== 'Enter') return;
                if (e.target.tagName !== 'INPUT') return;
                e.preventDefault();
                e.stopPropagation();   // مهم: يمنع listener الفورم من التحرك تاني

                var td  = e.target.closest('td');
                var tr  = e.target.closest('tr');
                var col = td.cellIndex;              // 1 = سيريال، 2 = مبلغ
                var nxt = tr.nextElementSibling;

                // آخر صف؟ ضيف واحد جديد
                if (!nxt) nxt = rvAddRow(mk, false);
                if (!nxt) return;

                var inp = nxt.cells[col] && nxt.cells[col].querySelector('input');
                if (inp) inp.focus();
            });
        });
    })();

    // Enter / الأسهم = انتقال بين الحقول (مش اعتماد المراجعة).
    //
    // ملاحظة: الجداول (فيزا/إنستا) ليها listener خاص فوق بيضيف
    // صف جديد لو إحنا في آخر صف. الـlistener ده بيشتغل الأول (على الـtbody)
    // وبيعمل preventDefault، فمش هيتعارض مع اللي تحت.
    (function(){
        var form = document.getElementById('rvForm');
        if (!form) return;

        form.addEventListener('keydown', function(e){
            if (e.key !== 'Enter' && e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;

            var el = e.target;
            if (el.tagName === 'TEXTAREA') return;
            if (el.tagName !== 'INPUT' && el.tagName !== 'SELECT') return;
            if (e.defaultPrevented) return;   // الجدول اتعامل معاه خلاص

            e.preventDefault();

            var all = Array.prototype.filter.call(
                form.querySelectorAll('input:not([type=hidden]), select, textarea'),
                function(x){
                    return !x.disabled && !x.readOnly && x.offsetParent !== null;
                }
            );

            var i = all.indexOf(el);
            if (i === -1) return;

            var next = (e.key === 'ArrowUp') ? all[i - 1] : all[i + 1];
            if (!next) return;

            next.focus();
            if (next.select) next.select();
        });
    })();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
