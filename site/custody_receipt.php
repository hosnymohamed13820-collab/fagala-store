<?php
/** محضر العهدة المعتمدة — ورق أبيض من غير هيدر الموقع أو الثيم الليلي. */
require_once __DIR__ . '/auth.php';
require_module_access('custody');
require_once __DIR__ . '/custody_lib.php';

try {
    $receipt = custody_get_receipt(db(), (int)($_GET['id'] ?? 0), current_user());
} catch (RuntimeException $ex) {
    $code = $ex->getCode();
    http_response_code(in_array($code, [403, 404, 409], true) ? $code : 500);
    exit(e($ex->getMessage()));
}
$custody = $receipt['custody'];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>محضر العهدة <?= (int)$custody['id'] ?></title>
    <link rel="stylesheet" href="assets/fagala.css?v=<?= filemtime(__DIR__ . '/assets/fagala.css') ?>">
    <style>
        @page { size: A4; margin: 15mm; }
        :root { color-scheme: light; }
        body { background: white; color: black; font-family: Arial, sans-serif; max-width: 180mm; margin: 12mm auto; font-variant-numeric: tabular-nums; }
        h1 { text-align: center; }
        .receipt-summary { display: grid; grid-template-columns: 1fr 1fr; gap: 6mm; margin-block: 8mm; }
        .receipt-signatures { display: flex; justify-content: space-around; margin-top: 25mm; break-inside: avoid; }
        .receipt-signature { text-align: center; min-width: 40mm; }
        .receipt-signature p { border-bottom: 1px solid black; height: 15mm; }
        .num { text-align: center; font-variant-numeric: tabular-nums; }
        @media print { body { margin: 0; max-width: none; } .receipt-tools { display: none; } thead { display: table-header-group; } tr { break-inside: avoid; } }
    </style>
</head>
<body>
    <div class="receipt-tools"><button type="button" class="btn btn-secondary" onclick="window.print()">طباعة محضر العهدة</button></div>
    <h1>محضر العهدة <?= (int)$custody['id'] ?></h1>
    <p>الفرع: <?= e($custody['branch_name'] ?? '—') ?></p>
    <p>الفترة: <?= e($custody['period_start']) ?> — <?= e($custody['period_end'] ?? '—') ?></p>
    <p>المسؤول: <?= e($custody['responsible_name'] ?? $custody['employee_name'] ?? 'مش متحدد') ?></p>
    <p>أول المدة: <?= number_format((float)$custody['opening_balance'], 2) ?> ج</p>
    <table class="ftable">
        <thead><tr><th>التاريخ</th><th>البيان</th><th class="num">المبلغ</th></tr></thead>
        <tbody>
        <?php foreach ($receipt['expenses'] as $line): ?>
            <tr><td><?= e($line['expense_date']) ?></td><td><?= e($line['description']) ?></td><td class="num"><?= number_format((float)$line['amount'], 2) ?> ج</td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th colspan="2">إجمالي المصروفات</th><td class="num"><?= number_format((float)$custody['total_expenses'], 2) ?> ج</td></tr></tfoot>
    </table>
    <div class="receipt-summary">
        <div>المفروض: <?= number_format((float)$custody['closing_balance'], 2) ?> ج</div>
        <div>الكاشير عدّ: <?= number_format((float)$custody['counted_cash'], 2) ?> ج</div>
        <div>المحاسب استلم: <?= number_format((float)$custody['accountant_counted'], 2) ?> ج</div>
        <div>الفرق: <bdi dir="ltr"><?= number_format((float)$custody['difference'], 2) ?></bdi> ج</div>
    </div>
    <p>قدّمها: <?= e($custody['submitter_name'] ?? '—') ?> · <?= e($custody['submitted_at'] ?? '—') ?></p>
    <p>اعتمدها: <?= e($custody['reviewer_name'] ?? '—') ?> · <?= e($custody['reviewed_at'] ?? '—') ?></p>
    <div class="receipt-signatures">
        <div class="receipt-signature">الكاشير<p></p></div>
        <div class="receipt-signature">المحاسب<p></p></div>
        <div class="receipt-signature">المدير<p></p></div>
    </div>
</body>
</html>
