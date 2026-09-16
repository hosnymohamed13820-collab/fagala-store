<?php
/**
 * ashraqa_invoices.php — فواتير فرع إشراقة
 * نظام الفجالة ستور المالي
 *
 * مخصصة لمراجعة وتسعير الفواتير الموزعة لفرع إشراقة (branch_id = 5).
 * - يرى فرع إشراقة فقط بدون باقي الفروع.
 * - التسعير منفصل تماماً في جدول ashraqa_item_prices.
 * - لا تعديل على الكميات أو التوزيع أو الحالات.
 */
require_once __DIR__ . '/auth.php';
require_permission('invoices:ashraqa');
require_once __DIR__ . '/receiving_lib.php';
require_once __DIR__ . '/ashraqa_prices_lib.php';

$page_title    = 'فواتير إشراقة';
$active_module = 'invoices';
$user          = current_user();
$uid           = $user['id'] ?? null;
$pdo           = db();

// فرع إشراقة الثابت
const ASHRAQA_BRANCH_ID = 5;

// نوع المستند في نظام الشركة: 2 = فاتورة شراء · 6 = مردود شراء.
// 🔴 عاشور بيراجع المشتريات بس — المردودات مابتظهرش ليه خالص
//    (قرار محمد ١٣ سبتمبر ٢٠٢٦). الفلتر مطبّق في تلات أماكن:
//    القايمة · حارس فتح الفاتورة · بحث الصنف — عشان مايبقاش فيه
//    باب جانبي يوصّل لمردود.
const ASH_DOC_RETURN = 6;

// ١) أرقام الفواتير اللي فيها توزيع لإشراقة — استعلام واحد مجمّع
//
// 🔴 الشرط `SUM(amount) > 0` مش `amount > 0`:
//    `distribute_item()` بتضيف سطور تراكمية، فالصنف ممكن يبقى ليه أكتر
//    من سطر. الفلترة على السطر الواحد كانت بتطلّع فواتير مجموعها صفر.
//
// 🔴 والـJOIN على `hq_invoice_lines` مش زيادة:
//    الفاتورة ممكن يكون ليها سطر توزيع لإشراقة بكود صنف **مابقاش موجود
//    في الفاتورة** (اتعدّلت في نظام الشركة بعد التوزيع). ساعتها القايمة
//    كانت بتوريها وهي بتفتح **فاضية خالص** — وده اللي محمد شافه.
//    شاشة الفاتورة بتفلتر الأصناف على اللي موجود فعلاً، فالقايمة لازم
//    تسأل **نفس السؤال** وإلا الاتنين بيختلفوا.
//
// ⚠️ والمرآة ممكن تكون فاضية (الجسر لسه ماجابش السطور). ساعتها
//    `hi.sth_id IS NULL` والشرط بيعدّي الفاتورة بدل ما يخفيها —
//    إخفاء فاتورة ليها توزيع حقيقي أوحش بكتير من عرض واحدة زيادة.
try {
    $rows = $pdo->prepare(
        'SELECT d.invoice_no, d.company_branch, d.doc_type, d.id AS dist_id, l.item_code, l.qty
           FROM invoice_distribution d
           JOIN (
                  SELECT distribution_id, notes AS item_code, SUM(amount) AS qty
                    FROM invoice_distribution_lines
                   WHERE branch_id = ?
                   GROUP BY distribution_id, notes
                  HAVING SUM(amount) > 0
                ) l ON l.distribution_id = d.id
          WHERE (d.company_branch = 1 OR d.company_branch IS NULL)
            AND (d.doc_type = 2 OR d.doc_type IS NULL)'
    );
    $rows->execute([ASHRAQA_BRANCH_ID]);
} catch (Throwable $ex) {
    // لو الأعمدة لسه مش موجودة في الجدول (قبل تشغيل ملف الترقية)
    $rows = $pdo->prepare(
        'SELECT d.invoice_no, NULL AS company_branch, NULL AS doc_type, d.id AS dist_id, l.item_code, l.qty
           FROM invoice_distribution d
           JOIN (
                  SELECT distribution_id, notes AS item_code, SUM(amount) AS qty
                    FROM invoice_distribution_lines
                   WHERE branch_id = ?
                   GROUP BY distribution_id, notes
                  HAVING SUM(amount) > 0
                ) l ON l.distribution_id = d.id'
    );
    $rows->execute([ASHRAQA_BRANCH_ID]);
}

$ashraqaDocNos = [];       // [key => dist_id]
$ashraqaCodesByDoc = [];   // [key => [code => true]]
$ashraqaQtysByDist = [];   // [dist_id => [code => qty]]
$ashraqaCodesByDist = [];  // [dist_id => [code => true]]
foreach ($rows as $r) {
    $did  = (int) $r['dist_id'];
    $code = trim((string) $r['item_code']);
    $qty  = (float) ($r['qty'] ?? 0);

    $ashraqaQtysByDist[$did][$code] = $qty;
    $ashraqaCodesByDist[$did][$code] = true;

    $no = trim((string) $r['invoice_no']);
    $cb = ($r['company_branch'] !== null && $r['company_branch'] !== '') ? (string)$r['company_branch'] : '*';
    $dt = ($r['doc_type'] !== null && $r['doc_type'] !== '') ? (string)$r['doc_type'] : '*';

    $key = $no . '|' . $cb . '|' . $dt;
    $ashraqaDocNos[$key] = $did;
    $ashraqaCodesByDoc[$key][$code] = true;

    // توافق مع السجلات القديمة التي تحوي NULL
    if ($cb === '*' && $dt === '*') {
        $ashraqaDocNos[$no . '|*|*'] = $did;
        $ashraqaCodesByDoc[$no . '|*|*'][$code] = true;
        $ashraqaDocNos[$no] = $did;
        $ashraqaCodesByDoc[$no][$code] = true;
    }
}

/**
 * دالة استخراج معرف سجل التوزيع لفاتورة معينة
 * بتجرّب المفتاح الكامل doc_no|company_branch|doc_type ثم doc_no|*|* ثم doc_no
 */
function ash_dist_for(array $inv, array $ashraqaDocNos): ?int
{
    $no = trim((string)($inv['inv_no'] ?? ($inv['doc_no'] ?? '')));
    if ($no === '') return null;
    $cb = $inv['company_branch_id'] ?? ($inv['company_branch'] ?? null);
    $dt = $inv['doc_type'] ?? null;
    if ($cb !== null && $cb !== '' && $dt !== null && $dt !== '') {
        $k = $no . '|' . (int)$cb . '|' . (int)$dt;
        if (isset($ashraqaDocNos[$k])) return $ashraqaDocNos[$k];
    }
    if (isset($ashraqaDocNos[$no . '|*|*'])) return $ashraqaDocNos[$no . '|*|*'];
    if (isset($ashraqaDocNos[$no])) return $ashraqaDocNos[$no];
    return null;
}

/**
 * دالة استخراج أكواد التوزيع لفاتورة معينة
 */
function ash_codes_for(array $inv, array $ashraqaCodesByDoc): array
{
    $no = trim((string)($inv['inv_no'] ?? ($inv['doc_no'] ?? '')));
    if ($no === '') return [];
    $cb = $inv['company_branch_id'] ?? ($inv['company_branch'] ?? null);
    $dt = $inv['doc_type'] ?? null;
    if ($cb !== null && $cb !== '' && $dt !== null && $dt !== '') {
        $k = $no . '|' . (int)$cb . '|' . (int)$dt;
        if (isset($ashraqaCodesByDoc[$k])) return $ashraqaCodesByDoc[$k];
    }
    if (isset($ashraqaCodesByDoc[$no . '|*|*'])) return $ashraqaCodesByDoc[$no . '|*|*'];
    if (isset($ashraqaCodesByDoc[$no])) return $ashraqaCodesByDoc[$no];
    return [];
}

// ===== حالة مراجعة إشراقة — استعلام واحد لكل الفواتير =====
// 🔴 جدول منفصل تماماً عن invoice_distribution.status.
//    العمود المشترك ده (new/split/done) بتاع المشتريات ومعناه «الفاتورة
//    اتوزّعت ولا لأ». «تم الإدخال» هنا معناها عاشور خلّص إدخالها عندهم —
//    معنى تاني خالص، والخلط بينهم بيخلّي فاتورة موزّعة تبان جديدة للمشتريات.
// 🔴 غياب الصف = 'new'. مفيش backfill ولا لمس لأي فاتورة قديمة.
$ashraqaStatus = [];   // [dist_id => 'new'|'entered']
try {
    foreach ($pdo->query('SELECT distribution_id, status FROM ashraqa_invoice_status') as $r) {
        $ashraqaStatus[(int) $r['distribution_id']] = (string) $r['status'];
    }
} catch (Throwable $ex) {
    // الجدول لسه ما اتعملش — كل الفواتير تبان «جديدة» والصفحة تفضل شغّالة
    error_log('ashraqa_invoice_status: ' . $ex->getMessage());
}

