<?php
declare(strict_types=1);

require_once __DIR__ . '/stock_transfer_lib.php';

/** نفس صلاحية إنشاء التحويل من الإدارة في transfers_api.php. */
function autotransfer_can_create(): bool
{
    if (!can('transfers') && !is_owner()) return false;
    $isOwner = is_real_owner() || is_owner();
    $user = current_user();
    $userBranchId = !empty($user['branch_id']) ? (int) $user['branch_id'] : null;
    if (!$isOwner && !$userBranchId) return false;
    if (!$isOwner) {
        if (!can('transfers:create')) return false;
        if (6 !== $userBranchId) return false;
    }
    return true;
}

/** الصفحة مافيهاش اختيار فواتير؛ الافتراضي كل توزيع خلص ولسه له فرع ماتحوّلش. */
function autotransfer_default_ids(PDO $pdo): array
{
    return array_map('intval', $pdo->query(
        "SELECT d.id FROM invoice_distribution d
         WHERE d.status = 'done' AND EXISTS (
             SELECT 1 FROM invoice_distribution_lines l
             WHERE l.distribution_id = d.id AND l.branch_id BETWEEN 1 AND 4
               AND NOT EXISTS (SELECT 1 FROM stock_doc_dists x
                   WHERE x.distribution_id = d.id AND x.branch_id = l.branch_id)
         ) ORDER BY d.id"
    )->fetchAll(PDO::FETCH_COLUMN));
}

function autotransfer_ids(array $distIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $distIds), fn($id) => $id > 0)));
    sort($ids, SORT_NUMERIC);
    return $ids;
}

/** قراية بس؛ كمية التوزيع بوحدة كود الفاتورة، والتحويل بيتجمع بالقطعة لكل item_id. */
function autotransfer_preview(PDO $pdo, array $distIds): array
{
    $ids = autotransfer_ids($distIds);
    $out = ['dist_ids' => $ids, 'branches' => [], 'skipped' => []];
    if (!$ids) return $out;
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, invoice_no, status FROM invoice_distribution WHERE id IN ($marks) ORDER BY id");
    $st->execute($ids);
    $dists = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $dists[(int) $row['id']] = $row;

    // استعلام واحد لكل السطور؛ التجميع بالكود والفرع مع الاحتفاظ بالتوزيع عشان منع التكرار.
    $st = $pdo->prepare(
        "SELECT l.distribution_id, l.branch_id, l.notes AS code, SUM(l.amount) AS qty_units,
                c.item_id, c.unit_factor, c.name, x.doc_id
         FROM invoice_distribution_lines l
         LEFT JOIN hq_codes c ON c.code = l.notes
         LEFT JOIN stock_doc_dists x ON x.distribution_id = l.distribution_id AND x.branch_id = l.branch_id
         WHERE l.distribution_id IN ($marks)
         GROUP BY l.distribution_id, l.branch_id, l.notes, c.item_id, c.unit_factor, c.name, x.doc_id
         ORDER BY l.branch_id, l.distribution_id, l.notes"
    );
    $st->execute($ids);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ids as $id) {
        if (!isset($dists[$id]) || $dists[$id]['status'] !== 'done') {
            $out['skipped'][] = ['distribution_id' => $id, 'invoice_no' => $dists[$id]['invoice_no'] ?? '',
                'reason' => isset($dists[$id]) ? 'التوزيع لسه مش done' : 'التوزيع مش موجود'];
        }
    }
    $seen = [];
    foreach ($rows as $row) {
        $id = (int) $row['distribution_id'];
        if (!isset($dists[$id]) || $dists[$id]['status'] !== 'done') continue;
        $branch = (int) $row['branch_id'];
        $reason = null;
        if ($row['doc_id'] !== null) $reason = 'اتحوّل قبل كده';
        elseif ($branch === 5) $reason = 'فرع إشراقة مش مدعوم في التحويلات';
        elseif ($branch < 1 || $branch > 4) $reason = 'الفرع ده مش فرع مستلم للتحويل التلقائي';
        elseif ((float) $row['qty_units'] <= 0) $reason = 'الكمية أقل من أو بتساوي صفر';
        elseif ((int) $row['item_id'] <= 0 || (float) $row['unit_factor'] <= 0) $reason = 'الصنف مالوش item_id أو معامل وحدة معروف';
        if ($reason !== null) {
            $key = $id . ':' . $branch . ':' . ($row['doc_id'] !== null ? '' : $row['code']);
            if (!isset($seen[$key])) $out['skipped'][] = ['distribution_id' => $id,
                'invoice_no' => $dists[$id]['invoice_no'], 'branch_id' => $branch,
                'code' => $row['code'], 'reason' => $reason];
            $seen[$key] = true;
            continue;
        }
        $pieces = item_line_pieces((float) $row['qty_units'], (float) $row['unit_factor'], null)['pieces'];
        if ($pieces <= 0) {
            $out['skipped'][] = ['distribution_id' => $id, 'invoice_no' => $dists[$id]['invoice_no'],
                'branch_id' => $branch, 'code' => $row['code'], 'reason' => 'الكمية بالقطعة اتقرّبت لصفر'];
            continue;
        }
        if (!isset($out['branches'][$branch])) $out['branches'][$branch] = [
            'branch_id' => $branch, 'lines' => [], 'distributions' => [], 'total_pieces' => 0.0];
        $b = &$out['branches'][$branch];
        $item = (int) $row['item_id'];
        if (!isset($b['lines'][$item])) $b['lines'][$item] = ['item_id' => $item,
            'code' => (string) $row['code'], 'name' => (string) $row['name'],
            'unit_factor' => 1, 'qty_units' => 0.0, 'qty_pieces' => 0.0];
        // الكود للتتبّع بس؛ المعامل الصريح ١ لأن السطر المجمع كله قطع، حتى لو فيه باركودات مختلفة.
        $b['lines'][$item]['qty_pieces'] = round($b['lines'][$item]['qty_pieces'] + $pieces, 3);
        $b['lines'][$item]['qty_units'] = $b['lines'][$item]['qty_pieces'];
        $b['total_pieces'] = round($b['total_pieces'] + $pieces, 3);
        $b['distributions'][$id] = ['distribution_id' => $id, 'invoice_no' => $dists[$id]['invoice_no']];
        unset($b);
    }
    foreach ($out['branches'] as &$branch) {
        $branch['lines'] = array_values($branch['lines']);
        $branch['distributions'] = array_values($branch['distributions']);
        $branch['items_count'] = count($branch['lines']);
    }
    unset($branch);
    $out['branches'] = array_values($out['branches']);
    return $out;
}

