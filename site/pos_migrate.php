<?php
require_once __DIR__ . '/includes/site_profile.php';
if (!site_profile()['true_integration']) { http_response_code(404); exit; }

/**
 * ===========================================================================
 *  pos_migrate.php  —  الترحيل الفعلي لنظام الشركة
 * ===========================================================================
 *
 *  ⚠️⚠️  الصفحة دي بتكتب فعليًا في قاعدة الشركة.  ⚠️⚠️
 *
 *  الحمايات:
 *     1. المالك بس
 *     2. is_active = 1 في pos_branch_map  (مقفول افتراضيًا)
 *     3. WRITE_ENABLED = true في bridge_write.php  (مقفول افتراضيًا)
 *     4. مفيش فواتير معلّقة في نظام الشركة
 *     5. تأكيد نصي مكتوب باليد
 *     6. كل فاتورة في transaction مستقلة
 *     7. كل عملية بتتسجل في pos_migration_log
 *
 *  ليه كل فاتورة لوحدها مش كلهم مرة واحدة؟
 *     لو فاتورة رقم 5 من 10 فشلت، اللي قبلها (1-4) تفضل مكتوبة صح،
 *     ومانرجعش من الأول. نصلّح المشكلة ونكمّل من 5.
 *
 *     ولو بعتناهم كلهم في transaction واحدة، أي فشل = رجوع كامل،
 *     وده وجع في ورديات كبيرة.
 * ===========================================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/pos_migrate_lib.php';

require_login();

if (!is_owner()) {
    http_response_code(403);
    exit('غير مصرح');
}

$pdo     = db();
$shiftId = (int)($_POST['shift'] ?? $_GET['shift'] ?? 0);
$confirm = trim((string)($_POST['confirm'] ?? ''));
// CSRF إجباري: دي أخطر عملية في النظام (كتابة في قاعدة الشركة)
$csrfOk  = ($_SERVER['REQUEST_METHOD'] !== 'POST') || csrf_verify($_POST['csrf'] ?? '');
$doRun   = ($_SERVER['REQUEST_METHOD'] === 'POST' && $csrfOk && $confirm === 'ترحيل');
$error   = null;
$results = [];
$summary = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$csrfOk) {
    $error = 'انتهت صلاحية الجلسة، اعمل تحديث للصفحة وحاول تاني.';
}

/* دالة القراءة (SELECT فقط) عبر جسر القراءة */
$read = function (string $sql) {
    if (stripos(ltrim($sql), 'SELECT') !== 0) {
        throw new RuntimeException('القراءة: SELECT فقط');
    }
    return sqlsrv_query($sql);
};


