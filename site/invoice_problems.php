<?php
/**
 * invoice_problems.php — صفحة مشاكل الفواتير
 * نظام الفجالة ستور المالي
 *
 * الغرض:
 * تجميع كل الأصناف التي بها مشاكل أو فروقات في مكان واحد:
 * 1. تلقائي: من المردودات (doc_type = 6 لنفس المورد خلال 90 يوم من تاريخ الشراء).
 *    مثال محمد بالحرف: قلم روتو في الفاتورة 12، اللي جه 10، المردود 2.
 *    العمود short_qty = الناقص (12 - 10 = 2)، مش 10 ومش 12.
 * 2. يدوي: من خانة ملاحظات الأصناف أو الإضافة المباشرة داخل هذه الصفحة.
 *
 * القواعد:
 * - الحفظ التلقائي لكل خانة على حدث blur فوري ومباشر.
 * - إذا كان الصنف مسجلاً مسبقاً بـ source = 'manual'، التحديث التلقائي يحدّث الكميات فقط
 *   ولا يمس note ولا source.
 * - الاستعلامات مجمّعة دائماً (ممنوع أي prepare أو استعلام داخل حلقة).
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_permission('invoices:problems');
require_once __DIR__ . '/receiving_lib.php';

$pdo = db();
$user = current_user();
$uid = (int) ($user['id'] ?? 0);

// =========================================================================
//  معالجة طلبات POST (حفظ الخانات تلقائياً على blur وحذف المشاكل)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!csrf_verify($_POST['csrf'] ?? '')) {
            throw new RuntimeException('انتهت صلاحية الجلسة، اعمل تحديث للصفحة.');
        }

        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'save_problem_field') {
            $did   = (int) ($_POST['distribution_id'] ?? 0);
            $code  = trim((string) ($_POST['item_code'] ?? ''));
            $name  = trim((string) ($_POST['item_name'] ?? ''));
            $field = (string) ($_POST['field'] ?? '');
            $val   = trim((string) ($_POST['value'] ?? ''));

            if ($did <= 0 || $code === '') {
                throw new RuntimeException('بيانات الصنف أو الفاتورة غير مكتملة.');
            }

            if (!in_array($field, ['short_qty', 'over_qty', 'note'], true)) {
                throw new RuntimeException('حقل غير صالح.');
            }

            // فحص السجل الحالي
            $stCur = $pdo->prepare('SELECT id, source, short_qty, over_qty, note FROM invoice_problems WHERE distribution_id = ? AND item_code = ? LIMIT 1');
            $stCur->execute([$did, $code]);
            $cur = $stCur->fetch();

            if ($cur) {
                // تحديث السجل القائم
                if ($field === 'short_qty') {
                    $num = max(0, (float) $val);
                    $upd = $pdo->prepare('UPDATE invoice_problems SET short_qty = ?, item_name = COALESCE(NULLIF(?, ""), item_name) WHERE id = ?');
                    $upd->execute([$num, $name, $cur['id']]);
                } elseif ($field === 'over_qty') {
                    $num = max(0, (float) $val);
                    $upd = $pdo->prepare('UPDATE invoice_problems SET over_qty = ?, item_name = COALESCE(NULLIF(?, ""), item_name) WHERE id = ?');
                    $upd->execute([$num, $name, $cur['id']]);
                } else { // note
                    $upd = $pdo->prepare('UPDATE invoice_problems SET note = ?, item_name = COALESCE(NULLIF(?, ""), item_name), created_by = COALESCE(created_by, ?) WHERE id = ?');
                    $upd->execute([$val !== '' ? $val : null, $name, $uid, $cur['id']]);
                }
                echo json_encode(['ok' => true, 'source' => $cur['source']], JSON_UNESCAPED_UNICODE);
            } else {
                // إدراج سجل يدوي جديد
                $short = ($field === 'short_qty') ? max(0, (float) $val) : 0.0;
                $over  = ($field === 'over_qty') ? max(0, (float) $val) : 0.0;
                $note  = ($field === 'note' && $val !== '') ? $val : null;

                $ins = $pdo->prepare(
                    "INSERT INTO invoice_problems (distribution_id, item_code, item_name, source, short_qty, over_qty, note, created_by)
                     VALUES (?, ?, ?, 'manual', ?, ?, ?, ?)"
                );
                $ins->execute([$did, $code, $name ?: null, $short, $over, $note, $uid]);
                echo json_encode(['ok' => true, 'source' => 'manual'], JSON_UNESCAPED_UNICODE);
            }
            exit;
        }

        if ($action === 'delete_problem') {
            $did  = (int) ($_POST['distribution_id'] ?? 0);
            $code = trim((string) ($_POST['item_code'] ?? ''));
            if ($did > 0 && $code !== '') {
                $del = $pdo->prepare('DELETE FROM invoice_problems WHERE distribution_id = ? AND item_code = ?');
                $del->execute([$did, $code]);
            }
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }

        throw new RuntimeException('إجراء غير معروف.');
    } catch (Throwable $ex) {
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// =========================================================================
//  دوال الحساب التلقائي المجمّع للمردودات
// =========================================================================

/**
 * حساب ومزامنة المردودات التلقائية لفاتورة معينة.
 * استعلام واحد مجمّع بدون أي استعلام داخل حلقة.
 *
 * مثال محمد بالحرف: قلم روتو في الفاتورة 12، اللي جه 10، المردود 2.
 * العمود short_qty = الناقص (12 - 10 = 2)، مش 10 ومش 12.
 */
