// =====================================================================
//  click_audit.mjs — زاحف وفاحص النقرات الحي (بريف ٢٠١)
//  نظام الفجالة ستور المالي — أداة فحص آمنة ١٠٠٪
// =====================================================================
import { spawn, execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync, rmSync, mkdtempSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

// قائمة الكلمات المستبعدة تماماً من الضغط حمايةً للبيانات
const SKIP_WORDS = [
    'حذف', 'مسح', 'امسح', 'اعتماد', 'اعتمد', 'رفض', 'صرف', 'ترحيل',
    'إلغاء', 'ألغي', 'الغاء', 'الغي', 'دفع', 'خروج', 'تسجيل خروج', 'إرسال', 'ارسال', 'ابعت'
];

const outDir = process.argv[2] || 'D:/fagala_wt_ds2/tests/audit/out/latest';
const PHP_BIN = process.env.PHP_BIN || 'D:/laragon/bin/php/php-8.3.33-Win32-vs16-x64/php.exe';
const WWW_AUDIT = process.env.LARAGON_WWW || 'D:/laragon/www_audit';
const HTTP_PORT = 8899;

console.log('=========================================================');
console.log('   CLICK CRAWLER AUDIT SUITE (Brief 201)');
console.log(`   Target Directory: ${WWW_AUDIT}`);
console.log(`   Output Directory: ${outDir}`);
console.log('=========================================================\n');

// -------------------------------------------------------------
// ١. تجهيز الجلسات (Sessions) للأدوار الثلاثة في fagala_audit
// -------------------------------------------------------------
console.log('>>> [١/٥] تجهيز الجلسات الحقيقية للأدوار الثلاثة (مالك، محاسب، كاشير)...');
const sessionPrepScript = `
require_once '${WWW_AUDIT.replace(/\\/g, '/')}/config.php';
require_once '${WWW_AUDIT.replace(/\\/g, '/')}/auth.php';

$roles = [
    'owner'      => ['id' => 1,  'unlocked' => true],
    'accountant' => ['id' => 15, 'unlocked' => false],
    'cashier'    => ['id' => 16, 'unlocked' => false],
];

foreach ($roles as $roleKey => $cfg) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    session_name('FGSESS');
    session_id("audit-$roleKey");
    session_start();
    $_SESSION = [];
    $_SESSION['app'] = 'fagala';

    $st = db()->prepare("
        SELECT u.id, u.username, u.full_name, u.role_id, u.branch_id, u.perms_version, u.pw_version, u.is_active, r.is_owner
        FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?
    ");
    $st->execute([$cfg['id']]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if ($u) {
        auth_fill_session($u, (int)($u['perms_version'] ?? 1), (int)($u['pw_version'] ?? 1));
        if ($cfg['unlocked']) {
            $_SESSION['sens_until'] = time() + 7200;
        }
    }
    session_write_close();
}
echo "SESSIONS_OK";
`;

try {
    const res = execFileSync(PHP_BIN, ['-r', sessionPrepScript], { encoding: 'utf8' });
    console.log(`  - حالة تجهيز الجلسات: ${res.trim()}\n`);
} catch (e) {
    console.error('❌ فشل إعداد الجلسات:', e.message);
    process.exit(1);
}

// -------------------------------------------------------------
// ٢. بدء خادم PHP المحلي المدمج
// -------------------------------------------------------------
console.log(`>>> [٢/٥] بدء تشغيل خادم PHP المحلي على http://127.0.0.1:${HTTP_PORT}...`);
const phpServer = spawn(PHP_BIN, ['-S', `127.0.0.1:${HTTP_PORT}`, '-t', WWW_AUDIT], { stdio: 'ignore' });

// إغلاق الخادم عند انتهاء أو تعليق السكربت
const cleanup = () => {
    try { phpServer.kill(); } catch {}
};
process.on('exit', cleanup);
process.on('SIGINT', () => { cleanup(); process.exit(); });
process.on('SIGTERM', () => { cleanup(); process.exit(); });

// -------------------------------------------------------------
// ٣. بدء تشغيل Edge Headless مع بروتوكول DevTools (CDP)
// -------------------------------------------------------------
console.log('>>> [٣/٥] بدء تشغيل Microsoft Edge Headless مع CDP...');
const EDGE = [
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe'
].find(existsSync);

if (!EDGE) {
    console.error('❌ لم يتم العثور على متصفح Microsoft Edge');
    cleanup();
    process.exit(1);
}

const W = 390, H = 844;
const prof = mkdtempSync(join(tmpdir(), 'click-edge-'));
const cdpPort = 9500 + Math.floor(Math.random() * 400);

const edge = spawn(EDGE, [
    '--headless=new',
    '--disable-gpu',
    `--remote-debugging-port=${cdpPort}`,
    `--user-data-dir=${prof}`,
    `--window-size=${W},${H}`,
    'about:blank'
], { stdio: 'ignore' });

const sleep = ms => new Promise(r => setTimeout(r, ms));

let wsUrl;
for (let i = 0; i < 50 && !wsUrl; i++) {
    await sleep(200);
    try {
        const t = await (await fetch(`http://127.0.0.1:${cdpPort}/json/list`)).json();
        wsUrl = t.find(x => x.type === 'page')?.webSocketDebuggerUrl;
    } catch {}
}

if (!wsUrl) {
    console.error('❌ فشل الاتصال بـ Edge DevTools Protocol');
    edge.kill();
    cleanup();
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
        // تشغيل مستمعي الأحداث
        for (const l of [...eventListeners]) {
            if (l.method === m.method) l.fn(m);
        }
    }
});

