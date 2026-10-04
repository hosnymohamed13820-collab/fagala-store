<?php
/**
 * daily_summary.php — الملخص اليومي للإدارة (بريف ١١١)
 * نظام الفجالة ستور المالي
 *
 * الصفحة اللي إشعار الملخص بيفتحها. بيفتحها الأونر أو اللي محمد حطّه في
 * قايمة المستلمين (settings.php ← كارت «الملخص اليومي»).
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/daily_summary_lib.php';

$dsUsers = array_map('intval', explode(',', (string) setting('daily_summary_users', '')));
// الأونر · صلاحية «التقارير ← الملخص اليومي» · أو اللي في قايمة المستلمين
if (!can('reports:daily') && !in_array((int) current_user()['id'], $dsUsers, true)) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;text-align:center;padding:60px;direction:rtl">'
        . '<h2>غير مصرّح</h2>'
        . '<p>ليس لديك صلاحية للوصول إلى هذه الصفحة.</p>'
        . '<a href="logout.php">تسجيل الخروج</a></div>');
}

// الافتراضي: النهاردة لو عدّينا ساعة الإرسال، وإلا إمبارح (اليوم لسه مكمّلش)
$date = trim((string) ($_GET['date'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
    $date = (int) date('G') >= (int) setting('daily_summary_hour', '23')
        ? date('Y-m-d')
        : date('Y-m-d', strtotime('-1 day'));
}
$today   = date('Y-m-d');
$prevDay = date('Y-m-d', strtotime($date . ' -1 day'));
$nextDay = date('Y-m-d', strtotime($date . ' +1 day'));

$s   = ds_build($date);
$dts = strtotime($date);
$cal = ds_month($date);                 // نتيجة شهر اليوم المختار
$calOpen   = isset($_GET['cal']);
$prevMonth = date('Y-m-d', strtotime(date('Y-m-01', $dts) . ' -1 month'));
$nextMonth = date('Y-m-d', strtotime(date('Y-m-01', $dts) . ' +1 month'));
const DS_MONTHS = ['', 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];

function ds_chip(?float $p): string
{
    if ($p === null) {
        return '<span class="fchip fchip-mute">—</span>';
    }
    $cls = $p > 0.5 ? 'pos' : ($p < -0.5 ? 'neg' : 'mute');
    return '<span class="fchip fchip-' . $cls . '">' . e(ds_pct($p)) . '</span>';
}

$page_title    = 'الملخص اليومي';
$active_module = 'reports';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .ds-nav { display:flex; align-items:center; gap:10px; margin-bottom:16px; flex-wrap:wrap; }
    .ds-nav .ds-day { font-size:17px; font-weight:700; color:var(--ink-900); min-width:170px; text-align:center;
        background:none; border:1.5px solid transparent; border-radius:10px; padding:6px 12px; cursor:pointer; font-family:inherit;
        display:inline-flex; align-items:center; gap:8px; justify-content:center; }
    .ds-nav .ds-day:hover { border-color:var(--line); }
    .ds-nav .ds-day .ti { font-size:16px; color:var(--ink-500); }
    .ds-cal { max-width:620px; margin-bottom:16px; padding:14px; }
    .ds-cal-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; font-size:15px; color:var(--ink-900); }
    .ds-cal-grid { display:grid; grid-template-columns:repeat(7, 1fr); gap:5px; }
    .ds-wd { font-size:11px; color:var(--ink-500); text-align:center; padding:2px 0 4px; }
    .ds-cd { display:flex; flex-direction:column; align-items:center; gap:1px; padding:6px 2px; border-radius:9px;
        text-decoration:none; border:1.5px solid transparent; min-width:0; font-variant-numeric:tabular-nums; }
    .ds-cd-n { font-size:13px; font-weight:700; color:var(--ink-900); }
    .ds-cd-p { font-size:12px; font-weight:700; direction:ltr; unicode-bidi:isolate; }
    .ds-cd-v { font-size:10px; color:var(--ink-500); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:100%; }
    .ds-cd-pos { background:var(--pos-soft); } .ds-cd-pos .ds-cd-p { color:var(--pos); }
    .ds-cd-neg { background:var(--neg-soft); } .ds-cd-neg .ds-cd-p { color:var(--neg); }
    .ds-cd-mute { background:var(--line-soft); } .ds-cd-mute .ds-cd-p { color:var(--ink-500); }
    .ds-cd:hover { border-color:var(--line); }
    .ds-cd.is-cur { border-color:var(--accent-text); }
    .ds-cd-future { opacity:.35; }
    .ds-cal-note { font-size:12px; color:var(--ink-500); margin-top:10px; }
    @media (max-width:520px) { .ds-cd-v { display:none; } }
    .ds-nav .ds-next-ico { display:inline-block; transform:rotate(180deg); }
    .ds-sub { font-size:12px; color:var(--ink-500); margin:-8px 0 14px; }
    .ds-total td { font-weight:700; border-top:2px solid var(--line); }
    .ds-sec { font-size:15px; margin:22px 0 10px; color:var(--ink-900); }
</style>

<div class="ds-nav">
    <a class="btn btn-ghost btn-sm" href="daily_summary.php?date=<?= e($prevDay) ?>" title="اليوم اللي قبله">
        <i class="ti ti-chevron-left ds-next-ico"></i>
    </a>
    <button type="button" class="ds-day" id="dsDayBtn" title="اضغط عشان تشوف أيام الشهر">
        <?= e(DS_DAY_NAMES[(int) date('w', $dts)]) ?> <?= e(date('j/n/Y', $dts)) ?>
        <i class="ti ti-calendar"></i>
    </button>
    <?php if ($nextDay <= $today): ?>
    <a class="btn btn-ghost btn-sm" href="daily_summary.php?date=<?= e($nextDay) ?>" title="اليوم اللي بعده">
        <i class="ti ti-chevron-left"></i>
    </a>
    <?php endif; ?>
    <?php if ($date !== $today): ?>
    <a class="btn btn-ghost btn-sm" href="daily_summary.php?date=<?= e($today) ?>">النهاردة</a>
    <?php endif; ?>
</div>
<div class="ds-cal card" id="dsCal"<?= $calOpen ? '' : ' hidden' ?>>
    <div class="ds-cal-head">
        <a class="btn btn-ghost btn-sm" href="daily_summary.php?date=<?= e($prevMonth) ?>&cal=1" title="الشهر اللي قبله"><i class="ti ti-chevron-left ds-next-ico"></i></a>
        <strong><?= e(DS_MONTHS[(int) date('n', $dts)]) ?> <?= e(date('Y', $dts)) ?></strong>
        <?php if ($nextMonth <= $today): ?>
        <a class="btn btn-ghost btn-sm" href="daily_summary.php?date=<?= e($nextMonth) ?>&cal=1" title="الشهر اللي بعده"><i class="ti ti-chevron-left"></i></a>
        <?php else: ?><span style="width:34px;"></span><?php endif; ?>
    </div>
    <div class="ds-cal-grid">
        <?php foreach (['السبت', 'الأحد', 'الاتنين', 'التلات', 'الأربع', 'الخميس', 'الجمعة'] as $wd): ?>
            <div class="ds-wd"><?= e($wd) ?></div>
        <?php endforeach; ?>
        <?php
        // الأسبوع بيبدأ السبت: date('w') للسبت = 6
        $lead = ((int) date('w', strtotime(date('Y-m-01', $dts))) + 1) % 7;
        for ($i = 0; $i < $lead; $i++) echo '<div></div>';
        foreach ($cal as $d => $c):
            $future = $d > $today;
            $cls = 'mute';
            if (!$future && $c['change'] !== null) {
                $cls = $c['change'] > 0.5 ? 'pos' : ($c['change'] < -0.5 ? 'neg' : 'mute');
            }
        ?>
            <?php if ($future): ?>
                <div class="ds-cd ds-cd-future"><span class="ds-cd-n"><?= (int) date('j', strtotime($d)) ?></span></div>
            <?php else: ?>
                <a class="ds-cd ds-cd-<?= $cls ?><?= $d === $date ? ' is-cur' : '' ?>" href="daily_summary.php?date=<?= e($d) ?>"
                   title="<?= e(ds_money($c['net'])) ?> ج — الأسبوع اللي فات <?= e(ds_money($c['prev'])) ?>">
                    <span class="ds-cd-n"><?= (int) date('j', strtotime($d)) ?></span>
                    <span class="ds-cd-p"><?= $c['change'] !== null ? e(ds_pct($c['change'])) : '—' ?></span>
                    <span class="ds-cd-v"><?= e(ds_money($c['net'])) ?></span>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <div class="ds-cal-note">كل يوم مقارنة بنفس اليوم الأسبوع اللي قبله: أخضر = طلع · أحمر = نزل</div>
</div>
<script>
document.getElementById('dsDayBtn').addEventListener('click', function () {
    var c = document.getElementById('dsCal');
    c.hidden = !c.hidden;
});
</script>
<div class="ds-sub">
    مقارنة بنفس اليوم الأسبوع اللي فات (<?= e(DS_DAY_NAMES[(int) date('w', strtotime($s['prev_date']))]) ?> <?= e(date('j/n', strtotime($s['prev_date']))) ?>)
    · صافي المبيعات بعد المرتجع
</div>

<div class="ftable-wrap">
    <table class="ftable">
        <thead>
            <tr>
                <th>الفرع</th>
                <th class="num">مبيعات اليوم</th>
                <th class="num">الأسبوع اللي فات</th>
                <th class="num">التغيير</th>
                <th class="num">الفواتير</th>
                <th class="num">خلص النهاردة</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($s['branches'] as $b): ?>
            <tr>
                <td class="name">
                    <?= e($b['name']) ?>
                    <?php if ($b['stale']): ?>
                        <span class="fchip fchip-warn">داتا ناقصة — آخر داتا <?= $b['last_beat'] ? e(date('j/n H:i', strtotime($b['last_beat']))) : 'مفيش' ?></span>
                    <?php endif; ?>
                </td>
                <td class="num"><?= e(ds_money($b['net'])) ?></td>
                <td class="num dim"><?= e(ds_money($b['prev_net'])) ?></td>
                <td class="num"><?= ds_chip($b['change']) ?></td>
                <td class="num"><?= (int) $b['count'] ?> <span class="dim">/ <?= (int) $b['prev_count'] ?></span></td>
                <td class="num"><?= $b['ran_out'] > 0 ? '<span class="fchip fchip-neg">' . (int) $b['ran_out'] . '</span>' : '0' ?></td>
            </tr>
        <?php endforeach; ?>
            <tr class="ds-total">
                <td>الإجمالي</td>
                <td class="num"><?= e(ds_money($s['total']['net'])) ?></td>
                <td class="num"><?= e(ds_money($s['total']['prev_net'])) ?></td>
                <td class="num"><?= ds_chip($s['total']['change']) ?></td>
                <td class="num"><?= (int) $s['total']['count'] ?> <span class="dim">/ <?= (int) $s['total']['prev_count'] ?></span></td>
                <td class="num"><?= (int) $s['total']['ran_out'] ?></td>
            </tr>
        </tbody>
    </table>
</div>

<h3 class="ds-sec">الأكتر بيعاً (كل الفروع)</h3>
<?php if (empty($s['top'])): ?>
    <div class="dim">مفيش مبيعات في اليوم ده.</div>
<?php else: ?>
<div class="ftable-wrap">
    <table class="ftable">
        <thead>
            <tr>
                <th class="num">#</th>
                <th>الصنف</th>
                <th>الباركود</th>
                <th class="num">الكمية</th>
                <th class="num">القيمة</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($s['top'] as $i => $t): ?>
            <tr>
                <td class="num"><?= $i + 1 ?></td>
                <td class="name"><?= e($t['name']) ?></td>
                <td class="dim"><?= e($t['barcode']) ?></td>
                <td class="num"><?= e(rtrim(rtrim(number_format($t['qty'], 2, '.', ','), '0'), '.')) ?></td>
                <td class="num"><?= e(ds_money($t['revenue'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