function sync_invoice_problems_for_dist(PDO $pdo, int $distId): void
{
    if ($distId <= 0) return;

    $stDist = $pdo->prepare(
        "SELECT d.id, d.invoice_no, d.company_branch, d.doc_type, d.dist_date, d.supplier_id
         FROM invoice_distribution d
         WHERE d.id = ? LIMIT 1"
    );
    $stDist->execute([$distId]);
    $dist = $stDist->fetch();
    if (!$dist) return;

    // مشاكل الفواتير مخصصة لفواتير شراء الإدارة فقط (company_branch = 6 و doc_type = 2)
    if ($dist['company_branch'] !== null && (int) $dist['company_branch'] !== 6) return;
    if ($dist['doc_type'] !== null && (int) $dist['doc_type'] !== 2) return;

    // جلب فاتورة الشراء الأصلية من مرآة الشركة باستخدام المفتاح المركب
    $distKey = dist_key_sql('d', 'i.company_branch', 'i.doc_type');
    $stHq = $pdo->prepare(
        "SELECT i.sth_id, i.suffix, i.doc_no, i.company_branch, i.doc_type, i.supplier_id, i.invoice_date
         FROM hq_invoices i
         CROSS JOIN (SELECT ? AS company_branch, ? AS doc_type) d
         WHERE i.doc_no = ? AND i.doc_type = 2 AND (i.company_branch = 6 OR i.company_branch IS NULL) AND $distKey
         LIMIT 1"
    );
    $stHq->execute([$dist['company_branch'], $dist['doc_type'], $dist['invoice_no']]);
    $hqInv = $stHq->fetch();
    if (!$hqInv) return;

    // جلب كل سطور فاتورة الشراء
    $stLines = $pdo->prepare(
        "SELECT hl.item_id, hl.item_code, hl.item_name, hl.quantity, hl.unit_price, hl.cost_price
         FROM hq_invoice_lines hl
         WHERE hl.sth_id = ? AND hl.suffix = ?"
    );
    $stLines->execute([$hqInv['sth_id'], $hqInv['suffix']]);
    $rawLines = $stLines->fetchAll();
    if (empty($rawLines)) return;

    // دمج الأكواد المكررة بالدالة الرسمية
    $mergedList = merge_invoice_items($rawLines);
    $mergedItems = [];
    foreach ($mergedList as $it) {
        $code = trim((string) ($it['item_code'] ?? ''));
        if ($code !== '') {
            $mergedItems[$code] = $it;
        }
    }
    $itemCodes = array_keys($mergedItems);
    if (empty($itemCodes)) return;

    // استعلام مجمّع لجلب كميات المردود (doc_type = 6) لنفس المورد خلال 90 يوم
    $placeholders = implode(',', array_fill(0, count($itemCodes), '?'));
    $retSql = "
        SELECT lr.item_code, SUM(lr.quantity) AS return_qty
        FROM hq_invoices ir
        JOIN hq_invoice_lines lr ON lr.sth_id = ir.sth_id AND lr.suffix = ir.suffix
        WHERE ir.doc_type = 6
          AND ir.supplier_id = ?
          AND ir.invoice_date >= ?
          AND ir.invoice_date <= DATE_ADD(?, INTERVAL 90 DAY)
          AND lr.item_code IN ($placeholders)
        GROUP BY lr.item_code
        HAVING return_qty > 0
    ";
    $retParams = array_merge([$hqInv['supplier_id'], $hqInv['invoice_date'], $hqInv['invoice_date']], $itemCodes);
    $stRet = $pdo->prepare($retSql);
    $stRet->execute($retParams);
    $returns = $stRet->fetchAll(PDO::FETCH_KEY_PAIR);

    // جلب المشاكل الحالية المسجلة لهذه الفاتورة
    $stExist = $pdo->prepare(
        "SELECT item_code, source, short_qty, over_qty, note
         FROM invoice_problems
         WHERE distribution_id = ?"
    );
    $stExist->execute([$distId]);
    $existing = [];
    foreach ($stExist->fetchAll() as $ep) {
        $existing[$ep['item_code']] = $ep;
    }

    // استعلامات الإدراج والتحديث خارج أي حلقة
    $insAuto = $pdo->prepare(
        "INSERT INTO invoice_problems (distribution_id, item_code, item_name, source, short_qty, over_qty)
         VALUES (?, ?, ?, 'auto', ?, 0)
         ON DUPLICATE KEY UPDATE
             short_qty = VALUES(short_qty),
             item_name = COALESCE(NULLIF(VALUES(item_name), ''), item_name)"
    );

    $updManual = $pdo->prepare(
        "UPDATE invoice_problems
         SET short_qty = ?, item_name = COALESCE(NULLIF(?, ''), item_name)
         WHERE distribution_id = ? AND item_code = ?"
    );

    foreach ($returns as $code => $retQty) {
        $rQty = (float) $retQty;
        if ($rQty <= 0) continue;

        // مثال محمد بالحرف: قلم روتو في الفاتورة ١٢، اللي جه ١٠، المردود ٢
        // العمود short_qty = الناقص (١٢ - ١٠ = ٢)، مش ١٠ ومش ١٢
        $shortQty = $rQty;
        $itemName = $mergedItems[$code]['item_name'] ?? '';

        if (isset($existing[$code])) {
            // لو الصنف موجود يدوي: نحدّث الكميات فقط ونسيب source و note زي ما هما
            if ($existing[$code]['source'] === 'manual') {
                $updManual->execute([$shortQty, $itemName, $distId, $code]);
            } else {
                $insAuto->execute([$distId, $code, $itemName, $shortQty]);
            }
        } else {
            $insAuto->execute([$distId, $code, $itemName, $shortQty]);
        }
    }
}

