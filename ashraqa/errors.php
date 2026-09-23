<?php
/**
 * errors.php — سجل أخطاء الموقع
 * نظام الفجالة ستور المالي
 *
 * بيعمل حاجتين:
 *  ١) ?report=js — بيستقبل أخطاء الجافاسكربت من المتصفح (أي مستخدم داخل).
 *  ٢) الصفحة نفسها — عرض السجل مجمّع (صلاحية `errors`، والأونر دايماً).
 *
 * التخزين والقواعد كلها في errlog_lib.php. الملف على السيرفر:
 *   public_html/logs/errors.log.php   ← بينزل مع الميرورينج
 */
require_once __DIR__ . '/auth.php';

// =====================================================================
//  ١) استقبال أخطاء الجافاسكربت (sendBeacon)
// =====================================================================
//  🔴 قبل require_permission عن قصد — الأخطاء بتحصل عند الكل، مش عند
//     اللي معاه صلاحية السجل بس. الشرط الوحيد إنه داخل.
if (($_GET['report'] ?? '') === 'js') {
    http_response_code(204);
    if (!is_logged_in() || $_SERVER['REQUEST_METHOD'] !== 'POST') exit;

    // حد أقصى ٢٠ تقرير كل ١٠ دقايق للجلسة الواحدة. المتصفح عنده حد ٣ في
    // الصفحة، بس ده الحزام التاني لو صفحة بتعمل refresh في حلقة. (RULES §٢)
    $now = time();
    $rl  = $_SESSION['errlog_js'] ?? [0, 0];
    if ($now - (int) $rl[0] > 600) $rl = [$now, 0];
    if ($rl[1] >= 20) exit;
    $_SESSION['errlog_js'] = [$rl[0], $rl[1] + 1];

    $msg = trim((string) ($_POST['m'] ?? ''));
    $src = (string) ($_POST['s'] ?? '');
    if ($msg === '' || $msg === 'Script error.') exit;   // Script error. = سكربت من دومين تاني، مالوش تفاصيل

    // المصدر لازم يكون من موقعنا — إضافات المتصفح عند المستخدم مش شغلنا
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $sp   = parse_url($src);
    if ($src !== '' && (($sp['host'] ?? '') !== $host)) exit;
    $srcPath = ltrim((string) ($sp['path'] ?? ''), '/');

    // الصفحة اللي حصل فيها — بتيجي من المتصفح مش من REQUEST_URI بتاع الريكوست ده
    $_SERVER['REQUEST_URI'] = '/' . ltrim(errlog_cut((string) ($_POST['p'] ?? ''), 300), '/');

    errlog_record(
        'js',
        $msg,
        $srcPath,
        (int) ($_POST['l'] ?? 0),
        (string) ($_POST['k'] ?? '')
    );
    exit;   // الكتابة بتحصل في errlog_shutdown
}

// =====================================================================
//  ٢) الصفحة
// =====================================================================
require_permission('errors');

$flash = ''; $flashType = 'success';

