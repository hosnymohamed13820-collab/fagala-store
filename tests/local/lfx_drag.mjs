// =====================================================================
//  lfx_drag.mjs — فحص تفاعل وسحب شخصيات صفحة الدخول على Laragon (بريف ٦٥)
// ---------------------------------------------------------------------
//  يفحص حالات التفاعل الثمانية المذكورة في بريف ٦٥:
//  ١. مسك القلم وحركته ٣٠٠px
//  ٢. رمي القلم شمال تحت وسرعة الطيران والتحول للمطاردة
//  ٣. مطاردة الماوس لـ (1200, 200) مع الالتفاف حول الكارت
//  ٤. ثبات الماوس ٦ ثواني وعودة القلم للبيت دون قفزات (> 8px)
//  ٥. رمي البرّاية نحو الكارت وارتدادها
//  ٦. فوكس خانة الباسورد وإلغاء المطاردة فوراً
//  ٧. السحب باللمس على شاشة الموبايل ٣٩٠×٨٤٤ مع بقاء scrollY = 0
//  ٨. فحص probe للتأكد من عدم تأثر الحركة العادية
// =====================================================================
import { spawn, execSync } from 'node:child_process';
import { writeFileSync, mkdtempSync, mkdirSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const OUT = process.argv[2];
if (!OUT) {
    console.log('الاستعمال: node tests/local/lfx_drag.mjs <مجلد>');
    process.exit(1);
}
mkdirSync(OUT, { recursive: true });

const W = 1440, H = 900;
const EDGE = ['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
              'C:/Program Files/Microsoft/Edge/Application/msedge.exe'].find(existsSync);
if (!EDGE) { console.log('❌ مفيش Edge'); process.exit(1); }

const prof = mkdtempSync(join(tmpdir(), 'lfx-drag-'));
const port = 9400 + Math.floor(Math.random() * 500);
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
        const list = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
        wsUrl = list.find(x => x.type === 'page')?.webSocketDebuggerUrl;
    } catch {}
}
if (!wsUrl) { console.log('❌ Edge ماردّش'); edge.kill(); process.exit(1); }

const ws = new WebSocket(wsUrl);
await new Promise(r => ws.addEventListener('open', r, { once: true }));
let id = 0;
const pending = new Map();
ws.addEventListener('message', ev => {
    const m = JSON.parse(ev.data);
    if (m.id && pending.has(m.id)) {
        pending.get(m.id)(m);
        pending.delete(m.id);
    }
});

const send = (method, params = {}) => new Promise(r => {
    const i = ++id;
    pending.set(i, r);
    ws.send(JSON.stringify({ id: i, method, params }));
});

const ev = async expr => {
    const res = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
    return res.result?.result?.value;
};

const takeScreenshot = async filename => {
    const s = await send('Page.captureScreenshot', { format: 'png' });
    writeFileSync(join(OUT, filename), Buffer.from(s.result.data, 'base64'));
};

await send('Page.enable');
await send('Emulation.setDeviceMetricsOverride', { width: W, height: H, deviceScaleFactor: 1, mobile: false });

console.log('🚀 بدء اختبارات lfx_drag...');

// Helper: dispatch mouse moves
async function mouseMoveSmooth(fromX, fromY, toX, toY, steps = 10, interval = 20, pressed = false) {
    for (let s = 1; s <= steps; s++) {
        const curX = fromX + (toX - fromX) * (s / steps);
        const curY = fromY + (toY - fromY) * (s / steps);
        await send('Input.dispatchMouseEvent', {
            type: 'mouseMoved',
            x: Math.round(curX),
            y: Math.round(curY),
            button: pressed ? 'left' : 'none',
            buttons: pressed ? 1 : 0
        });
        if (interval > 0) await sleep(interval);
    }
}

// ---------------------------------------------------------------------
// الحالة ١: امسك القلم الرصاص وحركه 300px
// ---------------------------------------------------------------------
await send('Page.navigate', { url: 'http://localhost/login.php?lfx_debug=1' });
await sleep(1500);

