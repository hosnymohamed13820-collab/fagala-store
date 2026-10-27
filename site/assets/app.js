/**
 * app.js — سلوكيات مشتركة لكل صفحات النظام
 * نظام الفجالة ستور المالي
 *
 * 1) سلايدر أفقي ورأسي تلقائي لأي <table>.
 * 2) التنقل بأسهم الكيبورد بين خانات الإدخال داخل أي جدول.
 * 3) إضافة صف جديد تلقائياً عند النزول بالسهم/Enter من آخر صف
 *    (للجداول اللي عليها class="repeatable" أو data-repeatable).
 *
 * يتحمّل تلقائياً في كل الصفحات عبر footer.php.
 */
(function () {
    'use strict';

    /* ============================================================
     * 1) لفّ الجداول للتمرير
     * ============================================================ */
    function wrapTables() {
        document.querySelectorAll('table').forEach(function (table) {
            var parent = table.parentElement;
            if (parent && parent.classList.contains('table-scroll')) return;
            var wrapper = document.createElement('div');
            wrapper.className = 'table-scroll';
            table.parentNode.insertBefore(wrapper, table);
            wrapper.appendChild(table);
        });
    }

    /* ============================================================
     * 2) + 3) التنقل بالأسهم وإضافة صف
     * ============================================================ */
    function buildGrid(table) {
        // مصفوفة [صف][عمود] للخانات القابلة للإدخال داخل tbody
        var body = table.tBodies && table.tBodies.length ? table.tBodies[0] : table;
        var rows = Array.prototype.slice.call(body.rows);
        var grid = [];
        rows.forEach(function (row) {
            // تخطّي أي صف مخفي (xt-out أو display:none أو غيره)
            if (row.offsetParent === null) return;
            var line = [];
            Array.prototype.slice.call(row.cells).forEach(function (cell) {
                var input = cell.querySelector('input:not([type=hidden]), select, textarea');
                line.push(input || null);
            });
            grid.push(line);
        });
        return grid;
    }

    // حركة رأسية: العمود يفضل ثابت، يدوّر على أول صف فوق/تحت فيه خانة صالحة في نفس العمود
    function focusVertical(grid, startR, c, step) {
        var r = startR;
        while (r >= 0 && r < grid.length) {
            var line = grid[r];
            if (line && c >= 0 && c < line.length) {
                var t = line[c];
                if (t && !t.disabled && !t.readOnly) {
                    t.focus();
                    if (t.select) { try { t.select(); } catch (e) {} }
                    return true;
                }
            }
            r += step;
        }
        return false;
    }

    // حركة أفقية: الصف يفضل ثابت، يدوّر في نفس الصف بالاتجاه المطلوب
    function focusHorizontal(grid, r, startC, step) {
        if (r < 0 || r >= grid.length) return false;
        var line = grid[r];
        if (!line) return false;
        var c = startC;
        while (c >= 0 && c < line.length) {
            var t = line[c];
            if (t && !t.disabled && !t.readOnly) {
                t.focus();
                if (t.select) { try { t.select(); } catch (e) {} }
                return true;
            }
            c += step;
        }
        return false;
    }

    function findPosition(grid, el) {
        for (var r = 0; r < grid.length; r++) {
            for (var c = 0; c < grid[r].length; c++) {
                if (grid[r][c] === el) return { r: r, c: c };
            }
        }
        return null;
    }

    // هل الجدول بيدعم إضافة صفوف؟ (class repeatable أو data-repeatable)
    function isRepeatable(table) {
        return table.classList.contains('repeatable') ||
               table.hasAttribute('data-repeatable');
    }

    // إضافة صف جديد بنسخ آخر صف وتفضية خاناته
    function addRow(table) {
        var body = table.tBodies && table.tBodies.length ? table.tBodies[0] : null;
        if (!body || !body.rows.length) return null;
        var last = body.rows[body.rows.length - 1];
        var clone = last.cloneNode(true);
        var newIndex = body.rows.length + 1;   // رقم الصف الجديد (1-based)

        // تفضية القيم في النسخة
        clone.querySelectorAll('input, select, textarea').forEach(function (inp) {
            if (inp.type === 'hidden') return;
            if (inp.tagName === 'SELECT') { inp.selectedIndex = 0; }
            else if (inp.type === 'checkbox' || inp.type === 'radio') { inp.checked = false; }
            else { inp.value = ''; }

            // ⚠️ الأهم: إعادة ترقيم الاسم.
            // cloneNode بينسخ الـ name زي ما هو، يعني الصف الجديد كان
            // بياخد نفس اسم آخر صف (مثلاً visa[10] مرتين). وقتها PHP
            // بيقرأ آخر قيمة بس والباقي بيضيع — فلوس بتختفي من التقفيل.
            // هنا بنزوّد الرقم اللي بين القوسين: visa[10] → visa[11].
            var nm = inp.getAttribute('name');
            if (nm) {
                var renamed = nm.replace(/\[(\d+)\]\s*$/, function (_, n) {
                    return '[' + newIndex + ']';
                });
                if (renamed !== nm) inp.setAttribute('name', renamed);
            }
            // نفس الحكاية مع id لو موجود (عشان الـlabels والسكربتات)
            var idAttr = inp.getAttribute('id');
            if (idAttr && /\d+$/.test(idAttr)) {
                inp.setAttribute('id', idAttr.replace(/\d+$/, String(newIndex)));
            }
        });

        body.appendChild(clone);

        // ترقيم تلقائي لو فيه خلية رقم تسلسلي (data-rownum)
        var numCell = clone.querySelector('[data-rownum]');
        if (numCell) numCell.textContent = body.rows.length;
        // بعض الجداول بتحط الرقم في أول خلية من غير data-rownum
        else {
            var firstCell = clone.cells && clone.cells.length ? clone.cells[0] : null;
            if (firstCell && !firstCell.querySelector('input, select, textarea') &&
                /^\s*\d+\s*$/.test(firstCell.textContent)) {
                firstCell.textContent = body.rows.length;
            }
        }
        return clone;
    }

    function handleKey(e) {
        var el = e.target;
        if (!el.matches || !el.matches('input, select, textarea')) return;
        // خانات توزيع الاستلامات ليها تنقّل خاص بيها (gridNav في invoices.php)
        // — من غير الاستثناء ده الهاندلرين بيتعاركوا والفوكس بيروح غلط.
        if (el.classList.contains('dist-input')) return;
        var table = el.closest('table');
        if (!table) return;

        var key = e.key;
        if (['ArrowUp','ArrowDown','ArrowLeft','ArrowRight','Enter'].indexOf(key) === -1) return;

        // في حقول النص: يمين/شمال يحرّكوا المؤشر جوّا النص
        var isText = el.tagName === 'INPUT' &&
                     ['text','search','url','tel','email','password'].indexOf(el.type) !== -1;
        if (isText && (key === 'ArrowLeft' || key === 'ArrowRight')) return;

        // 🔴 حماية خانات الأرقام من التغيير بالسهم فوق/تحت، وحماية الفورم من الإرسال بـ Enter
        var isNumber = el.tagName === 'INPUT' && el.type === 'number';
        if (isNumber && (key === 'ArrowUp' || key === 'ArrowDown')) {
            e.preventDefault();
        }
        if (key === 'Enter') {
            e.preventDefault();
        }

        var grid = buildGrid(table);
        var pos = findPosition(grid, el);
        if (!pos) return;

        var rtl = document.documentElement.dir === 'rtl';
        var moved = false;

        if (key === 'ArrowDown' || key === 'Enter') {
            moved = focusVertical(grid, pos.r + 1, pos.c, 1);
            if (!moved && isRepeatable(table)) {
                // آخر صف: ضيف صف جديد وانزل له في نفس العمود
                var newRow = addRow(table);
                if (newRow) {
                    var fresh = buildGrid(table);
                    moved = focusVertical(fresh, fresh.length - 1, pos.c, 1);
                }
            }
        } else if (key === 'ArrowUp') {
            moved = focusVertical(grid, pos.r - 1, pos.c, -1);
        } else if (key === 'ArrowRight') {
            var stepR = rtl ? -1 : 1;
            moved = focusHorizontal(grid, pos.r, pos.c + stepR, stepR);
        } else if (key === 'ArrowLeft') {
            var stepL = rtl ? 1 : -1;
            moved = focusHorizontal(grid, pos.r, pos.c + stepL, stepL);
        }

        if (moved) e.preventDefault();
    }

    function init() {
        wrapTables();
        document.addEventListener('keydown', handleKey);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

// مكوّنات HTML للصفحات اللي بترسم نتيجة الـAPI من الجافاسكربت.
(function () {
    'use strict';
    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];
        });
    }
    function icon(name) {
        name = String(name || '').toLowerCase().replace(/[^a-z0-9-]/g, '');
        return '<i class="ti ti-' + esc(name) + '"></i>';
    }
    function attrs(values) {
        return Object.keys(values || {}).filter(function (name) {
            return /^[a-zA-Z_:][a-zA-Z0-9_.:-]*$/.test(name) && values[name] != null && values[name] !== false;
        }).map(function (name) {
            return ' ' + esc(name) + (values[name] === true ? '' : '="' + esc(values[name]) + '"');
        }).join('');
    }
    function btn(label, o) {
        o = o || {};
        var variant = String(o.variant || 'primary').replace(/[^a-z0-9-]/g, '');
        var body = (o.icon ? icon(o.icon) + ' ' : '') + esc(label);
        var id = o.id ? ' id="' + esc(o.id) + '"' : '';
        if (o.href != null) return '<a class="btn btn-' + variant + '" href="' + esc(o.href) + '"' + id + attrs(o.attrs) + '>' + body + '</a>';
        return '<button class="btn btn-' + variant + '" type="' + esc(o.type || 'button') + '"' + id + attrs(o.attrs) + '>' + body + '</button>';
    }
    function chip(value, kind) {
        kind = ['pos', 'neg', 'warn', 'mute'].indexOf(kind) >= 0 ? kind : 'mute';
        return '<span class="fchip fchip-' + kind + '">' + esc(value) + '</span>';
    }
    function alert(kind, value) {
        var icons = {info:'alert-circle', warn:'alert-triangle', neg:'alert-circle', pos:'circle-check'};
        kind = icons[kind] ? kind : 'info';
        return '<div class="falert falert-' + kind + '">' + icon(icons[kind]) + '<div>' + esc(value) + '</div></div>';
    }
    function empty(value) {
        return '<div class="fg-empty">' + icon('inbox') + '<span>' + esc(value) + '</span></div>';
    }
    window.ui = {esc:esc, icon:icon, btn:btn, chip:chip, alert:alert, empty:empty};
})();

