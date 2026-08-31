<?php
/**
 * purchase_groups.php — شاشة مجموعات الشراء وتجميع الحجوزات
 * نظام الفجالة ستور المالي
 *
 * المراحل:
 *   - المرحلة (٣): إنشاء مجموعة شراء + كروت المجموعات
 *   - المرحلة (٤): تحويل استعراض المجموعة لواجهة تفاعلية شبيهة بـ Google Keep
 */
require_once __DIR__ . '/auth.php';
require_permission('shortage');
require_once __DIR__ . '/book_lib.php';
require_once __DIR__ . '/purchase_group_lib.php';

$uid           = (int) current_user()['id'];
$active_module = 'shortage';

// =====================================================================
//  معالجات AJAX
// =====================================================================
$ajax = (string) ($_GET['ajax'] ?? '');
if ($ajax !== '') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw new RuntimeException('طلب غير صالح.');
        }
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            throw new RuntimeException('بيانات غير صالحة.');
        }
        if (!csrf_verify((string) ($body['csrf'] ?? ''))) {
            throw new RuntimeException('انتهت صلاحية الجلسة — اعمل ريفريش وحاول تاني.');
        }

        switch ($ajax) {
            // إنشاء مجموعة جديدة
            case 'create':
                $resIds   = (array) ($body['res_line_ids'] ?? []);
                $shortIds = (array) ($body['short_line_ids'] ?? []);
                $name     = (string) ($body['name'] ?? '');
                $skipped  = 0;
                $id = purchase_group_create($resIds, $shortIds, $name, $uid, $skipped);

                $msg = 'تم إنشاء مجموعة الشراء رقم #' . $id;
                if ($skipped > 0) {
                    $msg .= ' (' . $skipped . ' سطر اتخطّى لعدم توافره)';
                }

                echo json_encode([
                    'ok'      => true,
                    'id'      => $id,
                    'skipped' => $skipped,
                    'msg'     => $msg,
                ], JSON_UNESCAPED_UNICODE);
                break;

            // تشييك / شيل تشييك على سطر
            case 'toggle':
                $groupId   = (int) ($body['group_id'] ?? 0);
                $lineId    = (int) ($body['line_id'] ?? 0);
                $isChecked = !empty($body['is_checked']);
                $res = purchase_group_toggle_line($groupId, $lineId, $isChecked, $uid);
                echo json_encode($res, JSON_UNESCAPED_UNICODE);
                break;

            // مسح سطر من المجموعة
            case 'remove':
                $groupId = (int) ($body['group_id'] ?? 0);
                $lineId  = (int) ($body['line_id'] ?? 0);
                $res = purchase_group_remove_line($groupId, $lineId);
                echo json_encode($res, JSON_UNESCAPED_UNICODE);
                break;

            // شيل التشييك عن الكل
            case 'uncheck_all':
                $groupId = (int) ($body['group_id'] ?? 0);
                $res = purchase_group_uncheck_all($groupId);
                echo json_encode($res, JSON_UNESCAPED_UNICODE);
                break;

            // مسح المتشحّط
            case 'delete_checked':
                $groupId = (int) ($body['group_id'] ?? 0);
                $res = purchase_group_delete_checked($groupId);
                echo json_encode($res, JSON_UNESCAPED_UNICODE);
                break;

            // تعديل اسم المجموعة
            case 'rename':
                $groupId = (int) ($body['group_id'] ?? 0);
                $name    = (string) ($body['name'] ?? '');
                $res = purchase_group_rename($groupId, $name);
                echo json_encode($res, JSON_UNESCAPED_UNICODE);
                break;

            // ترتيب السطور بالسحب
            case 'sort':
                $groupId = (int) ($body['group_id'] ?? 0);
                $lineIds = (array) ($body['line_ids'] ?? []);
                $res = purchase_group_sort($groupId, $lineIds);
                echo json_encode($res, JSON_UNESCAPED_UNICODE);
                break;

            default:
                throw new RuntimeException('طلب غير معروف.');
        }
    } catch (Throwable $ex) {
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// =====================================================================
//  تحميل بيانات الصفحة
// =====================================================================
$viewGroupId = (int) ($_GET['g'] ?? 0);
$groupView   = $viewGroupId > 0 ? purchase_group_get($viewGroupId) : null;

// بيانات شاشة الكروت وبوب أب التجميع (تُحمّل فقط عند الحاجة)
$openGroups      = [];
$candidates      = ['reservations' => [], 'shortages' => []];
$branches        = [];
$totalCandidates = 0;

if ($groupView === null) {
    $openGroups      = purchase_groups_list('open');
    $candidates      = purchase_group_candidates();
    $branches        = db()->query('SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name')->fetchAll();
    $totalCandidates = count($candidates['reservations']) + count($candidates['shortages']);
}

$page_title = 'تجميع الحجوزات';
require_once __DIR__ . '/includes/header.php';
?>
<style>
    .btn { background:var(--accent); color:#fff; border:none; padding:11px 20px; border-radius:10px;
        font-size:14px; font-family:inherit; cursor:pointer; display:inline-flex; align-items:center;
        gap:7px; text-decoration:none; white-space:nowrap; transition:filter .15s; }
    .btn:hover { filter:brightness(0.94); color:#fff; }
    .btn:disabled { opacity:.55; cursor:not-allowed; }

    .btn-secondary { background:var(--surface); color:var(--ink-700); border:1.5px solid var(--line); }
    .btn-secondary:hover { background:var(--line-soft); color:var(--ink-700); }

    .btn-branch { background:var(--surface); color:var(--ink-700); border:1.5px solid var(--line);
                  border-radius:20px; padding:4px 12px; font-size:12.5px; font-weight:600; cursor:pointer;
                  transition:all .15s; font-family:inherit; }
    .btn-branch:hover { background:var(--line-soft); color:var(--ink-700); }
    .btn-branch.active { background:var(--accent); color:#fff; border-color:var(--accent-text); }
    .btn-branch.active:hover { filter:brightness(0.94); color:#fff; }

    /* كروت المجموعات */
    .grp-cards { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:14px; }
    .grp-card { display:block; background:var(--surface); border:1.5px solid var(--line); border-radius:14px;
        padding:16px 18px; text-decoration:none; color:inherit; transition:all .15s; }
    .grp-card:hover { border-color:var(--accent-text); transform:translateY(-2px);
        box-shadow:0 8px 22px var(--accent-ring); }
    .gc-top { display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:10px; }
    .gc-title { font-weight:700; color:var(--ink-900); font-size:15px; }
    .gc-state { font-size:11.5px; padding:3px 10px; border-radius:20px; white-space:nowrap; font-weight:600; }
    .gc-meta { display:flex; flex-wrap:wrap; gap:12px; color:var(--ink-500); font-size:12.5px; margin-bottom:12px; }
    .gc-meta i { font-size:14px; }
    .gc-bar { height:6px; background:var(--line-soft); border-radius:4px; overflow:hidden; }
    .gc-bar span { display:block; height:100%; border-radius:4px; transition:width .3s; }
    .gc-foot { display:flex; justify-content:space-between; align-items:center;
        font-size:12.5px; color:var(--ink-500); margin-top:8px; }

    /* تفاصيل المجموعة — شكل Google Keep */
    .keep-view-wrap { max-width:760px; margin:0 auto 30px; }
    .keep-top-nav { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; }
    .keep-back-link { display:inline-flex; align-items:center; gap:6px; color:var(--ink-600); text-decoration:none; font-size:13.5px; font-weight:600; padding:6px 10px; border-radius:8px; transition:background .15s; }
    .keep-back-link:hover { background:var(--line-soft); color:var(--ink-800); }
    .keep-save-toast { font-size:12.5px; font-weight:600; color:var(--pos); display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:6px; background:var(--pos-soft); }

    .keep-card { background:var(--surface); border:1.5px solid var(--line); border-radius:16px; box-shadow:var(--shadow-sm); padding:20px 22px; position:relative; }
    .keep-header { display:flex; justify-content:space-between; align-items:center; gap:12px; border-bottom:1.5px solid var(--line-soft); padding-bottom:14px; margin-bottom:12px; }
    .keep-title-input { flex:1; border:none; background:transparent; font-size:18px; font-weight:700; color:var(--ink-900); font-family:inherit; padding:4px 0; outline:none; border-bottom:2px solid transparent; transition:border-color .15s; }
    .keep-title-input:focus { border-bottom-color:var(--accent-text); }

    .keep-menu-wrap { position:relative; }
    .keep-menu-btn { background:none; border:none; font-size:22px; line-height:1; font-weight:700; color:var(--ink-600); cursor:pointer; padding:6px 10px; border-radius:8px; font-family:inherit; transition:all .15s; }
    .keep-menu-btn:hover { background:var(--line-soft); color:var(--ink-900); }
    .keep-dropdown { position:absolute; left:0; top:100%; background:var(--surface); border:1px solid var(--line); border-radius:12px; box-shadow:var(--shadow-lg); min-width:210px; z-index:100; display:none; padding:6px 0; margin-top:4px; }
    .keep-dropdown.show { display:block; }
    .keep-dropdown-item { width:100%; display:flex; align-items:center; gap:9px; padding:10px 16px; border:none; background:none; font-size:13.5px; font-family:inherit; color:var(--ink-800); text-align:right; cursor:pointer; transition:background .15s; }
    .keep-dropdown-item:hover { background:var(--line-soft); color:var(--ink-900); }
    .keep-dropdown-item.keep-item-danger { color:var(--neg); }
    .keep-dropdown-item.keep-item-danger:hover { background:var(--neg-soft); color:var(--neg); }

    .keep-meta { display:flex; flex-wrap:wrap; gap:14px; color:var(--ink-500); font-size:12.5px; margin-bottom:16px; align-items:center; }
    .keep-meta i { font-size:14px; }

    /* سطور Keep */
    .keep-list { display:flex; flex-direction:column; gap:3px; }
    .keep-row { border-radius:10px; transition:background .15s, opacity .15s; padding:2px 0; }
    .keep-row:hover { background:var(--line-soft); }
    .keep-row.dragging { opacity:.35; background:var(--accent-soft); }
    .keep-row.drag-over { border-top:2px solid var(--accent); }
    .keep-row-main { display:flex; align-items:center; gap:10px; padding:7px 10px; }

    .keep-drag-handle { color:var(--ink-400); font-size:15px; cursor:grab; user-select:none; display:flex; align-items:center; padding:2px 4px; }
    .keep-drag-handle:active { cursor:grabbing; }

    /* Checkbox */
    .keep-cb-wrap { position:relative; display:inline-flex; align-items:center; cursor:pointer; user-select:none; }
    .keep-cb-input { position:absolute; opacity:0; width:0; height:0; }
    .keep-cb-box { width:20px; height:20px; border:2px solid var(--ink-400); border-radius:5px; display:inline-flex; align-items:center; justify-content:center; transition:all .15s; color:transparent; background:var(--surface); }
    .keep-cb-wrap:hover .keep-cb-box { border-color:var(--accent-text); }
    .keep-cb-input:checked + .keep-cb-box { background:var(--accent); border-color:var(--accent-text); color:#fff; }
    .keep-cb-box i { font-size:13px; font-weight:bold; }

    /* اسم الصنف والكمية */
    .keep-item-info { flex:1; display:flex; align-items:center; gap:8px; cursor:pointer; min-width:0; }
    .keep-item-name { font-size:14.5px; font-weight:600; color:var(--ink-900); }
    .keep-item-code { font-family:monospace; font-size:12px; color:var(--ink-400); }
    .keep-qty { font-size:15px; font-weight:700; color:var(--ink-800); min-width:38px; text-align:left; cursor:pointer; }
    .keep-btn-del { border:none; background:none; color:var(--ink-400); font-size:18px; font-weight:bold; cursor:pointer; padding:3px 8px; border-radius:6px; font-family:inherit; transition:all .15s; }
    .keep-btn-del:hover { background:var(--neg-soft); color:var(--neg); }

    /* القسم المطوي للمتشحّط */
    .keep-checked-section { margin-top:16px; border-top:1.5px solid var(--line-soft); padding-top:12px; }
    .keep-checked-header { display:inline-flex; align-items:center; gap:8px; font-size:13.5px; font-weight:700; color:var(--ink-600); cursor:pointer; padding:6px 10px; border-radius:8px; user-select:none; transition:background .15s; margin-bottom:6px; }
    .keep-checked-header:hover { background:var(--line-soft); color:var(--ink-900); }
    .keep-chevron { font-size:16px; transition:transform .2s; }
    .keep-chevron.collapsed { transform:rotate(-90deg); }

    /* التنسيق المشطوب والباهت للمتشحّط */
    .keep-row.is-checked .keep-item-name { text-decoration:line-through; color:var(--ink-400); font-weight:400; }
    .keep-row.is-checked .keep-item-code { text-decoration:line-through; color:var(--ink-300); }
    .keep-row.is-checked .keep-qty { text-decoration:line-through; color:var(--ink-400); font-weight:400; }
    .keep-row.is-checked .keep-drag-handle { visibility:hidden; }

    /* شجرة تفاصيل المصادر */
    .keep-sources { padding:8px 14px 12px 14px; margin-right:40px; margin-left:36px; border-right:2.5px solid var(--accent); margin-bottom:8px; background:var(--bg); border-radius:0 0 10px 10px; }
    .keep-src-inner { display:flex; flex-direction:column; gap:9px; font-size:13px; }
    .keep-src-branch-block { display:flex; flex-direction:column; gap:4px; }
    .keep-src-branch-title { display:flex; align-items:center; gap:6px; font-weight:700; color:var(--ink-800); }
    .keep-src-icon { color:var(--accent-text); font-size:14px; }
    .keep-src-arrow { color:var(--ink-400); font-size:12px; margin:0 2px; }
    .keep-src-val { color:var(--accent-text); font-weight:700; }
    .keep-src-sub-list { display:flex; flex-direction:column; gap:3px; margin-right:16px; }
    .keep-src-sub-item { display:flex; align-items:center; gap:6px; color:var(--ink-700); font-size:12.5px; }
    .keep-tree-char { color:var(--ink-400); font-family:monospace; font-weight:bold; }
    .keep-src-dash { color:var(--ink-400); margin:0 2px; }
    .keep-src-sub-qty { color:var(--ink-900); }
    .keep-src-empty { color:var(--ink-400); font-style:italic; }
    .keep-empty-msg { text-align:center; color:var(--ink-400); padding:24px 12px; font-size:14px; }

    /* بوب أب التجميع */
    .pg-table { width:100%; border-collapse:collapse; }
    .pg-table th, .pg-table td { padding:10px 12px; border-bottom:1px solid var(--line-soft);
                                 text-align:center; font-size:13.5px; }
    .pg-table th { background:var(--line-soft); color:var(--accent-text); font-weight:600; }
    .pg-table .nm { text-align:right; font-weight:500; min-width:200px; }
    .pg-table .cd { font-family:monospace; color:var(--ink-500); font-size:12px; }

    .pg-modal { position:fixed; inset:0; background:rgba(22,35,61,0.6); z-index:300;
                display:none; align-items:center; justify-content:center; padding:16px; }
    .pg-modal.on { display:flex; }
    .pg-modal-box { background:var(--surface); border-radius:14px; width:100%; max-width:960px;
                    max-height:90vh; display:flex; flex-direction:column; box-shadow:var(--shadow-lg); }
    .pg-modal-head { display:flex; justify-content:space-between; align-items:center;
                     padding:16px 20px; border-bottom:1px solid var(--line); }
    .pg-modal-title { font-size:16px; font-weight:700; color:var(--ink-900); display:flex; align-items:center; gap:8px; }
    .pg-modal-close { border:none; background:none; font-size:22px; cursor:pointer; color:var(--ink-500); font-family:inherit; }
    .pg-modal-close:hover { color:var(--neg); }
    .pg-modal-body { padding:20px; overflow-y:auto; flex:1; }
    .pg-modal-foot { padding:14px 20px; border-top:1px solid var(--line); display:flex;
                     justify-content:space-between; align-items:center; background:var(--bg); }

    .pg-sec { margin-bottom:20px; border:1px solid var(--line); border-radius:12px; padding:14px; background:var(--surface); }
    .pg-sec-head { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:10px; }
    .pg-sec-title { font-size:14.5px; font-weight:700; color:var(--ink-900); display:flex; align-items:center; gap:7px; }
    .pg-sec-count { font-size:12.5px; font-weight:700; color:var(--accent-text); background:var(--accent-soft); padding:3px 10px; border-radius:12px; }
    .pg-branches-bar { display:flex; gap:6px; flex-wrap:wrap; align-items:center; margin-bottom:10px; }

    .pg-warn-banner { background:var(--warn-soft); color:var(--warn); border:1px solid var(--warn);
                      border-radius:10px; padding:10px 14px; font-size:13px; font-weight:600;
                      margin-bottom:14px; display:flex; align-items:center; gap:8px; }

    .pg-empty { text-align:center; color:var(--ink-400); padding:20px 12px; font-size:13.5px; }

    .pill { display:inline-block; padding:4px 13px; border-radius:20px; font-size:12.5px;
            font-weight:700; white-space:nowrap; }
    .pill.st-open { background:var(--accent-soft); color:var(--accent-text); }
    .pill.st-closed { background:var(--pos-soft); color:var(--pos); }
</style>

<?= book_tabs_bar('pgroup') ?>

<?php if ($groupView !== null): ?>
    <!-- ===================================================================== -->
    <!--  المرحلة (٤): عرض مجموعة الشراء — شكل Google Keep                    -->
    <!-- ===================================================================== -->
    <?php
        $grp          = $groupView['group'];
        $grpLines     = $groupView['lines'];
        $grpTitle     = $grp['name'] ? $grp['name'] : ('مجموعة ' . $grp['id']);
        $activeLines  = [];
        $checkedLines = [];

        foreach ($grpLines as $ln) {
            if ((int)$ln['is_checked'] === 1) {
                $checkedLines[] = $ln;
            } else {
                $activeLines[] = $ln;
            }
        }

        /**
         * رندر سطر صنف في شكل Keep
         */
        function render_keep_line(array $l, bool $isChecked): string
        {
            $id           = (int) $l['id'];
            $name         = !empty($l['item_name']) ? $l['item_name'] : $l['code'];
            $code         = !empty($l['item_name']) ? $l['code'] : '';
            $qtyFormatted = rtrim(rtrim(number_format((float)$l['total_qty'], 2, '.', ''), '0'), '.');

            // تجميع المصادر حسب الفرع
            $sourcesByBranch = [];
            foreach ($l['sources'] as $s) {
                $bName = $s['branch_name'] ?: '—';
                if (!isset($sourcesByBranch[$bName])) {
                    $sourcesByBranch[$bName] = [
                        'branch_name' => $bName,
                        'total_qty'   => 0.0,
                        'items'       => [],
                    ];
                }
                $sourcesByBranch[$bName]['total_qty'] += (float)$s['qty'];
                $sourcesByBranch[$bName]['items'][] = $s;
            }

            ob_start();
            ?>
            <div class="keep-row <?= $isChecked ? 'is-checked' : '' ?>" id="line-<?= $id ?>" data-id="<?= $id ?>" draggable="<?= $isChecked ? 'false' : 'true' ?>">
                <div class="keep-row-main">
                    <span class="keep-drag-handle" title="اسحب للترتيب">⠿</span>
                    <label class="keep-cb-wrap">
                        <input type="checkbox" class="keep-cb-input" <?= $isChecked ? 'checked' : '' ?> onchange="onLineToggle(<?= $id ?>, this.checked)">
                        <span class="keep-cb-box"><i class="ti ti-check"></i></span>
                    </label>
                    <div class="keep-item-info" onclick="toggleSources(<?= $id ?>)" title="اضغط لعرض تفاصيل المصادر">
                        <span class="keep-item-name"><?= e($name) ?></span>
                        <?php if ($code !== ''): ?>
                            <span class="keep-item-code"><?= e($code) ?></span>
                        <?php endif; ?>
                    </div>
                    <span class="keep-qty" onclick="toggleSources(<?= $id ?>)"><?= $qtyFormatted ?></span>
                    <button type="button" class="keep-btn-del" onclick="onLineRemove(<?= $id ?>)" title="مسح">&times;</button>
                </div>

                <div class="keep-sources" id="sources-<?= $id ?>" style="display:none;">
                    <div class="keep-src-inner">
                        <?php if (empty($sourcesByBranch)): ?>
                            <div class="keep-src-empty">مفيش تفاصيل مصادر مسجّلة</div>
                        <?php else: ?>
                            <?php foreach ($sourcesByBranch as $sb): ?>
                                <?php
                                    $bQty = rtrim(rtrim(number_format($sb['total_qty'], 2, '.', ''), '0'), '.');
                                    $isSingleShortage = (count($sb['items']) === 1 && $sb['items'][0]['src_type'] === 'shortage');
                                ?>
                                <?php if ($isSingleShortage): ?>
                                    <div class="keep-src-branch-title">
                                        <span class="keep-src-icon"><i class="ti ti-shopping-cart"></i></span>
                                        <span>نواقص · <?= e($sb['branch_name']) ?></span>
                                        <span class="keep-src-arrow">→</span>
                                        <strong class="keep-src-val"><?= $bQty ?></strong>
                                    </div>
                                <?php else: ?>
                                    <div class="keep-src-branch-block">
                                        <div class="keep-src-branch-title">
                                            <span class="keep-src-icon"><i class="ti ti-building-store"></i></span>
                                            <span><?= e($sb['branch_name']) ?></span>
                                            <span class="keep-src-arrow">→</span>
                                            <strong class="keep-src-val"><?= $bQty ?></strong>
                                        </div>
                                        <div class="keep-src-sub-list">
                                            <?php
                                            $itemCount = count($sb['items']);
                                            foreach ($sb['items'] as $sIdx => $sItem):
                                                $isLast = ($sIdx === $itemCount - 1);
                                                $treeChar = $isLast ? '└' : '├';
                                                $itemQty = rtrim(rtrim(number_format((float)$sItem['qty'], 2, '.', ''), '0'), '.');
                                            ?>
                                                <div class="keep-src-sub-item">
                                                    <span class="keep-tree-char"><?= $treeChar ?></span>
                                                    <?php if ($sItem['src_type'] === 'reservation'): ?>
                                                        <span>حجز <?= e($sItem['customer_name'] ?: 'عميل') ?></span>
                                                        <span class="keep-src-dash">—</span>
                                                        <strong class="keep-src-sub-qty"><?= $itemQty ?></strong>
                                                    <?php else: ?>
                                                        <span>نواقص</span>
                                                        <span class="keep-src-dash">—</span>
                                                        <strong class="keep-src-sub-qty"><?= $itemQty ?></strong>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php
            return (string) ob_get_clean();
        }
    ?>

    <div class="keep-view-wrap">
        <div class="keep-top-nav">
            <a href="purchase_groups.php" class="keep-back-link">
                <i class="ti ti-arrow-right"></i> كل مجموعات الشراء
            </a>
            <span id="keepSaveIndicator" class="keep-save-toast" style="display:none;"></span>
        </div>

        <div class="keep-card">
            <!-- الهيدر: عنوان الملاحظة قابل للتعديل + قائمة ⋮ -->
            <div class="keep-header">
                <input type="text" id="groupTitleInput" class="keep-title-input"
                       value="<?= e($grpTitle) ?>" placeholder="اسم المجموعة..."
                       data-original="<?= e($grpTitle) ?>"
                       onblur="saveGroupName()"
                       onkeydown="if(event.key==='Enter'){this.blur();}">

                <div class="keep-menu-wrap">
                    <button type="button" class="keep-menu-btn" onclick="toggleKeepMenu(event)" title="خيارات" aria-label="خيارات">
                        ⋮
                    </button>
                    <div class="keep-dropdown" id="keepDropdown">
                        <button type="button" class="keep-dropdown-item" onclick="uncheckAll()">
                            <i class="ti ti-checklist"></i> شيل التشييك عن الكل
                        </button>
                        <button type="button" class="keep-dropdown-item keep-item-danger" onclick="deleteChecked()">
                            <i class="ti ti-trash"></i> امسح المتشحّط
                        </button>
                    </div>
                </div>
            </div>

            <!-- بيانات الميتاداتا -->
            <div class="keep-meta">
                <span><i class="ti ti-calendar"></i> <?= e(date('Y-m-d H:i', strtotime((string)$grp['created_at']))) ?></span>
                <?php if (!empty($grp['creator'])): ?>
                    <span><i class="ti ti-user-check"></i> أنشأها: <?= e($grp['creator']) ?></span>
                <?php endif; ?>
                <span class="pill <?= $grp['status'] === 'open' ? 'st-open' : 'st-closed' ?>">
                    <?= $grp['status'] === 'open' ? 'مفتوحة' : 'مغلقة' ?>
                </span>
            </div>

            <!-- قائمة الأصناف غير المتشحّطة (فوق) -->
            <div class="keep-list" id="activeList">
                <?php if (empty($activeLines) && empty($checkedLines)): ?>
                    <div class="keep-empty-msg" id="emptyMsg">المجموعة مفيهاش سطور</div>
                <?php else: ?>
                    <?php foreach ($activeLines as $al): ?>
                        <?= render_keep_line($al, false) ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- قسم الأصناف المتشحّطة (مطوي تحت) -->
            <div class="keep-checked-section" id="checkedSection" style="<?= count($checkedLines) > 0 ? '' : 'display:none;' ?>">
                <div class="keep-checked-header" onclick="toggleCheckedCollapse()">
                    <i class="ti ti-chevron-down keep-chevron" id="checkedChevron"></i>
                    <span id="checkedCountLabel"><?= count($checkedLines) ?> أصناف متشحّطة</span>
                </div>
                <div class="keep-list keep-list-checked" id="checkedList">
                    <?php foreach ($checkedLines as $cl): ?>
                        <?= render_keep_line($cl, true) ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
        const GROUP_ID   = <?= (int)$grp['id'] ?>;

        // ===== التشييك / شيل التشييك =====
        function onLineToggle(lineId, isChecked) {
            const row = document.getElementById(`line-${lineId}`);
            if (!row) return;

            const activeList  = document.getElementById('activeList');
            const checkedList = document.getElementById('checkedList');

            if (isChecked) {
                row.classList.add('is-checked');
                row.setAttribute('draggable', 'false');
                checkedList.appendChild(row);
            } else {
                row.classList.remove('is-checked');
                row.setAttribute('draggable', 'true');
                activeList.appendChild(row);
            }

            updateCheckedCounters();

            fetch('purchase_groups.php?ajax=toggle', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json; charset=utf-8' },
                body: JSON.stringify({
                    csrf: CSRF_TOKEN,
                    group_id: GROUP_ID,
                    line_id: lineId,
                    is_checked: isChecked ? 1 : 0
                })
            })
            .then(res => res.json())
            .then(data => {
                if (!data.ok) {
                    alert(data.error || 'حصل خطأ أثناء تحديث السطر.');
                    location.reload();
                }
            })
            .catch(err => {
                alert('فشل الاتصال بالسيرفر.');
                location.reload();
            });
        }

        // ===== مسح سطر =====
        function onLineRemove(lineId) {
            const row = document.getElementById(`line-${lineId}`);
            if (!row) return;

            const isChecked = row.classList.contains('is-checked');
            if (isChecked) {
                if (!confirm('الصنف ده متشحّط (اتشرى فعلاً) — متأكد إنك عايز تمسحه من المجموعة؟')) {
                    return;
                }
            }

            row.style.opacity = '0.3';

            fetch('purchase_groups.php?ajax=remove', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json; charset=utf-8' },
                body: JSON.stringify({
                    csrf: CSRF_TOKEN,
                    group_id: GROUP_ID,
                    line_id: lineId
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.ok) {
                    row.remove();
                    updateCheckedCounters();
                    checkEmptyState();
                } else {
                    row.style.opacity = '1';
                    alert(data.error || 'حصل خطأ أثناء مسح السطر.');
                }
            })
            .catch(err => {
                row.style.opacity = '1';
                alert('فشل الاتصال بالسيرفر.');
            });
        }

        // ===== شيل التشييك عن الكل =====
        function uncheckAll() {
            closeKeepMenu();
            const checkedRows = Array.from(document.querySelectorAll('#checkedList .keep-row'));
            if (checkedRows.length === 0) return;

            const activeList = document.getElementById('activeList');
            checkedRows.forEach(row => {
                row.classList.remove('is-checked');
                row.setAttribute('draggable', 'true');
                const cb = row.querySelector('.keep-cb-input');
                if (cb) cb.checked = false;
                activeList.appendChild(row);
            });

            updateCheckedCounters();

            fetch('purchase_groups.php?ajax=uncheck_all', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json; charset=utf-8' },
                body: JSON.stringify({
                    csrf: CSRF_TOKEN,
                    group_id: GROUP_ID
                })
            })
            .then(res => res.json())
            .then(data => {
                if (!data.ok) {
                    alert(data.error || 'حصل خطأ أثناء شيل التشييك.');
                    location.reload();
                }
            })
            .catch(err => {
                alert('فشل الاتصال بالسيرفر.');
                location.reload();
            });
        }

        // ===== مسح المتشحّط =====
        function deleteChecked() {
            closeKeepMenu();
            const checkedRows = Array.from(document.querySelectorAll('#checkedList .keep-row'));
            if (checkedRows.length === 0) {
                alert('مفيش أصناف متشحّطة للمسح.');
                return;
            }

            if (!confirm(`هتمسح ${checkedRows.length} صنف متشحّط — متأكد؟`)) {
                return;
            }

            fetch('purchase_groups.php?ajax=delete_checked', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json; charset=utf-8' },
                body: JSON.stringify({
                    csrf: CSRF_TOKEN,
                    group_id: GROUP_ID
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.ok) {
                    checkedRows.forEach(r => r.remove());
                    updateCheckedCounters();
                    checkEmptyState();
                } else {
                    alert(data.error || 'حصل خطأ أثناء مسح المتشحّط.');
                }
            })
            .catch(err => {
                alert('فشل الاتصال بالسيرفر.');
            });
        }

        // ===== حفظ اسم المجموعة في المكان =====
        function saveGroupName() {
            const input = document.getElementById('groupTitleInput');
            if (!input) return;
            const newName = input.value.trim();
            const orig    = input.getAttribute('data-original') || '';

            if (newName === orig) return;

            showIndicator('جاري الحفظ...');

            fetch('purchase_groups.php?ajax=rename', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json; charset=utf-8' },
                body: JSON.stringify({
                    csrf: CSRF_TOKEN,
                    group_id: GROUP_ID,
                    name: newName
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.ok) {
                    input.setAttribute('data-original', newName);
                    showIndicator('✓ اتحفظ', true);
                } else {
                    showIndicator('✗ فشل الحفظ', false);
                    alert(data.error || 'فشل حفظ الاسم.');
                }
            })
            .catch(err => {
                showIndicator('✗ مفيش اتصال', false);
            });
        }

        function showIndicator(text, isOk) {
            const el = document.getElementById('keepSaveIndicator');
            if (!el) return;
            el.textContent = text;
            el.style.display = 'inline-flex';
            el.style.color = (isOk === false) ? 'var(--neg)' : 'var(--pos)';
            el.style.background = (isOk === false) ? 'var(--neg-soft)' : 'var(--pos-soft)';
            if (isOk !== undefined) {
                setTimeout(() => { el.style.display = 'none'; }, 2200);
            }
        }

        // ===== فتح/غلق تفاصيل المصادر =====
        function toggleSources(lineId) {
            const box = document.getElementById(`sources-${lineId}`);
            if (!box) return;
            box.style.display = (box.style.display === 'none' || box.style.display === '') ? 'block' : 'none';
        }

        // ===== طي وفرد قسم المتشحّط =====
        function toggleCheckedCollapse() {
            const list    = document.getElementById('checkedList');
            const chevron = document.getElementById('checkedChevron');
            if (!list || !chevron) return;

            if (list.style.display === 'none') {
                list.style.display = 'flex';
                chevron.classList.remove('collapsed');
            } else {
                list.style.display = 'none';
                chevron.classList.add('collapsed');
            }
        }

        // ===== تحديث العدادات وقسم المتشحّط =====
        function updateCheckedCounters() {
            const checkedRows = document.querySelectorAll('#checkedList .keep-row');
            const count       = checkedRows.length;
            const sec         = document.getElementById('checkedSection');
            const label       = document.getElementById('checkedCountLabel');

            if (sec) {
                sec.style.display = count > 0 ? 'block' : 'none';
            }
            if (label) {
                label.textContent = `${count} أصناف متشحّطة`;
            }
        }

        function checkEmptyState() {
            const activeRows  = document.querySelectorAll('#activeList .keep-row');
            const checkedRows = document.querySelectorAll('#checkedList .keep-row');
            const activeList  = document.getElementById('activeList');

            if (activeRows.length === 0 && checkedRows.length === 0) {
                if (!document.getElementById('emptyMsg')) {
                    const msg = document.createElement('div');
                    msg.id = 'emptyMsg';
                    msg.className = 'keep-empty-msg';
                    msg.textContent = 'المجموعة مفيهاش سطور';
                    activeList.appendChild(msg);
                }
            } else {
                const msg = document.getElementById('emptyMsg');
                if (msg) msg.remove();
            }
        }

        // ===== قائمة ⋮ =====
        function toggleKeepMenu(ev) {
            ev.stopPropagation();
            const dd = document.getElementById('keepDropdown');
            if (dd) dd.classList.toggle('show');
        }

        function closeKeepMenu() {
            const dd = document.getElementById('keepDropdown');
            if (dd) dd.classList.remove('show');
        }

        document.addEventListener('click', function(ev) {
            if (!ev.target.closest('.keep-menu-wrap')) {
                closeKeepMenu();
            }
        });

        // ===== الترتيب بالسحب (Drag & Drop) =====
        let draggedRow = null;

        function initDragAndDrop() {
            const activeList = document.getElementById('activeList');
            if (!activeList) return;

            activeList.addEventListener('dragstart', function(ev) {
                const row = ev.target.closest('.keep-row');
                if (!row || row.classList.contains('is-checked')) return;
                draggedRow = row;
                row.classList.add('dragging');
                ev.dataTransfer.effectAllowed = 'move';
                ev.dataTransfer.setData('text/plain', row.getAttribute('data-id'));
            });

            activeList.addEventListener('dragend', function(ev) {
                const row = ev.target.closest('.keep-row');
                if (row) row.classList.remove('dragging');
                document.querySelectorAll('.keep-row').forEach(r => r.classList.remove('drag-over'));
                draggedRow = null;
            });

            activeList.addEventListener('dragover', function(ev) {
                ev.preventDefault();
                ev.dataTransfer.dropEffect = 'move';
                const targetRow = ev.target.closest('.keep-row');
                if (!targetRow || targetRow === draggedRow || targetRow.classList.contains('is-checked')) return;
                document.querySelectorAll('.keep-row').forEach(r => r.classList.remove('drag-over'));
                targetRow.classList.add('drag-over');
            });

            activeList.addEventListener('dragleave', function(ev) {
                const targetRow = ev.target.closest('.keep-row');
                if (targetRow) targetRow.classList.remove('drag-over');
            });

            activeList.addEventListener('drop', function(ev) {
                ev.preventDefault();
                const targetRow = ev.target.closest('.keep-row');
                if (!targetRow || targetRow === draggedRow || !draggedRow) return;
                targetRow.classList.remove('drag-over');

                const rect = targetRow.getBoundingClientRect();
                const midY = rect.top + rect.height / 2;
                if (ev.clientY < midY) {
                    activeList.insertBefore(draggedRow, targetRow);
                } else {
                    activeList.insertBefore(draggedRow, targetRow.nextSibling);
                }

                saveSortOrder();
            });
        }

        function saveSortOrder() {
            const rows = Array.from(document.querySelectorAll('#activeList .keep-row'));
            const ids  = rows.map(r => parseInt(r.getAttribute('data-id'), 10)).filter(id => id > 0);

            if (ids.length === 0) return;

            fetch('purchase_groups.php?ajax=sort', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json; charset=utf-8' },
                body: JSON.stringify({
                    csrf: CSRF_TOKEN,
                    group_id: GROUP_ID,
                    line_ids: ids
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.ok) {
                    showIndicator('✓ ترتيب محفوظ', true);
                }
            })
            .catch(() => {});
        }

        initDragAndDrop();
    </script>

<?php else: ?>
    <!-- ===================================================================== -->
    <!--  شاشة كروت المجموعات المفتوحة + زرار مجموعة جديدة                      -->
    <!-- ===================================================================== -->
    <div class="card" style="margin-bottom:16px;">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;">
            <div>
                <div class="section-title" style="margin-bottom:4px;">
                    <i class="ti ti-stack-2"></i> مجموعات الشراء
                </div>
                <p style="color:var(--ink-500);font-size:13px;margin:0;">
                    تجميع طلبات وحجوزات الفروع لشراء الكميات مجمّعة وتوزيعها وقت الاستلام.
                </p>
            </div>
            <button type="button" class="btn" onclick="openCreateModal()">
                <i class="ti ti-plus"></i> مجموعة جديدة
            </button>
        </div>
    </div>

    <?php if (empty($openGroups)): ?>
        <div class="card">
            <div class="pg-empty" style="padding:36px 20px;line-height:1.9;">
                <i class="ti ti-checklist" style="font-size:36px;display:block;margin-bottom:8px;color:var(--ink-300);"></i>
                مفيش مجموعات شراء مفتوحة حالياً.<br>
                <span style="font-size:13px;color:var(--ink-500);">
                    اضغط «مجموعة جديدة» عشان تجمّع حجوزات وطلبات الفروع في مجموعة واحدة للمشتريات.
                </span>
            </div>
        </div>
    <?php else: ?>
        <div class="grp-cards">
            <?php foreach ($openGroups as $og): ?>
                <?php
                    $pct    = (int)$og['pct'];
                    $col    = $pct >= 100 ? 'var(--pos)' : ($pct > 0 ? 'var(--warn)' : 'var(--ink-400)');
                    $lbl    = $pct >= 100 ? 'مكتملة' : ($pct > 0 ? 'شغل جاري' : 'جديدة');
                    $gTitle = $og['name'] ? $og['name'] : ('مجموعة شراء #' . $og['id']);
                ?>
                <a class="grp-card" href="purchase_groups.php?g=<?= (int)$og['id'] ?>">
                    <div class="gc-top">
                        <span class="gc-title"><?= e($gTitle) ?></span>
                        <span class="gc-state" style="background:<?= $col ?>1a;color:<?= $col ?>;"><?= e($lbl) ?></span>
                    </div>
                    <div class="gc-meta">
                        <span><i class="ti ti-checklist"></i> <?= (int)$og['items_count'] ?> صنف</span>
                        <span><i class="ti ti-calendar"></i> <?= e(date('Y-m-d', strtotime((string)$og['created_at']))) ?></span>
                        <?php if (!empty($og['creator'])): ?>
                            <span><i class="ti ti-user-check"></i> <?= e($og['creator']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="gc-bar"><span style="width:<?= $pct ?>%;background:<?= $col ?>;"></span></div>
                    <div class="gc-foot">
                        <span><?= (int)$og['checked_count'] ?> من <?= (int)$og['items_count'] ?> تم شراؤه</span>
                        <span style="color:<?= $col ?>;font-weight:700;"><?= $pct ?>%</span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- ===================================================================== -->
    <!--  بوب أب تجميع الحجوزات والنواقص                                       -->
    <!-- ===================================================================== -->
    <div class="pg-modal" id="createGroupModal" onclick="if(event.target===this)closeCreateModal()">
        <div class="pg-modal-box">
            <div class="pg-modal-head">
                <div class="pg-modal-title">
                    <i class="ti ti-stack-2"></i> إنشاء مجموعة شراء جديدة
                </div>
                <button type="button" class="pg-modal-close" onclick="closeCreateModal()">&times;</button>
            </div>

            <div class="pg-modal-body">
                <?php if ($totalCandidates > 500): ?>
                    <div class="pg-warn-banner">
                        <i class="ti ti-alert-triangle"></i> فيه <?= $totalCandidates ?> سطر متاح — ممكن الشاشة تتقل
                    </div>
                <?php endif; ?>

                <div style="margin-bottom:16px;">
                    <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:var(--ink-800);">اسم المجموعة (اختياري)</label>
                    <input type="text" id="grpNameInput" placeholder="مثال: طلبية السبت سلاح التلميذ والمعاصر"
                           style="width:100%;padding:10px 14px;border:1.5px solid var(--line);border-radius:10px;font-family:inherit;font-size:14px;box-sizing:border-box;">
                </div>

                <!-- (١) قسم الحجوزات -->
                <div class="pg-sec" id="secReservations">
                    <div class="pg-sec-head">
                        <div class="pg-sec-title">
                            <i class="ti ti-user-check"></i> الحجوزات المتاحة
                        </div>
                        <div class="pg-sec-count" id="countRes">محدد: 0 حجز</div>
                    </div>

                    <?php if (!empty($branches)): ?>
                        <div class="pg-branches-bar">
                            <span style="font-size:12px;color:var(--ink-500);margin-left:4px;">تحديد فرع:</span>
                            <?php foreach ($branches as $b): ?>
                                <button type="button" class="btn-branch btn-b-res" data-branch="<?= (int)$b['id'] ?>"
                                        onclick="toggleBranchSelection('res', <?= (int)$b['id'] ?>, this)">
                                    <?= e($b['name']) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (empty($candidates['reservations'])): ?>
                        <div class="pg-empty">مفيش حجوزات متاحة للتجميع</div>
                    <?php else: ?>
                        <div style="overflow-x:auto;max-height:260px;">
                            <table class="pg-table">
                                <thead>
                                    <tr>
                                        <th style="width:40px;"></th>
                                        <th class="cd" style="width:130px;">كود الصنف</th>
                                        <th class="nm">اسم الصنف</th>
                                        <th style="width:120px;">الفرع</th>
                                        <th style="width:130px;">العميل</th>
                                        <th style="width:90px;">الباقي</th>
                                        <th style="width:120px;">تاريخ الحجز</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($candidates['reservations'] as $r):
                                        $remQty = (float)$r['qty'] - (float)$r['allocated_qty'];
                                        $bId    = (int)($r['branch_id'] ?? 0);
                                    ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" class="res-cb" name="res_lines[]"
                                                       value="<?= (int)$r['id'] ?>" data-branch="<?= $bId ?>"
                                                       onchange="onCheckboxChange()">
                                            </td>
                                            <td class="cd"><?= e($r['code']) ?></td>
                                            <td class="nm"><?= e($r['item_name'] ?: '—') ?></td>
                                            <td><?= e($r['branch_name'] ?: '—') ?></td>
                                            <td><?= e($r['customer_name'] ?: '—') ?></td>
                                            <td><strong><?= rtrim(rtrim(number_format($remQty, 2, '.', ''), '0'), '.') ?></strong></td>
                                            <td><?= e(date('Y-m-d', strtotime((string)$r['created_at']))) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- (٢) قسم طلبات النواقص -->
                <div class="pg-sec" id="secShortages">
                    <div class="pg-sec-head">
                        <div class="pg-sec-title">
                            <i class="ti ti-shopping-cart"></i> طلبات النواقص (للمشتريات)
                        </div>
                        <div class="pg-sec-count" id="countShort">محدد: 0 طلب</div>
                    </div>

                    <?php if (!empty($branches)): ?>
                        <div class="pg-branches-bar">
                            <span style="font-size:12px;color:var(--ink-500);margin-left:4px;">تحديد فرع:</span>
                            <?php foreach ($branches as $b): ?>
                                <button type="button" class="btn-branch btn-b-short" data-branch="<?= (int)$b['id'] ?>"
                                        onclick="toggleBranchSelection('short', <?= (int)$b['id'] ?>, this)">
                                    <?= e($b['name']) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (empty($candidates['shortages'])): ?>
                        <div class="pg-empty">مفيش طلبات نواقص متاحة للتجميع</div>
                    <?php else: ?>
                        <div style="overflow-x:auto;max-height:260px;">
                            <table class="pg-table">
                                <thead>
                                    <tr>
                                        <th style="width:40px;"></th>
                                        <th class="cd" style="width:130px;">كود الصنف</th>
                                        <th class="nm">اسم الصنف</th>
                                        <th style="width:120px;">الفرع</th>
                                        <th style="width:90px;">الكمية</th>
                                        <th style="width:120px;">تاريخ الطلب</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($candidates['shortages'] as $s):
                                        $bId = (int)($s['branch_id'] ?? 0);
                                    ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" class="short-cb" name="short_lines[]"
                                                       value="<?= (int)$s['id'] ?>" data-branch="<?= $bId ?>"
                                                       onchange="onCheckboxChange()">
                                            </td>
                                            <td class="cd"><?= e($s['code']) ?></td>
                                            <td class="nm"><?= e($s['item_name'] ?: '—') ?></td>
                                            <td><?= e($s['branch_name'] ?: '—') ?></td>
                                            <td><strong><?= rtrim(rtrim(number_format((float)$s['qty'], 2, '.', ''), '0'), '.') ?></strong></td>
                                            <td><?= e(date('Y-m-d', strtotime((string)$s['created_at']))) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="pg-modal-foot">
                <button type="button" class="btn btn-secondary" onclick="closeCreateModal()">إلغاء</button>
                <button type="button" class="btn" id="btnSubmitGroup" onclick="submitCreateGroup()">
                    <i class="ti ti-stack-2"></i> تجميع المحدد
                </button>
            </div>
        </div>
    </div>

    <script>
        const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;

        function openCreateModal() {
            const m = document.getElementById('createGroupModal');
            if (m) m.classList.add('on');
            updateCounters();
        }

        function closeCreateModal() {
            const m = document.getElementById('createGroupModal');
            if (m) m.classList.remove('on');
        }

        function toggleBranchSelection(type, branchId, btnEl) {
            const selector = type === 'res'
                ? `.res-cb[data-branch="${branchId}"]`
                : `.short-cb[data-branch="${branchId}"]`;
            const boxes = document.querySelectorAll(selector);
            if (!boxes.length) return;

            // إذا كان الكل محدد، شيل التحديد. وإلا حدد الكل
            let allChecked = true;
            boxes.forEach(cb => { if (!cb.checked) allChecked = false; });

            boxes.forEach(cb => { cb.checked = !allChecked; });

            if (btnEl) {
                btnEl.classList.toggle('active', !allChecked);
            }

            updateCounters();
        }

        function onCheckboxChange() {
            updateCounters();
            updateBranchButtonsState('res');
            updateBranchButtonsState('short');
        }

        function updateBranchButtonsState(type) {
            const btnClass = type === 'res' ? '.btn-b-res' : '.btn-b-short';
            const cbClass  = type === 'res' ? '.res-cb' : '.short-cb';

            document.querySelectorAll(btnClass).forEach(btn => {
                const bId = btn.getAttribute('data-branch');
                const boxes = document.querySelectorAll(`${cbClass}[data-branch="${bId}"]`);
                if (!boxes.length) {
                    btn.classList.remove('active');
                    return;
                }
                let allChecked = true;
                boxes.forEach(cb => { if (!cb.checked) allChecked = false; });
                btn.classList.toggle('active', allChecked);
            });
        }

        function updateCounters() {
            const resBoxes   = document.querySelectorAll('.res-cb:checked');
            const shortBoxes = document.querySelectorAll('.short-cb:checked');

            const countResEl   = document.getElementById('countRes');
            const countShortEl = document.getElementById('countShort');

            if (countResEl)   countResEl.textContent   = `محدد: ${resBoxes.length} حجز`;
            if (countShortEl) countShortEl.textContent = `محدد: ${shortBoxes.length} طلب`;
        }

        function submitCreateGroup() {
            const resBoxes   = Array.from(document.querySelectorAll('.res-cb:checked')).map(cb => parseInt(cb.value, 10));
            const shortBoxes = Array.from(document.querySelectorAll('.short-cb:checked')).map(cb => parseInt(cb.value, 10));

            if (resBoxes.length === 0 && shortBoxes.length === 0) {
                alert('اختار سطر واحد على الأقل للتجميع.');
                return;
            }

            const btn = document.getElementById('btnSubmitGroup');
            if (btn) { btn.disabled = true; btn.textContent = 'جاري التجميع...'; }

            const nameInput = document.getElementById('grpNameInput');
            const grpName   = nameInput ? nameInput.value.trim() : '';

            fetch('purchase_groups.php?ajax=create', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json; charset=utf-8' },
                body: JSON.stringify({
                    csrf: CSRF_TOKEN,
                    name: grpName,
                    res_line_ids: resBoxes,
                    short_line_ids: shortBoxes
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.ok) {
                    window.location.href = `purchase_groups.php?g=${data.id}`;
                } else {
                    alert(data.error || 'حصل خطأ أثناء إنشاء المجموعة.');
                    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="ti ti-stack-2"></i> تجميع المحدد'; }
                }
            })
            .catch(err => {
                alert('فشل الاتصال بالسيرفر.');
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="ti ti-stack-2"></i> تجميع المحدد'; }
            });
        }

        document.addEventListener('keydown', function(ev) {
            if (ev.key === 'Escape') closeCreateModal();
        });
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
