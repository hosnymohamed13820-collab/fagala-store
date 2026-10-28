<?php
/**
 * tasks.php — المهام (Tasks)
 * نظام الفجالة ستور المالي
 *
 * - أي مستخدم عنده صلاحية tasks يقدر يصدر مهام (لفرع/موظف/كذا موظف).
 * - المستهدف يشوف المهمة، يعلّمها "تمت"، ويكتب رد.
 * - إشعارات بتتولّد للمستهدفين (تظهر في الجرس بالهيدر).
 */
require_once __DIR__ . '/auth.php';
require_permission('tasks');
require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/shortage_lib.php';   // بيانات النواقص + المرفقات + can_view_task
require_once __DIR__ . '/tasks_lib.php';

$pdo = db();
$uid = (int) current_user()['id'];
$flash = ''; $flashType = 'success';
$tab = $_GET['tab'] ?? 'inbox';
$openTaskId = (int) ($_GET['task'] ?? 0);

// بيانات للنموذج
$branches  = $pdo->query('SELECT id, name FROM branches WHERE is_active=1 ORDER BY name')->fetchAll();
$usersList = $pdo->query(
    'SELECT u.id, u.full_name, b.name AS branch_name
     FROM users u
     LEFT JOIN branches b ON b.id = u.branch_id
     WHERE u.is_active=1 ORDER BY u.full_name'
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $flash = 'انتهت صلاحية الجلسة، حاول مرة أخرى.'; $flashType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'mark_done') {
                mark_task_done((int)($_POST['task_id'] ?? 0), $uid);
                $flash = 'تم تعليم المهمة كمنجزة.';
                $openTaskId = (int)($_POST['task_id'] ?? 0);
            }
            elseif ($action === 'add_comment') {
                add_task_comment((int)($_POST['task_id'] ?? 0), $uid, $_POST['body'] ?? '');
                $flash = 'تم إضافة الرد.';
                $openTaskId = (int)($_POST['task_id'] ?? 0);
            }
            elseif ($action === 'upload_attachment') {
                $tid = (int)($_POST['task_id'] ?? 0);
                if (!can_view_task($tid, $uid)) {
                    throw new RuntimeException('مش مصرّح لك ترفع على المهمة دي.');
                }
                if (empty($_FILES['attachment']['name'])) {
                    throw new RuntimeException('اختار ملف الأول.');
                }
                add_task_attachment($tid, $uid, $_FILES['attachment']);
                $flash = 'تم رفع المرفق.';
                $openTaskId = $tid;
            }
        } catch (Throwable $ex) {
            $flash = 'خطأ: ' . $ex->getMessage(); $flashType = 'error';
        }
    }
    // PRG: بعد أي POST نعمل تحويل لصفحة GET عشان الـ Refresh ما يعيدش الإرسال (منع التكرار)
    $_SESSION['flash'] = $flash;
    $_SESSION['flash_type'] = $flashType;
    header('Location: ' . ($openTaskId > 0 ? 'tasks.php?task=' . $openTaskId : ($_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF'])));
    exit;
}

// نقرأ رسالة الفلاش من السيشن (بعد التحويل)
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    $flashType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash'], $_SESSION['flash_type']);
}

// ===== تحميل البيانات =====
// المهام الواردة لي (أنا مستهدف فيها)
// 🔴 المهمة المقفولة **مابتظهرش هنا خالص**. أول ما حد من المستهدفين
//    يعلّمها «تمت»، بتتقفل للكل وتختفي من قايمة كل واحد فيهم.
//    قبل كده ماكانش فيه أي كود بيقفل مهمة، فالقايمة كانت بتتخم بحاجات
//    خلصت من شهور — وموظف اتنقل لفرع تاني كان بيفضل شايف مهام فرعه
//    القديم للأبد.
//    السجل مابيضيعش: اللي عمل المهمة بيفضل شايفها في قايمة «اللي
//    أصدرتها» تحت بحالتها.
$inbox = tasks_inbox_list($uid);
$sent = tasks_sent_list($uid);

// تفاصيل مهمة مفتوحة
$openTask = null; $openTargets = []; $openComments = [];
if ($openTaskId > 0) {
    $detail = tasks_get_detail($openTaskId, $uid);
    if ($detail) {
        $openTask        = $detail['task'];
        $openTargets     = $detail['targets'];
        $openComments    = $detail['comments'];
        $openShortage    = $detail['shortage'];
        $openAttachments = $detail['attachments'];
    }
}

$pendingCount = 0;
foreach ($inbox as $t) if ($t['my_status'] === 'pending') $pendingCount++;

$page_title = 'المهام';
$active_module = 'tasks';
require_once __DIR__ . '/includes/header.php';

$prioBadge = function($p) {
    $map = ['normal'=>['عادي',''], 'important'=>['مهم','fchip-warn'], 'urgent'=>['عاجل','fchip-neg']];
    $b = $map[$p] ?? $map['normal'];
    return '<span class="fchip '.$b[1].'">'.$b[0].'</span>';
};
?>

<style>
    .task-item { display:block; padding:14px 16px; border:1.5px solid var(--line); border-radius:12px; margin-bottom:10px; text-decoration:none; color:inherit; transition:.15s; }
    .task-item:hover { border-color:var(--accent); background:var(--line-soft); }
    .task-item.pending { border-right:4px solid var(--accent); }
    .task-item.done { opacity:.6; }
    .task-title { font-weight:600; color:var(--ink-900); font-size:15px; }
    .task-meta { font-size:12px; color:var(--ink-500); margin-top:5px; }
    .checklist { max-height:260px; overflow-y:auto; border:1.5px solid var(--line); border-radius:10px; padding:10px; }
    .checklist label { display:flex; align-items:center; gap:8px; padding:7px 6px; font-size:14px; cursor:pointer; border-radius:6px; }
    .checklist label:hover { background:var(--line-soft); }
    .comment { padding:10px 14px; background:var(--line-soft); border-radius:10px; margin-bottom:8px; }
    .comment .who { font-size:12px; color:var(--accent-text); font-weight:600; margin-bottom:3px; }
    .empty { text-align:center; color:var(--ink-400); padding:30px; }
</style>

<?php if ($flash): ?><div class="falert <?= $flashType === 'success' ? 'falert-pos' : 'falert-neg' ?>"><?= e($flash) ?></div><?php endif; ?>

<?php if ($openTask): ?>
    <!-- ===== تفاصيل مهمة ===== -->
    <a href="tasks.php" style="color:var(--accent-text);text-decoration:none;font-size:14px;">→ رجوع للمهام</a>
    <div class="fcard" style="margin-top:14px;">
        <div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:10px;">
            <div>
                <h2 style="color:var(--ink-900);margin-bottom:8px;"><?= e($openTask['title']) ?></h2>
                <div style="font-size:13px;color:var(--ink-500);">
                    <?= $prioBadge($openTask['priority']) ?>
                    <?php if ($openTask['due_date']): ?> · موعد نهائي: <?= e($openTask['due_date']) ?><?php endif; ?>
                    · من: <?= e($openTask['creator'] ?? '—') ?>
                </div>
            </div>
            <?php if ($openTask['my_target_status'] === 'pending'): ?>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="mark_done">
                    <input type="hidden" name="task_id" value="<?= (int)$openTask['id'] ?>">
                    <button class="btn btn-success"><i class="ti ti-check"></i> علّمها تمت</button>
                </form>
            <?php elseif ($openTask['my_target_status'] === 'done'): ?>
                <span class="fchip fchip-pos">✓ أنجزتها</span>
            <?php endif; ?>
        </div>
        <?php if ($openTask['details']): ?>
            <p style="margin-top:16px;color:var(--ink-700);line-height:1.8;white-space:pre-wrap;"><?= e($openTask['details']) ?></p>
        <?php endif; ?>

        <!-- ===== بيانات طلب النواقص (لو المهمة من الموديول) ===== -->
        <?php if (!empty($openShortage)): $shr = $openShortage['request']; ?>
            <div class="falert falert-pos" style="margin-top:18px;">
                <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:13.5px;color:var(--ink-700);margin-bottom:12px;">
                    <span><i class="ti ti-shopping-cart"></i> <b>الفرع الطالب:</b> <?= e($shr['from_name']) ?></span>
                    <span><b>المطلوب منه:</b> <?= e(($shr['source'] ?? 'branch') === 'purchase' ? 'المشتريات' : ($shr['to_name'] ?? '—')) ?></span>
                    <span><b>الطالب:</b> <?= e($shr['requester'] ?? '—') ?></span>
                </div>
                <div class="ftable-wrap">
                    <table class="ftable">
                        <thead><tr>
                            <th class="num">#</th>
                            <th class="num">الكود</th>
                            <th>الصنف</th>
                            <th class="num">الكمية المطلوبة</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($openShortage['lines'] as $i => $ln): ?>
                            <tr>
                                <td class="num"><?= $i+1 ?></td>
                                <td class="num"><?= e($ln['code']) ?></td>
                                <td class="name"><?= e($ln['item_name'] ?: '—') ?></td>
                                <td class="num" style="font-weight:700;color:var(--pos);"><?= rtrim(rtrim(number_format((float)$ln['qty'], 3, '.', ''), '0'), '.') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!empty($shr['notes'])): ?>
                    <div style="margin-top:10px;font-size:13px;color:var(--ink-700);"><b>ملاحظات:</b> <?= e($shr['notes']) ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- حالة المستهدفين (للمنشئ) -->
        <?php if ((int)$openTask['created_by'] === $uid || is_owner()): ?>
            <div style="margin-top:18px;padding-top:16px;border-top:1px solid var(--line);">
                <div style="font-size:13px;color:var(--ink-500);margin-bottom:8px;">حالة المستهدفين:</div>
                <?php foreach ($openTargets as $tg): ?>
                    <span class="fchip <?= $tg['status'] === 'done' ? 'fchip-pos' : '' ?>"><?= e($tg['full_name']) ?> <?= $tg['status']==='done'?'✓':'<i class="ti ti-clock"></i>' ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- المرفقات -->
    <div class="fcard">
        <div style="font-weight:600;color:var(--ink-900);margin-bottom:14px;"><i class="ti ti-paperclip"></i> المرفقات (إثبات التنفيذ)</div>
        <?php if (empty($openAttachments)): ?>
            <div class="empty" style="padding:12px;">مفيش مرفقات بعد.</div>
        <?php else: foreach ($openAttachments as $a): ?>
            <a href="task_file.php?id=<?= (int)$a['id'] ?>" target="_blank"
               style="display:flex;align-items:center;gap:10px;padding:10px 14px;border:1.5px solid var(--line);border-radius:10px;margin-bottom:8px;text-decoration:none;color:inherit;">
                <i class="ti <?= strpos((string)$a['mime'], 'pdf') !== false ? 'ti-file-type-pdf' : 'ti-photo' ?>"
                   style="font-size:22px;color:var(--accent-text);"></i>
                <span style="flex:1;min-width:0;">
                    <span style="display:block;font-size:14px;font-weight:500;color:var(--ink-900);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($a['orig_name']) ?></span>
                    <span style="font-size:12px;color:var(--ink-500);">
                        <?= e($a['full_name'] ?? 'مستخدم') ?> · <?= e(date('Y-m-d H:i', strtotime($a['created_at']))) ?>
                        · <?= number_format($a['size_bytes'] / 1024, 0) ?> ك.ب
                    </span>
                </span>
                <i class="ti ti-external-link" style="color:var(--ink-500);"></i>
            </a>
        <?php endforeach; endif; ?>
        <form method="post" enctype="multipart/form-data" style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="upload_attachment">
            <input type="hidden" name="task_id" value="<?= (int)$openTask['id'] ?>">
            <input type="file" class="finput" name="attachment" accept="image/jpeg,image/png,image/webp,application/pdf" required
                   style="flex:1;min-width:220px;font-size:13.5px;">
            <button class="btn btn-sm"><i class="ti ti-upload"></i> رفع</button>
        </form>
        <div style="font-size:12px;color:var(--ink-500);margin-top:6px;">صور (JPG/PNG/WebP) أو PDF — حد أقصى ٥ ميجا.</div>
    </div>

    <!-- الردود -->
    <div class="fcard">
        <div style="font-weight:600;color:var(--ink-900);margin-bottom:14px;">الردود والتعليقات</div>
        <?php if (empty($openComments)): ?>
            <div class="empty" style="padding:16px;">مفيش ردود بعد.</div>
        <?php else: foreach ($openComments as $c): ?>
            <div class="comment">
                <div class="who"><?= e($c['full_name'] ?? 'مستخدم') ?> · <?= e(date('Y-m-d H:i', strtotime($c['created_at']))) ?></div>
                <div style="color:var(--ink-700);white-space:pre-wrap;"><?= e($c['body']) ?></div>
            </div>
        <?php endforeach; endif; ?>
        <form method="post" style="margin-top:14px;">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_comment">
            <input type="hidden" name="task_id" value="<?= (int)$openTask['id'] ?>">
            <div class="ffield fld"><textarea class="ftextarea" name="body" placeholder="اكتب رد..." required></textarea></div>
            <button class="btn btn-sm">إرسال الرد</button>
        </form>
    </div>

