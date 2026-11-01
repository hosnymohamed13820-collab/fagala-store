<?php
/**
 * pos_receipt.php — صفحة إيصال نقطة البيع بعرض ٨٠ مم للطباعة المباشرة.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();

$receiptType = (string) ($_GET['type'] ?? 'sale');
if ($receiptType === 'drawer') {
    $eventId = (int) ($_GET['id'] ?? 0);
    if ($eventId <= 0) {
        http_response_code(400);
        exit('معرّف فتح الدرج غير صحيح.');
    }

    $pdo = db();
    $st = $pdo->prepare(
        "SELECT e.id, e.branch_id, e.device_id, e.session_id, e.user_id, e.payload, e.created_at,
                b.name AS branch_name, b.phone AS branch_phone,
                d.name AS device_name,
                u.full_name AS cashier_name
           FROM sale_events e
           LEFT JOIN branches b ON b.id = e.branch_id
           LEFT JOIN sale_devices d ON d.id = e.device_id
           LEFT JOIN users u ON u.id = e.user_id
          WHERE e.id = ? AND e.event_type = 'drawer_open'
          LIMIT 1"
    );
    $st->execute([$eventId]);
    $event = $st->fetch(PDO::FETCH_ASSOC);
    if (!$event) {
        http_response_code(404);
        exit('سجل فتح الدرج مش موجود.');
    }

    $currentUser = current_user();
    $isOwner = !empty($currentUser['is_owner']) || is_real_owner();
    $isDrawerUser = !empty($currentUser['id']) && (int) ($event['user_id'] ?? 0) === (int) $currentUser['id'];
    if (!can('pos:drawer') && !$isOwner && !$isDrawerUser) {
        http_response_code(403);
        exit('مالكش صلاحية على الإيصال ده.');
    }

    require_once __DIR__ . '/sale_lib.php';

    $user = $currentUser;
    if ($isOwner) {
        $user['is_owner'] = 1;
    }

    try {
        sale_require_branch($pdo, (int) $event['branch_id'], $user);
    } catch (SaleApiException $e) {
        http_response_code($e->getCode() ?: 403);
        exit('مالكش صلاحية على الإيصال ده.');
    }

    $isDrawer = true;
    $isReprint = false;
    $isReturn = false;
    $payloadData = json_decode((string) ($event['payload'] ?? '{}'), true) ?: [];
    $drawerReason = trim((string) ($payloadData['reason'] ?? ''));
    $branchName = (string) ($event['branch_name'] ?? '');
    $branchPhone = (string) ($event['branch_phone'] ?? '');
    $receiptNo = '';
    $refReceiptNo = '';
    $createdAt = (string) ($event['created_at'] ?? '');
    $dt = $createdAt !== '' ? strtotime($createdAt) : false;
    $dateStr = $dt ? date('d/m/Y', $dt) : '';
    $timeStr = $dt ? date('h:i A', $dt) : '';
    $deviceName = (string) ($event['device_name'] ?? '');
    $cashierName = (string) ($event['cashier_name'] ?? '');
    $customerName = '';
    $lines = [];
    $payments = [];
    $gross = 0.0;
    $discount = 0.0;
    $net = 0.0;
    $paid = 0.0;
    $changeDue = 0.0;
    $returnDays = 0;
    $returnUntilDisplay = '';
    $pageTitle = 'إيصال فتح درج';
} else {
    $isDrawer = false;
    if (!can('pos:sell') && !is_real_owner()) {
        http_response_code(403);
        exit('مالكش صلاحية نقطة البيع.');
    }

    require_once __DIR__ . '/sale_lib.php';

    $docId = (int) ($_GET['id'] ?? 0);
    $forceReprint = !empty($_GET['reprint']);

    try {
        $payload = sale_receipt_payload($docId, $forceReprint);
    } catch (SaleApiException $e) {
        http_response_code($e->http);
        exit(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
    } catch (Throwable $e) {
        http_response_code(400);
        exit('طلب غير صالح.');
    }

    $isReprint = !empty($payload['is_reprint']);
    $isReturn = !empty($payload['is_return']);
    $branchName = (string) ($payload['branch_name'] ?? '');
    $branchPhone = (string) ($payload['branch_phone'] ?? '');
    $receiptNo = (string) ($payload['receipt_no'] ?? '');
    $refReceiptNo = (string) ($payload['ref_receipt_no'] ?? '');
    $dateStr = (string) ($payload['date'] ?? '');
    $timeStr = (string) ($payload['time'] ?? '');
    $deviceName = (string) ($payload['device_name'] ?? ($payload['device_code'] ?? ''));
    $cashierName = (string) ($payload['cashier_name'] ?? '');
    $customerName = (string) ($payload['customer_name'] ?? '');
    $lines = (array) ($payload['lines'] ?? []);
    $payments = (array) ($payload['payments'] ?? []);
    $gross = (float) ($payload['gross'] ?? 0.0);
    $discount = (float) ($payload['discount'] ?? 0.0);
    $net = (float) ($payload['net'] ?? 0.0);
    $paid = (float) ($payload['paid'] ?? 0.0);
    $changeDue = (float) ($payload['change_due'] ?? 0.0);
    $returnDays = (int) ($payload['return_days'] ?? 0);
    $returnUntil = (string) ($payload['return_until'] ?? '');
    $returnUntilDisplay = '';
    if ($returnUntil !== '') {
        $rTs = strtotime($returnUntil);
        if ($rTs !== false) {
            $returnUntilDisplay = date('d/m/Y', $rTs);
        }
    }
    $pageTitle = 'إيصال ' . $receiptNo;
}

require_once __DIR__ . '/barcode_lib.php';
$barcodeSvg = '';
if (!$isDrawer && $receiptNo !== '' && barcode_printable($receiptNo)) {
    $barcodeModule = barcode_fit_module($receiptNo, 68.0);
    $barcodeSvg = barcode_svg($receiptNo, 12.0, $barcodeModule);
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>try{const t=localStorage.getItem('fagala-theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="assets/fagala.css?v=38">
    <style>
        @page {
            size: 80mm auto;
            margin: 0;
        }

        body {
            margin: 0;
            padding: var(--sp-3) 0;
            background: var(--bg);
            color: var(--text);
            font-family: Tahoma, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: var(--fs-sm);
            line-height: 1.4;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .receipt-sheet {
            width: 80mm;
            max-width: 80mm;
            box-sizing: border-box;
            background: var(--bg-surface);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 14px 12px;
            margin: 0 auto;
        }

        .receipt-header {
            text-align: center;
            margin-bottom: 8px;
        }

        .receipt-branch {
            font-size: var(--fs-lg);
            font-weight: 700;
            margin-bottom: 2px;
        }

        .receipt-phone {
            font-size: var(--fs-xs);
            color: var(--muted);
            margin-bottom: 4px;
            direction: ltr;
        }

        .receipt-badge-reprint {
            display: inline-block;
            border: 2px solid var(--neg);
            color: var(--neg);
            font-weight: 700;
            font-size: var(--fs-md);
            padding: 1px 10px;
            border-radius: 4px;
            margin: 4px 0;
            letter-spacing: 2px;
        }

        .receipt-title {
            font-size: var(--fs-md);
            font-weight: 700;
            margin: 4px 0 2px;
        }

        .receipt-ref-no {
            font-size: var(--fs-xs);
            color: var(--muted);
            margin-bottom: 4px;
        }

        .receipt-divider-double {
            border-top: 3px double var(--line);
            margin: 8px 0;
        }

        .receipt-divider-solid {
            border-top: 1px solid var(--line);
            margin: 6px 0;
        }

        .receipt-divider-dash {
            border-top: 1px dashed var(--line);
            margin: 6px 0;
        }

        .receipt-meta {
            width: 100%;
            font-size: var(--fs-xs);
            margin-bottom: 4px;
        }

        .receipt-meta-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2px;
        }

        .receipt-meta-label {
            color: var(--muted);
        }

        .receipt-meta-val {
            font-weight: 600;
        }

        .receipt-items {
            width: 100%;
            margin: 4px 0;
        }

        .receipt-item-row {
            margin-bottom: 6px;
        }

        .receipt-item-name {
            font-weight: 600;
            font-size: var(--fs-sm);
            word-break: break-word;
        }

        .receipt-item-details {
            display: flex;
            justify-content: space-between;
            font-size: var(--fs-xs);
            color: var(--text);
            margin-top: 1px;
        }

        .receipt-item-calc {
            color: var(--muted);
        }

        .receipt-item-net {
            font-weight: 700;
            font-variant-numeric: tabular-nums;
        }

        .receipt-totals {
            width: 100%;
            margin: 4px 0;
        }

        .receipt-total-row {
            display: flex;
            justify-content: space-between;
            font-size: var(--fs-sm);
            margin-bottom: 2px;
        }

        .receipt-total-row.is-net {
            font-size: var(--fs-lg);
            font-weight: 700;
            margin-top: 4px;
            padding-top: 4px;
            border-top: 1px solid var(--line);
        }

        .receipt-payments {
            width: 100%;
            font-size: var(--fs-xs);
            margin: 4px 0;
        }

        .receipt-barcode {
            text-align: center;
            margin: 6px 0;
        }

        .receipt-barcode svg {
            display: block;
            margin: 0 auto;
            max-width: 100%;
            height: 12mm;
        }

        .receipt-barcode-text {
            font-size: var(--fs-xs);
            font-weight: 700;
            margin-top: 2px;
            letter-spacing: 1px;
            text-align: center;
        }

        .receipt-drawer-reason-box {
            margin: 8px 0;
            padding: 8px;
            background: var(--bg);
            border-radius: 4px;
        }

        .receipt-drawer-reason-label {
            font-size: var(--fs-xs);
            color: var(--muted);
            margin-bottom: 4px;
        }

        .receipt-drawer-reason-text {
            font-size: var(--fs-md);
            font-weight: 700;
            word-break: break-word;
        }

        .receipt-drawer-sign {
            margin: 16px 0 8px;
            font-size: var(--fs-sm);
            font-weight: 600;
            text-align: center;
        }

        .receipt-footer {
            text-align: center;
            font-size: var(--fs-xs);
            color: var(--muted);
            margin-top: 10px;
        }

        .receipt-return-policy {
            margin-bottom: 2px;
        }

        .receipt-return-until {
            font-weight: 700;
            margin-bottom: 4px;
        }

        .receipt-actions {
            margin: 12px auto 0;
            display: flex;
            justify-content: center;
            gap: var(--sp-2);
        }

        @media print {
            body {
                background: white !important;
                color: black !important;
                padding: 0 !important;
                margin: 0 !important;
                width: 80mm !important;
            }

            .receipt-sheet {
                width: 80mm !important;
                max-width: 80mm !important;
                border: none !important;
                border-radius: 0 !important;
                box-shadow: none !important;
                padding: 4mm 3mm !important;
                background: white !important;
                color: black !important;
            }

            .receipt-phone,
            .receipt-ref-no,
            .receipt-meta-label,
            .receipt-item-calc,
            .receipt-footer,
            .receipt-barcode-text,
            .receipt-drawer-reason-label,
            .receipt-drawer-reason-text,
            .receipt-drawer-sign {
                color: black !important;
            }

            .receipt-barcode svg {
                max-width: 100% !important;
            }

            .receipt-drawer-reason-box {
                background: white !important;
                border: 1px dashed black !important;
                padding: 4px !important;
            }

            .receipt-badge-reprint {
                border-color: black !important;
                color: black !important;
            }

            .receipt-divider-double {
                border-top-color: black !important;
            }

            .receipt-divider-solid {
                border-top-color: black !important;
            }

            .receipt-divider-dash {
                border-top-color: black !important;
            }

            .receipt-total-row.is-net {
                border-top-color: black !important;
            }

            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <div class="receipt-sheet fcard">
        <div class="receipt-header">
            <?php if ($isReprint): ?>
                <div><span class="receipt-badge-reprint">نسخة</span></div>
            <?php endif; ?>

            <div class="receipt-branch"><?= htmlspecialchars($branchName, ENT_QUOTES, 'UTF-8') ?></div>
            <?php if ($branchPhone !== ''): ?>
                <div class="receipt-phone"><?= htmlspecialchars($branchPhone, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <div class="receipt-title"><?= $isDrawer ? 'فتح درج' : ($isReturn ? 'إيصال مرتجع' : 'إيصال بيع') ?></div>
            <?php if (!$isDrawer && $isReturn && $refReceiptNo !== ''): ?>
                <div class="receipt-ref-no">فاتورة أصلية: <?= htmlspecialchars($refReceiptNo, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </div>

        <div class="receipt-divider-double"></div>

        <div class="receipt-meta">
            <?php if (!$isDrawer && $receiptNo !== ''): ?>
                <div class="receipt-meta-row">
                    <span class="receipt-meta-label">رقم الإيصال:</span>
                    <span class="receipt-meta-val" dir="ltr"><?= htmlspecialchars($receiptNo, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            <?php endif; ?>
            <div class="receipt-meta-row">
                <span class="receipt-meta-label">التاريخ والوقت:</span>
                <span class="receipt-meta-val"><?= htmlspecialchars($dateStr . ' ' . $timeStr, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="receipt-meta-row">
                <span class="receipt-meta-label">الجهاز:</span>
                <span class="receipt-meta-val"><?= htmlspecialchars($deviceName, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="receipt-meta-row">
                <span class="receipt-meta-label">الكاشير:</span>
                <span class="receipt-meta-val"><?= htmlspecialchars($cashierName, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <?php if ($customerName !== ''): ?>
                <div class="receipt-meta-row">
                    <span class="receipt-meta-label">العميل:</span>
                    <span class="receipt-meta-val"><?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($isDrawer): ?>
            <div class="receipt-divider-solid"></div>
            <div class="receipt-drawer-reason-box">
                <div class="receipt-drawer-reason-label">سبب فتح الدرج:</div>
                <div class="receipt-drawer-reason-text"><?= nl2br(htmlspecialchars($drawerReason, ENT_QUOTES, 'UTF-8')) ?></div>
            </div>
            <div class="receipt-divider-dash"></div>
            <div class="receipt-drawer-sign">
                توقيع المسؤول: ___________________
            </div>
        <?php else: ?>
            <div class="receipt-divider-solid"></div>

            <div class="receipt-items">
                <?php foreach ($lines as $line): ?>
                    <div class="receipt-item-row">
                        <div class="receipt-item-name"><?= htmlspecialchars($line['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="receipt-item-details">
                            <span class="receipt-item-calc">
                                <?= rtrim(rtrim(number_format((float) ($line['qty'] ?? 0), 2, '.', ''), '0'), '.') ?>
                                <?= htmlspecialchars($line['unit'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                × <?= number_format((float) ($line['price'] ?? 0), 2) ?>
                                <?php if (!empty($line['discount']) && (float) $line['discount'] > 0): ?>
                                    (خصم <?= number_format((float) $line['discount'], 2) ?>)
                                <?php endif; ?>
                            </span>
                            <span class="receipt-item-net"><?= number_format((float) ($line['net'] ?? 0), 2) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="receipt-divider-double"></div>

            <div class="receipt-totals">
                <div class="receipt-total-row">
                    <span>الإجمالي:</span>
                    <span dir="ltr"><?= number_format($gross, 2) ?></span>
                </div>
                <?php if ($discount > 0): ?>
                    <div class="receipt-total-row">
                        <span>الخصم:</span>
                        <span dir="ltr"><?= number_format($discount, 2) ?></span>
                    </div>
                <?php endif; ?>
                <div class="receipt-total-row is-net">
                    <span>الصافي:</span>
                    <span dir="ltr"><?= number_format($net, 2) ?> ج.م</span>
                </div>
            </div>

            <div class="receipt-divider-solid"></div>

            <div class="receipt-payments">
                <?php foreach ($payments as $p): ?>
                    <div class="receipt-total-row">
                        <span>دفع <?= htmlspecialchars($p['name'] ?? $p['method'], ENT_QUOTES, 'UTF-8') ?>:</span>
                        <span dir="ltr"><?= number_format((float) ($p['amount'] ?? 0), 2) ?></span>
                    </div>
                <?php endforeach; ?>
                <div class="receipt-total-row">
                    <span>المدفوع:</span>
                    <span dir="ltr"><?= number_format($paid, 2) ?></span>
                </div>
                <div class="receipt-total-row">
                    <span>الباقي:</span>
                    <span dir="ltr"><?= number_format($changeDue, 2) ?></span>
                </div>
            </div>

            <?php if ($barcodeSvg !== ''): ?>
                <div class="receipt-divider-dash"></div>
                <div class="receipt-barcode">
                    <?= $barcodeSvg ?>
                    <div class="receipt-barcode-text" dir="ltr"><?= htmlspecialchars($receiptNo, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="receipt-divider-dash"></div>

        <div class="receipt-footer">
            <?php if (!$isDrawer && !$isReturn && $returnDays > 0 && $returnUntilDisplay !== ''): ?>
                <div class="receipt-return-policy">الاستبدال والاسترجاع خلال <?= $returnDays ?> يوم بأصل الإيصال</div>
                <div class="receipt-return-until"><strong>حتى يوم <?= htmlspecialchars($returnUntilDisplay, ENT_QUOTES, 'UTF-8') ?></strong></div>
            <?php endif; ?>
            <div>شكراً لتعاملكم معنا</div>
        </div>
    </div>

    <div class="receipt-actions no-print">
        <button type="button" class="btn btn-primary" onclick="window.print()">
            <i class="ti ti-printer"></i> طباعة
        </button>
        <button type="button" class="btn btn-outline" onclick="window.close()">
            إغلاق
        </button>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const params = new URLSearchParams(window.location.search);
        if (!params.has('noprint') && window.location.protocol !== 'file:') {
            setTimeout(function () {
                window.print();
            }, 300);
        }
    });
    </script>
</body>
</html>