/**
 * مزامنة تلقائية مجمّعة لكل الفواتير الموزعة التي لها مردودات.
 * استعلام واحد مجمّع بدون أي استعلام داخل حلقة.
 */
function sync_all_invoice_problems_auto(PDO $pdo): void
{
    // تنظيف أي سجلات تلقائية قديمة لفروع أخرى أو لمردودات
    try {
        $pdo->exec(
            "DELETE p FROM invoice_problems p
             JOIN invoice_distribution d ON d.id = p.distribution_id
             WHERE p.source = 'auto' AND ((d.company_branch IS NOT NULL AND d.company_branch <> 1) OR d.doc_type = 6)"
        );
    } catch (Throwable $e) { /* تجاهل */ }

    $sql = "
        SELECT 
            d.id AS dist_id,
            lp.item_code,
            MAX(lp.item_name) AS item_name,
            SUM(lr.quantity) AS return_qty
        FROM invoice_distribution d
        JOIN hq_invoices inv_p 
            ON inv_p.doc_no = d.invoice_no 
           AND inv_p.doc_type = 2
           AND (d.company_branch = 1 OR (d.company_branch IS NULL AND (inv_p.company_branch = 1 OR inv_p.company_branch IS NULL)))
           AND (d.doc_type = 2 OR d.doc_type IS NULL)
           AND (inv_p.company_branch = 1 OR inv_p.company_branch IS NULL)
        JOIN hq_invoice_lines lp 
            ON lp.sth_id = inv_p.sth_id AND lp.suffix = inv_p.suffix
        JOIN hq_invoices inv_r 
            ON inv_r.doc_type = 6 
           AND inv_r.supplier_id = inv_p.supplier_id
           AND (inv_r.company_branch = 1 OR inv_r.company_branch IS NULL OR inv_r.company_branch = inv_p.company_branch)
           AND inv_r.invoice_date >= inv_p.invoice_date 
           AND inv_r.invoice_date <= DATE_ADD(inv_p.invoice_date, INTERVAL 90 DAY)
        JOIN hq_invoice_lines lr 
            ON lr.sth_id = inv_r.sth_id AND lr.suffix = inv_r.suffix 
           AND lr.item_code = lp.item_code
        WHERE (d.company_branch = 1 OR d.company_branch IS NULL)
          AND (d.doc_type = 2 OR d.doc_type IS NULL)
        GROUP BY d.id, lp.item_code
        HAVING return_qty > 0
    ";
    $st = $pdo->query($sql);
    $rows = $st ? $st->fetchAll() : [];
    if (empty($rows)) return;

    $stManual = $pdo->query("SELECT distribution_id, item_code FROM invoice_problems WHERE source = 'manual'");
    $manualKeys = [];
    if ($stManual) {
        foreach ($stManual->fetchAll() as $m) {
            $manualKeys[$m['distribution_id'] . ':' . $m['item_code']] = true;
        }
    }

    $insAuto = $pdo->prepare(
        "INSERT INTO invoice_problems (distribution_id, item_code, item_name, source, short_qty, over_qty)
         VALUES (?, ?, ?, 'auto', ?, 0)
         ON DUPLICATE KEY UPDATE
             short_qty = VALUES(short_qty),
             item_name = COALESCE(NULLIF(VALUES(item_name), ''), item_name)"
    );

    $updManual = $pdo->prepare(
        "UPDATE invoice_problems
         SET short_qty = ?, item_name = COALESCE(NULLIF(?, ''), item_name)
         WHERE distribution_id = ? AND item_code = ?"
    );

    foreach ($rows as $r) {
        $did  = (int) $r['dist_id'];
        $code = (string) $r['item_code'];
        $name = (string) $r['item_name'];
        $sq   = (float) $r['return_qty'];
        $k    = $did . ':' . $code;

        // مثال محمد بالحرف: قلم روتو في الفاتورة ١٢، اللي جه ١٠، المردود ٢
        // العمود short_qty = الناقص (١٢ - ١٠ = ٢)، مش ١٠ ومش ١٢
        if (isset($manualKeys[$k])) {
            $updManual->execute([$sq, $name, $did, $code]);
        } else {
            $insAuto->execute([$did, $code, $name, $sq]);
        }
    }
}

