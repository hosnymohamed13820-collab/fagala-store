// ملف الطباعة (٦٠٠ dpi بالنزيف) وبعده الموكاب لأنه بيستخدمه
const { chromium } = require('playwright');
const path = require('path');

(async () => {
  const browser = await chromium.launch();
  const jobs = [
    ['cover.html', 'cover_print.png', '#canvas', 795, 1652, 2.365],
    ['cover.html', 'cover_guide.png', '#canvas', 795, 1652, 1, 'guide'],
    ['mockup.html', 'mockup.png', '#scene', 1400, 2100, 1],
  ];
  for (const [src, out, sel, w, h, scale, cls] of jobs) {
    const page = await browser.newPage({ viewport: { width: w, height: h }, deviceScaleFactor: scale });
    await page.goto('file://' + path.resolve(__dirname, src));
    if (cls) await page.evaluate(c => document.body.classList.add(c), cls);
    await page.evaluate(() => document.fonts.ready);
    await page.locator(sel).screenshot({ path: path.join(__dirname, out) });
    await page.close();
  }
  await browser.close();
})();
