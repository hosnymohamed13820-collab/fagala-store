<?php
/**
 * rabt_guard.php — لوحة تحكم حارس الربط
 * =====================================================================
 *  نظام الفجالة ستور المالي
 *  بريف ٩٤ — أكتوبر ٢٠٢٦
 * =====================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_login();

if (!is_owner()) {
    http_response_code(403);
    exit('غير مصرح — الصفحة دي للأونر بس.');
}

$pdo       = db();
$flash     = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $flash = 'انتهت صلاحية الجلسة، حاول مرة أخرى.';
        $flashType = 'error';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'close') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $st = $pdo->prepare(
                    "UPDATE rabt_incidents
                        SET status = 'closed', closed_at = NOW(), close_reason = 'قفلها الأونر'
                      WHERE id = ? AND status <> 'closed'"
                );
                $st->execute([$id]);
                if ($st->rowCount() > 0) {
                    $flash = 'اتقفلت الحادثة بنجاح.';
                }
            }
        }
    }
}

// ٣ استعلامات بالظبط:
// ١) الحوادث المفتوحة
$openIncidents = $pdo->query(
    "SELECT * FROM rabt_incidents
      WHERE status <> 'closed'
      ORDER BY last_seen DESC, id DESC"
)->fetchAll(PDO::FETCH_ASSOC);

// ٢) أوامر إعادة التشغيل (آخر ٥٠)
$commands = $pdo->query(
    "SELECT * FROM agent_commands
      ORDER BY id DESC
      LIMIT 50"
)->fetchAll(PDO::FETCH_ASSOC);

// ٣) الحوادث المقفولة (آخر ٥٠)
$closedIncidents = $pdo->query(
    "SELECT * FROM rabt_incidents
      WHERE status = 'closed'
      ORDER BY closed_at DESC, id DESC
      LIMIT 50"
)->fetchAll(PDO::FETCH_ASSOC);

$page_title    = 'حارس الربط';
$active_module = 'settings';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .rg-sec-title { font-size:16px; font-weight:700; color:var(--ink-900); margin-bottom:12px; display:flex; align-items:center; gap:8px; }
    .rg-card { background:var(--surface); border:1px solid var(--line); border-radius:12px; padding:16px; margin-bottom:16px; box-shadow:0 1px 3px rgba(0,0,0,0.03); }
    .rg-badge { display:inline-block; padding:3px 8px; border-radius:6px; font-size:12px; font-weight:600; }
    .rg-badge-new { background:var(--neg-soft); color:var(--neg); }
    .rg-badge-claimed { background:var(--warn-soft); color:var(--warn); }
    .rg-badge-analyzed { background:var(--accent-soft); color:var(--accent); }
    .rg-badge-closed { background:var(--line-soft); color:var(--ink-500); }
    .rg-badge-done { background:var(--pos-soft); color:var(--pos); }
    .rg-badge-failed { background:var(--neg-soft); color:var(--neg); }
    .rg-badge-pending { background:var(--warn-soft); color:var(--warn); }
    .rg-badge-picked { background:var(--accent-soft); color:var(--accent); }
    .rg-badge-expired { background:var(--line-soft); color:var(--ink-400); }
    .rg-table { width:100%; border-collapse:collapse; font-size:13px; }
    .rg-table th, .rg-table td { padding:10px 12px; text-align:right; border-bottom:1px solid var(--line); }
    .rg-table th { background:var(--line-soft); color:var(--ink-700); font-weight:600; }
    .rg-table tr:hover td { background:rgba(0,0,0,0.01); }
</style>

<div class="content-wrap">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
        <h2 style="font-size:20px; font-weight:700; color:var(--ink-900); margin:0;">
            <i class="ti ti-alert-triangle"></i> لوحة تحكم حارس الربط
        </h2>
        <a href="settings.php#agents" class="btn" style="text-decoration:none;">
            <i class="ti ti-arrow-right"></i> رجوع للإعدادات
        </a>
    </div>

    <?php if ($flash): ?>
        <div class="flash <?= e($flashType) ?>" style="padding:12px 16px; border-radius:8px; margin-bottom:18px; background:<?= $flashType === 'success' ? 'var(--pos-soft)' : 'var(--neg-soft)' ?>; color:<?= $flashType === 'success' ? 'var(--pos)' : 'var(--neg)' ?>;">
            <?= e($flash) ?>
        </div>
    <?php endif; ?>

    <!-- ١) الحوادث المفتوحة -->
    <div class="rg-card">
        <div class="rg-sec-title">
            <i class="ti ti-alert-circle" style="color:var(--neg);"></i>
            <span>الحوادث المفتوحة (<?= count($openIncidents) ?>)</span>
        </div>

        <?php if (empty($openIncidents)): ?>
            <p style="color:var(--pos); margin:0; font-size:14px;">
                <i class="ti ti-check"></i> كل فحوصات الربط سليمة، ولا توجد أي حوادث مفتوحة حالياً.
            </p>
        <?php else: ?>
            <div style="display:flex; flex-direction:column; gap:14px;">
                <?php foreach ($openIncidents as $inc):
                    $incId = (int) $inc['id'];
                    $stBadge = 'rg-badge-new';
                    if ($inc['status'] === 'claimed')  $stBadge = 'rg-badge-claimed';
                    if ($inc['status'] === 'analyzed') $stBadge = 'rg-badge-analyzed';
                ?>
                    <div id="i<?= $incId ?>" style="border:1px solid var(--line); border-radius:10px; padding:14px; background:var(--surface);">
                        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px; flex-wrap:wrap;">
                            <div>
                                <span class="rg-badge <?= $stBadge ?>"><?= e($inc['status']) ?></span>
                                <b style="font-size:14px; color:var(--ink-900); margin:0 6px;">[<?= e($inc['check_code']) ?>]</b>
                                <?php if (!empty($inc['branch_key'])): ?>
                                    <span style="font-size:13px; color:var(--ink-700); background:var(--line-soft); padding:2px 6px; border-radius:4px;">فرع: <?= e($inc['branch_key']) ?></span>
                                <?php endif; ?>
                                <h4 style="margin:8px 0 4px 0; font-size:15px; color:var(--ink-900);"><?= e($inc['title']) ?></h4>
                                <div style="font-size:12px; color:var(--ink-500); display:flex; gap:14px; flex-wrap:wrap; margin-top:6px;">
                                    <span>أول ظهور: <?= e($inc['first_seen']) ?></span>
                                    <span>آخر ظهور: <?= e($inc['last_seen']) ?></span>
                                    <span>تكررت: <?= (int)$inc['seen_count'] ?> مرة</span>
                                </div>
                            </div>
                            <form method="post" style="margin:0;" onsubmit="return confirm('متأكد تقفل الحادثة دي؟');">
                                <input type="hidden" name="action" value="close">
                                <input type="hidden" name="id" value="<?= $incId ?>">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <button type="submit" class="btn btn-sm" style="background:var(--line-soft); color:var(--ink-700);">
                                    <i class="ti ti-check"></i> اقفلها
                                </button>
                            </form>
                        </div>

                        <?php if (!empty($inc['analysis'])): ?>
                            <div style="margin-top:12px; padding-top:10px; border-top:1px dashed var(--line);">
                                <b style="font-size:13px; color:var(--accent);"><i class="ti ti-eye"></i> تحليل كلود:</b>
                                <pre style="white-space:pre-wrap; background:var(--line-soft); padding:10px 14px; border-radius:8px; font-family:inherit; font-size:13px; margin:6px 0 0 0; color:var(--ink-900);"><?= htmlspecialchars((string) $inc['analysis']) ?></pre>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($inc['details'])): ?>
                            <details style="margin-top:8px;">
                                <summary style="cursor:pointer; font-size:12px; color:var(--ink-500);">تفاصيل البيانات (JSON)</summary>
                                <pre style="white-space:pre-wrap; font-size:11px; max-height:160px; overflow:auto; background:var(--line-soft); padding:8px; border-radius:6px; margin-top:4px;"><?= htmlspecialchars((string) $inc['details']) ?></pre>
                            </details>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ٢) أوامر إعادة التشغيل -->
    <div class="rg-card">
        <div class="rg-sec-title">
            <i class="ti ti-refresh" style="color:var(--accent);"></i>
            <span>أوامر إعادة التشغيل (آخر ٥٠ أمر)</span>
        </div>
        <?php if (empty($commands)): ?>
            <p style="color:var(--ink-500); margin:0; font-size:13px;">لا توجد أي أوامر مسجلة حتى الآن.</p>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="rg-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الوكيل</th>
                            <th>الأمر</th>
                            <th>السبب</th>
                            <th>الحالة</th>
                            <th>وقت الطلب</th>
                            <th>الالتقاط</th>
                            <th>الانتهاء</th>
                            <th>النتيجة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($commands as $cmd):
                            $cStatus = (string) $cmd['status'];
                            $bClass = 'rg-badge-pending';
                            if ($cStatus === 'picked')  $bClass = 'rg-badge-picked';
                            if ($cStatus === 'done')    $bClass = 'rg-badge-done';
                            if ($cStatus === 'failed')  $bClass = 'rg-badge-failed';
                            if ($cStatus === 'expired') $bClass = 'rg-badge-expired';
                        ?>
                            <tr>
                                <td><?= (int) $cmd['id'] ?></td>
                                <td><b><?= e($cmd['agent_key']) ?></b></td>
                                <td><code><?= e($cmd['cmd']) ?></code></td>
                                <td style="max-width:220px;"><?= e($cmd['reason'] ?? '—') ?></td>
                                <td><span class="rg-badge <?= $bClass ?>"><?= e($cStatus) ?></span></td>
                                <td><?= e($cmd['created_at']) ?></td>
                                <td><?= e($cmd['picked_at'] ?? '—') ?></td>
                                <td><?= e($cmd['done_at'] ?? '—') ?></td>
                                <td style="max-width:200px;"><?= e($cmd['result'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- ٣) حوادث أُغلقت -->
    <div class="rg-card">
        <div class="rg-sec-title">
            <i class="ti ti-circle-check" style="color:var(--pos);"></i>
            <span>حوادث أُغلقت (آخر ٥٠ حادثة)</span>
        </div>
        <?php if (empty($closedIncidents)): ?>
            <p style="color:var(--ink-500); margin:0; font-size:13px;">لا توجد حوادث مغلقة حتى الآن.</p>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="rg-table">
                    <thead>
                        <tr>
                            <th>الكود</th>
                            <th>الفرع</th>
                            <th>العنوان</th>
                            <th>التكرار</th>
                            <th>أول ظهور</th>
                            <th>آخر ظهور</th>
                            <th>تاريخ الإغلاق</th>
                            <th>سبب الإغلاق</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($closedIncidents as $cl): ?>
                            <tr>
                                <td><b><?= e($cl['check_code']) ?></b></td>
                                <td><?= e($cl['branch_key'] ?? 'hq') ?></td>
                                <td><?= e($cl['title']) ?></td>
                                <td><?= (int) $cl['seen_count'] ?></td>
                                <td><?= e($cl['first_seen']) ?></td>
                                <td><?= e($cl['last_seen']) ?></td>
                                <td><?= e($cl['closed_at'] ?? '—') ?></td>
                                <td><?= e($cl['close_reason'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