function autotransfer_run(PDO $pdo, array $distIds, int $uid): array
{
    stock_require_enabled();
    $ids = autotransfer_ids($distIds);
    if (!$ids) return ['documents' => [], 'skipped' => []];
    if ($pdo->inTransaction()) throw new RuntimeException('التحويل التلقائي محتاج معاملة مستقلة');
    $pdo->beginTransaction();
    try {
        // القفل على الأصل حتى لو جدول الربط لسه فاضي؛ الترتيب ثابت للطلبات المتقاطعة.
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $lock = $pdo->prepare("SELECT id FROM invoice_distribution WHERE id IN ($marks) ORDER BY id FOR UPDATE");
        $lock->execute($ids);
        $lock->fetchAll(PDO::FETCH_COLUMN);
        // أول قراية عادية بعد انتظار القفل، فبتشوف الربط اللي الطلب السابق عمله.
        $preview = autotransfer_preview($pdo, $ids);
        $documents = [];
        $links = [];
        $values = [];
        foreach ($preview['branches'] as $branch) {
            $invoiceNos = array_unique(array_column($branch['distributions'], 'invoice_no'));
            $docId = transfer_create($pdo, 6, $branch['branch_id'], $branch['lines'], $uid,
                'تحويل تلقائي من توزيع فواتير: ' . implode('، ', $invoiceNos));
            $docNo = transfer_send($pdo, $docId, $uid);
            $documents[] = ['doc_id' => $docId, 'doc_no' => $docNo,
                'branch_id' => $branch['branch_id'], 'items_count' => $branch['items_count']];
            foreach ($branch['distributions'] as $dist) {
                $links[] = '(?, ?, ?)';
                array_push($values, $docId, $dist['distribution_id'], $branch['branch_id']);
            }
        }
        if ($links) {
            $insert = $pdo->prepare('INSERT INTO stock_doc_dists (doc_id, distribution_id, branch_id) VALUES ' . implode(',', $links));
            $insert->execute($values);
        }
        $pdo->commit();
        return ['documents' => $documents, 'skipped' => $preview['skipped']];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
