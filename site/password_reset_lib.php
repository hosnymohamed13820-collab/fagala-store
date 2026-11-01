<?php
declare(strict_types=1);
require_once __DIR__ . '/account_lib.php';
require_once __DIR__ . '/push_lib.php';

const PWRESET_REQUEST_MESSAGE = 'لو الاسم صح، الطلب اتبعت. استنى المدير يديك كود';
const PWRESET_CODE_ERROR = 'الكود غلط أو انتهى';

// الموافقة محتاجة الصلاحية الجديدة بالاسم، من الدور أو الزيادة.
function pwreset_user_columns(): string
{
    return "u.*, r.is_owner,
        (EXISTS(SELECT 1 FROM user_permissions up WHERE up.user_id=u.id AND up.module_key='hr:password_reset')
         OR EXISTS(SELECT 1 FROM role_permissions rp WHERE rp.role_id=u.role_id AND rp.module_key='hr:password_reset')) AS reset_permission,
        (EXISTS(SELECT 1 FROM user_permissions up WHERE up.user_id=u.id AND up.module_key='view_all')
         OR EXISTS(SELECT 1 FROM role_permissions rp WHERE rp.role_id=u.role_id AND rp.module_key='view_all')) AS view_permission";
}

function pwreset_manager(PDO $pdo): array
{
    $st = $pdo->prepare('SELECT ' . pwreset_user_columns() . ' FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.is_active=1');
    $st->execute([(int) (current_user()['id'] ?? 0)]);
    $actor = $st->fetch(PDO::FETCH_ASSOC);
    if (!$actor || (!$actor['is_owner'] && !$actor['reset_permission'])) {
        throw new RuntimeException('مالكش صلاحية', 403);
    }
    return $actor;
}

// بنجيب صلاحيات كل الحسابات مرة واحدة، من غير استعلام جوه حلقة.
function pwreset_permissions(PDO $pdo, array $userIds): array
{
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    if (!$userIds) return [];
    $marks = implode(',', array_fill(0, count($userIds), '?'));
    $st = $pdo->prepare("SELECT up.user_id, up.module_key FROM user_permissions up WHERE up.user_id IN ($marks)
        UNION
        SELECT u.id AS user_id, rp.module_key FROM users u JOIN role_permissions rp ON rp.role_id=u.role_id WHERE u.id IN ($marks)");
    $st->execute(array_merge($userIds, $userIds));
    $permissions = array_fill_keys($userIds, []);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $permissions[(int) $row['user_id']][$row['module_key']] = true;
    }
    return $permissions;
}

function pwreset_can_decide(array $actor, array $target, array $permissions): bool
{
    if ((int) $actor['id'] === (int) $target['id']) return false;
    if (!empty($actor['is_owner'])) return true;
    if (empty($actor['reset_permission']) || !empty($target['is_owner'])
        || !empty($target['view_permission']) || !empty($target['reset_permission'])) return false;
    $actorKeys = $permissions[(int) $actor['id']] ?? null;
    $targetKeys = $permissions[(int) $target['id']] ?? null;
    if ($actorKeys === null || $targetKeys === null) return false;
    foreach ($targetKeys as $key => $_) {
        // الموديول الكامل عند الموافق بيغطّي أجزاءه، لكن الجزء مايغطّيش الكامل.
        $parent = explode(':', $key, 2)[0];
        if (!isset($actorKeys[$key]) && !isset($actorKeys[$parent])) return false;
    }
    return true;
}

