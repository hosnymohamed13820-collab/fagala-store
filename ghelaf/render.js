// بيطلّع ملف الطباعة (٦٠٠ dpi بالنزيف) + صورة معاينة على شكل الموبايل
const { chromium } = require('playwright');
const path = require('path');

(async () => {
  const browser = await chromium.launch();
  const url = 'file://' + path.resolve(__dirname, 'cover.html');
  for (const [file, scale, preview] of [['cover_print.png', 3.1, false], ['preview.png', 1.2, true]]) {
    const page = await browser.newPage({ viewport: { width: 607, height: 1261 }, deviceScaleFactor: scale });
    await page.goto(url);
    if (preview) await page.evaluate(() => document.body.classList.add('preview'));
    await page.evaluate(() => document.fonts.ready);
    await page.locator('#canvas').screenshot({ path: path.join(__dirname, file) });
    await page.close();
  }
  await browser.close();
})();