/* ============================================================
 * formSubmit — بديل `form.submit()` بيبعت حدث submit فعلاً
 * ------------------------------------------------------------
 *  🔴 البق اللي اتعمل عشانه (2026-09-01):
 *  `form.submit()` اللي بيتنادى من جافاسكربت **مابيبعتش حدث
 *  `submit`** — ده سلوك المتصفح نفسه مش بق عندنا. و`xtable`
 *  بيحفظ فلتر الجدول على الحدث ده بالظبط (قسم ١١ فيه).
 *
 *  النتيجة: أي `onchange="this.form.submit()"` — زي مبدّل الشهر
 *  في شاشة الرواتب — كان بيرجّع الصفحة **من غير الفلتر**، ومحمد
 *  يفضل يفلتر من أول وجديد كل مرة يغيّر التاريخ.
 *
 *  استخدم `formSubmit(this.form)` بدل `this.form.submit()` في أي
 *  فلتر بيبعت لوحده.
 *
 *  ⚠️ **مش** بديل عام: في `stocktake.php` و`attendance.php` فيه
 *  نداءات `form.submit()` **مقصودة** عشان تتخطى `onsubmit`
 *  (واحد منهم بيشيل الخاصية بإيده الأول). سيبهم زي ما هم.
 * ============================================================ */
