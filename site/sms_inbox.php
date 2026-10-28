<?php
/**
 * sms_inbox.php — عرض رسايل إنستاباي وإدارة الفلتر وبيانات الربط
 * =====================================================================
 *  للأونر فقط — قراءة الرسايل الواردة من تطبيق الأندرويد، تعديل كلمات
 *  الفلتر (حفظ فوري على blur)، وبيانات الاتصال الخاصة بالتطبيق.
 * =====================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_permission('sms_inbox');

require_once __DIR__ . '/sync_tokens.php';
$pdo = db();

// معالجة حفظ كلمات الفلتر وتسجيل الأذونات عبر AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'انتهت صلاحية الجلسة، اعمل تحديث.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'save_mark') {
        $smsId = (int) ($_POST['sms_id'] ?? 0);
        $note = trim((string) ($_POST['note'] ?? ''));
        $color = trim((string) ($_POST['color'] ?? ''));
        if ($smsId <= 0 || !in_array($color, ['', 'red', 'green', 'yellow', 'orange'], true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'بيانات الرسالة أو اللون غير صحيحة.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($note === '' && $color === '') {
            $pdo->prepare('DELETE FROM sms_marks WHERE sms_id = ?')->execute([$smsId]);
        } else {
            $pdo->prepare('INSERT INTO sms_marks (sms_id, note, color, updated_by) VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE note = VALUES(note), color = VALUES(color),
                updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP')
                ->execute([$smsId, $note === '' ? null : $note, $color === '' ? null : $color, (int) current_user()['id']]);
        }
        echo json_encode(['ok' => true, 'note' => $note, 'color' => $color], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'create_permit') {
        require_once __DIR__ . '/sms_permits_lib.php';
        $smsId = (int) ($_POST['sms_id'] ?? 0);
        $permitAction = (string) ($_POST['permit_action'] ?? '');
        $uid = (int) current_user()['id'];
        try {
            $permitId = sms_permit_create($smsId, $permitAction, $_POST, $uid);
            echo json_encode(['ok' => true, 'permit_id' => $permitId], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    if ($action === 'save_setting') {
        if (!is_owner()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'العملية دي للمالك فقط.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $key = trim((string) ($_POST['key'] ?? ''));
        $val = trim((string) ($_POST['value'] ?? ''));

        // السماح بمفتاحين فقط
        if (!in_array($key, ['sms_include_keywords', 'sms_exclude_keywords'], true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'مفتاح غير مسموح به.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $st = $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?');
        $st->execute([$val, $key]);

        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'عملية غير معروفة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/sms_lib.php';

// (١) جلب إحصائيات الرسايل
$stats = sms_get_stats($pdo);
$totalCount    = $stats['total_count'];
$lastReceived  = $stats['last_received'];
$backfillCount = $stats['backfill_count'];
$liveCount     = $stats['live_count'];

// (٢) جلب إعدادات كلمات الفلتر
$keywords = sms_get_keywords($pdo);
$includeKw = $keywords['include_keywords'];
$excludeKw = $keywords['exclude_keywords'];

// (٣) معالجة الفلتر وجلب الرسايل المحللة
$period = sms_parse_period($_GET['from'] ?? null, $_GET['to'] ?? null, $_GET['kind'] ?? null);
$from   = $period['from'];
$to     = $period['to'];
$kind   = $period['kind'];

$allMessages = sms_fetch($pdo, $from, $to, $kind);

// إجماليات الكروت على كامل النتائج في الفترة
$totals = sms_calc_totals($allMessages);
$totInCount   = $totals['in_count'];
$totInAmount  = $totals['in_amount'];
$totOutCount  = $totals['out_count'];
$totOutAmount = $totals['out_amount'];
$unknownCount = $totals['unknown_count'];
$foreignCount = $totals['foreign_count'];
$netAmount    = $totals['net_amount'];

// تحديد المعروض في الجدول (أول ٥٠٠ صف فقط)
$totalCountFiltered = count($allMessages);
$messages = array_slice($allMessages, 0, 500);
$hasMore = $totalCountFiltered > 500;
$moreCount = $totalCountFiltered - 500;

// (٤) بيانات الربط
$webhookInfo = sms_get_webhook_info(is_owner());
$hookUrl     = $webhookInfo['hook_url'];
$hookToken   = $webhookInfo['hook_token'];

require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/sms_permits_lib.php';

$costBranches = cost_branches_list();
$suppliers = $pdo->query('SELECT id, name FROM suppliers WHERE is_active=1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$employees = $pdo->query('SELECT id, full_name FROM employees WHERE is_active=1 ORDER BY full_name')->fetchAll(PDO::FETCH_ASSOC);
$branchesForCustody = $pdo->query('SELECT b.id, b.name FROM branches b JOIN treasuries t ON t.branch_id=b.id AND t.type="branch_custody" WHERE b.is_active=1 ORDER BY b.name')->fetchAll(PDO::FETCH_ASSOC);
// نفس ترتيب treasury.php — جدول treasuries مفيهوش sort_order
$allTreasuries = $pdo->query('SELECT id, name, type, show_in_receipts FROM treasuries WHERE is_active=1 ORDER BY FIELD(type, "cash","main","visa","instapay","card","branch_custody","employee_custody"), name')->fetchAll(PDO::FETCH_ASSOC);
$instapayTreasuryId = 0;
foreach ($allTreasuries as $t) {
    if ($t['type'] === 'instapay') {
        $instapayTreasuryId = (int) $t['id'];
        break;
    }
}

$smsIds = array_map(fn($m) => (int) $m['id'], $messages);
// try: قبل ما local/upgrade_sms_permits.sql يتشغّل الجدول مش موجود — الصفحة تفضل شغالة
try {
    $permitsMap = sms_permits_for($smsIds);
} catch (Throwable $e) {
    $permitsMap = [];
}

$page_title    = 'رسايل إنستاباي';
$active_module = 'sms_inbox';
require_once __DIR__ . '/includes/header.php';
?>

<div class="content-container">

    <div class="fpage-head" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
        <h2 style="margin:0;font-size:22px;display:flex;align-items:center;gap:8px;">
            <i class="ti ti-device-mobile" style="color:var(--accent-text);"></i> رسايل إنستاباي
        </h2>
        <a href="sms_inbox.php" class="btn btn-ghost"><i class="ti ti-refresh"></i> تحديث</a>
    </div>

    <!-- (١) كروت الحالة -->
    <div class="fkpis" style="margin-bottom:24px;">
        <div class="fkpi">
            <div style="font-size:12px;color:var(--ink-500);margin-bottom:6px;">إجمالي الرسايل</div>
            <div style="font-size:24px;font-weight:700;color:var(--accent-text);"><?= number_format($totalCount) ?></div>
        </div>
        <div class="fkpi">
            <div style="font-size:12px;color:var(--ink-500);margin-bottom:6px;">رسايل جديدة (مباشرة)</div>
            <div style="font-size:24px;font-weight:700;color:var(--pos);"><?= number_format($liveCount) ?></div>
        </div>
        <div class="fkpi">
            <div style="font-size:12px;color:var(--ink-500);margin-bottom:6px;">رسايل قديمة (سحب)</div>
            <div style="font-size:24px;font-weight:700;color:var(--ink-700);"><?= number_format($backfillCount) ?></div>
        </div>
        <div class="fkpi">
            <div style="font-size:12px;color:var(--ink-500);margin-bottom:6px;">آخر رسالة وصلت</div>
            <div style="font-size:15px;font-weight:600;color:var(--ink-800);direction:ltr;text-align:right;"><?= e($lastReceived) ?></div>
        </div>
    </div>

    <?php if (is_owner()): ?>
    <!-- (٢) بيانات ربط التطبيق -->
    <div class="fcard" style="margin-bottom:24px;">
        <h3 style="margin:0 0 12px 0;font-size:15px;display:flex;align-items:center;gap:6px;">
            <i class="ti ti-device-mobile"></i> إعدادات الربط في تطبيق الأندرويد
        </h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(300px, 1fr));gap:16px;">
            <div>
                <label style="display:block;font-size:12px;color:var(--ink-500);margin-bottom:6px;">رابط السيرفر (Webhook URL)</label>
                <div style="display:flex;gap:6px;">
                    <input type="text" class="finput" id="hookUrlInp" value="<?= e($hookUrl) ?>" readonly
                           style="direction:ltr;font-variant-numeric:tabular-nums;">
                    <button type="button" class="btn btn-ghost" onclick="copyField('hookUrlInp', 'تم نسخ الرابط')" title="نسخ الرابط">
                        <i class="ti ti-copy"></i>
                    </button>
                </div>
            </div>
            <div>
                <label style="display:block;font-size:12px;color:var(--ink-500);margin-bottom:6px;">توكن الحماية (Secret Token)</label>
                <div style="display:flex;gap:6px;">
                    <input type="password" class="finput" id="hookTokInp" value="<?= e($hookToken) ?>" readonly
                           style="direction:ltr;font-variant-numeric:tabular-nums;">
                    <button type="button" class="btn btn-ghost" onclick="toggleTokVis()" title="إظهار / إخفاء">
                        <i class="ti ti-eye"></i>
                    </button>
                    <button type="button" class="btn btn-ghost" onclick="copyField('hookTokInp', 'تم نسخ التوكن')" title="نسخ التوكن">
                        <i class="ti ti-copy"></i>
                    </button>
                </div>
            </div>
        </div>
        <div style="font-size:12px;color:var(--ink-400);margin-top:10px;">
            اكتب البيانات دي في شاشة التطبيق على موبايلك واضغط «جرّب الاتصال». التوكن محمي ولا تظهره إلا عند الحاجة.
        </div>
    </div>
    <?php endif; ?>

    <!-- (٣) كلمات الفلتر -->
    <div class="fcard" style="margin-bottom:24px;">
        <h3 style="margin:0 0 6px 0;font-size:15px;display:flex;align-items:center;gap:6px;">
            <i class="ti ti-filter"></i> كلمات فلتر الرسائل (بتتحفظ لوحدها)
        </h3>
        <p style="font-size:12px;color:var(--ink-500);margin:0 0 14px 0;">
            اكتب كلمة في كل سطر. التطبيق بيسحب الكلمات دي تلقائياً أول ما يفتح وكل ٦ ساعات.
        </p>

        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:16px;">
            <div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                    <label style="font-weight:600;font-size:13px;color:var(--pos);">كلمات التضمين (الرسايل المقبولة)</label>
                    <span class="save-ind" id="ind_include" style="font-size:12px;font-weight:600;"></span>
                </div>
                <textarea class="ftextarea kw-textarea" data-key="sms_include_keywords" rows="7"
                          data-saved="<?= e($includeKw) ?>" onblur="saveKeywords(this)"
                          ><?= e($includeKw) ?></textarea>
                <div style="font-size:11px;color:var(--ink-400);margin-top:4px;">
                    الرسالة اللي فيها أي كلمة من دول في الراسل أو النص بتتبعت للموقع.
                </div>
            </div>

            <div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                    <label style="font-weight:600;font-size:13px;color:var(--neg);">كلمات الاستبعاد (تمنع الإرسال فوراً)</label>
                    <span class="save-ind" id="ind_exclude" style="font-size:12px;font-weight:600;"></span>
                </div>
                <textarea class="ftextarea kw-textarea" data-key="sms_exclude_keywords" rows="7"
                          data-saved="<?= e($excludeKw) ?>" onblur="saveKeywords(this)"
                          ><?= e($excludeKw) ?></textarea>
                <div style="font-size:11px;color:var(--ink-400);margin-top:4px;">
                    الرسالة اللي فيها أي كلمة من دول (أكواد التحقق وOTP) مابتتبعتش خالص حتى لو فيها كلمة تضمين.
                </div>
            </div>
        </div>
    </div>

    <!-- (٤) كروت ملخص الفترة والفلتر وجدول الرسايل -->
    <div class="fkpis" style="margin-bottom:20px;">
        <div class="fkpi" style="border-top:3px solid var(--pos);">
            <div style="font-size:12px;color:var(--ink-500);margin-bottom:4px;">إجمالي الداخل</div>
            <div style="font-size:20px;font-weight:700;color:var(--pos);"><?= number_format($totInAmount, 2) ?> <span style="font-size:13px;font-weight:400;">جم</span></div>
            <div style="font-size:12px;color:var(--ink-400);margin-top:2px;"><?= number_format($totInCount) ?> عملية</div>
        </div>
        <div class="fkpi" style="border-top:3px solid var(--neg);">
            <div style="font-size:12px;color:var(--ink-500);margin-bottom:4px;">إجمالي الخارج (تحويلات + كارت)</div>
            <div style="font-size:20px;font-weight:700;color:var(--neg);"><?= number_format($totOutAmount, 2) ?> <span style="font-size:13px;font-weight:400;">جم</span></div>
            <div style="font-size:12px;color:var(--ink-400);margin-top:2px;"><?= number_format($totOutCount) ?> عملية</div>
        </div>
        <div class="fkpi" style="border-top:3px solid <?= $netAmount >= 0 ? 'var(--pos)' : 'var(--neg)' ?>;">
            <div style="font-size:12px;color:var(--ink-500);margin-bottom:4px;">الصافي</div>
            <div style="font-size:20px;font-weight:700;color:<?= $netAmount >= 0 ? 'var(--pos)' : 'var(--neg)' ?>;">
                <?= ($netAmount < 0 ? '−' : '') . number_format(abs($netAmount), 2) ?> <span style="font-size:13px;font-weight:400;">جم</span>
            </div>
            <div style="font-size:12px;color:var(--ink-400);margin-top:2px;">داخل − خارج</div>
        </div>
        <div class="fkpi" style="border-top:3px solid var(--ink-400);">
            <div style="font-size:12px;color:var(--ink-500);margin-bottom:4px;">مش معروف</div>
            <div style="font-size:20px;font-weight:700;color:var(--ink-600);"><?= number_format($unknownCount) ?></div>
            <div style="font-size:12px;color:var(--ink-400);margin-top:2px;">رسائل خارج النمط</div>
        </div>
        <div class="fkpi" style="border-top:3px solid var(--neg);">
            <div style="font-size:12px;color:var(--ink-500);margin-bottom:4px;"><?= e(SMS_FOREIGN_LABEL) ?> (مش شغل)</div>
            <div style="font-size:20px;font-weight:700;color:var(--neg);"><?= number_format($foreignCount) ?></div>
            <div style="font-size:12px;color:var(--ink-400);margin-top:2px;">برّه الإجماليات · بالأحمر</div>
        </div>
    </div>

    <div class="fcard">
        <!-- شريط الفلتر والبحث والتصدير -->
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px;">
            <form method="GET" action="sms_inbox.php" class="ffilters">
                <div>
                    <label style="display:block;font-size:11px;color:var(--ink-500);margin-bottom:4px;">من تاريخ</label>
                    <input type="date" class="finput" name="from" value="<?= e($from) ?>">
                </div>
                <div>
                    <label style="display:block;font-size:11px;color:var(--ink-500);margin-bottom:4px;">إلى تاريخ</label>
                    <input type="date" class="finput" name="to" value="<?= e($to) ?>">
                </div>
                <div>
                    <label style="display:block;font-size:11px;color:var(--ink-500);margin-bottom:4px;">النوع</label>
                    <select class="fselect" name="kind">
                        <option value="all" <?= $kind === 'all' ? 'selected' : '' ?>>الكل</option>
                        <option value="in" <?= $kind === 'in' ? 'selected' : '' ?>>داخل</option>
                        <option value="out" <?= $kind === 'out' ? 'selected' : '' ?>>خارج</option>
                        <option value="card" <?= $kind === 'card' ? 'selected' : '' ?>>كارت</option>
                        <option value="unknown" <?= $kind === 'unknown' ? 'selected' : '' ?>>مش معروف</option>
                    </select>
                </div>
                <div>
                    <label for="smsColorFilter" style="display:block;font-size:11px;color:var(--ink-500);margin-bottom:4px;">اللون</label>
                    <select class="fselect" id="smsColorFilter" onchange="filterSmsTable()">
                        <option value="all">كل الألوان</option><option value="yellow">أصفر</option><option value="green">أخضر</option>
                        <option value="red">أحمر</option><option value="orange">برتقالي</option><option value="none">بلا لون</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-sm"><i class="ti ti-search"></i> تصفية</button>
                <a href="sms_export.php?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&kind=<?= urlencode($kind) ?>" class="btn btn-sm">
                    <i class="ti ti-download"></i> نزّل إكسيل
                </a>
            </form>

            <div style="position:relative;width:240px;max-width:100%;">
                <i class="ti ti-search" style="position:absolute;top:9px;right:10px;color:var(--ink-400);"></i>
                <input type="text" class="finput" id="smsSearch" placeholder="بحث سريع في النتائج…"
                       oninput="filterSmsTable()"
                       style="padding-inline-start:32px;">
            </div>
        </div>

        <?php if (empty($messages)): ?>
            <div style="text-align:center;padding:40px 10px;color:var(--ink-400);">
                <i class="ti ti-inbox" style="font-size:36px;display:block;margin-bottom:8px;"></i>
                مفيش أي رسالة في الفترة أو النوع المختار.
            </div>
        <?php else: ?>
            <div class="ftable-wrap">
                <table class="ftable" id="smsTable">
                    <thead>
                        <tr style="border-bottom:2px solid var(--line);text-align:right;">
                            <th style="padding:10px 8px;width:140px;">التاريخ والوقت</th>
                            <th style="padding:10px 8px;width:80px;text-align:center;">النوع</th>
                            <th class="num" style="width:100px;">المبلغ</th>
                            <th style="padding:10px 8px;">الطرف</th>
                            <th style="padding:10px 8px;width:120px;">الرقم المرجعي</th>
                            <th style="padding:10px 8px;width:130px;text-align:center;">القيد</th>
                            <th style="padding:10px 8px;width:110px;">الراسل</th>
                            <th style="padding:10px 8px;">نص الرسالة</th>
                            <th style="padding:10px 8px;min-width:175px;">ملاحظة</th>
                            <th style="padding:10px 8px;min-width:140px;">لون</th>
                            <th style="padding:10px 8px;width:70px;text-align:center;">المصدر</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($messages as $m): ?>
                            <?php
                                $sClass = $m['source'] === 'live' ? 'fchip-pos' : '';
                                $sLabel = $m['source'] === 'live' ? 'جديدة' : 'قديمة';
                            ?>
                            <tr class="sms-row <?= !empty($m['foreign']) ? 'sms-foreign' : '' ?> <?= $m['color'] !== '' ? 'sms-mark-' . e($m['color']) : '' ?>" style="border-bottom:1px solid var(--line);"
                                data-sms-id="<?= (int) $m['id'] ?>" data-color="<?= e($m['color']) ?>"
                                data-search="<?= e(mb_strtolower($m['sender'] . ' ' . $m['body'] . ' ' . ($m['party'] ?? '') . ' ' . ($m['ref'] ?? '') . ' ' . ($m['device'] ?? ''))) ?>">
                                <td style="padding:10px 8px;white-space:nowrap;direction:ltr;text-align:right;font-size:12px;">
                                    <?php if ($m['txn_at']): ?>
                                        <span style="color:var(--ink-800);font-weight:600;"><?= e($m['txn_at']) ?></span>
                                    <?php else: ?>
                                        <span style="color:var(--ink-400);" title="وقت وصول الرسالة"><?= e($m['sms_at']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:10px 8px;white-space:nowrap;text-align:center;">
                                    <?php
                                        if ($m['kind'] === 'in') {
                                             echo '<span class="fchip fchip-pos">داخل</span>';
                                        } elseif ($m['kind'] === 'out') {
                                            echo '<span class="fchip fchip-neg">خارج</span>';
                                        } elseif ($m['kind'] === 'card') {
                                            echo '<span class="fchip fchip-neg">كارت</span>';
                                        } else {
                                            echo '<span class="fchip">مش معروف</span>';
                                        }
                                    ?>
                                </td>
                                <td class="num" style="white-space:nowrap;direction:ltr;">
                                    <?php if ($m['amount'] !== null): ?>
                                        <?php $amtColor = $m['kind'] === 'in' ? 'var(--pos)' : ($m['kind'] !== null ? 'var(--neg)' : 'var(--ink-700)'); ?>
                                        <span style="color:<?= $amtColor ?>;font-weight:700;"><?= number_format((float) $m['amount'], 2) ?></span>
                                    <?php else: ?>
                                        <span style="color:var(--ink-400);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:10px 8px;white-space:nowrap;font-weight:500;">
                                    <?= e($m['party'] ?? '—') ?>
                                </td>
                                <td class="num" style="white-space:nowrap;direction:ltr;font-variant-numeric:tabular-nums;font-size:12px;">
                                    <?= e($m['ref'] ?? '—') ?>
                                </td>
                                <td style="padding:10px 8px;text-align:center;white-space:nowrap;" id="spCell_<?= (int) $m['id'] ?>">
                                    <?php
                                        $sp = $permitsMap[(int) $m['id']] ?? null;
                                        if (!empty($m['foreign']) && !$sp):
                                    ?>
                                        <span class="fchip fchip-neg"
                                              title="<?= e(SMS_FOREIGN_LABEL) ?> — متسجّلة على نفس الرقم ومالهاش علاقة بالشغل">
                                            مش شغل
                                        </span>
                                    <?php elseif ($sp && $sp['status'] === 'approved'):
                                    ?>
                                        <span class="fchip fchip-pos">
                                            ✓ اتسجّل (إذن #<?= (int) $sp['id'] ?>)
                                        </span>
                                    <?php elseif ($sp && ($sp['status'] === 'pending' || $sp['status'] === 'approving')): ?>
                                        <span class="fchip fchip-warn">
                                            مستني المراجعة
                                        </span>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm"
                                                onclick="openPermitModal(<?= htmlspecialchars(json_encode([
                                                    'id'     => (int) $m['id'],
                                                    'kind'   => (string) $m['kind'],
                                                    'amount' => (float) ($m['amount'] ?? 0),
                                                    'date'   => substr((string) ($m['txn_at'] ?: $m['sms_at']), 0, 10),
                                                    'party'  => (string) ($m['party'] ?? ''),
                                                ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)">
                                            سجّل إذن
                                        </button>
                                        <?php if ($sp && $sp['status'] === 'rejected' && !empty($sp['review_note'])): ?>
                                            <div style="font-size:11px;color:var(--neg);margin-top:3px;">
                                                اترفض: <?= e($sp['review_note']) ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:10px 8px;font-weight:600;white-space:nowrap;font-size:12px;">
                                    <?= e($m['sender']) ?>
                                </td>
                                <td style="padding:10px 8px;line-height:1.4;word-break:break-word;font-size:12px;max-width:320px;" title="<?= e($m['body']) ?>">
                                    <?= e(mb_strimwidth((string) $m['body'], 0, 75, '…')) ?>
                                </td>
                                <td style="padding:10px 8px;">
                                    <input class="finput sms-note" type="text" value="<?= e($m['note']) ?>" data-saved="<?= e($m['note']) ?>"
                                           aria-label="ملاحظة الرسالة <?= (int) $m['id'] ?>" onblur="saveSmsMark(this.closest('tr'))" placeholder="اكتب ملاحظة…">
                                    <span class="sms-save-ind" aria-live="polite"></span>
                                </td>
                                <td style="padding:10px 8px;white-space:nowrap;">
                                    <div class="sms-color-dots" role="group" aria-label="لون الرسالة <?= (int) $m['id'] ?>">
                                        <?php foreach (['yellow'=>'أصفر','green'=>'أخضر','red'=>'أحمر','orange'=>'برتقالي'] as $markColor => $markLabel): ?>
                                            <button type="button" class="sms-color-dot sms-dot-<?= $markColor ?> <?= $m['color'] === $markColor ? 'active' : '' ?>"
                                                    title="<?= $markLabel ?>" aria-label="<?= $markLabel ?>" onclick="saveSmsMark(this.closest('tr'), '<?= $markColor ?>')"></button>
                                        <?php endforeach; ?>
                                        <button type="button" class="sms-color-clear" title="امسح اللون" aria-label="امسح اللون" onclick="saveSmsMark(this.closest('tr'), '')"><i class="ti ti-x"></i></button>
                                    </div>
                                </td>
                                <td style="padding:10px 8px;text-align:center;white-space:nowrap;">
                                    <span class="fchip <?= $sClass ?>">
                                        <?= e($sLabel) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div id="smsNoResults" style="display:none;text-align:center;padding:24px;color:var(--ink-400);">
                مفيش رسايل مطابقة للبحث.
            </div>

            <?php if ($hasMore): ?>
                <div style="margin-top:14px;padding:10px 14px;background:var(--warn-soft);color:var(--ink-800);border-radius:var(--r);font-size:13px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                    <span>معروض أول ٥٠٠ رسالة فقط (فيه <?= number_format($moreCount) ?> رسالة كمان في الفترة دي).</span>
                    <a href="sms_export.php?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&kind=<?= urlencode($kind) ?>" class="btn btn-sm">
                        <i class="ti ti-download"></i> نزّل كل الرسايل إكسيل
                    </a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

</div>

<script>
var CSRF = <?= json_encode(csrf_token()) ?>;

function toggleTokVis() {
    var inp = document.getElementById('hookTokInp');
    if (!inp) return;
    inp.type = inp.type === 'password' ? 'text' : 'password';
}

function copyField(elemId, successMsg) {
    var inp = document.getElementById(elemId);
    if (!inp) return;
    var prevType = inp.type;
    inp.type = 'text';
    navigator.clipboard.writeText(inp.value).then(function () {
        inp.type = prevType;
        if (typeof fgToast === 'function') {
            fgToast(successMsg, 'ok');
        } else {
            alert(successMsg);
        }
    }).catch(function () {
        inp.type = prevType;
        inp.select();
        document.execCommand('copy');
        if (typeof fgToast === 'function') {
            fgToast(successMsg, 'ok');
        } else {
            alert(successMsg);
        }
    });
}

function saveKeywords(textarea) {
    var key  = textarea.dataset.key;
    var val  = textarea.value.trim();
    var prev = textarea.dataset.saved || '';
    if (val === prev) return;

    var ind = textarea.parentElement.querySelector('.save-ind');
    if (ind) {
        ind.innerHTML = '<i class="ti ti-loader ti-spin"></i> جاري الحفظ…'; /* نص ثابت */
        ind.style.color = 'var(--warn)';
    }

    var fd = new FormData();
    fd.append('action', 'save_setting');
    fd.append('key', key);
    fd.append('value', val);
    fd.append('csrf', CSRF);

    fetch('sms_inbox.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.ok) {
                textarea.dataset.saved = val;
                if (ind) {
                    ind.textContent = '✓ تم الحفظ';
                    ind.style.color = 'var(--pos)';
                    setTimeout(function () { ind.textContent = ''; }, 2500);
                }
            } else {
                if (ind) {
                    ind.textContent = '✗ ' + (d.error || 'فشل الحفظ');
                    ind.style.color = 'var(--neg)';
                }
            }
        })
        .catch(function () {
            if (ind) {
                ind.textContent = '✗ خطأ اتصال';
                ind.style.color = 'var(--neg)';
            }
        });
}