const pencilInfo = await ev(`(() => {
    const el = document.querySelector('.lfx-char-pencil');
    const r = el.getBoundingClientRect();
    return { cx: r.left + r.width / 2, cy: r.top + r.height / 2 };
})()`);

const startX = pencilInfo.cx;
const startY = pencilInfo.cy;

await send('Input.dispatchMouseEvent', {
    type: 'mousePressed',
    x: Math.round(startX),
    y: Math.round(startY),
    button: 'left',
    buttons: 1,
    clickCount: 1
});
await sleep(50);

// حرك 300px لليمين
const targetX1 = startX + 300;
const targetY1 = startY;
await mouseMoveSmooth(startX, startY, targetX1, targetY1, 10, 20, true);
await sleep(100);

await takeScreenshot('1_held.png');

const afterHold = await ev(`(() => {
    const el = document.querySelector('.lfx-char-pencil');
    const r = el.getBoundingClientRect();
    const d = document.body.dataset;
    return {
        cx: r.left + r.width / 2,
        cy: r.top + r.height / 2,
        interact: d.lfxInteract || '',
        incard: d.lfxIncard || '0'
    };
})()`);

// المسافة بين مركز القلم ونقطة الماوس الحالية
const distFromMouse1 = Math.hypot(afterHold.cx - targetX1, afterHold.cy - targetY1);
const pass1 = (afterHold.interact === 'pencil:held') && (distFromMouse1 <= 10);
console.log(`1. مسك القلم وحركته 300px: ${pass1 ? '✅' : '❌'} (interact='${afterHold.interact}' dist=${distFromMouse1.toFixed(1)}px <= 10px)`);

// ---------------------------------------------------------------------
// الحالة ٢: ارميه ناحية الشمال تحت بسرعة ← flying ← chase في أقل من 3ث و incard = 0
// ---------------------------------------------------------------------
// نرمي بسرعة معتدلة ناحية الشمال تحت في 5 خطوات سريعة
let throwCurX = targetX1;
let throwCurY = targetY1;
const throwSteps = 5;
const stepDx = -25;
const stepDy = 16;

for (let i = 0; i < throwSteps; i++) {
    throwCurX += stepDx;
    throwCurY += stepDy;
    await send('Input.dispatchMouseEvent', {
        type: 'mouseMoved',
        x: Math.round(throwCurX),
        y: Math.round(throwCurY),
        button: 'left',
        buttons: 1
    });
    await sleep(15);
}

await send('Input.dispatchMouseEvent', {
    type: 'mouseReleased',
    x: Math.round(throwCurX),
    y: Math.round(throwCurY),
    button: 'left',
    buttons: 0,
    clickCount: 1
});

// مراقبة الانتقال من flying إلى chase خلال ٣ ثواني
const t0_throw = Date.now();
let reachedChase = false;
let chaseTransitionTime = 0;
let maxIncardDuringThrow = 0;

while (Date.now() - t0_throw < 3500) {
    const st = await ev(`(() => ({
        interact: document.body.dataset.lfxInteract || '',
        incard: parseInt(document.body.dataset.lfxIncard || '0', 10)
    }))()`);
    maxIncardDuringThrow = Math.max(maxIncardDuringThrow, st.incard);
    if (st.interact === 'pencil:chase') {
        reachedChase = true;
        chaseTransitionTime = (Date.now() - t0_throw) / 1000;
        break;
    }
    await sleep(50);
}

await takeScreenshot('2_flying_chase.png');
const pass2 = reachedChase && (chaseTransitionTime < 3.0) && (maxIncardDuringThrow === 0);
console.log(`2. رمي القلم (flying -> chase): ${pass2 ? '✅' : '❌'} (time=${chaseTransitionTime.toFixed(2)}s < 3s, incard=${maxIncardDuringThrow})`);

