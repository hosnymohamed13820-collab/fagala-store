// فحص ربط الكروت من نفس كود المكتبة، من غير متصفح أو قاعدة.
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const source = fs.readFileSync('site/assets/xtable.js', 'utf8');
const functions = source.slice(source.indexOf('    function fmtNum('), source.indexOf('    function txt('))
    + source.slice(source.indexOf('    function kpiRaw('), source.indexOf("    document.addEventListener('xtable:filter'"));
const context = {};
vm.runInNewContext(functions, context);
function element(attrs = {}, html = '') {
    return {
        attrs: {...attrs}, innerHTML: html, className: '', nextElementSibling: null,
        hasAttribute(key) { return Object.hasOwn(this.attrs, key); },
        getAttribute(key) { return this.hasAttribute(key) ? this.attrs[key] : null; },
        setAttribute(key, value) { this.attrs[key] = String(value); },
        removeAttribute(key) { delete this.attrs[key]; },
        insertAdjacentElement(_, node) { this.nextElementSibling = node; },
        set textContent(value) { this.innerHTML = String(value); },
        get classList() {
            return {
                contains: cls => this.className.split(' ').includes(cls),
                toggle: (cls, on) => {
                    const classes = new Set(this.className.split(' ').filter(Boolean));
                    if (on) classes.add(cls); else classes.delete(cls);
                    this.className = [...classes].join(' ');
                }
            };
        }
    };
}
function row(value, extra = {}, footer = false) {
    const result = element({'data-v-x': String(value), ...extra});
    result.tagName = 'TR'; result.closest = () => footer ? {} : null;
    Object.defineProperty(result, 'textContent', {get() { throw Error('ممنوع قراية نص الخلية'); }});
    return result;
}
const rows = [row(10), row(20.5), row(-5), row(1000, {'data-xt-ignore': ''}), row(2000, {}, true)];
const sum = element({'data-xt-sum': 'x', 'data-xt-orig': '<bdi>6,400,000.00</bdi>'});
assert.strictEqual(context.kpiRaw(sum, rows), 25.5);
assert.strictEqual(context.kpiText(sum, rows, true), '25.50');
assert.strictEqual(context.kpiText(sum, rows, false), '<bdi>6,400,000.00</bdi>');
assert.strictEqual(context.kpiText(sum, [], true), '0.00');
assert.strictEqual(context.kpiText(element({'data-xt-count': ''}), rows, true), '3');
assert.strictEqual(context.kpiText(element({'data-xt-ratio': 'profit,revenue'}), [row(0, {'data-v-profit': '12', 'data-v-revenue': '0'})], true), '—');
assert.strictEqual(context.kpiText(element({'data-xt-ratio': 'profit,revenue'}), [row(0, {'data-v-profit': '5', 'data-v-revenue': '20'})], true), '25.00');
assert.strictEqual(context.kpiText(sum, [row('12,000')], true), '0.00');
assert.strictEqual(context.kpiText(element({'data-xt-sum': 'x', 'data-xt-dec': '3'}), rows, true), '25.500');

let handler;
const tableA = {}, tableB = {}, tables = {'#a': tableA, '#b': tableB};
const a = element({'data-xt-sum': 'x'}, '<bdi>100.00</bdi>');
const b = element({'data-xt-sum': 'x'}, '200.00');
const boxA = element({'data-xt-kpis': '#a'}), boxB = element({'data-xt-kpis': '#b'});
boxA.querySelectorAll = () => [a]; boxB.querySelectorAll = () => [b];
const document = {
    addEventListener(name, fn) { handler = fn; },
    querySelectorAll() { return [boxA, boxB]; },
    querySelector(selector) { return tables[selector]; },
    createElement() { return element(); }
};
context.document = document;
vm.runInNewContext(source.slice(source.indexOf("    document.addEventListener('xtable:filter'"), source.indexOf('    function rowKind(')), context);
handler({detail: {table: tableA, rows, active: false}});
assert.strictEqual(a.innerHTML, '<bdi>100.00</bdi>');
assert.strictEqual(a.getAttribute('data-xt-orig'), '<bdi>100.00</bdi>');
handler({detail: {table: tableA, rows, active: true}});
assert.strictEqual(a.innerHTML, '25.50');
assert.strictEqual(a.nextElementSibling.hidden, false);
assert.strictEqual(b.innerHTML, '200.00');
handler({detail: {table: tableB, rows: [], active: true}});
assert.strictEqual(a.innerHTML, '25.50');
assert.strictEqual(b.innerHTML, '0.00');
handler({detail: {table: tableA, rows, active: false}});
assert.strictEqual(a.innerHTML, '<bdi>100.00</bdi>');
assert.strictEqual(a.nextElementSibling.hidden, true);
tables['#a'] = {};
handler({detail: {table: tableA, rows, active: true}});
assert.strictEqual(a.innerHTML, '<bdi>100.00</bdi>');
handler({detail: {table: tables['#a'], rows, active: true}});
assert.strictEqual(a.innerHTML, '25.50');
console.log('20 KPI assertions passed: raw sums, zero ratio, original HTML, table isolation, dynamic replacement, ignored rows and footer.');