window.formSubmit = function (form) {
    if (!form) return;
    /* requestSubmit بيبعت الحدث وبيشغّل تحقق HTML — ده اللي عايزينه */
    if (typeof form.requestSubmit === 'function') { form.requestSubmit(); return; }
    /* متصفح قديم (سفاري أقل من ١٦): ابعت الحدث بإيدك، وامشِ بس
       لو محدش لغاه — عشان `onsubmit="return confirm(...)"` يفضل شغّال */
    var ev;
    try { ev = new Event('submit', { bubbles: true, cancelable: true }); }
    catch (e) { form.submit(); return; }
    if (form.dispatchEvent(ev)) form.submit();
};

/* ============================================================
 * isEnterKey — فحص موحّد لزرار Enter يشتغل على كيبورد الموبايل
 * (Gboard وغيره بيبعتوا Go/Search/Next بدل Enter)
 * أي صفحة تستخدمه بدل event.key === 'Enter'
 *
 * ------------------------------------------------------------
 *  ⚠️ 2026-08-09 — بق خطير كان بيخلي كل حرف = Enter على أندرويد
 * ------------------------------------------------------------
 *  كان في القايمة 'Unidentified'. المشكلة إن كيبورد أندرويد
 *  (Gboard وأغلب العربي) شغّال بنظام IME، فبيبعت مع **كل حرف
 *  عادي** حدث keydown بـ key='Unidentified' و keyCode=229 —
 *  مش وقت Enter بس.
 *
 *  النتيجة: أول ما تكتب حرف، الصفحة تفتكره Enter وتنفّذ
 *  الأكشن وتفضّي الخانة. في طلبيات الكتب كان بيختار أول اقتراح
 *  لوحده ويمسح اللي اتكتب. وفي النواقص/الجرد/استعلام الأسعار
 *  كان بيدوّر على كود ناقص ويفشل من غير سبب واضح.
 *
 *  الحل: keyCode 229 معناها "مفتاح قيد التركيب" = مش Enter أبداً.
 *  و'Unidentified' بقت تتقبل بس لو معاها keyCode 13 (سكانر أو
 *  كيبورد بيبعت Enter من غير ما يسمّيه).
 * ------------------------------------------------------------ */
window.isEnterKey = function (ev) {
    // حرف قيد التركيب من الـ IME — مش Enter مهما كان اسم المفتاح
    if (ev.keyCode === 229 || ev.which === 229 || ev.isComposing) return false;

    var k  = ev.key || '';
    var kc = ev.keyCode || ev.which || 0;

    if (kc === 13) return true;                       // Enter الحقيقي
    return k === 'Enter' || k === 'Go' || k === 'Search'
        || k === 'Done'  || k === 'Next';
};

