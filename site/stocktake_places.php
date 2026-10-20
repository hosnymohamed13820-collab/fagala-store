<?php
/**
 * stocktake_places.php — إدارة أقسام/أرفف الجرد (للأونر فقط).
 * الأماكن دي بتظهر كقائمة في محضر الجرد بدل الكتابة الحرة.
 */
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/stocktake_places_lib.php';
require_login();
if (!is_owner()) { http_response_code(403); exit('للأونر فقط.'); }

$pdo = db();
$flash = ''; $flashType = '';

// إنشاء الجدول تلقائياً لو مش موجود (بدل ما تشغّل SQL يدوي)
stp_ready();


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $flash = 'انتهت صلاحية الجلسة.'; $flashType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'add') {
                // إضافة أماكن (سطر لكل مكان)
                $n = stp_add((string)($_POST['places'] ?? ''));
                $flash = "تمت إضافة $n مكان."; $flashType = 'success';
            } elseif ($action === 'delete') {
                stp_delete((int)($_POST['id'] ?? 0));
                $flash = 'تم الحذف.'; $flashType = 'success';
            } elseif ($action === 'delete_all') {
                stp_delete_all();
                $flash = 'تم مسح جميع الأقسام والأرفف.'; $flashType = 'success';
            } elseif ($action === 'toggle') {
                stp_toggle((int)($_POST['id'] ?? 0));
                $flash = 'تم التحديث.'; $flashType = 'success';
            }
        } catch (Throwable $ex) {
            $flash = 'خطأ: ' . $ex->getMessage(); $flashType = 'error';
        }
    }
}

$places = stp_list();

$page_title = 'أقسام وأرفف الجرد';
$active_module = 'stocktake';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head"><h1>أقسام وأرفف الجرد</h1></div>

<?php if ($flash): ?>
    <div class="flash <?= $flashType ?>"><?= e($flash) ?></div>
<?php endif; ?>

<div class="card">
    <h3 style="font-size:16px;color:var(--ink-900);margin-bottom:8px;">إضافة أقسام/أرفف</h3>
    <p style="color:var(--ink-600);font-size:14px;line-height:1.8;">
        اكتب كل قسم/رف في سطر. مثلاً:<br>
        <span style="color:var(--ink-500);">الأقلام - الرف الأول<br>الأقلام - الرف الثاني<br>الكشاكيل - الرف الأول</span>
    </p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="add">
        <textarea name="places" rows="6" required placeholder="اكتب كل مكان في سطر..."
                  style="width:100%;padding:12px;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;font-size:14px;background:var(--line-soft);"></textarea>
        <button type="submit" class="btn" style="margin-top:10px;"><i class="ti ti-plus"></i> إضافة</button>
    </form>
</div>

<div class="card">
    <h3 style="font-size:16px;color:var(--ink-900);margin-bottom:8px;">الأماكن الحالية (<?= count($places) ?>)</h3>
    <?php if (empty($places)): ?>
        <div class="empty">لا توجد أماكن بعد. أضف أقسام وأرفف فوق.</div>
    <?php else: ?>
    <form method="post" data-confirm="مسح جميع الأقسام والأرفف (<?= count($places) ?>) دفعة واحدة؟ مش هينفع ترجعهم." data-confirm-danger style="margin-bottom:14px;">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete_all">
        <button type="submit" class="btn" style="background:var(--neg-fill);"><i class="ti ti-trash"></i> مسح جميع الأقسام والأرفف</button>
    </form>
    <table>
        <thead><tr><th>الترتيب</th><th>الاسم</th><th>الحالة</th><th>إجراءات</th></tr></thead>
        <tbody>
        <?php foreach ($places as $p): ?>
            <tr style="<?= $p['is_active']?'':'opacity:0.5;' ?>">
                <td><?= (int)$p['sort_order'] ?></td>
                <td style="font-weight:500;"><?= e($p['name']) ?></td>
                <td><?= $p['is_active'] ? '<span style="color:var(--pos);">مفعّل</span>' : '<span style="color:var(--ink-400);">مخفي</span>' ?></td>
                <td>
                    <div style="display:flex;gap:6px;">
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <button class="btn btn-sm btn-ghost"><?= $p['is_active']?'إخفاء':'تفعيل' ?></button>
                        </form>
                        <form method="post" style="display:inline;" data-confirm="حذف المكان ده؟" data-confirm-danger>
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <button style="background:var(--neg-soft);color:var(--neg);border:none;border-radius:7px;padding:6px 11px;cursor:pointer;font-family:inherit;font-size:13px;">حذف</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