<?php else: ?>
    <!-- ===== القائمة ===== -->
    <nav class="ftabs" aria-label="المهام">
        <a href="?tab=inbox" class="ftab <?= $tab==='inbox'?'is-active':'' ?>">الوارد <?= $pendingCount>0?'('.$pendingCount.')':'' ?></a>
        <a href="?tab=sent" class="ftab <?= $tab==='sent'?'is-active':'' ?>">اللي أصدرتها</a>
        <?php if (can('tasks_issue')): ?>
            <a href="tasks_issue.php" class="ftab">+ إصدار مهمة</a>
        <?php endif; ?>
    </nav>

    <?php if ($tab === 'inbox'): ?>
        <div class="fcard">
            <?php if (empty($inbox)): ?>
                <div class="empty">مفيش مهام واردة لك.</div>
            <?php else: foreach ($inbox as $t): ?>
                <a href="?task=<?= (int)$t['id'] ?>" class="task-item <?= $t['my_status'] ?>">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;">
                        <span class="task-title"><?= e($t['title']) ?></span>
                        <?= $prioBadge($t['priority']) ?>
                    </div>
                    <div class="task-meta">
                        من: <?= e($t['creator'] ?? '—') ?>
                        <?php if ($t['due_date']): ?> · موعد: <?= e($t['due_date']) ?><?php endif; ?>
                        · <?= $t['my_status']==='done' ? '<span style="color:var(--pos);">✓ منجزة</span>' : '<span style="color:var(--accent-text);"><i class="ti ti-clock"></i> مطلوبة</span>' ?>
                    </div>
                </a>
            <?php endforeach; endif; ?>
        </div>

    <?php elseif ($tab === 'sent'): ?>
        <div class="fcard">
            <?php if (empty($sent)): ?>
                <div class="empty">لسه مصدرتش مهام.</div>
            <?php else: foreach ($sent as $t): ?>
                <a href="?task=<?= (int)$t['id'] ?>" class="task-item">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;">
                        <span class="task-title"><?= e($t['title']) ?></span>
                        <?= $prioBadge($t['priority']) ?>
                    </div>
                    <div class="task-meta">
                        أنجزها <?= (int)$t['done'] ?> من <?= (int)$t['total'] ?>
                        <?php if ($t['due_date']): ?> · موعد: <?= e($t['due_date']) ?><?php endif; ?>
                    </div>
                </a>
            <?php endforeach; endif; ?>
        </div>

    <?php endif; ?>
<?php endif; ?>

<script>
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