/* ============================================================
 * خانات الفلوس — فاصلة الآلاف وانت بتكتب
 * ------------------------------------------------------------
 *  الاستعمال: <input type="text" inputmode="decimal" data-money ...>
 *  و`moneyVal(el)` بترجّع الرقم النضيف لأي حساب أو رسالة تأكيد.
 *
 *  🔴 ليه type="text" مش type="number"؟
 *     خانة type="number" **مابتعرضش فاصلة** أصلاً — المتصفح بيعتبر
 *     "10,000" قيمة غلط ويرجّع value فاضية. مفيش حل تاني.
 *     inputmode="decimal" بيرجّع لوحة الأرقام على الموبايل زي ما كانت.
 *
 *  🔴 الكيرسر: من غير حساب مكانه، أول ما تتحط فاصلة الكيرسر بيطير
 *     لآخر الخانة وتبقى مستحيل تتعدّل من النص. بنعدّ الأرقام اللي
 *     قبل الكيرسر وبنرجّعه على نفس الرقم بعد التنسيق.
 * ============================================================ */
window.moneyVal = function (el) {
    if (!el) return 0;
    var n = parseFloat(String(el.value || '').replace(/,/g, '').trim());
    return isFinite(n) ? n : 0;
};

window.moneyFormat = function (raw) {
    var s = String(raw);
    // أرقام عربية (٠١٢٣) → إنجليزية، عشان الخانة ماتاكلش اللي كتبه
    s = s.replace(/[\u0660-\u0669]/g, function (d) { return String(d.charCodeAt(0) - 0x0660); });
    s = s.replace(/[^\d.]/g, '');          // أرقام ونقطة بس
    var i = s.indexOf('.');                 // نقطة عشرية واحدة لا أكتر
    if (i !== -1) s = s.slice(0, i + 1) + s.slice(i + 1).replace(/\./g, '');
    var parts = s.split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return parts.join('.');                 // الكسور من غير فواصل
};

window.initMoneyInputs = function (root) {
    (root || document).querySelectorAll('input[data-money]').forEach(function (el) {
        if (el.dataset.moneyReady) return;
        el.dataset.moneyReady = '1';

        el.addEventListener('input', function () {
            var before  = el.value.slice(0, el.selectionStart || 0);
            var keptBef = (before.match(/[\d.]/g) || []).length;
            el.value = window.moneyFormat(el.value);
            var pos = 0, seen = 0;
            while (pos < el.value.length && seen < keptBef) {
                if (/[\d.]/.test(el.value[pos])) seen++;
                pos++;
            }
            try { el.setSelectionRange(pos, pos); } catch (e) {}
        });

        /* 🔴 شبكة أمان: أي إرسال بيشيل الفواصل قبل ما القيمة تروح للسيرفر.
           PHP بتقرا (float)"10,000" = 10 — صرف عشرة آلاف يتسجّل عشرة.
           والسيرفر بيشيلها كمان (money_post) — الاتنين مقصودين. */
        var form = el.form;
        if (form && !form.dataset.moneyHook) {
            form.dataset.moneyHook = '1';
            form.addEventListener('submit', function () {
                var mons = form.querySelectorAll('input[data-money]');
                mons.forEach(function (m) { m.value = String(m.value || '').replace(/,/g, ''); });
                /* لو الإرسال اتلغى (تأكيد/تحقق) الفواصل ترجع للعرض.
                   الفورم بيتسلّم للمتصفح قبل ما الـtimeout ده يشتغل،
                   فاللي راح للسيرفر يفضل من غير فواصل. */
                setTimeout(function () {
                    mons.forEach(function (m) { m.value = window.moneyFormat(m.value); });
                }, 0);
            });
        }
    });
};

document.addEventListener('DOMContentLoaded', function () { window.initMoneyInputs(); });