// تشغيل المزامنة التلقائية المجمعة لجميع الفواتير ذات المردودات
sync_all_invoice_problems_auto($pdo);

$selectedDistId = isset($_GET['d']) ? (int) $_GET['d'] : 0;
if ($selectedDistId > 0) {
    sync_invoice_problems_for_dist($pdo, $selectedDistId);
}

$page_title    = 'مشاكل الفواتير';
$active_module = 'invoices';
require_once __DIR__ . '/includes/header.php';
?>

<style>
/* تعريف أزرار الصفحة محلياً طبقاً للقواعد */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: var(--r);
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid transparent;
    background: var(--accent);
    color: #fff;
    text-decoration: none;
    transition: background 0.15s ease, border-color 0.15s ease;
}
.btn:hover {
    background: var(--accent-hover);
    color: #fff;
}
.btn-ghost {
    background: var(--surface);
    color: var(--text);
    border-color: var(--line);
}
.btn-ghost:hover {
    background: var(--accent-soft);
    border-color: var(--accent);
    color: var(--accent-text);
}
.btn-danger {
    background: var(--neg);
    color: #fff;
}
.btn-danger:hover {
    background: var(--neg-fill);
    color: #fff;
}
.btn-sm {
    padding: 4px 10px;
    font-size: 12px;
}

.prob-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--r);
    padding: 18px;
    margin-bottom: 16px;
    box-shadow: var(--shadow-sm);
}
.prob-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid var(--line-soft);
    padding-bottom: 12px;
    margin-bottom: 14px;
    flex-wrap: wrap;
    gap: 10px;
}
.prob-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
    font-size: 13px;
}
.prob-table th, .prob-table td {
    padding: 8px 10px;
    border-bottom: 1px solid var(--line-soft);
    text-align: right;
    vertical-align: middle;
}
.prob-table th {
    background: var(--line-soft);
    color: var(--accent-text);
    font-weight: 600;
}
.prob-input {
    width: 100%;
    padding: 6px 8px;
    border: 1px solid var(--line);
    border-radius: var(--r-sm);
    font-size: 13px;
    background: var(--surface);
    color: var(--text);
    box-sizing: border-box;
}
.prob-input:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 2px var(--accent-soft);
}
.prob-input-num {
    text-align: center;
    max-width: 90px;
}
.save-ind {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 16px;
    margin-right: 4px;
    font-size: 13px;
}
.badge-src {
    display: inline-block;
    padding: 2px 7px;
    border-radius: var(--r-sm);
    font-size: 11px;
    font-weight: 600;
}
.badge-auto { background: var(--warn-soft); color: var(--warn); }
.badge-manual { background: var(--accent-soft); color: var(--accent-text); }
.filter-btn-group {
    display: flex;
    gap: 6px;
    margin-bottom: 12px;
}
.filter-pill {
    padding: 6px 14px;
    border-radius: var(--r);
    border: 1px solid var(--line);
    background: var(--surface);
    color: var(--text);
    cursor: pointer;
    font-size: 12.5px;
    font-weight: 600;
    font-family: inherit;
    transition: all 0.15s;
}
.filter-pill:hover {
    border-color: var(--accent);
    color: var(--accent-text);
}
.filter-pill.active {
    background: var(--accent);
    color: #fff;
    border-color: var(--accent);
}
</style>

<div class="content-container">

