<?php
/**
 * purchase_groups.php — شاشة مجموعات الشراء وتجميع الحجوزات
 * نظام الفجالة ستور المالي
 *
 * المراحل:
 *   - المرحلة (٣): إنشاء مجموعة شراء + كروت المجموعات + استعراض السطور
 *   - المرحلة (٤): تحويل استعراض المجموعة لواجهة تفاعلية شبيهة بـ Google Keep
 */
require_once __DIR__ . '/auth.php';
require_permission('shortage');
require_once __DIR__ . '/book_lib.php';
require_once __DIR__ . '/purchase_group_lib.php';

$uid           = (int) current_user()['id'];
$active_module = 'shortage';

// =====================================================================
//  معالج AJAX لإنشاء مجموعة شراء جديدة
// =====================================================================
if (($_GET['ajax'] ?? '') === 'create') {
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

        $resIds   = (array) ($body['res_line_ids'] ?? []);
        $shortIds = (array) ($body['short_line_ids'] ?? []);
        $name     = (string) ($body['name'] ?? '');

        $skipped = 0;
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
    } catch (Throwable $ex) {
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$viewGroupId = (int) ($_GET['g'] ?? 0);
$groupView   = $viewGroupId > 0 ? purchase_group_get($viewGroupId) : null;

// بيانات شاشة الكروت وبوب أب التجميع
$openGroups      = purchase_groups_list('open');
$candidates      = purchase_group_candidates();
$branches        = db()->query('SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name')->fetchAll();
$totalCandidates = count($candidates['reservations']) + count($candidates['shortages']);

$page_title = 'تجميع الحجوزات';
require_once __DIR__ . '/includes/header.php';
?>
<style>
    .btn { background:var(--accent); color:#fff; border:none; padding:11px 20px; border-radius:10px;
        font-size:14px; font-family:inherit; cursor:pointer; display:inline-flex; align-items:center;
        gap:7px; text-decoration:none; white-space:nowrap; }
    .btn:hover { filter:brightness(0.94); color:#fff; }
    .btn:disabled { opacity:.55; cursor:not-allowed; }

    .btn-secondary { background:var(--surface); color:var(--ink-700); border:1.5px solid var(--line); }
    .btn-secondary:hover { background:var(--line-soft); color:var(--ink-700); }

    .btn-branch { background:var(--surface); color:var(--ink-700); border:1.5px solid var(--line);
                  border-radius:20px; padding:4px 12px; font-size:12.5px; font-weight:600; cursor:pointer;
                  transition:all .15s; font-family:inherit; }
    .btn-branch:hover { background:var(--line-soft); color:var(--ink-700); }
    .btn-branch.active { background:var(--accent); color:#fff; border-color:var(--accent); }
    .btn-branch.active:hover { filter:brightness(0.94); color:#fff; }

    /* كروت المجموعات */
    .grp-cards { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:14px; }
    .grp-card { display:block; background:#fff; border:1.5px solid var(--line); border-radius:14px;
        padding:16px 18px; text-decoration:none; color:inherit; transition:all .15s; }
    .grp-card:hover { border-color:var(--accent); transform:translateY(-2px);
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

    /* تفاصيل المجموعة المفردة */
    .pg-detail-head { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px; }
    .pg-table { width:100%; border-collapse:collapse; }
    .pg-table th, .pg-table td { padding:10px 12px; border-bottom:1px solid var(--line-soft);
                                 text-align:center; font-size:13.5px; }
    .pg-table th { background:var(--line-soft); color:var(--accent-hover); font-weight:600; }
    .pg-table .nm { text-align:right; font-weight:500; min-width:200px; }
    .pg-table .cd { font-family:monospace; color:var(--ink-500); font-size:12px; }

    /* بوب أب التجميع */
    .pg-modal { position:fixed; inset:0; background:rgba(22,35,61,0.6); z-index:300;
                display:none; align-items:center; justify-content:center; padding:16px; }
    .pg-modal.on { display:flex; }
    .pg-modal-box { background:#fff; border-radius:14px; width:100%; max-width:960px;
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
    .pg-sec-count { font-size:12.5px; font-weight:700; color:var(--accent); background:var(--accent-soft); padding:3px 10px; border-radius:12px; }
    .pg-branches-bar { display:flex; gap:6px; flex-wrap:wrap; align-items:center; margin-bottom:10px; }

    .pg-warn-banner { background:var(--warn-soft); color:var(--warn); border:1px solid #e8dcc0;
                      border-radius:10px; padding:10px 14px; font-size:13px; font-weight:600;
                      margin-bottom:14px; display:flex; align-items:center; gap:8px; }

    .pg-empty { text-align:center; color:var(--ink-400); padding:20px 12px; font-size:13.5px; }

    .pill { display:inline-block; padding:4px 13px; border-radius:20px; font-size:12.5px;
            font-weight:700; white-space:nowrap; }
    .pill.st-open { background:var(--accent-soft); color:var(--accent); }
    .pill.st-closed { background:var(--pos-soft); color:var(--pos); }
</style>

<?= book_tabs_bar('pgroup') ?>

<?php if ($groupView !== null): ?>
    <!-- ===================================================================== -->
    <!--  عرض مجموعة شراء محددة (مرحلة بسيطة — والتحويل لـ Keep مرحلة ٤)      -->
    <!-- ===================================================================== -->
    <?php
        $grp      = $groupView['group'];
        $grpLines = $groupView['lines'];
        $grpTitle = $grp['name'] ? $grp['name'] : ('مجموعة شراء #' . $grp['id']);
    ?>
    <div class="card" style="margin-bottom:16px;">
        <div class="pg-detail-head">
            <div>
                <a href="purchase_groups.php" style="display:inline-flex;align-items:center;gap:6px;color:var(--ink-500);text-decoration:none;font-size:13px;margin-bottom:6px;">
                    <i class="ti ti-arrow-right"></i> كل مجموعات الشراء
                </a>
                <div class="section-title" style="margin-bottom:4px;">
                    <i class="ti ti-stack-2"></i> <?= e($grpTitle) ?>
                </div>
                <div style="font-size:13px;color:var(--ink-500);display:flex;gap:16px;flex-wrap:wrap;margin-top:6px;">
                    <span><i class="ti ti-calendar"></i> <?= e(date('Y-m-d H:i', strtotime((string)$grp['created_at']))) ?></span>
                    <?php if (!empty($grp['creator'])): ?>
                        <span><i class="ti ti-user-check"></i> أنشأها: <?= e($grp['creator']) ?></span>
                    <?php endif; ?>
                    <span>الحالة: <span class="pill st-<?= e($grp['status']) ?>"><?= $grp['status'] === 'open' ? 'مفتوحة' : 'مغلقة' ?></span></span>
                </div>
            </div>
            <div>
                <span style="font-size:14px;font-weight:700;color:var(--accent);background:var(--accent-soft);padding:6px 14px;border-radius:10px;">
                    إجمالي الأصناف: <?= count($grpLines) ?>
                </span>
            </div>
        </div>
    </div>

    <div class="card">
        <?php if (empty($grpLines)): ?>
            <div class="pg-empty">المجموعة مفيهاش سطور</div>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="pg-table">
                    <thead>
                        <tr>
                            <th style="width:60px;">#</th>
                            <th class="cd" style="width:150px;">كود الصنف</th>
                            <th class="nm">اسم الصنف</th>
                            <th style="width:120px;">إجمالي الكمية</th>
                            <th style="width:130px;">الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($grpLines as $idx => $gl): ?>
                            <tr>
                                <td><?= $idx + 1 ?></td>
                                <td class="cd"><?= e($gl['code']) ?></td>
                                <td class="nm"><?= e($gl['item_name'] ?: '—') ?></td>
                                <td><strong><?= rtrim(rtrim(number_format((float)$gl['total_qty'], 2, '.', ''), '0'), '.') ?></strong></td>
                                <td>
                                    <?php if ((int)$gl['is_checked'] === 1): ?>
                                        <span class="pill st-closed"><i class="ti ti-check"></i> تم الشراء</span>
                                    <?php else: ?>
                                        <span class="pill st-open">قيد الانتظار</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

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
                    $pct = (int)$og['pct'];
                    $col = $pct >= 100 ? 'var(--pos)' : ($pct > 0 ? 'var(--warn)' : 'var(--ink-400)');
                    $lbl = $pct >= 100 ? 'مكتملة' : ($pct > 0 ? 'شغل جاري' : 'جديدة');
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
