<?php
/**
 * labels.php — طباعة ليبلز الباركود والسعر (بريف ٢١٦)
 * نظام الفجالة ستور المالي
 *
 * بتختار أصناف بالسكانر أو بالاسم ← ليبل لكل قطعة فيه اسم الصنف والسعر
 * والباركود ← تطبع من المتصفح (مقاس الورق بالمليمتر) أو تصدّر إكسيل
 * لـBarTender. القايمة بتتحفظ في المتصفح (localStorage) عشان لو الصفحة
 * اتقفلت بالغلط ماتضيعش.
 *
 * الأصناف والسعر من hq_codes.price — سعر الكود نفسه (الباكو ليه سعره).
 */
require_once __DIR__ . '/auth.php';
require_permission('labels');
require_once __DIR__ . '/labels_lib.php';

$lb      = labels_settings();
$isOwner = is_real_owner();

$page_title    = 'طباعة ليبلز';
$active_module = 'labels';
require_once __DIR__ . '/includes/header.php';
?>
<style>
    /* الصفحة دي مالهاش كلاس .btn خاص — الموحّد في fagala.css (بريف ٤٣) */
    .lbx-settings {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(118px, 1fr));
        gap: 10px;
        align-items: end;
        padding: 12px;
        border: 1px solid var(--line);
        border-radius: var(--r-md);
        background: var(--bg);
        margin-bottom: 14px;
    }
    .lbx-fld { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
    .lbx-fld > span { font-size: var(--fs-xs); color: var(--ink-600); font-weight: 600; }
    .lbx-fld input[type=number], .lbx-fld input[type=text] {
        width: 100%; padding: 7px 10px; border: 1.5px solid var(--line);
        border-radius: var(--r-sm); background: var(--surface); color: var(--text);
        font-family: inherit; font-size: var(--fs-sm);
    }
    .lbx-fld input:focus { outline: none; border-color: var(--accent); }
    .lbx-fld input:disabled { opacity: 0.65; cursor: not-allowed; }
    .lbx-fld.lbx-wide { grid-column: span 2; }
    .lbx-chk { display: flex; align-items: center; gap: 6px; font-size: var(--fs-sm);
               color: var(--ink-700); font-weight: 600; padding-bottom: 8px; }
    .lbx-chk input { width: 17px; height: 17px; accent-color: var(--accent); }
    .lbx-set-status { font-size: var(--fs-xs); color: var(--ink-500); align-self: center; }

    .lbx-bar { display: flex; gap: 8px; align-items: center; margin-bottom: 6px;
               flex-wrap: wrap; position: relative; }
    .lbx-bar input.main {
        flex: 1; min-width: 200px; padding: 11px 14px; border: 1.5px solid var(--line);
        border-radius: var(--r-md); background: var(--surface); color: var(--text);
        font-size: var(--fs-md); font-family: inherit;
    }
    .lbx-bar input.main:focus { outline: none; border-color: var(--accent-text); }
    .lbx-sug {
        position: absolute; top: 100%; right: 0; left: 0; z-index: var(--z-dropdown);
        background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-md);
        margin-top: 4px; max-height: 320px; overflow-y: auto; box-shadow: var(--shadow-lg);
        display: none;
    }
    .lbx-sug.show { display: block; }
    .lbx-sug-item {
        padding: 9px 13px; cursor: pointer; display: flex; justify-content: space-between;
        gap: 10px; border-bottom: 1px solid var(--line-soft); font-size: var(--fs-sm);
    }
    .lbx-sug-item:last-child { border-bottom: none; }
    .lbx-sug-item:hover { background: var(--accent-soft); }
    .lbx-sug-item .c { color: var(--ink-500); font-size: var(--fs-xs); direction: ltr;
                       unicode-bidi: embed; font-variant-numeric: tabular-nums; }
    .lbx-sug-item .p { color: var(--pos); font-weight: 700; white-space: nowrap;
                       font-variant-numeric: tabular-nums; }

    .lbx-status { font-size: var(--fs-sm); min-height: 20px; margin-bottom: 8px; }
    .lbx-status.err { color: var(--neg); }
    .lbx-status.ok  { color: var(--pos); }
    .lbx-status.warn{ color: var(--warn); }

    .lbx-code { direction: ltr; unicode-bidi: embed; font-variant-numeric: tabular-nums;
                color: var(--ink-600); white-space: nowrap; }
    .lbx-copies { width: 76px; padding: 6px 8px; border: 1.5px solid var(--line);
                  border-radius: var(--r-sm); background: var(--surface); color: var(--text);
                  font-family: inherit; font-size: var(--fs-sm); text-align: center;
                  font-variant-numeric: tabular-nums; }
    .lbx-copies:focus { outline: none; border-color: var(--accent); }
    .lbx-del { background: none; border: none; color: var(--neg); cursor: pointer;
               font-size: 17px; line-height: 1; padding: 4px 6px; font-family: inherit; }
    .lbx-del:hover { color: var(--neg); opacity: 0.7; }

    .lbx-foot { display: flex; justify-content: space-between; align-items: center;
                gap: 12px; flex-wrap: wrap; margin-top: 12px; }
    .lbx-total { font-size: var(--fs-sm); color: var(--ink-600); font-weight: 600; }
    .lbx-total b { color: var(--text); font-variant-numeric: tabular-nums; }
    .lbx-actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .lbx-empty { text-align: center; color: var(--ink-400); padding: 34px 16px; font-size: var(--fs-md); }
    .lbx-hint { font-size: var(--fs-xs); color: var(--ink-500); margin: -6px 0 12px; }
    @media (max-width: 520px) {
        .lbx-fld.lbx-wide { grid-column: span 2; }
        .lbx-actions { width: 100%; }
        .lbx-actions .btn { flex: 1; justify-content: center; }
    }