/* ===== نظام التصميم ٢ب — توست · بوب أب · تحميل (بريف ٤٤) ===== */
(function () {
    var toastContainer = null;
    function getToastContainer() {
        if (!toastContainer || !document.body.contains(toastContainer)) {
            toastContainer = document.createElement('div');
            toastContainer.className = 'fg-toasts';
            toastContainer.setAttribute('role', 'status');
            toastContainer.setAttribute('aria-live', 'polite');
            document.body.appendChild(toastContainer);
        }
        return toastContainer;
    }

    window.fgToast = function (msg, type, ms) {
        if (!document.body) return null;
        var validTypes = { ok: 'ti-circle-check', err: 'ti-alert-circle', warn: 'ti-alert-triangle' };
        var t = (type && validTypes[type]) ? type : 'ok';
        var iconClass = validTypes[t];
        var duration = typeof ms === 'number' ? ms : (t === 'warn' ? 5000 : (t === 'err' ? 6000 : 3000));

        var container = getToastContainer();
        var toast = document.createElement('div');
        toast.className = 'fg-toast fg-toast-' + t;
        if (t === 'err') {
            toast.setAttribute('role', 'alert');
        }

        var icon = document.createElement('i');
        icon.className = 'ti ' + iconClass + ' fg-toast-icon';
        icon.setAttribute('aria-hidden', 'true');

        var text = document.createElement('span');
        text.className = 'fg-toast-text';
        text.textContent = String(msg || '');

        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'fg-toast-x';
        closeBtn.setAttribute('aria-label', 'قفل');
        var closeIcon = document.createElement('i');
        closeIcon.className = 'ti ti-x';
        closeBtn.appendChild(closeIcon);

        function removeToast() {
            if (toast.parentNode) {
                toast.classList.add('fg-toast-closing');
                setTimeout(function () {
                    if (toast.parentNode) {
                        toast.parentNode.removeChild(toast);
                    }
                }, 200);
            }
        }

        closeBtn.addEventListener('click', removeToast);

        toast.appendChild(icon);
        toast.appendChild(text);
        toast.appendChild(closeBtn);

        container.appendChild(toast);

        while (container.children.length > 4) {
            container.removeChild(container.firstElementChild);
        }

        if (duration > 0) {
            setTimeout(removeToast, duration);
        }

        return toast;
    };

    var dialogQueue = Promise.resolve();

    function showDialog(msg, opts) {
        return new Promise(function (resolve) {
            var prevFocus = null;
            try { prevFocus = document.activeElement; } catch (e) {}

            var bg = document.createElement('div');
            bg.className = 'fg-dlg-bg';

            var dlg = document.createElement('div');
            dlg.className = 'fg-dlg';
            dlg.setAttribute('role', 'dialog');
            dlg.setAttribute('aria-modal', 'true');

            var titleId = 'fg_dlg_title_' + Math.random().toString(36).substr(2, 9);
            var descId = 'fg_dlg_desc_' + Math.random().toString(36).substr(2, 9);
            dlg.setAttribute('aria-labelledby', titleId);
            dlg.setAttribute('aria-describedby', descId);

            var titleEl = document.createElement('h3');
            titleEl.className = 'fg-dlg-title';
            titleEl.id = titleId;
            titleEl.textContent = opts.title;

            var msgEl = document.createElement('p');
            msgEl.className = 'fg-dlg-msg';
            msgEl.id = descId;
            msgEl.textContent = msg || 'متأكد؟';

            var actions = document.createElement('div');
            actions.className = 'fg-dlg-actions';

            var okBtn = document.createElement('button');
            okBtn.type = 'button';
            okBtn.className = 'fg-dlg-btn fg-dlg-ok' + (opts.danger ? ' fg-dlg-danger' : '');
            okBtn.textContent = opts.okText;

            var cancelBtn = null;
            if (opts.showCancel) {
                cancelBtn = document.createElement('button');
                cancelBtn.type = 'button';
                cancelBtn.className = 'fg-dlg-btn fg-dlg-cancel';
                cancelBtn.textContent = opts.cancelText;
            }

            actions.appendChild(okBtn);
            if (cancelBtn) {
                actions.appendChild(cancelBtn);
            }

            dlg.appendChild(titleEl);
            dlg.appendChild(msgEl);
            dlg.appendChild(actions);
            bg.appendChild(dlg);

            var keyHandler = null;

            function closeWith(val) {
                if (keyHandler) {
                    document.removeEventListener('keydown', keyHandler, true);
                    keyHandler = null;
                }
                if (bg.parentNode) {
                    bg.parentNode.removeChild(bg);
                }
                if (!document.querySelector('.fg-dlg-bg')) {
                    if (document.documentElement) {
                        document.documentElement.classList.remove('fg-dlg-open');
                    }
                }
                try {
                    if (prevFocus && typeof prevFocus.focus === 'function' && document.body.contains(prevFocus)) {
                        prevFocus.focus();
                    }
                } catch (e) {}
                resolve(val);
            }

            okBtn.addEventListener('click', function () { closeWith(true); });
            if (cancelBtn) {
                cancelBtn.addEventListener('click', function () { closeWith(false); });
            }

            bg.addEventListener('click', function (e) {
                if (e.target === bg) {
                    closeWith(opts.showCancel ? false : true);
                }
            });

            keyHandler = function (e) {
                if (e.key === 'Escape') {
                    e.stopPropagation();
                    closeWith(opts.showCancel ? false : true);
                    return;
                }
                var isEnter = (typeof window.isEnterKey === 'function') ? window.isEnterKey(e) : (e.key === 'Enter');
                if (isEnter) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (cancelBtn && document.activeElement === cancelBtn) {
                        closeWith(false);
                    } else {
                        closeWith(true);
                    }
                    return;
                }
                if (e.key === 'Tab') {
                    var btns = cancelBtn ? [okBtn, cancelBtn] : [okBtn];
                    if (btns.length === 1) {
                        e.preventDefault();
                        e.stopPropagation();
                        btns[0].focus();
                        return;
                    }
                    e.stopPropagation();
                    if (e.shiftKey) {
                        if (document.activeElement === btns[0]) {
                            e.preventDefault();
                            btns[1].focus();
                        }
                    } else {
                        if (document.activeElement === btns[1]) {
                            e.preventDefault();
                            btns[0].focus();
                        }
                    }
                }
            };

            document.addEventListener('keydown', keyHandler, true);

            if (document.documentElement) {
                document.documentElement.classList.add('fg-dlg-open');
            }
            if (document.body) {
                document.body.appendChild(bg);
            }

            try { okBtn.focus(); } catch (e) {}
        });
    }

    function queueDialog(msg, opts) {
        var res = dialogQueue.then(function () {
            return showDialog(msg, opts);
        });
        dialogQueue = res.catch(function () {});
        return res;
    }

    window.fgAlert = function (msg, title) {
        return queueDialog(msg, {
            title: title || 'تنبيه',
            okText: 'تمام',
            cancelText: '',
            showCancel: false,
            danger: false
        }).then(function () {});
    };

    window.fgConfirm = function (msg, options) {
        var opt = options || {};
        return queueDialog(msg, {
            title: opt.title || 'تأكيد',
            okText: opt.okText || 'تمام',
            cancelText: opt.cancelText || 'رجوع',
            showCancel: true,
            danger: Boolean(opt.danger)
        });
    };

    window.fgBusy = function (button, on, label) {
        if (!button) return;
        if (on) {
            if (button.classList && button.classList.contains('fg-busy')) return;
            button.__fgHtml = button.innerHTML;
            button.__fgMinWidth = button.style ? button.style.minWidth : '';
            var w = button.offsetWidth;
            if (w && button.style) {
                button.style.minWidth = w + 'px';
            }
            if (button.classList) {
                button.classList.add('fg-busy');
            }
            button.setAttribute('aria-busy', 'true');
            button.setAttribute('aria-disabled', 'true');

            var origText = (button.textContent || '').trim();
            var textToShow = typeof label === 'string' ? label : origText;
            var html = '<i class="ti ti-loader ti-spin" aria-hidden="true"></i>';
            if (textToShow) {
                var span = document.createElement('span');
                span.textContent = ' ' + textToShow;
                html += span.outerHTML;
            }
            button.innerHTML = html;

            if (button.type === 'submit') {
                button.__fgTimer = setTimeout(function () {
                    button.disabled = true;
                }, 0);
            } else {
                button.disabled = true;
            }
        } else {
            if (!button.classList || !button.classList.contains('fg-busy')) return;
            if (button.__fgTimer) {
                clearTimeout(button.__fgTimer);
                delete button.__fgTimer;
            }
            if (typeof button.__fgHtml === 'string') {
                button.innerHTML = button.__fgHtml;
                delete button.__fgHtml;
            }
            if (button.style) {
                button.style.minWidth = button.__fgMinWidth || '';
                delete button.__fgMinWidth;
            }
            button.classList.remove('fg-busy');
            button.removeAttribute('aria-busy');
            button.removeAttribute('aria-disabled');
            button.disabled = false;
        }
    };

    window.addEventListener('pageshow', function (e) {
        if (e && e.persisted) {
            var busyBtns = document.querySelectorAll ? document.querySelectorAll('.fg-busy') : [];
            for (var i = 0; i < busyBtns.length; i++) {
                window.fgBusy(busyBtns[i], false);
            }
            var forms = document.querySelectorAll ? document.querySelectorAll('form') : [];
            for (var j = 0; j < forms.length; j++) {
                delete forms[j].__fgSending;
                delete forms[j].__fgConfirmed;
            }
        }
    });

    // فورم data-confirm — مرحلة الالتقاط
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || form.tagName !== 'FORM' || !form.hasAttribute('data-confirm')) return;
        if (form.__fgConfirmed) {
            delete form.__fgConfirmed;
            return;
        }
        var msg = form.getAttribute('data-confirm');
        if (typeof form.requestSubmit !== 'function') {
            if (!window.confirm(msg)) {
                e.preventDefault();
            }
            return;
        }
        e.preventDefault();
        e.stopPropagation();
        var sub = e.submitter;
        var opts = {
            title: form.getAttribute('data-confirm-title') || 'تأكيد',
            okText: form.getAttribute('data-confirm-ok') || 'تمام',
            cancelText: 'رجوع',
            danger: form.hasAttribute('data-confirm-danger')
        };
        var confFn = window.fgConfirm || function () { return Promise.resolve(true); };
        confFn(msg, opts).then(function (ok) {
            if (!ok) return;
            form.__fgConfirmed = true;
            if (sub && sub.form === form) {
                form.requestSubmit(sub);
            } else {
                form.requestSubmit();
            }
        });
    }, true);

    // فورم data-busy — مرحلة الفقاعة
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || form.tagName !== 'FORM' || !form.hasAttribute('data-busy')) return;
        if (form.__fgSending) {
            e.preventDefault();
            return;
        }
        if (e.defaultPrevented) return;
        form.__fgSending = true;
        var b = (e.submitter && e.submitter.form === form) ? e.submitter : null;
        if (!b && form.elements) {
            for (var i = 0; i < form.elements.length; i++) {
                if (form.elements[i].type === 'submit') {
                    b = form.elements[i];
                    break;
                }
            }
        }
        if (b) {
            var busyLabel = form.getAttribute('data-busy');
            window.fgBusy(b, true, busyLabel || undefined);
        }
    });
})();