// ---------------------------------------------------------------------
// الحالة ٣: حرك الماوس لـ (1200, 200) واستنى ~3ث ← القلم على بعد <= 120px و incard = 0
// ---------------------------------------------------------------------
// تحريك الماوس إلى (1200, 200)
await mouseMoveSmooth(throwCurX, throwCurY, 1200, 200, 10, 20, false);

const t0_chase = Date.now();
let maxIncardDuringChase = 0;
let distFromMouseChase = 9999;

while (Date.now() - t0_chase < 4600) {
    const curState = await ev(`(() => {
        const el = document.querySelector('.lfx-char-pencil');
        const r = el.getBoundingClientRect();
        return {
            cx: r.left + r.width / 2,
            cy: r.top + r.height / 2,
            incard: parseInt(document.body.dataset.lfxIncard || '0', 10)
        };
    })()`);
    maxIncardDuringChase = Math.max(maxIncardDuringChase, curState.incard);
    distFromMouseChase = Math.hypot(curState.cx - 1200, curState.cy - 200);
    if (Date.now() - t0_chase >= 2800 && distFromMouseChase <= 120) {
        break;
    }
    await sleep(80);
}

await takeScreenshot('3_chase_arrived.png');
const pass3 = (distFromMouseChase <= 120) && (maxIncardDuringChase === 0);
console.log(`3. مطاردة لـ (1200, 200) لـ ~3ث: ${pass3 ? '✅' : '❌'} (dist=${distFromMouseChase.toFixed(1)}px <= 120px, incard=${maxIncardDuringChase})`);

// ---------------------------------------------------------------------
// الحالة ٤: سيب الماوس ثابت 6ث ← lfxInteract = '' والقلم ماشي لبيته، maxStep <= 8px
// ---------------------------------------------------------------------
// نقيس أكبر قفزة أثناء العودة
const walkHomeRecord = await ev(`new Promise(res => {
    const el = document.querySelector('.lfx-char-pencil');
    const center = () => { const r = el.getBoundingClientRect(); return [r.left + r.width / 2, r.top + r.height / 2]; };
    const t0 = performance.now();
    let maxStep = 0;
    let prev = center();
    (function loop() {
        const now = performance.now();
        const c = center();
        const step = Math.hypot(c[0] - prev[0], c[1] - prev[1]);
        if (step > maxStep) maxStep = step;
        prev = c;
        if (now - t0 < 6200) {
            requestAnimationFrame(loop);
        } else {
            res({
                interact: document.body.dataset.lfxInteract || '',
                maxStep: maxStep
            });
        }
    })();
})`);

await takeScreenshot('4_walk_home.png');
const pass4 = (walkHomeRecord.interact === '') && (walkHomeRecord.maxStep <= 8.0);
console.log(`4. ماوس ثابت 6ث وعودة للبيت: ${pass4 ? '✅' : '❌'} (interact='${walkHomeRecord.interact}', maxStep=${walkHomeRecord.maxStep.toFixed(1)}px <= 8px)`);

// ---------------------------------------------------------------------
// الحالة ٥: ارمي البرّاية ناحية الكارت ← ترتد، incard = 0
// ---------------------------------------------------------------------
const sharpenerInfo = await ev(`(() => {
    const el = document.querySelector('.lfx-char-sharpener');
    const card = document.querySelector('.login-card').getBoundingClientRect();
    const r = el.getBoundingClientRect();
    return {
        cx: r.left + r.width / 2,
        cy: r.top + r.height / 2,
        cardCx: card.left + card.width / 2,
        cardCy: card.top + card.height / 2
    };
})()`);

await send('Input.dispatchMouseEvent', {
    type: 'mousePressed',
    x: Math.round(sharpenerInfo.cx),
    y: Math.round(sharpenerInfo.cy),
    button: 'left',
    buttons: 1,
    clickCount: 1
});
await sleep(50);