function filterSmsTable() {
    var q = (document.getElementById('smsSearch').value || '').trim().toLowerCase();
    var color = document.getElementById('smsColorFilter').value;
    var rows = document.querySelectorAll('#smsTable tbody tr.sms-row');
    var shown = 0;
    for (var i = 0; i < rows.length; i++) {
        var s = rows[i].dataset.search || '';
        var note = rows[i].querySelector('.sms-note').value.toLowerCase();
        var matchesColor = color === 'all' || (color === 'none' ? !rows[i].dataset.color : rows[i].dataset.color === color);
        var match = matchesColor && (!q || s.indexOf(q) !== -1 || note.indexOf(q) !== -1);
        rows[i].style.display = match ? '' : 'none';
        if (match) shown++;
    }
    var noRes = document.getElementById('smsNoResults');
    if (noRes) noRes.style.display = (rows.length > 0 && shown === 0) ? '' : 'none';
}

function saveSmsMark(row, chosenColor) {
    var input = row.querySelector('.sms-note');
    var note = input.value.trim();
    var color = chosenColor === undefined ? (row.dataset.color || '') : chosenColor;
    if (note === (input.dataset.saved || '') && color === (row.dataset.color || '')) return;
    var ind = row.querySelector('.sms-save-ind');
    ind.textContent = 'جاري الحفظ…';
    var fd = new FormData();
    fd.append('action', 'save_mark');
    fd.append('sms_id', row.dataset.smsId);
    fd.append('note', note);
    fd.append('color', color);
    fd.append('csrf', CSRF);
    row._markSaving = (row._markSaving || Promise.resolve()).catch(function() {}).then(function() {
        return fetch('sms_inbox.php', {method:'POST', body:fd}).then(function(r) { return r.json(); }).then(function(d) {
            if (!d.ok) throw new Error(d.error || 'تعذّر الحفظ');
            input.dataset.saved = note;
            row.classList.remove('sms-mark-yellow', 'sms-mark-green', 'sms-mark-red', 'sms-mark-orange');
            if (color) row.classList.add('sms-mark-' + color);
            row.dataset.color = color;
            row.querySelectorAll('.sms-color-dot').forEach(function(dot) {
                dot.classList.toggle('active', dot.classList.contains('sms-dot-' + color));
            });
            ind.textContent = 'تم الحفظ';
            filterSmsTable();
        }).catch(function(err) {
            ind.textContent = err.message || 'تعذّر الحفظ';
            ind.style.color = 'var(--neg)';
        });
    });
}
</script>