const send = (method, params = {}) => new Promise(r => {
    const i = ++msgId;
    pending.set(i, r);
    ws.send(JSON.stringify({ id: i, method, params }));
});

const evaluate = async expr => {
    const r = await send('Runtime.evaluate', {
        expression: expr,
        returnByValue: true
    });
    return r.result?.result?.value;
};

const onEvent = method => new Promise(r => {
    const l = {
        method,
        fn: m => {
            const idx = eventListeners.indexOf(l);
            if (idx !== -1) eventListeners.splice(idx, 1);
            r(m);
        }
    };
    eventListeners.push(l);
});

await send('Page.enable');
await send('Runtime.enable');
await send('Log.enable');
await send('Network.enable');
await send('Emulation.setDeviceMetricsOverride', { width: W, height: H, deviceScaleFactor: 2, mobile: true });

// معالجة وإغلاق أي نافذة alert / confirm / prompt فوراً حتى لا يعلق المتصفح
eventListeners.push({
    method: 'Page.javascriptDialogOpening',
    fn: async m => {
        try {
            await send('Page.handleJavaScriptDialog', { accept: true });
        } catch {}
    }
});

// -------------------------------------------------------------
// 🔴 حارس الأمان الصارم (Fetch.enable): إلغاء أي طلب مش GET
// -------------------------------------------------------------
console.log('>>> [حارس الأمان] تفعيل اعتراض الطلبات (Fetch.enable) لمنع أي كتابة أو خروج عن 127.0.0.1...');
await send('Fetch.enable', { patterns: [{ urlPattern: '*' }] });

const blocked_writes = [];
let currentRole = '';
let currentPage = '';
let currentButtonInfo = null;

eventListeners.push({
    method: 'Fetch.requestPaused',
    fn: async m => {
        const req = m.params.request;
        const reqId = m.params.requestId;
        const method = (req.method || 'GET').toUpperCase();

        try {
            const urlObj = new URL(req.url);
            const isLocal = urlObj.hostname === '127.0.0.1' || urlObj.hostname === 'localhost';

            // إلغاء أي طلب خارج الدومين المحلي
            if (!isLocal) {
                blocked_writes.push({
                    role: currentRole,
                    page: currentPage,
                    type: 'external_domain',
                    url: req.url,
                    method,
                    button: currentButtonInfo?.text || ''
                });
                await send('Fetch.failRequest', { requestId: reqId, errorReason: 'AccessDenied' });
                return;
            }

            // إلغاء أي طلب غير GET (منع الكتابة كلياً)
            if (method !== 'GET') {
                blocked_writes.push({
                    role: currentRole,
                    page: currentPage,
                    type: 'non_get_request',
                    url: req.url,
                    method,
                    postData: req.postData || '',
                    button: currentButtonInfo?.text || ''
                });
                await send('Fetch.failRequest', { requestId: reqId, errorReason: 'AccessDenied' });
                return;
            }

            // السماح بطلبات GET المحلية
            await send('Fetch.continueRequest', { requestId: reqId });
        } catch (err) {
            try {
                await send('Fetch.failRequest', { requestId: reqId, errorReason: 'AccessDenied' });
            } catch {}
        }
    }
});