if ($shiftId > 0 && $doRun) {
    try {
        /* ---------- 1. الوردية ---------- */
        $st = $pdo->prepare("SELECT * FROM pos_shifts WHERE id = ?");
        $st->execute([$shiftId]);
        $shift = $st->fetch();
        if (!$shift)                       throw new RuntimeException('الوردية غير موجودة');
        if ($shift['status'] === 'migrated') throw new RuntimeException('الوردية دي اترحّلت قبل كده');
        if ($shift['status'] !== 'closed')   throw new RuntimeException('الوردية لازم تكون مقفولة الأول');

        /* ---------- 2. الفرع ---------- */
        $st = $pdo->prepare('SELECT * FROM pos_branch_map WHERE branch_id = ?');
        $st->execute([(int)$shift['branch_id']]);
        $cfg = $st->fetch();
        if (!$cfg) throw new RuntimeException('الفرع غير معرّف في pos_branch_map');

        // ★ السويتش
        if ((int)$cfg['is_active'] !== 1) {
            throw new RuntimeException(
                'الترحيل موقوف لهذا الفرع. غيّر is_active = 1 في pos_branch_map.'
            );
        }

        $dbName = (string)$cfg['sqlsrv_db'];
        $px     = $dbName . '.dbo.';

        /* ---------- 3. الفواتير ---------- */
        $st = $pdo->prepare(
            "SELECT * FROM pos_invoices
             WHERE shift_id = ? AND status = 'completed'
             ORDER BY sold_at, id"
        );
        $st->execute([$shiftId]);
        $invoices = $st->fetchAll();
        if (!$invoices) throw new RuntimeException('مفيش فواتير جاهزة للترحيل');

        $ym = date('y_m', strtotime($invoices[0]['sold_at']));

        /* ---------- 4. الفحوصات ---------- */
        if (!pos_month_tables_exist($read, $ym, $dbName)) {
            throw new RuntimeException("جداول الشهر ({$ym}) مش موجودة في نظام الشركة");
        }

        $openShift = pos_open_shift($read, (int)$cfg['branch_id'], $px);
        if (!$openShift) {
            throw new RuntimeException(
                'مفيش وردية مفتوحة في نظام الشركة — الفلوس مش هتوصل خزنة الكاشير'
            );
        }

        // ★ الفواتير المعلّقة: خطر تضارب أرقام
        $pending = pos_pending_invoices($read, (int)$cfg['branch_id'], $px);
        if ($pending > 0) {
            throw new RuntimeException(
                "فيه {$pending} فاتورة معلّقة في نظام الشركة (محفوظة بدون ترحيل). "
                . "لازم الكاشير يرحّلها أو يلغيها الأول، وإلا هيحصل تضارب في الأرقام."
            );
        }

        /* ---------- 5. الترحيل: فاتورة فاتورة ---------- */
        $ok = 0; $fail = 0;

        $stLines = $pdo->prepare(
            'SELECT * FROM pos_invoice_lines WHERE invoice_id = ? ORDER BY line_no'
        );
        $stUpdateSuccess = $pdo->prepare(
            "UPDATE pos_invoices SET
                status = 'migrated', sql_sth_id = ?, sql_sth_id_ref = ?,
                sql_ath_id = ?, sql_doc_no = ?, sql_ps_id = ?,
                migrated_at = NOW(), migrate_error = NULL
             WHERE id = ?"
        );
        $stLogSuccess = $pdo->prepare(
            "INSERT INTO pos_migration_log
                (shift_id, invoice_id, branch_id, mode, result,
                 sth_id, sth_id_ref, ath_id, stil_id, atg_id, doc_no, ps_id,
                 sql_dump, duration_ms, created_by)
             VALUES (?,?,?,'live','success',?,?,?,?,?,?,?,?,?,?)"
        );
        $stUpdateFail = $pdo->prepare(
            "UPDATE pos_invoices SET status='failed', migrate_error=? WHERE id=?"
        );
        $stLogFail = $pdo->prepare(
            "INSERT INTO pos_migration_log
                (shift_id, invoice_id, branch_id, mode, result,
                 sql_dump, error_msg, created_by)
             VALUES (?,?,?,'live','failed',?,?,?)"
        );

        foreach ($invoices as $idx => $inv) {

            // ★★ العدادات بتتقري من جديد قبل كل فاتورة ★★
            // مش مرة واحدة في الأول — عشان لو حد ضرب فاتورة في نظام
            // الشركة أثناء الترحيل، نشوف رقمه ومناخدش نفس الرقم.
            $ctr = pos_read_counters($read, $ym, $px);

            // رقم المستند بيتصفّر يوميًا، فبنحسبه على يوم البيع الفعلي
            // (مش يوم الترحيل) — عشان لو رحّلنا بعد منتصف الليل، الفاتورة
            // تاخد رقم يومها الصح.
            $saleDay = date('Y-m-d', strtotime($inv['sold_at']));
            $ctr['next_doc_no'] = pos_next_doc_no(
                $read, $ym, (int)$cfg['branch_id'], $saleDay, $px
            );

            $stLines->execute([(int)$inv['id']]);
            $lines = $stLines->fetchAll();

            if (!$lines) {
                $results[] = [
                    'inv' => (int)$inv['id'], 'ok' => false,
                    'msg' => 'الفاتورة مفيهاش أصناف — تم تخطيها',
                ];
                continue;
            }

            // drs_id إجباري في db_repl_send ومفيش IDENTITY، فبنقراه
            // قبل كل فاتورة (عشان لو حد كتب في الطابور أثناء الترحيل).
            $maxDrs = pos_max_drs_id($read, $px);

            $built = pos_build_invoice($inv, $lines, $cfg, $ctr, $openShift);

            // ★ الطابور (db_repl_send) يتكتب للفروع بس.
            //   الإدارة نهاية الخط (بتستقبل بس) → emit_repl = 0 → 11 أمر.
            //   الفرع → emit_repl = 1 → 23 أمر (11 حقيقي + 12 طابور).
            $emitRepl = (int)($cfg['emit_repl'] ?? 1) === 1;
            $all = $emitRepl
                ? array_merge($built['sql'], pos_wrap_repl($built['repl'], $cfg, $built['ids'], $maxDrs))
                : $built['sql'];

            $t0 = microtime(true);

            try {
                // ★ الكتابة الفعلية — transaction واحدة جوه الجسر
                $res = pos_bridge_write($all);
                $ms  = (int)round((microtime(true) - $t0) * 1000);

                // تحديث الفاتورة عندنا
                $stUpdateSuccess->execute([
                    $built['ids']['sth_id'], $built['ids']['sth_id_ref'],
                    $built['ids']['ath_id'], $built['ids']['doc_no'],
                    $built['ids']['ps_id'], (int)$inv['id'],
                ]);

                // السجل
                $stLogSuccess->execute([
                    $shiftId, (int)$inv['id'], (int)$cfg['branch_id'],
                    $built['ids']['sth_id'], $built['ids']['sth_id_ref'],
                    $built['ids']['ath_id'], $built['ids']['stil_id'],
                    $built['ids']['atg_id'], $built['ids']['doc_no'],
                    $built['ids']['ps_id'],
                    implode(";\n", $all), $ms, (int)current_user()['id'],
                ]);

                $ok++;
                $results[] = [
                    'inv'    => (int)$inv['id'],
                    'ok'     => true,
                    'sth_id' => $built['ids']['sth_id'],
                    'doc_no' => $built['ids']['doc_no'],
                    'ops'    => count($all),
                    'ms'     => $ms,
                ];

            } catch (Throwable $e) {
                $fail++;

                $stUpdateFail->execute([$e->getMessage(), (int)$inv['id']]);

                $stLogFail->execute([
                    $shiftId, (int)$inv['id'], (int)$cfg['branch_id'],
                    implode(";\n", $all), $e->getMessage(),
                    (int)current_user()['id'],
                ]);

                $results[] = [
                    'inv' => (int)$inv['id'], 'ok' => false,
                    'msg' => $e->getMessage(),
                ];

                // ★ وقف فورًا عند أول فشل.
                //   الفواتير اللي اتكتبت قبلها سليمة (كل واحدة transaction مستقلة).
                //   بس مانكملش — لأن الفشل ممكن يكون سببه مشكلة عامة
                //   (تضارب أرقام، الجسر وقع) وهتفضل تتكرر.
                break;
            }

            // ديلاي بسيط بين الفواتير — عشان الأوقات تبان مرتبة
            // في نظام الشركة ومايحصلش تداخل.
            if ($idx < count($invoices) - 1) {
                usleep(400000);   // 0.4 ثانية
            }
        }

        /* ---------- 6. تحديث الوردية ---------- */
        if ($fail === 0 && $ok > 0) {
            $pdo->prepare(
                "UPDATE pos_shifts SET status='migrated', migrated_at=NOW(), migrated_by=?
                 WHERE id=?"
            )->execute([(int)current_user()['id'], $shiftId]);
        }

        $summary = ['ok' => $ok, 'fail' => $fail, 'total' => count($invoices)];

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

/* ---------- الورديات الجاهزة ---------- */
$shifts = $pdo->query(
    "SELECT s.*, COUNT(i.id) AS inv_count, COALESCE(SUM(i.net_value),0) AS total
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
<title>ترحيل البيانات</title>
<link rel="stylesheet" href="assets/tabler-subset.css">
<link rel="stylesheet" href="assets/fagala.css?v=39">
<style>
  body{margin:0;padding:24px}
  .wrap{max-width:900px;margin:0 auto}
  h1{font-size:22px;margin:0 0 18px}
  .danger b{display:block;margin-bottom:6px;font-size:15px}
  .ok-row{color:var(--pos);font-weight:bold}
  .bad-row{color:var(--neg);font-weight:bold}
  .sum .n{font-size:22px;font-weight:bold}
</style>
</head>
<body>
<div class="wrap">

  <h1>ترحيل البيانات لنظام الشركة</h1>

  <div class="falert falert-neg danger">
    <b><i class="ti ti-alert-triangle"></i> الصفحة دي بتكتب فعليًا في نظام الشركة</b>
    قبل الترحيل، تأكد إن الكاشير خلّص كل الفواتير المعلّقة (رحّلها أو لغاها)،
    وإنه واقف ومش بيضرب فواتير دلوقتي.
  </div>

  <?php if ($error): ?>
    <div class="falert falert-neg"><strong>خطأ:</strong> <?= e($error) ?></div>
  <?php endif; ?>

  <?php if ($summary): ?>
    <div class="fcard">
      <h2>نتيجة الترحيل</h2>
      <div class="fkpis sum" data-xt-kpis="#posMigrateResults">
        <div class="fkpi"><div class="n" style="color:var(--pos)"><span data-xt-sum="ok" data-xt-dec="0"><?= $summary['ok'] ?></span></div>نجحت</div>
        <div class="fkpi"><div class="n" style="color:var(--neg)"><span data-xt-sum="fail" data-xt-dec="0"><?= $summary['fail'] ?></span></div>فشلت</div>
        <div class="fkpi"><div class="n"><span data-xt-sum="total" data-xt-dec="0"><?= $summary['total'] ?></span></div>الإجمالي</div>
      </div>
      <table class="ftable" id="posMigrateResults">
        <thead><tr><th class="num">الفاتورة</th><th>الحالة</th><th class="num">STH_ID</th><th class="num">DOC_NO</th><th class="num">الأوامر</th><th class="num">الوقت</th></tr></thead><tbody>
        <?php foreach ($results as $r): ?>
        <tr data-v-ok="<?= $r['ok'] ? 1 : 0 ?>" data-v-fail="<?= $r['ok'] ? 0 : 1 ?>" data-v-total="1">
          <td class="num">#<?= (int)$r['inv'] ?></td>
          <td class="<?= $r['ok'] ? 'ok-row' : 'bad-row' ?>">
            <?= $r['ok'] ? '✓ اترحّلت' : '✗ ' . e($r['msg'] ?? '') ?>
          </td>
          <td class="num"><?= $r['ok'] ? (int)$r['sth_id'] : '—' ?></td>
          <td class="num"><?= $r['ok'] ? e((string)$r['doc_no']) : '—' ?></td>
          <td class="num"><?= $r['ok'] ? (int)$r['ops'] : '—' ?></td>
          <td class="num"><?= $r['ok'] ? (int)$r['ms'] . ' ms' : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody></table>
      <?php if ($summary['fail'] > 0): ?>
        <p style="color:var(--neg);font-size:13px;margin-top:14px">
          <i class="ti ti-alert-triangle"></i> الفواتير اللي نجحت اتكتبت فعليًا في نظام الشركة.
          الفواتير اللي فشلت لم تُكتب (تم التراجع).
          صلّح المشكلة وارجع رحّل الوردية تاني — اللي اترحّل مش هيتكرر.
        </p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="fcard">
    <h2>الورديات المقفولة</h2>
    <?php if (!$shifts): ?>
      <p style="color:var(--ink-500)">مفيش ورديات جاهزة.</p>
    <?php else: ?>
      <table class="ftable">
        <tr><th class="num">#</th><th class="num">الفرع</th><th>الإقفال</th><th class="num">الفواتير</th><th class="num">الإجمالي</th><th></th></tr>
        <?php foreach ($shifts as $s): ?>
        <tr>
          <td class="num"><?= (int)$s['id'] ?></td>
          <td class="num"><?= (int)$s['branch_id'] ?></td>
          <td><?= e((string)$s['closed_at']) ?></td>
          <td class="num"><?= (int)$s['inv_count'] ?></td>
          <td class="num"><?= number_format((float)$s['total'], 2) ?></td>
          <td>
            <a class="btn btn-secondary" href="pos_dryrun.php?shift=<?= (int)$s['id'] ?>">معاينة</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <?php if ($shifts): ?>
  <div class="fcard">
    <h2>تنفيذ الترحيل</h2>
    <form method="post" onsubmit="return confirm('متأكد؟ ده هيكتب في نظام الشركة فعليًا.')">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <table class="ftable" style="margin-bottom:14px" data-xt-off>
        <tr>
          <th style="width:130px">الوردية</th>
          <td>
            <select class="fselect" name="shift" required>
              <option value="">— اختر —</option>
              <?php foreach ($shifts as $s): ?>
                <option value="<?= (int)$s['id'] ?>">
                  #<?= (int)$s['id'] ?> · فرع <?= (int)$s['branch_id'] ?> ·
                  <?= (int)$s['inv_count'] ?> فاتورة ·
                  <?= number_format((float)$s['total'], 2) ?> ج
                </option>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>
        <tr>
          <th>التأكيد</th>
          <td>
            <input type="text" class="finput" name="confirm" required autocomplete="off"
                   placeholder="اكتب: ترحيل" aria-label="اكتب: ترحيل">
            <span style="color:var(--ink-500);font-size:12px;margin-right:8px">
              اكتب كلمة «ترحيل» بالظبط
            </span>
          </td>
        </tr>
      </table>
      <button type="submit" class="btn btn-danger">تنفيذ الترحيل</button>
    </form>
  </div>
  <?php endif; ?>

</div>
</body>
</html>
