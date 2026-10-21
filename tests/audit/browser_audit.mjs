// =====================================================================
//  browser_audit.mjs — فحص المتصفح بالـ Edge Headless + CDP (بريف ١٨٢ — الجزء ب)
//  نظام الفجالة ستور المالي
// =====================================================================
import { spawn } from 'node:child_process';
import { readFileSync, writeFileSync, rmSync, mkdtempSync, existsSync, readdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const outDir = process.argv[2] || 'D:/fagala_wt_ds2/tests/audit/out/latest';
const rendersDir = join(outDir, 'renders');

const PHP_BIN = process.env.PHP_BIN || 'D:/laragon/bin/php/php-8.3.33-Win32-vs16-x64/php.exe';
const HTTP_PORT = 8799;

console.log(`بدء تشغيل خادم PHP المحلي للأصول على منفذ ${HTTP_PORT}...`);
const phpServer = spawn(PHP_BIN, ['-S', `127.0.0.1:${HTTP_PORT}`, '-t', rendersDir], { stdio: 'ignore' });

const EDGE = [
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe'
].find(existsSync);

if (!EDGE) {
    console.error('❌ لم يتم العثور على متصفح Microsoft Edge');
    process.exit(1);
}

const W = 390, H = 844;
const prof = mkdtempSync(join(tmpdir(), 'audit-edge-'));
const port = 9400 + Math.floor(Math.random() * 500);

console.log(`بدء تشغيل Edge headless على منفذ ${port}...`);
const edge = spawn(EDGE, [
    '--headless=new',
    '--disable-gpu',
    `--remote-debugging-port=${port}`,
    `--user-data-dir=${prof}`,
    `--window-size=${W},${H}`,
    'about:blank'
], { stdio: 'ignore' });

const sleep = ms => new Promise(r => setTimeout(r, ms));

let wsUrl;
for (let i = 0; i < 50 && !wsUrl; i++) {
    await sleep(200);
    try {
        const t = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
        wsUrl = t.find(x => x.type === 'page')?.webSocketDebuggerUrl;
    } catch {}
}

if (!wsUrl) {
    console.error('❌ فشل الاتصال بـ Edge DevTools Protocol');
    edge.kill();
    process.exit(1);
}

const ws = new WebSocket(wsUrl);
await new Promise(r => ws.addEventListener('open', r, { once: true }));

let msgId = 0;
const pending = new Map();
const eventListeners = [];

ws.addEventListener('message', ev => {
    const m = JSON.parse(ev.data);
    if (m.id && pending.has(m.id)) {
        pending.get(m.id)(m);
        pending.delete(m.id);
    } else if (m.method) {
        eventListeners.filter(l => l.method === m.method).forEach(l => l.fn(m));
    }
});

const send = (method, params = {}) => new Promise(r => {
    const i = ++msgId;
    pending.set(i, r);
    ws.send(JSON.stringify({ id: i, method, params }));
});

const onEvent = method => new Promise(r => {
    const l = { method, fn: m => { eventListeners.splice(eventListeners.indexOf(l), 1); r(m); } };
    eventListeners.push(l);
});

await send('Page.enable');
await send('Runtime.enable');
await send('Log.enable');
await send('Network.enable');
await send('Emulation.setDeviceMetricsOverride', { width: W, height: H, deviceScaleFactor: 2, mobile: true });

// اسكربت الفحص الشامل داخل المتصفح
const AUDIT_IN_PAGE = `(() => {
    const W = document.documentElement.clientWidth;
    const res = {
        title: document.title,
        overflow_elements: [],
        missing_glyphs: [],
        dark_contrast_issues: [],
        table_issues: [],
        buttons: []
    };

    // ١. فحص السكرول بالعرض 390px
    const clipped = el => {
        for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
            if (/(auto|scroll|hidden)/.test(getComputedStyle(p).overflowX)) return true;
        }
        return false;
    };

    for (const el of document.querySelectorAll('body *')) {
        if (res.overflow_elements.length >= 8) break;
        if (el.closest('.sidebar, aside, [aria-hidden="true"]')) continue;
        const r = el.getBoundingClientRect();
        if (!r.width) continue;
        const cs = getComputedStyle(el);
        if (cs.position === 'fixed' || cs.display === 'none') continue;
        if ((r.right > W + 2 || r.left < -2) && !clipped(el)) {
            const cls = typeof el.className === 'string' ? el.className.trim().split(/\\s+/).slice(0, 2).join('.') : '';
            res.overflow_elements.push(el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (cls ? '.' + cls : '') + ' [' + Math.round(r.left) + '→' + Math.round(r.right) + ']');
        }
    }

    // ٢. فحص أيقونات ti-* بدون glyph
    for (const el of document.querySelectorAll('[class*="ti-"]')) {
        if (res.missing_glyphs.length >= 10) break;
        const cs = getComputedStyle(el, ':before');
        const content = cs.getPropertyValue('content');
        if (!content || content === 'none' || content === '""' || content === "''") {
            const cls = typeof el.className === 'string' ? el.className : '';
            res.missing_glyphs.push(cls);
        }
    }

    // ٣. فحص الجداول مش .ftable وخلايا الأرقام من غير .num
    for (const tbl of document.querySelectorAll('table')) {
        if (!tbl.classList.contains('ftable')) {
            res.table_issues.push('table without .ftable');
        }
        for (const td of tbl.querySelectorAll('td')) {
            if (res.table_issues.length >= 10) break;
            const txt = (td.textContent || '').trim();
            if (/^-?[0-9,]+(\\.[0-9]+)?$/.test(txt) && !td.classList.contains('num')) {
                res.table_issues.push('numeric cell without .num: ' + txt.slice(0, 15));
            }
        }
    }

    // ٤. فحص الوضع الليلي data-theme="dark" والتباين
    document.documentElement.setAttribute('data-theme', 'dark');
    const parseRgb = str => {
        const m = str.match(/rgba?\\((\\d+),\\s*(\\d+),\\s*(\\d+)/);
        return m ? [parseInt(m[1]), parseInt(m[2]), parseInt(m[3])] : null;
    };
    const luminance = rgb => {
        const a = rgb.map(v => {
            v /= 255;
            return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
        });
        return a[0] * 0.2126 + a[1] * 0.7152 + a[2] * 0.0722;
    };
    const contrast = (rgb1, rgb2) => {
        const l1 = luminance(rgb1) + 0.05;
        const l2 = luminance(rgb2) + 0.05;
        return l1 > l2 ? l1 / l2 : l2 / l1;
    };

    for (const el of document.querySelectorAll('p, span, a, td, th, h1, h2, h3, h4, h5, h6, label')) {
        if (res.dark_contrast_issues.length >= 10) break;
        const cs = getComputedStyle(el);
        if (cs.display === 'none' || cs.visibility === 'hidden') continue;
        const fg = parseRgb(cs.color);
        const bg = parseRgb(cs.backgroundColor);
        if (fg && bg) {
            const ratio = contrast(fg, bg);
            if (ratio < 3.0) {
                const txt = (el.textContent || '').trim().slice(0, 30);
                if (txt.length > 0) {
                    res.dark_contrast_issues.push({ text: txt, ratio: Math.round(ratio * 10) / 10 });
                }
            }
        }
    }
    document.documentElement.removeAttribute('data-theme');

    // ٥. جرد الأزرار
    for (const btn of document.querySelectorAll('button, a.btn, [class*="btn-"], [onclick]')) {
        let formAction = '', formMethod = '';
        if (btn.form) {
            formAction = btn.form.getAttribute('action') || '';
            formMethod = (btn.form.getAttribute('method') || 'GET').toUpperCase();
        }
        res.buttons.push({
            tag: btn.tagName.toLowerCase(),
            text: (btn.textContent || '').trim().slice(0, 50),
            onclick: btn.getAttribute('onclick') || '',
            form_action: formAction,
            form_method: formMethod,
            class: typeof btn.className === 'string' ? btn.className.trim() : ''
        });
    }

    return JSON.stringify(res);
})()`;

const findings = [];
const buttonsInventory = [];

const userTypes = ['owner_unlocked', 'cashier'];
let pagesCount = 0;

for (const uType of userTypes) {
    const userDir = join(rendersDir, uType);
    if (!existsSync(userDir)) continue;

    const files = readdirSync(userDir).filter(f => f.endsWith('.html'));
    console.log(`فحص صفحات المستخدم [${uType}] (${files.length} صفحة)...`);

    for (const file of files) {
        pagesCount++;
        const pageName = file.replace('.html', '');
        const filePath = join(userDir, file);

        const consoleErrors = [];
        const network404s = [];

        const logHandler = m => {
            if (m.params?.entry?.level === 'error') {
                consoleErrors.push(m.params.entry.text || 'Console error');
            }
        };
        const exceptionHandler = m => {
            const desc = m.params?.exceptionDetails?.text || m.params?.exceptionDetails?.exception?.description || 'Exception thrown';
            consoleErrors.push(desc);
        };
        const responseHandler = m => {
            if (m.params?.response?.status === 404) {
                network404s.push(m.params.response.url);
            }
        };

        eventListeners.push({ method: 'Log.entryAdded', fn: logHandler });
        eventListeners.push({ method: 'Runtime.exceptionThrown', fn: exceptionHandler });
        eventListeners.push({ method: 'Network.responseReceived', fn: responseHandler });

        const loadFired = onEvent('Page.loadEventFired');
        await send('Page.navigate', { url: `http://127.0.0.1:${HTTP_PORT}/${uType}/${file}` });
        await Promise.race([loadFired, sleep(2000)]);
        await sleep(150);

        // إزالة مستمعي الأحداث للصفحة الحالية
        eventListeners.splice(eventListeners.findIndex(l => l.fn === logHandler), 1);
        eventListeners.splice(eventListeners.findIndex(l => l.fn === exceptionHandler), 1);
        eventListeners.splice(eventListeners.findIndex(l => l.fn === responseHandler), 1);

        // التحقق أولاً من تحميل أصول التصميم والخطوط قبل القياس
        let assetCheck = { hasCss: true, hasTabler: true };
        try {
            const rawAsset = await send('Runtime.evaluate', {
                expression: `(() => {
                    const cs = getComputedStyle(document.body);
                    const ink900 = cs.getPropertyValue('--ink-900').trim();
                    const hasCss = ink900.length > 0 || /system-ui|Segoe/i.test(cs.fontFamily);
                    const hasTabler = document.fonts ? document.fonts.check('16px tabler-icons') : true;
                    return JSON.stringify({ hasCss, hasTabler });
                })()`,
                returnByValue: true
            });
            assetCheck = JSON.parse(rawAsset.result?.result?.value || '{}');
        } catch {}

        if (!assetCheck.hasCss || !assetCheck.hasTabler) {
            findings.push({
                severity: 'yellow',
                kind: 'asset_load_failed',
                file: `site/${pageName}.php`,
                line: 1,
                user: uType,
                url: pageName,
                detail: `لم يتم تحميل أصول التصميم كاملة (hasCss: ${assetCheck.hasCss}, hasTabler: ${assetCheck.hasTabler})`
            });
            continue; // لا تحسب مؤشرات التصميم
        }

        // تشغيل فحص الصفحة
        let evalResult;
        try {
            const rawEval = await send('Runtime.evaluate', { expression: AUDIT_IN_PAGE, returnByValue: true });
            evalResult = JSON.parse(rawEval.result?.result?.value || '{}');
        } catch (e) {
            evalResult = {};
        }

        // تسجيل أخطاء الكونسول (مع استبعاد طلبات fetch للأف بي آي المحلية)
        for (const ce of consoleErrors) {
            const isApiFetch = /_api\.php|Failed to fetch|NetworkError|net::ERR_|404.*_api/i.test(ce);
            if (isApiFetch) {
                // استبعادها من أخطاء الكونسول لأن الرندر ستاتيك
                continue;
            }
            findings.push({
                severity: 'orange',
                kind: 'browser_console_error',
                file: `site/${pageName}.php`,
                line: 1,
                user: uType,
                url: pageName,
                detail: `خطأ كونسول أو استثناء في الجافاسكربت: ${ce}`
            });
        }

        // تسجيل طلبات 404
        for (const n404 of network404s) {
            findings.push({
                severity: 'orange',
                kind: 'browser_resource_404',
                file: `site/${pageName}`,
                line: 1,
                user: uType,
                url: pageName,
                detail: `طلب مورد رجع 404: ${n404}`
            });
        }

        // تسجيل عناصر السكرول بالعرض
        if (evalResult.overflow_elements && evalResult.overflow_elements.length > 0) {
            findings.push({
                severity: 'yellow',
                kind: 'browser_overflow_390px',
                file: `site/${pageName}`,
                line: 1,
                user: uType,
                url: pageName,
                detail: `عناصر تخرج عن عرض الشاشة في الموبايل 390px: ${evalResult.overflow_elements.join('، ')}`
            });
        }

        // تسجيل الأيقونات بدون glyph
        for (const mg of evalResult.missing_glyphs || []) {
            findings.push({
                severity: 'yellow',
                kind: 'browser_missing_glyph',
                file: `site/${pageName}`,
                line: 1,
                user: uType,
                url: pageName,
                detail: `أيقونة بدون رسم (مربع فاضي): ${mg}`
            });
        }

        // تسجيل مشاكل الجداول
        for (const ti of evalResult.table_issues || []) {
            findings.push({
                severity: 'yellow',
                kind: 'browser_table_styling',
                file: `site/${pageName}`,
                line: 1,
                user: uType,
                url: pageName,
                detail: `ملاحظة تنسيق جداول: ${ti}`
            });
        }

        // تسجيل مشاكل الوضع الليلي
        for (const dc of evalResult.dark_contrast_issues || []) {
            findings.push({
                severity: 'yellow',
                kind: 'browser_dark_contrast',
                file: `site/${pageName}`,
                line: 1,
                user: uType,
                url: pageName,
                detail: `تباين ضعيف في الوضع الليلي (${dc.ratio}:1): "${dc.text}"`
            });
        }

        // تسجيل جرد الأزرار
        for (const btn of evalResult.buttons || []) {
            buttonsInventory.push({
                page: pageName,
                user: uType,
                ...btn
            });
        }
    }
}

// إنهاء Edge وخادم PHP المحلي
try {
    ws.close();
    edge.kill();
    phpServer.kill();
    rmSync(prof, { recursive: true, force: true });
} catch {}

writeFileSync(join(outDir, 'browser_findings.json'), JSON.stringify(findings, null, 2));
writeFileSync(join(outDir, 'buttons_inventory.json'), JSON.stringify(buttonsInventory, null, 2));

console.log(`\n✅ اكتمل الجزء ب: تم فحص ${pagesCount} صفحة، وسُجّل ${findings.length} ملاحظة متصفح، وجُرد ${buttonsInventory.length} زرار/عنصر تفاعلي.`);