<?php if ($selectedDistId <= 0): ?>
    <!-- ===================================================================== -->
    <!--  عرض القائمة العامة: الفواتير التي بها مشاكل                         -->
    <!-- ===================================================================== -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
        <div>
            <h2 style="font-size:20px;font-weight:bold;margin:0;"><i class="ti ti-alert-triangle"></i> مشاكل الفواتير</h2>
            <span style="font-size:12.5px;color:var(--ink-400);">الأصناف التي بها فروقات في الاستلام (عجز/زيادة) أو ملاحظات</span>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            <a href="invoices.php" class="btn btn-ghost btn-sm">← الرجوع للاستلامات</a>
        </div>
    </div>

    <?php
    // جلب فواتير الإدارة فقط (company_branch = 1 ومشتريات فقط doc_type = 2) لإتاحة فتح أي فاتورة
    $stAllInv = $pdo->query(
        "SELECT d.id, d.invoice_no, d.dist_date,
                COALESCE(s.name, inv_p.supplier_name, 'غير محدد') AS supplier_name
         FROM invoice_distribution d
         LEFT JOIN suppliers s ON s.id = d.supplier_id
         LEFT JOIN hq_invoices inv_p 
             ON inv_p.doc_no = d.invoice_no 
            AND inv_p.doc_type = 2
            AND (d.company_branch IS NULL OR inv_p.company_branch IS NULL OR d.company_branch = inv_p.company_branch)
         WHERE (d.company_branch = 1 OR (d.company_branch IS NULL AND (inv_p.company_branch = 1 OR inv_p.company_branch IS NULL)))
           AND (d.doc_type = 2 OR (d.doc_type IS NULL AND (inv_p.doc_type = 2 OR inv_p.doc_type IS NULL)))
           AND (d.doc_type IS NULL OR d.doc_type <> 6)
         ORDER BY d.id DESC
         LIMIT 200"
    );
    $allInvoices = $stAllInv ? $stAllInv->fetchAll() : [];

    // استعلام فواتير الإدارة التي بها مشاكل حالياً مع اسم المورد وتاريخها ومين دخلها
    $stProblemsList = $pdo->query(
        "SELECT 
            d.id AS dist_id,
            d.invoice_no,
            d.dist_date,
            COALESCE(s.name, inv_p.supplier_name, 'غير محدد') AS supplier_name,
            u.full_name AS entered_by,
            COUNT(p.id) AS problems_count,
            SUM(CASE WHEN p.short_qty > 0 THEN 1 ELSE 0 END) AS short_count,
            SUM(CASE WHEN p.over_qty > 0 THEN 1 ELSE 0 END) AS over_count,
            SUM(CASE WHEN p.note IS NOT NULL AND p.note <> '' THEN 1 ELSE 0 END) AS note_count
         FROM invoice_problems p
         JOIN invoice_distribution d ON d.id = p.distribution_id
         LEFT JOIN users u ON u.id = d.created_by
         LEFT JOIN suppliers s ON s.id = d.supplier_id
         LEFT JOIN hq_invoices inv_p 
             ON inv_p.doc_no = d.invoice_no 
            AND inv_p.doc_type = 2
            AND (d.company_branch IS NULL OR inv_p.company_branch IS NULL OR d.company_branch = inv_p.company_branch)
         WHERE (d.company_branch = 1 OR (d.company_branch IS NULL AND (inv_p.company_branch = 1 OR inv_p.company_branch IS NULL)))
           AND (d.doc_type = 2 OR (d.doc_type IS NULL AND (inv_p.doc_type = 2 OR inv_p.doc_type IS NULL)))
           AND (d.doc_type IS NULL OR d.doc_type <> 6)
           AND (p.short_qty > 0 OR p.over_qty > 0 OR (p.note IS NOT NULL AND p.note <> ''))
         GROUP BY d.id, d.invoice_no, d.dist_date, supplier_name, u.full_name
         ORDER BY d.id DESC"
    );
    $problemInvoices = $stProblemsList ? $stProblemsList->fetchAll() : [];
    ?>

    <!-- شريط اختيار فاتورة لفتحها وإضافة مشاكل لها -->
    <div class="prob-card" style="padding:12px 16px;">
        <label style="display:block;font-size:12.5px;font-weight:600;color:var(--ink-700);margin-bottom:6px;">
            فتح فاتورة معينة لعرض أصنافها أو إضافة مشاكل:
        </label>
        <select onchange="if(this.value) location.href='invoice_problems.php?d=' + this.value;"
                style="width:100%;max-width:500px;padding:8px 12px;border:1px solid var(--line);border-radius:var(--r);font-size:13.5px;background:var(--surface);color:var(--text);">
            <option value="">— اختر فاتورة مشتريات الإدارة من القائمة —</option>
            <?php foreach ($allInvoices as $ai): ?>
                <option value="<?= (int)$ai['id'] ?>">
                    فاتورة #<?= e((string)$ai['invoice_no']) ?> — مورد: <?= e((string)$ai['supplier_name']) ?> (<?= e((string)$ai['dist_date']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php if (empty($problemInvoices)): ?>
        <div class="prob-card" style="text-align:center;padding:40px 20px;color:var(--ink-400);">
            <i class="ti ti-circle-check" style="font-size:38px;color:var(--pos);display:block;margin-bottom:8px;"></i>
            لا توجد مشاكل أو فروقات مسجلة في فواتير الإدارة حالياً.
        </div>
    <?php else: ?>
        <div class="prob-card">
            <h3 style="margin:0 0 12px 0;font-size:15px;">قائمة فواتير الإدارة التي بها مشاكل (<?= count($problemInvoices) ?> فاتورة)</h3>
            <table class="prob-table">
                <thead>
                    <tr>
                        <th style="width:100px;">رقم الفاتورة</th>
                        <th>اسم المورد</th>
                        <th style="width:120px;">تاريخ الفاتورة</th>
                        <th style="width:150px;">مين دخّلها</th>
                        <th style="width:120px;">الأصناف المتأثرة</th>
                        <th style="width:100px;">إجراء</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($problemInvoices as $pi): ?>
                        <tr>
                            <td><b>#<?= e((string)$pi['invoice_no']) ?></b></td>
                            <td><?= e((string)$pi['supplier_name']) ?></td>
                            <td><?= e((string)$pi['dist_date']) ?></td>
                            <td><?= e((string)($pi['entered_by'] ?: 'غير معروف')) ?></td>
                            <td>
                                <span style="font-weight:bold;color:var(--neg);"><?= (int)$pi['problems_count'] ?> صنف</span>
                                <?php if ($pi['short_count'] > 0): ?>
                                    <span style="font-size:11px;color:var(--neg);">(<?= (int)$pi['short_count'] ?> عجز)</span>
                                <?php endif; ?>
                                <?php if ($pi['over_count'] > 0): ?>
                                    <span style="font-size:11px;color:var(--accent-text);">(<?= (int)$pi['over_count'] ?> زيادة)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="invoice_problems.php?d=<?= (int)$pi['dist_id'] ?>" class="btn btn-ghost btn-sm">عرض الفاتورة</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