// المسح للأونر بس — اللي معاه صلاحية القراية مايمسحش الأدلة
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $_SESSION['flash'] = ['انتهت صلاحية الجلسة، حاول تاني.', 'error'];
    } elseif (!is_owner()) {
        $_SESSION['flash'] = ['المسح للأونر بس.', 'error'];
    } else {
        @unlink(ERRLOG_FILE);
        @unlink(ERRLOG_OLD);
        $_SESSION['flash'] = ['السجل اتمسح.', 'success'];
    }
    header('Location: errors.php');
    exit;
}
if (isset($_SESSION['flash']) && is_array($_SESSION['flash'])) {
    [$flash, $flashType] = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

$groups = errlog_read_grouped([ERRLOG_OLD, ERRLOG_FILE]);
$total  = array_sum(array_column($groups, 'count'));
$cntPhp = count(array_filter($groups, fn($g) => $g['type'] === 'php'));
$cntJs  = count(array_filter($groups, fn($g) => $g['type'] === 'js'));
$size   = (is_file(ERRLOG_FILE) ? filesize(ERRLOG_FILE) : 0) + (is_file(ERRLOG_OLD) ? filesize(ERRLOG_OLD) : 0);

$page_title    = 'سجل الأخطاء';
$active_module = 'errors';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .flash { padding:12px 16px; border-radius:10px; margin-bottom:18px; font-size:14px; }
    .flash.success { background:var(--pos-soft); color:var(--pos); }
    .flash.error   { background:var(--neg-soft); color:var(--neg); }
    .card { background:var(--surface); border-radius:14px; padding:18px 20px; box-shadow:var(--shadow-sm); margin-bottom:16px; }
    /* override محلي (بريف ٤٣): الزرار الموحّد في fagala.css، والصفحة دي مختلفة في دول بس */
    .btn { padding: 8px 16px; font-size: 13.5px; }
    .btn-danger { background:var(--neg-fill); color:#fff; }
    .btn-danger:hover { background:var(--neg-fill); color:#fff; opacity:.9; }

    .el-head { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; }
    .el-sub { color:var(--ink-500); font-size:13px; margin-top:4px; }
    .el-stats { display:flex; gap:10px; flex-wrap:wrap; margin-top:12px; }
    .el-stat { background:var(--line-soft); border-radius:10px; padding:8px 14px; font-size:13px; color:var(--ink-600); }
    .el-stat b { color:var(--ink-900); font-size:15px; margin-inline-start:4px; }

    .el-wrap { overflow-x:auto; }
    .el-table { width:100%; border-collapse:collapse; font-size:13px; }
    .el-table th { background:var(--line-soft); color:var(--ink-500); font-weight:600; padding:9px 10px; text-align:right; white-space:nowrap; }
    .el-table td { padding:9px 10px; border-bottom:1px solid var(--line-soft); vertical-align:top; color:var(--ink-700); }
    .el-type { display:inline-block; padding:2px 8px; border-radius:6px; font-size:11.5px; font-weight:700; }
    .el-type.php { background:var(--neg-soft); color:var(--neg); }
    .el-type.js  { background:var(--warn-soft); color:var(--warn); }
    .el-msg { color:var(--ink-900); font-weight:500; word-break:break-word; min-width:260px; }
    .el-loc, .el-url { font-family:monospace; font-size:12px; color:var(--ink-500); word-break:break-all; }
    .el-count { font-weight:700; color:var(--ink-900); text-align:center; }
    .el-msg details summary { cursor:pointer; color:var(--accent-text); font-size:12px; margin-top:4px; }
    .el-msg pre { white-space:pre-wrap; font-size:11.5px; color:var(--ink-500); background:var(--bg); padding:8px; border-radius:8px; margin:6px 0 0; max-height:260px; overflow:auto; }
    .el-empty { text-align:center; color:var(--ink-500); padding:36px; }
</style>

<?php if ($flash): ?>
    <div class="flash <?= e($flashType) ?>"><?= e($flash) ?></div>
<?php endif; ?>

<div class="card">
    <div class="el-head">
        <div>
            <h1 style="font-size:18px;margin:0;color:var(--ink-900);">سجل الأخطاء</h1>
            <div class="el-sub">
                أي خطأ بيحصل عند أي مستخدم — PHP أو جافاسكربت. نفس الغلطة بتتجمّع في سطر واحد.
            </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="errors.php" class="btn"><i class="ti ti-refresh"></i> تحديث</a>
            <?php if (is_owner() && $groups): ?>
                <form method="post" data-confirm="مسح السجل كله؟ مفيش رجوع." data-confirm-danger style="margin:0;">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="clear">
                    <button type="submit" class="btn btn-danger"><i class="ti ti-trash"></i> مسح السجل</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="el-stats">
        <span class="el-stat">غلطات مختلفة <b><?= count($groups) ?></b></span>
        <span class="el-stat">مرات الحدوث <b><?= number_format($total) ?></b></span>
        <span class="el-stat">PHP <b><?= $cntPhp ?></b></span>
        <span class="el-stat">جافاسكربت <b><?= $cntJs ?></b></span>
        <span class="el-stat">حجم الملف <b><?= number_format($size / 1024, 1) ?> KB</b></span>
    </div>
</div>

<div class="card">
    <?php if (!$groups): ?>
        <div class="el-empty">مفيش أخطاء متسجّلة.</div>
    <?php else: ?>
        <div class="el-wrap">
            <table class="el-table">
                <thead>
                    <tr>
                        <th>آخر مرة</th>
                        <th>النوع</th>
                        <th>الغلطة</th>
                        <th>المكان</th>
                        <th>الصفحة</th>
                        <th>المستخدمين</th>
                        <th>مرات</th>
                        <th>أول مرة</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($groups as $g): ?>
                    <tr>
                        <td style="white-space:nowrap;"><?= e($g['last']) ?></td>
                        <td><span class="el-type <?= e($g['type']) ?>"><?= $g['type'] === 'js' ? 'JS' : 'PHP' ?></span></td>
                        <td class="el-msg">
                            <?= e($g['msg']) ?>
                            <?php if ($g['trace'] !== ''): ?>
                                <details><summary>التفاصيل</summary><pre><?= e($g['trace']) ?></pre></details>
                            <?php endif; ?>
                        </td>
                        <td class="el-loc"><?= e($g['file']) ?><?= $g['line'] ? ':' . (int) $g['line'] : '' ?></td>
                        <td class="el-url"><?= e($g['url']) ?></td>
                        <td><?= e(implode('، ', $g['users'])) ?: '—' ?></td>
                        <td class="el-count"><?= number_format($g['count']) ?></td>
                        <td style="white-space:nowrap;color:var(--ink-500);"><?= e($g['first']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