<style>
#smsTable .sms-note { width:100%;box-sizing:border-box; }
#smsTable .sms-save-ind { display:block;font-size:11px;color:var(--ink-500); }
#smsTable tbody tr.sms-foreign:not([class*="sms-mark-"]) { background:var(--neg-soft); }
#smsTable tbody tr.sms-mark-red { background:var(--neg-soft);box-shadow:inset -4px 0 0 var(--neg); }
#smsTable tbody tr.sms-mark-green { background:var(--pos-soft);box-shadow:inset -4px 0 0 var(--pos); }
#smsTable tbody tr.sms-mark-yellow { background:var(--warn-soft);box-shadow:inset -4px 0 0 var(--warn); }
#smsTable tbody tr.sms-mark-orange { background:var(--warn-soft);box-shadow:inset -4px 0 0 var(--warn-fill); }
.sms-color-dots { display:flex;align-items:center;gap:5px; }
.sms-color-dot { width:20px;height:20px;border-radius:50%;border:2px solid transparent;cursor:pointer;padding:0; }
.sms-color-dot.active { border-color:var(--ink-900);box-shadow:0 0 0 2px var(--surface); }
.sms-dot-yellow { background:var(--warn); }.sms-dot-green { background:var(--pos); }
.sms-dot-red { background:var(--neg); }.sms-dot-orange { background:var(--warn-fill); }
.sms-color-clear { border:1px solid var(--line);background:var(--line-soft);color:var(--ink-700);border-radius:var(--r);cursor:pointer; }
</style>

