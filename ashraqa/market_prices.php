<?php
/**
 * market_prices.php — أسعار السوق (تاب ٤ في موديول النواقص)
 * نظام الفجالة ستور المالي — 2026-08-11
 *
 * =====================================================================
 *  الشاشة دي مهمتها حاجة واحدة: **الربط**.
 *
 *  بتسحب أسعار متجر منافس (WooCommerce Store API)، وبتقترح لكل كتاب
 *  عندنا أقرب منتج عندهم بدرجة تشابه. إنت بتأكّد بضغطة، والتأكيد
 *  بيتخزّن للأبد — فالمقارنة بعد كده مضبوطة ١٠٠٪ ومفيش تخمين.
 *
 *  المقارنة نفسها (عمود «سعر السوق» في تاب أسعار البيع) هي المرحلة
 *  اللي بعد دي — مابنعملهاش قبل ما نشوف نسبة الربط الحقيقية.
 * =====================================================================
 */
require_once __DIR__ . '/auth.php';
require_permission('shortage');
require_once __DIR__ . '/book_lib.php';
require_once __DIR__ . '/market_lib.php';

$uid = current_user()['id'] ?? null;

// ===================== AJAX =====================
$ajax = $_GET['ajax'] ?? '';
if ($ajax !== '') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!mk_ready()) {
            throw new RuntimeException('الجداول لسه ما اتعملتش — شغّل upgrade_market_prices.sql.');
        }

        $body = static function (): array {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('طلب غير صالح.');
            $b = json_decode(file_get_contents('php://input') ?: '', true);
            if (!is_array($b)) throw new RuntimeException('بيانات غير صالحة.');
            if (!csrf_verify((string) ($b['csrf'] ?? ''))) {
                throw new RuntimeException('انتهت صلاحية الجلسة — اعمل ريفريش.');
            }
            return $b;
        };

        switch ($ajax) {
            case 'fetch':
                $body();
                $res = mk_fetch_all($uid);
                if (!$res['ok']) throw new RuntimeException((string) $res['error']);
                echo json_encode(['ok' => true, 'products' => $res['products']], JSON_UNESCAPED_UNICODE);
                break;

            case 'link':
                $b = $body();
                mk_link((int) ($b['item_id'] ?? 0), (int) ($b['market_id'] ?? 0),
                        isset($b['score']) ? (float) $b['score'] : null, $uid);
                echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
                break;

            case 'unlink':
                $b = $body();
                mk_unlink((int) ($b['item_id'] ?? 0));
                echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
                break;

            case 'ignore':
                $b = $body();
                mk_ignore((int) ($b['item_id'] ?? 0), $uid);
                echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
                break;

            case 'unignore':
                $b = $body();
                mk_unignore((int) ($b['item_id'] ?? 0));
                echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
                break;

            case 'bulk_preview':
                $r = mk_bulk_link(MARKET_STRONG, $uid, true);
                echo json_encode(['ok' => true] + $r, JSON_UNESCAPED_UNICODE);
                break;

            case 'bulk_link':
                $b = $body();
                $mark = date('Y-m-d H:i:s');
                $r = mk_bulk_link(MARKET_STRONG, $uid, false);
                echo json_encode(['ok' => true, 'mark' => $mark] + $r, JSON_UNESCAPED_UNICODE);
                break;

            case 'bulk_undo':
                $b = $body();
                $since = (string) ($b['since'] ?? '');
                if ($since === '') throw new RuntimeException('مفيش وقت للتراجع.');
                $n = mk_bulk_unlink_since($since);
                echo json_encode(['ok' => true, 'removed' => $n], JSON_UNESCAPED_UNICODE);
                break;

            case 'renorm':
                $body();
                $n = mk_renorm_all();
                echo json_encode(['ok' => true, 'updated' => $n], JSON_UNESCAPED_UNICODE);
                break;

            case 'search':
                echo json_encode([
                    'ok' => true,
                    'rows' => mk_search_products((string) ($_GET['term'] ?? ''), 20),
                ], JSON_UNESCAPED_UNICODE);
                break;

            default:
                throw new RuntimeException('عملية غير معروفة.');
        }
    } catch (Throwable $ex) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ===================== البيانات =====================
