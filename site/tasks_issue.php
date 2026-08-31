<?php
/**
 * tasks_issue.php — إصدار المهام (صلاحية منفصلة)
 * نظام الفجالة ستور المالي
 *
 * بس اللي عنده صلاحية tasks_issue يقدر يفتح الصفحة دي ويصدر مهام للموظفين.
 */
require_once __DIR__ . '/auth.php';
require_permission('tasks_issue');
require_once __DIR__ . '/finance.php';

$pdo = db();
$uid = (int) current_user()['id'];
$flash = ''; $flashType = 'success';

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
        try {
            $scope = $_POST['scope'] ?? 'users';
            $targets = resolve_task_targets($scope, $_POST['branch_ids'] ?? [], $_POST['user_ids'] ?? []);
            if (empty($targets)) throw new RuntimeException('مفيش مستهدفين للاختيار ده.');
            create_task(
                $_POST['title'] ?? '',
                $_POST['details'] ?? '',
                $_POST['priority'] ?? 'normal',
                $_POST['due_date'] ?? '',
                $targets,
                $uid
            );
            $flash = 'تم إصدار المهمة وإرسال إشعار لـ ' . count($targets) . ' مستخدم.';
        } catch (Throwable $ex) {
            $flash = 'خطأ: ' . $ex->getMessage(); $flashType = 'error';
        }
    }
}

$page_title = 'إصدار المهام';
$active_module = 'tasks_issue';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .flash { padding:12px 16px; border-radius:10px; margin-bottom:18px; font-size:14px; }
    .flash.success { background:var(--pos-soft); color:var(--pos); }
    .flash.error { background:var(--neg-soft); color:var(--neg); }
    .card { background:var(--surface); border-radius:14px; padding:22px; box-shadow:0 2px 12px rgba(124,111,247,0.05); margin-bottom:18px; }
    .fld { margin-bottom:14px; }
    .fld label { display:block; font-size:13px; color:var(--ink-700); margin-bottom:6px; font-weight:500; }
    .fld input, .fld select, .fld textarea { width:100%; padding:10px 12px; border:1.5px solid var(--line); border-radius:9px; font-family:inherit; font-size:14px; box-sizing:border-box; }
    .fld textarea { min-height:90px; resize:vertical; }
    .btn { background:var(--accent); color:#fff; border:none; border-radius:9px; padding:11px 20px; cursor:pointer; font-family:inherit; font-size:14px; }
    .scope-row { display:flex; gap:10px; margin-bottom:14px; flex-wrap:wrap; }
    .scope-opt { padding:9px 16px; border:1.5px solid var(--line); border-radius:9px; cursor:pointer; font-size:14px; }
    .scope-opt.active { border-color:var(--accent-text); background:var(--bg); color:var(--accent-text); font-weight:600; }
    .checklist { max-height:260px; overflow-y:auto; border:1.5px solid var(--line); border-radius:10px; padding:10px; }
    .checklist label { display:flex; align-items:center; gap:8px; padding:7px 6px; font-size:14px; cursor:pointer; border-radius:6px; }
    .checklist label:hover { background:var(--line-soft); }
    .checklist input { width:auto; }
    .t-tabs { display:flex; gap:6px; border-bottom:1.5px solid var(--line); margin-bottom:20px; flex-wrap:wrap; }
    .t-tab { padding:10px 18px; text-decoration:none; color:var(--ink-500); font-size:14px; border-bottom:2px solid transparent; }
    .t-tab.active { color:var(--accent-text); border-bottom-color:var(--accent-text); font-weight:600; }
</style>

<div class="t-tabs">
    <a href="tasks.php" class="t-tab">مهامي</a>
    <a href="tasks_issue.php" class="t-tab active">+ إصدار مهمة</a>
</div>

<?php if ($flash): ?><div class="flash <?= $flashType ?>"><?= e($flash) ?></div><?php endif; ?>

<div class="card">
    <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="scope" id="scopeInput" value="users">

        <div class="fld">
            <label>عنوان المهمة *</label>
            <input type="text" name="title" required placeholder="مثال: جرد رف الكشاكيل" aria-label="عنوان المهمة">
        </div>
        <div class="fld">
            <label>التفاصيل</label>
            <textarea name="details" placeholder="اكتب تفاصيل المهمة..."></textarea>
        </div>
        <div style="display:flex;gap:14px;flex-wrap:wrap;">
            <div class="fld" style="flex:1;min-width:150px;">
                <label>الأولوية</label>
                <select name="priority">
                    <option value="normal">عادي</option>
                    <option value="important">مهم</option>
                    <option value="urgent">عاجل</option>
                </select>
            </div>
            <div class="fld" style="flex:1;min-width:150px;">
                <label>موعد نهائي (اختياري)</label>
                <input type="date" name="due_date">
            </div>
        </div>

        <label style="display:block;font-size:13px;color:var(--ink-700);margin-bottom:8px;font-weight:500;">توجيه المهمة إلى:</label>
        <div class="scope-row">
            <div class="scope-opt active" data-scope="users" onclick="setScope(this)">موظفين محددين</div>
            <div class="scope-opt" data-scope="branch" onclick="setScope(this)">فرع كامل</div>
            <div class="scope-opt" data-scope="all" onclick="setScope(this)">كل الموظفين</div>
        </div>

        <div class="fld" id="box_users">
            <div class="checklist">
                <?php foreach ($usersList as $usr): ?>
                    <label>
                        <input type="checkbox" name="user_ids[]" value="<?= (int)$usr['id'] ?>">
                        <?= e($usr['full_name']) ?>
                        <?php if ($usr['branch_name']): ?><span style="color:var(--ink-400);font-size:12px;">— <?= e($usr['branch_name']) ?></span><?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="fld" id="box_branch" style="display:none;">
            <div class="checklist">
                <?php foreach ($branches as $b): ?>
                    <label>
                        <input type="checkbox" name="branch_ids[]" value="<?= (int)$b['id'] ?>">
                        <?= e($b['name']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="fld" id="box_all" style="display:none;">
            <p style="color:var(--ink-500);font-size:14px;">المهمة هتروح لكل الموظفين النشطين في كل الفروع.</p>
        </div>

        <button class="btn" style="margin-top:8px;"><i class="ti ti-send"></i> إصدار المهمة</button>
    </form>
</div>

<script>
    function setScope(el) {
        document.querySelectorAll('.scope-opt').forEach(o => o.classList.remove('active'));
        el.classList.add('active');
        const scope = el.dataset.scope;
        document.getElementById('scopeInput').value = scope;
        document.getElementById('box_users').style.display  = scope==='users'  ? 'block' : 'none';
        document.getElementById('box_branch').style.display = scope==='branch' ? 'block' : 'none';
        document.getElementById('box_all').style.display    = scope==='all'    ? 'block' : 'none';
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
