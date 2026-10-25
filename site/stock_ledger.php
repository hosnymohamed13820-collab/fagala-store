<?php
/**
 * stock_ledger.php — دفتر المخزن والمستندات (بريف ٢١٤)
 * نظام الفجالة ستور المالي
 *
 * موديول stock_ledger، أيقونة ti-packages، متاح للمالك فقط.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sensitive_lib.php';
require_once __DIR__ . '/stock_lib.php';
require_once __DIR__ . '/stock_import_lib.php';

require_login();
require_module_access('stock_ledger');

if (!is_real_owner()) {
    http_response_code(403);
    $pageTitle = 'دفتر المخزن';
    $active_module = 'stock_ledger';
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="card"><div class="alert alert-danger">غير مصرح — هذه الصفحة متاحة للمالك فقط.</div></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

sens_page();

$pdo = db();
$stockEnabled = stock_enabled();

// تشغيل كسول للاستيراد إذا كان أقدم من ١٠ دقائق
stock_import_maybe_run($pdo, (int) ($_SESSION['user']['id'] ?? 1), 10);

$tab = $_GET['tab'] ?? 'docs';
if (!in_array($tab, ['docs', 'recon', 'import'], true)) {
    $tab = 'docs';
}

// جلب المخازن المدعومة (١ بونجور · ٢ اي بي سي · ٣ الفجالة · ٤ دجلة · ٦ الإدارة)
$stBranches = $pdo->query('SELECT id, name, sto_id FROM branches WHERE id IN (1, 2, 3, 4, 6) ORDER BY id ASC');
$branches = $stBranches->fetchAll(PDO::FETCH_ASSOC);

$docTypeNames = [
    'opening'         => 'رصيد افتتاحي',
    'adjust'          => 'تسوية مخزنية',
    'transfer'        => 'تحويل مخزني',
    'purchase'        => 'فاتورة شراء',
    'purchase_return' => 'مرتجع شراء',
    'import'          => 'استيراد من True',
];

// معالجة تصدير إكسيل للمطابقة
if ($tab === 'recon' && !empty($_GET['export'])) {
    $reconBranchId = !empty($_GET['branch_id']) ? (int) $_GET['branch_id'] : 3;
    $reconData = stock_recon($pdo, $reconBranchId);
    require_once __DIR__ . '/xlsx_lib.php';
    $x = new SimpleXlsx('مطابقة_' . $reconData['branch_name']);
    $x->setColumnTypes(['s', 's', 'n', 'n', 'n', 'n', 'n', 's', 's']);
    $x->addRow(['كود الصنف', 'اسم الصنف', 'رصيد الدفتر', 'رصيد True', 'فرق القطع', 'متوسط التكلفة', 'قيمة الفرق (ج.م)', 'وحدة غير معروفة؟', 'آخر حركة'], true);
    foreach ($reconData['differences'] as $row) {
        $x->addRow([
            (string) $row['code'],
            (string) $row['name'],
            $row['ledger_qty'],
            $row['true_qty'],
            $row['diff_qty'],
            $row['avg_cost'],
            $row['diff_value'],
            $row['has_unknown_unit'] ? 'نعم' : 'لا',
            $row['last_move_at'] ?: '—',
        ]);
    }
    $x->download('recon_' . $reconBranchId . '_' . date('Ymd_His') . '.xlsx');
    exit;
}

// بيانات تاب المطابقة مع True
$reconBranchId = 3;
$reconCards = [];
$activeRecon = null;
if ($tab === 'recon') {
    $reconBranchId = !empty($_GET['branch_id']) ? (int) $_GET['branch_id'] : 3;
    foreach ($branches as $b) {
        $bId = (int) $b['id'];
        try {
            $rRes = stock_recon($pdo, $bId);
            $reconCards[$bId] = [
                'name'            => $b['name'],
                'mode'            => stock_branch_mode($bId),
                'matched_count'   => $rRes['matched_count'],
                'diff_count'      => $rRes['diff_count'],
                'total_diff_qty'  => $rRes['total_diff_pieces'],
                'true_updated_at' => $rRes['true_updated_at'],
            ];
            if ($bId === $reconBranchId) {
                $activeRecon = $rRes;
            }
        } catch (Throwable $e) {
            $reconCards[$bId] = [
                'name'            => $b['name'],
                'mode'            => stock_branch_mode($bId),
                'matched_count'   => 0,
                'diff_count'      => 0,
                'total_diff_qty'  => 0,
                'true_updated_at' => '—',
                'error'           => $e->getMessage(),
            ];
        }
    }
    if ($activeRecon === null && isset($reconCards[$reconBranchId])) {
        $activeRecon = stock_recon($pdo, $reconBranchId);
    }
}

// بيانات تاب الاستيراد (آخر ٥٠ تشغيل)
$importRuns = [];
if ($tab === 'import') {
    $stRuns = $pdo->query("
        SELECT r.*, u.full_name AS user_name
          FROM stock_import_runs r
          LEFT JOIN users u ON u.id = r.created_by
         ORDER BY r.run_at DESC
         LIMIT 50
    ");
    $importRuns = $stRuns->fetchAll(PDO::FETCH_ASSOC);
}

// بيانات تاب المستندات
$postedOpenings = [];
$docsList = [];
$filterBranch = !empty($_GET['branch_id']) ? (int) $_GET['branch_id'] : 0;
$filterType   = !empty($_GET['type']) ? (string) $_GET['type'] : '';
$filterStatus = !empty($_GET['status']) ? (string) $_GET['status'] : '';
$filterFrom   = !empty($_GET['from']) ? (string) $_GET['from'] : '';
$filterTo     = !empty($_GET['to']) ? (string) $_GET['to'] : '';

if ($tab === 'docs') {
    $stOpenings = $pdo->query("SELECT branch_id, id, doc_no, doc_date FROM stock_docs WHERE doc_type = 'opening' AND status = 'posted'");
    while ($r = $stOpenings->fetch(PDO::FETCH_ASSOC)) {
        $postedOpenings[(int) $r['branch_id']] = $r;
    }

    $where = [];
    $params = [];
    if ($filterBranch > 0) {
        $where[] = 'd.branch_id = ?';
        $params[] = $filterBranch;
    }
    if ($filterType !== '') {
        $where[] = 'd.doc_type = ?';
        $params[] = $filterType;
    }
    if ($filterStatus !== '') {
        $where[] = 'd.status = ?';
        $params[] = $filterStatus;
    }
    if ($filterFrom !== '') {
        $where[] = 'd.doc_date >= ?';
        $params[] = $filterFrom;
    }
    if ($filterTo !== '') {
        $where[] = 'd.doc_date <= ?';
        $params[] = $filterTo;
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $sqlDocs = "
        SELECT d.*, b.name AS branch_name, u.full_name AS created_by_name
          FROM stock_docs d
          LEFT JOIN branches b ON b.id = d.branch_id
          LEFT JOIN users u ON u.id = d.created_by
         $whereSql
         ORDER BY d.doc_date DESC, d.id DESC
         LIMIT 150
    ";
    $stDocs = $pdo->prepare($sqlDocs);
    $stDocs->execute($params);
    $docsList = $stDocs->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'دفتر المخزن';
$active_module = 'stock_ledger';
require_once __DIR__ . '/includes/header.php';
?>

<div class="card" style="margin-bottom:16px;">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <h2 style="margin:0 0 4px 0;"><i class="ti ti-packages"></i> دفتر المخزن على الموقع</h2>
            <div style="font-size:13px;color:var(--text-muted, #888);">
                حركات الصنف والأرصدة المتخزنة ومتوسط التكلفة المتحرك
            </div>
        </div>
        <div>
            <?php if ($stockEnabled): ?>
                <span class="fchip fchip-pos" style="font-size:14px;padding:6px 12px;">
                    <i class="ti ti-check"></i> المخزن على الموقع: مفتوح
                </span>
            <?php else: ?>
                <span class="fchip fchip-warn" style="font-size:14px;padding:6px 12px;">
                    <i class="ti ti-alert-triangle"></i> المخزن على الموقع: مقفول (تجريبي)
                </span>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- شريط التبويبات -->
<div class="inv-tabs" style="display:flex;gap:6px;border-bottom:1px solid var(--line-soft, #ddd);margin-bottom:16px;">
    <a class="tab-btn <?= $tab === 'docs' ? 'active' : '' ?>" href="stock_ledger.php?tab=docs"><i class="ti ti-file-text"></i> مستندات المخزن</a>
    <a class="tab-btn <?= $tab === 'recon' ? 'active' : '' ?>" href="stock_ledger.php?tab=recon"><i class="ti ti-arrows-diff"></i> المطابقة مع True</a>
    <a class="tab-btn <?= $tab === 'import' ? 'active' : '' ?>" href="stock_ledger.php?tab=import"><i class="ti ti-history"></i> الاستيراد</a>
</div>

<?php if ($tab === 'docs'): ?>
<!-- كروت الرصيد الافتتاحي من المرآة -->
<div class="card" style="margin-bottom:16px;">
    <h3 style="margin:0 0 12px 0;"><i class="ti ti-building-warehouse"></i> الرصيد الافتتاحي من المرآة</h3>
    <p style="font-size:13px;color:var(--text-muted, #888);margin-top:0;">
        يمكن إنشاء وترحيل مستند رصيد افتتاحي من مرآة True مرة واحدة فقط لكل مخزن:
    </p>
    <div style="display:flex;flex-wrap:wrap;gap:10px;">
        <?php foreach ($branches as $b): ?>
            <?php $bId = (int) $b['id']; ?>
            <div style="border:1px solid var(--line-soft, #ddd);border-radius:8px;padding:10px 14px;background:var(--bg-surface, #fff);display:flex;align-items:center;gap:10px;">
                <b><?= e($b['name']) ?></b>
                <?php if (isset($postedOpenings[$bId])): ?>
                    <span class="fchip fchip-pos">
                        <i class="ti ti-check"></i> مرحل (مستند #<?= e((string)$postedOpenings[$bId]['doc_no']) ?>)
                    </span>
                <?php else: ?>
                    <button type="button" class="btn btn-sm btn-outline"
                            onclick="triggerOpening(<?= $bId ?>, '<?= e($b['name']) ?>')"
                            <?= !$stockEnabled ? 'disabled title="المخزن مقفول حالياً"' : '' ?>>
                        <i class="ti ti-plus"></i> رصيد افتتاحي
                    </button>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- فورم تسوية مخزنية بسيط للاختبار -->
<div class="card" style="margin-bottom:16px;">
    <h3 style="margin:0 0 12px 0;"><i class="ti ti-adjustments"></i> تسوية مخزنية سريعة (للاختبار)</h3>
    <form id="adjForm" onsubmit="submitAdjust(event)" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
        <div>
            <label style="display:block;font-size:12px;margin-bottom:4px;">المخزن</label>
            <select id="adj_branch" required style="padding:6px 10px;border-radius:6px;border:1px solid var(--line-soft, #ddd);">
                <?php foreach ($branches as $b): ?>
                    <option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="display:block;font-size:12px;margin-bottom:4px;">التاريخ</label>
            <input type="date" id="adj_date" value="<?= date('Y-m-d') ?>" required
                   style="padding:5px 8px;border-radius:6px;border:1px solid var(--line-soft, #ddd);">
        </div>
        <div style="position:relative;">
            <label style="display:block;font-size:12px;margin-bottom:4px;">الباركود / الكود</label>
            <div style="display:flex;gap:4px;">
                <input type="text" id="adj_code" placeholder="أدخل الباركود..." required
                       style="padding:5px 8px;border-radius:6px;border:1px solid var(--line-soft, #ddd);width:150px;">
                <button type="button" class="btn btn-sm btn-ghost" onclick="openBarcodeScanner('adj_code')" title="سكانر الكاميرا">
                    <i class="ti ti-scan"></i>
                </button>
            </div>
        </div>
        <div>
            <label style="display:block;font-size:12px;margin-bottom:4px;">معرّف الصنف (item_id)</label>
            <input type="number" id="adj_item_id" placeholder="رقم الصنف" required
                   style="padding:5px 8px;border-radius:6px;border:1px solid var(--line-soft, #ddd);width:110px;">
        </div>
        <div>
            <label style="display:block;font-size:12px;margin-bottom:4px;">معامل الوحدة</label>
            <input type="number" step="0.0001" id="adj_factor" value="1"
                   style="padding:5px 8px;border-radius:6px;border:1px solid var(--line-soft, #ddd);width:90px;">
        </div>
        <div>
            <label style="display:block;font-size:12px;margin-bottom:4px;">الكمية بالوحدة (+/-)</label>
            <input type="number" step="0.001" id="adj_qty" placeholder="+ زيادة / - عجز" required
                   style="padding:5px 8px;border-radius:6px;border:1px solid var(--line-soft, #ddd);width:130px;">
        </div>
        <div>
            <label style="display:block;font-size:12px;margin-bottom:4px;">التكلفة للقطعة (اختياري)</label>
            <input type="number" step="0.0001" id="adj_cost" placeholder="فارغ = المتوسط"
                   style="padding:5px 8px;border-radius:6px;border:1px solid var(--line-soft, #ddd);width:130px;">
        </div>
        <div>
            <label style="display:block;font-size:12px;margin-bottom:4px;">ملاحظات</label>
            <input type="text" id="adj_notes" placeholder="سبب التسوية..."
                   style="padding:5px 8px;border-radius:6px;border:1px solid var(--line-soft, #ddd);width:150px;">
        </div>
        <div>
            <button type="submit" class="btn btn-primary" <?= !$stockEnabled ? 'disabled title="المخزن مقفول حالياً"' : '' ?>>
                <i class="ti ti-check"></i> حفظ وترحيل التسوية
            </button>
        </div>
    </form>
</div>

<!-- جدول المستندات -->
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
        <h3 style="margin:0;"><i class="ti ti-file-text"></i> مستندات المخزن</h3>
        <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <select name="branch_id" style="padding:4px 8px;border-radius:4px;border:1px solid var(--line-soft, #ddd);">
                <option value="">كل المخازن</option>
                <?php foreach ($branches as $b): ?>
                    <option value="<?= (int) $b['id'] ?>" <?= $filterBranch === (int) $b['id'] ? 'selected' : '' ?>>
                        <?= e($b['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="type" style="padding:4px 8px;border-radius:4px;border:1px solid var(--line-soft, #ddd);">
                <option value="">كل الأنواع</option>
                <?php foreach ($docTypeNames as $k => $lbl): ?>
                    <option value="<?= e($k) ?>" <?= $filterType === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status" style="padding:4px 8px;border-radius:4px;border:1px solid var(--line-soft, #ddd);">
                <option value="">كل الحالات</option>
                <option value="draft" <?= $filterStatus === 'draft' ? 'selected' : '' ?>>مسودة</option>
                <option value="posted" <?= $filterStatus === 'posted' ? 'selected' : '' ?>>مرحل</option>
                <option value="cancelled" <?= $filterStatus === 'cancelled' ? 'selected' : '' ?>>ملغي</option>
            </select>
            <input type="date" name="from" value="<?= e($filterFrom) ?>" style="padding:3px 6px;border-radius:4px;border:1px solid var(--line-soft, #ddd);">
            <input type="date" name="to" value="<?= e($filterTo) ?>" style="padding:3px 6px;border-radius:4px;border:1px solid var(--line-soft, #ddd);">
            <button type="submit" class="btn btn-sm btn-ghost"><i class="ti ti-filter"></i> تصفية</button>
        </form>
    </div>

    <div style="overflow-x:auto;">
        <table class="ftable" id="docsTable">
            <thead>
                <tr>
                    <th class="num">#</th>
                    <th>النوع</th>
                    <th class="num">رقم المستند</th>
                    <th>المخزن</th>
                    <th>التاريخ</th>
                    <th>الحالة</th>
                    <th>ملاحظات</th>
                    <th>بواسطة</th>
                    <th>الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($docsList)): ?>
                    <tr>
                        <td colspan="9" style="text-align:center;color:var(--text-muted, #888);padding:24px;">
                            لا توجد مستندات مخزنية مسجلة
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($docsList as $doc): ?>
                        <tr>
                            <td class="num"><?= (int) $doc['id'] ?></td>
                            <td><?= e($docTypeNames[$doc['doc_type']] ?? $doc['doc_type']) ?></td>
                            <td class="num"><?= $doc['doc_no'] ? e((string)$doc['doc_no']) : '—' ?></td>
                            <td><?= e($doc['branch_name'] ?? ('مخزن ' . $doc['branch_id'])) ?></td>
                            <td><?= e($doc['doc_date']) ?></td>
                            <td>
                                <?php if ($doc['status'] === 'posted'): ?>
                                    <span class="fchip fchip-pos">مرحل</span>
                                <?php elseif ($doc['status'] === 'draft'): ?>
                                    <span class="fchip fchip-warn">مسودة</span>
                                <?php elseif ($doc['status'] === 'cancelled'): ?>
                                    <span class="fchip fchip-neg">ملغي</span>
                                <?php else: ?>
                                    <span class="fchip"><?= e($doc['status']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($doc['notes'] ?? '') ?></td>
                            <td><?= e($doc['created_by_name'] ?? ('مستخدم ' . $doc['created_by'])) ?></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-ghost" onclick="viewDoc(<?= (int) $doc['id'] ?>)">
                                    <i class="ti ti-eye"></i> فتح
                                </button>
                                <?php if ($doc['status'] === 'draft'): ?>
                                    <button type="button" class="btn btn-sm btn-success" onclick="postDoc(<?= (int) $doc['id'] ?>)"
                                            <?= !$stockEnabled ? 'disabled' : '' ?>>
                                        <i class="ti ti-check"></i> ترحيل
                                    </button>
                                <?php elseif ($doc['status'] === 'posted'): ?>
                                    <button type="button" class="btn btn-sm btn-danger" onclick="cancelDoc(<?= (int) $doc['id'] ?>)"
                                            <?= !$stockEnabled ? 'disabled' : '' ?>>
                                        <i class="ti ti-ban"></i> إلغاء
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php elseif ($tab === 'recon'): ?>
<!-- كروت المخازن للمطابقة -->
<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(210px, 1fr));gap:12px;margin-bottom:16px;">
    <?php foreach ($branches as $b): ?>
        <?php
            $bId = (int)$b['id'];
            $c = $reconCards[$bId] ?? [];
            $isSelected = ($bId === $reconBranchId);
            $isMirror = ($c['mode'] ?? 'mirror') === 'mirror';
            $diffCnt = (int)($c['diff_count'] ?? 0);
            $matchedCnt = (int)($c['matched_count'] ?? 0);
        ?>
        <div class="card" style="margin:0;padding:12px;border:2px solid <?= $isSelected ? 'var(--accent, #0070f3)' : 'var(--line-soft, #ddd)' ?>;cursor:pointer;position:relative;"
             onclick="location.href='stock_ledger.php?tab=recon&branch_id=<?= $bId ?>'">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                <b style="font-size:15px;"><?= e($b['name']) ?></b>
                <span class="fchip <?= $isMirror ? '' : 'fchip-pos' ?>">
                    <?= $isMirror ? 'مرآة (mirror)' : 'حي (live)' ?>
                </span>
            </div>
            <div style="font-size:12px;color:var(--text-muted, #888);margin-bottom:8px;">
                رصيد True من: <?= e($c['true_updated_at'] ?? '—') ?>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <div>
                    <?php if ($diffCnt === 0): ?>
                        <span class="fchip fchip-pos"><i class="ti ti-check"></i> متطابق (<?= $matchedCnt ?>)</span>
                    <?php else: ?>
                        <span class="fchip fchip-neg"><i class="ti ti-alert-circle"></i> <?= $diffCnt ?> فرق</span>
                    <?php endif; ?>
                </div>
                <button type="button" class="btn btn-sm btn-outline"
                        onclick="event.stopPropagation(); triggerImport(<?= $bId ?>)"
                        <?= !$stockEnabled ? 'disabled title="المخزن مقفول حالياً"' : '' ?>>
                    <i class="ti ti-refresh"></i> استورد دلوقتي
                </button>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- جدول الفروق للمخزن المختار -->
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:14px;">
        <div>
            <h3 style="margin:0 0 4px 0;">
                <i class="ti ti-arrows-diff"></i> فروق المطابقة — مخزن <?= e($activeRecon['branch_name'] ?? '') ?>
            </h3>
            <div style="font-size:13px;color:var(--text-muted, #888);">
                رصيد True من: <b><?= e($activeRecon['true_updated_at'] ?? '—') ?></b> ·
                الدفتر لحد: <b><?= e($activeRecon['ledger_updated_at'] ?? '—') ?></b>
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <button type="button" class="btn btn-sm btn-primary"
                    onclick="triggerImport(<?= $reconBranchId ?>)"
                    <?= !$stockEnabled ? 'disabled' : '' ?>>
                <i class="ti ti-refresh"></i> استورد دلوقتي للمخزن
            </button>
            <a href="stock_ledger.php?tab=recon&branch_id=<?= $reconBranchId ?>&export=1" class="btn btn-sm btn-outline">
                <i class="ti ti-file-spreadsheet"></i> تصدير إكسيل
            </a>
            <a href="reports.php?type=stock_recon&branch=<?= $reconBranchId ?>" class="btn btn-sm btn-ghost" target="_blank">
                <i class="ti ti-external-link"></i> فتح في التقارير
            </a>
        </div>
    </div>

    <?php if (empty($activeRecon['differences'])): ?>
        <div class="alert alert-success" style="padding:16px;font-size:14px;">
            <i class="ti ti-check" style="font-size:18px;"></i>
            جميع أرصدة الدفتر متطابقة تماماً مع رصيد True لهذا المخزن (<?= (int)($activeRecon['matched_count'] ?? 0) ?> صنف متطابق، صفر فروق).
        </div>
    <?php else: ?>
        <div style="margin-bottom:12px;display:flex;gap:12px;flex-wrap:wrap;">
            <span class="fchip fchip-neg">عدد الأصناف المختلفة: <?= count($activeRecon['differences']) ?></span>
            <span class="fchip">مجموع فرق القطع: <?= number_format((float)$activeRecon['total_diff_pieces'], 3) ?></span>
            <span class="fchip">مجموع فرق القيمة: <?= number_format((float)$activeRecon['total_diff_value'], 2) ?> ج.م</span>
        </div>
        <div style="overflow-x:auto;">
            <table class="ftable">
                <thead>
                    <tr>
                        <th>كود الصنف</th>
                        <th>اسم الصنف</th>
                        <th class="num">رصيد الدفتر</th>
                        <th class="num">رصيد True</th>
                        <th class="num">فرق القطع</th>
                        <th class="num">متوسط التكلفة</th>
                        <th class="num">قيمة الفرق</th>
                        <th>وحدة مجهولة؟</th>
                        <th>آخر حركة</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activeRecon['differences'] as $diff): ?>
                        <tr>
                            <td><?= e($diff['code']) ?></td>
                            <td><?= e($diff['name']) ?></td>
                            <td class="num font-bold"><?= number_format((float)$diff['ledger_qty'], 3) ?></td>
                            <td class="num font-bold"><?= number_format((float)$diff['true_qty'], 3) ?></td>
                            <td class="num font-bold" style="color:<?= $diff['diff_qty'] > 0 ? 'var(--pos, green)' : 'var(--neg, red)' ?>;">
                                <?= $diff['diff_qty'] > 0 ? '+' : '' ?><?= number_format((float)$diff['diff_qty'], 3) ?>
                            </td>
                            <td class="num"><?= number_format((float)$diff['avg_cost'], 4) ?></td>
                            <td class="num"><?= number_format((float)$diff['diff_value'], 2) ?></td>
                            <td>
                                <?php if (!empty($diff['has_unknown_unit'])): ?>
                                    <span class="fchip fchip-warn"><i class="ti ti-alert-triangle"></i> نعم (unit:unknown)</span>
                                <?php else: ?>
                                    <span style="color:var(--text-muted, #888);">لا</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($diff['last_move_at'] ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'import'): ?>
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:14px;">
        <div>
            <h3 style="margin:0 0 4px 0;"><i class="ti ti-history"></i> سجل دورات الاستيراد من True (آخر ٥٠ تشغيل)</h3>
            <div style="font-size:13px;color:var(--text-muted, #888);">
                الاستيراد الدوري الآلي (كل ١٠ دقائق عبر الوركر أو عند فتح الشاشة) + الاستيراد اليدوي
            </div>
        </div>
        <div>
            <button type="button" class="btn btn-primary" onclick="triggerImport(null)" <?= !$stockEnabled ? 'disabled' : '' ?>>
                <i class="ti ti-refresh"></i> تشغيل استيراد فوري الآن
            </button>
        </div>
    </div>

    <div style="overflow-x:auto;">
        <table class="ftable">
            <thead>
                <tr>
                    <th class="num">#</th>
                    <th>تاريخ ووقت التشغيل</th>
                    <th class="num">المدة (ث)</th>
                    <th class="num">جديد (imported)</th>
                    <th class="num">محدث (updated)</th>
                    <th class="num">تخطي (skipped)</th>
                    <th class="num">فشل (failed)</th>
                    <th>بواسطة</th>
                    <th>ملاحظات وأخطاء</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($importRuns)): ?>
                    <tr>
                        <td colspan="9" style="text-align:center;color:var(--text-muted, #888);padding:24px;">
                            لا توجد دورات استيراد مسجلة حتى الآن
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($importRuns as $ir): ?>
                        <tr>
                            <td class="num"><?= (int)$ir['id'] ?></td>
                            <td><?= e($ir['run_at']) ?></td>
                            <td class="num"><?= number_format((float)$ir['duration_sec'], 2) ?></td>
                            <td class="num" style="color:<?= $ir['imported_count'] > 0 ? 'var(--pos, green)' : 'inherit' ?>;">
                                <?= (int)$ir['imported_count'] ?>
                            </td>
                            <td class="num" style="color:<?= $ir['updated_count'] > 0 ? 'var(--accent, blue)' : 'inherit' ?>;">
                                <?= (int)$ir['updated_count'] ?>
                            </td>
                            <td class="num"><?= (int)$ir['skipped_count'] ?></td>
                            <td class="num" style="color:<?= $ir['failed_count'] > 0 ? 'var(--neg, red)' : 'inherit' ?>;font-weight:<?= $ir['failed_count'] > 0 ? 'bold' : 'normal' ?>;">
                                <?= (int)$ir['failed_count'] ?>
                            </td>
                            <td><?= e($ir['user_name'] ?? ($ir['created_by'] ? ('مستخدم ' . $ir['created_by']) : 'النظام (آلي)')) ?></td>
                            <td style="font-size:12px;max-width:300px;word-break:break-all;">
                                <?php
                                    $det = !empty($ir['details']) ? json_decode((string)$ir['details'], true) : null;
                                    if (!empty($det['errors'])) {
                                        echo '<span style="color:var(--neg, red);">' . e(count($det['errors']) . ' خطأ: ' . ($det['errors'][0]['error'] ?? '')) . '</span>';
                                    } else {
                                        echo '<span style="color:var(--text-muted, #888);">نجح بدون أخطاء</span>';
                                    }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Modal لعرض المستند وسجله وحركاته -->
<div id="docModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);z-index:9999;align-items:center;justify-content:center;padding:20px;">
    <div style="background:var(--bg-surface, #fff);border-radius:10px;width:100%;max-width:960px;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 10px 25px rgba(0,0,0,0.3);overflow:hidden;">
        <div style="padding:14px 20px;border-bottom:1px solid var(--line-soft, #ddd);display:flex;justify-content:space-between;align-items:center;">
            <h3 id="modalTitle" style="margin:0;"><i class="ti ti-file-invoice"></i> تفاصيل المستند</h3>
            <button type="button" class="btn btn-sm btn-ghost" onclick="closeDocModal()"><i class="ti ti-x"></i></button>
        </div>
        <div id="modalContent" style="padding:20px;overflow-y:auto;flex:1;">
            جاري التحميل...
        </div>
    </div>
</div>

<script src="assets/barcode_scanner.js?v=9"></script>
<script>
const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;

// كل نص جاي من القاعدة وبيتحط في innerHTML لازم يعدي من هنا — وإلا
// ملاحظة أو سبب إلغاء فيه <img onerror=...> بيشتغل سكربت عند فتح المستند.
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

async function apiRequest(action, data = null) {
    const isPost = data !== null;
    const url = 'stock_api.php' + (!isPost ? ('?' + new URLSearchParams(action)) : '');
    const opts = {
        method: isPost ? 'POST' : 'GET',
        headers: {
            'Accept': 'application/json',
            'X-CSRF-Token': CSRF_TOKEN,
        }
    };
    if (isPost) {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify({ action: action, ...data });
    }
    const res = await fetch(url, opts);
    const json = await res.json();
    if (!json.ok) {
        throw new Error(json.error || 'حصل خطأ في الاتصال');
    }
    return json.data;
}

async function triggerOpening(branchId, branchName) {
    if (!confirm(`هل تريد استيراد وترحيل الرصيد الافتتاحي لمخزن ${branchName} من المرآة؟`)) {
        return;
    }
    try {
        const res = await apiRequest('opening', { branch_id: branchId });
        alert(`تم إنشاء وترحيل مستند الرصيد الافتتاحي بنجاح (#${res.doc_id})`);
        location.reload();
    } catch (e) {
        alert('فشل إنشاء الرصيد الافتتاحي: ' + e.message);
    }
}

async function triggerImport(branchId = null) {
    const targetLabel = branchId ? 'لهذا المخزن' : 'لكل المخازن';
    if (!confirm(`هل تريد بدء دورة استيراد حركات True الآن ${targetLabel}؟`)) {
        return;
    }
    try {
        const res = await apiRequest('import_run', { branch_id: branchId });
        const imp = res.import || {};
        alert(`تم الانتهاء من الاستيراد بنجاح:\n- مستورد جديد: ${imp.imported || 0}\n- محدث: ${imp.updated || 0}\n- تم تخطيه: ${imp.skipped || 0}\n- أخطاء: ${imp.failed || 0}\nالمدة: ${imp.duration || 0} ثانية`);
        location.reload();
    } catch (e) {
        alert('فشل استيراد حركات True: ' + e.message);
    }
}

async function submitAdjust(ev) {
    ev.preventDefault();
    const branchId = parseInt(document.getElementById('adj_branch').value, 10);
    const docDate = document.getElementById('adj_date').value;
    const code = document.getElementById('adj_code').value.trim();
    const itemId = parseInt(document.getElementById('adj_item_id').value, 10);
    const factor = parseFloat(document.getElementById('adj_factor').value) || 1.0;
    const qty = parseFloat(document.getElementById('adj_qty').value);
    const costInput = document.getElementById('adj_cost').value;
    const unitCost = costInput !== '' ? parseFloat(costInput) : null;
    const notes = document.getElementById('adj_notes').value.trim();

    if (!branchId || !itemId || isNaN(qty)) {
        alert('برجاء التأكد من إدخال المخزن والصنف والكمية');
        return;
    }

    const lines = [{
        line_no: 1,
        item_id: itemId,
        code: code,
        unit_factor: factor,
        qty_units: qty,
        unit_cost: unitCost,
        notes: notes
    }];

    try {
        const res = await apiRequest('adjust_create', {
            branch_id: branchId,
            doc_date: docDate,
            notes: notes,
            lines: lines,
            post_now: true
        });
        alert(`تم ترحيل مستند التسوية بنجاح (#${res.doc_id})`);
        location.reload();
    } catch (e) {
        alert('فشل حفظ التسوية: ' + e.message);
    }
}

async function postDoc(docId) {
    if (!confirm(`هل تريد ترحيل المستند رقم ${docId} الآن؟`)) return;
    try {
        await apiRequest('post', { doc_id: docId });
        alert('تم ترحيل المستند بنجاح');
        location.reload();
    } catch (e) {
        alert('فشل الترحيل: ' + e.message);
    }
}

async function cancelDoc(docId) {
    const reason = prompt('سبب الإلغاء:');
    if (!reason || reason.trim() === '') return;
    try {
        await apiRequest('cancel', { doc_id: docId, reason: reason.trim() });
        alert('تم إلغاء المستند وعكس حركاته بنجاح');
        location.reload();
    } catch (e) {
        alert('فشل الإلغاء: ' + e.message);
    }
}

async function viewDoc(docId) {
    const modal = document.getElementById('docModal');
    const content = document.getElementById('modalContent');
    modal.style.display = 'flex';
    content.innerHTML = '<div style="text-align:center;padding:40px;">جاري تحميل تفاصيل المستند...</div>';

    try {
        const data = await apiRequest({ action: 'doc', id: docId });
        const doc = data.doc;
        const lines = data.lines || [];
        const moves = data.moves || [];
        const events = data.events || [];

        let statusChip = doc.status === 'posted' ? '<span class="fchip fchip-pos">مرحل</span>'
            : (doc.status === 'draft' ? '<span class="fchip fchip-warn">مسودة</span>'
            : (doc.status === 'cancelled' ? '<span class="fchip fchip-neg">ملغي</span>' : `<span class="fchip">${esc(doc.status)}</span>`));

        let html = `
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:12px;margin-bottom:16px;background:var(--bg-main, #f8f9fa);padding:14px;border-radius:8px;">
                <div><b>المعرف:</b> #${esc(doc.id)}</div>
                <div><b>النوع:</b> ${esc(doc.doc_type)}</div>
                <div><b>الرقم:</b> ${esc(doc.doc_no || '—')} / ${esc(doc.doc_year || '—')}</div>
                <div><b>المخزن:</b> ${esc(doc.branch_name || doc.branch_id)}</div>
                <div><b>التاريخ:</b> ${esc(doc.doc_date)}</div>
                <div><b>الحالة:</b> ${statusChip}</div>
                <div><b>أنشئ بواسطة:</b> ${esc(doc.created_by_name || doc.created_by)} (${esc(doc.created_at)})</div>
                ${doc.cancel_reason ? `<div style="grid-column:1/-1;color:var(--neg);"><b>سبب الإلغاء:</b> ${esc(doc.cancel_reason)}</div>` : ''}
            </div>

            <h4 style="margin:16px 0 8px 0;"><i class="ti ti-list"></i> سطور المستند (${lines.length})</h4>
            <div style="overflow-x:auto;margin-bottom:16px;">
                <table class="ftable">
                    <thead>
                        <tr>
                            <th class="num">#</th>
                            <th>الباركود</th>
                            <th class="num">الصنف</th>
                            <th>اسم الصنف</th>
                            <th class="num">المعامل</th>
                            <th class="num">الكمية</th>
                            <th class="num">القطع</th>
                            <th class="num">التكلفة</th>
                            <th>ملاحظة</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${lines.map(l => `
                            <tr>
                                <td class="num">${esc(l.line_no)}</td>
                                <td>${esc(l.code || '—')}</td>
                                <td class="num">${esc(l.item_id)}</td>
                                <td>${esc(l.item_name || '—')}</td>
                                <td class="num">${esc(l.unit_factor)}</td>
                                <td class="num">${parseFloat(l.qty_units).toFixed(3)}</td>
                                <td class="num">${parseFloat(l.qty_pieces).toFixed(3)}</td>
                                <td class="num">${l.unit_cost !== null ? parseFloat(l.unit_cost).toFixed(4) : '—'}</td>
                                <td>${esc(l.notes || '')}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>

            <h4 style="margin:16px 0 8px 0;"><i class="ti ti-history"></i> حركات الدفتر (stock_moves: ${moves.length})</h4>
            <div style="overflow-x:auto;margin-bottom:16px;">
                <table class="ftable">
                    <thead>
                        <tr>
                            <th class="num">#</th>
                            <th class="num">الصنف</th>
                            <th class="num">الكمية</th>
                            <th class="num">تكلفة الحركة</th>
                            <th class="num">الرصيد بعدها</th>
                            <th class="num">المتوسط بعدها</th>
                            <th>الوقت</th>
                            <th class="num">عكس حركة #</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${moves.length === 0 ? '<tr><td colspan="8" style="text-align:center;color:#888;">لا توجد حركات مسجلة للمستند</td></tr>' :
                        moves.map(m => `
                            <tr>
                                <td class="num">${esc(m.id)}</td>
                                <td class="num">${esc(m.item_id)}</td>
                                <td class="num" style="color:${m.qty >= 0 ? 'var(--pos)' : 'var(--neg)'};font-weight:bold;">
                                    ${m.qty >= 0 ? '+' : ''}${parseFloat(m.qty).toFixed(3)}
                                </td>
                                <td class="num">${parseFloat(m.unit_cost).toFixed(4)}</td>
                                <td class="num">${parseFloat(m.qty_after).toFixed(3)}</td>
                                <td class="num">${parseFloat(m.avg_cost_after).toFixed(4)}</td>
                                <td>${esc(m.move_at)}</td>
                                <td class="num">${esc(m.reverses_move_id || '—')}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>

            <h4 style="margin:16px 0 8px 0;"><i class="ti ti-notes"></i> سجل المستند (doc_events: ${events.length})</h4>
            <div style="overflow-x:auto;">
                <table class="ftable">
                    <thead>
                        <tr>
                            <th class="num">#</th>
                            <th>الحدث</th>
                            <th>المستخدم</th>
                            <th>التاريخ والوقت</th>
                            <th>التفاصيل</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${events.map(ev => `
                            <tr>
                                <td class="num">${esc(ev.id)}</td>
                                <td><span class="fchip">${esc(ev.event)}</span></td>
                                <td>${esc(ev.user_name || ev.user_id || 'النظام')}</td>
                                <td>${esc(ev.at)}</td>
                                <td style="font-size:12px;max-width:350px;word-break:break-all;">${esc(ev.details || '')}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;

        content.innerHTML = html;
    } catch (e) {
        content.innerHTML = `<div class="alert alert-danger">فشل تحميل المستند: ${esc(e.message)}</div>`;
    }
}

function closeDocModal() {
    document.getElementById('docModal').style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
