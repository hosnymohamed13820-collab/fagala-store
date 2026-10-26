<?php
/**
 * stock_ledger_lib.php — استعلامات دفتر المخزن والمستندات (بريف ٢٢٩)
 * نظام الفجالة ستور المالي
 *
 * بريف ٢٢٩: الاستعلامات دي كانت جوه stock_ledger.php نفسها (حوالين سطر ٤٢ · ١٢٠ · ١٤٠ · ١٧٩).
 * اتنقلت هنا **بالحرف** من غير أي تغيير في السلوك أو الشكل، والصفحة بقت عرض بس،
 * وstock_ledger_api.php بيستخدم نفس الدوال عشان أرقام الموقع والتطبيقات تفضل واحدة.
 */
declare(strict_types=1);

/**
 * المخازن المدعومة (١ بونجور · ٢ اي بي سي · ٣ الفجالة · ٤ دجلة · ٦ الإدارة).
 *
 * @return array<int, array<string, mixed>>
 */
function stock_ledger_branches(PDO $pdo): array
{
    $st = $pdo->query('SELECT id, name, sto_id FROM branches WHERE id IN (1, 2, 3, 4, 6) ORDER BY id ASC');
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * آخر ٥٠ دورة استيراد من True (تاب «الاستيراد»).
 *
 * @return array<int, array<string, mixed>>
 */
function stock_ledger_import_runs(PDO $pdo): array
{
    $st = $pdo->query("
        SELECT r.*, u.full_name AS user_name
          FROM stock_import_runs r
          LEFT JOIN users u ON u.id = r.created_by
         ORDER BY r.run_at DESC
         LIMIT 50
    ");
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * الأرصدة الافتتاحية المرحّلة، خريطة مفتاحها branch_id
 * (الصفحة بتعرض منها «مرحل — مستند #…» في كروت الرصيد الافتتاحي).
 *
 * @return array<int, array<string, mixed>>
 */
function stock_ledger_posted_openings(PDO $pdo): array
{
    $st = $pdo->query("SELECT branch_id, id, doc_no, doc_date FROM stock_docs WHERE doc_type = 'opening' AND status = 'posted'");
    $map = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $map[(int) $r['branch_id']] = $r;
    }
    return $map;
}

/**
 * مستندات المخزن مع الفلاتر (تاب «مستندات المخزن») — آخر ١٥٠ مستند.
 *
 * نفس شروط الصفحة القديمة بالحرف: كل فلتر بيضيف شرط واحد بس لو اتبعت.
 *
 * @return array<int, array<string, mixed>>
 */
function stock_ledger_docs(
    PDO $pdo,
    int $branchId = 0,
    string $type = '',
    string $status = '',
    string $from = '',
    string $to = ''
): array {
    $where = [];
    $params = [];
    if ($branchId > 0) {
        $where[] = 'd.branch_id = ?';
        $params[] = $branchId;
    }
    if ($type !== '') {
        $where[] = 'd.doc_type = ?';
        $params[] = $type;
    }
    if ($status !== '') {
        $where[] = 'd.status = ?';
        $params[] = $status;
    }
    if ($from !== '') {
        $where[] = 'd.doc_date >= ?';
        $params[] = $from;
    }
    if ($to !== '') {
        $where[] = 'd.doc_date <= ?';
        $params[] = $to;
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "
        SELECT d.*, b.name AS branch_name, u.full_name AS created_by_name
          FROM stock_docs d
          LEFT JOIN branches b ON b.id = d.branch_id
          LEFT JOIN users u ON u.id = d.created_by
         $whereSql
         ORDER BY d.doc_date DESC, d.id DESC
         LIMIT 150
    ";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