</style>

<div class="card">
    <div class="section-title"><i class="ti ti-printer"></i> طباعة ليبلز الباركود والسعر</div>

    <!-- ===== إعدادات الليبل (بتتحفظ في settings للمالك) ===== -->
    <div class="lbx-settings">
        <label class="lbx-fld">
            <span>عرض الليبل (مم)</span>
            <input type="number" id="setW" min="20" max="120" step="0.5" value="<?= e((string) $lb['w_mm']) ?>" <?= $isOwner ? '' : 'disabled' ?>>
        </label>
        <label class="lbx-fld">
            <span>ارتفاع الليبل (مم)</span>
            <input type="number" id="setH" min="10" max="80" step="0.5" value="<?= e((string) $lb['h_mm']) ?>" <?= $isOwner ? '' : 'disabled' ?>>
        </label>
        <label class="lbx-fld">
            <span>عدد الأعمدة</span>
            <input type="number" id="setCols" min="1" max="5" step="1" value="<?= (int) $lb['cols'] ?>" <?= $isOwner ? '' : 'disabled' ?>>
        </label>
        <label class="lbx-fld">
            <span>المسافة بين الليبلز (مم)</span>
            <input type="number" id="setGap" min="0" max="10" step="0.5" value="<?= e((string) $lb['gap_mm']) ?>" <?= $isOwner ? '' : 'disabled' ?>>
        </label>
        <label class="lbx-fld lbx-wide">
            <span>اسم المحل في الليبل</span>
            <input type="text" id="setShop" maxlength="40" value="<?= e($lb['shop_name']) ?>" <?= $isOwner ? '' : 'disabled' ?>>
        </label>
        <label class="lbx-chk">
            <input type="checkbox" id="setPrice" <?= $lb['show_price'] ? 'checked' : '' ?> <?= $isOwner ? '' : 'disabled' ?>>
            اطبع السعر
        </label>
        <span class="lbx-set-status" id="setStatus"></span>
    </div>
    <?php if (!$isOwner): ?>
        <div class="lbx-hint">مقاسات الليبل واسم المحل بيتظبطوا من حساب المالك — الطباعة شغالة بالمقاسات المحفوظة.</div>
    <?php endif; ?>

    <!-- ===== البحث والسكانر ===== -->
    <div class="lbx-bar">
        <input type="text" class="main" id="q" autocomplete="off"
               placeholder="امسح باركود، أو اكتب كود/اسم الصنف…"
               enterkeyhint="search"
               oninput="lbxType()"
               onkeydown="if(isEnterKey(event)){event.preventDefault();lbxAddByCode();}">
        <button type="button" class="btn btn-ghost" onclick="openBarcodeScanner('q')" title="مسح بالكاميرا">
            <i class="ti ti-camera"></i>
        </button>
        <button type="button" class="btn btn-primary" onclick="lbxAddByCode()">
            <i class="ti ti-plus"></i> إضافة
        </button>
        <div class="lbx-sug" id="sug"></div>
    </div>
    <div class="lbx-status" id="status"></div>

    <!-- ===== القايمة ===== -->
    <div class="ftable-wrap">
        <table class="ftable" id="tbl">
            <thead>
                <tr>
                    <th class="num" style="width:44px;">#</th>
                    <th>الكود</th>
                    <th>اسم الصنف</th>
                    <th class="num" style="width:90px;">السعر</th>
                    <th class="num" style="width:118px;">عدد الليبلز</th>
                    <th style="width:44px;"></th>
                </tr>
            </thead>
            <tbody id="body"></tbody>
        </table>
    </div>

    <div class="lbx-foot">
        <span class="lbx-total" id="total"></span>
        <span class="lbx-actions">
            <button type="button" class="btn btn-secondary" onclick="lbxExport()">
                <i class="ti ti-file-spreadsheet"></i> إكسيل لـBarTender
            </button>
            <button type="button" class="btn btn-danger" onclick="lbxClear()">
                <i class="ti ti-trash"></i> تفريغ
            </button>
            <button type="button" class="btn btn-primary" onclick="lbxPrint()">
                <i class="ti ti-printer"></i> اطبع
            </button>
        </span>
    </div>