/* ============================================================
 * apiFetch — استدعاء الـAPI الموحّد (بريف ١٠١)
 * ------------------------------------------------------------
 * يرسل ويستقبل JSON، ويضع X-CSRF-Token وIdempotency-Key
 * للعمليات غير GET، ويعيد المحاولة عند أخطاء الشبكة.
 * ============================================================ */
window.apiFetch = function (url, opts) {
    opts = Object.assign({}, opts);
    var method = (opts.method || 'GET').toUpperCase();
    var headers = Object.assign({}, opts.headers || {});

    // وضع Content-Type الافتراضي لو فيه body كائن ومش FormData
    if (opts.body && typeof opts.body === 'object' && !(opts.body instanceof FormData) && !(opts.body instanceof Blob)) {
        opts.body = JSON.stringify(opts.body);
        if (!headers['Content-Type'] && !headers['content-type']) {
            headers['Content-Type'] = 'application/json';
        }
    }

    if (method !== 'GET') {
        // X-CSRF-Token
        if (!headers['X-CSRF-Token'] && !headers['x-csrf-token']) {
            var metaCsrf = document.querySelector('meta[name="csrf-token"]');
            var csrfToken = (window.SENS && window.SENS.csrf) ? window.SENS.csrf : (metaCsrf ? metaCsrf.getAttribute('content') : '');
            if (csrfToken) {
                headers['X-CSRF-Token'] = csrfToken;
            }
        }

        // Idempotency-Key (يولّد مرة واحدة للنداء)
        if (!headers['Idempotency-Key'] && !headers['idempotency-key']) {
            var idemKey = '';
            if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
                idemKey = crypto.randomUUID();
            } else {
                idemKey = 'idem_' + Date.now() + '_' + Math.random().toString(36).substring(2, 15) + Math.random().toString(36).substring(2, 15);
            }
            headers['Idempotency-Key'] = idemKey;
        }
    }

    opts.headers = headers;

    var delays = [1000, 3000];
    var attempt = 0;

    function doFetch() {
        return fetch(url, opts)
            .then(function (response) {
                return response.json().catch(function () {
                    return {
                        ok: false,
                        error: 'استجابة غير صالحة من السيرفر',
                        code: 'server'
                    };
                }).then(function (json) {
                    if (json && json.ok) {
                        return json;
                    }
                    var msg = (json && json.error) ? json.error : 'حصل خطأ في العملية';
                    var err = new Error(msg);
                    err.error = msg;
                    err.code = (json && json.code) ? json.code : 'server';
                    err.status = response.status;
                    return Promise.reject(err);
                });
            })
            .catch(function (err) {
                // إعادة المحاولة عند أخطاء الشبكة فقط (بدون status كود)
                if (typeof err.status === 'undefined' && method !== 'GET' && attempt < delays.length) {
                    var delay = delays[attempt++];
                    return new Promise(function (resolve) {
                        setTimeout(resolve, delay);
                    }).then(doFetch);
                }
                return Promise.reject(err);
            });
    }

    return doFetch();
};

