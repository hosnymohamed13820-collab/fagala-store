// اختبار تشغيل السكربت من غير متصفح: التحميل المؤجل والرابط وحفظ صنف الموقع.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');

async function scenario(source, canEdit = true, classes = {}) {
    const elements = new Map(), events = {}, requests = [];
    function element(id) {
        if (!elements.has(id)) elements.set(id, {
            dataset: {}, hidden: id === 'imCard' || id === 'imCreate',
            innerHTML: '', textContent: '', value: '', children: [], handlers: {},
            addEventListener(event, fn) { this.handlers[event] = fn; },
            querySelectorAll() { return []; },
            appendChild(child) { this.children.push(child); },
            setAttribute() {}, focus() {}
        });
        return elements.get(id);
    }
    element('imApp').dataset.boot = JSON.stringify({canEdit, canPrices: true, canMovement: true});
    const item = {source, readonly: source === 'true', cost_unlocked: false,
        main: {id: 835, name1: 'صنف اختبار', base_unit: 'قطعة', class_names: classes},
        codes: [{id: 835, code: '1', name1: 'صنف اختبار', code_type: 0,
            unit_factor: 1, true_price: 4, site_price: null, cost: null}],
        history: [], suppliers: []};
    const context = {
        URLSearchParams, setTimeout, clearTimeout, console,
        location: {search: '?source=' + source + '&id=835', pathname: '/item_master.php'},
        history: {replaceState() {}},
        document: {getElementById: element, querySelectorAll: () => [],
            createElement: () => element('new-row'),
            addEventListener: (name, fn) => events[name] = fn},
        apiFetch: async (url, options) => {
            requests.push({url, options});
            if (url.includes('part=categories')) return {data: {options: {}}};
            if (url.includes('part=detail')) return {data: {item}};
            if (url.includes('part=search')) return {data: {rows: [{source, id: 835, code: '1',
                name1: 'صنف اختبار', sale_price: 4, balance: 191}], pages: 1, page: 1, total: 1}};
            return {data: {saved: true}};
        }
    };
    vm.runInNewContext(fs.readFileSync('site/assets/item_master.js', 'utf8'), context);
    assert.equal(requests.length, 0, 'مفيش API قبل DOMContentLoaded');
    events.DOMContentLoaded();
    await new Promise(setImmediate);
    assert.equal(element('imCard').hidden, false);
    assert.equal(element('imSearchView').hidden, true);
    assert.equal(element('imCode').textContent, '1');
    assert.equal(element('imTitle').textContent, 'صنف اختبار');
    assert.equal(element('imData').innerHTML.includes('تكلفة/أساسي'), false);
    assert.equal(element('imCompany').innerHTML.includes('data-field'), false);
    assert.equal(element('imData').innerHTML.includes('data-field="name1"'), source === 'site' && canEdit);
    if (source === 'true') {
        for (const name of Object.values(classes)) assert.ok(element('imData').innerHTML.includes(name));
        assert.equal(element('imData').innerHTML.includes('لسه مااتسحبتش من True'), Object.keys(classes).length < 4);
    }
    if (source === 'site' && canEdit) {
        const field = {type: 'text', value: 'اسم اتعدل', dataset: {field: 'name1', old: 'صنف اختبار'},
            matches: selector => selector === '[data-field]'};
        element('imCard').handlers.blur({target: field});
        await new Promise(setImmediate);
        const update = requests.find(r => r.url.includes('part=update'));
        assert.equal(update.options.body.id, 835);
        assert.equal(update.options.body.value, 'اسم اتعدل');
        assert.equal(field.dataset.old, 'اسم اتعدل');
    }
    element('imSearch').value = '1';
    element('imSearchBtn').handlers.click();
    await new Promise(setImmediate);
    assert.match(element('imResults').innerHTML, /role="button" tabindex="0" aria-expanded="false"/);
    assert.match(element('imResults').innerHTML, /class="ftable"/);
    assert.doesNotMatch(element('imResults').innerHTML, /data-xt-off/);
}

(async () => {
    await scenario('site');
    await scenario('true');
    await scenario('true', true, {description: 'وصف True', type: 'نوع True', stage: 'مرحلة True', company: 'شركة True'});
    await scenario('site', false);
    console.log('PASS: DOMContentLoaded + direct link + site edit/save + view only + sensitive lock');
})().catch(error => { console.error(error); process.exitCode = 1; });