// رمي سريع باتجاه الكارت
const throwSharpSteps = 5;
const sStepDx = (sharpenerInfo.cardCx - sharpenerInfo.cx) / throwSharpSteps;
const sStepDy = (sharpenerInfo.cardCy - sharpenerInfo.cy) / throwSharpSteps;
let sCurX = sharpenerInfo.cx;
let sCurY = sharpenerInfo.cy;

for (let i = 0; i < throwSharpSteps; i++) {
    sCurX += sStepDx;
    sCurY += sStepDy;
    await send('Input.dispatchMouseEvent', {
        type: 'mouseMoved',
        x: Math.round(sCurX),
        y: Math.round(sCurY),
        button: 'left',
        buttons: 1
    });
    await sleep(15);
}

await send('Input.dispatchMouseEvent', {
    type: 'mouseReleased',
    x: Math.round(sCurX),
    y: Math.round(sCurY),
    button: 'left',
    buttons: 0,
    clickCount: 1
});

// انتظر 1.5 ثانية لفحص الارتداد ومراقبة incard
let maxIncardBounce = 0;
const t0_bounce = Date.now();
while (Date.now() - t0_bounce < 1500) {
    const inc = await ev(`parseInt(document.body.dataset.lfxIncard || '0', 10)`);
    maxIncardBounce = Math.max(maxIncardBounce, inc);
    await sleep(50);
}

await takeScreenshot('5_bounce_card.png');
const pass5 = (maxIncardBounce === 0);
console.log(`5. رمي البرّاية نحو الكارت والارتداد: ${pass5 ? '✅' : '❌'} (incard=${maxIncardBounce})`);

// ---------------------------------------------------------------------
// الحالة ٦: اضغط خانة الباسورد ← activeElement هي الخانة، وأثناء chase إلغاء فوراً
// ---------------------------------------------------------------------
// نبدأ مسك القلم ونرميه للدخول في chase
const p6 = await ev(`(() => {
    const el = document.querySelector('.lfx-char-pencil');
    const r = el.getBoundingClientRect();
    return { cx: r.left + r.width / 2, cy: r.top + r.height / 2 };
})()`);

await send('Input.dispatchMouseEvent', {
    type: 'mousePressed',
    x: Math.round(p6.cx),
    y: Math.round(p6.cy),
    button: 'left',
    buttons: 1,
    clickCount: 1
});
await sleep(30);

// حركة ثم إفلات
await send('Input.dispatchMouseEvent', {
    type: 'mouseMoved',
    x: Math.round(p6.cx + 50),
    y: Math.round(p6.cy),
    button: 'left',
    buttons: 1
});
await sleep(15);
await send('Input.dispatchMouseEvent', {
    type: 'mouseReleased',
    x: Math.round(p6.cx + 50),
    y: Math.round(p6.cy),
    button: 'left',
    buttons: 0,
    clickCount: 1
});

// انتظر حتى يدخل القلم في chase
for (let i = 0; i < 40; i++) {
    const it = await ev(`document.body.dataset.lfxInteract || ''`);
    if (it.includes('chase')) break;
    await sleep(50);
}

// الآن اضغط على خانة الباسورد
const passInputInfo = await ev(`(() => {
    const inp = document.querySelector('#password, input[type="password"]');
    const r = inp.getBoundingClientRect();
    return { cx: r.left + r.width / 2, cy: r.top + r.height / 2 };
})()`);

await send('Input.dispatchMouseEvent', {
    type: 'mousePressed',
    x: Math.round(passInputInfo.cx),
    y: Math.round(passInputInfo.cy),
    button: 'left',
    buttons: 1,
    clickCount: 1
});
await send('Input.dispatchMouseEvent', {
    type: 'mouseReleased',
    x: Math.round(passInputInfo.cx),
    y: Math.round(passInputInfo.cy),
    button: 'left',
    buttons: 0,
    clickCount: 1
});
await sleep(100);

const focusResult = await ev(`(() => {
    const active = document.activeElement;
    return {
        isPassword: active && (active.id === 'password' || active.type === 'password'),
        activeTag: active ? active.tagName.toLowerCase() + (active.id ? '#' + active.id : '') : 'none',
        interact: document.body.dataset.lfxInteract || ''
    };
})()`);

