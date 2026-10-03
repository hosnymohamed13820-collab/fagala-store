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
require_login();

if (!is_owner()) {
    http_response_code(403);
    echo '<div style="font-family:sans-serif;text-align:center;padding:50px 20px;">'
        . '<h2>غير مصرح لك</h2>'
        . '<p style="color:#666;">الصفحة دي متاحة لمالك النظام فقط.</p>'
        . '<a href="dashboard.php" style="color:#1e6fbf;">الرجوع للرئيسية</a>'
        . '</div>';
    exit;
}

require_once __DIR__ . '/sync_tokens.php';
$pdo = db();

// معالجة حفظ كلمات الفلتر عبر AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'انتهت صلاحية الجلسة، اعمل تحديث.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'save_setting') {
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

// (١) جلب إحصائيات الرسايل
$statsSt = $pdo->query(
    "SELECT
        COUNT(*) AS total_count,
        MAX(received_at) AS last_received,
        SUM(CASE WHEN source = 'backfill' THEN 1 ELSE 0 END) AS backfill_count,
        SUM(CASE WHEN source = 'live' THEN 1 ELSE 0 END) AS live_count
     FROM sms_inbox"
);
$stats = $statsSt->fetch(PDO::FETCH_ASSOC) ?: [];
$totalCount    = (int) ($stats['total_count'] ?? 0);
$lastReceived  = $stats['last_received'] ? (string) $stats['last_received'] : 'لا يوجد بعد';
$backfillCount = (int) ($stats['backfill_count'] ?? 0);
$liveCount     = (int) ($stats['live_count'] ?? 0);

// (٢) جلب إعدادات كلمات الفلتر
$setSt = $pdo->query(
    "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('sms_include_keywords', 'sms_exclude_keywords')"
);
$settingsMap = [];
while ($row = $setSt->fetch(PDO::FETCH_ASSOC)) {
    $settingsMap[(string) $row['setting_key']] = (string) $row['setting_value'];
}
$includeKw = $settingsMap['sms_include_keywords'] ?? '';
$excludeKw = $settingsMap['sms_exclude_keywords'] ?? '';

require_once __DIR__ . '/sms_lib.php';