<?php else: ?>
    <!-- ===================================================================== -->
    <!--  عرض أصناف فاتورة محددة (?d=<distribution_id>)                         -->
    <!-- ===================================================================== -->
    <?php
    $stDistHeader = $pdo->prepare(
        "SELECT d.id, d.invoice_no, d.company_branch, d.doc_type, d.dist_date, d.created_by,
                COALESCE(s.name, inv_p.supplier_name, 'غير محدد') AS supplier_name,
                u.full_name AS entered_by
         FROM invoice_distribution d
         LEFT JOIN users u ON u.id = d.created_by
         LEFT JOIN suppliers s ON s.id = d.supplier_id
         LEFT JOIN hq_invoices inv_p 
             ON inv_p.doc_no = d.invoice_no 
            AND inv_p.doc_type = 2
            AND (d.company_branch IS NULL OR inv_p.company_branch IS NULL OR d.company_branch = inv_p.company_branch)
         WHERE d.id = ?
           AND (d.company_branch = 1 OR (d.company_branch IS NULL AND (inv_p.company_branch = 1 OR inv_p.company_branch IS NULL)))
           AND (d.doc_type = 2 OR (d.doc_type IS NULL AND (inv_p.doc_type = 2 OR inv_p.doc_type IS NULL)))
           AND (d.doc_type IS NULL OR d.doc_type <> 6)
         LIMIT 1"
    );
    $stDistHeader->execute([$selectedDistId]);
    $distHeader = $stDistHeader->fetch();

    if (!$distHeader):
    ?>
        <div class="prob-card" style="text-align:center;color:var(--neg);padding:30px;">
            <i class="ti ti-alert-triangle"></i> الفاتورة المطلوبة غير موجودة أو ليست من فواتير مشتريات الإدارة.
            <div style="margin-top:12px;"><a href="invoice_problems.php" class="btn btn-ghost btn-sm">← الرجوع للقائمة</a></div>
        </div>
    <?php
    else:
        // جلب سطور الفاتورة من مرآة الشركة مع دمج الأكواد المكررة
        $distKey = dist_key_sql('d', 'i.company_branch', 'i.doc_type');
        $stHq = $pdo->prepare(
            "SELECT i.sth_id, i.suffix
             FROM hq_invoices i
             CROSS JOIN (SELECT ? AS company_branch, ? AS doc_type) d
             WHERE i.doc_no = ? AND i.doc_type = 2 AND $distKey
             LIMIT 1"
        );
        $stHq->execute([$distHeader['company_branch'], $distHeader['doc_type'], $distHeader['invoice_no']]);
        $hqInv = $stHq->fetch();

        $mergedInvoiceItems = [];
        if ($hqInv) {
            $stLines = $pdo->prepare(
                "SELECT hl.item_id, hl.item_code, hl.item_name, hl.quantity, hl.unit_price, hl.cost_price
                 FROM hq_invoice_lines hl
                 WHERE hl.sth_id = ? AND hl.suffix = ?"
            );
            $stLines->execute([$hqInv['sth_id'], $hqInv['suffix']]);
            $mergedList = merge_invoice_items($stLines->fetchAll());
            foreach ($mergedList as $it) {
                $code = trim((string) ($it['item_code'] ?? ''));
                if ($code !== '') {
                    $mergedInvoiceItems[$code] = $it;
                }
            }
        }

        // جلب كل المشاكل المسجلة في جدول invoice_problems لهذه الفاتورة
        $stProblems = $pdo->prepare(
            "SELECT p.*, u.full_name AS recorder_name
             FROM invoice_problems p
             LEFT JOIN users u ON u.id = p.created_by
             WHERE p.distribution_id = ?"
        );
        $stProblems->execute([$selectedDistId]);
        $problemsMap = [];
        foreach ($stProblems->fetchAll() as $pRow) {
            $problemsMap[$pRow['item_code']] = $pRow;
        }

        // تجميع كل الأصناف في قائمة موحدة
        $allItemCodes = array_unique(array_merge(array_keys($mergedInvoiceItems), array_keys($problemsMap)));
        $itemsList = [];
        foreach ($allItemCodes as $c) {
            $m = $mergedInvoiceItems[$c] ?? null;
            $p = $problemsMap[$c] ?? null;

            $itemName = $p['item_name'] ?? ($m['item_name'] ?? $c);
            $invQty   = $m ? (float)$m['quantity'] : 0.0;
            $shortQty = $p ? (float)$p['short_qty'] : 0.0;
            $overQty  = $p ? (float)$p['over_qty'] : 0.0;
            $note     = $p['note'] ?? '';
            $src      = $p['source'] ?? '';
            $recName  = $p['recorder_name'] ?? '';
            $recAt    = $p['updated_at'] ?? ($p['created_at'] ?? '');

            $hasProblem = ($shortQty > 0 || $overQty > 0 || $note !== '');

            $itemsList[] = [
                'code'        => $c,
                'name'        => $itemName,
                'inv_qty'     => $invQty,
                'short_qty'   => $shortQty,
                'over_qty'    => $overQty,
                'note'        => $note,
                'source'      => $src,
                'recorder'    => $recName,
                'time'        => $recAt,
                'has_problem' => $hasProblem,
            ];
        }

        // ترتيب: أصناف المشاكل أولاً ثم الباقي
        usort($itemsList, function ($a, $b) {
            if ($a['has_problem'] && !$b['has_problem']) return -1;
            if (!$a['has_problem'] && $b['has_problem']) return 1;
            return strcmp($a['name'], $b['name']);
        });

        $problemsOnlyCount = count(array_filter($itemsList, fn($it) => $it['has_problem']));
    ?>
        <div class="prob-card">
            <div class="prob-header">
                <div>
                    <h2 style="margin:0 0 6px 0;font-size:18px;">
                        فاتورة #<?= e((string)$distHeader['invoice_no']) ?> — <?= e((string)$distHeader['supplier_name']) ?>
                    </h2>
                    <div style="font-size:12.5px;color:var(--ink-500);display:flex;gap:14px;flex-wrap:wrap;">
                        <span><b>تاريخ الفاتورة:</b> <?= e((string)$distHeader['dist_date']) ?></span>
                        <span><b>مين دخلها:</b> <?= e((string)($distHeader['entered_by'] ?: 'غير معروف')) ?></span>
                        <span><b>أصناف المشاكل:</b> <b style="color:var(--neg);"><?= $problemsOnlyCount ?></b> من <?= count($itemsList) ?> صنف</span>
                    </div>
                </div>
                <div style="display:flex;gap:8px;">
                    <a href="invoice_problems.php" class="btn btn-ghost btn-sm">← رجوع لكل الفواتير</a>
                </div>
            </div>

            <!-- أزرار الفلترة بين عرض المشاكل فقط وعرض كل الأصناف -->
            <div class="filter-btn-group">
                <button type="button" class="filter-pill active" onclick="setProblemsFilter('problems', this)">
                    أصناف المشاكل فقط (<?= $problemsOnlyCount ?>)
                </button>
                <button type="button" class="filter-pill" onclick="setProblemsFilter('all', this)">
                    كل أصناف الفاتورة (<?= count($itemsList) ?>)
                </button>
            </div>

            <table class="prob-table" id="itemsTable">
                <thead>
                    <tr>
                        <th style="width:140px;">كود الصنف</th>
                        <th>اسم الصنف</th>
                        <th style="width:85px;text-align:center;">كمية الفاتورة</th>
                        <th style="width:110px;text-align:center;">الناقص</th>
                        <th style="width:110px;text-align:center;">الزيادة</th>
                        <th>الملاحظة</th>
                        <th style="width:80px;text-align:center;">المصدر</th>
                        <th style="width:130px;">التسجيل</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($itemsList as $it):
                        $rowClass = $it['has_problem'] ? 'prob-row is-problem' : 'prob-row not-problem';
                        $rowStyle = $it['has_problem'] ? '' : 'display:none;';
                    ?>
                        <tr class="<?= $rowClass ?>" style="<?= $rowStyle ?>"
                            data-code="<?= e((string)$it['code']) ?>"
                            data-name="<?= e((string)$it['name']) ?>"
                            data-has-problem="<?= $it['has_problem'] ? '1' : '0' ?>">
                            <td><code><?= e((string)$it['code']) ?></code></td>
                            <td><b><?= e((string)$it['name']) ?></b></td>
                            <td style="text-align:center;color:var(--ink-500);"><?= (float)$it['inv_qty'] ?></td>
                            <td style="text-align:center;">
                                <div style="display:flex;align-items:center;justify-content:center;">
                                    <input type="number" step="any" min="0"
                                           class="prob-input prob-input-num input-short"
                                           value="<?= $it['short_qty'] > 0 ? (float)$it['short_qty'] : '' ?>"
                                           placeholder="0"
                                           data-saved="<?= $it['short_qty'] > 0 ? (float)$it['short_qty'] : '' ?>"
                                           onblur="saveField(this, 'short_qty')">
                                    <span class="save-ind"></span>
                                </div>
                            </td>
                            <td style="text-align:center;">
                                <div style="display:flex;align-items:center;justify-content:center;">
                                    <input type="number" step="any" min="0"
                                           class="prob-input prob-input-num input-over"
                                           value="<?= $it['over_qty'] > 0 ? (float)$it['over_qty'] : '' ?>"
                                           placeholder="0"
                                           data-saved="<?= $it['over_qty'] > 0 ? (float)$it['over_qty'] : '' ?>"
                                           onblur="saveField(this, 'over_qty')">
                                    <span class="save-ind"></span>
                                </div>
                            </td>
                            <td>
                                <div style="display:flex;align-items:center;">
                                    <input type="text"
                                           class="prob-input input-note"
                                           value="<?= e((string)$it['note']) ?>"
                                           placeholder="اكتب ملاحظة المشكلة…"
                                           data-saved="<?= e((string)$it['note']) ?>"
                                           onblur="saveField(this, 'note')">
                                    <span class="save-ind"></span>
                                </div>
                            </td>
                            <td style="text-align:center;">
                                <?php if ($it['source'] === 'auto'): ?>
                                    <span class="badge-src badge-auto src-badge">تلقائي</span>
                                <?php elseif ($it['source'] === 'manual'): ?>
                                    <span class="badge-src badge-manual src-badge">يدوي</span>
                                <?php else: ?>
                                    <span class="badge-src src-badge" style="color:var(--ink-400);">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:11.5px;color:var(--ink-500);">
                                <?php if ($it['recorder']): ?>
                                    <div><?= e((string)$it['recorder']) ?></div>
                                    <div style="font-size:10.5px;color:var(--ink-400);"><?= e((string)date('m-d H:i', strtotime((string)$it['time']))) ?></div>
                                <?php else: ?>
                                    <span style="color:var(--ink-300);">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <script>
        var CSRF = <?= json_encode(csrf_token()) ?>;
        var DIST_ID = <?= (int)$selectedDistId ?>;
        var currentFilter = 'problems';

        function setProblemsFilter(mode, btn) {
            currentFilter = mode;
            document.querySelectorAll('.filter-pill').forEach(b => b.classList.remove('active'));
            if (btn) btn.classList.add('active');

            var rows = document.querySelectorAll('tr.prob-row');
            rows.forEach(r => {
                if (mode === 'all') {
                    r.style.display = '';
                } else {
                    r.style.display = (r.dataset.hasProblem === '1') ? '' : 'none';
                }
            });
        }

        function saveField(input, fieldName) {
            var row = input.closest('tr');
            if (!row) return;
            var code = row.dataset.code;
            var name = row.dataset.name || '';
            var val  = input.value.trim();
            var prev = input.dataset.saved || '';

            if (val === prev) return; // مفيش تعديل

            var ind = input.parentElement.querySelector('.save-ind');
            if (ind) { ind.innerHTML = '<i class="ti ti-clock"></i>'; ind.style.color = 'var(--warn)'; }

            var fd = new FormData();
            fd.append('action', 'save_problem_field');
            fd.append('csrf', CSRF);
            fd.append('distribution_id', DIST_ID);
            fd.append('item_code', code);
            fd.append('item_name', name);
            fd.append('field', fieldName);
            fd.append('value', val);

            fetch('invoice_problems.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if (d.ok) {
                        input.dataset.saved = val;
                        if (ind) {
                            ind.innerHTML = '<i class="ti ti-check"></i>';
                            ind.style.color = 'var(--pos)';
                            setTimeout(function(){ ind.innerHTML = ''; }, 1800);
                        }
                        if (d.source) {
                            var srcBadge = row.querySelector('.src-badge');
                            if (srcBadge) {
                                srcBadge.textContent = (d.source === 'auto') ? 'تلقائي' : 'يدوي';
                                srcBadge.className = 'badge-src badge-' + d.source + ' src-badge';
                            }
                        }

                        // فحص هل الصف أصبح مشكلة أم لا
                        var sq = parseFloat(row.querySelector('.input-short').value) || 0;
                        var oq = parseFloat(row.querySelector('.input-over').value) || 0;
                        var nt = row.querySelector('.input-note').value.trim();
                        var hasProb = (sq > 0 || oq > 0 || nt !== '');
                        row.dataset.hasProblem = hasProb ? '1' : '0';
                        if (hasProb) {
                            row.classList.add('is-problem');
                            row.classList.remove('not-problem');
                        } else {
                            row.classList.remove('is-problem');
                            row.classList.add('not-problem');
                            if (currentFilter === 'problems') {
                                row.style.display = 'none';
                            }
                        }
                    } else {
                        input.value = prev;
                        if (ind) { ind.innerHTML = '<i class="ti ti-x"></i>'; ind.style.color = 'var(--neg)'; }
                        alert(d.error || 'حدث خطأ في الحفظ');
                    }
                })
                .catch(function () {
                    input.value = prev;
                    if (ind) { ind.innerHTML = '<i class="ti ti-x"></i>'; ind.style.color = 'var(--neg)'; }
                });
        }
        </script>
    <?php endif; ?>

<?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
