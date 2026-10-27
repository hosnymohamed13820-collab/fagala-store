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

<?= ui_page_head('أقسام وأرفف الجرد') ?>

<?php if ($flash): ?>
    <?= ui_alert($flashType === 'error' ? 'neg' : 'pos', e($flash)) ?>
<?php endif; ?>

<?= ui_card_open('إضافة أقسام/أرفف') ?>
    <p class="fpage-sub" style="margin-bottom:12px;">
        اكتب كل قسم/رف في سطر. مثلاً:<br>
        <span style="color:var(--ink-500);">الأقلام - الرف الأول<br>الأقلام - الرف الثاني<br>الكشاكيل - الرف الأول</span>
    </p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="add">
        <textarea class="ftextarea" name="places" rows="6" required placeholder="اكتب كل مكان في سطر..."></textarea>
        <button type="submit" class="btn" style="margin-top:10px;"><i class="ti ti-plus"></i> إضافة</button>
    </form>
<?= ui_card_close() ?>

<?php
// زرار «مسح الكل» بيتحط في رأس الكارت — لو فيه أماكن بس
$stpActions = '';
if (!empty($places)) {
    ob_start();
    ?>
    <form method="post" data-confirm="مسح جميع الأقسام والأرفف (<?= count($places) ?>) دفعة واحدة؟ مش هينفع ترجعهم." data-confirm-danger>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete_all">
        <button type="submit" class="btn btn-danger"><i class="ti ti-trash"></i> مسح جميع الأقسام والأرفف</button>
    </form>
    <?php
    $stpActions = (string) ob_get_clean();
}
?>
<?= ui_card_open('الأماكن الحالية (' . count($places) . ')', $stpActions) ?>
<?php if (empty($places)): ?>
    <div class="fg-empty"><i class="ti ti-inbox"></i><span>لا توجد أماكن بعد. أضف أقسام وأرفف فوق.</span></div>
<?php else: ?>
    <div class="ftable-wrap">
        <table class="ftable">
            <thead><tr><th class="num">الترتيب</th><th>الاسم</th><th>الحالة</th><th>إجراءات</th></tr></thead>
            <tbody>
            <?php foreach ($places as $p): ?>
                <tr style="<?= $p['is_active']?'':'opacity:0.5;' ?>">
                    <td class="num"><?= (int)$p['sort_order'] ?></td>
                    <td class="name"><?= e($p['name']) ?></td>
                    <td><?= $p['is_active'] ? '<span class="fchip fchip-pos">مفعّل</span>' : '<span class="fchip fchip-mute">مخفي</span>' ?></td>
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
                                <button class="btn btn-sm btn-danger">حذف</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?= ui_card_close() ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