// (٣) معالجة الفلتر وجلب الرسايل المحللة
$from = trim((string) ($_GET['from'] ?? ''));
$to   = trim((string) ($_GET['to'] ?? ''));
$kind = trim((string) ($_GET['kind'] ?? 'all'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = date('Y-m-d', strtotime('-30 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = date('Y-m-d');
}

$allMessages = sms_fetch($pdo, $from, $to, $kind);

// إجماليات الكروت على كامل النتائج في الفترة
$totInCount   = 0;
$totInAmount  = 0.0;
$totOutCount  = 0;
$totOutAmount = 0.0;
$unknownCount = 0;

foreach ($allMessages as $m) {
    $k = $m['kind'];
    if ($k === 'in') {
        $totInCount++;
        $totInAmount += (float) $m['amount'];
    } elseif ($k === 'out' || $k === 'card') {
        $totOutCount++;
        $totOutAmount += (float) $m['amount'];
    } else {
        $unknownCount++;
    }
}
$netAmount = $totInAmount - $totOutAmount;

// تحديد المعروض في الجدول (أول ٥٠٠ صف فقط)
$totalCountFiltered = count($allMessages);
$messages = array_slice($allMessages, 0, 500);
$hasMore = $totalCountFiltered > 500;
$moreCount = $totalCountFiltered - 500;


// (٤) بيانات الربط
$hookUrl   = 'https://ashraqa.hosnimanagement.com/sms_hook.php';
$hookToken = (string) (SYNC_AGENT_TOKENS['sms_phone'] ?? '');

$page_title    = 'رسايل إنستاباي — إشراقة';
$active_module = 'sms_inbox';
require_once __DIR__ . '/includes/header.php';
?>

<div class="content-container">

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
        <h2 style="margin:0;font-size:22px;display:flex;align-items:center;gap:8px;">
            <i class="ti ti-device-mobile" style="color:var(--accent);"></i> رسايل إنستاباي — إشراقة
        </h2>
        <a href="sms_inbox.php" class="btn btn-ghost"><i class="ti ti-refresh"></i> تحديث</a>
    </div>

    <!-- (١) كروت الحالة -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:14px;margin-bottom:24px;">
        <div class="card" style="padding:16px;">
            <div style="font-size:12px;color:var(--ink-500);margin-bottom:6px;">إجمالي الرسايل</div>
            <div style="font-size:24px;font-weight:700;color:var(--accent-text);"><?= number_format($totalCount) ?></div>
        </div>
        <div class="card" style="padding:16px;">
            <div style="font-size:12px;color:var(--ink-500);margin-bottom:6px;">رسايل جديدة (مباشرة)</div>
            <div style="font-size:24px;font-weight:700;color:var(--pos);"><?= number_format($liveCount) ?></div>
        </div>
        <div class="card" style="padding:16px;">
            <div style="font-size:12px;color:var(--ink-500);margin-bottom:6px;">رسايل قديمة (سحب)</div>
            <div style="font-size:24px;font-weight:700;color:var(--ink-700);"><?= number_format($backfillCount) ?></div>
        </div>
        <div class="card" style="padding:16px;">
            <div style="font-size:12px;color:var(--ink-500);margin-bottom:6px;">آخر رسالة وصلت</div>
            <div style="font-size:15px;font-weight:600;color:var(--ink-800);direction:ltr;text-align:right;"><?= e($lastReceived) ?></div>
        </div>
    </div>

    <!-- (٢) بيانات ربط التطبيق -->
    <div class="card" style="padding:18px;margin-bottom:24px;background:var(--surface);">
        <h3 style="margin:0 0 12px 0;font-size:15px;display:flex;align-items:center;gap:6px;">
            <i class="ti ti-device-mobile"></i> إعدادات الربط في تطبيق الأندرويد
        </h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(300px, 1fr));gap:16px;">
            <div>
                <label style="display:block;font-size:12px;color:var(--ink-500);margin-bottom:6px;">رابط السيرفر (Webhook URL)</label>
                <div style="display:flex;gap:6px;">
                    <input type="text" id="hookUrlInp" value="<?= e($hookUrl) ?>" readonly
                           style="width:100%;direction:ltr;background:var(--bg);border:1px solid var(--line);border-radius:var(--r);padding:8px 10px;font-family:monospace;font-size:13px;">
                    <button type="button" class="btn btn-ghost" onclick="copyField('hookUrlInp', 'تم نسخ الرابط')" title="نسخ الرابط">
                        <i class="ti ti-copy"></i>
                    </button>
                </div>
            </div>
            <div>
                <label style="display:block;font-size:12px;color:var(--ink-500);margin-bottom:6px;">توكن الحماية (Secret Token)</label>
                <div style="display:flex;gap:6px;">
                    <input type="password" id="hookTokInp" value="<?= e($hookToken) ?>" readonly
                           style="width:100%;direction:ltr;background:var(--bg);border:1px solid var(--line);border-radius:var(--r);padding:8px 10px;font-family:monospace;font-size:13px;">
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

    <!-- (٣) كلمات الفلتر -->
    <div class="card" style="padding:18px;margin-bottom:24px;">
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
                <textarea class="kw-textarea" data-key="sms_include_keywords" rows="7"
                          data-saved="<?= e($includeKw) ?>" onblur="saveKeywords(this)"
                          style="width:100%;box-sizing:border-box;padding:10px;border:1px solid var(--line);border-radius:var(--r);font-family:inherit;font-size:13px;line-height:1.5;background:var(--bg);"><?= e($includeKw) ?></textarea>
                <div style="font-size:11px;color:var(--ink-400);margin-top:4px;">
                    الرسالة اللي فيها أي كلمة من دول في الراسل أو النص بتتبعت للموقع.
                </div>
            </div>

            <div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                    <label style="font-weight:600;font-size:13px;color:var(--neg);">كلمات الاستبعاد (تمنع الإرسال فوراً)</label>
                    <span class="save-ind" id="ind_exclude" style="font-size:12px;font-weight:600;"></span>
                </div>
                <textarea class="kw-textarea" data-key="sms_exclude_keywords" rows="7"
                          data-saved="<?= e($excludeKw) ?>" onblur="saveKeywords(this)"
                          style="width:100%;box-sizing:border-box;padding:10px;border:1px solid var(--line);border-radius:var(--r);font-family:inherit;font-size:13px;line-height:1.5;background:var(--bg);"><?= e($excludeKw) ?></textarea>
                <div style="font-size:11px;color:var(--ink-400);margin-top:4px;">
                    الرسالة اللي فيها أي كلمة من دول (أكواد التحقق وOTP) مابتتبعتش خالص حتى لو فيها كلمة تضمين.
                </div>
            </div>
        </div>
    </div>

    <!-- (٤) كروت ملخص الفترة والفلتر وجدول الرسايل -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:14px;margin-bottom:20px;">
        <div class="card" style="padding:14px;border-top:3px solid var(--pos);">
            <div style="font-size:12px;color:var(--ink-500);margin-bottom:4px;">إجمالي الداخل</div>
            <div style="font-size:20px;font-weight:700;color:var(--pos);"><?= number_format($totInAmount, 2) ?> <span style="font-size:13px;font-weight:400;">جم</span></div>
            <div style="font-size:12px;color:var(--ink-400);margin-top:2px;"><?= number_format($totInCount) ?> عملية</div>
        </div>
        <div class="card" style="padding:14px;border-top:3px solid var(--neg);">
            <div style="font-size:12px;color:var(--ink-500);margin-bottom:4px;">إجمالي الخارج (تحويلات + كارت)</div>
            <div style="font-size:20px;font-weight:700;color:var(--neg);"><?= number_format($totOutAmount, 2) ?> <span style="font-size:13px;font-weight:400;">جم</span></div>
            <div style="font-size:12px;color:var(--ink-400);margin-top:2px;"><?= number_format($totOutCount) ?> عملية</div>
        </div>
        <div class="card" style="padding:14px;border-top:3px solid <?= $netAmount >= 0 ? 'var(--pos)' : 'var(--neg)' ?>;">
            <div style="font-size:12px;color:var(--ink-500);margin-bottom:4px;">الصافي</div>
            <div style="font-size:20px;font-weight:700;color:<?= $netAmount >= 0 ? 'var(--pos)' : 'var(--neg)' ?>;">
                <?= ($netAmount < 0 ? '−' : '') . number_format(abs($netAmount), 2) ?> <span style="font-size:13px;font-weight:400;">جم</span>
            </div>
            <div style="font-size:12px;color:var(--ink-400);margin-top:2px;">داخل − خارج</div>
        </div>
        <div class="card" style="padding:14px;border-top:3px solid var(--ink-400);">
            <div style="font-size:12px;color:var(--ink-500);margin-bottom:4px;">مش معروف</div>
            <div style="font-size:20px;font-weight:700;color:var(--ink-600);"><?= number_format($unknownCount) ?></div>
            <div style="font-size:12px;color:var(--ink-400);margin-top:2px;">رسائل خارج النمط</div>
        </div>
    </div>

    <div class="card" style="padding:18px;">
        <!-- شريط الفلتر والبحث والتصدير -->
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px;">
            <form method="GET" action="sms_inbox.php" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin:0;">
                <div>
                    <label style="display:block;font-size:11px;color:var(--ink-500);margin-bottom:4px;">من تاريخ</label>
                    <input type="date" name="from" value="<?= e($from) ?>" style="padding:6px 10px;border:1px solid var(--line);border-radius:var(--r);font-size:13px;background:var(--bg);">
                </div>
                <div>
                    <label style="display:block;font-size:11px;color:var(--ink-500);margin-bottom:4px;">إلى تاريخ</label>
                    <input type="date" name="to" value="<?= e($to) ?>" style="padding:6px 10px;border:1px solid var(--line);border-radius:var(--r);font-size:13px;background:var(--bg);">
                </div>
                <div>
                    <label style="display:block;font-size:11px;color:var(--ink-500);margin-bottom:4px;">النوع</label>
                    <select name="kind" style="padding:6px 10px;border:1px solid var(--line);border-radius:var(--r);font-size:13px;background:var(--bg);">
                        <option value="all" <?= $kind === 'all' ? 'selected' : '' ?>>الكل</option>
                        <option value="in" <?= $kind === 'in' ? 'selected' : '' ?>>داخل</option>
                        <option value="out" <?= $kind === 'out' ? 'selected' : '' ?>>خارج</option>
                        <option value="card" <?= $kind === 'card' ? 'selected' : '' ?>>كارت</option>
                        <option value="unknown" <?= $kind === 'unknown' ? 'selected' : '' ?>>مش معروف</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-sm"><i class="ti ti-search"></i> تصفية</button>
                <a href="sms_export.php?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&kind=<?= urlencode($kind) ?>" class="btn btn-sm">
                    <i class="ti ti-download"></i> نزّل إكسيل
                </a>
            </form>

            <div style="position:relative;width:240px;max-width:100%;">
                <i class="ti ti-search" style="position:absolute;top:9px;right:10px;color:var(--ink-400);"></i>
                <input type="text" id="smsSearch" placeholder="بحث سريع في النتائج…"
                       oninput="filterSmsTable()"
                       style="width:100%;box-sizing:border-box;padding:7px 32px 7px 10px;border:1px solid var(--line);border-radius:var(--r);font-size:13px;background:var(--bg);">
            </div>
        </div>

        <?php if (empty($messages)): ?>
            <div style="text-align:center;padding:40px 10px;color:var(--ink-400);">
                <i class="ti ti-inbox" style="font-size:36px;display:block;margin-bottom:8px;"></i>
                مفيش أي رسالة في الفترة أو النوع المختار.
            </div>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table id="smsTable" style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead>
                        <tr style="border-bottom:2px solid var(--line);text-align:right;">
                            <th style="padding:10px 8px;width:140px;">التاريخ والوقت</th>
                            <th style="padding:10px 8px;width:80px;text-align:center;">النوع</th>
                            <th style="padding:10px 8px;width:100px;text-align:left;">المبلغ</th>
                            <th style="padding:10px 8px;">الطرف</th>
                            <th style="padding:10px 8px;width:120px;">الرقم المرجعي</th>
                            <th style="padding:10px 8px;width:90px;text-align:left;">المتاح</th>
                            <th style="padding:10px 8px;width:110px;">الراسل</th>
                            <th style="padding:10px 8px;">نص الرسالة</th>
                            <th style="padding:10px 8px;width:70px;text-align:center;">المصدر</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($messages as $m): ?>
                            <?php
                                $sClass = $m['source'] === 'live' ? 'background:var(--pos-soft);color:var(--pos);' : 'background:var(--ink-100);color:var(--ink-700);';
                                $sLabel = $m['source'] === 'live' ? 'جديدة' : 'قديمة';
                            ?>
                            <tr class="sms-row" style="border-bottom:1px solid var(--line);"
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
                                            echo '<span style="display:inline-block;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:600;background:var(--pos-soft);color:var(--pos);">داخل</span>';
                                        } elseif ($m['kind'] === 'out') {
                                            echo '<span style="display:inline-block;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:600;background:var(--neg-soft);color:var(--neg);">خارج</span>';
                                        } elseif ($m['kind'] === 'card') {
                                            echo '<span style="display:inline-block;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:600;background:var(--neg-soft);color:var(--neg);">كارت</span>';
                                        } else {
                                            echo '<span style="display:inline-block;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:600;background:var(--ink-100);color:var(--ink-500);">مش معروف</span>';
                                        }
                                    ?>
                                </td>
                                <td style="padding:10px 8px;white-space:nowrap;direction:ltr;text-align:left;">
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
                                <td style="padding:10px 8px;white-space:nowrap;direction:ltr;text-align:right;font-family:monospace;font-size:12px;">
                                    <?= e($m['ref'] ?? '—') ?>
                                </td>
                                <td style="padding:10px 8px;white-space:nowrap;direction:ltr;text-align:left;font-size:12px;">
                                    <?= $m['available'] !== null ? number_format((float) $m['available'], 2) : '<span style="color:var(--ink-400);">—</span>' ?>
                                </td>
                                <td style="padding:10px 8px;font-weight:600;white-space:nowrap;font-size:12px;">
                                    <?= e($m['sender']) ?>
                                </td>
                                <td style="padding:10px 8px;line-height:1.4;word-break:break-word;font-size:12px;max-width:320px;" title="<?= e($m['body']) ?>">
                                    <?= e(mb_strimwidth((string) $m['body'], 0, 75, '…')) ?>
                                </td>
                                <td style="padding:10px 8px;text-align:center;white-space:nowrap;">
                                    <span style="display:inline-block;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:600;<?= $sClass ?>">
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
    var rows = document.querySelectorAll('#smsTable tbody tr.sms-row');
    var shown = 0;
    for (var i = 0; i < rows.length; i++) {
        var s = rows[i].dataset.search || '';
        var match = !q || s.indexOf(q) !== -1;
        rows[i].style.display = match ? '' : 'none';
        if (match) shown++;
    }
    var noRes = document.getElementById('smsNoResults');
    if (noRes) noRes.style.display = (rows.length > 0 && shown === 0) ? '' : 'none';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