// ===== بحث الصنف (AJAX) — «الصنف ده نزل في أنهي فاتورة؟» =====
// نفس نمط invoices.php:30، بس **مفلتر على إشراقة**.
$ilkAjax = $_GET['ilk'] ?? '';
if ($ilkAjax !== '') {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/item_lookup_lib.php';
    try {
        if (!ilk_ready()) throw new RuntimeException('المزامنة لسه ما وصلتش — استنى شوية وجرّب تاني.');

        if ($ilkAjax === 'search') {
            echo json_encode([
                'ok'   => true,
                'rows' => ilk_search((string) ($_GET['term'] ?? ''), 15),
            ], JSON_UNESCAPED_UNICODE);

        } elseif ($ilkAjax === 'history') {
            $res = ilk_item_history((string) ($_GET['code'] ?? ''), (int) ($_GET['item_id'] ?? 0));

            // 🔴🔴 الفلترة دي **في السيرفر قبل json_encode** — مش إخفاء في
            //     الجافاسكربت. ilk_item_history بترجّع كل الفواتير ومعاها
            //     توزيع كل الفروع واسم المورد، وعاشور مالوش شوف ده.
            $rows = [];
            foreach ($res['rows'] ?? [] as $row) {
                $docNo = (string) ($row['doc_no'] ?? $row['inv_no'] ?? '');
                if (!isset($ashraqaDocNos[$docNo])) continue;   // فاتورة مالهاش إشراقة
                if (!empty($row['is_return'])) continue;        // مردود — مايظهرش لعاشور

                // ⚠️ `dist` قايمة [['branch_id','name','qty'], …] مش خريطة
                //    بمفتاح رقم الفرع — لازم ندوّر فيها، مش $dist[5].
                $ashQty = 0.0;
                foreach (($row['dist'] ?? []) as $b) {
                    if ((int) ($b['branch_id'] ?? 0) === ASHRAQA_BRANCH_ID) {
                        $ashQty = (float) ($b['qty'] ?? 0);
                        break;
                    }
                }
                if ($ashQty <= 0) continue;

                // الحقول دي بس هي اللي بتوصل المتصفح — مفيش مورد ولا فرع تاني
                // ولا in_store ولا dist_total (دول بيوروا توزيع الباقي بالطرح).
                $rows[] = [
                    'doc_no'    => $docNo,
                    'sth_id'    => (int) ($row['sth_id'] ?? 0),
                    'suffix'    => (string) ($row['suffix'] ?? ''),
                    'date'      => (string) ($row['date'] ?? ''),
                    'qty'       => (float) ($row['qty'] ?? 0),
                    'ash_qty'   => $ashQty,
                ];
            }

            $item = $res['item'] ?? null;
            echo json_encode([
                'ok'    => true,
                'item'  => $item ? [
                    'code' => (string) ($item['code'] ?? ''),
                    'name' => (string) ($item['name'] ?? ''),
                ] : null,
                'rows'  => $rows,
            ], JSON_UNESCAPED_UNICODE);

        } else {
            throw new RuntimeException('عملية غير معروفة.');
        }
    } catch (Throwable $ex) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ===== تغيير حالة المراجعة (AJAX) — بيتحفظ على الضغط، مفيش زرار حفظ =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_status') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!csrf_verify($_POST['csrf'] ?? '')) {
            throw new RuntimeException('انتهت صلاحية الجلسة، اعمل تحديث للصفحة.');
        }
        $d  = (int) ($_POST['distribution_id'] ?? 0);
        $st = (string) ($_POST['status'] ?? '');

        if (!in_array($st, ['new', 'entered'], true)) {
            throw new RuntimeException('حالة غير معروفة.');
        }
        // نفس حارس save_price — الفاتورة لازم تكون فيها توزيع لإشراقة
        if ($d <= 0 || !in_array($d, $ashraqaDocNos, true)) {
            http_response_code(403);
            throw new RuntimeException('مالكش صلاحية تغيّر حالة الفاتورة دي.');
        }

        $up = $pdo->prepare(
            'INSERT INTO ashraqa_invoice_status (distribution_id, status, updated_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status), updated_by = VALUES(updated_by)'
        );
        $up->execute([$d, $st, $uid]);

        echo json_encode(['ok' => true, 'status' => $st], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        if (http_response_code() === 200) {
            http_response_code(400);
        }
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// معالج حفظ السعر لفرع إشراقة (AJAX على blur)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_price') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!csrf_verify($_POST['csrf'] ?? '')) {
            throw new RuntimeException('انتهت صلاحية الجلسة، اعمل تحديث للصفحة.');
        }
        $d = (int) ($_POST['distribution_id'] ?? 0);
        $c = trim((string) ($_POST['item_code'] ?? ''));
        $p = (float) ($_POST['price'] ?? 0);

        if ($d <= 0 || $c === '') {
            throw new RuntimeException('بيانات ناقصة.');
        }
        if ($p < 0) {
            throw new RuntimeException('السعر لازم يكون موجب.');
        }

        // حارس أمان: التحقق من أن الفاتورة فيها توزيع لإشراقة
        if (!in_array($d, $ashraqaDocNos, true)) {
            http_response_code(403);
            throw new RuntimeException('مالكش صلاحية تسعّر الفاتورة دي.');
        }

        ashraqa_save_price($d, $c, $p, $uid);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        if (http_response_code() === 200) {
            http_response_code(400);
        }
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// دقة تقريب نسبة التسعير لأقرب نصف جنيه
const ASH_MRK_ROUND_STEP = 0.5;

function ash_mrk_round(float $v): float
{
    return round($v / ASH_MRK_ROUND_STEP) * ASH_MRK_ROUND_STEP;
}

