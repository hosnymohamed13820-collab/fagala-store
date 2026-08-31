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
