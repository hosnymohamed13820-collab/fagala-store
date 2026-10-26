<?php
/**
 * الأقسام والتصنيفات (بريف ٢٣٤) — صفحة عرض وتحرير بس.
 * كل الداتا والكتابة من item_classes_api.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_module_access('item_master');
require_once __DIR__ . '/item_classes_lib.php';

if (!can('item_master:classes')) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;text-align:center;padding:60px;direction:rtl"><h2>غير مصرّح</h2><p>مالكش صلاحية «الأقسام والتصنيفات».</p></div>');
}

$page_title = 'الأقسام والتصنيفات';
$active_module = 'item_master';
$icKinds = ic_kinds();
require_once __DIR__ . '/includes/header.php';
?>
<style>
.ic-banner{padding:12px 14px;border:1px solid var(--warn);background:var(--warn-soft);border-radius:10px;margin-bottom:14px}
.ic-tabs{display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid var(--line);padding-bottom:8px;margin-bottom:14px}
.ic-tab{border:1px solid var(--line);background:var(--surface);color:var(--text);padding:9px 12px;border-radius:8px;cursor:pointer;font:inherit}
.ic-tab.active{background:var(--accent);color:var(--fill-strong-fg);border-color:var(--accent)}
.ic-add{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px}
.ic-input{padding:10px 12px;border:1px solid var(--line);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;max-width:100%}
.ic-rename{width:100%;min-width:180px}
.ic-hint{font-size:12px;color:var(--ink-500)}
.ic-muted{color:var(--ink-400);font-size:12px}
.ic-actions{white-space:nowrap}
.ic-off .ic-name{color:var(--ink-400)}
.ic-status{min-height:22px;margin-top:8px;color:var(--ink-500)}
.ic-status.err{color:var(--neg)}
.ic-status.ok{color:var(--pos)}
@media(max-width:700px){.ic-add .ic-input{flex:1 1 100%}.ic-tab{flex:1 1 45%}}
</style>

<div class="ic-banner">
    التصنيفات الجاية من True (القسم) عرض بس — التعديل والإيقاف للموقع بس.
    كل تصنيف بيتخزن عندنا، ومفيش أي كتابة في True.
</div>

<div class="card">
    <div class="section-title"><i class="ti ti-list"></i> الأقسام والتصنيفات</div>

    <div class="ic-tabs" id="icTabs">
        <?php foreach ($icKinds as $kindKey => $kindLabel): ?>
            <button type="button" class="ic-tab<?= $kindKey === 'section' ? ' active' : '' ?>"
                    data-kind="<?= htmlspecialchars($kindKey, ENT_QUOTES) ?>"><?= htmlspecialchars($kindLabel) ?></button>
        <?php endforeach; ?>
    </div>

    <form class="ic-add" id="icAddForm" autocomplete="off">
        <input class="ic-input" id="icNewName" maxlength="255" placeholder="اسم جديد…" required>
        <button type="submit" class="btn btn-primary"><i class="ti ti-plus"></i> إضافة</button>
        <span class="ic-hint">الاسم لازم يبقى فريد جوه نفس النوع.</span>
    </form>

    <div class="ftable-wrap">
        <table class="ftable">
            <thead>
                <tr>
                    <th>الاسم</th>
                    <th>المصدر</th>
                    <th>الحالة</th>
                    <th data-xt-skip>إجراءات</th>
                </tr>
            </thead>
            <tbody id="icRows">
                <tr><td colspan="4" class="dim">جاري التحميل…</td></tr>
            </tbody>
        </table>
    </div>

    <div id="icStatus" class="ic-status"></div>
</div>

<script>
const IC_KINDS = <?= json_encode($icKinds, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
(function(){
    'use strict';
    const state = {kind:'section', rows:[]};
    const $ = id => document.getElementById(id);
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
    function status(msg, type){const el=$('icStatus'); el.textContent=msg||''; el.className='ic-status '+(type||'');}

    function rowHtml(r){
        const fromTrue = r.true_id !== null && r.true_id !== undefined && r.true_id !== '';
        const active = Number(r.is_active) === 1;
        const actions = fromTrue
            ? '<span class="ic-muted"><i class="ti ti-lock"></i> عرض بس — جاي من True</span>'
            : '<button type="button" class="btn btn-sm btn-outline" data-act="rename"><i class="ti ti-pencil"></i> تعديل الاسم</button> '
              + (active
                    ? '<button type="button" class="btn btn-sm btn-danger" data-act="off"><i class="ti ti-ban"></i> إيقاف</button>'
                    : '<button type="button" class="btn btn-sm btn-success" data-act="on"><i class="ti ti-circle-check"></i> تفعيل</button>');
        return '<tr data-id="' + Number(r.id) + '"' + (active ? '' : ' class="ic-off"') + '>'
            + '<td class="name"><span class="ic-name" title="' + esc(r.name) + '">' + esc(r.name) + '</span></td>'
            + '<td><span class="fchip ' + (fromTrue ? 'fchip-mute' : 'fchip-pos') + '">' + (fromTrue ? 'True' : 'الموقع') + '</span></td>'
            + '<td><span class="fchip ' + (active ? 'fchip-pos' : 'fchip-mute') + '">' + (active ? 'مفعّل' : 'موقوف') + '</span></td>'
            + '<td class="ic-actions">' + actions + '</td>'
            + '</tr>';
    }

    function render(){
        $('icRows').innerHTML = state.rows.length
            ? state.rows.map(rowHtml).join('')
            : '<tr data-xt-ignore><td colspan="4" class="dim">مفيش تصنيفات في النوع ده — ضيف أول واحد من فوق.</td></tr>';
        $('icNewName').placeholder = 'اسم جديد في «' + (IC_KINDS[state.kind] || '') + '»…';
    }

    async function load(kind){
        state.kind = kind;
        document.querySelectorAll('.ic-tab').forEach(b => b.classList.toggle('active', b.dataset.kind === kind));
        status('جاري التحميل…');
        try{
            const r = await apiFetch('item_classes_api.php?part=list&kind=' + encodeURIComponent(kind));
            state.rows = r.data.rows || [];
            render();
            status(state.rows.length ? '' : 'مفيش تصنيفات في النوع ده — ضيف أول واحد من فوق.');
        }catch(e){
            state.rows = [];
            render();
            status(e.message, 'err');
        }
    }

    function startRename(id){
        const tr = document.querySelector('#icRows tr[data-id="' + id + '"]');
        if (!tr) return;
        const cell = tr.querySelector('td.name');
        const old = tr.querySelector('.ic-name').textContent;
        cell.innerHTML = '<input class="ic-input ic-rename" maxlength="255" value="' + esc(old) + '">'
            + ' <button type="button" class="btn btn-sm btn-primary" data-act="save">حفظ</button> '
            + '<button type="button" class="btn btn-sm btn-outline" data-act="cancel">إلغاء</button>';
        const inp = cell.querySelector('.ic-rename');
        inp.focus();
        inp.select();
        inp.addEventListener('keydown', ev => {
            if (ev.key === 'Enter') {
                ev.preventDefault();
                ev.stopPropagation();
                saveRename(id, inp.value);
            } else if (ev.key === 'Escape') {
                ev.preventDefault();
                ev.stopPropagation();
                render();
            }
        });
    }

    async function saveRename(id, name){
        name = String(name || '').trim();
        if (!name) { status('اكتب الاسم', 'err'); return; }
        status('جاري حفظ الاسم…');
        try{
            await apiFetch('item_classes_api.php?part=rename', {method:'POST', body:{id:id, name:name}});
            await load(state.kind);
            status('الاسم اتعدّل', 'ok');
        }catch(e){
            status(e.message, 'err');
        }
    }

    async function toggleClass(id, active){
        status(active ? 'جاري التفعيل…' : 'جاري الإيقاف…');
        try{
            await apiFetch('item_classes_api.php?part=toggle', {method:'POST', body:{id:id, is_active:active ? 1 : 0}});
            await load(state.kind);
            status(active ? 'اتفعّل' : 'اتوقف', 'ok');
        }catch(e){
            status(e.message, 'err');
        }
    }

    $('icTabs').addEventListener('click', e => {
        const b = e.target.closest('[data-kind]');
        if (!b || b.dataset.kind === state.kind) return;
        load(b.dataset.kind);
    });

    $('icRows').addEventListener('click', e => {
        const btn = e.target.closest('[data-act]');
        if (!btn) return;
        const tr = btn.closest('tr');
        const id = Number(tr.dataset.id || 0);
        const act = btn.dataset.act;
        if (act === 'rename') return startRename(id);
        if (act === 'save') return saveRename(id, (tr.querySelector('.ic-rename') || {}).value);
        if (act === 'cancel') return render();
        if (act === 'off' || act === 'on') return toggleClass(id, act === 'on');
    });

    $('icAddForm').addEventListener('submit', async ev => {
        ev.preventDefault();
        const name = $('icNewName').value.trim();
        if (!name) { status('اكتب الاسم', 'err'); return; }
        const btn = ev.currentTarget.querySelector('button[type="submit"]');
        btn.disabled = true;
        status('جاري الإضافة…');
        try{
            await apiFetch('item_classes_api.php?part=create', {method:'POST', body:{kind:state.kind, name:name}});
            $('icNewName').value = '';
            await load(state.kind);
            status('اتضاف «' + name + '»', 'ok');
        }catch(e){
            status(e.message, 'err');
        }finally{
            btn.disabled = false;
        }
    });

    // 🔴 بعد DOMContentLoaded: `apiFetch` في assets/app.js بيتحمّل في الفوتر (بريف ٢٣٥)
    document.addEventListener('DOMContentLoaded', () => { load('section'); });
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
