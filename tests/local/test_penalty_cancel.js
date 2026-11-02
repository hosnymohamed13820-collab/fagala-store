// تشغيل كود المودال الحقيقي على DOM صغير عشان نفحص الافتراضي وإعادة الفتح واختيار الإشعار.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
for (const root of ['site', 'ashraqa']) {
    const source = fs.readFileSync(`${root}/hr.php`, 'utf8');
    const start = source.indexOf('    function hrOpenPenalty(button)');
    const end = source.indexOf("    document.querySelectorAll('.modal-bg')", start);
    const nodes = new Map();
    function element(id) {
        if (!nodes.has(id)) nodes.set(id, {value: '', disabled: false, hidden: false, textContent: '',
            classList: {add() {}}, focus() {}});
        return nodes.get(id);
    }
    const add = [element('pnEmp'), element('pnAmount'), element('pnDate'), element('pnReason')];
    const save = element('save');
    const quiet = element('quiet'); quiet.dataset = {notify: '0'};
    const loud = element('loud'); loud.dataset = {notify: '1'};
    const buttons = [save, quiet, loud];
    const form = {
        elements: {action: element('action'), penalty_id: element('penalty_id'), notify: element('notify')},
        reset() {this.elements.notify.value = '0'; element('pnCancelReason').value = '';},
        querySelectorAll() {return buttons;},
        addEventListener(type, fn) {this.submit = fn;}
    };
    const modal = element('penaltyModal');
    modal.querySelector = selector => selector === 'form' ? form : element('title');
    const document = {
        getElementById: element,
        querySelectorAll(selector) {
            if (selector.startsWith('#pnAddFields')) return add;
            if (selector.startsWith('#pnAddActions')) return [save];
            if (selector.startsWith('#pnCancelActions')) return [quiet, loud];
            throw new Error(selector);
        }
    };
    const context = vm.createContext({document});
    vm.runInContext(source.slice(start, end), context);
    const row = {dataset: {penaltyId: '17', employee: '<script>اسم</script>', amount: '50.00', reason: 'سبب أصلي'}};
    context.hrOpenPenalty(row);
    assert.equal(form.elements.action.value, 'cancel_penalty');
    assert.equal(form.elements.penalty_id.value, '17');
    assert.equal(form.elements.notify.value, '0');
    assert.equal(element('pnCancelEmployee').textContent, row.dataset.employee);
    assert.equal(element('pnOriginalReason').textContent, row.dataset.reason);
    assert.ok(add.every(el => el.disabled));
    assert.equal(element('pnCancelReason').disabled, false);
    assert.equal(element('pnAddActions').hidden, true);
    assert.equal(save.disabled, true);
    form.submit.call(form, {submitter: quiet});
    assert.equal(form.elements.notify.value, '0');
    assert.ok(buttons.every(b => b.disabled));
    context.hrOpenPenalty(row);
    form.submit.call(form, {submitter: loud});
    assert.equal(form.elements.notify.value, '1');
    context.hrOpenPenalty(row);
    assert.equal(form.elements.notify.value, '0');
    assert.equal(loud.disabled, false);
    form.submit.call(form, {});
    assert.equal(form.elements.notify.value, '0');
    context.hrOpenPenalty();
    assert.equal(form.elements.action.value, 'add_penalty');
    assert.ok(add.every(el => !el.disabled));
    assert.equal(element('pnCancelReason').disabled, true);
    assert.equal(element('pnCancelActions').hidden, true);
    assert.equal(save.disabled, false);
    assert.equal(loud.disabled, true);
    assert.equal((source.match(/id="penaltyModal"/g) || []).length, 1);
    console.log(`${root}: modal PASS — quiet/default/Enter, notify, reopen, add mode, duplicate-submit controls.`);
}