$ready  = mk_ready();
$stats  = mk_stats();
$last   = mk_last_fetch();
$cool   = $ready ? mk_cooldown() : 0;

$filter = $_GET['f']    ?? 'unlinked';
$term   = trim((string) ($_GET['q'] ?? ''));
$page   = max(1, (int) ($_GET['p'] ?? 1));
$per    = 40;

$books = ['rows' => [], 'total' => 0];
$sugg  = [];
$prods = [];

if ($ready && $stats['products'] > 0) {
    $books = mk_our_books($filter, $term, $per, ($page - 1) * $per);
    if ($books['rows']) {
        $prods = mk_load_products();
        $sugg  = mk_suggest($books['rows'], $prods, 3);
    }
}
$pages = max(1, (int) ceil($books['total'] / $per));

$page_title    = 'أسعار السوق';
$active_module = 'shortage';
require_once __DIR__ . '/includes/header.php';
?>

<?= book_tabs_bar('market') ?>

<style>
    .mk-head { background:var(--surface); border-radius:12px; padding:16px; margin-bottom:14px;
        display:flex; gap:12px; align-items:center; flex-wrap:wrap;
        box-shadow:0 2px 10px rgba(124,111,247,0.06); }
    .mk-stat { font-size:13px; color:var(--ink-500); }
    .mk-stat b { color:var(--ink-900); }
    .mk-filters { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:12px; }
    .mk-f { padding:8px 15px; border-radius:9px; background:var(--surface); border:1.5px solid var(--line);
        color:var(--ink-700,var(--accent-text)); font-size:13px; text-decoration:none; }
    .mk-f.on { background:var(--accent); border-color:var(--accent-text); color:#fff; }
    .mk-card { background:var(--surface); border-radius:12px; padding:14px 16px; margin-bottom:10px;
        box-shadow:0 1px 6px rgba(124,111,247,0.06); }
    .mk-our { font-size:14px; font-weight:600; color:var(--ink-900); }
    .mk-sub { font-size:12px; color:var(--ink-500); margin-top:3px; }
    .mk-sugg { margin-top:10px; padding-top:10px; border-top:1px dashed var(--line); }
    .mk-opt { display:flex; gap:10px; align-items:center; flex-wrap:wrap;
        padding:7px 0; font-size:13px; }
    .mk-badge { padding:2px 9px; border-radius:20px; font-size:11px; font-weight:700; color:#fff; }
    .mk-strong { background:var(--pos-fill); }
    .mk-maybe  { background:var(--warn-fill); }
    .mk-weak   { background:var(--ink-200); }
    .mk-linked { background:var(--line-soft); border:1.5px solid var(--pos); }
    .mk-ign    { opacity:.55; }
    .btn { background:var(--accent); color:#fff; border:none; padding:11px 20px; border-radius:10px;
        font-size:14px; font-family:inherit; cursor:pointer; display:inline-flex; align-items:center; gap:7px; text-decoration:none; }
    .btn:hover { background:var(--accent-hover); }
    .btn[disabled] { opacity:.5; cursor:not-allowed; }
    .btn-sm { padding:6px 13px; font-size:12px; border-radius:8px; border:none; cursor:pointer;
        font-family:inherit; background:var(--accent); color:#fff; }
    .btn-sm.ghost { background:var(--surface); border:1.5px solid var(--line); color:var(--ink-700,var(--accent-text)); }
    .btn-sm[disabled] { opacity:.5; cursor:not-allowed; }
    .mk-search { padding:9px 11px; border:1.5px solid var(--line); border-radius:9px;
        font-family:inherit; font-size:13px; }
    .mk-manual { margin-top:8px; display:none; }
    .mk-mres div { padding:7px 10px; border-bottom:1px solid var(--line-soft); cursor:pointer; font-size:13px; }
    .mk-mres div:hover { background:var(--bg); }
    .mk-pager { display:flex; gap:8px; align-items:center; justify-content:center; margin:16px 0; }
</style>

<?php if (!$ready): ?>
    <div class="mk-card" style="color:var(--neg);text-align:center;padding:30px;">
        الجداول لسه ما اتعملتش — شغّل <b>upgrade_market_prices.sql</b> الأول.
    </div>
<?php else: ?>

<div class="mk-head">
    <button class="btn" id="mkFetch" <?= $cool > 0 ? 'disabled' : '' ?>>
        <i class="ti ti-refresh"></i> حدّث أسعار السوق
    </button>
    <span class="mk-stat">
        منتجات محفوظة: <b><?= number_format($stats['products']) ?></b> ·
        مربوط: <b><?= number_format($stats['linked']) ?></b> ·
        مستبعد: <b><?= number_format($stats['ignored']) ?></b>
    </span>
    <span class="mk-stat" id="mkMsg" style="flex:1;"></span>
    <span class="mk-stat">
        <?php if ($last): ?>
            آخر سحبة: <?= e(date('Y-m-d H:i', strtotime((string) $last['fetched_at']))) ?>
            <?php if ($cool > 0): ?> · الجاي بعد <?= ceil($cool / 60) ?> د<?php endif; ?>
        <?php else: ?>
            لسه ما اتسحبش حاجة
        <?php endif; ?>
    </span>
</div>

<?php if ($stats['products'] === 0): ?>
    <div class="mk-card" style="text-align:center;padding:34px;color:var(--ink-500);">
        اضغط «حدّث أسعار السوق» الأول عشان نجيب المنتجات ونبدأ الربط.
    </div>
<?php else: ?>

<?php
// المنتجات المخزّنة اتطبّعت وقت السحب. لو قاموس المرادفات اتغيّر بعد
// كده، المخزّن بقى قديم والمطابقة بتبوظ — فبنكشفه ونعرض زرار إصلاح.
[$staleN, $staleChecked] = mk_norm_stale();
?>
<?php if ($staleN > 0): ?>
    <div class="mk-card" id="mkStale" style="border-right:4px solid var(--warn);
         background:var(--warn-soft);padding:14px;margin-bottom:12px;
         display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <i class="ti ti-alert-triangle" style="color:var(--warn);font-size:20px;"></i>
        <div style="flex:1;min-width:220px;">
            <b>المنتجات المحفوظة محتاجة إعادة تطبيع.</b><br>
            <span style="font-size:12.5px;color:var(--ink-600);">
                قاموس المرادفات اتغيّر، و<?= $staleN ?> من <?= $staleChecked ?> منتج
                فحصناهم لسه بالصيغة القديمة. من غير الخطوة دي المطابقة هتطلع
                أسوأ من الأول. مابيتغيّرش أي سعر ولا أي ربط أكّدته.
            </span>
        </div>
        <button class="btn" id="mkRenorm">
            <i class="ti ti-refresh"></i> أعد التطبيع دلوقتي
        </button>
    </div>
<?php endif; ?>

<form method="get" style="margin-bottom:12px;display:flex;gap:8px;flex-wrap:wrap;">
    <input type="hidden" name="f" value="<?= e($filter) ?>">
    <input type="text" name="q" class="mk-search" value="<?= e($term) ?>"
           placeholder="دوّر على كتاب عندنا…" style="flex:1;min-width:200px;">
    <button class="btn" type="submit"><i class="ti ti-search"></i> بحث</button>
</form>

<div class="mk-filters">
    <?php foreach (['unlinked'=>'مش مربوط','linked'=>'مربوط','ignored'=>'مستبعد','all'=>'الكل'] as $k=>$lbl): ?>
        <a class="mk-f <?= $filter===$k?'on':'' ?>"
           href="?f=<?= $k ?>&q=<?= urlencode($term) ?>"><?= e($lbl) ?></a>
    <?php endforeach; ?>
    <span class="mk-stat" style="align-self:center;">(<?= number_format($books['total']) ?> كتاب)</span>
</div>

<?php if ($filter === 'unlinked' && $books['total'] > 0): ?>
<div class="mk-card" style="background:var(--surface);border:1.5px dashed var(--accent);">
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <button class="btn" id="mkBulk"><i class="ti ti-wand"></i> أكّد كل المطابقات القوية</button>
        <span class="mk-stat" id="mkBulkMsg" style="flex:1;">
            بدل ما تدوس «أكّد» <?= number_format($books['total']) ?> مرة — ده بيربط اللي فوق
            <?= (int) round(MARKET_STRONG * 100) ?>% بس، وبيسيب أي كتاب ليه اقتراحين متقاربين لعينك.
        </span>
        <button class="btn-sm ghost" id="mkUndo" style="display:none;">تراجع عن الدفعة دي</button>
    </div>
</div>
<?php endif; ?>

<?php if (!$books['rows']): ?>
    <div class="mk-card" style="text-align:center;padding:30px;color:var(--ink-500);">
        مفيش كتب في الفلتر ده.
    </div>
<?php endif; ?>

<?php foreach ($books['rows'] as $b):
    $iid  = $b['item_id'];
    $opts = $sugg[$iid] ?? [];
    $lnk  = $b['linked'];
?>
<div class="mk-card <?= $lnk ? 'mk-linked' : '' ?><?= $b['ignored'] ? ' mk-ign' : '' ?>" data-item="<?= $iid ?>">
    <div class="mk-our"><?= e($b['name']) ?></div>
    <div class="mk-sub">
        <?= e($b['code']) ?> · سعرنا: <b><?= number_format($b['sale'], 2) ?></b>
    </div>

    <?php if ($lnk !== null && isset($prods[$lnk])): $p = $prods[$lnk];
        $ls = $b['lnk_score'];
        $lcls = $ls === null ? 'mk-strong' : ($ls >= MARKET_STRONG ? 'mk-strong' : ($ls >= MARKET_MAYBE ? 'mk-maybe' : 'mk-weak'));
    ?>
        <div class="mk-sugg">
            <div class="mk-opt">
                <span class="mk-badge <?= $lcls ?>">
                    <?= $ls === null ? 'يدوي' : number_format($ls * 100) . '%' ?>
                </span>
                <span style="flex:1;"><?= e($p['name']) ?></span>
                <b><?= number_format($p['price'], 2) ?></b>
                <?php if ($p['permalink'] !== ''): ?>
                    <a class="btn-sm ghost" href="<?= e($p['permalink']) ?>" target="_blank"
                       rel="noopener" style="text-decoration:none;">شوفه</a>
                <?php endif; ?>
                <button class="btn-sm ghost" data-act="unlink">فك الربط</button>
            </div>
        </div>
    <?php elseif ($b['ignored']): ?>
        <div class="mk-sugg">
            <div class="mk-opt">
                <span class="mk-badge mk-weak">مستبعد</span>
                <span style="flex:1;">قلت إن مالوش مقابل عندهم</span>
                <button class="btn-sm ghost" data-act="unignore">رجّعه</button>
            </div>
        </div>
    <?php else: ?>
        <div class="mk-sugg">
            <?php if (!$opts): ?>
                <div class="mk-opt"><span class="mk-badge mk-weak">مفيش اقتراح</span>
                    <span style="flex:1;color:var(--ink-500);">دوّر عليه بإيدك</span></div>
            <?php endif; ?>

            <?php foreach ($opts as $o):
                $p = $prods[$o['market_id']] ?? null;
                if (!$p) continue;
                $s = (float) $o['score'];
                $cls = $s >= MARKET_STRONG ? 'mk-strong' : ($s >= MARKET_MAYBE ? 'mk-maybe' : 'mk-weak');
            ?>
                <div class="mk-opt">
                    <span class="mk-badge <?= $cls ?>"><?= number_format($s * 100) ?>%</span>
                    <span style="flex:1;"><?= e($p['name']) ?></span>
                    <b><?= number_format($p['price'], 2) ?></b>
                    <button class="btn-sm" data-act="link"
                            data-market="<?= (int) $o['market_id'] ?>"
                            data-score="<?= $s ?>">أكّد</button>
                </div>
            <?php endforeach; ?>

            <div class="mk-opt">
                <button class="btn-sm ghost" data-act="manual">دوّر بإيدي</button>
                <button class="btn-sm ghost" data-act="ignore">مالوش مقابل</button>
            </div>

            <div class="mk-manual">
                <input type="text" class="mk-search mk-mterm" placeholder="ابحث في منتجاتهم…" style="width:100%;box-sizing:border-box;">
                <div class="mk-mres"></div>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php if ($pages > 1): ?>
<div class="mk-pager">
    <?php $qs = 'f=' . urlencode($filter) . '&q=' . urlencode($term); ?>
    <?php if ($page > 1): ?><a class="mk-f" href="?<?= $qs ?>&p=<?= $page-1 ?>">السابق</a><?php endif; ?>
    <span class="mk-stat">صفحة <?= $page ?> من <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="mk-f" href="?<?= $qs ?>&p=<?= $page+1 ?>">التالي</a><?php endif; ?>
</div>
<?php endif; ?>

<?php endif; /* products > 0 */ ?>
<?php endif; /* ready */ ?>

<script>
(function () {
    'use strict';
    const CSRF = <?= json_encode(csrf_token()) ?>;
    const $ = id => document.getElementById(id);

    async function api(action, body, qs) {
        const res = await fetch('market_prices.php?ajax=' + action + (qs ? '&' + qs : ''),
            body ? { method:'POST', headers:{'Content-Type':'application/json'},
                     body: JSON.stringify(Object.assign({ csrf: CSRF }, body)) } : {});
        const d = await res.json().catch(() => ({ ok:false, error:'رد غير مفهوم.' }));
        if (!d.ok) throw new Error(d.error || 'حصل خطأ.');
        return d;
    }
    function msg(t, bad) {
        const el = $('mkMsg');
        if (!el) return;
        el.textContent = t || '';
        el.style.color = bad ? 'var(--neg)' : 'var(--pos)';
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g,
            c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    // ---- سحب الأسعار ----
    const fb = $('mkFetch');
    if (fb) fb.addEventListener('click', async function () {
        fb.disabled = true;
        msg('بيسحب من المتجر… ممكن ياخد شوية.');
        try {
            const d = await api('fetch', {});
            msg('اتسحب ' + d.products + ' منتج ✓ — بيعمل ريفريش…');
            setTimeout(() => location.reload(), 900);
        } catch (e) { msg(e.message, true); fb.disabled = false; }
    });

    // ---- إعادة تطبيع المنتجات المحفوظة ----
    const rb = $('mkRenorm');
    if (rb) rb.addEventListener('click', async function () {
        rb.disabled = true;
        const old = rb.innerHTML;
        rb.textContent = 'بيعيد الحساب…';
        try {
            const d = await api('renorm', {});
            rb.textContent = 'اتحدّث ' + d.updated + ' منتج ✓';
            setTimeout(() => location.reload(), 900);
        } catch (e) {
            msg(e.message, true);
            rb.innerHTML = old;
            rb.disabled = false;
        }
    });

    // ---- الربط الجماعي ----
    const bulk = $('mkBulk'), undo = $('mkUndo'), bmsg = $('mkBulkMsg');
    let lastMark = null;

    if (bulk) bulk.addEventListener('click', async function () {
        bulk.disabled = true;
        bmsg.textContent = 'بيفحص كل الكتب… استنى شوية.';
        try {
            const pv = await api('bulk_preview', null);
            if (!pv.count) {
                bmsg.textContent = 'مفيش أي مطابقة قوية كفاية — لازم تربط بإيدك.';
                bulk.disabled = false;
                return;
            }
            const txt = 'هيتربط ' + pv.count + ' كتاب أوتوماتيك (مطابقة قوية بس)'
                + (pv.skipped_ambiguous ? '\nوهيتساب ' + pv.skipped_ambiguous
                   + ' كتاب ليهم أكتر من اقتراح قوي — دول محتاجين عينك.' : '')
                + '\n\nتقدر تراجع كل واحد بعدين من فلتر «مربوط» وتفك أي غلطة. تمام؟';
            if (!confirm(txt)) { bulk.disabled = false; bmsg.textContent = ''; return; }

            bmsg.textContent = 'بيربط…';
            const d = await api('bulk_link', {});
            lastMark = d.mark;
            bmsg.textContent = 'اتربط ' + d.count + ' كتاب ✓ — راجعهم من فلتر «مربوط» (مرتّبين بالأحدث).'
                + (d.exact_ties ? ' منهم ' + d.exact_ties
                   + ' كان ليهم أكتر من منتج بنفس الاسم بالظبط — اتربطوا بالأول، ابص عليهم.' : '')
                + (d.skipped_ambiguous ? ' وفاضل ' + d.skipped_ambiguous
                   + ' كتاب ليهم أكتر من اقتراح متقارب — هتلاقيهم في «مش مربوط» تربطهم بإيدك.' : '');
            if (undo) undo.style.display = '';
        } catch (e) {
            bmsg.textContent = e.message;
            bmsg.style.color = 'var(--neg)';
            bulk.disabled = false;
        }
    });

    if (undo) undo.addEventListener('click', async function () {
        if (!lastMark) return;
        if (!confirm('هيتفك ربط كل اللي اتعمل في الدفعة دي. تمام؟')) return;
        undo.disabled = true;
        try {
            const d = await api('bulk_undo', { since: lastMark });
            bmsg.textContent = 'اتفك ' + d.removed + ' ربط — بيعمل ريفريش…';
            setTimeout(() => location.reload(), 800);
        } catch (e) { bmsg.textContent = e.message; undo.disabled = false; }
    });

    // ---- أزرار الكروت (تفويض واحد) ----
    document.addEventListener('click', async function (ev) {
        const b = ev.target.closest('[data-act]');
        if (!b) return;
        const card = b.closest('[data-item]');
        if (!card) return;
        const itemId = parseInt(card.dataset.item, 10);
        const act = b.dataset.act;

        if (act === 'manual') {
            const box = card.querySelector('.mk-manual');
            box.style.display = box.style.display === 'block' ? 'none' : 'block';
            if (box.style.display === 'block') box.querySelector('.mk-mterm').focus();
            return;
        }

        b.disabled = true;
        try {
            if (act === 'link') {
                await api('link', { item_id: itemId, market_id: parseInt(b.dataset.market, 10),
                                    score: parseFloat(b.dataset.score) });
                card.style.transition = 'opacity .3s'; card.style.opacity = '.35';
                msg('اتربط ✓');
            } else if (act === 'unlink')   { await api('unlink',   { item_id: itemId }); location.reload(); }
              else if (act === 'ignore')   { await api('ignore',   { item_id: itemId });
                                             card.style.opacity = '.35'; msg('اتستبعد'); }
              else if (act === 'unignore') { await api('unignore', { item_id: itemId }); location.reload(); }
        } catch (e) { msg(e.message, true); b.disabled = false; }
    });

    // ---- البحث اليدوي في منتجاتهم ----
    let t = null;
    document.addEventListener('input', function (ev) {
        const inp = ev.target.closest('.mk-mterm');
        if (!inp) return;
        clearTimeout(t);
        const card = inp.closest('[data-item]');
        const term = inp.value.trim();
        if (term.length < 2) return;
        t = setTimeout(async () => {
            const box = card.querySelector('.mk-mres');
            box.innerHTML = '<div style="color:var(--ink-400);">بيدوّر…</div>';
            try {
                const d = await api('search', null, 'term=' + encodeURIComponent(term));
                box.innerHTML = d.rows.length ? d.rows.map(r =>
                    '<div data-pick="' + r.market_id + '">' + esc(r.name) +
                    ' — <b>' + Number(r.price).toFixed(2) + '</b></div>').join('')
                    : '<div style="color:var(--ink-400);">مفيش نتائج</div>';
            } catch (e) { box.innerHTML = '<div style="color:var(--neg);">' + esc(e.message) + '</div>'; }
        }, 350);
    });

    document.addEventListener('click', async function (ev) {
        const d = ev.target.closest('[data-pick]');
        if (!d) return;
        const card = d.closest('[data-item]');
        try {
            await api('link', { item_id: parseInt(card.dataset.item, 10),
                                market_id: parseInt(d.dataset.pick, 10), score: null });
            card.style.opacity = '.35';
            msg('اتربط ✓');
        } catch (e) { msg(e.message, true); }
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
