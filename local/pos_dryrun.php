<?php
/**
 * ===========================================================================
 *  pos_dryrun.php  —  معاينة ترحيل الفواتير
 * ===========================================================================
 *
 *  🔒 الصفحة دي لا تكتب أي شيء على SQL Server.
 *
 *     كل اللي بتعمله:
 *       1. تقرأ العدادات من نظام الشركة   (SELECT فقط)
 *       2. تبني نصوص SQL كـ strings
 *       3. تعرضها على الشاشة
 *
 *     مفيش INSERT. مفيش UPDATE. مفيش DELETE.
 *
 *  الغرض: تراجع الـSQL بعينك وتقارنه بالنصوص الحقيقية اللي برنامج
 *         الشركة بيولّدها، قبل أي تنفيذ فعلي.
 * ===========================================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/pos_migrate_lib.php';

require_login();

// الترحيل عملية خطيرة — المالك بس
if (!is_owner()) {
    http_response_code(403);
    exit('غير مصرح');
}

$pdo      = db();
$shiftId  = (int)($_GET['shift'] ?? 0);
$error    = null;
$result   = null;

/* ---------------------------------------------------------------------------
 *  دالة القراءة من SQL Server عن طريق البريدج
 *  ⚠️ SELECT فقط — أي أمر تاني مرفوض.
 * ------------------------------------------------------------------------- */
$sqlsrvRead = function (string $sql) {
    $trimmed = ltrim($sql);
    if (stripos($trimmed, 'SELECT') !== 0) {
        throw new RuntimeException('وضع المعاينة: SELECT فقط مسموح');
    }
    return sqlsrv_query($sql);
};