// -------------------------------------------------------------
// ٤. استخراج قائمة الصفحات من all_modules()
// -------------------------------------------------------------
console.log('>>> [٤/٥] استخراج قائمة الصفحات التابعة للنظام من auth.php...');
let pages = [];
try {
    const authContent = readFileSync(join(WWW_AUDIT, 'auth.php'), 'utf8');
    const pageMatches = [...authContent.matchAll(/'url'\s*=>\s*'([a-z0-9_]+\.php)'/g)];
    pages = [...new Set(pageMatches.map(m => m[1]))];
    // إزالة تسجيل الدخول والخروج للحفاظ على الجلسة
    pages = pages.filter(p => p !== 'login.php' && p !== 'logout.php');
} catch (e) {
    console.error('❌ خطأ في قراءة صفحات auth.php:', e.message);
    pages = ['dashboard.php', 'hr.php', 'review.php', 'cashier.php', 'treasury.php'];
}

console.log(`  - عدد الصفحات المكتشفة: ${pages.length} صفحة: ${pages.join(', ')}\n`);

// -------------------------------------------------------------
// ٥. بدء الزحف وفحص النقرات لكل دور
// -------------------------------------------------------------
console.log('>>> [٥/٥] بدء زاحف النقرات للأدوار الثلاثة...');

const roles = ['owner', 'accountant', 'cashier'];
const findings = [];
const skipped = [];
const stats = {
    total_pages_visited: 0,
    total_clicks: { owner: 0, accountant: 0, cashier: 0 },
    pages_reached_limit: [],
    tabs_clicked: { owner: 0, accountant: 0, cashier: 0 },
    role_access_log: []
};

const startTime = Date.now();