// معالج حفظ الأسعار بالجملة لفرع إشراقة (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_prices_bulk') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!csrf_verify($_POST['csrf'] ?? '')) {
            throw new RuntimeException('انتهت صلاحية الجلسة، اعمل تحديث للصفحة.');
        }
        $d = (int) ($_POST['distribution_id'] ?? 0);
        $rawPrices = (string) ($_POST['prices'] ?? '');
        $map = json_decode($rawPrices, true);

        if ($d <= 0 || !is_array($map) || empty($map)) {
            throw new RuntimeException('بيانات ناقصة.');
        }
        if (count($map) > 5000) {
            throw new RuntimeException('عدد الأصناف كبير جداً.');
        }

        // حارس أمان: التحقق من أن الفاتورة فيها توزيع لإشراقة
        if (!in_array($d, $ashraqaDocNos, true)) {
            http_response_code(403);
            throw new RuntimeException('مالكش صلاحية تسعّر الفاتورة دي.');
        }

        $n = 0;
        $pdo->beginTransaction();
        try {
            foreach ($map as $code => $price) {
                $code = trim((string) $code);
                $price = (float) $price;
                if ($code === '' || $price < 0) continue;
                ashraqa_save_price($d, $code, ash_mrk_round($price), $uid);
                $n++;
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $ex;
        }

        echo json_encode(['ok' => true, 'count' => $n], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        if (http_response_code() === 200) {
            http_response_code(400);
        }
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

function ash_mrk_cost(array $item): float
{
    $c = (float) ($item['unit_price'] ?? 0);
    if ($c <= 0) $c = (float) ($item['cost_price'] ?? 0);
    return $c > 0 ? $c : 0.0;
}

function ash_mrk_pct(float $cost, float $sale): ?float
{
    if ($cost <= 0 || $sale <= 0) return null;
    return ($sale / $cost - 1) * 100;
}

function ash_mrk_fmt(?float $p): string
{
    if ($p === null) return '';
    $s = number_format($p, 1, '.', '');
    $s = rtrim(rtrim($s, '0'), '.');
    return ($s === '' || $s === '-') ? '0' : $s;
}

function ash_mrk_cell(string $code, float $cost, ?float $sale): string
{
    $has = $cost > 0;
    $pct = ($has && $sale !== null && $sale > 0) ? ash_mrk_pct($cost, $sale) : null;
    $ph  = $pct === null ? '—' : ash_mrk_fmt($pct) . '%';
    $cs  = $has ? rtrim(rtrim(number_format($cost, 5, '.', ''), '0'), '.') : '0';

    return '<td class="c-mrk">'
        . '<input type="number" step="0.5" class="mrk-input' . ($has ? '' : ' no-cost') . '"'
        . ' aria-label="نسبة التسعير" data-code="' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '" data-cost="' . htmlspecialchars($cs, ENT_QUOTES, 'UTF-8') . '"'
        . ' placeholder="' . htmlspecialchars($ph, ENT_QUOTES, 'UTF-8') . '"' . ($has ? '' : ' disabled')
        . ' title="' . ($has ? 'اكتب نسبة واضغط Enter — سعر البيع هيتحسب لوحده' : 'مفيش تكلفة للصنف ده') . '"'
        . ' onblur="applyAshMrk(this)" onkeydown="ashMrkKey(event,this)">'
        . '</td>';
}

// فحص الفاتورة المفتوحة
$openId  = $_GET['invoice'] ?? null;
$openSuf = $_GET['suffix'] ?? null;
$openInvoice = null;
$distId = null;

if ($openId !== null && $openId !== '') {
    $header = sqlsrv_fetch_invoice_header($openId, $openSuf);
    $docNo = $header ? (string)($header['inv_no'] ?? '') : '';
    $isRet = $header && (int)($header['doc_type'] ?? 0) === ASH_DOC_RETURN;
    $cb = $header ? ($header['company_branch_id'] ?? ($header['company_branch'] ?? null)) : null;
    $distId = $header ? ash_dist_for($header, $ashraqaDocNos) : null;
    // حارس إجباري: الفاتورة فيها توزيع لإشراقة؟ ومش مردود؟ ومن الإدارة المركزية (branch 1)؟
    // 🔴 شرط المردود هنا مش بس في القايمة — من غيره حد يكتب رقم مردود
    //    في اللينك ويفتحه عادي.
    if (!$header || $isRet || ($cb !== null && (int)$cb !== 1) || $distId === null) {
        http_response_code(403);
        require_once __DIR__ . '/includes/header.php';
        ?>
        <div class="card" style="margin:20px 0;text-align:center;padding:40px;">
            <h2 style="color:var(--neg);margin-bottom:12px;">403 — الفاتورة دي مش ليك</h2>
            <p style="color:var(--ink-500);font-size:14px;margin-bottom:20px;">
                الفاتورة دي مافيهاش أي كمية اتوزّعت لإشراقة، أو مش من فواتير الفرع.
            </p>
            <a href="ashraqa_invoices.php" class="btn btn-primary">
                <i class="ti ti-arrow-right"></i> رجوع لفواتير إشراقة
            </a>
        </div>
        <?php
        require_once __DIR__ . '/includes/footer.php';
        exit;
    }
    $openInvoice = $header;
}

// في حالة الشاشة الأولى (قائمة الفواتير)
$invoices = [];
if ($openInvoice === null) {
    $all = sqlsrv_fetch_invoices();
    $cand = [];
    foreach ($all as $inv) {
        // فواتير الإدارة المركزية فقط (company_branch = 1 أو غير محدد)
        $cb = $inv['company_branch_id'] ?? ($inv['company_branch'] ?? null);
        if ($cb !== null && (int)$cb !== 1) continue;

        $dId = ash_dist_for($inv, $ashraqaDocNos);
        if ($dId === null) continue;
        // المردودات مابتظهرش لعاشور — مشتريات بس
        if ((int) ($inv['doc_type'] ?? 0) === ASH_DOC_RETURN) continue;
        $inv['dist_id'] = $dId;
        $cand[] = $inv;
    }

    // ===== استبعاد الفواتير اللي مش هتفتح على أي صنف =====
    // 🔴 الربط لازم يبقى على (sth_id + suffix) مش على doc_no:
    //    `doc_no` **مش فريد** بين الشهور، والربط بيه كان بيوصّل لفاتورة
    //    شهر تاني فسطورها مافيهاش أكوادنا — فكانت فواتير سليمة تختفي.
    //    ودي بالظبط نفس الأعمدة اللي `sqlsrv_fetch_invoice_items()`
    //    بتقرا منها، فالقايمة والشاشة بيشوفوا نفس الحاجة.
    $sthIds = array_values(array_unique(array_map(
        fn($i) => (int) $i['inv_id'], $cand
    )));
    $lineCodes = [];   // ["sth|suffix" => [code => true]]
    $lineCosts = [];   // ["sth|suffix" => [code => cost]]
    if ($sthIds) {
        $ph = implode(',', array_fill(0, count($sthIds), '?'));
        $q  = $pdo->prepare(
            "SELECT sth_id, suffix, item_code, item_id, unit_price, cost_price
               FROM hq_invoice_lines WHERE sth_id IN ($ph)"
        );
        $q->execute($sthIds);
        foreach ($q as $r) {
            $k = (int) $r['sth_id'] . '|' . (string) $r['suffix'];
            $c = trim((string) ($r['item_code'] ?? ''));
            // ⚠️ merge_invoice_items() بتحط '#'.item_id للصنف اللي مالوش
            //    باركود — لازم نقبل الشكلين وإلا الصنف ده مايتلاقاش أبداً.
            if ($c === '') $c = '#' . (int) ($r['item_id'] ?? 0);
            $lineCodes[$k][$c] = true;

            $cost = (float)($r['unit_price'] ?? 0);
            if ($cost <= 0) $cost = (float)($r['cost_price'] ?? 0);
            if (!isset($lineCosts[$k][$c]) || $cost > $lineCosts[$k][$c]) {
                $lineCosts[$k][$c] = $cost;
            }
            if (!empty($r['item_id'])) {
                $hKey = '#' . (int)$r['item_id'];
                if (!isset($lineCosts[$k][$hKey]) || $cost > $lineCosts[$k][$hKey]) {
                    $lineCosts[$k][$hKey] = $cost;
                }
            }
        }
    }

    foreach ($cand as $inv) {
        $k   = (int) $inv['inv_id'] . '|' . (string) ($inv['suffix'] ?? '');
        $dId = (int) $inv['dist_id'];
        // ⚠️ المرآة مالهاش سطور للفاتورة دي (الجسر لسه ماجابهاش) →
        //    نوريها. إخفاء فاتورة ليها توزيع حقيقي أوحش من عرض واحدة زيادة.
        if (isset($lineCodes[$k])) {
            $codes = ash_codes_for($inv, $ashraqaCodesByDoc);
            if (!array_intersect_key($codes, $lineCodes[$k])) {
                continue;
            }
        }

        // حساب إجمالي التكلفة وعدد أصناف إشراقة
        $invTotal = 0.0;
        foreach (($ashraqaQtysByDist[$dId] ?? []) as $c => $q) {
            $cost = $lineCosts[$k][$c] ?? 0.0;
            $invTotal += ($q * $cost);
        }
        $inv['ash_total'] = $invTotal;
        $inv['ash_items_count'] = count($ashraqaCodesByDist[$dId] ?? []);

        $invoices[] = $inv;
    }
} else {
    // جلب أصناف الفاتورة ودمج المتكرر
    $rawItems = sqlsrv_fetch_invoice_items($openId, $openSuf);
    $items = merge_invoice_items($rawItems);

    // كميات إشراقة باستعلام واحد مجمّع
    $stQty = $pdo->prepare(
        'SELECT notes AS item_code, SUM(amount) AS qty
           FROM invoice_distribution_lines
          WHERE distribution_id = ? AND branch_id = ?
          GROUP BY notes'
    );
    $stQty->execute([$distId, ASHRAQA_BRANCH_ID]);
    $ashraqaQtys = [];
    foreach ($stQty as $qr) {
        $ashraqaQtys[(string)$qr['item_code']] = (float)$qr['qty'];
    }

    // جلب item_id لكل أكواد التوزيع باستعلام واحد مجمّع (للاحتياطي)
    $distCodes = array_keys($ashraqaQtys);
    $distCodeToItemId = [];
    $distCodesByItemId = [];
    $nonHashDistCodes = [];
    foreach ($distCodes as $dc) {
        if (str_starts_with($dc, '#')) {
            $iid = (int) substr($dc, 1);
            if ($iid > 0) {
                $distCodeToItemId[$dc] = $iid;
                $distCodesByItemId[$iid][] = $dc;
            }
        } else {
            $nonHashDistCodes[] = $dc;
        }
    }
    if ($nonHashDistCodes) {
        $phC = implode(',', array_fill(0, count($nonHashDistCodes), '?'));
        try {
            $stC = $pdo->prepare("SELECT code, item_id FROM hq_codes WHERE code IN ($phC)");
            $stC->execute($nonHashDistCodes);
            foreach ($stC as $cr) {
                $iid = (int) $cr['item_id'];
                $cVal = (string) $cr['code'];
                $distCodeToItemId[$cVal] = $iid;
                $distCodesByItemId[$iid][] = $cVal;
            }
        } catch (Throwable $ex) { /* تجاهل */ }
    }

    // فلترة الأصناف: بالكود أولاً ثم باحتياطي item_id
    $claimedDistCodes = [];
    $ashraqaItems = [];
    $ashraqaCodes = [];
    foreach ($items as $it) {
        $c = (string)($it['item_code'] ?? '');
        $q = 0.0;
        if ($c !== '' && isset($ashraqaQtys[$c]) && !isset($claimedDistCodes[$c])) {
            $q = (float) $ashraqaQtys[$c];
            $claimedDistCodes[$c] = true;
        } elseif (!empty($it['item_id'])) {
            $iid = (int) $it['item_id'];
            $candCodes = $distCodesByItemId[$iid] ?? [];
            foreach ($candCodes as $dc) {
                if (!isset($claimedDistCodes[$dc]) && isset($ashraqaQtys[$dc]) && (str_starts_with($c, '#') || str_starts_with($dc, '#'))) {
                    $q += (float) $ashraqaQtys[$dc];
                    $claimedDistCodes[$dc] = true;
                }
            }
        }
        if ($q <= 0) continue;
        $ashraqaQtys[$c] = $q;
        $ashraqaItems[] = $it;
        $ashraqaCodes[] = $c;
    }

    // سطور التوزيع التي فضلت من غير صنف في الفاتورة (أصناف يتيمة)
    $orphanedDistItems = [];
    $unclaimedCodes = [];
    foreach ($ashraqaQtys as $dc => $dQty) {
        if (!isset($claimedDistCodes[$dc]) && $dQty > 0) {
            $unclaimedCodes[] = $dc;
        }
    }
    if ($unclaimedCodes) {
        $orpNames = [];
        $regularUnclaimed = [];
        $hashIds = [];
        foreach ($unclaimedCodes as $uc) {
            if (str_starts_with($uc, '#')) {
                $iid = (int) substr($uc, 1);
                if ($iid > 0) $hashIds[$iid] = $uc;
            } else {
                $regularUnclaimed[] = $uc;
            }
        }
        // أسامي أصناف «#item_id» في استعلام واحد (hq_items مفتاحها item_id مش id)
        if ($hashIds) {
            $phH = implode(',', array_fill(0, count($hashIds), '?'));
            try {
                $stI = $pdo->prepare(
                    "SELECT item_id, MAX(name) AS name FROM hq_items
                     WHERE item_id IN ($phH) GROUP BY item_id"
                );
                $stI->execute(array_keys($hashIds));
                foreach ($stI as $ir) {
                    $orpNames[$hashIds[(int) $ir['item_id']]] = (string) $ir['name'];
                }
            } catch (Throwable $ex) {}
        }
        if ($regularUnclaimed) {
            $phU = implode(',', array_fill(0, count($regularUnclaimed), '?'));
            try {
                $stU = $pdo->prepare(
                    "SELECT c.code, COALESCE(i.name, c.code) AS name
                     FROM hq_codes c
                     LEFT JOIN hq_items i ON i.item_id = c.item_id
                     WHERE c.code IN ($phU)"
                );
                $stU->execute($regularUnclaimed);
                foreach ($stU as $ur) {
                    $orpNames[(string)$ur['code']] = (string)$ur['name'];
                }
            } catch (Throwable $ex) {}
        }
        foreach ($unclaimedCodes as $uc) {
            $orphanedDistItems[] = [
                'code' => $uc,
                'name' => $orpNames[$uc] ?? '—',
                'qty'  => (float) $ashraqaQtys[$uc],
            ];
        }
    }

    // ===== رصيد إشراقة — بكل باركودات الصنف مش بكود الفاتورة بس =====
    // 🔴 `ashraqa_balances` مفتاحه **الباركود الحقيقي** الجاي من نظامهم
    //    (Redux). وكود الصنف في الفاتورة ساعات بيبقى كود داخلي قصير
    //    (37 · 256) مش باركود — فالبحث بيه بيرجّع فاضي، والعمود كله
    //    كان بيطلع شُرَط.
    //    `hq_codes` بتربط كل باركودات الصنف بنفس `item_id`، فبنجيبهم
    //    كلهم وندوّر بيهم.
    //
    // 🔴 وبناخد الباركودات اللي `unit_factor = 1` بس:
    //    رصيدهم متخزّن بالوحدة الأساسية، والباركود اللي معامله 12
    //    (علبة مثلاً) رصيده برقم تاني خالص — عرضه كأنه رصيد الوحدة
    //    بيدّي رقم غلط، وده عمود بيتبني عليه قرار شراء.
    $itemIds = [];
    foreach ($ashraqaItems as $it) {
        $iid = (int) ($it['item_id'] ?? 0);
        if ($iid > 0) $itemIds[$iid] = true;
    }

    $codesByItemId = [];   // [item_id => [barcode, …]]
    if ($itemIds) {
        $ids = array_keys($itemIds);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $qc  = $pdo->prepare(
            "SELECT code, item_id FROM hq_codes
              WHERE item_id IN ($ph) AND unit_factor = 1"
        );
        $qc->execute($ids);
        foreach ($qc as $r) {
            $codesByItemId[(int) $r['item_id']][] = (string) $r['code'];
        }
    }

    // كل الباركودات المحتملة مرة واحدة — استعلام واحد
    $lookup = $ashraqaCodes;
    foreach ($codesByItemId as $list) {
        foreach ($list as $bc) $lookup[] = $bc;
    }
    $rawBalances = ashraqa_fetch_balances($lookup);

    // لكل صنف: كود الفاتورة الأول، وبعدين باركوداته
    $balances = [];
    foreach ($ashraqaItems as $it) {
        $c = (string) ($it['item_code'] ?? '');
        if (isset($rawBalances[$c])) { $balances[$c] = $rawBalances[$c]; continue; }
        foreach ($codesByItemId[(int) ($it['item_id'] ?? 0)] ?? [] as $bc) {
            if (isset($rawBalances[$bc])) { $balances[$c] = $rawBalances[$bc]; break; }
        }
    }

    $salePrices = ashraqa_get_prices($distId);
    $lastSync   = ashraqa_last_sync();

    // إجمالي تكلفة أصناف إشراقة في هذه الفاتورة
    $totalAshraqaCost = 0.0;
    foreach ($ashraqaItems as $it) {
        $c = (string)($it['item_code'] ?? '');
        $q = (float)($ashraqaQtys[$c] ?? 0);
        $cost = ash_mrk_cost($it);
        $totalAshraqaCost += ($q * $cost);
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
    .btn {
        background: var(--accent);
        color: #fff;
        border: none;
        padding: 8px 16px;
        border-radius: 9px;
        font-size: 13.5px;
        font-family: inherit;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 7px;
        text-decoration: none;
        transition: background 0.15s ease;
    }
    .btn:hover {
        background: var(--accent-hover);
        color: #fff;
    }
    .btn-primary {
        background: var(--accent);
        color: #fff;
    }
    .btn-primary:hover {
        background: var(--accent-hover);
        color: #fff;
    }
    .btn-outline {
        background: var(--surface);
        color: var(--ink-700);
        border: 1px solid var(--line);
    }
    .btn-outline:hover {
        background: var(--bg);
        color: var(--ink-700);
    }
    .btn-sm {
        padding: 5px 11px;
        font-size: 12.5px;
    }
    .btn:disabled {
        opacity: .5;
        cursor: not-allowed;
    }
    .btn-outline:disabled:hover {
        background: var(--surface);
        color: var(--ink-700);
        border-color: var(--line);
    }
    .st-badge {
        display: inline-block;
        padding: 3px 9px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
        background: var(--accent-soft);
        color: var(--accent-text);
    }
    /* «تم الإدخال» — أخضر عشان يبان من «جديدة» بنظرة */
    .st-badge.st-entered { background: var(--pos-soft); color: var(--pos); }

    /* الصف الباهت للفواتير المدخلة في القائمة — لا ينطبق عند فلترة «تم الإدخال» */
    .ash-table:not([data-filter="entered"]) tr[data-st="entered"] {
        opacity: .6;
    }
    body:has(.sf-btn.active[data-f="entered"]) .ash-table tr[data-st="entered"] {
        opacity: 1;
    }
    .inv-no {
        font-weight: 700;
        color: var(--accent-text);
    }
    .ash-list-container {
        max-width: 900px;
        margin: 0 auto;
    }
    .ash-pagination {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
        padding: 12px 10px;
        margin-top: 14px;
        flex-wrap: wrap;
    }
    .ash-page-info {
        font-size: 13px;
        font-weight: 600;
        color: var(--ink-700);
    }

    /* ===== فلتر الحالات — منقول بالحرف من invoices.php:1739 ===== */
    .status-filters { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
    .sf-btn { display:inline-flex; align-items:center; gap:7px; padding:7px 14px;
        border:1px solid var(--line); border-radius:20px; background:var(--surface);
        color:var(--ink-600); font-family:inherit; font-size:13px; font-weight:500;
        cursor:pointer; transition:all .15s; }
    .sf-btn:hover { border-color:var(--ink-300); color:var(--ink-800); }
    .sf-btn span { display:inline-block; min-width:20px; padding:1px 6px; border-radius:20px;
        background:var(--line-soft); color:var(--ink-500); font-size:11.5px; font-weight:700; }
    .sf-btn.active { background:var(--fill-strong); border-color:var(--fill-strong); color:var(--fill-strong-fg); }
    .sf-btn.active span { background:var(--fill-strong-veil); color:var(--fill-strong-fg); }
    .no-match { padding:26px; text-align:center; color:var(--ink-400); }
    /* الفلترة بتستعمل tr.hidden — القاعدة دي تأمين صريح لو حد ضاف
       display على الصفوف بعدين، عشان الصف المخفي مايفضلش ظاهر بصمت */
    .ash-table tr[hidden] { display:none; }

    /* ===== بار البحث — نفس مقاسات invoices.php:1554 ===== */
    .ilk-bar { background:var(--surface); border-radius:12px; padding:11px 15px; margin-bottom:16px;
        display:flex; align-items:center; gap:11px; box-shadow:var(--shadow-sm); }
    .ilk-bar > i { color:var(--ink-500); font-size:18px; }
    .ilk-wrap { position:relative; flex:1; }
    .ilk-wrap input { width:100%; box-sizing:border-box; padding:9px 11px;
        border:1.5px solid var(--line); border-radius:9px; font-family:inherit; font-size:13.5px;
        background:var(--surface); color:var(--ink-900); }
    .ilk-wrap input:focus { outline:none; border-color:var(--accent-text); }
    .ilk-sugg { position:absolute; top:calc(100% + 5px); right:0; left:0; z-index:60;
        background:var(--surface); border:1px solid var(--line); border-radius:10px;
        box-shadow:var(--shadow-lg); max-height:300px; overflow-y:auto; display:none; }
    .ilk-sugg div.ilk-s { padding:9px 12px; cursor:pointer; border-bottom:1px solid var(--line-soft); font-size:13px; }
    .ilk-sugg div.ilk-s:last-child { border-bottom:none; }
    .ilk-sugg div.ilk-s:hover, .ilk-sugg div.ilk-s.on { background:var(--accent-soft); }
    .ilk-sugg .ilk-c { color:var(--ink-500); font-size:12px; }

    /* فلتر رقم الفاتورة */
    .inv-filter { width:100%; box-sizing:border-box; padding:9px 12px; margin-bottom:14px;
        border:1.5px solid var(--line); border-radius:9px; font-family:inherit; font-size:13.5px;
        background:var(--surface); color:var(--ink-900); }
    .inv-filter:focus { outline:none; border-color:var(--accent-text); }

    /* ===== سلايدر الحالة (جوّه الفاتورة) ===== */
    .st-slider { display:inline-flex; border:1px solid var(--line); border-radius:20px;
        overflow:hidden; background:var(--surface); }
    .st-slider button { border:none; background:none; font-family:inherit; font-size:12.5px;
        font-weight:600; padding:5px 14px; cursor:pointer; color:var(--ink-500); transition:all .15s; }
    .st-slider button:hover:not(.on) { color:var(--ink-800); background:var(--line-soft); }
    .st-slider button.on { background:var(--fill-strong); color:var(--fill-strong-fg); }
    .st-slider button:disabled { opacity:.6; cursor:default; }
    .ash-table-wrap {
        overflow-x: auto;
        border: 1px solid var(--line);
        border-radius: 10px;
        background: var(--surface);
    }
    .ash-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13.5px;
        text-align: right;
    }
    .ash-table th, .ash-table td {
        padding: 10px 12px;
        border-bottom: 1px solid var(--line-soft);
        vertical-align: middle;
    }
    .ash-table th {
        background: var(--bg);
        color: var(--ink-700);
        font-weight: 600;
        white-space: nowrap;
    }
    .ash-table tr:last-child td {
        border-bottom: none;
    }
    .ash-table tbody tr:hover {
        background: var(--bg);
    }
    .c-code {
        font-family: monospace;
        font-size: 12.5px;
        color: var(--ink-600);
        direction: ltr;
        text-align: right;
    }
    .c-sale {
        white-space: nowrap;
    }
    .sale-input {
        width: 88px;
        padding: 6px 8px;
        border: 1px solid var(--line);
        border-radius: 7px;
        font-family: inherit;
        font-size: 13.5px;
        background: var(--surface);
        color: var(--ink-900);
        box-sizing: border-box;
    }
    .sale-input:focus {
        outline: none;
        border-color: var(--accent-text);
        box-shadow: 0 0 0 3px var(--accent-ring);
    }
    .sale-input.dirty {
        border-color: var(--warn);
        background: var(--warn-soft);
    }
    .sale-ind {
        font-size: 12px;
        width: 14px;
        display: inline-block;
        text-align: center;
        margin-right: 3px;
    }
    .sale-ind.wait { color: var(--ink-500); }
    .sale-ind.ok   { color: var(--pos); font-weight: bold; }
    .sale-ind.err  { color: var(--neg); font-weight: bold; }
    .c-mrk {
        width: 96px;
    }
    .mrk-input {
        width: 80px;
        padding: 6px 8px;
        border: 1px solid var(--line);
        border-radius: 7px;
        font-family: inherit;
        font-size: 13.5px;
        background: var(--surface);
        color: var(--ink-900);
        box-sizing: border-box;
    }
    .mrk-input:focus {
        outline: none;
        border-color: var(--accent-text);
        box-shadow: 0 0 0 3px var(--accent-ring);
    }
    .mrk-input.no-cost {
        opacity: 0.5;
        background: var(--bg);
    }
    .inv-row {
        cursor: pointer;
        transition: background 0.12s;
    }
    .inv-row:hover, .inv-row:focus {
        background: var(--accent-soft);
        outline: none;
    }
    .ash-mrk-bulk {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: 9px;
        padding: 10px 14px;
        margin-bottom: 12px;
    }
    .ash-mrk-lbl {
        font-size: 13.5px;
        font-weight: 600;
        color: var(--ink-800);
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    #ashMrkBulk {
        width: 90px;
        padding: 6px 8px;
        border: 1px solid var(--line);
        border-radius: 7px;
        font-family: inherit;
        font-size: 13.5px;
        background: var(--surface);
        color: var(--ink-900);
        box-sizing: border-box;
    }
    #ashMrkBulk:focus {
        outline: none;
        border-color: var(--accent-text);
        box-shadow: 0 0 0 3px var(--accent-ring);
    }
    .ash-mrk-hint {
        font-size: 12.5px;
        color: var(--ink-500);
    }
</style>

<?php if ($openInvoice === null): ?>
    <!-- ================= الشاشة الأولى: قائمة فواتير إشراقة ================= -->
    <div class="card" style="margin-bottom:16px;">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
            <div>
                <h1 style="font-size:18px;margin:0 0 4px 0;color:var(--ink-900);">فواتير إشراقة</h1>
                <div style="font-size:13px;color:var(--ink-500);">
                    الفواتير اللي اتوزّع منها كميات لإشراقة
                </div>
            </div>
        </div>
    </div>

    <!-- بار «الصنف ده نزل في أنهي فاتورة؟» -->
    <div class="ilk-bar">
        <i class="ti ti-search"></i>
        <div class="ilk-wrap">
            <input type="text" id="ilkInput" autocomplete="off" inputmode="search"
                   aria-label="بحث عن صنف"
                   placeholder="دوّر على صنف بالكود أو الاسم — يوريك نزل في أنهي فواتير إشراقة…">
            <div class="ilk-sugg" id="ilkSugg"></div>
        </div>
    </div>

    <?php if (empty($invoices)): ?>
        <div class="card" style="text-align:center;padding:36px;color:var(--ink-500);">
            <i class="ti ti-package" style="font-size:32px;display:block;margin-bottom:8px;"></i>
            مفيش فواتير اتوزّع منها حاجة لإشراقة لحد دلوقتي.
        </div>
    <?php else: ?>
        <?php
            // عدّادات الفلتر — بتتحسب هنا مرة واحدة
            $cntNew = 0; $cntEntered = 0;
            foreach ($invoices as $inv) {
                $s = $ashraqaStatus[(int) $inv['dist_id']] ?? 'new';
                if ($s === 'entered') $cntEntered++; else $cntNew++;
            }
        ?>
        <div class="ash-list-container">
            <div class="status-filters">
                <button type="button" class="sf-btn active" data-f="all" onclick="ashFilter(this)">
                    الكل <span><?= count($invoices) ?></span>
                </button>
                <button type="button" class="sf-btn" data-f="new" onclick="ashFilter(this)">
                    جديدة <span><?= $cntNew ?></span>
                </button>
                <button type="button" class="sf-btn" data-f="entered" onclick="ashFilter(this)">
                    تم الإدخال <span><?= $cntEntered ?></span>
                </button>
            </div>

            <input type="text" class="inv-filter" id="invFilter" autocomplete="off" inputmode="search"
                   aria-label="فلتر برقم الفاتورة" placeholder="فلتر: رقم الفاتورة…"
                   oninput="ashApply(true)">

            <div class="ash-table-wrap">
                <table class="ash-table" id="invTable" data-xt-off data-filter="all">
                    <thead>
                        <tr>
                            <th style="width:50px;">#</th>
                            <th>رقم الفاتورة</th>
                            <th>التاريخ</th>
                            <th>أصناف إشراقة</th>
                            <th>الإجمالي</th>
                            <th>الحالة</th>
                        </tr>
                    </thead>
                    <tbody id="invBody">
                        <?php foreach ($invoices as $idx => $inv):
                            $invUrl     = 'ashraqa_invoices.php?invoice=' . urlencode((string)$inv['inv_id']) . '&suffix=' . urlencode((string)($inv['suffix'] ?? ''));
                            $st         = $ashraqaStatus[(int) $inv['dist_id']] ?? 'new';
                            $stLbl      = $st === 'entered' ? 'تم الإدخال' : 'جديدة';
                            $docNo      = (string)($inv['inv_no'] ?? '');
                            $itemsCount = (int)($inv['ash_items_count'] ?? 0);
                            $invTotal   = (float)($inv['ash_total'] ?? 0);
                        ?>
                            <tr class="inv-row" tabindex="0" role="link"
                                data-st="<?= $st ?>"
                                data-no="<?= htmlspecialchars($docNo, ENT_QUOTES, 'UTF-8') ?>"
                                onclick="location.href='<?= $invUrl ?>'"
                                onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); location.href='<?= $invUrl ?>'; }">
                                <td class="rownum"><?= $idx + 1 ?></td>
                                <td class="inv-no">
                                    <?= htmlspecialchars($docNo !== '' ? $docNo : '—', ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td style="color:var(--ink-600);font-size:13px;">
                                    <?= !empty($inv['invoice_date']) ? htmlspecialchars(date('Y-m-d', strtotime((string)$inv['invoice_date'])), ENT_QUOTES, 'UTF-8') : '—' ?>
                                </td>
                                <td style="font-weight:600;color:var(--ink-700);">
                                    <?= $itemsCount ?>
                                </td>
                                <td style="font-weight:700;color:var(--accent-text);white-space:nowrap;">
                                    <?= number_format($invTotal, 2) ?> ج.م
                                </td>
                                <td>
                                    <span class="st-badge<?= $st === 'entered' ? ' st-entered' : '' ?>"><?= $stLbl ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="no-match" id="noMatch" style="display:none;">
                    مفيش فاتورة مطابقة للفلتر ده.
                </div>
            </div>

            <div class="ash-pagination" id="ashPagination" style="display:none;">
                <button type="button" class="btn btn-outline btn-sm" id="ashPrev" onclick="ashChangePage(-1)">السابق</button>
                <span class="ash-page-info" id="ashPageInfo"></span>
                <button type="button" class="btn btn-outline btn-sm" id="ashNext" onclick="ashChangePage(1)">التالي</button>
            </div>
        </div>
    <?php endif; ?>

    <script>
        // ===== الفلتر + البحث =====
        // 🔴 الاتنين بيشتغلوا **مع بعض** — الفلتر مابيلغيش البحث والعكس.
        //    الحالة متخزّنة في متغيّر بره عشان ماتضيعش لما الواحد يتحدّث.
        let ashF = 'all';
        let ashPage = 1;
        const ASH_PER_PAGE = 50;

        function ashFilter(btn) {
            document.querySelectorAll('.sf-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            ashF = btn.dataset.f;
            const tbl = document.getElementById('invTable');
            if (tbl) tbl.dataset.filter = ashF;
            ashPage = 1;
            ashApply();
        }

        function ashChangePage(delta) {
            ashPage += delta;
            ashApply();
        }

        function ashApply(resetPage = false) {
            if (resetPage) ashPage = 1;

            const q = (document.getElementById('invFilter')?.value || '').trim().toLowerCase();
            const tbl = document.getElementById('invTable');
            if (tbl) tbl.dataset.filter = ashF;

            // جمع الصفوف المطابقة وحساب ترتيبها الحقيقي
            const matches = [];
            document.querySelectorAll('#invBody tr.inv-row').forEach(tr => {
                const okSt = (ashF === 'all') || (tr.dataset.st === ashF);
                const okQ  = (q === '') || (tr.dataset.no || '').toLowerCase().includes(q);
                const on   = okSt && okQ;       // ← الشرطين مع بعض
                if (on) {
                    matches.push(tr);
                    tr.querySelector('.rownum').textContent = matches.length;
                } else {
                    tr.hidden = true;
                }
            });

            const total = matches.length;
            const totalPages = Math.ceil(total / ASH_PER_PAGE) || 1;

            if (ashPage > totalPages) ashPage = 1;
            if (ashPage < 1) ashPage = 1;

            // إظهار صفوف الصفحة الحالية فقط
            const start = (ashPage - 1) * ASH_PER_PAGE;
            const end   = start + ASH_PER_PAGE;
            matches.forEach((tr, idx) => {
                tr.hidden = (idx < start || idx >= end);
            });

            const nm = document.getElementById('noMatch');
            if (nm) nm.style.display = total === 0 ? '' : 'none';

            // شريط الترقيم
            const pWrap   = document.getElementById('ashPagination');
            const pInfo   = document.getElementById('ashPageInfo');
            const btnPrev = document.getElementById('ashPrev');
            const btnNext = document.getElementById('ashNext');

            if (pWrap) {
                if (totalPages <= 1) {
                    pWrap.style.display = 'none';
                } else {
                    pWrap.style.display = 'flex';
                    if (pInfo) pInfo.textContent = 'صفحة ' + ashPage + ' من ' + totalPages;
                    if (btnPrev) btnPrev.disabled = (ashPage <= 1);
                    if (btnNext) btnNext.disabled = (ashPage >= totalPages);
                }
            }
        }

        ashApply();

        // ===== بار «الصنف ده نزل في أنهي فاتورة؟» =====
        (function () {
            const inp  = document.getElementById('ilkInput');
            const sugg = document.getElementById('ilkSugg');
            if (!inp || !sugg) return;

            // seq: كل نداء ليه رقم، والرد القديم اللي يوصل متأخر بيتجاهل.
            // من غيره: تكتب بسرعة فرد «12» يوصل بعد رد «123» ويدوس عليه.
            let t = null, seq = 0;

            // 🔴 'block' مش '' — الـCSS بتاع .ilk-sugg فيه display:none،
            //    و style.display = '' بيمسح الستايل المكتوب فيرجع للـCSS
            //    = مخفي. البحث كان شغال والنتايج بتيجي من السيرفر، بس
            //    عمرها ما بانت. (invoices.php الأصلي كاتبها 'block' صح.)
            const show = () => { sugg.style.display = 'block'; };
            const hide = () => { sugg.style.display = 'none'; sugg.innerHTML = ''; delete sugg.dataset.term; };
            const esc  = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
                                       .replace(/>/g, '&gt;').replace(/"/g, '&quot;');

            function note(msg) {
                sugg.innerHTML = '<div class="ilk-s" style="cursor:default;color:var(--ink-500);">'
                               + esc(msg) + '</div>';
                show();
            }

            inp.addEventListener('input', function () {
                const term = inp.value.trim();
                clearTimeout(t);
                if (term.length < 2) { seq++; hide(); return; }
                // تأخير بسيط — من غيره بنبعت نداء مع كل حرف
                t = setTimeout(() => { search(term); }, 250);
            });

            // Enter = دوّر على طول.
            // 🔴 ده أهم سطر للسكانر: السكانر بيكتب الكود كله في لحظة ويبعت
            //    Enter **قبل** ما التأخير (250ms) يخلص والاقتراحات تيجي.
            //    فلو فيه اقتراحات **للكلمة دي بالظبط** ناخد أولها، وإلا
            //    ندوّر بالكود المكتوب نفسه (الباركود المضروب كود كامل).
            inp.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') { hide(); return; }
                if (e.key !== 'Enter') return;
                e.preventDefault();
                const term = inp.value.trim();
                if (!term) return;
                clearTimeout(t);
                const first = sugg.dataset.term === term ? sugg.querySelector('[data-code]') : null;
                if (first) history(first.dataset.code, parseInt(first.dataset.item, 10) || 0);
                else       history(term, 0);
            });

            function search(term) {
                const my = ++seq;
                fetch('ashraqa_invoices.php?ilk=search&term=' + encodeURIComponent(term))
                    .then(r => r.json())
                    .then(j => {
                        if (my !== seq) return;              // رد قديم — اتجاهله
                        if (!j.ok) throw new Error(j.error || 'خطأ');
                        if (!j.rows || !j.rows.length) { note('مفيش صنف بالاسم أو الكود ده.'); return; }
                        sugg.innerHTML = j.rows.map(r =>
                            '<div class="ilk-s" data-code="' + esc(r.code) + '" data-item="' + (parseInt(r.item_id, 10) || 0) + '">'
                          + esc(r.name || '—')
                          + ' <span class="ilk-c">' + esc(r.code) + '</span></div>'
                        ).join('');
                        sugg.dataset.term = term;   // الاقتراحات دي تبع الكلمة دي بالظبط
                        show();
                    })
                    .catch(e => { if (my === seq) note(e.message || 'تعذّر البحث'); });
            }

            // دوسة واحدة بتتعامل مع النوعين: اقتراح صنف، أو فاتورة من النتايج.
            // 🔴 stopPropagation: من غيرها الـinnerHTML بيتغيّر والعنصر اللي
            //    اتداس عليه بيتشال من الصفحة، فمستمع الـdocument يلاقيه «بره
            //    البار» ويقفل القايمة — كانت بتترعش وتختفي.
            sugg.addEventListener('click', function (e) {
                e.stopPropagation();
                const d = e.target.closest('.ilk-s');
                if (!d) return;
                if (d.dataset.url)  { location.href = d.dataset.url; return; }
                if (d.dataset.code) { history(d.dataset.code, parseInt(d.dataset.item, 10) || 0); }
            });

            function history(code, itemId) {
                const my = ++seq;
                delete sugg.dataset.term;
                note('بيدوّر…');
                // item_id بيغطّي كل باركودات الصنف — لو معروف ابعته
                fetch('ashraqa_invoices.php?ilk=history&code=' + encodeURIComponent(code)
                      + (itemId ? '&item_id=' + itemId : ''))
                    .then(r => r.json())
                    .then(j => {
                        if (my !== seq) return;
                        if (!j.ok) throw new Error(j.error || 'خطأ');
                        if (!j.rows || !j.rows.length) {
                            note('الصنف ده ما نزلش في أي فاتورة فيها توزيع لإشراقة.');
                            return;
                        }
                        const nm = j.item ? (j.item.name || code) : code;
                        sugg.innerHTML =
                            '<div class="ilk-s" style="cursor:default;background:var(--line-soft);font-weight:600;">'
                          + esc(nm) + ' <span class="ilk-c">' + esc(code) + '</span></div>'
                          + j.rows.map(r => {
                                const url = 'ashraqa_invoices.php?invoice=' + encodeURIComponent(r.sth_id)
                                          + '&suffix=' + encodeURIComponent(r.suffix || '');
                                return '<div class="ilk-s" data-url="' + esc(url) + '">'
                                     + 'فاتورة ' + esc(r.doc_no)
                                     + ' <span class="ilk-c">' + esc((r.date || '').substring(0, 10)) + '</span>'
                                     + '<div class="ilk-c">كمية الفاتورة ' + r.qty
                                     + ' · راح لإشراقة ' + r.ash_qty + '</div>'
                                     + '</div>';
                            }).join('');
                        show();
                    })
                    .catch(e => { if (my === seq) note(e.message || 'تعذّر الجلب'); });
            }

            document.addEventListener('click', function (e) {
                if (!e.target.closest('.ilk-bar')) hide();
            });
        })();
    </script>

<?php else: ?>
    <!-- ================= الشاشة الثانية: فاتورة واحدة ================= -->
    <div class="card" style="margin-bottom:16px;">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:12px;">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <a href="ashraqa_invoices.php" class="btn btn-outline btn-sm">
                    <i class="ti ti-arrow-right"></i> رجوع للفواتير
                </a>
                <h1 style="font-size:18px;margin:0;color:var(--ink-900);">
                    فاتورة رقم <?= htmlspecialchars((string)$openInvoice['inv_no'], ENT_QUOTES, 'UTF-8') ?>
                </h1>
                <?php $curSt = $ashraqaStatus[(int) $distId] ?? 'new'; ?>
                <span style="font-size:12.5px;color:var(--ink-500);">الحالة:</span>
                <div class="st-slider" id="stSlider" role="group" aria-label="حالة مراجعة الفاتورة">
                    <button type="button" data-v="new"
                            class="<?= $curSt === 'new' ? 'on' : '' ?>"
                            onclick="ashSetStatus('new')">جديدة</button>
                    <button type="button" data-v="entered"
                            class="<?= $curSt === 'entered' ? 'on' : '' ?>"
                            onclick="ashSetStatus('entered')">تم الإدخال</button>
                </div>
                <span id="stNote" style="font-size:12px;"></span>
            </div>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <a href="branch_export.php?invoice=<?= urlencode((string)$openId) ?>&branch=5&suffix=<?= urlencode((string)($openSuf ?? '')) ?>"
                   class="btn btn-outline btn-sm" title="تحميل ملف توزيع الفرع تكست">
                    <i class="ti ti-download"></i> تحميل تكست
                </a>
                <a href="ashraqa_prices_export.php?invoice=<?= urlencode((string)$openId) ?>&suffix=<?= urlencode((string)($openSuf ?? '')) ?>"
                   class="btn btn-outline btn-sm" title="تقرير أسعار إشراقة">
                    <i class="ti ti-file-invoice"></i> تقرير الأسعار
                </a>
                <a href="ashraqa_invoice_export.php?invoice=<?= urlencode((string)$openId) ?>&suffix=<?= urlencode((string)($openSuf ?? '')) ?>"
                   class="btn btn-outline btn-sm" title="تحميل إكسيل لأصناف إشراقة">
                    <i class="ti ti-file-spreadsheet"></i> تحميل إكسيل
                </a>
            </div>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;font-size:12.5px;color:var(--ink-500);border-top:1px solid var(--line-soft);padding-top:8px;">
            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                <span>تاريخ الفاتورة: <?= !empty($openInvoice['invoice_date']) ? htmlspecialchars(date('Y-m-d', strtotime((string)$openInvoice['invoice_date'])), ENT_QUOTES, 'UTF-8') : '—' ?></span>
                <span style="display:inline-flex;align-items:center;gap:6px;background:var(--line-soft);padding:3px 10px;border-radius:6px;font-weight:600;color:var(--ink-900);">
                    إجمالي التكلفة لإشراقة: <strong style="color:var(--accent-text);font-size:13.5px;"><?= number_format($totalAshraqaCost, 2) ?></strong> ج.م
                </span>
            </div>
            <div>
                <i class="ti ti-clock"></i> آخر تحديث أرصدة إشراقة: <?= $lastSync ? htmlspecialchars($lastSync, ENT_QUOTES, 'UTF-8') : 'لسه مافيش مزامنة' ?>
            </div>
        </div>
    </div>

    <script>
        // سلايدر الحالة — بيتحفظ على الضغط، مفيش زرار حفظ (قرار نهائي في المشروع).
        // بره بلوك الأصناف عشان يفضل شغّال حتى لو الفاتورة مالهاش أصناف.
        const ST_CSRF = '<?= csrf_token() ?>';
        const ST_DIST = <?= (int) $distId ?>;

        function ashSetStatus(v) {
            const box  = document.getElementById('stSlider');
            const note = document.getElementById('stNote');
            if (!box) return;

            const btns = box.querySelectorAll('button');
            const cur  = box.querySelector('button.on');
            if (cur && cur.dataset.v === v) return;      // نفس الحالة — مفيش نداء

            const prev = cur ? cur.dataset.v : 'new';
            btns.forEach(b => { b.classList.toggle('on', b.dataset.v === v); b.disabled = true; });
            note.textContent = 'بيحفظ…';
            note.style.color = 'var(--ink-500)';

            fetch('ashraqa_invoices.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    action: 'set_status', csrf: ST_CSRF,
                    distribution_id: ST_DIST, status: v
                }).toString()
            })
            .then(r => r.json())
            .then(j => {
                if (!j.ok) throw new Error(j.error || 'تعذّر الحفظ');
                note.textContent = '✓ اتحفظ';
                note.style.color = 'var(--pos)';
                setTimeout(() => { note.textContent = ''; }, 2000);
            })
            .catch(err => {
                // رجّع الشكل للحالة القديمة — مايبانش إنه اتحفظ وهو ما اتحفظش
                btns.forEach(b => b.classList.toggle('on', b.dataset.v === prev));
                note.textContent = '✗ ' + (err.message || 'تعذّر الحفظ');
                note.style.color = 'var(--neg)';
            })
            .finally(() => { btns.forEach(b => { b.disabled = false; }); });
        }
    </script>

    <?php if (empty($ashraqaItems)): ?>
        <div class="card" style="text-align:center;padding:36px;color:var(--ink-500);">
            مفيش أصناف اتوزّعت لإشراقة في الفاتورة دي.
        </div>
    <?php else: ?>
        <div class="ash-mrk-bulk">
            <span class="ash-mrk-lbl"><i class="ti ti-percentage"></i> نسبة التسعير لكل الأصناف</span>
            <input type="number" step="0.5" id="ashMrkBulk" placeholder="مثال: 50"
                   aria-label="نسبة تسعير لكل الأصناف" autocomplete="off"
                   onkeydown="ashMrkBulkKey(event)">
            <button type="button" class="btn btn-sm btn-primary" onclick="applyAshBulkMrk()">
                <i class="ti ti-check"></i> طبّق
            </button>
            <span class="ash-mrk-hint">بتسعّر كل الأصناف مرة واحدة (بتأكيد قبلها).</span>
            <span id="ashMrkBulkNote" style="display:none;font-size:13px;"></span>
        </div>

        <div class="ash-table-wrap">
            <table class="ash-table">
                <thead>
                    <tr>
                        <th>كود الصنف</th>
                        <th>اسم الصنف</th>
                        <th>كمية إشراقة</th>
                        <th>رصيد إشراقة</th>
                        <th>سعر الوحدة</th>
                        <th>سعر البيع</th>
                        <th>نسبة التسعير</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ashraqaItems as $it):
                        $code = (string)($it['item_code'] ?? '');
                        $qty  = (float)($ashraqaQtys[$code] ?? 0);
                        $hasPrice = isset($salePrices[$code]);
                        $sPrice = $hasPrice ? (float)$salePrices[$code] : null;

                        // رصيد إشراقة: قد يكون سالب ويعرض كما هو، ولو غير موجود يعرض —
                        if (isset($balances[$code])) {
                            $bVal = (float)$balances[$code]['balance'];
                            $bDisp = rtrim(rtrim(number_format($bVal, 2, '.', ''), '0'), '.');
                            if ($bDisp === '-0' || $bDisp === '') $bDisp = '0';
                        } else {
                            $bDisp = '—';
                        }
                    ?>
                        <tr class="item-row">
                            <td class="c-code"><?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="font-weight:500;color:var(--ink-900);">
                                <?= htmlspecialchars((string)($it['item_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td style="font-weight:600;">
                                <?= rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') ?>
                            </td>
                            <td>
                                <span><?= htmlspecialchars($bDisp, ENT_QUOTES, 'UTF-8') ?></span>
                            </td>
                            <td style="color:var(--ink-600);">
                                <?= number_format((float)($it['unit_price'] ?? 0), 2) ?>
                            </td>
                            <td class="c-sale">
                                <div style="display:inline-flex;align-items:center;">
                                    <input type="number" step="0.01" min="0"
                                           class="sale-input" aria-label="سعر البيع"
                                           value="<?= $hasPrice ? rtrim(rtrim(number_format($sPrice, 5, '.', ''), '0'), '.') : '' ?>"
                                           <?php /* 🔴 مش "0" — الصفر شكله سعر متسجّل بصفر، وده غير
                                                    "لسه ما اتسعّرش". الفرق ده بيبان في تقرير الأسعار. */ ?>
                                           placeholder="—"
                                           data-code="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>"
                                           data-saved="<?= $hasPrice ? htmlspecialchars((string)$sPrice, ENT_QUOTES, 'UTF-8') : '' ?>"
                                           oninput="ashOnSaleInput(this)"
                                           onblur="ashSaveSalePrice(this)">
                                    <span class="sale-ind"></span>
                                </div>
                            </td>
                            <?= ash_mrk_cell($code, ash_mrk_cost($it), $sPrice) ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if (!empty($orphanedDistItems)): ?>
            <div class="card" style="margin-top:16px;background:var(--warn-soft, #fff8e6);border:1.5px solid var(--warn, #f59e0b);padding:16px;">
                <div style="font-weight:600;color:var(--warn-ink, #92400e);margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                    <i class="ti ti-alert-triangle"></i>
                    أصناف اتوزّعت لإشراقة ومش موجودة في الفاتورة دي دلوقتي (الفاتورة ممكن تكون اتعدّلت في نظام الشركة):
                </div>
                <table style="width:100%;font-size:13px;">
                    <thead>
                        <tr>
                            <th style="text-align:right;">الكود</th>
                            <th style="text-align:right;">اسم الصنف</th>
                            <th style="text-align:right;">الكمية</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orphanedDistItems as $orp): ?>
                            <tr>
                                <td class="c-code"><?= htmlspecialchars((string)$orp['code'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string)($orp['name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td style="font-weight:600;"><?= rtrim(rtrim(number_format((float)$orp['qty'], 2, '.', ''), '0'), '.') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <script>
            const CSRF = '<?= csrf_token() ?>';
            const DIST_ID = <?= (int)$distId ?>;

            function ashMrkRound(v) {
                return Math.round(v / 0.5) * 0.5;
            }

            function ashMrkFmt(p) {
                if (p === null || isNaN(p)) return '';
                let s = Number(p).toFixed(1);
                if (s.endsWith('.0')) s = s.slice(0, -2);
                return (s === '' || s === '-') ? '0' : s;
            }

            function refreshAshMrk(row) {
                if (!row) return;
                const m = row.querySelector('.mrk-input');
                const s = row.querySelector('.sale-input');
                if (!m || !s) return;
                const cost = parseFloat(m.dataset.cost || '0') || 0;
                const sale = parseFloat(s.value || '0') || 0;
                m.placeholder = (cost > 0 && sale > 0) ? ashMrkFmt((sale / cost - 1) * 100) + '%' : '—';
            }

            function ashOnSaleInput(inp) {
                inp.classList.add('dirty');
                const row = inp.closest('tr');
                if (row) refreshAshMrk(row);
            }

            function ashMrkKey(ev, inp) {
                const k = ev.key || '';
                const isEnter = k === 'Enter' || k === 'Go' || k === 'Done' || k === 'Next'
                             || ev.keyCode === 13 || ev.which === 13;
                if (!isEnter) return;
                ev.preventDefault();
                inp.blur();
            }

            function applyAshMrk(inp) {
                const raw = (inp.value || '').trim();
                inp.value = '';
                if (raw === '') return;

                const p = parseFloat(raw);
                if (isNaN(p) || p <= -100) return;

                const cost = parseFloat(inp.dataset.cost || '0') || 0;
                if (cost <= 0) return;

                const row = inp.closest('tr');
                const sale = row && row.querySelector('.sale-input');
                if (!sale) return;

                const val = ashMrkRound(cost * (1 + p / 100));
                sale.value = String(val);
                sale.classList.add('dirty');
                ashSaveSalePrice(sale);
                refreshAshMrk(row);
            }

            function ashSaveSalePrice(inp) {
                const code = inp.dataset.code;
                const raw  = (inp.value || '').trim();
                const val  = raw === '' ? 0 : parseFloat(raw);
                if (isNaN(val) || val < 0) {
                    inp.value = inp.dataset.saved || '';
                    return;
                }

                const prev = parseFloat(inp.dataset.saved || '0') || 0;
                if (inp.dataset.saved !== '' && Math.abs(val - prev) < 0.000001) {
                    inp.classList.remove('dirty');
                    return;
                }

                const ind = inp.parentElement ? inp.parentElement.querySelector('.sale-ind') : null;
                if (ind) { ind.textContent = '⏳'; ind.className = 'sale-ind wait'; }

                const body = {
                    action: 'save_price',
                    csrf: CSRF,
                    distribution_id: DIST_ID,
                    item_code: code,
                    price: val
                };

                fetch('ashraqa_invoices.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams(body).toString()
                })
                .then(r => r.json())
                .then(j => {
                    if (j.ok) {
                        inp.dataset.saved = String(val);
                        inp.classList.remove('dirty');
                        if (ind) { ind.textContent = '✓'; ind.className = 'sale-ind ok'; }
                        setTimeout(() => { if (ind) ind.textContent = ''; }, 1400);
                        const row = inp.closest('tr');
                        if (row) refreshAshMrk(row);
                    } else {
                        if (ind) { ind.textContent = '✕'; ind.className = 'sale-ind err'; }
                        alert(j.error || 'تعذّر الحفظ');
                    }
                })
                .catch(() => {
                    if (ind) { ind.textContent = '✕'; ind.className = 'sale-ind err'; }
                });
            }

            function ashMrkBulkKey(ev) {
                const k = ev.key || '';
                const isEnter = k === 'Enter' || k === 'Go' || k === 'Done' || k === 'Next'
                             || ev.keyCode === 13 || ev.which === 13;
                if (!isEnter) return;
                ev.preventDefault();
                applyAshBulkMrk();
            }

            function applyAshBulkMrk() {
                const box  = document.getElementById('ashMrkBulk');
                const note = document.getElementById('ashMrkBulkNote');
                const setNote = (txt, color) => {
                    if (!note) return;
                    note.textContent = txt; note.style.color = color; note.style.display = 'inline';
                    clearTimeout(window.__ashMrkNoteT);
                    window.__ashMrkNoteT = setTimeout(() => { note.style.display = 'none'; }, 6000);
                };
                if (!box) return;

                const raw = (box.value || '').trim();
                if (raw === '') { box.focus(); return; }
                const p = parseFloat(raw);
                if (isNaN(p) || p <= -100) { setNote('✗ اكتب نسبة صحيحة', 'var(--neg)'); return; }

                const prices = {};
                const rows = [];
                let noCost = 0;
                document.querySelectorAll('tr.item-row').forEach(row => {
                    const m = row.querySelector('.mrk-input');
                    const s = row.querySelector('.sale-input');
                    if (!m || !s) return;
                    const cost = parseFloat(m.dataset.cost || '0') || 0;
                    if (cost <= 0) { noCost++; return; }
                    const val = ashMrkRound(cost * (1 + p / 100));
                    prices[s.dataset.code] = val;
                    rows.push({ input: s, row: row, val: val });
                });

                const n = rows.length;
                if (!n) { setNote('✗ مفيش أصناف ليها تكلفة', 'var(--neg)'); return; }

                const extra = noCost > 0 ? '\n(' + noCost + ' صنف من غير تكلفة هيتساب زي ما هو)' : '';
                if (!confirm('هيتسعّر ' + n + ' صنف بنسبة ' + p + '%\n\n'
                           + '⚠️ أي سعر بيع موجود دلوقتي هيتكتب فوقه.' + extra + '\n\nتمام؟')) return;

                setNote('بيحفظ ' + n + ' صنف…', 'var(--ink-500)');
                box.disabled = true;

                const body = {
                    action: 'save_prices_bulk',
                    csrf: CSRF,
                    distribution_id: DIST_ID,
                    prices: JSON.stringify(prices)
                };

                fetch('ashraqa_invoices.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams(body).toString()
                })
                .then(r => r.json())
                .then(j => {
                    if (!j.ok) throw new Error(j.error || 'تعذّر الحفظ');
                    rows.forEach(r => {
                        r.input.value = String(r.val);
                        r.input.dataset.saved = String(r.val);
                        r.input.classList.remove('dirty');
                        refreshAshMrk(r.row);
                    });
                    box.value = '';
                    setNote('✓ اتسعّر ' + n + ' صنف بنسبة ' + p + '%', 'var(--pos)');
                })
                .catch(err => setNote('✗ ' + (err.message || 'تعذّر الحفظ'), 'var(--neg)'))
                .finally(() => { box.disabled = false; });
            }
        </script>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