if ($shiftId > 0) {
    try {
        /* -------- 1. الوردية -------- */
        $st = $pdo->prepare('SELECT * FROM pos_shifts WHERE id = ?');
        $st->execute([$shiftId]);
        $shift = $st->fetch();
        if (!$shift) throw new RuntimeException('الوردية غير موجودة');

        /* -------- 2. خريطة الفرع -------- */
        $st = $pdo->prepare('SELECT * FROM pos_branch_map WHERE branch_id = ?');
        $st->execute([(int)$shift['branch_id']]);
        $cfg = $st->fetch();
        if (!$cfg) throw new RuntimeException('الفرع غير معرّف في pos_branch_map');

        // بادئة القاعدة — لازمة لأن الـ DSN بتاع ODBC ممكن يكون
        // واقف على قاعدة تانية (master مثلًا).
        $dbName = (string)$cfg['sqlsrv_db'];
        $px     = $dbName . '.dbo.';

        /* -------- 3. الفواتير -------- */
        $st = $pdo->prepare(
            "SELECT * FROM pos_invoices
             WHERE shift_id = ? AND status = 'completed'
             ORDER BY sold_at, id"
        );
        $st->execute([$shiftId]);
        $invoices = $st->fetchAll();
        if (!$invoices) throw new RuntimeException('لا توجد فواتير جاهزة للترحيل');

        /* -------- 4. فحوصات ما قبل الترحيل -------- */
        $checks = [];
        $ym = date('y_m', strtotime($invoices[0]['sold_at']));

        // (أ) جداول الشهر موجودة؟
        $tablesOk = pos_month_tables_exist($sqlsrvRead, $ym, $dbName);
        $checks[] = [
            'name' => "جداول الشهر ({$ym}) موجودة",
            'ok'   => $tablesOk,
            'note' => $tablesOk ? '' : 'الجداول الشهرية لم تُنشأ بعد في نظام الشركة',
        ];

        // (ب) فيه وردية مفتوحة؟
        $openShift = pos_open_shift($sqlsrvRead, (int)$cfg['branch_id'], $px);
        $checks[] = [
            'name' => 'وردية مفتوحة في نظام الشركة',
            'ok'   => $openShift !== null,
            'note' => $openShift
                    ? "PS_ID = {$openShift['ps_id']} · خزنة {$openShift['safe_id']}"
                    : 'لا توجد وردية مفتوحة — الفواتير لن تدخل على خزنة الكاشير',
        ];

        // (ج) فيه فواتير معلّقة في نظام الشركة؟
        //     ★ حرج: الفاتورة المعلّقة بتحجز sth_id و sth_doc_no من ساعة
        //       الحفظ. لو الكاشير رحّلها في نفس اللحظة اللي بنرحّل فيها
        //       → تضارب أرقام. لازم يخلّص المعلّق الأول.
        //
        //     ملاحظة: لو الاستعلام نفسه فشل (جدول مش موجود مثلًا)، مابنوقفش
        //     المعاينة — بنعرض المشكلة كتحذير. التنفيذ الفعلي هيمنع.
        try {
            $pending = pos_pending_invoices($sqlsrvRead, (int)$cfg['branch_id'], $px);
            $checks[] = [
                'name' => 'لا توجد فواتير معلّقة في نظام الشركة',
                'ok'   => $pending === 0,
                'note' => $pending === 0
                        ? ''
                        : "فيه {$pending} فاتورة محفوظة بدون ترحيل — لازم الكاشير يرحّلها أو يلغيها قبل الترحيل الفعلي",
            ];
        } catch (Throwable $pe) {
            $checks[] = [
                'name' => 'فحص الفواتير المعلّقة',
                'ok'   => false,
                'note' => 'تعذّر الفحص: ' . $pe->getMessage(),
            ];
        }

        // (د) الفرع مفعّل؟
        $checks[] = [
            'name' => 'الترحيل مفعّل للفرع',
            'ok'   => (int)$cfg['is_active'] === 1,
            'note' => (int)$cfg['is_active'] === 1
                    ? ''
                    : 'is_active = 0 → الترحيل الفعلي موقوف (المعاينة تعمل عادي)',
        ];

        // في المعاينة: الفواتير المعلّقة تحذير بس (مش مانع) — عشان تقدر
        // تشوف الـSQL وتراجعه. التنفيذ الفعلي هيمنع.
        if (!$tablesOk) {
            throw new RuntimeException("جداول الشهر ({$ym}) مش موجودة في نظام الشركة");
        }
        if (!$openShift) {
            throw new RuntimeException('مفيش وردية مفتوحة في نظام الشركة');
        }

        /* -------- 5. حجز العدادات (قراءة فقط) -------- */
        $counters = pos_read_counters($sqlsrvRead, $ym, $px);

        /* -------- 6. بناء الفواتير -------- */
        $built    = [];
        $totalOps = 0;
        $dayDoc   = [];   // آخر رقم مستند لكل يوم (للمعاينة بس)
        $maxDrs   = pos_max_drs_id($sqlsrvRead, $px);   // آخر drs_id

        foreach ($invoices as $inv) {
            $st = $pdo->prepare(
                'SELECT * FROM pos_invoice_lines WHERE invoice_id = ? ORDER BY line_no'
            );
            $st->execute([(int)$inv['id']]);
            $lines = $st->fetchAll();
            if (!$lines) continue;

            // رقم المستند بيتصفّر يوميًا — بنحسبه لكل فاتورة على يوم بيعها.
            //
            // ملاحظة على المعاينة: الفواتير مش بتتكتب فعليًا، فالـ MAX في
            // القاعدة مش بيزيد. عشان كده بنمسك آخر رقم لكل يوم يدويًا،
            // وإلا فاتورتين في نفس اليوم هياخدوا نفس الرقم.
            // (في التنفيذ الفعلي ده مش بيحصل — الفاتورة بتتكتب فبيزيد لوحده.)
            $saleDay = date('Y-m-d', strtotime($inv['sold_at']));

            if (isset($dayDoc[$saleDay])) {
                $counters['next_doc_no'] = (string)(++$dayDoc[$saleDay]);
            } else {
                $counters['next_doc_no'] = pos_next_doc_no(
                    $sqlsrvRead, $ym, (int)$cfg['branch_id'], $saleDay, $px
                );
                $dayDoc[$saleDay] = (int)$counters['next_doc_no'];
            }

            $b = pos_build_invoice($inv, $lines, $cfg, $counters, $openShift);

            // ★ الطابور للفروع بس (emit_repl=1). الإدارة (emit_repl=0) بتستقبل بس.
            $emitRepl = (int)($cfg['emit_repl'] ?? 1) === 1;
            $b['repl_wrapped'] = $emitRepl
                ? pos_wrap_repl($b['repl'], $cfg, $b['ids'], $maxDrs)
                : [];

            // في المعاينة الأوامر مش بتتنفذ، فبنزوّد drs_id يدويًا
            $maxDrs += $emitRepl ? count($b['repl']) : 0;
            $b['invoice']      = $inv;
            $b['lines']        = $lines;

            $built[]   = $b;
            $totalOps += count($b['sql']) + count($b['repl_wrapped']);

            // ★ مهم: نزوّد العدادات يدويًا للفاتورة اللي بعدها
            //   (في التنفيذ الفعلي ده بيحصل جوه transaction واحدة)
            $counters['max_sth_id']     = $b['ids']['sth_id'];
            $counters['max_sth_id_ref'] = $b['ids']['sth_id_ref'];
            $counters['max_ath_id']     = $b['ids']['ath_id'];
            $counters['max_ath_id_ref'] = $b['ids']['ath_id_ref'];
            $counters['max_stil_id']    = $b['ids']['stil_id'];
            $counters['max_atg_id']     = $b['ids']['atg_id'];
            // doc_no بيتحسب من القاعدة لكل فاتورة، فمش بنزوّده يدويًا هنا.
        }

        $result = [
            'shift'     => $shift,
            'cfg'       => $cfg,
            'checks'    => $checks,
            'built'     => $built,
            'total_ops' => $totalOps,
            'open_shift'=> $openShift,
        ];

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

/* -------- قائمة الورديات الجاهزة -------- */
$shifts = $pdo->query(
    "SELECT s.*, COUNT(i.id) AS inv_count
     FROM pos_shifts s
     LEFT JOIN pos_invoices i ON i.shift_id = s.id AND i.status = 'completed'
     WHERE s.status = 'closed'
     GROUP BY s.id
     HAVING inv_count > 0
     ORDER BY s.closed_at DESC
     LIMIT 20"
)->fetchAll();

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>معاينة الترحيل</title>
<style>
  body{font-family:Tahoma,Arial,sans-serif;background:#f4f6f8;margin:0;padding:24px;color:#1f2933}
  .wrap{max-width:1100px;margin:0 auto}
  h1{font-size:22px;margin:0 0 4px}

  .banner{background:var(--warn-soft);border:2px solid #f59e0b;border-radius:10px;
          padding:14px 18px;margin-bottom:22px;font-weight:bold;color:#78350f}

  .card{background:#fff;border-radius:12px;padding:20px;margin-bottom:18px;
        box-shadow:0 1px 6px rgba(0,0,0,.06)}
  .card h2{font-size:16px;margin:0 0 14px;color:#374151}

  table{width:100%;border-collapse:collapse;font-size:14px}
  th,td{padding:9px 10px;text-align:right;border-bottom:1px solid #eef1f4}
  th{background:#f9fafb;font-weight:bold;color:var(--ink-500)}

  .chk{display:flex;align-items:center;gap:10px;padding:9px 0;
       border-bottom:1px solid #f3f4f6;font-size:14px}
  .chk:last-child{border-bottom:0}
  .ok{color:#059669;font-weight:bold}
  .bad{color:var(--neg);font-weight:bold}
  .note{color:var(--ink-500);font-size:12px;margin-right:auto}

  .ids{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}
  .id-box{background:#f9fafb;border-radius:8px;padding:9px 12px}
  .id-box .k{font-size:11px;color:var(--ink-500)}
  .id-box .v{font-size:15px;font-weight:bold;font-family:monospace;color:#111827}

  pre{background:#0f172a;color:#e2e8f0;padding:14px;border-radius:8px;
      overflow-x:auto;font-size:12px;line-height:1.7;direction:ltr;text-align:left;
      margin:0;white-space:pre-wrap;word-break:break-all}
  .sec{margin-bottom:14px}
  .sec-t{font-size:13px;font-weight:bold;color:#374151;margin-bottom:7px}

  .err{background:#fef2f2;border:2px solid var(--neg);color:#991b1b;
       padding:14px;border-radius:10px;margin-bottom:18px}

  .btn{display:inline-block;background:#6366f1;color:#fff;padding:9px 18px;
       border-radius:8px;text-decoration:none;font-size:14px;border:0;cursor:pointer}
  .btn-off{background:#d1d5db;color:var(--ink-500);cursor:not-allowed}
  .rmk{background:#ecfdf5;border-right:4px solid #059669;padding:9px 12px;
       border-radius:6px;font-size:13px;margin-bottom:12px}
</style>
</head>
<body>
<div class="wrap">

  <h1>معاينة الترحيل</h1>

  <div class="banner">
    🔒 وضع المعاينة — لا يتم تنفيذ أي شيء على نظام الشركة.
    الصفحة تقرأ العدادات فقط وتبني نصوص SQL للمراجعة.
  </div>

  <?php if ($error): ?>
    <div class="err"><strong>خطأ:</strong> <?= e($error) ?></div>
  <?php endif; ?>

  <?php if (!$result): ?>
    <div class="card">
      <h2>اختر وردية مقفولة</h2>
      <?php if (!$shifts): ?>
        <p style="color:var(--ink-500)">لا توجد ورديات مقفولة بها فواتير.</p>
      <?php else: ?>
        <table>
          <tr><th>#</th><th>الفرع</th><th>الإقفال</th><th>الفواتير</th><th>الإجمالي</th><th></th></tr>
          <?php foreach ($shifts as $s): ?>
          <tr>
            <td><?= (int)$s['id'] ?></td>
            <td><?= e((string)$s['branch_id']) ?></td>
            <td><?= e((string)$s['closed_at']) ?></td>
            <td><?= (int)$s['inv_count'] ?></td>
            <td><?= number_format((float)$s['total_sales'], 2) ?></td>
            <td><a class="btn" href="?shift=<?= (int)$s['id'] ?>">معاينة</a></td>
          </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </div>

  <?php else: ?>

    <!-- الفحوصات -->
    <div class="card">
      <h2>فحوصات ما قبل الترحيل</h2>
      <?php foreach ($result['checks'] as $c): ?>
        <div class="chk">
          <span class="<?= $c['ok'] ? 'ok' : 'bad' ?>"><?= $c['ok'] ? '✓' : '✗' ?></span>
          <span><?= e($c['name']) ?></span>
          <?php if ($c['note']): ?><span class="note"><?= e($c['note']) ?></span><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- الملخص -->
    <div class="card">
      <h2>الملخص</h2>
      <table>
        <tr><th>الفرع</th><td><?= e($result['cfg']['branch_name']) ?>
            (BRCH_ID = <?= (int)$result['cfg']['branch_id'] ?>)</td></tr>
        <tr><th>قاعدة البيانات</th><td><?= e($result['cfg']['sqlsrv_db']) ?></td></tr>
        <tr><th>وردية الشركة</th><td>PS_ID = <?= (int)$result['open_shift']['ps_id'] ?>
            · خزنة <?= (int)$result['open_shift']['safe_id'] ?></td></tr>
        <tr><th>عدد الفواتير</th><td><?= count($result['built']) ?></td></tr>
        <tr><th>إجمالي الأوامر</th><td><strong><?= (int)$result['total_ops'] ?></strong></td></tr>
      </table>
    </div>

    <!-- الفواتير -->
    <?php foreach ($result['built'] as $b):
        $inv = $b['invoice']; $ids = $b['ids']; ?>
    <div class="card">
      <h2>فاتورة #<?= (int)$inv['id'] ?> —
          <?= number_format((float)$inv['net_value'], 2) ?> ج.م ·
          <?= count($b['lines']) ?> صنف</h2>

      <div class="rmk">📝 <strong>الملاحظة:</strong> <?= e($ids['remark']) ?></div>

      <div class="sec">
        <div class="sec-t">الأرقام المحجوزة</div>
        <div class="ids">
          <div class="id-box"><div class="k">STH_ID</div><div class="v"><?= $ids['sth_id'] ?></div></div>
          <div class="id-box"><div class="k">STH_ID_REF</div><div class="v"><?= $ids['sth_id_ref'] ?></div></div>
          <div class="id-box"><div class="k">ATH_ID</div><div class="v"><?= $ids['ath_id'] ?></div></div>
          <div class="id-box"><div class="k">ATH_ID_REF</div><div class="v"><?= $ids['ath_id_ref'] ?></div></div>
          <div class="id-box"><div class="k">STIL_ID</div><div class="v"><?= $ids['stil_id'] ?></div></div>
          <div class="id-box"><div class="k">ATG_ID</div><div class="v"><?= $ids['atg_id'] ?></div></div>
          <div class="id-box"><div class="k">DOC_NO</div><div class="v"><?= e($ids['doc_no']) ?></div></div>
          <div class="id-box"><div class="k">التكلفة</div><div class="v"><?= number_format($ids['total_cost'], 2) ?></div></div>
        </div>
      </div>

      <div class="sec">
        <div class="sec-t">
          الجداول الحقيقية — <?= count($b['sql']) ?> أمر
          <span style="color:var(--ink-500);font-weight:normal">(عشان الفرع يشوف الفاتورة)</span>
        </div>
        <pre><?= e(implode(";\n\n", $b['sql'])) ?>;</pre>
      </div>

      <div class="sec">
        <div class="sec-t">
          طابور المزامنة — <?= count($b['repl_wrapped']) ?> أمر
          <span style="color:var(--ink-500);font-weight:normal">(عشان الإدارة تستقبلها)</span>
        </div>
        <pre><?= e(implode(";\n\n", $b['repl_wrapped'])) ?>;</pre>
      </div>
    </div>
    <?php endforeach; ?>

    <div class="card" style="text-align:center">
      <button class="btn btn-off" disabled>تنفيذ فعلي (غير مفعّل)</button>
      <p style="color:var(--ink-500);font-size:13px;margin:12px 0 0">
        التنفيذ الفعلي سيُفعّل بعد مراجعة الـSQL أعلاه ومطابقته<br>
        بالنصوص التي يولّدها برنامج الشركة.
      </p>
    </div>

  <?php endif; ?>

</div>
</body>
</html>
