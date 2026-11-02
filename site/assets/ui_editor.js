/* وضع التعديل معاينة شخصية؛ الحفظ بيكتب طلب جديد بس. */
(() => {
    'use strict';
    const config = window.FAGALA_UI_EDITOR;
    if (!config || !window.ui || !window.apiFetch) return;
    const families = Object.keys(config.variants);
    const targets = families.map(f => '.' + f).join(',');
    const records = new Map(), selectors = new WeakMap(), history = [];
    let selected = null, hover = null, gesture = null, busy = false, dragged = false;
    const esc = window.ui.esc;
    const root = document.createElement('div');
    root.dataset.uiEditor = '1';
    root.dir = 'rtl';
    const button = (text, action) => window.ui.btn(text, {variant: 'ghost', attrs: {'data-editor-action': action}});
    root.innerHTML = `<div class="fcard uie-toolbar" role="toolbar" aria-label="وضع التعديل">
        <strong>وضع التعديل</strong>${button('اختيار', 'choose')}${button('حفظ الطلب', 'save')}${button('تراجع', 'undo')}${button('إلغاء الكل', 'reset')}${button('خروج', 'exit')}
        <span class="fpage-sub" data-editor-message role="status">معاينة عندك بس، والتعديلات بتتحفظ كطلب.</span></div>
        <aside class="fcard uie-panel" aria-label="تعديل العنصر" hidden>
        <strong data-editor-label></strong>
        <p class="fpage-sub">اسحب العنصر أو حرّكه بالأسهم. Shift بيكبّر الخطوة.</p>
        <div class="fgrid fgrid-2">
        <div class="ffield"><label for="uieWidth">العرض بالبكسل</label><input id="uieWidth" class="finput" type="number" min="1" max="20000" data-editor-field="width"></div>
        <div class="ffield"><label for="uieHeight">الطول بالبكسل</label><input id="uieHeight" class="finput" type="number" min="1" max="20000" data-editor-field="height"></div></div>
        ${button('تثبيت النسبة', 'ratio')}
        <div class="ffield"><label for="uieVariant">النوع من المكتبة</label><select id="uieVariant" class="fselect" data-editor-field="variant"></select></div>
        <div class="fcard-actions">${button('إضاءة', 'highlight')}${button('إخفاء', 'hide')}${button('اختار الأب', 'parent')}</div>
        <div class="ffield"><label for="uieNote">ملاحظة</label><textarea id="uieNote" class="ftextarea" rows="3" maxlength="2000" data-editor-field="note"></textarea></div>
        <div class="ffield"><label for="uieTitle">عنوان الطلب</label><input id="uieTitle" class="finput" maxlength="180" value="تعديل شكل الصفحة" data-editor-title></div>
        </aside><div class="uie-outline" data-editor-outline hidden></div><div class="uie-hover" data-editor-hover hidden></div>`;
    document.body.append(root);
    const panel = root.querySelector('.uie-panel');
    const outline = root.querySelector('[data-editor-outline]');
    const hoverBox = root.querySelector('[data-editor-hover]');
    const field = name => root.querySelector(`[data-editor-field="${name}"]`);
    const actionButton = name => root.querySelector(`[data-editor-action="${name}"]`);
    for (const corner of ['nw', 'ne', 'sw', 'se']) {
        const handle = document.createElement('button');
        handle.type = 'button'; handle.className = 'uie-handle uie-' + corner;
        handle.dataset.editorCorner = corner;
        handle.setAttribute('aria-label', 'غيّر المقاس من الركن ' + corner);
        outline.append(handle);
    }
    function say(message) { root.querySelector('[data-editor-message]').textContent = message; }
    function family(el) { return families.find(f => el.classList.contains(f)); }
    function eligible(node) {
        if (!(node instanceof Element) || node.closest('[data-ui-editor]')) return null;
        return node.closest(targets);
    }
    function unique(selector, el) {
        try { const matches = document.querySelectorAll(selector); return matches.length === 1 && matches[0] === el; }
        catch (_) { return false; }
    }
    function anchor(el) {
        if (el.id && unique('#' + CSS.escape(el.id), el)) return '#' + CSS.escape(el.id);
        if (el.hasAttribute('data-ui-key')) {
            const key = `[data-ui-key="${CSS.escape(el.getAttribute('data-ui-key'))}"]`;
            if (unique(key, el)) return key;
        }
        return '';
    }
    function stableSelector(el) {
        const direct = anchor(el);
        if (direct) return direct;
        const f = family(el);
        if (f && unique('.' + f, el)) return '.' + f;
        const parts = [];
        let node = el;
        while (node && node !== document.documentElement) {
            const fixed = anchor(node);
            if (fixed) { parts.unshift(fixed); break; }
            let part = node.tagName.toLowerCase();
            const base = family(node);
            if (base) part += '.' + base;
            const siblings = node.parentElement ? [...node.parentElement.children].filter(s => s.tagName === node.tagName) : [];
            if (siblings.length > 1) part += `:nth-of-type(${siblings.indexOf(node) + 1})`;
            parts.unshift(part);
            const candidate = parts.join(' > ');
            if (unique(candidate, el)) return candidate;
            node = node.parentElement;
        }
        return parts.join(' > ');
    }
    // قبل أي تغيير: نوع الزرار أو كلاس الإضاءة مايغيّرش عنوان العنصر.
    document.querySelectorAll(targets).forEach(el => {
        if (!el.closest('[data-ui-editor]')) selectors.set(el, stableSelector(el));
    });
    function record(el) {
        if (records.has(el)) return records.get(el);
        const rect = el.getBoundingClientRect();
        const from = {classes: [...el.classList], width: Math.max(1, el.offsetWidth || rect.width), height: Math.max(1, el.offsetHeight || rect.height)};
        const rec = {
            el, style: el.getAttribute('style'), classes: el.getAttribute('class'), hidden: el.hidden,
            transform: getComputedStyle(el).transform, ratio: from.width / from.height, locked: false,
            edit: {selector: selectors.get(el) || stableSelector(el), family: family(el),
                label: Array.from((el.textContent || el.getAttribute('aria-label') || el.getAttribute('placeholder') || el.name || el.tagName).trim().replace(/\s+/g, ' ')).slice(0, 40).join(''),
                from, to: {...from, classes: [...from.classes], x: 0, y: 0, fhl: el.classList.contains('fhl'), hidden: el.hidden}, note: ''}
        };
        records.set(el, rec); return rec;
    }
    function restore(rec) {
        if (rec.style === null) rec.el.removeAttribute('style'); else rec.el.setAttribute('style', rec.style);
        if (rec.classes === null) rec.el.removeAttribute('class'); else rec.el.setAttribute('class', rec.classes);
        rec.el.hidden = rec.hidden;
    }
    function snapshot() {
        history.push([...records].map(([el, rec]) => [el, rec, JSON.parse(JSON.stringify(rec.edit)), rec.locked]));
        if (history.length > 100) history.shift();
    }
    function apply(rec) {
        const to = rec.edit.to, el = rec.el;
        // الكلاسات الأصلية زي ما هي، بنبدّل أنواع نفس العيلة بس.
        el.className = to.classes.join(' ');
        el.classList.toggle('fhl', to.fhl);
        to.classes = [...el.classList];
        el.style.setProperty('box-sizing', 'border-box', 'important');
        el.style.setProperty('width', to.width + 'px', 'important');
        el.style.setProperty('height', to.height + 'px', 'important');
        el.style.setProperty('min-width', '0', 'important');
        el.style.setProperty('min-height', '0', 'important');
        el.style.setProperty('max-width', 'none', 'important');
        el.style.setProperty('max-height', 'none', 'important');
        el.style.setProperty('flex-shrink', '0', 'important');
        el.style.setProperty('transform', `translate(${to.x}px, ${to.y}px)${rec.transform === 'none' ? '' : ' ' + rec.transform}`, 'important');
        el.hidden = to.hidden;
        if (to.hidden) el.style.setProperty('display', 'none', 'important');
        else { el.style.removeProperty('display'); if (rec.style !== null) {
            const original = document.createElement('div'); original.setAttribute('style', rec.style);
            if (original.style.display) el.style.setProperty('display', original.style.display, original.style.getPropertyPriority('display'));
        } }
        draw();
    }
    function position(box, el) {
        box.hidden = !el || !el.isConnected || el.hidden;
        if (box.hidden) return;
        const r = el.getBoundingClientRect();
        box.style.left = r.left + 'px'; box.style.top = r.top + 'px';
        box.style.width = r.width + 'px'; box.style.height = r.height + 'px';
    }
    function draw() { position(outline, selected); position(hoverBox, hover === selected ? null : hover); }
    function refresh() {
        panel.hidden = !selected;
        if (!selected) { draw(); return; }
        const rec = record(selected), edit = rec.edit;
        root.querySelector('[data-editor-label]').textContent = edit.family + ' · ' + edit.label;
        field('width').value = edit.to.width; field('height').value = edit.to.height;
        field('note').value = edit.note;
        const variants = config.variants[edit.family];
        field('variant').innerHTML = '<option value="">النوع الأساسي</option>' + variants.map(v => `<option value="${esc(v)}">${esc(v)}</option>`).join('');
        field('variant').value = variants.find(v => edit.to.classes.includes(v)) || '';
        actionButton('ratio').setAttribute('aria-pressed', String(rec.locked));
        actionButton('highlight').setAttribute('aria-pressed', String(edit.to.fhl));
        actionButton('hide').setAttribute('aria-pressed', String(edit.to.hidden));
        actionButton('hide').textContent = edit.to.hidden ? 'إظهار' : 'إخفاء';
        draw();
    }
    function choose(el) { selected = el; if (el) record(el); refresh(); }
    function resize(rec, width, height, source) {
        width = Math.max(1, Math.min(20000, width)); height = Math.max(1, Math.min(20000, height));
        if (rec.locked) {
            if (source === 'height') width = height * rec.ratio;
            else height = width / rec.ratio;
            const scale = Math.min(1, 20000 / width, 20000 / height);
            width *= scale; height *= scale;
        }
        // النسبة دايمًا من المقاس الأصلي، والتقريب للعرض بس.
        rec.edit.to.width = Math.round(width * 100) / 100;
        rec.edit.to.height = Math.round(height * 100) / 100;
        apply(rec);
        field('width').value = rec.edit.to.width; field('height').value = rec.edit.to.height;
    }
    function changed(rec) {
        const e = rec.edit, a = e.from, b = e.to;
        return e.note !== '' || b.x !== 0 || b.y !== 0 || b.width !== a.width || b.height !== a.height || b.hidden !== rec.hidden || b.classes.join(' ') !== a.classes.join(' ');
    }
    function undo() {
        const prior = history.pop(); if (!prior) return;
        records.forEach(restore);
        records.clear();
        prior.forEach(([el, rec, edit, locked]) => { records.set(el, rec); rec.edit = edit; rec.locked = locked; if (changed(rec)) apply(rec); });
        refresh(); say('رجّعنا آخر تعديل.');
    }
    async function save() {
        if (busy) return;
        const edits = [...records.values()].filter(changed).map(rec => rec.edit);
        if (!edits.length) { say('مفيش تعديلات تتحفظ لسه.'); return; }
        const title = root.querySelector('[data-editor-title]').value.trim();
        if (!title) { say('اكتب عنوان الطلب الأول.'); return; }
        busy = true; actionButton('save').disabled = true;
        try {
            const page = new URL(location.href); page.searchParams.delete('ui_edit'); page.searchParams.delete('req');
            const response = await window.apiFetch(new URL('ui_edits_api.php?action=save', document.baseURI).href, {
                method: 'POST', body: {page: page.pathname + page.search, title, edits, viewport_w: window.innerWidth}
            });
            say('اتحفظ طلب رقم ' + response.data.id + '. تقدر تكمّل وتحفظ نسخة جديدة.');
        } catch (error) { say(error.message || 'الحفظ ماكملش، حاول تاني.'); }
        finally { busy = false; actionButton('save').disabled = false; }
    }
    root.addEventListener('click', event => {
        const buttonEl = event.target.closest('[data-editor-action]');
        if (!buttonEl) return;
        const action = buttonEl.dataset.editorAction;
        if (action === 'choose') { choose(null); say('اختار مكوّن من الصفحة.'); return; }
        if (action === 'save') { save(); return; }
        if (action === 'undo') { undo(); return; }
        if (action === 'reset') { snapshot(); records.forEach(restore); records.clear(); choose(null); say('اتلغت كل المعاينة.'); return; }
        if (action === 'exit') {
            if ([...records.values()].some(changed) && !window.confirm('تخرج من غير حفظ نسخة جديدة؟')) return;
            const url = new URL(location.href); url.searchParams.delete('ui_edit'); url.searchParams.delete('req'); location.assign(url); return;
        }
        if (!selected) return;
        const rec = record(selected);
        if (action === 'parent') { choose(eligible(selected.parentElement)); return; }
        if (action === 'ratio') { rec.locked = !rec.locked; refresh(); return; }
        snapshot();
        if (action === 'highlight') rec.edit.to.fhl = !rec.edit.to.fhl;
        if (action === 'hide') rec.edit.to.hidden = !rec.edit.to.hidden;
        apply(rec); refresh();
    });
    root.addEventListener('change', event => {
        if (!selected || !event.target.dataset.editorField) return;
        const rec = record(selected), key = event.target.dataset.editorField;
        snapshot();
        if (key === 'width' || key === 'height') {
            const value = Number(event.target.value);
            if (!Number.isFinite(value) || value < 1) { refresh(); return; }
            resize(rec, key === 'width' ? value : rec.edit.to.width, key === 'height' ? value : rec.edit.to.height, key);
        } else if (key === 'variant') {
            const variants = config.variants[rec.edit.family], value = event.target.value;
            if (value && !variants.includes(value)) return;
            // btn-sm حجم مستقل، فاختيار لون جديد مابيلغيش الحجم الصغير.
            if (value === 'btn-sm') rec.edit.to.classes = rec.edit.to.classes.includes(value) ? rec.edit.to.classes.filter(c => c !== value) : [...rec.edit.to.classes, value];
            else {
                rec.edit.to.classes = rec.edit.to.classes.filter(c => !variants.includes(c) || (c === 'btn-sm' && value));
                if (value) rec.edit.to.classes.push(value);
            }
            apply(rec); refresh();
        } else if (key === 'note') rec.edit.note = event.target.value;
    });
    // على window في مرحلة capture، قبل أحداث الصفحة الأصلية بما فيها onclick والفورم.
    const stop = event => { event.preventDefault(); event.stopImmediatePropagation(); };
    window.addEventListener('click', event => {
        if (root.contains(event.target)) return;
        stop(event);
        if (dragged) { dragged = false; return; }
        choose(eligible(event.target));
    }, true);
    for (const type of ['dblclick', 'auxclick', 'submit', 'mousedown', 'mouseup', 'touchstart', 'touchend']) {
        window.addEventListener(type, event => { if (!root.contains(event.target)) stop(event); }, {capture: true, passive: false});
    }
    window.addEventListener('pointerdown', event => {
        const corner = event.target.dataset.editorCorner;
        if (root.contains(event.target) && !corner) return;
        stop(event);
        const el = corner ? selected : eligible(event.target);
        if (!el || event.button !== 0) return;
        choose(el); snapshot();
        dragged = false;
        const rec = record(el);
        gesture = {rec, corner, px: event.clientX, py: event.clientY, start: {...rec.edit.to}};
    }, true);
    window.addEventListener('pointermove', event => {
        if (!gesture) { hover = eligible(event.target); draw(); return; }
        stop(event);
        const {rec, corner, px, py, start} = gesture;
        const dx = event.clientX - px, dy = event.clientY - py;
        if (Math.abs(dx) + Math.abs(dy) > 2) dragged = true;
        if (corner) {
            const width = start.width + (corner.includes('w') ? -dx : dx);
            const height = start.height + (corner.includes('n') ? -dy : dy);
            resize(rec, width, height, Math.abs(dx) >= Math.abs(dy) ? 'width' : 'height');
            rec.edit.to.x = start.x + (corner.includes('w') ? start.width - rec.edit.to.width : 0);
            rec.edit.to.y = start.y + (corner.includes('n') ? start.height - rec.edit.to.height : 0);
        } else { rec.edit.to.x = start.x + dx; rec.edit.to.y = start.y + dy; }
        apply(rec);
    }, true);
    window.addEventListener('pointerup', event => {
        if (gesture) { stop(event); gesture = null; refresh(); }
        else if (!root.contains(event.target)) stop(event);
    }, true);
    window.addEventListener('pointercancel', () => { gesture = null; }, true);
    window.addEventListener('keydown', event => {
        if (root.contains(event.target)) return;
        const arrows = {ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1]};
        if (event.key === 'Tab') { stop(event); actionButton('choose').focus(); return; }
        stop(event);
        if (!selected) return;
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') { undo(); return; }
        if (event.key === 'Escape') { choose(null); return; }
        if (!arrows[event.key]) return;
        snapshot(); const rec = record(selected), step = event.shiftKey ? 10 : 1;
        rec.edit.to.x += arrows[event.key][0] * step; rec.edit.to.y += arrows[event.key][1] * step;
        apply(rec);
    }, true);
    for (const type of ['keyup', 'keypress']) {
        window.addEventListener(type, event => { if (!root.contains(event.target)) stop(event); }, true);
    }
    window.addEventListener('scroll', draw, true);
    window.addEventListener('resize', draw);
    async function reopen() {
        const id = new URL(location.href).searchParams.get('req');
        if (!id) return;
        try {
            const response = await window.apiFetch(new URL('ui_edits_api.php?action=get&id=' + encodeURIComponent(id), document.baseURI).href);
            const request = response.data.request;
            if (!request) { say('الطلب ده مش موجود.'); return; }
            const expected = new URL(request.page, location.origin);
            const here = new URL(location.href); here.searchParams.delete('ui_edit'); here.searchParams.delete('req');
            const sorted = url => { url.searchParams.sort(); return url.pathname + url.search; };
            if (expected.origin !== location.origin || sorted(expected) !== sorted(here)) { say('الطلب ده يخص صفحة أو فلاتر مختلفة. افتحه من قايمة الطلبات.'); return; }
            root.querySelector('[data-editor-title]').value = request.title;
            let missed = 0;
            snapshot();
            // بنحل كل العناوين قبل تطبيق أي مقاس أو كلاس.
            const resolved = request.edits.map(edit => {
                try { const matches = document.querySelectorAll(edit.selector); return [edit, matches.length === 1 ? matches[0] : null]; }
                catch (_) { return [edit, null]; }
            });
            resolved.forEach(([edit, el]) => {
                if (!el || root.contains(el) || family(el) !== edit.family) { missed++; return; }
                const rec = record(el);
                rec.edit = JSON.parse(JSON.stringify(edit));
                rec.ratio = edit.from.width / edit.from.height;
                apply(rec); selected = el;
            });
            refresh();
            say('فتحنا الطلب ' + id + (missed ? '؛ فيه ' + missed + ' عنصر اتغيّر مكانه في الكود وماتلاقاش.' : '.') + (Number(request.viewport_w) !== innerWidth ? ' عرض الشاشة مختلف عن وقت الحفظ.' : ''));
        } catch (error) { say(error.message || 'فتح الطلب ماكملش.'); }
    }
    reopen();
})();
