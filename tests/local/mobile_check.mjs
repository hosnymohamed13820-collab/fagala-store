// =====================================================================
//  mobile_check.mjs — كل صفحة بعرض موبايل: فيه حاجة طالعة برّه الشاشة؟
// ---------------------------------------------------------------------
//  node tests/local/mobile_check.mjs                  ← كل صفحات all_modules()
//  node tests/local/mobile_check.mjs cashier.php hr.php
//  MC_W=360 node tests/local/mobile_check.mjs
//  MC_SHOT=<فولدر> node tests/local/mobile_check.mjs hr.php   ← + صورة موبايل حقيقية (MC_H = أقصى طول)
//
//  بيرندر من Laragon (run_page.php) ويفتح Edge headless بعرض الموبايل عن طريق
//  DevTools Protocol، ويطبع: عرض الصفحة (أكبر من الشاشة = سكرول بالعرض) + أول ٨
//  عناصر طالعة برّه (مش جوّه حاجة ليها overflow زي الجداول). نص بس — أرخص من الصور.
//  🔴 شغّل bash tests/local/sync_to_laragon.sh الأول.
//  (--dump-dom مابيطلعش حاجة من msedge على ويندوز — عشان كده CDP.)
// =====================================================================
import { execFileSync, spawn } from 'node:child_process';
import { readFileSync, writeFileSync, rmSync, mkdtempSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const PHP = process.env.PHP_BIN || 'D:/php-8.5.10-nts-Win32-vs17-x64/php.exe';
const WWW = process.env.LARAGON_WWW || 'D:/laragon/www';
const W = +(process.env.MC_W || 390), H = 844;
const EDGE = ['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
              'C:/Program Files/Microsoft/Edge/Application/msedge.exe'].find(existsSync);
if (!EDGE) { console.log('❌ مفيش Edge'); process.exit(1); }

let pages = process.argv.slice(2);
if (!pages.length) {
    const auth = readFileSync('site/auth.php', 'utf8');
    pages = [...new Set([...auth.matchAll(/'url' => '([a-z_]+\.php)'/g)].map(m => m[1]))];
    if (!pages.includes('dashboard.php')) pages.unshift('dashboard.php');
}

const PROBE = `(() => {
  const W = document.documentElement.clientWidth, out = [];
  const clipped = el => { for (let p = el.parentElement; p && p !== document.body; p = p.parentElement)
      if (/(auto|scroll|hidden)/.test(getComputedStyle(p).overflowX)) return true; return false; };
  for (const el of document.querySelectorAll('body *')) {
    if (out.length >= 8) break;
    if (el.closest('.sidebar, aside, [aria-hidden="true"]')) continue;   // القايمة الجانبية مخفية برّه الشاشة بقصد
    const r = el.getBoundingClientRect(); if (!r.width) continue;
    const cs = getComputedStyle(el); if (cs.position === 'fixed' || cs.display === 'none') continue;
    if ((r.right > W + 2 || r.left < -2) && !clipped(el)) {
      const cls = typeof el.className === 'string' ? el.className.trim().split(/\\s+/).slice(0, 2).join('.') : '';
      out.push(el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (cls ? '.' + cls : '') + ' [' + Math.round(r.left) + '→' + Math.round(r.right) + ']');
    }
  }
  return JSON.stringify({ sw: document.documentElement.scrollWidth, w: W, out, ok: !!document.querySelector(".sidebar, main, .main"), t: document.title });
})()`;

const prof = mkdtempSync(join(tmpdir(), 'mc-'));
const port = 9300 + Math.floor(Math.random() * 500);
const edge = spawn(EDGE, ['--headless=new', '--disable-gpu', `--remote-debugging-port=${port}`,
    `--user-data-dir=${prof}`, `--window-size=${W},${H}`, 'about:blank'], { stdio: 'ignore' });

const sleep = ms => new Promise(r => setTimeout(r, ms));
let wsUrl;
for (let i = 0; i < 50 && !wsUrl; i++) {
    await sleep(200);
    try { const t = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
          wsUrl = t.find(x => x.type === 'page')?.webSocketDebuggerUrl; } catch {}
}
if (!wsUrl) { console.log('❌ Edge ماردّش'); edge.kill(); process.exit(1); }

const ws = new WebSocket(wsUrl);
await new Promise(r => ws.addEventListener('open', r, { once: true }));
let id = 0; const pending = new Map(), waiters = [];
ws.addEventListener('message', ev => {
    const m = JSON.parse(ev.data);
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); }
    else if (m.method) waiters.filter(w => w.method === m.method).forEach(w => w.fn(m));
});
const send = (method, params = {}) => new Promise(r => { const i = ++id; pending.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
const once = method => new Promise(r => { const w = { method, fn: m => { waiters.splice(waiters.indexOf(w), 1); r(m); } }; waiters.push(w); });

await send('Page.enable');
await send('Emulation.setDeviceMetricsOverride', { width: W, height: H, deviceScaleFactor: 2, mobile: true });

let bad = 0;
for (const p of pages) {
    let html;
    try { html = execFileSync(PHP, ['tests/local/run_page.php', p, 'owner', ''], { encoding: 'utf8', maxBuffer: 64 << 20 }); }
    catch (e) { html = String(e.stdout || ''); }
    if (/Fatal error|Parse error/.test(html)) { console.log(`❌ ${p} — الصفحة وقعت`); bad++; continue; }
    const f = `${WWW}/_mc_${p.replace('.php', '')}.html`;
    writeFileSync(f, html);
    const loaded = once('Page.loadEventFired');
    await send('Page.navigate', { url: 'file:///' + f });
    await Promise.race([loaded, sleep(15000)]);
    await sleep(600);
    const r = await send('Runtime.evaluate', { expression: PROBE, returnByValue: true });
    if (process.env.MC_SHOT) {   // صورة موبايل حقيقية (shot.sh مابيقدرش ينزل تحت ~500px في الـheadless)
        const m = await send('Page.getLayoutMetrics');
        const hh = Math.min(Math.ceil(m.result.cssContentSize.height), +(process.env.MC_H || 2400));
        const s = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width: W, height: hh, scale: 1 } });
        writeFileSync(join(process.env.MC_SHOT, p.replace('.php', '') + `_m${W}.png`), Buffer.from(s.result.data, 'base64'));
    }
    rmSync(f, { force: true });
    let res; try { res = JSON.parse(r.result.result.value); } catch { console.log(`⚠️ ${p} — القياس فشل`); continue; }
    if (!res.ok) { bad++; console.log(`⚠️ ${p} — الصفحة ماتحمّلتش (${res.t}) — القياس مالوش قيمة`); continue; }
    if (res.sw <= res.w + 2 && !res.out.length) console.log(`✅ ${p}`);
    else { bad++; console.log(`❌ ${p} — عرض الصفحة ${res.sw} (الشاشة ${res.w})`); res.out.forEach(o => console.log('      ' + o)); }
}
ws.close();
// على ويندوز kill() بيقفل الأب بس — والولاد بيفضلوا شغّالين ويقفلوا الـheadless اللي بعده
try { execFileSync('taskkill', ['/PID', String(edge.pid), '/T', '/F'], { stdio: 'ignore' }); } catch { edge.kill(); }
await sleep(500); try { rmSync(prof, { recursive: true, force: true }); } catch {}
console.log(bad ? `\n❌ ${bad} صفحة فيها حاجة طالعة برّه الشاشة على ${W}` : `\n✅ كل الصفحات مظبوطة على ${W}`);
