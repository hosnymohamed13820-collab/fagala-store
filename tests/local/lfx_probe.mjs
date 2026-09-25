// =====================================================================
//  lfx_probe.mjs — فحص شخصيات صفحة الدخول على Laragon (بريف ٦٣/٦٤)
// ---------------------------------------------------------------------
//  shot.sh مابيقدرش يصوّر login.php (بيدخل كمستخدم فبيتحوّل)، و--dump-dom
//  مابيطلعش حاجة من msedge على ويندوز — عشان كده CDP على http://localhost.
//
//  الاستعمال:
//     node tests/local/lfx_probe.mjs <مجلد الصور> ["query1" "query2" ...]
//  من غير queries بيشغّل الحالات الافتراضية تحت.
//  لكل حالة: صورة <اسم>.png + أرقام الديبج (mindist · flips · incard)
//  + مكان مركز كل شخصية + أكبر قفزة في فريم (maxStep) على ٣ ثواني حقيقية.
//  🔴 اعمل sync_to_laragon.sh الأول.
// =====================================================================
import { spawn } from 'node:child_process';
import { writeFileSync, mkdtempSync, mkdirSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const OUT = process.argv[2];
if (!OUT) { console.log('الاستعمال: node tests/local/lfx_probe.mjs <مجلد> ["query" ...]'); process.exit(1); }
mkdirSync(OUT, { recursive: true });
const W = +(process.env.LFX_W || 1440), H = +(process.env.LFX_H || 900);
const EDGE = ['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
              'C:/Program Files/Microsoft/Edge/Application/msedge.exe'].find(existsSync);
if (!EDGE) { console.log('❌ مفيش Edge'); process.exit(1); }

let queries = process.argv.slice(3);
if (!queries.length) queries = [
    'lfx_idle=1&lfx_debug=1&lfx_warp=1800',
    'lfx_idle=1&lfx_debug=1&lfx_scene=chase&lfx_warp=6',
    'lfx_idle=1&lfx_debug=1&lfx_scene=chase&lfx_warp=14',
    'lfx_idle=1&lfx_debug=1&lfx_scene=chase&lfx_warp=24',
    'lfx_idle=1&lfx_debug=1&lfx_scene=write&lfx_warp=12',
    'lfx_idle=1&lfx_debug=1&lfx_scene=sign&lfx_warp=14',
    'lfx_idle=1&lfx_debug=1&lfx_scene=measure&lfx_warp=10',
    'lfx_idle=1&lfx_debug=1&lfx_scene=read&lfx_warp=16',
];

const prof = mkdtempSync(join(tmpdir(), 'lfx-'));
const port = 9300 + Math.floor(Math.random() * 500);
const edge = spawn(EDGE, ['--headless=new', '--disable-gpu', `--remote-debugging-port=${port}`,
    `--user-data-dir=${prof}`, `--window-size=${W},${H}`, 'about:blank'], { stdio: 'ignore' });
const sleep = ms => new Promise(r => setTimeout(r, ms));
let wsUrl;
for (let i = 0; i < 50 && !wsUrl; i++) {
    await sleep(200);
    try { wsUrl = (await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()).find(x => x.type === 'page')?.webSocketDebuggerUrl; } catch {}
}
if (!wsUrl) { console.log('❌ Edge ماردّش'); edge.kill(); process.exit(1); }
const ws = new WebSocket(wsUrl);
await new Promise(r => ws.addEventListener('open', r, { once: true }));
let id = 0; const pending = new Map();
ws.addEventListener('message', ev => { const m = JSON.parse(ev.data); if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); } });
const send = (method, params = {}) => new Promise(r => { const i = ++id; pending.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
const ev = async e => (await send('Runtime.evaluate', { expression: e, returnByValue: true, awaitPromise: true })).result.result.value;
await send('Page.enable');
await send('Emulation.setDeviceMetricsOverride', { width: W, height: H, deviceScaleFactor: 1, mobile: W < 700 });

// المركز من offset + transform (مش getBoundingClientRect — المسطرة لما تلف بتكبّر الـbbox وتكدب)
const PROBE = `new Promise(res => {
  const els = [...document.querySelectorAll('.lfx-char')];
  const name = el => (el.className.match(/lfx-char-([a-z]+)/) || [])[1] || '?';
  const center = el => { const r = el.getBoundingClientRect(); return [r.left + r.width / 2, r.top + r.height / 2]; };
  const hist = els.map(() => []); const t0 = performance.now();
  (function f() {
    els.forEach((el, i) => hist[i].push(center(el)));
    if (performance.now() - t0 < 3000) requestAnimationFrame(f);
    else {
      const card = document.querySelector('.login-card').getBoundingClientRect();
      const d = document.body.dataset;
      res(JSON.stringify({
        mindist: d.lfxMindist, flips: d.lfxFlips, incard: d.lfxIncard,
        card: [Math.round(card.left), Math.round(card.top), Math.round(card.right), Math.round(card.bottom)],
        chars: els.map((el, i) => { const h = hist[i]; let mx = 0;
          for (let k = 1; k < h.length; k++) mx = Math.max(mx, Math.hypot(h[k][0] - h[k-1][0], h[k][1] - h[k-1][1]));
          const c = h[h.length - 1];
          return name(el) + '@' + Math.round(c[0]) + ',' + Math.round(c[1]) + (name(el) === 'ruler' ? '' : ' step' + mx.toFixed(1)); })
      }));
    }
  })();
})`;

for (const q of queries) {
    const nm = (q.match(/lfx_scene=([a-z]+)/) || [, 'all'])[1] + '_w' + ((q.match(/lfx_warp=(\d+)/) || [, '0'])[1]);
    await send('Page.navigate', { url: 'http://localhost/login.php?' + q });
    await sleep(1500);
    const s = await send('Page.captureScreenshot', { format: 'png' });
    writeFileSync(join(OUT, nm + '.png'), Buffer.from(s.result.data, 'base64'));
    const r = JSON.parse(await ev(PROBE));
    console.log(`── ${nm}  mindist=${r.mindist} flips=${r.flips} incard=${r.incard}  card=${r.card.join(',')}`);
    console.log('   ' + r.chars.join(' · '));
}
ws.close(); edge.kill();