for (const role of roles) {
    currentRole = role;
    console.log(`\n======================================================`);
    console.log(`   فحص دور: [${role.toUpperCase()}]`);
    console.log(`======================================================`);

    // تعيين كوكي الجلسة الحقيقية لهذا الدور
    await send('Network.clearBrowserCookies');
    await send('Network.setCookie', {
        url: `http://127.0.0.1:${HTTP_PORT}/`,
        name: 'FGSESS',
        value: `audit-${role}`,
        domain: '127.0.0.1',
        path: '/'
    });

    for (const page of pages) {
        currentPage = page;
        stats.total_pages_visited++;
        const targetUrl = `http://127.0.0.1:${HTTP_PORT}/${page}`;

        // متابعة الأخطاء والشبكة أثناء فتح الصفحة
        let pageConsoleErrors = [];
        let pageJsExceptions = [];
        let pageNetworkErrors = [];

        const logListener = {
            method: 'Log.entryAdded',
            fn: m => {
                if (m.params?.entry?.level === 'error') {
                    pageConsoleErrors.push(m.params.entry.text || 'Console error');
                }
            }
        };
        const excListener = {
            method: 'Runtime.exceptionThrown',
            fn: m => {
                const desc = m.params?.exceptionDetails?.exception?.description
                    || m.params?.exceptionDetails?.text
                    || 'Uncaught JS Exception';
                pageJsExceptions.push(desc);
            }
        };
        const netListener = {
            method: 'Network.responseReceived',
            fn: m => {
                const res = m.params?.response;
                if (res && res.status >= 400 && res.url.includes(`127.0.0.1:${HTTP_PORT}`)) {
                    pageNetworkErrors.push({ url: res.url, status: res.status });
                }
            }
        };

        eventListeners.push(logListener, excListener, netListener);

        // فتح الصفحة وانتظار التحميل
        try {
            const loadPromise = onEvent('Page.loadEventFired');
            await send('Page.navigate', { url: targetUrl });
            await Promise.race([loadPromise, sleep(4000)]);
            await sleep(500);
        } catch (e) {
            console.log(`  - تعذر فتح ${page}: ${e.message}`);
            continue;
        }

        // فحص هل تم تحويل الصفحة أو حظرها (خاصة للكاشير)
        const stateRaw = await evaluate(`JSON.stringify({
            url: window.location.href,
            title: document.title,
            bodySnippet: document.body ? document.body.innerText.slice(0, 200) : ''
        })`);

        const state = JSON.parse(stateRaw || '{}');
        const snippet = String(state.bodySnippet || '');
        const stateUrl = String(state.url || '');
        const isForbidden = snippet.includes('403') || snippet.includes('غير مسموح') || snippet.includes('Forbidden');
        const isRedirected = Boolean(stateUrl && !stateUrl.includes(page));

        if (isForbidden || isRedirected) {
            stats.role_access_log.push({
                role,
                page,
                status: isForbidden ? 403 : 'redirect',
                finalUrl: state.url,
                note: 'تم منع الوصول / توجيه (سلوك طبيعي لحماية الصلاحيات)'
            });
            // إذا كان كاشير أو مستخدم بدون صلاحية، هذا ليس خطأ في النظام بل تأكيد الحماية
            console.log(`  [${page}] تم توجيهه / منعه (صلاحيات الدور: ${isForbidden ? '403' : 'Redirect'})`);
            // إزالة المستمعين والانتقال للصفحة التالية
            const idx1 = eventListeners.indexOf(logListener); if (idx1 !== -1) eventListeners.splice(idx1, 1);
            const idx2 = eventListeners.indexOf(excListener); if (idx2 !== -1) eventListeners.splice(idx2, 1);
            const idx3 = eventListeners.indexOf(netListener); if (idx3 !== -1) eventListeners.splice(idx3, 1);
            continue;
        }

        // جرد العناصر القابلة للضغط داخل الصفحة
        const scanRes = await evaluate(`(() => {
            const els = document.querySelectorAll('button, [role=tab], [onclick], a[href^="#"], .tab, summary');
            const out = [];
            els.forEach((el, index) => {
                const text = (el.textContent || '').trim().replace(/\\s+/g, ' ');
                const title = el.getAttribute('title') || '';
                const ariaLabel = el.getAttribute('aria-label') || '';
                const onclick = el.getAttribute('onclick') || '';
                const type = (el.getAttribute('type') || '').toLowerCase();
                const tagName = el.tagName.toLowerCase();
                const isTab = el.getAttribute('role') === 'tab' || el.classList.contains('tab') || (tagName === 'a' && el.getAttribute('href')?.startsWith('#'));

                let formMethod = '';
                let isSubmit = (type === 'submit');
                const form = el.form || el.closest('form');
                if (form) {
                    formMethod = (form.getAttribute('method') || 'GET').toUpperCase();
                }

                out.push({
                    index,
                    tagName,
                    text: text.slice(0, 60),
                    title: title.slice(0, 60),
                    ariaLabel: ariaLabel.slice(0, 60),
                    type,
                    formMethod,
                    isSubmit,
                    isTab,
                    className: (el.className || '').toString().slice(0, 50)
                });
            });
            return JSON.stringify(out);
        })()`);

        const elements = JSON.parse(scanRes || '[]');
        console.log(`  [${page}] إجمالي العناصر التفاعلية: ${elements.length}`);

        let pageClicks = 0;
        const MAX_CLICKS_PER_PAGE = 80;

        for (const item of elements) {
            // فحص شروط الاستبعاد والتخطي
            let skipReason = null;
            const fullLabel = `${item.text} ${item.title} ${item.ariaLabel}`;

            if (item.isSubmit) {
                skipReason = 'type=submit';
            } else if (item.formMethod === 'POST') {
                skipReason = 'inside form[method=POST]';
            } else {
                for (const word of SKIP_WORDS) {
                    if (fullLabel.includes(word)) {
                        skipReason = `skip_word [${word}]`;
                        break;
                    }
                }
            }

            if (skipReason) {
                skipped.push({
                    role,
                    page,
                    text: item.text || item.title || item.tagName,
                    reason: skipReason
                });
                continue;
            }

            // فحص الحد الأقصى للصفحة (80 ضغطة)
            if (pageClicks >= MAX_CLICKS_PER_PAGE) {
                if (!stats.pages_reached_limit.includes(`${role}:${page}`)) {
                    stats.pages_reached_limit.push(`${role}:${page}`);
                    console.log(`    ⚠️ وصلت الصفحة للحد الأقصى للضغطات (${MAX_CLICKS_PER_PAGE})`);
                }
                break;
            }

            // تجهيز تتبع أحداث هذه النقرة
            pageClicks++;
            stats.total_clicks[role]++;
            if (item.isTab) stats.tabs_clicked[role]++;

            currentButtonInfo = item;
            const clickConsoleErrors = [];
            const clickJsExceptions = [];
            const clickNetworkErrors = [];

            const clickLog = {
                method: 'Log.entryAdded',
                fn: m => {
                    if (m.params?.entry?.level === 'error') {
                        clickConsoleErrors.push(m.params.entry.text || 'Console error');
                    }
                }
            };
            const clickExc = {
                method: 'Runtime.exceptionThrown',
                fn: m => {
                    const desc = m.params?.exceptionDetails?.exception?.description
                        || m.params?.exceptionDetails?.text
                        || 'Uncaught JS Exception';
                    clickJsExceptions.push(desc);
                }
            };
            const clickNet = {
                method: 'Network.responseReceived',
                fn: m => {
                    const res = m.params?.response;
                    if (res && res.status >= 400 && res.url.includes(`127.0.0.1:${HTTP_PORT}`)) {
                        clickNetworkErrors.push({ url: res.url, status: res.status });
                    }
                }
            };

            eventListeners.push(clickLog, clickExc, clickNet);

            // تنفيذ الضغط على العنصر
            await send('Runtime.evaluate', {
                expression: `(() => {
                    const els = document.querySelectorAll('button, [role=tab], [onclick], a[href^="#"], .tab, summary');
                    const target = els[${item.index}];
                    if (target) {
                        try { target.scrollIntoView({ block: 'center', inline: 'center' }); } catch {}
                        target.click();
                    }
                })()`
            });

            // الانتظار بعد الضغط 400ms
            await sleep(400);

            // إزالة مستمعي النقرة
            const i1 = eventListeners.indexOf(clickLog); if (i1 !== -1) eventListeners.splice(i1, 1);
            const i2 = eventListeners.indexOf(clickExc); if (i2 !== -1) eventListeners.splice(i2, 1);
            const i3 = eventListeners.indexOf(clickNet); if (i3 !== -1) eventListeners.splice(i3, 1);

            // 1. فحص استثناءات JS
            for (const exc of clickJsExceptions) {
                findings.push({
                    severity: 1,
                    type: 'JS_EXCEPTION',
                    role,
                    page,
                    button: item.text || item.title || item.tagName,
                    error: exc.split('\n')[0]
                });
            }

            // 2. فحص طلبات 5xx
            for (const net of clickNetworkErrors.filter(n => n.status >= 500)) {
                findings.push({
                    severity: 2,
                    type: 'HTTP_5XX',
                    role,
                    page,
                    button: item.text || item.title || item.tagName,
                    error: `GET ${net.url} -> HTTP ${net.status}`
                });
            }

            // 3. فحص طلبات 4xx
            for (const net of clickNetworkErrors.filter(n => n.status >= 400 && n.status < 500)) {
                findings.push({
                    severity: 3,
                    type: 'HTTP_4XX',
                    role,
                    page,
                    button: item.text || item.title || item.tagName,
                    error: `GET ${net.url} -> HTTP ${net.status}`
                });
            }

            // 4. فحص هل فتح مودال وإمكانية إغلاقه بـ Escape
            const modalOpen = await evaluate(`(() => {
                const m = document.querySelector('.modal.show, .modal[style*="display: block"], [role=dialog]:not([hidden]), dialog[open]');
                return m ? true : false;
            })()`);

            if (modalOpen) {
                // إرسال Escape
                await send('Input.dispatchKeyEvent', { type: 'rawKeyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
                await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
                await sleep(200);

                const stillOpen = await evaluate(`(() => {
                    const m = document.querySelector('.modal.show, .modal[style*="display: block"], [role=dialog]:not([hidden]), dialog[open]');
                    if (m) {
                        // محاولة إغلاقه بالقوة حتى لا يعطل باقي الأزرار
                        const closeBtn = m.querySelector('.close, [data-bs-dismiss="modal"], .btn-close');
                        if (closeBtn) closeBtn.click();
                        return true;
                    }
                    return false;
                })()`);

                if (stillOpen) {
                    findings.push({
                        severity: 4,
                        type: 'UNCLOSED_MODAL',
                        role,
                        page,
                        button: item.text || item.title || item.tagName,
                        error: 'المودال لم يُغلق عند الضغط على Escape'
                    });
                }
            }

            // 5. فحص السكرول بالعرض على عرض ٣٩٠
            const isOverflow = await evaluate(`document.documentElement.scrollWidth > document.documentElement.clientWidth + 2`);

            if (isOverflow) {
                findings.push({
                    severity: 5,
                    type: 'OVERFLOW_390',
                    role,
                    page,
                    button: item.text || item.title || item.tagName,
                    error: 'ظهور شريط سكرول بالعرض بعد الضغط'
                });
            }

            // 6. فحص أخطاء الكونسول الأخرى
            for (const cerr of clickConsoleErrors) {
                findings.push({
                    severity: 6,
                    type: 'CONSOLE_WARN',
                    role,
                    page,
                    button: item.text || item.title || item.tagName,
                    error: cerr.split('\n')[0]
                });
            }

            // فحص هل تغير الـ URL بعد الضغط
            const currentUrl = (await evaluate(`window.location.href`)) || '';
            if (currentUrl && !currentUrl.includes(page) && !currentUrl.includes('#')) {
                // العودة للصفحة الأصلية
                const loadPromise = onEvent('Page.loadEventFired');
                await send('Page.navigate', { url: targetUrl });
                await Promise.race([loadPromise, sleep(3000)]);
                await sleep(400);
            }
        }

        // إزالة مستمعي الصفحة
        const l1 = eventListeners.indexOf(logListener); if (l1 !== -1) eventListeners.splice(l1, 1);
        const l2 = eventListeners.indexOf(excListener); if (l2 !== -1) eventListeners.splice(l2, 1);
        const l3 = eventListeners.indexOf(netListener); if (l3 !== -1) eventListeners.splice(l3, 1);
    }
}

const totalDurationSec = Math.round((Date.now() - startTime) / 1000);

// ترتيب المشاكل حسب الخطورة
findings.sort((a, b) => a.severity - b.severity);

// -------------------------------------------------------------
// ٦. حفظ النتائج في clicks.json و clicks.md
// -------------------------------------------------------------
console.log('\n>>> حفظ نتائج الزحف في مجلد المخرجات...');

const summary = {
    duration_seconds: totalDurationSec,
    total_pages_visited: stats.total_pages_visited,
    total_clicks: stats.total_clicks,
    tabs_clicked: stats.tabs_clicked,
    pages_reached_limit: stats.pages_reached_limit,
    total_skipped: skipped.length,
    total_blocked_writes: blocked_writes.length,
    total_findings: findings.length,
    findings_by_type: {
        JS_EXCEPTION: findings.filter(f => f.type === 'JS_EXCEPTION').length,
        HTTP_5XX: findings.filter(f => f.type === 'HTTP_5XX').length,
        HTTP_4XX: findings.filter(f => f.type === 'HTTP_4XX').length,
        UNCLOSED_MODAL: findings.filter(f => f.type === 'UNCLOSED_MODAL').length,
        OVERFLOW_390: findings.filter(f => f.type === 'OVERFLOW_390').length,
        CONSOLE_WARN: findings.filter(f => f.type === 'CONSOLE_WARN').length
    }
};

const jsonReport = {
    summary,
    blocked_writes,
    findings,
    skipped: skipped.slice(0, 200),
    role_access_log: stats.role_access_log
};

writeFileSync(join(outDir, 'clicks.json'), JSON.stringify(jsonReport, null, 2), 'utf8');

// إنشاء تقرير clicks.md
let mdReport = `# تقرير زاحف النقرات (Click Audit Report - بريف ٢٠١)\n\n`;
mdReport += `- **التاريخ والوقت:** ${new Date().toISOString()}\n`;
mdReport += `- **الوقت المستغرق:** ${Math.floor(totalDurationSec / 60)} دقيقة و ${totalDurationSec % 60} ثانية\n`;
mdReport += `- **عدد الصفحات المفحوصة:** ${stats.total_pages_visited}\n`;
mdReport += `- **عدد الضغطات الفعلي:** مالك (${stats.total_clicks.owner}) · محاسب (${stats.total_clicks.accountant}) · كاشير (${stats.total_clicks.cashier}) — الإجمالي: ${stats.total_clicks.owner + stats.total_clicks.accountant + stats.total_clicks.cashier}\n`;
mdReport += `- **التابات التي تم الضغط عليها:** مالك (${stats.tabs_clicked.owner}) · محاسب (${stats.tabs_clicked.accountant}) · كاشير (${stats.tabs_clicked.cashier})\n`;
mdReport += `- **العناصر المستبعدة بأمان (Skipped):** ${skipped.length}\n`;
mdReport += `- **محاولات الكتابة المحظورة بأمان (Blocked Writes):** ${blocked_writes.length}\n\n`;

mdReport += `## ملخص المشاكل حسب الخطورة\n\n`;
mdReport += `| نوع المشكلة | الخطورة | العدد |\n`;
mdReport += `|---|---|---|\n`;
mdReport += `| استثناءات جافاسكريبت (JS Exceptions) | 🔴 حرجة | ${summary.findings_by_type.JS_EXCEPTION} |\n`;
mdReport += `| أخطاء خادم (HTTP 5xx) | 🔴 عالية | ${summary.findings_by_type.HTTP_5XX} |\n`;
mdReport += `| طلبات غير موجودة / ممنوعة (HTTP 4xx) | 🟠 متوسطة | ${summary.findings_by_type.HTTP_4XX} |\n`;
mdReport += `| مودال لا يغلق بـ Escape | 🟡 منخفضة | ${summary.findings_by_type.UNCLOSED_MODAL} |\n`;
mdReport += `| سكرول بالعرض على عرض ٣٩٠ | 🟡 تنسيق | ${summary.findings_by_type.OVERFLOW_390} |\n`;
mdReport += `| تحذيرات كونسول | ⚪ معلوماتية | ${summary.findings_by_type.CONSOLE_WARN} |\n\n`;

mdReport += `## أول ٤٠ مشكلة بالحرف (مرتبة بالخطورة)\n\n`;
mdReport += `| # | الدور | الصفحة | نص الزرار | المشكلة | أول سطر من الخطأ |\n`;
mdReport += `|---|---|---|---|---|---|\n`;

const top40 = findings.slice(0, 40);
if (top40.length === 0) {
    mdReport += `| — | — | — | — | لم يتم العثور على أي مشاكل أثناء الضغط | — |\n`;
} else {
    top40.forEach((f, idx) => {
        const cleanBtn = (f.button || '—').replace(/\|/g, '-');
        const cleanErr = (f.error || '—').replace(/\|/g, '-').slice(0, 80);
        mdReport += `| ${idx + 1} | ${f.role} | ${f.page} | ${cleanBtn} | ${f.type} | \`${cleanErr}\` |\n`;
    });
}

writeFileSync(join(outDir, 'clicks.md'), mdReport, 'utf8');

console.log('✅ تم حفظ clicks.json و clicks.md بنجاح!');
console.log('=========================================================\n');

// إغلاق المتصفح والعمليات
edge.kill();
cleanup();
try { rmSync(prof, { recursive: true, force: true }); } catch {}
process.exit(0);