function pwreset_request(PDO $pdo, string $username, string $ip): array
{
    $started = microtime(true);
    $locked = false;
    // نفس تنبيه الوركر في كل الحالات عشان وقت الإغلاق مايكشفش وجود الاسم.
    push_kick_worker();
    try {
        $locked = (int) $pdo->query("SELECT GET_LOCK('fagala_pwreset_request', 5)")->fetchColumn() === 1;
        if (!$locked) return ['message' => PWRESET_REQUEST_MESSAGE];
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT id, full_name FROM users WHERE username=? AND is_active=1 LIMIT 1');
        $st->execute([trim($username)]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        // الحساب الموجود بيتعدّ بالمعرّف، فاختلاف حالة الحروف مايفلتش من الحد.
        $key = hash('sha256', $user ? 'id:' . $user['id'] : 'name:' . mb_strtolower(trim($username)));
        $st = $pdo->prepare('SELECT COUNT(*) FROM password_reset_limits WHERE username_key=? AND created_at > NOW() - INTERVAL 1 HOUR');
        $st->execute([$key]);
        $nameCount = (int) $st->fetchColumn();
        $st = $pdo->prepare('SELECT COUNT(*) FROM password_reset_limits WHERE request_ip=? AND created_at > NOW() - INTERVAL 1 HOUR');
        $st->execute([$ip]);
        if ((int) $st->fetchColumn() < 10) {
            // حتى الاسم اللي وصل لحدّه بيتحسب على الـIP، من غير طلب ولا إشعار جديد.
            $pdo->prepare('INSERT INTO password_reset_limits (username_key, request_ip) VALUES (?, ?)')->execute([$key, $ip]);
            $st = $pdo->prepare("SELECT id FROM password_reset_requests WHERE user_id=? AND status='pending' AND created_at > NOW() - INTERVAL 1 HOUR LIMIT 1");
            $st->execute([(int) ($user['id'] ?? 0)]);
            $pending = $st->fetchColumn();
            if ($nameCount < 3 && $user && !$pending) {
                $pdo->prepare("INSERT INTO password_reset_requests (user_id, request_ip) VALUES (?, ?)")->execute([$user['id'], $ip]);
                $st = $pdo->prepare('SELECT ' . pwreset_user_columns() . ' FROM users u JOIN roles r ON r.id=u.role_id WHERE u.is_active=1 AND u.id<>?');
                $st->execute([$user['id']]);
                $recipients = array_column(array_filter($st->fetchAll(PDO::FETCH_ASSOC),
                    static fn(array $row): bool => !empty($row['is_owner']) || !empty($row['reset_permission'])), 'id');
                notify($pdo, $recipients, 'password_reset', 'طلب إعادة باسورد', $user['full_name'] . ' بيطلب باسورد جديد', 'password_resets.php');
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('pwreset request failed: ' . $e->getMessage());
    } finally {
        if ($locked) $pdo->query("SELECT RELEASE_LOCK('fagala_pwreset_request')");
        // حد أدنى ثابت للرد، حتى الاسم الغلط والطلب اللي وصل للحد.
        $left = 0.5 - (microtime(true) - $started);
        if ($left > 0) usleep((int) ($left * 1000000));
    }
    return ['message' => PWRESET_REQUEST_MESSAGE];
}

function pwreset_list(PDO $pdo, int $page = 1): array
{
    $actor = pwreset_manager($pdo);
    $offset = (max(1, $page) - 1) * 50;
    $st = $pdo->query('SELECT ' . pwreset_user_columns() . ", q.id AS request_id, q.created_at AS requested_at, q.request_ip, b.name AS branch_name
        FROM password_reset_requests q JOIN users u ON u.id=q.user_id JOIN roles r ON r.id=u.role_id
        LEFT JOIN branches b ON b.id=u.branch_id
        WHERE q.status='pending' AND q.created_at > NOW() - INTERVAL 24 HOUR AND u.is_active=1
        ORDER BY q.id DESC LIMIT 51 OFFSET " . $offset);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $more = count($rows) > 50;
    $rows = array_slice($rows, 0, 50);
    $permissions = pwreset_permissions($pdo, array_merge([(int) $actor['id']], array_column($rows, 'id')));
    $rows = array_map(static fn(array $row): array => [
        'id' => (int) $row['request_id'], 'full_name' => $row['full_name'], 'branch' => $row['branch_name'] ?? 'من غير فرع',
        'created_at' => $row['requested_at'], 'ip' => $row['request_ip'], 'can_decide' => pwreset_can_decide($actor, $row, $permissions),
    ], $rows);
    return ['requests' => $rows, 'more' => $more];
}

function pwreset_decide(PDO $pdo, int $requestId, bool $approve): array
{
    $actor = pwreset_manager($pdo);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT user_id FROM password_reset_requests WHERE id=?');
        $st->execute([$requestId]);
        $uid = (int) $st->fetchColumn();
        // قفل الحساب الأول في الموافقة والاستعمال عشان مفيش كودين يتسابقوا.
        $st = $pdo->prepare('SELECT ' . pwreset_user_columns() . ' FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? FOR UPDATE');
        $st->execute([$uid]);
        $target = $st->fetch(PDO::FETCH_ASSOC);
        $permissions = pwreset_permissions($pdo, [(int) $actor['id'], $uid]);
        if (!$target || !pwreset_can_decide($actor, $target, $permissions)) throw new RuntimeException('مالكش صلاحية توافق على الحساب ده', 403);
        $st = $pdo->prepare("SELECT id FROM password_reset_requests WHERE id=? AND status='pending' AND created_at > NOW() - INTERVAL 24 HOUR FOR UPDATE");
        $st->execute([$requestId]);
        if (!$st->fetchColumn() || !$target['is_active']) throw new RuntimeException('الطلب اتقفل أو انتهى', 409);
        $code = null;
        if ($approve) {
            $code = (string) random_int(100000, 999999);
            $pdo->prepare("UPDATE password_reset_requests SET status='expired', code_hash=NULL WHERE user_id=? AND status='approved'")->execute([$uid]);
            $pdo->prepare("UPDATE password_reset_requests SET status='approved', decided_by=?, decided_at=NOW(), code_hash=?, code_expires_at=DATE_ADD(NOW(), INTERVAL 30 MINUTE), attempts=0 WHERE id=?")
                ->execute([$actor['id'], password_hash($code, PASSWORD_DEFAULT), $requestId]);
            notify($pdo, [$uid], 'password_reset', 'فيه موافقة على باسورد جديد لحسابك', 'لو مش انت اللي طلبت، كلّم المالك فوراً', 'login.php');
        } else {
            $pdo->prepare("UPDATE password_reset_requests SET status='rejected', decided_by=?, decided_at=NOW() WHERE id=?")
                ->execute([$actor['id'], $requestId]);
        }
        $pdo->commit();
        return $approve ? ['reset_otp' => $code, 'message' => 'ادّي الكود ده للموظف بنفسك أو في التليفون'] : ['message' => 'الطلب اترفض'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function pwreset_redeem(PDO $pdo, string $username, string $code, string $new, string $confirmation): array
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id, password_hash FROM users WHERE username=? AND is_active=1 LIMIT 1 FOR UPDATE');
        $st->execute([trim($username)]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        $st = $pdo->prepare("SELECT *, (code_expires_at > NOW()) AS valid_time FROM password_reset_requests WHERE user_id=? AND status='approved' ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $st->execute([(int) ($user['id'] ?? 0)]);
        $request = $st->fetch(PDO::FETCH_ASSOC);
        if (!$request || !$request['valid_time'] || (int) $request['attempts'] >= 5) {
            if ($request) $pdo->prepare("UPDATE password_reset_requests SET status='expired', code_hash=NULL WHERE id=?")->execute([$request['id']]);
            $pdo->commit();
            return ['ok' => false, 'error' => PWRESET_CODE_ERROR];
        }
        if (!preg_match('/^[0-9]{6}$/D', $code) || !password_verify($code, (string) $request['code_hash'])) {
            $attempts = (int) $request['attempts'] + 1;
            $pdo->prepare("UPDATE password_reset_requests SET attempts=?, status=?, code_hash=IF(? >= 5, NULL, code_hash) WHERE id=?")
                ->execute([$attempts, $attempts >= 5 ? 'expired' : 'approved', $attempts, $request['id']]);
            $pdo->commit();
            return ['ok' => false, 'error' => PWRESET_CODE_ERROR];
        }
        $error = my_account_password_error($new, $confirmation, password_verify($new, (string) $user['password_hash']));
        if ($error !== null) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => $error];
        }
        $pdo->prepare('UPDATE users SET password_hash=?, pw_version=pw_version+1 WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        $pdo->prepare("UPDATE password_reset_requests SET status='used', used_at=NOW(), code_hash=NULL WHERE id=?")->execute([$request['id']]);
        $pdo->prepare("UPDATE password_reset_requests SET status='expired', code_hash=NULL WHERE user_id=? AND status IN ('pending','approved')")->execute([$user['id']]);
        notify($pdo, [(int) $user['id']], 'password_reset', 'اتغيّر باسورد حسابك. لو مش انت، كلّم المدير', null, 'login.php');
        $pdo->commit();
        $_SESSION['pwreset_success'] = true;
        return ['ok' => true, 'message' => 'اتغيّر، ادخل بالباسورد الجديد', 'redirect' => 'login.php'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