await takeScreenshot('6_focus_input.png');
const pass6 = focusResult.isPassword && (focusResult.interact === '');
console.log(`6. ضغط خانة الباسورد أثناء المطاردة: ${pass6 ? '✅' : '❌'} (active='${focusResult.activeTag}', interact='${focusResult.interact}')`);

// ---------------------------------------------------------------------
// الحالة ٧: موبايل 390×844: سحب باللمس، تتحرك و scrollY = 0
// ---------------------------------------------------------------------
await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
await send('Emulation.setTouchEmulationEnabled', { enabled: true, configuration: 'mobile' });
await send('Page.navigate', { url: 'http://localhost/login.php?lfx_debug=1' });
await sleep(1500);

const scrollBefore = await ev(`window.scrollY`);
const charMobile = await ev(`(() => {
    const el = document.querySelector('.lfx-char-pencil');
    const r = el.getBoundingClientRect();
    return { cx: r.left + r.width / 2, cy: r.top + r.height / 2 };
})()`);

// لمس وسحب بالصباع
await send('Input.dispatchTouchEvent', {
    type: 'touchStart',
    touchPoints: [{ x: Math.round(charMobile.cx), y: Math.round(charMobile.cy), id: 0 }]
});
await sleep(30);

let touchX = charMobile.cx;
let touchY = charMobile.cy;
for (let i = 0; i < 8; i++) {
    touchX += 15;
    touchY += 10;
    await send('Input.dispatchTouchEvent', {
        type: 'touchMove',
        touchPoints: [{ x: Math.round(touchX), y: Math.round(touchY), id: 0 }]
    });
    await sleep(25);
}

const charMobileAfter = await ev(`(() => {
    const el = document.querySelector('.lfx-char-pencil');
    const r = el.getBoundingClientRect();
    return { cx: r.left + r.width / 2, cy: r.top + r.height / 2 };
})()`);

await send('Input.dispatchTouchEvent', {
    type: 'touchEnd',
    touchPoints: []
});
await sleep(100);

const scrollAfter = await ev(`window.scrollY`);
const touchMoveDist = Math.hypot(charMobileAfter.cx - charMobile.cx, charMobileAfter.cy - charMobile.cy);

await takeScreenshot('7_mobile_touch.png');
const pass7 = (scrollBefore === 0) && (scrollAfter === 0) && (touchMoveDist > 30);
console.log(`7. موبايل 390×844 سحب باللمس: ${pass7 ? '✅' : '❌'} (scrollBefore=${scrollBefore}, scrollAfter=${scrollAfter}, distMoved=${touchMoveDist.toFixed(1)}px > 30px)`);

ws.close();
edge.kill();
await sleep(1500);

// ---------------------------------------------------------------------
// الحالة ٨: فحص الحركة العادية عبر lfx_probe.mjs
// ---------------------------------------------------------------------
console.log('8. تشغيل فحص الحركة العادية (lfx_probe.mjs)...');
let pass8 = false;
let probeOutput = '';
try {
    probeOutput = execSync(`node tests/local/lfx_probe.mjs "${OUT}"`, { encoding: 'utf-8' });
    console.log(probeOutput);
    pass8 = probeOutput.includes('all_w1800') && !probeOutput.includes('❌') && !probeOutput.includes('step9') && !probeOutput.includes('incard=1');
} catch (err) {
    console.log('❌ خطأ في تشغيل probe:', err.message);
}
console.log(`8. فحص الحركة العادية (probe): ${pass8 ? '✅' : '❌'}`);

const allPassed = pass1 && pass2 && pass3 && pass4 && pass5 && pass6 && pass7 && pass8;
console.log(`\nنتيجة الاختبارات الإجمالية: ${allPassed ? '✅ جميع الحالات عدّت بنجاح' : '❌ هناك حالات فشلت'}`);
process.exit(allPassed ? 0 : 1);
