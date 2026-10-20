<?php
/**
 * tasks_lib.php — مكتبة استعلامات وبيانات موديول المهام (بريف ١٤٥)
 * نظام الفجالة ستور المالي
 *
 * الدوال الموحدة للاستعلامات المشتركة بين صفحات الويب وواجهات الـ API:
 *   tasks_inbox_list()         ← قائمة المهام الواردة للمستخدم
 *   tasks_sent_list()          ← قائمة المهام التي أصدرها المستخدم
 *   tasks_get_detail()         ← تفاصيل مهمة ومستهدفيها وردودها ومرفقاتها
 *   tasks_issue_targets_data() ← بيانات الفروع والمستخدمين لنموذج الإصدار
 */
declare(strict_types=1);

require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/shortage_lib.php';

/**
 * قائمة المهام الواردة لمستخدم (المستهدف فيها) التي لم تُغلق بعد.
 *
 * @param int $userId معرّف المستخدم
 * @param int $limit أقصى عدد للمهام
 * @return array
 */
function tasks_inbox_list(int $userId, int $limit = 200): array
{
    if ($userId <= 0) {
        return [];
    }

    $st = db()->prepare(
        "SELECT t.*, tt.status AS my_status, tt.done_at, u.full_name AS creator
         FROM task_targets tt
         JOIN tasks t ON t.id = tt.task_id
         LEFT JOIN users u ON u.id = t.created_by
         WHERE tt.user_id = ? AND t.status <> 'closed'
         ORDER BY (tt.status='pending') DESC, FIELD(t.priority,'urgent','important','normal'), t.id DESC
         LIMIT " . (int) $limit
    );
    $st->execute([$userId]);
    return $st->fetchAll();
}

/**
 * قائمة المهام التي أصدرها المستخدم مع إحصائيات الإنجاز.
 *
 * @param int $userId معرّف المستخدم
 * @param int $limit أقصى عدد للمهام
 * @return array
 */
function tasks_sent_list(int $userId, int $limit = 200): array
{
    if ($userId <= 0) {
        return [];
    }

    $st = db()->prepare(
        "SELECT t.*,
                (SELECT COUNT(*) FROM task_targets x WHERE x.task_id=t.id) AS total,
                (SELECT COUNT(*) FROM task_targets x WHERE x.task_id=t.id AND x.status='done') AS done
         FROM tasks t WHERE t.created_by = ? ORDER BY t.id DESC LIMIT " . (int) $limit
    );
    $st->execute([$userId]);
    return $st->fetchAll();
}

/**
 * تفاصيل مهمة مع فحص صلاحية الرؤية (المنشئ، المستهدف، المالك، مشرف النواقص).
 *
 * @param int $taskId معرّف المهمة
 * @param int $userId معرّف المستخدم الحالي
 * @return array|null مصفوفة التفاصيل أو null لو غير مصرّح أو غير موجودة
 */
function tasks_get_detail(int $taskId, int $userId): ?array
{
    if ($taskId <= 0 || $userId <= 0) {
        return null;
    }

    $pdo = db();
    $st = $pdo->prepare(
        "SELECT t.*, u.full_name AS creator FROM tasks t
         LEFT JOIN users u ON u.id=t.created_by WHERE t.id=?"
    );
    $st->execute([$taskId]);
    $openTask = $st->fetch();
    if (!$openTask) {
        return null;
    }

    // فحص صلاحية الوصول (نفس شروط tasks.php:118-126 بالحرف)
    $chk = $pdo->prepare('SELECT status FROM task_targets WHERE task_id=? AND user_id=?');
    $chk->execute([$taskId, $userId]);
    $myTarget = $chk->fetchColumn();

    $amCreator = (int) $openTask['created_by'] === $userId;
    $amSupervisor = ($openTask['ref_type'] ?? '') === 'shortage'
                    && $userId === shortage_supervisor_id();

    if ($myTarget === false && !$amCreator && !is_owner() && !$amSupervisor) {
        return null;
    }

    $openTask['my_target_status'] = $myTarget !== false ? $myTarget : null;

    $tt = $pdo->prepare(
        "SELECT tt.*, u.full_name FROM task_targets tt
         JOIN users u ON u.id=tt.user_id WHERE tt.task_id=? ORDER BY u.full_name"
    );
    $tt->execute([$taskId]);
    $openTargets = $tt->fetchAll();

    $tc = $pdo->prepare(
        "SELECT tc.*, u.full_name FROM task_comments tc
         LEFT JOIN users u ON u.id=tc.user_id WHERE tc.task_id=? ORDER BY tc.id ASC"
    );
    $tc->execute([$taskId]);
    $openComments = $tc->fetchAll();

    $openShortage = ($openTask['ref_type'] ?? '') === 'shortage'
        ? shortage_by_task($taskId) : null;

    $openAttachments = task_attachments($taskId);

    return [
        'task'        => $openTask,
        'targets'     => $openTargets,
        'comments'    => $openComments,
        'shortage'    => $openShortage,
        'attachments' => $openAttachments,
    ];
}

/**
 * بيانات الفروع والمستخدمين لنموذج توجيه المهام.
 *
 * @return array{branches:array,users:array}
 */
function tasks_issue_targets_data(): array
{
    $pdo = db();
    $branches = $pdo->query('SELECT id, name FROM branches WHERE is_active=1 ORDER BY name')->fetchAll();
    $usersList = $pdo->query(
        'SELECT u.id, u.full_name, b.name AS branch_name
         FROM users u
         LEFT JOIN branches b ON b.id = u.branch_id
         WHERE u.is_active=1 ORDER BY u.full_name'
    )->fetchAll();

    return [
        'branches' => $branches,
        'users'    => $usersList,
    ];
}

