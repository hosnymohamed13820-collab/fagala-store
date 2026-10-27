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
require_once __DIR__ . '/tasks_lib.php';

$pdo = db();
$uid = (int) current_user()['id'];
$flash = ''; $flashType = 'success';

$targetsData = tasks_issue_targets_data();
$branches    = $targetsData['branches'];
$usersList   = $targetsData['users'];

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
    .checklist { max-height:260px; overflow-y:auto; border:1.5px solid var(--line); border-radius:10px; padding:10px; }
    .checklist label { display:flex; align-items:center; gap:8px; padding:7px 6px; font-size:14px; cursor:pointer; border-radius:6px; }
    .checklist label:hover { background:var(--line-soft); }
    .checklist .check-control { width:auto; }
</style>

<nav class="ftabs" aria-label="المهام">
    <a href="tasks.php" class="ftab">مهامي</a>
    <a href="tasks_issue.php" class="ftab is-active">+ إصدار مهمة</a>
</nav>

<?php if ($flash): ?><div class="falert <?= $flashType === 'success' ? 'falert-pos' : 'falert-neg' ?>"><?= e($flash) ?></div><?php endif; ?>

<div class="fcard">
    <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="scope" id="scopeInput" value="users">

        <div class="ffield fld">
            <label>عنوان المهمة *</label>
            <input type="text" class="finput" name="title" required placeholder="مثال: جرد رف الكشاكيل" aria-label="عنوان المهمة">
        </div>
        <div class="ffield fld">
            <label>التفاصيل</label>
            <textarea class="ftextarea" name="details" placeholder="اكتب تفاصيل المهمة..."></textarea>
        </div>
        <div style="display:flex;gap:14px;flex-wrap:wrap;">
            <div class="ffield fld" style="flex:1;min-width:150px;">
                <label>الأولوية</label>
                <select class="fselect" name="priority">
                    <option value="normal">عادي</option>
                    <option value="important">مهم</option>
                    <option value="urgent">عاجل</option>
                </select>
            </div>
            <div class="ffield fld" style="flex:1;min-width:150px;">
                <label>موعد نهائي (اختياري)</label>
                <input type="date" class="finput" name="due_date">
            </div>
        </div>

        <label style="display:block;font-size:13px;color:var(--ink-700);margin-bottom:8px;font-weight:500;">توجيه المهمة إلى:</label>
        <div class="ftabs scope-row">
            <div class="ftab scope-opt is-active" data-scope="users" onclick="setScope(this)">موظفين محددين</div>
            <div class="ftab scope-opt" data-scope="branch" onclick="setScope(this)">فرع كامل</div>
            <div class="ftab scope-opt" data-scope="all" onclick="setScope(this)">كل الموظفين</div>
        </div>

        <div class="ffield fld" id="box_users">
            <script>
            /* 🔴 لازم تفضل فوق القايمة — لو نزلت تحت، اللي يكتب قبل ما الصفحة
                   تكمّل مايحصلش حاجة وتتسجّل غلطة في سجل السيرفر. */
                function empFilter() {
                    var box = document.getElementById('empSearch');
                    if (!box) return;
                    var q = (box.value || '').trim().toLowerCase();
                    var rows = document.querySelectorAll('[data-emp-row]');
                    for (var i = 0; i < rows.length; i++) {
                        rows[i].style.display =
                            (!q || (rows[i].dataset.s || '').indexOf(q) !== -1) ? '' : 'none';
                    }
                }
            </script>
            <input type="text" class="finput" id="empSearch" placeholder="ابحث بالاسم أو الفرع…" oninput="empFilter()" style="margin-bottom:8px;">
            <div class="checklist">
                <?php foreach ($usersList as $usr): ?>
                    <label data-emp-row data-s="<?= e(mb_strtolower($usr['full_name'] . ' ' . ($usr['branch_name'] ?? ''))) ?>">
                        <input type="checkbox" class="check-control" name="user_ids[]" value="<?= (int)$usr['id'] ?>">
                        <?= e($usr['full_name']) ?>
                        <?php if ($usr['branch_name']): ?><span style="color:var(--ink-400);font-size:12px;">— <?= e($usr['branch_name']) ?></span><?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="ffield fld" id="box_branch" style="display:none;">
            <div class="checklist">
                <?php foreach ($branches as $b): ?>
                    <label>
                        <input type="checkbox" class="check-control" name="branch_ids[]" value="<?= (int)$b['id'] ?>">
                        <?= e($b['name']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="ffield fld" id="box_all" style="display:none;">
            <p style="color:var(--ink-500);font-size:14px;">المهمة هتروح لكل الموظفين النشطين في كل الفروع.</p>
        </div>

        <button class="btn btn-primary" style="margin-top:8px;"><i class="ti ti-send"></i> إصدار المهمة</button>
    </form>
</div>

<script>
    function setScope(el) {
        document.querySelectorAll('.scope-opt').forEach(o => o.classList.remove('is-active'));
        el.classList.add('is-active');
        const scope = el.dataset.scope;
        document.getElementById('scopeInput').value = scope;
        document.getElementById('box_users').style.display  = scope==='users'  ? 'block' : 'none';
        document.getElementById('box_branch').style.display = scope==='branch' ? 'block' : 'none';
        document.getElementById('box_all').style.display    = scope==='all'    ? 'block' : 'none';
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