<style>
/* Modal أذونات إنستاباي */
.modal-bg { display:none; position:fixed; inset:0; background:rgba(30,27,75,0.45);
    z-index:200; align-items:flex-start; justify-content:center; padding:30px 16px; overflow-y:auto; }
.modal-bg.open { display:flex; }
.modal { background:var(--surface); border-radius:16px; width:100%; max-width:440px; padding:26px; }
.modal h3 { color:var(--ink-900); margin-bottom:18px; font-size:18px; }
.form-row { margin-bottom:14px; }
.form-row label { display:block; font-size:13px; color:var(--ink-700); margin-bottom:6px; font-weight:500; }
.modal-actions { display:flex; gap:10px; margin-top:20px; }
.form-row .trs-pick-search { width:100%;margin-bottom:8px;box-sizing:border-box; }
.trs-pick-list { max-height:160px;overflow-y:auto;border:1.5px solid var(--line);border-radius:8px;background:var(--surface); }
.trs-pick-opt { padding:9px 12px;cursor:pointer;font-size:14px;border-bottom:1px solid var(--line-soft); }
.trs-pick-opt:hover { background:var(--bg); }
.trs-pick-chosen { margin-top:8px;font-size:13px;color:var(--pos);font-weight:600; }
</style>

<div class="modal-bg" id="spModal">
    <div class="modal">
        <h3 id="spModalTitle" style="display:flex;align-items:center;gap:8px;">
            <i class="ti ti-file-invoice"></i> سجّل إذن من الرسالة
        </h3>
        <div id="spError" class="falert falert-neg" style="display:none;"></div>
        <form id="spForm" onsubmit="handleSpSubmit(event)">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create_permit">
            <input type="hidden" name="sms_id" id="spSmsId" value="">
            <input type="hidden" name="permit_action" id="spPermitAction" value="receipt">

            <div class="form-row">
                <label for="spActionSelect">نوع الإذن</label>
                <select class="fselect" id="spActionSelect" onchange="spChangeAction(this.value)">
                    <option value="receipt">إذن استلام (نقدية داخلة)</option>
                    <option value="disbursement">إذن صرف (نقدية خارجة)</option>
                </select>
            </div>

            <!-- نوع العملية -->
            <div class="form-row" style="background:var(--line-soft);padding:12px;border-radius:10px;border:1.5px solid var(--line);">
                <label for="spOpType">تصنيف العملية</label>
                <select class="fselect" name="op_type" id="spOpType" onchange="spToggleOpType()">
                    <option value="normal">عادي</option>
                    <option value="supplier">مورد</option>
                    <option value="advance">سلفة موظف</option>
                    <option value="custody">عهدة فرع</option>
                    <option value="employee_custody" id="spOptEmpCustody" style="display:none;">عهدة موظف</option>
                </select>

                <!-- مورد -->
                <div id="spSupplierBox" style="display:none;margin-top:12px;">
                    <input type="text" id="spSuppSearch" class="finput trs-pick-search" placeholder="ابحث باسم المورد..."
                           data-picker-search="#spSuppList" data-item=".sp-supp-opt" data-empty="مفيش مورد بالاسم ده"
                           oninput="filterSpSupps()" autocomplete="off" aria-label="ابحث باسم المورد...">
                    <div id="spSuppList" class="trs-pick-list">
                        <?php foreach ($suppliers as $s): ?>
                            <div class="sp-supp-opt trs-pick-opt" data-id="<?= (int)$s['id'] ?>" data-name="<?= e($s['name']) ?>"
                                 onclick="pickSpSupp(this)">
                                <?= e($s['name']) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="supplier_id" id="spSuppId" value="">
                    <div id="spSuppChosen" class="trs-pick-chosen"></div>
                </div>

                <!-- سلفة أو عهدة موظف -->
                <div id="spAdvanceBox" style="display:none;margin-top:12px;">
                    <input type="text" id="spEmpSearch" class="finput trs-pick-search" placeholder="ابحث باسم الموظف..."
                           oninput="filterSpEmps()" autocomplete="off" aria-label="ابحث باسم الموظف...">
                    <div id="spEmpList" class="trs-pick-list">
                        <?php foreach ($employees as $emp): ?>
                            <div class="sp-emp-opt trs-pick-opt" data-id="<?= (int)$emp['id'] ?>" data-name="<?= e($emp['full_name']) ?>"
                                 onclick="pickSpEmp(this)">
                                <?= e($emp['full_name']) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="employee_id" id="spEmpId" value="">
                    <div id="spEmpChosen" class="trs-pick-chosen"></div>
                </div>

                <!-- عهدة فرع -->
                <div id="spCustodyBox" style="display:none;margin-top:12px;">
                    <label for="spCustodyBranch">الفرع</label>
                    <select class="fselect" name="branch_id" id="spCustodyBranch">
                        <option value="">— اختر الفرع —</option>
                        <?php foreach ($branchesForCustody as $b): ?>
                            <option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <label for="spTreasury">الخزينة</label>
                <select class="fselect" name="treasury_id" id="spTreasury" required>
                    <?php foreach ($allTreasuries as $t): ?>
                        <option value="<?= (int)$t['id'] ?>"
                                data-type="<?= e($t['type']) ?>"
                                data-receipt="<?= (int)($t['show_in_receipts'] ?? 1) ?>"
                                <?= (int)$t['id'] === $instapayTreasuryId ? 'selected' : '' ?>>
                            <?= e($t['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row" id="spAccountRow">
                <label>القيد</label>
                <?= acctree_field('entry_code_id', null, '— بدون قيد —', 'all', 'spEntryCode') ?>
            </div>

            <div class="form-row" id="spCostBranchRow">
                <label for="spCostBranch">اسم الفرع</label>
                <select class="fselect" name="cost_branch_id" id="spCostBranch">
                    <option value="">— بدون تحديد —</option>
                    <?php foreach ($costBranches as $cb): ?>
                        <option value="<?= (int)$cb['id'] ?>"><?= e($cb['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <label for="spAmount">المبلغ</label>
                <input type="text" class="finput" inputmode="decimal" data-money name="amount" id="spAmount" required
                       autocomplete="off" placeholder="0" aria-label="المبلغ">
            </div>

            <div class="form-row">
                <label for="spDate">التاريخ</label>
                <input type="date" class="finput" name="date" id="spDate" required>
            </div>

            <div class="form-row">
                <label for="spDesc">البيان</label>
                <input type="text" class="finput" name="description" id="spDesc" placeholder="البيان" aria-label="البيان">
            </div>

            <div class="form-row">
                <label for="spNotes">ملاحظات <span style="color:var(--ink-400);font-weight:400;">(اختياري)</span></label>
                <textarea class="ftextarea" name="notes" id="spNotes" rows="2" placeholder="شرح تفصيلي للعملية…" aria-label="ملاحظات"></textarea>
            </div>

            <div class="modal-actions">
                <button type="submit" class="btn" id="spSubmitBtn">حفظ الإذن</button>
                <button type="button" class="btn btn-cancel" onclick="closePermitModal()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<?= acctree_data() ?>
<script src="assets/acctree.js?v=186"></script>

<script>
var defaultInstapayTreasuryId = <?= (int) $instapayTreasuryId ?>;

function openPermitModal(msg) {
    document.getElementById('spError').style.display = 'none';
    document.getElementById('spError').textContent = '';
    document.getElementById('spSmsId').value = msg.id;

    var act = (msg.kind === 'in') ? 'receipt' : 'disbursement';
    document.getElementById('spActionSelect').value = act;
    spChangeAction(act);

    // المبلغ
    document.getElementById('spAmount').value = (msg.amount > 0) ? msg.amount : '';
    // التاريخ
    document.getElementById('spDate').value = msg.date || '<?= date('Y-m-d') ?>';
    // الخزينة الافتراضية: إنستاباي
    var trSelect = document.getElementById('spTreasury');
    if (defaultInstapayTreasuryId) {
        trSelect.value = defaultInstapayTreasuryId;
    }
    // البيان
    var desc = '';
    if (msg.kind === 'in') {
        desc = msg.party ? ('داخل من ' + msg.party) : 'تحويل داخل';
    } else if (msg.kind === 'out') {
        desc = msg.party ? ('خارج إلى ' + msg.party) : 'تحويل خارج';
    } else if (msg.kind === 'card') {
        desc = msg.party ? ('خصم كارت ' + msg.party) : 'خصم كارت';
    }
    document.getElementById('spDesc').value = desc;
    document.getElementById('spNotes').value = '';

    // نوع العملية: عادي
    document.getElementById('spOpType').value = 'normal';
    spToggleOpType();

    // تفريغ المورد والموظف والفرع
    document.getElementById('spSuppId').value = '';
    document.getElementById('spSuppChosen').textContent = '';
    document.getElementById('spEmpId').value = '';
    document.getElementById('spEmpChosen').textContent = '';
    document.getElementById('spCustodyBranch').value = '';
    document.getElementById('spCostBranch').value = '';

    // تصفير القيد
    if (typeof acctreeSet === 'function') {
        acctreeSet(document.getElementById('spEntryCode'), '');
    }

    document.getElementById('spModal').classList.add('open');
    document.getElementById('spActionSelect').focus();
}

function closePermitModal() {
    document.getElementById('spModal').classList.remove('open');
}

function spChangeAction(act) {
    document.getElementById('spPermitAction').value = act;
    var trSelect = document.getElementById('spTreasury');
    var opts = trSelect.options;
    for (var i = 0; i < opts.length; i++) {
        var opt = opts[i];
        var isCash = opt.getAttribute('data-type') === 'cash';
        var showInRec = parseInt(opt.getAttribute('data-receipt') || '1', 10);
        if (act === 'receipt') {
            opt.style.display = showInRec ? '' : 'none';
        } else {
            opt.style.display = isCash ? 'none' : '';
        }
    }

    var opSelect = document.getElementById('spOpType');
    var optEmpC = document.getElementById('spOptEmpCustody');
    if (act === 'receipt') {
        opSelect.options[0].text = 'استلام عادي';
        opSelect.options[1].text = 'استلام من مورد';
        opSelect.options[2].text = 'رد سلفة من موظف';
        opSelect.options[3].text = 'استلام عهدة من فرع';
        if (optEmpC) optEmpC.style.display = 'none';
        if (opSelect.value === 'employee_custody') opSelect.value = 'normal';
        document.getElementById('spSubmitBtn').textContent = 'حفظ الإذن (استلام)';
    } else {
        opSelect.options[0].text = 'صرف عادي';
        opSelect.options[1].text = 'صرف لمورد';
        opSelect.options[2].text = 'سلفة لموظف';
        opSelect.options[3].text = 'عهدة لفرع';
        if (optEmpC) optEmpC.style.display = '';
        document.getElementById('spSubmitBtn').textContent = 'حفظ الإذن (صرف)';
    }
    spToggleOpType();
}

function spToggleOpType() {
    var v = document.getElementById('spOpType').value;
    var accRow = document.getElementById('spAccountRow');
    var costRow = document.getElementById('spCostBranchRow');
    var suppBox = document.getElementById('spSupplierBox');
    var advBox = document.getElementById('spAdvanceBox');
    var cstBox = document.getElementById('spCustodyBox');

    if (v === 'normal') {
        accRow.style.display = '';
        costRow.style.display = '';
        suppBox.style.display = 'none';
        advBox.style.display = 'none';
        cstBox.style.display = 'none';
    } else if (v === 'supplier') {
        accRow.style.display = 'none';
        costRow.style.display = '';
        suppBox.style.display = '';
        advBox.style.display = 'none';
        cstBox.style.display = 'none';
    } else if (v === 'advance' || v === 'employee_custody') {
        accRow.style.display = 'none';
        costRow.style.display = 'none';
        suppBox.style.display = 'none';
        advBox.style.display = '';
        cstBox.style.display = 'none';
    } else if (v === 'custody') {
        accRow.style.display = 'none';
        costRow.style.display = 'none';
        suppBox.style.display = 'none';
        advBox.style.display = 'none';
        cstBox.style.display = '';
    }
}

function filterSpSupps() {
    var input = document.getElementById('spSuppSearch');
    if (input && input._fgFilter) { input._fgFilter(); return; }
    var q = window.fgNorm ? window.fgNorm(input ? input.value : '') : (input ? input.value : '').trim().toLowerCase();
    var items = document.querySelectorAll('.sp-supp-opt');
    for (var i = 0; i < items.length; i++) {
        var n = window.fgNorm ? window.fgNorm(items[i].dataset.name || '') : (items[i].dataset.name || '').toLowerCase();
        items[i].style.display = (!q || n.indexOf(q) !== -1) ? '' : 'none';
    }
}

function pickSpSupp(el) {
    document.getElementById('spSuppId').value = el.dataset.id;
    document.getElementById('spSuppChosen').textContent = 'تم اختيار: ' + el.dataset.name;
    document.getElementById('spSuppSearch').value = '';
    filterSpSupps();
}

function filterSpEmps() {
    var q = (document.getElementById('spEmpSearch').value || '').trim().toLowerCase();
    var items = document.querySelectorAll('.sp-emp-opt');
    for (var i = 0; i < items.length; i++) {
        var n = items[i].dataset.name.toLowerCase();
        items[i].style.display = (!q || n.indexOf(q) !== -1) ? '' : 'none';
    }
}

function pickSpEmp(el) {
    document.getElementById('spEmpId').value = el.dataset.id;
    document.getElementById('spEmpChosen').textContent = 'تم اختيار: ' + el.dataset.name;
    document.getElementById('spEmpSearch').value = '';
    filterSpEmps();
}

function handleSpSubmit(e) {
    e.preventDefault();
    var btn = document.getElementById('spSubmitBtn');
    btn.disabled = true;
    var errBox = document.getElementById('spError');
    errBox.style.display = 'none';
    errBox.textContent = '';

    var form = document.getElementById('spForm');
    var fd = new FormData(form);

    fetch('sms_inbox.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            btn.disabled = false;
            if (d.ok) {
                closePermitModal();
                var smsId = fd.get('sms_id');
                var cell = document.getElementById('spCell_' + smsId);
                if (cell) {
                    cell.innerHTML = '<span style="display:inline-block;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:600;background:var(--warn-soft);color:var(--warn);">مستني المراجعة</span>';
                }
                if (typeof fgToast === 'function') {
                    fgToast('تم تسجيل الإذن ومستني مراجعة المحاسب', 'ok');
                } else {
                    alert('تم تسجيل الإذن ومستني مراجعة المحاسب');
                }
            } else {
                errBox.textContent = d.error || 'فشل حفظ الإذن.';
                errBox.style.display = '';
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            errBox.textContent = 'خطأ اتصال أثناء حفظ الإذن.';
            errBox.style.display = '';
        });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