</div>

<script src="assets/barcode_scanner.js?v=9"></script>
<script>
/* =====================================================================
 *  طباعة الليبلز — كل الحساب في المتصفح، والسيرفر بيسأله عن الأكواد بس.
 *  القايمة في localStorage: الصفحة لو اتقفلت أو النت قطع مايضيعش الشغل.
 * ===================================================================== */
const LBX = {
    max: <?= (int) LABELS_MAX ?>,
    owner: <?= $isOwner ? 'true' : 'false' ?>,
    settings: <?= json_encode($lb, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
};
const LBX_LS = 'fagala_labels_v1';

let rows = [];          // [{code,name,price,code_type,factor,kind,printable,copies}]
let sugTimer = null;
let setTimer = null;
let setSaving = null;

/* ===== التخزين ===== */
function lbxSave() {
    try { localStorage.setItem(LBX_LS, JSON.stringify(rows)); } catch (e) { /* متصفح بيمنع */ }
}
function lbxLoad() {
    let raw = null;
    try { raw = localStorage.getItem(LBX_LS); } catch (e) { return; }
    if (!raw) return;
    try {
        const list = JSON.parse(raw);
        if (!Array.isArray(list)) return;
        rows = list.filter(r => r && typeof r.code === 'string' && r.code !== '')
            .map(r => ({
                code: String(r.code),
                name: String(r.name == null ? '' : r.name),
                price: +r.price || 0,
                code_type: +r.code_type || 0,
                factor: +r.factor || 1,
                kind: String(r.kind || ''),
                printable: r.printable !== false,
                copies: Math.max(1, Math.min(LBX.max, parseInt(r.copies, 10) || 1))
            }));
    } catch (e) { rows = []; }
}

/* ===== الإضافة ===== */
function lbxType() {
    const v = document.getElementById('q').value.trim();
    clearTimeout(sugTimer);
    if (v.length < 2) { lbxHideSug(); return; }
    if (/^\d+$/.test(v)) { lbxHideSug(); return; }   // أرقام = باركود، Enter هيضيفه
    sugTimer = setTimeout(() => lbxFetchSug(v), 350);
}

function lbxFetchSug(term) {
    apiFetch('labels_api.php?part=search&term=' + encodeURIComponent(term))
        .then(j => {
            const list = j.data.rows || [];
            if (!list.length) { lbxHideSug(); return; }
            const box = document.getElementById('sug');
            box.innerHTML = list.map((r, i) =>
                '<div class="lbx-sug-item" data-sug="' + i + '">' +
                    '<span><b>' + lbxEsc(r.name) + '</b><br><span class="c">' + lbxEsc(r.code) + '</span></span>' +
                    '<span class="p">' + lbxMoney(r.price) + '</span>' +
                '</div>').join('');
            box.dataset.rows = JSON.stringify(list);
            box.classList.add('show');
        })
        .catch(() => lbxHideSug());
}

function lbxHideSug() {
    const box = document.getElementById('sug');
    box.classList.remove('show');
    box.innerHTML = '';
    delete box.dataset.rows;
}

function lbxPickSug(i) {
    const box = document.getElementById('sug');
    let list = [];
    try { list = JSON.parse(box.dataset.rows || '[]'); } catch (e) { list = []; }
    const r = list[i];
    lbxHideSug();
    if (!r) return;
    document.getElementById('q').value = '';
    lbxPush(r);
    document.getElementById('q').focus();
}

function lbxAddByCode() {
    const inp = document.getElementById('q');
    const code = inp.value.trim();
    if (!code) return;
    lbxHideSug();
    lbxStatus('بيدور…', '');
    apiFetch('labels_api.php?part=code&code=' + encodeURIComponent(code))
        .then(j => {
            if (j.data.row) {
                inp.value = '';
                lbxPush(j.data.row);
                if (navigator.vibrate) navigator.vibrate(40);
            } else {
                lbxStatus('✗ الكود ده مش موجود — جرّب تكتب اسم الصنف', 'err');
            }
        })
        .catch(lbxErr)
        .finally(() => inp.focus());
}

function lbxPush(row) {
    const i = rows.findIndex(r => r.code === row.code);
    if (i > -1) {
        // نفس الكود تاني = نسخة زيادة (السكانر بيمسح نفس الصنف مرتين)
        rows[i].copies = Math.min(LBX.max, rows[i].copies + 1);
        lbxSave();
        lbxRender();
        lbxStatus('✓ ' + row.name + ' — العدد بقى ' + rows[i].copies, 'ok');
        return;
    }
    rows.push({
        code: row.code, name: row.name, price: +row.price || 0,
        code_type: +row.code_type || 0, factor: +row.factor || 1,
        kind: row.kind || '', printable: row.printable !== false, copies: 1
    });
    lbxSave();
    lbxRender();
    lbxStatus('✓ اتضاف ' + row.name, 'ok');
}

/* ===== العرض ===== */
function lbxRender() {
    const body = document.getElementById('body');
    if (!rows.length) {
        body.innerHTML = '<tr><td colspan="6" class="lbx-empty">' +
            'امسح باركود، أو اكتب اسم صنف واختاره من القايمة — وكل صنف بتحدد له كام ليبل.' +
            '</td></tr>';
    } else {
        body.innerHTML = rows.map((r, i) => {
            let chip = '';
            if (!r.printable) {
                chip = '<span class="fchip fchip-warn">مش هيتطبع</span> ';
            } else if (r.code_type === 1 && r.factor > 1) {
                chip = '<span class="fchip fchip-mute">باكو ×' + lbxNum(r.factor) + '</span> ';
            }
            return '<tr>' +
                '<td class="num">' + (i + 1) + '</td>' +
                '<td class="lbx-code">' + lbxEsc(r.code) + '</td>' +
                '<td class="name" title="' + lbxEsc(r.name) + '">' + chip + lbxEsc(r.name) + '</td>' +
                '<td class="num">' + lbxMoney(r.price) + '</td>' +
                '<td class="num"><input type="number" class="lbx-copies" data-copy="' + i + '"' +
                    ' min="1" max="' + LBX.max + '" step="1" value="' + r.copies + '"' +
                    ' aria-label="عدد ليبلز ' + lbxEsc(r.name) + '"></td>' +
                '<td><button type="button" class="lbx-del" data-del="' + i + '" title="شيل" aria-label="شيل">' +
                    '<i class="ti ti-x"></i></button></td>' +
            '</tr>';
        }).join('');
    }

    lbxTotal();
}

/* العدد اللي تحت الجدول — بيتحدّث مع أي تعديل (مش رقم ثابت من السيرفر) */
function lbxTotal() {
    const total = rows.reduce((s, r) => s + r.copies, 0);
    const bad = rows.filter(r => !r.printable).length;
    document.getElementById('total').innerHTML =
        '<b>' + rows.length + '</b> صنف · <b>' + total + '</b> ليبل' +
        (total > LBX.max ? ' — <span style="color:var(--neg)">الحد الأقصى ' + LBX.max + ' ليبل في الطباعة الواحدة</span>' : '') +
        (bad ? ' — <span style="color:var(--warn)">' + bad + ' كود مش هيتطبع كباركود</span>' : '');
}

/* ===== التعديل والحذف (تفويض — مش مستمع لكل صف) ===== */
document.getElementById('body').addEventListener('input', function (e) {
    const inp = e.target.closest('.lbx-copies');
    if (!inp) return;
    const i = +inp.dataset.copy;
    if (!rows[i]) return;
    let n = parseInt(inp.value, 10);
    if (isNaN(n)) return;
    rows[i].copies = Math.max(1, Math.min(LBX.max, n));
    lbxSave();
    lbxTotal();
});

document.getElementById('body').addEventListener('change', function (e) {
    const inp = e.target.closest('.lbx-copies');
    if (!inp) return;
    const i = +inp.dataset.copy;
    if (!rows[i]) return;
    inp.value = rows[i].copies;      // نرجّع الرقم النضيف في الخانة
});

document.getElementById('body').addEventListener('click', function (e) {
    const btn = e.target.closest('.lbx-del');
    if (!btn) return;
    const i = +btn.dataset.del;
    if (!rows[i]) return;
    const nm = rows[i].name;
    rows.splice(i, 1);
    lbxSave();
    lbxRender();
    lbxStatus('اتشال ' + nm, '');
});

document.getElementById('sug').addEventListener('click', function (e) {
    const it = e.target.closest('.lbx-sug-item');
    if (it) lbxPickSug(+it.dataset.sug);
});

function lbxClear() {
    if (!rows.length) return;
    if (!confirm('تفريغ القايمة كلها؟')) return;
    rows = [];
    lbxSave();
    lbxRender();
    lbxStatus('', '');
}

/* ===== الإعدادات (حفظ على blur) ===== */
function lbxSetBody() {
    return {
        w_mm: +document.getElementById('setW').value,
        h_mm: +document.getElementById('setH').value,
        cols: +document.getElementById('setCols').value,
        gap_mm: +document.getElementById('setGap').value,
        show_price: document.getElementById('setPrice').checked ? 1 : 0,
        shop_name: document.getElementById('setShop').value
    };
}

function lbxQueueSave() {
    if (!LBX.owner) return;
    const s = document.getElementById('setStatus');
    s.textContent = 'بيتحفظ…';
    s.style.color = 'var(--ink-500)';
    clearTimeout(setTimer);
    setTimer = setTimeout(lbxSaveSettings, 400);
}

function lbxSaveSettings() {
    setTimer = null;
    const done = apiFetch('labels_api.php?part=settings', { method: 'POST', body: lbxSetBody() })
        .then(j => {
            LBX.settings = j.data.settings || LBX.settings;
            const s = document.getElementById('setStatus');
            s.textContent = '✓ اتحفظ';
            s.style.color = 'var(--pos)';
            setTimeout(() => { if (s.textContent === '✓ اتحفظ') s.textContent = ''; }, 2500);
            return j;
        })
        .catch(err => {
            const s = document.getElementById('setStatus');
            s.textContent = '✗ ' + ((err && err.error) || 'ماحفظش');
            s.style.color = 'var(--neg)';
            throw err;
        })
        .finally(() => { setSaving = null; });
    setSaving = done;
    done.catch(() => {});   // الخطأ ظاهر في الشاشة — مش عايزين unhandled rejection
    return done;
}

['setW', 'setH', 'setCols', 'setGap', 'setShop'].forEach(id => {
    const el = document.getElementById(id);
    if (el && LBX.owner) el.addEventListener('change', lbxQueueSave);
});
(function () {
    const cb = document.getElementById('setPrice');
    if (cb && LBX.owner) cb.addEventListener('change', lbxQueueSave);
})();

/* قبل الطباعة/التصدير: لو فيه إعداد لسه ماحفظش، نحفظه ونستناه
   عشان الليبل يطلع بالمقاس الجديد مش القديم. */
async function lbxFlushSettings() {
    if (setTimer) {
        clearTimeout(setTimer);
        await lbxSaveSettings().catch(() => {});
    } else if (setSaving) {
        await setSaving.catch(() => {});
    }
}

/* ===== الطباعة والتصدير ===== */
function lbxPost(action, fields, newTab) {
    const f = document.createElement('form');
    f.method = 'POST';
    f.action = action;
    f.style.display = 'none';
    if (newTab) f.target = '_blank';
    Object.keys(fields).forEach(k => {
        const i = document.createElement('input');
        i.type = 'hidden';
        i.name = k;
        i.value = fields[k];
        f.appendChild(i);
    });
    document.body.appendChild(f);
    f.submit();
    setTimeout(() => f.remove(), 1500);
}

function lbxPayload() {
    return JSON.stringify(rows.map(r => ({ code: r.code, copies: r.copies })));
}

async function lbxPrint() {
    if (!rows.length) { fgToast('القايمة فاضية', 'warn'); return; }
    const total = rows.reduce((s, r) => s + r.copies, 0);
    if (total > LBX.max) {
        lbxStatus('الحد الأقصى ' + LBX.max + ' ليبل في الطباعة الواحدة — قلّل العدد أو اطبع على دفعتين', 'err');
        return;
    }
    await lbxFlushSettings();
    lbxPost('labels_print.php', { rows: lbxPayload() }, true);
}

async function lbxExport() {
    if (!rows.length) { fgToast('القايمة فاضية', 'warn'); return; }
    const total = rows.reduce((s, r) => s + r.copies, 0);
    if (total > LBX.max) {
        lbxStatus('الحد الأقصى ' + LBX.max + ' ليبل في المرة الواحدة', 'err');
        return;
    }
    await lbxFlushSettings();
    const meta = document.querySelector('meta[name="csrf-token"]');
    lbxPost('labels_api.php?part=xlsx',
        { rows: lbxPayload(), csrf: meta ? meta.getAttribute('content') : '' }, false);
}

/* ===== أدوات ===== */
function lbxStatus(msg, kind) {
    const s = document.getElementById('status');
    s.textContent = msg;
    s.className = 'lbx-status' + (kind ? ' ' + kind : '');
    if (kind === 'ok') {
        setTimeout(() => { if (s.textContent === msg) { s.textContent = ''; s.className = 'lbx-status'; } }, 3000);
    }
}

function lbxErr(err) {
    if (err && err.code === 'auth') { location.reload(); return; }
    if (err && typeof err.status === 'undefined') { lbxStatus('✗ مفيش اتصال بالسيرفر', 'err'); return; }
    lbxStatus('✗ ' + ((err && err.error) || 'حصل خطأ'), 'err');
}

function lbxMoney(n) {
    n = +n || 0;
    return n.toFixed(2);
}
function lbxNum(n) {
    n = +n || 0;
    return String(parseFloat(n.toFixed(3)));
}
function lbxEsc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

/* إغلاق الاقتراحات بالضغط برّه */
document.addEventListener('click', e => {
    if (!e.target.closest('.lbx-bar')) lbxHideSug();
});

lbxLoad();
lbxRender();
document.getElementById('q').focus();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
