<?php
/**
 * pos_devices.php — شاشة تسجيل أجهزة نقطة البيع (عرض فقط، الداتا والكتابة من الـAPI).
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/site_profile.php';
if (!site_profile()['true_integration']) { http_response_code(404); exit; }


require_once __DIR__ . '/auth.php';
require_module_access('pos');
if (!can('pos:devices')) {
    http_response_code(403);
    exit('مالكش صلاحية تسجيل أجهزة نقطة البيع.');
}

$page_title = 'أجهزة نقطة البيع';
$active_module = 'pos';
require_once __DIR__ . '/includes/header.php';
?>
<main class="content-container">
    <?= ui_page_head('أجهزة نقطة البيع', 'سجّل كل جهاز على فرعه. رقم الجهاز بيتولّد على السيرفر ومابيتغيّرش.') ?>

    <div id="posdevAlert"></div>

    <?= ui_card_open('إضافة جهاز') ?>
    <div class="fgrid fgrid-3">
        <?= ui_field('الفرع', ui_select('branch_id', [], '', ['id' => 'posdevBranch']), ['for' => 'posdevBranch']) ?>
        <?= ui_field('اسم الجهاز', ui_input('name', ['id' => 'posdevName', 'placeholder' => 'مثال: كاشير ١']), ['for' => 'posdevName']) ?>
        <?= ui_field('الكود القصير', ui_input('code', ['id' => 'posdevCode', 'placeholder' => 'مثال: K1', 'attrs' => ['maxlength' => 12, 'dir' => 'ltr']]), ['for' => 'posdevCode']) ?>
    </div>
    <div class="fcard-actions">
        <?= ui_btn('إضافة', ['id' => 'posdevAdd', 'icon' => 'plus']) ?>
    </div>
    <?= ui_card_close() ?>

    <?= ui_card_open('الأجهزة المسجّلة') ?>
    <div class="ftable-wrap">
        <table class="ftable" id="posdevTable" data-xt-off>
            <thead>
                <tr>
                    <th>اسم الجهاز</th>
                    <th>الكود</th>
                    <th>معرّف الجهاز</th>
                    <th>وضع الطباعة</th>
                    <th>آخر ظهور</th>
                    <th>الحالة</th>
                </tr>
            </thead>
            <tbody id="posdevBody"></tbody>
        </table>
    </div>
    <div id="posdevEmpty"></div>
    <?= ui_card_close() ?>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const ui = window.ui;
    const branch = document.getElementById('posdevBranch');
    const body = document.getElementById('posdevBody');
    const empty = document.getElementById('posdevEmpty');
    const alertBox = document.getElementById('posdevAlert');
    const addBtn = document.getElementById('posdevAdd');
    let firstLoad = true;

    function show(kind, message) {
        alertBox.innerHTML = ui.alert(kind, message);
    }

    function clearMessage() {
        alertBox.innerHTML = '';
    }

    function loadDevices() {
        const q = branch.value ? '&branch_id=' + encodeURIComponent(branch.value) : '';
        window.apiFetch('pos_devices_api.php?part=list' + q)
            .then(function (j) {
                const data = j.data || {};
                if (firstLoad) {
                    branch.innerHTML = (data.branches || []).map(function (b) {
                        return '<option value="' + Number(b.id) + '">' + ui.esc(b.name) + '</option>';
                    }).join('');
                    if (data.branch_id) branch.value = String(data.branch_id);
                    firstLoad = false;
                }
                render(data.devices || []);
            })
            .catch(function (e) { show('neg', e.message || 'تعذر تحميل الأجهزة.'); });
    }

    function render(rows) {
        body.innerHTML = rows.map(function (d) {
            const state = Number(d.is_active) === 1 ? '1' : '0';
            const printMode = d.print_mode === 'queue' ? 'queue' : 'browser';
            return '<tr data-id="' + Number(d.id) + '" data-branch="' + Number(d.branch_id) + '">' +
                '<td><input class="finput" name="name" value="' + ui.esc(d.name) + '" maxlength="120" data-field="name"></td>' +
                '<td><input class="finput" name="code" value="' + ui.esc(d.code) + '" maxlength="12" dir="ltr" data-field="code"></td>' +
                '<td class="num">' + ui.esc(d.device_uuid) + '</td>' +
                '<td><select class="fselect" name="print_mode" data-field="print_mode">' +
                    '<option value="browser"' + (printMode === 'browser' ? ' selected' : '') + '>مباشر من المتصفح</option>' +
                    '<option value="queue"' + (printMode === 'queue' ? ' selected' : '') + '>طابور الوكيل</option>' +
                '</select></td>' +
                '<td>' + ui.esc(d.last_seen_at || 'لسه ماظهرش') + '</td>' +
                '<td><select class="fselect" name="is_active" data-field="is_active">' +
                    '<option value="1"' + (state === '1' ? ' selected' : '') + '>مفعّل</option>' +
                    '<option value="0"' + (state === '0' ? ' selected' : '') + '>موقوف</option>' +
                '</select></td>' +
                '</tr>';
        }).join('');
        empty.innerHTML = rows.length ? '' : ui.empty('مفيش أجهزة مسجّلة في الفرع ده');
    }

    function saveRow(row) {
        clearMessage();
        if (row.dataset.saving === '1') {
            row.dataset.pendingSave = '1';
            return;
        }
        const payload = {
            device_id: Number(row.dataset.id),
            branch_id: Number(row.dataset.branch),
            name: row.querySelector('[data-field="name"]').value.trim(),
            code: row.querySelector('[data-field="code"]').value.trim(),
            print_mode: row.querySelector('[data-field="print_mode"]').value,
            is_active: Number(row.querySelector('[data-field="is_active"]').value)
        };
        row.dataset.saving = '1';
        window.apiFetch('pos_devices_api.php?part=update', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
        }).then(function () {
            show('pos', 'اتحفظ تعديل الجهاز.');
        }).catch(function (e) {
            show('neg', e.message || 'التعديل ماتحفظش.');
        }).finally(function () {
            row.dataset.saving = '0';
            if (row.dataset.pendingSave === '1') {
                row.dataset.pendingSave = '0';
                saveRow(row);
            }
        });
    }

    body.addEventListener('change', function (e) {
        if (!e.target.matches('select[data-field]')) return;
        const row = e.target.closest('tr[data-id]');
        if (row) saveRow(row);
    });
    body.addEventListener('focusout', function (e) {
        if (!e.target.matches('input[data-field]')) return;
        const row = e.target.closest('tr[data-id]');
        if (row) saveRow(row);
    });

    branch.addEventListener('change', loadDevices);
    addBtn.addEventListener('click', function () {
        clearMessage();
        const payload = {
            branch_id: Number(branch.value),
            name: document.getElementById('posdevName').value.trim(),
            code: document.getElementById('posdevCode').value.trim()
        };
        addBtn.disabled = true;
        window.apiFetch('pos_devices_api.php?part=add', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
        }).then(function () {
            document.getElementById('posdevName').value = '';
            document.getElementById('posdevCode').value = '';
            show('pos', 'اتضاف الجهاز واتولد معرّفه.');
            loadDevices();
        }).catch(function (e) {
            show('neg', e.message || 'الجهاز ماتضافش.');
        }).finally(function () { addBtn.disabled = false; });
    });

    loadDevices();
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