/* ============================================================
 * منتقي وبحث الموردين الموحد (بريف ١٢٩)
 * تطبيع الحروف العربية (أ/ا/إ/آ، ة/ه، ي/ى) مع رسالة عند عدم وجود نتايج
 * ============================================================ */
(function () {
    function fgNorm(s) {
        return String(s || '').toLowerCase()
            .replace(/[\u064B-\u0652\u0640]/g, '')
            .replace(/[\u0623\u0625\u0622\u0671]/g, '\u0627')
            .replace(/\u0649/g, '\u064A')
            .replace(/\u0629/g, '\u0647')
            .trim();
    }
    window.fgNorm = fgNorm;

    // تهيئة خانة بحث لقائمة عناصر (divs أو select options)
    function initSearchablePicker(input, target, itemSelector, emptyMsg) {
        if (!input || !target) return;
        emptyMsg = emptyMsg || 'مفيش مورد بالاسم ده';

        if (target.tagName === 'SELECT') {
            var select = target;
            var masterOptions = Array.prototype.slice.call(select.options);

            function filterSelect() {
                var q = fgNorm(input.value);
                var currentVal = select.value;
                var matchCount = 0;
                var hasSelected = false;

                select.innerHTML = '';

                masterOptions.forEach(function (opt) {
                    var val = opt.value;
                    var text = opt.text;
                    var isDefault = (val === '0' || val === '');
                    var match = isDefault || !q || fgNorm(text).indexOf(q) !== -1;

                    if (match) {
                        select.appendChild(opt);
                        if (!isDefault) matchCount++;
                        if (val === currentVal) hasSelected = true;
                    }
                });

                if (matchCount === 0 && q) {
                    var emptyOpt = document.createElement('option');
                    emptyOpt.disabled = true;
                    emptyOpt.selected = true;
                    emptyOpt.value = '';
                    emptyOpt.textContent = emptyMsg;
                    select.appendChild(emptyOpt);
                } else if (hasSelected) {
                    select.value = currentVal;
                } else if (select.options.length > 0) {
                    select.selectedIndex = 0;
                }
            }

            input.addEventListener('input', filterSelect);
            input.addEventListener('keydown', function (ev) {
                if (ev.key === 'Escape') {
                    input.value = '';
                    filterSelect();
                } else if (ev.key === 'Enter') {
                    ev.preventDefault();
                    var nonDefaultOpts = Array.prototype.slice.call(select.options).filter(function (o) {
                        return o.value !== '0' && o.value !== '' && !o.disabled;
                    });
                    if (nonDefaultOpts.length === 1) {
                        select.value = nonDefaultOpts[0].value;
                        select.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }
            });
            input._fgFilter = filterSelect;
        } else {
            var list = target;
            var emptyEl = list.querySelector('.picker-empty-msg');
            if (!emptyEl) {
                emptyEl = document.createElement('div');
                emptyEl.className = 'picker-empty-msg';
                emptyEl.style.cssText = 'padding:14px 10px;text-align:center;font-size:13px;color:var(--ink-500);display:none;';
                emptyEl.textContent = emptyMsg;
                list.appendChild(emptyEl);
            }

            function filterList() {
                var q = fgNorm(input.value);
                var items = list.querySelectorAll(itemSelector || '.trs-pick-opt, .cst-pick-opt, [data-name]');
                var matchCount = 0;

                items.forEach(function (it) {
                    var nm = it.getAttribute('data-name') || it.textContent;
                    var match = !q || fgNorm(nm).indexOf(q) !== -1;
                    it.style.display = match ? '' : 'none';
                    if (match) matchCount++;
                });

                emptyEl.style.display = (matchCount === 0) ? 'block' : 'none';
            }

            input.addEventListener('input', filterList);
            input.addEventListener('keydown', function (ev) {
                if (ev.key === 'Escape') {
                    input.value = '';
                    filterList();
                }
            });
            input._fgFilter = filterList;
        }
    }
    window.fgInitSearchablePicker = initSearchablePicker;

    function scanSearchablePickers(root) {
        root = root || document;

        root.querySelectorAll('input[data-picker-search]').forEach(function (inp) {
            if (inp._fgPickerReady) return;
            var targetSel = inp.getAttribute('data-picker-search') || inp.getAttribute('data-target');
            if (!targetSel) return;
            var target = document.querySelector(targetSel);
            if (!target) return;
            var itemSel = inp.getAttribute('data-item') || null;
            var emptyMsg = inp.getAttribute('data-empty') || 'مفيش مورد بالاسم ده';
            initSearchablePicker(inp, target, itemSel, emptyMsg);
            inp._fgPickerReady = true;
        });

        root.querySelectorAll('select[data-searchable]').forEach(function (sel) {
            if (sel._fgPickerReady) return;
            var inpSel = sel.getAttribute('data-search-input');
            var inp = inpSel ? document.querySelector(inpSel) : null;

            if (!inp) {
                inp = document.createElement('input');
                inp.type = 'text';
                inp.className = 'select-search-inp';
                inp.placeholder = sel.getAttribute('data-placeholder') || 'بحث في الموردين...';
                inp.style.cssText = 'padding:6px 10px;border:1.5px solid var(--line);border-radius:8px;font-family:inherit;font-size:13px;background:var(--surface);color:var(--ink-900);width:130px;box-sizing:border-box;margin-inline-end:6px;';
                sel.parentNode.insertBefore(inp, sel);
            }
            var emptyMsg = sel.getAttribute('data-empty') || 'مفيش مورد بالاسم ده';
            initSearchablePicker(inp, sel, null, emptyMsg);
            sel._fgPickerReady = true;
        });
    }
    window.fgScanPickers = scanSearchablePickers;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            scanSearchablePickers();
        });
    } else {
        scanSearchablePickers();
    }
})();

